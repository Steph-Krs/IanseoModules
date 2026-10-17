<?php
/**
 * booking/lib/stats.php — the archer's statistics.
 *
 * Every shot of the archer's licence on THIS server, sorted by discipline and by combination —
 * bow, distance, target face or peg — since scores shot at different distances or on different
 * faces do not compare; and the national distinctions they earn (lib/distinctions.php).
 *
 * Read only. Left out: competitions with "No publication" (level 1, private to the organiser)
 * and those not started yet. A "shot" is one round: a 25 m + 18 m competition gives two.
 */
require_once __DIR__ . '/distinctions.php';
require_once __DIR__ . '/competition.php';

/** Disciplines of the statistics, in display order. */
function bk_stats_disc_keys()
{
    return array('indoor', 'outdoor_int', 'outdoor_nat', 'field', '3d', 'nature', 'beursault', 'run', 'other');
}

/** Calendar discipline of a statistics discipline, for its picture (bk_disc_icon). */
function bk_stats_icon_key($disc)
{
    $m = array('indoor' => 'salle', 'outdoor_int' => 'ext', 'outdoor_nat' => 'ext', 'field' => 'campagne',
               '3d' => '3d', 'nature' => 'nature', 'beursault' => 'beursault', 'run' => 'run');
    return $m[$disc] ?? 'ext';
}

/**
 * Age class and sex of a class code of the federation's set: S1H → ['age' => 'S1', 'sex' => 'M',
 * 'nat' => false]. M / W are the national-distance classes (S1M, U18W), H / F the international
 * ones. Null for anything else (discovery, arrow and feather tests, officials).
 */
function bk_stats_class($cls)
{
    if (!preg_match('/^(U11|U13|U15|U18|U21|S1|S2|S3)([HFMW])$/', (string) $cls, $m)) return null;
    return array('age' => $m[1], 'sex' => in_array($m[2], array('H', 'M'), true) ? 'M' : 'F',
                 'nat' => in_array($m[2], array('M', 'W'), true));
}

/** Federation season of a date (it starts on 1 September and bears the next year's number). */
function bk_stats_season($date)
{
    if (!preg_match('/^(\d{4})-(\d{2})/', (string) $date, $m)) return 0;
    return intval($m[2]) >= 9 ? intval($m[1]) + 1 : intval($m[1]);
}

/**
 * Discipline of one round. $max = the round's maximum score: the badges count full rounds only
 * (60 arrows indoors, 72 outdoors), anything else (a mixed-team trophy over 36 arrows…) is "other".
 */
function bk_stats_disc($r, $cls, $max)
{
    $type = intval($r->ToType);
    if (!$cls) return 'other';
    if (in_array($type, array(6, 7, 8), true)) return $max === 600 ? 'indoor' : 'other';
    if (in_array($type, array(1, 2, 3, 4, 5), true)) {
        if ($type !== 3 || $max !== 720) return 'other';
        return $cls['nat'] ? 'outdoor_nat' : 'outdoor_int';
    }
    $map = array('campagne' => 'field', '3d' => '3d', 'nature' => 'nature', 'beursault' => 'beursault', 'run' => 'run');
    $d = bk_comp_discipline($type, (string) $r->ToTypeSubRule, (string) $r->ToTypeName);
    return $map[$d['key']] ?? 'other';
}

