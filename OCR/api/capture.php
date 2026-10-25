<?php
/**
 * Endpoint of the series capture: stores the photos the page takes, lists them,
 * hands them over as one ZIP archive, and removes them.
 *
 *   POST action=store  photo=<JPEG>  meta=<JSON>  csrf=<token>
 *   GET  action=list
 *   GET  action=zip                      (the archive itself, not JSON)
 *   POST action=clear  csrf=<token>
 *
 * The photos hold archers' names and scores: read-write access to the qualification
 * round is required for everything, listing included. Nothing goes to the database.
 */

require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/capture.php';

$JSON = ['error' => 1, 'msg' => ''];

if (!ocr_has_access(AclReadWrite)) {
    $JSON['msg'] = ocr_text('ErrAccess');
    JsonOut($JSON);
}
// A development tool: refused while the administrator keeps them hidden (lib/dev.php).
if (!ocr_dev_on()) {
    $JSON['msg'] = ocr_text('DevOff');
    JsonOut($JSON);
}

$action = (string)($_REQUEST['action'] ?? '');
$post   = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if (in_array($action, ['store', 'clear'], true) && (!$post || !ocr_token_ok())) {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}
// The token is checked: the session is no longer needed, other pages may run.
session_write_close();
ocr_capture_purge();

if ($action === 'store') {
    if (empty($_FILES['photo']['tmp_name']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $JSON['msg'] = ocr_text('ErrUpload');
        JsonOut($JSON);
    }
    $meta = json_decode((string)($_POST['meta'] ?? ''), true);
    $name = ocr_capture_store($_FILES['photo']['tmp_name'], is_array($meta) ? $meta : []);
    if (!$name) {
        $JSON['msg'] = ocr_text('ErrUpload');
        JsonOut($JSON);
    }
    $JSON['error'] = 0;
    $JSON['name']  = $name;
    $JSON['count'] = count(ocr_capture_list());
    JsonOut($JSON);
}

if ($action === 'list') {
    $list = ocr_capture_list();
    $JSON['error'] = 0;
    $JSON['count'] = count($list);
    $JSON['size']  = array_sum(array_column($list, 'size'));
    $JSON['items'] = array_map(function ($c) {
        return ['name' => $c['name'], 'size' => $c['size'], 'time' => $c['time'], 'barcode' => $c['meta']['barcode'] ?? ''];
    }, $list);
    JsonOut($JSON);
}

if ($action === 'clear') {
    $JSON['error']   = 0;
    $JSON['removed'] = ocr_capture_clear();
    JsonOut($JSON);
}

if ($action === 'zip') {
    $list = ocr_capture_list();
    if (!$list || !class_exists('ZipArchive')) {
        $JSON['msg'] = ocr_text($list ? 'ErrZip' : 'NoCaptures');
        JsonOut($JSON);
    }
    @set_time_limit(0);
    $tmp = tempnam(sys_get_temp_dir(), 'ocr');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $dir = ocr_capture_dir();
    foreach ($list as $c) {
        $zip->addFile($dir . '/' . $c['name'], $c['name']);
        // JPEG does not compress: stored as it is, the archive is built at once.
        $zip->setCompressionName($c['name'], ZipArchive::CM_STORE);
        $json = preg_replace('/\.jpg$/', '.json', $c['name']);
        if (is_file($dir . '/' . $json)) $zip->addFile($dir . '/' . $json, $json);
    }
    $zip->close();
    $code = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($_SESSION['TourCode'] ?? 'competition'));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="scorecards-' . $code . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

$JSON['msg'] = ocr_text('ErrRequest');
JsonOut($JSON);
