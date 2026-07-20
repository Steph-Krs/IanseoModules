<?php
/**
 * Rendu commun des pages de mise à jour des modules Custom.
 *
 * Objectif : une seule implémentation du comportement (vérifier / mettre à jour /
 * synchroniser _shared / installer d'autres modules du dépôt / désinstaller) pour
 * que les 5 modules se comportent et s'affichent de façon identique.
 *
 * - Module « simple » : admin/update.php se réduit à upd_render_common_page().
 * - Module avec sections propres (ex. GUIDE et ses formations) : il assemble
 *   lui-même la page en réutilisant les briques upd_ui_*() et upd_*_handle().
 *
 * Charte : bleu FFTA (#0254a8). Les impressions d'un module gardent leur propre
 * charte (ex. rose/bleu TNM) — ce fichier ne concerne que la page d'admin.
 */

require_once __DIR__ . '/update-lib.php';

// -------- Styles communs (superset couvrant aussi les tableaux de GUIDE) -----
function upd_ui_styles() {
    return <<<'CSS'
<style>
.upd-section { max-width: 860px; margin-bottom: 32px; }
.upd-section h2 { color: #0254a8; font-size: 16px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.upd-section h3 { font-size: 14px; color: #333; margin: 0 0 8px; }
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
.upd-warn { font-size: 13px; color: #8a1a1a; background: #fdecea; border: 1px solid #c0392b; border-left-width: 4px; border-radius: 0 6px 6px 0; padding: 10px 14px; margin: 0 0 14px; line-height: 1.6; }
details.upd-force > summary {
  cursor: pointer; list-style: none;
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 18px; border-radius: 6px;
  border: 1px solid #c8d4ec; background: #f0f4ff;
  color: #0254a8; font-size: 13px; font-weight: 600; user-select: none;
}
details.upd-force > summary::-webkit-details-marker { display: none; }
details.upd-force > summary::before { content: '\25B6'; font-size: 10px; display: inline-block; transition: transform .15s; }
details.upd-force[open] > summary::before { transform: rotate(90deg); }
details.upd-force .upd-force-body { margin-top: 16px; }
</style>
CSS;
}

// -------- Bloc « Source » -----------------------------------------------------
function upd_ui_source($cfg, $localVer, $localModVer) {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    ob_start(); ?>
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
    <p style="color:#c0392b;font-size:13px">&#9888; Aucun dépôt GitHub configuré dans <code>module.json</code>.</p>
  <?php endif; ?>
</div>
    <?php return ob_get_clean();
}

// -------- Traitement POST : installation d'un autre module du dépôt ----------
// À appeler AVANT upd_others_state() pour que le module fraîchement installé
// apparaisse comme installé dans la liste.
function upd_install_handle($cfg, &$messages) {
    if (($_POST['action'] ?? '') !== 'install-module') return;
    $name = $_POST['name'] ?? '';
    if (!upd_valid_module_name($name)) { $messages[] = ['err', 'Nom de module invalide.']; return; }
    $remote = upd_remote_modules($cfg);
    if (isset($remote['_error'])) { $messages[] = ['err', 'GitHub : ' . htmlspecialchars($remote['_error'])]; return; }
    if (!in_array($name, $remote, true)) { $messages[] = ['err', 'Module « ' . htmlspecialchars($name) . ' » absent du dépôt.']; return; }
    $res = upd_install_module($cfg, $name);
    if (isset($res['_error'])) { $messages[] = ['err', 'Installation de ' . htmlspecialchars($name) . ' : ' . htmlspecialchars($res['_error'])]; return; }
    $extra = empty($res['fail']) ? '' : ' (échecs : ' . htmlspecialchars(implode(', ', $res['fail'])) . ')';
    $messages[] = ['ok', 'Module « ' . htmlspecialchars($name) . ' » installé en version '
        . htmlspecialchars($res['version']) . ' (' . (int)$res['ok'] . ' fichier(s))' . $extra
        . '. Rechargez ianseo pour le voir apparaître dans son menu.'];
}

// -------- Traitement POST : liste des modules du dépôt (bloc « autres ») ------
// Renvoie ['checked'=>bool, 'error'=>?string, 'modules'=>[['name','installed'],...]].
function upd_others_state($cfg) {
    $state = ['checked' => false, 'error' => null, 'modules' => []];
    $action = $_POST['action'] ?? '';
    if ($action !== 'check-others' && $action !== 'install-module') return $state;
    $remote = upd_remote_modules($cfg);
    if (isset($remote['_error'])) { $state['error'] = $remote['_error']; return $state; }
    $state['checked'] = true;
    $installed = upd_list_modules();
    foreach ($remote as $n) {
        $state['modules'][] = ['name' => $n, 'installed' => in_array($n, $installed, true)];
    }
    return $state;
}

// -------- Bloc « Autres modules du dépôt » (replié par défaut) ----------------
function upd_ui_others_block($cfg, $state, $selfName) {
    if (!upd_parse_repo($cfg['github_url'] ?? '')) return '';
    ob_start(); ?>
<div class="upd-section">
  <details class="upd-force"<?= $state['checked'] || $state['error'] ? ' open' : '' ?>>
    <summary>Autres modules du dépôt</summary>
    <div class="upd-force-body">
      <p class="upd-hint" style="margin-top:0">Installe d'autres modules publiés dans le même dépôt GitHub, directement depuis ianseo.</p>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="check-others">
        <button type="submit" class="upd-btn upd-btn-check">&#128269; Voir les modules disponibles</button>
      </form>
      <?php if ($state['error']): ?>
        <p class="upd-msg upd-msg-err" style="margin-top:12px">GitHub : <?= htmlspecialchars($state['error']) ?></p>
      <?php elseif ($state['checked']): ?>
        <table class="upd-table" style="margin-top:12px">
          <thead><tr><th>Module</th><th>État</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($state['modules'] as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['name']) ?><?= $m['name'] === $selfName ? ' <span class="upd-hint">(ce module)</span>' : '' ?></td>
              <td><?= $m['installed']
                    ? '<span class="upd-badge upd-ok">&#10003; Installé</span>'
                    : '<span class="upd-badge upd-new">Disponible</span>' ?></td>
              <td>
                <?php if (!$m['installed']): ?>
                  <form method="post" style="margin:0"
                        onsubmit="return confirm('Installer le module <?= htmlspecialchars($m['name'], ENT_QUOTES) ?> depuis GitHub ?')">
                    <input type="hidden" name="action" value="install-module">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($m['name']) ?>">
                    <button type="submit" class="upd-btn upd-btn-module">&#8595; Installer</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($state['modules'])): ?>
            <tr><td colspan="3" style="color:#888;font-style:italic">Aucun module trouvé dans le dépôt.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </details>
</div>
    <?php return ob_get_clean();
}

// -------- Zone de danger : désinstallation ------------------------------------
function upd_ui_danger_zone($module_dir) {
    global $CFG;
    $name    = basename($module_dir);
    $tables  = upd_module_tables($module_dir);
    $warning = upd_uninstall_warning($module_dir);
    ob_start(); ?>
<div class="upd-section">
  <details class="upd-force">
    <summary style="border-color:#e8b4ae;background:#fdf0ef;color:#c0392b">Désinstaller le module</summary>
    <div class="upd-force-body">
      <?php if ($warning): ?>
        <div class="upd-warn"><b>&#9888;</b> <?= nl2br(htmlspecialchars($warning)) ?></div>
      <?php endif; ?>
      <p class="upd-hint" style="margin-top:0">
        Supprime les fichiers du module. Une sauvegarde est créée avant suppression.
        <?php if ($tables): ?>
          La suppression des données en base (<?= htmlspecialchars(implode(', ', $tables)) ?>)
          est proposée séparément, <b>décochée par défaut</b>.
        <?php else: ?>
          Ce module ne crée aucune table : aucune donnée n'est perdue.
        <?php endif; ?>
      </p>
      <a class="upd-btn upd-btn-danger" style="text-decoration:none;display:inline-block"
         href="<?= $CFG->ROOT_DIR ?>Modules/Custom/_shared/uninstall.php?module=<?= urlencode($name) ?>">&#128465; Désinstaller <?= htmlspecialchars($name) ?>&hellip;</a>
    </div>
  </details>
</div>
    <?php return ob_get_clean();
}

// -------- Ligne d'état de la bibliothèque commune (dans « Vérifier ») ---------
function upd_ui_shared_status($sharedCheck) {
    if (!$sharedCheck) return '';
    ob_start(); ?>
    <p style="font-size:13px;margin-top:10px">
      Bibliothèque commune <code>_shared</code> :
      locale <b><?= htmlspecialchars($sharedCheck['local'] ?? 'inconnue') ?></b>
      &nbsp;|&nbsp; distante <b><?= htmlspecialchars($sharedCheck['remote']) ?></b>
      &nbsp;
      <?php if ($sharedCheck['update']): ?>
        <span class="upd-badge upd-update">Mise à jour disponible</span>
      <?php else: ?>
        <span class="upd-badge upd-ok">&#10003; À jour</span>
      <?php endif; ?>
      <span class="upd-hint">— synchronisée automatiquement avec la mise à jour du module.</span>
    </p>
    <?php return ob_get_clean();
}

// -------- Traitement POST commun : check / update-module (+ _shared) ----------
// Renvoie ['checkResult'=>?, 'sharedCheck'=>?] ; empile les messages.
function upd_module_handle($cfg, $module_dir, &$messages, $opts = []) {
    $out = ['checkResult' => null, 'sharedCheck' => null];
    $action = $_POST['action'] ?? '';
    $localVer    = upd_local_version($module_dir);
    $localModVer = $localVer['version'] ?? null;

    if ($action === 'check' || $action === 'update-module') {
        $rs = upd_remote_shared_version($cfg);
        if (!isset($rs['_error'])) {
            $ls = upd_local_shared_version();
            $lsv = $ls['version'] ?? null;
            $out['sharedCheck'] = [
                'local'  => $lsv,
                'remote' => $rs['version'],
                'update' => ($lsv === null || version_compare($rs['version'], $lsv, '>')),
            ];
        }
    }

    if ($action === 'check') {
        $remoteVer = upd_remote_version($cfg);
        if (isset($remoteVer['_error'])) {
            $messages[] = ['err', 'GitHub : ' . htmlspecialchars($remoteVer['_error'])];
        } else {
            $out['checkResult'] = [
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
            $messages[] = ['err', 'Impossible de lire version.json distant : ' . htmlspecialchars($remoteVer['_error'])];
        } elseif (empty($remoteVer['files'])) {
            $messages[] = ['err', 'Le version.json distant ne contient pas de liste de fichiers (files[]).'];
        } else {
            $result = upd_sync_files($cfg, $module_dir, $remoteVer['files']);
            if (empty($result['fail'])) {
                $messages[] = ['ok', 'Module mis à jour vers ' . htmlspecialchars($remoteVer['version'])
                    . ' (' . (int)$result['ok'] . ' fichier(s)).'];
            } else {
                $messages[] = ['err', (int)$result['ok'] . ' fichier(s) OK. Échec : '
                    . htmlspecialchars(implode(', ', $result['fail']))];
            }
            // Bibliothèque commune, alignée dans la foulée.
            $sh = upd_sync_shared($cfg);
            if (isset($sh['_error'])) {
                $messages[] = ['err', 'Bibliothèque commune _shared : ' . htmlspecialchars($sh['_error'])];
            } elseif (!empty($sh['fail'])) {
                $messages[] = ['err', 'Bibliothèque commune _shared : échec ' . htmlspecialchars(implode(', ', $sh['fail']))];
            } elseif ($sh['ok']) {
                $messages[] = ['ok', 'Bibliothèque commune _shared synchronisée (v'
                    . htmlspecialchars($sh['version']) . ', ' . (int)$sh['ok'] . ' fichier(s)).'];
            }
            // Recharge la version locale (version.json vient d'être téléchargé).
            $localVer    = upd_local_version($module_dir);
            $localModVer  = $localVer['version'] ?? null;
            $out['checkResult'] = [
                'local'  => $localModVer,
                'remote' => $remoteVer['version'],
                'notes'  => $remoteVer['notes'] ?? null,
                'date'   => $remoteVer['date']  ?? null,
                'update' => version_compare($remoteVer['version'], $localModVer ?? '0', '>'),
            ];
            // Rappel post-MaJ propre au module (ex. AUTH : redéployer dist/).
            if (!empty($opts['after_update']) && is_callable($opts['after_update'])) {
                $extra = call_user_func($opts['after_update']);
                if (is_string($extra) && $extra !== '') $messages[] = ['ok', $extra];
            }
        }
    }
    return $out;
}

// -------- Bloc « Vérifier / Appliquer » du module -----------------------------
function upd_ui_module_block($cfg, $checkResult, $sharedCheck) {
    if (!upd_parse_repo($cfg['github_url'] ?? '')) return '';
    $allUpToDate = $checkResult !== null && !$checkResult['update']
                   && !($sharedCheck && $sharedCheck['update']);
    ob_start(); ?>
<div class="upd-section">
  <h2>Vérifier les mises à jour</h2>
  <form method="post" style="display:inline">
    <input type="hidden" name="action" value="check">
    <button type="submit" class="upd-btn upd-btn-check">&#128269; Vérifier maintenant</button>
  </form>

  <?php if ($checkResult): ?>
    <p style="font-size:13px;margin-top:14px">
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
        <span class="upd-badge upd-ok">&#10003; À jour</span>
      <?php endif; ?>
    </p>
    <?php if ($checkResult['notes']): ?>
      <p class="upd-notes"><?= htmlspecialchars($checkResult['notes']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
  <?= upd_ui_shared_status($sharedCheck) ?>
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
        onsubmit="return confirm('Télécharger et remplacer les fichiers du module depuis GitHub ?\n\nLes fichiers listés dans version.json seront remplacés, et la bibliothèque commune _shared synchronisée.')">
    <input type="hidden" name="action" value="update-module">
    <button type="submit" class="upd-btn upd-btn-module">&#8595; Mettre à jour le module</button>
  </form>
  <p class="upd-hint">Remplace les fichiers listés dans <code>version.json</code> par la version du dépôt et synchronise <code>_shared</code>. La config locale (<code>module.json</code>) n'est pas modifiée.</p>
<?php if ($allUpToDate): ?>
    </div>
  </details>
<?php endif; ?>
</div>
    <?php return ob_get_clean();
}

// -------- Messages -----------------------------------------------------------
function upd_ui_messages($messages) {
    ob_start();
    foreach ($messages as [$type, $text]) {
        $cls = $type === 'ok' ? 'upd-msg-ok' : 'upd-msg-err';
        echo '<div class="upd-msg ' . $cls . '">' . $text . '</div>';
    }
    return ob_get_clean();
}

/**
 * Page de mise à jour complète pour un module « simple ».
 * $opts : h1, title, back=['url'=>,'label'=>], after_update=callable():string
 */
function upd_render_common_page($module_dir, $opts = []) {
    global $CFG;
    $cfg  = upd_load_config($module_dir);
    $name = basename($module_dir);
    $h1   = $opts['h1'] ?? ($name . ' — Mise à jour du module');

    $localVer    = upd_local_version($module_dir);
    $localModVer = $localVer['version'] ?? null;

    $messages = [];
    upd_install_handle($cfg, $messages);
    $mod    = upd_module_handle($cfg, $module_dir, $messages, $opts);
    $others = upd_others_state($cfg);

    $PAGE_TITLE = $opts['title'] ?? $h1;
    include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

    echo upd_ui_styles();
    echo '<h1>' . htmlspecialchars($h1) . '</h1>';
    if (!empty($opts['back']['url'])) {
        echo '<p><a href="' . htmlspecialchars($opts['back']['url']) . '">&larr; '
           . htmlspecialchars($opts['back']['label'] ?? 'Retour') . '</a></p>';
    }
    echo upd_ui_messages($messages);
    echo upd_ui_source($cfg, $localVer, $localModVer);
    echo upd_ui_module_block($cfg, $mod['checkResult'], $mod['sharedCheck']);
    echo upd_ui_others_block($cfg, $others, $name);
    echo upd_ui_danger_zone($module_dir);

    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
}
