<?php
/**
 * Every page of every module, in every language it offers, served by a real web server.
 *
 *     php bin/pages.php            every module of tests/modules/
 *     php bin/pages.php AUTH GUIDE
 *
 * The ianseo of the run (bin/core.php) gets a database built from install.sql, the
 * tables of every module, and one made-up competition created through ianseo's own
 * "new competition" form, with made-up archers. Then, in a session that keeps its
 * cookies like a browser:
 *   - "pages" of tests/modules/<MODULE>.json are opened without a competition,
 *   - "tour_pages" after opening the made-up competition (Common/TourOn.php),
 * each with ?Lang= for every language of "languages".
 *
 * A page fails when it does not answer 200, sends the visitor elsewhere (a redirect
 * is never followed: a page that bounces to the home page used to look fine), shows a
 * translation key instead of its text ([[Key]@…]), or raises a PHP warning, notice or
 * fatal error in a file of a module. Warnings of the core are counted, not failed.
 *
 * Then the browser-side checks of dom/<MODULE>.js run against the same server.
 */
require_once __DIR__ . '/../lib/bench.php';

const PAGES_DB   = BENCH_PREFIX . 'pages';
const PAGES_CODE = 'BENCHTST';   // ToCode holds 8 characters

$wanted = array_slice($argv, 1);
$stamp  = bench_prepare_run();
bench_say("ianseo {$stamp['version']} (updated {$stamp['day']} from " . BENCH_PACKAGE . ')');

bench_reference_db(PAGES_DB);
$confs = bench_module_confs();
foreach ($confs as $mod => $conf) {
    if (empty($conf['schema']['call']) || !is_dir(BENCH_HTDOCS . '/Modules/Custom/' . $mod)) continue;
    $r = bench_child('child-schema.php', BENCH_HTDOCS, PAGES_DB, [$mod]);
    if (!$r['ok']) bench_die("schema of $mod failed:\n" . $r['out']);
}
// ianseo leaves PHP errors hidden unless its debug mode is on (config.php).
bench_q("REPLACE INTO `" . PAGES_DB . "`.Parameters (ParId, ParValue) VALUES ('DEBUG', '1')");

// The web server: PHP's own, several workers so that a page calling another one works.
$port = (int)(getenv('BENCH_PORT') ?: 8090);
$base = "http://127.0.0.1:$port";
$log  = sys_get_temp_dir() . '/bench-php-errors.log';
@unlink($log);
$env  = getenv();
$env['BENCH_DB']                = PAGES_DB;
$env['BENCH_HTDOCS']            = BENCH_HTDOCS;
$env['PHP_CLI_SERVER_WORKERS']  = '4';
// An argument list, not a command line: no shell in between, so stopping the process
// stops the server itself.
// -d values are read as ini lines: quoted, or a "~" in a Windows temporary path cuts it.
$cmd  = [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log="' . $log . '"', '-d', 'display_errors=0',
         '-S', '127.0.0.1:' . $port, '-t', BENCH_HTDOCS];
$out  = sys_get_temp_dir() . '/bench-server.out';
$server = proc_open($cmd, [1 => ['file', $out, 'a'], 2 => ['file', $out, 'a']], $pipes, BENCH_HTDOCS, $env);
if (!is_resource($server)) bench_die('the web server could not start');
register_shutdown_function(function () use ($server) {
    if (is_resource($server)) proc_terminate($server);
});
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($sock) { fclose($sock); break; }
    usleep(200000);
}
if (empty($sock)) bench_die('the web server does not answer on port ' . $port);

// The made-up competition, through ianseo's own form.
$ch = pages_session();
$toId = pages_competition($ch, $base);
bench_say('made-up competition ' . PAGES_CODE . " created through the core form (ToId $toId)");

// Made-up data of the modules that need some to show their pages (tests/fixtures/).
putenv("BENCH_TOUR=$toId");
foreach (glob(dirname(__DIR__) . '/fixtures/*.php') as $fixture) {
    $mod = basename($fixture, '.php');
    if (!is_dir(BENCH_HTDOCS . '/Modules/Custom/' . $mod)) continue;
    $r = bench_child('child-fixture.php', BENCH_HTDOCS, PAGES_DB, [$mod]);
    if (!$r['ok']) bench_die("made-up data of $mod failed:\n" . $r['out']);
    bench_say("made-up data of $mod loaded");
}

