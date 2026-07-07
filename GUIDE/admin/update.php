<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');
require_once dirname(dirname(__DIR__)) . '/_shared/update-lib.php';
require_once dirname(__DIR__) . '/lib/guide-lib.inc.php'; // guide_ensure_schema (schéma v3 partagé)

guide_check_admin();

$PAGE_TITLE = 'Guide FFTA — Mises à jour';
$MODULE_DIR = dirname(__DIR__);

/* ---- Helpers formations (GUIDE-spécifique) ---- */

function guide_local_formations() {
    global $MODULE_DIR;
    $result = [];
    foreach (glob($MODULE_DIR . '/content/*.json') as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['id'])) continue;
        $result[$d['id']] = [
            'version' => $d['version'] ?? '1.0',
            'file'    => $f,
            'title'   => $d['title'] ?? $d['id'],
        ];
    }
    return $result;
}

/* ---- Init ---- */

$cfg      = upd_load_config($MODULE_DIR);
$messages = [];
$action   = $_POST['action'] ?? '';

$localVer    = upd_local_version($MODULE_DIR);
$localModVer = $localVer['version'] ?? null;

$checkResults      = null;
$moduleCheckResult = null;

/* ---- Actions POST ---- */

if ($action === 'check') {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) {
        $messages[] = ['err', 'URL GitHub invalide dans module.json'];
    } else {
        $branch  = $cfg['github_branch'] ?: 'main';
        $token   = $cfg['github_token']  ?: '';
        $prefix  = upd_remote_prefix($cfg);
        $apiBase = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}";

        // Formations
        $contentList = upd_gh_fetch("$apiBase/contents/{$prefix}content?ref=$branch", $token);
        if (isset($contentList['_error'])) {
            $messages[] = ['err', 'GitHub : ' . $contentList['_error']];
        } else {
            $local        = guide_local_formations();
            $checkResults = [];
            foreach ($contentList as $item) {
                if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;
                $raw  = upd_gh_raw($item['download_url'], $token);
                $data = $raw ? json_decode($raw, true) : null;
                if (!$data || empty($data['id'])) continue;
                $id     = $data['id'];
                $remVer = $data['version'] ?? '1.0';
                $locVer = $local[$id]['version'] ?? null;
                $status = ($locVer === null) ? 'new'
                        : (version_compare($remVer, $locVer, '>') ? 'update' : 'ok');
                $checkResults[] = [
                    'id'         => $id,
                    'title'      => $data['title'] ?? $id,
                    'local_ver'  => $locVer,
                    'remote_ver' => $remVer,
                    'status'     => $status,
                    'filename'   => $item['name'],
                    'raw_url'    => $item['download_url'],
                ];
            }
        }

        // Version module
        $remoteVer = upd_remote_version($cfg);
        if (isset($remoteVer['_error'])) {
            $messages[] = ['err', 'Module : ' . $remoteVer['_error']];
        } else {
            $moduleCheckResult = [
                'local'  => $localModVer,
                'remote' => $remoteVer['version'],
                'notes'  => $remoteVer['notes'] ?? null,
                'date'   => $remoteVer['date']  ?? null,
                'update' => ($localModVer === null || version_compare($remoteVer['version'], $localModVer, '>')),
            ];
        }
    }
}

