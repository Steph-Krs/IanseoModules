<?php
/**
 * Menu entries and page furniture of the Interactive Guide module.
 *
 * get_which_menu() in Common/Menu.php includes this file on EVERY ianseo page,
 * which is what lets the guide panel follow the user through the software. It is
 * also what makes this the most dangerous file in the module: an SQL error here
 * does not break the guide, it makes the whole installation unreachable, because
 * safe_error() answers 404 and calls exit() with nothing able to catch it.
 *
 * The rules this file follows, none of which may be relaxed:
 *   - it never creates a table (guide_ensure_schema() is for the module's own
 *     pages), and never reads one without knowing it exists;
 *   - its single write, the page-visit record, is guarded and non-fatal — see
 *     guide_track_visit();
 *   - the panel markup is emitted once per page, guarded by a global flag,
 *     because get_which_menu() can be reached more than once.
 *
 * What it emits: the Modules menu entries, the side panel, the floating button,
 * the trigger recorder used by the course editor, and — for an account that has
 * no competition yet — a banner inviting the user to learn ianseo.
 */

require_once(dirname(__FILE__) . '/lib/guide-lib.inc.php');

$_guideUrl = function_exists('cmod_url')
    ? cmod_url(__DIR__)
    : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/';

/* ---- Modules menu ---- */

$ret['MODS']['GUIDE'][] = guide_text('ModuleName');
$ret['MODS']['GUIDE'][] = guide_text('MenuCourses') . '|' . $_guideUrl;

// Administration is restricted to the server administrator view when an account
// module is installed: its authCheckACL() grants AclRoot to every signed-in
// organiser on pages outside a competition, so subFeatureAcl alone would show
// this entry to all of them. Reading $_SESSION directly keeps the module
// independent of any particular account module.
if (isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite
    && (guide_current_user() === '' || !empty($_SESSION['AUTH_ROOT']))) {
    $ret['MODS']['GUIDE'][] = guide_text('MenuAdmin') . '|' . $_guideUrl . 'admin/';
}

/* ---- Page furniture, once per page ---- */

if (!empty($GLOBALS['_guide_panel_done'])) return;
$GLOBALS['_guide_panel_done'] = true;

// Records the visit only when a course condition watches this page, and only
// when the module's tables already exist.
guide_track_visit();

$_gAssets = __DIR__ . '/assets/';
$_gCss    = $_guideUrl . 'assets/guide.css' . cmod_asset_version($_gAssets . 'guide.css');
$_gJs     = $_guideUrl . 'assets/guide.js'  . cmod_asset_version($_gAssets . 'guide.js');
?>
<script>
/* Published for assets/guide.js: the whole course player runs in the browser, so
   its wording cannot go through guide_text() at render time. */
window.GUIDE_USER = <?= json_encode(guide_current_user(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.GUIDE_T    = <?= json_encode(guide_js_strings(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
/* null means "no account module": the client then keeps the preference in
   localStorage instead of on the server. */
window.GUIDE_CTX  = <?= guide_current_user() !== '' ? (int)guide_pref_ctx() : 'null' ?>;
</script>
<link rel="stylesheet" href="<?= htmlspecialchars($_gCss) ?>">

<div id="guide-panel" style="display:none">
  <div id="guide-panel-header">
    <button id="guide-panel-toggle-side" title="<?= htmlspecialchars(guide_text('PanelMove')) ?>">←</button>
    <span id="guide-panel-header-title"><?= htmlspecialchars(guide_text('ModuleName')) ?></span>
    <span id="guide-panel-header-btns">
      <button id="guide-panel-min"   title="<?= htmlspecialchars(guide_text('PanelMinimise')) ?>">▁</button>
      <button id="guide-panel-max"   title="<?= htmlspecialchars(guide_text('PanelMaximise')) ?>">▢</button>
      <button id="guide-panel-close" title="<?= htmlspecialchars(guide_text('PanelCloseCourse')) ?>">✕</button>
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
    <button id="guide-btn-validate">☐ <?= htmlspecialchars(guide_text('CmdMarkDone')) ?></button>
  </div>
  <div id="guide-panel-nav">
    <button id="guide-btn-prev" disabled>◀ <?= htmlspecialchars(guide_text('CmdPrev')) ?></button>
    <button id="guide-btn-restart" title="<?= htmlspecialchars(guide_text('CmdRestartHints')) ?>">🔄</button>
    <button id="guide-btn-back"    title="<?= htmlspecialchars(guide_text('CmdBackHint')) ?>">↶</button>
    <button id="guide-btn-next"><?= htmlspecialchars(guide_text('CmdNextStep')) ?> ▶</button>
  </div>
</div>

<button id="guide-fab" style="display:none">🎯 <?= htmlspecialchars(guide_text('ModuleName')) ?></button>

<?php
/* Banner shown on the ianseo home page when the current account can see NO
   competition at all — the sign of a brand new user. That is not the same test
   as "no competition is currently open": an organiser between two events must
   not be told to start learning. With an account module the visibility follows
   AUTH_COMP, so each account is judged on its own competitions. The COUNT runs
   on the home page only. */
$_gIsHome = isset($_SERVER['SCRIPT_NAME'])
    && $_SERVER['SCRIPT_NAME'] === rtrim($CFG->ROOT_DIR, '/') . '/index.php';

if ($_gIsHome && guide_visible_tournament_count() === 0):
?>
<div id="guide-learn-banner" style="display:none">
  <a href="<?= htmlspecialchars($_guideUrl) ?>">
    <span class="glb-emoji">🎯</span>
    <span class="glb-txt">
      <b><?= htmlspecialchars(guide_text('BannerTitle')) ?></b>
      <span><?= htmlspecialchars(guide_text('BannerText')) ?></span>
    </span>
    <span class="glb-arrow">→</span>
  </a>
</div>
<script>
/* Moved to the top of #Content once the page exists: the banner is emitted here,
   where the menu is included, which is not where it has to appear. */
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
    <span id="guide-rec-title"><?= htmlspecialchars(guide_text('RecTitle')) ?></span>
    <button id="guide-rec-close" title="<?= htmlspecialchars(guide_text('RecAbort')) ?>">✕</button>
  </div>
  <div id="guide-rec-hint"><?= htmlspecialchars(guide_text('RecHint')) ?></div>
  <div id="guide-rec-list"></div>
  <div id="guide-rec-actions">
    <button id="guide-rec-pause" class="guide-rec-btn">⏸ <?= htmlspecialchars(guide_text('RecPause')) ?></button>
    <button id="guide-rec-page"  class="guide-rec-btn"
            title="<?= htmlspecialchars(guide_text('RecCurrentPageHint')) ?>">📍 <?= htmlspecialchars(guide_text('RecCurrentPage')) ?></button>
    <button id="guide-rec-undo"  class="guide-rec-btn"
            title="<?= htmlspecialchars(guide_text('RecUndoHint')) ?>">↶ <?= htmlspecialchars(guide_text('RecUndo')) ?></button>
    <button id="guide-rec-done"  class="guide-rec-btn guide-rec-btn-done">✓ <?= htmlspecialchars(guide_text('RecDone')) ?></button>
  </div>
</div>

<script src="<?= htmlspecialchars($_gJs) ?>"></script>
<?php
unset($_guideUrl, $_gAssets, $_gCss, $_gJs, $_gIsHome);
