<?php
/**
 * Visual course editor of the Interactive Guide module.
 *
 * Builds the content file of a course: its steps, the element each step points
 * at, the quiz and the challenge. The alternative to this screen is writing the
 * JSON by hand, which the editor still allows through its "JSON source" panel —
 * the two views edit the same document.
 *
 * THE TRIGGER RECORDER is what makes the editor usable at all. Writing a CSS
 * selector for an ianseo element by hand means reading ianseo's HTML; instead,
 * the editor sends the author into the real software with a recording panel
 * (rendered by the module's menu.php, so it exists on every page), and each
 * click there becomes a trigger. The result travels back through localStorage.
 *
 * IMPORT AND EXPORT use the .ianseo convention: the JSON compressed with zlib,
 * as ianseo's own exports are. Import decodes with json_decode and never
 * unserialize, so a crafted file cannot instantiate objects.
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

$adminUrl    = (function_exists('cmod_url') ? cmod_url(dirname(__DIR__)) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/') . 'admin/';
$contentDir  = dirname(__DIR__) . '/content/';
$editId      = isset($_GET['id']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['id'])) : '';
// Condition labels are multilingual; resolve them here rather than in the
// browser, so the script below only ever handles plain strings. Only the label
// is display text — the id is what a course stores, and it stays untouched.
$conditions = [];
foreach (guide_load_conditions() as $c) {
    $c['label'] = guide_i18n($c['label'] ?? '');
    $conditions[] = $c;
}

/**
 * Base language offered for a course that does not declare one.
 *
 * The author's own interface language, not English: a course starts out as
 * plain strings in whatever language its author writes, and that is what the
 * base language names. Defaulting to English filed a French author's original
 * text under "en" the first time they translated a field — the course then
 * carried its French as its English translation.
 *
 * Narrowed to the languages the selector offers, a regional code through its
 * parent (fr-ca gives fr), so the value always matches an option.
 */
$editorLangs    = ['en', 'fr', 'it', 'de', 'es'];
$editorBaseLang = 'en';
foreach ([guide_lang_code(), mb_substr(guide_lang_code(), 0, 2)] as $try) {
    if (in_array($try, $editorLangs, true)) { $editorBaseLang = $try; break; }
}

$action = $_POST['action'] ?? '';

/* ---- Export .ianseo: the JSON compressed with zlib, as ianseo's own exports ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'export-ianseo') {
    $data = json_decode($_POST['json_raw'] ?? '', true);
    if (!$data || empty($data['id'])) { http_response_code(400); echo guide_text('EdErrJson'); exit; }
    $payload = gzcompress(json_encode($data, JSON_UNESCAPED_UNICODE), 9);
    $fname   = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['id'])) . '.ianseo';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    // bytes: Content-Length counts bytes on the wire, so strlen is the right
    // one here — mb_strlen would understate a compressed payload and truncate
    // the download.
    header('Content-Length: ' . strlen($payload));
    echo $payload;
    exit;
}

/* ---- Import .ianseo, falling back to a plain .json ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import-ianseo') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => guide_text('EdErrFileMissing')]); exit;
    }
    $raw  = file_get_contents($_FILES['file']['tmp_name']);
    $json = @gzuncompress($raw);          // .ianseo (zlib)
    if ($json === false) $json = $raw;    // fall back to an uncompressed .json
    $data = json_decode($json, true);
    if (!$data || empty($data['id']) || !isset($data['steps'])) {
        echo json_encode(['error' => guide_text('EdErrFileNotCourse')]); exit;
    }
    echo json_encode(['ok' => true, 'formation' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Save ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['json_raw'])) {
    $isAjax = !empty($_POST['is_ajax']);
    $error  = null;
    $data   = json_decode($_POST['json_raw'], true);
    if (!$data || empty($data['id'])) {
        $error = guide_text('EdErrJsonNoId');
    } else {
        $cleanId = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['id']));
        if ($cleanId !== $data['id']) {
            $error = guide_text('EdErrIdChars');
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
                $error = guide_text('EdErrWrite');
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

/* ---- Load ---- */
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
            'id' => 'step-' . substr(md5(uniqid()), 0, 6),   // bytes: md5 is hex, one byte per character
            'title' => guide_text('EdStepTitlePh'),
            'content' => guide_text('EdNewStepBody'),
            'page' => null, 'triggers' => [],
        ]],
    ];
}

// guide_i18n(): the title of a translated course is a language map, and the
// page title needs the reader's own language, not the whole map.
$PAGE_TITLE = $editId ? guide_text('EdTitleEdit', guide_i18n($formation['title'])) : guide_text('EdTitleNew');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
/* ===== Layout ===== */

.ge-top-bar  { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; align-items: center; }

/* Course metadata */
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

/* ===== Editor: two columns ===== */
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

/* ===== The guide panel =====
   Deliberately styled like the real panel: the author edits what the learner
   will actually see, rather than a form that only resembles it. */
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

/* ===== Editable areas ===== */
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
/* Content styling, mirroring guide.css so the preview matches the panel */
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

/* 16:9 frame with automatic letterboxing — editor preview and thumbnails */
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

/* Panel navigation bar */
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

/* ===== Step actions and options ===== */
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
/* Cancels the width:100% of .ge-opt input[type=text] for the trigger fields */
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

/* ===== Quiz and challenge sections ===== */
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

/* ===== Raw JSON accordion ===== */
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

<h1><?= htmlspecialchars($editId ? guide_text('EdHeadingEdit') : guide_text('EdTitleNew')) ?></h1>
<p><a href="<?= htmlspecialchars($adminUrl) ?>">← <?= htmlspecialchars(guide_text('EdBackList')) ?></a></p>

<?php if (!empty($error)): ?>
  <div class="ge-err"><?= htmlspecialchars(guide_text('EdErrorLabel')) ?> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="ge-main">

<!-- Export and import bar -->
<div class="ge-top-bar">
  <button class="ge-btn ge-btn-ghost" onclick="exportIanseo()" title="<?= htmlspecialchars(guide_text('EdExportHint')) ?>">⬇ <?= htmlspecialchars(guide_text('EdExport')) ?></button>
  <label class="ge-btn ge-btn-ghost" style="cursor:pointer">
    ⬆ <?= htmlspecialchars(guide_text('EdImport')) ?>
    <input type="file" id="import-file" accept=".ianseo,.json,application/json" style="display:none">
  </label>
  <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/help.php" target="_blank"
     class="ge-btn ge-btn-ghost" style="text-decoration:none;margin-left:auto">❔ <?= htmlspecialchars(guide_text('EdHelpLink')) ?></a>
