<?php
/**
 * public/map.php — map of the competitions (option 1: SVG background of France + overseas
 * territories as insets, markers at the towns geocoded by the server, NO external tile).
 * Discipline / date filters (default: today → D+14). One colour per discipline (dot in the
 * filter), para as an outline, past competitions semi-transparent, unofficial ones in charcoal.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/geo.php';
require_once dirname(__DIR__) . '/lib/ffta-event.php';   // venue read on the FFTA extranet

$archer = bk_require_archer();

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

$geo = bk_map_geometry();

// Grouped by POINT (same town → one marker). Colour = discipline; para as an outline; past ones
// semi-transparent. The "unofficial" rendering (charcoal) EXISTS (bk_disc_color) but no data
// tells an unofficial competition apart today → all official for now. The day a signal exists,
// compute $official here (the rest is ready).
$clusters = array(); $located = 0;
foreach ($comps as $c) {
    if ($c->BcLat === null || $c->BcLng === null) continue;
    $xy = bk_map_marker_xy($geo['proj'], (float) $c->BcLat, (float) $c->BcLng);
    if (!$xy) continue;
    $located++;
    $dd = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
    $official = true;   // TODO: no field tells an unofficial competition apart today
    $key = round($xy[0], 0) . '_' . round($xy[1], 0);
    if (!isset($clusters[$key])) $clusters[$key] = array('x' => round($xy[0], 1), 'y' => round($xy[1], 1), 'items' => array());
    $clusters[$key]['items'][] = array(
        'id'    => intval($c->ToId),
        'name'  => (string) $c->ToName,
        'city'  => trim((string) $c->ToVenue),
        'date'  => bk_date_range($c->ToWhenFrom, $c->ToWhenTo),
        'fill'  => bk_disc_color($dd['key'], $official),
        'para'  => (bool) $dd['para'],
        'past'  => (substr((string) $c->ToWhenTo, 0, 10) < $today),   // bytes: ASCII date
        'in'    => isset($mine[intval($c->ToId)]),
        'icon'  => bk_disc_icon($dd['key'], 18) . ((bool) $dd['para'] ? ' ' . bk_disc_icon_para(14) : ''),
    );
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
#bk .bk-map-dates label { display:flex; flex-direction:column; font-size:11px; color:#7d8183; gap:2px; }
#bk .bk-map-dates input { padding:5px 7px; border:1px solid #d2d4d6; border-radius:6px; font-size:13px; }
/* Colour dot of the discipline in the filter (stands for the legend). */
#bk .bk-dc-dot { width:12px; height:12px; border-radius:50%; border:1.5px solid #fff;
    box-shadow:0 0 0 1px rgba(0,0,0,.18); flex:0 0 auto; }
#bk .bk-dchip.on .bk-dc-dot { box-shadow:0 0 0 1px rgba(255,255,255,.6); }
#bk .bk-dc-dot.para { border-color:#A0006D; border-width:2.5px; }
/* Map: fits the screen height on a computer (centred square), full width on a phone. */
#bk .bk-map-wrap { position:relative; border:1px solid #d2d4d6; border-radius:8px; overflow:hidden; background:#eef3f8; }
#bk .bk-map { display:block; margin:0 auto; width:min(100%, calc(100vh - 200px));
    height:auto; touch-action:none; cursor:grab; }
#bk .bk-map.drag { cursor:grabbing; }
#bk .bk-dept { fill:#dbe6f2; stroke:#7fa0c2; stroke-width:.6; vector-effect:non-scaling-stroke; }
#bk .bk-inset { fill:none; stroke:#b9c4d0; stroke-width:1; vector-effect:non-scaling-stroke; }
#bk .bk-inset-lb { font-size:11px; fill:#556; }
#bk .bk-deptlb { font-size:7px; fill:#41618a; text-anchor:middle; opacity:0; transition:opacity .15s; pointer-events:none; }
#bk .bk-map.zoomed .bk-deptlb { opacity:.85; }
#bk .bk-map.deep .bk-deptlb { font-size:3.5px; }   /* beyond the initial max zoom: number at ×0.5 */
#bk .bk-mk { cursor:pointer; }
#bk .bk-mk circle { stroke:#fff; stroke-width:1.4; }
#bk .bk-mk.para circle { stroke:#A0006D; stroke-width:2.4; }
#bk .bk-mk.past { opacity:.42; }
#bk .bk-mk text { font-size:8px; fill:#fff; text-anchor:middle; font-weight:700; pointer-events:none; }
#bk .bk-map-tools { position:absolute; top:8px; right:8px; display:flex; flex-direction:column; gap:4px; }
#bk .bk-map-tools button { width:30px; height:30px; border:1px solid #c9d4df; background:#fff; border-radius:6px;
    font-size:18px; line-height:1; cursor:pointer; color:#01367c; }
