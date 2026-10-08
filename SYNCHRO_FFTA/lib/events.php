<?php
/**
 * SYNCHRO_FFTA — the FftaEvents table: the FFTA calendar as read on the extranet, kept for every
 * module of the server (see lib/schema.php for the columns).
 *
 * Writes: a calendar search refreshes its rows (list columns only), the « Détail » box of an event
 * adds its venue, and the creation of a competition decides whether the extranet coordinates stay
 * (venue kept as offered) or go (venue changed by the organiser).
 */

// Venue the organiser has not given yet: the extranet then shows the organiser's own address,
// which must neither be offered as the competition venue nor kept with its coordinates.
const SFA_VENUE_UNKNOWN = '/^(INCONNU|A D[ÉE]FINIR|À D[ÉE]FINIR|NON D[ÉE]FINI|-)?$/iu';

// The « Détail » box is read again after this delay only; a venue rarely moves.
const SFA_DETAIL_TTL = 21600;

/** ianseo competition code of an Exalto event: F + season (2 digits) + event number. */
function sfa_event_code(string $id, string $isoDateFrom): string
{
    $y = (int) substr($isoDateFrom, 0, 4);   // bytes: an ISO date is ASCII
    $m = (int) substr($isoDateFrom, 5, 2);   // bytes: an ISO date is ASCII
    if ($y === 0) {
        $y = (int) date('Y');
        $m = (int) date('n');
    }
    // Sports season: September onwards belongs to the next year.
    $season = (string) ($y + ($m >= 9 ? 1 : 0));

    return 'F' . substr($season, -2) . $id;   // bytes: digits only
}

/** State letter of a calendar label: A validated, R postponed, X cancelled, '' otherwise. */
function sfa_event_state(string $label): string
{
    $l = sfa_normalize($label);
    if (strpos($l, 'VALID') === 0) {
        return 'A';
    }
    if (strpos($l, 'REPORT') === 0) {
        return 'R';
    }
    if (strpos($l, 'ANNUL') === 0) {
        return 'X';
    }

    return '';
}

/** Keeps the rows of a calendar search (list columns only: the venue is not in the list). */
function sfa_events_save_list(array $events): void
{
    $now = StrSafe_DB(date('Y-m-d H:i:s'));
    foreach ($events as $ev) {
        if (!ctype_digit((string) ($ev['id'] ?? ''))) {
            continue;
        }
        $id   = (int) $ev['id'];
        $from = $ev['dateFrom'] !== '' ? StrSafe_DB($ev['dateFrom']) : 'NULL';
        $to   = $ev['dateTo'] !== '' ? StrSafe_DB($ev['dateTo']) : 'NULL';
        $cols = "FeCode=" . StrSafe_DB(sfa_event_code((string) $id, $ev['dateFrom']))
            . ", FeSeason=" . (int) $ev['season']
            . ", FeName=" . StrSafe_DB(mb_substr($ev['nom'], 0, 255))
            . ", FeOrgCode=" . StrSafe_DB(mb_substr($ev['orgCode'], 0, 10))
            . ", FeOrgName=" . StrSafe_DB(mb_substr($ev['orgName'], 0, 255))
            . ", FeDateFrom=$from, FeDateTo=$to"
            . ", FeState=" . StrSafe_DB(sfa_event_state($ev['etat']))
            . ", FeDiscipline=" . StrSafe_DB(mb_substr($ev['discipline'], 0, 100))
            . ", FeFormat=" . StrSafe_DB(mb_substr($ev['format'], 0, 150))
            . ", FeChampionship=" . StrSafe_DB(mb_substr($ev['type'], 0, 150))
            . ", FeValidePara=" . ($ev['validePara'] ? 1 : 0)
            . ", FeDuels=" . ($ev['duels'] ? 1 : 0)
            . ", FeDistinction=" . StrSafe_DB(mb_substr($ev['distinction'], 0, 50))
            . ", FeCity=" . StrSafe_DB(mb_substr($ev['lieu'], 0, 150))
            . ", FeSeenAt=$now";
        safe_w_sql("INSERT INTO FftaEvents SET FeId=$id, $cols ON DUPLICATE KEY UPDATE $cols");
    }
}

