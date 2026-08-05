<?php
/**
 * Orchestration : synchronisation des marchés, cotes, règlement, snapshot.
 *
 * Le poller ne recalcule le modèle que si les données ianseo ont bougé (empreinte
 * CRC sur Finals + Qualifications). Entre deux changements, seules les cotes sont
 * rafraîchies à partir de la masse des pronostics — c'est ce qui permet de tourner
 * toutes les 5 secondes sans charger la machine.
 */
require_once __DIR__ . '/markets.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/groups.php';

const PRONO_POLL_THROTTLE = 4;     // secondes entre deux passages effectifs
const PRONO_POOL_SCALE    = 25.0;  // nombre de pronostics à partir duquel la foule pèse autant que le modèle
const PRONO_ODDS_MIN      = 1.01;
const PRONO_ODDS_MAX      = 200.0;
// Repli si PaCfPointsCap est absent (installation pas encore migrée) — même valeur
// que le défaut de la colonne, voir prono_points_cap().
const PRONO_POINTS_CAP_DEFAULT = 25.0;

/** Plafond de difficulté récompensée (× les points de base), réglable en console. */
function prono_points_cap(array $cfg): float
{
    $cap = (float) ($cfg['PaCfPointsCap'] ?? PRONO_POINTS_CAP_DEFAULT);
    return $cap > 0 ? $cap : PRONO_POINTS_CAP_DEFAULT;
}

/**
 * Un pronostic est-il modifiable après coup ?
 *
 * Oui pour les duels : leur marché se ferme dès la première volée, donc changer d'avis
 * avant le début du match n'apporte aucune information.
 *
 * Oui pour le tiercé de qualification : contrairement au vainqueur d'épreuve, son
 * marché se verrouille tôt (dernière volée de qualification, `PRONO_QUAL_LOCK_ARROWS`)
 * — changer d'avis avant ce verrouillage n'a pas plus de sens que pour un duel, on ne
 * peut pas « attendre de voir » un résultat encore inconnu.
 *
 * Non pour les autres marchés « au long cours » (vainqueur d'épreuve, score du 1er
 * qualifié/du cut) : ils restent ouverts pendant que la compétition se joue. Autoriser
 * le changement reviendrait à laisser attendre l'élimination de son favori pour se
 * reporter sur le suivant — le jeu n'aurait plus d'intérêt.
 */
function prono_changeable(string $type): bool
{
    return $type === 'MATCH_WINNER' || $type === 'QUAL_TIERCE';
}

/**
 * Valeur d'un pronostic, en points.
 *
 * ODDS (défaut) : indexée sur la difficulté. Un pronostic à 50/50 vaut les points de
 * base, un favori évident en vaut à peine plus, un outsider beaucoup. C'est la cote
 * qui sert de multiplicateur — voir juste, quand personne n'y croyait, doit payer.
 *
 * FLAT : forfait par type de pronostic, indépendant de la difficulté, pour qui préfère un
 * barème lisible d'un coup d'œil.
 */
function prono_points(string $type, string $group, float $odds, array $cfg): int
{
    $base = max(1, (int) ($cfg['PaCfPointsBase'] ?? 10));

    if (($cfg['PaCfScoring'] ?? 'ODDS') === 'FLAT') {
        $mult = $group === 'S' ? 3.0 : ([
            'MATCH_WINNER'  => 1.0, 'EVENT_WINNER' => 2.5,
            'QUAL_TIERCE'   => 4.0, 'QUAL_TOP1' => 1.5, 'QUAL_CUT' => 1.5,
        ][$type] ?? 1.0);
        return (int) max(1, round($base * $mult));
    }

    return (int) max(1, round($base * min($odds, prono_points_cap($cfg))));
}

/**
 * Points de repli d'un pronostic de score : ceux qu'aurait rapportés le simple
 * pronostic du vainqueur. Annoncer 6-0 et voir le match finir 6-2, c'est s'être
 * trompé sur le score mais pas sur l'archer — on ne repart pas les mains vides.
 */
function prono_partial_points(int $market, int $athlete, array $cfg): int
{
    $odds = prono_val("SELECT PaSeOdds FROM PRONO_Selections
                       WHERE PaSeMarket = ? AND PaSeGroup = 'W' AND PaSeAthlete = ?",
        [$market, $athlete], 0);
    return $odds ? prono_points('MATCH_WINNER', 'W', (float) $odds, $cfg) : 0;
}

/**
 * Valeur d'un tiercé, comme aux courses hippiques : l'ordre exact (les 3 noms dans
 * le bon ordre) rapporte le maximum, le même trio dans le désordre rapporte moins,
 * et se tromper sur l'un des trois noms ne rapporte rien. Les deux probabilités
 * viennent de Harville (prono_harville_triple), à partir des seules probabilités de
 * victoire (rang 1) déjà mémorisées dans les issues du groupe 'R1' — jamais
 * recalculées à partir de zéro, pour rester cohérentes avec ce que le joueur a vu
 * au moment de choisir.
 *
 * @return array{order:int, any:int} points (ordre exact, même trio désordonné)
 */
