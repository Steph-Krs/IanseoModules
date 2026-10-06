<?php
/**
 * AUTH module — nightly backup of the ianseo database and files.
 *
 * Called by cron/backup.php (itself run by cron/maintenance.php inside the
 * maintenance window, so the dump is consistent: nobody writes while it runs).
 *
 * Settings: config.local.json → "backup" (see aut_backup_defaults()).
 * Off-site copy: rclone (https://rclone.org), one tool for Google Drive, Dropbox,
 * OneDrive, S3, SFTP… The cloud authorisation lives in rclone's own config file,
 * owned by the web user: this module never stores a cloud token.
 *
 * Security rules that must not be weakened:
 *  - the backup directory is NEVER inside the web root: a .sql.gz there could be
 *    downloaded by anyone (Apache only denies .json/.bak/.sql/.log, not .gz);
 *  - the MySQL password never appears on a command line (visible in `ps`): it goes
 *    through a 0600 option file deleted right after the dump;
 *  - every value that reaches a shell goes through escapeshellarg(), and the rclone
 *    destination is also checked against a strict pattern;
 *  - binaries (mysqldump, tar, rclone) are looked up, never read from the config:
 *    the config is editable from the web, a binary path there would be a way to run
 *    arbitrary commands from a stolen admin session.
 *
 * Club logos are left out of the dumps by default (backup.logos): on the first real
 * server the logo cache was 59 % of the database and the dump went from 57.6 MB / 11.6 s
 * to 4.1 MB / 2.6 s without it. They are rebuilt by cron/sync-logos.php. What makes this
 * safe is how they are left out — see aut_backup_db().
 *
 * "Live" copies (cron/backup.php --live, every 6 hours, site open) are database dumps
 * only. InnoDB + --single-transaction: a consistent snapshot, no table lock, so nothing
 * waits on them.
 */

require_once __DIR__ . '/lang-lib.php';

/** Default settings. */
function aut_backup_defaults()
{
    return array(
        'enabled'           => true,
        'dir'               => '/var/backups/ianseo',
        'keep_days'         => 14,
        'files'             => true,
        'logos'             => false,
        'required_for_core' => true,
        'remote'            => '',
        'remote_keep_days'  => 30,
        'live'              => true,
        'live_keep_hours'   => 48,
    );
}

/** Effective settings: defaults merged with config.local.json → "backup", normalised. */
function aut_backup_config($all = null)
{
    if ($all === null) $all = function_exists('aut_local_config') ? aut_local_config() : array();
    $c = array_merge(aut_backup_defaults(), is_array($all['backup'] ?? null) ? $all['backup'] : array());
    $c['enabled']           = !empty($c['enabled']);
    $c['files']             = !empty($c['files']);
    $c['logos']             = !empty($c['logos']);
    $c['live']              = !empty($c['live']);
    $c['required_for_core'] = !empty($c['required_for_core']);
    $c['dir']               = rtrim(str_replace('\\', '/', trim((string) $c['dir'])), '/');
    $c['remote']            = trim((string) $c['remote']);
    $c['keep_days']         = max(1, min(3650, intval($c['keep_days'])));
    $c['remote_keep_days']  = max(1, min(3650, intval($c['remote_keep_days'])));
    $c['live_keep_hours']   = max(6, min(720, intval($c['live_keep_hours'])));
    return $c;
}

/** Is $path inside $root? Works for paths that do not exist yet. */
function aut_backup_path_inside($path, $root)
{
    $norm = function ($p) {
        $p = str_replace('\\', '/', (string) $p);
        $real = @realpath($p);
        if ($real !== false) $p = str_replace('\\', '/', $real);
        // bytes: a path or a file name, compared as the file system does
        return rtrim(strtolower($p), '/') . '/';
    };
    return strpos($norm($path), $norm($root)) === 0;
}

/**
 * Checks the backup directory. Returns '' when usable, otherwise a message.
 * $fix receives the one-time shell command that solves the problem, if any.
 */