/** One event of the table, or null. */
function sfa_event_get(int $id)
{
    $rs = safe_r_sql("SELECT * FROM FftaEvents WHERE FeId=$id");

    return safe_fetch($rs) ?: null;
}

/** Is the « Détail » box of this row recent enough to be used without asking the extranet again? */
function sfa_event_detail_fresh($row): bool
{
    return $row && $row->FeDetailAt && (time() - strtotime($row->FeDetailAt) < SFA_DETAIL_TTL);
}

/**
 * Keeps the « Détail » box of an event. No venue is kept when the extranet does not know it yet
 * (the address shown is then the organiser's). The coordinates are never written again once the
 * organiser has replaced the venue at creation (FeVenueOwn).
 */
function sfa_events_save_detail(int $id, array $detail): void
{
    $v = $detail['venue'];
    if (preg_match(SFA_VENUE_UNKNOWN, trim($detail['lieu']))) {
        $v = ['name' => '', 'street' => '', 'zip' => '', 'city' => '', 'country' => '', 'lat' => null, 'lon' => null];
    }
    $lat = $v['lat'] !== null ? sprintf('%.6F', $v['lat']) : 'NULL';
    $lon = $v['lon'] !== null ? sprintf('%.6F', $v['lon']) : 'NULL';

    safe_w_sql("UPDATE FftaEvents SET"
        . " FeVenueName=" . StrSafe_DB(mb_substr($v['name'], 0, 255))
        . ", FeVenueStreet=" . StrSafe_DB(mb_substr($v['street'], 0, 255))
        . ", FeVenueZip=" . StrSafe_DB(mb_substr($v['zip'], 0, 10))
        . ", FeVenueCity=" . StrSafe_DB(mb_substr($v['city'], 0, 150))
        . ", FeVenueCountry=" . StrSafe_DB(mb_substr($v['country'], 0, 100))
        . ", FeLatitude=IF(FeVenueOwn=1, NULL, $lat)"
        . ", FeLongitude=IF(FeVenueOwn=1, NULL, $lon)"
        . ($detail['duels'] !== null ? ", FeDuels=" . ($detail['duels'] ? 1 : 0) : '')
        . ($detail['distinction'] !== '' ? ", FeDistinction=" . StrSafe_DB(mb_substr($detail['distinction'], 0, 50)) : '')
        . ", FeDetailAt=" . StrSafe_DB(date('Y-m-d H:i:s'))
        . " WHERE FeId=$id");
}

/**
 * « Lieu précis » offered at creation: place name, street, postcode and town (country when not
 * France), or '' when the extranet does not know the venue. Cut to the 255 bytes of
 * Tournament.ToWhere without splitting a character.
 */
function sfa_event_venue_text($row): string
{
    if (!$row || $row->FeVenueCity === '') {
        return '';
    }
    $town = trim($row->FeVenueZip . ' ' . $row->FeVenueCity);
    if ($row->FeVenueCountry !== '' && sfa_normalize($row->FeVenueCountry) !== 'FRANCE') {
        $town .= ' - ' . $row->FeVenueCountry;
    }
    $text = implode(', ', array_filter([$row->FeVenueName, $row->FeVenueStreet, $town], function ($s) {
        return $s !== '';
    }));

    return mb_strcut($text, 0, 255, 'UTF-8');
}

/**
 * At creation: the extranet coordinates stay only when the organiser kept the venue offered.
 * Otherwise they are removed, and FeVenueOwn keeps a later « Détail » from writing them back.
 */
function sfa_event_venue_decision(int $id, string $postedWhere): void
{
    $row = sfa_event_get($id);
    if (!$row) {
        return;
    }
    $offered = sfa_event_venue_text($row);
    $norm    = function ($s) {
        return trim(preg_replace('/\s+/u', ' ', $s));
    };
    if ($offered !== '' && $norm($postedWhere) === $norm($offered)) {
        safe_w_sql("UPDATE FftaEvents SET FeVenueOwn=0 WHERE FeId=$id");
    } else {
        safe_w_sql("UPDATE FftaEvents SET FeVenueOwn=1, FeLatitude=NULL, FeLongitude=NULL WHERE FeId=$id");
    }
}
