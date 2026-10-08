<?php
/**
 * public/share.php — shareable "I'll be there" picture of a participation.
 *
 * Generated 100 % IN THE BROWSER (canvas → PNG, no server load): PHP only gives the data. Reuses
 * the visual identity of the MANDATE (template + colour chosen by the organiser) through
 * bk_mandate_get()/bk_mandate_palette(). For the archer REGISTERED on the competition
 * (bk_reg_existing) — which is also what allows the logos (see public/tourlogo.php).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';
require_once dirname(__DIR__) . '/lib/registration.php';

$archer = bk_require_archer();
$t = intval($_GET['t'] ?? 0);

// Limit: only a registered archer makes the picture of THEIR competition.
if (!$t || !bk_reg_existing($t, $archer->BaLicence)) {
    bk_redirect('registrations.php');
}

$data = bk_mandate_data($t);
if (!$data) bk_redirect('registrations.php');

$tour = $data['tour'];
$m    = bk_mandate_get($data['cfg']);
$pal  = bk_mandate_palette($m['color']);

// Countdown against the competition's local date (lib/clock.php): CURDATE() is UTC on
// these pages, which gave a wrong "J-X" for two hours every night.
$lt = bk_local_today_sql('ToTimeZone');
$dd = safe_fetch(safe_r_sql("SELECT DATEDIFF(ToWhenFrom, $lt) AS d,
    DATEDIFF(ToWhenTo, $lt) AS dEnd FROM Tournament WHERE ToId = $t"));
$daysTo  = $dd ? intval($dd->d) : 0;
$daysEnd = $dd ? intval($dd->dEnd) : 0;

if ($daysEnd < 0) {                    // competition over
    $hook = bk_t('ShHookPast'); $badge = '';
} elseif ($daysTo <= 0) {              // under way / the day itself
    $hook = bk_t('ShHookNow'); $badge = bk_t('ShDayD');
} else {                               // to come
    $hook = bk_t('ShHookSoon'); $badge = bk_t('ShDaysLeft', $daysTo);
}

// Logos actually there AND switched on in the mandate's settings (same images as the mandate).
// Served by tourlogo.php (limited: visible mandate OR registered archer).
$logos = array();
foreach (array('L', 'R', 'B') as $k) {
    $has = intval($tour->{'Has' . $k} ?? 0) > 0;
    if ($has && !empty($m['logos'][$k])) {
        $logos[$k] = bk_public_url('tourlogo.php?type=' . $k . '&t=' . $t . '&w=600');
    }
}

$conf = array(
    'hook'     => $hook,
    'badge'    => $badge,
    'name'     => (string) $tour->ToName,
    'place'    => (string) $tour->ToWhere,
    'dates'    => bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo),
    'disc'     => (string) ($data['discLabel'] ?? ''),
    'region'   => (string) ($data['region'] ?? ''),
    'foot'     => bk_t('Brand'),
    'template' => $m['share_template'],   // template chosen by the organiser (Mandate page)
    'pal'      => $pal,
    'logos'    => $logos,
);

$shareText = $hook . ' ' . $tour->ToName . ($tour->ToWhere ? ' — ' . $tour->ToWhere : '')
    . ($badge ? ' (' . $badge . ')' : '');

bk_head(bk_t('ShTitle'), 'page');
$fmtBtn = function ($fmt, $key, $on) {
    return '<button type="button" class="bk-btn bk-fmt' . ($on ? ' on' : '') . '" data-fmt="' . $fmt . '">' . bk_e(bk_t($key)) . '</button> ';
};
echo '<div class="bk-share"><h1 class="bk-share-h">' . bk_e(bk_t('ShTitle')) . '</h1>'
    . '<p class="bk-hint">' . bk_e(bk_t('ShIntro')) . '</p>'
    . '<div class="bk-share-formats" role="group" aria-label="' . bk_e(bk_t('ShFormat')) . '">'
    . $fmtBtn('square', 'ShSquare', true) . $fmtBtn('story', 'ShStory', false) . $fmtBtn('wide', 'ShWide', false) . '</div>'
    . '<div class="bk-share-canvas-wrap"><canvas id="bk-share-c" width="1080" height="1080" aria-label="' . bk_e(bk_t('ShCanvas')) . '"></canvas></div>'
    . '<div class="bk-share-act"><button type="button" id="bk-share-btn" class="bk-btn bk-btn-primary" style="display:none">' . bk_e(bk_t('ShShareBtn')) . '</button> '
    . '<button type="button" id="bk-dl-btn" class="bk-btn">' . bk_e(bk_t('ShDownload')) . '</button> '
    . '<a class="bk-btn" href="' . bk_e(bk_public_url('registrations.php?t=' . $t)) . '">' . bk_e(bk_t('BackMyRegs')) . '</a></div>'
    . '<p class="bk-hint" id="bk-share-hint"></p></div>';
?>

<script>
(function () {
  var CONF = <?= json_encode($conf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var SHARE_TEXT = <?= json_encode($shareText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var HINT = <?= json_encode(bk_t('ShHint'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var FILE_NAME = <?= json_encode(bk_t('ShFileName') . '.png', JSON_UNESCAPED_UNICODE) ?>;
  var FORMATS = { square: [1080, 1080], story: [1080, 1920], wide: [1200, 630] };
  var fmt = 'square';

  var canvas = document.getElementById('bk-share-c');
  var ctx = canvas.getContext('2d');
  var P = CONF.pal;

  // Logos preloaded (same origin → untainted canvas → PNG export works).
  var imgs = {};
  var pending = 0;
  ['L', 'B', 'R'].forEach(function (k) {
    if (!CONF.logos[k]) return;
    pending++;
    var im = new Image();
    im.onload = function () { imgs[k] = im; if (--pending <= 0) draw(); };
    im.onerror = function () { if (--pending <= 0) draw(); };
    im.src = CONF.logos[k];
  });

  function rr(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  // Splits a text into lines that fit in maxW (font already set on ctx).
  function wrap(text, maxW) {
    var words = String(text).split(/\s+/), lines = [], cur = '';
    for (var i = 0; i < words.length; i++) {
      var test = cur ? cur + ' ' + words[i] : words[i];
      if (ctx.measureText(test).width > maxW && cur) { lines.push(cur); cur = words[i]; }
      else cur = test;
    }
    if (cur) lines.push(cur);
    return lines;
  }

  function drawLogos(x, y, maxW, maxH, keys) {
    var got = keys.filter(function (k) { return imgs[k]; });
    if (!got.length) return 0;
    var gap = maxW * 0.04, each = (maxW - gap * (got.length - 1)) / got.length;
    var cx = x;
    got.forEach(function (k) {
      var im = imgs[k], r = Math.min(each / im.width, maxH / im.height);
      var w = im.width * r, h = im.height * r;
      ctx.drawImage(im, cx + (each - w) / 2, y + (maxH - h) / 2, w, h);
      cx += each + gap;
    });
    return maxH;
  }

  function draw() {
    var W = canvas.width, H = canvas.height;
    var pad = Math.round(W * 0.08);
    var innerW = W - pad * 2;
    var story = (H / W) > 1.4;
    var tpl = CONF.template;
    var cx = W / 2;

    // ---- Background by template + colour scheme ----
    var onDark = false;    // is the body text on a dark background?
    var splitY = 0;        // split line ("moitie" template)
    if (tpl === 'degrade') {
      var g = ctx.createLinearGradient(0, 0, W, H);
      g.addColorStop(0, P.primary); g.addColorStop(1, P.dark);
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
      onDark = true;
    } else if (tpl === 'moitie') {
      splitY = Math.round(H * (story ? 0.46 : 0.50));
      ctx.fillStyle = P.light; ctx.fillRect(0, 0, W, H);
      var g2 = ctx.createLinearGradient(0, 0, 0, splitY);
      g2.addColorStop(0, P.primary); g2.addColorStop(1, P.dark);
      ctx.fillStyle = g2; ctx.fillRect(0, 0, W, splitY);
    } else if (tpl === 'encadre') {
      ctx.fillStyle = P.light; ctx.fillRect(0, 0, W, H);
      var bw = Math.max(6, W * 0.02);
      ctx.strokeStyle = P.primary; ctx.lineWidth = bw;
      ctx.strokeRect(bw / 2, bw / 2, W - bw, H - bw);
    } else if (tpl === 'epure') {
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
    } else {   // 'bandeau' (default)
      ctx.fillStyle = P.light; ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = P.primary; ctx.fillRect(0, 0, W, Math.round(H * (story ? 0.13 : 0.17)));
    }

    var mainCol = onDark ? P.on : P.dark;      // name
    var subCol  = onDark ? P.on : '#3a4256';   // meta / place
    var accent  = onDark ? P.on : P.primary;   // dates
    // The hook + badge sit in a COLOURED area for degrade and moitie.
    var hookOn  = onDark || (tpl === 'moitie');

    ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    var y = pad;

    // ---- Logos on top ----
    var logoH = Math.round(H * (story ? 0.10 : 0.13));
    var used = drawLogos(pad, y, innerW, logoH, ['L', 'B', 'R']);
    y += (used ? used + Math.round(H * 0.03) : Math.round(H * 0.02));
    if (tpl === 'bandeau') {   // stay below the coloured band
      var bandH = Math.round(H * (story ? 0.13 : 0.17));
      if (y < bandH + Math.round(H * 0.03)) y = bandH + Math.round(H * 0.03);
    }

    // ---- "I'll be there!" hook ----
    var hookSize = Math.round(W * (story ? 0.11 : 0.115));
    ctx.font = '800 ' + hookSize + 'px Arial, Helvetica, sans-serif';
    ctx.fillStyle = hookOn ? P.on : P.primary;
    y += hookSize;
    ctx.fillText(CONF.hook, cx, y);
    y += Math.round(H * 0.02);

    // ---- D-X badge (pill) ----
    if (CONF.badge) {
      var bSize = Math.round(W * 0.075);
      ctx.font = '800 ' + bSize + 'px Arial, sans-serif';
      var pw = ctx.measureText(CONF.badge).width + bSize * 1.1;
      var ph = bSize * 1.5;
      y += Math.round(H * 0.015);
      ctx.fillStyle = hookOn ? P.on : P.primary;
      rr(cx - pw / 2, y, pw, ph, ph / 2); ctx.fill();
      ctx.fillStyle = hookOn ? P.primary : '#ffffff';
      ctx.textBaseline = 'middle';
      ctx.fillText(CONF.badge, cx, y + ph / 2 + bSize * 0.03);
      ctx.textBaseline = 'alphabetic';
      y += ph + Math.round(H * 0.045);
    } else {
      y += Math.round(H * 0.03);
    }

    // 'moitie': go below the split line for the "details" part
    if (tpl === 'moitie' && y < splitY + Math.round(H * 0.04)) y = splitY + Math.round(H * 0.06);

    // 'epure': thin rule above the name
    if (tpl === 'epure') {
      ctx.strokeStyle = P.primary; ctx.lineWidth = Math.max(2, W * 0.004);
      var fl = innerW * 0.26;
      ctx.beginPath(); ctx.moveTo(cx - fl / 2, y); ctx.lineTo(cx + fl / 2, y); ctx.stroke();
      y += Math.round(H * 0.025);
    }

    // ---- Competition name (up to 3 lines) ----
    var nSize = Math.round(W * (story ? 0.062 : 0.066));
    ctx.font = '700 ' + nSize + 'px Arial, sans-serif';
    ctx.fillStyle = mainCol;
    wrap(CONF.name, innerW).slice(0, 3).forEach(function (ln) { y += nSize * 1.12; ctx.fillText(ln, cx, y); });

    // ---- Discipline • region ----
    var meta = [CONF.disc, CONF.region].filter(Boolean).join('  •  ');
    if (meta) {
      var mSize = Math.round(W * 0.038);
      ctx.font = '600 ' + mSize + 'px Arial, sans-serif';
      ctx.fillStyle = subCol; y += mSize * 1.9; ctx.fillText(meta, cx, y);
    }

    // ---- Dates + place ----
    var dSize = Math.round(W * 0.044);
    ctx.font = '700 ' + dSize + 'px Arial, sans-serif';
    ctx.fillStyle = accent;
    if (CONF.dates) { y += dSize * 1.9; ctx.fillText(CONF.dates, cx, y); }
    if (CONF.place) {
      var pSize = Math.round(W * 0.04);
      ctx.font = '400 ' + pSize + 'px Arial, sans-serif';
      ctx.fillStyle = subCol;
      wrap('📍 ' + CONF.place, innerW).slice(0, 2).forEach(function (ln) { y += pSize * 1.4; ctx.fillText(ln, cx, y); });
    }

    // ---- Footer ----
    var fSize = Math.round(W * 0.03);
    ctx.font = '600 ' + fSize + 'px Arial, sans-serif';
    ctx.fillStyle = (tpl === 'degrade') ? P.on : subCol;
    ctx.globalAlpha = 0.85;
    ctx.fillText(CONF.foot, cx, H - pad * 0.6);
    ctx.globalAlpha = 1;
  }

  function setFormat(f) {
    fmt = f;
    canvas.width = FORMATS[f][0];
    canvas.height = FORMATS[f][1];
    draw();
  }

  // Builds the PNG file SYNCHRONOUSLY (toDataURL, not the async toBlob): on iOS/Android,
  // navigator.share must be called WITHIN the user gesture; the async callback of toBlob breaks
  // that "activation" → the share fails silently (why the button "did nothing" on mobile).
  function canvasFile() {
    var url = canvas.toDataURL('image/png');
    var bin = atob(url.split(',')[1]);
    var n = bin.length, arr = new Uint8Array(n);
    for (var i = 0; i < n; i++) arr[i] = bin.charCodeAt(i);
    return new File([arr], FILE_NAME, { type: 'image/png' });
  }

  function download() {
    var a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = FILE_NAME;
    document.body.appendChild(a); a.click(); a.remove();
  }

  function share() {
    var file;
    try { file = canvasFile(); } catch (e) { download(); return; }
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      navigator.share({ files: [file], text: SHARE_TEXT })
        .catch(function (e) { if (!e || e.name !== 'AbortError') download(); });   // real failure → download instead
    } else {
      download();
    }
  }

  // Controls
  document.querySelectorAll('.bk-fmt').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.bk-fmt').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      setFormat(b.getAttribute('data-fmt'));
    });
  });
  document.getElementById('bk-dl-btn').addEventListener('click', download);

  var sBtn = document.getElementById('bk-share-btn');
  // Handler ALWAYS attached (the former "empty file" test returned false on Chrome Android and
  // left the button without action). Detection tests ONLY that the API exists (the real file is
  // tested on click, in share()). Visibility goes through style.display: the `hidden` attribute
  // is overridden by ".bk-btn{display:...}".
  sBtn.addEventListener('click', share);
  if (navigator.canShare && navigator.share) {
    sBtn.style.display = 'inline-block';
  } else {
    document.getElementById('bk-share-hint').textContent = HINT;
  }

  draw();   // first drawing (the logos draw again once loaded)
})();
</script>
<?php
bk_foot();
