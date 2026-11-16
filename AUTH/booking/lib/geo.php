<?php
/**
 * lib/geo.php — geolocation of the competitions for the MAP (public/map.php).
 *
 * Two parts, kept apart on purpose:
 *  - GEOCODING, done BY THE SERVER and ONCE per competition (result cached in
 *    BookingCompetitions: BcLat/BcLng/BcGeoSrc), through the Base Adresse Nationale
 *    (api-adresse.data.gouv.fr — French public service, free, no key). Can be turned off with
 *    config.local.json → "geo": {"enabled": false}. Only the address of the competition is sent
 *    (its venue field and town), never anything about a person.
 *  - BASE MAP: tiles of the IGN Geoplateforme (data.geopf.fr — French public body, free, no
 *    key, no cookie), loaded by the ARCHER'S BROWSER straight from IGN: nothing goes through
 *    this server. Displayed with Leaflet, shipped in public/assets/vendor/leaflet and never
 *    taken from a CDN, so that no other third party sees the visitors.
 *
 * ⚠️ The town is the ToVenue field (ToWhere = precise place: gym/stadium, often with its street).
 * The marker goes on that ADDRESS — the one the itinerary links use — and on the centre of the
 * town only when the address cannot be found: a town centre can be kilometres from the field
 * (real case, 1.8.0: a gym shown at the town hall, the itinerary links being right).
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
 * Answers of the Base Adresse Nationale: [['lat', 'lng', 'label', 'type', 'score', 'city']], best
 * first. $type: '' (any) or 'municipality'; $citycode: answers in that town only. Never throws: at the slightest problem (network,
 * unexpected answer) it returns an empty list.
 */
