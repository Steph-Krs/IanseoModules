<?php
// =============================================================================
// bso-action.php — Endpoint AJAX BSO
// act: getEvents | getVolee | saveScore | setStatus | initVolee | getCurrentRound
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

header('Content-Type: application/json; charset=utf-8');

$act    = $_REQUEST['act'] ?? '';
$tourId = intval($_SESSION['TourId']);

// ── Contrôle d'accès renforcé pour les actions admin ─────────────────────────
// checkFullACL() (ligne 9) garantit AclReadOnly pour toutes les requêtes.
// requireWriteAcl() vérifie en plus AclReadWrite pour les actions destructrices
// ou qui modifient la structure des données (initVolee, resetScores, deleteTeams,
// recalcAll, setPoolTeamRanking).
function requireWriteAcl() {
    if (!hasFullACL(AclRobin, '', AclReadWrite)) {
        echo json_encode(['error' => 1, 'msg' => 'Droits insuffisants — action réservée aux administrateurs (ReadWrite requis)']);
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function getBsoConfig($tourId, $evCode) {
    $rs = safe_r_sql("SELECT * FROM TNM_BsoConfig
                      WHERE BcTournament=$tourId AND BcEvent=".StrSafe_DB($evCode));
    return safe_fetch($rs);
}

// Structure des volées selon bsoCount : [round => nb équipes]
function getBsoStructure(int $bsoCount): array {
    return $bsoCount > 8 ? [1=>$bsoCount, 2=>8, 3=>4, 4=>2]
                          : [1=>$bsoCount, 2=>4, 3=>2];
}

// Nombre d'équipes qualifiées à l'issue d'une volée
// (= nb équipes de la volée suivante, ou 1 pour la dernière = le vainqueur)
function getBsoQualifiers(int $bsoCount, int $round): int {
    $struct = getBsoStructure($bsoCount);
    $total  = max(array_keys($struct));
    return $round >= $total ? 1 : $struct[$round + 1];
}

// Calcule les cibles centrées pour n équipes dans la plage [startTarget, startTarget+bsoCount-1]
// teams = tableau ordonné de CoId ; retourne [CoId => target]
function computeTargets(array $teams, int $startTarget, int $bsoCount): array {
    $n = count($teams);
    if ($n === 0) return [];
    // Centrer dans la plage d'origine
    $firstTarget = (int)round(($startTarget + $startTarget + $bsoCount - 1 - $n + 1) / 2);
    $result = [];
    foreach ($teams as $i => $teamId)
        $result[$teamId] = $firstTarget + $i;
    return $result;
}

// Séquence ordonnée des places possibles pour une volée
function buildSequence(int $Q, int $N): array {
    if ($Q >= 3) return array_merge(array_fill(0,$Q,'qualify'), array_fill(0,$N-$Q,'eliminate'));
    $seq = array_fill(0, $Q, 'qualify');
    for ($rk = $Q+1; $rk <= $N; $rk++) $seq[] = 'rank'.$rk;
    return $seq;
}

// 'qualify'/'eliminate'/'rankX' -> {status, rank, options}
function outcomeToResult($outcome, $Q, $distinct) {
    $options = labelOptions($distinct, $Q);
    if ($outcome === 'qualify')   return ['status'=>1, 'rank'=>($Q===1?1:null), 'options'=>$options];
    if ($outcome === 'eliminate') return ['status'=>0, 'rank'=>null, 'options'=>$options];
    if (preg_match('/^rank(\d+)$/', $outcome, $m)) return ['status'=>0, 'rank'=>(int)$m[1], 'options'=>$options];
    return ['status'=>null, 'rank'=>null, 'options'=>$options];
}

// statut/rang actuel -> type de place (pour calculer le résiduel)
function resultToOutcome($status, $rank) {
    if ($status === null) return null;
    if ((int)$status === 1) return 'qualify';
    return $rank !== null ? 'rank'.(int)$rank : 'eliminate';
}

// types ambigus -> noms des boutons front, dans l'ordre d'affichage souhaité
function labelOptions(array $distinct, int $Q): array {
    if (count($distinct) <= 1) return [];
    if ($Q === 1) return ['winner']; // tour final : un seul bouton

    $ranks = []; $hasQualify = false; $hasEliminate = false;
    foreach ($distinct as $o) {
        if ($o === 'qualify')        $hasQualify = true;
        elseif ($o === 'eliminate')  $hasEliminate = true;
        elseif (preg_match('/^rank(\d+)$/', $o, $m)) $ranks[] = (int)$m[1];
    }
    rsort($ranks); // ex: [4,3]
    $opts = array_map(fn($r) => 'rank'.$r, $ranks);
    if ($hasQualify)   $opts[] = 'qualify';
    if ($hasEliminate) $opts[] = 'eliminate';
    return $opts;
}

// Statuts "garantis" pendant la saisie (n < N), sans gestion des égalités.
// Une équipe est définitivement éliminée si Q équipes la dominent déjà (ce
// nombre ne peut que rester ≥ Q). Elle est définitivement qualifiée si même
// dans le pire des cas (toutes les équipes restantes la dépassent), son rang
// reste ≤ Q.
function computeBoundPlan(array $rows, int $Q, int $N): array {
    $n = count($rows);
    $plan = [];
    foreach ($rows as $r) {
        $score = intval($r->BvScore);
        $aboveStrict = 0; $tied = 0;
        foreach ($rows as $o) {
            $os = intval($o->BvScore);
            if ($os > $score) $aboveStrict++;
            if ($os === $score) $tied++;
        }
        if ($aboveStrict >= $Q)                              $status = 0;
        elseif ($aboveStrict + ($N - $n) + $tied <= $Q)      $status = 1;
        else                                                  $status = null;

        $plan[(int)$r->BvTeam] = ['status'=>$status, 'rank'=>null, 'options'=>[]];
    }
    return $plan;
}

// $rows triées par BvScore DESC. Retourne BvTeam => ['status','rank','options']
function computeGroupPlan(array $rows, array $sequence, int $Q): array {
    $plan = [];
    $groups = [];
    foreach ($rows as $r) $groups[intval($r->BvScore)][] = $r;
    krsort($groups);

    foreach ($groups as $score => $teams) {
        $size = count($teams);
        $window = array_splice($sequence, 0, $size);
        $distinct = array_values(array_unique($window));

        if (count($distinct) === 1) {
            foreach ($teams as $r) $plan[(int)$r->BvTeam] = outcomeToResult($distinct[0], $Q, []);
            continue;
        }

        // groupe ambigu : résiduel après prise en compte des choix manuels
        $residual = $window;
        foreach ($teams as $r) {
            if ((int)$r->BvManual === 1) {
                $o = resultToOutcome($r->BvStatus, $r->BvRank);
                $idx = $o !== null ? array_search($o, $residual) : false;
                if ($idx !== false) array_splice($residual, $idx, 1);
            }
        }
        $resDistinct = array_values(array_unique($residual));
        $nonManual = array_values(array_filter($teams, fn($r) => (int)$r->BvManual !== 1));

        $autoOutcome = (count($nonManual) > 0 && count($resDistinct) === 1
                        && count($residual) === count($nonManual)) ? $resDistinct[0] : null;

        foreach ($teams as $r) {
            $team = (int)$r->BvTeam;
            if ((int)$r->BvManual === 1) {
                $plan[$team] = outcomeToResult(resultToOutcome($r->BvStatus, $r->BvRank), $Q, $distinct);
            } elseif ($autoOutcome !== null) {
                $plan[$team] = outcomeToResult($autoOutcome, $Q, $distinct);
            } else {
                $plan[$team] = ['status'=>null, 'rank'=>null, 'options'=>labelOptions($distinct, $Q)];
            }
        }
    }
    return $plan;
}

// Écrit en base les statuts/rangs non manuels recalculés
function recomputeRound($tourId, $evCode, $round, $Q, $N) {
    $rs = safe_r_sql("SELECT BvTeam, BvScore, BvStatus, BvRank, BvManual FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvScore IS NOT NULL ORDER BY BvScore DESC, BvTeam ASC");
    $rows = [];
    while ($r = safe_fetch($rs)) $rows[] = $r;
    $n = count($rows);
    if ($n === 0) return;

    $plan = ($n === $N)
        ? computeGroupPlan($rows, buildSequence($Q, $N), $Q)
        : computeBoundPlan($rows, $Q, $N);

    foreach ($rows as $r) {
        if ((int)$r->BvManual === 1) continue;
        $p = $plan[(int)$r->BvTeam];
        $cur = $r->BvStatus === null ? null : (int)$r->BvStatus;
        $curRank = $r->BvRank === null ? null : (int)$r->BvRank;
        if ($cur === $p['status'] && $curRank === $p['rank']) continue;

        $sql  = $p['status'] === null ? 'NULL' : $p['status'];
        $rsql = $p['rank']   === null ? 'NULL' : $p['rank'];
        safe_r_sql("UPDATE TNM_BsoVolee SET BvStatus=$sql, BvRank=$rsql
            WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
            AND BvRound=$round AND BvTeam=".intval($r->BvTeam));
    }
}

// ── Volées normales (Q >= 3) : qualif/élim automatique avec ties protégés ────
function recomputeStatuses($tourId, $evCode, $round, $bsoCount) {
    $Q = getBsoQualifiers($bsoCount, $round);

    $rs = safe_r_sql("SELECT BvTeam, BvScore, BvStatus, BvManual FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvScore IS NOT NULL ORDER BY BvScore DESC");
    $rows = [];
    while ($r = safe_fetch($rs)) $rows[] = $r;

    $n = count($rows);
    if ($n < $Q) return;

    $cutoffScore = intval($rows[$Q-1]->BvScore);
    $aboveCount = 0;
    foreach ($rows as $r) if (intval($r->BvScore) > $cutoffScore) $aboveCount++;

    $atCutoff = array_values(array_filter($rows, fn($r) => intval($r->BvScore) === $cutoffScore));
    $remaining = $Q - $aboveCount;

    $manualQualified = 0;
    foreach ($atCutoff as $r) if ((int)$r->BvManual === 1 && (int)$r->BvStatus === 1) $manualQualified++;

    $nonManual = array_values(array_filter($atCutoff, fn($r) => (int)$r->BvManual !== 1));
    $stillNeeded = $remaining - $manualQualified;

    $autoResolve = null; // 1=qualifier, 0=éliminer, null=ambigu -> reset
    if (count($nonManual) > 0) {
        if ($stillNeeded <= 0)                  $autoResolve = 0;
        elseif ($stillNeeded === count($nonManual)) $autoResolve = 1;
    }

    foreach ($rows as $r) {
        $score = intval($r->BvScore);
        $team  = intval($r->BvTeam);

        if ($score > $cutoffScore)      $new = 1;
        elseif ($score < $cutoffScore)  $new = 0;
        else {
            if ((int)$r->BvManual === 1) continue; // décision protégée
            $new = $autoResolve; // peut être null -> réinitialise un ancien auto-statut
        }

        $cur = $r->BvStatus === null ? null : (int)$r->BvStatus;
        if ($cur === $new) continue;

        $sql = $new === null ? 'NULL' : $new;
        safe_r_sql("UPDATE TNM_BsoVolee SET BvStatus=$sql, BvRank=NULL
            WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
            AND BvRound=$round AND BvTeam=$team");
    }
}

// Place partagée pour les éliminés (volées normales, Q>=3)
function assignEliminatedRanks($tourId, $evCode, $round, $Q) {
    //$Q = getBsoQualifiers($bsoCount, $round);

    $rs = safe_r_sql("SELECT BvTeam, BvScore FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvStatus=0 ORDER BY BvScore DESC");
    $rows = [];
    while ($r = safe_fetch($rs)) $rows[] = $r;
    if (empty($rows)) return;

    $place = $Q + 1; $prevScore = null; $prevPlace = $place;
    foreach ($rows as $i => $r) {
        $score = intval($r->BvScore);
        $rank = ($i > 0 && $score === $prevScore) ? $prevPlace : $place;
        safe_r_sql("UPDATE TNM_BsoVolee SET BvRank=$rank
            WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
            AND BvRound=$round AND BvTeam=".intval($r->BvTeam));
        $prevScore = $score; $prevPlace = $rank; $place++;
    }
}

// ── Volées podium (Q < 3 : demi-finale Q=2, finale Q=1) ───────────────────────
// Plan en lecture seule : BvTeam => ['status'=>?, 'rank'=>?, 'options'=>[...]]
// $rows = lignes triées par BvScore DESC, avec BvTeam/BvScore/BvStatus/BvRank/BvManual
function computePodiumPlan(array $rows, int $Q, int $N): array {
    $individualSlots = [];
    for ($p = $N; $p > $Q; $p--) $individualSlots[] = $p; // ex: [4,3] ou [2]

    $plan = [];
    $remainingQualify = $Q;
    $used = [];

    foreach ($rows as $r) {
        if ((int)$r->BvManual === 1) {
            $status = $r->BvStatus !== null ? (int)$r->BvStatus : null;
            $rank   = $r->BvRank   !== null ? (int)$r->BvRank   : null;
            $plan[(int)$r->BvTeam] = ['status'=>$status,'rank'=>$rank,'options'=>[]];
            if ($status === 1) $remainingQualify--;
            if ($rank !== null) $used[] = $rank;
        }
    }
    $remainingIndividual = array_values(array_diff($individualSlots, $used));
    rsort($remainingIndividual); // ordre décroissant : 4 avant 3

    $free = array_values(array_filter($rows, fn($r) => (int)$r->BvManual !== 1));
    $groups = [];
    foreach ($free as $r) $groups[intval($r->BvScore)][] = $r;
    krsort($groups);

    $ambiguous = false;
    foreach ($groups as $score => $teams) {
        $size = count($teams);

        if (!$ambiguous && $remainingQualify > 0 && $size <= $remainingQualify) {
            $rank = ($Q === 1) ? 1 : null; // tour final : qualifié = vainqueur = rang 1
            foreach ($teams as $r) $plan[(int)$r->BvTeam] = ['status'=>1,'rank'=>$rank,'options'=>[]];
            $remainingQualify -= $size;
            continue;
        }
        if (!$ambiguous && $remainingQualify === 0 && $size === 1) {
            $rank = array_shift($remainingIndividual);
            $r = $teams[0];
            $plan[(int)$r->BvTeam] = ['status'=>0,'rank'=>$rank,'options'=>[]];
            continue;
        }

        // ambigu : ce groupe + tous les suivants
        $ambiguous = true;
        if ($Q === 1) {
            $options = ['winner']; // tour final : un seul bouton
        } else {
            $options = [];
            foreach ($remainingIndividual as $rk) $options[] = 'rank'.$rk; // 4ème, 3ème
            if ($remainingQualify > 0) $options[] = 'qualify';             // puis ✓
        }
        foreach ($teams as $r) $plan[(int)$r->BvTeam] = ['status'=>null,'rank'=>null,'options'=>$options];
    }
    return $plan;
}

function setPodiumResult($tourId, $evCode, $round, $row, $status, $rank) {
    $cur = $row->BvStatus === null ? null : (int)$row->BvStatus;
    $curRank = $row->BvRank === null ? null : (int)$row->BvRank;
    if ($cur === $status && $curRank === $rank) return;

    $sql = $status === null ? 'NULL' : $status;
    $rsql = $rank === null ? 'NULL' : $rank;
    safe_r_sql("UPDATE TNM_BsoVolee SET BvStatus=$sql, BvRank=$rsql
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvTeam=".intval($row->BvTeam));
}

function recomputePodium($tourId, $evCode, $round, $bsoCount, $Q) {
    $struct = getBsoStructure($bsoCount);
    $N = $struct[$round] ?? 0;
    if ($N <= 0) return;

    $rs = safe_r_sql("SELECT BvTeam, BvScore, BvStatus, BvRank, BvManual FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvScore IS NOT NULL ORDER BY BvScore DESC, BvTeam ASC");
    $rows = [];
    while ($r = safe_fetch($rs)) $rows[] = $r;
    if (count($rows) < $N) return;

    $plan = computePodiumPlan($rows, $Q, $N);
    foreach ($rows as $r) {
        if ((int)$r->BvManual === 1) continue;
        $p = $plan[(int)$r->BvTeam];
        setPodiumResult($tourId, $evCode, $round, $r, $p['status'], $p['rank']);
    }
}

function resolveGroupConflicts($tourId, $evCode, $round, $Q, $N, $changedTeam) {
    $rs = safe_r_sql("SELECT BvTeam, BvScore, BvStatus, BvRank, BvManual FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvScore IS NOT NULL ORDER BY BvScore DESC, BvTeam ASC");
    $rows = [];
    while ($r = safe_fetch($rs)) $rows[] = $r;
    if (count($rows) < $N) return;

    $groups = [];
    foreach ($rows as $r) $groups[intval($r->BvScore)][] = $r;
    krsort($groups);

    $sequence = buildSequence($Q, $N);
    foreach ($groups as $score => $teams) {
        $window = array_splice($sequence, 0, count($teams));
        $windowCounts = array_count_values($window);

        $manual = array_values(array_filter($teams, fn($r) => (int)$r->BvManual === 1));
        $claimCounts = [];
        foreach ($manual as $r) {
            $o = resultToOutcome($r->BvStatus, $r->BvRank);
            if ($o !== null) $claimCounts[$o] = ($claimCounts[$o] ?? 0) + 1;
        }

        foreach ($claimCounts as $outcome => $count) {
            $excess = $count - ($windowCounts[$outcome] ?? 0);
            if ($excess <= 0) continue;

            foreach ($manual as $r) {
                if ($excess <= 0) break;
                if ((int)$r->BvTeam === $changedTeam) continue;
                if (resultToOutcome($r->BvStatus, $r->BvRank) !== $outcome) continue;

                safe_r_sql("UPDATE TNM_BsoVolee SET BvStatus=NULL, BvRank=NULL, BvManual=0
                    WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
                    AND BvRound=$round AND BvTeam=".intval($r->BvTeam));
                $excess--;
            }
        }
    }
}

// ── Point d'entrée commun : à appeler après saveScore et setStatus ────────────
function refreshBsoRound($tourId, $evCode, $round, $bsoCount) {
    $Q = getBsoQualifiers($bsoCount, $round);
    $struct = getBsoStructure($bsoCount);
    $N = $struct[$round] ?? 0;
    if ($N <= 0) return;

    recomputeRound($tourId, $evCode, $round, $Q, $N);

    if ($Q >= 3) {
        $rs = safe_r_sql("SELECT COUNT(*) as n FROM TNM_BsoVolee
            WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
            AND BvRound=$round AND BvStatus IS NOT NULL");
        $r = safe_fetch($rs);
        if (($r ? intval($r->n) : 0) === $N) assignEliminatedRanks($tourId, $evCode, $round, $Q);
    }
}

// ── Routeur ───────────────────────────────────────────────────────────────────
switch ($act) {

// ── Liste des épreuves avec leur config ───────────────────────────────────────
case 'getEvents':
    $rs = safe_r_sql(
        "SELECT e.EvCode, e.EvEventName, b.BcBsoCount, b.BcStartTarget, b.BcSchedule
         FROM Events e
         LEFT JOIN TNM_BsoConfig b ON b.BcTournament=e.EvTournament AND b.BcEvent COLLATE utf8mb4_unicode_ci=e.EvCode
         WHERE e.EvElimType=5 AND e.EvTeamEvent='1'
         AND e.EvTournament=$tourId AND e.EvCodeParent=''
         ORDER BY e.EvProgr"
    );
    $rows = [];
    while ($r = safe_fetch($rs)) {
        $sch = json_decode($r->BcSchedule ?? '{}', true) ?? [];
        $rows[] = [
            'code'         => $r->EvCode,
            'name'         => get_text($r->EvEventName,'','',true),
            'bso_count'    => (int)($r->BcBsoCount ?? 10),
            'start_target' => (int)($r->BcStartTarget ?? 1),
            'start_time'   => $sch['1'] ?? '',
        ];
    }
    echo json_encode(['error' => 0, 'rows' => $rows]);
    break;

// ── Données d'une volée (avec delta sync) ─────────────────────────────────────
case 'getVolee':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $round  = intval($_REQUEST['round'] ?? 1);
    $since  = trim($_REQUEST['since'] ?? '');

    $cfg = getBsoConfig($tourId, $evCode);
    $bsoCount = $cfg ? intval($cfg->BcBsoCount) : 10;
    $Q = getBsoQualifiers($bsoCount, $round);
    $struct = getBsoStructure($bsoCount);
    $N = $struct[$round] ?? 0;

    $deltaSQL = $since ? "AND bv.BvUpdated > ".StrSafe_DB($since) : '';

    $rs = safe_r_sql(
        "SELECT bv.BvTeam, bv.BvTarget, bv.BvScore, bv.BvStatus, bv.BvRank, bv.BvUpdated,
                co.CoName, co.CoCode
         FROM TNM_BsoVolee bv
         LEFT JOIN Countries co ON co.CoId=bv.BvTeam AND co.CoTournament=bv.BvTournament
         WHERE bv.BvTournament=$tourId AND bv.BvEvent=".StrSafe_DB($evCode)."
         AND bv.BvRound=$round $deltaSQL
         ORDER BY bv.BvTarget ASC"
    );
    $allRows = [];
    while ($r = safe_fetch($rs)) $allRows[] = $r;

    // Plan podium (lecture seule), si applicable et tous les scores saisis
    $plan = [];
    if ($N > 0) {
        $rsAll = safe_r_sql("SELECT BvTeam, BvScore, BvStatus, BvRank, BvManual FROM TNM_BsoVolee
            WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
            AND BvRound=$round AND BvScore IS NOT NULL ORDER BY BvScore DESC, BvTeam ASC");
        $scored = [];
        while ($r = safe_fetch($rsAll)) $scored[] = $r;
        if (count($scored) === $N) $plan = computeGroupPlan($scored, buildSequence($Q, $N), $Q);
    }

    $rows = [];
    foreach ($allRows as $r) {
        $team = (int)$r->BvTeam;
        $status = $r->BvStatus !== null ? (int)$r->BvStatus : null;
        $rank   = $r->BvRank   !== null ? (int)$r->BvRank   : null;

        $options = isset($plan[$team]) ? $plan[$team]['options'] : [];
        if ($status === null && $r->BvScore !== null) {
            if (isset($plan[$team])) $options = $plan[$team]['options'];
            //elseif ($Q >= 3)         $options = ['eliminate','qualify']; // ordre affichage ✗ puis ✓
        }

        $rows[] = [
            'team'=>$team, 'target'=>(int)$r->BvTarget, 'name'=>$r->CoName, 'code'=>$r->CoCode,
            'score'=>$r->BvScore !== null ? (int)$r->BvScore : null,
            'status'=>$status, 'rank'=>$rank, 'options'=>$options, 'updated'=>$r->BvUpdated,
        ];
    }
    echo json_encode(['error'=>0,'rows'=>$rows,'serverDate'=>date('Y-m-d H:i:s'),
                       'qualifiers'=>$Q,'totalTeams'=>$N]);
    break;

// ── Volée courante (round le plus avancé ayant des données) ───────────────────
case 'getCurrentRound':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $rs = safe_r_sql(
        "SELECT MAX(BvRound) as r FROM TNM_BsoVolee
         WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)
    );
    $r = safe_fetch($rs);
    echo json_encode(['error' => 0, 'round' => $r ? (int)($r->r ?? 1) : 1]);
    break;

// ── Sauvegarde d'un score ─────────────────────────────────────────────────────
case 'saveScore':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $round  = intval($_REQUEST['round'] ?? 1);
    $team   = intval($_REQUEST['team']  ?? 0);
    $score  = intval($_REQUEST['score'] ?? 0);

    if ($evCode === '' || $round <= 0 || $team <= 0 || $score < 0) {
        echo json_encode(['error' => 1, 'msg' => 'Paramètres invalides']);
        break;
    }

    safe_r_sql("UPDATE TNM_BsoVolee SET BvScore=$score
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvTeam=$team");

    $cfg = getBsoConfig($tourId, $evCode);
    if ($cfg) refreshBsoRound($tourId, $evCode, $round, intval($cfg->BcBsoCount));
    echo json_encode(['error' => 0]);
    break;

// ── Définir le statut d'une équipe (qualifiée / éliminée) ────────────────────
// ── status: 0|1 ; rank optionnel (3,4 ou 1 pour "Vainqueur") ──────────────────
case 'setStatus':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $round  = intval($_REQUEST['round']  ?? 1);
    $team   = intval($_REQUEST['team']   ?? 0);
    $status = intval($_REQUEST['status'] ?? 0);
    $rank   = isset($_REQUEST['rank']) && $_REQUEST['rank'] !== '' ? intval($_REQUEST['rank']) : null;
    $rankSQL = $rank === null ? 'NULL' : $rank;

    if ($evCode === '' || $round <= 0 || $team <= 0) {
        echo json_encode(['error' => 1, 'msg' => 'Paramètres invalides']);
        break;
    }

    safe_r_sql("UPDATE TNM_BsoVolee SET BvStatus=$status, BvRank=$rankSQL, BvManual=1
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound=$round AND BvTeam=$team");

    $cfg = getBsoConfig($tourId, $evCode);
        if ($cfg) {
            $bsoCount = intval($cfg->BcBsoCount);
            $Q = getBsoQualifiers($bsoCount, $round);
            $struct = getBsoStructure($bsoCount);
            $N = $struct[$round] ?? 0;
            resolveGroupConflicts($tourId, $evCode, $round, $Q, $N, $team);
            refreshBsoRound($tourId, $evCode, $round, $bsoCount);
        }
    echo json_encode(['error' => 0]);
    break;

// ── Initialisation d'une volée (calcul équipes + cibles) ─────────────────────
case 'initVolee':
    requireWriteAcl();
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $round  = intval($_REQUEST['round'] ?? 1);
    if ($evCode === '' || $round <= 0) {
        echo json_encode(['error' => 1, 'msg' => 'Paramètres invalides']);
        break;
    }
    error_log("TNM BSO initVolee tourId=$tourId evCode=$evCode round=$round");

    $cfg = getBsoConfig($tourId, $evCode);
    if (!$cfg) { echo json_encode(['error' => 1, 'msg' => 'Config BSO manquante']); break; }

    $bsoCount    = intval($cfg->BcBsoCount);
    $startTarget = intval($cfg->BcStartTarget);
    $skipCheck   = !empty($cfg->BcSkipCheck);

    if ($round === 1) {
        // Équipes : top $bsoCount du classement poules principales Tour 2
        $rsLev = safe_r_sql(
            "SELECT RrLevGroupArchers FROM RoundRobinLevel
             WHERE RrLevTournament=$tourId AND RrLevEvent=".StrSafe_DB($evCode)." AND RrLevLevel=2"
        );
        $teamsPerPool = 4;
        if ($li = safe_fetch($rsLev)) $teamsPerPool = max(2, intval($li->RrLevGroupArchers));
        $nPoolsMain = intdiv($bsoCount * 2, $teamsPerPool);

        $rsTeams = safe_r_sql(
            "SELECT RrPartParticipant as teamId
             FROM RoundRobinParticipants
             WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode)."
             AND RrPartLevel=2 AND RrPartGroup<=$nPoolsMain
             ORDER BY   RrPartGroupRank ASC, RrPartPoints DESC,
                        RrPartTieBreaker DESC, RrPartTieBreaker2 DESC,
                        RrPartLevelRank ASC, RrPartGroup ASC
             LIMIT $bsoCount"
        );
    } else {
        if (!$skipCheck) {
            $expectedQual = getBsoQualifiers($bsoCount, $round - 1);
            $rsCnt = safe_r_sql("SELECT COUNT(*) as n FROM TNM_BsoVolee
                WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
                AND BvRound=".($round-1)." AND BvStatus=1");
            $cnt = safe_fetch($rsCnt);
            if (($cnt ? intval($cnt->n) : 0) !== $expectedQual) {
                echo json_encode(['error'=>1,
                    'msg'=>"Nombre d'équipes qualifiées incorrect : ".($cnt->n ?? 0)." (attendu : $expectedQual)"]);
                break;
            }

            // Nb total d'équipes ayant reçu une place finale sur les volées précédentes
            $expectedRanked = $bsoCount - $expectedQual;
            $rsRk = safe_r_sql("SELECT COUNT(*) as n FROM TNM_BsoVolee
                WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
                AND BvRound<$round AND BvRank IS NOT NULL");
            $rk = safe_fetch($rsRk);
            if (($rk ? intval($rk->n) : 0) !== $expectedRanked) {
                echo json_encode(['error'=>1,
                    'msg'=>"Classement incomplet : ".($rk->n ?? 0)." équipe(s) classée(s) sur $expectedRanked attendue(s). Vérifiez les égalités."]);
                break;
            }
        }
        // Équipes qualifiées de la volée précédente, dans l'ordre des cibles (préserve l'ordre)
        $rsTeams = safe_r_sql(
            "SELECT BvTeam as teamId, BvTarget as prevTarget FROM TNM_BsoVolee
             WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
             AND BvRound=".($round-1)." AND BvStatus=1
             ORDER BY BvTarget ASC"
        );
    }

    $teams = []; $prevTargets = [];
    while ($r = safe_fetch($rsTeams)) {
        $teams[] = intval($r->teamId);
        if (isset($r->prevTarget)) $prevTargets[intval($r->teamId)] = intval($r->prevTarget);
    }

    if (empty($teams)) { echo json_encode(['error' => 1, 'msg' => 'Aucune équipe trouvée']); break; }

    if ($round === 2) {
        // Volée 2 : on conserve les cibles de la volée 1, pas de regroupement
        $targets = $prevTargets;
    } else {
        // Volée 1 (déjà géré au-dessus) et volées 3+ : regroupement/centrage
        $targets = computeTargets($teams, $startTarget, $bsoCount);
    }

    // INSERT IGNORE : ne pas écraser les scores déjà saisis
    foreach ($targets as $teamId => $target) {
        safe_r_sql(
            "INSERT IGNORE INTO TNM_BsoVolee
             (BvTournament, BvEvent, BvRound, BvTeam, BvTarget)
             VALUES ($tourId, ".StrSafe_DB($evCode).", $round, $teamId, $target)"
        );
    }

    echo json_encode(['error' => 0, 'teams' => $teams, 'targets' => $targets]);
    break;

// ── Recalcule (corrige) toutes les volées complètes d'une épreuve ────────────
case 'recalcAll':
    requireWriteAcl();
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    error_log("TNM BSO recalcAll tourId=$tourId evCode=$evCode");
    $cfg = getBsoConfig($tourId, $evCode);
    if (!$cfg) { echo json_encode(['error'=>1,'msg'=>'Config BSO manquante']); break; }
    $bsoCount = intval($cfg->BcBsoCount);
    $struct = getBsoStructure($bsoCount);

    foreach (array_keys($struct) as $round) {
        $rs = safe_r_sql("SELECT COUNT(*) as n FROM TNM_BsoVolee
            WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
            AND BvRound=$round AND BvScore IS NOT NULL");
        $r = safe_fetch($rs);
        if (($r ? intval($r->n) : 0) > 0) refreshBsoRound($tourId, $evCode, $round, $bsoCount);
    }
    echo json_encode(['error'=>0]);
    break;

// ── Reset des scores (garde les équipes/cibles de la volée 1, supprime les volées suivantes) ─
case 'resetScores':
    requireWriteAcl();
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    if ($evCode === '') {
        echo json_encode(['error' => 1, 'msg' => 'Code épreuve manquant']);
        break;
    }
    error_log("TNM BSO resetScores tourId=$tourId evCode=$evCode");

    safe_r_sql(
        "DELETE FROM TNM_BsoVolee
         WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)." AND BvRound>1"
    );
    safe_r_sql(
        "UPDATE TNM_BsoVolee SET BvScore=NULL, BvStatus=NULL, BvRank=NULL, BvManual=0
         WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)." AND BvRound=1"
    );
    echo json_encode(['error' => 0]);
    break;

// ── Suppression complète des équipes BSO (toutes volées) ─────────────────────
case 'deleteTeams':
    requireWriteAcl();
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    if ($evCode === '') {
        echo json_encode(['error' => 1, 'msg' => 'Code épreuve manquant']);
        break;
    }
    error_log("TNM BSO deleteTeams tourId=$tourId evCode=$evCode");

    safe_r_sql(
        "DELETE FROM TNM_BsoVolee
         WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)
    );
    echo json_encode(['error' => 0]);
    break;

// ── Vue commentateur : volée en cours + équipes déjà éliminées/classées ──────
case 'getCommentateur':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');

    $rsR = safe_r_sql("SELECT MAX(BvRound) as r FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode));
    $rr = safe_fetch($rsR);
    $currentRound = $rr ? intval($rr->r ?? 0) : 0;
    if ($currentRound === 0) {
        echo json_encode(['error'=>1, 'msg'=>'Aucune donnée BSO pour cette épreuve']);
        break;
    }

    // Scores de toutes les volées <= volée en cours (pour la colonne "Scores")
    $rsScores = safe_r_sql("SELECT BvTeam, BvRound, BvScore FROM TNM_BsoVolee
        WHERE BvTournament=$tourId AND BvEvent=".StrSafe_DB($evCode)."
        AND BvRound<=$currentRound");
    $scoresByTeam = [];
    while ($r = safe_fetch($rsScores))
        $scoresByTeam[(int)$r->BvTeam][(int)$r->BvRound] = $r->BvScore !== null ? (int)$r->BvScore : null;

    // Infos poules (Tour 1 / Tour 2)
    $rsPool = safe_r_sql("SELECT RrPartParticipant, RrPartLevel, RrPartGroup, RrPartGroupRank
        FROM RoundRobinParticipants
        WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode));
    
    $poolInfo = [];
    while ($r = safe_fetch($rsPool))
        $poolInfo[(int)$r->RrPartParticipant][(int)$r->RrPartLevel] = [
            'rank'  => (int)$r->RrPartGroupRank,
            'group' => (int)$r->RrPartGroup,
        ];

    // Scores de qualification
    $rsTe = safe_r_sql("SELECT TeCoId, TeScore FROM Teams
        WHERE TeTournament=$tourId AND TeEvent=".StrSafe_DB($evCode));
    $teScores = [];
    while ($r = safe_fetch($rsTe))
        $teScores[(int)$r->TeCoId] = $r->TeScore !== null ? (int)$r->TeScore : null;

    // Équipes de la volée en cours, dans l'ordre des cibles
    $rsCur = safe_r_sql("SELECT bv.BvTeam, bv.BvTarget, bv.BvStatus, bv.BvRank,
                                co.CoName, co.CoCode
                         FROM TNM_BsoVolee bv
                         LEFT JOIN Countries co ON co.CoId=bv.BvTeam AND co.CoTournament=bv.BvTournament
                         WHERE bv.BvTournament=$tourId AND bv.BvEvent=".StrSafe_DB($evCode)."
                         AND bv.BvRound=$currentRound
                         ORDER BY bv.BvTarget ASC");
    $current = [];
    while ($r = safe_fetch($rsCur)) {
        $team = (int)$r->BvTeam;
        $scores = [];
        for ($rd = 1; $rd <= $currentRound; $rd++) $scores[] = $scoresByTeam[$team][$rd] ?? null;
        $current[] = [
            'team'   => $team, 'target' => (int)$r->BvTarget,
            'name'   => $r->CoName, 'code' => $r->CoCode,
            'scores' => $scores,
            'status' => $r->BvStatus !== null ? (int)$r->BvStatus : null,
            'rank'   => $r->BvRank   !== null ? (int)$r->BvRank   : null,
            'teScore'=> $teScores[$team] ?? null,
            'pools'  => $poolInfo[$team] ?? [],
        ];
    }

    // Équipes déjà éliminées et classées (volées précédentes)
    $rsElim = safe_r_sql("SELECT bv.BvTeam, bv.BvRound, bv.BvRank,
                                 co.CoName, co.CoCode
                          FROM TNM_BsoVolee bv
                          LEFT JOIN Countries co ON co.CoId=bv.BvTeam AND co.CoTournament=bv.BvTournament
                          WHERE bv.BvTournament=$tourId AND bv.BvEvent=".StrSafe_DB($evCode)."
                          AND bv.BvRound<$currentRound AND bv.BvRank IS NOT NULL
                          ORDER BY bv.BvRank ASC");
    $eliminated = [];
    while ($r = safe_fetch($rsElim)) {
        $team = (int)$r->BvTeam;
        $maxRd = (int)$r->BvRound;
        $scores = [];
        for ($rd = 1; $rd <= $maxRd; $rd++) $scores[] = $scoresByTeam[$team][$rd] ?? null;
        $eliminated[] = [
            'team'=>$team, 'name'=>$r->CoName, 'code'=>$r->CoCode,
            'scores'=>$scores, 'rank'=>(int)$r->BvRank,
            'teScore'=>$teScores[$team] ?? null,
            'pools'=>$poolInfo[$team] ?? [],
        ];
    }

    echo json_encode(['error'=>0, 'currentRound'=>$currentRound,
                       'current'=>$current, 'eliminated'=>$eliminated]);
    break;

// ── Aide à la répartition des poules Tour 2 (équipes "à classer") ────────────
case 'getPoolsAssist':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');

    $cfg = getBsoConfig($tourId, $evCode);
    if (!$cfg) { echo json_encode(['error'=>1,'msg'=>'Config BSO manquante']); break; }
    $bsoCount = intval($cfg->BcBsoCount);

    // teamsPerPool (niveau 2)
    $rsLev = safe_r_sql("SELECT RrLevGroupArchers FROM RoundRobinLevel
        WHERE RrLevTournament=$tourId AND RrLevEvent=".StrSafe_DB($evCode)." AND RrLevLevel=2");
    $teamsPerPool = 4;
    if ($li = safe_fetch($rsLev)) $teamsPerPool = max(2, intval($li->RrLevGroupArchers));
    $nPoolsMain = intdiv($bsoCount * 2, $teamsPerPool);

    // Noms d'équipes
    $rsNames = safe_r_sql("SELECT CoId, CoName FROM Countries WHERE CoTournament=$tourId");
    $names = [];
    while ($r = safe_fetch($rsNames)) $names[(int)$r->CoId] = $r->CoName;

    // Participants niveau 1 (poule d'origine + rang classement général)
    $rs1 = safe_r_sql("SELECT RrPartParticipant, RrPartGroup, RrPartLevelRank,
                              RrPartGroupRank, RrPartPoints, RrPartTieBreaker, RrPartTieBreaker2
                       FROM RoundRobinParticipants
                       WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode)."
                       AND RrPartLevel=1");
    $lvl1 = [];
    while ($r = safe_fetch($rs1)) $lvl1[(int)$r->RrPartParticipant] = $r;

    // Participants niveau 2 (composition des poules destination)
    $rs2 = safe_r_sql("SELECT RrPartGroup, RrPartParticipant, RrPartSourceGroup, RrPartSourceRank
                       FROM RoundRobinParticipants
                       WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode)."
                       AND RrPartLevel=2 ORDER BY RrPartGroup");
    $byDest = []; // RrGrGroup => [slots]
    while ($r = safe_fetch($rs2)) $byDest[(int)$r->RrPartGroup][] = $r;

    // ── Classement résiduel global (toutes équipes RrPartLevelRank != 0) ──────
    $residualTeams = [];
    foreach ($lvl1 as $coId => $p) {
        if (intval($p->RrPartLevelRank) !== 0) $residualTeams[] = $coId;
    }
    usort($residualTeams, function($a, $b) use ($lvl1) {
        $pa = $lvl1[$a]; $pb = $lvl1[$b];
        return intval($pa->RrPartGroupRank) - intval($pb->RrPartGroupRank)
            ?: intval($pb->RrPartPoints)      - intval($pa->RrPartPoints)
            ?: intval($pb->RrPartTieBreaker)  - intval($pa->RrPartTieBreaker)
            ?: intval($pb->RrPartTieBreaker2) - intval($pa->RrPartTieBreaker2)
            ?: intval($pa->RrPartLevelRank) - intval($pb->RrPartLevelRank);
    });

    // ── Construire fixes(D) et residuels(D) pour chaque poule destination ─────
    $fixes = []; $residuels = []; $destOfTeam = [];
    foreach ($byDest as $dest => $slots) {
        $fixes[$dest] = []; $residuels[$dest] = [];
        foreach ($slots as $s) {
            if (intval($s->RrPartSourceGroup) !== 0) {
                $fixes[$dest][] = intval($s->RrPartSourceGroup);
            } else {
                $part = intval($s->RrPartParticipant);
                if ($part !== 0 && isset($lvl1[$part])) {
                    $origin = intval($lvl1[$part]->RrPartGroup);
                    $residuels[$dest][] = ['team'=>$part, 'origin'=>$origin];
                    $destOfTeam[$part] = $dest;
                }
            }
        }
    }

    // liste(D) finale = fixes(D) + origines apparaissant >=2x dans residuels(D)
    $listeD = [];
    foreach ($byDest as $dest => $slots) {
        $counts = [];
        foreach ($residuels[$dest] as $r) $counts[$r['origin']] = ($counts[$r['origin']] ?? 0) + 1;
        $dups = array_keys(array_filter($counts, fn($c) => $c >= 2));
        $listeD[$dest] = array_values(array_unique(array_merge($fixes[$dest] ?? [], $dups)));
        sort($listeD[$dest]);
    }

    // ── Découpage segments PP / PC ─────────────────────────────────────────────
    $ppDest = array_values(array_filter(array_keys($byDest), fn($d) => $d <= $nPoolsMain));
    $pcDest = array_values(array_filter(array_keys($byDest), fn($d) => $d >  $nPoolsMain));
    sort($ppDest); sort($pcDest);

    $sizePP = 0; foreach ($ppDest as $d) foreach ($byDest[$d] as $s) if (intval($s->RrPartSourceGroup)===0) $sizePP++;
    $sizePC = 0; foreach ($pcDest as $d) foreach ($byDest[$d] as $s) if (intval($s->RrPartSourceGroup)===0) $sizePC++;

    $ppTeams = array_slice($residualTeams, 0, $sizePP);
    $pcTeams = array_slice($residualTeams, $sizePP, $sizePC);

    // ── Construction de la sortie ──────────────────────────────────────────────
    function buildSegment($teams, $dests, $listeD, $destOfTeam, $lvl1, $names, $prefix) {
        $columns = [];
        foreach ($dests as $i => $d) $columns[] = ['dest'=>$d, 'label'=>$prefix.($i+1), 'liste'=>$listeD[$d] ?? []];

        $rows = [];
        foreach ($teams as $coId) {
            $origin = isset($lvl1[$coId]) ? intval($lvl1[$coId]->RrPartGroup) : null;
            $cells = [];
            foreach ($dests as $d) {
                $liste = $listeD[$d] ?? [];
                $conflict = $origin !== null && in_array($origin, $liste);
                $cells[] = [
                    'liste' => $liste,
                    'conflict' => $conflict,
                    'highlight' => isset($destOfTeam[$coId]) && $destOfTeam[$coId] === $d,
                ];
            }
            $rows[] = [
                'team' => $coId, 'name' => $names[$coId] ?? ('#'.$coId),
                'origin' => $origin, 'cells' => $cells,
            ];
        }
        return ['columns'=>$columns, 'rows'=>$rows];
    }

    echo json_encode([
        'error'=>0,
        'pp'=>buildSegment($ppTeams, $ppDest, $listeD, $destOfTeam, $lvl1, $names, 'PP'),
        'pc'=>buildSegment($pcTeams, $pcDest, $listeD, $destOfTeam, $lvl1, $names, 'PC'),
    ]);
    break;
    
// ── Lecture des participants d'une poule (pour édition manuelle) ─────────────
case 'getPoolTeams':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $level  = intval($_REQUEST['level'] ?? 1);
    $group  = intval($_REQUEST['group'] ?? 0);

    $rs = safe_r_sql("SELECT p.RrPartParticipant, p.RrPartGroupRankBefSO, p.RrPartGroupRank,
                              p.RrPartPoints, p.RrPartTieBreaker, p.RrPartTieBreaker2, p.RrPartIrmType,
                              co.CoName
                       FROM RoundRobinParticipants p
                       LEFT JOIN Countries co ON co.CoId=p.RrPartParticipant AND co.CoTournament=p.RrPartTournament
                       WHERE p.RrPartTournament=$tourId AND p.RrPartEvent=".StrSafe_DB($evCode)."
                       AND p.RrPartLevel=$level AND p.RrPartGroup=$group
                       ORDER BY p.RrPartGroupRankBefSO ASC, p.RrPartParticipant ASC");
    $rows = [];
    while ($r = safe_fetch($rs)) {
        $rows[] = [
            'team' => (int)$r->RrPartParticipant,
            'name' => $r->CoName,
            'groupRankBefSO' => (int)$r->RrPartGroupRankBefSO,
            'groupRank'      => (int)$r->RrPartGroupRank,
            'points'         => (int)$r->RrPartPoints,
            'tieBreaker'     => (int)$r->RrPartTieBreaker,
            'tieBreaker2'    => (int)$r->RrPartTieBreaker2,
            'irmType'        => (int)$r->RrPartIrmType,
        ];
    }
    echo json_encode(['error'=>0, 'rows'=>$rows]);
    break;

// ── Écriture manuelle des valeurs de classement d'une équipe ─────────────────
case 'setPoolTeamRanking':
    requireWriteAcl();
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');
    $level  = intval($_REQUEST['level'] ?? 1);
    $group  = intval($_REQUEST['group'] ?? 0);
    $team   = intval($_REQUEST['team']  ?? 0);
    if ($evCode === '' || $team <= 0 || $level <= 0 || $group <= 0) {
        echo json_encode(['error' => 1, 'msg' => 'Paramètres invalides']);
        break;
    }

    $groupRankBefSO = intval($_REQUEST['groupRankBefSO'] ?? 0);
    $groupRank      = intval($_REQUEST['groupRank'] ?? 0);
    $points         = intval($_REQUEST['points'] ?? 0);
    $tieBreaker     = intval($_REQUEST['tieBreaker'] ?? 0);
    $tieBreaker2    = intval($_REQUEST['tieBreaker2'] ?? 0);
    $irmType        = intval($_REQUEST['irmType'] ?? 0);

    safe_r_sql("UPDATE RoundRobinParticipants SET
        RrPartGroupRankBefSO=$groupRankBefSO, RrPartGroupRank=$groupRank,
        RrPartPoints=$points, RrPartTieBreaker=$tieBreaker, RrPartTieBreaker2=$tieBreaker2,
        RrPartIrmType=$irmType
        WHERE RrPartTournament=$tourId AND RrPartEvent=".StrSafe_DB($evCode)."
        AND RrPartLevel=$level AND RrPartGroup=$group AND RrPartParticipant=$team");

    echo json_encode(['error'=>0]);
    break;
    
// ── Composition des poules Tour 1 avec détection des conflits géographiques ───
case 'getPoolsTour1':
    $evCode = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['event'] ?? '');

    $rs = safe_r_sql(
        "SELECT rp.RrPartGroup, rp.RrPartParticipant,
                co.CoName, co.CoCode
         FROM RoundRobinParticipants rp
         LEFT JOIN Countries co ON co.CoId=rp.RrPartParticipant AND co.CoTournament=rp.RrPartTournament
         WHERE rp.RrPartTournament=$tourId AND rp.RrPartTeam=1 AND rp.RrPartLevel=1
           AND rp.RrPartEvent=" . StrSafe_DB($evCode) . "
           AND rp.RrPartParticipant>0
         ORDER BY rp.RrPartGroup ASC, rp.RrPartParticipant ASC"
    );

    $poolsByGroup = [];
    while ($r = safe_fetch($rs)) {
        $group = (int)$r->RrPartGroup;
        if (!isset($poolsByGroup[$group])) $poolsByGroup[$group] = [];
        $code = $r->CoCode ?? '';
        $poolsByGroup[$group][] = [
            'team'   => (int)$r->RrPartParticipant,
            'name'   => $r->CoName ?? '',
            'code'   => $code,
            'region' => substr($code, 0, 2),
            'dept'   => substr($code, 0, 4),
        ];
    }

    if (empty($poolsByGroup)) {
        echo json_encode(['error' => 0, 'pools' => [], 'conflicts' => 0]);
        break;
    }

    $result = [];
    $conflicts = 0;
    foreach ($poolsByGroup as $group => $members) {
        $deptCount = []; $regionCount = [];
        foreach ($members as $t) {
            if ($t['dept'])   $deptCount[$t['dept']]     = ($deptCount[$t['dept']]     ?? 0) + 1;
            if ($t['region']) $regionCount[$t['region']] = ($regionCount[$t['region']] ?? 0) + 1;
        }
        $out = [];
        foreach ($members as $t) {
            $dc = $t['dept']   !== '' && ($deptCount[$t['dept']]     ?? 0) > 1;
            $rc = !$dc && $t['region'] !== '' && ($regionCount[$t['region']] ?? 0) > 1;
            if ($dc || $rc) $conflicts++;
            $out[] = [
                'team'          => $t['team'],
                'name'          => $t['name'],
                'code'          => $t['code'],
                'deptConflict'  => $dc,
                'regionConflict'=> $rc,
            ];
        }
        $result[] = ['group' => $group, 'teams' => $out];
    }

    echo json_encode(['error' => 0, 'pools' => $result, 'conflicts' => $conflicts]);
    break;

default:
    echo json_encode(['error' => 1, 'msg' => 'Action inconnue : '.$act]);
}
