<?php
/**
 * admin/reimport.php — reconciliation after a competition re-import.
 *
 * lib/adopt.php brings back the settings, payments, shop and registrations automatically, then
 * records EACH gap as a "conflict" the organiser settles here:
 *   - category    : same archer/same departure, different category between booking and import;
 *   - onlybooking : registered online in booking but missing from the import (injected again by
 *                   default);
 *   - onlyimport  : participant of the import not registered through booking (made visible by
 *                   default);
 *   - reinject    : booking registration that could not be injected again (unknown licence,
 *                   departure gone).
 *
 * Each conflict is settled on one SIDE (import or booking). Meaning in bk_reimport_apply(). Two
 * global buttons settle everything on one side.
 * ⚠️ "Booking side" of an onlyimport = REMOVAL of the participant from the competition (Entry
 * deleted).
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/adopt.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();
$TOUR = intval($_SESSION['TourId']);
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } else {
        $action = (string) ($_POST['do'] ?? '');
        if ($action === 'bulk') {
            $side = ((string) ($_POST['side'] ?? '') === 'booking') ? 'booking' : 'import';
            $r = bk_reimport_bulk($TOUR, $side);
            $msg = bk_t($side === 'booking' ? 'RiBulkBooking' : 'RiBulkImport', intval($r['done']))
                . ($r['removed'] ? ', ' . bk_t('RiRemovedN', intval($r['removed'])) : '')
                . ($r['fail'] ? ', ' . bk_t('RiFailN', intval($r['fail'])) : '') . '.';
        } else {
            $rcId = intval($_POST['rc'] ?? 0);
            $rc = $rcId ? safe_fetch(safe_r_sql("SELECT * FROM BookingReimportConflicts
                WHERE RcId = $rcId AND RcTournament = $TOUR")) : null;
            $side = ((string) ($_POST['side'] ?? '') === 'booking') ? 'booking' : 'import';
            if (!$rc) {
                $err = bk_t('RiGone');
            } else {
                $r = bk_reimport_apply($TOUR, $rc, $side);
                if (!empty($r['ok'])) $msg = bk_t('RiApplied');
                else $err = bk_t('RiImpossible', $r['msg'] ?? '');
            }
        }
    }
}

$conflicts = bk_reimport_conflicts($TOUR);
$by = array('category' => array(), 'onlybooking' => array(), 'onlyimport' => array(), 'reinject' => array());
foreach ($conflicts as $c) { if (isset($by[$c->RcKind])) $by[$c->RcKind][] = $c; }

$backUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';

$catLabel = function ($j) {
    $a = is_array($j) ? $j : (array) json_decode((string) $j, true);
    $d = trim((string) ($a['division'] ?? ''));
    $c = trim((string) ($a['class'] ?? ''));
    return ($d || $c) ? bk_e(trim($d . ' ' . $c)) : '—';
};
// Form of one button per row (conflict + side + label + class + confirmation).
$btn = function ($rcId, $side, $labelKey, $class, $confirmKey = '') {
    $oc = $confirmKey ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode(bk_t($confirmKey), JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')"' : '';
    return '<form method="post" class="bk-inline"' . $oc . '>' . bk_csrf_field()
        . '<input type="hidden" name="do" value="apply"><input type="hidden" name="rc" value="' . intval($rcId) . '">'
        . '<input type="hidden" name="side" value="' . bk_e($side) . '"><button class="' . bk_e($class) . '">' . bk_e(bk_t($labelKey)) . '</button></form> ';
};
$head = function ($cols) {
    $h = '<tr>';
    foreach ($cols as $k) $h .= '<th>' . bk_e(bk_t($k)) . '</th>';
    return $h . '</tr>';
};
$bulk = function ($side, $confirm, $labelKey, $class) {
    return '<form method="post" class="bk-inline" onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')">'
        . bk_csrf_field() . '<input type="hidden" name="do" value="bulk"><input type="hidden" name="side" value="' . $side . '">'
        . '<button class="' . $class . '">' . bk_e(bk_t($labelKey)) . '</button></form> ';
};

$PAGE_TITLE = bk_t('RiPageTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm { max-width: 100%; }
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 6px; font-size:15px; color:#0254a8; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm .bk-hint { margin:2px 0 10px; font-size:12px; color:#7d8183; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; width:100%; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:6px 10px; text-align:left; vertical-align:middle; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-btn { padding:7px 13px; border:1px solid #0254a8; border-radius:6px;
    background:#0254a8; color:#fff; font-size:13px; font-weight:600; cursor:pointer; }
#bkadm .bk-btn:hover { background:#01367c; border-color:#01367c; }
#bkadm .bk-btn2 { padding:7px 13px; border:1px solid #d2d4d6; border-radius:6px;
    background:#fff; color:#334; font-size:13px; cursor:pointer; }
#bkadm .bk-btn2:hover { background:#f0f4ff; border-color:#0254a8; }
#bkadm .bk-btn-danger { border-color:#c0392b; color:#c0392b; background:#fff; }
#bkadm .bk-btn-danger:hover { background:#fdf0ef; }
#bkadm form.bk-inline { display:inline; margin:0; }
#bkadm .bk-empty { color:#5b6470; font-size:13px; }
#bkadm a.bk-back { font-size:13px; color:#0254a8; text-decoration:none; }
#bkadm .bk-bulk { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:8px; }
#bkadm details.bk-fold > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:4px 0; }
</style>
<?php
$out = '<div id="bkadm"><h1>' . bk_e(bk_t('RiTitle')) . '</h1>'
    . '<p><a class="bk-back" href="' . bk_e($backUrl) . '">' . bk_e(bk_t('RiBack')) . '</a></p>'
    . ($msg ? '<div class="bk-msg bk-ok">' . bk_e($msg) . '</div>' : '')
    . ($err ? '<div class="bk-msg bk-err">' . bk_e($err) . '</div>' : '');

if (!$conflicts) {
    echo $out . '<div class="bk-sec"><p class="bk-empty">' . bk_e(bk_t('RiNothing')) . '</p></div></div>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

$out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('RiAllTitle')) . '</h2>'
    . '<p class="bk-hint">' . bk_e(bk_t('RiAllHint', count($conflicts))) . '</p><div class="bk-bulk">'
    . $bulk('import', bk_t('RiAllImportConfirm'), 'RiAllImport', 'bk-btn')
    . $bulk('booking', bk_t('RiAllBookingConfirm', count($by['onlyimport'])), 'RiAllBooking', 'bk-btn2 bk-btn-danger')
    . '</div></div>';

if ($by['category']) {
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('RiCatTitle', count($by['category']))) . '</h2>'
        . '<p class="bk-hint">' . bk_e(bk_t('RiCatHint')) . '</p><table class="bk-t">'
        . $head(array('ColArcher', 'Licence', 'RiColBooking', 'RiColImport', 'RiColChoice'));
    foreach ($by['category'] as $c) {
        $out .= '<tr><td>' . bk_e($c->RcName ?: '—') . '</td><td>' . bk_e($c->RcLicence) . '</td><td>' . $catLabel($c->RcBooking) . '</td>'
            . '<td><b>' . $catLabel($c->RcImport) . '</b></td><td>' . $btn($c->RcId, 'import', 'RiKeepImport', 'bk-btn')
            . $btn($c->RcId, 'booking', 'RiUseBooking', 'bk-btn2', 'RiUseBookingConfirm') . '</td></tr>';
    }
    $out .= '</table></div>';
}

if ($by['onlybooking']) {
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('RiOnlyBkTitle', count($by['onlybooking']))) . '</h2>'
        . '<p class="bk-hint">' . bk_e(bk_t('RiOnlyBkHint')) . '</p><table class="bk-t">'
        . $head(array('ColArcher', 'Licence', 'SsCategory', 'RiColChoice'));
    foreach ($by['onlybooking'] as $c) {
        $out .= '<tr><td>' . bk_e($c->RcName ?: '—') . '</td><td>' . bk_e($c->RcLicence) . '</td><td>' . $catLabel($c->RcBooking) . '</td>'
            . '<td>' . $btn($c->RcId, 'booking', 'RiKeepReg', 'bk-btn')
            . $btn($c->RcId, 'import', 'RiRemove', 'bk-btn2 bk-btn-danger', 'RiRemoveRegConfirm') . '</td></tr>';
    }
    $out .= '</table></div>';
}

if ($by['onlyimport']) {
    $oi = $by['onlyimport'];
    $cap = 200;
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('RiOnlyImTitle', count($oi))) . '</h2>'
        . '<p class="bk-hint">' . bk_t('RiOnlyImHint') . '</p>'
        . '<details class="bk-fold"' . (count($oi) <= 30 ? ' open' : '') . '><summary>' . bk_e(bk_t('RiSeeList', count($oi))) . '</summary>'
        . '<table class="bk-t">' . $head(array('ColArcher', 'Licence', 'SsCategory', 'RiColChoice'));
    foreach (array_slice($oi, 0, $cap) as $c) {
        $out .= '<tr><td>' . bk_e($c->RcName ?: '—') . '</td><td>' . bk_e($c->RcLicence) . '</td><td>' . $catLabel($c->RcImport) . '</td>'
            . '<td>' . $btn($c->RcId, 'import', 'RiKeep', 'bk-btn')
            . $btn($c->RcId, 'booking', 'RiRemove', 'bk-btn2 bk-btn-danger', 'RiRemovePartConfirm') . '</td></tr>';
    }
    $out .= '</table>' . (count($oi) > $cap ? '<p class="bk-hint">' . bk_e(bk_t('RiMore', count($oi) - $cap)) . '</p>' : '')
        . '</details></div>';
}

if ($by['reinject']) {
    $out .= '<div class="bk-sec"><h2>' . bk_e(bk_t('RiReinjTitle', count($by['reinject']))) . '</h2>'
        . '<p class="bk-hint">' . bk_e(bk_t('RiReinjHint')) . '</p><table class="bk-t">'
        . $head(array('Licence', 'SsCategory', 'RiColReason', 'RiColChoice'));
    foreach ($by['reinject'] as $c) {
        $b = (array) json_decode((string) $c->RcBooking, true);
        $out .= '<tr><td>' . bk_e($c->RcLicence) . '</td><td>' . $catLabel($c->RcBooking) . '</td><td>' . bk_e((string) ($b['msg'] ?? '')) . '</td>'
            . '<td>' . $btn($c->RcId, 'booking', 'RiRetry', 'bk-btn')
            . $btn($c->RcId, 'import', 'RiDrop', 'bk-btn2 bk-btn-danger', 'RiDropConfirm') . '</td></tr>';
    }
    $out .= '</table></div>';
}

echo $out . '</div>';
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
