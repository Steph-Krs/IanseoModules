<?php
/**
 * Import of a save made by the stand-alone HTML draw page used before this module.
 *
 * That page kept its state in the browser and exported it as JSON: the places
 * drawn per category, the history of each team, the display toggles and the
 * appearance, background image included. The category NAMES and the full team
 * lists were not in the save but in the page's own source, so the page itself
 * can be given as well; without it, the lists are rebuilt from the save and the
 * categories are numbered.
 *
 * Three quirks of the old page are handled here:
 *   - a team was identified by its name, and the same club in two categories
 *     was told apart by trailing spaces ("RENNES", "RENNES "). The history is
 *     looked up with the exact old name first, then trimmed, and names are
 *     cleaned only once matched;
 *   - the save holds leftovers that belong to no list (renamed or sample
 *     teams): they are ignored, since nothing ever displayed them;
 *   - places are NOT carried over. A save is imported to prepare the next draw;
 *   - the season's stages had to be entered as a list of teams to be shown one
 *     after the other: such a list becomes a list of stages again.
 */

/**
 * Category names and team lists written in the old page's source.
 *
 * @param string $html
 * @return array ['title' => string, 'categories' => [id => ['name' => …, 'teams' => [...]]]]
 */
function tir_legacy_parse_html($html) {
    $out = ['title' => '', 'categories' => []];
    if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
        $out['title'] = tir_clean_name(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $unquote = function ($s) { return str_replace(["\\'", '\\\\'], ["'", '\\'], $s); };

    if (!preg_match_all("/\{\s*id:\s*'([^']+)',\s*name:\s*'((?:[^'\\\\]|\\\\.)*)',\s*teams:\s*\[(.*?)\]\s*\}/s",
        $html, $cats, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($cats as $c) {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/s", $c[3], $t);
        $out['categories'][$c[1]] = [
            'name'  => $unquote($c[2]),
            'teams' => array_map($unquote, $t[1]),
        ];
    }
    return $out;
}

/**
 * Create a show from an old save.
 *
 * @param string $json Content of the exported save.
 * @param string $html Content of the old page, or ''.
 * @return array ['id' => int] on success, ['error' => message] otherwise.
 */
function tir_legacy_import($json, $html) {
    $save = json_decode($json, true);
    if (!is_array($save) || !isset($save['draws']) || !is_array($save['draws'])) {
        return ['error' => tir_text('ErrLegacyFormat')];
    }
    $page  = $html !== '' ? tir_legacy_parse_html($html) : ['title' => '', 'categories' => []];
    $stats = is_array($save['stats'] ?? null) ? $save['stats'] : [];
    $cfg   = is_array($save['config'] ?? null) ? $save['config'] : [];

    // Categories in the page's order, then any the page does not know.
    $ids = array_keys($page['categories']);
    foreach (array_keys($save['draws']) as $id) {
        if (!in_array($id, $ids, true)) $ids[] = $id;
    }
    if (!$ids) return ['error' => tir_text('ErrLegacyEmpty')];

    $showId = tir_create_show($page['title'] !== '' ? $page['title'] : tir_text('LegacyTitle'));

    $n = 0;
    foreach ($ids as $id) {
        $n++;
        $name  = $page['categories'][$id]['name'] ?? tir_text('LegacyCategory', $n);
        $teams = $page['categories'][$id]['teams'] ?? [];
        $drawn = is_array($save['draws'][$id] ?? null) ? $save['draws'][$id] : [];
        asort($drawn);
        foreach (array_keys($drawn) as $t) {
            if (!in_array($t, $teams, true)) $teams[] = $t;
        }

        $catId = tir_add_category($showId, $name);

        // The old page drew the season's stages as teams named "Place - dates with a
        // year". A list made only of such names becomes a list of stages.
        $named = array_filter(array_map('trim', array_map('strval', $teams)), 'strlen');
        $asStages = $named && count(preg_grep('/\s[-–—]\s.*\b\d{4}\b/u', $named)) === count($named);
        if ($asStages) {
            safe_w_sql("UPDATE DrawCategories SET DcType='stages' WHERE DcId=$catId");
        }

        foreach ($teams as $raw) {
            $raw = (string)$raw;
            if (trim($raw) === '') continue;
            $h = $stats[$raw] ?? $stats[trim($raw)] ?? [];
            tir_add_team($catId, $raw, '', [
                'participations' => $h['participations'] ?? null,
                'wins'           => $h['victoires'] ?? null,
                'podiums'        => $h['podiums'] ?? null,
                'rank'           => $h['classement'] ?? null,
            ]);
        }
        if ($asStages) tir_split_stage_names($catId);
    }

    // Appearance: the old page stored CSS font stacks, matched here on their first family.
    $fonts = ['impact' => 'impact', 'arial' => 'arial', 'segoe ui' => 'segoe', 'trebuchet ms' => 'trebuchet',
        'verdana' => 'verdana', 'calibri' => 'calibri', 'georgia' => 'georgia', 'palatino' => 'palatino',
        'times new roman' => 'times'];
    $font = function ($stack) use ($fonts) {
        $first = mb_strtolower(trim(explode(',', (string)$stack)[0], " '\""));
        return $fonts[$first] ?? null;
    };
    $look = tir_clean_look([
        'bg'        => $cfg['bgColor'] ?? null,
        'accent'    => $cfg['accentColor'] ?? null,
        'text'      => $cfg['textColor'] ?? null,
        'overlay'   => $cfg['overlayOpacity'] ?? null,
        'fontTitle' => $font($cfg['fontTitle'] ?? ''),
        'fontBody'  => $font($cfg['fontBody'] ?? ''),
        'sizeTitle' => $cfg['sizeCat'] ?? null,
        'sizeTeam'  => $cfg['sizeTeam'] ?? null,
        'sizeRank'  => $cfg['sizeRank'] ?? null,
        // The old page kept a fixed 17% margin above and below the lists, so
        // that its background image's own banner and logo stayed clear.
        'margin'    => 17,
    ]);
    // The old page wrote short colours (#fff) that the colour fields cannot hold.
    foreach (['bg' => 'bgColor', 'accent' => 'accentColor', 'text' => 'textColor'] as $k => $old) {
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', (string)($cfg[$old] ?? ''), $m)) {
            $look[$k] = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
    }

    $shown = [];
    $map = ['participations' => 'participations', 'victoires' => 'wins', 'podiums' => 'podiums', 'classement' => 'rank'];
    foreach ($map as $old => $new) {
        if (!empty($save['showStats'][$old])) $shown[] = $new;
    }

    $set = "DwLook=" . StrSafe_DB(json_encode($look))
         . ", DwStats=" . StrSafe_DB(implode(',', $shown))
         . ", DwSubtitle=" . StrSafe_DB(tir_clean_name($cfg['subtitle'] ?? '', 160));

    $image = tir_decode_data_url((string)($cfg['bgImageData'] ?? ''));
    if ($image) {
        $set .= ", DwImage=" . StrSafe_DB(base64_encode($image['data'])) . ", DwImageType=" . StrSafe_DB($image['type']);
    }
    safe_w_sql("UPDATE DrawShows SET $set WHERE DwId=$showId");

    return ['id' => $showId];
}

/**
 * The image inside a data: URL, checked to really be an image.
 *
 * @param string $url
 * @return array|null ['type' => mime, 'data' => binary]
 */
function tir_decode_data_url($url) {
    if (!preg_match('#^data:(image/(?:jpeg|png|gif|webp));base64,([A-Za-z0-9+/=\s]+)$#', $url, $m)) return null;
    $data = base64_decode($m[2], true);
    if ($data === false) return null;
    return tir_check_image($data);
}

/**
 * Accept an image only if its content says it is one of the allowed types.
 *
 * The type is taken from the bytes, never from a file name or a declared
 * content type, so what is served later is what was checked now.
 *
 * @param string $data Binary content.
 * @return array|null ['type' => mime, 'data' => binary]
 */
function tir_check_image($data) {
    $info = @getimagesizefromstring($data);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!$info || !in_array($info['mime'] ?? '', $allowed, true)) return null;
    return ['type' => $info['mime'], 'data' => $data];
}
