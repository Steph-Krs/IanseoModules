<?php
/**
 * lib/moteur.php — moteur de placement.
 *
 * Un bloc = une épreuve sur une plage de cibles × lettres contiguës, avec quatre
 * réglages : source de l'ordre, parcours, sens des lettres, sens des cibles.
 * L'attribution se fait toujours lettre d'abord, cible ensuite.
 *
 * Ce fichier ne fait AUCUNE écriture dans les tables ianseo : il calcule.
 */

if (!function_exists('rep_coll')) require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mapping.php';

define('REP_SRC_CLASSEMENT',      0);   // classement national, archer par archer
define('REP_SRC_CLUBS',           1);   // classement national par club (clubs triés par leur meilleur classé)
define('REP_SRC_CLUBS_MANUEL',    2);   // par ordre de club manuel (ordre défini sur la page dédiée)
define('REP_SRC_ARRETE_IND',      3);   // classement de l'arrêté (individuel), archer par archer
define('REP_SRC_CLUBS_ARR_IND',   4);   // par club, meilleur classé du classement d'arrêté individuel
define('REP_SRC_CLUBS_ARR_EQ',    5);   // par club, classement d'équipe de l'arrêté (EQ, équipes de clubs)
define('REP_SRC_CLUBS_ARR_DM',    6);   // par club, classement d'équipe de l'arrêté (DM, double mixte)
define('REP_SRC_ALPHA',           7);   // ordre alphabétique explicite, aucun classement consulté
// Sources qui groupent par club (algorithme des couloirs, rep_placement_clubs()) —
// utilisé à la fois pour la source principale et pour le second niveau (placement
// hybride, voir rep_placer_hybride()).
define('REP_SOURCES_CLUB', [REP_SRC_CLUBS, REP_SRC_CLUBS_MANUEL,
    REP_SRC_CLUBS_ARR_IND, REP_SRC_CLUBS_ARR_EQ, REP_SRC_CLUBS_ARR_DM]);

function rep_lettre($i)
{
    return chr(65 + max(0, intval($i)));
}

/** Les départs de la compétition, tels que ianseo les décrit. */
function rep_departs($tourId)
{
    $tourId = intval($tourId);
    $out = [];
    $rs = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesAth4Target, SesFirstTarget
        FROM Session WHERE SesTournament=$tourId AND SesType='Q' ORDER BY SesOrder");
    while ($rs && $r = safe_fetch($rs)) {
        $nb = intval($r->SesTar4Session);
        if ($nb <= 0) continue;
        $out[intval($r->SesOrder)] = [
            'ordre'   => intval($r->SesOrder),
            'nom'     => trim($r->SesName) !== '' ? trim($r->SesName) : ('Départ ' . intval($r->SesOrder)),
            'cibles'  => $nb,
            'ath'     => max(1, intval($r->SesAth4Target)),
            'premiere' => max(1, intval($r->SesFirstTarget)),
        ];
    }
    return $out;
}

/** Les blocs d'une compétition (tous départs, ou un seul). */
function rep_blocs($tourId, $session = null)
{
    $tourId = intval($tourId);
    $where  = "CbTournament=$tourId";
    if ($session !== null) $where .= " AND CbSession=" . intval($session);
    $out = [];
    $rs = safe_r_sql("SELECT * FROM REP_Blocs WHERE $where ORDER BY CbSession, CbEvent, CbT1, CbL1");
    while ($rs && $r = safe_fetch($rs)) {
        $out[] = [
            'id'       => intval($r->CbId),
            'session'  => intval($r->CbSession),
            'event'    => $r->CbEvent,
            'cle'      => $r->CbEvent,
            't1'       => intval($r->CbT1),  't2' => intval($r->CbT2),
            'l1'       => intval($r->CbL1),  'l2' => intval($r->CbL2),
            'src'      => intval($r->CbSource),
            'src2'     => intval($r->CbSource2 ?? 7),
            // Priorité cible/lettre (1 = cible en premier/boucle externe, 0 = lettre
            // en premier) — depuis 1.8.0 remplace l'ancien CbParcours à 3 valeurs
            // (0 par cible, 1 par lettre, 2 serpentin) : la priorité se règle en
            // déplaçant les sous-blocs Cibles/Lettres l'un au-dessus de l'autre dans
            // la colonne « Répartition », le serpentin est un réglage indépendant
            // (CbSerpentin) — voir migration v12 (lib/schema.php).
            'ciblePriorite' => intval($r->CbParcours),
            'serpentin'     => intval($r->CbSerpentin ?? 0),
            'sl'       => intval($r->CbSensLettres),
            'sc'       => intval($r->CbSensCibles),
            'depuis'   => max(1, intval($r->CbDepuis)),
            'br'       => intval($r->CbBrassage),
            'reb'      => intval($r->CbRebalance),
            'inclnp'   => intval($r->CbInclureNP ?? 0),
        ];
    }
    return $out;
}

function rep_places($b)
{
    return ($b['t2'] - $b['t1'] + 1) * ($b['l2'] - $b['l1'] + 1);
}

/**
 * Résout la jointure de rang PAR ARCHER pour une valeur de source (primaire ou
 * secondaire) — retourne ['sql'=>fragment JOIN, 'champ'=>expression SQL du
 * rang] ou null si aucun rang par archer n'existe pour cette valeur :
 *  - REP_SRC_ALPHA : jamais de rang, par construction.
 *  - REP_SRC_ARRETE_IND / CLUBS_ARR_IND : rang de l'archer dans le classement
 *    d'arrêté individuel (REP_ArrRangs, ArCle = licence — unique par
 *    classement, aucun risque de doublon).
 *  - REP_SRC_CLUBS_ARR_EQ / CLUBS_ARR_DM : PAS géré ici. Le rang n'est pas par
 *    archer mais par CLUB, et un même club peut apparaître PLUSIEURS FOIS dans
 *    un classement d'équipe (ex. deux équipes du même club) — une jointure SQL
 *    sur le code club dupliquerait chaque archer du club (fan-out : bug réel
 *    trouvé en testant, Cannes-Mandelieu présent 2 fois dans le classement
 *    d'équipe CL). Résolu à part, en PHP, par rep_archers_epreuve() via
 *    rep_arr_rangs_clubs() qui déduplique déjà par club (meilleur rang retenu).
 *  - REP_SRC_CLUBS_MANUEL : aucun rang numérique (un ordre manuel n'en est pas
 *    un) — reste sur le national, comme 0/1, uniquement par défaut raisonnable.
 *  - 0, 1 (national) et repli de 2 : classement national.
 */
