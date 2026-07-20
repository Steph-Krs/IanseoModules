<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

guide_check_admin();

$contentDir  = dirname(__DIR__) . '/content/';
$editId      = isset($_GET['id']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['id'])) : '';
$condFile    = dirname(__DIR__) . '/conditions.json';
$conditions  = file_exists($condFile) ? (json_decode(file_get_contents($condFile), true) ?: []) : [];

$action = $_POST['action'] ?? '';

/* ---- Export .ianseo (JSON compressé zlib, comme les exports natifs ianseo) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'export-ianseo') {
    $data = json_decode($_POST['json_raw'] ?? '', true);
    if (!$data || empty($data['id'])) { http_response_code(400); echo 'JSON invalide'; exit; }
    $payload = gzcompress(json_encode($data, JSON_UNESCAPED_UNICODE), 9);
    $fname   = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['id'])) . '.ianseo';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($payload));
    echo $payload;
    exit;
}

/* ---- Import .ianseo (ou .json brut en repli) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import-ianseo') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Fichier manquant ou en erreur.']); exit;
    }
    $raw  = file_get_contents($_FILES['file']['tmp_name']);
    $json = @gzuncompress($raw);          // .ianseo (zlib)
    if ($json === false) $json = $raw;    // repli : .json non compressé
    $data = json_decode($json, true);
    if (!$data || empty($data['id']) || !isset($data['steps'])) {
        echo json_encode(['error' => 'Fichier invalide (formation non reconnue).']); exit;
    }
    echo json_encode(['ok' => true, 'formation' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Sauvegarde ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['json_raw'])) {
    $isAjax = !empty($_POST['is_ajax']);
    $error  = null;
    $data   = json_decode($_POST['json_raw'], true);
    if (!$data || empty($data['id'])) {
        $error = 'JSON invalide ou champ "id" manquant.';
    } else {
        $cleanId = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['id']));
        if ($cleanId !== $data['id']) {
            $error = 'L\'id ne doit contenir que des lettres minuscules, chiffres et tirets.';
        } else {
            $targetFile = null;
            foreach (glob($contentDir . '*.json') as $f) {
                $d = json_decode(file_get_contents($f), true);
                if (isset($d['id']) && $d['id'] === $cleanId) { $targetFile = $f; break; }
            }
            if (!$targetFile) {
                $count = count(glob($contentDir . '*.json')) + 1;
                $targetFile = $contentDir . sprintf('%02d', $count) . '-' . $cleanId . '.json';
            }
            if (file_put_contents($targetFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                $error = 'Erreur lors de l\'écriture du fichier.';
            }
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo $error ? json_encode(['error' => $error]) : json_encode(['ok' => true]);
        exit;
    }
    if (!$error) {
        header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/admin/?saved=1');
        exit;
    }
}

/* ---- Chargement ---- */
function generateFormationId() {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($i = 0; $i < 20; $i++) { $id .= $chars[random_int(0, 35)]; }
    return $id;
}

$formation = null;
if ($editId) {
    foreach (glob($contentDir . '*.json') as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (isset($d['id']) && $d['id'] === $editId) { $formation = $d; break; }
    }
}
if (!$formation) {
    $formation = [
        'id' => generateFormationId(),
        'title' => '', 'description' => '', 'version' => '1.0',
        'steps' => [[
            'id' => 'step-' . substr(md5(uniqid()), 0, 6),
            'title' => 'Titre de l\'étape',
            'content' => '<p>Contenu HTML de l\'étape.</p>',
            'page' => null, 'triggers' => [],
        ]],
    ];
}

$PAGE_TITLE = $editId ? 'Éditer : ' . $formation['title'] : 'Nouvelle formation';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
/* ===== Mise en page ===== */

.ge-top-bar  { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; align-items: center; }

