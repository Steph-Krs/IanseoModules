<?php
/**
 * lib/registration.php — création et suppression d'une inscription.
 *
 * Écrit dans les tables du CŒUR ianseo (Entries, Qualifications, Countries) en
 * suivant exactement le chemin de Partecipants/PopEdit.php, hooks de recalcul
 * compris. Toute divergence laisserait classements et équipes obsolètes.
 *
 * Une inscription BOOKING = une ligne Entries + une ligne Qualifications (1:1
 * par QuId=EnId, comme le cœur) + une ligne BK_Registrations qui trace l'auteur
 * (Entries n'a aucune notion d'auteur d'inscription).
 */

if (defined('BK_REG_LOADED')) return;
define('BK_REG_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/archer.php';   // bk_lookup_licence, bk_clean_licence

// Fonctions du cœur ianseo utilisées pour écrire une inscription et déclencher
// les recalculs. Chargées par chemin relatif : config.php ajoute htdocs à
// l'include_path, ces fichiers s'incluent donc les uns les autres normalement.
require_once('Common/Fun_FormatText.inc.php');              // AdjustCaseTitle
require_once('Common/Fun_Various.inc.php');                 // checkAgainstLUE
require_once('Partecipants/Fun_Targets.php');               // getTargets
require_once('Partecipants/Fun_Partecipants.local.inc.php');// Params4Recalc, RecalculateShootoffAndTeams
require_once('Qualification/Fun_Qualification.local.inc.php');// CalcQualRank, MakeIndAbs

/**
 * Exécute $fn avec la session de compétition de ianseo positionnée sur $tourId,
 * puis REMET la session dans son état d'origine.
 *
 * Indispensable : les fonctions de recalcul du cœur (RecalculateShootoffAndTeams,
 * CalcQualRank, MakeIndAbs…) lisent $_SESSION['TourId'] et compagnie. Or l'espace
 * licencié n'a aucune compétition ouverte — et le navigateur peut par ailleurs
 * porter la session d'un organisateur sur une AUTRE compétition.
 *
 * ⚠️ CreateTourSession() VIDE $_SESSION (Globals.inc.php) : elle effacerait le
 * jeton de session de l'archer. On sauvegarde donc la session ENTIÈRE et on la
 * restaure telle quelle — y compris via register_shutdown_function, pour qu'une
 * erreur fatale ne laisse jamais l'archer déconnecté ni un organisateur avec la
 * mauvaise compétition ouverte.
 */
function bk_with_tournament($tourId, $fn)
{
    $saved = $_SESSION;
    $done  = false;

    register_shutdown_function(function () use ($saved, &$done) {
        if (!$done) $_SESSION = $saved;   // filet en cas d'erreur fatale
    });

    try {
        CreateTourSession(intval($tourId));
        return $fn();
    } finally {
        $_SESSION = $saved;
        $done = true;
    }
}

/* ------------------------------------------------------------------ */
/* Catégories proposées                                                */
/* ------------------------------------------------------------------ */

/** Armes (divisions) ouvertes aux athlètes sur cette compétition. */
function bk_reg_divisions($tourId)
{
    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions
        WHERE DivTournament = " . intval($tourId) . " AND DivAthlete = 1
        ORDER BY DivViewOrder, DivId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->DivId] = $r->DivDescription;
    return $out;
}

/**
 * Catégories (classes) ouvertes à cet archer pour cette arme.
 *
 * Règle du cœur ianseo (Partecipants/Get-Classes.php, Participants/lib.php) :
 * l'âge est l'ANNÉE de fin de compétition moins l'ANNÉE de naissance — jamais
 * un âge révolu à la date du jour.
 */
