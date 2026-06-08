<?php
// =============================================================================
// PdfPools.php — Impression des poules Round Robin
// Custom/TNM — FFTA Trophée National des Mixtes
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);
require_once($CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php');

// =============================================================================
// CONFIGURATION — modifier ici pour ajuster l'apparence globale
// =============================================================================

// Tailles de base en points (pt) — scalées automatiquement si la poule ne tient pas sur 1 page
$BASE = [
    // Polices
    'fTitle'   => 20,   // Titre épreuve + tour + date
    'fPool'    => 30,   // Nom de la poule (grand)
    'fMhdr'    => 20,   // Bandeau de match / classement
    'fChdr'    => 15,   // En-têtes de colonnes
    'fData'    => 15,   // Données (scores, noms…)
    'fLeg'     => 10,   // Légendes / notes bas de page
    // Hauteurs de ligne (mm)
    'hTitle'   => 6,    // Titre épreuve
    'hPool'    => 5,    // Nom poule
    'hMhdr'    => 6,    // Bandeau match / classement
    'hChdr'    => 5,    // En-tête colonne
    'hRow'     => 4,    // Ligne de données
    // Largeurs colonnes (mm) — section matchs
    'colTgt'   => 15,   // Numéro de cible
    'colSetSc' => 12,   // Total de sets
    'colTbW'   => 7,    // Barrage (affiché seulement si match en barrage)
    'endW'     => 8,    // Largeur par volée
    // Largeurs colonnes (mm) — section classement
    'wPl'      => 8,    // Pl.
    'wPts'     => 20,   // Points totaux
    'wTB'      => 10,   // Tiebreaker (×2)
    'wRnd'     => 20,   // Points par match
    'wCl'      => 15,   // Classement final
    //
    'W'        => 190   // largeur utile A4
];

// Couleurs (toutes en hex)
$COLORS = [
    'evFallback'  => '#dfdfdf',   // couleur épreuve si aucune AccColor trouvée
    'rankHdr'     => '#002B92',   // fond titre classement                      - Bleu TNM
    'rankSub'     => '#406BD2',   // fond sous-en-tête classement
    'teamName'    => '#F90A72',   // couleur du nom d'équipe dans le classement - Rouge TNM
];

// Hauteur utile A4 portrait (mm) après marges Ianseo (top≈25, bottom≈10, sécurité 5)
define('PAGE_H', 257);

// =============================================================================
// HELPERS
// =============================================================================

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

// Applique une couleur de fond + texte auto (blanc/noir selon luminance)
function applyColor($pdf, $hex) {
    [$r,$g,$b] = hexToRgb($hex);
    [$tr,$tg,$tb] = autoTextColor($r,$g,$b);
    $pdf->SetFillColor($r,$g,$b);
    $pdf->SetTextColor($tr,$tg,$tb);
}

// Charge toutes les couleurs AccColors une fois
function loadAccColors($tourId) {
    $rs = safe_r_sql("SELECT AcDivClass, AcColor FROM AccColors WHERE AcTournament=$tourId");
    $map = [];
    while ($r = safe_fetch($rs)) $map[] = ['pattern'=>$r->AcDivClass, 'color'=>$r->AcColor];
    return $map;
}

// Retourne le code hex (#RRGGBB) correspondant à un code épreuve, ou null
function getEventColor($evCode, $colorMap) {
    foreach ($colorMap as $entry) {
        // preg_split gère les espaces multiples, tabs, etc.
        foreach (preg_split('/\s+/', trim($entry['pattern']), -1, PREG_SPLIT_NO_EMPTY) as $pat) {
            $regex = '/^'.str_replace('%','.*',preg_quote($pat,'/')).'$/i';
            if (preg_match($regex, $evCode))
                return '#'.ltrim($entry['color'],'#'); // ltrim sécurise si # déjà présent en BDD
        }
    }
    return null;
}

// ── Noms d'équipes ('0' = BYE) ───────────────────────────────────────────────
function getTeamNames($tourId) {
    $rs = safe_r_sql("SELECT CoId, CoName, CoCode FROM Countries WHERE CoTournament=$tourId");
    $result = [];
    while ($r = safe_fetch($rs))
        $result[intval($r->CoId)] = !empty($r->CoName) ? $r->CoName : '0'; // '0' = BYE
    return $result;
}

// Estime la hauteur totale d'une poule pour le calcul du scale
function estimatePoolHeight($nTeams, $nRounds, $withResults, $b) {
    // En-tête : titre + nom poule + ln3 + légende volées
    $h = $b['hTitle'] + $b['hPool'] + 3 + $b['hChdr'];
    // Matchs : (nRounds rounds) × (bandeau + colheader + ln2 + paires + ln8)
    $nPairs = max(1, (int)($nTeams / 2));
    $h += $nRounds * ($b['hMhdr'] + $b['hChdr'] + 2 + $nPairs * (2*$b['hRow'] + 2) + 8);
    // Classement : ln3 + titre + en-tête + nTeams lignes + légende (2 lignes)
    $h += 3 + $b['hMhdr'] + $b['hChdr'] + $nTeams * $b['hRow'] + $b['hRow'] * 2;
    return $h;
}

// ── Rendu d'une ligne équipe dans un match ────────────────────────────────────
// $b = tableau de config (éventuellement scalé), $maxEnds = nb de volées
function printMatchRow($pdf, $m, $teamNames, $b, $colNameBase, $maxEnds, $hasTiebreak, $isLooser, $showScore, $mBye) {
    $name    = $teamNames[intval($m->RrMatchAthlete)] ?? '0';
    $isBye   = ($name === '0');
    $displayName = $isBye ? '-- Bye --' : mb_strtoupper($name, 'UTF-8');
    $hasScore = $showScore && intval($m->RrMatchScore) > 0;
    $colScAll = $maxEnds * $b['endW'] + $b['colSetSc'] + ($hasTiebreak ? $b['colTbW'] : 0);
    $byeValue = $mBye ? (($isBye || $isLooser) ? '0' : '6') : '';

    $pdf->SetFont($pdf->FontStd, '', $b['fData']);
    $pdf->Cell($b['colTgt'], $b['hRow'], ltrim($m->RrMatchTarget,'0') ?: '0', 1, 0, 'C', 0);

    $style = $isBye ? 'I' : ($showScore ? ($isLooser ? 'I' : 'B') : 'B');
    $pdf->SetFont($pdf->FontStd, $style, $b['fData']);
    $pdf->Cell($colNameBase, $b['hRow'], $displayName, 1, 0, 'L', 0);

    if (!$hasScore) {
        // Pas de résultat : une seule cellule vide
        $style = $isBye ? 'I' : ($byeValue>0 ? 'B' : 'I');
        $pdf->SetFont($pdf->FontStd, $style, $b['fData']);
        $pdf->Cell($colScAll, $b['hRow'], $byeValue, 1, 1, 'C', 0);
    } else {
        // Volées
        $endScores = array_values(
            array_filter(explode('|', trim($m->RrMatchSetPoints ?? '')), 'strlen')
        );
        while (count($endScores) < $maxEnds) $endScores[] = '';
        $pdf->SetFont($pdf->FontFix, '', $b['fData']);
        foreach ($endScores as $s)
            $pdf->Cell($b['endW'], $b['hRow'], $s ?: '', 1, 0, 'C', 0);
        // Total sets
        $pdf->SetFont($pdf->FontFix, $showScore ? ($isLooser ? 'I' : 'B') : 'B', $b['fData']);
        $pdf->Cell($b['colSetSc'], $b['hRow'], $m->RrMatchSetScore ?? '', 1, 0, 'C', 0);
        // Barrage (toujours présent si $hasTiebreak, vide si ce match n'en a pas)
        if ($hasTiebreak) {
            $pdf->SetFont($pdf->FontFix, 'I', $b['fData']);
            $pdf->Cell($b['colTbW'], $b['hRow'], $m->RrMatchTbDecoded ?? '', 1, 0, 'C', 0);
        }
        $pdf->Ln();
    }
}

// ── Paramètres request ────────────────────────────────────────────────────────
$tourId       = intval($_SESSION['TourId']);
$events       = (array)($_REQUEST['event'] ?? ['.']);
$levels       = (array)($_REQUEST['level'] ?? ['.']);
$groups       = (array)($_REQUEST['group'] ?? ['.']);
$withResults  = !empty($_REQUEST['withResults']);
$useAccColors = !empty($_REQUEST['useAccColors']); // checkbox dans index.php

$allEvt    = in_array('.', $events);
$allLev    = in_array('.', $levels);
$allGrp    = in_array('.', $groups);
$levelNums = $allLev ? [] : array_map('intval', $levels);
$groupNums = $allGrp ? [] : array_map('intval', $groups);

// ── Requête niveaux ───────────────────────────────────────────────────────────
$evSQL = $allEvt ? '' :
    "AND l.RrLevEvent IN (".implode(',', array_map('StrSafe_DB', $events)).")";

$rsLev = safe_r_sql(
    "SELECT l.*, e.EvEventName
     FROM RoundRobinLevel l
     JOIN Events e ON e.EvTournament=l.RrLevTournament AND e.EvCode=l.RrLevEvent
     WHERE l.RrLevTournament=$tourId $evSQL
     ORDER BY l.RrLevEvent, l.RrLevLevel"
);

// ── PDF ───────────────────────────────────────────────────────────────────────
$pdf       = new ResultPDF('Poules – Round Robin', true);
$firstPage = true;
$colorMap  = loadAccColors($tourId);
$teamNames = getTeamNames($tourId); // requête unique pour toute la génération

while ($lev = safe_fetch($rsLev)) {
    // Filtre niveau
    if (!$allLev && !in_array(intval($lev->RrLevLevel), $levelNums)) continue;

    $evName = get_text($lev->EvEventName, '', '', true);
    $evHex  = ($useAccColors ? getEventColor($lev->RrLevEvent, $colorMap) : null) ?? $COLORS['evFallback'];

    $rsGrp = safe_r_sql(
        "SELECT * FROM RoundRobinGroup
         WHERE RrGrTournament=$tourId
         AND RrGrEvent=".StrSafe_DB($lev->RrLevEvent)."
         AND RrGrLevel=".intval($lev->RrLevLevel)."
         ORDER BY RrGrGroup"
    );

    while ($grp = safe_fetch($rsGrp)) {
        // Filtre groupe
        if (!$allGrp && !in_array(intval($grp->RrGrGroup), $groupNums)) continue;

        // ── Participants ──────────────────────────────────────────────────────
        $rsPart = safe_r_sql(
            "SELECT * FROM RoundRobinParticipants
             WHERE RrPartTournament=$tourId
             AND RrPartEvent=".StrSafe_DB($lev->RrLevEvent)."
             AND RrPartLevel=".intval($lev->RrLevLevel)."
             AND RrPartGroup=".intval($grp->RrGrGroup)."
             ORDER BY RrPartDestItem"
        );
        $parts = [];
        while ($p = safe_fetch($rsPart)) $parts[] = $p;

        // ── Matchs ───────────────────────────────────────────────────────────
        $rsMatch = safe_r_sql(
            "SELECT * FROM RoundRobinMatches
             WHERE RrMatchTournament=$tourId
             AND RrMatchEvent=".StrSafe_DB($lev->RrLevEvent)."
             AND RrMatchLevel=".intval($lev->RrLevLevel)."
             AND RrMatchGroup=".intval($grp->RrGrGroup)."
             ORDER BY RrMatchRound, RrMatchMatchNo"
        );
        $rounds = [];
        $matchPts = [];
        $maxEnds = intval($lev->RrLevEnds);
        while ($m = safe_fetch($rsMatch)) {
            $rounds[$m->RrMatchRound][] = $m;
            $matchPts[intval($m->RrMatchAthlete)][$m->RrMatchRound] = $m->RrMatchRoundPoints;
            // Nombre réel de volées (peut dépasser RrLevEnds si barrage)
            if (!empty($m->RrMatchSetPoints)) {
                $cnt = count(array_filter(explode('|', trim($m->RrMatchSetPoints)), 'strlen'));
                $maxEnds = max($maxEnds, $cnt);
            }
            // Fallback nom si absent de Entries
            if (!array_key_exists(intval($m->RrMatchAthlete), $teamNames))
                $teamNames[intval($m->RrMatchAthlete)] = $m->RrMatchAthlete;
        }
        $nRounds = count($rounds);

        // ── Auto-scale : ajuste les tailles si la poule dépasse 1 page ──────
        $nTeams = count(array_filter($parts,
            fn($p) => ($teamNames[intval($p->RrPartParticipant)] ?? null) !== null
        ));
        $estH  = estimatePoolHeight($nTeams, $nRounds, $withResults, $BASE);
        $scale = min(1.0, max(0.65, PAGE_H / max(1, $estH)));

        $b = $BASE; // copie locale — on ne modifie pas $BASE
        if ($scale < 1.0) {
            foreach (['fTitle','fPool','fMhdr','fChdr','fData','fLeg'] as $k)
                $b[$k] = max(7, (int)round($b[$k] * $scale));
            foreach (['hTitle','hPool','hMhdr','hChdr','hRow'] as $k)
                $b[$k] = max(3.0, round($b[$k] * $scale, 1));
        }

        // Largeur colonne nom dans le classement (remplit exactement 190mm)
        $colNameS = max(35, $b['W'] - $b['wPl'] - $b['wPts'] - $b['wTB']*2 - ($nRounds * $b['wRnd']) - $b['wCl']);

        // ── Nouvelle page ─────────────────────────────────────────────────────
        if ($firstPage) { $firstPage = false; }
        else { $pdf->AddPage(); }

        // ── En-tête poule ─────────────────────────────────────────────────────
        // Date de la poule (date du premier match)
        $headerDate = '';
        foreach ($rounds as $rows) {
            if (!empty($rows)) {
                $dt = $rows[0]->RrMatchScheduledDate ?? '';
                if ($dt && $dt !== '0000-00-00') {
                    $dp = explode('-', $dt);
                    if (count($dp) === 3)
                        $headerDate = $dp[2].'/'.$dp[1];
                }
                break;
            }
        }

        applyColor($pdf, $evHex);
        $pdf->SetFont($pdf->FontStd, 'B', $b['fTitle']);
        $pdf->Cell($b['W'], $b['hTitle'],
            $evName.' – '.($lev->RrLevName ?: 'Tour '.$lev->RrLevLevel).($headerDate ? ' – '.$headerDate : ''),
            0, 1, 'C', 1);
        $pdf->SetFont($pdf->FontStd, 'B', $b['fPool']);
        $pdf->Cell($b['W'], $b['hPool'], $grp->RrGrName ?: 'Poule '.$grp->RrGrGroup, 0, 1, 'C', 1);
        $pdf->SetDefaultColor();
        $pdf->Ln(3);
        $pdf->SetFont($pdf->FontStd, 'I', $b['fLeg']);
        $pdf->Cell($b['W'], 5, 'Les résultats de match possibles sont : 6-0 / 5-1 / 6-2 / 5-3 / 5-4', 0, 1, 'C', 0);

        // ── Section matchs ────────────────────────────────────────────────────
        $matchCounter = 0;
        $poolHasScore = false;

        foreach ($rounds as $round => $rows) {
            $matchCounter++;
            // heure du round
            $tm = $rows[0]->RrMatchScheduledTime ?? '';

            // Bandeau gris du round
            $pdf->SetFont($pdf->FontStd, 'B', $b['fMhdr']);
            $pdf->Cell($b['W'], $b['hMhdr'],
                'Match '.$matchCounter.($tm ? ' – '.substr($tm,0,5) : ''),
                1, 1, 'C', 1);

            // Entête tableau
            $colScBase  = $maxEnds * $b['endW'] + $b['colSetSc'];
            $colNameBase = max(50, $b['W'] - $b['colTgt'] - $colScBase);
            $pdf->SetFont($pdf->FontStd, 'B', $b['fChdr']);
            $pdf->Cell($b['colTgt'],  $b['hChdr'], 'Cible',             1, 0, 'C', 1);
            $pdf->Cell($colNameBase,  $b['hChdr'], 'Club',              1, 0, 'L', 1);
            $pdf->Cell($colScBase,    $b['hChdr'], 'Résultat du match', 1, 1, 'C', 1);
            $pdf->Ln(2);

            // Tous les matchs de ce round
            for ($i = 0; $i + 1 < count($rows); $i += 2) {
                $r1    = $rows[$i];
                $r2    = $rows[$i+1];
                $name1 = $teamNames[intval($r1->RrMatchAthlete)] ?? '0';
                $name2 = $teamNames[intval($r2->RrMatchAthlete)] ?? '0';

                // Tiebreak propre à CE match uniquement
                $hasTiebreak = !empty($r1->RrMatchTbDecoded) || !empty($r2->RrMatchTbDecoded);
                $b['endW'] = ($hasTiebreak ? ($b['endW']-($b['colTbW'] / $maxEnds)) : $b['endW']); // reduit endW s'il y a barrage

                $r1IsLooser  = $name1 === '0' || ($r1->RrMatchWinLose === '0' && $r2->RrMatchWinLose === '1' );
                $r2IsLooser  = $name2 === '0' || ($r1->RrMatchWinLose === '1' && $r2->RrMatchWinLose === '0');

                $withResults ?
                    $mBye  = ($name1 === '0') || ($name2 === '0') || (($r1IsLooser||$r2IsLooser)&&($r1->RrMatchSetPoints   === '' || $r2->RrMatchSetPoints === '')): // match en Bye ou DNF (perdant sans résultat de volée)
                    $mBye  = ($name1 === '0') || ($name2 === '0'); // match avec BYE uniquement
                
                // Détecter si la poule a au moins un résultat
                if (intval($r1->RrMatchScore) > 0 || intval($r2->RrMatchScore) > 0)
                    $poolHasScore = true;

                // Team 1
                printMatchRow($pdf, $r1, $teamNames, $b, $colNameBase, $maxEnds, $hasTiebreak, $r1IsLooser, $withResults, $mBye);
                // Team 2
                printMatchRow($pdf, $r2, $teamNames, $b, $colNameBase, $maxEnds, $hasTiebreak, $r2IsLooser, $withResults, $mBye);
                $pdf->Ln(2);
                $b['endW'] = ($hasTiebreak ? ($b['endW']+($b['colTbW'] / $maxEnds)) : $b['endW']); // restaure endW s'il y avait barrage
            }
            $pdf->Ln(8);
        }

        $pdf->Ln(3);

        // ── Classement de la poule ────────────────────────────────────────────

        applyColor($pdf, $COLORS['rankHdr']);
        $pdf->SetFont($pdf->FontStd, 'B', $b['fMhdr']);
        $pdf->Cell($b['W'], $b['hMhdr'],
            'Classement de la poule '.$grp->RrGrGroup.' – Tour '.$lev->RrLevLevel,
            1, 1, 'C', 1);

        // En-têtes colonnes
        applyColor($pdf, $COLORS['rankSub']);
        $pdf->SetFont($pdf->FontStd, '', $b['fChdr']);
        $pdf->Cell($b['wPl'],   $b['hChdr'], 'Pl.',    1, 0, 'C', 1);
        $pdf->Cell($colNameS,   $b['hChdr'], 'Équipe', 1, 0, 'L', 1);
        for ($r = 1; $r <= $nRounds; $r++)
            $pdf->Cell($b['wRnd'], $b['hChdr'], "Match $r", 1, 0, 'C', 1);

        if ($withResults && $poolHasScore) {
            $pdf->SetFont($pdf->FontStd, 'B', $b['fChdr']);
            $pdf->Cell($b['wPts'], $b['hChdr'], 'Points',     1, 0, 'C', 1);
            $pdf->SetFont($pdf->FontStd, 'I', $b['fChdr']);
            $pdf->Cell($b['wTB'],  $b['hChdr'], 'Diff.',      1, 0, 'C', 1);
            $pdf->Cell($b['wTB'],  $b['hChdr'], 'Pts sets',   1, 0, 'C', 1);
            $pdf->SetFont($pdf->FontStd, 'I', $b['fChdr']);
            $pdf->Cell($b['wCl'],  $b['hChdr'], 'Classement', 1, 1, 'C', 1);
        } else {
            // Pas de résultat dans la poule : on affiche quand même les points
            $pdf->SetFont($pdf->FontStd, 'B', $b['fChdr']);
            $pdf->Cell($b['wPts']+$b['wTB']*2+$b['wCl'], $b['hChdr'], 'Cumul des points', 1, 1, 'C', 1);
        }
        $pdf->SetDefaultColor();

        // Données (triées par rang, BYE exclus)
        usort($parts, fn($a, $z) => intval($a->RrPartDestItem) <=> intval($z->RrPartDestItem));
        foreach ($parts as $p) {
            $name = $teamNames[intval($p->RrPartParticipant)] ?? '0';
            if ($name === '0') continue; // BYE ignoré

            $pdf->SetFont($pdf->FontStd, '', $b['fData']);
            $pdf->Cell($b['wPl'], $b['hRow'], $p->RrPartDestItem, 1, 0, 'C', 0);
            [$tr,$tg,$tb] = hexToRgb($COLORS['teamName']);
            $pdf->SetTextColor($tr,$tg,$tb);
            $pdf->SetFont($pdf->FontStd, 'B', $b['fData']);
            $pdf->Cell($colNameS, $b['hRow'], mb_strtoupper($name, 'UTF-8'), 1, 0, 'L', 0);
            $pdf->SetDefaultColor();

            for ($r = 1; $r <= $nRounds; $r++) {
                $pts = $matchPts[intval($p->RrPartParticipant)][$r] ?? '';
                $pdf->SetFont($pdf->FontFix, '', $b['fData']);
                $pdf->Cell($b['wRnd'], $b['hRow'], ($withResults && $poolHasScore) ? $pts : '', 1, 0, 'C', 0);
            }

            if ($withResults && $poolHasScore) {
                $pdf->SetFont($pdf->FontFix, 'B', $b['fData']);
                $pdf->Cell($b['wPts'], $b['hRow'], $p->RrPartPoints,         1, 0, 'C', 0);
                $pdf->SetFont($pdf->FontFix, 'I', $b['fData']);
                $pdf->Cell($b['wTB'],  $b['hRow'], $p->RrPartTieBreaker,     1, 0, 'C', 0);
                $pdf->Cell($b['wTB'],  $b['hRow'], $p->RrPartTieBreaker2,    1, 0, 'C', 0);
                $pdf->SetFont($pdf->FontStd, 'B', $b['fData']);
                $pdf->Cell($b['wCl'],  $b['hRow'], $p->RrPartGroupRankBefSO, 1, 1, 'C', 0);
            } else {
                // Pas de résultat dans la poule : une case vide
                $pdf->Cell($b['wPts']+$b['wTB']*2+$b['wCl'], $b['hRow'], '', 1, 1, 'C', 0);
            }
        }

        // ── Légende bas de page ───────────────────────────────────────────────
        $pdf->SetFont($pdf->FontStd, 'I', $b['fLeg']);
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $wLeft  = $b['wPl'] + $colNameS;
        $wMid   = $b['wRnd'] * $nRounds;
        $wRight = $b['wPts'] + $b['wTB']*2 + $b['wCl'];
        $pdf->MultiCell($wLeft,  $b['hRow'], " \n ", 1, 'C', 0);
        $maxY = $pdf->GetY(); // hauteur réelle après wrap
        $pdf->SetXY($x + $wLeft, $y); // revenir sur la même ligne, colonne suivante
        $pdf->MultiCell($wMid,   $b['hRow'], "Victoire = 2\nDéfaite = 0", 1, 'C', 0);
        $maxY = max($maxY, $pdf->GetY());
        $pdf->SetXY($x + $wLeft + $wMid, $y); // revenir sur la même ligne, colonne suivante
        $pdf->MultiCell($wRight, $b['hRow'], "Cumuls des points possibles\n0 / 2 / 4 / 6", 1, 'C', 0);
        $pdf->SetY(max($maxY, $pdf->GetY())); // passer sous la ligne la plus haute
    }
}

// ── Nom du fichier PDF ────────────────────────────────────────────────────────
$evPart  = $allEvt ? 'Toutes categories' : implode('+', array_map('strtoupper', $events));
$levPart = $allLev ? 'Tous les tours'      : 'Tour '.implode('+', $levelNums);
$grpPart = $allGrp ? 'Toutes les poules'  : 'Poule '.implode('+', $groupNums);
// Nettoie les caractères invalides dans un nom de fichier
$filename = "Tableaux de poules" . ($withResults ? " avec résultats" : " sans résultat") . " - " . preg_replace('/[^\w\-\+]/', ' ', $evPart.' - '.$levPart.' - '.$grpPart) . '.pdf';

$pdf->Output($filename, 'I');