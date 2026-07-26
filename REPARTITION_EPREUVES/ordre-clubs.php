<?php
/**
 * ordre-clubs.php — ordre manuel des clubs par épreuve.
 *
 * Une colonne par épreuve, la liste des clubs dessous, réordonnable au glisser-déposer.
 * Cet ordre n'est utilisé que par les blocs dont la source est « Par ordre de club
 * manuel ». Les colonnes se replient pour tenir quand il y a beaucoup d'épreuves.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$cfg      = rep_config_lire($REP_TOUR);
$epreuves = rep_epreuves($REP_TOUR);

$cols = [];
foreach ($epreuves as $cle => $e) {
    $clubs = rep_clubs_epreuve($REP_TOUR, $e['event'],
                              $cfg['annee'], $cfg['discipline'], $cfg['set'] ?? '');
    $cols[] = [
        'event'   => $e['event'],
        'libelle' => $e['event'],
        'nom'     => $e['nom'],
        'sexe'    => $e['sexe'],
        'division' => $e['division'],
        'clubs'   => $clubs,
    ];
}

// Départ (QuSession) de chaque épreuve, d'après l'affectation ianseo des archers.
$departsParEv = [];
$rs = safe_r_sql("SELECT i.IndEvent AS ev, q.QuSession AS s, COUNT(*) n
    FROM Individuals i JOIN Qualifications q ON q.QuId = i.IndId
    WHERE i.IndTournament=" . intval($REP_TOUR) . " AND q.QuSession > 0
    GROUP BY i.IndEvent, q.QuSession");
while ($rs && $r = safe_fetch($rs)) $departsParEv[$r->ev][intval($r->s)] = intval($r->n);

// Armes présentes, pour le filtre.
$armesPresentes = [];
foreach ($cols as $c) $armesPresentes[$c['division']] = true;
$departsPresents = [];
foreach ($departsParEv as $evd) foreach (array_keys($evd) as $s) $departsPresents[$s] = true;
ksort($departsPresents);

$PAGE_TITLE = 'Ordre des clubs';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $REP_ROOT ?>assets/rep.css?v=<?= rep_version() ?>">
<div id="rep">
  <h1>Ordre des clubs</h1>
  <p class="sous">Une colonne par épreuve. Glissez les clubs pour changer leur ordre — il est
     enregistré automatiquement. Cet ordre n'est utilisé que par les blocs réglés sur
     <b>« Par ordre de club manuel »</b> dans le <a href="index.php">plan des départs</a>.
     Les clubs non rangés à la main suivent leur meilleur classement national.</p>

  <?php if (!$cols): ?>
    <div class="msg err on">Aucune épreuve individuelle n'est inscrite dans cette compétition.</div>
  <?php else: ?>
    <div class="barre-g">
      <button class="btn" id="oc-deplier">Tout déplier</button>
      <button class="btn" id="oc-replier">Tout replier</button>
      <?php if (count($departsPresents) > 1): ?>
      <label style="font-size:10px;color:#4c4e50">Départ
        <select id="oc-f-dep"><option value="">tous</option>
          <?php foreach (array_keys($departsPresents) as $s): ?><option value="<?= $s ?>">Départ <?= $s ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <label style="font-size:10px;color:#4c4e50">Sexe
        <select id="oc-f-sexe"><option value="">tous</option><option value="H">Homme</option><option value="F">Femme</option></select></label>
      <?php if (count($armesPresentes) > 1): ?>
      <label style="font-size:10px;color:#4c4e50">Arme
        <select id="oc-f-arme"><option value="">toutes</option>
          <?php foreach (array_keys($armesPresentes) as $dv): ?><option value="<?= htmlspecialchars($dv) ?>"><?= htmlspecialchars($dv) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <span class="sep" style="flex:1"></span>
      <span class="msg ok" id="oc-msg" style="margin:0;padding:3px 8px"></span>
    </div>

    <div class="oc-cols">
      <?php foreach ($cols as $c): ?>
        <div class="oc-col" data-event="<?= htmlspecialchars($c['event']) ?>"
             data-sexe="<?= htmlspecialchars($c['sexe']) ?>" data-arme="<?= htmlspecialchars($c['division']) ?>"
             data-deps="<?= htmlspecialchars(implode(',', array_keys($departsParEv[$c['event']] ?? []))) ?>">
          <div class="oc-head" tabindex="0" role="button" aria-expanded="true">
            <span class="caret">▾</span>
            <b><?= htmlspecialchars($c['libelle']) ?></b>
            <span class="oc-nb"><?= count($c['clubs']) ?> club(s)</span>
          </div>
          <div class="oc-body">
            <ol class="oc-list">
              <?php foreach ($c['clubs'] as $club): ?>
                <li class="oc-item" draggable="true" data-code="<?= htmlspecialchars($club['code']) ?>">
                  <span class="oc-poi" aria-hidden="true">⠿</span>
                  <span class="oc-nom"><?= htmlspecialchars($club['nom']) ?></span>
                  <span class="oc-meta"><?= htmlspecialchars($club['code']) ?> · <?= intval($club['nb']) ?> arch.<?= $club['rang'] !== null ? ' · n°' . intval($club['rang']) : '' ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
            <div class="oc-foot"><button class="btn" data-reset>Ordre par défaut</button></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
"use strict";
var ROOT = <?= json_encode($REP_ROOT) ?>, JETON = <?= json_encode(rep_token()) ?>;
var $ = function(s,c){ return (c||document).querySelector(s); };

function msg(txt, err){
  var m = $("#oc-msg"); if(!m) return;
  m.className = "msg " + (err ? "err on" : "ok on");
  m.textContent = txt;
  clearTimeout(m._t); m._t = setTimeout(function(){ m.className = "msg"; }, 2500);
}
function poste(data){
  data.jeton = JETON;
  return fetch(ROOT + "ajax/ordre-clubs.php", {method:"POST", credentials:"same-origin",
    body:new URLSearchParams(data)}).then(function(r){ return r.json(); });
}
function codes(col){
  return Array.prototype.map.call(col.querySelectorAll(".oc-item"), function(li){ return li.dataset.code; });
}
function enregistre(col){
  poste({action:"enregistrer", event:col.dataset.event, codes:codes(col).join(",")})
    .then(function(r){ msg(r.ok ? "Ordre enregistré." : ("Échec : "+r.err), !r.ok); })
    .catch(function(e){ msg("Erreur réseau : "+e, true); });
}

/* ── glisser-déposer dans une même colonne ── */
var drag = null;
document.addEventListener("dragstart", function(e){
  var li = e.target.closest(".oc-item"); if(!li) return;
  drag = li; li.classList.add("oc-drag");
  e.dataTransfer.effectAllowed = "move";
  try { e.dataTransfer.setData("text/plain", li.dataset.code); } catch(_){}
});
document.addEventListener("dragend", function(){
  if(drag){ drag.classList.remove("oc-drag"); drag = null; }
});
document.addEventListener("dragover", function(e){
  if(!drag) return;
  var list = e.target.closest(".oc-list"); if(!list) return;
  if(drag.closest(".oc-list") !== list) return;   // pas de transfert entre épreuves
  e.preventDefault();
  var apres = null, items = list.querySelectorAll(".oc-item:not(.oc-drag)");
  for(var i=0;i<items.length;i++){
    var r = items[i].getBoundingClientRect();
    if(e.clientY < r.top + r.height/2){ apres = items[i]; break; }
  }
  if(apres) list.insertBefore(drag, apres); else list.appendChild(drag);
});
document.addEventListener("drop", function(e){
  if(!drag) return;
  var col = drag.closest(".oc-col"); if(col){ e.preventDefault(); enregistre(col); }
});

