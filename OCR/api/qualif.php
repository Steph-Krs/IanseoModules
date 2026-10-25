<?php
/**
 * JSON endpoint of the qualification page: finds whose scorecard was photographed
 * and returns what the reader needs about it (grid, allowed values, what ianseo
 * holds). Answers follow the core's envelope: error (0 = success) and msg.
 *
 *   action=lookup  code=<barcode text>            [dist=<n>]
 *   action=lookup  target=<12A>  [session=<n>]    dist=<n>
 *   action=card    enId=<id>                      dist=<n>
 *   action=roster  -> every archer with a target, as their scorecard prints them
 *   action=layouts -> the scorecard layouts of the competition (ends, arrows, count columns)
 *
 * Read-only: the photo never leaves the browser, only its barcode text does.
 */

require_once dirname(__DIR__) . '/lib/boot.php';

$JSON = ['error' => 1, 'msg' => ''];

if (!ocr_has_access(AclReadOnly)) {
    $JSON['msg'] = ocr_text('ErrAccess');
    JsonOut($JSON);
}
// Nothing below writes to the session: let the other pages of this browser run.
session_write_close();

$action = (string)($_REQUEST['action'] ?? '');
$dist   = (int)($_REQUEST['dist'] ?? 0);

if ($action === 'roster') {
    $JSON['error'] = 0;
    $JSON['roster'] = ocr_roster();
    JsonOut($JSON);
}

if ($action === 'layouts') {
    $JSON['error'] = 0;
    $JSON['layouts'] = ocr_layouts();
    JsonOut($JSON);
}

if ($action === 'lookup') {
    $code = trim((string)($_REQUEST['code'] ?? ''));
    if ($code !== '') {
        $parsed = ocr_parse_code($code);
        if (!$parsed) {
            $JSON['msg'] = ocr_text('ErrBarcode', $code);
            JsonOut($JSON);
        }
        // The code of a scorecard printed for one distance carries that distance.
        if ($parsed['dist']) $dist = $parsed['dist'];
        $found = ocr_find_entries('code', $code);
    } else {
        $found = ocr_find_entries('target', (string)($_REQUEST['target'] ?? ''), (int)($_REQUEST['session'] ?? 0));
    }
    if (!$found) {
        $JSON['msg'] = ocr_text('ErrNotFound');
        JsonOut($JSON);
    }
    if (count($found) > 1) {
        $JSON['error'] = 0;
        $JSON['candidates'] = $found;
        $JSON['dist'] = $dist;
        JsonOut($JSON);
    }
    $enId = $found[0]['enId'];
} elseif ($action === 'card') {
    $enId = (int)($_REQUEST['enId'] ?? 0);
} else {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}

$card = ocr_card($enId, $dist);
if (!$card) {
    $JSON['msg'] = $dist ? ocr_text('ErrNotFound') : ocr_text('ErrDistance');
    JsonOut($JSON);
}
$JSON['error'] = 0;
$JSON['card'] = $card;
JsonOut($JSON);
