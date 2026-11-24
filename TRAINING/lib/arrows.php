<?php
/**
 * Arrows of the simulated competitors, written the way the core stores them.
 *
 * An arrow is one letter of the core's table ($LetterPoint, Common/Lib/ArrTargets.inc.php),
 * and only the letters of the face being shot are used (GetGoodLettersFromTgtId()), so a
 * drawn end is always one a scorer could have written: no 9 on a 3D animal, no 3 on an
 * indoor triple spot. Totals are reached exactly: a level gives a total, the arrows are
 * then drawn around the average it means and adjusted one ring at a time.
 *
 * Draws are repeatable: each one has its own seeded generator, so that showing a match
 * again, or another browser asking for the same end, gives the same arrows.
 */

/**
 * A repeatable random generator.
 *
 * @param string $seed
 * @return callable Returns a float in [0, 1) at each call.
 */
function trn_rng($seed) {
    $state = crc32((string)$seed) ^ 0x5bd1e995;
    if ($state === 0) $state = 0x12345678;
    return function () use (&$state) {
        // xorshift32, kept on 32 bits.
        $state ^= ($state << 13) & 0xFFFFFFFF;
        $state ^= ($state >> 17);
        $state ^= ($state << 5) & 0xFFFFFFFF;
        $state &= 0xFFFFFFFF;
        return $state / 4294967296;
    };
}

/**
 * A normal draw (Box-Muller) from a generator.
 *
 * @param callable $rnd
 * @return float
 */
