<?php
/**
 * lib/placement.php — target assignment of a departure (pure: no database access).
 *
 * Rules (REGLES_PLACEMENT.md):
 *  - ORDER: the archers are sorted on the organiser's criteria — by default distance (shortest
 *    first), face (largest first, a single face before a reduced or triple one of the same size),
 *    bow (barebow, recurve, compound, then the other divisions), age (youngest first), sex (women
 *    first) — and the clubs alternate within one category;
 *  - the targets are taken in the order of the field, from target 1 or from the last one (setting
 *    of the departure);
 *  - the sorted list is CUT into targets as evenly as possible (dynamic programming), every target
 *    physically possible: one distance series, indoors the halves of the buttress (left A C,
 *    right B D, one family of faces per half), outdoors the sharing rules of
 *    lib/cohabitation.php, no 4 archers on the target of a wheelchair archer;
 *  - at least `minTargets` targets with 2 archers or more while the archers allow it (spread
 *    first, then complete);
 *  - club quota per target and no two archers of one club alone on a target: swaps with the
 *    nearest archer in the order, the one that changes the least important criterion first, so
 *    that categories are mixed as little as possible;
 *  - field settings of each target: distance range and the exact faces (TfId) it accepts;
 *  - archers already placed never move.
 */

if (defined('BK_PLACEMENT_LOADED')) return;
define('BK_PLACEMENT_LOADED', true);

require_once __DIR__ . '/cohabitation.php';

/** Target types of ianseo (Targets.TarId) of a reduced or triple face: indoor 6-10, outdoor compound. */
const BK_REDUCED_TYPES = array(2, 4, 9, 10, 32);

/** Criteria of the order, default order and direction (REGLES_PLACEMENT.md). */
const BK_PLACE_CRITERIA = array('dist', 'face', 'div', 'age', 'sex');

/** Default order of the bows; the other divisions follow in the order of ianseo. */
const BK_PLACE_BOWS = array('BB', 'CL', 'CO');

/* ---- Families of faces -------------------------------------------------------------------- */

/**
 * Family of a face on ONE distance — what decides whether two faces can share a buttress.
 * $t: target type of ianseo (TargetFaces.TfT<n> = Targets.TarId), $w: diameter in cm. The type
 * decides; the name is only a fallback (type 0).
 *  indoors: '80' (whole buttress), '60', '60t' (60 triple), '40', 'tri' (40 triple), '?' unknown;
 *  outdoors: 'diameter|full' or 'diameter|reduit' (lib/cohabitation.php);
 *  other disciplines: '*' (no rule on faces).
 */
function bk_face_family($disc, $name, $t, $w)
{
    $t = intval($t);
    $w = intval($w);
    if ($disc === 'salle') {
        $triple = null;
        if ($t === 2 || $t === 4)      $triple = true;
        elseif ($t > 0)                $triple = false;
        else {
            $c = bk_face_class($name);
            if ($c['type'] === 'tri') $triple = true;
            elseif ($c['dia'] > 0)    $triple = false;
            $w = $c['dia'];
        }
        if ($triple === null || $w <= 0) return '?';
        if ($triple) return $w >= 60 ? '60t' : 'tri';
        if ($w >= 80) return '80';
        if ($w >= 60) return '60';
        return '40';
    }
    if ($disc === 'ext') {
        $c = bk_face_class($name);
        if ($t > 0 && $w > 0) {
            return $w . '|' . (in_array($t, BK_REDUCED_TYPES, true) || $c['type'] === 'reduit' ? 'reduit' : 'full');
        }
        if (trim((string) $name) === '') return '?';
        return bk_face_key($c);
    }
    return '*';
}

/**
 * Family at each distance. $tw = [[TfT1, TfW1], [TfT2, TfW2], …]; a distance without a type (0)
 * takes the family of the previous one.
 */
function bk_face_families($disc, $name, $tw, $nDist)
{
    $out = array();
    $nDist = max(1, intval($nDist));
    for ($i = 0; $i < $nDist; $i++) {
        $t = intval($tw[$i][0] ?? 0);
        $w = intval($tw[$i][1] ?? 0);
        if ($i > 0 && $t === 0) { $out[] = $out[$i - 1]; continue; }
        $out[] = bk_face_family($disc, $name, $t, $w);
    }
    return $out;
}

/** Size of a family for the order: [diameter, 1 if reduced or triple]. */
function bk_fam_size($fam)
{
    switch ((string) $fam) {
        case '80':  return array(80, 0);
        case '60':  return array(60, 0);
        case '60t': return array(60, 1);
        case '40':  return array(40, 0);
        case 'tri': return array(40, 1);
    }
    $p = explode('|', (string) $fam, 2);
    return array(intval($p[0]), in_array($p[1] ?? '', array('reduit', 'tri'), true) ? 1 : 0);
}

/** Letters of a rhythm, and the two halves of the buttress. */
function bk_rhythm_letters($rhythm)
{
    $l = array();
    for ($i = 0; $i < max(1, intval($rhythm)); $i++) $l[] = chr(65 + $i);
    return $l;
}

function bk_plan_halves($rhythm)
{
    $l = bk_rhythm_letters(min(4, intval($rhythm)));
    return array(array_values(array_intersect($l, array('A', 'C'))), array_values(array_intersect($l, array('B', 'D'))));
}

/** Does the buttress model of halves apply? */
function bk_plan_halves_model($disc, $rhythm)
{
    return $disc === 'salle' && intval($rhythm) <= 4;
}

/**
 * Is a target possible on ONE distance? $byLetter: letter → family. Returns
 * ['ok' => bool, 'mixed40tri' => bool].
 */
