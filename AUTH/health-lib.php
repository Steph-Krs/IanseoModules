<?php
/**
 * AUTH module — server health, shown to the administrator in admin/config.php.
 *
 * Each check turns a lesson from a production ianseo server (September 2026: adding an
 * archer took up to 10 minutes, phones blocked during score entry, services restarted in
 * the middle of competitions) into something visible here, without SSH.
 *
 * Read-only and cheap. Never fatal: every query passes $force, every system file is read
 * optionally (open_basedir, permissions), and a check that cannot conclude is left out
 * rather than guessed.
 */

/** Places per departure above which a departure is flagged (same value as booking's BK_BIG_SESSION_PLACES). */
if (!defined('AUT_BIG_SESSION_PLACES')) define('AUT_BIG_SESSION_PLACES', 5000);

/** One result: level ok|warn|info, title, plain text, optional command, optional list. */
function aut_health_item($level, $title, $text, $fix = '', $list = array())
{
    return array('level' => $level, 'title' => $title, 'text' => $text, 'fix' => $fix, 'list' => $list);
}

/** A global MySQL/MariaDB variable, or null. */
function aut_health_var($name)
{
    $rs = safe_r_sql('SELECT @@GLOBAL.' . preg_replace('/[^a-z_]/', '', $name) . ' AS v', false, true);
    $r = $rs ? safe_fetch($rs) : null;
    return $r ? $r->v : null;
}

/** A global status counter, or null. */
function aut_health_status($name)
{
    $rs = safe_r_sql('SHOW GLOBAL STATUS LIKE ' . StrSafe_DB($name), false, true);
    $r = $rs ? safe_fetch($rs) : null;
    return $r ? $r->Value : null;
}

/** php.ini size ("64M", "1G", "512K", "0") → bytes. */
function aut_health_bytes($v)
{
    if (!preg_match('/^\s*(\d+)\s*([KkMmGg]?)/', (string) $v, $m)) return 0;
    $shift = array('' => 0, 'K' => 10, 'k' => 10, 'M' => 20, 'm' => 20, 'G' => 30, 'g' => 30);
    return (int) $m[1] << $shift[$m[2]];
}

/** Optional read of a system file: '' when absent or not readable (open_basedir…). */
function aut_health_read($path)
{
    $s = @file_get_contents($path);
    return is_string($s) ? $s : '';
}

/**
 * How many PHP processes can run at once: [count, where it was read], or null if unknown.
 * mod_php: Apache's MaxRequestWorkers (prefork); PHP-FPM: pm.max_children of the pool.
 */
function aut_health_php_workers()
{
    $sapi = php_sapi_name();
    if ($sapi === 'apache2handler') {
        $f = '/etc/apache2/mods-enabled/mpm_prefork.conf';
        if (preg_match('/^\s*MaxRequestWorkers\s+(\d+)/mi', aut_health_read($f), $m)) {
            return array((int) $m[1], 'MaxRequestWorkers, ' . $f);
        }
    } elseif ($sapi === 'fpm-fcgi') {
        $f = '/etc/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/fpm/pool.d/www.conf';
        if (preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/mi', aut_health_read($f), $m)) {
            return array((int) $m[1], 'pm.max_children, ' . $f);
        }
    }
    return null;
}

/**
 * Program files replaced on disk since this web process started (full paths): an update is
 * installed but this process still runs the old copy — Apache keeping the previous PHP in
 * memory. Read from the process's own memory map, which needs no right. null when it cannot be
 * read (not Linux, open_basedir). $maps: the content to parse instead (tests).
 */
function aut_health_stale_files($maps = null)
{
    if ($maps === null) {
        if (PHP_OS_FAMILY !== 'Linux') return null;
        $maps = aut_health_read('/proc/self/maps');
        if ($maps === '') return null;
    }
    $files = array();
    // Only program files: "/dev/zero (deleted)" is shared memory, not an update.
    if (preg_match_all('#\s(/(?:usr|lib|lib64|opt)/\S+) \(deleted\)$#m', $maps, $m)) {
        foreach ($m[1] as $f) $files[$f] = true;
    }
    return array_keys($files);
}

/** Debian version comparison, as dpkg --compare-versions: <0, 0 or >0. */
function aut_health_dpkg_cmp($a, $b)
{
    $parse = function ($v) {
        preg_match('/^(?:(\d+):)?(.+?)(?:-([^-]+))?$/', trim((string) $v), $m);
        return array((int) ($m[1] ?? 0), $m[2] ?? '', $m[3] ?? '');
    };
    $x = $parse($a);
    $y = $parse($b);
    if ($x[0] !== $y[0]) return $x[0] <=> $y[0];
    $c = aut_health_verrevcmp($x[1], $y[1]);
    return $c !== 0 ? $c : aut_health_verrevcmp($x[2], $y[2]);
}

