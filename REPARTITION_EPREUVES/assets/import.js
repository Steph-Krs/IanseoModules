/**
 * assets/import.js — écran 4 : import des arrêtés.
 *
 * L'état vit côté serveur (REP_ImpEtat, un JSON par compétition) : chaque action
 * poste puis réaffiche l'état complet renvoyé. Pas de framework, dans le même
 * esprit que rep.js.
 */
(function () {
"use strict";

var CFG = window.REP_IMPORT_CFG || {};
var hote = document.getElementById("imp-app");
if (!hote) return;

var ETAT = null;
var APERCU = null;   // dernier aperçu d'écriture (chargé à la demande)
var DIRECT = null;   // résultat du dernier import direct (détail des lignes manquantes/modifications)
var DM_APERCU = null;   // dernier aperçu de propagation double mixte (chargé à la demande)
var MODE_AUTO = null;   // dernier résultat du mode automatique
var OCCUPE = false;
var TRI = { champ: null, sens: 1 };   // tri courant de la liste consolidée
var FICHIER_ATTENTE = null;   // { file, convention } en attente de confirmation avant upload
// Cartes de vérification repliées par défaut (demandé par l'utilisateur, pour ne
// pas polluer la vue) — état conservé ici car rendre() reconstruit tout le DOM à
// chaque action, ce qui perdrait sinon l'ouverture/fermeture faite par l'utilisateur.
var REPLI = { consolide: true, classements: true, ecriture: true, dm: true };

/**
 * Pré-remplissage seulement — jamais appliqué sans confirmation visible dans le
 * sélecteur : la convention de classe (F/H ou M/W) ne se lit nulle part dans le
 * contenu du fichier, seulement (parfois) dans son nom.
 */
function conventionDepuisNom(nom) {
  var n = (nom || "").toUpperCase();
  if (n.indexOf("INTER") !== -1) return "FH";
  if (n.indexOf("NATIONAL") !== -1) return "MW";
  return "FH";
}

function ech(s) {
  return String(s === null || s === undefined ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
// L'import direct (mode « ecrire-direct ») réexécute Partecipants/ListLoad.php
// côté serveur ; si ce script natif termine anormalement (ACL, session), la
// réponse peut ne pas être un JSON exploitable — sans ce filet, le bouton
// resterait bloqué sans aucun message.
function echecReseau() {
  occupe(false);
  var m = document.getElementById("imp-ecriture-msg") || document.getElementById("imp-msg-upload");
  if (m) m.innerHTML = '<div class="msg err on">Réponse du serveur inattendue (l\'action a peut-être échoué en cours de route). Recharge la page pour vérifier l\'état réel avant de recommencer.</div>';
  return { ok: false, err: "réponse invalide" };
}
function poste(data) {
  data.jeton = CFG.jeton;
  return fetch(CFG.root + "ajax/import.php", {
    method: "POST", credentials: "same-origin", body: new URLSearchParams(data)
  }).then(function (r) { return r.json(); }).catch(echecReseau);
}
function posteFichier(fd) {
  fd.append("jeton", CFG.jeton);
  return fetch(CFG.root + "ajax/import.php", {
    method: "POST", credentials: "same-origin", body: fd
  }).then(function (r) { return r.json(); }).catch(echecReseau);
}
function occupe(on) {
  OCCUPE = on;
  var boutons = hote.querySelectorAll("button, input[type=file]");
  for (var i = 0; i < boutons.length; i++) boutons[i].disabled = on;
}

function charger() {
  occupe(true);
  poste({ action: "etat" }).then(function (r) {
    occupe(false);
    if (!r.ok) { hote.innerHTML = '<div class="msg err on">' + ech(r.err || "Erreur de chargement.") + "</div>"; return; }
    ETAT = r;
    rendre();
  });
}

/* ── rendu ───────────────────────────────────────────────────────────────── */
function rendre() {
  hote.innerHTML =
    carteFichiers() +
    carteAutomatique() +
    carteConsolide() +
    carteClassements() +
    carteEcriture() +
    carteDoubleMixte();
  brancher();
}

/** Enveloppe repliable commune aux 4 cartes de vérification (voir REPLI). */
function carteRepliable(cle, titre, corps) {
  return '<details class="carte" data-repli="' + cle + '"' + (REPLI[cle] ? "" : " open") + ">" +
    "<summary>" + titre + "</summary>" +
    '<div class="corps">' + corps + "</div></details>";
}

function carteFichiers() {
  var lignes = "";
  (ETAT.fichiers || []).forEach(function (f) {
    lignes += '<div class="imp-row-fichier">' +
      '<span class="imp-badge ' + (f.type === "equipe" ? "coach" : "archer") + '">' + ech(f.sous || f.type) + "</span>" +
      "<b>" + ech(f.nom) + "</b><span>" + f.nb + " ligne(s)</span>" +
      '<span class="pill p-nb" title="Convention de suffixe de classe utilisée pour ce fichier">' +
      (f.convention === "MW" ? "M/W" : "F/H") + "</span>" +
      '<span class="sep"></span>' +
      '<button class="btn" data-act="suppr-fichier" data-id="' + f.id + '">Supprimer</button>' +
      "</div>";
  });
  if (!lignes) lignes = '<p class="sous">Aucun fichier déposé pour l\'instant.</p>';

  var zone;
  if (FICHIER_ATTENTE) {
    zone = '<div class="imp-row-fichier">' +
      '<b>' + ech(FICHIER_ATTENTE.file.name) + '</b>' +
      '<label class="imp-opt">Convention de classe : <select id="imp-attente-convention">' +
      '<option value="FH"' + (FICHIER_ATTENTE.convention === "FH" ? " selected" : "") + '>F/H (Femme/Homme)</option>' +
      '<option value="MW"' + (FICHIER_ATTENTE.convention === "MW" ? " selected" : "") + ">M/W (Men/Women)</option>" +
      "</select></label>" +
      '<span class="sep"></span>' +
      '<button class="btn prim" data-act="confirmer-upload">Importer ce fichier</button>' +
      '<button class="btn" data-act="annuler-upload">Annuler</button>' +
      "</div>" +
      '<p class="sous">La convention (F/H ou M/W) ne se lit pas dans le fichier, seulement — parfois — ' +
      "dans son nom : vérifiez le choix ci-dessus avant de confirmer (International = F/H, National = M/W).</p>";
  } else {
    zone = '<label class="imp-drop" id="imp-drop">' +
      '<div><b>Déposer un fichier</b> (.xlsx ou .csv) ou cliquer ici — sélections individuelles ' +
      "ou dépôts d'équipe, le format est détecté automatiquement.</div>" +
      '<input type="file" id="imp-file" accept=".xlsx,.csv,.txt">' +
      "</label>";
  }

  return '<div class="carte"><h2>Fichiers de l\'arrêté<span class="sep"></span>' +
    '<button class="btn" data-act="reinit">Réinitialiser l\'assistant</button></h2>' +
    '<div class="corps">' +
    zone +
    '<div id="imp-msg-upload"></div>' +
    lignes +
    "</div></div>";
}

function carteAutomatique() {
  var corps = '<p class="sous">Enchaîne en un clic : construction des classements (avec association ' +
    "automatique aux épreuves), import direct des inscriptions dans ianseo, puis propagation du drapeau " +
    "double mixte à tous les archers éligibles des clubs concernés. S'arrête avant toute écriture s'il " +
    "reste un conflit non résolu entre fichiers (division, classe ou club) — nom, prénom et sexe ne " +
    "bloquent jamais, la première valeur trouvée suffit.</p>";
  var disabled = (!ETAT.fichiers || !ETAT.fichiers.length) ? " disabled" : "";
  corps += '<div class="outils"><button class="btn prim" data-act="mode-auto"' + disabled + '>' +
    "Tout faire automatiquement</button></div>" +
    '<div id="imp-auto-msg"></div>';
  return '<div class="carte"><h2>Mode automatique</h2><div class="corps">' + corps + "</div></div>";
}

function champCellule(c, champ) {
  if (c.conflit && c.candidats && c.candidats[champ]) {
    var cands = Object.keys(c.candidats[champ]);
    var out = '<div class="imp-candidats">';
    cands.forEach(function (v) {
      var sel = (c[champ] === v) ? " sel" : "";
      out += '<span class="imp-cand' + sel + '" data-act="resoudre" data-lic="' + ech(c.licence) +
        '" data-role="' + ech(c.role) + '" data-champ="' + champ + '" data-val="' + ech(v) + '" ' +
        'title="' + ech(c.candidats[champ][v].join(", ")) + '">' + ech(v || "(vide)") + "</span>";
    });
    return out + "</div>";
  }
  return ech(c[champ]);
}

/** Valeur comparable pour le tri (booléens → 0/1, texte insensible à la casse/accents). */
function valeurTri(c, champ) {
  if (champ === "etat") return c.conflit ? 2 : (c.incomplet ? 1 : 0);
  var v = c[champ];
  if (typeof v === "boolean") return v ? 1 : 0;
  return v === null || v === undefined ? "" : v;
}
function comparerTri(a, b, champ) {
  var va = valeurTri(a, champ), vb = valeurTri(b, champ);
  if (typeof va === "number" && typeof vb === "number") return va - vb;
  return String(va).localeCompare(String(vb), "fr", { sensitivity: "base" });
}
/** En-tête cliquable : trie sur ce champ, inverse le sens si déjà actif. */
function th(label, champ, classe) {
  var actif = TRI.champ === champ;
  var fleche = actif ? (TRI.sens === 1 ? " ▲" : " ▼") : "";
  return '<th class="imp-th' + (classe ? " " + classe : "") + '" data-sort="' + champ + '">' + label + fleche + "</th>";
}

function carteConsolide() {
  var lignes = (ETAT.consolide || []).slice();
  if (TRI.champ) lignes.sort(function (a, b) { return TRI.sens * comparerTri(a, b, TRI.champ); });

  var corps = "";
  if (!lignes.length) {
    corps = '<p class="sous">Aucune ligne pour l\'instant — dépose un fichier ci-dessus.</p>';
  } else {
    corps = '<div style="overflow-x:auto"><table class="g"><thead><tr>' +
      th("Rôle", "role") + th("Licence", "licence", "lft") + th("Nom", "nom", "lft") + th("Prénom", "prenom", "lft") +
      th("Sexe", "sexe") + th("Division", "division") + th("Classe", "class") + th("Club", "clubnom", "lft") +
      th("Para", "para") +
      th("Indiv.", "indiv") + th("Équipe", "equipe") + th("D.Mixte", "doublemixte") + th("Étr.", "etranger") +
      '<th class="lft">Sources</th>' + th("État", "etat") +
      "</tr></thead><tbody>";
    lignes.forEach(function (c) {
      var cls = c.conflit ? "imp-conflit" : (c.incomplet ? "imp-incomplet" : "");
      var etat = c.conflit ? '<span class="pill p-vx">conflit</span>'
               : c.incomplet ? '<span class="pill p-ko">incomplet</span>'
               : '<span class="pill p-ok">ok</span>';
      var nomTitre = c.nom_devine ? ' <span class="imp-devine" title="Nom/prénom déduits automatiquement d\'un champ fusionné — à vérifier">⚠</span>' : "";
      var para = c.para ? '<span class="pill p-nb" title="' + ech(c.para) + '">Para</span>' : "";
      corps += '<tr class="' + cls + '">' +
        '<td><span class="imp-badge ' + (c.role === "coach" ? "coach" : "archer") + '">' + (c.role === "coach" ? "Coach" : "Archer") + "</span></td>" +
        '<td class="lft">' + ech(c.licence) + "</td>" +
        '<td class="lft nom">' + champCellule(c, "nom", "Nom") + nomTitre + "</td>" +
        '<td class="lft nom">' + champCellule(c, "prenom", "Prénom") + "</td>" +
        "<td>" + champCellule(c, "sexe") + "</td>" +
        "<td>" + champCellule(c, "division") + "</td>" +
        "<td>" + champCellule(c, "class") + "</td>" +
        '<td class="lft">' + champCellule(c, "clubnom") + "</td>" +
        "<td>" + para + "</td>" +
        "<td>" + (c.indiv ? "✓" : "") + "</td>" +
        "<td>" + (c.equipe ? "✓" : "") + "</td>" +
        "<td>" + (c.doublemixte ? "✓" : "") + "</td>" +
        "<td>" + (c.etranger ? ech(c.etranger) : "") + "</td>" +
        '<td class="lft">' + ech((c.sources || []).join(", ")) + "</td>" +
        "<td>" + etat + "</td>" +
        "</tr>";
    });
    corps += "</tbody></table></div>";
  }

  var barre = "";
  if (ETAT.nbConflits > 0) {
    barre = '<div class="outils"><span class="sous">' + ETAT.nbConflits + " ligne(s) en conflit.</span>" +
      '<span class="sep"></span><button class="btn prim" data-act="resoudre-tout">' +
      "Valider tous les conflits sur la valeur la plus fréquente</button></div>";
  }

  return carteRepliable("consolide",
    "Archers et coachs consolidés<span class=\"sub\">" + lignes.length + " ligne(s)</span>", barre + corps);
}

function carteClassements() {
  var cls = ETAT.classements || [];
  var listeCls = "";
  if (cls.length) {
    listeCls = cls.map(function (c) {
      return '<div class="imp-clsligne"><span class="pill ' + (c.type === "E" ? "p-nb" : "p-ok") + '">' +
        (c.type === "E" ? "Équipe" : "Individuel") + "</span><b>" + ech(c.libelle) + "</b>" +
        '<span class="sep"></span><span class="sous">' + c.nb + " ligne(s)</span></div>";
    }).join("");
  } else {
    listeCls = '<p class="sous">Aucun classement d\'arrêté construit pour l\'instant.</p>';
  }

  var indCls = cls.filter(function (c) { return c.type === "I"; });
  var eqCls  = cls.filter(function (c) { return c.type === "E"; });
  function option(c, selectionne) {
    return '<option value="' + c.id + '"' + (c.id === selectionne ? " selected" : "") + ">" + ech(c.libelle) + "</option>";
  }

  var lignesEpr = (ETAT.epreuves || []).map(function (e) {
    var suggestion = e.suggere
      ? ' <span class="pill p-vx" title="Correspondance automatique par division/catégorie/sexe — pas encore confirmée, changez-la si besoin">suggéré</span>'
      : "";
    return '<tr><td class="lft">' + ech(e.nom) + "</td><td>" + ech(e.division) + "</td><td>" + ech(e.sexe) + "</td>" +
      '<td><select data-act="associer" data-event="' + ech(e.cle) + '">' +
      '<option value="0"' + (e.classement === 0 ? " selected" : "") + ">— aucun —</option>" +
      '<optgroup label="Individuel">' + indCls.map(function (c) { return option(c, e.classement); }).join("") + "</optgroup>" +
      '<optgroup label="Équipe">' + eqCls.map(function (c) { return option(c, e.classement); }).join("") + "</optgroup>" +
      "</select>" + suggestion + "</td></tr>";
  }).join("");

  var tableEpr = ETAT.epreuves && ETAT.epreuves.length
    ? '<table class="g" style="margin-top:9px"><thead><tr><th class="lft">Épreuve</th><th>Division</th>' +
      "<th>Sexe</th><th>Classement associé</th></tr></thead><tbody>" + lignesEpr + "</tbody></table>"
    : "";

  var boutonReinit = cls.length
    ? '<button class="btn" data-act="reinit-classements">Réinitialiser les classements</button>'
    : "";

  var corps = '<div class="outils"><button class="btn prim" data-act="construire-classements">' +
    "Construire / actualiser les classements</button>" + boutonReinit + "</div>" +
    '<div id="imp-cls-msg"></div>' +
    listeCls + tableEpr;
  return carteRepliable("classements", "Classements dérivés de l'arrêté", corps);
}

function carteEcriture() {
  var etatOf = ETAT.ofCoaOk
    ? '<span class="pill p-ok">OF / COA configurés</span>'
    : '<span class="pill p-vx">OF / COA absents — les coachs seront ignorés</span>';
  var etatAcl = ETAT.directPossible
    ? '<span class="pill p-ok">import direct disponible</span>'
    : '<span class="pill p-nb">import direct indisponible (droits Participants requis)</span>';

  var corpsApercu = "";
  if (APERCU) {
    corpsApercu = '<p class="sous">' + APERCU.pretes.length + " ligne(s) prête(s), " +
      APERCU.ignorees.length + " ignorée(s).</p>";
    if (APERCU.ignorees.length) {
      corpsApercu += '<table class="g"><thead><tr><th class="lft">Licence</th><th class="lft">Nom</th>' +
        '<th class="lft">Motif</th></tr></thead><tbody>' +
        APERCU.ignorees.map(function (i) {
          return '<tr><td class="lft">' + ech(i.ligne.licence) + '</td><td class="lft">' +
            ech(i.ligne.nom + " " + i.ligne.prenom) + '</td><td class="lft imp-motif">' + ech(i.motif) + "</td></tr>";
        }).join("") + "</tbody></table>";
    }
  }

  var corpsDirect = "";
  if (DIRECT && DIRECT.manquantes && DIRECT.manquantes.length) {
    corpsDirect += '<p class="sous"><b>' + DIRECT.manquantes.length + " ligne(s) introuvable(s) après l'import " +
      "direct</b> (probablement refusées par ianseo — vérifier la classe d'âge de l'archer dans cette " +
      "division, ou son statut auprès de la fédération) :</p>" +
      '<table class="g"><thead><tr><th class="lft">Rôle</th><th class="lft">Licence</th><th class="lft">Nom</th>' +
      '<th>Division</th><th>Classe</th></tr></thead><tbody>' +
      DIRECT.manquantes.map(function (m) {
        return '<tr><td class="lft">' + ech(m.role) + '</td><td class="lft">' + ech(m.licence) + '</td>' +
          '<td class="lft">' + ech(m.nom) + "</td><td>" + ech(m.division) + "</td><td>" + ech(m.class) + "</td></tr>";
      }).join("") + "</tbody></table>";
  }
  if (DIRECT && DIRECT.modifications && DIRECT.modifications.length) {
    corpsDirect += '<p class="sous"><b>' + DIRECT.modifications.length + " inscription(s) modifiée(s) par ianseo " +
      "à l'import</b> (classe et/ou sexe recalculés depuis sa base fédérale — souvent le signe d'un mauvais " +
      "suffixe de classe soumis, F/H au lieu de M/W ou l'inverse) :</p>" +
      '<table class="g"><thead><tr><th class="lft">Licence</th><th class="lft">Nom</th><th>Division</th>' +
      "<th>Classe soumise</th><th>Classe ianseo</th><th>Sexe soumis</th><th>Sexe ianseo</th></tr></thead><tbody>" +
      DIRECT.modifications.map(function (m) {
        var clsCls = m.class_soumise !== m.class_ianseo ? ' style="color:var(--ko-tx);font-weight:700"' : "";
        var clsSexe = m.sexe_soumis !== m.sexe_ianseo ? ' style="color:var(--ko-tx);font-weight:700"' : "";
        return '<tr><td class="lft">' + ech(m.licence) + '</td><td class="lft">' + ech(m.nom) + "</td>" +
          "<td>" + ech(m.division) + "</td><td" + clsCls + ">" + ech(m.class_soumise) + "</td>" +
          "<td" + clsCls + ">" + ech(m.class_ianseo) + "</td>" +
          "<td" + clsSexe + ">" + ech(m.sexe_soumis) + "</td><td" + clsSexe + ">" + ech(m.sexe_ianseo) + "</td></tr>";
      }).join("") + "</tbody></table>";
  }

  var corps = '<div class="outils">' + etatOf + etatAcl + "</div>" +
    '<div class="outils"><button class="btn" data-act="apercu">Aperçu de l\'écriture</button></div>' +
    '<div class="outils">' +
    '<label class="imp-opt"><input type="checkbox" id="imp-opt-entete"> Ligne d\'en-tête</label>' +
    '<label class="imp-opt"><input type="checkbox" id="imp-opt-bom"> Compatible Excel (BOM)</label>' +
    '<button class="btn" data-act="telecharger">Télécharger le fichier</button>' +
    '<span class="sep"></span>' +
    '<button class="btn prim" data-act="ecrire-direct"' + (ETAT.directPossible ? "" : " disabled") + '>' +
    "Importer directement dans ianseo</button></div>" +
    '<p class="sous">Le fichier reprend les 10 colonnes du format d\'import ianseo, plus code club et ' +
    "nom du club en colonnes 11 et 12 (à retirer avant réimport dans l'écran natif ianseo — mêmes " +
    "conventions que le module EXPORT_LISTE). L'import direct, lui, écrit ces informations aux bonnes " +
    "colonnes automatiquement.</p>" +
    '<div id="imp-ecriture-msg"></div>' + corpsDirect + corpsApercu;
  return carteRepliable("ecriture", "Écriture dans ianseo", corps);
}

function carteDoubleMixte() {
  var clubs = DM_APERCU;
  var corps;
  if (!clubs) {
    corps = '<div class="outils"><button class="btn prim" data-act="dm-apercu">Vérifier les clubs double mixte</button></div>' +
      '<p class="sous">Repère, à partir des dépôts double mixte déjà importés, tous les clubs qui ont une ' +
      "équipe DM, puis propose de cocher le drapeau double mixte pour TOUS les archers du club dont la " +
      "classe/division est acceptée par l'épreuve double mixte ianseo liée — pas seulement les 2 archers " +
      "nommés dans le dépôt : la composition définitive se choisit plus tard dans ianseo, parmi tous les " +
      "éligibles du club, pas seulement ces deux-là.</p>";
  } else if (!clubs.length) {
    corps = '<div class="outils"><button class="btn" data-act="dm-apercu">Revérifier</button></div>' +
      '<p class="sous">Aucun club à traiter (aucun dépôt double mixte importé, ou aucune épreuve double ' +
      "mixte ianseo ne correspond à leur division).</p>";
  } else {
    var lignes = "";
    for (var i = 0; i < clubs.length; i++) {
      var c = clubs[i];
      lignes += '<tr class="imp-dm-club"><td colspan="4"><b>' + ech(c.clubnom) + "</b> (" + ech(c.clubcode) +
        ") — " + ech(c.division) + " — classes acceptées par l'épreuve double mixte : " +
        ech(c.classes.join(", ")) + "</td></tr>";
      for (var j = 0; j < c.archers.length; j++) {
        var a = c.archers[j];
        var checked = a.dejaDm ? "" : " checked";
        var etat = a.dejaDm ? '<span class="pill p-ok">déjà DM</span>' : '<span class="pill p-vx">à activer</span>';
        lignes += "<tr><td><input type=\"checkbox\" class=\"dm-chk\" data-enid=\"" + a.enid + "\"" + checked + "></td>" +
          '<td class="lft">' + ech(a.licence) + '</td><td class="lft">' + ech(a.nom) + " (" + ech(a.class) + ")</td>" +
          "<td>" + etat + "</td></tr>";
      }
    }
    corps = '<div class="outils"><button class="btn" data-act="dm-apercu">Revérifier</button>' +
      '<span class="sep"></span><button class="btn prim" data-act="dm-appliquer">Appliquer la sélection</button></div>' +
      '<div style="overflow-x:auto"><table class="g"><thead><tr><th></th><th class="lft">Licence</th>' +
      "<th class=\"lft\">Archer (classe)</th><th>État</th></tr></thead><tbody>" + lignes + "</tbody></table></div>" +
      '<div id="imp-dm-msg"></div>';
  }
  return carteRepliable("dm", "Double mixte — propagation par club", corps);
}

/* ── actions ─────────────────────────────────────────────────────────────── */
function brancher() {
  var drop = document.getElementById("imp-drop");
  var file = document.getElementById("imp-file");
  if (drop && file) {
    drop.addEventListener("dragover", function (e) { e.preventDefault(); drop.classList.add("over"); });
    drop.addEventListener("dragleave", function () { drop.classList.remove("over"); });
    drop.addEventListener("drop", function (e) {
      e.preventDefault(); drop.classList.remove("over");
      if (e.dataTransfer.files.length) mettreEnAttente(e.dataTransfer.files[0]);
    });
    file.addEventListener("change", function () {
      if (file.files.length) mettreEnAttente(file.files[0]);
    });
  }

  hote.addEventListener("click", onClic);
  hote.addEventListener("click", onClicTri);
  hote.addEventListener("change", onChange);
  // "toggle" (<details>) ne bulle pas : écoute en phase de capture pour
  // l'intercepter malgré tout depuis un parent stable (hote survit aux
  // re-rendus, contrairement aux <details> eux-mêmes).
  hote.addEventListener("toggle", onToggleDetails, true);
}

/** Mémorise l'ouverture/fermeture d'une carte repliable (voir REPLI, carteRepliable()). */
function onToggleDetails(e) {
  var d = e.target;
  if (!d || d.tagName !== "DETAILS") return;
  var cle = d.getAttribute("data-repli");
  if (cle) REPLI[cle] = !d.open;
}

function onClicTri(e) {
  var th = e.target.closest("[data-sort]");
  if (!th) return;
  var champ = th.getAttribute("data-sort");
  if (TRI.champ === champ) TRI.sens = -TRI.sens; else { TRI.champ = champ; TRI.sens = 1; }
  rendre();
}

function onChange(e) {
  var sel = e.target.closest("select[data-act='associer']");
  if (sel) {
    poste({ action: "associer_classement", event: sel.getAttribute("data-event"), classement: sel.value })
      .then(function (r) { if (r.ok) { ETAT = r; rendre(); } });
  }
}

function onClic(e) {
  var b = e.target.closest("[data-act]");
  if (!b || OCCUPE) return;
  var act = b.getAttribute("data-act");

  if (act === "confirmer-upload") {
    var sel = document.getElementById("imp-attente-convention");
    var fichier = FICHIER_ATTENTE ? FICHIER_ATTENTE.file : null;
    var convention = sel ? sel.value : "FH";
    FICHIER_ATTENTE = null;
    if (fichier) televerser(fichier, convention);
  } else if (act === "annuler-upload") {
    FICHIER_ATTENTE = null;
    rendre();
  } else if (act === "suppr-fichier") {
    poste({ action: "supprimer_fichier", id: b.getAttribute("data-id") }).then(function (r) {
      if (r.ok) { ETAT = r; APERCU = null; rendre(); }
    });
  } else if (act === "reinit") {
    if (!confirm("Effacer tous les fichiers déposés et repartir de zéro ? Rien n'aura été écrit dans ianseo.")) return;
    poste({ action: "reinitialiser" }).then(function (r) { if (r.ok) { ETAT = r; APERCU = null; rendre(); } });
  } else if (act === "resoudre") {
    poste({
      action: "resoudre", licence: b.getAttribute("data-lic"), role: b.getAttribute("data-role"),
      champ: b.getAttribute("data-champ"), valeur: b.getAttribute("data-val"),
    }).then(function (r) { if (r.ok) { ETAT = r; rendre(); } });
  } else if (act === "resoudre-tout") {
    poste({ action: "resoudre_tout" }).then(function (r) { if (r.ok) { ETAT = r; rendre(); } });
  } else if (act === "construire-classements") {
    occupe(true);
    poste({ action: "construire_classements" }).then(function (r) {
      occupe(false);
      if (!r.ok) return;
      ETAT = r;
      rendre();
      var m = document.getElementById("imp-cls-msg");
      if (m) {
        m.innerHTML = '<div class="msg ok on">Classements construits' +
          (r.associes ? " — " + r.associes + " épreuve(s) associée(s) automatiquement" : "") + ".</div>";
      }
    });
  } else if (act === "reinit-classements") {
    if (!confirm("Supprimer tous les classements dérivés de l'arrêté de cette compétition, ainsi que " +
        "leurs associations aux épreuves ? Les fichiers déposés et la consolidation restent intacts — " +
        "tu pourras reconstruire les classements avec « Construire / actualiser ».")) return;
    occupe(true);
    poste({ action: "reinitialiser_classements" }).then(function (r) {
      occupe(false);
      if (!r.ok) return;
      ETAT = r;
      rendre();
      var m = document.getElementById("imp-cls-msg");
      if (m) m.innerHTML = '<div class="msg ok on">' + r.classementsReinitialises + " classement(s) supprimé(s).</div>";
    });
  } else if (act === "apercu") {
    occupe(true);
    poste({ action: "apercu_ecriture" }).then(function (r) {
      occupe(false);
      if (r.ok) { APERCU = r; rendre(); }
    });
  } else if (act === "telecharger") {
    occupe(true);
    var entete = document.getElementById("imp-opt-entete");
    var bom = document.getElementById("imp-opt-bom");
    poste({ action: "telecharger", entete: (entete && entete.checked) ? "1" : "", bom: (bom && bom.checked) ? "1" : "" }).then(function (r) {
      occupe(false);
      if (!r.ok) { msgEcriture(r.err, "err"); return; }
      var blob = new Blob([r.contenu], { type: "text/csv;charset=utf-8" });
      var a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "arrete-import.csv";
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      msgEcriture(r.nb + " ligne(s) dans le fichier (" + r.ignorees + " ignorée(s)).", "ok");
    });
  } else if (act === "dm-apercu") {
    occupe(true);
    poste({ action: "dm_apercu" }).then(function (r) {
      occupe(false);
      if (r.ok) { DM_APERCU = r.clubs; rendre(); }
    });
  } else if (act === "dm-appliquer") {
    var chks = hote.querySelectorAll(".dm-chk:checked");
    var enids = [];
    for (var k = 0; k < chks.length; k++) enids.push(chks[k].getAttribute("data-enid"));
    if (!enids.length) { msgDm("Aucun archer coché.", "err"); return; }
    occupe(true);
    poste({ action: "dm_appliquer", enids: enids.join(",") }).then(function (r) {
      if (!r.ok) { occupe(false); msgDm(r.err || "Échec.", "err"); return; }
      poste({ action: "dm_apercu" }).then(function (r2) {
        occupe(false);
        if (r2.ok) DM_APERCU = r2.clubs;
        rendre();
        msgDm(r.n + " archer(s) avec le drapeau double mixte.", "ok");
      });
    });
  } else if (act === "ecrire-direct") {
    if (!confirm("Importer directement ces inscriptions dans ianseo ? Cette action écrit dans la base de la compétition (aucune suppression ni modification d'inscription existante).")) return;
    occupe(true);
    poste({ action: "ecrire_direct" }).then(function (r) {
      occupe(false);
      DIRECT = r;
      rendre();
      if (r.ok) {
        msgEcriture(r.tentees + " inscription(s) importée(s) (" + r.ignorees + " ignorée(s)).", "ok");
      } else {
        msgEcriture(r.err || "Échec de l'import.", "err");
      }
    });
  } else if (act === "mode-auto") {
    if (!confirm("Lancer le mode automatique ? Il va construire les classements, importer les inscriptions " +
        "dans ianseo (si les droits le permettent), puis cocher le drapeau double mixte pour les archers " +
        "éligibles des clubs concernés. Aucune inscription existante n'est modifiée ni supprimée.")) return;
    occupe(true);
    poste({ action: "mode_auto" }).then(function (r) {
      occupe(false);
      if (!r.ok) return;
      ETAT = r;
      MODE_AUTO = r.modeAuto;
      DIRECT = (MODE_AUTO && MODE_AUTO.ok) ? MODE_AUTO.ecriture : null;
      if (MODE_AUTO && !MODE_AUTO.ok) REPLI.consolide = false;   // force l'affichage des incohérences à corriger
      rendre();
      msgAuto(MODE_AUTO);
    });
  }
}

function mettreEnAttente(fichier) {
  FICHIER_ATTENTE = { file: fichier, convention: conventionDepuisNom(fichier.name) };
  rendre();
}

function televerser(fichier, convention) {
  var fd = new FormData();
  fd.append("action", "televerser");
  fd.append("fichier", fichier);
  fd.append("convention", convention || "FH");
  occupe(true);
  posteFichier(fd).then(function (r) {
    occupe(false);
    if (!r.ok) {
      rendre();   // referme le panneau d'attente (déjà effacé côté état JS)
      var m = document.getElementById("imp-msg-upload");
      if (m) m.innerHTML = '<div class="msg err on">' + ech(r.err) + "</div>";
      return;
    }
    ETAT = r; APERCU = null;
    rendre();
    var m2 = document.getElementById("imp-msg-upload");
    if (m2 && r.fiche) {
      var txt = "Fichier « " + ech(r.fiche.sousType || r.fiche.type) + " » importé : " + r.fiche.nb + " ligne(s).";
      if (r.fiche.inconnues && r.fiche.inconnues.length) {
        txt += " Colonnes ignorées (non reconnues) : " + r.fiche.inconnues.map(ech).join(", ") + ".";
      }
      m2.innerHTML = '<div class="msg ok on">' + txt + "</div>";
    }
  });
}

function msgEcriture(txt, type) {
  var m = document.getElementById("imp-ecriture-msg");
  if (m) m.innerHTML = '<div class="msg ' + type + ' on">' + ech(txt) + "</div>";
}

function msgDm(txt, type) {
  var m = document.getElementById("imp-dm-msg");
  if (m) m.innerHTML = '<div class="msg ' + type + ' on">' + ech(txt) + "</div>";
}

/** Résumé du mode automatique (voir carteAutomatique(), action "mode_auto"). */
function msgAuto(r) {
  var m = document.getElementById("imp-auto-msg");
  if (!m || !r) return;
  if (!r.ok) {
    m.innerHTML = '<div class="msg err on"><b>' + r.bloquants.length +
      " incohérence(s) à résoudre avant de continuer</b> (déplie « Archers et coachs consolidés » " +
      'ci-dessous pour les corriger) :<ul style="margin:4px 0 0;padding-left:18px">' +
      r.bloquants.map(function (b) {
        return "<li>" + ech(b.nom) + " (" + ech(b.licence) + ") — " + ech(b.champs) + "</li>";
      }).join("") + "</ul></div>";
    return;
  }
  var lignes = [];
  lignes.push(r.classements.individuel + " classement(s) individuel(s), " + r.classements.equipe +
    " d'équipe construit(s).");
  if (r.associes) lignes.push(r.associes + " épreuve(s) associée(s) automatiquement à leur classement.");
  if (!r.directPossible) {
    lignes.push("Import direct indisponible (droits « Participants » requis) : télécharge le fichier plus bas " +
      "et réimporte-le manuellement dans ianseo, puis relance le mode automatique pour la propagation double mixte.");
  } else if (r.ecriture) {
    lignes.push(r.ecriture.tentees + " inscription(s) importée(s) (" + r.ecriture.ignorees + " ignorée(s)).");
    if (r.ecriture.manquantes && r.ecriture.manquantes.length) {
      lignes.push('<b style="color:var(--ko-tx)">' + r.ecriture.manquantes.length +
        " inscription(s) introuvable(s) après import</b> — voir « Écriture dans ianseo » ci-dessous.");
    }
  }
  lignes.push(r.dm + " archer(s) marqué(s) double mixte par propagation de club.");
  m.innerHTML = '<div class="msg ok on"><ul style="margin:0;padding-left:18px">' +
    lignes.map(function (l) { return "<li>" + l + "</li>"; }).join("") + "</ul></div>";
}

charger();
})();
