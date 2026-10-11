<?php
/**
 * desk/list.php — the check-in desk seen by the organiser: the participants of the open
 * competition with where each one stands at the registry (documents) and at the equipment
 * check — accepted, refused or not seen yet —, the last draw weight measured and the notes.
 * Filters by state and departure, and a search; totals on top.
 *
 * The desk is always there for the organisers (user's choice, 1.4.2 — it costs nothing while
 * nobody opens it: no task, no cron): "Open the desk on this device" makes the organiser a
 * volunteer with every right (shp_staff_organiser), and each row opens the archer's file at the
 * desk (GET with the session's CSRF token). Each view has ONE option: open the changes to the
 * volunteers — the registry here (ShopSettings.SgDesk), the equipment check in the judges' view
 * (SgJudges) —, and then gives the address and QR code of the desk for their phones.
 *
 * Two views of the same list, two menus: "Check-in" (this address) and "Judges" (?v=equip:
 * opened on the equipment not checked yet) — until the judges have their own page.
 *
 * ianseo look and ACL of the core, frame shared by AUTH's organiser pages (adm-lib.php): reading
 * with participants read-only, acting with read-write.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

require_once dirname(__DIR__) . '/lib/desk.php';
require_once dirname(__DIR__) . '/lib/purge.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_qr_svg

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$RW = hasFullACL(AclParticipants, 'pEntries', AclReadWrite);
$JUDGES = (string) ($_GET['v'] ?? '') === 'equip';
$SELF = shp_url('desk/list.php' . ($JUDGES ? '?v=equip' : ''));
$BY = shp_staff_by();
$window = shp_window($TOUR);
$over = !$window || $window['purge_due'];

/** Opens the desk on this device for the organiser (volunteer with every right), then goes there. */
function dl_open_desk($tour, $by, $account)
{
    $me = shp_staff_organiser($tour, $by);
    if (!$me) return shp_t('ShErrInternal');
    shp_staff_session_open(intval($me->SfId));
    header('Location: ' . shp_url('desk/index.php' . ($account !== '' ? '?a=' . rawurlencode($account) : '')));
    exit;
}

$errors = array();
if ($RW && !shp_impersonating()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $act = (string) ($_POST['act'] ?? '');
        if (!bk_csrf_check()) {
            $errors[] = shp_t('ShStfErrSession');
        } elseif ($act === 'on' || $act === 'off') {
            // The post of this view opened to the volunteers, or closed again.
            shp_settings_save($TOUR, array($JUDGES ? 'judges' : 'desk' => $act === 'on'));
            header('Location: ' . $SELF);
            exit;
        } elseif ($act === 'open') {
            if ($over) $errors[] = shp_t('ShStfErrOver');
            else $errors[] = dl_open_desk($TOUR, $BY, '');
        }
    } elseif (isset($_GET['open'])) {
        // A row of the list: the token of the session guards this address against another site.
        $acc = (string) $_GET['open'];
        if (!hash_equals(bk_csrf_token(), (string) ($_GET['t'] ?? '')) || !shp_desk_account_ok($acc)) $errors[] = shp_t('ShStfErrSession');
        elseif ($over) $errors[] = shp_t('ShStfErrOver');
        else $errors[] = dl_open_desk($TOUR, $BY, $acc);
    }
}

$volOn = $JUDGES ? shp_judges_on($TOUR) : shp_desk_on($TOUR);   // this view's post opened to the volunteers
$settings = shp_settings($TOUR);

/* ---- Filters ---- */
$f = (string) ($_GET['f'] ?? ($JUDGES ? 'eq0' : ''));
$fs = array('' => 'DkLsAll', 'reg0' => 'DkLsReg0', 'reg1' => 'DkLsReg1', 'reg2' => 'DkLsReg2',
    'eq0' => 'DkLsEq0', 'eq1' => 'DkLsEq1', 'eq2' => 'DkLsEq2', 'ko' => 'DkLsKo');
if (!isset($fs[$f])) $f = '';
$fSes = (string) ($_GET['s'] ?? '');
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 60));

