<?php
/**
 * admin/targets.php — target assignment and check of the rules. Markup produced in PHP.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pTarget', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/targets.php';
require_once dirname(__DIR__) . '/lib/archer.php';
require_once dirname(__DIR__) . '/lib/ui.php';
// Payer trust index (AUTH core): badges and acceptances, shown in its alert and block modes only.
$trust = is_file(dirname(__DIR__, 2) . '/trust-lib.php');
if ($trust) require_once dirname(__DIR__, 2) . '/trust-lib.php';

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$cfg  = bk_comp_config($TOUR);
$msg = ''; $err = '';

/** Report of an assignment, telling apart the causes of non-placement. */
function bk_assign_msg($prefix, $r)
{
    $m = $prefix . bk_t('TgPlaced', $r['places']);
    $free = intval($r['restants']) - intval($r['incompatibles']);
    if ($free > 0)               $m .= ', ' . bk_t('TgNoPlace', $free);
    if ($r['incompatibles'] > 0) $m .= ', ' . bk_t('TgIncompatible', $r['incompatibles']);
    if ($r['compromis'] > 0)     $m .= ', ' . bk_t('TgOverQuota', $r['compromis']);
    $m .= '.';
    if (!empty($r['voeux'])) {
        $m .= ' ' . bk_t('TgWishes', array('ok' => intval($r['voeuxOk']), 'all' => intval($r['voeux'])));
    }
    return $m;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif ($trust && in_array($_POST['action'] ?? '', array('trust_accept', 'trust_unaccept'), true)) {
        $r = aut_trust_org_post($TOUR, (string) ($_SESSION['AUTH_User'] ?? 'local'));
        $msg = $r['msg'];
        $err = $r['err'];
    } elseif (IsBlocked(BIT_BLOCK_PARTICIPANT)) {
        $err = bk_t('TgLocked');
    } else {
        $act = $_POST['action'] ?? '';
        $ses = intval($_POST['session'] ?? 0);
        if ($act === 'assign') {
            // "Reassign" = FREE the module's placements, then assign again: otherwise an archer
            // already placed does not move, even when the target plan changed (bk_assign_session
            // never moves a placed archer).
            $r = bk_replan_session($TOUR, $ses, $cfg);
            $msg = bk_assign_msg(bk_t('TgDepPrefix', $ses), $r);
        } elseif ($act === 'assign_all') {
            $r = bk_replan_all($TOUR, $cfg);
            $msg = bk_assign_msg('', $r);
        } elseif ($act === 'clear') {
            $n = bk_clear_session($TOUR, $ses);
            $msg = bk_t('TgFreed', array('dep' => $ses, 'n' => $n));
        } elseif ($act === 'validate') {
            if (bk_validate_registration($TOUR, intval($_POST['enid'] ?? 0), $cfg)) {
                $msg = bk_t('TgValidated');
            } else {
                $err = bk_t('TgNotPending');
            }
        } elseif ($act === 'validate_all') {
            $n = bk_validate_all($TOUR, $cfg);
            $msg = bk_t('TgValidatedN', $n);
        }
    }
}

$sessions = bk_comp_sessions($TOUR);
$pending  = bk_pending_registrations($TOUR);
$controle = bk_rules_check($TOUR, $cfg);
$voir     = intval($_GET['plan'] ?? 0);
$plan     = $voir ? bk_session_plan($TOUR, $voir) : array();

