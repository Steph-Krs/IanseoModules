<?php
/**
 * Shared update and uninstall library for ianseo custom modules.
 *
 * Every module keeps itself up to date the same way: a module.json naming the
 * GitHub repository it came from, a version.json listing the files that make it
 * up, and this library doing the work. One implementation means one set of
 * safety rules to get right rather than one per module.
 *
 * WHAT LIVES WHERE
 *   module.json   the repository, branch, folder and optional token. Local
 *                 configuration: never listed in files[], never overwritten by
 *                 an update, never editable from the web interface.
 *   version.json  the version, the manifest files[], and optionally tables[],
 *                 uninstall_warning and uninstall_backup. It comes from the
 *                 repository, so everything read out of it is treated as
 *                 untrusted input — table names in particular are filtered
 *                 before they can reach any SQL.
 *
 * Usage from a module: require_once dirname(__DIR__) . '/_shared/update-lib.php';
 */

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        // bytes: a polyfill has to behave exactly like the PHP 8 function it
        // stands in for, and that one compares bytes.
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}

/* =======================================================================
 * Translations
 * =====================================================================*/

/**
 * The whole string table for the current language, English merged underneath.
 *
 * Same mechanism as the ianseo core: English is read first and the user's
 * language merged over it, so a key missing from a translation falls back to
 * English instead of disappearing from the page.
 *
 * @return array Key to translated string.
 */
function upd_lang_all() {
    static $strings = null;
    if ($strings !== null) return $strings;

    $dir = __DIR__ . '/languages/';

    $lang = [];
    if (is_file($dir . 'en.php')) include $dir . 'en.php';
    $strings = is_array($lang) ? $lang : [];

    // bytes: a language code is ASCII by definition (fr, pt-br), so neither
    // folding its case nor cutting it needs multibyte handling.
    $code = function_exists('SelectLanguage') ? strtolower((string)SelectLanguage()) : 'en';
    if ($code === '') $code = 'en';

    // ianseo language codes may be regional (fr-ca, pt-br). Try the exact code,
    // then the base language, before giving up on English.
    foreach (array_unique([$code, mb_substr($code, 0, 2)]) as $try) {
        if ($try === 'en' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $try)) continue;
        if (!is_file($dir . $try . '.php')) continue;

        $lang = [];
        include $dir . $try . '.php';
        if (is_array($lang)) $strings = array_merge($strings, $lang);
        break;
    }
    return $strings;
}

/**
 * Translate a key for the update and uninstall screens.
 *
 * @param string $key Key defined in languages/en.php.
 * @param mixed $a Value substituted for {$a}, or an array for {$a[name]}.
 * @return string The translated text, or a visible marker for an unknown key.
 */
function upd_text($key, $a = null) {
    $strings = upd_lang_all();

    if (!isset($strings[$key])) {
        // Same shape as the core's marker: loud in development, searchable.
        return '<b>[[' . $key . ']@[shared]]</b>';
    }

    $result = $strings[$key];
    if (is_null($a)) return $result;

    if (is_scalar($a)) {
        return str_replace(['{$a}', '$a'], (string)$a, $result);
    }
    if (is_array($a)) {
        foreach ($a as $k => $v) {
            $result = str_replace(['{$a[' . $k . ']}', '$a[' . $k . ']'], (string)$v, $result);
        }
    }
    return $result;
}

/**
 * Escape a value on its way into a page.
 *
 * The update and uninstall screens are built in PHP rather than woven out of
 * template tags — ianseo asks that a file be in one language at a time — so
 * every value passes through one short function instead of a long
 * htmlspecialchars() call repeated a hundred times. It lives here rather than in
 * update-ui.php because uninstall.php renders without that file.
 *
 * @param mixed $value Value to escape.
 * @return string HTML-safe text.
 */
function upd_esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =======================================================================
 * Module configuration and the GitHub repository
 * =====================================================================*/

/**
 * Read module.json.
 *
 * @param string $module_dir Module root.
 * @return array Always complete: github_url, github_branch, github_path, github_token.
 */