/* ── plier / déplier ── */
function bascule(col, ouvert){
  col.classList.toggle("oc-plie", !ouvert);
  var h = col.querySelector(".oc-head");
  h.setAttribute("aria-expanded", ouvert ? "true" : "false");
  h.querySelector(".caret").textContent = ouvert ? "▾" : "▸";
}
document.addEventListener("click", function(e){
  var h = e.target.closest(".oc-head");
  if(h){ var col = h.closest(".oc-col"); bascule(col, col.classList.contains("oc-plie")); return; }
  var rz = e.target.closest("[data-reset]");
  if(rz){
    var col2 = rz.closest(".oc-col");
    poste({action:"reset", event:col2.dataset.event})
      .then(function(r){ if(r.ok){ msg("Ordre par défaut rétabli — rechargez pour voir l'ordre national."); } });
    return;
  }
});

/* ── filtres départ / sexe / arme ── */
function filtre(){
  var fd = document.getElementById("oc-f-dep"), fs = document.getElementById("oc-f-sexe"), fa = document.getElementById("oc-f-arme");
  var dep = fd ? fd.value : "", sx = fs ? fs.value : "", ar = fa ? fa.value : "";
  document.querySelectorAll(".oc-col").forEach(function(col){
    var ok = true;
    if(sx && col.dataset.sexe !== sx) ok = false;
    if(ar && col.dataset.arme !== ar) ok = false;
    if(dep){ var deps = (col.dataset.deps||"").split(",").filter(Boolean); if(deps.indexOf(dep) < 0) ok = false; }
    col.style.display = ok ? "" : "none";
  });
}
["oc-f-dep","oc-f-sexe","oc-f-arme"].forEach(function(id){ var el=document.getElementById(id); if(el) el.addEventListener("change", filtre); });
document.addEventListener("keydown", function(e){
  var h = e.target.closest(".oc-head"); if(!h) return;
  if(e.key==="Enter"||e.key===" "){ e.preventDefault();
    var col = h.closest(".oc-col"); bascule(col, col.classList.contains("oc-plie")); }
});
var dp = $("#oc-deplier"), rp = $("#oc-replier");
if(dp) dp.addEventListener("click", function(){ document.querySelectorAll(".oc-col").forEach(function(c){ bascule(c,true); }); });
if(rp) rp.addEventListener("click", function(){ document.querySelectorAll(".oc-col").forEach(function(c){ bascule(c,false); }); });
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
