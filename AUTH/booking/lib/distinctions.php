<?php
/**
 * booking/lib/distinctions.php — national distinction badges: rules and pictures.
 *
 * What may need changing lives in booking/distinctions/, never here: the two SVG templates
 * (shapes), logo.svg (the archer pictogram shared by every badge), looks.json (colours and
 * texts, layer by layer) and rules.json (score to reach, by discipline and bow).
 *
 * No database, no session, no ianseo function: the picture endpoint (public/badge.php) runs
 * without loading ianseo at all.
 */

function bk_dist_dir()
{
    return dirname(__DIR__) . '/distinctions/';
}

/**
 * Contents of a JSON file of the folder, decoded, or null; $err receives the reason (shown to
 * the administrator on the badge gallery, so that an edit that breaks the file is visible).
 */
function bk_dist_json($name, &$err = null)
{
    static $cache = array();
    if (!array_key_exists($name, $cache)) {
        $cache[$name] = array('data' => null, 'err' => '');
        $raw = @file_get_contents(bk_dist_dir() . $name);
        if ($raw === false) {
            $cache[$name]['err'] = $name . ': file not found';
        } else {
            $data = json_decode($raw, true);
            if (!is_array($data)) $cache[$name]['err'] = $name . ': ' . json_last_error_msg();
            else $cache[$name]['data'] = $data;
        }
    }
    $err = $cache[$name]['err'];
    return $cache[$name]['data'];
}

function bk_dist_rules(&$err = null)
{
    return bk_dist_json('rules.json', $err);
}

function bk_dist_looks(&$err = null)
{
    return bk_dist_json('looks.json', $err);
}

/** Changes whenever a file of the folder changes: cache key of the picture addresses. */
function bk_dist_version()
{
    static $v = null;
    if ($v === null) {
        $sig = '';
        foreach ((array) glob(bk_dist_dir() . '*') as $f) $sig .= basename($f) . filemtime($f) . filesize($f);
        // bytes: a hash, not a human text
        $v = substr(md5($sig), 0, 10);
    }
    return $v;
}

/**
 * Picture of a badge, as an SVG document.
 *   $family  key of looks.json "families" (template + base layer)
 *   $layers  keys of looks.json "layers", applied in order after the base
 *   $set     last values, [id => [attribute => value]] (the distance written on the badge)
 * Returns the SVG text, or '' with $err set.
 */
function bk_dist_svg($family, array $layers, array $set = array(), &$err = null)
{
    $err = '';
    $looks = bk_dist_looks($err);
    if (!$looks) return '';
    $fam = $looks['families'][$family] ?? null;
    if (!is_array($fam) || empty($fam['template'])) { $err = 'looks.json: unknown family ' . $family; return ''; }

    $doc = bk_dist_load(basename((string) $fam['template']), $err);
    if (!$doc) return '';
    $xp = new DOMXPath($doc);

    $slot = $xp->query('//*[@id="logo"]')->item(0);
    if ($slot) {
        $logo = bk_dist_load('logo.svg', $err);
        if (!$logo) return '';
        $pic = (new DOMXPath($logo))->query('//*[@id="pictogram"]')->item(0);
        if ($pic) $slot->appendChild($doc->importNode($pic, true));
    }

    $steps = array($fam['base'] ?? array());
    foreach ($layers as $name) {
        if (!isset($looks['layers'][$name])) { $err = 'looks.json: unknown layer ' . $name; return ''; }
        $steps[] = $looks['layers'][$name];
    }
    $steps[] = $set;
    foreach ($steps as $step) {
        if (!is_array($step)) continue;
        foreach ($step as $id => $attrs) {
            if (!is_array($attrs) || !preg_match('/^[A-Za-z][\w-]*$/', (string) $id)) continue;
            $el = $xp->query('//*[@id="' . $id . '"]')->item(0);
            if (!$el) continue;
            foreach ($attrs as $attr => $value) {
                $value = (string) $value;
                if ($attr === 'text') { $el->textContent = $value; continue; }
                // Presentation attributes only: no event handler, no link.
                if (!preg_match('/^[a-z][a-z-]*$/', $attr) || strpos($attr, 'on') === 0 || $attr === 'href') continue;
                $el->setAttribute($attr, $value);
            }
        }
    }

    foreach (iterator_to_array($xp->query('//comment()')) as $c) $c->parentNode->removeChild($c);
    return $doc->saveXML($doc->documentElement);
}

