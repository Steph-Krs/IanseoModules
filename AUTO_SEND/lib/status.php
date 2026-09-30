<?php
/**
 * The status panel's data: what the scheduled task did and will do.
 *
 * Every sentence is built and translated here, so the page's script only lays
 * out what it receives.
 */

// A run every minute: past this many seconds without one, the task is not running.
const AUS_HEARTBEAT_LATE = 180;

const AUS_KIND_KEYS = [
    'send'   => 'KindSend',
    'manual' => 'KindManual',
    'final'  => 'KindFinal',
    'open'   => 'KindOpen',
    'close'  => 'KindClose',
];

/**
 * Sentence describing an outcome recorded by the scheduled task.
 *
 * @param string $code Outcome code (Sent, ErrTimeout…).
 * @param string|null $detail Detail recorded with it.
 * @return string
 */
function aus_outcome_text($code, $detail) {
    $detail = trim((string)$detail);
    $all = aus_lang_all();
    $key = 'Out' . $code;
    if (!isset($all[$key])) return trim($code . ' ' . $detail);
    if (strpos($all[$key], '{$a}') !== false) return aus_text($key, $detail);
    if ($detail === '') return $all[$key];
    // A sentence ending with a colon introduces the detail; any other is followed by it.
    return $all[$key] . (mb_substr(rtrim($all[$key]), -1) === ':' ? ' ' : ' — ') . $detail;
}

/**
 * Everything the status panel shows for the open competition.
 *
 * @param int $tour
 * @return array
 */
function aus_status($tour) {
    $p = aus_plan_load($tour);
    if (!$p) return ['error' => 1, 'msg' => aus_text('OutErrNoPlan')];

    $now = time();
    $tz = aus_valid_zone($p->AsTimeZone) ? $p->AsTimeZone : 'UTC';

    $hb = aus_ts($p->AsHeartbeat);
    if ($hb === null) {
        $heartbeat = ['ok' => false, 'text' => aus_text('HeartbeatNever')];
    } elseif ($now - $hb > AUS_HEARTBEAT_LATE) {
        $heartbeat = ['ok' => false, 'text' => aus_text('HeartbeatLate', (int)floor(($now - $hb) / 60))];
    } else {
        $heartbeat = ['ok' => true, 'text' => aus_text('HeartbeatOk')];
    }

    $phase = aus_plan_phase($p, $now);
    switch ($phase) {
        case 'waiting':   $phaseText = aus_text('PhaseWaiting', aus_display_time($p->AsStart, $tz)); break;
        case 'running':   $phaseText = aus_text('PhaseRunning', aus_display_time($p->AsEnd, $tz)); break;
        case 'finishing': $phaseText = aus_text('PhaseFinishing'); break;
        case 'done':      $phaseText = aus_text('PhaseDone', aus_display_time($p->AsEnd, $tz)); break;
        case 'incomplete': $phaseText = aus_text('PhaseIncomplete'); break;
        default:          $phaseText = aus_text('PhaseDisabled');
    }

    $locked = aus_sessions_locked($tour);
    $sessions = [];
    foreach (aus_json_array($p->AsSessionKeys) as $key) {
        $sessions[] = ['key' => $key, 'open' => !in_array($key, $locked, true)];
    }

    $runs = [];
    $q = safe_r_sql("SELECT * FROM AutoSendRuns WHERE ArTournament=" . (int)$tour . " ORDER BY ArId DESC LIMIT 30");
    while ($r = safe_fetch($q)) {
        $runs[] = [
            'when'       => aus_utc_to_local($r->ArWhen, $tz, 'Y-m-d H:i:s'),
            'kind'       => aus_text(AUS_KIND_KEYS[$r->ArKind] ?? 'KindSend'),
            'ok'         => (bool)$r->ArOk,
            'simulation' => (bool)$r->ArSimulation,
            'seconds'    => in_array($r->ArKind, ['open', 'close'], true) ? '' : number_format((float)$r->ArSeconds, 1),
            'size'       => $r->ArBytes ? number_format($r->ArBytes / 1024, 0) . ' KB' : '',
            'text'       => aus_outcome_text($r->ArCode, $r->ArDetail),
        ];
    }

    $failing = (int)$p->AsFailures > 0;
    return [
        'error'       => 0,
        'heartbeat'   => $heartbeat,
        'phase'       => $phase,
        'phaseText'   => $phaseText,
        'enabled'     => (bool)$p->AsEnabled,
        'simulation'  => (bool)$p->AsSimulation,
        'lastSuccess' => $p->AsLastSuccess ? aus_display_time($p->AsLastSuccess, $tz) : '',
        'nextSend'    => in_array($phase, ['waiting', 'running', 'finishing'], true) && $p->AsNextSend
            ? aus_display_time($p->AsNextSend, $tz) : '',
        'failures'    => (int)$p->AsFailures,
        'failing'     => $failing,
        'lastText'    => $p->AsLastCode !== '' ? aus_outcome_text($p->AsLastCode, $p->AsLastDetail) : '',
        'sendNow'     => (bool)$p->AsSendNow,
        'sessions'    => $sessions,
        'runs'        => $runs,
    ];
}
