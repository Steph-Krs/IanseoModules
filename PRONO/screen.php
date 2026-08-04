<?php
/**
 * Écran de salle — cotes en direct et classement des joueurs, en plein écran.
 * Lecture seule, pensé pour un vidéoprojecteur : gros caractères, rafraîchissement
 * automatique, aucune interaction.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadOnly);

require_once __DIR__ . '/lib/engine.php';

// Toujours la compétition réellement ouverte aux pronostics, pas celle de la session
// ianseo : après un réimport les deux divergent, et l'écran de salle afficherait un
// classement figé d'un import précédent.
$tid = prono_active_tournament() ?: intval($_SESSION['TourId']);

if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        prono_poll($tid);
        $path = prono_snapshot_path($tid);
        echo is_readable($path) ? (string) file_get_contents($path) : '{"markets":[],"board":[]}';
    } catch (Throwable $e) {
        echo '{"markets":[],"board":[],"error":"' . htmlspecialchars($e->getMessage()) . '"}';
    }
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pronostics — écran de salle</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#02215e;color:#fff;font:16px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
     min-height:100vh;padding:18px 22px}
header{display:flex;align-items:baseline;gap:16px;margin-bottom:16px;border-bottom:2px solid #0254a8;padding-bottom:10px}
h1{font-size:26px;font-weight:700}
header .sub{color:#a7d6ff;font-size:15px;flex:1}
header .clk{font-size:15px;color:#a7d6ff;font-variant-numeric:tabular-nums}
.wrap{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
@media (max-width:1100px){.wrap{grid-template-columns:1fr}}
h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#a7d6ff;margin-bottom:9px}
.mk{background:rgba(255,255,255,.07);border-radius:8px;padding:11px 13px;margin-bottom:9px}
.mk .ti{font-size:18px;font-weight:600;margin-bottom:2px}
.mk .sb{font-size:13px;color:#a7d6ff;margin-bottom:8px}
.mk .sb .lv{color:#ff5043;font-weight:700}
.sels{display:flex;gap:9px;flex-wrap:wrap}
.sel{flex:1 1 200px;display:flex;justify-content:space-between;align-items:center;gap:10px;
     background:rgba(255,255,255,.09);border-radius:6px;padding:8px 11px}
.sel .n{font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sel .o{font-size:22px;font-weight:700;color:#ffd86b;font-variant-numeric:tabular-nums}
table{width:100%;border-collapse:collapse}
td,th{padding:7px 9px;font-size:16px;border-bottom:1px solid rgba(255,255,255,.12);text-align:left}
th{font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#a7d6ff}
td.n{text-align:right;font-variant-numeric:tabular-nums;font-weight:700}
tr:first-child td{color:#ffd86b}
.empty{color:#a7d6ff;padding:28px 0;font-size:17px}
</style>
</head>
<body>
<header>
  <h1 id="ti">Pronostics</h1>
  <div class="sub" id="sub"></div>
  <div class="clk" id="clk"></div>
</header>

<div class="wrap">
  <section>
    <h2>À pronostiquer — points en jeu</h2>
    <div id="mk"><div class="empty">Chargement…</div></div>
  </section>
  <section>
    <h2>Classement</h2>
    <div id="bd"><div class="empty">—</div></div>
  </section>
</div>

<script>
'use strict';
const $ = s => document.querySelector(s);
const el = (t, c, x) => { const n = document.createElement(t); if (c) n.className = c; if (x != null) n.textContent = x; return n; };

async function tick() {
  try {
    const r = await fetch('screen.php?json=1', { credentials: 'same-origin' });
    const S = await r.json();
    $('#ti').textContent = S.title || 'Pronostics';
    $('#sub').textContent = S.tour || '';
    $('#clk').textContent = new Date().toLocaleTimeString('fr-FR');

    const box = $('#mk');
    box.textContent = '';
    const list = (S.markets || []).filter(m => m.status === 'OPEN' || m.status === 'LOCKED').slice(0, 8);
    if (!list.length) box.appendChild(el('div', 'empty', 'Aucun pronostic ouvert.'));

    list.forEach(m => {
      const c = el('div', 'mk');
      c.appendChild(el('div', 'ti', m.label));
      const sb = el('div', 'sb');
      if (/en cours/.test(m.sub || '')) {
        const i = m.sub.indexOf('en cours');
        sb.appendChild(document.createTextNode(m.sub.slice(0, i)));
        sb.appendChild(el('span', 'lv', m.sub.slice(i)));
      } else sb.textContent = m.sub || '';
      c.appendChild(sb);

      const w = el('div', 'sels');
      m.sels.slice(0, 4).forEach(s => {
        const d = el('div', 'sel');
        d.appendChild(el('span', 'n', s.label));
        d.appendChild(el('span', 'o', '+' + s.pts));
        w.appendChild(d);
      });
      c.appendChild(w);
      box.appendChild(c);
    });

    const bd = $('#bd');
    bd.textContent = '';
    const rows = (S.board || []).slice(0, 12);
    if (!rows.length) { bd.appendChild(el('div', 'empty', 'Personne n\'a encore pronostiqué.')); return; }
    const t = el('table');
    const hr = el('tr');
    ['#', 'Joueur', 'Points'].forEach((x, i) => { const th = el('th', i === 2 ? 'n' : '', x); if (i === 2) th.style.textAlign = 'right'; hr.appendChild(th); });
    t.appendChild(el('thead')).appendChild(hr);
    const tb = el('tbody');
    rows.forEach((r, i) => {
      const tr = el('tr');
      tr.appendChild(el('td', '', String(i + 1)));
      tr.appendChild(el('td', '', r.PaUsNick));
      tr.appendChild(el('td', 'n', String(r.PaUsPoints)));
      tb.appendChild(tr);
    });
    t.appendChild(tb);
    bd.appendChild(t);
  } catch (e) {
    $('#clk').textContent = 'hors ligne';
  }
}
tick();
setInterval(tick, 8000);
</script>
</body>
</html>
