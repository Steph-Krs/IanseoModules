<?php
/**
 * AUTH module — nightly maintenance window, in ONE script (command line only).
 *
 * Runs in a row, each step only started once the previous one has ended:
 *
 *   1. what is to be installed tonight? — site OPEN: the core asks ianseo.net
 *      (cron/check-core.php), each module reads its version.json in the repository, and the
 *      files of a module to update are downloaded in advance (_shared 1.3.6+)
 *   2. ONLY when something is to be installed: maintenance ON → BACKUP → unlocking → core
 *      update → modules put in place → AUTH redeployment → locking → maintenance OFF;
 *      otherwise: BACKUP, site open
 *   3. licence sync → logo sync → points of sale → accounts → foreign files — site OPEN
 *   4. off-site copy of the backups, in the background
 *
 * The site is closed only for what cannot be done while it serves pages: putting new code in
 * place. Measured on a test server in October 2026, it used to stay closed 5 to 8 minutes
 * every night, more than half of it to download the club logos again and two minutes more to
 * download a module file by file; now: not at all on a night without update, a few seconds to
 * a minute on a night with one.
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
 * Options: --dry-run (installs nothing, shows the plan — the update checks themselves do run,
 * they only read), --core (forces the core update for this run), --no-core, --no-backup,
 * --only=backup,modules,licences,logos, --close (closes the site even with nothing to install:
 * to check that maintenance on/off work, e.g. "--only=none --close" at installation time)
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
    mt_last_line($out, $rc);
    return $rc === 0;
}

/** Keeps the last line written by a command: the detail of a failure, for the journal. */
function mt_last_line($out, $rc) {
    $last = '';
    foreach ($out as $l) if (trim($l) !== '' && stripos(ltrim($l), 'libpng warning:') !== 0) $last = trim($l);
    $GLOBALS['MT_LAST'] = $last !== '' ? $last : ($rc !== 0 ? "code $rc" : '');
}

/**
 * Journal line of a step (admin/config.php reads them): ok, or failed with the last line the
 * step wrote. Nothing when $since is given and the step wrote its own lines since (a script
 * that reports in detail), nor on a dry run.
 */
