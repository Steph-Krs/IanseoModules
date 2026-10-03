<?php
/**
 * lib/payment.php — accounts and payments of a competition.
 *
 * An ACCOUNT is a competition and a licence ('#<EnId>' for a participant without one).
 * - DUE: every registration of that licence, whatever its origin (online registration or
 *   participant entered/imported in ianseo), priced by the tariff configured for the
 *   competition (base, category, departure, provenance, multi-registration rank), plus the
 *   shop orders. Later: what is consumed on site, in the same account.
 * - PAID: the sum of the journal BK_Ledger (payments, refunds, cancellations — signed).
 * - REMAINING = due - paid; negative = paid too much, a refund to make.
 * The organiser manages it on admin/dues.php ("Paiements"); the archer sees it in "Mes
 * inscriptions", on the receipt (any time, with every line and movement) and, for a
 * competition over with something left to pay, on the home page.
 * BK_Payments keeps the archer's declared payment choice (PyDecl*). Its former "paid" tick
 * (PyPaid) is taken over once into the journal (bk_ledger_migrate).
 * No invoice: the legal elements (SIRET, address, VAT, sequential number) are not there.
 */

if (defined('BK_PAYMENT_LOADED')) return;
define('BK_PAYMENT_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/shop.php';

/** Moyens de paiement proposés. */
function bk_payment_methods()
{
    return array('cash' => 'Espèces', 'cheque' => 'Chèque', 'virement' => 'Virement',
        'cb' => 'Carte bancaire', 'online' => 'Paiement en ligne', 'autre' => 'Autre');
}

/** Quand un moyen est disponible. */
function bk_payinfo_when_labels()
{
    return array('before' => 'Avant la compétition', 'onsite' => 'Sur place', 'both' => 'Avant ou sur place');
}

/**
 * Moyens de paiement déclarés par l'organisateur (depuis BcPayInfo JSON).
 * @return array de ['m','label','when','whenLabel','info']
 */
function bk_payinfo_get($cfg)
{
    $raw = json_decode((string) ($cfg->BcPayInfo ?? ''), true);
    if (!is_array($raw)) return array();
    $methods = bk_payment_methods();
    $whens = bk_payinfo_when_labels();
    $out = array();
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        $m = (string) ($r['m'] ?? '');
        if (!isset($methods[$m])) continue;
        $when = (string) ($r['when'] ?? 'both');
        if (!isset($whens[$when])) $when = 'both';
        $out[] = array('m' => $m, 'label' => $methods[$m], 'when' => $when,
            'whenLabel' => $whens[$when], 'info' => (string) ($r['info'] ?? ''));
    }
    return $out;
}

/**
 * Choix de paiement présentés au compétiteur : chaque moyen autorisé, décliné en
 * « avant » et/ou « sur place » selon sa disponibilité. value = "moyen|quand".
 */
function bk_payinfo_choices($payinfo)
{
    $out = array();
    foreach ($payinfo as $pi) {
        $whens = ($pi['when'] === 'both') ? array('before', 'onsite') : array($pi['when']);
        foreach ($whens as $w) {
            $out[] = array('value' => $pi['m'] . '|' . $w, 'm' => $pi['m'], 'when' => $w,
                'label' => $pi['label'] . ' (' . ($w === 'before' ? 'avant' : 'sur place') . ')'
                    . ($pi['info'] !== '' ? ' — ' . $pi['info'] : ''));
        }
    }
    return $out;
}

/** Libellé court d'une déclaration de paiement (moyen + quand). '' si vide. */
function bk_payment_decl_label($method, $when)
{
    if ($method === '') return '';
    $methods = bk_payment_methods();
    $lbl = $methods[$method] ?? $method;
    if ($when === 'before') $lbl .= ' (avant)';
    elseif ($when === 'onsite') $lbl .= ' (sur place)';
    return $lbl;
}

/**
 * Déclaration du compétiteur : moyen souhaité + quand (before/onsite). Upsert sans
 * toucher au statut d'encaissement (que l'organisateur gère).
 */
