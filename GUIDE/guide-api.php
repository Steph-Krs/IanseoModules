<?php
/**
 * Guide interactif — API AJAX v3
 * GET ?f={id}               → JSON d'une formation
 * POST ?action=start        → démarre/réinitialise la progression
 * POST ?action=update       → met à jour la progression
 * GET  ?action=progress&f=  → progression pour une formation
 * GET  ?action=progress-all → toutes les progressions
 */
define('HTDOCS', dirname(dirname(dirname(dirname(__FILE__)))));
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

/* ======= Handlers ======= */
/* Le schéma (guide_ensure_schema) et l'utilisateur courant (guide_current_user)
   sont dans lib/guide-lib.inc.php — toute la progression est cloisonnée par
   utilisateur (GpUser, '' sans module de comptes). */

function guide_get_formation() {
    $f = isset($_GET['f']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'])) : '';
    if ($f === '') { http_response_code(400); echo json_encode(['error' => 'missing f']); exit; }

    $dir = __DIR__ . '/content/';
    foreach (glob($dir . '*.json') as $file) {
        $raw  = file_get_contents($file);
        $data = json_decode($raw, true);
        if ($data && isset($data['id']) && $data['id'] === $f) {
            echo $raw;
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['error' => 'formation not found']);
}

function guide_start() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'method']); exit;
    }
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $formId  = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['formation_id'] ?? ''));
    $formVer = substr(preg_replace('/[^0-9a-zA-Z\.\-]/', '', $body['formation_version'] ?? '1.0'), 0, 20);
    $tourId  = (int)($_SESSION['TourId'] ?? 0);

    if (!$formId) { echo json_encode(['error' => 'missing formation_id']); exit; }

    $user = StrSafe_DB(guide_current_user());
    $now  = date('Y-m-d H:i:s');
    $q    = safe_r_sql("SELECT GpId FROM GUIDE_Progress WHERE GpUser=$user AND GpFormId=" . StrSafe_DB($formId));
    $row  = safe_fetch($q);

    if ($row) {
        // Redémarrage : remet à zéro et met à jour la compétition courante
        safe_w_sql("UPDATE GUIDE_Progress SET
            GpFormVer="   . StrSafe_DB($formVer) . ",
            GpTourId="    . $tourId              . ",
            GpStep=0, GpStatus='en_cours', GpValidated=NULL,
            GpUpdatedAt=" . StrSafe_DB($now) . "
            WHERE GpId="  . (int)$row->GpId);
        echo json_encode(['gp_id' => (int)$row->GpId]);
    } else {
        safe_w_sql("INSERT INTO GUIDE_Progress
            (GpUser, GpFormId, GpFormVer, GpTourId, GpStep, GpStatus, GpValidated, GpUpdatedAt)
            VALUES ($user, " . StrSafe_DB($formId) . ", " . StrSafe_DB($formVer) . ", " . $tourId . ",
                    0, 'en_cours', NULL, " . StrSafe_DB($now) . ")");
        $q2   = safe_r_sql("SELECT GpId FROM GUIDE_Progress WHERE GpUser=$user AND GpFormId=" . StrSafe_DB($formId));
        $row2 = safe_fetch($q2);
        echo json_encode(['gp_id' => $row2 ? (int)$row2->GpId : 0]);
    }
}

function guide_update() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'method']); exit;
    }
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $gpId      = (int)($body['gp_id'] ?? 0);
    $step      = max(0, (int)($body['step'] ?? 0));
    $statusRaw = $body['status'] ?? 'en_cours';
    $status    = in_array($statusRaw, ['en_cours', 'termine', 'obsolete']) ? $statusRaw : 'en_cours';
    $validated = json_encode($body['validated'] ?? []);

    if (!$gpId) { echo json_encode(['error' => 'missing gp_id']); exit; }

    $now = date('Y-m-d H:i:s');
    // GpUser dans le WHERE : un utilisateur ne peut pas modifier la ligne d'un autre
    safe_w_sql("UPDATE GUIDE_Progress SET
        GpStep="      . $step                  . ",
        GpStatus="    . StrSafe_DB($status)    . ",
        GpValidated=" . StrSafe_DB($validated) . ",
        GpUpdatedAt=" . StrSafe_DB($now)       . "
        WHERE GpId="  . $gpId . " AND GpUser=" . StrSafe_DB(guide_current_user()));

    echo json_encode(['ok' => true]);
}

