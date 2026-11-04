<?php
/**
 * Cells for a person to check: those the reading could not make sure of (cells in doubt
 * on a card read from a photo or from the live view), each kept as a small picture with
 * what was read, for someone to type what is written there. What is typed is what the
 * reading networks learn from next.
 *
 * Kept in the server's temporary directory, never under the web root, one folder per
 * competition, removed on their own after OCR_LABEL_DAYS days. A cell's picture shows
 * handwritten figures, never a name. The checking page shows the cells of every folder,
 * whatever the competition open: those of folder "train" are put there by the tools that
 * build the networks' training set (the arrows its labels are not sure of), kept until
 * checked.
 *
 * Folder: items.jsonl (one cell a line: what was read, where it comes from), <uid>.png
 * (its picture), labels.jsonl (what was typed; a later line for the same cell wins).
 */

define('OCR_LABEL_DAYS', 60);
define('OCR_LABEL_BATCH', 12);    // cells shown at once (each large, with its neighbours)
define('OCR_LABEL_MAX', 400);     // cells sent at once, at most
define('OCR_LABEL_PNG', 262144);  // bytes of a cell's picture, at most

/**
 * Folder of a competition's cells to check.
 *
 * @param int $tour Competition (0: the open one).
 * @param bool $create
 * @return string Absolute path, without a trailing slash.
 */
