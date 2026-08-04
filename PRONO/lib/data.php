<?php
/**
 * Lecture des données ianseo pour le module PRONO (strictement en SELECT).
 *
 * Les colonnes VARCHAR custom joignant des colonnes VARCHAR ianseo portent un
 * COLLATE utf8mb4_unicode_ci explicite (MySQL 8 : les tables ianseo peuvent être
 * en utf8mb4_0900_ai_ci → erreur 1267 sinon).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/model.php';

/** Classes d'âge mineures : filtrées par défaut (PaCfAdultOnly). */
function prono_is_minor_class(string $class): bool
{
    return (bool) preg_match('/^U(0[0-9]|1[0-8])/i', trim($class));
}

function prono_tournament(int $tid): ?array
{
    $r = prono_one('SELECT ToName, ToNumDist, ToNumEnds, ToMaxDistScore, ToType
                    FROM Tournament WHERE ToId = ?', [$tid]);
    if (!$r) return null;

    $numDist = max(1, (int) $r['ToNumDist']);
    $maxDist = (int) $r['ToMaxDistScore'];

    // Nombre de flèches par distance : ianseo le connaît déjà exactement (volées ×
    // flèches par volée), par distance, dans DistanceInformation — inutile de l'estimer
    // depuis ToMaxDistScore (faux dès qu'une flèche vaut autre chose que 10, ex. 6 en
    // campagne) ni depuis ToNumEnds (compte les volées de la distance ENTIÈRE dans
    // certains formats mais du round ENTIER dans d'autres : vérifié faux sur un TAE réel,
    // ToNumEnds=12 pour 2 distances de 6 volées de 6 flèches chacune). Une distance porte
    // une ligne par départ (session) ; le format est le même pour tout le monde sur une
    // même distance, on ne lit donc que la première rencontrée (MIN, pas SUM).
    // Gardé PAR distance (et pas seulement en somme) : certaines catégories ne tirent
    // qu'une partie des distances de la compétition (voir prono_class_dist_count()).
    $rows = prono_all(
        "SELECT DiDistance, MIN(DiEnds) AS ends, MIN(DiArrows) AS arrows
         FROM DistanceInformation
         WHERE DiTournament = ? AND DiType = 'Q'
         GROUP BY DiDistance", [$tid]);

    $distArrows = [];
    foreach ($rows as $row) $distArrows[(int) $row['DiDistance']] = ((int) $row['ends']) * ((int) $row['arrows']);
    $planned = array_sum($distArrows);

    if ($planned <= 0) {
        // Repli si la table n'est pas renseignée (formats hérités ou compétition mal
        // configurée) : estimation prudente qui ne peut que sous-estimer, corrigée dès
        // la première flèche saisie par la longueur réelle des chaînes.
        $ends    = max(1, (int) $r['ToNumEnds']);
        $perDist = max($maxDist > 0 ? (int) round($maxDist / 10) : 0, $ends * 3);
        $seen    = (int) prono_val(
            'SELECT MAX(LENGTH(q.QuD1Arrowstring)) FROM Qualifications q
             INNER JOIN Entries e ON e.EnId = q.QuId WHERE e.EnTournament = ?', [$tid], 0);
        if ($seen > $perDist) $perDist = $seen;
        $planned = $numDist * $perDist;
        for ($d = 1; $d <= $numDist; $d++) $distArrows[$d] = $perDist;
    }

    // Valeur maximale d'une flèche pour cette compétition (10 en extérieur/salle, 6
    // en campagne...) : sert uniquement à amorcer la loi neutre du plateau tant que
    // personne n'a encore tiré (paris_field_prior()) — sans ça, un profil par défaut
    // pensé pour une flèche à 10 points project un score presque double du maximum
    // réellement atteignable en campagne.
    $perDistArrows = $numDist > 0 ? $planned / $numDist : 0;
    $maxArrow = ($maxDist > 0 && $perDistArrows > 0) ? (int) round($maxDist / $perDistArrows) : 10;
    $maxArrow = max(1, min(20, $maxArrow));

    return [
        'id'         => $tid,
        'name'       => (string) $r['ToName'],
        'toType'     => (int) $r['ToType'],
        'numDist'    => $numDist,
        'numEnds'    => (int) $r['ToNumEnds'],
        'distArrows' => $distArrows,   // [n° de distance => flèches de CETTE distance]
        'planned'    => $planned,       // repli tournoi-large, si aucune règle par classe
        'maxArrow'   => $maxArrow,
    ];
}

/**
 * Nombre de distances ("parcours") réellement tirées par une division+classe donnée,
 * d'après TournamentDistances — ianseo y encode des règles PAR CATÉGORIE (en campagne
 * par exemple, seules certaines catégories jeunes tirent 2 parcours quand les adultes
 * n'en tirent qu'un), une distinction que DistanceInformation ne porte pas (elle est
 * tournoi-large, sans colonne classe). Sans ceci, le nombre de flèches prévues — et donc
 * le score maximum projeté — d'une catégorie adulte se retrouve doublé par erreur :
 * confirmé sur une compétition réelle en campagne (2 distances au niveau du tournoi,
 * mais une seule pour les catégories adultes -- 864 projeté au lieu de 432).
 *
 * Même correspondance que le cœur ianseo (Common/Lib/ScorecardsLib.php) :
 * CONCAT(EnDivision, EnClass) LIKE TdClasses, filtré par ToType. Si plusieurs motifs
 * correspondent, le plus spécifique (le plus long) l'emporte.
 *
 * @return int Nombre de distances actives, ou 0 si aucune règle ne matche (l'appelant
 *             retombe alors sur $tour['numDist']).
 */
function prono_class_dist_count(int $tid, int $toType, string $division, string $class): int
{
    static $cache = [];
    $concat = trim($division) . trim($class);
    $key = $tid . '|' . $toType . '|' . $concat;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $row = prono_one(
        "SELECT Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8
         FROM TournamentDistances
         WHERE TdTournament = ? AND TdType = ? AND ? LIKE TdClasses
         ORDER BY LENGTH(TdClasses) DESC LIMIT 1",
        [$tid, $toType, $concat]);

    if (!$row) return $cache[$key] = 0;

    $n = 0;
    foreach ($row as $v) {
        $v = trim((string) $v);
        if ($v !== '' && $v !== '-') $n++;
    }
    return $cache[$key] = max(1, $n);
}

/** Flèches prévues en qualification pour une division+classe (voir prono_class_dist_count()). */
function prono_class_planned(array $tour, string $division, string $class): int
{
    $nd = prono_class_dist_count($tour['id'], $tour['toType'] ?? 0, $division, $class);
    if ($nd <= 0) $nd = $tour['numDist'];   // pas de règle connue : repli tournoi-large

    $default = $tour['numDist'] > 0 ? $tour['planned'] / $tour['numDist'] : 0;
    $planned = 0;
    for ($d = 1; $d <= $nd; $d++) $planned += $tour['distArrows'][$d] ?? $default;
    return (int) round($planned);
}

/**
 * Épreuves de la compétition, individuelles et par équipes.
 *
 * La clé primaire d'Events est (EvCode, EvTeamEvent, EvTournament) : un même code
 * peut désigner une épreuve individuelle et une épreuve par équipes. L'index renvoyé
 * les distingue donc par « T:code ».
 */
function prono_events(int $tid): array
{
    $rows = prono_all('SELECT EvCode, EvEventName, EvTeamEvent, EvMatchMode,
                              EvFinalFirstPhase, EvElimType
                       FROM Events WHERE EvTournament = ?
                       ORDER BY EvTeamEvent, EvCode', [$tid]);
    $out = [];
    foreach ($rows as $r) {
        $team = ((int) $r['EvTeamEvent']) !== 0;
        $out[($team ? 'T:' : '') . $r['EvCode']] = [
            'code'       => (string) $r['EvCode'],
            'name'       => (string) $r['EvEventName'],
            'team'       => $team,
            'sets'       => ((int) $r['EvMatchMode']) === 1,   // 1 = sets, 0 = cumul
            'firstPhase' => (int) $r['EvFinalFirstPhase'],
            'elimType'   => (int) $r['EvElimType'],
        ];
    }
    return $out;
}

/** Participants d'une épreuve : archers, ou équipes selon le type d'épreuve. */
function prono_participants(int $tid, array $ev, array $tour): array
{
    return $ev['team']
        ? prono_teams($tid, $ev['code'], $tour)
        : prono_archers($tid, $ev['code'], $tour);
}

/**
 * Archers d'une épreuve, avec leur profil de flèches en qualification.
 *
 * counts  : histogramme des valeurs de flèches réellement tirées
 * shot    : flèches tirées, left : flèches restantes en qualification
 */
function prono_archers(int $tid, string $event, array $tour): array
{
    $cols = [];
    for ($d = 1; $d <= min(8, $tour['numDist']); $d++) $cols[] = "q.QuD{$d}Arrowstring AS a$d";
    $arrowCols = $cols ? ', ' . implode(', ', $cols) : '';

    $rows = prono_all(
        "SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass, e.EnCountry,
                c.CoName, c.CoCode,
                q.QuScore, q.QuClRank, q.QuIrmType $arrowCols
         FROM Individuals i
         INNER JOIN Entries e ON e.EnId = i.IndId AND e.EnTournament = i.IndTournament
         LEFT  JOIN Qualifications q ON q.QuId = i.IndId
         LEFT  JOIN Countries c ON c.CoId = e.EnCountry AND c.CoTournament = e.EnTournament
         WHERE i.IndTournament = ? AND i.IndEvent = ? AND e.EnAthlete = 1",
        [$tid, $event]
    );

    $matchArrows = prono_match_arrows($tid);
    $out = [];
    foreach ($rows as $r) {
        $strings = [];
        for ($d = 1; $d <= min(8, $tour['numDist']); $d++) {
            if (isset($r["a$d"])) $strings[] = (string) $r["a$d"];
        }
        $counts = prono_arrow_counts($strings);
        $shot   = prono_counts_total($counts);   // flèches de qualification seules

        // Les flèches de match affinent le profil, sans entrer dans `shot` : ce
        // compteur sert aux marchés de qualification (flèches restantes à tirer).
        foreach (($matchArrows[(int) $r['EnId']] ?? []) as $v => $n) $counts[$v] += $n;

        // EnId change à chaque réimport de la compétition ; EnCode (numéro de licence)
        // survit. C'est donc lui qui identifie un archer dans les marchés et les pronostics.
        // Unique au sein d'une épreuve : les rares doublons d'EnCode correspondent à
        // des inscriptions hors épreuve individuelle (IndEvent nul), écartées ici.
        $stable = trim((string) $r['EnCode']);
        if ($stable === '') $stable = '#' . (int) $r['EnId'];

        // Flèches prévues : par division+classe (TournamentDistances), pas le total
        // tournoi-large — certaines catégories ne tirent qu'une partie des distances.
        $planned = prono_class_planned($tour, (string) $r['EnDivision'], (string) $r['EnClass']);

        $out[(int) $r['EnId']] = [
            'id'     => (int) $r['EnId'],
            'key'    => $stable,
            'name'   => trim($r['EnFirstName'] . ' ' . $r['EnName']),
            'club'   => (string) ($r['CoName'] ?? ''),
            'code'   => (string) ($r['CoCode'] ?? ''),
            'class'  => (string) $r['EnClass'],
            'score'  => (int) $r['QuScore'],
            'rank'   => (int) $r['QuClRank'],
            'irm'    => (int) $r['QuIrmType'],
            'counts' => $counts,
            'shot'   => $shot,
            'left'   => max(0, $planned - $shot),
        ];
    }
    return $out;
}

/**
 * Équipes d'une épreuve, avec le profil de flèches de chacun de leurs membres.
 *
 * Une équipe est identifiée par (club, n° d'équipe) — un club peut en aligner
 * plusieurs. L'identifiant interne est synthétique (CoId × 100 + SubTeam) pour rester
 * un entier, comme un EnId ; la clé stable au réimport est « <n° de club>:<n° d'équipe> ».
 *
 * `members` conserve l'histogramme de chaque archer séparément : la volée d'équipe
 * est la somme de 2 flèches par archer, sa loi est donc la convolution des lois
 * individuelles, pas celle d'un tireur moyen.
 */
function prono_teams(int $tid, string $event, array $tour): array
{
    $rows = prono_all(
        'SELECT t.TeCoId, t.TeSubTeam, t.TeScore, t.TeRank, t.TeIrmType, c.CoName, c.CoCode
         FROM Teams t
         LEFT JOIN Countries c ON c.CoId = t.TeCoId AND c.CoTournament = t.TeTournament
         WHERE t.TeTournament = ? AND t.TeEvent = ?', [$tid, $event]);
    if (!$rows) return [];

    $nd   = min(8, $tour['numDist']);
    $cols = [];
    for ($d = 1; $d <= $nd; $d++) $cols[] = "q.QuD{$d}Arrowstring AS a$d";
    $arrowCols = $cols ? ', ' . implode(', ', $cols) : '';

    $members = prono_all(
        "SELECT tc.TcCoId, tc.TcSubTeam, tc.TcId, e.EnDivision, e.EnClass $arrowCols
         FROM TeamComponent tc
         LEFT JOIN Qualifications q ON q.QuId = tc.TcId
         LEFT JOIN Entries e ON e.EnId = tc.TcId
         WHERE tc.TcTournament = ? AND tc.TcEvent = ?", [$tid, $event]);

    $matchArrows = prono_match_arrows($tid);
    $byTeam = $classes = $divisions = [];
    foreach ($members as $m) {
        $strings = [];
        for ($d = 1; $d <= $nd; $d++) if (isset($m["a$d"])) $strings[] = (string) $m["a$d"];
        $tk = (int) $m['TcCoId'] . '-' . (int) $m['TcSubTeam'];
        $qual = prono_arrow_counts($strings);
        $prof = $qual;
        // Les flèches tirées en individuel comptent aussi pour le profil de l'équipier,
        // mais pas pour le décompte des flèches de qualification restantes.
        foreach (($matchArrows[(int) $m['TcId']] ?? []) as $v => $n) $prof[$v] += $n;
        // Indexé par TcId : une composition déclarée deux fois ne compte qu'une fois.
        $byTeam[$tk][(int) $m['TcId']] = ['qual' => $qual, 'prof' => $prof];
        // La catégorie d'âge (et l'arme) de l'équipe est celle de son premier membre
        // rencontré : c'est elle que regardent le filtre « catégories adultes » et le
        // nombre de flèches prévues par distance (TournamentDistances).
        if (!isset($classes[$tk]) && !empty($m['EnClass'])) {
            $classes[$tk]   = (string) $m['EnClass'];
            $divisions[$tk] = (string) ($m['EnDivision'] ?? '');
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $co  = (int) $r['TeCoId'];
        $sub = (int) $r['TeSubTeam'];
        $mem = array_values($byTeam[$co . '-' . $sub] ?? []);

        $counts = $qualCounts = array_fill(0, PRONO_MAXV + 1, 0);
        $prof   = [];
        foreach ($mem as $c) {
            foreach ($c['prof'] as $v => $n) $counts[$v] += $n;
            foreach ($c['qual'] as $v => $n) $qualCounts[$v] += $n;
            $prof[] = $c['prof'];
        }
        $mem = $prof;   // la loi de volée se compose des profils, pas des seules qualifs

        $club   = trim((string) ($r['CoCode'] ?? ''));
        $name   = trim((string) ($r['CoName'] ?? '')) ?: ('#' . $co);
        if ($sub > 0) $name .= ' ' . ($sub + 1);
        $size   = max(1, count($mem));
        $shot   = prono_counts_total($qualCounts);   // qualification seule

        // Flèches prévues : par division+classe (TournamentDistances), pas le total
        // tournoi-large — voir prono_archers() pour la même correction côté individuel.
        $tk      = $co . '-' . $sub;
        $planned = prono_class_planned($tour, $divisions[$tk] ?? '', $classes[$tk] ?? '');

        $out[$co * 100 + $sub] = [
            'id'      => $co * 100 + $sub,
            'key'     => ($club !== '' ? $club : ('#' . $co)) . ':' . $sub,
            'name'    => $name,
            'club'    => $name,
            'code'    => $club,
            'class'   => $classes[$tk] ?? '',
            'score'   => (int) $r['TeScore'],
            'rank'    => (int) $r['TeRank'],
            'irm'     => (int) $r['TeIrmType'],
            'counts'  => $counts,
            'shot'    => $shot,
            'left'    => max(0, $size * $planned - $shot),
            'members' => $mem,
            'size'    => $size,
        ];
    }
    return $out;
}

/**
 * Flèches tirées en match par chaque archer, agrégées sur toute la compétition.
 *
 * La qualification seule ne dit pas tout : un archer qui monte en puissance dans le
 * tableau, ou qui craque, doit voir son profil bouger. ianseo conserve les flèches de
 * match (`FinArrowstring`), tirées à la même distance que la qualification — elles
 * sont donc directement comparables et s'ajoutent simplement à l'historique.
 *
 * @return array<int, array<int,int>> [EnId => histogramme des valeurs]
 */
function prono_match_arrows(int $tid): array
{
    // Cache le temps d'un passage du moteur (un même poll lit plusieurs épreuves),
    // vidé au début de chaque passage : en mode boucle, le processus vit une minute
    // pendant laquelle les résultats continuent d'arriver.
    static $cache = [];
    if ($tid < 0) { $cache = []; return []; }
    if (isset($cache[$tid])) return $cache[$tid];

    $rows = prono_all(
        "SELECT FinAthlete AS id, GROUP_CONCAT(FinArrowstring SEPARATOR '') AS arr
         FROM Finals
         WHERE FinTournament = ? AND FinAthlete > 0 AND TRIM(FinArrowstring) <> ''
         GROUP BY FinAthlete", [$tid]);

    $out = [];
    foreach ($rows as $r) $out[(int) $r['id']] = prono_arrow_counts([(string) $r['arr']]);
    return $cache[$tid] = $out;
}

/** Loi par flèche du plateau — sert de référence pour régulariser chaque archer. */
function prono_field_prior(array $archers, int $maxArrow = 10): array
{
    $counts = array_fill(0, PRONO_MAXV + 1, 0);
    foreach ($archers as $a) {
        foreach ($a['counts'] as $v => $n) $counts[$v] += $n;
    }
    return prono_counts_to_pmf($counts, $maxArrow);
}

/**
 * Résout, pour une épreuve, le classement national FFTA (REPARTITION_EPREUVES) le plus
 * représentatif de son effectif : celui où le plus grand nombre d'archers de l'épreuve
 * sont recensés (un archer peut apparaître dans plusieurs classements, sa catégorie
 * ET Scratch par exemple — on garde alors le plus spécifique, plus petit `CcNbArchers`,
 * comme REPARTITION_EPREUVES lui-même). Point d'entrée UNIQUE vers `REP_Rangs` pour ce
 * module : sert à la fois la force relative de chaque archer (`prono_rep_priors()`) et
 * l'ancrage des fourchettes de score (`prono_rep_anchor_top1/cut()`).
 *
 * `REP_Rangs` porte, par archer, `CrMoyenne` (moyenne de la saison) et `CrS1/S2/S3`
 * (ses 3 meilleurs scores individuels, décroissants) — de quoi distinguer la
 * PERFORMANCE TYPIQUE d'un archer (moyenne) de son PLAFOND (meilleur score).
 *
 * `mean`/`sd` sont calculés sur TOUT le classement (potentiellement des dizaines
 * d'archers), pas seulement les quelques-uns présents dans cette épreuve : un
 * écart-type sur un tout petit échantillon serait bruité et changerait d'une épreuve
 * à l'autre pour la même catégorie.
 *
 * PRONO reste utilisable seul : toute erreur (module absent, table/colonnes
 * manquantes, droits insuffisants) retombe silencieusement sur `null` (repli neutre
 * chez l'appelant).
 *
 * @return array{id:int, byRank:array<int,array{avg:float,s1:float,s2:float,s3:float}>,
 *               byLicence:array<string,array{avg:float,s1:float,s2:float,s3:float}>,
 *               mean:float, sd:float}|null
 */
function prono_rep_classement(int $tid, array $archers): ?array
{
    if (count($archers) < 4) return null;

    try {
        $cfg = prono_one('SELECT RcAnnee, RcDiscipline FROM REP_Config WHERE RcTournament = ?', [$tid]);
        if (!$cfg || (int) $cfg['RcAnnee'] <= 0 || (string) $cfg['RcDiscipline'] === '') return null;

        $codes = [];
        foreach ($archers as $a) {
            $lic = trim((string) ($a['key'] ?? ''));
            if ($lic !== '' && $lic[0] !== '#') $codes[] = $lic;
        }
        if (count($codes) < 4) return null;

        $ph   = implode(',', array_fill(0, count($codes), '?'));
        $matches = prono_all(
            "SELECT r.CrLicence, r.CrClassement, c.CcNbArchers
             FROM REP_Rangs r INNER JOIN REP_Classements c ON c.CcId = r.CrClassement
             WHERE c.CcAnnee = ? AND c.CcDiscipline = ?
               AND r.CrLicence COLLATE utf8mb4_unicode_ci IN ($ph)",
            array_merge([(int) $cfg['RcAnnee'], (string) $cfg['RcDiscipline']], $codes)
        );
        if (!$matches) return null;

        $bestByLic = [];
        foreach ($matches as $row) {
            $lic = trim((string) $row['CrLicence']);
            $n   = (int) $row['CcNbArchers'];
            if (!isset($bestByLic[$lic]) || ($n > 0 && $n < $bestByLic[$lic]['n'])) {
                $bestByLic[$lic] = ['cc' => (int) $row['CrClassement'], 'n' => $n > 0 ? $n : PHP_INT_MAX];
            }
        }
        if (count($bestByLic) < 4) return null;

        $counts = [];
        foreach ($bestByLic as $b) $counts[$b['cc']] = ($counts[$b['cc']] ?? 0) + 1;
        arsort($counts);
        $ccId = (int) array_key_first($counts);

        $rows = prono_all(
            'SELECT CrRang, CrLicence, CrMoyenne, CrS1, CrS2, CrS3
             FROM REP_Rangs WHERE CrClassement = ? AND CrMoyenne > 0 ORDER BY CrRang', [$ccId]);
    } catch (Throwable $e) {
        return null;
    }
    if (count($rows) < 4) return null;

    $byRank = $byLicence = [];
    foreach ($rows as $r) {
        $lic   = trim((string) $r['CrLicence']);
        $entry = [
            'lic' => $lic,
            'avg' => (float) $r['CrMoyenne'],
            's1'  => (float) $r['CrS1'],
            's2'  => (float) $r['CrS2'],
            's3'  => (float) $r['CrS3'],
        ];
        $byRank[(int) $r['CrRang']] = $entry;   // déjà trié par rang national (ORDER BY CrRang)
        $byLicence[$lic] = $entry;
    }

    return ['id' => $ccId, 'byRank' => $byRank, 'byLicence' => $byLicence];
}

/**
 * Sous-ensemble du classement national correspondant aux archers RÉELLEMENT inscrits à
 * cette épreuve (pas tout le classement), dans l'ordre du rang national — sert à situer
 * le vainqueur ou le cut PARMI CEUX QUI CONCOURENT ICI, jamais parmi l'ensemble du
 * classement (qui inclut des archers d'autres compétitions, d'autres clubs, jamais
 * confrontés à ce plateau). Réindexé à partir de 0 : la position 0 est le mieux classé
 * nationalement PARMI LES INSCRITS À CETTE ÉPREUVE.
 *
 * Approximation : seuls les inscrits ayant une correspondance dans REP_Rangs comptent.
 * Un inscrit sans classement national (pas encore rapproché, licence étrangère...)
 * n'apparaît pas, ce qui peut légèrement décaler une position si la couverture du
 * rapprochement est incomplète.
 *
 * @return array<int, array{lic:string,avg:float,s1:float,s2:float,s3:float}>
 */
function prono_rep_local_ranking(array $classement, array $archers): array
{
    $lics = [];
    foreach ($archers as $a) {
        $lic = trim((string) ($a['key'] ?? ''));
        if ($lic !== '' && $lic[0] !== '#') $lics[$lic] = true;
    }

    $local = [];
    foreach ($classement['byRank'] as $entry) {
        if (isset($lics[$entry['lic']])) $local[] = $entry;
    }
    return $local;
}

/**
 * Amorce le profil des archers à partir du classement national FFTA (voir
 * prono_rep_classement()). Sans cela, un archer sans flèche tirée démarre pile sur la
 * loi moyenne du plateau — vrai en cours de qualification (les scores déjà tombés
 * portent l'info), faux avant le premier tir : tout le monde a alors exactement la
 * même cote, ce qui n'a aucun sens dès qu'un classement national existe pour désigner
 * des favoris.
 *
 * Le profil de chaque archer est recentré DIRECTEMENT sur sa propre moyenne nationale
 * (`CrMoyenne` / flèches du round = ce qu'il réalise HABITUELLEMENT par flèche),
 * amorti à 70 % — le classement est un indice, pas une certitude, d'où le mélange
 * avec la loi neutre du plateau plutôt qu'un remplacement pur. PAS un décalage
 * additif au-dessus de la loi neutre du plateau (ancienne version, écartée) : la loi
 * neutre elle-même part d'une hypothèse optimiste (90 % de la valeur max d'une flèche,
 * `prono_counts_to_pmf()`) pensée pour amorcer un plateau générique, pas pour servir
 * de PLANCHER en dessous duquel personne ne serait jamais ajusté. Un décalage additif
 * cumule les deux optimismes : bug réel constaté mi-août 2026 — la loi neutre
 * anticipait déjà 5,1/6 pts par flèche en campagne (≈ 367 pts sur 72 flèches, soit le
 * record de la saison), si bien que la moindre poussée additive faisait apparaître le
 * favori quasi certain de battre le record. Recentrer directement sur la moyenne
 * réelle de l'archer élimine ce double comptage : un favori dont la moyenne nationale
 * est déjà proche de l'hypothèse neutre n'est presque pas déplacé, seul un écart RÉEL
 * (positif ou négatif) déplace son profil.
 *
 * @return array<int,array<int,float>> [EnId => loi par flèche], pour les seuls archers
 *         individuels disposant d'une donnée nationale exploitable.
 */
function prono_rep_priors(int $tid, array $archers, array $fieldPrior, int $maxArrow = 10): array
{
    $classement = prono_rep_classement($tid, $archers);
    if (!$classement) return [];

    $baseMean = prono_pmf_mean($fieldPrior);
    $out = [];
    foreach ($archers as $id => $a) {
        $lic = trim((string) ($a['key'] ?? ''));
        if ($lic === '' || $lic[0] === '#') continue;
        $rep = $classement['byLicence'][$lic] ?? null;
        if (!$rep) continue;

        // Flèches du round complet de CET archer (déjà tirées + restantes, cf.
        // prono_archers()/prono_teams()) : la moyenne nationale (un score TOTAL) doit
        // être ramenée à un rythme PAR FLÈCHE sur CE format avant toute comparaison —
        // sans ça (bug réel corrigé début août 2026), un simple écart national se
        // retrouvait démultiplié flèche après flèche sur tout le round (72 à 144
        // flèches en qualification), rendant les cotes du tiercé absurdement tranchées.
        $planned = $a['shot'] + $a['left'];
        if ($planned <= 0) continue;

        // Ce que cet archer réalise HABITUELLEMENT par flèche sur un round complet.
        $targetMean = $rep['avg'] / $planned;

        // Décalage CONTINU (pas arrondi à la flèche) : deux archers voisins au
        // classement avec un écart de score minime doivent produire des profils quasi
        // identiques, pas se retrouver dans deux paliers différents d'un arrondi à
        // l'entier le plus proche. $maxArrow (jamais PRONO_MAXV, la constante globale)
        // borne le décalage à la valeur max d'une flèche de CETTE compétition (6 en
        // campagne, 10 en extérieur...) : sinon un fort classement peut pousser le
        // profil au-delà du score physiquement atteignable.
        $offset = ($targetMean - $baseMean) * 0.7;
        if (abs($offset) < 1e-6) continue;
        $out[$id] = prono_shift_pmf($fieldPrior, $offset, $maxArrow);
    }
    return $out;
}

/**
 * Encadrement du score du 1er qualifié, pour la question à 3 issues (« - de X1 »,
 * « X1-X2 », « + de X2 ») : X1 = le moins bon des 3 meilleurs scores individuels
 * (`CrS3`, décroissant par archer) des 3 premiers au classement national PARMI LES
 * INSCRITS À CETTE ÉPREUVE — même un favori a ses jours plus faibles ; X2 = le
 * meilleur score individuel (`CrS1`) jamais réalisé cette saison par n'importe quel
 * inscrit à cette épreuve — la meilleure performance connue DE CE PLATEAU précis.
 *
 * Jamais le podium ni le record du classement national TOUT ENTIER (bug réel corrigé
 * mi-août 2026) : le classement mélange des archers d'autres compétitions, jamais
 * confrontés à ce plateau précis — seul le sous-ensemble RÉELLEMENT INSCRIT ICI (voir
 * prono_rep_local_ranking()) a un sens pour cette question.
 *
 * @return array{lo:float,hi:float,n:int}|null n : taille du groupe de référence (3),
 *         pour prono_qual_three_way().
 */
function prono_rep_anchor_top1(array $classement, array $archers): ?array
{
    $local = prono_rep_local_ranking($classement, $archers);
    $top3  = array_slice($local, 0, 3);
    if (count($top3) < 3) return null;

    $x1 = min(array_column($top3, 's3'));
    $x2 = 0.0;
    foreach ($local as $r) $x2 = max($x2, $r['s1']);

    return $x2 > $x1 ? ['lo' => $x1, 'hi' => $x2, 'n' => count($top3)] : null;
}

/**
 * Encadrement du score du cut : même principe que prono_rep_anchor_top1(), mais
 * localisé au voisinage du cut (sa position, celle au-dessus, celle en-dessous) DANS
 * LE CLASSEMENT NATIONAL RESTREINT AUX INSCRITS DE CETTE ÉPREUVE (prono_rep_local_
 * ranking()) — jamais la position `$cutRank` prise comme un rang national brut (bug
 * réel corrigé mi-août 2026) : le cut d'une épreuve est une position parmi SES
 * inscrits, pas dans le classement national entier, qui contient des archers absents
 * de cette compétition.
 *
 * @return array{lo:float,hi:float,n:int}|null n : taille du groupe de référence
 *         (2 ou 3 selon la disponibilité des rangs voisins), pour prono_qual_three_way().
 */
function prono_rep_anchor_cut(array $classement, array $archers, int $cutRank): ?array
{
    $local = prono_rep_local_ranking($classement, $archers);
    $idx   = $cutRank - 1;   // position 0-based dans $local

    $group = [];
    foreach ([$idx - 1, $idx, $idx + 1] as $i) {
        if (isset($local[$i])) $group[] = $local[$i];
    }
    if (count($group) < 2) return null;

    $x1 = min(array_column($group, 's3'));
    $x2 = max(array_column($group, 's1'));
    return $x2 > $x1 ? ['lo' => $x1, 'hi' => $x2, 'n' => count($group)] : null;
}

/** Nombre de matchs de la phase contenant ce slot (16 = 1/16, 1 = finale). */
function prono_phase_of_slot(int $slot): int
{
    if ($slot < 2) return 1;                       // finale (slots 0-1)
    return (int) (pow(2, floor(log($slot, 2))) / 2);
}

/** Slot atteint par le vainqueur ; -1 si le match est terminal. */
function prono_slot_target(int $slot): int
{
    if ($slot < 4) return -1;                      // finale et petite finale
    $phase = prono_phase_of_slot($slot);
    // Les demi-finales (2 matchs) alimentent les slots 0-1 : décalage de 2 pour
    // laisser 2-3 à la petite finale (cf. Final/Fun_ChangePhase.inc.php).
    return $phase > 2 ? intdiv($slot, 2) : intdiv($slot, 2) - 2;
}

function prono_phase_label(int $phase, int $slot): string
{
    if ($phase === 1) return $slot < 2 ? 'Finale' : 'Petite finale';
    if ($phase === 2) return 'Demi-finale';
    if ($phase === 4) return 'Quart de finale';
    return '1/' . $phase . ' de finale';
}

/**
 * Slots du tableau d'élimination d'une épreuve.
 * Un match = la paire (2k, 2k+1) ; on renvoie les slots bruts, l'appariement est
 * fait par l'appelant (markets.php) qui a besoin des deux lignes ensemble.
 *
 * TeamFinals a exactement la même structure d'arbre binaire que Finals, au préfixe
 * de colonnes près : les équipes traversent donc tout le reste du moteur sans cas
 * particulier, sous leur identifiant synthétique.
 */
function prono_slots(int $tid, string $event, bool $team = false): array
{
    // La chaîne de flèches n'existe qu'à partir de la première flèche saisie (vérifié :
    // 256 matchs non tirés sur 480 n'en ont aucune). C'est donc le signal le plus
    // précoce pour considérer un duel commencé — bien avant la fin de la première volée,
    // seul moment où SetPointsByEnd est alimenté.
    $rows = $team
        ? prono_all(
            'SELECT TfMatchNo AS mno, TfTeam, TfSubTeam, TfScore AS sc, TfSetScore AS ss,
                    TfSetPoints AS sp, TfSetPointsByEnd AS spe, TfWinLose AS wl,
                    TfIrmType AS irm, TfStatus AS st, TfTarget AS tg, TfArrowstring AS arr
             FROM TeamFinals WHERE TfTournament = ? AND TfEvent = ? ORDER BY TfMatchNo',
            [$tid, $event])
        : prono_all(
            'SELECT FinMatchNo AS mno, FinAthlete, FinScore AS sc, FinSetScore AS ss,
                    FinSetPoints AS sp, FinSetPointsByEnd AS spe, FinWinLose AS wl,
                    FinIrmType AS irm, FinStatus AS st, FinTarget AS tg, FinArrowstring AS arr
             FROM Finals WHERE FinTournament = ? AND FinEvent = ? ORDER BY FinMatchNo',
            [$tid, $event]);

    $out = [];
    foreach ($rows as $r) {
        $byEnd = trim((string) $r['spe']);
        $ends  = $byEnd === '' ? 0 : count(array_filter(explode('|', $byEnd), 'strlen'));

        $who = $team
            ? ((int) $r['TfTeam'] > 0 ? (int) $r['TfTeam'] * 100 + (int) $r['TfSubTeam'] : 0)
            : (int) $r['FinAthlete'];

        $out[(int) $r['mno']] = [
            'slot'     => (int) $r['mno'],
            'athlete'  => $who,
            'score'    => (int) $r['sc'],
            'setScore' => (int) $r['ss'],
            'setPts'   => (string) $r['sp'],
            'byEnd'    => $byEnd,
            'ends'     => $ends,
            'arrows'   => strlen(trim((string) ($r['arr'] ?? ''))),
            'win'      => (int) $r['wl'],
            'irm'      => (int) $r['irm'],
            'status'   => (int) $r['st'],
            'target'   => (string) $r['tg'],
        ];
    }
    return $out;
}

/**
 * État d'un match à partir de ses deux slots.
 * done : un vainqueur est acté — live : au moins une volée saisie — todo : sinon.
 */
function prono_match_state(array $a, array $b): string
{
    if ($a['win'] || $b['win'] || $a['irm'] || $b['irm']) return 'done';
    // Dès la première flèche saisie, pas à la fin de la volée : c'est le moment où
    // quelqu'un au bord du terrain commence à en savoir plus que le serveur.
    if (($a['arrows'] ?? 0) > 0 || ($b['arrows'] ?? 0) > 0) return 'live';
    if ($a['ends'] > 0 || $b['ends'] > 0 || $a['score'] > 0 || $b['score'] > 0) return 'live';
    return 'todo';
}

/** Volées tirées et points de set acquis dans un match en cours. */
function prono_live_sets(array $a, array $b): array
{
    $pa = $a['byEnd'] === '' ? [] : array_values(array_filter(explode('|', $a['byEnd']), 'strlen'));
    $pb = $b['byEnd'] === '' ? [] : array_values(array_filter(explode('|', $b['byEnd']), 'strlen'));
    $n  = min(count($pa), count($pb));

    $sa = $sb = 0;
    for ($i = 0; $i < $n; $i++) { $sa += (int) $pa[$i]; $sb += (int) $pb[$i]; }
    return ['a' => $sa, 'b' => $sb, 'sets' => $n];
}
