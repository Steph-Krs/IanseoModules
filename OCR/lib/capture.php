<?php
/**
 * Photos taken by the series capture: where they are kept, and how they are listed,
 * archived and removed.
 *
 * They hold archers' names and scores, so they are kept in the server's temporary
 * directory, never under the web root, in one folder per competition, and removed on
 * their own after OCR_CAPTURE_DAYS days. Each photo comes with a small JSON file:
 * what the browser read on it (barcode, grid) and when it was taken.
 */

define('OCR_CAPTURE_DAYS', 7);

/**
 * Folder of the open competition's captures, created when asked for.
 *
 * @param bool $create
 * @return string Absolute path, without a trailing slash.
 */
function ocr_capture_dir($create = false) {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/ianseo-' . basename(dirname(__DIR__))
        . '/captures/' . (int)$_SESSION['TourId'];
    if ($create && !is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/**
 * Remove the captures older than the retention period, of every competition.
 */
function ocr_capture_purge() {
    $root = dirname(ocr_capture_dir());
    if (!is_dir($root)) return;
    $limit = time() - OCR_CAPTURE_DAYS * 86400;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $limit) @unlink($file);
        }
        @rmdir($dir);   // only succeeds once empty
    }
}

/**
 * The open competition's captures, oldest first.
 *
 * @return array List of ['name', 'size', 'time', 'meta'].
 */
function ocr_capture_list() {
    $out = [];
    foreach (glob(ocr_capture_dir() . '/*.jpg') ?: [] as $file) {
        $meta = @json_decode((string)@file_get_contents(preg_replace('/\.jpg$/', '.json', $file)), true);
        $out[] = ['name' => basename($file), 'size' => filesize($file), 'time' => filemtime($file),
            'meta' => is_array($meta) ? $meta : []];
    }
    usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $out;
}

/**
 * Store one photo and what was read on it.
 *
 * @param string $tmp Uploaded file.
 * @param array $meta From the browser; only known keys are kept.
 * @return string|null Name of the stored photo, or null when it is not a JPEG.
 */
function ocr_capture_store($tmp, array $meta) {
    $info = @getimagesize($tmp);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) return null;
    $dir = ocr_capture_dir(true);

    // Sequence number, then the barcode when one was read, for a readable listing.
    $n = count(glob($dir . '/*.jpg') ?: []) + 1;
    $code = preg_replace('/[^A-Za-z0-9\-]/', '_', (string)($meta['barcode'] ?? ''));
    do {
        $name = sprintf('%04d', $n++) . ($code !== '' ? '_' . $code : '') . '.jpg';
    } while (is_file($dir . '/' . $name));
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) return null;

    $keep = [
        'barcode' => (string)($meta['barcode'] ?? ''),
        'turn'    => isset($meta['turn']) ? (int)$meta['turn'] : null,
        'quad'    => is_array($meta['quad'] ?? null) ? $meta['quad'] : null,
        'source'  => mb_substr((string)($meta['source'] ?? ''), 0, 40),
        'width'   => $info[0],
        'height'  => $info[1],
        'taken'   => date('c'),
        'tour'    => (int)$_SESSION['TourId'],
    ];
    file_put_contents($dir . '/' . preg_replace('/\.jpg$/', '.json', $name), json_encode($keep));
    return $name;
}

/**
 * Remove all the open competition's captures.
 *
 * @return int Number of photos removed.
 */
function ocr_capture_clear() {
    $n = 0;
    foreach (glob(ocr_capture_dir() . '/*') ?: [] as $file) {
        if (preg_match('/\.jpg$/', $file)) $n++;
        @unlink($file);
    }
    @rmdir(ocr_capture_dir());
    return $n;
}
