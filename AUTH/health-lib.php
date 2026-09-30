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
    $mb  = function ($b) { return round($b / 1048576) . ' Mo'; };
    $ini = php_ini_loaded_file() ?: 'php.ini';

    // Database engine — MySQL 8.0.22+ pushes the outer filter into each branch of the core's
    // createAvailableTargetSQL() (one UNION per place): quadratic on big departures.
    $ver   = (string) aut_health_var('version');
    $maria = stripos($ver, 'mariadb') !== false;
    $num   = preg_replace('/[^0-9.].*$/', '', $ver);
    if ($ver !== '' && !$maria && version_compare($num, '8.0.22', '>=')) {
        if (strpos((string) aut_health_var('optimizer_switch'), 'derived_condition_pushdown=on') !== false) {
            $out[] = aut_health_item('warn', 'MySQL ' . $num . ' : réglage de vitesse manquant',
                'L\'optimisation « derived_condition_pushdown » de MySQL 8 rend la vérification des numéros de '
                . 'cible du cœur ianseo quadratique : sur un serveur réel, ajouter un archer sur un départ de '
                . '80 000 places prenait 10 minutes (1,2 s une fois coupée), processeur saturé pour tout le monde. '
                . 'Réglage de vitesse seulement : résultats identiques, conservé au redémarrage, réversible (=on).',
                "sudo mysql -e \"SET PERSIST optimizer_switch='derived_condition_pushdown=off';\"");
        } else {
            $out[] = aut_health_item('ok', 'MySQL ' . $num, 'Réglage derived_condition_pushdown=off en place.');
        }
    } elseif ($ver !== '') {
        $out[] = aut_health_item('ok', ($maria ? 'MariaDB ' : 'MySQL ') . $num,
            'Moteur non concerné par le piège de MySQL 8 sur les grands départs.');
    }

    // Connections — ianseo opens TWO per page (reading and writing).
    $max = (int) aut_health_var('max_connections');
    if ($max > 0) {
        $used    = (int) aut_health_status('Max_used_connections');
        $refused = (int) aut_health_status('Connection_errors_max_connections');
        $w       = aut_health_php_workers();
        $need    = $w ? 2 * $w[0] + 20 : 0;
        $txt = 'max_connections = ' . $max . ', pic depuis le démarrage de la base : ' . $used
            . '. ianseo ouvre deux connexions par page (lecture et écriture)';
        $fix = 'max_connections = ' . max($need, 200)
            . "\n(fichier /etc/mysql/…/99-ianseo.cnf, gabarit serveur/mysql/ianseo.cnf, puis redémarrer la base)";
        if ($refused > 0) {
            $out[] = aut_health_item('warn', 'Connexions à la base refusées', $refused . ' connexion(s) refusée(s) '
                . 'depuis le démarrage : la limite a été atteinte, des utilisateurs ont eu une page d\'erreur. ' . $txt . '.', $fix);
        } elseif ($w && $max < $need) {
            $out[] = aut_health_item('warn', 'Connexions à la base', $txt . ' : ' . $w[0] . ' processus PHP au plus ('
                . $w[1] . ') en demanderaient ' . (2 * $w[0]) . ' au plus fort de la charge, au-delà de la limite.', $fix);
        } elseif ($used >= 0.8 * $max) {
            $out[] = aut_health_item('warn', 'Connexions à la base', $txt . ' : le pic approche la limite.', $fix);
        } else {
            $out[] = aut_health_item('ok', 'Connexions à la base', $txt . ($w ? ' ; ' . $w[0] . ' processus PHP au plus' : '') . '.');
        }
    }

    // InnoDB memory — the whole database should fit in it.
    $pool = (float) aut_health_var('innodb_buffer_pool_size');
    $rs = safe_r_sql("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS s FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()", false, true);
    $size = ($rs && ($r = safe_fetch($rs))) ? (float) $r->s : 0;
    if ($pool > 0 && $size > 0) {
        $txt = 'innodb_buffer_pool_size = ' . $mb($pool) . ' pour une base de ' . $mb($size);
        $fix = 'innodb_buffer_pool_size = ' . max(1, (int) ceil($size * 2 / 1073741824)) . 'G'
            . "\n(au moins la taille de la base ; jusqu'à 50-70 % de la RAM sur une machine dédiée — gabarit serveur/mysql/ianseo.cnf)";
        if ($pool < $size) {
            $out[] = aut_health_item('warn', 'Mémoire de la base', $txt . ' : elle ne tient plus en mémoire, les pages relisent le disque.', $fix);
        } elseif ($pool < 1.5 * $size) {
            $out[] = aut_health_item('info', 'Mémoire de la base', $txt . ' : la marge se réduit à mesure que les compétitions s\'accumulent.', $fix);
        } else {
            $out[] = aut_health_item('ok', 'Mémoire de la base', $txt . '.');
        }
    }

    // Slow query log — what named the culprit on the production server.
    $slow = (string) aut_health_var('slow_query_log');
    if ($slow !== '') {
        if ($slow === '0' || strcasecmp($slow, 'OFF') === 0) {
            $out[] = aut_health_item('info', 'Journal des requêtes lentes inactif',
                'C\'est lui qui désigne la requête en cause quand le serveur ralentit. Seuil conseillé : 2 s.',
                "sudo mysql -e \"SET GLOBAL slow_query_log = 1; SET GLOBAL long_query_time = 2;\""
                . "\n(et dans le .cnf pour le garder au redémarrage — gabarit serveur/mysql/ianseo.cnf)");
        } else {
            $out[] = aut_health_item('ok', 'Journal des requêtes lentes', 'Actif, seuil '
                . (float) aut_health_var('long_query_time') . ' s : ' . aut_health_var('slow_query_log_file') . '.');
        }
    }

    // PHP sessions — the default 24 min logs people out and loses their open competition.
    $gc  = (int) ini_get('session.gc_maxlifetime');
    $dur = $gc >= 3600 ? round($gc / 3600, 1) . ' h' : round($gc / 60) . ' min';
    $fix = 'session.gc_maxlifetime = 43200' . "\n(dans " . $ini . ', puis redémarrer Apache)';
    if ($gc > 0 && $gc < 3600) {
        $out[] = aut_health_item('warn', 'Sessions PHP courtes', 'Une session inactive est effacée après ' . $dur
            . ' : l\'utilisateur est déconnecté et perd la compétition ouverte.', $fix);
    } elseif ($gc > 0 && $gc < 43200) {
        $out[] = aut_health_item('info', 'Sessions PHP', 'Une session inactive est effacée après ' . $dur
            . ', avant les 12 h d\'inactivité prévues par le module.', $fix);
    } elseif ($gc > 0) {
        $out[] = aut_health_item('ok', 'Sessions PHP', 'Effacées après ' . $dur . ' d\'inactivité.');
    }

    // Upload size — PHP, then ModSecurity, which has its own, smaller limit.
    $lim = min(aut_health_bytes(ini_get('upload_max_filesize')), aut_health_bytes(ini_get('post_max_size')));
    if ($lim > 0 && $lim < (64 << 20)) {
        $out[] = aut_health_item('warn', 'Taille des imports', 'PHP refuse les envois de plus de ' . $mb($lim)
            . ' : l\'import d\'une grosse compétition échouera.',
            "upload_max_filesize = 64M\npost_max_size = 64M\n(dans " . $ini . ', puis redémarrer Apache)');
    } elseif ($lim > 0) {
        $out[] = aut_health_item('ok', 'Taille des imports', 'Jusqu\'à ' . $mb($lim) . ' côté PHP.');
    }
    $ms = aut_health_read('/etc/modsecurity/modsecurity.conf');
    if ($ms !== '' && preg_match('/^\s*SecRequestBodyLimit\s+(\d+)/mi', $ms, $m) && (int) $m[1] < (64 << 20)) {
        $engine = preg_match('/^\s*SecRuleEngine\s+(\w+)/mi', $ms, $e) ? $e[1] : '?';
        $on = strcasecmp($engine, 'On') === 0;
        $out[] = aut_health_item($on ? 'warn' : 'info', 'ModSecurity : taille des envois',
            'ModSecurity (' . $engine . ') limite les envois à ' . $mb((int) $m[1]) . ($on
                ? ' : un import plus lourd est refusé (erreur 413) alors que PHP l\'accepterait.'
                : ' : sans effet tant qu\'il ne fait qu\'observer, mais un import plus lourd sera refusé dès son passage en blocage (On).'),
            'SecRequestBodyLimit 67108864' . "\n(dans /etc/modsecurity/modsecurity.conf, puis recharger Apache)");
    }

    // OPcache — without it every page compiles ianseo again.
    if (!extension_loaded('Zend OPcache') || !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
        $out[] = aut_health_item('warn', 'OPcache inactif', 'Chaque page recompile le code de ianseo : trois à cinq '
            . 'fois plus de processeur pour le même trafic.',
            "sudo apt install php-opcache\n(opcache.enable = 1 dans " . $ini . ', puis redémarrer Apache)');
    } else {
        $out[] = aut_health_item('ok', 'OPcache', 'Actif.');
    }

    // Departure size — the core builds one UNION branch per place to check a target number.
    $rs = safe_r_sql("SELECT ToCode, ToName, SesOrder, SesTar4Session, SesAth4Target
        FROM Session INNER JOIN Tournament ON ToId = SesTournament
        WHERE SesType = 'Q' AND SesTar4Session * SesAth4Target > " . AUT_BIG_SESSION_PLACES . "
          AND ToWhenTo >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY SesTar4Session * SesAth4Target DESC LIMIT 10", false, true);
    $big = array();
    while ($rs && ($r = safe_fetch($rs))) {
        $big[] = $r->ToCode . ' (' . $r->ToName . '), départ ' . $r->SesOrder . ' : ' . $r->SesTar4Session
            . ' cibles × ' . $r->SesAth4Target . ' = '
            . number_format($r->SesTar4Session * $r->SesAth4Target, 0, ',', ' ') . ' places';
    }
    if ($big) {
        $out[] = aut_health_item('warn', 'Départs surdimensionnés', 'Pour vérifier un numéro de cible, ianseo '
            . 'fabrique une requête d\'une ligne par place du départ : au-delà de quelques milliers de places, '
            . 'ajouter ou déplacer un archer devient lent pour tout le serveur (très lent sous MySQL 8). '
            . 'À ramener au besoin réel, dans Compétition › Départs :', '', $big);
    } else {
        $out[] = aut_health_item('ok', 'Taille des départs', 'Aucun départ de plus de '
            . AUT_BIG_SESSION_PLACES . ' places dans les compétitions en cours ou à venir.');
    }

    // System updates — by default around 06:00-07:00, restarting the database and Apache.
    $over = '';
    $g = @glob('/etc/systemd/system/apt-daily-upgrade.timer.d/*.conf');
    foreach (is_array($g) ? $g : array() as $f) $over .= aut_health_read($f) . "\n";
    if (preg_match_all('/^\s*OnCalendar\s*=\s*(\S.*)$/mi', $over, $m)) {
        $out[] = aut_health_item('ok', 'Mises à jour du système', 'Installation programmée : ' . trim(end($m[1])) . '.');
    } elseif (aut_health_read('/lib/systemd/system/apt-daily-upgrade.timer') !== '') {
        $out[] = aut_health_item('warn', 'Mises à jour du système', 'Heure par défaut : vers 6 h, avec un délai '
            . 'aléatoire d\'une heure. Elles redémarrent la base et Apache — sur un autre serveur ianseo, trois fois '
            . 'en pleine compétition en un semestre.',
            'Gabarits serveur/apt (installation à 04:30, après la maintenance de 03:15) — SERVEUR.md § 4.1');
    }

    // Backups — age of the last nightly set and of the last live copy.
    if (function_exists('aut_backup_config')) {
        $c = aut_backup_config();
        if (!$c['enabled']) {
            $out[] = aut_health_item('warn', 'Sauvegardes', 'Désactivées (backup.enabled).');
        } else {
            $night = null; $live = null;
            foreach (aut_backup_list($c['dir']) as $b) {
                if ($b['kind'] === 'db' && !$night) $night = $b;
                if ($b['kind'] === 'live' && !$live) $live = $b;
            }
            $age = function ($b) { $h = (time() - $b['time']) / 3600; return $h < 48 ? round($h) . ' h' : round($h / 24) . ' jours'; };
            $txt = $night ? 'Dernière nuit : il y a ' . $age($night) . ' (' . round($night['size'] / 1048576, 1) . ' Mo)'
                : 'Aucune sauvegarde nocturne dans ' . $c['dir'];
            $txt .= $live ? ' ; dernière copie à chaud : il y a ' . $age($live) . '.' : ' ; pas de copie à chaud (gabarit serveur/cron/ianseo-backup-live).';
            $stale = $night && (time() - $night['time']) > 30 * 3600;
            $out[] = aut_health_item(($night && !$stale) ? 'ok' : 'warn', 'Sauvegardes', $txt);
        }
    }
    return $out;
}
