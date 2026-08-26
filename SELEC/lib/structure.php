<?php
/**
 * lib/structure.php — génération de la structure ianseo depuis le mode de sélection.
 *
 * Objectif : que l'opérateur n'ait RIEN à créer à la main. À partir du règlement
 * (modes/*.json) et des catégories retenues, le module produit :
 *
 *   - une SESSION par qualification, portant SES propres séries
 *     (session 1 → distances 1-2, session 2 → 3-4, session 3 → 5-6, session 4 → 7-8),
 *     chacune réglée en volées × flèches ;
 *   - pour chaque tournoi et chaque catégorie, DEUX épreuves ianseo : le tableau
 *     principal (places 1-4) et sa consolante liée (places 5-8, épreuve parente) ;
 *   - la portée de chaque épreuve créée, recopiée de l'épreuve de qualification ;
 *   - les grilles de matchs vides ;
 *   - les rattachements du module (SELEC_Bind), pour que le calcul sache où lire.
 *
 * Deux temps, toujours : selec_structure_plan() dit ce qui SERA fait (et ce qui
 * existe déjà), selec_structure_appliquer() le fait. Sur une sélection, on ne
 * lance pas une écriture en masse sans avoir vu la liste d'abord.
 *
 * RIEN N'EST JAMAIS ÉCRASÉ : une épreuve ou une session qui existe déjà est
 * laissée telle quelle et signalée comme telle. Aucun score n'est touché.
 *
 * ── La limite qui décide de tout ──────────────────────────────────────────────
 * `Qualifications` a huit emplacements de distance (QuD1..QuD8) et un seul par
 * archer : le nombre de SESSIONS est libre, celui des DISTANCES ne l'est pas.
 * Quatre qualifications de 2×36 les consomment toutes les huit. Les duels
 * simulés ne peuvent donc pas être des distances de qualification en épreuve 1 :
 * ils se tirent en épreuve de duels (source « evenements » de la brique).
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/donnees.php';

/** Nombre d'emplacements de distance de ianseo. Contrainte de schéma, pas un réglage. */
if (!defined('SELEC_MAX_DISTANCES')) define('SELEC_MAX_DISTANCES', 8);

// ─────────────────────────────────────────────────────────────────────────────
// Plan
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Décrit la structure attendue et la compare à l'existant.
 *
 * @return array ['sessions'=>[], 'epreuves'=>[], 'alertes'=>[], 'distances'=>int]
 */
