<?php
/**
 * admin/dues.php — payments of the open competition (organiser).
 *
 * One account per participant, whatever the way they were registered (online or entered /
 * imported in ianseo): what is due according to the competition's tariff, plus the shop;
 * what was paid (journal BK_Ledger: when, how much, how); what is left. Payments, refunds
 * and cancellations are journal lines, never edited nor deleted. A whole club can be settled
 * in one go. Receipt of an account and list of the accounts as PDF (core PDF class).
 * Refunds owed after an anonymisation (BK_Refunds) are shown at the top.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/shop.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e, bk_date_fr
require_once dirname(__DIR__) . '/lib/adopt.php';    // bk_adopt_check

bk_schema();

$TOUR = intval($_SESSION['TourId']);
// A newer version of this competition was imported: take the previous one's registrations
// and payments over BEFORE anything is written here (a journal line makes it "already used").
$adopted = bk_adopt_check($TOUR);
$WHO = (string) ($_SESSION['AUTH_User'] ?? 'organisateur');
$SELF = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/dues.php';
$methods = bk_payment_methods();

function due_eur($n) { return number_format((float) $n, 2, ',', ' ') . ' €'; }
function due_hm($dt) { return preg_match('/ (\d{2}:\d{2})/', (string) $dt, $m) ? $m[1] : ''; }

/** Page URL with the given parameters (account, sort, message). */
function due_url($params = array())
{
    global $SELF;
    $q = http_build_query(array_filter($params, function ($v) { return $v !== '' && $v !== null; }));
    return $SELF . ($q !== '' ? '?' . $q : '');
}

/** Display name of an account. */
function due_name($a)
{
    if ($a['name'] !== '') return $a['name'];
    if ($a['account'] === 'ANON') return 'Archer(s) anonymisé(s)';
    return $a['licence'] !== '' ? $a['licence'] : 'Sans licence';
}

$accounts = bk_accounts($TOUR);

// Writes: each one is a journal line; then back to the page (post/redirect/get).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    $acc = (string) ($_POST['acc'] ?? '');
    $back = array('a' => $acc, 'sort' => (string) ($_POST['sort'] ?? ''));
    $done = '';
    if (!bk_csrf_check()) {
        $done = 'csrf';
    } elseif (isset($_POST['refund_done'])) {
        bk_refund_done($TOUR, intval($_POST['refund_done']), $WHO);
        $done = 'refund_done';
    } elseif ($act === 'pay' || $act === 'refund') {
        $amount = bk_money_in($_POST['amount'] ?? '');
        if (!isset($accounts[$acc])) {
            $done = 'unknown';
        } elseif ($amount <= 0) {
            $done = 'amount';
        } else {
            $note = trim((string) ($_POST['note'] ?? ''));
            $id = bk_ledger_add($TOUR, $acc, $act === 'pay' ? 'payment' : 'refund', $amount, (string) ($_POST['method'] ?? ''),
                (string) ($_POST['date'] ?? ''), $note !== '' ? $note : ($act === 'pay' ? 'Encaissement' : 'Remboursement'), $WHO);
            $done = $id ? $act : 'amount';
        }
    } elseif ($act === 'cancel') {
        $done = bk_ledger_cancel($TOUR, intval($_POST['id'] ?? 0), $WHO) ? 'cancel' : 'cancel_no';
    } elseif ($act === 'club') {
        $amounts = array();
        foreach ((array) ($_POST['amt'] ?? array()) as $k => $v) $amounts[(string) $k] = bk_money_in($v);
        $r = bk_ledger_pay_club($TOUR, (string) ($_POST['club'] ?? ''), $amounts, (string) ($_POST['method'] ?? ''),
            (string) ($_POST['date'] ?? ''), (string) ($_POST['note'] ?? ''), $WHO);
        $back = array('sort' => (string) ($_POST['sort'] ?? ''), 'n' => $r['count'], 'tot' => $r['total']);
        $done = $r['count'] ? 'club' : 'club_none';
    }
    header('Location: ' . due_url($back + array('done' => $done)));
    exit;
}

// PDF: receipt of one account, or the list.
if (($_GET['pdf'] ?? '') !== '') {
    require_once dirname(__DIR__) . '/lib/ledger-pdf.php';
    $acc = (string) ($_GET['a'] ?? '');
    if ($_GET['pdf'] === 'acc' && isset($accounts[$acc])) {
        $a = bk_account($TOUR, $acc);
        if ($a['name'] === '') $a['name'] = due_name($accounts[$acc]);
        bk_ledger_pdf_send(bk_ledger_pdf_build($TOUR, 'Reçu', function ($pdf) use ($a) {
            bk_ledger_pdf_account($pdf, $a);
        }), 'recu-' . $acc . '.pdf');
    }
    $list = array_filter($accounts, function ($a) { return $a['due'] > 0 || $a['moves'] > 0; });
    uasort($list, function ($x, $y) { return strcasecmp(due_name($x), due_name($y)); });
    foreach ($list as $k => $a) $list[$k]['name'] = due_name($a);
    bk_ledger_pdf_send(bk_ledger_pdf_build($TOUR, 'Paiements', function ($pdf) use ($list) {
        bk_ledger_pdf_list($pdf, $list, 'Paiements', 'Situation au ' . bk_now_local_text());
    }, false), 'paiements.pdf');
}

