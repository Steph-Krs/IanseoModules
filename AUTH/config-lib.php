<?php
/**
 * AUTH module — reading and writing config.local.json from the web (admin/config.php).
 *
 * The file holds secrets (FFTA service account…) AND settings that the nightly cron
 * turns into shell commands or file writes. Editing it from a browser is only safe
 * with three rules, enforced here on the SERVER side (the page is just a view):
 *
 *  1. Secrets are never sent to the browser. They are replaced by a placeholder; a
 *     placeholder coming back means "unchanged".
 *  2. "Locked" settings cannot be changed from the web, only from the command line:
 *     shell commands (maintenance.on/off/lock/unlock), file/dir/binary paths, and
 *     URLs/hosts. Otherwise a stolen admin session would turn into:
 *       - running any command as the web user (maintenance.on is exec()'d by cron),
 *       - writing a .php file in the web root (log_file receives the typed login name),
 *       - relaying every organiser's FFTA password to another server (sso.base).
 *     The one exception is backup.dir, validated by aut_backup_dir_problem() (never
 *     inside the web root).
 *  3. Atomic write, previous version kept as config.local.json.bak. Both names end in
 *     .json/.bak, which the Apache config shipped with the module denies.
 */

require_once __DIR__ . '/lang-lib.php';

define('AUT_CFG_MASK', '••••••••');

function aut_cfg_file()
{
    return __DIR__ . '/config.local.json';
}

/** Fresh read (aut_local_config() is cached per request). null + $err if unreadable. */
function aut_cfg_read(&$err = '')
{
    $err = '';
    $f = aut_cfg_file();
    if (!is_file($f)) return array();
    $raw = @file_get_contents($f);
    if ($raw === false) { $err = aut_t('ClUnreadable'); return null; }
    $raw = function_exists('aut_json_strip_bom') ? aut_json_strip_bom($raw) : $raw;
    if (trim($raw) === '') return array();
    $d = json_decode($raw, true);
    if (!is_array($d)) { $err = aut_t('ClBadJson', json_last_error_msg()); return null; }
    return $d;
}

/** Flattens nested arrays to "a.b.c" => scalar (lists are kept whole). */
function aut_cfg_flatten($a, $prefix = '')
{
    $out = array();
    foreach ((array) $a as $k => $v) {
        $p = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v) && $v !== array() && array_keys($v) !== range(0, count($v) - 1)) {
            $out += aut_cfg_flatten($v, $p);
        } else {
            $out[$p] = $v;
        }
    }
    return $out;
}

function aut_cfg_is_secret($path)
{
    // bytes: configuration keys are ASCII
    $leaf = strtolower((string) substr(strrchr('.' . $path, '.'), 1));
    return (bool) preg_match('/(pass(word)?|secret|token|otp|api_?key|private_?key)$/', $leaf);
}

/** Setting that only the command line may change (see rule 2 above). */
function aut_cfg_is_locked($path, $value = null)
{
    $path = (string) $path;
    if ($path === 'backup.dir' || $path === 'backup.remote') return false;   // validated separately
    if (preg_match('/^maintenance\.(on|off|lock|unlock)$/', $path)) return true;
    // bytes: configuration keys are ASCII
    $leaf = strtolower((string) substr(strrchr('.' . $path, '.'), 1));
    if (preg_match('/(^|_)(file|dir|path|bin|cmd|command|exec|base|url|host)$/', $leaf)) return true;
    // Unknown keys holding a URL are treated the same way. (Not a leading "/": regular
    // expressions such as sso.required_role_regex start with one too — paths are
    // caught by the key name above.)
    if (is_string($value) && preg_match('#^[a-z][a-z0-9+.\-]*://#i', trim($value))) return true;
    return false;
}

/** Copy of the config safe to show in a browser: secrets replaced by the placeholder. */
function aut_cfg_masked($cfg)
{
    foreach ($cfg as $k => $v) {
        if (is_array($v)) {
            $cfg[$k] = aut_cfg_masked_sub($v, (string) $k);
        } elseif (aut_cfg_is_secret((string) $k) && (string) $v !== '') {
            $cfg[$k] = AUT_CFG_MASK;
        }
    }
    return $cfg;
}

