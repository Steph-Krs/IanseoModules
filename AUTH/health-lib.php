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
        $out[] = aut_health_item('ok', aut_t('HlAptT'), aut_t('HlAptOk', trim(end($m[1]))));
    } elseif (aut_health_read('/lib/systemd/system/apt-daily-upgrade.timer') !== '') {
        $out[] = aut_health_item('warn', aut_t('HlAptT'), aut_t('HlAptDefault'), aut_t('HlAptFix'));
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