if ($action === 'update-formations') {
    $mode = $_POST['mode'] ?? 'merge';
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) {
        $messages[] = ['err', 'URL GitHub invalide.'];
    } else {
        guide_ensure_schema();
        $branch      = $cfg['github_branch'] ?: 'main';
        $token       = $cfg['github_token']  ?: '';
        $prefix      = upd_remote_prefix($cfg);
        $apiBase     = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}";
        $contentList = upd_gh_fetch("$apiBase/contents/{$prefix}content?ref=$branch", $token);
        $local       = guide_local_formations();
        $contentDir  = $MODULE_DIR . '/content/';
        $updated = $added = $skipped = 0;

        if (isset($contentList['_error'])) {
            $messages[] = ['err', 'GitHub : ' . $contentList['_error']];
        } else {
            if ($mode === 'replace') {
                $remoteIds = [];
                foreach ($contentList as $item) {
                    if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;
                    $raw  = upd_gh_raw($item['download_url'], $token);
                    $data = $raw ? json_decode($raw, true) : null;
                    if ($data && !empty($data['id'])) $remoteIds[] = $data['id'];
                }
                foreach ($local as $lid => $ldata) {
                    if (!in_array($lid, $remoteIds)) unlink($ldata['file']);
                }
            }
            foreach ($contentList as $item) {
                if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;
                $raw  = upd_gh_raw($item['download_url'], $token);
                $data = $raw ? json_decode($raw, true) : null;
                if (!$data || empty($data['id']) || !is_string($raw)) continue;
                $id     = $data['id'];
                $remVer = $data['version'] ?? '1.0';
                $locVer = $local[$id]['version'] ?? null;
                if ($locVer !== null && !version_compare($remVer, $locVer, '>') && $mode === 'merge') {
                    $skipped++;
                    continue;
                }
                file_put_contents($contentDir . $item['name'], $raw);
                if ($locVer !== null) {
                    $updated++;
                    if (version_compare($remVer, $locVer, '>')) {
                        $now = date('Y-m-d H:i:s');
                        safe_w_sql("UPDATE GUIDE_Progress
                            SET GpStatus='obsolete', GpUpdatedAt=" . StrSafe_DB($now) . "
                            WHERE GpFormId=" . StrSafe_DB($id) . "
                            AND GpStatus='termine'
                            AND GpFormVer != " . StrSafe_DB($remVer));
                    }
                } else {
                    $added++;
                }
            }
            $messages[] = ['ok', "Formations : $updated modifiée(s), $added ajoutée(s), $skipped déjà à jour."];
        }
    }
}

if ($action === 'update-module') {
    $remoteVer = upd_remote_version($cfg);
    if (isset($remoteVer['_error'])) {
        $messages[] = ['err', 'Impossible de lire version.json distant : ' . $remoteVer['_error']];
    } elseif (empty($remoteVer['files'])) {
        $messages[] = ['err', 'Le version.json distant ne contient pas de liste de fichiers (files[]).'];
    } else {
        $result = upd_sync_files($cfg, $MODULE_DIR, $remoteVer['files']);
        // Recharger la version locale (version.json vient d'être téléchargé)
        $localVer    = upd_local_version($MODULE_DIR);
        $localModVer = $localVer['version'] ?? null;
        if (empty($result['fail'])) {
            $messages[] = ['ok', "Module mis à jour vers {$remoteVer['version']} ({$result['ok']} fichier(s))."];
        } else {
            $messages[] = ['err', "{$result['ok']} fichier(s) OK. Échec : " . implode(', ', $result['fail'])];
        }
    }
}

/* ---- Recalcul allUpToDate ---- */

$repo = upd_parse_repo($cfg['github_url'] ?? '');

