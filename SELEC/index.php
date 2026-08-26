<?php
/**
 * index.php — configuration de la sélection pour la compétition ouverte.
 *
 * Trois choses à régler, dans cet ordre :
 *   1. le MODE de sélection (quel règlement s'applique) — figé au rattachement ;
 *   2. les CATÉGORIES traitées (les épreuves individuelles ianseo concernées) ;
 *   3. le RATTACHEMENT des épreuves ianseo qui portent les tournois et les poules.
 *
 * Le calcul lui-même et son résultat vivent dans classement.php.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$cfg        = selec_config_lire($SELEC_TOUR);
$catalogue  = selec_modes_catalogue();
$categories = selec_categories($SELEC_TOUR);
$tour       = selec_tournoi($SELEC_TOUR);

$mode    = $cfg && $cfg['snapshot'] ? $cfg['snapshot'] : null;
$aLier   = $mode ? selec_etapes_a_lier($mode) : array();
$binds   = selec_binds_tous($SELEC_TOUR);
$options = $cfg ? $cfg['options'] : array();
// Les épreuves que le module a créées pour les tournois portent la même portée
// que leur épreuve de qualification : ianseo y range les mêmes archers. Ce sont
// des supports de duels, jamais des catégories — les lister ici conduirait à
// traiter chaque archer autant de fois qu'il a de tournois.
$liees = $mode ? selec_evenements_lies($SELEC_TOUR) : array();
$catsActives = $mode ? selec_categories_actives($SELEC_TOUR, $cfg) : array();

// Distances de qualification réellement attendues par le mode : sert à prévenir
// tout de suite si la compétition n'en déclare pas assez (une sélection à
// 4 qualifications a besoin de 8 distances, le maximum de ianseo).
$distMax = 0;
if ($mode) {
    foreach ($mode['etapes'] as $st) {
        foreach ((array) (isset($st['distances']) ? $st['distances'] : array()) as $d) {
            $distMax = max($distMax, intval($d));
        }
    }
}

$PAGE_TITLE = 'Sélection — configuration';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $SELEC_ROOT ?>assets/selec.css?v=<?= selec_version() ?>">
<div id="selec">
  <h1>Sélection Équipe de France — configuration</h1>
  <p class="sous">Le mode de sélection est <b>figé</b> au moment du rattachement : une mise à jour
     du module ne modifiera jamais rétroactivement les classements d'une compétition déjà tirée.
     Tous les calculs se lisent dans <a href="<?= $SELEC_ROOT ?>classement.php">Classements et traçabilité</a>.</p>

  <!-- ── 1. Mode ─────────────────────────────────────────────────────────── -->
  <div class="carte">
    <h2><span>1. Mode de sélection</span><span class="sep"></span>
      <span class="sub"><?= htmlspecialchars($tour ? $tour['nom'] : '') ?></span></h2>
    <div class="corps">
      <?php if ($mode): ?>
        <p><span class="pill p-ok">rattachée</span>
           <b><?= htmlspecialchars($mode['libelle']) ?></b>
           — version <?= htmlspecialchars($cfg['version']) ?>,
           figée le <?= htmlspecialchars($cfg['date']) ?>.</p>
        <?php if (!empty($mode['reference'])): ?>
          <p class="sous" style="margin:0 0 8px">Référence : <?= htmlspecialchars($mode['reference']) ?></p>
        <?php endif; ?>

        <?php
        // Le règlement de la compétition est FIGÉ : une version plus récente du
        // mode ne s'applique pas toute seule. C'est voulu (un classement publié
        // ne doit pas bouger), mais il faut le dire, sinon l'opérateur cherche
        // pourquoi une correction livrée n'apparaît pas.
        $dispo = isset($catalogue[$cfg['mode']]) ? $catalogue[$cfg['mode']]['version'] : '';
        if ($dispo !== '' && version_compare($dispo, $cfg['version'], '>')): ?>
          <div class="info">Une version plus récente de ce règlement est livrée avec le module
            (<b><?= htmlspecialchars($dispo) ?></b>, contre <?= htmlspecialchars($cfg['version']) ?>
            ici). Elle ne s'applique <b>pas</b> toute seule : le règlement d'une compétition est figé
            pour que ses classements ne bougent jamais d'eux-mêmes. Pour l'adopter — par exemple
            après un changement de libellés ou une correction de barème — utilisez
            « Changer de mode (ré-ancrage) » ci-dessous, puis relancez le calcul.</div>
        <?php endif; ?>

        <?php if ($distMax > 0 && $tour && $tour['nb_dist'] < $distMax): ?>
          <div class="alerte"><b>Distances insuffisantes.</b> Ce mode a besoin de
            <?= $distMax ?> distances de qualification ; la compétition en déclare
            <?= intval($tour['nb_dist']) ?>. Les qualifications au-delà de la
            <?= intval($tour['nb_dist']) ?><sup>e</sup> distance resteront vides.
            Corrigez le type de compétition avant de saisir des scores.</div>
        <?php endif; ?>

        <?php foreach ((array) (isset($mode['points_ouverts']) ? $mode['points_ouverts'] : array()) as $po): ?>
          <div class="ouvert"><b><?= htmlspecialchars($po['titre']) ?></b>
            <span class="pill p-att"><?= htmlspecialchars($po['statut']) ?></span><br>
            <?= htmlspecialchars($po['detail']) ?></div>
        <?php endforeach; ?>

        <details class="carte" style="margin-top:10px;box-shadow:none">
          <summary style="background:#f7f7f7;color:#20263d;border-bottom:1px solid #d2d4d6">Changer de mode (ré-ancrage)</summary>
          <div class="corps">
            <p class="alerte">Ré-ancrer remplace le règlement figé de cette compétition.
               Tous les classements déjà calculés seront recalculés avec les nouvelles règles.
               L'opération est journalisée.</p>
            <?php include __DIR__ . '/inc/form-mode.php'; ?>
          </div>
        </details>
      <?php else: ?>
        <p><span class="pill p-nb">non rattachée</span>
           Cette compétition n'est encore associée à aucun mode de sélection.</p>
        <?php include __DIR__ . '/inc/form-mode.php'; ?>
      <?php endif; ?>
      <div class="msg" id="m-mode"></div>
    </div>
  </div>

  <?php if ($mode): ?>
  <!-- ── 2. Catégories ───────────────────────────────────────────────────── -->
  <div class="carte">
    <h2><span>2. Catégories traitées</span><span class="sep"></span>
      <span class="sub">une catégorie = une épreuve individuelle ianseo</span></h2>
    <div class="corps">
      <p class="sous" style="margin:0 0 8px">Chaque catégorie est classée séparément, à tous les
         niveaux. Le regroupement de classes et de divisions se fait dans ianseo, par la
         configuration des épreuves — le module s'y conforme sans rien redéfinir.
         <?php if ($liees): ?><br><?= count($liees) ?> épreuve(s) de duels créées pour les tournois
         ne figurent pas ici : elles portent les mêmes archers que leur catégorie d'origine et ne
         sont que des supports de matchs.<?php endif; ?></p>
      <div class="tbl-wrap">
      <table>
        <thead><tr>
          <th class="c">Traitée</th><th>Code</th><th>Épreuve</th><th class="n">Archers</th>
          <th class="n">Étapes calculées</th><th>Classements</th>
        </tr></thead>
        <tbody>
        <?php foreach ($categories as $code => $c):
            if (isset($liees[$code])) continue; // épreuve de duels, pas une catégorie
            $nbCalc = 0;
            $rs = safe_r_sql("SELECT COUNT(DISTINCT SrStep) n FROM SELEC_Results
                WHERE SrTournament=$SELEC_TOUR AND SrCategory=" . StrSafe_DB($code));
            if ($rs && ($r = safe_fetch($rs))) $nbCalc = intval($r->n);
            $active = in_array($code, $catsActives, true);
        ?>
          <tr>
            <td class="c"><input type="checkbox" class="cat-on" data-cat="<?= htmlspecialchars($code) ?>"
                <?= $active ? 'checked' : '' ?>></td>
            <td><code><?= htmlspecialchars($code) ?></code></td>
            <td><?= htmlspecialchars($c['nom']) ?></td>
            <td class="n"><?= $c['nb'] ?></td>
            <td class="n"><?= $nbCalc ?: '—' ?></td>
            <td><?php if ($nbCalc): ?>
                  <a href="<?= $SELEC_ROOT ?>classement.php?cat=<?= urlencode($code) ?>">voir</a>
                <?php else: ?><span class="tie">jamais calculé</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$categories): ?>
          <tr><td colspan="6"><span class="tie">Aucune épreuve individuelle avec des archers.</span></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      </div>
      <div class="msg" id="m-cat"></div>
    </div>
  </div>

  <!-- ── 3. Génération de la structure ianseo ───────────────────────────── -->
  <?php
  $catsPlan = $catsActives;
  $plan = selec_structure_plan($SELEC_TOUR, $mode, $catsPlan);
  $nSesACreer = 0; $nEvACreer = 0;
  foreach ($plan['sessions'] as $s) if ($s['etat'] !== 'existe') $nSesACreer++;
  foreach ($plan['epreuves'] as $e) if ($e['etat'] === 'à créer') $nEvACreer++;
  ?>
  <div class="carte">
    <h2><span>3. Structure ianseo</span><span class="sep"></span>
      <span class="sub"><?= count($plan['sessions']) ?> session(s), <?= count($plan['epreuves']) ?> épreuve(s) de duels</span></h2>
    <div class="corps">
      <p class="sous" style="margin:0 0 8px">Le module crée lui-même les <b>départs</b> et les
         <b>épreuves de duels</b> décrits par le règlement. Vous n'avez rien à saisir : vous ajustez
         seulement si votre organisation diffère. Rien n'est jamais écrasé — ce qui existe déjà est
         laissé tel quel, et aucun score n'est touché.</p>

      <?php foreach ($plan['alertes'] as $a): ?>
        <div class="alerte"><?= htmlspecialchars($a) ?></div>
      <?php endforeach; ?>

      <p style="margin:10px 0 4px"><span class="etape-t">Départs de qualification</span>
         <span class="famille">— une session par qualification, portant ses propres séries</span></p>
      <div class="tbl-wrap">
      <table>
        <thead><tr><th class="n">Session</th><th>Nom</th><th>Étape</th><th>Séries</th>
          <th class="n">Volées</th><th class="n">Flèches</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($plan['sessions'] as $s): ?>
          <tr>
            <td class="n"><?= $s['ordre'] ?></td>
            <td><?= htmlspecialchars($s['nom']) ?></td>
            <td><code><?= htmlspecialchars($s['etape']) ?></code></td>
            <td><?= implode(', ', $s['distances']) ?></td>
            <td class="n"><?= $s['volees'] ?></td>
            <td class="n"><?= $s['fleches'] ?></td>
            <td><span class="pill <?= $s['etat'] === 'existe' ? 'p-ok' : ($s['etat'] === 'à créer' ? 'p-inf' : 'p-att') ?>"><?= htmlspecialchars($s['etat']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$plan['sessions']): ?>
          <tr><td colspan="7"><span class="tie">Ce mode ne décrit aucun départ.</span></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      </div>
      <p class="sous" style="margin:6px 0 0">Les archers passent d'un départ au suivant avec
         <a href="<?= $CFG->ROOT_DIR ?>Partecipants/MoveSession.php">Déplacer une session</a> :
         seule leur affectation change, les scores déjà tirés restent en place puisque chaque départ
         écrit dans ses propres séries.</p>

      <?php if ($plan['epreuves']): ?>
      <p style="margin:14px 0 4px"><span class="etape-t">Épreuves de duels</span>
         <span class="famille">— tableau principal et consolante liée pour chaque tournoi</span></p>
      <details>
        <summary style="cursor:pointer;color:#0254a8"><?= count($plan['epreuves']) ?> épreuve(s) —
          <?= $nEvACreer ?> à créer</summary>
        <div class="tbl-wrap" style="margin-top:6px">
        <table>
          <thead><tr><th>Catégorie</th><th>Étape</th><th>Rôle</th><th>Code</th><th>Libellé</th><th>État</th></tr></thead>
          <tbody>
          <?php foreach ($plan['epreuves'] as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['categorie']) ?></td>
              <td><code><?= htmlspecialchars($e['etape']) ?></code></td>
              <td><?= htmlspecialchars($e['slot']) ?></td>
              <td><code><?= htmlspecialchars($e['code']) ?></code></td>
              <td><?= htmlspecialchars($e['nom']) ?></td>
              <td><span class="pill <?= $e['etat'] === 'existe' ? 'p-ok' : ($e['etat'] === 'à créer' ? 'p-inf' : 'p-att') ?>"><?= htmlspecialchars($e['etat']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </details>
      <?php endif; ?>

      <?php
      // ── Cibles et horaires des duels ──────────────────────────────────────
      $pl = selec_structure_planning_defauts($SELEC_TOUR, $mode, $catsPlan, $options);
      if ($pl['horaires']):
      ?>
      <p style="margin:14px 0 4px"><span class="etape-t">Cibles et horaires des duels</span>
         <span class="famille">— tournois et duels simulés ; vous ne donnez que l'heure de départ et la première cible</span></p>
      <p class="sous" style="margin:0 0 8px">Chaque duel dure
         <input type="number" id="d-duree" min="5" max="180" step="5" value="<?= intval($pl['duree']) ?>" style="width:60px">
         minutes, et le suivant enchaîne immédiatement. Un tournoi occupe un bloc de cibles
         du début à la fin : au premier tour les 8 archers y sont, puis la consolante récupère les
         cibles que le tableau principal libère. Les duels simulés occupent le même bloc, les archers
         rangés dans l'ordre du classement — le 1<sup>er</sup> sur la première cible — et gardent leur
         place d'un duel à l'autre. Un archer par cible, une cible par archer.</p>

      <div class="tbl-wrap">
      <table>
        <thead><tr><th>Étape</th><th>Journée</th><th class="n">Tours</th>
          <th>Date</th><th>Heure du 1<sup>er</sup> tour</th></tr></thead>
        <tbody>
        <?php foreach ($pl['horaires'] as $sid => $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['libelle']) ?></td>
            <td><?= htmlspecialchars(isset($mode['journees'][$h['journee']]) ? $mode['journees'][$h['journee']] : $h['journee']) ?></td>
            <td class="n"><?= intval($h['tours']) ?></td>
            <td><input type="date" class="d-date" data-step="<?= htmlspecialchars($sid) ?>"
                       value="<?= htmlspecialchars($h['date']) ?>"></td>
            <td><input type="time" class="d-heure" data-step="<?= htmlspecialchars($sid) ?>"
                       value="<?= htmlspecialchars(substr($h['heure'], 0, 5)) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="tbl-wrap" style="margin-top:8px">
      <table>
        <thead><tr><th>Catégorie</th><th class="n">Première cible</th><th>Bloc occupé</th></tr></thead>
        <tbody>
        <?php
        // Le bloc doit tenir la plus grosse étape à duels, tournoi OU duels
        // simulés : les deux occupent le même bloc de cibles.
        $effTab = 8;
        foreach ($mode['etapes'] as $stx) {
            if (in_array($stx['type'] ?? '', array('tournoi', 'duels_simules'), true)) {
                $effTab = max($effTab, intval($stx['structure']['effectif'] ?? 8));
            }
        }
        foreach ($pl['cibles'] as $cat => $t0): ?>
          <tr>
            <td><?= htmlspecialchars(isset($categories[$cat]) ? $categories[$cat]['nom'] : $cat) ?>
                <span class="tie">(<?= htmlspecialchars($cat) ?>)</span></td>
            <td class="n"><input type="number" class="d-cible" data-cat="<?= htmlspecialchars($cat) ?>"
                                 min="1" value="<?= intval($t0) ?>" style="width:70px"></td>
            <td class="tie">cibles <?= intval($t0) ?> à <?= intval($t0) + $effTab - 1 ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p style="margin:8px 0 0">
        <button class="btn" id="b-duels">Enregistrer et appliquer les cibles et horaires</button>
      </p>
      <div class="msg" id="m-duels"></div>
      <?php endif; ?>

      <p style="margin:14px 0 0">
        <label><input type="checkbox" id="g-ses" checked> les départs (<?= $nSesACreer ?> à créer ou corriger)</label>
        &nbsp;&nbsp;
        <label><input type="checkbox" id="g-ev" checked> les épreuves de duels (<?= $nEvACreer ?> à créer)</label>
      </p>
      <p style="margin:8px 0 0">
        <button class="btn primaire" id="b-struct">Générer la structure</button>
      </p>
      <div class="msg" id="m-struct"></div>

      <?php
      // ── Duels simulés, isolés ─────────────────────────────────────────────
      // Ils arrivent en fin d'épreuve, souvent bien après la première génération :
      // une commande à part évite de relancer toute la structure pour eux seuls.
      $nDuels = 0;
      foreach ($plan['epreuves'] as $e) {
          foreach ($mode['etapes'] as $stx) {
              if ($stx['id'] !== $e['etape']) continue;
              if (($stx['type'] ?? '') === 'duels_simules'
                  && (($stx['source']['type'] ?? '') === 'evenements')) $nDuels++;
          }
      }
      if ($nDuels):
      ?>
      <p style="margin:16px 0 4px"><span class="etape-t">Duels simulés</span>
         <span class="famille">— un tableau par duel, seul le premier tour se tire</span></p>
      <p class="sous" style="margin:0 0 8px">Chaque duel simulé est une épreuve de duels à part
         entière : les archers y sont appariés deux à deux, en cumul et non en sets, et les
         appariements tournent d'un duel à l'autre pour que personne ne rencontre deux fois le même
         adversaire. Aucune série de qualification n'est consommée. Le classement se fait au total
         des scores — la victoire ne compte pas.</p>
      <p style="margin:8px 0 0">
        <button class="btn" id="b-duelssim">Créer ou corriger les duels simulés seulement</button>
      </p>
      <div class="msg" id="m-duelssim"></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 4. Rattachement manuel ──────────────────────────────────────────── -->
  <?php if ($aLier): ?>
  <details class="carte">
    <summary><span>4. Rattachement des épreuves (ajustement manuel)</span><span class="sep"></span>
      <span class="sub"><?= count($aLier) ?> étape(s) par catégorie</span></summary>
    <div class="corps">
      <p class="sous" style="margin:0 0 8px">Rempli automatiquement par la génération ci-dessus.
         À n'ouvrir que pour corriger un cas particulier — par exemple si vous avez créé les épreuves
         vous-même, ou si un tournoi se joue dans une épreuve déjà existante. Le rôle
         <i>consolante</i> est l'épreuve liée (épreuve parente) qui reçoit les perdants du premier
         tour et attribue les places 5 à 8.</p>

      <?php foreach ($categories as $code => $c):
          if (!in_array($code, $catsActives, true)) continue; ?>
      <details class="carte" style="box-shadow:none">
        <summary style="background:#f0f4ff;color:#01367c;border-bottom:1px solid #d2d4d6">
          <?= htmlspecialchars($c['nom']) ?> <span class="sub">(<?= htmlspecialchars($code) ?>)</span>
        </summary>
        <div class="corps">
          <div class="tbl-wrap">
          <table>
            <thead><tr><th>Étape</th><th>Type</th><?php
              $slotsVus = array();
              foreach ($aLier as $a) foreach ($a['slots'] as $s) $slotsVus[$s] = true;
              foreach (array_keys($slotsVus) as $s) echo '<th>' . htmlspecialchars($s) . '</th>';
            ?></tr></thead>
            <tbody>
            <?php foreach ($aLier as $sid => $a): ?>
              <tr>
                <td><span class="etape-t"><?= htmlspecialchars($a['libelle']) ?></span></td>
                <td><span class="pill p-inf"><?= htmlspecialchars($a['type']) ?></span></td>
                <?php foreach (array_keys($slotsVus) as $slot):
                    $cur = isset($binds[$code][$sid][$slot]) ? $binds[$code][$sid][$slot] : ''; ?>
                  <td>
                    <?php if (in_array($slot, $a['slots'], true)): ?>
                      <select class="bind" data-cat="<?= htmlspecialchars($code) ?>"
                              data-step="<?= htmlspecialchars($sid) ?>" data-slot="<?= htmlspecialchars($slot) ?>">
                        <option value="">— aucune —</option>
                        <?php foreach ($categories as $ev => $e): ?>
                          <option value="<?= htmlspecialchars($ev) ?>" <?= $ev === $cur ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev . ' — ' . $e['nom']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?><span class="tie">—</span><?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>
      </details>
      <?php endforeach; ?>
      <div class="msg" id="m-bind"></div>
    </div>
  </details>
  <?php endif; ?>

  <!-- ── 5. Calcul ───────────────────────────────────────────────────────── -->
  <div class="carte">
    <h2><span>5. Calcul</span></h2>
    <div class="corps">
      <p class="sous" style="margin:0 0 8px">Le recalcul est intégral et idempotent : il relit les
         scores dans ianseo et réécrit tous les classements de la compétition. Le lancer deux fois
         de suite donne exactement le même résultat.</p>
      <button class="btn primaire" id="b-calc">Recalculer toutes les catégories</button>
      <div class="msg" id="m-calc"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var JETON = <?= json_encode(selec_token()) ?>;
  var URL   = <?= json_encode($SELEC_ROOT . 'action.php') ?>;

  function poster(data, cible, ok) {
    var m = document.getElementById(cible);
    if (m) m.innerHTML = 'Enregistrement<span class="selec-dots"><i></i><i></i><i></i></span>';
    data.jeton = JETON;
    var body = Object.keys(data).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');
    return fetch(URL, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (m) m.innerHTML = j.ok
          ? '<div class="info">' + (j.msg || 'Enregistré.') + '</div>'
          : '<div class="alerte">' + (j.err || 'Erreur.') + '</div>';
        if (j.ok && ok) ok(j);
        return j;
      })
      .catch(function (e) {
        if (m) m.innerHTML = '<div class="alerte">Erreur réseau : ' + e + '</div>';
      });
  }

  var f = document.getElementById('f-mode');
  if (f) f.addEventListener('submit', function (e) {
    e.preventDefault();
    poster({ action: 'ancrer', mode: f.querySelector('[name=mode]').value }, 'm-mode',
      function () { setTimeout(function () { location.reload(); }, 700); });
  });

  document.querySelectorAll('.bind').forEach(function (s) {
    s.addEventListener('change', function () {
      poster({ action: 'bind', cat: s.dataset.cat, step: s.dataset.step,
               slot: s.dataset.slot, event: s.value }, 'm-bind');
    });
  });

  document.querySelectorAll('.cat-on').forEach(function (c) {
    c.addEventListener('change', function () {
      var actives = [];
      document.querySelectorAll('.cat-on').forEach(function (x) { if (x.checked) actives.push(x.dataset.cat); });
      poster({ action: 'categories', cats: actives.join(',') }, 'm-cat');
    });
  });

  var bd = document.getElementById('b-duels');
  if (bd) bd.addEventListener('click', function () {
    var cibles = {}, horaires = {};
    document.querySelectorAll('.d-cible').forEach(function (i) { cibles[i.dataset.cat] = i.value; });
    document.querySelectorAll('.d-date').forEach(function (i) {
      horaires[i.dataset.step] = horaires[i.dataset.step] || {};
      horaires[i.dataset.step].date = i.value;
    });
    document.querySelectorAll('.d-heure').forEach(function (i) {
      horaires[i.dataset.step] = horaires[i.dataset.step] || {};
      horaires[i.dataset.step].heure = i.value;
    });
    bd.disabled = true;
    document.getElementById('m-duels').innerHTML =
      'Attribution<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: 'duels_reglages', appliquer: 1,
             duree: document.getElementById('d-duree').value,
             cibles: JSON.stringify(cibles), horaires: JSON.stringify(horaires) }, 'm-duels')
      .then(function () { bd.disabled = false; });
  });

  var bs = document.getElementById('b-struct');
  if (bs) bs.addEventListener('click', function () {
    var ses = document.getElementById('g-ses').checked;
    var ev  = document.getElementById('g-ev').checked;
    if (!confirm('Générer la structure dans ianseo ?\n\nLes départs et les épreuves manquants seront '
        + 'créés. Rien de ce qui existe déjà ne sera modifié, et aucun score ne sera touché.')) return;
    bs.disabled = true;
    document.getElementById('m-struct').innerHTML =
      'Création<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: 'structure', sessions: ses ? 1 : 0, epreuves: ev ? 1 : 0 }, 'm-struct',
      function () { setTimeout(function () { location.reload(); }, 1500); })
      .then(function () { bs.disabled = false; });
  });

  // Duels simulés seuls : ne touche ni aux départs ni aux tournois, et retire
  // au passage les épreuves de l'ancien format « tous contre tous ».
  var bd = document.getElementById('b-duelssim');
  if (bd) bd.addEventListener('click', function () {
    if (!confirm('Créer ou corriger les épreuves des duels simulés ?\n\n'
        + 'Les départs et les tournois ne sont pas touchés. Une épreuve qui porte déjà '
        + 'des scores est laissée telle quelle.')) return;
    bd.disabled = true;
    document.getElementById('m-duelssim').innerHTML =
      'Création<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: 'structure', duels: 1 }, 'm-duelssim',
      function () { setTimeout(function () { location.reload(); }, 1500); })
      .then(function () { bd.disabled = false; });
  });

  var b = document.getElementById('b-calc');
  if (b) b.addEventListener('click', function () {
    b.disabled = true;
    var m = document.getElementById('m-calc');
    m.innerHTML = 'Calcul en cours<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: 'calculer' }, 'm-calc').then(function () { b.disabled = false; });
  });
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