$all = shp_desk_rows($TOUR);
$tot = array('n' => count($all), 'reg' => array(0, 0, 0), 'eq' => array(0, 0, 0));
$seenAcc = array();
foreach ($all as $r) {
    if (!isset($seenAcc[$r['account']])) { $seenAcc[$r['account']] = true; $tot['reg'][$r['reg']]++; }
    $tot['eq'][$r['equip']]++;
}
$tot['archers'] = count($seenAcc);
$sessions = shp_desk_sessions($TOUR);
$words = array_filter(preg_split('/\s+/u', $q), 'strlen');
$fold = function ($s) {
    $t = class_exists('Transliterator') ? \Transliterator::create('NFD; [:Nonspacing Mark:] Remove') : null;
    $s = $t ? $t->transliterate((string) $s) : (string) $s;
    return mb_strtolower($s);
};
$rows = array_filter($all, function ($r) use ($f, $fSes, $words, $fold) {
    if ($fSes !== '' && (string) $r['session'] !== $fSes) return false;
    switch ($f) {
        case 'reg0': case 'reg1': case 'reg2': if ($r['reg'] !== intval(substr($f, 3))) return false; break;   // bytes: our own codes
        case 'eq0': case 'eq1': case 'eq2': if ($r['equip'] !== intval(substr($f, 2))) return false; break;
        case 'ko': if ($r['reg'] !== 2 && $r['equip'] !== 2) return false; break;
    }
    if ($words) {
        $hay = $fold($r['family'] . ' ' . $r['given'] . ' ' . $r['licence'] . ' ' . $r['club'] . ' ' . $r['club_name']);
        foreach ($words as $w) if (mb_strpos($hay, $fold($w)) === false) return false;
    }
    return true;
});

/* ---- Page ---- */
$JS_SCRIPT = aut_adm_assets(array('<link rel="stylesheet" href="' . shp_e(shp_asset_url('desk-list.css')) . '">'));
$PAGE_TITLE = shp_t($JUDGES ? 'DkJudges' : 'DkMenu');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$state = function ($s) {
    return '<span class="dl-st dl-st' . intval($s) . '">' . shp_e(shp_t(array('DkStateNone', 'DkStateOk', 'DkStateKo')[intval($s)] ?? 'DkStateNone')) . '</span>';
};

$html = aut_adm_open('shop/desk/list.php' . ($JUDGES ? '?v=equip' : ''), shp_t($JUDGES ? 'DkJudgesTitle' : 'DkListTitle'), '', 'dl',
    shp_e(shp_t($JUDGES ? 'DkJudgesLead' : 'DkListLead')));
foreach ($errors as $e) $html .= shp_adm_msg('err', $e);

// The desk on this device (organisers, always), and the one option of this view: the changes
// opened to the volunteers.
list($qS, $hS) = aut_adm_help(shp_e(shp_t($JUDGES ? 'DkJSwitchHint' : 'DkSwitchHint')));
$html .= '<div class="sa-card">' . aut_adm_title('h2', shp_t('ShSetSectSwitch')) . '<div class="sa-switch' . ($volOn ? ' sa-on' : '') . '"><div><b>' . shp_e(shp_t('DkSwitch')) . '</b>' . $qS . $hS . '</div>'
    . '<span class="sa-tag">' . shp_e(shp_t($volOn ? 'DkOn' : 'DkOff')) . '</span></div>';
if ($RW && !shp_impersonating()) {
    $html .= '<div class="sa-bar"><form method="post" action="' . shp_e($SELF) . '">' . bk_csrf_field()
        . '<input type="hidden" name="act" value="' . ($volOn ? 'off' : 'on') . '">'
        . '<button type="submit" class="sa-btn">' . shp_e(shp_t($volOn ? 'DkTurnOff' : 'DkTurnOn')) . '</button></form>';
    if (!$over) {
        $html .= '<form method="post" action="' . shp_e($SELF) . '">' . bk_csrf_field() . '<input type="hidden" name="act" value="open">'
            . '<button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t($JUDGES ? 'DkJUseDesk' : 'DkUseDesk')) . '</button></form>';
    }
    $html .= '<a class="sa-btn" href="' . shp_e(shp_url('admin/staff.php')) . '">' . shp_e(shp_t('DkVolunteers')) . '</a></div>';
}
if ($volOn && $settings) {
    $url = shp_abs_url('desk/index.php?k=' . rawurlencode((string) $settings->SgPublicKey));
    $html .= '<details class="sa-access"><summary>' . shp_e(shp_t('DkAddressTitle')) . '</summary><div class="sa-address">'
        . '<div class="sa-qr">' . aut_qr_svg($url, 180) . '</div><div class="sa-address-text"><p>' . shp_e(shp_t('DkAddressHint')) . '</p>'
        . '<input type="text" class="sa-url" readonly value="' . shp_e($url) . '" onclick="this.select()"></div></div></details>';
}
$html .= '</div>';

// Key figures: the post of this view first.
$pct = function ($n, $of) { return $of > 0 ? ' · ' . round(100 * $n / $of) . ' %' : ''; };
$kReg = '<div class="sa-kpi"><span>' . shp_e(shp_t('DkRegTitle')) . '</span><b>' . $tot['reg'][1] . ' / ' . $tot['archers'] . '</b>'
    . '<small>' . $state(1) . $pct($tot['reg'][1], $tot['archers']) . ' · ' . $state(2) . ' ' . $tot['reg'][2] . ' · ' . $state(0) . ' ' . $tot['reg'][0] . '</small></div>';
