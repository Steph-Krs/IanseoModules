<?php
/**
 * lib/mandate.php — the competition's mandate (document of the organiser).
 *
 * On screen it is HTML (bk_mandate_document); on paper, the same content goes through the core's
 * PDF classes (lib/mandate-pdf.php). Its content is FILLED IN
 * AUTOMATICALLY from the competition (name, dates, place, departures, categories, tariffs,
 * means of payment); the organiser only adds free text blocks, a template and a colour. No
 * free layout, no word processor — the frame stays simple and consistent.
 *
 * The logos (top left / top right / bottom) are those already uploaded in ianseo
 * (Tournament/ManLogo.php: columns ToImgL / ToImgR / ToImgB), served by Common/TourLogo.php —
 * never asked again by the module.
 */

if (defined('BK_MANDATE_LOADED')) return;
define('BK_MANDATE_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/ui.php';

/** Available templates: key => readable label. */
function bk_mandate_templates()
{
    return array(
        'sobre'   => bk_t('MnTplSobre'),
        'moderne' => bk_t('MnTplModerne'),
        'bandeau' => bk_t('MnTplBandeau'),
        'encadre' => bk_t('MnTplEncadre'),
        'ligne'   => bk_t('MnTplLigne'),
        'compact' => bk_t('MnTplCompact'),
    );
}

/**
 * Templates of the SHAREABLE PICTURE (share.php), distinct from the mandate's but driven by
 * the SAME colour (common identity). key => label. The drawing (canvas) lives in share.php —
 * to add or change a template: add a key here AND its case in the switch of share.php.
 */
function bk_share_templates()
{
    return array(
        'bandeau' => bk_t('ShTplBandeau'),
        'degrade' => bk_t('ShTplDegrade'),
        'encadre' => bk_t('ShTplEncadre'),
        'moitie'  => bk_t('ShTplMoitie'),
        'epure'   => bk_t('ShTplEpure'),
    );
}

/** Free text blocks offered (key => label). An empty block is not shown. */
function bk_mandate_sections()
{
    return array(
        'intro'    => bk_t('MnSecIntro'),
        'access'   => bk_t('MnSecAccess'),
        'lodging'  => bk_t('MnSecLodging'),
        'catering' => bk_t('MnSecCatering'),
        'awards'   => bk_t('MnSecAwards'),
        'contact'  => bk_t('MnSecContact'),
        'misc'     => bk_t('MnSecMisc'),
    );
}

/** Automatic sections that can be hidden (key => label). */
function bk_mandate_auto_sections()
{
    return array(
        'sessions'   => bk_t('MnSessions'),
        'categories' => bk_t('MnCategories'),
        'fees'       => bk_t('MnFees'),
        'payment'    => bk_t('PayMeansTitle'),
        'shop'       => bk_t('Shop'),
        'register'   => bk_t('Brand'),
    );
}

/**
 * Settings of the mandate (from the BcMandate JSON column), with sound defaults: a competition
 * never set up still gives a correct mandate.
 */
function bk_mandate_get($cfg)
{
    // Default: every automatic section shown (including 'shop' — the key MUST come from
    // bk_mandate_auto_sections(), otherwise a section added later would never be read back
    // from the JSON — real bug on the shop).
    $show = array();
    foreach (bk_mandate_auto_sections() as $k => $_l) $show[$k] = 1;
    $d = array(
        'template'       => 'sobre',
        'share_template' => 'bandeau',                  // template of the shareable picture (share.php)
        'color'          => '#0254a8',                  // default blue (shared by mandate and picture)
        'logos'          => array('L' => 1, 'R' => 1, 'B' => 1),
        'show'           => $show,
        'blocks'         => array(),                    // section key => free text
    );
    $raw = is_object($cfg) ? ($cfg->BcMandate ?? null) : (is_array($cfg) ? ($cfg['BcMandate'] ?? null) : null);
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (!empty($j['template']) && array_key_exists($j['template'], bk_mandate_templates())) {
                $d['template'] = $j['template'];
            }
            if (!empty($j['share_template']) && array_key_exists($j['share_template'], bk_share_templates())) {
                $d['share_template'] = $j['share_template'];
            }
            if (!empty($j['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $j['color'])) {
                // bytes: a #rrggbb colour is ASCII
                $d['color'] = strtolower($j['color']);
            }
            foreach (array('L', 'R', 'B') as $k) {
                $d['logos'][$k] = empty($j['logos'][$k]) ? 0 : 1;
            }
            foreach ($d['show'] as $k => $_v) {
                $d['show'][$k] = isset($j['show'][$k]) ? (empty($j['show'][$k]) ? 0 : 1) : 1;
            }
            if (!empty($j['blocks']) && is_array($j['blocks'])) {
                foreach (bk_mandate_sections() as $sk => $_l) {
                    $t = trim((string) ($j['blocks'][$sk] ?? ''));
                    if ($t !== '') $d['blocks'][$sk] = $t;
                }
            }
        }
    }
    return $d;
}

/** Builds the settings from a POST. Fields are validated and limited here. */
function bk_mandate_from_post($post)
{
    $tpl = (string) ($post['template'] ?? 'sobre');
    if (!array_key_exists($tpl, bk_mandate_templates())) $tpl = 'sobre';

    $stpl = (string) ($post['share_template'] ?? 'bandeau');
    if (!array_key_exists($stpl, bk_share_templates())) $stpl = 'bandeau';

    // bytes: a #rrggbb colour is ASCII
    $color = strtolower((string) ($post['color'] ?? '#0254a8'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#0254a8';

    $logos = array();
    foreach (array('L', 'R', 'B') as $k) $logos[$k] = empty($post['logo_' . $k]) ? 0 : 1;

    $show = array();
    foreach (bk_mandate_auto_sections() as $k => $_l) $show[$k] = empty($post['show_' . $k]) ? 0 : 1;

    $blocks = array();
    foreach (bk_mandate_sections() as $sk => $_l) {
        $t = trim((string) ($post['block_' . $sk] ?? ''));
        if ($t !== '') $blocks[$sk] = mb_substr($t, 0, 4000);
    }
    return array('template' => $tpl, 'share_template' => $stpl, 'color' => $color,
                 'logos' => $logos, 'show' => $show, 'blocks' => $blocks);
}

/** Saves the mandate's settings (JSON in BcMandate), leaving the rest of the row alone. */
function bk_mandate_save($tourId, $data)
{
    bk_schema();
    $tourId = intval($tourId);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $set  = "BcMandate = " . StrSafe_DB($json);
    safe_w_sql("INSERT INTO BookingCompetitions SET BcTournament = $tourId, $set
        ON DUPLICATE KEY UPDATE $set");
}

/**
 * Palette derived from a primary colour: light shade (backgrounds), dark shade (titles) and a
 * text colour readable ON the primary (from its luminance).
 */
function bk_mandate_palette($hex)
{
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $hex)) $hex = '#0254a8';
    // bytes: a #rrggbb colour is ASCII
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $mix = function ($c, $target, $ratio) { return (int) round($c + ($target - $c) * $ratio); };
    $light = sprintf('#%02x%02x%02x', $mix($r, 255, 0.90), $mix($g, 255, 0.90), $mix($b, 255, 0.90));
    $dark  = sprintf('#%02x%02x%02x', $mix($r, 0, 0.30), $mix($g, 0, 0.30), $mix($b, 0, 0.30));
    $lum   = 0.299 * $r + 0.587 * $g + 0.114 * $b;   // contrast of the text on the primary
    // bytes: a #rrggbb colour is ASCII
    return array('primary' => strtolower($hex), 'light' => $light, 'dark' => $dark,
                 'on' => ($lum > 150 ? '#20263d' : '#ffffff'));
}

/**
 * Automatic data of the mandate, from the competition. null when the competition does not
 * exist. Does NOT assume the registration is open (the organiser may prepare the mandate
 * before opening it).
 */
function bk_mandate_data($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere, ToComDescr, ToCommitee,
                ToWhenFrom, ToWhenTo, ToType, ToTypeName, ToTypeSubRule,
                LENGTH(ToImgL) AS HasL, LENGTH(ToImgR) AS HasR, LENGTH(ToImgB) AS HasB
        FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return null;

    $cfg  = bk_comp_config($tourId);
    $disc = bk_comp_discipline($t->ToType, $t->ToTypeSubRule, $t->ToTypeName);
    $labels = bk_disc_labels();

    $divisions = array();
    $rs = safe_r_sql("SELECT DivDescription FROM Divisions
        WHERE DivTournament = $tourId AND DivAthlete = 1 ORDER BY DivViewOrder, DivId");
    while ($r = safe_fetch($rs)) $divisions[] = $r->DivDescription;

    $classes = array();
    $rs = safe_r_sql("SELECT ClDescription FROM Classes
        WHERE ClTournament = $tourId AND ClAthlete = 1 ORDER BY ClAgeFrom, ClId");
    while ($r = safe_fetch($rs)) $classes[] = $r->ClDescription;

    $pricing = bk_pricing_get($cfg);

    return array(
        'tour'        => $t,
        'cfg'         => $cfg,
        'disc'        => $disc,
        'discLabel'   => $labels[$disc['key']] ?? $disc['key'],
        // bytes: ToCommitee is an ASCII approval number
        'region'      => bk_region_name(substr((string) $t->ToCommitee, 0, 2)),
        'sessions'    => bk_comp_sessions($tourId),
        'divisions'   => $divisions,
        'classes'     => $classes,
        'pay'         => bk_payinfo_get($cfg),
        'fee'         => (float) $cfg->BcFee,
        'feeAdvanced' => bk_pricing_is_advanced($pricing),
        'pricing'     => $pricing,
        'deadline'    => $cfg->BcOpenTo ?? null,
        'shop'        => bk_shop_has_items($tourId) ? bk_shop_items($tourId, true) : array(),
    );
}

/**
 * May the archers read the mandate? Three states on BcShowMandate:
 *  - NULL (never chosen) → visible as soon as a mandate exists (the requested default);
 *  - 1                   → visible (when a mandate exists);
 *  - 0                   → hidden.
 * Never visible without a mandate (nothing to show).
 */
function bk_mandate_visible($cfg)
{
    // Level 2 (simple publication): the mandate is filled from the data and always published,
    // even without explicit settings (BcMandate may be empty).
    $lvl = intval(is_object($cfg) ? ($cfg->BcPublishLevel ?? 0) : (is_array($cfg) ? ($cfg['BcPublishLevel'] ?? 0) : 0));
    if ($lvl == 2) return true;

    $raw  = is_object($cfg) ? ($cfg->BcMandate ?? null) : (is_array($cfg) ? ($cfg['BcMandate'] ?? null) : null);
    $has  = trim((string) $raw) !== '';
    $flag = is_object($cfg) ? ($cfg->BcShowMandate ?? null) : (is_array($cfg) ? ($cfg['BcShowMandate'] ?? null) : null);
    if ($flag === null || $flag === '') return $has;
    return $has && intval($flag) === 1;
}

/**
 * Official ianseo documents that can be relayed (the organiser opts in). Each entry:
 * key => ['label', 'icon', 'flag' (BookingCompetitions column), 'script' (core generator), 'params'
 * (controlled GET parameters)]. The scripts are PDF entry points of the core (Prn*.php) that
 * work on the current competition session.
 */
function bk_doc_defs()
{
    return array(
        'program' => array(
            'label' => bk_t('DocProgram'), 'icon' => '📋',
            'flag' => 'BcShowProgram', 'has' => 'bk_has_program',
            'script' => 'Scheduler/PrnScheduler.php',
            'params' => array('Finalists' => '1', 'PageBreaks' => ''),
        ),
        'participants' => array(
            'label' => bk_t('DocByClub'), 'icon' => '👥',
            'flag' => 'BcShowParticipants', 'has' => 'bk_has_participants',
            'script' => 'Partecipants/PrnCountry.php', 'params' => array(),
        ),
        'participants_target' => array(
            'label' => bk_t('DocByTarget'), 'icon' => '🎯',
            'flag' => 'BcShowParticipants', 'has' => 'bk_has_placements',
            'script' => 'Partecipants/PrnSession.php', 'params' => array(),
        ),
        // "Results" (BcShowResults box) gives several buttons depending on how far the
        // competition went; each one only shows with its content.
        'qualifications' => array(
            'label' => bk_t('DocResQual'), 'icon' => '🏅',
            'flag' => 'BcShowResults', 'has' => 'bk_has_results',
            'script' => 'Qualification/PrnCompleteAbs.php', 'params' => array(),
        ),
        // 'events' => 'ind'/'team': the relay passes the explicit LIST of events (bk_final_events)
        // instead of "." (all) — otherwise the generator also prints the categories WITHOUT
        // matches. Same as the select id="IndividualEvents" / "TeamEvents" of Final/PrintOut.php
        // (EvFinalFirstPhase != 0), without the "all" option.
        'duels_ind' => array(
            'label' => bk_t('DocResInd'), 'icon' => '🏹',
            'flag' => 'BcShowResults', 'has' => 'bk_has_ind_finals',
            'script' => 'Final/Individual/PrnIndividual.php', 'events' => 'ind',
            'params' => array('IncRankings' => '1', 'IncBrackets' => '1',
                              'ShowTargetNo' => '1', 'ShowSchedule' => '1', 'OrisABD' => 'AB'),
        ),
        'duels_team' => array(
            'label' => bk_t('DocResTeam'), 'icon' => '🏆',
            'flag' => 'BcShowResults', 'has' => 'bk_has_team_finals',
            'script' => 'Final/Team/PrnTeam.php', 'events' => 'team',
            'params' => array('IncRankings' => '1', 'IncBrackets' => '1',
                              'ShowTargetNo' => '1', 'ShowSchedule' => '1', 'OrisABD' => 'AB'),
        ),
    );
}

/** At least one participant on this competition? */
function bk_has_participants($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Entries
        WHERE EnTournament = " . intval($tourId) . " AND EnAthlete = 1 LIMIT 1"));
    return (bool) $r;
}

/** At least one qualification result (score entered)? */
function bk_has_results($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1
        FROM Qualifications INNER JOIN Entries ON EnId = QuId
        WHERE EnTournament = " . intval($tourId) . " AND QuScore > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Is there a programme to show? Same logic as the core's scheduler
 * (Common/Lib/Fun_Scheduler.php): either an item TYPED in the scheduler (Scheduler table), or a
 * TIMED departure (DistanceInformation with a date and a start or warm-up time).
 */
function bk_has_program($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Scheduler WHERE SchTournament = $tourId LIMIT 1"));
    if ($r) return true;
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM DistanceInformation
        WHERE DiTournament = $tourId AND DiDay > 0 AND (DiStart > 0 OR DiWarmStart > 0) LIMIT 1"));
    return (bool) $r;
}

/** At least one archer on a target? (for the participants BY TARGET list) */
function bk_has_placements($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1
        FROM Qualifications INNER JOIN Entries ON EnId = QuId
        WHERE EnTournament = " . intval($tourId) . " AND QuTarget > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Were INDIVIDUAL matches REALLY generated? Finals holds the bracket STRUCTURE (rows with
 * FinAthlete=0) as soon as the events exist; a real match only exists once an archer is in it →
 * FinAthlete>0 required (real bug: competition 722 had 102 empty Finals rows, the button showed).
 */
function bk_has_ind_finals($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Finals
        WHERE FinTournament = " . intval($tourId) . " AND FinAthlete > 0 LIMIT 1"));
    return (bool) $r;
}

/** TEAM matches really generated? (TeamFinals with a team in it, TfTeam>0) */
function bk_has_team_finals($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM TeamFinals
        WHERE TfTournament = " . intval($tourId) . " AND TfTeam > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Events (EvCode) offered by the core's print selector of the matches (Final/PrintOut.php:
 * select id="IndividualEvents"/"TeamEvents") — those with a final bracket
 * (EvFinalFirstPhase != 0), without the "all" option ("."). Passing them explicitly keeps
 * the generator from printing the categories without matches.
 */
function bk_final_events($tourId, $team)
{
    $tourId = intval($tourId);
    $t = $team ? '1' : '0';
    $out = array();
    $rs = safe_r_sql("SELECT EvCode FROM Events
        WHERE EvTeamEvent = '$t' AND EvTournament = $tourId
          AND EvFinalFirstPhase <> 0 AND EvCodeParent = '' ORDER BY EvProgr");
    while ($r = safe_fetch($rs)) $out[] = $r->EvCode;
    return $out;
}

/**
 * Documents of the competition the archers may read. Returns a list of
 * ['key', 'label', 'icon', 'url', 'external'?]: mandate (when visible) + ianseo.net link (when
 * set) + the official ianseo documents whose box the organiser ticked.
 */
function bk_docs_list($cfg, $tourId)
{
    $tourId = intval($tourId);
    $out = array();
    if (bk_mandate_visible($cfg)) {
        $out[] = array('key' => 'mandat', 'icon' => '📄', 'label' => bk_t('DocMandate'),
            'url' => bk_public_url('mandate.php?t=' . $tourId));
    }
    foreach (bk_doc_defs() as $key => $d) {
        $flag = $d['flag'];
        $on = is_object($cfg) ? ($cfg->$flag ?? 0) : (is_array($cfg) ? ($cfg[$flag] ?? 0) : 0);
        if (intval($on) !== 1) continue;
        // A document only makes sense with content (bk_has_* of the def): no programme
        // without times, no participants without entries, no results without scores, no
        // matches without a generated bracket.
        if (!empty($d['has']) && function_exists($d['has']) && !call_user_func($d['has'], $tourId)) continue;
        $out[] = array('key' => $key, 'icon' => $d['icon'], 'label' => $d['label'],
            'url' => bk_public_url('document.php?t=' . $tourId . '&doc=' . $key));
    }
    $url = trim((string) (is_object($cfg) ? ($cfg->BcIanseoUrl ?? '') : ($cfg['BcIanseoUrl'] ?? '')));
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        $out[] = array('key' => 'ianseo', 'icon' => '🔗', 'label' => bk_t('DocIanseo'),
            'url' => $url, 'external' => true);
    }
    return $out;
}

/**
 * LIMITED relay to an official ianseo PDF generator. The sensitive point: the core's generators
 * need a competition session and an organiser ACL. So, FOR THIS SINGLE REQUEST and THIS SINGLE
 * competition, it sets:
 *  - the competition session (CreateTourSession, like bk_with_tournament);
 *  - a read right LIMITED to this code (AUTH_COMP = [code]), NEVER AUTH_ROOT (see authCheckACL:
 *    the grant comes from aut_code_allowed(code), not from an admin role) — so the elevated
 *    context cannot touch any other competition.
 * The archer's WHOLE session is saved then restored (finally + register_shutdown_function as a
 * net: an Output()/exit of the core script must never leave the archer elevated). The caller
 * ALREADY checked the archer, the tourId and the permission box.
 */
function bk_doc_relay($tourId, $code, $spec)
{
    global $CFG;
    $tourId = intval($tourId);

    $saved = $_SESSION;
    $done  = false;
    register_shutdown_function(function () use ($saved, &$done) {
        if (!$done) $_SESSION = $saved;
    });

    try {
        CreateTourSession($tourId);                 // empties the session, sets TourId/TourCode + settings
        $_SESSION['AUTH_User']   = '__doc_relay__'; // dummy non-empty identity (grant depends on the code)
        $_SESSION['AUTH_ENABLE'] = 1;
        unset($_SESSION['AUTH_ROOT']);              // NEVER admin
        $_SESSION['AUTH_COMP']   = array((string) $code);   // limits the right to THIS competition

        foreach ((array) ($spec['params'] ?? array()) as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; }

        // Matches: explicit list of the events with a bracket (never "all", which would also
        // print the categories without matches). See bk_final_events / bk_doc_defs.
        if (!empty($spec['events'])) {
            $evs = bk_final_events($tourId, $spec['events'] === 'team');
            if ($evs) { $_GET['Event'] = $evs; $_REQUEST['Event'] = $evs; }
        }

        include $CFG->DOCUMENT_PATH . $spec['script'];   // generates and streams the official PDF
    } finally {
        $_SESSION = $saved;
        $done = true;
    }
    exit;
}

/* ------------------------------------------------------------------ */
/* Bib (Qualification badge) — printed PER ARCHER                     */
/* ------------------------------------------------------------------ */

/**
 * Number of the bib template to use: the FIRST "Qualification" badge (lowest IcNumber) of the
 * competition — "the first of the list" (0 on competition 185). null when there is no Q
 * template (nothing to print).
 */
function bk_dossard_card($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT MIN(IcNumber) AS n FROM IdCards
        WHERE IcTournament = " . intval($tourId) . " AND IcType = 'Q'"));
    return ($r && $r->n !== null) ? intval($r->n) : null;
}

/** Is the bib offered to the archers? (opt-in; always on at level 2, like the mandate) */
function bk_dossard_visible($cfg)
{
    $lvl = intval(is_object($cfg) ? ($cfg->BcPublishLevel ?? 0) : (is_array($cfg) ? ($cfg['BcPublishLevel'] ?? 0) : 0));
    if ($lvl == 2) return true;
    $flag = is_object($cfg) ? ($cfg->BcShowDossard ?? 0) : (is_array($cfg) ? ($cfg['BcShowDossard'] ?? 0) : 0);
    return intval($flag) === 1;
}

/** Is there a printable bib on this competition? (option on AND a Q template) */
function bk_dossard_available($cfg, $tourId)
{
    return bk_dossard_visible($cfg) && bk_dossard_card($tourId) !== null;
}

/**
 * Registrations whose bib the archer may print on THIS competition: their own (BrLicence = their
 * licence) OR those they made (BrArcher = them, a club mate's registration). One row per
 * registration (departure).
 */
function bk_dossard_entries($tourId, $archer)
{
    $tourId = intval($tourId);
    $lic  = StrSafe_DB($archer->BaLicence);
    $baid = intval($archer->BaId);
    $rs = safe_r_sql("SELECT BrEnId, BrLicence, EnFirstName, EnName,
            QuSession, QuTarget, QuLetter, DivDescription, ClDescription
        FROM BookingRegistrations
        INNER JOIN Entries        ON EnId = BrEnId
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Divisions      ON DivTournament = $tourId AND DivId = EnDivision
        LEFT  JOIN Classes       ON ClTournament = $tourId AND ClId = EnClass
        WHERE BrTournament = $tourId AND (BrLicence = $lic OR BrArcher = $baid)
        ORDER BY (BrLicence = $lic) DESC, EnFirstName, EnName, QuSession");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * May the archer print the bib of this registration (EnId)? Returns the row (BrTournament) if so,
 * null otherwise. Defence against a forged ?enid.
 */
function bk_dossard_can($enId, $archer)
{
    $enId = intval($enId);
    $lic  = StrSafe_DB($archer->BaLicence);
    $baid = intval($archer->BaId);
    return safe_fetch(safe_r_sql("SELECT BrTournament, BrLicence FROM BookingRegistrations
        WHERE BrEnId = $enId AND (BrLicence = $lic OR BrArcher = $baid)")) ?: null;
}

/**
 * LIMITED relay to the core's badge generator (Accreditation/CardCustom.php), for one or SEVERAL
 * registrations (Entries[] = list of EnId) with the Qualification template $card. CardCustom.php
 * fills the slots of each A4 itself ("fill the pages"). Same elevated, limited context as
 * bk_doc_relay.
 */
function bk_doc_relay_bib($tourId, $code, $enIds, $card)
{
    $enIds = array_values(array_map('intval', (array) $enIds));
    bk_doc_relay($tourId, $code, array(
        'script' => 'Accreditation/CardCustom.php',
        'params' => array(
            'CardType'   => 'Q',
            'CardNumber' => intval($card),
            'Entries'    => $enIds,   // EnId in (...) filter in CommonCard.php
        ),
    ));
}

/**
 * Filling order of the bib slots: LEFT→RIGHT then TOP→BOTTOM.
 *
 * CardCustom.php fills the slots in the order of the template's offsets (Badges[0] = first
 * OffsetX × first OffsetY). Some templates have a "reversed" OffsetX (e.g. competition 185:
 * "105;0" → first slot top RIGHT), so a single bib lands top right. The offsets are SORTED
 * ascending: the positions stay the same (a full page is unchanged), only the filling ORDER
 * becomes natural → a single bib goes top left, several fill from the top left. Idempotent
 * (writes only when needed).
 */
function bk_dossard_normalize_layout($tourId, $card)
{
    $tourId = intval($tourId);
    $card   = intval($card);
    $r = safe_fetch(safe_r_sql("SELECT IcSettings FROM IdCards
        WHERE IcTournament = $tourId AND IcType = 'Q' AND IcNumber = $card"));
    if (!$r || (string) $r->IcSettings === '') return;
    $o = @unserialize((string) $r->IcSettings);
    if (!is_array($o) || !isset($o['OffsetX'], $o['OffsetY'])) return;

    $sortAxis = function ($csv) {
        $vals = array_values(array_filter(array_map('trim', explode(';', (string) $csv)),
            function ($v) { return $v !== ''; }));
        usort($vals, function ($a, $b) { return ((float) $a) <=> ((float) $b); });
        return implode(';', $vals);
    };
    $nx = $sortAxis($o['OffsetX']);
    $ny = $sortAxis($o['OffsetY']);
    if ($nx === (string) $o['OffsetX'] && $ny === (string) $o['OffsetY']) return;   // already natural

    $o['OffsetX'] = $nx;
    $o['OffsetY'] = $ny;
    safe_w_sql("UPDATE IdCards SET IcSettings = " . StrSafe_DB(serialize($o))
        . " WHERE IcTournament = $tourId AND IcType = 'Q' AND IcNumber = $card");
}

/**
 * Renders the STANDALONE HTML document of the mandate (doctype → /html) and prints it. Shared by
 * the organiser's preview (admin) and the public view (archer): only the CONTEXT changes.
 *
 * $ctx:
 *   'logo'    => callable($type, $width): URL of a logo (L/R/B) — differs whether the caller has
 *                an organiser session (TourLogo.php) or not (limited public endpoint);
 *   'regUrl'  => absolute URL of the online registration;
 *   'shopUrl' => absolute URL of the shop;
 *   'toolbar' => HTML of the top bar (buttons), or '' to leave it out.
 */
function bk_mandate_document($data, $m, $ctx)
{
    $pal = bk_mandate_palette($m['color']);
    $t   = $data['tour'];
    $logo = $ctx['logo'];
    $regUrl  = (string) ($ctx['regUrl'] ?? '');
    $shopUrl = (string) ($ctx['shopUrl'] ?? '');
    $toolbar = (string) ($ctx['toolbar'] ?? '');

    $block = function ($key) use ($m) {
        if (empty($m['blocks'][$key])) return '';
        return '<div class="mn-free"><h2>' . bk_e(bk_mandate_sections()[$key])
             . '</h2><div class="mn-text">' . nl2br(bk_e($m['blocks'][$key])) . '</div></div>';
    };

    header('Content-Type: text/html; charset=utf-8');
    $tourId = intval($t->ToId);
    $grey = function ($text) { return ' <span style="color:#7d8183">(' . bk_e($text) . ')</span>'; };
    $chips = function ($list) {
        $h = '';
        foreach ($list as $x) $h .= '<span class="mn-chip">' . bk_e($x) . '</span>';
        return '<div class="mn-chips">' . $h . '</div>';
    };

    echo '<!DOCTYPE html><html lang="' . bk_e(aut_lang_code()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . bk_e(bk_t('MnTitle', $t->ToName)) . '</title>'
        . '<style>:root{ --pri: ' . $pal['primary'] . '; --light: ' . $pal['light'] . '; --dark: ' . $pal['dark']
        . '; --on: ' . $pal['on'] . '; }</style>';
    ?>
<style>
*{ box-sizing:border-box; }
body{ margin:0; background:#e9edf2; color:#20263d;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  font-size:14px; line-height:1.5; overflow-wrap:break-word; word-break:break-word; }
.mn-title h1, .mn-text, ul.mn-list li, .mn-chip, .mn-meta td, .mn-reg, .mn-free { overflow-wrap:break-word; word-break:break-word; }
.mn-reg a, .mn-free a { word-break:break-all; }
.mn-bar{ position:sticky; top:0; display:flex; gap:8px; justify-content:center;
  padding:10px; background:#20263d; z-index:5; }
.mn-bar button, .mn-bar a{ font:inherit; font-size:13px; padding:8px 16px; border-radius:6px;
  border:0; cursor:pointer; text-decoration:none; }
.mn-bar .mn-print{ background:var(--pri); color:var(--on); font-weight:600; }
.mn-bar img{ vertical-align:middle; margin-right:4px; }
.mn-bar .mn-close{ background:#4a4f63; color:#fff; }
.mn-page{ max-width:800px; margin:18px auto; background:#fff; padding:0;
  box-shadow:0 2px 14px rgba(0,0,0,.14); }
.mn-inner{ padding:26px 34px 34px; }

/* Header */
.mn-head{ display:flex; align-items:center; gap:16px; }
.mn-head img{ max-height:64px; max-width:130px; object-fit:contain; flex:0 0 auto; }
.mn-head .mn-title{ flex:1; text-align:center; }
.mn-title h1{ margin:0; font-size:26px; color:var(--dark); line-height:1.15; }
.mn-title .mn-sub{ margin:6px 0 0; color:#4c4e50; font-size:14px; }
.mn-title .mn-org{ margin:2px 0 0; color:#7d8183; font-size:13px; }

/* Sections */
h2{ font-size:16px; margin:22px 0 8px; color:var(--dark);
  border-bottom:2px solid var(--pri); padding-bottom:4px; }
.mn-meta{ width:100%; border-collapse:collapse; }
.mn-meta th,.mn-meta td{ text-align:left; padding:6px 10px; border-bottom:1px solid #e3e6ea; font-size:14px; }
.mn-meta th{ width:170px; color:var(--dark); font-weight:600; white-space:nowrap; }
ul.mn-list{ margin:6px 0 0; padding-left:18px; }
ul.mn-list li{ margin:3px 0; }
.mn-chips{ display:flex; flex-wrap:wrap; gap:6px; margin:4px 0 0; }
.mn-chip{ background:var(--light); border:1px solid var(--pri); color:var(--dark);
  border-radius:5px; padding:2px 9px; font-size:13px; }
.mn-text{ white-space:normal; }
.mn-free{ margin-top:4px; }
.mn-reg{ background:var(--light); border:1px solid var(--pri); border-radius:8px;
  padding:12px 14px; margin-top:6px; }
.mn-reg a{ color:var(--dark); font-weight:600; word-break:break-all; }
.mn-bottom{ text-align:center; margin-top:26px; padding-top:14px; border-top:1px solid #e3e6ea; }
.mn-bottom img{ max-height:70px; max-width:100%; object-fit:contain; }

/* Template: Modern — side accent */
body.tpl-moderne .mn-inner{ border-left:10px solid var(--pri); }
body.tpl-moderne .mn-head .mn-title{ text-align:left; }
body.tpl-moderne .mn-title h1{ font-size:30px; }

/* Template: Compact — dense */
body.tpl-compact{ font-size:13px; }
body.tpl-compact .mn-inner{ padding:18px 24px 24px; }
body.tpl-compact .mn-title h1{ font-size:22px; }
body.tpl-compact h2{ font-size:14px; margin:14px 0 5px; }
body.tpl-compact .mn-meta th,.tpl-compact .mn-meta td{ padding:3px 8px; }

/* Template: Banner — full-colour header */
body.tpl-bandeau .mn-inner{ padding-top:0; }
body.tpl-bandeau .mn-head{ background:var(--pri); color:var(--on); margin:0 -34px 18px;
  padding:22px 34px; align-items:center; }
body.tpl-bandeau .mn-title h1{ color:var(--on); }
body.tpl-bandeau .mn-title .mn-sub, body.tpl-bandeau .mn-title .mn-org{ color:var(--on); opacity:.92; }
body.tpl-bandeau .mn-head img{ background:#fff; border-radius:6px; padding:4px; }
body.tpl-bandeau h2{ border-bottom-width:3px; }

/* Template: Framed — bordered page */
body.tpl-encadre .mn-page{ border:3px solid var(--pri); }
body.tpl-encadre .mn-inner{ padding:24px 30px 30px; }
body.tpl-encadre h2{ border:0; background:var(--light); color:var(--dark);
  padding:6px 12px; border-left:5px solid var(--pri); border-radius:0 4px 4px 0; }

/* Template: Clean — thin rules, spaced titles */
body.tpl-ligne h2{ border-bottom:1px solid var(--pri); text-transform:uppercase;
  letter-spacing:.12em; font-size:13px; font-weight:700; color:var(--pri); }
body.tpl-ligne .mn-chip{ background:#fff; }
body.tpl-ligne .mn-title h1{ font-weight:600; letter-spacing:.01em; }

/* Mobile: smaller logos, so they do not crush the central title on a narrow screen */
@media (max-width:600px){
  .mn-head{ gap:10px; }
  .mn-head img{ max-height:44px; max-width:74px; }
  .mn-inner{ padding:18px 16px 24px; }
  .mn-title h1{ font-size:21px; }
  body.tpl-bandeau .mn-head{ margin:0 -16px 16px; padding:16px; }
}

@media print{
  body{ background:#fff; }
  .mn-bar{ display:none; }
  .mn-page{ box-shadow:none; margin:0; max-width:none; }
  .mn-inner{ padding:0 6mm; }
  h2{ break-after:avoid; }
  .mn-free,.mn-reg{ break-inside:avoid; }
}
</style>
</head>
<?php
    $out = '<body class="tpl-' . bk_e($m['template']) . '">'
        . ($toolbar !== '' ? '<div class="mn-bar no-print">' . $toolbar . '</div>' : '')
        . '<div class="mn-page"><div class="mn-inner"><div class="mn-head">'
        . ((!empty($m['logos']['L']) && intval($t->HasL) > 0) ? '<img src="' . bk_e($logo('L', 400)) . '" alt="">' : '')
        . '<div class="mn-title"><h1>' . bk_e($t->ToName) . '</h1><p class="mn-sub">' . bk_e($data['discLabel'])
        . ($t->ToWhere ? ' — ' . bk_e($t->ToWhere) : '') . ' — ' . bk_e(bk_date_range($t->ToWhenFrom, $t->ToWhenTo)) . '</p>';
    $org = array();
    if ($t->ToComDescr) $org[] = bk_t('OrganisedBy', $t->ToComDescr);
    if ($data['region']) $org[] = $data['region'];
    if ($org) $out .= '<p class="mn-org">' . bk_e(implode(' — ', $org)) . '</p>';
    $out .= '</div>'
        . ((!empty($m['logos']['R']) && intval($t->HasR) > 0) ? '<img src="' . bk_e($logo('R', 400)) . '" alt="">' : '')
        . '</div>' . $block('intro');

    if (!empty($m['show']['sessions']) && $data['sessions']) {
        $out .= '<h2>' . bk_e(bk_t('MnSessions')) . '</h2><ul class="mn-list">';
        foreach ($data['sessions'] as $s) {
            $ss = bk_session_start($s);
            $places = intval($s->Places);
            $out .= '<li><b>' . bk_e(bk_t('DepCap', intval($s->SesOrder))) . '</b>' . ($s->SesName ? ' — ' . bk_e($s->SesName) : '')
                . ($ss !== '' ? ' — ' . bk_e(bk_date_time($ss)) : '')
                . ' — ' . bk_e(bk_t($places > 1 ? 'PlacesMany' : 'PlacesOne', $places)) . '</li>';
        }
        $out .= '</ul>';
    }

    if (!empty($m['show']['categories']) && ($data['divisions'] || $data['classes'])) {
        $out .= '<h2>' . bk_e(bk_t('MnCategories')) . '</h2>';
        if ($data['divisions']) $out .= '<p style="margin:0 0 4px"><b>' . bk_e(bk_t('MnBows')) . '</b></p>' . $chips($data['divisions']);
        if ($data['classes']) $out .= '<p style="margin:8px 0 4px"><b>' . bk_e(bk_t('MnClasses')) . '</b></p>' . $chips($data['classes']);
    }

    if (!empty($m['show']['fees'])) {
        $out .= '<h2>' . bk_e(bk_t('MnFees')) . '</h2>';
        if ($data['fee'] <= 0 && !$data['feeAdvanced']) {
            $out .= '<p style="margin:0">' . bk_e(bk_t('MnFree')) . '</p>';
        } else {
            $out .= '<p style="margin:0"><b>' . bk_e(bk_t('MnBaseFee')) . '</b> ' . bk_e(bk_eur($data['fee'], false, $tourId)) . '</p>';
            if ($data['feeAdvanced']) $out .= '<p class="mn-text" style="margin:4px 0 0; color:#4c4e50">' . bk_e(bk_t('MnFeeAdjust')) . '</p>';
        }
    }

    if (!empty($m['show']['payment']) && $data['pay']) {
        $out .= '<h2>' . bk_e(bk_t('PayMeansTitle')) . '</h2><ul class="mn-list">';
        foreach ($data['pay'] as $pi) {
            $out .= '<li>' . bk_e($pi['label']) . $grey($pi['whenLabel']) . ($pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '') . '</li>';
        }
        $out .= '</ul>';
    }

    if (!empty($m['show']['shop']) && !empty($data['shop']) && $shopUrl !== '') {
        $out .= '<h2>' . bk_e(bk_t('Shop')) . '</h2><ul class="mn-list">';
        foreach ($data['shop'] as $it) {
            $out .= '<li>' . bk_e($it['label']) . ($it['price'] > 0 ? ' — ' . bk_e(bk_eur($it['price'], false, $tourId)) : '')
                . ($it['description'] !== '' ? $grey($it['description']) : '');
            $vlabels = array();
            foreach ($it['variants'] as $v) {
                $vl = trim((string) $v['label']);
                if ($vl !== '') $vlabels[] = $vl;
            }
            if ($vlabels) {
                $out .= '<div style="color:#4c4e50; font-size:13px">'
                    . bk_e(bk_t('LabelColon', $it['option'] !== '' ? $it['option'] : bk_t('MnOptions')))
                    . ' ' . bk_e(implode(', ', $vlabels)) . '</div>';
            }
            $out .= '</li>';
        }
        $out .= '</ul><p class="mn-text" style="margin:4px 0 0; color:#4c4e50">' . bk_e(bk_t('MnShopOnline'))
            . ' <a href="' . bk_e($shopUrl) . '" style="color:var(--dark)">' . bk_e($shopUrl) . '</a></p>';
    }

    if (!empty($m['show']['register']) && $regUrl !== '') {
        $out .= '<h2>' . bk_e(bk_t('Brand')) . '</h2><div class="mn-reg"><p style="margin:0 0 4px">' . bk_e(bk_t('MnRegOnline')) . '</p>'
            . '<p style="margin:0"><a href="' . bk_e($regUrl) . '">' . bk_e($regUrl) . '</a></p>';
        if (!empty($data['deadline']) && bk_date_fr($data['deadline']) !== '') {
            $out .= '<p style="margin:6px 0 0"><b>' . bk_e(bk_t('MnDeadline')) . '</b> '
                . bk_e(bk_t('DateOn', bk_date_fr($data['deadline']))) . '</p>';
        }
        $out .= '</div>';
    }

    foreach (array('access', 'lodging', 'catering', 'awards', 'misc', 'contact') as $k) $out .= $block($k);
    if (!empty($m['logos']['B']) && intval($t->HasB) > 0) {
        $out .= '<div class="mn-bottom"><img src="' . bk_e($logo('B', 1000)) . '" alt=""></div>';
    }
    echo $out . '</div></div></body></html>';
}
