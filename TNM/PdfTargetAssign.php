<?php
// =============================================================================
// PdfTargetAssign.php — Impression « Affectation des cibles »
// Custom/TNM — Trophée National des Mixtes
//
// Pour une (épreuve × tour × match) donnée : liste toutes les équipes avec leur
// cible de départ et leur poule, appariées par rencontre. 1 page par match.
// Sert aux archers à trouver leur cible de départ ; ensuite ils se réfèrent aux
// tableaux de poule.
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);
require_once($CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php');

// =============================================================================
// CONFIGURATION — tailles de base (pt pour polices, mm pour hauteurs/largeurs)
// Scalées automatiquement pour tenir sur 1 page.
// =============================================================================
$BASE = [
    // Polices (pt)
    'fHdr'   => 12,   // en-têtes de colonnes
    'fData'  => 12,   // données (cible, club)
    'fPool'  => 12,   // libellé poule (vertical)
    // Hauteurs (mm)
    'hHdr'   => 6,    // en-tête colonne
    'hRow'   => 5,    // ligne d'une équipe
    'gapMatch' => 1.5,// espace entre 2 rencontres d'une même poule
    'gapPool'  => 4,  // espace entre 2 poules
    // Largeurs (mm)
    'wPool'    => 8,  // bandeau poule vertical
    'colTgt1'  => 24, // cible — mise en page 1 colonne
    'colTgt2'  => 16, // cible — mise en page 2 colonnes
];

// Titre (non scalé — reste lisible)
define('TA_FTITLE', 16);   // taille police titre
define('TA_HTITLE', 7);    // hauteur d'une ligne de titre

$COLORS = [
    'evFallback' => '#dfdfdf',   // couleur épreuve si aucune AccColor
    'hdr'        => '#002B92',   // fond en-tête colonnes (bleu TNM)
    'poolBand'   => '#cfcfcf',   // fond bandeau poule (gris)
];

// Hauteur utile depuis le haut du contenu (après header ianseo), A4 portrait (mm)
define('CONTENT_H', 258);
// Espacement colonnes en mise en page 2 colonnes (mm)
define('COL_GAP', 6);

// =============================================================================
// HELPERS couleurs (identiques à PdfPools.php)
// =============================================================================
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
    while ($r = safe_fetch($rs)) $map[] = ['pattern'=>$r->AcDivClass, 'color'=>$r->AcColor];
    return $map;
}
function getEventColor($evCode, $colorMap) {
    foreach ($colorMap as $entry) {
        foreach (preg_split('/\s+/', trim($entry['pattern']), -1, PREG_SPLIT_NO_EMPTY) as $pat) {
            $regex = '/^'.str_replace('%','.*',preg_quote($pat,'/')).'$/i';
            if (preg_match($regex, $evCode))
                return '#'.ltrim($entry['color'],'#');
        }
    }
    return null;
}
function getTeamNames($tourId) {
    $rs = safe_r_sql("SELECT CoId, CoName FROM Countries WHERE CoTournament=$tourId");
    $result = [];
    while ($r = safe_fetch($rs))
        $result[intval($r->CoId)] = !empty($r->CoName) ? $r->CoName : '0'; // '0' = BYE
    return $result;
}

// =============================================================================
// HELPERS mise en page
// =============================================================================
// Hauteur d'un bloc-poule (n rencontres) selon la config courante
function blockHeight($nPairs, $b) {
    return $nPairs * 2 * $b['hRow'] + max(0, $nPairs - 1) * $b['gapMatch'];
}
// Hauteur totale d'une liste de blocs-poules (en-tête colonnes incluse)
function tableHeight($blocks, $b) {
    $h = $b['hHdr'];
    foreach ($blocks as $bl) $h += blockHeight(count($bl['pairs']), $b) + $b['gapPool'];
    return $h - (count($blocks) ? $b['gapPool'] : 0); // pas de gap après le dernier
}
// Applique une échelle au corps du tableau (les polices/hauteurs, pas le titre)
function scaleConfig($b, $scale) {
    if ($scale >= 1.0) return $b;
    foreach (['fHdr','fData','fPool'] as $k) $b[$k] = max(6, (int)round($b[$k] * $scale));
    foreach (['hHdr','hRow']         as $k) $b[$k] = max(3.0, round($b[$k] * $scale, 2));
    foreach (['gapMatch','gapPool']  as $k) $b[$k] = round($b[$k] * $scale, 2);
    return $b;
}
// Répartit les blocs en 2 colonnes ~équilibrées SANS couper une poule
function splitBlocks($blocks, $b) {
    $heights = [];
    $total = 0;
    foreach ($blocks as $i => $bl) {
        $heights[$i] = blockHeight(count($bl['pairs']), $b) + $b['gapPool'];
        $total += $heights[$i];
    }
    $half = $total / 2;
    $left = []; $right = []; $acc = 0;
    foreach ($blocks as $i => $bl) {
        // On remplit la colonne de gauche tant qu'on n'a pas dépassé la moitié
        if (empty($left) || $acc + $heights[$i] / 2 <= $half) {
            $left[] = $bl; $acc += $heights[$i];
        } else {
            $right[] = $bl;
        }
    }
    if (empty($right) && count($left) > 1) $right[] = array_pop($left);
    return [$left, $right];
}

