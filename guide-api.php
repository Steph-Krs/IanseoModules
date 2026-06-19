<?php
/**
 * Guide FFTA — API AJAX v3
 * GET ?f={id}               → JSON d'une formation
 * POST ?action=start        → démarre/réinitialise la progression
 * POST ?action=update       → met à jour la progression
 * GET  ?action=progress&f=  → progression pour une formation
 * GET  ?action=progress-all → toutes les progressions
 */
define('HTDOCS', dirname(dirname(dirname(dirname(__FILE__)))));
require_once(HTDOCS . '/config.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

guide_ensure_schema();

$action = isset($_GET['action']) ? $_GET['action'] : '';

if     ($action === 'start')           guide_start();
elseif ($action === 'update')          guide_update();
elseif ($action === 'progress')        guide_progress();
elseif ($action === 'progress-all')    guide_progress_all();
elseif ($action === 'check-condition') guide_check_condition();
else                                   guide_get_formation();

/* ======= Schéma ======= */

function guide_ensure_schema() {
    if (!empty($_SESSION['_guide_schema_ok'])) return;

    $rs = safe_r_sql("SHOW TABLES LIKE 'GUIDE_Progress'");
    if (safe_fetch($rs)) {
        $rc_col = safe_r_sql("SHOW COLUMNS FROM GUIDE_Progress LIKE 'GpTourId'");
        $has_col = !!safe_fetch($rc_col);

        if ($has_col) {
            // Vérifie que c'est bien le nouveau schéma (clé unique sur GpFormId seul)
            $rc_old = safe_r_sql("SHOW INDEX FROM GUIDE_Progress WHERE Key_name='uq_form_tour'");
            if (!safe_fetch($rc_old)) {
                // GpTourId existe et pas d'ancienne clé composite → schéma correct
                $_SESSION['_guide_schema_ok'] = true;
                return;
            }
        }
        // Schéma obsolète (ancienne clé composite ou GpTourId manquant) → recréer
        safe_w_sql("DROP TABLE GUIDE_Progress");
    }

    safe_w_sql("CREATE TABLE GUIDE_Progress (
        GpId        INT AUTO_INCREMENT PRIMARY KEY,
        GpFormId    VARCHAR(30)  NOT NULL,
        GpFormVer   VARCHAR(20)  NOT NULL DEFAULT '1.0',
        GpTourId    INT          NOT NULL DEFAULT 0,
        GpStep      INT          NOT NULL DEFAULT 0,
        GpStatus    ENUM('en_cours','termine','obsolete') NOT NULL DEFAULT 'en_cours',
        GpValidated TEXT,
        GpUpdatedAt DATETIME     NOT NULL,
        UNIQUE KEY uq_form (GpFormId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $_SESSION['_guide_schema_ok'] = true;
}

/* ======= Handlers ======= */

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

    $now = date('Y-m-d H:i:s');
    $q   = safe_r_sql("SELECT GpId FROM GUIDE_Progress WHERE GpFormId=" . StrSafe_DB($formId));
    $row = safe_fetch($q);

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
            (GpFormId, GpFormVer, GpTourId, GpStep, GpStatus, GpValidated, GpUpdatedAt)
            VALUES (" . StrSafe_DB($formId) . ", " . StrSafe_DB($formVer) . ", " . $tourId . ",
                    0, 'en_cours', NULL, " . StrSafe_DB($now) . ")");
        $q2   = safe_r_sql("SELECT GpId FROM GUIDE_Progress WHERE GpFormId=" . StrSafe_DB($formId));
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
    safe_w_sql("UPDATE GUIDE_Progress SET
        GpStep="      . $step                  . ",
        GpStatus="    . StrSafe_DB($status)    . ",
        GpValidated=" . StrSafe_DB($validated) . ",
        GpUpdatedAt=" . StrSafe_DB($now)       . "
        WHERE GpId="  . $gpId);

    echo json_encode(['ok' => true]);
}

function guide_progress() {
    $formId        = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['f'] ?? ''));
    $currentTourId = (int)($_SESSION['TourId'] ?? 0);
    if (!$formId) { echo json_encode(null); exit; }

    $q   = safe_r_sql("SELECT * FROM GUIDE_Progress WHERE GpFormId=" . StrSafe_DB($formId));
    $row = safe_fetch($q);
    if (!$row) { echo json_encode(null); exit; }

    echo json_encode([
        'gp_id'           => (int)$row->GpId,
        'step'            => (int)$row->GpStep,
        'status'          => $row->GpStatus,
        'form_ver'        => $row->GpFormVer,
        'tour_id'         => (int)$row->GpTourId,
        'current_tour_id' => $currentTourId,
        'validated'       => $row->GpValidated ? json_decode($row->GpValidated, true) : [],
    ]);
}

function guide_check_condition() {
    $cid = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_GET['cid'] ?? ''));
    if (!$cid) { echo json_encode(['error' => 'missing cid']); exit; }

    $file = file_get_contents(__DIR__ . '/conditions.json');
    if (!$file) { echo json_encode(['error' => 'conditions.json not found']); exit; }
    $conditions = json_decode($file, true);

    $cond = null;
    foreach ($conditions as $c) {
        if ($c['id'] === $cid) { $cond = $c; break; }
    }
    if (!$cond) { echo json_encode(['error' => 'condition not found', 'met' => false]); exit; }

    echo json_encode(['met' => guide_evaluate_condition($cond), 'label' => $cond['label']]);
}

