<?php
/**
 * Bibliothèque interne du module GUIDE.
 * - Utilisateur courant + schéma DB (progression et visites, par utilisateur)
 * - Évaluation des conditions (lecture seule sur la DB ianseo)
 * - Catalogue des contenus (formations, checklists, FAQ) trié pour les parcours
 * Incluse par guide-api.php, menu.php, index.php et les pages admin.
 */

/* ======= Utilisateur courant (module de comptes facultatif) ======= */

/**
 * Identifiant de l'utilisateur connecté, '' sans système de comptes.
 * $_SESSION['AUTH_User'] est la convention du cœur ianseo (USERAUTH) : posée
 * par le module d'authentification (ex. Modules/Custom/AUTH) et revalidée à
 * chaque requête. Absente → installation locale classique : tout le monde
 * partage le suivi de l'utilisateur ''.
 */
function guide_current_user() {
    return substr(trim((string)($_SESSION['AUTH_User'] ?? '')), 0, 64);
}

/**
 * Nombre de compétitions visibles par l'utilisateur courant. Avec un module de
 * comptes, la liste est restreinte par $_SESSION['AUTH_COMP'] (codes exacts ou
 * motifs LIKE) — même convention que la liste d'accueil du cœur ianseo.
 */
function guide_visible_tournament_count() {
    if (guide_current_user() !== '' && empty($_SESSION['AUTH_ROOT']) && isset($_SESSION['AUTH_COMP']) && is_array($_SESSION['AUTH_COMP'])) {
        $parts = [];
        foreach ($_SESSION['AUTH_COMP'] as $p) {
            $p = (string)$p;
            $parts[] = (strpos($p, '%') !== false || strpos($p, '_') !== false)
                ? 'ToCode LIKE ' . StrSafe_DB($p)
                : 'ToCode = ' . StrSafe_DB($p);
        }
        if (!count($parts)) return 0;
        $rs = safe_r_sql("SELECT COUNT(*) AS cnt FROM Tournament WHERE " . implode(' OR ', $parts));
    } else {
        $rs = safe_r_sql("SELECT COUNT(*) AS cnt FROM Tournament");
    }
    $row = safe_fetch($rs);
    return $row ? (int)$row->cnt : 0;
}

/**
 * Droit d'administrer le GUIDE. AclRoot ne suffit pas : avec un module de
 * comptes, authCheckACL accorde AclReadWrite à tout organisateur connecté sur
 * les pages hors compétition → on exige en plus la vue Administrateur serveur
 * (AUTH_ROOT). Sans compte (install locale, localhost), ACL ianseo classique.
 */
function guide_is_admin() {
    if (guide_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) return false;
    return hasFullACL(AclRoot, '', AclReadWrite);
}

/** Garde des pages admin : avorte (noAccess) si non autorisé. */
function guide_check_admin() {
    global $CFG;
    checkFullACL(AclRoot, '', AclReadWrite);
    if (guide_current_user() !== '' && empty($_SESSION['AUTH_ROOT'])) {
        CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
        die();
    }
}

/* ======= Préférences par utilisateur ======= */

/** Aide contextuelle activée pour l'utilisateur courant (préférence serveur). */
function guide_pref_ctx() {
    guide_ensure_schema();
    $rs  = safe_r_sql("SELECT GfCtxHelp FROM GUIDE_Prefs WHERE GfUser=" . StrSafe_DB(guide_current_user()));
    $row = safe_fetch($rs);
    return $row ? (int)$row->GfCtxHelp : 1;
}

