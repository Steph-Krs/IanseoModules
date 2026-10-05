<?php
/**
 * AUTH module — synchronisation of the CLUB LOGOS by cron (command line only).
 *
 * Goal: no more handling on the organiser side. Natively, each one has to open
 * "Participants › Load lookup table" and tick "Flags" for THEIR competition; here, one daily
 * pass fills a global cache then copies it into every competition under way — the core's
 * printouts (bibs, badges, lists) find the logos without anyone doing anything.
 *
 * Two steps, separate on purpose (see logos-lib.php):
 *   1. DOWNLOAD (network): one logo per approval number in AuthClubLogos;
 *   2. PROPAGATION (local): cache → Flags table + files TV/Photos/{ToCode}-Fl-*.jpg for each
 *      competition not over yet.
 * A network outage therefore never prevents the propagation of what is already cached.
 *
 * crontab (every day at 04:15, after the licence sync of 03:15):
 *   15 4 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-logos.php >> /var/log/ianseo-logosync.log 2>&1
 *
 * Options:
 *   --propagate-only   only runs step 2 (no network access)
 *   --full             downloads everything again, even the logos already refreshed today
 *   --limit=N          stops after N downloads (tuning)
 *
 * config.local.json (optional):
 *   { "logos": { "enabled": true, "delay_ms": 120, "refresh_days": 0, "timeout": 15 } }
 *   "url" and "ioc" are taken from ianseo (LookUpPaths) when missing.
 *
 * LOGO CHANGE: the FFTA endpoint returns neither Last-Modified nor ETag (checked), so no
 * conditional request is possible — the logo has to be downloaded to compare. Hence
 * refresh_days = 0 by default (download everything again). A logo is only written again when
 * it REALLY changed: md5 fingerprint compared in the cache (LgHash) then, at propagation,
 * between the file in place and the cache. A changed logo therefore reaches by itself every
 * competition not over yet that uses it.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;   // no web bootstrap in CLI
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
aut_table_names();   // module updated since the last page opened: see names-lib.php
require_once(dirname(__DIR__) . '/logos-lib.php');

ini_set('memory_limit', '512M');
@set_time_limit(0);

// LOCAL time (ianseo forces PHP to UTC) — see aut_log_time().
function lg_log($msg) { echo '[' . aut_log_time() . '] ' . $msg . "\n"; }
function lg_fail($msg) {
    lg_log('ERROR: ' . $msg);
    aut_log('LOGOSYNC_FAIL', 'cron', 'cli');
    exit(1);
}

/* ---- Options ---- */
$argvAll        = implode(' ', array_slice($argv, 1));
$propagateOnly  = strpos($argvAll, '--propagate-only') !== false;
$full           = strpos($argvAll, '--full') !== false;
$limit          = preg_match('/--limit=(\d+)/', $argvAll, $m) ? intval($m[1]) : 0;

/* ---- Lock against a double run (file distinct from the licence sync) ---- */
$lock = fopen(__DIR__ . '/.logos.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    lg_fail('a logo synchronisation is already running.');
}

if (!aut_logos_enabled()) {
    lg_log('Logo synchronisation turned off (config.local.json → logos.enabled = false).');
    exit(0);
}

aut_logos_schema();
$cfg     = aut_logos_config();
$delayMs = max(0, intval($cfg['delay_ms'] ?? 120));
// refresh_days = 0 (DEFAULT): download everything again at each pass.
//
// It is the only setting that detects a CHANGE of logo: the FFTA endpoint returns neither
// Last-Modified nor ETag (checked), so no conditional request is possible — the logo has to
// be downloaded to compare. The cost is modest (~1600 logos, ~50 MB, ~7 min once a night)
// and only the logos really CHANGED are written again afterwards.
//
// N > 0: only take again what is older than N days (saves bandwidth, at the price of a
// detection delay). ⚠️ Do NOT set 1 with a daily cron: the club downloaded a few minutes after
// the start of the previous pass would be judged "fresh" at the next pass and skipped one
// night out of two. Use 0, or 2 and more.
$days    = max(0, intval($cfg['refresh_days'] ?? 0));
$timeout = max(3, intval($cfg['timeout'] ?? 15));

