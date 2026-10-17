<?php
/**
 * public/map.php — map of the competitions: IGN base map loaded by the archer's browser straight
 * from IGN (lib/geo.php, assets/map.js), markers at the towns geocoded by the server.
 * Discipline / date filters (default: today → D+14). One colour per discipline (dot in the
 * filter), para as an outline, past competitions paler, unofficial ones in charcoal.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/geo.php';
require_once dirname(__DIR__) . '/lib/ffta-event.php';   // venue read on the FFTA extranet

$archer = bk_require_archer();

// Leftovers of the former SVG map: a module update never deletes a file.
foreach (array('departements.geojson', 'france-map.cache') as $old) {
    if (is_file(__DIR__ . '/assets/' . $old)) @unlink(__DIR__ . '/assets/' . $old);
}

$facets = bk_comp_facets();
$labels = bk_disc_labels();
$today  = bk_today();   // server-zone date (date() alone is UTC on these pages)

// Filters (URL). Default dates: today → D+14. (No region filter: the map shows it.)
$disc = (string) ($_GET['disc'] ?? '');
if ($disc !== '' && $disc !== 'para' && !isset($facets['disc'][$disc])) $disc = '';
$dchk = function ($v, $def) { return (isset($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) ? (string) $v : $def; };
$from = $dchk($_GET['from'] ?? null, $today);
$to   = $dchk($_GET['to'] ?? null, date('Y-m-d', strtotime($today . ' +14 days')));

// Published competitions matching the filters (period overlap).
$w = array("BcOpen = 1", "ToWhenTo >= " . StrSafe_DB($from), "ToWhenFrom <= " . StrSafe_DB($to));
if ($disc === 'para') $w[] = "ToTypeSubRule LIKE '%Para%'";
elseif ($disc !== '') { $types = bk_disc_types($disc); $w[] = $types ? "ToType IN (" . implode(',', array_map('intval', $types)) . ")" : "1=0"; }

$rs = safe_r_sql("SELECT ToId, ToName, ToVenue, ToWhere, ToWhenFrom, ToWhenTo, ToType,
            ToTypeName, ToTypeSubRule, BcLat, BcLng, BcGeoSrc, " . bk_ffta_cols_sql() . "
        FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament" . bk_ffta_join_sql() . "
        WHERE " . implode(' AND ', $w) . " ORDER BY ToWhenFrom");
$comps = array();
while ($r = safe_fetch($rs)) $comps[] = $r;

// Geocoding on demand, capped per page load (one network call per new town).
$cap = 15; $pending = 0;
foreach ($comps as $c) {
    // The venue itself, read on the FFTA extranet, while it is still the competition's.
    if ($p = bk_ffta_point($c)) { $c->BcLat = $p['lat']; $c->BcLng = $p['lng']; continue; }
    $venue = trim((string) $c->ToVenue);
    if ($venue === '') continue;
    if ($c->BcLat !== null && $c->BcLng !== null && (string) $c->BcGeoSrc === $venue) continue;
    if ($cap > 0) { $cap--; $g = bk_comp_geocode(intval($c->ToId)); if ($g) { $c->BcLat = $g['lat']; $c->BcLng = $g['lng']; } }
    else $pending++;
}

$mine = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) $mine[intval($r->BrTournament)] = true;

// Grouped by POINT (same place → one marker). The "unofficial" rendering (charcoal) EXISTS
// (bk_disc_color) but no data tells an unofficial competition apart today → all official for
// now. The day a signal exists, compute $official here (the rest is ready).
$points = array(); $located = 0; $inArea = array();
foreach ($comps as $c) {
    if ($c->BcLat === null || $c->BcLng === null) continue;
    $lat = (float) $c->BcLat; $lng = (float) $c->BcLng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
    $located++;
    $dd = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
    $official = true;   // TODO: no field tells an unofficial competition apart today
    $key = round($lat, 4) . '_' . round($lng, 4);
    if (!isset($points[$key])) {
        $area = bk_map_area_of($lat, $lng);
        if ($area !== '') $inArea[$area] = true;
        $points[$key] = array('lat' => round($lat, 5), 'lng' => round($lng, 5), 'area' => $area, 'items' => array());
    }
    $points[$key]['items'][] = array(
        'id'   => intval($c->ToId),
        'name' => (string) $c->ToName,
        'city' => trim((string) $c->ToVenue),
        'date' => bk_date_range($c->ToWhenFrom, $c->ToWhenTo),
        'fill' => bk_disc_color($dd['key'], $official),
        'para' => (bool) $dd['para'],
        'past' => (substr((string) $c->ToWhenTo, 0, 10) < $today),   // bytes: ASCII date
        'in'   => isset($mine[intval($c->ToId)]),
        'icon' => bk_disc_icon($dd['key'], 18) . ((bool) $dd['para'] ? ' ' . bk_disc_icon_para(14) : ''),
    );
}
// Marker of a point: colour of its discipline (grey when several are mixed); para outline and
// paler past colour only when they apply to all its competitions.
foreach ($points as $k => $p) {
    $fills = array(); $allPara = true; $allPast = true;
    foreach ($p['items'] as $it) { $fills[$it['fill']] = true; if (!$it['para']) $allPara = false; if (!$it['past']) $allPast = false; }
    $points[$k]['fill'] = (count($fills) === 1) ? array_key_first($fills) : '#6b7480';
    $points[$k]['para'] = $allPara;
    $points[$k]['past'] = $allPast;
}

/** Keeps the filters while changing one parameter. */
function bk_map_url($over = array())
{
    global $disc, $from, $to;
    $p = array('disc' => $disc, 'from' => $from, 'to' => $to);
    foreach ($over as $k => $v) $p[$k] = $v;
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return bk_public_url('map.php') . '?' . http_build_query($p);
}

