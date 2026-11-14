<?php
/**
 * tests/placement.php — test bench of the target placement (lib/placement.php).
 *
 *   php Modules/Custom/AUTH/booking/tests/placement.php          (any PHP 7.4+, ianseo's included)
 *   php Modules/Custom/AUTH/booking/tests/placement.php -v       (every case, not only failures)
 *
 * The planner is pure (no database): the cases are built by hand and every rule of
 * REGLES_PLACEMENT.md has its case here, so the output reads as the specification. Exit code 0
 * when every case passes, 1 otherwise.
 *
 * Besides the expected result of each case, EVERY plan is checked against the invariants that
 * must hold whatever the data (check_valid): each archer placed once or reported unplaced, no
 * place taken twice, archers already placed never moved, one distance per target, one family
 * per half of the buttress at every distance, a possible target, field settings respected.
 *
 * Command line only: nothing is run from the web. Not distributed (absent from files[]).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '1');

// The planner never touches the database; the ianseo SQL functions only exist so that the
// libraries load.
if (!function_exists('safe_r_sql')) { function safe_r_sql($q, $u = false, $f = false) { return false; } }
if (!function_exists('safe_w_sql')) { function safe_w_sql($q) { return false; } }
if (!function_exists('safe_fetch')) { function safe_fetch($r) { return false; } }
if (!function_exists('StrSafe_DB')) { function StrSafe_DB($v) { return "'" . addslashes((string) $v) . "'"; } }

require_once __DIR__ . '/../lib/placement.php';

$VERBOSE = in_array('-v', $argv ?? array(), true);

/* ═══ Micro harness ════════════════════════════════════════════════════════════════════════════ */

final class CaseFailed extends Exception {}

$RESULTS = array('ok' => 0, 'ko' => 0);

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
            'maxPerClub' => $maxPerClub, 'minTargets' => 0, 'caps' => array(), 'fixed' => array(), 'archers' => array(),
            'bows' => array('BB' => 0, 'CL' => 1, 'CO' => 2));
    }

    /**
     * $n archers to place; $fam: one family, or one per distance. Options: club, div, cls, age,
     * sex (1 woman, 0 man), face (TfId), dist, wheelchair. Returns their ids.
     */
    public function add($n, $fam, $o = array())
    {
        $ids = array();
        for ($i = 0; $i < $n; $i++) {
            $id = ++$this->seq;
            $this->in['archers'][] = array('id' => $id, 'dist' => $o['dist'] ?? array(18, 18), 'fam' => $fam,
                'club' => $o['club'] ?? ('C' . $id), 'with' => null, 'letter' => '',
                'div' => $o['div'] ?? 'CL', 'cls' => $o['cls'] ?? 'S1H', 'age' => array_key_exists('age', $o) ? $o['age'] : 20,
                'sex' => $o['sex'] ?? 0, 'face' => $o['face'] ?? 0, 'wheelchair' => !empty($o['wheelchair']));
            $ids[] = $id;
        }
        return $ids;
    }

    /** An archer already placed (by hand or by another tool). */
    public function fixed($t, $l, $fam, $club = 'FIX', $dist = array(18, 18))
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

    /** Plans, and checks the invariants on the way. */
    public function run()
    {
        $res = bk_plan_session($this->in);
        check_valid($this, $res);
        return $res;
    }

    public function info()
    {
        $info = array();
        foreach (array_merge($this->in['archers'], $this->in['fixed']) as $a) $info[$a['id']] = bk_plan_norm($a) + array('raw' => $a);
        return $info;
    }

    /** [target => [letter => archer]], archers already placed included, targets in number order. */
    public function byTarget($res)
    {
        $info = $this->info();
        $out = array();
        foreach ($this->in['fixed'] as $f) $out[$f['t']][$f['l']] = $info[$f['id']];
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

    /** Target of each archer, in the order of the ids. */
    public function targetsOf($res, $ids)
    {
        return array_map(function ($id) use ($res) { return $res['places'][$id]['t'] ?? null; }, $ids);
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
        $dists = array_unique(array_map(function ($a) { return $a['dk']; }, $byLetter));
        truthy(count($dists) === 1, "target $t: several distances " . show(array_values($dists)));
        $nDist = count(reset($byLetter)['fams']);
        for ($d = 0; $d < $nDist; $d++) {
            $fams = array_map(function ($a) use ($d) { return $a['fams'][$d]; }, $byLetter);
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
            if (isset($a['raw']['t'])) continue;   // already placed: the setting does not move them
            if (!empty($caps['faces']) && $a['face'] > 0) truthy(in_array($a['face'], $caps['faces'], true), "target $t: face {$a['face']} refused by the field setting");
            foreach ($a['dist'] as $m) {
                if (!empty($caps['min'])) truthy($m >= $caps['min'], "target $t: distance below the field setting");
                if (!empty($caps['max'])) truthy($m <= $caps['max'], "target $t: distance above the field setting");
            }
        }
    }
}

