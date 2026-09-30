<?php
/**
 * One upload to ianseo.net, run by the scheduled task in a process of its own.
 *
 *     php worker.php <ToId>
 *
 * The upload itself is the core's, unchanged: Tournament/UploadResults-upload.php
 * already accepts a call without a browser — given a competition code, it opens
 * the competition and uses the ianseo.net credentials saved with it (the
 * "remember" box of the core's credentials page). This script only prepares
 * what that file reads — the form fields the core page would post — and includes
 * it. The core then builds the rankings, sends them, and prints its JSON answer,
 * which the scheduled task reads.
 *
 * Before handing over, it checks what the core would only report as a generic
 * error: credentials missing, publication locked for the competition, nothing
 * selected, ianseo.net unreachable. The credentials check is the core's own
 * CheckCredentials(), the same call the core page makes when it opens; it comes
 * before the costly part, so a network outage fails in a fraction of a second
 * instead of after the rankings are built.
 *
 * Its own answers carry "aus_code"; the core's never do. Remarks on the selection
 * go to STDERR as an AUTOSEND-NOTE line, because STDOUT must hold the core's
 * answer and nothing else.
 */

require __DIR__ . '/lib/cli.php';
require_once __DIR__ . '/lib/dryrun.php';

/**
 * Print the worker's own answer and stop.
 *
 * @param bool $ok
 * @param string $code Translation key of the outcome.
 * @param string $detail
 */
function aus_worker_answer($ok, $code, $detail = '') {
    echo json_encode(['error' => $ok ? 0 : 1, 'aus_code' => $code, 'aus_detail' => (string)$detail]);
    exit;
}

$tour = (int)($argv[1] ?? 0);
$plan = $tour > 0 ? aus_plan_load($tour) : null;
if (!$plan) aus_worker_answer(false, 'ErrNoPlan');

$code = getCodeFromId($tour);
if ($code === '' || $code === null) aus_worker_answer(false, 'ErrNoCompetition');

$selection = aus_items_clean(aus_json_array($plan->AsItems));
if (!aus_items_count($selection)) aus_worker_answer(false, 'ErrNothingSelected');

$credentials = getModuleParameter('SendToIanseo', 'Credentials', null, $tour);
if (!is_object($credentials) || empty($credentials->OnlineId) || (string)$credentials->OnlineAuth === '') {
    aus_worker_answer(false, 'ErrNoCredentials');
}

if ($plan->AsSimulation) {
    aus_dryrun_enable();
}

CreateTourSession($tour);
if (IsBlocked(BIT_BLOCK_PUBBLICATION)) aus_worker_answer(false, 'ErrPublicationLocked');

$built = aus_items_request($tour, $selection);
if ($built['waiting'] || $built['missing']) {
    fwrite(STDERR, 'AUTOSEND-NOTE ' . json_encode(['waiting' => $built['waiting'], 'missing' => $built['missing']]) . "\n");
}
if (!$built['sent']) aus_worker_answer(true, 'NothingYet');

// A simulation must never reach ianseo.net: checked again right before the
// first network call.
if ((bool)$plan->AsSimulation !== aus_dryrun_active()) {
    aus_worker_answer(false, 'ErrWorker', 'simulation switch');
}

// Enough for a slow answer from ianseo.net, well within the task's own limit.
ini_set('default_socket_timeout', '120');

require_once 'Common/Lib/CommonLib.php';
$error = CheckCredentials($credentials->OnlineId, $credentials->OnlineAuth, 'Tournament/UploadResults-upload.php');
if ($error) {
    // CheckCredentials() returns the network error itself when the call fails,
    // and ianseo.net's message when the codes are refused; the last PHP error
    // only adds something in the second case.
    $last = (string)(error_get_last()['message'] ?? '');
    if ($last !== '' && mb_strpos($error, $last) === false) $error .= ' ' . $last;
    aus_worker_answer(false, 'ErrCredentialsCheck', $error);
}

$_GET = [];
$_POST = $built['request'];
$_REQUEST = $built['request'] + ['ToCode' => $code];

require $CFG->DOCUMENT_PATH . 'Tournament/UploadResults-upload.php';

// Not reached: the core ends with JsonOut().
aus_worker_answer(false, 'ErrWorker', 'no answer');
