<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');

checkFullACL(AclRoot, '', AclReadWrite);

$contentDir  = dirname(__DIR__) . '/content/';
$editId      = isset($_GET['id']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['id'])) : '';
$condFile    = dirname(__DIR__) . '/conditions.json';
$conditions  = file_exists($condFile) ? (json_decode(file_get_contents($condFile), true) ?: []) : [];

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
  grid-template-columns: 1fr 1fr 80px;
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

/* Barre nav panneau (décorative) */
.ge-panel-nav {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 12px; border-top: 1px solid #eef0f8; background: #f7f9ff; gap: 6px;
}
.ge-panel-nav-btn {
  padding: 7px 12px; border-radius: 8px; border: 1px solid #d0d8f0;
  font-size: 12px; font-family: inherit; background: #fff; color: #444; cursor: default;
}
#pv-btn-next {
  background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%) !important;
  color: #fff !important; border: none !important; flex: 1; text-align: center;
}

/* ===== Colonne droite (navigation + options) ===== */
.ge-step-nav  { display: flex; align-items: center; gap: 6px; margin-bottom: 12px; }
.ge-step-ctr  { flex: 1; text-align: center; font-size: 13px; font-weight: 700; color: #082c7c; }
.ge-step-acts { display: flex; gap: 8px; margin-bottom: 18px; }

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
.tr-row { display: block; padding: 7px 8px; margin-bottom: 5px; background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 7px; }
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
.tr-cond { padding: 4px 7px; border: 1px solid #c8d4ec; border-radius: 4px; font-size: 12px; flex: 1; min-width: 0; background: #f8f4ff; color: #3a2660; }
.tr-req  { display: flex; align-items: center; gap: 4px; font-size: 11px; color: #555; white-space: nowrap; cursor: pointer; flex-shrink: 0; }
.tr-req input { margin: 0; }
.tr-del  { padding: 3px 7px !important; font-size: 11px !important; flex-shrink: 0; }
.tr-hint { flex: 1; min-width: 0; padding: 4px 7px; border: 1px dashed #c8d4ec; border-radius: 4px; font-size: 11px; box-sizing: border-box; color: #555; background: #fafbff; }

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
.ge-btn-nav   { background: #fff; border: 1px solid #c8d4ec; color: #444; font-size: 12px; padding: 5px 10px; }
.ge-btn-nav:hover { background: #eef2ff; border-color: #0254a8; }
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
  <button class="ge-btn ge-btn-ghost" onclick="exportJson()">⬇ Exporter JSON</button>
  <label class="ge-btn ge-btn-ghost" style="cursor:pointer">
    ⬆ Importer JSON
    <input type="file" id="import-file" accept=".json,application/json" style="display:none">
  </label>
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
</div>
<p style="font-size:11px;color:#aaa;margin:-12px 0 18px">
  ID : <code style="background:#f0f4ff;padding:1px 6px;border-radius:3px;color:#555"><?= htmlspecialchars($formation['id']) ?></code>
  — identifiant unique auto-généré, modifiable via le JSON si nécessaire
</p>

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
        <span class="ge-panel-header-title">Guide FFTA</span>
        <span class="ge-panel-header-hint">éditeur</span>
      </div>
      <div class="ge-panel-fname" id="pv-fname"></div>
      <div class="ge-panel-prog">
        <div class="ge-panel-prog-bar">
          <div class="ge-panel-prog-fill" id="pv-fill" style="width:0%"></div>
        </div>
        <span class="ge-panel-prog-txt" id="pv-prog-txt">Étape 1 / 1</span>
      </div>

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

      <!-- Boutons nav (décoratifs) -->
      <div class="ge-panel-nav">
        <button class="ge-panel-nav-btn">◀ Préc.</button>
        <button class="ge-panel-nav-btn" style="color:#b00;border-color:#f5c0c0;font-size:11px">✕</button>
        <button class="ge-panel-nav-btn" id="pv-btn-next">Suivant ▶</button>
      </div>
    </div>

  </div><!-- /ge-left -->

  <!-- Colonne droite : navigation + options -->
  <div class="ge-right">

    <!-- Navigation étapes -->
    <div class="ge-step-nav">
      <button class="ge-btn ge-btn-nav" onclick="navStep(-1)">◀</button>
      <span class="ge-step-ctr" id="step-counter">1 / 1</span>
      <button class="ge-btn ge-btn-nav" onclick="navStep(1)">▶</button>
    </div>
    <div class="ge-step-acts">
      <button class="ge-btn ge-btn-add" onclick="addStep(-1)" title="Insérer une étape avant celle-ci">+ Avant</button>
      <button class="ge-btn ge-btn-add" onclick="addStep(1)"  title="Insérer une étape après celle-ci">+ Après</button>
      <button class="ge-btn ge-btn-del" onclick="deleteStep()" title="Supprimer cette étape">🗑</button>
    </div>

    <!-- Options de l'étape -->
    <div class="ge-opts">
      <div class="ge-opts-title">Options de l'étape</div>

      <div class="ge-opt">
        <label>Page par défaut <span class="ge-hint">(pour les triggers sans page propre)</span></label>
        <input type="text" id="opt-page" placeholder="/path/to/file.php" oninput="captureAndSync()">
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
      </div>
    </div>

  </div><!-- /ge-right -->
</div><!-- /ge-editor -->

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
  document.getElementById('pv-content').addEventListener('focus', function () {
    document.execCommand('defaultParagraphSeparator', false, 'p');
  });
  document.getElementById('import-file').addEventListener('change', handleImport);

  _fd = <?= json_encode($formation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  clampStep();
  syncToDOM();
});

/* ===== DOM → JSON ===== */

function captureAndSync() {
  if (_syncing) return;
  captureFormation();
  captureStep();
  renderJson();
}

function captureFormation() {
  if (!_fd) return;
  // ID : jamais modifié via l'UI, préservé tel quel depuis le JSON
  _fd.title       = getVal('f-title');
  _fd.description = getVal('f-description');
  _fd.version     = getVal('f-version') || '1.0';
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
    } else {
      var page = row.querySelector('.tr-page').value.trim() || null;
      var type = row.querySelector('.tr-type').value;
      var sel  = row.querySelector('.tr-sel').value.trim();
      var hint = row.querySelector('.tr-hint').value.trim() || null;
      tr = { kind: 'action', trigger: type === 'null' ? null : type, selector: sel || null, required: req };
      if (page) tr.page = page;
      if (hint) tr.hint = hint;
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
  setText('step-counter', (_sidx + 1) + ' / ' + total);
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
  GUIDE_CONDITIONS.forEach(function (c) {
    opts += '<option value="' + c.id + '">' + c.label + '</option>';
  });
  return opts;
}

function setTriggerKind(row, kind) {
  var isAction = kind !== 'etat';
  row.querySelector('.tr-body-action').style.display = isAction ? 'flex' : 'none';
  row.querySelector('.tr-body-etat').style.display   = isAction ? 'none' : 'flex';
  row.querySelector('.tr-hint').style.display        = isAction ? '' : 'none';
  row.querySelector('.tr-req').style.marginLeft      = isAction ? '' : 'auto';
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
      '<input type="text" class="tr-page" placeholder="/page" title="Page (vide = page de l\'étape)">' +
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
      '<input type="text" class="tr-sel" placeholder="#sélecteur-css">' +
    '</div>' +
    '<div class="tr-body tr-body-etat" style="display:none">' +
      '<select class="tr-cond">' + buildConditionOptions() + '</select>' +
    '</div>';

  // Valeurs initiales
  row.querySelector('.tr-kind').value        = kind;
  row.querySelector('.tr-page').value        = t.page      || '';
  row.querySelector('.tr-type').value        = t.trigger   || 'null';
  row.querySelector('.tr-sel').value         = t.selector  || '';
  row.querySelector('.tr-cond').value        = t.condition || '';
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
  row.querySelector('.tr-cond').addEventListener('change', captureAndSync);
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

/* ===== Export / Import ===== */

function exportJson() {
  captureAndSync();
  var raw = document.getElementById('guide-json-editor').value;
  var data; try { data = JSON.parse(raw); } catch(e) { alert('JSON invalide.'); return; }
  var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  var url  = URL.createObjectURL(blob);
  var a    = document.createElement('a');
  a.href = url; a.download = (data.id || 'formation') + '.json'; a.click();
  setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
}

function handleImport(e) {
  var file = e.target.files[0]; if (!file) return;
  var reader = new FileReader();
  reader.onload = function (ev) {
    try {
      var data = JSON.parse(ev.target.result);
      if (!data.id || !Array.isArray(data.steps)) { alert('Structure invalide (champs id et steps requis).'); return; }
      _fd = data; _sidx = 0; syncToDOM();
    } catch(ex) { alert('JSON invalide : ' + ex.message); }
  };
  reader.readAsText(file);
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
