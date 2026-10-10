<?php
/**
 * lib/other.php — archers without an FFTA licence (cross-border archers, other federations).
 *
 * They have no account on the federation's licensee space, so they get their own (BaKind
 * 'OTHER'), signed in with an identifier (BaLicence) and a password they choose (BaPassword).
 * No way to contact them is kept.
 *
 * Signing up in two steps (public/other-signup.php):
 *  1. names, sex, country — searched in World Archery (bk_wa_search: the WA API only knows the
 *     archers who shot an international competition; typos forgiven here);
 *  2. the archer picks themselves in the list: names and sex come from WA (not editable
 *     afterwards), the identifier IS the WA id, the birth year is one of the two the WA age
 *     allows; asked then: year, club, password.
 *     Not in the list: their NATIONAL licence becomes the identifier; asked: licence, year,
 *     club, password.
 * The WA photos are loaded by the archer's browser straight from World Archery: nothing goes
 * through this server.
 *
 * The archer may delete their account (bk_other_self_delete, also for FFTA licensees): what is
 * to come is erased, what is done or under way stays.
 *
 * The rest of the online registration works unchanged through a LookUpEntries row of their own
 * (LueIocCode = their country, never FRA: the nightly FFTA import empties the FRA rows), with the
 * club they typed in LueCountry and their country in LueCountry2 (club 2 of the registration).
 *
 * Erased automatically one month after their last sign-in or their last competition, whichever
 * comes last, and never while they are registered for a competition not over (bk_other_purge).
 */

if (defined('BK_OTHER_LOADED')) return;
define('BK_OTHER_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/archer.php';
require_once __DIR__ . '/clock.php';   // bk_server_tz

define('BK_OTHER_KEEP_DAYS', 30);
define('BK_WA_API', 'https://api.worldarchery.org/v3/ATHLETES/');
define('BK_WA_PHOTO', 'https://extranet.worldarchery.sport/ProfilePictures/?Id=');

/** Countries offered, [IOC code => name in the reader's language], France left out. */
function bk_other_countries()
{
    global $CFG;
    $lang = array();
    $code = function_exists('aut_lang_code') ? mb_substr(aut_lang_code(), 0, 2) : 'en';
    foreach (array($code, 'en') as $l) {
        $f = $CFG->DOCUMENT_PATH . 'Common/Languages/' . preg_replace('/[^a-z]/', '', $l) . '/IOC_Codes.php';
        if (is_file($f)) { include $f; break; }
    }
    unset($lang['FRA']);
    $lang = array_filter($lang, function ($v, $k) { return preg_match('/^[A-Z]{3}$/', $k); }, ARRAY_FILTER_USE_BOTH);
    uasort($lang, function ($a, $b) { return strcmp(bk_other_norm($a), bk_other_norm($b)); });
    return $lang;
}

/** Upper-case ASCII letters and single spaces: names compared whatever the accents and typing. */
function bk_other_norm($s)
{
    $s = mb_strtoupper(trim((string) $s), 'UTF-8');
    $s = strtr($s, array('À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE', 'Ù' => 'U', 'Ú' => 'U',
        'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'ß' => 'SS', 'Ł' => 'L', 'Š' => 'S', 'Ž' => 'Z', 'Č' => 'C',
        'Ř' => 'R', 'Ě' => 'E', 'Ů' => 'U', 'Ő' => 'O', 'Ű' => 'U'));
    $s = preg_replace('/[^A-Z ]+/', ' ', $s);
    return trim(preg_replace('/ +/', ' ', $s));
}

/** Is $got close enough to $typed (typos forgiven: about one letter in four)? */
function bk_other_close($typed, $got)
{
    $typed = bk_other_norm($typed); $got = bk_other_norm($got);
    if ($typed === '' || $got === '') return false;
    if ($typed === $got || strpos($got, $typed) === 0 || strpos($typed, $got) === 0) return true;
    // bytes: both strings are plain ASCII once normalised
    $max = max(1, intdiv(min(strlen($typed), strlen($got)), 4));
    return levenshtein($typed, $got) <= $max;
}

/** One call to the WA API; null when it cannot be reached. */
function bk_wa_get($params)
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init(BK_WA_API . '?' . http_build_query($params));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ianseo-booking (registration)',
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $j = json_decode($body, true);
    return is_array($j) && isset($j['items']) && is_array($j['items']) ? $j['items'] : null;
}