$allUpToDate = false;
if ($checkResults !== null) {
    $hasFormationUpdates = !empty(array_filter($checkResults, function ($r) { return $r['status'] !== 'ok'; }));
    $hasModuleUpdate     = $moduleCheckResult && $moduleCheckResult['update'];
    $allUpToDate         = !$hasFormationUpdates && !$hasModuleUpdate && !empty($checkResults);
}

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.upd-section { max-width: 860px; margin-bottom: 32px; }
.upd-section h2 { color: #0254a8; font-size: 16px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.upd-btn { padding: 8px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.upd-btn-check  { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; }
.upd-btn-apply  { background: #1a8a4a; color: #fff; }
.upd-btn-module { background: #082c7c; color: #fff; }
.upd-btn-danger { background: #c0392b; color: #fff; }
.upd-btn + .upd-btn { margin-left: 8px; }
.upd-msg { padding: 8px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
.upd-msg-ok  { background: #e8faf0; border-left: 3px solid #1a8a4a; color: #1a5a33; }
.upd-msg-err { background: #fde8e8; border-left: 3px solid #c0392b; color: #8a1a1a; }
.upd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.upd-table th { background: #0254a8; color: #fff; padding: 8px 12px; text-align: left; font-weight: 600; }
.upd-table td { padding: 8px 12px; border-bottom: 1px solid #eef0f8; }
.upd-table tr:hover td { background: #f7f9ff; }
.upd-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; }
.upd-ok     { background: #d4f0de; color: #1a7a3a; }
.upd-new    { background: #e8f0ff; color: #0254a8; }
.upd-update { background: #fff0d4; color: #7a4a00; }
.upd-hint { font-size: 12px; color: #888; margin-top: 6px; }
.upd-source { font-size: 13px; color: #555; background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 6px; padding: 10px 14px; display: inline-block; line-height: 1.8; }
.upd-source b { color: #082c7c; }
.upd-notes { font-size: 12px; color: #555; background: #fffbea; border-left: 3px solid #f5a623; padding: 6px 10px; border-radius: 0 6px 6px 0; margin-top: 6px; }
details.upd-force > summary {
  cursor: pointer; list-style: none;
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 18px; border-radius: 6px;
  border: 1px solid #c8d4ec; background: #f0f4ff;
  color: #0254a8; font-size: 13px; font-weight: 600; user-select: none;
}
details.upd-force > summary::-webkit-details-marker { display: none; }
details.upd-force > summary::before { content: '▶'; font-size: 10px; display: inline-block; transition: transform .15s; }
details.upd-force[open] > summary::before { transform: rotate(90deg); }
details.upd-force .upd-force-body { margin-top: 16px; }
</style>

<h1>Guide FFTA — Mises à jour</h1>
<p><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/">← Retour à l'administration</a></p>

<?php foreach ($messages as [$type, $text]): ?>
  <div class="upd-msg <?= $type === 'ok' ? 'upd-msg-ok' : 'upd-msg-err' ?>"><?= htmlspecialchars($text) ?></div>
<?php endforeach; ?>

<!-- === SOURCE === -->
<div class="upd-section">
  <h2>Source</h2>
  <?php if ($repo): ?>
    <p class="upd-source">
      Dépôt : <b><?= htmlspecialchars($cfg['github_url']) ?></b><br>
      Branche : <b><?= htmlspecialchars($cfg['github_branch'] ?: 'main') ?></b>
      <?php if (!empty($cfg['github_path'])): ?>
        &nbsp;|&nbsp; Dossier : <b><?= htmlspecialchars($cfg['github_path']) ?></b>
      <?php endif; ?>
      <br>Version locale du module : <b><?= htmlspecialchars($localModVer ?? 'inconnue') ?></b>
      <?php if ($localVer && !empty($localVer['date'])): ?>
        &nbsp;(<?= htmlspecialchars($localVer['date']) ?>)
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p style="color:#c0392b;font-size:13px">⚠ Aucun dépôt GitHub configuré dans <code>module.json</code>.</p>
  <?php endif; ?>
</div>

<!-- === VÉRIFICATION === -->
<?php if ($repo): ?>
<div class="upd-section">
  <h2>Vérifier les mises à jour</h2>
  <form method="post" style="display:inline">
    <input type="hidden" name="action" value="check">
    <button type="submit" class="upd-btn upd-btn-check">🔍 Vérifier maintenant</button>
  </form>

  <?php if ($checkResults !== null): ?>
    <br><br>
    <h3 style="font-size:14px;color:#333;margin:0 0 8px">Formations</h3>
    <table class="upd-table">
      <thead><tr><th>Titre</th><th>ID</th><th>Version locale</th><th>Version distante</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($checkResults as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['title']) ?></td>
          <td style="font-family:monospace;font-size:11px;color:#666"><?= htmlspecialchars($r['id']) ?></td>
          <td><?= htmlspecialchars($r['local_ver'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['remote_ver']) ?></td>
          <td>
            <?php if ($r['status'] === 'ok'): ?>
              <span class="upd-badge upd-ok">✓ À jour</span>
            <?php elseif ($r['status'] === 'new'): ?>
              <span class="upd-badge upd-new">Nouveau</span>
            <?php else: ?>
              <span class="upd-badge upd-update">Mise à jour disponible</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($checkResults)): ?>
        <tr><td colspan="5" style="color:#888;font-style:italic">Aucune formation trouvée dans le dépôt.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <?php if ($moduleCheckResult): ?>
      <br>
      <h3 style="font-size:14px;color:#333;margin:0 0 8px">Module (fichiers du moteur)</h3>
      <p style="font-size:13px">
        Version locale : <b><?= htmlspecialchars($moduleCheckResult['local'] ?? 'inconnue') ?></b>
        &nbsp;|&nbsp;
        Version distante : <b><?= htmlspecialchars($moduleCheckResult['remote']) ?></b>
        <?php if ($moduleCheckResult['date']): ?>
          <span style="color:#888;font-size:11px">(<?= htmlspecialchars($moduleCheckResult['date']) ?>)</span>
        <?php endif; ?>
        &nbsp;
        <?php if ($moduleCheckResult['update']): ?>
          <span class="upd-badge upd-update">Mise à jour disponible</span>
        <?php else: ?>
          <span class="upd-badge upd-ok">À jour</span>
        <?php endif; ?>
      </p>
      <?php if ($moduleCheckResult['notes']): ?>
        <p class="upd-notes"><?= htmlspecialchars($moduleCheckResult['notes']) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- === ACTIONS === -->
<div class="upd-section">

<?php if ($allUpToDate): ?>
  <details class="upd-force">
    <summary>Forcer une mise à jour</summary>
    <div class="upd-force-body">
<?php else: ?>
  <h2>Appliquer les mises à jour</h2>
<?php endif; ?>

  <div style="margin-bottom:20px">
    <h3 style="font-size:14px;color:#333;margin:0 0 8px">Formations</h3>
    <form method="post" style="display:inline"
          onsubmit="return confirm('Mettre à jour les formations ? Les progressions terminées sur d\'anciennes versions seront marquées comme obsolètes.')">
      <input type="hidden" name="action" value="update-formations">
      <input type="hidden" name="mode" value="merge">
      <button type="submit" class="upd-btn upd-btn-apply">↓ Fusionner (conserver les formations locales)</button>
    </form>
    <form method="post" style="display:inline"
          onsubmit="return confirm('ATTENTION : Cette action supprimera les formations locales absentes du dépôt distant. Continuer ?')">
      <input type="hidden" name="action" value="update-formations">
      <input type="hidden" name="mode" value="replace">
      <button type="submit" class="upd-btn upd-btn-danger" style="margin-left:8px">↓ Remplacer (écraser tout)</button>
    </form>
    <p class="upd-hint">
      <b>Fusionner</b> : télécharge les formations nouvelles ou mises à jour, conserve les formations locales absentes du dépôt.<br>
      <b>Remplacer</b> : le contenu du dépôt devient la seule référence. Les formations locales non présentes dans le dépôt sont supprimées.
    </p>
  </div>

  <div>
    <h3 style="font-size:14px;color:#333;margin:0 0 8px">Module (fichiers du moteur)</h3>
    <form method="post" style="display:inline"
          onsubmit="return confirm('Télécharger et remplacer les fichiers du module depuis GitHub ?\n\nLes fichiers listés dans version.json seront remplacés.')">
      <input type="hidden" name="action" value="update-module">
      <button type="submit" class="upd-btn upd-btn-module">↓ Mettre à jour le module</button>
    </form>
    <p class="upd-hint">Remplace les fichiers listés dans <code>version.json</code> par la version du dépôt distant. La config locale (<code>module.json</code>) n'est pas modifiée.</p>
  </div>

<?php if ($allUpToDate): ?>
    </div>
  </details>
<?php endif; ?>

</div>
<?php endif; ?>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
