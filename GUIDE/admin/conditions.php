<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);

/* ---- Test AJAX d'une condition (définition posée, pas encore sauvegardée) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    $cond = json_decode($_POST['condition'] ?? '', true);
    if (!$cond || empty($cond['checks']) || !is_array($cond['checks'])) {
        echo json_encode(['error' => 'Condition invalide (aucun check).']); exit;
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

/* ---- Sauvegarde de toutes les conditions ---- */
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $arr = json_decode($_POST['conditions_json'] ?? '', true);
    if (!is_array($arr)) {
        $error = 'JSON invalide.';
    } else {
        foreach ($arr as $c) {
            if (empty($c['id']) || empty($c['label']) || empty($c['checks'])) {
                $error = 'Chaque condition doit avoir un id, un label et au moins un check.';
                break;
            }
        }
        if (!$error) {
            if (guide_save_conditions($arr)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
                exit;
            }
            $error = 'Erreur d\'écriture de conditions.json (droits ?).';
        }
    }
}

$conditions = guide_load_conditions();
$PAGE_TITLE = 'Guide FFTA — Conditions';
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

<h1>Guide FFTA — Constructeur de conditions</h1>
<p><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/">← Retour à l'administration</a></p>

<div class="gc-wrap">

<?php if (!empty($_GET['saved'])): ?><div class="gc-msg-ok">✓ Conditions enregistrées.</div><?php endif; ?>
<?php if ($error): ?><div class="gc-msg-err">✗ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<p style="font-size:13px;color:#555;max-width:760px">
  Les conditions vérifient l'état de la compétition (session, tables ianseo, en lecture seule).
  Elles servent aux <b>triggers d'état</b>, aux <b>branches conditionnelles</b>, aux <b>défis</b> et aux
  <b>checklists auto-cochables</b>. Le bouton <b>Tester</b> évalue la condition sur la compétition ouverte.
</p>

<!-- Liste -->
<table class="gc-table">
  <thead><tr><th>ID</th><th>Label</th><th>Checks</th><th style="width:300px">Actions</th></tr></thead>
  <tbody id="gc-list"></tbody>
</table>

<!-- Builder -->
<div class="gc-builder" id="gc-builder" style="display:none">
  <h2 id="gc-builder-title">Nouvelle condition</h2>
  <div class="gc-field">
    <label>ID <span style="text-transform:none;font-weight:400;color:#999">(minuscules, chiffres, _ )</span></label>
    <input type="text" id="gc-id" placeholder="ma_condition">
  </div>
  <div class="gc-field">
    <label>Label (affiché à l'utilisateur)</label>
    <input type="text" id="gc-label" placeholder="Au moins une session définie">
  </div>
  <div class="gc-field">
    <label>Checks (tous doivent être vrais)</label>
    <div id="gc-checks"></div>
    <button type="button" class="gc-btn gc-btn-test" onclick="addCheck()">+ Ajouter un check</button>
  </div>
  <div style="margin-top:12px">
    <button type="button" class="gc-btn gc-btn-test" onclick="testBuilder()">🔍 Tester maintenant</button>
    <span class="gc-test-res" id="gc-builder-res"></span>
    <br><br>
    <button type="button" class="gc-btn gc-btn-edit" onclick="applyBuilder()">✓ Valider cette condition</button>
    <button type="button" class="gc-btn" style="background:#eee" onclick="closeBuilder()">Annuler</button>
  </div>
</div>

<p>
  <button type="button" class="gc-btn gc-btn-add" onclick="openBuilder(-1)">+ Nouvelle condition</button>
</p>

<!-- Sauvegarde globale -->
<form method="post" onsubmit="return prepareSave()">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="conditions_json" id="gc-json">
  <button type="submit" class="gc-btn gc-btn-save">💾 Enregistrer toutes les conditions</button>
  <span id="gc-dirty" style="display:none;color:#b8860b;font-size:12px;margin-left:10px">● modifications non enregistrées</span>
</form>

<details class="gc-raw" style="margin-top:18px">
  <summary>JSON brut (experts)</summary>
  <textarea id="gc-raw-ta" spellcheck="false"></textarea>
  <button type="button" class="gc-btn gc-btn-test" style="margin-top:6px" onclick="applyRaw()">↺ Appliquer le JSON</button>
</details>

</div>

<script>
var CONDS = <?= json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var OPS = [['eq','='],['neq','≠'],['gt','>'],['gte','≥'],['lt','<'],['lte','≤']];
var TABLES = ['Tournament','Entries','Individuals','Teams','Events','Classes','Divisions','Qualifications','Session','DistanceInformation'];
var _editIdx = -1;

/* ===== Liste ===== */

function renderList() {
  var tb = document.getElementById('gc-list');
  tb.innerHTML = '';
  CONDS.forEach(function (c, i) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="font-family:monospace;font-size:12px">' + esc(c.id) + '</td>' +
      '<td>' + esc(c.label) + '</td>' +
      '<td>' + (c.checks || []).length + '</td>' +
      '<td>' +
        '<button class="gc-btn gc-btn-test" onclick="testCond(' + i + ', this)">🔍 Tester</button> ' +
        '<button class="gc-btn gc-btn-edit" onclick="openBuilder(' + i + ')">Éditer</button> ' +
        '<button class="gc-btn gc-btn-del" onclick="delCond(' + i + ')">✕</button>' +
        '<span class="gc-test-res"></span>' +
      '</td>';
    tb.appendChild(tr);
  });
  if (!CONDS.length) tb.innerHTML = '<tr><td colspan="4" style="color:#999;font-style:italic">Aucune condition.</td></tr>';
  document.getElementById('gc-raw-ta').value = JSON.stringify(CONDS, null, 2);
}

