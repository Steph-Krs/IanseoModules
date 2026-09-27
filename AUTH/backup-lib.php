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
 */

/** Default settings. */
function aut_backup_defaults()
{
    return array(
        'enabled'           => true,
        'dir'               => '/var/backups/ianseo',
        'keep_days'         => 14,
        'files'             => true,
        'required_for_core' => true,
        'remote'            => '',
        'remote_keep_days'  => 30,
    );
}

/** Effective settings: defaults merged with config.local.json → "backup", normalised. */
function aut_backup_config($all = null)
{
    if ($all === null) $all = function_exists('aut_local_config') ? aut_local_config() : array();
    $c = array_merge(aut_backup_defaults(), is_array($all['backup'] ?? null) ? $all['backup'] : array());
    $c['enabled']           = !empty($c['enabled']);
    $c['files']             = !empty($c['files']);
    $c['required_for_core'] = !empty($c['required_for_core']);
    $c['dir']               = rtrim(str_replace('\\', '/', trim((string) $c['dir'])), '/');
    $c['remote']            = trim((string) $c['remote']);
    $c['keep_days']         = max(1, min(3650, intval($c['keep_days'])));
    $c['remote_keep_days']  = max(1, min(3650, intval($c['remote_keep_days'])));
    return $c;
}

/** Is $path inside $root? Works for paths that do not exist yet. */
function aut_backup_path_inside($path, $root)
{
    $norm = function ($p) {
        $p = str_replace('\\', '/', (string) $p);
        $real = @realpath($p);
        if ($real !== false) $p = str_replace('\\', '/', $real);
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
    if ($dir === '') return 'Aucun dossier de sauvegarde configuré.';
    if (!preg_match('#^(/|[A-Za-z]:/)#', $dir)) return 'Le dossier doit être un chemin absolu (ex. /var/backups/ianseo).';
    if (strpos($dir, '..') !== false) return 'Le chemin ne doit pas contenir « .. ».';
    if (aut_backup_path_inside($dir, HTDOCS)) {
        return 'Le dossier est à l\'intérieur du site web : les sauvegardes y seraient téléchargeables. '
            . 'Choisissez un dossier hors de ' . HTDOCS . '.';
    }
    $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
    $cmd = 'sudo install -d -o ' . $user . ' -g ' . $user . ' -m 0700 ' . escapeshellarg($dir);
    if (!is_dir($dir) && (!$create || !@mkdir($dir, 0700, true))) {
        $fix = $cmd;
        return 'Le dossier n\'existe pas et le serveur web ne peut pas le créer.';
    }
    if (!is_writable($dir)) {
        $fix = $cmd;
        return 'Le dossier existe mais le serveur web ne peut pas y écrire.';
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

/** Backup file name pattern → [kind, timestamp] or null. Only our files match. */
function aut_backup_parse_name($name)
{
    if (!preg_match('/^ianseo-(db|files)-(\d{8})-(\d{6})\.(sql\.gz|tar\.gz)$/', $name, $m)) return null;
    // The two groups above are fixed-width runs of ASCII digits, so bytes and
    // characters are the same thing here and the byte functions are exact.
    $ts = mktime(intval(substr($m[3], 0, 2)), intval(substr($m[3], 2, 2)), intval(substr($m[3], 4, 2)),
        intval(substr($m[2], 4, 2)), intval(substr($m[2], 6, 2)), intval(substr($m[2], 0, 4)));
    return array($m[1], $ts);
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
 * Database dump → $dir/ianseo-db-<stamp>.sql.gz. Returns the path, or false with $err.
 */
function aut_backup_db($dir, $stamp, &$err, &$log)
{
    global $CFG;
    $err = ''; $log = array();
    $bin = aut_backup_mysqldump_bin();
    if ($bin === '') { $err = 'mysqldump introuvable sur le serveur.'; return false; }

    // Host may be "host:port" in ianseo's config.
    $host = (string) $CFG->W_HOST; $port = '';
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = $m[2]; }
    $q = function ($v) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $v) . '"'; };
    $opt = "[client]\nhost=" . $q($host) . "\nuser=" . $q($CFG->W_USER) . "\npassword=" . $q($CFG->W_PASS) . "\n"
         . ($port !== '' ? "port=$port\n" : '');

    $optFile = tempnam(sys_get_temp_dir(), 'autmy');
    @chmod($optFile, 0600);
    file_put_contents($optFile, $opt);

    $part  = $dir . '/.ianseo-db-' . $stamp . '.sql.part';
    $final = $dir . '/ianseo-db-' . $stamp . '.sql.gz';
    $cmd = escapeshellarg($bin)
        . ' --defaults-extra-file=' . escapeshellarg($optFile)
        . ' --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4'
        . ' --result-file=' . escapeshellarg($part)
        . ' ' . escapeshellarg($CFG->DB_NAME) . ' 2>&1';
    $rc = 1;
    exec($cmd, $log, $rc);
    @unlink($optFile);

    // mysqldump ends a complete dump with "-- Dump completed": a truncated one does not.
    $ok = $rc === 0 && is_file($part) && filesize($part) > 0;
    if ($ok) {
        $fh = fopen($part, 'rb');
        fseek($fh, max(0, filesize($part) - 200));
        $ok = strpos((string) fread($fh, 200), 'Dump completed') !== false;
        fclose($fh);
    }
    if (!$ok) {
        @unlink($part);
        $err = 'mysqldump a échoué' . ($rc !== 0 ? " (code $rc)" : ' (dump incomplet)') . '.';
        return false;
    }

    // Streamed compression: the dump may be far bigger than memory_limit.
    $in = fopen($part, 'rb');
    $gz = gzopen($final, 'wb6');
    while (!feof($in)) gzwrite($gz, fread($in, 1048576));
    fclose($in);
    gzclose($gz);
    @unlink($part);
    @chmod($final, 0600);
    if (!is_file($final) || filesize($final) === 0) { $err = 'Compression du dump impossible.'; return false; }
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
    if ($bin === '') { $err = 'tar introuvable sur le serveur.'; return false; }
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
        $err = "Archive des fichiers impossible (tar, code $rc).";
        return false;
    }
    rename($part, $final);
    @chmod($final, 0600);
    return $final;
}

