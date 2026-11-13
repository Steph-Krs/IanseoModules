<?php
/**
 * tests/placement.php — test bench of the target placement by face (lib/placement.php).
 *
 *   php Modules/Custom/AUTH/booking/tests/placement.php          (any PHP 7.4+, ianseo's included)
 *   php Modules/Custom/AUTH/booking/tests/placement.php -v       (every case, not only failures)
 *
 * The planner is pure (no database): the cases are built by hand and every RULE of
 * REGLES_PLACEMENT.md has its case here, so the output reads as the specification. Exit code 0
 * when every case passes, 1 otherwise.
 *
 * Besides the expected result of each case, EVERY plan is checked against the invariants that
 * must hold whatever the data (check_valid): each archer placed once or reported unplaced, no
 * place taken twice, archers already placed never moved, one distance per target, one family
 * per half of the buttress at every distance, a possible target, field settings respected.
 *
 * Command line only: nothing is run from the web.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '1');

// The planner and the face sharing rules never touch the database; the ianseo SQL functions
// only exist so that the libraries load.
if (!function_exists('safe_r_sql')) { function safe_r_sql($q, $u = false, $f = false) { return false; } }
if (!function_exists('safe_w_sql')) { function safe_w_sql($q) { return false; } }
if (!function_exists('safe_fetch')) { function safe_fetch($r) { return false; } }
if (!function_exists('StrSafe_DB')) { function StrSafe_DB($v) { return "'" . addslashes((string) $v) . "'"; } }

require_once __DIR__ . '/../lib/placement.php';

$VERBOSE = in_array('-v', $argv ?? array(), true);

/* ═══ Micro harness ════════════════════════════════════════════════════════════════════════════ */

final class CaseFailed extends Exception {}

$RESULTS = array('ok' => 0, 'ko' => 0, 'failures' => array());

function section($title)
{
    global $VERBOSE;
    if ($VERBOSE) echo "\n── $title\n";
}

/** One case: a rule and the code that proves it. */
function test_case($rule, callable $fn)
{
    global $RESULTS, $VERBOSE;
    try {
        $fn();
        $RESULTS['ok']++;
        if ($VERBOSE) echo "  OK  $rule\n";
    } catch (Throwable $e) {
        $RESULTS['ko']++;
        $RESULTS['failures'][] = array($rule, $e->getMessage());
        echo "  KO  $rule\n      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n";
    }
}

function show($v) { return json_encode($v); }

function same($expected, $actual, $what = '')
{
    if ($expected !== $actual) throw new CaseFailed(($what !== '' ? "$what: " : '') . 'expected ' . show($expected) . ', got ' . show($actual));
}

function truthy($cond, $what)
{
    if (!$cond) throw new CaseFailed($what);
}

/* ═══ Building a departure and reading a plan ══════════════════════════════════════════════════ */

final class Plan
{
    private $seq = 0;
    public $in;

    public function __construct($disc = 'salle', $targets = 10, $rhythm = 4, $maxPerClub = 4)
    {
        $this->in = array('disc' => $disc, 'rhythm' => $rhythm, 'targets' => range(1, $targets),
            'maxPerClub' => $maxPerClub, 'minTargets' => 0, 'caps' => array(), 'fixed' => array(), 'archers' => array());
    }

    /** $n archers to place; $fam: one family, or one per distance. Returns their ids. */
    public function add($n, $fam, $dist = array(18, 18), $o = array())
    {
        $ids = array();
        for ($i = 0; $i < $n; $i++) {
            $id = ++$this->seq;
            $this->in['archers'][] = array('id' => $id, 'dist' => $dist, 'fam' => $fam,
                'club' => $o['club'] ?? ('C' . $id), 'with' => null, 'letter' => '',
                'wheelchair' => !empty($o['wheelchair']));
            $ids[] = $id;
        }
        return $ids;
    }

    /** An archer already placed (by hand or by another tool). */
    public function fixed($t, $l, $fam, $dist = array(18, 18), $club = 'FIX')
    {
        $id = ++$this->seq;
        $this->in['fixed'][] = array('id' => $id, 't' => $t, 'l' => $l, 'dist' => $dist, 'fam' => $fam, 'club' => $club);
        return $id;
    }

    public function wish($id, $with = null, $letter = '')
    {
        foreach ($this->in['archers'] as &$a) {
            if ($a['id'] !== $id) continue;
            if ($with !== null) $a['with'] = $with;
            if ($letter !== '') $a['letter'] = $letter;
        }
    }

    public function caps($t, $caps) { $this->in['caps'][$t] = $caps; }

    public function minTargets($n) { $this->in['minTargets'] = $n; }

    /** Plans, and checks the invariants on the way. */
    public function run()
    {
        $res = bk_plan_session($this->in);
        check_valid($this, $res);
        return $res;
    }

    public static function fams($a)
    {
        return is_array($a['fam']) ? array_values($a['fam']) : array_fill(0, max(1, count($a['dist'])), $a['fam']);
    }

    /** [target => [letter => archer]], archers already placed included. */
    public function byTarget($res)
    {
        $info = array();
        foreach (array_merge($this->in['archers'], $this->in['fixed']) as $a) $info[$a['id']] = $a;
        $out = array();
        foreach ($this->in['fixed'] as $f) $out[$f['t']][$f['l']] = $f;
        foreach ($res['places'] as $id => $p) $out[$p['t']][$p['l']] = $info[$id];
        ksort($out);
        foreach ($out as &$c) ksort($c);
        return $out;
    }

