<?php
/**
 * Guide FFTA — menu.php
 * Inclus sur TOUTES les pages par get_which_menu() dans Common/Menu.php.
 */

/* ---- Menu Modules ---- */
$ret['MODS']['GUIDE'][] = 'Guide FFTA';
$ret['MODS']['GUIDE'][] = 'Formations disponibles|' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/';
if (isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite) {
    $ret['MODS']['GUIDE'][] = 'Administration|' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/admin/';
}

/* ---- Injection du panneau (une seule fois par page) ---- */
if (!empty($GLOBALS['_guide_panel_done'])) return;
$GLOBALS['_guide_panel_done'] = true;

$_gr   = $CFG->ROOT_DIR;
$_gdir = dirname(__FILE__) . '/assets/';
$_gvc  = filemtime($_gdir . 'guide.css');
$_gvj  = filemtime($_gdir . 'guide.js');
?>
<link rel="stylesheet" href="<?= $_gr ?>Modules/Custom/GUIDE/assets/guide.css?v=<?= $_gvc ?>">

<div id="guide-panel" style="display:none">
  <div id="guide-panel-header">
    <button id="guide-panel-toggle-side" title="Déplacer">←</button>
    <span id="guide-panel-header-title">Guide FFTA</span>
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

<button id="guide-fab" style="display:none">🎯 Guide FFTA</button>

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