function aut_cfg_masked_sub($a, $prefix)
{
    foreach ($a as $k => $v) {
        $p = $prefix . '.' . $k;
        if (is_array($v)) $a[$k] = aut_cfg_masked_sub($v, $p);
        elseif (aut_cfg_is_secret($p) && (string) $v !== '') $a[$k] = AUT_CFG_MASK;
    }
    return $a;
}

/** Puts back the real secret wherever the placeholder came back. */
function aut_cfg_unmask($new, $old)
{
    foreach ($new as $k => $v) {
        if (is_array($v)) {
            $new[$k] = aut_cfg_unmask($v, is_array($old[$k] ?? null) ? $old[$k] : array());
        } elseif ($v === AUT_CFG_MASK) {
            if (array_key_exists($k, (array) $old)) $new[$k] = $old[$k];
            else unset($new[$k]);
        }
    }
    return $new;
}

/**
 * Validates and writes a new config. $new comes from the browser (masked secrets).
 * Returns true, or false with $err. $changed receives the modified top-level keys
 * (for the audit log — never the values).
 */
function aut_cfg_save($new, &$err, &$changed = array())
{
    $err = ''; $changed = array();
    if (!is_array($new)) { $err = aut_t('ClBadContent'); return false; }
    $old = aut_cfg_read($err);
    if ($old === null) return false;

    $new = aut_cfg_unmask($new, $old);

    // Rule 2: locked settings must be identical before and after.
    $fo = aut_cfg_flatten($old);
    $fn = aut_cfg_flatten($new);
    $bad = array();
    foreach (array_unique(array_merge(array_keys($fo), array_keys($fn))) as $p) {
        $vo = $fo[$p] ?? null; $vn = $fn[$p] ?? null;
        if ($vo === $vn) continue;
        if (aut_cfg_is_locked($p, $vo) || aut_cfg_is_locked($p, $vn)) $bad[] = $p;
    }
    if ($bad) {
        $err = aut_t('ClLocked', implode(', ', $bad));
        return false;
    }

    if (isset($new['backup'])) {
        if (!is_array($new['backup'])) { $err = aut_t('ClBackupObject'); return false; }
        require_once __DIR__ . '/backup-lib.php';
        $b = aut_backup_config($new);
        if (($new['backup']['dir'] ?? null) !== ($old['backup']['dir'] ?? null)) {
            $fix = '';
            $p = aut_backup_dir_problem($b['dir'], $fix, false);
            // A directory that does not exist yet is accepted (the admin creates it with
            // the command shown); a directory inside the web root never is.
            if ($p !== '' && $fix === '') { $err = aut_t('ClDirRefused', $p); return false; }
        }
        if ($b['remote'] !== '' && !aut_backup_remote_valid($b['remote'])) {
            $err = aut_t('ClBadRemote');
            return false;
        }
    }

    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
        if (($old[$k] ?? null) !== ($new[$k] ?? null)) $changed[] = $k;
    }
    if (!$changed) return true;

    $f = aut_cfg_file();
    if (is_file($f) && !is_writable($f)) {
        $err = aut_t('ClNotWritable', 'sudo chown www-data:www-data ' . $f . ' && sudo chmod 600 ' . $f);
        return false;
    }
    $json = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { $err = aut_t('ClJsonEncode'); return false; }

    // Temporary name ends in .json so Apache denies it too while it exists.
    $tmp = __DIR__ . '/config.local.tmp.json';
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        $err = aut_t('ClWriteDir', __DIR__);
        return false;
    }
    @chmod($tmp, 0600);
    if (is_file($f)) { @copy($f, $f . '.bak'); @chmod($f . '.bak', 0600); }
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        $err = aut_t('ClReplace');
        return false;
    }
    return true;
}
