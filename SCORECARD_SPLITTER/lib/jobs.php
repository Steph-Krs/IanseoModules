<?php
/**
 * Working folders of the archive downloads.
 *
 * An archive of every club or every archer is built over several short requests
 * (see api/build.php), so that no single request comes near the time limits of
 * PHP or of a proxy in front of the server: each request draws a few files into
 * a working folder, the last one packs them into a ZIP, and download.php sends
 * it and removes the folder.
 *
 * The folder lives in the system's temporary directory, never under the module:
 * anything below Modules/Custom/ may be served by the web server, and these
 * files hold names and licence numbers. A folder belongs to the ianseo session
 * that started it and to the competition open at the time; one left behind by
 * an interrupted download is removed a day later, at the next start.
 */

const SCS_JOB_TTL = 86400;

/**
 * Folder holding every working folder of the module.
 *
 * @return string
 */
function scs_jobs_root() {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ianseo-' . basename(dirname(__DIR__));
}

/**
 * Working folder of one download.
 *
 * @param string $job Identifier, already checked by scs_job_load() or made by scs_job_create().
 * @return string
 */
function scs_job_dir($job) {
    return scs_jobs_root() . DIRECTORY_SEPARATOR . $job;
}

/**
 * Start a download: a new working folder and its description.
 *
 * @param array $data What the following requests need: options, file names, archive name.
 * @return string The job identifier, or '' when the folder cannot be created.
 */
function scs_job_create(array $data) {
    scs_jobs_purge();
    $job = bin2hex(random_bytes(8));
    $dir = scs_job_dir($job);
    if (!@mkdir($dir . DIRECTORY_SEPARATOR . 'files', 0700, true)) return '';

    $data['tour'] = (int)$_SESSION['TourId'];
    $data['next'] = 0;
    if (@file_put_contents($dir . DIRECTORY_SEPARATOR . 'job.json', json_encode($data)) === false) {
        scs_job_remove($job);
        return '';
    }
    $_SESSION['SCS_JOBS'][$job] = (int)$_SESSION['TourId'];
    return $job;
}

/**
 * Description of a download started by this session, for the competition open now.
 *
 * @param mixed $job Identifier from the request.
 * @return array|null
 */
function scs_job_load($job) {
    if (!is_string($job) || !preg_match('/^[a-f0-9]{16}$/', $job)) return null;
    if (($_SESSION['SCS_JOBS'][$job] ?? null) !== (int)$_SESSION['TourId']) return null;
    $file = scs_job_dir($job) . DIRECTORY_SEPARATOR . 'job.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : null;
}

/**
 * Record how far a download has gone.
 *
 * @param string $job
 * @param array $data
 * @return bool
 */
function scs_job_save($job, array $data) {
    return @file_put_contents(scs_job_dir($job) . DIRECTORY_SEPARATOR . 'job.json', json_encode($data)) !== false;
}

/**
 * Remove a working folder and everything in it.
 *
 * Only ever called with an identifier made of hexadecimal digits, so the path
 * cannot leave the module's folder of the temporary directory.
 *
 * @param string $job
 */
function scs_job_remove($job) {
    if (!preg_match('/^[a-f0-9]{16}$/', (string)$job)) return;
    $dir = scs_job_dir($job);
    foreach (['files', ''] as $sub) {
        $path = $sub === '' ? $dir : $dir . DIRECTORY_SEPARATOR . $sub;
        foreach ((array)glob($path . DIRECTORY_SEPARATOR . '*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (is_dir($path)) @rmdir($path);
    }
}

/**
 * Remove the working folders left behind by downloads that never finished, and
 * the reduced copies of images that no document has used for a day.
 */
function scs_jobs_purge() {
    $limit = time() - SCS_JOB_TTL;
    foreach ((array)glob(scs_jobs_root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
        if (@filemtime($dir) < $limit) scs_job_remove(basename($dir));
    }
    foreach ((array)glob(scs_jobs_root() . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . '*') as $f) {
        if (is_file($f) && @filemtime($f) < $limit) @unlink($f);
    }
}

/**
 * Keep the files of a download as they are at its start.
 *
 * Every step then draws from the same picture of the competition, even if an
 * archer is added or moved while the archive is being built, and no step has to
 * read the competition again.
 *
 * @param string $job
 * @param array $files Output of scs_split().
 * @return bool
 */
function scs_job_put_files($job, array $files) {
    return @file_put_contents(scs_job_dir($job) . DIRECTORY_SEPARATOR . 'files.ser', serialize($files)) !== false;
}

/**
 * The files of a download, as kept at its start.
 *
 * @param string $job
 * @return array|null
 */
function scs_job_get_files($job) {
    $data = @file_get_contents(scs_job_dir($job) . DIRECTORY_SEPARATOR . 'files.ser');
    if ($data === false) return null;
    $files = unserialize($data, ['allowed_classes' => false]);
    return is_array($files) ? $files : null;
}

/**
 * Pack the files of a download into its archive.
 *
 * Compressed, although PDF pages are compressed already: the archive of a real
 * challenge (201 clubs) still went from 47 to 36 MB, for two seconds of work.
 *
 * @param string $job
 * @return string Path of the archive, or '' on failure.
 */
function scs_job_zip($job) {
    $dir = scs_job_dir($job);
    $zip = new ZipArchive();
    $out = $dir . DIRECTORY_SEPARATOR . 'archive.zip';
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return '';
    $files = (array)glob($dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . '*.pdf');
    foreach ($files as $f) {
        $zip->addFile($f, basename($f));
    }
    if (!$zip->close()) return '';
    // The archive holds them now: no reason to keep two copies on the disk.
    foreach ($files as $f) @unlink($f);
    return $out;
}