$messages = array(
    'csrf' => array('err', 'Session expirée — rechargez la page et réessayez.'),
    'refund_done' => array('ok', 'Remboursement noté comme effectué.'),
    'unknown' => array('err', 'Ce compte n\'existe pas sur cette compétition.'),
    'amount' => array('err', 'Montant illisible : saisissez par exemple 12 ou 12,50.'),
    'pay' => array('ok', 'Encaissement enregistré.'),
    'refund' => array('ok', 'Remboursement enregistré.'),
    'cancel' => array('ok', 'Mouvement annulé : une ligne d\'annulation a été ajoutée à l\'historique.'),
    'cancel_no' => array('err', 'Ce mouvement est déjà annulé.'),
    'club' => array('ok', 'Règlement du club enregistré : ' . intval($_GET['n'] ?? 0) . ' archer(s), '
        . due_eur((float) ($_GET['tot'] ?? 0)) . '.'),
    'club_none' => array('err', 'Rien n\'a été encaissé : aucun montant saisi pour les archers de ce club.'),
);
$msg = $messages[(string) ($_GET['done'] ?? '')] ?? null;

$sort = (string) ($_GET['sort'] ?? 'name');
if (!in_array($sort, array('name', 'club', 'due', 'remaining'), true)) $sort = 'name';
$rows = array_values(array_filter($accounts, function ($a) { return $a['due'] > 0 || $a['moves'] > 0 || $a['count'] > 0; }));
usort($rows, function ($x, $y) use ($sort) {
    if ($sort === 'due') return $y['due'] <=> $x['due'];
    if ($sort === 'remaining') return $y['remaining'] <=> $x['remaining'];
    if ($sort === 'club') return strcasecmp($x['club_code'] . due_name($x), $y['club_code'] . due_name($y));
    return strcasecmp(due_name($x), due_name($y));
});

$tot = array('due' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'over' => 0.0);
$clubs = array();   // club code => [name, count, total] of what is left to pay
foreach ($rows as $a) {
    $tot['due'] += $a['due']; $tot['paid'] += $a['paid'];
    if ($a['remaining'] > 0) $tot['remaining'] += $a['remaining']; else $tot['over'] -= $a['remaining'];
    if ($a['remaining'] > 0.005 && $a['club_code'] !== '') {
        if (!isset($clubs[$a['club_code']])) $clubs[$a['club_code']] = array('name' => $a['club_name'], 'count' => 0, 'total' => 0.0);
        $clubs[$a['club_code']]['count']++;
        $clubs[$a['club_code']]['total'] += $a['remaining'];
    }
}
ksort($clubs);

$cfg = bk_comp_config($TOUR);
$noTariff = (float) $cfg->BcFee <= 0 && !bk_pricing_is_advanced(bk_pricing_norm($cfg->BcPricing ?? ''));
$tour = safe_fetch(safe_r_sql("SELECT ToName FROM Tournament WHERE ToId = $TOUR"));
$today = bk_today();

/** Method options, $sel selected. */
function due_methods($sel)
{
    global $methods;
    $h = '<option value="">—</option>';
    foreach ($methods as $k => $l) $h .= '<option value="' . bk_e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . bk_e($l) . '</option>';
    return $h;
}

/** Payment or refund form of an account. */
function due_form($act, $acc, $amount, $method, $sort, $label)
{
    global $today;
    return '<form method="post" class="due-form">' . bk_csrf_field()
        . '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="acc" value="' . bk_e($acc) . '">'
        . '<input type="hidden" name="sort" value="' . bk_e($sort) . '">'
        . '<label>Montant <input type="text" name="amount" inputmode="decimal" size="7" value="'
        . ($amount > 0 ? bk_e(number_format($amount, 2, ',', '')) : '') . '" required> €</label>'
        . '<label>Moyen <select name="method">' . due_methods($method) . '</select></label>'
        . '<label>Date <input type="date" name="date" value="' . bk_e($today) . '" max="' . bk_e($today) . '"></label>'
        . '<label class="due-note">Note <input type="text" name="note" maxlength="120" placeholder="n° de chèque, reçu…"></label>'
        . '<button type="submit" class="due-btn' . ($act === 'refund' ? ' due-btn-warn' : '') . '">' . bk_e($label) . '</button></form>';
}