function bk_target_layout($disc, $rhythm, $byLetter)
{
    $rhythm = max(1, intval($rhythm));
    $letters = bk_rhythm_letters($rhythm);
    foreach (array_keys($byLetter) as $l) {
        // bytes: target letters are ASCII
        if (!in_array(strtoupper((string) $l), $letters, true)) return array('ok' => false, 'mixed40tri' => false);
    }
    $fams = array_values($byLetter);
    if (!$fams) return array('ok' => true, 'mixed40tri' => false);
    if (count($fams) > $rhythm) return array('ok' => false, 'mixed40tri' => false);

    if ($disc === 'salle') {
        if (in_array('?', $fams, true)) return array('ok' => count($fams) === 1, 'mixed40tri' => false);
        if (in_array('80', $fams, true)) return array('ok' => count(array_unique($fams)) === 1, 'mixed40tri' => false);
        if ($rhythm > 4) return array('ok' => count(array_unique($fams)) === 1, 'mixed40tri' => false);
        foreach (bk_plan_halves($rhythm) as $half) {
            $in = array();
            // bytes: target letters are ASCII
            foreach ($byLetter as $l => $f) if (in_array(strtoupper((string) $l), $half, true)) $in[$f] = true;
            if (count($in) > 1) return array('ok' => false, 'mixed40tri' => false);
        }
        return array('ok' => true, 'mixed40tri' => in_array('40', $fams, true) && in_array('tri', $fams, true));
    }

    // Outdoors and the others: the faces sharing rules of lib/cohabitation.php.
    if (in_array('?', $fams, true)) return array('ok' => count($fams) === 1, 'mixed40tri' => false);
    $classes = array();
    foreach ($fams as $f) {
        $p = explode('|', (string) $f, 2);
        $classes[] = array('dia' => intval($p[0]), 'type' => (string) ($p[1] ?? ''));
    }
    return array('ok' => bk_cohabit_ok($classes, $disc, $rhythm), 'mixed40tri' => false);
}

/* ---- Context ------------------------------------------------------------------------------ */

/**
 * Normalised input. $in: disc, rhythm, targets (in the order of the field), reverse (from the last
 * target), maxPerClub, minTargets, caps [target => ['min', 'max', 'faces' => TfId[]]], order
 * [[criterion, 1|-1]…], bows [division => rank], fixed (archers already placed: + 't', 'l'),
 * archers (to place). An archer: id, dist (metres per series), fam (family, or one per distance),
 * face (TfId), club, wheelchair, with (id), letter, div, cls, age (ClAgeFrom, null = no age
 * range), sex (ClSex: 1 woman, 0 man, -1 mixed), fsize (optional: [diameter, reduced] per distance).
 */
function bk_plan_ctx($in)
{
    $disc   = (string) ($in['disc'] ?? 'ext');
    $rhythm = max(1, intval($in['rhythm'] ?? 4));
    $targets = array_values(array_map('intval', $in['targets'] ?? array()));
    if (!empty($in['reverse'])) $targets = array_reverse($targets);
    $ctx = array(
        'disc' => $disc, 'rhythm' => $rhythm, 'letters' => bk_rhythm_letters($rhythm),
        'halves' => bk_plan_halves_model($disc, $rhythm) ? bk_plan_halves($rhythm) : null,
        'max' => max(1, intval($in['maxPerClub'] ?? 99)),
        'minTargets' => max(0, intval($in['minTargets'] ?? 0)),
        'caps' => $in['caps'] ?? array(),
        'targets' => $targets,
        'crit' => bk_plan_criteria($in['order'] ?? null),
        'bows' => (array) ($in['bows'] ?? array()),
        'archers' => array(), 'fixed' => array(), 'cluster' => array(),
    );
    foreach (($in['fixed'] ?? array()) as $f) {
        $f = bk_plan_norm($f);
        // bytes: target letters are ASCII
        $f['l'] = strtoupper((string) $f['l']);
        $f['t'] = intval($f['t']);
        $ctx['fixed'][$f['id']] = $f;
    }
    foreach (($in['archers'] ?? array()) as $a) {
        $a = bk_plan_norm($a);
        $a['key'] = bk_plan_key($ctx, $a);
        $ctx['archers'][$a['id']] = $a;
    }
    $fixedTargets = array();
    foreach ($ctx['fixed'] as $f) $fixedTargets[$f['t']] = true;
    $ctx['free'] = array_values(array_filter($targets, function ($t) use ($fixedTargets) { return !isset($fixedTargets[$t]); }));
    return $ctx;
}

/** One archer, normalised. */
function bk_plan_norm($a)
{
    $a['id']   = intval($a['id']);
    $a['dist'] = array_values(array_map('intval', (array) ($a['dist'] ?? array())));
    $a['dk']   = implode('-', $a['dist']);
    $a['fams'] = is_array($a['fam'] ?? null) ? array_values($a['fam'])
               : array_fill(0, max(1, count($a['dist'])), (string) ($a['fam'] ?? '?'));
    $a['fk']   = implode('/', $a['fams']);
    $a['fsize'] = isset($a['fsize']) ? array_values($a['fsize']) : array_map('bk_fam_size', $a['fams']);
    $a['club'] = (string) ($a['club'] ?? '') !== '' ? (string) $a['club'] : '?';
    $a['face'] = intval($a['face'] ?? 0);
    $a['wheel'] = !empty($a['wheelchair']);
    // bytes: target letters are ASCII
    $a['letter'] = strtoupper(trim((string) ($a['letter'] ?? '')));
    $a['with'] = isset($a['with']) && $a['with'] !== null && $a['with'] !== '' ? intval($a['with']) : null;
    $a['div']  = (string) ($a['div'] ?? '');
    $a['cls']  = (string) ($a['cls'] ?? '');
    $a['age']  = isset($a['age']) && $a['age'] !== null ? intval($a['age']) : null;
    $a['sex']  = isset($a['sex']) && $a['sex'] !== null ? intval($a['sex']) : null;
    $a['cat']  = $a['div'] . '|' . $a['cls'] . '|' . $a['face'] . '|' . $a['fk'];
    return $a;
}