    /** Archers per target used, in the order of the targets. */
    public function counts($res)
    {
        return array_values(array_map('count', $this->byTarget($res)));
    }

    public static function at($res, $id)
    {
        truthy(isset($res['places'][$id]), "archer $id not placed");
        return $res['places'][$id];
    }
}

/** Letters of the left and right halves of the buttress for a rhythm. */
function halves($rhythm)
{
    $letters = array_slice(array('A', 'B', 'C', 'D'), 0, max(1, min(4, $rhythm)));
    return array('left' => array_values(array_intersect($letters, array('A', 'C'))),
                 'right' => array_values(array_intersect($letters, array('B', 'D'))));
}

/** Invariants of EVERY plan, whatever the data. */
function check_valid(Plan $p, $res)
{
    $in = $p->in;
    $todo = array_column($in['archers'], 'id');
    sort($todo);
    $all = array_merge(array_map('intval', array_keys($res['places'])), array_map('intval', $res['unplaced']));
    sort($all);
    same($todo, $all, 'every archer placed or unplaced, once');

    foreach ($in['fixed'] as $f) truthy(!isset($res['places'][$f['id']]), 'an archer already placed gets no new place');

    $letters = array_slice(array('A', 'B', 'C', 'D', 'E', 'F'), 0, $in['rhythm']);
    $seen = array();
    foreach ($in['fixed'] as $f) $seen[$f['t'] . $f['l']] = true;
    foreach ($res['places'] as $id => $pl) {
        truthy(in_array($pl['t'], $in['targets'], true), "archer $id: target {$pl['t']} outside the departure");
        truthy(in_array($pl['l'], $letters, true), "archer $id: letter {$pl['l']} outside the rhythm");
        truthy(!isset($seen[$pl['t'] . $pl['l']]), "place {$pl['t']}{$pl['l']} taken twice");
        $seen[$pl['t'] . $pl['l']] = true;
    }

    foreach ($p->byTarget($res) as $t => $byLetter) {
        $dists = array_unique(array_map(function ($a) { return implode('-', $a['dist']); }, $byLetter));
        truthy(count($dists) === 1, "target $t: several distances " . show(array_values($dists)));
        $nDist = count(Plan::fams(reset($byLetter)));
        for ($d = 0; $d < $nDist; $d++) {
            $fams = array_map(function ($a) use ($d) { return Plan::fams($a)[$d]; }, $byLetter);
            if ($in['disc'] === 'salle' && $in['rhythm'] <= 4) {
                foreach (halves($in['rhythm']) as $side => $ls) {
                    $half = array_unique(array_values(array_intersect_key($fams, array_flip($ls))));
                    truthy(count($half) <= 1, "target $t, distance " . ($d + 1) . ", $side half: several families " . show(array_values($half)));
                }
            }
            truthy(bk_target_layout($in['disc'], $in['rhythm'], $fams)['ok'], "target $t impossible at distance " . ($d + 1) . ': ' . show($fams));
        }
        $caps = $in['caps'][$t] ?? null;
        if (!$caps) continue;
        foreach ($byLetter as $a) {
            if (!empty($caps['fams'])) foreach (Plan::fams($a) as $f) truthy(in_array($f, $caps['fams'], true), "target $t: family $f refused by the field setting");
            foreach ($a['dist'] as $m) {
                if (!empty($caps['min'])) truthy($m >= $caps['min'], "target $t: distance below the field setting");
                if (!empty($caps['max'])) truthy($m <= $caps['max'], "target $t: distance above the field setting");
            }
        }
    }
}

/** Rank of a target on one distance (a mixed 60 follows its partner): 80, 60, 40, triple. */
function target_rank($fams)
{
    $rank = array('80' => 0, '60' => 1, '60t' => 1, '40' => 2, 'tri' => 3, '?' => 4);
    foreach (array('?', '40', 'tri', '60', '60t', '80') as $f) if (in_array($f, $fams, true)) return $rank[$f];
    return 4;
}

/** Targets of a distance next to each other, shortest distance first, families 80, 60, 40, triple. */
function check_ordered(Plan $p, $res)
{
    $fixedTargets = array_flip(array_column($p->in['fixed'], 't'));
    $seq = array();
    foreach ($p->byTarget($res) as $t => $byLetter) {
        if (isset($fixedTargets[$t])) continue;
        $first = reset($byLetter);
        $ranks = array();
        for ($d = 0; $d < count(Plan::fams($first)); $d++) {
            $ranks[] = target_rank(array_map(function ($a) use ($d) { return Plan::fams($a)[$d]; }, $byLetter));
        }
        $seq[] = array('t' => $t, 'dist' => $first['dist'], 'rank' => $ranks);
    }
    $free = array_values(array_filter($p->in['targets'], function ($t) use ($fixedTargets) { return !isset($fixedTargets[$t]); }));
    $next = array();
    for ($i = 1; $i < count($free); $i++) $next[$free[$i - 1]] = $free[$i];
    for ($i = 1; $i < count($seq); $i++) {
        $a = $seq[$i - 1]; $b = $seq[$i];
        same($next[$a['t']] ?? null, $b['t'], "targets next to each other after {$a['t']}");
        $cmp = $a['dist'] <=> $b['dist'];
        truthy($cmp <= 0, "a longer distance before a shorter one (targets {$a['t']}, {$b['t']})");
        if ($cmp === 0) truthy(($a['rank'] <=> $b['rank']) <= 0, "family order 80, 60, 40, triple broken (targets {$a['t']}, {$b['t']})");
    }
}