function selec_structure_plan($tourId, $mode, $cats)
{
    $tourId = intval($tourId);
    $out = array('sessions' => array(), 'epreuves' => array(), 'alertes' => array(), 'distances' => 0);
    $tour = selec_tournoi($tourId);
    $connues = selec_categories($tourId);

    // ── Sessions de qualification ───────────────────────────────────────────
    $sesExistantes = array();
    $rs = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesAth4Target FROM Session
        WHERE SesTournament=$tourId AND SesType='Q'");
    while ($rs && ($r = safe_fetch($rs))) $sesExistantes[intval($r->SesOrder)] = $r;

    $diExistantes = array();
    $rs = safe_r_sql("SELECT DiSession, DiDistance, DiEnds, DiArrows FROM DistanceInformation
        WHERE DiTournament=$tourId AND DiType='Q'");
    while ($rs && ($r = safe_fetch($rs))) {
        $diExistantes[intval($r->DiSession)][intval($r->DiDistance)] = $r;
    }

    // ── Toutes les séries dans CHAQUE départ ────────────────────────────────
    // ISK-NG construit la clé de séquence d'un appareil avec
    // `CONCAT(SesType, ToNumDist, SesOrder)` (Common/Lib/CommonLib.php) : le
    // nombre de séries proposées est celui de la COMPÉTITION (8), pas celui du
    // départ. L'appareil offre donc les séries 1 à 8 quel que soit le départ,
    // puis toute écriture passe par
    // `DistanceInformation … DiSession=QuSession AND DiDistance=<série>`.
    // Ne déclarer que les 2 séries propres à l'étape rendait donc l'appli
    // téléphone inutilisable dès le 2e départ : elle demandait la série 1, qui
    // n'était pas rattachée au départ 2. `MaxEnds`, la liste des volées par
    // série renvoyée à l'appareil, était elle aussi tronquée.
    //
    // On déclare donc TOUTES les séries du règlement dans CHAQUE départ, avec
    // pour chacune le format de l'étape qui la possède. Les scores restent
    // rangés par série : l'étape Q2 lit toujours ses séries 3 et 4, quel que
    // soit le départ où elles ont été saisies.
    $formatDist = array();          // série => [volées, flèches]
    $ordre = 0;
    $distMax = 0;
    $etapesDist = array();
    foreach ($mode['etapes'] as $st) {
        $dists = selec_structure_distances($st);
        if (!$dists) continue;
        $ordre++;
        $struct = isset($st['structure']) ? $st['structure'] : array();
        $etapesDist[] = array(
            'st'      => $st,
            'ses'     => intval($struct['session'] ?? $ordre),
            'dists'   => array_map('intval', $dists),
            'volees'  => intval($struct['volees']  ?? 6),
            'fleches' => intval($struct['fleches'] ?? 6),
            'struct'  => $struct,
        );
        foreach ($dists as $d) {
            $distMax = max($distMax, intval($d));
            $formatDist[intval($d)] = array(intval($struct['volees'] ?? 6), intval($struct['fleches'] ?? 6));
        }
    }
    ksort($formatDist);
    $toutesDist = array_keys($formatDist);

    foreach ($etapesDist as $e) {
        $ses = $e['ses'];
        $etat = 'à créer';
        if (isset($sesExistantes[$ses])) {
            $etat = 'existe';
            $actuelles = array_keys(isset($diExistantes[$ses]) ? $diExistantes[$ses] : array());
            sort($actuelles);
            $voulues = $toutesDist;
            sort($voulues);
            if ($actuelles !== $voulues) $etat = 'à corriger';
        }

        $out['sessions'][] = array(
            'ordre'     => $ses,
            'nom'       => isset($e['struct']['nom']) ? $e['struct']['nom']
                          : (isset($e['st']['libelle']) ? $e['st']['libelle'] : $e['st']['id']),
            'etape'     => $e['st']['id'],
            // Les séries que l'étape LIT — c'est ce qui intéresse l'opérateur.
            'distances' => $e['dists'],
            // Celles réellement déclarées dans le départ, pour ISK-NG.
            'toutes'    => $toutesDist,
            'formats'   => $formatDist,
            'volees'    => $e['volees'],
            'fleches'   => $e['fleches'],
            'etat'      => $etat,
        );
    }
    $out['distances'] = $distMax;

    if ($distMax > SELEC_MAX_DISTANCES) {
        $out['alertes'][] = "Ce mode demande $distMax distances de qualification alors que ianseo "
            . "n'en stocke que " . SELEC_MAX_DISTANCES . " par archer (colonnes QuD1 à QuD"
            . SELEC_MAX_DISTANCES . "). Le nombre de sessions est libre, celui des distances ne l'est pas.";
    }
    if ($tour && $distMax > 0 && intval($tour['nb_dist']) < $distMax) {
        $out['alertes'][] = "La compétition déclare " . intval($tour['nb_dist']) . " distance(s) alors "
            . "que le mode en utilise $distMax. Corrigez le type de compétition avant de saisir des scores.";
    }

    // ── Épreuves de duels ───────────────────────────────────────────────────
    $evExistantes = array();
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvCodeParent, EvFinalFirstPhase, EvNumQualified,
                             EvWinnerFinalRank, EvElimType
        FROM Events WHERE EvTournament=$tourId AND EvTeamEvent=0");
    while ($rs && ($r = safe_fetch($rs))) $evExistantes[$r->EvCode] = $r;

    $binds = selec_binds_tous_local($tourId);

    foreach ((array) $cats as $cat) {
        if (!isset($connues[$cat])) continue;
        foreach ($mode['etapes'] as $st) {
            $slots = selec_structure_slots($st);
            if (!$slots) continue;
            foreach ($slots as $slot => $spec) {
                $code = selec_structure_code($cat, $st['id'], $slot, $evExistantes);
                $lie  = isset($binds[$cat][$st['id']][$slot]) ? $binds[$cat][$st['id']][$slot] : '';
                $etat = isset($evExistantes[$code]) ? 'existe' : 'à créer';
                if ($lie !== '' && $lie !== $code) $etat = 'déjà rattachée à ' . $lie;

                $out['epreuves'][] = array(
                    'categorie' => $cat,
                    'cat_nom'   => $connues[$cat]['nom'],
                    'etape'     => $st['id'],
                    'etape_lib' => isset($st['libelle']) ? $st['libelle'] : $st['id'],
                    'slot'      => $slot,
                    'code'      => $code,
                    'nom'       => $spec['nom'],
                    'type'      => $spec['type'],
                    'etat'      => $etat,
                    'note'      => isset($spec['note']) ? $spec['note'] : '',
                );
                if (!empty($spec['note'])) $out['alertes'][] = $spec['nom'] . ' : ' . $spec['note'];
            }
        }
    }

    return $out;
}

/** Distances de qualification portées par une étape (qualification, ou duels simulés en distances). */
function selec_structure_distances($st)
{
    if ($st['type'] === 'qualification') {
        return isset($st['distances']) ? (array) $st['distances'] : array();
    }
    if ($st['type'] === 'duels_simules'
        && (($st['source']['type'] ?? '') === 'distances')) {
        return (array) ($st['source']['distances'] ?? array());
    }
    return array();
}

/**
 * Épreuves ianseo dont une étape a besoin, par rôle.
 * Retourne [rôle => ['type','nom','phase','qualifies','rangVainqueur','parent','note']].
 */
function selec_structure_slots($st)
{
    $struct = isset($st['structure']) ? $st['structure'] : array();
    $lib = isset($st['libelle']) ? $st['libelle'] : $st['id'];

    if ($st['type'] === 'tournoi') {
        $n = intval($struct['effectif'] ?? 8);
        // Un tableau de N archers démarre à la phase N (8 → quarts, 4 → demies).
        $phase = selec_structure_phase($n);
        $out = array(
            'principal' => array(
                'type' => 'tableau', 'nom' => $lib . ' — tableau principal',
                'phase' => $phase, 'qualifies' => $n, 'rangVainqueur' => 1, 'parent' => '',
                'volees' => intval($struct['volees'] ?? 5), 'fleches' => intval($struct['fleches'] ?? 3),
            ),
        );
        // Consolante : les perdants du premier tour, phase suivante, vainqueur
        // classé juste après le dernier rang du tableau principal.
        if ($n >= 4 && empty($struct['sans_consolante'])) {
            $out['consolante'] = array(
                'type' => 'consolante', 'nom' => $lib . ' — consolante (places ' . (intdiv($n, 2) + 1) . '-' . $n . ')',
                'phase' => selec_structure_phase(intdiv($n, 2)), 'qualifies' => intdiv($n, 2),
                'rangVainqueur' => intdiv($n, 2) + 1, 'parent' => 'principal',
                'volees' => intval($struct['volees'] ?? 5), 'fleches' => intval($struct['fleches'] ?? 3),
            );
        }
        if ($n === 6) {
            $out['principal']['note'] = 'Tournoi à 6 : le 1er tour (1v6, 2v5, 3v4) se joue en tableau, '
                . 'mais le 2e tour est un duel à 3, format inexistant dans ianseo — à saisir à part '
                . 'tant que la brique n\'est pas développée.';
        }
        return $out;
    }

    if ($st['type'] === 'poule') {
        return array('poule' => array(
            'type' => 'poule', 'nom' => $lib,
            'phase' => 0, 'qualifies' => intval($struct['effectif'] ?? 6),
            'rangVainqueur' => 1, 'parent' => '', 'elim' => 5,
            'volees' => intval($struct['volees'] ?? 5), 'fleches' => intval($struct['fleches'] ?? 3),
            'note' => 'Épreuve créée en mode « tous contre tous » : il reste à générer les tours '
                . 'depuis le menu Round Robin de ianseo.',
        ));
    }

    if ($st['type'] === 'duels_simules' && (($st['source']['type'] ?? '') === 'evenements')) {
        // Un duel simulé = un TABLEAU dont seul le premier tour se tire. Huit
        // archers tiennent dans des quarts : quatre duels, tout le monde tire, et
        // aucun inscrit fictif n'entre dans la compétition. On en crée autant que
        // le règlement demande de duels, avec des appariements différents.
        //
        // Le score seul compte (règlement) : les épreuves sont donc en CUMUL
        // (EvMatchMode=0) et non en sets — c'est aussi ce qu'affichent la feuille
        // de marque et la tablette.
        $n = intval($struct['effectif'] ?? 8);
        $nb = max(1, intval($struct['duels'] ?? ($st['nb_duels'] ?? 5)));
        $phase = selec_structure_phase($n);
        $out = array();
        for ($k = 1; $k <= $nb; $k++) {
            $out['ds' . $k] = array(
                'type' => 'tableau', 'nom' => $lib . ' — duel ' . $k,
                'phase' => $phase, 'qualifies' => $n, 'rangVainqueur' => 1, 'parent' => '',
                'volees' => intval($struct['volees'] ?? 5), 'fleches' => intval($struct['fleches'] ?? 3),
                'cumul' => 1, 'tour_unique' => 1, 'duel' => $k,
                'note' => 'Duel simulé ' . $k . '/' . $nb . ' : tableau de ' . $n . ' archers dont '
                    . 'seul le premier tour se tire (' . intdiv($n, 2) . ' duels). Score en cumul, '
                    . 'pas en sets ; le module ne retient que le score, jamais la victoire.',
            );
        }
        return $out;
    }

    return array();
}

/**
 * Phase ianseo de départ d'un tableau de N archers.
 *
 * `EvFinalFirstPhase` est un identifiant de la table Phases, et
 * numQualifiedByPhase() vaut simplement phase × 2 : un tableau de 8 archers
 * démarre donc en phase 4 (« quarts »), un tableau de 4 en phase 2 (« demies »),
 * un duel sec en phase 0 (« finale »). On arrondit au tableau supérieur : 6
 * archers tiennent dans un tableau de 8, avec deux byes.
 */
function selec_structure_phase($n)
{
    $n = max(2, intval($n));
    $taille = 2;
    while ($taille < $n) $taille *= 2;
    return ($taille <= 2) ? 0 : intdiv($taille, 2);
}

/**
 * Code d'épreuve dérivé de la catégorie et de l'étape.
 * EvCode fait 10 caractères au plus : on tronque le préfixe de catégorie, pas le
 * suffixe — c'est lui qui distingue les 12 épreuves d'une même catégorie.
 */
/**
 * Appariements des duels simulés : les voisins de classement, 1-2, 3-4, 5-6…
 *
 * Rien à voir avec un tableau, où le 1er affronte le dernier pour préserver les
 * têtes de série : ici il n'y a pas de tour suivant à préparer, seulement des
 * duels à tirer côte à côte. Apparier les voisins met les archers de niveau
 * comparable face à face et, surtout, permet de les ranger sur les cibles dans
 * l'ordre du classement — le 1er sur la première cible du bloc, le 2e sur la
 * suivante, et ainsi de suite.
 *
 * Les mêmes appariements valent pour tous les duels simulés : les archers gardent
 * leur place du début à la fin. Le classement se fait au total des scores, la
 * victoire ne compte pas, donc faire tourner les adversaires n'apporterait rien.
 *
 * @return array [[têteA, têteB], …] en numéros de tête de série
 */
function selec_paires_classement($n)
{
    $n = max(2, intval($n));
    if ($n % 2) $n++;                       // effectif impair : la dernière place reste vide
    $paires = array();
    for ($i = 1; $i < $n; $i += 2) $paires[] = array($i, $i + 1);
    return $paires;
}

/**
 * Suffixe de code d'épreuve pour un rôle donné.
 *
 * Le rôle n'entre dans le code que lorsqu'une étape porte PLUSIEURS épreuves du
 * même genre : une consolante (`B`) et les duels simulés, dont chaque duel est
 * un tableau distinct (`MS1`, `MS2`…). Sans cela, les cinq duels partageraient
 * le même code et s'écraseraient l'un l'autre.
 */
function selec_structure_suffixe($stepId, $slot)
{
    $s = preg_replace('/[^A-Za-z0-9]/', '', $stepId);
    if ($slot === 'consolante') return $s . 'B';
    if (preg_match('/^ds(\d+)$/i', (string) $slot, $m)) return $s . intval($m[1]);
    return $s;
}

function selec_structure_code($cat, $stepId, $slot, $existantes = array())
{
    $suffixe = selec_structure_suffixe($stepId, $slot);
    $base = preg_replace('/[^A-Za-z0-9]/', '', $cat);
    $max = 10 - strlen($suffixe);
    if ($max < 1) $max = 1;
    $code = substr($base, 0, $max) . $suffixe;

    // Collision avec une épreuve d'une AUTRE catégorie : on raccourcit encore et
    // on numérote, plutôt que d'écraser quoi que ce soit.
    $n = 1;
    while (isset($existantes[$code]) && !selec_structure_meme_origine($existantes[$code], $cat, $stepId, $slot)) {
        $code = substr($base, 0, max(1, $max - 1)) . $n . $suffixe;
        if ($n++ > 9) break;
    }
    return $code;
}

/** Une épreuve existante correspond-elle déjà à ce rôle ? (heuristique de nommage) */
function selec_structure_meme_origine($ev, $cat, $stepId, $slot)
{
    $attendu = selec_structure_code_brut($cat, $stepId, $slot);
    return $ev->EvCode === $attendu;
}

function selec_structure_code_brut($cat, $stepId, $slot)
{
    $suffixe = selec_structure_suffixe($stepId, $slot);
    $base = preg_replace('/[^A-Za-z0-9]/', '', $cat);
    return substr($base, 0, max(1, 10 - strlen($suffixe))) . $suffixe;
}

/**
 * Blason, taille et distance d'une catégorie, lus sur son épreuve de qualification.
 *
 * `EvFinalTargetType` DOIT désigner une ligne réelle de `Targets` (la table
 * commence à TarId=1) : `Obj_Rank_GridInd` fait un INNER JOIN dessus, et une
 * valeur 0 rend l'épreuve invisible à toutes les impressions de match.
 */
function selec_structure_blason($tourId, $cat)
{
    $out = array('type' => 0, 'taille' => 0, 'distance' => 0);
    $rs = safe_r_sql("SELECT EvFinalTargetType, EvTargetSize, EvDistance FROM Events
        WHERE EvTournament=" . intval($tourId) . " AND EvTeamEvent=0
          AND EvCode=" . StrSafe_DB($cat));
    if ($rs && ($r = safe_fetch($rs))) {
        $out['type']     = intval($r->EvFinalTargetType);
        $out['taille']   = intval($r->EvTargetSize);
        $out['distance'] = $r->EvDistance;
    }
    // Repli : le premier blason déclaré dans la compétition, jamais 0.
    if ($out['type'] <= 0) {
        $rs = safe_r_sql("SELECT EvFinalTargetType FROM Events
            WHERE EvTournament=" . intval($tourId) . " AND EvTeamEvent=0
              AND EvFinalTargetType>0 ORDER BY EvProgr LIMIT 1");
        if ($rs && ($r = safe_fetch($rs))) $out['type'] = intval($r->EvFinalTargetType);
    }
    if ($out['type'] <= 0) {
        $rs = safe_r_sql("SELECT MIN(TarId) t FROM Targets");
        if ($rs && ($r = safe_fetch($rs))) $out['type'] = max(1, intval($r->t));
    }
    return $out;
}

/**
 * Reporte le blason de la PREMIÈRE série sur toutes les autres.
 *
 * `Tournament/ManTargets.php` affiche une colonne par distance jusqu'à
 * `ToNumDist` : une compétition à 8 séries dont le blason n'est réglé que sur les
 * deux premières laisse six séries sans blason. Or c'est exactement ce que
 * produit la création d'une compétition — le fichier de setup ne connaît que les
 * deux premières séries au moment où il pose les blasons — et le module ajoute
 * ensuite les distances 3 à 8.
 *
 * Ne remplit QUE les séries laissées à zéro : une série réglée à la main garde
 * son blason, même s'il diffère de celui de la première.
 *
 * @return array ['faces'=>N, 'series'=>N, 'details'=>[]]
 */
function selec_structure_blasons_completer($tourId)
{
    $tourId = intval($tourId);
    $res = array('faces' => 0, 'series' => 0, 'details' => array());

    $rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId=$tourId");
    $r = $rs ? safe_fetch($rs) : null;
    $nbDist = $r ? intval($r->ToNumDist) : 0;
    if ($nbDist < 2) return $res;

    $rs = safe_r_sql("SELECT * FROM TargetFaces WHERE TfTournament=$tourId");
    while ($rs && ($tf = safe_fetch($rs))) {
        $t1 = intval($tf->TfT1);
        $w1 = intval($tf->TfW1);
        if ($t1 <= 0) continue;   // première série elle-même non réglée : on n'invente rien

        $set = array();
        $manquantes = array();
        for ($i = 2; $i <= $nbDist; $i++) {
            $colT = 'TfT' . $i;
            if (intval($tf->$colT) > 0) continue;   // déjà réglée à la main
            $set[] = "TfT$i=" . $t1;
            $set[] = "TfW$i=" . $w1;
            // Caractères de comptage propres à la série, s'ils sont définis sur la première.
            foreach (array('TfGoldsChars', 'TfXNineChars', 'TfTieBreaker3Chars') as $base) {
                $src = $base . '1';
                if (isset($tf->$src) && (string) $tf->$src !== '') {
                    $set[] = $base . $i . '=' . StrSafe_DB($tf->$src);
                }
            }
            $manquantes[] = $i;
        }
        if (!$set) continue;

        safe_w_sql("UPDATE TargetFaces SET " . implode(', ', $set) . "
            WHERE TfTournament=$tourId AND TfId=" . intval($tf->TfId));
        $res['faces']++;
        $res['series'] += count($manquantes);
        $res['details'][] = 'Blason « ' . $tf->TfName . ' » : série'
            . (count($manquantes) > 1 ? 's ' : ' ') . implode(', ', $manquantes)
            . ' reprise' . (count($manquantes) > 1 ? 's' : '') . ' de la série 1.';
    }

    if ($res['faces']) selec_log($tourId, 'blasons', $res);
    return $res;
}

/**
 * Répare les épreuves déjà créées par le module : blason manquant, et portée
 * posée à tort sur une consolante. Idempotent, et borné aux épreuves que le
 * module a lui-même rattachées (SELEC_Bind) — jamais une épreuve de ianseo.
 *
 * @return array ['blasons'=>N, 'portees'=>N, 'details'=>[]]
 */
function selec_structure_reparer($tourId, $mode, $cats)
{
    $tourId = intval($tourId);
    $res = array('blasons' => 0, 'portees' => 0, 'details' => array());
    $binds = selec_binds_tous_local($tourId);

    // Blasons des séries de qualification : les compléter fait partie de la
    // réparation, au même titre que le blason des épreuves de duels.
    $bl = selec_structure_blasons_completer($tourId);
    foreach ($bl['details'] as $d) $res['details'][] = $d;

    foreach ((array) $cats as $cat) {
        if (empty($binds[$cat])) continue;
        $blason = selec_structure_blason($tourId, $cat);

        foreach ($binds[$cat] as $step => $slots) {
            foreach ($slots as $slot => $ev) {
                if ($ev === '') continue;
                $rs = safe_r_sql("SELECT EvFinalTargetType, EvTargetSize, EvDistance FROM Events
                    WHERE EvTournament=$tourId AND EvTeamEvent=0 AND EvCode=" . StrSafe_DB($ev));
                $r = $rs ? safe_fetch($rs) : null;
                if (!$r) continue;

                if (intval($r->EvFinalTargetType) <= 0 && $blason['type'] > 0) {
                    safe_w_sql("UPDATE Events SET
                        EvFinalTargetType=" . intval($blason['type']) . ",
                        EvTargetSize=" . intval($blason['taille']) . ",
                        EvDistance=" . StrSafe_DB($blason['distance']) . "
                        WHERE EvTournament=$tourId AND EvTeamEvent=0 AND EvCode=" . StrSafe_DB($ev));
                    $res['blasons']++;
                    $res['details'][] = "$ev : blason renseigné (sans lui, l'impression "
                        . "« Score du match » sort une page vide).";
                }

                if ($slot === 'consolante') {
                    safe_w_sql("DELETE FROM EventClass WHERE EcTournament=$tourId
                        AND EcTeamEvent=0 AND EcCode=" . StrSafe_DB($ev));
                    if (safe_w_affected_rows()) {
                        $res['portees']++;
                        $res['details'][] = "$ev : division et classe retirées (une consolante "
                            . "reçoit les perdants de son épreuve parente, elle ne recrute pas).";
                    }
                }
            }
        }
    }
    if ($res['blasons'] || $res['portees']) {
        selec_log($tourId, 'reparation', $res);
    }
    return $res;
}

/** Lecture locale des rattachements (évite une dépendance à lib/config.php). */
function selec_binds_tous_local($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT SbCategory, SbStep, SbSlot, SbEvent FROM SELEC_Bind
        WHERE SbTournament=" . intval($tourId));
    while ($rs && ($r = safe_fetch($rs))) $out[$r->SbCategory][$r->SbStep][$r->SbSlot] = $r->SbEvent;
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Application
// ─────────────────────────────────────────────────────────────────────────────

/** Les étapes de duels simulés joués en épreuves, par identifiant. */
function selec_structure_etapes_duels($mode)
{
    $out = array();
    foreach ((array) ($mode['etapes'] ?? array()) as $st) {
        if (($st['type'] ?? '') !== 'duels_simules') continue;
        if (($st['source']['type'] ?? '') !== 'evenements') continue;
        $out[$st['id']] = $st;
    }
    return $out;
}

/**
 * Retire une épreuve de duels simulés devenue caduque.
 *
 * Le premier jet créait UNE épreuve « tous contre tous » par étape (`HCLMS`) ;
 * on crée désormais un tableau par duel (`HCLMS1`…`HCLMS5`). L'ancienne n'a ni
 * groupe ni match — ianseo ne génère rien tant qu'on n'a pas fait le tour du
 * menu Round Robin — mais elle traîne dans la liste des épreuves.
 *
 * Garde-fou : on ne supprime QUE si l'épreuve ne porte aucun score. Une épreuve
 * qui a servi n'est jamais effacée en silence.
 */
function selec_structure_purger_duels($tourId, $mode, $cats)
{
    $tourId = intval($tourId);
    $res = array('supprimees' => 0, 'details' => array());

    foreach (selec_structure_etapes_duels($mode) as $sid => $st) {
        $valides = array();
        foreach (array_keys(selec_structure_slots($st)) as $slot) {
            foreach ($cats as $cat) $valides[selec_structure_code_brut($cat, $sid, $slot)] = true;
        }
        foreach ($cats as $cat) {
            $ancien = selec_structure_code_brut($cat, $sid, 'principal');
            if (isset($valides[$ancien])) continue;

            $rs = safe_r_sql("SELECT EvCode FROM Events
                WHERE EvTournament=$tourId AND EvTeamEvent=0 AND EvCode=" . StrSafe_DB($ancien));
            if (!$rs || !safe_fetch($rs)) continue;

            $n = 0;
            $rs = safe_r_sql("SELECT COUNT(*) n FROM Finals
                WHERE FinTournament=$tourId AND FinEvent=" . StrSafe_DB($ancien) . "
                  AND (FinScore>0 OR FinSetScore>0)");
            if ($rs && ($r = safe_fetch($rs))) $n += intval($r->n);
            $rs = safe_r_sql("SELECT COUNT(*) n FROM RoundRobinMatches
                WHERE RrMatchTournament=$tourId AND RrMatchEvent=" . StrSafe_DB($ancien) . "
                  AND RrMatchScore>0");
            if ($rs && ($r = safe_fetch($rs))) $n += intval($r->n);
            if ($n) {
                $res['details'][] = "Épreuve $ancien conservée : elle porte $n score(s). "
                    . "À supprimer à la main si elle est vraiment caduque.";
                continue;
            }

            // Même séquence que la suppression d'épreuve de ianseo
            // (Final/ListEvents-action.php, case 'delete'), plus les tables
            // propres au round robin et le rattachement du module.
            $c = StrSafe_DB($ancien);
            safe_w_sql("DELETE FROM Events      WHERE EvTournament=$tourId AND EvTeamEvent=0 AND EvCode=$c");
            safe_w_sql("DELETE FROM EventClass  WHERE EcTournament=$tourId AND EcTeamEvent=0 AND EcCode=$c");
            safe_w_sql("DELETE FROM Individuals WHERE IndTournament=$tourId AND IndEvent=$c");
            safe_w_sql("DELETE FROM Finals      WHERE FinTournament=$tourId AND FinEvent=$c");
            safe_w_sql("DELETE FROM FinSchedule WHERE FsTournament=$tourId AND FSTeamEvent=0 AND FSEvent=$c");
            foreach (array('RoundRobinMatches' => 'RrMatch', 'RoundRobinGroup' => 'RrGr',
                           'RoundRobinLevel' => 'RrLev', 'RoundRobinParticipants' => 'RrPart',
                           'RoundRobinGrids' => 'RrGrid') as $t => $p) {
                safe_w_sql("DELETE FROM $t WHERE {$p}Tournament=$tourId AND {$p}Event=$c");
            }
            safe_w_sql("DELETE FROM SELEC_Bind WHERE SbTournament=$tourId AND SbEvent=$c");

            $res['supprimees']++;
            $res['details'][] = "Épreuve $ancien supprimée — remplacée par les tableaux "
                . "d'un duel chacun.";
        }
    }
    return $res;
}

/**
 * Crée ce qui manque. Idempotent : relancer ne duplique rien et n'écrase rien.
 *
 * @param array $quoi ['sessions'=>bool, 'epreuves'=>bool, 'duels'=>bool]
 *              `duels` restreint le travail aux seules épreuves de duels simulés
 *              — les créer, les réparer, et retirer celles de l'ancien format.
 */
function selec_structure_appliquer($tourId, $mode, $cats, $quoi = array(), $options = array())
{
    global $CFG;
    $tourId = intval($tourId);
    $duelsSeuls = !empty($quoi['duels']);
    $faireSes = !$duelsSeuls && (!isset($quoi['sessions']) || $quoi['sessions']);
    $faireEv  = $duelsSeuls || (!isset($quoi['epreuves']) || $quoi['epreuves']);

    $res = array('faits' => array(), 'erreurs' => array(), 'sessions' => 0, 'epreuves' => 0);
    $plan = selec_structure_plan($tourId, $mode, $cats);

    if ($duelsSeuls) {
        $etapes = selec_structure_etapes_duels($mode);
        $plan['epreuves'] = array_values(array_filter($plan['epreuves'],
            function ($e) use ($etapes) { return isset($etapes[$e['etape']]); }));
        $plan['sessions'] = array();
    }

    if ($plan['distances'] > SELEC_MAX_DISTANCES) {
        $res['erreurs'][] = 'Structure refusée : ' . $plan['distances'] . ' distances demandées, '
            . SELEC_MAX_DISTANCES . ' possibles. Corrigez le mode de sélection avant de générer.';
        return $res;
    }

    // Chargées seulement si absentes : ces briques de ianseo s'incluent entre
    // elles et redéclarent des fonctions communes selon le point d'entrée.
    if (!function_exists('CreateEventNew'))  require_once($CFG->DOCUMENT_PATH . 'Modules/Sets/lib.php');
    if (!function_exists('insertSession'))   require_once($CFG->DOCUMENT_PATH . 'Tournament/Fun_ManSessions.inc.php');
    if (!function_exists('GetNumQualSessions')) require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Sessions.inc.php');

    // ── Sessions ────────────────────────────────────────────────────────────
    if ($faireSes && $plan['sessions']) {
        $ordreMax = 0;
        foreach ($plan['sessions'] as $s) $ordreMax = max($ordreMax, $s['ordre']);

        // ToNumSession doit couvrir nos sessions, sans jamais réduire l'existant.
        $rs = safe_r_sql("SELECT ToNumSession FROM Tournament WHERE ToId=$tourId");
        $actuel = ($rs && ($r = safe_fetch($rs))) ? intval($r->ToNumSession) : 0;
        if ($ordreMax > $actuel) {
            safe_w_sql("UPDATE Tournament SET ToNumSession=$ordreMax WHERE ToId=$tourId");
        }

        // Gabarit de cible repris de la session 1 si elle existe.
        $tar = 24; $ath = 4;
        $rs = safe_r_sql("SELECT SesTar4Session, SesAth4Target FROM Session
            WHERE SesTournament=$tourId AND SesType='Q' ORDER BY SesOrder LIMIT 1");
        if ($rs && ($r = safe_fetch($rs))) {
            if (intval($r->SesTar4Session)) $tar = intval($r->SesTar4Session);
            if (intval($r->SesAth4Target))  $ath = intval($r->SesAth4Target);
        }

        foreach ($plan['sessions'] as $s) {
            $ses = intval($s['ordre']);
            $rs = safe_r_sql("SELECT SesOrder FROM Session
                WHERE SesTournament=$tourId AND SesType='Q' AND SesOrder=$ses");
            if (!$rs || !safe_fetch($rs)) {
                insertSession($tourId, $ses, 'Q', $s['nom'], '', $tar, $ath, 1, 0);
                $res['faits'][] = "Session $ses créée — « {$s['nom']} »";
                $res['sessions']++;
            } else {
                // On ne renomme que si le nom est vide : ne jamais écraser une
                // organisation déjà saisie par l'opérateur.
                safe_w_sql("UPDATE Session SET SesName=" . StrSafe_DB($s['nom']) . "
                    WHERE SesTournament=$tourId AND SesType='Q' AND SesOrder=$ses AND SesName=''");
            }

            // TOUTES les séries du règlement dans CHAQUE départ, chacune avec le
            // format de l'étape qui la possède. Voir selec_structure_plan() pour
            // le pourquoi : sans cela, l'appli téléphone d'ISK-NG ne peut pas
            // écrire dès le deuxième départ.
            //
            // Les scores ne se mélangent pas pour autant : ils restent rangés
            // par SÉRIE (QuD1…QuD8), et chaque étape lit les siennes.
            $formats = isset($s['formats']) ? $s['formats'] : array();
            $liste = isset($s['toutes']) ? $s['toutes'] : array_map('intval', $s['distances']);
            $in = implode(',', array_map('intval', $liste));
            safe_w_sql("DELETE FROM DistanceInformation
                WHERE DiTournament=$tourId AND DiType='Q' AND DiSession=$ses"
                . ($in ? " AND DiDistance NOT IN ($in)" : ''));
            foreach ($liste as $d) {
                $d = intval($d);
                $vol = isset($formats[$d][0]) ? $formats[$d][0] : intval($s['volees']);
                $fle = isset($formats[$d][1]) ? $formats[$d][1] : intval($s['fleches']);
                safe_w_sql("INSERT INTO DistanceInformation SET
                    DiTournament=$tourId, DiType='Q', DiSession=$ses, DiDistance=$d,
                    DiEnds=" . intval($vol) . ", DiArrows=" . intval($fle) . "
                    ON DUPLICATE KEY UPDATE DiEnds=VALUES(DiEnds), DiArrows=VALUES(DiArrows)");
            }
            $res['faits'][] = "Session $ses → séries " . implode(', ', $liste)
                . " déclarées (l'étape y lit les séries " . implode(', ', (array) $s['distances'])
                . ", " . $s['volees'] . " volées de " . $s['fleches'] . " flèches)";
        }

        // Ajouter des séries sans leur donner de blason les laisserait vides dans
        // Compétition → Blasons : on reporte celui de la première série.
        $bl = selec_structure_blasons_completer($tourId);
        foreach ($bl['details'] as $d) $res['faits'][] = $d;
    }

    // ── Épreuves ────────────────────────────────────────────────────────────
    if ($faireEv && $plan['epreuves']) {
        $rs = safe_r_sql("SELECT MAX(EvProgr) m FROM Events WHERE EvTournament=$tourId");
        $progr = ($rs && ($r = safe_fetch($rs))) ? intval($r->m) : 0;
        $nouvelles = array();

        foreach ($plan['epreuves'] as $e) {
            if ($e['etat'] !== 'à créer') continue;
            $spec = null;
            foreach ($mode['etapes'] as $st) {
                if ($st['id'] !== $e['etape']) continue;
                $slots = selec_structure_slots($st);
                if (isset($slots[$e['slot']])) $spec = $slots[$e['slot']];
            }
            if (!$spec) continue;

            $parent = '';
            if (!empty($spec['parent'])) {
                $parent = selec_structure_code_brut($e['categorie'], $e['etape'], $spec['parent']);
            }

            // Blason, taille et distance repris de l'épreuve de qualification.
            //
            // ⚠ NON NÉGOCIABLE : `EvFinalTargetType` doit désigner une vraie
            // ligne de `Targets`. La table commence à TarId=1 et le classement
            // des grilles fait un INNER JOIN dessus
            // (`Common/Rank/Obj_Rank_GridInd.php`) : une épreuve laissée à 0 ne
            // renvoie AUCUNE ligne, et l'impression « Score du match » de
            // ianseo sort une page vide sans le moindre message.
            $blason = selec_structure_blason($tourId, $e['categorie']);

            $options = array(
                'EvTeamEvent'              => 0,
                'EvFinalFirstPhase'        => intval($spec['phase']),
                'EvNumQualified'           => intval($spec['qualifies']),
                'EvFirstQualified'         => 1,
                'EvWinnerFinalRank'        => intval($spec['rangVainqueur']),
                // Sets par défaut ; cumul pour les duels simulés, où le règlement
                // classe à la somme des scores et ne regarde jamais la victoire.
                // EvMatchMode=0 fait lire FinScore à ianseo au lieu de
                // FinSetScore — feuille de marque et tablette suivent.
                'EvMatchMode'              => empty($spec['cumul']) ? 1 : 0,
                'EvFinEnds'                => intval($spec['volees']),
                'EvFinArrows'              => intval($spec['fleches']),
                'EvFinSO'                  => 1,
                'EvElimEnds'               => intval($spec['volees']),
                'EvElimArrows'             => intval($spec['fleches']),
                'EvElimSO'                 => 1,
                'EvElimType'               => intval($spec['elim'] ?? 0),
                'EvCodeParent'             => $parent,
                'EvCodeParentWinnerBranch' => 0,   // 0 = branche des perdants
                'EvMedals'                 => 0,   // pas de podium sur une étape de sélection
                'EvFinalTargetType'        => $blason['type'],
                'EvTargetSize'             => $blason['taille'],
                'EvDistance'               => $blason['distance'],
            );

            try {
                CreateEventNew($tourId, $e['code'], mb_substr($e['nom'], 0, 64), ++$progr, $options);
            } catch (Exception $ex) {
                $res['erreurs'][] = "Création de {$e['code']} impossible : " . $ex->getMessage();
                continue;
            }

            // Portée (division/classe) : celle de l'épreuve de qualification pour
            // un tableau principal, AUCUNE pour une consolante. Une consolante
            // ne recrute pas d'archers par catégorie : elle reçoit les perdants
            // de son épreuve parente. Lui donner une portée y ferait entrer tous
            // les archers de la catégorie.
            if ($e['slot'] === 'consolante') {
                $res['faits'][] = "Épreuve {$e['code']} — consolante, sans division ni classe "
                    . "(elle reçoit les perdants de {$parent}).";
            } else {
                $n = 0;
                $rsC = safe_r_sql("SELECT EcDivision, EcClass, EcSubClass, EcExtraAddons, EcNumber
                    FROM EventClass WHERE EcTournament=$tourId AND EcTeamEvent=0
                      AND EcCode=" . StrSafe_DB($e['categorie']));
                while ($rsC && ($rc = safe_fetch($rsC))) {
                    InsertClassEvent($tourId, 0, intval($rc->EcNumber), $e['code'],
                        $rc->EcDivision, $rc->EcClass, $rc->EcSubClass, intval($rc->EcExtraAddons));
                    $n++;
                }
                if (!$n) {
                    $res['erreurs'][] = "{$e['code']} créée mais sans portée : l'épreuve "
                        . "{$e['categorie']} n'a aucune division/classe déclarée.";
                }
            }

            $nouvelles[] = $e['code'];
            $res['faits'][] = "Épreuve {$e['code']} — {$e['nom']}";
            $res['epreuves']++;
        }

        // Grilles de matchs vides pour les épreuves qui en ont besoin.
        $grilles = array();
        foreach ($nouvelles as $c) $grilles[] = StrSafe_DB($c);
        if ($grilles) CreateFinalsInd($tourId, implode(',', $grilles));
    }

    // ── Rattachements du module ─────────────────────────────────────────────
    foreach ($plan['epreuves'] as $e) {
        if (strpos($e['etat'], 'déjà rattachée') === 0) continue;
        $rs = safe_r_sql("SELECT EvCode FROM Events WHERE EvTournament=$tourId
            AND EvTeamEvent=0 AND EvCode=" . StrSafe_DB($e['code']));
        if (!$rs || !safe_fetch($rs)) continue;
        safe_w_sql("INSERT INTO SELEC_Bind SET
            SbTournament=$tourId,
            SbCategory=" . StrSafe_DB($e['categorie']) . ",
            SbStep=" . StrSafe_DB($e['etape']) . ",
            SbSlot=" . StrSafe_DB($e['slot']) . ",
            SbEvent=" . StrSafe_DB($e['code']) . "
            ON DUPLICATE KEY UPDATE SbEvent=VALUES(SbEvent)");
    }

    // ── Cibles et horaires des duels ────────────────────────────────────────
    if ($faireEv) {
        $pl = selec_structure_planning($tourId, $mode, $cats, $options);
        foreach ($pl['faits'] as $f) $res['faits'][] = $f;
        foreach ($pl['erreurs'] as $e) $res['erreurs'][] = $e;
    }

    selec_log($tourId, 'structure', array(
        'sessions' => $res['sessions'], 'epreuves' => $res['epreuves'],
        'erreurs' => count($res['erreurs']),
    ));
    return $res;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cibles et horaires des duels
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Tours d'un tableau, du premier au dernier, chacun étant la liste des phases
 * tirées EN MÊME TEMPS.
 *
 * Un tableau de 8 donne [[4], [2], [1, 0]] : la petite finale et la finale se
 * tirent ensemble — c'est ce que fait ianseo lui-même
 * (`Final/Individual/WriteDateTimeAll.php` traite la phase 1 comme « 0 ou 1 »).
 */
function selec_structure_tours($premierePhase)
{
    $p = intval($premierePhase);
    $tours = array();
    while ($p >= 2) { $tours[] = array($p); $p = intdiv($p, 2); }
    $tours[] = array(1, 0);
    return $tours;
}

/** Numéro de cible au format de ianseo (« 1 » → « 0001 »). */
function selec_structure_cible($n)
{
    $pad = defined('TargetNoPadding') ? TargetNoPadding : 4;
    return str_pad(intval($n), $pad, '0', STR_PAD_LEFT);
}

/**
 * Cible de départ et horaire proposés pour chaque tournoi et chaque catégorie.
 *
 * L'opérateur ne renseigne que deux choses : l'heure du PREMIER tour de duels
 * de chaque tournoi, et la première cible de chaque catégorie. Tout le reste se
 * déduit — 35 minutes par tour, le tour suivant enchaîne immédiatement, et
 * chaque catégorie occupe un bloc de cibles égal à l'effectif du tableau.
 */
function selec_structure_planning_defauts($tourId, $mode, $cats, $options = array())
{
    $out = array('cibles' => array(), 'horaires' => array(), 'duree' => 35);
    if (!empty($options['duels']['duree'])) $out['duree'] = max(1, intval($options['duels']['duree']));

    $effectif = 8;
    foreach ((array) $mode['etapes'] as $st) {
        if ($st['type'] === 'tournoi') $effectif = max($effectif, intval($st['structure']['effectif'] ?? 8));
    }

    // Un bloc de cibles par catégorie, dans l'ordre des épreuves.
    $i = 0;
    foreach ((array) $cats as $cat) {
        $defaut = 1 + $i * $effectif;
        $out['cibles'][$cat] = intval($options['duels']['cibles'][$cat] ?? $defaut);
        $i++;
    }

    // Dates et heures : ce que l'opérateur a saisi, sinon vide (rien ne sera
    // écrit tant qu'une heure n'est pas donnée — mieux vaut pas d'horaire qu'un
    // horaire inventé).
    $rs = safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId=" . intval($tourId));
    $debut = ($rs && ($r = safe_fetch($rs))) ? $r->ToWhenFrom : date('Y-m-d');

    $duels = selec_structure_etapes_duels($mode);
    foreach ((array) $mode['etapes'] as $st) {
        $sid = $st['id'];
        $estDuels = isset($duels[$sid]);
        if ($st['type'] !== 'tournoi' && !$estDuels) continue;
        $out['horaires'][$sid] = array(
            'libelle' => isset($st['libelle']) ? $st['libelle'] : $sid,
            'journee' => isset($st['journee']) ? $st['journee'] : '',
            'date'    => (string) ($options['duels']['horaires'][$sid]['date'] ?? ''),
            'heure'   => (string) ($options['duels']['horaires'][$sid]['heure'] ?? ''),
            // Un duel simulé = un tour ; un tournoi = ses tours de tableau.
            'tours'   => $estDuels
                ? count(selec_structure_slots($st))
                : count(selec_structure_tours(selec_structure_phase(intval($st['structure']['effectif'] ?? 8)))),
        );
        if ($out['horaires'][$sid]['date'] === '') $out['horaires'][$sid]['date'] = $debut;
    }
    return $out;
}

/**
 * Écrit les cibles et les horaires de tous les duels.
 *
 * Règle des cibles, relevée sur une configuration faite à la main et validée par
 * l'utilisateur : un tournoi occupe un bloc de N cibles (N = effectif) du début
 * à la fin. À chaque tour, les emplacements du tableau principal prennent les
 * premières cibles du bloc, puis ceux de la consolante prennent les suivantes —
 * autrement dit la consolante récupère les cibles que le principal a libérées.
 * Un archer par cible, une cible par archer.
 */
function selec_structure_planning($tourId, $mode, $cats, $options = array())
{
    $tourId = intval($tourId);
    $res = array('faits' => array(), 'erreurs' => array(), 'lignes' => 0);
    $def = selec_structure_planning_defauts($tourId, $mode, $cats, $options);
    $binds = selec_binds_tous_local($tourId);

    // Emplacements de match par phase, une fois pour toutes.
    $grille = array();
    $rs = safe_r_sql("SELECT GrMatchNo, GrPhase FROM Grids ORDER BY GrPhase, GrMatchNo");
    while ($rs && ($r = safe_fetch($rs))) $grille[intval($r->GrPhase)][] = intval($r->GrMatchNo);

    $etapesDuels = selec_structure_etapes_duels($mode);

    foreach ((array) $mode['etapes'] as $st) {
        $sid = $st['id'];
        $h = isset($def['horaires'][$sid]) ? $def['horaires'][$sid] : null;

        // ── Duels simulés : une épreuve par duel, un seul tour chacune ───────
        // Ils s'enchaînent comme les tours d'un tournoi (même durée), sur le
        // même bloc de cibles : 8 archers, 8 cibles, 4 duels à chaque fois.
        if (isset($etapesDuels[$sid])) {
            if (!$h || $h['heure'] === '') continue;
            $effectif = intval($st['structure']['effectif'] ?? 8);
            $premiere = selec_structure_phase($effectif);
            $slotsDs = array_keys(selec_structure_slots($st));

            foreach ((array) $cats as $cat) {
                $t0 = intval($def['cibles'][$cat] ?? 1);
                $n = 0;
                foreach ($slotsDs as $k => $slot) {
                    $ev = $binds[$cat][$sid][$slot] ?? '';
                    if ($ev === '') continue;
                    $quand = strtotime($h['date'] . ' ' . $h['heure']) + $k * $def['duree'] * 60;
                    $date  = date('Y-m-d', $quand);
                    $heure = date('H:i:s', $quand);

                    $cible = $t0;
                    foreach ((array) ($grille[$premiere] ?? array()) as $m) {
                        $tgt = selec_structure_cible($cible);
                        safe_w_sql("INSERT INTO FinSchedule
                            (FSEvent, FSTeamEvent, FSMatchNo, FSTournament, FSTarget, FSLetter,
                             FSScheduledDate, FSScheduledTime, FSScheduledLen)
                            VALUES (" . StrSafe_DB($ev) . ", 0, " . intval($m) . ", $tourId,
                                    " . StrSafe_DB($tgt) . ", " . StrSafe_DB($tgt) . ",
                                    " . StrSafe_DB($date) . ", " . StrSafe_DB($heure) . ",
                                    " . intval($def['duree']) . ")
                            ON DUPLICATE KEY UPDATE
                                FSTarget=VALUES(FSTarget), FSLetter=VALUES(FSLetter),
                                FSScheduledDate=VALUES(FSScheduledDate),
                                FSScheduledTime=VALUES(FSScheduledTime),
                                FSScheduledLen=VALUES(FSScheduledLen)");
                        $res['lignes']++;
                        $cible++;
                    }
                    $n++;
                }
                if ($n) {
                    $res['faits'][] = "$sid / $cat : $n duel(s) simulé(s), cibles $t0 à "
                        . ($t0 + $effectif - 1) . ", " . $def['duree'] . " min chacun à partir de "
                        . substr($h['heure'], 0, 5) . " le " . $h['date'] . '.';
                }
            }
            continue;
        }

        if ($st['type'] !== 'tournoi') continue;
        if (!$h || $h['heure'] === '') continue;   // pas d'heure donnée : on n'invente rien

        $effectif = intval($st['structure']['effectif'] ?? 8);
        $premiere = selec_structure_phase($effectif);
        $tours = selec_structure_tours($premiere);

        foreach ((array) $cats as $cat) {
            $principal  = $binds[$cat][$sid]['principal']  ?? '';
            $consolante = $binds[$cat][$sid]['consolante'] ?? '';
            if ($principal === '') continue;
            $t0 = intval($def['cibles'][$cat] ?? 1);

            foreach ($tours as $r => $phases) {
                $quand = strtotime($h['date'] . ' ' . $h['heure']) + $r * $def['duree'] * 60;
                $date = date('Y-m-d', $quand);
                $heure = date('H:i:s', $quand);

                // Emplacements du tour : le principal d'abord, la consolante
                // ensuite, chacun trié par numéro de match croissant.
                $slots = array();
                foreach (array($principal, $consolante) as $ev) {
                    if ($ev === '') continue;
                    $rsE = safe_r_sql("SELECT EvFinalFirstPhase p FROM Events
                        WHERE EvTournament=$tourId AND EvTeamEvent=0 AND EvCode=" . StrSafe_DB($ev));
                    $rE = $rsE ? safe_fetch($rsE) : null;
                    if (!$rE) continue;
                    $sien = array();
                    foreach ($phases as $ph) {
                        // Une épreuve ne tire une phase que si elle démarre à
                        // cette phase ou avant.
                        if ($ph > intval($rE->p)) continue;
                        foreach ((array) ($grille[$ph] ?? array()) as $m) $sien[] = $m;
                    }
                    sort($sien);
                    foreach ($sien as $m) $slots[] = array($ev, $m);
                }
                if (!$slots) continue;

                if (count($slots) > $effectif) {
                    $res['erreurs'][] = "$sid / $cat, tour " . ($r + 1) . " : " . count($slots)
                        . " archers pour un bloc de $effectif cibles — bloc trop petit.";
                }

                $cible = $t0;
                foreach ($slots as $s) {
                    list($ev, $m) = $s;
                    $tgt = selec_structure_cible($cible);
                    // Même écriture que Final/LibFinals.php : FSLetter reprend la
                    // cible quand il n'y a qu'un archer par cible.
                    safe_w_sql("INSERT INTO FinSchedule
                        (FSEvent, FSTeamEvent, FSMatchNo, FSTournament, FSTarget, FSLetter,
                         FSScheduledDate, FSScheduledTime, FSScheduledLen)
                        VALUES (" . StrSafe_DB($ev) . ", 0, " . intval($m) . ", $tourId,
                                " . StrSafe_DB($tgt) . ", " . StrSafe_DB($tgt) . ",
                                " . StrSafe_DB($date) . ", " . StrSafe_DB($heure) . ",
                                " . intval($def['duree']) . ")
                        ON DUPLICATE KEY UPDATE
                            FSTarget=VALUES(FSTarget), FSLetter=VALUES(FSLetter),
                            FSScheduledDate=VALUES(FSScheduledDate),
                            FSScheduledTime=VALUES(FSScheduledTime),
                            FSScheduledLen=VALUES(FSScheduledLen)");
                    $res['lignes']++;
                    $cible++;
                }
            }
            $res['faits'][] = "$sid / $cat : cibles $t0 à " . ($t0 + $effectif - 1)
                . ", " . count($tours) . " tours de " . $def['duree'] . " min à partir de "
                . substr($h['heure'], 0, 5) . " le " . $h['date'] . '.';
        }
    }

    if ($res['lignes']) {
        selec_log($tourId, 'planning', array('lignes' => $res['lignes']));
    }
    return $res;
}
