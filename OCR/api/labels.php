<?php
/**
 * Endpoint of the cells to check (lib/labels.php): the reading page sends the cells it
 * could not make sure of, the checking page shows them and saves what was typed.
 *
 *   POST action=add    items=<JSON>   csrf=<token>   -> {added, skipped}
 *   POST action=next                                 -> {pending, done, items}
 *   POST action=save   labels=<JSON>  csrf=<token>   -> {saved, pending, done, items}
 *
 * Read-write access to the qualification round is required. Nothing goes to the
 * database.
 */

require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/labels.php';

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
$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($action !== 'next' && !ocr_token_ok())) {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}
// The token is checked: the session is no longer needed, other pages may run.
session_write_close();

if ($action === 'add') {
    ocr_label_purge();
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    $JSON = array_merge(['error' => 0, 'msg' => ''], ocr_label_add(is_array($items) ? $items : []));
    JsonOut($JSON);
}

if ($action === 'save') {
    $labels = json_decode((string)($_POST['labels'] ?? ''), true);
    $saved = ocr_label_save(is_array($labels) ? $labels : []);
    $JSON = array_merge(['error' => 0, 'msg' => '', 'saved' => $saved], ocr_label_next());
    JsonOut($JSON);
}

if ($action === 'next') {
    $JSON = array_merge(['error' => 0, 'msg' => ''], ocr_label_next());
    JsonOut($JSON);
}

$JSON['msg'] = ocr_text('ErrRequest');
JsonOut($JSON);
