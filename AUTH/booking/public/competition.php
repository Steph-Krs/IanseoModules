<?php
/**
 * public/competition.php — detail of an open competition (from the calendar, or the link given
 * by the organiser).
 *
 * Name, dates, discipline, organiser, venue, fee, and the DEPARTURES with their date/time and
 * the places left. Registration button (the registration flow checks everything again on the
 * server: this screen informs).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_mandate_visible

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? 0);
$embed  = !empty($_GET['embed']);           // fragment (preview) asked by the calendar
$c = $tourId ? bk_comp_one($tourId) : null;

if (!$c) {
    if ($embed) { echo bk_msg('err', bk_t('CompClosedMsg')); exit; }
    bk_head(bk_t('CompTitle'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('CompUnavailable')) . '</h1>' . bk_msg('err', bk_t('CompClosedMsg'))
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">' . bk_e(bk_t('BackCalendar')) . '</a></p></div>';
    bk_foot();
    exit;
}

bk_money_tour($tourId);   // amounts of this page in its currency

// Club of the archer (read again from the licence file) for the eligibility.
$club = $archer->BaClubCode;
$q = safe_r_sql("SELECT LueCountry FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . " ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) $club = $r->LueCountry;

$blocked  = bk_comp_archer_blocked($c, $club);
$sessions = bk_comp_sessions($tourId);
$dd       = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
$labels   = bk_disc_labels();

$mine = 0;
foreach (bk_my_registrations($archer->BaLicence) as $r) if (intval($r->BrTournament) === $tourId) $mine++;

/** Date and time of a departure, '' when missing. */
function bk_comp_dt($v)
{
    $v = trim((string) $v);
    if ($v === '' || strpos($v, '0000') === 0) return '';
    $ts = strtotime($v);
    return $ts ? bk_t('DateAt', array('date' => date('d/m/Y', $ts), 'time' => date(bk_t('TimeFormat'), $ts))) : '';
}

if (!$embed) {
    bk_head($c->ToName);
    echo '<p class="bk-back"><a href="' . bk_e(bk_public_url('calendar.php')) . '">' . bk_e(bk_t('BackCalendar')) . '</a></p>';
}

echo '<div class="bk-detail"><div class="bk-detail-head">'
    . '<span class="bk-detail-ic">' . bk_disc_icon($dd['key'], 34) . ($dd['para'] ? bk_disc_icon_para(18) : '') . '</span><div>'
    . '<h1>' . bk_e($c->ToName) . '</h1><p class="bk-detail-sub">'
    . '<span>' . bk_e(($labels[$dd['key']] ?? '') . ($dd['para'] ? ' — ' . bk_t('Para') : '')) . '</span>'
    . '<span>' . bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) . '</span>'
    . ($c->ToWhere ? '<span>' . bk_e($c->ToWhere) . '</span>' : '') . '</p>'
    . ($c->ToComDescr ? '<p class="bk-org">' . bk_e(bk_t('OrganisedBy', $c->ToComDescr)) . '</p>' : '')
    . '</div></div>';

if ($blocked) {
    echo bk_msg('err', $blocked . ($c->BcRestrictTo ? ' ' . bk_t('OpenToAllOn', bk_date_fr($c->BcRestrictTo)) : ''));
}

echo '<h2>' . bk_e(bk_t('Departures')) . '</h2>';
if (!$sessions) {
    echo '<p class="bk-hint">' . bk_e(bk_t('NoDepYet')) . '</p>';
} else {
    echo '<ul class="bk-dep-list">';
    foreach ($sessions as $s) {
        $pl = intval($s->Places); $pr = intval($s->Pris);
        $left = max(0, $pl - $pr);
        $pc = $pl > 0 ? min(100, round($pr * 100 / $pl)) : 0;
        $dt = bk_comp_dt(bk_session_start($s));
        echo '<li class="bk-dep' . ($left === 0 ? ' bk-dep-full' : '') . '"><div class="bk-dep-main">'
            . '<b>' . bk_e(bk_t('DepCap', intval($s->SesOrder))) . ($s->SesName ? ' — ' . bk_e($s->SesName) : '') . '</b>'
            . ($dt ? '<span class="bk-dep-dt">' . bk_e($dt) . '</span>' : '') . '</div>';
        if (!empty($c->BcShowGauges)) {
            echo '<div class="bk-dep-gauge"><span class="bk-gauge' . ($left === 0 ? ' bk-gauge-full' : '') . '"><i style="width:' . $pc . '%"></i></span>'
                . '<span class="bk-dep-num">' . bk_e(bk_t($left > 1 ? 'PlacesMany' : 'PlacesOne', $left)) . '</span></div>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

echo '<div class="bk-detail-act">';
$cp = bk_pricing_get($c);
if (bk_pricing_is_advanced($cp)) {
    echo '<p class="bk-fee">' . bk_e(bk_t('FeeFrom', bk_eur(bk_price_min($c->BcFee, $cp))))
        . ' <span class="bk-hint">' . bk_e(bk_t('FeeFromHint')) . '</span></p>';
} elseif ((float) $c->BcFee > 0) {
    echo '<p class="bk-fee">' . bk_e(bk_t('Fee', bk_eur($c->BcFee))) . '</p>';
}
$regs  = '<a class="bk-btn" href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('NavMyRegs')) . '</a>';
$toReg = bk_e(bk_public_url('register-comp.php?t=' . $tourId));
if (bk_is_finished($c->ToWhenTo)) {
    echo '<p class="bk-tag">' . bk_e(bk_t('CompOver')) . '</p>' . ($mine > 0 ? $regs : '');
} elseif ($blocked) {
    echo '<p class="bk-hint">' . bk_e(bk_t('CantRegisterYet')) . '</p>';
} elseif ($mine > 0) {
    echo '<p class="bk-tag bk-tag-on">' . bk_e(bk_t('AlreadyIn')) . ($mine > 1 ? ' (' . $mine . ')' : '') . '</p>'
        . '<a class="bk-btn bk-btn-primary" href="' . $toReg . '">' . bk_e(bk_t('AddReg')) . '</a> ' . $regs;
} else {
    echo '<a class="bk-btn bk-btn-primary" href="' . $toReg . '">' . bk_e(bk_t('RegisterBtn')) . '</a>';
}
if (bk_docs_list($c, $tourId) || (bk_dossard_available($c, $tourId) && $mine > 0)) {
    echo ' <a class="bk-btn" href="' . bk_e(bk_public_url('documents.php?t=' . $tourId)) . '">' . bk_e(bk_t('CompDocsBtn')) . '</a>';
}
// Food & shop of the competition, when the organiser switched it on.
if (!bk_is_finished($c->ToWhenTo) && is_file(dirname(__DIR__) . '/../shop/lib/link.php')) {
    require_once dirname(__DIR__) . '/../shop/lib/link.php';
    $shpLinks = shp_public_links($tourId);
    if ($shpLinks['shop'] !== '') {
        echo ' <a class="bk-btn" href="' . bk_e($shpLinks['shop']) . '">' . bk_e(shp_t('ShCusBookingBtn')) . '</a>';
    }
}
echo '</div></div>';
if (!$embed) bk_foot();
