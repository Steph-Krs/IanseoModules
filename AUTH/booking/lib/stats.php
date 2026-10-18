<?php
/**
 * booking/lib/stats.php — the archer's statistics.
 *
 * Every shot of the archer's licence on THIS server, and those of the federation record they
 * imported (lib/palmares.php), sorted by discipline and by combination — bow, distance, target
 * face or peg — since scores shot at different distances or on different faces do not compare;
 * and the national distinctions they earn (lib/distinctions.php).
 *
 * Read only. Left out: competitions with "No publication" (level 1, private to the organiser)
 * and those not started yet. A "shot" is one round: a 25 m + 18 m competition gives two.
 */
require_once __DIR__ . '/distinctions.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/palmares.php';

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
            // A triple face (TrgIndSmall / TrgCOIndSmall): what the 18 m stars accept besides the 40 cm face.
            'trispot' => in_array(intval($r->{'TfT' . $first}), array(2, 4), true),
            'combo' => $disc . '|' . $bow . '|' . $dist . '|' . $faceKey,
            'score' => $score, 'max' => $max, 'best1' => count($g) > 1 ? $best1 : 0,
            'complete' => $complete, 'rank' => count($groups) === 1 ? intval($r->QuClRank) : 0,
            'arrows' => $arrows, 'walk' => $planned > 0 ? intval(round($walkFull * $arrows / $planned)) : 0,
            'honours' => $honours,
            // Shot on this server (lib/palmares.php gives 'pal' rounds the same keys).
            'src' => 'local', 'conf' => 'exact', 'official' => true, 'badge' => true,
        );
    }
    return $out;
}

/** Every round of a licence shot on this server, oldest first (competitions started and published). */
function bk_stats_local_rounds($licence)
{
    $rows = bk_stats_entries($licence);
    $info = bk_stats_distinfo(array_map(function ($r) { return $r->ToId; }, $rows));
    $out = array();
    foreach ($rows as $r) $out = array_merge($out, bk_stats_entry_rounds($r, $info));
    return $out;
}

/**
 * Every round of a licence, oldest first: those shot on this server, and those of the
 * federation record the archer imported (lib/palmares.php) — but for a day and a score already
 * here: a competition of this server is never counted twice, and its own figures (distance,
 * face, arrows) are the precise ones.
 */
function bk_stats_rounds($licence)
{
    static $cache = array();
    if (isset($cache[$licence])) return $cache[$licence];
    $out = bk_stats_local_rounds($licence);
    $known = bk_pal_local_keys($out);
    $pal = array_values(array_filter(bk_pal_rounds($licence), function ($r) use ($known) {
        return !isset($known[$r['date'] . '|' . $r['score']]);
    }));
    if ($pal) {
        $out = array_merge($out, $pal);
        // Stable: rounds of one day keep their order.
        $i = 0;
        foreach ($out as &$r) $r['_i'] = $i++;
        unset($r);
        usort($out, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: $a['_i'] - $b['_i']; });
        foreach ($out as &$r) unset($r['_i']);
        unset($r);
    }
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
 * first round reaching its threshold where the level is open (age class, target face:
 * bk_dist_level_open); reaching a level directly earns the ones below it too. Returns
 * [ctxKey => ['ctx', 'measure', 'ladder', 'earned' => [level => round], 'bestFor' => [level =>
 * best value where the level is open], 'age' => age class of the latest round, 'next']].
 */
function bk_stats_badges(array $rounds)
{
    $out = array();
    foreach ($rounds as $r) {
        // An imported round counts only when official and placed for sure (lib/palmares.php).
        if (!$r['complete'] || empty($r['badge']) || !($ctx = bk_stats_round_ctx($r))) continue;
        $k = bk_stats_ctx_key($ctx);
        if (!isset($out[$k])) {
            $out[$k] = array('ctx' => $ctx, 'measure' => bk_dist_measure($ctx), 'ladder' => bk_dist_ladder($ctx),
                             'earned' => array(), 'bestFor' => array());
        }
        $out[$k]['age'] = $r['age'];
        $v = $out[$k]['measure'] === 'honours' ? $r['honours'] : $r['score'];
        if ($v === null) continue;
        foreach ($out[$k]['ladder'] as $lv) {
            if (!bk_dist_level_open($lv, $r['age'], $r['faceW'], !empty($r['trispot']))) continue;
            $out[$k]['bestFor'][$lv['id']] = max($out[$k]['bestFor'][$lv['id']] ?? 0, $v);
            if (!isset($out[$k]['earned'][$lv['id']]) && $v >= $lv['score']) $out[$k]['earned'][$lv['id']] = $r;
        }
    }
    foreach ($out as $k => $b) $out[$k]['next'] = bk_stats_next_of($b, $b['age']);
    return $out;
}