</div>

<!-- Course metadata -->
<div class="ge-meta">
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldTitle')) ?></label>
    <input type="text" id="f-title" placeholder="<?= htmlspecialchars(guide_text('EdTitlePlaceholder')) ?>" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldDesc')) ?></label>
    <input type="text" id="f-description" placeholder="<?= htmlspecialchars(guide_text('EdDescPlaceholder')) ?>" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldVersion')) ?></label>
    <input type="text" id="f-version" placeholder="1.0" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldGroup')) ?> <span style="text-transform:none;font-weight:400;color:#999"><?= htmlspecialchars(guide_text('EdGroupHint')) ?></span></label>
    <input type="text" id="f-group" placeholder="<?= htmlspecialchars(guide_text('EdGroupPlaceholder')) ?>" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldSubgroup')) ?></label>
    <input type="text" id="f-subgroup" placeholder="<?= htmlspecialchars(guide_text('EdSubgroupPlaceholder')) ?>" oninput="captureAndSync()">
  </div>
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldOrder')) ?></label>
    <input type="number" id="f-order" placeholder="10" oninput="captureAndSync()">
  </div>
  <!-- Editing language. A course carries every language it has been written in,
       inside its own file; this selector says which one the fields below show
       and write. The original language is the one plain strings are written in,
       which is what lets the editor turn a plain string into a language map
       without mislabelling the text that was already there. -->
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldEditLang')) ?></label>
    <select id="f-lang" onchange="switchLang(this.value)"></select>
  </div>
  <div class="ge-meta-field">
    <label><?= htmlspecialchars(guide_text('EdFieldBaseLang')) ?></label>
    <?php
    /* Pre-selected on the author's own language rather than on whichever option
       comes first, so a new course never starts by calling its text English. */
    $baseOptions = ['en' => 'LangEn', 'fr' => 'LangFr', 'it' => 'LangIt',
                    'de' => 'LangDe', 'es' => 'LangEs'];
    echo '<select id="f-baselang" onchange="captureAndSync()">';
    foreach ($baseOptions as $code => $key) {
        echo '<option value="' . $code . '"' . ($code === $editorBaseLang ? ' selected' : '') . '>'
           . htmlspecialchars(guide_text($key), ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
    ?>
  </div>
</div>
<p style="font-size:11px;color:#aaa;margin:-12px 0 14px">
  ID : <code style="background:#f0f4ff;padding:1px 6px;border-radius:3px;color:#555"><?= htmlspecialchars($formation['id']) ?></code>
  <?= htmlspecialchars(guide_text('EdIdHint')) ?>
</p>

<!-- Course thumbnail -->
<div class="ge-opt" style="margin-bottom:18px">
  <label><?= htmlspecialchars(guide_text('EdThumbnail')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdThumbnailHint')) ?></span></label>
  <div class="ge-img-ctrl">
    <div id="f-img-preview" style="display:none"></div>
    <div>
      <label class="ge-btn ge-btn-ghost" style="cursor:pointer">
        🖼 <?= htmlspecialchars(guide_text('EdChooseImage')) ?>
        <input type="file" accept="image/*" style="display:none" onchange="handleFormationImageFile(this)">
      </label>
      <button type="button" class="ge-btn ge-btn-del" id="f-img-remove" style="display:none;margin-left:6px" onclick="removeFormationImage()"><?= htmlspecialchars(guide_text('EdRemove')) ?></button>
    </div>
  </div>
</div>

<!-- Editor -->
<div class="ge-editor">

  <!-- Left column: the editable panel -->
  <div class="ge-left">

    <!-- Formatting toolbar -->
    <div class="ge-toolbar">
      <button class="tb" onclick="fmt('bold')"    title="<?= htmlspecialchars(guide_text('EdBold')) ?>"><b>B</b></button>
      <button class="tb" onclick="fmt('italic')"  title="<?= htmlspecialchars(guide_text('EdItalic')) ?>"><i>I</i></button>
      <button class="tb" onclick="fmt('underline')" title="<?= htmlspecialchars(guide_text('EdUnderline')) ?>"><u>U</u></button>
      <input  type="color" class="tb-color" id="txt-color" value="#082c7c"
              onchange="fmt('foreColor',this.value)" title="<?= htmlspecialchars(guide_text('EdTextColour')) ?>">
      <button class="tb" onclick="fmt('removeFormat')" title="<?= htmlspecialchars(guide_text('EdRemoveFormat')) ?>" style="font-size:11px;color:#888">✕fmt</button>
      <div class="tb-sep"></div>
      <button class="tb" onclick="fmt('insertUnorderedList')" title="<?= htmlspecialchars(guide_text('EdBullets')) ?>">• ≡</button>
      <button class="tb" onclick="fmt('insertOrderedList')"   title="<?= htmlspecialchars(guide_text('EdNumbered')) ?>">1. ≡</button>
      <div class="tb-sep"></div>
      <button class="tb tb-tip"  onclick="insertTip()"  title="<?= htmlspecialchars(guide_text('EdTipHint')) ?>">💡 <?= htmlspecialchars(guide_text('EdTip')) ?></button>
      <button class="tb tb-code" onclick="insertCode()" title="<?= htmlspecialchars(guide_text('EdCode')) ?>">&lt;/&gt;</button>
    </div>

    <!-- The panel, exactly as a learner sees it -->
    <div class="ge-panel">
      <div class="ge-panel-header">
        <span class="ge-panel-header-title"><?= htmlspecialchars(guide_text('ModuleName')) ?></span>
        <span class="ge-panel-header-hint"><?= htmlspecialchars(guide_text('EdEditorBadge')) ?></span>
      </div>
      <div class="ge-panel-fname" id="pv-fname"></div>
      <div class="ge-panel-prog">
        <div class="ge-panel-prog-bar">
          <div class="ge-panel-prog-fill" id="pv-fill" style="width:0%"></div>
        </div>
        <span class="ge-panel-prog-txt" id="pv-prog-txt"></span>
      </div>

      <!-- Step image preview -->
      <div id="pv-step-image" style="display:none"></div>

      <!-- Step title, edited in place -->
      <div id="pv-stitle" contenteditable="true"
           data-ph="<?= htmlspecialchars(guide_text('EdStepTitlePh')) ?>"
           oninput="captureAndSync()"
           onkeydown="if(event.key==='Enter'){event.preventDefault();}">
      </div>

      <!-- Step content, edited in place -->
      <div id="pv-content" contenteditable="true"
           data-ph="<?= htmlspecialchars(guide_text('EdContentPh')) ?>"
           oninput="captureAndSync()">
      </div>

      <!-- Navigation, working as it does during a real course -->
      <div class="ge-panel-nav">
        <button class="ge-panel-nav-btn" id="pv-btn-prev" onclick="navStep(-1)">◀ <?= htmlspecialchars(guide_text('CmdPrev')) ?></button>
        <button class="ge-panel-nav-btn" id="pv-btn-next" onclick="navStep(1)"><?= htmlspecialchars(guide_text('CmdNextStep')) ?> ▶</button>
      </div>
    </div>

    <!-- Step actions -->
    <div class="ge-step-acts">
      <button class="ge-btn ge-btn-add" onclick="addStep(-1)" title="<?= htmlspecialchars(guide_text('EdAddBeforeHint')) ?>">+ <?= htmlspecialchars(guide_text('EdAddBefore')) ?></button>
      <button class="ge-btn ge-btn-add" onclick="addStep(1)"  title="<?= htmlspecialchars(guide_text('EdAddAfterHint')) ?>">+ <?= htmlspecialchars(guide_text('EdAddAfter')) ?></button>
      <button class="ge-btn ge-btn-del" onclick="deleteStep()" title="<?= htmlspecialchars(guide_text('EdDeleteStepHint')) ?>">✕</button>
    </div>

  </div><!-- /ge-left -->

  <!-- Right-hand column: options -->
  <div class="ge-right">

    <!-- Step options -->
    <div class="ge-opts">
      <div class="ge-opts-title"><?= htmlspecialchars(guide_text('EdStepOptions')) ?></div>

      <div class="ge-opt">
        <label><?= htmlspecialchars(guide_text('EdDefaultPage')) ?> <span class="ge-hint"><?= guide_text('EdDefaultPageHint') ?></span></label>
        <input type="text" id="opt-page" placeholder="<?= htmlspecialchars(guide_text('EdOptPagePh')) ?>" oninput="captureAndSync()">
      </div>

      <div class="ge-opt">
        <label><?= htmlspecialchars(guide_text('EdStepImage')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdStepImageHint')) ?></span></label>
        <div class="ge-img-ctrl">
          <div id="step-img-thumb" style="display:none"></div>
          <div>
            <label class="ge-btn ge-btn-ghost" style="cursor:pointer;font-size:12px;padding:5px 12px">
              🖼 <?= htmlspecialchars(guide_text('EdChoose')) ?>
              <input type="file" accept="image/*" style="display:none" onchange="handleStepImageFile(this)">
            </label>
            <button type="button" class="ge-btn ge-btn-del" id="step-img-remove" style="display:none;margin-top:6px;font-size:12px" onclick="removeStepImage()"><?= htmlspecialchars(guide_text('EdRemove')) ?></button>
          </div>
        </div>
      </div>

      <div class="ge-opt">
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;">
          <input type="checkbox" id="opt-optional" onchange="captureAndSync()" style="margin:0;">
          <?= htmlspecialchars(guide_text('EdOptional')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdOptionalHint')) ?></span>
        </label>
      </div>

      <div class="ge-opt">
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;">
          <input type="checkbox" id="opt-strict-click" onchange="captureAndSync()" style="margin:0;">
          <?= htmlspecialchars(guide_text('EdStrict')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdStrictHint')) ?></span>
        </label>
      </div>

      <div class="ge-opt">
        <label>
          Triggers
          <span class="ge-hint"><?= htmlspecialchars(guide_text('EdTriggersHint')) ?></span>
        </label>
        <div id="triggers-list"></div>
        <button type="button" class="ge-btn ge-btn-ghost"
                onclick="addTrigger()"
                style="margin-top:8px;font-size:12px;padding:5px 14px">
          + <?= htmlspecialchars(guide_text('EdAddTrigger')) ?>
        </button>
        <button type="button" class="ge-btn ge-btn-ghost"
                onclick="startRecording()"
                style="margin-top:8px;margin-left:6px;font-size:12px;padding:5px 14px;border-color:#e0b4ae;color:#c0392b"
                title="<?= htmlspecialchars(guide_text('EdRecordHint')) ?>">
          🔴 <?= htmlspecialchars(guide_text('EdRecord')) ?>
        </button>
      </div>
    </div>

  </div><!-- /ge-right -->
</div><!-- /ge-editor -->

<!-- The other two activities: quiz and challenge -->
<details class="ge-extra" id="sect-quiz">
  <summary>📝 <?= htmlspecialchars(guide_text('EdQuizSummary')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdQuizHint')) ?></span></summary>
  <div class="ge-extra-body">
    <div class="ge-opt" style="max-width:240px">
      <label><?= htmlspecialchars(guide_text('EdPassScore')) ?></label>
      <input type="number" id="quiz-pass" min="1" max="100" placeholder="70" oninput="captureAndSync()">
    </div>
    <div class="ge-opt">
      <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;text-transform:none;">
        <input type="checkbox" id="quiz-shuffle" onchange="captureAndSync()" style="margin:0;">
        <?= htmlspecialchars(guide_text('EdShuffle')) ?>
      </label>
    </div>
    <div id="quiz-list"></div>
    <button type="button" class="ge-btn ge-btn-ghost" onclick="addQuizQuestion()" style="font-size:12px;padding:5px 14px">
      + <?= htmlspecialchars(guide_text('EdAddQuestion')) ?>
    </button>
  </div>
</details>

<details class="ge-extra" id="sect-defi">
  <summary>🎯 <?= htmlspecialchars(guide_text('EdChallengeSummary')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdChallengeHint')) ?></span></summary>
  <div class="ge-extra-body">
    <div class="ge-opt">
      <label><?= htmlspecialchars(guide_text('EdBrief')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdBriefHint')) ?></span></label>
      <textarea id="defi-intro" rows="3" oninput="captureAndSync()"
                placeholder="<?= htmlspecialchars(guide_text('EdBriefPh')) ?>"></textarea>
    </div>
    <div class="ge-opt">
      <label><?= htmlspecialchars(guide_text('EdConditionsToMeet')) ?> <span class="ge-hint"><?= htmlspecialchars(guide_text('EdConditionsHint')) ?></span></label>
      <div id="defi-conds"></div>
      <p class="ge-hint"><?= guide_text('EdConditionsLink') ?></p>
    </div>
  </div>
</details>

<!-- The same document as raw JSON -->
<div class="ge-json-sect">
  <button class="ge-json-toggle" onclick="toggleJson()">
    <span id="json-icon">▶</span>
    <?= htmlspecialchars(guide_text('EdJsonToggle')) ?>
  </button>
  <div id="ge-json-body">
    <textarea id="guide-json-editor" spellcheck="false"></textarea>
    <div class="ge-json-err" id="json-err">⚠ <?= htmlspecialchars(guide_text('EdErrJson')) ?></div>
    <div style="margin-top:6px">
      <button class="ge-btn ge-btn-apply" onclick="applyJson()">↺ <?= htmlspecialchars(guide_text('EdApplyJson')) ?></button>
    </div>
  </div>
</div>

<!-- Save -->
<div class="ge-mt" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <button type="button" class="ge-btn ge-btn-save" id="btn-save" onclick="prepareSave()">💾 <?= htmlspecialchars(guide_text('EdSave')) ?></button>
  <span id="save-status" style="display:none;font-size:13px;font-weight:600;"></span>
</div>

</div><!-- /ge-main -->

<script>
/* ===== State conditions, published from PHP ===== */
var GUIDE_CONDITIONS = <?= json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE) ?>;

/* ===== Translations =====
   The editor builds most of its widgets in JavaScript, so its wording travels
   here rather than going through guide_text() at render time. */
var GE_T = <?= json_encode([
    'langNames' => [
        'en' => guide_text('LangEn'), 'fr' => guide_text('LangFr'), 'it' => guide_text('LangIt'),
        'de' => guide_text('LangDe'), 'es' => guide_text('LangEs'),
    ],
    /* The author's own interface language, used as the base language of a course
       that has not declared one. Assuming English there labelled a French
       author's text as English, and the first translation then filed it under
       "en" — a course whose English was the original French.
       Narrowed to the languages the base-language selector offers, a regional
       code through its parent (fr-ca gives fr), so the value always selects. */
    'uiLang'        => $editorBaseLang,
    'toTranslate'   => guide_text('EdToTranslate'),
    'step'          => guide_text('JsStep'),
    'newStep'       => guide_text('EdNewStep'),
    'newStepBody'   => guide_text('EdNewStepBody'),
    'errOneStep'    => guide_text('EdErrOneStep'),
    'errNoId'       => guide_text('EdErrNoId'),
    'delStepConfirm'=> guide_text('EdDelStepConfirm'),
    'stepFallback'  => guide_text('EdStepFallback'),
    'noConditions'  => guide_text('EdNoConditions'),
    'correctAnswer' => guide_text('EdCorrectAnswer'),
    'answerPh'      => guide_text('EdAnswerPh'),
    'optionalSuffix'=> guide_text('EdOptionalSuffix'),
    'answersLabel'  => guide_text('EdAnswersLabel'),
    'explainLabel'  => guide_text('EdExplainLabel'),
    'explainPh'     => guide_text('EdExplainPh'),
    'questionPh'    => guide_text('EdQuestionPh'),
    'condCss'       => guide_text('EdCondCss'),
    'dragHint'      => guide_text('EdDragHint'),
    'kindAction'    => guide_text('EdKindAction'),
    'kindState'     => guide_text('EdKindState'),
    'pagePh'        => guide_text('EdPagePh'),
    'pageHint'      => guide_text('EdPageHint'),
    'triggerInput'  => guide_text('EdTriggerInput'),
    'triggerKeyup'  => guide_text('EdTriggerKeyup'),
    'triggerKeydown'=> guide_text('EdTriggerKeydown'),
    'selectorPh'    => guide_text('EdSelectorPh'),
    'selectorHint'  => guide_text('EdSelectorHint'),
    'condPagePh'    => guide_text('EdCondPagePh'),
    'condPageHint'  => guide_text('EdCondPageHint'),
    'condCssPh'     => guide_text('EdCondCssPh'),
    'condCssHint'   => guide_text('EdCondCssHint'),
    'cssModeHint'   => guide_text('EdCssModeHint'),
    'cssPresent'    => guide_text('EdCssPresent'),
    'imageBig'      => guide_text('EdImageBig'),
    'recConfirm'    => guide_text('EdRecConfirm'),
    'saveFailed'    => guide_text('EdSaveFailed'),
    'unknown'       => guide_text('EdUnknown'),
    'networkSave'   => guide_text('EdNetworkSave'),
    'triggersAdded' => guide_text('EdTriggersAdded'),
    'imported'      => guide_text('EdImported'),
    'networkImport' => guide_text('EdNetworkImport'),
    'saved'         => guide_text('EdSaved'),
    'networkErr'    => guide_text('EdNetworkErr'),
    'question'      => guide_text('EdQuestion'),
    'untitled'      => guide_text('EdUntitled'),
    'next'          => guide_text('CmdNextStep'),
    'finish'        => guide_text('JsFinish'),
    'chooseCond'    => guide_text('EdChooseCondition'),
    'condPage'      => guide_text('EdCondPage'),
    'gateAlways'    => guide_text('EdGateAlways'),
    'gateIf'        => guide_text('EdGateIf'),
    'gateIfNot'     => guide_text('EdGateIfNot'),
    'gateHint'      => guide_text('EdGateHint'),
    'triggerKind'   => guide_text('EdTriggerKind'),
    'hintPh'        => guide_text('EdHintPh'),
    'requiredShort' => guide_text('EdRequiredShort'),
    'delete'        => guide_text('EdDelete'),
    'triggerNone'   => guide_text('EdTriggerNone'),
    'triggerClick'  => guide_text('EdTriggerClick'),
    'triggerDbl'    => guide_text('EdTriggerDblClick'),
    'triggerChange' => guide_text('EdTriggerChange'),
    'triggerFocus'  => guide_text('EdTriggerFocus'),
    'triggerSubmit' => guide_text('EdTriggerSubmit'),
    'triggerHover'  => guide_text('EdTriggerHover'),
    'cssAbsent'     => guide_text('EdCssAbsent'),
    'noTrigger'     => guide_text('EdNoTrigger'),
    'tipBody'       => guide_text('EdTipBody'),
    'errNotImage'   => guide_text('EdErrNotImage'),
    'errJson'       => guide_text('EdErrJson'),
    'importPrefix'  => guide_text('EdImportPrefix'),
    'unknownError'  => guide_text('EdUnknownError'),
    'saving'        => guide_text('EdSaving'),
    'save'          => guide_text('EdSave'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* ===== State ===== */
var _fd      = null;  // the course being edited
var _sidx    = 0;     // index of the step on screen
var _syncing = false; // guards against sync loops
var _lang    = '';    // language being edited; '' means the course's own base language

/* ===== Multilingual fields =====
   A course carries every language it has been written in, inside its own file.
   Only the TEXT of a field becomes a map; the structure around it — steps,
   selectors, pages — is never duplicated, so the languages cannot drift apart
   and point at different elements. Mirrors guide_i18n() on the PHP side.

   The file's `lang` says which language its plain strings are written in. It is
   what lets the editor turn a plain string into a map without guessing: the old
   text keeps its own language rather than being mislabelled as the new one.
   The language being EDITED cannot answer that question — it says where the
   author is typing now, not what the untouched text already is.

   A course that declares nothing falls back to the author's own interface
   language, not to English: someone writing in French would otherwise have
   their text filed under "en" the first time they translated a field. */

var LANG_NAMES = GE_T.langNames;

function baseLang() { return (_fd && _fd.lang) || GE_T.uiLang || 'en'; }
function editLang() { return _lang || baseLang(); }

// Is this value a language map rather than ordinary content?
function isLangMap(v) {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return false;
  var keys = Object.keys(v);
  if (!keys.length) return false;
  return keys.every(function (k) {
    return /^[a-z]{2}(-[a-z]{2})?$/.test(k) && typeof v[k] === 'string';
  });
}

// Does this field hold text in ANY language? What decides whether a piece of
// the course still exists, as opposed to i18nGet(), which answers for the one
// language on screen. Translating a course into a third language must not make
// its answers look empty and delete them.
function i18nAny(v) {
  if (isLangMap(v)) return Object.keys(v).some(function (k) { return v[k] !== ''; });
  return !!(v && String(v).trim() !== '');
}

// The text to show for the language being edited. Empty rather than a fallback:
// the author has to SEE that a translation is missing before they can add it.
function i18nGet(v) {
  if (!isLangMap(v)) return (editLang() === baseLang()) ? (v || '') : '';
  return v[editLang()] || '';
}

// Write the edited text back, keeping the other languages.
function i18nSet(current, value) {
  var lang = editLang();

  if (isLangMap(current)) {
    var out = {};
    Object.keys(current).forEach(function (k) { out[k] = current[k]; });
    if (value === '') delete out[lang]; else out[lang] = value;
    // Down to a single language: store it plainly again rather than leave a map
    // of one, so a course that loses its translations reads as it did before.
    var left = Object.keys(out);
    if (!left.length) return '';
    if (left.length === 1 && left[0] === baseLang()) return out[left[0]];
    return out;
  }

  if (lang === baseLang()) return value;          // still a single language

  // First translation of this field: keep the existing text under the base
  // language so it is not silently relabelled.
  var map = {};
  if (current) map[baseLang()] = current;
  if (value)   map[lang] = value;
  return Object.keys(map).length ? map : '';
}

// Fill the language selector with the languages this course already has, plus
// the ones it could be translated into.
function renderLangSelect() {
  var sel = document.getElementById('f-lang');
  if (!sel || !_fd) return;

  var present = {};
  (function walk(node) {
    if (isLangMap(node)) { Object.keys(node).forEach(function (k) { present[k] = true; }); return; }
    if (node && typeof node === 'object') Object.keys(node).forEach(function (k) { walk(node[k]); });
  })(_fd);
  present[baseLang()] = true;

  var codes = Object.keys(LANG_NAMES).sort(function (a, b) {
    var pa = present[a] ? 0 : 1, pb = present[b] ? 0 : 1;
    return pa - pb || a.localeCompare(b);
  });

  sel.innerHTML = codes.map(function (c) {
    var label = (LANG_NAMES[c] || c) + (present[c] ? '' : ' — ' + GE_T.toTranslate);
    return '<option value="' + c + '"' + (c === editLang() ? ' selected' : '') + '>' + label + '</option>';
  }).join('');

  var base = document.getElementById('f-baselang');
  if (base) base.value = baseLang();
}

function switchLang(code) {
  // Captured while the OLD language is still current, or what the author just
  // typed would be written under the language they are switching to.
  captureFormation();
  captureStep();
  captureExtras();
  _lang = code;
  _syncing = true;
  try { syncToDOM(); } finally { _syncing = false; }
}

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

/* Enter inside a tip box leaves it and starts an ordinary paragraph.
   Shift+Enter stays inside it, inserting a line break, which is the native behaviour. */
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

/* ===== Activities: quiz and challenge ===== */

function initDefiConds() {
  var box = document.getElementById('defi-conds');
  box.innerHTML = '';
  if (!GUIDE_CONDITIONS.length) {
    box.innerHTML = '<p class="ge-hint">' + esc(GE_T.noConditions) + '</p>';
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
        '<input type="checkbox" class="qz-correct"' + (correct.indexOf(i) !== -1 ? ' checked' : '') + ' title="' + esc(GE_T.correctAnswer) + '">' +
        '<input type="text" class="qz-choice" placeholder="' + esc(GE_T.answerPh.replace('{$a}', i + 1) + (i > 1 ? GE_T.optionalSuffix : '')) + '" value="">' +
      '</div>';
  }
  block.innerHTML =
    '<div class="qz-head"><b>' + esc(GE_T.question) + '</b>' +
      '<button type="button" class="ge-btn ge-btn-del" style="font-size:11px;padding:3px 8px" onclick="this.closest(\'.qz-block\').remove();captureAndSync()">✕</button>' +
    '</div>' +
    '<textarea class="qz-q" rows="2" placeholder="' + esc(GE_T.questionPh) + '"></textarea>' +
    '<div class="qz-lbl">' + esc(GE_T.answersLabel) + '</div>' +
    choicesHtml +
    '<div class="qz-lbl">' + esc(GE_T.explainLabel) + '</div>' +
    '<input type="text" class="qz-explain" placeholder="' + esc(GE_T.explainPh) + '">';

  // The block is the only place the other languages of this question survive:
  // the quiz is rebuilt from the DOM on every capture, so the original object is
  // thrown away. Indexed BY ROW, never by the compacted output, so an answer
  // left empty in one language cannot shift the rest out of alignment.
  block._i18n = { q: q.q, choices: (q.choices || []).slice(), explain: q.explain };

  block.querySelector('.qz-q').value = i18nGet(q.q);
  var inputs = block.querySelectorAll('.qz-choice');
  (q.choices || []).forEach(function (c, i) { if (inputs[i]) inputs[i].value = i18nGet(c); });
  block.querySelector('.qz-explain').value = i18nGet(q.explain);

  block.querySelectorAll('textarea, input').forEach(function (el) {
    el.addEventListener('input',  captureAndSync);
    el.addEventListener('change', captureAndSync);
  });
  document.getElementById('quiz-list').appendChild(block);
}

function captureExtras() {
  if (!_fd) return;
  // Quiz
  var questions = [];
  document.querySelectorAll('#quiz-list .qz-block').forEach(function (block) {
    var store = block._i18n || { q: '', choices: [], explain: '' };
    var qText = i18nSet(store.q, block.querySelector('.qz-q').value.trim());
    var rows  = block.querySelectorAll('.qz-choice-row');
    var choices = [], correct = [], kept = 0;
    rows.forEach(function (row, i) {
      var val = i18nSet(store.choices[i], row.querySelector('.qz-choice').value.trim());
      store.choices[i] = val;               // by row, so the rows stay aligned
      if (!i18nAny(val)) return;            // exists in no language at all
      if (row.querySelector('.qz-correct').checked) correct.push(kept);
      choices.push(val);
      kept++;
    });
    var expl = i18nSet(store.explain, block.querySelector('.qz-explain').value.trim());
    store.q = qText; store.explain = expl;
    block._i18n = store;

    if (!i18nAny(qText) || choices.length < 2) return;
    if (!correct.length) correct = [0];   // a question always has one right answer
    var entry = { q: qText, choices: choices, correct: (correct.length === 1 ? correct[0] : correct) };
    if (i18nAny(expl)) entry.explain = expl;
    questions.push(entry);
  });
  if (questions.length) {
    var pass = parseInt(getVal('quiz-pass'), 10);
    _fd.quiz = { pass_score: (!isNaN(pass) && pass >= 1 && pass <= 100) ? pass : 70, questions: questions };
    if (document.getElementById('quiz-shuffle').checked) _fd.quiz.shuffle = true;
  } else {
    delete _fd.quiz;
  }
  // Challenge
  var conds = [];
  document.querySelectorAll('#defi-conds .defi-cond-cb:checked').forEach(function (cb) { conds.push(cb.value); });
  var intro = i18nSet(_fd.challenge ? _fd.challenge.intro : '',
                      document.getElementById('defi-intro').value.trim());
  if (conds.length) {
    _fd.challenge = { intro: intro, conditions: conds };
  } else {
    delete _fd.challenge;
  }
}

function syncExtras() {
  if (!_fd) return;
  // Quiz
  var list = document.getElementById('quiz-list');
  list.innerHTML = '';
  var qz = _fd.quiz || {};
  setVal('quiz-pass', qz.pass_score ? String(qz.pass_score) : '');
  document.getElementById('quiz-shuffle').checked = !!qz.shuffle;
  (qz.questions || []).forEach(function (q) { addQuizQuestion(q); });
  if (qz.questions && qz.questions.length) document.getElementById('sect-quiz').open = true;
  // Challenge
  var ch = _fd.challenge || {};
  document.getElementById('defi-intro').value = i18nGet(ch.intro);
  var selected = ch.conditions || [];
  document.querySelectorAll('#defi-conds .defi-cond-cb').forEach(function (cb) {
    cb.checked = selected.indexOf(cb.value) !== -1;
  });
  if (selected.length) document.getElementById('sect-defi').open = true;
}

function captureFormation() {
  if (!_fd) return;

  // The id is never editable here: it is the key everything else refers to —
  // saved progress, contextual help, the update comparison.
  var base = document.getElementById('f-baselang');
  if (base && base.value) _fd.lang = base.value;

  _fd.title       = i18nSet(_fd.title,       getVal('f-title'));
  _fd.description = i18nSet(_fd.description, getVal('f-description'));
  _fd.version     = getVal('f-version') || '1.0';

  var grp = i18nSet(_fd.group,    getVal('f-group').trim());
  var sgr = i18nSet(_fd.subgroup, getVal('f-subgroup').trim());
  var ord = parseInt(getVal('f-order'), 10);

  if (grp) _fd.group = grp; else delete _fd.group;
  if (sgr) _fd.subgroup = sgr; else delete _fd.subgroup;
  if (!isNaN(ord)) _fd.order = ord; else delete _fd.order;
}

function captureStep() {
  if (!_fd || !_fd.steps || !_fd.steps.length) return;
  var s     = _fd.steps[_sidx];
  s.title   = i18nSet(s.title,   document.getElementById('pv-stitle').textContent.trim());
  s.content = i18nSet(s.content, cleanHtml(document.getElementById('pv-content').innerHTML));
  s.page         = getVal('opt-page') || null;
  s.optional     = document.getElementById('opt-optional').checked;
  s.strict_click = document.getElementById('opt-strict-click').checked;

  // Collect the triggers from the rows of the list
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
      var hint = i18nSet(row._hintI18n, row.querySelector('.tr-hint').value.trim());
      tr = { kind: 'action', trigger: type === 'null' ? null : type, selector: sel || null, required: req };
      if (page) tr.page = page;
      if (hint) tr.hint = hint;
      row._hintI18n = hint;
    }
    // Activation condition, shared by action and state triggers: this is what
    // gives a course an if/else without nesting its steps.
    var gate = row.querySelector('.tr-gate-cond').value;
    if (gate) {
      var sep = gate.indexOf(':');
      var mode = gate.slice(0, sep), cid = gate.slice(sep + 1);
      if (mode === 'met') tr.when = cid;
      else if (mode === 'not') tr.when_not = cid;
    }
    s.triggers.push(tr);
  });

  // Drop the old single-trigger fields, from the format that predates the list
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
  // i18nGet(): the title of a translated course is a language map, and the
  // preview must show the language being edited rather than the whole object.
  setText('pv-fname',   i18nGet(_fd.title) || GE_T.untitled);
  document.getElementById('pv-fill').style.width = pct + '%';
  setText('pv-prog-txt', GE_T.step + ' ' + (_sidx + 1) + ' / ' + total);
  document.getElementById('pv-btn-prev').disabled = (_sidx === 0);
  setText('pv-btn-next', _sidx === total - 1 ? (GE_T.finish + ' ✓') : (GE_T.next + ' ▶'));
}

/* ===== JSON → DOM ===== */

function syncToDOM() {
  if (!_fd) return;
  _syncing = true;
  try {
    renderLangSelect();
    setVal('f-title',       i18nGet(_fd.title));
    setVal('f-description', i18nGet(_fd.description));
    setVal('f-version',     _fd.version     || '1.0');
    setVal('f-group',       i18nGet(_fd.group));
    setVal('f-subgroup',    i18nGet(_fd.subgroup));
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
  document.getElementById('pv-stitle').textContent = i18nGet(s.title);
  document.getElementById('pv-content').innerHTML  = i18nGet(s.content);
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

/* ===== Adding and deleting steps ===== */

function addStep(direction) {
  captureStep();
  if (!_fd.steps) _fd.steps = [];
  var newStep = {
    id:       'step-' + Date.now().toString().slice(-6),
    title:    GE_T.newStep,
    content:  GE_T.newStepBody,
    page: null, triggers: []
  };
  var insertAt = direction < 0 ? _sidx : _sidx + 1;
  _fd.steps.splice(insertAt, 0, newStep);
  _sidx = insertAt;
  _syncing = true; try { loadStepToDOM(); } finally { _syncing = false; }
  // Focus the title so the author can type straight away
  var t = document.getElementById('pv-stitle');
  t.focus();
  document.execCommand('selectAll');
}

function deleteStep() {
  if (!_fd.steps || _fd.steps.length <= 1) {
    alert(GE_T.errOneStep);
    return;
  }
  var stepName = i18nGet(_fd.steps[_sidx].title) || GE_T.stepFallback.replace('{$a}', _sidx + 1);
  if (!confirm(GE_T.delStepConfirm.replace('{$a}', stepName))) return;
  _fd.steps.splice(_sidx, 1);
  if (_sidx >= _fd.steps.length) _sidx = _fd.steps.length - 1;
  _syncing = true; try { loadStepToDOM(); } finally { _syncing = false; }
}

/* ===== Triggers ===== */

var _dragSrc = null;

function buildConditionOptions() {
  var opts = '<option value="">' + esc(GE_T.chooseCond) + '</option>';
  opts += '<option value="__page">📍 ' + esc(GE_T.condPage) + '</option>';
  opts += '<option value="__css">🔎 ' + esc(GE_T.condCss) + '</option>';
  GUIDE_CONDITIONS.forEach(function (c) {
    opts += '<option value="' + esc(c.id) + '">' + esc(c.label) + '</option>';
  });
  return opts;
}

/* Activation condition (a branch): the trigger counts only if it is true, or
   false for the "if NOT" form. */
function buildGateOptions() {
  var opts = '<option value="">⎇ ' + esc(GE_T.gateAlways) + '</option>';
  GUIDE_CONDITIONS.forEach(function (c) {
    opts += '<option value="met:' + esc(c.id) + '">' + esc(GE_T.gateIf + ' ' + c.label) + '</option>';
    opts += '<option value="not:' + esc(c.id) + '">' + esc(GE_T.gateIfNot + ' ' + c.label) + '</option>';
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

/* Show the fields that belong to the built-in condition chosen: the page to
   be open, or the element to look for. */
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
      '<span class="tr-drag" title="' + esc(GE_T.dragHint) + '">⠿</span>' +
      '<select class="tr-kind" title="' + esc(GE_T.triggerKind) + '">' +
        '<option value="action">⚡ ' + esc(GE_T.kindAction) + '</option>' +
        '<option value="etat">✓ ' + esc(GE_T.kindState) + '</option>' +
      '</select>' +
      '<input type="text" class="tr-hint" placeholder="💬 ' + esc(GE_T.hintPh) + '">' +
      '<label class="tr-req"><input type="checkbox"> ' + esc(GE_T.requiredShort) + '</label>' +
      '<button type="button" class="ge-btn ge-btn-del tr-del" title="' + esc(GE_T.delete) + '" onclick="removeTrigger(this)">✕</button>' +
    '</div>' +
    '<div class="tr-body tr-body-action">' +
      '<input type="text" class="tr-page" placeholder="' + esc(GE_T.pagePh) + '" title="' + esc(GE_T.pageHint) + '">' +
      '<select class="tr-type">' +
        '<option value="null">' + esc(GE_T.triggerNone) + '</option>' +
        '<option value="click">' + esc(GE_T.triggerClick) + '</option>' +
        '<option value="dblclick">' + esc(GE_T.triggerDbl) + '</option>' +
        '<option value="change">' + esc(GE_T.triggerChange) + '</option>' +
        '<option value="input">' + esc(GE_T.triggerInput) + '</option>' +
        '<option value="keyup">' + esc(GE_T.triggerKeyup) + '</option>' +
        '<option value="keydown">' + esc(GE_T.triggerKeydown) + '</option>' +
        '<option value="focus">' + esc(GE_T.triggerFocus) + '</option>' +
        '<option value="submit">' + esc(GE_T.triggerSubmit) + '</option>' +
        '<option value="mouseover">' + esc(GE_T.triggerHover) + '</option>' +
      '</select>' +
      '<input type="text" class="tr-sel" placeholder="' + esc(GE_T.selectorPh) + '" ' +
        'title="' + esc(GE_T.selectorHint) + '">' +
    '</div>' +
    '<div class="tr-body tr-body-etat" style="display:none">' +
      '<select class="tr-cond">' + buildConditionOptions() + '</select>' +
      '<input type="text" class="tr-cond-page" placeholder="' + esc(GE_T.condPagePh) + '" style="display:none" ' +
             'title="' + esc(GE_T.condPageHint) + '">' +
      '<input type="text" class="tr-cond-css" placeholder="' + esc(GE_T.condCssPh) + '" style="display:none" ' +
             'title="' + esc(GE_T.condCssHint) + '">' +
      '<select class="tr-cond-css-mode" style="display:none" title="' + esc(GE_T.cssModeHint) + '">' +
        '<option value="present">' + esc(GE_T.cssPresent) + '</option>' +
        '<option value="absent">' + esc(GE_T.cssAbsent) + '</option>' +
      '</select>' +
    '</div>' +
    '<div class="tr-gate" title="' + esc(GE_T.gateHint) + '">' +
      '<span class="tr-gate-label">⎇</span>' +
      '<select class="tr-gate-cond">' + buildGateOptions() + '</select>' +
    '</div>';

  // Initial values
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
  row.querySelector('.tr-hint').value        = i18nGet(t.hint);

  // The row is the only place the other languages of this hint survive: the
  // trigger list is rebuilt from the DOM on every capture, so the original
  // object is thrown away. Stored on the element itself, so it follows the row
  // when the author drags it to a new position.
  row._hintI18n = t.hint;

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
      p.textContent = GE_T.noTrigger;
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

/* ===== Formatting toolbar ===== */

function fmt(cmd, val) {
  document.getElementById('pv-content').focus();
  document.execCommand(cmd, false, val || null);
  setTimeout(captureAndSync, 0);
}

function insertTip() {
  document.getElementById('pv-content').focus();
  document.execCommand('insertHTML', false,
    '<p class="guide-tip">⚠️ ' + esc(GE_T.tipBody) + '</p><p></p>');
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

/* ===== Images =====
   Stored as base64 inside the content file, so a course travels as one
   document — through the GitHub sync and through import/export alike. */

function readImageFile(input, cb) {
  var file = input.files && input.files[0];
  input.value = '';
  if (!file) return;
  if (!/^image\//.test(file.type)) { alert(GE_T.errNotImage); return; }
  if (file.size > 2 * 1024 * 1024) {
    var mo = (file.size / 1024 / 1024).toFixed(1);
    if (!confirm(GE_T.imageBig.replace('{$a}', mo))) return;
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

/* ===== Recording triggers ===== */

function startRecording() {
  captureAndSync();
  if (!_fd || !_fd.id) { alert(GE_T.errNoId); return; }
  var step = _fd.steps[_sidx];
  if (!step) return;

  // Saved before leaving: the recording happens in ianseo itself, on another
  // page, so anything unsaved here would be lost on the way.
  if (!confirm(GE_T.recConfirm)) return;

  var json = document.getElementById('guide-json-editor').value;
  var fd = new FormData();
  fd.append('json_raw', json);
  fd.append('is_ajax', '1');
  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) { alert(GE_T.saveFailed.replace('{$a}', data.error || GE_T.unknown)); return; }
      var rec = {
        active: true, paused: false,
        formation_id: _fd.id, step_id: step.id,
        // Force the id into the return URL: a brand new course with no ?id would
  // come back as an empty one.
        return_url: window.location.pathname + '?id=' + encodeURIComponent(_fd.id),
        triggers: []
      };
      localStorage.setItem('guide_rec', JSON.stringify(rec));
      var root = (typeof WebDir !== 'undefined') ? WebDir : '/';
      var page = (step.page && step.page !== '*') ? step.page : '';
      window.location.href = page ? (root.replace(/\/$/, '') + page) : root;
    })
    .catch(function () { alert(GE_T.networkSave); });
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
  alert(GE_T.triggersAdded
    .replace('{$a[n]}', res.triggers.length)
    .replace('{$a[step]}', i18nGet(step.title) || GE_T.stepFallback.replace('{$a}', idx + 1)));
}

/* ===== Export and import =====
   .ianseo is the JSON compressed with zlib on the server side, the same format
   ianseo uses for its own exports. */

function exportIanseo() {
  captureAndSync();
  var json = document.getElementById('guide-json-editor').value;
  try { JSON.parse(json); } catch (e) { alert(GE_T.errJson); return; }
  // Submitted through a hidden form: the binary response is what makes the
  // browser download the .ianseo file instead of rendering it.
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
        showSaveStatus('ok', '✓ ' + GE_T.imported);
      } else {
        alert(GE_T.importPrefix.replace('{$a}', data.error || GE_T.unknownError));
      }
    })
    .catch(function () { alert(GE_T.networkImport); });
  e.target.value = '';
}

/* ===== Save ===== */

function prepareSave() {
  captureAndSync();
  var btn  = document.getElementById('btn-save');
  var json = document.getElementById('guide-json-editor').value;

  btn.disabled    = true;
  btn.textContent = '⏳ ' + GE_T.saving;

  var fd = new FormData();
  fd.append('json_raw', json);
  fd.append('is_ajax',  '1');

  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok) {
        showSaveStatus('ok', '✓ ' + GE_T.saved);
      } else {
        showSaveStatus('err', '✗ ' + (data.error || GE_T.unknownError));
      }
    })
    .catch(function () {
      showSaveStatus('err', '✗ ' + GE_T.networkErr);
    })
    .finally(function () {
      btn.disabled    = false;
      btn.textContent = '💾 ' + GE_T.save;
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

/* ===== Raw JSON ===== */

function toggleJson() {
  var body = document.getElementById('ge-json-body');
  var show = body.style.display !== 'block';
  body.style.display = show ? 'block' : 'none';
  document.getElementById('json-icon').textContent = show ? '▼' : '▶';
}

/* ===== Utilities ===== */

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
  // DOM cleanup: strip the inline style="" from ul/ol/li
  // Chrome adds inline styles through execCommand, and they would override
  // the panel's own CSS.
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
