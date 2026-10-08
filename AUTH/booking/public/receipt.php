<?php
/**
 * public/receipt.php — an archer's account on a competition, available at any time.
 *
 * `?comp=<ToId>`          : the connected archer's account: everything consumed (registrations
 *                           with their tariff detail, shop), every payment movement, and what
 *                           is left to pay. `&pdf=1` gives the printable receipt (core PDF class).
 * `?club=1&t=<ToId>`      : accounts of the club(s) a declared manager looks after (`&pdf=1`).
 * `?enid=N`               : former per-registration link, sent to its competition's account.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/club.php';

$archer = bk_require_archer();

function rc_eur($n) { return bk_e(bk_eur($n)); }

function rc_fail($text)
{
    bk_head(bk_t('ReceiptTitle'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('Unavailable')) . '</h1>' . bk_msg('err', $text)
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('NavMyRegs')) . '</a></p></div>';
    bk_foot();
    exit;
}

if (!empty($_GET['enid'])) {
    $e = safe_fetch(safe_r_sql("SELECT EnTournament, EnCode FROM Entries WHERE EnId = " . intval($_GET['enid'])));
    if (!$e || bk_clean_licence($e->EnCode) !== bk_clean_licence($archer->BaLicence)) rc_fail(bk_t('NotYourReg'));
    bk_redirect('receipt.php?comp=' . intval($e->EnTournament));
}

$club = !empty($_GET['club']);
$tourId = intval($club ? ($_GET['t'] ?? 0) : ($_GET['comp'] ?? 0));
$tour = $tourId ? safe_fetch(safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo FROM Tournament WHERE ToId = $tourId")) : null;
if (!$tour) rc_fail(bk_t('CompNotGiven'));
bk_money_tour($tourId);   // amounts of this page in its currency
$pdfWanted = !empty($_GET['pdf']);

if ($club) {
    $scopes = bk_manager_scopes($archer);
    if (!$scopes) rc_fail(bk_t('NotManager'));
    $rows = array_filter(bk_accounts($tourId), function ($a) use ($scopes) {
        return $a['club_code'] !== '' && bk_scope_covers($scopes, $a['club_code']) && ($a['due'] > 0 || $a['moves'] || $a['count']);
    });
    if (!$rows) rc_fail(bk_t('NoClubArcher'));
    uasort($rows, function ($x, $y) { return strcasecmp($x['club_code'] . $x['name'], $y['club_code'] . $y['name']); });
    if ($pdfWanted) {
        require_once dirname(__DIR__) . '/lib/ledger-pdf.php';
        bk_ledger_pdf_send(bk_ledger_pdf_build($tourId, bk_t('ClubStatement'), function ($pdf) use ($rows) {
            bk_ledger_pdf_list($pdf, $rows, bk_t('ClubStatement'), bk_t('SituationOn', bk_now_local_text()));
        }, false), 'club-' . $tourId . '.pdf');
    }
    bk_head(bk_t('ClubStatement'));
    echo '<h1>' . bk_e(bk_t('ClubStatement')) . '</h1><p class="bk-hint"><b>' . bk_e($tour->ToName) . '</b> — '
        . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . ($tour->ToWhere ? ' — ' . bk_e($tour->ToWhere) : '') . '</p>'
        . '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('receipt.php?club=1&t=' . $tourId . '&pdf=1')) . '" target="_blank">'
        . bk_e(bk_t('DownloadStatement')) . '</a></p>'
        . '<div class="bk-doc-scroll"><table class="bk-acc"><tr><th>' . bk_e(bk_t('ColArcher')) . '</th><th>' . bk_e(bk_t('Licence')) . '</th>'
        . '<th class="n">' . bk_e(bk_t('ColDue')) . '</th><th class="n">' . bk_e(bk_t('ColPaid')) . '</th>'
        . '<th class="n">' . bk_e(bk_t('ColLeft')) . '</th><th>' . bk_e(bk_t('ColState')) . '</th></tr>';
    $t = array(0.0, 0.0, 0.0);
    foreach ($rows as $a) {
        echo '<tr><td>' . bk_e($a['name']) . '</td><td>' . bk_e($a['licence']) . '</td><td class="n">' . rc_eur($a['due']) . '</td>'
            . '<td class="n">' . rc_eur($a['paid']) . '</td><td class="n"><b>' . rc_eur($a['remaining']) . '</b></td>'
            . '<td>' . bk_e(bk_account_state_label(bk_account_state($a))) . '</td></tr>';
        $t[0] += $a['due']; $t[1] += $a['paid']; $t[2] += $a['remaining'];
    }
    echo '<tr class="tot"><td colspan="2">' . bk_e(bk_t(count($rows) > 1 ? 'TotalArchersMany' : 'TotalArchersOne', count($rows))) . '</td>'
        . '<td class="n">' . rc_eur($t[0]) . '</td><td class="n">' . rc_eur($t[1]) . '</td><td class="n">' . rc_eur($t[2]) . '</td><td></td></tr>'
        . '</table></div><p class="bk-hint">' . bk_e(bk_t('NotInvoiceStatement')) . '</p>'
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('BackMyRegs')) . '</a></p>';
    bk_foot();
    exit;
}

$a = bk_account($tourId, bk_clean_licence($archer->BaLicence));
if (!$a['registrations'] && !$a['shp_lines'] && !$a['moves']) rc_fail(bk_t('NothingHere'));
if ($a['name'] === '') $a['name'] = trim($archer->BaFamilyName . ' ' . $archer->BaName);
// Over, and the organiser records no payment here: what was paid is unknown, nothing is claimed
// (unless the account holds nothing but food & shop sales, all recorded).
$known = bk_account_known($tourId, $a, bk_is_finished($tour->ToWhenTo));

if ($pdfWanted) {
    require_once dirname(__DIR__) . '/lib/ledger-pdf.php';
    bk_ledger_pdf_send(bk_ledger_pdf_build($tourId, bk_t('ReceiptTitle'), function ($pdf) use ($a, $known) {
        bk_ledger_pdf_account($pdf, $a, true, $known);
    }), 'recu-' . $tourId . '.pdf');
}

$methods = bk_payment_methods();
$kinds = bk_ledger_kinds();
$state = bk_account_state($a);

bk_head(bk_t('ReceiptTitle'));
echo '<h1>' . bk_e(bk_t('MyAccountX', $tour->ToName)) . '</h1><p class="bk-hint">'
    . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . ($tour->ToWhere ? ' — ' . bk_e($tour->ToWhere) : '') . '</p>'
    . '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('receipt.php?comp=' . $tourId . '&pdf=1')) . '" target="_blank">'
    . bk_e(bk_t('DownloadReceipt')) . '</a></p>';

echo '<h2 class="bk-acc-h">' . bk_e(bk_t('Consumption')) . '</h2><div class="bk-doc-scroll"><table class="bk-acc">';
foreach ($a['registrations'] as $r) {
    echo '<tr><td><b>' . bk_e(bk_t('RegDepLine', intval($r['session']))) . '</b><br>' . bk_e($r['category']) . '</td>'
        . '<td class="n"><b>' . rc_eur($r['price']) . '</b></td></tr>';
    foreach ($r['lines'] as $l) echo '<tr class="sub"><td>' . bk_e($l['label']) . '</td><td class="n">' . rc_eur($l['amount']) . '</td></tr>';
}
$lastOrder = 0;
foreach ($a['shp_lines'] as $s) {
    if ($s['order'] !== $lastOrder) {
        $lastOrder = $s['order'];
        echo '<tr><td colspan="2"><b>' . bk_e(bk_t('DuShpLine', array('stand' => $s['stand'] !== '' ? $s['stand'] : bk_t('DuShpTitle'),
            'number' => $s['number']))) . '</b> <span class="bk-hint">' . bk_e(bk_date_fr($s['date'])
            . ($s['tab'] ? ' · ' . bk_t('DuOnTab') : '')) . '</span></td></tr>';
    }
    echo '<tr class="sub"><td>' . bk_e($s['label']) . ' <span class="bk-hint">' . intval($s['qty']) . ' × ' . rc_eur($s['unit']) . '</span></td>'
        . '<td class="n">' . rc_eur($s['amount']) . '</td></tr>';
}
echo '<tr class="tot"><td>' . bk_e(bk_t('TotalDue')) . '</td><td class="n">' . rc_eur($a['due']) . '</td></tr></table></div>';

echo '<h2 class="bk-acc-h">' . bk_e(bk_t('Payments')) . '</h2>';
if (!$a['moves']) {
    echo '<p class="bk-hint">' . bk_e(bk_t('NoPaymentYet')) . '</p>';
} else {
    echo '<div class="bk-doc-scroll"><table class="bk-acc"><tr><th>' . bk_e(bk_t('ColDate')) . '</th><th>' . bk_e(bk_t('ColMove')) . '</th>'
        . '<th class="n">' . bk_e(bk_t('ColAmount')) . '</th></tr>';
    foreach ($a['moves'] as $m) {
        $off = intval($m->BlgCancelled) > 0;
        echo '<tr' . ($off ? ' class="off"' : '') . '><td>' . bk_e(bk_date_fr($m->BlgWhen)) . '</td><td>'
            . bk_e(($kinds[$m->BlgKind] ?? $m->BlgKind) . (isset($methods[$m->BlgMethod]) ? ' — ' . $methods[$m->BlgMethod] : ''))
            . ($m->BlgLabel !== '' ? '<br><span class="bk-hint">' . bk_e($m->BlgLabel) . '</span>' : '')
            . ($off ? ' <span class="bk-hint">(' . bk_e(bk_ledger_off_word($m)) . ')</span>' : '') . '</td><td class="n">' . rc_eur($m->BlgAmount) . '</td></tr>';
    }
    echo '<tr class="tot"><td colspan="2">' . bk_e(bk_t('TotalPaid')) . '</td><td class="n">' . rc_eur($a['paid']) . '</td></tr></table></div>';
}

if (!$known) {
    echo '<p class="bk-hint">' . bk_e(bk_t('NotTracked')) . '</p>';
} elseif ($state === 'over') {
    echo '<p class="bk-acc-bal over">' . bk_e(bk_t('OverOwed', bk_eur(-$a['remaining']))) . '</p>';
} elseif ($state === 'settled' || $state === 'none') {
    echo '<p class="bk-acc-bal ok">' . bk_e(bk_t($state === 'none' ? 'StateNone' : 'SettledThanks')) . '</p>';
} else {
    echo '<p class="bk-acc-bal due">' . bk_e(bk_t('LeftToPayX', bk_eur($a['remaining']))) . '</p>';
    $pay = bk_payinfo_get(bk_comp_config($tourId));
    if ($pay) {
        echo '<div class="bk-payinfo"><b>' . bk_e(bk_t('PayMeansTitle')) . '</b><ul>';
        foreach ($pay as $pi) {
            echo '<li>' . bk_e($pi['label']) . ' <span class="bk-hint">(' . bk_e($pi['whenLabel']) . ')</span>'
                . ($pi['info'] !== '' ? ' — ' . bk_linkify($pi['info']) : '') . '</li>';
        }
        echo '</ul></div>';
    }
}
echo '<p class="bk-hint">' . bk_e(bk_t('StatementNote')) . '</p>'
    . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('BackMyRegs')) . '</a></p>';
bk_foot();
