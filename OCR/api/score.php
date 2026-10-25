<?php
/**
 * JSON endpoint writing a scorecard's reading into ianseo (qualification, one distance).
 *
 *   POST action=import    enId dist arrows zero edited csrf
 *        -> the distance's arrows and totals, while ianseo does not hold them all and the
 *           distance is not confirmed: the reading completes what is entered, ianseo holding
 *           no arrow otherwise but those the operator corrected
 *   POST action=validate  enId dist arrows zero edited signed csrf
 *        -> what is entered confirmed (QuConfirm), as the core's barcode check does; only when
 *           the reading is what is entered, but for the cells the operator corrected, which are
 *           written first
 *
 * arrows: one letter of the archer's face per arrow, a space for none. zero: the totals the
 * archer left blank, which count 0 ("score,gold,xnine"). edited: the arrows the operator
 * corrected (0-based, comma separated). signed: the totals read as the archer signed them
 * ("score,gold,xnine", -1 for blank). Answers follow the core's envelope: error and msg, then
 * the card as it stands (a refusal too: the page then knows what ianseo holds).
 */

require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/qualif.php';
require_once dirname(__DIR__) . '/lib/score.php';

$JSON = ['error' => 1, 'msg' => ''];

if (!ocr_has_access(AclReadWrite)) {
    $JSON['msg'] = ocr_text('ErrAccess');
    JsonOut($JSON);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !ocr_token_ok()) {
    $JSON['msg'] = ocr_text('ErrRequest');
    JsonOut($JSON);
}
session_write_close();

$action = (string)($_POST['action'] ?? '');
$dist = (int)($_POST['dist'] ?? 0);
$row = ocr_score_row((int)($_POST['enId'] ?? 0), $dist);
if (!$row || !in_array($action, ['import', 'validate'], true)) {
    $JSON['msg'] = ocr_text($row ? 'ErrRequest' : 'ErrNotFound');
    JsonOut($JSON);
}
$refuse = function ($msg) use ($JSON, $row, $dist) {
    $JSON['msg'] = $msg;
    $JSON['card'] = ocr_card((int)$row->EnId, $dist);
    JsonOut($JSON);
};
$letters = ocr_score_letters($row, $dist, (string)($_POST['arrows'] ?? ''));
if ($letters === null) $refuse(ocr_text('ErrRequest'));
$zero = array_values(array_intersect(['score', 'gold', 'xnine'], explode(',', (string)($_POST['zero'] ?? ''))));
$edited = array_map('intval', array_filter(explode(',', (string)($_POST['edited'] ?? '')), 'strlen'));
$confirmed = ((int)$row->QuConfirm & (1 << $dist)) !== 0;
$held = rtrim((string)$row->Arrows);

if ($action === 'import') {
    // Every arrow entered, or confirmed: it is to be validated (or corrected in ianseo).
    if ($confirmed || ocr_score_whole($row)) $refuse(ocr_text('ErrImportHeld'));
    $off = ocr_score_off($row, $letters, $edited, false);
    if ($off) $refuse(ocr_text('ErrImportDiff', $off));
    ocr_score_write($row, $dist, $letters, $zero);
} else {
    if ($held === '') $refuse(ocr_text('ErrValidateNone'));
    // The arrows: what is entered, but those the operator corrected (written then).
    $off = ocr_score_off($row, $letters, $edited, true);
    if ($off) $refuse(ocr_text('ErrValidateDiff', $off));
    // The totals: what the arrows make (a blank one 0), or what the archer signed.
    list($score, $gold, $xnine) = ocr_score_totals($row, $letters, $zero);
    $signed = array_map('intval', explode(',', (string)($_POST['signed'] ?? '-1,-1,-1')) + [-1, -1, -1]);
    $now = [(int)$row->Score, (int)$row->Gold, (int)$row->Xnine];
    $count = (int)$row->DiEnds * (int)$row->DiArrows;
    // bytes: one ASCII letter per arrow, padded with spaces for the arrows not entered.
    $changed = str_pad((string)$row->Arrows, $count) !== $letters || $zero;
    foreach ([$score, $gold, $xnine] as $k => $want) {
        if ($now[$k] !== $want && $now[$k] !== $signed[$k] && !$changed) $refuse(ocr_text('ErrValidateTotals'));
    }
    if ($changed) ocr_score_write($row, $dist, $letters, $zero);
    ocr_score_confirm($row, $dist);
}

$JSON['error'] = 0;
$JSON['card'] = ocr_card((int)$row->EnId, $dist);
JsonOut($JSON);