/** Criteria in the order given, each once, the missing ones appended in the default order. */
function bk_plan_criteria($order)
{
    $out = array();
    $seen = array();
    foreach ((array) $order as $c) {
        $k = is_array($c) ? (string) ($c[0] ?? '') : (string) $c;
        if (!in_array($k, BK_PLACE_CRITERIA, true) || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = array($k, is_array($c) && intval($c[1] ?? 1) < 0 ? -1 : 1);
    }
    foreach (BK_PLACE_CRITERIA as $k) if (!isset($seen[$k])) $out[] = array($k, 1);
    return $out;
}

/** Sort key of an archer: one value list per criterion, in the order of the criteria. */
function bk_plan_key($ctx, $a)
{
    $key = array();
    foreach ($ctx['crit'] as $c) {
        list($k, $d) = $c;
        switch ($k) {
            case 'dist':
                $key[] = array_map(function ($m) use ($d) { return $d * $m; }, $a['dist']);
                break;
            case 'face':
                // Largest first; at one size, the single face before the reduced or triple one.
                $v = array();
                foreach ($a['fsize'] as $s) { $v[] = -$d * intval($s[0]); $v[] = $d * intval($s[1]); }
                $key[] = $v;
                break;
            case 'div':
                $key[] = array(intval($ctx['bows'][$a['div']] ?? 1000), $a['div']);
                break;
            case 'age':
                // A class without an age range (discovery…) comes last, whatever the direction.
                $key[] = $a['age'] === null ? array(1, 0) : array(0, $d * $a['age']);
                break;
            case 'sex':
                if ($a['sex'] === 1)     $key[] = array(0, $d > 0 ? 0 : 1);
                elseif ($a['sex'] === 0) $key[] = array(0, $d > 0 ? 1 : 0);
                else                     $key[] = array(1, 0);
                break;
        }
    }
    return $key;
}

/** Compares two sort keys criterion by criterion. */
function bk_plan_cmp($ka, $kb)
{
    foreach ($ka as $i => $v) {
        $c = $v <=> ($kb[$i] ?? array());
        if ($c) return $c;
    }
    return 0;
}

/**
 * How far apart two archers are in the order: 0 same key, else the rank of the first criterion
 * that differs counted from the end (the least important criterion = 1). What a swap breaks.
 */
function bk_plan_gap($ctx, $x, $y)
{
    $kx = $ctx['archers'][$x]['key'];
    $ky = $ctx['archers'][$y]['key'];
    $n = count($kx);
    foreach ($kx as $i => $v) if (($v <=> ($ky[$i] ?? array())) !== 0) return $n - $i;
    return 0;
}

/* ---- Planner ------------------------------------------------------------------------------ */

/**
 * Plans the targets of a departure. Returns ['places' => [id => ['t', 'l']], 'unplaced' => ids,
 * 'overQuota', 'mixed40tri', 'alone', 'sameClubPairs', 'wheelchairFull', 'wishes' => [asked, kept]].
 */
function bk_plan_session($in)
{
    $ctx = bk_plan_ctx($in);
    $places = array();
    if ($ctx['archers']) {
        $seq = bk_plan_order($ctx);
        $seq = bk_plan_clusters($ctx, $seq);
        $plan = bk_plan_numbered($ctx, bk_plan_cut($ctx, $seq));
        $plan = bk_plan_swaps($ctx, $plan);
        foreach ($plan as $c) {
            $fit = bk_plan_fit($ctx, $c['ids'], true);
            if ($fit === null) continue;   // never: every target of the plan was checked
            foreach ($fit['letters'] as $id => $l) $places[$id] = array('t' => $c['t'], 'l' => $l);
        }
        $places = bk_plan_leftovers($ctx, $places);
        $places = bk_plan_repair($ctx, $places);
    }
    return bk_plan_result($ctx, $places);
}

/** The archers in the order of the criteria; within one key, the clubs in turn (the biggest first). */
function bk_plan_order($ctx)
{
    $ids = array_keys($ctx['archers']);
    usort($ids, function ($x, $y) use ($ctx) {
        return bk_plan_cmp($ctx['archers'][$x]['key'], $ctx['archers'][$y]['key']) ?: $x <=> $y;
    });
    $out = array();
    $run = array();
    foreach ($ids as $id) {
        if ($run && bk_plan_cmp($ctx['archers'][end($run)]['key'], $ctx['archers'][$id]['key']) !== 0) {
            $out = array_merge($out, bk_plan_alternate($ctx, $run));
            $run = array();
        }
        $run[] = $id;
    }
    return array_merge($out, bk_plan_alternate($ctx, $run));
}

/** One archer of each club in turn, the biggest clubs first. */
function bk_plan_alternate($ctx, $ids)
{
    $byClub = array();
    foreach ($ids as $id) $byClub[$ctx['archers'][$id]['club']][] = $id;
    if (count($byClub) < 2) return $ids;
    $rank = array_flip(array_keys($byClub));
    uksort($byClub, function ($x, $y) use ($byClub, $rank) {
        return (count($byClub[$y]) <=> count($byClub[$x])) ?: $rank[$x] <=> $rank[$y];
    });
    $out = array();
    while ($byClub) {
        foreach ($byClub as $c => $list) {
            $out[] = array_shift($byClub[$c]);
            if (!$byClub[$c]) unset($byClub[$c]);
        }
    }
    return $out;
}

/**
 * "With someone" wishes: the archers who asked for someone come right after them in the order
 * (same distance only), so that the cut keeps them on one target. The ANCHOR — the one the others
 * asked for — stays where the order put them: when the group does not fit on one target, the
 * askers end up with the person they asked for rather than together without them.
 */
function bk_plan_clusters(&$ctx, $seq)
{
    $root = array();
    $find = function ($x) use (&$root) { while (isset($root[$x]) && $root[$x] !== $x) $x = $root[$x]; return $x; };
    foreach ($ctx['archers'] as $id => $a) {
        if (!isset($root[$id])) $root[$id] = $id;
        if ($a['with'] === null || !isset($ctx['archers'][$a['with']])) continue;
        if (!isset($root[$a['with']])) $root[$a['with']] = $a['with'];
        $ra = $find($id); $rb = $find($a['with']);
        if ($ra !== $rb) $root[$ra] = $rb;
    }
    $pos = array_flip($seq);
    $groups = array();
    foreach ($ctx['archers'] as $id => $a) $groups[$find($id)][] = $id;
    foreach ($groups as $ids) {
        if (count($ids) < 2) continue;
        $cited = array();
        foreach ($ids as $id) {
            $w = $ctx['archers'][$id]['with'];
            if ($w !== null) $cited[$w] = ($cited[$w] ?? 0) + 1;
        }
        uksort($cited, function ($x, $y) use ($cited, $pos) { return ($cited[$y] <=> $cited[$x]) ?: $pos[$x] <=> $pos[$y]; });
        $anchor = key($cited);
        $an = $ctx['archers'][$anchor];
        usort($ids, function ($x, $y) use ($ctx, $anchor, $pos) {
            $ax = $ctx['archers'][$x]['with'] === $anchor ? 0 : 1;
            $ay = $ctx['archers'][$y]['with'] === $anchor ? 0 : 1;
            return ($ax <=> $ay) ?: $pos[$x] <=> $pos[$y];
        });
        // As many as one target can take: the rhythm, and the club quota.
        $clubs = array($an['club'] => 1);
        $take = array();
        foreach ($ids as $id) {
            if ($id === $anchor) continue;
            $a = $ctx['archers'][$id];
            if ($a['dk'] !== $an['dk'] || count($take) + 1 >= $ctx['rhythm']) continue;
            if (($clubs[$a['club']] ?? 0) >= $ctx['max']) continue;
            $clubs[$a['club']] = ($clubs[$a['club']] ?? 0) + 1;
            $take[] = $id;
        }
        if (!$take) continue;
        $seq = array_values(array_diff($seq, $take));
        array_splice($seq, array_search($anchor, $seq, true) + 1, 0, $take);
        $pos = array_flip($seq);
        foreach (array_merge(array($anchor), $take) as $id) $ctx['cluster'][$id] = $anchor;
    }
    return $seq;
}

/**
 * Cuts the order into targets: one run per distance series (consecutive archers), a number of
 * targets per run, then the best cut of each run. Returns [['dk' => series, 'ids' => [...]], …].
 */
function bk_plan_cut($ctx, $seq)
{
    $runs = array();
    foreach ($seq as $id) {
        $dk = $ctx['archers'][$id]['dk'];
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last]['dk'] === $dk) $runs[$last]['ids'][] = $id;
        else $runs[] = array('dk' => $dk, 'ids' => array($id));
    }

    // Targets per run: the seats (one more beside each wheelchair archer at rhythm 4), the club
    // quota (a club of 6 at 2 per target needs 3 targets).
    foreach ($runs as &$r) {
        $n = count($r['ids']);
        $wheel = 0; $clubs = array();
        foreach ($r['ids'] as $id) {
            $a = $ctx['archers'][$id];
            if ($a['wheel']) $wheel++;
            $clubs[$a['club']] = ($clubs[$a['club']] ?? 0) + 1;
        }
        $seats = $n + ($ctx['rhythm'] >= 4 ? $wheel : 0);
        $r['k'] = min($n, max(1, (int) ceil($seats / $ctx['rhythm']), (int) ceil(max($clubs) / $ctx['max'])));
    }
    unset($r);

    // At least `minTargets` targets of 2 archers or more: the busiest run is spread over one more
    // target, again and again, never down to a target of a single archer.
    $need = min($ctx['minTargets'], count($ctx['free']));
    for ($guard = 0; $guard < 500; $guard++) {
        $filled = 0; $total = 0;
        foreach ($runs as $r) { $filled += min($r['k'], intdiv(count($r['ids']), 2)); $total += $r['k']; }
        if ($filled >= $need || $total >= count($ctx['free'])) break;
        $best = null;
        foreach ($runs as $i => $r) {
            if (count($r['ids']) < 2 * ($r['k'] + 1)) continue;
            if ($best === null || count($r['ids']) / $r['k'] > count($runs[$best]['ids']) / $runs[$best]['k']) $best = $i;
        }
        if ($best === null) break;
        $runs[$best]['k']++;
    }

    $total = 0;
    foreach ($runs as $r) $total += $r['k'];
    $out = array();
    foreach ($runs as $r) {
        $cut = null;
        for ($k = $r['k']; $k <= count($r['ids']) && $cut === null; $k++) $cut = bk_plan_dp($ctx, $r['ids'], $k);
        if ($cut === null) continue;   // nothing possible: every archer of the run goes to the leftovers
        // A 40 with a triple face is a last resort: one more target when the field has it.
        if ($cut['mix40tri'] && $total < count($ctx['free'])) {
            $more = bk_plan_dp($ctx, $r['ids'], count($cut['chunks']) + 1);
            if ($more !== null && !$more['mix40tri']) { $cut = $more; $total++; }
        }
        foreach ($cut['chunks'] as $ids) $out[] = array('dk' => $r['dk'], 'ids' => $ids);
    }
    return $out;
}

