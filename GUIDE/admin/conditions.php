<?php
/**
 * Condition builder of the Interactive Guide module.
 *
 * A condition asks the ianseo database whether something has been accomplished
 * — a competition is open, archers are registered, targets are assigned. Course
 * steps wait on them and challenges are graded by them, which is what makes the
 * module able to check real work rather than just show slides.
 *
 * This screen builds them without writing SQL: each condition is a list of
 * checks (a session value, a visited page, a COUNT over a table, a single
 * column) and they are stored in conditions.json. Every check READS; none of
 * them writes, and the evaluator itself is in the module library so that this
 * screen and the runtime cannot drift apart.
 *
 * A condition can be tried before being saved, against the live database, which
 * is the only reliable way to tell a correct condition from one that merely
 * looks right.
 */

// Walk up to the ianseo root instead of counting directory levels, so the module
// keeps working if it is installed somewhere other than Modules/Custom/.
$_guide_root = __DIR__;
while ($_guide_root !== dirname($_guide_root) && !is_file($_guide_root . '/config.php')) {
    $_guide_root = dirname($_guide_root);
}
define('HTDOCS', $_guide_root);
unset($_guide_root);

require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

guide_check_admin();

/* ---- Try a condition that is still being written and has not been saved ----
 * Admin only, like the whole page: the definition arrives from the request and
 * reaches the query builder. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    $cond = json_decode($_POST['condition'] ?? '', true);
    if (!$cond || empty($cond['checks']) || !is_array($cond['checks'])) {
        echo json_encode(['error' => guide_text('CndErrNoCheck')]); exit;
    }
    $results = [];
    $met = true;
    foreach ($cond['checks'] as $check) {
        $ok = false;
        try { $ok = guide_evaluate_check($check); } catch (Throwable $e) { $ok = false; }
        $results[] = $ok;
        if (!$ok) $met = false;
    }
    echo json_encode(['met' => $met, 'results' => $results]);
    exit;
}

/* ---- Save the whole catalogue at once ----
 * The catalogue is written as a single file, so it is validated as a whole and
 * either replaces the previous one entirely or is rejected: a half-written
 * conditions.json would break every course that waits on a condition. */
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $arr = json_decode($_POST['conditions_json'] ?? '', true);
    if (!is_array($arr)) {
        $error = guide_text('CndErrJson');
    } else {
        // Validated as a whole before anything is written: a half-valid
        // conditions.json would break every course that waits on a condition.
        foreach ($arr as $c) {
            if (empty($c['id']) || empty($c['label']) || empty($c['checks'])) {
                $error = guide_text('CndErrIncomplete');
                break;
            }
        }
        if (!$error) {
            if (guide_save_conditions($arr)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
                exit;
            }
            $error = guide_text('CndErrWrite');
        }
    }
}