/** dpkg's verrevcmp(): letters before other signs, "~" before everything, even the end. */
function aut_health_verrevcmp($a, $b)
{
    $a = preg_split('//', (string) $a, -1, PREG_SPLIT_NO_EMPTY);
    $b = preg_split('//', (string) $b, -1, PREG_SPLIT_NO_EMPTY);
    $digit = function ($c) { return $c !== null && ctype_digit($c); };
    $order = function ($c) use ($digit) {
        if ($c === null || $digit($c)) return 0;
        if (ctype_alpha($c)) return ord($c);
        return $c === '~' ? -1 : ord($c) + 256;
    };
    $i = $j = 0;
    while (isset($a[$i]) || isset($b[$j])) {
        while ((isset($a[$i]) && !$digit($a[$i])) || (isset($b[$j]) && !$digit($b[$j]))) {
            $ac = $order($a[$i] ?? null);
            $bc = $order($b[$j] ?? null);
            if ($ac !== $bc) return $ac <=> $bc;
            $i++;
            $j++;
        }
        while (($a[$i] ?? null) === '0') $i++;
        while (($b[$j] ?? null) === '0') $j++;
        $first = 0;
        while ($digit($a[$i] ?? null) && $digit($b[$j] ?? null)) {
            if ($first === 0) $first = (int) $a[$i] <=> (int) $b[$j];
            $i++;
            $j++;
        }
        if ($digit($a[$i] ?? null)) return 1;
        if ($digit($b[$j] ?? null)) return -1;
        if ($first !== 0) return $first;
    }
    return 0;
}

/**
 * Where PHP comes from on this server: distribution ("debian12", "ubuntu24.04", '' unknown),
 * the package holding the PHP of this web process and its installed version ('' when PHP was
 * not installed by dpkg: Windows, RPM, compiled by hand). $root: a folder standing for "/" (tests).
 */
function aut_health_php_origin($root = '', $sapi = null, $branch = null)
{
    $sapi   = $sapi ?? php_sapi_name();
    $branch = $branch ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $os = aut_health_read($root . '/etc/os-release');
    $distro = '';
    if (preg_match('/^ID="?([a-z0-9._-]+)"?\s*$/mi', $os, $m)) {
        $distro = mb_strtolower($m[1]) .(preg_match('/^VERSION_ID="?([0-9.]+)"?\s*$/mi', $os, $v) ? $v[1] : '');
    }
    $status = aut_health_read($root . '/var/lib/dpkg/status');
    $names = array('apache2handler' => "libapache2-mod-php$branch", 'fpm-fcgi' => "php$branch-fpm", 'cgi-fcgi' => "php$branch-cgi");
    $pkg = '';
    $ver = '';
    foreach (array_unique(array($names[$sapi] ?? "php$branch-cli", "php$branch-common")) as $p) {
        $at = strpos($status, "Package: $p\n");
        if ($at !== false && preg_match('/\GPackage: \S+\nStatus: install ok installed\n(?:.+\n)*?Version: (\S+)$/m', $status, $m, 0, $at)) {
            $pkg = $p;
            $ver = $m[1];
            break;
        }
    }
    return array('distro' => $distro, 'pkg' => $pkg, 'pkgver' => $ver);
}

/**
 * The known PHP flaws of security-flaws.json, each with its state on this server:
 * fixed — the PHP in service has the fix; reload — the fix is installed but the web server still
 * runs the previous PHP; open — not fixed; eol — PHP branch that upstream no longer fixes;
 * unknown — a distribution package whose fix (backported under the same upstream number) is not
 * in the list yet. 'mitigated' / 'missing': the php.ini settings of the workaround in place or not.
 * $env (tests): php, branch, distro, pkg, pkgver, stale, ini => [name => value]. null without list.
 */
