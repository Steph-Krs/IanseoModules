<?php
/**
 * lib/ffta-event.php — what the SYNCHRO_FFTA module read on the FFTA extranet about a competition:
 * its FftaEvents table, matched by the competition code (FeCode = Tournament.ToCode).
 *
 * Read only, and only when that table exists: AUTH works the same without SYNCHRO_FFTA, and a
 * competition not created from the extranet simply has no row.
 *  - Precise venue and coordinates (bk_ffta_point): for the map and the itinerary links, but only
 *    while they still describe the venue of the competition. SYNCHRO_FFTA clears the coordinates
 *    when the organiser replaces the venue at creation (FeVenueOwn); a venue changed later in
 *    ianseo is caught here, the street and the town of the extranet having to be in « Lieu ».
 *    Otherwise everything works as before: town geocoded by lib/geo.php, address as typed.
 *  - Matches announced on the extranet (bk_ffta_duels_check): compared with the events set up.
 */

if (defined('BK_FFTA_EVENT_LOADED')) return;
define('BK_FFTA_EVENT_LOADED', true);

require_once __DIR__ . '/schema.php';

/** Does the FftaEvents table of SYNCHRO_FFTA exist? Asked once per request, never fails. */
function bk_ffta_ready()
{
    static $ready = null;
    if ($ready === null) {
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FftaEvents'"));
        $ready = $r && intval($r->n) > 0;
    }
    return $ready;
}

/** Columns of FftaEvents read by AUTH, for a SELECT joined with bk_ffta_join_sql(). */
function bk_ffta_cols_sql()
{
    return bk_ffta_ready()
        ? "FeVenueStreet, FeVenueCity, FeLatitude, FeLongitude, FeVenueOwn, FeDuels, FeDetailAt"
        : "NULL AS FeVenueStreet, NULL AS FeVenueCity, NULL AS FeLatitude, NULL AS FeLongitude,
           NULL AS FeVenueOwn, NULL AS FeDuels, NULL AS FeDetailAt";
}

/** LEFT JOIN of FftaEvents for a query on Tournament ('' without the table). */
function bk_ffta_join_sql()
{
    return bk_ffta_ready() ? " LEFT JOIN FftaEvents ON FeCode" . bk_coll() . "= ToCode " : '';
}

