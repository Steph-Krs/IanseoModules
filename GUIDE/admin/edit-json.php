<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

guide_check_admin();

$contentDir = dirname(__DIR__) . '/content/';
$editId     = isset($_GET['id']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['id'])) : '';
$newType    = $_GET['new'] ?? '';

/* ---- Templates ---- */
function guide_json_template($type) {
    if ($type === 'checklist') {
        return [
            'id'          => 'checklist-' . substr(md5(uniqid()), 0, 8),
            'type'        => 'checklist',
            'title'       => 'Ma checklist',
            'description' => 'Préparation de la compétition',
            'version'     => '1.0',
            'group'       => '',
            'order'       => 9999,
            'questions'   => [
                [
                    'q'       => 'Votre compétition comporte-t-elle des duels ?',
                    'choices' => [
                        ['label' => 'Oui', 'tags' => ['duels']],
                        ['label' => 'Non', 'tags' => []],
                    ],
                ],
            ],
            'items' => [
                ['label' => 'Mettre à jour ianseo', 'page' => null, 'tags' => [], 'condition' => null],
                ['label' => 'Synchroniser la base licenciés', 'page' => '/Partecipants/LookupTableLoad.php', 'tags' => [], 'condition' => null],
                ['label' => 'Configurer les duels', 'page' => null, 'tags' => ['duels'], 'condition' => 'has_individual_duel'],
            ],
        ];
    }
    if ($type === 'faq') {
        return [
            'id'          => 'faq-' . substr(md5(uniqid()), 0, 8),
            'type'        => 'faq',
            'title'       => 'Dépannage',
            'description' => 'Résolution des problèmes courants',
            'version'     => '1.0',
            'group'       => '',
            'order'       => 9999,
            'nodes'       => [
                'start' => [
                    'q'       => 'Quel est votre problème ?',
                    'answers' => [
                        ['label' => 'Un archer n\'apparaît pas', 'next' => 'sol-archer'],
                        ['label' => 'Autre problème', 'next' => 'sol-autre'],
                    ],
                ],
                'sol-archer' => [
                    'solution'  => '<p>Vérifiez que l\'archer est bien <b>inscrit</b> et affecté à une session.</p>',
                    'page'      => '/Partecipants/index.php',
                    'formation' => null,
                ],
                'sol-autre' => [
                    'solution' => '<p>Consultez le manuel ianseo ou contactez le support FFTA.</p>',
                ],
            ],
        ];
    }
    return null;
}

/* ---- Sauvegarde ---- */
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['json_raw'])) {
    $data = json_decode($_POST['json_raw'], true);
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
                $error = 'Erreur lors de l\'écriture du fichier (droits ?).';
            } else {
                header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/admin/?saved=1');
                exit;
            }
        }
    }
}

/* ---- Chargement ---- */
$data = null;
if (!empty($_POST['json_raw'])) {
    $raw = $_POST['json_raw']; // repost après erreur
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

$PAGE_TITLE = 'Guide FFTA — Éditeur JSON';
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

<h1>Éditeur JSON — checklists &amp; FAQ</h1>
<p><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/">← Retour à l'administration</a></p>

<div class="gj-wrap">

<?php if ($error): ?><div class="gj-err">✗ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="gj-doc">
  <b>Checklist</b> : <code>questions[]</code> (chaque choix porte des <code>tags</code>) puis <code>items[]</code> —
  un item sans tag est toujours affiché, un item avec tags n'apparaît que si un choix correspondant a été sélectionné.
  <code>condition</code> (optionnelle) auto-coche l'item quand elle est remplie ; <code>page</code> ajoute un lien.<br>
  <b>FAQ</b> : <code>nodes</code> — l'arbre démarre au nœud <code>start</code>. Un nœud a soit
  <code>q</code> + <code>answers[]</code> (avec <code>next</code>), soit <code>solution</code> (HTML) avec
  <code>page</code> et/ou <code>formation</code> optionnels.<br>
  Champs communs de parcours : <code>group</code>, <code>subgroup</code>, <code>order</code>.
</div>

<form method="post">
  <textarea id="gj-ta" name="json_raw" spellcheck="false"><?= htmlspecialchars($raw) ?></textarea>
  <div style="margin-top:10px">
    <button type="button" class="gj-btn gj-btn-check" onclick="gjValidate()">✓ Valider le JSON</button>
    <button type="submit" class="gj-btn gj-btn-save">💾 Enregistrer</button>
    <span id="gj-status"></span>
  </div>
</form>

</div>

<script>
function gjValidate() {
  var st = document.getElementById('gj-status');
  try {
    var d = JSON.parse(document.getElementById('gj-ta').value);
    if (!d.id) throw new Error('champ "id" manquant');
    if (!d.type || ['checklist', 'faq'].indexOf(d.type) === -1) throw new Error('champ "type" doit être "checklist" ou "faq"');
    if (d.type === 'checklist' && !Array.isArray(d.items)) throw new Error('champ "items" manquant');
    if (d.type === 'faq' && (!d.nodes || !d.nodes.start)) throw new Error('nœud "start" manquant dans "nodes"');
    st.textContent = '✅ JSON valide';
    st.style.color = '#1a7a3a';
  } catch (e) {
    st.textContent = '❌ ' + e.message;
    st.style.color = '#b00020';
  }
}
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
