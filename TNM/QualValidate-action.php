<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);

header('Content-Type: application/json');
$JSON = ['error' => 1, 'msg' => 'Erreur'];

if (!hasFullACL(AclQualification, '', AclReadWrite)) {
    $JSON['msg'] = 'Droits insuffisants';
    echo json_encode($JSON);
    exit;
}

$tourId  = intval($_SESSION['TourId']);
$evCode  = isset($_REQUEST['event']) ? trim($_REQUEST['event']) : '';

if ($evCode === '') {
    $JSON['msg'] = 'Paramètre event manquant';
    echo json_encode($JSON);
    exit;
}

// Parse custom rank order from drag & drop (optional)
$customRanks = null;
if (!empty($_POST['ranks'])) {
    $decoded = json_decode($_POST['ranks'], true);
    if (is_array($decoded)) {
        $customRanks = [];
        foreach ($decoded as $r) {
            $coId    = intval($r['coId']    ?? 0);
            $subTeam = intval($r['subTeam'] ?? 0);
            $rank    = intval($r['rank']    ?? 0);
            if ($coId > 0 && $rank > 0) $customRanks["$coId-$subTeam"] = $rank;
        }
        if (empty($customRanks)) $customRanks = null;
    }
}

// Fetch teams that qualify for Round Robin
$rsTeams = safe_r_sql(
    "SELECT TeCoId, TeSubTeam, TeScore, TeGold, TeXNine
     FROM Teams
     WHERE TeTournament=$tourId AND TeEvent=" . StrSafe_DB($evCode) . " AND TeFinEvent=1"
);
$teams = [];
while ($t = safe_fetch($rsTeams)) {
    $teams[] = $t;
}

if (empty($teams)) {
    $JSON['msg'] = 'Aucune équipe qualifiée pour cet événement';
    echo json_encode($JSON);
    exit;
}

if ($customRanks !== null) {
    // Apply custom rank order from drag & drop
    foreach ($teams as $t) {
        $key  = $t->TeCoId . '-' . $t->TeSubTeam;
        $rank = $customRanks[$key] ?? null;
        if ($rank === null) continue;
        safe_w_sql(
            "UPDATE Teams SET TeRank=$rank, TeSO=0, TeTimeStamp=now()
             WHERE TeTournament=$tourId AND TeEvent=" . StrSafe_DB($evCode) .
            " AND TeCoId=$t->TeCoId AND TeSubTeam=$t->TeSubTeam AND TeFinEvent=1"
        );
    }
} else {
    // Auto-sort: non-zero scores first (score DESC, gold DESC, xnine DESC), crc32 tie-break
    usort($teams, function ($a, $b) {
        $aZero = ($a->TeScore == 0);
        $bZero = ($b->TeScore == 0);
        if ($aZero !== $bZero) return $aZero ? 1 : -1;
        if ($a->TeScore !== $b->TeScore) return $b->TeScore <=> $a->TeScore;
        if ($a->TeGold  !== $b->TeGold)  return $b->TeGold  <=> $a->TeGold;
        if ($a->TeXNine !== $b->TeXNine) return $b->TeXNine <=> $a->TeXNine;
        return crc32((string)$a->TeCoId) <=> crc32((string)$b->TeCoId);
    });

    foreach ($teams as $i => $t) {
        $rank = $i + 1;
        safe_w_sql(
            "UPDATE Teams SET TeRank=$rank, TeSO=0, TeTimeStamp=now()
             WHERE TeTournament=$tourId AND TeEvent=" . StrSafe_DB($evCode) .
            " AND TeCoId=$t->TeCoId AND TeSubTeam=$t->TeSubTeam AND TeFinEvent=1"
        );
    }
}

// Reset RoundRobinParticipants for this event
safe_w_sql(
    "UPDATE RoundRobinParticipants SET
        RrPartGroupRank=0, RrPartGroupRankBefSO=0, RrPartLevelRank=0, RrPartLevelRankBefSO=0,
        RrPartPoints=0, RrPartTieBreaker=0, RrPartTieBreaker2=0,
        RrPartParticipant=0, RrPartSubTeam=0,
        RrPartGroupTieBreak='', RrPartGroupTbClosest=0, RrPartGroupTbDecoded='',
        RrPartLevelTieBreak='', RrPartLevelTbClosest=0, RrPartLevelTbDecoded='',
        RrPartIrmType=0, RrPartGroupTiesForSO=0, RrPartGroupTiesForCT=0,
        RrPartDateTime=now(), RrPartLevelTiesForSO=0, RrPartLevelTiesForCT=0
     WHERE RrPartTournament=$tourId AND RrPartTeam=1 AND RrPartEvent=" . StrSafe_DB($evCode)
);

// Reset RoundRobinMatches for this event
safe_w_sql(
    "UPDATE RoundRobinMatches SET
        RrMatchAthlete=0, RrMatchSubTeam=0, RrMatchRank=0, RrMatchScore=0,
        RrMatchSetScore=0, RrMatchSetPoints='', RrMatchSetPointsByEnd='',
        RrMatchWinnerSet=0, RrMatchTie=0, RrMatchArrowstring='',
        RrMatchTiebreak='', RrMatchTbClosest=0, RrMatchTbDecoded='',
        RrMatchArrowPosition='', RrMatchTiePosition='', RrMatchWinLose=0,
        RrMatchFinalRank=0, RrMatchDateTime=0, RrMatchSyncro=0, RrMatchLive=0,
        RrMatchStatus=0, RrMatchShootFirst=0, RrMatchVxF=0, RrMatchConfirmed=0,
        RrMatchNotes='', RrMatchRecordBitmap=0, RrMatchIrmType=0, RrMatchCoach=0,
        RrMatchRoundPoints=0, RrMatchTieBreaker=0, RrMatchTieBreaker2=0
     WHERE RrMatchTournament=$tourId AND RrMatchTeam=1 AND RrMatchEvent=" . StrSafe_DB($evCode)
);

