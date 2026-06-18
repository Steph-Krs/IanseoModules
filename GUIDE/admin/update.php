<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');

checkFullACL(AclRoot, '', AclReadWrite);

// PHP 7 compat
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}

$PAGE_TITLE = 'Guide FFTA — Mises à jour';
$MODULE_DIR = dirname(__DIR__);
$CONFIG_FILE = $MODULE_DIR . '/guide-config.json';

/* ---- Helpers ---- */

function upd_load_config() {
    global $CONFIG_FILE;
    if (!is_file($CONFIG_FILE)) return ['github_url' => '', 'github_branch' => 'main', 'github_token' => ''];
    $d = json_decode(file_get_contents($CONFIG_FILE), true);
    return is_array($d) ? $d : ['github_url' => '', 'github_branch' => 'main', 'github_token' => ''];
}

function upd_save_config($cfg) {
    global $CONFIG_FILE;
    file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function upd_parse_repo($url) {
    if (preg_match('|github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?$|i', $url, $m)) {
        return ['owner' => $m[1], 'repo' => rtrim($m[2], '/')];
    }
    return null;
}

function upd_gh_fetch($url, $token = '') {
    $headers = "User-Agent: ianseo-guide/1.0\r\n";
    if ($token) $headers .= "Authorization: token $token\r\n";
    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return ['_error' => 'Impossible de joindre GitHub (vérifiez la connexion internet)'];
    $data = json_decode($res, true);
    // GitHub rate-limit / auth error
    if (isset($data['message'])) return ['_error' => $data['message']];
    return $data;
}

function upd_gh_raw($url, $token = '') {
    $headers = "User-Agent: ianseo-guide/1.0\r\n";
    if ($token) $headers .= "Authorization: token $token\r\n";
    $ctx = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx);
}

function upd_local_formations() {
    global $MODULE_DIR;
    $result = [];
    foreach (glob($MODULE_DIR . '/content/*.json') as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['id'])) continue;
        $result[$d['id']] = ['version' => $d['version'] ?? '1.0', 'file' => $f, 'title' => $d['title'] ?? $d['id']];
    }
    return $result;
}

