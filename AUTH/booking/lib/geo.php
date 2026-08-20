<?php
/**
 * lib/geo.php — géolocalisation des compétitions pour la CARTE (option 1 : carte SVG
 * de France + marqueurs de ville, SANS tuiles externes ni fuite d'IP côté navigateur).
 *
 * Le seul appel sortant est le GÉOCODAGE, fait CÔTÉ SERVEUR et UNE SEULE FOIS par
 * compétition (résultat mis en cache dans BK_Competitions : BcLat/BcLng/BcGeoSrc),
 * via la Base Adresse Nationale (api-adresse.data.gouv.fr — service public FR, gratuit,
 * sans clé). Désactivable via config.local.json → "geo": {"enabled": false}.
 *
 * ⚠️ La ville est le champ ToVenue (ToWhere = lieu précis : gymnase/stade).
 */

if (defined('BK_GEO_LOADED')) return;
define('BK_GEO_LOADED', true);

require_once __DIR__ . '/schema.php';

/** Config géo (config.local.json → "geo"), avec défauts. */
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
 * Géocode une ville via la Base Adresse Nationale. Retour ['lat','lng','label'] ou null.
 * Jamais d'exception : au moindre souci (réseau, réponse inattendue), on renvoie null.
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
        CURLOPT_USERAGENT      => 'ianseo-booking/1.0 (+carte compétitions)',
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
 * Coordonnées d'une compétition, géocodées à la demande (une fois) et mises en cache.
 * Re-géocode si la ville (ToVenue) a changé depuis le dernier cache. Retour
 * ['lat','lng'] ou null (ville absente / géocodage impossible). N'écrit qu'au besoin.
 */
