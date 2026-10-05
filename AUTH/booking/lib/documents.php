<?php
/**
 * lib/documents.php — distances of a category, read from the ianseo settings (TournamentDistances,
 * LIKE pattern on Division+Class), never asked again. Used by the registration form.
 */

if (defined('BK_DOCS_LOADED')) return;
define('BK_DOCS_LOADED', true);

require_once __DIR__ . '/schema.php';

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
