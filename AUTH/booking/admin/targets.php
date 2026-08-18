<?php
/**
 * admin/targets.php — attribution des cibles et contrôle du règlement.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pTarget', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/targets.php';
require_once dirname(__DIR__) . '/lib/archer.php';
require_once dirname(__DIR__) . '/lib/ui.php';

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$cfg  = bk_comp_config($TOUR);
$msg = ''; $err = '';

/** Compte rendu d'une attribution, en distinguant les causes de non-placement. */
function bk_assign_msg($prefix, $r)
{
    $m = $prefix . $r['places'] . ' archer(s) placé(s)';
    $libres = intval($r['restants']) - intval($r['incompatibles']);
    if ($libres > 0)             $m .= ", $libres sans place (départ complet)";
    if ($r['incompatibles'] > 0) $m .= ", {$r['incompatibles']} qu'aucune cible ne peut recevoir "
                                     . "(distance ou blason incompatible avec le plan du terrain)";
    if ($r['compromis'] > 0)     $m .= ", dont {$r['compromis']} au-delà du quota par club faute de place";
    $m .= '.';
    if (!empty($r['voeux'])) {
        $m .= ' Souhaits des archers : ' . intval($r['voeuxOk']) . ' sur ' . intval($r['voeux'])
            . ' satisfait' . ($r['voeuxOk'] > 1 ? 's' : '') . '.';
    }
    return $m;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — rechargez la page et réessayez.';
    } elseif (IsBlocked(BIT_BLOCK_PARTICIPANT)) {
        $err = 'Les participants de cette compétition sont verrouillés.';
    } else {
        $act = $_POST['action'] ?? '';
        $ses = intval($_POST['session'] ?? 0);
        if ($act === 'assign') {
            // « Réattribuer » = LIBÉRER les placements du module puis réattribuer :
            // sinon un archer déjà placé ne bouge pas, même si le plan de cibles a
            // changé (bk_assign_session ne déplace jamais un archer placé).
            $r = bk_replan_session($TOUR, $ses, $cfg);
            $msg = bk_assign_msg("Départ $ses : ", $r);
        } elseif ($act === 'assign_all') {
            $r = bk_replan_all($TOUR, $cfg);
            $msg = bk_assign_msg('', $r);
        } elseif ($act === 'clear') {
            $n = bk_clear_session($TOUR, $ses);
            $msg = "Départ $ses : $n place(s) libérée(s).";
        } elseif ($act === 'validate') {
            if (bk_validate_registration($TOUR, intval($_POST['enid'] ?? 0), $cfg)) {
                $msg = 'Inscription validée : sa cible a été attribuée.';
            } else {
                $err = "Cette inscription n'est plus en attente de validation.";
            }
        } elseif ($act === 'validate_all') {
            $n = bk_validate_all($TOUR, $cfg);
            $msg = "$n inscription(s) validée(s) et placée(s).";
        }
    }
}

$sessions = bk_comp_sessions($TOUR);
$pending  = bk_pending_registrations($TOUR);
$controle = bk_rules_check($TOUR, $cfg);
$voir     = intval($_GET['plan'] ?? 0);
$plan     = $voir ? bk_session_plan($TOUR, $voir) : array();

