<?php
/**
 * lib/cohabitation.php — target faces sharing one target (M7).
 *
 * Federation rules provided (see REGLES_COHABITATION.md). UNIFIED model that reproduces every
 * combination: each target has a physical BUDGET and each face a COST (share of the target it
 * takes); a target is valid when Σcosts ≤ budget AND number of archers ≤ rhythm
 * (SesAth4Target).
 *
 *   18 m (buttress 4×40cm): budget 4 — 40cm→1, 60cm→2, 80→4.
 *   Outdoor (80 face)     : budget 3 — reduced 80→1, full (60/80/122)→3.
 *
 * An unknown face costs the whole target (= budget) → never overfilled: when in doubt, the
 * target takes a single archer.
 *
 * Pure (no write): used by the admission check at registration (gauge per category/face +
 * refusal when there is no room left) AND by the eligibility of the placement.
 * Courses (groups): a separate model (size/club quota/balance), for later.
 */

if (defined('BK_COHAB_LOADED')) return;
define('BK_COHAB_LOADED', true);

/** Disciplines where the sharing of faces follows firm rules. */
function bk_cohabit_enabled($disc)
{
    return $disc === 'ext' || $disc === 'salle';
}

/** Physical budget of a target (in cost units). Override: config.local.json → cohabitation.budget. */
function bk_cohabit_budget($disc)
{
    $ov = bk_cohabit_conf('budget', $disc);
    if ($ov !== null) return max(1, intval($ov));
    switch ($disc) {
        case 'salle': return 4;   // 18 m: up to 4 faces of 40cm on the buttress
        case 'ext':   return 3;   // outdoor: 3 reduced 80 or 1 full face
        default:      return 1;
    }
}

/** Small access to the overrides of config.local.json (optional, never required). */
function bk_cohabit_conf($key, $disc)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = array();
        $f = dirname(__DIR__) . '/config.local.json';
        if (is_file($f)) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j) && isset($j['cohabitation']) && is_array($j['cohabitation'])) $cfg = $j['cohabitation'];
        }
    }
    if (isset($cfg[$key][$disc])) return $cfg[$key][$disc];
    if (isset($cfg[$key]) && !is_array($cfg[$key])) return $cfg[$key];
    return null;
}

/** Lower case without accents, to analyse a face name whatever its encoding. */
function bk_face_norm($s)
{
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $from = array('à','â','ä','é','è','ê','ë','î','ï','ô','ö','ù','û','ü','ç');
    $to   = array('a','a','a','e','e','e','e','i','i','o','o','u','u','u','c');
    return str_replace($from, $to, $s);
}

/**
 * Classifies a face from its name (TargetFaces.TfName, federation set).
 * Returns ['dia'=>40|60|80|122|0, 'type'=>'mono'|'tri'|'full'|'reduit'|'peg'|'', 'raw'=>name].
 */
function bk_face_class($tfName)
{
    $n = bk_face_norm($tfName);
    $type = '';
    if (strpos($n, 'piquet') !== false)                        $type = 'peg';
    elseif (strpos($n, 'reduit') !== false)                    $type = 'reduit';
    elseif (strpos($n, 'trispot') !== false)                   $type = 'tri';
    elseif (strpos($n, 'monospot') !== false || strpos($n, 'unique') !== false) $type = 'mono';
    elseif (strpos($n, 'complet') !== false || strpos($n, 'classique') !== false
         || strpos($n, 'poulie') !== false || strpos($n, 'blason') !== false)   $type = 'full';

    // Diameter: first plausible token. Numeric bounds (not \b, which fails on "40cm" since
    // digits and letters are all word characters); the zone ranges "6-10"/"5-10" do not
    // match (5/6/10 are not diameters).
    $dia = 0;
    if (preg_match('/(?<!\d)(122|80|60|40)(?!\d)/', $n, $m)) $dia = intval($m[1]);

    return array('dia' => $dia, 'type' => $type, 'raw' => (string) $tfName);
}

