<?php
/**
 * lib/adopt.php — persistance des données d'inscription à travers un RÉIMPORT.
 *
 * Problème (Common/Fun_TourDelete.php:tour_import) : réimporter une version plus
 * récente d'une compétition déjà présente (MÊME ToCode) SUPPRIME l'ancien tournoi
 * et en crée un nouveau avec un ToId DIFFÉRENT. Toutes les tables BK_ sont liées au
 * ToId → elles deviennent orphelines, et les inscriptions en ligne (Entries)
 * disparaissent avec l'ancien tournoi.
 *
 * Solution (même principe que PRONO) : on ancre BK_Competitions sur le ToCode
 * (stable). Quand l'organisateur ouvre la nouvelle version, bk_adopt_check() détecte
 * l'orphelin (même code, ToId différent) et bk_adopt() :
 *   1. déplace toutes les tables liées au ToId de l'ancien vers le nouveau ;
 *   2. réconcilie les inscriptions avec le nouvel import :
 *      - présentes des deux côtés  → re-liées (placement/départ du NOUVEL IMPORT) ;
 *        catégorie divergente      → conflit 'category' (import gardé, orga tranche) ;
 *      - présentes seulement dans booking → RÉ-INJECTÉES dans la nouvelle version ;
 *      - présentes seulement dans l'import → capturées (visibles dans l'espace de
 *        l'archer), SANS info de paiement (BrByRole='IMPORT').
 *
 * ⚠️ Réutilise bk_register() pour la ré-injection (chemin d'écriture testé, hooks de
 * recalcul du cœur) plutôt que de recréer une Entry à la main.
 */

