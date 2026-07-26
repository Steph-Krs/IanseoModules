/**
 * assets/rep.js — éditeur de plan des départs.
 *
 * L'état vit côté serveur (table REP_Blocs) : chaque modification est postée puis
 * l'état complet est re-rendu à partir de la réponse. Le glisser-déposer fait un
 * aperçu local pour rester fluide, et ne confirme qu'au relâchement.
 */
(function () {
"use strict";

var CFG = window.REP_CFG || {};
var hote = document.getElementById("rep-editeur");
if (!hote) return;

var LET   = ["A", "B", "C", "D", "E", "F", "G", "H"];
var LH    = 25;
var SOURCES  = ["Classement national", "Classement national par club", "Par ordre de club manuel",
                 "Classement de l'arrêté (individuel)", "Par club selon l'arrêté individuel",
                 "Par club selon l'arrêté équipe", "Par club selon l'arrêté double mixte",
                 "Ordre alphabétique"];
var SOURCES_CLUB = [1, 2, 4, 5, 6];   // sources « par club » : répartition imposée par l'algorithme des couloirs
var ICONE = { ok: '<span style="color:#04ac0b">✓</span>',
              warn: '<span style="color:#cb8137">▲</span>',
              stop: '<span style="color:#a80000">✕</span>' };

var ETAT = null;                 // dernière réponse du serveur
// repli : état ouvert/fermé des <details class="rep-aide"> (aide, défauts des blocs), gardé
// côté JS parce que rendu() reconstruit tout le DOM à chaque action — sans ça, modifier un
// réglage du panneau « Défauts des blocs » le repliait aussitôt (l'action déclenche un
// re-rendu, un <details> recréé sans son attribut "open" repart toujours fermé).
var UI   = { zoom: {}, open: {}, sel: null, msg: null, apercu: null, occupe: false, repli: {} };

/* ── outils ──────────────────────────────────────────────────────────────── */
function ech(s) {
  return String(s === null || s === undefined ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
function poste(url, data) {
  data.jeton = CFG.jeton;
  return fetch(CFG.root + url, {
    method: "POST", credentials: "same-origin", body: new URLSearchParams(data)
  }).then(function (r) { return r.json(); });
}
/** Couleur stable par épreuve : deux épreuves voisines ne se ressemblent pas. */
function teinte(cle) {
  var h = 2166136261;
  for (var i = 0; i < cle.length; i++) { h ^= cle.charCodeAt(i); h = Math.imul(h, 16777619); }
  return (h >>> 0) % 360;
}
function styleEp(cle) {
  var t = teinte(cle);
  return "--ep:hsl(" + t + ",52%,40%);--epbg:hsl(" + t + ",58%,93%);--eptx:hsl(" + t + ",55%,28%)";
}
function places(b) { return (b.t2 - b.t1 + 1) * (b.l2 - b.l1 + 1); }
function departDe(s) {
  for (var i = 0; i < ETAT.departs.length; i++) if (ETAT.departs[i].ordre === s) return ETAT.departs[i];
  return null;
}
function blocDe(id) {
  for (var i = 0; i < ETAT.blocs.length; i++) if (ETAT.blocs[i].id === id) return ETAT.blocs[i];
  return null;
}
function libelleEp(cle) {
  var e = ETAT.epreuves[cle];
  return e ? e.libelle : cle.replace("|", " ");
}
function chevauche(ses, b, t1, t2, l1, l2) {
  return ETAT.blocs.some(function (o) {
    if (o.session !== ses || (b && o.id === b.id)) return false;
    return !(t2 < o.t1 || t1 > o.t2 || l2 < o.l1 || l1 > o.l2);
  });
}

/* ── communication ───────────────────────────────────────────────────────── */
function majEtat(r) {
  if (!r || r.ok === false) { erreur(r && r.err ? r.err : "Action refusée."); return false; }
  if (r.libere > 0)   UI.apercu = "<b>" + r.libere + " cible(s) libérée(s)</b> pour les archers de l'épreuve supprimée.";
  if (r.repartis > 0) UI.apercu = "<b>Répartition appliquée</b> à " + r.repartis + " bloc(s) : la dernière cible reçoit désormais deux archers.";
  if (r.brasses > 0)  UI.apercu = "<b>Brassage fédéral appliqué</b> à " + r.brasses + " bloc(s) : au plus deux archers d'un même club par cible.";
  if (r.defautsAppliques > 0) UI.apercu = "<b>Défauts appliqués</b> à " + r.defautsAppliques + " bloc(s) de la compétition.";
  ETAT = r;
  ETAT.departs.forEach(function (d) {
    if (UI.zoom[d.ordre] === undefined) UI.zoom[d.ordre] = "fit";
    if (UI.open[d.ordre] === undefined) UI.open[d.ordre] = (d.ordre === ETAT.departs[0].ordre);
  });
  rendu();
  return true;
}
function action(data) {
  if (UI.occupe) return;
  UI.occupe = true;
  poste("ajax/blocs.php", data)
    .then(function (r) { UI.occupe = false; majEtat(r); })
    .catch(function (e) { UI.occupe = false; erreur("Erreur réseau : " + e); });
}
function erreur(txt) {
  UI.msg = txt;
  rendu();
  clearTimeout(erreur._t);
  erreur._t = setTimeout(function () { UI.msg = null; rendu(); }, 5000);
}

/**
 * Suppression d'un bloc, depuis la croix du plan comme depuis le × du tableau.
 * Si c'est le dernier bloc de son épreuve et que des cibles ont déjà été attribuées
 * aux archers de cette épreuve, on demande s'il faut aussi les libérer.
 */
function supprimerBloc(id) {
  var b = blocDe(id);
  if (!b) return;
  var data = { action: "supprimer", id: id };
  var e = ETAT.epreuves[b.cle];
  var reste = ETAT.blocs.filter(function (x) { return x.cle === b.cle; }).length - 1;
  if (reste === 0 && e && e.affecte > 0) {
    if (window.confirm("Supprimer aussi les cibles déjà attribuées aux " + e.affecte
        + " archer(s) de l'épreuve " + e.libelle + " ?\n\n"
        + "OK = supprimer le bloc ET libérer leurs cibles dans ianseo.\n"
        + "Annuler = supprimer seulement le bloc, les cibles restent attribuées.")) {
      data.liberer = 1;
    }
  }
  action(data);
}

/* ── dessin du plan ──────────────────────────────────────────────────────── */
function dessine(d) {
  var wrap = hote.querySelector('.dep[data-i="' + d.ordre + '"] .plan-wrap');
  if (!wrap) return;
  var fit = UI.zoom[d.ordre] === "fit";
  var dispo = Math.max(320, wrap.clientWidth - 19);
  var cw = fit ? Math.max(7, Math.floor(dispo / d.cibles)) : 32;
  var W = cw * d.cibles, H = LH * d.ath;
  var ko = {};
  (ETAT.controles.blocsKo || []).forEach(function (id) { ko[id] = true; });

  var axe = "";
  for (var t = 0; t < d.cibles; t++) {
    var num = d.premiere + t;
    axe += '<span style="width:' + cw + 'px">' + (fit ? ((num % 5 === 0 || t === 0) ? num : "") : num) + '</span>';
  }
  var lignes = "";
  for (var i = 1; i < d.ath; i++) lignes += '<div class="lg" style="top:' + (i * LH - 1) + 'px"></div>';
  for (var k = 1; k < d.cibles; k++) {
    lignes += '<div class="cg' + ((d.premiere + k - 1) % 10 === 0 ? " dix" : "") + '" style="left:' + (k * cw) + 'px"></div>';
  }
  var lettres = "";
  for (var m = 0; m < d.ath; m++) lettres += '<b style="top:' + (m * LH) + 'px;height:' + LH + 'px">' + LET[m] + '</b>';

  var blocs = "";
  ETAT.blocs.filter(function (b) { return b.session === d.ordre; }).forEach(function (b) {
    var x = (b.t1 - d.premiere) * cw, y = b.l1 * LH;
    var w = (b.t2 - b.t1 + 1) * cw, h = (b.l2 - b.l1 + 1) * LH;
    var fl = (b.sl ? "↑" : "↓") + (b.sc ? "←" : "→");
    var lib = w > 26 ? libelleEp(b.cle) : "";
    var l1 = (w > 40) ? (lib ? lib + ' <span class="fl">' + fl + "</span>" : '<span class="fl">' + fl + "</span>") : lib;
    var rk = (w > 110 && h >= LH * 2)
      ? '<span class="rk">n°' + b.depuis + "→" + (b.depuis + places(b) - 1) + (b.br ? " · brassé" : "") + "</span>" : "";
    // Croix grises : cases de ce bloc où le placement actuel ne met aucun archer
    // (effectif insuffisant) — pour repérer les trous et réorganiser avant
    // d'appliquer, demandé par l'utilisateur. Masquées si les cases sont trop
    // étroites pour rester lisibles (vue « ajusté à la largeur » très zoomée).
    var vides = "";
    if (cw >= 8) {
      (b.vides || []).forEach(function (v) {
        var vx = (v.t - b.t1) * cw, vy = (v.l - b.l1) * LH;
        vides += '<span class="vide" style="left:' + vx + 'px;top:' + vy + 'px;width:' + cw + 'px;height:' + LH + 'px">&times;</span>';
      });
    }
    blocs += '<div class="bloc' + (UI.sel === b.id ? " sel" : "") + (ko[b.id] ? " conflit" : "") +
      '" data-id="' + b.id + '" style="' + styleEp(b.cle) + ";left:" + x + "px;top:" + y +
      "px;width:" + (w - 1) + "px;height:" + (h - 1) + 'px" title="' + ech(libelleEp(b.cle)) +
      " — lettres " + (b.sl ? LET[b.l2] + "→" + LET[b.l1] : LET[b.l1] + "→" + LET[b.l2]) +
      ", puis cibles " + (b.sc ? b.t2 + "→" + b.t1 : b.t1 + "→" + b.t2) + '">' + l1 + rk + vides +
      '<button class="bsuppr" data-suppr="' + b.id + '" title="Supprimer ce bloc" aria-label="Supprimer ce bloc">&times;</button>' +
      '<i class="n" data-m="n"></i><i class="s" data-m="s"></i><i class="w" data-m="w"></i><i class="e" data-m="e"></i>' +
      '<i class="nw" data-m="nw"></i><i class="ne" data-m="ne"></i><i class="sw" data-m="sw"></i><i class="se" data-m="se"></i></div>';
  });

  var plan = wrap.querySelector(".plan");
  plan.innerHTML =
    '<div class="axe" style="width:' + (W + 17) + 'px;padding-left:17px">' + axe + "</div>" +
    '<div class="corps" style="width:' + (W + 17) + "px;height:" + H + 'px;margin-left:17px">' +
    '<div class="lignes" style="width:' + W + 'px">' + lignes + "</div>" + blocs + "</div>" +
    '<div class="lettres" style="height:' + (H + 15) + 'px;top:15px">' + lettres + "</div>";
  plan.style.width = (W + 18) + "px";
  plan.dataset.cw = cw;
}

/* ── tableau des blocs ───────────────────────────────────────────────────── */
function opts(list, v) {
  return list.map(function (s, i) {
    return '<option value="' + i + '"' + (i === v ? " selected" : "") + ">" + ech(s) + "</option>";
  }).join("");
}
/**
 * Options de « Source de l'ordre » : toutes affichées, mais grisées (disabled)
 * quand aucun classement réel n'existe pour cette épreuve avec cette source —
 * pour ne plus jamais retomber en silence sur l'ordre alphabétique sans que ce
 * soit visible (bug réel : une épreuve sans correspondance d'arrêté retombait
 * en alphabétique sans aucun signal). $dispo vient de e.sourcesDispo
 * (ajax/blocs.php, rep_sources_dispo()) ; sans lui (épreuve introuvable), tout
 * reste activé plutôt que de bloquer l'interface.
 */
function optsSrc(v, dispo) {
  return SOURCES.map(function (s, i) {
    var ok = !dispo || dispo[i] !== false;
    var dis = ok ? "" : " disabled";
    var titre = ok ? "" : ' title="Aucun classement disponible pour cette épreuve avec cette source"';
    return '<option value="' + i + '"' + (i === v ? " selected" : "") + dis + titre + ">" + ech(s) + "</option>";
  }).join("");
}
function groupes(ses) {
  var ordre = [], paquets = {};
  ETAT.blocs.filter(function (b) { return b.session === ses; }).forEach(function (b) {
    if (!paquets[b.cle]) { paquets[b.cle] = []; ordre.push(b.cle); }
    paquets[b.cle].push(b);
  });
  var out = [];
  ordre.forEach(function (cle) {
    paquets[cle].sort(function (a, b) { return a.t1 - b.t1 || a.l1 - b.l1; });
    paquets[cle].forEach(function (b, i) {
      out.push({ b: b, premier: i === 0, rang: i + 1, total: paquets[cle].length });
    });
  });
  return out;
}
function aideListe(list) {
  return (list || []).map(function (x) { return "• " + x.libelle + (x.aide ? " : " + x.aide : ""); }).join("\n");
}
function brassageAide() {
  var reg = ETAT.regles || {}, mx = reg.max_club || 2;
  return "Brassage des clubs de ce bloc (facultatif, « aucun » par défaut) :\n\n"
       + "• —  aucun : les archers restent placés selon la source et le remplissage.\n"
       + "• Féd. : au plus " + mx + " archers d'un même club par cible (règle fédérale). "
       + "Le bouton « Brasser » des contrôles fait la même chose d'un coup sur les cibles en faute.\n"
       + "• Mél. : au plus 1 archer d'un même club par cible — mélange plus poussé, non obligatoire, "
       + "pour éviter que deux archers du même club tirent ensemble.\n\n"
       + "Le brassage échange seulement les cibles des archers DU BLOC ; il ne change pas les places "
       + "du classement utilisées.";
}

function tableau(d) {
  var ko = {};
  (ETAT.controles.blocsKo || []).forEach(function (id) { ko[id] = true; });

  var reg = ETAT.regles || {};
  var tSrc = "Ordre dans lequel les archers de l'épreuve sont pris :\n" + aideListe(reg.sources);
  var tRep = "Cibles et lettres occupées par ce bloc. Le sous-bloc du DESSUS se remplit entièrement "
           + "avant de passer au suivant de l'autre (priorité de répartition) — cliquer ⇅ pour échanger "
           + "leur ordre. Chaque flèche inverse le sens de remplissage de son propre axe (A→D ou D→A, "
           + "1→X ou X→1).";
  var tOptions = "Options du bloc :\n"
           + "• Mélange : au plus 1 archer d'un même club par cible — mélange poussé, non obligatoire. "
           + "La règle fédérale (au plus 2) se règle plutôt d'un coup via le bouton « Brasser » des "
           + "contrôles, sur les cibles qui en ont besoin.\n"
           + "• Serpentin : l'axe sans la priorité inverse son sens à chaque tour de l'autre.";

  var h = '<table class="regles"><thead><tr>' +
    '<th style="width:46px" title="+ ajoute un second bloc à la même épreuve · × supprime ce bloc"></th>' +
    '<th style="width:150px">Épreuve</th>' +
    '<th style="width:80px" title="Déplacer ce bloc vers un autre départ">Départ</th>' +
    '<th style="width:150px" title="' + ech(tRep) + '">Répartition</th>' +
    '<th style="width:150px" title="' + ech(tSrc) + '">Source de l\'ordre</th>' +
    '<th style="width:66px" title="Place du classement à partir de laquelle ce bloc sert les archers, et (en dessous, indicatif) la dernière place servie — calculée, non modifiable. Permet à deux blocs d\'une même épreuve de se partager l\'effectif sans doublon.">Depuis n°</th>' +
    '<th style="width:82px" title="' + ech(tOptions) + '">Options</th>' +
    '<th title="Compare l\'effectif de l\'épreuve au nombre de places de ses blocs">Effectif</th></tr></thead><tbody>';

  groupes(d.ordre).forEach(function (g) {
    var b = g.b, e = ETAT.epreuves[b.cle] || { nb: 0, nom: b.cle };
    var p = places(b);
    var totalEp = 0;
    ETAT.blocs.forEach(function (x) { if (x.cle === b.cle) totalEp += places(x); });
    var reste = e.nb - totalEp;
    // Plein sans place vide : rien à signaler ici, le "x / x" juste après suffit.
    var eff = reste === 0 ? ""
      : reste > 0 ? '<span class="pill p-vx">' + reste + " archer(s) sans place</span>"
                  : '<span class="pill p-nb">' + (-reste) + " place(s) vide(s)</span>";
    if (!e.classe) eff += ' <span class="pill p-ko" title="Sans classement, tous les archers sont traités comme non classés">sans classement</span>';
    eff += ' <span class="eff-nb" title="Archers de l\'épreuve / places de ses blocs">' + e.nb + " / " + totalEp + "</span>";

    // Sources par club : l'algorithme des couloirs impose ses propres cibles/lettres
    // (rep_axes(), pas rep_cellules_bloc()) — la priorité et le serpentin n'ont alors
    // aucun effet, comme l'ancien Parcours désactivé dans ce cas.
    var estClubSrc = SOURCES_CLUB.indexOf(b.src) !== -1;
    var repTitreClub = estClubSrc ? ' title="Sans effet : priorité imposée par l\'algorithme des couloirs (regroupement par club)"' : "";

    var subCible = '<div class="rep-sub" data-axe="cible"><span class="rep-sub-lbl">Cibles</span>' +
      '<input type="number" data-f="t1" value="' + b.t1 + '" min="' + d.premiere + '" max="' + (d.premiere + d.cibles - 1) + '">' +
      '<button type="button" class="fl-toggle" data-f="sc" data-v="' + b.sc +
      '" title="Cliquer pour inverser le sens de remplissage des cibles">' + (b.sc ? "←" : "→") + "</button>" +
      '<input type="number" data-f="t2" value="' + b.t2 + '" min="' + d.premiere + '" max="' + (d.premiere + d.cibles - 1) + '"></div>';
    var subLettre = '<div class="rep-sub" data-axe="lettre"><span class="rep-sub-lbl">Lettres</span>' +
      '<select data-f="l1">' + opts(LET.slice(0, d.ath), b.l1) + "</select>" +
      '<button type="button" class="fl-toggle" data-f="sl" data-v="' + b.sl +
      '" title="Cliquer pour inverser le sens de remplissage des lettres">' + (b.sl ? "←" : "→") + "</button>" +
      '<select data-f="l2">' + opts(LET.slice(0, d.ath), b.l2) + "</select></div>";
    var swapBtn = '<button type="button" class="rep-swap" data-swap-priorite' + (estClubSrc ? " disabled" : "") +
      repTitreClub + (estClubSrc ? "" : ' title="Échanger la priorité (le sous-bloc du dessus se remplit en entier avant l\'autre)"') +
      ">⇅</button>";
    var repartition = b.ciblePriorite ? (subCible + swapBtn + subLettre) : (subLettre + swapBtn + subCible);

    h += '<tr data-id="' + b.id + '" class="' + (g.premier ? "gr1 " : "") +
         (UI.sel === b.id ? "sel " : "") + (ko[b.id] ? "ko" : "") + '">' +
      '<td><button class="plus" data-add title="Ajouter un bloc à cette épreuve" aria-label="Ajouter un bloc">+</button> ' +
          '<button class="plus sup" data-del title="Supprimer ce bloc" aria-label="Supprimer ce bloc">×</button></td>' +
      "<td>" +
        '<span class="chip" style="' + styleEp(b.cle) + '" title="' + ech(e.nom) + '">' + ech(libelleEp(b.cle)) + "</span>" +
        (g.total > 1 ? ' <span class="suite">' + g.rang + "/" + g.total + "</span>" : "") +
        '<label class="inclnp-lbl" title="Inclure les archers de la même arme/sexe/catégorie qui ne participent pas ' +
        'à une épreuve individuelle : ils sont comptés dans l\'épreuve et placés comme les autres. Coche ce bloc ' +
        'redimensionne automatiquement pour s\'ajuster au nouvel effectif.">' +
          '<input type="checkbox" data-f="inclnp"' + (b.inclnp ? " checked" : "") + '> hors épr.</label>' +
      "</td>" +
      '<td><select data-f="session">' + ETAT.departs.map(function (x) {
            return '<option value="' + x.ordre + '"' + (x.ordre === b.session ? " selected" : "") +
                   ">Départ " + x.ordre + "</option>"; }).join("") + "</select></td>" +
      '<td class="cell-repartition">' + repartition + "</td>" +
      '<td><select data-f="src">' + optsSrc(b.src, e.sourcesDispo) + "</select>" +
          '<label class="src2-row" title="Second niveau de classement : pris pour tout archer que la ' +
          'source principale ne classe pas, avant de retomber sur l\'ordre alphabétique.">puis ' +
          '<select data-f="src2">' + optsSrc(b.src2, e.sourcesDispo) + "</select></label></td>" +
      '<td><input type="number" data-f="depuis" value="' + b.depuis + '" min="1">' +
          '<div class="depuis-jusqua">jusqu\'au n° ' + (b.depuis + p - 1) + "</div></td>" +
      '<td class="cell-options">' +
          '<label class="opt-chk"><input type="checkbox" data-f="br" data-checked-value="2"' +
          (b.br === 2 ? " checked" : "") + '> Mélange</label>' +
          (b.br === 1 ? '<span class="pill p-nb" title="' + ech(brassageAide()) + '">Féd. (contrôles)</span>' : "") +
          '<label class="opt-chk"><input type="checkbox" data-f="serpentin"' + (b.serpentin ? " checked" : "") +
          (estClubSrc ? " disabled" : "") + '> Serpentin</label>' +
      "</td>" +
      "<td>" + eff + "</td></tr>";
  });

  if (!groupes(d.ordre).length) {
    h += '<tr><td colspan="8" style="text-align:center;color:#7d8183;padding:10px">' +
         "Aucun bloc sur ce départ — ajoutez une épreuve avec la liste ci-dessus.</td></tr>";
  }
  return h + "</tbody></table>";
}

/* ── corps d'un départ ───────────────────────────────────────────────────── */
function corps(d) {
  var placees = {};
  ETAT.blocs.forEach(function (b) { placees[b.cle] = true; });
  var libres = Object.keys(ETAT.epreuves).filter(function (c) { return !placees[c]; });
  var ajout = libres.length
    ? '<select data-nouv><option value="">Ajouter une épreuve…</option>' +
      libres.map(function (c) {
        var e = ETAT.epreuves[c];
        return '<option value="' + ech(c) + '">' + ech(e.libelle + " — " + e.nom + " (" + e.nb + ")") + "</option>";
      }).join("") + "</select><button class=\"btn\" data-nouv-ok>Placer sur ce départ</button>"
    : '<span style="font-size:10px;color:#7d8183">Toutes les épreuves sont placées.</span>';

  return '<div class="outils">' +
    '<button class="btn" data-zoom aria-pressed="' + (UI.zoom[d.ordre] === "fit") + '">' +
      (UI.zoom[d.ordre] === "fit" ? "Ajusté à la largeur" : "Taille réelle") + "</button>" + ajout +
    '<span class="sep"></span>' +
    '<span style="font-size:10px;color:#7d8183"><b>↓ ↑</b> lettres, puis <b>→ ←</b> cibles</span></div>' +
    '<div class="plan-wrap"><div class="plan"></div></div>' + tableau(d);
}

function entete(d) {
  var occ = 0;
  ETAT.blocs.forEach(function (b) { if (b.session === d.ordre) occ += places(b); });
  var tot = d.cibles * d.ath, pct = tot ? Math.round(occ / tot * 100) : 0;
  var nb = ETAT.blocs.filter(function (b) { return b.session === d.ordre; }).length;
  return '<div class="dep-h" tabindex="0" role="button" aria-expanded="' + (!!UI.open[d.ordre]) + '">' +
    '<span class="caret">' + (UI.open[d.ordre] ? "▾" : "▸") + "</span>" +
    '<span class="nom">' + ech(d.nom) + "</span>" +
    '<span class="meta">' + d.cibles + " cibles × " + d.ath + " archers · " + nb + " bloc(s)</span>" +
    '<span class="jauge"><i style="width:' + pct + '%"></i></span>' +
    '<span class="meta">' + occ + " / " + tot + " places</span></div>";
}

/* ── panneau de contrôles ────────────────────────────────────────────────── */
function panneau() {
  var c = ETAT.controles;
  var h = '<div class="ctrl"><div class="ctrl-h">Contrôles avant affectation' +
    '<span class="note">recalculés à chaque modification</span><span class="sep"></span>' +
    (c.stop ? '<span class="pill p-ko">' + c.stop + " erreur(s) bloquante(s)</span>"
            : '<span class="pill p-ok">aucune erreur bloquante</span>') +
    (c.warn ? ' <span class="pill p-vx">' + c.warn + " avertissement(s)</span>" : "") +
    '</div><table class="chk"><tbody>';
  c.controles.forEach(function (x) {
    var extra = "";
    if (x.id === "seuls" && x.rebalancables > 0)
      extra = ' <button class="ctrl-inline" data-repartir title="Report vers la cible précédente">Répartir (' + x.rebalancables + ')</button>';
    else if (x.id === "reglement" && x.brassables > 0)
      extra = ' <button class="ctrl-inline" data-brasser title="Brassage fédéral : au plus 2 archers d\'un club par cible">Brasser (' + x.brassables + ')</button>';
    h += '<tr class="' + x.sev + '"><td class="ic">' + ICONE[x.sev] + '</td><td class="ti">' +
         ech(x.titre) + '</td><td class="nb">' + ech(x.nb) + '</td><td class="de">' + ech(x.detail) + extra + "</td></tr>";
  });
  h += "</tbody></table><div class=\"ctrl-f\">" +
    '<button class="btn" data-apercu>Aperçu de l\'attribution</button>' +
    '<button class="btn prim" data-appliquer' + (c.stop ? " disabled" : "") + ">Appliquer aux archers</button>" +
    '<span class="etat">' + (c.stop ? "Bloqué : corrigez les erreurs ci-dessus."
      : c.warn ? "Possible malgré " + c.warn + " avertissement — les cibles signalées resteront à corriger à la main."
               : "Prêt : " + c.total + " inscriptions seront placées.") + "</span>" +
    '<span class="sep" style="flex:1"></span>' +
    '<span class="etat">Portée : compétition en cours uniquement</span></div>' +
    '<div class="msg' + (UI.apercu ? " on ok" : "") + '" data-sortie style="margin:0 10px 10px">' +
    (UI.apercu || "") + "</div></div>";
  return h;
}

/* ── aide pour les nouveaux venus ────────────────────────────────────────── */
function aide() {
  var reg = ETAT.regles || {}, mx = reg.max_club || 2;
  var li = function (t) { return "<li>" + t + "</li>"; };
  return '<details class="rep-aide" data-repli="aide"' + (UI.repli.aide ? " open" : "") + '>'
    + '<summary>Comment ça marche&nbsp;?</summary>'
    + '<div class="rep-aide-corps"><ul>'
    + li("<b>Un bloc = une épreuve</b> posée sur une plage de cibles et de lettres. Glissez son "
        + "<b>centre</b> pour le déplacer, ses <b>bords</b> ou ses <b>coins</b> pour l'agrandir. "
        + "La <b>croix rouge</b> le supprime.")
    + li("L'attribution se lit <b>lettre d'abord</b> (↓ A→D ou ↑ D→A), <b>puis cible</b> (→ 1→X ou ← X→1) — "
        + "c'est l'ordre des deux flèches affichées sur le bloc.")
    + li("<b>Source de l'ordre</b> — dans quel ordre les archers sont pris : " + aideListe(reg.sources).replace(/\n/g, " ; ").replace(/•/g, "").trim()
        + " La ligne « puis » juste en dessous est le <b>second niveau</b> : pris pour tout archer que la source "
        + "principale ne classe pas, avant l'ordre alphabétique en dernier recours (ex. classement national pour "
        + "ordonner les archers hors sélection d'un classement d'arrêté).")
    + li("<b>Répartition</b> — les sous-blocs Cibles et Lettres empilés : celui du DESSUS se remplit "
        + "entièrement avant de passer au suivant de l'autre. Cliquez ⇅ pour échanger leur ordre (glisser "
        + "l'un au-dessus de l'autre), et chaque flèche pour inverser le sens de son propre axe.")
    + li("<b>Depuis n°</b> — la place du classement où ce bloc commence (le « jusqu'au n° » juste en "
        + "dessous est calculé, non modifiable). Si une épreuve a plusieurs blocs (bouton <b>+</b>), "
        + "chacun démarre après le précédent pour qu'aucun archer ne soit servi deux fois.")
    + li("<b>Options</b> (facultatives, par bloc) — <b>Mélange</b> évite même 2 archers d'un club sur "
        + "une cible (plus poussé que la règle fédérale des " + mx + " max, elle proposée d'un coup par le "
        + "bouton « Brasser » des contrôles) ; <b>Serpentin</b> inverse le sens de l'axe sans la priorité "
        + "à chaque tour de l'autre. Ni l'un ni l'autre ne change les places du classement utilisées.")
    + li("Rien n'est écrit tant que vous ne cliquez pas <b>« Appliquer aux archers »</b>. Le bouton "
        + "<b>« Aperçu »</b> montre le résultat sans rien enregistrer, et les <b>contrôles</b> en bas "
        + "bloquent l'écriture s'il y a une incohérence.")
    + '</ul><p class="rep-aide-note">Passez la souris sur les en-têtes du tableau pour le détail de chaque réglage.</p>'
    + '</div></details>';
}

/**
 * Défauts des blocs (1.9.0, demandé par l'utilisateur) : mêmes réglages
 * qu'un bloc (hors épr., sens + priorité cible/lettre, source + second
 * niveau, options), mais propres à la COMPÉTITION, pas à un bloc — jamais sa
 * position, son départ ni « depuis n° ». Préremplissent automatiquement tout
 * nouveau bloc (bouton <b>+</b>) et peuvent s'appliquer d'un coup à tous les
 * blocs déjà créés via le bouton dédié. Champs postés au fil de l'eau (comme
 * un bloc) via `data-df` (au lieu de `data-f`) — voir les listeners "change"
 * et "click" (data-df-swap, data-df-appliquer), qui n'exigent pas de <tr>
 * ancêtre contrairement à leurs équivalents par bloc.
 */
function panneauDefauts() {
  var def = ETAT.blocDefaut || { inclnp: 0, sc: 0, sl: 0, ciblePriorite: 1, src: 0, src2: 7, br: 0, serpentin: 0 };

  var subCible = '<div class="rep-sub" data-axe="cible"><span class="rep-sub-lbl">Cibles</span>' +
    '<button type="button" class="fl-toggle" data-df="sc" data-v="' + def.sc +
    '" title="Sens de remplissage des cibles par défaut">' + (def.sc ? "←" : "→") + "</button></div>";
  var subLettre = '<div class="rep-sub" data-axe="lettre"><span class="rep-sub-lbl">Lettres</span>' +
    '<button type="button" class="fl-toggle" data-df="sl" data-v="' + def.sl +
    '" title="Sens de remplissage des lettres par défaut">' + (def.sl ? "←" : "→") + "</button></div>";
  var swapBtn = '<button type="button" class="rep-swap" data-df-swap ' +
    'title="Échanger la priorité par défaut (le sous-bloc du dessus se remplit en entier avant l\'autre)">⇅</button>';
  var repartition = def.ciblePriorite ? (subCible + swapBtn + subLettre) : (subLettre + swapBtn + subCible);

  return '<details class="rep-aide" data-repli="defauts"' + (UI.repli.defauts ? " open" : "") + '>' +
    '<summary>Défauts des blocs</summary><div class="rep-aide-corps">' +
    '<p class="sous">Préremplit tout nouveau bloc (bouton <b>+</b> d\'une épreuve) et peut s\'appliquer d\'un ' +
    "coup à tous les blocs déjà créés, dans tous les départs — jamais leur position, leur départ ni « depuis n° ».</p>" +
    '<div class="defauts-grille">' +
      '<label class="inclnp-lbl" style="display:inline-block;font-size:10.5px">' +
        '<input type="checkbox" data-df="inclnp"' + (def.inclnp ? " checked" : "") + '> hors épr.</label>' +
      '<div class="cell-repartition">' + repartition + "</div>" +
      '<div><select data-df="src">' + optsSrc(def.src, null) + "</select>" +
        '<label class="src2-row">puis <select data-df="src2">' + optsSrc(def.src2, null) + "</select></label></div>" +
      '<div class="cell-options">' +
        '<label class="opt-chk"><input type="checkbox" data-df="br" data-checked-value="2"' +
        (def.br === 2 ? " checked" : "") + '> Mélange</label>' +
        '<label class="opt-chk"><input type="checkbox" data-df="serpentin"' + (def.serpentin ? " checked" : "") + '> Serpentin</label>' +
      "</div>" +
    "</div>" +
    '<div class="outils"><button class="btn prim" data-df-appliquer>Appliquer à tous les blocs existants</button></div>' +
    "</div></details>";
}

/* ── rendu complet ───────────────────────────────────────────────────────── */
function rendu() {
  if (!ETAT) return;
  var scroll = {};
  hote.querySelectorAll(".plan-wrap").forEach(function (w) {
    var d = w.closest(".dep"); if (d) scroll[d.dataset.i] = w.scrollLeft;
  });

  var nOuv = ETAT.departs.filter(function (d) { return UI.open[d.ordre]; }).length;
  var h = aide() + panneauDefauts() +
    '<div class="barre-g">' +
    '<button class="btn" data-tout="1">Tout déplier</button>' +
    '<button class="btn" data-tout="0">Tout replier</button>' +
    '<span style="font-size:10px;color:#7d8183">' + nOuv + " départ(s) ouvert(s) sur " + ETAT.departs.length + "</span>" +
    '<span class="sep"></span>' +
    '<a class="btn" href="classements.php">Classements</a>' +
    '<a class="btn" href="mapping.php">Correspondances</a>' +
    '<a class="btn" href="ordre-clubs.php">Ordre des clubs</a></div>';

  ETAT.departs.forEach(function (d) {
    h += '<div class="dep" data-i="' + d.ordre + '">' + entete(d) +
         '<div class="dep-b' + (UI.open[d.ordre] ? "" : " hid") + '">' +
         (UI.open[d.ordre] ? corps(d) : "") + "</div></div>";
  });
  h += '<div class="msg' + (UI.msg ? " on err" : "") + '" data-msg>' + ech(UI.msg || "") + "</div>";
  h += panneau();

  hote.innerHTML = h;
  ETAT.departs.forEach(function (d) { if (UI.open[d.ordre]) dessine(d); });
  hote.querySelectorAll(".plan-wrap").forEach(function (w) {
    var d = w.closest(".dep");
    if (d && scroll[d.dataset.i]) w.scrollLeft = scroll[d.dataset.i];
  });
}

/* ── interactions ────────────────────────────────────────────────────────── */
hote.addEventListener("click", function (e) {
  var tt = e.target.closest("[data-tout]");
  if (tt) {
    var v = tt.dataset.tout === "1";
    ETAT.departs.forEach(function (d) { UI.open[d.ordre] = v; });
    rendu(); return;
  }
  var th = e.target.closest(".dep-h");
  if (th) { var i = parseInt(th.parentNode.dataset.i, 10); UI.open[i] = !UI.open[i]; rendu(); return; }

  var z = e.target.closest("[data-zoom]");
  if (z) {
    var di = parseInt(z.closest(".dep").dataset.i, 10);
    UI.zoom[di] = UI.zoom[di] === "fit" ? "reel" : "fit";
    rendu(); return;
  }

  var nv = e.target.closest("[data-nouv-ok]");
  if (nv) {
    var carte = nv.closest(".dep"), sel = carte.querySelector("[data-nouv]");
    if (!sel || !sel.value) { erreur("Choisissez d'abord une épreuve dans la liste."); return; }
    action({ action: "ajouter", cle: sel.value, session: carte.dataset.i });
    return;
  }
  var add = e.target.closest("[data-add]");
  if (add) { action({ action: "dupliquer", id: add.closest("tr").dataset.id }); return; }

  var del = e.target.closest("[data-del]");
  if (del) { supprimerBloc(+del.closest("tr").dataset.id); return; }

  var sup = e.target.closest("[data-suppr]");
  if (sup) { supprimerBloc(+sup.dataset.suppr); return; }

  var flt = e.target.closest(".fl-toggle");
  if (flt) {
    var curF = parseInt(flt.dataset.v, 10) || 0;
    if (flt.dataset.df) {
      // Sens par défaut (panneau « Défauts des blocs », pas de <tr> ancêtre).
      var dataDef = { action: "defaut_modifier" };
      dataDef[flt.dataset.df] = curF ? 0 : 1;
      action(dataDef);
      return;
    }
    var trF = flt.closest("tr");
    if (!trF) return;
    var idF = parseInt(trF.dataset.id, 10);
    var fF = flt.dataset.f;
    var dataF = { action: "modifier", id: idF };
    dataF[fF] = curF ? 0 : 1;
    action(dataF);
    return;
  }

  // Échange la priorité cible/lettre (colonne « Répartition ») : déplace le
  // sous-bloc du dessous au-dessus de l'autre (et inversement) — un seul clic
  // suffit puisqu'il n'y a que deux positions possibles.
  var swp = e.target.closest("[data-swap-priorite]");
  if (swp) {
    var trSw = swp.closest("tr"), idSw = parseInt(trSw.dataset.id, 10), bSw = blocDe(idSw);
    if (bSw) action({ action: "modifier", id: idSw, ciblePriorite: bSw.ciblePriorite ? 0 : 1 });
    return;
  }

  // Même échange, mais pour le panneau « Défauts des blocs ».
  var swpDef = e.target.closest("[data-df-swap]");
  if (swpDef) {
    var defCour = ETAT.blocDefaut || {};
    action({ action: "defaut_modifier", ciblePriorite: defCour.ciblePriorite ? 0 : 1 });
    return;
  }

  var appDef = e.target.closest("[data-df-appliquer]");
  if (appDef) {
    if (!confirm("Appliquer ces valeurs par défaut à TOUS les blocs déjà créés dans cette compétition " +
        "(tous départs) ? Ça ne touche ni leur position, ni leur départ, ni « depuis n° ».")) return;
    action({ action: "defaut_appliquer" });
    return;
  }

  if (e.target.closest("[data-repartir]")) { action({ action: "repartir" }); return; }
  if (e.target.closest("[data-brasser]"))  { action({ action: "brasser" });  return; }

  if (e.target.closest("[data-apercu]")) { appliquer("apercu", false); return; }
  var ap = e.target.closest("[data-appliquer]");
  if (ap && !ap.disabled) { appliquer("ecrire", ETAT.controles.warn > 0); return; }

  var tr = e.target.closest("table.regles tbody tr");
  if (tr && tr.dataset.id && !e.target.closest("input,select,button")) {
    var id = parseInt(tr.dataset.id, 10);
    UI.sel = UI.sel === id ? null : id;
    rendu();
  }
});

// L'événement "toggle" ne bulle pas : écouteur en phase de CAPTURE sur hote (qui, contrairement
// aux <details> eux-mêmes, survit à rendu()) pour retenir l'état ouvert/fermé malgré la
// reconstruction complète du DOM à chaque action.
hote.addEventListener("toggle", function (e) {
  var cle = e.target && e.target.dataset && e.target.dataset.repli;
  if (cle) UI.repli[cle] = e.target.open;
}, true);

hote.addEventListener("keydown", function (e) {
  var th = e.target.closest(".dep-h"); if (!th) return;
  if (e.key === "Enter" || e.key === " ") {
    e.preventDefault();
    var i = parseInt(th.parentNode.dataset.i, 10);
    UI.open[i] = !UI.open[i]; rendu();
  }
});

hote.addEventListener("change", function (e) {
  // Valeurs par défaut (panneau « Défauts des blocs ») : mêmes champs qu'un
  // bloc, mais data-df (pas de <tr>/id, action différente).
  var fDef = e.target.dataset.df;
  if (fDef) {
    var dataDef = { action: "defaut_modifier" };
    if (e.target.type === "checkbox") {
      var valDef = e.target.dataset.checkedValue || "1";
      dataDef[fDef] = e.target.checked ? parseInt(valDef, 10) : 0;
    } else {
      dataDef[fDef] = parseInt(e.target.value, 10);
    }
    action(dataDef);
    return;
  }

  var f = e.target.dataset.f; if (!f) return;
  var tr = e.target.closest("tr"), id = parseInt(tr.dataset.id, 10), b = blocDe(id);
  if (!b) return;
  var data = { action: "modifier", id: id };
  if (e.target.type === "checkbox") {
    // data-checked-value : valeur envoyée à cocher (par défaut 1) — sert au
    // « Mélange » des options, qui doit envoyer 2 (CbBrassage), pas 1.
    var val = e.target.dataset.checkedValue || "1";
    data[f] = e.target.checked ? parseInt(val, 10) : 0;
  } else {
    data[f] = parseInt(e.target.value, 10);
  }
  action(data);
});

/* ── glisser-déposer et étirement ────────────────────────────────────────── */
var drag = null;
hote.addEventListener("pointerdown", function (e) {
  if (e.target.closest("[data-suppr]")) return;   // la croix n'amorce pas un déplacement
  var el = e.target.closest(".bloc"); if (!el) return;
  var plan = el.closest(".plan"), ses = parseInt(el.closest(".dep").dataset.i, 10);
  var b = blocDe(parseInt(el.dataset.id, 10)); if (!b) return;
  var d = departDe(ses); if (!d) return;
  drag = { el: el, plan: plan, d: d, b: b, mode: e.target.dataset.m || "move",
           cw: parseInt(plan.dataset.cw, 10) || 12,
           x0: e.clientX, y0: e.clientY,
           t1: b.t1, t2: b.t2, l1: b.l1, l2: b.l2, bouge: false };
  plan.classList.add("drag");
  el.setPointerCapture(e.pointerId);
  e.preventDefault();
});

hote.addEventListener("pointermove", function (e) {
  if (!drag) return;
  var dx = Math.round((e.clientX - drag.x0) / drag.cw);
  var dy = Math.round((e.clientY - drag.y0) / LH);
  if (dx || dy) drag.bouge = true;
  var d = drag.d, m = drag.mode, fin = d.premiere + d.cibles - 1;
  var n = { t1: drag.t1, t2: drag.t2, l1: drag.l1, l2: drag.l2 };
  if (m === "move") {
    var larg = drag.t2 - drag.t1, haut = drag.l2 - drag.l1;
    n.t1 = Math.min(Math.max(d.premiere, drag.t1 + dx), fin - larg); n.t2 = n.t1 + larg;
    n.l1 = Math.min(Math.max(0, drag.l1 + dy), d.ath - 1 - haut);    n.l2 = n.l1 + haut;
  } else {
    if (m.indexOf("e") >= 0) n.t2 = Math.min(Math.max(drag.t1, drag.t2 + dx), fin);
    if (m.indexOf("w") >= 0) n.t1 = Math.max(Math.min(drag.t2, drag.t1 + dx), d.premiere);
    if (m.indexOf("s") >= 0) n.l2 = Math.min(Math.max(drag.l1, drag.l2 + dy), d.ath - 1);
    if (m.indexOf("n") >= 0) n.l1 = Math.max(Math.min(drag.l2, drag.l1 + dy), 0);
  }
  var ko = chevauche(d.ordre, drag.b, n.t1, n.t2, n.l1, n.l2);
  drag.el.classList.toggle("ko", ko);
  if (!ko) {
    drag.b.t1 = n.t1; drag.b.t2 = n.t2; drag.b.l1 = n.l1; drag.b.l2 = n.l2;
    drag.el.style.left   = ((n.t1 - d.premiere) * drag.cw) + "px";
    drag.el.style.top    = (n.l1 * LH) + "px";
    drag.el.style.width  = ((n.t2 - n.t1 + 1) * drag.cw - 1) + "px";
    drag.el.style.height = ((n.l2 - n.l1 + 1) * LH - 1) + "px";
  }
});

hote.addEventListener("pointerup", function () {
  if (!drag) return;
  var d = drag; drag = null;
  d.plan.classList.remove("drag");
  d.el.classList.remove("ko");
  if (!d.bouge) { UI.sel = UI.sel === d.b.id ? null : d.b.id; rendu(); return; }
  if (d.b.t1 === d.t1 && d.b.t2 === d.t2 && d.b.l1 === d.l1 && d.b.l2 === d.l2) { rendu(); return; }
  action({ action: "modifier", id: d.b.id, t1: d.b.t1, t2: d.b.t2, l1: d.b.l1, l2: d.b.l2 });
});

hote.addEventListener("pointercancel", function () {
  if (!drag) return;
  var d = drag; drag = null;
  d.b.t1 = d.t1; d.b.t2 = d.t2; d.b.l1 = d.l1; d.b.l2 = d.l2;
  d.plan.classList.remove("drag");
  rendu();
});

/* ── aperçu et écriture ──────────────────────────────────────────────────── */
function appliquer(mode, forcer) {
  if (UI.occupe) return;
  if (mode === "ecrire") {
    var n = ETAT.controles.total;
    // Épreuves présentes dans le plan, et combien de leurs archers ont déjà une cible.
    var placees = {}, dejaAff = 0;
    ETAT.blocs.forEach(function (b) { placees[b.cle] = true; });
    Object.keys(placees).forEach(function (cle) {
      var e = ETAT.epreuves[cle];
      if (e) dejaAff += e.affecte || 0;
    });
    var txt = "Attribuer les cibles à " + n + " inscription(s) de cette compétition ?\n\n"
            + "Toutes les épreuves du plan sont réattribuées, pour que vos derniers changements "
            + "soient bien pris en compte.";
    if (dejaAff > 0) {
      txt += "\n\n" + dejaAff + " archer(s) de ces épreuves ont déjà une cible : elle sera "
           + "recalculée et réécrite selon le plan actuel.";
    }
    if (forcer) txt += "\n\nDes avertissements subsistent (règlement des clubs, places vides, archers seuls).";
    if (!window.confirm(txt)) return;
  }
  UI.occupe = true;
  UI.apercu = "Calcul en cours <span class=\"rep-dots\"><i></i><i></i><i></i></span>";
  rendu();
  poste("ajax/appliquer.php", { mode: mode, forcer: forcer ? 1 : 0, sessions: "" })
    .then(function (r) {
      UI.occupe = false;
      if (!r.ok && !r.lignes) { UI.apercu = "<b>" + ech(r.err) + "</b>"; rendu(); return; }
      var lignes = (r.lignes || []).slice(0, 400).map(function (l) {
        return "  " + l.cible + "  " + l.epreuve + "  " +
               (l.rang ? "n°" + l.rang : "n.c.") + "  " + l.nom + " (" + l.licence + ") " + l.club;
      }).join("\n");
      var tete = r.ecrit
        ? (r.ok ? "<b>Écriture effectuée</b> — " + r.verifie + " inscription(s) vérifiée(s) sur " + r.attendu + "."
                : "<b>Écriture incomplète</b> — " + ech(r.err))
        : "<b>Aperçu — rien n'a été écrit.</b> " + r.attendu + " inscription(s) seraient placées." +
          (r.err ? " " + ech(r.err) : "");
      UI.apercu = tete + "<pre>" + ech(lignes) +
        ((r.lignes || []).length > 400 ? "\n  … " + ((r.lignes.length) - 400) + " autres lignes" : "") + "</pre>";
      rendu();
      if (r.ecrit) action({ action: "etat" });
    })
    .catch(function (e) { UI.occupe = false; UI.apercu = "Erreur réseau : " + ech(e); rendu(); });
}

/* ── démarrage ───────────────────────────────────────────────────────────── */
hote.innerHTML = '<div style="padding:14px;color:#7d8183">Chargement du plan ' +
                 '<span class="rep-dots"><i></i><i></i><i></i></span></div>';
poste("ajax/blocs.php", { action: "etat" })
  .then(function (r) { if (!majEtat(r)) hote.innerHTML = '<div class="msg on err">' + ech(r.err || "Chargement impossible.") + "</div>"; })
  .catch(function (e) { hote.innerHTML = '<div class="msg on err">Erreur réseau : ' + ech(e) + "</div>"; });

var rt;
window.addEventListener("resize", function () {
  clearTimeout(rt);
  rt = setTimeout(function () {
    if (!ETAT) return;
    ETAT.departs.forEach(function (d) { if (UI.open[d.ordre]) dessine(d); });
  }, 140);
});
})();
