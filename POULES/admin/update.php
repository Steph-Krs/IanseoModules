<?php
// =============================================================================
// admin/update.php — Mise à jour du module POULES depuis GitHub
// =============================================================================
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(dirname(__DIR__)) . '/_shared/update-lib.php';

// AclRoot seul ne suffit pas : avec un module de comptes, authCheckACL accorde
// AclReadWrite à tout organisateur connecté hors compétition. upd_admin_guard()
// exige en plus la vue Administrateur serveur (AUTH_ROOT).
upd_admin_guard();

$PAGE_TITLE = 'Poules — Mise à jour du module';
$MODULE_DIR = dirname(__DIR__);

$cfg         = upd_load_config($MODULE_DIR);
$messages    = [];
$action      = $_POST['action'] ?? '';
$localVer    = upd_local_version($MODULE_DIR);
$localModVer = $localVer['version'] ?? null;

$checkResult = null;

if ($action === 'check') {
    $remoteVer = upd_remote_version($cfg);
    if (isset($remoteVer['_error'])) {
        $messages[] = ['err', 'GitHub : ' . $remoteVer['_error']];
    } else {
        $checkResult = [
            'local'  => $localModVer,
            'remote' => $remoteVer['version'],
            'notes'  => $remoteVer['notes'] ?? null,
            'date'   => $remoteVer['date']  ?? null,
            'update' => ($localModVer === null || version_compare($remoteVer['version'], $localModVer, '>')),
        ];
    }
}

if ($action === 'update-module') {
    $remoteVer = upd_remote_version($cfg);
    if (isset($remoteVer['_error'])) {
        $messages[] = ['err', 'Impossible de lire version.json distant : ' . $remoteVer['_error']];
    } elseif (empty($remoteVer['files'])) {
        $messages[] = ['err', 'Le version.json distant ne contient pas de liste de fichiers (files[]).'];
    } else {
        $result      = upd_sync_files($cfg, $MODULE_DIR, $remoteVer['files']);
        $localVer    = upd_local_version($MODULE_DIR);
        $localModVer = $localVer['version'] ?? null;
        if (empty($result['fail'])) {
            $messages[] = ['ok', "Module mis à jour vers {$remoteVer['version']} ({$result['ok']} fichier(s))."];
        } else {
            $messages[] = ['err', "{$result['ok']} fichier(s) OK. Échec : " . implode(', ', $result['fail'])];
        }
    }
}

$repo        = upd_parse_repo($cfg['github_url'] ?? '');
$allUpToDate = $checkResult !== null && !$checkResult['update'];

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.upd-section { max-width: 860px; margin-bottom: 32px; }
.upd-section h2 { color: #0254a8; font-size: 16px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.upd-btn { padding: 8px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.upd-btn-check  { background: #f0f4ff; color: #0254a8; border: 1px solid #b0c4e8; }
.upd-btn-module { background: #082c7c; color: #fff; }
.upd-msg { padding: 8px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
.upd-msg-ok  { background: #e8faf0; border-left: 3px solid #1a8a4a; color: #1a5a33; }
.upd-msg-err { background: #fde8e8; border-left: 3px solid #c0392b; color: #8a1a1a; }
.upd-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; }
.upd-ok     { background: #d4f0de; color: #1a7a3a; }
.upd-update { background: #fff0d4; color: #7a4a00; }
.upd-hint { font-size: 12px; color: #888; margin-top: 6px; }
.upd-source { font-size: 13px; color: #555; background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 6px; padding: 10px 14px; display: inline-block; line-height: 1.8; }
.upd-source b { color: #082c7c; }
.upd-notes { font-size: 12px; color: #555; background: #fffbea; border-left: 3px solid #f5a623; padding: 6px 10px; border-radius: 0 6px 6px 0; margin-top: 6px; }
details.upd-force > summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 18px; border-radius: 6px; border: 1px solid #c8d4ec; background: #f0f4ff;
  color: #0254a8; font-size: 13px; font-weight: 600; user-select: none; }
details.upd-force > summary::-webkit-details-marker { display: none; }
details.upd-force > summary::before { content: '▶'; font-size: 10px; display: inline-block; transition: transform .15s; }
details.upd-force[open] > summary::before { transform: rotate(90deg); }
details.upd-force .upd-force-body { margin-top: 16px; }
</style>

<h1>Poules — Mise à jour du module</h1>

<?php foreach ($messages as [$type, $text]): ?>
  <div class="upd-msg <?= $type === 'ok' ? 'upd-msg-ok' : 'upd-msg-err' ?>"><?= htmlspecialchars($text) ?></div>
<?php endforeach; ?>

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

<?php if ($repo): ?>
<div class="upd-section">
  <h2>Vérifier les mises à jour</h2>
  <form method="post" style="display:inline">
    <input type="hidden" name="action" value="check">
    <button type="submit" class="upd-btn upd-btn-check">🔍 Vérifier maintenant</button>
  </form>

  <?php if ($checkResult): ?>
    <p style="font-size:13px">
      Version locale : <b><?= htmlspecialchars($checkResult['local'] ?? 'inconnue') ?></b>
      &nbsp;|&nbsp;
      Version distante : <b><?= htmlspecialchars($checkResult['remote']) ?></b>
      <?php if ($checkResult['date']): ?>
        <span style="color:#888;font-size:11px">(<?= htmlspecialchars($checkResult['date']) ?>)</span>
      <?php endif; ?>
      &nbsp;
      <?php if ($checkResult['update']): ?>
        <span class="upd-badge upd-update">Mise à jour disponible</span>
      <?php else: ?>
        <span class="upd-badge upd-ok">✓ À jour</span>
      <?php endif; ?>
    </p>
    <?php if ($checkResult['notes']): ?>
      <p class="upd-notes"><?= htmlspecialchars($checkResult['notes']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="upd-section">
<?php if ($allUpToDate): ?>
  <details class="upd-force">
    <summary>Forcer une mise à jour</summary>
    <div class="upd-force-body">
<?php else: ?>
  <h2>Appliquer la mise à jour</h2>
<?php endif; ?>

  <form method="post" style="display:inline"
        onsubmit="return confirm('Télécharger et remplacer les fichiers du module depuis GitHub ?\n\nLes fichiers listés dans version.json seront remplacés.')">
    <input type="hidden" name="action" value="update-module">
    <button type="submit" class="upd-btn upd-btn-module">↓ Mettre à jour le module</button>
  </form>
  <p class="upd-hint">Remplace les fichiers listés dans <code>version.json</code> par la version du dépôt distant. La config locale (<code>module.json</code>) n'est pas modifiée.</p>

<?php if ($allUpToDate): ?>
    </div>
  </details>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- === ZONE DE DANGER === -->
<div class="upd-section">
  <details class="upd-force">
    <summary style="border-color:#e8b4ae;background:#fdf0ef;color:#c0392b">Désinstaller le module</summary>
    <div class="upd-force-body">
      <p class="upd-hint" style="margin-top:0">
        Supprime les fichiers du module. Une sauvegarde est créée avant suppression.
        Ce module ne crée aucune table : aucune donnée n'est perdue.
      </p>
      <a class="upd-btn" style="background:#c0392b;color:#fff;text-decoration:none;display:inline-block"
         href="<?= $CFG->ROOT_DIR ?>Modules/Custom/_shared/uninstall.php?module=POULES">🗑 Désinstaller POULES…</a>
    </div>
  </details>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