/** Ends and arrows of each distance, per competition and departure: [tour][session][distance]. */
function bk_stats_distinfo(array $tourIds)
{
    $out = array();
    $ids = array_filter(array_map('intval', $tourIds));
    if (!$ids) return $out;
    $rs = safe_r_sql("SELECT DiTournament, DiSession, DiDistance, DiEnds, DiArrows FROM DistanceInformation
        WHERE DiType = 'Q' AND DiTournament IN (" . implode(',', $ids) . ")");
    while ($d = safe_fetch($rs)) {
        $out[intval($d->DiTournament)][intval($d->DiSession)][intval($d->DiDistance)] = array(intval($d->DiEnds), intval($d->DiArrows));
    }
    return $out;
}

/**
 * Most points one arrow can score, by ianseo type: estimates the arrows of a finished round when
 * the competition has no ends × arrows (DistanceInformation). Field 6, 3D 11, Beursault 4,
 * nature 17.5 (two arrows per target, 20 then 15 at best), target archery 10.
 */
function bk_stats_arrow_max($type)
{
    $m = array(9 => 6, 11 => 11, 50 => 4, 51 => 17.5);
    return $m[intval($type)] ?? 10;
}

/**
 * Entries of a licence, with what a round needs, oldest first. $upcoming = false: competitions
 * already started and published (not level 1, private to the organiser); true: those not over,
 * whatever their publication — the archer is registered, they know them.
 */
function bk_stats_entries($licence, $upcoming = false)
{
    $sel = array();
    for ($d = 1; $d <= 8; $d++) $sel[] = "QuD{$d}Score, QuD{$d}Hits, Td{$d}, TdDist{$d}, TfW{$d}, TfT{$d}";
    // Distances by category: the same LIKE match as the core (Common/Lib/Fun_Scheduler.php).
    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere, ToWhenFrom, ToWhenTo, ToType, ToTypeName, ToTypeSubRule,
                ToNumDist, ToMaxDistScore, EnId, EnDivision, EnClass, DivDescription, ClDescription,
                QuSession, QuScore, QuClRank, QuIrmType, QuArrow, QuD1Arrowstring, TdClasses, TfName, " . implode(', ', $sel) . "
        FROM Entries
        INNER JOIN Tournament ON ToId = EnTournament
        /* Qualifications is 1:1 with Entries (the core repairs the relation at the top of
           Partecipants/index.php) → INNER JOIN, never LEFT + IS NULL. */
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Divisions ON DivTournament = ToId AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = ToId AND ClId = EnClass
        LEFT JOIN BookingCompetitions ON BcTournament = ToId
        LEFT JOIN TournamentDistances ON TdTournament = ToId AND TdType = ToType
             AND CONCAT(TRIM(EnDivision), TRIM(EnClass)) LIKE TdClasses
        LEFT JOIN TargetFaces ON TfTournament = ToId AND TfId = EnTargetFace
        WHERE EnCode = " . StrSafe_DB($licence) . " AND EnAthlete = 1"
          . ($upcoming ? '' : " AND COALESCE(BcPublishLevel, 2) <> 1") . "
        ORDER BY ToWhenFrom, ToId, EnId");

    $today = bk_today();
    $rows = array();
    while ($r = safe_fetch($rs)) {
        // Server-zone date, compared as strings.
        $from = substr((string) $r->ToWhenFrom, 0, 10); $to = substr((string) $r->ToWhenTo, 0, 10);   // bytes: ASCII dates
        if ($upcoming ? $to < $today : $from > $today) continue;
        // Two distance patterns may match one category: keep the most specific.
        $spec = strlen(str_replace(array('%', '_'), '', (string) $r->TdClasses));   // bytes: ASCII pattern
        $id = intval($r->EnId);
        if (!isset($rows[$id]) || $spec > $rows[$id][0]) $rows[$id] = array($spec, $r);
    }
    return array_map(function ($x) { return $x[1]; }, array_values($rows));
}

/**
 * Rounds of one entry (bk_stats_entries): one, two for a 25 m + 18 m competition (distances 1-2
 * at 25 m, 3-4 at 18 m: two separate indoor rounds). $info = bk_stats_distinfo(). Rounds not
 * shot (score 0) are left out, unless $all (a registration to come).
 */
function bk_stats_entry_rounds($r, array $info, $all = false)
{
    $out = array();
    $nd = max(1, min(8, intval($r->ToNumDist)));
    $groups = intval($r->ToType) === 8 && $nd >= 4 ? array(array(1, 2), array(3, 4)) : array(range(1, $nd));
    $cls = bk_stats_class($r->EnClass);
    $perArrow = bk_stats_arrow_max($r->ToType);
    foreach ($groups as $g) {
        $score = 0; $complete = true; $best1 = 0; $planned = 0; $hits = 0; $walkFull = 0;
        foreach ($g as $d) {
            $s = intval($r->{'QuD' . $d . 'Score'});
            $score += $s;
            $best1 = max($best1, $s);
            // Complete = every distance shot in full. Under a tenth of its maximum, a distance is
            // an unfinished entry or a test (a real 36-arrow distance scores more than 36).
            if ($s <= 0 || $s < intval($r->ToMaxDistScore) / 10) $complete = false;
            $di = $info[intval($r->ToId)][intval($r->QuSession)][$d] ?? null;
            // Arrows of the distance: as planned by the organiser, or estimated from its maximum.
            $arr = $di ? $di[0] * $di[1] : intval(round(intval($r->ToMaxDistScore) / $perArrow));
            $planned += $arr;
            // Arrows counted (scored arrow by arrow) but fewer than planned: the round is not over.
            $h = intval($r->{'QuD' . $d . 'Hits'});
            $hits += $h;
            if ($h > 0 && $h < $arr) $complete = false;
            $m = intval($r->{'TdDist' . $d});
            if ($m <= 0 && preg_match('/^(\d+)\s*m/i', (string) $r->{'Td' . $d}, $mm)) $m = intval($mm[1]);
            // Walked to the target and back after each end (3 arrows indoors, 6 outdoors when unknown).
            $ends = $di ? $di[0] : intval(ceil($arr / (in_array(intval($r->ToType), array(6, 7, 8), true) ? 3 : 6)));
            $walkFull += $ends * 2 * $m;
        }
        // Scores keyed in as a total only: nothing per distance, the total is the round.
        if ($score === 0 && count($groups) === 1 && intval($r->QuScore) > 0) {
            $score = intval($r->QuScore); $best1 = 0;
            $complete = $score >= count($g) * intval($r->ToMaxDistScore) / 10;
        }
        if ($score <= 0 && !$all) continue;   // did not shoot
        if (intval($r->QuIrmType) > 0 || $score <= 0) $complete = false;

        $first = $g[0];
        $max = count($g) * intval($r->ToMaxDistScore);
        $disc = bk_stats_disc($r, $cls, $max);
        $dist = intval($r->{'TdDist' . $first});
        if ($dist <= 0 && preg_match('/^(\d+)\s*m/i', (string) $r->{'Td' . $first}, $mm)) $dist = intval($mm[1]);
        if (in_array($disc, array('field', '3d', 'nature'), true)) { $dist = 0; $walkFull = 0; }   // pegs, not one distance
        $faceW = intval($r->{'TfW' . $first});
        $peg = in_array(intval($r->{'TfT' . $first}), array(6, 8, 33), true) || stripos((string) $r->TfName, 'piquet') === 0;
        if ($peg) {
            // bytes: a hash of the face name, used as an ASCII key
            $faceKey = 'p' . substr(md5(mb_strtolower(trim((string) $r->TfName), 'UTF-8')), 0, 6);
            $faceLabel = trim((string) $r->TfName);
        } elseif ($disc === 'beursault' || $faceW <= 0) {
            $faceKey = ''; $faceLabel = '';
        } else {
            $faceKey = (string) $faceW; $faceLabel = $faceW . ' cm';
        }
        $honours = null;
        if ($disc === 'beursault') {
            // Honours = arrows in the scoring zones. The core's Beursault entry
            // (Modules/Sets/FR/Modules/Bslt) keeps their count in QuArrow; scored arrow by arrow,
            // a miss is "A" in the arrow string and a hit any other letter (Targets 27: B-E = 1-4).
            if (intval($r->QuArrow) > 0) {
                $honours = intval($r->QuArrow);
            } else {
                $as = rtrim((string) $r->QuD1Arrowstring);
                if ($as !== '') $honours = strlen(str_replace(array('A', ' '), '', $as));   // bytes: ASCII arrow string
            }
        }
        // Arrows shot: all those planned once the round is finished, those counted otherwise.
        $arrows = $complete ? $planned : min($hits, $planned);
        $bow = (string) $r->EnDivision;
        $out[] = array(
            'tour' => intval($r->ToId), 'code' => (string) $r->ToCode, 'name' => (string) $r->ToName,
            'where' => (string) $r->ToWhere, 'date' => substr((string) $r->ToWhenFrom, 0, 10),   // bytes: ASCII date
            'dateTo' => substr((string) $r->ToWhenTo, 0, 10),   // bytes: ASCII date
            'disc' => $disc, 'bow' => $bow, 'bowName' => (string) ($r->DivDescription ?: $bow),
            'cls' => (string) $r->EnClass, 'clsName' => (string) ($r->ClDescription ?: $r->EnClass),
            'age' => $cls['age'] ?? '', 'sex' => $cls['sex'] ?? '',
            'dist' => $dist, 'distText' => $dist > 0 ? $dist . 'm' : '',
            'faceW' => $faceW, 'faceKey' => $faceKey, 'faceLabel' => $faceLabel,
            'combo' => $disc . '|' . $bow . '|' . $dist . '|' . $faceKey,
            'score' => $score, 'max' => $max, 'best1' => count($g) > 1 ? $best1 : 0,
            'complete' => $complete, 'rank' => count($groups) === 1 ? intval($r->QuClRank) : 0,
            'arrows' => $arrows, 'walk' => $planned > 0 ? intval(round($walkFull * $arrows / $planned)) : 0,
            'honours' => $honours,
        );
    }
    return $out;
}

/** Every round of a licence, oldest first (competitions started and published). */
function bk_stats_rounds($licence)
{
    static $cache = array();
    if (isset($cache[$licence])) return $cache[$licence];
    $rows = bk_stats_entries($licence);
    $info = bk_stats_distinfo(array_map(function ($r) { return $r->ToId; }, $rows));
    $out = array();
    foreach ($rows as $r) $out = array_merge($out, bk_stats_entry_rounds($r, $info));
    return $cache[$licence] = $out;
}

/**
 * Distinction context of the archer's next competition (not over, the one under way included):
 * ['ctx', 'round'] from the first of its entries that can earn one, or null — no registration,
 * or a discipline, bow or class without national distinction.
 */
function bk_stats_upcoming_ctx($licence)
{
    $rows = bk_stats_entries($licence, true);
    if (!$rows) return null;
    $tour = intval($rows[0]->ToId);
    $rows = array_values(array_filter($rows, function ($r) use ($tour) { return intval($r->ToId) === $tour; }));
    $info = bk_stats_distinfo(array($tour));
    foreach ($rows as $r) {
        foreach (bk_stats_entry_rounds($r, $info, true) as $x) {
            if ($ctx = bk_stats_round_ctx($x)) return array('ctx' => $ctx, 'round' => $x);
        }
    }
    return null;
}

/**
 * Rounds grouped by discipline then combination, most recent activity first:
 * [disc => [combo => ['key', 'disc', 'bow', 'bowName', 'dist', 'faceLabel', 'rounds' => [...]]]].
 */
function bk_stats_combos(array $rounds)
{
    $out = array();
    foreach ($rounds as $r) {
        $c = &$out[$r['disc']][$r['combo']];
        if (!$c) $c = array('key' => $r['combo'], 'disc' => $r['disc'], 'bow' => $r['bow'], 'rounds' => array());
        // The latest names win (a division renamed, a face relabelled).
        $c['bowName'] = $r['bowName']; $c['dist'] = $r['dist']; $c['faceLabel'] = $r['faceLabel'];
        $c['rounds'][] = $r;
        unset($c);
    }
    $last = function ($c) { return end($c['rounds'])['date']; };
    foreach ($out as $d => $combos) {
        uasort($combos, function ($a, $b) use ($last) { return strcmp($last($b), $last($a)); });
        $out[$d] = $combos;
    }
    uksort($out, function ($a, $b) use ($out, $last) {
        $la = max(array_map($last, $out[$a])); $lb = max(array_map($last, $out[$b]));
        return strcmp($lb, $la) ?: array_search($a, bk_stats_disc_keys()) - array_search($b, bk_stats_disc_keys());
    });
    return $out;
}

/** Best complete round of a list (the earliest on a tie: the record was set then), or null. */
function bk_stats_best(array $rounds)
{
    $best = null;
    foreach ($rounds as $r) if ($r['complete'] && (!$best || $r['score'] > $best['score'])) $best = $r;
    return $best;
}

/** Key of a distinction context. */
function bk_stats_ctx_key(array $ctx)
{
    return $ctx['disc'] . '|' . $ctx['set'] . '|' . $ctx['col'] . '|' . $ctx['text'];
}

/** Distinction context of a round (complete or not), or null. */
function bk_stats_round_ctx(array $r)
{
    return $r['age'] !== '' ? bk_dist_context($r['disc'], $r['bow'], $r['age'], $r['distText']) : null;
}

/**
 * Distinctions earned, per context, from complete rounds in date order. A level is earned by the
 * first round reaching its threshold (on the face it requires); reaching a level directly earns
 * the ones below it too. Returns [ctxKey => ['ctx', 'measure', 'ladder', 'earned' => [level =>
 * round], 'next' => level or null, 'bestFor' => [level => best value meeting its face]]].
 */
function bk_stats_badges(array $rounds)
{
    $out = array();
    foreach ($rounds as $r) {
        if (!$r['complete'] || !($ctx = bk_stats_round_ctx($r))) continue;
        $k = bk_stats_ctx_key($ctx);
        if (!isset($out[$k])) {
            $out[$k] = array('ctx' => $ctx, 'measure' => bk_dist_measure($ctx), 'ladder' => bk_dist_ladder($ctx),
                             'earned' => array(), 'bestFor' => array());
        }
        $v = $out[$k]['measure'] === 'honours' ? $r['honours'] : $r['score'];
        if ($v === null) continue;
        foreach ($out[$k]['ladder'] as $lv) {
            if ($lv['face'] && $r['faceW'] !== $lv['face']) continue;
            $out[$k]['bestFor'][$lv['id']] = max($out[$k]['bestFor'][$lv['id']] ?? 0, $v);
            if (!isset($out[$k]['earned'][$lv['id']]) && $v >= $lv['score']) $out[$k]['earned'][$lv['id']] = $r;
        }
    }
    foreach ($out as $k => $b) {
        $out[$k]['next'] = null;
        foreach ($b['ladder'] as $lv) {
            if (!isset($b['earned'][$lv['id']])) { $out[$k]['next'] = $lv; break; }
        }
    }
    return $out;
}

/** Highest level earned in a context entry of bk_stats_badges(), or null. */
function bk_stats_top(array $b)
{
    $top = null;
    foreach ($b['ladder'] as $lv) if (isset($b['earned'][$lv['id']])) $top = $lv;
    return $top;
}

/** Entry of bk_stats_badges() for a context; one with nothing earned when none was reached yet. */
function bk_stats_ctx_badges(array $ctx, array $badges)
{
    return $badges[bk_stats_ctx_key($ctx)] ?? array('ctx' => $ctx, 'measure' => bk_dist_measure($ctx),
        'ladder' => bk_dist_ladder($ctx), 'earned' => array(), 'bestFor' => array(),
        'next' => bk_dist_ladder($ctx)[0] ?? null);
}

/** Context entry of a combination: that of its latest round, or null. */
function bk_stats_combo_badges(array $combo, array $badges)
{
    $ctx = bk_stats_round_ctx(end($combo['rounds']));
    return $ctx ? bk_stats_ctx_badges($ctx, $badges) : null;
}

/**
 * The next distinction of a context entry: its picture, name, score to reach, the bar of the best
 * score so far, how much is missing — or that every one is earned. $base = address of
 * booking/public/, $h = height of the picture.
 */
function bk_stats_next_html(array $b, $base, $h = 80)
{
    if (!$b['next']) return '<p class="bk-sx-msg">' . bk_e(bk_t('StatAllEarned')) . '</p>';
    $nx = $b['next'];
    $honours = $b['measure'] === 'honours';
    $have = intval($b['bestFor'][$nx['id']] ?? 0);
    $pct = $nx['score'] > 0 ? min(100, $have / $nx['score'] * 100) : 0;
    $name = bk_t('DistLvl' . $nx['id']);
    $seps = bk_number_seps();
    return '<div class="bk-sx-next"><span class="bk-sx-next-pic">' . bk_dist_visual($b['ctx'], $nx['id'], $name, $base, $h) . '</span>'
        . '<div class="bk-sx-next-t"><b>' . bk_e($name) . '</b><span class="o">' . bk_e(bk_t($honours ? 'DistHonours' : 'DistPts', $nx['score']))
        . ($nx['face'] ? ' · ' . bk_e(bk_t('DistFace', $nx['face'])) : '') . '</span>'
        . '<div class="bk-sx-bar" style="--c:' . bk_e(bk_dist_level_color($nx['id'])) . '"><span style="width:' . number_format($pct, 1, '.', '') . '%"></span></div>'
        . '<div class="bk-sx-pct">' . bk_e($have > 0
            ? bk_t('StatProgress', array('have' => $have, 'pct' => number_format($pct, 1, $seps['dec'], $seps['thousands']), 'left' => $nx['score'] - $have))
            : bk_t('StatProgressNone')) . '</div></div></div>';
}

/** Arrows shot and metres walked to fetch them, over a list of rounds. */
function bk_stats_totals(array $rounds)
{
    $t = array('arrows' => 0, 'walk' => 0, 'tours' => array());
    foreach ($rounds as $r) { $t['arrows'] += $r['arrows']; $t['walk'] += $r['walk']; $t['tours'][$r['tour']] = true; }
    $t['tours'] = count($t['tours']);
    return $t;
}

/** "70 m · 122 cm", "Piquet rouge", "18 m · 40 cm": what tells a combination apart. */
function bk_stats_combo_where(array $c)
{
    $p = array();
    if ($c['dist'] > 0) $p[] = $c['dist'] . ' m';
    if ($c['faceLabel'] !== '') $p[] = $c['faceLabel'];
    return implode(' · ', $p);
}

/** A round step giving about $n intervals over $span. */
function bk_stats_step($span, $n = 5)
{
    foreach (array(1, 2, 5, 10, 20, 25, 50, 100, 200, 500) as $s) if ($span / $s <= $n) return $s;
    return 1000;
}

/**
 * Chart of a combination: one point per complete round (date → value), the distinction
 * thresholds as dashed lines in the colour of the badge, the record labelled. Drawn here in SVG;
 * assets/stats.js adds the tooltips (pointer, touch, keyboard). Every value is also in the
 * history table under it.
 *   $rounds  complete rounds of the combination, oldest first
 *   $b       its distinction context (bk_stats_combo_badges), or null
 */
function bk_stats_chart(array $rounds, $b)
{
    // Two drawings, one for a wide screen and one for a phone (CSS shows one): a single SVG
    // scaled down to a phone would shrink its texts to 5 px.
    $wide = bk_stats_chart_svg($rounds, $b, 760, 300);
    return $wide === '' ? '' : '<div class="bk-chart-w">' . $wide . '</div><div class="bk-chart-n">' . bk_stats_chart_svg($rounds, $b, 380, 300) . '</div>';
}

/** One drawing of bk_stats_chart(), $W × $H in SVG units (≈ pixels on the screen it suits). */
function bk_stats_chart_svg(array $rounds, $b, $W, $H)
{
    $honours = $b && $b['measure'] === 'honours';
    $pts = array();
    foreach ($rounds as $r) {
        $v = $honours ? $r['honours'] : $r['score'];
        if ($v !== null) $pts[] = array('t' => strtotime($r['date'] . ' 12:00:00 UTC'), 'v' => $v, 'r' => $r);
    }
    if (!$pts) return '';
    $L = 40; $R = $W > 500 ? 70 : 44; $T = 18; $B = 30;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $f = function ($n) { return number_format($n, 1, '.', ''); };

    // Thresholds kept in view: the highest earned and the next one to reach.
    $lines = array();
    $vals = array_column($pts, 'v');
    $lo = min($vals); $hi = max($vals);
    if ($b) {
        $top = bk_stats_top($b);
        foreach ($b['ladder'] as $lv) {
            $state = isset($b['earned'][$lv['id']]) ? 'done' : (($b['next']['id'] ?? '') === $lv['id'] ? 'next' : 'todo');
            // Label priority: the next level, then the highest earned, then the others.
            $key = $state === 'next' ? 2 : (($top && $top['id'] === $lv['id']) ? 1 : 0);
            $lines[] = array('lv' => $lv, 'state' => $state, 'key' => $key);
            if ($key) { $lo = min($lo, $lv['score']); $hi = max($hi, $lv['score']); }
        }
    }
    $span = max(10, $hi - $lo);
    $lo = max(0, $lo - $span * 0.08); $hi += $span * 0.1;
    $step = bk_stats_step($hi - $lo);
    $lo = floor($lo / $step) * $step; $hi = ceil($hi / $step) * $step;
    $y = function ($v) use ($lo, $hi, $T, $ph) { return $T + $ph - ($v - $lo) / ($hi - $lo) * $ph; };

    $t0 = min(array_column($pts, 't')); $t1 = max(array_column($pts, 't'));
    $pad = max(20 * 86400, ($t1 - $t0) * 0.04);
    $t0 -= $pad; $t1 += $pad;
    $x = function ($t) use ($t0, $t1, $L, $pw) { return $L + ($t - $t0) / ($t1 - $t0) * $pw; };

    $svg = '';
    for ($v = $lo; $v <= $hi + 0.001; $v += $step) {
        $svg .= '<line class="g" x1="' . $L . '" x2="' . ($L + $pw) . '" y1="' . $f($y($v)) . '" y2="' . $f($y($v)) . '"/>'
            . '<text class="ax" x="' . ($L - 7) . '" y="' . $f($y($v) + 4) . '" text-anchor="end">' . intval($v) . '</text>';
    }

    // Dates: a tick every 1, 2, 3, 6, 12 or 24 months, as many as the width holds (one per 64 units).
    $months = ($t1 - $t0) / (30.44 * 86400);
    $room = max(2, floor($pw / 64));
    $every = 24;
    foreach (array(1, 2, 3, 6, 12, 24) as $e) if ($months / $e <= $room) { $every = $e; break; }
    $m = intval(gmdate('n', $t0)); $yr = intval(gmdate('Y', $t0));
    for ($i = 0; $i < 400; $i++) {
        $m++; if ($m > 12) { $m = 1; $yr++; }
        $tt = gmmktime(0, 0, 0, $m, 1, $yr);
        if ($tt > $t1) break;
        if ($every >= 12 ? ($m !== 1 || ($every === 24 && $yr % 2)) : ($m - 1) % $every !== 0) continue;
        $svg .= '<line class="tk" x1="' . $f($x($tt)) . '" x2="' . $f($x($tt)) . '" y1="' . ($T + $ph) . '" y2="' . ($T + $ph + 4) . '"/>'
            . '<text class="ax" x="' . $f($x($tt)) . '" y="' . ($T + $ph + 18) . '" text-anchor="middle">'
            . ($every >= 12 ? $yr : sprintf('%02d/%02d', $m, $yr % 100)) . '</text>';
    }
    $svg .= '<line class="base" x1="' . $L . '" x2="' . ($L + $pw) . '" y1="' . ($T + $ph) . '" y2="' . ($T + $ph) . '"/>';

    // Threshold lines; labels in the right margin, the key ones placed first, never overlapping.
    $placed = array();
    usort($lines, function ($p, $q) { return intval($q['key']) - intval($p['key']); });
    foreach ($lines as $ln) {
        $s = $ln['lv']['score'];
        if ($s < $lo || $s > $hi) continue;
        $yy = $y($s);
        $col = bk_e(bk_dist_level_color($ln['lv']['id']));
        $name = bk_t('DistLvl' . $ln['lv']['id']);
        $svg .= '<g class="th th-' . $ln['state'] . '"><title>' . bk_e($name . ' — ' . $s) . '</title>'
            . '<line x1="' . $L . '" x2="' . ($L + $pw) . '" y1="' . $f($yy) . '" y2="' . $f($yy) . '" stroke="' . $col . '"/>';
        $free = true;
        foreach ($placed as $py) if (abs($py - $yy) < 13) $free = false;
        if ($free) {
            $placed[] = $yy;
            $svg .= '<rect x="' . ($L + $pw + 6) . '" y="' . $f($yy - 4.5) . '" width="9" height="9" rx="2" fill="' . $col . '"/>'
                . '<text class="tl" x="' . ($L + $pw + 19) . '" y="' . $f($yy + 4) . '">' . $s . '</text>';
        }
        $svg .= '</g>';
    }

    // The line, then the points (a 2 px ring in the surface colour), the record larger.
    $best = null;
    foreach ($pts as $i => $p) if (!$best || $p['v'] > $pts[$best - 1]['v']) $best = $i + 1;
    $path = ''; $dots = ''; $hits = ''; $data = array();
    foreach ($pts as $i => $p) {
        $px = $x($p['t']); $py = $y($p['v']);
        $path .= ($i ? ' L' : 'M') . $f($px) . ',' . $f($py);
        $rec = $i + 1 === $best;
        $dots .= '<circle class="pt' . ($rec ? ' rec' : '') . '" data-i="' . $i . '" cx="' . $f($px) . '" cy="' . $f($py) . '" r="' . ($rec ? 6.5 : 4.5) . '"/>';
        $hits .= '<circle class="hit" tabindex="0" data-i="' . $i . '" cx="' . $f($px) . '" cy="' . $f($py) . '" r="13"'
            . ' aria-label="' . bk_e($p['v'] . ' — ' . $p['r']['name'] . ', ' . bk_date_fr($p['r']['date'])) . '"/>';
        $r = $p['r'];
        $data[] = array('x' => round($px, 1), 'y' => round($py, 1), 'v' => $p['v'], 'n' => $r['name'], 'w' => $r['where'],
                        'd' => bk_date_range($r['date'], $r['dateTo']),
                        'k' => $r['rank'] > 0 ? strip_tags(bk_t('StatRanked', $r['rank'])) : '');
    }
    $bp = $pts[$best - 1];
    $label = bk_t('StatRecordShort', $bp['v']);
    $lx = min(max($x($bp['t']), $L + 30), $L + $pw - 30);
    $svg .= '<path class="ln" d="' . $path . '"/>' . $dots
        . '<text class="rl" x="' . $f($lx) . '" y="' . $f(max($T + 2, $y($bp['v']) - 12)) . '" text-anchor="middle">' . bk_e($label) . '</text>'
        . '<line class="cross" x1="0" x2="0" y1="' . $T . '" y2="' . ($T + $ph) . '"/>' . $hits;

    return '<figure class="bk-chart" data-pts="' . bk_e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">'
        . '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . bk_e(bk_t('StatChartAria', count($pts))) . '">' . $svg . '</svg>'
        . '<div class="bk-chart-tip" hidden></div></figure>';
}

/** Ring gauge of a score out of its maximum (the record). */
function bk_stats_gauge($score, $max)
{
    $r = 58; $c = 2 * M_PI * $r;
    $frac = $max > 0 ? max(0, min(1, $score / $max)) : 0;
    return '<svg class="bk-ring" viewBox="0 0 150 150" role="img" aria-label="' . bk_e($score . ' / ' . $max) . '">'
        . '<circle cx="75" cy="75" r="' . $r . '" class="gt"/>'
        . '<circle cx="75" cy="75" r="' . $r . '" class="gv" stroke-dasharray="' . number_format($frac * $c, 1, '.', '') . ' '
        . number_format($c, 1, '.', '') . '" transform="rotate(-90 75 75)"/>'
        . '<text x="75" y="80" text-anchor="middle" class="gs">' . intval($score) . '</text>'
        . '<text x="75" y="104" text-anchor="middle" class="gm">/ ' . intval($max) . '</text></svg>';
}
