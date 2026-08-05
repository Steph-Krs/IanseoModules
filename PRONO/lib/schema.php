<?php
/**
 * Création des tables PRONO_*.
 *
 * Collation forcée à utf8mb4_unicode_ci : les tables ianseo peuvent être en
 * utf8mb4_0900_ai_ci ou _as_ci selon le serveur MySQL 8, et un JOIN entre une
 * colonne VARCHAR custom et une colonne VARCHAR ianseo lèverait une erreur 1267.
 * Les jointures concernées portent un COLLATE explicite côté custom (cf. data.php).
 */
require_once __DIR__ . '/db.php';

function prono_install_schema(): void
{
    $db = prono_db();
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Pas de solde ni de mise : on pronostique, et on marque des points quand on a
    // vu juste. PaCfPointsBase = points d'un pronostic à 50/50 ; PaCfScoring choisit
    // entre un barème indexé sur la difficulté (ODDS) et un forfait par type (FLAT).
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Config (
        PaCfTournament   INT          NOT NULL,
        PaCfTitle        VARCHAR(120) NOT NULL DEFAULT '',
        PaCfOpen         TINYINT      NOT NULL DEFAULT 0,
        PaCfPointsBase   INT          NOT NULL DEFAULT 10,
        PaCfPointsCap    DECIMAL(6,2) NOT NULL DEFAULT 25.00,
        PaCfScoring      VARCHAR(10)  NOT NULL DEFAULT 'ODDS',
        PaCfBetsClosed   TINYINT      NOT NULL DEFAULT 0,
        PaCfDeadline     DATETIME     NULL,
        PaCfMargin       DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
        PaCfLockOnStart  TINYINT      NOT NULL DEFAULT 1,
        PaCfAdultOnly    TINYINT      NOT NULL DEFAULT 1,
        PaCfEvents       TEXT         NULL,
        PaCfMarkets      VARCHAR(255) NOT NULL DEFAULT 'MATCH_WINNER|SET_SCORE|EVENT_WINNER|QUAL_TIERCE|QUAL_TOP1|QUAL_CUT',
        PaCfUpdated      DATETIME     NULL,
        PRIMARY KEY (PaCfTournament)
    ) $opt");

    // PaUsPass : empreinte bcrypt (password_hash). Le mot de passe permet de
    // retrouver ses pronostics depuis un autre téléphone — sans lui, un pseudo perdu
    // avec le cookie est perdu tout court.
    // Compte unique pour tout le serveur : un joueur s'inscrit une fois et retrouve
    // son compte d'une compétition à l'autre, ce qui rend possible un classement de
    // saison. Les points, eux, sont comptés par compétition (PRONO_Scores).
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Users (
        PaUsId         INT AUTO_INCREMENT PRIMARY KEY,
        PaUsNick       VARCHAR(24)   NOT NULL,
        PaUsPass       VARCHAR(255)  NOT NULL DEFAULT '',
        PaUsCreated    DATETIME      NOT NULL,
        PaUsSeen       DATETIME      NOT NULL,
        UNIQUE KEY uq_nick (PaUsNick)
    ) $opt");

    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Scores (
        PaScUser       INT NOT NULL,
        PaScTournament INT NOT NULL,
        PaScPoints     INT NOT NULL DEFAULT 0,
        PaScBets       INT NOT NULL DEFAULT 0,
        PaScWon        INT NOT NULL DEFAULT 0,
        PRIMARY KEY (PaScUser, PaScTournament),
        KEY idx_tour (PaScTournament)
    ) $opt");

    // Un jeton par appareil : se connecter sur le téléphone ne déconnecte pas
    // la tablette.
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Tokens (
        PaTkToken   CHAR(64) NOT NULL,
        PaTkUser    INT      NOT NULL,
        PaTkCreated DATETIME NOT NULL,
        PaTkSeen    DATETIME NOT NULL,
        PRIMARY KEY (PaTkToken),
        KEY idx_user (PaTkUser)
    ) $opt");

    // PaMkKey : discriminant du marché dans son type (n° de slot, id d'archer…).
    // L'unicité (tournoi, épreuve, type, clé) rend la génération idempotente :
    // le poller peut tourner en boucle sans jamais dupliquer un marché.
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Markets (
        PaMkId         INT AUTO_INCREMENT PRIMARY KEY,
        PaMkTournament INT           NOT NULL,
        PaMkTeam       TINYINT       NOT NULL DEFAULT 0,
        PaMkEvent      VARCHAR(10)   NOT NULL,
        PaMkType       VARCHAR(16)   NOT NULL,
        PaMkKey        VARCHAR(40)   NOT NULL,
        PaMkLabel      VARCHAR(160)  NOT NULL,
        PaMkSubLabel   VARCHAR(160)  NOT NULL DEFAULT '',
        PaMkPhase      INT           NOT NULL DEFAULT 0,
        PaMkSort       INT           NOT NULL DEFAULT 0,
        PaMkStatus     VARCHAR(10)   NOT NULL DEFAULT 'OPEN',
        PaMkPool       DECIMAL(12,2) NOT NULL DEFAULT 0,
        PaMkUpdated    DATETIME      NOT NULL,
        PaMkSettled    DATETIME      NULL,
        UNIQUE KEY uq_market (PaMkTournament, PaMkTeam, PaMkEvent, PaMkType, PaMkKey),
        KEY idx_status (PaMkTournament, PaMkStatus)
    ) $opt");

    // PaSeGroup : dans un duel, « A gagne » et « A 6-0 » ne sont pas des issues
    // exclusives — la seconde implique la première. Les probabilités sont donc
    // normalisées par groupe ('W' = vainqueur, 'S' = score exact), jamais ensemble.
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Selections (
        PaSeId        INT AUTO_INCREMENT PRIMARY KEY,
        PaSeMarket    INT           NOT NULL,
        PaSeGroup     VARCHAR(2)    NOT NULL DEFAULT 'W',
        PaSeCode      VARCHAR(24)   NOT NULL,
        PaSeLabel     VARCHAR(120)  NOT NULL,
        PaSeAthlete   INT           NOT NULL DEFAULT 0,
        PaSeProbModel DECIMAL(9,8)  NOT NULL DEFAULT 0,
        PaSeProb      DECIMAL(9,8)  NOT NULL DEFAULT 0,
        PaSeOdds      DECIMAL(7,2)  NOT NULL DEFAULT 0,
        PaSePool      DECIMAL(12,2) NOT NULL DEFAULT 0,
        PaSeResult    TINYINT       NOT NULL DEFAULT -1,
        PaSeSort      INT           NOT NULL DEFAULT 0,
        UNIQUE KEY uq_sel (PaSeMarket, PaSeCode)
    ) $opt");

    // PaBePoints fige la valeur du pronostic au moment où il est fait : miser tôt sur
    // un outsider doit rapporter ce qu'il valait à ce moment-là, même si tout le monde
    // suit ensuite. PaBeOdds conserve la difficulté correspondante, pour l'affichage.
    // PaBeSelection2/3 : uniquement pour QUAL_TIERCE (choix du 2e et du 3e qualifié —
    // le 1er est PaBeSelection). Un tiercé est UN pronostic à 3 noms, jamais 3 lignes.
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Bets (
        PaBeId         INT          AUTO_INCREMENT PRIMARY KEY,
        PaBeUser       INT          NOT NULL,
        PaBeMarket     INT          NOT NULL,
        PaBeSelection  INT          NOT NULL,
        PaBeSelection2 INT          NULL,
        PaBeSelection3 INT          NULL,
        PaBeOdds      DECIMAL(7,2) NOT NULL,
        PaBePoints    INT          NOT NULL DEFAULT 0,
        PaBePartial   INT          NOT NULL DEFAULT 0,
        PaBeStatus    VARCHAR(8)   NOT NULL DEFAULT 'PENDING',
        PaBePlaced    DATETIME     NOT NULL,
        PaBeSettled   DATETIME     NULL,
        UNIQUE KEY uq_one_per_market (PaBeUser, PaBeMarket),
        KEY idx_market (PaBeMarket, PaBeStatus),
        KEY idx_user (PaBeUser)
    ) $opt");

    prono_migrate();
}