function upd_load_config($module_dir) {
    $defaults = ['github_url' => '', 'github_branch' => 'main', 'github_path' => '', 'github_token' => ''];

    $f = $module_dir . '/module.json';
    if (!is_file($f)) return $defaults;

    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? array_merge($defaults, $d) : $defaults;
}

/**
 * Split a GitHub URL into its owner and repository.
 *
 * @param string $url Any form of GitHub URL, with or without .git.
 * @return array|null ['owner' => …, 'repo' => …], or null when unrecognisable.
 */
function upd_parse_repo($url) {
    if (preg_match('|github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?(?:/.*)?$|i', $url, $m)) {
        return ['owner' => $m[1], 'repo' => rtrim($m[2], '/')];
    }
    return null;
}

/**
 * Path prefix of the module inside the repository, with a trailing slash.
 *
 * @param array $cfg Module configuration.
 * @return string Such as "GUIDE/", or "" when the module sits at the root.
 */
function upd_remote_prefix($cfg) {
    $p = trim($cfg['github_path'] ?? '', '/');
    return $p !== '' ? "$p/" : '';
}

/**
 * Base raw.githubusercontent.com URL for the module, without a trailing slash.
 *
 * @param array $cfg Module configuration.
 * @return string Empty when the repository URL is unusable.
 */
function upd_raw_base($cfg) {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) return '';

    $branch = $cfg['github_branch'] ?: 'main';
    $prefix = rtrim(upd_remote_prefix($cfg), '/');
    return "https://raw.githubusercontent.com/{$repo['owner']}/{$repo['repo']}/$branch"
         . ($prefix ? "/$prefix" : '');
}

/**
 * Base raw URL at the ROOT of the repository, ignoring the module's own folder.
 *
 * Needed to reach _shared/ and the sibling modules, which live beside the
 * current module rather than inside it.
 *
 * @param array $cfg Module configuration.
 * @return string Empty when the repository URL is unusable.
 */
function upd_raw_root($cfg) {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) return '';

    $branch = $cfg['github_branch'] ?: 'main';
    return "https://raw.githubusercontent.com/{$repo['owner']}/{$repo['repo']}/$branch";
}

/**
 * Call the GitHub JSON API.
 *
 * ignore_errors keeps the response body on an HTTP error, because GitHub puts
 * the reason ("rate limit exceeded", "Not Found") in it — reporting that is far
 * more useful than reporting the status code.
 *
 * @param string $url API URL.
 * @param string $token Optional token, for private repositories.
 * @return array Decoded response, or ['_error' => message].
 */
function upd_gh_fetch($url, $token = '') {
    $headers = "User-Agent: ianseo-custom-module/1.0\r\n";
    if ($token) $headers .= "Authorization: token $token\r\n";

    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);

    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return ['_error' => upd_text('ErrGitHubUnreachable')];

    $data = json_decode($res, true);
    if (isset($data['message'])) return ['_error' => $data['message']];

    return is_array($data) ? $data : ['_error' => upd_text('ErrBadJson')];
}

/**
 * Download one file from the repository.
 *
 * @param string $url Raw file URL.
 * @param string $token Optional token, for private repositories.
 * @return string|false The file content, or false.
 */
function upd_gh_raw($url, $token = '') {
    $headers = "User-Agent: ianseo-custom-module/1.0\r\n";
    if ($token) $headers .= "Authorization: token $token\r\n";

    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);

    return @file_get_contents($url, false, $ctx);
}

/* =======================================================================
 * Versions
 * =====================================================================*/

/**
 * Read the module's version.json from the repository.
 *
 * @param array $cfg Module configuration.
 * @return array Decoded manifest, or ['_error' => message].
 */
function upd_remote_version($cfg) {
    $base = upd_raw_base($cfg);
    if (!$base) return ['_error' => upd_text('ErrBadGitHubUrl')];

    $raw = upd_gh_raw("$base/version.json", $cfg['github_token'] ?? '');
    if ($raw === false || $raw === '') return ['_error' => upd_text('ErrRemoteVersionUnreadable')];

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['version'])) return ['_error' => upd_text('ErrRemoteVersionInvalid')];

    return $data;
}

