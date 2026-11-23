<?php
/**
 * Placement of the archers of a training on the targets.
 *
 * A lighter version of the rules of the AUTH module (booking/lib/placement.php,
 * REGLES_PLACEMENT.md), without its club rules — a training needs neither the
 * club quota per target nor the rule against an archer alone. What is kept is
 * what the field imposes:
 *   - order: distance (shortest first), face (largest first, a single face
 *     before a reduced or triple one of the same size), bow (barebow, recurve,
 *     compound, then the other divisions in the order of ianseo), age (youngest
 *     first), sex (women first);
 *   - one distance per target, at every distance of the round;
 *   - faces that may share a target: indoors one family of faces per half of
 *     the buttress (A C on one side, B D on the other) and an 80 cm face alone;
 *     outdoors one family per target (reduced faces together, a full face shared
 *     by the archers who shoot it); no rule on courses, Beursault and Run;
 *   - a series of identical archers spread evenly (9 archers at ABCD take 3
 *     targets of 3, not 4 + 4 + 1) while the targets allow it, the letters
 *     spread too (2 archers at ABC on A and C);
 *   - archers already placed never move.
 */

/** Target types of ianseo (Targets.TarId) of a reduced or triple face. */
const TRN_REDUCED_TYPES = [2, 4, 9, 10, 32];

/** Order of the bows; the other divisions follow in the order of ianseo. */
const TRN_BOW_ORDER = ['BB' => 0, 'CL' => 1, 'CO' => 2];

/**
 * Discipline of a competition, from its type (as AUTH reads it).
 *
 * @param int $type ToType.
 * @return string 'salle', 'ext' or 'other'.
 */
function trn_disc($type) {
    $type = intval($type);
    if (in_array($type, [6, 7, 8], true)) return 'salle';
    if (in_array($type, [1, 2, 3, 4, 5], true)) return 'ext';
    return 'other';
}

/**
 * Family of a face on one distance: what decides whether two faces can share a target.
 *
 * @param string $disc
 * @param string $name TfName, used only when the type is missing.
 * @param int $t Target type (TfT<n>).
 * @param int $w Diameter in cm (TfW<n>).
 * @return string Indoors '80', '60', '60t', '40', 'tri' or '?'; outdoors 'diameter|full' or
 *                'diameter|reduced' or '?'; '*' elsewhere.
 */
function trn_face_family($disc, $name, $t, $w) {
    $t = intval($t);
    $w = intval($w);
    if ($disc === 'other') return '*';
    if ($t <= 0 || $w <= 0) {
        // No type: read the diameter and the kind from the name, as AUTH does.
        $n = mb_strtolower((string)$name);
        if (!preg_match('/(?<!\d)(122|80|60|40)(?!\d)/', $n, $m)) return '?';
        $w = intval($m[1]);
        $reduced = (bool)preg_match('/tri|r(e|é)duit|reduced|spot/u', $n);
    } else {
        $reduced = in_array($t, TRN_REDUCED_TYPES, true);
    }
    if ($disc === 'salle') {
        if ($reduced) return $w >= 60 ? '60t' : 'tri';
        if ($w >= 80) return '80';
        return $w >= 60 ? '60' : '40';
    }
    return $w . '|' . ($reduced ? 'reduced' : 'full');
}

/**
 * Size of a family for the order: [diameter, 1 if reduced or triple].
 *
 * @param string $fam
 * @return int[]
 */
function trn_fam_size($fam) {
    $map = ['80' => [80, 0], '60' => [60, 0], '60t' => [60, 1], '40' => [40, 0], 'tri' => [40, 1]];
    if (isset($map[$fam])) return $map[$fam];
    $p = explode('|', (string)$fam, 2);
    return [intval($p[0]), ($p[1] ?? '') === 'reduced' ? 1 : 0];
}

/**
 * Can these archers share one target? Each one: ['l' => letter or null, 'dist' => [metres…],
 * 'fams' => [family per distance]]. The letters matter indoors only (halves of the buttress).
 *
 * @param string $disc
 * @param int $rhythm Letters of the target.
 * @param array $people
 * @return bool
 */