function aut_backup_dir_problem($dir, &$fix = '', $create = true)
{
    $fix = '';
    $dir = (string) $dir;
    if ($dir === '') return aut_t('BuNoDir');
    if (!preg_match('#^(/|[A-Za-z]:/)#', $dir)) return aut_t('BuNotAbsolute');
    if (strpos($dir, '..') !== false) return aut_t('BuDotDot');
    if (aut_backup_path_inside($dir, HTDOCS)) return aut_t('BuInsideSite', HTDOCS);
    $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
    $cmd = 'sudo install -d -o ' . $user . ' -g ' . $user . ' -m 0700 ' . escapeshellarg($dir);
    if (!is_dir($dir) && (!$create || !@mkdir($dir, 0700, true))) {
        $fix = $cmd;
        return aut_t('BuCannotCreate');
    }
    if (!is_writable($dir)) {
        $fix = $cmd;
        return aut_t('BuCannotWrite');
    }
    return '';
}

/** Looks a binary up in PATH, then in a few usual places. Returns '' if not found. */
function aut_backup_find_bin($names, $extra = array())
{
    $win = DIRECTORY_SEPARATOR === '\\';
    foreach ((array) $names as $n) {
        $out = array(); $rc = 1;
        @exec(($win ? 'where ' : 'command -v ') . escapeshellarg($n) . ($win ? ' 2>NUL' : ' 2>/dev/null'), $out, $rc);
        if ($rc === 0 && !empty($out[0]) && is_file(trim($out[0]))) return trim($out[0]);
    }
    foreach ($extra as $p) if (is_file($p)) return $p;
    return '';
}

function aut_backup_mysqldump_bin()
{
    return aut_backup_find_bin(array('mysqldump', 'mariadb-dump'), array(
        '/usr/bin/mysqldump', '/usr/bin/mariadb-dump',
        dirname(HTDOCS) . '/mysql/bin/mysqldump.exe',   // XAMPP (development)
    ));
}

function aut_backup_rclone_bin()
{
    return aut_backup_find_bin(array('rclone'), array('/usr/bin/rclone', '/usr/local/bin/rclone'));
}

/** rclone destination: "remote:" or "remote:path", nothing a shell could interpret. */
function aut_backup_remote_valid($remote)
{
    return (bool) preg_match('#^[A-Za-z0-9_][A-Za-z0-9_\-.]*:[A-Za-z0-9_\-./ ]*$#', (string) $remote);
}

/**
 * Backup file name pattern → [kind, timestamp] or null. Only our files match.
 * Kinds: db + files = the nightly set, live = daytime database copy.
 */
function aut_backup_parse_name($name)
{
    if (!preg_match('/^ianseo-(db|files|live)-(\d{8})-(\d{6})\.(sql\.gz|tar\.gz)$/', $name, $m)) return null;
    // The stamp is written in the server's local time (aut_log_time): read it back in the
    // same zone, or every age (rotation, health page) would be off by one or two hours.
    static $tz = null;
    if ($tz === null) {
        $all = function_exists('aut_local_config') ? aut_local_config() : array();
        try { $tz = new DateTimeZone((string) ($all['timezone'] ?? 'Europe/Paris')); }
        catch (\Throwable $e) { $tz = new DateTimeZone('UTC'); }
    }
    $d = DateTime::createFromFormat('Ymd His', $m[2] . ' ' . $m[3], $tz);
    return array($m[1], $d ? $d->getTimestamp() : 0);
}

/** Existing backups in $dir, newest first: [['file','kind','time','size'], …]. */
function aut_backup_list($dir)
{
    $out = array();
    if ($dir === '' || !is_dir($dir)) return $out;
    foreach ((array) @scandir($dir) as $f) {
        $p = aut_backup_parse_name($f);
        if (!$p) continue;
        $out[] = array('file' => $dir . '/' . $f, 'kind' => $p[0], 'time' => $p[1], 'size' => (int) @filesize($dir . '/' . $f));
    }
    usort($out, function ($a, $b) { return $b['time'] - $a['time']; });
    return $out;
}

/**
 * Exact names, as stored, of the logo tables present in the database: Flags (the copies
 * the core prints from, per competition) and AuthClubLogos (this module's cache). The
 * stored name matters: mysqldump matches --ignore-table literally, and a Windows server
 * may store them in lower case.
 */