/**
 * Ajouts de colonnes sur une installation déjà en place.
 * Idempotent : appelé à chaque chargement de la console.
 */
function prono_migrate(): void
{
    $db   = prono_db();
    $cols = array_column(prono_all('SHOW COLUMNS FROM PRONO_Config'), 'Field');

    $add = [
        // Ancre stable de la compétition : le ToId change à chaque réimport, pas le ToCode.
        'PaCfTourCode'    => "VARCHAR(30) NOT NULL DEFAULT ''",
        'PaCfPointsBase'  => 'INT NOT NULL DEFAULT 10',
        // Plafond de difficulté récompensée (× les points de base) : jusqu'ici la
        // constante PRONO_POINTS_CAP (25), invisible et non réglable — un pronostic à
        // 250 pts (base 10 par défaut) sans que l'organisateur ait pu le voir ni le
        // changer. Même valeur par défaut, désormais dans la console (réglages).
        'PaCfPointsCap'   => 'DECIMAL(6,2) NOT NULL DEFAULT 25.00',
        'PaCfScoring'     => "VARCHAR(10) NOT NULL DEFAULT 'ODDS'",
        // Fermeture des pronostics sans couper l'accès : les joueurs continuent de
        // consulter leurs pronostics et le classement.
        'PaCfBetsClosed'  => 'TINYINT NOT NULL DEFAULT 0',
        'PaCfDeadline'    => 'DATETIME NULL',
        // Épreuves fermées à la main, indépendamment du reste : les résultats
        // n'arrivent qu'une fois les archers revenus de la cible.
        'PaCfClosedEvents' => 'TEXT NULL',
        // Fermeture fine : liste de cellules (épreuve × type × phase) fermées à la main
        // ou par le coupe-circuit rapide, format « équipe:épreuve:type:phase » (0/1:code:
        // MATCH_WINNER|EVENT_WINNER|QUAL_TIERCE|QUAL_CUT:n° de phase, 0 si sans objet).
        'PaCfClosedCells'  => 'TEXT NULL',
        // Largeur des tranches de total à l'arc à poulies, en points.
        'PaCfBandWidth'    => 'INT NOT NULL DEFAULT 3',
        // Cette compétition entre-t-elle dans le classement général de la saison ?
        'PaCfSeason'       => 'TINYINT NOT NULL DEFAULT 1',
        'PaCfPublicUrl'   => "VARCHAR(255) NOT NULL DEFAULT ''",
        'PaCfPosterTitle' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'PaCfPosterText'  => 'TEXT NULL',
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $db->exec("ALTER TABLE PRONO_Config ADD COLUMN $col $def");
        }
    }

    // Retrait du coupe-circuit global des duels (v5) : remplacé par une fermeture
    // fine par cellule (épreuve × phase), pilotée depuis la nouvelle grille et par le
    // bouton rapide (qui ferme désormais la PROCHAINE phase de chaque épreuve selon
    // son horaire prévu, plutôt qu'un drapeau permanent). Les compétitions qui
    // l'avaient activé migrent vers la fermeture des duels actuellement ouverts, pour
    // ne rien rouvrir à tort — sans quoi la mise à jour rouvrirait silencieusement des
    // duels que l'organisateur avait fermés à la main.
    if (in_array('PaCfDuelsClosed', $cols, true)) {
        foreach (prono_all('SELECT PaCfTournament, PaCfClosedCells FROM PRONO_Config WHERE PaCfDuelsClosed = 1') as $row) {
            $tid   = (int) $row['PaCfTournament'];
            $cells = array_filter(explode('|', (string) $row['PaCfClosedCells']), 'strlen');
            foreach (prono_all(
                "SELECT DISTINCT PaMkTeam, PaMkEvent, PaMkPhase FROM PRONO_Markets
                 WHERE PaMkTournament = ? AND PaMkType = 'MATCH_WINNER' AND PaMkStatus <> 'SETTLED'",
                [$tid]) as $o) {
                $cells[] = ((int) $o['PaMkTeam']) . ':' . $o['PaMkEvent'] . ':MATCH_WINNER:' . (int) $o['PaMkPhase'];
            }
            prono_q('UPDATE PRONO_Config SET PaCfClosedCells = ? WHERE PaCfTournament = ?',
                [implode('|', array_unique($cells)), $tid]);
        }
        $db->exec('ALTER TABLE PRONO_Config DROP COLUMN PaCfDuelsClosed');
    }

    // Qualification simplifiée (v5) : meilleur score / qualification pour le tableau /
    // seuil remplacés par un tiercé (comme aux courses hippiques : ordre exact, ordre
    // quelconque, ou rien) sur les 3 premiers, une fourchette pour le score du premier
    // qualifié (QUAL_TOP1) et une fourchette pour le score du cut (dernier qualifié,
    // QUAL_CUT) — les fourchettes ne portent que sur ces deux valeurs, jamais sur le
    // 2e ou le 3e. Une compétition qui avait explicitement activé l'un des anciens
    // types migre vers les trois nouveaux, pour ne pas perdre la case cochée ; une
    // configuration vide (« tout est autorisé ») n'a rien à migrer.
    foreach (prono_all("SELECT PaCfTournament, PaCfMarkets FROM PRONO_Config
                        WHERE PaCfMarkets REGEXP 'QUAL_WINNER|QUAL_TOPN|QUAL_OU'") as $row) {
        $list = array_filter(explode('|', (string) $row['PaCfMarkets']), 'strlen');
        $list = array_diff($list, ['QUAL_WINNER', 'QUAL_TOPN', 'QUAL_OU']);
        foreach (['QUAL_TIERCE', 'QUAL_TOP1', 'QUAL_CUT'] as $new) {
            if (!in_array($new, $list, true)) $list[] = $new;
        }
        prono_q('UPDATE PRONO_Config SET PaCfMarkets = ? WHERE PaCfTournament = ?',
            [implode('|', $list), (int) $row['PaCfTournament']]);
    }
    // Les anciens marchés ne sont plus jamais reconstruits : les retirer une fois pour
    // ne pas laisser de marchés fantômes. Sans conséquence sur les points déjà acquis
    // (comptés dans PRONO_Scores, jamais recalculés depuis ces lignes) ; vérifié avant
    // d'écrire cette migration qu'aucun des deux n'a de ligne réglée ni de pronostic
    // posé dans la base réelle.
    if (prono_val("SELECT 1 FROM PRONO_Markets WHERE PaMkType IN ('QUAL_WINNER','QUAL_TOPN','QUAL_OU') LIMIT 1")) {
        prono_q("DELETE b FROM PRONO_Bets b INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
                 WHERE m.PaMkType IN ('QUAL_WINNER','QUAL_TOPN','QUAL_OU')");
        prono_q("DELETE s FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                 WHERE m.PaMkType IN ('QUAL_WINNER','QUAL_TOPN','QUAL_OU')");
        prono_q("DELETE FROM PRONO_Markets WHERE PaMkType IN ('QUAL_WINNER','QUAL_TOPN','QUAL_OU')");
    }

    // Tiercé qualification (v5) : un pronostic nomme 3 participants (1er/2e/3e) en un
    // seul geste, jamais 3 lignes indépendantes — sans quoi rien ne permettrait de
    // distinguer un tiercé dans l'ordre (gain maximum) d'un tiercé dans le désordre
    // (gain partiel, PaBePartial) au règlement.
    $bcols3 = array_column(prono_all('SHOW COLUMNS FROM PRONO_Bets'), 'Field');
    if (!in_array('PaBeSelection2', $bcols3, true)) {
        $db->exec('ALTER TABLE PRONO_Bets ADD COLUMN PaBeSelection2 INT NULL AFTER PaBeSelection');
        $db->exec('ALTER TABLE PRONO_Bets ADD COLUMN PaBeSelection3 INT NULL AFTER PaBeSelection2');
    }

    // Épreuves par équipes : la clé primaire d'Events est (EvCode, EvTeamEvent,
    // EvTournament) — un même code peut donc désigner une épreuve individuelle ET une
    // épreuve par équipes. Le code seul ne suffit pas à identifier un marché.
    $mcols = array_column(prono_all('SHOW COLUMNS FROM PRONO_Markets'), 'Field');
    if (!in_array('PaMkTeam', $mcols, true)) {
        $db->exec('ALTER TABLE PRONO_Markets ADD COLUMN PaMkTeam TINYINT NOT NULL DEFAULT 0 AFTER PaMkTournament');
        $db->exec('ALTER TABLE PRONO_Markets DROP INDEX uq_market');
        $db->exec('ALTER TABLE PRONO_Markets ADD UNIQUE KEY uq_market
                   (PaMkTournament, PaMkTeam, PaMkEvent, PaMkType, PaMkKey)');
    }

    // Passage du jeton unique par joueur (v1.0) aux comptes id/mot de passe.
    $ucols = array_column(prono_all('SHOW COLUMNS FROM PRONO_Users'), 'Field');
    if (!in_array('PaUsPass', $ucols, true)) {
        $db->exec("ALTER TABLE PRONO_Users ADD COLUMN PaUsPass VARCHAR(255) NOT NULL DEFAULT ''");
    }
    if (in_array('PaUsToken', $ucols, true)) {
        // Les sessions ouvertes sont conservées : on les bascule dans PRONO_Tokens.
        $db->exec("INSERT IGNORE INTO PRONO_Tokens (PaTkToken, PaTkUser, PaTkCreated, PaTkSeen)
                   SELECT PaUsToken, PaUsId, PaUsCreated, PaUsSeen
                   FROM PRONO_Users WHERE PaUsToken <> ''");
        $db->exec('ALTER TABLE PRONO_Users DROP COLUMN PaUsToken');
    }

    // Confidentialité des pronostics (v1.2) : PUBLIC (comportement d'avant ce
    // réglage, rien ne change par défaut), GROUPS (visible seulement par les groupes
    // du joueur) ou PRIVATE (personne).
    if (!in_array('PaUsPrivacy', $ucols, true)) {
        $db->exec("ALTER TABLE PRONO_Users ADD COLUMN PaUsPrivacy VARCHAR(10) NOT NULL DEFAULT 'PUBLIC'");
    }

    // Passage du système de mise (v1.x) au système de points : plus de solde de départ
    // ni de mise, on pronostique et on marque. Les pronostics déjà réglés sont convertis
    // pour que le classement reste cohérent.
    $bcols = array_column(prono_all('SHOW COLUMNS FROM PRONO_Bets'), 'Field');
    if (!in_array('PaBePoints', $bcols, true)) {
        $db->exec('ALTER TABLE PRONO_Bets ADD COLUMN PaBePoints INT NOT NULL DEFAULT 0 AFTER PaBeOdds');
        $db->exec("UPDATE PRONO_Bets SET PaBePoints = GREATEST(1, ROUND(10 * LEAST(PaBeOdds, 25)))
                   WHERE PaBeStatus = 'WON'");
    }
    foreach (['PaBeStake', 'PaBeWin'] as $col) {
        if (in_array($col, $bcols, true)) $db->exec("ALTER TABLE PRONO_Bets DROP COLUMN $col");
    }

    $ucols2 = array_column(prono_all('SHOW COLUMNS FROM PRONO_Users'), 'Field');
    if (!in_array('PaUsPoints', $ucols2, true)) {
        $db->exec('ALTER TABLE PRONO_Users ADD COLUMN PaUsPoints INT NOT NULL DEFAULT 0 AFTER PaUsPass');
        $db->exec('UPDATE PRONO_Users u SET u.PaUsPoints = (
                       SELECT IFNULL(SUM(b.PaBePoints), 0) FROM PRONO_Bets b WHERE b.PaBeUser = u.PaUsId)');
    }
    foreach (['PaUsCredit', 'PaUsStaked'] as $col) {
        if (in_array($col, $ucols2, true)) $db->exec("ALTER TABLE PRONO_Users DROP COLUMN $col");
    }

    $ccols = array_column(prono_all('SHOW COLUMNS FROM PRONO_Config'), 'Field');
    foreach (['PaCfStartCredit', 'PaCfMaxStake'] as $col) {
        if (in_array($col, $ccols, true)) $db->exec("ALTER TABLE PRONO_Config DROP COLUMN $col");
    }

    // Les masses de paris deviennent des nombres de pronostics : on repart à zéro,
    // le prochain passage du moteur les recompte.
    if (!in_array('PaBePoints', $bcols, true)) {
        $db->exec('UPDATE PRONO_Selections SET PaSePool = 0');
        $db->exec('UPDATE PRONO_Markets SET PaMkPool = 0');
    }

    // Fusion du duel et de son score exact dans un seul marché (v2.2). Pronostiquer
    // « 6-0 » désigne déjà le vainqueur : deux marchés séparés obligeaient à jouer
    // deux fois et permettaient de se contredire (A gagnant, et un score de 0-6).
    $scols = array_column(prono_all('SHOW COLUMNS FROM PRONO_Selections'), 'Field');
    if (!in_array('PaSeGroup', $scols, true)) {
        $db->exec("ALTER TABLE PRONO_Selections ADD COLUMN PaSeGroup VARCHAR(2) NOT NULL DEFAULT 'W' AFTER PaSeMarket");
    }
    $bcols2 = array_column(prono_all('SHOW COLUMNS FROM PRONO_Bets'), 'Field');
    if (!in_array('PaBePartial', $bcols2, true)) {
        $db->exec('ALTER TABLE PRONO_Bets ADD COLUMN PaBePartial INT NOT NULL DEFAULT 0 AFTER PaBePoints');
    }

    $pairs = prono_all("SELECT s.PaMkId AS src, w.PaMkId AS dst
                        FROM PRONO_Markets s
                        INNER JOIN PRONO_Markets w
                           ON w.PaMkTournament = s.PaMkTournament AND w.PaMkTeam = s.PaMkTeam
                          AND w.PaMkEvent = s.PaMkEvent AND w.PaMkKey = s.PaMkKey
                          AND w.PaMkType = 'MATCH_WINNER'
                        WHERE s.PaMkType = 'SET_SCORE'");
    foreach ($pairs as $p) {
        $src = (int) $p['src'];
        $dst = (int) $p['dst'];
        // Un joueur ayant pronostiqué les deux : le score est plus précis, il l'emporte.
        prono_q('DELETE b FROM PRONO_Bets b
                 INNER JOIN PRONO_Bets b2 ON b2.PaBeUser = b.PaBeUser AND b2.PaBeMarket = ?
                 WHERE b.PaBeMarket = ?', [$src, $dst]);
        prono_q("UPDATE PRONO_Selections SET PaSeMarket = ?, PaSeGroup = 'S' WHERE PaSeMarket = ?", [$dst, $src]);
        prono_q('UPDATE PRONO_Bets SET PaBeMarket = ? WHERE PaBeMarket = ?', [$dst, $src]);
        prono_q('DELETE FROM PRONO_Markets WHERE PaMkId = ?', [$src]);
    }
    // Marchés de score sans duel jumeau (cas théorique) : plus rien ne les alimente.
    if ($pairs || prono_val("SELECT 1 FROM PRONO_Markets WHERE PaMkType='SET_SCORE' LIMIT 1")) {
        prono_q("DELETE b FROM PRONO_Bets b INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
                 WHERE m.PaMkType = 'SET_SCORE'");
        prono_q("DELETE s FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                 WHERE m.PaMkType = 'SET_SCORE'");
        prono_q("DELETE FROM PRONO_Markets WHERE PaMkType = 'SET_SCORE'");
        // Les compteurs de pronostics doivent refléter ce qui reste.
        $db->exec('UPDATE PRONO_Users u SET u.PaUsBets = (
                       SELECT COUNT(*) FROM PRONO_Bets b WHERE b.PaBeUser = u.PaUsId)');
    }

    // Passage aux comptes globaux (v3) : un compte par joueur pour tout le serveur,
    // et les points comptés par compétition dans PRONO_Scores. Sans cela, un classement
    // de saison est impossible — rien ne relie deux inscriptions d'une compétition à
    // l'autre.
    $ucols3 = array_column(prono_all('SHOW COLUMNS FROM PRONO_Users'), 'Field');
    if (in_array('PaUsTournament', $ucols3, true)) {

        // 1. Les scores acquis deviennent une ligne par (joueur, compétition).
        $db->exec('INSERT IGNORE INTO PRONO_Scores (PaScUser, PaScTournament, PaScPoints, PaScBets, PaScWon)
                   SELECT PaUsId, PaUsTournament, PaUsPoints, PaUsBets, PaUsWon FROM PRONO_Users');

        // 2. Comptes rattachés à une compétition disparue de ianseo : ce sont des
        //    essais, la compétition n'existe plus et leurs pronostics ne mènent nulle
        //    part. On les retire avant de rendre le pseudo unique.
        $db->exec('DELETE b FROM PRONO_Bets b
                   INNER JOIN PRONO_Users u ON u.PaUsId = b.PaBeUser
                   LEFT  JOIN Tournament t ON t.ToId = u.PaUsTournament
                   WHERE t.ToId IS NULL');
        $db->exec('DELETE k FROM PRONO_Tokens k
                   INNER JOIN PRONO_Users u ON u.PaUsId = k.PaTkUser
                   LEFT  JOIN Tournament t ON t.ToId = u.PaUsTournament
                   WHERE t.ToId IS NULL');
        $db->exec('DELETE s FROM PRONO_Scores s
                   INNER JOIN PRONO_Users u ON u.PaUsId = s.PaScUser
                   LEFT  JOIN Tournament t ON t.ToId = u.PaUsTournament
                   WHERE t.ToId IS NULL');
        $db->exec('DELETE u FROM PRONO_Users u
                   LEFT JOIN Tournament t ON t.ToId = u.PaUsTournament
                   WHERE t.ToId IS NULL');

        // 3. Pseudos encore en double sur plusieurs compétitions vivantes : on garde le
        //    compte le mieux doté et on lui rattache tout le reste.
        foreach (prono_all('SELECT PaUsNick FROM PRONO_Users GROUP BY PaUsNick HAVING COUNT(*) > 1') as $d) {
            $ids = array_column(prono_all(
                'SELECT PaUsId FROM PRONO_Users WHERE PaUsNick = ? ORDER BY PaUsPoints DESC, PaUsId ASC',
                [$d['PaUsNick']]), 'PaUsId');
            $keep = (int) array_shift($ids);
            foreach ($ids as $old) {
                $old = (int) $old;
                prono_q('UPDATE PRONO_Bets   SET PaBeUser = ? WHERE PaBeUser = ?', [$keep, $old]);
                prono_q('UPDATE PRONO_Tokens SET PaTkUser = ? WHERE PaTkUser = ?', [$keep, $old]);
                prono_q('UPDATE IGNORE PRONO_Scores SET PaScUser = ? WHERE PaScUser = ?', [$keep, $old]);
                prono_q('DELETE FROM PRONO_Scores WHERE PaScUser = ?', [$old]);
                // Un mot de passe existant vaut mieux que pas de mot de passe.
                prono_q("UPDATE PRONO_Users k
                         INNER JOIN PRONO_Users o ON o.PaUsId = ?
                         SET k.PaUsPass = o.PaUsPass
                         WHERE k.PaUsId = ? AND k.PaUsPass = '' AND o.PaUsPass <> ''", [$old, $keep]);
                prono_q('DELETE FROM PRONO_Users WHERE PaUsId = ?', [$old]);
            }
        }

        $db->exec('ALTER TABLE PRONO_Users DROP INDEX uq_nick');
        $db->exec('ALTER TABLE PRONO_Users ADD UNIQUE KEY uq_nick (PaUsNick)');
        foreach (['PaUsTournament', 'PaUsPoints', 'PaUsBets', 'PaUsWon'] as $col) {
            if (in_array($col, $ucols3, true)) $db->exec("ALTER TABLE PRONO_Users DROP COLUMN $col");
        }
    }

    // Groupes de joueurs (v1.2) : classements parallèles à l'intérieur d'un cercle
    // (club, famille...). Rejoindre un groupe se fait par nom + mot de passe, comme
    // un compte joueur — PgPass est donc une empreinte bcrypt, jamais le mot de passe
    // en clair (voir prono_group_join(), lib/groups.php).
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_Groups (
        PgId       INT AUTO_INCREMENT PRIMARY KEY,
        PgName     VARCHAR(40)  NOT NULL,
        PgPass     VARCHAR(255) NOT NULL,
        PgOwner    INT          NOT NULL,
        PgCreated  DATETIME     NOT NULL,
        UNIQUE KEY uq_name (PgName)
    ) $opt");
    $db->exec("CREATE TABLE IF NOT EXISTS PRONO_GroupMembers (
        PgmGroup   INT      NOT NULL,
        PgmUser    INT      NOT NULL,
        PgmJoined  DATETIME NOT NULL,
        PRIMARY KEY (PgmGroup, PgmUser),
        KEY idx_user (PgmUser)
    ) $opt");

    // Jetons orphelins : un identifiant d'auto-incrément peut être réattribué après
    // suppression d'un joueur, ce qui rendrait un vieux jeton valable sur un compte
    // qui n'a rien à voir.
    $db->exec('DELETE t FROM PRONO_Tokens t
               LEFT JOIN PRONO_Users u ON u.PaUsId = t.PaTkUser
               WHERE u.PaUsId IS NULL');

    // Ancrage rétroactif sur le ToCode, pour que les compétitions déjà configurées
    // survivent à leur prochain réimport.
    $db->exec("UPDATE PRONO_Config c
               INNER JOIN Tournament t ON t.ToId = c.PaCfTournament
               SET c.PaCfTourCode = t.ToCode
               WHERE c.PaCfTourCode = ''");

    // Configuration pointant sur un tournoi disparu (réimport antérieur à cette
    // version) : plus rien à servir, on la referme pour que la face publique ne
    // reste pas bloquée dessus. Joueurs et pronostics ne sont pas touchés.
    $db->exec("UPDATE PRONO_Config c
               LEFT JOIN Tournament t ON t.ToId = c.PaCfTournament
               SET c.PaCfOpen = 0
               WHERE t.ToId IS NULL AND c.PaCfOpen = 1");
}

function prono_tables_exist(): bool
{
    try {
        prono_val('SELECT 1 FROM PRONO_Config LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