bk_head(bk_t('MapTitle'));
?>
<style>
#bk .bk-map-filters { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:flex-end; margin:0 0 10px; }
#bk .bk-map-dates { display:flex; gap:8px; align-items:flex-end; }
/* margin:0 — the generic "#bk label" spacing is meant for forms, here it shifts the dates. */
#bk .bk-map-dates label { display:flex; flex-direction:column; margin:0; font-size:11px; color:#7d8183; gap:2px; }
#bk .bk-map-dates input { padding:5px 7px; border:1px solid #d2d4d6; border-radius:6px; font-size:13px; }
/* Short date (2-digit year), shown on a phone only (below). */
#bk .bk-date { position:relative; display:inline-flex; }
#bk .bk-date-txt { display:none; }
/* Phone: the disciplines fold behind their button next to the dates (bk-fold, in bk.css),
   "From" / "To" sit on the line of their date, so that the map comes up the page.
   A date field cannot shorten its own year: its short text is shown and the real field lies
   over it, transparent, so that a touch reaches it (map.js then asks for its picker). */
@media (max-width:640px){
  #bk .bk-map-filters { gap:8px; }
  #bk .bk-map-dates { order:1; width:100%; flex-wrap:nowrap; align-items:center; gap:8px; }
  #bk .bk-map-dates label { flex-direction:row; align-items:center; gap:4px; font-size:12px; }
  #bk .bk-date-txt { display:block; padding:5px 7px; border:1px solid #d2d4d6; border-radius:6px; background:#fff;
      color:#20263d; font-size:14px; font-weight:400; line-height:16px; white-space:nowrap; }
  #bk .bk-date input { position:absolute; inset:0; width:100%; height:100%; margin:0; padding:0; border:0;
      opacity:0; cursor:pointer; }
}
@media (max-width:340px){
  #bk .bk-map-dates { gap:6px; }
  #bk .bk-map-dates label { gap:3px; }
  #bk .bk-date-txt { padding:5px 5px; font-size:13px; }
}
/* Colour dot of the discipline in the filter (stands for the legend). */
#bk .bk-dc-dot { width:12px; height:12px; border-radius:50%; border:1.5px solid #fff;
    box-shadow:0 0 0 1px rgba(0,0,0,.18); flex:0 0 auto; }