/**
 * Read the module's local version.json.
 *
 * @param string $module_dir Module root.
 * @return array|null Null when missing or unreadable.
 */
function upd_local_version($module_dir) {
    $f = $module_dir . '/version.json';
    if (!is_file($f)) return null;

    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/**
 * Compare a local version against a remote one.
 *
 * version_compare handles X.Y.Z properly; a string comparison would rank 1.10
 * below 1.9.
 *
 * @param string $local_ver Local version.
 * @param string $remote_ver Remote version.
 * @return string 'update' when the remote one is newer, 'ok' otherwise.
 */
function upd_compare($local_ver, $remote_ver) {
    return version_compare($remote_ver, $local_ver, '>') ? 'update' : 'ok';
}

/**
 * Download the manifest's files into the module folder.
 *
 * A file that comes back empty is reported as a failure rather than written:
 * overwriting a working file with nothing would break the installation, and an
 * empty response is far more often a network problem than a real empty file.
 *
 * @param array $cfg Module configuration.
 * @param string $module_dir Module root.
 * @param array $files Paths relative to the module folder.
 * @return array ['ok' => count, 'fail' => [paths]].
 */
function upd_sync_files($cfg, $module_dir, $files) {
    $base  = upd_raw_base($cfg);
    $token = $cfg['github_token'] ?? '';
    $ok    = 0;
    $fail  = [];

    foreach ($files as $rel) {
        $content = upd_gh_raw("$base/$rel", $token);
        if ($content !== false && $content !== '') {
            $dest = $module_dir . '/' . $rel;
            $dir  = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dest, $content);
            $ok++;
        } else {
            $fail[] = $rel;
        }
    }
    return ['ok' => $ok, 'fail' => $fail];
}

/* =======================================================================
 * Permissions and module discovery
 * =====================================================================*/

/**
 * The Modules/Custom directory. This file lives in Custom/_shared/.
 *
 * @return string Absolute path without trailing slash.
 */
function upd_custom_dir() {
    return dirname(__DIR__);
}

/**
 * Identifier of the signed-in user, '' without an account module.
 *
 * $_SESSION['AUTH_User'] is the ianseo core convention (USERAUTH), set by an
 * authentication module. Read directly so this library depends on no particular
 * one of them.
 *
 * mb_substr, not substr: a user name is an international field, and cutting it
 * at 64 bytes can split a character in half — the identifier would then no
 * longer match the one the account module set.
 *
 * @return string At most 64 characters.
 */
function upd_current_user() {
    return mb_substr(trim((string)($_SESSION['AUTH_User'] ?? '')), 0, 64);
}

/**
 * May the current user administer modules?
 *
 * AclRoot alone is NOT enough. With an account module installed, its
 * authCheckACL() grants AclReadWrite to any signed-in organiser on pages outside
 * a competition, so a plain AclRoot test would let every organiser update and
 * uninstall modules. The server administrator view is required as well.
 *
 * @return bool
 */
function upd_is_admin() {
    if (upd_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) return false;
    return hasFullACL(AclRoot, '', AclReadWrite);
}

/**
 * Guard for the administration pages: aborts to noAccess when not allowed.
 */
function upd_admin_guard() {
    global $CFG;

    checkFullACL(AclRoot, '', AclReadWrite);
    if (upd_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) {
        CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
        die();
    }
}

/**
 * The managed modules: folders of Custom/ holding a module.json.
 *
 * module.json is what distinguishes a managed module from any other folder, and
 * it is the same marker the updater and the uninstaller rely on throughout.
 *
 * @return array Module names, sorted.
 */
function upd_list_modules() {
    $out = [];
    foreach (glob(upd_custom_dir() . '/*', GLOB_ONLYDIR) as $d) {
        $n = basename($d);
        if ($n === '' || $n[0] === '_' || $n[0] === '.') continue;
        if (is_file($d . '/module.json')) $out[] = $n;
    }
    sort($out);
    return $out;
}

