<?php
/**
 * JSON endpoint of the module's page, for the open competition.
 *
 *   GET                         the status panel (lib/status.php);
 *   POST action=sendnow, csrf   ask for an upload now. The page does not upload
 *                               itself: it flags the plan, and the scheduled
 *                               task uploads within the minute, through the
 *                               same path as every other upload — which also
 *                               proves that the task is running.
 */

require_once dirname(__DIR__) . '/lib/boot.php';

$JSON = ['error' => 1, 'msg' => aus_text('ErrAccess')];
if (!aus_has_access()) JsonOut($JSON);

$tour = (int)$_SESSION['TourId'];

if (($_POST['action'] ?? '') === 'sendnow') {
    if (!aus_token_ok()) {
        $JSON['msg'] = aus_text('ErrToken');
        JsonOut($JSON);
    }
    safe_w_sql("UPDATE AutoSendPlans SET AsSendNow=1 WHERE AsTournament=$tour");
}

// Polled every few seconds: release the session so the other pages of the
// same browser are not kept waiting behind it.
session_write_close();

JsonOut(aus_status($tour));