/**
 * Deletes local backups older than $days. The newest backup of each kind is always
 * kept, whatever its age: if the nightly job stopped for a month, the last good copy
 * must not disappear with the others.
 */
function aut_backup_rotate($dir, $days)
{
    $removed = array();
    $limit = time() - $days * 86400;
    $seen = array();
    foreach (aut_backup_list($dir) as $b) {
        if (empty($seen[$b['kind']])) { $seen[$b['kind']] = true; continue; }
        if ($b['time'] < $limit && @unlink($b['file'])) $removed[] = basename($b['file']);
    }
    return $removed;
}

/** Runs rclone with the given arguments (already escaped). Returns the exit code. */
function aut_backup_rclone($args, &$out)
{
    $out = array();
    $bin = aut_backup_rclone_bin();
    if ($bin === '') { $out[] = 'rclone n\'est pas installé sur le serveur.'; return 127; }
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
    if (!aut_backup_remote_valid($remote)) { $out = array('Destination invalide (format attendu : nom:dossier).'); return false; }
    $opt = ' --contimeout 20s --timeout 60s ';
    if (aut_backup_rclone('mkdir' . $opt . escapeshellarg($remote), $out) !== 0) return false;
    return aut_backup_rclone('lsf --max-depth 1' . $opt . escapeshellarg($remote), $out) === 0;
}

/**
 * Full run. $say is called with each log line. Returns
 *   ['local_ok' => bool, 'remote' => 'off'|'ok'|'fail', 'files' => [paths]].
 * 'local_ok' is what the core update depends on.
 */