$PAGE_TITLE = 'Attribution des cibles';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; margin-bottom:6px; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:5px 10px; text-align:left; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-btn { padding:7px 14px; border:1px solid #d2d4d6; border-radius:6px;
    background:#f7f7f7; color:#20263d; font-size:13px; cursor:pointer; }
#bkadm .bk-btn-primary { background:#0254a8; border-color:#0254a8; color:#fff; font-weight:600; }
#bkadm .bk-btn-primary:hover { background:#01367c; }
#bkadm .bk-pill { display:inline-block; padding:1px 8px; border-radius:5px; font-size:12px; }
#bkadm .bk-pill-ok { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-pill-ko { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm .bk-pill-warn { background:#fdf0e6; border:1px solid #cb8137; color:#cb8137; }
#bkadm .bk-hint { font-size:12px; color:#7d8183; margin:6px 0 0; }
#bkadm .bk-plan td { font-size:12px; }
#bkadm .bk-plan .bk-empty-cell { color:#c9ccce; }
#bkadm .bk-viol { margin:4px 0 0; padding-left:18px; font-size:12px; color:#a80000; }
</style>

<div id="bkadm">
<h1>Attribution des cibles</h1>
<p style="font-size:13px"><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/competition.php">← Inscriptions en ligne</a>
   &nbsp;·&nbsp; <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/field.php">Plan du terrain</a></p>

<?php if ($msg): ?><div class="bk-msg bk-ok"><?= bk_e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="bk-msg bk-err"><?= bk_e($err) ?></div><?php endif; ?>

<?php if ($pending): ?>
<div class="bk-sec" style="border-color:#cb8137">
  <h2 style="color:#cb8137">Inscriptions en attente de validation
      <span class="bk-pill bk-pill-warn"><?= count($pending) ?></span></h2>
  <p class="bk-hint" style="margin-top:0">La validation manuelle est activée : ces inscriptions
     ne seront placées sur une cible qu'une fois validées ci-dessous.</p>
  <table class="bk-t">
    <tr><th>Archer</th><th>Licence</th><th>Catégorie</th><th>Club</th><th>Départ</th><th></th></tr>
    <?php foreach ($pending as $p): ?>
    <tr>
      <td><?= bk_e(trim($p->EnFirstName . ' ' . $p->EnName)) ?></td>
      <td><?= bk_e($p->EnCode) ?></td>
      <td><?= bk_e(trim(($p->DivDescription ?: $p->EnDivision) . ' ' . ($p->ClDescription ?: $p->EnClass))) ?></td>
      <td><?= bk_e($p->CoName ?: $p->CoCode) ?></td>
      <td><?= $p->QuSession ? 'Départ ' . intval($p->QuSession) : '—' ?></td>
      <td>
        <form method="post" style="margin:0">
          <?= bk_csrf_field() ?>
          <input type="hidden" name="action" value="validate">
          <input type="hidden" name="enid" value="<?= intval($p->BrEnId) ?>">
          <button type="submit" class="bk-btn bk-btn-primary">Valider</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <form method="post" style="margin:6px 0 0" onsubmit="return confirm('Valider toutes les inscriptions en attente ?');">
    <?= bk_csrf_field() ?>
    <input type="hidden" name="action" value="validate_all">
    <button type="submit" class="bk-btn">Tout valider (<?= count($pending) ?>)</button>
  </form>
</div>
<?php endif; ?>

<div class="bk-sec">
  <h2>Départs</h2>
  <?php if (!$sessions): ?>
    <p class="bk-hint">Aucun départ de qualification configuré.</p>
  <?php else: ?>
  <table class="bk-t">
    <tr><th>Départ</th><th>Places</th><th>Inscrits</th><th>Placés</th><th>Actions</th></tr>
    <?php foreach ($sessions as $s):
      $o = intval($s->SesOrder);
      $c = null;
      foreach ($controle as $x) if ($x['depart'] === $o) $c = $x;
      $inscrits = $c ? $c['archers'] : 0;
      $places   = $c ? $inscrits - $c['nonPlaces'] : 0; ?>
      <tr>
        <td><?= $o ?><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?></td>
        <td><?= intval($s->Places) ?></td>
        <td><?= $inscrits ?></td>
        <td><?= $places ?> / <?= $inscrits ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= bk_csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="session" value="<?= $o ?>">
            <button type="submit" class="bk-btn bk-btn-primary">Réattribuer</button>
          </form>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Supprimer les attributions de cibles du départ <?= $o ?> ?')">
            <?= bk_csrf_field() ?>
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="session" value="<?= $o ?>">
            <button type="submit" class="bk-btn">Supprimer les attributions de cibles</button>
          </form>
          <a class="bk-btn" style="text-decoration:none;display:inline-block"
             href="?plan=<?= $o ?>">Voir le plan</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <form method="post" style="margin-top:8px"
        onsubmit="return confirm('Réattribuer les cibles sur tous les départs ?')">
    <?= bk_csrf_field() ?>
    <input type="hidden" name="action" value="assign_all">
    <button type="submit" class="bk-btn bk-btn-primary">Réattribuer sur tous les départs</button>
  </form>
  <?php
  // Bouton vers PlanQualifs (plan de cible imprimable), s'il est installé.
  // ⚠️ Ce module doit être renommé/déplacé à l'avenir : garder le chemin isolé
  //    ici (une seule ligne à changer le jour venu).
  $bkPlanQualifsDir  = 'Modules/Custom/PlanQualifs/';
  if (is_dir($CFG->DOCUMENT_PATH . $bkPlanQualifsDir)): ?>
    <a class="bk-btn" style="text-decoration:none;display:inline-block;margin-top:8px"
       href="<?= bk_e($CFG->ROOT_DIR . $bkPlanQualifsDir) ?>">Plan de cible (impression) →</a>
  <?php endif; ?>
  <p class="bk-hint">« Réattribuer » libère les cibles des inscriptions en ligne puis les réattribue
     — les modifications du plan de cibles sont ainsi prises en compte (les vœux aussi). Les archers
     saisis hors du module gardent leur cible. « Libérer » vide entièrement le départ.</p>
  <?php endif; ?>
</div>

<div class="bk-sec">
  <h2>Contrôle du règlement</h2>
  <?php if (!$controle): ?>
    <p class="bk-hint">Aucun archer inscrit pour l'instant.</p>
  <?php else: ?>
    <table class="bk-t">
      <tr><th>Départ</th><th>Archers</th><th>Clubs</th><th>Placement</th><th>État</th></tr>
      <?php foreach ($controle as $c): ?>
        <tr>
          <td><?= $c['depart'] ?></td>
          <td><?= $c['archers'] ?></td>
          <td>
            <?= $c['clubs'] ?>
            <span class="bk-pill <?= $c['clubsOk'] ? 'bk-pill-ok' : 'bk-pill-ko' ?>">
              min. <?= $c['minClubs'] ?></span>
          </td>
          <td><?= $c['nonPlaces'] ? $c['nonPlaces'] . ' non placé(s)' : 'complet' ?></td>
          <td>
            <?php if ($c['ok']): ?>
              <span class="bk-pill bk-pill-ok">conforme</span>
            <?php else: ?>
              <span class="bk-pill bk-pill-ko">à corriger</span>
            <?php endif; ?>
            <?php if ($c['exces']): ?>
              <ul class="bk-viol">
                <?php foreach ($c['exces'] as $e): ?>
                  <li>Cible <?= intval($e['cible']) ?> : <?= intval($e['n']) ?> archers du club
                      <?= bk_e($e['club']) ?> (max. <?= $c['max'] ?>)</li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($c['doublons']): ?>
              <ul class="bk-viol">
                <?php foreach ($c['doublons'] as $lic => $n): ?>
                  <li>Licence <?= bk_e($lic) ?> inscrite <?= intval($n) ?> fois sur ce départ</li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="bk-hint">Règles vérifiées : au plus <?= intval($cfg->BcMaxPerClubPerTarget) ?> archers
       d'un même club par cible, au moins <?= intval($cfg->BcMinClubsPerSession) ?> clubs différents
       par départ, pas de double inscription sur un même départ, tous les archers placés.
       Réglable dans <b>Inscriptions en ligne</b>.</p>
  <?php endif; ?>
</div>

<?php
// Demandes libres laissées par les archers (« Autre demande » des souhaits).
$demandes = array();
$rs = safe_r_sql("SELECT e.EnFirstName, e.EnName, e.EnCode, r.BrRequest, q.QuSession
    FROM BK_Registrations r
    INNER JOIN Entries e ON e.EnId = r.BrEnId AND e.EnTournament = $TOUR
    LEFT JOIN Qualifications q ON q.QuId = e.EnId
    WHERE r.BrTournament = $TOUR AND TRIM(COALESCE(r.BrRequest, '')) <> ''
    ORDER BY q.QuSession, e.EnFirstName, e.EnName");
while ($r = safe_fetch($rs)) $demandes[] = $r;
?>
<?php if ($demandes): ?>
<div class="bk-sec">
  <h2>Demandes des archers</h2>
  <p class="bk-hint">Champ libre « Autre demande » laissé à l'inscription — avec l'archer qui l'a
     écrit. (Les souhaits de placement — position, « même cible que » — sont appliqués
     automatiquement et n'apparaissent pas ici.)</p>
  <table class="bk-t">
    <tr><th>Archer</th><th>Licence</th><th>Départ</th><th>Demande</th></tr>
    <?php foreach ($demandes as $d): ?>
      <tr>
        <td><?= bk_e(trim($d->EnFirstName . ' ' . $d->EnName)) ?></td>
        <td><?= bk_e($d->EnCode) ?></td>
        <td><?= $d->QuSession !== null ? 'Départ ' . intval($d->QuSession) : '—' ?></td>
        <td><?= nl2br(bk_e($d->BrRequest)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($voir && $plan): ?>
<div class="bk-sec">
  <h2>Plan du départ <?= $voir ?></h2>
  <?php
  $lettres = array();
  foreach ($plan as $c) foreach (array_keys($c) as $l) $lettres[$l] = true;
  ksort($lettres);
  $lettres = array_keys($lettres);
  ?>
  <table class="bk-t bk-plan">
    <tr><th>Cible</th><?php foreach ($lettres as $l): ?><th><?= bk_e($l) ?></th><?php endforeach; ?></tr>
    <?php foreach ($plan as $cible => $par): ?>
      <tr>
        <td><b><?= intval($cible) ?></b></td>
        <?php foreach ($lettres as $l):
          $a = $par[$l] ?? null; ?>
          <td<?= $a ? '' : ' class="bk-empty-cell"' ?>>
            <?php if ($a): ?>
              <?= bk_e($a->EnFirstName . ' ' . $a->EnName) ?><br>
              <span style="color:#7d8183"><?= bk_e($a->CoCode) ?> · <?= bk_e($a->EnDivision . $a->EnClass) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