function bk_comp_geocode($tourId)
{
    bk_schema();
    $tourId = intval($tourId);

    $r = safe_fetch(safe_r_sql("SELECT t.ToVenue, o.BcLat, o.BcLng, o.BcGeoSrc
        FROM Tournament t LEFT JOIN BK_Competitions o ON o.BcTournament = t.ToId
        WHERE t.ToId = $tourId"));
    if (!$r) return null;
    $venue = trim((string) $r->ToVenue);
    if ($venue === '') return null;

    // Cache valide : même ville ET coordonnées présentes.
    if ((string) $r->BcGeoSrc === $venue && $r->BcLat !== null && $r->BcLng !== null) {
        return array('lat' => (float) $r->BcLat, 'lng' => (float) $r->BcLng);
    }

    $g = bk_geocode($venue);
    if (!$g) {
        // Mémorise la tentative (BcGeoSrc) sans coordonnées → on ne re-tente pas en boucle
        // pour la même ville ; un nouveau ToVenue relancera un géocodage.
        safe_w_sql("INSERT INTO BK_Competitions SET BcTournament = $tourId, BcGeoSrc = " . StrSafe_DB($venue) . "
            ON DUPLICATE KEY UPDATE BcGeoSrc = " . StrSafe_DB($venue));
        return null;
    }
    safe_w_sql("INSERT INTO BK_Competitions SET BcTournament = $tourId,
        BcLat = " . StrSafe_DB(number_format($g['lat'], 6, '.', '')) . ",
        BcLng = " . StrSafe_DB(number_format($g['lng'], 6, '.', '')) . ",
        BcGeoSrc = " . StrSafe_DB($venue) . "
        ON DUPLICATE KEY UPDATE BcLat = VALUES(BcLat), BcLng = VALUES(BcLng), BcGeoSrc = VALUES(BcGeoSrc)");
    return array('lat' => $g['lat'], 'lng' => $g['lng']);
}

/* ------------------------------------------------------------------ */
/* Fond de carte (SVG) — métropole en grand cadre + DROM en mini-cadres */
/* ------------------------------------------------------------------ */

/**
 * Cadres de projection dans un viewBox 0 0 1000 1000. Une projection équirectangulaire
 * GLOBALE écraserait la métropole (la Guyane est à ~50° de longitude d'écart) : chaque
 * territoire ultramarin a donc SON cadre et SA bbox → encarts empilés dans la colonne de
 * droite (métropole = grand cadre à gauche).
 *
 * DROM (971-976) : bbox déduite de la géométrie du GeoJSON. COM (Nouvelle-Calédonie 988,
 * Polynésie 987) : ABSENTES du GeoJSON des départements → bbox FIXE fournie ici pour que
 * les marqueurs y atterrissent (la Base Adresse Nationale géocode ces deux territoires ;
 * le cadre est dessiné vide sinon). Les rectangles sont calculés (hauteur égale) afin
 * d'ajouter/retirer un territoire sans repositionner les autres à la main.
 */
function bk_map_groups()
{
    $insets = array(
        '971' => array('lat0' => 16.2,  'label' => 'Guadeloupe'),
        '972' => array('lat0' => 14.6,  'label' => 'Martinique'),
        '973' => array('lat0' => 4.0,   'label' => 'Guyane'),
        '974' => array('lat0' => -21.1, 'label' => 'La Réunion'),
        '976' => array('lat0' => -12.8, 'label' => 'Mayotte'),
        '988' => array('lat0' => -21.2, 'label' => 'Nouvelle-Calédonie', 'bbox' => array(163.9, 168.3, -22.9, -19.4)),
        '987' => array('lat0' => -17.6, 'label' => 'Polynésie fr.',       'bbox' => array(-150.0, -149.05, -17.98, -17.42)),
    );
    $g = array('metro' => array('rect' => array(6, 6, 756, 988), 'lat0' => 46.6, 'label' => ''));
    $colX = 770; $colW = 224; $top = 6; $bottom = 994; $gap = 6; $n = count($insets);
    $bh = ($bottom - $top - $gap * ($n - 1)) / $n;
    $i = 0;
    foreach ($insets as $code => $cfg) {
        $y = $top + $i++ * ($bh + $gap);
        $g[$code] = array('rect' => array($colX, round($y, 1), $colW, round($bh, 1)),
                          'lat0' => $cfg['lat0'], 'label' => $cfg['label']);
        if (isset($cfg['bbox'])) $g[$code]['bbox'] = $cfg['bbox'];
    }
    return $g;
}

/**
 * Contours SIMPLIFIÉS des COM absentes du GeoJSON des départements (Nouvelle-Calédonie,
 * Polynésie), au format « feature » GeoJSON (properties.code/nom + geometry MultiPolygon).
 * Tracés approximatifs mais reconnaissables, en lng/lat → projetés dans l'encart comme un
 * département, pour que le marqueur repose sur une terre et non dans un rectangle vide.
 */
function bk_com_features()
{
    return array(
        array('properties' => array('code' => '988', 'nom' => 'Nouvelle-Calédonie'),
            'geometry' => array('type' => 'MultiPolygon', 'coordinates' => array(
                array(array(  // Grande Terre
                    array(164.05, -20.25), array(164.55, -20.28), array(165.00, -20.68), array(165.30, -20.92),
                    array(165.62, -21.28), array(165.95, -21.45), array(166.25, -21.62), array(166.62, -21.92),
                    array(166.95, -22.18), array(167.00, -22.38), array(166.70, -22.35), array(166.45, -22.27),
                    array(166.10, -21.90), array(165.82, -21.70), array(165.48, -21.56), array(165.10, -21.28),
                    array(164.82, -21.05), array(164.50, -20.75), array(164.28, -20.55), array(164.10, -20.38),
                    array(164.05, -20.25),
                )),
                array(array(array(166.45, -20.45), array(166.65, -20.48), array(166.68, -20.65), array(166.50, -20.68), array(166.45, -20.45))), // Ouvéa
                array(array(array(167.05, -20.75), array(167.45, -20.78), array(167.48, -21.05), array(167.10, -21.08), array(167.05, -20.75))), // Lifou
                array(array(array(167.82, -21.40), array(168.12, -21.42), array(168.15, -21.62), array(167.85, -21.62), array(167.82, -21.40))), // Maré
            ))),
        array('properties' => array('code' => '987', 'nom' => 'Polynésie française'),
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

/** Groupe d'un code département : lui-même si territoire ultramarin connu, sinon 'metro'. */
function bk_map_group_of($code)
{
    $code = (string) $code;
    $g = bk_map_groups();
    return ($code !== 'metro' && isset($g[$code])) ? $code : 'metro';
}

/** Ajuste une bbox [lngMin,lngMax,latMin,latMax] dans un rect [x,y,w,h] (aspect préservé). */
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

/** Projette (lng,lat) dans un cadre bk_map_fit → [x, y]. */
function bk_map_xy($p, $lng, $lat)
{
    return array($p['ox'] + ($lng - $p['lngMin']) * $p['k'] * $p['s'],
                 $p['oy'] + ($p['latMax'] - $lat) * $p['s']);
}

/**
 * Géométrie du fond de carte, projetée et SIMPLIFIÉE (décimation à ~0,8 px), mise en
 * CACHE (sérialisée, invalidée si le GeoJSON change) — on ne relit pas 3,5 Mo par requête.
 * Retour : ['proj'=>[groupe=>params], 'paths'=>[ ['code','nom','group','d','cx','cy'] ], 'ok'=>bool].
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

    // 1) bbox par groupe (métropole = union des départements métropolitains). Les COM
    // (contours simplifiés, hors GeoJSON) sont ajoutées ici pour être tracées comme un
    // département ; leur PROJECTION garde la bbox fixe de bk_map_groups (voir étape 2).
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

    // 2) projection par groupe. Pour les COM, la bbox FIXE prime (cadrage généreux : les
    // marqueurs de villes non couvertes par le contour simplifié atterrissent quand même).
    $proj = array();
    foreach ($groups as $g => $cfg) {
        $bb = ($cfg['bbox'] ?? null) ?: ($bbox[$g] ?? null);
        if (!$bb) continue;
        $p = bk_map_fit($bb, $cfg['rect'], $cfg['lat0']);
        if (!$p) continue;
        $p['label'] = $cfg['label']; $p['rectArr'] = $cfg['rect'];
        $proj[$g] = $p;
    }

    // 3) chemins SVG (projetés, décimés). Centroïde = moyenne des sommets du plus gros anneau.
    // eps = seuil de décimation en px (viewBox 1000) : plus haut = plus léger. 1.6 px reste
    // net même zoomé au département (0,3 px effectif à 5×), pour un poids ~3× moindre.
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
            if (count($pts) > $bestN) {   // centroïde depuis l'anneau le plus détaillé (le principal)
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
 * Position d'un marqueur (lat,lng) sur le fond : trouve le groupe dont la bbox le contient
 * (métropole ou un DROM) puis projette. Retour [x,y] ou null.
 */
function bk_map_marker_xy($proj, $lat, $lng)
{
    // Territoires ultramarins d'abord (bbox restreintes, prioritaires), métropole en dernier.
    foreach ($proj as $g => $p) {
        if ($g === 'metro' || !$p) continue;
        $b = $p['bbox'];
        if ($lng >= $b[0] && $lng <= $b[1] && $lat >= $b[2] && $lat <= $b[3]) return bk_map_xy($p, $lng, $lat);
    }
    $p = $proj['metro'] ?? null;
    if ($p) { $b = $p['bbox']; if ($lng >= $b[0] && $lng <= $b[1] && $lat >= $b[2] && $lat <= $b[3]) return bk_map_xy($p, $lng, $lat); }
    return null;
}
