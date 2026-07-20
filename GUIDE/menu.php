<?php
/**
 * Guide interactif — menu.php
 * Inclus sur TOUTES les pages par get_which_menu() dans Common/Menu.php.
 */

require_once(dirname(__FILE__) . '/lib/guide-lib.inc.php');

/* ---- Menu Modules ---- */
$ret['MODS']['GUIDE'][] = 'Guide interactif';
$ret['MODS']['GUIDE'][] = 'Formations disponibles|' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/';
// Avec un module de comptes, l'entrée Administration est réservée à la vue
// Administrateur serveur (authCheckACL accorde AclRoot à tout connecté)
if (isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite
    && (guide_current_user() === '' || !empty($_SESSION['AUTH_ROOT']))) {
    $ret['MODS']['GUIDE'][] = 'Administration|' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/admin/';
}

/* ---- Injection du panneau (une seule fois par page) ---- */
if (!empty($GLOBALS['_guide_panel_done'])) return;
$GLOBALS['_guide_panel_done'] = true;

guide_track_visit();   // conditions « page visitée » (ne touche la DB que sur les pages surveillées)

$_gr   = $CFG->ROOT_DIR;
$_gdir = dirname(__FILE__) . '/assets/';
$_gvc  = filemtime($_gdir . 'guide.css');
$_gvj  = filemtime($_gdir . 'guide.js');
?>
<script>
window.GUIDE_USER = <?= json_encode(guide_current_user(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.GUIDE_CTX  = <?= guide_current_user() !== '' ? (int)guide_pref_ctx() : 'null' ?>; // null = pas de compte → préférence localStorage
</script>
<link rel="stylesheet" href="<?= $_gr ?>Modules/Custom/GUIDE/assets/guide.css?v=<?= $_gvc ?>">

<div id="guide-panel" style="display:none">
  <div id="guide-panel-header">
    <button id="guide-panel-toggle-side" title="Déplacer">←</button>
    <span id="guide-panel-header-title">Guide interactif</span>
    <span id="guide-panel-header-btns">
      <button id="guide-panel-min"   title="Réduire">▁</button>
      <button id="guide-panel-max"   title="Agrandir">▢</button>
      <button id="guide-panel-close" title="Fermer la formation">✕</button>
    </span>
  </div>
  <div id="guide-panel-formation-name"></div>
  <div id="guide-panel-progress">
    <div id="guide-panel-progress-bar">
      <div id="guide-panel-progress-fill" style="width:0%"></div>
    </div>
    <span id="guide-panel-progress-text"></span>
  </div>
  <div id="guide-panel-step">
    <div id="guide-panel-step-image" style="display:none"></div>
    <div id="guide-panel-step-title"></div>
    <div id="guide-panel-step-content"></div>
    <div id="guide-panel-page-info" style="display:none"></div>
    <div id="guide-panel-condition-wait" style="display:none"></div>
  </div>
  <div id="guide-panel-validate">
    <button id="guide-btn-validate">☐ Marquer comme fait</button>
  </div>
  <div id="guide-panel-nav">
    <button id="guide-btn-prev" disabled>◀ Préc.</button>
    <button id="guide-btn-restart" title="Recommencer les indications de cette étape">🔄</button>
    <button id="guide-btn-back" title="Revenir à l'indication précédente">↶</button>
    <button id="guide-btn-next">Suivant ▶</button>
  </div>
</div>

<button id="guide-fab" style="display:none">🎯 Guide interactif</button>

<?php
/* Bannière "Apprendre" : page d'accueil ianseo + AUCUNE compétition visible (nouvel utilisateur).
   Avec le module de comptes, chaque compte ne voit que ses compétitions → la bannière s'adresse
   au compte qui n'en a encore aucune. Dès qu'une compétition est visible, elle disparaît. */
$_gIsHome = isset($_SERVER['SCRIPT_NAME'])
    && $_SERVER['SCRIPT_NAME'] === rtrim($CFG->ROOT_DIR, '/') . '/index.php';
$_gNoTour = $_gIsHome && guide_visible_tournament_count() === 0;
if ($_gNoTour):
?>
<div id="guide-learn-banner" style="display:none">
  <a href="<?= $_gr ?>Modules/Custom/GUIDE/">
    <span class="glb-emoji">🎯</span>
    <span class="glb-txt">
      <b>Apprendre à utiliser ianseo</b>
      <span>Formations interactives pas-à-pas, QCM et défis — Guide interactif</span>
    </span>
    <span class="glb-arrow">→</span>
  </a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var b = document.getElementById('guide-learn-banner');
  if (!b) return;
  var c = document.getElementById('Content') || document.body;
  c.insertBefore(b, c.firstChild);
  b.style.display = 'block';
});
</script>
<?php endif; ?>

<div id="guide-rec" style="display:none">
  <div id="guide-rec-header">
    <span class="guide-rec-dot"></span>
    <span id="guide-rec-title">Enregistrement</span>
    <button id="guide-rec-close" title="Abandonner l'enregistrement">✕</button>
  </div>
  <div id="guide-rec-hint">Cliquez sur les éléments à enregistrer comme triggers. La navigation est conservée.</div>
  <div id="guide-rec-list"></div>
  <div id="guide-rec-actions">
    <button id="guide-rec-pause" class="guide-rec-btn">⏸ Pause</button>
    <button id="guide-rec-page"  class="guide-rec-btn" title="Enregistrer la page courante comme condition d'état">📍 Page active</button>
    <button id="guide-rec-undo"  class="guide-rec-btn" title="Annuler le dernier trigger enregistré">↶ Annuler</button>
    <button id="guide-rec-done"  class="guide-rec-btn guide-rec-btn-done">✓ Terminer</button>
  </div>
</div>

<script src="<?= $_gr ?>Modules/Custom/GUIDE/assets/guide.js?v=<?= $_gvj ?>"></script>
<?php
unset($_gr, $_gdir, $_gvc, $_gvj);
?>
