<?php
/**
 * admin/legal.php — informations légales de l'exploitant du serveur (ADMIN uniquement).
 *
 * L'exploitant saisit ici son identité, son hébergeur, ses contacts, etc. Le module
 * GÉNÈRE alors des mentions légales / CGU / politique de confidentialité / cookies
 * complètes (page publique legal.php). Chaque texte peut être SURCHARGÉ (zone libre) ;
 * laissé vide, le texte généré s'applique. Stockage : legal.local.json (non versionné).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/legal-lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

$fields = aut_legal_fields();
$docs   = aut_legal_docs();
$ok = ''; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $err = 'Session expirée — réessayez.';
    } else {
        $conf = aut_legal_conf();
        $op = array();
        foreach (array_keys($fields) as $k) $op[$k] = trim((string) ($_POST['op'][$k] ?? ''));
        $conf['operator'] = $op;
        $custom = array();
        foreach (array_keys($docs) as $k) $custom[$k] = trim((string) ($_POST['custom'][$k] ?? ''));
        $conf['custom'] = $custom;
        $ver = trim((string) ($_POST['version'] ?? '1'));
        $conf['version'] = ($ver !== '') ? substr($ver, 0, 16) : '1';
        if (aut_legal_save($conf)) {
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/legal.php?ok=1'); die();
        }
        $err = "Écriture impossible (droits sur le fichier legal.local.json ?).";
    }
}
if (isset($_GET['ok'])) $ok = 'Informations légales enregistrées.';

$conf = aut_legal_conf();
$op   = aut_legal_operator();
$statuses = array('' => '—', 'particulier' => 'Particulier', 'association' => 'Association',
    'société' => 'Société', 'structure publique' => 'Structure publique / fédérale');

// Suivi des acceptations de CGU (version courante). Les archers ne sont comptés que si la
// table booking existe (module d'inscriptions installé).
aut_legal_ensure_schema();
$curVer = aut_legal_version();
$cnt = function ($sql) { $r = safe_fetch(safe_r_sql($sql)); return $r ? intval($r->n) : 0; };
$orgOk  = $cnt("SELECT COUNT(*) n FROM AUT_Users WHERE AuCguVer = " . StrSafe_DB($curVer));
$orgTot = $cnt("SELECT COUNT(*) n FROM AUT_Users");
$hasArchers = $cnt("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BK_Archers'") > 0;
$arcOk = $arcTot = 0;
if ($hasArchers) {
    $arcOk  = $cnt("SELECT COUNT(*) n FROM BK_Archers WHERE BaCguVer = " . StrSafe_DB($curVer));
    $arcTot = $cnt("SELECT COUNT(*) n FROM BK_Archers");
}

$PAGE_TITLE = 'Mentions légales & CGU';
include('Common/Templates/head.php');
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
?>
<style>
#aut-lg { max-width:820px; }
#aut-lg h1 { font-size:22px; color:#01367c; margin:0 0 4px; }
#aut-lg .lead { color:#4c4e50; font-size:14px; margin:0 0 16px; max-width:680px; }
#aut-lg .sec { background:#fff; border:1px solid #d2d4d6; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08);
    padding:16px 18px; margin:0 0 14px; }
#aut-lg .sec h2 { font-size:15px; color:#0254a8; margin:0 0 10px; }
#aut-lg label { display:block; font-weight:600; font-size:13px; color:#01367c; margin:12px 0 3px; }
#aut-lg .help { font-weight:400; color:#7d8183; font-size:12px; }
#aut-lg input[type=text], #aut-lg textarea, #aut-lg select { width:100%; max-width:560px; padding:8px 10px;
    border:1px solid #cfd3d6; border-radius:6px; font:inherit; font-size:14px; }
#aut-lg textarea { min-height:70px; resize:vertical; max-width:100%; }
#aut-lg .msg { padding:10px 13px; border-radius:6px; margin:0 0 14px; font-size:14px; }
#aut-lg .ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-lg .err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#aut-lg .btn { padding:9px 18px; border:1px solid #0254a8; border-radius:6px; background:#0254a8;
    color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
#aut-lg .btn:hover { background:#01367c; }
#aut-lg .btn-2 { background:#f7f7f7; color:#20263d; border-color:#d2d4d6; text-decoration:none; display:inline-block; }
#aut-lg .prev { display:flex; flex-wrap:wrap; gap:8px; margin:6px 0 0; }
#aut-lg details.adv > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:4px 0; }
#aut-lg .warn { background:#fdf0ef; border:1px solid #e8b4ae; color:#8b1a1a; border-radius:6px; padding:10px 13px; font-size:13px; margin:0 0 14px; }
</style>

<div id="aut-lg">
<h1>Mentions légales &amp; CGU du serveur</h1>
<p class="lead">Renseignez les informations de <b>l'exploitant de ce serveur</b>. Le service génère alors
   automatiquement des <b>mentions légales, CGU, politique de confidentialité et page cookies</b> complètes,
   affichées aux utilisateurs. Vous restez responsable de l'exactitude de ces informations — au besoin,
   faites-les relire (juriste, DPO).</p>

<?php if ($ok): ?><div class="msg ok"><?= $e($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg err"><?= $e($err) ?></div><?php endif; ?>

<?php if (!aut_legal_configured()): ?>
  <div class="warn">⚠️ Tant que <b>le nom de l'exploitant et un e-mail de contact</b> ne sont pas renseignés,
     les pages légales sont incomplètes, un bandeau d'avertissement s'affiche <b>et l'acceptation des CGU n'est
     pas encore demandée</b> aux utilisateurs (elle s'activera une fois ces informations saisies).</div>
<?php endif; ?>

<div class="sec">
  <h2>Suivi des acceptations — CGU version <?= $e($curVer) ?></h2>
  <p style="margin:0 0 6px; font-size:14px">
    <b>Organisateurs</b> : <?= intval($orgOk) ?> / <?= intval($orgTot) ?> ont accepté la version courante.
    <?php if ($hasArchers): ?><br><b>Archers</b> : <?= intval($arcOk) ?> / <?= intval($arcTot) ?> ont accepté la version courante.<?php endif; ?>
  </p>
  <p class="help" style="margin:0">Détail par organisateur (date/heure + version) dans
    <a href="<?= $e($CFG->ROOT_DIR) ?>Modules/Custom/AUTH/admin/">Multi-comptes › Utilisateurs</a>, colonne « CGU ».
    Chaque acceptation est aussi tracée (événement <code>CGU_ACCEPT</code>) dans le journal, avec sa date et son heure.</p>
</div>

<form method="post">
  <?= aut_csrf_field() ?>

  <div class="sec">
    <h2>Exploitant du serveur</h2>
    <?php foreach ($fields as $k => $f): ?>
      <label for="op_<?= $e($k) ?>"><?= $e($f[0]) ?><?php if ($f[1]): ?> <span class="help"><?= $e($f[1]) ?></span><?php endif; ?></label>
      <?php if ($k === 'status'): ?>
        <select id="op_<?= $e($k) ?>" name="op[<?= $e($k) ?>]">
          <?php foreach ($statuses as $sv => $sl): ?>
            <option value="<?= $e($sv) ?>" <?= $op[$k] === $sv ? 'selected' : '' ?>><?= $e($sl) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($k === 'address' || $k === 'host_address'): ?>
        <textarea id="op_<?= $e($k) ?>" name="op[<?= $e($k) ?>]" rows="2"><?= $e($op[$k]) ?></textarea>
      <?php else: ?>
        <input type="text" id="op_<?= $e($k) ?>" name="op[<?= $e($k) ?>]" value="<?= $e($op[$k]) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="sec">
    <h2>Version des CGU</h2>
    <label for="version">Numéro de version <span class="help">Changez-le pour redemander l'acceptation à tous les utilisateurs (ex. après une modification des CGU).</span></label>
    <input type="text" id="version" name="version" maxlength="16" style="max-width:160px" value="<?= $e(aut_legal_version()) ?>">
  </div>

  <div class="sec">
    <h2>Textes générés</h2>
    <p class="help" style="margin:0 0 8px">Les quatre documents sont générés à partir des informations ci-dessus.
       Prévisualisez-les :</p>
    <div class="prev">
      <?php foreach ($docs as $k => $d): ?>
        <a class="btn btn-2" href="<?= $e($CFG->ROOT_DIR) ?>Modules/Custom/AUTH/legal.php?doc=<?= $e($d[1]) ?>" target="_blank" rel="noopener"><?= $e($d[0]) ?> ↗</a>
      <?php endforeach; ?>
    </div>

    <details class="adv" style="margin-top:14px">
      <summary>Surcharger un texte (avancé)</summary>
      <p class="help" style="margin:6px 0">Laissez vide pour utiliser le texte généré. Un texte saisi ici
         <b>remplace entièrement</b> le document correspondant (texte simple ; les lignes vides séparent les paragraphes).</p>
      <?php foreach ($docs as $k => $d): ?>
        <label for="custom_<?= $e($k) ?>"><?= $e($d[0]) ?></label>
        <textarea id="custom_<?= $e($k) ?>" name="custom[<?= $e($k) ?>]" rows="4" placeholder="(vide = texte généré automatiquement)"><?= $e($conf['custom'][$k] ?? '') ?></textarea>
      <?php endforeach; ?>
    </details>
  </div>

  <p><button type="submit" class="btn">Enregistrer</button></p>
</form>
</div>

<?php include('Common/Templates/tail.php'); ?>
