<?php
/**
 * Connexion PDO autonome du module PRONO.
 *
 * Le moteur et la face publique ne chargent JAMAIS config.php de ianseo : pas de
 * session, pas d'ACL, pas de routage. La surface atteignable depuis le vhost public
 * se limite donc à public/ + ces libs + les tables PRONO_*.
 *
 * Identifiants : data/db.local.json s'il existe (utilisateur MySQL restreint, cf.
 * README « Durcir l'accès base »), sinon ceux de ianseo (Common/config.inc.php).
 */

function prono_root(): string
{
    return dirname(__DIR__);
}

function prono_data_dir(): string
{
    $dir = prono_root() . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function prono_db_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $local = prono_root() . '/data/db.local.json';
    if (is_readable($local)) {
        $j = json_decode((string) file_get_contents($local), true);
        if (is_array($j) && !empty($j['name'])) {
            return $cfg = [
                'host' => $j['host'] ?? 'localhost',
                'user' => (string) ($j['user'] ?? ''),
                'pass' => (string) ($j['pass'] ?? ''),
                'name' => (string) $j['name'],
            ];
        }
    }

    // Repli : la configuration ianseo. Si config.php est déjà chargé (contexte admin),
    // on réutilise $CFG ; sinon on inclut le seul fichier de config, qui ne fait
    // qu'affecter des propriétés — aucun autre morceau de ianseo n'est embarqué.
    $CFG = $GLOBALS['CFG'] ?? null;
    if (!is_object($CFG) || empty($CFG->DB_NAME)) {
        $CFG  = new stdClass();
        $file = dirname(prono_root(), 3) . '/Common/config.inc.php';
        if (!is_readable($file)) {
            throw new RuntimeException('Configuration base introuvable (ni data/db.local.json ni Common/config.inc.php).');
        }
        include $file;
    }

    return $cfg = [
        'host' => $CFG->W_HOST ?? 'localhost',
        'user' => $CFG->W_USER ?? '',
        'pass' => $CFG->W_PASS ?? '',
        'name' => $CFG->DB_NAME ?? '',
    ];
}

function prono_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $c   = prono_db_config();
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Requête préparée + exécution. Tous les accès du module passent par ici. */
function prono_q(string $sql, array $params = []): PDOStatement
{
    $st = prono_db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function prono_all(string $sql, array $params = []): array
{
    return prono_q($sql, $params)->fetchAll();
}

function prono_one(string $sql, array $params = [])
{
    $row = prono_q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function prono_val(string $sql, array $params = [], $default = null)
{
    $v = prono_q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