/* ================================================================= */
/* Step 1 — download of the missing or outdated logos                 */
/* ================================================================= */
$dl = array('ok' => 0, 'same' => 0, 'none' => 0, 'fail' => 0);

if (!$propagateOnly) {
    lg_log('Source: ' . aut_logos_url());
    $codes = aut_logos_club_codes();
    lg_log(count($codes) . ' club approval number(s) known (federation file + competitions).');

    // By default (refresh_days = 0): EVERYTHING is downloaded again, the only way to spot a
    // changed logo. Sorting by age only exists when the operator turns it on.
    $todo = $codes;
    if (!$full && $days > 0) {
        $fresh = array();
        $rs = safe_r_sql("SELECT LgCode FROM AuthClubLogos
            WHERE LgFetched IS NOT NULL AND LgFetched > DATE_SUB(NOW(), INTERVAL $days DAY)", false, true);
        while ($rs && ($r = safe_fetch($rs))) $fresh[trim($r->LgCode)] = true;
        $todo = array_values(array_filter($codes, function ($c) use ($fresh) { return empty($fresh[$c]); }));
        lg_log(count($todo) . ' to refresh (the others are less than ' . $days . ' d old).');
    } else {
        lg_log('All will be downloaded again — the only way to spot a CHANGED logo, '
            . 'the FFTA returning neither Last-Modified nor ETag.');
    }

    $n = 0;
    foreach ($todo as $code) {
        if ($limit && $n >= $limit) { lg_log('Limit --limit=' . $limit . ' reached.'); break; }
        $r = aut_logos_fetch_one($code, $timeout);
        $dl[$r] = ($dl[$r] ?? 0) + 1;
        $n++;
        if ($n % 100 === 0) {
            lg_log("  … $n/" . count($todo) . " (new/updated {$dl['ok']}, unchanged {$dl['same']}, no logo {$dl['none']}, failures {$dl['fail']})");
        }
        if ($delayMs > 0) usleep($delayMs * 1000);   // stay polite with the federation server
    }
    lg_log("Download done: {$dl['ok']} new/updated, {$dl['same']} unchanged, "
        . "{$dl['none']} without logo, {$dl['fail']} failures.");

    // A TOTAL failure is abnormal (network cut, endpoint moved): it is reported, but the
    // propagation of what is already cached still follows.
    if ($n > 0 && $dl['fail'] === $n) {
        lg_log('WARNING: no download succeeded — check the access to ' . aut_logos_url());
    }
}

/* ================================================================= */
/* Step 2 — local propagation to the competitions not over yet        */
/* ================================================================= */
$tours = aut_logos_active_tournaments();
lg_log(count($tours) . ' competition(s) not over yet to feed.');
$tot = array('written' => 0, 'current' => 0, 'missing' => 0, 'failed' => 0);
$dirPhotos = $CFG->DOCUMENT_PATH . 'TV/Photos';
if (!is_writable($dirPhotos)) {
    lg_log('WARNING: ' . $dirPhotos . ' is NOT writable by this account — the logos cannot be '
        . 'set (ianseo needs it too: flags, photos, badges, update status file).');
}
foreach ($tours as $tid) {
    $r = aut_logos_sync_tournament($tid);
    foreach ($r as $k => $v) $tot[$k] = ($tot[$k] ?? 0) + $v;
    if ($r['written']) lg_log("  competition $tid: {$r['written']} logo(s) set.");
    if ($r['failed']) lg_log("  competition $tid: {$r['failed']} write FAILURE(S).");
}
lg_log("Propagation done: {$tot['written']} file(s) written, {$tot['current']} already up to date, "
    . "{$tot['missing']} club(s) without a cached logo"
    . ($tot['failed'] ? ", {$tot['failed']} write FAILURE(S) (permissions of TV/Photos?)" : '') . '.');
if ($tot['failed']) {
    aut_log('LOGOSYNC_FAIL', 'cron', 'cli');
    lg_log('Done WITH FAILURES.');
    exit(1);
}

$st = aut_logos_stats();
lg_log('Cache: ' . $st['with'] . ' logos / ' . $st['total'] . ' known clubs ('
    . $st['without'] . ' without a logo on the FFTA side, ' . round($st['bytes'] / 1048576, 1) . ' MB).');

aut_log('LOGOSYNC_OK', 'cron', 'cli');
lg_log('Done.');
