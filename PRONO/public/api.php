<?php
/**
 * API de la face publique — le seul point d'entrée exposé par le vhost public.
 *
 * Ne charge jamais config.php de ianseo : ni session, ni ACL, ni routage ianseo.
 * Tous les accès base passent par des requêtes préparées (lib/db.php).
 */
require_once dirname(__DIR__) . '/lib/engine.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

const PRONO_COOKIE = 'prono_tk';

function prono_out($data, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function prono_fail(string $msg, int $code = 400)
{
    prono_out(['ok' => false, 'error' => $msg], $code);
}

const PRONO_MIN_PASS = 4;

/**
 * Le compte est global au serveur ; ses points sont ceux de la compétition en cours,
 * lus dans PRONO_Scores. Un joueur retrouve donc son compte d'une compétition à
 * l'autre, ce qui rend le classement de saison possible.
 */
function prono_current_user(int $tid): ?array
{
    $raw = $_COOKIE[PRONO_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;

    return prono_one(
        'SELECT u.*, IFNULL(s.PaScPoints, 0) AS PaUsPoints, IFNULL(s.PaScBets, 0) AS PaUsBets,
                IFNULL(s.PaScWon, 0) AS PaUsWon
         FROM PRONO_Tokens t
         INNER JOIN PRONO_Users u ON u.PaUsId = t.PaTkUser
         LEFT  JOIN PRONO_Scores s ON s.PaScUser = u.PaUsId AND s.PaScTournament = ?
         WHERE t.PaTkToken = ?',
        [$tid, hash('sha256', $raw)]);
}

/** Recharge un joueur avec ses points de la compétition courante. */
function prono_reload_user(int $uid, int $tid): ?array
{
    return prono_one(
        'SELECT u.*, IFNULL(s.PaScPoints, 0) AS PaUsPoints, IFNULL(s.PaScBets, 0) AS PaUsBets,
                IFNULL(s.PaScWon, 0) AS PaUsWon
         FROM PRONO_Users u
         LEFT JOIN PRONO_Scores s ON s.PaScUser = u.PaUsId AND s.PaScTournament = ?
         WHERE u.PaUsId = ?', [$tid, $uid]);
}

/** Ouvre une session sur CET appareil, sans toucher aux autres. */
function prono_issue_token(int $userId): void
{
    $raw = bin2hex(random_bytes(32));
    prono_q('INSERT INTO PRONO_Tokens (PaTkToken, PaTkUser, PaTkCreated, PaTkSeen)
             VALUES (?, ?, NOW(), NOW())', [hash('sha256', $raw), $userId]);

    $https = (($_SERVER['HTTPS'] ?? '') !== '')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(PRONO_COOKIE, $raw, [
        'expires'  => time() + 86400 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
}

function prono_check_credentials(string $nick, string $pass): array
{
    if (!preg_match('/^[\p{L}\p{N} _\'-]{2,20}$/u', $nick)) {
        prono_fail('Pseudo invalide : 2 à 20 caractères, lettres, chiffres, espace, - ou _.');
    }
    if (mb_strlen($pass) < PRONO_MIN_PASS) {
        prono_fail('Mot de passe trop court : ' . PRONO_MIN_PASS . ' caractères minimum.');
    }
    return [$nick, $pass];
}

function prono_user_payload(?array $u): ?array
{
    if (!$u) return null;
    return [
        'nick'   => $u['PaUsNick'],
        'points' => (int) $u['PaUsPoints'],
        'bets'   => (int) $u['PaUsBets'],
        'won'    => (int) $u['PaUsWon'],
        // Comptes créés avant les mots de passe : la session en cours reste valable,
        // mais il faut en définir un pour pouvoir se reconnecter ailleurs.
        'nopass' => $u['PaUsPass'] === '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────

try {
    if (!prono_tables_exist()) prono_fail('Les pronostics ne sont pas encore installés.', 503);

    $tid = prono_active_tournament();
    if (!$tid) prono_fail('Aucune compétition ouverte aux pronostics.', 503);

    $cfg    = prono_config($tid);
    $action = $_GET['a'] ?? 'snap';
    $user   = prono_current_user($tid);

    switch ($action) {

        // ── État courant : snapshot pré-calculé + situation du joueur
        case 'snap': {
            prono_poll($tid);                       // throttlé à 4 s, verrou partagé

            $path = prono_snapshot_path($tid);
            $snap = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (!$snap) prono_fail('Snapshot indisponible.', 503);

            // Le snapshot est mis en cache : on rafraîchit ce qui dépend de l'heure,
            // pour que la fermeture programmée se voie tout de suite.
            $snap['betsOpen'] = (int) ($cfg['PaCfBetsOpen'] ?? 1);
            $snap['left']     = ($cfg['PaCfLeft'] ?? null) !== null ? (int) $cfg['PaCfLeft'] : null;
            $snap['me']       = prono_user_payload($user);
            if ($user) {
                $snap['mybets'] = prono_all(
                    'SELECT b.PaBeId id, b.PaBeMarket mk, b.PaBeSelection sel,
                            b.PaBePoints pts, b.PaBeStatus status,
                            m.PaMkLabel label, m.PaMkSubLabel sub,
                            -- Un tiercé porte 3 sélections : on assemble « 1er, 2e, 3e »
                            -- plutôt que le seul premier choix.
                            CASE WHEN s2.PaSeId IS NULL THEN s.PaSeLabel
                                 ELSE CONCAT(s.PaSeLabel, \', \', s2.PaSeLabel, \', \', s3.PaSeLabel) END AS pick
                     FROM PRONO_Bets b
                     INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
                     INNER JOIN PRONO_Selections s  ON s.PaSeId = b.PaBeSelection
                     LEFT  JOIN PRONO_Selections s2 ON s2.PaSeId = b.PaBeSelection2
                     LEFT  JOIN PRONO_Selections s3 ON s3.PaSeId = b.PaBeSelection3
                     WHERE b.PaBeUser = ? AND m.PaMkTournament = ?
                     ORDER BY b.PaBeId DESC LIMIT 60', [$user['PaUsId'], $tid]);
                prono_q('UPDATE PRONO_Users SET PaUsSeen = NOW() WHERE PaUsId = ?', [$user['PaUsId']]);
            }
            prono_out($snap);
        }

        // ── Création de compte : pseudo + mot de passe, rien d'autre
        case 'join': {
            if ($user) prono_out(['ok' => true, 'me' => prono_user_payload($user)]);

            [$nick, $pass] = prono_check_credentials(
                trim((string) ($_POST['nick'] ?? '')), (string) ($_POST['pass'] ?? ''));

            // Le pseudo est unique pour tout le serveur : c'est ce qui permet de
            // retrouver son compte à la compétition suivante.
            if (prono_val('SELECT 1 FROM PRONO_Users WHERE PaUsNick = ?', [$nick])) {
                prono_fail('Ce pseudo est déjà pris. Si c\'est le tien, connecte-toi.');
            }

            prono_q('INSERT INTO PRONO_Users (PaUsNick, PaUsPass, PaUsCreated, PaUsSeen)
                     VALUES (?, ?, NOW(), NOW())', [$nick, password_hash($pass, PASSWORD_DEFAULT)]);

            $uid = (int) prono_val('SELECT PaUsId FROM PRONO_Users WHERE PaUsNick = ?', [$nick], 0);
            prono_issue_token($uid);
            prono_out(['ok' => true, 'me' => prono_user_payload(prono_reload_user($uid, $tid))]);
        }

        // ── Connexion depuis un autre appareil : on retrouve ses pronostics et ses points
        case 'login': {
            $nick = trim((string) ($_POST['nick'] ?? ''));
            $pass = (string) ($_POST['pass'] ?? '');

            $me = prono_one('SELECT * FROM PRONO_Users WHERE PaUsNick = ?', [$nick]);

            // Message identique dans les deux cas : ne pas révéler quels pseudos
            // existent. La lenteur de bcrypt suffit à décourager le tâtonnement,
            // on y ajoute une pause pour aplanir les différences de temps.
            if (!$me || empty($me['PaUsPass']) || !password_verify($pass, $me['PaUsPass'])) {
                usleep(400000);
                prono_fail('Pseudo ou mot de passe incorrect.', 401);
            }

            prono_issue_token((int) $me['PaUsId']);
            prono_q('UPDATE PRONO_Users SET PaUsSeen = NOW() WHERE PaUsId = ?', [$me['PaUsId']]);
            prono_out(['ok' => true, 'me' => prono_user_payload(prono_reload_user((int) $me['PaUsId'], $tid))]);
        }

        // ── Définir ou changer son mot de passe depuis un appareil déjà connecté
        case 'setpass': {
            if (!$user) prono_fail('Connecte-toi d\'abord.', 401);

            $pass = (string) ($_POST['pass'] ?? '');
            if (mb_strlen($pass) < PRONO_MIN_PASS) {
                prono_fail('Mot de passe trop court : ' . PRONO_MIN_PASS . ' caractères minimum.');
            }
            // Changer son mot de passe n'a de sens que si l'ancien est connu — sauf
            // pour les comptes qui n'en ont jamais eu.
            if ($user['PaUsPass'] !== '' && !password_verify((string) ($_POST['old'] ?? ''), $user['PaUsPass'])) {
                usleep(400000);
                prono_fail('Mot de passe actuel incorrect.', 401);
            }

            prono_q('UPDATE PRONO_Users SET PaUsPass = ? WHERE PaUsId = ?',
                [password_hash($pass, PASSWORD_DEFAULT), $user['PaUsId']]);
            prono_out(['ok' => true, 'me' => prono_user_payload(
                prono_reload_user((int) $user['PaUsId'], $tid))]);
        }

        // ── Changer de pseudo. Les pronostics suivent : ils sont liés au compte,
        //    pas au nom affiché.
        case 'setnick': {
            if (!$user) prono_fail('Connecte-toi d\'abord.', 401);

            $nick = trim((string) ($_POST['nick'] ?? ''));
            if ($nick === $user['PaUsNick']) {
                prono_out(['ok' => true, 'me' => prono_user_payload($user), 'same' => true]);
            }
            if (!preg_match('/^[\p{L}\p{N} _\'-]{2,20}$/u', $nick)) {
                prono_fail('Pseudo invalide : 2 à 20 caractères, lettres, chiffres, espace, - ou _.');
            }
            if (prono_val('SELECT 1 FROM PRONO_Users WHERE PaUsNick = ? AND PaUsId <> ?',
                    [$nick, $user['PaUsId']])) {
                prono_fail('Ce pseudo est déjà pris.');
            }

            prono_q('UPDATE PRONO_Users SET PaUsNick = ? WHERE PaUsId = ?', [$nick, $user['PaUsId']]);
            prono_poll($tid, true);          // le classement affiche le nouveau nom
            prono_out(['ok' => true, 'me' => prono_user_payload(
                prono_reload_user((int) $user['PaUsId'], $tid))]);
        }

        // ── Déconnexion : ne ferme que l'appareil courant
        case 'logout': {
            $raw = $_COOKIE[PRONO_COOKIE] ?? '';
            if (preg_match('/^[a-f0-9]{64}$/', $raw)) {
                prono_q('DELETE FROM PRONO_Tokens WHERE PaTkToken = ?', [hash('sha256', $raw)]);
            }
            setcookie(PRONO_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
            prono_out(['ok' => true]);
        }

        // ── Pronostic : aucune mise, un simple choix. Modifiable tant que le marché
        //    est ouvert ; les points sont figés au moment du choix.
        case 'predict': {
            if (!$user) prono_fail('Choisis d\'abord un pseudo.', 401);
            // Vérification sur la configuration relue à l'instant, pas sur le snapshot :
            // une échéance dépassée doit bloquer à la seconde près.
            if (empty($cfg['PaCfBetsOpen'])) {
                prono_fail('Les pronostics sont fermés. Tu peux toujours consulter les tiens et le classement.');
            }

            $sid = (int) ($_POST['sel'] ?? 0);
            $sel = prono_one(
                'SELECT s.PaSeId, s.PaSeMarket, s.PaSeGroup, s.PaSeAthlete, s.PaSeOdds,
                        s.PaSeLabel, m.PaMkStatus, m.PaMkType
                 FROM PRONO_Selections s
                 INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                 WHERE s.PaSeId = ? AND m.PaMkTournament = ?', [$sid, $tid]);

            if (!$sel)                         prono_fail('Issue inconnue.');
            if ($sel['PaMkStatus'] !== 'OPEN') prono_fail('Ce pronostic est fermé.');

            $mid    = (int) $sel['PaSeMarket'];
            $points = prono_points($sel['PaMkType'], $sel['PaSeGroup'], (float) $sel['PaSeOdds'], $cfg);
            // Pronostic de score : on fige aussi le lot de consolation, c'est-à-dire ce
            // que rapporte le seul bon vainqueur si le score n'est pas exact.
            $partial = $sel['PaSeGroup'] === 'S'
                ? prono_partial_points($mid, (int) $sel['PaSeAthlete'], $cfg) : 0;

            $db = prono_db();
            $db->beginTransaction();
            try {
                $old = prono_one('SELECT PaBeId, PaBeSelection, PaBeStatus FROM PRONO_Bets
                                  WHERE PaBeUser = ? AND PaBeMarket = ? FOR UPDATE',
                    [$user['PaUsId'], $mid]);

                if ($old && $old['PaBeStatus'] !== 'PENDING') {
                    $db->rollBack();
                    prono_fail('Ce pronostic est déjà réglé.');
                }
                if ($old && (int) $old['PaBeSelection'] === $sid) {
                    $db->rollBack();
                    prono_out(['ok' => true, 'me' => prono_user_payload($user), 'same' => true]);
                }
                if ($old && !prono_changeable($sel['PaMkType'])) {
                    $db->rollBack();
                    prono_fail('Ton pronostic est définitif sur ce marché : il reste ouvert '
                             . 'pendant la compétition, on ne peut pas le changer en cours de route.');
                }

                if ($old) {
                    // Changement d'avis : on retire le vote précédent avant de poser le nouveau.
                    prono_q('UPDATE PRONO_Selections SET PaSePool = GREATEST(0, PaSePool - 1)
                             WHERE PaSeId = ?', [(int) $old['PaBeSelection']]);
                    prono_q('UPDATE PRONO_Bets SET PaBeSelection = ?, PaBeOdds = ?, PaBePoints = ?,
                                PaBePartial = ?, PaBePlaced = NOW() WHERE PaBeId = ?',
                        [$sid, (float) $sel['PaSeOdds'], $points, $partial, (int) $old['PaBeId']]);
                } else {
                    prono_q('INSERT INTO PRONO_Bets (PaBeUser, PaBeMarket, PaBeSelection, PaBeOdds,
                                PaBePoints, PaBePartial, PaBePlaced) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                        [$user['PaUsId'], $mid, $sid, (float) $sel['PaSeOdds'], $points, $partial]);
                    prono_q('INSERT INTO PRONO_Scores (PaScUser, PaScTournament, PaScBets) VALUES (?, ?, 1)
                             ON DUPLICATE KEY UPDATE PaScBets = PaScBets + 1',
                        [$user['PaUsId'], $tid]);
                }
                prono_q('UPDATE PRONO_Selections SET PaSePool = PaSePool + 1 WHERE PaSeId = ?', [$sid]);
                $db->commit();
            } catch (PDOException $e) {
                $db->rollBack();
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) prono_fail('Pronostic déjà enregistré.');
                throw $e;
            }

            prono_poll($tid, true);      // la valeur des issues bouge avec les votes

            prono_out([
                'ok'      => true,
                'me'      => prono_user_payload(prono_reload_user((int) $user['PaUsId'], $tid)),
                'changed' => (bool) $old,
                'pick'    => ['label' => $sel['PaSeLabel'], 'pts' => $points],
            ]);
        }

        // ── Tiercé de qualification : un seul pronostic, 3 noms (1er/2e/3e), comme
        //    aux courses hippiques. Jamais changeable une fois posé (marché « au
        //    long cours », comme le vainqueur d'épreuve).
        case 'predict3': {
            if (!$user) prono_fail('Choisis d\'abord un pseudo.', 401);
            if (empty($cfg['PaCfBetsOpen'])) {
                prono_fail('Les pronostics sont fermés. Tu peux toujours consulter les tiens et le classement.');
            }

            $s1 = (int) ($_POST['s1'] ?? 0);
            $s2 = (int) ($_POST['s2'] ?? 0);
            $s3 = (int) ($_POST['s3'] ?? 0);
            if (!$s1 || !$s2 || !$s3) prono_fail('Choisis un nom pour chacune des 3 places.');

            $sels = prono_all(
                "SELECT s.PaSeId, s.PaSeGroup, s.PaSeAthlete, s.PaSeLabel, m.PaMkId, m.PaMkStatus, m.PaMkType
                 FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                 WHERE s.PaSeId IN (?, ?, ?) AND m.PaMkTournament = ? AND m.PaMkType = 'QUAL_TIERCE'",
                [$s1, $s2, $s3, $tid]);
            $byId = [];
            foreach ($sels as $s) $byId[(int) $s['PaSeId']] = $s;

            if (count($byId) !== 3 || !isset($byId[$s1], $byId[$s2], $byId[$s3])
                || ($byId[$s1]['PaSeGroup'] ?? '') !== 'R1'
                || ($byId[$s2]['PaSeGroup'] ?? '') !== 'R2'
                || ($byId[$s3]['PaSeGroup'] ?? '') !== 'R3') {
                prono_fail('Choix invalide : une place par ligne (1er / 2e / 3e).');
            }
            $mid = (int) $byId[$s1]['PaMkId'];
            if ($byId[$s2]['PaMkId'] != $mid || $byId[$s3]['PaMkId'] != $mid) {
                prono_fail('Les 3 choix doivent porter sur le même tiercé.');
            }
            if ($byId[$s1]['PaMkStatus'] !== 'OPEN') prono_fail('Ce tiercé est fermé.');

            $a1 = (int) $byId[$s1]['PaSeAthlete'];
            $a2 = (int) $byId[$s2]['PaSeAthlete'];
            $a3 = (int) $byId[$s3]['PaSeAthlete'];
            if ($a1 === $a2 || $a1 === $a3 || $a2 === $a3) {
                prono_fail('Les 3 noms doivent être différents.');
            }

            $pts   = prono_tierce_points($mid, $a1, $a2, $a3, $cfg);
            $winProbRows = prono_all(
                "SELECT PaSeAthlete, PaSeProb FROM PRONO_Selections WHERE PaSeMarket = ? AND PaSeGroup = 'R1'",
                [$mid]);
            $winProb = [];
            foreach ($winProbRows as $r) $winProb[(int) $r['PaSeAthlete']] = (float) $r['PaSeProb'];
            $orderP = prono_harville_triple($winProb, $a1, $a2, $a3)['order'];
            $odds   = $orderP > 0 ? min(PRONO_ODDS_MAX, 1 / $orderP) : PRONO_ODDS_MAX;

            $db = prono_db();
            $db->beginTransaction();
            try {
                $old = prono_one('SELECT PaBeId, PaBeStatus FROM PRONO_Bets
                                  WHERE PaBeUser = ? AND PaBeMarket = ? FOR UPDATE',
                    [$user['PaUsId'], $mid]);

                if ($old && $old['PaBeStatus'] !== 'PENDING') {
                    $db->rollBack();
                    prono_fail('Ce tiercé est déjà réglé.');
                }
                if ($old) {
                    $db->rollBack();
                    prono_fail('Ton tiercé est définitif sur cette épreuve : il reste ouvert '
                             . 'pendant la compétition, on ne peut pas le changer en cours de route.');
                }

                prono_q('INSERT INTO PRONO_Bets (PaBeUser, PaBeMarket, PaBeSelection, PaBeSelection2,
                            PaBeSelection3, PaBeOdds, PaBePoints, PaBePartial, PaBePlaced)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$user['PaUsId'], $mid, $s1, $s2, $s3, $odds, $pts['order'], $pts['any']]);
                prono_q('INSERT INTO PRONO_Scores (PaScUser, PaScTournament, PaScBets) VALUES (?, ?, 1)
                         ON DUPLICATE KEY UPDATE PaScBets = PaScBets + 1', [$user['PaUsId'], $tid]);
                foreach ([$s1, $s2, $s3] as $sid) {
                    prono_q('UPDATE PRONO_Selections SET PaSePool = PaSePool + 1 WHERE PaSeId = ?', [$sid]);
                }
                $db->commit();
            } catch (PDOException $e) {
                $db->rollBack();
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) prono_fail('Tiercé déjà enregistré.');
                throw $e;
            }

            prono_poll($tid, true);

            prono_out([
                'ok'   => true,
                'me'   => prono_user_payload(prono_reload_user((int) $user['PaUsId'], $tid)),
                'pick' => ['label' => $byId[$s1]['PaSeLabel'] ?? '', 'pts' => $pts['order']],
            ]);
        }

        default:
            prono_fail('Action inconnue.', 404);
    }
} catch (Throwable $e) {
    error_log('[PRONO] ' . $e->getMessage());
    prono_fail('Erreur interne.', 500);
}