/**
 * Best cut of a run of archers (in order) into exactly $k targets, or null when impossible.
 * Cost of a target: gap to the average size (squared), an archer alone, two families of faces
 * (a 40 with a triple face much more), each change of category inside, club over its quota, two
 * archers of one club alone; plus a "with someone" group split by the cut.
 * Returns ['chunks' => [[ids]…], 'mix40tri' => bool].
 */
function bk_plan_dp($ctx, $ids, $k)
{
    $n = count($ids);
    $R = min($ctx['rhythm'], count($ctx['letters']));
    if ($k < 1 || $k > $n || $k * $R < $n) return null;
    $avg = $n / $k;
    $INF = INF;

    $base = array();
    $chunk = function ($p, $i) use (&$base, $ctx, $ids, $n) {
        $key = $p . ':' . $i;
        if (isset($base[$key])) return $base[$key];
        $m = array_slice($ids, $p, $i - $p);
        $fit = bk_plan_fit($ctx, $m);
        if ($fit === null) return $base[$key] = array(INF, false);
        $s = count($m);
        $cost = 0;
        if ($s === 1 && $n > 1) $cost += 200;
        if ($fit['kinds'] > 1) $cost += 4;
        if ($fit['mix40tri']) $cost += 30;
        $cats = array(); $clubs = array();
        foreach ($m as $id) {
            $a = $ctx['archers'][$id];
            $cats[$a['cat']] = true;
            $clubs[$a['club']] = ($clubs[$a['club']] ?? 0) + 1;
        }
        $cost += count($cats) - 1;
        foreach ($clubs as $c) $cost += 25 * max(0, $c - $ctx['max']);
        if ($s === 2 && count($clubs) === 1) $cost += 25;
        return $base[$key] = array($cost, $fit['mix40tri']);
    };
    $cut = function ($p) use ($ctx, $ids) {
        if ($p <= 0) return 0;
        $x = $ctx['cluster'][$ids[$p - 1]] ?? null;
        return ($x !== null && $x === ($ctx['cluster'][$ids[$p]] ?? null)) ? 3 : 0;
    };

    $best = array(0 => array(0 => 0.0));
    $from = array();
    for ($j = 1; $j <= $k; $j++) {
        for ($i = $j; $i <= min($n, $j * $R); $i++) {
            if ($n - $i > ($k - $j) * $R) continue;   // what is left would not fit
            for ($s = 1; $s <= min($R, $i - ($j - 1)); $s++) {
                $p = $i - $s;
                if (!isset($best[$j - 1][$p])) continue;
                list($c) = $chunk($p, $i);
                if ($c === $INF) continue;
                $v = $best[$j - 1][$p] + $c + 10 * ($s - $avg) * ($s - $avg) + $cut($p);
                if (!isset($best[$j][$i]) || $v < $best[$j][$i]) { $best[$j][$i] = $v; $from[$j][$i] = $p; }
            }
        }
    }
    if (!isset($best[$k][$n])) return null;
    $chunks = array();
    $mix = false;
    for ($j = $k, $i = $n; $j > 0; $j--) {
        $p = $from[$j][$i];
        array_unshift($chunks, array_slice($ids, $p, $i - $p));
        if ($chunk($p, $i)[1]) $mix = true;
        $i = $p;
    }
    return array('chunks' => $chunks, 'mix40tri' => $mix);
}