function prono_tierce_points(int $market, int $a, int $b, int $c, array $cfg): array
{
    $rows = prono_all(
        "SELECT PaSeAthlete, PaSeProb FROM PRONO_Selections
         WHERE PaSeMarket = ? AND PaSeGroup = 'R1'", [$market]);
    $winProb = [];
    foreach ($rows as $r) $winProb[(int) $r['PaSeAthlete']] = (float) $r['PaSeProb'];
    foreach ([$a, $b, $c] as $id) if (!isset($winProb[$id])) $winProb[$id] = 0.0;

    return prono_tierce_points_from($winProb, $a, $b, $c, $cfg);
}

/**
 * Cœur du calcul de prono_tierce_points(), à partir d'une carte [athlète => P(1er)]
 * déjà en mémoire (pas de requête SQL) : sert aussi à prono_build_snapshot() pour
 * afficher l'étendue de points du tiercé (le plus sûr / le plus risqué), sans
 * requêter la base pour chaque combinaison testée.
 *
 * @return array{order:int, any:int} points (ordre exact, même trio désordonné)
 */
function prono_tierce_points_from(array $winProb, int $a, int $b, int $c, array $cfg): array
{
    $cap   = prono_points_cap($cfg);
    $p     = prono_harville_triple($winProb, $a, $b, $c);
    $order = prono_points('QUAL_TIERCE', 'W', $p['order'] > 0 ? 1 / $p['order'] : $cap, $cfg);

    // Le désordre rapporte moins que l'ordre exact — mécaniquement en barème ODDS
    // (le même trio dans n'importe quel ordre est plus probable, donc moins payant),
    // et par convention en barème FLAT (un tiers du plein tarif, comme au tiercé).
    $any = ($cfg['PaCfScoring'] ?? 'ODDS') === 'FLAT'
        ? (int) max(1, round($order / 3))
        : prono_points('QUAL_TIERCE', 'W', $p['anyOrder'] > 0 ? 1 / $p['anyOrder'] : $cap, $cfg);

    return ['order' => $order, 'any' => $any];
}

/**
 * Configuration d'une compétition, augmentée de deux valeurs calculées :
 *
 *  PaCfBetsOpen  peut-on encore pronostiquer ? (ouvert, non fermé à la main, échéance
 *                non dépassée)
 *  PaCfLeft      secondes avant la fermeture programmée, NULL s'il n'y en a pas
 *
 * L'échéance est comparée par MySQL, jamais par PHP : ianseo force PHP en UTC alors
 * que l'organisateur raisonne en heure murale, et les deux faces du module n'ont pas
 * le même fuseau. NOW() est la seule horloge commune.
 */
function prono_config_sql(): string
{
    return 'SELECT c.*, NOW() AS PaCfNow,
                   (c.PaCfOpen = 1 AND c.PaCfBetsClosed = 0
                    AND (c.PaCfDeadline IS NULL OR c.PaCfDeadline > NOW())) AS PaCfBetsOpen,
                   IF(c.PaCfDeadline IS NULL, NULL,
                      TIMESTAMPDIFF(SECOND, NOW(), c.PaCfDeadline)) AS PaCfLeft
            FROM PRONO_Config c WHERE c.PaCfTournament = ?';
}

function prono_config(int $tid): array
{
    $c = prono_one(prono_config_sql(), [$tid]);
    if (!$c) {
        prono_q('INSERT INTO PRONO_Config (PaCfTournament, PaCfTourCode, PaCfUpdated)
                 VALUES (?, ?, NOW())', [$tid, prono_tour_code($tid)]);
        $c = prono_one(prono_config_sql(), [$tid]);
    } elseif (($c['PaCfTourCode'] ?? '') === '') {
        prono_q('UPDATE PRONO_Config SET PaCfTourCode = ? WHERE PaCfTournament = ?',
            [prono_tour_code($tid), $tid]);
        $c['PaCfTourCode'] = prono_tour_code($tid);
    }
    return $c;
}

// ─── Survie aux réimports de la compétition ──────────────────────────────────
//
// Réimporter une compétition dans ianseo ne met pas à jour le tournoi existant :
// elle en crée un nouveau (nouveau ToId, nouveaux EnId) et supprime l'ancien. Sans
// précaution, tous les pronostics déjà pris deviendraient orphelins à chaque import —
// c'est-à-dire à chaque fois qu'on rafraîchit les résultats.
//
// Deux ancres rendent les données réutilisables :
//   - la compétition est identifiée par son ToCode (stable), pas par son ToId ;
//   - les archers le sont par leur licence (EnCode), pas par leur EnId.
// Il ne reste qu'à faire suivre les identifiants de tournoi, ce que fait prono_adopt().

function prono_tour_code(int $tid): string
{
    return (string) prono_val('SELECT ToCode FROM Tournament WHERE ToId = ?', [$tid], '');
}

/** Le tournoi le plus récent portant ce code, 0 si aucun. */
function prono_tour_by_code(string $code): int
{
    if ($code === '') return 0;
    return (int) prono_val('SELECT ToId FROM Tournament WHERE ToCode = ? ORDER BY ToId DESC LIMIT 1',
        [$code], 0);
}

/**
 * Déplace joueurs, pronostics et marchés de $from vers $to.
 * Les marchés sont des données dérivées : ceux de la destination sont écartés, ils
 * seront reconstruits au prochain passage du moteur.
 */
function prono_rebind(int $from, int $to): void
{
    $db = prono_db();
    $db->beginTransaction();
    try {
        prono_q('DELETE s FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                 WHERE m.PaMkTournament = ?', [$to]);
        prono_q('DELETE FROM PRONO_Markets WHERE PaMkTournament = ?', [$to]);
        prono_q('DELETE FROM PRONO_Config WHERE PaCfTournament = ?', [$to]);
        prono_q('DELETE FROM PRONO_Scores WHERE PaScTournament = ?', [$to]);

        prono_q('UPDATE PRONO_Markets SET PaMkTournament = ? WHERE PaMkTournament = ?', [$to, $from]);
        // Les points acquis restent attachés à leur compétition, qui change seulement
        // d'identifiant : un réimport ne doit rien faire perdre au classement.
        prono_q('UPDATE PRONO_Scores SET PaScTournament = ? WHERE PaScTournament = ?', [$to, $from]);
        prono_q('UPDATE PRONO_Config  SET PaCfTournament = ?, PaCfTourCode = ?, PaCfUpdated = NOW()
                 WHERE PaCfTournament = ?', [$to, prono_tour_code($to), $from]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Détecte un réimport et récupère les pronostics de l'import précédent.
 *
 * @return array{moved:bool, msg:string}
 */
function prono_adopt(int $tid): array
{
    $code = prono_tour_code($tid);
    if ($code === '') return ['moved' => false, 'msg' => ''];

    $old = prono_one(
        'SELECT PaCfTournament FROM PRONO_Config
         WHERE PaCfTourCode = ? AND PaCfTournament <> ? ORDER BY PaCfUpdated DESC LIMIT 1',
        [$code, $tid]);
    if (!$old) return ['moved' => false, 'msg' => ''];

    $from = (int) $old['PaCfTournament'];

    // On ne récupère que si la destination est vierge : écraser des pronostics déjà pris
    // sur le nouvel import serait pire que le problème qu'on résout.
    $destBets = (int) prono_val(
        'SELECT COUNT(*) FROM PRONO_Bets b INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
         WHERE m.PaMkTournament = ?', [$tid], 0);
    $destUsers = (int) prono_val('SELECT COUNT(*) FROM PRONO_Scores WHERE PaScTournament = ?', [$tid], 0);

    if ($destBets > 0 || $destUsers > 0) {
        return ['moved' => false, 'msg' =>
            "Des pronostics de l'import précédent (compétition n° $from) n'ont pas été repris : "
            . "cette compétition a déjà $destUsers joueur(s) et $destBets pronostic(s)."];
    }

    $users = (int) prono_val('SELECT COUNT(*) FROM PRONO_Scores WHERE PaScTournament = ?', [$from], 0);
    $bets  = (int) prono_val(
        'SELECT COUNT(*) FROM PRONO_Bets b INNER JOIN PRONO_Markets m ON m.PaMkId = b.PaBeMarket
         WHERE m.PaMkTournament = ?', [$from], 0);

    prono_rebind($from, $tid);

    return ['moved' => true, 'msg' =>
        "Réimport détecté : $users joueur(s) et $bets pronostic(s) récupérés depuis l'import précédent."];
}

/** Empreinte des données ianseo : change dès qu'une flèche est saisie. */
function prono_data_hash(int $tid): string
{
    $f = prono_val(
        "SELECT CONCAT(COUNT(*), ':', IFNULL(SUM(CRC32(CONCAT_WS('|',
            FinMatchNo, FinAthlete, FinScore, FinSetScore, FinSetPointsByEnd, FinWinLose, FinIrmType))), 0))
         FROM Finals WHERE FinTournament = ?", [$tid], '0:0');

    $q = prono_val(
        "SELECT CONCAT(COUNT(*), ':', IFNULL(SUM(CRC32(CONCAT_WS('|',
            q.QuId, q.QuScore, q.QuClRank, q.QuIrmType,
            LENGTH(q.QuD1Arrowstring), LENGTH(q.QuD2Arrowstring),
            LENGTH(q.QuD3Arrowstring), LENGTH(q.QuD4Arrowstring)))), 0))
         FROM Qualifications q
         INNER JOIN Entries e ON e.EnId = q.QuId
         WHERE e.EnTournament = ?", [$tid], '0:0');

    return md5($f . '#' . $q);
}

