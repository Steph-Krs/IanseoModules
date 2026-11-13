<?php
/**
 * lib/placement.php — target assignment by FACE, not by category (pure: no database access).
 *
 * Rules agreed with the organisers (see REGLES_PLACEMENT_FR.md):
 *
 *  Indoors, a buttress has two HALVES: left = letters A and C, right = B and D. A half carries
 *  ONE family of faces:
 *    '80'  80 cm face: the whole buttress, shared by every archer of the target, alone on it;
 *    '60'  60 cm single face: one half, shared by the two archers of the half;
 *    '60t' 60 cm triple face: placed like a 60, but never in the same half as a '60';
 *    '40'  40 cm single face: two per half (one per archer);
 *    'tri' 40 cm triple face (recurve or compound): two per half;
 *    '?'   unknown face: alone on its target.
 *  40 and 'tri' on one target is possible but avoided (last resort, reported). A family is
 *  given PER DISTANCE (indoor 25 + 18: "60cm/40cm" = 60 at 25 m, then 40 at 18 m) and a target
 *  must be possible at each distance. Outdoors, the rules of lib/cohabitation.php are kept.
 *
 *  Hard: one distance (series by series) per target, field settings, buttress capacity and
 *  rhythm, archers already placed never move. Soft, by priority: 1 everyone placed, 2 club
 *  quota per target, 3 no 40 with a triple face, 4 fewest targets, 5 targets of one distance
 *  next to each other (shortest distance first) and families in the order 80, 60, 40, triple,
 *  6 balance within a family, 7 "with someone" wishes, 8 letter wishes.
 *
 * How: several candidate plans are built (club quota taken into account or not when sizing,
 * 40 + triple mixing never / only to save targets / as much as possible), each one is filled
 * and scored on the priorities above, the best one wins. A candidate plan is a list of SHAPES
 * (a target to be: which family where, how many archers), numbered on the free targets of the
 * departure in order, then filled club by club, then the leftovers are placed wherever they can
 * still go (targets of archers already placed included).
 */

if (defined('BK_PLACEMENT_LOADED')) return;
define('BK_PLACEMENT_LOADED', true);

require_once __DIR__ . '/cohabitation.php';

const BK_FAM_RANK = array('80' => 0, '60' => 1, '60t' => 1, '40' => 2, 'tri' => 3, '?' => 4);

/* ---- Families of faces -------------------------------------------------------------------- */

/**
 * Family of a face on ONE distance. $t: target type of ianseo (TargetFaces.TfT<n> = Targets.TarId:
 * 1 complete, 2 "6-10" triple, 3 compound complete, 4 compound triple), $w: diameter in cm.
 * The type decides; the name is only a fallback (type 0).
 */
