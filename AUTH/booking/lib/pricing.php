<?php
/**
 * lib/pricing.php — detailed tariff (calculation engine).
 *
 * The setup lives as JSON in BK_Competitions.BcPricing (empty = single fee BcFee, the
 * original behaviour). The price of a registration is made of:
 *
 *   price = max(0,  BASE                       (base fee, or the fixed price of the category
 *                                               when a rule matches)
 *                 + Δ departure                (adjustment of the departure chosen)
 *                 + Δ origin                   (local department / region, the best only)
 *                 + Δ rank )                   (2nd, 3rd… registration of the person)
 *
 * The server is the authority (receipt). The live display on the archer side reproduces the
 * same formula in JavaScript.
 */

if (defined('BK_PRICING_LOADED')) return;
define('BK_PRICING_LOADED', true);

require_once __DIR__ . '/lang.php';

/** Normalised structure, every field present, from a JSON (or nothing). */
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
    // bytes: department and league codes are ASCII (reduced to letters and digits).
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

/** Normalised tariff setup of a competition (object of bk_comp_config). */
function bk_pricing_get($cfg)
{
    return bk_pricing_norm($cfg->BcPricing ?? '');
}

/** True when at least one detailed dimension is set up (otherwise a single fee). */
function bk_pricing_is_advanced($p)
{
    return $p['categories'] || $p['departures']
        || $p['prov']['dept'] != 0.0 || $p['prov']['region'] != 0.0 || $p['rank'];
}

/**
 * Origin tier of a club (agreement LLDDCCC) against the setup: 'dept' when the department
 * (positions 3-4) matches, else 'region' when the league (positions 1-2) matches, else '' —
 * the most local wins.
 */
function bk_prov_tier($p, $clubCode)
{
    // bytes, in this function: an agreement number is ASCII.
    $club = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $clubCode));
    if (strlen($club) < 2) return '';
    $dept   = $p['prov']['deptCode'];
    $region = $p['prov']['regionCode'];
    if ($dept !== '' && $p['prov']['dept'] != 0.0 && strtoupper($dept) === substr($club, 2, 2)) return 'dept';
    if ($region !== '' && $p['prov']['region'] != 0.0 && strtoupper($region) === substr($club, 0, 2)) return 'region';
    return '';
}

/** Labels of the tariff lines, in the visitor's language (also given to the page's script). */
function bk_price_labels()
{
    return array('base' => bk_t('PriceBase'), 'cat' => bk_t('PriceCat'), 'dept' => bk_t('PriceDept'),
        'region' => bk_t('PriceRegion'));
}

/**
 * Price of a registration and its detail, line by line.
 *
 * @return array ['total' => float, 'lines' => [['label' => …, 'amount' => float], …]]
 */
function bk_price_calc($base, $p, $division, $class, $sessionOrder, $tier, $rank)
{
    $lines = array();

    // Base, replaced by the fixed price of a category when a rule matches (the first one).
    $price = round((float) $base, 2);
    $label = bk_t('PriceBase');
    foreach ($p['categories'] as $rule) {
        $okDiv = !$rule['div'] || in_array((string) $division, $rule['div'], true);
        $okCls = !$rule['cls'] || in_array((string) $class, $rule['cls'], true);
        if ($okDiv && $okCls) {
            $price = round((float) $rule['price'], 2);
            $label = $rule['label'] !== '' ? bk_t('PriceCatNamed', $rule['label']) : bk_t('PriceCat');
            break;
        }
    }
    $lines[] = array('label' => $label, 'amount' => $price);

    // Departure.
    $ord = (string) intval($sessionOrder);
    if (isset($p['departures'][$ord])) {
        $lines[] = array('label' => bk_t('DepCap', $ord), 'amount' => (float) $p['departures'][$ord]);
        $price += (float) $p['departures'][$ord];
    }

    // Origin (tier already resolved by the caller).
    if ($tier === 'dept' && $p['prov']['dept'] != 0.0) {
        $lines[] = array('label' => bk_t('PriceDept'), 'amount' => (float) $p['prov']['dept']);
        $price += (float) $p['prov']['dept'];
    } elseif ($tier === 'region' && $p['prov']['region'] != 0.0) {
        $lines[] = array('label' => bk_t('PriceRegion'), 'amount' => (float) $p['prov']['region']);
        $price += (float) $p['prov']['region'];
    }

    // Rank (decreasing rate): the highest threshold ≤ rank.
    $bestTh = 0; $rd = 0.0;
    foreach ($p['rank'] as $th => $delta) {
        $th = (int) $th;
        if ($rank >= $th && $th > $bestTh) { $bestTh = $th; $rd = (float) $delta; }
    }
    if ($bestTh > 0 && $rd != 0.0) {
        $lines[] = array('label' => bk_t('PriceRank', intval($rank)), 'amount' => $rd);
        $price += $rd;
    }

    return array('total' => max(0.0, round($price, 2)), 'lines' => $lines);
}

/** Price alone (no detail). */
function bk_price_of($base, $p, $division, $class, $sessionOrder, $tier, $rank)
{
    $r = bk_price_calc($base, $p, $division, $class, $sessionOrder, $tier, $rank);
    return $r['total'];
}

/**
 * Rank (order of creation) of each online registration of an archer on a competition.
 * Returns [BrEnId => rank], rank starting at 1.
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

/** Agreement number of the organising committee (Tournament.ToCommitee). */
function bk_org_agrement($tourId)
{
    $rs = safe_r_sql("SELECT ToCommitee FROM Tournament WHERE ToId = " . intval($tourId));
    $r = safe_fetch($rs);
    return $r ? (string) $r->ToCommitee : '';
}

/**
 * Lowest price that can be shown ("from"): the smallest base (base or smallest category)
 * with the best negative adjustments. Used by the calendar and the detail page when the
 * tariff is detailed.
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