$PAGE_TITLE = 'Paiements';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

echo '<style>
#bkdue { max-width:1100px; }
#bkdue h1 { font-size:22px; color:#01367c; margin:0 0 4px; }
#bkdue h2 { font-size:17px; color:#01367c; margin:18px 0 8px; }
#bkdue .due-sub { color:#4c4e50; font-size:13px; margin:0 0 14px; }
#bkdue .due-kpis { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 14px; }
#bkdue .due-kpi { background:#f0f4ff; border:1px solid #c9d6ee; border-radius:8px; padding:8px 14px; min-width:150px; }
#bkdue .due-kpi b { display:block; font-size:18px; color:#01367c; }
#bkdue .due-kpi span { font-size:12px; color:#4c4e50; }
#bkdue .due-kpi.warn { background:#fdf0ef; border-color:#e8b4ae; }
#bkdue .due-kpi.warn b { color:#a32019; }
#bkdue .due-scroll { overflow-x:auto; }
#bkdue table { border-collapse:collapse; width:100%; font-size:14px; background:#fff; }
#bkdue th, #bkdue td { border:1px solid #d2d4d6; padding:6px 9px; text-align:left; vertical-align:top; }
#bkdue th { background:#f0f4ff; color:#01367c; white-space:nowrap; }
#bkdue th a { color:#01367c; text-decoration:none; }
#bkdue th a.on { text-decoration:underline; }
#bkdue .num { text-align:right; white-space:nowrap; }
#bkdue tr.tot td { background:#eef4fb; font-weight:700; color:#01367c; }
#bkdue .due-mut { font-size:12px; color:#7d8183; }
#bkdue .due-btn { padding:6px 12px; border:1px solid #0254a8; border-radius:6px; background:#0254a8; color:#fff;
    font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
#bkdue .due-btn-light { background:#fff; color:#0254a8; }
#bkdue .due-btn-warn { background:#c0392b; border-color:#c0392b; }
#bkdue .due-btn-sm { padding:3px 9px; font-size:12px; }
#bkdue .due-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkdue .due-msg-ok { background:#d2f4cd; border:1px solid #75ae77; color:#1d6b21; }
#bkdue .due-msg-err { background:#fdf0ef; border:1px solid #e8b4ae; color:#8b1a1a; }
#bkdue .due-msg-info { background:#fff8e1; border:1px solid #f0d58c; color:#6b5300; }
#bkdue .due-state { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; white-space:nowrap; }
#bkdue .st-due { background:#fdf0ef; color:#a32019; }
#bkdue .st-partial { background:#fff3d6; color:#8a5a00; }
#bkdue .st-settled { background:#d2f4cd; color:#1d6b21; }
#bkdue .st-over { background:#e7defa; color:#5b2a9e; }
#bkdue .st-none { background:#eceff1; color:#4c4e50; }
#bkdue .due-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 10px; }
#bkdue .due-filters button { padding:5px 11px; border:1px solid #c9d6ee; border-radius:14px; background:#fff; cursor:pointer; font-size:13px; }
#bkdue .due-filters button.on { background:#0254a8; border-color:#0254a8; color:#fff; }
#bkdue .due-filters input { padding:5px 9px; border:1px solid #d2d4d6; border-radius:6px; font-size:13px; min-width:220px; }
#bkdue .due-form { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin:6px 0; }
#bkdue .due-form label { display:flex; flex-direction:column; font-size:12px; color:#4c4e50; gap:2px; }
#bkdue .due-form input, #bkdue .due-form select { padding:5px 7px; border:1px solid #d2d4d6; border-radius:6px; font-size:13px; }
#bkdue .due-note input { width:180px; }
#bkdue details.due-box { border:1px solid #c9d6ee; border-radius:8px; padding:8px 12px; margin:0 0 12px; background:#fff; }
#bkdue details.due-box > summary { cursor:pointer; font-weight:600; color:#01367c; }
#bkdue .due-panel { border:2px solid #0254a8; border-radius:10px; padding:12px 16px; margin:0 0 18px; background:#fbfcff; }
#bkdue tr.is-cancelled td { color:#9a9ea1; text-decoration:line-through; }
#bkdue tr.is-cancelled td.keep { text-decoration:none; }
#bkdue tr.due-line td { border-top:none; border-bottom:none; font-size:12px; color:#4c4e50; padding-top:1px; padding-bottom:1px; }
</style>';

echo '<div id="bkdue"><h1>Paiements</h1><p class="due-sub">' . bk_e($tour->ToName ?? '') . ' — ' . count($rows)
    . ' compte' . (count($rows) > 1 ? 's' : '') . ' · inscriptions en ligne et participants saisis dans ianseo, boutique comprise</p>';

