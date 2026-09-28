<?php
/**
 * Sends the archive built by api/build.php, then removes its working folder.
 *
 * Only the session that started the archive, with the same competition open, can
 * fetch it. The folder is removed once the archive has been sent, including when
 * the browser gives up halfway: the files hold names and licence numbers, and
 * have no reason to stay on the server once delivered.
 */

require_once __DIR__ . '/lib/boot.php';

scs_require_access();

$job  = (string)($_GET['job'] ?? '');
$data = scs_job_load($job);
$zip  = $data ? scs_job_dir($job) . DIRECTORY_SEPARATOR . 'archive.zip' : '';

if (!$data || !is_file($zip)) {
    $PAGE_TITLE = scs_text('ModuleName');
    include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
    echo '<div class="scs-msg">' . scs_t('ErrJob') . ' <a href="' . scs_esc(scs_url() . 'index.php') . '">'
        . scs_t('BackToPage') . '</a></div>';
    include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
    exit;
}

unset($_SESSION['SCS_JOBS'][$job]);
session_write_close();
ignore_user_abort(true);
@set_time_limit(0);

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $data['zip'] . '"');
header('Content-Length: ' . filesize($zip));
header('Cache-Control: no-store');
readfile($zip);

scs_job_remove($job);