function trn_rng_normal($rnd) {
    $u = max($rnd(), 1e-9);
    $v = $rnd();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

/**
 * The letters of a face and their values.
 *
 * @param int $tarId Targets.TarId.
 * @return array letter => value, every letter the face allows (the miss always included).
 */
function trn_face_letters($tarId) {
    static $cache = [];
    $tarId = intval($tarId);
    if (isset($cache[$tarId])) return $cache[$tarId];
    require_once 'Common/Lib/ArrTargets.inc.php';
    global $LetterPoint;
    $out = [];
    foreach (GetGoodLettersFromTgtId($tarId) as $l) {
        if (!isset($LetterPoint[$l])) continue;
        $p = (string)$LetterPoint[$l]['P'];
        // Letters marked with $ are the core's variants of a ring (special faces), and a
        // non-miss worth nothing is not an arrow anybody aims at.
        if (strpos($p, '$') !== false) continue;
        $n = intval($LetterPoint[$l]['N']);
        if ($n === 0 && $l !== 'A') continue;
        $out[$l] = $n;
    }
    if (!$out) $out = ['A' => 0];
    return $cache[$tarId] = $out;
}

/**
 * The highest value of a face.
 *
 * @param array $letters From trn_face_letters().
 * @return int
 */
function trn_face_max($letters) {
    return $letters ? max($letters) : 0;
}

/**
 * Letters grouped by value, the plain ring first and the inner ring (X) after it.
 *
 * @param array $letters letter => value.
 * @return array value => [letters], values ascending.
 */
function trn_face_by_value($letters) {
    global $LetterPoint;
    $out = [];
    foreach ($letters as $l => $v) $out[$v][] = $l;
    foreach ($out as $v => $ls) {
        usort($ls, function ($a, $b) use ($LetterPoint) {
            $xa = ($LetterPoint[$a]['P'] ?? '') === 'X' ? 1 : 0;
            $xb = ($LetterPoint[$b]['P'] ?? '') === 'X' ? 1 : 0;
            return $xa <=> $xb;
        });
        $out[$v] = $ls;
    }
    ksort($out);
    return $out;
}

/**
 * Values that add up exactly to a total, drawn around the average it means.
 *
 * @param int[] $values Values allowed, any order.
 * @param int $n Number of arrows.
 * @param int $total Wanted total, brought back within what n arrows can make.
 * @param callable $rnd
 * @return int[]
 */
function trn_values_for_total($values, $n, $total, $rnd) {
    $n = intval($n);
    if ($n <= 0) return [];
    $values = array_values(array_unique(array_map('intval', $values)));
    sort($values);
    $min = $values[0];
    $max = $values[count($values) - 1];
    $total = max($n * $min, min($n * $max, intval($total)));
    $mean = $total / $n;
    // Spread per arrow: tight near the top of the face, wider below, as archers are.
    $sd = max(0.45, min(2.5, ($max - $mean) * 0.75));
    $snap = function ($x) use ($values) {
        $best = $values[0];
        foreach ($values as $v) if (abs($v - $x) < abs($best - $x)) $best = $v;
        return $best;
    };
    $next = function ($v, $dir) use ($values) {
        $i = array_search($v, $values, true);
        $j = $i + $dir;
        return ($j >= 0 && $j < count($values)) ? $values[$j] : null;
    };
    $arr = [];
    for ($i = 0; $i < $n; $i++) $arr[] = $snap($mean + $sd * trn_rng_normal($rnd));

    $sum = array_sum($arr);
    $best = $arr;
    $bestGap = abs($total - $sum);
    for ($guard = 0; $sum !== $total && $guard < 20000; $guard++) {
        $dir = $sum < $total ? 1 : -1;
        // Move an arrow that sits on the wrong side of the average first, so that the
        // ends keep their shape instead of piling up at the top or the bottom.
        $i = intval($rnd() * $n);
        for ($k = 0; $k < 3; $k++) {
            $j = intval($rnd() * $n);
            if (($dir > 0 && $arr[$j] < $arr[$i]) || ($dir < 0 && $arr[$j] > $arr[$i])) $i = $j;
        }
        $to = $next($arr[$i], $dir);
        if ($to === null) continue;
        $sum += $to - $arr[$i];
        $arr[$i] = $to;
        if (abs($total - $sum) < $bestGap) {
            $bestGap = abs($total - $sum);
            $best = $arr;
        }
    }
    if ($sum === $total) return $arr;
    // Values with gaps (3D: 0, 5, 8, 10, 11) can defeat the ring-by-ring adjustment:
    // an exact composition is then searched, and the arrows keep the order drawn.
    $exact = trn_values_exact($values, $n, $total);
    if (!$exact) return $best;
    for ($i = count($exact) - 1; $i > 0; $i--) {
        $j = intval($rnd() * ($i + 1));
        $t = $exact[$i]; $exact[$i] = $exact[$j]; $exact[$j] = $t;
    }
    return $exact;
}

/**
 * n values adding up exactly to a total, as close to each other as possible
 * (dynamic programming over the number of arrows and the sum).
 *
 * @param int[] $values Allowed values, ascending.
 * @param int $n
 * @param int $total
 * @return int[]|null Null when no composition exists.
 */
function trn_values_exact($values, $n, $total) {
    $mean = $total / max(1, $n);
    // Values nearest the average first, so that the composition found stays even.
    $order = $values;
    usort($order, function ($a, $b) use ($mean) { return abs($a - $mean) <=> abs($b - $mean); });
    $from = [0 => [0 => -1]];
    for ($k = 1; $k <= $n; $k++) {
        $from[$k] = [];
        foreach ($from[$k - 1] as $sum => $unused) {
            foreach ($order as $v) {
                $t = $sum + $v;
                if ($t > $total || isset($from[$k][$t])) continue;
                // The rest must still be reachable with the arrows left.
                if ($t + ($n - $k) * $values[count($values) - 1] < $total) continue;
                $from[$k][$t] = $v;
            }
        }
    }
    if (!isset($from[$n][$total])) return null;
    $out = [];
    for ($k = $n, $t = $total; $k > 0; $k--) {
        $v = $from[$k][$t];
        $out[] = $v;
        $t -= $v;
    }
    return $out;
}

/**
 * Letters for a list of values: the plain ring, or the inner one (X) for a share of them.
 *
 * @param int[] $vals
 * @param array $byValue From trn_face_by_value().
 * @param callable $rnd
 * @param float $inner Share of the arrows on a value with an inner ring that get it.
 * @return string
 */
function trn_values_to_letters($vals, $byValue, $rnd, $inner = 0.35) {
    $out = '';
    foreach ($vals as $v) {
        $ls = $byValue[$v] ?? ['A'];
        $out .= (count($ls) > 1 && $rnd() < $inner) ? $ls[count($ls) - 1] : $ls[0];
    }
    return $out;
}

/**
 * Arrows reaching a total on a face.
 *
 * @param array $letters From trn_face_letters().
 * @param int $n
 * @param int $total
 * @param callable $rnd
 * @return string Letters.
 */
function trn_arrows_for_total($letters, $n, $total, $rnd) {
    $byValue = trn_face_by_value($letters);
    return trn_values_to_letters(trn_values_for_total(array_keys($byValue), $n, $total, $rnd), $byValue, $rnd);
}

/**
 * Arrows reaching a total with a given number of golds and of X/9, as an athlete's
 * real qualification gives them (World Archery publishes only these three figures).
 * The rings counted are those of the competition (gold and X/9 characters); when
 * they cannot be met with the total, the total alone is kept.
 *
 * @param array $letters
 * @param int $n
 * @param int $total
 * @param int $golds
 * @param int $xnines
 * @param string $goldChars Letters counted as golds.
 * @param string $xChars Letters counted as X/9.
 * @param callable $rnd
 * @return string
 */
function trn_arrows_counted($letters, $n, $total, $golds, $xnines, $goldChars, $xChars, $rnd) {
    $gSet = array_values(array_intersect(str_split($goldChars), array_keys($letters))); // bytes: arrow letters are ASCII.
    $xSet = array_values(array_intersect(str_split($xChars), array_keys($letters))); // bytes: arrow letters are ASCII.
    $both = array_values(array_intersect($gSet, $xSet));
    $gOnly = array_values(array_diff($gSet, $xSet));
    $xOnly = array_values(array_diff($xSet, $gSet));
    $top = function ($set) use ($letters) {
        if (!$set) return null;
        usort($set, function ($a, $b) use ($letters) { return $letters[$b] <=> $letters[$a]; });
        return $set[0];
    };
    $fixed = [];
    if ($both) {
        $nb = min($xnines, $golds);
        $fixed = array_merge($fixed, array_fill(0, max(0, $nb), $top($both)));
        if ($golds - $nb > 0) $fixed = array_merge($fixed, array_fill(0, $golds - $nb, $top($gOnly) ?? $top($both)));
        if ($xnines - $nb > 0 && $xOnly) $fixed = array_merge($fixed, array_fill(0, $xnines - $nb, $top($xOnly)));
    } else {
        if ($golds > 0 && $gOnly) $fixed = array_merge($fixed, array_fill(0, $golds, $top($gOnly)));
        if ($xnines > 0 && $xOnly) $fixed = array_merge($fixed, array_fill(0, $xnines, $top($xOnly)));
    }
    $rest = $n - count($fixed);
    $restLetters = array_diff_key($letters, array_flip(array_merge($gSet, $xSet)));
    $restTotal = $total;
    foreach ($fixed as $l) $restTotal -= $letters[$l];
    $ok = $rest >= 0 && $restLetters;
    if ($ok) {
        $vals = array_values($restLetters);
        $ok = $restTotal >= $rest * min($vals) && $restTotal <= $rest * max($vals);
    }
    if (!$ok) return trn_arrows_for_total($letters, $n, $total, $rnd);

    $out = str_split(implode('', $fixed) . ($rest > 0 ? trn_arrows_for_total($restLetters, $rest, $restTotal, $rnd) : '')); // bytes: arrow letters are ASCII.
    // Shuffle (Fisher-Yates) with the same generator.
    for ($i = count($out) - 1; $i > 0; $i--) {
        $j = intval($rnd() * ($i + 1));
        $t = $out[$i]; $out[$i] = $out[$j]; $out[$j] = $t;
    }
    return implode('', $out);
}

/**
 * Value of a string of letters.
 *
 * @param string $s
 * @return int
 */
function trn_letters_value($s) {
    global $LetterPoint;
    require_once 'Common/Lib/ArrTargets.inc.php';
    $t = 0;
    // bytes: arrow letters are ASCII.
    foreach (str_split(rtrim((string)$s)) as $l) {
        $u = strtoupper($l);
        if (isset($LetterPoint[$u])) $t += intval($LetterPoint[$u]['N']);
    }
    return $t;
}

/**
 * Letters of a list of arrows written as on a scorecard ("X", "10", "M", "9*"), the
 * way World Archery publishes them. A star marks the arrow closest to the centre.
 *
 * @param string[] $tokens
 * @param array $letters Face letters.
 * @param bool $closest Set when one of the arrows carries a star.
 * @return string Letters, a space for an arrow that was not shot.
 */
function trn_letters_from_print($tokens, $letters, &$closest = false) {
    require_once 'Common/Lib/ArrTargets.inc.php';
    $closest = false;
    $out = '';
    foreach ($tokens as $tok) {
        $tok = trim((string)$tok);
        if ($tok === '') { $out .= ' '; continue; }
        if (strpos($tok, '*') !== false) {
            $closest = true;
            $tok = str_replace('*', '', $tok);
        }
        $l = GetLetterFromPrint($tok, $letters);
        if ($l === ' ' && is_numeric($tok)) {
            // A value the face does not know under that name: the letter of the same value.
            foreach ($letters as $k => $v) if ($v === intval($tok)) { $l = $k; break; }
        }
        $out .= strtoupper($l); // bytes: arrow letters are ASCII.
    }
    return $out;
}
