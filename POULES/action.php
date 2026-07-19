<?php
// =============================================================================
// action.php — API AJAX du module POULES (lecture seule)
// Retourne classements + matchs des poules Round Robin équipes du tournoi courant.
// Les projections (rangs atteignables, enjeux) sont calculées côté client.
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

$out = [
    'tour'   => $_SESSION['TourNameSafe'] ?? '',
    'now'    => date('H:i:s'),
    'events' => [],
];

$levels = [];
$rs = safe_r_sql("SELECT L.RrLevEvent, L.RrLevLevel, L.RrLevName, L.RrLevWinPoints, L.RrLevTiePoints, L.RrLevMatchMode,
        L.RrLevTieBreakSystem, L.RrLevTieBreakSystem2, E.EvEventName
    FROM RoundRobinLevel L
    INNER JOIN Events E ON E.EvTournament=L.RrLevTournament AND E.EvCode=L.RrLevEvent AND E.EvTeamEvent=L.RrLevTeam
    WHERE L.RrLevTournament=$tourId AND L.RrLevTeam=1
    ORDER BY L.RrLevEvent, L.RrLevLevel");
while ($r = safe_fetch($rs)) $levels[] = $r;

foreach ($levels as $lev) {
    $ev  = $lev->RrLevEvent;
    $lv  = intval($lev->RrLevLevel);
    $evQ = StrSafe_DB($ev);

    $groups = [];
    $rs = safe_r_sql("SELECT RrGrGroup, RrGrName FROM RoundRobinGroup
        WHERE RrGrTournament=$tourId AND RrGrTeam=1 AND RrGrEvent=$evQ AND RrGrLevel=$lv
        ORDER BY RrGrGroup");
    while ($g = safe_fetch($rs)) $groups[intval($g->RrGrGroup)] = $g->RrGrName;
    if (!$groups) $groups = [1 => ''];

    foreach ($groups as $grpNo => $grpName) {
        $teams = [];
        $rs = safe_r_sql("SELECT P.RrPartParticipant AS id, C.CoName, C.CoCode
            FROM RoundRobinParticipants P
            LEFT JOIN Countries C ON C.CoId=P.RrPartParticipant AND C.CoTournament=$tourId
            WHERE P.RrPartTournament=$tourId AND P.RrPartTeam=1
              AND P.RrPartEvent=$evQ AND P.RrPartLevel=$lv AND P.RrPartGroup=$grpNo");
        while ($t = safe_fetch($rs)) {
            $id = intval($t->id);
            if (!$id) continue;
            $teams[$id] = [
                'id' => $id, 'name' => $t->CoName ?: ('#' . $id), 'code' => $t->CoCode ?: '',
                'played' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0, 'pts' => 0,
                'sf' => 0, 'sa' => 0, 'scf' => 0, 'sca' => 0, 'remaining' => 0,
                'tb' => 0, 'tb2' => 0, // Σ des tie-breaks natifs par match (suivent le système configuré)
            ];
        }
        if (!$teams) continue;

        $raw = [];
        $rs = safe_r_sql("SELECT RrMatchRound r, RrMatchMatchNo n, RrMatchTarget tg,
                RrMatchScheduledDate d, RrMatchScheduledTime t,
                RrMatchAthlete id, RrMatchScore sc, RrMatchSetScore st,
                RrMatchWinLose wl, RrMatchRoundPoints rp, RrMatchIrmType irm,
                RrMatchTieBreaker mtb, RrMatchTieBreaker2 mtb2,
                RrMatchSetPointsByEnd spe
            FROM RoundRobinMatches
            WHERE RrMatchTournament=$tourId AND RrMatchTeam=1
              AND RrMatchEvent=$evQ AND RrMatchLevel=$lv AND RrMatchGroup=$grpNo
            ORDER BY RrMatchRound, RrMatchMatchNo");
        while ($m = safe_fetch($rs)) $raw[intval($m->r)][intval($m->n)] = $m;

        $matches      = [];
        $totalRounds  = 0;
        $currentRound = 0;
        foreach ($raw as $round => $rows) {
            $totalRounds = max($totalRounds, $round);
            ksort($rows);
            $rows = array_values($rows);
            for ($i = 0; $i + 1 < count($rows); $i += 2) {
                $a = $rows[$i];
                $b = $rows[$i + 1];
                $idA = intval($a->id);
                $idB = intval($b->id);
                if (!$idA || !$idB || !isset($teams[$idA]) || !isset($teams[$idB])) continue; // bye

                $done = intval($a->wl) || intval($b->wl) || intval($a->rp) || intval($b->rp)
                     || intval($a->irm) || intval($b->irm);
                $live = !$done && (intval($a->sc) > 0 || intval($b->sc) > 0
                     || trim($a->spe) !== '' || trim($b->spe) !== '');
                $state = $done ? 'done' : ($live ? 'live' : 'todo');

                if ($done) {
                    $tie = !intval($a->wl) && !intval($b->wl) && !intval($a->irm) && !intval($b->irm);
                    foreach ([[$a, $b], [$b, $a]] as [$me, $op]) {
                        $t = &$teams[intval($me->id)];
                        $t['played']++;
                        $t['pts'] += intval($me->rp);
                        if ($tie) $t['ties']++;
                        elseif (intval($me->wl)) $t['wins']++;
                        else $t['losses']++;
                        $t['sf']  += intval($me->st);
                        $t['sa']  += intval($op->st);
                        $t['scf'] += intval($me->sc);
                        $t['sca'] += intval($op->sc);
                        $t['tb']  += intval($me->mtb);
                        $t['tb2'] += intval($me->mtb2);
                        unset($t);
                    }
                } else {
                    $teams[$idA]['remaining']++;
                    $teams[$idB]['remaining']++;
                    if (!$currentRound) $currentRound = $round;
                }

                $matches[] = [
                    'r' => $round, 'tg' => $a->tg, 'time' => substr($a->t, 0, 5), 'date' => $a->d,
                    'state' => $state,
                    'a' => ['id' => $idA, 'sc' => intval($a->sc), 'st' => intval($a->st),
                            'wl' => intval($a->wl), 'irm' => intval($a->irm)],
                    'b' => ['id' => $idB, 'sc' => intval($b->sc), 'st' => intval($b->st),
                            'wl' => intval($b->wl), 'irm' => intval($b->irm)],
                ];
            }
        }

        $name = $lev->EvEventName;
        if (count($groups) > 1 && $grpName) $name .= ' — ' . $grpName;

        $out['events'][] = [
            'ev'           => $ev,
            'level'        => $lv,
            'group'        => $grpNo,
            'key'          => $ev . '-' . $lv . '-' . $grpNo,
            'name'         => $name,
            'winPts'       => intval($lev->RrLevWinPoints) ?: 2,
            'tiePts'       => intval($lev->RrLevTiePoints),
            'matchMode'    => intval($lev->RrLevMatchMode), // 1 = sets, 0 = cumul de points
            'tieSys'       => intval($lev->RrLevTieBreakSystem),
            'tieSys2'      => intval($lev->RrLevTieBreakSystem2),

            'teams'        => array_values($teams),
            'matches'      => $matches,
            'totalRounds'  => $totalRounds,
            'currentRound' => $currentRound,
        ];
    }
}

JsonOut($out);
