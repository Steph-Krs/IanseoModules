<?php
// =============================================================================
// PdfRanking.php — Classement général Round Robin par épreuve
// Tour 1 : split positional (PP / PC)
// Tour 2+: split par niveau sélectionné (PP = niveau bas / PC = niveau haut)
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);
require_once($CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php');

// ── Lecture des valeurs de configuration TNM ─────────────────────────────────
function getTNMEventValue($tourId, $evCode, $key, $default = null) {
    $map = ['bso_count' => 'BcBsoCount', 'start_target' => 'BcStartTarget'];
    $col = $map[$key] ?? null;
    if (!$col) return $default;
    $rs = safe_r_sql("SELECT $col FROM TNM_BsoConfig
                      WHERE BcTournament=$tourId AND BcEvent=".StrSafe_DB($evCode));
    $r = safe_fetch($rs);
    return ($r && $r->$col !== null) ? $r->$col : $default;
}

// ── Couleurs (identiques à PdfPools) ─────────────────────────────────────────
$COLORS = [
    'evFallback' => '#dfdfdf',
    'rankHdr'    => '#002B92',
    'rankSub'    => '#406BD2',
    'teamName'   => '#F90A72',
];

// ── Helpers couleurs ──────────────────────────────────────────────────────────
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
// Éclaircit une couleur hex de $pct% (0=inchangé, 100=blanc)
function lightenColor($hex, $pct) {
    [$r,$g,$b] = hexToRgb($hex);
    $f = $pct / 100.0;
    return sprintf('#%02X%02X%02X',
        min(255, (int)round($r + (255 - $r) * $f)),
        min(255, (int)round($g + (255 - $g) * $f)),
        min(255, (int)round($b + (255 - $b) * $f))
    );
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

// ── Classement avec gestion des égalités ─────────────────────────────────────
// Affecte $p->ComputedRank (1-indexed, partagé en cas d'égalité)
function assignRanks(&$parts) {
    $n = count($parts);
    for ($i = 0; $i < $n; ) {
        $rankVal = $i + 1;
        $k = [
            intval($parts[$i]->RrPartGroupRankBefSO),
            intval($parts[$i]->RrPartPoints),
            intval($parts[$i]->RrPartTieBreaker),
            intval($parts[$i]->RrPartTieBreaker2),
        ];
        $j = $i;
        while ($j < $n) {
            $kj = [
                intval($parts[$j]->RrPartGroupRankBefSO),
                intval($parts[$j]->RrPartPoints),
                intval($parts[$j]->RrPartTieBreaker),
                intval($parts[$j]->RrPartTieBreaker2),
            ];
            if ($kj !== $k) break;
            $parts[$j]->ComputedRank = $rankVal;
            $j++;
        }
        $i = $j;
    }
}

// ── Comparateur de tri ────────────────────────────────────────────────────────
$sortFn = fn($a, $b) =>
    intval($a->RrPartGroupRankBefSO) - intval($b->RrPartGroupRankBefSO)
    ?: intval($b->RrPartPoints)      - intval($a->RrPartPoints)
    ?: intval($b->RrPartTieBreaker)  - intval($a->RrPartTieBreaker)
    ?: intval($b->RrPartTieBreaker2) - intval($a->RrPartTieBreaker2)
    ?: intval($a->RrPartLevelRank)   - intval($b->RrPartLevelRank)
    ?: intval($a->RrPartGroup)       - intval($b->RrPartGroup);

// ── Dessin du label vertical d'une section ───────────────────────────────────
// Remplit la cellule gauche avec $sectionColor et y inscrit text1 (gras) + text2 (normal)
function drawVerticalLabel($pdf, $x, $y, $w, $h, $text1, $text2, $sectionColor, $vertLabelSize) {
    if ($h <= 0) return;
    $pdf->SetLineWidth(0.7);
    applyColor($pdf, $sectionColor);
    $pdf->Rect($x, $y, $w, $h, 'FD'); //'FD' = fond + bordure, 'F' = fond seul
    $pdf->SetLineWidth(0.1);

    $cx = $x + $w / 2;
    $cy = $y + $h / 2;
    //$pdf->SetDrawColor(0xC3, 0x33, 0xC3); // couleur des bordures et du "X"
    //$pdf->Line($cx - $w/2, $cy, $cx + $w/2, $cy);
    //$pdf->Line($cx, $cy - $h/2, $cx, $cy + $h/2);

    // Ligne 1 : texte en gras, centré légèrement au-dessus
    $pdf->SetFont($pdf->FontStd, 'B', $vertLabelSize);
    $wT1 = $pdf->GetStringWidth($text1);
    $hT1 = $vertLabelSize *0.5; // demi-hauteur de caractère en mm (approx)
    
    $T1x = $cx - $hT1; // '+' vers la droite
    $T1y = $cy + $wT1/2; // '+' vers le bas
    $pdf->Rotate(90, $T1x, $T1y);
    $pdf->Text($T1x, $T1y, $text1);
    $pdf->Rotate(0);

    // Ligne 2 : texte normal, centré légèrement en-dessous
    $pdf->SetFont($pdf->FontStd, '', $vertLabelSize);
    $wT2 = $pdf->GetStringWidth($text2);
    $hT2 = $vertLabelSize *0.5;
    $T2x = $cx; // '+' vers la droite
    $T2y = $cy + $wT2/2; // '+' vers le bas
    $pdf->Rotate(90, $T2x, $T2y);
    $pdf->Text($T2x, $T2y, $text2);
    $pdf->Rotate(0);

    $pdf->SetDefaultColor();
}

// =============================================================================
// PARAMÈTRES
// =============================================================================
$tourId       = intval($_SESSION['TourId']);
$events       = (array)($_REQUEST['event'] ?? ['.']);
$levels       = (array)($_REQUEST['level'] ?? ['1']);
$useAccColors = !empty($_REQUEST['useAccColors']);

$allEvt    = in_array('.', $events);
$allLev    = in_array('.', $levels);
$levelNums = $allLev ? [] : array_map('intval', $levels);
$minLevel  = $allLev ? 1 : min($levelNums);

$levelSQL = $allLev ? '' :
    "AND p.RrPartLevel IN (".implode(',', $levelNums).")";

// ── Liste des épreuves ────────────────────────────────────────────────────────
$evFilter = $allEvt ? '' :
    "AND e.EvCode IN (".implode(',', array_map('StrSafe_DB', $events)).")";

$rsEvList = safe_r_sql(
    "SELECT DISTINCT e.EvCode, e.EvEventName
     FROM Events e
     WHERE e.EvTournament=$tourId
     AND e.EvElimType=5 AND e.EvTeamEvent='1' AND e.EvCodeParent=''
     $evFilter ORDER BY e.EvProgr"
);

// ── Tailles de base (seront scalées par épreuve) ──────────────────────────────
$DEF = [
    'fTitle' => 18, 'fSub' => 14, 'fHdr' => 11, 'fData' => 11, 'fLabel' => 10,
    'hTitle' =>  7, 'hSub' =>  6, 'hHdr' =>  5, 'hRow'  =>  4,
];

// Dimensions colonnes (mm) — fixes, indépendantes du scale
$W     = 190;
$wSec  = 12;   // label vertical
$wRk   = 12;   // classement
$wPl   = 16;   // place poule
$wPts  = 16;   // points
$wDiff = 16;   // différentiel
$wPS   = 16;   // pts-sets
$wClub = $W - $wSec - $wRk - $wPl - $wPts - $wDiff - $wPS; // = 102mm

// =============================================================================
// PDF
// =============================================================================
$pdf       = new ResultPDF('Classement Round Robin', true);
$firstPage = true;
$colorMap  = loadAccColors($tourId);

while ($ev = safe_fetch($rsEvList)) {
    $evCode = $ev->EvCode;
    $evName = get_text($ev->EvEventName, '', '', true);
    $evHex  = ($useAccColors ? getEventColor($evCode, $colorMap) : null) ?? $COLORS['evFallback'];

    // ── BSO par épreuve ───────────────────────────────────────────────────────
    $bsoCount  = intval(getTNMEventValue($tourId, $evCode, 'bso_count', 10));
    $mainLimit = $bsoCount * 2;

    // ── Participants de tous les niveaux sélectionnés ─────────────────────────
    $rs = safe_r_sql(
        "SELECT p.*, co.CoName as ClubName
         FROM RoundRobinParticipants p
         LEFT JOIN Countries co ON co.CoId=p.RrPartParticipant AND co.CoTournament=p.RrPartTournament
         WHERE p.RrPartTournament=$tourId
         AND p.RrPartEvent=".StrSafe_DB($evCode)."
         $levelSQL
         AND co.CoName IS NOT NULL AND co.CoName != ''"
    );
    $allParts = [];
    while ($p = safe_fetch($rs)) $allParts[] = $p;
    if (empty($allParts)) continue;

    usort($allParts, $sortFn);

    // ── Construction des sections ─────────────────────────────────────────────
    $distinctLevels = array_unique(array_map(fn($p) => intval($p->RrPartLevel), $allParts));

    if (count($distinctLevels) === 1 && $minLevel === 1) {

        // ── TOUR 1 : split positional ─────────────────────────────────────────
        assignRanks($allParts);
        $mainParts  = array_values(array_filter($allParts, fn($p) => $p->ComputedRank <= $mainLimit));
        $classParts = array_values(array_filter($allParts, fn($p) => $p->ComputedRank > $mainLimit));
        $nMain      = count($mainParts);
        $nClass     = count($classParts);

        $sections = [
            [
                'label1'=>'Poules principales',
                'label2'=>$mainLimit.' équipes pour le Tour 2',
                'parts' =>$mainParts,
                'noRank'=>false,
                'isMain'=>true,
                'offset'=>0
            ],
            [
                'label1'=>'Poules de classement',
                'label2'=>($nMain+1).' à '.($nMain+$nClass),
                'parts' =>$classParts,
                'noRank'=>false,
                'isMain'=>false,
                'offset'=>0
            ],
        ];
        $tourLabel = 'Tour 1';

    } else {

        // ── TOUR 2+ : split par numéro de poule ───────────────────────────────
        // PP (Poules Principales) et PC (Poules de Classement) sont dans le MÊME
        // niveau (RrPartLevel = $minLevel). Elles ne sont pas identifiables dans
        // la BDD autrement que par leur numéro de poule (RrPartGroup) :
        //   - Groupes 1..$nPoolsMain     → Poules Principales
        //   - Groupes $nPoolsMain+1..N   → Poules de Classement
        //
        // $nPoolsMain = ($bsoCount × 2) / teamsPerPool
        // Le tri dans chaque section est indépendant et suit les mêmes critères
        // que le Tour 1 (GroupRankBefSO, Points, TieBreaker, TieBreaker2).

        // Nombre d'équipes par poule (depuis RoundRobinLevel)
        $rsLevInfo = safe_r_sql(
            "SELECT RrLevGroupArchers FROM RoundRobinLevel
             WHERE RrLevTournament=$tourId
             AND RrLevEvent=".StrSafe_DB($evCode)."
             AND RrLevLevel=$minLevel"
        );
        $teamsPerPool = 4; // valeur par défaut si non trouvé
        if ($li = safe_fetch($rsLevInfo))
            $teamsPerPool = max(2, intval($li->RrLevGroupArchers));

        $nPoolsMain = intdiv($bsoCount * 2, $teamsPerPool);

        // Split par groupe (tous sont au niveau $minLevel)
        $mainParts  = array_values(array_filter($allParts, fn($p) => intval($p->RrPartGroup) <= $nPoolsMain));
        $classParts = array_values(array_filter($allParts, fn($p) => intval($p->RrPartGroup) > $nPoolsMain));

        // Tri et ranking indépendants dans chaque type de poule
        usort($mainParts,  $sortFn);
        usort($classParts, $sortFn);
        assignRanks($mainParts);
        assignRanks($classParts);

        // Poules principales : 1ère moitié → BSO (sans rang), 2ème → classés
        $bsoParts  = array_slice($mainParts, 0, $bsoCount);
        $mainClass = array_slice($mainParts, $bsoCount);
        $nMain     = count($mainParts);
        $nClass    = count($classParts);

        $sections = [
            [
                'label1'=>'Big Shoot Off',
                'label2'=>$bsoCount.' qualifiés',
                'parts' =>$bsoParts,
                'noRank'=>true,
                'isMain'=>true,
                'offset'=>0
            ],
            [
                'label1'=>'Classement',
                'label2'=>($bsoCount+1).' à '.$nMain,
                'parts' =>$mainClass,
                'noRank'=>false,
                'isMain'=>false,
                'offset'=>0
            ],
            [
                'label1'=>'Classement',
                'label2'=>($nMain+1).' à '.($nMain+$nClass),
                'parts' =>$classParts,
                'noRank'=>false,
                'isMain'=>false,
                'offset'=>$nMain
            ],
        ];
        $tourLabel = 'Tour '.$minLevel;
    }

    // ── Nouvelle page (AVANT le scale pour lire les marges réelles) ─────────────
    if ($firstPage) { $firstPage = false; } else { $pdf->AddPage(); }

    // ── Auto-scale : tout le classement tient sur 1 page ─────────────────────
    // TCPDF impose Cell() ≥ fontSizePt × PT_MM × CHR → hRow "réel" ≠ hRow "demandé"
    // Ex : 11pt → min 4.84mm ; passer 4mm à Cell() donne quand même 4.84mm → débordement.
    // On itère avec les hauteurs effectives jusqu'à trouver le bon scale.
    $PT_MM  = 25.4 / 72;  // ≈ 0.353 mm/pt
    $CHR    = 1.25;        // K_CELL_HEIGHT_RATIO dans tcpdf.php (constant)
    $margins   = $pdf->getMargins();
    $LN_GAP    = 4;   // Ln(4) fixe, non scalable — soustrait de l'espace disponible
    $pageAvail = $pdf->getPageHeight() - $margins['top'] - $margins['bottom'] - $DEF['hTitle'] - $DEF['hSub'] - $LN_GAP;
    $nRows     = array_sum(array_map(fn($s) => count($s['parts']), $sections));

    // Valeurs par défaut si aucune itération n'est nécessaire
    $scale = 1.0;
    $fD    = $DEF['fData'];
    $fH    = $DEF['fHdr'];
    $rH    = max($DEF['hRow'], $fD * $PT_MM * $CHR); // hauteur effective réelle
    $hH    = max($DEF['hHdr'], $fH * $PT_MM * $CHR);

    if ($nRows > 0) {
        for ($iter = 0; $iter < 25; $iter++) {
            $fD  = max(6, (int)round($DEF['fData'] * $scale));
            $fH  = max(6, (int)round($DEF['fHdr']  * $scale));
            $rH  = $fD * $PT_MM * $CHR;    // hauteur min TCPDF pour fData
            $hH  = max($fH * $PT_MM * $CHR, round($DEF['hHdr'] * $scale, 1));
            $predicted = $hH + $nRows * $rH;
            if ($predicted <= $pageAvail) break;
            // Réduire le scale proportionnellement (−0.5mm de marge de sécurité)
            $scale = max(0.3, $scale * ($pageAvail - 0.5) / max(0.1, $predicted));
        }

        // Étaler les lignes pour remplir l'espace disponible (évite le blanc en bas)
        // floor × 0.1 pour rester en dessous de pageAvail même avec les arrondis
        $spreadH = max($rH, floor(($pageAvail - 1.0 - $hH) / $nRows * 10) / 10);
    } else {
        $spreadH = $rH;
    }

    // Construire $S avec les tailles effectives pour cette épreuve si nécessaire
    $S = $DEF;
    if ($scale < 1.0) {
        $S['fData'] = $fD;
        $S['fHdr']  = $fH;
        $S['fTitle'] = max(8,  (int)round($DEF['fTitle'] * $scale));
        $S['fSub']   = max(7,  (int)round($DEF['fSub']   * $scale));
        $S['fLabel'] = max(6,  (int)round($DEF['fLabel'] * $scale));
        $S['hRow']  = $spreadH;
        $S['hHdr']  = $hH;
        $S['hTitle'] = max(4.0, round($DEF['hTitle'] * $scale, 1));
        $S['hSub']   = max(3.0, round($DEF['hSub']   * $scale, 1));
    }

    // ── Titre ─────────────────────────────────────────────────────────────────
    applyColor($pdf, $evHex);
    $pdf->SetFont($pdf->FontStd, 'B', $S['fTitle']);
    $pdf->Cell($W, $S['hTitle'], $evName, 0, 1, 'C', 1);
    $pdf->SetFont($pdf->FontStd, 'B', $S['fSub']);
    $pdf->Cell($W, $S['hSub'], 'Classement à l\'issue du '.$tourLabel, 0, 1, 'C', 1);
    $pdf->SetDefaultColor();
    $marginX = $pdf->GetX();
    $pdf->Ln($LN_GAP);

    // ── En-tête colonnes (une fois par page) ──────────────────────────────────
    applyColor($pdf, $COLORS['rankHdr']);
    $pdf->SetFont($pdf->FontStd, 'B', $S['fHdr']);
    $pdf->SetX($marginX);
    $hdrStartY = $pdf->GetY();
    $pdf->Cell($wSec,  $S['hHdr'], '',          1, 0, 'C', 1);
    $pdf->Cell($wRk,   $S['hHdr'], 'Cl.',        1, 0, 'C', 1);
    $pdf->Cell($wClub, $S['hHdr'], 'Club',       1, 0, 'L', 1);
    $pdf->Cell($wPl,   $S['hHdr'], 'Pl. Poule', 1, 0, 'C', 1);
    $pdf->Cell($wPts,  $S['hHdr'], 'Pts',        1, 0, 'C', 1);
    $pdf->Cell($wDiff, $S['hHdr'], 'Diff.',      1, 0, 'C', 1);
    $pdf->Cell($wPS,   $S['hHdr'], 'Pts-sets',   1, 1, 'C', 1);
    
    // Bordure épaisse autour de l'en-tête
    $hdrH = $pdf->GetY() - $hdrStartY;
    $pdf->SetLineWidth(0.7);
    $pdf->Rect($marginX, $hdrStartY, $W, $hdrH, 'D');
    $pdf->SetLineWidth(0.1);
    $pdf->SetDefaultColor();

    // ── Sections ──────────────────────────────────────────────────────────────
    foreach ($sections as $idx => $section) {
        if (empty($section['parts'])) continue;

        // Couleur : 10% plus claire à chaque section successive
        $sectionColor = lightenColor($evHex, $idx * 30);

        $noRank = $section['noRank'];
        $isMain = $section['isMain'];
        $offset = $section['offset'];
        $sParts = $section['parts'];

        $sectionStartY = $pdf->GetY();

        // ── Lignes de données (décalées de $wSec pour laisser la colonne label) ──
        foreach ($sParts as $p) {
            $rankDisplay = $noRank ? '—' : ($p->ComputedRank + $offset);

            $pdf->SetX($marginX + $wSec);
            $pdf->SetFont($pdf->FontStd, 'B', $S['fData']);
            $sectionRankSub2 = lightenColor($COLORS['rankSub'], $idx * 30);
            applyColor($pdf, $sectionRankSub2);
            $pdf->Cell($wRk, $S['hRow'], $rankDisplay, 1, 0, 'C', 1);
            $pdf->SetDefaultColor();

            // Nom en rouge si poule principale
            if ($isMain) {
                [$tr,$tg,$tb] = hexToRgb($COLORS['teamName']);
                $pdf->SetTextColor($tr,$tg,$tb);
            }
            $pdf->SetFont($pdf->FontStd, 'B', $S['fData']);
            $pdf->Cell($wClub, $S['hRow'], $p->ClubName, 1, 0, 'L', 0);
            $pdf->SetDefaultColor();

            $pdf->SetFont($pdf->FontFix, '', $S['fData']);
            $pdf->Cell($wPl,   $S['hRow'], $p->RrPartGroupRankBefSO, 1, 0, 'C', 0);
            $pdf->Cell($wPts,  $S['hRow'], $p->RrPartPoints,          1, 0, 'C', 0);
            $pdf->Cell($wDiff, $S['hRow'], $p->RrPartTieBreaker,      1, 0, 'C', 0);
            $pdf->Cell($wPS,   $S['hRow'], $p->RrPartTieBreaker2,     1, 1, 'C', 0);
        }

        $sectionEndY = $pdf->GetY();
        $sectionH    = $sectionEndY - $sectionStartY;

        // ── Bordure épaisse autour de la section ──────────────────────────────
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($marginX, $sectionStartY, $W, $sectionH, 'D');
        $pdf->SetLineWidth(0.1);
        $pdf->SetDefaultColor();

        // ── Label vertical gauche (par-dessus la bordure) ─────────────────────
        drawVerticalLabel($pdf, $marginX, $sectionStartY, $wSec, $sectionH, $section['label1'], $section['label2'], $sectionColor, $S['fLabel']);

        $pdf->SetY($sectionEndY);
    }
}

// ── Nom du fichier ────────────────────────────────────────────────────────────
$evPart  = $allEvt ? 'toutes categories' : implode('+', array_map('strtoupper', $events));
$levPart = $allLev ? 'Tous les tours'      : 'Tour '.implode('+', $levelNums);
$filename = preg_replace('/[^\w\-\+]/', ' ', 'Classement '.$evPart.' - '.$levPart).'.pdf';

$pdf->Output($filename, 'I');