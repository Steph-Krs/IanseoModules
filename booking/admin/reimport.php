<?php
/**
 * admin/reimport.php — réconciliation après un réimport de compétition.
 *
 * lib/adopt.php rapatrie automatiquement config, paiements, boutique et inscriptions,
 * puis enregistre CHAQUE écart comme un « conflit » que l'organisateur tranche ici :
 *   - category    : même archer/même départ, catégorie différente entre booking et l'import ;
 *   - onlybooking : inscrit en ligne dans booking mais absent de l'import (ré-injecté par défaut) ;
 *   - onlyimport  : participant de l'import non inscrit via booking (rendu visible par défaut) ;
 *   - reinject    : inscription booking non ré-injectable (licence inconnue, départ disparu).
 *
 * Chaque conflit se tranche d'un CÔTÉ (import ou booking). Sémantique dans
 * bk_reimport_apply(). Deux boutons globaux tranchent tout d'un même côté.
 * ⚠️ « Côté booking » d'un onlyimport = RETRAIT du participant de la compétition (Entry supprimée).
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/adopt.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();
$TOUR = intval($_SESSION['TourId']);
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — rechargez la page et réessayez.';
    } else {
        $action = (string) ($_POST['do'] ?? '');
        if ($action === 'bulk') {
            $side = ((string) ($_POST['side'] ?? '') === 'booking') ? 'booking' : 'import';
            $r = bk_reimport_bulk($TOUR, $side);
            $msg = 'Tout tranché « ' . ($side === 'booking' ? 'côté booking' : 'côté import') . ' » : '
                . intval($r['done']) . ' élément(s) traité(s)'
                . ($r['removed'] ? ', ' . intval($r['removed']) . ' participant(s) retiré(s)' : '')
                . ($r['fail'] ? ', ' . intval($r['fail']) . ' échec(s)' : '') . '.';
        } else {
            $rcId = intval($_POST['rc'] ?? 0);
            $rc = $rcId ? safe_fetch(safe_r_sql("SELECT * FROM BK_ReimportConflicts
                WHERE RcId = $rcId AND RcTournament = $TOUR")) : null;
            $side = ((string) ($_POST['side'] ?? '') === 'booking') ? 'booking' : 'import';
            if (!$rc) {
                $err = 'Élément introuvable (déjà traité ?).';
            } else {
                $r = bk_reimport_apply($TOUR, $rc, $side);
                if (!empty($r['ok'])) $msg = 'Décision appliquée.';
                else $err = 'Action impossible : ' . ($r['msg'] ?? '');
            }
        }
    }
}

$conflicts = bk_reimport_conflicts($TOUR);
$by = array('category' => array(), 'onlybooking' => array(), 'onlyimport' => array(), 'reinject' => array());
foreach ($conflicts as $c) { if (isset($by[$c->RcKind])) $by[$c->RcKind][] = $c; }

$backUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';
$self    = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/reimport.php';

$catLabel = function ($j) {
    $a = is_array($j) ? $j : (array) json_decode((string) $j, true);
    $d = trim((string) ($a['division'] ?? ''));
    $c = trim((string) ($a['class'] ?? ''));
    return ($d || $c) ? bk_e(trim($d . ' ' . $c)) : '—';
};
// Formulaire d'un bouton par ligne (rc + côté + libellé + classe + confirmation).
$btn = function ($rcId, $side, $label, $class, $confirm = '') {
    $oc = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ')"' : '';
    ob_start(); ?>
    <form method="post" class="bk-inline"<?= $oc ?>><?= bk_csrf_field() ?>
      <input type="hidden" name="do" value="apply">
      <input type="hidden" name="rc" value="<?= intval($rcId) ?>">
      <input type="hidden" name="side" value="<?= bk_e($side) ?>">
      <button class="<?= bk_e($class) ?>"><?= bk_e($label) ?></button>
    </form>
    <?php return ob_get_clean();
};

$PAGE_TITLE = 'Réimport — réconciliation';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm { max-width: 100%; }
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 6px; font-size:15px; color:#0254a8; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm .bk-hint { margin:2px 0 10px; font-size:12px; color:#7d8183; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; width:100%; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:6px 10px; text-align:left; vertical-align:middle; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-btn { padding:7px 13px; border:1px solid #0254a8; border-radius:6px;
    background:#0254a8; color:#fff; font-size:13px; font-weight:600; cursor:pointer; }
#bkadm .bk-btn:hover { background:#01367c; border-color:#01367c; }
#bkadm .bk-btn2 { padding:7px 13px; border:1px solid #d2d4d6; border-radius:6px;
    background:#fff; color:#334; font-size:13px; cursor:pointer; }
#bkadm .bk-btn2:hover { background:#f0f4ff; border-color:#0254a8; }
#bkadm .bk-btn-danger { border-color:#c0392b; color:#c0392b; background:#fff; }
#bkadm .bk-btn-danger:hover { background:#fdf0ef; }
#bkadm form.bk-inline { display:inline; margin:0; }
#bkadm .bk-empty { color:#5b6470; font-size:13px; }
#bkadm a.bk-back { font-size:13px; color:#0254a8; text-decoration:none; }
#bkadm .bk-bulk { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:8px; }
#bkadm details.bk-fold > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:4px 0; }
</style>

<div id="bkadm">
<h1>Réimport de la compétition — réconciliation</h1>
<p><a class="bk-back" href="<?= bk_e($backUrl) ?>">← Retour aux inscriptions en ligne</a></p>

<?php if ($msg): ?><div class="bk-msg bk-ok"><?= bk_e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="bk-msg bk-err"><?= bk_e($err) ?></div><?php endif; ?>

<?php if (!$conflicts): ?>
  <div class="bk-sec"><p class="bk-empty">Rien à trancher : tout a été rapatrié et validé.</p></div>
<?php else: ?>

<div class="bk-sec">
  <h2>Trancher tout d'un coup</h2>
  <p class="bk-hint"><?= count($conflicts) ?> élément(s) à valider. Vous pouvez décider ligne par
    ligne ci-dessous, ou tout trancher d'un côté :</p>
  <div class="bk-bulk">
    <form method="post" class="bk-inline"
      onsubmit="return confirm('Tout garder de l\'IMPORT ?\n\nLes catégories de l\'import sont conservées, les participants de l\'import restent, et les inscriptions en ligne ABSENTES de l\'import sont RETIRÉES.')">
      <?= bk_csrf_field() ?>
      <input type="hidden" name="do" value="bulk">
      <input type="hidden" name="side" value="import">
      <button class="bk-btn">Tout garder de l'import</button>
    </form>
    <form method="post" class="bk-inline"
      onsubmit="return confirm('Tout garder de BOOKING ?\n\nLes catégories du licencié sont appliquées, les inscriptions en ligne sont conservées, et les participants présents SEULEMENT dans l\'import (saisis hors module) sont RETIRÉS DE LA COMPÉTITION (<?= count($by['onlyimport']) ?> participant·s). Action irréversible.')">
      <?= bk_csrf_field() ?>
      <input type="hidden" name="do" value="bulk">
      <input type="hidden" name="side" value="booking">
      <button class="bk-btn2 bk-btn-danger">Tout garder de booking</button>
    </form>
  </div>
</div>

<?php if ($by['category']): ?>
<div class="bk-sec">
  <h2>Catégories divergentes (<?= count($by['category']) ?>)</h2>
  <p class="bk-hint">Même archer, même départ, mais catégorie différente entre l'inscription
    booking et l'import. Le placement de l'import est conservé ; choisissez la catégorie.</p>
  <table class="bk-t">
    <tr><th>Archer</th><th>Licence</th><th>Booking</th><th>Import (en place)</th><th>Choix</th></tr>
    <?php foreach ($by['category'] as $c): ?>
    <tr>
      <td><?= bk_e($c->RcName ?: '—') ?></td>
      <td><?= bk_e($c->RcLicence) ?></td>
      <td><?= $catLabel($c->RcBooking) ?></td>
      <td><b><?= $catLabel($c->RcImport) ?></b></td>
      <td>
        <?= $btn($c->RcId, 'import', 'Garder l\'import', 'bk-btn') ?>
        <?= $btn($c->RcId, 'booking', 'Utiliser booking', 'bk-btn2', 'Remplacer la catégorie de l\'import par celle de l\'inscription booking ?') ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($by['onlybooking']): ?>
<div class="bk-sec">
  <h2>Inscrits en ligne absents de l'import (<?= count($by['onlybooking']) ?>)</h2>
  <p class="bk-hint">Ces archers s'étaient inscrits en ligne mais ne figurent pas dans le nouvel
    import (export pris avant leur inscription). Ils ont été ré-injectés par défaut. Gardez-les,
    ou retirez-les si l'import fait foi.</p>
  <table class="bk-t">
    <tr><th>Archer</th><th>Licence</th><th>Catégorie</th><th>Choix</th></tr>
    <?php foreach ($by['onlybooking'] as $c): ?>
    <tr>
      <td><?= bk_e($c->RcName ?: '—') ?></td>
      <td><?= bk_e($c->RcLicence) ?></td>
      <td><?= $catLabel($c->RcBooking) ?></td>
      <td>
        <?= $btn($c->RcId, 'booking', 'Garder l\'inscription', 'bk-btn') ?>
        <?= $btn($c->RcId, 'import', 'Retirer', 'bk-btn2 bk-btn-danger', 'Retirer cette inscription de la compétition ?') ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($by['onlyimport']):
    $oi = $by['onlyimport']; $cap = 200; $shown = array_slice($oi, 0, $cap); ?>
<div class="bk-sec">
  <h2>Participants de l'import non inscrits via booking (<?= count($oi) ?>)</h2>
  <p class="bk-hint">Ces participants ont été saisis hors module (directement dans ianseo). Ils
    sont désormais visibles dans leur espace licencié, <b>sans information de paiement</b>. Gardez-les,
    ou retirez-les de la compétition si seules les inscriptions en ligne doivent y figurer.</p>
  <details class="bk-fold"<?= count($oi) <= 30 ? ' open' : '' ?>>
    <summary>Voir la liste (<?= count($oi) ?>)</summary>
    <table class="bk-t">
      <tr><th>Archer</th><th>Licence</th><th>Catégorie</th><th>Choix</th></tr>
      <?php foreach ($shown as $c): ?>
      <tr>
        <td><?= bk_e($c->RcName ?: '—') ?></td>
        <td><?= bk_e($c->RcLicence) ?></td>
        <td><?= $catLabel($c->RcImport) ?></td>
        <td>
          <?= $btn($c->RcId, 'import', 'Garder', 'bk-btn') ?>
          <?= $btn($c->RcId, 'booking', 'Retirer', 'bk-btn2 bk-btn-danger', 'Retirer ce participant de la compétition (inscription ianseo supprimée) ?') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($oi) > $cap): ?>
      <p class="bk-hint">… et <?= count($oi) - $cap ?> autre(s). Utilisez les boutons globaux
        ci-dessus pour tout trancher d'un coup.</p>
    <?php endif; ?>
  </details>
</div>
<?php endif; ?>

<?php if ($by['reinject']): ?>
<div class="bk-sec">
  <h2>Inscriptions non ré-injectées (<?= count($by['reinject']) ?>)</h2>
  <p class="bk-hint">Ces inscriptions booking étaient absentes de l'import et n'ont pas pu être
    recréées (licence inconnue du fichier fédéral, départ disparu…). Réessayez après correction,
    ou abandonnez la trace.</p>
  <table class="bk-t">
    <tr><th>Licence</th><th>Catégorie</th><th>Motif</th><th>Choix</th></tr>
    <?php foreach ($by['reinject'] as $c):
      $b = (array) json_decode((string) $c->RcBooking, true); ?>
    <tr>
      <td><?= bk_e($c->RcLicence) ?></td>
      <td><?= $catLabel($c->RcBooking) ?></td>
      <td><?= bk_e((string) ($b['msg'] ?? '')) ?></td>
      <td>
        <?= $btn($c->RcId, 'booking', 'Réessayer', 'bk-btn') ?>
        <?= $btn($c->RcId, 'import', 'Abandonner', 'bk-btn2 bk-btn-danger', 'Abandonner définitivement cette trace ?') ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