function trn_target_ok($disc, $rhythm, $people) {
    if (count($people) > $rhythm) return false;
    if (count($people) < 2) return true;
    $first = reset($people);
    foreach ($people as $p) {
        if ($p['dist'] !== $first['dist']) return false;
    }
    if ($disc === 'other') return true;
    $n = 0;
    foreach ($people as $p) $n = max($n, count($p['fams']));
    for ($i = 0; $i < $n; $i++) {
        $fams = [];
        foreach ($people as $p) $fams[] = $p['fams'][$i] ?? end($p['fams']);
        if (in_array('?', $fams, true)) return false;
        $distinct = array_unique($fams);
        if ($disc === 'ext' || in_array('80', $fams, true) || $rhythm > 4) {
            if (count($distinct) > 1) return false;
            continue;
        }
        // Indoors: one family per half of the buttress, A C and B D.
        $halves = [];
        foreach ($people as $p) {
            if ($p['l'] === null) continue;
            $half = in_array($p['l'], ['A', 'C'], true) ? 0 : 1;
            $halves[$half][$p['fams'][$i] ?? end($p['fams'])] = true;
        }
        foreach ($halves as $h) if (count($h) > 1) return false;
    }
    return true;
}

/**
 * Letters for the new archers of a target, around those already placed.
 *
 * @param string $disc
 * @param int $rhythm
 * @param array $fixed People already placed (with their letter).
 * @param array $new People to place (letter ignored), in order.
 * @return array|null Index of $new => letter, or null when they cannot all be placed.
 */
function trn_target_letters($disc, $rhythm, $fixed, $new) {
    $all = [];
    for ($i = 0; $i < $rhythm; $i++) $all[] = chr(65 + $i);
    $taken = array_column($fixed, 'l');
    $free = array_values(array_diff($all, $taken));
    $k = count($new);
    if ($k === 0) return [];
    if ($k > count($free)) return null;

    $try = function ($letters) use ($disc, $rhythm, $fixed, $new) {
        $people = $fixed;
        foreach ($new as $i => $p) { $p['l'] = $letters[$i]; $people[] = $p; }
        return trn_target_ok($disc, $rhythm, $people);
    };

    // Spread first: on an empty target, k archers on evenly spaced letters (A and C at ABC).
    if (!$fixed && $k > 1 && $k < $rhythm) {
        $spread = [];
        for ($j = 0; $j < $k; $j++) $spread[] = $all[intval(round($j * ($rhythm - 1) / ($k - 1)))];
        if ($try($spread)) return $spread;
    }
    if ($try(array_slice($free, 0, $k))) return array_slice($free, 0, $k);
    // Letters only matter on the halves of an indoor buttress: a few combinations at most.
    if ($disc !== 'salle' || $rhythm > 4) return null;
    foreach (trn_permutations($free, $k) as $letters) {
        if ($try($letters)) return $letters;
    }
    return null;
}

/**
 * Ordered selections of $k items of a list.
 *
 * @param array $items
 * @param int $k
 * @return array[]
 */
function trn_permutations($items, $k) {
    if ($k === 0) return [[]];
    $out = [];
    foreach ($items as $i => $x) {
        $rest = $items;
        unset($rest[$i]);
        foreach (trn_permutations(array_values($rest), $k - 1) as $p) $out[] = array_merge([$x], $p);
    }
    return $out;
}

/**
 * Sort key of an archer, criterion by criterion.
 *
 * @param array $a
 * @return array
 */
function trn_place_key($a) {
    $face = [];
    foreach ($a['fams'] as $f) {
        $s = trn_fam_size($f);
        $face[] = -$s[0];
        $face[] = $s[1];
    }
    return [
        $a['dist'],
        $face,
        [TRN_BOW_ORDER[$a['div']] ?? 3, $a['divOrder'], $a['div']],
        $a['age'] === null ? [1, 0] : [0, $a['age']],
        $a['sex'] === 1 ? 0 : ($a['sex'] === 0 ? 1 : 2),
        $a['name'],
    ];
}

/**
 * Plan the placement of a session (pure: no database).
 *
 * @param string $disc
 * @param int $rhythm
 * @param int[] $targets Target numbers of the session, in order.
 * @param array $fixed Target => people already placed.
 * @param array $archers People to place: + 'id', 'div', 'divOrder', 'age', 'sex', 'name'.
 * @return array ['places' => [id => [target, letter]], 'left' => [id…]]
 */