$conditions = guide_load_conditions();
$adminUrl   = (function_exists('cmod_url') ? cmod_url(dirname(__DIR__)) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/') . 'admin/';
$PAGE_TITLE = guide_text('CndTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.gc-wrap { max-width: 980px; }
.gc-msg-ok  { background:#e8faf0; border-left:3px solid #1a8a4a; color:#1a5a33; padding:8px 14px; border-radius:6px; margin-bottom:12px; font-size:13px; }
.gc-msg-err { background:#fde8e8; border-left:3px solid #c0392b; color:#8a1a1a; padding:8px 14px; border-radius:6px; margin-bottom:12px; font-size:13px; }
.gc-table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 22px; }
.gc-table th { background: #0254a8; color: #fff; padding: 8px 12px; text-align: left; }
.gc-table td { padding: 7px 12px; border-bottom: 1px solid #eef0f8; vertical-align: middle; }
.gc-table tr:hover td { background: #f7f9ff; }
.gc-btn { padding: 5px 12px; border-radius: 5px; border: none; cursor: pointer; font-size: 12px; font-weight: 600; }
.gc-btn-test { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; }
.gc-btn-edit { background: #0254a8; color: #fff; }
.gc-btn-del  { background: #c0392b; color: #fff; }
.gc-btn-save { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; padding: 10px 26px; font-size: 14px; }
.gc-btn-add  { background: #1a8a4a; color: #fff; }
.gc-test-res { font-size: 13px; margin-left: 6px; }

.gc-builder { background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 10px; padding: 16px 18px; margin-bottom: 22px; }
.gc-builder h2 { color: #0254a8; font-size: 15px; margin: 0 0 12px; }
.gc-field { margin-bottom: 10px; }
.gc-field label { display: block; font-size: 11px; font-weight: 700; color: #0254a8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
.gc-field input[type=text], .gc-field input[type=number] { width: 100%; max-width: 420px; padding: 6px 9px; border: 1px solid #c8d4ec; border-radius: 5px; font-size: 13px; box-sizing: border-box; }
.gc-check { background: #fff; border: 1px solid #dde2f5; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; }
.gc-check-head { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.gc-check select, .gc-check input { padding: 5px 7px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; }
.gc-check-body { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.gc-where { margin-top: 6px; padding-left: 14px; border-left: 2px solid #dde2f5; }
.gc-where-row { display: flex; gap: 6px; align-items: center; margin-bottom: 5px; }
.gc-hint { font-size: 11px; color: #999; margin-top: 3px; }
.gc-res-icons { font-size: 13px; margin-left: 8px; }
details.gc-raw summary { cursor: pointer; font-size: 12px; color: #666; padding: 6px 0; }
#gc-raw-ta { width: 100%; height: 260px; font-family: monospace; font-size: 12px; border: 1px solid #c8d4ec; border-radius: 6px; padding: 10px; box-sizing: border-box; }
</style>

<h1><?= htmlspecialchars(guide_text('CndHeading')) ?></h1>
<p><a href="<?= htmlspecialchars($adminUrl) ?>">← <?= htmlspecialchars(guide_text('AdmBackAdmin')) ?></a></p>

<div class="gc-wrap">

<?php
// Feedback from the previous request: what was saved, or what went wrong.
if (!empty($_GET['saved'])) {
    echo '<div class="gc-msg-ok">✓ ' . htmlspecialchars(guide_text('CndSaved'), ENT_QUOTES, 'UTF-8') . '</div>';
}
if ($error) {
    echo '<div class="gc-msg-err">✗ ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
}
?>

<?php /* Carries deliberate <b> markup from the language file, so it is not escaped. */ ?>
<p style="font-size:13px;color:#555;max-width:760px"><?= guide_text('CndIntro') ?></p>

<!-- The catalogue -->
<table class="gc-table">
  <thead><tr>
    <th>ID</th>
    <th><?= htmlspecialchars(guide_text('CndColLabel')) ?></th>
    <th><?= htmlspecialchars(guide_text('CndColChecks')) ?></th>
    <th style="width:300px"><?= htmlspecialchars(guide_text('AdmColActions')) ?></th>
  </tr></thead>
  <tbody id="gc-list"></tbody>
</table>

<!-- One condition being written -->
<div class="gc-builder" id="gc-builder" style="display:none">
  <h2 id="gc-builder-title"><?= htmlspecialchars(guide_text('CndNew')) ?></h2>
  <div class="gc-field">
    <label>ID <span style="text-transform:none;font-weight:400;color:#999"><?= htmlspecialchars(guide_text('CndIdHint')) ?></span></label>
    <input type="text" id="gc-id" placeholder="my_condition">
  </div>
  <div class="gc-field">
    <label><?= htmlspecialchars(guide_text('CndLabelField')) ?></label>
    <input type="text" id="gc-label" placeholder="<?= htmlspecialchars(guide_text('CndLabelPlaceholder')) ?>">
  </div>
  <div class="gc-field">
    <label><?= htmlspecialchars(guide_text('CndChecksField')) ?></label>
    <div id="gc-checks"></div>
    <button type="button" class="gc-btn gc-btn-test" onclick="addCheck()">+ <?= htmlspecialchars(guide_text('CndAddCheck')) ?></button>
  </div>
  <div style="margin-top:12px">
    <button type="button" class="gc-btn gc-btn-test" onclick="testBuilder()">🔍 <?= htmlspecialchars(guide_text('CndTestNow')) ?></button>
    <span class="gc-test-res" id="gc-builder-res"></span>
    <br><br>
    <button type="button" class="gc-btn gc-btn-edit" onclick="applyBuilder()">✓ <?= htmlspecialchars(guide_text('CndApply')) ?></button>
    <button type="button" class="gc-btn" style="background:#eee" onclick="closeBuilder()"><?= htmlspecialchars(guide_text('Cancel')) ?></button>
  </div>
</div>

<p>
  <button type="button" class="gc-btn gc-btn-add" onclick="openBuilder(-1)">+ <?= htmlspecialchars(guide_text('CndNew')) ?></button>
</p>

<!-- The catalogue is saved as a whole, never one condition at a time -->
<form method="post" onsubmit="return prepareSave()">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="conditions_json" id="gc-json">
  <button type="submit" class="gc-btn gc-btn-save">💾 <?= htmlspecialchars(guide_text('CndSaveAll')) ?></button>
  <span id="gc-dirty" style="display:none;color:#b8860b;font-size:12px;margin-left:10px">● <?= htmlspecialchars(guide_text('CndDirty')) ?></span>
</form>

<details class="gc-raw" style="margin-top:18px">
  <summary><?= htmlspecialchars(guide_text('CndRawJson')) ?></summary>
  <textarea id="gc-raw-ta" spellcheck="false"></textarea>
  <button type="button" class="gc-btn gc-btn-test" style="margin-top:6px" onclick="applyRaw()">↺ <?= htmlspecialchars(guide_text('CndApplyRaw')) ?></button>
</details>

</div>

<script>
var CONDS = <?= json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/* The builder renders its widgets in JavaScript, so its wording travels here
   rather than through guide_text() at render time. */
var GC_T = <?= json_encode([
    'new'          => guide_text('CndNew'),
    'editTitle'    => guide_text('CndEditTitle'),
    'test'         => guide_text('CndTest'),
    'edit'         => guide_text('AdmEdit'),
    'none'         => guide_text('CndNone'),
    'delConfirm'   => guide_text('CndDelConfirm'),
    'met'          => guide_text('CndMet'),
    'notMet'       => guide_text('CndNotMet'),
    'network'      => guide_text('CndNetwork'),
    'noCheck'      => guide_text('CndNoCheck'),
    'typeSession'  => guide_text('CndTypeSession'),
    'typeCount'    => guide_text('CndTypeCount'),
    'typeColumn'   => guide_text('CndTypeColumn'),
    'typeVisited'  => guide_text('CndTypeVisited'),
    'key'          => guide_text('CndKey'),
    'table'        => guide_text('CndTable'),
    'rowCount'     => guide_text('CndRowCount'),
    'joinOptional' => guide_text('CndJoinOptional'),
    'joinOn'       => guide_text('CndJoinOn'),
    'addWhere'     => guide_text('CndAddWhere'),
    'whereHint'    => guide_text('CndWhereHint'),
    'pagePath'     => guide_text('CndPagePath'),
    'anyTournament'=> guide_text('CndAnyTournament'),
    'visitedHint'  => guide_text('CndVisitedHint'),
    'column'       => guide_text('CndColumn'),
    'join'         => guide_text('CndJoin'),
    'whereColumn'  => guide_text('CndWhereColumn'),
    'opInList'     => guide_text('CndOpInList'),
    'opSession'    => guide_text('CndOpSession'),
    'errIdLabel'   => guide_text('CndErrIdLabel'),
    'errNoChecks'  => guide_text('CndErrNoChecks'),
    'errIdExists'  => guide_text('CndErrIdExists'),
    'errArray'     => guide_text('CndErrArrayExpected'),
    'errRawJson'   => guide_text('CndErrRawJson'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* The interface language, so a label that carries several languages is shown —
   and edited — in the one the administrator is reading. */
var GC_LANG = <?= json_encode(guide_lang_code()) ?>;

var OPS = [['eq','='],['neq','≠'],['gt','>'],['gte','≥'],['lt','<'],['lte','≤']];

/* ===== Labels carrying several languages =====
   A condition label is translatable like any other text. It is stored as a map
   of language code to string. Editing one language must never discard the
   others, which is what condLabelSet() is for: the builder shows one language
   and merges the edit back, rather than replacing the whole field. */

function isLangMap(v) {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return false;
  var keys = Object.keys(v);
  return keys.length > 0 && keys.every(function (k) {
    return /^[a-z]{2}(-[a-z]{2})?$/.test(k) && typeof v[k] === 'string';
  });
}

// The text to show: the reader's language, then its base language, then English,
// then whatever the label does have — never an empty cell.
function condLabel(v) {
  if (!isLangMap(v)) return v || '';
  var tries = [GC_LANG, GC_LANG.slice(0, 2), 'en'];
  for (var i = 0; i < tries.length; i++) if (v[tries[i]]) return v[tries[i]];
  var first = Object.keys(v)[0];
  return first ? v[first] : '';
}

// Merge an edited label back, keeping the languages that were already there.
function condLabelSet(existing, text) {
  if (!isLangMap(existing)) return text;
  var out = {};
  Object.keys(existing).forEach(function (k) { out[k] = existing[k]; });
  out[GC_LANG.slice(0, 2)] = text;
  return out;
}
var TABLES = ['Tournament','Entries','Individuals','Teams','Events','Classes','Divisions','Qualifications','Session','DistanceInformation','TournamentInvolved'];
var _editIdx = -1;
var _editLabel = '';   // the label as stored, so its other languages survive an edit

/* ===== Liste ===== */

function renderList() {
  var tb = document.getElementById('gc-list');
  tb.innerHTML = '';
  CONDS.forEach(function (c, i) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="font-family:monospace;font-size:12px">' + esc(c.id) + '</td>' +
      '<td>' + esc(condLabel(c.label)) + '</td>' +
      '<td>' + (c.checks || []).length + '</td>' +
      '<td>' +
        '<button class="gc-btn gc-btn-test" onclick="testCond(' + i + ', this)">🔍 ' + esc(GC_T.test) + '</button> ' +
        '<button class="gc-btn gc-btn-edit" onclick="openBuilder(' + i + ')">' + esc(GC_T.edit) + '</button> ' +
        '<button class="gc-btn gc-btn-del" onclick="delCond(' + i + ')">✕</button>' +
        '<span class="gc-test-res"></span>' +
      '</td>';
    tb.appendChild(tr);
  });
  if (!CONDS.length) tb.innerHTML = '<tr><td colspan="4" style="color:#999;font-style:italic">' + esc(GC_T.none) + '</td></tr>';
  document.getElementById('gc-raw-ta').value = JSON.stringify(CONDS, null, 2);
}

function markDirty() { document.getElementById('gc-dirty').style.display = 'inline'; }

function delCond(i) {
  // Nothing here knows which courses use a condition, so the confirmation says
  // to check rather than pretending it could.
  if (!confirm(GC_T.delConfirm.replace('{$a}', CONDS[i].id))) return;
  CONDS.splice(i, 1);
  renderList(); markDirty();
}

function testCond(i, btn) {
  var span = btn.parentNode.querySelector('.gc-test-res');
  span.textContent = '⏳';
  postTest(CONDS[i], function (data) {
    span.textContent = data.error ? ('⚠ ' + data.error)
                                  : (data.met ? '✅ ' + GC_T.met : '❌ ' + GC_T.notMet);
  });
}

function postTest(cond, cb) {
  var fd = new FormData();
  fd.append('action', 'test');
  fd.append('condition', JSON.stringify(cond));
  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(cb)
    .catch(function () { cb({ error: GC_T.network }); });
}

/* ===== Builder ===== */

function openBuilder(i) {
  _editIdx = i;
  var c = i >= 0 ? CONDS[i] : { id: '', label: '', checks: [] };
  document.getElementById('gc-builder-title').textContent =
    i >= 0 ? GC_T.editTitle.replace('{$a}', c.id) : GC_T.new;
  document.getElementById('gc-id').value    = c.id || '';
  document.getElementById('gc-label').value = condLabel(c.label);
  // Remembered so captureBuilder() can merge the edit back into it rather than
  // replacing a multilingual label with a single string.
  _editLabel = (_editIdx >= 0) ? CONDS[_editIdx].label : '';
  document.getElementById('gc-checks').innerHTML = '';
  (c.checks || []).forEach(function (ch) { addCheck(ch); });
  document.getElementById('gc-builder-res').textContent = '';
  document.getElementById('gc-builder').style.display = '';
  document.getElementById('gc-builder').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function closeBuilder() {
  document.getElementById('gc-builder').style.display = 'none';
  _editIdx = -1;
}

function opSelect(cls, val) {
  var h = '<select class="' + cls + '">';
  OPS.forEach(function (o) { h += '<option value="' + o[0] + '"' + (o[0] === val ? ' selected' : '') + '>' + o[1] + '</option>'; });
  return h + '</select>';
}

function checkType(ch) {
  if (ch.source === 'session') return 'session';
  if (ch.source === 'visited') return 'visited';
  if (ch.aggregate === 'count') return 'count';
  return 'column';
}

function addCheck(ch) {
  ch = ch || { source: 'session', key: 'TourId', op: 'gt', value: 0 };
  var type = checkType(ch);
  var div = document.createElement('div');
  div.className = 'gc-check';
  div.innerHTML =
    '<div class="gc-check-head">' +
      '<select class="gc-type" onchange="retype(this)">' +
        '<option value="session"' + (type === 'session' ? ' selected' : '') + '>' + esc(GC_T.typeSession) + '</option>' +
        '<option value="count"'   + (type === 'count'   ? ' selected' : '') + '>' + esc(GC_T.typeCount)   + '</option>' +
        '<option value="column"'  + (type === 'column'  ? ' selected' : '') + '>' + esc(GC_T.typeColumn)  + '</option>' +
        '<option value="visited"' + (type === 'visited' ? ' selected' : '') + '>' + esc(GC_T.typeVisited) + '</option>' +
      '</select>' +
      '<button type="button" class="gc-btn gc-btn-del" onclick="this.closest(\'.gc-check\').remove()">✕</button>' +
      '<span class="gc-res-icons"></span>' +
    '</div>' +
    '<div class="gc-check-body"></div>';
  document.getElementById('gc-checks').appendChild(div);
  buildCheckBody(div, type, ch);
}

function retype(sel) {
  var div = sel.closest('.gc-check');
  buildCheckBody(div, sel.value, {});
}

function buildCheckBody(div, type, ch) {
  var b = div.querySelector('.gc-check-body');
  var dl = '<datalist id="gc-tables">' + TABLES.map(function (t) { return '<option value="' + t + '">'; }).join('') + '</datalist>';
  if (type === 'session') {
    b.innerHTML = esc(GC_T.key) + ' <input type="text" class="gc-skey" list="gc-skeys" value="' + esc(ch.key || 'TourId') + '" style="width:110px">' +
      '<datalist id="gc-skeys"><option value="TourId"></datalist>' +
      opSelect('gc-op', ch.op || 'gt') +
      '<input type="text" class="gc-val" value="' + esc(ch.value !== undefined ? String(ch.value) : '0') + '" style="width:70px">';
  } else if (type === 'count') {
    var whereRows = '';
    ((ch.where) || []).forEach(function (w) { whereRows += whereRowHtml(w); });
    var jn = ch.join || {};
    b.innerHTML = esc(GC_T.table) + ' <input type="text" class="gc-table-in" list="gc-tables" value="' + esc(ch.table || '') + '" style="width:150px">' + dl +
      ' — ' + esc(GC_T.rowCount) + ' ' + opSelect('gc-op', ch.op || 'gt') +
      '<input type="text" class="gc-val" value="' + esc(ch.value !== undefined ? String(ch.value) : '0') + '" style="width:70px">' +
      '<div style="width:100%">' + esc(GC_T.joinOptional) + ' <input type="text" class="gc-jtable" list="gc-tables" value="' + esc(jn.table || '') + '" style="width:130px" placeholder="Entries">' +
      ' ' + esc(GC_T.joinOn) + ' <input type="text" class="gc-jon" value="' + esc(jn.on || '') + '" style="width:130px" placeholder="QuId = EnId"></div>' +
      '<div class="gc-where" style="width:100%">' +
        '<div class="gc-where-list">' + whereRows + '</div>' +
        '<button type="button" class="gc-btn gc-btn-test" onclick="addWhere(this)">+ ' + esc(GC_T.addWhere) + '</button>' +
        '<p class="gc-hint">' + esc(GC_T.whereHint) + '</p>' +
      '</div>';
  } else if (type === 'visited') {
    b.innerHTML = esc(GC_T.pagePath) + ' <input type="text" class="gc-vpath" value="' + esc(ch.path || '') + '" style="width:280px" placeholder="/Modules/Sets/FR/exports/">' +
      ' <label style="font-size:12px"><input type="checkbox" class="gc-vany"' + (ch.any_tournament ? ' checked' : '') + '> ' + esc(GC_T.anyTournament) + '</label>' +
      '<p class="gc-hint" style="width:100%">' + esc(GC_T.visitedHint) + '</p>';
  } else {
    b.innerHTML = esc(GC_T.table) + ' <input type="text" class="gc-table-in" list="gc-tables" value="' + esc(ch.table || '') + '" style="width:140px">' + dl +
      ' ' + esc(GC_T.column) + ' <input type="text" class="gc-col" value="' + esc(ch.column || '') + '" style="width:120px">' +
      ' ' + esc(GC_T.join) + ' <input type="text" class="gc-join" value="' + esc(ch.join || 'ToId = TourId') + '" style="width:130px">' +
      opSelect('gc-op', ch.op || 'eq') +
      '<input type="text" class="gc-val" value="' + esc(ch.value !== undefined ? String(ch.value) : '') + '" style="width:80px">';
  }
}

function whereRowHtml(w) {
  w = w || {};
  var isSess = (w.source === 'session');
  var isIn   = (!isSess && w.op === 'in');
  var h = '<div class="gc-where-row">' + esc(GC_T.whereColumn) + ' <input type="text" class="gc-wcol" value="' + esc(w.column || '') + '" style="width:130px">';
  h += '<select class="gc-wop">';
  OPS.forEach(function (o) { h += '<option value="' + o[0] + '"' + (!isSess && !isIn && o[0] === (w.op || 'eq') ? ' selected' : '') + '>' + o[1] + '</option>'; });
  h += '<option value="in"' + (isIn ? ' selected' : '') + '>' + esc(GC_T.opInList) + '</option>';
  h += '<option value="session"' + (isSess ? ' selected' : '') + '>' + esc(GC_T.opSession) + '</option></select>';
  h += '<input type="text" class="gc-wval" value="' + esc(isSess ? (w.key || 'TourId') : (w.value !== undefined ? String(w.value) : '')) + '" style="width:90px">';
  h += '<button type="button" class="gc-btn gc-btn-del" onclick="this.parentNode.remove()">✕</button></div>';
  return h;
}

function addWhere(btn) {
  var list = btn.parentNode.querySelector('.gc-where-list');
  var tmp = document.createElement('div');
  tmp.innerHTML = whereRowHtml({});
  list.appendChild(tmp.firstChild);
}

/* Builder → objet condition */
function captureBuilder() {
  var id = document.getElementById('gc-id').value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
  var label = document.getElementById('gc-label').value.trim();
  var checks = [];
  document.querySelectorAll('#gc-checks .gc-check').forEach(function (div) {
    var type = div.querySelector('.gc-type').value;
    var op   = div.querySelector('.gc-op') ? div.querySelector('.gc-op').value : 'eq';
    var val  = div.querySelector('.gc-val') ? div.querySelector('.gc-val').value.trim() : '';
    if (/^-?\d+$/.test(val)) val = parseInt(val, 10);
    if (type === 'session') {
      checks.push({ source: 'session', key: div.querySelector('.gc-skey').value.trim(), op: op, value: val });
    } else if (type === 'visited') {
      var vc = { source: 'visited', path: div.querySelector('.gc-vpath').value.trim() };
      if (div.querySelector('.gc-vany').checked) vc.any_tournament = true;
      checks.push(vc);
    } else if (type === 'count') {
      var where = [];
      div.querySelectorAll('.gc-where-row').forEach(function (r) {
        var wcol = r.querySelector('.gc-wcol').value.trim();
        var wop  = r.querySelector('.gc-wop').value;
        var wval = r.querySelector('.gc-wval').value.trim();
        if (!wcol) return;
        if (wop === 'session') where.push({ column: wcol, source: 'session', key: wval });
        else if (wop === 'in') where.push({ column: wcol, op: 'in', value: wval });
        else {
          if (/^-?\d+$/.test(wval)) wval = parseInt(wval, 10);
          where.push({ column: wcol, op: wop, value: wval });
        }
      });
      var cc = { table: div.querySelector('.gc-table-in').value.trim(), aggregate: 'count', where: where, op: op, value: val };
      var jt = div.querySelector('.gc-jtable').value.trim();
      var jo = div.querySelector('.gc-jon').value.trim();
      if (jt && jo) cc.join = { table: jt, on: jo };
      checks.push(cc);
    } else {
      checks.push({
        table: div.querySelector('.gc-table-in').value.trim(),
        column: div.querySelector('.gc-col').value.trim(),
        join: div.querySelector('.gc-join').value.trim(),
        op: op, value: String(val)
      });
    }
  });
  return { id: id, label: condLabelSet(_editLabel, label), checks: checks };
}

function testBuilder() {
  var c = captureBuilder();
  var span = document.getElementById('gc-builder-res');
  if (!c.checks.length) { span.textContent = '⚠ ' + GC_T.noCheck; return; }
  span.textContent = '⏳';
  postTest(c, function (data) {
    if (data.error) { span.textContent = '⚠ ' + data.error; return; }
    span.textContent = (data.met ? '✅ ' + GC_T.met : '❌ ' + GC_T.notMet) + '  (' +
      data.results.map(function (r) { return r ? '✓' : '✗'; }).join(' ') + ')';
    // One icon per check, so a condition that fails says WHICH check failed
    // rather than only that it did.
    var divs = document.querySelectorAll('#gc-checks .gc-check .gc-res-icons');
    data.results.forEach(function (r, i) { if (divs[i]) divs[i].textContent = r ? '✅' : '❌'; });
  });
}

function applyBuilder() {
  var c = captureBuilder();
  // condLabel() rather than the raw field: a language map is an object, and an
  // object is always truthy, so an empty label would slip through.
  if (!c.id || !condLabel(c.label)) { alert(GC_T.errIdLabel); return; }
  if (!c.checks.length) { alert(GC_T.errNoChecks); return; }

  // The id is the key courses refer to, so it has to stay unique. The row being
  // edited is skipped, otherwise a condition would clash with itself.
  for (var i = 0; i < CONDS.length; i++) {
    if (i !== _editIdx && CONDS[i].id === c.id) { alert(GC_T.errIdExists); return; }
  }
  if (_editIdx >= 0) CONDS[_editIdx] = c;
  else CONDS.push(c);
  closeBuilder(); renderList(); markDirty();
}

/* ===== Save / raw ===== */

function prepareSave() {
  document.getElementById('gc-json').value = JSON.stringify(CONDS);
  return true;
}

function applyRaw() {
  try {
    var arr = JSON.parse(document.getElementById('gc-raw-ta').value);
    if (!Array.isArray(arr)) throw new Error(GC_T.errArray);
    CONDS = arr;
    renderList(); markDirty();
  } catch (e) { alert(GC_T.errRawJson.replace('{$a}', e.message)); }
}

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

renderList();
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