/**
 * World Archery athletes of country $noc who may be the archer typed: same sex, family and given
 * names close to what was typed. Searched by a few letters of the family name (the API only finds
 * exact pieces), twice so that a typo in the first letters is caught too. Sorted by family then
 * given name. [WA id => ['id', 'family', 'given', 'sex' (0 man, 1 woman), 'age', 'photo' (bool)]],
 * or null when WA could not be reached.
 */
function bk_wa_search($noc, $family, $given, $sex)
{
    $fam = bk_other_norm($family);
    if ($fam === '' || !preg_match('/^[A-Z]{3}$/', (string) $noc)) return array();
    // bytes: $fam is plain ASCII (bk_other_norm)
    $pieces = array(substr($fam, 0, 3));
    if (strlen($fam) >= 6) $pieces[] = substr($fam, 2, 3);   // bytes: ASCII
    $items = array(); $reached = false;
    foreach (array_unique($pieces) as $p) {
        $res = bk_wa_get(array('Name' => $p, 'Country' => $noc, 'RBP' => 200));
        if ($res === null) continue;
        $reached = true;
        foreach ($res as $it) $items[(string) ($it['Id'] ?? '')] = $it;
    }
    if (!$reached) return null;

    $out = array();
    foreach ($items as $id => $it) {
        if (!ctype_digit((string) $id) || ($it['NOC'] ?? '') !== $noc) continue;
        $s = ($it['Gender'] ?? '') === 'W' ? 1 : 0;
        if ($s !== intval($sex)) continue;
        if (!bk_other_close($family, $it['FName'] ?? '')) continue;
        $g = bk_other_norm($it['GName'] ?? '');
        $gt = bk_other_norm($given);
        // Some WA entries only carry an initial: then the initial must match.
        // bytes: ASCII after bk_other_norm
        $okGiven = (strlen($g) <= 2) ? ($g !== '' && $gt !== '' && $g[0] === $gt[0]) : bk_other_close($given, $it['GName'] ?? '');
        if (!$okGiven) continue;
        $out[$id] = array('id' => intval($id), 'family' => trim((string) $it['FName']), 'given' => trim((string) $it['GName']),
            'sex' => $s, 'age' => intval($it['Age'] ?? 0), 'photo' => !empty($it['ProfilePicture']));
    }
    uasort($out, function ($a, $b) {
        return strcmp(bk_other_norm($a['family'] . ' ' . $a['given']), bk_other_norm($b['family'] . ' ' . $b['given']));
    });
    return $out;
}

/** The two birth years a WA age allows today ([] when WA gives no age). */
function bk_other_birth_years($age)
{
    $age = intval($age);
    if ($age <= 0) return array();
    $y = intval((new DateTime('now', bk_server_tz()))->format('Y'));
    return array($y - $age - 1, $y - $age);
}

/**
 * Is this archer already known? '' when not, otherwise the reason: 'licence' (this national
 * licence has an account, or is an FFTA licence number), 'wa' (this World Archery athlete has an
 * account), 'person' (same names, birth year, sex and country). $exceptId: the account being
 * edited.
 */
