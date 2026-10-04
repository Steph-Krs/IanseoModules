<?php
/**
 * public/calendar-ics.php — iCalendar (.ics) export of the archer's competitions.
 *
 * V1: downloadable snapshot (the phone imports it into its calendar). For the connected archer
 * only (like the rest of the space); serves ONLY their own registrations. V2 (webcal
 * subscription updated automatically through a secret token per archer) is noted for later —
 * it will need an anonymous endpoint + token.
 *
 * Each competition = one "all day" event (VALUE=DATE), from the first to the last day (DTEND
 * is exclusive in iCal → last day + 1).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';

$archer = bk_require_archer();

/** iCalendar escaping (RFC 5545) of a text value. */
function bk_ics_esc($s)
{
    $s = str_replace('\\', '\\\\', (string) $s);
    $s = str_replace(array(';', ','), array('\\;', '\\,'), $s);
    $s = str_replace(array("\r\n", "\r", "\n"), '\\n', $s);
    return $s;
}

$host   = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ianseo'));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $host;
$stamp  = gmdate('Ymd\THis\Z');

// Grouped by competition, keeping the archer's DEPARTURES (one competition = one all-day event,
// with its departures detailed in the description).
$byComp = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) {
    $tid = intval($r->BrTournament);
    if (!isset($byComp[$tid])) $byComp[$tid] = array('c' => $r, 'ses' => array());
    $so = intval($r->QuSession);
    if ($so > 0) $byComp[$tid]['ses'][$so] = true;
}

$labels = bk_disc_labels();

$L = array();
$L[] = 'BEGIN:VCALENDAR';
$L[] = 'VERSION:2.0';
$L[] = 'PRODID:-//ianseo//Inscriptions en ligne//FR';
$L[] = 'CALSCALE:GREGORIAN';
$L[] = 'METHOD:PUBLISH';
$L[] = 'X-WR-CALNAME:' . bk_ics_esc(bk_t('IcsCalName'));
foreach ($byComp as $tid => $g) {
    $r = $g['c'];
    $from = substr((string) $r->ToWhenFrom, 0, 10);   // bytes: ASCII dates
    $to   = substr((string) $r->ToWhenTo, 0, 10);
    if ($from === '' || strpos($from, '0000') === 0) continue;   // invalid date → skipped
    if ($to === '' || strpos($to, '0000') === 0) $to = $from;
    $dtStart = str_replace('-', '', $from);
    $dtEnd   = date('Ymd', strtotime($to . ' +1 day'));           // exclusive DTEND (all day)

    $dd   = bk_comp_discipline($r->ToType, $r->ToTypeSubRule ?? '', $r->ToTypeName ?? '');
    $disc = $labels[$dd['key']] ?? '';
    $url  = $base . bk_public_url('competition.php?t=' . $tid);

    // Place = precise place (ToWhere: gym/stadium) + town (ToVenue).
    $loc   = trim((string) $r->ToWhere);
    $venue = trim((string) ($r->ToVenue ?? ''));
    if ($venue !== '' && stripos($loc, $venue) === false) $loc = ($loc !== '' ? $loc . ', ' : '') . $venue;

    // The archer's departures: name, start time, estimated length.
    $byOrder = array();
    foreach (bk_comp_sessions($tid) as $s) $byOrder[intval($s->SesOrder)] = $s;
    $orders = array_keys($g['ses']); sort($orders);
    $depLines = array();
    foreach ($orders as $so) {
        $s = $byOrder[$so] ?? null;
        $name = ($s && trim((string) $s->SesName) !== '') ? ' ' . bk_t('QuotedX', trim($s->SesName)) : '';
        $hh = '';
        if ($s) {
            $st = bk_session_start($s);
            if (preg_match('/ (\d{2}):(\d{2})/', $st, $m) && $m[1] . $m[2] !== '0000') {
                $hh = ' ' . bk_t('AtTime', date(bk_t('TimeFormat'), mktime(intval($m[1]), intval($m[2]), 0, 1, 1, 2000)));
            }
        }
        $dur = bk_dur_hm(bk_session_format($tid, $so)['min']);
        $line = bk_t('DepCap', $so) . $name . $hh;
        if ($dur !== '') $line .= ' — ' . bk_t('DurationX', $dur);
        $depLines[] = '• ' . $line;
    }

    // DESCRIPTION: real new lines, escaped into \n by bk_ics_esc afterwards.
    $desc = '';
    if ($disc !== '') $desc .= $disc . "\n";
    if ($depLines) $desc .= bk_t('IcsYourDeps') . "\n" . implode("\n", $depLines) . "\n";
    $desc .= bk_t('IcsPage', $url);

    $L[] = 'BEGIN:VEVENT';
    $L[] = 'UID:bk-' . $tid . '@' . $host;
    $L[] = 'DTSTAMP:' . $stamp;
    $L[] = 'DTSTART;VALUE=DATE:' . $dtStart;
    $L[] = 'DTEND;VALUE=DATE:' . $dtEnd;
    $L[] = 'SUMMARY:' . bk_ics_esc($r->ToName);
    if ($loc !== '') $L[] = 'LOCATION:' . bk_ics_esc($loc);
    $L[] = 'DESCRIPTION:' . bk_ics_esc($desc);
    $L[] = 'URL:' . bk_ics_esc($url);
    $L[] = 'TRANSP:TRANSPARENT';
    $L[] = 'END:VEVENT';
}
$L[] = 'END:VCALENDAR';

// Folding of long lines (RFC 5545: 75 bytes, continuation prefixed with a space). Counted in
// bytes on purpose; mb_strcut never splits a character.
$out = array();
foreach ($L as $line) {
    while (strlen($line) > 74) {   // bytes, as the RFC counts
        $head = mb_strcut($line, 0, 74, 'UTF-8');
        $out[] = $head;
        $line = ' ' . substr($line, strlen($head));   // bytes: offset of the cut
    }
    $out[] = $line;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="mes-competitions.ics"');
echo implode("\r\n", $out) . "\r\n";
