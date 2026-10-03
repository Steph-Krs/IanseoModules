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

function rc_eur($n) { return bk_e(number_format((float) $n, 2, ',', ' ')) . ' €'; }

function rc_fail($text)
{
    bk_head('Reçu', 'card');
    echo '<div class="bk-card"><h1>Indisponible</h1>' . bk_msg('err', $text)
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">Mes inscriptions</a></p></div>';
    bk_foot();
    exit;
}

if (!empty($_GET['enid'])) {
    $e = safe_fetch(safe_r_sql("SELECT EnTournament, EnCode FROM Entries WHERE EnId = " . intval($_GET['enid'])));
    if (!$e || bk_clean_licence($e->EnCode) !== bk_clean_licence($archer->BaLicence)) rc_fail("Cette inscription n'est pas la vôtre.");
    bk_redirect('receipt.php?comp=' . intval($e->EnTournament));
}

$club = !empty($_GET['club']);
$tourId = intval($club ? ($_GET['t'] ?? 0) : ($_GET['comp'] ?? 0));
$tour = $tourId ? safe_fetch(safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo FROM Tournament WHERE ToId = $tourId")) : null;
if (!$tour) rc_fail('Compétition non précisée.');
$pdfWanted = !empty($_GET['pdf']);

if ($club) {
    $scopes = bk_manager_scopes($archer);
    if (!$scopes) rc_fail("Votre compte n'est pas déclaré gestionnaire de club.");
    $rows = array_filter(bk_accounts($tourId), function ($a) use ($scopes) {
        return $a['club_code'] !== '' && bk_scope_covers($scopes, $a['club_code']) && ($a['due'] > 0 || $a['moves'] || $a['count']);
    });
    if (!$rows) rc_fail('Aucun archer de votre club sur cette compétition.');
    uasort($rows, function ($x, $y) { return strcasecmp($x['club_code'] . $x['name'], $y['club_code'] . $y['name']); });
    if ($pdfWanted) {
        require_once dirname(__DIR__) . '/lib/ledger-pdf.php';
        bk_ledger_pdf_send(bk_ledger_pdf_build($tourId, 'Club', function ($pdf) use ($rows) {
            bk_ledger_pdf_list($pdf, $rows, 'Relevé du club', 'Situation au ' . bk_now_local_text());
        }, false), 'club-' . $tourId . '.pdf');
    }
    bk_head('Relevé du club');
    echo '<h1>Relevé du club</h1><p class="bk-hint"><b>' . bk_e($tour->ToName) . '</b> — '
        . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . ($tour->ToWhere ? ' — ' . bk_e($tour->ToWhere) : '') . '</p>'
        . '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('receipt.php?club=1&t=' . $tourId . '&pdf=1')) . '" target="_blank">'
        . 'Télécharger le relevé (PDF)</a></p>'
        . '<div class="bk-doc-scroll"><table class="bk-acc"><tr><th>Archer</th><th>Licence</th><th class="n">Dû</th>'
        . '<th class="n">Payé</th><th class="n">Reste</th><th>État</th></tr>';
    $t = array(0.0, 0.0, 0.0);
    foreach ($rows as $a) {
        echo '<tr><td>' . bk_e($a['name']) . '</td><td>' . bk_e($a['licence']) . '</td><td class="n">' . rc_eur($a['due']) . '</td>'
            . '<td class="n">' . rc_eur($a['paid']) . '</td><td class="n"><b>' . rc_eur($a['remaining']) . '</b></td>'
            . '<td>' . bk_e(bk_account_state_label(bk_account_state($a))) . '</td></tr>';
        $t[0] += $a['due']; $t[1] += $a['paid']; $t[2] += $a['remaining'];
    }
    echo '<tr class="tot"><td colspan="2">Total — ' . count($rows) . ' archer' . (count($rows) > 1 ? 's' : '') . '</td>'
        . '<td class="n">' . rc_eur($t[0]) . '</td><td class="n">' . rc_eur($t[1]) . '</td><td class="n">' . rc_eur($t[2]) . '</td><td></td></tr>'
        . '</table></div><p class="bk-hint">Ce relevé n\'est pas une facture.</p>'
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">← Mes inscriptions</a></p>';
    bk_foot();
    exit;
}

$a = bk_account($tourId, $archer->BaLicence);
if (!$a['registrations'] && !$a['shop_lines'] && !$a['moves']) {
    rc_fail("Vous n'avez aucune inscription, commande ni paiement sur cette compétition.");
}
if ($a['name'] === '') $a['name'] = trim($archer->BaFamilyName . ' ' . $archer->BaName);
// Over, and the organiser records no payment here: what was paid is unknown, nothing is claimed.
$known = !bk_is_finished($tour->ToWhenTo) || bk_ledger_tracked($tourId);

if ($pdfWanted) {
    require_once dirname(__DIR__) . '/lib/ledger-pdf.php';
    bk_ledger_pdf_send(bk_ledger_pdf_build($tourId, 'Reçu', function ($pdf) use ($a, $known) {
        bk_ledger_pdf_account($pdf, $a, true, $known);
    }), 'recu-' . $tourId . '.pdf');
}

$methods = bk_payment_methods();
$kinds = bk_ledger_kinds();
$state = bk_account_state($a);

bk_head('Reçu');
echo '<h1>Mon compte — ' . bk_e($tour->ToName) . '</h1><p class="bk-hint">'
    . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . ($tour->ToWhere ? ' — ' . bk_e($tour->ToWhere) : '') . '</p>'
    . '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('receipt.php?comp=' . $tourId . '&pdf=1')) . '" target="_blank">'
    . 'Télécharger le reçu (PDF)</a></p>';

echo '<h2 class="bk-acc-h">Consommations</h2><div class="bk-doc-scroll"><table class="bk-acc">';
foreach ($a['registrations'] as $r) {
    echo '<tr><td><b>Inscription — départ ' . intval($r['session']) . '</b><br>' . bk_e($r['category']) . '</td>'
        . '<td class="n"><b>' . rc_eur($r['price']) . '</b></td></tr>';
    foreach ($r['lines'] as $l) echo '<tr class="sub"><td>' . bk_e($l['label']) . '</td><td class="n">' . rc_eur($l['amount']) . '</td></tr>';
}
foreach ($a['shop_lines'] as $s) {
    echo '<tr><td>' . bk_e(($s['section'] !== '' ? $s['section'] : 'Boutique') . ' — ' . $s['label'])
        . ' <span class="bk-hint">' . intval($s['qty']) . ' × ' . rc_eur($s['unit']) . '</span></td>'
        . '<td class="n">' . rc_eur($s['amount']) . '</td></tr>';
}
echo '<tr class="tot"><td>Total dû</td><td class="n">' . rc_eur($a['due']) . '</td></tr></table></div>';

echo '<h2 class="bk-acc-h">Paiements</h2>';
if (!$a['moves']) {
    echo '<p class="bk-hint">Aucun paiement enregistré par l\'organisateur pour l\'instant.</p>';
} else {
    echo '<div class="bk-doc-scroll"><table class="bk-acc"><tr><th>Date</th><th>Mouvement</th><th class="n">Montant</th></tr>';
    foreach ($a['moves'] as $m) {
        $off = intval($m->BlgCancelled) > 0;
        echo '<tr' . ($off ? ' class="off"' : '') . '><td>' . bk_e(bk_date_fr($m->BlgWhen)) . '</td><td>'
            . bk_e(($kinds[$m->BlgKind] ?? $m->BlgKind) . (isset($methods[$m->BlgMethod]) ? ' — ' . $methods[$m->BlgMethod] : ''))
            . ($m->BlgLabel !== '' ? '<br><span class="bk-hint">' . bk_e($m->BlgLabel) . '</span>' : '')
            . ($off ? ' <span class="bk-hint">(annulé)</span>' : '') . '</td><td class="n">' . rc_eur($m->BlgAmount) . '</td></tr>';
    }
    echo '<tr class="tot"><td colspan="2">Total payé</td><td class="n">' . rc_eur($a['paid']) . '</td></tr></table></div>';
}

if (!$known) {
    echo '<p class="bk-hint">L\x27organisateur de cette compétition n\x27enregistre pas les paiements sur ce site : ce '
        . 'relevé indique ce qui était dû, pas ce qui a été réglé.</p>';
} elseif ($state === 'over') {
    echo '<p class="bk-acc-bal over">Trop-perçu : ' . rc_eur(-$a['remaining']) . ' — l\'organisateur vous doit cette somme</p>';
} elseif ($state === 'settled' || $state === 'none') {
    echo '<p class="bk-acc-bal ok">' . ($state === 'none' ? 'Rien à payer' : 'Soldé — merci !') . '</p>';
} else {
    echo '<p class="bk-acc-bal due">Reste à payer : ' . rc_eur($a['remaining']) . '</p>';
    $pay = bk_payinfo_get(bk_comp_config($tourId));
    if ($pay) {
        echo '<div class="bk-payinfo"><b>Moyens de paiement</b><ul>';
        foreach ($pay as $pi) {
            echo '<li>' . bk_e($pi['label']) . ' <span class="bk-hint">(' . bk_e($pi['whenLabel']) . ')</span>'
                . ($pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '') . '</li>';
        }
        echo '</ul></div>';
    }
}
echo '<p class="bk-hint">Relevé établi d\'après les tarifs de la compétition et les paiements enregistrés par '
    . 'l\'organisateur. Ce document n\'est pas une facture.</p>'
    . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">← Mes inscriptions</a></p>';
bk_foot();
