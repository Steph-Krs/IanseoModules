<?php
// =============================================================================
// PdfRanking.php — Classement général Round Robin par épreuve
// Tour 1 : split positional (PP / PC)
// Tour 2+: split par niveau sélectionné (PP = niveau bas / PC = niveau haut)
// Une page par (épreuve × tour).
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
function loadIrmTypes() {
    $rs = safe_r_sql("SELECT IrmId, IrmType FROM IrmTypes");
    $map = [];
    while ($r = safe_fetch($rs)) $map[(int)$r->IrmId] = $r->IrmType;
    return $map;
}

// ── Classement avec gestion des égalités ─────────────────────────────────────
// Affecte $p->ComputedRank (1-indexed, partagé en cas d'égalité)
function assignRanks(&$parts) {
    $n = count($parts);
    for ($i = 0; $i < $n; ) {
        $rankVal = $i + 1;
        $k = [
            intval($parts[$i]->RrPartGroupRank),
            intval($parts[$i]->RrPartPoints),
            intval($parts[$i]->RrPartTieBreaker),
            intval($parts[$i]->RrPartTieBreaker2),
        ];
        $j = $i;
        while ($j < $n) {
            $kj = [
                intval($parts[$j]->RrPartGroupRank),
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
    intval($a->RrPartIrmType)        - intval($b->RrPartIrmType)
    ?: intval($a->RrPartGroupRank)      - intval($b->RrPartGroupRank)
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
    $pdf->Rect($x, $y, $w, $h, 'FD');
    $pdf->SetLineWidth(0.1);

    $cx = $x + $w / 2;
    $cy = $y + $h / 2;

    $pdf->SetFont($pdf->FontStd, 'B', $vertLabelSize);
    $wT1 = $pdf->GetStringWidth($text1);
    $hT1 = $vertLabelSize *0.5;
    $T1x = $cx - $hT1;
    $T1y = $cy + $wT1/2;
    $pdf->Rotate(90, $T1x, $T1y);
    $pdf->Text($T1x, $T1y, $text1);
    $pdf->Rotate(0);

    $pdf->SetFont($pdf->FontStd, '', $vertLabelSize);
    $wT2 = $pdf->GetStringWidth($text2);
    $hT2 = $vertLabelSize *0.5;
    $T2x = $cx;
    $T2y = $cy + $wT2/2;
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
$evRows = [];
while ($ev = safe_fetch($rsEvList)) $evRows[] = $ev;


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
$wBso  = 32;   // nouvelle colonne BSO (uniquement Tour 3)
$wClub = $W - $wSec - $wRk - $wPl - $wPts - $wDiff - $wPS; // = 102mm

// =============================================================================
// PDF
// =============================================================================
$pdf       = new ResultPDF('Classement Round Robin', true);
$firstPage = true;
$colorMap  = loadAccColors($tourId);
$irmTypes  = loadIrmTypes();

foreach ($evRows as $ev) {
    $evCode = $ev->EvCode;
    $evName = get_text($ev->EvEventName, '', '', true);
    $evHex  = ($useAccColors ? getEventColor($evCode, $colorMap) : null) ?? $COLORS['evFallback'];

    // ── BSO par épreuve ───────────────────────────────────────────────────────
    $bsoCount  = intval(getTNMEventValue($tourId, $evCode, 'bso_count', 10));
    $mainLimit = $bsoCount * 2;

    // ── Niveaux à rendre pour cette épreuve ───────────────────────────────────
    // Chaque niveau génère une page distincte.
    if ($allLev) {
        $rsLevs = safe_r_sql(
            "SELECT DISTINCT RrPartLevel FROM RoundRobinParticipants
             WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode)."
             AND RrPartParticipant!=0 ORDER BY RrPartLevel"
        );
        $levelsForEvent = [];
        while ($lv = safe_fetch($rsLevs)) $levelsForEvent[] = (int)$lv->RrPartLevel;
    } else {
        $levelsForEvent = $levelNums;
    }

    foreach ($levelsForEvent as $currentLevel) {

        // ── Participants de ce niveau ─────────────────────────────────────────
        $rs = safe_r_sql(
            "SELECT p.*, co.CoName as ClubName
             FROM RoundRobinParticipants p
             LEFT JOIN Countries co ON co.CoId=p.RrPartParticipant AND co.CoTournament=p.RrPartTournament
             WHERE p.RrPartTournament=$tourId
             AND p.RrPartEvent=".StrSafe_DB($evCode)."
             AND p.RrPartLevel=$currentLevel
             AND co.CoName IS NOT NULL AND co.CoName != ''"
        );
        $allParts = [];
        while ($p = safe_fetch($rs)) $allParts[] = $p;
        if (empty($allParts)) continue;

        usort($allParts, $sortFn);

        $isTour3 = ($currentLevel === 3);

        // ── Données BSO (Tour 3 uniquement) ───────────────────────────────────
        $bsoByTeam = [];
        $bsoMaxRound = 0;
        if ($isTour3) {
            $rs = safe_r_sql("SELECT BvTeam, BvRound, BvScore, BvRank FROM TNM_BsoVolee
                WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)." ORDER BY BvRound ASC");
            while ($r = safe_fetch($rs)) {
                $team = (int)$r->BvTeam;
                $bsoByTeam[$team]['scores'][(int)$r->BvRound] = $r->BvScore !== null ? (int)$r->BvScore : null;
                if ($r->BvRank !== null) $bsoByTeam[$team]['rank'] = (int)$r->BvRank;
                $bsoMaxRound = max($bsoMaxRound, (int)$r->BvRound);
            }
        }

        // ── Construction des sections ─────────────────────────────────────────
        if ($currentLevel === 1) {

            // ── TOUR 1 : split selon l'affectation réelle Tour 2 (niveau 2) ──
            $rsLevInfo = safe_r_sql(
                "SELECT RrLevGroupArchers FROM RoundRobinLevel
                WHERE RrLevTournament=$tourId AND RrLevEvent=".StrSafe_DB($evCode)." AND RrLevLevel=2"
            );
            $teamsPerPool = 4;
            if ($li = safe_fetch($rsLevInfo)) $teamsPerPool = max(2, intval($li->RrLevGroupArchers));
            $nPoolsMain = intdiv($mainLimit, $teamsPerPool);

            $rsAssign = safe_r_sql(
                "SELECT RrPartParticipant, RrPartGroup FROM RoundRobinParticipants
                WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode)."
                AND RrPartLevel=2 AND RrPartParticipant!=0"
            );
            $isMainTeam = [];
            while ($a = safe_fetch($rsAssign))
                $isMainTeam[intval($a->RrPartParticipant)] = intval($a->RrPartGroup) <= $nPoolsMain;

            assignRanks($allParts);
            $mainParts  = [];
            $classParts = [];
            foreach ($allParts as $p) {
                $coId = intval($p->RrPartParticipant);
                if (isset($isMainTeam[$coId])) {
                    $isMainTeam[$coId] ? $mainParts[] = $p : $classParts[] = $p;
                } else {
                    $p->ComputedRank <= $mainLimit ? $mainParts[] = $p : $classParts[] = $p;
                }
            }
            $nMain  = count($mainParts);
            $nClass = count($classParts);

            $sections = [
                [
                    'label1'=>'Poules principales',
                    'label2'=>$mainLimit.' qualifiés',
                    'parts' =>$mainParts,
                    'noRank'=>true,
                    'isMain'=>true,
                    'offset'=>0,
                    'bsoRank'=>false,
                ],
                [
                    'label1'=>'Poules de classement',
                    'label2'=>($nMain+1).' à '.($nMain+$nClass),
                    'parts' =>$classParts,
                    'noRank'=>true,
                    'isMain'=>false,
                    'offset'=>0,
                    'bsoRank'=>false,
                ],
            ];
            $tourLabel = 'Tour 1';

        } else {

            // ── TOUR 2+ : split par numéro de poule ───────────────────────────
            // Tour 3 n'a pas ses propres RoundRobinParticipants : on réutilise ceux
            // du Tour 2 pour le split PP/PC et les sections de classement.
            $sourceLevel = $isTour3 ? 2 : $currentLevel;

            if ($isTour3) {
                $rs2 = safe_r_sql(
                    "SELECT p.*, co.CoName as ClubName
                    FROM RoundRobinParticipants p
                    LEFT JOIN Countries co ON co.CoId=p.RrPartParticipant AND co.CoTournament=p.RrPartTournament
                    WHERE p.RrPartTournament=$tourId
                    AND p.RrPartEvent=".StrSafe_DB($evCode)."
                    AND p.RrPartLevel=2
                    AND co.CoName IS NOT NULL AND co.CoName != ''"
                );
                $allParts2 = [];
                while ($p = safe_fetch($rs2)) $allParts2[] = $p;
                if (empty($allParts2)) continue;
                usort($allParts2, $sortFn);
            } else {
                $allParts2 = $allParts;
            }

            $rsLevInfo = safe_r_sql(
                "SELECT RrLevGroupArchers FROM RoundRobinLevel
                WHERE RrLevTournament=$tourId
                AND RrLevEvent=".StrSafe_DB($evCode)."
                AND RrLevLevel=$sourceLevel"
            );
            $teamsPerPool = 4;
            if ($li = safe_fetch($rsLevInfo))
                $teamsPerPool = max(2, intval($li->RrLevGroupArchers));

            $nPoolsMain = intdiv($bsoCount * 2, $teamsPerPool);

            $mainParts  = array_values(array_filter($allParts2, fn($p) => intval($p->RrPartGroup) <= $nPoolsMain));
            $classParts = array_values(array_filter($allParts2, fn($p) => intval($p->RrPartGroup) >  $nPoolsMain));

            usort($mainParts,  $sortFn);
            usort($classParts, $sortFn);
            assignRanks($mainParts);
            assignRanks($classParts);

            $bsoParts  = array_slice($mainParts, 0, $bsoCount);
            $mainClass = array_slice($mainParts, $bsoCount);
            $nMain     = count($mainParts);
            $nClass    = count($classParts);

            if ($isTour3) {
                usort($bsoParts, function($a, $b) use ($bsoByTeam) {
                    $ra = $bsoByTeam[(int)$a->RrPartParticipant]['rank'] ?? 9999;
                    $rb = $bsoByTeam[(int)$b->RrPartParticipant]['rank'] ?? 9999;
                    return $ra - $rb;
                });

                $sections = [
                    [
                        'label1'=>'Big Shoot Off',
                        'label2'=>'1 à '.$bsoCount,
                        'parts' =>$bsoParts,
                        'noRank'=>false,
                        'isMain'=>true,
                        'offset'=>0,
                        'bsoRank'=>true,
                    ],
                    [
                        'label1'=>'Classement',
                        'label2'=>($bsoCount+1).' à '.$nMain,
                        'parts' =>$mainClass,
                        'noRank'=>false,
                        'isMain'=>false,
                        'offset'=>0,
                        'bsoRank'=>false,
                    ],
                    [
                        'label1'=>'Classement',
                        'label2'=>($nMain+1).' à '.($nMain+$nClass),
                        'parts' =>$classParts,
                        'noRank'=>false,
                        'isMain'=>false,
                        'offset'=>$nMain,
                        'bsoRank'=>false,
                    ],
                ];
                $tourLabel = 'BSO (Classement final)';
            } else {
                $sections = [
                    [
                        'label1'=>'Big Shoot Off',
                        'label2'=>$bsoCount.' qualifiés',
                        'parts' =>$bsoParts,
                        'noRank'=>true,
                        'isMain'=>true,
                        'offset'=>0,
                        'bsoRank'=>false,
                    ],
                    [
                        'label1'=>'Classement',
                        'label2'=>($bsoCount+1).' à '.$nMain,
                        'parts' =>$mainClass,
                        'noRank'=>false,
                        'isMain'=>false,
                        'offset'=>0,
                        'bsoRank'=>false,
                    ],
                    [
                        'label1'=>'Classement',
                        'label2'=>($nMain+1).' à '.($nMain+$nClass),
                        'parts' =>$classParts,
                        'noRank'=>false,
                        'isMain'=>false,
                        'offset'=>$nMain,
                        'bsoRank'=>false,
                    ],
                ];
                $tourLabel = 'Tour '.$currentLevel;
            }
        }

        // ── Nouvelle page (AVANT le scale pour lire les marges réelles) ───────
        if ($firstPage) { $firstPage = false; } else { $pdf->AddPage(); }

        // ── Auto-scale : tout le classement tient sur 1 page ─────────────────
        $PT_MM  = 25.4 / 72;
        $CHR    = 1.25;
        $margins   = $pdf->getMargins();
        $LN_GAP    = 4;
        $pageAvail = $pdf->getPageHeight() - $margins['top'] - $margins['bottom'] - $DEF['hTitle'] - $DEF['hSub'] - $LN_GAP;
        $nRows     = array_sum(array_map(fn($s) => count($s['parts']), $sections));

        $scale = 1.0;
        $fD    = $DEF['fData'];
        $fH    = $DEF['fHdr'];
        $rH    = max($DEF['hRow'], $fD * $PT_MM * $CHR);
        $hH    = max($DEF['hHdr'], $fH * $PT_MM * $CHR);

        if ($nRows > 0) {
            for ($iter = 0; $iter < 25; $iter++) {
                $fD  = max(6, (int)round($DEF['fData'] * $scale));
                $fH  = max(6, (int)round($DEF['fHdr']  * $scale));
                $rH  = $fD * $PT_MM * $CHR;
                $hH  = max($fH * $PT_MM * $CHR, round($DEF['hHdr'] * $scale, 1));
                $predicted = $hH + $nRows * $rH;
                if ($predicted <= $pageAvail) break;
                $scale = max(0.3, $scale * ($pageAvail - 0.5) / max(0.1, $predicted));
            }

            $spreadH = max($rH, floor(($pageAvail - 1.0 - $hH) / $nRows * 10) / 10);
        } else {
            $spreadH = $rH;
        }

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

        $wClubPage = $isTour3
            ? $W - $wSec - $wRk - $wBso - $wPl - $wPts - $wDiff - $wPS
            : $W - $wSec - $wRk - $wPl - $wPts - $wDiff - $wPS;

        // ── Titre ─────────────────────────────────────────────────────────────
        
        if ($currentLevel===1){
            $subTitle = 'Répartition à l\'issue du '.$tourLabel;
        } elseif ($currentLevel===2){
            $subTitle = 'Classement provisoire à l\'issue du '.$tourLabel;
        } elseif ($currentLevel===3){
            $subTitle = 'Classement final ';
        }
        applyColor($pdf, $evHex);
        $pdf->SetFont($pdf->FontStd, 'B', $S['fTitle']);
        $pdf->Cell($W, $S['hTitle'], $evName, 0, 1, 'C', 1);
        $pdf->SetFont($pdf->FontStd, 'B', $S['fSub']);
        $pdf->Cell($W, $S['hSub'], $subTitle, 0, 1, 'C', 1);
        $pdf->SetDefaultColor();
        $marginX = $pdf->GetX();
        $pdf->Ln($LN_GAP);

        // ── En-tête colonnes ──────────────────────────────────────────────────
        applyColor($pdf, $COLORS['rankHdr']);
        $pdf->SetFont($pdf->FontStd, 'B', $S['fHdr']);
        $pdf->SetX($marginX);
        $hdrStartY = $pdf->GetY();
        $pdf->Cell($wSec,      $S['hHdr'], '',          1, 0, 'C', 1);
        $pdf->Cell($wRk,       $S['hHdr'], 'Cl.',        1, 0, 'C', 1);
        $pdf->Cell($wClubPage, $S['hHdr'], 'Club',       1, 0, 'L', 1);
        if ($isTour3) $pdf->Cell($wBso, $S['hHdr'], 'BSO', 1, 0, 'C', 1);
        $pdf->Cell($wPl,       $S['hHdr'], 'Pl. Poule', 1, 0, 'C', 1);
        $pdf->Cell($wPts,      $S['hHdr'], 'Pts',        1, 0, 'C', 1);
        $pdf->Cell($wDiff,     $S['hHdr'], 'Diff.',      1, 0, 'C', 1);
        $pdf->Cell($wPS,       $S['hHdr'], 'Pts-sets',   1, 1, 'C', 1);

        $hdrH = $pdf->GetY() - $hdrStartY;
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($marginX, $hdrStartY, $W, $hdrH, 'D');
        $pdf->SetLineWidth(0.1);
        $pdf->SetDefaultColor();

        // ── Sections ──────────────────────────────────────────────────────────
        foreach ($sections as $idx => $section) {
            if (empty($section['parts'])) continue;

            $sectionColor = lightenColor($evHex, $idx * 30);

            $noRank = $section['noRank'];
            $isMain = $section['isMain'];
            $offset = $section['offset'];
            $sParts = $section['parts'];

            // Largeur Club selon le contexte BSO de cette section
            $wClubRow = ($isTour3 && $isMain)
                ? $W - $wSec - $wRk - $wBso - $wPl - $wPts - $wDiff - $wPS
                : $W - $wSec - $wRk - $wPl - $wPts - $wDiff - $wPS;

            $sectionStartY = $pdf->GetY();

            foreach ($sParts as $p) {
                $coId = (int)$p->RrPartParticipant;
                $irm  = (int)$p->RrPartIrmType;

                if ($irm > 0) {
                    $rankDisplay = $irmTypes[$irm] ?? '—';
                } elseif (!empty($section['bsoRank'])) {
                    $rankDisplay = $bsoByTeam[$coId]['rank'] ?? '—';
                } else {
                    $rankDisplay = $noRank ? '—' : ($p->ComputedRank + $offset);
                }

                $pdf->SetX($marginX + $wSec);
                $pdf->SetFont($pdf->FontStd, 'B', $S['fData']);
                $sectionRankSub2 = lightenColor($COLORS['rankSub'], $idx * 30);
                applyColor($pdf, $sectionRankSub2);
                $pdf->Cell($wRk, $S['hRow'], $rankDisplay, 1, 0, 'C', 1);
                $pdf->SetDefaultColor();

                if ($isMain) {
                    [$tr,$tg,$tb] = hexToRgb($COLORS['teamName']);
                    $pdf->SetTextColor($tr,$tg,$tb);
                }
                $pdf->SetFont($pdf->FontStd, 'B', $S['fData']);
                $pdf->Cell($wClubRow, $S['hRow'], $p->ClubName, 1, 0, 'L', 0);
                $pdf->SetDefaultColor();

                if ($isTour3 && $isMain) {
                    $scoresTxt = '';
                    if (isset($bsoByTeam[$coId]['scores'])) {
                        $scoreParts = [];
                        for ($rd = 1; $rd <= $bsoMaxRound; $rd++) {
                            if (array_key_exists($rd, $bsoByTeam[$coId]['scores']) && $bsoByTeam[$coId]['scores'][$rd] !== null) {
                                $scoreParts[] = $bsoByTeam[$coId]['scores'][$rd];
                            }
                        }
                        $scoresTxt = implode(' - ', $scoreParts);
                    }
                    $pdf->SetFont($pdf->FontFix, '', $S['fData']);
                    $pdf->Cell($wBso, $S['hRow'], $scoresTxt, 1, 0, 'L', 0);
                }

                $pdf->SetFont($pdf->FontFix, '', $S['fData']);
                $pdf->Cell($wPl,   $S['hRow'], $p->RrPartGroupRank,   1, 0, 'C', 0);
                $pdf->Cell($wPts,  $S['hRow'], $p->RrPartPoints,       1, 0, 'C', 0);
                $pdf->Cell($wDiff, $S['hRow'], $p->RrPartTieBreaker,   1, 0, 'C', 0);
                $pdf->Cell($wPS,   $S['hRow'], $p->RrPartTieBreaker2,  1, 1, 'C', 0);
            }

            $sectionEndY = $pdf->GetY();
            $sectionH    = $sectionEndY - $sectionStartY;

            $pdf->SetLineWidth(0.7);
            $pdf->Rect($marginX, $sectionStartY, $W, $sectionH, 'D');
            $pdf->SetLineWidth(0.1);
            $pdf->SetDefaultColor();

            drawVerticalLabel($pdf, $marginX, $sectionStartY, $wSec, $sectionH, $section['label1'], $section['label2'], $sectionColor, $S['fLabel']);

            $pdf->SetY($sectionEndY);
        }

    } // end foreach $levelsForEvent
} // end foreach $evRows

// ── Nom du fichier ────────────────────────────────────────────────────────────
$evPart  = $allEvt ? 'toutes categories' : implode('+', array_map('strtoupper', $events));
$levPart = $allLev ? 'Tous les tours'    : 'Tour '.implode('+', $levelNums);
$filename = preg_replace('/[^\w\-\+]/', ' ', 'Classement '.$evPart.' - '.$levPart).'.pdf';

$pdf->Output($filename, 'I');
