<?php
/**
 * public/scoresheet-official.php — an archer's OFFICIAL ianseo score sheet.
 *
 * Limited relay (bk_doc_relay) to the official generator Qualification/PDFScore.php, aimed at
 * ONE registration (parameter Entry = EnId) → the archer only gets THEIR own. Strict guard
 * before any elevation:
 *   1. connected archer (bk_require_archer);
 *   2. the registration (EnId) is theirs (Entries.EnCode = their licence);
 *   3. the organiser allows the score sheet (BcAllowScoresheet).
 *
 * Rendering: ianseo header + footer (ScorePageHeaderFooter), every distance on one filled sheet
 * (PersonalScore); NO QR code or barcode (ScoreBarcode / ScoreQrPersonal left out on purpose).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_doc_relay

$archer = bk_require_archer();

$enid = intval($_GET['enid'] ?? 0);
if (!$enid) { http_response_code(404); exit; }

// Allowed when the registration is the archer's OWN (Entries.EnCode = their licence) OR when
// they made it for a club mate (group registration: BookingRegistrations.BrArcher = their account).
// Nothing else.
$row = safe_fetch(safe_r_sql("SELECT EnTournament, QuSession, QuTarget
    FROM Entries
    INNER JOIN Qualifications ON QuId = EnId
    LEFT  JOIN BookingRegistrations ON BrEnId = EnId
    WHERE EnId = $enid
      AND (EnCode = " . StrSafe_DB($archer->BaLicence) . "
           OR BrArcher = " . intval($archer->BaId) . ")"));
if (!$row) { http_response_code(404); exit; }

$tourId = intval($row->EnTournament);
$cfg    = bk_comp_config($tourId);
if (empty($cfg->BcAllowScoresheet)) { http_response_code(404); exit; }   // the organiser's choice

$t = safe_fetch(safe_r_sql("SELECT ToCode, ToNumDist, ToType, ToCategory
    FROM Tournament WHERE ToId = $tourId"));
if (!$t || (string) $t->ToCode === '') { http_response_code(404); exit; }

$session = intval($row->QuSession);
$target  = intval($row->QuTarget);
$from    = $target > 0 ? $target : 1;
$to      = $target > 0 ? $target : 999;
$numDist = max(1, intval($t->ToNumDist));       // number of distances of the qualification
$toType  = intval($t->ToType);
$field3D = intval($t->ToCategory) & 12;         // same signal as Qualification/PrintScore.php

// The score sheet DEPENDS on the discipline (see the ianseo form).
if ($toType == 50) {
    // BEURSAULT: French discipline → dedicated generator of the FR set. ⚠️ This core generator
    // has NO per-archer filter (it prints by target range), so it is limited to the archer's
    // target (noEmpty = occupied positions only).
    $spec = array(
        'script' => 'Modules/Sets/FR/pdf/PDFScore.php',
        'params' => array(
            'x_Session' => $session, 'x_From' => $from, 'x_To' => $to,
            'noEmpty' => '1', 'ScoreFilled' => '1',
            'ScoreHeader' => '1', 'ScoreLogos' => '1', 'ScoreFlags' => '1',
            // No code: ScoreBarcode / ScoreQrPersonal / QRCode left out on purpose.
        ),
    );
} else {
    // Outdoor, indoor, field, 3D: generic generator, AIMED at the archer (Entry), every
    // distance on one sheet, filled with the results.
    $params = array(
        'x_Session' => $session, 'x_From' => $from, 'x_To' => $to,
        'Entry'         => $enid,               // filter: THIS archer only
        'ScoreDist'     => range(1, $numDist),  // every distance (otherwise the first only)
        'ScoreFilled'   => '1',                 // with the results (otherwise a blank sheet)
        'PersonalScore' => '1',                 // every distance on one sheet
        'ScoreDraw'     => 'Complete',
        'ScorePageHeaderFooter' => '1',         // ianseo header + footer
    );
    if ($field3D != 0) {
        // COURSE (field or 3D): tick the discipline box. ianseo then forces the "inline"
        // header/logo (not the full-page header) — same as its form (optionField3d).
        $params['TourField3D'] = ($field3D == 4) ? 'FIELD' : '3D';
        unset($params['ScorePageHeaderFooter']);
        $params['ScoreHeader'] = '1';
        $params['ScoreLogos']  = '1';
    }
    $spec = array('script' => 'Qualification/PDFScore.php', 'params' => $params);
}

bk_doc_relay($tourId, $t->ToCode, $spec);
