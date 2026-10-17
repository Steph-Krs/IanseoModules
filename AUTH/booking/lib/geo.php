<?php
/**
 * lib/geo.php — geolocation of the competitions for the MAP (public/map.php).
 *
 * Two parts, kept apart on purpose:
 *  - GEOCODING, done BY THE SERVER and ONCE per competition (result cached in
 *    BookingCompetitions: BcLat/BcLng/BcGeoSrc), through the Base Adresse Nationale
 *    (api-adresse.data.gouv.fr — French public service, free, no key). Can be turned off with
 *    config.local.json → "geo": {"enabled": false}. Only the town of the competition is sent.
 *  - BASE MAP: tiles of the IGN Geoplateforme (data.geopf.fr — French public body, free, no
 *    key, no cookie), loaded by the ARCHER'S BROWSER straight from IGN: nothing goes through
 *    this server. Displayed with Leaflet, shipped in public/assets/vendor/leaflet and never
 *    taken from a CDN, so that no other third party sees the visitors.
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
