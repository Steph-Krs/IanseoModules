<?php
/**
 * lib/caps.php — technical possibilities of the field, target by target.
 *
 * For each target of each departure, the organiser declares the distances and target faces it
 * can take. The assignment then follows them.
 *
 * The OFFERED distances and faces are not typed: they are read from the competition's settings
 * (TournamentDistances and TargetFaces), so always in line with what ianseo already knows.
 *
 * Default = no constraint: a target without a row in BookingTargetCaps accepts everything. A
 * competition never set up therefore behaves exactly as before this table existed.
 */

if (defined('BK_CAPS_LOADED')) return;
define('BK_CAPS_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/placement.php';   // families of faces, order of the placement

/**
 * Metres of one distance of TournamentDistances. TdDist is 0 on competitions made by a set that
 * only fills the label ("70m-1"): the label then gives the metres, as the core does in
 * DragDropTarget.
 */
function bk_td_metres($label, $dist)
{
    $m = intval($dist);
    return $m > 0 ? $m : intval(trim((string) $label));
}

/**
 * Distances used by the competition, with the categories concerned.
 * Returns [metres => ['m'=>int, 'labels'=>[...], 'classes'=>[...]]]
 */
function bk_caps_distances($tourId, $type)
{
    $rs = safe_r_sql("SELECT TdClasses, Td1, Td2, Td3, Td4, TdDist1, TdDist2, TdDist3, TdDist4
        FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . " AND TdType = " . StrSafe_DB($type));
    $out = array();
    while ($r = safe_fetch($rs)) {
        for ($i = 1; $i <= 4; $i++) {
            $lab = trim((string) $r->{'Td' . $i});
            $m   = bk_td_metres($lab, $r->{'TdDist' . $i});
            if ($lab === '' || $lab === '-' || $m <= 0) continue;
            if (!isset($out[$m])) $out[$m] = array('m' => $m, 'labels' => array(), 'classes' => array());
            $out[$m]['labels'][$lab] = true;
            $out[$m]['classes'][trim((string) $r->TdClasses)] = true;
        }
    }
    ksort($out);
    foreach ($out as &$d) {
        $d['labels']  = array_keys($d['labels']);
        $d['classes'] = array_keys($d['classes']);
    }
    return $out;
}

/**
 * Target faces defined on the competition. Returns [TfId => ['id','label','cm','who','name']]
 *  - 'cm'   : diameter (mm→cm of TfW1)
 *  - 'name' : TYPE of the face (TfName) — what really tells two faces apart
 *  - 'label': "40 cm", told apart by the TYPE when several faces share the same diameter
 *             ("40 cm · Trispot") — never an (a)/(b) mark that means nothing to the organiser.
 *  - 'who'  : ianseo categories/regex — internal data, NEVER shown (unreadable).
 */
function bk_caps_faces($tourId)
{
    // Join on Targets (TfT1 → TarId) for the "TarDescr-diameter" key that sets the face's
    // PICTURE, exactly like the PlanQualifs module.
    $rs = safe_r_sql("SELECT TF.TfId, TF.TfName, TF.TfW1, TF.TfClasses, TF.TfRegExp, T.TarDescr
        FROM TargetFaces TF
        LEFT JOIN Targets T ON T.TarId = TF.TfT1
        WHERE TF.TfTournament = " . intval($tourId) . "
        ORDER BY TF.TfW1 DESC, TF.TfId");
    $out = array();
    $parCm = array();
    while ($r = safe_fetch($rs)) {
        $cm   = intval($r->TfW1);
        $id   = intval($r->TfId);
        $name = trim((string) $r->TfName);
        // Courses: the "faces" are really coloured PEGS ("Piquet Rouge/Bleu/Blanc/Rose") →
        // marked for a dedicated rendering.
        $isPeg = (stripos($name, 'piquet') !== false);
        $parCm[$cm][] = $id;
        $out[$id] = array('id' => $id, 'cm' => $cm,
                          'who'   => trim((string) ($r->TfClasses !== '' ? $r->TfClasses : $r->TfRegExp)),
                          'name'  => $name,
                          'peg'   => $isPeg,
                          'color' => ($isPeg && function_exists('bk_peg_color')) ? bk_peg_color($name) : '',
                          'svg'   => bk_face_svg((string) ($r->TarDescr ?? ''), $cm),
                          'label' => $isPeg ? ($name !== '' ? $name : ('Piquet ' . $id))
                                            : ($cm ? ($cm . ' cm') : ($name !== '' ? $name : ('Blason ' . $id))));
    }
    // Several faces of the same diameter → told apart by TYPE (the face's name).
    foreach ($parCm as $cm => $ids) {
        if (count($ids) < 2 || !$cm) continue;
        foreach ($ids as $n => $id) {
            $type = $out[$id]['name'];
            $out[$id]['label'] = $cm . ' cm · ' . ($type !== '' ? $type : '#' . ($n + 1));
        }
    }
    // One colour per face, to tell them apart at a glance on the field page (two faces of the
    // same size included); a peg keeps its own colour. Short text: the size, or the peg colour.
    $palette = bk_face_palette();
    $n = 0;
    foreach ($out as $id => $f) {
        $out[$id]['hue'] = $f['peg'] ? $f['color'] : $palette[$n++ % count($palette)];
        $out[$id] += bk_face_tones($out[$id]['hue']);
        $out[$id]['short'] = $f['peg'] ? (trim(preg_replace('/^\s*piquet\s*/iu', '', $f['name'])) ?: $f['label'])
                                       : ($f['cm'] ? $f['cm'] . ' cm' : 'Blason');
        // Tag on a target card (narrow): the number alone beside the picture of the face.
        $out[$id]['tag'] = (!$f['peg'] && $f['cm']) ? (string) $f['cm'] : $out[$id]['short'];
    }
    return $out;
}

/**
 * Type and diameter of every face of the competition, distance by distance:
 * [TfId => ['name' => TfName, 'tw' => [[TfT1, TfW1], …]]], read once per request.
 */
function bk_caps_face_specs($tourId)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (isset($cache[$tourId])) return $cache[$tourId];
    $out = array();
    $rs = safe_r_sql("SELECT * FROM TargetFaces WHERE TfTournament = $tourId");
    while ($r = safe_fetch($rs)) {
        $tw = array();
        for ($i = 1; $i <= 8; $i++) $tw[] = array(intval($r->{'TfT' . $i} ?? 0), intval($r->{'TfW' . $i} ?? 0));
        $out[intval($r->TfId)] = array('name' => (string) $r->TfName, 'tw' => $tw);
    }
    return $cache[$tourId] = $out;
}

/**
 * What the placement needs of a face over $n distances: its family at each distance
 * (lib/placement.php) and its size for the order ([diameter, 1 if reduced or triple]).
 * An unknown face (none set) is alone on its target indoors and outdoors.
 */
function bk_caps_face_profile($tourId, $disc, $faceId, $n)
{
    static $cache = array();
    $n = max(1, intval($n));
    $k = intval($tourId) . '|' . $disc . '|' . intval($faceId) . '|' . $n;
    if (isset($cache[$k])) return $cache[$k];
    $f = bk_caps_face_specs($tourId)[intval($faceId)] ?? null;
    if (!$f) {
        return $cache[$k] = array('fams' => array_fill(0, $n, in_array($disc, array('salle', 'ext'), true) ? '?' : '*'),
                                  'fsize' => array_fill(0, $n, array(0, 0)));
    }
    $size = array();
    for ($i = 0; $i < $n; $i++) {
        $t = intval($f['tw'][$i][0] ?? 0);
        if ($i > 0 && $t === 0) { $size[] = $size[$i - 1]; continue; }
        $size[] = array(intval($f['tw'][$i][1] ?? 0), in_array($t, BK_REDUCED_TYPES, true) ? 1 : 0);
    }
    return $cache[$k] = array('fams' => bk_face_families($disc, $f['name'], $f['tw'], $n), 'fsize' => $size);
}

/**
 * Order of the placement of a competition. BookingCompetitions.BcPlaceOrder holds JSON
 * {"c": [[criterion, 1|-1]…], "bows": [division…]}; BcPlaceReverse the departures placed from
 * the last target ("2,3"). Defaults: distance, face, bow, age, sex, each in its first direction;
 * bows barebow, recurve, compound, then the other divisions in the order of ianseo. A division
 * added later takes its place at the end, one removed is dropped.
 * Returns ['crit' => [[criterion, 1|-1]…], 'bows' => [DivId => label] in order, 'reverse' => [SesOrder…]].
 */
function bk_place_order($tourId, $cfg = null)
{
    $j = $cfg && !empty($cfg->BcPlaceOrder) ? json_decode((string) $cfg->BcPlaceOrder, true) : null;
    $all = array();
    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions
        WHERE DivTournament = " . intval($tourId) . " AND DivAthlete = '1' ORDER BY DivViewOrder, DivId");
    while ($r = safe_fetch($rs)) $all[(string) $r->DivId] = (string) ($r->DivDescription ?: $r->DivId);
    $first = is_array($j['bows'] ?? null) ? array_map('strval', $j['bows']) : BK_PLACE_BOWS;
    $bows = array();
    foreach ($first as $d) if (isset($all[$d])) $bows[$d] = $all[$d];
    foreach (BK_PLACE_BOWS as $d) if (isset($all[$d]) && !isset($bows[$d])) $bows[$d] = $all[$d];
    $bows += $all;
    $reverse = array();
    foreach (explode(',', (string) ($cfg->BcPlaceReverse ?? '')) as $s) if (intval($s) > 0) $reverse[] = intval($s);
    return array('crit' => bk_plan_criteria(is_array($j['c'] ?? null) ? $j['c'] : array()), 'bows' => $bows, 'reverse' => $reverse);
}

/** Saves the order of the criteria and of the bows (null: back to the defaults). */
function bk_place_order_save($tourId, $crit, $bows)
{
    $tourId = intval($tourId);
    safe_w_sql("INSERT IGNORE INTO BookingCompetitions (BcTournament) VALUES ($tourId)");
    $v = 'NULL';
    if ($crit !== null) {
        $c = array();
        foreach (bk_plan_criteria($crit) as $x) $c[] = array($x[0], $x[1]);
        $b = array();
        foreach ((array) $bows as $d) if (preg_match('/^[A-Za-z0-9_-]{1,4}$/', (string) $d)) $b[] = (string) $d;
        $v = StrSafe_DB(json_encode(array('c' => $c, 'bows' => array_values(array_unique($b)))));
    }
    safe_w_sql("UPDATE BookingCompetitions SET BcPlaceOrder = $v WHERE BcTournament = $tourId");
}

/** Places a departure from its last target ($on) or from target 1. */
function bk_place_reverse_save($tourId, $session, $on)
{
    $tourId = intval($tourId);
    $session = intval($session);
    safe_w_sql("INSERT IGNORE INTO BookingCompetitions (BcTournament) VALUES ($tourId)");
    $r = safe_fetch(safe_r_sql("SELECT BcPlaceReverse FROM BookingCompetitions WHERE BcTournament = $tourId"));
    $list = array();
    foreach (explode(',', (string) ($r->BcPlaceReverse ?? '')) as $s) if (intval($s) > 0) $list[intval($s)] = true;
    if ($on) $list[$session] = true; else unset($list[$session]);
    ksort($list);
    safe_w_sql("UPDATE BookingCompetitions SET BcPlaceReverse = " . StrSafe_DB(implode(',', array_keys($list))) . "
        WHERE BcTournament = $tourId");
}

/**
 * Text, background and border of a face's chip and tags, from its colour — the same
 * proportions as the former single red (#a8382c on #fdf0ef, border #f0b8b2): a dark text, a
 * very light background, a mid border. A light colour (white peg) gets a darker text.
 */
function bk_face_tones($hex)
{
    $hex = ltrim((string) $hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) $hex = 'a8382c';
    $rgb = array_map('hexdec', str_split($hex, 2));   // hex pairs: ASCII, bytes are characters
    $mix = function ($to, $w) use ($rgb) {
        $o = '#';
        foreach ($rgb as $i => $c) $o .= sprintf('%02x', (int) round($c * (1 - $w) + $to * $w));
        return $o;
    };
    $lum = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;
    return array('fg' => $mix(0, $lum > 0.6 ? 0.6 : 0.2), 'bg' => $mix(255, 0.92), 'bd' => $mix(255, 0.65));
}

/** Colours given to the faces of a competition, in their order (bk_caps_faces). */
function bk_face_palette()
{
    return array('#1f6fd1', '#d1452b', '#1e9e57', '#8e44ad', '#e08a00', '#0f9bb3', '#c2185b', '#6d7d12',
        '#795548', '#546e7a');
}

/**
 * SVG file of the face (in Common/Images/Targets/) from the target description + diameter —
 * table taken AS IS from PlanQualifs (QP_Blason::svgForKey) so the pictures match. PlanQualifs
 * is NOT required (the modules stay standalone): the table is copied here. '0.svg' = "unknown"
 * face (fallback), as in PlanQualifs.
 */
function bk_face_svg($tarDescr, $diameter)
{
    static $map = array(
        'TrgIndComplete-40'        => '1.svg',
        'TrgIndSmall-40'           => '2.svg',
        'TrgCOIndSmall-40'         => '4.svg',
        'TrgProAMIndVegasSmall-40' => '16.svg',
        'TrgIndComplete-60'        => '1.svg',
        'TrgIndSmall-60'           => '2.svg',
        'TrgIndComplete-80'        => '1.svg',
        'TrgCOOutdoor-80'          => '9.svg',
        'TrgOutdoor-80'            => '1.svg',
        'TrgOutdoor-122'           => '5.svg',
        'TrgFrBeursault-45'        => '27.svg',
    );
    $key = $tarDescr . '-' . intval($diameter);
    return $map[$key] ?? '0.svg';
}

/**
 * Saved capabilities of a departure.
 * Returns [target => ['def'=>m, 'min'=>m, 'max'=>m, 'f'=>[TfId,…]]]
 * 0 = not set (so no constraint on that axis).
 */
function bk_caps_get($tourId, $session)
{
    bk_schema();
    $rs = safe_r_sql("SELECT BtTarget, BtDistDef, BtDistMin, BtDistMax, BtFaces FROM BookingTargetCaps
        WHERE BtTournament = " . intval($tourId) . " AND BtSession = " . intval($session));
    $out = array();
    while ($r = safe_fetch($rs)) {
        $out[intval($r->BtTarget)] = array(
            'def' => intval($r->BtDistDef),
            'min' => intval($r->BtDistMin),
            'max' => intval($r->BtDistMax),
            'f'   => array_values(array_filter(array_map('intval', explode(',', $r->BtFaces)))),
        );
    }
    return $out;
}

/**
 * Saves the capabilities of a target.
 *
 * All zero/empty deletes the row: "no constraint" is the same as "no setting", and an empty row
 * must not block assignments. Bounds are reordered when inverted, and the default value is
 * brought back into the range — an inconsistent setting must never produce a target nothing
 * can occupy.
 */
function bk_caps_set($tourId, $session, $target, $def, $min, $max, $faces)
{
    bk_schema();
    $tourId  = intval($tourId);
    $session = intval($session);
    $target  = intval($target);

    $def = max(0, intval($def));
    $min = max(0, intval($min));
    $max = max(0, intval($max));
    if ($min && $max && $min > $max) { $t = $min; $min = $max; $max = $t; }
    if ($def && $min && $def < $min) $def = $min;
    if ($def && $max && $def > $max) $def = $max;

    $f = array_values(array_unique(array_filter(array_map('intval', (array) $faces))));
    sort($f);
    $f = implode(',', array_slice($f, 0, 20));

    if (!$def && !$min && !$max && $f === '') {
        safe_w_sql("DELETE FROM BookingTargetCaps
            WHERE BtTournament = $tourId AND BtSession = $session AND BtTarget = $target");
        return;
    }
    $set = "BtDistDef = $def, BtDistMin = $min, BtDistMax = $max, BtFaces = " . StrSafe_DB($f);
    safe_w_sql("INSERT INTO BookingTargetCaps SET BtTournament = $tourId, BtSession = $session,
        BtTarget = $target, $set ON DUPLICATE KEY UPDATE $set");
}

/** Clears every capability of a departure (back to "no constraint"). */
function bk_caps_clear($tourId, $session)
{
    bk_schema();
    safe_w_sql("DELETE FROM BookingTargetCaps WHERE BtTournament = " . intval($tourId)
        . " AND BtSession = " . intval($session));
}

/** Copies the capabilities of one departure to another. */
function bk_caps_copy($tourId, $from, $to)
{
    bk_caps_clear($tourId, $to);
    foreach (bk_caps_get($tourId, $from) as $t => $c) {
        bk_caps_set($tourId, $to, $t, $c['def'], $c['min'], $c['max'], $c['f']);
    }
}

/**
 * Needs of an archer: distances (metres) and target face.
 * Same matching as the core: CONCAT(Division, Class) LIKE TdClasses, longest pattern first.
 */
function bk_caps_needs($tourId, $type, $division, $class, $faceId)
{
    $rs = safe_r_sql("SELECT Td1, Td2, Td3, Td4, TdDist1, TdDist2, TdDist3, TdDist4
        FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . "
          AND TdType = " . StrSafe_DB($type) . "
          AND " . StrSafe_DB(trim($division) . trim($class)) . " LIKE TdClasses
        ORDER BY CHAR_LENGTH(TdClasses) DESC LIMIT 1");
    $d = array();
    if ($r = safe_fetch($rs)) {
        for ($i = 1; $i <= 4; $i++) {
            $m = bk_td_metres($r->{'Td' . $i}, $r->{'TdDist' . $i});
            if ($m > 0) $d[$m] = true;
        }
    }
    $d = array_keys($d);
    sort($d);
    return array('d' => $d, 'f' => intval($faceId));
}

/**
 * Distance fingerprint of an archer — two archers of one target must have the same: they shoot
 * together, the target is at a single distance. A PHYSICAL constraint, not a rule on faces
 * sharing a target (those are in lib/cohabitation.php).
 */
function bk_caps_dist_key($needs)
{
    return implode('-', $needs['d']);
}

/** Text summary of a capability, for display. */
function bk_caps_label($c, $faces)
{
    if (!$c) return '';
    $p = array();
    if (!empty($c['min']) || !empty($c['max'])) {
        $p[] = ($c['min'] ?: '?') . '–' . ($c['max'] ?: '?') . ' m'
             . (!empty($c['def']) ? ' (' . bk_t('CapsDefault', $c['def']) . ')' : '');
    } elseif (!empty($c['def'])) {
        $p[] = $c['def'] . ' m';
    }
    if (!empty($c['f'])) {
        $l = array();
        foreach ($c['f'] as $id) $l[] = $faces[$id]['label'] ?? ('#' . $id);
        $p[] = implode(' / ', $l);
    }
    return implode(' · ', $p);
}

/**
 * Target faces really possible for a category, from the competition's settings. This is what
 * the archer is offered: never a free list. getTargets() returns [DivId][ClId][TfId] => name,
 * sorted from the most specific to the most generic — the first is the default choice.
 */
function bk_caps_faces_for($tourId, $division, $class, $faces = null)
{
    static $allOf = array();
    if (!function_exists('getTargets')) require_once('Partecipants/Fun_Targets.php');
    if ($faces === null) $faces = bk_caps_faces($tourId);

    if (!isset($allOf[intval($tourId)])) $allOf[intval($tourId)] = getTargets(true);
    $all = $allOf[intval($tourId)];
    $out = array();
    foreach (array_keys($all[$division][$class] ?? array()) as $id) {
        $id = intval($id);
        if (isset($faces[$id])) $out[$id] = $faces[$id];
    }
    return $out;
}

/**
 * Target faces offered to the ARCHER for their category, deduplicated by TYPE.
 *
 * The face size follows from the category (chosen above). The archer must only choose between
 * really different faces (their type / name). Two setting entries meaning the same face (same
 * pictures, different category regex) make a single choice — no puzzling "40 cm (a) / 40 cm
 * (b)". The most specific TfId is kept as representative (the one ianseo would give by
 * default).
 *
 * Returns [representative TfId => type label].
 */
function bk_caps_face_choices($tourId, $division, $class, $faces = null)
{
    $list = bk_caps_faces_for($tourId, $division, $class, $faces);
    $out  = array();
    $seen = array();
    foreach ($list as $f) {
        $name  = trim((string) ($f['name'] ?? ''));
        $label = $name !== '' ? $name : ($f['cm'] ? $f['cm'] . ' cm' : 'Blason');
        $sig   = $name !== '' ? 'n:' . mb_strtolower($name) : 'c:' . intval($f['cm']);
        if (isset($seen[$sig])) continue;   // same face → one choice
        $seen[$sig] = true;
        $out[intval($f['id'])] = $label;
    }
    return $out;
}
