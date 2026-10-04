<?php
/**
 * lib/survey.php — archers' satisfaction survey, after the competition.
 *
 * Model: the questionnaire the federation uses for its national championships — two
 * sections, 1 to 5 ratings, free texts, nothing mandatory, under two minutes.
 *
 * - WHO: archers with a qualification score in the competition, whether they registered
 *   online or were entered by the organiser (matched on the licence, Entries.EnCode).
 * - WHEN: from the day after the last day, for BK_SURVEY_DAYS days.
 * - WHERE: competitions published on this server (level 2 or 3) with the survey on:
 *   always at level 2, a checkbox at level 3 (BcSurvey).
 * - ONE ANSWER per archer and per competition, for good. While the survey is open the
 *   answer row carries the licence (UNIQUE key: sending again UPDATES it). Who has
 *   answered is also written to BK_SurveyVoters, apart from the answers, and never
 *   detached: that roll still refuses a second answer once the answers are anonymised,
 *   if the dates are changed and the window reopens, or after a re-import.
 * - ANONYMITY: the organiser only sees aggregated figures and comments, never who wrote
 *   what. Once the survey is closed, the licence is detached from the answer every night
 *   (bk_survey_anonymise); the roll alone cannot be linked back to an answer.
 */

// Loaded on its own by the nightly purge (aut_log_purge): bring the clock along.
require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/lang.php';

if (!defined('BK_SURVEY_DAYS')) define('BK_SURVEY_DAYS', 30);
if (!defined('BK_SURVEY_TEXT_MAX')) define('BK_SURVEY_TEXT_MAX', 2000);

/** The questionnaire: sections, 1-5 ratings and free texts, mapped to BK_Surveys columns. */
function bk_survey_questions()
{
    $intro = bk_t('SvIntroThemes');
    return array(
        'org' => array(
            'title' => bk_t('SvOrgTitle'),
            'intro' => $intro,
            'items' => array(
                array('col' => 'BqWelcome',    'label' => bk_t('SvWelcome')),
                array('col' => 'BqAccess',     'label' => bk_t('SvAccess')),
                array('col' => 'BqBar',        'label' => bk_t('SvBar')),
                array('col' => 'BqBarValue',   'label' => bk_t('SvBarValue')),
                array('col' => 'BqVenue',      'label' => bk_t('SvVenue'), 'hint' => bk_t('SvVenueHint')),
                array('col' => 'BqFacilities', 'label' => bk_t('SvFacilities'), 'hint' => bk_t('SvFacilitiesHint')),
            ),
            'texts' => array(
                array('col' => 'BqOrgComment', 'label' => bk_t('SvDetail')),
                array('col' => 'BqOrgIdeas',   'label' => bk_t('SvIdeas')),
            ),
        ),
        'comp' => array(
            'title' => bk_t('SvCompTitle'),
            'intro' => $intro,
            'items' => array(
                array('col' => 'BqDuration',  'label' => bk_t('SvDuration'), 'hint' => bk_t('SvDurationHint'), 'low' => bk_t('SvTooLong')),
                array('col' => 'BqSchedule',  'label' => bk_t('SvSchedule'), 'low' => bk_t('SvTooLong')),
                array('col' => 'BqResults',   'label' => bk_t('SvResults'), 'hint' => bk_t('SvResultsHint')),
                array('col' => 'BqAnimation', 'label' => bk_t('SvAnimation'), 'hint' => bk_t('SvAnimationHint')),
            ),
            'texts' => array(
                array('col' => 'BqCompComment', 'label' => bk_t('SvDetail')),
            ),
        ),
    );
}

/** Rating columns and text columns, flattened. */
function bk_survey_cols()
{
    $r = array(); $t = array();
    foreach (bk_survey_questions() as $s) {
        foreach ($s['items'] as $i) $r[] = $i['col'];
        foreach ($s['texts'] as $i) $t[] = $i['col'];
    }
    return array('ratings' => $r, 'texts' => $t);
}

/**
 * SQL condition: the survey of the Tournament row is open today — "today" being the local
 * date OF THAT COMPETITION (lib/clock.php), identical on organiser and archer pages.
 */
function bk_survey_open_sql()
{
    $today = bk_local_today_sql('ToTimeZone');
    return "$today > ToWhenTo AND $today <= DATE_ADD(ToWhenTo, INTERVAL " . intval(BK_SURVEY_DAYS) . " DAY)";
}

/** SQL condition: the licence has a qualification score in the Tournament row. */
function bk_survey_participant_sql($licence)
{
    return "EXISTS (SELECT 1 FROM Entries INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = ToId AND EnCode = " . StrSafe_DB($licence) . " AND QuScore > 0)";
}

/**
 * Surveys currently open for this archer, indexed by ToId, newest first:
 * ToId => (object) {ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo, CloseOn, Answered}.
 * One query for all competitions (never one per competition).
 */