function guide_evaluate_condition($cond) {
    foreach ($cond['checks'] as $check) {
        if (!guide_evaluate_check($check)) return false;
    }
    return true;
}

function guide_evaluate_check($check) {
    // Check sur la session
    if (isset($check['source']) && $check['source'] === 'session') {
        $val = isset($_SESSION[$check['key']]) ? (int)$_SESSION[$check['key']] : 0;
        return guide_compare($val, $check['op'], $check['value']);
    }

    // Agrégat COUNT sur une table
    if (isset($check['aggregate']) && $check['aggregate'] === 'count') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $where = [];
        foreach ($check['where'] as $w) {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', $w['column']);
            if (isset($w['source']) && $w['source'] === 'session') {
                $where[] = "`$col` = " . (int)($_SESSION[$w['key']] ?? 0);
            } else {
                $ops = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
                $op  = $ops[$w['op'] ?? 'eq'] ?? '=';
                $where[] = "`$col` $op " . StrSafe_DB($w['value']);
            }
        }
        $sql = "SELECT COUNT(*) AS cnt FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $rs  = safe_r_sql($sql);
        $row = safe_fetch($rs);
        return guide_compare($row ? (int)$row->cnt : 0, $check['op'], $check['value']);
    }

    // Valeur d'une colonne avec jointure sur session
    if (isset($check['table']) && isset($check['column'])) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $col   = preg_replace('/[^a-zA-Z0-9_]/', '', $check['column']);
        $where = '';
        if (isset($check['join'])) {
            // Format: "ToId = TourId" → WHERE `ToId` = $_SESSION['TourId']
            if (preg_match('/^(\w+)\s*=\s*(\w+)$/', $check['join'], $m)) {
                $jcol = preg_replace('/[^a-zA-Z0-9_]/', '', $m[1]);
                $jkey = preg_replace('/[^a-zA-Z0-9_]/', '', $m[2]);
                $where = " WHERE `$jcol` = " . (int)($_SESSION[$jkey] ?? 0);
            }
        }
        $sql = "SELECT `$col` FROM `$table`$where LIMIT 1";
        $rs  = safe_r_sql($sql);
        $row = safe_fetch($rs);
        if (!$row) return false;
        return guide_compare($row->$col, $check['op'], $check['value']);
    }

    return false;
}

function guide_compare($actual, $op, $expected) {
    switch ($op) {
        case 'eq':  return $actual == $expected;
        case 'neq': return $actual != $expected;
        case 'gt':  return $actual >  $expected;
        case 'gte': return $actual >= $expected;
        case 'lt':  return $actual <  $expected;
        case 'lte': return $actual <= $expected;
        default:    return false;
    }
}

function guide_progress_all() {
    $currentTourId = (int)($_SESSION['TourId'] ?? 0);
    $q      = safe_r_sql("SELECT * FROM GUIDE_Progress");
    $result = [];
    while ($row = safe_fetch($q)) {
        $result[$row->GpFormId] = [
            'gp_id'           => (int)$row->GpId,
            'step'            => (int)$row->GpStep,
            'status'          => $row->GpStatus,
            'form_ver'        => $row->GpFormVer,
            'tour_id'         => (int)$row->GpTourId,
            'current_tour_id' => $currentTourId,
        ];
    }
    echo json_encode($result);
}
