<?php
/**
 * Face publique — page unique installable (PWA), pensée pour un téléphone tenu
 * à la verticale. Aucun appel à ianseo : toutes les données transitent par api.php.
 *
 * Principe : on ne mise rien. On pronostique, et on marque des points quand on a vu
 * juste. Un pronostic se pose d'un seul geste et se change tant que le marché est
 * ouvert.
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#01367c">
<title>Pronostics</title>
<link rel="icon" type="image/svg+xml" href="icon.svg">
<link rel="apple-touch-icon" href="icon.svg">
<link rel="manifest" href="manifest.json">
<style>
:root{
  --bleu:#0254a8; --fonce:#01367c; --nuit:#02215e; --clair:#f0f4ff; --doux:#a7d6ff;
  --corail:#ff5043; --vert:#2ad56e; --vertf:#149c4c; --orange:#cb8137;
  --texte:#20263d; --gris:#4c4e50; --gris2:#7d8183; --bord:#d2d4d6;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
     color:var(--texte);background:#f7f8fa;padding-bottom:40px}
h1,h2,h3{margin:0}
button{font:inherit;cursor:pointer}

#top{position:sticky;top:0;z-index:50}
#hd{background:var(--fonce);color:#fff;padding:calc(6px + env(safe-area-inset-top)) 12px 6px;
    display:flex;align-items:center;gap:10px;box-shadow:0 1px 4px rgba(0,0,0,.25)}
#hd h1{font-size:14.5px;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#purse{background:rgba(255,255,255,.16);border-radius:14px;padding:3px 10px;font-size:13px;
       font-weight:700;white-space:nowrap}
#purse small{font-weight:400;opacity:.8}
#tabs{display:flex;background:var(--nuit)}
#tabs button{flex:1;background:none;border:0;color:#a7d6ff;padding:10px 4px;font-size:13px;
             font-weight:600;border-bottom:3px solid transparent}
#tabs button[aria-selected=true]{color:#fff;border-bottom-color:var(--doux)}
#tabs .pip{display:inline-block;min-width:17px;padding:0 4px;margin-left:4px;border-radius:9px;
           background:var(--corail);color:#fff;font-size:11px;line-height:17px}

main{padding:10px}
.panel[hidden]{display:none}

#filters{margin-bottom:4px}
#fams{display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;scrollbar-width:none;
      -webkit-overflow-scrolling:touch}
#fams::-webkit-scrollbar{display:none}
#fams button{flex:0 0 auto;background:#fff;border:1px solid var(--bord);border-radius:16px;
             padding:7px 13px;font-size:13px;font-weight:600;color:var(--gris);white-space:nowrap}
#fams button[aria-pressed=true]{background:var(--bleu);border-color:var(--bleu);color:#fff}
#fams button b{font-weight:700;opacity:.75;margin-left:4px}
#evf{width:100%;padding:9px 10px;border:1px solid var(--bord);border-radius:6px;background:#fff;
     font:inherit;color:var(--texte);margin-bottom:6px}

.sec{margin:12px 0 8px}
.sec>summary{list-style:none;display:flex;align-items:center;gap:8px;background:var(--clair);
             border:1px solid var(--doux);border-radius:6px;padding:9px 11px;cursor:pointer;
             font-size:13.5px;font-weight:700;color:var(--fonce)}
.sec>summary::-webkit-details-marker{display:none}
.sec>summary .arw{transition:transform .15s;font-size:11px;color:var(--bleu)}
.sec[open]>summary .arw{transform:rotate(90deg)}
.sec>summary .cnt{margin-left:auto;background:#fff;border-radius:10px;padding:1px 8px;
                  font-size:12px;color:var(--gris)}
.sec .body{padding-top:8px}

.mk{background:#fff;border:1px solid var(--bord);border-radius:6px;padding:10px;margin-bottom:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08)}
.mk h3{font-size:14.5px;font-weight:600;line-height:1.3}
.mk .sub{font-size:12px;color:var(--gris);margin-top:2px}
.badge{font-size:11px;font-weight:700;border-radius:5px;padding:2px 7px;margin-left:6px;
       display:inline-block;vertical-align:middle}
.b-live{background:#ffd6db;color:#a80000}
.b-lock{background:#ddd;color:#333}

/* Issues : la valeur affichée est ce qu'on gagne si on a raison */
.sels{display:grid;gap:6px;margin-top:9px;grid-template-columns:1fr 1fr}
.sels.one{grid-template-columns:1fr}
.sel{display:flex;justify-content:space-between;align-items:center;gap:8px;background:var(--clair);
     border:1px solid var(--doux);border-radius:6px;padding:10px;text-align:left;color:var(--texte);
     min-height:46px}
.sel .nm{font-size:13px;line-height:1.25;overflow-wrap:anywhere}
.sel .pts{font-weight:700;color:var(--bleu);font-size:14px;font-variant-numeric:tabular-nums;
          white-space:nowrap}
