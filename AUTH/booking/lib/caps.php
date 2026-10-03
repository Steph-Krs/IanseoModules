<?php
/**
 * lib/caps.php — possibilités techniques du terrain, cible par cible.
 *
 * L'organisateur déclare, pour chaque cible de chaque départ, les distances et
 * les blasons qu'elle peut recevoir. L'attribution s'y conforme ensuite.
 *
 * Les distances et blasons PROPOSÉS ne sont pas saisis : ils sont lus dans la
 * configuration de la compétition (TournamentDistances et TargetFaces), donc
 * toujours cohérents avec ce que ianseo sait déjà.
 *
 * Défaut = aucune contrainte : une cible sans ligne dans BK_TargetCaps accepte
 * tout. Une compétition jamais configurée se comporte donc exactement comme
 * avant l'existence de cette table.
 */

if (defined('BK_CAPS_LOADED')) return;
define('BK_CAPS_LOADED', true);

require_once __DIR__ . '/schema.php';

/**
 * Distances utilisées par la compétition, avec les catégories concernées.
 * Retour : [metres => ['m'=>int, 'labels'=>[...], 'classes'=>[...]]]
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
            $m   = intval($r->{'TdDist' . $i});
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
 * Blasons définis sur la compétition. Retour : [TfId => ['id','label','cm','who','name']]
 *  - 'cm'   : diamètre (mm→cm de TfW1)
 *  - 'name' : TYPE du blason (TfName) — ce qui distingue vraiment deux blasons
 *  - 'label': « 40 cm », désambiguïsé par le TYPE quand plusieurs blasons partagent
 *             le même diamètre (« 40 cm · Trispot ») — jamais un repère (a)/(b) qui
 *             n'évoque rien pour l'organisateur.
 *  - 'who'  : catégories/regex ianseo — donnée interne, JAMAIS affichée (illisible).
 */
