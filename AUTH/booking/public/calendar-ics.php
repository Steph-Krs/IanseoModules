<?php
/**
 * public/calendar-ics.php — export iCalendar (.ics) des compétitions de l'archer.
 *
 * V1 : instantané téléchargeable (le téléphone l'importe dans son agenda). Réservé
 * à l'archer connecté (comme le reste de l'espace) ; ne sert QUE ses propres
 * inscriptions. La V2 (abonnement webcal à MàJ automatique via un jeton secret par
 * archer) est notée pour plus tard — elle demandera un endpoint anonyme + token.
 *
 * Chaque compétition = un événement « journée entière » (VALUE=DATE), du premier au
 * dernier jour (DTEND exclusif en iCal → dernier jour + 1).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';

$archer = bk_require_archer();

/** Échappement iCalendar (RFC 5545) d'une valeur texte. */
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

// Compétitions de l'archer, dédoublonnées par compétition (plusieurs départs = 1 événement).
$seen = array();
$events = array();
foreach (bk_my_registrations($archer->BaLicence) as $r) {
    $tid = intval($r->BrTournament);
    if (isset($seen[$tid])) continue;
    $seen[$tid] = true;
    $events[] = $r;
}

$L = array();
$L[] = 'BEGIN:VCALENDAR';
$L[] = 'VERSION:2.0';
$L[] = 'PRODID:-//ianseo//Inscriptions en ligne//FR';
$L[] = 'CALSCALE:GREGORIAN';
$L[] = 'METHOD:PUBLISH';
$L[] = 'X-WR-CALNAME:Mes compétitions';
foreach ($events as $r) {
    $from = substr((string) $r->ToWhenFrom, 0, 10);
    $to   = substr((string) $r->ToWhenTo, 0, 10);
    if ($from === '' || strpos($from, '0000') === 0) continue;   // date invalide → on saute
    if ($to === '' || strpos($to, '0000') === 0) $to = $from;
    $dtStart = str_replace('-', '', $from);
    $dtEnd   = date('Ymd', strtotime($to . ' +1 day'));           // DTEND exclusif (journée entière)

    $dd  = bk_comp_discipline($r->ToType, $r->ToTypeSubRule ?? '', $r->ToTypeName ?? '');
    $labels = bk_disc_labels();
    $disc = $labels[$dd['key']] ?? '';
    $url  = $base . bk_public_url('competition.php?t=' . intval($r->BrTournament));
    $desc = trim(($disc !== '' ? $disc . ' — ' : '') . 'Voir la fiche : ' . $url);

    $L[] = 'BEGIN:VEVENT';
    $L[] = 'UID:bk-' . intval($r->BrTournament) . '@' . $host;
    $L[] = 'DTSTAMP:' . $stamp;
    $L[] = 'DTSTART;VALUE=DATE:' . $dtStart;
    $L[] = 'DTEND;VALUE=DATE:' . $dtEnd;
    $L[] = 'SUMMARY:' . bk_ics_esc($r->ToName);
    if (trim((string) $r->ToWhere) !== '') $L[] = 'LOCATION:' . bk_ics_esc($r->ToWhere);
    $L[] = 'DESCRIPTION:' . bk_ics_esc($desc);
    $L[] = 'URL:' . bk_ics_esc($url);
    $L[] = 'TRANSP:TRANSPARENT';
    $L[] = 'END:VEVENT';
}
$L[] = 'END:VCALENDAR';

// Repli des lignes trop longues (RFC 5545 : 75 octets, poursuite préfixée d'une espace).
$out = array();
foreach ($L as $line) {
    while (strlen($line) > 74) { $out[] = substr($line, 0, 74); $line = ' ' . substr($line, 74); }
    $out[] = $line;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="mes-competitions.ics"');
echo implode("\r\n", $out) . "\r\n";