function bk_payment_declare($tourId, $licence, $method, $when)
{
    bk_schema();
    $tourId = intval($tourId);
    $method = array_key_exists($method, bk_payment_methods()) ? $method : '';
    $when = in_array($when, array('before', 'onsite'), true) ? $when : '';
    $lic = StrSafe_DB($licence);
    safe_w_sql("INSERT INTO BK_Payments (PyTournament, PyLicence, PyDeclMethod, PyDeclWhen)
        VALUES ($tourId, $lic, " . StrSafe_DB($method) . ", " . StrSafe_DB($when) . ")
        ON DUPLICATE KEY UPDATE PyDeclMethod = " . StrSafe_DB($method) . ", PyDeclWhen = " . StrSafe_DB($when));
}

/** Construit le JSON BcPayInfo depuis le POST de la page de config ($_POST['pay']). */
function bk_payinfo_from_post($post)
{
    $methods = bk_payment_methods();
    $whens = bk_payinfo_when_labels();
    $out = array();
    foreach ((array) $post as $m => $row) {
        if (!isset($methods[$m]) || !is_array($row) || empty($row['on'])) continue;
        $when = (string) ($row['when'] ?? 'both');
        if (!isset($whens[$when])) $when = 'both';
        $out[] = array('m' => $m, 'when' => $when, 'info' => substr(trim((string) ($row['info'] ?? '')), 0, 255));
    }
    return $out ? json_encode($out) : '';
}

/** Ligne de paiement d'un archer sur une compétition, ou null. */
function bk_payment_get($tourId, $licence)
{
    bk_schema();
    return safe_fetch(safe_r_sql("SELECT * FROM BK_Payments
        WHERE PyTournament = " . intval($tourId) . " AND PyLicence = " . StrSafe_DB($licence))) ?: null;
}

/** Is everything paid on this competition (nothing left, something was due or paid)? */
function bk_payment_is_paid($tourId, $licence)
{
    $a = bk_account($tourId, $licence);
    return $a['remaining'] <= 0.005;
}

/** Declared payment choices of a competition: [licence => BK_Payments row]. */
function bk_payment_map($tourId)
{
    bk_schema();
    $rs = safe_r_sql("SELECT * FROM BK_Payments WHERE PyTournament = " . intval($tourId));
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->PyLicence] = $r;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Accounts                                                            */
/* ------------------------------------------------------------------ */

/** Account key of a participant: their licence, or '#<EnId>' without one. */
function bk_account_key($licence, $enId)
{
    $l = trim((string) $licence);
    return $l !== '' ? $l : '#' . intval($enId);
}

/** EnId of a '#<EnId>' account, 0 for a licence. */
function bk_account_enid($account)
{
    return preg_match('/^#(\d+)$/', (string) $account, $m) ? intval($m[1]) : 0;
}

/** Labels of the journal line kinds. */
function bk_ledger_kinds()
{
    return array('payment' => 'Encaissement', 'refund' => 'Remboursement', 'cancel' => 'Annulation');
}

/**
 * Registrations charged by the tariff, grouped by account: [account => ['name', 'club_code',
 * 'club_name', 'licence', 'online' => bool, 'reg' => total, 'rows' => [['enid', 'session',
 * 'category', 'online', 'price', 'lines' => tariff detail]]]]. $account: one account, or null
 * for the whole competition (one query).
 *
 * The rank (multi-registration discount) follows the order: online registrations first, by
 * date — as bk_rank_map() has always done — then the participants entered in ianseo, by EnId.
 * Qualifications in LEFT JOIN on purpose: a competition just imported may lack the rows until
 * the core repairs them (Partecipants/index.php); its participants must still be charged.
 */
function bk_account_registrations($tourId, $account = null)
{
    $tourId = intval($tourId);
    $cfg = bk_comp_config($tourId);
    $pricing = bk_pricing_norm($cfg->BcPricing ?? '');
    $where = "EnTournament = $tourId AND EnAthlete = 1";
    if ($account !== null) {
        $id = bk_account_enid($account);
        $where .= $id ? " AND EnId = $id AND EnCode = ''" : " AND EnCode = " . StrSafe_DB($account);
    }
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass, CoCode, CoName,
            QuSession, BrEnId, DivDescription, ClDescription
        FROM Entries
        LEFT JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry
        LEFT JOIN BK_Registrations ON BrEnId = EnId
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE $where
        ORDER BY (BrEnId IS NULL), BrCreated, BrId, EnId");
    $out = array();
    while ($r = safe_fetch($rs)) {
        $k = bk_account_key($r->EnCode, $r->EnId);
        if (!isset($out[$k])) {
            $out[$k] = array('name' => trim($r->EnFirstName . ' ' . $r->EnName), 'club_code' => (string) $r->CoCode,
                'club_name' => (string) $r->CoName, 'licence' => bk_account_enid($k) ? '' : $k,
                'online' => false, 'reg' => 0.0, 'rows' => array());
        }
        $calc = bk_price_calc($cfg->BcFee, $pricing, $r->EnDivision, $r->EnClass, intval($r->QuSession),
            bk_prov_tier($pricing, $r->CoCode), count($out[$k]['rows']) + 1);
        $online = $r->BrEnId !== null;
        $out[$k]['rows'][] = array('enid' => intval($r->EnId), 'session' => intval($r->QuSession), 'online' => $online,
            'category' => ($r->DivDescription ?: $r->EnDivision) . ' / ' . ($r->ClDescription ?: $r->EnClass),
            'price' => $calc['total'], 'lines' => $calc['lines']);
        $out[$k]['reg'] = round($out[$k]['reg'] + $calc['total'], 2);
        if ($online) $out[$k]['online'] = true;
    }
    return $out;
}

/**
 * Takes over the former "paid" ticks of a competition (BK_Payments.PyPaid) into the
 * journal, once: one payment of what was due at that moment, with its method, date and
 * author. Each tick is claimed by an UPDATE before its line is written, so that two pages
 * opened at the same time cannot both write it.
 */
function bk_ledger_migrate($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT PyLicence, PyMethod, PyPaidAt, PyBy FROM BK_Payments
        WHERE PyTournament = $tourId AND PyPaid = 1 AND PyLedger = 0");
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[] = $r;
    foreach ($rows as $r) {
        safe_w_sql("UPDATE BK_Payments SET PyLedger = 1 WHERE PyTournament = $tourId
            AND PyLicence = " . StrSafe_DB($r->PyLicence) . " AND PyLedger = 0");
        if (safe_w_affected_rows() < 1) continue;
        $due = bk_account_due($tourId, $r->PyLicence);
        if ($due['due'] <= 0) continue;
        bk_ledger_add($tourId, $r->PyLicence, 'payment', $due['due'], (string) $r->PyMethod,
            bk_date_iso($r->PyPaidAt), "Repris du suivi précédent (case « payé »)",
            (string) $r->PyBy);
    }
}

/** What an account owes, without the journal: ['reg', 'shop', 'due', 'registrations' => […]]. */
function bk_account_due($tourId, $account)
{
    $regs = bk_account_registrations($tourId, $account);
    $a = $regs[$account] ?? null;
    $shop = bk_account_enid($account) ? 0.0 : bk_shop_order_total($tourId, $account);
    $reg = $a ? $a['reg'] : 0.0;
    return array('reg' => $reg, 'shop' => $shop, 'due' => round($reg + $shop, 2), 'registrations' => $a);
}

/** Journal lines of an account, oldest first. */
function bk_ledger_moves($tourId, $account)
{
    $out = array();
    $rs = safe_r_sql("SELECT * FROM BK_Ledger WHERE BlgTournament = " . intval($tourId) . "
        AND BlgAccount = " . StrSafe_DB($account) . " ORDER BY BlgWhen, BlgId");
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * One account: what is due (registrations with their tariff detail, shop lines), the
 * journal, and the totals. ['name', 'club_code', 'club_name', 'registrations' => rows,
 * 'shop_lines', 'reg', 'shop', 'due', 'moves', 'paid', 'remaining'].
 */
function bk_account($tourId, $account)
{
    bk_schema();
    $tourId = intval($tourId);
    bk_ledger_migrate($tourId);
    $d = bk_account_due($tourId, $account);
    $moves = bk_ledger_moves($tourId, $account);
    $paid = 0.0;
    foreach ($moves as $m) $paid += (float) $m->BlgAmount;
    $a = $d['registrations'];
    return array(
        'account' => $account, 'name' => $a ? $a['name'] : '', 'club_code' => $a ? $a['club_code'] : '',
        'club_name' => $a ? $a['club_name'] : '', 'registrations' => $a ? $a['rows'] : array(),
        'shop_lines' => bk_account_enid($account) ? array() : bk_shop_order_lines($tourId, $account),
        'reg' => $d['reg'], 'shop' => $d['shop'], 'due' => $d['due'], 'moves' => $moves,
        'paid' => round($paid, 2), 'remaining' => round($d['due'] - $paid, 2),
    );
}

/**
 * Every account of a competition, for the payments page: participants (any origin), shop
 * buyers and accounts with journal lines. [account => ['account', 'licence', 'name',
 * 'club_code', 'club_name', 'count', 'online', 'reg', 'shop', 'due', 'paid', 'remaining',
 * 'moves', 'decl']]. A handful of queries whatever the size of the competition.
 */
function bk_accounts($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    bk_ledger_migrate($tourId);
    $out = array();
    $blank = array('licence' => '', 'name' => '', 'club_code' => '', 'club_name' => '', 'count' => 0,
        'online' => false, 'reg' => 0.0, 'shop' => 0.0, 'paid' => 0.0, 'moves' => 0, 'decl' => '');
    foreach (bk_account_registrations($tourId) as $k => $a) {
        $out[$k] = array_merge($blank, array('account' => $k, 'licence' => $a['licence'], 'name' => $a['name'],
            'club_code' => $a['club_code'], 'club_name' => $a['club_name'], 'count' => count($a['rows']),
            'online' => $a['online'], 'reg' => $a['reg']));
    }
    $rs = safe_r_sql("SELECT SoLicence AS k, SUM(SoQty * SiPrice) AS t FROM BK_ShopOrders
        INNER JOIN BK_ShopItems ON SiId = SoItem
        WHERE SoTournament = $tourId AND SoQty > 0 GROUP BY SoLicence");
    while ($r = safe_fetch($rs)) {
        if (!isset($out[$r->k])) $out[$r->k] = array_merge($blank, array('account' => $r->k, 'licence' => $r->k));
        $out[$r->k]['shop'] = round((float) $r->t, 2);
    }
    $rs = safe_r_sql("SELECT BlgAccount AS k, SUM(BlgAmount) AS p, COUNT(*) AS n FROM BK_Ledger
        WHERE BlgTournament = $tourId GROUP BY BlgAccount");
    while ($r = safe_fetch($rs)) {
        if (!isset($out[$r->k])) {
            $out[$r->k] = array_merge($blank, array('account' => $r->k, 'licence' => bk_account_enid($r->k) ? '' : $r->k));
        }
        $out[$r->k]['paid'] = round((float) $r->p, 2);
        $out[$r->k]['moves'] = intval($r->n);
    }
    $decl = bk_payment_map($tourId);
    foreach ($out as $k => &$a) {
        if ($a['name'] === '' && $a['licence'] !== '') {   // shop or journal only: name from the account or the federal file
            $n = safe_fetch(safe_r_sql("SELECT BaFamilyName AS f, BaName AS g, BaClubCode AS c FROM BK_Archers
                    WHERE BaLicence = " . StrSafe_DB($a['licence'])))
                ?: safe_fetch(safe_r_sql("SELECT LueFamilyName AS f, LueName AS g, LueCountry AS c FROM LookUpEntries
                    WHERE LueCode = " . StrSafe_DB($a['licence']) . " ORDER BY LueDefault DESC LIMIT 1"));
            if ($n) { $a['name'] = trim($n->f . ' ' . $n->g); $a['club_code'] = (string) $n->c; }
        }
        $a['due'] = round($a['reg'] + $a['shop'], 2);
        $a['remaining'] = round($a['due'] - $a['paid'], 2);
        $py = $decl[$k] ?? null;
        $a['decl'] = $py ? bk_payment_decl_label($py->PyDeclMethod ?? '', $py->PyDeclWhen ?? '') : '';
        $a['decl_method'] = $py ? (string) ($py->PyDeclMethod ?? '') : '';
    }
    unset($a);
    return $out;
}

/** State of an account: 'none' (nothing due nor paid), 'due', 'partial', 'settled', 'over' (to refund). */
function bk_account_state($a)
{
    if ($a['remaining'] < -0.005) return 'over';
    if ($a['remaining'] <= 0.005) return ($a['due'] > 0 || $a['paid'] > 0) ? 'settled' : 'none';
    return $a['paid'] > 0.005 ? 'partial' : 'due';
}

/** French label of an account state. */
function bk_account_state_label($state)
{
    $l = array('none' => 'Rien à payer', 'due' => 'À payer', 'partial' => 'Payé en partie',
        'settled' => 'Soldé', 'over' => 'Trop-perçu');
    return $l[$state] ?? $state;
}

/* ------------------------------------------------------------------ */
/* Journal                                                             */
/* ------------------------------------------------------------------ */

/** Amount typed by a person ("12,50", "12.5") → float, 0 when unreadable. */
function bk_money_in($v)
{
    $v = str_replace(array(' ', "\u{00A0}", '€'), '', str_replace(',', '.', trim((string) $v)));
    return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v) ? round((float) $v, 2) : 0.0;
}

/**
 * Writes a journal line. $kind 'payment' or 'refund' (amount given positive, stored with its
 * sign); $date 'YYYY-MM-DD' typed by the organiser ('' = now, local time of the competition).
 * Returns the line id, 0 when refused (unknown kind, amount out of range).
 */
function bk_ledger_add($tourId, $account, $kind, $amount, $method, $date, $label, $by, $group = 0)
{
    bk_schema();
    $tourId = intval($tourId);
    $amount = round((float) $amount, 2);
    if (!in_array($kind, array('payment', 'refund'), true) || $amount <= 0 || $amount > 999999) return 0;
    $signed = $kind === 'refund' ? -$amount : $amount;
    // Re-import anchor (lib/adopt.php): a competition handled in ianseo only has no row yet,
    // and its payments would be left behind when a newer version of it is imported.
    safe_w_sql("INSERT IGNORE INTO BK_Competitions (BcTournament, BcCode) SELECT ToId, ToCode FROM Tournament WHERE ToId = $tourId");
    $when = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])
        ? StrSafe_DB($date . ' 12:00:00')
        : bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)");
    safe_w_sql("INSERT INTO BK_Ledger SET BlgTournament = $tourId,
        BlgAccount = " . StrSafe_DB(mb_substr((string) $account, 0, 25)) . ",
        BlgKind = "    . StrSafe_DB($kind) . ",
        BlgAmount = "  . StrSafe_DB(number_format($signed, 2, '.', '')) . ",
        BlgMethod = "  . StrSafe_DB(array_key_exists($method, bk_payment_methods()) ? $method : '') . ",
        BlgLabel = "   . StrSafe_DB(mb_substr(trim((string) $label), 0, 160)) . ",
        BlgGroup = "   . intval($group) . ",
        BlgWhen = $when,
        BlgCreated = " . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)") . ",
        BlgBy = "      . StrSafe_DB(mb_substr((string) $by, 0, 64)));
    return intval(safe_w_last_id());
}

/**
 * Cancels a journal line: a counter-line of the opposite amount, both kept. Only a line
 * not cancelled yet and not itself a cancellation. Returns the new line id, or 0.
 */
function bk_ledger_cancel($tourId, $id, $by)
{
    bk_schema();
    $tourId = intval($tourId);
    $l = safe_fetch(safe_r_sql("SELECT * FROM BK_Ledger WHERE BlgId = " . intval($id) . "
        AND BlgTournament = $tourId AND BlgKind <> 'cancel' AND BlgCancelled = 0"));
    if (!$l) return 0;
    $kinds = bk_ledger_kinds();
    $d = bk_date_iso($l->BlgWhen);
    safe_w_sql("INSERT INTO BK_Ledger SET BlgTournament = $tourId,
        BlgAccount = " . StrSafe_DB($l->BlgAccount) . ", BlgKind = 'cancel',
        BlgAmount = " . StrSafe_DB(number_format(-(float) $l->BlgAmount, 2, '.', '')) . ",
        BlgMethod = " . StrSafe_DB($l->BlgMethod) . ",
        BlgLabel = " . StrSafe_DB('Annulation : ' . mb_strtolower($kinds[$l->BlgKind] ?? $l->BlgKind)
            . ($d !== '' ? ' du ' . bk_date_dmy($d) : '')) . ",
        BlgCancels = " . intval($l->BlgId) . ",
        BlgWhen = " . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)") . ",
        BlgCreated = " . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)") . ",
        BlgBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)));
    $new = intval(safe_w_last_id());
    safe_w_sql("UPDATE BK_Ledger SET BlgCancelled = $new WHERE BlgId = " . intval($l->BlgId));
    return $new;
}

/** 'YYYY-MM-DD…' → 'YYYY-MM-DD', '' when not a date. */
function bk_date_iso($d)
{
    return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $d, $m) && $m[1] !== '0000-00-00' ? $m[1] : '';
}

/** 'YYYY-MM-DD…' → 'DD/MM/YYYY' (no dependency on lib/ui.php, loaded by public pages only). */
function bk_date_dmy($d)
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $d, $m) ? "$m[3]/$m[2]/$m[1]" : '';
}

/**
 * What is left to pay on an account (bk_accounts row), split into registrations and shop.
 * Payments are not earmarked: what was paid is counted against the registrations first.
 */
function bk_account_left_parts($a)
{
    $left = max(0.0, $a['remaining']);
    $reg = min($left, max(0.0, round($a['reg'] - max(0.0, $a['paid']), 2)));
    return array('reg' => round($reg, 2), 'shop' => round($left - $reg, 2));
}

/**
 * One payment for a club, of the amount the club pays: $amounts = [account => amount], each
 * account of that club getting a line of its amount (the whole remaining, the registrations
 * only, or anything the organiser typed), all tied by one group number. Accounts of another
 * club and amounts that are not positive are left out. Returns ['count', 'total'].
 */
function bk_ledger_pay_club($tourId, $clubCode, $amounts, $method, $date, $note, $by)
{
    $tourId = intval($tourId);
    $accounts = bk_accounts($tourId);
    $todo = array();
    foreach ((array) $amounts as $k => $v) {
        $v = round((float) $v, 2);
        if ($v > 0 && isset($accounts[$k]) && $accounts[$k]['club_code'] === (string) $clubCode) $todo[$k] = $v;
    }
    if (!$todo) return array('count' => 0, 'total' => 0.0);
    $g = safe_fetch(safe_r_sql("SELECT COALESCE(MAX(BlgGroup), 0) + 1 AS g FROM BK_Ledger"));
    $group = $g ? intval($g->g) : 1;
    $label = 'Règlement du club ' . $clubCode . (trim((string) $note) !== '' ? ' — ' . trim((string) $note) : '');
    $total = 0.0; $count = 0;
    foreach ($todo as $k => $v) {
        if (!bk_ledger_add($tourId, $k, 'payment', $v, $method, $date, $label, $by, $group)) continue;
        $total += $v; $count++;
    }
    return array('count' => $count, 'total' => round($total, 2));
}

/**
 * Records a refund the organiser owes (BK_Refunds): a registration already paid was
 * removed by the server. No name and no licence: club and amount only.
 */
function bk_refund_add($tourId, $clubCode, $clubName, $amount, $method, $reason = 'ANONYMISE')
{
    bk_schema();
    $tourId = intval($tourId);
    safe_w_sql("INSERT INTO BK_Refunds SET BfTournament = $tourId,
        BfClubCode = " . StrSafe_DB(mb_substr((string) $clubCode, 0, 16)) . ",
        BfClubName = " . StrSafe_DB(mb_substr((string) $clubName, 0, 80)) . ",
        BfAmount = "   . StrSafe_DB(number_format((float) $amount, 2, '.', '')) . ",
        BfMethod = "   . StrSafe_DB(array_key_exists($method, bk_payment_methods()) ? $method : '') . ",
        BfReason = "   . StrSafe_DB($reason) . ",
        BfCreated = "  . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)"));
}

/** Refunds of a competition, the ones to make first. */
function bk_refunds_of($tourId)
{
    bk_schema();
    $out = array();
    $rs = safe_r_sql("SELECT * FROM BK_Refunds WHERE BfTournament = " . intval($tourId) . " ORDER BY BfDone, BfId");
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * The organiser has refunded. The payment lines of the removed person stayed in the journal
 * under the anonymous code: the refund line goes there too, for what that account still
 * holds — nothing more if the organiser already recorded it from the account itself.
 */
function bk_refund_done($tourId, $id, $by)
{
    bk_schema();
    $tourId = intval($tourId);
    safe_w_sql("UPDATE BK_Refunds SET BfDone = 1, BfDoneBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)) . ",
        BfDoneAt = " . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)") . "
        WHERE BfId = " . intval($id) . " AND BfTournament = $tourId AND BfDone = 0");
    if (safe_w_affected_rows() < 1) return;
    $f = safe_fetch(safe_r_sql("SELECT * FROM BK_Refunds WHERE BfId = " . intval($id)));
    if (!$f || $f->BfReason !== 'ANONYMISE') return;
    $anon = defined('AUT_ANON_CODE') ? AUT_ANON_CODE : 'ANON';
    $a = bk_account($tourId, $anon);
    $amount = min((float) $f->BfAmount, -$a['remaining']);
    if ($amount > 0.005) {
        bk_ledger_add($tourId, $anon, 'refund', $amount, (string) $f->BfMethod, '',
            'Remboursement après anonymisation — club ' . trim($f->BfClubCode . ' ' . $f->BfClubName), $by);
    }
}

/**
 * What an archer owes on a competition and where they stand: ['reg', 'shop', 'total' (due),
 * 'count', 'paid', 'remaining']. Registrations of any origin, priced by the tariff, plus the
 * shop; minus the journal (bk_account).
 */
function bk_due_total($tourId, $licence)
{
    $a = bk_account($tourId, $licence);
    return array('reg' => $a['reg'], 'shop' => $a['shop'], 'total' => $a['due'], 'count' => count($a['registrations']),
        'paid' => $a['paid'], 'remaining' => $a['remaining']);
}

/**
 * Does the organiser record payments here (at least one journal line on the competition)?
 * Without it, nothing is known of what was paid: a competition that is over is then not
 * shown as owing anything — paid on site and never recorded is the common case.
 */
function bk_ledger_tracked($tourId)
{
    return (bool) safe_fetch(safe_r_sql("SELECT BlgId FROM BK_Ledger WHERE BlgTournament = " . intval($tourId) . " LIMIT 1"));
}

/**
 * Competitions where an archer has an account worth showing: something priced on a competition
 * that uses the payments (open on this server, or closed with the payments ticked), a shop
 * order or a journal line. Newest first: ['ToId', 'ToName', 'ToWhere', 'ToWhenFrom', 'ToWhenTo',
 * 'past', 'tracked', 'due', 'paid', 'remaining', 'payinfo'].
 */
function bk_archer_accounts($licence)
{
    bk_schema();
    $lic = trim((string) $licence);
    if ($lic === '' || bk_account_enid($lic)) return array();
    $l = StrSafe_DB($lic);
    $rs = safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo FROM Tournament
        WHERE ToId IN (
              SELECT EnTournament FROM Entries INNER JOIN BK_Competitions ON BcTournament = EnTournament
               WHERE EnCode = $l AND EnAthlete = 1 AND (BcFee > 0 OR BcPricing IS NOT NULL)
                 AND (BcPublishLevel >= 2 OR BcPayments = 1)
              UNION SELECT SoTournament FROM BK_ShopOrders WHERE SoLicence = $l AND SoQty > 0
              UNION SELECT BlgTournament FROM BK_Ledger WHERE BlgAccount = $l)
        ORDER BY ToWhenFrom DESC, ToId DESC");
    $tours = array();
    while ($r = safe_fetch($rs)) $tours[] = $r;
    $out = array();
    foreach ($tours as $t) {
        $a = bk_account(intval($t->ToId), $lic);
        if ($a['due'] <= 0 && !$a['moves']) continue;
        $out[] = array('ToId' => intval($t->ToId), 'ToName' => $t->ToName, 'ToWhere' => $t->ToWhere,
            'ToWhenFrom' => $t->ToWhenFrom, 'ToWhenTo' => $t->ToWhenTo, 'past' => bk_is_finished($t->ToWhenTo),
            'tracked' => bk_ledger_tracked(intval($t->ToId)),
            'due' => $a['due'], 'paid' => $a['paid'], 'remaining' => $a['remaining'],
            'payinfo' => bk_payinfo_get(bk_comp_config(intval($t->ToId))));
    }
    return $out;
}
