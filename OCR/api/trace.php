<?php
/**
 * Endpoint of the trial recordings of the live view (lib/trace.php): the page sends its
 * camera's video, piece by piece, and the record of what it made of it.
 *
 *   POST action=start  meta=<JSON>               csrf=<token>   -> {id, dir}
 *   POST action=video  id=<id> seq=<n> part=<WebM> csrf=<token>
 *   POST action=log    id=<id> lines=<JSON lines> csrf=<token>
 *
 * The video shows archers' names and scores: read-write access to the qualification
 * round is required. Nothing goes to the database.
 */

require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/trace.php';

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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !ocr_token_ok()) {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}
// The token is checked: the session is no longer needed, other pages may run.
session_write_close();

$action = (string)($_POST['action'] ?? '');

if ($action === 'start') {
    ocr_trace_purge();
    $meta = json_decode((string)($_POST['meta'] ?? ''), true);
    $id = ocr_trace_start(is_array($meta) ? $meta : []);
    $JSON['error'] = 0;
    $JSON['id'] = $id;
    $JSON['dir'] = ocr_trace_dir($id);
    JsonOut($JSON);
}

$id = ocr_trace_id($_POST['id'] ?? '');
if ($id === '') {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}

if ($action === 'video') {
    $ok = !empty($_FILES['part']['tmp_name']) && is_uploaded_file($_FILES['part']['tmp_name'])
        && ocr_trace_video($id, (int)($_POST['seq'] ?? 0), $_FILES['part']['tmp_name']);
    $JSON['error'] = $ok ? 0 : 1;
    if (!$ok) $JSON['msg'] = ocr_text('ErrUpload');
    JsonOut($JSON);
}

if ($action === 'log') {
    $ok = ocr_trace_log($id, (string)($_POST['lines'] ?? ''));
    $JSON['error'] = $ok ? 0 : 1;
    JsonOut($JSON);
}

$JSON['msg'] = ocr_text('ErrRequest');
JsonOut($JSON);
