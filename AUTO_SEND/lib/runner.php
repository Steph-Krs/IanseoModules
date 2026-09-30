<?php
/**
 * What the scheduled task does on each run (cron.php, every minute).
 *
 * WHY A RUN EVERY MINUTE
 * PHP on a web server only runs when something asks for a page; nothing inside
 * ianseo can wake up at 08:00 on its own. The system's scheduler (cron) is that
 * alarm clock. Each run reads the plans, does what is due, and stops; almost
 * always nothing is due and the run costs two small queries. Waking every
 * minute is what lets the start and end times be kept to the minute and the
 * interval be changed from the module's page, without ever touching the
 * server's configuration again.
 *
 * WHY EVERY RUN STARTS FROM SCRATCH
 * The browser page this replaces kept its schedule in a JavaScript timer, and
 * one failed request could stop that timer for good. Here nothing is carried
 * from one run to the next except what is in the database, so a failure (the
 * database restarting, ianseo.net unreachable, the network down) costs at most
 * that run: the next one starts over.
 *
 * WHY THE UPLOAD RUNS IN A SEPARATE PROCESS
 * The core upload ends with exit() (JsonOut), and a database error anywhere
 * inside it with another exit() (safe_error). Running it in worker.php keeps
 * those from ending this script, lets its answer be read and recorded whatever
 * happens, and lets a stuck upload be stopped after a time limit.
 *
 * One run at a time: a MySQL named lock, held by this process's connection and
 * released by MySQL itself if the process dies, so an upload longer than a
 * minute makes the following runs step aside instead of piling up.
 */

// Hard limit of one upload, in seconds (AUTOSEND_TIMEOUT overrides it for tests).
const AUS_WORKER_TIMEOUT = 600;

/**
 * One run of the scheduled task.
 */