function markDirty() { document.getElementById('gc-dirty').style.display = 'inline'; }

function delCond(i) {
  if (!confirm('Supprimer la condition « ' + CONDS[i].id + ' » ?\nVérifiez qu\'aucune formation/défi ne l\'utilise.')) return;
  CONDS.splice(i, 1);
  renderList(); markDirty();
}

function testCond(i, btn) {
  var span = btn.parentNode.querySelector('.gc-test-res');
  span.textContent = '⏳';
  postTest(CONDS[i], function (data) {
    span.textContent = data.error ? ('⚠ ' + data.error) : (data.met ? '✅ remplie' : '❌ non remplie');
  });
}

function postTest(cond, cb) {
  var fd = new FormData();
  fd.append('action', 'test');
  fd.append('condition', JSON.stringify(cond));
  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(cb)
    .catch(function () { cb({ error: 'réseau' }); });
}

/* ===== Builder ===== */

function openBuilder(i) {
  _editIdx = i;
  var c = i >= 0 ? CONDS[i] : { id: '', label: '', checks: [] };
  document.getElementById('gc-builder-title').textContent = i >= 0 ? 'Éditer : ' + c.id : 'Nouvelle condition';
  document.getElementById('gc-id').value    = c.id || '';
  document.getElementById('gc-label').value = c.label || '';
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
        '<option value="session"' + (type === 'session' ? ' selected' : '') + '>Variable de session</option>' +
        '<option value="count"'   + (type === 'count'   ? ' selected' : '') + '>Nombre de lignes (COUNT)</option>' +
        '<option value="column"'  + (type === 'column'  ? ' selected' : '') + '>Valeur d\'une colonne</option>' +
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
    b.innerHTML = 'Clé <input type="text" class="gc-skey" list="gc-skeys" value="' + esc(ch.key || 'TourId') + '" style="width:110px">' +
      '<datalist id="gc-skeys"><option value="TourId"></datalist>' +
      opSelect('gc-op', ch.op || 'gt') +
      '<input type="text" class="gc-val" value="' + esc(ch.value !== undefined ? String(ch.value) : '0') + '" style="width:70px">';
  } else if (type === 'count') {
    var whereRows = '';
    ((ch.where) || []).forEach(function (w) { whereRows += whereRowHtml(w); });
    b.innerHTML = 'Table <input type="text" class="gc-table-in" list="gc-tables" value="' + esc(ch.table || '') + '" style="width:150px">' + dl +
      ' — nombre de lignes ' + opSelect('gc-op', ch.op || 'gt') +
      '<input type="text" class="gc-val" value="' + esc(ch.value !== undefined ? String(ch.value) : '0') + '" style="width:70px">' +
      '<div class="gc-where" style="width:100%">' +
        '<div class="gc-where-list">' + whereRows + '</div>' +
        '<button type="button" class="gc-btn gc-btn-test" onclick="addWhere(this)">+ critère WHERE</button>' +
        '<p class="gc-hint">Op « = session » : compare la colonne à une variable de session (valeur = nom de la clé, ex. TourId).</p>' +
      '</div>';
  } else {
    b.innerHTML = 'Table <input type="text" class="gc-table-in" list="gc-tables" value="' + esc(ch.table || '') + '" style="width:140px">' + dl +
      ' colonne <input type="text" class="gc-col" value="' + esc(ch.column || '') + '" style="width:120px">' +
      ' jointure <input type="text" class="gc-join" value="' + esc(ch.join || 'ToId = TourId') + '" style="width:130px" title="Colonne = CléSession">' +
      opSelect('gc-op', ch.op || 'eq') +
      '<input type="text" class="gc-val" value="' + esc(ch.value !== undefined ? String(ch.value) : '') + '" style="width:80px">';
  }
}

