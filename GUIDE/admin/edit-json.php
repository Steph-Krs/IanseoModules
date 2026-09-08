<?php
/**
 * JSON editor for the content types that have no visual editor: checklists and
 * troubleshooting trees.
 *
 * Courses get the visual editor (edit.php) because a course is a long sequence
 * of steps, each pointing at a page element. A checklist is a flat list of items
 * with tags, and a troubleshooting tree is a handful of linked nodes; both are
 * quicker to write directly, and both change shape rarely enough that a
 * dedicated editor would cost more than it saves.
 *
 * Creating one starts from a template (?new=checklist or ?new=faq) so the author
 * has the structure in front of them rather than a blank page.
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

$contentDir = dirname(__DIR__) . '/content/';
$editId     = isset($_GET['id']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['id'])) : '';
$newType    = $_GET['new'] ?? '';
$adminUrl   = (function_exists('cmod_url') ? cmod_url(dirname(__DIR__)) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/') . 'admin/';

/* ---- Starting templates ----
 * Sample content, deliberately left in English: it is a scaffold the author
 * replaces immediately, and it shows the shape of every field — questions with
 * tags, an item driven by a condition, a troubleshooting node that leads to a
 * solution. Translating a placeholder nobody keeps would only add noise. */
function guide_json_template($type) {
    if ($type === 'checklist') {
        return [
            'id'          => 'checklist-' . substr(md5(uniqid()), 0, 8),   // bytes: md5 is hex
            'type'        => 'checklist',
            'lang'        => 'en',
            'title'       => 'My checklist',
            'description' => 'Preparing the competition',
            'version'     => '1.0',
            'group'       => '',
            'order'       => 9999,
            'questions'   => [
                [
                    'q'       => 'Does your competition include head-to-head matches?',
                    'choices' => [
                        ['label' => 'Yes', 'tags' => ['duels']],
                        ['label' => 'No',  'tags' => []],
                    ],
                ],
            ],
            'items' => [
                ['label' => 'Update ianseo', 'page' => null, 'tags' => [], 'condition' => null],
                ['label' => 'Synchronise the licence database', 'page' => '/Partecipants/LookupTableLoad.php', 'tags' => [], 'condition' => null],
                ['label' => 'Configure the matches', 'page' => null, 'tags' => ['duels'], 'condition' => 'has_individual_duel'],
            ],
        ];
    }
    if ($type === 'faq') {
        return [
            'id'          => 'faq-' . substr(md5(uniqid()), 0, 8),   // bytes: md5 is hex
            'type'        => 'faq',
            'lang'        => 'en',
            'title'       => 'Troubleshooting',
            'description' => 'Solving the common problems',
            'version'     => '1.0',
            'group'       => '',
            'order'       => 9999,
            'nodes'       => [
                'start' => [
                    'q'       => 'What is the problem?',
                    'answers' => [
                        ['label' => 'An archer does not appear', 'next' => 'sol-archer'],
                        ['label' => 'Something else',            'next' => 'sol-other'],
                    ],
                ],
                'sol-archer' => [
                    'solution'  => '<p>Check that the archer is <b>registered</b> and assigned to a session.</p>',
                    'page'      => '/Partecipants/index.php',
                    'formation' => null,
                ],
                'sol-other' => [
                    'solution' => '<p>See the ianseo manual.</p>',
                ],
            ],
        ];
    }
    return null;
}

/* ---- Save ---- */
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['json_raw'])) {
    $data = json_decode($_POST['json_raw'], true);
    if (!$data || empty($data['id'])) {
        $error = guide_text('JedErrInvalidJson');
    } else {
        // The id becomes part of a filename, so it is compared against its own
        // sanitised form rather than silently corrected: an author who typed
        // something unusable is told, instead of finding their content saved
        // under a name they did not choose.
        $cleanId = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['id']));
        if ($cleanId !== $data['id']) {
            $error = guide_text('JedErrBadId');
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
                $error = guide_text('JedErrWrite');
            } else {
                header('Location: ' . $adminUrl . '?saved=1');
                exit;
            }
        }
    }
}