function ocr_label_dir($tour = 0, $create = false) {
    $tour = $tour ?: (int)$_SESSION['TourId'];
    $dir = ocr_label_root() . '/' . (int)$tour;
    if ($create && !is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** The folder holding every folder of cells to check. */
function ocr_label_root() {
    return rtrim(sys_get_temp_dir(), '/\\') . '/ianseo-' . basename(dirname(__DIR__)) . '/labels';
}

/** The folders of cells to check: a competition's (its number) or the training set's ("train"). */
function ocr_label_folders() {
    $out = [];
    foreach (glob(ocr_label_root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (preg_match('/^(\d+|train)$/', basename($dir))) $out[] = $dir;
    }
    return $out;
}

/** The folder a cell is kept in, or ''. */
function ocr_label_find($uid) {
    foreach (ocr_label_folders() as $dir) {
        if (is_file($dir . '/' . $uid . '.png')) return $dir;
    }
    return '';
}

/** A cell's name: 40 hexadecimal characters, nothing else. */
function ocr_label_uid($uid) {
    $uid = (string)$uid;
    return preg_match('/^[0-9a-f]{40}$/', $uid) ? $uid : '';
}

/**
 * What may be typed for a cell: a value of a target face, a number of up to four
 * figures, empty (nothing written), "-" (a dash), "?" (cannot be read) or "*" (the cell
 * cut out badly: across two cells, off the grid).
 */
function ocr_label_value($v) {
    // bytes: whatever is not held to ASCII by the pattern below is refused anyway
    $v = strtoupper(trim((string)$v));
    return preg_match('/^(\?|\*|-|X|M|\d{0,4})$/', $v) ? $v : null;
}

/** The numbers a cell can hold, as sent ([[from, to]…]), kept to whole numbers; null: any. */
function ocr_label_allowed($a) {
    if (!is_array($a)) return null;
    $out = [];
    foreach (array_slice($a, 0, 64) as $r) {
        if (is_array($r) && count($r) === 2 && is_numeric($r[0]) && is_numeric($r[1])) $out[] = [(int)$r[0], (int)$r[1]];
    }
    return $out ?: null;
}

/** The JSON lines of a file, as arrays (bad lines left out). */
function ocr_label_lines($file) {
    $out = [];
    if (!is_file($file)) return $out;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $o = json_decode($line, true);
        if (is_array($o) && !empty($o['uid'])) $out[] = $o;
    }
    return $out;
}

/**
 * Cells sent by the reading page. Each: {uid, kind, key, line, src, enId, dist, allowed
 * (the numbers the cell can hold with the archer's face, [[from, to]…], null: any), reading:
 * [[value, p]…], box: [x0, y0, x1, y1] (the cell in its picture), png: base64}. A cell
 * already kept is left as it is.
 *
 * @return array ['added' => n, 'skipped' => n]
 */
function ocr_label_add(array $items, $tour = 0) {
    $dir = ocr_label_dir($tour, true);
    $added = $skipped = 0;
    $lines = '';
    foreach (array_slice($items, 0, OCR_LABEL_MAX) as $it) {
        $uid = ocr_label_uid($it['uid'] ?? '');
        $png = base64_decode((string)($it['png'] ?? ''), true);
        // bytes: the PNG signature and a size limit, not text
        if ($uid === '' || $png === false || strlen($png) > OCR_LABEL_PNG || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            $skipped++;
            continue;
        }
        if (is_file($dir . '/' . $uid . '.png')) {
            $skipped++;
            continue;
        }
        if (@file_put_contents($dir . '/' . $uid . '.png', $png) === false) {
            $skipped++;
            continue;
        }
        $reading = [];
        foreach (array_slice((array)($it['reading'] ?? []), 0, 6) as $r) {
            if (is_array($r) && count($r) >= 2) $reading[] = [(string)$r[0], round((float)$r[1], 4)];
        }
        $lines .= json_encode([
            'uid'     => $uid,
            'kind'    => preg_replace('/[^a-z]/', '', (string)($it['kind'] ?? '')),
            'key'     => preg_replace('/[^a-z0-9]/', '', (string)($it['key'] ?? '')),
            'line'    => preg_replace('/[^a-z0-9]/', '', (string)($it['line'] ?? '')),
            'src'     => preg_replace('/[^a-z0-9_-]/', '', (string)($it['src'] ?? '')),
            'enId'    => (int)($it['enId'] ?? 0),
            'dist'    => (int)($it['dist'] ?? 0),
            'allowed' => ocr_label_allowed($it['allowed'] ?? null),
            'label'   => mb_substr(preg_replace('/[^0-9A-Za-z+*]/', '', (string)($it['label'] ?? '')), 0, 8),
            'reading' => $reading,
            'box'     => array_map('floatval', array_slice((array)($it['box'] ?? []), 0, 4)),
            'time'    => time(),
        ]) . "\n";
        $added++;
    }
    if ($lines !== '') @file_put_contents($dir . '/items.jsonl', $lines, FILE_APPEND | LOCK_EX);
    return ['added' => $added, 'skipped' => $skipped];
}

/** What was typed for each cell of a folder so far. -> [uid => value] */
function ocr_label_done($dir) {
    $done = [];
    foreach (ocr_label_lines($dir . '/labels.jsonl') as $l) $done[$l['uid']] = (string)($l['value'] ?? '');
    return $done;
}

/**
 * The next cells to check, of every folder: the least sure reading first, each with its
 * picture.
 *
 * @return array ['pending' => n, 'done' => n, 'items' => [...]]
 */
function ocr_label_next() {
    $todo = [];
    $done = 0;
    foreach (ocr_label_folders() as $dir) {
        $typed = ocr_label_done($dir);
        $done += count($typed);
        foreach (ocr_label_lines($dir . '/items.jsonl') as $it) {
            if (isset($typed[$it['uid']]) || isset($todo[$it['uid']]) || !is_file($dir . '/' . $it['uid'] . '.png')) continue;
            $it['dir'] = $dir;
            $todo[$it['uid']] = $it;
        }
    }
    $todo = array_values($todo);
    usort($todo, function ($a, $b) {
        return ($a['reading'][0][1] ?? 0) <=> ($b['reading'][0][1] ?? 0);
    });
    $items = $byTour = [];
    foreach (array_slice($todo, 0, OCR_LABEL_BATCH) as $it) {
        $it['png'] = base64_encode((string)@file_get_contents($it['dir'] . '/' . $it['uid'] . '.png'));
        $it['tour'] = ctype_digit(basename($it['dir'])) ? (int)basename($it['dir']) : 0;
        unset($it['dir']);
        if ($it['tour']) $byTour[$it['tour']][] = (int)($it['enId'] ?? 0);
        $items[] = $it;
    }
    // Each cell's target, for the sheet to be found again (a training cell says where it is).
    $where = [];
    foreach ($byTour as $tour => $ids) $where[$tour] = ocr_label_targets($ids, $tour);
    foreach ($items as $k => $it) $items[$k]['target'] = $where[$it['tour']][(int)($it['enId'] ?? 0)] ?? '';
    return ['pending' => count($todo), 'done' => $done, 'items' => $items];
}

/**
 * The targets of some archers of a competition, as printed on their scorecards ("12C").
 *
 * @return array [enId => target]
 */
function ocr_label_targets(array $enIds, $tour = 0) {
    $tour = (int)($tour ?: $_SESSION['TourId']);
    $ids = array_values(array_unique(array_filter(array_map('intval', $enIds))));
    $out = [];
    if (!$ids || !$tour) return $out;
    $rs = safe_r_sql("SELECT QuId, QuTarget, QuLetter FROM Qualifications INNER JOIN Entries ON EnId = QuId
        WHERE EnTournament = $tour AND QuId IN (" . implode(',', $ids) . ")");
    while ($r = safe_fetch($rs)) {
        if ((int)$r->QuTarget) $out[(int)$r->QuId] = (int)$r->QuTarget . $r->QuLetter;
    }
    safe_free_result($rs);
    return $out;
}

/**
 * What a person typed. Each: {uid, value}; a cell not kept is left out.
 *
 * @return int Cells saved.
 */
function ocr_label_save(array $labels) {
    $lines = [];
    $n = 0;
    foreach (array_slice($labels, 0, OCR_LABEL_MAX) as $l) {
        $uid = ocr_label_uid($l['uid'] ?? '');
        $value = ocr_label_value($l['value'] ?? '');
        $dir = $uid === '' ? '' : ocr_label_find($uid);
        if ($dir === '' || $value === null) continue;
        $lines[$dir] = ($lines[$dir] ?? '') . json_encode(['uid' => $uid, 'value' => $value, 'time' => time()]) . "\n";
        $n++;
    }
    foreach ($lines as $dir => $text) @file_put_contents($dir . '/labels.jsonl', $text, FILE_APPEND | LOCK_EX);
    return $n;
}

/** Remove the cells kept longer than the retention period, of every competition. */
function ocr_label_purge() {
    $root = ocr_label_root();
    if (!is_dir($root)) return;
    $limit = time() - OCR_LABEL_DAYS * 86400;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $tour) {
        // The training set's: what was typed there is what the networks learn from.
        if (basename($tour) === 'train' || filemtime($tour) >= $limit) continue;
        foreach (glob($tour . '/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($tour);
    }
}