function guide_progress() {
    $formId        = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'] ?? ''));
    $currentTourId = (int)($_SESSION['TourId'] ?? 0);
    if (!$formId) { echo json_encode(null); exit; }

    $q   = safe_r_sql("SELECT * FROM GUIDE_Progress WHERE GpUser=" . StrSafe_DB(guide_current_user())
        . " AND GpFormId=" . StrSafe_DB($formId));
    $row = safe_fetch($q);
    if (!$row) { echo json_encode(null); exit; }

    echo json_encode([
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

function guide_check_condition() {
    $cid = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_GET['cid'] ?? ''));
    if (!$cid) { echo json_encode(['error' => 'missing cid']); exit; }

    $conditions = guide_load_conditions();
    if (!$conditions) { echo json_encode(['error' => 'conditions.json not found']); exit; }

    $cond = null;
    foreach ($conditions as $c) {
        if ($c['id'] === $cid) { $cond = $c; break; }
    }
    if (!$cond) { echo json_encode(['error' => 'condition not found', 'met' => false]); exit; }

    echo json_encode(['met' => guide_evaluate_condition($cond), 'label' => $cond['label']]);
}

function guide_progress_all() {
    $currentTourId = (int)($_SESSION['TourId'] ?? 0);
    $q      = safe_r_sql("SELECT * FROM GUIDE_Progress WHERE GpUser=" . StrSafe_DB(guide_current_user()));
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
    echo json_encode($result);
}

/* ======= Parcours / activités / contexte ======= */

// Formation suivante dans l'ordre du parcours (groupes/order du catalogue)
function guide_next_formation() {
    $f = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'] ?? ''));
    $list = guide_formations_ordered();
    $next = null;
    foreach ($list as $i => $c) {
        if ($c['id'] === $f && isset($list[$i + 1])) {
            $next = ['id' => $list[$i + 1]['id'], 'title' => $list[$i + 1]['title']];
            break;
        }
    }
    echo json_encode(['next' => $next]);
}

// Marque une activité (quiz / challenge) comme réussie pour une formation
function guide_activity() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'method']); exit;
    }
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $formId   = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['formation_id'] ?? ''));
    $activity = $body['activity'] ?? '';
    if (!$formId || !in_array($activity, ['quiz', 'challenge'])) {
        echo json_encode(['error' => 'bad params']); exit;
    }
    $col  = $activity === 'quiz' ? 'GpQuiz' : 'GpChallenge';
    $user = StrSafe_DB(guide_current_user());
    $now  = date('Y-m-d H:i:s');
    // La ligne peut ne pas exister (activité lancée sans avoir fait le guide)
    safe_w_sql("INSERT IGNORE INTO GUIDE_Progress
        (GpUser, GpFormId, GpFormVer, GpTourId, GpStep, GpStatus, GpValidated, GpUpdatedAt)
        VALUES ($user, " . StrSafe_DB($formId) . ", '1.0', " . (int)($_SESSION['TourId'] ?? 0) . ",
                0, 'en_cours', NULL, " . StrSafe_DB($now) . ")");
    safe_w_sql("UPDATE GUIDE_Progress SET $col=1, GpUpdatedAt=" . StrSafe_DB($now) . "
        WHERE GpUser=$user AND GpFormId=" . StrSafe_DB($formId));
    echo json_encode(['ok' => true]);
}

// Contenus liés à une page (aide contextuelle)
function guide_context() {
    $path = guide_norm_path($_GET['path'] ?? '');
    if (!$path) { echo json_encode([]); exit; }
    $items = [];
    foreach (guide_content_list() as $c) {
        foreach ($c['pages'] as $p) {
            if (guide_norm_path($p) === $path) {
                $items[] = ['id' => $c['id'], 'title' => $c['title'], 'type' => $c['type']];
                break;
            }
        }
    }
    // Formations d'abord, puis outils
    usort($items, function ($a, $b) {
        $rank = ['formation' => 0, 'checklist' => 1, 'faq' => 2];
        return ($rank[$a['type']] ?? 9) - ($rank[$b['type']] ?? 9);
    });
    echo json_encode($items);
}

// Préférences de l'utilisateur courant (aide contextuelle)
function guide_pref() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'method']); exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (array_key_exists('ctx_help', $body)) {
        guide_pref_set_ctx(!empty($body['ctx_help']));
    }
    echo json_encode(['ok' => true, 'ctx_help' => guide_pref_ctx()]);
}

// Test d'une condition en cours d'édition (constructeur admin uniquement)
function guide_test_condition() {
    if (!guide_is_admin()) {
        http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $cond = $body['condition'] ?? null;
    if (!$cond || empty($cond['checks']) || !is_array($cond['checks'])) {
        echo json_encode(['error' => 'condition invalide']); exit;
    }
    $results = [];
    $met = true;
    foreach ($cond['checks'] as $check) {
        $ok = false;
        try { $ok = guide_evaluate_check($check); } catch (Throwable $e) { $ok = false; }
        $results[] = $ok;
        if (!$ok) $met = false;
    }
    echo json_encode(['met' => $met, 'results' => $results]);
}