// Dessine une ligne d'équipe (cible + club) à une position donnée
function renderTeamRow($pdf, $x, $y, $colTgt, $colClub, $b, $target, $name) {
    $isBye = ($name === '0');
    $disp  = $isBye ? '-- Bye --' : mb_strtoupper($name, 'UTF-8');
    $pdf->SetXY($x, $y);
    // ignore_min_height=true (10e arg) : hauteur dessinée = exactement hRow, pour
    // que les 2 lignes d'un match soient collées et coïncident avec le bandeau poule.
    $pdf->SetFont($pdf->FontStd, $isBye ? 'I' : '', $b['fData']);
    $pdf->Cell($colTgt, $b['hRow'], $isBye ? '' : $target, 1, 0, 'C', 0, '', 1, true);
    $pdf->SetFont($pdf->FontStd, $isBye ? 'I' : 'B', $b['fData']);
    $pdf->Cell($colClub, $b['hRow'], $disp, 1, 0, 'L', 0, '', 1, true);
}

// Dessine le bandeau poule vertical (fond gris + texte pivoté 90°)
// Reprend le pattern éprouvé de PdfRanking.php (Rotate + Text).
function drawPoolBand($pdf, $x, $y0, $y1, $w, $label, $fontSize, $bandHex) {
    $h = $y1 - $y0;
    if ($h <= 0) return;
    [$r,$g,$bl] = hexToRgb($bandHex);
    $pdf->SetFillColor($r, $g, $bl);
    $pdf->Rect($x, $y0, $w, $h, 'FD');

    $cx = $x + $w / 2;
    $cy = $y0 + $h / 2;

    $pdf->SetFont($pdf->FontStd, 'B', $fontSize);
    $wT = $pdf->GetStringWidth($label);
    // Réduit la police si le libellé dépasse la hauteur du bandeau
    if ($wT > $h - 2 && $wT > 0) {
        $fontSize = max(5, $fontSize * ($h - 2) / $wT);
        $pdf->SetFont($pdf->FontStd, 'B', $fontSize);
        $wT = $pdf->GetStringWidth($label);
    }
    $tx = $cx - $fontSize * 0.25;
    $ty = $cy + $wT / 2;
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Rotate(90, $tx, $ty);
    $pdf->Text($tx, $ty, $label);
    $pdf->Rotate(0);
    $pdf->SetDefaultColor();
}

// Dessine un tableau (liste de blocs-poules) à l'origine (x, topY)
function renderTable($pdf, $blocks, $x, $topY, $W, $b, $colors) {
    $wPool   = $b['wPool'];
    $colTgt  = $b['colTgt'];
    $colClub = $W - $wPool - $colTgt;

    // En-tête colonnes
    $pdf->SetXY($x, $topY);
    applyColor($pdf, $colors['hdr']);
    $pdf->SetFont($pdf->FontStd, 'B', $b['fHdr']);
    $pdf->Cell($wPool,   $b['hHdr'], 'Poule', 1, 0, 'C', 1);
    $pdf->Cell($colTgt,  $b['hHdr'], 'Cible', 1, 0, 'C', 1);
    $pdf->Cell($colClub, $b['hHdr'], 'Club',  1, 0, 'L', 1);
    $pdf->SetDefaultColor();

    $cursorY = $topY + $b['hHdr'];
    foreach ($blocks as $bl) {
        $blockTop = $cursorY;
        $pairs    = $bl['pairs'];
        $np       = count($pairs);
        foreach ($pairs as $pi => $pair) {
            renderTeamRow($pdf, $x + $wPool, $cursorY, $colTgt, $colClub, $b, $pair['t1'], $pair['n1']);
            $cursorY += $b['hRow'];
            renderTeamRow($pdf, $x + $wPool, $cursorY, $colTgt, $colClub, $b, $pair['t2'], $pair['n2']);
            $cursorY += $b['hRow'];
            if ($pi < $np - 1) $cursorY += $b['gapMatch'];
        }
        drawPoolBand($pdf, $x, $blockTop, $cursorY, $wPool, $bl['name'], $b['fPool'], $colors['poolBand']);
        $cursorY += $b['gapPool'];
    }
}

