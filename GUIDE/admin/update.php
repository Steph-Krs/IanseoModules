<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');
require_once dirname(dirname(__DIR__)) . '/_shared/update-ui.php'; // rendu commun + blocs partagés
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

// Installation d'un autre module du dépôt (traité avant la liste « autres modules »).
upd_install_handle($cfg, $messages);

$localVer    = upd_local_version($MODULE_DIR);
$localModVer = $localVer['version'] ?? null;

$checkResults      = null;
$moduleCheckResult = null;
$sharedCheck       = null;

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
        // Bibliothèque commune _shared, alignée dans la foulée.
        $sh = upd_sync_shared($cfg);
        if (isset($sh['_error']))      $messages[] = ['err', 'Bibliothèque commune _shared : ' . $sh['_error']];
        elseif (!empty($sh['fail']))   $messages[] = ['err', 'Bibliothèque commune _shared : échec ' . implode(', ', $sh['fail'])];
        elseif ($sh['ok'])             $messages[] = ['ok', "Bibliothèque commune _shared synchronisée (v{$sh['version']}, {$sh['ok']} fichier(s))."];
    }
}

// État de la bibliothèque commune + catalogue des autres modules du dépôt.
if ($action === 'check' || $action === 'update-module') {
    $rs = upd_remote_shared_version($cfg);
    if (!isset($rs['_error'])) {
        $ls  = upd_local_shared_version();
        $lsv = $ls['version'] ?? null;
        $sharedCheck = ['local' => $lsv, 'remote' => $rs['version'],
            'update' => ($lsv === null || version_compare($rs['version'], $lsv, '>'))];
    }
}
$othersState = upd_others_state($cfg);

/* ---- Recalcul allUpToDate ---- */

$repo = upd_parse_repo($cfg['github_url'] ?? '');

$allUpToDate = false;
if ($checkResults !== null) {
    $hasFormationUpdates = !empty(array_filter($checkResults, function ($r) { return $r['status'] !== 'ok'; }));
    $hasModuleUpdate     = $moduleCheckResult && $moduleCheckResult['update'];
    $hasSharedUpdate     = $sharedCheck && $sharedCheck['update'];
    $allUpToDate         = !$hasFormationUpdates && !$hasModuleUpdate && !$hasSharedUpdate && !empty($checkResults);
}

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<?= upd_ui_styles() ?>

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
    <?= upd_ui_shared_status($sharedCheck) ?>
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

<!-- === AUTRES MODULES DU DÉPÔT === -->
<?= upd_ui_others_block($cfg, $othersState, 'GUIDE') ?>

<!-- === ZONE DE DANGER === -->
<?= upd_ui_danger_zone($MODULE_DIR) ?>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
