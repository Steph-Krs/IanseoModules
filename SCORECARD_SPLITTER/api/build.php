<?php
/**
 * Builds the archive of every club or every archer, over several short requests.
 *
 * The page calls it three ways, always by POST with the anti-CSRF token:
 *   do=start   the options of the form; answers the job identifier and the number of files
 *   do=step    draws files for a few seconds; answers how many are done
 *   do=finish  packs the files into a ZIP; answers the address to download it from
 * Each step stops after a few seconds and the page calls the next one, so no
 * request comes near the time limit of PHP or of a proxy, whatever the size of
 * the competition, and the page can show how far the work has gone.
 *
 * Answers are JSON, with error = 0 on success and msg holding the reason
 * otherwise. Nothing is written to the database: the files go to a working
 * folder of the system's temporary directory (lib/jobs.php).
 */

require_once dirname(__DIR__) . '/lib/boot.php';

// Seconds of drawing per step: well below any time limit, long enough for the
// cost of reading the competition again to stay small.
const SCS_STEP_SECONDS = 5;

if (!scs_has_access()) JsonOut(['error' => 1, 'msg' => scs_text('ErrAccess')]);
if (!scs_token_ok())   JsonOut(['error' => 1, 'msg' => scs_text('ErrToken')]);

$sessions = scs_sessions();
$do       = (string)($_POST['do'] ?? '');

if ($do === 'start') {
    $comp = scs_competition();
    if ($comp['field']) JsonOut(['error' => 1, 'msg' => scs_text('NotTargetArchery')]);
    if (!class_exists('ZipArchive')) JsonOut(['error' => 1, 'msg' => scs_text('ErrZip')]);

    $opts = scs_options($_POST, $sessions, $comp['distances']);
    if (!$opts['sessions']) JsonOut(['error' => 1, 'msg' => scs_text('ErrNoSession')]);

    $files = scs_split(scs_read_cards($opts['sessions'], $sessions), $opts['mode']);
    if (!$files) JsonOut(['error' => 1, 'msg' => scs_text('ErrNothing')]);

    $zipName = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$_SESSION['TourCode'])
        . ($opts['mode'] === 'archer' ? '-archers' : '-clubs') . '.zip';
    $job = scs_job_create(['opts' => $opts, 'sessions' => $sessions, 'names' => array_keys($files), 'zip' => $zipName]);
    if ($job === '') JsonOut(['error' => 1, 'msg' => scs_text('ErrTemp')]);
    if (!scs_job_put_files($job, $files)) {
        scs_job_remove($job);
        JsonOut(['error' => 1, 'msg' => scs_text('ErrTemp')]);
    }

    JsonOut(['error' => 0, 'job' => $job, 'done' => 0, 'total' => count($files)]);
}

$job  = (string)($_POST['job'] ?? '');
$data = scs_job_load($job);
if (!$data) JsonOut(['error' => 1, 'msg' => scs_text('ErrJob')]);

// Nothing below writes to the session: release it, so that the other pages of
// the same browser are not kept waiting while the files are drawn.
session_write_close();
@set_time_limit(120);

$total = count($data['names']);

if ($do === 'step') {
    $started = microtime(true);
    $files   = scs_job_get_files($job);
    if ($files === null) JsonOut(['error' => 1, 'msg' => scs_text('ErrJob')]);
    $dir = scs_job_dir($job) . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR;

    // One document set up once and copied for each file, as the core does when it
    // saves one scorecard per archer: setting one up reads the competition's header.
    $model = scs_new_pdf($data['opts']);
    while ($data['next'] < $total && microtime(true) - $started < SCS_STEP_SECONDS) {
        $name = $data['names'][$data['next']];
        $pdf  = clone $model;
        scs_draw($pdf, $files[$name], $data['opts'], $data['sessions']);
        $pdf->Output($dir . $name . '.pdf', 'F');
        unset($pdf);
        $data['next']++;
    }
    if (!scs_job_save($job, $data)) JsonOut(['error' => 1, 'msg' => scs_text('ErrTemp')]);
    JsonOut(['error' => 0, 'done' => $data['next'], 'total' => $total]);
}

if ($do === 'finish') {
    if ($data['next'] < $total) JsonOut(['error' => 1, 'msg' => scs_text('ErrJob')]);
    if (scs_job_zip($job) === '') JsonOut(['error' => 1, 'msg' => scs_text('ErrTemp')]);
    JsonOut(['error' => 0, 'url' => scs_url() . 'download.php?job=' . $job]);
}

JsonOut(['error' => 1, 'msg' => scs_text('ErrJob')]);
