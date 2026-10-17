/*
 * Map of the competitions (public/map.php). Leaflet + IGN tiles: the browser fetches the tiles
 * straight from IGN, the server only sends the points (attribute data-map of #bk-map).
 * Texts come from the page (C.t), never from this file.
 */
(function () {
  // Phone: the date field is transparent over its short text (map.php). Some browsers open their
  // picker only from the field's own calendar icon: a click asks for the picker explicitly.
  [].forEach.call(document.querySelectorAll('.bk-date input'), function (inp) {
    inp.addEventListener('click', function () {
      if (!inp.showPicker || getComputedStyle(inp).opacity !== '0') return;
      try { inp.showPicker(); } catch (e) { /* already open, or refused: the browser's own way stays */ }
    });
  });

  var el = document.getElementById('bk-map');
  if (!el || !window.L) return;
  var C = JSON.parse(el.getAttribute('data-map'));
  var T = C.t;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function box(b) { return L.latLngBounds([b[0], b[2]], [b[1], b[3]]); }

  var map = L.map(el, { zoomControl: false, attributionControl: false, minZoom: 2, maxZoom: 18 });
  L.control.zoom({ zoomInTitle: T.zoomIn, zoomOutTitle: T.zoomOut }).addTo(map);
  L.control.attribution({ prefix: '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>' }).addTo(map);

  // Base layers. A tile missing from a layer (the plan over New Caledonia or French Polynesia
  // when zoomed in) is replaced by the tile of its fallback layer at the same place.
  var layers = {};
  Object.keys(C.layers).forEach(function (k) {
    var d = C.layers[k];
    var lay = L.tileLayer(d.url, { maxZoom: 18, attribution: C.attribution, referrerPolicy: 'no-referrer' });
    var alt = d.fallback && C.layers[d.fallback] ? C.layers[d.fallback].url : '';
    if (alt) lay.on('tileerror', function (e) {
      if (e.tile.getAttribute('data-alt')) return;   // the fallback failed too: leave it empty
      e.tile.setAttribute('data-alt', '1');
      e.tile.src = L.Util.template(alt, { x: e.coords.x, y: e.coords.y, z: e.coords.z });
    });
    layers[k] = lay;
  });

  var current = '', sw = null;
  function useLayer(k) {
    if (!layers[k] || k === current) return;
    if (current) map.removeLayer(layers[current]);
    current = k;
    layers[k].addTo(map);
    if (sw) [].forEach.call(sw.querySelectorAll('button'), function (b) {
      b.classList.toggle('on', b.getAttribute('data-k') === k);
      b.setAttribute('aria-pressed', b.getAttribute('data-k') === k ? 'true' : 'false');
    });
  }

  var Switch = L.Control.extend({
    options: { position: 'topright' },
    onAdd: function () {
      sw = L.DomUtil.create('div', 'bk-map-switch leaflet-bar');
      Object.keys(layers).forEach(function (k) {
        var b = L.DomUtil.create('button', '', sw);
        b.type = 'button';
        b.textContent = C.layers[k].label;
        b.setAttribute('data-k', k);
      });
      L.DomEvent.disableClickPropagation(sw);
      L.DomEvent.on(sw, 'click', function (e) { useLayer(e.target.getAttribute('data-k')); });
      return sw;
    }
  });
  new Switch().addTo(map);
  useLayer(Object.keys(layers)[0]);

  // Markers: one per point, the number of competitions when there are several.
  var all = [];
  function popupHtml(items) {
    var h = '<h3>' + esc(items.length > 1 ? T.many.replace('{n}', items.length) : (items[0].city || T.one)) + '</h3>';
    items.forEach(function (it) {
      h += '<a class="bk-mp-c" href="' + esc(C.compUrl + it.id) + '">'
         + '<span class="bk-mp-ic">' + (it.icon || '') + '</span>'
         + '<span class="bk-mp-tx">' + (it.in ? '<span class="bk-mp-in">✓ </span>' : '') + esc(it.name)
         + '<br><small>' + esc(it.date || '') + (it.city ? ' · ' + esc(it.city) : '') + '</small></span></a>';
    });
    return h;
  }
  C.points.forEach(function (p) {
    var n = p.items.length, size = n > 1 ? 24 : 18;
    var fill = /^#[0-9a-f]{3,8}$/i.test(p.fill) ? p.fill : '#6b7480';
    var cls = 'bk-mk' + (p.para ? ' para' : '') + (p.past ? ' past' : '');
    var icon = L.divIcon({ className: 'bk-mk-box', iconSize: [size, size],
      html: '<span class="' + cls + '" style="--c:' + fill + '">' + (n > 1 ? n : '') + '</span>' });
    var title = n > 1 ? T.many.replace('{n}', n) : p.items[0].name;
    L.marker([p.lat, p.lng], { icon: icon, title: title, alt: title, riseOnHover: true })
      .bindPopup(function () { return popupHtml(p.items); }, { className: 'bk-map-pop', maxWidth: 280, minWidth: 200 })
      .addTo(map);
    all.push([p.lat, p.lng]);
  });
  // Leaflet names its close button in English only.
  map.on('popupopen', function (e) {
    var x = e.popup.getElement() && e.popup.getElement().querySelector('.leaflet-popup-close-button');
    if (x) { x.setAttribute('aria-label', T.close); x.setAttribute('title', T.close); }
  });

  // Views: mainland France by default; the points themselves when none is there.
  function areaPoints(a) {
    return C.points.filter(function (p) { return p.area === a; }).map(function (p) { return [p.lat, p.lng]; });
  }
  function show(a) {
    var pts = a ? areaPoints(a) : all;
    if (a === 'metro' || !pts.length) map.fitBounds(box(C.areas.metro));
    else map.fitBounds(L.latLngBounds(pts).pad(0.3), { maxZoom: 10 });
  }
  var home = (!all.length || areaPoints('metro').length) ? 'metro' : '';
  show(home);

  var Reset = L.Control.extend({
    options: { position: 'topleft' },
    onAdd: function () {
      var div = L.DomUtil.create('div', 'leaflet-bar');
      var a = L.DomUtil.create('a', 'bk-map-reset', div);
      a.href = '#'; a.innerHTML = '&#10226;';
      a.setAttribute('role', 'button'); a.title = T.reset; a.setAttribute('aria-label', T.reset);
      L.DomEvent.disableClickPropagation(div);
      L.DomEvent.on(a, 'click', function (e) { L.DomEvent.preventDefault(e); map.closePopup(); show(home); });
      return div;
    }
  });
  new Reset().addTo(map);

  [].forEach.call(document.querySelectorAll('.bk-map-areas [data-area]'), function (b) {
    b.addEventListener('click', function () { map.closePopup(); show(b.getAttribute('data-area')); });
  });
})();
