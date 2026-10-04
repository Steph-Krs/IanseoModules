<?php
/**
 * lib/documents.php — data of the score sheets.
 *
 * Everything is READ from the ianseo settings, nothing is asked again:
 *  - shooting rhythm: DistanceInformation (DiEnds ends × DiArrows arrows)
 *  - distances      : TournamentDistances (LIKE pattern on Division+Class)
 *  - target face    : TargetFaces through Entries.EnTargetFace
 */

if (defined('BK_DOCS_LOADED')) return;
define('BK_DOCS_LOADED', true);

require_once __DIR__ . '/schema.php';

/**
 * Full record of a registration for printing.
 * Returns null when the registration does not exist or was not made through booking.
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
 * Shooting rhythm of a departure: one entry per distance (ends, arrows).
 * Read from DistanceInformation — the table ianseo fills from the "Distances" screen, never a
 * value guessed from ToNumEnds (which sometimes counts the ends of the whole round, a trap
 * already documented for PRONO).
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
 * Distance labels that apply to a category (Division+Class).
 * Same matching as the core: CONCAT(EnDivision, EnClass) LIKE TdClasses, filtered by ToType.
 * The longest pattern wins when they overlap.
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

/** The archer's target face (name + diameter), from Entries.EnTargetFace. */
function bk_doc_face($tourId, $tfId)
{
    if (!$tfId) return '';
    $rs = safe_r_sql("SELECT TfW1, TfT1 FROM TargetFaces
        WHERE TfTournament = " . intval($tourId) . " AND TfId = " . intval($tfId));
    $r = safe_fetch($rs);
    if (!$r) return '';
    return $r->TfW1 ? intval($r->TfW1) . ' cm' : '';
}