// Reset group/level shootoff-solved flags
safe_w_sql(
    "UPDATE RoundRobinGroup SET RrGrSoSolved=0
     WHERE RrGrTournament=$tourId AND RrGrTeam=1 AND RrGrEvent=" . StrSafe_DB($evCode)
);
safe_w_sql(
    "UPDATE RoundRobinLevel SET RrLevSoSolved=0
     WHERE RrLevTournament=$tourId AND RrLevTeam=1 AND RrLevEvent=" . StrSafe_DB($evCode)
);

// Populate RoundRobinParticipants + Matches from the new TeRank values
safe_w_sql(
    "UPDATE RoundRobinParticipants
     INNER JOIN Teams ON TeTournament=RrPartTournament AND TeEvent=RrPartEvent
         AND TeRank=RrPartSourceRank AND TeFinEvent=1
     INNER JOIN RoundRobinGrids ON RrGridTournament=RrPartTournament
         AND RrGridEvent=RrPartEvent AND RrGridTeam=RrPartTeam
         AND RrGridLevel=RrPartLevel AND RrGridGroup=RrPartGroup AND RrGridItem=RrPartDestItem
     INNER JOIN RoundRobinMatches ON RrMatchTournament=RrGridTournament
         AND RrMatchTeam=RrGridTeam AND RrMatchEvent=RrGridEvent
         AND RrMatchLevel=RrGridLevel AND RrMatchGroup=RrGridGroup
         AND RrMatchRound=RrGridRound AND RrMatchMatchNo=RrGridMatchno
     SET RrPartParticipant=TeCoId, RrMatchAthlete=TeCoId,
         RrPartSubTeam=TeSubTeam, RrMatchSubTeam=TeSubTeam
     WHERE RrPartSourceLevel=0 AND RrPartTournament=$tourId
         AND RrPartTeam=1 AND RrPartEvent=" . StrSafe_DB($evCode)
);

// Mark qualification as validated for this event
safe_w_sql(
    "UPDATE Events SET EvE1ShootOff=1
     WHERE EvTeamEvent=1 AND EvTournament=$tourId AND EvCode=" . StrSafe_DB($evCode)
);
set_qual_session_flags();

// Handle BYE matches (one slot empty → auto-win for the present team)
$qq = safe_r_sql(
    "SELECT r1.RrMatchGroup AS MatchGroup, r1.RrMatchRound AS MatchRound,
            r1.RrMatchMatchNo AS M1, r1.RrMatchAthlete AS A1,
            r2.RrMatchMatchNo AS M2, r2.RrMatchAthlete AS A2
     FROM RoundRobinMatches r1
     INNER JOIN RoundRobinMatches r2
         ON r2.RrMatchTournament=r1.RrMatchTournament AND r2.RrMatchTeam=r1.RrMatchTeam
         AND r2.RrMatchEvent=r1.RrMatchEvent AND r2.RrMatchLevel=r1.RrMatchLevel
         AND r2.RrMatchGroup=r1.RrMatchGroup AND r2.RrMatchRound=r1.RrMatchRound
         AND r2.RrMatchMatchNo=r1.RrMatchMatchNo+1
     WHERE r1.RrMatchMatchno%2=0
         AND (r1.RrMatchAthlete=0 OR r2.RrMatchAthlete=0)
         AND r1.RrMatchTournament=$tourId AND r1.RrMatchTeam=1 AND r1.RrMatchLevel=1
         AND r1.RrMatchEvent=" . StrSafe_DB($evCode)
);
while ($rr = safe_fetch($qq)) {
    if ($rr->A1) {
        safe_w_sql(
            "UPDATE RoundRobinMatches SET RrMatchTie=2, RrMatchWinLose=1
             WHERE RrMatchTournament=$tourId AND RrMatchTeam=1 AND RrMatchLevel=1
             AND RrMatchGroup=$rr->MatchGroup AND RrMatchRound=$rr->MatchRound
             AND RrMatchMatchNo=$rr->M1 AND RrMatchEvent=" . StrSafe_DB($evCode)
        );
    } elseif ($rr->A2) {
        safe_w_sql(
            "UPDATE RoundRobinMatches SET RrMatchTie=2, RrMatchWinLose=1
             WHERE RrMatchTournament=$tourId AND RrMatchTeam=1 AND RrMatchLevel=1
             AND RrMatchGroup=$rr->MatchGroup AND RrMatchRound=$rr->MatchRound
             AND RrMatchMatchNo=$rr->M2 AND RrMatchEvent=" . StrSafe_DB($evCode)
        );
    }
}

require_once($CFG->DOCUMENT_PATH . 'Modules/RoundRobin/Lib.php');
calculateFinalRank(1, $evCode, 0);

$JSON['error'] = 0;
$JSON['msg']   = count($teams) . ' équipes affectées aux poules';
echo json_encode($JSON);