/** Within one distance and one family (targets of a single family), one archer of gap at most. */
function check_balanced(Plan $p, $res)
{
    $groups = array();
    foreach ($p->byTarget($res) as $t => $byLetter) {
        $keys = array_unique(array_map(function ($a) { return implode('/', array_unique(Plan::fams($a))); }, $byLetter));
        if (count($keys) !== 1) continue;
        $first = reset($byLetter);
        $groups[implode('-', $first['dist']) . '/' . reset($keys)][$t] = count($byLetter);
    }
    foreach ($groups as $g => $n) truthy(max($n) - min($n) <= 1, "$g unbalanced: " . show($n));
}

/** Archers of one club per target ≤ quota. */
function check_quota(Plan $p, $res, $max)
{
    foreach ($p->byTarget($res) as $t => $byLetter) {
        foreach (array_count_values(array_map(function ($a) { return $a['club']; }, $byLetter)) as $club => $n) {
            truthy($n <= $max, "target $t: $n archers of club $club");
        }
    }
}

/* ═══ Faces ═══════════════════════════════════════════════════════════════════════════════════ */

section('Family of a face (type and diameter of ianseo, not the name)');

foreach (array(
    // Indoor faces ianseo creates for the FFTA (Modules/Sets/FR/Setup_Target.php).
    array('Blason Unique 40cm', 1, 40, '40'),
    array('Blason Unique', 1, 40, '40'),
    array('Blason Unique 60cm', 1, 60, '60'),
    array('Trispot Classique 6-10 40cm', 2, 40, 'tri'),
    array('Trispot Classique 6-10', 2, 40, 'tri'),
    array('Trispot 40cm 6-10', 2, 40, 'tri'),
    array('Trispot Poulie 6-10 40cm', 4, 40, 'tri'),
    array('Trispot Poulie 6-10', 4, 40, 'tri'),
    array('Trispot Classique 6-10 60cm', 2, 60, '60t'),
    array('Trispot 60cm 6-10', 2, 60, '60t'),
    array('Trispot Poulie 6-10 60cm', 4, 60, '60t'),
    array('Blason Unique 80cm', 1, 80, '80'),
    array('Blason Découverte', 1, 80, '80'),
    // The ianseo type decides, not the name typed by the organiser.
    array('My face', 4, 40, 'tri'),
    array('Big', 1, 60, '60'),
) as $c) {
    test_case("\"{$c[0]}\" (type {$c[1]}, {$c[2]} cm) is a {$c[3]}", function () use ($c) {
        same($c[3], bk_face_family('salle', $c[0], $c[1], $c[2]));
    });
}
test_case('recurve and compound triple faces are one family', function () {
    same(bk_face_family('salle', 'Trispot Classique 6-10 40cm', 2, 40), bk_face_family('salle', 'Trispot Poulie 6-10 40cm', 4, 40));
});
test_case('one face under two names is one family', function () {
    same(bk_face_family('salle', 'Trispot Classique 6-10 40cm', 2, 40), bk_face_family('salle', 'Trispot 40cm 6-10', 2, 40));
});
test_case('a face not recognised is unknown (alone on its target)', function () {
    same('?', bk_face_family('salle', '', 0, 0));
});
test_case('outdoors: the classes of lib/cohabitation.php are kept', function () {
    same('122|full', bk_face_family('ext', 'Blason Classique 122', 5, 122));
    same('80|reduit', bk_face_family('ext', 'Blason réduit 80', 5, 80));
});

section('Indoor 25 + 18: one family per distance');

foreach (array(
    array('Blason Unique 60cm/40cm', array(array(1, 60), array(1, 60), array(1, 40), array(1, 40)), array('60', '60', '40', '40')),
    array('Blason Unique 80cm/60cm', array(array(1, 80), array(1, 80), array(1, 60), array(1, 60)), array('80', '80', '60', '60')),
    array('Trispot Poulie 6-10 60cm', array(array(4, 60), array(4, 60), array(4, 40), array(4, 40)), array('60t', '60t', 'tri', 'tri')),
    array('Trispot Classique 60cm/40cm', array(array(2, 60), array(2, 60), array(2, 40), array(2, 40)), array('60t', '60t', 'tri', 'tri')),
    // A distance without a type takes the previous one.
    array('Trispot 40 cm', array(array(2, 40), array(0, 0)), array('tri', 'tri')),
) as $c) {
    test_case("\"{$c[0]}\" is " . implode(' / ', $c[2]), function () use ($c) {
        same($c[2], bk_face_families('salle', $c[0], $c[1], count($c[2])));
    });
}

section('Indoor buttress: left half A and C, right half B and D');

