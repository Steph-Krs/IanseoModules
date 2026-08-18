<?php
/**
 * lib/pricing.php — tarification avancée (moteur de calcul).
 *
 * La configuration vit en JSON dans BK_Competitions.BcPricing (vide = tarif plat
 * BcFee, comportement d'origine). Le prix d'une inscription se compose ainsi :
 *
 *   prix = max(0,  BASE                       (tarif de base, ou prix fixe de la
 *                                               catégorie si une règle correspond)
 *                + Δ départ                    (ajustement propre au départ choisi)
 *                + Δ provenance                (local départemental / régional,
 *                                               le meilleur seul)
 *                + Δ rang )                    (2ᵉ inscription, 3ᵉ+… de la personne)
 *
 * Le serveur est l'autorité (reçu). L'affichage vivant côté archer reproduit la
 * même formule en JavaScript à partir de bk_pricing_js_config().
 */

if (defined('BK_PRICING_LOADED')) return;
define('BK_PRICING_LOADED', true);

/** Structure normalisée, tous champs présents, à partir d'un JSON éventuel. */
function bk_pricing_norm($raw)
{
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) $raw = array();

    $out = array('categories' => array(), 'departures' => array(),
                 'prov' => array('deptCode' => '', 'regionCode' => '', 'dept' => 0.0, 'region' => 0.0),
                 'rank' => array());

    foreach (($raw['categories'] ?? array()) as $r) {
        if (!is_array($r)) continue;
        $out['categories'][] = array(
            'label' => trim((string) ($r['label'] ?? '')),
            'div'   => array_values(array_filter(array_map('strval', (array) ($r['div'] ?? array())), 'strlen')),
            'cls'   => array_values(array_filter(array_map('strval', (array) ($r['cls'] ?? array())), 'strlen')),
            'price' => round((float) ($r['price'] ?? 0), 2),
        );
    }
    foreach (($raw['departures'] ?? array()) as $ord => $delta) {
        $ord = (int) $ord;
        if ($ord > 0 && (float) $delta != 0.0) $out['departures'][(string) $ord] = round((float) $delta, 2);
    }
    $prov = $raw['prov'] ?? array();
    $out['prov']['deptCode']   = preg_replace('/[^0-9A-Za-z]/', '', substr((string) ($prov['deptCode'] ?? ''), 0, 2));
    $out['prov']['regionCode'] = preg_replace('/[^0-9A-Za-z]/', '', substr((string) ($prov['regionCode'] ?? ''), 0, 2));
    $out['prov']['dept']       = round((float) ($prov['dept'] ?? 0), 2);
    $out['prov']['region']     = round((float) ($prov['region'] ?? 0), 2);
    foreach (($raw['rank'] ?? array()) as $th => $delta) {
        $th = (int) $th;
        if ($th >= 2 && (float) $delta != 0.0) $out['rank'][(string) $th] = round((float) $delta, 2);
    }
    return $out;
}

/** Config tarifaire normalisée d'une compétition (objet de bk_comp_config). */
function bk_pricing_get($cfg)
{
    return bk_pricing_norm($cfg->BcPricing ?? '');
}

/** Vrai si au moins une dimension avancée est configurée (sinon tarif plat). */
function bk_pricing_is_advanced($p)
{
    return $p['categories'] || $p['departures']
        || $p['prov']['dept'] != 0.0 || $p['prov']['region'] != 0.0 || $p['rank'];
}

/**
 * Palier de provenance d'un club (agrément LLDDCCC) face à la config :
 * 'dept' si le département (positions 3-4) correspond, sinon 'region' si la ligue
 * (positions 1-2) correspond, sinon '' — le plus local l'emporte.
 */
function bk_prov_tier($p, $clubCode)
{
    $club = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $clubCode));
    if (strlen($club) < 2) return '';
    $dept   = $p['prov']['deptCode'];
    $region = $p['prov']['regionCode'];
    if ($dept !== '' && $p['prov']['dept'] != 0.0 && strtoupper($dept) === substr($club, 2, 2)) return 'dept';
    if ($region !== '' && $p['prov']['region'] != 0.0 && strtoupper($region) === substr($club, 0, 2)) return 'region';
    return '';
}

/**
 * Calcule le prix d'une inscription et le détail ligne à ligne.
 *
 * @return array ['total'=>float, 'lines'=>[['label'=>..,'amount'=>float], ...]]
 */