/** An SVG file of the folder as a DOM document (null + $err when unreadable). */
function bk_dist_load($file, &$err)
{
    $raw = @file_get_contents(bk_dist_dir() . $file);
    if ($raw === false) { $err = $file . ': file not found'; return null; }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($raw, LIBXML_NONET);
    $xmlErr = libxml_get_last_error();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        $err = $file . ': ' . ($xmlErr ? trim($xmlErr->message) . ' (line ' . $xmlErr->line . ')' : 'invalid SVG');
        return null;
    }
    return $doc;
}

/** Sets of badges of a discipline (rules.json), [] when it has none. */
function bk_dist_sets($disc)
{
    $rules = bk_dist_rules();
    $sets = $rules['disciplines'][$disc]['sets'] ?? array();
    return is_array($sets) ? array_values(array_filter($sets, 'is_array')) : array();
}

/** One set of a discipline by its id, or null. */
function bk_dist_set($disc, $setId)
{
    foreach (bk_dist_sets($disc) as $set) {
        if ((string) ($set['id'] ?? '') === (string) $setId) return $set;
    }
    return null;
}

/**
 * Which badges a shot can earn: ['disc', 'set', 'col', 'text'] or null.
 *   $bow   the ianseo division code of the entry
 *   $age   its age class (U13 … S3)
 *   $dist  the distance shot, as written on a badge ("70m"); when the column writes one text per
 *          age class, the shot must have been at that distance (an S1 recurve earns the 70 m badges
 *          by shooting 70 m). Ignored for a single text: indoor "18m" is also earned at 25 m.
 */
function bk_dist_context($disc, $bow, $age, $dist)
{
    foreach (bk_dist_sets($disc) as $set) {
        foreach ((array) ($set['columns'] ?? array()) as $colId => $col) {
            if (!is_array($col) || !in_array($bow, (array) ($col['bows'] ?? array()), true)) continue;
            $t = $col['text'] ?? '';
            if (is_array($t)) {
                if (!isset($t[$age]) || (string) $t[$age] !== (string) $dist) continue;
                $text = (string) $t[$age];
            } else {
                if (isset($col['ages']) && !in_array($age, (array) $col['ages'], true)) continue;
                $text = (string) $t;
            }
            return array('disc' => $disc, 'set' => (string) ($set['id'] ?? ''), 'col' => (string) $colId, 'text' => $text);
        }
    }
    return null;
}

/**
 * The levels of a context, in order: ['id', 'score', 'face', 'drawn'] — score = what to reach in
 * the context's column (levels without a score there are skipped), drawn = has a picture.
 */
function bk_dist_ladder(array $ctx)
{
    $set = bk_dist_set($ctx['disc'], $ctx['set']);
    $out = array();
    foreach ((array) ($set['levels'] ?? array()) as $lv) {
        $score = intval($lv[$ctx['col']] ?? 0);
        if (!is_array($lv) || ($lv['id'] ?? '') === '' || $score <= 0) continue;
        $out[] = array('id' => (string) $lv['id'], 'score' => $score, 'face' => intval($lv['face'] ?? 0),
                       'drawn' => !empty($lv['badge']));
    }
    return $out;
}

/** What the set compares to the thresholds: 'score' or 'honours'. */
function bk_dist_measure(array $ctx)
{
    $set = bk_dist_set($ctx['disc'], $ctx['set']);
    return ($set['measure'] ?? '') === 'honours' ? 'honours' : 'score';
}

/** Every column text of a set: [column => [text => age classes]] (the gallery's rows). */
function bk_dist_columns(array $set)
{
    $out = array();
    foreach ((array) ($set['columns'] ?? array()) as $colId => $col) {
        if (!is_array($col)) continue;
        $t = $col['text'] ?? '';
        if (is_array($t)) {
            foreach ($t as $age => $txt) $out[$colId][(string) $txt][] = $age;
        } else {
            $out[$colId][(string) $t] = (array) ($col['ages'] ?? array());
        }
    }
    return $out;
}