// ─── Écriture des marchés ────────────────────────────────────────────────────

function prono_sync(int $tid, array $cfg): void
{
    $built = prono_build($tid, $cfg);
    if (!$built) return;

    $existing = [];
    foreach (prono_all('SELECT PaMkId, PaMkTeam, PaMkEvent, PaMkType, PaMkKey, PaMkStatus
                        FROM PRONO_Markets WHERE PaMkTournament = ?', [$tid]) as $m) {
        $existing[$m['PaMkTeam'] . '|' . $m['PaMkEvent'] . '|' . $m['PaMkType'] . '|' . $m['PaMkKey']] = $m;
    }

    foreach ($built as $mk) {
        $team = (int) ($mk['team'] ?? 0);
        $k    = $team . '|' . $mk['event'] . '|' . $mk['type'] . '|' . $mk['key'];
        $old  = $existing[$k] ?? null;

        // Un marché réglé ne se rouvre jamais.
        $status = ($old && $old['PaMkStatus'] === 'SETTLED') ? 'SETTLED' : $mk['status'];

        if ($old) {
            $mid = (int) $old['PaMkId'];
            prono_q('UPDATE PRONO_Markets SET PaMkLabel = ?, PaMkSubLabel = ?, PaMkPhase = ?,
                        PaMkSort = ?, PaMkStatus = ?, PaMkUpdated = NOW()
                     WHERE PaMkId = ?',
                [$mk['label'], $mk['sub'], $mk['phase'], $mk['sort'], $status, $mid]);
        } else {
            prono_q('INSERT INTO PRONO_Markets (PaMkTournament, PaMkTeam, PaMkEvent, PaMkType,
                        PaMkKey, PaMkLabel, PaMkSubLabel, PaMkPhase, PaMkSort, PaMkStatus, PaMkUpdated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$tid, $team, $mk['event'], $mk['type'], $mk['key'], $mk['label'], $mk['sub'],
                 $mk['phase'], $mk['sort'], $status]);
            $mid = (int) prono_db()->lastInsertId();
        }

        $known = [];
        foreach (prono_all('SELECT PaSeId, PaSeCode FROM PRONO_Selections WHERE PaSeMarket = ?', [$mid]) as $s) {
            $known[$s['PaSeCode']] = (int) $s['PaSeId'];
        }

        // Marchés dont le jeu d'issues se réduit au fil de la compétition (un archer
        // éliminé ne peut plus gagner l'épreuve, ou sort du focus borné du tiercé) :
        // les issues stockées qui ne sont plus proposées sont marquées perdantes.
        // Sans ça elles s'accumulent, restent affichées, et surtout faussent la
        // normalisation des probabilités.
        if (in_array($mk['type'], ['EVENT_WINNER', 'QUAL_TIERCE'], true) && $mk['sels']) {
            $codes = array_column($mk['sels'], 'code');
            $ph    = implode(',', array_fill(0, count($codes), '?'));
            prono_q("UPDATE PRONO_Selections
                     SET PaSeProbModel = 0, PaSeProb = 0, PaSeOdds = 0, PaSeResult = 0
                     WHERE PaSeMarket = ? AND PaSeCode NOT IN ($ph) AND PaSeResult <> 1",
                array_merge([$mid], $codes));
        }

        // Tranches devenues caduques — typiquement après un changement de largeur, ou
        // simplement parce que la fourchette plausible s'est déplacée d'un passage à
        // l'autre. Celles qui portent un pronostic sont conservées : leur code inscrit
        // ses propres bornes, elles resteront jugées sur la tranche promise au joueur.
        // Format du code différent selon le marché : « A138-140 »/« B138-140 » pour
        // un duel à l'arc à poulies (lettre = archer) ; « LO:432 »/« MID:281:328 »/
        // « HI:328 » pour le score du premier qualifié / du cut (3 issues fixes,
        // ancrées sur le classement national — v5.0.3 ; l'ancien format numérique
        // « 705-709 » sans préfixe, encore présent sur des lignes antérieures à cette
        // version, reste reconnu pour que la purge continue de les nettoyer).
        if ($mk['type'] === 'MATCH_WINNER' && $mk['sels']) {
            $codes = array_column($mk['sels'], 'code');
            $ph    = implode(',', array_fill(0, count($codes), '?'));
            prono_q("DELETE s FROM PRONO_Selections s
                     WHERE s.PaSeMarket = ? AND s.PaSeGroup = 'S'
                       AND s.PaSeCode REGEXP '^[AB][0-9]' AND s.PaSeCode NOT IN ($ph)
                       AND NOT EXISTS (SELECT 1 FROM PRONO_Bets b WHERE b.PaBeSelection = s.PaSeId)",
                array_merge([$mid], $codes));
        }
        if (in_array($mk['type'], ['QUAL_TOP1', 'QUAL_CUT'], true) && $mk['sels']) {
            $codes = array_column($mk['sels'], 'code');
            $ph    = implode(',', array_fill(0, count($codes), '?'));
            prono_q("DELETE s FROM PRONO_Selections s
                     WHERE s.PaSeMarket = ?
                       AND s.PaSeCode REGEXP '^(-?[0-9]+-[0-9]|LO:|MID:|HI:)' AND s.PaSeCode NOT IN ($ph)
                       AND NOT EXISTS (SELECT 1 FROM PRONO_Bets b WHERE b.PaBeSelection = s.PaSeId)",
                array_merge([$mid], $codes));
        }

        foreach ($mk['sels'] as $sel) {
            $prob = max(0.0, min(1.0, (float) $sel['prob']));
            $grp  = $sel['group'] ?? 'W';
            if (isset($known[$sel['code']])) {
                prono_q('UPDATE PRONO_Selections SET PaSeGroup = ?, PaSeLabel = ?, PaSeAthlete = ?,
                            PaSeProbModel = ?, PaSeResult = ?, PaSeSort = ? WHERE PaSeId = ?',
                    [$grp, $sel['label'], (int) $sel['athlete'], $prob, (int) $sel['result'],
                     (int) $sel['sort'], $known[$sel['code']]]);
            } else {
                prono_q('INSERT INTO PRONO_Selections (PaSeMarket, PaSeGroup, PaSeCode, PaSeLabel,
                            PaSeAthlete, PaSeProbModel, PaSeProb, PaSeResult, PaSeSort)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$mid, $grp, $sel['code'], $sel['label'], (int) $sel['athlete'], $prob, $prob,
                     (int) $sel['result'], (int) $sel['sort']]);
            }
        }

        unset($existing[$k]);   // ce qui reste après la boucle n'est plus jamais construit
    }

    // Marchés qui ne sont plus jamais reconstruits (une épreuve retirée de la
    // sélection, un tiercé retombé sous le seuil de 2 candidats crédibles par
    // position, un cut qui n'en est plus un après un réimport...). Jamais un marché
    // réglé (l'historique et les points déjà acquis restent), et jamais un marché sur
    // lequel un pronostic a déjà été posé — auquel cas mieux vaut le laisser tel
    // quel que faire disparaître un pronostic sans explication.
    foreach ($existing as $old) {
        if ($old['PaMkStatus'] === 'SETTLED') continue;
        $mid = (int) $old['PaMkId'];
        if (prono_val('SELECT 1 FROM PRONO_Bets WHERE PaBeMarket = ? LIMIT 1', [$mid])) continue;
        prono_q('DELETE FROM PRONO_Selections WHERE PaSeMarket = ?', [$mid]);
        prono_q('DELETE FROM PRONO_Markets WHERE PaMkId = ?', [$mid]);
    }
}

