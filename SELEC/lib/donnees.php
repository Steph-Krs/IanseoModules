<?php
/**
 * lib/donnees.php — lecture des données ianseo natives, normalisées pour le moteur.
 *
 * Ce fichier ne calcule AUCUN point de sélection : il ne fait que produire une
 * image fidèle de ce que ianseo contient (scores de qualification, matchs,
 * poules), dans une forme unique que les briques du moteur consomment. Toutes
 * les lectures sont bornées à une compétition et à une épreuve.
 *
 * Aucune écriture ici. Les écritures vivent dans lib/ecriture.php.
 *
 * Conventions ianseo vérifiées sur la base réelle (compétition 617, TAE 70 m) :
 *  - `Qualifications` a 8 emplacements de distance (QuD1..QuD8) et AUCUNE colonne
 *    de compétition : toute lecture doit joindre `Entries` (EnTournament).
 *  - `QuD{n}Gold` compte les 10 ET les X (chars de `ToGoldsChars`, ex. « KL ») ;
 *    `QuD{n}Xnine` compte les seuls X (chars de `ToXNineChars`, ex. « K »).
 *    Le « nombre de 10 » du règlement FFTA = QuD{n}Gold.
 *  - Une flèche = un caractère de l'arrowstring, valeur = ord(c) - ord('A'),
 *    plafonnée à la valeur max de la cible (K = X = 10, L = 10). Un caractère
 *    espace = flèche non tirée.
 *  - `Finals` porte DEUX lignes par match (FinMatchNo 2k et 2k+1) ;
 *    `FinSetPoints` est la liste des scores de set séparés par « | » (c'est le
 *    S1..S5 du tableur DTN), `FinSetPointsByEnd` les points de set gagnés.
 *  - `FinFinalRank` n'est PAS alimenté par ianseo (vérifié : 0 partout) — le
 *    classement d'un tableau se recalcule depuis `Grids`/`Phases`.
 */

require_once __DIR__ . '/schema.php';

// ─────────────────────────────────────────────────────────────────────────────
// Compétition
// ─────────────────────────────────────────────────────────────────────────────

/** Paramètres de la compétition utiles au décodage des scores. */
function selec_tournoi($tourId)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (isset($cache[$tourId])) return $cache[$tourId];

    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToNumDist, ToNumEnds, ToMaxDistScore,
                             ToGoldsChars, ToXNineChars, ToTypeName, ToTypeSubRule
        FROM Tournament WHERE ToId=" . $tourId);
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r) return null;

    $max = intval($r->ToMaxDistScore);
    $t = array(
        'id'          => $tourId,
        'code'        => $r->ToCode,
        'nom'         => $r->ToName,
        'nb_dist'     => intval($r->ToNumDist),
        'max_dist'    => $max,
        // Valeur maximale d'une flèche : 10 en TAE (360 pour 36 flèches).
        'val_max'     => 10,
        // Repli quand l'arrowstring est vide (score saisi sans les flèches).
        'fleches_dist' => $max > 0 ? intval(round($max / 10)) : 0,
        'chars_gold'  => (string) $r->ToGoldsChars,
        'chars_x'     => (string) $r->ToXNineChars,
        'type'        => $r->ToTypeName,
        'sous_regle'  => $r->ToTypeSubRule,
    );
    $cache[$tourId] = $t;
    return $t;
}

/**
 * Valeur d'une flèche depuis son caractère d'arrowstring.
 * Retourne null pour une flèche non tirée (espace ou chaîne vide).
 */
function selec_valeur_fleche($c, $valMax = 10)
{
    if ($c === ' ' || $c === '' || $c === null) return null;
    $v = ord($c) - 65; // 'A' = 0 (manqué)
    if ($v < 0) return null;
    return min($v, $valMax);
}

/**
 * Décode un arrowstring : nombre de flèches tirées, total, nb de 10 (gold), nb de X.
 * Sert de CONTRÔLE face aux agrégats stockés par ianseo, jamais de source unique.
 */
