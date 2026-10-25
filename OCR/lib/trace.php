<?php
/**
 * Trial recordings of the live view: the camera's video and what the page made of it,
 * frame after frame, kept to look into a trial afterwards (option of the reading page,
 * off unless ticked).
 *
 * They show archers' names and scores: kept in the server's temporary directory, never
 * under the web root, one folder per competition and per recording, and removed on
 * their own after OCR_TRACE_DAYS days.
 */

define('OCR_TRACE_DAYS', 7);

/**
 * Folder of the open competition's recordings, or of one of them.
 *
 * @param string $id Recording ('' for all of them).
 * @param bool $create
 * @return string Absolute path, without a trailing slash.
 */
function ocr_trace_dir($id = '', $create = false) {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/ianseo-' . basename(dirname(__DIR__))
        . '/traces/' . (int)$_SESSION['TourId'] . ($id !== '' ? '/' . $id : '');
    if ($create && !is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** A recording's name as the page gives it back: date and time, nothing else. */
function ocr_trace_id($id) {
    $id = (string)$id;
    return preg_match('/^\d{8}-\d{6}-\d{3}$/', $id) ? $id : '';
}

/** A new recording. -> its name */
function ocr_trace_start(array $meta) {
    $t = microtime(true);
    $id = date('Ymd-His', (int)$t) . '-' . sprintf('%03d', (int)(($t - floor($t)) * 1000));
    $dir = ocr_trace_dir($id, true);
    $keep = [];
    foreach (['camera', 'width', 'height', 'agent', 'options', 'grids', 'dist', 'session', 'lens'] as $k) {
        if (isset($meta[$k])) $keep[$k] = $meta[$k];
    }
    $keep['started'] = date('c', (int)$t);
    @file_put_contents($dir . '/meta.json', json_encode($keep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $id;
}

/**
 * A piece of the video, as the browser's recorder cut it (WebM).
 *
 * @return bool
 */
function ocr_trace_video($id, $seq, $tmp) {
    $dir = ocr_trace_dir($id);
    if (!is_dir($dir)) return false;
    return @move_uploaded_file($tmp, $dir . '/video-' . sprintf('%05d', (int)$seq) . '.webm');
}

/**
 * Lines of the record of what the page did (one JSON object per line).
 *
 * @return bool
 */
function ocr_trace_log($id, $text) {
    $dir = ocr_trace_dir($id);
    if (!is_dir($dir)) return false;
    // At most 2 MB at a time: what the page sends every few seconds is far less.
    $text = substr((string)$text, 0, 2 * 1024 * 1024);   // bytes: a size limit, not text
    return @file_put_contents($dir . '/log.jsonl', rtrim($text, "\n") . "\n", FILE_APPEND) !== false;
}

/** Remove the recordings older than the retention period, of every competition. */
function ocr_trace_purge() {
    $root = dirname(ocr_trace_dir());
    if (!is_dir($root)) return;
    $limit = time() - OCR_TRACE_DAYS * 86400;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $tour) {
        foreach (glob($tour . '/*', GLOB_ONLYDIR) ?: [] as $rec) {
            if (filemtime($rec) >= $limit) continue;
            foreach (glob($rec . '/*') ?: [] as $file) {
                if (is_file($file)) @unlink($file);
            }
            @rmdir($rec);
        }
        @rmdir($tour);   // only succeeds once empty
    }
}