/**
 * Applique la marge sur le *gain* et non sur la mise.
 *
 * Une marge proportionnelle (cote = (1-m)/p) est destructrice sur les gros
 * favoris : à p = 0,95 la cote équitable vaut 1,053 et 5 % de marge la ramène à
 * 1,00 — le pronostic ne rapporte plus rien. On cherche donc le facteur k tel que
 * cote' = 1 + k·(cote − 1) et que la somme des 1/cote' vaille exactement 1 + marge.
 * Le favori garde un gain réel, la marge se reporte sur les outsiders (biais
 * favori/outsider classique des bookmakers).
 */
function prono_apply_margin(array $probs, float $margin): array
{
    $fair = [];
    foreach ($probs as $k => $p) $fair[$k] = 1 / max($p, 1e-6);

    // somme(1/cote') décroît quand k croît : dichotomie directe
    $lo = 1e-4; $hi = 1.0;
    for ($i = 0; $i < 50; $i++) {
        $mid = ($lo + $hi) / 2;
        $s = 0.0;
        foreach ($fair as $o) $s += 1 / (1 + $mid * ($o - 1));
        if ($s > 1 + $margin) $lo = $mid; else $hi = $mid;
    }
    $k = ($lo + $hi) / 2;

    $out = [];
    foreach ($fair as $key => $o) {
        $out[$key] = max(PRONO_ODDS_MIN, min(PRONO_ODDS_MAX, 1 + $k * ($o - 1)));
    }
    return $out;
}

/**
 * Cotes : mélange de la probabilité modèle et de la masse réellement pronostiquée.
 * Le poids du marché croît avec le nombre de pronostics — à trois joueurs, le
 * modèle décide ; à pleine charge, la foule reprend la main.
 */
function prono_refresh_odds(int $tid, float $margin): void
{
    // PaSeResult = 0 sur un marché non réglé = issue éliminée : on l'exclut de la
    // normalisation, sinon elle absorbe une part de probabilité qui n'existe plus.
    $rows = prono_all(
        'SELECT s.PaSeId, s.PaSeMarket, s.PaSeGroup, s.PaSeProbModel, s.PaSePool
         FROM PRONO_Selections s
         INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
         WHERE m.PaMkTournament = ? AND m.PaMkStatus <> ? AND s.PaSeResult <> 0',
        [$tid, 'SETTLED']);

    // Normalisation par (marché, groupe) : dans un duel, « A gagne » et « A 6-0 »
    // ne s'excluent pas — les mélanger donnerait une somme de probabilités de 2.
    $byMarket = [];
    foreach ($rows as $r) $byMarket[$r['PaSeMarket'] . '|' . $r['PaSeGroup']][] = $r;

    $pools = [];
    foreach ($byMarket as $key => $sels) {
        $mid = (int) strtok($key, '|');
        $pool = 0.0;
        foreach ($sels as $s) $pool += (float) $s['PaSePool'];
        $w = $pool > 0 ? $pool / ($pool + PRONO_POOL_SCALE) : 0.0;

        $probs = [];
        $sum   = 0.0;
        foreach ($sels as $s) {
            $pm = (float) $s['PaSeProbModel'];
            $pk = $pool > 0 ? ((float) $s['PaSePool']) / $pool : $pm;
            $p  = (1 - $w) * $pm + $w * $pk;
            $p  = max($p, 1e-4);
            $probs[(int) $s['PaSeId']] = $p;
            $sum += $p;
        }
        if ($sum <= 0) continue;

        foreach ($probs as $sid => $p) $probs[$sid] = $p / $sum;
        $odds = prono_apply_margin($probs, $margin);

        foreach ($probs as $sid => $p) {
            prono_q('UPDATE PRONO_Selections SET PaSeProb = ?, PaSeOdds = ? WHERE PaSeId = ?',
                [round($p, 8), round($odds[$sid], 2), $sid]);
        }
        // Le total du marché cumule ses groupes, écrits ici en une seule fois.
        $pools[$mid] = ($pools[$mid] ?? 0) + $pool;
    }

    foreach ($pools as $mid => $pool) {
        prono_q('UPDATE PRONO_Markets SET PaMkPool = ? WHERE PaMkId = ?', [round($pool, 2), $mid]);
    }
}

