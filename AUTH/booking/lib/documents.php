<?php
/**
 * lib/documents.php — données des feuilles de marque et des reçus.
 *
 * Tout est LU dans la configuration ianseo, rien n'est redemandé :
 *  - rythme de tir  : DistanceInformation (DiEnds volées × DiArrows flèches)
 *  - distances      : TournamentDistances (motif LIKE sur Division+Classe)
 *  - blason         : TargetFaces via Entries.EnTargetFace
 */

if (defined('BK_DOCS_LOADED')) return;
define('BK_DOCS_LOADED', true);

require_once __DIR__ . '/schema.php';

/**
 * Fiche complète d'une inscription pour l'impression.
 * Retourne null si l'inscription n'existe pas ou n'a pas été créée par BOOKING.
 */
function bk_doc_entry($enId)
{
    $enId = intval($enId);
    $rs = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnDob, e.EnSex,
                e.EnDivision, e.EnClass, e.EnTargetFace,
                c.CoCode, c.CoName,
                q.QuSession, q.QuTarget, q.QuLetter, q.QuTargetNo,
                d.DivDescription, cl.ClDescription,
                t.ToId, t.ToName, t.ToWhere, t.ToWhenFrom, t.ToWhenTo, t.ToNumDist, t.ToType,
                r.BrLicence, r.BrCreated, r.BrRequest, r.BrByRole,
                o.BcFee, o.BcPricing, o.BcAllowScoresheet
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        INNER JOIN Tournament t     ON t.ToId = e.EnTournament
        LEFT  JOIN Countries c      ON c.CoId = e.EnCountry
        LEFT  JOIN Divisions d      ON d.DivTournament = t.ToId AND d.DivId = e.EnDivision
        LEFT  JOIN Classes cl       ON cl.ClTournament = t.ToId AND cl.ClId = e.EnClass
        LEFT  JOIN BK_Competitions o ON o.BcTournament = t.ToId
        WHERE r.BrEnId = $enId");
    return safe_fetch($rs) ?: null;
}

/**
 * Rythme de tir d'un départ : une entrée par distance (volées, flèches).
 * Lu dans DistanceInformation — la table que ianseo alimente depuis l'écran
 * « Distances », jamais une valeur devinée depuis ToNumEnds (qui compte parfois
 * les volées du round entier, piège déjà documenté côté PRONO).
 */
function bk_doc_rhythm($tourId, $session)
{
    $rs = safe_r_sql("SELECT DiDistance, DiEnds, DiArrows
        FROM DistanceInformation
        WHERE DiTournament = " . intval($tourId) . "
          AND DiSession = " . intval($session) . "
          AND DiType = 'Q'
        ORDER BY DiDistance");
    $out = array();
    while ($r = safe_fetch($rs)) {
        $out[] = array('dist' => intval($r->DiDistance),
                       'ends' => intval($r->DiEnds), 'arrows' => intval($r->DiArrows));
    }
    return $out;
}

/**
 * Libellés de distance applicables à une catégorie (Division+Classe).
 * Reprend la correspondance du cœur : CONCAT(EnDivision, EnClass) LIKE TdClasses,
 * filtrée par ToType. Le motif le plus long l'emporte en cas de recouvrement.
 */
function bk_doc_distances($tourId, $type, $division, $class)
{
    $rs = safe_r_sql("SELECT Td1, Td2, Td3, Td4, TdDist1, TdDist2, TdDist3, TdDist4
        FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . "
          AND TdType = " . StrSafe_DB($type) . "
          AND " . StrSafe_DB(trim($division) . trim($class)) . " LIKE TdClasses
        ORDER BY CHAR_LENGTH(TdClasses) DESC
        LIMIT 1");
    $r = safe_fetch($rs);
    if (!$r) return array();

    $out = array();
    for ($i = 1; $i <= 4; $i++) {
        $lab = trim((string) $r->{'Td' . $i});
        if ($lab === '' || $lab === '-') continue;
        $out[] = array('label' => $lab, 'metres' => intval($r->{'TdDist' . $i}));
    }
    return $out;
}

/** Blason de l'archer (nom + diamètre), depuis Entries.EnTargetFace. */
function bk_doc_face($tourId, $tfId)
{
    if (!$tfId) return '';
    $rs = safe_r_sql("SELECT TfW1, TfT1 FROM TargetFaces
        WHERE TfTournament = " . intval($tourId) . " AND TfId = " . intval($tfId));
    $r = safe_fetch($rs);
    if (!$r) return '';
    return $r->TfW1 ? intval($r->TfW1) . ' cm' : '';
}
