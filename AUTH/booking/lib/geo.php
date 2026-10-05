<?php
/**
 * lib/geo.php — geolocation of the competitions for the MAP (option 1: SVG map of France + town
 * markers, NO external tiles nor IP leak from the browser).
 *
 * The only outgoing call is the GEOCODING, done BY THE SERVER and ONCE per competition (result
 * cached in BookingCompetitions: BcLat/BcLng/BcGeoSrc), through the Base Adresse Nationale
 * (api-adresse.data.gouv.fr — French public service, free, no key). Can be turned off with
 * config.local.json → "geo": {"enabled": false}.
 *
 * ⚠️ The town is the ToVenue field (ToWhere = precise place: gym/stadium).
 */

if (defined('BK_GEO_LOADED')) return;
define('BK_GEO_LOADED', true);

require_once __DIR__ . '/schema.php';

/** Geo settings (config.local.json → "geo"), with defaults. */
function bk_geo_conf()
{
    static $c = null;
    if ($c === null) {
        $c = array('enabled' => true, 'base' => 'https://api-adresse.data.gouv.fr');
        $ov = function_exists('bk_local_config') ? (bk_local_config()['geo'] ?? array()) : array();
        if (isset($ov['enabled'])) $c['enabled'] = (bool) $ov['enabled'];
        if (!empty($ov['base']))   $c['base']    = rtrim((string) $ov['base'], '/');
    }
    return $c;
}

/**
 * Geocodes a town through the Base Adresse Nationale. Returns ['lat', 'lng', 'label'] or null.
 * Never throws: at the slightest problem (network, unexpected answer) it returns null.
 */
function bk_geocode($query)
{
    $conf = bk_geo_conf();
    $query = trim((string) $query);
    if (!$conf['enabled'] || $query === '' || !function_exists('curl_init')) return null;

    $url = $conf['base'] . '/search/?limit=1&type=municipality&q=' . rawurlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'ianseo-booking/1.0 (+competition map)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;

    $j = json_decode($body, true);
    $co = $j['features'][0]['geometry']['coordinates'] ?? null;   // [lng, lat]
    if (!is_array($co) || count($co) < 2) return null;
    $lng = (float) $co[0]; $lat = (float) $co[1];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return array('lat' => $lat, 'lng' => $lng, 'label' => (string) ($j['features'][0]['properties']['label'] ?? ''));
}

/**
 * Coordinates of a competition, geocoded on demand (once) and cached. Geocodes again when the
 * town (ToVenue) changed since the last cache. Returns ['lat', 'lng'] or null (no town /
 * geocoding impossible). Writes only when needed.
 */
