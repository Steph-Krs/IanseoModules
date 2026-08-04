<?php
/**
 * Console d'administration du module PRONO — page 2 : types de pronostics et grille
 * d'ouverture / fermeture fine, épreuve par épreuve et phase par phase.
 *
 * Complète index.php (réglages généraux + accès des joueurs), qui reste la page
 * d'entrée du menu.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

require_once __DIR__ . '/../lib/engine.php';

$tid  = intval($_SESSION['TourId']);
$msg  = '';
$err  = '';
$root = $CFG->ROOT_DIR . 'Modules/Custom/PRONO/';

$MARKET_LABELS = [
    'MATCH_WINNER' => 'Duels des éliminatoires (avec le score exact si coché)',
    'SET_SCORE'    => 'Score exact dans les duels (arc classique)',
    'EVENT_WINNER' => 'Vainqueur de l\'épreuve',
    'QUAL_TIERCE'  => 'Tiercé de qualification (1er / 2e / 3e)',
    'QUAL_TOP1'    => 'Score du 1er qualifié (fourchette)',
    'QUAL_CUT'     => 'Score du cut, dernier qualifié (fourchette)',
];

try {
    if (!prono_tables_exist()) prono_install_schema();
    prono_migrate();

    $cfg = prono_config($tid);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = $_POST['do'] ?? '';

        if ($do === 'types') {
            $events  = implode('|', array_map('strval', (array) ($_POST['ev'] ?? [])));
            $markets = implode('|', array_map('strval', (array) ($_POST['mk'] ?? [])));
            prono_q('UPDATE PRONO_Config SET PaCfEvents = ?, PaCfMarkets = ?, PaCfUpdated = NOW()
                     WHERE PaCfTournament = ?', [$events, $markets ?: 'MATCH_WINNER', $tid]);
            prono_poll($tid, true);
            $msg = 'Types de pronostics et épreuves éligibles enregistrés.';
        }

        if ($do === 'cell') {
            $cell = (string) ($_POST['cell'] ?? '');
            $list = array_filter(explode('|', (string) $cfg['PaCfClosedCells']), 'strlen');
            if (in_array($cell, $list, true)) {
                $list = array_values(array_diff($list, [$cell]));
                $msg  = 'Cellule rouverte.';
            } else {
                $list[] = $cell;
                $msg = 'Cellule fermée.';
            }
            prono_q('UPDATE PRONO_Config SET PaCfClosedCells = ?, PaCfUpdated = NOW()
                     WHERE PaCfTournament = ?', [implode('|', array_unique($list)), $tid]);
            prono_poll($tid, true);
        }

        if ($do === 'column') {
            $cells  = array_filter(explode(',', (string) ($_POST['cells'] ?? '')), 'strlen');
            $action = ($_POST['action'] ?? '') === 'open' ? 'open' : 'close';
            $list   = array_filter(explode('|', (string) $cfg['PaCfClosedCells']), 'strlen');
            $list   = $action === 'close'
                ? array_unique(array_merge($list, $cells))
                : array_values(array_diff($list, $cells));
            prono_q('UPDATE PRONO_Config SET PaCfClosedCells = ?, PaCfUpdated = NOW()
                     WHERE PaCfTournament = ?', [implode('|', $list), $tid]);
            prono_poll($tid, true);
            $msg = $action === 'close' ? 'Colonne fermée.' : 'Colonne rouverte.';
        }
    }

    $cfg         = prono_config($tid);
    $events      = prono_events($tid);
    $selEvents   = array_filter(explode('|', (string) $cfg['PaCfEvents']), 'strlen');
    $selMk       = array_filter(explode('|', (string) $cfg['PaCfMarkets']), 'strlen');
    $closedCells = array_filter(explode('|', (string) $cfg['PaCfClosedCells']), 'strlen');
    $allowed     = fn($t) => !$selMk || in_array($t, $selMk, true);

    $qualsOn      = $allowed('QUAL_TIERCE') || $allowed('QUAL_TOP1') || $allowed('QUAL_CUT');
    $repInstalled = is_file(dirname(__DIR__) . '/REPARTITION_EPREUVES/module.json');

    // Phases de duel réellement présentes dans la compétition (les plus grandes
    // épreuves fixent les colonnes ; les petites n'auront pas de cellule dessus).
    $phases = [];
    foreach ($events as $ev) {
        for ($p = $ev['firstPhase']; $p >= 1; $p = intdiv($p, 2)) $phases[$p] = true;
    }
    krsort($phases);
    $phases = array_keys($phases);

    // Colonnes de la grille, dans l'ordre chronologique d'une compétition.
    $columns = [];
    if ($allowed('QUAL_TIERCE')) $columns[] = ['type' => 'QUAL_TIERCE', 'phase' => 0, 'label' => 'Tiercé'];
    if ($allowed('QUAL_TOP1'))   $columns[] = ['type' => 'QUAL_TOP1',   'phase' => 0, 'label' => 'Score 1er'];
    if ($allowed('QUAL_CUT'))    $columns[] = ['type' => 'QUAL_CUT',    'phase' => 0, 'label' => 'Score cut'];
    if ($allowed('EVENT_WINNER')) $columns[] = ['type' => 'EVENT_WINNER', 'phase' => 0, 'label' => 'Vainqueur'];
    if ($allowed('MATCH_WINNER')) {
        foreach ($phases as $p) {
            $columns[] = ['type' => 'MATCH_WINNER', 'phase' => $p, 'label' => prono_phase_label($p, 2 * $p)];
        }
    }
} catch (Throwable $e) {
    $err     = $e->getMessage();
    $cfg     = $cfg ?? [];
    $events  = $events ?? [];
    $columns = $columns ?? [];
    $selEvents = $selEvents ?? [];
    $selMk     = $selMk ?? [];
    $closedCells = $closedCells ?? [];
    $qualsOn = $qualsOn ?? false;
    $repInstalled = $repInstalled ?? false;
}

include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#prono{max-width:none}
#prono h1{font-size:20px;color:#01367c;margin:0 0 4px}
#prono .lead{color:#4c4e50;font-size:13px;margin-bottom:14px}
#prono .flash{border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13.5px}
#prono .flash.ok{background:#d2f4cd;border:1px solid #75ae77;color:#04ac0b}
#prono .flash.ko{background:#ffd6db;border:1px solid #bb7575;color:#a80000}
#prono .box{background:#fff;border:1px solid #d2d4d6;border-radius:6px;padding:14px;
           box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:14px}
#prono .box h2{font-size:14px;color:#01367c;margin:0 0 10px;text-transform:uppercase;
              letter-spacing:.04em;border-bottom:1px solid #d2d4d6;padding-bottom:6px}
#prono .chk{display:flex;gap:7px;align-items:flex-start;margin-bottom:8px;font-size:13px}
#prono .chk input{margin-top:3px}
#prono .chk em{display:block;color:#7d8183;font-style:normal;font-size:12px}
#prono .btn{background:#0254a8;color:#fff;border:0;border-radius:6px;padding:9px 16px;
           font-weight:600;font-size:14px;cursor:pointer}
#prono .btn.sec{background:#f7f7f7;color:#20263d;border:1px solid #d2d4d6}
#prono .hint{font-size:12px;color:#7d8183;margin:0 0 10px}
#prono .note{border-radius:6px;padding:9px 11px;margin:4px 0 12px;font-size:12.5px;line-height:1.5}
#prono .note.info{background:#eaf3ff;border:1px solid #a7d6ff;color:#0b3d68}
#prono .note.warn{background:#fff6e5;border:1px solid #f0cf8a;color:#6b4c00}
#prono .note a{color:inherit;font-weight:600}
#prono .grid-wrap{overflow-x:auto}
#prono table.grid{border-collapse:collapse;font-size:12.5px;min-width:100%}
#prono table.grid th,#prono table.grid td{border:1px solid #e4e6e8;padding:5px 7px;text-align:center;white-space:nowrap}
#prono table.grid th{background:#f0f4ff;color:#01367c;font-size:11px;text-transform:uppercase}
#prono table.grid td.name{text-align:left;white-space:normal;max-width:220px;font-size:12.5px}
#prono table.grid td.empty{background:#fafafa;color:#ccc}
#prono .cell-btn{border:0;border-radius:5px;padding:3px 8px;font-size:15px;cursor:pointer;line-height:1}
#prono .cell-btn.open{background:#d2f4cd;color:#04ac0b}
#prono .cell-btn.closed{background:#ffd6db;color:#a80000}
#prono .col-actions{display:flex;gap:3px;justify-content:center;margin-top:3px}
#prono .col-actions button{border:0;border-radius:4px;padding:1px 5px;font-size:10px;cursor:pointer;background:#fff;color:#01367c}
</style>

<div id="prono">
  <h1>Pronostics — types &amp; grille</h1>
  <div class="lead">Quels pronostics proposer, et sur quelles épreuves — puis, phase par phase,
    lesquels sont ouverts ou fermés. Réglages généraux et accès des joueurs :
    <a href="<?= $root ?>index.php">retour à la console</a>.</div>

  <?php if ($msg): ?><div class="flash ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko">Erreur : <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if (!$err): ?>
  <form id="types-form" method="post">
    <input type="hidden" name="do" value="types">
    <div class="box">
      <h2>Types de pronostics proposés</h2>
      <?php foreach ($MARKET_LABELS as $k => $lbl): ?>
        <div class="chk">
          <input type="checkbox" name="mk[]" value="<?= $k ?>" id="m-<?= $k ?>"
                 <?= (!$selMk || in_array($k, $selMk, true)) ? 'checked' : '' ?>>
          <label for="m-<?= $k ?>" style="margin:0"><?= htmlspecialchars($lbl) ?></label>
        </div>
      <?php endforeach; ?>

      <?php if ($qualsOn && $repInstalled): ?>
        <div class="note info">📊 Module <b>REPARTITION_EPREUVES</b> détecté — les pronostics de
          qualification s'appuient sur ses classements nationaux pour désigner des favoris dès
          l'ouverture, avant la première flèche. Pense à <b>actualiser les classements</b> avant
          la compétition pour qu'ils soient à jour.
          <a href="<?= htmlspecialchars($CFG->ROOT_DIR . 'Modules/Custom/REPARTITION_EPREUVES/classements.php') ?>"
             target="_blank">Actualiser les classements nationaux →</a></div>
      <?php elseif ($qualsOn): ?>
        <div class="note warn">Avant la première flèche, tous les archers partent à égalité
          faute de classement national installé : les pronostics de qualification ne
          distingueront de favoris qu'une fois des scores rentrés. Le module
          <b>REPARTITION_EPREUVES</b> (classements nationaux FFTA) permet de proposer des
          cotes réalistes dès l'ouverture des pronostics — il vient du même dépôt GitHub que
          ce module.
          <a href="<?= $root ?>admin/update.php">Voir « Autres modules du dépôt » sur la page de mise à jour →</a></div>
      <?php endif; ?>

      <p style="margin:10px 0 0">
        <button class="btn" type="submit">Enregistrer les types et les épreuves cochées ci-dessous</button>
      </p>
    </div>
  </form>

  <div class="box">
    <h2>Épreuves et grille d'ouverture</h2>
    <div class="hint">Case en début de ligne : épreuve éligible aux pronostics (aucune case
      cochée = toutes les épreuves). Chaque cellule s'ouvre ou se ferme d'un clic ; les deux
      petits boutons en tête de colonne agissent sur toute la colonne d'un coup. Une cellule
      grisée n'a pas de sens pour cette épreuve (tableau plus petit, ou type non proposé plus haut).</div>

    <?php if (!$events): ?>
      <div class="hint">Aucune épreuve dans cette compétition.</div>
    <?php else: ?>
    <div class="grid-wrap">
    <table class="grid">
      <tr>
        <th></th>
        <th style="text-align:left">Épreuve</th>
        <?php foreach ($columns as $col):
          // Cellules de cette colonne sur TOUTES les épreuves où elle a un sens —
          // sert aux deux boutons « toute la colonne ».
          $colCells = [];
          foreach ($events as $ev) {
              if ($col['type'] === 'MATCH_WINNER' && $col['phase'] > $ev['firstPhase']) continue;
              if ($col['type'] === 'EVENT_WINNER' && $ev['firstPhase'] <= 0) continue;
              $colCells[] = ($ev['team'] ? 1 : 0) . ':' . $ev['code'] . ':' . $col['type'] . ':' . $col['phase'];
          }
          $cellsAttr = htmlspecialchars(implode(',', $colCells));
        ?>
          <th><?= htmlspecialchars($col['label']) ?>
            <div class="col-actions">
              <form method="post"><input type="hidden" name="do" value="column">
                <input type="hidden" name="cells" value="<?= $cellsAttr ?>">
                <input type="hidden" name="action" value="close">
                <button type="submit" title="Fermer toute la colonne">🔒 tout</button></form>
              <form method="post"><input type="hidden" name="do" value="column">
                <input type="hidden" name="cells" value="<?= $cellsAttr ?>">
                <input type="hidden" name="action" value="open">
                <button type="submit" title="Rouvrir toute la colonne">🔓 tout</button></form>
            </div>
          </th>
        <?php endforeach; ?>
      </tr>
      <?php foreach ($events as $key => $ev):
        $team = $ev['team'] ? 1 : 0;
      ?>
        <tr>
          <td><input type="checkbox" form="types-form" name="ev[]" value="<?= htmlspecialchars($key) ?>"
                     <?= in_array($key, $selEvents, true) ? 'checked' : '' ?>></td>
          <td class="name"><?= htmlspecialchars($ev['name']) ?><?= $ev['team'] ? ' <b>(équipes)</b>' : '' ?>
            <br><small style="color:#7d8183"><?= $ev['sets'] ? 'sets' : 'cumul' ?><?= $ev['firstPhase'] ? ' · tableau de ' . ($ev['firstPhase'] * 2) : '' ?></small></td>
          <?php foreach ($columns as $col):
            $applies = true;
            if ($col['type'] === 'MATCH_WINNER' && $col['phase'] > $ev['firstPhase']) $applies = false;
            if ($col['type'] === 'EVENT_WINNER' && $ev['firstPhase'] <= 0) $applies = false;
            if (!$applies): ?>
              <td class="empty">—</td>
            <?php else:
              $cellKey = $team . ':' . $ev['code'] . ':' . $col['type'] . ':' . $col['phase'];
              $closed  = in_array($cellKey, $closedCells, true);
            ?>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="do" value="cell">
                  <input type="hidden" name="cell" value="<?= htmlspecialchars($cellKey) ?>">
                  <button type="submit" class="cell-btn <?= $closed ? 'closed' : 'open' ?>"
                          title="<?= $closed ? 'Fermée — cliquer pour rouvrir' : 'Ouverte — cliquer pour fermer' ?>">
                    <?= $closed ? '🔒' : '🔓' ?>
                  </button>
                </form>
              </td>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