function rep_rang_jointure($tourId, $event, $annee, $discipline, $set, $epreuveDef, $src, $alias)
{
    if ($src == REP_SRC_ALPHA) return null;
    if (in_array($src, [REP_SRC_CLUBS_ARR_EQ, REP_SRC_CLUBS_ARR_DM], true)) return null;
    if (in_array($src, [REP_SRC_ARRETE_IND, REP_SRC_CLUBS_ARR_IND], true)) {
        $cl = rep_classement_arrete($tourId, $event, $epreuveDef, $set);
        $arrid = $cl ? intval($cl['arrid'] ?? 0) : 0;
        if ($arrid <= 0) return null;
        return ['sql' => " LEFT JOIN REP_ArrRangs $alias ON $alias.ArClassement=$arrid"
                        . " AND $alias.ArCle " . rep_coll() . " = e.EnCode ",
                'champ' => "$alias.ArRang"];
    }
    $cl = rep_classement_epreuve($tourId, $event, $annee, $discipline, $set);
    $ccid = $cl ? intval($cl['ccid']) : 0;
    if ($ccid <= 0) return null;
    return ['sql' => " LEFT JOIN REP_Rangs $alias ON $alias.CrClassement=$ccid"
                    . " AND $alias.CrLicence " . rep_coll() . " = e.EnCode ",
            'champ' => "$alias.CrRang"];
}

/**
 * Les archers d'une ÉPREUVE (Events.EvCode, via la table Individuals), dans l'ordre
 * du classement retenu. Une épreuve peut réunir plusieurs catégories d'âge — d'où le
 * passage par Individuals plutôt que par EnDivision/EnClass.
 *
 * $src1 : valeur de source (CbSource, 0-7) pour le classement PRINCIPAL.
 * $src2 : valeur de source (CbSource2) pour le SECOND NIVEAU — pris pour tout
 * archer que $src1 ne classe pas (absent de son classement, ou classement
 * introuvable), avant de retomber sur l'ordre alphabétique. Ex. : une épreuve
 * Jeune classée par l'arrêté individuel (qui ne couvre que les sélectionnés)
 * peut prendre le classement national en second niveau pour ordonner le reste
 * plutôt que de les laisser en vrac alphabétique (demandé par l'utilisateur —
 * avant, ce repli alphabétique était le seul comportement possible, sans
 * second niveau réglable).
 *
 * Retourne [ ['enid','licence','nom','division','class','club','clubcode',
 *             'rang'|null,'rang2'|null], … ]
 */