function aut_backup_logo_tables()
{
    $out = array();
    $rs = safe_r_sql("SELECT TABLE_NAME AS t FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
          AND TABLE_NAME IN ('Flags', 'AuthClubLogos')", false, true);
    while ($rs && ($r = safe_fetch($rs))) $out[] = $r->t;
    return $out;
}

/** Does this dump end with mysqldump's "-- Dump completed" line? A truncated one does not. */
function aut_backup_dump_complete($file)
{
    if (!is_file($file) || filesize($file) === 0) return false;
    $fh = fopen($file, 'rb');
    fseek($fh, max(0, filesize($file) - 200));
    $ok = strpos((string) fread($fh, 200), 'Dump completed') !== false;
    fclose($fh);
    return $ok;
}

/**
 * Database dump → $dir/ianseo-<kind>-<stamp>.sql.gz. Returns the path, or false with $err.
 * $kind: 'db' (nightly) or 'live' (daytime copy). $logos: dump the club logos too.
 *
 * Without the logos, their tables still appear in the file, as STRUCTURE ONLY and as
 * "CREATE TABLE IF NOT EXISTS", placed before everything else. So:
 *  - restoring on this server leaves the logos in place (no DROP, no data for them);
 *  - restoring on a new server creates the tables empty, and cron/sync-logos.php --full
 *    fills them again. Leaving the tables out altogether would break ianseo there
 *    (a missing table is a fatal SQL error).
 */
function aut_backup_db($dir, $stamp, &$err, &$log, $kind = 'db', $logos = true)
{
    global $CFG;
    $err = ''; $log = array();
    $bin = aut_backup_mysqldump_bin();
    if ($bin === '') { $err = 'mysqldump not found on the server.'; return false; }

    // Host may be "host:port" in ianseo's config.
    $host = (string) $CFG->W_HOST; $port = '';
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = $m[2]; }
    $q = function ($v) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $v) . '"'; };
    $opt = "[client]\nhost=" . $q($host) . "\nuser=" . $q($CFG->W_USER) . "\npassword=" . $q($CFG->W_PASS) . "\n"
         . ($port !== '' ? "port=$port\n" : '');

    $optFile = tempnam(sys_get_temp_dir(), 'autmy');
    @chmod($optFile, 0600);
    file_put_contents($optFile, $opt);

    $skip  = $logos ? array() : aut_backup_logo_tables();
    $base  = escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($optFile)
        . ' --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4';
    $parts = array();
    $rc    = 0;
    if ($skip) {
        $p = $dir . '/.ianseo-' . $kind . '-' . $stamp . '.logos.part';
        $cmd = $base . ' --no-data --skip-add-drop-table --result-file=' . escapeshellarg($p)
            . ' ' . escapeshellarg($CFG->DB_NAME);
        foreach ($skip as $t) $cmd .= ' ' . escapeshellarg($t);
        exec($cmd . ' 2>&1', $log, $rc);
        $parts[] = $p;
    }
    if ($rc === 0) {
        $p = $dir . '/.ianseo-' . $kind . '-' . $stamp . '.sql.part';
        $cmd = $base . ' --result-file=' . escapeshellarg($p);
        foreach ($skip as $t) $cmd .= ' --ignore-table=' . escapeshellarg($CFG->DB_NAME . '.' . $t);
        exec($cmd . ' ' . escapeshellarg($CFG->DB_NAME) . ' 2>&1', $log, $rc);
        $parts[] = $p;
    }
    @unlink($optFile);

    $ok = $rc === 0;
    foreach ($parts as $p) $ok = $ok && aut_backup_dump_complete($p);
    if (!$ok) {
        foreach ($parts as $p) @unlink($p);
        $err = 'mysqldump failed' . ($rc !== 0 ? " (code $rc)" : ' (incomplete dump)') . '.';
        return false;
    }

    // Streamed compression: the dump may be far bigger than memory_limit. The logo tables'
    // structure comes first, turned into "create only if missing" (see above).
    $final = $dir . '/ianseo-' . $kind . '-' . $stamp . '.sql.gz';
    $gz = gzopen($final, 'wb6');
    foreach ($parts as $i => $p) {
        $in = fopen($p, 'rb');
        if ($skip && $i === 0) {
            while (($line = fgets($in)) !== false) {
                gzwrite($gz, preg_replace('/^CREATE TABLE(?= `)/', '$0 IF NOT EXISTS', $line));
            }
        } else {
            while (!feof($in)) gzwrite($gz, fread($in, 1048576));
        }
        fclose($in);
        @unlink($p);
    }
    gzclose($gz);
    @chmod($final, 0600);
    if (!is_file($final) || filesize($final) === 0) { $err = 'Could not compress the dump.'; return false; }
    return $final;
}