/* ---- Load what the editor should show ---- */
$data = null;
if (!empty($_POST['json_raw'])) {
    // Reposted after a validation error: show what the author typed, not what
    // is on disk, so their work is not thrown away by a missing comma.
    $raw = $_POST['json_raw'];
} elseif ($editId) {
    foreach (glob($contentDir . '*.json') as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (isset($d['id']) && $d['id'] === $editId) { $data = $d; break; }
    }
    $raw = $data ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
} elseif ($newType && ($tpl = guide_json_template($newType))) {
    $raw = json_encode($tpl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    $raw = '';
}

$PAGE_TITLE = guide_text('JedTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.gj-wrap { max-width: 900px; }
.gj-err { background: #fde; border-left: 3px solid #c00; padding: 8px 12px; margin-bottom: 12px; color: #900; border-radius: 4px; }
#gj-ta { width: 100%; height: 480px; font-family: monospace; font-size: 12.5px; border: 1px solid #c8d4ec; border-radius: 8px; padding: 12px; box-sizing: border-box; }
.gj-btn { padding: 9px 24px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.gj-btn-save { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; }
.gj-btn-check { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; }
.gj-doc { background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 8px; padding: 12px 16px; font-size: 12px; color: #555; margin-bottom: 14px; line-height: 1.6; }
.gj-doc code { background: #eef2ff; border: 1px solid #c5cef5; border-radius: 3px; padding: 0 4px; font-size: 11px; }
#gj-status { font-size: 13px; margin-left: 10px; }
</style>

<h1><?= htmlspecialchars(guide_text('JedHeading')) ?></h1>
<p><a href="<?= htmlspecialchars($adminUrl) ?>">← <?= htmlspecialchars(guide_text('AdmBackAdmin')) ?></a></p>

<div class="gj-wrap">

<?php if ($error): ?><div class="gj-err">✗ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php /* The three lines below carry deliberate <code> markup, so they are not escaped. */ ?>
<div class="gj-doc">
  <?= guide_text('JedDocChecklist') ?><br>
  <?= guide_text('JedDocFaq') ?><br>
  <?= guide_text('JedDocCommon') ?>
</div>

<form method="post">
  <textarea id="gj-ta" name="json_raw" spellcheck="false"><?= htmlspecialchars($raw) ?></textarea>
  <div style="margin-top:10px">
    <button type="button" class="gj-btn gj-btn-check" onclick="gjValidate()">✓ <?= htmlspecialchars(guide_text('JedValidate')) ?></button>
    <button type="submit" class="gj-btn gj-btn-save">💾 <?= htmlspecialchars(guide_text('JedSave')) ?></button>
    <span id="gj-status"></span>
  </div>
</form>

</div>

<script>
/* Client-side validation, so a mistake is caught before the round trip. The
   server checks the same things again: this is a convenience, not a guard. */
var GJ_T = <?= json_encode([
    'valid'   => guide_text('JedValid'),
    'noId'    => guide_text('JedErrNoId'),
    'badType' => guide_text('JedErrBadType'),
    'noItems' => guide_text('JedErrNoItems'),
    'noStart' => guide_text('JedErrNoStart'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function gjValidate() {
  var st = document.getElementById('gj-status');
  try {
    var d = JSON.parse(document.getElementById('gj-ta').value);
    if (!d.id) throw new Error(GJ_T.noId);
    if (!d.type || ['checklist', 'faq'].indexOf(d.type) === -1) throw new Error(GJ_T.badType);
    if (d.type === 'checklist' && !Array.isArray(d.items)) throw new Error(GJ_T.noItems);
    if (d.type === 'faq' && (!d.nodes || !d.nodes.start)) throw new Error(GJ_T.noStart);
    st.textContent = '✅ ' + GJ_T.valid;
    st.style.color = '#1a7a3a';
  } catch (e) {
    st.textContent = '❌ ' + e.message;
    st.style.color = '#b00020';
  }
}
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