/**
 * Letters of a target for a group of archers, or null when the group cannot share a target: one
 * distance series, the rhythm, the wheelchair rule, indoors one family of faces per half (the
 * first family in the order on the left half), the sharing rules at every distance. Letter wishes
 * are kept when asked ($wishes) and possible. Returns ['letters' => [id => letter],
 * 'kinds' => families, 'mix40tri' => bool].
 */
function bk_plan_fit($ctx, $ids, $wishes = false)
{
    $n = count($ids);
    if (!$n) return array('letters' => array(), 'kinds' => 0, 'mix40tri' => false);
    if ($n > count($ctx['letters'])) return null;
    $A = array();
    $byKind = array();
    $dk = null;
    $wheel = false;
    foreach ($ids as $id) {
        $a = $ctx['archers'][$id] ?? $ctx['fixed'][$id];
        if ($dk === null) $dk = $a['dk'];
        elseif ($dk !== $a['dk']) return null;
        if ($a['wheel']) $wheel = true;
        $A[$id] = $a;
        $byKind[$a['fk']][] = $id;
    }
    // Regulations B.1.2: no 4 archers on the target of an archer in a wheelchair.
    if ($wheel && $n >= 4) return null;

    if ($ctx['halves']) {
        if (count($byKind) > 2) return null;
        $kinds = array_keys($byKind);
        $orients = count($kinds) === 1
            ? array(array($kinds[0] => $ctx['letters']))
            : array(array($kinds[0] => $ctx['halves'][0], $kinds[1] => $ctx['halves'][1]),
                    array($kinds[0] => $ctx['halves'][1], $kinds[1] => $ctx['halves'][0]));
    } else {
        $kinds = array_keys($byKind);
        $orients = array(array('*' => $ctx['letters']));
        $byKind = array('*' => $ids);
    }
    $best = null; $bestKept = -1;
    foreach ($orients as $o) {
        $letters = array(); $kept = 0; $ok = true;
        foreach ($o as $kind => $ls) {
            $list = $byKind[$kind];
            if (count($list) > count($ls)) { $ok = false; break; }
            $free = $ls;
            if ($wishes) {
                foreach ($list as $id) {
                    $w = $A[$id]['letter'];
                    $pos = $w !== '' ? array_search($w, $free, true) : false;
                    if ($pos === false) continue;
                    $letters[$id] = $w; unset($free[$pos]); $kept++;
                }
            }
            // The others on the outer letters of what is left, to leave room between them on the
            // shooting line: two archers on A-B-C take A and C (same rule as REPARTITION_EPREUVES).
            $free = array_values($free);
            $rest = array();
            foreach ($list as $id) if (!isset($letters[$id])) $rest[] = $id;
            $k = count($rest);
            foreach ($rest as $j => $id) $letters[$id] = $free[$k > 1 ? (int) round($j * (count($free) - 1) / ($k - 1)) : 0];
        }
        if ($ok && $kept > $bestKept) { $best = $letters; $bestKept = $kept; }
        if ($ok && !$wishes) break;
    }
    if ($best === null) return null;

    $mix = false;
    $nd = count(reset($A)['fams']);
    for ($d = 0; $d < $nd; $d++) {
        $by = array();
        foreach ($best as $id => $l) $by[$l] = $A[$id]['fams'][$d] ?? $A[$id]['fams'][0];
        $r = bk_target_layout($ctx['disc'], $ctx['rhythm'], $by);
        if (!$r['ok']) return null;
        if ($r['mixed40tri']) $mix = true;
    }
    return array('letters' => $best, 'kinds' => count($kinds), 'mix40tri' => $mix);
}

/**
 * Field settings of a target for one archer: distance range, and the exact faces (TfId) the
 * target accepts. A setting NOT declared imposes nothing — never invent a constraint.
 */
function bk_plan_caps_ok($ctx, $t, $a)
{
    $c = $ctx['caps'][$t] ?? null;
    if (!$c) return true;
    foreach ($a['dist'] as $m) {
        if (!empty($c['min']) && $m < $c['min']) return false;
        if (!empty($c['max']) && $m > $c['max']) return false;
    }
    if (!empty($c['faces']) && $a['face'] > 0 && !in_array($a['face'], array_map('intval', $c['faces']), true)) return false;
    return true;
}

/**
 * The planned targets on the free targets of the departure, in the order of the field: each takes
 * the next free target whose settings accept all its archers (searching on, then from the start:
 * a target skipped for its settings serves later). A target RESERVED to some faces that accepts
 * the group is taken first: it can serve nobody else, and the organiser set it for them. A planned
 * target no target accepts is left out: its archers go to the leftovers.
 */