/**
 * Archive of the ianseo files → $dir/ianseo-files-<stamp>.tar.gz.
 * A database rollback needs the matching code: both are taken together.
 */
function aut_backup_files($dir, $stamp, &$err, &$log)
{
    $err = ''; $log = array();
    $bin = aut_backup_find_bin(array('tar'), array('/bin/tar', '/usr/bin/tar'));
    if ($bin === '') { $err = 'tar not found on the server.'; return false; }
    $root  = str_replace('\\', '/', realpath(HTDOCS));
    $base  = basename($root);
    $final = $dir . '/ianseo-files-' . $stamp . '.tar.gz';
    $part  = $dir . '/.ianseo-files-' . $stamp . '.tar.gz.part';
    // TV/Photos holds temporary files (update status, photo cache): not worth keeping.
    $cmd = escapeshellarg($bin) . ' -czf ' . escapeshellarg($part)
        . ' --exclude=' . escapeshellarg($base . '/TV/Photos')
        . ' -C ' . escapeshellarg(dirname($root)) . ' ' . escapeshellarg($base) . ' 2>&1';
    $rc = 2;
    exec($cmd, $log, $rc);
    // GNU tar: 1 = "some files changed while being read" — the archive is still valid.
    if (($rc !== 0 && $rc !== 1) || !is_file($part) || filesize($part) === 0) {
        @unlink($part);
        $err = "Could not archive the files (tar, code $rc).";
        return false;
    }
    rename($part, $final);
    @chmod($final, 0600);
    return $final;
}

/**
 * Deletes local backups older than $days (live copies: older than $liveHours). The newest
 * backup of each kind is always kept, whatever its age: if the nightly job stopped for a
 * month, the last good copy must not disappear with the others.
 */
function aut_backup_rotate($dir, $days, $liveHours = 48)
{
    $removed = array();
    $seen = array();
    foreach (aut_backup_list($dir) as $b) {
        if (empty($seen[$b['kind']])) { $seen[$b['kind']] = true; continue; }
        $limit = time() - ($b['kind'] === 'live' ? $liveHours * 3600 : $days * 86400);
        if ($b['time'] < $limit && @unlink($b['file'])) $removed[] = basename($b['file']);
    }
    return $removed;
}

/**
 * Named lock (cron/.<name>.lock), held until the process ends. Non-blocking: false when
 * another process holds it. "backup" = one dump at a time; "upload" = one off-site copy
 * at a time (a slow uplink must never stack uploads).
 */
function aut_backup_lock($name)
{
    static $held = array();
    if (isset($held[$name])) return true;
    $fh = @fopen(__DIR__ . '/cron/.' . $name . '.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        if ($fh) fclose($fh);
        return false;
    }
    $held[$name] = $fh;
    return true;
}

/**
 * Is the nightly maintenance window running (or a restore, which holds the same lock)?
 * Only tests the lock, never keeps it: holding it would stop the night from starting.
 */
function aut_backup_night_running()
{
    $fh = @fopen(__DIR__ . '/cron/.maintenance.lock', 'c');
    if (!$fh) return false;
    $free = flock($fh, LOCK_SH | LOCK_NB);
    if ($free) flock($fh, LOCK_UN);
    fclose($fh);
    return !$free;
}

/** Runs rclone with the given arguments (already escaped). Returns the exit code. */
function aut_backup_rclone($args, &$out)
{
    $out = array();
    $bin = aut_backup_rclone_bin();
    if ($bin === '') { $out[] = aut_t('CfRcloneKo'); return 127; }
    $rc = 1;
    exec(escapeshellarg($bin) . ' ' . $args . ' 2>&1', $out, $rc);
    return $rc;
}

/**
 * Checks that the rclone destination is reachable AND writable (web test button).
 * mkdir first: a destination that never received a backup does not exist yet, and a
 * bare listing then fails with "directory not found" (rclone exit code 3) although
 * everything is fine — seen on the first real setup. mkdir is a no-op when the folder
 * exists, and proves the write permission the nightly upload needs.
 */
