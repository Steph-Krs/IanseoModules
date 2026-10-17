<?php
/**
 * booking/public/badge.php — picture of a national distinction badge (SVG).
 *
 * Public and standalone: a badge is the same drawing for everybody, so this endpoint loads
 * neither ianseo nor the session, only the files of booking/distinctions/. Every parameter is
 * checked against rules.json: nothing else can be drawn, and the text written on the badge is
 * one the rules give.
 *   d = discipline (indoor, outdoor_int…)   s = set   c = column (CL, CO…)   l = level (green … star3)
 *   t = text written on the badge (18m, 70m…)   v = version (bk_dist_version), cache key only
 */
require_once dirname(__DIR__) . '/lib/distinctions.php';

$badge = bk_dist_badge((string) ($_GET['d'] ?? ''), (string) ($_GET['s'] ?? ''), (string) ($_GET['c'] ?? ''),
                       (string) ($_GET['l'] ?? ''), (string) ($_GET['t'] ?? ''));
if (!$badge) { http_response_code(404); exit; }

$svg = bk_dist_svg($badge['family'], $badge['layers'], $badge['set'], $err);
if ($svg === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $err;
    exit;
}

$etag = '"' . md5($svg) . '"';
header('Content-Type: image/svg+xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Opened on its own, the picture runs nothing.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
// A versioned address never changes content; without the version, a short cache only.
header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? 31536000 : 600));
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
echo $svg;