if (defined('BK_ADOPT_LOADED')) return;
define('BK_ADOPT_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/registration.php';   // bk_register, bk_lookup_licence, bk_comp_finished

/** ToCode (stable) d'une compétition VIVANTE. '' si le tournoi n'existe pas/plus. */
function bk_tour_code($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = " . intval($tourId)));
    return $r ? trim((string) $r->ToCode) : '';
}

/**
 * Ancien tournoi orphelin correspondant à ce code (données booking d'une version
 * précédente), ou 0. On prend la version précédente la plus récente.
 */
function bk_adopt_orphan($newId, $code)
{
    $newId = intval($newId);
    $code  = trim((string) $code);
    if ($code === '') return 0;
    $r = safe_fetch(safe_r_sql("SELECT BcTournament FROM BK_Competitions
        WHERE BcCode = " . StrSafe_DB($code) . " AND BcTournament <> $newId
        ORDER BY BcTournament DESC LIMIT 1"));
    return $r ? intval($r->BcTournament) : 0;
}

/** La compétition (nouveau ToId) porte-t-elle déjà des données booking propres ? */
function bk_has_booking_data($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT
        (SELECT COUNT(*) FROM BK_Competitions  WHERE BcTournament = $tourId)
      + (SELECT COUNT(*) FROM BK_Registrations WHERE BrTournament = $tourId)
      + (SELECT COUNT(*) FROM BK_Payments      WHERE PyTournament = $tourId) AS n"));
    return $r && intval($r->n) > 0;
}

/**
 * Déclencheur bon marché, appelé à l'ouverture d'une compétition côté organisateur.
 * Ne fait rien (un SELECT indexé) tant qu'il n'y a rien à adopter. Lance l'adoption
 * UNIQUEMENT si la nouvelle compétition n'a encore aucune donnée booille propre et
 * qu'un orphelin du même code existe. Mémorise le compte-rendu en session pour que
 * la page admin l'affiche. Retourne le compte-rendu ou null.
 */
function bk_adopt_check($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    if ($tourId <= 0) return null;

    // Déjà des données propres ? → soit déjà adopté, soit compétition non concernée.
    if (bk_has_booking_data($tourId)) return null;

    $code = bk_tour_code($tourId);
    if ($code === '') return null;
    if (!bk_adopt_orphan($tourId, $code)) return null;

    $report = bk_adopt($tourId);
    if ($report && !empty($report['ok'])) {
        $_SESSION['BK_ADOPT_REPORT'] = $report;
        bk_log('REIMPORT_ADOPT', 'tour=' . $tourId . ' from=' . $report['old']);
    }
    return $report;
}

/** Compte-rendu de la dernière adoption (affiché une fois par la page admin). */
function bk_adopt_report_pull()
{
    if (empty($_SESSION['BK_ADOPT_REPORT'])) return null;
    $r = $_SESSION['BK_ADOPT_REPORT'];
    unset($_SESSION['BK_ADOPT_REPORT']);
    return $r;
}

/** Enregistre une incohérence à trancher par l'organisateur. */
function bk_reimport_conflict($tourId, $code, $licence, $name, $kind, $enId, $booking, $import)
{
    safe_w_sql("INSERT INTO BK_ReimportConflicts SET
        RcTournament = " . intval($tourId) . ",
        RcCode = "     . StrSafe_DB((string) $code) . ",
        RcLicence = "  . StrSafe_DB((string) $licence) . ",
        RcName = "     . StrSafe_DB(mb_substr((string) $name, 0, 120)) . ",
        RcKind = "     . StrSafe_DB((string) $kind) . ",
        RcEnId = "     . intval($enId) . ",
        RcBooking = "  . StrSafe_DB(json_encode($booking, JSON_UNESCAPED_UNICODE)) . ",
        RcImport = "   . StrSafe_DB(json_encode($import, JSON_UNESCAPED_UNICODE)));
}

/** Incohérences non résolues d'une compétition (pour la page admin). */
function bk_reimport_conflicts($tourId, $onlyOpen = true)
{
    bk_schema();
    $tourId = intval($tourId);
    $w = "RcTournament = $tourId" . ($onlyOpen ? " AND RcResolved = 0" : '');
    $out = array();
    $q = safe_r_sql("SELECT * FROM BK_ReimportConflicts WHERE $w ORDER BY RcKind, RcName");
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/** Inscriptions capturées depuis l'import (saisies hors module, sans paiement). */
function bk_reimport_imported($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $out = array();
    $q = safe_r_sql("SELECT r.*, e.EnFirstName, e.EnName, e.EnDivision, e.EnClass
        FROM BK_Registrations r
        INNER JOIN Entries e ON e.EnId = r.BrEnId
        WHERE r.BrTournament = $tourId AND r.BrByRole = 'IMPORT'
        ORDER BY e.EnFirstName, e.EnName");
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/**
 * Ré-injecte une inscription booking (absente du nouvel import) dans la nouvelle
 * compétition, via le chemin d'écriture standard. Préserve l'auteur, la date et le
 * statut de validation d'origine. Retourne ['ok'=>bool, 'msg'=>...].
 */
function bk_adopt_reinject($newId, $reg)
{
    $lue = bk_lookup_licence($reg->BrLicence);
    if (!$lue) {
        return array('ok' => false, 'msg' => 'licence inconnue du fichier fédéral');
    }
    $by = array(
        'archer' => intval($reg->BrArcher),
        'role'   => (string) $reg->BrByRole,
        'who'    => (string) $reg->BrBy,
    );
    $opts = array(
        'face'   => intval($reg->BrFace),
        'letter' => (string) $reg->BrWantLetter,
        'with'   => (string) $reg->BrWantWith,
        'skip_capacity' => true,   // ré-injection d'une inscription historique : jamais refusée par l'admission
    );
    $res = bk_register($newId, $lue, $reg->BrDivision, $reg->BrClass,
        intval($reg->BrSession), (string) $reg->BrRequest, $by, $opts);
    if (empty($res['ok'])) {
        return array('ok' => false, 'msg' => $res['msg'] ?? 'échec');
    }
    $newEn = intval($res['enid']);
    // bk_register a recalculé BrValidated (mode validation) et posé BrCreated=maintenant :
    // on restaure les valeurs d'origine, puis on supprime l'ancienne ligne orpheline.
    safe_w_sql("UPDATE BK_Registrations SET
        BrValidated = " . intval($reg->BrValidated) . ",
        BrCreated = "   . StrSafe_DB((string) $reg->BrCreated) . "
        WHERE BrEnId = $newEn");
    safe_w_sql("DELETE FROM BK_Registrations WHERE BrId = " . intval($reg->BrId));
    return array('ok' => true, 'enid' => $newEn);
}

/* ------------------------------------------------------------------ */
/* Résolution des incohérences (page admin/reimport.php)               */
/* ------------------------------------------------------------------ */

/** Marque une incohérence comme résolue. */
function bk_reimport_resolve($rcId)
{
    safe_w_sql("UPDATE BK_ReimportConflicts SET RcResolved = 1 WHERE RcId = " . intval($rcId));
}

/**
 * Applique la catégorie de l'inscription booking à l'Entry de l'import (conflit
 * 'category'). On garde le PLACEMENT de l'import (QuTarget inchangé) et on ne change
 * que la catégorie — édition en place, comme PopEdit.php, suivie des hooks de recalcul.
 */
function bk_reimport_apply_booking($tourId, $rc)
{
    $tourId = intval($tourId);
    $enId   = intval($rc->RcEnId);
    $book   = json_decode((string) $rc->RcBooking, true);
    if (!$enId || !is_array($book) || empty($book['division']) || empty($book['class'])) {
        return array('ok' => false, 'msg' => 'données incomplètes');
    }
    $division = (string) $book['division'];
    $class    = (string) $book['class'];

    return bk_with_tournament($tourId, function () use ($tourId, $enId, $division, $class) {
        $now = date('Y-m-d H:i:s');

        $r = safe_fetch(safe_r_sql("SELECT (DivAthlete AND ClAthlete) AS Athlete
            FROM Divisions INNER JOIN Classes ON DivTournament = ClTournament
            WHERE DivTournament = $tourId
              AND DivId = " . StrSafe_DB($division) . "
              AND ClId  = " . StrSafe_DB($class)));
        if (!$r || !$r->Athlete) return array('ok' => false, 'msg' => 'catégorie invalide pour cette compétition');

        $face = 0;
        $all = getTargets(true);
        if (!empty($all[$division][$class])) {
            $ids  = array_map('intval', array_keys($all[$division][$class]));
            $face = $ids[0];
        }

        safe_w_sql("UPDATE Entries SET
            EnDivision = " . StrSafe_DB($division) . ",
            EnClass = "    . StrSafe_DB($class) . ",
            EnAgeClass = " . StrSafe_DB($class) . ",
            EnAthlete = 1,
            EnTargetFace = $face,
            EnMainInfoUpdate = '$now', EnTimestamp = '$now'
            WHERE EnId = $enId");

        $p = Params4Recalc($enId);
        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
            if ($t) for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            MakeIndAbs();
        }
        checkAgainstLUE($enId);

        safe_w_sql("UPDATE BK_Registrations SET
            BrDivision = " . StrSafe_DB($division) . ",
            BrClass = "    . StrSafe_DB($class) . ",
            BrFace = $face WHERE BrEnId = $enId");

        return array('ok' => true);
    });
}

/** Ligne orpheline booking encore non ré-injectée (Entry disparue) pour une licence. */
function bk_reimport_orphan_row($tourId, $licence)
{
    $tourId = intval($tourId);
    return safe_fetch(safe_r_sql("SELECT r.* FROM BK_Registrations r
        LEFT JOIN Entries e ON e.EnId = r.BrEnId
        WHERE r.BrTournament = $tourId
          AND r.BrLicence = " . StrSafe_DB((string) $licence) . "
          AND r.BrByRole <> 'IMPORT'
          AND e.EnId IS NULL
        ORDER BY r.BrId LIMIT 1")) ?: null;
}

/** Nouvelle tentative de ré-injection d'un conflit 'reinject'. */
function bk_reimport_retry($tourId, $rc)
{
    $reg = bk_reimport_orphan_row($tourId, $rc->RcLicence);
    if (!$reg) return array('ok' => false, 'msg' => 'trace introuvable (déjà traitée ?)');
    return bk_adopt_reinject($tourId, $reg);
}

/** Abandon d'une ré-injection : supprime la trace orpheline. */
function bk_reimport_drop($tourId, $rc)
{
    $reg = bk_reimport_orphan_row($tourId, $rc->RcLicence);
    if ($reg) safe_w_sql("DELETE FROM BK_Registrations WHERE BrId = " . intval($reg->BrId));
    return array('ok' => true);
}

/**
 * RETIRE une inscription de la compétition (Entry supprimée du cœur ianseo + ligne
 * BK_Registrations). Action ADMINISTRATEUR (pas d'auth archer) : réservée à la
 * réconciliation de réimport. Suit exactement bk_unregister (deleteArcher + promotion
 * de l'épreuve + hooks de recalcul), pour ne pas laisser classements/équipes obsolètes.
 */
function bk_reimport_remove_entry($tourId, $enId)
{
    $tourId = intval($tourId);
    $enId   = intval($enId);
    if (!$enId) return array('ok' => false, 'msg' => 'inscription manquante');

    return bk_with_tournament($tourId, function () use ($tourId, $enId) {
        if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return array('ok' => false, 'msg' => 'compétition verrouillée');

        $old = safe_fetch(safe_r_sql("SELECT EnCode, EnDivision, EnIndClEvent
            FROM Entries WHERE EnId = $enId AND EnTournament = $tourId"));
        if (!$old) { safe_w_sql("DELETE FROM BK_Registrations WHERE BrEnId = $enId"); return array('ok' => true); }

        $p = Params4Recalc($enId);
        deleteArcher($enId);

        // Promotion de l'épreuve si on retire la porteuse alors qu'un tir de la même
        // arme subsiste (sinon l'archer disparaîtrait du classement tout en restant inscrit).
        if (intval($old->EnIndClEvent) === 1) {
            $n = safe_fetch(safe_r_sql("SELECT e.EnId FROM Entries e
                INNER JOIN Qualifications q ON q.QuId = e.EnId
                WHERE e.EnTournament = $tourId
                  AND e.EnCode = " . StrSafe_DB($old->EnCode) . "
                  AND e.EnDivision = " . StrSafe_DB($old->EnDivision) . "
                  AND e.EnAthlete = 1
                ORDER BY q.QuSession, e.EnId LIMIT 1"));
            if ($n) safe_w_sql("UPDATE Entries SET EnIndClEvent = 1, EnTeamClEvent = 1,
                EnIndFEvent = 1, EnTeamFEvent = 1, EnTeamMixEvent = 1,
                EnTimestamp = '" . date('Y-m-d H:i:s') . "' WHERE EnId = " . intval($n->EnId));
        }

        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
            if ($t) for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            MakeIndAbs();
        }

        safe_w_sql("DELETE FROM BK_Registrations WHERE BrEnId = $enId");
        return array('ok' => true);
    });
}

/**
 * Applique une décision à un conflit selon le CÔTÉ retenu ('import' | 'booking').
 * Sémantique par type :
 *   category    import → garder la catégorie de l'import ; booking → appliquer celle du licencié.
 *   onlybooking import → RETIRER l'inscription (l'import ne la contient pas) ;
 *                booking → la garder (déjà ré-injectée).
 *   onlyimport  import → garder le participant (déjà visible) ;
 *                booking → le RETIRER de la compétition (Entry supprimée).
 *   reinject    import → abandonner la trace ; booking → réessayer la ré-injection.
 * Retourne ['ok'=>bool, 'msg'=>...]. Marque le conflit résolu si succès.
 */
function bk_reimport_apply($tourId, $rc, $side)
{
    $side = ($side === 'booking') ? 'booking' : 'import';
    $kind = (string) $rc->RcKind;
    $r = array('ok' => true);

    if ($kind === 'category') {
        if ($side === 'booking') $r = bk_reimport_apply_booking($tourId, $rc);
        // import → rien à faire (l'import est déjà en place).
    } elseif ($kind === 'onlybooking') {
        if ($side === 'import') $r = bk_reimport_remove_entry($tourId, intval($rc->RcEnId));
        // booking → garder (déjà ré-injectée).
    } elseif ($kind === 'onlyimport') {
        if ($side === 'booking') $r = bk_reimport_remove_entry($tourId, intval($rc->RcEnId));
        // import → garder (déjà capturée).
    } elseif ($kind === 'reinject') {
        if ($side === 'booking') { $r = bk_reimport_retry($tourId, $rc); }
        else { $r = bk_reimport_drop($tourId, $rc); }
    }

    if (!empty($r['ok'])) bk_reimport_resolve(intval($rc->RcId));
    return $r;
}

/**
 * Bouton global : tranche TOUS les conflits non résolus du même côté. Retourne un
 * compte-rendu (traités, retirés, échecs). Peut être long si beaucoup de suppressions.
 */
function bk_reimport_bulk($tourId, $side)
{
    $out = array('done' => 0, 'removed' => 0, 'fail' => 0);
    foreach (bk_reimport_conflicts($tourId) as $rc) {
        $before = $rc->RcKind;
        $r = bk_reimport_apply($tourId, $rc, $side);
        if (!empty($r['ok'])) {
            $out['done']++;
            if (($side === 'import' && $before === 'onlybooking') ||
                ($side === 'booking' && $before === 'onlyimport')) $out['removed']++;
        } else {
            $out['fail']++;
        }
    }
    return $out;
}

/**
 * Adopte les données booking d'une version précédente vers la nouvelle compétition.
 * À n'appeler que via bk_adopt_check() (qui garantit les préconditions). Retourne un
 * compte-rendu.
 */
function bk_adopt($newId)
{
    bk_schema();
    $newId = intval($newId);
    $code  = bk_tour_code($newId);
    if ($code === '') return array('ok' => false, 'reason' => 'no_code');

    $old = bk_adopt_orphan($newId, $code);
    if (!$old) return array('ok' => false, 'reason' => 'no_orphan');

    // Garde : ne jamais écraser des données booking déjà présentes sur la nouvelle
    // compétition (cas ambigu : l'orga aurait reconfiguré booking sur le nouvel import).
    if (bk_has_booking_data($newId)) {
        return array('ok' => false, 'reason' => 'target_has_data', 'old' => $old, 'new' => $newId);
    }

    $rep = array('ok' => true, 'old' => $old, 'new' => $newId, 'code' => $code,
        'relinked' => 0, 'reinjected' => 0, 'reinject_fail' => 0, 'imported' => 0,
        'category' => 0, 'payments' => 0);

    // Combien de paiements déplacés (pour le compte-rendu).
    $p = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BK_Payments WHERE PyTournament = $old"));
    $rep['payments'] = $p ? intval($p->n) : 0;

    // ---- Phase A : déplacer les tables liées au ToId (ancien → nouveau) ----
    safe_w_sql("START TRANSACTION");
    safe_w_sql("UPDATE BK_Competitions   SET BcTournament = $newId, BcCode = " . StrSafe_DB($code) . " WHERE BcTournament = $old");
    safe_w_sql("UPDATE BK_TargetCaps     SET BtTournament = $newId WHERE BtTournament = $old");
    safe_w_sql("UPDATE BK_ShopItems      SET SiTournament = $newId WHERE SiTournament = $old");
    safe_w_sql("UPDATE BK_ShopOrders     SET SoTournament = $newId WHERE SoTournament = $old");
    safe_w_sql("UPDATE BK_Payments       SET PyTournament = $newId WHERE PyTournament = $old");
    safe_w_sql("UPDATE BK_Registrations  SET BrTournament = $newId WHERE BrTournament = $old");
    safe_w_sql("COMMIT");

    // ---- Phase B : réconcilier les inscriptions avec le nouvel import ----

    // Inscriptions booking (désormais sur le nouveau ToId, mais BrEnId pointe encore
    // sur les Entries supprimées). Capturées en tableau : la boucle modifie la table.
    $regs = array();
    $q = safe_r_sql("SELECT * FROM BK_Registrations WHERE BrTournament = $newId ORDER BY BrId");
    while ($r = safe_fetch($q)) $regs[] = $r;

    // Entries du nouvel import, indexées par licence (chacune « à réclamer »).
    $importByLic = array();
    $q = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnDivision, e.EnClass, e.EnTargetFace,
                e.EnFirstName, e.EnName, q.QuSession, q.QuTarget
        FROM Entries e LEFT JOIN Qualifications q ON q.QuId = e.EnId
        WHERE e.EnTournament = $newId");
    while ($e = safe_fetch($q)) {
        $lic = trim((string) $e->EnCode);
        $importByLic[$lic][] = array('e' => $e, 'claimed' => false);
    }

    // Appariement, une inscription booking à la fois. On indexe directement dans
    // $importByLic (pas de référence : `=& $importByLic[$lic]` auto-créerait une
    // entrée nulle pour une licence absente, parcourue ensuite comme un nouvel inscrit).
    foreach ($regs as $reg) {
        $lic = trim((string) $reg->BrLicence);
        $pickIdx = -1;

        if (!empty($importByLic[$lic])) {
            // Priorité : même départ ET même arme ; puis même départ ; puis même arme ;
            // puis n'importe quelle Entry non réclamée de cette licence.
            $prefs = array(
                function ($e) use ($reg) { return intval($e->QuSession) === intval($reg->BrSession) && (string) $e->EnDivision === (string) $reg->BrDivision; },
                function ($e) use ($reg) { return intval($e->QuSession) === intval($reg->BrSession); },
                function ($e) use ($reg) { return (string) $e->EnDivision === (string) $reg->BrDivision; },
                function ($e) { return true; },
            );
            foreach ($prefs as $ok) {
                foreach ($importByLic[$lic] as $i => $cand) {
                    if ($cand['claimed']) continue;
                    if ($ok($cand['e'])) { $pickIdx = $i; break; }
                }
                if ($pickIdx >= 0) break;
            }
        }

        if ($pickIdx >= 0) {
            $e = $importByLic[$lic][$pickIdx]['e'];
            $importByLic[$lic][$pickIdx]['claimed'] = true;
            $enId = intval($e->EnId);

            // Placement / départ : autorité au NOUVEL IMPORT (on synchronise l'instantané).
            $catDiff = ((string) $e->EnDivision !== (string) $reg->BrDivision)
                    || ((string) $e->EnClass    !== (string) $reg->BrClass);
            safe_w_sql("UPDATE BK_Registrations SET
                BrEnId = $enId,
                BrDivision = " . StrSafe_DB((string) $e->EnDivision) . ",
                BrClass = "    . StrSafe_DB((string) $e->EnClass) . ",
                BrSession = "  . intval($e->QuSession) . ",
                BrFace = "     . intval($e->EnTargetFace) . "
                WHERE BrId = " . intval($reg->BrId));
            $rep['relinked']++;

            if ($catDiff) {
                // Même personne + même départ, catégorie différente : on garde l'import,
                // l'organisateur confirmera sur la page dédiée.
                $rep['category']++;
                bk_reimport_conflict($newId, $code, $lic,
                    trim($e->EnFirstName . ' ' . $e->EnName), 'category', $enId,
                    array('division' => $reg->BrDivision, 'class' => $reg->BrClass),
                    array('division' => $e->EnDivision, 'class' => $e->EnClass));
            }
        } else {
            // Absente de l'import → ré-injecter dans la nouvelle version (défaut sûr :
            // ne jamais perdre une inscription en ligne). Enregistrée comme conflit
            // 'onlybooking' pour que l'orga puisse la GARDER (défaut) ou la RETIRER
            // (l'import ne la contient pas).
            $res = bk_adopt_reinject($newId, $reg);
            if (!empty($res['ok'])) {
                $rep['reinjected']++;
                $nm = '';
                $ne = safe_fetch(safe_r_sql("SELECT EnFirstName, EnName FROM Entries WHERE EnId = " . intval($res['enid'])));
                if ($ne) $nm = trim($ne->EnFirstName . ' ' . $ne->EnName);
                bk_reimport_conflict($newId, $code, $lic, $nm, 'onlybooking', intval($res['enid']),
                    array('division' => $reg->BrDivision, 'class' => $reg->BrClass, 'session' => $reg->BrSession),
                    null);
            } else {
                $rep['reinject_fail']++;
                bk_reimport_conflict($newId, $code, $lic,
                    '', 'reinject', 0,
                    array('division' => $reg->BrDivision, 'class' => $reg->BrClass,
                          'session' => $reg->BrSession, 'msg' => $res['msg'] ?? ''),
                    null);
                // On conserve l'ancienne ligne orpheline pour trace/traitement manuel.
            }
        }
    }

    // Participants présents SEULEMENT dans l'import (saisis hors module) → capturés
    // dans l'espace de l'archer (BrByRole='IMPORT'), sans info de paiement (défaut sûr :
    // on ne supprime jamais un participant sans décision). Enregistrés comme conflit
    // 'onlyimport' pour que l'orga puisse les GARDER (défaut) ou les RETIRER de la
    // compétition (choix « côté booking »).
    $now = date('Y-m-d H:i:s');
    foreach ($importByLic as $lic => $cands) {
        foreach ($cands as $cand) {
            if ($cand['claimed']) continue;
            $e = $cand['e'];
            safe_w_sql("INSERT INTO BK_Registrations SET
                BrEnId = "     . intval($e->EnId) . ",
                BrTournament = $newId,
                BrArcher = 0,
                BrLicence = "  . StrSafe_DB((string) $e->EnCode) . ",
                BrByRole = 'IMPORT',
                BrBy = 'import',
                BrRequest = NULL,
                BrWantLetter = '', BrWantWith = '',
                BrValidated = 1,
                BrDivision = " . StrSafe_DB((string) $e->EnDivision) . ",
                BrClass = "    . StrSafe_DB((string) $e->EnClass) . ",
                BrSession = "  . intval($e->QuSession) . ",
                BrFace = "     . intval($e->EnTargetFace) . ",
                BrCreated = "  . StrSafe_DB($now));
            $rep['imported']++;
            bk_reimport_conflict($newId, $code, (string) $e->EnCode,
                trim($e->EnFirstName . ' ' . $e->EnName), 'onlyimport', intval($e->EnId),
                null,
                array('division' => $e->EnDivision, 'class' => $e->EnClass, 'session' => $e->QuSession));
        }
    }

    return $rep;
}