function aut_backup_remote_test($remote, &$out)
{
    if (!aut_backup_remote_valid($remote)) { $out = array(aut_t('BuBadRemote')); return false; }
    $opt = ' --contimeout 20s --timeout 60s ';
    if (aut_backup_rclone('mkdir' . $opt . escapeshellarg($remote), $out) !== 0) return false;
    return aut_backup_rclone('lsf --max-depth 1' . $opt . escapeshellarg($remote), $out) === 0;
}

/**
 * Uploads the given local backup files to the rclone destination, then prunes the
 * remote. Returns 'off' (no destination configured), 'ok', 'busy' (another upload is
 * still running) or 'fail'.
 */
function aut_backup_upload($c, $files, $say, $live = false)
{
    if ($c['remote'] === '') return 'off';
    if (!aut_backup_remote_valid($c['remote'])) {
        $say('FAILED online copy: invalid destination "' . $c['remote'] . '".');
        return 'fail';
    }
    if (!aut_backup_lock('upload')) {
        $say('Online copy not started: a previous upload is still running (the local copy is done).');
        return 'busy';
    }
    $dest = escapeshellarg($c['remote']);
    $ok = true;
    foreach ($files as $f) {
        $out = array();
        $rc = aut_backup_rclone('copy --retries 3 --contimeout 30s ' . escapeshellarg($f) . ' ' . $dest, $out);
        foreach ($out as $l) $say('  | ' . $l);
        if ($rc !== 0) { $ok = false; $say('FAILED online copy of ' . basename($f) . " (rclone, code $rc)"); }
    }
    if (!$ok) return 'fail';
    $say('Online copy: ok → ' . $c['remote']);
    // Prune only after this copy succeeded: the remote is never left empty. Deleted for
    // good: on Google Drive a plain delete only moves to the bin, which still counts
    // against the quota (and with encrypted names, nobody can tell the files apart there).
    // The flag is ignored by every other kind of storage.
    $inc = $live
        ? ' --min-age ' . $c['live_keep_hours'] . 'h --include ' . escapeshellarg('ianseo-live-*.sql.gz')
        : ' --min-age ' . $c['remote_keep_days'] . 'd --include ' . escapeshellarg('ianseo-db-*.sql.gz')
          . ' --include ' . escapeshellarg('ianseo-files-*.tar.gz');
    $out = array();
    aut_backup_rclone('delete ' . $dest . ' --drive-use-trash=false' . $inc, $out);
    foreach ($out as $l) $say('  | ' . $l);
    $say('Online rotation (' . ($live ? $c['live_keep_hours'] . ' h' : $c['remote_keep_days'] . ' d') . '): done.');
    return 'ok';
}

/** Newest local NIGHTLY set (one db dump, plus the files archive of the same run). */
function aut_backup_latest_set($dir)
{
    $set = array(); $stamp = null;
    foreach (aut_backup_list($dir) as $b) {
        if ($b['kind'] === 'live') continue;
        $s = date('YmdHis', $b['time']);
        if ($stamp === null) $stamp = $s;
        if ($s !== $stamp) break;
        $set[] = $b['file'];
    }
    return $set;
}

/**
 * Backup run. $say is called with each log line. $mode:
 *   'all'    — local backup, then off-site copy (manual run);
 *   'local'  — local backup only (inside the maintenance window, site closed);
 *   'upload' — off-site copy of the newest local set only (after the site reopened:
 *              a slow uplink took 17 min for 125 MB on the first real server, and the
 *              site does not need to stay closed for that);
 *   'live'   — daytime database copy, site open, then its off-site copy (cron every
 *              6 hours). Skipped while the nightly window or a restore runs.
 * Returns ['local_ok' => bool, 'remote' => 'off'|'ok'|'busy'|'fail'|'skip',
 * 'files' => [paths], 'skipped' => bool]. 'local_ok' is what the core update depends on.
 */
