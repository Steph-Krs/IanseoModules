<?php
/**
 * Console d'administration du module PRONO — page 3 : groupes de joueurs et gestion
 * des comptes. Complète index.php (réglages généraux) et admin/markets.php (types &
 * grille), qui restent les autres pages du menu.
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

try {
    if (!prono_tables_exist()) prono_install_schema();
    prono_migrate();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = $_POST['do'] ?? '';

        if ($do === 'delgroup') {
            $gid  = (int) ($_POST['gid'] ?? 0);
            $name = (string) prono_val('SELECT PgName FROM PRONO_Groups WHERE PgId = ?', [$gid], '');
            if ($name !== '') {
                prono_group_delete($gid, 0, true);
                $msg = 'Groupe « ' . $name . ' » supprimé.';
            }
        }

        if ($do === 'deluser') {
            $uid = (int) ($_POST['uid'] ?? 0);
            $who = (string) prono_val('SELECT PaUsNick FROM PRONO_Users WHERE PaUsId = ?', [$uid], '');
            if ($who !== '') {
                prono_delete_user($uid, $tid);
                prono_poll($tid, true);
                $msg = 'Compte « ' . $who . ' » supprimé, avec ses pronostics.';
            }
        }
    }

    $groups = prono_all_groups();
    $players = prono_all('SELECT u.PaUsId, u.PaUsNick, u.PaUsSeen,
                                 IFNULL(s.PaScPoints,0) PaUsPoints, IFNULL(s.PaScBets,0) PaUsBets,
                                 IFNULL(s.PaScWon,0) PaUsWon
                          FROM PRONO_Scores s INNER JOIN PRONO_Users u ON u.PaUsId = s.PaScUser
                          WHERE s.PaScTournament = ?
                          ORDER BY s.PaScPoints DESC, u.PaUsNick', [$tid]);
} catch (Throwable $e) {
    $err     = $e->getMessage();
    $groups  = $groups ?? [];
    $players = $players ?? [];
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
#prono .hint{font-size:12px;color:#7d8183;margin:0 0 10px}
#prono .btn{background:#0254a8;color:#fff;border:0;border-radius:6px;padding:4px 10px;
           font-weight:600;font-size:12.5px;cursor:pointer}
#prono .btn.dgr{background:#c0392b}
#prono table.plain{border-collapse:collapse;width:100%;max-width:720px}
#prono table.plain th{text-align:left;padding:6px 8px;font-size:11.5px;text-transform:uppercase;background:#f0f4ff}
#prono table.plain th.n,#prono table.plain td.n{text-align:right}
#prono table.plain td{padding:6px 8px;font-size:13.5px;border-bottom:1px solid #eceef0}
#prono .empty{color:#7d8183;font-size:13px}
</style>

<div id="prono">
  <h1>Pronostics — groupes &amp; joueurs</h1>
  <div class="lead">Groupes créés par les joueurs (classements parallèles) et comptes
    joueurs de cette compétition. Réglages généraux : <a href="<?= $root ?>index.php">retour
    à la console</a>.</div>

  <?php if ($msg): ?><div class="flash ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko">Erreur : <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="box">
    <h2>Groupes (<?= count($groups) ?>)</h2>
    <?php if (!$groups): ?>
      <div class="empty">Aucun groupe créé pour l'instant.</div>
    <?php else: ?>
    <div class="hint">Un groupe se crée et se rejoint depuis la face publique (nom + mot
      de passe). Le supprimer ici ne touche ni aux comptes ni à leurs pronostics — seul
      le classement parallèle disparaît.</div>
    <table class="plain">
      <tr>
        <th>Nom</th><th>Propriétaire</th><th class="n">Membres</th><th>Créé le</th><th></th>
      </tr>
      <?php foreach ($groups as $g): ?>
        <tr>
          <td><?= htmlspecialchars($g['name']) ?></td>
          <td><?= htmlspecialchars((string) ($g['owner'] ?? '—')) ?></td>
          <td class="n"><?= (int) $g['members'] ?></td>
          <td style="color:#7d8183;font-size:12.5px"><?= htmlspecialchars(substr((string) $g['created'], 0, 10)) ?></td>
          <td style="text-align:right">
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Supprimer définitivement le groupe « <?= htmlspecialchars(addslashes($g['name'])) ?> » ?')">
              <input type="hidden" name="do" value="delgroup">
              <input type="hidden" name="gid" value="<?= (int) $g['id'] ?>">
              <button class="btn dgr">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="box">
    <h2>Joueurs de cette compétition (<?= count($players) ?>)</h2>
    <?php if (!$players): ?>
      <div class="empty">Personne n'a encore pronostiqué.</div>
    <?php else: ?>
    <div class="hint">Supprimer un compte efface aussi ses pronostics et ses points. Les
      valeurs affichées aux autres joueurs sont recalculées dans la foulée.</div>
    <table class="plain">
      <tr>
        <th>Pseudo</th><th class="n">Points</th><th class="n">Justes</th><th class="n">Vu le</th><th></th>
      </tr>
      <?php foreach ($players as $pl): ?>
        <tr>
          <td><?= htmlspecialchars($pl['PaUsNick']) ?></td>
          <td class="n" style="font-weight:700"><?= (int) $pl['PaUsPoints'] ?></td>
          <td class="n"><?= (int) $pl['PaUsWon'] ?>/<?= (int) $pl['PaUsBets'] ?></td>
          <td class="n" style="color:#7d8183;font-size:12.5px"><?= htmlspecialchars(substr((string) $pl['PaUsSeen'], 5, 11)) ?></td>
          <td style="text-align:right">
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Supprimer définitivement le compte « <?= htmlspecialchars(addslashes($pl['PaUsNick'])) ?> » et ses <?= (int) $pl['PaUsBets'] ?> pronostic(s) ?')">
              <input type="hidden" name="do" value="deluser">
              <input type="hidden" name="uid" value="<?= (int) $pl['PaUsId'] ?>">
              <button class="btn dgr">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