// =============================================================================
// PARAMÈTRES REQUEST
// =============================================================================
$tourId       = intval($_SESSION['TourId']);
$events       = (array)($_REQUEST['event'] ?? ['.']);
$levels       = (array)($_REQUEST['level'] ?? ['.']);
$matches      = (array)($_REQUEST['match'] ?? ['.']);
$useAccColors = !empty($_REQUEST['useAccColors']);

$allEvt   = in_array('.', $events);
$allLev   = in_array('.', $levels);
$allMatch = in_array('.', $matches);
$levelNums = $allLev   ? [] : array_map('intval', $levels);
$matchNums = $allMatch ? [] : array_map('intval', $matches);

// =============================================================================
// DONNÉES COMMUNES
// =============================================================================
$colorMap  = loadAccColors($tourId);
$teamNames = getTeamNames($tourId);

$evSQL = $allEvt ? '' :
    "AND l.RrLevEvent IN (".implode(',', array_map('StrSafe_DB', $events)).")";

$rsLev = safe_r_sql(
    "SELECT l.RrLevEvent, l.RrLevLevel, l.RrLevName, e.EvEventName
     FROM RoundRobinLevel l
     JOIN Events e ON e.EvTournament=l.RrLevTournament AND e.EvCode=l.RrLevEvent
     WHERE l.RrLevTournament=$tourId $evSQL
     ORDER BY e.EvProgr, l.RrLevLevel"
);

// =============================================================================
// PDF
// =============================================================================
$pdf = new ResultPDF('Affectation des cibles', true);
$pdf->SetAutoPageBreak(false); // 1 page par match — pas de débordement automatique
$pdf->setCellPaddings(1, 0, 1, 0); // marge interne verticale nulle → lignes collées
$firstPage = true;