function bk_price_calc($base, $p, $division, $class, $sessionOrder, $tier, $rank)
{
    $lines = array();

    // Base, éventuellement remplacée par le prix fixe d'une catégorie (1re règle).
    $price = round((float) $base, 2);
    $label = 'Tarif de base';
    foreach ($p['categories'] as $rule) {
        $okDiv = !$rule['div'] || in_array((string) $division, $rule['div'], true);
        $okCls = !$rule['cls'] || in_array((string) $class, $rule['cls'], true);
        if ($okDiv && $okCls) {
            $price = round((float) $rule['price'], 2);
            $label = 'Tarif' . ($rule['label'] !== '' ? ' ' . $rule['label'] : ' catégorie');
            break;
        }
    }
    $lines[] = array('label' => $label, 'amount' => $price);

    // Départ.
    $ord = (string) intval($sessionOrder);
    if (isset($p['departures'][$ord])) {
        $lines[] = array('label' => 'Départ ' . $ord, 'amount' => (float) $p['departures'][$ord]);
        $price += (float) $p['departures'][$ord];
    }

    // Provenance (palier déjà résolu par l'appelant).
    if ($tier === 'dept' && $p['prov']['dept'] != 0.0) {
        $lines[] = array('label' => 'Tarif départemental', 'amount' => (float) $p['prov']['dept']);
        $price += (float) $p['prov']['dept'];
    } elseif ($tier === 'region' && $p['prov']['region'] != 0.0) {
        $lines[] = array('label' => 'Tarif régional', 'amount' => (float) $p['prov']['region']);
        $price += (float) $p['prov']['region'];
    }

    // Rang (dégressif) : le plus grand seuil ≤ rang.
    $bestTh = 0; $rd = 0.0;
    foreach ($p['rank'] as $th => $delta) {
        $th = (int) $th;
        if ($rank >= $th && $th > $bestTh) { $bestTh = $th; $rd = (float) $delta; }
    }
    if ($bestTh > 0 && $rd != 0.0) {
        $lines[] = array('label' => intval($rank) . 'ᵉ inscription', 'amount' => $rd);
        $price += $rd;
    }

    return array('total' => max(0.0, round($price, 2)), 'lines' => $lines);
}

/** Prix seul (sans détail). Raccourci pour le reçu. */
function bk_price_of($base, $p, $division, $class, $sessionOrder, $tier, $rank)
{
    $r = bk_price_calc($base, $p, $division, $class, $sessionOrder, $tier, $rank);
    return $r['total'];
}

/**
 * Rang (ordre chronologique) de chaque inscription d'un archer sur une
 * compétition. Retourne [BrEnId => rang], rang commençant à 1.
 */
function bk_rank_map($tourId, $licence)
{
    $rs = safe_r_sql("SELECT BrEnId FROM BK_Registrations
        WHERE BrTournament = " . intval($tourId) . "
          AND BrLicence = " . StrSafe_DB($licence) . "
        ORDER BY BrCreated, BrId");
    $map = array(); $n = 0;
    while ($r = safe_fetch($rs)) $map[intval($r->BrEnId)] = ++$n;
    return $map;
}

/** Agrément du comité organisateur (Tournament.ToCommitee). */
function bk_org_agrement($tourId)
{
    $rs = safe_r_sql("SELECT ToCommitee FROM Tournament WHERE ToId = " . intval($tourId));
    $r = safe_fetch($rs);
    return $r ? (string) $r->ToCommitee : '';
}

/**
 * Prix plancher affichable (« à partir de ») : la plus petite base possible
 * (base ou plus petite catégorie) avec les meilleurs ajustements négatifs.
 * Sert au calendrier / détail quand la tarification est avancée.
 */
function bk_price_min($base, $p)
{
    $b = round((float) $base, 2);
    foreach ($p['categories'] as $rule) $b = min($b, round((float) $rule['price'], 2));
    $best = 0.0;
    foreach ($p['departures'] as $d) $best = min($best, (float) $d);
    $best += min(0.0, (float) $p['prov']['dept'], (float) $p['prov']['region']);
    $rankBest = 0.0;
    foreach ($p['rank'] as $d) $rankBest = min($rankBest, (float) $d);
    $best += $rankBest;
    return max(0.0, round($b + $best, 2));
}