function upd_ensure_schema() {
    if (!empty($_SESSION['_guide_schema_ok'])) return;
    $rs = safe_r_sql("SHOW TABLES LIKE 'GUIDE_Progress'");
    if (!safe_fetch($rs)) {
        safe_w_sql("CREATE TABLE GUIDE_Progress (
            GpId INT AUTO_INCREMENT PRIMARY KEY,
            GpFormId VARCHAR(100) NOT NULL, GpFormVer VARCHAR(20) NOT NULL DEFAULT '1.0',
            GpTourId INT NOT NULL DEFAULT 0, GpStep INT NOT NULL DEFAULT 0,
            GpStatus ENUM('en_cours','termine','obsolete') NOT NULL DEFAULT 'en_cours',
            GpValidated TEXT, GpCreatedAt DATETIME NOT NULL, GpUpdatedAt DATETIME NOT NULL,
            UNIQUE KEY uq_form_tour (GpFormId, GpTourId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $_SESSION['_guide_schema_ok'] = true;
}

/* ---- Actions POST ---- */

$cfg      = upd_load_config();
$messages = [];
$action   = $_POST['action'] ?? '';

if ($action === 'save-config') {
    $cfg['github_url']    = trim($_POST['github_url']    ?? '');
    $cfg['github_branch'] = trim($_POST['github_branch'] ?? 'main') ?: 'main';
    $cfg['github_token']  = trim($_POST['github_token']  ?? '');
    upd_save_config($cfg);
    $messages[] = ['ok', 'Configuration sauvegardée.'];
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$checkResults    = null;
$moduleCheckResult = null;

if ($action === 'check') {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) {
        $messages[] = ['err', 'URL GitHub invalide. Format attendu : https://github.com/propriétaire/dépôt'];
    } else {
        $branch = $cfg['github_branch'] ?: 'main';
        $token  = $cfg['github_token']  ?: '';
        $apiBase = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}";

        // Vérifier formations
        $contentList = upd_gh_fetch("$apiBase/contents/content?ref=$branch", $token);
        if (isset($contentList['_error'])) {
            $messages[] = ['err', 'GitHub : ' . $contentList['_error']];
        } else {
            $local       = upd_local_formations();
            $checkResults = [];
            foreach ($contentList as $item) {
                if (!isset($item['name']) || !str_ends_with($item['name'], '.json')) continue;
                $raw  = upd_gh_raw($item['download_url'], $token);
                $data = $raw ? json_decode($raw, true) : null;
                if (!$data || empty($data['id'])) continue;
                $id      = $data['id'];
                $remVer  = $data['version'] ?? '1.0';
                $locVer  = $local[$id]['version'] ?? null;
                $status  = 'new';
                if ($locVer !== null) {
                    $status = version_compare($remVer, $locVer, '>') ? 'update' : 'ok';
                }
                $checkResults[] = [
                    'id'        => $id,
                    'title'     => $data['title'] ?? $id,
                    'local_ver' => $locVer,
                    'remote_ver'=> $remVer,
                    'status'    => $status,
                    'filename'  => $item['name'],
                    'raw_url'   => $item['download_url'],
                ];
            }
        }

        // Vérifier version module (fichier VERSION à la racine du repo)
        $moduleVerRaw = upd_gh_raw("https://raw.githubusercontent.com/{$repo['owner']}/{$repo['repo']}/$branch/VERSION", $token);
        $localVerFile = $MODULE_DIR . '/VERSION';
        $localModVer  = is_file($localVerFile) ? trim(file_get_contents($localVerFile)) : null;
        $remoteModVer = $moduleVerRaw ? trim($moduleVerRaw) : null;
        if ($remoteModVer) {
            $moduleCheckResult = [
                'local'  => $localModVer,
                'remote' => $remoteModVer,
                'update' => ($localModVer === null || version_compare($remoteModVer, $localModVer, '>')),
            ];
        }
    }
}

if ($action === 'update-formations') {
    $mode = $_POST['mode'] ?? 'merge'; // merge | replace
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) {
        $messages[] = ['err', 'URL GitHub invalide.'];
    } else {
        upd_ensure_schema();
        $branch      = $cfg['github_branch'] ?: 'main';
        $token       = $cfg['github_token']  ?: '';
        $apiBase     = "https://api.github.com/repos/{$repo['owner']}/{$repo['repo']}";
        $contentList = upd_gh_fetch("$apiBase/contents/content?ref=$branch", $token);
        $local       = upd_local_formations();
        $contentDir  = $MODULE_DIR . '/content/';
        $updated = $added = $skipped = 0;

        if (isset($contentList['_error'])) {
            $messages[] = ['err', 'GitHub : ' . $contentList['_error']];
        } else {
            // En mode replace : supprimer les fichiers locaux absents du repo distant
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

                $id      = $data['id'];
                $remVer  = $data['version'] ?? '1.0';
                $locVer  = $local[$id]['version'] ?? null;

                if ($locVer !== null && !version_compare($remVer, $locVer, '>') && $mode === 'merge') {
                    $skipped++; continue;
                }

                // Écrire le fichier
                file_put_contents($contentDir . $item['name'], $raw);

                if ($locVer !== null) {
                    $updated++;
                    // Marquer les progressions "terminées" comme obsolètes si version changée
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
            $messages[] = ['ok', "Formations mises à jour : $updated modifiée(s), $added ajoutée(s), $skipped déjà à jour."];
        }
    }
}

if ($action === 'update-module') {
    $repo = upd_parse_repo($cfg['github_url'] ?? '');
    if (!$repo) {
        $messages[] = ['err', 'URL GitHub invalide.'];
    } else {
        $branch  = $cfg['github_branch'] ?: 'main';
        $token   = $cfg['github_token']  ?: '';
        $rawBase = "https://raw.githubusercontent.com/{$repo['owner']}/{$repo['repo']}/$branch";
        $files   = [
            'assets/guide.css' => $MODULE_DIR . '/assets/guide.css',
            'assets/guide.js'  => $MODULE_DIR . '/assets/guide.js',
        ];
        $ok = $fail = 0;
        foreach ($files as $remotePath => $localPath) {
            $content = upd_gh_raw("$rawBase/$remotePath", $token);
            if ($content && strlen($content) > 100) {
                file_put_contents($localPath, $content);
                $ok++;
            } else {
                $fail++;
            }
        }
        // Mettre à jour le fichier VERSION
        $verContent = upd_gh_raw("$rawBase/VERSION", $token);
        if ($verContent) file_put_contents($MODULE_DIR . '/VERSION', $verContent);

        if ($fail === 0) {
            $messages[] = ['ok', "Module mis à jour ($ok fichier(s) téléchargé(s))."];
        } else {
            $messages[] = ['err', "$fail fichier(s) n'ont pas pu être téléchargé(s). $ok réussi(s)."];
        }
    }
}

$cfg    = upd_load_config(); // recharger après save
$local  = upd_local_formations();
$repo   = upd_parse_repo($cfg['github_url'] ?? '');
$localModVer = is_file($MODULE_DIR . '/VERSION') ? trim(file_get_contents($MODULE_DIR . '/VERSION')) : null;

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.upd-section { max-width: 860px; margin-bottom: 32px; }
.upd-section h2 { color: #0254a8; font-size: 16px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.upd-form label { display: block; margin-bottom: 12px; font-size: 13px; color: #333; }
.upd-form label span { display: block; font-weight: 600; margin-bottom: 4px; }
.upd-form input[type=text], .upd-form input[type=password] {
  width: 100%; max-width: 500px; padding: 7px 10px; border: 1px solid #c8d4ec;
  border-radius: 6px; font-size: 13px; box-sizing: border-box;
}
.upd-btn { padding: 8px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.upd-btn-save   { background: linear-gradient(80deg,#0254a8 10%,#082c7c 100%); color:#fff; }
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
.upd-code { background: #f0f4ff; border: 1px solid #c5cef5; border-radius: 4px; padding: 10px 14px; font-family: monospace; font-size: 12px; color: #082c7c; margin: 8px 0; white-space: pre-wrap; }
</style>

<h1>Guide FFTA — Mises à jour</h1>
<p><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/">← Retour à l'administration</a></p>

<?php foreach ($messages as [$type, $text]): ?>
  <div class="upd-msg <?= $type === 'ok' ? 'upd-msg-ok' : 'upd-msg-err' ?>"><?= htmlspecialchars($text) ?></div>
<?php endforeach; ?>

<!-- === CONFIG GITHUB === -->
<div class="upd-section">
  <h2>Configuration GitHub</h2>
  <form method="post" class="upd-form">
    <input type="hidden" name="action" value="save-config">
    <label>
      <span>URL du dépôt GitHub</span>
      <input type="text" name="github_url" value="<?= htmlspecialchars($cfg['github_url']) ?>"
             placeholder="https://github.com/propriétaire/guide-ianseo">
    </label>
    <label>
      <span>Branche</span>
      <input type="text" name="github_branch" value="<?= htmlspecialchars($cfg['github_branch'] ?: 'main') ?>" style="max-width:200px">
    </label>
    <label>
      <span>Token GitHub (optionnel — pour dépôt privé ou quota élevé)</span>
      <input type="password" name="github_token" value="<?= htmlspecialchars($cfg['github_token']) ?>"
             placeholder="ghp_xxxxxxxxxxxx" autocomplete="off">
    </label>
    <button type="submit" class="upd-btn upd-btn-save">Enregistrer</button>
  </form>

  <?php if (!$repo): ?>
    <p class="upd-hint">
      Pas encore de dépôt GitHub ? Créez un dépôt public avec cette structure :
    </p>
    <div class="upd-code">guide-ianseo/
├── assets/
│   ├── guide.css
│   └── guide.js
├── content/
│   └── 01-premiere-competition.json
└── VERSION        ← fichier texte avec le numéro de version (ex: 1.0)</div>
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
      <h3 style="font-size:14px;color:#333;margin:0 0 8px">Module (assets CSS/JS)</h3>
      <p style="font-size:13px">
        Version locale : <b><?= htmlspecialchars($moduleCheckResult['local'] ?? 'inconnue') ?></b>
        &nbsp;|&nbsp;
        Version distante : <b><?= htmlspecialchars($moduleCheckResult['remote']) ?></b>
        &nbsp;
        <?php if ($moduleCheckResult['update']): ?>
          <span class="upd-badge upd-update">Mise à jour disponible</span>
        <?php else: ?>
          <span class="upd-badge upd-ok">À jour</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- === ACTIONS === -->
<?php if ($repo): ?>
<div class="upd-section">
  <h2>Appliquer les mises à jour</h2>

  <div style="margin-bottom:20px">
    <h3 style="font-size:14px;color:#333;margin:0 0 8px">Formations</h3>
    <form method="post" style="display:inline"
          onsubmit="return confirm('Mettre à jour les formations ? Les progressions terminées sur d\'anciennes versions seront marquées comme obsolètes.')">
      <input type="hidden" name="action" value="update-formations">
      <input type="hidden" name="mode" value="merge">
      <button type="submit" class="upd-btn upd-btn-apply">↓ Fusionner (conserver les formations locales)</button>
    </form>
    <form method="post" style="display:inline"
          onsubmit="return confirm('ATTENTION : Cette action supprimera les formations locales absentes du dépôt distant et remplacera toutes les autres. Continuer ?')">
      <input type="hidden" name="action" value="update-formations">
      <input type="hidden" name="mode" value="replace">
      <button type="submit" class="upd-btn upd-btn-danger" style="margin-left:8px">↓ Remplacer (écraser tout)</button>
    </form>
    <p class="upd-hint">
      <b>Fusionner</b> : télécharge les formations nouvelles ou mises à jour, conserve les formations locales absentes du dépôt.<br>
      <b>Remplacer</b> : le contenu du dépôt distant devient la seule référence. Les formations locales non présentes dans le dépôt sont supprimées.
    </p>
  </div>

  <div>
    <h3 style="font-size:14px;color:#333;margin:0 0 8px">Module (guide.css et guide.js)</h3>
    <form method="post" style="display:inline"
          onsubmit="return confirm('Télécharger et remplacer guide.css et guide.js depuis GitHub ?')">
      <input type="hidden" name="action" value="update-module">
      <button type="submit" class="upd-btn upd-btn-module">↓ Mettre à jour le module</button>
    </form>
    <p class="upd-hint">Remplace les fichiers assets locaux par la version du dépôt distant.</p>
  </div>
</div>
<?php endif; ?>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
