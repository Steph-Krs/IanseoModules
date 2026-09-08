<?php
/**
 * AJAX endpoint of the Interactive Guide module.
 *
 * The panel, the course player and the catalogue all run in the browser; this is
 * the only server side they talk to. Everything it returns is JSON.
 *
 *   GET  ?f=<id>              the content file of one course
 *   POST ?action=start        begin or restart a course, returns its progress id
 *   POST ?action=update       save the current step and validated triggers
 *   GET  ?action=progress&f=  progress for one course
 *   GET  ?action=progress-all progress for every course
 *   GET  ?action=check-condition&cid=  is a catalogue condition satisfied?
 *   GET  ?action=next&f=      the next course of the learning path
 *   GET  ?action=context&path= content items related to an ianseo page
 *   POST ?action=activity     record a passed quiz or challenge
 *   POST ?action=pref         save a per-user preference
 *   POST ?action=test-condition  evaluate an unsaved condition (admin only)
 *
 * Every statement here touches the module's own tables and nothing else, and
 * every one of them filters on GpUser: a user can neither read nor modify
 * another user's progress, including through the gp_id they supply themselves.
 */

// Walk up to the ianseo root instead of counting directory levels, so the module
// keeps working if it is installed somewhere other than Modules/Custom/.
$_guide_root = __DIR__;
while ($_guide_root !== dirname($_guide_root) && !is_file($_guide_root . '/config.php')) {
    $_guide_root = dirname($_guide_root);
}
define('HTDOCS', $_guide_root);
unset($_guide_root);

require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib/guide-lib.inc.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

guide_ensure_schema();

$action = isset($_GET['action']) ? $_GET['action'] : '';

if     ($action === 'start')           guide_start();
elseif ($action === 'update')          guide_update();
elseif ($action === 'progress')        guide_progress();
elseif ($action === 'progress-all')    guide_progress_all();
elseif ($action === 'check-condition') guide_check_condition();
elseif ($action === 'next')            guide_next_formation();
elseif ($action === 'context')         guide_context();
elseif ($action === 'activity')        guide_activity();
elseif ($action === 'pref')            guide_pref();
elseif ($action === 'test-condition')  guide_test_condition();
else                                   guide_get_formation();

/* =======================================================================
 * Handlers
 * =====================================================================*/

/**
 * Return one course, in the language the user is reading ianseo in.
 *
 * The identifier is reduced to lower-case letters, digits and hyphens before
 * anything else, so it can never walk out of the content folder — the id is then
 * matched against the id INSIDE each file, never used to build a path.
 *
 * A content file carries every language it has been translated into (see the
 * multilingual section of the library). The language maps are resolved HERE
 * rather than in the browser, for two reasons: the player stays free of
 * translation logic, and a course translated into five languages does not send
 * five copies of its text to every reader.
 *
 * The editor does not come through this endpoint — it reads the file from disk —
 * so it still sees every language.
 */