function guide_pref_set_ctx($on) {
    guide_ensure_schema();
    safe_w_sql("INSERT INTO GUIDE_Prefs (GfUser, GfCtxHelp, GfUpdatedAt) VALUES ("
        . StrSafe_DB(guide_current_user()) . ", " . ($on ? 1 : 0) . ", " . StrSafe_DB(date('Y-m-d H:i:s')) . ")
        ON DUPLICATE KEY UPDATE GfCtxHelp=VALUES(GfCtxHelp), GfUpdatedAt=VALUES(GfUpdatedAt)");
}

/* ======= Schéma DB ======= */

function guide_ensure_schema() {
    if (!empty($_SESSION['_guide_schema_v4'])) return;

    $rs = safe_r_sql("SHOW TABLES LIKE 'GUIDE_Progress'");
    if (safe_fetch($rs)) {
        $rc_col = safe_r_sql("SHOW COLUMNS FROM GUIDE_Progress LIKE 'GpTourId'");
        $rc_old = safe_r_sql("SHOW INDEX FROM GUIDE_Progress WHERE Key_name='uq_form_tour'");
        if (!safe_fetch($rc_col) || safe_fetch($rc_old)) {
            // schéma d'avant la clé unique par formation → recréer
            safe_w_sql("DROP TABLE GUIDE_Progress");
        } else {
            $rc = safe_r_sql("SHOW COLUMNS FROM GUIDE_Progress LIKE 'GpQuiz'");
            if (!safe_fetch($rc)) {
                safe_w_sql("ALTER TABLE GUIDE_Progress
                    ADD COLUMN GpQuiz TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN GpChallenge TINYINT(1) NOT NULL DEFAULT 0");
            }
            // v3 : suivi par utilisateur (module de comptes) — les lignes
            // existantes deviennent la progression de l'utilisateur ''
            $rc = safe_r_sql("SHOW COLUMNS FROM GUIDE_Progress LIKE 'GpUser'");
            if (!safe_fetch($rc)) {
                safe_w_sql("ALTER TABLE GUIDE_Progress
                    ADD COLUMN GpUser VARCHAR(64) NOT NULL DEFAULT '' AFTER GpId");
                safe_w_sql("ALTER TABLE GUIDE_Progress
                    DROP INDEX uq_form,
                    ADD UNIQUE KEY uq_user_form (GpUser, GpFormId)");
            }
        }
    }

    $rs = safe_r_sql("SHOW TABLES LIKE 'GUIDE_Progress'");
    if (!safe_fetch($rs)) {
        safe_w_sql("CREATE TABLE GUIDE_Progress (
            GpId        INT AUTO_INCREMENT PRIMARY KEY,
            GpUser      VARCHAR(64)  NOT NULL DEFAULT '',
            GpFormId    VARCHAR(30)  NOT NULL,
            GpFormVer   VARCHAR(20)  NOT NULL DEFAULT '1.0',
            GpTourId    INT          NOT NULL DEFAULT 0,
            GpStep      INT          NOT NULL DEFAULT 0,
            GpStatus    ENUM('en_cours','termine','obsolete') NOT NULL DEFAULT 'en_cours',
            GpQuiz      TINYINT(1)   NOT NULL DEFAULT 0,
            GpChallenge TINYINT(1)   NOT NULL DEFAULT 0,
            GpValidated TEXT,
            GpUpdatedAt DATETIME     NOT NULL,
            UNIQUE KEY uq_user_form (GpUser, GpFormId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Visites de pages (checks « visited »), par utilisateur et compétition
    safe_w_sql("CREATE TABLE IF NOT EXISTS GUIDE_Visits (
        GvId     INT AUTO_INCREMENT PRIMARY KEY,
        GvUser   VARCHAR(64)  NOT NULL DEFAULT '',
        GvTourId INT          NOT NULL DEFAULT 0,
        GvPath   VARCHAR(120) NOT NULL,
        GvWhen   DATETIME     NOT NULL,
        UNIQUE KEY uq_user_tour_path (GvUser, GvTourId, GvPath)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Préférences par utilisateur (aide contextuelle…)
    safe_w_sql("CREATE TABLE IF NOT EXISTS GUIDE_Prefs (
        GfUser      VARCHAR(64) NOT NULL PRIMARY KEY,
        GfCtxHelp   TINYINT(1)  NOT NULL DEFAULT 1,
        GfUpdatedAt DATETIME    NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION['_guide_schema_v4'] = true;
}

/* ======= Visites de pages ======= */

/** Chemin du script courant relatif à la racine ianseo, normalisé. */
function guide_script_rel() {
    global $CFG;
    $s = $_SERVER['SCRIPT_NAME'] ?? '';
    $root = rtrim($CFG->ROOT_DIR ?? '/', '/');
    if ($root && strpos($s, $root) === 0) $s = substr($s, strlen($root));
    return guide_norm_path($s !== '' ? $s : '/');
}

/** Chemins surveillés = tous les checks « visited » de conditions.json. */
function guide_tracked_paths() {
    static $paths = null;
    if (!is_null($paths)) return $paths;
    $paths = [];
    foreach (guide_load_conditions() as $c) {
        foreach (($c['checks'] ?? []) as $ch) {
            if (($ch['source'] ?? '') === 'visited' && !empty($ch['path'])) {
                $paths[] = guide_norm_path($ch['path']);
            }
        }
    }
    $paths = array_values(array_unique($paths));
    return $paths;
}

/**
 * Mémorise la visite de la page courante si une condition la surveille.
 * Appelée depuis menu.php (toutes les pages) : ne touche la DB que pour les
 * pages effectivement surveillées.
 */
function guide_track_visit() {
    $path = guide_script_rel();
    if (!in_array($path, guide_tracked_paths())) return;
    guide_ensure_schema();
    safe_w_sql("INSERT IGNORE INTO GUIDE_Visits (GvUser, GvTourId, GvPath, GvWhen) VALUES ("
        . StrSafe_DB(guide_current_user()) . ", "
        . max(0, (int)($_SESSION['TourId'] ?? 0)) . ", "
        . StrSafe_DB($path) . ", "
        . StrSafe_DB(date('Y-m-d H:i:s')) . ")");
}

/* ======= Conditions ======= */

function guide_load_conditions() {
    $f = dirname(__DIR__) . '/conditions.json';
    if (!is_file($f)) return [];
    return json_decode(file_get_contents($f), true) ?: [];
}

function guide_save_conditions($conditions) {
    $f = dirname(__DIR__) . '/conditions.json';
    return file_put_contents($f, json_encode(array_values($conditions), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function guide_evaluate_condition($cond) {
    foreach ($cond['checks'] as $check) {
        if (!guide_evaluate_check($check)) return false;
    }
    return true;
}

/** Jointure interne facultative d'un check agrégat. */
function guide_build_join($check) {
    if (empty($check['join']['table'])
        || !preg_match('/^(\w+)\s*=\s*(\w+)$/', $check['join']['on'] ?? '', $m)) return '';
    $jt = preg_replace('/[^a-zA-Z0-9_]/', '', $check['join']['table']);
    return " INNER JOIN `$jt` ON `{$m[1]}` = `{$m[2]}`";
}

/** Clause WHERE d'un check agrégat (op 'in' = liste CSV ; source 'session' = clé de session). */
function guide_build_where($wheres) {
    $out = [];
    foreach ($wheres as $w) {
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $w['column']);
        if (isset($w['source']) && $w['source'] === 'session') {
            $out[] = "`$col` = " . (int)($_SESSION[$w['key']] ?? 0);
        } elseif (($w['op'] ?? '') === 'in') {
            $vals = array_map('trim', explode(',', (string)$w['value']));
            $out[] = "`$col` IN (" . implode(',', array_map('StrSafe_DB', $vals)) . ")";
        } else {
            $ops = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
            $op  = $ops[$w['op'] ?? 'eq'] ?? '=';
            $out[] = "`$col` $op " . StrSafe_DB($w['value']);
        }
    }
    return $out ? ' WHERE ' . implode(' AND ', $out) : '';
}

function guide_evaluate_check($check) {
    // Check sur la session
    if (isset($check['source']) && $check['source'] === 'session') {
        $val = isset($_SESSION[$check['key']]) ? (int)$_SESSION[$check['key']] : 0;
        return guide_compare($val, $check['op'], $check['value']);
    }

    // Page visitée par cet utilisateur (enregistrée par guide_track_visit).
    // Par défaut limitée à la compétition ouverte ; "any_tournament": true
    // pour accepter une visite faite sur n'importe quelle compétition.
    if (isset($check['source']) && $check['source'] === 'visited') {
        $path = guide_norm_path((string)($check['path'] ?? ''));
        if ($path === '') return false;
        guide_ensure_schema();
        $sql = "SELECT 1 FROM GUIDE_Visits WHERE GvUser=" . StrSafe_DB(guide_current_user())
            . " AND GvPath=" . StrSafe_DB($path);
        if (empty($check['any_tournament'])) {
            $sql .= " AND GvTourId=" . max(0, (int)($_SESSION['TourId'] ?? 0));
        }
        $rs = safe_r_sql($sql . " LIMIT 1");
        return (bool)safe_fetch($rs);
    }

    // Agrégats sur une table, avec jointure interne facultative
    // ("join": {"table": "Entries", "on": "QuId = EnId"}) :
    //  - "count"     : nombre de lignes
    //  - "max_group" : taille du plus gros groupe ("group_by": ["EnDivision","EnClass"])
    //                  → « au moins N archers dans UNE MÊME catégorie »
    $agg = $check['aggregate'] ?? '';
    if ($agg === 'count' || $agg === 'max_group') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $sql   = "FROM `$table`" . guide_build_join($check) . guide_build_where($check['where'] ?? []);

        if ($agg === 'max_group') {
            $cols = [];
            foreach ((array)($check['group_by'] ?? []) as $g) {
                $cols[] = '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $g) . '`';
            }
            if (!$cols) return false;
            $rs  = safe_r_sql("SELECT COUNT(*) AS cnt $sql GROUP BY " . implode(',', $cols)
                . " ORDER BY cnt DESC LIMIT 1");
        } else {
            $rs = safe_r_sql("SELECT COUNT(*) AS cnt $sql");
        }
        $row = safe_fetch($rs);
        return guide_compare($row ? (int)$row->cnt : 0, $check['op'], $check['value']);
    }

    // Valeur d'une colonne avec jointure sur session
    if (isset($check['table']) && isset($check['column'])) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $col   = preg_replace('/[^a-zA-Z0-9_]/', '', $check['column']);
        $where = '';
        if (isset($check['join'])) {
            // Format: "ToId = TourId" → WHERE `ToId` = $_SESSION['TourId']
            if (preg_match('/^(\w+)\s*=\s*(\w+)$/', $check['join'], $m)) {
                $jcol = preg_replace('/[^a-zA-Z0-9_]/', '', $m[1]);
                $jkey = preg_replace('/[^a-zA-Z0-9_]/', '', $m[2]);
                $where = " WHERE `$jcol` = " . (int)($_SESSION[$jkey] ?? 0);
            }
        }
        $sql = "SELECT `$col` FROM `$table`$where LIMIT 1";
        $rs  = safe_r_sql($sql);
        $row = safe_fetch($rs);
        if (!$row) return false;
        return guide_compare($row->$col, $check['op'], $check['value']);
    }

    return false;
}

function guide_compare($actual, $op, $expected) {
    switch ($op) {
        case 'eq':  return $actual == $expected;
        case 'neq': return $actual != $expected;
        case 'gt':  return $actual >  $expected;
        case 'gte': return $actual >= $expected;
        case 'lt':  return $actual <  $expected;
        case 'lte': return $actual <= $expected;
        default:    return false;
    }
}

/* ======= Catalogue des contenus ======= */

function guide_content_dir() {
    return dirname(__DIR__) . '/content/';
}

/**
 * Liste tous les contenus (formations, checklists, FAQ) triés par order puis titre.
 * $with_image : inclure l'image base64 (lourde) — pour le catalogue uniquement.
 */
function guide_content_list($with_image = false) {
    $out = [];
    foreach (glob(guide_content_dir() . '*.json') as $file) {
        $d = json_decode(file_get_contents($file), true);
        if (!$d || empty($d['id'])) continue;
        $type  = $d['type'] ?? 'formation';
        $pages = [];
        if ($type === 'formation') {
            foreach (($d['steps'] ?? []) as $s) {
                if (!empty($s['page']) && $s['page'] !== '*') $pages[] = $s['page'];
                foreach (($s['triggers'] ?? []) as $t) {
                    if (!empty($t['page']) && $t['page'] !== '*') $pages[] = $t['page'];
                }
            }
        } elseif ($type === 'checklist') {
            foreach (($d['items'] ?? []) as $it) {
                if (!empty($it['page'])) $pages[] = $it['page'];
            }
        } elseif ($type === 'faq') {
            foreach (($d['nodes'] ?? []) as $n) {
                if (!empty($n['page'])) $pages[] = $n['page'];
            }
        }
        $entry = [
            'id'            => $d['id'],
            'type'          => $type,
            'title'         => $d['title'] ?? '(sans titre)',
            'description'   => $d['description'] ?? '',
            'version'       => $d['version'] ?? '1.0',
            'group'         => $d['group'] ?? '',
            'subgroup'      => $d['subgroup'] ?? '',
            'order'         => isset($d['order']) ? (int)$d['order'] : 9999,
            'steps_count'   => count($d['steps'] ?? []),
            'has_quiz'      => !empty($d['quiz']['questions']),
            'has_challenge' => !empty($d['challenge']['conditions']),
            'pages'         => array_values(array_unique($pages)),
            'file'          => basename($file),
        ];
        if ($with_image) $entry['image'] = $d['image'] ?? '';
        $out[] = $entry;
    }
    usort($out, function ($a, $b) {
        if ($a['order'] !== $b['order']) return $a['order'] - $b['order'];
        return strcmp($a['title'], $b['title']);
    });
    return $out;
}

/** Formations seules, dans l'ordre du parcours (pour "formation suivante"). */
function guide_formations_ordered() {
    $out = [];
    foreach (guide_content_list() as $c) {
        if ($c['type'] === 'formation') $out[] = $c;
    }
    return $out;
}

/** Normalise un chemin de page pour comparaison (retire la query, /index.php → /). */
function guide_norm_path($p) {
    $q = strpos($p, '?');
    if ($q !== false) $p = substr($p, 0, $q);
    return preg_replace('#/index\.php$#', '/', $p);
}