function selec_decoder_fleches($str, $tour)
{
    $n = 0; $total = 0; $gold = 0; $x = 0;
    $len = strlen((string) $str);
    for ($i = 0; $i < $len; $i++) {
        $c = $str[$i];
        $v = selec_valeur_fleche($c, $tour['val_max']);
        if ($v === null) continue;
        $n++;
        $total += $v;
        if ($tour['chars_gold'] !== '' && strpos($tour['chars_gold'], $c) !== false) $gold++;
        if ($tour['chars_x'] !== ''    && strpos($tour['chars_x'], $c)    !== false) $x++;
    }
    return array('fleches' => $n, 'score' => $total, 'gold' => $gold, 'x' => $x);
}

// ─────────────────────────────────────────────────────────────────────────────
// Catégories (= épreuves individuelles ianseo) et archers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Épreuves individuelles de la compétition qui portent des archers.
 * La table de vérité du rattachement archer → épreuve est `Individuals`
 * (IndEvent), jamais EnDivision/EnClass ni les drapeaux EnIndClEvent.
 */
function selec_categories($tourId)
{
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT e.EvCode, e.EvEventName, e.EvProgr, COUNT(i.IndId) AS nb
        FROM Events e
        LEFT JOIN Individuals i ON i.IndEvent = e.EvCode AND i.IndTournament = e.EvTournament
        WHERE e.EvTournament=$tourId AND e.EvTeamEvent=0
        GROUP BY e.EvCode, e.EvEventName, e.EvProgr
        ORDER BY e.EvProgr, e.EvCode");
    while ($rs && ($r = safe_fetch($rs))) {
        $out[$r->EvCode] = array(
            'code'  => $r->EvCode,
            'nom'   => $r->EvEventName,
            'progr' => intval($r->EvProgr),
            'nb'    => intval($r->nb),
        );
    }
    return $out;
}