foreach (array(
    // One family per half, mixes allowed between the halves.
    array('4 × 40', array('A' => '40', 'B' => '40', 'C' => '40', 'D' => '40'), true, false),
    array('4 triple faces', array('A' => 'tri', 'B' => 'tri', 'C' => 'tri', 'D' => 'tri'), true, false),
    array('a 60 shared by A-C and one by B-D', array('A' => '60', 'C' => '60', 'B' => '60', 'D' => '60'), true, false),
    array('60 left + 40 right', array('A' => '60', 'C' => '60', 'B' => '40', 'D' => '40'), true, false),
    array('40 left + 60 right', array('A' => '40', 'C' => '40', 'B' => '60', 'D' => '60'), true, false),
    array('60 left + triple right', array('A' => '60', 'C' => '60', 'B' => 'tri', 'D' => 'tri'), true, false),
    array('a 60 alone in A', array('A' => '60'), true, false),
    array('60 in A and 40 in B', array('A' => '60', 'B' => '40'), true, false),
    // 40 with triple: possible, last resort, reported.
    array('40 left + triple right (last resort, reported)', array('A' => '40', 'C' => '40', 'B' => 'tri', 'D' => 'tri'), true, true),
    // Two families in one half: impossible.
    array('60 in A and 40 in C: two families in a half', array('A' => '60', 'C' => '40'), false, false),
    array('40 in A and triple in C: two families in a half', array('A' => '40', 'C' => 'tri'), false, false),
    array('60 in B and triple in D: two families in a half', array('B' => '60', 'D' => 'tri'), false, false),
    // 60 single face and 60 triple: same target, never the same half.
    array('60 left + 60 triple right', array('A' => '60', 'C' => '60', 'B' => '60t', 'D' => '60t'), true, false),
    array('60 in A and 60 triple in C: two faces in a half', array('A' => '60', 'C' => '60t'), false, false),
    array('60 triple + 40', array('A' => '60t', 'C' => '60t', 'B' => '40', 'D' => '40'), true, false),
    array('60 triple + 40 triple', array('A' => '60t', 'C' => '60t', 'B' => 'tri', 'D' => 'tri'), true, false),
    // 80: the whole buttress, shared by the 4, alone on its target.
    array('4 × 80', array('A' => '80', 'B' => '80', 'C' => '80', 'D' => '80'), true, false),
    array('an 80 alone', array('A' => '80'), true, false),
    array('80 with a 60: impossible', array('A' => '80', 'C' => '80', 'B' => '60'), false, false),
    array('80 with a 40: impossible', array('A' => '80', 'B' => '40'), false, false),
    // Unknown face: alone on its target.
    array('an unknown face alone', array('A' => '?'), true, false),
    array('an unknown face with another archer: impossible', array('A' => '?', 'B' => '40'), false, false),
) as $c) {
    test_case($c[0], function () use ($c) {
        $r = bk_target_layout('salle', 4, $c[1]);
        same($c[2], $r['ok'], 'possible');
        if ($c[2]) same($c[3], $r['mixed40tri'], '40 with triple reported');
    });
}
test_case('rhythm 3: left A and C, right B', function () {
    truthy(bk_target_layout('salle', 3, array('A' => '60', 'C' => '60', 'B' => '60'))['ok'], '3 × 60');
    truthy(bk_target_layout('salle', 3, array('A' => '40', 'C' => '40', 'B' => '60'))['ok'], '40 left, 60 right');
    truthy(!bk_target_layout('salle', 3, array('A' => '40', 'C' => '60', 'B' => '60'))['ok'], 'two families left');
    truthy(!bk_target_layout('salle', 3, array('A' => '40', 'B' => '40', 'C' => '40', 'D' => '40'))['ok'], 'no letter D at rhythm 3');
});
test_case('rhythm 2: one archer per half', function () {
    truthy(bk_target_layout('salle', 2, array('A' => '60', 'B' => '60'))['ok'], '2 × 60');
    truthy(bk_target_layout('salle', 2, array('A' => '60', 'B' => 'tri'))['ok'], '60 and triple');
    truthy(!bk_target_layout('salle', 2, array('A' => '60', 'C' => '60'))['ok'], 'no letter C at rhythm 2');
});
test_case('outdoors: a full face shared by 4', function () {
    truthy(bk_target_layout('ext', 4, array('A' => '122|full', 'B' => '122|full', 'C' => '122|full', 'D' => '122|full'))['ok'], '4 × 122');
});
test_case('outdoors: up to 3 reduced faces, never with a full face', function () {
    truthy(bk_target_layout('ext', 4, array('A' => '80|reduit', 'B' => '80|reduit', 'C' => '80|reduit'))['ok'], '3 reduced');
    truthy(!bk_target_layout('ext', 4, array('A' => '80|reduit', 'B' => '80|reduit', 'C' => '80|reduit', 'D' => '80|reduit'))['ok'], '4 reduced');
    truthy(!bk_target_layout('ext', 4, array('A' => '80|reduit', 'B' => '122|full'))['ok'], 'reduced with full');
});

/* ═══ Planner ═════════════════════════════════════════════════════════════════════════════════ */

section('Composition of the targets (indoors, rhythm 4)');

