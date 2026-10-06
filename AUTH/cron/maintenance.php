<?php
/**
 * AUTH module — nightly maintenance window, in ONE script (command line only).
 *
 * Runs in a row, each step only started once the previous one has ended:
 *
 *   maintenance ON → BACKUP → unlocking → ianseo core update → update of the Custom modules
 *   → AUTH redeployment → licence sync → logo sync → points of sale erasing → locking
 *   → maintenance OFF
 *
 * NON-NEGOTIABLE INVARIANT: the maintenance is ALWAYS turned off at the end, including when a
 * step fails, when the script is interrupted (Ctrl-C, SIGTERM) or when it dies on a fatal
 * error — otherwise the server would stay on the 503 page for ever. Hence the
 * register_shutdown_function set BEFORE anything else, and the signal handlers.
 *
 * Each step is INDEPENDENT and can be turned off; the failure of one does not prevent the
 * next ones (a network outage at the FFTA must not deprive the server of its module update,
 * nor leave the maintenance on).
 *
 * The heavy steps run as SUB-PROCESSES. This is not a detail:
 *  - sync-licences.php / sync-logos.php / update-core.php end with `exit()` — included
 *    directly, they would kill the orchestrator before the maintenance is turned off;
 *  - after a core update, ianseo erased Modules/Authentication/ and replaced files: a new
 *    process loads the right version of the code;
 *  - a fatal error in one of them stays contained.
 *
 * crontab (one line replaces those of the syncs):
 *   15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/maintenance.php >> /var/log/ianseo-maintenance.log 2>&1
 *
 * config.local.json (the commands are specific to the server; an empty command = step
 * skipped, which makes the script harmless on a development machine):
 *   { "maintenance": {
 *       "on":     "sudo /usr/local/bin/ianseo-maintenance-on",
 *       "off":    "sudo /usr/local/bin/ianseo-maintenance-off",
 *       "unlock": "",   ← empty: unlocking done by root in the cron line
 *       "lock":   "",   ← (serveur/cron/ianseo-nightly), never through sudo for www-data
 *       "steps":  { "core": false, "modules": true, "licences": true, "logos": true },
 *       "ping_url": ""   ← optional heartbeat (healthchecks.io…), see aut_backup_ping()
 *   },
 *   "backup": { "enabled": true, "dir": "/var/backups/ianseo", "keep_days": 14, … } }
 *   (details: backup-lib.php; can be set from admin/config.php)
 *
 * Options: --dry-run (runs nothing, shows the plan), --core (forces the core update for this
 * run), --no-core, --no-backup, --only=backup,modules,licences,logos
 *
 * The log is written in English, like ianseo's own scripts: it is read on the server, not in
 * ianseo. The failed steps are recorded as codes (see aut_backup_step_label()), which the
 * administrator banner shows in the visitor's language.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
aut_table_names();   // module updated since the last page opened: see names-lib.php

@set_time_limit(0);
ini_set('memory_limit', '512M');

$T0 = microtime(true);
// LOCAL time: ianseo forces PHP to UTC, and this log is read next to the lines of the system
// scripts (local time). See aut_log_time().
function mt_log($msg) { echo '[' . aut_log_time() . '] ' . $msg . "\n"; }
function mt_step($t)  { mt_log(''); mt_log('=== ' . $t . ' ==='); }

/* ------------------------------------------------------------------ */
/* Options                                                             */
/* ------------------------------------------------------------------ */
$args    = array_slice($argv, 1);
$argsStr = implode(' ', $args);
$dryRun  = in_array('--dry-run', $args, true);
// bytes: an ASCII command-line option
$only    = preg_match('/--only=([a-z,]+)/i', $argsStr, $m) ? array_filter(explode(',', strtolower($m[1]))) : null;

$cfg   = aut_local_config()['maintenance'] ?? array();
$steps = is_array($cfg['steps'] ?? null) ? $cfg['steps'] : array();

/** Is a step asked for? (--only first, then the config, then the default) */
function mt_want($name, $default) {
    global $only, $steps;
    if ($only !== null) return in_array($name, $only, true);
    return array_key_exists($name, $steps) ? !empty($steps[$name]) : $default;
}

// The CORE update is off by default: it rewrites ianseo files and applies database
// migrations, with no way back. To turn on only with backups in place (see SERVEUR.md).
$doCore     = mt_want('core', false);
if (in_array('--core', $args, true))    $doCore = true;
if (in_array('--no-core', $args, true)) $doCore = false;
// Backup: driven by config.local.json → backup.enabled (default: yes), not by steps — it is
// a feature on its own, set from admin/config.php.
require_once(dirname(__DIR__) . '/backup-lib.php');
$bkCfg      = aut_backup_config();
$doBackup   = $bkCfg['enabled'] && ($only === null || in_array('backup', $only, true));
if (in_array('--no-backup', $args, true)) $doBackup = false;
$doModules  = mt_want('modules',  true);
$doLicences = mt_want('licences', true);
$doLogos    = mt_want('logos',    true);