function mt_job($code, $ok, $since = null) {
    global $dryRun;
    if ($dryRun || !function_exists('aut_job_log')) return;
    if ($since !== null && aut_job_written($code, $since)) return;
    aut_job_log($code, $ok ? 'ok' : 'fail', $ok ? 'JbStepOk' : 'JbStepFail', $ok ? null : (string) ($GLOBALS['MT_LAST'] ?? ''));
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
    // Files of a module downloaded in advance and not put in place: nothing to keep.
    foreach ($GLOBALS['MT_STAGED'] ?? array() as $st) {
        if (function_exists('upd_discard_staged')) upd_discard_staged($st);
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
 * not delay the re-locking of the files by root (the cron line locks as soon as this script
 * ends). Returns false when detaching is impossible (not Linux, output not redirected to a
 * file — manual run in a terminal): the caller then uploads in the foreground.
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

/**
 * Runs a PHP script of the module in a NEW process. $rc receives its exit code. $evenDry: a
 * script that only reads (the update checks) runs on a dry run too, so the plan is the real one.
 */
function mt_php($script, $args = '', &$rc = 0, $evenDry = false) {
    global $dryRun;
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
    if ($dryRun && !$evenDry) { mt_log('  [dry-run] ' . $cmd); $rc = 0; return true; }
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
    // The core's safe_error() answers an SQL error with an HTML page and exit(0): a step killed
    // that way was logged "ok" (foreign federation files, every night from 12 to 23 October 2026).
    if ($rc === 0 && preg_match('#<div>(Error \d+:[^<]*)#', implode("\n", $out), $e)) {
        $rc = 1;
        $out[] = 'SQL error: ' . trim($e[1]);
        mt_log('  | SQL error: ' . trim($e[1]));
    }
    mt_last_line($out, $rc);
    return $rc === 0;
}

/** Backup of the database and files (backup.php --local). True when the local copy is there. */
function mt_backup($title) {
    global $failed;
    mt_step($title);
    $rc = 1;
    // LOCAL copy only: the off-site upload waits until the end of the script.
    mt_php(__DIR__ . '/backup.php', '--local', $rc);
    $ok = ($rc === 0);
    if ($ok) mt_log('  local backup: ok');
    else     { $failed[] = 'backup'; mt_log('  backup: FAILED' . ($rc !== 1 ? " (code $rc)" : '')); }
    mt_job('backup', $ok);
    return $ok;
}

/**
 * Redeployment of the authentication. A core update erases Modules/Authentication/, and an
 * update of the AUTH module may change dist/. The self-repair would do it at the first web
 * request, but better start the syncs from a consistent server.
 */
function mt_redeploy() {
    global $failed, $dryRun;
    if ($dryRun) return;
    mt_step('Redeployment of the authentication');
    if (!function_exists('aut_dist_status') || !function_exists('aut_deploy')) return;
    $st = aut_dist_status();
    if ($st['deployed'] && !$st['drift']) { mt_log('  deployed files already matching.'); return; }
    $errs = array();
    $ok = aut_deploy($errs);
    mt_log('  redeployment: ' . ($ok ? 'ok' : 'FAILED — ' . implode(' ; ', $errs)));
    if (!$ok) $failed[] = 'deploy';
    $GLOBALS['MT_LAST'] = implode(' ; ', $errs);
    mt_job('deploy', $ok);
}

/* ================================================================== */
/* Run                                                                 */
/* ================================================================== */
// The site stays open during most of the night: the night's work yields the processor and the
// disk to its pages. Inherited by every sub-process, the off-site copy included. What MariaDB
// does itself (writing 70 000 licences) is not lowered by this.
if (function_exists('proc_nice')) @proc_nice(10);
if (DIRECTORY_SEPARATOR === '/' && is_executable('/usr/bin/ionice')) {
    @exec('/usr/bin/ionice -c 2 -n 7 -p ' . getmypid() . ' 2>/dev/null');
}

$yn = function ($b) { return $b ? 'yes' : 'no'; };
mt_log('Maintenance window — start' . ($dryRun ? ' [DRY-RUN]' : ''));
mt_log('Steps: backup=' . $yn($doBackup) . ', core=' . $yn($doCore) . ', modules=' . $yn($doModules)
    . ', licences=' . $yn($doLicences) . ', logos=' . $yn($doLogos));

$failed  = array();   // step codes, see aut_backup_step_label()
$closedS = 0;         // seconds the site stayed closed

/* ---- 1. What is to be installed tonight — site open ---- */
mt_step('1. Updates to install tonight (site open)');
$coreUpdate = false;
if ($doCore) {
    $ccRc = 0;
    mt_php(__DIR__ . '/check-core.php', '', $ccRc, true);
    if ($ccRc === 10) {
        $coreUpdate = true;
    } elseif ($ccRc !== 0) {
        $failed[] = 'core';
        mt_log('  core: no update tonight, the question could not be answered.');
        if (!$dryRun) aut_job_log('core', 'fail', 'JbStepFail', (string) ($GLOBALS['MT_LAST'] ?? ''));
    } elseif (!$dryRun) {
        aut_job_log('core', 'ok', 'JbCoreUpToDate');
    }
}

// Modules to update: name => [cfg, dir, from, to, files, staged]. 'staged' holds the files
// downloaded in advance; null with a _shared older than 1.3.6, which downloads once closed.
$pending  = array();
$dryClose = false;
$GLOBALS['MT_STAGED'] = array();
if ($doModules) {
    $shared = HTDOCS . '/Modules/Custom/_shared/update-lib.php';
    if (!is_file($shared)) {
        mt_log('  _shared/update-lib.php missing — modules not checked.');
    } else {
        require_once $shared;
        $stage = function_exists('upd_fetch_files');
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
                if (!$dryRun) aut_job_log("module:$name", 'fail', 'JbModuleRemote', (string) $rem['_error']);
                continue;
            }
            $lv = $loc['version'] ?? '0';
            $rv = $rem['version'];
            if (upd_compare($lv, $rv) !== 'update') { mt_log("  $name: up to date (v$lv)"); continue; }
            mt_log("  $name: v$lv → v$rv" . ($dryRun ? ' [dry-run]' : ''));
            if ($dryRun) { $dryClose = true; continue; }
            $p = array('cfg' => $mcfg, 'dir' => $dir, 'from' => $lv, 'to' => $rv,
                'files' => $rem['files'] ?? array(), 'staged' => null);
            if ($stage) {
                $t = microtime(true);
                $st = upd_fetch_files($mcfg, $p['files']);
                if (isset($st['_error'])) {
                    mt_log('    ' . $st['_error'] . ': files downloaded once the site is closed.');
                } elseif ($st['fail']) {
                    // All or nothing: a half-updated module would run two versions at once. The
                    // module stays as it is, and the update is tried again the next night.
                    upd_discard_staged($st);
                    $failed[] = "module:$name";
                    mt_log('    download FAILED (' . implode(', ', $st['fail']) . "): left in v$lv, tried again next night.");
                    aut_job_log("module:$name", 'fail', 'JbModuleFiles',
                        array('from' => $lv, 'to' => $rv, 'n' => 0, 'fail' => implode(', ', $st['fail'])));
                    continue;
                } else {
                    $p['staged'] = $st;
                    $GLOBALS['MT_STAGED'][$name] = $st;
                    mt_log(sprintf('    %d file(s) downloaded in %d s, put in place once the site is closed',
                        count($st['files']), round(microtime(true) - $t)));
                }
            }
            $pending[$name] = $p;
        }
    }
}
$close = $coreUpdate || $pending || $dryClose || in_array('--close', $args, true);
mt_log('  → ' . ($close ? 'site closed for the installation only' : 'nothing to install: the site stays open all night'));