/**
 * Validate a module name that arrived in a request, and return its path.
 *
 * Three layers, because this decides what a delete operation may reach: the
 * null byte is rejected first (basename() truncates on it), then basename() and
 * a character whitelist remove any path traversal, and finally the module.json
 * requirement means no ianseo folder can ever be named.
 *
 * @param string $name Name from the request.
 * @return string|null Absolute path, or null when the name is not acceptable.
 */
function upd_valid_module($name) {
    $name = (string)$name;
    if (strpos($name, "\0") !== false) return null;

    $name = basename(trim($name));
    if ($name === '' || $name[0] === '_' || $name[0] === '.') return null;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) return null;

    $dir = upd_custom_dir() . '/' . $name;
    return (is_dir($dir) && is_file($dir . '/module.json')) ? $dir : null;
}

/**
 * Validate a module name that is not installed yet, for installing from the
 * repository. Same rules as upd_valid_module() minus the existence checks.
 *
 * @param string $name Name from the request.
 * @return bool
 */
function upd_valid_module_name($name) {
    $name = (string)$name;
    if (strpos($name, "\0") !== false) return false;
    if ($name === '' || $name[0] === '_' || $name[0] === '.') return false;
    return (bool)preg_match('/^[A-Za-z0-9._-]+$/', $name);
}

/* =======================================================================
 * What a module declares about its own removal
 * =====================================================================*/

/**
 * Tables the module declares in version.json ("tables": [...]).
 *
 * version.json comes from the repository, so the names are filtered here rather
 * than at the point of use: nothing that fails this pattern can ever reach a
 * DROP statement.
 *
 * @param string $module_dir Module root.
 * @return array Table names.
 */
function upd_module_tables($module_dir) {
    $v    = upd_local_version($module_dir);
    $list = (is_array($v) && !empty($v['tables']) && is_array($v['tables'])) ? $v['tables'] : [];

    $out = [];
    foreach ($list as $t) {
        if (is_string($t) && preg_match('/^[A-Za-z0-9_]+$/', $t)) $out[] = $t;
    }
    return $out;
}

/**
 * Warning the module declares in version.json ("uninstall_warning").
 *
 * For modules whose removal has effects OUTSIDE their own folder — files
 * deployed elsewhere, core dependencies — which the generic screen cannot know
 * about and the administrator has to be told.
 *
 * @param string $module_dir Module root.
 * @return string Empty when the module declares none.
 */
function upd_uninstall_warning($module_dir) {
    $v = upd_local_version($module_dir);
    $w = (is_array($v) && !empty($v['uninstall_warning'])) ? $v['uninstall_warning'] : '';
    return is_string($w) ? $w : '';
}

/**
 * Does the module ask for a backup before removal ("uninstall_backup": true)?
 *
 * No backup by default, on purpose: the files stay recoverable from the
 * repository, and an archive in the temporary folder would only duplicate
 * whatever secrets a local configuration holds. Only modules with genuinely
 * unversioned local data ask for one.
 *
 * @param string $module_dir Module root.
 * @return bool
 */
function upd_uninstall_backup($module_dir) {
    $v = upd_local_version($module_dir);
    return is_array($v) && !empty($v['uninstall_backup']);
}

/* =======================================================================
 * Removal
 * =====================================================================*/

/**
 * Delete leftover uninstall archives older than $max_age seconds.
 *
 * A backup nobody downloads would otherwise sit in the temporary folder for
 * ever, holding a copy of whatever the module's configuration contained.
 *
 * @param int $max_age Seconds; 24 hours by default.
 */
function upd_purge_old_backups($max_age = 86400) {
    clearstatcache();

    $dir = rtrim(sys_get_temp_dir(), '/\\');
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'ianseo-*.zip') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > $max_age) @unlink($f);
    }
}

/**
 * Archive a module into the system temporary folder.
 *
 * Deliberately outside the web root: a backup written into Custom/ would be
 * downloadable by anyone who guessed its name, secrets included.
 *
 * @param string $module_dir Module root.
 * @param string $name Module name, used inside the archive and in its filename.
 * @return array ['file' => path] or ['_error' => message].
 */
