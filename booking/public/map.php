<?php
/**
 * public/map.php — carte des compétitions (option 1 : fond SVG France + DROM/COM en encarts,
 * marqueurs aux villes géocodées côté serveur, AUCUNE tuile externe). Filtres discipline /
 * dates (défaut : aujourd'hui → J+14). Couleurs FFTA par discipline (pastille dans le filtre),
 * para en contour, compétitions passées en semi-transparence, non officielles en anthracite.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/geo.php';

$archer = bk_require_archer();

$facets = bk_comp_facets();
$labels = bk_disc_labels();
$today  = date('Y-m-d');

// Filtres (URL). Défaut dates : aujourd'hui → J+14. (Pas de filtre région : la carte le montre.)
$disc = (string) ($_GET['disc'] ?? '');
if ($disc !== '' && $disc !== 'para' && !isset($facets['disc'][$disc])) $disc = '';
$dchk = function ($v, $def) { return (isset($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) ? (string) $v : $def; };
$from = $dchk($_GET['from'] ?? null, $today);
$to   = $dchk($_GET['to'] ?? null, date('Y-m-d', strtotime($today . ' +14 days')));

// Compétitions publiées correspondant aux filtres (chevauchement de la période).
$w = array("o.BcOpen = 1", "t.ToWhenTo >= " . StrSafe_DB($from), "t.ToWhenFrom <= " . StrSafe_DB($to));
if ($disc === 'para') $w[] = "t.ToTypeSubRule LIKE '%Para%'";
elseif ($disc !== '') { $types = bk_disc_types($disc); $w[] = $types ? "t.ToType IN (" . implode(',', array_map('intval', $types)) . ")" : "1=0"; }

$rs = safe_r_sql("SELECT t.ToId, t.ToName, t.ToVenue, t.ToWhenFrom, t.ToWhenTo, t.ToType,
            t.ToTypeName, t.ToTypeSubRule, o.BcLat, o.BcLng, o.BcGeoSrc
        FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
        WHERE " . implode(' AND ', $w) . " ORDER BY t.ToWhenFrom");
$comps = array();
while ($r = safe_fetch($rs)) $comps[] = $r;

// Géocodage à la demande, plafonné par chargement (un appel réseau par ville nouvelle).
$cap = 15; $pending = 0;
foreach ($comps as $c) {
    $venue = trim((string) $c->ToVenue);
    if ($venue === '') continue;
    if ($c->BcLat !== null && $c->BcLng !== null && (string) $c->BcGeoSrc === $venue) continue;
    if ($cap > 0) { $cap--; $g = bk_comp_geocode(intval($c->ToId)); if ($g) { $c->BcLat = $g['lat']; $c->BcLng = $g['lng']; } }
    else $pending++;
}

$deja = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) $deja[intval($r->BrTournament)] = true;

$geo = bk_map_geometry();

// Regroupe par POINT (même ville → un marqueur). Couleur = discipline (FFTA) ; contour para ;
// passées en semi-transparence. Le rendu « non officielle » (anthracite) EXISTE (bk_disc_color)
// mais aucune donnée ne distingue une compétition non officielle à ce jour → toutes officielles
// pour l'instant. Le jour où un signal existe, calculer $official ici (le reste est déjà prêt).
$clusters = array(); $located = 0;
foreach ($comps as $c) {
    if ($c->BcLat === null || $c->BcLng === null) continue;
    $xy = bk_map_marker_xy($geo['proj'], (float) $c->BcLat, (float) $c->BcLng);
    if (!$xy) continue;
    $located++;
    $dd = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
    $official = true;   // TODO : aucun champ ne distingue une compétition non officielle aujourd'hui
    $key = round($xy[0], 0) . '_' . round($xy[1], 0);
    if (!isset($clusters[$key])) $clusters[$key] = array('x' => round($xy[0], 1), 'y' => round($xy[1], 1), 'items' => array());
    $clusters[$key]['items'][] = array(
        'id'    => intval($c->ToId),
        'name'  => (string) $c->ToName,
        'city'  => trim((string) $c->ToVenue),
        'date'  => bk_date_range($c->ToWhenFrom, $c->ToWhenTo),
        'fill'  => bk_disc_color($dd['key'], $official),
        'para'  => (bool) $dd['para'],
        'past'  => (substr((string) $c->ToWhenTo, 0, 10) < $today),
        'in'    => isset($deja[intval($c->ToId)]),
        'icon'  => bk_disc_icon($dd['key'], 18) . ((bool) $dd['para'] ? ' ' . bk_disc_icon_para(14) : ''),
    );
}

/** Conserve les filtres en changeant un paramètre. */
function bk_map_url($over = array())
{
    global $disc, $from, $to;
    $p = array('disc' => $disc, 'from' => $from, 'to' => $to);
    foreach ($over as $k => $v) $p[$k] = $v;
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return bk_public_url('map.php') . '?' . http_build_query($p);
}