function aus_tick() {
    $now = time();

    // $force: the tables exist once a page of the module has been opened; until
    // then there is nothing to do, and a missing table must not be an error.
    $q = safe_r_sql("SELECT AsTournament FROM AutoSendPlans", false, true);
    if ($q === false || !safe_num_rows($q)) return;

    // Proof of life for the module's page, written on every run, busy or not.
    safe_w_sql("UPDATE AutoSendPlans SET AsHeartbeat=" . StrSafe_DB(aus_utc($now)));

    if (!aus_lock()) return;

    // Read after taking the lock: a run that has just finished may have changed them.
    $plans = [];
    $q = safe_r_sql("SELECT AutoSendPlans.*, ToCode
        FROM AutoSendPlans
        INNER JOIN Tournament ON ToId=AsTournament
        WHERE AsEnabled=1 OR AsSendNow=1");
    while ($r = safe_fetch($q)) $plans[] = $r;

    foreach ($plans as $p) aus_process_plan($p, time());

    if ((int)gmdate('i', $now) === 7) {
        safe_w_sql("DELETE FROM AutoSendRuns WHERE ArWhen < "
            . StrSafe_DB(aus_utc($now - AUS_RUNS_KEEP_DAYS * 86400)));
    }
}

/**
 * Take the module's lock, without waiting.
 *
 * @return bool False when another run holds it.
 */
function aus_lock() {
    global $CFG;
    // bytes: GET_LOCK names are limited to 64 characters; database names are ASCII.
    $name = substr($CFG->DB_NAME . '.AutoSend', 0, 64);
    $q = safe_r_sql("SELECT GET_LOCK(" . StrSafe_DB($name) . ", 0) AS Got");
    $r = safe_fetch($q);
    return $r && (int)$r->Got === 1;
}

/**
 * Do whatever is due for one competition.
 *
 * Order matters at the end of the window: the scoring is closed first, then the
 * final upload runs, so that it carries every score entered before the close.
 *
 * @param object $p Plan row, with ToCode.
 * @param int $now
 */
function aus_process_plan($p, $now) {
    $tour  = (int)$p->AsTournament;
    $start = aus_ts($p->AsStart);
    $end   = aus_ts($p->AsEnd);
    $scheduled = $p->AsEnabled && $start !== null && $end !== null;
    $inWindow  = $scheduled && $now >= $start && $now < $end;
    $after     = $scheduled && $now >= $end;
    $keys      = aus_json_array($p->AsSessionKeys);

    // Once per schedule, with catch-up: a server that was down at the start
    // time opens the scoring on its first run inside the window.
    if ($p->AsSessions && $keys && $inWindow && $p->AsOpened === null) {
        aus_sessions_set($tour, $keys, true);
        safe_w_sql("UPDATE AutoSendPlans SET AsOpened=" . StrSafe_DB(aus_utc($now)) . " WHERE AsTournament=$tour");
        aus_record_run($tour, 'open', ['ok' => true, 'code' => 'SessionsOpened', 'detail' => implode(', ', $keys)], $now);
    }
    if ($p->AsSessions && $keys && $after && $p->AsClosed === null) {
        aus_sessions_set($tour, $keys, false);
        safe_w_sql("UPDATE AutoSendPlans SET AsClosed=" . StrSafe_DB(aus_utc($now)) . " WHERE AsTournament=$tour");
        aus_record_run($tour, 'close', ['ok' => true, 'code' => 'SessionsClosed', 'detail' => implode(', ', $keys)], $now);
    }

    $next = aus_ts($p->AsNextSend);
    $due  = $next === null || $next <= $now;
    $kind = null;
    if ($p->AsSendNow) {
        $kind = 'manual';
    } elseif ($inWindow && $due) {
        $kind = 'send';
    } elseif ($after && $p->AsFinalSent === null && $due && $now < $end + AUS_FINAL_GRACE) {
        $kind = 'final';
    }

    $outcome = null;
    if ($kind !== null) {
        // Cleared before the upload: a request that crashes the upload must not
        // be replayed on every following run.
        safe_w_sql("UPDATE AutoSendPlans SET AsSendNow=0, AsLastTry=" . StrSafe_DB(aus_utc($now))
            . " WHERE AsTournament=$tour");

        $outcome = aus_run_worker($tour);
        aus_record_run($tour, $kind, $outcome, $now);

        $interval = max(AUS_INTERVAL_MIN, (int)$p->AsInterval);
        if ($outcome['ok']) {
            // From the minute the upload started, so the pace does not drift by
            // the few seconds each upload takes.
            $nextSend = $now - $now % 60 + $interval * 60;
            safe_w_sql("UPDATE AutoSendPlans SET AsFailures=0,
                    AsLastSuccess=" . StrSafe_DB(aus_utc($now)) . ",
                    AsNextSend=" . StrSafe_DB(aus_utc($nextSend)) . ",
                    AsLastCode=" . StrSafe_DB($outcome['code']) . ",
                    AsLastDetail=" . StrSafe_DB($outcome['detail'])
                    . ($kind === 'final' ? ", AsFinalSent=" . StrSafe_DB(aus_utc($now)) : '') . "
                WHERE AsTournament=$tour");
        } else {
            $failures = (int)$p->AsFailures + 1;
            $nextSend = $now - $now % 60 + aus_backoff($failures, $interval) * 60;
            safe_w_sql("UPDATE AutoSendPlans SET AsFailures=$failures,
                    AsNextSend=" . StrSafe_DB(aus_utc($nextSend)) . ",
                    AsLastCode=" . StrSafe_DB($outcome['code']) . ",
                    AsLastDetail=" . StrSafe_DB($outcome['detail']) . "
                WHERE AsTournament=$tour");
        }
    }

    aus_ping_maybe($p, $outcome, $now);
}

/**
 * Run worker.php for one competition and read what happened.
 *
 * The child writes to temporary files rather than pipes: reading pipes without
 * blocking does not work on Windows, and a child that fills a pipe nobody
 * reads would hang forever.
 *
 * @param int $tour
 * @return array ['ok', 'code', 'detail', 'seconds', 'bytes', 'simulation']
 */
function aus_run_worker($tour) {
    $timeout = (int)getenv('AUTOSEND_TIMEOUT') ?: AUS_WORKER_TIMEOUT;
    $outFile = tempnam(sys_get_temp_dir(), 'aus');
    $errFile = tempnam(sys_get_temp_dir(), 'aus');
    $t0 = microtime(true);

    $proc = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/worker.php', (string)(int)$tour],
        [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        return aus_outcome(false, 'ErrStart', '', $t0);
    }
    fclose($pipes[0]);

    $timedOut = false;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (microtime(true) - $t0 > $timeout) {
            proc_terminate($proc, 9);
            $timedOut = true;
            break;
        }
        usleep(200000);
    }
    proc_close($proc);

    $stdout = trim((string)@file_get_contents($outFile));
    $stderr = (string)@file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);

    $bytes = 0;
    $simulation = false;
    if (preg_match('/^AUTOSEND-DRY (\{.*\})\s*$/m', $stderr, $m)) {
        $simulation = true;
        $bytes = (int)(json_decode($m[1], true)['bytes'] ?? 0);
    }
    $notes = '';
    if (preg_match('/^AUTOSEND-NOTE (\{.*\})\s*$/m', $stderr, $m)) {
        $notes = aus_notes_text(json_decode($m[1], true) ?: []);
    }

    if ($timedOut) {
        return aus_outcome(false, 'ErrTimeout', (string)$timeout, $t0, $bytes, $simulation);
    }

    $json = aus_json_answer($stdout);
    if (!is_array($json)) {
        // A safe_error() page, or a fatal error on STDERR.
        $raw = $stdout !== '' ? $stdout : preg_replace('/^AUTOSEND-(DRY|NOTE) .*$/m', '', $stderr);
        return aus_outcome(false, 'ErrWorker', aus_plain($raw), $t0, $bytes, $simulation);
    }

    // Answers of the worker's own checks.
    if (isset($json['aus_code'])) {
        $ok = (int)($json['error'] ?? 1) === 0;
        return aus_outcome($ok, (string)$json['aus_code'], trim(aus_plain($json['aus_detail'] ?? '') . ' ' . $notes), $t0, $bytes, $simulation);
    }

    // The core upload's answer.
    if ((int)($json['error'] ?? 1) === 0) {
        return aus_outcome(true, $simulation ? 'SentSimulated' : 'Sent', $notes, $t0, $bytes, $simulation);
    }
    $detail = aus_plain($json['msg'] ?? '');
    // ianseo.net's raw answer only helps when it is not the JSON the message came from.
    if (!empty($json['debug']) && json_decode((string)$json['debug']) === null) {
        $detail .= ' — ' . mb_substr(aus_plain($json['debug']), 0, 300);
    }
    return aus_outcome(false, 'ErrIanseoNet', $detail, $t0, $bytes, $simulation);
}

/**
 * The JSON answer at the end of the worker's output.
 *
 * PHP can print start-up warnings (a mismatched extension, for instance) on
 * STDOUT before the script even runs; the answer is what follows them.
 *
 * @param string $stdout
 * @return array|null
 */
function aus_json_answer($stdout) {
    $json = json_decode($stdout, true);
    if (is_array($json)) return $json;
    // bytes: strpos and substr agree on byte offsets.
    $pos = strpos($stdout, '{"');
    if ($pos === false) return null;
    $json = json_decode(substr($stdout, $pos), true);
    return is_array($json) ? $json : null;
}

/**
 * Build an outcome array.
 */
function aus_outcome($ok, $code, $detail, $t0, $bytes = 0, $simulation = false) {
    return [
        'ok'         => (bool)$ok,
        'code'       => $code,
        'detail'     => mb_substr(trim((string)$detail), 0, 2000),
        'seconds'    => round(microtime(true) - $t0, 2),
        'bytes'      => (int)$bytes,
        'simulation' => (bool)$simulation,
    ];
}

/**
 * The worker's remarks on the selection, as one line of text.
 *
 * Kept as item codes (IQCL, IBCO…), which read the same in every language.
 *
 * @param array $notes ['waiting' => [...], 'missing' => [...]]
 * @return string
 */
function aus_notes_text(array $notes) {
    $parts = [];
    if (!empty($notes['waiting'])) $parts[] = 'waiting: ' . implode(', ', $notes['waiting']);
    if (!empty($notes['missing'])) $parts[] = 'missing: ' . implode(', ', $notes['missing']);
    return implode(' — ', $parts);
}

/**
 * Plain text from a fragment of HTML answered by the core.
 *
 * @param string $html
 * @return string
 */
function aus_plain($html) {
    $s = preg_replace('#<br\s*/?>#i', ' ', (string)$html);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Add a line to the history.
 *
 * @param int $tour
 * @param string $kind send, manual, final, open or close.
 * @param array $o Outcome.
 * @param int $now
 */
function aus_record_run($tour, $kind, array $o, $now) {
    safe_w_sql("INSERT INTO AutoSendRuns (ArTournament, ArWhen, ArKind, ArOk, ArSimulation, ArSeconds, ArBytes, ArCode, ArDetail)
        VALUES (" . (int)$tour . ", " . StrSafe_DB(aus_utc($now)) . ", " . StrSafe_DB($kind) . ", "
        . ($o['ok'] ? 1 : 0) . ", " . (empty($o['simulation']) ? 0 : 1) . ", "
        . (float)($o['seconds'] ?? 0) . ", " . (int)($o['bytes'] ?? 0) . ", "
        . StrSafe_DB($o['code']) . ", " . StrSafe_DB((string)($o['detail'] ?? '')) . ")");
}

/**
 * Tell the outside monitoring service how things stand, when one is configured.
 *
 * A "dead man's switch" (healthchecks.io and compatible services) raises the
 * alarm when the pings STOP coming, which is the only way to learn that the
 * server itself, its network or this scheduled task is down: an alert sent by
 * the server cannot report its own absence. While a plan is enabled the service
 * therefore hears from it at least once per interval: after every upload, and
 * between uploads as a sign of life.
 *
 * Nothing is sent in simulation: a green light while nothing is published would
 * be the very silence this module exists to prevent.
 *
 * @param object $p Plan row as read before this run.
 * @param array|null $outcome The upload made during this run, if any.
 * @param int $now
 */
function aus_ping_maybe($p, $outcome, $now) {
    if ($p->AsPingUrl === '' || $p->AsSimulation || !$p->AsEnabled) return;

    if ($outcome !== null) {
        $ok = $outcome['ok'];
        $detail = $outcome['code'] . ' ' . $outcome['detail'];
    } else {
        $last = aus_ts($p->AsLastPing);
        if ($last !== null && $now - $last < max(AUS_INTERVAL_MIN, (int)$p->AsInterval) * 60) return;
        $ok = (int)$p->AsFailures === 0;
        $detail = $ok ? '' : $p->AsLastCode . ' ' . $p->AsLastDetail;
    }

    aus_ping($p->AsPingUrl, $ok, $detail);
    safe_w_sql("UPDATE AutoSendPlans SET AsLastPing=" . StrSafe_DB(aus_utc($now))
        . " WHERE AsTournament=" . (int)$p->AsTournament);
}

/**
 * Send one ping: the URL itself on success, URL/fail on failure (healthchecks.io convention).
 *
 * @param string $url
 * @param bool $ok
 * @param string $detail Sent as the body, shown in the service's log.
 * @return bool Whether the service answered.
 */
function aus_ping($url, $ok, $detail = '') {
    $target = $ok ? $url : rtrim($url, '/') . '/fail';
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: text/plain; charset=utf-8\r\nUser-Agent: ianseo-autosend\r\n",
        'content'       => mb_substr((string)$detail, 0, 2000),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($target, false, $ctx) !== false;
}