function aut_backup_run($say, $mode = 'all')
{
    $c = aut_backup_config();
    $res = array('local_ok' => false, 'remote' => 'skip', 'files' => array(), 'skipped' => false);
    if (!$c['enabled']) { $say('Backup turned off (config.local.json → backup.enabled).'); return $res; }

    if ($mode === 'live') {
        $why = !$c['live'] ? 'live copies turned off (backup.live)'
            : (aut_backup_night_running() ? 'nightly maintenance or restore under way' : '');
        if ($why !== '') { $say('Live copy not started: ' . $why . '.'); $res['skipped'] = true; return $res; }
    }
    if ($mode !== 'upload' && !aut_backup_lock('backup')) {
        $say('Backup not started: another backup is running.');
        $res['skipped'] = ($mode === 'live');
        return $res;
    }

    if ($mode === 'upload') {
        $res['files'] = aut_backup_latest_set($c['dir']);
        $res['local_ok'] = (bool) $res['files'];
        if (!$res['files']) { $say('Online copy: no local backup to send.'); return $res; }
        if ($c['remote'] === '') { $res['remote'] = 'off'; return $res; }
        $say('Online copy of ' . implode(', ', array_map('basename', $res['files'])) . '…');
        $res['remote'] = aut_backup_upload($c, $res['files'], $say);
        return $res;
    }

    $fix = '';
    if ($p = aut_backup_dir_problem($c['dir'], $fix)) {
        $say('FAILED: ' . $p . ' (' . $c['dir'] . ')');
        if ($fix !== '') $say('  To run once on the server: ' . $fix);
        return $res;
    }

    // Never fill the disk: MySQL usually lives on the same partition and stops when full.
    $prev = 0;
    foreach (aut_backup_list($c['dir']) as $b) $prev += $b['size'];
    $need = max(200 * 1048576, (int) ($prev / max(1, count(aut_backup_list($c['dir']))) * 3));
    $free = @disk_free_space($c['dir']);
    if ($free !== false && $free < $need) {
        $say('FAILED: not enough disk space (' . round($free / 1048576) . ' MB free, ~' . round($need / 1048576) . ' MB needed).');
        return $res;
    }

    $stamp = function_exists('aut_log_time') ? aut_log_time('Ymd-His') : date('Ymd-His');
    $err = ''; $log = array();

    $live = ($mode === 'live');
    $t0 = microtime(true);
    $db = aut_backup_db($c['dir'], $stamp, $err, $log, $live ? 'live' : 'db', $c['logos']);
    foreach ($log as $l) $say('  | ' . $l);
    if (!$db) { $say('FAILED database: ' . $err); return $res; }
    $say('Database: ' . basename($db) . ' (' . round(filesize($db) / 1048576, 1) . ' MB, '
        . round(microtime(true) - $t0, 1) . ' s' . ($c['logos'] ? '' : ', without the logos') . ')');
    $res['files'][] = $db;

    if ($c['files'] && !$live) {
        $fa = aut_backup_files($c['dir'], $stamp, $err, $log);
        foreach ($log as $l) $say('  | ' . $l);
        if (!$fa) { $say('FAILED files: ' . $err); return $res; }
        $say('Files: ' . basename($fa) . ' (' . round(filesize($fa) / 1048576, 1) . ' MB)');
        $res['files'][] = $fa;
    }
    $res['local_ok'] = true;

    $gone = aut_backup_rotate($c['dir'], $c['keep_days'], $c['live_keep_hours']);
    $say('Local rotation (' . $c['keep_days'] . ' d, live copies ' . $c['live_keep_hours'] . ' h): '
        . ($gone ? count($gone) . ' old cop' . (count($gone) > 1 ? 'ies' : 'y') . ' deleted' : 'nothing to delete'));

    if ($mode === 'all' || $live) $res['remote'] = aut_backup_upload($c, $res['files'], $say, $live);
    return $res;
}

/**
 * Optional heartbeat to a monitoring service (healthchecks.io or alike):
 * config.local.json → maintenance.ping_url, set on the command line only (a URL: locked
 * in the web editor). The nightly run pings its verdict; a failed off-site copy or live
 * copy pings a failure. The service raises the alarm on a failure AND when no ping comes
 * at all — a stopped cron or a dead server, which nothing on the server itself can report.
 */
function aut_backup_ping($fail = false)
{
    $all = function_exists('aut_local_config') ? aut_local_config() : array();
    $url = trim((string) (($all['maintenance'] ?? array())['ping_url'] ?? ''));
    if ($url === '' || !preg_match('#^https://[^\s"\'<>]+$#i', $url)) return false;
    if ($fail) $url = rtrim($url, '/') . '/fail';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false));
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);
        return $ok;
    }
    return @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 10)))) !== false;
}