bk_head('Carte des compétitions');
?>
<style>
#bk .bk-map-filters { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:flex-end; margin:0 0 10px; }
#bk .bk-map-dates { display:flex; gap:8px; align-items:flex-end; }
#bk .bk-map-dates label { display:flex; flex-direction:column; font-size:11px; color:#7d8183; gap:2px; }
#bk .bk-map-dates input { padding:5px 7px; border:1px solid #d2d4d6; border-radius:6px; font-size:13px; }
/* Pastille de couleur FFTA dans le filtre discipline (tient lieu de légende). */
#bk .bk-dc-dot { width:12px; height:12px; border-radius:50%; border:1.5px solid #fff;
    box-shadow:0 0 0 1px rgba(0,0,0,.18); flex:0 0 auto; }
#bk .bk-dchip.on .bk-dc-dot { box-shadow:0 0 0 1px rgba(255,255,255,.6); }
#bk .bk-dc-dot.para { border-color:#A0006D; border-width:2.5px; }
/* Carte : tient dans la hauteur de l'écran sur PC (carré centré), pleine largeur sur mobile. */
#bk .bk-map-wrap { position:relative; border:1px solid #d2d4d6; border-radius:8px; overflow:hidden; background:#eef3f8; }
#bk .bk-map { display:block; margin:0 auto; width:min(100%, calc(100vh - 200px));
    height:auto; touch-action:none; cursor:grab; }
#bk .bk-map.drag { cursor:grabbing; }
#bk .bk-dept { fill:#dbe6f2; stroke:#7fa0c2; stroke-width:.6; vector-effect:non-scaling-stroke; }
#bk .bk-inset { fill:none; stroke:#b9c4d0; stroke-width:1; vector-effect:non-scaling-stroke; }
#bk .bk-inset-lb { font-size:11px; fill:#556; }
#bk .bk-deptlb { font-size:7px; fill:#41618a; text-anchor:middle; opacity:0; transition:opacity .15s; pointer-events:none; }
#bk .bk-map.zoomed .bk-deptlb { opacity:.85; }
#bk .bk-map.deep .bk-deptlb { font-size:3.5px; }   /* au-delà du zoom max initial : numéro à ×0,5 */
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

<div class="bk-map-filters">
  <div class="bk-cal-disc">
    <a class="bk-dchip<?= $disc === '' ? ' on' : '' ?>" href="<?= bk_e(bk_map_url(array('disc' => ''))) ?>">Toutes</a>
    <?php foreach ($labels as $k => $lab): if (empty($facets['disc'][$k])) continue; ?>
      <a class="bk-dchip<?= $disc === $k ? ' on' : '' ?>" href="<?= bk_e(bk_map_url(array('disc' => $k))) ?>"><?= bk_disc_icon($k, 18) ?><span><?= bk_e($lab) ?></span><i class="bk-dc-dot" style="background:<?= bk_e(bk_disc_color($k, true)) ?>"></i></a>
    <?php endforeach; ?>
    <?php if (!empty($facets['para'])): ?>
      <a class="bk-dchip<?= $disc === 'para' ? ' on' : '' ?>" href="<?= bk_e(bk_map_url(array('disc' => 'para'))) ?>"><?= bk_disc_icon_para(18) ?><span>Para</span><i class="bk-dc-dot para" style="background:#6b7480"></i></a>
    <?php endif; ?>
  </div>
  <form class="bk-map-dates" method="get" action="<?= bk_e(bk_public_url('map.php')) ?>">
    <input type="hidden" name="disc" value="<?= bk_e($disc) ?>">
    <label>Du <input type="date" name="from" value="<?= bk_e($from) ?>" onchange="this.form.submit()"></label>
    <label>Au <input type="date" name="to" value="<?= bk_e($to) ?>" onchange="this.form.submit()"></label>
  </form>
</div>

<?php if (!$geo['ok']): ?>
  <p class="bk-blocked">Le fond de carte n'est pas disponible (fichier des départements manquant).</p>
