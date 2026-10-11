<?php
/**
 * desk/list.php — the check-in desk seen by the organiser: the participants of the open
 * competition with where each one stands at the registry (documents) and at the equipment
 * check — accepted, refused or not seen yet —, the last draw weight measured and the notes.
 * Filters by state and departure, and a search; totals on top.
 *
 * Also where the desk is switched on (ShopSettings.SgDesk) and opened: "Open the desk on this
 * device" makes the organiser a volunteer with every right (shp_staff_organiser), the address
 * and QR code of the desk are given for the volunteers' phones, and each row opens the archer's
 * file at the desk (GET with the session's CSRF token).
 *
 * ianseo look and ACL of the core: reading with participants read-only, acting with read-write.
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
$SELF = shp_url('desk/list.php');
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
            shp_settings_save($TOUR, array('desk' => $act === 'on'));
            header('Location: ' . $SELF);
            exit;
        } elseif ($act === 'open') {
            if (!shp_desk_on($TOUR) || $over) $errors[] = shp_t($over ? 'ShStfErrOver' : 'DkErrOff');
            else $errors[] = dl_open_desk($TOUR, $BY, '');
        }
    } elseif (isset($_GET['open'])) {
        // A row of the list: the token of the session guards this address against another site.
        $acc = (string) $_GET['open'];
        if (!hash_equals(bk_csrf_token(), (string) ($_GET['t'] ?? '')) || !shp_desk_account_ok($acc)) $errors[] = shp_t('ShStfErrSession');
        elseif (!shp_desk_on($TOUR) || $over) $errors[] = shp_t($over ? 'ShStfErrOver' : 'DkErrOff');
        else $errors[] = dl_open_desk($TOUR, $BY, $acc);
    }
}

$deskOn = shp_desk_on($TOUR);
$settings = shp_settings($TOUR);

/* ---- Filters ---- */
$f = (string) ($_GET['f'] ?? '');
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
$JS_SCRIPT = array(
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('admin.css')) . '">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('desk-list.css')) . '">',
);
$PAGE_TITLE = shp_t('DkMenu');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$state = function ($s) {
    return '<span class="dl-st dl-st' . intval($s) . '">' . shp_e(shp_t(array('DkStateNone', 'DkStateOk', 'DkStateKo')[intval($s)] ?? 'DkStateNone')) . '</span>';
};

$html = '<div id="shpadm" class="dl"><h1>' . shp_e(shp_t('DkListTitle')) . '</h1>'
    . '<p class="sa-lead">' . shp_e(shp_t('DkListLead')) . '</p>';
foreach ($errors as $e) $html .= shp_adm_msg('err', $e);

// Switch and access for the volunteers.
$html .= '<div class="sa-card"><div class="sa-switch' . ($deskOn ? ' sa-on' : '') . '"><div><b>' . shp_e(shp_t('DkSwitch')) . '</b>'
    . '<div class="sa-hint">' . shp_e(shp_t($deskOn ? 'DkSwitchOnHint' : 'DkSwitchOffHint')) . '</div></div>'
    . '<span class="sa-tag">' . shp_e(shp_t($deskOn ? 'DkOn' : 'DkOff')) . '</span></div>';
if ($RW && !shp_impersonating()) {
    $html .= '<div class="sa-bar"><form method="post" action="' . shp_e($SELF) . '">' . bk_csrf_field()
        . '<input type="hidden" name="act" value="' . ($deskOn ? 'off' : 'on') . '">'
        . '<button type="submit" class="sa-btn' . ($deskOn ? '' : ' sa-primary') . '">' . shp_e(shp_t($deskOn ? 'DkTurnOff' : 'DkTurnOn')) . '</button></form>';
    if ($deskOn && !$over) {
        $html .= '<form method="post" action="' . shp_e($SELF) . '">' . bk_csrf_field() . '<input type="hidden" name="act" value="open">'
            . '<button type="submit" class="sa-btn sa-primary">' . shp_e(shp_t('DkUseDesk')) . '</button></form>'
            . '<a class="sa-btn" href="' . shp_e(shp_url('admin/staff.php')) . '">' . shp_e(shp_t('DkVolunteers')) . '</a>';
    }
    $html .= '</div>';
}
if ($deskOn && $settings) {
    $url = shp_abs_url('desk/index.php?k=' . rawurlencode((string) $settings->SgPublicKey));
    $html .= '<details class="dl-access"><summary>' . shp_e(shp_t('DkAddressTitle')) . '</summary><div class="sa-address">'
        . '<div class="sa-qr">' . aut_qr_svg($url, 180) . '</div><div class="sa-address-text"><p>' . shp_e(shp_t('DkAddressHint')) . '</p>'
        . '<input type="text" class="sa-url" readonly value="' . shp_e($url) . '" onclick="this.select()"></div></div></details>';
}
$html .= '</div>';

// Totals.
$pct = function ($n, $of) { return $of > 0 ? ' (' . round(100 * $n / $of) . ' %)' : ''; };
$html .= '<div class="dl-tot">'
    . '<div class="sa-card"><b>' . shp_e(shp_t('DkLsArchers', $tot['archers'])) . '</b><small>' . shp_e(shp_t('DkLsEntries', $tot['n'])) . '</small></div>'
    . '<div class="sa-card"><b>' . shp_e(shp_t('DkRegTitle')) . '</b>'
    . '<small>' . $state(1) . ' ' . $tot['reg'][1] . $pct($tot['reg'][1], $tot['archers']) . '</small>'
    . '<small>' . $state(2) . ' ' . $tot['reg'][2] . '</small><small>' . $state(0) . ' ' . $tot['reg'][0] . '</small></div>'
    . '<div class="sa-card"><b>' . shp_e(shp_t('DkEquipTitle')) . '</b>'
    . '<small>' . $state(1) . ' ' . $tot['eq'][1] . $pct($tot['eq'][1], $tot['n']) . '</small>'
    . '<small>' . $state(2) . ' ' . $tot['eq'][2] . '</small><small>' . $state(0) . ' ' . $tot['eq'][0] . '</small></div>'
    . '</div>';

// Filters (GET form).
$fOpts = array();
foreach ($fs as $k => $key) $fOpts[$k] = shp_t($key);
$sOpts = array('' => shp_t('DkAllSessions'));
foreach ($sessions as $o => $s) $sOpts[(string) $o] = $s['label'];
$html .= '<form method="get" action="' . shp_e($SELF) . '" class="sa-row dl-filters">'
    . shp_adm_field(shp_t('DkLsShow'), '<select name="f" onchange="this.form.submit()">' . shp_adm_options($fOpts, $f) . '</select>')
    . shp_adm_field(shp_t('DkFilterSession'), '<select name="s" onchange="this.form.submit()">' . shp_adm_options($sOpts, $fSes) . '</select>')
    . shp_adm_field(shp_t('DkLsSearch'), '<input type="search" name="q" value="' . shp_e($q) . '" placeholder="' . shp_e(shp_t('DkSearchPh')) . '">', '', 'sa-grow')
    . '<button type="submit" class="sa-btn">' . shp_e(shp_t('DkLsApply')) . '</button></form>';

// Table.
$canOpen = $RW && $deskOn && !$over && !shp_impersonating();
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
$html .= '</div>';
echo $html;

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