function aut_backup_run($say)
{
    $c = aut_backup_config();
    $res = array('local_ok' => false, 'remote' => 'off', 'files' => array());
    if (!$c['enabled']) { $say('Sauvegarde désactivée (config.local.json → backup.enabled).'); return $res; }

    $fix = '';
    if ($p = aut_backup_dir_problem($c['dir'], $fix)) {
        $say('ÉCHEC : ' . $p . ' (' . $c['dir'] . ')');
        if ($fix !== '') $say('  À lancer une fois sur le serveur : ' . $fix);
        return $res;
    }

    // Never fill the disk: MySQL usually lives on the same partition and stops when full.
    $prev = 0;
    foreach (aut_backup_list($c['dir']) as $b) $prev += $b['size'];
    $need = max(200 * 1048576, (int) ($prev / max(1, count(aut_backup_list($c['dir']))) * 3));
    $free = @disk_free_space($c['dir']);
    if ($free !== false && $free < $need) {
        $say('ÉCHEC : espace disque insuffisant (' . round($free / 1048576) . ' Mo libres, ~' . round($need / 1048576) . ' Mo nécessaires).');
        return $res;
    }

    $stamp = function_exists('aut_log_time') ? aut_log_time('Ymd-His') : date('Ymd-His');
    $err = ''; $log = array();

    $db = aut_backup_db($c['dir'], $stamp, $err, $log);
    foreach ($log as $l) $say('  | ' . $l);
    if (!$db) { $say('ÉCHEC base : ' . $err); return $res; }
    $say('Base : ' . basename($db) . ' (' . round(filesize($db) / 1048576, 1) . ' Mo)');
    $res['files'][] = $db;

    if ($c['files']) {
        $fa = aut_backup_files($c['dir'], $stamp, $err, $log);
        foreach ($log as $l) $say('  | ' . $l);
        if (!$fa) { $say('ÉCHEC fichiers : ' . $err); return $res; }
        $say('Fichiers : ' . basename($fa) . ' (' . round(filesize($fa) / 1048576, 1) . ' Mo)');
        $res['files'][] = $fa;
    }
    $res['local_ok'] = true;

    $gone = aut_backup_rotate($c['dir'], $c['keep_days']);
    $say('Rotation locale (' . $c['keep_days'] . ' j) : ' . ($gone ? count($gone) . ' ancienne(s) copie(s) supprimée(s)' : 'rien à supprimer'));

    if ($c['remote'] !== '') {
        if (!aut_backup_remote_valid($c['remote'])) {
            $say('ÉCHEC copie en ligne : destination invalide « ' . $c['remote'] . ' ».');
            $res['remote'] = 'fail';
            return $res;
        }
        $dest = escapeshellarg($c['remote']);
        $ok = true;
        foreach ($res['files'] as $f) {
            $out = array();
            $rc = aut_backup_rclone('copy --retries 3 --contimeout 30s ' . escapeshellarg($f) . ' ' . $dest, $out);
            foreach ($out as $l) $say('  | ' . $l);
            if ($rc !== 0) { $ok = false; $say('ÉCHEC copie en ligne de ' . basename($f) . " (rclone, code $rc)"); }
        }
        if ($ok) {
            $say('Copie en ligne : ok → ' . $c['remote']);
            // Prune only after tonight's copy succeeded: the remote is never left empty.
            $out = array();
            aut_backup_rclone('delete ' . $dest . ' --min-age ' . $c['remote_keep_days'] . 'd'
                . ' --include ' . escapeshellarg('ianseo-db-*.sql.gz')
                . ' --include ' . escapeshellarg('ianseo-files-*.tar.gz'), $out);
            foreach ($out as $l) $say('  | ' . $l);
            $say('Rotation en ligne (' . $c['remote_keep_days'] . ' j) : faite.');
        }
        $res['remote'] = $ok ? 'ok' : 'fail';
    }
    return $res;
}