function bk_geocode_all($query, $type = '', $limit = 5, $citycode = '')
{
    $conf = bk_geo_conf();
    $query = trim(preg_replace('/\s+/u', ' ', (string) $query));
    if (!$conf['enabled'] || $query === '' || !function_exists('curl_init')) return array();

    $url = $conf['base'] . '/search/?limit=' . intval($limit) . ($type !== '' ? '&type=' . rawurlencode($type) : '')
        . ($citycode !== '' ? '&citycode=' . rawurlencode((string) $citycode) : '')
        . '&q=' . rawurlencode(mb_substr($query, 0, 200));
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
    // No answer (network, service down, limit reached): told apart from "nothing found", so
    // that the caller tries again later instead of remembering a failure.
    if ($code !== 200 || !$body) { $GLOBALS['_bk_geo_unreachable'] = true; return array(); }

    $j = json_decode($body, true);
    $out = array();
    foreach ((array) ($j['features'] ?? array()) as $f) {
        $co = $f['geometry']['coordinates'] ?? null;   // [lng, lat]
        if (!is_array($co) || count($co) < 2) continue;
        $lng = (float) $co[0]; $lat = (float) $co[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $pr = (array) ($f['properties'] ?? array());
        $out[] = array('lat' => $lat, 'lng' => $lng, 'label' => (string) ($pr['label'] ?? ''), 'type' => (string) ($pr['type'] ?? ''),
            'score' => (float) ($pr['score'] ?? 0), 'city' => (string) ($pr['city'] ?? ''),
            'citycode' => (string) ($pr['citycode'] ?? ''), 'name' => (string) ($pr['name'] ?? ''));
    }
    return $out;
}

/** A town through the Base Adresse Nationale: ['lat', 'lng', 'label', 'citycode'…] or null. */
function bk_geocode($query)
{
    $all = bk_geocode_all($query, 'municipality', 1);
    return $all ? $all[0] : null;
}

/** Words of a text, upper case without accents (compares a street typed and the one found). */
function bk_geo_words($s)
{
    $s = mb_strtoupper((string) $s, 'UTF-8');
    $s = strtr($s, array('À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ÿ' => 'Y', 'Œ' => 'OE', 'Æ' => 'AE'));
    return preg_split('/[^A-Z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * Does the street found belong to the address typed? Every word of its name but the articles —
 * its type of way included, abbreviated or not — must be in the address: the Base Adresse
 * Nationale answers something to anything (real cases: the name of a gym alone gave a street of
 * the town with a score of 0.52; an avenue that does not exist gave a quay of the same name).
 */
function bk_geo_same_street($name, $typed)
{
    static $short = array('AV' => 'AVENUE', 'AVE' => 'AVENUE', 'BD' => 'BOULEVARD', 'BVD' => 'BOULEVARD', 'CH' => 'CHEMIN',
        'CHE' => 'CHEMIN', 'PL' => 'PLACE', 'RTE' => 'ROUTE', 'IMP' => 'IMPASSE', 'ALL' => 'ALLEE', 'SQ' => 'SQUARE',
        'PASS' => 'PASSAGE', 'LOT' => 'LOTISSEMENT', 'R' => 'RUE', 'ST' => 'SAINT', 'STE' => 'SAINTE');
    $have = array();
    foreach (bk_geo_words($typed) as $w) $have[$short[$w] ?? $w] = true;
    $n = 0;
    foreach (bk_geo_words($name) as $w) {
        $w = $short[$w] ?? $w;
        if (preg_match('/^(LA|LE|LES|DE|DU|DES|ET|AU|AUX|EN|L|D|A)$/', $w)) continue;   // articles
        if (!isset($have[$w])) return false;
        $n++;
    }
    return $n > 1;   // a type and a name at least
}

/**
 * The venue itself (the venue field, with the town when it is not in it already): ['lat', 'lng'] or null.
 * The whole text is tried, then without its first part ("the X gym, street of Y": the name of
 * the building often lowers the score), and the best answer of a street, a house number or a
 * place IN THE TOWN of the competition ($citycode: INSEE code of the town, as the Base Adresse
 * Nationale gives it) is kept, when it is likely enough (score 0.5). Never a point of another
 * town: a namesake street elsewhere would be worse than the town centre.
 */
function bk_geocode_venue($where, $town, $citycode)
{
    $where = trim((string) $where);
    $town = trim((string) $town);
    if ($where === '' || $town === '' || (string) $citycode === '') return null;
    $addr = $where . (mb_stripos($where, $town) === false ? ' ' . $town : '');
    $tries = array($addr);
    $parts = array_map('trim', explode(',', $where));
    if (count($parts) > 1) {
        $rest = implode(' ', array_slice($parts, 1));
        $tries[] = $rest . (mb_stripos($rest, $town) === false ? ' ' . $town : '');
    }
    $best = null;
    foreach ($tries as $q) {
        foreach (bk_geocode_all($q, '', 5, $citycode) as $g) {
            if (!in_array($g['type'], array('housenumber', 'street', 'locality'), true) || $g['score'] < 0.5) continue;
            if ($g['citycode'] !== (string) $citycode || !bk_geo_same_street($g['name'], $where)) continue;
            if ($best === null || $g['score'] > $best['score']) $best = $g;
        }
    }
    return $best;
}

/** What the cached point was computed from (BcGeoSrc, 160 characters): the address and the town. */
function bk_geo_key($where, $town)
{
    return 'A:' . md5(trim((string) $where) . '|' . trim((string) $town));
}

/**
 * Coordinates of a competition, geocoded on demand (once) and cached: its address (venue field and
 * town), else the centre of its town. Geocodes again when the address or the town changed since
 * the last cache — a venue corrected in ianseo after the creation moves the marker. Returns
 * ['lat', 'lng'] or null (no town / geocoding impossible). Writes only when needed.
 */
function bk_comp_geocode($tourId)
{
    bk_schema();
    $tourId = intval($tourId);

    $r = safe_fetch(safe_r_sql("SELECT ToWhere, ToVenue, BcLat, BcLng, BcGeoSrc
        FROM Tournament LEFT JOIN BookingCompetitions ON BcTournament = ToId
        WHERE ToId = $tourId"));
    if (!$r) return null;
    $town = trim((string) $r->ToVenue);
    if ($town === '') return null;
    $key = bk_geo_key($r->ToWhere, $town);
    $venue = $key;   // what BcGeoSrc remembers below

    // Valid cache: same address — with its point, or found nowhere (not asked again).
    if ((string) $r->BcGeoSrc === $key) {
        return ($r->BcLat !== null && $r->BcLng !== null) ? array('lat' => (float) $r->BcLat, 'lng' => (float) $r->BcLng) : null;
    }

    $GLOBALS['_bk_geo_unreachable'] = false;
    $tg = bk_geocode($town);
    $g = $tg ? (bk_geocode_venue($r->ToWhere, $town, $tg['citycode']) ?: $tg) : null;
    if (!empty($GLOBALS['_bk_geo_unreachable']) && (!$g || $g === $tg)) {
        // The service did not answer: nothing remembered, the next display tries again; the
        // former point meanwhile (the town centre of an earlier version, for instance).
        return ($r->BcLat !== null && $r->BcLng !== null) ? array('lat' => (float) $r->BcLat, 'lng' => (float) $r->BcLng) : null;
    }
    if (!$g) {
        // Remembers the try (BcGeoSrc) without coordinates → no endless retries for the same
        // address; a new venue or town starts a new geocoding.
        safe_w_sql("INSERT INTO BookingCompetitions SET BcTournament = $tourId, BcGeoSrc = " . StrSafe_DB($venue) . "
            ON DUPLICATE KEY UPDATE BcGeoSrc = " . StrSafe_DB($venue) . ", BcLat = NULL, BcLng = NULL");
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
/* Base map — IGN tiles, loaded by the archer's browser                */
/* ------------------------------------------------------------------ */

/**
 * Base layers of the map: IGN Geoplateforme, WMTS on the web mercator grid ("PM"). Free, no
 * key, no cookie (checked on the answers). The plan stops at zoom 7 over New Caledonia and
 * French Polynesia (tested): public/assets/map.js then takes the photo tile of the same place
 * ('fallback').
 */
function bk_map_layers()
{
    $wmts = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&STYLE=normal'
        . '&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}';
    return array(
        'plan'  => array('label' => bk_t('MapPlan'), 'fallback' => 'photo',
                         'url' => $wmts . '&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&FORMAT=image/png'),
        'photo' => array('label' => bk_t('MapPhoto'),
                         'url' => $wmts . '&LAYER=ORTHOIMAGERY.ORTHOPHOTOS&FORMAT=image/jpeg'),
    );
}

/**
 * Areas of the map as [latMin, latMax, lngMin, lngMax]: mainland France (Corsica included) and
 * the overseas territories that have a quick-access button (label: MapInset<code>). A point in
 * none of them is still shown, it just has no button.
 */
function bk_map_areas()
{
    return array(
        'metro' => array(41.2, 51.2, -5.3, 9.7),
        '971'   => array(15.8, 16.6, -61.9, -60.9),
        '972'   => array(14.3, 14.95, -61.3, -60.75),
        '973'   => array(2.0, 5.9, -54.7, -51.5),
        '974'   => array(-21.45, -20.8, 55.15, 55.9),
        '976'   => array(-13.1, -12.55, 44.9, 45.35),
        '988'   => array(-23.0, -19.0, 163.0, 168.5),
        '987'   => array(-28.0, -7.0, -155.0, -134.0),
    );
}

/** Area code of a point (see bk_map_areas), '' outside all of them. */
function bk_map_area_of($lat, $lng)
{
    foreach (bk_map_areas() as $code => $b) {
        if ($lat >= $b[0] && $lat <= $b[1] && $lng >= $b[2] && $lng <= $b[3]) return (string) $code;
    }
    return '';
}