/**
 * Label of a failed step of the night, as cron/maintenance.php records it (code), in the
 * visitor's language. "module:NAME" keeps the module's name; an unknown word (a record made
 * by an older version, in French) is shown as it is.
 */
function aut_backup_step_label($code)
{
    if (strpos($code, 'module:') === 0) return aut_t('BuStepModule', mb_substr($code, 7));
    $keys = array('backup' => 'BuStepBackup', 'core' => 'BuStepCore', 'core-skipped' => 'BuStepCoreSkipped',
        'unlock' => 'BuStepUnlock', 'lock' => 'BuStepLock', 'deploy' => 'BuStepDeploy',
        'licences' => 'BuStepLicences', 'logos' => 'BuStepLogos', 'online-backup' => 'BuStepOnline',
        'shop-purge' => 'BuStepShopPurge');
    return isset($keys[$code]) ? aut_t($keys[$code]) : $code;
}

/**
 * Problems of the recent nights and live copies, for the administrator banner (menu.php):
 * short sentences, [] when all is well. Silent on a machine without the nightly job
 * (maintenance.on empty: development, fresh install). Never fatal: $force on the query.
 * The events are written by the command-line scripts, whose MySQL session is in UTC —
 * hence UTC_TIMESTAMP() for their age.
 */
function aut_backup_alerts($all = null)
{
    if ($all === null) $all = function_exists('aut_local_config') ? aut_local_config() : array();
    if (trim((string) (($all['maintenance'] ?? array())['on'] ?? '')) === '') return array();
    $rs = safe_r_sql("SELECT AlEvent, AlUser, AlWhen, TIMESTAMPDIFF(HOUR, AlWhen, UTC_TIMESTAMP()) AS Age
        FROM AuthLog
        WHERE AlWhen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)
          AND AlEvent IN ('MAINT_OK', 'MAINT_PARTIAL', 'MAINT_FAIL', 'BACKUP_REMOTE_OK',
                          'BACKUP_REMOTE_FAIL', 'BACKUP_LIVE_OK', 'BACKUP_LIVE_FAIL')
        ORDER BY AlId DESC LIMIT 60", false, true);
    $last = array();   // newest event of each family
    while ($rs && ($r = safe_fetch($rs))) {
        $family = strpos($r->AlEvent, 'MAINT_') === 0 ? 'night'
            : (strpos($r->AlEvent, 'BACKUP_LIVE_') === 0 ? 'live' : 'remote');
        if (!isset($last[$family])) $last[$family] = $r;
    }
    try { $tz = new DateTimeZone((string) ($all['timezone'] ?? 'Europe/Paris')); }
    catch (\Throwable $e) { $tz = new DateTimeZone('Europe/Paris'); }
    $at = function ($r) use ($tz) {
        $d = (new DateTime($r->AlWhen, new DateTimeZone('UTC')))->setTimezone($tz);
        return aut_t('BuAt', array('day' => $d->format('d/m'), 'time' => $d->format('H:i')));
    };

    $out = array();
    $m = $last['night'] ?? null;
    if (!$m || $m->Age > 30) {
        $out[] = $m ? aut_t('BuNoNightSince', $at($m)) : aut_t('BuNoNight3Days');
    } elseif ($m->AlEvent !== 'MAINT_OK') {
        $what = implode(', ', array_map('aut_backup_step_label',
            array_filter(array_map('trim', explode(',', preg_replace('/^cron:?/', '', (string) $m->AlUser))), 'strlen')));
        $out[] = aut_t('BuNightFailed', $at($m)) . ($what !== '' ? ' — ' . $what : '') . '.';
    }
    $r = $last['remote'] ?? null;
    if ($r && $r->AlEvent === 'BACKUP_REMOTE_FAIL' && $r->Age <= 30) {
        $out[] = aut_t('BuRemoteFailed', $at($r));
    }
    $r = $last['live'] ?? null;
    if ($r && $r->AlEvent === 'BACKUP_LIVE_FAIL' && $r->Age <= 12) {
        $out[] = aut_t('BuLiveFailed', $at($r));
    }
    return $out;
}