/* Formation metadata */
.ge-meta {
  display: grid;
  grid-template-columns: 2fr 3fr 90px;
  gap: 10px;
  background: #f7f9ff;
  border: 1px solid #dde2f5;
  border-radius: 10px;
  padding: 12px 16px;
  margin-bottom: 18px;
  align-items: end;
}
.ge-meta-field label { display: block; font-size: 11px; font-weight: 700; color: #0254a8; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 3px; }
.ge-meta-field input { width: 100%; padding: 6px 9px; border: 1px solid #c8d4ec; border-radius: 5px; font-size: 13px; box-sizing: border-box; }

/* ===== Éditeur : deux colonnes ===== */
.ge-editor  { display: flex; gap: 20px; align-items: flex-start; }
.ge-left    { width: 400px; flex-shrink: 0; }
.ge-right   { flex: 1; min-width: 0; }

/* ===== Toolbar ===== */
.ge-toolbar {
  display: flex; gap: 3px; padding: 6px 10px;
  background: #f0f4ff; border: 1px solid #c8d4ec;
  border-radius: 8px 8px 0 0; flex-wrap: wrap; align-items: center;
}
.tb { padding: 4px 8px; border: 1px solid #c8d4ec; border-radius: 4px; background: #fff; cursor: pointer; font-size: 13px; color: #333; line-height: 1.2; }
.tb:hover { background: #e8f0ff; border-color: #0254a8; color: #0254a8; }
.tb-sep   { width: 1px; height: 22px; background: #c8d4ec; margin: 0 2px; }
.tb-color { padding: 2px; width: 32px; height: 28px; border-radius: 4px; border: 1px solid #c8d4ec; cursor: pointer; vertical-align: middle; }
.tb-tip   { background: #fff8e6 !important; border-color: #f5a623 !important; color: #7a4a00 !important; font-size: 12px; }
.tb-tip:hover { background: #ffe9a0 !important; }
.tb-code  { font-family: monospace; font-size: 11px; background: #eef2ff !important; border-color: #c5cef5 !important; color: #082c7c !important; }

/* ===== Panneau guide (éditeur principal) ===== */
.ge-panel {
  border-left: 2px solid rgba(2,84,168,.2);
  border-right: 2px solid rgba(2,84,168,.2);
  border-bottom: 2px solid rgba(2,84,168,.2);
  border-radius: 0 0 14px 14px;
  overflow: hidden;
  font-family: "Poppins","Helvetica",sans-serif;
  font-size: 13px;
  background: #fff;
}
.ge-panel-header {
  background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%);
  color: #fff; padding: 10px 14px;
  display: flex; align-items: center; justify-content: space-between;
}
.ge-panel-header-title { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; }
.ge-panel-header-hint  { font-size: 9px; opacity: .5; }
.ge-panel-fname {
  background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%);
  color: rgba(255,255,255,.75); padding: 0 14px 9px; font-size: 11px; line-height: 1.3; min-height: 16px;
}
.ge-panel-prog {
  padding: 8px 14px 7px; border-bottom: 1px solid #eef0f8; background: #f7f9ff;
}
.ge-panel-prog-bar { height: 4px; background: #dde2f5; border-radius: 2px; overflow: hidden; margin-bottom: 5px; }
.ge-panel-prog-fill { height: 100%; background: linear-gradient(90deg,#0254a8,#082c7c); border-radius: 2px; transition: width .3s; }
.ge-panel-prog-txt  { font-size: 10px; color: #8a94c0; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }

/* ===== Zones éditables ===== */
#pv-stitle {
  font-size: 14px; font-weight: 700; color: #082c7c;
  padding: 12px 16px 6px; min-height: 32px;
  outline: none; border-bottom: 1px dashed rgba(2,84,168,.2);
  cursor: text;
}
#pv-stitle:focus { background: rgba(2,84,168,.03); }
#pv-stitle[data-ph]:empty::before {
  content: attr(data-ph); color: #c0c8e0; font-weight: 400; pointer-events: none;
}
#pv-content {
  padding: 10px 16px 14px; min-height: 130px;
  color: #3a3f5c; line-height: 1.6; outline: none;
  font-size: 13px; cursor: text;
}
#pv-content:focus { background: rgba(2,84,168,.018); }
#pv-content[data-ph]:empty::before {
  content: attr(data-ph); color: #c0c8e0; pointer-events: none;
}
/* Styles du contenu (réplication de guide.css pour cette div) */
#pv-content p { margin: 0 0 8px; }
#pv-content p:last-child { margin-bottom: 0; }
#pv-content ul,
#pv-content ol { list-style: none !important; padding: 0 !important; margin: 4px 0 8px 8px !important; }
#pv-content li { display: block !important; float: none !important; margin-bottom: 4px !important; padding-left: 14px !important; }
#pv-content ul > li::before {
  content: "•"; display: inline-block; width: 14px; margin-left: -14px;
}
#pv-content ol { counter-reset: guide-ol; }
#pv-content ol > li { counter-increment: guide-ol; padding-left: 22px !important; }
#pv-content ol > li::before {
  content: counter(guide-ol) ".";
  display: inline-block; width: 22px; margin-left: -22px; font-weight: 600;
}
#pv-content b, #pv-content strong { color: #082c7c; }
#pv-content .guide-tip {
  background: #fff8e6; border-left: 3px solid #f5a623;
  padding: 7px 10px; margin: 8px 0 4px;
  border-radius: 0 6px 6px 0; font-size: 12px; color: #664d00; line-height: 1.5;
}
#pv-content code {
  background: #eef2ff; border: 1px solid #c5cef5; border-radius: 3px;
  padding: 1px 5px; font-size: 11px; font-family: monospace; color: #082c7c; font-weight: 600;
}

/* Image 16:9 (bandes noires auto) — preview éditeur + vignettes */
.guide-img-16x9 {
  position: relative; width: 100%; padding-top: 56.25%;
  background: #000; border-radius: 8px; overflow: hidden;
}
.guide-img-16x9 img {
  position: absolute; top: 0; left: 0; width: 100%; height: 100%;
  object-fit: contain; display: block;
}
#pv-step-image { margin: 12px 16px 0; }
#step-img-thumb  { width: 120px; flex-shrink: 0; }
#f-img-preview   { width: 180px; flex-shrink: 0; }
.ge-img-ctrl { display: flex; gap: 10px; align-items: flex-start; margin-top: 4px; }

/* Barre nav panneau (décorative) */
.ge-panel-nav {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 12px; border-top: 1px solid #eef0f8; background: #f7f9ff; gap: 6px;
}
.ge-panel-nav-btn {
  padding: 7px 12px; border-radius: 8px; border: 1px solid #d0d8f0;
  font-size: 12px; font-family: inherit; background: #fff; color: #444; cursor: pointer;
}
.ge-panel-nav-btn:hover:not(:disabled) { background: #eef2ff; border-color: #0254a8; color: #0254a8; }
.ge-panel-nav-btn:disabled { opacity: .4; cursor: default; }
#pv-btn-next {
  background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%) !important;
  color: #fff !important; border: none !important; flex: 1; text-align: center;
}

/* ===== Actions étape (sous la preview) + options ===== */
.ge-step-acts { display: flex; gap: 8px; margin-top: 12px; }

.ge-opts { background: #fff; border: 1px solid #dde2f5; border-radius: 10px; padding: 14px 16px; }
.ge-opts-title { font-size: 11px; font-weight: 700; color: #0254a8; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 12px; }
.ge-opt { margin-bottom: 11px; }
.ge-opt > label { display: block; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 3px; }
.ge-opt input[type=text] {
  width: 100%; padding: 6px 9px; border: 1px solid #c8d4ec; border-radius: 5px; font-size: 12px; box-sizing: border-box;
}
.ge-hint { font-size: 11px; color: #aaa; margin-top: 2px; }

/* ===== Triggers ===== */
.tr-empty { font-size: 12px; color: #bbb; font-style: italic; padding: 6px 0; }
.tr-row { display: block; padding: 7px 8px; margin-bottom: 5px; background: #d8e9ff; border: 1px solid #082c7c; border-radius: 7px; }
.tr-row.tr-dragging { opacity: .35; }
.tr-row.tr-over     { border-color: #0254a8; background: #eef4ff; }
.tr-main { display: flex; gap: 6px; align-items: center; }
.tr-body { display: flex; gap: 6px; align-items: center; margin-top: 5px; }
.tr-drag { cursor: grab; color: #bbb; font-size: 15px; user-select: none; flex-shrink: 0; }
.tr-drag:active { cursor: grabbing; }
.tr-kind { padding: 4px 5px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 11px; flex-shrink: 0; background: #fff; }
.tr-kind option[value="action"] { color: #0254a8; }
.tr-kind option[value="etat"]   { color: #7c5cbf; }
/* Annule le width:100% de .ge-opt input[type=text] pour les champs de trigger */
.tr-row input[type=text] { width: auto; }
.tr-page { padding: 4px 6px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 11px; flex: 0 0 130px; box-sizing: border-box; }
.tr-type { padding: 4px 5px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex-shrink: 0; }
.tr-sel  { padding: 4px 7px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex: 1 1 0; min-width: 0; box-sizing: border-box; }
.tr-cond { padding: 4px 7px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex: 1; min-width: 0; background: #d8d4ff; color: #3a2660; }
.tr-cond-page { padding: 4px 7px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex: 1; min-width: 0; box-sizing: border-box; background: #f8f4ff; color: #3a2660; }
.tr-cond-css { padding: 4px 7px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex: 1; min-width: 0; box-sizing: border-box; background: #f8f4ff; color: #3a2660; }
.tr-cond-css-mode { padding: 4px 5px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex-shrink: 0; background: #f8f4ff; color: #3a2660; }
.tr-gate { display: flex; align-items: center; gap: 6px; margin-top: 5px; }
.tr-gate-label { color: #b0a0c8; font-size: 13px; flex-shrink: 0; }
.tr-gate-cond { flex: 1; min-width: 0; padding: 3px 6px; border: 1px dashed #cbb8e0; border-radius: 4px; font-size: 11px; background: #fbf9ff; color: #6a5a8a; }
.tr-req  { display: flex; align-items: center; gap: 4px; font-size: 11px; color: #555; white-space: nowrap; cursor: pointer; flex-shrink: 0; }
.tr-req input { margin: 0; }
.tr-del  { padding: 3px 7px !important; font-size: 11px !important; flex-shrink: 0; }
.tr-hint { flex: 1; min-width: 0; padding: 4px 7px; border: 1px dashed #c8d4ec; border-radius: 4px; font-size: 11px; box-sizing: border-box; color: #555; background: #fafbff; }

/* ===== Sections QCM / Défi ===== */
details.ge-extra { margin-top: 18px; border: 1px solid #dde2f5; border-radius: 10px; background: #fff; }
details.ge-extra > summary {
  cursor: pointer; padding: 11px 16px; font-size: 13px; font-weight: 700; color: #0254a8;
  list-style: none; user-select: none;
}
details.ge-extra > summary::-webkit-details-marker { display: none; }
details.ge-extra > summary::before { content: '▶'; font-size: 10px; margin-right: 8px; display: inline-block; transition: transform .15s; }
details.ge-extra[open] > summary::before { transform: rotate(90deg); }
details.ge-extra .ge-extra-body { padding: 4px 16px 16px; }
details.ge-extra .ge-hint { font-weight: 400; }
#defi-intro { width: 100%; padding: 7px 9px; border: 1px solid #c8d4ec; border-radius: 5px; font-size: 12px; box-sizing: border-box; font-family: monospace; resize: vertical; }
#defi-conds label { display: flex; align-items: center; gap: 7px; font-size: 12.5px; padding: 3px 0; cursor: pointer; }
#defi-conds input { margin: 0; }
.qz-block { background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
.qz-block textarea, .qz-block input[type=text] { width: 100%; padding: 6px 8px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; box-sizing: border-box; }
.qz-choice-row { display: flex; gap: 7px; align-items: center; margin: 4px 0; }
.qz-choice-row input[type=radio] { margin: 0; flex-shrink: 0; }
.qz-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.qz-head b { color: #0254a8; font-size: 12px; }
.qz-lbl { font-size: 11px; color: #888; margin: 6px 0 2px; }

/* ===== JSON (accordéon) ===== */
.ge-json-sect  { margin-top: 20px; }
.ge-json-toggle {
  background: none; border: 1px solid #c8d4ec; border-radius: 6px;
  padding: 6px 14px; font-size: 12px; color: #666; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
}
.ge-json-toggle:hover { background: #f0f4ff; color: #0254a8; }
#ge-json-body { display: none; margin-top: 8px; }
#guide-json-editor {
  width: 100%; height: 320px; font-family: monospace; font-size: 12px;
  border: 1px solid #c8d4ec; border-radius: 6px; padding: 10px; box-sizing: border-box; resize: vertical;
}
.ge-json-err { font-size: 12px; color: #c00; margin-top: 4px; display: none; }

/* ===== Boutons ===== */
.ge-btn { padding: 7px 16px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
.ge-btn-save  { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; padding: 10px 28px; font-size: 14px; }
.ge-btn-save:hover { opacity: .9; }
.ge-btn-ghost { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; }
.ge-btn-ghost:hover { background: #e4ecff; }
.ge-btn-add   { background: #1a8a4a; color: #fff; font-size: 12px; padding: 6px 12px; flex: 1; }
.ge-btn-add:hover { background: #147a3f; }
.ge-btn-del   { background: #c0392b; color: #fff; font-size: 12px; padding: 6px 10px; }
.ge-btn-del:hover { background: #a93226; }
.ge-btn-apply { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; font-size: 12px; padding: 5px 14px; }
.ge-btn-apply:hover { background: #e4ecff; }

.ge-err { background: #fde; border-left: 3px solid #c00; padding: 8px 12px; margin-bottom: 12px; color: #900; border-radius: 4px; }
.ge-mt  { margin-top: 20px; }
</style>

<h1><?= $editId ? 'Éditer la formation' : 'Nouvelle formation' ?></h1>
<p><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/">← Retour à la liste</a></p>

<?php if (!empty($error)): ?>
  <div class="ge-err">Erreur : <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="ge-main">

<!-- Barre Export / Import -->
<div class="ge-top-bar">
  <button class="ge-btn ge-btn-ghost" onclick="exportIanseo()" title="Fichier .ianseo compressé (plus léger), comme les exports natifs ianseo">⬇ Exporter (.ianseo)</button>
  <label class="ge-btn ge-btn-ghost" style="cursor:pointer">
    ⬆ Importer (.ianseo)
    <input type="file" id="import-file" accept=".ianseo,.json,application/json" style="display:none">
  </label>
  <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/help.php" target="_blank"
     class="ge-btn ge-btn-ghost" style="text-decoration:none;margin-left:auto">❔ Aide à la création</a>
</div>

<!-- Métadonnées formation -->
<div class="ge-meta">
  <div class="ge-meta-field">
    <label>Titre de la formation</label>
    <input type="text" id="f-title" placeholder="Ma première compétition" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label>Description</label>
    <input type="text" id="f-description" placeholder="Description courte..." oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label>Version</label>
    <input type="text" id="f-version" placeholder="1.0" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label>Groupe <span style="text-transform:none;font-weight:400;color:#999">(parcours)</span></label>
    <input type="text" id="f-group" placeholder="Les bases" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label>Sous-groupe</label>
    <input type="text" id="f-subgroup" placeholder="(optionnel)" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label>Ordre</label>
    <input type="number" id="f-order" placeholder="10" oninput="captureAndSync()">
  </div>
</div>
<p style="font-size:11px;color:#aaa;margin:-12px 0 14px">
  ID : <code style="background:#f0f4ff;padding:1px 6px;border-radius:3px;color:#555"><?= htmlspecialchars($formation['id']) ?></code>
  — identifiant unique auto-généré, modifiable via le JSON si nécessaire
</p>

<!-- Vignette de la formation -->
<div class="ge-opt" style="margin-bottom:18px">
  <label>Vignette de la formation <span class="ge-hint">(optionnelle · 16:9 · GIF accepté · affichée dans le catalogue)</span></label>
  <div class="ge-img-ctrl">
    <div id="f-img-preview" style="display:none"></div>
    <div>
      <label class="ge-btn ge-btn-ghost" style="cursor:pointer">
        🖼 Choisir une image
        <input type="file" accept="image/*" style="display:none" onchange="handleFormationImageFile(this)">
      </label>
      <button type="button" class="ge-btn ge-btn-del" id="f-img-remove" style="display:none;margin-left:6px" onclick="removeFormationImage()">Retirer</button>
    </div>
  </div>
</div>

<!-- Éditeur -->
<div class="ge-editor">

  <!-- Colonne gauche : panneau éditeur -->
  <div class="ge-left">

    <!-- Toolbar de formatage -->
    <div class="ge-toolbar">
      <button class="tb" onclick="fmt('bold')"    title="Gras (Ctrl+B)"><b>B</b></button>
      <button class="tb" onclick="fmt('italic')"  title="Italique (Ctrl+I)"><i>I</i></button>
      <button class="tb" onclick="fmt('underline')" title="Souligné (Ctrl+U)"><u>U</u></button>
      <input  type="color" class="tb-color" id="txt-color" value="#082c7c"
              onchange="fmt('foreColor',this.value)" title="Couleur du texte">
      <button class="tb" onclick="fmt('removeFormat')" title="Supprimer le formatage" style="font-size:11px;color:#888">✕fmt</button>
      <div class="tb-sep"></div>
      <button class="tb" onclick="fmt('insertUnorderedList')" title="Liste à puces">• ≡</button>
      <button class="tb" onclick="fmt('insertOrderedList')"   title="Liste numérotée">1. ≡</button>
      <div class="tb-sep"></div>
      <button class="tb tb-tip"  onclick="insertTip()"  title="Ajouter un encadré conseil">💡 Conseil</button>
      <button class="tb tb-code" onclick="insertCode()" title="Code inline">&lt;/&gt;</button>
    </div>

    <!-- Panneau guide éditable -->
    <div class="ge-panel">
      <div class="ge-panel-header">
        <span class="ge-panel-header-title">Guide interactif</span>
        <span class="ge-panel-header-hint">éditeur</span>
      </div>
      <div class="ge-panel-fname" id="pv-fname"></div>
      <div class="ge-panel-prog">
        <div class="ge-panel-prog-bar">
          <div class="ge-panel-prog-fill" id="pv-fill" style="width:0%"></div>
        </div>
        <span class="ge-panel-prog-txt" id="pv-prog-txt">Étape 1 / 1</span>
      </div>

      <!-- Image d'étape (preview) -->
      <div id="pv-step-image" style="display:none"></div>

      <!-- Titre (éditable) -->
      <div id="pv-stitle" contenteditable="true"
           data-ph="Titre de l'étape..."
           oninput="captureAndSync()"
           onkeydown="if(event.key==='Enter'){event.preventDefault();}">
      </div>

      <!-- Contenu (éditable riche) -->
      <div id="pv-content" contenteditable="true"
           data-ph="Cliquez ici pour écrire le contenu (HTML)..."
           oninput="captureAndSync()">
      </div>

      <!-- Boutons nav (fonctionnels : navigation entre étapes, comme en formation) -->
      <div class="ge-panel-nav">
        <button class="ge-panel-nav-btn" id="pv-btn-prev" onclick="navStep(-1)">◀ Préc.</button>
        <button class="ge-panel-nav-btn" id="pv-btn-next" onclick="navStep(1)">Suivant ▶</button>
      </div>
    </div>

    <!-- Actions sur l'étape (regroupées sous la preview) -->
    <div class="ge-step-acts">
      <button class="ge-btn ge-btn-add" onclick="addStep(-1)" title="Insérer une étape avant celle-ci">+ Avant</button>
      <button class="ge-btn ge-btn-add" onclick="addStep(1)"  title="Insérer une étape après celle-ci">+ Après</button>
      <button class="ge-btn ge-btn-del" onclick="deleteStep()" title="Supprimer cette étape">✕</button>
    </div>

  </div><!-- /ge-left -->

  <!-- Colonne droite : options -->
  <div class="ge-right">

    <!-- Options de l'étape -->
    <div class="ge-opts">
      <div class="ge-opts-title">Options de l'étape</div>

      <div class="ge-opt">
        <label>Page par défaut <span class="ge-hint">(pour les triggers sans page propre · <code>*</code> = toutes les pages)</span></label>
        <input type="text" id="opt-page" placeholder="/path/to/file.php ou *" oninput="captureAndSync()">
      </div>

      <div class="ge-opt">
        <label>Image de l'étape <span class="ge-hint">(optionnelle · 16:9 · GIF accepté)</span></label>
        <div class="ge-img-ctrl">
          <div id="step-img-thumb" style="display:none"></div>
          <div>
            <label class="ge-btn ge-btn-ghost" style="cursor:pointer;font-size:12px;padding:5px 12px">
              🖼 Choisir
              <input type="file" accept="image/*" style="display:none" onchange="handleStepImageFile(this)">
            </label>
            <button type="button" class="ge-btn ge-btn-del" id="step-img-remove" style="display:none;margin-top:6px;font-size:12px" onclick="removeStepImage()">Retirer</button>
          </div>
        </div>
      </div>

      <div class="ge-opt">
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;">
          <input type="checkbox" id="opt-optional" onchange="captureAndSync()" style="margin:0;">
          Facultatif <span class="ge-hint">("Marquer comme fait" visible — l'utilisateur peut forcer l'étape)</span>
        </label>
      </div>

      <div class="ge-opt">
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;">
          <input type="checkbox" id="opt-strict-click" onchange="captureAndSync()" style="margin:0;">
          Non-permissif <span class="ge-hint">(bloque tout clic hors du sélecteur attendu)</span>
        </label>
      </div>

      <div class="ge-opt">
        <label>
          Triggers
          <span class="ge-hint">— déclenchés dans l'ordre ; glisser-déposer pour réordonner</span>
        </label>
        <div id="triggers-list"></div>
        <button type="button" class="ge-btn ge-btn-ghost"
                onclick="addTrigger()"
                style="margin-top:8px;font-size:12px;padding:5px 14px">
          + Ajouter un trigger
        </button>
        <button type="button" class="ge-btn ge-btn-ghost"
                onclick="startRecording()"
                style="margin-top:8px;margin-left:6px;font-size:12px;padding:5px 14px;border-color:#e0b4ae;color:#c0392b"
                title="Naviguer dans ianseo et cliquer sur les éléments pour enregistrer des triggers automatiquement">
          🔴 Enregistrer les triggers
        </button>
      </div>
    </div>

  </div><!-- /ge-right -->
</div><!-- /ge-editor -->

<!-- Activités : QCM et Défi -->
<details class="ge-extra" id="sect-quiz">
  <summary>📝 QCM de validation <span class="ge-hint">(optionnel — proposé à la fin du guide, compte pour la cible d'argent/or)</span></summary>
  <div class="ge-extra-body">
    <div class="ge-opt" style="max-width:240px">
      <label>Score minimal pour réussir (%)</label>
      <input type="number" id="quiz-pass" min="1" max="100" placeholder="70" oninput="captureAndSync()">
    </div>
    <div class="ge-opt">
      <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;text-transform:none;">
        <input type="checkbox" id="quiz-shuffle" onchange="captureAndSync()" style="margin:0;">
        Réponses affichées dans un ordre aléatoire
      </label>
    </div>
    <div id="quiz-list"></div>
    <button type="button" class="ge-btn ge-btn-ghost" onclick="addQuizQuestion()" style="font-size:12px;padding:5px 14px">
      + Ajouter une question
    </button>
  </div>
</details>

<details class="ge-extra" id="sect-defi">
  <summary>🎯 Défi <span class="ge-hint">(optionnel — l'utilisateur agit sans aide, validation par conditions d'état)</span></summary>
  <div class="ge-extra-body">
    <div class="ge-opt">
      <label>Consigne <span class="ge-hint">(HTML autorisé)</span></label>
      <textarea id="defi-intro" rows="3" oninput="captureAndSync()"
                placeholder="<p>Créez une compétition avec 2 sessions...</p>"></textarea>
    </div>
    <div class="ge-opt">
      <label>Conditions à remplir <span class="ge-hint">(toutes)</span></label>
      <div id="defi-conds"></div>
      <p class="ge-hint">Créez de nouvelles conditions avec le <a href="conditions.php">constructeur de conditions</a>.</p>
    </div>
  </div>
</details>

<!-- JSON source (accordéon, pour experts) -->
<div class="ge-json-sect">
  <button class="ge-json-toggle" onclick="toggleJson()">
    <span id="json-icon">▶</span>
    JSON source — pour experts / import-export
  </button>
  <div id="ge-json-body">
    <textarea id="guide-json-editor" spellcheck="false"></textarea>
    <div class="ge-json-err" id="json-err">⚠ JSON invalide</div>
    <div style="margin-top:6px">
      <button class="ge-btn ge-btn-apply" onclick="applyJson()">↺ Appliquer le JSON à l'éditeur visuel</button>
    </div>
  </div>
</div>

<!-- Sauvegarde -->
<div class="ge-mt" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <button type="button" class="ge-btn ge-btn-save" id="btn-save" onclick="prepareSave()">💾 Enregistrer la formation</button>
  <span id="save-status" style="display:none;font-size:13px;font-weight:600;"></span>
</div>

</div><!-- /ge-main -->

<script>
/* ===== Conditions d'état (injectées depuis PHP) ===== */
var GUIDE_CONDITIONS = <?= json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE) ?>;

/* ===== État ===== */
var _fd      = null;  // objet formation courant
var _sidx    = 0;     // index étape courante
var _syncing = false; // évite les boucles de sync

/* ===== Init ===== */

document.addEventListener('DOMContentLoaded', function () {
  var pv = document.getElementById('pv-content');
  pv.addEventListener('focus', function () {
    document.execCommand('defaultParagraphSeparator', false, 'p');
  });
  pv.addEventListener('keydown', onContentKeydown);
  document.getElementById('import-file').addEventListener('change', handleImport);

  _fd = <?= json_encode($formation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  initDefiConds();
  clampStep();
  syncToDOM();
  checkRecResult();
});

/* Entrée dans un encadré conseil → sort en paragraphe normal.
   Shift+Entrée → reste dans le conseil (saut de ligne <br>, comportement natif). */
function onContentKeydown(e) {
  if (e.key !== 'Enter' || e.shiftKey) return;
  var tip = currentTipElement();
  if (!tip) return;
  e.preventDefault();
  var p = document.createElement('p');
  p.appendChild(document.createElement('br'));
  if (tip.nextSibling) tip.parentNode.insertBefore(p, tip.nextSibling);
  else                 tip.parentNode.appendChild(p);
  var range = document.createRange();
  range.setStart(p, 0);
  range.collapse(true);
  var sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
  setTimeout(captureAndSync, 0);
}

function currentTipElement() {
  var sel = window.getSelection();
  if (!sel.rangeCount) return null;
  var node = sel.anchorNode;
  var root = document.getElementById('pv-content');
  while (node && node !== root) {
    if (node.nodeType === 1 && node.classList && node.classList.contains('guide-tip')) return node;
    node = node.parentNode;
  }
  return null;
}

/* ===== DOM → JSON ===== */

function captureAndSync() {
  if (_syncing) return;
  captureFormation();
  captureStep();
  captureExtras();
  renderJson();
}

/* ===== Activités : QCM & Défi ===== */

function initDefiConds() {
  var box = document.getElementById('defi-conds');
  box.innerHTML = '';
  if (!GUIDE_CONDITIONS.length) {
    box.innerHTML = '<p class="ge-hint">Aucune condition définie.</p>';
    return;
  }
  GUIDE_CONDITIONS.forEach(function (c) {
    var lab = document.createElement('label');
    var cb  = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = c.id;
    cb.className = 'defi-cond-cb';
    cb.addEventListener('change', captureAndSync);
    lab.appendChild(cb);
    lab.appendChild(document.createTextNode(' ' + c.label + ' '));
    var code = document.createElement('code');
    code.textContent = c.id;
    code.style.cssText = 'font-size:10px;color:#999';
    lab.appendChild(code);
    box.appendChild(lab);
  });
}

function addQuizQuestion(q) {
  q = q || { q: '', choices: ['', ''], correct: 0, explain: '' };
  var correct = Array.isArray(q.correct) ? q.correct : [q.correct || 0];
  var block = document.createElement('div');
  block.className = 'qz-block';
  var choicesHtml = '';
  for (var i = 0; i < 4; i++) {
    choicesHtml +=
      '<div class="qz-choice-row">' +
        '<input type="checkbox" class="qz-correct"' + (correct.indexOf(i) !== -1 ? ' checked' : '') + ' title="Bonne réponse">' +
        '<input type="text" class="qz-choice" placeholder="Réponse ' + (i + 1) + (i > 1 ? ' (optionnelle)' : '') + '" value="">' +
      '</div>';
  }
  block.innerHTML =
    '<div class="qz-head"><b>Question</b>' +
      '<button type="button" class="ge-btn ge-btn-del" style="font-size:11px;padding:3px 8px" onclick="this.closest(\'.qz-block\').remove();captureAndSync()">✕</button>' +
    '</div>' +
    '<textarea class="qz-q" rows="2" placeholder="Énoncé de la question..."></textarea>' +
    '<div class="qz-lbl">Réponses — cochez la ou les bonnes (au moins une) :</div>' +
    choicesHtml +
    '<div class="qz-lbl">Explication (affichée après la réponse, optionnelle) :</div>' +
    '<input type="text" class="qz-explain" placeholder="Pourquoi cette réponse...">';

  block.querySelector('.qz-q').value = q.q || '';
  var inputs = block.querySelectorAll('.qz-choice');
  (q.choices || []).forEach(function (c, i) { if (inputs[i]) inputs[i].value = c; });
  block.querySelector('.qz-explain').value = q.explain || '';

  block.querySelectorAll('textarea, input').forEach(function (el) {
    el.addEventListener('input',  captureAndSync);
    el.addEventListener('change', captureAndSync);
  });
  document.getElementById('quiz-list').appendChild(block);
}

function captureExtras() {
  if (!_fd) return;
  // QCM
  var questions = [];
  document.querySelectorAll('#quiz-list .qz-block').forEach(function (block) {
    var qText = block.querySelector('.qz-q').value.trim();
    var rows  = block.querySelectorAll('.qz-choice-row');
    var choices = [], correct = [], kept = 0;
    rows.forEach(function (row) {
      var txt = row.querySelector('.qz-choice').value.trim();
      var cb  = row.querySelector('.qz-correct');
      if (txt === '') return;
      if (cb.checked) correct.push(kept);
      choices.push(txt);
      kept++;
    });
    if (!qText || choices.length < 2) return;
    if (!correct.length) correct = [0]; // toujours au moins une bonne réponse
    var entry = { q: qText, choices: choices, correct: (correct.length === 1 ? correct[0] : correct) };
    var expl = block.querySelector('.qz-explain').value.trim();
    if (expl) entry.explain = expl;
    questions.push(entry);
  });
  if (questions.length) {
    var pass = parseInt(getVal('quiz-pass'), 10);
    _fd.quiz = { pass_score: (!isNaN(pass) && pass >= 1 && pass <= 100) ? pass : 70, questions: questions };
    if (document.getElementById('quiz-shuffle').checked) _fd.quiz.shuffle = true;
  } else {
    delete _fd.quiz;
  }
  // Défi
  var conds = [];
  document.querySelectorAll('#defi-conds .defi-cond-cb:checked').forEach(function (cb) { conds.push(cb.value); });
  var intro = document.getElementById('defi-intro').value.trim();
  if (conds.length) {
    _fd.challenge = { intro: intro, conditions: conds };
  } else {
    delete _fd.challenge;
  }
}

function syncExtras() {
  if (!_fd) return;
  // QCM
  var list = document.getElementById('quiz-list');
  list.innerHTML = '';
  var qz = _fd.quiz || {};
  setVal('quiz-pass', qz.pass_score ? String(qz.pass_score) : '');
  document.getElementById('quiz-shuffle').checked = !!qz.shuffle;
  (qz.questions || []).forEach(function (q) { addQuizQuestion(q); });
  if (qz.questions && qz.questions.length) document.getElementById('sect-quiz').open = true;
  // Défi
  var ch = _fd.challenge || {};
  document.getElementById('defi-intro').value = ch.intro || '';
  var selected = ch.conditions || [];
  document.querySelectorAll('#defi-conds .defi-cond-cb').forEach(function (cb) {
    cb.checked = selected.indexOf(cb.value) !== -1;
  });
  if (selected.length) document.getElementById('sect-defi').open = true;
}

function captureFormation() {
  if (!_fd) return;
  // ID : jamais modifié via l'UI, préservé tel quel depuis le JSON
  _fd.title       = getVal('f-title');
  _fd.description = getVal('f-description');
  _fd.version     = getVal('f-version') || '1.0';
  var grp = getVal('f-group').trim();
  var sgr = getVal('f-subgroup').trim();
  var ord = parseInt(getVal('f-order'), 10);
  if (grp) _fd.group = grp; else delete _fd.group;
  if (sgr) _fd.subgroup = sgr; else delete _fd.subgroup;
  if (!isNaN(ord)) _fd.order = ord; else delete _fd.order;
}

function captureStep() {
  if (!_fd || !_fd.steps || !_fd.steps.length) return;
  var s     = _fd.steps[_sidx];
  s.title   = document.getElementById('pv-stitle').textContent.trim();
  s.content = cleanHtml(document.getElementById('pv-content').innerHTML);
  s.page         = getVal('opt-page') || null;
  s.optional     = document.getElementById('opt-optional').checked;
  s.strict_click = document.getElementById('opt-strict-click').checked;

  // Collecte les triggers depuis les lignes de la liste
  s.triggers = [];
  document.querySelectorAll('#triggers-list .tr-row').forEach(function (row) {
    var kind = row.querySelector('.tr-kind').value;
    var req  = row.querySelector('.tr-req input').checked;
    var tr;
    if (kind === 'etat') {
      var cond = row.querySelector('.tr-cond').value || null;
      tr = { kind: 'etat', condition: cond, required: req };
      if (cond === '__page') {
        var cpage = row.querySelector('.tr-cond-page').value.trim() || null;
        if (cpage) tr.page = cpage;
      } else if (cond === '__css') {
        var csel = row.querySelector('.tr-cond-css').value.trim();
        if (csel) tr.selector = csel;
        if (row.querySelector('.tr-cond-css-mode').value === 'absent') tr.absent = true;
      }
    } else {
      var page = row.querySelector('.tr-page').value.trim() || null;
      var type = row.querySelector('.tr-type').value;
      var sel  = row.querySelector('.tr-sel').value.trim();
      var hint = row.querySelector('.tr-hint').value.trim() || null;
      tr = { kind: 'action', trigger: type === 'null' ? null : type, selector: sel || null, required: req };
      if (page) tr.page = page;
      if (hint) tr.hint = hint;
    }
    // Condition d'activation (branche conditionnelle) — commune action/état
    var gate = row.querySelector('.tr-gate-cond').value;
    if (gate) {
      var sep = gate.indexOf(':');
      var mode = gate.slice(0, sep), cid = gate.slice(sep + 1);
      if (mode === 'met') tr.when = cid;
      else if (mode === 'not') tr.when_not = cid;
    }
    s.triggers.push(tr);
  });

  // Supprime les anciens champs mono-trigger s'ils existent
  delete s.trigger; delete s.selector; delete s.required;
}

function renderJson() {
  if (!_fd) return;
  document.getElementById('guide-json-editor').value = JSON.stringify(_fd, null, 2);
  showJsonError(false);
  updatePanelChrome();
}

function updatePanelChrome() {
  if (!_fd || !_fd.steps) return;
  var total = _fd.steps.length;
  var pct   = total > 1 ? Math.round(_sidx / (total - 1) * 100) : 100;
  setText('pv-fname',   _fd.title || '(titre formation)');
  document.getElementById('pv-fill').style.width = pct + '%';
  setText('pv-prog-txt', 'Étape ' + (_sidx + 1) + ' / ' + total);
  document.getElementById('pv-btn-prev').disabled = (_sidx === 0);
  setText('pv-btn-next', _sidx === total - 1 ? 'Terminer ✓' : 'Suivant ▶');
}

/* ===== JSON → DOM ===== */

function syncToDOM() {
  if (!_fd) return;
  _syncing = true;
  try {
    setVal('f-title',       _fd.title       || '');
    setVal('f-description', _fd.description || '');
    setVal('f-version',     _fd.version     || '1.0');
    setVal('f-group',       _fd.group       || '');
    setVal('f-subgroup',    _fd.subgroup    || '');
    setVal('f-order',       (_fd.order !== undefined && _fd.order !== null) ? String(_fd.order) : '');
    renderFormationImagePreview();
    syncExtras();
    clampStep();
    loadStepToDOM();
  } finally {
    _syncing = false;
  }
}

function loadStepToDOM() {
  if (!_fd || !_fd.steps || !_fd.steps.length) return;
  var s = _fd.steps[_sidx];
  document.getElementById('pv-stitle').textContent = s.title   || '';
  document.getElementById('pv-content').innerHTML  = s.content || '';
  setVal('opt-page', s.page || '');
  document.getElementById('opt-optional').checked    = (s.optional !== false);
  document.getElementById('opt-strict-click').checked = !!(s.strict_click);
  renderStepImagePreview();

  var list = document.getElementById('triggers-list');
  list.innerHTML = '';
  (s.triggers || []).forEach(function (t) { addTrigger(t); });
  updateTriggersEmpty();

  renderJson();
}

function applyJson() {
  var raw = document.getElementById('guide-json-editor').value;
  try { _fd = JSON.parse(raw); } catch(e) { showJsonError(true); return; }
  showJsonError(false);
  _sidx = 0; syncToDOM();
}

/* ===== Navigation ===== */

function navStep(delta) {
  captureStep();
  _sidx += delta;
  clampStep();
  _syncing = true; try { loadStepToDOM(); } finally { _syncing = false; }
}

function clampStep() {
  if (!_fd || !_fd.steps) return;
  _sidx = Math.max(0, Math.min(_sidx, _fd.steps.length - 1));
}

/* ===== Ajouter / Supprimer étape ===== */

function addStep(direction) {
  captureStep();
  if (!_fd.steps) _fd.steps = [];
  var newStep = {
    id:       'step-' + Date.now().toString().slice(-6),
    title:    'Nouvelle étape',
    content:  '<p>Contenu de l\'étape.</p>',
    page: null, triggers: []
  };
  var insertAt = direction < 0 ? _sidx : _sidx + 1;
  _fd.steps.splice(insertAt, 0, newStep);
  _sidx = insertAt;
  _syncing = true; try { loadStepToDOM(); } finally { _syncing = false; }
  // Focus sur le titre pour édition immédiate
  var t = document.getElementById('pv-stitle');
  t.focus();
  document.execCommand('selectAll');
}

function deleteStep() {
  if (!_fd.steps || _fd.steps.length <= 1) {
    alert('Une formation doit avoir au moins une étape.');
    return;
  }
  if (!confirm('Supprimer l\'étape "' + (_fd.steps[_sidx].title || 'étape ' + (_sidx + 1)) + '" ?')) return;
  _fd.steps.splice(_sidx, 1);
  if (_sidx >= _fd.steps.length) _sidx = _fd.steps.length - 1;
  _syncing = true; try { loadStepToDOM(); } finally { _syncing = false; }
}

/* ===== Triggers ===== */

var _dragSrc = null;

function buildConditionOptions() {
  var opts = '<option value="">— choisir une condition…</option>';
  opts += '<option value="__page">📍 Page active</option>';
  opts += '<option value="__css">🔎 Présence / absence d\'un élément</option>';
  GUIDE_CONDITIONS.forEach(function (c) {
    opts += '<option value="' + c.id + '">' + c.label + '</option>';
  });
  return opts;
}

/* Condition d'activation (branche) : le trigger n'est pris en compte que si elle est vraie/fausse. */
function buildGateOptions() {
  var opts = '<option value="">⎇ toujours actif</option>';
  GUIDE_CONDITIONS.forEach(function (c) {
    opts += '<option value="met:' + c.id + '">si : ' + c.label + '</option>';
    opts += '<option value="not:' + c.id + '">si PAS : ' + c.label + '</option>';
  });
  return opts;
}

function setTriggerKind(row, kind) {
  var isAction = kind !== 'etat';
  row.querySelector('.tr-body-action').style.display = isAction ? 'flex' : 'none';
  row.querySelector('.tr-body-etat').style.display   = isAction ? 'none' : 'flex';
  row.querySelector('.tr-hint').style.display        = isAction ? '' : 'none';
  row.querySelector('.tr-req').style.marginLeft      = isAction ? '' : 'auto';
  if (!isAction) setEtatCondUI(row);
}

/* Affiche les champs spécifiques selon la condition intégrée sélectionnée (📍 Page active / 🔎 Élément). */
function setEtatCondUI(row) {
  var cond = row.querySelector('.tr-cond').value;
  row.querySelector('.tr-cond-page').style.display     = (cond === '__page') ? '' : 'none';
  row.querySelector('.tr-cond-css').style.display      = (cond === '__css')  ? '' : 'none';
  row.querySelector('.tr-cond-css-mode').style.display = (cond === '__css')  ? '' : 'none';
}

function addTrigger(t) {
  t = t || { kind: 'action', trigger: null, selector: '', required: false };
  var kind = t.kind || 'action';

  var row = document.createElement('div');
  row.className = 'tr-row';
  row.draggable = true;

  row.innerHTML =
    '<div class="tr-main">' +
      '<span class="tr-drag" title="Déplacer">⠿</span>' +
      '<select class="tr-kind" title="Type de trigger">' +
        '<option value="action">⚡ Action</option>' +
        '<option value="etat">✓ État</option>' +
      '</select>' +
      '<input type="text" class="tr-hint" placeholder="💬 Info-bulle (optionnelle)">' +
      '<label class="tr-req"><input type="checkbox"> Oblig.</label>' +
      '<button type="button" class="ge-btn ge-btn-del tr-del" title="Supprimer" onclick="removeTrigger(this)">✕</button>' +
    '</div>' +
    '<div class="tr-body tr-body-action">' +
      '<input type="text" class="tr-page" placeholder="/page ou *" title="Page : vide = page de l\'étape · * = toutes les pages">' +
      '<select class="tr-type">' +
        '<option value="null">— aucun</option>' +
        '<option value="click">clic</option>' +
        '<option value="dblclick">double-clic</option>' +
        '<option value="change">changement</option>' +
        '<option value="input">saisie (temps réel)</option>' +
        '<option value="keyup">touche relâchée</option>' +
        '<option value="keydown">touche pressée</option>' +
        '<option value="focus">focus</option>' +
        '<option value="submit">soumission</option>' +
        '<option value="mouseover">survol</option>' +
      '</select>' +
      '<input type="text" class="tr-sel" placeholder="#sélecteur-css" ' +
        'title="Id dynamique (ex : #d_q_QuSession_25360) ? Utilisez un sélecteur par préfixe : [id^=&quot;d_q_QuSession_&quot;]">' +
    '</div>' +
    '<div class="tr-body tr-body-etat" style="display:none">' +
      '<select class="tr-cond">' + buildConditionOptions() + '</select>' +
      '<input type="text" class="tr-cond-page" placeholder="/page à vérifier ou *" style="display:none" ' +
             'title="Page que l\'utilisateur doit avoir active (peut différer de la page de l\'étape)">' +
      '<input type="text" class="tr-cond-css" placeholder="#sélecteur-css" style="display:none" ' +
             'title="Élément à détecter (accepte [id^=&quot;…&quot;] pour les id dynamiques)">' +
      '<select class="tr-cond-css-mode" style="display:none" title="L\'élément doit être…">' +
        '<option value="present">présent</option>' +
        '<option value="absent">absent</option>' +
      '</select>' +
    '</div>' +
    '<div class="tr-gate" title="Branche conditionnelle : ce trigger n\'est pris en compte que si la condition est remplie">' +
      '<span class="tr-gate-label">⎇</span>' +
      '<select class="tr-gate-cond">' + buildGateOptions() + '</select>' +
    '</div>';

  // Valeurs initiales
  row.querySelector('.tr-kind').value        = kind;
  row.querySelector('.tr-page').value        = t.page      || '';
  row.querySelector('.tr-type').value        = t.trigger   || 'null';
  row.querySelector('.tr-sel').value         = t.selector  || '';
  row.querySelector('.tr-cond').value        = t.condition || '';
  row.querySelector('.tr-cond-page').value     = (t.condition === '__page') ? (t.page || '') : '';
  row.querySelector('.tr-cond-css').value      = (t.condition === '__css') ? (t.selector || '') : '';
  row.querySelector('.tr-cond-css-mode').value = t.absent ? 'absent' : 'present';
  row.querySelector('.tr-gate-cond').value   = t.when ? ('met:' + t.when) : (t.when_not ? ('not:' + t.when_not) : '');
  row.querySelector('.tr-req input').checked = !!t.required;
  row.querySelector('.tr-hint').value        = t.hint      || '';
  setTriggerKind(row, kind);

  // Listeners
  row.querySelector('.tr-kind').addEventListener('change', function () {
    setTriggerKind(row, this.value); captureAndSync();
  });
  row.querySelector('.tr-page').addEventListener('input',  captureAndSync);
  row.querySelector('.tr-type').addEventListener('change', captureAndSync);
  row.querySelector('.tr-sel').addEventListener('input',   captureAndSync);
  row.querySelector('.tr-cond').addEventListener('change', function () {
    setEtatCondUI(row); captureAndSync();
  });
  row.querySelector('.tr-cond-page').addEventListener('input', captureAndSync);
  row.querySelector('.tr-cond-css').addEventListener('input', captureAndSync);
  row.querySelector('.tr-cond-css-mode').addEventListener('change', captureAndSync);
  row.querySelector('.tr-gate-cond').addEventListener('change', captureAndSync);
  row.querySelector('.tr-req input').addEventListener('change', captureAndSync);
  row.querySelector('.tr-hint').addEventListener('input',  captureAndSync);

  initTriggerDnd(row);
  document.getElementById('triggers-list').appendChild(row);
  updateTriggersEmpty();
  captureAndSync();
}

function removeTrigger(btn) {
  btn.closest('.tr-row').remove();
  updateTriggersEmpty();
  captureAndSync();
}

function updateTriggersEmpty() {
  var list  = document.getElementById('triggers-list');
  var empty = list.querySelector('.tr-empty');
  if (list.querySelectorAll('.tr-row').length === 0) {
    if (!empty) {
      var p = document.createElement('p');
      p.className = 'tr-empty';
      p.textContent = 'Aucun trigger — le bouton Suivant est toujours libre.';
      list.appendChild(p);
    }
  } else {
    if (empty) empty.remove();
  }
}

function initTriggerDnd(row) {
  row.addEventListener('dragstart', function (e) {
    _dragSrc = row;
    e.dataTransfer.effectAllowed = 'move';
    setTimeout(function () { row.classList.add('tr-dragging'); }, 0);
  });
  row.addEventListener('dragend', function () {
    row.classList.remove('tr-dragging');
    document.querySelectorAll('#triggers-list .tr-row').forEach(function (r) { r.classList.remove('tr-over'); });
  });
  row.addEventListener('dragover',  function (e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
  row.addEventListener('dragenter', function ()  { if (row !== _dragSrc) row.classList.add('tr-over'); });
  row.addEventListener('dragleave', function ()  { row.classList.remove('tr-over'); });
  row.addEventListener('drop', function (e) {
    e.stopPropagation();
    row.classList.remove('tr-over');
    if (_dragSrc && _dragSrc !== row) {
      var list = document.getElementById('triggers-list');
      var items = Array.from(list.querySelectorAll('.tr-row'));
      var si = items.indexOf(_dragSrc), ti = items.indexOf(row);
      if (si < ti) list.insertBefore(_dragSrc, row.nextSibling);
      else         list.insertBefore(_dragSrc, row);
      captureAndSync();
    }
  });
}

/* ===== Toolbar de formatage ===== */

function fmt(cmd, val) {
  document.getElementById('pv-content').focus();
  document.execCommand(cmd, false, val || null);
  setTimeout(captureAndSync, 0);
}

function insertTip() {
  document.getElementById('pv-content').focus();
  document.execCommand('insertHTML', false,
    '<p class="guide-tip">⚠️ Texte du conseil ou avertissement</p><p></p>');
  setTimeout(captureAndSync, 0);
}

function insertCode() {
  document.getElementById('pv-content').focus();
  var sel = window.getSelection().toString();
  if (sel) document.execCommand('delete');
  document.execCommand('insertHTML', false,
    '<code>' + esc(sel || 'code') + '</code>');
  setTimeout(captureAndSync, 0);
}

/* ===== Images (base64 embarqué) ===== */

function readImageFile(input, cb) {
  var file = input.files && input.files[0];
  input.value = '';
  if (!file) return;
  if (!/^image\//.test(file.type)) { alert('Veuillez choisir une image (PNG, JPG, GIF…).'); return; }
  if (file.size > 2 * 1024 * 1024) {
    var mo = (file.size / 1024 / 1024).toFixed(1);
    if (!confirm('Cette image fait ' + mo + ' Mo.\nLes grosses images alourdissent la formation et sa synchronisation GitHub.\nContinuer quand même ?')) return;
  }
  var reader = new FileReader();
  reader.onload = function (e) { cb(e.target.result); };
  reader.readAsDataURL(file);
}

function handleFormationImageFile(input) {
  readImageFile(input, function (dataUrl) {
    if (!_fd) return;
    _fd.image = dataUrl;
    renderFormationImagePreview();
    captureAndSync();
  });
}
function removeFormationImage() {
  if (_fd) delete _fd.image;
  renderFormationImagePreview();
  captureAndSync();
}

function handleStepImageFile(input) {
  readImageFile(input, function (dataUrl) {
    if (!_fd || !_fd.steps || !_fd.steps[_sidx]) return;
    _fd.steps[_sidx].image = dataUrl;
    renderStepImagePreview();
    captureAndSync();
  });
}
function removeStepImage() {
  if (_fd && _fd.steps && _fd.steps[_sidx]) delete _fd.steps[_sidx].image;
  renderStepImagePreview();
  captureAndSync();
}

function setImageBox(id, img) {
  var wrap = document.getElementById(id);
  if (!wrap) return;
  if (img && /^data:image\//.test(img)) {
    var box = document.createElement('div');
    box.className = 'guide-img-16x9';
    var el = document.createElement('img');
    el.src = img;
    box.appendChild(el);
    wrap.innerHTML = '';
    wrap.appendChild(box);
    wrap.style.display = '';
  } else {
    wrap.innerHTML = '';
    wrap.style.display = 'none';
  }
}

function renderFormationImagePreview() {
  var img = _fd && _fd.image;
  setImageBox('f-img-preview', img);
  document.getElementById('f-img-remove').style.display = (img ? '' : 'none');
}

function renderStepImagePreview() {
  var img = (_fd && _fd.steps && _fd.steps[_sidx]) ? _fd.steps[_sidx].image : null;
  setImageBox('pv-step-image', img);
  setImageBox('step-img-thumb', img);
  document.getElementById('step-img-remove').style.display = (img ? '' : 'none');
}

/* ===== Enregistrement de triggers ===== */

function startRecording() {
  captureAndSync();
  if (!_fd || !_fd.id) { alert('La formation doit avoir un identifiant.'); return; }
  var step = _fd.steps[_sidx];
  if (!step) return;
  if (!confirm('Démarrer l\'enregistrement des triggers ?\n\n'
    + 'La formation va d\'abord être enregistrée, puis vous serez redirigé dans ianseo. '
    + 'Cliquez sur les éléments souhaités : ils seront ajoutés à cette étape. '
    + 'Cliquez sur « Terminer » dans le panneau rouge pour revenir ici.')) return;

  var json = document.getElementById('guide-json-editor').value;
  var fd = new FormData();
  fd.append('json_raw', json);
  fd.append('is_ajax', '1');
  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) { alert('Échec de la sauvegarde : ' + (data.error || 'inconnue')); return; }
      var rec = {
        active: true, paused: false,
        formation_id: _fd.id, step_id: step.id,
        // Forcer l'id dans l'URL de retour : une nouvelle formation sans ?id se recréerait à vide
        return_url: window.location.pathname + '?id=' + encodeURIComponent(_fd.id),
        triggers: []
      };
      localStorage.setItem('guide_rec', JSON.stringify(rec));
      var root = (typeof WebDir !== 'undefined') ? WebDir : '/';
      var page = (step.page && step.page !== '*') ? step.page : '';
      window.location.href = page ? (root.replace(/\/$/, '') + page) : root;
    })
    .catch(function () { alert('Erreur réseau lors de la sauvegarde.'); });
}

function checkRecResult() {
  var raw = localStorage.getItem('guide_rec_result');
  if (!raw) return;
  localStorage.removeItem('guide_rec_result');
  var res; try { res = JSON.parse(raw); } catch (e) { return; }
  if (!res || !res.triggers || !res.triggers.length) return;
  if (_fd && res.formation_id && res.formation_id !== _fd.id) return;

  var idx = -1;
  if (res.step_id) {
    idx = _fd.steps.findIndex(function (s) { return s.id === res.step_id; });
  }
  if (idx < 0) idx = _sidx;
  _sidx = idx;
  var step = _fd.steps[idx];
  if (!step.triggers) step.triggers = [];
  res.triggers.forEach(function (t) { step.triggers.push(t); });
  syncToDOM();
  alert(res.triggers.length + ' trigger(s) enregistré(s) ont été ajoutés à l\'étape « '
    + (step.title || ('étape ' + (idx + 1))) + ' ».\nVérifiez-les puis enregistrez la formation.');
}

/* ===== Export / Import (.ianseo = JSON compressé zlib côté serveur) ===== */

function exportIanseo() {
  captureAndSync();
  var json = document.getElementById('guide-json-editor').value;
  try { JSON.parse(json); } catch (e) { alert('JSON invalide.'); return; }
  // Soumission par formulaire caché → la réponse binaire déclenche le téléchargement du .ianseo
  var f  = document.createElement('form');
  f.method = 'POST'; f.action = ''; f.style.display = 'none';
  var i1 = document.createElement('input'); i1.type = 'hidden'; i1.name = 'action';   i1.value = 'export-ianseo';
  var i2 = document.createElement('input'); i2.type = 'hidden'; i2.name = 'json_raw'; i2.value = json;
  f.appendChild(i1); f.appendChild(i2);
  document.body.appendChild(f);
  f.submit();
  document.body.removeChild(f);
}

function handleImport(e) {
  var file = e.target.files[0]; if (!file) return;
  var fd = new FormData();
  fd.append('action', 'import-ianseo');
  fd.append('file', file);
  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok && data.formation) {
        _fd = data.formation; _sidx = 0; syncToDOM();
        showSaveStatus('ok', '✓ Formation importée — vérifiez puis enregistrez');
      } else {
        alert('Import : ' + (data.error || 'erreur inconnue'));
      }
    })
    .catch(function () { alert('Erreur réseau lors de l\'import.'); });
  e.target.value = '';
}

/* ===== Sauvegarde ===== */

function prepareSave() {
  captureAndSync();
  var btn  = document.getElementById('btn-save');
  var json = document.getElementById('guide-json-editor').value;

  btn.disabled    = true;
  btn.textContent = '⏳ Enregistrement…';

  var fd = new FormData();
  fd.append('json_raw', json);
  fd.append('is_ajax',  '1');

  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok) {
        showSaveStatus('ok', '✓ Formation enregistrée');
      } else {
        showSaveStatus('err', '✗ ' + (data.error || 'Erreur inconnue'));
      }
    })
    .catch(function () {
      showSaveStatus('err', '✗ Erreur réseau');
    })
    .finally(function () {
      btn.disabled    = false;
      btn.textContent = '💾 Enregistrer la formation';
    });
}

function showSaveStatus(type, msg) {
  var el = document.getElementById('save-status');
  el.textContent = msg;
  el.style.color   = type === 'ok' ? '#1a7a3a' : '#b00020';
  el.style.display = 'inline';
  clearTimeout(el._t);
  el._t = setTimeout(function () { el.style.display = 'none'; }, 3000);
}

/* ===== JSON accordéon ===== */

function toggleJson() {
  var body = document.getElementById('ge-json-body');
  var show = body.style.display !== 'block';
  body.style.display = show ? 'block' : 'none';
  document.getElementById('json-icon').textContent = show ? '▼' : '▶';
}

/* ===== Utilitaires ===== */

function getVal(id)    { return document.getElementById(id).value; }
function setVal(id, v) {
  var el = document.getElementById(id);
  if (el.tagName === 'SELECT') el.value = (v === null || v === undefined) ? 'null' : String(v);
  else el.value = v;
}
function setText(id, t) { document.getElementById(id).textContent = t; }

function showJsonError(show) {
  document.getElementById('json-err').style.display = show ? '' : 'none';
}

function cleanHtml(html) {
  html = html
    .replace(/<p><br\s*\/?><\/p>$/gi, '')
    .replace(/<div><br\s*\/?><\/div>/gi, '')
    .trim();
  // Nettoyage DOM : retire les style="" inline sur ul/ol/li
  // (Chrome peut en ajouter via execCommand et ça écrase notre CSS)
  var tmp = document.createElement('div');
  tmp.innerHTML = html;
  tmp.querySelectorAll('ul, ol, li').forEach(function(el) {
    el.removeAttribute('style');
  });
  return tmp.innerHTML;
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
