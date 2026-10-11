<?php
/**
 * desk/api/file.php — an archer's file at the desk (GET ?a=<account>): identity, licence, entries,
 * decisions, draw weights, notes, and what they owe for a volunteer of the registry or the
 * payments. Any right at the desk.
 */

require_once dirname(__DIR__, 2) . '/staff/boot.php';
require_once dirname(__DIR__, 2) . '/lib/desk.php';

shp_api_guard(false);
$me = shp_require_desk();
$file = shp_desk_file($me, (string) ($_GET['a'] ?? ''));
if (!$file) shp_json_error('account', shp_t('DkErrArcher'), 404);
shp_json(array('error' => 0, 'file' => $file));