function upd_backup_module($module_dir, $name) {
    if (!class_exists('ZipArchive')) {
        return ['_error' => upd_text('ErrZipMissing')];
    }

    $base = realpath($module_dir);
    if ($base === false) return ['_error' => upd_text('ErrModuleDirMissing')];

    $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
          . 'ianseo-' . $name . '-' . date('Ymd-His') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['_error' => upd_text('ErrZipCreate', $file)];
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        // bytes: a filesystem path cut at the byte offset strlen just produced.
        $rel = $name . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if ($f->isDir()) $zip->addEmptyDir($rel);
        else             $zip->addFile($f->getPathname(), $rel);
    }

    if (!$zip->close()) return ['_error' => upd_text('ErrZipWrite')];
    return ['file' => $file];
}

/**
 * Recursive delete, confined to Modules/Custom/.
 *
 * The containment test is the main safeguard of the whole uninstall path: even
 * with a forged path, nothing outside Custom/ — and not Custom/ itself — can be
 * deleted. realpath() is what makes it hold, by resolving any symlink or ".."
 * before the comparison.
 *
 * @param string $dir Directory to delete.
 * @return bool True when the directory is gone.
 */
function upd_rrmdir($dir) {
    $base = realpath(upd_custom_dir());
    $real = realpath($dir);
    if ($base === false || $real === false) return false;
    if ($real === $base || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return false;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) { if (!rmdir($f->getPathname()))  return false; }
        else             { if (!unlink($f->getPathname())) return false; }
    }
    return rmdir($real);
}

/**
 * Drop the tables a module declared.
 *
 * Views are handled too: a module that renamed its tables (cmod_rename_table)
 * leaves a view under the old name for the duration of the transition, and
 * DROP TABLE does not remove a view. Without this, uninstalling would leave
 * orphan views pointing at tables that no longer exist.
 *
 * @param array $tables Names, already filtered by upd_module_tables().
 * @return array The names actually processed.
 */
function upd_drop_tables($tables) {
    $done = [];

    foreach ($tables as $t) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;   // second guard before any SQL

        $rs   = safe_r_sql("SELECT TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . StrSafe_DB($t) . " LIMIT 1", false, true);
        $row  = $rs ? safe_fetch($rs) : null;
        $type = $row ? $row->TABLE_TYPE : null;

        if ($type === 'VIEW') safe_w_sql("DROP VIEW IF EXISTS `$t`");
        else                  safe_w_sql("DROP TABLE IF EXISTS `$t`");

        $done[] = $t;
    }
    return $done;
}

/* =======================================================================
 * The shared library, and the repository's other modules
 * =====================================================================*/

/**
 * Local version.json of _shared. This file lives in _shared/.
 *
 * @return array|null
 */
function upd_local_shared_version() {
    $f = __DIR__ . '/version.json';
    if (!is_file($f)) return null;

    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/**
 * Remote version.json of _shared.
 *
 * @param array $cfg Module configuration, for the repository coordinates.
 * @return array Decoded manifest, or ['_error' => message].
 */
function upd_remote_shared_version($cfg) {
    $root = upd_raw_root($cfg);
    if (!$root) return ['_error' => upd_text('ErrBadGitHubUrl')];

    $raw = upd_gh_raw("$root/_shared/version.json", $cfg['github_token'] ?? '');
    if ($raw === false || $raw === '') return ['_error' => upd_text('ErrSharedVersionUnreadable')];

    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['version'])) return ['_error' => upd_text('ErrSharedVersionInvalid')];

    return $d;
}

/**
 * Re-download _shared from the repository.
 *
 * Called on every module update, so the shared library — including this file
 * and uninstall.php — stays aligned with the modules that depend on it, without
 * anyone having to run install.sh again.
 *
 * @param array $cfg Module configuration.
 * @return array ['ok' => N, 'fail' => [...], 'version' => X], or ['_error' => …].
 */