function trn_plan_session($disc, $rhythm, $targets, $fixed, $archers) {
    usort($archers, function ($x, $y) { return trn_place_key($x) <=> trn_place_key($y); });

    // Series of identical archers (same distances, same families).
    $series = [];
    foreach ($archers as $a) {
        $sig = json_encode([$a['dist'], $a['fams']]);
        if (!$series || end($series)['sig'] !== $sig) $series[] = ['sig' => $sig, 'list' => []];
        $series[count($series) - 1]['list'][] = $a;
    }

    // How many of one series fit on an empty target.
    $capacity = function ($a) use ($disc, $rhythm) {
        for ($k = $rhythm; $k > 1; $k--) {
            if (trn_target_letters($disc, $rhythm, [], array_fill(0, $k, $a)) !== null) return $k;
        }
        return 1;
    };

    $empty = array_values(array_filter($targets, function ($t) use ($fixed) { return empty($fixed[$t]); }));
    $chunks = [];
    $needed = 0;
    foreach ($series as $s) {
        $cap = $capacity($s['list'][0]);
        $needed += (int)ceil(count($s['list']) / $cap);
        $chunks[] = ['list' => $s['list'], 'cap' => $cap];
    }
    $dense = $needed > count($empty);

    $new = [];          // target => people placed now
    $left = [];
    $cursor = 0;
    foreach ($chunks as $c) {
        $n = count($c['list']);
        $k = (int)ceil($n / $c['cap']);
        // Even sizes (9 on 3 targets: 3 3 3), or full targets when the field is short.
        $sizes = [];
        for ($j = 0; $j < $k; $j++) {
            $sizes[] = $dense ? min($c['cap'], $n - $j * $c['cap']) : intdiv($n, $k) + ($j < $n % $k ? 1 : 0);
        }
        $off = 0;
        foreach ($sizes as $size) {
            $group = array_slice($c['list'], $off, $size);
            $off += $size;
            if ($cursor < count($empty)) {
                $new[$empty[$cursor++]] = $group;
            } else {
                foreach ($group as $a) $left[] = $a;
            }
        }
    }

    // What did not fit on an empty target: any target where it can still go, in order.
    $still = [];
    foreach ($left as $a) {
        $done = false;
        foreach ($targets as $t) {
            $group = $new[$t] ?? [];
            $group[] = $a;
            if (trn_target_letters($disc, $rhythm, $fixed[$t] ?? [], $group) !== null) {
                $new[$t] = $group;
                $done = true;
                break;
            }
        }
        if (!$done) $still[] = $a['id'];
    }

    $places = [];
    foreach ($new as $t => $group) {
        $letters = trn_target_letters($disc, $rhythm, $fixed[$t] ?? [], $group);
        if ($letters === null) {
            foreach ($group as $a) $still[] = $a['id'];
            continue;
        }
        foreach ($group as $i => $a) $places[$a['id']] = [$t, $letters[$i]];
    }
    return ['places' => $places, 'left' => $still];
}

/**
 * Distances of a category, series by series, in metres (most specific TdClasses).
 *
 * @param int $tourId
 * @param int $type
 * @param string $div
 * @param string $cls
 * @param int $nDist
 * @return int[]
 */
function trn_category_distances($tourId, $type, $div, $cls, $nDist) {
    static $cache = [];
    $key = "$tourId|$type|$div|$cls";
    if (isset($cache[$key])) return $cache[$key];
    $out = [];
    $r = safe_fetch(safe_r_sql("SELECT * FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . " AND TdType = " . intval($type) . "
          AND " . StrSafe_DB(trim($div) . trim($cls)) . " LIKE TdClasses
        ORDER BY CHAR_LENGTH(TdClasses) DESC LIMIT 1"));
    if ($r) {
        for ($i = 1; $i <= min(8, max(1, intval($nDist))); $i++) {
            $label = trim((string)($r->{'Td' . $i} ?? ''));
            if ($label === '' || $label === '-') continue;
            $m = intval($r->{'TdDist' . $i} ?? 0);
            $out[] = $m > 0 ? $m : intval($label);
        }
    }
    return $cache[$key] = $out;
}