function aut_health_php_flaws($env = null, $list = null)
{
    if ($list === null) {
        $list = json_decode((string) @file_get_contents(__DIR__ . '/security-flaws.json'), true);
        $list = is_array($list) && isset($list['php']) ? $list['php'] : null;
        if ($list === null) return null;
    }
    if ($env === null) {
        $stale = aut_health_stale_files();
        $env = aut_health_php_origin() + array(
            'php'    => PHP_VERSION,
            'branch' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'stale'  => $stale && preg_grep('#/(libphp[^/]*|php-fpm[^/]*)$|/lib/php/#', $stale),
            'ini'    => null,
        );
    }
    // Distributions that backport fixes under the PHP number they shipped with.
    $backports = (bool) preg_match('/^(debian|ubuntu|rhel|centos|rocky|almalinux|ol|amzn|sles|opensuse)/', $env['distro']);
    $upstream  = preg_replace('/^\d+:|-[^-]*$/', '', $env['pkgver']);
    $out = array();
    foreach ($list as $f) {
        $branches = array_keys($f['fixed']);
        usort($branches, 'version_compare');
        $fix = $f['fixed'][$env['branch']] ?? '';
        $old = $fix === '' && version_compare($env['branch'], $branches[0], '<');
        if ($fix === '' && !$old) {
            $state = 'fixed';   // a branch released after the fix
        } elseif (!$old && version_compare($env['php'], $fix, '>=')) {
            $state = 'fixed';
        } elseif ($env['pkgver'] !== '') {
            $distroFix = $f['distro'][$env['distro']] ?? '';
            if (!$old && version_compare($upstream, $fix, '>=')) {
                $state = 'fixed';
            } elseif ($distroFix !== '') {
                $state = aut_health_dpkg_cmp($env['pkgver'], $distroFix) >= 0 ? 'fixed' : 'open';
            } else {
                $state = $backports ? 'unknown' : ($old ? 'eol' : 'open');
            }
            if ($state === 'fixed' && $env['stale']) $state = 'reload';
        } else {
            // A distribution PHP not read from dpkg (RPM): its number does not tell the fixes.
            $state = $backports ? 'unknown' : ($old ? 'eol' : 'open');
        }
        $f['state'] = $state;
        $f['fix'] = $fix;
        $f['mitigated'] = $f['missing'] = array();
        foreach ($f['mitigate'] ?? array() as $w) {
            $val = $env['ini'] !== null ? ($env['ini'][$w['ini']] ?? '') : ini_get($w['ini']);
            $off = !filter_var($val, FILTER_VALIDATE_BOOLEAN);
            if ($off === !empty($w['off'])) $f['mitigated'][] = $w['ini'];
            else $f['missing'][] = $w['ini'] . ' = ' . (!empty($w['off']) ? '0' : '1');
        }
        $out[] = $f;
    }
    return array('env' => $env, 'flaws' => $out);
}

/** The "known PHP flaws" line of the server state: one line however many flaws are known. */
function aut_health_flaws_item($sec, $ini)
{
    $env  = $sec['env'];
    $list = array();
    $warn = false;
    $update = false;
    $restart = false;
    $set = array();
    foreach ($sec['flaws'] as $f) {
        if ($f['state'] === 'fixed') continue;
        $refs = (array) $f['ref'];
        $cve = count($refs) >= $f['count'] ? implode(', ', $refs) : aut_t('HlSecMore', array('ref' => $refs[0], 'n' => $f['count'] - 1));
        if ($f['state'] === 'reload') {
            $txt = aut_t('HlSecReload');
            $restart = true;
            $warn = true;
        } else {
            $txt = $f['state'] === 'unknown'
                ? ($env['pkg'] !== '' ? aut_t('HlSecUnknown', array('pkg' => $env['pkg'], 'ver' => $env['pkgver'])) : aut_t('HlSecUnknownRpm', $env['distro']))
                : ($f['state'] === 'eol' ? aut_t('HlSecEol', $env['branch']) : aut_t('HlSecOpen', $f['fix']));
            if ($f['mitigated'] && !$f['missing']) {
                $txt .= aut_t('HlSecMitigated', implode(', ', $f['mitigated']));
            } else {
                $warn = true;
            }
            foreach ($f['missing'] as $m) $set[$m] = true;
            $update = true;
        }
        $list[] = aut_t('HlSecLine', array('name' => $f['name'], 'cve' => $cve, 'date' => $f['date'], 'state' => $txt));
    }
    $running = 'PHP ' . $env['php'] . ($env['pkgver'] !== '' ? ' (' . $env['pkg'] . ' ' . $env['pkgver'] . ')' : '');
    if (!$list) {
        return aut_health_item('ok', aut_t('HlSecT'), aut_t('HlSecOk', array('n' => count($sec['flaws']), 'php' => $running)));
    }
    $cmd = array();
    if ($set) {
        $scanned = (string) php_ini_scanned_files();
        $dir = $scanned !== '' ? dirname(trim(explode(',', $scanned)[0])) : '';
        $cmd[] = $dir !== '' && $env['pkgver'] !== ''
            ? "printf '%s\\n' '" . implode("' '", array_keys($set)) . "' | sudo tee -a " . $dir . '/99-ianseo-security.ini   # ' . aut_t('HlSecCmdMitigate')
            : implode("\n", array_keys($set)) . "\n" . aut_t('HlRestartApache', $ini);
        $restart = true;
    }
    if ($update) {
        $cmd[] = $env['pkgver'] !== '' ? 'sudo apt update && sudo apt upgrade   # ' . aut_t('HlSecCmdUpdate') : aut_t('HlSecCmdManual');
        $restart = true;
    }
    if ($restart && $env['pkgver'] !== '') {
        $cmd[] = (php_sapi_name() === 'fpm-fcgi'
            ? 'sudo systemctl restart php' . $env['branch'] . '-fpm'
            : 'sudo systemctl restart apache2') . '   # ' . aut_t('HlSecCmdRestart');
    }
    return aut_health_item($warn ? 'warn' : 'info', aut_t('HlSecT'),
        aut_t($warn ? 'HlSecWarn' : 'HlSecInfo', $running), implode("\n", $cmd), $list);
}