/** Row of a competition (ToWhere, ToVenue + the columns above), or null. */
function bk_ffta_row($tourId)
{
    $rs = safe_r_sql("SELECT ToWhere, ToVenue, " . bk_ffta_cols_sql() . "
        FROM Tournament" . bk_ffta_join_sql() . " WHERE ToId = " . intval($tourId), false, true);
    return $rs ? (safe_fetch($rs) ?: null) : null;
}

/** Upper case without accents nor repeated spaces, to compare addresses typed differently. */
function bk_ffta_norm($s)
{
    $s = mb_strtoupper(trim((string) $s), 'UTF-8');
    $s = strtr($s, array('À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ÿ' => 'Y', 'Œ' => 'OE', 'Æ' => 'AE', '-' => ' ', '\'' => ' ', '’' => ' ', ',' => ' '));
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Extranet coordinates of the venue, when they still describe it: ['lat', 'lng'] or null.
 * $r: a row carrying ToWhere and the FftaEvents columns (bk_ffta_row, or a query using
 * bk_ffta_cols_sql / bk_ffta_join_sql).
 */
function bk_ffta_point($r)
{
    if (!$r || $r->FeLatitude === null || $r->FeLongitude === null || intval($r->FeVenueOwn) !== 0) return null;
    $where = bk_ffta_norm($r->ToWhere ?? '');
    $city  = bk_ffta_norm($r->FeVenueCity ?? '');
    $street = bk_ffta_norm($r->FeVenueStreet ?? '');
    if ($city === '' || strpos($where, $city) === false) return null;
    if ($street !== '' && strpos($where, $street) === false) return null;
    $lat = (float) $r->FeLatitude; $lng = (float) $r->FeLongitude;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return array('lat' => $lat, 'lng' => $lng);
}

/**
 * Itinerary links of a competition: [label => url] for the navigation applications. On the
 * extranet coordinates when they hold (bk_ffta_point), otherwise on the address typed in ianseo
 * (« Lieu » and town) — never on the centre of the town, which may be far from the field.
 */
function bk_itinerary_links($tourId, $print = false)
{
    $r = bk_ffta_row($tourId);
    if (!$r) return array();
    $p = bk_ffta_point($r);
    if ($p) {
        $ll = sprintf('%.6F,%.6F', $p['lat'], $p['lng']);
        return array(
            'Google Maps' => 'https://www.google.com/maps/dir/?api=1&destination=' . $ll,
            'Waze'        => bk_waze_url('ll=' . $ll, $print),
            bk_t('ItinApple') => 'https://maps.apple.com/?daddr=' . $ll,
        );
    }
    $where = trim((string) $r->ToWhere);
    $town  = trim((string) $r->ToVenue);
    $addr  = trim($where . ($town !== '' && mb_stripos($where, $town) === false ? ', ' . $town : ''), ', ');
    if ($addr === '') return array();
    $q = rawurlencode($addr);
    return array(
        'Google Maps' => 'https://www.google.com/maps/dir/?api=1&destination=' . $q,
        'Waze'        => bk_waze_url('q=' . $q, $print),
        bk_t('ItinApple') => 'https://maps.apple.com/?daddr=' . $q,
    );
}

/**
 * Waze link ($param: "ll=lat,lng" or "q=address", already encoded). On Android the web address
 * only opens the application when the system tied waze.com to it, which it often has not (real
 * case: the web page opened even with Waze installed): an intent link opens the application, and
 * its web page when it is missing. $print: a document read elsewhere (PDF) keeps the web address.
 */
function bk_waze_url($param, $print = false)
{
    $web = 'https://www.waze.com/ul?' . $param . '&navigate=yes';
    if (!$print && stripos((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Android') !== false) {
        return 'intent://?' . $param . '&navigate=yes#Intent;scheme=waze;package=com.waze;S.browser_fallback_url='
            . rawurlencode($web) . ';end';
    }
    return $web;
}

/**
 * Organiser pages: the warning of bk_ffta_duels_check() in a box, with links to the events pages
 * of ianseo where the match phases are set ('' when nothing to say).
 */
function bk_ffta_warning_html($tourId)
{
    global $CFG;
    $m = bk_ffta_duels_check($tourId);
    if ($m === '') return '';
    $links = '<a href="' . bk_e($CFG->ROOT_DIR . 'Final/Individual/ListEvents.php') . '">' . bk_e(bk_t('MnEventsInd')) . ' →</a>';
    $team = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM Events WHERE EvTournament = " . intval($tourId) . " AND EvTeamEvent = 1"));
    if ($team && intval($team->n) > 0) {
        $links .= ' &nbsp; <a href="' . bk_e($CFG->ROOT_DIR . 'Final/Team/ListEvents.php') . '">' . bk_e(bk_t('MnEventsTeam')) . ' →</a>';
    }
    return '<div class="bk-msg" style="background:#fff8e1;border:1px solid #e0a800;color:#5b4300;text-align:left">'
        . '⚠ ' . bk_e($m) . '<br>' . $links . '</div>';
}

/** « Itinerary: Google Maps · Waze · Plans » line of the archer side ('' without an address). */
function bk_itinerary_html($tourId)
{
    $links = array();
    foreach (bk_itinerary_links($tourId) as $label => $url) {
        // An application link (intent://) stays in the page: a new tab would be left blank.
        $tab = strpos($url, 'https://') === 0 ? ' target="_blank" rel="noopener"' : '';
        $links[] = '<a href="' . bk_e($url) . '"' . $tab . '>' . bk_e($label) . '</a>';
    }
    return $links ? '<p class="bk-itin">' . bk_e(bk_t('Itinerary')) . ' ' . implode(' · ', $links) . '</p>' : '';
}

/**
 * Matches announced on the extranet against the events set up in ianseo. Returns '' when they
 * agree or cannot be compared (no extranet detail read, no event yet), otherwise the warning for
 * the organiser.
 */
function bk_ffta_duels_check($tourId)
{
    $r = bk_ffta_row($tourId);
    if (!$r || $r->FeDetailAt === null || $r->FeDuels === null) return '';
    $e = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n, SUM(EvFinalFirstPhase > 0) AS d FROM Events
        WHERE EvTournament = " . intval($tourId) . " AND EvCodeParent = ''"));
    if (!$e || intval($e->n) === 0) return '';
    $here = intval($e->d) > 0;
    $there = intval($r->FeDuels) > 0;
    if ($here === $there) return '';
    return bk_t($there ? 'FfDuelsMissing' : 'FfDuelsExtra');
}