test_case('categories do not matter: 6 triple faces make 3 + 3', function () {
    $p = new Plan(); $p->add(6, 'tri');
    $res = $p->run();
    same(array(3, 3), $p->counts($res));
    same(array(), $res['unplaced']);
});
test_case('8 triple faces (recurve and compound) make 4 + 4', function () {
    $p = new Plan(); $p->add(8, 'tri');
    same(array(4, 4), $p->counts($p->run()));
});
test_case('40 and triple faces each on their own targets, 40 first', function () {
    $p = new Plan(); $p->add(5, '40'); $p->add(3, 'tri');
    $res = $p->run();
    same(array(3, 2, 3), $p->counts($res));
    same(0, $res['mixed40tri']);
    check_ordered($p, $res);
    $byT = $p->byTarget($res);
    same(array('40'), array_values(array_unique(array_column($byT[1], 'fam'))), 'target 1');
    same(array('tri'), array_values(array_unique(array_column($byT[3], 'fam'))), 'target 3');
});
test_case('a 60 completes the half of another family: 1×60 + 5×40 + 5 triple on 4 targets', function () {
    $p = new Plan(); $p->add(1, '60'); $p->add(5, '40'); $p->add(5, 'tri');
    $res = $p->run();
    same(4, count($p->counts($res)), 'targets');
    same(11, array_sum($p->counts($res)), 'archers');
    same(0, $res['mixed40tri']);
    check_ordered($p, $res);
    check_balanced($p, $res);
});
test_case('the 60 is shared: 2×60 + 2×40 fit on one target, the 60s on one half', function () {
    $p = new Plan(); $sixty = $p->add(2, '60'); $p->add(2, '40');
    $res = $p->run();
    same(array(4), $p->counts($res));
    $letters = array(Plan::at($res, $sixty[0])['l'], Plan::at($res, $sixty[1])['l']);
    sort($letters);
    truthy(in_array($letters, array(array('A', 'C'), array('B', 'D')), true), 'the two 60s shoot the same face: ' . show($letters));
});
test_case('4×60: one face left (A, C), one right (B, D)', function () {
    $p = new Plan(); $p->add(4, '60');
    same(array(4), $p->counts($p->run()));
});
test_case('balance: 9 triple faces make 3 + 3 + 3', function () {
    $p = new Plan(); $p->add(9, 'tri');
    same(array(3, 3, 3), $p->counts($p->run()));
});
test_case('1×60 + 2×40 + 2 triple on 2 targets, no 40 with triple', function () {
    $p = new Plan(); $p->add(1, '60'); $p->add(2, '40'); $p->add(2, 'tri');
    $res = $p->run();
    same(2, count($p->counts($res)), 'targets');
    same(0, $res['mixed40tri']);
    check_ordered($p, $res);
});
test_case('the target completed by a 60 opens the 40 block (60 / 40 junction)', function () {
    $p = new Plan(); $sixty = $p->add(1, '60'); $p->add(5, '40');
    $res = $p->run();
    same(array(3, 3), $p->counts($res));
    same(1, Plan::at($res, $sixty[0])['t'], 'target of the 60');
});
test_case('a 60 with two 40s on one target', function () {
    $p = new Plan(); $p->add(1, '60'); $p->add(2, '40');
    same(array(3), $p->counts($p->run()));
});
test_case('rhythm 3: 3×60 on one target', function () {
    $p = new Plan('salle', 10, 3); $p->add(3, '60');
    same(array(3), $p->counts($p->run()));
});
test_case('rhythm 2: 2×60 + 2×40 on 2 targets', function () {
    $p = new Plan('salle', 10, 2); $p->add(2, '60'); $p->add(2, '40');
    same(array(2, 2), $p->counts($p->run()));
});
test_case('an unknown face is alone on its target', function () {
    $p = new Plan('salle', 2); $p->add(3, '?');
    $res = $p->run();
    same(array(1, 1), $p->counts($res));
    same(1, count($res['unplaced']), 'unplaced');
});
test_case('nothing to place', function () {
    $p = new Plan();
    $res = $p->run();
    same(array(), $res['places']);
    same(array(), $res['unplaced']);
});

section('Distances');

test_case('distances never mixed, shortest first, on targets next to each other', function () {
    $p = new Plan('ext'); $p->add(5, '122|full', array(70, 70)); $p->add(3, '122|full', array(60, 60));
    $res = $p->run();
    same(array(3, 3, 2), $p->counts($res));
    check_ordered($p, $res);
    $byT = $p->byTarget($res);
    same(array(60, 60), reset($byT[1])['dist'], 'target 1');
});
test_case('better an archer without a target than a target at two distances', function () {
    $p = new Plan('ext', 1); $p->add(2, '122|full', array(60, 60)); $p->add(2, '122|full', array(70, 70));
    $res = $p->run();
    same(2, count($res['places']), 'placed');
    same(2, count($res['unplaced']), 'unplaced');
});
test_case('distances compared series by series', function () {
    $p = new Plan('ext', 1); $p->add(2, '122|full', array(70, 50)); $p->add(2, '122|full', array(50, 70));
    same(2, count($p->run()['unplaced']));
});
test_case('outdoors: 3 reduced faces together, the 122 apart', function () {
    $p = new Plan('ext'); $reduced = $p->add(3, '80|reduit', array(50, 50)); $full = $p->add(1, '122|full', array(50, 50));
    $res = $p->run();
    $t = Plan::at($res, $reduced[0])['t'];
    foreach ($reduced as $id) same($t, Plan::at($res, $id)['t'], 'reduced faces together');
    truthy(Plan::at($res, $full[0])['t'] !== $t, 'the 122 on another target');
});
test_case('outdoors: a full face shared across categories', function () {
    $p = new Plan('ext'); $p->add(4, '122|full', array(70, 70));
    same(array(4), $p->counts($p->run()));
});

section('Field settings (hard)');

test_case('a target limited to 60s takes only 60s', function () {
    $p = new Plan('salle', 3); $p->caps(1, array('fams' => array('60'))); $p->add(4, '40'); $p->add(1, '60');
    same(array(), $p->run()['unplaced']);
});
test_case('the field setting beats the distance order', function () {
    $p = new Plan('ext', 2); $p->caps(1, array('min' => 70)); $p->caps(2, array('max' => 60));
    $long = $p->add(4, '122|full', array(70, 70)); $short = $p->add(4, '122|full', array(60, 60));
    $res = $p->run();
    same(1, Plan::at($res, $long[0])['t'], '70 m');
    same(2, Plan::at($res, $short[0])['t'], '60 m');
});