if ($adopted && !empty($adopted['ok'])) {
    echo '<div class="due-msg due-msg-info">Nouvelle version de la compétition importée : inscriptions en ligne et paiements '
        . 'de la version précédente repris. Les écarts éventuels se tranchent dans <a href="'
        . bk_e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php') . '">Inscriptions en ligne</a>.</div>';
}
if ($msg) echo '<div class="due-msg due-msg-' . ($msg[0] === 'ok' ? 'ok' : 'err') . '">' . bk_e($msg[1]) . '</div>';

$cfgUrl = bk_e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php');
if (!bk_comp_payments_on($cfg)) {
    echo '<div class="due-msg due-msg-info"><b>La gestion des paiements n\'est pas activée</b> pour cette compétition '
        . 'fermée : les archers ne voient ni leur compte ni la boutique. Cochez « Utiliser la gestion des paiements et la '
        . 'boutique » dans <a href="' . $cfgUrl . '">Inscriptions en ligne</a>.</div>';
} elseif ($noTariff) {
    echo '<div class="due-msg due-msg-info"><b>Aucun tarif n\'est configuré pour cette compétition</b> : les inscriptions '
        . 'valent 0 €. Le tarif se règle dans <a href="' . $cfgUrl . '">Inscriptions en ligne</a> — tarif de base dès '
        . '« Inscriptions ouvertes », tarif par catégorie, départ, provenance et dégressif dans les réglages détaillés ou, '
        . 'compétition fermée, une fois la gestion des paiements cochée. Les montants se recalculent aussitôt, ici comme '
        . 'sur les reçus.</div>';
}

echo '<div class="due-kpis">'
    . '<div class="due-kpi"><b>' . due_eur($tot['due']) . '</b><span>Total dû</span></div>'
    . '<div class="due-kpi"><b>' . due_eur($tot['paid']) . '</b><span>Encaissé (remboursements déduits)</span></div>'
    . '<div class="due-kpi' . ($tot['remaining'] > 0.005 ? ' warn' : '') . '"><b>' . due_eur($tot['remaining']) . '</b><span>Reste à encaisser</span></div>'
    . ($tot['over'] > 0.005 ? '<div class="due-kpi warn"><b>' . due_eur($tot['over']) . '</b><span>Trop-perçu à rendre</span></div>' : '')
    . '</div>';

// Refunds owed after the server removed a paid registration (anonymisation of a licensee,
// AUTH anonymise-lib.php). Club and amount only: the person has been anonymised.
$refunds = bk_refunds_of($TOUR);
if ($refunds) {
    $pending = array_filter($refunds, function ($f) { return !intval($f->BfDone); });
    echo '<div class="due-msg ' . ($pending ? 'due-msg-err' : 'due-msg-ok') . '">'
        . '<b>' . ($pending ? 'Remboursement à faire' : 'Remboursements effectués') . '</b> — archer(s) retiré(s) de cette '
        . 'compétition à la suite d\'une demande d\'anonymisation de ses données ; un paiement avait été enregistré. '
        . 'Son nom n\'est plus connu du serveur : le club et le montant permettent de retrouver le règlement.'
        . '<ul style="margin:6px 0 0 18px">';
    foreach ($refunds as $f) {
        $what = 'Un archer du club <b>' . bk_e(trim($f->BfClubCode . ' ' . $f->BfClubName)) . '</b> : <b>'
            . due_eur($f->BfAmount) . '</b>'
            . (isset($methods[$f->BfMethod]) ? ' (' . bk_e($methods[$f->BfMethod]) . ')' : '')
            . ' — signalé le ' . bk_e(bk_date_fr($f->BfCreated));
        if (intval($f->BfDone)) {
            echo '<li style="opacity:.75">' . $what . ' — remboursé le ' . bk_e(bk_date_fr($f->BfDoneAt))
                . ($f->BfDoneBy !== '' ? ' (' . bk_e($f->BfDoneBy) . ')' : '') . '</li>';
        } else {
            echo '<li>' . $what . ' <form method="post" style="display:inline"'
                . ' onsubmit="return confirm(\'Ce remboursement a-t-il été fait ?\')">' . bk_csrf_field()
                . '<button type="submit" name="refund_done" value="' . intval($f->BfId) . '" class="due-btn due-btn-sm">'
                . 'Remboursement effectué</button></form></li>';
        }
    }
    echo '</ul></div>';
}