$failed = [];
foreach ($confs as $mod => $conf) {
    if ($wanted && !in_array($mod, $wanted, true)) continue;
    if (empty($conf['pages']) && empty($conf['tour_pages'])) continue;
    if (!is_dir(BENCH_HTDOCS . '/Modules/Custom/' . $mod)) continue;
    bench_say("\n== $mod");
    $languages = $conf['languages'] ?? ['en'];
    $bad = 0;

    foreach (['pages' => 0, 'tour_pages' => $toId] as $key => $tour) {
        if (empty($conf[$key])) continue;
        $ch = pages_session();
        if ($tour) pages_get($ch, $base . '/Common/TourOn.php?ToId=' . $tour . '&BackTo=' . rawurlencode('/index.php'), $log);
        foreach ($conf[$key] as $page) {
            $line = ($tour ? '  [in competition] ' : '  ') . $page;
            $problems = [];
            foreach ($languages as $lang) {
                $url = $base . $page . (strpos($page, '?') === false ? '?' : '&') . 'Lang=' . $lang;
                $r   = pages_get($ch, $url, $log);
                if ($r['status'] !== 200) $problems[] = "$lang: HTTP {$r['status']}";
                if ($r['location'] !== '') $problems[] = "$lang: sent to " . (parse_url($r['location'], PHP_URL_PATH) ?: $r['location']);
                if (preg_match_all('/\[\[([^\]]+)\]@/', $r['body'], $m)) $problems[] = "$lang: missing key(s) " . implode(', ', array_unique($m[1]));
                foreach ($r['module_errors'] as $e) $problems[] = "$lang: $e";
            }
            if ($problems) {
                $bad++;
                bench_say("  FAIL" . substr($line, 1));
                foreach (array_unique($problems) as $p) bench_say("        $p");
            } else {
                bench_say("  ok  " . substr($line, 1) . ' — ' . implode(' ', $languages));
            }
        }
    }
    if ($bad) $failed[] = $mod;
}

// Browser-side checks: what only exists once the page's own script has run (jsdom).
foreach (glob(dirname(__DIR__) . '/dom/*.js') as $scenario) {
    $mod = basename($scenario, '.js');
    if ($mod === 'run' || ($wanted && !in_array($mod, $wanted, true))) continue;
    if (!is_dir(BENCH_HTDOCS . '/Modules/Custom/' . $mod)) continue;
    bench_say("\n== $mod (browser)");
    $out = [];
    exec('node ' . escapeshellarg(dirname(__DIR__) . '/dom/run.js') . ' ' . escapeshellarg($mod) . ' ' . escapeshellarg($base) . ' 2>&1', $out, $code);
    foreach ($out as $l) if (trim($l) !== '') bench_say('  ' . $l);
    if ($code !== 0 && !in_array($mod, $failed, true)) $failed[] = $mod;
}

$core = pages_core_errors($log);
if ($core) bench_say("\nnote: $core PHP warning(s) raised by the core itself (not counted)");
bench_drop(PAGES_DB);
bench_say("\n" . ($failed ? 'FAILED: ' . implode(', ', $failed) : 'All pages passed.'));
exit($failed ? 1 : 0);

/**
 * @return resource|CurlHandle A session of its own: cookies in memory, redirects not followed.
 */
function pages_session()
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_TIMEOUT        => 120,
    ]);
    return $ch;
}

/**
 * One request, and the PHP errors it raised in module files.
 *
 * @param resource|CurlHandle $ch
 * @param string $url
 * @param string $log
 * @param array|null $post
 * @return array ['status', 'body', 'location', 'module_errors']
 */
function pages_get($ch, $url, $log, $post = null)
{
    clearstatcache();
    $from = is_file($log) ? filesize($log) : 0;
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $body = curl_exec($ch);
    usleep(50000);   // let the worker finish writing to the log
    clearstatcache();
    $new  = is_file($log) && filesize($log) > $from ? (string)file_get_contents($log, false, null, $from) : '';
    $errors = [];
    foreach (explode("\n", str_replace('\\', '/', $new)) as $l) {
        if (strpos($l, '/Modules/Custom/') === false) continue;
        if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Recoverable fatal error):\s*(.+)$/', $l, $m)) {
            $errors[] = $m[1] . ': ' . preg_replace('#\S*/(Modules/Custom/)#', '$1', trim($m[2]));
        }
    }
    return [
        'status'        => $body === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body'          => (string)$body,
        'location'      => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        'module_errors' => array_values(array_unique($errors)),
    ];
}

/**
 * @param string $log
 * @return int Warnings, notices and errors raised outside the modules.
 */
function pages_core_errors($log)
{
    $n = 0;
    foreach (explode("\n", str_replace('\\', '/', (string)@file_get_contents($log))) as $l) {
        if (strpos($l, '/Modules/Custom/') === false && preg_match('/PHP (Warning|Notice|Fatal error)/', $l)) $n++;
    }
    return $n;
}

