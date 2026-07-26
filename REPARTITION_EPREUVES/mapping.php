<?php
/**
 * mapping.php — écran 2 : correspondances épreuve ianseo ↔ classement FFTA.
 *
 * Les correspondances sont enregistrées par « set » de compétition
 * (`Tournament.ToTypeSubRule`) : deux compétitions du même set les partagent, on ne
 * les saisit qu'une fois. Chaque ÉPREUVE a sa propre discipline (depuis 1.4.0) :
 * un même championnat (« Adulte ») peut très bien mélanger des épreuves en TAE
 * International et d'autres en TAE National — le sélecteur de discipline en haut
 * de page ne fait que choisir QUEL catalogue FFTA parcourir pour affecter de
 * nouvelles épreuves ; il n'efface jamais les épreuves déjà affectées sous une
 * AUTRE discipline (rep_mapping_enregistrer() fusionne, ne remplace pas).
 * data/mapping.json est dans files[] de version.json — la version du dépôt fait
 * autorité, une correction faite ici doit être poussée sur GitHub sinon la
 * prochaine mise à jour la remplacera.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$cfg   = rep_config_lire($REP_TOUR);
$annee = intval($_REQUEST['annee'] ?? $cfg['annee']);
$disc  = rep_disc_valide($_REQUEST['discipline'] ?? $cfg['discipline']);
$set   = $cfg['set'];

$info = '';
$erreur = '';

if (($_POST['action'] ?? '') === 'enregistrer') {
    if (!hash_equals(rep_token(), (string) ($_POST['jeton'] ?? ''))) {
        $erreur = 'Jeton de session invalide — rechargez la page.';
    } else {
        $epreuves = [];
        foreach ((array) ($_POST['cible'] ?? []) as $cle => $val) {
            $cle = (string) $cle;
            if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $cle)) continue;   // code d'épreuve (EvCode)
            $val = trim((string) $val);
            if ($val === '') { $epreuves[$cle] = null; continue; }      // « aucune » = supprime la correspondance
            $p = explode('|', $val);
            if (count($p) !== 4) continue;
            $discSoumis = rep_disc_valide((string) ($_POST['disc'][$cle] ?? $disc));
            $epreuves[$cle] = ['discipline' => $discSoumis, 'arme' => $p[0], 'categorie' => $p[1],
                               'sexe' => $p[2], 'niveau' => $p[3]];
        }
        ksort($epreuves);
        if ($set === '') {
            $erreur = "Cette compétition n'a pas de sous-type (set) déclaré : impossible de mémoriser les correspondances par épreuve.";
        } elseif (rep_mapping_enregistrer($set, $epreuves)) {
            rep_config_ecrire($REP_TOUR, $annee, $disc);
            $n = count(array_filter($epreuves, function ($v) { return $v !== null; }));
            $info = $n . ' correspondance(s) enregistrée(s) pour le set <code>'
                  . htmlspecialchars($set) . '</code>. Toute compétition du même set les reprendra '
                  . 'automatiquement. Pensez à pousser <code>data/mapping.json</code> sur le dépôt.';
        } else {
            $erreur = "Écriture impossible dans data/mapping.json (droits du dossier ?).";
        }
    }
}

rep_config_ecrire($REP_TOUR, $annee, $disc);

// ── Choix possibles : ce que la FFTA publie cette saison, pour LA discipline
// actuellement parcourue (les épreuves déjà affectées sous une autre discipline
// gardent leur valeur via l'option « hors discipline » injectée plus bas). ────
$dist      = rep_ffta_liste($annee, $disc);
$choix     = [];
$avertFfta = '';
if ($dist['ok'] && empty($dist['vide'])) {
    foreach ($dist['liste'] as $c) {
        $k = rep_cle_classement($c['arme'], $c['categorie'], $c['sexe'], $c['niveau']);
        $choix[$k] = $c['libelle'] . ($c['distance'] !== '' ? '  (' . $c['distance'] . ')' : '');
    }
} elseif (!empty($dist['vide'])) {
    $avertFfta = "La FFTA ne publie aucun classement pour cette discipline en $annee.";
} else {
    $avertFfta = "Liste FFTA indisponible ({$dist['err']}) — seules les valeurs déjà téléchargées sont proposées.";
    $rs = safe_r_sql("SELECT DISTINCT CcArme, CcCategorie, CcSexe, CcNiveau, CcLibelle, CcDistance
        FROM REP_Classements WHERE CcAnnee=" . intval($annee) . " AND CcDiscipline=" . StrSafe_DB($disc));
    while ($rs && $r = safe_fetch($rs)) {
        $k = rep_cle_classement($r->CcArme, $r->CcCategorie, $r->CcSexe, $r->CcNiveau);
        $choix[$k] = $r->CcLibelle . ($r->CcDistance !== '' ? '  (' . $r->CcDistance . ')' : '');
    }
}
uasort($choix, 'strcasecmp');

$courant  = rep_mapping_actif($set);
$epreuves = rep_epreuves($REP_TOUR);
$multi    = rep_classes_multi_epreuves($REP_TOUR);

$PAGE_TITLE = 'Correspondances épreuve ↔ classement';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $REP_ROOT ?>assets/rep.css?v=<?= rep_version() ?>">
<div id="rep">
  <h1>Correspondances épreuve ↔ classement national</h1>
  <p class="sous">Une ligne par épreuve individuelle inscrite dans la compétition. Les épreuves sans
     correspondance sont signalées : leurs archers seraient tous traités comme non classés. Chaque
     épreuve garde sa propre discipline : changez le sélecteur ci-dessous pour affecter les épreuves
     d'une autre discipline sans perdre celles déjà faites (utile pour un championnat qui mélange par
     exemple TAE International et TAE National).
     <?php if ($set !== ''): ?>
       Enregistrement rattaché au set <b><?= htmlspecialchars($set) ?></b> — toute autre compétition
       du même set reprendra ces correspondances sans les ressaisir.
     <?php else: ?>
       Cette compétition n'a pas de sous-type déclaré : l'enregistrement sert de repli pour toute la discipline.
     <?php endif; ?>
  </p>

  <?php if ($info): ?><div class="msg ok on"><?= $info ?></div><?php endif; ?>
  <?php if ($erreur): ?><div class="msg err on"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>
  <?php if ($avertFfta): ?><div class="msg err on"><?= htmlspecialchars($avertFfta) ?></div><?php endif; ?>

  <form method="post" action="mapping.php">
    <input type="hidden" name="action" value="enregistrer">
    <input type="hidden" name="jeton" value="<?= htmlspecialchars(rep_token()) ?>">
    <input type="hidden" name="annee" value="<?= $annee ?>">
    <input type="hidden" name="discipline" value="<?= htmlspecialchars($disc) ?>">

    <div class="carte">
      <h2><span>Saison <?= $annee ?> — parcourir : <?= htmlspecialchars(rep_disc_lib($disc)) ?></span>
        <span class="sep"></span>
        <span class="sub"><?= count($epreuves) ?> épreuve(s) dans la compétition</span></h2>
      <div class="corps">
        <div class="segs" role="group" aria-label="Discipline parcourue">
          <?php foreach (rep_ffta_disciplines() as $code => $lib): ?>
          <button type="button" class="seg" aria-pressed="<?= (string) $code === (string) $disc ? 'true' : 'false' ?>"
                  onclick="location.href='mapping.php?annee=<?= $annee ?>&amp;discipline=<?= urlencode((string) $code) ?>'">
            <?= htmlspecialchars($lib) ?></button>
          <?php endforeach; ?>
        </div>

        <?php if (!$epreuves): ?>
          <p class="sous">Aucune épreuve individuelle n'est inscrite dans cette compétition.</p>
        <?php else: ?>
        <table class="g">
          <thead><tr>
            <th class="lft" style="width:70px">Épreuve</th>
            <th class="lft">Intitulé ianseo</th>
            <th class="lft" style="width:150px">Catégories</th>
            <th style="width:56px">Archers</th>
            <th class="lft" style="width:320px">Classement national</th>
            <th class="lft" style="width:90px">Discipline</th>
            <th style="width:150px">État</th>
            <?php if ($multi): ?><th style="width:120px">Classe partagée</th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($epreuves as $cle => $e):
              $sel = '';
              $rowDisc = $disc;
              if (!empty($courant[$cle])) {
                  $sel = rep_cle_classement($courant[$cle]['arme'], $courant[$cle]['categorie'],
                                            $courant[$cle]['sexe'], $courant[$cle]['niveau'] ?? '');
                  $rowDisc = rep_disc_valide($courant[$cle]['discipline'] ?? $disc);
              }
              // Suggestion par défaut si rien d'enregistré (Scratch → classement Scratch).
              $suggere = false;
              if ($sel === '') { $sug = rep_mapping_suggestion($e, $choix, $disc); if ($sug !== '') { $sel = $sug; $suggere = true; } }

              $dansCetteDiscipline = ($rowDisc === $disc);
              $cl  = rep_classement_epreuve($REP_TOUR, $cle, $annee, $rowDisc, $set);
              $ffta = ($dansCetteDiscipline && $sel !== '' && isset($choix[$sel])) ? rep_ffta_id_pour($dist, $sel) : 0;

              if (!$sel)                                    $etat = '<span class="pill p-ko">sans correspondance</span>';
              elseif ($dansCetteDiscipline && !isset($choix[$sel])) $etat = '<span class="pill p-vx">absent de la saison</span>';
              elseif ($cl && $cl['ccid'])   $etat = '<span class="pill p-ok' . ($ffta ? ' oc-dl' : '') . '"'
                                                  . ($ffta ? ' data-ffta="' . $ffta . '" title="Cliquer pour actualiser ce classement"' : '')
                                                  . '>' . intval($cl['nb']) . ' archers en base</span>';
              else                          $etat = '<span class="pill p-nb' . ($ffta ? ' oc-dl' : '') . '"'
                                                  . ($ffta ? ' data-ffta="' . $ffta . '" title="Cliquer pour télécharger ce classement"' : '')
                                                  . '>' . ($ffta ? 'télécharger' : 'pas encore téléchargé') . '</span>';

              // classes de l'épreuve présentes aussi dans une autre épreuve
              $partagees = [];
              foreach ($e['classes'] as $cls) { $k = $e['division'] . $cls; if (isset($multi[$k])) $partagees[] = $cls; }
          ?>
            <tr>
              <td class="lft"><b><?= htmlspecialchars($e['event']) ?></b></td>
              <td class="lft"><?= htmlspecialchars($e['nom']) ?></td>
              <td class="lft" style="font-size:10px"><?= htmlspecialchars(implode(', ', $e['classes'])) ?></td>
              <td><?= intval($e['nb']) ?></td>
              <td class="lft">
                <select name="cible[<?= htmlspecialchars($cle) ?>]" data-cle="<?= htmlspecialchars($cle) ?>"
                        style="width:100%"<?= $suggere ? ' data-suggere="1"' : '' ?>>
                  <option value="">— aucune —</option>
                  <?php foreach ($choix as $val => $lib): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= ($val === $sel && $dansCetteDiscipline) ? ' selected' : '' ?>>
                      <?= htmlspecialchars($lib) ?></option>
                  <?php endforeach; ?>
                  <?php if ($sel && (!$dansCetteDiscipline || !isset($choix[$sel]))): ?>
                    <option value="<?= htmlspecialchars($sel) ?>" selected>
                      <?= htmlspecialchars(str_replace('|', ' · ', $sel)) ?>
                      (<?= $dansCetteDiscipline ? 'hors saison' : htmlspecialchars(rep_disc_lib($rowDisc)) ?>)</option>
                  <?php endif; ?>
                </select>
                <input type="hidden" name="disc[<?= htmlspecialchars($cle) ?>]" value="<?= htmlspecialchars($rowDisc) ?>">
              </td>
              <td class="lft" style="font-size:10.5px"><?= $sel ? htmlspecialchars(rep_disc_lib($rowDisc)) : '—' ?></td>
              <td><?= $etat ?></td>
              <?php if ($multi): ?><td><?= $partagees ? '<span class="pill p-vx" title="Cette ou ces classes sont aussi dans une autre épreuve — un même archer pourrait être placé deux fois. À vérifier.">' . htmlspecialchars(implode(', ', $partagees)) . '</span>' : '' ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($multi): ?>
          <div class="msg err on" style="margin-top:8px">Certaines classes appartiennent à plusieurs épreuves
          (colonne « Classe partagée »). Un même archer pourrait alors être placé deux fois : vérifiez ces
          épreuves avant d'appliquer. Le contrôle « un archer ne tire qu'une fois par départ » le détectera aussi.</div>
        <?php endif; ?>
        <div class="legende">
          <span class="sep" style="flex:1"></span>
          <a class="btn" href="classements.php">Télécharger les classements manquants</a>
          <button type="submit" class="btn prim">Enregistrer les correspondances</button>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<script>
(function(){
"use strict";
var ROOT = <?= json_encode($REP_ROOT) ?>, JETON = <?= json_encode(rep_token()) ?>;
var annee = <?= intval($annee) ?>, disc = <?= json_encode($disc) ?>, occupe = false;

// Choisir une valeur dans le menu déroulant d'une épreuve rattache cette
// correspondance à la discipline actuellement parcourue (elle garde sa
// discipline d'origine tant qu'on ne la touche pas — utile pour ne pas
// re-taguer par erreur une épreuve déjà affectée sous une autre discipline).
document.getElementById("rep").addEventListener("change", function(e){
  var sel = e.target.closest("select[name^='cible[']");
  if (!sel) return;
  var cle = sel.getAttribute("data-cle");
  var h = document.querySelector("input[name='disc[" + cle + "]']");
  if (h) h.value = disc;
});

// Clic sur une pastille « à jour » / « télécharger » → (re)télécharge le classement.
document.getElementById("rep").addEventListener("click", function(e){
  var p = e.target.closest(".oc-dl"); if(!p || occupe) return;
  var id = p.dataset.ffta; if(!id) return;
  occupe = true;
  var avant = p.textContent, cls = p.className;
  p.textContent = "téléchargement…";
  fetch(ROOT + "ajax/classements.php", {method:"POST", credentials:"same-origin",
    body:new URLSearchParams({action:"telecharger", annee:annee, discipline:disc, ids:id, jeton:JETON})})
    .then(function(r){ return r.json(); })
    .then(function(r){
      occupe = false;
      if(r.ok || r.charges){ p.className="pill p-ok"; p.textContent = r.archers + " archers ✓"; p.removeAttribute("data-ffta"); }
      else { p.className = cls; p.textContent = avant; alert("Échec : " + (r.err||"")); }
    })
    .catch(function(ex){ occupe=false; p.className=cls; p.textContent=avant; alert("Erreur réseau : "+ex); });
});
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
