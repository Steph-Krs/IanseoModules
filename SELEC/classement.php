<?php
/**
 * classement.php — tous les classements d'une catégorie, avec leur traçabilité.
 *
 * Le calcul est refait EN MÉMOIRE à chaque affichage : la page ne peut donc
 * jamais montrer un classement périmé après une correction de score dans ianseo.
 * Le bouton « Figer » écrit le résultat dans SELEC_Results (base des impressions
 * et de l'historique).
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$cfg = selec_config_lire($SELEC_TOUR);
if (!$cfg || !$cfg['snapshot']) {
    $PAGE_TITLE = 'Sélection';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
    echo '<div id="selec"><h1>Sélection Équipe de France</h1>'
       . '<div class="alerte">Cette compétition n\'est rattachée à aucun mode de sélection. '
       . '<a href="' . $SELEC_ROOT . 'index.php">Configurer</a>.</div></div>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

$mode  = $cfg['snapshot'];
$cats  = selec_categories_actives($SELEC_TOUR, $cfg);
$tousC = selec_categories($SELEC_TOUR);
$cat   = (string) ($_GET['cat'] ?? '');
if (!in_array($cat, $cats, true)) $cat = $cats ? $cats[0] : '';

$etatGel  = selec_arch_etat($SELEC_TOUR);
// Verrouillage ISK-NG, une lecture pour toute la page : la requête des sessions
// verrouillables est une union de cinq SELECT, inutile de la rejouer par étape.
$etatLock = selec_lock_etat($SELEC_TOUR, $mode);

$ctx = null;
if ($cat !== '') {
    $binds = selec_binds_lire($SELEC_TOUR, $cat);
    $ctx = selec_calculer($SELEC_TOUR, $cat, $mode, $binds);
    if (!empty($_GET['figer'])) {
        selec_enregistrer($ctx);
        $fige = true;
    }
}

/** Libellé de la famille de points d'une étape (jamais un libellé générique). */
function selec_famille_lib($mode, $st)
{
    if (empty($st['famille'])) return '';
    return isset($mode['familles'][$st['famille']]) ? $mode['familles'][$st['famille']] : $st['famille'];
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$PAGE_TITLE = 'Sélection — classements';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $SELEC_ROOT ?>assets/selec.css?v=<?= selec_version() ?>">
<div id="selec">
  <h1>Sélection Équipe de France — classements</h1>
  <p class="sous"><b><?= h($mode['libelle']) ?></b> — règlement figé le <?= h($cfg['date']) ?>.
     Les valeurs sont recalculées à chaque affichage depuis les scores ianseo :
     ce que vous lisez correspond toujours à l'état actuel de la saisie.</p>

  <div class="carte">
    <h2><span>Catégorie</span><span class="sep"></span>
      <span class="sub"><?= $ctx ? count($ctx['archers']) : 0 ?> archers</span></h2>
    <div class="corps">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <select name="cat" onchange="this.form.submit()">
          <?php foreach ($cats as $c): ?>
            <option value="<?= h($c) ?>" <?= $c === $cat ? 'selected' : '' ?>>
              <?= h(isset($tousC[$c]) ? $tousC[$c]['nom'] . ' (' . $c . ')' : $c) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn" type="submit">Afficher</button></noscript>
        <span class="sep"></span>
        <a class="btn" href="?cat=<?= urlencode($cat) ?>&amp;figer=1">Figer ce classement</a>
        <a class="btn" href="<?= $SELEC_ROOT ?>index.php">Configuration</a>
      </form>
      <?php if (!empty($fige)): ?>
        <div class="info">Classement enregistré dans la base (<?= date('d/m/Y H:i') ?>).</div>
      <?php endif; ?>
    </div>
  </div>

<?php if ($ctx): ?>

  <?php if ($ctx['alertes']): ?>
  <div class="carte">
    <h2><span>Points de vigilance</span><span class="sep"></span>
      <span class="sub"><?= count($ctx['alertes']) ?></span></h2>
    <div class="corps">
      <p class="sous" style="margin:0 0 6px">Rien n'est corrigé automatiquement : ce qui ne peut pas
         être tranché par le règlement est signalé ici et attend une décision.</p>
      <?php foreach ($ctx['alertes'] as $a): ?>
        <div class="alerte"><?= h($a) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // ── Les étapes, groupées par journée ────────────────────────────────────
  $parJournee = array();
  foreach ($mode['etapes'] as $st) {
      $j = isset($st['journee']) ? $st['journee'] : '';
      $parJournee[$j][] = $st;
  }
  ?>
  <!-- Un onglet par journée : la page porte facilement une centaine de tableaux,
       il faut pouvoir s'y repérer. -->
  <div class="jours" role="tablist">
    <?php $premierJour = true; foreach ($parJournee as $jour => $etapes):
        $libJour = isset($mode['journees'][$jour]) ? $mode['journees'][$jour] : ($jour ?: 'Autres'); ?>
      <button class="jour-tab<?= $premierJour ? ' actif' : '' ?>" data-jour="<?= h($jour) ?>"
              role="tab" aria-selected="<?= $premierJour ? 'true' : 'false' ?>"><?= h($libJour) ?></button>
    <?php $premierJour = false; endforeach; ?>
  </div>

  <?php $premierJour = true; foreach ($parJournee as $jour => $etapes):
      $libJour = isset($mode['journees'][$jour]) ? $mode['journees'][$jour] : ($jour ?: 'Autres');
  ?>
  <div class="jour-panneau<?= $premierJour ? '' : ' cache' ?>" data-jour="<?= h($jour) ?>">
    <?php $premierJour = false; ?>
      <?php foreach ($etapes as $st):
        $sid = $st['id'];
        $fam = selec_famille_lib($mode, $st);
        $type = $st['type'];
        if (empty($ctx['etapes'][$sid]['lignes'])) {
            echo '<div class="carte etape-vide"><h2><span>' . h($st['libelle']) . '</span>'
               . '<span class="sep"></span><span class="sub">pas encore de résultat</span></h2></div>';
            continue;
        }
        $lignes = $ctx['etapes'][$sid]['lignes'];
        // Tri par rang, puis par nom pour un affichage stable entre ex aequo.
        uasort($lignes, function ($a, $b) { return $a['rang'] <=> $b['rang']; });
        // Étapes sources de la coupure qui fige un classement : seules celles-là
        // sont renseignées pour un archer figé.
        $srcFige = array();
        if (!empty($st['fige_apres_coupure'])) {
            foreach ((array) $mode['etapes'] as $x) {
                if ($x['id'] === $st['fige_apres_coupure']) {
                    $srcFige = (array) ($x['sources'] ?? array());
                    break;
                }
            }
        }
      ?>
        <!-- Repliée par défaut : on ouvre l'étape qu'on regarde. -->
        <details class="carte etape" data-etape="<?= h($sid) ?>">
          <summary>
            <span><?= h($st['libelle']) ?></span>
            <span class="sep"></span>
            <?php if ($fam): ?><span class="sub">attribue les <?= h($fam) ?></span><?php endif; ?>
            <?php if (isset($etatGel[$sid])): ?><span class="sub">· verrouillée</span><?php endif; ?>
            <span class="sub">· <?= count($lignes) ?> archers</span>
          </summary>
          <div class="corps">
        <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th class="n">Rg</th><th>Archer</th>
            <?php if ($type === 'qualification' || $type === 'duels_simules'): ?>
              <th class="n">Score</th><th class="n">10</th><th class="n">X</th><th class="n">Flèches</th>
            <?php elseif ($type === 'tournoi'): ?>
              <th class="n">Place</th><th class="n">Pts&nbsp;clt</th>
              <th class="n">Sets</th><th class="n">Moy.&nbsp;set</th>
              <th class="n">Rg&nbsp;perf</th><th class="n">Pts&nbsp;perf</th><th class="n">Somme</th>
              <th class="n">V</th>
            <?php elseif ($type === 'poule'): ?>
              <th class="n">V</th><th class="n">Pts&nbsp;clt</th>
              <th class="n">Sets</th><th class="n">Moy.&nbsp;set</th>
              <th class="n">Rg&nbsp;perf</th><th class="n">Pts&nbsp;perf</th><th class="n">Somme</th>
            <?php else: ?>
              <?php foreach ((array) (isset($st['sources']) ? $st['sources'] : array()) as $s): ?>
                <th class="n"><?= h($s) ?></th>
              <?php endforeach; ?>
              <th class="n">Total</th>
            <?php endif; ?>
            <?php if (!empty($st['bareme'])): ?>
              <?php /* Jamais un « Points » générique : le règlement distingue des
                        familles qui ne se mélangent pas, la colonne les nomme. */ ?>
              <th class="n"><?= h($fam ?: 'Points') ?></th>
            <?php endif; ?>
            <th>Départage</th>
          </tr></thead>
          <tbody>
          <?php foreach ($lignes as $id => $l):
              $d = $l['detail'];
          ?>
            <tr>
              <td class="n"><?= $l['rang'] ?><?= $l['exaequo'] ? ' <span class="exaequo">=</span>' : '' ?></td>
              <td><?= h(selec_nom($ctx, $id)) ?></td>
              <?php if ($type === 'qualification' || $type === 'duels_simules'): ?>
                <td class="n"><?= intval($d['score'] ?? 0) ?></td>
                <td class="n"><?= intval($d['dix'] ?? 0) ?></td>
                <td class="n"><?= intval($d['x'] ?? 0) ?></td>
                <td class="n"><?= intval($d['fleches'] ?? 0) ?></td>
              <?php elseif ($type === 'tournoi'): ?>
                <td class="n"><?= intval($d['place'] ?? 0) ?: '—' ?></td>
                <td class="n"><?= selec_fmt_points($d['pts_clt'] ?? 0) ?></td>
                <td class="n"><?= intval($d['sets'] ?? 0) ?></td>
                <td class="n"><?= selec_fmt_frac($d['set_total'] ?? 0, $d['sets'] ?? 0) ?></td>
                <td class="n"><?= intval($d['rang_perf'] ?? 0) ?></td>
                <td class="n"><?= selec_fmt_points($d['pts_perf'] ?? 0) ?></td>
                <td class="n"><b><?= selec_fmt_points($d['somme'] ?? 0) ?></b></td>
                <td class="n"><?= intval($d['victoires'] ?? 0) ?></td>
              <?php elseif ($type === 'poule'): ?>
                <td class="n"><?= intval($d['victoires'] ?? 0) ?></td>
                <td class="n"><?= selec_fmt_points($d['pts_clt'] ?? 0) ?></td>
                <td class="n"><?= intval($d['sets'] ?? 0) ?></td>
                <td class="n"><?= selec_fmt_frac($d['set_total'] ?? 0, $d['sets'] ?? 0) ?></td>
                <td class="n"><?= intval($d['rang_perf'] ?? 0) ?></td>
                <td class="n"><?= selec_fmt_points($d['pts_perf'] ?? 0) ?></td>
                <td class="n"><b><?= selec_fmt_points($d['somme'] ?? 0) ?></b></td>
              <?php else: ?>
                <?php /* Classement figé à la coupure : les étapes que l'archer n'a
                          pas disputées restent vides. Un « 0 » se lirait comme un
                          résultat nul alors qu'il n'y a pas eu de tir. */ ?>
                <?php foreach ((array) (isset($st['sources']) ? $st['sources'] : array()) as $s): ?>
                  <td class="n"><?= (!empty($d['fige']) && !in_array($s, $srcFige, true))
                      ? '' : selec_fmt_points($d['par_source'][$s] ?? 0) ?></td>
                <?php endforeach; ?>
                <td class="n"><b><?= selec_fmt_points($d['total'] ?? 0) ?></b></td>
              <?php endif; ?>
              <?php if (!empty($st['bareme'])): ?>
                <td class="n"><b><?= selec_fmt_points($l['points_c']) ?></b></td>
              <?php endif; ?>
              <td class="tie"><?= $l['exaequo'] ? '<span class="exaequo">ex aequo</span>' : h($l['tie']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <?php
        // ── Les gestes qui suivent une étape ─────────────────────────────────
        // Imprimer ce qui vient d'être tiré, et préparer ce qui vient après.
        $suite = selec_prepa_cible($mode, $sid);
        $gelable = (bool) selec_arch_distances($st);
        $estGele = isset($etatGel[$sid]);
        $verifUrl = selec_verif_page($st);
        $lock = isset($etatLock[$sid]) ? $etatLock[$sid] : null;
        // Étape tirée en duels : ses feuilles de marque sont celles des matchs,
        // pas celles d'un round de qualification. `$gelable` distingue les deux —
        // une étape à distances se tire comme une qualification.
        $duels = !$gelable && in_array($st['type'], array('tournoi', 'poule', 'duels_simules'), true);
        ?>
        <p class="etape-actions">
          <?php if ($estGele): ?>
            <span class="pill p-ok" title="Scores, 10, X et flèches archivés le <?= h($etatGel[$sid]['date']) ?>">
              verrouillée — <?= intval($etatGel[$sid]['archers']) ?> archers archivés</span>
          <?php elseif ($gelable): ?>
            <span class="pill p-att">non verrouillée</span>
          <?php endif; ?>
          <a class="btn" target="_blank" rel="noopener"
             href="<?= $SELEC_ROOT ?>print.php?step=<?= urlencode($sid) ?>&amp;cat=<?= urlencode($cat) ?>">
            Imprimer le classement</a>
          <a class="btn" target="_blank" rel="noopener"
             href="<?= $SELEC_ROOT ?>print.php?step=<?= urlencode($sid) ?>">
            Toutes les catégories</a>
          <?php /* Feuilles de marque : toujours l'impression NATIVE de ianseo, sur
                   les scores en base. Elle montre le départ en cours — une étape
                   déjà verrouillée ne se réimprime donc pas telle qu'elle a été
                   tirée, c'est assumé : le classement, lui, reste figé par
                   l'archive, et c'est ce qui compte pour la sélection. */ ?>
          <?php if ($gelable): ?>
            <a class="btn" target="_blank" rel="noopener"
               href="<?= $CFG->ROOT_DIR ?>Qualification/PrintScore.php"
               title="Impression native de ianseo, sur les scores en cours">Feuilles de marque</a>
          <?php elseif ($duels): ?>
            <a class="btn" target="_blank" rel="noopener"
               href="<?= $CFG->ROOT_DIR ?>Final/Individual/PrintScore.php"
               title="Impression native de ianseo : feuilles de marque des duels">Feuilles de marque</a>
          <?php endif; ?>
          <?php if ($verifUrl): ?>
            <a class="btn" target="_blank" rel="noopener"
               href="<?= $CFG->ROOT_DIR . $verifUrl ?>"
               title="Contrôle des feuilles de marque par code-barres">Vérifier les feuilles</a>
          <?php endif; ?>
          <?php if ($lock): ?>
            <button class="btn verrou" data-step="<?= h($sid) ?>"
                    data-etat="<?= h($lock['etat']) ?>"
                    title="Ouvre ou ferme la saisie tablette des <?= intval($lock['total']) ?> session(s) de cette étape (ISK-NG)">
              <?= $lock['etat'] === 'tout' ? 'Ouvrir la saisie'
                  : ($lock['etat'] === 'partiel'
                     ? 'Verrouiller la saisie (' . intval($lock['verrouillees']) . '/' . intval($lock['total']) . ')'
                     : 'Verrouiller la saisie') ?></button>
          <?php endif; ?>
          <?php if ($suite): ?>
            <button class="btn primaire prepa" data-step="<?= h($sid) ?>"
                    data-suite="<?= h(isset($suite['libelle']) ? $suite['libelle'] : $suite['id']) ?>">
              Verrouiller et préparer :
              <?= h(isset($suite['libelle']) ? $suite['libelle'] : $suite['id']) ?>
            </button>
          <?php else: ?>
            <span class="tie">Dernière étape du règlement — rien à préparer ensuite.</span>
          <?php endif; ?>
          <?php if ($gelable): ?>
            <button class="btn gel" data-step="<?= h($sid) ?>"
                    title="Voir ce qui a changé depuis le verrouillage, et revenir en arrière">
              <?= $estGele ? 'Verrou et retour en arrière…' : 'Verrouiller seulement…' ?></button>
          <?php endif; ?>
        </p>

        <?php
        // Cascade de départage réellement appliquée — elle se règle dans le mode
        // (`departage` de l'étape), et se lit ici pour que personne n'ait à
        // deviner ce qui a séparé deux archers.
        $casc = array();
        foreach ((array) ($st['departage'] ?? array()) as $dp) {
            $cc = isset($dp['c']) ? $dp['c'] : '';
            if ($cc === 'egalite') { $casc[] = 'égalité conservée'; break; }
            $cr = selec_critere($ctx, $dp, $sid);
            if ($cr) $casc[] = $cr['label'];
        }
        if ($casc): ?>
          <p class="sous" style="margin:6px 0 0">Départage&nbsp;: <?= h(implode(' → ', $casc)) ?>.</p>
        <?php endif; ?>

        <div class="prepa-zone" id="prepa-<?= h($sid) ?>"></div>
        <div class="prepa-zone" id="gel-<?= h($sid) ?>"></div>
          </div>
        </details>
      <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <!-- ── Statistiques par archer ──────────────────────────────────────────── -->
  <?php
  // Périmètres : toutes les étapes de qualification d'une part, toutes celles à
  // duels d'autre part. Déduits du mode, jamais codés en dur.
  $etQ = array(); $etD = array();
  foreach ($mode['etapes'] as $st) {
      if ($st['type'] === 'qualification') $etQ[] = $st['id'];
      elseif (in_array($st['type'], array('tournoi', 'poule', 'duels_simules'), true)) $etD[] = $st['id'];
  }
  ?>
  <div class="carte">
    <h2><span>Statistiques par archer</span><span class="sep"></span>
      <span class="sub">qualifications, duels, ensemble</span></h2>
    <div class="corps">
      <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th rowspan="2">Archer</th>
            <th colspan="3" class="c">Qualifications</th>
            <th colspan="4" class="c">Duels</th>
            <th colspan="2" class="c">Ensemble</th>
          </tr>
          <tr>
            <th class="n">Nb</th><th class="n">Moyenne</th><th class="n">Val. flèche</th>
            <th class="n">Matchs</th><th class="n">Victoires</th>
            <th class="n">Moy. volée</th><th class="n">Val. flèche</th>
            <th class="n">Flèches</th><th class="n">Val. flèche</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $stats = array();
        foreach ($ctx['archers'] as $id => $a) {
            $q = selec_contrib_somme($ctx, $id, $etQ);
            $d = selec_contrib_somme($ctx, $id, $etD);
            $nbQ = 0;
            foreach ($etQ as $s) {
                if (!empty($ctx['etapes'][$s]['contrib'][$id]['fleches'])) $nbQ++;
            }
            $stats[$id] = array('q' => $q, 'd' => $d, 'nbq' => $nbQ,
                'tot' => $q['score'] + $d['score'], 'fl' => $q['fleches'] + $d['fleches']);
        }
        // Tri par valeur de flèche globale, décroissante (fraction exacte).
        uasort($stats, function ($a, $b) {
            return selec_cmp(selec_v_frac($b['tot'], $b['fl']), selec_v_frac($a['tot'], $a['fl']));
        });
        foreach ($stats as $id => $s):
            $q = $s['q']; $d = $s['d'];
        ?>
          <tr>
            <td><?= h(selec_nom($ctx, $id)) ?></td>
            <?php /* Moyennes (qualification, volée de duel) à 4 décimales, valeurs de
                      flèche à 6 : ce sont des critères de départage, il faut voir où
                      ça se joue. */ ?>
            <td class="n"><?= $s['nbq'] ?: '—' ?></td>
            <td class="n"><?= $s['nbq'] ? selec_fmt_frac($q['score'], $s['nbq'], 4) : '—' ?></td>
            <td class="n"><?= $q['fleches'] ? selec_fmt_frac($q['score'], $q['fleches'], 6) : '—' ?></td>
            <td class="n"><?= $d['matchs'] ?: '—' ?></td>
            <td class="n"><?= $d['matchs'] ? $d['victoires'] : '—' ?></td>
            <td class="n"><?= $d['sets'] ? selec_fmt_frac($d['set_total'], $d['sets'], 4) : '—' ?></td>
            <td class="n"><?= $d['fleches'] ? selec_fmt_frac($d['score'], $d['fleches'], 6) : '—' ?></td>
            <td class="n"><?= $s['fl'] ?: '—' ?></td>
            <td class="n"><b><?= $s['fl'] ? selec_fmt_frac($s['tot'], $s['fl'], 6) : '—' ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p class="sous" style="margin:8px 0 0">« Moy. volée » est la moyenne d'un set de 3 flèches sur
         l'ensemble des duels ; « Val. flèche » est le total marqué divisé par le nombre de flèches
         réellement tirées. Les flèches de barrage ne comptent dans aucune des deux — elles ne font
         pas partie du match au sens du règlement.</p>
    </div>
  </div>

<?php endif; ?>
</div>

<script>
(function () {
  var JETON = <?= json_encode(selec_token()) ?>;
  var URL   = <?= json_encode($SELEC_ROOT . 'action.php') ?>;
  var CLE   = 'selec_vue_<?= (int) $SELEC_TOUR ?>_<?= h($cat) ?>';

  // ── Onglets par journée + étapes repliables ─────────────────────────────
  // Chaque action recharge la page : sans mémoire, l'opérateur retomberait à
  // chaque fois sur le premier onglet, replié.
  function vueLire() {
    try { return JSON.parse(localStorage.getItem(CLE) || '{}') || {}; } catch (e) { return {}; }
  }
  function vueEcrire(v) { try { localStorage.setItem(CLE, JSON.stringify(v)); } catch (e) {} }

  function activerJour(j) {
    document.querySelectorAll('.jour-tab').forEach(function (t) {
      var on = (t.dataset.jour === j);
      t.classList.toggle('actif', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('.jour-panneau').forEach(function (p) {
      p.classList.toggle('cache', p.dataset.jour !== j);
    });
  }

  var vue = vueLire();
  if (vue.jour && document.querySelector('.jour-tab[data-jour="' + vue.jour + '"]')) activerJour(vue.jour);
  (vue.etapes || []).forEach(function (sid) {
    var d = document.querySelector('details.etape[data-etape="' + sid + '"]');
    if (d) d.open = true;
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('.jour-tab') : null;
    if (!t) return;
    e.preventDefault();
    activerJour(t.dataset.jour);
    var v = vueLire(); v.jour = t.dataset.jour; vueEcrire(v);
  });

  // L'événement `toggle` ne remonte pas : on l'écoute en phase de capture.
  document.addEventListener('toggle', function (e) {
    if (!e.target.classList || !e.target.classList.contains('etape')) return;
    var v = vueLire();
    var l = (v.etapes || []).filter(function (x) { return x !== e.target.dataset.etape; });
    if (e.target.open) l.push(e.target.dataset.etape);
    v.etapes = l; vueEcrire(v);
  }, true);

  function poster(data) {
    data.jeton = JETON;
    var body = Object.keys(data).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');
    return fetch(URL, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); });
  }

  function ech(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function rendre(zone, step, plan) {
    if (!plan.ok) {
      zone.innerHTML = '<div class="alerte">' + ech(plan.bloquant || 'Préparation impossible.') + '</div>';
      return;
    }
    var h = '<div class="carte prepa-carte"><h2><span>Préparer : ' + ech(plan.cible_lib) + '</span>'
          + '<span class="sep"></span><span class="sub">' + plan.lignes.length + ' archer(s)</span></h2>'
          + '<div class="corps">';

    h += '<p class="sous" style="margin:0 0 8px">Classement de référence : <label>'
       + '<select class="prepa-base" data-step="' + ech(step) + '">';
    Object.keys(plan.bases).forEach(function (k) {
      h += '<option value="' + ech(k) + '"' + (k === plan.base ? ' selected' : '') + '>'
         + ech(plan.bases[k]) + '</option>';
    });
    h += '</select></label></p>';

    (plan.alertes || []).forEach(function (a) { h += '<div class="alerte">' + ech(a) + '</div>'; });

    if (plan.type === 'session') {
      // Choix explicite : quelles catégories, et sur quelle plage de places.
      // L'enchaînement proposé remplit les cibles sans trou — la dernière cible
      // d'une catégorie peut être complétée par la suivante.
      h += '<p class="sous" style="margin:0 0 6px">Départ n° ' + ech(plan.session)
         + ' — cibles ' + ech(plan.cible_min) + ' à ' + ech(plan.cible_max)
         + ', ' + ech(plan.par_cible) + ' place(s) par cible (lettres '
         + plan.lettres.join(', ') + '). Une plage se note « cible + lettre », par exemple '
         + ech(plan.cible_min) + 'A.</p>';
      h += '<div class="tbl-wrap"><table><thead><tr>'
         + '<th class="c">Replacer</th><th>Catégorie</th><th class="n">Archers</th>'
         + '<th>De</th><th>À</th><th class="n">Places</th></tr></thead><tbody>';
      (plan.categories || []).forEach(function (c) {
        var manque = c.actif && c.capacite < c.nb;
        h += '<tr><td class="c"><input type="checkbox" class="prepa-cat" data-cat="' + ech(c.code) + '"'
           + (c.actif ? ' checked' : '') + '></td>'
           + '<td>' + ech(c.nom) + ' <span class="tie">(' + ech(c.code) + ')</span></td>'
           + '<td class="n">' + ech(c.nb) + '</td>'
           + '<td><input type="text" size="5" class="prepa-de" data-cat="' + ech(c.code)
           + '" value="' + ech(c.de) + '"></td>'
           + '<td><input type="text" size="5" class="prepa-a" data-cat="' + ech(c.code)
           + '" value="' + ech(c.a) + '"></td>'
           + '<td class="n"' + (manque ? ' style="color:#a80000;font-weight:bold"' : '') + '>'
           + ech(c.capacite) + '</td></tr>';
      });
      h += '</tbody></table></div>';
      h += '<p style="margin:6px 0 0"><button class="btn prepa-recalc" data-step="' + ech(step)
         + '">Recalculer le placement</button></p>';
    }

    h += '<div class="tbl-wrap" style="margin-top:8px"><table><thead><tr>';
    if (plan.type === 'session') {
      h += '<th class="n">Rg</th><th>Catégorie</th><th>Archer</th><th>Club</th><th>Cible</th>';
    } else {
      h += '<th class="n">Tête de série</th><th>Catégorie</th><th>Archer</th>'
         + '<th class="n">Rg de référence</th><th>Épreuve</th>';
    }
    h += '</tr></thead><tbody>';
    plan.lignes.forEach(function (l) {
      h += '<tr>';
      if (plan.type === 'session') {
        h += '<td class="n">' + (l.rang >= 99999 ? '—' : l.rang) + '</td>'
           + '<td>' + ech(l.cat) + '</td><td>' + ech(l.nom) + '</td><td>' + ech(l.club) + '</td>'
           + '<td class="n">' + (l.cible ? ech(l.cible + l.lettre) : '<span class="tie">aucune place</span>') + '</td>';
      } else {
        h += '<td class="n"><b>' + ech(l.serie) + '</b></td><td>' + ech(l.cat) + '</td>'
           + '<td>' + ech(l.nom) + (l.exaequo ? ' <span class="exaequo">=</span>' : '') + '</td>'
           + '<td class="n">' + ech(l.rang) + '</td><td><code>' + ech(l.event) + '</code></td>';
      }
      h += '</tr>';
    });
    h += '</tbody></table></div>';

    h += '<p style="margin:10px 0 0">'
       + '<button class="btn primaire prepa-go" data-step="' + ech(step) + '">'
       + (plan.type === 'session'
            ? 'Placer les archers sur le départ n° ' + ech(plan.session)
            : 'Valider les qualifications et générer les tableaux')
       + '</button> <button class="btn prepa-annuler">Annuler</button></p>';
    h += '<div class="msg"></div></div></div>';
    zone.innerHTML = h;
  }

  // Les plages saisies dans le panneau, telles quelles.
  function plagesDe(zone) {
    var p = {};
    zone.querySelectorAll('.prepa-cat').forEach(function (c) {
      var cat = c.dataset.cat;
      var de = zone.querySelector('.prepa-de[data-cat="' + cat + '"]');
      var a  = zone.querySelector('.prepa-a[data-cat="' + cat + '"]');
      p[cat] = { actif: c.checked ? 1 : 0, de: de ? de.value : '', a: a ? a.value : '' };
    });
    return Object.keys(p).length ? JSON.stringify(p) : '';
  }

  function charger(zone, step, base, plages) {
    zone.innerHTML = 'Analyse<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: 'prepa_plan', step: step, base: base || '', plages: plages || '' })
      .then(function (j) {
        if (!j.ok) { zone.innerHTML = '<div class="alerte">' + ech(j.err) + '</div>'; return; }
        rendre(zone, step, j.plan);
      })
      .catch(function (e) { zone.innerHTML = '<div class="alerte">Erreur réseau : ' + ech(e) + '</div>'; });
  }

  // ── Verrou et retour en arrière ─────────────────────────────────────────
  function rendreGel(zone, step, ec) {
    var gele = ec.date && ec.archers > 0;
    var h = '<div class="carte prepa-carte"><h2><span>Verrou de l\'étape ' + ech(step) + '</span>'
          + '<span class="sep"></span><span class="sub">'
          + (gele ? ech(ec.archers) + ' archers archivés le ' + ech(ec.date)
                    + (ec.user ? ' par ' + ech(ec.user) : '')
                  : 'étape non verrouillée')
          + '</span></h2><div class="corps">';

    if (!gele) {
      h += '<p class="sous" style="margin:0 0 8px">Verrouiller recopie les scores, les 10, les X '
         + 'et les flèches de cette étape. Une fois verrouillée, elle n\'est plus relue dans ianseo : '
         + 'son classement ne peut plus bouger, même si une saisie ultérieure réécrit la ligne d\'un '
         + 'archer. C\'est ce que fait aussi le bouton « Verrouiller et préparer ».</p>'
         + '<p><button class="btn primaire gel-go" data-step="' + ech(step) + '">'
         + 'Verrouiller cette étape</button> <button class="btn prepa-annuler">Fermer</button></p>';
    } else {
      if (ec.ecarts.length === 0) {
        h += '<div class="info">Ce qui est en base est <b>identique</b> à l\'archive : rien n\'a '
           + 'été modifié depuis le verrouillage.</div>';
      } else {
        h += '<div class="alerte"><b>' + ec.ecarts.length + ' écart(s)</b> entre l\'archive et ce '
           + 'que contient ianseo aujourd\'hui. Le classement affiché utilise l\'archive.</div>';
        h += '<div class="tbl-wrap"><table><thead><tr><th>Archer</th><th>Donnée</th>'
           + '<th>Archivé (fait foi)</th><th>Actuellement en base</th></tr></thead><tbody>';
        ec.ecarts.forEach(function (x) {
          h += '<tr><td>' + ech(x.nom) + '</td><td>' + ech(x.quoi) + '</td>'
             + '<td><b>' + ech(x.gele) + '</b></td><td>' + ech(x.actuel) + '</td></tr>';
        });
        h += '</tbody></table></div>';
      }
      h += '<p style="margin:10px 0 4px"><label><input type="checkbox" class="gel-placement"> '
         + 'remettre aussi les départs et les cibles d\'origine</label></p>';
      h += '<p style="margin:6px 0 0">'
         + '<button class="btn gel-restaurer" data-step="' + ech(step) + '">'
         + 'Restaurer les valeurs archivées dans ianseo</button> '
         + '<button class="btn gel-refaire" data-step="' + ech(step) + '">'
         + 'Re-verrouiller sur les valeurs actuelles</button> '
         + '<button class="btn gel-degeler" data-step="' + ech(step) + '" '
         + 'style="border-color:#bb7575;color:#a80000">Retirer le verrou</button> '
         + '<button class="btn prepa-annuler">Fermer</button></p>'
         + '<p class="sous" style="margin:6px 0 0">« Restaurer » réécrit les scores archivés dans '
         + 'ianseo. « Re-verrouiller » remplace l\'archive par ce qui est en base — à n\'utiliser '
         + 'qu\'après une correction de score assumée. « Retirer le verrou » supprime l\'archive '
         + 'sans toucher aux scores : l\'étape redevient relue dans ianseo.</p>';
    }
    h += '<div class="msg"></div></div></div>';
    zone.innerHTML = h;
  }

  function chargerGel(zone, step) {
    zone.innerHTML = 'Comparaison<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: 'gel_etat', step: step })
      .then(function (j) {
        if (!j.ok) { zone.innerHTML = '<div class="alerte">' + ech(j.err) + '</div>'; return; }
        rendreGel(zone, step, j.ecarts);
      })
      .catch(function (e) { zone.innerHTML = '<div class="alerte">Erreur réseau : ' + ech(e) + '</div>'; });
  }

  function actionGel(btn, action, question) {
    var zone = btn.closest('.prepa-zone');
    var pl = zone.querySelector('.gel-placement');
    if (!confirm(question)) return;
    btn.disabled = true;
    var msg = zone.querySelector('.msg');
    msg.innerHTML = 'En cours<span class="selec-dots"><i></i><i></i><i></i></span>';
    poster({ action: action, step: btn.dataset.step, placement: (pl && pl.checked) ? 1 : 0 })
      .then(function (j) {
        msg.innerHTML = j.ok ? '<div class="info">' + j.msg + '</div>'
                             : '<div class="alerte">' + j.err + '</div>';
        btn.disabled = false;
        if (j.ok) setTimeout(function () { location.reload(); }, 2500);
      })
      .catch(function (err) {
        msg.innerHTML = '<div class="alerte">Erreur réseau : ' + ech(err) + '</div>';
        btn.disabled = false;
      });
  }

  // ── Verrouillage ISK-NG d'une étape ────────────────────────────────────────
  // Le bouton porte l'état courant : « partiel » se referme (on verrouille tout),
  // « tout » s'ouvre. Le libellé se met à jour sans recharger la page — l'intérêt
  // du bouton est justement de ne pas quitter le classement.
  function basculerVerrou(btn) {
    var sens = (btn.dataset.etat === 'tout') ? 'unlock' : 'lock';
    var texte = btn.textContent;
    btn.disabled = true;
    btn.textContent = '…';
    poster({ action: 'sessions_bascule', step: btn.dataset.step, sens: sens })
      .then(function (j) {
        btn.disabled = false;
        if (!j.ok) { btn.textContent = texte; alert(j.err || 'Bascule impossible.'); return; }
        btn.dataset.etat = j.etat;
        btn.textContent = (j.etat === 'tout') ? 'Ouvrir la saisie' : 'Verrouiller la saisie';
        btn.title = j.msg;
      })
      .catch(function (err) {
        btn.disabled = false; btn.textContent = texte;
        alert('Erreur réseau : ' + err);
      });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.prepa') : null;
    if (b) {
      e.preventDefault();
      charger(document.getElementById('prepa-' + b.dataset.step), b.dataset.step, '');
      return;
    }

    var vb = e.target.closest ? e.target.closest('.verrou') : null;
    if (vb) { e.preventDefault(); basculerVerrou(vb); return; }

    var gb = e.target.closest ? e.target.closest('.gel') : null;
    if (gb) { e.preventDefault(); chargerGel(document.getElementById('gel-' + gb.dataset.step), gb.dataset.step); return; }

    var gg = e.target.closest ? e.target.closest('.gel-go') : null;
    if (gg) { e.preventDefault(); actionGel(gg, 'gel_geler',
      'Verrouiller cette étape ?\n\nSes scores et ses flèches seront archivés et ne bougeront plus.'); return; }

    var gr = e.target.closest ? e.target.closest('.gel-refaire') : null;
    if (gr) { e.preventDefault(); actionGel(gr, 'gel_geler',
      'Remplacer l\'archive par les valeurs actuellement en base ?\n\n'
      + 'À ne faire qu\'après une correction de score volontaire.'); return; }

    var gx = e.target.closest ? e.target.closest('.gel-restaurer') : null;
    if (gx) { e.preventDefault(); actionGel(gx, 'gel_restaurer',
      'Réécrire dans ianseo les scores archivés de cette étape ?\n\n'
      + 'Ce qui est actuellement en base pour ces séries sera remplacé.'); return; }

    var gd = e.target.closest ? e.target.closest('.gel-degeler') : null;
    if (gd) { e.preventDefault(); actionGel(gd, 'gel_degeler',
      'Retirer le verrou ?\n\nL\'archive est supprimée et l\'étape redevient lue dans ianseo. '
      + 'Les scores en base ne sont pas touchés.'); return; }
    var a = e.target.closest ? e.target.closest('.prepa-annuler') : null;
    if (a) { e.preventDefault(); a.closest('.prepa-zone').innerHTML = ''; return; }

    var rc = e.target.closest ? e.target.closest('.prepa-recalc') : null;
    if (rc) {
      e.preventDefault();
      var z = rc.closest('.prepa-zone');
      var b = z.querySelector('.prepa-base') ? z.querySelector('.prepa-base').value : '';
      charger(z, rc.dataset.step, b, plagesDe(z));
      return;
    }

    var g = e.target.closest ? e.target.closest('.prepa-go') : null;
    if (g) {
      e.preventDefault();
      var zone = g.closest('.prepa-zone');
      var base = zone.querySelector('.prepa-base') ? zone.querySelector('.prepa-base').value : '';
      if (!confirm('Confirmer la préparation ?\n\nLes scores déjà tirés ne sont jamais modifiés.')) return;
      g.disabled = true;
      var msg = zone.querySelector('.msg');
      msg.innerHTML = 'Application<span class="selec-dots"><i></i><i></i><i></i></span>';
      poster({ action: 'prepa_appliquer', step: g.dataset.step, base: base, plages: plagesDe(zone) })
        .then(function (j) {
          msg.innerHTML = j.ok ? '<div class="info">' + j.msg + '</div>'
                               : '<div class="alerte">' + j.err + '</div>';
          g.disabled = false;
          if (j.ok) setTimeout(function () { location.reload(); }, 2500);
        })
        .catch(function (err) {
          msg.innerHTML = '<div class="alerte">Erreur réseau : ' + ech(err) + '</div>';
          g.disabled = false;
        });
    }
  });

  document.addEventListener('change', function (e) {
    if (!e.target.classList) return;
    var zone = e.target.closest ? e.target.closest('.prepa-zone') : null;
    if (!zone) return;
    // Changer le classement de référence repart des plages proposées ; cocher
    // une catégorie ou modifier une plage conserve la saisie en cours.
    if (e.target.classList.contains('prepa-base')) {
      charger(zone, e.target.dataset.step, e.target.value, '');
    } else if (e.target.classList.contains('prepa-cat')
            || e.target.classList.contains('prepa-de')
            || e.target.classList.contains('prepa-a')) {
      var b = zone.querySelector('.prepa-base') ? zone.querySelector('.prepa-base').value : '';
      var step = zone.id.replace(/^prepa-/, '');
      charger(zone, step, b, plagesDe(zone));
    }
  });
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