// ─── Règlement ───────────────────────────────────────────────────────────────

/**
 * Recompte les votes par issue à partir des pronostics réellement présents.
 * Les compteurs sont tenus à l'incrément ; après une suppression de compte, seul un
 * recomptage garantit qu'ils restent exacts.
 */
function prono_recount_pools(int $tid): void
{
    // Un tiercé référence 3 sélections (PaBeSelection/2/3) : les deux dernières
    // valent toujours NULL hors QUAL_TIERCE, donc sans effet ailleurs.
    prono_q('UPDATE PRONO_Selections s
             INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
             SET s.PaSePool = (SELECT COUNT(*) FROM PRONO_Bets b WHERE b.PaBeSelection  = s.PaSeId)
                            + (SELECT COUNT(*) FROM PRONO_Bets b WHERE b.PaBeSelection2 = s.PaSeId)
                            + (SELECT COUNT(*) FROM PRONO_Bets b WHERE b.PaBeSelection3 = s.PaSeId)
             WHERE m.PaMkTournament = ?', [$tid]);
}

/**
 * Supprime un joueur et tout ce qui lui est attaché.
 * Les points déjà attribués disparaissent avec lui ; les compteurs de votes sont
 * recomptés pour que les valeurs affichées restent justes.
 */
function prono_delete_user(int $uid, int $tid = 0): void
{
    if (!prono_val('SELECT 1 FROM PRONO_Users WHERE PaUsId = ?', [$uid])) return;

    $db = prono_db();
    $db->beginTransaction();
    try {
        // Un compte supprimé « quitte » aussi ses groupes (transfert de propriété au
        // membre restant le mieux classé, ou suppression du groupe s'il n'en reste
        // aucun) — avant les DELETE ci-dessous, pendant que le compte existe encore.
        prono_group_user_removed($uid);
        prono_q('DELETE FROM PRONO_Tokens WHERE PaTkUser = ?', [$uid]);
        prono_q('DELETE FROM PRONO_Bets   WHERE PaBeUser = ?', [$uid]);
        prono_q('DELETE FROM PRONO_Scores WHERE PaScUser = ?', [$uid]);
        prono_q('DELETE FROM PRONO_Users  WHERE PaUsId   = ?', [$uid]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    if ($tid) prono_recount_pools($tid);
}

/**
 * Attribue les points des marchés réglés.
 *
 * Un pronostic juste rapporte les points figés au moment où il a été fait ; un
 * pronostic faux ne coûte rien — on ne perd jamais de points. Si aucune issue n'est
 * gagnante (IRM, match annulé, résultat hors des issues proposées), le marché est
 * annulé sans conséquence pour personne.
 */
function prono_settle(int $tid): int
{
    $markets = prono_all(
        "SELECT PaMkId, PaMkType FROM PRONO_Markets
         WHERE PaMkTournament = ? AND PaMkStatus = 'SETTLED' AND PaMkSettled IS NULL", [$tid]);

    $done = 0;
    foreach ($markets as $m) {
        $mid  = (int) $m['PaMkId'];
        $sels = prono_all('SELECT PaSeId, PaSeGroup, PaSeAthlete, PaSeResult
                           FROM PRONO_Selections WHERE PaSeMarket = ?', [$mid]);
        if (!$sels) continue;

        $undecided = false;
        $winners   = [];
        $champions = [];   // participants réellement vainqueurs, pour le crédit partiel
        $byGroup   = [];   // athlète vainqueur de chaque groupe (R1/R2/R3 du tiercé)
        foreach ($sels as $s) {
            if ((int) $s['PaSeResult'] < 0) $undecided = true;
            if ((int) $s['PaSeResult'] === 1) {
                $winners[] = (int) $s['PaSeId'];
                $grp = $s['PaSeGroup'] ?? 'W';
                if ($grp === 'W') $champions[] = (int) $s['PaSeAthlete'];
                $byGroup[$grp] = (int) $s['PaSeAthlete'];
            }
        }
        if ($undecided) continue;

        $db = prono_db();
        $db->beginTransaction();
        try {
            if ((string) $m['PaMkType'] === 'QUAL_TIERCE') {
                prono_settle_tierce($mid, $tid, $byGroup);
            } else {
                $bets = prono_all(
                    "SELECT b.PaBeId, b.PaBeUser, b.PaBeSelection, b.PaBePoints, b.PaBePartial,
                            s.PaSeAthlete, s.PaSeGroup
                     FROM PRONO_Bets b
                     INNER JOIN PRONO_Selections s ON s.PaSeId = b.PaBeSelection
                     WHERE b.PaBeMarket = ? AND b.PaBeStatus = 'PENDING'", [$mid]);

                foreach ($bets as $b) {
                    if (!$winners) {
                        $status = 'VOID';
                        $points = 0;
                    } elseif (in_array((int) $b['PaBeSelection'], $winners, true)) {
                        $status = 'WON';
                        $points = (int) $b['PaBePoints'];
                    } elseif (($b['PaSeGroup'] ?? 'W') === 'S'
                           && in_array((int) $b['PaSeAthlete'], $champions, true)
                           && (int) $b['PaBePartial'] > 0) {
                        // Score manqué mais bon vainqueur : on garde les points du duel.
                        $status = 'WON';
                        $points = (int) $b['PaBePartial'];
                    } else {
                        $status = 'LOST';
                        $points = 0;
                    }

                    prono_q("UPDATE PRONO_Bets SET PaBeStatus = ?, PaBePoints = ?, PaBeSettled = NOW()
                             WHERE PaBeId = ?", [$status, $points, (int) $b['PaBeId']]);

                    if ($status === 'WON') {
                        // Les points s'accumulent par compétition : le classement du jour
                        // en lit une ligne, celui de la saison les additionne.
                        prono_q('INSERT INTO PRONO_Scores (PaScUser, PaScTournament, PaScPoints, PaScWon)
                                 VALUES (?, ?, ?, 1)
                                 ON DUPLICATE KEY UPDATE PaScPoints = PaScPoints + ?, PaScWon = PaScWon + 1',
                            [(int) $b['PaBeUser'], $tid, $points, $points]);
                    }
                }
            }

            prono_q('UPDATE PRONO_Markets SET PaMkSettled = NOW() WHERE PaMkId = ?', [$mid]);
            $db->commit();
            $done++;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    return $done;
}

/**
 * Règlement d'un tiercé : ordre exact (les 3 picks tombent chacun sur le bon
 * groupe), même trio dans le désordre (crédit partiel), ou rien. $byGroup donne
 * l'athlète réellement vainqueur de chaque groupe ('R1'/'R2'/'R3'), déjà résolu par
 * l'appelant. Sans vainqueur avéré sur les 3 groupes (qualification annulée, IRM
 * générale...), le marché est remboursé comme les autres.
 */
function prono_settle_tierce(int $mid, int $tid, array $byGroup): void
{
    $actual = [$byGroup['R1'] ?? 0, $byGroup['R2'] ?? 0, $byGroup['R3'] ?? 0];
    $void   = in_array(0, $actual, true);

    $bets = prono_all(
        'SELECT b.PaBeId, b.PaBeUser, b.PaBePoints, b.PaBePartial,
                s1.PaSeAthlete AS a1, s2.PaSeAthlete AS a2, s3.PaSeAthlete AS a3
         FROM PRONO_Bets b
         INNER JOIN PRONO_Selections s1 ON s1.PaSeId = b.PaBeSelection
         LEFT  JOIN PRONO_Selections s2 ON s2.PaSeId = b.PaBeSelection2
         LEFT  JOIN PRONO_Selections s3 ON s3.PaSeId = b.PaBeSelection3
         WHERE b.PaBeMarket = ? AND b.PaBeStatus = ?', [$mid, 'PENDING']);

    foreach ($bets as $b) {
        $picked = [(int) $b['a1'], (int) $b['a2'], (int) $b['a3']];

        // PaBePoints/PaBePartial ont été figés à la prise du pronostic (comme pour
        // tout le reste du module) : le règlement ne fait que choisir lequel des deux
        // s'applique, jamais recalculer à partir des cotes actuelles.
        if ($void) {
            $status = 'VOID';
            $points = 0;
        } elseif ($picked === $actual) {
            $status = 'WON';
            $points = (int) $b['PaBePoints'];
        } elseif (!array_diff($picked, $actual) && !array_diff($actual, $picked)) {
            // Même trio (les 3 noms), ordre différent.
            $status = 'WON';
            $points = (int) $b['PaBePartial'];
        } else {
            $status = 'LOST';
            $points = 0;
        }

        prono_q("UPDATE PRONO_Bets SET PaBeStatus = ?, PaBePoints = ?, PaBeSettled = NOW()
                 WHERE PaBeId = ?", [$status, $points, (int) $b['PaBeId']]);

        if ($status === 'WON') {
            prono_q('INSERT INTO PRONO_Scores (PaScUser, PaScTournament, PaScPoints, PaScWon)
                     VALUES (?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE PaScPoints = PaScPoints + ?, PaScWon = PaScWon + 1',
                [(int) $b['PaBeUser'], $tid, $points, $points]);
        }
    }
}

// ─── Snapshot servi à la face publique ───────────────────────────────────────

function prono_snapshot_path(int $tid): string
{
    return prono_data_dir() . '/snapshot_' . $tid . '.json';
}

function prono_build_snapshot(int $tid, array $cfg): array
{
    $rows = prono_all(
        "SELECT m.PaMkId, m.PaMkTeam, m.PaMkEvent, m.PaMkType, m.PaMkKey, m.PaMkLabel, m.PaMkSubLabel,
                m.PaMkPhase, m.PaMkSort, m.PaMkStatus, m.PaMkPool,
                s.PaSeId, s.PaSeGroup, s.PaSeCode, s.PaSeLabel, s.PaSeAthlete, s.PaSeOdds,
                s.PaSeProb, s.PaSePool, s.PaSeResult, s.PaSeSort
         FROM PRONO_Markets m
         INNER JOIN PRONO_Selections s ON s.PaSeMarket = m.PaMkId
         WHERE m.PaMkTournament = ? AND m.PaMkStatus <> 'SETTLED' AND s.PaSeResult <> 0
         ORDER BY m.PaMkPhase DESC, m.PaMkSort ASC, m.PaMkId ASC, s.PaSeSort ASC", [$tid]);

    $markets = [];
    foreach ($rows as $r) {
        $mid = (int) $r['PaMkId'];
        if (!isset($markets[$mid])) {
            $markets[$mid] = [
                'id'     => $mid,
                // Même index que prono_events() : « T: » distingue une épreuve par
                // équipes d'une épreuve individuelle qui porterait le même code.
                'ev'     => ((int) $r['PaMkTeam'] ? 'T:' : '') . $r['PaMkEvent'],
                'team'   => (int) $r['PaMkTeam'],
                'type'   => $r['PaMkType'],
                'fixed'  => prono_changeable($r['PaMkType']) ? 0 : 1,
                'label'  => $r['PaMkLabel'],
                'sub'    => $r['PaMkSubLabel'],
                'status' => $r['PaMkStatus'],
                'pool'   => (float) $r['PaMkPool'],
                'sels'   => [],
            ];
        }
        // `ath` et `code` permettent au client de regrouper les issues d'un même
        // marché par archer (score exact : 12 issues illisibles en colonne unique).
        $markets[$mid]['sels'][] = [
            'id'    => (int) $r['PaSeId'],
            'grp'   => $r['PaSeGroup'],
            'label' => $r['PaSeLabel'],
            'code'  => $r['PaSeCode'],
            'ath'   => (int) $r['PaSeAthlete'],
            // Points à gagner si l'issue se réalise — c'est ce que voit le joueur.
            'pts'   => prono_points($r['PaMkType'], $r['PaSeGroup'], (float) $r['PaSeOdds'], $cfg),
            'prob'  => round((float) $r['PaSeProb'], 4),
            'votes' => (int) $r['PaSePool'],
        ];
    }

    $recent = prono_all(
        "SELECT m.PaMkLabel, m.PaMkSubLabel, s.PaSeLabel
         FROM PRONO_Markets m
         INNER JOIN PRONO_Selections s ON s.PaSeMarket = m.PaMkId AND s.PaSeResult = 1
         WHERE m.PaMkTournament = ? AND m.PaMkStatus = 'SETTLED' AND m.PaMkSettled IS NOT NULL
         ORDER BY m.PaMkSettled DESC LIMIT 12", [$tid]);

    // Classement du jour : les scores de cette seule compétition.
    $board = prono_all(
        'SELECT u.PaUsNick, s.PaScPoints AS PaUsPoints, s.PaScBets AS PaUsBets, s.PaScWon AS PaUsWon
         FROM PRONO_Scores s INNER JOIN PRONO_Users u ON u.PaUsId = s.PaScUser
         WHERE s.PaScTournament = ?
         ORDER BY s.PaScPoints DESC, s.PaScWon DESC, s.PaScBets ASC LIMIT 30', [$tid]);

    // Classement de la saison : somme des compétitions marquées comme y comptant.
    $season = prono_all(
        'SELECT u.PaUsNick, SUM(s.PaScPoints) AS PaUsPoints, SUM(s.PaScBets) AS PaUsBets,
                SUM(s.PaScWon) AS PaUsWon, COUNT(*) AS PaUsEvents
         FROM PRONO_Scores s
         INNER JOIN PRONO_Users u  ON u.PaUsId = s.PaScUser
         INNER JOIN PRONO_Config c ON c.PaCfTournament = s.PaScTournament AND c.PaCfSeason = 1
         GROUP BY u.PaUsId, u.PaUsNick
         ORDER BY SUM(s.PaScPoints) DESC, SUM(s.PaScWon) DESC LIMIT 30');

    $tour = prono_tournament($tid);

    // Nom lisible des épreuves : sert au filtre et aux sections de la face publique.
    $events = [];
    foreach (prono_events($tid) as $key => $ev) {
        foreach ($markets as $m) {
            if ($m['ev'] === $key) {
                $events[$key] = $ev['name'] . ($ev['team'] ? ' (équipes)' : '');
                break;
            }
        }
    }

    return [
        't'       => time(),
        'tour'    => $tour['name'] ?? '',
        'title'   => (string) ($cfg['PaCfTitle'] ?: ($tour['name'] ?? 'Pronostics')),
        'open'    => (int) $cfg['PaCfOpen'],
        // Pronostics fermés : la page reste consultable (mes pronostics, classement),
        // seule la prise de nouveaux pronostics est bloquée.
        'betsOpen' => (int) ($cfg['PaCfBetsOpen'] ?? 1),
        'left'     => isset($cfg['PaCfLeft']) && $cfg['PaCfLeft'] !== null
                        ? (int) $cfg['PaCfLeft'] : null,
        'base'    => (int) $cfg['PaCfPointsBase'],
        // Servent au client à estimer en direct les points d'un tiercé pendant la
        // saisie (avant validation), avec exactement la même formule que
        // prono_points()/prono_tierce_points_from() — pas une approximation.
        'cap'     => prono_points_cap($cfg),
        'scoring' => (string) ($cfg['PaCfScoring'] ?? 'ODDS'),
        'events'  => $events,
        'markets' => array_values($markets),
        'recent'  => $recent,
        'board'   => $board,
        'season'  => $season,
        'inseason' => (int) ($cfg['PaCfSeason'] ?? 1),
    ];
}

function prono_write_snapshot(int $tid, array $cfg): array
{
    $snap = prono_build_snapshot($tid, $cfg);
    $path = prono_snapshot_path($tid);
    $tmp  = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($snap, JSON_UNESCAPED_UNICODE));
    @rename($tmp, $path);                       // remplacement atomique
    prono_purge_snapshots($tid);
    return $snap;
}

/**
 * Supprime les snapshots des compétitions qui n'ont plus de configuration : chaque
 * réimport en laissait un derrière lui, et un vieux fichier servi par erreur affiche
 * un classement d'un autre temps.
 */
function prono_purge_snapshots(int $keep): void
{
    $live = array_map('intval', array_column(
        prono_all('SELECT PaCfTournament FROM PRONO_Config'), 'PaCfTournament'));
    $live[] = $keep;

    foreach (glob(prono_data_dir() . '/snapshot_*.json') ?: [] as $f) {
        if (preg_match('/snapshot_(\d+)\.json$/', $f, $m) && !in_array((int) $m[1], $live, true)) {
            @unlink($f);
        }
    }
}

// ─── Boucle ──────────────────────────────────────────────────────────────────

/**
 * Un passage du moteur. Protégé par un verrou fichier : plusieurs requêtes
 * simultanées ne déclenchent qu'un seul calcul, les autres repartent aussitôt.
 *
 * @return array compte-rendu (skipped / recalculé / réglé)
 */
function prono_poll(int $tid, bool $force = false): array
{
    $lockFile = prono_data_dir() . '/poll_' . $tid . '.lock';
    $fh = @fopen($lockFile, 'c+');
    if (!$fh) return ['ok' => false, 'error' => 'verrou impossible'];

    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return ['ok' => true, 'skipped' => 'concurrent'];
    }

    try {
        $state = json_decode((string) stream_get_contents($fh), true) ?: [];
        $now   = time();
        if (!$force && isset($state['at']) && ($now - (int) $state['at']) < PRONO_POLL_THROTTLE) {
            return ['ok' => true, 'skipped' => 'throttle'];
        }

        prono_match_arrows(-1);          // vide le cache de flèches du passage précédent
        $cfg     = prono_config($tid);
        $hash    = prono_data_hash($tid);
        $changed = $force || ($state['hash'] ?? '') !== $hash;

        if ($changed) prono_sync($tid, $cfg);
        prono_refresh_odds($tid, (float) $cfg['PaCfMargin']);
        $settled = prono_settle($tid);
        prono_write_snapshot($tid, $cfg);

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(['at' => $now, 'hash' => $hash]));
        fflush($fh);

        return ['ok' => true, 'recomputed' => $changed, 'settled' => $settled];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Compétition sur laquelle les pronostics sont ouverts (une seule à la fois).
 *
 * Suit automatiquement les réimports : si le tournoi enregistré a été remplacé par
 * un nouvel import du même ToCode, la face publique bascule dessus sans intervention.
 */
function prono_active_tournament(): int
{
    $row = prono_one("SELECT PaCfTournament, PaCfTourCode FROM PRONO_Config
                      WHERE PaCfOpen = 1 ORDER BY PaCfUpdated DESC LIMIT 1");
    if (!$row) return 0;

    $tid  = (int) $row['PaCfTournament'];
    $code = (string) $row['PaCfTourCode'];
    if ($code === '') return $tid;

    $live = prono_tour_by_code($code);
    if ($live && $live !== $tid) {
        $r = prono_adopt($live);
        if ($r['moved']) return $live;
    }
    return $tid;
}

// ─── Fermeture rapide (barre flottante) ──────────────────────────────────────
//
// Ferme, pour chaque épreuve, la PROCHAINE phase non terminée dès que son horaire
// prévu — lu dans FinSchedule, la table native de ianseo alimentée par l'écran
// Final/Individual/ManSchedule.php, pas par un module — est passé ou tombe dans
// l'heure qui vient. Une seule phase par épreuve : si la 1/16 et la 1/8 tirent
// toutes les deux dans l'heure mais que la 1/16 n'a pas encore de résultat, seule la
// 1/16 se ferme (la 1/8 n'est pas encore « la prochaine »). Sans horaire saisi pour
// une épreuve, repli sur l'ancien critère : déjà commencée (une flèche réelle).

const PRONO_QUICKCLOSE_WINDOW = 60;   // minutes

/**
 * Phase « prochaine » d'une épreuve à partir de ses slots : la plus grande valeur de
 * phase (donc le tour le plus proche du premier) dont au moins un match réel n'est
 * pas encore 'done'. 0 si le tableau est entièrement joué, vide, ou pas encore tiré.
 */
function prono_next_phase(array $slots): int
{
    $next = 0;
    foreach ($slots as $n => $s) {
        if ($n % 2 !== 0) continue;
        $b = $slots[$n + 1] ?? null;
        if (!$b || $s['athlete'] <= 0 || $b['athlete'] <= 0) continue;
        if (prono_match_state($s, $b) === 'done') continue;
        $phase = prono_phase_of_slot($n);
        if ($phase > $next) $next = $phase;
    }
    return $next;
}

/** La phase $phase a-t-elle au moins un match déjà en cours ou terminé ? (repli sans horaire) */
function prono_phase_started(array $slots, int $phase): bool
{
    foreach ($slots as $n => $s) {
        if ($n % 2 !== 0 || prono_phase_of_slot($n) !== $phase) continue;
        $b = $slots[$n + 1] ?? null;
        if (!$b || $s['athlete'] <= 0 || $b['athlete'] <= 0) continue;
        if (prono_match_state($s, $b) !== 'todo') return true;
    }
    return false;
}

/**
 * Horaire prévu (FinSchedule) de la phase $phase d'une épreuve : le plus tôt des
 * matchs qui la composent (slots [2×phase, 4×phase-1], même encodage que le reste du
 * module). NULL si aucun horaire n'a été saisi pour cette phase.
 */
function prono_phase_schedule(int $tid, string $event, bool $team, int $phase): ?string
{
    return prono_val(
        "SELECT MIN(TIMESTAMP(FSScheduledDate, FSScheduledTime))
         FROM FinSchedule
         WHERE FSTournament = ? AND FSEvent = ? AND FSTeamEvent = ?
           AND FSMatchNo BETWEEN ? AND ?",
        [$tid, $event, $team ? 1 : 0, 2 * $phase, 4 * $phase - 1]);
}

/** L'horaire prévu est-il passé, ou dans l'heure qui vient ? Comparaison MySQL. */
function prono_phase_due(string $scheduled): bool
{
    return (bool) prono_val(
        'SELECT ? <= DATE_ADD(NOW(), INTERVAL ? MINUTE)',
        [$scheduled, PRONO_QUICKCLOSE_WINDOW]);
}

/**
 * Calcule et applique la fermeture rapide sur toutes les épreuves (individuelles et
 * par équipes) de la compétition. Idempotent : rejouable sans effet sur ce qui est
 * déjà fermé.
 *
 * @return string[] libellés des phases fermées par cet appel (pour confirmation)
 */
function prono_quickclose(int $tid): array
{
    $cfg    = prono_config($tid);
    $closed = array_filter(explode('|', (string) ($cfg['PaCfClosedCells'] ?? '')), 'strlen');
    $done   = [];

    foreach (prono_events($tid) as $ev) {
        if ($ev['firstPhase'] <= 0) continue;             // pas de tableau à éliminations
        $slots = prono_slots($tid, $ev['code'], $ev['team']);
        if (!$slots) continue;

        $phase = prono_next_phase($slots);
        if ($phase <= 0) continue;                        // joué, ou pas encore tiré

        $team    = $ev['team'] ? 1 : 0;
        $cellKey = $team . ':' . $ev['code'] . ':MATCH_WINNER:' . $phase;
        if (in_array($cellKey, $closed, true)) continue;   // déjà fermée

        $scheduled = prono_phase_schedule($tid, $ev['code'], $ev['team'], $phase);
        $due = $scheduled !== null ? prono_phase_due($scheduled) : prono_phase_started($slots, $phase);
        if (!$due) continue;

        $closed[] = $cellKey;
        $done[]   = $ev['name'] . ($ev['team'] ? ' (équipes)' : '') . ' · ' . prono_phase_label($phase, 2 * $phase);

        // Le vainqueur d'épreuve se ferme avec la toute première phase du tableau.
        if ($phase === $ev['firstPhase']) {
            $ewKey = $team . ':' . $ev['code'] . ':EVENT_WINNER:0';
            if (!in_array($ewKey, $closed, true)) $closed[] = $ewKey;
        }
    }

    if ($done) {
        prono_q('UPDATE PRONO_Config SET PaCfClosedCells = ?, PaCfUpdated = NOW() WHERE PaCfTournament = ?',
            [implode('|', array_unique($closed)), $tid]);
    }
    return $done;
}