/**
 * The system files of the module's templates (serveur/README.md): where each one goes, how it is
 * installed, whether the server cannot do without it, and whether it is copied as it is — then
 * its content can be compared with this version of the template. Files adapted by hand (vhost,
 * MySQL settings, jail) are only looked for. Not listed: sudoers.d, unreadable by the web server.
 */
function aut_health_template_list()
{
    $bin = function ($n, $need = true) {
        return array('src' => "bin/$n", 'dest' => "/usr/local/bin/$n", 'need' => $need, 'same' => false, 'mode' => '0750');
    };
    $file = function ($src, $dest, $need, $same = true) {
        return array('src' => $src, 'dest' => $dest, 'need' => $need, 'same' => $same, 'mode' => '0644');
    };
    return array(
        $bin('ianseo-lock'), $bin('ianseo-unlock'), $bin('ianseo-maintenance-on'), $bin('ianseo-maintenance-off'),
        $bin('ianseo-restart-services'), $bin('ianseo-restore', false),
        $file('apache/maintenance.html', '/var/www/maintenance/index.html', true),
        $file('cron/ianseo-nightly', '/etc/cron.d/ianseo-nightly', true),
        $file('cron/ianseo-restart', '/etc/cron.d/ianseo-restart', true),
        $file('cron/ianseo-backup-live', '/etc/cron.d/ianseo-backup-live', false),
        $file('cron/ianseo-licences', '/etc/cron.d/ianseo-licences', false),
        $file('cron/ianseo-waitlist', '/etc/cron.d/ianseo-waitlist', false),
        $file('cron/ianseo-trust', '/etc/cron.d/ianseo-trust', false),
        $file('logrotate/ianseo', '/etc/logrotate.d/ianseo', true),
        $file('apt/apt-daily.timer.conf', '/etc/systemd/system/apt-daily.timer.d/ianseo.conf', true),
        $file('apt/apt-daily-upgrade.timer.conf', '/etc/systemd/system/apt-daily-upgrade.timer.d/ianseo.conf', true),
        $file('apt/51ianseo-auto-reboot', '/etc/apt/apt.conf.d/51ianseo-auto-reboot', false),
        $file('needrestart/ianseo.conf', '/etc/needrestart/conf.d/ianseo.conf', true),
        $file('fail2ban/filter-ianseo-auth.conf', '/etc/fail2ban/filter.d/ianseo-auth.conf', false),
        $file('fail2ban/jail-ianseo.conf', '/etc/fail2ban/jail.d/ianseo.conf', false, false),
        $file('mysql/ianseo.cnf', '/etc/mysql/mariadb.conf.d/99-ianseo.cnf|/etc/mysql/mysql.conf.d/99-ianseo.cnf', false, false),
    );
}

/**
 * State of each template on this server: [entry + 'state' => ok|missing|differs, 'found' => path].
 * $root: a folder standing for "/" (tests). null when not on Linux.
 */
function aut_health_templates($root = '')
{
    if (PHP_OS_FAMILY !== 'Linux' && $root === '') return null;
    $tpl = __DIR__ . '/serveur/';
    $out = array();
    foreach (aut_health_template_list() as $t) {
        $t['found'] = '';
        $t['old'] = array();
        $t['unrotated'] = array();
        foreach (explode('|', $t['dest']) as $d) {
            if (is_file($root . $d)) { $t['found'] = $d; break; }
        }
        if ($t['found'] === '' && $t['src'] === 'logrotate/ianseo') {
            // Installations older than the single template have one file per log
            // (ianseo-maintenance, ianseo-auth) and forget the backup log. Never install the
            // template beside them: two rules for one log make logrotate fail altogether.
            preg_match_all('#^(/var/log/\S+)\s*\{#m', (string) @file_get_contents($tpl . $t['src']), $m);
            $covered = array();
            $g = @glob($root . '/etc/logrotate.d/*');
            foreach (is_array($g) ? $g : array() as $f) {
                $c = (string) @file_get_contents($f);
                foreach ($m[1] as $log) {
                    if (strpos($c, $log) !== false) { $covered[$log] = true; $t['old'][$f] = mb_substr($f, mb_strlen($root)); }
                }
            }
            $t['unrotated'] = array_values(array_diff($m[1], array_keys($covered)));
            $t['old'] = array_values($t['old']);
            if ($t['old']) {
                $t['state'] = $t['unrotated'] ? 'old' : 'ok';
                $t['found'] = implode(', ', $t['old']);
                $out[] = $t;
                continue;
            }
        }
        if ($t['found'] === '') {
            $t['state'] = 'missing';
        } elseif ($t['same'] && is_readable($root . $t['found']) && is_readable($tpl . $t['src'])
            && md5_file($root . $t['found']) !== md5_file($tpl . $t['src'])) {
            $t['state'] = 'differs';
            $mine = aut_health_meaningful_lines(file_get_contents($root . $t['found']));
            $new  = aut_health_meaningful_lines(file_get_contents($tpl . $t['src']));
            $t['comments'] = $mine === $new;
            // Every working line of the installed file is still in the template: an older version
            // of it, which the template only completes — nothing set by hand can be lost.
            $t['adds'] = (!$t['comments'] && !array_diff($mine, $new)) ? array_values(array_diff($new, $mine)) : array();
        } else {
            $t['state'] = 'ok';
        }
        $out[] = $t;
    }
    return $out;
}