function guide_get_formation() {
    $f = isset($_GET['f']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'])) : '';
    if ($f === '') {
        http_response_code(400);
        JsonOut(['error' => 1, 'msg' => 'missing f']);
    }

    foreach (glob(guide_content_dir() . '*.json') as $file) {
        $raw  = file_get_contents($file);
        $data = json_decode($raw, true);
        if (!$data || !isset($data['id']) || $data['id'] !== $f) continue;

        // The one endpoint that returns a document rather than a status, and
        // the only one not wrapped in the error/msg envelope: a course is well
        // over a megabyte once its screenshots are counted, and single-language
        // content goes out as it sits on disk. JsonOut is still what sends it,
        // for the headers — $Straight skips a re-encode that would double the
        // memory for no gain.
        if (!guide_content_languages($data)) {
            JsonOut($raw, false, [], true);
        }

        JsonOut(json_encode(guide_i18n_resolve($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false, [], true);
    }

    http_response_code(404);
    // A diagnostic for whoever reads the response, not a label: the player shows
    // its own translated JsCourseNotFound when a load comes back 404.
    JsonOut(['error' => 1, 'msg' => 'course not found']);
}

/**
 * Begin a course, or restart one that was already under way.
 *
 * Restarting reuses the existing row rather than inserting a second one: the
 * unique key is (GpUser, GpFormId), one row per user and course.
 */
function guide_start() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        JsonOut(['error' => 1, 'msg' => 'method not allowed']);
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $formId  = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['formation_id'] ?? ''));
    $formVer = mb_substr(preg_replace('/[^0-9a-zA-Z\.\-]/', '', $body['formation_version'] ?? '1.0'), 0, 20);
    $tourId  = (int)($_SESSION['TourId'] ?? 0);

    if (!$formId) {
        JsonOut(['error' => 1, 'msg' => 'missing formation_id']);
    }

    $user = StrSafe_DB(guide_current_user());
    $now  = date('Y-m-d H:i:s');
    $q    = safe_r_sql("SELECT GpId FROM GuideProgress WHERE GpUser=$user AND GpFormId=" . StrSafe_DB($formId));
    $row  = safe_fetch($q);

    if ($row) {
        safe_w_sql("UPDATE GuideProgress SET
            GpFormVer="   . StrSafe_DB($formVer) . ",
            GpTourId="    . $tourId              . ",
            GpStep=0, GpStatus='en_cours', GpValidated=NULL,
            GpUpdatedAt=" . StrSafe_DB($now) . "
            WHERE GpId="  . (int)$row->GpId . " AND GpUser=$user");
        JsonOut(['error' => 0, 'msg' => '', 'gp_id' => (int)$row->GpId]);
    }

    safe_w_sql("INSERT INTO GuideProgress
        (GpUser, GpFormId, GpFormVer, GpTourId, GpStep, GpStatus, GpValidated, GpUpdatedAt)
        VALUES ($user, " . StrSafe_DB($formId) . ", " . StrSafe_DB($formVer) . ", " . $tourId . ",
                0, 'en_cours', NULL, " . StrSafe_DB($now) . ")");

    // Read the id back rather than using LAST_INSERT_ID() through safe_r_sql():
    // ianseo keeps separate read and write connections, and LAST_INSERT_ID() is
    // per-connection, so the read connection would always answer 0.
    $q2   = safe_r_sql("SELECT GpId FROM GuideProgress WHERE GpUser=$user AND GpFormId=" . StrSafe_DB($formId));
    $row2 = safe_fetch($q2);
    JsonOut(['error' => 0, 'msg' => '', 'gp_id' => $row2 ? (int)$row2->GpId : 0]);
}

/**
 * Save the position within a course.
 *
 * gp_id comes from the client, so GpUser is part of the WHERE clause: supplying
 * somebody else's id updates nothing.
 */
function guide_update() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        JsonOut(['error' => 1, 'msg' => 'method not allowed']);
    }

    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $gpId      = (int)($body['gp_id'] ?? 0);
    $step      = max(0, (int)($body['step'] ?? 0));
    $statusRaw = $body['status'] ?? 'en_cours';
    $status    = in_array($statusRaw, ['en_cours', 'termine', 'obsolete']) ? $statusRaw : 'en_cours';
    $validated = json_encode($body['validated'] ?? []);

    if (!$gpId) {
        JsonOut(['error' => 1, 'msg' => 'missing gp_id']);
    }

    $now = date('Y-m-d H:i:s');
    safe_w_sql("UPDATE GuideProgress SET
        GpStep="      . $step                  . ",
        GpStatus="    . StrSafe_DB($status)    . ",
        GpValidated=" . StrSafe_DB($validated) . ",
        GpUpdatedAt=" . StrSafe_DB($now)       . "
        WHERE GpId="  . $gpId . " AND GpUser=" . StrSafe_DB(guide_current_user()));

    JsonOut(['error' => 0, 'msg' => '']);
}

/**
 * Progress for one course.
 *
 * current_tour_id travels with the answer so the client can tell that progress
 * was made during a different competition and offer to restart.
 */
function guide_progress() {
    $formId        = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'] ?? ''));
    $currentTourId = (int)($_SESSION['TourId'] ?? 0);
    if (!$formId) {
        JsonOut(['error' => 0, 'msg' => '', 'progress' => null]);
    }

    $q = safe_r_sql("SELECT * FROM GuideProgress WHERE GpUser=" . StrSafe_DB(guide_current_user())
        . " AND GpFormId=" . StrSafe_DB($formId));
    $row = safe_fetch($q);
    if (!$row) {
        JsonOut(['error' => 0, 'msg' => '', 'progress' => null]);
    }

    JsonOut([
        'error'           => 0,
        'msg'             => '',
        'gp_id'           => (int)$row->GpId,
        'step'            => (int)$row->GpStep,
        'status'          => $row->GpStatus,
        'form_ver'        => $row->GpFormVer,
        'tour_id'         => (int)$row->GpTourId,
        'current_tour_id' => $currentTourId,
        'quiz'            => (int)$row->GpQuiz,
        'challenge'       => (int)$row->GpChallenge,
        'validated'       => $row->GpValidated ? json_decode($row->GpValidated, true) : [],
    ]);
}

/**
 * Progress for every course, keyed by course id. Used by the catalogue.
 */
function guide_progress_all() {
    $currentTourId = (int)($_SESSION['TourId'] ?? 0);
    $q      = safe_r_sql("SELECT * FROM GuideProgress WHERE GpUser=" . StrSafe_DB(guide_current_user()));
    $result = [];

    while ($row = safe_fetch($q)) {
        $result[$row->GpFormId] = [
            'gp_id'           => (int)$row->GpId,
            'step'            => (int)$row->GpStep,
            'status'          => $row->GpStatus,
            'form_ver'        => $row->GpFormVer,
            'tour_id'         => (int)$row->GpTourId,
            'current_tour_id' => $currentTourId,
            'quiz'            => (int)$row->GpQuiz,
            'challenge'       => (int)$row->GpChallenge,
        ];
    }
    JsonOut(['error' => 0, 'msg' => '', 'progress' => $result]);
}

/**
 * Are these catalogue conditions satisfied right now?
 *
 * Takes a LIST, and always has: a challenge holds several conditions and polls
 * them every ten seconds, so asking one at a time meant one HTTP request, one
 * read of conditions.json and one round of SQL per condition per poll. Asking
 * for the whole list costs a single request and a single read whatever the
 * number of conditions.
 *
 * `cid` accepts one id or several separated by commas, so a step waiting on a
 * single state and a challenge waiting on five use the same endpoint.
 */
function guide_check_condition() {
    $raw = (string)($_GET['cid'] ?? '');
    $ids = [];
    foreach (explode(',', $raw) as $one) {
        $one = preg_replace('/[^a-z0-9_\-]/', '', strtolower($one));
        if ($one !== '') $ids[$one] = true;
    }
    $ids = array_keys($ids);

    if (!$ids) JsonOut(['error' => 1, 'msg' => 'missing cid']);

    $conditions = guide_load_conditions();
    if (!$conditions) JsonOut(['error' => 1, 'msg' => 'conditions.json not found']);

    $byId = [];
    foreach ($conditions as $c) $byId[$c['id']] = $c;

    $results = [];
    foreach ($ids as $cid) {
        if (!isset($byId[$cid])) {
            $results[$cid] = ['met' => false, 'label' => $cid, 'known' => false];
            continue;
        }
        $results[$cid] = [
            'met'   => guide_evaluate_condition($byId[$cid]),
            // The label may be a language map, like any other translatable text.
            'label' => guide_i18n($byId[$cid]['label']),
            'known' => true,
        ];
    }

    // The single-condition shape is kept alongside the list so that a page
    // served before an update, and still asking for one id, keeps working.
    $out = ['error' => 0, 'msg' => '', 'conditions' => $results];
    if (count($ids) === 1) {
        $out['met']   = $results[$ids[0]]['met'];
        $out['label'] = $results[$ids[0]]['label'];
    }
    JsonOut($out);
}

/* =======================================================================
 * Learning path, activities, contextual help
 * =====================================================================*/

/**
 * The course that follows this one in the learning path.
 *
 * Order comes from the catalogue's own sort, so it follows the group and order
 * fields of the content files rather than the filenames.
 */
function guide_next_formation() {
    $f    = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'] ?? ''));
    $list = guide_formations_ordered();
    $next = null;

    foreach ($list as $i => $c) {
        if ($c['id'] === $f && isset($list[$i + 1])) {
            $next = ['id' => $list[$i + 1]['id'], 'title' => $list[$i + 1]['title']];
            break;
        }
    }
    JsonOut(['error' => 0, 'msg' => '', 'next' => $next]);
}

/**
 * Record that the user passed a quiz or a challenge.
 *
 * The INSERT IGNORE comes first because an activity can be started without ever
 * having walked the guide, in which case no progress row exists yet.
 */
function guide_activity() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        JsonOut(['error' => 1, 'msg' => 'method not allowed']);
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $formId   = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['formation_id'] ?? ''));
    $activity = $body['activity'] ?? '';

    if (!$formId || !in_array($activity, ['quiz', 'challenge'])) {
        JsonOut(['error' => 1, 'msg' => 'bad params']);
    }

    // Chosen from a fixed pair, never interpolated from the request.
    $col  = $activity === 'quiz' ? 'GpQuiz' : 'GpChallenge';
    $user = StrSafe_DB(guide_current_user());
    $now  = date('Y-m-d H:i:s');

    safe_w_sql("INSERT IGNORE INTO GuideProgress
        (GpUser, GpFormId, GpFormVer, GpTourId, GpStep, GpStatus, GpValidated, GpUpdatedAt)
        VALUES ($user, " . StrSafe_DB($formId) . ", '1.0', " . (int)($_SESSION['TourId'] ?? 0) . ",
                0, 'en_cours', NULL, " . StrSafe_DB($now) . ")");

    safe_w_sql("UPDATE GuideProgress SET $col=1, GpUpdatedAt=" . StrSafe_DB($now) . "
        WHERE GpUser=$user AND GpFormId=" . StrSafe_DB($formId));

    JsonOut(['error' => 0, 'msg' => '']);
}

/**
 * Content items that talk about a given ianseo page.
 *
 * Feeds contextual help: the client asks about the page it is on and offers what
 * comes back. Courses are listed before tools, since a course is the fuller
 * answer to "how do I use this page".
 */
function guide_context() {
    $path = guide_norm_path($_GET['path'] ?? '');
    if (!$path) {
        JsonOut(['error' => 0, 'msg' => '', 'items' => []]);
    }

    $items = [];
    foreach (guide_content_list() as $c) {
        foreach ($c['pages'] as $p) {
            if (guide_norm_path($p) === $path) {
                $items[] = ['id' => $c['id'], 'title' => $c['title'], 'type' => $c['type']];
                break;
            }
        }
    }

    usort($items, function ($a, $b) {
        $rank = ['formation' => 0, 'checklist' => 1, 'faq' => 2];
        return ($rank[$a['type']] ?? 9) - ($rank[$b['type']] ?? 9);
    });
    JsonOut(['error' => 0, 'msg' => '', 'items' => $items]);
}

/**
 * Save a preference of the current user.
 *
 * Only meaningful with an account module installed; without one the client keeps
 * its preferences in localStorage and never calls this.
 */
function guide_pref() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        JsonOut(['error' => 1, 'msg' => 'method not allowed']);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (array_key_exists('ctx_help', $body)) {
        guide_pref_set_ctx(!empty($body['ctx_help']));
    }
    JsonOut(['error' => 0, 'msg' => '', 'ctx_help' => guide_pref_ctx()]);
}

/**
 * Evaluate a condition that is still being edited and has not been saved.
 *
 * Admin only: the definition arrives from the request and reaches the query
 * builder, so this must never be reachable by an ordinary user.
 */
function guide_test_condition() {
    if (!guide_is_admin()) {
        http_response_code(403);
        JsonOut(['error' => 1, 'msg' => 'forbidden']);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $cond = $body['condition'] ?? null;
    if (!$cond || empty($cond['checks']) || !is_array($cond['checks'])) {
        JsonOut(['error' => 1, 'msg' => 'invalid condition']);
    }

    $results = [];
    $met     = true;
    foreach ($cond['checks'] as $check) {
        $ok = false;
        // A half-written condition is expected here, so a failure is a result
        // rather than an error: report the check as not satisfied and move on.
        try {
            $ok = guide_evaluate_check($check);
        } catch (Throwable $e) {
            $ok = false;
        }
        $results[] = $ok;
        if (!$ok) $met = false;
    }
    JsonOut(['error' => 0, 'msg' => '', 'met' => $met, 'results' => $results]);
}