/* ------------------------------------------------------------------ */
/* Lock: never two maintenance windows at once                         */
/* ------------------------------------------------------------------ */
$lock = fopen(__DIR__ . '/.maintenance.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    mt_log('ERROR: a maintenance window is already running.');
    exit(1);
}

/* ------------------------------------------------------------------ */
/* GUARANTEED exit from maintenance                                    */
/* ------------------------------------------------------------------ */
$GLOBALS['MT_ON'] = false;   // was the maintenance turned on BY US?

function mt_exec($cmd, $label) {
    global $dryRun;
    $cmd = trim((string) $cmd);
    if ($cmd === '') { mt_log("  ($label: no command configured — skipped)"); return true; }
    if ($dryRun)     { mt_log("  [dry-run] $label: $cmd"); return true; }
    $out = array(); $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    foreach ($out as $l) mt_log('    | ' . $l);
    mt_log('  ' . $label . ': ' . ($rc === 0 ? 'ok' : "FAILED (code $rc)"));
    return $rc === 0;
}

function mt_maintenance_off() {
    if (empty($GLOBALS['MT_ON'])) return;
    $GLOBALS['MT_ON'] = false;
    $cfg = aut_local_config()['maintenance'] ?? array();
    mt_log('Leaving maintenance mode.');
    mt_exec($cfg['off'] ?? '', 'maintenance OFF');
}

// Set BEFORE any action: covers the fatal error, the die() and the normal end.
register_shutdown_function(function () {
    if (!empty($GLOBALS['MT_ON'])) {
        mt_log('!! Unexpected end of the script — safety exit from maintenance.');
        mt_maintenance_off();
    }
});
// Interruptions (Ctrl-C, service stop): same guarantee.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach (array(SIGINT, SIGTERM, SIGHUP) as $sig) {
        @pcntl_signal($sig, function ($s) { mt_log("!! Signal $s received."); mt_maintenance_off(); exit(1); });
    }
}

/**
 * Runs a module PHP script in the BACKGROUND, detached, its output appended to the same
 * log as this script. Used for the off-site copy of the backups, which can take far
 * longer than the maintenance itself (17 min for 125 MB on the first server): it must
 * neither keep the site closed nor delay the re-locking of the files by root (the cron
 * line locks as soon as this script ends). Returns false when detaching is impossible
 * (not Linux, output not redirected to a file — manual run in a terminal): the caller
 * then uploads in the foreground.
 */