// Free requests left by the archers ("Other request" of the wishes).
$requests = array();
$rs = safe_r_sql("SELECT EnFirstName, EnName, EnCode, BrRequest, QuSession
    FROM BookingRegistrations
    INNER JOIN Entries ON EnId = BrEnId AND EnTournament = $TOUR
    /* 1:1 with Entries → INNER JOIN, never LEFT + IS NULL. */
    INNER JOIN Qualifications ON QuId = EnId
    WHERE BrTournament = $TOUR AND TRIM(COALESCE(BrRequest, '')) <> ''
    ORDER BY QuSession, EnFirstName, EnName");
while ($r = safe_fetch($rs)) $requests[] = $r;

$PAGE_TITLE = bk_t('MnuTargets');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; margin-bottom:6px; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:5px 10px; text-align:left; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-btn { padding:7px 14px; border:1px solid #d2d4d6; border-radius:6px;
    background:#f7f7f7; color:#20263d; font-size:13px; cursor:pointer; }
#bkadm .bk-btn-primary { background:#0254a8; border-color:#0254a8; color:#fff; font-weight:600; }
#bkadm .bk-btn-primary:hover { background:#01367c; }
#bkadm .bk-pill { display:inline-block; padding:1px 8px; border-radius:5px; font-size:12px; }
#bkadm .bk-pill-ok { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-pill-ko { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm .bk-pill-warn { background:#fdf0e6; border:1px solid #cb8137; color:#cb8137; }
#bkadm .bk-hint { font-size:12px; color:#7d8183; margin:6px 0 0; }
#bkadm .bk-plan td { font-size:12px; }
#bkadm .bk-plan .bk-empty-cell { color:#c9ccce; }
#bkadm .bk-viol { margin:4px 0 0; padding-left:18px; font-size:12px; color:#a80000; }
</style>
<?php
/** One action form: hidden fields, a button, an optional confirmation. */
$form = function ($fields, $label, $class, $confirm = '', $style = 'display:inline') {
    $h = '<form method="post" style="' . $style . '"'
        . ($confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')"' : '')
        . '>' . bk_csrf_field();
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . $k . '" value="' . bk_e($v) . '">';
    return $h . '<button type="submit" class="' . $class . '">' . bk_e($label) . '</button></form> ';
};
$th = function ($keys) {
    $h = '<tr>';
    foreach ($keys as $k) $h .= '<th>' . ($k === '' ? '' : bk_e(bk_t($k))) . '</th>';
    return $h . '</tr>';
};
$base = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/';

$out = '<div id="bkadm"><h1>' . bk_e(bk_t('MnuTargets')) . '</h1>'
    . '<p style="font-size:13px"><a href="' . $base . 'competition.php">← ' . bk_e(bk_t('Brand')) . '</a>'
    . ' &nbsp;·&nbsp; <a href="' . $base . 'field.php">' . bk_e(bk_t('MnuField')) . '</a></p>'
    . ($msg ? '<div class="bk-msg bk-ok">' . bk_e($msg) . '</div>' : '')
    . ($err ? '<div class="bk-msg bk-err">' . bk_e($err) . '</div>' : '');

if ($pending) {
    if ($trust && in_array(aut_trust_mode(), array('alert', 'block'), true)) {   // one query for the whole list
        $subjects = array();
        foreach ($pending as $p) $subjects[] = aut_trust_subject($p->EnCode);
        aut_trust_preload($subjects);
    }
    $out .= '<div class="bk-sec" style="border-color:#cb8137"><h2 style="color:#cb8137">' . bk_e(bk_t('TgPendingTitle'))
        . ' <span class="bk-pill bk-pill-warn">' . count($pending) . '</span></h2>'
        . '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('TgPendingHint')) . '</p><table class="bk-t">'
        . $th(array('ColArcher', 'Licence', 'SsCategory', 'Club', 'SsDeparture', ''));
    foreach ($pending as $p) {
        $out .= '<tr><td>' . bk_e(trim($p->EnFirstName . ' ' . $p->EnName)) . ($trust ? aut_trust_badge($p->EnCode, $TOUR) : '')
            . '</td><td>' . bk_e($p->EnCode) . '</td>'
            . '<td>' . bk_e(trim(($p->DivDescription ?: $p->EnDivision) . ' ' . ($p->ClDescription ?: $p->EnClass))) . '</td>'
            . '<td>' . bk_e($p->CoName ?: $p->CoCode) . '</td>'
            . '<td>' . ($p->QuSession ? bk_e(bk_t('DepCap', intval($p->QuSession))) : '—') . '</td>'
            . '<td>' . $form(array('action' => 'validate', 'enid' => intval($p->BrEnId)), bk_t('TgValidate'), 'bk-btn bk-btn-primary', '', 'margin:0') . '</td></tr>';
    }
    $out .= '</table>' . $form(array('action' => 'validate_all'), bk_t('TgValidateAll', count($pending)), 'bk-btn', bk_t('TgValidateAllConfirm'), 'margin:6px 0 0')
        . '</div>';
}
if ($trust) $out .= aut_trust_org_html($TOUR, bk_csrf_field());

$out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('TgDepartures')) . '</h2>';
if (!$sessions) {
    $out .= '<p class="bk-hint">' . bk_e(bk_t('TgNoSession')) . '</p>';
} else {
    $out .= '<table class="bk-t">' . $th(array('SsDeparture', 'TgPlaces', 'TgRegistered', 'TgPlacedCol', 'TgActions'));
    foreach ($sessions as $s) {
        $o = intval($s->SesOrder);
        $c = null;
        foreach ($controle as $x) if ($x['depart'] === $o) $c = $x;
        $registered = $c ? $c['archers'] : 0;
        $placed = $c ? $registered - $c['nonPlaces'] : 0;
        $out .= '<tr><td>' . $o . ($s->SesName ? ' — ' . bk_e($s->SesName) : '') . '</td><td>' . intval($s->Places) . '</td>'
            . '<td>' . $registered . '</td><td>' . $placed . ' / ' . $registered . '</td><td>'
            . $form(array('action' => 'assign', 'session' => $o), bk_t('TgReassign'), 'bk-btn bk-btn-primary')
            . $form(array('action' => 'clear', 'session' => $o), bk_t('TgClear'), 'bk-btn', bk_t('TgClearConfirm', $o))
            . '<a class="bk-btn" style="text-decoration:none;display:inline-block" href="?plan=' . $o . '">' . bk_e(bk_t('TgSeePlan')) . '</a>'
            . '</td></tr>';
    }
    $out .= '</table>' . $form(array('action' => 'assign_all'), bk_t('TgReassignAll'), 'bk-btn bk-btn-primary', bk_t('TgReassignAllConfirm'), 'margin-top:8px');
    // Link to PlanQualifs (printable target plan), when installed.
    // ⚠️ That module is to be renamed/moved in the future: keep the path in one place here.
    $planQualifsDir = 'Modules/Custom/PlanQualifs/';
    if (is_dir($CFG->DOCUMENT_PATH . $planQualifsDir)) {
        $out .= '<a class="bk-btn" style="text-decoration:none;display:inline-block;margin-top:8px" href="'
            . bk_e($CFG->ROOT_DIR . $planQualifsDir) . '">' . bk_e(bk_t('TgPrintPlan')) . '</a>';
    }
    $out .= '<p class="bk-hint">' . bk_e(bk_t('TgReassignHint')) . '</p>';
}
$out .= '</div>';

$out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('TgRulesTitle')) . '</h2>';
if (!$controle) {
    $out .= '<p class="bk-hint">' . bk_e(bk_t('TgNobody')) . '</p>';
} else {
    $out .= '<table class="bk-t">' . $th(array('SsDeparture', 'TgArchers', 'TgClubs', 'TgPlacement', 'ColState'));
    foreach ($controle as $c) {
        $out .= '<tr><td>' . $c['depart'] . '</td><td>' . $c['archers'] . '</td>'
            . '<td>' . $c['clubs'] . ' <span class="bk-pill ' . ($c['clubsOk'] ? 'bk-pill-ok' : 'bk-pill-ko') . '">'
            . bk_e(bk_t('TgMinClubs', $c['minClubs'])) . '</span></td>'
            . '<td>' . bk_e($c['nonPlaces'] ? bk_t('TgUnplaced', $c['nonPlaces']) : bk_t('TgComplete')) . '</td><td>'
            . '<span class="bk-pill ' . ($c['ok'] ? 'bk-pill-ok' : 'bk-pill-ko') . '">' . bk_e(bk_t($c['ok'] ? 'TgCompliant' : 'TgToFix')) . '</span>';
        if ($c['exces']) {
            $out .= '<ul class="bk-viol">';
            foreach ($c['exces'] as $e) {
                $out .= '<li>' . bk_e(bk_t('TgTooMany', array('target' => intval($e['cible']), 'n' => intval($e['n']),
                    'club' => $e['club'], 'max' => $c['max']))) . '</li>';
            }
            $out .= '</ul>';
        }
        if ($c['doublons']) {
            $out .= '<ul class="bk-viol">';
            foreach ($c['doublons'] as $lic => $n) $out .= '<li>' . bk_e(bk_t('TgTwice', array('lic' => $lic, 'n' => intval($n)))) . '</li>';
            $out .= '</ul>';
        }
        $out .= '</td></tr>';
    }
    $out .= '</table><p class="bk-hint">' . bk_t('TgRulesHint', array('max' => intval($cfg->BcMaxPerClubPerTarget),
        'clubs' => intval($cfg->BcMinClubsPerSession))) . '</p>';
}
$out .= '</div>';

