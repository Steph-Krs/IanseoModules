<?php
/**
 * Console d'administration du module PRONO (dans ianseo).
 * Ouvre / ferme les pronostics, règle les paramètres, surveille l'activité.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

require_once __DIR__ . '/lib/engine.php';

$tid   = intval($_SESSION['TourId']);
$msg   = '';
$err   = '';
$adopt = ['moved' => false, 'msg' => ''];
$root  = $CFG->ROOT_DIR . 'Modules/Custom/PRONO/';

try {
    if (!prono_tables_exist()) prono_install_schema();
    prono_migrate();   // installations antérieures : colonnes et tables ajoutées depuis

    // Réimport de la compétition : ianseo crée un nouveau tournoi et supprime l'ancien.
    // On récupère joueurs et pronostics avant toute autre chose.
    $adopt = prono_adopt($tid);
    if ($adopt['moved']) prono_poll($tid, true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = $_POST['do'] ?? '';

        if ($do === 'save') {
            $margin = max(0, min(30, (float) ($_POST['margin'] ?? 5))) / 100;

            prono_q('UPDATE PRONO_Config SET PaCfTitle = ?, PaCfOpen = ?, PaCfPointsBase = ?,
                        PaCfPointsCap = ?, PaCfScoring = ?, PaCfBandWidth = ?, PaCfMargin = ?,
                        PaCfSeason = ?, PaCfLockOnStart = ?, PaCfAdultOnly = ?, PaCfUpdated = NOW()
                     WHERE PaCfTournament = ?',
                [
                    trim((string) ($_POST['title'] ?? '')),
                    isset($_POST['open']) ? 1 : 0,
                    max(1, min(1000, (int) ($_POST['base'] ?? 10))),
                    max(1.0, min(1000.0, (float) ($_POST['cap'] ?? 25))),
                    ($_POST['scoring'] ?? 'ODDS') === 'FLAT' ? 'FLAT' : 'ODDS',
                    max(1, min(50, (int) ($_POST['band'] ?? 3))),
                    $margin,
                    isset($_POST['season']) ? 1 : 0,
                    isset($_POST['lock']) ? 1 : 0,
                    isset($_POST['adult']) ? 1 : 0,
                    $tid,
                ]);

            // Une seule compétition ouverte à la fois : la face publique n'en sert qu'une.
            if (isset($_POST['open'])) {
                prono_q('UPDATE PRONO_Config SET PaCfOpen = 0 WHERE PaCfTournament <> ?', [$tid]);
            }
            prono_poll($tid, true);
            $msg = 'Paramètres enregistrés et marchés recalculés.';
        }

        // Fermeture / réouverture immédiate, sans couper l'accès aux joueurs.
        if ($do === 'close' || $do === 'reopen') {
            prono_q('UPDATE PRONO_Config SET PaCfBetsClosed = ?, PaCfDeadline = NULL,
                        PaCfUpdated = NOW() WHERE PaCfTournament = ?',
                [$do === 'close' ? 1 : 0, $tid]);
            $msg = $do === 'close'
                ? 'Pronostics clos. Les joueurs gardent l\'accès à leurs pronostics et au classement.'
                : 'Pronostics rouverts.';
        }

        // Échéance : saisie libre, ou raccourci en minutes calculé par MySQL, qui est
        // la seule horloge partagée (PHP tourne en UTC sous ianseo).
        if ($do === 'deadline') {
            $mins = (int) ($_POST['mins'] ?? 0);
            $when = empty($_POST['clear']) ? trim((string) ($_POST['when'] ?? '')) : '';

            if ($mins > 0) {
                prono_q('UPDATE PRONO_Config SET PaCfDeadline = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                            PaCfBetsClosed = 0, PaCfUpdated = NOW() WHERE PaCfTournament = ?',
                    [$mins, $tid]);
                $msg = 'Fermeture programmée dans ' . $mins . ' minutes.';
            } elseif ($when !== '') {
                prono_q('UPDATE PRONO_Config SET PaCfDeadline = ?, PaCfBetsClosed = 0,
                            PaCfUpdated = NOW() WHERE PaCfTournament = ?',
                    [str_replace('T', ' ', $when) . ':00', $tid]);
                $msg = 'Fermeture programmée.';
            } else {
                prono_q('UPDATE PRONO_Config SET PaCfDeadline = NULL, PaCfUpdated = NOW()
                         WHERE PaCfTournament = ?', [$tid]);
                $msg = 'Fermeture programmée annulée.';
            }
        }

        if ($do === 'deluser') {
            $uid = (int) ($_POST['uid'] ?? 0);
            $who = (string) prono_val('SELECT PaUsNick FROM PRONO_Users WHERE PaUsId = ?', [$uid], '');
            if ($who !== '') {
                prono_delete_user($uid, $tid);
                prono_poll($tid, true);
                $msg = 'Compte « ' . $who .' » supprimé, avec ses pronostics.';
            }
        }

        if ($do === 'refresh') {
            $r = prono_poll($tid, true);
            $msg = 'Recalcul effectué : ' . json_encode($r, JSON_UNESCAPED_UNICODE);
        }

        if ($do === 'reset' && ($_POST['confirm'] ?? '') === 'REMISE A ZERO') {
            prono_q('DELETE b FROM PRONO_Bets b INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
                     WHERE m.PaMkTournament = ?', [$tid]);
            prono_q('DELETE s FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                     WHERE m.PaMkTournament = ?', [$tid]);
            prono_q('DELETE FROM PRONO_Markets WHERE PaMkTournament = ?', [$tid]);
            // Les comptes sont globaux : on efface leurs scores sur CETTE compétition,
            // pas les comptes eux-mêmes ni ce qu'ils ont gagné ailleurs.
            prono_q('DELETE FROM PRONO_Scores WHERE PaScTournament = ?', [$tid]);
            @unlink(prono_snapshot_path($tid));
            $msg = 'Pronostics, scores et marchés de cette compétition supprimés. '
                 . 'Les comptes et leurs résultats sur les autres compétitions sont conservés.';
        }
    }

    $cfg = prono_config($tid);

    // Clé du déclencheur HTTP de cron/poll.php (générée à la demande)
    $keyFile = prono_data_dir() . '/poll.key';
    if (!is_readable($keyFile)) @file_put_contents($keyFile, bin2hex(random_bytes(16)));
    $pollKey = trim((string) @file_get_contents($keyFile));

    $stats = prono_one(
        'SELECT (SELECT COUNT(*) FROM PRONO_Scores WHERE PaScTournament = ?) users,
                (SELECT COUNT(*) FROM PRONO_Markets WHERE PaMkTournament = ?) markets,
                (SELECT COUNT(*) FROM PRONO_Markets WHERE PaMkTournament = ? AND PaMkStatus = ?) open,
                (SELECT COUNT(*) FROM PRONO_Bets b INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
                 WHERE m.PaMkTournament = ?) bets,
                (SELECT IFNULL(MAX(PaScPoints),0) FROM PRONO_Scores WHERE PaScTournament = ?) best',
        [$tid, $tid, $tid, 'OPEN', $tid, $tid]);

    $model = @json_decode((string) @file_get_contents(prono_root() . '/data/model.local.json'), true) ?: [];
} catch (Throwable $e) {
    $err = $e->getMessage();
    $cfg = $cfg ?? [];
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
#prono .cols{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start}
#prono .box{background:#fff;border:1px solid #d2d4d6;border-radius:6px;padding:14px;
           box-shadow:0 1px 3px rgba(0,0,0,.08);flex:1 1 340px}
#prono .box h2{font-size:14px;color:#01367c;margin:0 0 10px;text-transform:uppercase;
              letter-spacing:.04em;border-bottom:1px solid #d2d4d6;padding-bottom:6px}
#prono label{display:block;font-size:13px;margin-bottom:9px}
#prono label span{display:block;color:#4c4e50;font-size:12px;margin-bottom:3px}
#prono input[type=text],#prono input[type=number]{width:100%;padding:7px 9px;border:1px solid #d2d4d6;
    border-radius:6px;font:inherit}
#prono .chk{display:flex;gap:7px;align-items:flex-start;margin-bottom:8px;font-size:13px}
#prono .chk input{margin-top:3px}
#prono .chk em{display:block;color:#7d8183;font-style:normal;font-size:12px}
#prono .btn{background:#0254a8;color:#fff;border:0;border-radius:6px;padding:9px 16px;
           font-weight:600;font-size:14px;cursor:pointer}
#prono .btn.sec{background:#f7f7f7;color:#20263d;border:1px solid #d2d4d6}
#prono .btn.dgr{background:#c0392b}
#prono .stat{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
#prono .stat div{flex:1 1 92px;background:#f0f4ff;border-radius:6px;padding:8px 10px}
#prono .stat b{display:block;font-size:19px;color:#0254a8;line-height:1.2}
#prono .stat small{color:#4c4e50;font-size:11.5px}
#prono .url{background:#f0f4ff;border:1px solid #a7d6ff;border-radius:6px;padding:8px 10px;
           font-family:monospace;font-size:12.5px;word-break:break-all;margin-bottom:8px}
#prono details{margin-top:12px;border:1px solid #e8b4ae;border-radius:6px}
#prono details summary{background:#fdf0ef;color:#c0392b;padding:8px 12px;font-weight:600;
                      font-size:13px;cursor:pointer;border-radius:5px}
#prono details .body{padding:12px}
#prono .hint{font-size:12px;color:#7d8183;margin-top:6px}
</style>

<div id="prono">
  <h1>Pronostics — console</h1>
  <div class="lead">Pronostics sur la compétition en cours. Aucune mise, aucun argent : les
    spectateurs entrent un pseudo, pronostiquent depuis leur téléphone et marquent des points
    quand ils voient juste. Un pronostic se change tant que le match n'a pas commencé.</div>

  <?php if (!empty($adopt['msg'])): ?>
    <div class="flash <?= $adopt['moved'] ? 'ok' : 'ko' ?>"><?= htmlspecialchars($adopt['msg']) ?></div>
  <?php endif; ?>
  <?php if ($msg): ?><div class="flash ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash ko">Erreur : <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if (!$err): ?>
  <div class="stat">
    <div><b><?= (int) $stats['users'] ?></b><small>joueurs</small></div>
    <div><b><?= (int) $stats['bets'] ?></b><small>pronostics</small></div>
    <div><b><?= (int) $stats['best'] ?></b><small>meilleur score</small></div>
    <div><b><?= (int) $stats['open'] ?></b><small>marchés ouverts</small></div>
    <div><b><?= (int) $stats['markets'] ?></b><small>marchés au total</small></div>
  </div>

  <?php
    $betsOpen = !empty($cfg['PaCfBetsOpen']);
    $left     = $cfg['PaCfLeft'] ?? null;
  ?>
  <div class="box" style="margin-bottom:14px">
    <h2 style="margin-bottom:8px">Prise de pronostics</h2>
    <div class="cols" style="gap:12px">
      <div style="flex:1 1 280px">
        <p style="margin:0 0 10px;font-size:14px">
          <?php if ($betsOpen): ?>
            <b style="color:#04ac0b">● Ouverte</b> — les joueurs peuvent pronostiquer.
            <?php if ($left !== null && $left > 0): ?>
              <br><span style="color:#cb8137">Fermeture automatique dans
              <?= $left >= 3600 ? intdiv((int) $left, 3600) . ' h ' . intdiv((int) $left % 3600, 60) . ' min'
                                : max(1, intdiv((int) $left, 60)) . ' min' ?>.</span>
            <?php endif; ?>
          <?php else: ?>
            <b style="color:#a80000">● Close</b> — les joueurs consultent toujours leurs
            pronostics et le classement, mais n'en posent plus.
          <?php endif; ?>
        </p>
        <form method="post" style="display:inline">
          <?php if ($betsOpen): ?>
            <button class="btn dgr" type="submit" name="do" value="close">Fermer les pronostics maintenant</button>
          <?php else: ?>
            <button class="btn" type="submit" name="do" value="reopen">Rouvrir les pronostics</button>
          <?php endif; ?>
        </form>
      </div>

      <div style="flex:1 1 320px">
        <form method="post">
          <input type="hidden" name="do" value="deadline">
          <div class="hint" style="margin:0 0 6px">Programmer la fermeture — horloge du
            serveur : <b><?= htmlspecialchars(substr((string) ($cfg['PaCfNow'] ?? ''), 0, 16)) ?></b></div>
          <p style="margin:0 0 8px">
            <?php foreach ([15, 30, 60, 120] as $m): ?>
              <button class="btn sec" type="submit" name="mins" value="<?= $m ?>"
                      style="padding:7px 12px"><?= $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h' ?></button>
            <?php endforeach; ?>
          </p>
          <label style="margin-bottom:8px"><span>ou une heure précise</span>
            <input type="datetime-local" name="when"
                   value="<?= htmlspecialchars(str_replace(' ', 'T', substr((string) ($cfg['PaCfDeadline'] ?? ''), 0, 16))) ?>"
                   style="width:100%;padding:7px 9px;border:1px solid #d2d4d6;border-radius:6px;font:inherit"></label>
          <button class="btn sec" type="submit">Enregistrer l'échéance</button>
          <?php if (!empty($cfg['PaCfDeadline'])): ?>
            <button class="btn sec" type="submit" name="clear" value="1">Annuler l'échéance</button>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="box" style="margin-bottom:14px">
    <h2 style="margin-bottom:8px">Types de pronostics et grille d'ouverture</h2>
    <p style="margin:0 0 10px;font-size:13.5px">Choisir quels pronostics proposer, sur quelles
      épreuves, et fermer chaque phase individuellement (ou toute une colonne d'un coup) :
      c'est sur une page à part, pour ne pas noyer les réglages ci-dessous.</p>
    <a class="btn" style="text-decoration:none;display:inline-block" href="<?= $root ?>admin/markets.php">
      Ouvrir la grille des pronostics →</a>
  </div>

  <form method="post">
    <input type="hidden" name="do" value="save">
    <div class="cols">

      <div class="box">
        <h2>Réglages</h2>
        <label><span>Titre affiché sur les téléphones</span>
          <input type="text" name="title" maxlength="120"
                 value="<?= htmlspecialchars((string) $cfg['PaCfTitle']) ?>"
                 placeholder="<?= htmlspecialchars($_SESSION['TourName'] ?? 'Pronostics') ?>"></label>

        <div class="chk"><input type="checkbox" name="open" id="c-open" <?= $cfg['PaCfOpen'] ? 'checked' : '' ?>>
          <label for="c-open" style="margin:0"><b>Compétition active</b>
            <em>Une seule à la fois : cocher ici désactive les autres. Décocher coupe
            complètement l'accès — pour seulement arrêter les pronostics en laissant
            les joueurs consulter, utilise le bouton ci-dessous.</em></label></div>

        <label><span>Points d'un pronostic à 50/50</span>
          <input type="number" name="base" min="1" max="1000" value="<?= (int) $cfg['PaCfPointsBase'] ?>"></label>

        <label><span>Plafond de difficulté (barème à la difficulté uniquement)</span>
          <input type="number" name="cap" min="1" max="1000" step="0.5"
                 value="<?= rtrim(rtrim(number_format((float) ($cfg['PaCfPointsCap'] ?? 25), 2, '.', ''), '0'), '.') ?>"></label>
        <div class="hint" style="margin:-6px 0 11px">Un outsider ne peut pas rapporter plus de
          ce multiple des points de base, même à une chance sur mille. Exemple : base 10,
          plafond 25 → 250 points maximum pour un seul pronostic.</div>

        <div class="chk"><input type="radio" name="scoring" id="s-odds" value="ODDS"
             <?= $cfg['PaCfScoring'] !== 'FLAT' ? 'checked' : '' ?>>
          <label for="s-odds" style="margin:0"><b>Barème à la difficulté</b>
            <em>Plus l'issue est improbable, plus elle rapporte. Voir juste quand personne
            n'y croyait doit payer.</em></label></div>
        <div class="chk"><input type="radio" name="scoring" id="s-flat" value="FLAT"
             <?= $cfg['PaCfScoring'] === 'FLAT' ? 'checked' : '' ?>>
          <label for="s-flat" style="margin:0"><b>Barème forfaitaire</b>
            <em>Points fixes par type : duel ×1, score exact ×3, vainqueur d'épreuve ×2,5.
            Plus lisible d'un coup d'œil.</em></label></div>

        <label><span>Arc à poulies — largeur des tranches de score (points)</span>
          <input type="number" name="band" min="1" max="50" step="1"
                 value="<?= (int) ($cfg['PaCfBandWidth'] ?? 3) ?>"></label>
        <div class="hint" style="margin:-6px 0 11px">Les poulies se jouant au cumul, on
          annonce le total du vainqueur par tranches. Plus la tranche est large, plus
          elle est facile à deviner — et moins elle rapporte. Un changement ne rejuge
          jamais un pronostic déjà posé.</div>

        <label><span>Marge (%) — laisser à 0 en mode points</span>
          <input type="number" name="margin" min="0" max="30" step="0.5"
                 value="<?= rtrim(rtrim(number_format((float) $cfg['PaCfMargin'] * 100, 1, '.', ''), '0'), '.') ?>"></label>

        <div class="chk"><input type="checkbox" name="lock" id="c-lock" <?= $cfg['PaCfLockOnStart'] ? 'checked' : '' ?>>
          <label for="c-lock" style="margin:0"><b>Fermer un duel dès la première volée</b>
            <em>Recommandé. La saisie ianseo a du retard sur le terrain : sans ça, quelqu'un
            dans les gradins peut poser un pronostic sur un résultat qu'il connaît déjà.</em></label></div>

        <div class="chk"><input type="checkbox" name="season" id="c-season" <?= !empty($cfg['PaCfSeason']) ? 'checked' : '' ?>>
          <label for="c-season" style="margin:0"><b>Compte pour le classement de la saison</b>
            <em>Décocher retire cette compétition du classement général, sans toucher à
            ses pronostics ni à son classement propre. Pratique pour écarter un essai.</em></label></div>

        <div class="chk"><input type="checkbox" name="adult" id="c-adult" <?= $cfg['PaCfAdultOnly'] ? 'checked' : '' ?>>
          <label for="c-adult" style="margin:0"><b>Catégories adultes uniquement</b>
            <em>Écarte les épreuves U11 à U18.</em></label></div>
      </div>

      <div class="box">
        <h2>Accès des joueurs</h2>
        <div class="hint" style="margin:0 0 6px">Depuis le réseau local :</div>
        <div class="url"><?= htmlspecialchars($root) ?>public/</div>
        <div class="hint" style="margin:0 0 6px">Depuis un téléphone en 4G, via le tunnel Cloudflare
          (voir le README du module) : la racine du vhost dédié.</div>

        <h2 style="margin-top:16px">Modèle</h2>
        <?php if ($model): ?>
          <div class="hint" style="margin:0 0 8px">
            Température <b><?= htmlspecialchars((string) $model['temperature']) ?></b>
            <?= isset($model['n']) ? ' — ajustée sur ' . (int) $model['n'] . ' matchs' : '' ?>
            <?= isset($model['fitted']) ? '<br>le ' . htmlspecialchars(substr((string) $model['fitted'], 0, 10)) : '' ?>
          </div>
        <?php else: ?>
          <div class="hint" style="margin:0 0 8px">Modèle non calibré (température 1.0).</div>
        <?php endif; ?>
        <a class="btn sec" style="text-decoration:none;display:inline-block"
           href="<?= $root ?>admin/calibrate.php">Calibrer sur l'historique</a>

        <h2 style="margin-top:16px">Affiche et QR code</h2>
        <?php if (!empty($cfg['PaCfPublicUrl'])): ?>
          <div class="url"><?= htmlspecialchars((string) $cfg['PaCfPublicUrl']) ?></div>
        <?php else: ?>
          <div class="hint" style="margin:0 0 8px">Adresse publique pas encore renseignée.</div>
        <?php endif; ?>
        <a class="btn sec" style="text-decoration:none;display:inline-block"
           href="<?= $root ?>admin/qrcode.php">Préparer l'affiche à imprimer</a>

        <h2 style="margin-top:16px">Écran de salle</h2>
        <a class="btn sec" style="text-decoration:none;display:inline-block"
           href="<?= $root ?>screen.php" target="_blank">Ouvrir la vue projection</a>
      </div>
    </div>

    <p style="margin-top:14px">
      <button class="btn" type="submit">Enregistrer</button>
      <button class="btn sec" type="submit" name="do" value="refresh">Recalculer maintenant</button>
    </p>
  </form>

  <?php
    $players = prono_all('SELECT u.PaUsId, u.PaUsNick, u.PaUsSeen,
                                 IFNULL(s.PaScPoints,0) PaUsPoints, IFNULL(s.PaScBets,0) PaUsBets,
                                 IFNULL(s.PaScWon,0) PaUsWon
                          FROM PRONO_Scores s INNER JOIN PRONO_Users u ON u.PaUsId = s.PaScUser
                          WHERE s.PaScTournament = ?
                          ORDER BY s.PaScPoints DESC, u.PaUsNick', [$tid]);
  ?>
  <?php if ($players): ?>
  <details style="border-color:#d2d4d6;margin-top:14px">
    <summary style="background:#f0f4ff;color:#01367c">Joueurs (<?= count($players) ?>)</summary>
    <div class="body">
      <div class="hint" style="margin:0 0 8px">Supprimer un compte efface aussi ses
        pronostics et ses points. Les valeurs affichées aux autres joueurs sont
        recalculées dans la foulée.</div>
      <table style="border-collapse:collapse;width:100%;max-width:720px">
        <tr style="background:#f0f4ff">
          <th style="text-align:left;padding:6px 8px;font-size:11.5px;text-transform:uppercase">Pseudo</th>
          <th style="text-align:right;padding:6px 8px;font-size:11.5px;text-transform:uppercase">Points</th>
          <th style="text-align:right;padding:6px 8px;font-size:11.5px;text-transform:uppercase">Justes</th>
          <th style="text-align:right;padding:6px 8px;font-size:11.5px;text-transform:uppercase">Vu le</th>
          <th></th>
        </tr>
        <?php foreach ($players as $pl): ?>
          <tr style="border-bottom:1px solid #eceef0">
            <td style="padding:6px 8px;font-size:13.5px"><?= htmlspecialchars($pl['PaUsNick']) ?></td>
            <td style="padding:6px 8px;text-align:right;font-weight:700"><?= (int) $pl['PaUsPoints'] ?></td>
            <td style="padding:6px 8px;text-align:right"><?= (int) $pl['PaUsWon'] ?>/<?= (int) $pl['PaUsBets'] ?></td>
            <td style="padding:6px 8px;text-align:right;color:#7d8183;font-size:12.5px">
              <?= htmlspecialchars(substr((string) $pl['PaUsSeen'], 5, 11)) ?></td>
            <td style="padding:6px 8px;text-align:right">
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Supprimer définitivement le compte « <?= htmlspecialchars(addslashes($pl['PaUsNick'])) ?> » et ses <?= (int) $pl['PaUsBets'] ?> pronostic(s) ?')">
                <input type="hidden" name="do" value="deluser">
                <input type="hidden" name="uid" value="<?= (int) $pl['PaUsId'] ?>">
                <button class="btn dgr" style="padding:4px 10px;font-size:12.5px">Supprimer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </details>
  <?php endif; ?>

  <details>
    <summary>Remettre les pronostics à zéro</summary>
    <div class="body">
      <p style="font-size:13px;margin-top:0">Supprime <b>tous</b> les joueurs, pronostics et marchés de cette
      compétition. Les résultats sportifs ne sont pas touchés.</p>
      <form method="post" onsubmit="return confirm('Supprimer tous les pronostics et joueurs ?')">
        <input type="hidden" name="do" value="reset">
        <label><span>Taper <b>REMISE A ZERO</b> pour confirmer</span>
          <input type="text" name="confirm" autocomplete="off"></label>
        <button class="btn dgr" type="submit">Supprimer</button>
      </form>
    </div>
  </details>
  <?php endif; ?>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
