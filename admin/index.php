<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');

checkFullACL(AclRoot, '', AclReadWrite);

/* Actions */
$action = $_POST['action'] ?? '';

if ($action === 'delete' && !empty($_POST['id'])) {
    $id = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['id']));
    $dir = dirname(__DIR__) . '/content/';
    foreach (glob($dir . '*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (isset($data['id']) && $data['id'] === $id) {
            unlink($file);
            break;
        }
    }
    header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/admin/');
    exit;
}

/* Charger les formations */
$formations = [];
$dir = dirname(__DIR__) . '/content/';
foreach (glob($dir . '*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['id'])) continue;
    $formations[] = [
        'id'          => $data['id'],
        'title'       => $data['title'] ?? '(sans titre)',
        'description' => $data['description'] ?? '',
        'steps_count' => count($data['steps'] ?? []),
        'version'     => $data['version'] ?? '',
        'file'        => basename($file),
    ];
}
usort($formations, function ($a, $b) { return strcmp($a['title'], $b['title']); });

$PAGE_TITLE = 'Guide FFTA — Administration';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.gadm-table { border-collapse: collapse; width: 100%; max-width: 900px; }
.gadm-table th { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; padding: 8px 12px; text-align: left; }
.gadm-table td { padding: 8px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
.gadm-table tr:hover td { background: #f7f9ff; }
.gadm-btn     { padding: 4px 12px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; }
.gadm-btn-edit { background: #0254a8; color: #fff; }
.gadm-btn-del  { background: #c0392b; color: #fff; }
.gadm-btn-new  { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color: #fff; padding: 8px 20px; font-size: 14px; border-radius: 5px; border: none; cursor: pointer; }
.gadm-btn-upd  { background: #1a8a4a; color: #fff; padding: 8px 20px; font-size: 14px; border-radius: 5px; border: none; cursor: pointer; }
.gadm-meta { color: #888; font-size: 12px; }
</style>

<h1>Guide FFTA — Administration des formations</h1>

<p>
  <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/edit.php">
    <button class="gadm-btn-new">+ Nouvelle formation</button>
  </a>
  &nbsp;
  <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/update.php">
    <button class="gadm-btn-upd">↑ Mises à jour</button>
  </a>
  &nbsp;
  <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/">← Retour au catalogue</a>
</p>

<?php if (empty($formations)): ?>
  <p><i>Aucune formation existante. Créez-en une !</i></p>
<?php else: ?>
<table class="gadm-table">
  <thead>
    <tr>
      <th>Titre</th>
      <th>ID</th>
      <th>Étapes</th>
      <th>Version</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($formations as $f): ?>
    <tr>
      <td>
        <strong><?= htmlspecialchars($f['title']) ?></strong><br>
        <span class="gadm-meta"><?= htmlspecialchars($f['description']) ?></span>
      </td>
      <td class="gadm-meta"><?= htmlspecialchars($f['id']) ?></td>
      <td><?= $f['steps_count'] ?></td>
      <td class="gadm-meta"><?= htmlspecialchars($f['version']) ?></td>
      <td>
        <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/edit.php?id=<?= urlencode($f['id']) ?>">
          <button class="gadm-btn gadm-btn-edit">Éditer</button>
        </a>
        &nbsp;
        <form method="post" style="display:inline"
              onsubmit="return confirm('Supprimer définitivement cette formation ?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= htmlspecialchars($f['id']) ?>">
          <button type="submit" class="gadm-btn gadm-btn-del">Supprimer</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