function bk_plan_numbered($ctx, $plan)
{
    $free = $ctx['free'];
    $n = count($free);
    $used = array();
    $pos = 0;
    $out = array();
    $reserved = array();
    foreach ($free as $idx => $t) if (!empty($ctx['caps'][$t]['faces'])) $reserved[$idx] = true;
    foreach ($plan as $c) {
        $found = null;
        foreach (array(true, false) as $onlyReserved) {
            if ($onlyReserved && !$reserved) continue;
            for ($k = 0; $k < $n && $found === null; $k++) {
                $idx = ($pos + $k) % $n;
                if (isset($used[$idx]) || ($onlyReserved && !isset($reserved[$idx]))) continue;
                $ok = true;
                foreach ($c['ids'] as $id) if (!bk_plan_caps_ok($ctx, $free[$idx], $ctx['archers'][$id])) { $ok = false; break; }
                if ($ok) $found = $idx;
            }
            if ($found !== null) break;
        }
        if ($found === null) continue;
        $used[$found] = true;
        $pos = $found + 1;
        $c['t'] = $free[$found];
        $out[] = $c;
    }
    return $out;
}

/** Problems of a group on one target: [archers over the club quota, two archers of one club alone]. */
function bk_plan_issues($ctx, $ids)
{
    $clubs = array();
    foreach ($ids as $id) {
        $c = ($ctx['archers'][$id] ?? $ctx['fixed'][$id])['club'];
        $clubs[$c] = ($clubs[$c] ?? 0) + 1;
    }
    $over = 0;
    foreach ($clubs as $n) $over += max(0, $n - $ctx['max']);
    return array($over, count($ids) === 2 && count($clubs) === 1 ? 1 : 0);
}

/**
 * Club quota, then no two archers of one club alone: an archer of the target at fault swaps with
 * an archer of another club on another target — the nearest in the order, the one changing the
 * least important criterion first. A swap is kept only when both targets stay possible (layout,
 * settings) and the problems go down.
 */
function bk_plan_swaps($ctx, $plan)
{
    $pos = array();
    $i = 0;
    foreach ($plan as $c) foreach ($c['ids'] as $id) $pos[$id] = $i++;
    for ($round = 0; $round < 20; $round++) {
        $plan = bk_plan_swap_one($ctx, $plan, $pos);
        $before = $plan;
        $plan = bk_plan_swap_two($ctx, $plan, $pos);
        if ($plan === $before) break;
    }
    // Back in the order on each target (letters follow the order).
    foreach ($plan as &$c) usort($c['ids'], function ($x, $y) use ($pos) { return $pos[$x] <=> $pos[$y]; });
    unset($c);
    return $plan;
}

/** Sum of the problems of two groups. */
function bk_plan_issues2($ctx, $a, $b)
{
    $x = bk_plan_issues($ctx, $a);
    $y = bk_plan_issues($ctx, $b);
    return array($x[0] + $y[0], $x[1] + $y[1]);
}

/** Single swaps (see bk_plan_swaps), as long as one lowers the problems. */
function bk_plan_swap_one($ctx, $plan, $pos)
{
    $sum = function ($x, $y) { return array($x[0] + $y[0], $x[1] + $y[1]); };
    for ($guard = 0; $guard < 1000; $guard++) {
        $best = null; $bestCost = null;
        foreach ($plan as $ci => $c) {
            $is = bk_plan_issues($ctx, $c['ids']);
            if (!$is[0] && !$is[1]) continue;
            $count = array();
            foreach ($c['ids'] as $id) $count[$ctx['archers'][$id]['club']] = ($count[$ctx['archers'][$id]['club']] ?? 0) + 1;
            foreach ($c['ids'] as $xi => $x) {
                $cx = $ctx['archers'][$x]['club'];
                if (!$is[1] && $count[$cx] <= $ctx['max']) continue;
                foreach ($plan as $cj => $d) {
                    if ($cj === $ci || $d['dk'] !== $c['dk']) continue;
                    $before = $sum($is, bk_plan_issues($ctx, $d['ids']));
                    foreach ($d['ids'] as $yi => $y) {
                        if ($ctx['archers'][$y]['club'] === $cx) continue;
                        $cost = array(bk_plan_gap($ctx, $x, $y), abs($pos[$x] - $pos[$y]));
                        if ($bestCost !== null && $cost >= $bestCost) continue;
                        $a2 = $c['ids']; $a2[$xi] = $y;
                        $b2 = $d['ids']; $b2[$yi] = $x;
                        if (($sum(bk_plan_issues($ctx, $a2), bk_plan_issues($ctx, $b2)) <=> $before) >= 0) continue;
                        if (!bk_plan_caps_ok($ctx, $c['t'], $ctx['archers'][$y]) || !bk_plan_caps_ok($ctx, $d['t'], $ctx['archers'][$x])) continue;
                        if (bk_plan_fit($ctx, $a2) === null || bk_plan_fit($ctx, $b2) === null) continue;
                        $best = array($ci, $xi, $cj, $yi);
                        $bestCost = $cost;
                    }
                }
            }
        }
        if ($best === null) break;
        list($ci, $xi, $cj, $yi) = $best;
        $x = $plan[$ci]['ids'][$xi];
        $plan[$ci]['ids'][$xi] = $plan[$cj]['ids'][$yi];
        $plan[$cj]['ids'][$yi] = $x;
    }
    return $plan;
}

/**
 * One swap of TWO archers of a club over its quota against two archers of another target: what
 * a single swap cannot do when a half of the buttress must change family (two 60s of one club
 * against two 40s of other clubs). Targets near in the order only (ten on each side).
 */