/**
 * Next level to reach in a context entry for an archer of this age class: the first not earned
 * and open to the class, or null (all earned, or the next ones closed to the class).
 */
function bk_stats_next_of(array $b, $age)
{
    foreach ($b['ladder'] as $lv) {
        if (!isset($b['earned'][$lv['id']]) && bk_dist_level_for_age($lv, $age)) return $lv;
    }
    return null;
}

/** First level of a context entry not earned and closed to this age class, or null. */
function bk_stats_closed_of(array $b, $age)
{
    foreach ($b['ladder'] as $lv) {
        if (!isset($b['earned'][$lv['id']]) && !bk_dist_level_for_age($lv, $age)) return $lv;
    }
    return null;
}

/** Highest level earned in a context entry of bk_stats_badges(), or null. */
function bk_stats_top(array $b)
{
    $top = null;
    foreach ($b['ladder'] as $lv) if (isset($b['earned'][$lv['id']])) $top = $lv;
    return $top;
}

/**
 * Entry of bk_stats_badges() for a context, one with nothing earned when none was reached yet;
 * $age = the archer's age class now (the next competition's), when it may differ from that of
 * the latest round (a new season).
 */
function bk_stats_ctx_badges(array $ctx, array $badges, $age = null)
{
    $b = $badges[bk_stats_ctx_key($ctx)] ?? array('ctx' => $ctx, 'measure' => bk_dist_measure($ctx),
        'ladder' => bk_dist_ladder($ctx), 'earned' => array(), 'bestFor' => array(), 'age' => '');
    if ($age !== null) $b['age'] = $age;
    $b['next'] = bk_stats_next_of($b, $b['age']);
    return $b;
}

/** Context entry of a combination: that of its latest round, or null. */
function bk_stats_combo_badges(array $combo, array $badges)
{
    $last = end($combo['rounds']);
    $ctx = bk_stats_round_ctx($last);
    return $ctx ? bk_stats_ctx_badges($ctx, $badges, $last['age']) : null;
}

/**
 * The next distinction of a context entry: its picture, name, score to reach and what else it
 * requires, the bar of the best score so far, how much is missing — or that every one is earned,
 * or that the next ones open to other age classes only. $base = address of booking/public/,
 * $h = height of the picture.
 */
