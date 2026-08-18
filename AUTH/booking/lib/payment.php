<?php
/**
 * lib/payment.php — suivi de paiement (organisateur) + montant dû par archer.
 *
 * L'organisateur coche « payé » et le moyen sur la page « Sommes dues ». Tant que
 * PyPaid=0, le reçu n'est pas délivré côté compétiteur (montant dû affiché comme
 * non encaissé). Pas de facture : les éléments légaux (SIRET, adresse, TVA, n°
 * séquentiel) ne sont pas réunis — seul un reçu est produit.
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

/** L'archer a-t-il payé sur cette compétition ? */
function bk_payment_is_paid($tourId, $licence)
{
    $p = bk_payment_get($tourId, $licence);
    return $p && intval($p->PyPaid) === 1;
}

/** Statuts de paiement pour tous les archers d'une compétition : [licence => row]. */
function bk_payment_map($tourId)
{
    bk_schema();
    $rs = safe_r_sql("SELECT * FROM BK_Payments WHERE PyTournament = " . intval($tourId));
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->PyLicence] = $r;
    return $out;
}

/** Enregistre le statut de paiement (upsert). Conserve la date d'encaissement d'origine. */
function bk_payment_set($tourId, $licence, $paid, $method, $by)
{
    bk_schema();
    $tourId = intval($tourId);
    $paid = $paid ? 1 : 0;
    $method = array_key_exists($method, bk_payment_methods()) ? $method : '';
    $lic = StrSafe_DB($licence);
    $m = StrSafe_DB($method);
    $b = StrSafe_DB(substr((string) $by, 0, 64));

    $ex = bk_payment_get($tourId, $licence);
    $paidAt = 'NULL';
    if ($paid) {
        $paidAt = ($ex && intval($ex->PyPaid) === 1 && $ex->PyPaidAt) ? StrSafe_DB($ex->PyPaidAt) : 'NOW()';
    }
    safe_w_sql("INSERT INTO BK_Payments (PyTournament, PyLicence, PyPaid, PyMethod, PyPaidAt, PyBy)
        VALUES ($tourId, $lic, $paid, $m, $paidAt, $b)
        ON DUPLICATE KEY UPDATE PyPaid = $paid, PyMethod = $m, PyPaidAt = $paidAt, PyBy = $b");
}

/**
 * Montant dû par un archer sur une compétition : inscriptions (moteur de
 * tarification, comme le reçu) + boutique. Source unique utilisée par la page
 * « Sommes dues », l'espace compétiteur et la garde du reçu.
 *
 * @return array ['reg'=>float, 'shop'=>float, 'total'=>float, 'count'=>int]
 */
function bk_due_total($tourId, $licence)
{
    bk_schema();
    $tourId = intval($tourId);
    $cfg = bk_comp_config($tourId);
    $pricing = bk_pricing_norm($cfg->BcPricing ?? '');
    $base = $cfg->BcFee;
    $rankMap = bk_rank_map($tourId, $licence);

    $rs = safe_r_sql("SELECT r.BrEnId, e.EnDivision, e.EnClass, c.CoCode, q.QuSession
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        LEFT  JOIN Countries c      ON c.CoId = e.EnCountry
        LEFT  JOIN Qualifications q ON q.QuId = e.EnId
        WHERE r.BrTournament = $tourId AND r.BrLicence = " . StrSafe_DB($licence));
    $reg = 0.0; $count = 0;
    while ($r = safe_fetch($rs)) {
        $tier = bk_prov_tier($pricing, $r->CoCode);
        $rank = $rankMap[intval($r->BrEnId)] ?? 1;
        $reg += bk_price_of($base, $pricing, $r->EnDivision, $r->EnClass, $r->QuSession, $tier, $rank);
        $count++;
    }
    $shop = bk_shop_order_total($tourId, $licence);
    return array('reg' => $reg, 'shop' => $shop, 'total' => round($reg + $shop, 2), 'count' => $count);
}
