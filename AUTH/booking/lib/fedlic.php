<?php
/**
 * lib/fedlic.php — licensee files published by some foreign federations (ianseo "VERSION 2.0"
 * format), for the accounts of archers without an FFTA licence (lib/other.php).
 *
 * The addresses are those the ianseo team keeps in the core table LookUpPaths; the files are
 * public. Loaded each night (booking/cron/sync-fed.php) into BookingFedLicences — never into
 * LookUpEntries: Italian and Slovenian numbers are plain counters that would be taken for a World
 * Archery id or another country's licence, and a synchronisation of the country by an organiser
 * (Partecipants › lookup) erases all its rows.
 *
 * An archer of these countries signs up with their licence: names, sex, birth date and club come
 * from the file, refreshed each night, and cannot be edited. A licence missing from the file gets
 * no account; an archived one (status 9) does, but cannot register (bk_register).
 *
 * The file of the Baltic countries (BALT) does not give the country: the licence prefix does.
 */

if (defined('BK_FEDLIC_LOADED')) return;
define('BK_FEDLIC_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/archer.php';

/**
 * Countries whose federation publishes its file: [IOC country => ['src' => file code in
 * LookUpPaths, 're' => licence format (from the files of 2026-10), 'ex' => example shown]]. The
 * example is a mask (0 a digit, A a letter), never a real licence nor the exact format: nobody is
 * to be tempted to type someone else's number. The format itself is only checked on the server.
 */
function bk_fed_countries()
{
    return array(
        'CAN' => array('src' => 'CAN',  're' => '/^(AB|AC|BC|MB|NB|NL|NS|NT|NU|ON|PE|QC|SK|YT)[0-9]{1,8}$/', 'ex' => 'AA00000'),
        'EST' => array('src' => 'BALT', 're' => '/^EE?[0-9]{3,8}$/', 'ex' => 'A0000000'),
        'ITA' => array('src' => 'ITA',  're' => '/^[1-9][0-9]{0,5}$/', 'ex' => '00000'),
        'LAT' => array('src' => 'BALT', 're' => '/^LV[0-9]{5}$/', 'ex' => 'AA00000'),
        'LTU' => array('src' => 'BALT', 're' => '/^LTU[0-9]{4}$/', 'ex' => 'AAA0000'),
        'SLO' => array('src' => 'SLO',  're' => '/^[0-9]{4}$/', 'ex' => '0000'),
    );
}

/** Countries whose file is loaded (at least one licence known), as [IOC => config]. */
function bk_fed_active()
{
    static $out = null;
    if ($out !== null) return $out;
    $out = array();
    $all = bk_fed_countries();
    $rs = safe_r_sql("SELECT DISTINCT BflCountry FROM BookingFedLicences", false, true);
    while ($rs && ($r = safe_fetch($rs))) {
        if (isset($all[$r->BflCountry])) $out[$r->BflCountry] = $all[$r->BflCountry];
    }
    return $out;
}

/** The licence as typed, without the "COUNTRY-" of an identifier typed in full. */
function bk_fed_clean($country, $typed)
{
    $raw = bk_clean_licence($typed);
    $pre = $country . '-';
    if (mb_strpos($raw, $pre) === 0) $raw = mb_substr($raw, mb_strlen($pre));
    return $raw;
}

/** Does $raw have the format of the licences of $country? */
function bk_fed_format_ok($country, $raw)
{
    $c = bk_fed_countries()[$country] ?? null;
    return $c !== null && preg_match($c['re'], (string) $raw) === 1;
}

/** The row of the file for this licence, or null. */
function bk_fed_find($country, $raw)
{
    if ((string) $raw === '') return null;
    return safe_fetch(safe_r_sql("SELECT * FROM BookingFedLicences
        WHERE BflCountry = " . StrSafe_DB($country) . " AND BflCode = " . StrSafe_DB($raw))) ?: null;
}

/** Country of a line of a file: the file's own, or for BALT the one of the licence prefix. */
function bk_fed_line_country($src, $code)
{
    if ($src !== 'BALT') {
        foreach (bk_fed_countries() as $ioc => $c) if ($c['src'] === $src) return $ioc;
        return '';
    }
    // bytes: licence prefixes are ASCII
    if (strpos($code, 'LTU') === 0) return 'LTU';
    if (strpos($code, 'LV') === 0) return 'LAT';
    if (strpos($code, 'E') === 0) return 'EST';
    return '';
}

/** Address of a file in LookUpPaths (tab-separated format only), '' when unknown. */
function bk_fed_url($src)
{
    $r = safe_fetch(safe_r_sql("SELECT LupPath, LupOrigin FROM LookUpPaths WHERE LupIocCode = " . StrSafe_DB($src)));
    if (!$r || trim((string) $r->LupOrigin) !== '') return '';
    $url = trim((string) $r->LupPath);
    return preg_match('~^https?://~i', $url) ? $url : '';
}

/** Downloads and uncompresses a file; null on failure ($err says why). */
function bk_fed_download($url, &$err)
{
    $err = '';
    if (!function_exists('curl_init')) { $err = 'curl missing'; return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ianseo-booking (licences)',
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false || $body === '') { $err = $cerr !== '' ? $cerr : 'empty answer'; return null; }
    if ($code !== 200) { $err = "HTTP $code"; return null; }
    $u = @gzuncompress($body);
    return $u !== false ? $u : $body;
}

/**
 * Reads a file: ['date' => …, 'clubs' => [code => [name, short]], 'lines' => [the ENTRIES lines]],
 * or null when it is not a "VERSION 2.0" file of $src.
 */
function bk_fed_parse($src, $data)
{
    $lines = explode("\n", str_replace("\r", '', (string) $data));
    if (!preg_match('/^VERSION:\s*2\.0\s*$/', $lines[0] ?? '')) return null;
    if (!preg_match('/^DATE:\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $lines[1] ?? '', $m)) return null;
    // bytes: the IOC header of the file is ASCII
    if (strtoupper(trim(preg_replace('/^IOC:/', '', $lines[2] ?? ''))) !== $src) return null;
    if (trim($lines[3] ?? '') !== 'CLUBS') return null;
    $clubs = array();
    $i = 4;
    for ($n = count($lines); $i < $n && trim($lines[$i]) !== 'ENTRIES'; $i++) {
        $c = explode("\t", $lines[$i]);
        if (trim($c[0]) !== '') $clubs[trim($c[0])] = array(trim($c[1] ?? ''), trim($c[2] ?? ''));
    }
    if ($i >= count($lines)) return null;
    return array('date' => $m[1], 'clubs' => $clubs, 'lines' => array_slice($lines, $i + 1));
}

/** A date of a file, or NULL for SQL. */
function bk_fed_date_sql($d)
{
    $d = trim((string) $d);
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && $m[1] !== '0000' && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ? StrSafe_DB($d) : 'NULL';
}

/**
 * Replaces the licences of file $src by those of $data. A file holding fewer than 90 % of the
 * licences of the day before is not believed (cut short? or a cleaning by the federation): the
 * former ones stay, unless the administrator forces it ($force, admin/config.php). Returns
 * ['ok' => bool, 'short' => refused as too short, 'count' => licences of the file, 'before' =>
 * the day before, 'date' => date of the file, 'msg' => …].
 */
function bk_fed_import($src, $data, $force = false)
{
    bk_schema();
    $f = bk_fed_parse($src, $data);
    if ($f === null) return array('ok' => false, 'short' => false, 'count' => 0, 'before' => 0, 'date' => '', 'msg' => 'invalid format');
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BookingFedLicences WHERE BflSource = " . StrSafe_DB($src)));
    $before = $r ? intval($r->n) : 0;
    $count = 0;
    foreach ($f['lines'] as $l) {
        $c = explode("\t", $l, 3);
        if (trim($c[0]) !== '' && bk_fed_line_country($src, trim($c[0])) !== '') $count++;
    }
    $out = array('ok' => false, 'short' => false, 'count' => $count, 'before' => $before, 'date' => $f['date'], 'msg' => '');
    if ($count === 0) { $out['msg'] = 'empty file'; return $out; }
    if (!$force && $count < 0.9 * $before) {
        $out['short'] = true;
        $out['msg'] = 'file too short, former licences kept';
        return $out;
    }

    safe_w_BeginTransaction();
    safe_w_sql("DELETE FROM BookingFedLicences WHERE BflSource = " . StrSafe_DB($src));
    $head = "INSERT IGNORE INTO BookingFedLicences (BflCountry, BflCode, BflSource, BflFamilyName, BflName, BflSex,
        BflBirth, BflClub, BflClubName, BflClubShort, BflStatus, BflValidUntil) VALUES ";
    $rows = array();
    foreach ($f['lines'] as $l) {
        $c = explode("\t", $l);
        $code = trim($c[0]);
        $ioc = $code !== '' ? bk_fed_line_country($src, $code) : '';
        if ($ioc === '' || mb_strlen($code) > 25) continue;
        $club = trim($c[9] ?? '');
        $cl = $f['clubs'][$club] ?? array('', '');
        $rows[] = '(' . StrSafe_DB($ioc) . ', ' . StrSafe_DB($code) . ', ' . StrSafe_DB($src) . ', '
            . StrSafe_DB(mb_substr(trim($c[2] ?? ''), 0, 60)) . ', ' . StrSafe_DB(mb_substr(trim($c[3] ?? ''), 0, 30)) . ', '
            . (intval($c[4] ?? 0) ? 1 : 0) . ', ' . bk_fed_date_sql($c[5] ?? '') . ', '
            . StrSafe_DB(mb_substr($club, 0, 10)) . ', ' . StrSafe_DB(mb_substr($cl[0], 0, 80)) . ', '
            . StrSafe_DB(mb_substr($cl[1] !== '' ? $cl[1] : $cl[0], 0, 30)) . ', '
            . intval($c[7] ?? 0) . ', ' . bk_fed_date_sql($c[8] ?? '') . ')';
        if (count($rows) >= 500) { safe_w_sql($head . implode(', ', $rows)); $rows = array(); }
    }
    if ($rows) safe_w_sql($head . implode(', ', $rows));
    safe_w_Commit();
    $out['ok'] = true;
    return $out;
}

/**
 * Writes the LookUpEntries row of a licence of a file under its identifier "COUNTRY-licence" —
 * the row of an account taken from the file, or of a clubmate registered by another archer of
 * their club (who may have no account).
 */
function bk_fed_lue_write($f)
{
    require_once __DIR__ . '/other.php';
    $code = bk_clean_licence($f->BflCountry . '-' . $f->BflCode);
    safe_w_sql("DELETE FROM LookUpEntries WHERE LueCode = " . StrSafe_DB($code) . " AND LueIocCode = " . StrSafe_DB($f->BflCountry));
    bk_other_lue_insert(array('code' => $code, 'country' => $f->BflCountry, 'family' => $f->BflFamilyName,
        'given' => $f->BflName, 'sex' => intval($f->BflSex), 'birth' => (string) $f->BflBirth,
        'club' => $f->BflClub, 'club_name' => $f->BflClubName, 'club_short' => $f->BflClubShort,
        'status' => intval($f->BflStatus), 'valid' => (string) $f->BflValidUntil));
}

/**
 * Downloads and loads one file, records its state (BookingFedSources) and a line of the jobs
 * journal (admin/config.php). $force: take it even when shorter than 90 % of the day before.
 * Returns the state: 'ok', 'short', 'fail' or 'nourl'.
 */
function bk_fed_sync_source($src, $force = false)
{
    bk_schema();
    $log = function ($status, $key, $a) use ($src) {
        if (function_exists('aut_job_log')) aut_job_log('fed:' . $src, $status, $key, $a);
    };
    $set = function ($state, $msg, $extra = '') use ($src) {
        safe_w_sql("INSERT INTO BookingFedSources SET BfsSource = " . StrSafe_DB($src) . ", BfsChecked = UTC_TIMESTAMP(),
            BfsState = " . StrSafe_DB($state) . ", BfsMessage = " . StrSafe_DB(mb_substr($msg, 0, 255)) . $extra . "
            ON DUPLICATE KEY UPDATE BfsChecked = UTC_TIMESTAMP(), BfsState = VALUES(BfsState), BfsMessage = VALUES(BfsMessage)" . $extra);
    };
    $url = bk_fed_url($src);
    if ($url === '') {
        $set('nourl', '');
        $log('skip', 'JbFedNoUrl', $src);
        return 'nourl';
    }
    $err = '';
    $data = bk_fed_download($url, $err);
    if ($data === null) {
        $set('fail', $err);
        $log('fail', 'JbFedDownload', array('src' => $src, 'err' => $err));
        return 'fail';
    }
    $res = bk_fed_import($src, $data, $force);
    unset($data);
    $a = array('src' => $src, 'n' => $res['count'], 'b' => $res['before']);
    if ($res['ok']) {
        $set('ok', '', ", BfsLoaded = UTC_TIMESTAMP(), BfsCount = " . intval($res['count']) . ", BfsPending = 0,
            BfsFileDate = " . StrSafe_DB($res['date']));
        $log('ok', $force ? 'JbFedForced' : 'JbFedOk', $a);
        return 'ok';
    }
    if ($res['short']) {
        $set('short', '', ", BfsPending = " . intval($res['count']) . ", BfsFileDate = " . StrSafe_DB($res['date']));
        $log('warn', 'JbFedShort', $a);
        return 'short';
    }
    $set('fail', $res['msg']);
    $log('fail', 'JbFedFormat', array('src' => $src, 'err' => $res['msg']));
    return 'fail';
}

/** Files known: [source => [countries]]. */
function bk_fed_sources()
{
    $out = array();
    foreach (bk_fed_countries() as $ioc => $c) $out[$c['src']][] = $ioc;
    return $out;
}

/**
 * Every file, then the accounts; one summary line in the journal. Returns ['states' => [source
 * => state], 'accounts' => accounts refreshed].
 */
function bk_fed_sync_all()
{
    $states = array();
    foreach (array_keys(bk_fed_sources()) as $src) $states[$src] = bk_fed_sync_source($src);
    $n = bk_fed_refresh_accounts();
    $ok = count(array_filter($states, function ($s) { return $s === 'ok'; }));
    $status = $ok === count($states) ? 'ok' : ($ok > 0 ? 'warn' : 'fail');
    if (function_exists('aut_job_log')) {
        aut_job_log('fed-sync', $status, 'JbFedSummary', array('ok' => $ok, 'all' => count($states), 'acc' => $n));
    }
    return array('states' => $states, 'accounts' => $n);
}

/** State of the files, for the administrator: [source => row of BookingFedSources or null]. */
function bk_fed_state()
{
    bk_schema();
    $out = array_fill_keys(array_keys(bk_fed_sources()), null);
    $rs = safe_r_sql("SELECT * FROM BookingFedSources");
    while ($r = safe_fetch($rs)) if (array_key_exists($r->BfsSource, $out)) $out[$r->BfsSource] = $r;
    return $out;
}

/** Writes the row of identifier "COUNTRY-licence" when it is a licence of a loaded file. */
function bk_fed_lue_ensure($identifier)
{
    if (!preg_match('/^([A-Z]{3})-(.+)$/', bk_clean_licence($identifier), $m) || !isset(bk_fed_countries()[$m[1]])) return false;
    $f = bk_fed_find($m[1], $m[2]);
    if (!$f) return false;
    bk_fed_lue_write($f);
    return true;
}

/**
 * Each night, after the files: the accounts taken from a file get their identity and club again
 * (a licence no longer in the file keeps its last values), and every account of an archer without
 * an FFTA licence gets its LookUpEntries row written again — a synchronisation of its country by
 * an organiser may have erased it. Returns the number of accounts refreshed from a file.
 */
function bk_fed_refresh_accounts()
{
    require_once __DIR__ . '/other.php';
    $n = 0;
    $rs = safe_r_sql("SELECT * FROM BookingArchers WHERE BaKind = 'OTHER'");
    while ($a = safe_fetch($rs)) {
        if ($a->BaSource === 'fed') {
            $f = bk_fed_find($a->BaCountry, bk_fed_clean($a->BaCountry, $a->BaLicence));
            if ($f) {
                safe_w_sql("UPDATE BookingArchers SET
                    BaFamilyName = " . StrSafe_DB($f->BflFamilyName) . ", BaName = " . StrSafe_DB($f->BflName) . ",
                    BaSex = " . intval($f->BflSex) . ", BaBirthYear = " . intval(mb_substr((string) $f->BflBirth, 0, 4)) . ",
                    BaClubCode = " . StrSafe_DB($f->BflClub) . ", BaClubName = " . StrSafe_DB($f->BflClubName) . "
                    WHERE BaId = " . intval($a->BaId));
                $a = bk_get_archer($a->BaId);
                $n++;
            }
        }
        bk_other_lue_sync($a);
    }
    // Clubmates registered without an account: their rows follow the file too.
    $in = implode(', ', array_map('StrSafe_DB', array_keys(bk_fed_countries())));
    $rs = safe_r_sql("SELECT DISTINCT LueCode FROM LookUpEntries
        WHERE LueIocCode IN ($in) AND LueCode LIKE CONCAT(LueIocCode, '-%')
          AND NOT EXISTS (SELECT 1 FROM BookingArchers WHERE BaLicence = LueCode)");
    while ($r = safe_fetch($rs)) bk_fed_lue_ensure($r->LueCode);
    return $n;
}