#bk .bk-dchip.on .bk-dc-dot { box-shadow:0 0 0 1px rgba(255,255,255,.6); }
#bk .bk-dc-dot.para { border-color:#A0006D; border-width:2.5px; }
/* Quick access to mainland France and to the overseas territories that have competitions. */
#bk .bk-map-areas { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 8px; }
#bk .bk-map-areas .bk-dchip { font:inherit; font-size:12px; padding:5px 10px; cursor:pointer; }
/* Map: the screen height minus the header and the filters, never smaller than a phone screen. */
#bk .bk-map-wrap { border:1px solid #d2d4d6; border-radius:8px; overflow:hidden; }
#bk .bk-map { height:calc(100vh - 230px); min-height:360px; background:#eef3f8; }
/* Markers: discipline colour in a CSS variable, never an inline background (THEME dark mode
   rewrites the text colour of elements with a light inline background). */
#bk .bk-mk-box { background:none; border:0; }
#bk .bk-mk { display:flex; align-items:center; justify-content:center; width:100%; height:100%;
    border-radius:50%; background:var(--c, #6b7480); border:2px solid #fff;
    box-shadow:0 0 0 1px rgba(0,0,0,.3), 0 1px 4px rgba(0,0,0,.45); color:#fff; font-size:11px; font-weight:700; line-height:1; }
#bk .bk-mk.para { border:3px solid #A0006D; }
/* Past: paler colour but opaque, so that the marker stays visible on the aerial photos. */
#bk .bk-mk.past { background:color-mix(in srgb, var(--c, #6b7480) 40%, #fff); color:#20263d; }
/* Layer switch and reset button, among the Leaflet controls. */
#bk .bk-map-switch { display:flex; background:#fff; }
#bk .bk-map-switch button { border:0; background:#fff; padding:6px 10px; font:inherit; font-size:12px;
    color:#01367c; cursor:pointer; }
#bk .bk-map-switch button + button { border-left:1px solid #ccc; }
#bk .bk-map-switch button.on { background:#0254a8; color:#fff; }
#bk .bk-map-reset { font-size:16px; }
/* Popup listing the competitions of a marker. */
#bk .bk-map-pop .leaflet-popup-content { margin:10px 12px; font-size:13px; line-height:1.35; }
#bk .bk-map-pop h3 { margin:0 0 6px; padding-right:14px; font-size:12px; color:#01367c; }
#bk .bk-map-pop a.bk-mp-c { display:flex; gap:8px; align-items:flex-start; padding:5px 0; color:#20263d;
    text-decoration:none; border-top:1px solid #eef1f6; }
#bk .bk-map-pop h3 + a.bk-mp-c { border-top:0; }
#bk .bk-map-pop a.bk-mp-c:hover { color:#0254a8; }
#bk .bk-mp-ic { flex:0 0 auto; display:inline-flex; align-items:center; gap:2px; padding-top:1px; }
#bk .bk-mp-ic img { display:block; }
#bk .bk-mp-tx { flex:1 1 auto; min-width:0; }
#bk .bk-map-pop .bk-mp-in { color:#1a8a3f; font-weight:700; }
</style>
<?php
$chip = function ($on, $url, $inner) {
    return '<a class="bk-dchip' . ($on ? ' on' : '') . '" href="' . bk_e($url) . '">' . $inner . '</a>';
};
$out = '<div class="bk-map-filters bk-fold"><div class="bk-cal-disc" id="bk-map-disc">' . $chip($disc === '', bk_map_url(array('disc' => '')), bk_e(bk_t('All')));
foreach ($labels as $k => $lab) {
    if (empty($facets['disc'][$k])) continue;
    $out .= $chip($disc === $k, bk_map_url(array('disc' => $k)), bk_disc_icon($k, 18) . '<span>' . bk_e($lab) . '</span>'
        . '<i class="bk-dc-dot" style="background:' . bk_e(bk_disc_color($k, true)) . '"></i>');
}
if (!empty($facets['para'])) {
    $out .= $chip($disc === 'para', bk_map_url(array('disc' => 'para')), bk_disc_icon_para(18) . '<span>Para</span>'
        . '<i class="bk-dc-dot para" style="background:#6b7480"></i>');
}
// Date field: the real one, plus its short text (MapDateFmt, 2-digit year) for a phone.
$dateField = function ($name, $label, $value) {
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return '<label>' . bk_e($label) . ' <span class="bk-date"><span class="bk-date-txt" aria-hidden="true">'
        . bk_e($d ? $d->format(bk_t('MapDateFmt')) : $value) . '</span>'
        . '<input type="date" name="' . bk_e($name) . '" value="' . bk_e($value) . '" onchange="this.form.submit()"></span></label>';
};
$out .= '</div><form class="bk-map-dates" method="get" action="' . bk_e(bk_public_url('map.php')) . '">'
    . '<input type="hidden" name="disc" value="' . bk_e($disc) . '">'
    . $dateField('from', bk_t('MapFrom'), $from) . $dateField('to', bk_t('MapTo'), $to)
    . bk_disc_fold_btn($disc, 'bk-map-disc') . '</form></div>';

if ($pending) $out .= '<p class="bk-hint">' . bk_e(bk_t('MapPending', intval($pending))) . '</p>';
if (!$located && !$pending) $out .= '<p class="bk-hint">' . bk_e(bk_t('MapNothing')) . '</p>';

// One button per overseas territory that has a competition in the list (they are far from
// mainland France: without them, nobody would find these markers).
$areas = bk_map_areas();
$over  = array_diff_key($inArea, array('metro' => true));
if ($over) {
    $out .= '<div class="bk-map-areas"><button type="button" class="bk-dchip" data-area="metro">' . bk_e(bk_t('MapMetro')) . '</button>';
    foreach ($areas as $code => $b) {
        if (isset($over[$code])) $out .= '<button type="button" class="bk-dchip" data-area="' . bk_e($code) . '">' . bk_e(bk_t('MapInset' . $code)) . '</button>';
    }
    $out .= '</div>';
}

$conf = array(
    'layers'      => bk_map_layers(),
    'attribution' => '© <a href="https://www.ign.fr/" target="_blank" rel="noopener">IGN</a>',
    'areas'       => $areas,
    'points'      => array_values($points),
    'compUrl'     => bk_public_url('competition.php?t='),
    't'           => array('many' => bk_t('MapNComps', '{n}'), 'one' => bk_t('MapOneComp'), 'close' => bk_t('Close'),
                           'zoomIn' => bk_t('MapZoomIn'), 'zoomOut' => bk_t('MapZoomOut'), 'reset' => bk_t('MapReset')),
);
$v  = bk_e(bk_version());
$lf = bk_public_url('assets/vendor/leaflet/');
$out .= '<link rel="stylesheet" href="' . bk_e($lf . 'leaflet.css') . '?v=' . $v . '">'
    . '<div class="bk-map-wrap"><div id="bk-map" class="bk-map" role="region" aria-label="' . bk_e(bk_t('MapTitle')) . '"'
    . ' data-map="' . bk_e(json_encode($conf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"></div></div>'
    . '<script src="' . bk_e($lf . 'leaflet.js') . '?v=' . $v . '"></script>'
    . '<script src="' . bk_e(bk_public_url('assets/map.js')) . '?v=' . $v . '"></script>';
echo $out;

bk_foot();