function bk_plan_swap_two($ctx, $plan, $pos)
{
    $keys = array_keys($plan);
    $best = null; $bestCost = null;
    foreach ($keys as $ri => $ci) {
        $c = $plan[$ci];
        $is = bk_plan_issues($ctx, $c['ids']);
        if (!$is[0]) continue;
        $count = array();
        foreach ($c['ids'] as $id) $count[$ctx['archers'][$id]['club']][] = $id;
        foreach ($count as $club => $ids) {
            if (count($ids) <= $ctx['max']) continue;
            for ($a = 0; $a < count($ids); $a++) for ($b = $a + 1; $b < count($ids); $b++) {
                $x1 = $ids[$a]; $x2 = $ids[$b];
                for ($rj = max(0, $ri - 10); $rj <= min(count($keys) - 1, $ri + 10); $rj++) {
                    $cj = $keys[$rj];
                    $d = $plan[$cj];
                    if ($cj === $ci || $d['dk'] !== $c['dk']) continue;
                    $before = bk_plan_issues2($ctx, $c['ids'], $d['ids']);
                    $n = count($d['ids']);
                    for ($p = 0; $p < $n; $p++) for ($q = $p + 1; $q < $n; $q++) {
                        $y1 = $d['ids'][$p]; $y2 = $d['ids'][$q];
                        if ($ctx['archers'][$y1]['club'] === $club || $ctx['archers'][$y2]['club'] === $club) continue;
                        $cost = array(max(bk_plan_gap($ctx, $x1, $y1), bk_plan_gap($ctx, $x2, $y2)), abs($pos[$x1] - $pos[$y1]));
                        if ($bestCost !== null && $cost >= $bestCost) continue;
                        $a2 = array_values(array_diff($c['ids'], array($x1, $x2)));
                        $a2[] = $y1; $a2[] = $y2;
                        $b2 = array_values(array_diff($d['ids'], array($y1, $y2)));
                        $b2[] = $x1; $b2[] = $x2;
                        if ((bk_plan_issues2($ctx, $a2, $b2) <=> $before) >= 0) continue;
                        if (!bk_plan_caps_ok($ctx, $c['t'], $ctx['archers'][$y1]) || !bk_plan_caps_ok($ctx, $c['t'], $ctx['archers'][$y2])
                            || !bk_plan_caps_ok($ctx, $d['t'], $ctx['archers'][$x1]) || !bk_plan_caps_ok($ctx, $d['t'], $ctx['archers'][$x2])) continue;
                        if (bk_plan_fit($ctx, $a2) === null || bk_plan_fit($ctx, $b2) === null) continue;
                        $best = array($ci, $a2, $cj, $b2);
                        $bestCost = $cost;
                    }
                }
            }
        }
    }
    if ($best !== null) {
        $plan[$best[0]]['ids'] = $best[1];
        $plan[$best[2]]['ids'] = $best[3];
    }
    return $plan;
}

/* ---- Leftovers, repairs, counters --------------------------------------------------------- */

/** Occupants of every target: [t => [letter => archer]] (archers already placed + $places). */
function bk_plan_occupants($ctx, $places)
{
    $occ = array();
    foreach ($ctx['fixed'] as $f) $occ[$f['t']][$f['l']] = $f;
    foreach ($places as $id => $p) $occ[$p['t']][$p['l']] = $ctx['archers'][$id];
    return $occ;
}

/** Can archer $a go on target $t, letter $l, given its occupants? Returns null or [overQuota, mixes]. */
function bk_plan_slot_cost($ctx, $occ, $t, $l, $a)
{
    $here = $occ[$t] ?? array();
    if (isset($here[$l])) return null;
    $wheel = $a['wheel'];
    foreach ($here as $o) {
        if ($o['dk'] !== $a['dk']) return null;
        if ($o['wheel']) $wheel = true;
    }
    if ($wheel && count($here) + 1 >= 4) return null;
    if (!bk_plan_caps_ok($ctx, $t, $a)) return null;
    $mix = 0;
    foreach ($a['fams'] as $d => $f) {
        $by = array($l => $f);
        foreach ($here as $ol => $o) $by[$ol] = $o['fams'][$d] ?? $o['fams'][0];
        $r = bk_target_layout($ctx['disc'], $ctx['rhythm'], $by);
        if (!$r['ok']) return null;
        if ($r['mixed40tri']) $mix = 1;
    }
    $club = 0;
    foreach ($here as $o) if ($o['club'] === $a['club']) $club++;
    return array($club >= $ctx['max'] ? 1 : 0, $mix);
}

/**
 * Second pass: the archers the plan could not take go wherever they still can — a target
 * already in use first (archers already placed included), then an empty one in the order of
 * the field.
 */
function bk_plan_leftovers($ctx, $places)
{
    $occ = bk_plan_occupants($ctx, $places);
    foreach ($ctx['archers'] as $id => $a) {
        if (isset($places[$id])) continue;
        $best = null; $bestKey = null;
        foreach ($ctx['targets'] as $rank => $t) {
            if (empty($occ[$t])) continue;
            foreach ($ctx['letters'] as $l) {
                $cost = bk_plan_slot_cost($ctx, $occ, $t, $l, $a);
                if ($cost === null) continue;
                $key = array($cost[0], $cost[1], $rank, $l);
                if ($best === null || $key < $bestKey) { $best = array($t, $l); $bestKey = $key; }
            }
        }
        if ($best === null || $bestKey[0]) {
            // No room within the quota on a target in use: the first empty target that takes them.
            foreach ($ctx['targets'] as $t) {
                if (!empty($occ[$t]) || bk_plan_slot_cost($ctx, $occ, $t, 'A', $a) === null) continue;
                $best = array($t, 'A');
                break;
            }
        }
        if ($best === null) continue;
        $places[$id] = array('t' => $best[0], 'l' => $best[1]);
        $occ[$best[0]][$best[1]] = $a;
    }
    return $places;
}

/**
 * Rules of the regulations (B.1.2) checked on every target, repaired once the plan is filled:
 * no archer ALONE on a target, not TWO archers of one club alone (they would mark each other's
 * scores), not 4 archers on a target where an archer in a WHEELCHAIR shoots. Moves only archers
 * this plan placed, one at a time, onto targets already in use, each move kept only when it does
 * not break a rule of higher priority (club quota, physical layout).
 */
function bk_plan_repair($ctx, $places)
{
    for ($guard = 0; $guard < 200; $guard++) {
        $occ = bk_plan_occupants($ctx, $places);
        $move = bk_plan_repair_one($ctx, $places, $occ);
        if ($move === null) break;
        list($id, $t, $l) = $move;
        $places[$id] = array('t' => $t, 'l' => $l);
    }
    return $places;
}

