<?php
/**
 * lib/club.php — inscription par un gestionnaire de club.
 *
 * Autonomie : le module ne `require` JAMAIS AUTH et ne suppose pas sa présence.
 * Deux sources de droits, cumulatives :
 *   1. BK_ClubManagers — table propre au module, alimentée par l'administrateur.
 *      C'est le repli qui rend la fonctionnalité utilisable sans aucun module
 *      de comptes.
 *   2. La session ianseo, SI un module de comptes en a posé une : lecture seule
 *      de $_SESSION['AUTH_ROLE'] / ['AUTH_SCOPE'] (convention documentée dans le
 *      CLAUDE.md racine). Aucune fonction d'AUTH n'est appelée.
 */

if (defined('BK_CLUB_LOADED')) return;
define('BK_CLUB_LOADED', true);

require_once __DIR__ . '/schema.php';

/**
 * Agréments de club que cet archer peut gérer.
 * Retourne un tableau de codes (agréments complets LLDDCCC), éventuellement
 * avec des motifs LIKE pour un périmètre départemental ou régional.
 */
function bk_manager_scopes($archer)
{
    bk_schema();
    $out = array();

    if ($archer) {
        $rs = safe_r_sql("SELECT BmClub FROM BK_ClubManagers WHERE BmArcher = " . intval($archer->BaId));
        while ($r = safe_fetch($rs)) $out[] = $r->BmClub;
    }

    // Session d'un module de comptes, si elle existe — jamais requise.
    $role  = (string) ($_SESSION['AUTH_ROLE'] ?? '');
    $scope = (string) ($_SESSION['AUTH_SCOPE'] ?? '');
    if ($scope !== '' && in_array($role, array('CLUB', 'CD', 'CR'), true)) {
        if ($role === 'CD')      $out[] = '__' . $scope . '%';
        elseif ($role === 'CR')  $out[] = $scope . '%';
        else                     $out[] = $scope;
    }

    return array_values(array_unique($out));
}

/** Condition SQL « LueCountry appartient à l'un de ces périmètres ». */
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

/** Un agrément donné est-il dans le périmètre ? (contrôle serveur avant écriture) */
function bk_scope_covers($scopes, $clubCode)
{
    $club = strtoupper(trim((string) $clubCode));
    foreach ($scopes as $s) {
        $s = strtoupper(trim((string) $s));
        if (strpbrk($s, '%_') !== false) {
            $re = '';
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

/** Licenciés du périmètre, filtrés par nom ou licence. */
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

/** Libellés des clubs du périmètre (pour l'affichage). */
function bk_scope_labels($scopes)
{
    if (!$scopes) return array();
    $rs = safe_r_sql("SELECT DISTINCT LueCountry, LueCoDescr FROM LookUpEntries
        WHERE " . bk_scopes_sql($scopes) . " ORDER BY LueCoDescr");
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->LueCountry] = $r->LueCoDescr;
    return $out;
}