function upd_sync_shared($cfg) {
    $remote = upd_remote_shared_version($cfg);
    if (isset($remote['_error'])) return $remote;

    $files = (!empty($remote['files']) && is_array($remote['files'])) ? $remote['files'] : [];
    $root  = upd_raw_root($cfg);
    $token = $cfg['github_token'] ?? '';
    $ok    = 0;
    $fail  = [];

    foreach ($files as $rel) {
        // The manifest comes from the repository, so a path in it is untrusted:
        // ".." or a leading slash would write outside _shared/.
        if (!is_string($rel) || $rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
            $fail[] = (string)$rel;
            continue;
        }

        $content = upd_gh_raw("$root/_shared/$rel", $token);
        if ($content !== false && $content !== '') {
            $dest = __DIR__ . '/' . $rel;
            $dir  = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dest, $content);
            $ok++;
        } else {
            $fail[] = $rel;
        }
    }
    return ['ok' => $ok, 'fail' => $fail, 'version' => $remote['version']];
}

/**
 * The modules published in the repository: root folders holding a module.json.
 *
 * One API call, using the recursive git tree, rather than one call per folder —
 * GitHub's unauthenticated rate limit is low enough to matter.
 *
 * @param array $cfg Module configuration.
 * @return array Module names, sorted, or ['_error' => message].
 */
function upd_remote_modules($cfg) {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) return ['_error' => upd_text('ErrBadGitHubUrl')];

    $branch = $cfg['github_branch'] ?: 'main';
    $token  = $cfg['github_token'] ?? '';
    $api    = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}/git/trees/"
            . rawurlencode($branch) . "?recursive=1";

    $tree = upd_gh_fetch($api, $token);
    if (isset($tree['_error'])) return $tree;
    if (empty($tree['tree']) || !is_array($tree['tree'])) return ['_error' => upd_text('ErrTreeUnreadable')];

    $mods = [];
    foreach ($tree['tree'] as $node) {
        if (($node['type'] ?? '') !== 'blob') continue;
        if (preg_match('#^([A-Za-z0-9._-]+)/module\.json$#', $node['path'] ?? '', $m)) {
            if ($m[1][0] === '_' || $m[1][0] === '.') continue;
            $mods[] = $m[1];
        }
    }

    $mods = array_values(array_unique($mods));
    sort($mods);
    return $mods;
}

/**
 * Install another module of the same repository into Custom/.
 *
 * The caller must have checked that $name is actually in the repository.
 *
 * @param array $cfg Configuration of the module doing the installing.
 * @param string $name Module to install.
 * @return array ['version', 'ok', 'fail', 'shared'], or ['_error' => message].
 */
function upd_install_module($cfg, $name) {
    if (!upd_valid_module_name($name)) return ['_error' => upd_text('MsgBadModuleName')];

    // Modules sit at the root of the repository, whatever folder the current one
    // happens to live in.
    $tcfg = $cfg;
    $tcfg['github_path'] = $name;

    $remote = upd_remote_version($tcfg);
    if (isset($remote['_error'])) return ['_error' => $remote['_error']];

    $files = (!empty($remote['files']) && is_array($remote['files'])) ? $remote['files'] : [];
    if (!$files) return ['_error' => upd_text('MsgNoFilesList')];

    $dest = upd_custom_dir() . '/' . $name;
    if (!is_dir($dest)) mkdir($dest, 0755, true);

    // module.json is local configuration and is never in files[], so it is
    // fetched separately — and never over an existing one, which would discard
    // a token or a branch the administrator set here.
    if (!is_file($dest . '/module.json')) {
        $mj = upd_gh_raw(upd_raw_base($tcfg) . '/module.json', $cfg['github_token'] ?? '');
        if ($mj !== false && $mj !== '') file_put_contents($dest . '/module.json', $mj);
    }

    $res    = upd_sync_files($tcfg, $dest, $files);
    $shared = upd_sync_shared($cfg);

    return ['version' => $remote['version'], 'ok' => $res['ok'], 'fail' => $res['fail'], 'shared' => $shared];
}
