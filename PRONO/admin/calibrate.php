<?php
/**
 * Calibrage du modèle sur les compétitions déjà présentes en base.
 * Mesure l'écart entre probabilités annoncées et résultats réels, puis propose
 * d'enregistrer la température correctrice.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

require_once dirname(__DIR__) . '/lib/calibrate.php';

$root = $CFG->ROOT_DIR . 'Modules/Custom/PRONO/';
$fit  = null;
$msg  = '';
$err  = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        @set_time_limit(300);
        $samples = prono_calibration_samples();
        $fit     = prono_fit_temperature($samples);

        if (($_POST['do'] ?? '') === 'save' && empty($fit['error'])) {
            prono_save_temperature((float) $fit['t'], ['n' => $fit['n']]);
            $msg = 'Température ' . $fit['t'] . ' enregistrée. Elle s\'applique dès le prochain recalcul.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$current = @json_decode((string) @file_get_contents(prono_root() . '/data/model.local.json'), true) ?: [];

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#pcal h1{font-size:20px;color:#01367c;margin:0 0 4px}
#pcal .lead{color:#4c4e50;font-size:13px;margin-bottom:14px;max-width:780px}
#pcal .box{background:#fff;border:1px solid #d2d4d6;border-radius:6px;padding:14px;
           box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:14px;max-width:780px}
#pcal .flash{border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13.5px}
#pcal .flash.ok{background:#d2f4cd;border:1px solid #75ae77;color:#04ac0b}
#pcal .flash.ko{background:#ffd6db;border:1px solid #bb7575;color:#a80000}
#pcal .btn{background:#0254a8;color:#fff;border:0;border-radius:6px;padding:9px 16px;
           font-weight:600;font-size:14px;cursor:pointer}
#pcal .btn.sec{background:#f7f7f7;color:#20263d;border:1px solid #d2d4d6;text-decoration:none;
               display:inline-block}
#pcal table{border-collapse:collapse;width:100%;font-size:13px}
#pcal th,#pcal td{padding:6px 9px;border-bottom:1px solid #eceef0;text-align:right}
#pcal th:first-child,#pcal td:first-child{text-align:left}
#pcal th{background:#f0f4ff;color:#4c4e50;font-size:11.5px;text-transform:uppercase}
#pcal .bar{display:inline-block;height:11px;background:#0254a8;border-radius:2px;vertical-align:middle}
#pcal .bar.obs{background:#2ad56e}
#pcal .kpi{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
#pcal .kpi div{flex:1 1 130px;background:#f0f4ff;border-radius:6px;padding:9px 11px}
#pcal .kpi b{display:block;font-size:20px;color:#0254a8}
#pcal .kpi small{color:#4c4e50;font-size:11.5px}
</style>

<div id="pcal">
  <h1>Calibrage du modèle</h1>
  <div class="lead">Le modèle suppose les flèches indépendantes les unes des autres, ce qui peut le
    rendre trop tranché. On rejoue donc tous les duels déjà disputés en base, on compare les
    probabilités annoncées aux résultats réels, et on en déduit une correction unique.</div>

  <?php if ($msg): ?><div class="flash ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko">Erreur : <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="box">
    <p style="margin-top:0;font-size:13.5px">
      Réglage courant :
      <b><?= $current ? htmlspecialchars((string) $current['temperature']) : '1.0 (non calibré)' ?></b>
      <?php if (!empty($current['fitted'])): ?>
        <span style="color:#7d8183">— mesuré le <?= htmlspecialchars(substr((string) $current['fitted'], 0, 10)) ?>
        sur <?= (int) ($current['n'] ?? 0) ?> matchs</span>
      <?php endif; ?>
    </p>
    <form method="post" style="display:inline">
      <button class="btn" type="submit">Analyser l'historique</button>
    </form>
    <a class="btn sec" href="<?= $root ?>index.php">Retour à la console</a>
  </div>

  <?php if ($fit && empty($fit['error'])): ?>
    <div class="box">
      <div class="kpi">
        <div><b><?= (int) $fit['n'] ?></b><small>matchs analysés</small></div>
        <div><b><?= htmlspecialchars((string) $fit['t']) ?></b><small>température proposée</small></div>
        <div><b><?= htmlspecialchars((string) $fit['brier_before']) ?></b><small>Brier avant</small></div>
        <div><b><?= htmlspecialchars((string) $fit['brier_after']) ?></b><small>Brier après</small></div>
      </div>

      <p style="font-size:13px;color:#4c4e50">
        Une température proche de <b>1</b> signifie que le modèle était déjà juste. En dessous de 1,
        il était trop sûr de lui ; au-dessus, trop prudent. Le score de Brier mesure l'erreur
        moyenne : plus il est bas, mieux c'est.
      </p>

      <h3 style="font-size:13px;color:#01367c;text-transform:uppercase;letter-spacing:.04em">
        Fiabilité — annoncé contre observé</h3>
      <table>
        <thead><tr><th>Tranche</th><th>Matchs</th><th>Annoncé</th><th>Observé</th><th style="width:34%"></th></tr></thead>
        <tbody>
        <?php foreach ($fit['bins'] as $k => $b): ?>
          <tr>
            <td><?= $k * 10 ?>–<?= $k * 10 + 10 ?> %</td>
            <td><?= (int) $b['n'] ?></td>
            <td><?= number_format($b['pred'] * 100, 1) ?> %</td>
            <td><?= number_format($b['obs'] * 100, 1) ?> %</td>
            <td style="text-align:left">
              <span class="bar" style="width:<?= round($b['pred'] * 100) ?>%"></span><br>
              <span class="bar obs" style="width:<?= round($b['obs'] * 100) ?>%"></span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p style="font-size:12px;color:#7d8183">Barre bleue : ce que le modèle annonçait. Barre verte :
        ce qui s'est réellement passé. Plus elles se ressemblent, mieux le modèle est calibré.</p>

      <form method="post">
        <input type="hidden" name="do" value="save">
        <button class="btn" type="submit">Enregistrer cette température</button>
      </form>
    </div>
  <?php elseif ($fit): ?>
    <div class="box"><?= htmlspecialchars($fit['error']) ?> — il faut au moins une trentaine de
      duels terminés en base pour mesurer quoi que ce soit.</div>
  <?php endif; ?>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