function bk_caps_faces($tourId)
{
    // Jointure sur Targets (TfT1 → TarId) pour la clé « TarDescr-diamètre » qui
    // détermine le VISUEL du blason, exactement comme le module PlanQualifs.
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
        // Parcours : les « blasons » sont en réalité des PIQUETS de couleur
        // (« Piquet Rouge/Bleu/Blanc/Rose ») → on les marque pour un rendu dédié.
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
    // Plusieurs blasons du même diamètre → on distingue par le TYPE (nom du blason).
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
 * Fichier SVG du blason (dans Common/Images/Targets/) d'après le descriptif de
 * cible + le diamètre — table reprise à l'IDENTIQUE de PlanQualifs
 * (QP_Blason::svgForKey) pour que les pictogrammes correspondent. On NE requiert
 * PAS PlanQualifs (les modules restent autonomes) : la table est recopiée ici.
 * '0.svg' = blason « inconnu » (repli), comme dans PlanQualifs.
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
 * Capacités enregistrées d'un départ.
 * Retour : [cible => ['def'=>m, 'min'=>m, 'max'=>m, 'f'=>[TfId,…]]]
 * 0 = non renseigné (donc pas de contrainte sur cet axe).
 */
function bk_caps_get($tourId, $session)
{
    bk_schema();
    $rs = safe_r_sql("SELECT BtTarget, BtDistDef, BtDistMin, BtDistMax, BtFaces FROM BK_TargetCaps
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
 * Enregistre les capacités d'une cible.
 *
 * Tout à zéro/vide supprime la ligne : « aucune contrainte » ne se distingue pas
 * d'« aucune configuration », et une ligne vide ne doit pas bloquer les
 * affectations. Les bornes sont réordonnées si elles sont inversées, et la
 * valeur par défaut est ramenée dans la plage — un réglage incohérent ne doit
 * jamais produire une cible que rien ne peut occuper.
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
        safe_w_sql("DELETE FROM BK_TargetCaps
            WHERE BtTournament = $tourId AND BtSession = $session AND BtTarget = $target");
        return;
    }
    $set = "BtDistDef = $def, BtDistMin = $min, BtDistMax = $max, BtFaces = " . StrSafe_DB($f);
    safe_w_sql("INSERT INTO BK_TargetCaps SET BtTournament = $tourId, BtSession = $session,
        BtTarget = $target, $set ON DUPLICATE KEY UPDATE $set");
}

/** Efface toutes les capacités d'un départ (retour à « aucune contrainte »). */
function bk_caps_clear($tourId, $session)
{
    bk_schema();
    safe_w_sql("DELETE FROM BK_TargetCaps WHERE BtTournament = " . intval($tourId)
        . " AND BtSession = " . intval($session));
}

/** Recopie les capacités d'un départ vers un autre. */
function bk_caps_copy($tourId, $from, $to)
{
    bk_caps_clear($tourId, $to);
    foreach (bk_caps_get($tourId, $from) as $t => $c) {
        bk_caps_set($tourId, $to, $t, $c['def'], $c['min'], $c['max'], $c['f']);
    }
}

/**
 * Besoins d'un archer : distances (mètres) et blason.
 * Même correspondance que le cœur : CONCAT(Division, Classe) LIKE TdClasses,
 * motif le plus long prioritaire.
 */
function bk_caps_needs($tourId, $type, $division, $class, $faceId)
{
    $rs = safe_r_sql("SELECT TdDist1, TdDist2, TdDist3, TdDist4
        FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . "
          AND TdType = " . StrSafe_DB($type) . "
          AND " . StrSafe_DB(trim($division) . trim($class)) . " LIKE TdClasses
        ORDER BY CHAR_LENGTH(TdClasses) DESC LIMIT 1");
    $d = array();
    if ($r = safe_fetch($rs)) {
        for ($i = 1; $i <= 4; $i++) {
            $m = intval($r->{'TdDist' . $i});
            if ($m > 0) $d[$m] = true;
        }
    }
    $d = array_keys($d);
    sort($d);
    return array('d' => $d, 'f' => intval($faceId));
}

/**
 * Une cible peut-elle recevoir cet archer ?
 * Une capacité NON déclarée (liste vide) n'impose rien — on n'invente jamais
 * une contrainte que l'organisateur n'a pas posée.
 */
function bk_caps_target_ok($caps, $target, $needs)
{
    $c = $caps[$target] ?? null;
    if (!$c) return true;

    // Chaque distance dont l'archer a besoin doit tenir dans la plage de la cible.
    foreach ($needs['d'] as $m) {
        if (!empty($c['min']) && $m < $c['min']) return false;
        if (!empty($c['max']) && $m > $c['max']) return false;
    }
    if (!empty($c['f']) && !empty($needs['f'])) {
        if (!in_array($needs['f'], $c['f'], true)) return false;
    }
    return true;
}

/**
 * Empreinte de distances d'un archer — deux archers d'une même cible doivent
 * l'avoir identique : ils tirent ensemble, la cible est à une seule distance.
 * Contrainte PHYSIQUE, pas une règle de cohabitation de blasons (celles-ci
 * restent à définir, voir M7).
 */
function bk_caps_dist_key($needs)
{
    return implode('-', $needs['d']);
}

/** Récapitulatif texte d'une capacité, pour l'affichage. */
function bk_caps_label($c, $faces)
{
    if (!$c) return '';
    $p = array();
    if (!empty($c['min']) || !empty($c['max'])) {
        $p[] = ($c['min'] ?: '?') . '–' . ($c['max'] ?: '?') . ' m'
             . (!empty($c['def']) ? ' (déf. ' . $c['def'] . ')' : '');
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
 * Blasons réellement possibles pour une catégorie, d'après la configuration de
 * la compétition. C'est ce qu'on propose à l'archer : jamais une liste libre.
 * getTargets() renvoie [DivId][ClId][TfId] => nom, trié du plus spécifique au
 * plus générique — le premier est donc le choix par défaut.
 */
function bk_caps_faces_for($tourId, $division, $class, $faces = null)
{
    if (!function_exists('getTargets')) require_once('Partecipants/Fun_Targets.php');
    if ($faces === null) $faces = bk_caps_faces($tourId);

    $all = getTargets(true);
    $out = array();
    foreach (array_keys($all[$division][$class] ?? array()) as $id) {
        $id = intval($id);
        if (isset($faces[$id])) $out[$id] = $faces[$id];
    }
    return $out;
}

/**
 * Choix de blason proposés à l'ARCHER pour sa catégorie, dédupliqués par TYPE.
 *
 * La taille du blason découle de la catégorie (choisie au-dessus). L'archer ne
 * doit donc choisir qu'entre des blasons réellement différents (leur type / nom).
 * Deux entrées de configuration qui désignent le même blason (mêmes visuels,
 * régex de catégories différentes) ne forment qu'un seul choix — pas de
 * « 40 cm (a) / 40 cm (b) » incompréhensible. On garde le TfId le plus
 * spécifique comme représentant (celui que ianseo attribuerait par défaut).
 *
 * Retour : [TfId représentatif => libellé du type].
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
        if (isset($seen[$sig])) continue;   // même blason → un seul choix
        $seen[$sig] = true;
        $out[intval($f['id'])] = $label;
    }
    return $out;
}