section('Archers already placed (never moved)');

test_case('an archer already placed imposes their family on their half', function () {
    $p = new Plan('salle', 3); $fixed = $p->fixed(1, 'B', 'tri'); $p->add(2, '40'); $p->add(3, 'tri');
    $res = $p->run();
    truthy(!isset($res['places'][$fixed]), 'not moved');
    same(array(), $res['unplaced']);
});
test_case('an archer already placed imposes their distance', function () {
    $p = new Plan('ext', 1); $p->fixed(1, 'A', '122|full', array(70, 70)); $p->add(1, '122|full', array(60, 60));
    same(1, count($p->run()['unplaced']));
});

section('Priorities (each against the next)');

test_case('1 > 2: everyone placed, beyond the club quota if needed (reported)', function () {
    $p = new Plan('salle', 1, 4, 2); $p->add(4, 'tri', array(18, 18), array('club' => 'X'));
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(2, $res['overQuota']);
});
test_case('1 > 3: everyone placed, 40 with triple if needed (reported)', function () {
    $p = new Plan('salle', 1); $p->add(2, '40'); $p->add(2, 'tri');
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(1, $res['mixed40tri']);
});
test_case('2 > 4: club quota before fewest targets', function () {
    $p = new Plan('salle', 10, 4, 2); $p->add(6, 'tri', array(18, 18), array('club' => 'X'));
    $res = $p->run();
    same(array(2, 2, 2), $p->counts($res));
    same(0, $res['overQuota']);
});
test_case('2 > 3: club quota before keeping 40 and triple apart', function () {
    $p = new Plan('salle', 2, 4, 2); $p->add(4, '40', array(18, 18), array('club' => 'X')); $p->add(4, 'tri', array(18, 18), array('club' => 'Y'));
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(0, $res['overQuota']);
    same(2, $res['mixed40tri']);
    check_quota($p, $res, 2);
});
test_case('3 > 4: one more target rather than 40 with triple', function () {
    $p = new Plan('salle', 3); $p->add(2, '40'); $p->add(2, 'tri');
    $res = $p->run();
    same(0, $res['mixed40tri']);
    same(array(2, 2), $p->counts($res));
});
test_case('4 > 6: 5 triple faces on 2 targets (3 + 2), not on 3', function () {
    $p = new Plan('salle', 3); $p->add(5, 'tri');
    same(array(3, 2), $p->counts($p->run()));
});
test_case('6 > 7: a "with someone" group of 4 does not break the balance 3 + 3', function () {
    $p = new Plan(); $ids = $p->add(4, 'tri');
    foreach (array_slice($ids, 1) as $id) $p->wish($id, $ids[0]);
    $p->add(2, 'tri');
    $res = $p->run();
    same(array(3, 3), $p->counts($res));
    same(3, $res['wishes']['asked'], 'wishes asked');
    truthy($res['wishes']['kept'] < 3, 'not every wish kept');
});
test_case('2 > 7: club quota before "with someone" wishes', function () {
    $p = new Plan('salle', 10, 4, 2); $ids = $p->add(3, 'tri', array(18, 18), array('club' => 'X'));
    $p->wish($ids[1], $ids[0]); $p->wish($ids[2], $ids[0]); $p->add(3, 'tri');
    $res = $p->run();
    check_quota($p, $res, 2);
    same(0, $res['overQuota']);
});
test_case('3 > 7: a 40 and a triple face wishing to shoot together stay apart', function () {
    $p = new Plan(); $forty = $p->add(2, '40'); $tri = $p->add(2, 'tri'); $p->wish($forty[0], $tri[0]);
    $res = $p->run();
    same(0, $res['mixed40tri']);
    truthy(Plan::at($res, $forty[0])['t'] !== Plan::at($res, $tri[0])['t'], 'different targets');
    same(array('asked' => 1, 'kept' => 0), $res['wishes']);
});
test_case('7 > 8: two archers wishing to shoot together AND letter A: same target', function () {
    $p = new Plan(); $ids = $p->add(2, 'tri'); $p->wish($ids[0], $ids[1], 'A'); $p->wish($ids[1], null, 'A'); $p->add(4, 'tri');
    $res = $p->run();
    same(Plan::at($res, $ids[0])['t'], Plan::at($res, $ids[1])['t'], 'same target');
});

section('"With someone" groups: the anchor');

test_case('group split by the quota: the person asked for stays with one of the askers', function () {
    $p = new Plan('salle', 10, 4, 2); $ids = $p->add(3, 'tri', array(18, 18), array('club' => 'X'));
    $p->wish($ids[1], $ids[0]); $p->wish($ids[2], $ids[0]); $p->add(3, 'tri');
    $res = $p->run();
    $anchor = Plan::at($res, $ids[0])['t'];
    truthy(in_array($anchor, array(Plan::at($res, $ids[1])['t'], Plan::at($res, $ids[2])['t']), true), 'anchor with an asker');
    same(1, $res['wishes']['kept']);
});
test_case('group bigger than a balanced target: the anchor keeps as many askers as it holds', function () {
    $p = new Plan(); $ids = $p->add(5, 'tri');
    foreach (array_slice($ids, 1) as $id) $p->wish($id, $ids[0]);
    $p->add(1, 'tri');
    $res = $p->run();
    same(array(3, 3), $p->counts($res));
    same(2, $res['wishes']['kept'], 'the anchor and two askers together');
});

