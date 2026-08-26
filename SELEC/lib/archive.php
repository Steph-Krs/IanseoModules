<?php
/**
 * lib/archive.php — geler une étape tirée, la relire, la restaurer.
 *
 * POURQUOI CE FICHIER EXISTE
 * `Qualifications` n'a qu'UNE ligne par archer : les huit séries d'une
 * compétition vivent dans QuD1..QuD8. Le module attribue à chaque départ ses
 * propres séries (départ 1 → 1-2, départ 2 → 3-4, …), donc ISK-NG écrit
 * toujours au bon endroit — il ne propose que les distances déclarées pour la
 * session en cours. Mais la page de saisie manuelle de ianseo
 * (`Qualification/index.php`) offre, elle, TOUTES les distances quelle que soit
 * la session : un mauvais choix réécrit une qualification déjà tirée, sans un
 * mot. Et une compétition dont les départs auraient été créés à la main garde,
 * par défaut, les mêmes séries pour tous — là, tout s'écrase.
 *
 * Sur une sélection en équipe de France, ça ne peut pas rester une question de
 * vigilance. Une étape terminée est donc GELÉE : ses scores, ses 10, ses X et
 * ses chaînes de flèches sont recopiés ici, et le moteur ne les relit plus dans
 * `Qualifications`. Son classement devient inaltérable.
 *
 * Trois garanties :
 *  - le gel n'écrit RIEN dans ianseo, il ne fait que copier ;
 *  - une étape gelée reste imprimable telle qu'elle a été tirée (feuilles de
 *    marque comprises, chaîne de flèches à l'appui) ;
 *  - la restauration est possible à tout moment, après affichage des écarts
 *    entre ce qui est gelé et ce qui se trouve actuellement en base.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/donnees.php';

/** Séries de qualification portées par une étape (0 si l'étape n'en a pas). */
function selec_arch_distances($st)
{
    if (!is_array($st)) return array();
    if (($st['type'] ?? '') === 'qualification') {
        return array_map('intval', (array) ($st['distances'] ?? array()));
    }
    if (($st['type'] ?? '') === 'duels_simules' && (($st['source']['type'] ?? '') === 'distances')) {
        return array_map('intval', (array) ($st['source']['distances'] ?? array()));
    }
    return array();
}