function rep_archers_epreuve($tourId, $event, $annee, $discipline, $set = '', $inclureNP = false, $epreuveDef = null, $src1 = REP_SRC_CLASSEMENT, $src2 = REP_SRC_ALPHA)
{
    $tourId = intval($tourId);
    $j1 = rep_rang_jointure($tourId, $event, $annee, $discipline, $set, $epreuveDef, $src1, 'r1');
    // Évite une jointure en double si les deux niveaux visent la même source :
    // le résultat serait de toute façon identique (même classement, même clé).
    $j2 = ($src2 == $src1) ? null : rep_rang_jointure($tourId, $event, $annee, $discipline, $set, $epreuveDef, $src2, 'r2');

    $rangJoin = ($j1 ? $j1['sql'] : '') . ($j2 ? $j2['sql'] : '');
    $rangSel  = ', ' . ($j1 ? $j1['champ'] : 'NULL') . ' AS rang, ' . ($j2 ? $j2['champ'] : 'NULL') . ' AS rang2';

    $lire = function ($sql) {
        $out = [];
        $rs = safe_r_sql($sql);
        while ($rs && $r = safe_fetch($rs)) {
            $out[] = [
                'enid'     => intval($r->EnId),
                'licence'  => $r->EnCode,
                'nom'      => trim($r->EnFirstName . ' ' . $r->EnName),
                'division' => $r->EnDivision,
                'class'    => $r->EnClass,
                'club'     => $r->clubnom !== null ? $r->clubnom : '',
                'clubcode' => $r->clubcode !== null ? $r->clubcode : '',
                'rang'     => $r->rang !== null ? intval($r->rang) : null,
                'rang2'    => $r->rang2 !== null ? intval($r->rang2) : null,
            ];
        }
        return $out;
    };

    // Archers de l'épreuve (participants individuels).
    $out = $lire("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
                   c.CoCode AS clubcode, c.CoName AS clubnom" . $rangSel . "
        FROM Individuals i
        JOIN Entries e ON e.EnId = i.IndId AND e.EnTournament = i.IndTournament
        LEFT JOIN Countries c ON c.CoId = e.EnCountry AND c.CoTournament = e.EnTournament"
        . $rangJoin . "
        WHERE i.IndTournament=$tourId AND i.IndEvent=" . StrSafe_DB($event) . " AND e.EnAthlete=1");

    // Optionnel : archers SANS épreuve individuelle qui, par leur arme + sexe (+ classe
    // si l'épreuve n'est pas Scratch), pourraient participer à cette épreuve. On les
    // traite comme les autres (classement, comptage, placement).
    if ($inclureNP && $epreuveDef && strpos($epreuveDef['division'], '/') === false && $epreuveDef['sexe'] !== 'X') {
        $sexNum = $epreuveDef['sexe'] === 'F' ? 1 : 0;
        $whereClasse = '';
        if (empty($epreuveDef['scratch']) && !empty($epreuveDef['classes'])) {
            $in = [];
            foreach ($epreuveDef['classes'] as $c) $in[] = StrSafe_DB($c);
            $whereClasse = ' AND e.EnClass IN (' . implode(',', $in) . ') ';
        }
        $np = $lire("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
                       c.CoCode AS clubcode, c.CoName AS clubnom" . $rangSel . "
            FROM Entries e
            LEFT JOIN Countries c ON c.CoId = e.EnCountry AND c.CoTournament = e.EnTournament"
            . $rangJoin . "
            WHERE e.EnTournament=$tourId AND e.EnAthlete=1
              AND e.EnDivision=" . StrSafe_DB($epreuveDef['division']) . "
              AND e.EnSex=$sexNum" . $whereClasse . "
              AND NOT EXISTS (SELECT 1 FROM Individuals i2
                   JOIN Events ev2 ON ev2.EvTournament=i2.IndTournament AND ev2.EvCode=i2.IndEvent AND ev2.EvTeamEvent=0
                   WHERE i2.IndTournament=e.EnTournament AND i2.IndId=e.EnId)");
        $out = array_merge($out, $np);
    }

    // Rang de CLUB (sources 5/6, jamais résolu par jointure — voir
    // rep_rang_jointure()) : appliqué ici en PHP via rep_arr_rangs_clubs(),
    // déjà dédupliqué par club (meilleur rang retenu même si le club apparaît
    // plusieurs fois dans le classement d'équipe).
    $estClubArrEQouDM = function ($src) {
        return in_array($src, [REP_SRC_CLUBS_ARR_EQ, REP_SRC_CLUBS_ARR_DM], true);
    };
    if (($estClubArrEQouDM($src1) || $estClubArrEQouDM($src2)) && $epreuveDef
        && strpos($epreuveDef['division'], '/') === false && function_exists('rep_arr_rangs_clubs')) {
        $division = $epreuveDef['division'];
        $rangsClub1 = $estClubArrEQouDM($src1)
            ? rep_arr_rangs_clubs($tourId, $division, $src1 == REP_SRC_CLUBS_ARR_EQ ? 'EQ' : 'DM', $epreuveDef) : null;
        $rangsClub2 = $estClubArrEQouDM($src2)
            ? rep_arr_rangs_clubs($tourId, $division, $src2 == REP_SRC_CLUBS_ARR_EQ ? 'EQ' : 'DM', $epreuveDef) : null;
        foreach ($out as $k => $a) {
            $code = mb_strtoupper($a['clubcode'], 'UTF-8');
            if ($rangsClub1 !== null && isset($rangsClub1[$code])) $out[$k]['rang']  = $rangsClub1[$code];
            if ($rangsClub2 !== null && isset($rangsClub2[$code])) $out[$k]['rang2'] = $rangsClub2[$code];
        }
    }

    // Ordre : classés par $src1 d'abord (rang croissant), puis ceux que $src1 ne
    // classe pas mais que $src2 classe (rang2 croissant), puis alphabétique.
    usort($out, function ($x, $y) {
        $tx = $x['rang'] !== null ? 0 : ($x['rang2'] !== null ? 1 : 2);
        $ty = $y['rang'] !== null ? 0 : ($y['rang2'] !== null ? 1 : 2);
        if ($tx !== $ty) return $tx - $ty;
        if ($tx === 0) return $x['rang'] - $y['rang'];
        if ($tx === 1) return $x['rang2'] - $y['rang2'];
        return strcasecmp($x['nom'], $y['nom']);
    });
    return $out;
}

/**
 * Disponibilité de chaque source moteur pour UNE épreuve : [id => bool].
 *
 * Sert à griser dans l'UI les sources qui n'ont PAS de classement réel derrière
 * elles pour cette épreuve précise (ex. « classement de l'arrêté » choisi alors
 * qu'aucun classement de l'arrêté ne correspond) — pour ne plus jamais retomber
 * en silence sur l'ordre alphabétique sans que l'utilisateur ne le voie (bug
 * réel signalé par l'utilisateur : S3WCL retombait en alphabétique sans aucun
 * signal). REP_SRC_CLUBS_MANUEL et REP_SRC_ALPHA sont TOUJOURS disponibles :
 * le premier se règle à la main (rien à télécharger), le second ne consulte
 * jamais de classement par construction.
 */
function rep_sources_dispo($tourId, $cle, $epreuveDef, $cfg)
{
    $tourId = intval($tourId);
    $set = $cfg['set'] ?? '';

    $clNat = rep_classement_epreuve($tourId, $cle, $cfg['annee'], $cfg['discipline'], $set);
    $natOk = $clNat && intval($clNat['ccid']) > 0;

    $clArr = rep_classement_arrete($tourId, $cle, $epreuveDef, $set);
    $arrOk = $clArr && !empty($clArr['arrid']);

    $division = $epreuveDef['division'] ?? '';
    $eqOk = false;
    $dmOk = false;
    if ($division !== '' && strpos($division, '/') === false && function_exists('rep_arr_rangs_clubs')) {
        $eqOk = !empty(rep_arr_rangs_clubs($tourId, $division, 'EQ', $epreuveDef));
        $dmOk = !empty(rep_arr_rangs_clubs($tourId, $division, 'DM', $epreuveDef));
    }

    return [
        REP_SRC_CLASSEMENT    => $natOk,
        REP_SRC_CLUBS         => $natOk,
        REP_SRC_CLUBS_MANUEL  => true,
        REP_SRC_ARRETE_IND    => $arrOk,
        REP_SRC_CLUBS_ARR_IND => $arrOk,
        REP_SRC_CLUBS_ARR_EQ  => $eqOk,
        REP_SRC_CLUBS_ARR_DM  => $dmOk,
        REP_SRC_ALPHA         => true,
    ];
}

/** Les axes du bloc, déjà retournés selon les deux sens. */
function rep_axes($b)
{
    $T = [];
    for ($t = $b['t1']; $t <= $b['t2']; $t++) $T[] = $t;
    $L = [];
    for ($l = $b['l1']; $l <= $b['l2']; $l++) $L[] = $l;
    if (!empty($b['sc'])) $T = array_reverse($T);
    if (!empty($b['sl'])) $L = array_reverse($L);
    return [$T, $L];
}

/**
 * Les cellules du bloc dans l'ordre de remplissage : $b['ciblePriorite'] fixe
 * QUEL axe forme la boucle EXTERNE (rempli entièrement avant de passer au
 * suivant de l'autre axe) — 1 = cible, 0 = lettre. $b['serpentin'], réglage
 * indépendant, alterne le sens de l'axe INTERNE à chaque tour de boucle
 * externe (symétrique quelle que soit la priorité choisie) — depuis 1.8.0
 * remplace l'ancien CbParcours à 3 valeurs (voir migration v12, lib/schema.php).
 */
function rep_cellules_bloc($b)
{
    list($T, $L) = rep_axes($b);
    $out = [];
    if (!empty($b['ciblePriorite'])) {
        foreach ($T as $k => $t) {
            $ordre = (empty($b['serpentin']) || $k % 2 === 0) ? $L : array_reverse($L);
            foreach ($ordre as $l) $out[] = [$t, $l];
        }
    } else {
        foreach ($L as $k => $l) {
            $ordre = (empty($b['serpentin']) || $k % 2 === 0) ? $T : array_reverse($T);
            foreach ($ordre as $t) $out[] = [$t, $l];
        }
    }
    return $out;
}

/**
 * Tri par club — algorithme des couloirs.
 *
 * Chaque lettre est un couloir indépendant. Un club se voit attribuer UN couloir et
 * **une seule lettre** : tous ses archers de l'épreuve tirent sur cette lettre, sur
 * des cibles adjacentes. Le couloir choisi est le moins avancé au moment où le club
 * est traité ; quand il est plein (club avec plus d'archers que de cibles), on
 * déborde sur le couloir le moins avancé suivant — seul cas où un club n'a pas une
 * lettre unique, et il est alors physiquement impossible de faire autrement.
 *
 * Exemple de référence (bloc sur les lettres A et B) :
 *   club 1 (5 archers) → 1A 2A 3A 4A 5A   club 2 (4) → 1B 2B 3B 4B
 *   club 3 → 5B                            club 4 → 6A
 *
 * $ordreClubs : liste ordonnée de numéros de club (ordre manuel).
 * $rangsClubs : [code club => rang] tiré du classement d'équipe de l'arrêté
 * (REP_SRC_CLUBS_ARR_EQ/DM) — prioritaire sur $ordreClubs si les deux sont
 * fournis (n'arrive pas en pratique, chaque source moteur ne fournit que l'un
 * des deux). Si aucun des deux, les clubs sont pris dans l'ordre de leur
 * meilleur classé (national ou d'arrêté individuel selon la source appelante).
 */
function rep_placement_clubs($b, $archers, $ordreClubs = null, $rangsClubs = null)
{
    list($T, $L) = rep_axes($b);
    $nT = count($T);
    $nL = count($L);

    // Groupement par NUMÉRO de club (unique), pas par nom.
    $groupes = [];
    $ordre   = [];
    $noms    = [];
    foreach ($archers as $a) {
        $c = $a['clubcode'] !== '' ? $a['clubcode'] : '(sans)';
        if (!isset($groupes[$c])) { $groupes[$c] = []; $ordre[] = $c; $noms[$c] = $a['club']; }
        $groupes[$c][] = $a;
    }

    $meilleur = function ($club) use ($groupes) {
        $m = null;
        foreach ($groupes[$club] as $a) if ($a['rang'] !== null && ($m === null || $a['rang'] < $m)) $m = $a['rang'];
        return $m;
    };

    if (is_array($rangsClubs) && $rangsClubs) {
        // Ordre du classement d'équipe de l'arrêté : les clubs qui y figurent
        // d'abord, dans leur rang d'équipe ; les autres ensuite comme en repli.
        usort($ordre, function ($x, $y) use ($rangsClubs, $meilleur, $noms) {
            $rx = $rangsClubs[mb_strtoupper($x, 'UTF-8')] ?? PHP_INT_MAX;
            $ry = $rangsClubs[mb_strtoupper($y, 'UTF-8')] ?? PHP_INT_MAX;
            if ($rx !== $ry) return $rx - $ry;
            $mx = $meilleur($x); $my = $meilleur($y);
            if ($mx === null && $my === null) return strcasecmp($noms[$x], $noms[$y]);
            if ($mx === null) return 1;
            if ($my === null) return -1;
            return $mx - $my;
        });
    } elseif (is_array($ordreClubs) && $ordreClubs) {
        // Ordre manuel : les clubs listés d'abord, dans l'ordre ; les autres ensuite
        // par meilleur classé, puis par nom.
        $rangManuel = array_flip(array_values($ordreClubs));
        usort($ordre, function ($x, $y) use ($rangManuel, $meilleur, $noms) {
            $px = $rangManuel[$x] ?? PHP_INT_MAX;
            $py = $rangManuel[$y] ?? PHP_INT_MAX;
            if ($px !== $py) return $px - $py;
            $mx = $meilleur($x); $my = $meilleur($y);
            if ($mx === null && $my === null) return strcasecmp($noms[$x], $noms[$y]);
            if ($mx === null) return 1;
            if ($my === null) return -1;
            return $mx - $my;
        });
    } else {
        // Ordre par meilleur rang national du club, à défaut par nom.
        usort($ordre, function ($x, $y) use ($meilleur, $noms) {
            $mx = $meilleur($x); $my = $meilleur($y);
            if ($mx === null && $my === null) return strcasecmp($noms[$x], $noms[$y]);
            if ($mx === null) return 1;
            if ($my === null) return -1;
            return $mx - $my;
        });
    }

    $curseur = array_fill(0, $nL, 0);
    $libre = function () use (&$curseur, $nL, $nT) {
        $best = -1;
        for ($i = 0; $i < $nL; $i++) {
            if ($curseur[$i] >= $nT) continue;
            if ($best < 0 || $curseur[$i] < $curseur[$best]) $best = $i;
        }
        return $best;
    };

    $res = [];
    foreach ($ordre as $club) {
        $lane = $libre();
        foreach ($groupes[$club] as $a) {
            if ($lane < 0 || $curseur[$lane] >= $nT) $lane = $libre();
            if ($lane < 0) break 2;   // plus une seule place dans le bloc
            $res[] = ['t' => $T[$curseur[$lane]], 'l' => $L[$lane], 'a' => $a];
            $curseur[$lane]++;
        }
    }
    return $res;
}

define('REP_BRASS_AUCUN',   0);   // pas de brassage
define('REP_BRASS_FEDERAL', 1);   // règle fédérale : au plus 2 archers d'un club par cible
define('REP_BRASS_MELANGE', 2);   // plus poussé : au plus 1 archer d'un club par cible

/**
 * Brassage des clubs — échange des archers entre cibles VOISINES pour qu'aucune ne
 * réunisse plus de N licenciés d'un même club (N = 2 fédéral, N = 1 mélange). Les
 * places du bloc ne changent pas, un archer ne se déplace que d'une cible au plus,
 * et l'archer le mieux classé reste prioritaire.
 *
 * Règle (classement croissant, cibles de la 1 vers la X) :
 *  - cible du milieu avec trop d'archers d'un club : le meilleur recule d'une cible,
 *    le moins bon avance d'une cible, ceux du milieu ne bougent pas ;
 *  - première cible (pas de précédente) : on garde les N meilleurs, les suivants
 *    avancent ; dernière cible : on garde les N moins bons, les meilleurs reculent ;
 *  - conflit de 2 avec N = 1 (mélange) : seul le moins bon avance.
 * L'échange se fait avec le rang le plus proche pour préserver l'ordre. Si la cible
 * voisine porte déjà le club au maximum, on y fait d'abord de la place en poussant
 * son archer du même club un cran plus loin (cascade), chacun d'un seul cran.
 */
function rep_brasser($res, $max)
{
    if (count($res) < 2) return $res;

    $parT = [];
    foreach ($res as $i => $r) $parT[$r['t']][] = $i;
    $ts = array_keys($parT);
    sort($ts, SORT_NUMERIC);
    $pos = array_flip($ts);
    $nT  = count($ts);

    $ord = function ($i) use (&$res) {
        $a = $res[$i]['a'];
        if (isset($a['ord'])) return $a['ord'];
        return ($a['rang'] === null) ? PHP_INT_MAX : $a['rang'];
    };
    $club  = function ($i) use (&$res) { return $res[$i]['a']['club']; };
    $count = function ($t, $c) use (&$res, &$parT, $club) {
        $n = 0;
        foreach ($parT[$t] as $i) if ($c !== '' && $club($i) === $c) $n++;
        return $n;
    };
    $moved = [];   // cellules figées (les 2 archers d'un échange déjà fait)

    // Déplace l'archer de la cellule $i vers la cible voisine dans le sens $dir
    // (+1 avance, -1 recule), par échange avec le rang le plus proche. Cascade si la
    // voisine est saturée du même club. Retourne true si l'échange a eu lieu.
    $move = function ($i, $dir) use (&$res, &$parT, $ts, $pos, $nT, $ord, $club, $count, $max, &$moved, &$move) {
        if (isset($moved[$i])) return false;
        $t = $res[$i]['t'];
        $p = $pos[$t] + $dir;
        if ($p < 0 || $p >= $nT) return false;
        $t2 = $ts[$p];
        $c  = $club($i);

        // Faire de la place si la voisine porte déjà le club au maximum.
        if ($count($t2, $c) >= $max) {
            $pousse = null;
            foreach ($parT[$t2] as $j) {
                if ($club($j) !== $c || isset($moved[$j])) continue;
                // sens +1 : on pousse le moins bon ; sens -1 : le meilleur
                if ($pousse === null
                    || ($dir > 0 ? $ord($j) > $ord($pousse) : $ord($j) < $ord($pousse))) $pousse = $j;
            }
            if ($pousse === null || !$move($pousse, $dir)) return false;
        }

        // Partenaire d'échange : club différent, non figé, et dont le club n'est pas
        // déjà au maximum sur $t (sinon on créerait un conflit en l'y amenant).
        $part = null;
        foreach ($parT[$t2] as $j) {
            if (isset($moved[$j]) || $club($j) === $c) continue;
            if ($count($t, $club($j)) >= $max) continue;
            // sens +1 : on ramène le meilleur ; sens -1 : le moins bon (rang proche)
            if ($part === null
                || ($dir > 0 ? $ord($j) < $ord($part) : $ord($j) > $ord($part))) $part = $j;
        }
        if ($part === null) return false;

        $tmp = $res[$i]['a'];
        $res[$i]['a'] = $res[$part]['a'];
        $res[$part]['a'] = $tmp;
        $moved[$i] = $moved[$part] = true;
        return true;
    };

    // Résout un conflit (cible $t, club $c). Retourne true si au moins un déplacement.
    $resolve = function ($t, $c) use (&$res, &$parT, $ts, $pos, $nT, $ord, $club, $count, $move, $max) {
        $liste = [];
        foreach ($parT[$t] as $i) if ($club($i) === $c) $liste[] = $i;
        usort($liste, function ($a, $b) use ($ord) { return $ord($a) - $ord($b); });   // meilleur d'abord
        $m = count($liste);
        if ($m <= $max) return false;

        $premier = ($pos[$t] === 0);
        $dernier = ($pos[$t] === $nT - 1);
        $bouge = false;

        if ($premier) {
            // garder les $max meilleurs, avancer les autres (le moins bon d'abord)
            for ($k = $m - 1; $k >= $max; $k--) if ($move($liste[$k], +1)) $bouge = true;
        } elseif ($dernier) {
            // garder les $max moins bons, reculer les meilleurs
            for ($k = 0; $k < $m - $max; $k++) if ($move($liste[$k], -1)) $bouge = true;
        } elseif ($m === 2 && $max === 1) {
            // mélange, conflit de 2 : seul le moins bon avance
            if ($move($liste[1], +1)) $bouge = true;
        } else {
            // milieu : meilleur recule, moins bon avance, milieu inchangé
            if ($move($liste[0], -1)) $bouge = true;
            if ($move($liste[$m - 1], +1)) $bouge = true;
        }
        return $bouge;
    };

    $skip = [];
    $cap  = count($res) * 4 + 20;
    while ($cap-- > 0) {
        $trouve = null;
        foreach ($ts as $t) {
            $cc = [];
            foreach ($parT[$t] as $i) { $k = $club($i); if ($k !== '') $cc[$k] = ($cc[$k] ?? 0) + 1; }
            foreach ($cc as $c => $n) {
                if ($n > $max && empty($skip[$t . '|' . $c])) { $trouve = [$t, $c]; break 2; }
            }
        }
        if (!$trouve) break;
        if (!$resolve($trouve[0], $trouve[1])) $skip[$trouve[0] . '|' . $trouve[1]] = true;
    }

    // Remettre les archers dans l'ordre du classement (lettre A au mieux classé)
    // UNIQUEMENT dans les cibles réellement touchées par un échange. Sinon un
    // placement sans conflit (ex. par club, une lettre par club) serait redistribué
    // à tort — c'est ce qui cassait le « une lettre par club ».
    $touchees = [];
    foreach (array_keys($moved) as $i) $touchees[$res[$i]['t']] = true;
    foreach (array_keys($touchees) as $t) {
        $cells = $parT[$t];
        $arch  = [];
        foreach ($cells as $i) $arch[] = $res[$i]['a'];
        usort($arch, function ($x, $y) {
            $ox = $x['ord'] ?? ($x['rang'] === null ? PHP_INT_MAX : $x['rang']);
            $oy = $y['ord'] ?? ($y['rang'] === null ? PHP_INT_MAX : $y['rang']);
            return $ox - $oy;
        });
        $lettres = $cells;
        usort($lettres, function ($a, $b) use (&$res) { return $res[$a]['l'] - $res[$b]['l']; });
        foreach ($lettres as $k => $i) $res[$i]['a'] = $arch[$k];
    }

    return $res;
}

/**
 * Rééquilibrage de la dernière cible d'un bloc : quand une cible ne reçoit qu'un
 * seul archer (ça arrive selon l'effectif), on lui donne le dernier archer de la
 * cible voisine si celle-ci en a au moins trois — la cible seule passe à deux,
 * la voisine reste à deux minimum. Ne touche à rien si le report créerait un
 * nouvel isolé (voisine à exactement deux).
 */
function rep_rebalance($res, $l1 = 0, $l2 = null)
{
    if (count($res) < 2) return $res;
    $parT = [];
    foreach ($res as $i => $r) $parT[$r['t']][] = $i;

    foreach ($parT as $t => $idx) {
        if (count($idx) !== 1) continue;
        foreach ([$t - 1, $t + 1] as $pt) {              // cible voisine dans le bloc
            if (!isset($parT[$pt]) || count($parT[$pt]) < 3) continue;

            // On prend l'archer de la voisine le plus proche de la cible isolée :
            // la lettre la plus haute si la voisine précède, la plus basse sinon.
            $choix = null;
            foreach ($parT[$pt] as $i) {
                if ($choix === null) { $choix = $i; continue; }
                if ($pt < $t) { if ($res[$i]['l'] > $res[$choix]['l']) $choix = $i; }
                else          { if ($res[$i]['l'] < $res[$choix]['l']) $choix = $i; }
            }
            $used = [];
            foreach ($idx as $i) $used[$res[$i]['l']] = true;
            // La lettre libre se cherche à partir de $l1 (bornes du bloc), jamais
            // depuis 0/« A » dans l'absolu : un bloc qui ne couvre que C-D plaçait
            // sinon l'archer reporté en A, hors de sa plage de lettres (bug réel).
            $nl = $l1;
            while (isset($used[$nl]) && ($l2 === null || $nl < $l2)) $nl++;
            $res[$choix]['t'] = $t;
            $res[$choix]['l'] = $nl;
            return $res;                                  // un report suffit pour la dernière cible
        }
    }
    return $res;
}

/**
 * Espacement des cibles sous-remplies : quand une cible reçoit moins d'archers
 * que le bloc n'a de lettres possibles, on les répartit sur les lettres
 * EXTRÊMES plutôt que de les regrouper depuis la première (ex. 3 lettres
 * possibles A-B-C, 2 archers → A et C, pas A et B) — pour laisser le plus de
 * place possible sur le pas de tir entre deux archers.
 *
 * S'applique aussi aux sources par club (rep_placement_clubs) depuis que
 * l'utilisateur l'a demandé explicitement : là, chaque club occupe en principe
 * une lettre fixe et cohérente sur toutes ses cibles (« un club, une lettre »),
 * et réassigner les lettres d'une cible sous-remplie PEUT rompre cette
 * cohérence pour le club concerné sur cette cible précise (son voisin de
 * lettre s'y retrouve décalé alors qu'il garde sa lettre habituelle sur les
 * autres cibles) — accepté volontairement : sur les dernières cibles d'une
 * épreuve, où le nombre de clubs encore actifs tombe sous le nombre de
 * lettres du bloc, l'étalement prime sur cette cohérence.
 */
function rep_espacer($res, $b)
{
    if (count($res) < 2) return $res;
    $lettresBloc = range($b['l1'], $b['l2']);   // toujours croissant : A, puis B, etc.
    $nL = count($lettresBloc);
    if ($nL < 3) return $res;   // rien à espacer avec 1 ou 2 lettres possibles

    $parT = [];
    foreach ($res as $i => $r) $parT[$r['t']][] = $i;

    foreach ($parT as $t => $idx) {
        $k = count($idx);
        if ($k >= $nL || $k < 2) continue;   // cible complète, ou un seul archer

        // k positions parmi nL, réparties le plus uniformément possible en
        // commençant et finissant aux extrémités (0 et nL-1 incluses).
        $positions = [];
        for ($j = 0; $j < $k; $j++) $positions[] = (int) round($j * ($nL - 1) / ($k - 1));
        $positions = array_values(array_unique($positions));
        $suivant = 0;
        while (count($positions) < $k) {
            if (!in_array($suivant, $positions, true)) $positions[] = $suivant;
            $suivant++;
        }
        sort($positions);

        // Conserve l'ordre relatif déjà en place (meilleur classé vers la
        // première lettre) : seules les lettres occupées changent.
        usort($idx, function ($a, $c) use (&$res) { return $res[$a]['l'] - $res[$c]['l']; });
        foreach ($idx as $rang => $i) $res[$i]['l'] = $lettresBloc[$positions[$rang]];
    }
    return $res;
}

/**
 * Blocs pour lesquels un rééquilibrage est possible et pas encore appliqué :
 * une cible n'a qu'un archer (physiquement, tous blocs confondus) et une cible
 * voisine du même bloc en a au moins trois.
 */
function rep_blocs_rebalancables($plan)
{
    $phys = [];
    foreach ($plan['affectations'] as $a) {
        $k = $a['session'] . ':' . $a['target'];
        $phys[$k] = ($phys[$k] ?? 0) + 1;
    }
    $out = [];
    foreach ($plan['blocs'] as $b) {
        if (!empty($b['reb'])) continue;
        $res  = $plan['parBloc'][$b['id']] ?? [];
        $parT = [];
        foreach ($res as $r) $parT[$r['t']][] = $r;
        foreach ($parT as $t => $rows) {
            if (count($rows) !== 1) continue;
            if (($phys[$b['session'] . ':' . $t] ?? 0) !== 1) continue;   // physiquement seul ?
            if ((isset($parT[$t - 1]) && count($parT[$t - 1]) >= 3)
             || (isset($parT[$t + 1]) && count($parT[$t + 1]) >= 3)) {
                $out[$b['id']] = true;
                break;
            }
        }
    }
    return array_keys($out);
}

/**
 * Blocs qui enfreignent la règle fédérale (plus de 2 archers d'un même club sur
 * une cible) et ne sont pas encore brassés : candidats au bouton « Brasser ».
 */
function rep_blocs_a_brasser($plan)
{
    $max = rep_max_club();
    $out = [];
    foreach ($plan['blocs'] as $b) {
        if (!empty($b['br'])) continue;                    // déjà brassé (fédéral ou mélange)
        $res  = $plan['parBloc'][$b['id']] ?? [];
        $parT = [];
        foreach ($res as $r) {
            if ($r['a']['club'] === '') continue;
            $k = $r['t'] . '|' . $r['a']['club'];
            $parT[$r['t']][$r['a']['club']] = ($parT[$r['t']][$r['a']['club']] ?? 0) + 1;
        }
        foreach ($parT as $t => $clubs) {
            if (max($clubs) > $max) { $out[$b['id']] = true; break; }
        }
    }
    return array_keys($out);
}

/**
 * Placement hybride (1.7.1, demandé par l'utilisateur) : la source PRINCIPALE
 * ne groupe pas par club (sinon on est dans le cas « club » ordinaire), mais
 * le SECOND NIVEAU, lui, en est une. Les archers classés par la principale
 * ($tier0, ont un 'rang' réel) remplissent d'abord, en ordre simple, les
 * premières cibles du bloc ; ceux que la principale ne classe pas ($reste)
 * remplissent les cibles restantes avec l'algorithme des couloirs (même club
 * = même lettre), ordonnés par le second niveau. Jamais de mélange des deux
 * groupes sur une même cible : chacun reçoit une plage de cibles entières.
 *
 * Cas réel qui a motivé cette fonction : une épreuve classée par l'arrêté
 * individuel (principale) avec un second niveau « par club selon l'arrêté
 * équipe » — les archers hors sélection individuelle (équipiers seuls,
 * inclus via « hors épr. ») doivent se retrouver sur la même lettre que leurs
 * coéquipiers de club, dans l'ordre du classement d'équipe — pas seulement
 * hérité d'un rang numérique de repli (bug initial signalé par l'utilisateur :
 * deux archers du même club pas sur la même lettre, club mieux classé en
 * équipe placé après un club moins bien classé).
 */
function rep_placer_hybride($b, $tier0, $reste, $ordreClubs2, $rangsClubs2)
{
    $nL = $b['l2'] - $b['l1'] + 1;
    $nCiblesTotal = $b['t2'] - $b['t1'] + 1;
    $nCiblesTier0 = $tier0 ? min($nCiblesTotal, (int) ceil(count($tier0) / $nL)) : 0;

    $res = [];
    if ($nCiblesTier0 > 0) {
        $b0 = $b;
        $b0['t2'] = $b0['t1'] + $nCiblesTier0 - 1;
        $cells = rep_cellules_bloc($b0);
        for ($i = 0; $i < count($cells) && $i < count($tier0); $i++) {
            $res[] = ['t' => $cells[$i][0], 'l' => $cells[$i][1], 'a' => $tier0[$i]];
        }
        $res = rep_espacer($res, $b0);   // la dernière cible du tier 0 peut être sous-remplie
    }

    $t1Reste = $b['t1'] + $nCiblesTier0;
    if ($reste && $t1Reste <= $b['t2']) {
        $bReste = $b;
        $bReste['t1'] = $t1Reste;
        // rep_placement_clubs() classe les clubs sans $rangsClubs/$ordreClubs par
        // le meilleur 'rang' de leurs archers (rep_axes/$meilleur) — hors ici,
        // ces archers n'ont par définition PAS de 'rang' (c'est ce qui les met
        // dans $reste) : on y substitue leur 'rang2' (le second niveau) pour que
        // ce repli reste pertinent quand le second niveau est un classement par
        // club « national »/« arrêté individuel » (1/4), sans rang de club dédié.
        $resteAvecRang = array_map(function ($a) {
            $a['rang'] = $a['rang2'];
            return $a;
        }, $reste);
        $resReste = rep_placement_clubs($bReste, $resteAvecRang, $ordreClubs2, $rangsClubs2);
        $res = array_merge($res, $resReste);
    }
    return $res;
}

/**
 * Placement d'un bloc.
 * $tous = la liste ordonnée des archers de l'épreuve ; le bloc y prélève
 * les places [depuis, depuis + places - 1].
 * $ordreClubs/$rangsClubs : pour la source PRINCIPALE quand elle groupe par
 * club (REP_SRC_CLUBS_MANUEL / CLUBS_ARR_EQ|DM). $ordreClubs2/$rangsClubs2 :
 * mêmes réglages, mais pour le SECOND NIVEAU (placement hybride, voir
 * rep_placer_hybride()) — n'a de sens QUE si la principale ne groupe pas déjà
 * par club (sinon un seul groupement s'applique, celui de la principale).
 * Retourne [ ['t','l','a'], … ] et la liste des archers réellement servis.
 */
function rep_placer($b, $tous, $ordreClubs = null, $rangsClubs = null, $ordreClubs2 = null, $rangsClubs2 = null)
{
    $n       = rep_places($b);
    $debut   = max(0, $b['depuis'] - 1);
    $archers = array_slice($tous, $debut, $n);
    if (!$archers) return [];

    // Ordre de priorité pour le brassage : plus « ord » est petit, meilleur est le
    // classement. La liste $tous est déjà triée (rang croissant, non-classés à la
    // fin), donc l'index suffit — quelle que soit la source du rang (national ou
    // arrêté).
    foreach ($archers as $k => $void) $archers[$k]['ord'] = $k;

    $estClub1 = in_array($b['src'], REP_SOURCES_CLUB, true);
    $estClub2 = in_array($b['src2'], REP_SOURCES_CLUB, true);

    if ($b['src'] == REP_SRC_CLUBS || $b['src'] == REP_SRC_CLUBS_ARR_IND) {
        $res = rep_placement_clubs($b, $archers);
    } elseif ($b['src'] == REP_SRC_CLUBS_MANUEL) {
        $res = rep_placement_clubs($b, $archers, $ordreClubs);
    } elseif ($b['src'] == REP_SRC_CLUBS_ARR_EQ || $b['src'] == REP_SRC_CLUBS_ARR_DM) {
        $res = rep_placement_clubs($b, $archers, null, $rangsClubs);
    } elseif ($estClub2) {
        // Principale en ordre simple, second niveau par club : placement hybride
        // (voir rep_placer_hybride()) — jamais combiné avec une principale déjà
        // groupée par club (cas déjà traité par les branches ci-dessus).
        $tier0 = array_values(array_filter($archers, function ($a) { return $a['rang'] !== null; }));
        $reste = array_values(array_filter($archers, function ($a) { return $a['rang'] === null; }));
        $res = rep_placer_hybride($b, $tier0, $reste, $ordreClubs2, $rangsClubs2);
    } else {
        $cells = rep_cellules_bloc($b);
        $res   = [];
        for ($i = 0; $i < count($cells) && $i < count($archers); $i++) {
            $res[] = ['t' => $cells[$i][0], 'l' => $cells[$i][1], 'a' => $archers[$i]];
        }
    }

    if (!empty($b['reb'])) $res = rep_rebalance($res, $b['l1'], $b['l2']);   // fige d'abord les cibles
    if ($b['br'] == REP_BRASS_FEDERAL)      $res = rep_brasser($res, rep_max_club());
    elseif ($b['br'] == REP_BRASS_MELANGE)  $res = rep_brasser($res, 1);

    // Espacement des cibles sous-remplies (2 <= k < nL archers) : appliqué à TOUTE
    // source, y compris par club (demandé par l'utilisateur — auparavant exclu pour
    // les sources par club, voir rep_espacer()). Sur les dernières cibles d'une
    // épreuve groupée par club, le nombre de couloirs (clubs) encore actifs tombe
    // sous le nombre de lettres du bloc : l'étalement prime alors sur la cohérence
    // « une lettre par club », quitte à décaler UN club d'une lettre sur cette
    // cible précise par rapport à ses autres cibles (accepté volontairement pour ce
    // cas, plutôt que de laisser les derniers archers tassés d'un côté du pas de
    // tir). `rep_espacer()` ne touche jamais une cible pleine ni une cible à 0/1
    // archer, donc n'affecte que ces cibles de fin de bloc.
    $res = rep_espacer($res, $b);
    return $res;
}

/**
 * Placement complet d'une compétition.
 * Retourne ['affectations'=>[…], 'parBloc'=>[id=>[…]], 'archers'=>[cle=>[…]]].
 * Aucune écriture : c'est la base de l'aperçu comme des contrôles.
 */
function rep_placer_tout($tourId, $session = null)
{
    $cfg   = rep_config_lire($tourId);
    $blocs = rep_blocs($tourId, $session);
    $epr   = rep_epreuves($tourId);
    $cacheArchers = [];
    $cacheOrdre   = [];
    $cacheRangsClubs = [];
    $parEpreuve   = [];   // union des archers par épreuve (dédup par enid), NP compris
    $affect = [];
    $parBloc = [];

    foreach ($blocs as $b) {
        // Le classement retenu (principal + second niveau) dépend de CbSource/
        // CbSource2 : le cache des archers doit donc distinguer les deux, en plus
        // de l'épreuve et de inclnp — sinon un bloc en arrêté et un bloc en
        // national de la même épreuve partageraient à tort le même ordre.
        $ck = $b['cle'] . '|' . (!empty($b['inclnp']) ? '1' : '0') . '|' . $b['src'] . '|' . $b['src2'];
        if (!isset($cacheArchers[$ck])) {
            $cacheArchers[$ck] = rep_archers_epreuve(
                $tourId, $b['event'], $cfg['annee'], $cfg['discipline'], $cfg['set'] ?? '',
                !empty($b['inclnp']), $epr[$b['event']] ?? null, $b['src'], $b['src2']);
        }
        $ordre = null;
        if ($b['src'] == REP_SRC_CLUBS_MANUEL) {
            if (!isset($cacheOrdre[$b['cle']])) {
                $cacheOrdre[$b['cle']] = rep_ordre_clubs_lire($tourId, $b['event']);
            }
            $ordre = $cacheOrdre[$b['cle']];
        }
        $rangsClubs = null;
        if ($b['src'] == REP_SRC_CLUBS_ARR_EQ || $b['src'] == REP_SRC_CLUBS_ARR_DM) {
            $sousType = ($b['src'] == REP_SRC_CLUBS_ARR_EQ) ? 'EQ' : 'DM';
            $division = $epr[$b['event']]['division'] ?? '';
            // Clé par ÉPREUVE, pas seulement division+sous-type : depuis 1.7.2 une
            // division peut avoir plusieurs classements d'équipe (scindés par sexe/
            // catégorie), rep_arr_rangs_clubs() choisit celui compatible avec CETTE
            // épreuve précise — deux épreuves de même division (ex. U18FCL et
            // U18HCL) peuvent donc avoir des rangs différents, jamais partagés.
            $rk = $b['cle'] . '|' . $sousType;
            if (!isset($cacheRangsClubs[$rk])) {
                $cacheRangsClubs[$rk] = rep_arr_rangs_clubs($tourId, $division, $sousType, $epr[$b['event']] ?? null);
            }
            $rangsClubs = $cacheRangsClubs[$rk];
        }
        // Mêmes réglages, mais pour CbSource2 (second niveau) — ne sert qu'au
        // placement hybride (principale non groupée par club, second niveau
        // groupé, voir rep_placer_hybride()) ; mêmes caches que ci-dessus.
        $ordre2 = null;
        if ($b['src2'] == REP_SRC_CLUBS_MANUEL) {
            if (!isset($cacheOrdre[$b['cle']])) {
                $cacheOrdre[$b['cle']] = rep_ordre_clubs_lire($tourId, $b['event']);
            }
            $ordre2 = $cacheOrdre[$b['cle']];
        }
        $rangsClubs2 = null;
        if ($b['src2'] == REP_SRC_CLUBS_ARR_EQ || $b['src2'] == REP_SRC_CLUBS_ARR_DM) {
            $sousType2 = ($b['src2'] == REP_SRC_CLUBS_ARR_EQ) ? 'EQ' : 'DM';
            $division2 = $epr[$b['event']]['division'] ?? '';
            $rk2 = $b['cle'] . '|' . $sousType2;
            if (!isset($cacheRangsClubs[$rk2])) {
                $cacheRangsClubs[$rk2] = rep_arr_rangs_clubs($tourId, $division2, $sousType2, $epr[$b['event']] ?? null);
            }
            $rangsClubs2 = $cacheRangsClubs[$rk2];
        }
        // Union des archers de l'épreuve (pour l'effectif et la libération).
        if (!isset($parEpreuve[$b['cle']])) $parEpreuve[$b['cle']] = [];
        foreach ($cacheArchers[$ck] as $a) $parEpreuve[$b['cle']][$a['enid']] = $a;

        $res = rep_placer($b, $cacheArchers[$ck], $ordre, $rangsClubs, $ordre2, $rangsClubs2);
        $parBloc[$b['id']] = $res;
        foreach ($res as $r) {
            $affect[] = [
                'enid'    => $r['a']['enid'],
                'licence' => $r['a']['licence'],
                'nom'     => $r['a']['nom'],
                'club'    => $r['a']['club'],
                'rang'    => $r['a']['rang'],
                'session' => $b['session'],
                'target'  => $r['t'],
                'letter'  => rep_lettre($r['l']),
                'bloc'    => $b['id'],
                'event'    => $b['event'],
                // division/classe RÉELLES de l'archer (l'épreuve en regroupe plusieurs) :
                // servent de garde-fou à l'écriture, ligne par ligne.
                'division' => $r['a']['division'],
                'class'    => $r['a']['class'],
            ];
        }
    }
    // 'archers' indexé par épreuve (valeurs ré-indexées) pour l'effectif et la libération.
    $archers = [];
    foreach ($parEpreuve as $cle => $m) $archers[$cle] = array_values($m);
    return ['affectations' => $affect, 'parBloc' => $parBloc, 'archers' => $archers, 'blocs' => $blocs];
}

/** Format ianseo de QuTargetNo : départ + cible sur 3 chiffres + lettre → « 1004A ». */
function rep_target_no($session, $target, $letter)
{
    return intval($session) . str_pad(intval($target), 3, '0', STR_PAD_LEFT) . $letter;
}
