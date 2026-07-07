<?php
/**
 * Bibliothèque interne du module GUIDE.
 * - Évaluation des conditions (lecture seule sur la DB ianseo)
 * - Catalogue des contenus (formations, checklists, FAQ) trié pour les parcours
 * Incluse par guide-api.php, index.php et les pages admin.
 */

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

function guide_evaluate_check($check) {
    // Check sur la session
    if (isset($check['source']) && $check['source'] === 'session') {
        $val = isset($_SESSION[$check['key']]) ? (int)$_SESSION[$check['key']] : 0;
        return guide_compare($val, $check['op'], $check['value']);
    }

    // Agrégat COUNT sur une table
    if (isset($check['aggregate']) && $check['aggregate'] === 'count') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $check['table']);
        $where = [];
        foreach ($check['where'] as $w) {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', $w['column']);
            if (isset($w['source']) && $w['source'] === 'session') {
                $where[] = "`$col` = " . (int)($_SESSION[$w['key']] ?? 0);
            } else {
                $ops = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
                $op  = $ops[$w['op'] ?? 'eq'] ?? '=';
                $where[] = "`$col` $op " . StrSafe_DB($w['value']);
            }
        }
        $sql = "SELECT COUNT(*) AS cnt FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $rs  = safe_r_sql($sql);
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