/* ---- 2. Installation — the only part with the site closed ---- */
$backupOk = false;
if ($close) {
    mt_step('2. Maintenance mode on');
    $tClose = microtime(true);
    if (trim((string) ($cfg['on'] ?? '')) !== '' && !$dryRun) $GLOBALS['MT_ON'] = true;
    if (!mt_exec($cfg['on'] ?? '', 'maintenance ON')) {
        // When turning it on fails, do not run updates on a server open to the public.
        $GLOBALS['MT_ON'] = false;
        mt_log('STOP: maintenance mode could not be turned on — no update started.');
        aut_log('MAINT_FAIL', 'cron: maintenance ON', 'cli');
        if (!$dryRun) aut_job_log('maintenance', 'fail', 'JbMaintOnFail', (string) ($GLOBALS['MT_LAST'] ?? ''));
        if (!$dryRun) aut_backup_ping(true);
        exit(1);
    }

    // Site closed → consistent copy. Taken BEFORE the core update: it is what allows going back
    // when a database migration goes wrong (ianseo has no way back). No local backup ⇒ no core
    // update this night (setting: backup.required_for_core). A failure of the ONLINE copy alone
    // blocks nothing: the local copy is enough to go back.
    if ($doBackup) $backupOk = mt_backup('3. Backup of the database and files (site closed)');
    if ($coreUpdate && !$dryRun && !$backupOk && $bkCfg['required_for_core']) {
        $coreUpdate = false;
        $failed[] = 'core-skipped';
        aut_job_log('core', 'skip', 'JbCoreSkipped');
        mt_log('');
        mt_log('!! Core update NOT started this night: no valid backup to go back to.'
            . ($bkCfg['enabled'] ? '' : ' (backup turned off)')
            . ' Setting: config.local.json → backup.required_for_core.');
    }

    // Unlocking of the files (needed by the core update)
    if ($coreUpdate) {
        mt_step('4a. Unlocking of the files');
        if (!mt_exec($cfg['unlock'] ?? '', 'unlock')) {
            // Core files still read-only: the update would fail for sure ("… must be writable by
            // the server"). Nothing having been unlocked, the final re-locking is skipped too.
            $failed[] = 'unlock';
            mt_job('unlock', false);
            $coreUpdate = false;
            mt_log('!! Core update NOT started: the files could not be unlocked.'
                . ' maintenance.unlock must stay empty: unlocking is done by root in /etc/cron.d/ianseo-nightly.');
        }
    }

    // Update of the ianseo core
    if ($coreUpdate) {
        mt_step('4b. Update of the ianseo core');
        $statusFile = HTDOCS . '/TV/Photos/updating.json';
        @unlink($statusFile);
        mt_php(__DIR__ . '/update-core.php');
        // The core leaves through exit(0) even on error: the verdict is in the status file.
        if (!$dryRun) {
            $d = is_file($statusFile) ? @json_decode((string) @file_get_contents($statusFile)) : null;
            // "Already up to date": ianseo.net answers "NothingToDo" and the core files it as
            // error=1 — the check of step 1 said otherwise, but the release may have moved since.
            // Recognised by the text of the language key, in the current language, with the
            // English wording as a fallback.
            $coreMsg     = trim(strip_tags((string) ($d->msg ?? '')));
            $nothingToDo = function_exists('get_text') ? trim(strip_tags((string) get_text('NothingToDo', 'Install'))) : '';
            $upToDate    = $d && $coreMsg !== ''
                && (($nothingToDo !== '' && $coreMsg === $nothingToDo) || stripos($coreMsg, 'is up to date') !== false);
            if ($upToDate) {
                mt_log('  core update: already up to date');
                aut_job_log('core', 'ok', 'JbCoreUpToDate');
            } elseif (!$d || !empty($d->error) || empty($d->finished)) {
                $failed[] = 'core';
                mt_log('  core update: FAILED or unfinished' . ($d && !empty($d->msg) ? ' — ' . strip_tags((string) $d->msg) : ''));
                aut_job_log('core', 'fail', 'JbStepFail', $d && !empty($d->msg) ? strip_tags((string) $d->msg) : (string) ($GLOBALS['MT_LAST'] ?? ''));
            } else {
                mt_log('  core update: ok');
                aut_job_log('core', 'ok', 'JbCoreUpdated');
            }
        }
    }

    // Update of the Custom modules: the files are already here, putting them in place is quick.
    if ($pending) {
        mt_step('5. Update of the modules');
        foreach ($pending as $name => $p) {
            if ($p['staged']) {
                $r = upd_apply_files($p['staged'], $p['dir']);
                upd_discard_staged($p['staged']);
                unset($GLOBALS['MT_STAGED'][$name]);
            } else {
                $r = upd_sync_files($p['cfg'], $p['dir'], $p['files']);
            }
            upd_sync_shared($p['cfg']);   // the shared library follows each update
            mt_log("  $name: v{$p['from']} → v{$p['to']}, {$r['ok']} file(s) updated" . ($r['fail'] ? ', FAILURES: ' . implode(', ', $r['fail']) : ''));
            if ($r['fail']) $failed[] = "module:$name";
            aut_job_log("module:$name", $r['fail'] ? 'fail' : 'ok', $r['fail'] ? 'JbModuleFiles' : 'JbModuleUpdated',
                array('from' => $p['from'], 'to' => $p['to'], 'n' => intval($r['ok']), 'fail' => implode(', ', $r['fail'])));
        }
    }

    if ($doCore || $doModules) mt_redeploy();

    if ($coreUpdate) {
        mt_step('6. Re-locking of the files');
        $ok = mt_exec($cfg['lock'] ?? '', 'lock');
        if (!$ok) $failed[] = 'lock';
        mt_job('lock', $ok);
    }

    mt_step('Leaving maintenance');
    mt_maintenance_off();
    $closedS = (int) round(microtime(true) - $tClose);
    mt_log("  site closed for $closedS s");
} else {
    // Nothing to install: the same copy as a live copy (InnoDB snapshot, no table locked),
    // with the files. The nightly set is still the one a restore starts from.
    if ($doBackup) $backupOk = mt_backup('2. Backup of the database and files (site open)');
    if ($doCore || $doModules) mt_redeploy();
}