function bk_reg_classes($tourId, $dob, $sex, $division)
{
    $tourId = intval($tourId);
    $sex    = intval($sex) ? 1 : 0;

    $rs = safe_r_sql("SELECT ClId, ClDescription, ClAgeFrom, ClAgeTo
        FROM Classes
        INNER JOIN Tournament ON ToId = ClTournament
        WHERE ClTournament = $tourId
          AND ClAthlete = 1
          AND ClSex IN (-1, $sex)
          AND (YEAR(ToWhenTo) - YEAR(" . StrSafe_DB($dob) . ")) BETWEEN ClAgeFrom AND ClAgeTo
          AND (ClDivisionsAllowed = '' OR FIND_IN_SET(" . StrSafe_DB($division) . ", ClDivisionsAllowed))
        ORDER BY (ClAgeTo - ClAgeFrom) ASC, ClId");   // la plus spécifique d'abord
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->ClId] = $r->ClDescription;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Inscription de groupe (un licencié inscrit un camarade de son club) */
/* ------------------------------------------------------------------ */

/**
 * Le licencié est-il MAJEUR ? (18 ans révolus à la date du jour.)
 *
 * Seul un majeur peut inscrire d'autres licenciés. Calcul côté SQL (CURDATE()) :
 * ianseo change le time_zone MySQL par compétition, on ne compare jamais à
 * l'horloge PHP. $dob au format AAAA-MM-JJ (LueCtrlCode).
 */
function bk_is_major($dob)
{
    $dob = trim((string) $dob);
    if ($dob === '' || $dob === '0000-00-00') return false;
    $r = safe_fetch(safe_r_sql("SELECT (" . StrSafe_DB($dob)
        . " <= DATE_SUB(CURDATE(), INTERVAL 18 YEAR)) AS major"));
    return $r ? (bool) intval($r->major) : false;
}

/**
 * Résout un camarade de club à partir d'un numéro de licence saisi, borné au
 * club de l'archer connecté. Retourne la fiche fédérale (LookUpEntries) ou null.
 *
 * null si : licence vide, licence inconnue du fichier fédéral, OU archer d'un
 * autre club — dans tous ces cas « rien ne doit se passer » (exigence métier :
 * on n'inscrit jamais un tiers hors de son propre club).
 */
function bk_lookup_clubmate($licence, $selfClubCode)
{
    $selfClubCode = strtoupper(trim((string) $selfClubCode));
    if ($selfClubCode === '') return null;

    $lue = bk_lookup_licence($licence);
    if (!$lue) return null;
    if (strtoupper(trim((string) $lue->LueCountry)) !== $selfClubCode) return null;
    return $lue;
}

/**
 * Camarades DISTINCTS déjà inscrits par cet archer, restreints à ceux qui sont
 * ENCORE dans son club aujourd'hui. Sert la liste déroulante d'inscription de
 * groupe (raccourci vers un licencié déjà inscrit). Le contrôle de club est
 * REJOUÉ ici (`bk_lookup_clubmate`) : un archer qui a changé de club en cours de
 * saison ne doit plus pouvoir être inscrit. Retourne [licence => "Nom Prénom"].
 */
function bk_authored_clubmates($archerId, $selfLicence, $selfClubCode)
{
    $archerId = intval($archerId);
    if ($archerId <= 0 || trim((string) $selfClubCode) === '') return array();

    $selfLic = bk_clean_licence($selfLicence);
    $rs = safe_r_sql("SELECT DISTINCT BrLicence FROM BK_Registrations
        WHERE BrArcher = $archerId AND BrLicence <> " . StrSafe_DB($selfLic));
    $out = array();
    while ($r = safe_fetch($rs)) {
        $mate = bk_lookup_clubmate($r->BrLicence, $selfClubCode);   // re-vérifie le club courant
        if ($mate) $out[$mate->LueCode] = trim($mate->LueFamilyName . ' ' . $mate->LueName);
    }
    asort($out);
    return $out;
}

/**
 * Inscriptions qu'un archer a créées POUR d'AUTRES licenciés (inscription de
 * groupe) — pour qu'il puisse les suivre et les annuler depuis « Mes
 * inscriptions ». Exclut ses propres inscriptions (BrLicence = sa licence).
 */
function bk_authored_registrations($archerId, $selfLicence)
{
    bk_schema();
    $archerId = intval($archerId);
    if ($archerId <= 0) return array();

    $rs = safe_r_sql("SELECT r.BrId, r.BrEnId, r.BrTournament, r.BrLicence, r.BrCreated, r.BrValidated,
                e.EnFirstName, e.EnName, e.EnCode, e.EnDivision, e.EnClass,
                q.QuSession, q.QuTarget, q.QuLetter,
                d.DivDescription, c.ClDescription,
                t.ToName, t.ToWhere, t.ToVenue, t.ToWhenFrom, t.ToWhenTo,
                t.ToType, t.ToTypeName, t.ToTypeSubRule,
                o.BcShowAssignment, o.BcAllowScoresheet, " . bk_comp_calc_sql('o') . "
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        INNER JOIN Tournament t     ON t.ToId = r.BrTournament
        LEFT  JOIN Divisions d      ON d.DivTournament = t.ToId AND d.DivId = e.EnDivision
        LEFT  JOIN Classes c        ON c.ClTournament  = t.ToId AND c.ClId  = e.EnClass
        LEFT  JOIN BK_Competitions o ON o.BcTournament = t.ToId
        WHERE r.BrArcher = $archerId
          AND r.BrLicence <> " . StrSafe_DB(bk_clean_licence($selfLicence)) . "
        ORDER BY t.ToWhenFrom DESC, e.EnFirstName, e.EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Contrôles                                                           */
/* ------------------------------------------------------------------ */

/** Inscriptions existantes de cette licence sur cette compétition. */
function bk_reg_existing($tourId, $licence)
{
    $rs = safe_r_sql("SELECT e.EnId, e.EnDivision, e.EnClass, q.QuSession
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        WHERE e.EnTournament = " . intval($tourId) . "
          AND e.EnCode = " . StrSafe_DB($licence) . "
          AND e.EnAthlete = 1");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Places restantes sur un départ (0 si le départ n'existe pas). */
function bk_reg_session_left($tourId, $order)
{
    foreach (bk_comp_sessions($tourId) as $s) {
        if (intval($s->SesOrder) === intval($order)) {
            return max(0, intval($s->Places) - intval($s->Pris));
        }
    }
    return -1;   // départ inconnu
}

/**
 * Toutes les règles à passer avant d'inscrire. Retourne '' si tout va bien,
 * sinon le motif du refus (message affichable).
 *
 * Revérifié côté serveur au moment de l'écriture : le calendrier informe, il
 * n'autorise pas.
 */
function bk_reg_blocked($tourId, $cfg, $licence, $clubCode, $division, $class, $sessionOrder, $lue = null)
{
    if (empty($cfg->BcIsOpen)) return "Les inscriptions ne sont pas ouvertes pour cette compétition.";

    // Une compétition terminée n'est plus inscriptible, même si la fenêtre
    // d'inscription a été laissée ouverte au-delà de sa date.
    if (bk_comp_finished($tourId)) return "Cette compétition est terminée : les inscriptions ne sont plus possibles.";

    // Licence « sans pratique » (LueStatus = 9, ex. dirigeant/trésorier) : ne peut PAS
    // s'inscrire à une compétition (mais peut en inscrire d'autres — c'est l'AUTEUR, pas
    // le SUJET, qui inscrit ; ici $lue = le sujet). Vaut pour l'auto-inscription comme
    // pour une inscription de camarade dont le sujet serait sans pratique.
    if ($lue && intval($lue->LueStatus) === 9) {
        return "Cette licence est « sans pratique » : elle ne permet pas de s'inscrire à une compétition.";
    }

    $geo = bk_comp_archer_blocked($cfg, $clubCode);
    if ($geo !== '') return $geo;

    if (!array_key_exists($division, bk_reg_divisions($tourId))) {
        return "Cette arme n'est pas proposée sur cette compétition.";
    }

    // La catégorie doit être l'une de celles que l'âge et le sexe autorisent —
    // vérifié ICI et pas seulement à l'affichage du formulaire : un POST forgé
    // ne doit pas pouvoir inscrire un adulte en catégorie jeune.
    if ($lue) {
        $classes = bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $division);
        if (!array_key_exists($class, $classes)) {
            return "Cette catégorie ne correspond pas à votre âge pour cette arme.";
        }
    }

    $left = bk_reg_session_left($tourId, $sessionOrder);
    if ($left < 0)  return "Ce départ n'existe pas sur cette compétition.";
    if ($left === 0) return "Ce départ est complet.";

    // Règlement : pas deux tirs pour un même archer sur un même départ.
    foreach (bk_reg_existing($tourId, $licence) as $e) {
        if (intval($e->QuSession) === intval($sessionOrder)) {
            return "Vous êtes déjà inscrit sur ce départ.";
        }
    }

    // La compétition peut être verrouillée par l'organisateur.
    if (bk_with_tournament($tourId, function () { return IsBlocked(BIT_BLOCK_PARTICIPANT); })) {
        return "Les inscriptions de cette compétition sont verrouillées.";
    }

    return '';
}

/* ------------------------------------------------------------------ */
/* Écriture                                                            */
/* ------------------------------------------------------------------ */

/** Résout (ou crée) le club dans Countries — repris de PopEdit.php. */
function bk_reg_club_id($tourId, $code, $name)
{
    $tourId = intval($tourId);
    $code = mb_convert_case(trim((string) $code), MB_CASE_UPPER, 'UTF-8');
    if ($code === '') return 0;

    $rs = safe_r_sql("SELECT CoId FROM Countries
        WHERE CoCode = " . StrSafe_DB($code) . " AND CoTournament = $tourId");
    if ($r = safe_fetch($rs)) {
        $coId = intval($r->CoId);
    } else {
        safe_w_sql("INSERT INTO Countries (CoTournament, CoCode) VALUES ($tourId, " . StrSafe_DB($code) . ")");
        $coId = intval(safe_w_last_id());
    }
    if (trim((string) $name) !== '') {
        safe_w_sql("UPDATE Countries SET CoName = " . StrSafe_DB(AdjustCaseTitle($name))
            . " WHERE CoId = $coId AND CoTournament = $tourId");
    }
    return $coId;
}

/**
 * Inscrit un archer. Retourne ['ok'=>true,'enid'=>N] ou ['ok'=>false,'msg'=>…].
 *
 * $lue     : fiche LookUpEntries (identité fédérale)
 * $by      : ['role'=>'SELF'|'MANAGER', 'who'=>identifiant, 'archer'=>BaId]
 */
function bk_register($tourId, $lue, $division, $class, $sessionOrder, $request, $by, $opts = array())
{
    $tourId = intval($tourId);

    // Garde ultime : toutes les voies d'inscription passent ici. Jamais d'écriture
    // sur une compétition terminée, quelles que soient les manipulations en amont.
    if (bk_comp_finished($tourId)) {
        return array('ok' => false, 'msg' => "Cette compétition est terminée : les inscriptions ne sont plus possibles.");
    }

    // Garde ultime « sans pratique » (LueStatus = 9) : le sujet ne peut jamais être inscrit
    // à une compétition, quelle que soit la voie (auto-inscription ou inscription de groupe).
    if (intval($lue->LueStatus ?? 0) === 9) {
        return array('ok' => false, 'msg' => "Cette licence est « sans pratique » : elle ne permet pas de s'inscrire à une compétition.");
    }

    return bk_with_tournament($tourId, function () use ($tourId, $lue, $division, $class, $sessionOrder, $request, $by, $opts) {

        $now  = date('Y-m-d H:i:s');
        $coId = bk_reg_club_id($tourId, $lue->LueCountry, $lue->LueCoDescr);

        // EnAthlete : dérivé de la division et de la classe, comme PopEdit.php.
        $rs = safe_r_sql("SELECT (DivAthlete AND ClAthlete) AS Athlete
            FROM Divisions INNER JOIN Classes ON DivTournament = ClTournament
            WHERE DivTournament = $tourId
              AND DivId = " . StrSafe_DB($division) . "
              AND ClId  = " . StrSafe_DB($class));
        $r = safe_fetch($rs);
        $athlete = ($r && $r->Athlete) ? 1 : 0;
        if (!$athlete) return array('ok' => false, 'msg' => "Catégorie invalide pour cette compétition.");

        // Blason : celui choisi par l'archer s'il figure parmi ceux que la
        // configuration autorise pour sa catégorie, sinon le premier proposé
        // (getTargets trie du plus spécifique au plus générique).
        $face = 0;
        $all = getTargets(true);
        if (!empty($all[$division][$class])) {
            $ids  = array_map('intval', array_keys($all[$division][$class]));
            $want = intval($opts['face'] ?? 0);
            $face = ($want && in_array($want, $ids, true)) ? $want : $ids[0];
        }

        // Contrôle d'admission (cohabitation des blasons) : le plan du terrain définit
        // ce qui est possible techniquement. Si plus aucune cible de ce départ ne peut
        // recevoir ce profil (arme + catégorie + blason), l'inscription est REFUSÉE —
        // sinon l'archer resterait non plaçable. bk_profile_remaining vit dans
        // targets.php (chargé dès qu'il y a placement) ; en son absence, on ne bloque pas.
        if (empty($opts['skip_capacity']) && function_exists('bk_profile_remaining')) {
            $rem = bk_profile_remaining($tourId, $sessionOrder, $division, $class, $face);
            if ($rem !== null && $rem < 1) {
                return array('ok' => false, 'msg' => "Ce départ est complet pour votre catégorie et votre blason : aucune cible ne peut plus vous recevoir. Choisissez un autre départ.");
            }
        }

        // Participation aux épreuves : seule la PREMIÈRE inscription d'un archer
        // pour une arme donnée y compte. Un second départ avec la même arme est
        // un tir supplémentaire, hors épreuve — sinon l'archer figurerait deux
        // fois au classement. Une AUTRE arme ouvre en revanche sa propre épreuve.
        $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Entries
            WHERE EnTournament = $tourId
              AND EnCode = " . StrSafe_DB($lue->LueCode) . "
              AND EnDivision = " . StrSafe_DB($division) . "
              AND EnAthlete = 1
              AND EnIndClEvent = 1");
        $r = safe_fetch($rs);
        $ev = ($r && intval($r->n) > 0) ? 0 : 1;

        $sql = "EnTournament = $tourId,
            EnDivision = "   . StrSafe_DB($division) . ",
            EnClass = "      . StrSafe_DB($class) . ",
            EnAthlete = $athlete,
            EnSubClass = " . StrSafe_DB($lue->LueSubClass ?: '') . ",
            EnAgeClass = " . StrSafe_DB($class) . ",
            EnCountry = $coId,
            EnDob = "     . StrSafe_DB($lue->LueCtrlCode ?: '0000-00-00') . ",
            EnCode = "    . StrSafe_DB($lue->LueCode) . ",
            EnName = "      . StrSafe_DB(AdjustCaseTitle($lue->LueName)) . ",
            EnFirstName = " . StrSafe_DB(AdjustCaseTitle($lue->LueFamilyName)) . ",
            EnSex = " . (intval($lue->LueSex) ? 1 : 0) . ",
            EnTargetFace = $face,
            EnStatus = 1,
            EnIndClEvent = $ev, EnTeamClEvent = $ev,
            EnIndFEvent = $ev, EnTeamFEvent = $ev, EnTeamMixEvent = $ev";

        safe_w_sql("INSERT INTO Entries SET EnTimestamp = '$now', EnMainInfoUpdate = '$now', $sql");
        $enId = intval(safe_w_last_id());
        if (!$enId) return array('ok' => false, 'msg' => "L'inscription n'a pas pu être enregistrée.");

        // EnIocCode vide = hérite du ToIocCode de la compétition (convention du
        // cœur : LueIocCode = IF(EnIocCode!='', EnIocCode, ToIocCode)). On ne le
        // renseigne que si l'archer relève d'une autre fédération.
        $rs = safe_r_sql("SELECT ToIocCode FROM Tournament WHERE ToId = $tourId");
        $to = safe_fetch($rs);
        if ($to && $lue->LueIocCode && $lue->LueIocCode !== $to->ToIocCode) {
            safe_w_sql("UPDATE Entries SET EnIocCode = " . StrSafe_DB($lue->LueIocCode)
                . ", EnTimestamp = EnTimestamp WHERE EnId = $enId");
        }

        // Ligne Qualifications appariée (1:1, QuId = EnId), puis le départ.
        safe_w_sql("INSERT INTO Qualifications (QuId, QuSession) VALUES ($enId, 0)");
        safe_w_sql("UPDATE Qualifications SET QuSession = " . intval($sessionOrder)
            . ", QuTarget = 0, QuLetter = '', QuTimestamp = QuTimestamp WHERE QuId = $enId");

        // Hooks de recalcul du cœur — sans eux, classements et équipes restent
        // obsolètes (voir PopEdit.php).
        $p = Params4Recalc($enId);
        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId");
            if ($t = safe_fetch($rs)) {
                for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            }
            MakeIndAbs();
        }
        checkAgainstLUE($enId);

        // Souhaits : n'honorer que ceux que l'organisateur propose. Revérifié
        // ici (côté écriture) : un POST forgé ne doit pas activer un souhait
        // désactivé, quel que soit l'appelant (archer ou gestionnaire de club).
        $wLetter = strtoupper(substr(trim((string) ($opts['letter'] ?? '')), 0, 2));
        $wWith   = bk_clean_licence($opts['with'] ?? '');
        $wReq    = substr(trim((string) $request), 0, 2000);
        if (function_exists('bk_comp_config')) {
            $wc = bk_comp_config($tourId);
            if (empty($wc->BcWishLetter)) $wLetter = '';
            if (empty($wc->BcWishWith))   $wWith   = '';
            if (empty($wc->BcWishFree))   $wReq    = '';
        }

        // Validation manuelle : en mode manuel, l'inscription arrive « en attente »
        // (BrValidated=0) et n'est pas placée tant que l'organisateur n'a pas validé.
        $mv = safe_fetch(safe_r_sql("SELECT BcManualValidation FROM BK_Competitions WHERE BcTournament = $tourId"));
        $validated = ($mv && intval($mv->BcManualValidation) === 1) ? 0 : 1;

        // Traçage BOOKING (auteur, demandes spéciales).
        safe_w_sql("INSERT INTO BK_Registrations SET
            BrEnId = $enId,
            BrTournament = $tourId,
            BrArcher = " . intval($by['archer'] ?? 0) . ",
            BrLicence = " . StrSafe_DB($lue->LueCode) . ",
            BrByRole = "  . StrSafe_DB($by['role'] ?? 'SELF') . ",
            BrBy = "      . StrSafe_DB(substr((string) ($by['who'] ?? ''), 0, 64)) . ",
            BrRequest = " . StrSafe_DB($wReq) . ",
            BrWantLetter = " . StrSafe_DB($wLetter) . ",
            BrWantWith = "   . StrSafe_DB($wWith) . ",
            BrValidated = $validated,
            BrDivision = " . StrSafe_DB($division) . ",
            BrClass = "    . StrSafe_DB($class) . ",
            BrSession = "  . intval($sessionOrder) . ",
            BrFace = $face");

        return array('ok' => true, 'enid' => $enId, 'event' => $ev, 'validated' => $validated);
    });
}

/**
 * Annule une inscription. N'accepte QUE des inscriptions créées par BOOKING
 * (présentes dans BK_Registrations) : un archer ne doit jamais pouvoir supprimer
 * un participant saisi par l'organisateur. Autorisée si l'inscription est celle
 * de l'archer ($licence) OU s'il en est l'AUTEUR (inscription de groupe qu'il a
 * faite pour un camarade de son club, BrArcher = $archerId).
 */
function bk_unregister($enId, $archerId, $licence)
{
    $enId = intval($enId);
    $archerId = intval($archerId);

    $rs = safe_r_sql("SELECT r.BrTournament, r.BrLicence, r.BrArcher, r.BrByRole, e.EnTournament
        FROM BK_Registrations r
        INNER JOIN Entries e ON e.EnId = r.BrEnId
        WHERE r.BrEnId = $enId");
    $r = safe_fetch($rs);
    if (!$r) return array('ok' => false, 'msg' => "Inscription introuvable.");
    // Inscription reprise d'un import (saisie hors module par l'organisateur) : visible
    // dans l'espace de l'archer, mais NON annulable par lui — l'invariant « un archer ne
    // supprime jamais un participant saisi par l'organisateur » reste vrai malgré la reprise.
    if ((string) $r->BrByRole === 'IMPORT') {
        return array('ok' => false, 'msg' => "Cette inscription a été saisie par l'organisateur : contactez-le pour toute modification.");
    }
    $isOwn    = bk_clean_licence($r->BrLicence) === bk_clean_licence($licence);
    $isAuthor = $archerId > 0 && intval($r->BrArcher) === $archerId;
    if (!$isOwn && !$isAuthor) {
        return array('ok' => false, 'msg' => "Cette inscription n'est pas la vôtre.");
    }

    $tourId = intval($r->BrTournament);
    $cfg    = bk_comp_config($tourId);
    if (empty($cfg->BcIsOpen)) {
        return array('ok' => false, 'msg' => "Les inscriptions sont closes : contactez l'organisateur.");
    }

    $res = bk_with_tournament($tourId, function () use ($enId, $tourId) {
        if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return false;

        // Mémoriser l'arme et le drapeau d'épreuve AVANT suppression : si on
        // supprime l'inscription porteuse de l'épreuve alors que l'archer garde
        // d'autres tirs avec la même arme, il faut en promouvoir une, sinon il
        // disparaîtrait du classement tout en restant inscrit.
        $rs = safe_r_sql("SELECT EnCode, EnDivision, EnIndClEvent FROM Entries WHERE EnId = $enId");
        $old = safe_fetch($rs);

        $p = Params4Recalc($enId);
        deleteArcher($enId);

        if ($old && intval($old->EnIndClEvent) === 1) {
            $rs = safe_r_sql("SELECT e.EnId FROM Entries e
                INNER JOIN Qualifications q ON q.QuId = e.EnId
                WHERE e.EnTournament = $tourId
                  AND e.EnCode = " . StrSafe_DB($old->EnCode) . "
                  AND e.EnDivision = " . StrSafe_DB($old->EnDivision) . "
                  AND e.EnAthlete = 1
                ORDER BY q.QuSession, e.EnId LIMIT 1");
            if ($n = safe_fetch($rs)) {
                safe_w_sql("UPDATE Entries SET EnIndClEvent = 1, EnTeamClEvent = 1,
                    EnIndFEvent = 1, EnTeamFEvent = 1, EnTeamMixEvent = 1,
                    EnTimestamp = '" . date('Y-m-d H:i:s') . "'
                    WHERE EnId = " . intval($n->EnId));
            }
        }

        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId");
            if ($t = safe_fetch($rs)) {
                for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            }
            MakeIndAbs();
        }
        return true;
    });

    if (!$res) return array('ok' => false, 'msg' => "Les inscriptions de cette compétition sont verrouillées.");

    safe_w_sql("DELETE FROM BK_Registrations WHERE BrEnId = $enId");
    return array('ok' => true);
}

/** Inscriptions d'un archer (toutes compétitions), les plus récentes d'abord. */
function bk_my_registrations($licence)
{
    bk_schema();
    $rs = safe_r_sql("SELECT r.BrId, r.BrEnId, r.BrTournament, r.BrRequest, r.BrCreated, r.BrByRole,
                r.BrWantLetter, r.BrWantWith, r.BrValidated,
                e.EnDivision, e.EnClass, e.EnIndClEvent, e.EnTargetFace,
                q.QuSession, q.QuTarget, q.QuLetter,
                d.DivDescription, c.ClDescription,
                t.ToName, t.ToWhere, t.ToVenue, t.ToWhenFrom, t.ToWhenTo,
                t.ToType, t.ToTypeName, t.ToTypeSubRule,
                o.BcShowAssignment, o.BcAllowScoresheet, o.BcFee, o.BcMandate, o.BcShowMandate, o.BcIanseoUrl,
                o.BcShowProgram, o.BcShowParticipants, o.BcShowResults, o.BcPublishLevel,
                " . bk_comp_calc_sql('o') . "
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        INNER JOIN Tournament t     ON t.ToId = r.BrTournament
        LEFT  JOIN Divisions d      ON d.DivTournament = t.ToId AND d.DivId = e.EnDivision
        LEFT  JOIN Classes c        ON c.ClTournament  = t.ToId AND c.ClId  = e.EnClass
        LEFT  JOIN BK_Competitions o ON o.BcTournament = t.ToId
        WHERE r.BrLicence = " . StrSafe_DB(bk_clean_licence($licence)) . "
        ORDER BY t.ToWhenFrom DESC");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}