/** Colour of a level on the archer's chart (#rrggbb). */
function bk_dist_level_color($levelId)
{
    $looks = bk_dist_looks();
    if (!empty($looks['chart'][$levelId])) return (string) $looks['chart'][$levelId];
    if (!empty($looks['layers'][$levelId]['panel']['fill'])) return (string) $looks['layers'][$levelId]['panel']['fill'];
    $tile = $looks['tiles'][$levelId] ?? null;
    // bytes: a hexadecimal colour, ASCII only
    if ($tile) return strtolower((string) ($tile['bg'] ?? '')) === '#ffffff' ? (string) $tile['fg'] : (string) $tile['bg'];
    return '#7d8183';
}

/**
 * HTML of a level: its picture when drawn, otherwise a text tile carrying $name.
 * $base = address of booking/public/ (the picture endpoint lives there), $h = height in pixels.
 */
function bk_dist_visual(array $ctx, $levelId, $name, $base, $h = 64, $class = '')
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
    $set = bk_dist_set($ctx['disc'], $ctx['set']);
    foreach ((array) ($set['levels'] ?? array()) as $lv) {
        if (($lv['id'] ?? '') !== $levelId) continue;
        if (!empty($lv['badge'])) {
            return '<img class="bk-dist-img' . ($class !== '' ? ' ' . $e($class) : '') . '" src="'
                . $e($base . bk_dist_url($ctx['disc'], $ctx['set'], $ctx['col'], $levelId, $ctx['text']))
                . '" height="' . intval($h) . '" alt="' . $e($name) . '" title="' . $e($name) . '" loading="lazy">';
        }
        $looks = bk_dist_looks();
        $tile = $looks['tiles'][$levelId] ?? array();
        $col = $set['columns'][$ctx['col']] ?? array();
        $bd = $looks['tile_borders'][$col['tile'] ?? 'default'] ?? ($looks['tile_borders']['default'] ?? '#9aa0a6');
        $style = '--dt-bg:' . ($tile['bg'] ?? '#ffffff') . ';--dt-fg:' . ($tile['fg'] ?? '#20263d') . ';--dt-bd:' . $bd
            . ';--dt-h:' . intval($h) . 'px';
        // Only colours from looks.json reach the style attribute.
        if (!preg_match('/^[#a-zA-Z0-9:;. -]*$/', $style)) $style = '';
        return '<span class="bk-dist-tile' . ($class !== '' ? ' ' . $e($class) : '') . '" style="' . $e($style) . '" title="'
            . $e($name) . '">' . $e($name) . '</span>';
    }
    return '';
}

/**
 * How to draw one badge: ['family', 'layers', 'set'] for bk_dist_svg(), or null when the rules
 * do not know it or the level has no picture. $text must be one the rules give for this column
 * (nothing else is ever written on a badge).
 */
function bk_dist_badge($disc, $setId, $colId, $level, $text)
{
    $set = bk_dist_set($disc, $setId);
    $col = $set['columns'][$colId] ?? null;
    if (!is_array($col)) return null;
    $t = $col['text'] ?? '';
    if (is_array($t) ? !in_array($text, array_map('strval', $t), true) : $text !== (string) $t) return null;
    foreach ((array) ($set['levels'] ?? array()) as $lv) {
        if (($lv['id'] ?? '') !== $level || empty($lv['badge'])) continue;
        return array(
            'family' => (string) $lv['badge'],
            'layers' => array_merge((array) ($lv['layers'] ?? array()), (array) ($col['layers'] ?? array())),
            'set'    => array('distance' => array('text' => $text)),
        );
    }
    return null;
}

/** Address of a badge picture (relative to booking/public/). */
function bk_dist_url($disc, $setId, $colId, $level, $text)
{
    return 'badge.php?' . http_build_query(array('d' => $disc, 's' => $setId, 'c' => $colId, 'l' => $level,
                                                 't' => $text, 'v' => bk_dist_version()));
}