// Detail of one account: what is due, line by line, and every movement.
$open = (string) ($_GET['a'] ?? '');
if ($open !== '' && isset($accounts[$open])) {
    $acc = bk_account($TOUR, $open);
    $row = $accounts[$open];
    $kinds = bk_ledger_kinds();
    $state = bk_account_state($acc);
    echo '<div class="due-panel" id="detail"><p style="float:right;margin:0">'
        . '<a class="due-btn due-btn-light" href="' . bk_e(due_url(array('a' => $open, 'pdf' => 'acc'))) . '" target="_blank">'
        . '<img src="' . $CFG->ROOT_DIR . 'Common/Images/pdf_small.gif" alt="" style="vertical-align:middle"> Reçu</a> '
        . '<a class="due-btn due-btn-light" href="' . bk_e(due_url(array('sort' => $sort))) . '">Fermer</a></p>'
        . '<h2 style="margin-top:0">' . bk_e(due_name($row)) . '</h2><p class="due-sub">'
        . bk_e(implode(' · ', array_filter(array($row['licence'] !== '' ? 'Licence ' . $row['licence'] : '',
            trim($row['club_code'] . ' ' . $row['club_name']),
            $row['decl'] !== '' ? 'Paiement prévu : ' . $row['decl'] : ''))))
        . '</p>';

    echo '<div class="due-scroll"><table><tr><th>Consommation</th><th class="num">Montant</th></tr>';
    foreach ($acc['registrations'] as $r) {
        echo '<tr><td><b>Inscription — départ ' . intval($r['session']) . '</b> — ' . bk_e($r['category'])
            . ' <span class="due-mut">(' . ($r['online'] ? 'inscription en ligne' : 'saisi dans ianseo') . ')</span></td>'
            . '<td class="num"><b>' . due_eur($r['price']) . '</b></td></tr>';
        foreach ($r['lines'] as $l) {
            echo '<tr class="due-line"><td>&nbsp;&nbsp;&nbsp;' . bk_e($l['label']) . '</td><td class="num">' . due_eur($l['amount']) . '</td></tr>';
        }
    }
    foreach ($acc['shop_lines'] as $s) {
        echo '<tr><td>' . bk_e(($s['section'] !== '' ? $s['section'] : 'Boutique') . ' — ' . $s['label'])
            . ' <span class="due-mut">' . intval($s['qty']) . ' × ' . due_eur($s['unit']) . '</span></td>'
            . '<td class="num">' . due_eur($s['amount']) . '</td></tr>';
    }
    if (!$acc['registrations'] && !$acc['shop_lines']) echo '<tr><td colspan="2" class="due-mut">Rien de dû.</td></tr>';
    echo '<tr class="tot"><td>Total dû</td><td class="num">' . due_eur($acc['due']) . '</td></tr></table></div>';

    echo '<h2>Historique</h2>';
    if (!$acc['moves']) {
        echo '<p class="due-mut">Aucun mouvement.</p>';
    } else {
        echo '<div class="due-scroll"><table><tr><th>Date</th><th>Type</th><th>Moyen</th><th>Libellé</th>'
            . '<th class="num">Montant</th><th>Saisi par</th><th></th></tr>';
        foreach ($acc['moves'] as $m) {
            $cancelled = intval($m->BlgCancelled) > 0;
            echo '<tr' . ($cancelled ? ' class="is-cancelled"' : '') . '><td>' . bk_e(bk_date_fr($m->BlgWhen)) . '</td>'
                . '<td>' . bk_e($kinds[$m->BlgKind] ?? $m->BlgKind) . '</td>'
                . '<td>' . bk_e($methods[$m->BlgMethod] ?? '') . '</td>'
                . '<td>' . bk_e($m->BlgLabel) . (intval($m->BlgGroup) ? ' <span class="due-mut">(règlement groupé)</span>' : '') . '</td>'
                . '<td class="num">' . due_eur($m->BlgAmount) . '</td>'
                . '<td class="keep due-mut">' . bk_e($m->BlgBy) . ' — ' . bk_e(bk_date_fr($m->BlgCreated) . ' ' . due_hm($m->BlgCreated)) . '</td>'
                . '<td class="keep">';
            if ($cancelled) {
                echo '<span class="due-mut">annulé</span>';
            } elseif ($m->BlgKind !== 'cancel') {
                echo '<form method="post" onsubmit="return confirm(\'Annuler ce mouvement ? Une ligne d\\\'annulation sera ajoutée, '
                    . 'l\\\'historique reste complet.\')">' . bk_csrf_field()
                    . '<input type="hidden" name="act" value="cancel"><input type="hidden" name="acc" value="' . bk_e($open) . '">'
                    . '<input type="hidden" name="id" value="' . intval($m->BlgId) . '">'
                    . '<button type="submit" class="due-btn due-btn-light due-btn-sm">Annuler</button></form>';
            }
            echo '</td></tr>';
        }
        echo '<tr class="tot"><td colspan="4">Total payé</td><td class="num">' . due_eur($acc['paid']) . '</td><td colspan="2"></td></tr></table></div>';
    }

    echo '<p style="font-size:16px;margin:12px 0 4px"><span class="due-state st-' . $state . '">'
        . bk_e(bk_account_state_label($state)) . '</span> '
        . ($state === 'over' ? 'Trop-perçu : <b>' . due_eur(-$acc['remaining']) . '</b>'
            : 'Reste à payer : <b>' . due_eur(max(0, $acc['remaining'])) . '</b>') . '</p>';
    echo due_form('pay', $open, max(0, $acc['remaining']), $row['decl_method'], $sort, 'Encaisser');
    if ($acc['paid'] > 0.005) {
        echo '<details class="due-box" style="margin-top:8px"' . ($state === 'over' ? ' open' : '') . '><summary>Rembourser</summary>'
            . due_form('refund', $open, $state === 'over' ? -$acc['remaining'] : 0, '', $sort, 'Enregistrer le remboursement')
            . '</details>';
    }
    echo '</div>';
}

