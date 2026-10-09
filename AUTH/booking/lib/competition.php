<?php
/**
 * lib/competition.php — opening of online registration, competition by competition.
 *
 * The FIELD setup is not here: it is already entered in ianseo (Session, DistanceInformation,
 * TargetFaces, TournamentDistances) and read as is. BookingCompetitions only holds what belongs
 * to online registration.
 *
 * ⚠️ Dates: NOW()/CURDATE() are NOT a clock shared by both faces — the MySQL connection is in
 * UTC on the archer pages and in the competition's zone on the organiser pages (real bug,
 * 2026-09-30). Any time typed by the organiser is compared with the competition's LOCAL
 * time: see lib/clock.php.
 */

if (defined('BK_COMP_LOADED')) return;
define('BK_COMP_LOADED', true);

require_once __DIR__ . '/schema.php';

/** Geographic restriction scopes offered. */
function bk_restrict_kinds()
{
    return array(
        ''   => bk_t('RestrictNone'),
        'CD' => bk_t('RestrictDept'),
        'CR' => bk_t('RestrictRegion'),
    );
}

/**
 * LIKE pattern of the club agreement numbers covered by a restriction. Agreement number =
 * LLDDCCC (league 2 + department 2 + club 3), same convention as the AUTH module.
 */
function bk_scope_like($kind, $code)
{
    $code = trim((string) $code);
    if ($code === '') return '%';
    if (preg_match('/[_%]/', $code)) return $code;      // expert pattern, as is
    if ($kind === 'CD') return '__' . $code . '%';      // department in positions 3-4
    return $code . '%';                                 // region: prefix
}

/** Check of a typed scope. Empty string = valid. */
function bk_scope_error($kind, $code)
{
    if ($kind === '') return '';
    $code = trim((string) $code);
    if ($code === '') return bk_t('ScopeNeedCode');
    if (preg_match('/^[0-9A-Za-z_%]{2,12}$/', $code) && preg_match('/[_%]/', $code)) return '';
    if (!preg_match('/^[0-9A-Za-z]{2,10}$/', $code)) return bk_t('ScopeFormat');
    // bytes: the code was just checked to be ASCII letters and digits.
    if ($kind === 'CD' && strlen($code) != 2) return bk_t('ScopeDept2');
    if ($kind === 'CR' && strlen($code) != 2) return bk_t('ScopeRegion2');
    return '';
}

/**
 * SQL fragment of the columns computed by MySQL. `o` = alias of BookingCompetitions.
 *  BcIsOpen  : registration is open right now
 *  BcAllOpen : the geographic restriction is lifted (none, or its opening date reached)
 */
function bk_comp_calc_sql($alias = '')
{
    $a = $alias !== '' ? "$alias." : '';
    // The organiser typed these times in the competition's local time: compare them with
    // the competition's local "now", identical whatever the page (lib/clock.php).
    $now = bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = {$a}BcTournament)");
    $win = function ($from, $to) use ($now) {
        return "($from IS NULL OR $from <= $now) AND ($to IS NULL OR $to >= $now)";
    };
    $general = $win("{$a}BcOpenFrom", "{$a}BcOpenTo");
    // Open = at least one departure open. At level 3 a departure may be closed or carry its own
    // dates (lib/sessionrules.php; "opens once the earlier ones are full" counts as open here,
    // the exact state is computed per departure). A competition without any departure yet
    // follows the general period.
    $open = $general;
    if (bk_session_rules_ready()) {
        $noSes = "NOT EXISTS (SELECT 1 FROM Session WHERE SesTournament = {$a}BcTournament AND SesType = 'Q')";
        $open = "EXISTS (SELECT 1 FROM Session
                    LEFT JOIN BookingSessionRules ON {$a}BcPublishLevel = 3
                         AND BdTournament = SesTournament AND BdSession = SesOrder
                    WHERE SesTournament = {$a}BcTournament AND SesType = 'Q' AND COALESCE(BdState, 1) <> 0
                      AND " . $win("COALESCE(BdOpenFrom, {$a}BcOpenFrom)", "COALESCE(BdOpenTo, {$a}BcOpenTo)") . ")
                 OR ($noSes AND $general)";
    }
    return "({$a}BcOpen = 1 AND ($open)) AS BcIsOpen,
            ({$a}BcRestrictKind = ''
              OR ({$a}BcRestrictTo IS NOT NULL AND {$a}BcRestrictTo <= $now)) AS BcAllOpen";
}

/**
 * Does BookingSessionRules exist yet? Asked once per request from the catalogue, which never
 * fails: the open state is computed by every page, some of which may run before the schema of
 * this version (cron, a session started before the update).
 */
function bk_session_rules_ready()
{
    static $ready = null;
    if ($ready === null) {
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingSessionRules'"));
        $ready = $r && intval($r->n) > 0;
    }
    return $ready;
}

/** Default values of a competition never configured. */
function bk_comp_defaults($tourId)
{
    return (object) array(
        'BcTournament' => intval($tourId),
        'BcOpen' => 0, 'BcOpenFrom' => null, 'BcOpenTo' => null,
        'BcRestrictKind' => '', 'BcRestrictCode' => '', 'BcRestrictTo' => null,
        'BcMaxPerClubPerTarget' => 2, 'BcMinClubsPerSession' => 3,
        'BcShowAssignment' => 0, 'BcShowGauges' => 1, 'BcAllowScoresheet' => 0,
        'BcWishLetter' => 1, 'BcWishWith' => 0, 'BcWishFree' => 0,
        'BcFee' => '0.00', 'BcPricing' => null, 'BcShopUntil' => null, 'BcExcludeStats' => 0,
        'BcPayInfo' => null, 'BcManualValidation' => 0, 'BcMandate' => null, 'BcShowMandate' => null,
        'BcIanseoUrl' => null, 'BcShowProgram' => 0, 'BcShowParticipants' => 0, 'BcShowResults' => 0,
        'BcShowDossard' => 0, 'BcSurvey' => 1, 'BcWaitlist' => 1, 'BcSingleReg' => 0,
        'BcPublishLevel' => 1, 'BcAdvancedBackup' => null, 'BcPayments' => 0,
        'BcIsOpen' => 0, 'BcAllOpen' => 1,
    );
}

/**
 * "Detailed" setting columns of a competition — those that level 2 (simple publication) sets
 * by itself, and that are SAVED (snapshot) to be restored when going back to level 3 ("keep
 * but hide").
 */