section('Wishes kept when nothing stands against them');

test_case('a "with someone" wish is kept', function () {
    $p = new Plan(); $ids = $p->add(8, 'tri'); $p->wish($ids[7], $ids[0]);
    $res = $p->run();
    same(Plan::at($res, $ids[0])['t'], Plan::at($res, $ids[7])['t'], 'same target');
    same(array('asked' => 1, 'kept' => 1), $res['wishes']);
});
test_case('a wish between a 40 and a 60 (allowed mix) is kept', function () {
    $p = new Plan(); $forty = $p->add(1, '40'); $sixty = $p->add(1, '60'); $p->wish($forty[0], $sixty[0]); $p->add(4, '40');
    $res = $p->run();
    same(Plan::at($res, $forty[0])['t'], Plan::at($res, $sixty[0])['t'], 'same target');
});
test_case('a letter wish is kept', function () {
    $p = new Plan(); $ids = $p->add(3, 'tri'); $p->wish($ids[2], null, 'C');
    $res = $p->run();
    same('C', Plan::at($res, $ids[2])['l']);
    same(array('asked' => 1, 'kept' => 1), $res['wishes']);
});

section('Regulations: at least 6 targets filled (2 archers or more) per departure');

test_case('20 archers fitting on 5 targets are spread over 6', function () {
    $p = new Plan(); $p->minTargets(6); $p->add(20, '40');
    $res = $p->run();
    same(array(4, 4, 3, 3, 3, 3), $p->counts($res));
    check_balanced($p, $res);
});
test_case('12 archers make 6 pairs', function () {
    $p = new Plan(); $p->minTargets(6); $p->add(12, 'tri');
    same(array(2, 2, 2, 2, 2, 2), $p->counts($p->run()));
});
test_case('never a target of a single archer to reach the minimum', function () {
    $p = new Plan(); $p->minTargets(6); $p->add(11, 'tri');
    same(array(3, 2, 2, 2, 2), $p->counts($p->run()));
});
test_case('several families: the busiest is spread, order and balance hold', function () {
    $p = new Plan(); $p->minTargets(6); $p->add(10, '40'); $p->add(6, 'tri');
    $res = $p->run();
    $counts = $p->counts($res);
    same(6, count($counts), 'targets');
    truthy(min($counts) >= 2, 'every target filled: ' . show($counts));
    same(0, $res['mixed40tri']);
    check_ordered($p, $res);
    check_balanced($p, $res);
});
test_case('the minimum is limited by the free targets', function () {
    $p = new Plan('salle', 5); $p->minTargets(6); $p->add(18, '40');
    same(5, count($p->counts($p->run())));
});
test_case('the minimum keeps the club quota', function () {
    $p = new Plan('salle', 10, 4, 2); $p->minTargets(6);
    $clubs = array('A', 'B', 'C');
    for ($i = 0; $i < 15; $i++) $p->add(1, 'tri', array(18, 18), array('club' => $clubs[$i % 3]));
    $res = $p->run();
    same(6, count($p->counts($res)), 'targets');
    same(0, $res['overQuota']);
    check_quota($p, $res, 2);
});

section('Regulations B.1.2: no archer alone, no same-club pair, wheelchair');

test_case('no archer alone: a single 40 joins the triple faces rather than shoot alone (before 40 + triple)', function () {
    $p = new Plan(); $p->add(1, '40'); $p->add(3, 'tri');
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(0, $res['alone']);
    same(1, $res['mixed40tri'], 'mix reported');
});
test_case('no archer alone, unless physically impossible (an unknown face)', function () {
    $p = new Plan('salle', 3); $p->add(1, '?'); $p->add(3, 'tri');
    $res = $p->run();
    same(1, $res['alone']);
});
test_case('two archers of one club are not left alone on a target', function () {
    $p = new Plan('salle', 10, 4, 2); $p->minTargets(6);
    $clubs = array('A', 'A', 'B', 'B', 'C', 'C', 'D', 'D', 'E', 'E', 'F', 'F');
    foreach ($clubs as $c) $p->add(1, 'tri', array(18, 18), array('club' => $c));
    $res = $p->run();
    same(array(2, 2, 2, 2, 2, 2), $p->counts($res));
    same(0, $res['sameClubPairs']);
});
test_case('…while the club quota still comes first (6 archers of one club: 2 + 2 + 2)', function () {
    $p = new Plan('salle', 10, 4, 2); $p->add(6, 'tri', array(18, 18), array('club' => 'X'));
    $res = $p->run();
    same(array(2, 2, 2), $p->counts($res));
    same(0, $res['overQuota']);
    same(3, $res['sameClubPairs'], 'unavoidable, reported');
});
test_case('the minimum of 6 targets with 3 clubs makes no same-club pair', function () {
    $p = new Plan('salle', 10, 4, 2); $p->minTargets(6);
    $clubs = array('A', 'B', 'C');
    for ($i = 0; $i < 15; $i++) $p->add(1, 'tri', array(18, 18), array('club' => $clubs[$i % 3]));
    $res = $p->run();
    same(0, $res['sameClubPairs']);
    same(0, $res['overQuota']);
});
test_case('no 4 archers on a target with a wheelchair archer', function () {
    $p = new Plan();
    $p->add(1, 'tri', array(18, 18), array('wheelchair' => true));
    $p->add(7, 'tri');
    $res = $p->run();
    same(0, $res['wheelchairFull']);
    same(array(), $res['unplaced']);
});
test_case('…unless every target is needed full', function () {
    $p = new Plan('salle', 2);
    $p->add(1, 'tri', array(18, 18), array('wheelchair' => true));
    $p->add(7, 'tri');
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(1, $res['wheelchairFull'], 'reported');
});
test_case('an archer already placed is never moved to fix these rules', function () {
    $p = new Plan('salle', 3); $fixed = $p->fixed(2, 'A', '40'); $p->add(4, 'tri');
    $res = $p->run();
    truthy(!isset($res['places'][$fixed]), 'not moved');
});