function bk_stats_next_html(array $b, $base, $h = 80)
{
    if (!$b['next']) {
        $closed = bk_stats_closed_of($b, $b['age']);
        return '<p class="bk-sx-msg">' . bk_e($closed
            ? bk_t('StatNextClosed', array('name' => bk_t('DistLvl' . $closed['id']), 'ages' => implode(', ', $closed['ages']), 'age' => $b['age']))
            : bk_t('StatAllEarned')) . '</p>';
    }
    $nx = $b['next'];
    $honours = $b['measure'] === 'honours';
    $have = intval($b['bestFor'][$nx['id']] ?? 0);
    $pct = $nx['score'] > 0 ? min(100, $have / $nx['score'] * 100) : 0;
    $name = bk_t('DistLvl' . $nx['id']);
    $seps = bk_number_seps();
    return '<div class="bk-sx-next"><span class="bk-sx-next-pic">' . bk_dist_visual($b['ctx'], $nx['id'], $name, $base, $h) . '</span>'
        . '<div class="bk-sx-next-t"><b>' . bk_e($name) . '</b><span class="o">' . bk_e(bk_t($honours ? 'DistHonours' : 'DistPts', $nx['score']))
        . ($nx['face'] ? ' · ' . bk_e(bk_t($nx['trispot'] ? 'DistFaceOrTri' : 'DistFace', $nx['face'])) : '') . '</span>'
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

/**
 * Chart of a combination: one point per complete round (date → value), the distinction
 * thresholds in the colour of the badge, the record labelled. The data only: assets/stats.js
 * draws it at the width of the screen and lets the archer zoom on a period (drag across it,
 * pinch, the buttons), the scale of the scores following what is shown. Every value is also in
 * the history table under it.
 *   $rounds  complete rounds of the combination, oldest first
 *   $b       its distinction context (bk_stats_combo_badges), or null
 */
function bk_stats_chart(array $rounds, $b)
{
    $honours = $b && $b['measure'] === 'honours';
    $pts = array(); $perDay = array();
    foreach ($rounds as $r) {
        $v = $honours ? $r['honours'] : $r['score'];
        if ($v === null) continue;
        $pts[] = array('r' => $r, 'v' => $v);
        $perDay[$r['date']] = ($perDay[$r['date']] ?? 0) + 1;
    }
    if (!$pts) return '';

    // Rounds of one day share it out (milliseconds since 1970): zooming in separates them.
    $data = array(); $seen = array(); $best = 0;
    foreach ($pts as $i => $p) {
        $r = $p['r'];
        $k = $seen[$r['date']] = ($seen[$r['date']] ?? -1) + 1;
        $t = (strtotime($r['date'] . ' 00:00:00 UTC') + ($k + 0.5) / $perDay[$r['date']] * 86400) * 1000;
        if ($p['v'] > $pts[$best]['v']) $best = $i;
        $pal = ($r['src'] ?? '') === 'pal';
        $data[] = array('t' => $t, 'v' => $p['v'], 'n' => $r['name'], 'w' => $r['where'],
                        'd' => bk_date_range($r['date'], $r['dateTo']),
                        'k' => $r['rank'] > 0 ? strip_tags(bk_t('StatRanked', $r['rank'])) : '',
                        'o' => $pal ? bk_t('StatFromPal') : '', 'p' => $pal ? 1 : 0);
    }
    $data[$best]['rec'] = 1;

    // Thresholds: those earned and those open to the archer's age class (an 18 m star is no
    // target for a U15). key = label priority: the next level, then the highest earned.
    $lines = array();
    if ($b) {
        $top = bk_stats_top($b);
        foreach ($b['ladder'] as $lv) {
            $done = isset($b['earned'][$lv['id']]);
            if (!$done && !bk_dist_level_for_age($lv, $b['age'])) continue;
            $next = ($b['next']['id'] ?? '') === $lv['id'];
            $lines[] = array('v' => $lv['score'], 'c' => bk_dist_level_color($lv['id']), 'n' => bk_t('DistLvl' . $lv['id']),
                             's' => $done ? 'done' : ($next ? 'next' : 'todo'),
                             'key' => $next ? 2 : (($top && $top['id'] === $lv['id']) ? 1 : 0));
        }
    }
    $cfg = array('pts' => $data, 'lines' => $lines, 'tx' => array(
        'rec' => bk_t('StatRecordShort', '{v}'), 'aria' => bk_t('StatChartAria', count($data)),
        'day' => bk_t('HomeDepDateFmt')));

    $btn = function ($act, $label, $title) {
        return '<button type="button" class="bk-chart-b" data-act="' . $act . '" title="' . bk_e($title) . '" aria-label="' . bk_e($title) . '" disabled>'
            . bk_e($label) . '</button>';
    };
    return '<figure class="bk-chart" data-chart="' . bk_e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '">'
        . '<div class="bk-chart-bar">' . $btn('prev', '‹', bk_t('StatZoomPrev')) . $btn('out', '−', bk_t('StatZoomOut'))
        . $btn('in', '+', bk_t('StatZoomIn')) . $btn('next', '›', bk_t('StatZoomNext'))
        . '<button type="button" class="bk-chart-b bk-chart-all" data-act="all" disabled>' . bk_e(bk_t('StatZoomAll')) . '</button>'
        . '<span class="bk-chart-period"></span></div>'
        . '<div class="bk-chart-plot"></div><div class="bk-chart-tip" hidden></div>'
        . '<figcaption class="bk-chart-hint">' . bk_e(bk_t('StatZoomHint')) . '</figcaption>'
        . '<noscript><p class="bk-sx-msg">' . bk_e(bk_t('StatChartNoJs')) . '</p></noscript></figure>';
}

/** Ring gauge of a score out of its maximum (the record); the score alone when the maximum is unknown. */
function bk_stats_gauge($score, $max)
{
    $r = 58; $c = 2 * M_PI * $r;
    $frac = $max > 0 ? max(0, min(1, $score / $max)) : 0;
    return '<svg class="bk-ring" viewBox="0 0 150 150" role="img" aria-label="' . bk_e($max > 0 ? $score . ' / ' . $max : $score) . '">'
        . '<circle cx="75" cy="75" r="' . $r . '" class="gt"/>'
        . '<circle cx="75" cy="75" r="' . $r . '" class="gv" stroke-dasharray="' . number_format($frac * $c, 1, '.', '') . ' '
        . number_format($c, 1, '.', '') . '" transform="rotate(-90 75 75)"/>'
        . '<text x="75" y="80" text-anchor="middle" class="gs">' . intval($score) . '</text>'
        . ($max > 0 ? '<text x="75" y="104" text-anchor="middle" class="gm">/ ' . intval($max) . '</text>' : '') . '</svg>';
}
