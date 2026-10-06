<?php
/**
 * staff/api/logout.php — the volunteer leaves the till on this phone (POST, JSON).
 * Closes this phone's session only; the volunteer stays in the team and may sign in again.
 */

require_once dirname(__DIR__) . '/boot.php';

shp_api_guard(true);

shp_staff_session_close();
shp_json(array('error' => 0, 'msg' => ''));