/** Problems of one target: [alone, same-club pair, wheelchair target full]. */
function bk_plan_target_issues($ctx, $here)
{
    $n = count($here);
    $alone = $n === 1 ? 1 : 0;
    $pair = 0;
    if ($n === 2) {
        $c = array_values(array_map(function ($o) { return $o['club']; }, $here));
        $pair = $c[0] === $c[1] ? 1 : 0;
    }
    $wheel = 0;
    if ($n >= 4) foreach ($here as $o) if (!empty($o['wheel'])) { $wheel = 1; break; }
    return array($alone, $pair, $wheel);
}

/** The best single move that removes a problem without creating a worse one; null: none. */
function bk_plan_repair_one($ctx, $places, $occ)
{
    $before = bk_plan_repair_score($ctx, $occ);
    $best = null; $bestScore = $before;
    foreach ($places as $id => $p) {
        $a = $ctx['archers'][$id];
        $issues = bk_plan_target_issues($ctx, $occ[$p['t']]);
        foreach (array_keys($occ) as $t) {
            if ($t === $p['t'] || empty($occ[$t])) continue;   // never open a new target to fix one
            $there = bk_plan_target_issues($ctx, $occ[$t]);
            if (!array_sum($issues) && !array_sum($there)) continue;
            foreach ($ctx['letters'] as $l) {
                $occ2 = $occ;
                unset($occ2[$p['t']][$p['l']]);
                if (!$occ2[$p['t']]) unset($occ2[$p['t']]);
                $cost = bk_plan_slot_cost($ctx, $occ2, $t, $l, $a);
                if ($cost === null || $cost[0]) continue;   // the club quota comes first
                $occ2[$t][$l] = $a;
                $score = bk_plan_repair_score($ctx, $occ2);
                if ($score < $bestScore) { $best = array($id, $t, $l); $bestScore = $score; }
            }
        }
    }
    return $best;
}

/** What the repair minimises, by priority: quota, same-club pairs, archers alone, wheelchair, 40 + triple. */
function bk_plan_repair_score($ctx, $occ)
{
    $over = 0; $pairs = 0; $alone = 0; $wheel = 0; $mixed = 0;
    foreach ($occ as $here) {
        if (!$here) continue;
        $n = array();
        foreach ($here as $o) $n[$o['club']] = ($n[$o['club']] ?? 0) + 1;
        foreach ($n as $c) $over += max(0, $c - $ctx['max']);
        list($a, $p, $w) = bk_plan_target_issues($ctx, $here);
        $alone += $a; $pairs += $p; $wheel += $w;
        if (bk_plan_mixed40tri($here)) $mixed++;
    }
    return array($over, $pairs, $alone, $wheel, $mixed);
}

/** Does a target carry a 40 and a 40 triple at one of its distances? */
function bk_plan_mixed40tri($here)
{
    $first = reset($here);
    for ($d = 0; $d < count($first['fams']); $d++) {
        $f = array();
        foreach ($here as $o) $f[] = $o['fams'][$d] ?? $o['fams'][0];
        if (in_array('40', $f, true) && in_array('tri', $f, true)) return true;
    }
    return false;
}

/**
 * Places a target can still take of one profile, around its occupants ($occ[$t]): archers of
 * that profile are added one by one while a letter accepts them (layout, settings, wheelchair
 * rule). The gauge of the registration and the places of an event come from here.
 */
function bk_plan_room($ctx, $occ, $t, $a)
{
    $a = bk_plan_norm($a);
    $n = 0;
    for ($guard = 0; $guard < count($ctx['letters']); $guard++) {
        $slot = null;
        foreach ($ctx['letters'] as $l) {
            $cost = bk_plan_slot_cost($ctx, $occ, $t, $l, $a);
            if ($cost !== null && ($slot === null || $cost[1] < $slot[1])) $slot = array($l, $cost[1]);
        }
        if ($slot === null) break;
        $occ[$t][$slot[0]] = $a;
        $n++;
    }
    return $n;
}

/** Wishes asked and kept. */
function bk_plan_wishes($ctx, $places)
{
    $where = array();
    foreach ($ctx['fixed'] as $id => $f) $where[$id] = array('t' => $f['t'], 'l' => $f['l']);
    foreach ($places as $id => $p) $where[$id] = $p;
    $asked = 0; $kept = 0;
    foreach ($ctx['archers'] as $id => $a) {
        if ($a['letter'] === '' && $a['with'] === null) continue;
        $asked++;
        if (!isset($where[$id])) continue;
        $ok = true;
        if ($a['letter'] !== '' && $where[$id]['l'] !== $a['letter']) $ok = false;
        if ($a['with'] !== null && (!isset($where[$a['with']]) || $where[$a['with']]['t'] !== $where[$id]['t'])) $ok = false;
        if ($ok) $kept++;
    }
    return array('asked' => $asked, 'kept' => $kept);
}

function bk_plan_result($ctx, $places)
{
    $occ = bk_plan_occupants($ctx, $places);
    $touched = array();
    foreach ($places as $p) $touched[$p['t']] = true;
    $over = 0; $mixed = 0; $alone = 0; $pairs = 0; $wheel = 0;
    foreach (array_keys($touched) as $t) {
        list($ia, $ip, $iw) = bk_plan_target_issues($ctx, $occ[$t]);
        $alone += $ia; $pairs += $ip; $wheel += $iw;
        $n = array();
        foreach ($occ[$t] as $o) $n[$o['club']] = ($n[$o['club']] ?? 0) + 1;
        foreach ($n as $c) $over += max(0, $c - $ctx['max']);
        if (bk_plan_mixed40tri($occ[$t])) $mixed++;
    }
    $unplaced = array();
    foreach (array_keys($ctx['archers']) as $id) if (!isset($places[$id])) $unplaced[] = $id;
    return array('places' => $places, 'unplaced' => $unplaced, 'overQuota' => $over,
                 'mixed40tri' => $mixed, 'alone' => $alone, 'sameClubPairs' => $pairs,
                 'wheelchairFull' => $wheel, 'wishes' => bk_plan_wishes($ctx, $places));
}