function bk_comp_advanced_cols()
{
    // BcFee (base fee) is left out ON PURPOSE: a stable effective setting, also typed at level 2
    // ("To finish"), which the snapshot must not put back to its former value when going back
    // to level 3. Only the DETAILED tariff (BcPricing) is set aside by level 2, then restored.
    return array('BcOpen', 'BcOpenFrom', 'BcOpenTo', 'BcRestrictKind', 'BcRestrictCode', 'BcRestrictTo',
        'BcMaxPerClubPerTarget', 'BcMinClubsPerSession', 'BcShowAssignment', 'BcShowGauges', 'BcAllowScoresheet',
        'BcWishLetter', 'BcWishWith', 'BcWishFree', 'BcPricing', 'BcManualValidation',
        'BcShowMandate', 'BcMandate', 'BcIanseoUrl', 'BcShowProgram', 'BcShowParticipants', 'BcShowResults',
        'BcShowDossard', 'BcSurvey', 'BcWaitlist', 'BcPayInfo', 'BcShopUntil', 'BcSingleReg');
}

/**
 * Address of the ianseo.net page of a competition — REBUILT, never typed.
 *
 * `Tournament.ToOnlineId` is the identifier given by ianseo.net together with the publication
 * codes (core: `CheckCredentials`); 0 = the competition is not published there, so no page
 * exists. Format taken from a competition really published (F26WFT1 → toId 27215). Returns
 * '' when there is nothing to offer.
 */
function bk_ianseo_url($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT ToOnlineId FROM Tournament WHERE ToId = " . intval($tourId)));
    $id = $r ? intval($r->ToOnlineId) : 0;
    return $id > 0 ? 'https://www.ianseo.net/Details.php?toId=' . $id : '';
}

