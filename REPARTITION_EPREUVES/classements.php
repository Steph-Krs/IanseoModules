<?php
/**
 * classements.php — écran 1 : téléchargement des classements nationaux FFTA.
 *
 * Matrice armes (colonnes) × catégories d'âge (lignes), homme et femme côte à côte.
 * Les cases hachurées n'existent pas à la FFTA : le découpage par catégorie change
 * d'une arme à l'autre (le Longbow en Campagne n'a qu'un « Scratch », le Bare Bow
 * fusionne U15 et U18).
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$cfg    = rep_config_lire($REP_TOUR);

// Saisons proposées : de l'année en cours (dynamique) jusqu'à 2024. En 2027, la
// liste ira donc de 2027 à 2024 — l'année courante apparaît d'elle-même.
$annees = [];
for ($a = max(intval(date('Y')), REP_ANNEE_MIN); $a >= REP_ANNEE_MIN; $a--) $annees[] = $a;
$anneeSel = min(max(intval($cfg['annee']), REP_ANNEE_MIN), $annees[0]);

$PAGE_TITLE = 'Classements nationaux';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $REP_ROOT ?>assets/rep.css?v=<?= rep_version() ?>">
<div id="rep">
  <h1>Classements nationaux</h1>
  <p class="sous">Source : l'iframe publique de l'extranet fédéral, sans authentification.
     Les identifiants de classement changent à chaque saison — la liste est relue à chaque
     ouverture, jamais mise en cache.</p>

  <div class="carte">
    <h2><span>Saison et discipline</span><span class="sep"></span>
        <span class="sub" id="rep-total"></span></h2>
    <div class="corps">
      <div class="segs" role="group" aria-label="Saison" id="rep-annees">
        <?php foreach ($annees as $a): ?>
        <button class="seg" data-annee="<?= $a ?>" aria-pressed="<?= $a === $anneeSel ? 'true' : 'false' ?>"><?= $a ?></button>
        <?php endforeach; ?>
      </div>
      <div class="segs" role="group" aria-label="Discipline" id="rep-disc">
        <?php foreach (rep_ffta_disciplines() as $code => $lib): ?>
        <button class="seg" data-disc="<?= htmlspecialchars((string) $code) ?>"
                aria-pressed="<?= (string) $code === (string) $cfg['discipline'] ? 'true' : 'false' ?>"><?= htmlspecialchars($lib) ?></button>
        <?php endforeach; ?>
      </div>
      <div id="rep-matrice"></div>
      <div class="legende">
        <span><span class="pill p-ok">JJ/MM</span> à jour (moins de 7 jours)</span>
        <span><span class="pill p-vx">JJ/MM</span> plus de 30 jours</span>
        <span><span class="pill p-nb">—</span> jamais téléchargé</span>
        <span><span class="na" style="display:inline-block;width:22px;height:12px;border:1px solid #d2d4d6;vertical-align:-2px"></span> n'existe pas à la FFTA</span>
        <span class="sep" style="flex:1"></span>
        <button class="btn" id="rep-vider" style="border-color:#bb7575;color:#a80000">Vider la base des classements…</button>
      </div>
      <div class="msg" id="rep-msg"></div>
    </div>
  </div>
</div>

<script>
(function(){
"use strict";
var ROOT = <?= json_encode($REP_ROOT) ?>, JETON = <?= json_encode(rep_token()) ?>;
var annee = <?= intval($anneeSel) ?>, disc = <?= json_encode($cfg['discipline']) ?>;
var etat = null, occupe = false;

var $ = function(s){ return document.querySelector(s); };
function msg(txt, err){
  var m = $("#rep-msg");
  m.className = "msg on " + (err ? "err" : "ok");
  m.innerHTML = txt;
}
function attente(txt){
  var m = $("#rep-msg");
  m.className = "msg on ok";
  m.innerHTML = txt + ' <span class="rep-dots"><i></i><i></i><i></i></span>';
}
function poste(url, data){
  data.jeton = JETON;
  var body = new URLSearchParams(data);
  return fetch(ROOT + url, {method:"POST", body:body, credentials:"same-origin"})
         .then(function(r){ return r.json(); });
}

function pastille(c){
  if(!c.maj) return '<span class="pill p-nb">—</span>';
  var d = new Date(c.maj.replace(" ","T"));
  var j = Math.floor((Date.now() - d.getTime()) / 86400000);
  var cls = j <= 7 ? "p-ok" : (j > 30 ? "p-vx" : "p-nb");
  var jj = ("0"+d.getDate()).slice(-2) + "/" + ("0"+(d.getMonth()+1)).slice(-2);
  return '<span class="pill '+cls+'" title="'+c.maj+' — '+c.nb+' archers">'+jj+'</span>';
}

var SEXES = ["H","F","X"], LIBSEXE = {H:"H", F:"F", X:"Mixte"};

function rendre(){
  if(!etat){ $("#rep-matrice").innerHTML = ""; return; }
  if(etat.vide){
    $("#rep-matrice").innerHTML = '<p style="color:#7d8183;padding:8px 2px">'
      + "La FFTA ne publie aucun classement pour cette discipline en " + etat.annee + ".</p>";
    $("#rep-total").textContent = "";
    return;
  }
  // Un classement qui regroupe plusieurs catégories (« U15-U18 », « Scratch »)
  // occupe une seule case, fusionnée sur les lignes qu'il couvre.
  var plan = {};
  etat.armes.forEach(function(arme){
    var col = etat.grille[arme] || {}, debuts = {}, couvert = {};
    for(var i = 0; i < etat.categories.length; i++){
      var c = col[etat.categories[i]];
      if(!c || couvert[i]) continue;
      var n = 1;
      while(i + n < etat.categories.length){
        var suiv = col[etat.categories[i+n]];
        if(!suiv || suiv.cle !== c.cle) break;
        couvert[i+n] = true;
        n++;
      }
      debuts[i] = { span: n, cell: c };
    }
    plan[arme] = { debuts: debuts, couvert: couvert };
  });

  function ids(cell){
    var l = [];
    SEXES.forEach(function(s){ if(cell.sexes[s]) l.push(cell.sexes[s].ffta); });
    return l;
  }

  var h = '<table class="g"><thead><tr><th class="lft" style="width:110px">Catégorie ↓ &nbsp; Arme →</th>';
  etat.armes.forEach(function(a){ h += '<th style="min-width:130px">'+a+'</th>'; });
  h += '<th style="width:96px">Ligne</th></tr></thead><tbody>';

  etat.categories.forEach(function(cat, i){
    var tds = "", ligne = {};
    etat.armes.forEach(function(arme){
      var p = plan[arme];
      if(p.couvert[i]) return;                       // case fusionnée depuis une ligne au-dessus
      var d = p.debuts[i];
      if(!d){ tds += '<td class="na" title="Pas de classement '+cat+' en '+arme+'"></td>'; return; }

      var lot = ids(d.cell), enBase = [], cells = "";
      SEXES.forEach(function(s){
        var x = d.cell.sexes[s];
        if(!x) return;
        if(x.maj) enBase.push(x.ffta);
        cells += '<span style="font-size:9px;color:#7d8183">'+LIBSEXE[s]+'</span>'+pastille(x);
      });
      lot.forEach(function(x){ ligne[x] = 1; });
      var titre = d.span > 1 ? ' title="'+d.cell.libelle.replace(/"/g,"&quot;")+' — un seul classement pour '+d.cell.groupe+'"' : '';
      var suppr = enBase.length
        ? '<button class="mini" data-del="'+enBase.join(",")+'" title="Supprimer de la base" aria-label="Supprimer" style="color:#a80000">&times;</button>'
        : '';
      tds += '<td'+(d.span > 1 ? ' rowspan="'+d.span+'" style="vertical-align:middle"' : '')+titre+'>'
        + '<div class="cell">' + cells
        + '<button class="mini" data-ids="'+lot.join(",")+'" title="Actualiser cette case" aria-label="Actualiser">&#8635;</button>'
        + suppr
        + '</div>'
        + (d.span > 1 ? '<div style="font-size:8.5px;color:#7d8183;margin-top:2px">'+d.cell.groupe+'</div>' : '')
        + '</td>';
    });
    // Le bouton de ligne reprend aussi les classements fusionnés qui la traversent.
    etat.armes.forEach(function(arme){
      var p = plan[arme];
      if(!p.couvert[i]) return;
      for(var j = i; j >= 0; j--) if(p.debuts[j]) { ids(p.debuts[j].cell).forEach(function(x){ ligne[x] = 1; }); break; }
    });
    h += '<tr><td class="lft"><b>'+cat+'</b></td>'+tds
       + '<td><button class="mini" data-ids="'+Object.keys(ligne).join(",")+'" title="Actualiser la catégorie" aria-label="Actualiser">&#8635;</button></td></tr>';
  });

  h += '<tr class="tot"><td class="lft">Colonne</td>';
  var tousLot = {};
  etat.armes.forEach(function(arme){
    var l = {};
    Object.keys(plan[arme].debuts).forEach(function(i){ ids(plan[arme].debuts[i].cell).forEach(function(x){ l[x] = 1; tousLot[x] = 1; }); });
    h += '<td><button class="mini" data-ids="'+Object.keys(l).join(",")+'" title="Actualiser l\'arme" aria-label="Actualiser">&#8635;</button></td>';
  });
  h += '<td><button class="mini mini-tout" data-tout title="Tout actualiser — '
     + Object.keys(tousLot).length + ' classement(s) de cette discipline, saison ' + annee
     + '" aria-label="Tout actualiser la discipline">&#8635;</button></td></tr></tbody></table>';
  $("#rep-matrice").innerHTML = h;
  $("#rep-total").textContent = etat.total + " classements publiés cette saison";
}

function charger(){
  if(occupe) return;
  occupe = true;
  attente("Lecture de la liste des classements");
  $("#rep-matrice").innerHTML = "";
  poste("ajax/classements.php", {action:"matrice", annee:annee, discipline:disc})
    .then(function(r){
      occupe = false;
      if(!r.ok){ etat = null; rendre(); msg("Échec : "+r.err, true); return; }
      etat = r; rendre();
      $("#rep-msg").className = "msg";
    })
    .catch(function(e){ occupe = false; msg("Erreur réseau : "+e, true); });
}

// Tous les identifiants FFTA de la matrice actuelle (toutes armes, catégories,
// sexes confondus, dédupliqués) — pour le bouton « Tout actualiser ».
function tousIds(){
  if(!etat || etat.vide) return [];
  var l = {};
  etat.armes.forEach(function(arme){
    var col = etat.grille[arme] || {};
    Object.keys(col).forEach(function(cat){
      SEXES.forEach(function(s){ var x = col[cat].sexes[s]; if(x) l[x.ffta] = 1; });
    });
  });
  return Object.keys(l);
}

// Découpe en lots de 60 (limite de l'action 'telecharger', ajax/classements.php)
// et les poste l'un après l'autre — une discipline peut publier plus de 60
// classements (para, TAE…), un seul appel ne suffirait pas toujours.
function toutActualiser(){
  if(occupe) return;
  var ids = tousIds();
  if(!ids.length){ msg("Aucun classement à actualiser pour cette discipline.", true); return; }
  if(!window.confirm("Actualiser les "+ids.length+" classement(s) de cette discipline pour la saison "+annee+" ?")) return;

  var lots = [];
  for(var i = 0; i < ids.length; i += 60) lots.push(ids.slice(i, i + 60));

  occupe = true;
  var charges = 0, archers = 0, erreurs = [];
  function suite(n){
    if(n >= lots.length){
      occupe = false;
      var t = charges+" classement(s) chargé(s), "+archers+" archers enregistrés.";
      if(erreurs.length) { msg(t+" Incidents : "+erreurs.join(" · "), true); } else { msg(t, false); }
      charger();
      return;
    }
    attente("Synchronisation générale — lot "+(n+1)+"/"+lots.length+" ("+ids.length+" classement(s) au total)");
    poste("ajax/classements.php", {action:"telecharger", annee:annee, discipline:disc, ids:lots[n].join(",")})
      .then(function(r){
        charges += r.charges||0; archers += r.archers||0;
        if(r.err) erreurs.push(r.err);
        suite(n+1);
      })
      .catch(function(e){ erreurs.push(String(e)); suite(n+1); });
  }
  suite(0);
}

function telecharger(ids){
  if(occupe || !ids.length) return;
  occupe = true;
  attente("Téléchargement de "+ids.length+" classement(s)");
  poste("ajax/classements.php", {action:"telecharger", annee:annee, discipline:disc, ids:ids.join(",")})
    .then(function(r){
      occupe = false;
      if(!r.ok && !r.charges){ msg("Échec : "+r.err, true); return; }
      var t = r.charges+" classement(s) chargé(s), "+r.archers+" archers enregistrés.";
      if(r.err) { msg(t+" Incidents : "+r.err, true); } else { msg(t, false); }
      charger();
    })
    .catch(function(e){ occupe = false; msg("Erreur réseau : "+e, true); });
}

function supprimer(ids){
  if(occupe || !ids.length) return;
  occupe = true;
  attente("Suppression de "+ids.length+" classement(s)");
  poste("ajax/classements.php", {action:"supprimer", ids:ids.join(",")})
    .then(function(r){
      occupe = false;
      if(!r.ok){ msg("Échec : "+r.err, true); return; }
      msg(r.supprimes+" classement(s) supprimé(s) de la base.", false);
      charger();
    })
    .catch(function(e){ occupe = false; msg("Erreur réseau : "+e, true); });
}

function vider(){
  if(occupe) return;
  if(!window.confirm("Supprimer TOUS les classements téléchargés, toutes saisons et disciplines confondues ?\n\nLes plans de départ et les cibles déjà attribuées aux archers ne sont pas touchés — seuls les classements nationaux stockés sont effacés.")) return;
  occupe = true;
  attente("Vidage de la base des classements");
  poste("ajax/classements.php", {action:"vider"})
    .then(function(r){
      occupe = false;
      if(!r.ok){ msg("Échec : "+r.err, true); return; }
      msg(r.supprimes+" classement(s) supprimé(s). La base des classements est vide.", false);
      charger();
    })
    .catch(function(e){ occupe = false; msg("Erreur réseau : "+e, true); });
}

document.getElementById("rep-annees").addEventListener("click", function(e){
  var b = e.target.closest("[data-annee]"); if(!b) return;
  this.querySelectorAll(".seg").forEach(function(x){ x.setAttribute("aria-pressed","false"); });
  b.setAttribute("aria-pressed","true");
  annee = parseInt(b.dataset.annee,10); charger();
});
document.getElementById("rep-disc").addEventListener("click", function(e){
  var b = e.target.closest("[data-disc]"); if(!b) return;
  this.querySelectorAll(".seg").forEach(function(x){ x.setAttribute("aria-pressed","false"); });
  b.setAttribute("aria-pressed","true");
  disc = b.dataset.disc; charger();
});
document.getElementById("rep-matrice").addEventListener("click", function(e){
  var del = e.target.closest("[data-del]");
  if(del){ supprimer(del.dataset.del.split(",").filter(Boolean)); return; }
  if(e.target.closest("[data-tout]")){ toutActualiser(); return; }
  var b = e.target.closest("[data-ids]");
  if(b){ telecharger(b.dataset.ids.split(",").filter(Boolean)); }
});
document.getElementById("rep-vider").addEventListener("click", vider);

charger();
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
