<?php
/**
 * public/scoresheet-official.php — feuille de marque OFFICIELLE ianseo d'un archer.
 *
 * Relais borné (bk_doc_relay) vers le générateur officiel Qualification/PDFScore.php,
 * ciblé sur UNE seule inscription (paramètre Entry = EnId) → l'archer ne récupère
 * QUE la sienne. Garde stricte avant toute élévation :
 *   1. archer connecté (bk_require_archer) ;
 *   2. l'inscription (EnId) lui appartient (Entries.EnCode = sa licence) ;
 *   3. l'organisateur autorise la feuille de marque (BcAllowScoresheet).
 *
 * Options de rendu : en-tête + pied de page ianseo (ScorePageHeaderFooter), toutes
 * les séries/distances sur une même feuille et remplies (PersonalScore) ; PAS de
 * QR code ni de code-barres (ScoreBarcode / ScoreQrPersonal volontairement absents).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_doc_relay

$archer = bk_require_archer();

$enid = intval($_GET['enid'] ?? 0);
if (!$enid) { http_response_code(404); exit; }

// Accès autorisé si l'inscription est CELLE de l'archer (Entries.EnCode = sa
// licence) OU s'il l'a lui-même créée pour un camarade de son club (inscription
// de groupe : BK_Registrations.BrArcher = son compte). Rien d'autre.
$row = safe_fetch(safe_r_sql("SELECT e.EnTournament, q.QuSession, q.QuTarget
    FROM Entries e
    INNER JOIN Qualifications q ON q.QuId = e.EnId
    LEFT  JOIN BK_Registrations r ON r.BrEnId = e.EnId
    WHERE e.EnId = $enid
      AND (e.EnCode = " . StrSafe_DB($archer->BaLicence) . "
           OR r.BrArcher = " . intval($archer->BaId) . ")"));
if (!$row) { http_response_code(404); exit; }

$tourId = intval($row->EnTournament);
$cfg    = bk_comp_config($tourId);
if (empty($cfg->BcAllowScoresheet)) { http_response_code(404); exit; }   // choix de l'organisateur

$t = safe_fetch(safe_r_sql("SELECT ToCode, ToNumDist, ToType, ToCategory
    FROM Tournament WHERE ToId = $tourId"));
if (!$t || (string) $t->ToCode === '') { http_response_code(404); exit; }

$session = intval($row->QuSession);
$target  = intval($row->QuTarget);
$from    = $target > 0 ? $target : 1;
$to      = $target > 0 ? $target : 999;
$numDist = max(1, intval($t->ToNumDist));       // nombre de séries/distances de la qualification
$toType  = intval($t->ToType);
$field3D = intval($t->ToCategory) & 12;         // même signal que Qualification/PrintScore.php

// La feuille de marque DÉPEND de la discipline (voir le formulaire ianseo).
if ($toType == 50) {
    // BEURSAULT : discipline française → générateur dédié du set FR. ⚠️ Ce générateur
    // du cœur n'a PAS de filtre par archer (il imprime par plage de cibles), on borne
    // donc à la cible de l'archer (noEmpty = seulement les positions occupées).
    $spec = array(
        'script' => 'Modules/Sets/FR/pdf/PDFScore.php',
        'params' => array(
            'x_Session' => $session, 'x_From' => $from, 'x_To' => $to,
            'noEmpty' => '1', 'ScoreFilled' => '1',
            'ScoreHeader' => '1', 'ScoreLogos' => '1', 'ScoreFlags' => '1',
            // Aucun code : ScoreBarcode / ScoreQrPersonal / QRCode volontairement absents.
        ),
    );
} else {
    // TAE, Salle, Campagne/Field, 3D : générateur générique, CIBLÉ sur l'archer
    // (Entry), toutes les séries sur une même feuille et remplies avec les résultats.
    $params = array(
        'x_Session' => $session, 'x_From' => $from, 'x_To' => $to,
        'Entry'         => $enid,               // filtre : uniquement CET archer
        'ScoreDist'     => range(1, $numDist),  // toutes les séries (sinon 1re seule)
        'ScoreFilled'   => '1',                 // avec les résultats (sinon feuille vierge)
        'PersonalScore' => '1',                 // toutes les séries sur une même feuille
        'ScoreDraw'     => 'Complete',
        'ScorePageHeaderFooter' => '1',         // en-tête + pied de page ianseo
    );
    if ($field3D != 0) {
        // PARCOURS (Campagne/Field ou 3D) : cocher la case discipline. ianseo force
        // alors l'en-tête/logo « inline » (pas l'en-tête pleine page) — on s'aligne
        // sur son formulaire (optionField3d).
        $params['TourField3D'] = ($field3D == 4) ? 'FIELD' : '3D';
        unset($params['ScorePageHeaderFooter']);
        $params['ScoreHeader'] = '1';
        $params['ScoreLogos']  = '1';
    }
    $spec = array('script' => 'Qualification/PDFScore.php', 'params' => $params);
}

bk_doc_relay($tourId, $t->ToCode, $spec);