/** Étapes du mode qui peuvent être gelées, dans l'ordre du règlement. */
function selec_arch_etapes_gelables($mode)
{
    $out = array();
    foreach ((array) ($mode['etapes'] ?? array()) as $st) {
        if (selec_arch_distances($st)) $out[$st['id']] = $st;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Lecture
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Étapes gelées d'une compétition : [étape => ['archers','date','user']].
 * Une seule requête, appelée à chaque calcul — d'où l'agrégat plutôt que les lignes.
 */
function selec_arch_etat($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT SaStep, COUNT(*) n, MIN(SaDate) d, MAX(SaUser) u
        FROM SELEC_Archive WHERE SaTournament=" . intval($tourId) . " GROUP BY SaStep");
    while ($rs && ($r = safe_fetch($rs))) {
        $out[$r->SaStep] = array('archers' => intval($r->n), 'date' => $r->d, 'user' => $r->u);
    }
    return $out;
}

/**
 * Contenu gelé : [étape][EnId] => ['session','cible','lettre','score','gold','x',
 * 'fleches','distances'=>[n => ['score','gold','x','hits','fleches','arrowstring','volees','par_volee']]].
 *
 * @param string $step limite à une étape (sinon toutes)
 */
function selec_arch_lire($tourId, $step = '')
{
    $out = array();
    $rs = safe_r_sql("SELECT SaStep, SaEntry, SaSession, SaTarget, SaLetter,
                             SaScore, SaGold, SaX, SaArrows, SaData, SaDate, SaUser
        FROM SELEC_Archive WHERE SaTournament=" . intval($tourId)
        . ($step !== '' ? " AND SaStep=" . StrSafe_DB($step) : ''));
    while ($rs && ($r = safe_fetch($rs))) {
        $d = json_decode((string) $r->SaData, true);
        $out[$r->SaStep][intval($r->SaEntry)] = array(
            'session'   => intval($r->SaSession),
            'cible'     => intval($r->SaTarget),
            'lettre'    => $r->SaLetter,
            'score'     => intval($r->SaScore),
            'gold'      => intval($r->SaGold),
            'x'         => intval($r->SaX),
            'fleches'   => intval($r->SaArrows),
            'distances' => is_array($d) ? $d : array(),
            'date'      => $r->SaDate,
            'user'      => $r->SaUser,
        );
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Gel
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Gèle une étape : recopie ce que les archers ont tiré sur ses séries.
 *
 * Ne touche à AUCUNE table de ianseo. Idempotent : re-geler remplace la copie,
 * ce qui permet de figer à nouveau après une correction de score assumée.
 *
 * @return array ['ok','archers','erreurs']
 */
function selec_arch_geler($tourId, $mode, $stepId, $cats, $note = '')
{
    $tourId = intval($tourId);
    $res = array('ok' => false, 'archers' => 0, 'erreurs' => array());

    $gelables = selec_arch_etapes_gelables($mode);
    if (!isset($gelables[$stepId])) {
        $res['erreurs'][] = "L'étape « $stepId » ne porte aucune série de qualification : rien à geler.";
        return $res;
    }
    $st = $gelables[$stepId];
    $dists = selec_arch_distances($st);
    $tour = selec_tournoi($tourId);
    if (!$tour) { $res['erreurs'][] = 'Compétition introuvable.'; return $res; }

    $now = date('Y-m-d H:i:s');
    $user = '';
    if (!empty($_SESSION['AUTH_User']))      $user = (string) $_SESSION['AUTH_User'];
    elseif (!empty($_SESSION['UserLogged'])) $user = (string) $_SESSION['UserLogged'];

    // Volées et flèches par série, telles que configurées AU MOMENT DU GEL : la
    // feuille de marque doit rester reproductible même si le paramétrage change.
    $confDist = array();
    $rs = safe_r_sql("SELECT DiSession, DiDistance, DiEnds, DiArrows FROM DistanceInformation
        WHERE DiTournament=$tourId AND DiType='Q'");
    while ($rs && ($r = safe_fetch($rs))) {
        $confDist[intval($r->DiSession)][intval($r->DiDistance)] =
            array(intval($r->DiEnds), intval($r->DiArrows));
    }

    $n = 0;
    foreach ((array) $cats as $cat) {
        $archers = selec_archers($tourId, $cat);
        if (!$archers) continue;

        $rs = safe_r_sql("SELECT q.* FROM Qualifications q
            INNER JOIN Entries e ON e.EnId = q.QuId
            WHERE e.EnTournament=$tourId AND q.QuId IN (" . implode(',', array_map('intval', array_keys($archers))) . ")");
        while ($rs && ($r = safe_fetch($rs))) {
            $id = intval($r->QuId);
            $ses = intval($r->QuSession);
            $data = array();
            $tScore = 0; $tGold = 0; $tX = 0; $tFleches = 0;

            foreach ($dists as $d) {
                $sc = "QuD{$d}Score"; $hi = "QuD{$d}Hits"; $go = "QuD{$d}Gold";
                $xn = "QuD{$d}Xnine";  $as = "QuD{$d}Arrowstring"; $stt = "QuD{$d}Status";
                $arrow = (string) $r->$as;
                $dec = selec_decoder_fleches($arrow, $tour);
                $fl = $dec['fleches'] > 0 ? $dec['fleches'] : 0;

                $data[$d] = array(
                    'score'       => intval($r->$sc),
                    'hits'        => intval($r->$hi),
                    'gold'        => intval($r->$go),
                    'x'           => intval($r->$xn),
                    'status'      => intval($r->$stt),
                    'arrowstring' => $arrow,
                    'fleches'     => $fl,
                    'volees'      => isset($confDist[$ses][$d][0]) ? $confDist[$ses][$d][0] : 0,
                    'par_volee'   => isset($confDist[$ses][$d][1]) ? $confDist[$ses][$d][1] : 0,
                );
                $tScore += intval($r->$sc);
                $tGold  += intval($r->$go);
                $tX     += intval($r->$xn);
                $tFleches += $fl > 0 ? $fl : (intval($r->$sc) > 0 ? $tour['fleches_dist'] : 0);
            }

            safe_w_sql("INSERT INTO SELEC_Archive SET
                SaTournament=$tourId,
                SaStep=" . StrSafe_DB($stepId) . ",
                SaEntry=$id,
                SaSession=$ses,
                SaTarget=" . intval($r->QuTarget) . ",
                SaLetter=" . StrSafe_DB($r->QuLetter) . ",
                SaScore=$tScore, SaGold=$tGold, SaX=$tX, SaArrows=$tFleches,
                SaData=" . StrSafe_DB(json_encode($data, JSON_UNESCAPED_UNICODE)) . ",
                SaDate=" . StrSafe_DB($now) . ",
                SaUser=" . StrSafe_DB(mb_substr($user, 0, 80)) . "
                ON DUPLICATE KEY UPDATE
                    SaSession=VALUES(SaSession), SaTarget=VALUES(SaTarget), SaLetter=VALUES(SaLetter),
                    SaScore=VALUES(SaScore), SaGold=VALUES(SaGold), SaX=VALUES(SaX),
                    SaArrows=VALUES(SaArrows), SaData=VALUES(SaData),
                    SaDate=VALUES(SaDate), SaUser=VALUES(SaUser)");
            $n++;
        }
    }

    if (!$n) {
        $res['erreurs'][] = "Aucun archer trouvé pour geler l'étape « $stepId ».";
        return $res;
    }
    selec_log($tourId, 'gel', array('etape' => $stepId, 'archers' => $n, 'note' => $note));
    $res['ok'] = true;
    $res['archers'] = $n;
    return $res;
}

/**
 * Gèle toutes les étapes à séries jusqu'à $stepId incluse, sauf celles déjà
 * gelées. Un opérateur qui n'aurait pas figé la première qualification ne doit
 * pas se retrouver avec un trou dans l'historique.
 */
function selec_arch_geler_jusqua($tourId, $mode, $stepId, $cats)
{
    $res = array('ok' => true, 'gelees' => array(), 'erreurs' => array());
    $deja = selec_arch_etat($tourId);
    foreach (selec_arch_etapes_gelables($mode) as $sid => $st) {
        if (isset($deja[$sid])) { if ($sid === $stepId) break; else continue; }
        $r = selec_arch_geler($tourId, $mode, $sid, $cats);
        if ($r['ok']) $res['gelees'][$sid] = $r['archers'];
        else foreach ($r['erreurs'] as $e) $res['erreurs'][] = $e;
        if ($sid === $stepId) break;
    }
    return $res;
}

// ─────────────────────────────────────────────────────────────────────────────
// Écarts et restauration
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Compare le gel d'une étape avec l'état actuel de `Qualifications`.
 *
 * C'est le garde-fou demandé : avant de revenir en arrière, on voit exactement
 * ce qui a bougé depuis le gel — et si rien n'a bougé, on le dit aussi.
 *
 * @return array ['etape','date','user','archers','ecarts'=>[...], 'identique'=>bool]
 */
function selec_arch_ecarts($tourId, $mode, $stepId, $cats)
{
    $tourId = intval($tourId);
    $out = array('etape' => $stepId, 'date' => '', 'user' => '', 'archers' => 0,
        'ecarts' => array(), 'identique' => true);

    $gelables = selec_arch_etapes_gelables($mode);
    if (!isset($gelables[$stepId])) return $out;
    $dists = selec_arch_distances($gelables[$stepId]);

    $arch = selec_arch_lire($tourId, $stepId);
    $arch = isset($arch[$stepId]) ? $arch[$stepId] : array();
    if (!$arch) return $out;

    $noms = array();
    foreach ((array) $cats as $cat) {
        foreach (selec_archers($tourId, $cat) as $id => $a) $noms[$id] = $a['affiche'];
    }

    $ids = array_map('intval', array_keys($arch));
    $rs = safe_r_sql("SELECT q.* FROM Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId
        WHERE e.EnTournament=$tourId AND q.QuId IN (" . implode(',', $ids) . ")");
    $live = array();
    while ($rs && ($r = safe_fetch($rs))) $live[intval($r->QuId)] = $r;

    foreach ($arch as $id => $a) {
        $out['archers']++;
        if (!$out['date']) { $out['date'] = $a['date']; $out['user'] = $a['user']; }
        $r = isset($live[$id]) ? $live[$id] : null;
        if (!$r) {
            $out['ecarts'][] = array('id' => $id, 'nom' => $noms[$id] ?? ('archer ' . $id),
                'quoi' => 'archer absent de la base', 'gele' => $a['score'], 'actuel' => '—');
            $out['identique'] = false;
            continue;
        }
        foreach ($dists as $d) {
            $g = isset($a['distances'][$d]) ? $a['distances'][$d] : null;
            if (!$g) continue;
            $paires = array(
                'score' => array("QuD{$d}Score", 'score'),
                '10'    => array("QuD{$d}Gold",  'gold'),
                'X'     => array("QuD{$d}Xnine", 'x'),
                'flèches' => array("QuD{$d}Arrowstring", 'arrowstring'),
            );
            foreach ($paires as $lib => $p) {
                $col = $p[0]; $cle = $p[1];
                $actuel = (string) $r->$col;
                $gele   = (string) $g[$cle];
                if ($actuel === $gele) continue;
                $out['ecarts'][] = array(
                    'id' => $id, 'nom' => $noms[$id] ?? ('archer ' . $id),
                    'quoi' => "série $d — $lib",
                    'gele' => $cle === 'arrowstring' ? trim($gele) : $gele,
                    'actuel' => $cle === 'arrowstring' ? trim($actuel) : $actuel,
                );
                $out['identique'] = false;
            }
        }
        if (intval($r->QuSession) !== $a['session']
            || intval($r->QuTarget) !== $a['cible'] || (string) $r->QuLetter !== $a['lettre']) {
            $out['ecarts'][] = array('id' => $id, 'nom' => $noms[$id] ?? ('archer ' . $id),
                'quoi' => 'départ / cible',
                'gele' => $a['session'] . ' · ' . $a['cible'] . $a['lettre'],
                'actuel' => intval($r->QuSession) . ' · ' . intval($r->QuTarget) . $r->QuLetter);
            // Un déplacement de départ est NORMAL après une préparation : il ne
            // marque pas l'étape comme altérée, seulement les scores le font.
        }
    }
    return $out;
}

/**
 * Remet en base les valeurs gelées d'une étape.
 *
 * @param bool $avecPlacement remet aussi le départ, la cible et la lettre
 * @return array ['ok','archers','erreurs']
 */
function selec_arch_restaurer($tourId, $mode, $stepId, $avecPlacement = false)
{
    $tourId = intval($tourId);
    $res = array('ok' => false, 'archers' => 0, 'erreurs' => array());

    $gelables = selec_arch_etapes_gelables($mode);
    if (!isset($gelables[$stepId])) {
        $res['erreurs'][] = "L'étape « $stepId » ne porte aucune série de qualification.";
        return $res;
    }
    $dists = selec_arch_distances($gelables[$stepId]);

    $arch = selec_arch_lire($tourId, $stepId);
    $arch = isset($arch[$stepId]) ? $arch[$stepId] : array();
    if (!$arch) { $res['erreurs'][] = "L'étape « $stepId » n'est pas gelée : rien à restaurer."; return $res; }

    $n = 0;
    foreach ($arch as $id => $a) {
        $id = intval($id);
        // Garde-fou : l'archer doit appartenir à CETTE compétition. Qualifications
        // n'a pas de colonne de compétition, la jointure sur Entries est la seule
        // borne possible — sans elle un UPDATE déborderait sur toute la base.
        $rs = safe_r_sql("SELECT EnId FROM Entries WHERE EnId=$id AND EnTournament=$tourId");
        if (!$rs || !safe_fetch($rs)) continue;

        $set = array();
        foreach ($dists as $d) {
            if (empty($a['distances'][$d])) continue;
            $g = $a['distances'][$d];
            $set[] = "QuD{$d}Score="       . intval($g['score']);
            $set[] = "QuD{$d}Hits="        . intval($g['hits']);
            $set[] = "QuD{$d}Gold="        . intval($g['gold']);
            $set[] = "QuD{$d}Xnine="       . intval($g['x']);
            $set[] = "QuD{$d}Status="      . intval($g['status']);
            $set[] = "QuD{$d}Arrowstring=" . StrSafe_DB($g['arrowstring']);
        }
        if ($avecPlacement) {
            $set[] = "QuSession=" . intval($a['session']);
            $set[] = "QuTarget="  . intval($a['cible']);
            $set[] = "QuLetter="  . StrSafe_DB($a['lettre']);
        }
        if (!$set) continue;

        // La jointure répète le contrôle fait juste au-dessus : la borne doit
        // vivre DANS la requête, pas seulement devant elle. C'est la seule
        // écriture du module qui réécrit des scores — elle ne peut pas dépendre
        // d'un test qu'une refonte déplacerait.
        safe_w_sql("UPDATE Qualifications q
            INNER JOIN Entries e ON e.EnId=q.QuId AND e.EnTournament=$tourId
            SET q." . implode(', q.', $set) . ", q.QuTimestamp=q.QuTimestamp
            WHERE q.QuId=$id");
        $n++;
    }

    // Les totaux de la ligne sont la somme des huit séries : ils doivent être
    // recalculés, sinon ils reflètent encore les valeurs écrasées.
    if ($n) {
        safe_w_sql("UPDATE Qualifications q INNER JOIN Entries e ON e.EnId=q.QuId
            SET q.QuScore = q.QuD1Score+q.QuD2Score+q.QuD3Score+q.QuD4Score
                          + q.QuD5Score+q.QuD6Score+q.QuD7Score+q.QuD8Score,
                q.QuHits  = q.QuD1Hits+q.QuD2Hits+q.QuD3Hits+q.QuD4Hits
                          + q.QuD5Hits+q.QuD6Hits+q.QuD7Hits+q.QuD8Hits,
                q.QuGold  = q.QuD1Gold+q.QuD2Gold+q.QuD3Gold+q.QuD4Gold
                          + q.QuD5Gold+q.QuD6Gold+q.QuD7Gold+q.QuD8Gold,
                q.QuXnine = q.QuD1Xnine+q.QuD2Xnine+q.QuD3Xnine+q.QuD4Xnine
                          + q.QuD5Xnine+q.QuD6Xnine+q.QuD7Xnine+q.QuD8Xnine,
                q.QuTimestamp=q.QuTimestamp
            WHERE e.EnTournament=$tourId");
    }

    selec_log($tourId, 'restauration', array('etape' => $stepId, 'archers' => $n,
        'placement' => $avecPlacement ? 1 : 0));
    $res['ok'] = ($n > 0);
    $res['archers'] = $n;
    if (!$n) $res['erreurs'][] = 'Aucun archer restauré.';
    return $res;
}

/** Retire le gel d'une étape (les scores en base ne sont pas touchés). */
function selec_arch_degeler($tourId, $stepId)
{
    safe_w_sql("DELETE FROM SELEC_Archive WHERE SaTournament=" . intval($tourId)
        . " AND SaStep=" . StrSafe_DB($stepId));
    $n = safe_w_affected_rows();
    selec_log($tourId, 'degel', array('etape' => $stepId, 'archers' => $n));
    return $n;
}