<?php else: ?>
  <?php if ($pending): ?><p class="bk-hint"><?= intval($pending) ?> compétition(s) en cours de localisation — rechargez dans un instant.</p><?php endif; ?>
  <?php if (!$located && !$pending): ?><p class="bk-hint">Aucune compétition à afficher sur cette période / ces filtres (ou sans ville renseignée).</p><?php endif; ?>

  <div class="bk-map-wrap">
    <svg id="bk-map" class="bk-map" viewBox="0 0 1000 1000" xmlns="http://www.w3.org/2000/svg" aria-label="Carte des compétitions">
      <g id="bk-map-scene">
        <?php foreach ($geo['proj'] as $g => $p):
            if ($g === 'metro' || empty($p['rectArr'])) continue; list($ix, $iy, $iw, $ih) = $p['rectArr']; ?>
          <rect class="bk-inset" x="<?= $ix ?>" y="<?= $iy ?>" width="<?= $iw ?>" height="<?= $ih ?>" rx="4"></rect>
          <text class="bk-inset-lb" x="<?= $ix + 4 ?>" y="<?= $iy + 13 ?>"><?= bk_e($p['label']) ?></text>
        <?php endforeach; ?>
        <?php foreach ($geo['paths'] as $pa): ?>
          <path class="bk-dept" data-code="<?= bk_e($pa['code']) ?>" d="<?= $pa['d'] ?>"><title><?= bk_e($pa['code'] . ' — ' . $pa['nom']) ?></title></path>
        <?php endforeach; ?>
        <?php foreach ($geo['paths'] as $pa): ?>
          <text class="bk-deptlb" x="<?= $pa['cx'] ?>" y="<?= $pa['cy'] ?>"><?= bk_e($pa['code']) ?></text>
        <?php endforeach; ?>
        <g id="bk-markers">
        <?php foreach ($clusters as $cl):
            $n = count($cl['items']);
            $fills = array(); $allPara = true; $allPast = true; $anyIn = false;
            foreach ($cl['items'] as $it) { $fills[$it['fill']] = true; if (!$it['para']) $allPara = false; if (!$it['past']) $allPast = false; if ($it['in']) $anyIn = true; }
            $fill = (count($fills) === 1) ? array_key_first($fills) : '#6b7480';
            $cls = 'bk-mk' . ($allPara && $n ? ' para' : '') . ($allPast && $n ? ' past' : '');
            $data = htmlspecialchars(json_encode($cl['items'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>
          <g class="<?= $cls ?>" data-x="<?= $cl['x'] ?>" data-y="<?= $cl['y'] ?>" data-items="<?= $data ?>" transform="translate(<?= $cl['x'] ?>,<?= $cl['y'] ?>)">
            <circle r="<?= $n > 1 ? 9.5 : 7 ?>" fill="<?= bk_e($fill) ?>"></circle>
            <?php if ($n > 1): ?><text y="3.4"><?= $n ?></text><?php endif; ?>
          </g>
        <?php endforeach; ?>
        </g>
      </g>
    </svg>
    <div class="bk-map-tools">
      <button type="button" data-z="in" aria-label="Zoomer">+</button>
      <button type="button" data-z="out" aria-label="Dézoomer">−</button>
      <button type="button" data-z="reset" aria-label="Réinitialiser" style="font-size:13px">⟲</button>
    </div>
    <div class="bk-map-pop" id="bk-map-pop"></div>
  </div>
<?php endif; ?>

<script>
(function () {
  var svg = document.getElementById('bk-map'); if (!svg) return;
  var pop = document.getElementById('bk-map-pop');
  var markers = [].slice.call(svg.querySelectorAll('.bk-mk'));
  var VB = { x:0, y:0, w:1000, h:1000 }, BASE = { x:0, y:0, w:1000, h:1000 };
  var MINW = 28, DEEP = 70;   // zoom max (plus serré pour la région parisienne) ; seuil « numéro ×0,5 » = ancien max
  var compUrl = <?= json_encode(bk_public_url('competition.php?t=')) ?>;
  var raf = 0;
  function scheduleMarks() { if (raf) return; raf = requestAnimationFrame(function () { raf = 0;
    // Taille d'écran CONSTANTE jusqu'à l'ancien zoom max (DEEP) ; au-delà, la taille en carte
    // est figée à DEEP → les pastilles GROSSISSENT à l'écran quand on zoome plus profond.
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
  // (Clic sur un département retiré : trop de miss-clicks avec les pastilles.)
  // Déplacement (souris) + pincement (tactile).
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
  // Popup des compétitions d'un marqueur.
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  markers.forEach(function (g) {
    g.addEventListener('click', function (e) {
      e.stopPropagation();
      var items = JSON.parse(g.getAttribute('data-items'));
      var html = '<span class="bk-map-x" data-close>×</span><h3>' + esc(items.length > 1 ? items.length + ' compétitions' : (items[0].city || 'Compétition')) + '</h3>';
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
<?php bk_foot(); ?>
