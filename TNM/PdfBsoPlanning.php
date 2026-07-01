<?php
// =============================================================================
// PdfBsoPlanning.php — Planning du BSO (volée 1) par épreuve
// Custom/TNM — FFTA Trophée National des Mixtes
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);
require_once($CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php');

// =============================================================================
// CONFIGURATION
// =============================================================================
$BASE = [
    'fTitle'     => 13,   // nom épreuve + nb équipes
    'fTime'      => 12,   // heure
    'fTgt'       => 12,   // numéro de cible
    'fTeam'      => 12,   // nom équipe
    'hMainTitle' => 30,   // hauteur titre page
    'hTime'      => 8,    // hauteur case heure
    'hTitle'     => 8,    // hauteur en-tête épreuve
    'hRow'       => 7,    // hauteur ligne cible
    'wTgt'       => 14,   // largeur colonne cible
];

$COLORS = [
    'evFallback' => '#dfdfdf',
    'unused'     => '#999999',
    'timeBox'    => '#ffffff',
];

// ── Helpers couleurs (identiques à PdfPools/PdfRanking) ───────────────────────
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
function autoTextColor($r, $g, $b) {
    return (0.299*$r + 0.587*$g + 0.114*$b) < 128 ? [255,255,255] : [0,0,0];
}
function applyColor($pdf, $hex) {
    [$r,$g,$b] = hexToRgb($hex);
    [$tr,$tg,$tb] = autoTextColor($r,$g,$b);
    $pdf->SetFillColor($r,$g,$b);
    $pdf->SetTextColor($tr,$tg,$tb);
}
function loadAccColors($tourId) {
    $rs = safe_r_sql("SELECT AcDivClass, AcColor FROM AccColors WHERE AcTournament=$tourId");
    $map = [];
    while ($r = safe_fetch($rs)) $map[] = ['pattern'=>$r->AcDivClass,'color'=>$r->AcColor];
    return $map;
}
function getEventColor($evCode, $colorMap) {
    foreach ($colorMap as $entry) {
        foreach (preg_split('/\s+/', trim($entry['pattern']), -1, PREG_SPLIT_NO_EMPTY) as $pat) {
            if (preg_match('/^'.str_replace('%','.*',preg_quote($pat,'/')).'$/i', $evCode))
                return '#'.ltrim($entry['color'],'#');
        }
    }
    return null;
}

// =============================================================================
// PARAMÈTRES
// =============================================================================
$tourId       = intval($_SESSION['TourId']);
$useAccColors = !empty($_REQUEST['useAccColors']);

// ── Récupération de toutes les épreuves configurées pour le BSO ──────────────
$rs = safe_r_sql(
    "SELECT c.BcEvent, c.BcBsoCount, c.BcStartTarget, c.BcSchedule, e.EvEventName
     FROM TNM_BsoConfig c
     JOIN Events e ON e.EvTournament=c.BcTournament AND e.EvCode=c.BcEvent COLLATE utf8mb4_unicode_ci
     WHERE c.BcTournament=$tourId"
);

$events = [];
while ($r = safe_fetch($rs)) {
    $sch = json_decode($r->BcSchedule ?? '{}', true) ?? [];
    $startTime = $sch['1'] ?? '';

    $bsoCount    = intval($r->BcBsoCount);
    $startTarget = intval($r->BcStartTarget);

    // ── Équipes de la volée 1 (cible => nom de l'équipe) ──────────────────────
    $rsTeams = safe_r_sql(
        "SELECT bv.BvTarget, co.CoName
         FROM TNM_BsoVolee bv
         LEFT JOIN Countries co ON co.CoId=bv.BvTeam AND co.CoTournament=bv.BvTournament
         WHERE bv.BvTournament=$tourId AND bv.BvEvent=".StrSafe_DB($r->BcEvent)."
         AND bv.BvRound=1"
    );
    $teamsByTarget = [];
    while ($t = safe_fetch($rsTeams)) $teamsByTarget[intval($t->BvTarget)] = $t->CoName;

    if (empty($teamsByTarget)) continue; // BSO non confirmé pour cette épreuve

    $events[] = [
        'code'       => $r->BcEvent,
        'name'       => get_text($r->EvEventName, '', '', true),
        'bsoCount'   => $bsoCount,
        'startTarget'=> $startTarget,
        'startTime'  => $startTime,
        'teams'      => $teamsByTarget, // BvTarget => CoName
    ];
}

if (empty($events)) {
    // Rien à imprimer
    $pdf = new ResultPDF('Planning BSO', true);
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(0, 10, "Aucune équipe BSO n'a été initialisée.", 0, 1, 'C');
    $pdf->Output('Planning BSO.pdf', 'I');
    exit;
}

// ── Tri des épreuves par heure de première volée (croissant) ─────────────────
usort($events, function($a, $b) {
    $ta = $a['startTime'] !== '' ? $a['startTime'] : '99:99';
    $tb = $b['startTime'] !== '' ? $b['startTime'] : '99:99';
    return strcmp($ta, $tb);
});

// ── Plage globale des cibles ──────────────────────────────────────────────────
$minTarget = null; $maxTarget = null;
foreach ($events as $ev) {
    $lo = $ev['startTarget'];
    $hi = $ev['startTarget'] + $ev['bsoCount'] - 1;
    $minTarget = $minTarget === null ? $lo : min($minTarget, $lo);
    $maxTarget = $maxTarget === null ? $hi : max($maxTarget, $hi);
}
$targets = range($minTarget, $maxTarget);

// =============================================================================
// PDF — paysage
// =============================================================================
$pdf = new ResultPDF('Planning BSO', true);
$pdf->DeletePage(1);
$pdf->AddPage('L');

$colorMap = loadAccColors($tourId);
$margins  = $pdf->getMargins();
$pageW    = $pdf->getPageWidth() - $margins['left'] - $margins['right'];

$wTgt   = $BASE['wTgt'];
$nEv    = count($events);
$wEv    = ($pageW - $wTgt) / $nEv;

// ── Auto-scale vertical : tout sur 1 page ─────────────────────────────────────
$nRows     = count($targets);
$pageAvail = $pdf->getPageHeight() - $margins['top'] - $margins['bottom'] - $BASE['hTime'] - $BASE['hTitle'] - $BASE['hMainTitle'];
$scale     = $nRows > 0 ? min(1.0, max(0.5, $pageAvail / ($nRows * $BASE['hRow']))) : 1.0;

$S = $BASE;
if ($scale < 1.0) {
    $S['hRow']        = max(3.0, round($BASE['hRow'] * $scale, 1));
    $S['hMainTitle']  = max(3.0, round($BASE['hMainTitle'] * $scale, 1));
    $S['fTeam']       = max(6, (int)round($BASE['fTeam'] * $scale));
    $S['fTgt']        = max(6, (int)round($BASE['fTgt']  * $scale));
}


$pdf->SetFont($pdf->FontStd, 'B', $BASE['fTitle']*2);
$pdf->Cell($pageW, $S['hMainTitle'], 'Sélectionnés pour le Big Shoot Off', 0, 1, 'C', 0);

$marginX = $pdf->GetX();
$startY  = $pdf->GetY();

// ── Ligne des horaires (au-dessus de chaque épreuve) ──────────────────────────
$pdf->SetXY($marginX, $startY);
$pdf->SetFont($pdf->FontStd, 'B', $BASE['fTime']);
$pdf->Cell($wTgt, $S['hTime'], '', 0, 0, 'C'); // case vide au-dessus de "Cible"
foreach ($events as $ev) {
    [$tr,$tg,$tb] = hexToRgb($COLORS['timeBox']);
    $pdf->SetFillColor($tr,$tg,$tb);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetDrawColor(0,0,0);
    $pdf->SetLineWidth(0.3);

    // case d'heure centrée et réduite (visuellement plus petite que la colonne)
    $boxW = min(22, $wEv * 0.5);
    $pdf->SetX($pdf->GetX() + ($wEv - $boxW) / 2);
    $pdf->Cell($boxW, $S['hTime'], $ev['startTime'] !== '' ? $ev['startTime'] : '—', 1, 0, 'C', 1);
    $pdf->SetX($pdf->GetX() + ($wEv - $boxW) / 2);
}
$pdf->Ln($S['hTime']);

// ── Ligne d'en-tête épreuves ──────────────────────────────────────────────────
$headerY = $pdf->GetY();
$pdf->SetXY($marginX, $headerY);
$pdf->SetFont($pdf->FontStd, 'B', $BASE['fTitle']);
applyColor($pdf, '#ffffff');
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth(0.5);
$pdf->Cell($wTgt, $S['hTitle'], 'Cible', 1, 0, 'C', 1);

$evHexes = [];
foreach ($events as $ev) {
    $hex = ($useAccColors ? getEventColor($ev['code'], $colorMap) : null) ?? $COLORS['evFallback'];
    $evHexes[$ev['code']] = $hex;

    applyColor($pdf, $hex);
    $pdf->SetFont($pdf->FontStd, 'B', $BASE['fTitle']);
    $label = $ev['name'];
    $pdf->Cell($wEv * 0.70, $S['hTitle'], $label, 1, 0, 'C', 1);
    $pdf->SetFont($pdf->FontStd, 'I', $BASE['fTitle'] - 2);
    $pdf->Cell($wEv * 0.3, $S['hTitle'], $ev['bsoCount'].' équipes', 1, 0, 'R', 1);
}
$pdf->Ln($S['hTitle']);
$pdf->SetDefaultColor();

// ── Lignes par cible ───────────────────────────────────────────────────────────
$pdf->SetLineWidth(0.2);
foreach ($targets as $target) {
    $rowY = $pdf->GetY();
    $pdf->SetXY($marginX, $rowY);

    // Colonne cible
    $pdf->SetFont($pdf->FontFix, 'B', $S['fTgt']);
    $pdf->SetFillColor(255,255,255);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetDrawColor(0,0,0);
    $pdf->Cell($wTgt, $S['hRow'], $target, 1, 0, 'C', 1);

    foreach ($events as $ev) {
        $inRange  = $target >= $ev['startTarget'] && $target <= ($ev['startTarget'] + $ev['bsoCount'] - 1);
        $teamName = $ev['teams'][$target] ?? null;

        if ($inRange && $teamName !== null) {
            applyColor($pdf, $evHexes[$ev['code']]);
            $pdf->SetFont($pdf->FontStd, '', $S['fTeam']);
            $pdf->Cell($wEv, $S['hRow'], $teamName, 1, 0, 'L', 1);
        } else {
            applyColor($pdf, $COLORS['unused']);
            $pdf->Cell($wEv, $S['hRow'], '', 1, 0, 'C', 1);
        }
    }
    $pdf->Ln($S['hRow']);
}
$pdf->SetDefaultColor();

// ── Bordure épaisse globale autour du tableau ──────────────────────────────────
$tableH = $pdf->GetY() - $headerY;
$pdf->SetLineWidth(0.7);
$pdf->Rect($marginX, $headerY, $wTgt + $nEv * $wEv, $tableH, 'D');
$pdf->SetLineWidth(0.1);

// ── Nom du fichier ────────────────────────────────────────────────────────────
$pdf->Output('Planning BSO.pdf', 'I');