/* ---- 3. Syncs — site open: none of them needs it closed ---- */
if ($doLicences) {
    // The licensee list is replaced in ONE transaction: until its end, a search sees the former
    // list, complete (cron/sync-licences.php).
    mt_step('7a. Licence synchronisation');
    $since = $dryRun ? 0 : aut_job_last_id();
    $ok = mt_php(__DIR__ . '/sync-licences.php');
    if (!$ok) { $failed[] = 'licences'; mt_log('  licences: FAILED'); }
    else mt_log('  licences: ok');
    mt_job('licences', $ok, $since);
}
if ($doLogos) {
    // AFTER the licences on purpose: the list of clubs is derived from them. Each logo file is
    // replaced in one go (logos-lib.php): a page never shows half an image.
    mt_step('7b. Club logo synchronisation');
    $ok = mt_php(__DIR__ . '/sync-logos.php');
    if (!$ok) { $failed[] = 'logos'; mt_log('  logos: FAILED'); }
    else mt_log('  logos: ok');
    mt_job('logos', $ok);
}

/* ---- 7c. Points of sale: erasing the day after a competition ---- */
// Volunteers without a licence, nicknames of settled visitors, sessions and QR codes (see
// shop/lib/purge.php). Also run by the pages of the points of sale, at most once an hour: this
// step only makes it happen even when nobody opens them.
if (is_file(dirname(__DIR__) . '/shop/cron/purge.php')) {
    mt_step('7c. Points of sale: erasing after the competitions');
    $ok = mt_php(dirname(__DIR__) . '/shop/cron/purge.php');
    if (!$ok) { $failed[] = 'shop-purge'; mt_log('  points of sale: FAILED'); }
    else mt_log('  points of sale: ok');
    mt_job('shop-purge', $ok);
}