.sel.mine{border-color:var(--vert);background:#eafaf0;border-width:2px}
.sel.mine .pts{color:var(--vertf)}
.sel.mine .nm::before{content:"✓ ";color:var(--vertf);font-weight:700}
/* Vainqueur désigné par le score en cours de saisie, pas encore enregistré */
.sel.implied{border-color:var(--vert);border-width:2px;border-style:dashed;background:#f2fbf6}
.sel.implied .pts{color:var(--vertf)}
.mk.locked .sel{background:#f2f3f5;border-color:var(--bord)}
.mk.locked .sel:not(.mine){opacity:.6}

/* Sélecteur de score : « + » de chaque côté, score courant au centre */
.stepper{display:grid;grid-template-columns:40px 46px 1fr 46px 40px;align-items:center;gap:5px;
         margin-top:8px;background:var(--clair);border:1px solid var(--doux);
         border-radius:6px;padding:6px}
.stepper.off{opacity:.6}
.stepper button{height:44px;border:1px solid var(--doux);background:#fff;border-radius:6px;
                font-weight:700;line-height:1;color:var(--bleu)}
.stepper .plus{font-size:22px}
.stepper .minus{font-size:19px;color:var(--gris);background:#fbfbfc}
.stepper button:disabled{opacity:.3;color:var(--gris2)}
.stepper .sc{display:flex;align-items:center;justify-content:center;gap:10px}
.stepper .sc b{font-size:26px;font-variant-numeric:tabular-nums;color:var(--gris2);min-width:24px;
               text-align:center}
.stepper .sc b.lead{color:var(--fonce)}
.stepper .sc i{font-style:normal;color:var(--gris2)}
.stepact{margin-top:7px;display:flex;flex-direction:column;align-items:stretch;gap:2px}
.btn.small{width:100%;padding:10px;font-size:13.5px}
.btn.small.done{background:var(--vertf)}

/* Tranches de total, à l'arc à poulies */
.wing{margin-top:9px}
.wing .who{font-size:12.5px;font-weight:700;color:var(--gris);margin-bottom:5px;overflow-wrap:anywhere}
.chipgrid{display:grid;gap:6px;grid-template-columns:repeat(auto-fill,minmax(90px,1fr))}
.chip{display:flex;flex-direction:column;align-items:center;gap:1px;background:var(--clair);
      border:1px solid var(--doux);border-radius:6px;padding:7px 4px;color:var(--texte);
      min-height:48px;justify-content:center}
.chip b{font-size:14px;font-variant-numeric:tabular-nums}
.chip span{font-size:12.5px;font-weight:700;color:var(--bleu);font-variant-numeric:tabular-nums}
.chip.mine{border-color:var(--vert);background:#eafaf0;border-width:2px}
.chip.mine span{color:var(--vertf)}
.mk.locked .chip:not(.mine){opacity:.6}

.many{margin-top:8px;background:none;border:0;color:var(--bleu);font-size:12.5px;
      font-weight:600;padding:4px 0;text-decoration:underline}
.hintline{font-size:12px;color:var(--gris2);margin-top:7px}
.closed{background:#ffd6db;border:1px solid #bb7575;color:#a80000;border-radius:6px;
        padding:11px 12px;margin-bottom:10px;font-size:13.5px}
.closed b{display:block;font-size:15px;margin-bottom:2px}
.deadline{background:#fff6e5;border:1px solid var(--orange);color:#8a5410;border-radius:6px;
          padding:9px 12px;margin-bottom:10px;font-size:13.5px;font-weight:600}

/* Mes pronostics */
.bet{background:#fff;border:1px solid var(--bord);border-left-width:4px;border-radius:6px;
     padding:9px 10px;margin-bottom:7px;display:flex;gap:10px;align-items:flex-start}
.bet.PENDING{border-left-color:var(--bleu)}
.bet.WON{border-left-color:var(--vert)}
.bet.LOST{border-left-color:var(--bord)}
.bet.VOID{border-left-color:var(--orange)}
.bet .col{flex:1;min-width:0}
.bet .t{font-size:13px;font-weight:600;overflow-wrap:anywhere}
.bet .d{font-size:12px;color:var(--gris);margin-top:2px;overflow-wrap:anywhere}
.bet .r{font-weight:700;font-size:13px;font-variant-numeric:tabular-nums;white-space:nowrap}
.bet.WON .r{color:var(--vertf)} .bet.LOST .r{color:var(--gris2)}
.bet.PENDING .r{color:var(--bleu)}
.tot{background:var(--clair);border:1px solid var(--doux);border-radius:6px;padding:9px 11px;
     margin-bottom:9px;font-size:13.5px}
.tot b{color:var(--fonce)}

table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--bord);border-radius:6px}
th,td{padding:8px 9px;text-align:left;font-size:13.5px;border-bottom:1px solid #eceef0}
th{background:var(--clair);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--gris)}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
td.n{font-weight:700}
tr.me td{background:#eafaf0}

.veil{position:fixed;inset:0;background:rgba(2,33,94,.55);display:flex;align-items:flex-end;
      justify-content:center;z-index:100}
.veil[hidden]{display:none}
.card{background:#fff;border-radius:12px 12px 0 0;padding:18px 16px calc(18px + env(safe-area-inset-bottom));
      width:100%;max-width:520px;max-height:92vh;overflow-y:auto}
.card h2{font-size:17px;margin-bottom:6px;overflow-wrap:anywhere}
.card p{font-size:13.5px;color:var(--gris);margin:0 0 14px;overflow-wrap:anywhere}
input[type=text],input[type=password]{width:100%;padding:11px;border:1px solid var(--bord);
     border-radius:6px;font:inherit;margin-bottom:10px;background:#fff;color:var(--texte)}
input:focus{outline:2px solid var(--doux);border-color:var(--bleu)}
.btn{width:100%;padding:12px;border:0;border-radius:6px;background:var(--bleu);color:#fff;
     font-weight:700;font-size:15px}
.btn:disabled{opacity:.5}
.btn.ghost{background:#f7f7f7;color:var(--texte);border:1px solid var(--bord);margin-top:8px}
.seg{display:flex;margin-bottom:14px;border:1px solid var(--bord);border-radius:6px;overflow:hidden}
.seg button{flex:1;padding:10px 6px;border:0;background:#fff;font-size:13px;font-weight:600;color:var(--gris)}
.seg button[aria-pressed=true]{background:var(--bleu);color:#fff}
.err{background:#ffd6db;border:1px solid #bb7575;color:#a80000;border-radius:6px;
     padding:9px 11px;margin-bottom:10px;font-size:13px}
.err[hidden]{display:none}
.warnbox{background:#fff6e5;border:1px solid var(--orange);color:#8a5410;border-radius:6px;
         padding:9px 11px;margin-bottom:12px;font-size:13px}
.warnbox[hidden]{display:none}

.empty{text-align:center;color:var(--gris2);padding:34px 20px;font-size:14px}

.dots{display:inline-flex;gap:6px;vertical-align:middle;margin-left:4px}
.dots i{width:8px;height:8px;border-radius:50%;background:var(--bleu);opacity:.4;
        animation:pBounce 1s ease-in-out infinite}
.dots i:nth-child(2){animation-delay:.16s}
.dots i:nth-child(3){animation-delay:.32s}
@keyframes pBounce{0%,80%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-7px);opacity:1}}
@keyframes pFade{0%,100%{opacity:.3}50%{opacity:1}}
@media (prefers-reduced-motion:reduce){.dots i{animation-name:pFade}}

#toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--nuit);
       color:#fff;padding:10px 16px;border-radius:20px;font-size:13.5px;z-index:120;
       box-shadow:0 2px 8px rgba(0,0,0,.3);max-width:92vw}
#toast[hidden]{display:none}

@media (max-width:359px){ .sels{grid-template-columns:1fr} }
</style>
</head>
<body>

<div id="top">
  <header id="hd">
    <img src="icon.svg" alt="" width="26" height="26" style="border-radius:6px;flex:0 0 auto">
    <h1 id="title">Pronostics</h1>
    <div id="purse" hidden><span id="nick"></span> · <span id="points">0</span><small> pts</small></div>
  </header>
  <nav id="tabs" role="tablist">
    <button role="tab" aria-selected="true"  data-p="mk">À pronostiquer</button>
    <button role="tab" aria-selected="false" data-p="my">Mes pronos<span class="pip" id="pip" hidden>0</span></button>
    <button role="tab" aria-selected="false" data-p="bd">Classement</button>
  </nav>
</div>

<main>
  <section class="panel" id="p-mk">
    <div id="filters" hidden>
      <div id="fams"></div>
    </div>
    <div id="mklist"><div class="empty">Chargement<span class="dots"><i></i><i></i><i></i></span></div></div>
  </section>
  <section class="panel" id="p-my" hidden></section>
  <section class="panel" id="p-bd" hidden></section>
</main>

<div class="veil" id="joinv" hidden>
  <div class="card">
    <div class="seg" id="mode">
      <button data-m="join" aria-pressed="true">Je m'inscris</button>
      <button data-m="login" aria-pressed="false">J'ai déjà un compte</button>
    </div>
    <h2 id="authh">Choisis ton pseudo</h2>
    <p id="authp"></p>
    <div class="err" id="joine" hidden></div>
    <input type="text" id="joinn" maxlength="20" placeholder="Ton pseudo" autocomplete="username"
           autocapitalize="words" enterkeyhint="next">
    <input type="password" id="joinp" maxlength="72" placeholder="Ton mot de passe"
           autocomplete="current-password" enterkeyhint="go">
    <button class="btn" id="joinb">C'est parti</button>
  </div>
</div>

<div class="veil" id="acctv" hidden>
  <div class="card">
    <h2 id="acctn"></h2>
    <p id="acctp"></p>
    <div id="acctwarn" class="warnbox" hidden>Ton compte n'a pas encore de mot de passe :
      si tu changes de téléphone, tu perdras tes pronostics. Choisis-en un maintenant.</div>
    <div id="acctne" class="err" hidden></div>
    <input type="text" id="acctnick" maxlength="20" placeholder="Ton pseudo"
           autocomplete="off" autocapitalize="words">
    <button class="btn ghost" id="acctnb" style="margin-top:0">Changer mon pseudo</button>
    <button class="btn ghost" id="acctt">Changer mon mot de passe</button>
    <div id="acctpw" hidden style="margin-top:10px">
      <div class="err" id="acctpe" hidden></div>
      <input type="password" id="acctold" maxlength="72" placeholder="Mot de passe actuel"
             autocomplete="current-password">
      <input type="password" id="acctnew" maxlength="72" placeholder="Nouveau mot de passe"
             autocomplete="new-password">
      <button class="btn" id="acctsb">Enregistrer le mot de passe</button>
    </div>
    <button class="btn ghost" id="acctc">Fermer</button>
    <button class="btn" id="acctl" style="background:#c0392b;margin-top:8px">Se déconnecter</button>
  </div>
</div>

<div id="toast" hidden></div>

<script>
'use strict';
const $  = s => document.querySelector(s);
const el = (t, c, x) => { const n = document.createElement(t); if (c) n.className = c; if (x != null) n.textContent = x; return n; };

let S = null;           // dernier snapshot
let me = null;          // joueur courant
let timer = null;
let busy = false;       // un pronostic est en cours d'envoi

const expanded = new Set();
let fam = 'all';

let closed = new Set();
try { closed = new Set(JSON.parse(localStorage.getItem('prono_closed') || '[]')); } catch (e) {}
const saveClosed = () => { try { localStorage.setItem('prono_closed', JSON.stringify([...closed])); } catch (e) {} };

const FAM = {
  MATCH_WINNER: 'duels', EVENT_WINNER: 'titres',
  QUAL_TIERCE: 'qualifs', QUAL_TOP1: 'qualifs', QUAL_CUT: 'qualifs',
};
const FAM_ORDER = ['duels', 'titres', 'qualifs'];
const FAM_LABEL = {
  all: 'Tout', duels: 'Duels', titres: 'Vainqueurs', qualifs: 'Qualifications',
};
const TYPE_LABEL = {
  MATCH_WINNER: 'Qui gagne le duel ?',
  EVENT_WINNER: 'Vainqueur de l\'épreuve',
  QUAL_TIERCE: 'Tiercé : 1er, 2e, 3e',
  QUAL_TOP1: 'Score du 1er qualifié', QUAL_CUT: 'Score du cut (dernier qualifié)',
};

async function call(action, body) {
  const opt = { method: body ? 'POST' : 'GET', credentials: 'same-origin' };
  if (body) {
    opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    opt.body = new URLSearchParams(body).toString();
  }
  const r = await fetch('api.php?a=' + action, opt);
  const j = await r.json().catch(() => ({ error: 'Réponse illisible' }));
  if (!r.ok) throw new Error(j.error || ('Erreur ' + r.status));
  return j;
}

function toast(msg, ms = 2600) {
  const t = $('#toast');
  t.textContent = msg; t.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { t.hidden = true; }, ms);
}

// ── Filtres ──────────────────────────────────────────────────────────────────
function openMarkets() {
  return (S.markets || []).filter(m => m.status !== 'SETTLED');
}

function renderFilters() {
  const all = openMarkets();
  const box = $('#fams');
  box.textContent = '';

  const counts = { all: all.length };
  all.forEach(m => { const f = FAM[m.type] || 'duels'; counts[f] = (counts[f] || 0) + 1; });
  if (fam !== 'all' && !counts[fam]) fam = 'all';

  ['all', ...FAM_ORDER.filter(f => counts[f])].forEach(f => {
    const b = el('button', '', FAM_LABEL[f]);
    b.appendChild(el('b', '', String(counts[f] || 0)));
    b.setAttribute('aria-pressed', String(fam === f));
    b.onclick = () => { fam = f; renderMarkets(); renderFilters(); };
    box.appendChild(b);
  });
  $('#filters').hidden = !all.length;
}

// ── Marchés ──────────────────────────────────────────────────────────────────
function myPick(marketId) {
  return (S.mybets || []).find(b => +b.mk === +marketId) || null;
}

function renderMarkets() {
  const box = $('#mklist');
  box.textContent = '';

  if (!S.open) {
    box.appendChild(el('div', 'empty', 'Les pronostics sont fermés pour le moment.'));
    return;
  }

  if (!betsOpen()) {
    const b = el('div', 'closed');
    b.appendChild(el('b', '', 'Les pronostics sont clos.'));
    b.appendChild(el('div', '', 'Tu peux toujours suivre tes pronostics et le classement.'));
    box.appendChild(b);
  } else if (S.left != null && S.left > 0 && S.left < 7200) {
    box.appendChild(el('div', 'deadline', 'Fermeture des pronostics dans ' + countdown(S.left)));
  }

  let list = openMarkets();
  if (!list.length) {
    box.appendChild(el('div', 'empty', 'Rien à pronostiquer pour l\'instant. Ça arrive dès que la grille est tirée.'));
    return;
  }
  if (fam !== 'all') list = list.filter(m => (FAM[m.type] || 'duels') === fam);

  if (!list.length) {
    box.appendChild(el('div', 'empty', 'Rien dans cette catégorie. Choisis « Tout » pour revoir l\'ensemble.'));
    return;
  }

  const groups = new Map();
  list.forEach(m => {
    if (!groups.has(m.ev)) groups.set(m.ev, []);
    groups.get(m.ev).push(m);
  });

  const single = groups.size === 1;
  for (const [code, items] of groups) {
    const name = (S.events || {})[code] || code;
    if (single) { items.forEach(m => box.appendChild(renderMarket(m))); continue; }

    const d = el('details', 'sec');
    d.open = !closed.has(code);
    const sm = el('summary');
    sm.appendChild(el('span', 'arw', '▶'));
    sm.appendChild(el('span', '', name));
    sm.appendChild(el('span', 'cnt', String(items.length)));
    d.appendChild(sm);
    const body = el('div', 'body');
    items.forEach(m => body.appendChild(renderMarket(m)));
    d.appendChild(body);
    d.addEventListener('toggle', () => {
      d.open ? closed.delete(code) : closed.add(code);
      saveClosed();
    });
    box.appendChild(d);
  }
}

/**
 * Redessine une seule carte. Monter un score se fait en plusieurs appuis : re-rendre
 * toute la liste à chaque « + » ferait sauter le défilement sous le doigt.
 */
function rerenderCard(m) {
  const old = document.querySelector('.mk[data-mk="' + m.id + '"]');
  if (old) old.replaceWith(renderMarket(m));
  else renderMarkets();
}

/** Pronostics fermés : plus rien n'est cliquable, mais tout reste consultable. */
function betsOpen() {
  return S.betsOpen === undefined ? true : !!S.betsOpen;
}

function countdown(sec) {
  if (sec == null || sec <= 0) return '';
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
  if (h > 0) return h + ' h ' + String(m).padStart(2, '0');
  if (m > 0) return m + ' min';
  return sec + ' s';
}

function renderMarket(m) {
  const locked = m.status !== 'OPEN' || !betsOpen();
  const card = el('div', 'mk' + (locked ? ' locked' : ''));
  card.dataset.mk = m.id;
  const mine = myPick(m.id);
  // Sur les marchés au long cours, le pronostic est définitif une fois posé.
  const frozen = locked || !!(mine && m.fixed);

  const h = el('h3');
  h.appendChild(document.createTextNode(m.label));
  if (locked) h.appendChild(el('span', 'badge b-lock', 'fermé'));
  else if (/en cours/.test(m.sub || '')) h.appendChild(el('span', 'badge b-live', 'en direct'));
  card.appendChild(h);

  const sub = el('div', 'sub');
  sub.textContent = (TYPE_LABEL[m.type] || '') + (m.sub ? ' · ' + m.sub : '');
  card.appendChild(sub);

  // Tiercé et fourchettes de qualification : structure propre, pas le schéma
  // vainqueur/score des duels.
  if (m.type === 'QUAL_TIERCE') { card.appendChild(renderTierce(m, mine, frozen)); return card; }
  if (m.type === 'QUAL_TOP1' || m.type === 'QUAL_CUT') {
    card.appendChild(renderQualBand(m, mine, frozen));
    if (mine && !locked) {
      card.appendChild(el('div', 'hintline',
        m.fixed ? 'Ton pronostic est définitif sur ce marché.' : 'Tu peux encore changer d\'avis.'));
    }
    return card;
  }

  const wins   = m.sels.filter(s => s.grp !== 'S');
  const scores = m.sels.filter(s => s.grp === 'S');
  // Deux formes de score : points de set (« 6-2 ») en arc classique, tranches de
  // total (« A138 ») à l'arc à poulies, qui se joue au cumul.
  const isSets  = scores.length > 0 && scores.every(s => /^\d+-\d+$/.test(s.code));
  const hasStep = isSets && wins.length === 2;
  const hasBands = scores.length > 0 && !isSets;

  // Dès qu'un côté atteint le seuil de victoire, le vainqueur est désigné par le
  // score lui-même : on l'affiche comme choisi, sans avoir à cliquer son nom.
  let implied = null;
  if (hasStep) {
    const [a, b] = stepState.get(m.id) || [0, 0];
    const t = winTarget(scores);
    if (a >= t)      implied = wins[0].id;
    else if (b >= t) implied = wins[1].id;
  }

  card.appendChild(renderPlain(m, wins, mine, frozen, implied));

  // Score exact : même pronostic, on le construit en montant les points de set.
  if (hasStep)  card.appendChild(renderStepper(m, wins, scores, mine, frozen));
  if (hasBands) card.appendChild(renderBands(m, scores, mine, frozen));

  if (mine && !locked && m.status === 'OPEN') {
    // L'issue choisie a pu disparaître de la liste : c'est qu'elle est éliminée.
    const still = m.sels.some(s => +s.id === +mine.sel);
    card.appendChild(el('div', 'hintline',
      !still     ? 'Ton pronostic — ' + mine.pick + ' — est éliminé.'
      : m.fixed  ? 'Ton pronostic est définitif sur ce marché.'
                 : 'Tu peux encore changer d\'avis jusqu\'à la première volée.'));
  }
  return card;
}

/** Une issue = un bouton. Un appui pose le pronostic, un autre le déplace. */
function pickButton(cls, m, s, body, frozen) {
  const b = el('button', cls);
  body(b);
  b.disabled = !!frozen;
  if (!b.disabled) b.onclick = () => predict(m, s);
  return b;
}

/**
 * Points de set qui emportent le duel : 6 en individuel, 5 en équipes. Déduit de la
 * liste des scores possibles plutôt que codé en dur — c'est le plus petit total
 * gagnant que le serveur propose.
 */
function winTarget(scores) {
  return Math.min(...scores.map(s => {
    const [x, y] = s.code.split('-').map(Number);
    return Math.max(x, y);
  }));
}

function renderPlain(m, sels, mine, frozen, implied) {
  const frag = document.createDocumentFragment();
  const many = sels.length > 4;
  const shown = many && !expanded.has(m.id) ? sels.slice(0, 4) : sels;

  const wrap = el('div', 'sels' + (sels.length > 2 ? ' one' : ''));
  shown.forEach(s => {
    const isMine = mine && +mine.sel === +s.id;
    const cls = 'sel' + (isMine ? ' mine' : (+implied === +s.id ? ' implied' : ''));
    wrap.appendChild(pickButton(cls, m, s, b => {
      b.appendChild(el('span', 'nm', s.label));
      b.appendChild(el('span', 'pts', '+' + s.pts));
    }, frozen));
  });
  frag.appendChild(wrap);

  if (many) {
    const more = el('button', 'many',
      expanded.has(m.id) ? 'Replier' : ('Voir les ' + sels.length + ' issues'));
    more.onclick = () => {
      expanded.has(m.id) ? expanded.delete(m.id) : expanded.add(m.id);
      renderMarkets();
    };
    frag.appendChild(more);
  }
  return frag;
}

/**
 * Arc à poulies : le duel se joue au total de points, pas en sets. On propose des
 * tranches du total du vainqueur, groupées par archer — deviner un total exact sur
 * 150 points serait injouable, une tranche reste à portée.
 */
function renderBands(m, sels, mine, frozen) {
  const frag = document.createDocumentFragment();
  const byArcher = new Map();
  sels.forEach(s => {
    if (!byArcher.has(s.ath)) byArcher.set(s.ath, []);
    byArcher.get(s.ath).push(s);
  });

  frag.appendChild(el('div', 'hintline',
    'Ou annonce son score final : ça rapporte plus, et le bon vainqueur reste payant même si le total est manqué.'));

  for (const [ath, list] of byArcher) {
    const who = (list[0].label || '').replace(/\s*\d+-\d+\s*$/, '').trim() || ('#' + ath);
    const wing = el('div', 'wing');
    wing.appendChild(el('div', 'who', who));

    const grid = el('div', 'chipgrid');
    list.forEach(s => {
      const band = (s.label.match(/(\d+-\d+)\s*$/) || [, s.code])[1];
      const isMine = mine && +mine.sel === +s.id;
      grid.appendChild(pickButton('chip' + (isMine ? ' mine' : ''), m, s, b => {
        b.appendChild(el('b', '', band));
        b.appendChild(el('span', '', '+' + s.pts));
      }, frozen));
    });
    wing.appendChild(grid);
    frag.appendChild(wing);
  }
  return frag;
}

/** Fourchette de qualification (score du 1er ou du cut) : pas d'archer, juste des tranches. */
function renderQualBand(m, mine, frozen) {
  const frag = document.createDocumentFragment();
  const grid = el('div', 'chipgrid');
  m.sels.forEach(s => {
    const isMine = mine && +mine.sel === +s.id;
    grid.appendChild(pickButton('chip' + (isMine ? ' mine' : ''), m, s, b => {
      b.appendChild(el('b', '', s.label));
      b.appendChild(el('span', '', '+' + s.pts));
    }, frozen));
  });
  frag.appendChild(grid);
  return frag;
}

/**
 * Tiercé de qualification : 3 choix (1er / 2e / 3e), un seul pronostic posé d'un
 * coup — comme aux courses hippiques. Définitif dès qu'il est posé (marché au long
 * cours, comme le vainqueur d'épreuve) : une fois `mine` renseigné, plus rien à
 * choisir, juste un rappel de ce qui a été pronostiqué.
 */
const tierceState = new Map();   // marché → [id choisi 1er, 2e, 3e] en cours de saisie

function renderTierce(m, mine, frozen) {
  const frag = document.createDocumentFragment();

  if (mine) {
    const box = el('div', 'sel mine');
    box.style.cssText = 'display:block;flex-direction:column;align-items:stretch;gap:4px';
    box.appendChild(el('div', 'nm', 'Ton tiercé : ' + mine.pick));
    box.appendChild(el('div', 'pts', '+' + mine.pts + ' pts si l\'ordre est exact, moins si le trio est bon dans le désordre'));
    frag.appendChild(box);
    return frag;
  }
  if (frozen) {
    frag.appendChild(el('div', 'hintline', 'Ce tiercé est fermé.'));
    return frag;
  }

  // Étendue de points sur CETTE épreuve : un tiercé sûr (les favoris dans l'ordre
  // attendu) rapporte le moins, un tiercé risqué (les plus gros outsiders) rapporte
  // le plus — de quoi choisir sa stratégie avant même de nommer ses 3 archers.
  if (m.tierce) {
    const range = el('div', 'hintline');
    range.style.cssText = 'margin-bottom:8px';
    range.textContent = 'Dans l\'ordre : de +' + m.tierce.orderMin + ' (tiercé sûr) à +'
      + m.tierce.orderMax + ' pts (tiercé risqué) · dans le désordre : de +'
      + m.tierce.anyMin + ' à +' + m.tierce.anyMax + ' pts.';
    frag.appendChild(range);
  }

  const byGrp = g => m.sels.filter(s => s.grp === g);
  const picks = tierceState.get(m.id) || [null, null, null];

  const row = (label, list, idx) => {
    const wrap = el('div');
    wrap.appendChild(el('div', 'sub', label));
    const sel = document.createElement('select');
    sel.style.cssText = 'width:100%;padding:9px;border:1px solid var(--doux);border-radius:6px;'
      + 'font:inherit;background:#fff;color:var(--texte);margin-bottom:8px';
    const opt0 = el('option', '', '— choisir —');
    opt0.value = '';
    sel.appendChild(opt0);
    list.forEach(s => {
      const o = el('option', '', s.label + ' (+' + s.pts + ')');
      o.value = String(s.id);
      if (picks[idx] && +picks[idx] === +s.id) o.selected = true;
      sel.appendChild(o);
    });
    sel.onchange = () => {
      const p = (tierceState.get(m.id) || [null, null, null]).slice();
      p[idx] = sel.value || null;
      tierceState.set(m.id, p);
      rerenderCard(m);
    };
    wrap.appendChild(sel);
    return wrap;
  };

  frag.appendChild(row('1er', byGrp('R1'), 0));
  frag.appendChild(row('2e', byGrp('R2'), 1));
  frag.appendChild(row('3e', byGrp('R3'), 2));

  const [p1, p2, p3] = picks;
  const allPicked = p1 && p2 && p3;
  const distinct  = allPicked && p1 !== p2 && p1 !== p3 && p2 !== p3;

  const act = el('div', 'stepact');
  if (allPicked && !distinct) {
    act.appendChild(el('div', 'hintline', 'Les 3 noms doivent être différents.'));
  }
  const go = el('button', 'btn small', 'Valider le tiercé');
  go.disabled = !distinct;
  if (distinct) go.onclick = () => predictTierce(m, p1, p2, p3);
  act.appendChild(go);
  frag.appendChild(act);

  return frag;
}

async function predictTierce(m, s1, s2, s3) {
  if (!me) { $('#joinv').hidden = false; return; }
  if (busy) return;
  busy = true;
  try {
    const r = await call('predict3', { s1, s2, s3 });
    tierceState.delete(m.id);
    toast('Tiercé enregistré : ' + r.pick.label + ' (+' + r.pick.pts + ' pts si l\'ordre est exact)');
    await refresh();
  } catch (e) {
    toast(e.message, 3400);
  } finally {
    busy = false;
  }
}

/**
 * Sélecteur de score : on part de 0-0 et on monte les points de set d'un côté ou de
 * l'autre. Un « + » ne s'active que si un score final valable reste atteignable —
 * impossible donc de composer un 4-4 en individuel ou de dépasser 6-2 en équipes.
 * Les codes d'issue sont orientés « gauche-droite », dans l'ordre des deux archers.
 */
const stepState = new Map();   // marché → [ptsGauche, ptsDroite] en cours de saisie

function renderStepper(m, wins, scores, mine, frozen) {
  const byCode = new Map(scores.map(s => [s.code, s]));
  const valid  = scores.map(s => s.code.split('-').map(Number));

  // On repart du pronostic déjà posé s'il portait sur un score.
  const mineScore = mine && scores.find(s => +s.id === +mine.sel);
  if (mineScore && !stepState.has(m.id)) {
    stepState.set(m.id, mineScore.code.split('-').map(Number));
  }
  const [a, b] = stepState.get(m.id) || [0, 0];

  const reachable = (x, y) => valid.some(([vx, vy]) => vx >= x && vy >= y);
  const exact     = byCode.get(a + '-' + b);

  const box = el('div', 'stepper' + (frozen ? ' off' : ''));

  // Un « − » ramène toujours vers un état déjà atteint : seul le plancher zéro le bride.
  const step = (label, cls, dx, dy, ok, who) => {
    const btn = el('button', cls, label);
    btn.disabled = frozen || !ok;
    btn.setAttribute('aria-label', label === '+'
      ? 'Un point de set de plus pour ' + who
      : 'Un point de set de moins pour ' + who);
    btn.onclick = () => { stepState.set(m.id, [a + dx, b + dy]); rerenderCard(m); };
    return btn;
  };

  const sc = el('div', 'sc');
  sc.appendChild(el('b', a > b ? 'lead' : '', String(a)));
  sc.appendChild(el('i', '', '–'));
  sc.appendChild(el('b', b > a ? 'lead' : '', String(b)));

  box.appendChild(step('−', 'minus', -1, 0, a > 0, wins[0].label));
  box.appendChild(step('+', 'plus', 1, 0, reachable(a + 1, b), wins[0].label));
  box.appendChild(sc);
  box.appendChild(step('+', 'plus', 0, 1, reachable(a, b + 1), wins[1].label));
  box.appendChild(step('−', 'minus', 0, -1, b > 0, wins[1].label));

  const frag = document.createDocumentFragment();
  frag.appendChild(box);

  if (a || b) {
    const act = el('div', 'stepact');
    if (exact) {
      const isMine = mine && +mine.sel === +exact.id;
      const go = el('button', 'btn small' + (isMine ? ' done' : ''));
      go.textContent = isMine
        ? '✓ Ton pronostic : ' + a + '–' + b + ' (+' + exact.pts + ' pts)'
        : 'Valider ' + a + '–' + b + ' · +' + exact.pts + ' pts';
      go.disabled = frozen || isMine;
      if (!go.disabled) go.onclick = () => predict(m, exact);
      act.appendChild(go);
    } else {
      // Seuls les scores réellement possibles en fin de duel sont enregistrables.
      act.appendChild(el('div', 'hintline',
        'Continue : ' + a + '–' + b + ' ne peut pas être le score final d\'un duel.'));
    }
    const undo = el('button', 'many');
    undo.textContent = 'Effacer le score';
    undo.onclick = () => { stepState.delete(m.id); rerenderCard(m); };
    act.appendChild(undo);
    frag.appendChild(act);
  } else if (!frozen) {
    frag.appendChild(el('div', 'hintline',
      'Ou monte le score set par set : ça rapporte plus, et le bon vainqueur reste payant même si le score est manqué.'));
  }
  return frag;
}

async function predict(m, s) {
  if (!me) { $('#joinv').hidden = false; return; }
  if (busy) return;
  busy = true;
  try {
    const r = await call('predict', { sel: s.id });
    // Pronostic sur le seul vainqueur : le score en cours de saisie n'a plus de sens.
    if (s.grp !== 'S') stepState.delete(m.id);
    if (!r.same) {
      toast((r.changed ? 'Pronostic modifié : ' : 'Pronostic enregistré : ')
            + r.pick.label + ' (+' + r.pick.pts + ' pts si tu as raison)');
    }
    await refresh();
  } catch (e) {
    toast(e.message, 3400);
  } finally {
    busy = false;
  }
}

// ── Mes pronostics et classement ─────────────────────────────────────────────
function renderMine() {
  const box = $('#p-my');
  box.textContent = '';
  const bets = S.mybets || [];
  if (!me)          { box.appendChild(el('div', 'empty', 'Choisis un pseudo pour commencer à pronostiquer.')); return; }
  if (!bets.length) { box.appendChild(el('div', 'empty', 'Aucun pronostic pour l\'instant.')); return; }

  const pending = bets.filter(b => b.status === 'PENDING');
  const enJeu   = pending.reduce((s, b) => s + Number(b.pts), 0);
  const gagnes  = bets.filter(b => b.status === 'WON').length;

  const tot = el('div', 'tot');
  tot.appendChild(document.createTextNode('Acquis : '));
  tot.appendChild(el('b', '', Math.round(me.points) + ' pts'));
  tot.appendChild(document.createTextNode(' sur ' + gagnes + ' pronostic' + (gagnes > 1 ? 's' : '') + ' juste' + (gagnes > 1 ? 's' : '')));
  if (enJeu) {
    tot.appendChild(document.createTextNode(' · encore '));
    tot.appendChild(el('b', '', enJeu + ' pts'));
    tot.appendChild(document.createTextNode(' en jeu sur ' + pending.length + ' en attente'));
  }
  box.appendChild(tot);

  bets.forEach(b => {
    const d = el('div', 'bet ' + b.status);
    const col = el('div', 'col');
    col.appendChild(el('div', 't', b.pick));
    col.appendChild(el('div', 'd', b.label));
    col.appendChild(el('div', 'd', b.sub));
    d.appendChild(col);
    d.appendChild(el('span', 'r',
      b.status === 'PENDING' ? '+' + b.pts + ' ?'
    : b.status === 'WON'     ? '+' + b.pts + ' pts'
    : b.status === 'VOID'    ? 'annulé'
    : '0 pt'));
    box.appendChild(d);
  });
}

let boardTab = 'day';   // 'day' = cette compétition, 'season' = classement général

function renderBoard() {
  const box = $('#p-bd');
  box.textContent = '';

  const season = S.season || [];
  if (season.length) {
    const seg = el('div', 'seg');
    [['day', 'Cette compétition'], ['season', 'Saison']].forEach(([k, lbl]) => {
      const b = el('button', '', lbl);
      b.setAttribute('aria-pressed', String(boardTab === k));
      b.onclick = () => { boardTab = k; renderBoard(); };
      seg.appendChild(b);
    });
    box.appendChild(seg);
  } else {
    boardTab = 'day';
  }

  const rows = boardTab === 'season' ? season : (S.board || []);
  if (!rows.length) { box.appendChild(el('div', 'empty', 'Personne n\'a encore pronostiqué.')); return; }

  if (boardTab === 'season') {
    box.appendChild(el('div', 'hintline',
      'Total des points sur toutes les compétitions retenues pour la saison.'));
  } else if (!S.inseason) {
    box.appendChild(el('div', 'hintline',
      'Cette compétition ne compte pas pour le classement de la saison.'));
  }

  const t = el('table');
  const hr = el('tr');
  const cols = boardTab === 'season'
    ? ['#', 'Joueur', 'Compét.', 'Justes', 'Points']
    : ['#', 'Joueur', 'Justes', 'Points'];
  cols.forEach((x, i) => hr.appendChild(el('th', i > 1 ? 'n' : '', x)));
  t.appendChild(el('thead')).appendChild(hr);

  const tb = el('tbody');
  rows.forEach((r, i) => {
    const tr = el('tr', me && r.PaUsNick === me.nick ? 'me' : '');
    tr.appendChild(el('td', '', String(i + 1)));
    tr.appendChild(el('td', '', r.PaUsNick));
    if (boardTab === 'season') tr.appendChild(el('td', 'n', String(r.PaUsEvents)));
    tr.appendChild(el('td', 'n', r.PaUsWon + '/' + r.PaUsBets));
    tr.appendChild(el('td', 'n', String(r.PaUsPoints)));
    tb.appendChild(tr);
  });
  t.appendChild(tb);
  box.appendChild(t);

  if ((S.recent || []).length) {
    box.appendChild(el('div', 'sec'));
    box.appendChild(el('div', 'sub', 'Derniers résultats'));
    S.recent.forEach(r => {
      const d = el('div', 'bet');
      const col = el('div', 'col');
      col.appendChild(el('div', 't', r.PaSeLabel));
      col.appendChild(el('div', 'd', r.PaMkLabel));
      d.appendChild(col);
      box.appendChild(d);
    });
  }
}

function render() {
  $('#title').textContent = S.title || 'Pronostics';
  me = S.me || null;

  $('#purse').hidden = !me;
  if (me) {
    $('#nick').textContent = me.nick;
    $('#points').textContent = Math.round(me.points);
  }
  const pending = (S.mybets || []).filter(b => b.status === 'PENDING').length;
  $('#pip').hidden = !pending;
  $('#pip').textContent = pending;

  renderFilters(); renderMarkets(); renderMine(); renderBoard();
  $('#joinv').hidden = !!me;
}

// ── Compte ───────────────────────────────────────────────────────────────────
let authMode = 'join';

function setAuthMode(m) {
  authMode = m;
  document.querySelectorAll('#mode button').forEach(b =>
    b.setAttribute('aria-pressed', String(b.dataset.m === m)));
  const join = m === 'join';
  $('#authh').textContent = join ? 'Choisis ton pseudo' : 'Reprends tes pronostics';
  $('#authp').textContent = join
    ? 'Pas de mise, pas d\'argent : tu pronostiques, et tu marques des points quand tu vois juste. '
      + 'Le mot de passe sert uniquement à retrouver tes pronostics si tu changes de téléphone.'
    : 'Entre le pseudo et le mot de passe que tu as choisis : tu retrouveras tes points et tes pronostics en cours.';
  $('#joinp').setAttribute('autocomplete', join ? 'new-password' : 'current-password');
  $('#joinb').textContent = join ? 'C\'est parti' : 'Me connecter';
  $('#joine').hidden = true;
}

document.querySelectorAll('#mode button').forEach(b => { b.onclick = () => setAuthMode(b.dataset.m); });
setAuthMode('join');

$('#joinb').onclick = async () => {
  const nick = $('#joinn').value.trim();
  const pass = $('#joinp').value;
  $('#joinb').disabled = true;
  try {
    await call(authMode, { nick, pass });
    $('#joinv').hidden = true;
    $('#joinp').value = '';
    await refresh();
    toast(authMode === 'join' ? 'Bienvenue ' + nick + ' !' : 'Content de te revoir, ' + nick + ' !');
  } catch (e) {
    $('#joine').textContent = e.message;
    $('#joine').hidden = false;
  } finally {
    $('#joinb').disabled = false;
  }
};
$('#joinn').addEventListener('keydown', e => { if (e.key === 'Enter') $('#joinp').focus(); });
$('#joinp').addEventListener('keydown', e => { if (e.key === 'Enter') $('#joinb').click(); });

$('#purse').onclick = () => {
  if (!me) return;
  $('#acctn').textContent = me.nick;
  $('#acctp').textContent = Math.round(me.points) + ' pts · ' + me.won + ' pronostic'
    + (me.won > 1 ? 's justes' : ' juste') + ' sur ' + me.bets
    + '. Tu peux te reconnecter depuis n\'importe quel appareil avec ton pseudo et ton mot de passe.';
  $('#acctwarn').hidden = !me.nopass;
  $('#acctold').hidden  = !!me.nopass;
  $('#acctt').hidden    = !!me.nopass;
  $('#acctpw').hidden   = !me.nopass;
  $('#acctpe').hidden   = true;
  $('#acctne').hidden   = true;
  $('#acctnick').value  = me.nick;
  $('#acctold').value = ''; $('#acctnew').value = '';
  $('#acctv').hidden = false;
};
$('#acctt').onclick = () => { $('#acctpw').hidden = !$('#acctpw').hidden; };
$('#acctc').onclick = () => { $('#acctv').hidden = true; };

$('#acctnb').onclick = async () => {
  const nick = $('#acctnick').value.trim();
  $('#acctnb').disabled = true;
  try {
    const r = await call('setnick', { nick });
    $('#acctne').hidden = true;
    if (!r.same) toast('Pseudo changé : ' + nick);
    $('#acctv').hidden = true;
    await refresh();
  } catch (e) {
    $('#acctne').textContent = e.message;
    $('#acctne').hidden = false;
  } finally {
    $('#acctnb').disabled = false;
  }
};

$('#acctsb').onclick = async () => {
  const body = { pass: $('#acctnew').value };
  if (!me.nopass) body.old = $('#acctold').value;
  $('#acctsb').disabled = true;
  try {
    await call('setpass', body);
    $('#acctv').hidden = true;
    toast('Mot de passe enregistré.');
    await refresh();
  } catch (e) {
    $('#acctpe').textContent = e.message;
    $('#acctpe').hidden = false;
  } finally {
    $('#acctsb').disabled = false;
  }
};

$('#acctl').onclick = async () => {
  try { await call('logout', { x: 1 }); } catch (e) {}
  $('#acctv').hidden = true;
  S = null; me = null;
  setAuthMode('login');
  await refresh();
};

document.querySelectorAll('#tabs button').forEach(b => {
  b.onclick = () => {
    document.querySelectorAll('#tabs button').forEach(x => x.setAttribute('aria-selected', String(x === b)));
    ['mk', 'my', 'bd'].forEach(p => { $('#p-' + p).hidden = (p !== b.dataset.p); });
    window.scrollTo({ top: 0 });
  };
});

// ── Boucle ───────────────────────────────────────────────────────────────────
async function refresh() {
  try {
    S = await call('snap');
    render();
  } catch (e) {
    if (!S) {
      $('#mklist').textContent = '';
      $('#mklist').appendChild(el('div', 'empty', e.message));
    }
  }
}

function schedule() {
  clearInterval(timer);
  timer = setInterval(() => { if (!document.hidden && !busy) refresh(); }, 10000);
}

document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
refresh().then(schedule);

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
</script>
</body>
</html>
