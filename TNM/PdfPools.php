<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);
require_once($CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php');


function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}

// Choisit blanc ou noir selon la luminance du fond
function autoTextColor($rBg, $gBg, $bBg) {
    return (0.299*$rBg + 0.587*$gBg + 0.114*$bBg) < 128
        ? [255, 255, 255]   // fond foncé → texte blanc
        : [0, 0, 0];        // fond clair → texte noir
}

// Charge toutes les couleurs AccColors une fois
function loadAccColors($tourId) {
    $rs = safe_r_sql("SELECT AcDivClass, AcColor FROM AccColors WHERE AcTournament = $tourId");
    $map = [];
    while ($r = safe_fetch($rs)) $map[] = ['pattern' => $r->AcDivClass, 'color' => $r->AcColor];
    return $map;
}

// Retourne le code hex (#RRGGBB) correspondant à un code épreuve, ou null
function getEventColor($evCode, $colorMap) {
    foreach ($colorMap as $entry) {
        // preg_split gère les espaces multiples, tabs, etc.
        $patterns = preg_split('/\s+/', trim($entry['pattern']), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($patterns as $pat) {
            $regex = '/^' . str_replace('%', '.*', preg_quote($pat, '/')) . '$/i';
            if (preg_match($regex, $evCode)) {
                return '#' . ltrim($entry['color'], '#'); // ltrim sécurise si # déjà présent en BDD
            }
        }
    }
    return null;
}

// ── Noms d'équipes (null = BYE) ───────────────────────────────────────────────
function getTeamNames($tourId) {
    $rs = safe_r_sql(
        "SELECT CoId, CoName, CoCode FROM Countries WHERE CoTournament = $tourId"
    );
    $result = [];
    while ($r = safe_fetch($rs)) {
        $result[intval($r->CoId)] = !empty($r->CoName)
            //? $r->CoCode . ' – ' . $r->CoName
            ? $r->CoName
            : null;  // null = BYE
    }
    return $result;
}

// ── Rendu d'une ligne équipe dans un match ────────────────────────────────────
function printMatchRow($pdf, $m, $teamNames, $colTgt, $colName, $maxEnds, $endW, $colSetSc, $colTb, $hasTiebreak, $isLooser, $showScore, $mBye) {
    $name        = $teamNames[intval($m->RrMatchAthlete)] ?? null;
    $displayName = ($name === null || $name === '0') ? '-- Bye --' : $name;
    $hasScore = $showScore && intval($m->RrMatchScore) > 0;
    $colScAll    = $maxEnds * $endW + $colSetSc + ($hasTiebreak ? $colTb : 0);
    $byeValue = $mBye ? (($name === null || $name === '0') ? '0' : '6') : '';

    $pdf->SetFont($pdf->FontStd, '', 15);
    $pdf->Cell($colTgt, 4, ltrim($m->RrMatchTarget, '0') ?: '0', 1, 0, 'C', 0);
    $pdf->SetFont($pdf->FontStd, ($name === null || $name === '0') ? '' : ($showScore ? ($isLooser ? 'I' : 'B') : 'B'), 15);
    $pdf->Cell($colName, 4, $displayName,       1, 0, 'L', 0);

    if (!$hasScore) {
        // Pas de résultat : une seule cellule vide
        $pdf->Cell($colScAll, 4, $byeValue, 1, 1, 'C', 0);
    } else {
        // Volées
        $endScores = array_values(
            array_filter(explode('|', trim($m->RrMatchSetPoints ?? '')), 'strlen')
        );
        while (count($endScores) < $maxEnds) $endScores[] = '';

        $pdf->SetFont($pdf->FontFix, '', 15);
        foreach ($endScores as $s) {
            $pdf->Cell($endW, 4, $s ?: '', 1, 0, 'C', 0);
        }
        // Total sets
        $pdf->SetFont($pdf->FontFix, $showScore ? ($isLooser ? 'I' : 'B') : 'B', 15);
        $pdf->Cell($colSetSc, 4, $m->RrMatchSetScore ?? '', 1, 0, 'C', 0);
        // Barrage (toujours présent si $hasTiebreak, vide si ce match n'en a pas)
        if ($hasTiebreak) {
            $pdf->SetFont($pdf->FontFix, 'I', 15);
            $pdf->Cell($colTb, 4, $m->RrMatchTbDecoded ?? '', 1, 0, 'C', 0);
        }
        $pdf->Ln();
    }
}

// ── Paramètres request ────────────────────────────────────────────────────────
$tourId      = intval($_SESSION['TourId']);
$events      = (array)($_REQUEST['event'] ?? ['.']);
$levels      = (array)($_REQUEST['level'] ?? ['.']);   // désormais de simples entiers
$groups      = (array)($_REQUEST['group'] ?? ['.']);   // idem
$withResults = !empty($_REQUEST['withResults']);

$allEvt    = in_array('.', $events);
$allLev    = in_array('.', $levels);
$allGrp    = in_array('.', $groups);
$levelNums = $allLev ? [] : array_map('intval', $levels);
$groupNums = $allGrp ? [] : array_map('intval', $groups);

// Filtres niveau  : "EvCode:LevelNo"
$levFilters = [];
if (!$allLev) {
    foreach ($levels as $lk) {
        $p = explode(':', $lk, 2);
        if (count($p) === 2) $levFilters[$p[0]][] = intval($p[1]);
    }
}
// Filtres groupe  : "EvCode:LevelNo:GroupNo"
$grpFilters = [];
if (!$allGrp) {
    foreach ($groups as $gk) {
        $p = explode(':', $gk, 3);
        if (count($p) === 3) $grpFilters[$p[0].':'.$p[1]][] = intval($p[2]);
    }
}

// ── Requête niveaux ───────────────────────────────────────────────────────────
$evSQL = $allEvt ? '' :
    "AND l.RrLevEvent IN (" . implode(',', array_map('StrSafe_DB', $events)) . ")";

$rsLev = safe_r_sql(
    "SELECT l.*, e.EvEventName
     FROM RoundRobinLevel l
     JOIN Events e ON e.EvTournament = l.RrLevTournament AND e.EvCode = l.RrLevEvent
     WHERE l.RrLevTournament = $tourId $evSQL
     ORDER BY l.RrLevEvent, l.RrLevLevel"
);

// ── Dimensions fixes ──────────────────────────────────────────────────────────
$W        = 190;   // largeur utile A4
$endW     = 8;     // mm par colonne de volée
$colTgtM  = 15;    // Cible (section matchs)
$colSetSc = 12;    // Total sets

// Classement
$wPl  = 8;
$wPts = 20;
$wTB  = 10;
$wRnd = 20;
$wCl  = 15;

// Couleur Trophée National des Mixtes
[$rBtnm, $gBtnm, $bBtnm] = hexToRgb('#002B92');
[$trBtnm, $tgBtnm, $tbBtnm] = autoTextColor($rBtnm, $gBtnm, $bBtnm);
[$rB2tnm, $gB2tnm, $bB2tnm] = hexToRgb('#406BD2');
[$trB2tnm, $tgB2tnm, $tbB2tnm] = autoTextColor($rB2tnm, $gB2tnm, $bB2tnm);
[$rRtnm, $gRtnm, $bRtnm] = hexToRgb('#F90A72');
[$trRtnm, $tgRtnm, $tbRtnm] = autoTextColor($rRtnm, $gRtnm, $bRtnm);


// ── PDF ───────────────────────────────────────────────────────────────────────
$pdf       = new ResultPDF('Poules – Round Robin', true);
$firstPage = true;

$colorMap = loadAccColors($tourId);

while ($lev = safe_fetch($rsLev)) {
    
    $evHex = getEventColor($lev->RrLevEvent, $colorMap) ?? '#c0c980'; // fallback bleu
    [$rEv, $gEv, $bEv] = hexToRgb($evHex);
    [$tr, $tg, $tb] = autoTextColor($rEv, $gEv, $bEv);

    // Filtre niveau
    if (!$allLev && !in_array(intval($lev->RrLevLevel), $levelNums)) continue;

    $evName    = get_text($lev->EvEventName, '', '', true);
    $teamNames = getTeamNames($tourId);

    $rsGrp = safe_r_sql(
        "SELECT * FROM RoundRobinGroup
         WHERE RrGrTournament = $tourId
         AND   RrGrEvent      = " . StrSafe_DB($lev->RrLevEvent) . "
         AND   RrGrLevel      = " . intval($lev->RrLevLevel) . "
         ORDER BY RrGrGroup"
    );

    while ($grp = safe_fetch($rsGrp)) {

        $levGrpKey = $lev->RrLevEvent . ':' . $lev->RrLevLevel;

        // Filtre groupe
        if (!$allGrp && !in_array(intval($grp->RrGrGroup), $groupNums)) continue;

        // ── Participants ──────────────────────────────────────────────────────
        $rsPart = safe_r_sql(
            "SELECT * FROM RoundRobinParticipants
             WHERE RrPartTournament = $tourId
             AND   RrPartEvent      = " . StrSafe_DB($lev->RrLevEvent) . "
             AND   RrPartLevel      = " . intval($lev->RrLevLevel) . "
             AND   RrPartGroup      = " . intval($grp->RrGrGroup) . "
             ORDER BY RrPartDestItem"
        );
        $parts = [];
        while ($p = safe_fetch($rsPart)) $parts[] = $p;

        // ── Matchs ───────────────────────────────────────────────────────────
        $rsMatch = safe_r_sql(
            "SELECT * FROM RoundRobinMatches
             WHERE RrMatchTournament = $tourId
             AND   RrMatchEvent      = " . StrSafe_DB($lev->RrLevEvent) . "
             AND   RrMatchLevel      = " . intval($lev->RrLevLevel) . "
             AND   RrMatchGroup      = " . intval($grp->RrGrGroup) . "
             ORDER BY RrMatchRound, RrMatchMatchNo"
        );

        $rounds   = [];   // [round] => [row, row, …]
        $matchPts = [];   // [athlete][round] => roundPoints
        $maxEnds  = intval($lev->RrLevEnds);

        while ($m = safe_fetch($rsMatch)) {
            $rounds[$m->RrMatchRound][] = $m;
            $matchPts[intval($m->RrMatchAthlete)][$m->RrMatchRound] = $m->RrMatchRoundPoints;
            // Nombre réel de volées (peut dépasser RrLevEnds si barrage)
            if (!empty($m->RrMatchSetPoints)) {
                $cnt = count(array_filter(explode('|', trim($m->RrMatchSetPoints)), 'strlen'));
                $maxEnds = max($maxEnds, $cnt);
            }
            // Fallback nom si absent de Entries
            if (!array_key_exists($m->RrMatchAthlete, $teamNames)) {
                $teamNames[intval($m->RrMatchAthlete)] = $m->RrMatchAthlete;
            }
        }

        $nRounds  = count($rounds);
        $colScAll = $maxEnds * $endW + $colSetSc;            // largeur bloc scores
        $colNameM = max(50, $W - $colTgtM - $colScAll);      // colonne nom (matchs)
        $colNameS = max(35, $W - $wPl - $wPts - $wTB*2 - ($nRounds * $wRnd) - $wCl ); // classement

        // ── Nouvelle page ─────────────────────────────────────────────────────
        if ($firstPage) { $firstPage = false; }
        else            { $pdf->AddPage(); }

        // ════════════════════════════════════════════════════════════════════
        // EN-TÊTE POULE
        // ════════════════════════════════════════════════════════════════════
        // Date de la poule (date du premier match)
        $headerDate = '';
        foreach ($rounds as $rows) {
            if (!empty($rows)) {
                $firstMatch = $rows[0];
                $dt = $firstMatch->RrMatchScheduledDate ?? '';

                if ($dt && $dt !== '0000-00-00') {
                    $p = explode('-', $dt);
                    if (count($p) === 3) {
                        $headerDate = $p[2] . '/' . $p[1];
                    }
                }
                break;
            }
        }
        $pdf->SetFillColor($rEv, $gEv, $bEv);
        $pdf->SetTextColor($tr, $tg, $tb);
        $pdf->SetFont($pdf->FontStd, 'B', 20);
        $ptitle = $evName . ' - ' . ($lev->RrLevName ?: 'Tour '.$lev->RrLevLevel) . ' - ' . $headerDate;
        $pdf->Cell($W, 6, $ptitle, 0, 1, 'C', 1);
        $pdf->SetFont($pdf->FontStd, 'B', 30);
        $pdf->Cell($W, 5, $grp->RrGrName  ?: 'Poule '.$grp->RrGrGroup,  0, 1, 'C', 1);
        $pdf->SetDefaultColor();
        $pdf->Ln(3);
        $pdf->SetFont($pdf->FontStd, 'I', 10);
        $pdf->Cell($W, 5, 'Les résultats de match possibles sont : 6-0 / 5-1 / 6-2 / 5-3 / 5-4',  0, 1, 'C', 0);

        // ════════════════════════════════════════════════════════════════════
        // MATCHS — format 2 lignes par match
        // ════════════════════════════════════════════════════════════════════
        $matchCounter = 0;

foreach ($rounds as $round => $rows) {

    $matchCounter++;

    // heure du round
    $tm = $rows[0]->RrMatchScheduledTime ?? '';

    // Bandeau gris du round
    $pdf->SetFont($pdf->FontStd, 'B', 20);
    $pdf->Cell($W, 6, 'Match ' . $matchCounter . ($tm ? ' - ' . substr($tm, 0, 5) : ''), 1, 1, 'C', 1);
    
    // Entête tableau
    $pdf->SetFont($pdf->FontStd, 'B', 15);
    $pdf->Cell($colTgtM,  5, 'Cible',             1, 0, 'C', 1);
    $pdf->Cell($colNameM, 5, 'Club',              1, 0, 'L', 1);
    $pdf->Cell($colScAll, 5, 'Résultat du match', 1, 1, 'C', 1);
    $pdf->Ln(2);

    
    
    // Tous les matchs de ce round
    for ($i = 0; $i + 1 < count($rows); $i += 2) {

        $r1 = $rows[$i];
        $r2 = $rows[$i + 1];
        $name1 = $teamNames[intval($r1->RrMatchAthlete)] ?? null;
        $name2 = $teamNames[intval($r2->RrMatchAthlete)] ?? null;
        $mBye = ($name1 === null || $name1 === '0') || ($name2 === null || $name2 === '0');
        $hasTiebreak  = !empty($r1->RrMatchTbDecoded) || !empty($r2->RrMatchTbDecoded);
        $r1IsLooser = ($r1->RrMatchWinLose === '0' && ($r1->RrMatchWinLose === '1' || $r2->RrMatchWinLose) );
        $r2IsLooser = ($r2->RrMatchWinLose === '0' && ($r1->RrMatchWinLose === '1' || $r2->RrMatchWinLose === '1') );
        $colTb    = ($hasTiebreak ? 7 : 0);
        $endW = ($hasTiebreak ? ($endW-($colTb / $maxEnds)) : $endW);
        $colScMatch  = $maxEnds * $endW + $colSetSc + ($hasTiebreak ? $colTb : 0);
        $colName  = max(50, $W - $colTgtM - $colScMatch);

        // Team 1
        printMatchRow($pdf, $r1, $teamNames, $colTgtM, $colName, $maxEnds, $endW, $colSetSc, $colTb, $hasTiebreak, $r1IsLooser, $withResults, $mBye);
        // Team 2
        printMatchRow($pdf, $r2, $teamNames, $colTgtM, $colName, $maxEnds, $endW, $colSetSc, $colTb, $hasTiebreak, $r2IsLooser, $withResults, $mBye);
        $pdf->Ln(2);
    $endW = ($endW+($colTb / $maxEnds));
    $colScMatch  = $maxEnds * $endW + $colSetSc;
    $colName  = max(50, $W - $colTgtM - $colScMatch);
    $hasTiebreak  = False;
    }

    $pdf->Ln(8);
}

        $pdf->Ln(3);

        // ════════════════════════════════════════════════════════════════════
        // CLASSEMENT DE LA POULE
        // ════════════════════════════════════════════════════════════════════
        
        // Détecter si la poule a au moins un résultat
        $poolHasScore = false;
        foreach ($rounds as $rows) {
            foreach ($rows as $m) {
                if (intval($m->RrMatchScore) > 0) { $poolHasScore = true; break 2; }
            }
        }
        $pdf->SetFillColor($rBtnm, $gBtnm, $bBtnm);   // #002B92
        $pdf->SetTextColor($trBtnm, $tgBtnm, $tbBtnm);       // texte blanc
        $pdf->SetFont($pdf->FontStd, 'B', 20);
        $pdf->Cell($W, 5, 'Classement de la poule ' . $grp->RrGrGroup . ' - Tour ' . $lev->RrLevLevel, 1, 1, 'C', 1);

        // En-têtes colonnes
        $pdf->SetFillColor($rB2tnm, $gB2tnm, $bB2tnm);
        $pdf->SetTextColor($trB2tnm, $tgB2tnm, $tbB2tnm);
        $pdf->SetFont($pdf->FontStd, '', 15);
        $pdf->Cell($wPl,    4, 'Pl.',    1, 0, 'C', 1);
        $pdf->SetFont($pdf->FontStd, '', 15);
        $pdf->Cell($colNameS,4,'Équipe', 1, 0, 'L', 1);
        for ($r = 1; $r <= $nRounds; $r++)
            $pdf->Cell($wRnd, 4, "Match $r",  1, 0, 'C', 1);
        
        If ($withResults && $poolHasScore) {
            $pdf->SetFont($pdf->FontStd, 'B', 15);
            $pdf->Cell($wPts, 4, 'Points', 1, 0, 'C', 1);
            $pdf->SetFont($pdf->FontStd, 'I', 15);
            $pdf->Cell($wTB,  4, 'Diff.', 1, 0, 'C', 1);
            $pdf->Cell($wTB,  4, 'Pts sets', 1, 0, 'C', 1);
            $pdf->SetFont($pdf->FontStd, 'B', 15);
            $pdf->Cell($wCl,  4, 'Classement',  1, 1, 'C', 1);
        } else {
            // Pas de résultat dans la poule : on affiche quand même les points
            $pdf->SetFont($pdf->FontStd, 'B', 15);
            $pdf->Cell($wPts + $wTB * 2 + $wCl, 4, 'Cumul des points', 1, 1, 'C', 1);
        };

        $pdf->SetDefaultColor();

        // Données (triées par rang, BYE exclus)
        usort($parts, fn($a, $b) => intval($a->RrPartDestItem) <=> intval($b->RrPartDestItem));
        foreach ($parts as $p) {
            $name = $teamNames[intval($p->RrPartParticipant)] ?? null;
            if ($name === null || $name === '0') continue;  // BYE ignoré

            $pdf->SetFont($pdf->FontStd, '', 15);
            $pdf->Cell($wPl,     4, $p->RrPartDestItem, 1, 0, 'C', 0);
            $pdf->SetTextColor($rRtnm, $gRtnm, $bRtnm); 
            $pdf->SetFont($pdf->FontStd, 'B', 15);
            $pdf->Cell($colNameS,4, $name,               1, 0, 'L', 0);
            $pdf->SetDefaultColor();
            
            for ($r = 1; $r <= $nRounds; $r++) {
                $pts = $matchPts[intval($p->RrPartParticipant)][$r] ?? '';
                $pdf->SetFont($pdf->FontFix, '', 15);
                $pdf->Cell($wRnd, 4, $withResults && $poolHasScore ? $pts : '', 1, 0, 'C', 0);
            }

            If ($withResults && $poolHasScore) {
                $pdf->SetFont($pdf->FontFix, 'B', 15);
                $pdf->Cell($wPts, 4, $p->RrPartPoints,      1, 0, 'C', 0);
                $pdf->SetFont($pdf->FontFix, 'I', 15);
                $pdf->Cell($wTB,  4, $p->RrPartTieBreaker,  1, 0, 'C', 0);
                $pdf->Cell($wTB,  4, $p->RrPartTieBreaker2, 1, 0, 'C', 0);
                $pdf->SetFont($pdf->FontStd, 'B', 15);
                $pdf->Cell($wCl,  4, $p->RrPartGroupRankBefSO,   1, 1, 'C', 0);
            } else {
                // Pas de résultat dans la poule : une case vide
                $pdf->SetFont($pdf->FontFix, 'B', 15);
                $pdf->Cell($wPts + $wTB * 2 + $wCl, 4, '',      1, 1, 'C', 0);
            }
        }
        
        $pdf->SetFont($pdf->FontStd, 'I', 10);
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->MultiCell($colNameS+$wPl, 4, " \n ", 1, 'C', 0);
        $bottomY = $pdf->GetY();  // hauteur réelle après wrap
        $pdf->SetXY($x + $colNameS+$wPl, $y);  // revenir sur la même ligne, colonne suivante
        $pdf->MultiCell($wRnd*$nRounds, 4, "Victoire = 2\nDéfaite = 0", 1, 'C', 0);
        $pdf->SetXY($x + $colNameS+$wPl + $wRnd*$nRounds, $y);  // revenir sur la même ligne, colonne suivante
        $pdf->MultiCell($wPts + $wTB * 2 + $wCl, 4, "Cumuls des points possibles\n0 / 2 / 4 / 6", 1, 'C', 0);
        $pdf->SetY($bottomY);  // passer sous la ligne la plus haute
    }
}

$pdf->Output();