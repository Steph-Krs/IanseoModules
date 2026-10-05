<?php
/**
 * lib/club.php — registration by a club manager.
 *
 * Standalone: the module NEVER `require`s AUTH and does not assume it is there.
 * Two sources of rights, added together:
 *   1. BookingClubManagers — the module's own table, filled by the administrator. This is the
 *      fallback that makes the feature usable without any accounts module.
 *   2. The ianseo session, IF an accounts module set one: read-only use of
 *      $_SESSION['AUTH_ROLE'] / ['AUTH_SCOPE'] (session convention shared by the modules).
 *      No AUTH function is called.
 */

if (defined('BK_CLUB_LOADED')) return;
define('BK_CLUB_LOADED', true);

require_once __DIR__ . '/schema.php';

/**
 * Club approval numbers this archer may manage.
 * Returns an array of codes (full LLDDCCC approval numbers), possibly with LIKE patterns for a
 * department or regional scope.
 */
function bk_manager_scopes($archer)
{
    bk_schema();
    $out = array();

    if ($archer) {
        $rs = safe_r_sql("SELECT BmClub FROM BookingClubManagers WHERE BmArcher = " . intval($archer->BaId));
        while ($r = safe_fetch($rs)) $out[] = $r->BmClub;
    }

    // Session of an accounts module, if there is one — never required.
    $role  = (string) ($_SESSION['AUTH_ROLE'] ?? '');
    $scope = (string) ($_SESSION['AUTH_SCOPE'] ?? '');
    if ($scope !== '' && in_array($role, array('CLUB', 'CD', 'CR'), true)) {
        if ($role === 'CD')      $out[] = '__' . $scope . '%';
        elseif ($role === 'CR')  $out[] = $scope . '%';
        else                     $out[] = $scope;
    }

    return array_values(array_unique($out));
}

/** SQL condition "LueCountry belongs to one of these scopes". */
function bk_scopes_sql($scopes, $col = 'LueCountry')
{
    if (!$scopes) return '0';
    $p = array();
    foreach ($scopes as $s) {
        $p[] = (strpbrk($s, '%_') !== false)
            ? "$col LIKE " . StrSafe_DB($s)
            : "$col = " . StrSafe_DB($s);
    }
    return '(' . implode(' OR ', $p) . ')';
}

/** Is a given approval number in the scope? (server check before writing) */
function bk_scope_covers($scopes, $clubCode)
{
    // bytes: licence numbers and club codes are ASCII letters and digits
    $club = strtoupper(trim((string) $clubCode));
    foreach ($scopes as $s) {
        $s = strtoupper(trim((string) $s));
        if (strpbrk($s, '%_') !== false) {
            $re = '';
            // bytes: licence numbers and club codes are ASCII letters and digits
            foreach (str_split($s) as $ch) {
                if ($ch === '%')     $re .= '.*';
                elseif ($ch === '_') $re .= '.';
                else                 $re .= preg_quote($ch, '/');
            }
            if (preg_match('/^' . $re . '$/', $club)) return true;
        } elseif ($s === $club) {
            return true;
        }
    }
    return false;
}

/** Licensees of the scope, filtered by name or licence. */
function bk_club_members($scopes, $q = '', $limit = 60)
{
    if (!$scopes) return array();
    $w = array(bk_scopes_sql($scopes));
    $q = trim($q);
    if ($q !== '') {
        $like = StrSafe_DB('%' . $q . '%');
        $w[] = "(LueFamilyName LIKE $like OR LueName LIKE $like OR LueCode LIKE $like)";
    }
    $rs = safe_r_sql("SELECT LueCode, LueFamilyName, LueName, LueCtrlCode, LueSex,
                LueCountry, LueCoDescr, LueSubClass, LueIocCode
        FROM LookUpEntries
        WHERE " . implode(' AND ', $w) . "
        ORDER BY LueFamilyName, LueName
        LIMIT " . intval($limit));
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Labels of the clubs of the scope (for display). */
function bk_scope_labels($scopes)
{
    if (!$scopes) return array();
    $rs = safe_r_sql("SELECT DISTINCT LueCountry, LueCoDescr FROM LookUpEntries
        WHERE " . bk_scopes_sql($scopes) . " ORDER BY LueCoDescr");
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->LueCountry] = $r->LueCoDescr;
    return $out;
}
