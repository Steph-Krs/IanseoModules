<?php
/**
 * JSON endpoint of the duel page (lib/duel.php).
 *
 *   action=tours     today unfinished   -> the rounds scheduled (brackets, round robin)
 *   action=matches   tour               -> a round's matches, as their sheets print them
 *   action=match     id                 -> one match as ianseo holds it now
 *   POST action=import    id sides csrf
 *        -> both sides' arrows, shoot-off and closest to the centre written, while the match
 *           is neither confirmed nor whole in ianseo: the reading completes what is entered,
 *           ianseo holding no arrow otherwise but those the operator corrected
 *   POST action=validate  id sides csrf
 *        -> the match confirmed, as the core's barcode check does (the winner moved on);
 *           only when ianseo holds what was read, but for the cells the operator corrected
 *           (written first), and the match has a winner
 *
 * sides: JSON, per side {letters (one letter of the face per arrow, a space for none), tb
 * (the shoot-off likewise), closest (bool), edited, tbEdited (the cells the operator
 * corrected, 0-based)}. Answers follow the core's envelope: error and msg, then the payload
 * (a refusal too carries the match as it stands).
 */

require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/duel.php';

$JSON = ['error' => 1, 'msg' => ''];

if (!ocr_duel_access(AclReadOnly)) {
    $JSON['msg'] = ocr_text('ErrAccess');
    JsonOut($JSON);
}

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'tours') {
    $JSON['tours'] = ocr_duel_tours(!empty($_REQUEST['today']), !empty($_REQUEST['unfinished']));
    $JSON['error'] = 0;
    JsonOut($JSON);
}

if ($action === 'matches') {
    $list = ocr_duel_matches((string)($_REQUEST['tour'] ?? ''));
    if ($list === null) {
        $JSON['msg'] = ocr_text('ErrRequest');
        JsonOut($JSON);
    }
    $JSON['matches'] = $list;
    $JSON['error'] = 0;
    JsonOut($JSON);
}

$m = ocr_duel_match((string)($_REQUEST['id'] ?? ''));
if (!$m) {
    $JSON['msg'] = ocr_text('ErrNotFound');
    JsonOut($JSON);
}

if ($action === 'match') {
    $JSON['match'] = $m;
    $JSON['error'] = 0;
    JsonOut($JSON);
}

if (!in_array($action, ['import', 'validate'], true) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !ocr_token_ok()) {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}
session_write_close();

$refuse = function ($msg) use ($JSON, $m) {
    $JSON['msg'] = $msg;
    $JSON['match'] = $m;
    JsonOut($JSON);
};
$blocked = ocr_duel_blocked($m);
if ($blocked) $refuse($blocked);
$sides = json_decode((string)($_POST['sides'] ?? ''), true);
if (!is_array($sides) || count($sides) !== 2) $refuse(ocr_text('ErrRequest'));
if ($m['confirmed']) $refuse(ocr_text('DuelConfirmed'));

// What ianseo holds against the reading: the same, or only the cells the operator corrected.
$same = true;
foreach ([0, 1] as $i) {
    $s = $sides[$i];
    // bytes: arrowstrings hold one ASCII letter per arrow.
    $want = str_pad((string)($s['letters'] ?? ''), strlen($m['sides'][$i]['letters'])) . '|' . str_pad((string)($s['tb'] ?? ''), strlen($m['sides'][$i]['tb']));
    $held = $m['sides'][$i]['letters'] . '|' . $m['sides'][$i]['tb'];
    if ($want !== $held || !empty($s['closest']) !== $m['sides'][$i]['closest']) $same = false;
}

if ($action === 'import') {
    if ($same && $m['decided']) $refuse(ocr_text('DuelImportHeld'));
    $err = ocr_duel_import($m, $sides);
    if ($err) $refuse($err);
} else {
    if (trim($m['sides'][0]['letters'] . $m['sides'][1]['letters']) === '') $refuse(ocr_text('ErrValidateNone'));
    if (!$same) {
        // Only the cells corrected: written first, the rest is what ianseo holds already.
        foreach ([0, 1] as $i) {
            $s = $sides[$i];
            // bytes: arrowstrings hold one ASCII letter per arrow.
            for ($k = 0; $k < strlen($m['sides'][$i]['letters']); $k++) {
                $c = substr((string)($s['letters'] ?? ''), $k, 1);
                if (($c === '' ? ' ' : $c) !== $m['sides'][$i]['letters'][$k] && !in_array($k, array_map('intval', (array)($s['edited'] ?? [])), true)) {
                    $refuse(ocr_text('DuelValidateDiff', ['side' => $m['sides'][$i]['name'], 'n' => $k + 1]));
                }
            }
        }
        $err = ocr_duel_import($m, $sides);
        if ($err) $refuse($err);
        $m = ocr_duel_match($m['id']);
    }
    if (!$m['decided']) $refuse(ocr_text('DuelNoWinner'));
    ocr_duel_confirm($m);
}

$JSON['match'] = ocr_duel_match($m['id']);
$JSON['error'] = 0;
JsonOut($JSON);
