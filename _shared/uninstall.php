<?php
/**
 * Désinstallation d'un module Custom, commune à tous les modules.
 *
 * Ce fichier vit dans _shared/ et NON dans le module : un script qui supprime
 * son propre dossier pendant qu'il s'exécute échoue sous Windows (fichier
 * verrouillé par Apache/PHP). Depuis _shared/, rien de ce qui tourne n'est
 * supprimé.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once __DIR__ . '/update-lib.php';

upd_admin_guard();

// Téléchargement à usage unique de la sauvegarde (modules "uninstall_backup") :
// on l'envoie puis on la supprime → rien ne persiste sur le serveur.
if (isset($_GET['download'])) {
    $dl = $_SESSION['upd_backup_dl'] ?? null;
    if (is_array($dl) && hash_equals((string)$dl['token'], (string)$_GET['download']) && is_file($dl['file'])) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($dl['file']) . '"');
        header('Content-Length: ' . filesize($dl['file']));
        header('X-Content-Type-Options: nosniff');
        readfile($dl['file']);
        @unlink($dl['file']);
        unset($_SESSION['upd_backup_dl']);
        exit;
    }
    unset($_SESSION['upd_backup_dl']); // jeton invalide/expiré
}

$modules = upd_list_modules();
$dir     = upd_valid_module($_REQUEST['module'] ?? '');
$name    = $dir ? basename($dir) : '';
$tables  = $dir ? upd_module_tables($dir) : [];
$warning = $dir ? upd_uninstall_warning($dir) : '';
$wantBackup = $dir ? upd_uninstall_backup($dir) : false;

$messages      = [];
$done          = false;
$downloadToken = null;
$droppedTables = [];

if (empty($_SESSION['upd_uninstall_token'])) {
    $_SESSION['upd_uninstall_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['upd_uninstall_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'uninstall') {
    $confirm = trim((string)($_POST['confirm'] ?? ''));
    $dropDb  = !empty($_POST['drop_tables']);

    if (!$dir) {
        $messages[] = ['err', 'Module introuvable ou non géré par ce système.'];
    } elseif (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $messages[] = ['err', 'Jeton de sécurité invalide. Rechargez la page et recommencez.'];
    } elseif ($confirm !== $name) {
        $messages[] = ['err', 'Le nom saisi ne correspond pas à « ' . $name .' ». Rien n\'a été supprimé.'];
    } else {
        upd_purge_old_backups();                 // pas d'accumulation d'archives résiduelles

        $backupPath = null;
        $backupErr  = null;
        if ($wantBackup) {                       // seuls les modules sensibles gardent un filet
            $backup = upd_backup_module($dir, $name);
            if (isset($backup['_error'])) $backupErr = $backup['_error'];
            else                          $backupPath = $backup['file'];
        }

        if ($backupErr !== null) {
            $messages[] = ['err', 'Sauvegarde impossible (' . $backupErr . '). Désinstallation annulée.'];
        } else {
            if ($dropDb && $tables) $droppedTables = upd_drop_tables($tables);
            if (upd_rrmdir($dir)) {
                $done = true;
                unset($_SESSION['upd_uninstall_token']);
                if ($backupPath) {               // sauvegarde à usage unique : lien de téléchargement
                    $downloadToken = bin2hex(random_bytes(16));
                    $_SESSION['upd_backup_dl'] = ['file' => $backupPath, 'token' => $downloadToken];
                }
            } else {
                $messages[] = ['err', 'Suppression des fichiers impossible (droits insuffisants sur le dossier ?).'];
                if ($backupPath && is_file($backupPath)) @unlink($backupPath); // ne rien laisser traîner
            }
        }
    }
}

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.uns-section { max-width: 760px; margin-bottom: 28px; }
.uns-msg { padding: 8px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
.uns-msg-ok  { background: #e8faf0; border-left: 3px solid #1a8a4a; color: #1a5a33; }
.uns-msg-err { background: #fde8e8; border-left: 3px solid #c0392b; color: #8a1a1a; }
.uns-box { border: 1px solid #e8b4ae; background: #fdf6f5; border-radius: 8px; padding: 18px 20px; }
.uns-box h2 { color: #c0392b; font-size: 16px; margin: 0 0 12px; }
.uns-list { font-size: 13px; color: #444; line-height: 1.9; margin: 0 0 14px; padding-left: 18px; }
.uns-list code { background: #fff; border: 1px solid #eadad8; border-radius: 3px; padding: 1px 5px; }
.uns-danger { background: #fff4e5; border-left: 3px solid #f5a623; padding: 10px 14px; border-radius: 0 6px 6px 0; font-size: 13px; margin: 14px 0; }
.uns-confirm { margin: 16px 0 8px; font-size: 13px; }
.uns-confirm input[type=text] { padding: 7px 10px; border: 1px solid #c8b4b0; border-radius: 5px; font-size: 13px; width: 220px; }
.uns-btn { padding: 9px 22px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; }
.uns-btn-danger { background: #c0392b; color: #fff; }
.uns-btn-cancel { background: #f0f0f4; color: #444; border: 1px solid #ccc; text-decoration: none; display: inline-block; }
.uns-hint { font-size: 12px; color: #888; margin-top: 10px; }
.uns-warning { background: #fdecea; border: 1px solid #c0392b; border-left-width: 4px; border-radius: 0 6px 6px 0; padding: 12px 14px; margin: 16px 0; font-size: 13px; color: #8a1a1a; line-height: 1.6; }
.uns-path { font-family: monospace; font-size: 12px; background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 4px; padding: 6px 10px; display: inline-block; word-break: break-all; }
</style>

<h1>Désinstaller un module</h1>

<?php foreach ($messages as [$type, $text]): ?>
  <div class="uns-msg <?= $type === 'ok' ? 'uns-msg-ok' : 'uns-msg-err' ?>"><?= htmlspecialchars($text) ?></div>
<?php endforeach; ?>

<?php if ($done): ?>

  <div class="uns-section">
    <div class="uns-msg uns-msg-ok">
      Le module <b><?= htmlspecialchars($name) ?></b> a été désinstallé.
    </div>
    <?php if ($downloadToken): ?>
      <p style="font-size:13px">Une sauvegarde des fichiers a été préparée. Téléchargez-la maintenant —
        <b>elle est supprimée du serveur dès le téléchargement</b> :</p>
      <p style="margin:10px 0">
        <a class="uns-btn" style="background:#0254a8;color:#fff;text-decoration:none;display:inline-block"
           href="?download=<?= urlencode($downloadToken) ?>">&#11015; Télécharger la sauvegarde (.zip)</a>
      </p>
      <p class="uns-hint" style="margin-top:0">Si vous ne la téléchargez pas, elle sera purgée automatiquement du dossier temporaire.</p>
    <?php else: ?>
      <p style="font-size:13px">Aucune sauvegarde conservée : les fichiers restent récupérables depuis le
        dépôt GitHub, et une réinstallation les restaure.</p>
    <?php endif; ?>
    <?php if ($droppedTables): ?>
      <p style="font-size:13px;margin-top:14px">Tables supprimées : <b><?= htmlspecialchars(implode(', ', $droppedTables)) ?></b></p>
    <?php elseif ($tables): ?>
      <p style="font-size:13px;margin-top:14px">
        Les tables <b><?= htmlspecialchars(implode(', ', $tables)) ?></b> ont été <b>conservées</b> :
        une réinstallation retrouvera les données.
      </p>
    <?php endif; ?>
    <p style="margin-top:20px"><a href="<?= $CFG->ROOT_DIR ?>index.php">← Retour à l'accueil ianseo</a></p>
  </div>

<?php elseif (!$dir): ?>

  <div class="uns-section">
    <?php if ($modules): ?>
      <p style="font-size:13px">Choisissez le module à désinstaller :</p>
      <ul class="uns-list">
        <?php foreach ($modules as $m): ?>
          <li><a href="?module=<?= urlencode($m) ?>"><?= htmlspecialchars($m) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <p class="uns-hint">Seuls les dossiers contenant un <code>module.json</code> sont listés.</p>
    <?php else: ?>
      <p style="font-size:13px">Aucun module géré par ce système n'est installé.</p>
    <?php endif; ?>
    <p style="margin-top:20px"><a href="<?= $CFG->ROOT_DIR ?>index.php">← Retour à l'accueil ianseo</a></p>
  </div>

<?php else: ?>

  <div class="uns-section">
    <div class="uns-box">
      <h2>&#9888; Désinstaller le module « <?= htmlspecialchars($name) ?> »</h2>

      <p style="font-size:13px;margin:0 0 10px">Cette action va :</p>
      <ul class="uns-list">
        <?php if ($wantBackup): ?>
          <li>préparer une <b>sauvegarde téléchargeable</b> des fichiers (proposée juste après, puis supprimée du serveur) ;</li>
        <?php endif; ?>
        <li>supprimer définitivement le dossier <code>Modules/Custom/<?= htmlspecialchars($name) ?>/</code><?php if (!$wantBackup): ?> (fichiers récupérables depuis le dépôt GitHub)<?php endif; ?>.</li>
      </ul>

      <p class="uns-hint" style="margin-top:-6px">
        La bibliothèque commune <code>_shared/</code> n'est jamais supprimée : d'autres modules l'utilisent.
      </p>

      <?php if ($warning): ?>
        <div class="uns-warning">
          <b>&#9888; Avertissement de ce module — à lire avant de continuer</b>
          <div style="margin-top:6px"><?= nl2br(htmlspecialchars($warning)) ?></div>
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="action" value="uninstall">
        <input type="hidden" name="module" value="<?= htmlspecialchars($name) ?>">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">

        <?php if ($tables): ?>
          <div class="uns-danger">
            <label style="cursor:pointer">
              <input type="checkbox" name="drop_tables" value="1">
              Supprimer aussi les <b>données en base</b> :
              <code><?= htmlspecialchars(implode('</code>, <code>', $tables)) ?></code>
            </label>
            <div style="margin-top:6px;color:#8a5a00">
              Décoché, les données sont conservées et une réinstallation les retrouve.
              <b>Coché, la suppression est irréversible.</b>
            </div>
          </div>
        <?php endif; ?>

        <div class="uns-confirm">
          Pour confirmer, tapez le nom du module (<b><?= htmlspecialchars($name) ?></b>) :<br>
          <input type="text" name="confirm" autocomplete="off" required
                 placeholder="<?= htmlspecialchars($name) ?>" style="margin-top:6px">
        </div>

        <p style="margin-top:16px">
          <button type="submit" class="uns-btn uns-btn-danger"
                  onclick="return confirm('Dernière confirmation : désinstaller <?= htmlspecialchars($name, ENT_QUOTES) ?> ?')">
            &#128465; Désinstaller définitivement
          </button>
          <a class="uns-btn uns-btn-cancel" style="margin-left:8px"
             href="<?= $CFG->ROOT_DIR ?>index.php">Annuler</a>
        </p>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