while ($lev = safe_fetch($rsLev)) {
    if (!$allLev && !in_array(intval($lev->RrLevLevel), $levelNums)) continue;

    $evCode = $lev->RrLevEvent;
    $level  = intval($lev->RrLevLevel);
    $evName = get_text($lev->EvEventName, '', '', true);
    $evHex  = ($useAccColors ? getEventColor($evCode, $colorMap) : null) ?? $COLORS['evFallback'];

    // Matchs (rounds) distincts de cette épreuve/tour
    $rsR = safe_r_sql(
        "SELECT DISTINCT RrMatchRound FROM RoundRobinMatches
         WHERE RrMatchTournament=$tourId
         AND RrMatchEvent=".StrSafe_DB($evCode)."
         AND RrMatchLevel=$level
         ORDER BY RrMatchRound"
    );
    $roundList = [];
    while ($rr = safe_fetch($rsR)) $roundList[] = intval($rr->RrMatchRound);

    foreach ($roundList as $matchRound) {
        if (!$allMatch && !in_array($matchRound, $matchNums)) continue;

        // ── Construction des blocs-poules pour ce (épreuve, tour, match) ────────
        $rsGrp = safe_r_sql(
            "SELECT RrGrGroup, RrGrName FROM RoundRobinGroup
             WHERE RrGrTournament=$tourId
             AND RrGrEvent=".StrSafe_DB($evCode)."
             AND RrGrLevel=$level
             ORDER BY RrGrGroup"
        );

        $poolBlocks = [];
        while ($grp = safe_fetch($rsGrp)) {
            $rsM = safe_r_sql(
                "SELECT RrMatchMatchNo, RrMatchAthlete, RrMatchTarget
                 FROM RoundRobinMatches
                 WHERE RrMatchTournament=$tourId
                 AND RrMatchEvent=".StrSafe_DB($evCode)."
                 AND RrMatchLevel=$level
                 AND RrMatchGroup=".intval($grp->RrGrGroup)."
                 AND RrMatchRound=$matchRound
                 ORDER BY RrMatchMatchNo"
            );
            // Les 2 adversaires d'une rencontre sont 2 lignes consécutives dans
            // l'ordre RrMatchMatchNo (même logique que PdfPools.php), et NON un
            // groupe partageant le même RrMatchMatchNo.
            $rows = [];
            while ($m = safe_fetch($rsM)) $rows[] = $m;

            $pairs = [];
            for ($i = 0; $i + 1 < count($rows); $i += 2) {
                $r1 = $rows[$i];
                $r2 = $rows[$i + 1];
                $n1 = $teamNames[intval($r1->RrMatchAthlete)] ?? '0';
                $n2 = $teamNames[intval($r2->RrMatchAthlete)] ?? '0';
                if ($n1 === '0' && $n2 === '0') continue; // rencontre fictive (2 byes)

                $tg1 = ltrim($r1->RrMatchTarget, '0');
                $tg2 = ltrim($r2->RrMatchTarget, '0');
                $v1 = is_numeric($tg1) ? intval($tg1) : PHP_INT_MAX;
                $v2 = is_numeric($tg2) ? intval($tg2) : PHP_INT_MAX;

                $pairs[] = [
                    'n1' => $n1, 't1' => $tg1,
                    'n2' => $n2, 't2' => $tg2,
                    'sort' => min($v1, $v2),
                ];
            }
            if (!$pairs) continue;

            usort($pairs, fn($a, $z) => $a['sort'] <=> $z['sort']); // cible croissante
            $poolBlocks[] = [
                'name'  => $grp->RrGrName ?: 'Poule '.$grp->RrGrGroup,
                'pairs' => $pairs,
            ];
        }
        if (!$poolBlocks) continue;

        // ── Nouvelle page ───────────────────────────────────────────────────────
        if ($firstPage) { $firstPage = false; }
        else            { $pdf->AddPage(); }

        $lMargin = $pdf->getSideMargin();
        $W       = $pdf->getPageWidth() - 2 * $lMargin;

        // ── Titre 2 lignes (fond couleur épreuve) ────────────────────────────────
        $contentTop = $pdf->GetY();
        applyColor($pdf, $evHex);
        $pdf->SetXY($lMargin, $contentTop);
        $pdf->SetFont($pdf->FontStd, 'B', TA_FTITLE);
        $pdf->Cell($W, TA_HTITLE, mb_strtoupper('Affectation des cibles', 'UTF-8'), 0, 1, 'C', 1);
        $pdf->SetX($lMargin);
        $pdf->Cell($W, TA_HTITLE, 'Match '.$matchRound.'   -   Tour '.$level.'   -   '.$evName, 0, 1, 'C', 1);
        $pdf->SetDefaultColor();

        $titleH   = 2 * TA_HTITLE + 3;
        $tableTop = $contentTop + $titleH;
        $availH   = CONTENT_H - $titleH;

        // ── Choix mise en page (1 ou 2 colonnes) + échelle ───────────────────────
        $nPools     = count($poolBlocks);
        $singleH    = tableHeight($poolBlocks, $BASE);
        $scaleSingle = min(1.0, $availH / max(1, $singleH));

        if ($scaleSingle >= 0.75 || $nPools < 2) {
            // 1 colonne
            $b = scaleConfig($BASE, $scaleSingle);
            $b['colTgt'] = $BASE['colTgt1'];
            renderTable($pdf, $poolBlocks, $lMargin, $tableTop, $W, $b, $COLORS);
        } else {
            // 2 colonnes
            [$left, $right] = splitBlocks($poolBlocks, $BASE);
            $colW  = ($W - COL_GAP) / 2;
            $colH  = max(tableHeight($left, $BASE), tableHeight($right, $BASE));
            $scale = min(1.0, $availH / max(1, $colH));
            $b = scaleConfig($BASE, $scale);
            $b['colTgt'] = $BASE['colTgt2'];
            renderTable($pdf, $left,  $lMargin,               $tableTop, $colW, $b, $COLORS);
            renderTable($pdf, $right, $lMargin + $colW + COL_GAP, $tableTop, $colW, $b, $COLORS);
        }
    }
}

// Aucune donnée trouvée : message sur la page vide déjà créée
if ($firstPage) {
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(0, 20, 'Aucune affectation de cible trouvée pour cette sélection.', 0, 1, 'C', 0);
}

// ── Nom du fichier ─────────────────────────────────────────────────────────────
$evPart  = $allEvt   ? 'Toutes epreuves' : implode('+', array_map('strtoupper', $events));
$levPart = $allLev   ? 'Tous tours'      : 'Tour '.implode('+', $levelNums);
$mPart   = $allMatch ? 'Tous matchs'     : 'Match '.implode('+', $matchNums);
$filename = 'Affectation cibles - '
          . preg_replace('/[^\w\-\+]/', ' ', $evPart.' - '.$levPart.' - '.$mPart) . '.pdf';

$pdf->Output($filename, 'I');