function bk_comp_geocode($tourId)
{
    bk_schema();
    $tourId = intval($tourId);

    $r = safe_fetch(safe_r_sql("SELECT ToVenue, BcLat, BcLng, BcGeoSrc
        FROM Tournament LEFT JOIN BookingCompetitions ON BcTournament = ToId
        WHERE ToId = $tourId"));
    if (!$r) return null;
    $venue = trim((string) $r->ToVenue);
    if ($venue === '') return null;

    // Valid cache: same town AND coordinates there.
    if ((string) $r->BcGeoSrc === $venue && $r->BcLat !== null && $r->BcLng !== null) {
        return array('lat' => (float) $r->BcLat, 'lng' => (float) $r->BcLng);
    }

    $g = bk_geocode($venue);
    if (!$g) {
        // Remembers the try (BcGeoSrc) without coordinates → no endless retries for the same
        // town; a new ToVenue starts a new geocoding.
        safe_w_sql("INSERT INTO BookingCompetitions SET BcTournament = $tourId, BcGeoSrc = " . StrSafe_DB($venue) . "
            ON DUPLICATE KEY UPDATE BcGeoSrc = " . StrSafe_DB($venue));
        return null;
    }
    safe_w_sql("INSERT INTO BookingCompetitions SET BcTournament = $tourId,
        BcLat = " . StrSafe_DB(number_format($g['lat'], 6, '.', '')) . ",
        BcLng = " . StrSafe_DB(number_format($g['lng'], 6, '.', '')) . ",
        BcGeoSrc = " . StrSafe_DB($venue) . "
        ON DUPLICATE KEY UPDATE BcLat = VALUES(BcLat), BcLng = VALUES(BcLng), BcGeoSrc = VALUES(BcGeoSrc)");
    return array('lat' => $g['lat'], 'lng' => $g['lng']);
}

/* ------------------------------------------------------------------ */
/* Map background (SVG) — mainland in a large frame + overseas in small ones */
/* ------------------------------------------------------------------ */

/**
 * Projection frames in a 0 0 1000 1000 viewBox. A GLOBAL equirectangular projection would
 * crush the mainland (French Guiana is ~50° of longitude away): each overseas territory has
 * ITS frame and ITS bbox → insets stacked in the right column (mainland = large frame on the
 * left).
 *
 * Overseas departments (971-976): bbox from the GeoJSON geometry. Overseas collectivities (New
 * Caledonia 988, French Polynesia 987): MISSING from the departments GeoJSON → FIXED bbox given
 * here so the markers land in them (the Base Adresse Nationale geocodes these two territories;
 * otherwise the frame is drawn empty). The rectangles are computed (equal height) so a
 * territory can be added or removed without moving the others by hand.
 */
function bk_map_groups()
{
    $insets = array(
        '971' => array('lat0' => 16.2),
        '972' => array('lat0' => 14.6),
        '973' => array('lat0' => 4.0),
        '974' => array('lat0' => -21.1),
        '976' => array('lat0' => -12.8),
        '988' => array('lat0' => -21.2, 'bbox' => array(163.9, 168.3, -22.9, -19.4)),
        '987' => array('lat0' => -17.6, 'bbox' => array(-150.0, -149.05, -17.98, -17.42)),
    );
    $g = array('metro' => array('rect' => array(6, 6, 756, 988), 'lat0' => 46.6));
    $colX = 770; $colW = 224; $top = 6; $bottom = 994; $gap = 6; $n = count($insets);
    $bh = ($bottom - $top - $gap * ($n - 1)) / $n;
    $i = 0;
    foreach ($insets as $code => $cfg) {
        $y = $top + $i++ * ($bh + $gap);
        $g[$code] = array('rect' => array($colX, round($y, 1), $colW, round($bh, 1)),
                          'lat0' => $cfg['lat0']);
        if (isset($cfg['bbox'])) $g[$code]['bbox'] = $cfg['bbox'];
    }
    return $g;
}

/**
 * SIMPLIFIED outlines of the collectivities missing from the departments GeoJSON (New
 * Caledonia, French Polynesia), as GeoJSON "features" (properties.code/nom + MultiPolygon
 * geometry). Rough but recognisable shapes, in lng/lat → projected in the inset like a
 * department, so the marker sits on land and not in an empty rectangle.
 */
function bk_com_features()
{
    return array(
        array('properties' => array('code' => '988', 'nom' => ''),
            'geometry' => array('type' => 'MultiPolygon', 'coordinates' => array(
                array(array(  // Grande Terre
                    array(164.05, -20.25), array(164.55, -20.28), array(165.00, -20.68), array(165.30, -20.92),
                    array(165.62, -21.28), array(165.95, -21.45), array(166.25, -21.62), array(166.62, -21.92),
                    array(166.95, -22.18), array(167.00, -22.38), array(166.70, -22.35), array(166.45, -22.27),
                    array(166.10, -21.90), array(165.82, -21.70), array(165.48, -21.56), array(165.10, -21.28),
                    array(164.82, -21.05), array(164.50, -20.75), array(164.28, -20.55), array(164.10, -20.38),
                    array(164.05, -20.25),
                )),
                array(array(array(166.45, -20.45), array(166.65, -20.48), array(166.68, -20.65), array(166.50, -20.68), array(166.45, -20.45))), // Ouvea
                array(array(array(167.05, -20.75), array(167.45, -20.78), array(167.48, -21.05), array(167.10, -21.08), array(167.05, -20.75))), // Lifou
                array(array(array(167.82, -21.40), array(168.12, -21.42), array(168.15, -21.62), array(167.85, -21.62), array(167.82, -21.40))), // Mare
            ))),
        array('properties' => array('code' => '987', 'nom' => ''),
            'geometry' => array('type' => 'MultiPolygon', 'coordinates' => array(
                array(array(  // Tahiti (Nui + Iti)
                    array(-149.62, -17.50), array(-149.48, -17.52), array(-149.36, -17.60), array(-149.34, -17.70),
                    array(-149.22, -17.74), array(-149.14, -17.83), array(-149.15, -17.88), array(-149.28, -17.87),
                    array(-149.34, -17.80), array(-149.45, -17.82), array(-149.58, -17.80), array(-149.63, -17.70),
                    array(-149.64, -17.58), array(-149.62, -17.50),
                )),
                array(array(array(-149.92, -17.48), array(-149.75, -17.47), array(-149.73, -17.58), array(-149.90, -17.60), array(-149.92, -17.48))), // Moorea
            ))),
    );
}

/** Group of a department code: itself for a known overseas territory, otherwise 'metro'. */
function bk_map_group_of($code)
{
    $code = (string) $code;
    $g = bk_map_groups();
    return ($code !== 'metro' && isset($g[$code])) ? $code : 'metro';
}

/** Fits a bbox [lngMin, lngMax, latMin, latMax] into a rect [x, y, w, h] (aspect kept). */
function bk_map_fit($bbox, $rect, $lat0)
{
    list($lngMin, $lngMax, $latMin, $latMax) = $bbox;
    list($rx, $ry, $rw, $rh) = $rect;
    $k = cos(deg2rad($lat0));
    $w = ($lngMax - $lngMin) * $k; $h = ($latMax - $latMin);
    if ($w <= 0 || $h <= 0) return null;
    $s  = min($rw / $w, $rh / $h);
    $ox = $rx + ($rw - $w * $s) / 2;
    $oy = $ry + ($rh - $h * $s) / 2;
    return array('bbox' => $bbox, 'rect' => $rect, 'lngMin' => $lngMin, 'latMax' => $latMax,
                 'k' => $k, 's' => $s, 'ox' => $ox, 'oy' => $oy);
}

/** Projects (lng, lat) into a bk_map_fit frame → [x, y]. */
function bk_map_xy($p, $lng, $lat)
{
    return array($p['ox'] + ($lng - $p['lngMin']) * $p['k'] * $p['s'],
                 $p['oy'] + ($p['latMax'] - $lat) * $p['s']);
}

/**
 * Geometry of the map background, projected and SIMPLIFIED (decimation at ~0.8 px), CACHED
 * (serialised, invalidated when the GeoJSON changes) — 3.5 MB are not read again per request.
 * Returns ['proj'=>[group=>params], 'paths'=>[ ['code','nom','group','d','cx','cy'] ], 'ok'=>bool].
 */
function bk_map_geometry()
{
    $src   = dirname(__DIR__) . '/public/assets/departements.geojson';
    $cache = dirname(__DIR__) . '/public/assets/france-map.cache';
    if (!is_file($src)) return array('ok' => false, 'proj' => array(), 'paths' => array());

    if (is_file($cache) && filemtime($cache) >= filemtime($src)) {
        $c = @unserialize((string) @file_get_contents($cache));
        if (is_array($c) && !empty($c['ok'])) return $c;
    }

    $j = json_decode((string) @file_get_contents($src), true);
    if (empty($j['features'])) return array('ok' => false, 'proj' => array(), 'paths' => array());

    // 1) bbox per group (mainland = union of the mainland departments). The collectivities
    // (simplified outlines, outside the GeoJSON) are added here to be drawn like a department;
    // their PROJECTION keeps the fixed bbox of bk_map_groups (see step 2).
    $groups = bk_map_groups();
    $bbox = array();   // groupe => [lngMin,lngMax,latMin,latMax]
    $feat = array();   // [ ['code','nom','group','polys'=>[ [ [lng,lat],... ] ]] ]
    foreach (array_merge($j['features'], bk_com_features()) as $f) {
        $code = (string) ($f['properties']['code'] ?? '');
        $grp  = bk_map_group_of($code);
        $polys = array();
        $geom = $f['geometry'] ?? array();
        $mp = ($geom['type'] ?? '') === 'MultiPolygon' ? ($geom['coordinates'] ?? array())
            : (($geom['type'] ?? '') === 'Polygon' ? array($geom['coordinates'] ?? array()) : array());
        foreach ($mp as $poly) {
            foreach ($poly as $ring) {
                $r = array();
                foreach ($ring as $pt) {
                    $lng = (float) $pt[0]; $lat = (float) $pt[1];
                    $r[] = array($lng, $lat);
                    if (!isset($bbox[$grp])) $bbox[$grp] = array($lng, $lng, $lat, $lat);
                    else {
                        if ($lng < $bbox[$grp][0]) $bbox[$grp][0] = $lng;
                        if ($lng > $bbox[$grp][1]) $bbox[$grp][1] = $lng;
                        if ($lat < $bbox[$grp][2]) $bbox[$grp][2] = $lat;
                        if ($lat > $bbox[$grp][3]) $bbox[$grp][3] = $lat;
                    }
                }
                if (count($r) >= 4) $polys[] = $r;
            }
        }
        $feat[] = array('code' => $code, 'nom' => (string) ($f['properties']['nom'] ?? ''), 'group' => $grp, 'polys' => $polys);
    }

    // 2) projection per group. For the collectivities the FIXED bbox wins (generous framing:
    // markers of towns outside the simplified outline still land in the inset).
    $proj = array();
    foreach ($groups as $g => $cfg) {
        $bb = ($cfg['bbox'] ?? null) ?: ($bbox[$g] ?? null);
        if (!$bb) continue;
        $p = bk_map_fit($bb, $cfg['rect'], $cfg['lat0']);
        if (!$p) continue;
        $p['rectArr'] = $cfg['rect'];
        $proj[$g] = $p;
    }

    // 3) SVG paths (projected, decimated). Centroid = mean of the vertices of the largest ring.
    // eps = decimation threshold in px (viewBox 1000): higher = lighter. 1.6 px stays sharp even
    // zoomed to a department (0.3 px effective at 5×), for a weight ~3× smaller.
    $eps = 1.6;
    $paths = array();
    foreach ($feat as $ft) {
        $p = $proj[$ft['group']] ?? null;
        if (!$p) continue;
        $d = ''; $cx = 0; $cy = 0; $cn = 0; $bestN = 0;
        foreach ($ft['polys'] as $ring) {
            $pts = array(); $last = null;
            foreach ($ring as $ll) {
                $xy = bk_map_xy($p, $ll[0], $ll[1]);
                if ($last === null || (abs($xy[0] - $last[0]) + abs($xy[1] - $last[1])) >= $eps) {
                    $pts[] = $xy; $last = $xy;
                }
            }
            if (count($pts) < 3) continue;
            $d .= 'M';
            foreach ($pts as $i => $xy) $d .= ($i ? 'L' : '') . round($xy[0], 1) . ' ' . round($xy[1], 1) . ' ';
            $d .= 'Z';
            if (count($pts) > $bestN) {   // centroid from the most detailed ring (the main one)
                $bestN = count($pts); $sx = 0; $sy = 0;
                foreach ($pts as $xy) { $sx += $xy[0]; $sy += $xy[1]; }
                $cx = $sx / count($pts); $cy = $sy / count($pts);
            }
        }
        if ($d !== '') $paths[] = array('code' => $ft['code'], 'nom' => $ft['nom'], 'group' => $ft['group'],
            'd' => trim($d), 'cx' => round($cx, 1), 'cy' => round($cy, 1));
    }

    $out = array('ok' => true, 'proj' => $proj, 'paths' => $paths);
    @file_put_contents($cache, serialize($out));
    return $out;
}

/**
 * Position of a marker (lat, lng) on the background: finds the group whose bbox contains it
 * (mainland or an overseas territory), then projects. Returns [x, y] or null.
 */
function bk_map_marker_xy($proj, $lat, $lng)
{
    // Overseas territories first (small bboxes, take precedence), mainland last.
    foreach ($proj as $g => $p) {
        if ($g === 'metro' || !$p) continue;
        $b = $p['bbox'];
        if ($lng >= $b[0] && $lng <= $b[1] && $lat >= $b[2] && $lat <= $b[3]) return bk_map_xy($p, $lng, $lat);
    }
    $p = $proj['metro'] ?? null;
    if ($p) { $b = $p['bbox']; if ($lng >= $b[0] && $lng <= $b[1] && $lat >= $b[2] && $lat <= $b[3]) return bk_map_xy($p, $lng, $lat); }
    return null;
}