#bk .bk-map-tools button:hover { background:#eaf2ff; }
#bk .bk-map-pop { position:absolute; max-width:260px; background:#fff; border:1px solid #c9d4df; border-radius:8px;
    box-shadow:0 4px 16px rgba(0,0,0,.18); padding:8px 10px; font-size:13px; z-index:5; display:none; }
#bk .bk-map-pop h3 { margin:0 0 6px; font-size:12px; color:#01367c; }
#bk .bk-map-pop a { display:flex; gap:8px; align-items:flex-start; padding:5px 0; color:#20263d; text-decoration:none; border-top:1px solid #eef1f6; }
#bk .bk-map-pop a:first-of-type { border-top:0; }
#bk .bk-map-pop a:hover { color:#0254a8; }
#bk .bk-mp-ic { flex:0 0 auto; display:inline-flex; align-items:center; gap:2px; padding-top:1px; }
#bk .bk-mp-ic img { display:block; }
#bk .bk-mp-tx { flex:1 1 auto; min-width:0; }
#bk .bk-map-pop .bk-mp-in { color:#1a8a3f; font-weight:700; }
#bk .bk-map-x { float:right; cursor:pointer; color:#7d8183; font-weight:700; }
</style>
<?php
$chip = function ($on, $url, $inner) {
    return '<a class="bk-dchip' . ($on ? ' on' : '') . '" href="' . bk_e($url) . '">' . $inner . '</a>';
};
$out = '<div class="bk-map-filters"><div class="bk-cal-disc">' . $chip($disc === '', bk_map_url(array('disc' => '')), bk_e(bk_t('All')));
foreach ($labels as $k => $lab) {
    if (empty($facets['disc'][$k])) continue;
    $out .= $chip($disc === $k, bk_map_url(array('disc' => $k)), bk_disc_icon($k, 18) . '<span>' . bk_e($lab) . '</span>'
        . '<i class="bk-dc-dot" style="background:' . bk_e(bk_disc_color($k, true)) . '"></i>');
}
if (!empty($facets['para'])) {
    $out .= $chip($disc === 'para', bk_map_url(array('disc' => 'para')), bk_disc_icon_para(18) . '<span>Para</span>'
        . '<i class="bk-dc-dot para" style="background:#6b7480"></i>');
}
$out .= '</div><form class="bk-map-dates" method="get" action="' . bk_e(bk_public_url('map.php')) . '">'
    . '<input type="hidden" name="disc" value="' . bk_e($disc) . '">'
    . '<label>' . bk_e(bk_t('MapFrom')) . ' <input type="date" name="from" value="' . bk_e($from) . '" onchange="this.form.submit()"></label>'
    . '<label>' . bk_e(bk_t('MapTo')) . ' <input type="date" name="to" value="' . bk_e($to) . '" onchange="this.form.submit()"></label>'
    . '</form></div>';