section('80 and 60 triple faces');

test_case('an 80 is shared by 4', function () {
    $p = new Plan(); $p->add(4, '80');
    same(array(4), $p->counts($p->run()));
});
test_case('80s are balanced: 5 make 3 + 2', function () {
    $p = new Plan(); $p->add(5, '80');
    same(array(3, 2), $p->counts($p->run()));
});
test_case('an 80 is alone on its target, and its targets come first', function () {
    $p = new Plan(); $p->add(2, '60'); $eighty = $p->add(2, '80');
    $res = $p->run();
    same(array(2, 2), $p->counts($res));
    same(1, Plan::at($res, $eighty[0])['t'], 'target of the 80s');
    check_ordered($p, $res);
});
test_case('an 80 with other faces is impossible, even to place everyone (physical)', function () {
    $p = new Plan('salle', 1); $p->add(2, '80'); $p->add(2, '40');
    same(2, count($p->run()['unplaced']));
});
test_case('60 single face and 60 triple: same target, each its half', function () {
    $p = new Plan(); $p->add(2, '60'); $p->add(2, '60t');
    same(array(4), $p->counts($p->run()));
});

section('Indoor 25 + 18: every distance must fit');

$D25_18 = array(25, 25, 18, 18);
$F60_40 = array('60', '60', '40', '40');
$F60T_TRI = array('60t', '60t', 'tri', 'tri');
$F80_60 = array('80', '80', '60', '60');

test_case('the same two-distance face fills a target', function () use ($D25_18, $F60_40) {
    $p = new Plan(); $p->add(4, $F60_40, $D25_18);
    same(array(4), $p->counts($p->run()));
});
test_case('60/40 and 60 triple/40 triple: possible at 25 m, 40 with triple at 18 m → apart', function () use ($D25_18, $F60_40, $F60T_TRI) {
    $p = new Plan(); $p->add(2, $F60_40, $D25_18); $p->add(2, $F60T_TRI, $D25_18);
    $res = $p->run();
    same(array(2, 2), $p->counts($res));
    same(0, $res['mixed40tri']);
});
test_case('…together as a last resort, the mix at 18 m reported', function () use ($D25_18, $F60_40, $F60T_TRI) {
    $p = new Plan('salle', 1); $p->add(2, $F60_40, $D25_18); $p->add(2, $F60T_TRI, $D25_18);
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(1, $res['mixed40tri']);
});
test_case('80/60 with 60/40: 80 + 60 impossible at 25 m, even if 60 + 40 would fit at 18 m', function () use ($D25_18, $F60_40, $F80_60) {
    $p = new Plan('salle', 1); $p->add(2, $F80_60, $D25_18); $p->add(2, $F60_40, $D25_18);
    same(2, count($p->run()['unplaced']));
});
test_case('a large 25 + 18 departure: valid, ordered, balanced, quota kept', function () use ($D25_18, $F60_40, $F60T_TRI, $F80_60) {
    $p = new Plan('salle', 20, 4, 2);
    $clubs = array('A', 'B', 'C', 'D', 'E');
    $i = 0;
    foreach (array(array($F80_60, 6), array($F60_40, 25), array($F60T_TRI, 22)) as $c) {
        for ($k = 0; $k < $c[1]; $k++) $p->add(1, $c[0], $D25_18, array('club' => $clubs[$i++ % count($clubs)]));
    }
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(0, $res['mixed40tri']);
    check_ordered($p, $res);
    check_balanced($p, $res);
    check_quota($p, $res, 2);
});

section('A large departure');

test_case('71 archers (7 × 60, 23 × 40, 41 triple), 7 clubs: valid, ordered, balanced, quota kept', function () {
    $p = new Plan('salle', 30, 4, 2);
    $clubs = array('A', 'B', 'C', 'D', 'E', 'F', 'G');
    $i = 0;
    foreach (array('60' => 7, '40' => 23, 'tri' => 41) as $fam => $n) {
        for ($k = 0; $k < $n; $k++) $p->add(1, (string) $fam, array(18, 18), array('club' => $clubs[$i++ % count($clubs)]));
    }
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(0, $res['overQuota']);
    same(0, $res['mixed40tri']);
    check_ordered($p, $res);
    check_balanced($p, $res);
    check_quota($p, $res, 2);
});

/* ═══ Summary ═════════════════════════════════════════════════════════════════════════════════ */

$total = $RESULTS['ok'] + $RESULTS['ko'];
echo "\nTarget placement: {$RESULTS['ok']} / $total cases passed" . ($RESULTS['ko'] ? ", {$RESULTS['ko']} FAILED" : '') . ".\n";
exit($RESULTS['ko'] ? 1 : 0);