/** Class of a face from its TfId (TargetFaces read + cache per competition). */
function bk_face_class_by_id($tourId, $tfId)
{
    static $cache = array();
    $tfId = intval($tfId);
    $key  = intval($tourId) . ':' . $tfId;
    if (!isset($cache[$key])) {
        $name = '';
        if ($tfId > 0) {
            $r = safe_fetch(safe_r_sql("SELECT TfName FROM TargetFaces
                WHERE TfId = $tfId AND TfTournament = " . intval($tourId)));
            if ($r) $name = $r->TfName;
        }
        $cache[$key] = bk_face_class($name);
    }
    return $cache[$key];
}

/** Cost of a PLACED face (share of the target it takes) by discipline. */
function bk_face_cost($class, $disc)
{
    $b = bk_cohabit_budget($disc);
    if (!is_array($class)) return $b;
    $dia  = intval($class['dia'] ?? 0);
    $type = (string) ($class['type'] ?? '');

    if ($disc === 'ext') {
        return ($type === 'reduit') ? 1 : $b;    // reduced = 1/3; full face = the whole target
    }
    if ($disc === 'salle') {                      // 18 m: by diameter
        if ($dia && $dia <= 40) return 1;
        if ($dia && $dia <= 60) return 2;
        return $b;                                // 80 (or unknown) = the whole target
    }
    return $b;
}

/**
 * Is a face SHAREABLE (several archers of one category shoot the same face, one target, in
 * turn)? Outdoors, only the FULL faces (60/80/122) are — which is why a "one 122 face" target
 * carries several archers. Elsewhere (outdoor reduced faces, all of 18 m), each archer has their
 * own face.
 */
function bk_face_shareable($class, $disc)
{
    if ($disc === 'ext') return (string) ($class['type'] ?? '') === 'full';
    return false;
}

/** Key of a face (diameter|type) to group identical shared faces. */
function bk_face_key($class)
{
    return intval($class['dia'] ?? 0) . '|' . (string) ($class['type'] ?? '');
}

/**
 * Total cost of the PLACED FACES for a set of archers: a shareable face is counted ONCE (archers
 * of the same category shoot the same one); one face per archer otherwise.
 */
function bk_cohabit_pins_cost($faces, $disc)
{
    $seen = array();
    $cost = 0;
    foreach ($faces as $f) {
        if (bk_face_shareable($f, $disc)) {
            $k = bk_face_key($f);
            if (isset($seen[$k])) continue;       // shared face already placed
            $seen[$k] = true;
        }
        $cost += bk_face_cost($f, $disc);
    }
    return $cost;
}

/**
 * Can the set of faces $faces (one class per archer) share a target of rhythm $rhythm in this
 * discipline? (Σ costs of the placed faces ≤ budget AND number of archers ≤ rhythm.)
 */
function bk_cohabit_ok($faces, $disc, $rhythm)
{
    $rhythm = max(1, intval($rhythm));
    if (count($faces) > $rhythm) return false;
    if (!bk_cohabit_enabled($disc)) return true;
    return bk_cohabit_pins_cost($faces, $disc) <= bk_cohabit_budget($disc);
}

/**
 * How many archers with face $newFace can STILL be added to a target already carrying $current
 * (one class per archer), rhythm $rhythm? 0 = no more room for this face.
 * A shareable face already placed is joined at no cost (only the rhythm limits).
 */
function bk_cohabit_max_add($current, $newFace, $disc, $rhythm)
{
    $rhythm = max(1, intval($rhythm));
    $byCount = $rhythm - count($current);
    if ($byCount <= 0) return 0;
    if (!bk_cohabit_enabled($disc)) return $byCount;

    $b = bk_cohabit_budget($disc);

    // Joining an identical shareable face already placed: no extra cost.
    if (bk_face_shareable($newFace, $disc)) {
        $k = bk_face_key($newFace);
        foreach ($current as $f) {
            if (bk_face_shareable($f, $disc) && bk_face_key($f) === $k) return $byCount;
        }
    }

    $used = bk_cohabit_pins_cost($current, $disc);
    $cost = bk_face_cost($newFace, $disc);
    if ($cost <= 0) $cost = $b;
    $byBudget = intdiv(max(0, $b - $used), $cost);
    return max(0, min($byCount, $byBudget));
}