function bk_other_duplicate($d, $exceptId = 0)
{
    $lic = bk_clean_licence($d['licence'] ?? '');
    $ex = intval($exceptId);
    if ($lic !== '') {
        if (safe_fetch(safe_r_sql("SELECT BaId FROM BookingArchers WHERE BaLicence = " . StrSafe_DB($lic) . " AND BaId <> $ex"))) return 'licence';
        if (safe_fetch(safe_r_sql("SELECT LueCode FROM LookUpEntries WHERE LueCode = " . StrSafe_DB($lic) . " AND LueIocCode = 'FRA' LIMIT 1"))) return 'licence';
    }
    $wa = intval($d['wa'] ?? 0);
    if ($wa > 0 && safe_fetch(safe_r_sql("SELECT BaId FROM BookingArchers WHERE BaWaId = $wa AND BaId <> $ex"))) return 'wa';
    $rs = safe_r_sql("SELECT BaFamilyName, BaName FROM BookingArchers
        WHERE BaKind = 'OTHER' AND BaId <> $ex AND BaCountry = " . StrSafe_DB((string) $d['country']) . "
          AND BaBirthYear = " . intval($d['year']) . " AND BaSex = " . intval($d['sex']));
    $me = bk_other_norm($d['family']) . '|' . bk_other_norm($d['given']);
    while ($r = safe_fetch($rs)) {
        if (bk_other_norm($r->BaFamilyName) . '|' . bk_other_norm($r->BaName) === $me) return 'person';
    }
    return '';
}

/** Message of a reason given by bk_other_duplicate(). */
function bk_other_dup_message($why)
{
    $keys = array('licence' => 'OtDupLicence', 'wa' => 'OtDupWa', 'person' => 'OtDupPerson');
    return isset($keys[$why]) ? bk_t($keys[$why]) : '';
}

/** Club code of a typed club: never an FFTA agreement number nor a country code. */
function bk_other_club_code($noc, $club)
{
    return 'X' . $noc . strtoupper(substr(sprintf('%08x', crc32(bk_other_norm($club))), 0, 6));   // bytes: ASCII hex
}

/**
 * Writes the LookUpEntries row of an account (its identity for the online registration), the
 * former one removed first (licence or country may have changed).
 */
function bk_other_lue_sync($a, $old = null)
{
    $countries = bk_other_countries();
    foreach (array_filter(array($old, $a)) as $x) {
        safe_w_sql("DELETE FROM LookUpEntries WHERE LueCode = " . StrSafe_DB($x->BaLicence)
            . " AND LueIocCode = " . StrSafe_DB($x->BaCountry) . " AND LueIocCode <> 'FRA'");
    }
    if ($a->BaCountry === '' || $a->BaCountry === 'FRA') return;
    $cname = (string) ($countries[$a->BaCountry] ?? $a->BaCountry);
    safe_w_sql("INSERT INTO LookUpEntries SET
        LueCode = " . StrSafe_DB($a->BaLicence) . ", LueIocCode = " . StrSafe_DB($a->BaCountry) . ",
        LueFamilyName = " . StrSafe_DB(mb_substr($a->BaFamilyName, 0, 60)) . ",
        LueName = " . StrSafe_DB(mb_substr($a->BaName, 0, 30)) . ",
        LueSex = " . intval($a->BaSex) . ", LueClassified = 1,
        LueCtrlCode = " . StrSafe_DB(sprintf('%04d-01-01', intval($a->BaBirthYear))) . ",
        LueCountry = " . StrSafe_DB($a->BaClubCode) . ",
        LueCoDescr = " . StrSafe_DB(mb_substr($a->BaClubName, 0, 80)) . ",
        LueCoShort = " . StrSafe_DB(mb_substr($a->BaClubName, 0, 30)) . ",
        LueCountry2 = " . StrSafe_DB($a->BaCountry) . ",
        LueCoDescr2 = " . StrSafe_DB(mb_substr($cname, 0, 80)) . ",
        LueCoShort2 = " . StrSafe_DB(mb_substr($cname, 0, 30)) . ",
        LueCountry3 = '', LueCoDescr3 = '', LueCoShort3 = '',
        LueDivision = '', LueClass = '', LueSubClass = '',
        LueStatus = 0, LueDefault = 1, LueNameOrder = 0");
}

/**
 * Creates an account. $d: licence, password (or hash: already hashed, as kept between the steps
 * of the sign-up), family, given, sex, year, country, club, wa (0 or the WA id), source ('wa' or
 * 'own'). Returns the account id, or 0.
 */
function bk_other_create($d)
{
    bk_schema();
    $lic = bk_clean_licence($d['licence']);
    $club = trim((string) $d['club']);
    safe_w_sql("INSERT INTO BookingArchers SET
        BaLicence = " . StrSafe_DB($lic) . ", BaKind = 'OTHER',
        BaPassword = " . StrSafe_DB(!empty($d['hash']) ? $d['hash'] : password_hash((string) $d['password'], PASSWORD_DEFAULT)) . ",
        BaFamilyName = " . StrSafe_DB(mb_substr(trim($d['family']), 0, 60)) . ",
        BaName = " . StrSafe_DB(mb_substr(trim($d['given']), 0, 30)) . ",
        BaClubCode = " . StrSafe_DB(bk_other_club_code($d['country'], $club)) . ",
        BaCountry = " . StrSafe_DB($d['country']) . ",
        BaClubName = " . StrSafe_DB(mb_substr($club, 0, 80)) . ",
        BaSex = " . intval($d['sex']) . ", BaBirthYear = " . intval($d['year']) . ",
        BaWaId = " . intval($d['wa'] ?? 0) . ", BaSource = " . StrSafe_DB($d['source'] === 'wa' ? 'wa' : 'own'));
    $id = intval(safe_w_last_id());
    if ($id) {
        bk_other_lue_sync(bk_get_archer($id));
        bk_log('OTHER_CREATE', $lic);
    }
    return $id;
}

/**
 * Updates an account from its profile page: the club always; names, sex, birth year and country
 * only when they were typed (not taken from World Archery). $in already checked by the caller.
 */
function bk_other_update($a, $in)
{
    $club = trim((string) $in['club']);
    $set = array(
        'BaClubName = ' . StrSafe_DB(mb_substr($club, 0, 80)),
    );
    $country = $a->BaCountry;
    if ($a->BaSource !== 'wa') {
        $country = $in['country'];
        $set[] = 'BaFamilyName = ' . StrSafe_DB(mb_substr(trim($in['family']), 0, 60));
        $set[] = 'BaName = ' . StrSafe_DB(mb_substr(trim($in['given']), 0, 30));
        $set[] = 'BaSex = ' . intval($in['sex']);
        $set[] = 'BaBirthYear = ' . intval($in['year']);
        $set[] = 'BaCountry = ' . StrSafe_DB($country);
    }
    $set[] = 'BaClubCode = ' . StrSafe_DB(bk_other_club_code($country, $club));
    safe_w_sql("UPDATE BookingArchers SET " . implode(', ', $set) . " WHERE BaId = " . intval($a->BaId) . " AND BaKind = 'OTHER'");
    bk_other_lue_sync(bk_get_archer($a->BaId), $a);
    bk_log('OTHER_UPDATE', $a->BaLicence);
}

/** Sign-in check: the account, or null. Never says which of the two was wrong. */
function bk_other_check($licence, $password)
{
    bk_schema();
    $a = safe_fetch(safe_r_sql("SELECT * FROM BookingArchers WHERE BaKind = 'OTHER'
        AND BaLicence = " . StrSafe_DB(bk_clean_licence($licence))));
    if (!$a || $a->BaPassword === '' || !password_verify((string) $password, $a->BaPassword)) return null;
    return $a;
}

/** First step of the sign-up, and the profile: names and country. '' when fine, else the message. */
function bk_other_check_identity($d)
{
    if (trim((string) $d['family']) === '' || trim((string) $d['given']) === '') return bk_t('OtNeedName');
    if (!isset(bk_other_countries()[$d['country']])) return bk_t('OtNeedCountry');
    return '';
}

/**
 * Second step of the sign-up, and the profile: birth year and club; the national licence when
 * the archer is not taken from World Archery ($licence); the password at sign-up ($password).
 */
/** Birth years accepted when typed: [first, last], the last being the current year. */
function bk_other_year_range()
{
    $y = intval((new DateTime('now', bk_server_tz()))->format('Y'));
    return array($y - 110, $y);
}

function bk_other_check_account($d, $licence, $password)
{
    list($from, $to) = bk_other_year_range();
    if (intval($d['year']) < $from || intval($d['year']) > $to) return bk_t('OtNeedYear');
    if (trim((string) $d['club']) === '') return bk_t('OtNeedClub');
    if ($licence && !preg_match('/^[A-Z0-9][A-Z0-9.\-\/]{2,24}$/', bk_clean_licence($d['licence']))) return bk_t('OtNeedLicence');
    if ($password) {
        if (mb_strlen((string) $d['password']) < 8) return bk_t('OtPwdShort');
        if ((string) $d['password'] !== (string) $d['password2']) return bk_t('OtPwdDiffer');
    }
    return '';
}

/** Address of the athlete's page on World Archery's site: French when read in French, English otherwise (WA has no other language). */
function bk_wa_profile_url($id, $given, $family)
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(bk_other_norm($given . ' ' . $family))), '-');   // bytes: ASCII after bk_other_norm
    $fr = function_exists('aut_lang_code') && mb_substr(aut_lang_code(), 0, 2) === 'fr';
    return 'https://www.worldarchery.sport/' . ($fr ? 'fr/' : '') . 'profile/' . intval($id) . '/' . ($slug !== '' ? $slug : 'athlete') . '/biography';
}

/**
 * The archer deletes their account (any account, FFTA or not). What is to come is erased for good:
 * their own online registrations for the competitions not started (unless the organiser locked
 * the participants) and their waiting requests. What is done or under way stays as it is: the
 * competitions started, the past results, the accounts and payments. Returns ['deleted' => n,
 * 'kept' => [competition names whose registration could not be removed]].
 */
function bk_other_self_delete($archer)
{
    require_once __DIR__ . '/registration.php';
    require_once __DIR__ . '/waitlist.php';
    $lic = bk_clean_licence($archer->BaLicence);
    $id = intval($archer->BaId);
    $out = array('deleted' => 0, 'kept' => array());
    $rs = safe_r_sql("SELECT BrEnId, BrTournament, ToName FROM BookingRegistrations
        INNER JOIN Tournament ON ToId = BrTournament
        WHERE BrLicence = " . StrSafe_DB($lic) . " AND BrByRole <> 'IMPORT'
          AND ToWhenFrom > " . bk_local_today_sql('ToTimeZone'));
    $replan = array();
    while ($r = safe_fetch($rs)) {
        $res = bk_unregister($r->BrEnId, $id, $lic, true);
        if (!empty($res['ok'])) { $out['deleted']++; $replan[intval($r->BrTournament)] = true; }
        else $out['kept'][] = $r->ToName;
    }
    foreach (array_keys($replan) as $t) {
        bk_replan_all($t, bk_comp_config($t));
        bk_waitlist_process($t);   // the places freed go to the waiting list
    }
    safe_w_sql("DELETE BookingWaitlist FROM BookingWaitlist INNER JOIN Tournament ON ToId = BwTournament
        WHERE BwLicence = " . StrSafe_DB($lic) . " AND ToWhenFrom > " . bk_local_today_sql('ToTimeZone'));
    safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher = $id");
    safe_w_sql("DELETE FROM BookingClubManagers WHERE BmArcher = $id");
    if (($archer->BaKind ?? '') === 'OTHER') {
        safe_w_sql("DELETE FROM LookUpEntries WHERE LueCode = " . StrSafe_DB($archer->BaLicence)
            . " AND LueIocCode = " . StrSafe_DB($archer->BaCountry) . " AND LueIocCode <> 'FRA'");
    }
    safe_w_sql("DELETE FROM BookingArchers WHERE BaId = $id");
    bk_log('ACCOUNT_SELF_DELETE', $lic);
    return $out;
}

/** The profile page: identity and account fields together. */
function bk_other_validate($d, $needPassword)
{
    $e = bk_other_check_identity($d);
    return $e !== '' ? $e : bk_other_check_account($d, $needPassword, $needPassword);
}

/**
 * Erases the accounts with nothing left to keep them: no registration for a competition not over,
 * and a last sign-in and a last competition both older than a month. Their past registrations in
 * the competitions stay (results), as for any archer. Returns the number erased.
 */
function bk_other_purge()
{
    bk_schema();
    $days = intval(BK_OTHER_KEEP_DAYS);
    $rs = safe_r_sql("SELECT BaId, BaLicence, BaCountry FROM BookingArchers
        WHERE BaKind = 'OTHER'
          AND COALESCE(BaLastLogin, BaCreated) < DATE_SUB(NOW(), INTERVAL $days DAY)
          AND NOT EXISTS (SELECT 1 FROM BookingRegistrations INNER JOIN Tournament ON ToId = BrTournament
                WHERE BrLicence = BaLicence AND ToWhenTo >= DATE_SUB(CURDATE(), INTERVAL $days DAY))");
    $n = 0;
    while ($a = safe_fetch($rs)) {
        $id = intval($a->BaId);
        safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher = $id");
        safe_w_sql("DELETE FROM BookingWaitlist WHERE BwLicence = " . StrSafe_DB($a->BaLicence) . " AND BwStatus = 0");
        safe_w_sql("DELETE FROM LookUpEntries WHERE LueCode = " . StrSafe_DB($a->BaLicence)
            . " AND LueIocCode = " . StrSafe_DB($a->BaCountry) . " AND LueIocCode <> 'FRA'");
        safe_w_sql("DELETE FROM BookingArchers WHERE BaId = $id AND BaKind = 'OTHER'");
        bk_log('OTHER_PURGE', $a->BaLicence);
        $n++;
    }
    return $n;
}