/** Setup of a competition (defaults if never saved). */
function bk_comp_config($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $q = safe_r_sql("SELECT *, " . bk_comp_calc_sql() . "
        FROM BookingCompetitions WHERE BcTournament = $tourId");
    $r = safe_fetch($q);
    return $r ?: bk_comp_defaults($tourId);
}

/* ------------------------------------------------------------------ */
/* "Copy from…" — take the setup of another competition               */
/* ------------------------------------------------------------------ */

/**
 * SQL fragment limiting the competitions ACCESSIBLE to the current organiser (on the
 * Tournament alias $alias). Reads AUTH's session convention (no hard dependency): without
 * AUTH, or server administrator → everything; otherwise the AUTH_COMP list (exact codes or
 * LIKE patterns). Safe fallback: no access.
 */
function bk_copy_access_where($alias = '')
{
    $a = $alias !== '' ? "$alias." : '';
    if (empty($_SESSION['AUTH_ENABLE'])) return '1=1';     // localhost / single organiser
    if (!empty($_SESSION['AUTH_ROOT']))  return '1=1';     // server administrator (free field)
    $comp = $_SESSION['AUTH_COMP'] ?? array();
    if (!is_array($comp) || !$comp) return '1=0';
    $ors = array();
    foreach ($comp as $c) {
        $c = (string) $c;
        if ($c === '') continue;
        $ors[] = (strpos($c, '%') !== false || strpos($c, '_') !== false)
            ? "{$a}ToCode LIKE " . StrSafe_DB($c)
            : "{$a}ToCode = " . StrSafe_DB($c);
    }
    return $ors ? '(' . implode(' OR ', $ors) . ')' : '1=0';
}

/** Is the current organiser the server administrator? ("Copy" source = free field). */
function bk_copy_is_admin()
{
    return !empty($_SESSION['AUTH_ENABLE']) && !empty($_SESSION['AUTH_ROOT']);
}

/** Accessible competitions with a booking setup, except the current one. */
function bk_copy_sources($currentTour)
{
    bk_schema();
    $currentTour = intval($currentTour);
    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhenFrom
        FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament
        WHERE ToId <> $currentTour AND " . bk_copy_access_where() . "
        ORDER BY ToWhenFrom DESC, ToName LIMIT 500");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Resolves a free entry of the administrator (competition code, or numeric id) to an
 * accessible ToId with a booking setup. Returns the id, or 0.
 */
function bk_copy_resolve($input, $currentTour)
{
    $input = trim((string) $input);
    if ($input === '') return 0;
    $currentTour = intval($currentTour);
    $where = bk_copy_access_where();
    if (ctype_digit($input)) {
        $r = safe_fetch(safe_r_sql("SELECT ToId FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament
            WHERE ToId = " . intval($input) . " AND ToId <> $currentTour AND $where"));
        if ($r) return intval($r->ToId);
    }
    $r = safe_fetch(safe_r_sql("SELECT ToId FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament
        WHERE ToCode = " . StrSafe_DB($input) . " AND ToId <> $currentTour AND $where
        ORDER BY ToWhenFrom DESC LIMIT 1"));
    return $r ? intval($r->ToId) : 0;
}

/**
 * Copies the booking setup of $srcTour to $destTour. REPLACES the settings (publication
 * level, registration, visibility, tariff, mandate, shop, field constraints). DATES keep the
 * same OFFSET from the start date. Not copied: identity (BcCode), geocoding (by venue), the
 * ianseo.net link (each competition's own), the LOGOS — only the mandate's "which logos"
 * boxes are. Returns true when copied.
 */
function bk_comp_copy_from($destTour, $srcTour)
{
    bk_schema();
    $destTour = intval($destTour); $srcTour = intval($srcTour);
    if ($destTour <= 0 || $srcTour <= 0 || $destTour === $srcTour) return false;

    $src = safe_fetch(safe_r_sql("SELECT BcTournament, ToWhenFrom AS SrcStart
        FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament WHERE BcTournament = $srcTour"));
    if (!$src) return false;                                   // the source has no booking setup
    $dst = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = $destTour"));
    if (!$dst) return false;

    safe_w_sql("INSERT IGNORE INTO BookingCompetitions (BcTournament) VALUES ($destTour)");

    $ss = StrSafe_DB($src->SrcStart);
    $ds = StrSafe_DB($dst->ToWhenFrom);
    // Same offset from the start date (e.g. "opens 30 days before, closes 2 days before").
    $remap = function ($col) use ($ss, $ds) {
        return "IF(s.$col IS NULL, NULL, DATE_ADD($ds, INTERVAL TIMESTAMPDIFF(SECOND, $ss, s.$col) SECOND))";
    };

    safe_w_sql("UPDATE BookingCompetitions d INNER JOIN BookingCompetitions s ON s.BcTournament = $srcTour SET
        d.BcOpen = s.BcOpen, d.BcPublishLevel = s.BcPublishLevel, d.BcAdvancedBackup = s.BcAdvancedBackup,
        d.BcOpenFrom = " . $remap('BcOpenFrom') . ", d.BcOpenTo = " . $remap('BcOpenTo') . ",
        d.BcRestrictKind = s.BcRestrictKind, d.BcRestrictCode = s.BcRestrictCode,
        d.BcRestrictTo = " . $remap('BcRestrictTo') . ",
        d.BcMaxPerClubPerTarget = s.BcMaxPerClubPerTarget, d.BcMinClubsPerSession = s.BcMinClubsPerSession,
        d.BcShowAssignment = s.BcShowAssignment, d.BcShowGauges = s.BcShowGauges, d.BcAllowScoresheet = s.BcAllowScoresheet,
        d.BcFee = s.BcFee, d.BcWishLetter = s.BcWishLetter, d.BcWishWith = s.BcWishWith, d.BcWishFree = s.BcWishFree,
        d.BcPricing = s.BcPricing, d.BcShopUntil = " . $remap('BcShopUntil') . ", d.BcExcludeStats = s.BcExcludeStats,
        d.BcPayInfo = s.BcPayInfo, d.BcManualValidation = s.BcManualValidation, d.BcMandate = s.BcMandate,
        d.BcShowMandate = s.BcShowMandate, d.BcShowProgram = s.BcShowProgram,
        d.BcShowParticipants = s.BcShowParticipants, d.BcShowResults = s.BcShowResults,
        d.BcShowDossard = s.BcShowDossard, d.BcSurvey = s.BcSurvey, d.BcWaitlist = s.BcWaitlist,
        d.BcSingleReg = s.BcSingleReg, d.BcPayments = s.BcPayments
        WHERE d.BcTournament = $destTour");

    // Opening of each departure, its own dates moved by the same offset.
    safe_w_sql("DELETE FROM BookingSessionRules WHERE BdTournament = $destTour");
    safe_w_sql("INSERT INTO BookingSessionRules (BdTournament, BdSession, BdState, BdOpenFrom, BdOpenTo)
        SELECT $destTour, BdSession, BdState,
            IF(BdOpenFrom IS NULL, NULL, DATE_ADD($ds, INTERVAL TIMESTAMPDIFF(SECOND, $ss, BdOpenFrom) SECOND)),
            IF(BdOpenTo IS NULL, NULL, DATE_ADD($ds, INTERVAL TIMESTAMPDIFF(SECOND, $ss, BdOpenTo) SECOND))
        FROM BookingSessionRules WHERE BdTournament = $srcTour");

    bk_comp_copy_shop($destTour, $srcTour);
    bk_comp_copy_caps($destTour, $srcTour);
    return true;
}

/**
 * Copies the points of sale (settings, stands, catalogue — AUTH/shop, which replaced the shop of
 * the online registration) when the source has some. The destination's are REPLACED, which the
 * shop refuses once the destination has orders: then nothing is copied. Returns the number of
 * products copied.
 */
function bk_comp_copy_shop($destTour, $srcTour)
{
    require_once dirname(__DIR__, 2) . '/shop/lib/copy.php';
    shp_schema();
    if (!safe_fetch(safe_r_sql("SELECT SdId FROM ShopStands WHERE SdTournament = " . intval($srcTour) . " LIMIT 1"))) return 0;
    $r = shp_copy_from($destTour, $srcTour);
    return empty($r['error']) ? intval($r['products']) : 0;
}

/**
 * Copies the field constraints. Distances = metres (portable). The FACES are mapped again BY
 * NAME (TfName): TfId values do not carry from one competition to another. A face missing at
 * the destination is skipped. REPLACES the existing constraints.
 */
function bk_comp_copy_caps($destTour, $srcTour)
{
    $destTour = intval($destTour); $srcTour = intval($srcTour);
    $srcName = array();                          // TfId (source) => TfName
    $rs = safe_r_sql("SELECT TfId, TfName FROM TargetFaces WHERE TfTournament = $srcTour");
    while ($r = safe_fetch($rs)) $srcName[(string) $r->TfId] = trim((string) $r->TfName);
    $destByName = array();                       // TfName => TfId (destination)
    $rs = safe_r_sql("SELECT TfId, TfName FROM TargetFaces WHERE TfTournament = $destTour");
    while ($r = safe_fetch($rs)) { $n = trim((string) $r->TfName); if ($n !== '' && !isset($destByName[$n])) $destByName[$n] = (string) $r->TfId; }

    $caps = array();
    $rs = safe_r_sql("SELECT * FROM BookingTargetCaps WHERE BtTournament = $srcTour");
    while ($r = safe_fetch($rs)) $caps[] = $r;

    safe_w_sql("DELETE FROM BookingTargetCaps WHERE BtTournament = $destTour");
    foreach ($caps as $c) {
        $ids = array();
        foreach (array_filter(explode(',', (string) $c->BtFaces), 'strlen') as $fid) {
            $name = $srcName[(string) intval($fid)] ?? '';
            if ($name !== '' && isset($destByName[$name])) $ids[] = $destByName[$name];
        }
        $faces = implode(',', array_values(array_unique($ids)));
        safe_w_sql("INSERT INTO BookingTargetCaps (BtTournament, BtSession, BtTarget, BtDistances, BtDistDef, BtDistMin, BtDistMax, BtFaces)
            VALUES ($destTour, " . intval($c->BtSession) . ", " . intval($c->BtTarget) . ", "
            . StrSafe_DB($c->BtDistances) . ", " . intval($c->BtDistDef) . ", "
            . intval($c->BtDistMin) . ", " . intval($c->BtDistMax) . ", " . StrSafe_DB($faces) . ")");
    }
    return count($caps);
}

/**
 * Is the competition over? (last day < today). YYYY-MM-DD string comparison, "today"
 * taken in the server's zone (bk_today) — date('Y-m-d') alone gave the UTC date, two
 * hours late every night. A finished competition cannot be registered for, even if its
 * registration window was left open beyond it (organiser's mistake).
 */
function bk_is_finished($toWhenTo)
{
    $d = substr((string) $toWhenTo, 0, 10);   // bytes: an ASCII date
    return $d !== '' && strpos($d, '0000') !== 0 && $d < bk_today();
}

/** Same, from a competition id (reads ToWhenTo). */
function bk_comp_finished($tourId)
{
    $rs = safe_r_sql("SELECT ToWhenTo FROM Tournament WHERE ToId = " . intval($tourId));
    $r = safe_fetch($rs);
    return $r ? bk_is_finished($r->ToWhenTo) : false;
}

/** Saves the setup. $in: values already checked by the caller. */
function bk_comp_save($tourId, $in)
{
    bk_schema();
    $tourId = intval($tourId);

    $dt = function ($v) {
        $v = trim((string) $v);
        return $v === '' ? 'NULL' : StrSafe_DB(str_replace('T', ' ', $v));
    };

    $set = "BcOpen = "        . (empty($in['open']) ? 0 : 1)
        . ", BcOpenFrom = "   . $dt($in['from'] ?? '')
        . ", BcOpenTo = "     . $dt($in['to'] ?? '')
        . ", BcRestrictKind = " . StrSafe_DB((string) ($in['kind'] ?? ''))
        . ", BcRestrictCode = " . StrSafe_DB(trim((string) ($in['code'] ?? '')))
        . ", BcRestrictTo = " . $dt($in['restrict_to'] ?? '')
        . ", BcMaxPerClubPerTarget = " . max(1, min(20, intval($in['max_club'] ?? 2)))
        . ", BcMinClubsPerSession = "  . max(1, min(50, intval($in['min_clubs'] ?? 3)))
        . ", BcShowAssignment = "  . (empty($in['show_assign']) ? 0 : 1)
        . ", BcShowGauges = "      . (empty($in['show_gauges']) ? 0 : 1)
        . ", BcAllowScoresheet = " . (empty($in['scoresheet']) ? 0 : 1)
        . ", BcWishLetter = " . (empty($in['wish_letter']) ? 0 : 1)
        . ", BcWishWith = "   . (empty($in['wish_with'])   ? 0 : 1)
        . ", BcWishFree = "   . (empty($in['wish_free'])   ? 0 : 1)
        . ", BcExcludeStats = " . (empty($in['exclude_stats']) ? 0 : 1)
        . ", BcManualValidation = " . (empty($in['manual_validation']) ? 0 : 1)
        . ", BcFee = " . StrSafe_DB(number_format((float) str_replace(',', '.', (string) ($in['fee'] ?? 0)), 2, '.', ''));

    // Visibility of the mandate: an explicit value (0/1) only when the caller showed the box
    // (three states otherwise — NULL = "not chosen yet").
    if (array_key_exists('show_mandate', $in)) {
        $set .= ", BcShowMandate = " . (empty($in['show_mandate']) ? 0 : 1);
    }
    // ianseo.net link: the organiser no longer types an address, they only decide to show it
    // — the column is a DERIVED value, rebuilt at every save from ToOnlineId. Useful effect: a
    // competition imported again under another online id corrects itself at the first save.
    if (array_key_exists('ianseo_present', $in)) {
        $u = empty($in['show_ianseo']) ? '' : bk_ianseo_url($tourId);
        $set .= ", BcIanseoUrl = " . ($u === '' ? 'NULL' : StrSafe_DB($u));
    }
    // Official ianseo documents offered to the archers (opt-in), written only when the caller
    // showed the boxes (competition.php).
    if (array_key_exists('docs_present', $in)) {
        $set .= ", BcShowProgram = "      . (empty($in['show_program']) ? 0 : 1)
              . ", BcShowParticipants = " . (empty($in['show_participants']) ? 0 : 1)
              . ", BcShowResults = "      . (empty($in['show_results']) ? 0 : 1)
              . ", BcShowDossard = "      . (empty($in['show_dossard']) ? 0 : 1);
    }
    // Satisfaction survey: written only when the caller showed the checkbox (level 3).
    if (array_key_exists('survey', $in)) {
        $set .= ", BcSurvey = " . (empty($in['survey']) ? 0 : 1);
    }
    // Waiting list: same rule, written only when the checkbox was shown (level 3).
    if (array_key_exists('waitlist', $in)) {
        $set .= ", BcWaitlist = " . (empty($in['waitlist']) ? 0 : 1);
    }
    // One registration per archer: same rule.
    if (array_key_exists('single_reg', $in)) {
        $set .= ", BcSingleReg = " . (empty($in['single_reg']) ? 0 : 1);
    }

    // Detailed tariff: JSON already normalised by the caller, or NULL (single fee).
    if (array_key_exists('pricing', $in)) {
        $json = trim((string) $in['pricing']);
        $set .= ", BcPricing = " . ($json === '' ? 'NULL' : StrSafe_DB($json));
    }
    // Payment methods: JSON already built by the caller, or NULL (none).
    if (array_key_exists('payinfo', $in)) {
        $json = trim((string) $in['payinfo']);
        $set .= ", BcPayInfo = " . ($json === '' ? 'NULL' : StrSafe_DB($json));
    }

    safe_w_sql("INSERT INTO BookingCompetitions SET BcTournament = $tourId, $set
        ON DUPLICATE KEY UPDATE $set");
}

/* ------------------------------------------------------------------ */
/* Publication level (bar of 3 levels)                                 */
/* ------------------------------------------------------------------ */

/** Snapshot of the detailed columns of a setup (for BcAdvancedBackup). */
function bk_comp_snapshot($cfg)
{
    $out = array();
    foreach (bk_comp_advanced_cols() as $c) {
        $out[$c] = is_object($cfg) ? ($cfg->$c ?? null) : ($cfg[$c] ?? null);
    }
    return $out;
}

/** Restores the detailed columns from a snapshot. */
function bk_comp_restore($tourId, $snap)
{
    if (!is_array($snap)) return;
    $parts = array();
    foreach (bk_comp_advanced_cols() as $c) {
        if (!array_key_exists($c, $snap)) continue;
        $v = $snap[$c];
        $parts[] = "$c = " . ($v === null ? 'NULL' : StrSafe_DB((string) $v));
    }
    if ($parts) {
        safe_w_sql("UPDATE BookingCompetitions SET " . implode(', ', $parts)
            . " WHERE BcTournament = " . intval($tourId));
    }
}

/**
 * Are the payments and the shop in use? Always on a competition open on this server (levels
 * 2 and 3); on a closed one (level 1, ianseo imports only) when the organiser ticked it.
 */
function bk_comp_payments_on($cfg)
{
    return intval($cfg->BcPublishLevel ?? 1) >= 2 || !empty($cfg->BcPayments);
}

/**
 * Writes settings that are edited outside level 3 (tariffs and payment methods of a closed
 * competition, shop deadline): the columns, and the level-2 snapshot when there is one —
 * otherwise going back to the detailed settings would restore the older values over them.
 */
function bk_comp_set_effective($tourId, $cols)
{
    $tourId = intval($tourId);
    if (!$cols) return;
    safe_w_sql("INSERT INTO BookingCompetitions (BcTournament) VALUES ($tourId) ON DUPLICATE KEY UPDATE BcTournament = BcTournament");
    $parts = array();
    foreach ($cols as $c => $v) $parts[] = "$c = " . ($v === null ? 'NULL' : StrSafe_DB((string) $v));
    safe_w_sql("UPDATE BookingCompetitions SET " . implode(', ', $parts) . " WHERE BcTournament = $tourId");
    $r = safe_fetch(safe_r_sql("SELECT BcAdvancedBackup FROM BookingCompetitions WHERE BcTournament = $tourId"));
    $snap = $r ? json_decode((string) $r->BcAdvancedBackup, true) : null;
    if (!is_array($snap)) return;
    foreach ($cols as $c => $v) if (array_key_exists($c, $snap)) $snap[$c] = $v;
    safe_w_sql("UPDATE BookingCompetitions SET BcAdvancedBackup = "
        . StrSafe_DB(json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . " WHERE BcTournament = $tourId");
}

/**
 * Applies the AUTO values of level 2 (simple publication): open from now to the end date
 * (included), no geographic restriction, automatic validation, base fee only, everything
 * visible and every document. Placement rules take the federal values (2 archers per club per
 * target, 3 clubs per departure). The mandate is made visible (filled from the data — see
 * bk_mandate_visible, level-2 shortcut).
 */
function bk_comp_apply_auto($tourId)
{
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToWhenTo, DATE_FORMAT(" . bk_local_now_sql()
        . ", '%Y-%m-%d %H:%i:%s') AS LocalNow FROM Tournament WHERE ToId = $tourId"));
    // bytes: ASCII dates.
    $end = ($t && substr((string) $t->ToWhenTo, 0, 4) > '0000')
        ? substr((string) $t->ToWhenTo, 0, 10) . ' 23:59:59' : '';
    // "Open from now", in the competition's local time like every other window time.
    $now = ($t && $t->LocalNow) ? (string) $t->LocalNow
        : (new DateTime('now', bk_server_tz()))->format('Y-m-d H:i:s');

    $set = "BcOpen = 1"
        . ", BcOpenFrom = " . StrSafe_DB($now)
        . ", BcOpenTo = " . ($end === '' ? 'NULL' : StrSafe_DB($end))
        . ", BcRestrictKind = '', BcRestrictCode = '', BcRestrictTo = NULL"
        . ", BcManualValidation = 0"
        . ", BcMaxPerClubPerTarget = 2, BcMinClubsPerSession = 3"
        . ", BcShowGauges = 1, BcShowAssignment = 1, BcAllowScoresheet = 1"
        . ", BcShowMandate = 1, BcShowProgram = 1, BcShowParticipants = 1, BcShowResults = 1, BcShowDossard = 1"
        . ", BcSurvey = 1"   // survey always offered at level 2: only level 3 can switch it off
        . ", BcWaitlist = 1" // same for the waiting list
        . ", BcSingleReg = 0" // and the limit to one registration (departure rules: level 3 only)
        // ianseo.net link offered when the competition is published there; nothing to show
        // otherwise. Rebuilt, as everywhere, from ToOnlineId.
        . ", BcIanseoUrl = " . (($u = bk_ianseo_url($tourId)) === '' ? 'NULL' : StrSafe_DB($u))
        . ", BcWishLetter = 1, BcWishWith = 0, BcWishFree = 0"
        . ", BcPricing = NULL, BcExcludeStats = 0";
    safe_w_sql("UPDATE BookingCompetitions SET $set WHERE BcTournament = $tourId");
}

/**
 * Changes the publication level and applies the transition:
 *  - 1: private (BcOpen=0, nothing left on the archer side);
 *  - 2: simple → snapshot of the detailed settings (if not done yet), then the AUTO values;
 *  - 3: detailed → restores the snapshot (if any) and empties it (the detailed settings of
 *       the form are then written by bk_comp_save).
 * The columns stay the EFFECTIVE setup read everywhere; BcAdvancedBackup keeps the detailed
 * settings while at level 2.
 */
function bk_comp_set_level($tourId, $level)
{
    bk_schema();
    $tourId = intval($tourId);
    $level  = in_array(intval($level), array(1, 2, 3), true) ? intval($level) : 1;

    // Make sure the row exists (changing nothing when it already does).
    safe_w_sql("INSERT INTO BookingCompetitions (BcTournament) VALUES ($tourId)
        ON DUPLICATE KEY UPDATE BcTournament = BcTournament");

    $cur = bk_comp_config($tourId);
    $hasBackup = trim((string) ($cur->BcAdvancedBackup ?? '')) !== '';

    if ($level == 2) {
        if (!$hasBackup) {
            $snap = json_encode(bk_comp_snapshot($cur), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            safe_w_sql("UPDATE BookingCompetitions SET BcAdvancedBackup = " . StrSafe_DB($snap)
                . " WHERE BcTournament = $tourId");
        }
        bk_comp_apply_auto($tourId);
        safe_w_sql("UPDATE BookingCompetitions SET BcPublishLevel = 2 WHERE BcTournament = $tourId");
    } elseif ($level == 3) {
        if ($hasBackup) {
            bk_comp_restore($tourId, json_decode($cur->BcAdvancedBackup, true));
            safe_w_sql("UPDATE BookingCompetitions SET BcAdvancedBackup = NULL WHERE BcTournament = $tourId");
        }
        // Level 3 is published, like level 2: BcOpen used to be left at the 0 of level 1 until the
        // form was saved once, so a competition without dates stayed closed although the page
        // says "without a date, registration is open from now on".
        safe_w_sql("UPDATE BookingCompetitions SET BcPublishLevel = 3, BcOpen = 1 WHERE BcTournament = $tourId");
    } else {
        safe_w_sql("UPDATE BookingCompetitions SET BcOpen = 0, BcPublishLevel = 1 WHERE BcTournament = $tourId");
    }
}

/**
 * May an archer of this club register? Returns '' if so, otherwise the reason (displayable).
 *
 * $cfg must carry the computed columns (BcAllOpen), so come from bk_comp_config() or
 * bk_comp_calendar().
 */
function bk_comp_archer_blocked($cfg, $clubCode)
{
    if (!empty($cfg->BcAllOpen)) return '';

    $kind = (string) $cfg->BcRestrictKind;
    // bytes: licence numbers and club codes are ASCII letters and digits
    $code = strtoupper(trim((string) $cfg->BcRestrictCode));
    $club = strtoupper(trim((string) $clubCode));
    if ($code === '') return '';          // incomplete restriction = no restriction

    // bytes, in this whole block: an agreement number and a scope are ASCII codes.
    if (strpbrk($code, '%_') !== false) {
        // Expert pattern (overseas, unusual areas): LIKE → regular expression.
        $re = '';
        // bytes: licence numbers and club codes are ASCII letters and digits
        foreach (str_split($code) as $ch) {
            if ($ch === '%')      $re .= '.*';
            elseif ($ch === '_')  $re .= '.';
            else                  $re .= preg_quote($ch, '/');
        }
        $ok = (bool) preg_match('/^' . $re . '$/', $club);
    } elseif ($kind === 'CD') {
        // Agreement LLDDCCC: the department is in positions 3-4.
        // bytes: licence numbers and club codes are ASCII letters and digits
        $ok = (substr($club, 2, strlen($code)) === $code);
    } else {
        // Region (league): prefix of the agreement number.
        // bytes: licence numbers and club codes are ASCII letters and digits
        $ok = (strncmp($club, $code, strlen($code)) === 0);
    }
    if ($ok) return '';

    return bk_t($kind === 'CD' ? 'BlockedDept' : 'BlockedRegion', $code);
}

/**
 * Competitions shown in the public calendar.
 *
 * Only those PUBLISHED in the calendar (BcOpen=1) — an organiser who published nothing is
 * never there. Competitions OVER are shown as well as the coming ones: it is a real calendar.
 * Registration itself stays guarded apart (window BcIsOpen + competition over + geo/tariff).
 *
 * $filters: ['q' => text, 'from' => date, 'to' => date, 'type' => ToTypeName, 'disc', 'region']
 */
function bk_comp_calendar($filters = array())
{
    bk_schema();
    $w = array('BcOpen = 1');

    if (!empty($filters['q'])) {
        $q = StrSafe_DB('%' . trim($filters['q']) . '%');
        $w[] = "(ToName LIKE $q OR ToWhere LIKE $q OR ToComDescr LIKE $q)";
    }
    if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
        $w[] = "ToWhenTo >= " . StrSafe_DB($filters['from']);
    }
    if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
        $w[] = "ToWhenFrom <= " . StrSafe_DB($filters['to']);
    }
    if (!empty($filters['type'])) {
        $w[] = "ToTypeName = " . StrSafe_DB($filters['type']);
    }
    if (!empty($filters['disc'])) {
        if ($filters['disc'] === 'para') {
            $w[] = "ToTypeSubRule LIKE '%Para%'";
        } else {
            $types = bk_disc_types($filters['disc']);
            $w[] = $types ? "ToType IN (" . implode(',', array_map('intval', $types)) . ")" : "1=0";
        }
    }
    if (!empty($filters['region']) && preg_match('/^[0-9A-Za-z]{2}$/', (string) $filters['region'])) {
        $w[] = "LEFT(ToCommitee, 2) = " . StrSafe_DB($filters['region']);
    }

    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere, ToComDescr, ToCommitee,
                ToWhenFrom, ToWhenTo, ToTypeName, ToType, ToTypeSubRule, ToNumSession,
                BookingCompetitions.*, " . bk_comp_calc_sql() . "
        FROM BookingCompetitions
        INNER JOIN Tournament ON ToId = BcTournament
        WHERE " . implode(' AND ', $w) . "
        ORDER BY ToWhenFrom ASC, ToName ASC");

    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Competition types present in the calendar (for the filter). */
function bk_comp_types()
{
    bk_schema();
    $rs = safe_r_sql("SELECT DISTINCT ToTypeName
        FROM BookingCompetitions
        INNER JOIN Tournament ON ToId = BcTournament
        WHERE BcOpen = 1 AND ToTypeName <> ''
        ORDER BY ToTypeName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r->ToTypeName;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Disciplines (from ToType) & region (prefix of ToCommitee)           */
/* ------------------------------------------------------------------ */

/** Name of the league (region) from its 2-digit code; "Region NN" when unknown. */
function bk_region_name($code)
{
    $known = array('01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '35', '36', '37', '38', '39');
    return in_array((string) $code, $known, true) ? bk_t('Region' . $code) : bk_t('RegionN', $code);
}

/**
 * Is the competition overseas (DROM-TOM)? (from the organiser's agreement number, ToCommitee).
 * Mainland leagues go from 01 to 13 (Corsica = 05); overseas ones are ≥ 30 (35 Reunion,
 * 36 French Guiana, 37 Guadeloupe, 38 New Caledonia, 39 Martinique). Only these competitions
 * escape the federal placement rules (few clubs).
 */
function bk_is_dromtom($committee)
{
    $ll = substr(preg_replace('/\D/', '', (string) $committee), 0, 2);   // bytes: digits only
    return $ll !== '' && intval($ll) >= 30;
}

/**
 * Labels of the disciplines (internal key → displayed name). Official federation names — not
 * to be shortened (calendar, "My registrations", mandate).
 */
function bk_disc_labels()
{
    return array(
        'ext'       => bk_t('DiscExt'),
        'salle'     => bk_t('DiscIndoor'),
        'campagne'  => bk_t('DiscField'),
        'nature'    => bk_t('DiscNature'),
        '3d'        => bk_t('Disc3d'),
        'run'       => bk_t('DiscRun'),
        'beursault' => bk_t('DiscBeursault'),
    );
}

/** ianseo types (ToType) covered by a discipline — to filter the calendar. */
function bk_disc_types($key)
{
    $m = array(
        'ext'       => array(1, 2, 3, 4, 5),      // outdoor / FITA / 70 m
        'salle'     => array(6),                  // indoor 18/25
        'campagne'  => array(7, 9),               // field — ToType 9 = "Type_HF 12+12" (Hunter-Field = field)
        'nature'    => array(),                   // (by label, see bk_comp_discipline)
        '3d'        => array(11),
        'run'       => array(48),                 // Run Archery (TourType 48 in ianseo)
        'beursault' => array(50),
    );
    return $m[$key] ?? array();
}

/**
 * Discipline of a competition from ToType (falling back on the label), and whether it is a
 * Para event (ToTypeSubRule). Returns ['key', 'para'].
 */
function bk_comp_discipline($type, $subrule = '', $typeName = '')
{
    $type = intval($type);
    // ⚠️ ToType 9 = "Type_HF 12+12" (Hunter-Field) = FIELD, not outdoor: every field
    // competition carries this type (checked in the database). Taking it for 'ext' showed an
    // outdoor picture in the calendar AND applied the outdoor face sharing to a course (which
    // has no face but peg colours).
    $byType = array(
        1 => 'ext', 2 => 'ext', 3 => 'ext', 4 => 'ext', 5 => 'ext',
        6 => 'salle', 7 => 'campagne', 9 => 'campagne', 11 => '3d', 48 => 'run', 50 => 'beursault',
    );
    $key = $byType[$type] ?? '';
    if ($key === '') {
        // bytes: matching ASCII keywords in the type name; nothing to fold beyond ASCII.
        $n = strtolower($typeName . ' ' . $subrule);
        if     (strpos($n, 'beursault') !== false) $key = 'beursault';
        elseif (strpos($n, '3d') !== false)        $key = '3d';
        elseif (strpos($n, 'nature') !== false)    $key = 'nature';
        elseif (strpos($n, 'run') !== false)       $key = 'run';
        elseif (strpos($n, 'field') !== false || strpos($n, 'campagne') !== false) $key = 'campagne';
        elseif (strpos($n, 'indoor') !== false || strpos($n, 'salle') !== false)   $key = 'salle';
        else   $key = 'ext';
    }
    return array('key' => $key, 'para' => (bool) preg_match('/para/i', (string) $subrule));
}

/**
 * Disciplines and regions REALLY present in the open calendar (to offer useful filters
 * only). Returns ['disc' => [key => n], 'para' => bool, 'regions' => [code => n]].
 */
function bk_comp_facets()
{
    bk_schema();
    $rs = safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule, ToCommitee
        FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament
        WHERE BcOpen = 1");
    $disc = array(); $para = false; $regions = array();
    while ($r = safe_fetch($rs)) {
        $d = bk_comp_discipline($r->ToType, $r->ToTypeSubRule, $r->ToTypeName);
        $disc[$d['key']] = ($disc[$d['key']] ?? 0) + 1;
        if ($d['para']) $para = true;
        $code = strtoupper(substr((string) $r->ToCommitee, 0, 2));   // bytes: ASCII agreement number
        if (preg_match('/^[0-9]{2}$/', $code)) $regions[$code] = ($regions[$code] ?? 0) + 1;
    }
    ksort($regions);
    return array('disc' => $disc, 'para' => $para, 'regions' => $regions);
}

/** One open competition, for the detail page (null when missing/closed). */
function bk_comp_one($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere, ToComDescr, ToCommitee,
                ToWhenFrom, ToWhenTo, ToTypeName, ToType, ToTypeSubRule, ToNumSession,
                BookingCompetitions.*, " . bk_comp_calc_sql() . "
        FROM BookingCompetitions INNER JOIN Tournament ON ToId = BcTournament
        WHERE BcTournament = $tourId AND BcOpen = 1");
    return safe_fetch($rs) ?: null;
}

/**
 * Discipline → ianseo face number (Common/Images/Targets/{id}.svg, the same pictures as
 * ISK-ng and PlanQualifs). The images come with the ianseo core, so they are always there.
 *
 * Surely identified: 1 = 10-zone colour face (WA), 20 = blue/white face (courses), 8 = animal,
 * 27 = Beursault. Indoor / Nature / Run are defaults to confirm. Can be changed without
 * touching the code: config.local.json → "disc_face": {"salle": 12, ...}.
 */
function bk_disc_face_id($key)
{
    static $map = null;
    if (is_null($map)) {
        $map = array(
            'ext'       => 1,    // 10-zone colour face (World Archery) — outdoor
            'salle'     => 2,    // 18 m face
            'campagne'  => 6,    // field course
            'nature'    => 12,   // animal — framed (see bk_disc_icon) to differ from 3D
            '3d'        => 8,    // animal
            'run'       => 19,   // run archery
            'beursault' => 27,   // Beursault target
        );
        $ov = function_exists('bk_local_config') ? (bk_local_config()['disc_face'] ?? array()) : array();
        foreach ($ov as $k => $v) {
            if (is_numeric($v)) $map[$k] = intval($v);
        }
    }
    return $map[$key] ?? 0;   // 0.svg = "unknown" face (fallback)
}

/**
 * COLOURABLE peg picture, for the FIELD PLAN of courses (field, 3D, nature): these
 * disciplines have no face changing with the category but PEG COLOURS (red, blue, white,
 * pink). Reusable brick — pass the regulation colour of the peg. (The CALENDAR keeps the
 * core's faces.)
 */
function bk_piquet_svg($color = '#0254a8', $size = 22)
{
    $s = intval($size);
    $c = htmlspecialchars($color, ENT_QUOTES);
    // Peg with a rounded top and a pointed base planted in the ground, + highlight.
    return '<svg class="bk-piquet" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" '
         . 'role="img" aria-hidden="true">'
         . '<ellipse cx="12" cy="20.2" rx="6.4" ry="1.8" fill="#d9d2c4"/>'
         . '<path d="M9 5.6a3 3 0 0 1 6 0v10.6l-3 4-3-4z" fill="' . $c . '" stroke="rgba(0,0,0,.28)" stroke-width=".6"/>'
         . '<path d="M10.3 6.4a1.3 1.3 0 0 1 1.2 0v9.2" stroke="rgba(255,255,255,.55)" stroke-width="1" fill="none" stroke-linecap="round"/>'
         . '</svg>';
}

/** Para colour of the federation (outline of the para badges). */
function bk_color_para() { return '#A0006D'; }

/**
 * Federation colour of a discipline (see CHARTE_GRAPHIQUE.md). $official=false (unofficial
 * competition) → charcoal. Para is an OUTLINE added on top (bk_color_para), not a fill.
 */
function bk_disc_color($key, $official = true)
{
    if (!$official) return '#37414a';                     // charcoal — unofficial
    switch ($key) {
        case 'ext': case 'salle':                 return '#3E62FF';   // targets (outdoor + 18 m)
        case 'campagne': case '3d': case 'nature': return '#157A32';   // courses (field/3D/nature)
        case 'beursault':                          return '#D04A0B';   // traditional (Beursault)
        case 'run':                                return '#0F857C';   // run archery
        default:                                   return '#37414a';   // unknown → charcoal
    }
}

/** Colour (hex) of a peg from its name ("Piquet Rouge/Bleu/Blanc/Rose" in the ianseo setup). */
function bk_peg_color($name)
{
    $n = mb_strtolower((string) $name, 'UTF-8');
    if (strpos($n, 'roug') !== false)  return '#d0342c';   // red
    if (strpos($n, 'bleu') !== false)  return '#2b6cb0';   // blue
    if (strpos($n, 'blanc') !== false) return '#c9ced6';   // white (light grey to stay visible)
    if (strpos($n, 'ros') !== false)   return '#e5679a';   // pink
    if (strpos($n, 'noir') !== false)  return '#333a44';   // black
    if (strpos($n, 'jaune') !== false) return '#e8c33a';   // yellow
    return '#7a8b3a';                                       // fallback (olive)
}

/** Picture of a discipline in the calendar: ianseo face image (ratio kept). */
function bk_disc_icon($key, $size = 22)
{
    global $CFG;
    $src = $CFG->ROOT_DIR . 'Common/Images/Targets/' . bk_disc_face_id($key) . '.svg';
    $img = '<img class="bk-disc-img" src="' . htmlspecialchars($src, ENT_QUOTES)
         . '" width="' . intval($size) . '" height="' . intval($size) . '" alt="" loading="lazy">';
    // Nature and 3D share animal faces: a rectangular frame marks nature.
    if ($key === 'nature') return '<span class="bk-disc-frame">' . $img . '</span>';
    return $img;
}

/** "Para" picture (wheelchair), small, on top of a tile. */
function bk_disc_icon_para($size = 16)
{
    return '<svg width="' . intval($size) . '" height="' . intval($size) . '" viewBox="0 0 24 24" '
        . 'fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">'
        . '<circle cx="9" cy="17.5" r="4"/><path d="M9 6.5v6.5h6l2.2 4.5"/>'
        . '<circle cx="10" cy="4" r="1.6" fill="currentColor" stroke="none"/></svg>';
}

/**
 * Places per departure above which the organiser is warned (admin/competition.php). To
 * check a target number, the core builds one UNION branch per place of the departure
 * (createAvailableTargetSQL): 9 999 targets × 8 = 80 000 branches, up to 10 minutes per
 * archer on a MySQL 8 server. Same value as AUT_BIG_SESSION_PLACES (AUTH health-lib.php).
 */
if (!defined('BK_BIG_SESSION_PLACES')) define('BK_BIG_SESSION_PLACES', 5000);

/**
 * Capacity of a competition, departure by departure, read from the ianseo setup (Session)
 * and the real occupation (Qualifications).
 *
 * ⚠️ Qualifications has NO competition column: the count MUST go through a join on Entries
 * (EnTournament), otherwise it adds up the archers of every competition of the database.
 */
function bk_comp_sessions($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesAth4Target,
                SesFirstTarget, SesDtStart,
                (SELECT CONCAT(DiDay, ' ', DiStart) FROM DistanceInformation
                   WHERE DiTournament = $tourId AND DiSession = SesOrder
                     AND DiType = 'Q' AND DiDistance = 1 LIMIT 1) AS SesStart,
                (SesTar4Session * SesAth4Target) AS Places,
                (SELECT COUNT(*)
                   FROM Qualifications
                   INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
                  WHERE QuSession = SesOrder) AS Pris
        FROM Session
        WHERE SesTournament = $tourId AND SesType = 'Q'
        ORDER BY SesOrder");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Start date/time of a departure: the time of distance 1 (DistanceInformation, typed in
 * ManSessions_kiss.php) first, else SesDtStart. Returns 'YYYY-MM-DD HH:MM:SS' or ''.
 */
function bk_session_start($s)
{
    foreach (array($s->SesStart ?? '', $s->SesDtStart ?? '') as $v) {
        $v = trim((string) $v);
        if ($v !== '' && substr($v, 0, 10) !== '0000-00-00') return $v;   // bytes: ASCII date
    }
    return '';
}

/**
 * Format and duration of a departure, read from DistanceInformation. The duration is the
 * REAL value typed by the organiser (DistanceInformation.DiDuration, in minutes, carried by
 * distance 1 but standing for the whole departure — see Scheduler / ManSessions). NEVER
 * estimated: 'min'=0 when not typed. 'fmt' = ends×arrows (information).
 * Returns ['ends' => int, 'fmt' => '10×3 + 10×3', 'min' => int].
 */
function bk_session_format($tourId, $sessionOrder)
{
    $rs = safe_r_sql("SELECT DiDistance, DiEnds, DiArrows, DiDuration FROM DistanceInformation
        WHERE DiTournament = " . intval($tourId) . " AND DiSession = " . intval($sessionOrder) . "
          AND DiType = 'Q' ORDER BY DiDistance");
    $ends = 0; $fmt = array(); $min = 0;
    while ($r = safe_fetch($rs)) {
        $e = intval($r->DiEnds); $a = intval($r->DiArrows);
        if ($e > 0 && $a > 0) { $ends += $e; $fmt[] = $e . '×' . $a; }
        if (intval($r->DiDistance) === 1) $min = intval($r->DiDuration);   // duration of the whole departure
    }
    return array('ends' => $ends, 'fmt' => implode(' + ', $fmt), 'min' => max(0, $min));
}

/** Duration as 'Xh', 'XhYY' or 'Z min'. '' when 0. */
function bk_dur_hm($min)
{
    $min = intval($min);
    if ($min <= 0) return '';
    $h = intdiv($min, 60); $m = $min % 60;
    if ($h && $m) return $h . 'h' . sprintf('%02d', $m);
    if ($h) return $h . 'h';
    return $m . ' min';
}