if (!$rows) {
    echo '<p class="due-mut"><i>Aucun participant ni commande sur cette compétition pour l\'instant.</i></p>';
} else {
    // Settle for a club: the organiser picks the club, then the amount of each archer —
    // prefilled with what each one still owes; or the registrations only; or a total shared
    // out (registrations first). One line per archer, tied by a group number.
    if ($clubs) {
        $clubSel = (string) ($_GET['club'] ?? '');
        $opts = '<option value="">— choisir —</option>';
        foreach ($clubs as $code => $c) {
            $opts .= '<option value="' . bk_e($code) . '"' . ((string) $code === $clubSel ? ' selected' : '') . '>'
                . bk_e(trim($code . ' ' . $c['name'])) . ' — ' . intval($c['count']) . ' archer' . ($c['count'] > 1 ? 's' : '')
                . ', ' . due_eur($c['total']) . '</option>';
        }
        echo '<details class="due-box" id="club"' . ($clubSel !== '' ? ' open' : '') . '><summary>Encaisser pour un club</summary>'
            . '<p class="due-mut" style="margin:6px 0">Un seul règlement pour plusieurs archers du club : chacun reçoit une ligne '
            . 'du montant indiqué, reliée aux autres, qui s\'annule ensuite séparément si besoin.</p>'
            . '<form method="get" action="#club" class="due-form"><input type="hidden" name="sort" value="' . bk_e($sort) . '">'
            . '<label>Club <select name="club" onchange="this.form.submit()">' . $opts . '</select></label>'
            . '<noscript><button type="submit" class="due-btn due-btn-light">Afficher</button></noscript></form>';
        if ($clubSel !== '' && isset($clubs[$clubSel])) {
            $members = array_filter($rows, function ($a) use ($clubSel) {
                return (string) $a['club_code'] === $clubSel && $a['remaining'] > 0.005;
            });
            $sum = array('rest' => 0.0, 'reg' => 0.0, 'shop' => 0.0);
            $lines = '';
            foreach ($members as $a) {
                $p = bk_account_left_parts($a);
                $sum['rest'] += $a['remaining']; $sum['reg'] += $p['reg']; $sum['shop'] += $p['shop'];
                $lines .= '<tr><td>' . bk_e(due_name($a)) . ($a['licence'] !== '' ? ' <span class="due-mut">' . bk_e($a['licence']) . '</span>' : '') . '</td>'
                    . '<td class="num">' . due_eur($a['remaining']) . '</td><td class="num">' . due_eur($p['reg']) . '</td>'
                    . '<td class="num">' . due_eur($p['shop']) . '</td>'
                    . '<td class="num"><input type="text" inputmode="decimal" size="7" class="due-amt" name="amt[' . bk_e($a['account']) . ']"'
                    . ' value="' . bk_e(number_format($a['remaining'], 2, ',', '')) . '" data-rest="' . $a['remaining'] . '"'
                    . ' data-reg="' . $p['reg'] . '" data-shop="' . $p['shop'] . '"> €</td></tr>';
            }
            echo '<form method="post" id="due-club" onsubmit="return confirm(\'Enregistrer ce règlement du club ?\')">' . bk_csrf_field()
                . '<input type="hidden" name="act" value="club"><input type="hidden" name="club" value="' . bk_e($clubSel) . '">'
                . '<input type="hidden" name="sort" value="' . bk_e($sort) . '">'
                . '<p class="due-fill" hidden>Pré-remplir : '
                . '<button type="button" class="due-btn due-btn-light due-btn-sm" data-fill="rest">Tout le reste</button> '
                . '<button type="button" class="due-btn due-btn-light due-btn-sm" data-fill="reg">Inscriptions seulement</button> '
                . '<button type="button" class="due-btn due-btn-light due-btn-sm" data-fill="none">Vider</button>'
                . ' &nbsp; ou répartir un total : <input type="text" inputmode="decimal" size="8" id="due-club-total" aria-label="Total à répartir"> € '
                . '<button type="button" class="due-btn due-btn-light due-btn-sm" id="due-club-split">Répartir</button></p>'
                . '<div class="due-scroll"><table><tr><th>Archer</th><th class="num">Reste à payer</th><th class="num">dont inscriptions</th>'
                . '<th class="num">dont boutique</th><th class="num">Montant encaissé</th></tr>' . $lines
                . '<tr class="tot"><td>Total</td><td class="num">' . due_eur($sum['rest']) . '</td><td class="num">' . due_eur($sum['reg']) . '</td>'
                . '<td class="num">' . due_eur($sum['shop']) . '</td><td class="num" id="due-club-sum"></td></tr></table></div>'
                . '<p class="due-msg due-msg-info" id="due-club-warn" hidden></p>'
                . '<div class="due-form"><label>Moyen <select name="method">' . due_methods('') . '</select></label>'
                . '<label>Date <input type="date" name="date" value="' . bk_e($today) . '" max="' . bk_e($today) . '"></label>'
                . '<label class="due-note">Note <input type="text" name="note" maxlength="80" placeholder="n° de chèque…"></label>'
                . '<button type="submit" class="due-btn">Encaisser pour le club</button></div>'
                . '<p class="due-mut">Montant vide ou 0 : l\'archer n\'est pas concerné par ce règlement. Dans le reste dû, ce qui '
                . 'a déjà été payé est compté sur les inscriptions d\'abord.</p></form>';
        } elseif ($clubSel !== '') {
            echo '<p class="due-mut">Rien à payer pour ce club.</p>';
        }
        echo '</details>';
    }

    echo '<div class="due-filters" id="due-filters">'
        . '<button type="button" class="on" data-f="all">Tous</button>'
        . '<button type="button" data-f="open">À payer</button>'
        . '<button type="button" data-f="settled">Soldés</button>'
        . '<button type="button" data-f="over">Trop-perçu</button>'
        . '<input type="search" id="due-q" placeholder="Nom, licence ou club" aria-label="Rechercher">'
        . '<a class="due-btn due-btn-light" style="margin-left:auto" href="' . bk_e(due_url(array('pdf' => 'list'))) . '" target="_blank">'
        . '<img src="' . $CFG->ROOT_DIR . 'Common/Images/pdf_small.gif" alt="" style="vertical-align:middle"> Liste</a></div>';

    $th = function ($key, $label, $num = false) use ($sort) {
        return '<th' . ($num ? ' class="num"' : '') . '><a class="' . ($sort === $key ? 'on' : '') . '" href="'
            . bk_e(due_url(array('sort' => $key))) . '">' . bk_e($label) . '</a></th>';
    };
    echo '<div class="due-scroll"><table id="due-table"><tr>' . $th('name', 'Archer') . $th('club', 'Club')
        . '<th class="num">Départs</th>' . $th('due', 'Total dû', true) . '<th class="num">Payé</th>'
        . $th('remaining', 'Reste', true) . '<th>État</th><th></th></tr>';
    foreach ($rows as $a) {
        $state = bk_account_state($a);
        $filter = in_array($state, array('due', 'partial'), true) ? 'open' : ($state === 'none' ? 'settled' : $state);
        $search = mb_strtolower(due_name($a) . ' ' . $a['licence'] . ' ' . $a['club_code'] . ' ' . $a['club_name']);
        echo '<tr data-f="' . $filter . '" data-q="' . bk_e($search) . '"' . ($a['account'] === $open ? ' style="background:#eef4fb"' : '') . '>'
            . '<td>' . bk_e(due_name($a)) . ($a['licence'] !== '' ? ' <span class="due-mut">' . bk_e($a['licence']) . '</span>' : '')
            . ($a['decl'] !== '' ? '<br><span class="due-mut">Prévu : ' . bk_e($a['decl']) . '</span>' : '') . '</td>'
            . '<td>' . bk_e($a['club_name'] !== '' ? $a['club_name'] : $a['club_code']) . '</td>'
            . '<td class="num">' . ($a['count'] ? intval($a['count']) : '') . '</td>'
            . '<td class="num">' . due_eur($a['due']) . ($a['shop'] > 0 ? '<br><span class="due-mut">dont boutique ' . due_eur($a['shop']) . '</span>' : '') . '</td>'
            . '<td class="num">' . due_eur($a['paid']) . '</td>'
            . '<td class="num"><b>' . due_eur($a['remaining']) . '</b></td>'
            . '<td><span class="due-state st-' . $state . '">' . bk_e(bk_account_state_label($state)) . '</span></td>'
            . '<td><a class="due-btn due-btn-sm' . ($state === 'due' || $state === 'partial' ? '' : ' due-btn-light') . '" href="'
            . bk_e(due_url(array('a' => $a['account'], 'sort' => $sort))) . '#detail">'
            . ($state === 'due' || $state === 'partial' ? 'Encaisser' : 'Détail') . '</a></td></tr>';
    }
    echo '<tr class="tot"><td colspan="3">Total — ' . count($rows) . ' compte' . (count($rows) > 1 ? 's' : '') . '</td>'
        . '<td class="num">' . due_eur($tot['due']) . '</td><td class="num">' . due_eur($tot['paid']) . '</td>'
        . '<td class="num">' . due_eur($tot['remaining'] - $tot['over']) . '</td><td colspan="2"></td></tr></table></div>';
    echo '<p class="due-mut" style="margin-top:10px">Montants calculés avec le tarif de la compétition (base, catégorie, départ, '
        . 'provenance, dégressif) pour chaque participant, inscrit en ligne ou saisi dans ianseo, plus la boutique. Un paiement '
        . 'ne se modifie pas : il s\'annule, et l\'annulation reste dans l\'historique. L\'archer voit son reste à payer et '
        . 'l\'historique dans son espace, et peut imprimer son reçu à tout moment. Ce n\'est pas une facture.</p>';

    echo '<script>
(function () {
  var box = document.getElementById("due-filters"); if (!box) return;
  var rows = document.querySelectorAll("#due-table tr[data-f]");
  var q = document.getElementById("due-q"), f = "all";
  function apply() {
    var s = q.value.trim().toLowerCase();
    Array.prototype.forEach.call(rows, function (r) {
      var ok = (f === "all" || r.getAttribute("data-f") === f) && (s === "" || r.getAttribute("data-q").indexOf(s) >= 0);
      r.style.display = ok ? "" : "none";
    });
  }
  Array.prototype.forEach.call(box.querySelectorAll("button[data-f]"), function (b) {
    b.addEventListener("click", function () {
      Array.prototype.forEach.call(box.querySelectorAll("button[data-f]"), function (x) { x.classList.remove("on"); });
      b.classList.add("on"); f = b.getAttribute("data-f"); apply();
    });
  });
  q.addEventListener("input", apply);
})();
(function () {
  /* Club payment: prefill each archer\'s amount, share a total out (registrations first,
     then the shop, in the order of the list) and show the sum being recorded. */
  var form = document.getElementById("due-club"); if (!form) return;
  var inputs = form.querySelectorAll("input.due-amt");
  var sumCell = document.getElementById("due-club-sum"), warn = document.getElementById("due-club-warn");
  function num(v) { v = parseFloat(String(v || "").replace(/\s| |€/g, "").replace(",", ".")); return isNaN(v) ? 0 : v; }
  function eur(n) { return n.toFixed(2).replace(".", ",") + " €"; }
  function put(inp, n) { inp.value = n > 0.004 ? n.toFixed(2).replace(".", ",") : ""; }
  function refresh() {
    var t = 0; Array.prototype.forEach.call(inputs, function (i) { t += num(i.value); });
    sumCell.textContent = eur(t);
  }
  form.querySelector(".due-fill").hidden = false;
  Array.prototype.forEach.call(form.querySelectorAll("button[data-fill]"), function (b) {
    b.addEventListener("click", function () {
      var k = b.getAttribute("data-fill");
      Array.prototype.forEach.call(inputs, function (i) { put(i, k === "none" ? 0 : num(i.getAttribute("data-" + k))); });
      warn.hidden = true; refresh();
    });
  });
  document.getElementById("due-club-split").addEventListener("click", function () {
    var left = num(document.getElementById("due-club-total").value);
    var got = []; Array.prototype.forEach.call(inputs, function () { got.push(0); });
    ["reg", "shop"].forEach(function (part) {
      Array.prototype.forEach.call(inputs, function (i, n) {
        var take = Math.min(left, num(i.getAttribute("data-" + part)));
        got[n] += take; left = Math.round((left - take) * 100) / 100;
      });
    });
    Array.prototype.forEach.call(inputs, function (i, n) { put(i, got[n]); });
    warn.hidden = left <= 0.004;
    warn.textContent = "Le total dépasse ce que doit le club de " + eur(left) + " : ajoutez-le à la main à l\'archer de votre choix.";
    refresh();
  });
  form.addEventListener("input", function (e) { if (e.target.classList.contains("due-amt")) refresh(); });
  refresh();
})();
</script>';
}
echo '</div>';
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