/* ---- 7d. Accounts of archers without an FFTA licence: erasing ---- */
// One month after their last sign-in and their last competition (booking/lib/other.php).
if (is_file(dirname(__DIR__) . '/booking/cron/purge-other.php')) {
    mt_step('7d. Archers without an FFTA licence: erasing of the accounts');
    $ok = mt_php(dirname(__DIR__) . '/booking/cron/purge-other.php');
    if (!$ok) { $failed[] = 'other-purge'; mt_log('  accounts: FAILED'); }
    else mt_log('  accounts: ok');
    mt_job('other-purge', $ok);
}

/* ---- 7e. Licensee files of foreign federations ---- */
// Italy, Canada, Slovenia, Baltic countries (booking/lib/fedlic.php): the accounts of their
// archers are taken from these files, refreshed here.
if (is_file(dirname(__DIR__) . '/booking/cron/sync-fed.php')) {
    mt_step('7e. Licensee files of foreign federations');
    $since = $dryRun ? 0 : aut_job_last_id();
    $ok = mt_php(dirname(__DIR__) . '/booking/cron/sync-fed.php');
    if (!$ok) { $failed[] = 'fed-sync'; mt_log('  files: FAILED'); }
    else mt_log('  files: ok');
    mt_job('fed-sync', $ok, $since);
}

/* ---- 4. Off-site copy of the backups, in the background ---- */
if ($doBackup && ($backupOk || $dryRun) && $bkCfg['remote'] !== '') {
    mt_step('Online copy of the backups (in the background)');
    if (mt_php_detached(__DIR__ . '/backup.php', '--upload')) {
        if (!$dryRun) mt_log('  started in the background: its result is appended further down this log ("Online copy: …").');
    } else {
        $upRc = 0;
        mt_php(__DIR__ . '/backup.php', '--upload', $upRc);
        if ($upRc === 0) mt_log('  online copy: ok');
        else { $failed[] = 'online-backup'; mt_log('  online copy: FAILED' . ($upRc !== 2 ? " (code $upRc)" : '')); }
        mt_job('online-backup', $upRc === 0);
    }
}

$duration = round(microtime(true) - $T0);
$closedTxt = $close ? "site closed $closedS s" : 'site open all night';
// The failed steps travel in the journal's user column (64 bytes, cut on a character
// boundary): the administrator banner (menu.php) names them without reading this log.
// A dry run is not a night: it must not tell the banner that the nightly job works.
if ($failed) {
    mt_log('Done in ' . $duration . " s ($closedTxt) — FAILURES: " . implode(', ', $failed));
    if (!$dryRun) {
        aut_log('MAINT_PARTIAL', mb_strcut('cron: ' . implode(', ', $failed), 0, 64, 'UTF-8'), 'cli');
        aut_job_log('maintenance', 'fail', 'JbMaintPartial', array('d' => $duration, 'steps' => $failed, 'closed' => $closedS));
        aut_backup_ping(true);
    }
    exit(1);
}
mt_log('Done in ' . $duration . " s ($closedTxt) — all ok.");
if (!$dryRun) {
    aut_log('MAINT_OK', 'cron', 'cli');
    aut_job_log('maintenance', 'ok', 'JbMaintOk', array('d' => $duration, 'closed' => $closedS));
    aut_backup_ping(false);
}
