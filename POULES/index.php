<?php
// =============================================================================
// index.php — Vue commentateur des poules Round Robin (enjeux & classement live)
// Lecture seule. Auto-refresh AJAX sur action.php. Projections calculées en JS :
// rangs atteignables (au mieux / au pire), statuts qualif / relégation,
// détection des matchs décisifs du round en cours.
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId   = intval($_SESSION['TourId']);
$compName = $_SESSION['TourNameSafe'] ?? '';
if (!$compName) {
    $rs = safe_r_sql("SELECT ToName FROM Tournament WHERE ToId=$tourId LIMIT 1");
    if ($r = safe_fetch($rs)) $compName = $r->ToName;
}
$base = $CFG->ROOT_DIR . 'Modules/Custom/POULES/';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Poules — Vue commentateur</title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Verdana, sans-serif; background: #eef1f6; margin: 0; color: #20263d; }

/* cache l'habillage ianseo si la page est servie dans son contexte */
#TourInfo, #navigation, #tnm-nav { display: none; }
#Content { padding: 0; height: auto; }

/* ── Header ── */
.pl-hdr { background: #02215e; color: #fff; padding: 8px 16px; position: sticky; top: 0; z-index: 100;
          box-shadow: 0 2px 6px rgba(0,0,0,.35); display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.pl-hdr-name { font-size: .8em; opacity: .8; text-transform: uppercase; letter-spacing: .05em;
               flex: 1 1 200px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pl-tabs { display: flex; gap: 6px; }
.pl-tab { background: rgba(255,255,255,.13); border: 1px solid rgba(255,255,255,.25); color: #fff;
          border-radius: 6px; padding: 6px 16px; font-size: .95em; cursor: pointer; font-weight: 600; }
.pl-tab.cur { background: #fff; color: #02215e; }
.pl-hdr-right { display: flex; align-items: center; gap: 10px; font-size: .75em; opacity: .85; }
.pl-gear { cursor: pointer; font-size: 1.5em; background: none; border: none; color: #fff; padding: 2px 6px; }
.pl-home { color: #fff; text-decoration: none; font-size: 1.3em; }

/* ── Panneau réglages ── */
#pl-prefs { display: none; background: #fff; border-bottom: 2px solid #d2d4d6; padding: 10px 16px;
            font-size: .85em; }
#pl-prefs.open { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; }
#pl-prefs label { display: flex; align-items: center; gap: 6px; }
#pl-prefs input[type=number] { width: 60px; padding: 4px; border: 1px solid #d2d4d6; border-radius: 5px; }
#pl-prefs input[type=text]   { width: 140px; padding: 4px; border: 1px solid #d2d4d6; border-radius: 5px; }

/* ── Bandeau round ── */
.pl-round { background: #0254a8; color: #fff; padding: 8px 16px; font-size: 1em; display: flex;
            gap: 18px; align-items: baseline; flex-wrap: wrap; }
.pl-round b { font-size: 1.15em; }
.pl-round .fin { color: #a7d6ff; }

/* ── Corps deux colonnes ── */
.pl-body { display: grid; grid-template-columns: minmax(430px, 5fr) minmax(360px, 4fr); gap: 14px;
           padding: 14px 16px; align-items: start; }
@media (max-width: 950px) { .pl-body { grid-template-columns: 1fr; } }

.pl-card { background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow: hidden; }
.pl-card h2 { margin: 0; background: #f0f4ff; color: #01367c; font-size: .85em; text-transform: uppercase;
              letter-spacing: .06em; padding: 8px 12px; border-bottom: 1px solid #d2d4d6;
              display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.pl-sort { background: #fff; border: 1px solid #b0c4e8; color: #0254a8; border-radius: 5px;
           padding: 3px 10px; font-size: .95em; font-weight: 700; cursor: pointer;
           text-transform: none; letter-spacing: normal; white-space: nowrap; }
.pl-sort:hover { background: #e2ecfb; }

/* ── Classement ── */
table.pl-std { width: 100%; border-collapse: collapse; font-size: .92em; }
table.pl-std th { background: #fff; color: #7d8183; font-size: .72em; text-transform: uppercase;
                  padding: 5px 6px; border-bottom: 2px solid #d2d4d6; text-align: center; }
table.pl-std td { padding: 5px 6px; border-bottom: 1px solid #eef0f4; text-align: center; white-space: nowrap; }
table.pl-std td.nm { text-align: left; font-weight: 600; white-space: normal; }
table.pl-std td.pts { font-weight: 800; font-size: 1.1em; }
table.pl-std td.rg { font-weight: 700; color: #7d8183; }
table.pl-std td.range { font-size: .85em; color: #4c4e50; }
tr.z-q  { background: #f2fbf5; }
tr.z-r  { background: #fff5f5; }
tr.sep-q td { border-bottom: 3px solid #2ad56e; }
tr.sep-r td { border-top: 3px solid #ff5043; }
.badge { display: inline-block; border-radius: 5px; padding: 2px 7px; font-size: .7em; font-weight: 800;
         letter-spacing: .03em; }
.b-q   { background: #d2f4cd; color: #04ac0b; border: 1px solid #75ae77; }
.b-run { background: #fff0d4; color: #a85e00; border: 1px solid #e5b567; }
.b-out { background: #eee; color: #777; border: 1px solid #ccc; }
.b-rel { background: #ffd6db; color: #a80000; border: 1px solid #bb7575; }
.b-thr { background: #ffe9d4; color: #b34700; border: 1px solid #e59b67; }
.b-first { background: #d4e8ff; color: #01367c; border: 1px solid #7fa8d9; }

/* ── Matchs ── */
.pl-match { display: grid; grid-template-columns: 52px 1fr auto 1fr; gap: 8px; align-items: center;
            padding: 8px 12px; border-bottom: 1px solid #eef0f4; }
.pl-match .tgt { font-size: .75em; color: #7d8183; text-align: center; line-height: 1.3; }
.pl-match .tA { text-align: right; }
.pl-match .tB { text-align: left; }
.pl-match .tn { font-weight: 700; font-size: .95em; }
.pl-match .tsub { font-size: .75em; color: #7d8183; }
.pl-match .mid { text-align: center; font-weight: 800; font-size: 1.15em; min-width: 66px; }
.pl-match .mid .vs { color: #a0a4ab; font-size: .8em; font-weight: 600; }
.pl-match.live { background: #f0f8ff; }
.pl-match.live .mid { color: #0254a8; }
.pl-match .win { color: #04ac0b; }
.pl-match .lose { color: #a0a4ab; font-weight: 600; }
.pl-enjeu { grid-column: 1 / -1; font-size: .8em; color: #4c4e50; padding-left: 60px; }
.pl-enjeu .fl { margin-right: 4px; }
.pl-enjeu .e { display: inline-block; margin-right: 12px; }
.pl-enjeu .e-hot { color: #b34700; font-weight: 700; }
.pl-enjeu .e-none { color: #a0a4ab; font-style: italic; }
.pl-live-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: #ff5043;
               margin-right: 6px; animation: plblink 1.2s infinite; vertical-align: baseline; }
@keyframes plblink { 50% { opacity: .25; } }

.pl-empty { padding: 16px; color: #7d8183; font-style: italic; font-size: .9em; }
.pl-foot { text-align: center; color: #7d8183; font-size: .75em; padding: 6px 0 16px; }
</style>
</head>
<body>

<header class="pl-hdr">
    <div class="pl-hdr-name"><?= htmlspecialchars($compName) ?></div>
    <nav class="pl-tabs" id="pl-tabs"></nav>
    <div class="pl-hdr-right">
        <span id="pl-clock"></span>
        <button class="pl-gear" id="pl-gear" title="Réglages">⚙</button>
        <a class="pl-home" href="<?= $CFG->ROOT_DIR ?>Main.php" title="Menu ianseo">🏠</a>
    </div>
</header>

<div id="pl-prefs">
    <label>Places qualificatives <input type="number" id="pf-q" min="0" max="16"></label>
    <label>Nom de la phase suivante <input type="text" id="pf-qlabel"></label>
    <label>Places de relégation <input type="number" id="pf-r" min="0" max="16"></label>
    <label>Rafraîchir toutes les <input type="number" id="pf-refresh" min="3" max="120"> s</label>
    <label><input type="checkbox" id="pf-alt"> Alterner les épreuves (20 s)</label>
</div>

<div class="pl-round" id="pl-round"></div>
<div class="pl-body">
    <div class="pl-card">
        <h2>Classement</h2>
        <div id="pl-standings"></div>
    </div>
    <div>
        <div class="pl-card" style="margin-bottom:14px">
            <h2><span id="pl-next-title">Prochain round</span>
                <button class="pl-sort" id="pl-sort" title="Changer l'ordre d'affichage des matchs"></button></h2>
            <div id="pl-next"></div>
        </div>
        <div class="pl-card">
            <h2>Derniers résultats</h2>
            <div id="pl-last"></div>
        </div>
    </div>
</div>
<div class="pl-foot" id="pl-foot"></div>

<script>
(function () {
'use strict';
var ACTION = <?= json_encode($base . 'action.php') ?>;

/* ── Préférences ─────────────────────────────────────────────────────────── */
var DEFAULTS = { q: 4, qlabel: 'demi-finales', r: 0, refresh: 10, alt: false, sortTarget: false };
var prefs = DEFAULTS;
try { prefs = Object.assign({}, DEFAULTS, JSON.parse(localStorage.getItem('poules_prefs') || '{}')); }
catch (e) {}
function savePrefs() { localStorage.setItem('poules_prefs', JSON.stringify(prefs)); }

var state = null;      // dernière réponse serveur
var curKey = new URLSearchParams(location.search).get('ev') || null;

/* ── Utilitaires ─────────────────────────────────────────────────────────── */
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function ord(n) { return n === 1 ? '1<sup>re</sup>' : n + '<sup>e</sup>'; }
function ordTxt(n) { return n === 1 ? '1re' : n + 'e'; }

/* ── Analyse d'une poule ─────────────────────────────────────────────────── */
function analyze(ev) {
    var win = ev.winPts || 2;
    var E = ev.ends || 4; // volées par match
    var T = ev.teams.map(function (t) { return Object.assign({}, t); });
    // tb / tb2 arrivent du serveur : Σ des tie-breaks natifs ianseo par match,
    // qui suivent le système configuré (sets ou cumul de points)
    T.sort(function (x, y) {
        return (y.pts - x.pts) || (y.tb - x.tb) || (y.tb2 - x.tb2) || x.name.localeCompare(y.name);
    });
    T.forEach(function (t, i) { t.rank = i + 1; });

    var N = T.length;
    var Q = Math.min(prefs.q, N);
    var safeLine = N - Math.min(prefs.r, N);

    // Raffinement par le départage (différence de sets, système 5, épreuves en
    // sets sans nul) : un match ne peut se finir qu'en 6-0…5-4 (équipes) ou
    // 6-0…6-5 (indiv) → le vainqueur gagne +1 à +6 de différentiel, le perdant
    // −1 à −6. Pour un match EN COURS, les sets déjà acquis resserrent ces
    // bornes (écart actuel ± 2 × volées restantes) et peuvent rendre l'issue
    // certaine avant la fin : ces certitudes entrent dans les projections.
    var refine = (ev.matchMode === 1 && ev.tieSys === 5 && !ev.tieAllowed);
    var byIdT = {};
    T.forEach(function (t) { byIdT[t.id] = t; });

    var pending = [];
    ev.matches.forEach(function (m) {
        if (m.state === 'done') return;
        var A = byIdT[m.a.id], B = byIdT[m.b.id];
        if (!A || !B) return;
        var r = Math.max(0, E - (m.ep || 0)); // volées restantes
        var d = (m.a.st || 0) - (m.b.st || 0); // écart de sets actuel
        var p = {
            A: A, B: B,
            canA: !refine || d + 2 * r >= 0,   // égalité finale → tir de barrage → possible
            canB: !refine || 2 * r - d >= 0,
            minA: Math.max(1, d - 2 * r), maxA: Math.min(6, Math.max(1, d + 2 * r)),
            minB: Math.max(1, -d - 2 * r), maxB: Math.min(6, Math.max(1, 2 * r - d))
        };
        if (!p.canA && !p.canB) { p.canA = p.canB = true; } // données incohérentes : tout ouvrir
        pending.push(p);
    });
    var allDone = pending.length === 0;

    // Agrégats par équipe : victoires encore possibles / déjà acquises (matchs
    // en cours pliés), et bornes du différentiel final.
    T.forEach(function (t) { t.winnable = 0; t.certain = 0; t._tbUp = 0; t._tbDn = 0; });
    pending.forEach(function (p) {
        if (p.canA) { p.A.winnable++; p.A._tbUp += p.maxA; } else { p.A._tbUp -= p.minB; }
        if (p.canB) { p.A._tbDn -= p.maxB; } else { p.A.certain++; p.A._tbDn += p.minA; }
        if (p.canB) { p.B.winnable++; p.B._tbUp += p.maxB; } else { p.B._tbUp -= p.minA; }
        if (p.canA) { p.B._tbDn -= p.maxA; } else { p.B.certain++; p.B._tbDn += p.minB; }
    });
    T.forEach(function (t) {
        t.maxPts = t.pts + win * t.winnable;
        t.minPts = t.pts + win * t.certain;
        t.maxTb  = t.tb + t._tbUp;
        t.minTb  = t.tb + t._tbDn;
    });

    // Meilleur rang atteignable : t gagne tout ce qu'il peut encore gagner (avec
    // l'écart max) ; les prétendants perdent tout ce qu'ils peuvent perdre ;
    // seuls leurs matchs ENTRE EUX (issue libre) sont énumérés.
    function bestRankOf(t) {
        var alwaysAbove = 0, contenders = [];
        T.forEach(function (o) {
            if (o === t) return;
            if (o.minPts > t.maxPts) alwaysAbove++;
            else if (o.maxPts >= t.maxPts) contenders.push(o);
        });
        var simple = 1 + alwaysAbove + (allDone ? T.filter(function (o) {
            return o !== t && o.pts === t.maxPts && o.rank < t.rank;
        }).length : 0);
        if (!refine) return simple;

        var cid = {};
        contenders.forEach(function (o) { cid[o.id] = true; });
        var base = {};
        contenders.forEach(function (o) { base[o.id] = { w: 0, tb: o.tb }; });
        var free = [];
        pending.forEach(function (p) {
            var isA = !!cid[p.A.id], isB = !!cid[p.B.id];
            if (p.A === t || p.B === t) {
                var tIsA = (p.A === t);
                var o = tIsA ? p.B : p.A;
                if (!cid[o.id]) return;
                var tCan = tIsA ? p.canA : p.canB;
                if (tCan) base[o.id].tb -= tIsA ? p.maxA : p.maxB;      // t gagne large
                else { base[o.id].w++; base[o.id].tb += tIsA ? p.minB : p.minA; }
                return;
            }
            if (isA && isB) {
                if (p.canA && p.canB) { free.push(p); return; }
                if (p.canA) { base[p.A.id].w++; base[p.A.id].tb += p.minA; base[p.B.id].tb -= p.maxA; }
                else        { base[p.B.id].w++; base[p.B.id].tb += p.minB; base[p.A.id].tb -= p.maxB; }
                return;
            }
            if (isA) { if (p.canB) base[p.A.id].tb -= p.maxB; else { base[p.A.id].w++; base[p.A.id].tb += p.minA; } }
            else if (isB) { if (p.canA) base[p.B.id].tb -= p.maxA; else { base[p.B.id].w++; base[p.B.id].tb += p.minB; } }
        });
        if (free.length > 12) return simple; // trop tôt dans la poule : borne simple

        var best = Infinity, n = free.length;
        for (var mask = 0; mask < (1 << n); mask++) {
            var addW = {}, addTb = {};
            contenders.forEach(function (o) { addW[o.id] = 0; addTb[o.id] = 0; });
            for (var i = 0; i < n; i++) {
                var f = free[i];
                if ((mask >> i) & 1) { addW[f.A.id]++; addTb[f.A.id] += f.minA; addTb[f.B.id] -= f.maxA; }
                else                 { addW[f.B.id]++; addTb[f.B.id] += f.minB; addTb[f.A.id] -= f.maxB; }
            }
            var above = alwaysAbove;
            for (var j = 0; j < contenders.length; j++) {
                var o = contenders[j];
                var pp = o.pts + win * (base[o.id].w + addW[o.id]);
                if (pp > t.maxPts) above++;
                else if (pp === t.maxPts) {
                    var tbMin = base[o.id].tb + addTb[o.id];
                    if (tbMin > t.maxTb) above++;
                    else if (tbMin === t.maxTb && allDone && o.rank < t.rank) above++;
                }
            }
            if (above + 1 < best) best = above + 1;
            if (best === alwaysAbove + 1) break;
        }
        return best;
    }

    T.forEach(function (t) {
        // pire rang (pessimiste : t perd tout ce qu'il peut perdre, les autres
        // gagnent tout ce qu'ils peuvent gagner)
        var worst = 1;
        T.forEach(function (o) {
            if (o === t) return;
            if (o.maxPts > t.minPts) worst++;
            else if (o.maxPts === t.minPts) {
                if (o.remaining === 0 && t.remaining === 0) { if (o.rank < t.rank) worst++; }
                else if (!refine || o.maxTb >= t.minTb) worst++;
            }
        });
        t.best = bestRankOf(t);
        t.worst = worst;
        t.qSecured = Q > 0 && t.worst <= Q;
        t.qOut     = Q > 0 && t.best > Q;
        t.qRace    = Q > 0 && !t.qSecured && !t.qOut;
        t.rDoomed  = prefs.r > 0 && t.best > safeLine;
        t.rRisk    = prefs.r > 0 && !t.rDoomed && t.worst > safeLine;
        t.canWin   = t.best === 1;
        t.firstSecured = t.worst === 1;
    });
    var byId = {};
    T.forEach(function (t) { byId[t.id] = t; });
    return { teams: T, byId: byId, N: N, Q: Q, safeLine: safeLine, win: win,
             mode: ev.matchMode, tieSys: ev.tieSys, tieSys2: ev.tieSys2 };
}

/* « t assuré de finir dans le top Q en cas de victoire contre opp ? » */
function securedIfWin(a, t, opp, line) {
    if (line <= 0 || t.remaining === 0) return false;
    var pw = t.pts + a.win;
    var worst = 1;
    a.teams.forEach(function (o) {
        if (o === t) return;
        var oMax = (o === opp) ? o.maxPts - a.win : o.maxPts;
        if (oMax >= pw) worst++;
    });
    return worst <= line;
}

/* ── Enjeux d'un match du round en cours ─────────────────────────────────── */
function matchStakes(a, m) {
    var A = a.byId[m.a.id], B = a.byId[m.b.id];
    var out = [], imp = 0;
    if (!A || !B) return { labels: out, imp: 0 };

    if (A.qRace && B.qRace) { out.push({ t: 'Duel direct pour les ' + prefs.qlabel, hot: 1 }); imp += 3; }
    else if (A.qRace) { out.push({ t: A.name + ' joue sa place en ' + prefs.qlabel, hot: 1 }); imp += 2; }
    else if (B.qRace) { out.push({ t: B.name + ' joue sa place en ' + prefs.qlabel, hot: 1 }); imp += 2; }

    if (A.qRace && securedIfWin(a, A, B, a.Q)) { out.push({ t: A.name + ' qualifiée en cas de victoire' }); imp += 1; }
    if (B.qRace && securedIfWin(a, B, A, a.Q)) { out.push({ t: B.name + ' qualifiée en cas de victoire' }); imp += 1; }

    if (prefs.r > 0) {
        if (A.rRisk && B.rRisk) { out.push({ t: 'Duel direct pour le maintien', hot: 1 }); imp += 3; }
        else if (A.rRisk) { out.push({ t: A.name + ' joue son maintien', hot: 1 }); imp += 2; }
        else if (B.rRisk) { out.push({ t: B.name + ' joue son maintien', hot: 1 }); imp += 2; }
        if (A.rRisk && securedIfWin(a, A, B, a.safeLine)) { out.push({ t: A.name + ' sauvée en cas de victoire' }); imp += 1; }
        if (B.rRisk && securedIfWin(a, B, A, a.safeLine)) { out.push({ t: B.name + ' sauvée en cas de victoire' }); imp += 1; }
    }

    if ((A.canWin && !A.firstSecured) || (B.canWin && !B.firstSecured)) {
        out.push({ t: 'La 1re place de poule peut se jouer ici' }); imp += 1;
    }
    return { labels: out, imp: imp };
}

/* ── Rendu ───────────────────────────────────────────────────────────────── */
function badgeHtml(t) {
    var b = [];
    if (t.firstSecured) b.push('<span class="badge b-first">1RE DE POULE</span>');
    if (t.qSecured) b.push('<span class="badge b-q">QUALIFIÉE</span>');
    else if (t.qRace) b.push('<span class="badge b-run">EN COURSE</span>');
    if (t.rDoomed) b.push('<span class="badge b-rel">RELÉGUÉE</span>');
    else if (t.rRisk) b.push('<span class="badge b-thr">MENACÉE</span>');
    if (!b.length && t.qOut) b.push('<span class="badge b-out">HORS COURSE</span>');
    return b.join(' ');
}

/* libellé + valeur d'un critère de départage selon le système ianseo configuré */
function tieLabel(sys, mode) {
    if (sys === 1) return mode ? 'Sets G' : 'Volées G';
    if (sys === 2) return mode ? 'Sets +' : 'Volées +';
    if (sys === 3) return 'Score';
    if (sys === 5) return 'Diff';
    return 'TB';
}
function tieVal(sys, v) { return (sys === 5 && v > 0 ? '+' : '') + v; }

function renderStandings(a) {
    var h = '<table class="pl-std"><thead><tr>' +
        '<th></th><th style="text-align:left">Équipe</th><th>J</th><th>V</th><th>D</th>' +
        '<th>Pts</th>' +
        '<th title="1er critère de départage">' + tieLabel(a.tieSys, a.mode) + '</th>' +
        '<th title="2e critère de départage">' + tieLabel(a.tieSys2, a.mode) + '</th>' +
        '<th>Max</th><th>Peut finir</th><th></th>' +
        '</tr></thead><tbody>';
    a.teams.forEach(function (t) {
        var cls = [];
        if (a.Q > 0 && t.rank <= a.Q) cls.push('z-q');
        if (prefs.r > 0 && t.rank > a.safeLine) cls.push('z-r');
        if (a.Q > 0 && t.rank === a.Q) cls.push('sep-q');
        if (prefs.r > 0 && t.rank === a.safeLine + 1) cls.push('sep-r');
        var range = (t.best === t.worst) ? ord(t.best) : ord(t.best) + ' – ' + ord(t.worst);
        h += '<tr class="' + cls.join(' ') + '">' +
            '<td class="rg">' + t.rank + '</td>' +
            '<td class="nm">' + esc(t.name) + '</td>' +
            '<td>' + t.played + '</td><td>' + t.wins + '</td><td>' + t.losses + '</td>' +
            '<td class="pts">' + t.pts + '</td>' +
            '<td>' + tieVal(a.tieSys, t.tb) + '</td>' +
            '<td>' + tieVal(a.tieSys2, t.tb2) + '</td>' +
            '<td style="color:#7d8183">' + t.maxPts + '</td>' +
            '<td class="range">' + range + '</td>' +
            '<td style="text-align:left">' + badgeHtml(t) + '</td>' +
            '</tr>';
    });
    h += '</tbody></table>';
    document.getElementById('pl-standings').innerHTML = h;
}

function teamCell(a, m, side, cssSide) {
    var t = a.byId[m[side].id];
    var name = t ? t.name : '?';
    var sub = t ? ordTxt(t.rank) + ' · ' + t.pts + ' pts' : '';
    return '<div class="' + cssSide + '"><div class="tn">' + esc(name) + '</div>' +
           '<div class="tsub">' + esc(sub) + '</div></div>';
}

function matchHtml(a, m, withStakes) {
    var live = m.state === 'live';
    var done = m.state === 'done';
    // épreuve en sets : points de sets (le cumul de flèches n'a pas d'intérêt) ;
    // épreuve en cumul : score de points
    var sA = a.mode ? m.a.st : m.a.sc;
    var sB = a.mode ? m.b.st : m.b.sc;
    var mid;
    if (done) {
        var wa = m.a.wl ? 'win' : 'lose', wb = m.b.wl ? 'win' : 'lose';
        mid = '<span class="' + wa + '">' + sA + '</span> – <span class="' + wb + '">' + sB + '</span>';
    } else if (live) {
        mid = '<span class="pl-live-dot"></span>' + sA + ' – ' + sB;
    } else {
        mid = '<span class="vs">vs</span>';
    }
    var h = '<div class="pl-match' + (live ? ' live' : '') + '">' +
        '<div class="tgt">Cible<br><b>' + esc((m.tg || '').replace(/^0+/, '') || m.tg) + '</b></div>' +
        teamCell(a, m, 'a', 'tA') +
        '<div class="mid">' + mid + '</div>' +
        teamCell(a, m, 'b', 'tB');
    if (withStakes) {
        var s = matchStakes(a, m);
        var inner;
        if (!s.labels.length) inner = '<span class="e e-none">Sans enjeu direct au classement</span>';
        else inner = s.labels.map(function (l) {
            return '<span class="e' + (l.hot ? ' e-hot' : '') + '">' + (l.hot ? '🔥 ' : '') + esc(l.t) + '</span>';
        }).join('');
        h += '<div class="pl-enjeu">' + inner + '</div>';
        h += '</div>';
        return { html: h, imp: s.imp };
    }
    h += '</div>';
    return { html: h, imp: 0 };
}

function render() {
    if (!state || !state.events.length) {
        document.getElementById('pl-round').textContent = 'Aucune poule Round Robin par équipes dans cette compétition.';
        return;
    }
    // onglets
    var tabs = document.getElementById('pl-tabs');
    tabs.innerHTML = '';
    state.events.forEach(function (ev) {
        var b = document.createElement('button');
        b.className = 'pl-tab' + (ev.key === curKey ? ' cur' : '');
        b.textContent = ev.name;
        b.onclick = function () { curKey = ev.key; render(); };
        tabs.appendChild(b);
    });
    var ev = null;
    state.events.forEach(function (e) { if (e.key === curKey) ev = e; });
    if (!ev) { ev = state.events[0]; curKey = ev.key; render(); return; }

    var a = analyze(ev);

    // bandeau round
    var rh;
    if (!ev.currentRound) {
        rh = '<b>Poule terminée</b> <span class="fin">— classement final' +
             (a.Q > 0 ? ' · les ' + a.Q + ' premières en ' + esc(prefs.qlabel) : '') + '</span>';
    } else {
        var nexts = ev.matches.filter(function (m) { return m.r === ev.currentRound; });
        var time = nexts.length ? nexts[0].time : '';
        var liveCount = nexts.filter(function (m) { return m.state === 'live'; }).length;
        rh = '<b>Round ' + ev.currentRound + ' / ' + ev.totalRounds + '</b>' +
             (liveCount ? '<span><span class="pl-live-dot"></span>' + liveCount + ' match(s) en cours</span>'
                        : (time ? '<span>départ prévu à <b>' + esc(time) + '</b></span>' : '')) +
             (a.Q > 0 ? '<span class="fin">Les ' + a.Q + ' premières iront en ' + esc(prefs.qlabel) + '</span>' : '') +
             (prefs.r > 0 ? '<span class="fin">Les ' + prefs.r + ' dernières sont reléguées</span>' : '');
    }
    document.getElementById('pl-round').innerHTML = rh;

    renderStandings(a);

    // round en cours / prochain — trié par enjeu décroissant
    var nextBox = document.getElementById('pl-next');
    var title = document.getElementById('pl-next-title');
    var sortBtn = document.getElementById('pl-sort');
    if (ev.currentRound) {
        var cur = ev.matches.filter(function (m) { return m.r === ev.currentRound; });
        var anyLive = cur.some(function (m) { return m.state === 'live'; });
        title.textContent = (anyLive ? 'Round en cours' : 'Prochain round') +
            (prefs.sortTarget ? ' — par ordre de cibles' : ' — matchs classés par enjeu');
        sortBtn.style.display = '';
        sortBtn.textContent = prefs.sortTarget ? '⇄ Trier par enjeu' : '⇄ Trier par cible';
        var rendered = cur.map(function (m) { return { m: m, r: matchHtml(a, m, true) }; });
        if (prefs.sortTarget) {
            rendered.sort(function (x, y) {
                return (parseInt(x.m.tg, 10) || 0) - (parseInt(y.m.tg, 10) || 0);
            });
        } else {
            rendered.sort(function (x, y) { return y.r.imp - x.r.imp; });
        }
        nextBox.innerHTML = rendered.map(function (x) { return x.r.html; }).join('') ||
            '<div class="pl-empty">Aucun match.</div>';
    } else {
        sortBtn.style.display = 'none';
        title.textContent = 'Round en cours';
        nextBox.innerHTML = '<div class="pl-empty">Tous les matchs de poule sont terminés.</div>';
    }

    // derniers résultats : dernier round entièrement joué
    var lastRound = 0;
    for (var r = ev.totalRounds; r >= 1; r--) {
        var ms = ev.matches.filter(function (m) { return m.r === r; });
        if (ms.length && ms.every(function (m) { return m.state === 'done'; })) { lastRound = r; break; }
    }
    var lastBox = document.getElementById('pl-last');
    if (lastRound) {
        var ms2 = ev.matches.filter(function (m) { return m.r === lastRound; });
        lastBox.innerHTML = '<div class="pl-empty" style="padding:8px 12px 0">Round ' + lastRound + '</div>' +
            ms2.map(function (m) { return matchHtml(a, m, false).html; }).join('');
    } else {
        lastBox.innerHTML = '<div class="pl-empty">Aucun round terminé.</div>';
    }

    document.getElementById('pl-foot').textContent =
        'Mise à jour ' + state.now + ' · rafraîchissement automatique toutes les ' + prefs.refresh + ' s' +
        ' · « Max » = points encore atteignables · « Peut finir » = fourchette de classement final mathématiquement possible';
}

/* ── Chargement / cycle ──────────────────────────────────────────────────── */
function load() {
    fetch(ACTION, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { state = d; render(); })
        .catch(function () { /* on garde l'affichage précédent */ });
}
var refreshTimer = null, altTimer = null;
function arm() {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(load, Math.max(3, prefs.refresh) * 1000);
    if (altTimer) clearInterval(altTimer);
    if (prefs.alt) altTimer = setInterval(function () {
        if (!state || state.events.length < 2) return;
        var idx = state.events.findIndex(function (e) { return e.key === curKey; });
        curKey = state.events[(idx + 1) % state.events.length].key;
        render();
    }, 20000);
}

/* ── Réglages ────────────────────────────────────────────────────────────── */
var panel = document.getElementById('pl-prefs');
document.getElementById('pl-gear').onclick = function () { panel.classList.toggle('open'); };
document.getElementById('pl-sort').onclick = function () {
    prefs.sortTarget = !prefs.sortTarget;
    savePrefs(); render();
};
function bindPref(id, key, isNum, isBool) {
    var el = document.getElementById(id);
    if (isBool) el.checked = !!prefs[key]; else el.value = prefs[key];
    el.onchange = function () {
        prefs[key] = isBool ? el.checked : (isNum ? parseInt(el.value, 10) || 0 : el.value);
        savePrefs(); arm(); render();
    };
}
bindPref('pf-q', 'q', true);
bindPref('pf-qlabel', 'qlabel');
bindPref('pf-r', 'r', true);
bindPref('pf-refresh', 'refresh', true);
bindPref('pf-alt', 'alt', false, true);

setInterval(function () {
    document.getElementById('pl-clock').textContent = new Date().toLocaleTimeString('fr-FR');
}, 1000);

load();
arm();
})();
</script>
</body>
</html>