/** The lines of a system file that do something: comments and blank lines left out. */
function aut_health_meaningful_lines($text)
{
    $out = array();
    foreach (preg_split('/\R/', (string) $text) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || strpos($l, '//') === 0) continue;
        $out[] = $l;
    }
    return $out;
}

/**
 * The commands that install the missing templates and bring the differing ones in line. Every
 * command goes through sudo: the module's folder is readable by root and the web server only
 * (ianseo-lock), so a plain "diff" from the administrator's account is refused. A file whose
 * difference is only in its comments, or an older version that the template only completes (all
 * its working lines are still in it), is installed again directly; any other difference may be a
 * setting made by hand (a time moved in a cron file): the diff comes first and the installation
 * is given commented out, so that pasting the whole block never overwrites it.
 */
function aut_health_template_fix($states)
{
    $tpl = __DIR__ . '/serveur/';
    $cmd = array();
    $after = array();   // what makes an installed file taken into account, run once at the end
    // A timer drop-in is only read after daemon-reload, a fail2ban filter after a reload.
    $then = function ($dest) {
        if (strpos($dest, '/etc/systemd/') === 0) return 'sudo systemctl daemon-reload';
        if (strpos($dest, '/etc/fail2ban/') === 0) return 'sudo systemctl reload fail2ban';
        return '';
    };
    foreach ($states as $t) {
        $dest = explode('|', $t['dest'])[0];
        if ($t['state'] === 'missing') {
            $cmd[] = $t['mode'] === '0750'
                ? 'sudo install -m 0750 -o root -g root ' . $tpl . $t['src'] . ' /usr/local/bin/'
                : 'sudo install -D -m 0644 -o root -g root ' . $tpl . $t['src'] . ' ' . $dest;
            if ($t['src'] === 'needrestart/ianseo.conf' && !is_file('/usr/sbin/needrestart')) $cmd[] = 'sudo apt install -y needrestart';
            if ($then($dest) !== '') $after[$then($dest)] = true;
        } elseif ($t['state'] === 'differs') {
            $install = 'sudo install -D -m 0644 -o root -g root ' . $tpl . $t['src'] . ' ' . $t['found'];
            if (!empty($t['comments']) || !empty($t['adds'])) {
                $what = !empty($t['comments']) ? aut_t('HlTplCommentsCmd') : aut_t('HlTplAddsCmd', implode(' ; ', $t['adds']));
                $cmd[] = $install . '   # ' . (mb_strlen($what) > 110 ? mb_substr($what, 0, 109) . '…' : $what);
                if ($then($dest) !== '') $after[$then($dest)] = true;
            } else {
                $cmd[] = 'sudo diff -u ' . $t['found'] . ' ' . $tpl . $t['src'] . '   # ' . aut_t('HlTplDiffCmd');
                $cmd[] = '# ' . $install . ($then($dest) !== '' ? ' && ' . $then($dest) : '') . '   # ' . aut_t('HlTplReinstall');
            }
        } elseif ($t['state'] === 'old') {
            $cmd[] = 'sudo rm ' . implode(' ', $t['old']) . '   # ' . aut_t('HlTplOldCmd');
            $cmd[] = 'sudo install -D -m 0644 -o root -g root ' . $tpl . $t['src'] . ' ' . $dest;
        }
    }
    return implode("\n", array_merge($cmd, array_keys($after)));
}