/**
 * Create the made-up competition the way an organiser does, through Tournament/index.php
 * (French rules, 18 m indoor), then add made-up archers.
 *
 * @param resource|CurlHandle $ch
 * @param string $base
 * @return int ToId
 */
function pages_competition($ch, $base)
{
    global $log;
    pages_get($ch, $base . '/Tournament/index.php?New=', $log);
    $from = strtotime('+30 days');
    $to   = $from + 86400;
    $resp = pages_get($ch, $base . '/Tournament/index.php', $log, [
        'Command'            => 'SAVE',
        'New'                => '',
        'd_ToType'           => '6',
        'd_Rule'             => 'FR',
        'd_SubRule'          => '1',
        'd_ToCode'           => PAGES_CODE,
        'd_ToName'           => 'Competition fictive du banc de test',
        'd_ToNameShort'      => 'Banc de test',
        'd_ToIocCode'        => 'FRA',
        'd_ToCountry'        => 'FRA',
        'd_ToCommitee'       => '0999000',
        'd_ToComDescr'       => 'Club fictif',
        'd_ToWhere'          => 'Gymnase fictif',
        'd_ToVenue'          => 'Ville fictive',
        'd_ToTimeZone'       => '+01:00',
        'xx_ToWhenFromYear'  => date('Y', $from),
        'xx_ToWhenFromMonth' => date('m', $from),
        'xx_ToWhenFromDay'   => date('d', $from),
        'xx_ToWhenToYear'    => date('Y', $to),
        'xx_ToWhenToMonth'   => date('m', $to),
        'xx_ToWhenToDay'     => date('d', $to),
        'xx_ToCurrency'      => 'EUR',
        'xx_ToPrintLang'     => 'fr',
        'xx_ToPaperSize'     => '0',
        'xx_ToUseHHT'        => '0',
    ]);
    $db  = PAGES_DB;
    $row = bench_q("SELECT ToId FROM `$db`.Tournament WHERE ToCode = '" . PAGES_CODE . "'")->fetch_row();
    if (!$row) {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)\b.*?</\1>#si', '', $resp['body']))));
        bench_die('the made-up competition could not be created through Tournament/index.php '
            . "(HTTP {$resp['status']}" . ($resp['location'] ? ", sent to {$resp['location']}" : '') . "):\n"
            . substr($text, 0, 1500) . "\n" . substr((string)@file_get_contents($log), -3000));
    }
    $toId = (int)$row[0];

    // Made-up archers: names and numbers that cannot be anyone's.
    $div = bench_q("SELECT DivId FROM `$db`.Divisions WHERE DivTournament = $toId AND DivAthlete = 1 ORDER BY DivViewOrder LIMIT 1")->fetch_row();
    $cls = [];
    $r   = bench_q("SELECT ClId, ClSex FROM `$db`.Classes WHERE ClTournament = $toId AND ClAthlete = 1 ORDER BY ClViewOrder");
    while ($c = $r->fetch_row()) $cls[(int)$c[1]][] = $c[0];
    if (!$div || empty($cls[0]) || empty($cls[1])) bench_die('the French rules did not create divisions and classes');
    bench_q("INSERT INTO `$db`.Countries (CoTournament, CoCode, CoName, CoNameComplete) VALUES ($toId, '0999001', 'CLUB FICTIF A', 'Club fictif A'), ($toId, '0999002', 'CLUB FICTIF B', 'Club fictif B')");
    $clubs = [];
    $r = bench_q("SELECT CoId FROM `$db`.Countries WHERE CoTournament = $toId ORDER BY CoCode");
    while ($c = $r->fetch_row()) $clubs[] = (int)$c[0];
    for ($i = 1; $i <= 8; $i++) {
        $sex  = $i % 2;
        $cl   = $cls[$sex][min(count($cls[$sex]) - 1, 4)];
        $club = $clubs[$i % 2];
        bench_q("INSERT INTO `$db`.Entries (EnTournament, EnDivision, EnClass, EnAgeClass, EnCountry, EnCode, EnName, EnFirstName, EnSex, EnAthlete, EnStatus, EnIndClEvent, EnIndFEvent, EnIocCode)
                 VALUES ($toId, '{$div[0]}', '$cl', '$cl', $club, 'BENCH0$i', 'Testeur$i', 'FICTIF', $sex, 1, 0, 1, 1, 'FRA')");
        bench_q("INSERT INTO `$db`.Qualifications (QuId, QuSession) VALUES (" . (int)bench_db()->insert_id . ", 1)");
    }
    return $toId;
}