/** Archers d'une épreuve (via Individuals), avec leur inscription. */
function selec_archers($tourId, $evCode)
{
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT en.EnId, en.EnCode, en.EnName, en.EnFirstName, en.EnSex,
                             en.EnDivision, en.EnClass, en.EnCountry, en.EnStatus,
                             c.CoCode, c.CoName, i.IndIrmType
        FROM Individuals i
        INNER JOIN Entries en ON en.EnId = i.IndId AND en.EnTournament = i.IndTournament
        LEFT  JOIN Countries c ON c.CoId = en.EnCountry
        WHERE i.IndTournament=$tourId
          AND i.IndEvent=" . StrSafe_DB($evCode) . "
          AND en.EnAthlete=1
        ORDER BY en.EnName, en.EnFirstName");
    while ($rs && ($r = safe_fetch($rs))) {
        $out[intval($r->EnId)] = array(
            'id'       => intval($r->EnId),
            'licence'  => $r->EnCode,
            'nom'      => $r->EnName,
            'prenom'   => $r->EnFirstName,
            'affiche'  => trim($r->EnName . ' ' . $r->EnFirstName),
            'sexe'     => intval($r->EnSex),
            'division' => $r->EnDivision,
            'classe'   => $r->EnClass,
            'club'     => $r->CoCode,
            'club_nom' => $r->CoName,
            'irm'      => intval($r->IndIrmType),
        );
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Qualifications
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Scores de qualification distance par distance pour les archers fournis.
 *
 * Retourne [EnId => [numéro de distance => ['score','gold','x','fleches','tire',
 * 'controle']]]. `controle` vaut '' si l'arrowstring recalcule exactement les
 * agrégats ianseo, sinon décrit l'écart — jamais corrigé en silence.
 */
function selec_quals($tourId, $ids)
{
    $tourId = intval($tourId);
    $tour   = selec_tournoi($tourId);
    $out    = array();
    if (!$ids || !$tour) return $out;

    $ids = array_map('intval', array_values($ids));
    $cols = array();
    for ($d = 1; $d <= 8; $d++) {
        $cols[] = "QuD{$d}Score, QuD{$d}Gold, QuD{$d}Xnine, QuD{$d}Arrowstring, QuD{$d}Status";
    }
    $rs = safe_r_sql("SELECT q.QuId, q.QuScore, q.QuGold, q.QuXnine, q.QuIrmType, "
        . implode(', ', $cols) . "
        FROM Qualifications q
        INNER JOIN Entries en ON en.EnId = q.QuId
        WHERE en.EnTournament=$tourId AND q.QuId IN (" . implode(',', $ids) . ")");

    while ($rs && ($r = safe_fetch($rs))) {
        $id = intval($r->QuId);
        $out[$id] = array('_total' => array(
            'score' => intval($r->QuScore), 'gold' => intval($r->QuGold),
            'x' => intval($r->QuXnine), 'irm' => intval($r->QuIrmType),
        ));
        for ($d = 1; $d <= 8; $d++) {
            $sc = "QuD{$d}Score"; $go = "QuD{$d}Gold"; $xn = "QuD{$d}Xnine"; $as = "QuD{$d}Arrowstring";
            $arrow = (string) $r->$as;
            $dec   = selec_decoder_fleches($arrow, $tour);
            $score = intval($r->$sc);
            $gold  = intval($r->$go);
            $x     = intval($r->$xn);

            // Une distance est « tirée » dès qu'un score ou des flèches existent.
            $tire = ($score > 0 || $dec['fleches'] > 0);
            $fleches = $dec['fleches'] > 0 ? $dec['fleches'] : ($tire ? $tour['fleches_dist'] : 0);

            $ctrl = '';
            if ($dec['fleches'] > 0) {
                $ecarts = array();
                if ($dec['score'] !== $score) $ecarts[] = "score {$dec['score']}≠$score";
                if ($dec['gold']  !== $gold)  $ecarts[] = "10 {$dec['gold']}≠$gold";
                if ($dec['x']     !== $x)     $ecarts[] = "X {$dec['x']}≠$x";
                if ($ecarts) $ctrl = implode(', ', $ecarts);
            }

            $out[$id][$d] = array(
                'score'    => $score,
                'gold'     => $gold,
                'x'        => $x,
                'fleches'  => $fleches,
                'tire'     => $tire,
                'controle' => $ctrl,
            );
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Matchs (duels) — table Finals
// ─────────────────────────────────────────────────────────────────────────────

/** Découpe un FinSetPoints (« 26|27|28 ») en liste d'entiers de scores de set. */
function selec_sets($setPoints)
{
    $out = array();
    foreach (explode('|', (string) $setPoints) as $s) {
        $s = trim($s);
        if ($s === '') continue;
        $out[] = intval($s);
    }
    return $out;
}

/**
 * Matchs individuels d'une liste d'épreuves ianseo.
 *
 * Retourne [EnId => [ 'evCode|matchNo' => match ]], chaque match portant :
 *   event, matchno, phase, sets[], set_total, set_nb, score, points_set,
 *   fleches, gagne (1/0/null si indécis), adversaire, barrage.
 */
function selec_matchs($tourId, $events)
{
    $tourId = intval($tourId);
    $out = array();
    if (!$events) return $out;

    $in = array();
    foreach ((array) $events as $e) { if ($e !== '' && $e !== null) $in[] = StrSafe_DB($e); }
    if (!$in) return $out;

    $rs = safe_r_sql("SELECT f.FinEvent, f.FinMatchNo, f.FinAthlete, f.FinRank, f.FinScore,
                             f.FinSetScore, f.FinSetPoints, f.FinSetPointsByEnd,
                             f.FinArrowstring, f.FinTiebreak, f.FinWinLose, f.FinIrmType,
                             g.GrPhase, g.GrPosition
        FROM Finals f
        INNER JOIN Grids g ON g.GrMatchNo = f.FinMatchNo
        WHERE f.FinTournament=$tourId AND f.FinEvent IN (" . implode(',', $in) . ")
        ORDER BY f.FinEvent, f.FinMatchNo");

    $lignes = array();
    while ($rs && ($r = safe_fetch($rs))) {
        $lignes[$r->FinEvent][intval($r->FinMatchNo)] = $r;
    }

    $tour = selec_tournoi($tourId);
    foreach ($lignes as $ev => $matchs) {
        foreach ($matchs as $no => $r) {
            $ath = intval($r->FinAthlete);
            if (!$ath) continue;
            // Les deux lignes d'un même match : 2k et 2k+1.
            $autreNo = ($no % 2 === 0) ? $no + 1 : $no - 1;
            $adv = isset($matchs[$autreNo]) ? $matchs[$autreNo] : null;

            $sets  = selec_sets($r->FinSetPoints);
            $nbSet = count($sets);
            $somme = array_sum($sets);
            $dec   = selec_decoder_fleches((string) $r->FinArrowstring, $tour);

            // Nombre de flèches : l'arrowstring fait foi quand elle existe ;
            // sinon on retombe sur 3 flèches par set (convention duel individuel
            // TAE, celle utilisée par le tableur DTN pour la valeur de flèche).
            $fleches = $dec['fleches'] > 0 ? $dec['fleches'] : $nbSet * 3;

            $gagne = null;
            if ($adv) {
                $wl = intval($r->FinWinLose);
                $wlAdv = intval($adv->FinWinLose);
                if ($wl || $wlAdv) $gagne = $wl ? 1 : 0;
            }

            $out[$ath][$ev . '|' . $no] = array(
                'event'      => $ev,
                'matchno'    => $no,
                'phase'      => intval($r->GrPhase),
                'position'   => intval($r->GrPosition),
                'seed'       => intval($r->FinRank),
                'sets'       => $sets,
                'set_nb'     => $nbSet,
                'set_total'  => $somme,
                'score'      => intval($r->FinScore),
                'points_set' => intval($r->FinSetScore),
                'fleches'    => $fleches,
                // ianseo ne totalise ni les 10 ni les X sur un duel : ses
                // compteurs n'existent que pour les qualifications. Quand le
                // règlement en a besoin — les duels simulés se départagent aux
                // X puis aux 10 — il n'y a qu'une source possible, la chaîne de
                // flèches, et il faut les compter soi-même.
                'gold'       => $dec['gold'],
                'x'          => $dec['x'],
                // Ces compteurs ne valent que si la chaîne de flèches couvre TOUT
                // le match. Une chaîne partielle donnerait un nombre de X trop
                // bas sans rien signaler — d'où la comparaison de son total au
                // score du match, seule preuve qu'elle est complète.
                'fleches_completes' => ($dec['fleches'] > 0 && $dec['score'] === $somme),
                'gagne'      => $gagne,
                'adversaire' => $adv ? intval($adv->FinAthlete) : 0,
                'barrage'    => (string) $r->FinTiebreak,
                'irm'        => intval($r->FinIrmType),
                'source'     => 'finals',
            );
        }
    }
    return $out;
}

/**
 * Classement final d'un tableau de duels, recalculé depuis Grids/Phases.
 *
 * ianseo ne stocke pas FinFinalRank : la place d'un archer se déduit du match
 * de finale (GrPhase 0 → places 1 et 2) et du match de bronze (GrPhase 1 →
 * places 3 et 4), décalées de `EvWinnerFinalRank - 1` (une consolante « 5-8 »
 * porte EvWinnerFinalRank = 5).
 *
 * Retourne ['rangs' => [EnId => rang], 'incomplet' => [messages],
 *           'sans_place' => [EnId => phase d'élimination]].
 *
 * `incomplet` ne contient que ce qui est anormal dans CE tableau (épreuve
 * introuvable, match sans vainqueur). Les archers éliminés avant la finale et le
 * bronze partent dans `sans_place` — un simple constat, pas une alerte : leur
 * place vient normalement de la consolante, que cette fonction ne voit pas.
 * C'est à l'appelant, qui connaît toutes les épreuves de l'étape, de décider si
 * un archer reste réellement sans place. Sur une sélection, un rang ambigu doit
 * se voir — mais une alerte qui crie à tort use la confiance dans les vraies.
 */
function selec_classement_tableau($tourId, $evCode, $matchsParArcher = null)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT EvCode, EvWinnerFinalRank, EvFinalFirstPhase, EvNumQualified
        FROM Events WHERE EvTournament=$tourId AND EvTeamEvent=0
          AND EvCode=" . StrSafe_DB($evCode));
    $ev = $rs ? safe_fetch($rs) : null;
    if (!$ev) {
        return array('rangs' => array(), 'sans_place' => array(),
            'incomplet' => array("Épreuve $evCode introuvable"));
    }

    $offset = max(1, intval($ev->EvWinnerFinalRank)) - 1;

    $rs = safe_r_sql("SELECT f.FinMatchNo, f.FinAthlete, f.FinWinLose, f.FinScore,
                             f.FinSetScore, f.FinIrmType, g.GrPhase
        FROM Finals f
        INNER JOIN Grids g ON g.GrMatchNo = f.FinMatchNo
        WHERE f.FinTournament=$tourId AND f.FinEvent=" . StrSafe_DB($evCode) . "
        ORDER BY g.GrPhase, f.FinMatchNo");

    $parPhase = array();
    while ($rs && ($r = safe_fetch($rs))) {
        $parPhase[intval($r->GrPhase)][intval($r->FinMatchNo)] = $r;
    }

    $rangs = array();
    $alerte = array();

    // Finale (phase 0) → 1 et 2 ; petite finale (phase 1) → 3 et 4.
    foreach (array(0 => 1, 1 => 3) as $phase => $rangHaut) {
        if (empty($parPhase[$phase])) continue;
        $lignes = $parPhase[$phase];
        $nos = array_keys($lignes);
        sort($nos);
        for ($i = 0; $i < count($nos); $i += 2) {
            $a = isset($lignes[$nos[$i]])     ? $lignes[$nos[$i]]     : null;
            $b = isset($lignes[$nos[$i + 1]]) ? $lignes[$nos[$i + 1]] : null;
            if (!$a || !$b) continue;
            $ida = intval($a->FinAthlete); $idb = intval($b->FinAthlete);
            if (!$ida && !$idb) continue;
            $wa = intval($a->FinWinLose);  $wb = intval($b->FinWinLose);
            if (!$wa && !$wb) {
                $alerte[] = "$evCode : match " . $nos[$i] . " sans vainqueur désigné";
                continue;
            }
            $gagnant = $wa ? $ida : $idb;
            $perdant = $wa ? $idb : $ida;
            if ($gagnant) $rangs[$gagnant] = $offset + $rangHaut;
            if ($perdant) $rangs[$perdant] = $offset + $rangHaut + 1;
        }
    }

    // Archers présents dans le tableau mais sans place attribuée : ils ont été
    // éliminés avant la finale/le bronze (tableau à plus de 4 archers). Cas
    // NORMAL dès qu'une consolante existe — on remonte le constat, l'appelant
    // tranche une fois toutes les épreuves de l'étape lues.
    $sansPlace = array();
    $tousPhases = array_keys($parPhase);
    rsort($tousPhases);
    foreach ($tousPhases as $phase) {
        if ($phase <= 1) continue;
        foreach ($parPhase[$phase] as $no => $r) {
            $id = intval($r->FinAthlete);
            if ($id && !isset($rangs[$id]) && !isset($sansPlace[$id])
                && intval($r->FinWinLose) === 0 && intval($r->FinScore) > 0) {
                $sansPlace[$id] = $phase;
            }
        }
    }

    return array('rangs' => $rangs, 'incomplet' => $alerte, 'sans_place' => $sansPlace);
}

// ─────────────────────────────────────────────────────────────────────────────
// Poules — tables RoundRobin*
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Matchs de poule d'une épreuve (individuels : RrMatchTeam = 0).
 * Même forme de sortie que selec_matchs(), pour que les briques ne fassent
 * aucune différence entre un duel de tableau et un duel de poule.
 */
function selec_matchs_poule($tourId, $events)
{
    $tourId = intval($tourId);
    $out = array();
    if (!$events) return $out;

    $in = array();
    foreach ((array) $events as $e) { if ($e !== '' && $e !== null) $in[] = StrSafe_DB($e); }
    if (!$in) return $out;

    $rs = safe_r_sql("SELECT RrMatchEvent, RrMatchLevel, RrMatchGroup, RrMatchRound, RrMatchMatchNo,
                             RrMatchAthlete, RrMatchScore, RrMatchSetScore, RrMatchSetPoints,
                             RrMatchArrowstring, RrMatchTiebreak, RrMatchWinLose, RrMatchIrmType,
                             RrMatchRoundPoints
        FROM RoundRobinMatches
        WHERE RrMatchTournament=$tourId AND RrMatchTeam=0
          AND RrMatchEvent IN (" . implode(',', $in) . ")
        ORDER BY RrMatchEvent, RrMatchLevel, RrMatchGroup, RrMatchRound, RrMatchMatchNo");

    $lignes = array();
    while ($rs && ($r = safe_fetch($rs))) {
        $cle = $r->RrMatchEvent . '|' . intval($r->RrMatchLevel) . '|' . intval($r->RrMatchGroup)
             . '|' . intval($r->RrMatchRound);
        $lignes[$cle][intval($r->RrMatchMatchNo)] = $r;
    }

    $tour = selec_tournoi($tourId);
    foreach ($lignes as $cle => $matchs) {
        foreach ($matchs as $no => $r) {
            $ath = intval($r->RrMatchAthlete);
            if (!$ath) continue;
            $autreNo = ($no % 2 === 0) ? $no + 1 : $no - 1;
            $adv = isset($matchs[$autreNo]) ? $matchs[$autreNo] : null;

            $sets  = selec_sets($r->RrMatchSetPoints);
            $nbSet = count($sets);
            $dec   = selec_decoder_fleches((string) $r->RrMatchArrowstring, $tour);
            $fleches = $dec['fleches'] > 0 ? $dec['fleches'] : $nbSet * 3;

            $gagne = null;
            if ($adv) {
                $wl = intval($r->RrMatchWinLose); $wlAdv = intval($adv->RrMatchWinLose);
                if ($wl || $wlAdv) $gagne = $wl ? 1 : 0;
            }

            $out[$ath][$cle . '|' . $no] = array(
                'event'      => $r->RrMatchEvent,
                'matchno'    => $no,
                'niveau'     => intval($r->RrMatchLevel),
                'poule'      => intval($r->RrMatchGroup),
                'round'      => intval($r->RrMatchRound),
                'phase'      => -1,
                'position'   => 0,
                'seed'       => 0,
                'sets'       => $sets,
                'set_nb'     => $nbSet,
                'set_total'  => array_sum($sets),
                'score'      => intval($r->RrMatchScore),
                'points_set' => intval($r->RrMatchSetScore),
                'pts_round'  => intval($r->RrMatchRoundPoints),
                'fleches'    => $fleches,
                // Même raison qu'en duel de tableau : ianseo ne compte les 10 et
                // les X que sur les qualifications, on les relit des flèches.
                'gold'              => $dec['gold'],
                'x'                 => $dec['x'],
                'fleches_completes' => ($dec['fleches'] > 0 && $dec['score'] === array_sum($sets)),
                'gagne'      => $gagne,
                'adversaire' => $adv ? intval($adv->RrMatchAthlete) : 0,
                'barrage'    => (string) $r->RrMatchTiebreak,
                'irm'        => intval($r->RrMatchIrmType),
                'source'     => 'roundrobin',
            );
        }
    }
    return $out;
}