/** Every check, in display order. */
function aut_health_checks()
{
    $out = array();
    $mb  = function ($b) { return aut_t('CfMb', round($b / 1048576)); };
    $ini = php_ini_loaded_file() ?: 'php.ini';

    // Database engine — MySQL 8.0.22+ pushes the outer filter into each branch of the core's
    // createAvailableTargetSQL() (one UNION per place): quadratic on big departures.
    $ver   = (string) aut_health_var('version');
    $maria = stripos($ver, 'mariadb') !== false;
    $num   = preg_replace('/[^0-9.].*$/', '', $ver);
    if ($ver !== '' && !$maria && version_compare($num, '8.0.22', '>=')) {
        if (strpos((string) aut_health_var('optimizer_switch'), 'derived_condition_pushdown=on') !== false) {
            $out[] = aut_health_item('warn', aut_t('HlMysqlSlowT', $num), aut_t('HlMysqlSlow'),
                "sudo mysql -e \"SET PERSIST optimizer_switch='derived_condition_pushdown=off';\"");
        } else {
            $out[] = aut_health_item('ok', 'MySQL ' . $num, aut_t('HlMysqlOk'));
        }
    } elseif ($ver !== '') {
        $out[] = aut_health_item('ok', ($maria ? 'MariaDB ' : 'MySQL ') . $num, aut_t('HlEngineOk'));
    }

    // Connections — ianseo opens TWO per page (reading and writing).
    $max = (int) aut_health_var('max_connections');
    if ($max > 0) {
        $used    = (int) aut_health_status('Max_used_connections');
        $refused = (int) aut_health_status('Connection_errors_max_connections');
        $w       = aut_health_php_workers();
        $need    = $w ? 2 * $w[0] + 20 : 0;
        $txt = aut_t('HlConnTxt', array('max' => $max, 'used' => $used));
        $fix = 'max_connections = ' . max($need, 200) . "\n" . aut_t('HlConnFix');
        if ($refused > 0) {
            $out[] = aut_health_item('warn', aut_t('HlConnRefusedT'), aut_t('HlConnRefused', $refused) . ' ' . $txt . '.', $fix);
        } elseif ($w && $max < $need) {
            $out[] = aut_health_item('warn', aut_t('HlConnT'), $txt . aut_t('HlConnWorkers', array('n' => $w[0], 'where' => $w[1], 'need' => 2 * $w[0])), $fix);
        } elseif ($used >= 0.8 * $max) {
            $out[] = aut_health_item('warn', aut_t('HlConnT'), $txt . aut_t('HlConnPeak'), $fix);
        } else {
            $out[] = aut_health_item('ok', aut_t('HlConnT'), $txt . ($w ? aut_t('HlConnMax', $w[0]) : '') . '.');
        }
    }

    // InnoDB memory — the whole database should fit in it.
    $pool = (float) aut_health_var('innodb_buffer_pool_size');
    $rs = safe_r_sql("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS s FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()", false, true);
    $size = ($rs && ($r = safe_fetch($rs))) ? (float) $r->s : 0;
    if ($pool > 0 && $size > 0) {
        $txt = aut_t('HlPoolTxt', array('pool' => $mb($pool), 'size' => $mb($size)));
        $fix = 'innodb_buffer_pool_size = ' . max(1, (int) ceil($size * 2 / 1073741824)) . 'G' . "\n" . aut_t('HlPoolFix');
        if ($pool < $size) {
            $out[] = aut_health_item('warn', aut_t('HlPoolT'), $txt . aut_t('HlPoolSmall'), $fix);
        } elseif ($pool < 1.5 * $size) {
            $out[] = aut_health_item('info', aut_t('HlPoolT'), $txt . aut_t('HlPoolMargin'), $fix);
        } else {
            $out[] = aut_health_item('ok', aut_t('HlPoolT'), $txt . '.');
        }
    }

    // Slow query log — what named the culprit on the production server.
    $slow = (string) aut_health_var('slow_query_log');
    if ($slow !== '') {
        if ($slow === '0' || strcasecmp($slow, 'OFF') === 0) {
            $out[] = aut_health_item('info', aut_t('HlSlowOffT'), aut_t('HlSlowOff'),
                "sudo mysql -e \"SET GLOBAL slow_query_log = 1; SET GLOBAL long_query_time = 2;\"" . "\n" . aut_t('HlSlowFix'));
        } else {
            $out[] = aut_health_item('ok', aut_t('HlSlowT'), aut_t('HlSlowOn', array(
                'sec' => (float) aut_health_var('long_query_time'), 'file' => aut_health_var('slow_query_log_file'))));
        }
    }

    // PHP sessions — the default 24 min logs people out and loses their open competition.
    $gc  = (int) ini_get('session.gc_maxlifetime');
    $dur = $gc >= 3600 ? round($gc / 3600, 1) . ' h' : round($gc / 60) . ' min';
    $restart = aut_t('HlRestartApache', $ini);
    $fix = 'session.gc_maxlifetime = 43200' . "\n" . $restart;
    if ($gc > 0 && $gc < 3600) {
        $out[] = aut_health_item('warn', aut_t('HlSessShortT'), aut_t('HlSessShort', $dur), $fix);
    } elseif ($gc > 0 && $gc < 43200) {
        $out[] = aut_health_item('info', aut_t('HlSessT'), aut_t('HlSessMid', $dur), $fix);
    } elseif ($gc > 0) {
        $out[] = aut_health_item('ok', aut_t('HlSessT'), aut_t('HlSessOk', $dur));
    }

    // Upload size — PHP, then ModSecurity, which has its own, smaller limit.
    $lim = min(aut_health_bytes(ini_get('upload_max_filesize')), aut_health_bytes(ini_get('post_max_size')));
    if ($lim > 0 && $lim < (64 << 20)) {
        $out[] = aut_health_item('warn', aut_t('HlUploadT'), aut_t('HlUploadSmall', $mb($lim)),
            "upload_max_filesize = 64M\npost_max_size = 64M\n" . $restart);
    } elseif ($lim > 0) {
        $out[] = aut_health_item('ok', aut_t('HlUploadT'), aut_t('HlUploadOk', $mb($lim)));
    }
    $ms = aut_health_read('/etc/modsecurity/modsecurity.conf');
    if ($ms !== '' && preg_match('/^\s*SecRequestBodyLimit\s+(\d+)/mi', $ms, $m) && (int) $m[1] < (64 << 20)) {
        $engine = preg_match('/^\s*SecRuleEngine\s+(\w+)/mi', $ms, $e) ? $e[1] : '?';
        $on = strcasecmp($engine, 'On') === 0;
        $out[] = aut_health_item($on ? 'warn' : 'info', aut_t('HlModsecT'),
            aut_t($on ? 'HlModsecOn' : 'HlModsecWatch', array('engine' => $engine, 'size' => $mb((int) $m[1]))),
            'SecRequestBodyLimit 67108864' . "\n" . aut_t('HlModsecFix'));
    }

    // OPcache — without it every page compiles ianseo again.
    if (!extension_loaded('Zend OPcache') || !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
        $out[] = aut_health_item('warn', aut_t('HlOpcacheOffT'), aut_t('HlOpcacheOff'),
            "sudo apt install php-opcache\n" . aut_t('HlOpcacheFix', $ini));
    } else {
        $out[] = aut_health_item('ok', 'OPcache', aut_t('HlActive'));
    }

    // Departure size — the core builds one UNION branch per place to check a target number.
    $rs = safe_r_sql("SELECT ToCode, ToName, SesOrder, SesTar4Session, SesAth4Target
        FROM Session INNER JOIN Tournament ON ToId = SesTournament
        WHERE SesType = 'Q' AND SesTar4Session * SesAth4Target > " . AUT_BIG_SESSION_PLACES . "
          AND ToWhenTo >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY SesTar4Session * SesAth4Target DESC LIMIT 10", false, true);
    $thousands = function_exists('get_text') ? get_text('NumberThousandsSeparator') : ' ';
    $big = array();
    while ($rs && ($r = safe_fetch($rs))) {
        $big[] = aut_t('HlBigLine', array('code' => $r->ToCode, 'name' => $r->ToName, 'ses' => $r->SesOrder,
            'targets' => $r->SesTar4Session, 'per' => $r->SesAth4Target,
            'places' => number_format($r->SesTar4Session * $r->SesAth4Target, 0, '', $thousands)));
    }
    if ($big) {
        $out[] = aut_health_item('warn', aut_t('HlBigT'), aut_t('HlBig'), '', $big);
    } else {
        $out[] = aut_health_item('ok', aut_t('HlBigOkT'), aut_t('HlBigOk', AUT_BIG_SESSION_PLACES));
    }

    // System updates — by default around 06:00-07:00, restarting the database and Apache.
    $over = '';
    $g = @glob('/etc/systemd/system/apt-daily-upgrade.timer.d/*.conf');
    foreach (is_array($g) ? $g : array() as $f) $over .= aut_health_read($f) . "\n";
    if (preg_match_all('/^\s*OnCalendar\s*=\s*(\S.*)$/mi', $over, $m)) {
        // Persistent: an installation missed while the server was off is caught up as soon as it
        // starts again — in the daytime too (2026-10-22: 13 packages at 13:53, after an outage).
        $catchUp = !preg_match('/^\s*Persistent\s*=\s*(false|no|0)\s*$/mi', $over);
        $out[] = $catchUp
            ? aut_health_item('info', aut_t('HlAptT'), aut_t('HlAptCatchUp', trim(end($m[1]))), aut_t('HlAptFix'))
            : aut_health_item('ok', aut_t('HlAptT'), aut_t('HlAptOk', trim(end($m[1]))));
    } elseif (aut_health_read('/lib/systemd/system/apt-daily-upgrade.timer') !== '') {
        $out[] = aut_health_item('warn', aut_t('HlAptT'), aut_t('HlAptDefault'), aut_t('HlAptFix'));
    }

    // Known PHP flaws — PHP2Shell (October 2026), phpAfterShell (November 2026): the list grows
    // with each AUTH version, the page keeps one line.
    $sec = aut_health_php_flaws();
    if ($sec !== null) $out[] = aut_health_flaws_item($sec, $ini);

    // Updates installed but not loaded — in October 2026, PHP 8.2.35 (two critical flaws) sat on
    // a test server's disk for 34 hours while Apache served the vulnerable 8.2.34: Debian restarts
    // nothing after a PHP update.
    $stale = aut_health_stale_files();
    if ($stale !== null) {
        $night    = is_file('/etc/cron.d/ianseo-restart') && is_file('/usr/local/bin/ianseo-restart-services');
        $nr       = is_file('/usr/sbin/needrestart');
        $listOnly = is_file('/etc/needrestart/conf.d/ianseo.conf');
        if ($stale) {
            $php = (bool) preg_grep('#/(libphp[^/]*|php-fpm[^/]*)$|/lib/php/#', $stale);
            $cmd = php_sapi_name() === 'fpm-fcgi'
                ? 'sudo systemctl restart php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm'
                : 'sudo systemctl restart apache2';
            $out[] = aut_health_item('warn', aut_t($php ? 'HlStalePhpT' : 'HlStaleT'),
                aut_t($php ? 'HlStalePhp' : 'HlStale') . ' ' . aut_t($night ? 'HlStaleNight' : 'HlStaleNoNight'),
                $cmd . ($night ? '' : "\n" . aut_t('HlRestartFix')), array_map('basename', $stale));
        } elseif ($night) {
            $out[] = aut_health_item('ok', aut_t('HlRestartT'), aut_t('HlRestartOk'));
        } elseif (!$nr) {
            $out[] = aut_health_item('info', aut_t('HlRestartT'), aut_t('HlRestartNone'), aut_t('HlRestartFix'));
        } elseif ($listOnly) {
            $out[] = aut_health_item('warn', aut_t('HlRestartT'), aut_t('HlRestartListOnly'), aut_t('HlRestartFix'));
        } else {
            $out[] = aut_health_item('info', aut_t('HlRestartT'), aut_t('HlRestartAuto'), aut_t('HlRestartFix'));
        }
    }

    // System files of the templates — what is installed, what is missing, what has changed.
    $tpls = aut_health_templates();
    if ($tpls !== null) {
        $list = array();
        $needMissing = false;
        foreach ($tpls as $t) {
            if ($t['state'] === 'ok') continue;
            if (($t['state'] === 'missing' || $t['state'] === 'old') && $t['need']) $needMissing = true;
            $list[] = explode('|', $t['dest'])[0] . ' — ' . ($t['state'] === 'old'
                ? aut_t('HlTplOld', array('files' => $t['found'], 'logs' => implode(', ', $t['unrotated'])))
                : aut_t($t['state'] === 'differs'
                    ? (!empty($t['comments']) ? 'HlTplDiffComments' : (!empty($t['adds']) ? 'HlTplDiffAdds' : 'HlTplDiff'))
                    : ($t['need'] ? 'HlTplMiss' : 'HlTplOpt')));
        }
        if (!$list) {
            $out[] = aut_health_item('ok', aut_t('HlTplT'), aut_t('HlTplOk', count($tpls)));
        } else {
            $out[] = aut_health_item($needMissing ? 'warn' : 'info', aut_t('HlTplT'),
                aut_t($needMissing ? 'HlTplWarn' : 'HlTplInfo'), aut_health_template_fix($tpls), $list);
        }
    }

    // Last nightly maintenance: how long the site stayed closed (recorded since 1.4.22).
    $rs = safe_r_sql("SELECT AjWhen, AjMessage FROM AuthJobs WHERE AjJob = 'maintenance'
        ORDER BY AjId DESC LIMIT 1", false, true);
    $r = $rs ? safe_fetch($rs) : null;
    $a = $r ? (json_decode((string) $r->AjMessage, true)['a'] ?? null) : null;
    if (is_array($a) && isset($a['closed'])) {
        $when = function_exists('aut_job_when') ? aut_job_when($r->AjWhen) : $r->AjWhen;
        $s = (int) $a['closed'];
        $out[] = aut_health_item($s > 120 ? 'info' : 'ok', aut_t('HlNightT'), $s === 0
            ? aut_t('HlNightOpen', $when)
            : aut_t('HlNightClosed', array('when' => $when, 's' => $s)));
    }

    // Backups — age of the last nightly set and of the last live copy.
    if (function_exists('aut_backup_config')) {
        $c = aut_backup_config();
        if (!$c['enabled']) {
            $out[] = aut_health_item('warn', aut_t('HlBackupT'), aut_t('HlBackupOff'));
        } else {
            $night = null; $live = null;
            foreach (aut_backup_list($c['dir']) as $b) {
                if ($b['kind'] === 'db' && !$night) $night = $b;
                if ($b['kind'] === 'live' && !$live) $live = $b;
            }
            $age = function ($b) {
                $h = (time() - $b['time']) / 3600;
                return $h < 48 ? aut_t('HlAgeHours', round($h)) : aut_t('HlAgeDays', round($h / 24));
            };
            $txt = $night ? aut_t('HlBackupNight', array('age' => $age($night), 'size' => aut_t('CfMb', round($night['size'] / 1048576, 1))))
                : aut_t('HlBackupNone', $c['dir']);
            $txt .= $live ? aut_t('HlBackupLive', $age($live)) : aut_t('HlBackupNoLive');
            $stale = $night && (time() - $night['time']) > 30 * 3600;
            $out[] = aut_health_item(($night && !$stale) ? 'ok' : 'warn', aut_t('HlBackupT'), $txt);
        }
    }
    return $out;
}
