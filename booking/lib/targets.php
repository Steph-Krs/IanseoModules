<?php
/**
 * lib/targets.php — attribution des cibles et contrôle des règles fédérales.
 *
 * Les emplacements possibles ne sont PAS recalculés ici : ils viennent de
 * createAvailableTargetSQL() (Common/Globals.inc.php), la vue virtuelle que le
 * cœur ianseo construit depuis Session (SesFirstTarget/SesTar4Session/
 * SesAth4Target). La table AvailableTarget existe en base mais est morte (son
 * INSERT est commenté dans Fun_ManSessions.inc.php) : ne jamais s'y fier.
 *
 * ⚠️ Qualifications n'a aucune colonne de compétition : toute lecture comme
 * toute écriture passe par une jointure sur Entries (EnTournament).
 */

if (defined('BK_TARGETS_LOADED')) return;
define('BK_TARGETS_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/caps.php';
require_once __DIR__ . '/cohabitation.php';   // cohabitation des blasons (M7)

/** Format ianseo de QuTargetNo : départ + cible sur 3 chiffres + lettre → 1004A. */
function bk_target_no($session, $target, $letter)
{
    return intval($session) . str_pad(intval($target), 3, '0', STR_PAD_LEFT) . strtoupper($letter);
}

/** Emplacements libres d'un départ, dans l'ordre cible puis lettre. */
function bk_free_slots($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $sql = bk_with_tournament($tourId, function () use ($sessionOrder, $tourId) {
        return createAvailableTargetSQL(intval($sessionOrder), $tourId);
    });

    $rs = safe_r_sql("SELECT f.FullTgtTarget AS t, f.FullTgtLetter AS l
        FROM ($sql) f
        LEFT JOIN (
            SELECT q.QuTarget, q.QuLetter, q.QuSession
              FROM Qualifications q
              INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        ) o ON o.QuSession = f.FullTgtSession
           AND o.QuTarget  = f.FullTgtTarget
           AND o.QuLetter  = f.FullTgtLetter
        WHERE o.QuTarget IS NULL
        ORDER BY f.FullTgtTarget, f.FullTgtLetter");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = array('t' => intval($r->t), 'l' => $r->l);
    return $out;
}

/** Archers d'un départ, avec leur club et leur éventuelle place déjà attribuée. */
function bk_session_archers($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
                e.EnCountry, e.EnTargetFace, c.CoCode, c.CoName,
                q.QuTarget, q.QuLetter, r.BrRequest, r.BrEnId AS BrRow, r.BrValidated
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        LEFT  JOIN Countries c ON c.CoId = e.EnCountry
        LEFT  JOIN BK_Registrations r ON r.BrEnId = e.EnId
        WHERE e.EnTournament = $tourId AND e.EnAthlete = 1
          AND q.QuSession = " . intval($sessionOrder) . "
        ORDER BY c.CoCode, e.EnFirstName, e.EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Idem, mais avec les demandes structurées et l'origine de l'inscription. */
function bk_session_archers_full($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass,
                e.EnCountry, e.EnTargetFace, c.CoCode, c.CoName,
                q.QuTarget, q.QuLetter,
                r.BrEnId, r.BrRequest, r.BrWantLetter, r.BrWantWith, r.BrValidated
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        LEFT  JOIN Countries c ON c.CoId = e.EnCountry
        LEFT  JOIN BK_Registrations r ON r.BrEnId = e.EnId
        WHERE e.EnTournament = $tourId AND e.EnAthlete = 1
          AND q.QuSession = " . intval($sessionOrder) . "
        ORDER BY c.CoCode, e.EnFirstName, e.EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Attribue une cible aux archers d'un départ qui n'en ont pas encore.
 *
 * Ne DÉPLACE jamais un archer déjà placé : l'organisateur a pu ajuster à la
 * main (ou un autre outil l'a placé), et une inscription tardive ne doit pas
 * rebattre les cartes de tout le monde.
 *
 * Contraintes appliquées, dans l'ordre de priorité :
 *  1. **Possibilités du terrain** (BK_TargetCaps) — une cible qui n'accepte pas
 *     la distance ou le blason de l'archer est éliminée. Contrainte DURE : on
 *     préfère laisser un archer non placé plutôt que sur une cible impossible.
 *  2. **Une seule distance par cible** — deux archers d'une même cible tirent
 *     ensemble : contrainte physique, pas une règle de cohabitation de blasons.
 *  3. `BcMaxPerClubPerTarget` archers d'un même club au plus par cible. Souple :
 *     dépassée en dernier recours, et signalée au contrôle.
 *
 * Les archers sont servis club par club en rotation, pour étaler les clubs.
 *
 * Retourne ['places'=>N, 'restants'=>M, 'compromis'=>K, 'incompatibles'=>I] —
 * `incompatibles` compte les archers qu'aucune cible du départ ne peut recevoir.
 */
function bk_assign_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    $max    = max(1, intval($cfg->BcMaxPerClubPerTarget));

    $archers = bk_session_archers_full($tourId, $sessionOrder);
    $slots   = bk_free_slots($tourId, $sessionOrder);

    $rs = safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule FROM Tournament WHERE ToId = $tourId");
    $tr = safe_fetch($rs);
    $type = $tr ? $tr->ToType : '';
    $caps = bk_caps_get($tourId, $sessionOrder);

    // Cohabitation des blasons (M7) : discipline + rythme (archers/cible) du départ.
    // Pour TAE/18m, une cible ne peut porter qu'un ensemble de blasons dont la somme
    // des « coûts » tient dans le budget physique (voir lib/cohabitation.php).
    $dd   = bk_comp_discipline($type, $tr ? $tr->ToTypeSubRule : '', $tr ? $tr->ToTypeName : '');
    $disc = $dd['key'];
    $sr   = safe_fetch(safe_r_sql("SELECT SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = " . intval($sessionOrder) . " AND SesType = 'Q'"));
    $rhythm = $sr ? max(1, intval($sr->SesAth4Target)) : 1;

    // Besoins de chaque archer (distances + blason), mis en cache par catégorie.
    $needCache = array();
    $needs = function ($a) use (&$needCache, $tourId, $type) {
        $k = $a->EnDivision . '|' . $a->EnClass . '|' . intval($a->EnTargetFace);
        if (!isset($needCache[$k])) {
            $needCache[$k] = bk_caps_needs($tourId, $type, $a->EnDivision, $a->EnClass, $a->EnTargetFace);
        }
        return $needCache[$k];
    };

    // Occupation des cibles déjà en place : quota par club ET distance déjà
    // imposée à la cible par ses occupants.
    $parCible = array();
    $distCible = array();
    $facesCible = array();     // cible → liste des classes de blason déjà posées (cohabitation)
    $aPlacer  = array();
    foreach ($archers as $a) {
        $club = (string) ($a->CoCode ?: '?');
        if (intval($a->QuTarget) > 0) {
            $t = intval($a->QuTarget);
            $parCible[$t][$club] = ($parCible[$t][$club] ?? 0) + 1;
            $distCible[$t] = bk_caps_dist_key($needs($a));
            $facesCible[$t][] = bk_face_class_by_id($tourId, $a->EnTargetFace);
        } else {
            // Inscription en ligne non encore validée (mode manuel) → non placée.
            if (!empty($a->BrEnId) && intval($a->BrValidated) === 0) continue;
            $aPlacer[$club][] = $a;
        }
    }
    if (!$aPlacer) return array('places' => 0, 'restants' => 0, 'compromis' => 0,
                               'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);

    // File d'attente : un archer de chaque club à tour de rôle, les plus gros
    // clubs d'abord — c'est ce qui étale les clubs sur toutes les cibles.
    uasort($aPlacer, function ($x, $y) { return count($y) - count($x); });
    $file = array();
    while ($aPlacer) {
        foreach ($aPlacer as $club => $liste) {
            $file[] = array_shift($aPlacer[$club]);
            if (!$aPlacer[$club]) unset($aPlacer[$club]);
        }
    }

    /* ---- Demandes « avec untel » : on regroupe avant de placer ------------
       Un vœu de ce type ne peut pas se satisfaire en plaçant les archers un à
       un dans l'ordre d'arrivée. On constitue donc des grappes (union-find
       simplifié), et on remonte chaque grappe en tête de file : ses membres
       seront servis consécutivement, donc sur la même cible tant qu'il y a de
       la place. Les grappes plus grandes qu'une cible débordent — inévitable. */
    $indexLic = array();
    foreach ($file as $i => $a) $indexLic[bk_clean_licence($a->EnCode)] = $i;

    $groupe = array();                       // EnId → identifiant de grappe
    $racine = function ($x) use (&$groupe, &$racine) {
        while (isset($groupe[$x]) && $groupe[$x] !== $x) $x = $groupe[$x];
        return $x;
    };
    foreach ($file as $a) {
        $me = intval($a->EnId);
        if (!isset($groupe[$me])) $groupe[$me] = $me;
        $want = bk_clean_licence($a->BrWantWith ?? '');
        if ($want === '' || !isset($indexLic[$want])) continue;
        $lui = intval($file[$indexLic[$want]]->EnId);
        if (!isset($groupe[$lui])) $groupe[$lui] = $lui;
        $ra = $racine($me); $rb = $racine($lui);
        if ($ra !== $rb) $groupe[$ra] = $rb;
    }
    $grappes = array();
    foreach ($file as $i => $a) $grappes[$racine(intval($a->EnId))][] = $i;

    // Au sein d'une grappe, l'ANCRE d'abord : celui que les autres ont désigné.
    // Si la grappe ne tient pas entièrement sur une cible (quota de club), c'est
    // lui qui doit rester — sinon les demandeurs se retrouvent groupés entre eux
    // sans la personne qu'ils avaient demandée, ce qui ne satisfait personne.
    $cite = array();
    foreach ($file as $a) {
        $w = bk_clean_licence($a->BrWantWith ?? '');
        if ($w !== '') $cite[$w] = ($cite[$w] ?? 0) + 1;
    }
    foreach ($grappes as &$g) {
        usort($g, function ($x, $y) use ($file, $cite) {
            $cx = $cite[bk_clean_licence($file[$x]->EnCode)] ?? 0;
            $cy = $cite[bk_clean_licence($file[$y]->EnCode)] ?? 0;
            return $cy - $cx;
        });
    }
    unset($g);

    // Grappes réelles (≥ 2) d'abord, les plus grandes en tête ; puis les isolés
    // dans l'ordre de brassage des clubs déjà calculé.
    uasort($grappes, function ($x, $y) { return count($y) - count($x); });
    $ordre = array();
    $enGrappe = array();          // index (dans la NOUVELLE file) → membre d'une grappe
    foreach ($grappes as $g) { if (count($g) > 1) foreach ($g as $i) { $ordre[] = $i; } }
    foreach ($grappes as $g) { if (count($g) === 1) $ordre[] = $g[0]; }
    $nouvelle = array();
    foreach ($ordre as $rang => $i) {
        $nouvelle[] = $file[$i];
        $enGrappe[intval($file[$i]->EnId)] = (count($grappes[$racine(intval($file[$i]->EnId))]) > 1);
    }
    $file = $nouvelle;

    // Comptage des vœux, pour rendre compte à l'organisateur.
    $voeux = 0;
    foreach ($file as $a) {
        if (trim((string) ($a->BrWantLetter ?? '')) !== '' || trim((string) ($a->BrWantWith ?? '')) !== '') $voeux++;
    }
    $voeuxOk = 0;

    $places = 0; $compromis = 0; $pose = array();
    foreach ($slots as $slot) {
        if (!$file) break;
        $t = $slot['t'];

        // Candidats que CETTE cible peut physiquement recevoir : capacités du
        // terrain, puis distance déjà imposée par les occupants de la cible.
        $eligibles = array();
        foreach ($file as $i => $a) {
            $n = $needs($a);
            if (!bk_caps_target_ok($caps, $t, $n)) continue;
            if (isset($distCible[$t]) && $distCible[$t] !== bk_caps_dist_key($n)) continue;
            // Cohabitation : le blason de l'archer doit tenir dans le budget restant de
            // la cible compte tenu des blasons déjà posés (TAE/18m ; sinon sans effet).
            if (bk_cohabit_max_add($facesCible[$t] ?? array(),
                    bk_face_class_by_id($tourId, $a->EnTargetFace), $disc, $rhythm) < 1) continue;
            $eligibles[] = $i;
        }
        if (!$eligibles) continue;   // aucune cible impossible : on laisse le créneau vide

        // Parmi eux, le premier dont le club tient encore sur cette cible.
        // À égalité, on privilégie celui qui a demandé CETTE lettre : le vœu ne
        // passe jamais devant une règle, seulement devant l'ordre d'arrivée.
        $possibles = array();
        foreach ($eligibles as $i) {
            $club = (string) ($file[$i]->CoCode ?: '?');
            if (($parCible[$t][$club] ?? 0) < $max) $possibles[] = $i;
        }
        $idx = null;
        if ($possibles) {
            // Préférence de lettre : uniquement parmi les archers SANS grappe.
            // Laisser un vœu de lettre doubler un membre de grappe casserait
            // l'adjacence qui, elle, satisfait un vœu « avec untel » — deux
            // demandes rompues au lieu d'une.
            foreach ($possibles as $i) {
                if (!empty($enGrappe[intval($file[$i]->EnId)])) continue;
                if (strtoupper(trim((string) ($file[$i]->BrWantLetter ?? ''))) === strtoupper($slot['l'])) {
                    $idx = $i; break;
                }
            }
            if ($idx === null) $idx = $possibles[0];
        } else {
            $idx = $eligibles[0]; $compromis++;   // quota dépassé, signalé
        }

        $a    = $file[$idx];
        $club = (string) ($a->CoCode ?: '?');
        $distCible[$t] = bk_caps_dist_key($needs($a));
        unset($file[$idx]);
        $file = array_values($file);

        $no = bk_target_no($sessionOrder, $slot['t'], $slot['l']);
        // Garde-fou redondant : l'UPDATE cible un EnId précis ET revérifie la
        // compétition — Qualifications seule déborderait sur toute la base.
        safe_w_sql("UPDATE Qualifications q
            INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
            SET q.QuTarget = " . intval($slot['t']) . ",
                q.QuLetter = " . StrSafe_DB($slot['l']) . ",
                q.QuTargetNo = " . StrSafe_DB($no) . ",
                q.QuTimestamp = q.QuTimestamp
            WHERE q.QuId = " . intval($a->EnId));

        $parCible[$t][$club] = ($parCible[$t][$club] ?? 0) + 1;
        $facesCible[$t][] = bk_face_class_by_id($tourId, $a->EnTargetFace);
        $pose[bk_clean_licence($a->EnCode)] = array('t' => $t, 'l' => strtoupper($slot['l']), 'a' => $a);
        $places++;
    }

    // Satisfaction réelle, mesurée APRÈS coup plutôt que devinée pendant le
    // placement : un vœu « avec untel » ne se juge qu'une fois les deux posés.
    foreach ($archers as $a) {
        if (intval($a->QuTarget) > 0) {
            $pose[bk_clean_licence($a->EnCode)] = array(
                't' => intval($a->QuTarget), 'l' => strtoupper($a->QuLetter), 'a' => $a);
        }
    }
    foreach ($pose as $lic => $p) {
        $wl = strtoupper(trim((string) ($p['a']->BrWantLetter ?? '')));
        $ww = bk_clean_licence($p['a']->BrWantWith ?? '');
        if ($wl === '' && $ww === '') continue;
        $ok = true;
        if ($wl !== '' && $p['l'] !== $wl) $ok = false;
        if ($ww !== '' && (!isset($pose[$ww]) || $pose[$ww]['t'] !== $p['t'])) $ok = false;
        if ($ok) $voeuxOk++;
    }

    // Ceux qu'AUCUNE cible du départ ne peut recevoir : à distinguer d'un simple
    // manque de place, car la cause est la configuration du terrain.
    $incompatibles = 0;
    foreach ($file as $a) {
        $n = $needs($a);
        $ok = false;
        foreach ($slots as $s) {
            if (bk_caps_target_ok($caps, $s['t'], $n)) { $ok = true; break; }
        }
        if (!$ok) $incompatibles++;
    }

    return array('places' => $places, 'restants' => count($file),
                 'compromis' => $compromis, 'incompatibles' => $incompatibles,
                 'voeux' => $voeux, 'voeuxOk' => $voeuxOk);
}

/**
 * Places ENCORE disponibles sur un départ pour un PROFIL précis (arme, catégorie,
 * blason) — c'est la « jauge spécifique » de l'inscription, et le contrôle d'admission :
 * 0 ⇒ plus aucune cible ne peut recevoir cet archer, l'inscription doit être refusée.
 *
 * Reproduit exactement l'éligibilité de bk_assign_session (capacités du terrain, une
 * seule distance par cible, cohabitation des blasons) et somme, sur les cibles à
 * lettres libres, ce que chacune peut encore accueillir de ce profil. Renvoie null si
 * la contrainte n'est pas connue (départ absent). Basé sur les placements actuels :
 * exact en validation AUTOMATIQUE (chaque inscription est placée aussitôt) ; en
 * validation MANUELLE, les inscriptions en attente ne sont pas encore posées.
 */
function bk_profile_remaining($tourId, $sessionOrder, $division, $class, $faceId)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);

    $tr = safe_fetch(safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule FROM Tournament WHERE ToId = $tourId"));
    if (!$tr) return null;
    $type = $tr->ToType;
    $dd   = bk_comp_discipline($type, $tr->ToTypeSubRule, $tr->ToTypeName);
    $disc = $dd['key'];

    $ses = safe_fetch(safe_r_sql("SELECT SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = $sessionOrder AND SesType = 'Q'"));
    if (!$ses) return null;
    $rhythm = max(1, intval($ses->SesAth4Target));

    $caps      = bk_caps_get($tourId, $sessionOrder);
    $needCache = array();
    $needOf = function ($div, $cls, $fid) use (&$needCache, $tourId, $type) {
        $k = $div . '|' . $cls . '|' . intval($fid);
        if (!isset($needCache[$k])) $needCache[$k] = bk_caps_needs($tourId, $type, $div, $cls, $fid);
        return $needCache[$k];
    };
    $needs     = $needOf($division, $class, $faceId);
    $distKey   = bk_caps_dist_key($needs);
    $faceClass = bk_face_class_by_id($tourId, intval($faceId));

    $slots = bk_free_slots($tourId, $sessionOrder);
    $targets = array();
    foreach ($slots as $s) $targets[intval($s['t'])] = true;
    if (!$targets) return 0;

    // Occupation actuelle : blasons et distance imposés par les archers déjà placés.
    $facesCible = array(); $distCible = array();
    foreach (bk_session_archers_full($tourId, $sessionOrder) as $a) {
        if (intval($a->QuTarget) <= 0) continue;
        $t = intval($a->QuTarget);
        $facesCible[$t][] = bk_face_class_by_id($tourId, $a->EnTargetFace);
        $distCible[$t] = bk_caps_dist_key($needOf($a->EnDivision, $a->EnClass, $a->EnTargetFace));
    }

    $remaining = 0;
    foreach (array_keys($targets) as $t) {
        if (!bk_caps_target_ok($caps, $t, $needs)) continue;
        if (isset($distCible[$t]) && $distCible[$t] !== $distKey) continue;
        $remaining += bk_cohabit_max_add($facesCible[$t] ?? array(), $faceClass, $disc, $rhythm);
    }
    return $remaining;
}

/**
 * Replanifie ENTIÈREMENT un départ pour satisfaire au mieux les demandes des
 * archers, puis replace tout le monde.
 *
 * Appelée après chaque inscription : une demande « avec untel » ne peut pas se
 * satisfaire en plaçant les gens un par un dans l'ordre d'arrivée — il faut
 * pouvoir rebattre les cartes.
 *
 * ⚠️ Ne libère QUE les inscriptions créées par ce module (présentes dans
 * BK_Registrations). Un participant saisi par l'organisateur, ou placé par un
 * autre outil, garde sa cible : elle devient un obstacle fixe. Sans cette
 * garde, une inscription en ligne déplacerait le travail manuel de
 * l'organisateur.
 */
function bk_replan_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        INNER JOIN BK_Registrations r ON r.BrEnId = e.EnId
        SET q.QuTarget = 0, q.QuLetter = '', q.QuTargetNo = '', q.QuTimestamp = q.QuTimestamp
        WHERE q.QuSession = " . intval($sessionOrder));
    return bk_assign_session($tourId, $sessionOrder, $cfg);
}

/**
 * Replanifie tous les départs où le module a des inscriptions. Utilisée après
 * une inscription ou une annulation, quand l'organisateur laisse le placement
 * automatique actif.
 */
function bk_replan_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT DISTINCT q.QuSession FROM Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        INNER JOIN BK_Registrations r ON r.BrEnId = e.EnId
        WHERE q.QuSession > 0");
    $tot = array('places' => 0, 'restants' => 0, 'compromis' => 0, 'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);
    while ($r = safe_fetch($rs)) {
        $x = bk_replan_session($tourId, intval($r->QuSession), $cfg);
        foreach ($tot as $k => $v) $tot[$k] = $v + ($x[$k] ?? 0);
    }
    return $tot;
}

/* ---- Validation manuelle des inscriptions -------------------------------- */

/** Inscriptions en ligne en attente de validation (BrValidated=0). */
function bk_pending_registrations($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT r.BrEnId, r.BrCreated, e.EnFirstName, e.EnName, e.EnCode,
                e.EnDivision, e.EnClass, d.DivDescription, cl.ClDescription,
                c.CoName, c.CoCode, q.QuSession
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        LEFT  JOIN Qualifications q ON q.QuId = e.EnId
        LEFT  JOIN Divisions d      ON d.DivTournament = e.EnTournament AND d.DivId = e.EnDivision
        LEFT  JOIN Classes cl       ON cl.ClTournament = e.EnTournament AND cl.ClId = e.EnClass
        LEFT  JOIN Countries c      ON c.CoId = e.EnCountry
        WHERE r.BrTournament = $tourId AND r.BrValidated = 0
        ORDER BY r.BrCreated, r.BrId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Nombre d'inscriptions en attente de validation. */
function bk_pending_count($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) n FROM BK_Registrations
        WHERE BrTournament = " . $tourId . " AND BrValidated = 0"));
    return $r ? intval($r->n) : 0;
}

/** Valide une inscription puis place son départ. Retourne bool. */
function bk_validate_registration($tourId, $enId, $cfg)
{
    $tourId = intval($tourId); $enId = intval($enId);
    $r = safe_fetch(safe_r_sql("SELECT q.QuSession FROM BK_Registrations br
        INNER JOIN Qualifications q ON q.QuId = br.BrEnId
        WHERE br.BrEnId = $enId AND br.BrTournament = $tourId AND br.BrValidated = 0"));
    if (!$r) return false;
    safe_w_sql("UPDATE BK_Registrations SET BrValidated = 1 WHERE BrEnId = $enId AND BrTournament = $tourId");
    bk_replan_session($tourId, intval($r->QuSession), $cfg);
    return true;
}

/** Valide toutes les inscriptions en attente + place les départs. Retourne le nombre validé. */
function bk_validate_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $sessions = array(); $n = 0;
    $rs = safe_r_sql("SELECT q.QuSession FROM BK_Registrations r
        INNER JOIN Qualifications q ON q.QuId = r.BrEnId
        WHERE r.BrTournament = $tourId AND r.BrValidated = 0");
    while ($r = safe_fetch($rs)) { $sessions[intval($r->QuSession)] = true; $n++; }
    if (!$n) return 0;
    safe_w_sql("UPDATE BK_Registrations SET BrValidated = 1 WHERE BrTournament = $tourId AND BrValidated = 0");
    foreach (array_keys($sessions) as $so) bk_replan_session($tourId, $so, $cfg);
    return $n;
}

/** Libère les cibles d'un départ (remet les archers en attente de placement). */
function bk_clear_session($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId AND e.EnTournament = $tourId
        SET q.QuTarget = 0, q.QuLetter = '', q.QuTargetNo = '', q.QuTimestamp = q.QuTimestamp
        WHERE q.QuSession = " . intval($sessionOrder));
    return intval(safe_w_affected_rows());
}

/**
 * Contrôle du règlement, départ par départ. À lancer à la clôture des
 * inscriptions (c'est le moment où le contrôle a un sens : avant, le plateau
 * bouge encore).
 *
 * Retourne un tableau par départ : violations de quota club/cible, nombre de
 * clubs présents, archers non placés.
 */
function bk_rules_check($tourId, $cfg)
{
    $tourId   = intval($tourId);
    $max      = max(1, intval($cfg->BcMaxPerClubPerTarget));
    $minClubs = max(1, intval($cfg->BcMinClubsPerSession));

    $out = array();
    foreach (bk_comp_sessions($tourId) as $s) {
        $order   = intval($s->SesOrder);
        $archers = bk_session_archers($tourId, $order);
        if (!$archers) continue;

        $parCible = array(); $clubs = array(); $nonPlaces = 0; $doublons = array();
        foreach ($archers as $a) {
            $club = (string) ($a->CoCode ?: '?');
            $clubs[$club] = true;
            if (intval($a->QuTarget) > 0) {
                $parCible[intval($a->QuTarget)][$club][] = $a;
            } else {
                $nonPlaces++;
            }
        }

        $exces = array();
        foreach ($parCible as $cible => $parClub) {
            foreach ($parClub as $club => $liste) {
                if (count($liste) > $max) {
                    $exces[] = array('cible' => $cible, 'club' => $club, 'n' => count($liste));
                }
            }
        }

        // Un même archer deux fois sur le même départ (ne devrait jamais
        // arriver via BOOKING, mais l'organisateur saisit aussi à la main).
        $vus = array();
        foreach ($archers as $a) {
            $k = bk_clean_licence($a->EnCode);
            if ($k === '') continue;
            if (isset($vus[$k])) $doublons[$k] = ($doublons[$k] ?? 1) + 1;
            $vus[$k] = true;
        }

        $out[] = array(
            'depart'     => $order,
            'nom'        => $s->SesName,
            'archers'    => count($archers),
            'clubs'      => count($clubs),
            'minClubs'   => $minClubs,
            'clubsOk'    => count($clubs) >= $minClubs,
            'max'        => $max,
            'exces'      => $exces,
            'nonPlaces'  => $nonPlaces,
            'doublons'   => $doublons,
            'ok'         => !$exces && count($clubs) >= $minClubs && $nonPlaces === 0 && !$doublons,
        );
    }
    return $out;
}

/**
 * Plan d'un départ pour affichage : cible → lettre → archer.
 * Sert à la fois à l'organisateur et, si BcShowAssignment, aux archers.
 */
function bk_session_plan($tourId, $sessionOrder)
{
    $plan = array();
    foreach (bk_session_archers($tourId, $sessionOrder) as $a) {
        if (intval($a->QuTarget) > 0) {
            $plan[intval($a->QuTarget)][strtoupper($a->QuLetter)] = $a;
        }
    }
    ksort($plan);
    foreach ($plan as &$c) ksort($c);
    return $plan;
}