if ($requests) {
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('TgRequestsTitle')) . '</h2><p class="bk-hint">' . bk_e(bk_t('TgRequestsHint')) . '</p>'
        . '<table class="bk-t">' . $th(array('ColArcher', 'Licence', 'SsDeparture', 'TgRequest'));
    foreach ($requests as $d) {
        $out .= '<tr><td>' . bk_e(trim($d->EnFirstName . ' ' . $d->EnName)) . '</td><td>' . bk_e($d->EnCode) . '</td>'
            . '<td>' . ($d->QuSession !== null ? bk_e(bk_t('DepCap', intval($d->QuSession))) : '—') . '</td>'
            . '<td>' . nl2br(bk_e($d->BrRequest)) . '</td></tr>';
    }
    $out .= '</table></div>';
}

if ($voir && $plan) {
    $letters = array();
    foreach ($plan as $c) foreach (array_keys($c) as $l) $letters[$l] = true;
    ksort($letters);
    $letters = array_keys($letters);
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('TgPlanTitle', $voir)) . '</h2><table class="bk-t bk-plan"><tr><th>' . bk_e(bk_t('SsTarget')) . '</th>';
    foreach ($letters as $l) $out .= '<th>' . bk_e($l) . '</th>';
    $out .= '</tr>';
    foreach ($plan as $target => $byLetter) {
        $out .= '<tr><td><b>' . intval($target) . '</b></td>';
        foreach ($letters as $l) {
            $a = $byLetter[$l] ?? null;
            $out .= $a
                ? '<td>' . bk_e($a->EnFirstName . ' ' . $a->EnName) . '<br><span style="color:#7d8183">' . bk_e($a->CoCode) . ' · ' . bk_e($a->EnDivision . $a->EnClass) . '</span></td>'
                : '<td class="bk-empty-cell">—</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</table></div>';
}
echo $out . '</div>';
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