function mt_php_detached($script, $args = '') {
    global $dryRun;
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
    if ($dryRun) { mt_log('  [dry-run] in the background: ' . $cmd); return true; }
    if (DIRECTORY_SEPARATOR !== '/') return false;
    $log = @readlink('/proc/self/fd/1');   // the file cron redirected this script's output to
    if ($log === false || $log === '' || $log[0] !== '/' || !is_file($log) || !is_writable($log)) return false;
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
    exec($setsid . 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &');
    return true;
}

/** Runs a PHP script of the module in a NEW process. $rc receives its exit code. */
function mt_php($script, $args = '', &$rc = 0) {
    global $dryRun;
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
    if ($dryRun) { mt_log('  [dry-run] ' . $cmd); $rc = 0; return true; }
    $out = array(); $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    // The "libpng warning: iCCP …" warnings come from the C library (slightly malformed ICC
    // profiles in the logos), are fully harmless and can amount to dozens of lines a night:
    // they are counted instead of copied, so the log stays readable. Everything else is kept.
    $noise = 0;
    foreach ($out as $l) {
        if (stripos(ltrim($l), 'libpng warning:') === 0) { $noise++; continue; }
        mt_log('  | ' . $l);
    }
    if ($noise) mt_log("  | ($noise libpng warning(s) skipped — ICC profiles of the logos, harmless)");
    return $rc === 0;
}

/* ================================================================== */
/* Run                                                                 */
/* ================================================================== */
$yn = function ($b) { return $b ? 'yes' : 'no'; };
mt_log('Maintenance window — start' . ($dryRun ? ' [DRY-RUN]' : ''));
mt_log('Steps: backup=' . $yn($doBackup) . ', core=' . $yn($doCore) . ', modules=' . $yn($doModules)
    . ', licences=' . $yn($doLicences) . ', logos=' . $yn($doLogos));

$failed = array();   // step codes, see aut_backup_step_label()

/* ---- 1. Maintenance ON ---- */
mt_step('1/8 Maintenance mode on');
if (trim((string) ($cfg['on'] ?? '')) !== '' && !$dryRun) $GLOBALS['MT_ON'] = true;
if (!mt_exec($cfg['on'] ?? '', 'maintenance ON')) {
    // When turning it on fails, do not run updates on a server open to the public.
    $GLOBALS['MT_ON'] = false;
    mt_log('STOP: maintenance mode could not be turned on — no update started.');
    aut_log('MAINT_FAIL', 'cron: maintenance ON', 'cli');
    if (!$dryRun) aut_backup_ping(true);
    exit(1);
}

/* ---- 2. Backup of the database + files ---- */
// Site closed → consistent copy. Taken BEFORE the core update: it is what allows going back
// when a database migration goes wrong (ianseo has no way back). No local backup ⇒ no core
// update this night (setting: backup.required_for_core). A failure of the ONLINE copy alone
// blocks nothing: the local copy is enough to go back.
$backupOk = false;
if ($doBackup) {
    mt_step('2/8 Backup of the database and files');
    $bkRc = 1;
    // LOCAL copy only: the off-site upload waits until the site has reopened (end of script).
    mt_php(__DIR__ . '/backup.php', '--local', $bkRc);
    $backupOk = ($bkRc === 0);
    if ($backupOk) mt_log('  local backup: ok');
    else           { $failed[] = 'backup'; mt_log('  backup: FAILED' . ($bkRc !== 1 ? " (code $bkRc)" : '')); }
}
if ($doCore && !$dryRun && !$backupOk && $bkCfg['required_for_core']) {
    $doCore = false;
    $failed[] = 'core-skipped';
    mt_log('');
    mt_log('!! Core update NOT started this night: no valid backup to go back to.'
        . ($bkCfg['enabled'] ? '' : ' (backup turned off)')
        . ' Setting: config.local.json → backup.required_for_core.');
}

/* ---- 3. Unlocking of the files (needed by the core update) ---- */
if ($doCore) {
    mt_step('3/8 Unlocking of the files');
    if (!mt_exec($cfg['unlock'] ?? '', 'unlock')) {
        // Core files still read-only: the update would fail for sure ("… must be writable by
        // the server"). Nothing having been unlocked, the final re-locking is skipped too.
        $failed[] = 'unlock';
        $doCore = false;
        mt_log('!! Core update NOT started: the files could not be unlocked.'
            . ' maintenance.unlock must stay empty: unlocking is done by root in /etc/cron.d/ianseo-nightly.');
    }
}

/* ---- 4. Update of the ianseo core ---- */
if ($doCore) {
    mt_step('4/8 Update of the ianseo core');
    $statusFile = HTDOCS . '/TV/Photos/updating.json';
    @unlink($statusFile);
    mt_php(__DIR__ . '/update-core.php');
    // The core leaves through exit(0) even on error: the verdict is in the status file.
    if (!$dryRun) {
        $d = is_file($statusFile) ? @json_decode((string) @file_get_contents($statusFile)) : null;
        // "Already up to date": ianseo.net answers "NothingToDo" and the core files it as
        // error=1 — yet it is the NORMAL outcome of almost every night. Counting it as a
        // failure raised a false alarm every night (seen for real on 2026-09-29).
        // Recognised by the text of the language key, in the current language, with the
        // English wording as a fallback.
        $coreMsg     = trim(strip_tags((string) ($d->msg ?? '')));
        $nothingToDo = function_exists('get_text') ? trim(strip_tags((string) get_text('NothingToDo', 'Install'))) : '';
        $upToDate    = $d && $coreMsg !== ''
            && (($nothingToDo !== '' && $coreMsg === $nothingToDo) || stripos($coreMsg, 'is up to date') !== false);
        if ($upToDate) {
            mt_log('  core update: already up to date');
        } elseif (!$d || !empty($d->error) || empty($d->finished)) {
            $failed[] = 'core';
            mt_log('  core update: FAILED or unfinished' . ($d && !empty($d->msg) ? ' — ' . strip_tags((string) $d->msg) : ''));
        } else {
            mt_log('  core update: ok');
        }
    }
}

/* ---- 5. Update of the Custom modules ---- */
if ($doModules) {
    mt_step('5/8 Update of the modules');
    $shared = HTDOCS . '/Modules/Custom/_shared/update-lib.php';
    if (!is_file($shared)) {
        mt_log('  _shared/update-lib.php missing — step skipped.');
    } else {
        require_once $shared;
        // A managed module = a folder of Custom/ holding module.json (invariant of the standard).
        foreach (glob(HTDOCS . '/Modules/Custom/*/module.json') as $mj) {
            $dir  = dirname($mj);
            $name = basename($dir);
            $mcfg = upd_load_config($dir);
            $loc  = upd_local_version($dir);
            $rem  = upd_remote_version($mcfg);
            if (!empty($rem['_error'])) {
                mt_log("  $name: cannot read the remote version (" . $rem['_error'] . ')');
                $failed[] = "module:$name";
                continue;
            }
            $lv = $loc['version'] ?? '0';
            $rv = $rem['version'];
            if (upd_compare($lv, $rv) !== 'update') { mt_log("  $name: up to date (v$lv)"); continue; }
            mt_log("  $name: v$lv → v$rv" . ($dryRun ? ' [dry-run]' : ''));
            if ($dryRun) continue;
            $r = upd_sync_files($mcfg, $dir, $rem['files'] ?? array());
            upd_sync_shared($mcfg);   // the shared library follows each update
            mt_log("    {$r['ok']} file(s) updated" . ($r['fail'] ? ', FAILURES: ' . implode(', ', $r['fail']) : ''));
            if ($r['fail']) $failed[] = "module:$name";
        }
    }
}

/* ---- 6. Redeployment of the authentication ---- */
// A core update erases Modules/Authentication/, and an update of the AUTH module may change
// dist/. The self-repair would do it at the first web request, but better start the syncs
// from a consistent server.
if (($doCore || $doModules) && !$dryRun) {
    mt_step('6/8 Redeployment of the authentication');
    if (function_exists('aut_dist_status') && function_exists('aut_deploy')) {
        $st = aut_dist_status();
        if (!$st['deployed'] || $st['drift']) {
            $errs = array();
            $ok = aut_deploy($errs);
            mt_log('  redeployment: ' . ($ok ? 'ok' : 'FAILED — ' . implode(' ; ', $errs)));
            if (!$ok) $failed[] = 'deploy';
        } else {
            mt_log('  deployed files already matching.');
        }
    }
}

/* ---- 7. Syncs (licences then logos) ---- */
if ($doLicences) {
    mt_step('7a/8 Licence synchronisation');
    if (!mt_php(__DIR__ . '/sync-licences.php')) { $failed[] = 'licences'; mt_log('  licences: FAILED'); }
    else mt_log('  licences: ok');
}
if ($doLogos) {
    // AFTER the licences on purpose: the list of clubs is derived from them.
    mt_step('7b/8 Club logo synchronisation');
    if (!mt_php(__DIR__ . '/sync-logos.php')) { $failed[] = 'logos'; mt_log('  logos: FAILED'); }
    else mt_log('  logos: ok');
}

/* ---- 7c. Points of sale: erasing the day after a competition ---- */
// Volunteers without a licence, nicknames of settled visitors, sessions and QR codes (see
// shop/lib/purge.php). Also run by the pages of the points of sale, at most once an hour: this
// step only makes it happen even when nobody opens them.
if (is_file(dirname(__DIR__) . '/shop/cron/purge.php')) {
    mt_step('7c/8 Points of sale: erasing after the competitions');
    if (!mt_php(dirname(__DIR__) . '/shop/cron/purge.php')) { $failed[] = 'shop-purge'; mt_log('  points of sale: FAILED'); }
    else mt_log('  points of sale: ok');
}

/* ---- 8. Re-locking + leaving maintenance ---- */
if ($doCore) {
    mt_step('8/8 Re-locking of the files');
    if (!mt_exec($cfg['lock'] ?? '', 'lock')) $failed[] = 'lock';
}

mt_step('Leaving maintenance');
mt_maintenance_off();

/* ---- Site reopened: off-site copy of the backups ---- */
if ($doBackup && ($backupOk || $dryRun) && $bkCfg['remote'] !== '') {
    mt_step('Online copy of the backups (site reopened)');
    if (mt_php_detached(__DIR__ . '/backup.php', '--upload')) {
        if (!$dryRun) mt_log('  started in the background: its result is appended further down this log ("Online copy: …").');
    } else {
        $upRc = 0;
        mt_php(__DIR__ . '/backup.php', '--upload', $upRc);
        if ($upRc === 0) mt_log('  online copy: ok');
        else { $failed[] = 'online-backup'; mt_log('  online copy: FAILED' . ($upRc !== 2 ? " (code $upRc)" : '')); }
    }
}

$duration = round(microtime(true) - $T0);
// The failed steps travel in the journal's user column (64 bytes, cut on a character
// boundary): the administrator banner (menu.php) names them without reading this log.
// A dry run is not a night: it must not tell the banner that the nightly job works.
if ($failed) {
    mt_log('Done in ' . $duration . ' s — FAILURES: ' . implode(', ', $failed));
    if (!$dryRun) {
        aut_log('MAINT_PARTIAL', mb_strcut('cron: ' . implode(', ', $failed), 0, 64, 'UTF-8'), 'cli');
        aut_backup_ping(true);
    }
    exit(1);
}
mt_log('Done in ' . $duration . ' s — all ok.');
if (!$dryRun) {
    aut_log('MAINT_OK', 'cron', 'cli');
    aut_backup_ping(false);
}