/** Within one family of faces (targets of a single family), one archer of gap at most. */
function check_balanced(Plan $p, $res)
{
    $groups = array();
    foreach ($p->byTarget($res) as $t => $byLetter) {
        $keys = array_unique(array_map(function ($a) { return $a['dk'] . '/' . $a['fk']; }, $byLetter));
        if (count($keys) === 1) $groups[reset($keys)][$t] = count($byLetter);
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

/** Order of the archers along the targets: target number of each id given, never decreasing. */
function check_sequence(Plan $p, $res, $ids, $what)
{
    $prev = 0;
    foreach ($ids as $id) {
        $t = Plan::at($res, $id)['t'];
        truthy($t >= $prev, "$what: archer $id on target $t after target $prev");
        $prev = $t;
    }
}

/* ═══ Faces ═══════════════════════════════════════════════════════════════════════════════════ */

section('Family of a face (type and diameter of ianseo, not the name)');

foreach (array(
    // Indoor faces ianseo creates for the FFTA (Modules/Sets/FR/Setup_Target.php).
    array('Blason Unique 40cm', 1, 40, '40'),
    array('Blason Unique 60cm', 1, 60, '60'),
    array('Trispot Classique 6-10 40cm', 2, 40, 'tri'),
    array('Trispot Poulie 6-10 40cm', 4, 40, 'tri'),
    array('Trispot Classique 6-10 60cm', 2, 60, '60t'),
    array('Trispot Poulie 6-10 60cm', 4, 60, '60t'),
    array('Blason Découverte', 1, 80, '80'),
    // The ianseo type decides, not the name typed by the organiser.
    array('My face', 4, 40, 'tri'),
    array('Big', 1, 60, '60'),
) as $c) {
    test_case("indoors, \"{$c[0]}\" (type {$c[1]}, {$c[2]} cm) is a {$c[3]}", function () use ($c) {
        same($c[3], bk_face_family('salle', $c[0], $c[1], $c[2]));
    });
}
test_case('a face not recognised is unknown (alone on its target)', function () {
    same('?', bk_face_family('salle', '', 0, 0));
});
test_case('outdoors: full face, and the compound 80 (type 9) is reduced', function () {
    same('122|full', bk_face_family('ext', 'Blason 122', 5, 122));
    same('80|full', bk_face_family('ext', 'Blason Classique 80', 5, 80));
    same('80|reduit', bk_face_family('ext', 'Blason Compound', 9, 80));
    same('80|reduit', bk_face_family('ext', 'Blason réduit 80', 0, 0));
});
test_case('courses and the other disciplines: no rule on faces', function () {
    same('*', bk_face_family('campagne', 'Piquet Rouge', 6, 0));
});
test_case('indoor 25 + 18: a family per distance ("60cm/40cm")', function () {
    same(array('60', '60', '40', '40'), bk_face_families('salle', 'Blason Unique 60cm/40cm', array(array(1, 60), array(1, 60), array(1, 40), array(1, 40)), 4));
    same(array('tri', 'tri'), bk_face_families('salle', 'Trispot 40 cm', array(array(2, 40), array(0, 0)), 2));
});

section('Indoor buttress: left half A and C, right half B and D');

foreach (array(
    array('4 × 40', array('A' => '40', 'B' => '40', 'C' => '40', 'D' => '40'), true),
    array('a 60 shared by A-C and one by B-D', array('A' => '60', 'C' => '60', 'B' => '60', 'D' => '60'), true),
    array('60 left + 40 right', array('A' => '60', 'C' => '60', 'B' => '40', 'D' => '40'), true),
    array('a 60 in A beside a 40 in B', array('A' => '60', 'B' => '40'), true),
    array('a 60 in A beside a 40 in D', array('A' => '60', 'D' => '40'), true),
    array('a 60 in A and a 40 in C: never (one half)', array('A' => '60', 'C' => '40'), false),
    array('40 in A and triple in C: never', array('A' => '40', 'C' => 'tri'), false),
    array('60 left + 60 triple right', array('A' => '60', 'C' => '60', 'B' => '60t', 'D' => '60t'), true),
    array('60 in A and 60 triple in C: never', array('A' => '60', 'C' => '60t'), false),
    array('4 × 80 (the whole buttress)', array('A' => '80', 'B' => '80', 'C' => '80', 'D' => '80'), true),
    array('80 with a 40: never', array('A' => '80', 'B' => '40'), false),
    array('an unknown face with another archer: never', array('A' => '?', 'B' => '40'), false),
) as $c) {
    test_case($c[0], function () use ($c) { same($c[2], bk_target_layout('salle', 4, $c[1])['ok']); });
}
test_case('40 left + triple right: possible, reported', function () {
    $r = bk_target_layout('salle', 4, array('A' => '40', 'C' => '40', 'B' => 'tri', 'D' => 'tri'));
    truthy($r['ok'] && $r['mixed40tri'], 'possible and reported');
});
test_case('rhythm 3: left A and C, right B', function () {
    truthy(bk_target_layout('salle', 3, array('A' => '40', 'C' => '40', 'B' => '60'))['ok'], '40 left, 60 right');
    truthy(!bk_target_layout('salle', 3, array('A' => '40', 'C' => '60', 'B' => '60'))['ok'], 'two families left');
});
test_case('outdoors: a full face shared by 4; reduced 80 faces one per archer up to the rhythm; never both', function () {
    truthy(bk_target_layout('ext', 4, array('A' => '122|full', 'B' => '122|full', 'C' => '122|full', 'D' => '122|full'))['ok'], '4 × 122');
    truthy(bk_target_layout('ext', 4, array('A' => '80|reduit', 'B' => '80|reduit', 'C' => '80|reduit', 'D' => '80|reduit'))['ok'], '4 reduced at ABCD');
    truthy(bk_target_layout('ext', 3, array('A' => '80|reduit', 'B' => '80|reduit', 'C' => '80|reduit'))['ok'], '3 reduced at ABC');
    truthy(!bk_target_layout('ext', 4, array('A' => '80|reduit', 'B' => '122|full'))['ok'], 'reduced with full');
    truthy(!bk_target_layout('ext', 4, array('A' => '80|reduit', 'B' => '80|full'))['ok'], 'reduced with a full 80');
});

/* ═══ Planner ═════════════════════════════════════════════════════════════════════════════════ */

section('Balance');

test_case('6 triple faces make 3 + 3, not 4 + 2', function () {
    $p = new Plan(); $p->add(6, 'tri');
    same(array(3, 3), $p->counts($p->run()));
});
test_case('5 × 40 and 3 triple faces: 3 + 2 + 3, no 40 with a triple face', function () {
    $p = new Plan(); $p->add(5, '40'); $p->add(3, 'tri');
    $res = $p->run();
    same(array(3, 2, 3), $p->counts($res));
    same(0, $res['mixed40tri']);
});
test_case('5 × 60 and 7 × 40: 4 targets of 3, a 60 beside the 40s on the same target', function () {
    $p = new Plan(); $p->add(5, '60'); $p->add(7, '40');
    $res = $p->run();
    same(array(3, 3, 3, 3), $p->counts($res));
});
test_case('71 archers of 7 clubs (7 × 60, 23 × 40, 41 triple): balanced, quota kept, no 40 with a triple face', function () {
    $p = new Plan('salle', 30, 4, 2);
    $clubs = array('A', 'B', 'C', 'D', 'E', 'F', 'G');
    $i = 0;
    foreach (array('60' => 7, '40' => 23, 'tri' => 41) as $fam => $n) {
        for ($k = 0; $k < $n; $k++) $p->add(1, (string) $fam, array('club' => $clubs[$i++ % count($clubs)]));
    }
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(0, $res['overQuota']);
    same(0, $res['mixed40tri']);
    check_balanced($p, $res);
    check_quota($p, $res, 2);
});

section('Default order: distance, face, bow, age, sex — targets from target 1');

test_case('faces from the largest: 80, then 60, then 40, then triple', function () {
    $p = new Plan();
    $tri = $p->add(4, 'tri'); $f40 = $p->add(4, '40'); $f80 = $p->add(4, '80'); $f60 = $p->add(4, '60');
    $res = $p->run();
    same(array(1, 2, 3, 4), array(Plan::at($res, $f80[0])['t'], Plan::at($res, $f60[0])['t'], Plan::at($res, $f40[0])['t'], Plan::at($res, $tri[0])['t']));
});
test_case('at one size, the single face before the triple (60 before 60 triple, 40 before 40 triple)', function () {
    $p = new Plan();
    $t60 = $p->add(4, '60t'); $s60 = $p->add(4, '60'); $t40 = $p->add(4, 'tri'); $s40 = $p->add(4, '40');
    $res = $p->run();
    same(array(1, 2, 3, 4), array(Plan::at($res, $s60[0])['t'], Plan::at($res, $t60[0])['t'], Plan::at($res, $s40[0])['t'], Plan::at($res, $t40[0])['t']));
});
test_case('outdoors: 122, then 80, then the reduced 80', function () {
    $p = new Plan('ext', 10, 4, 4);
    $red = $p->add(3, '80|reduit', array('dist' => array(50, 50)));
    $f80 = $p->add(4, '80|full', array('dist' => array(50, 50)));
    $f122 = $p->add(4, '122|full', array('dist' => array(50, 50)));
    $res = $p->run();
    same(array(1, 2, 3), array(Plan::at($res, $f122[0])['t'], Plan::at($res, $f80[0])['t'], Plan::at($res, $red[0])['t']));
});
test_case('distances from the shortest, each on targets that follow each other', function () {
    $p = new Plan('ext', 10, 4, 4);
    $d70 = $p->add(4, '122|full', array('dist' => array(70, 70)));
    $d30 = $p->add(4, '80|full', array('dist' => array(30, 30)));
    $d50 = $p->add(4, '122|full', array('dist' => array(50, 50)));
    $res = $p->run();
    same(array(1, 2, 3), array(Plan::at($res, $d30[0])['t'], Plan::at($res, $d50[0])['t'], Plan::at($res, $d70[0])['t']));
});
test_case('on one face, the bows: barebow, recurve, compound', function () {
    $p = new Plan();
    $co = $p->add(4, 'tri', array('div' => 'CO')); $cl = $p->add(4, 'tri', array('div' => 'CL')); $bb = $p->add(4, 'tri', array('div' => 'BB'));
    $res = $p->run();
    same(array(1, 2, 3), array(Plan::at($res, $bb[0])['t'], Plan::at($res, $cl[0])['t'], Plan::at($res, $co[0])['t']));
});
test_case('then the age from the youngest, women before men', function () {
    $p = new Plan();
    $s2h = $p->add(2, 'tri', array('cls' => 'S2H', 'age' => 39, 'sex' => 0));
    $u18f = $p->add(2, 'tri', array('cls' => 'U18F', 'age' => 14, 'sex' => 1));
    $s2f = $p->add(2, 'tri', array('cls' => 'S2F', 'age' => 39, 'sex' => 1));
    $u18h = $p->add(2, 'tri', array('cls' => 'U18H', 'age' => 14, 'sex' => 0));
    $p->in['rhythm'] = 2;
    $res = $p->run();
    check_sequence($p, $res, array($u18f[0], $u18h[0], $s2f[0], $s2h[0]), 'U18F, U18H, S2F, S2H');
});
test_case('a class without an age range (discovery) comes after the others', function () {
    $p = new Plan(); $p->in['rhythm'] = 2;
    $dec = $p->add(2, 'tri', array('cls' => 'DEC', 'age' => null, 'sex' => -1));
    $s3 = $p->add(2, 'tri', array('cls' => 'S3H', 'age' => 59));
    $res = $p->run();
    check_sequence($p, $res, array($s3[0], $dec[0]), 'S3 then discovery');
});
test_case('any division: the other ones after barebow, recurve and compound', function () {
    $p = new Plan();
    $ad = $p->add(4, 'tri', array('div' => 'AD')); $co = $p->add(4, 'tri', array('div' => 'CO'));
    $res = $p->run();
    check_sequence($p, $res, array($co[0], $ad[0]), 'compound then the straight bow');
});

section('Order set by the organiser');

test_case('the order of the criteria: the age before the bow', function () {
    $p = new Plan(); $p->in['rhythm'] = 2;
    $s1co = $p->add(2, 'tri', array('div' => 'CO', 'age' => 20));
    $s2cl = $p->add(2, 'tri', array('div' => 'CL', 'age' => 39));
    $u15co = $p->add(2, 'tri', array('div' => 'CO', 'age' => 12));
    $p->in['order'] = array(array('dist', 1), array('face', 1), array('age', 1), array('div', 1), array('sex', 1));
    $res = $p->run();
    check_sequence($p, $res, array($u15co[0], $s1co[0], $s2cl[0]), 'U15 compound, S1 compound, S2 recurve');
});
test_case('the direction of each criterion: oldest first, men first, smallest face first, longest distance first', function () {
    $p = new Plan('salle', 10, 2, 4);
    $a = $p->add(2, '40', array('age' => 12, 'sex' => 1));
    $b = $p->add(2, '40', array('age' => 39, 'sex' => 1));
    $c = $p->add(2, '40', array('age' => 39, 'sex' => 0));
    $d = $p->add(2, '60', array('age' => 39, 'sex' => 0));
    $p->in['order'] = array(array('dist', -1), array('face', -1), array('div', 1), array('age', -1), array('sex', -1));
    $res = $p->run();
    check_sequence($p, $res, array($c[0], $b[0], $a[0], $d[0]), '40 S2 men, 40 S2 women, 40 U15, then the 60');
    $q = new Plan('ext', 10, 4, 4);
    $short = $q->add(4, '122|full', array('dist' => array(50, 50)));
    $long = $q->add(4, '122|full', array('dist' => array(70, 70)));
    $q->in['order'] = array(array('dist', -1));
    $res = $q->run();
    check_sequence($q, $res, array($long[0], $short[0]), '70 m before 50 m');
});
test_case('the order of the bows: compound first when the organiser says so', function () {
    $p = new Plan();
    $bb = $p->add(4, 'tri', array('div' => 'BB')); $co = $p->add(4, 'tri', array('div' => 'CO'));
    $p->in['bows'] = array('CO' => 0, 'CL' => 1, 'BB' => 2);
    $res = $p->run();
    check_sequence($p, $res, array($co[0], $bb[0]), 'compound then barebow');
});
test_case('a criterion unknown or given twice is ignored, the missing ones follow in the default order', function () {
    same(array(array('age', -1), array('dist', 1), array('face', 1), array('div', 1), array('sex', 1)),
        bk_plan_criteria(array(array('age', -1), array('nope', 1), array('age', 1))));
});
test_case('from the last target: the same plan, mirrored on the field', function () {
    $p = new Plan('salle', 10);
    $f80 = $p->add(4, '80'); $f40 = $p->add(4, '40');
    $p->in['reverse'] = true;
    $res = $p->run();
    same(array(10, 9), array(Plan::at($res, $f80[0])['t'], Plan::at($res, $f40[0])['t']));
});

section('Clubs (quota per target, alternated within a category, swaps that mix the least)');

test_case('within one category, the clubs alternate: 2 clubs of 4 on 2 targets, 2 + 2 each', function () {
    $p = new Plan('salle', 10, 4, 2);
    $p->add(4, 'tri', array('club' => 'A')); $p->add(4, 'tri', array('club' => 'B'));
    $res = $p->run();
    same(0, $res['overQuota']);
    check_quota($p, $res, 2);
});
test_case('a club too big for its category: swapped with the nearest archer, the least important criterion first', function () {
    $p = new Plan('salle', 10, 4, 2);
    // Women S1 of one club (3 on the first target), then men S1 of other clubs: the swap takes a
    // man S1 (only the sex differs), never the barebow archer further away.
    $p->add(3, 'tri', array('div' => 'CL', 'sex' => 1, 'club' => 'A'));
    $men = $p->add(5, 'tri', array('div' => 'CL', 'sex' => 0));
    $bb = $p->add(4, 'tri', array('div' => 'BB', 'club' => 'Z'));
    $res = $p->run();
    same(0, $res['overQuota']);
    check_quota($p, $res, 2);
    same(1, Plan::at($res, $bb[0])['t'], 'barebow stays first');
});
test_case('two archers of one club alone on a target: repaired', function () {
    $p = new Plan('salle', 10, 4, 2);
    $p->add(2, '80', array('club' => 'A')); $p->add(2, '80', array('club' => 'B')); $p->add(2, '80', array('club' => 'C'));
    $p->in['minTargets'] = 3;
    $res = $p->run();
    same(0, $res['sameClubPairs']);
});

section('Regulations: at least 6 targets with 2 archers or more, then complete');

test_case('12 archers: 6 targets of 2', function () {
    $p = new Plan(); $p->in['minTargets'] = 6; $p->add(12, '40');
    same(array(2, 2, 2, 2, 2, 2), $p->counts($p->run()));
});
test_case('13 archers: 6 targets, nobody alone', function () {
    $p = new Plan(); $p->in['minTargets'] = 6; $p->add(13, '40');
    $res = $p->run();
    same(6, count($p->counts($res)));
    same(0, $res['alone']);
});
test_case('30 archers: as many targets as the rhythm needs (beyond 6), balanced', function () {
    $p = new Plan(); $p->in['minTargets'] = 6; $p->add(30, '40');
    $res = $p->run();
    same(array(4, 4, 4, 4, 4, 4, 3, 3), $p->counts($res));
});
test_case('never a target of a single archer to reach the minimum: 5 archers stay on 2 targets', function () {
    $p = new Plan(); $p->in['minTargets'] = 6; $p->add(5, '40');
    same(array(3, 2), $p->counts($p->run()));
});

section('Field settings (hard): distances, exact faces');

test_case('a target reserved to compound triple faces takes them, never a recurve triple face', function () {
    $p = new Plan();
    $cl = $p->add(6, 'tri', array('div' => 'CL', 'face' => 6));
    $co = $p->add(4, 'tri', array('div' => 'CO', 'face' => 7));
    $p->in['caps'] = array(1 => array('faces' => array(7)), 2 => array('faces' => array(7)));
    $res = $p->run();
    foreach ($cl as $id) truthy(Plan::at($res, $id)['t'] > 2, "recurve $id on a compound target");
    truthy(Plan::at($res, $co[0])['t'] <= 2, 'the compounds use their reserved target');
});
test_case('both faces accepted when the organiser sets both', function () {
    $p = new Plan();
    $ids = array_merge($p->add(2, 'tri', array('div' => 'CL', 'face' => 6)), $p->add(2, 'tri', array('div' => 'CO', 'face' => 7)));
    $p->in['caps'] = array(1 => array('faces' => array(6, 7)));
    $res = $p->run();
    same(array(1, 1, 1, 1), $p->targetsOf($res, $ids));
});
test_case('distance ranges: 70 m only on the targets set for it', function () {
    $p = new Plan('ext', 6, 4, 4);
    $d70 = $p->add(4, '122|full', array('dist' => array(70, 70)));
    $p->add(4, '122|full', array('dist' => array(30, 30)));
    $p->in['caps'] = array(5 => array('min' => 60, 'max' => 90), 6 => array('min' => 60, 'max' => 90),
                           1 => array('max' => 50), 2 => array('max' => 50), 3 => array('max' => 50), 4 => array('max' => 50));
    $res = $p->run();
    truthy(Plan::at($res, $d70[0])['t'] >= 5, '70 m on target 5 or 6');
});
test_case('an archer no target accepts stays unplaced', function () {
    $p = new Plan('salle', 2);
    $id = $p->add(1, 'tri', array('face' => 6));
    $p->in['caps'] = array(1 => array('faces' => array(7)), 2 => array('faces' => array(7)));
    same($id, $p->run()['unplaced']);
});

section('Archers already placed, wishes, wheelchair');

test_case('an archer already placed never moves; their target takes others only when needed', function () {
    $p = new Plan('salle', 2);
    $p->fixed(1, 'A', '40');
    $ids = $p->add(7, '40');
    $res = $p->run();
    same(array(), $res['unplaced']);
    same(array(4, 4), $p->counts($res));
});
test_case('"with someone": the archer shoots on the target of the one they asked for', function () {
    $p = new Plan('salle', 10, 4, 2);
    $ids = $p->add(12, 'tri', array('club' => 'K'));
    foreach (array_slice($ids, 0, 10) as $i => $id) $p->in['archers'][$i]['club'] = 'K' . $i;
    $p->wish($ids[11], $ids[0]);
    $res = $p->run();
    same(Plan::at($res, $ids[0])['t'], Plan::at($res, $ids[11])['t']);
});
test_case('a letter wish is kept when nothing stands against it', function () {
    $p = new Plan();
    $ids = $p->add(4, 'tri');
    $p->wish($ids[2], null, 'D');
    same('D', Plan::at($p->run(), $ids[2])['l']);
});
test_case('no 4 archers on the target of a wheelchair archer', function () {
    $p = new Plan('salle', 3);
    $w = $p->add(1, '40', array('wheelchair' => true));
    $p->add(7, '40');
    $res = $p->run();
    same(0, $res['wheelchairFull']);
    $t = Plan::at($res, $w[0])['t'];
    truthy(count($p->byTarget($res)[$t]) <= 3, 'wheelchair target of 3 at most');
});

section('Indoor 25 + 18 and outdoors');

test_case('25 + 18: a 60/40 and a 40/40 never share a half', function () {
    $p = new Plan();
    $p->add(3, array('60', '60', '40', '40'), array('dist' => array(25, 25, 18, 18)));
    $p->add(3, array('40', '40', '40', '40'), array('dist' => array(25, 25, 18, 18)));
    same(array(), $p->run()['unplaced']);
});
test_case('outdoors: 8 reduced faces (50 m, first) on 2 targets, then 4 archers on each 122 (70 m)', function () {
    $p = new Plan('ext', 10, 4, 4);
    $p->add(8, '122|full', array('dist' => array(70, 70)));
    $p->add(8, '80|reduit', array('dist' => array(50, 50)));
    same(array(4, 4, 4, 4), $p->counts($p->run()));
});

section('Letters: fewer archers than letters on the outer letters');

test_case('2 archers at ABC: A and C, never B', function () {
    $p = new Plan('ext', 10, 3, 4);
    $ids = $p->add(4, '80|reduit', array('dist' => array(50, 50)));
    $res = $p->run();
    same(array(2, 2), $p->counts($res));
    foreach ($p->byTarget($res) as $t => $byLetter) same(array('A', 'C'), array_keys($byLetter), "target $t");
});
test_case('2 archers at ABCD: A and D; 3 archers: A, C, D', function () {
    $p = new Plan('ext', 10, 4, 4);
    $p->add(5, '122|full', array('dist' => array(70, 70)));
    $res = $p->run();
    same(array(array('A', 'C', 'D'), array('A', 'D')), array_values(array_map('array_keys', $p->byTarget($res))));
});
test_case('a letter wish still comes first', function () {
    $p = new Plan('ext', 1, 3, 4);
    $ids = $p->add(2, '80|reduit', array('dist' => array(50, 50)));
    $p->wish($ids[1], null, 'B');
    same('B', Plan::at($p->run(), $ids[1])['l']);
});

section('Places left (gauge of the registration)');

test_case('an empty indoor target takes 4 of one face; outdoors 4 on a 122, 4 reduced at ABCD, 3 at ABC', function () {
    $ctx = bk_plan_ctx(array('disc' => 'salle', 'rhythm' => 4, 'targets' => array(1)));
    same(4, bk_plan_room($ctx, array(), 1, array('id' => -1, 'dist' => array(18), 'fam' => '40', 'club' => '~')));
    $ext = bk_plan_ctx(array('disc' => 'ext', 'rhythm' => 4, 'targets' => array(1)));
    same(4, bk_plan_room($ext, array(), 1, array('id' => -1, 'dist' => array(70), 'fam' => '122|full', 'club' => '~')));
    same(4, bk_plan_room($ext, array(), 1, array('id' => -1, 'dist' => array(50), 'fam' => '80|reduit', 'club' => '~')));
    $abc = bk_plan_ctx(array('disc' => 'ext', 'rhythm' => 3, 'targets' => array(1)));
    same(3, bk_plan_room($abc, array(), 1, array('id' => -1, 'dist' => array(50), 'fam' => '80|reduit', 'club' => '~')));
});
test_case('a 60 on the left half leaves the right half: 2 places for 40, none for 80', function () {
    $p = new Plan('salle', 1);
    $p->fixed(1, 'A', '60');
    $p->fixed(1, 'C', '60');
    $ctx = bk_plan_ctx($p->in);
    $occ = bk_plan_occupants($ctx, array());
    same(2, bk_plan_room($ctx, $occ, 1, array('id' => -1, 'dist' => array(18, 18), 'fam' => '40', 'club' => '~')));
    same(0, bk_plan_room($ctx, $occ, 1, array('id' => -1, 'dist' => array(18, 18), 'fam' => '80', 'club' => '~')));
});
test_case('a target reserved to another face offers no place', function () {
    $ctx = bk_plan_ctx(array('disc' => 'salle', 'rhythm' => 4, 'targets' => array(1), 'caps' => array(1 => array('faces' => array(7)))));
    same(0, bk_plan_room($ctx, array(), 1, array('id' => -1, 'dist' => array(18), 'fam' => 'tri', 'face' => 6, 'club' => '~')));
});

section('A large departure');

test_case('400 archers on a departure of 999 targets: placed in well under a second', function () {
    $p = new Plan('salle', 999, 4, 2);
    $p->in['minTargets'] = 6;
    $fams = array('80', '60', '40', 'tri');
    $divs = array('BB', 'CL', 'CO');
    for ($i = 0; $i < 400; $i++) {
        $p->add(1, $fams[$i % 4], array('div' => $divs[$i % 3], 'club' => 'K' . ($i % 37), 'age' => 10 + $i % 50, 'sex' => $i % 2, 'cls' => 'c' . ($i % 7)));
    }
    $t0 = microtime(true);
    $res = $p->run();
    $s = microtime(true) - $t0;
    same(array(), $res['unplaced']);
    same(0, $res['overQuota']);
    truthy($s < 1.5, sprintf('%.2f s', $s));
});

/* ═══ Summary ═════════════════════════════════════════════════════════════════════════════════ */

$total = $RESULTS['ok'] + $RESULTS['ko'];
echo "\nTarget placement: {$RESULTS['ok']} / $total cases passed" . ($RESULTS['ko'] ? ", {$RESULTS['ko']} FAILED" : '') . ".\n";
exit($RESULTS['ko'] ? 1 : 0);