function bk_survey_open_for($licence)
{
    $licence = bk_clean_licence($licence);
    $out = array();
    if ($licence === '') return $out;
    $q = safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo,
            DATE_ADD(ToWhenTo, INTERVAL " . intval(BK_SURVEY_DAYS) . " DAY) AS CloseOn,
            (SELECT COUNT(*) FROM BK_SurveyVoters WHERE BvTournament = ToId AND BvLicence = " . StrSafe_DB($licence) . ") AS Answered
        FROM Tournament
        INNER JOIN BK_Competitions ON BcTournament = ToId
        WHERE BcPublishLevel >= 2 AND BcSurvey = 1
          AND " . bk_survey_open_sql() . "
          AND " . bk_survey_participant_sql($licence) . "
        ORDER BY ToWhenTo DESC, ToId DESC", false, true);
    while ($q && ($r = safe_fetch($q))) $out[intval($r->ToId)] = $r;
    return $out;
}

/**
 * Can this archer answer the survey of this competition? Returns
 * ['ok' => bool, 'reason' => '', 'comp' => row|null, 'answer' => row|null].
 * Reasons: unknown, off (not published or switched off), not_yet, closed, not_participant,
 * already (answered before, answer since detached: no second answer, ever).
 */
function bk_survey_access($tourId, $licence)
{
    $tourId = intval($tourId);
    $licence = bk_clean_licence($licence);
    $res = array('ok' => false, 'reason' => 'unknown', 'comp' => null, 'answer' => null);
    $today = bk_local_today_sql('ToTimeZone');
    // OpenOn = first open day, CloseOn = LAST open day, ClosedOn = first closed day.
    $comp = safe_fetch(safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo,
            DATE_ADD(ToWhenTo, INTERVAL 1 DAY) AS OpenOn,
            DATE_ADD(ToWhenTo, INTERVAL " . intval(BK_SURVEY_DAYS) . " DAY) AS CloseOn,
            DATE_ADD(ToWhenTo, INTERVAL " . (intval(BK_SURVEY_DAYS) + 1) . " DAY) AS ClosedOn,
            COALESCE(BcPublishLevel, 1) AS Level, COALESCE(BcSurvey, 1) AS SurveyOn,
            ($today > ToWhenTo) AS Started,
            ($today > DATE_ADD(ToWhenTo, INTERVAL " . intval(BK_SURVEY_DAYS) . " DAY)) AS Ended,
            " . bk_survey_participant_sql($licence) . " AS Participant
        FROM Tournament LEFT JOIN BK_Competitions ON BcTournament = ToId
        WHERE ToId = $tourId"));
    if (!$comp) return $res;
    $res['comp'] = $comp;
    if (intval($comp->Level) < 2 || !intval($comp->SurveyOn)) { $res['reason'] = 'off'; return $res; }
    if (!intval($comp->Started))                                { $res['reason'] = 'not_yet'; return $res; }
    if (intval($comp->Ended))                                   { $res['reason'] = 'closed'; return $res; }
    if ($licence === '' || !intval($comp->Participant))         { $res['reason'] = 'not_participant'; return $res; }
    $res['answer'] = safe_fetch(safe_r_sql("SELECT * FROM BK_Surveys
        WHERE BqTournament = $tourId AND BqLicence = " . StrSafe_DB($licence))) ?: null;
    if (!$res['answer'] && safe_fetch(safe_r_sql("SELECT 1 AS x FROM BK_SurveyVoters
            WHERE BvTournament = $tourId AND BvLicence = " . StrSafe_DB($licence)))) {
        $res['reason'] = 'already';
        return $res;
    }
    $res['ok'] = true;
    $res['reason'] = '';
    return $res;
}

/**
 * Saves (or updates) the archer's answer from a POST array, and records in the roll that
 * this archer has answered. An answer with nothing in it is removed rather than stored,
 * and the archer leaves the roll (nothing was given). Only called after
 * bk_survey_access() said ok. Returns 'saved' or 'empty'.
 */
function bk_survey_save($tourId, $licence, $post)
{
    $tourId = intval($tourId);
    $licence = bk_clean_licence($licence);
    $cols = bk_survey_cols();
    $set = array(); $any = false;
    foreach ($cols['ratings'] as $c) {
        $v = intval($post[$c] ?? 0);
        $ok = ($v >= 1 && $v <= 5);
        $any = $any || $ok;
        $set[$c] = $ok ? (string) $v : 'NULL';
    }
    foreach ($cols['texts'] as $c) {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($post[$c] ?? '')));
        $v = mb_substr($v, 0, BK_SURVEY_TEXT_MAX);
        $any = $any || ($v !== '');
        $set[$c] = ($v === '') ? 'NULL' : StrSafe_DB($v);
    }
    $lic = StrSafe_DB($licence);
    if (!$any) {
        safe_w_sql("DELETE FROM BK_Surveys WHERE BqTournament = $tourId AND BqLicence = $lic");
        safe_w_sql("DELETE FROM BK_SurveyVoters WHERE BvTournament = $tourId AND BvLicence = $lic");
        return 'empty';
    }
    $names = implode(', ', array_keys($set));
    $values = implode(', ', array_values($set));
    $upd = array();
    foreach ($set as $c => $v) $upd[] = "$c = $v";
    safe_w_sql("INSERT INTO BK_Surveys (BqTournament, BqLicence, $names)
        VALUES ($tourId, $lic, $values)
        ON DUPLICATE KEY UPDATE " . implode(', ', $upd) . ", BqUpdated = NOW()");
    safe_w_sql("INSERT IGNORE INTO BK_SurveyVoters (BvTournament, BvLicence) VALUES ($tourId, $lic)");
    return 'saved';
}

/** Number of archers with a qualification score (the people who can answer). */
function bk_survey_participants($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT COUNT(DISTINCT EnCode) AS n FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = " . intval($tourId) . " AND EnCode <> '' AND QuScore > 0"));
    return $r ? intval($r->n) : 0;
}

/**
 * Aggregated results for the organiser: ['answers' => n, 'q' => [col => ['n', 'avg',
 * 'dist' => [1..5 => count]]], 'texts' => [col => [text, …]]]. No identity anywhere.
 */
function bk_survey_results($tourId)
{
    $tourId = intval($tourId);
    $cols = bk_survey_cols();
    $sel = array('COUNT(*) AS answers');
    foreach ($cols['ratings'] as $c) {
        $sel[] = "COUNT($c) AS {$c}_n";
        $sel[] = "AVG($c) AS {$c}_avg";
        for ($v = 1; $v <= 5; $v++) $sel[] = "SUM($c = $v) AS {$c}_$v";
    }
    $r = safe_fetch(safe_r_sql("SELECT " . implode(', ', $sel) . " FROM BK_Surveys WHERE BqTournament = $tourId"));
    $out = array('answers' => $r ? intval($r->answers) : 0, 'q' => array(), 'texts' => array());
    foreach ($cols['ratings'] as $c) {
        $dist = array();
        for ($v = 1; $v <= 5; $v++) $dist[$v] = $r ? intval($r->{$c . '_' . $v}) : 0;
        $out['q'][$c] = array('n' => $r ? intval($r->{$c . '_n'}) : 0,
            'avg' => ($r && $r->{$c . '_avg'} !== null) ? floatval($r->{$c . '_avg'}) : null, 'dist' => $dist);
    }
    foreach ($cols['texts'] as $c) {
        $out['texts'][$c] = array();
        $q = safe_r_sql("SELECT $c AS t FROM BK_Surveys WHERE BqTournament = $tourId AND $c IS NOT NULL AND $c <> ''
            ORDER BY BqId DESC");
        while ($q && ($x = safe_fetch($q))) $out['texts'][$c][] = $x->t;
    }
    return $out;
}

/**
 * Server benchmark: per question, the average of the OTHER competitions' averages (each
 * competition weighs the same, whatever its size). ['comps' => n, 'avg' => [col => float|null]].
 */
function bk_survey_server_avg($excludeTourId)
{
    $cols = bk_survey_cols();
    $inner = array('BqTournament');
    $outer = array('COUNT(*) AS comps');
    foreach ($cols['ratings'] as $c) {
        $inner[] = "AVG($c) AS $c";
        $outer[] = "AVG($c) AS $c";
    }
    // Derived table alias required by MySQL; Tournament joined to ignore deleted competitions.
    $r = safe_fetch(safe_r_sql("SELECT " . implode(', ', $outer) . " FROM (
            SELECT " . implode(', ', $inner) . " FROM BK_Surveys
            INNER JOIN Tournament ON ToId = BqTournament
            WHERE BqTournament <> " . intval($excludeTourId) . "
            GROUP BY BqTournament) AS per_comp"));
    $out = array('comps' => $r ? intval($r->comps) : 0, 'avg' => array());
    foreach ($cols['ratings'] as $c) $out['avg'][$c] = ($r && $r->$c !== null) ? floatval($r->$c) : null;
    return $out;
}

/**
 * Detaches the licence from the answers of closed surveys (and of deleted competitions):
 * once the survey is closed, the answer needs it no more. BK_SurveyVoters is left alone:
 * it keeps refusing a second answer. Guarded: never fatal if the table does not exist
 * yet. Called by the nightly purge (aut_log_purge).
 */
function bk_survey_anonymise()
{
    $t = safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BK_Surveys'", false, true));
    if (!$t) return;
    safe_w_sql("UPDATE BK_Surveys LEFT JOIN Tournament ON ToId = BqTournament
        SET BqLicence = CONCAT('#', BqId)
        WHERE BqLicence NOT LIKE '#%'
          AND (ToId IS NULL OR " . bk_local_today_sql('ToTimeZone') . " > DATE_ADD(ToWhenTo, INTERVAL " . intval(BK_SURVEY_DAYS) . " DAY))");
}