$kEq = '<div class="sa-kpi"><span>' . shp_e(shp_t('DkEquipTitle')) . '</span><b>' . $tot['eq'][1] . ' / ' . $tot['n'] . '</b>'
    . '<small>' . $state(1) . $pct($tot['eq'][1], $tot['n']) . ' · ' . $state(2) . ' ' . $tot['eq'][2] . ' · ' . $state(0) . ' ' . $tot['eq'][0] . '</small></div>';
$html .= '<div class="sa-kpis">'
    . '<div class="sa-kpi"><span>' . shp_e(shp_t('DkLsName')) . '</span><b>' . shp_e(shp_t('DkLsArchers', $tot['archers'])) . '</b>'
    . '<small>' . shp_e(shp_t('DkLsEntries', $tot['n'])) . '</small></div>'
    . ($JUDGES ? $kEq . $kReg : $kReg . $kEq) . '</div>';

// Filters (GET form).
$fOpts = array();
foreach ($fs as $k => $key) $fOpts[$k] = shp_t($key);
$sOpts = array('' => shp_t('DkAllSessions'));
foreach ($sessions as $o => $s) $sOpts[(string) $o] = $s['label'];
$html .= '<form method="get" action="' . shp_e($SELF) . '" class="sa-row dl-filters">' . ($JUDGES ? '<input type="hidden" name="v" value="equip">' : '')
    . shp_adm_field(shp_t('DkLsShow'), '<select name="f" onchange="this.form.submit()">' . shp_adm_options($fOpts, $f) . '</select>')
    . shp_adm_field(shp_t('DkFilterSession'), '<select name="s" onchange="this.form.submit()">' . shp_adm_options($sOpts, $fSes) . '</select>')
    . shp_adm_field(shp_t('DkLsSearch'), '<input type="search" name="q" value="' . shp_e($q) . '" placeholder="' . shp_e(shp_t('DkSearchPh')) . '">', '', 'sa-grow')
    . '<button type="submit" class="sa-btn">' . shp_e(shp_t('DkLsApply')) . '</button></form>';

// Table.
$canOpen = $RW && !$over && !shp_impersonating();
$token = bk_csrf_token();
$html .= '<p class="sa-muted">' . shp_e(shp_t('DkLsShown', count($rows))) . '</p>';
if (!$rows) {
    $html .= '<p class="sa-muted">' . shp_e(shp_t('DkLsNone')) . '</p>';
} else {
    $html .= '<div class="sa-scroll"><table class="sa-table dl-table"><thead><tr>'
        . '<th>' . shp_e(shp_t('DkSession')) . '</th><th>' . shp_e(shp_t('DkTarget')) . '</th><th>' . shp_e(shp_t('DkLsName')) . '</th>'
        . '<th>' . shp_e(shp_t('DkLicence')) . '</th><th>' . shp_e(shp_t('DkClub')) . '</th><th>' . shp_e(shp_t('DkWeapon')) . '</th>'
        . '<th>' . shp_e(shp_t('DkRegTitle')) . '</th><th>' . shp_e(shp_t('DkEquipTitle')) . '</th><th>' . shp_e(shp_t('DkNotesTitle')) . '</th>'
        . '<th>' . shp_e(shp_t('DkStatus')) . '</th>' . ($canOpen ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $name = '<b>' . shp_e($r['family']) . '</b> ' . shp_e($r['given']);
        $power = $r['power'] !== null ? ' <small>' . shp_e(str_replace('.', bk_number_seps()['dec'], number_format($r['power'], 1, '.', ''))
            . ' ' . shp_t('DkPowerUnit')) . '</small>' : '';
        $html .= '<tr' . ($r['reg'] === 2 || $r['equip'] === 2 ? ' class="dl-ko"' : '') . '>'
            . '<td data-label="' . shp_e(shp_t('DkSession')) . '">' . ($r['session'] > 0 ? intval($r['session']) : '—') . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkTarget')) . '">' . shp_e($r['target'] ?: '—') . '</td>'
            . '<td>' . $name . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkLicence')) . '">' . shp_e($r['licence']) . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkClub')) . '">' . shp_e(trim($r['club'] . ' ' . $r['club_name'])) . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkWeapon')) . '">' . shp_e($r['division_label'] . ' — ' . $r['class_label']) . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkRegTitle')) . '">' . $state($r['reg']) . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkEquipTitle')) . '">' . $state($r['equip']) . $power . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkNotesTitle')) . '" class="sa-c">' . ($r['notes'] ? intval($r['notes']) : '') . '</td>'
            . '<td data-label="' . shp_e(shp_t('DkStatus')) . '"><small>' . shp_e(shp_desk_status_label($r['status'])) . '</small></td>'
            . ($canOpen ? '<td class="sa-acts"><a class="sa-btn" href="' . shp_e($SELF . '?open=' . rawurlencode($r['account']) . '&t=' . rawurlencode($token)) . '">'
                . shp_e(shp_t('DkLsOpen')) . '</a></td>' : '')
            . '</tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= aut_adm_close();
echo $html;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