function whereRowHtml(w) {
  w = w || {};
  var isSess = (w.source === 'session');
  var h = '<div class="gc-where-row">Colonne <input type="text" class="gc-wcol" value="' + esc(w.column || '') + '" style="width:130px">';
  h += '<select class="gc-wop">';
  OPS.forEach(function (o) { h += '<option value="' + o[0] + '"' + (!isSess && o[0] === (w.op || 'eq') ? ' selected' : '') + '>' + o[1] + '</option>'; });
  h += '<option value="session"' + (isSess ? ' selected' : '') + '>= session</option></select>';
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
    } else if (type === 'count') {
      var where = [];
      div.querySelectorAll('.gc-where-row').forEach(function (r) {
        var wcol = r.querySelector('.gc-wcol').value.trim();
        var wop  = r.querySelector('.gc-wop').value;
        var wval = r.querySelector('.gc-wval').value.trim();
        if (!wcol) return;
        if (wop === 'session') where.push({ column: wcol, source: 'session', key: wval });
        else {
          if (/^-?\d+$/.test(wval)) wval = parseInt(wval, 10);
          where.push({ column: wcol, op: wop, value: wval });
        }
      });
      checks.push({ table: div.querySelector('.gc-table-in').value.trim(), aggregate: 'count', where: where, op: op, value: val });
    } else {
      checks.push({
        table: div.querySelector('.gc-table-in').value.trim(),
        column: div.querySelector('.gc-col').value.trim(),
        join: div.querySelector('.gc-join').value.trim(),
        op: op, value: String(val)
      });
    }
  });
  return { id: id, label: label, checks: checks };
}

function testBuilder() {
  var c = captureBuilder();
  var span = document.getElementById('gc-builder-res');
  if (!c.checks.length) { span.textContent = '⚠ aucun check'; return; }
  span.textContent = '⏳';
  postTest(c, function (data) {
    if (data.error) { span.textContent = '⚠ ' + data.error; return; }
    span.textContent = (data.met ? '✅ remplie' : '❌ non remplie') + '  (' +
      data.results.map(function (r) { return r ? '✓' : '✗'; }).join(' ') + ')';
    // Icônes par check
    var divs = document.querySelectorAll('#gc-checks .gc-check .gc-res-icons');
    data.results.forEach(function (r, i) { if (divs[i]) divs[i].textContent = r ? '✅' : '❌'; });
  });
}

function applyBuilder() {
  var c = captureBuilder();
  if (!c.id || !c.label) { alert('ID et label obligatoires.'); return; }
  if (!c.checks.length) { alert('Ajoutez au moins un check.'); return; }
  // Unicité de l'id (hors ligne en cours d'édition)
  for (var i = 0; i < CONDS.length; i++) {
    if (i !== _editIdx && CONDS[i].id === c.id) { alert('Cet ID existe déjà.'); return; }
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
    if (!Array.isArray(arr)) throw new Error('tableau attendu');
    CONDS = arr;
    renderList(); markDirty();
  } catch (e) { alert('JSON invalide : ' + e.message); }
}

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

renderList();
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
