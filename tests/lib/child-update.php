<?php
/**
 * Child process: the core update, exactly what the "Update ianseo" page runs. That page
 * is only an interface calling Update/index-action.php; the work is done by
 * Update/UpdateIanseo.php, which this script includes the same way. It downloads from
 * ianseo.net the difference between the files present and the latest release, then
 * runs the database migrations.
 *
 * UpdateIanseo.php ends with JsonOut(), hence exit: the verdict is read by the parent
 * from TV/Photos/updating.json ("error", "finished"), never from the exit code.
 */
require __DIR__ . '/boot.php';

define('DIRNAME', HTDOCS . '/Update');
// Relative includes of UpdateIanseo.php ("FileList.php", "Language/lib.php").
chdir(DIRNAME);

if (!function_exists('writeStatusFile')) {
    // Same implementation as Update/index-action.php.
    function writeStatusFile($file, $data)
    {
        file_put_contents($file . '.tmp', json_encode($data));
        rename($file . '.tmp', $file);
    }
}

$JSON = ['error' => 0, 'msg' => '', 'start' => date('Y-m-d H:i:s'), 'status' => '', 'finished' => 0];
writeStatusFile(HTDOCS . '/TV/Photos/updating.json', $JSON);

$IN_PHP = true;
require DIRNAME . '/UpdateIanseo.php';