function bk_face_family($disc, $name, $t, $w)
{
    if ($disc !== 'salle') {
        if (trim((string) $name) === '') return '?';
        return bk_face_key(bk_face_class($name));
    }
    $t = intval($t);
    $w = intval($w);
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

/* ---- Planner ------------------------------------------------------------------------------ */

/** Rank of a target on one distance in the order of the families (a mixed 60 follows its partner). */
function bk_plan_rank($fams)
{
    foreach (array('?', '40', 'tri', '60', '60t', '80') as $f) {
        if (in_array($f, $fams, true)) return BK_FAM_RANK[$f];
    }
    return BK_FAM_RANK['?'];
}

function bk_plan_rank_one($f)
{
    return BK_FAM_RANK[$f] ?? BK_FAM_RANK['?'];
}

/** Families of an archer at each distance. */
function bk_plan_fams($a)
{
    return is_array($a['fam']) ? array_values($a['fam']) : array_fill(0, max(1, count($a['dist'])), (string) $a['fam']);
}

/**
 * Plans the targets of a departure. Pure: see the contract in tests/lib/TargetPlan.php of the
 * deployment repository, and the header of this file.
 */
function bk_plan_session($in)
{
    $ctx = bk_plan_ctx($in);
    if (!$ctx['archers']) {
        return array('places' => array(), 'unplaced' => array(), 'overQuota' => 0, 'mixed40tri' => 0,
                     'wishes' => bk_plan_wishes($ctx, array()));
    }
    $variants = !empty($in['fast'])
        ? array(array('quota' => false, 'mix' => 'none'))
        : array(
            array('quota' => true,  'mix' => 'none'),
            array('quota' => false, 'mix' => 'none'),
            array('quota' => true,  'mix' => 'merge'),
            array('quota' => false, 'mix' => 'merge'),
            array('quota' => true,  'mix' => 'max'),
            array('quota' => false, 'mix' => 'max'),
        );
    $best = null; $bestScore = null;
    foreach ($variants as $v) {
        $places = bk_plan_variant($ctx, $v);
        $score = bk_plan_score($ctx, $places);
        if ($best === null || $score < $bestScore) { $best = $places; $bestScore = $score; }
    }
    return bk_plan_result($ctx, $best);
}

/** Normalised input: archers indexed by id, profiles, halves, free targets. */
function bk_plan_ctx($in)
{
    $disc   = (string) ($in['disc'] ?? 'ext');
    $rhythm = max(1, intval($in['rhythm'] ?? 4));
    $ctx = array(
        'disc' => $disc, 'rhythm' => $rhythm, 'letters' => bk_rhythm_letters($rhythm),
        'halves' => bk_plan_halves_model($disc, $rhythm) ? bk_plan_halves($rhythm) : null,
        'max' => max(1, intval($in['maxPerClub'] ?? 99)),
        'minTargets' => max(0, intval($in['minTargets'] ?? 0)),
        'caps' => $in['caps'] ?? array(),
        'targets' => array_values(array_map('intval', $in['targets'] ?? array())),
        'archers' => array(), 'fixed' => array(), 'profiles' => array(),
    );
    foreach (($in['fixed'] ?? array()) as $f) {
        $f['fams'] = bk_plan_fams($f);
        $f['club'] = (string) ($f['club'] ?? '') !== '' ? (string) $f['club'] : '?';
        // bytes: target letters are ASCII
        $f['l'] = strtoupper((string) $f['l']);
        $f['wheel'] = !empty($f['wheelchair']);
        $ctx['fixed'][intval($f['id'])] = $f;
    }
    foreach (($in['archers'] ?? array()) as $a) {
        $a['id'] = intval($a['id']);
        $a['fams'] = bk_plan_fams($a);
        $a['club'] = (string) ($a['club'] ?? '') !== '' ? (string) $a['club'] : '?';
        // bytes: target letters are ASCII
        $a['letter'] = strtoupper(trim((string) ($a['letter'] ?? '')));
        $a['with'] = isset($a['with']) && $a['with'] !== null && $a['with'] !== '' ? intval($a['with']) : null;
        $a['prof'] = implode('-', $a['dist']) . '#' . implode('/', $a['fams']);
        $a['wheel'] = !empty($a['wheelchair']);
        $ctx['archers'][$a['id']] = $a;
        $p = &$ctx['profiles'][$a['prof']];
        if (!$p) $p = array('key' => $a['prof'], 'dist' => $a['dist'], 'fams' => $a['fams'], 'ids' => array(),
                            'kind' => bk_plan_kind($ctx, $a['fams']), 'cap' => 0);
        $p['ids'][] = $a['id'];
        unset($p);
    }
    foreach ($ctx['profiles'] as &$p) $p['cap'] = bk_plan_capacity($ctx, $p);
    unset($p);
    $fixedTargets = array();
    foreach ($ctx['fixed'] as $f) $fixedTargets[intval($f['t'])] = true;
    $ctx['free'] = array_values(array_filter($ctx['targets'], function ($t) use ($fixedTargets) { return !isset($fixedTargets[$t]); }));
    return $ctx;
}

/**
 * Kind of a profile: 'whole' (takes the whole target: 80, unknown, or outside the halves
 * model), 'filler' (60 / 60t at every distance: goes with any other half), 'anchor' (the rest).
 */
function bk_plan_kind($ctx, $fams)
{
    if (!$ctx['halves']) return 'whole';
    if (array_intersect($fams, array('80', '?'))) return 'whole';
    foreach ($fams as $f) if ($f !== '60' && $f !== '60t') return 'anchor';
    return 'filler';
}

/** Archers of one profile a target can take alone. */
function bk_plan_capacity($ctx, $p)
{
    $cap = 0;
    for ($m = 1; $m <= $ctx['rhythm']; $m++) {
        $byLetter = array_fill_keys(array_slice($ctx['letters'], 0, $m), null);
        $ok = true;
        foreach ($p['fams'] as $f) {
            if (!bk_target_layout($ctx['disc'], $ctx['rhythm'], array_map(function () use ($f) { return $f; }, $byLetter))['ok']) { $ok = false; break; }
        }
        if (!$ok) break;
        $cap = $m;
    }
    return max(1, $cap);
}

/** $n archers over $k targets, as evenly as possible, the bigger first. */
function bk_plan_even($n, $k)
{
    $k = max(1, $k);
    $out = array();
    for ($i = 0; $i < $k; $i++) $out[] = intdiv($n, $k) + ($i < $n % $k ? 1 : 0);
    return array_values(array_filter($out));
}

/** Most archers of one club in a profile. */
function bk_plan_max_club($ctx, $ids)
{
    $n = array();
    foreach ($ids as $id) { $c = $ctx['archers'][$id]['club']; $n[$c] = ($n[$c] ?? 0) + 1; }
    return $n ? max($n) : 0;
}

/** Number of targets a profile takes alone (club quota taken into account or not). */
function bk_plan_k($ctx, $p, $quota, $extra = array())
{
    $n = count($p['ids']);
    // An archer in a wheelchair keeps a seat free beside them (no 4 archers on their target,
    // regulations B.1.2): one more seat to plan for each of them.
    $wheel = 0;
    foreach ($p['ids'] as $id) if (!empty($ctx['archers'][$id]['wheel'])) $wheel++;
    $seats = $n + ($p['cap'] >= 4 ? $wheel : 0);
    $k = (int) ceil($seats / max(1, $p['cap']));
    if ($quota) $k = max($k, (int) ceil(bk_plan_max_club($ctx, $p['ids']) / $ctx['max']));
    $k += intval($extra[$p['key']] ?? 0);
    return min($k, $n);
}

/**
 * One candidate plan → places [id => ['t','l']].
 * A shape: ['dist'=>int[], 'slots'=>[['prof'=>key, 'n'=>int, 'side'=>0|1|null]], 'rank'=>int[]]
 * (side null = the whole target).
 */
function bk_plan_variant($ctx, $v)
{
    $shapes = bk_plan_shapes_min($ctx, $v);
    $numbered = bk_plan_number($ctx, $shapes);
    $places = bk_plan_fill($ctx, $numbered);
    $places = bk_plan_leftovers($ctx, $places);
    return bk_plan_repair($ctx, $places);
}

/**
 * The shapes, with at least `minTargets` targets FILLED (2 archers or more: rule of the
 * regulations), as far as the archers and the free targets allow: the busiest family is spread
 * over one more target, again and again, never down to a target of a single archer.
 */
function bk_plan_shapes_min($ctx, $v)
{
    $v['extra'] = array();
    $shapes = bk_plan_shapes($ctx, $v);
    $need = min(intval($ctx['minTargets']), count($ctx['free']));
    for ($guard = 0; $guard < 200; $guard++) {
        $filled = 0;
        foreach ($shapes as $s) if ($s['size'] >= 2) $filled++;
        if ($filled >= $need || count($shapes) >= count($ctx['free'])) break;
        $best = null; $bestAvg = 0;
        foreach ($ctx['profiles'] as $key => $p) {
            if ($p['kind'] === 'filler') continue;
            $n = count($p['ids']);
            $k = bk_plan_k($ctx, $p, $v['quota'], $v['extra']);
            if ($n < 2 * ($k + 1)) continue;   // one more target would leave one with a single archer
            if ($best === null || $n / $k > $bestAvg) { $best = $key; $bestAvg = $n / $k; }
        }
        if ($best === null) break;
        $v['extra'][$best] = ($v['extra'][$best] ?? 0) + 1;
        $shapes = bk_plan_shapes($ctx, $v);
    }
    return $shapes;
}

/** The shapes of every distance, in the order of the targets. */
function bk_plan_shapes($ctx, $v)
{
    $byDist = array();
    foreach ($ctx['profiles'] as $p) $byDist[implode('-', $p['dist'])][] = $p;
    $all = array();
    foreach ($byDist as $profiles) {
        $shapes = array();
        $anchors = array(); $fillers = array();
        foreach ($profiles as $p) {
            if ($p['kind'] === 'whole') {
                foreach (bk_plan_even(count($p['ids']), bk_plan_k($ctx, $p, $v['quota'], $v['extra'] ?? array())) as $n) {
                    $shapes[] = array('dist' => $p['dist'], 'slots' => array(array('prof' => $p['key'], 'n' => $n, 'side' => null)));
                }
            } elseif ($p['kind'] === 'anchor') {
                $anchors[] = $p;
            } else {
                $fillers[] = $p;
            }
        }
        if ($v['mix'] === 'max') {
            $shapes = array_merge($shapes, bk_plan_pair_halves($ctx, array_merge($anchors, $fillers)));
        } else {
            $shapes = array_merge($shapes, bk_plan_anchor_shapes($ctx, $anchors, $fillers, $v));
            if ($v['mix'] === 'merge') $shapes = bk_plan_merge($ctx, $shapes);
        }
        $all = array_merge($all, $shapes);
    }
    foreach ($all as &$s) {
        $s['rank'] = array();
        $s['size'] = 0;
        foreach ($s['slots'] as $sl) $s['size'] += $sl['n'];
        $s['lead'] = array();
        $nd = count($ctx['profiles'][$s['slots'][0]['prof']]['fams']);
        for ($d = 0; $d < $nd; $d++) {
            $f = array();
            foreach ($s['slots'] as $sl) $f[] = $ctx['profiles'][$sl['prof']]['fams'][$d];
            $s['rank'][] = bk_plan_rank($f);
            $s['lead'][] = min(array_map('bk_plan_rank_one', $f));
        }
    }
    unset($s);
    // In a block, a target completed by a 60 comes first: it sits where the 60s end and the
    // block begins (junction), then the bigger targets first.
    usort($all, function ($a, $b) {
        return array($a['dist'], $a['rank'], $a['lead'], -$a['size']) <=> array($b['dist'], $b['rank'], $b['lead'], -$b['size']);
    });
    return $all;
}

/** Capacities of the two halves: [left, right]. */
function bk_plan_half_caps($ctx)
{
    if (!$ctx['halves']) return array($ctx['rhythm'], 0);   // outdoors: no halves
    return array(count($ctx['halves'][0]), count($ctx['halves'][1]));
}

/**
 * Anchors on their own targets (balanced), the 60s completing their free halves when that saves
 * targets, the remaining 60s on their own targets.
 */
function bk_plan_anchor_shapes($ctx, $anchors, $fillers, $v)
{
    list($cL, $cR) = bk_plan_half_caps($ctx);
    $c = $cL + $cR;
    $hMax = max($cL, $cR);

    usort($anchors, function ($a, $b) { return array_map('bk_plan_rank_one', $a['fams']) <=> array_map('bk_plan_rank_one', $b['fams']); });
    // Each anchor: k targets; h of them may carry ≤ $hMax archers (one half → a free half).
    $plans = array();
    foreach ($anchors as $p) {
        $n = count($p['ids']);
        $k = bk_plan_k($ctx, $p, $v['quota'], $v['extra'] ?? array());
        $even = bk_plan_even($n, $k);
        $h0 = 0;
        foreach ($even as $m) if ($m <= $hMax) $h0++;
        $hCap = $h0;
        if ($cR > 0) {
            for ($h = $h0 + 1; $h <= count($even); $h++) {
                $hs = min($hMax, intdiv($n, max(1, count($even))));
                if ($hs < 1 || $n - $h * $hs > (count($even) - $h) * $c) break;
                $hCap = $h;
            }
        }
        $plans[] = array('p' => $p, 'n' => $n, 'k' => count($even), 'h0' => $h0, 'hCap' => $hCap);
    }

    $fillerN = array();
    foreach ($fillers as $p) $fillerN[$p['key']] = count($p['ids']);

    // How many free halves to give to the 60s: the fewest that reach the fewest targets.
    $totalCap = 0;
    foreach ($plans as $pl) $totalCap += $pl['hCap'];
    $best = null;
    for ($a = 0; $a <= $totalCap; $a++) {
        $shapes = bk_plan_build_anchor($ctx, $plans, $fillerN, $a);
        if ($shapes === null) break;
        if ($best === null || count($shapes) < count($best)) $best = $shapes;
    }
    return $best ?? array();
}

/** Anchor shapes with $a free halves given to the 60s (null: not that many free halves). */
function bk_plan_build_anchor($ctx, $plans, $fillerN, $a)
{
    list($cL, $cR) = bk_plan_half_caps($ctx);
    $hMax = max($cL, $cR); $hMin = min($cL, $cR);

    // Distribute $a half-targets: natural ones first, then extra, anchors in rank order.
    $want = array();
    $left = $a;
    foreach ($plans as $i => $pl) { $x = min($left, $pl['h0']); $want[$i] = $x; $left -= $x; }
    foreach ($plans as $i => $pl) { $x = min($left, $pl['hCap'] - $want[$i]); $want[$i] += $x; $left -= $x; }
    if ($left > 0) return null;

    $shapes = array();
    $free = array();   // index in $shapes → capacity of the free half
    foreach ($plans as $i => $pl) {
        $k = $pl['k']; $n = $pl['n']; $h = $want[$i];
        if ($h <= $pl['h0']) {
            $sizes = bk_plan_even($n, $k);
        } else {
            $hs = min($hMax, intdiv($n, $k));
            $sizes = array_merge(bk_plan_even($n - $h * $hs, $k - $h), array_fill(0, $h, $hs));
        }
        rsort($sizes);
        $given = 0;
        // The smallest targets first get the free halves.
        $order = array_keys($sizes);
        usort($order, function ($x, $y) use ($sizes) { return $sizes[$x] <=> $sizes[$y]; });
        $isHalf = array();
        foreach ($order as $j) {
            if ($given < $h && $sizes[$j] <= $hMax) { $isHalf[$j] = true; $given++; }
        }
        foreach ($sizes as $j => $m) {
            $shapes[] = array('dist' => $pl['p']['dist'], 'slots' => array(array('prof' => $pl['p']['key'], 'n' => $m, 'side' => null)));
            if (!empty($isHalf[$j])) $free[count($shapes) - 1] = $m <= $hMin ? $hMax : $hMin;
        }
    }

    // Fill the free halves with the 60s (one profile per half, the biggest profile first).
    arsort($fillerN);
    foreach ($free as $si => $cap) {
        if ($cap < 1) continue;
        arsort($fillerN);
        $key = key($fillerN);
        if ($key === null || $fillerN[$key] <= 0) break;
        $n = min($cap, $fillerN[$key]);
        $fillerN[$key] -= $n;
        $anchorN = $shapes[$si]['slots'][0]['n'];
        // The anchor in the smaller half when it fits there, so that the 60s get the bigger one.
        if ($cL === $cR) $anchorSide = 0;
        else $anchorSide = $anchorN <= $hMin ? ($cL < $cR ? 0 : 1) : ($cL >= $cR ? 0 : 1);
        $shapes[$si]['slots'][0]['side'] = $anchorSide;
        $shapes[$si]['slots'][] = array('prof' => $key, 'n' => $n, 'side' => 1 - $anchorSide);
    }

    // Remaining 60s: their own targets.
    return array_merge($shapes, bk_plan_filler_shapes($ctx, $fillerN));
}

/** Targets of the 60s alone: balanced for a single profile, halves paired otherwise. */
function bk_plan_filler_shapes($ctx, $fillerN)
{
    $fillerN = array_filter($fillerN);
    if (!$fillerN) return array();
    list($cL, $cR) = bk_plan_half_caps($ctx);
    $shapes = array();
    if (count($fillerN) === 1) {
        $key = key($fillerN);
        $p = $ctx['profiles'][$key];
        foreach (bk_plan_even($fillerN[$key], (int) ceil($fillerN[$key] / max(1, $p['cap']))) as $n) {
            $shapes[] = array('dist' => $p['dist'], 'slots' => array(array('prof' => $key, 'n' => $n, 'side' => null)));
        }
        return $shapes;
    }
    while (array_sum($fillerN) > 0) {
        arsort($fillerN);
        $key = key($fillerN);
        $p = $ctx['profiles'][$key];
        $nl = min($cL, $fillerN[$key]); $fillerN[$key] -= $nl;
        $slots = array(array('prof' => $key, 'n' => $nl, 'side' => 0));
        arsort($fillerN);
        $k2 = key($fillerN);
        if ($cR > 0 && $k2 !== null && $fillerN[$k2] > 0) {
            $nr = min($cR, $fillerN[$k2]); $fillerN[$k2] -= $nr;
            if ($k2 === $key) $slots = array(array('prof' => $key, 'n' => $nl + $nr, 'side' => null));
            else $slots[] = array('prof' => $k2, 'n' => $nr, 'side' => 1);
        }
        $shapes[] = array('dist' => $p['dist'], 'slots' => $slots);
        $fillerN = array_filter($fillerN);
    }
    return $shapes;
}

/** Can two half profiles share a target (each distance), and does it mix 40 and triple? */
function bk_plan_pair_ok($ctx, $p, $q)
{
    $mixed = false;
    foreach ($p['fams'] as $d => $f) {
        $g = $q['fams'][$d] ?? $f;
        $by = array('A' => $f, 'B' => $g);
        $r = bk_target_layout($ctx['disc'], $ctx['rhythm'], $by);
        if (!$r['ok']) return null;
        $mixed = $mixed || $r['mixed40tri'];
    }
    return $mixed;
}

/** Every half profile cut into halves, then paired with another profile when possible (mixing). */
function bk_plan_pair_halves($ctx, $profiles)
{
    list($cL, $cR) = bk_plan_half_caps($ctx);
    $halves = array();
    foreach ($profiles as $p) {
        $n = count($p['ids']);
        while ($n > 0) { $m = min(max($cL, $cR), $n); $halves[] = array('prof' => $p['key'], 'n' => $m); $n -= $m; }
    }
    $shapes = array();
    $used = array();
    foreach ($halves as $i => $h) {
        if (isset($used[$i])) continue;
        $used[$i] = true;
        $p = $ctx['profiles'][$h['prof']];
        $mate = null;
        foreach (array(true, false) as $other) {
            foreach ($halves as $j => $g) {
                if (isset($used[$j]) || ($g['prof'] !== $h['prof']) !== $other) continue;
                if ($g['n'] > $cR && $h['n'] > $cR) continue;
                if (bk_plan_pair_ok($ctx, $p, $ctx['profiles'][$g['prof']]) === null) continue;
                $mate = $j; break 2;
            }
        }
        if ($mate === null || $cR < 1) {
            $shapes[] = array('dist' => $p['dist'], 'slots' => array(array('prof' => $h['prof'], 'n' => $h['n'], 'side' => null)));
            continue;
        }
        $used[$mate] = true;
        $g = $halves[$mate];
        if ($g['prof'] === $h['prof']) {
            $shapes[] = array('dist' => $p['dist'], 'slots' => array(array('prof' => $h['prof'], 'n' => $h['n'] + $g['n'], 'side' => null)));
        } else {
            $big = $h['n'] >= $g['n'];
            $shapes[] = array('dist' => $p['dist'], 'slots' => array(
                array('prof' => $h['prof'], 'n' => $h['n'], 'side' => $big ? 0 : 1),
                array('prof' => $g['prof'], 'n' => $g['n'], 'side' => $big ? 1 : 0)));
        }
    }
    return $shapes;
}

/** When targets are short: two targets that each use one half are merged (mixing if needed). */
function bk_plan_merge($ctx, $shapes)
{
    list($cL, $cR) = bk_plan_half_caps($ctx);
    $hMax = max($cL, $cR);
    $need = count($shapes) - count($ctx['free']);
    if ($need <= 0 || $cR < 1) return $shapes;
    $single = array();
    foreach ($shapes as $i => $s) {
        if (count($s['slots']) === 1 && $s['slots'][0]['side'] === null && $s['slots'][0]['n'] <= $hMax
            && $ctx['profiles'][$s['slots'][0]['prof']]['kind'] !== 'whole') $single[] = $i;
    }
    $drop = array();
    for ($x = 0; $x < count($single) && $need > 0; $x++) {
        $i = $single[$x];
        if (isset($drop[$i])) continue;
        for ($y = $x + 1; $y < count($single); $y++) {
            $j = $single[$y];
            if (isset($drop[$j])) continue;
            $a = $shapes[$i]['slots'][0]; $b = $shapes[$j]['slots'][0];
            if ($a['n'] > $cL && $b['n'] > $cL) continue;
            if (bk_plan_pair_ok($ctx, $ctx['profiles'][$a['prof']], $ctx['profiles'][$b['prof']]) === null) continue;
            if ($a['prof'] === $b['prof']) {
                $shapes[$i]['slots'] = array(array('prof' => $a['prof'], 'n' => $a['n'] + $b['n'], 'side' => null));
            } else {
                $shapes[$i]['slots'] = array(array('prof' => $a['prof'], 'n' => $a['n'], 'side' => 0), array('prof' => $b['prof'], 'n' => $b['n'], 'side' => 1));
            }
            $drop[$j] = true; $need--;
            break;
        }
    }
    return array_values(array_diff_key($shapes, $drop));
}

/**
 * Does the field setting of target $t accept profile $p? HARD constraint: better leave an archer
 * unplaced (reported as incompatible) than put them on a target the field cannot take them on.
 * A setting NOT declared imposes nothing — never invent a constraint the organiser did not set.
 */
function bk_plan_caps_ok($ctx, $t, $p)
{
    $c = $ctx['caps'][$t] ?? null;
    if (!$c) return true;
    foreach ($p['dist'] as $m) {
        if (!empty($c['min']) && $m < $c['min']) return false;
        if (!empty($c['max']) && $m > $c['max']) return false;
    }
    if (!empty($c['fams'])) {
        $allowed = array_map('strval', $c['fams']);
        foreach ($p['fams'] as $f) if (!in_array((string) $f, $allowed, true)) return false;
    }
    return true;
}

/** Shapes on the free targets, in order: each target takes the first shape it accepts. */
function bk_plan_number($ctx, $shapes)
{
    $out = array();
    foreach ($ctx['free'] as $t) {
        foreach ($shapes as $i => $s) {
            $ok = true;
            foreach ($s['slots'] as $sl) if (!bk_plan_caps_ok($ctx, $t, $ctx['profiles'][$sl['prof']])) { $ok = false; break; }
            if (!$ok) continue;
            $s['t'] = $t;
            $out[] = $s;
            unset($shapes[$i]);
            break;
        }
        if (!$shapes) break;
    }
    return $out;
}

/**
 * Archers into the numbered shapes: "with someone" clusters first, then club by club (the
 * target where the club has the fewest archers), then swaps to respect the club quota; letters
 * last (letter wishes).
 */
function bk_plan_fill($ctx, $shapes)
{
    $max = $ctx['max'];
    // Remaining room per shape and profile, and clubs per shape.
    $room = array(); $clubs = array(); $members = array();
    foreach ($shapes as $si => $s) {
        foreach ($s['slots'] as $k => $sl) $room[$si][$k] = $sl['n'];
        $clubs[$si] = array();
        $members[$si] = array();
    }
    $slotOf = function ($si, $prof) use ($shapes, &$room) {
        foreach ($shapes[$si]['slots'] as $k => $sl) if ($sl['prof'] === $prof && $room[$si][$k] > 0) return $k;
        return null;
    };
    $put = function ($si, $k, $id) use (&$room, &$clubs, &$members, $ctx) {
        $room[$si][$k]--;
        $c = $ctx['archers'][$id]['club'];
        $clubs[$si][$c] = ($clubs[$si][$c] ?? 0) + 1;
        $members[$si][$k][] = $id;
    };

    // "With someone" clusters (union-find over the archers to place).
    $root = array();
    $find = function ($x) use (&$root) { while (isset($root[$x]) && $root[$x] !== $x) $x = $root[$x]; return $x; };
    foreach ($ctx['archers'] as $id => $a) {
        if (!isset($root[$id])) $root[$id] = $id;
        if ($a['with'] === null || !isset($ctx['archers'][$a['with']])) continue;
        if (!isset($root[$a['with']])) $root[$a['with']] = $a['with'];
        $ra = $find($id); $rb = $find($a['with']);
        if ($ra !== $rb) $root[$ra] = $rb;
    }
    $clusters = array();
    foreach ($ctx['archers'] as $id => $a) $clusters[$find($id)][] = $id;
    $clusters = array_filter($clusters, function ($c) { return count($c) > 1; });
    uasort($clusters, function ($x, $y) { return count($y) <=> count($x); });
    $done = array();
    foreach ($clusters as $ids) {
        $need = array(); $byClub = array();
        foreach ($ids as $id) {
            $need[$ctx['archers'][$id]['prof']] = ($need[$ctx['archers'][$id]['prof']] ?? 0) + 1;
            $byClub[$ctx['archers'][$id]['club']] = ($byClub[$ctx['archers'][$id]['club']] ?? 0) + 1;
        }
        foreach ($shapes as $si => $s) {
            $ok = true;
            foreach ($need as $prof => $n) {
                $r = 0;
                foreach ($s['slots'] as $k => $sl) if ($sl['prof'] === $prof) $r += $room[$si][$k];
                if ($r < $n) { $ok = false; break; }
            }
            foreach ($byClub as $c => $n) if (($clubs[$si][$c] ?? 0) + $n > $max) $ok = false;
            if (!$ok) continue;
            foreach ($ids as $id) { $put($si, $slotOf($si, $ctx['archers'][$id]['prof']), $id); $done[$id] = true; }
            break;
        }
        if (isset($done[$ids[0]])) continue;

        // The cluster does not fit on one target (room, club quota): the ANCHOR first, the one the
        // others asked for. They are the one who must stay — otherwise the askers end up together
        // without the person they asked for, which pleases nobody. The anchor goes where the most
        // askers can join them; the others are served with everybody else.
        $cited = array();
        foreach ($ids as $id) {
            $w = $ctx['archers'][$id]['with'];
            if ($w !== null) $cited[$w] = ($cited[$w] ?? 0) + 1;
        }
        arsort($cited);
        $anchor = key($cited);
        $askers = array_values(array_filter($ids, function ($id) use ($ctx, $anchor) { return $ctx['archers'][$id]['with'] === $anchor; }));
        $others = array_values(array_diff($ids, array($anchor), $askers));
        $bestSi = null; $bestTake = array();
        foreach ($shapes as $si => $s) {
            if ($slotOf($si, $ctx['archers'][$anchor]['prof']) === null) continue;
            $c0 = $ctx['archers'][$anchor]['club'];
            if (($clubs[$si][$c0] ?? 0) >= $max) continue;
            // Who can join the anchor here, askers first: room per profile and club quota.
            $room2 = array(); $clubs2 = $clubs[$si];
            foreach ($s['slots'] as $k => $sl) $room2[$sl['prof']] = ($room2[$sl['prof']] ?? 0) + $room[$si][$k];
            $room2[$ctx['archers'][$anchor]['prof']]--;
            $clubs2[$c0] = ($clubs2[$c0] ?? 0) + 1;
            $take = array();
            foreach (array_merge($askers, $others) as $id) {
                $a = $ctx['archers'][$id];
                if (($room2[$a['prof']] ?? 0) < 1 || ($clubs2[$a['club']] ?? 0) >= $max) continue;
                $room2[$a['prof']]--; $clubs2[$a['club']] = ($clubs2[$a['club']] ?? 0) + 1;
                $take[] = $id;
            }
            $n = count(array_intersect($take, $askers));
            if ($bestSi === null || $n > count(array_intersect($bestTake, $askers))) { $bestSi = $si; $bestTake = $take; }
        }
        if ($bestSi === null) continue;
        foreach (array_merge(array($anchor), array_intersect($bestTake, $askers)) as $id) {
            $put($bestSi, $slotOf($bestSi, $ctx['archers'][$id]['prof']), $id);
            $done[$id] = true;
        }
    }

    // The others, club by club in turn (the biggest clubs first), profile by profile.
    foreach ($ctx['profiles'] as $prof => $p) {
        $byClub = array();
        foreach ($p['ids'] as $id) if (!isset($done[$id])) $byClub[$ctx['archers'][$id]['club']][] = $id;
        uasort($byClub, function ($x, $y) { return count($y) <=> count($x); });
        $queue = array();
        while ($byClub) {
            foreach ($byClub as $c => $list) {
                $queue[] = array_shift($byClub[$c]);
                if (!$byClub[$c]) unset($byClub[$c]);
            }
        }
        foreach ($queue as $id) {
            $c = $ctx['archers'][$id]['club'];
            $best = null; $bestKey = null;
            foreach ($shapes as $si => $s) {
                $k = $slotOf($si, $prof);
                if ($k === null) continue;
                $n = $clubs[$si][$c] ?? 0;
                // A wheelchair archer goes to a target planned with fewer than 4 archers.
                $full = 0;
                if (!empty($ctx['archers'][$id]['wheel'])) {
                    $size = 0;
                    foreach ($shapes[$si]['slots'] as $sl) $size += $sl['n'];
                    $full = $size >= 4 ? 1 : 0;
                }
                $key = array($n >= $max ? 1 : 0, $full, $n, -$room[$si][$k], $si);
                if ($best === null || $key < $bestKey) { $best = array($si, $k); $bestKey = $key; }
            }
            if ($best !== null) $put($best[0], $best[1], $id);
        }
    }

    // Club quota: swap two archers of one profile between targets when it helps.
    for ($guard = 0; $guard < 500 && bk_plan_quota_swap($ctx, $members, $clubs); $guard++);

    // Letters.
    $places = array();
    foreach ($shapes as $si => $s) {
        foreach (bk_plan_letters($ctx, $s, $members[$si]) as $id => $l) $places[$id] = array('t' => $s['t'], 'l' => $l);
    }
    return $places;
}

/** One swap of two archers of one profile that lowers a club over its quota; false: none. */
function bk_plan_quota_swap($ctx, &$members, &$clubs)
{
    $max = $ctx['max'];
    foreach ($members as $si => $bySlot) {
        foreach ($bySlot as $k => $ids) {
            foreach ($ids as $x => $id) {
                $c = $ctx['archers'][$id]['club'];
                if (($clubs[$si][$c] ?? 0) <= $max) continue;
                $prof = $ctx['archers'][$id]['prof'];
                foreach ($members as $sj => $bySlot2) {
                    if ($sj === $si || ($clubs[$sj][$c] ?? 0) >= $max) continue;
                    foreach ($bySlot2 as $k2 => $ids2) {
                        foreach ($ids2 as $y => $id2) {
                            $c2 = $ctx['archers'][$id2]['club'];
                            if ($ctx['archers'][$id2]['prof'] !== $prof || $c2 === $c) continue;
                            if (($clubs[$si][$c2] ?? 0) >= $max) continue;
                            $members[$si][$k][$x] = $id2;
                            $members[$sj][$k2][$y] = $id;
                            $clubs[$si][$c]--; $clubs[$si][$c2] = ($clubs[$si][$c2] ?? 0) + 1;
                            $clubs[$sj][$c2]--; $clubs[$sj][$c] = ($clubs[$sj][$c] ?? 0) + 1;
                            return true;
                        }
                    }
                }
            }
        }
    }
    return false;
}

/** Letters of one target: halves for the slots, letter wishes first. Returns [id => letter]. */
function bk_plan_letters($ctx, $s, $members)
{
    $sides = array();
    if (count($s['slots']) === 1 || !$ctx['halves']) {
        // One family on the whole target: one half first (A, C), so that what is left is a whole
        // half that another family can still take.
        $slotLetters = array(0 => $ctx['halves'] ? array_merge($ctx['halves'][0], $ctx['halves'][1]) : $ctx['letters']);
    } else {
        // Orientation: the one keeping the most letter wishes, the planned one on a tie.
        $best = null; $bestKept = -1;
        foreach (array(false, true) as $flip) {
            $sl = array(); $fits = true; $kept = 0;
            foreach ($s['slots'] as $k => $slot) {
                $side = $slot['side'] ?? $k;
                if ($flip) $side = 1 - $side;
                $sl[$k] = $ctx['halves'][$side];
                if (count($members[$k] ?? array()) > count($sl[$k])) $fits = false;
                foreach (($members[$k] ?? array()) as $id) if (in_array($ctx['archers'][$id]['letter'], $sl[$k], true)) $kept++;
            }
            if ($fits && $kept > $bestKept) { $best = $sl; $bestKept = $kept; }
        }
        $slotLetters = $best;
    }
    $out = array();
    foreach ($slotLetters as $k => $letters) {
        $ids = $members[$k] ?? array();
        $freeL = $letters;
        // Wishes first (a "with someone" cluster is already on this target).
        foreach ($ids as $id) {
            $w = $ctx['archers'][$id]['letter'];
            $pos = array_search($w, $freeL, true);
            if ($w !== '' && $pos !== false) { $out[$id] = $w; unset($freeL[$pos]); }
        }
        $freeL = array_values($freeL);
        foreach ($ids as $id) if (!isset($out[$id])) $out[$id] = array_shift($freeL);
    }
    return $out;
}

/** Occupants of every target: [t => [letter => archer]] (archers already placed + $places). */
function bk_plan_occupants($ctx, $places)
{
    $occ = array();
    foreach ($ctx['fixed'] as $f) $occ[intval($f['t'])][$f['l']] = $f;
    foreach ($places as $id => $p) $occ[$p['t']][$p['l']] = $ctx['archers'][$id];
    return $occ;
}

/** Can archer $a go on target $t, letter $l, given its occupants? Returns null or [overQuota, mixes]. */
function bk_plan_slot_cost($ctx, $occ, $t, $l, $a)
{
    $here = $occ[$t] ?? array();
    if (isset($here[$l])) return null;
    foreach ($here as $o) if ($o['dist'] != $a['dist']) return null;
    if (!bk_plan_caps_ok($ctx, $t, array('dist' => $a['dist'], 'fams' => $a['fams']))) return null;
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

/** Second pass: the archers no shape took go wherever they still can (archers already placed' targets included). */
function bk_plan_leftovers($ctx, $places)
{
    $occ = bk_plan_occupants($ctx, $places);
    foreach ($ctx['archers'] as $id => $a) {
        if (isset($places[$id])) continue;
        $best = null; $bestKey = null;
        foreach ($ctx['targets'] as $t) {
            foreach ($ctx['letters'] as $l) {
                $cost = bk_plan_slot_cost($ctx, $occ, $t, $l, $a);
                if ($cost === null) continue;
                $key = array($cost[0], $cost[1], empty($occ[$t]) ? 1 : 0, $t, $l);
                if ($best === null || $key < $bestKey) { $best = array($t, $l); $bestKey = $key; }
            }
        }
        if ($best === null) continue;
        $places[$id] = array('t' => $best[0], 'l' => $best[1]);
        $occ[$best[0]][$best[1]] = $a;
    }
    return $places;
}

/**
 * Rules the greffe checks on every target (regulations, B.1.2), repaired once the plan is filled:
 *  - no archer ALONE on a target;
 *  - not TWO archers of one club alone on a target (they would mark each other's scores; the
 *    same reason makes the rhythm AB ask for a marker from another club, B.5.1.8);
 *  - not 4 archers on a target where an archer in a WHEELCHAIR shoots.
 * Moves only archers this plan placed (never the ones already placed), one at a time, each move
 * kept only when it does not break a rule of higher priority (club quota, physical layout).
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
        // Only archers on a target with a problem move, or archers that can join one to fix it.
        foreach ($ctx['targets'] as $t) {
            if ($t === $p['t']) continue;
            $there = bk_plan_target_issues($ctx, $occ[$t] ?? array());
            if (!array_sum($issues) && !array_sum($there)) continue;
            if (empty($occ[$t])) continue;   // never open a new target to fix one
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
        $first = reset($here);
        for ($d = 0; $d < count($first['fams']); $d++) {
            $f = array();
            foreach ($here as $o) $f[] = $o['fams'][$d] ?? $o['fams'][0];
            if (in_array('40', $f, true) && in_array('tri', $f, true)) { $mixed++; break; }
        }
    }
    return array($over, $pairs, $alone, $wheel, $mixed);
}

/** Wishes asked and kept. */
function bk_plan_wishes($ctx, $places)
{
    $where = array();
    foreach ($ctx['fixed'] as $id => $f) $where[$id] = array('t' => intval($f['t']), 'l' => $f['l']);
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

/** Counters of a plan: archers over the club quota, targets mixing 40 and triple. */
function bk_plan_counters($ctx, $places)
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
        $first = reset($occ[$t]);
        for ($d = 0; $d < count($first['fams']); $d++) {
            $f = array();
            foreach ($occ[$t] as $o) $f[] = $o['fams'][$d] ?? $o['fams'][0];
            if (in_array('40', $f, true) && in_array('tri', $f, true)) { $mixed++; break; }
        }
    }
    return array('over' => $over, 'mixed' => $mixed, 'alone' => $alone, 'pairs' => $pairs, 'wheel' => $wheel,
                 'targets' => count($touched), 'occ' => $occ, 'touched' => $touched);
}

/** Score of a plan on the priorities (smaller is better). */
function bk_plan_score($ctx, $places)
{
    $k = bk_plan_counters($ctx, $places);
    // Balance: archers per target of one profile alone, gap beyond 1.
    $groups = array();
    foreach (array_keys($k['touched']) as $t) {
        $profs = array();
        foreach ($k['occ'][$t] as $o) $profs[implode('-', $o['dist']) . '#' . implode('/', $o['fams'])] = true;
        if (count($profs) === 1) $groups[key($profs)][] = count($k['occ'][$t]);
    }
    $imbalance = 0;
    foreach ($groups as $n) $imbalance += max(0, max($n) - min($n) - 1);
    $w = bk_plan_wishes($ctx, $places);
    // Targets filled (2 archers or more) missing to reach the minimum of the regulations.
    $filled = 0;
    foreach (array_keys($k['touched']) as $t) if (count($k['occ'][$t]) >= 2) $filled++;
    $short = max(0, min($ctx['minTargets'], count($ctx['free'])) - $filled);
    return array(count($ctx['archers']) - count($places), $short, $k['over'], $k['pairs'], $k['alone'], $k['wheel'],
                 $k['mixed'], $k['targets'], $imbalance, $w['asked'] - $w['kept']);
}

function bk_plan_result($ctx, $places)
{
    $k = bk_plan_counters($ctx, $places);
    $unplaced = array();
    foreach (array_keys($ctx['archers']) as $id) if (!isset($places[$id])) $unplaced[] = $id;
    return array('places' => $places, 'unplaced' => $unplaced, 'overQuota' => $k['over'],
                 'mixed40tri' => $k['mixed'], 'alone' => $k['alone'], 'sameClubPairs' => $k['pairs'],
                 'wheelchairFull' => $k['wheel'], 'wishes' => bk_plan_wishes($ctx, $places));
}