if (!$geo['ok']) {
    $out .= '<p class="bk-blocked">' . bk_e(bk_t('MapNoBackground')) . '</p>';
} else {
    if ($pending) $out .= '<p class="bk-hint">' . bk_e(bk_t('MapPending', intval($pending))) . '</p>';
    if (!$located && !$pending) $out .= '<p class="bk-hint">' . bk_e(bk_t('MapNothing')) . '</p>';
    $scene = '';
    foreach ($geo['proj'] as $g => $p) {
        if ($g === 'metro' || empty($p['rectArr'])) continue;
        list($ix, $iy, $iw, $ih) = $p['rectArr'];
        $scene .= '<rect class="bk-inset" x="' . $ix . '" y="' . $iy . '" width="' . $iw . '" height="' . $ih . '" rx="4"></rect>'
            . '<text class="bk-inset-lb" x="' . ($ix + 4) . '" y="' . ($iy + 13) . '">' . bk_e(bk_t('MapInset' . $g)) . '</text>';
    }
    foreach ($geo['paths'] as $pa) {
        // The two territories drawn by geo.php itself carry no name: it is translated here.
        $name = in_array((string) $pa['code'], array('987', '988'), true) ? bk_t('MapName' . $pa['code']) : $pa['nom'];
        $scene .= '<path class="bk-dept" data-code="' . bk_e($pa['code']) . '" d="' . $pa['d'] . '"><title>' . bk_e($pa['code'] . ' — ' . $name) . '</title></path>';
    }
    foreach ($geo['paths'] as $pa) {
        $scene .= '<text class="bk-deptlb" x="' . $pa['cx'] . '" y="' . $pa['cy'] . '">' . bk_e($pa['code']) . '</text>';
    }
    $scene .= '<g id="bk-markers">';
    foreach ($clusters as $cl) {
        $n = count($cl['items']);
        $fills = array(); $allPara = true; $allPast = true;
        foreach ($cl['items'] as $it) { $fills[$it['fill']] = true; if (!$it['para']) $allPara = false; if (!$it['past']) $allPast = false; }
        $fill = (count($fills) === 1) ? array_key_first($fills) : '#6b7480';
        $cls = 'bk-mk' . ($allPara && $n ? ' para' : '') . ($allPast && $n ? ' past' : '');
        $data = htmlspecialchars(json_encode($cl['items'], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $scene .= '<g class="' . $cls . '" data-x="' . $cl['x'] . '" data-y="' . $cl['y'] . '" data-items="' . $data . '" transform="translate(' . $cl['x'] . ',' . $cl['y'] . ')">'
            . '<circle r="' . ($n > 1 ? 9.5 : 7) . '" fill="' . bk_e($fill) . '"></circle>' . ($n > 1 ? '<text y="3.4">' . $n . '</text>' : '') . '</g>';
    }
    $scene .= '</g>';
    $out .= '<div class="bk-map-wrap"><svg id="bk-map" class="bk-map" viewBox="0 0 1000 1000" xmlns="http://www.w3.org/2000/svg" aria-label="' . bk_e(bk_t('MapTitle')) . '">'
        . '<g id="bk-map-scene">' . $scene . '</g></svg>'
        . '<div class="bk-map-tools"><button type="button" data-z="in" aria-label="' . bk_e(bk_t('MapZoomIn')) . '" title="' . bk_e(bk_t('MapZoomIn')) . '">+</button>'
        . '<button type="button" data-z="out" aria-label="' . bk_e(bk_t('MapZoomOut')) . '" title="' . bk_e(bk_t('MapZoomOut')) . '">−</button>'
        . '<button type="button" data-z="reset" aria-label="' . bk_e(bk_t('MapReset')) . '" title="' . bk_e(bk_t('MapReset')) . '" style="font-size:13px">⟲</button></div>'
        . '<div class="bk-map-pop" id="bk-map-pop"></div></div>';
}
echo $out;
?>
<script>
(function () {
  var svg = document.getElementById('bk-map'); if (!svg) return;
  var pop = document.getElementById('bk-map-pop');
  var markers = [].slice.call(svg.querySelectorAll('.bk-mk'));
  var VB = { x:0, y:0, w:1000, h:1000 }, BASE = { x:0, y:0, w:1000, h:1000 };
  var MINW = 28, DEEP = 70;   // max zoom (tighter for the Paris area); "number ×0.5" threshold = former max
  var T = <?= json_encode(array('many' => bk_t('MapNComps', '{n}'), 'one' => bk_t('MapOneComp')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var compUrl = <?= json_encode(bk_public_url('competition.php?t=')) ?>;
  var raf = 0;
  function scheduleMarks() { if (raf) return; raf = requestAnimationFrame(function () { raf = 0;
    // CONSTANT screen size down to the former max zoom (DEEP); beyond, the size on the map is
    // frozen at DEEP → the dots GROW on screen when zooming deeper.
    var s = Math.max(VB.w, DEEP) / BASE.w;
    for (var i = 0; i < markers.length; i++) markers[i].setAttribute('transform',
      'translate(' + markers[i].dataset.x + ',' + markers[i].dataset.y + ') scale(' + s.toFixed(3) + ')');
  }); }
  function apply() {
    svg.setAttribute('viewBox', VB.x + ' ' + VB.y + ' ' + VB.w + ' ' + VB.h);
    svg.classList.toggle('zoomed', VB.w < BASE.w * 0.6);
    svg.classList.toggle('deep', VB.w < DEEP);
    scheduleMarks();
  }
  function clamp() {
    VB.w = Math.max(MINW, Math.min(BASE.w, VB.w)); VB.h = VB.w * (BASE.h / BASE.w);
    VB.x = Math.max(BASE.x - VB.w * 0.15, Math.min(BASE.x + BASE.w - VB.w * 0.85, VB.x));
    VB.y = Math.max(BASE.y - VB.h * 0.15, Math.min(BASE.y + BASE.h - VB.h * 0.85, VB.y));
  }
  function zoomAt(factor, cx, cy) {
    var nw = Math.max(MINW, Math.min(BASE.w, VB.w * factor));
    var rx = (cx - VB.x) / VB.w, ry = (cy - VB.y) / VB.h;
    VB.x += (VB.w - nw) * rx; VB.y += (VB.h - nw * (BASE.h / BASE.w)) * ry; VB.w = nw;
    clamp(); apply();
  }
  function toSvg(cx, cy) { var r = svg.getBoundingClientRect();
    return { x: VB.x + (cx - r.left) / r.width * VB.w, y: VB.y + (cy - r.top) / r.height * VB.h }; }
  svg.addEventListener('wheel', function (e) { e.preventDefault(); var p = toSvg(e.clientX, e.clientY); zoomAt(e.deltaY < 0 ? 0.82 : 1.22, p.x, p.y); }, { passive:false });
  document.querySelector('.bk-map-tools').addEventListener('click', function (e) {
    var z = e.target.getAttribute('data-z'); if (!z) return;
    if (z === 'reset') { VB = { x:0, y:0, w:1000, h:1000 }; apply(); }
    else zoomAt(z === 'in' ? 0.7 : 1.43, VB.x + VB.w / 2, VB.y + VB.h / 2);
  });
  // (Click on a department removed: too many miss-clicks with the dots.)
  // Panning (mouse) + pinching (touch).
  var drag = null, pinch = null;
  function dist(t) { var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY; return Math.hypot(dx, dy); }
  svg.addEventListener('mousedown', function (e) { drag = { x:e.clientX, y:e.clientY }; svg.classList.add('drag'); pop.style.display = 'none'; });
  window.addEventListener('mousemove', function (e) { if (!drag) return; var r = svg.getBoundingClientRect();
    VB.x -= (e.clientX - drag.x) / r.width * VB.w; VB.y -= (e.clientY - drag.y) / r.height * VB.h; drag = { x:e.clientX, y:e.clientY }; clamp(); apply(); });
  window.addEventListener('mouseup', function () { drag = null; svg.classList.remove('drag'); });
  svg.addEventListener('touchstart', function (e) {
    if (e.touches.length === 2) { pinch = { d: dist(e.touches), mx:(e.touches[0].clientX + e.touches[1].clientX) / 2, my:(e.touches[0].clientY + e.touches[1].clientY) / 2 }; drag = null; }
    else if (e.touches.length === 1) { drag = { x:e.touches[0].clientX, y:e.touches[0].clientY }; }
  }, { passive:true });
  svg.addEventListener('touchmove', function (e) {
    if (e.touches.length === 2 && pinch) { e.preventDefault(); var nd = dist(e.touches); if (nd > 0) { var p = toSvg(pinch.mx, pinch.my); zoomAt(pinch.d / nd, p.x, p.y); pinch.d = nd; } }
    else if (e.touches.length === 1 && drag) { e.preventDefault(); var t = e.touches[0], r = svg.getBoundingClientRect();
      VB.x -= (t.clientX - drag.x) / r.width * VB.w; VB.y -= (t.clientY - drag.y) / r.height * VB.h; drag = { x:t.clientX, y:t.clientY }; clamp(); apply(); }
  }, { passive:false });
  svg.addEventListener('touchend', function (e) { if (e.touches.length < 2) pinch = null; if (!e.touches.length) drag = null; });
  // Popup listing the competitions of a marker.
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  markers.forEach(function (g) {
    g.addEventListener('click', function (e) {
      e.stopPropagation();
      var items = JSON.parse(g.getAttribute('data-items'));
      var html = '<span class="bk-map-x" data-close>×</span><h3>' + esc(items.length > 1 ? T.many.replace('{n}', items.length) : (items[0].city || T.one)) + '</h3>';
      items.forEach(function (it) {
        html += '<a href="' + compUrl + it.id + '">'
             + '<span class="bk-mp-ic">' + (it.icon || '') + '</span>'
             + '<span class="bk-mp-tx">' + (it.in ? '<span class="bk-mp-in">✓ </span>' : '') + esc(it.name)
             + '<br><small>' + esc(it.date || '') + (it.city ? ' · ' + esc(it.city) : '') + '</small></span>'
             + '</a>';
      });
      pop.innerHTML = html;
      var r = svg.getBoundingClientRect(), gr = g.getBoundingClientRect();
      pop.style.left = Math.min(r.width - 270, Math.max(6, gr.left - r.left + 12)) + 'px';
      pop.style.top  = Math.max(6, gr.top - r.top + 12) + 'px';
      pop.style.display = 'block';
    });
  });
  pop.addEventListener('click', function (e) { if (e.target.hasAttribute('data-close')) pop.style.display = 'none'; });
  apply();
})();
</script>
<?php
bk_foot();
