<?php
/**
 * public/competition.php — detail of an open competition (from the calendar, or the link given
 * by the organiser).
 *
 * Name, dates, discipline, organiser, venue, fee, and the DEPARTURES with their date/time and
 * the places left. Registration button (the registration flow checks everything again on the
 * server: this screen informs).
 *
 * For an archer registered (or who registered clubmates): their account on this competition —
 * balance, means of payment, receipt, survey — and the buttons to their registrations here
 * (registrations.php?t=), the documents (score sheets included) and the food & shop.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_mandate_visible
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/sessionrules.php';
require_once dirname(__DIR__) . '/lib/ffta-event.php';   // itinerary links

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
$adult = false;
$q = safe_r_sql("SELECT LueCountry, LueCtrlCode FROM LookUpEntries
    WHERE LueCode = " . StrSafe_DB($archer->BaLicence) . " ORDER BY LueDefault DESC LIMIT 1");
if ($r = safe_fetch($q)) { $club = $r->LueCountry; $adult = bk_is_major($r->LueCtrlCode); }

$blocked  = bk_comp_archer_blocked($c, $club);
$sessions = bk_comp_sessions($tourId);
$sesStates = bk_session_states($tourId, $c, $sessions);
$dd       = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
$labels   = bk_disc_labels();

$mine = 0; $mineRows = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) {
    if (intval($r->BrTournament) === $tourId) { $mine++; $mineRows[] = $r; }
}
$mates = 0;
foreach (bk_authored_registrations($archer->BaId, $archer->BaLicence) as $r) if (intval($r->BrTournament) === $tourId) $mates++;

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
    . bk_itinerary_html($tourId)
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
        $st = $sesStates[intval($s->SesOrder)] ?? array('open' => true);
        $shut = $st['open'] ? '' : bk_session_state_text($st);
        echo '<li class="bk-dep' . ($left === 0 ? ' bk-dep-full' : '') . ($shut !== '' ? ' bk-dep-shut' : '') . '"><div class="bk-dep-main">'
            . '<b>' . bk_e(bk_t('DepCap', intval($s->SesOrder))) . ($s->SesName ? ' — ' . bk_e($s->SesName) : '') . '</b>'
            . ($dt ? '<span class="bk-dep-dt">' . bk_e($dt) . '</span>' : '') . '</div>';
        if ($shut !== '') {
            echo '<span class="bk-dep-state">' . bk_e($shut) . '</span>';
        } elseif (!empty($c->BcShowGauges)) {
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
$regs  = ($mine + $mates) > 0 ? '<a class="bk-btn" href="' . bk_e(bk_public_url('registrations.php?t=' . $tourId)) . '">' . bk_e(bk_t('NavMyRegs')) . '</a>' : '';
$toReg = bk_e(bk_public_url('register-comp.php?t=' . $tourId));
$over  = bk_is_finished($c->ToWhenTo);
if ($over) {
    echo '<p class="bk-tag">' . bk_e(bk_t('CompOver')) . '</p>' . $regs;
} elseif ($blocked) {
    echo '<p class="bk-hint">' . bk_e(bk_t('CantRegisterYet')) . '</p>' . ($regs !== '' ? ' ' . $regs : '');
} elseif ($mine > 0) {
    // One registration per archer: nothing more for them, only clubmates (adults register them).
    echo '<p class="bk-tag bk-tag-on">' . bk_e(bk_t('AlreadyIn')) . ($mine > 1 ? ' (' . $mine . ')' : '') . '</p>'
        . (empty($c->BcSingleReg) ? '<a class="bk-btn bk-btn-primary" href="' . $toReg . '">' . bk_e(bk_t('AddReg')) . '</a> '
            : ($adult ? '<a class="bk-btn" href="' . $toReg . '">' . bk_e(bk_t('RegisterMate')) . '</a> ' : ''))
        . $regs;
} else {
    echo '<a class="bk-btn bk-btn-primary" href="' . $toReg . '">' . bk_e(bk_t('RegisterBtn')) . '</a>' . ($regs !== '' ? ' ' . $regs : '');
}
$scoresheets = $mineRows && !empty($c->BcAllowScoresheet);
if (bk_docs_list($c, $tourId) || (bk_dossard_available($c, $tourId) && ($mine + $mates) > 0) || $scoresheets) {
    echo ' <a class="bk-btn" href="' . bk_e(bk_public_url('documents.php?t=' . $tourId)) . '">' . bk_e(bk_t('DocsBtn')) . '</a>';
}
// Food & shop of the competition, when the organiser switched it on.
if (!$over && is_file(dirname(__DIR__) . '/../shop/lib/link.php')) {
    require_once dirname(__DIR__) . '/../shop/lib/link.php';
    $shpLinks = shp_public_links($tourId);
    if ($shpLinks['shop'] !== '') {
        echo ' <a class="bk-btn" href="' . bk_e($shpLinks['shop']) . '">' . bk_e(shp_t('ShCusBookingBtn')) . '</a>';
    }
}
echo '</div>';

// The archer's account on this competition: what they owe, how to pay it, the receipt, the
// survey once it is over.
$lic = bk_clean_licence($archer->BaLicence);
$due = bk_due_total($tourId, $lic);
$free = $due['total'] <= 0 && abs($due['paid']) < 0.005;
require_once dirname(__DIR__) . '/lib/survey.php';
$sv = bk_survey_open_for($archer->BaLicence)[$tourId] ?? null;
if ($mine > 0 || !$free || $sv) {
    $known = !$over || bk_ledger_tracked($tourId);
    $paid  = $due['remaining'] <= 0.005;
    echo '<section class="bk-comp-acc"><h2>' . bk_e(bk_t('AccountTitle')) . '</h2>';
    if (!$free) echo '<p class="bk-due">' . bk_balance_line($due, $known, $tourId) . '</p>';
    if (!$free && !$paid && $known) {
        echo bk_payinfo_box(bk_payinfo_get(bk_comp_config($tourId)), bk_payment_get($tourId, $archer->BaLicence));
    }
    echo '<p class="bk-comp-acc-act"><a class="bk-btn" href="' . bk_e(bk_public_url('receipt.php?comp=' . $tourId)) . '">'
        . bk_e(bk_t('AccountReceipt')) . '</a>';
    if ($sv) {
        echo ' <a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('survey.php?t=' . $tourId)) . '">'
            . bk_e(bk_t(intval($sv->Answered) ? 'SurveyEditIcon' : 'SurveyGiveIcon')) . '</a>';
    }
    echo '</p></section>';
}
echo '</div>';
if (!$embed) bk_foot();
