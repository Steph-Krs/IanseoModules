<?php
/**
 * Brings the theme to the pages that print no menu (popup windows, the Speaker
 * pages...), and to the head of every other page.
 *
 * Nothing in Modules/Custom/ is loaded on a page that prints no menu. The core
 * does include Common/DebugOverrides.php on every request, from config.php, and
 * its updater leaves that file alone ("overrides are personal"). When an
 * administrator enables it on the module's page, a marked block is added to that
 * file, which includes this one if it exists: uninstalling the module is enough
 * to switch the block off.
 *
 * This runs on EVERY request, before the session and the database exist, so it
 * must stay trivial: it reads one cookie and, when the choice adds anything to a
 * page, starts an output filter. The filter only ever touches the head of an
 * HTML page that uses the core's colours (see thm_ob_filter()).
 */

if (PHP_SAPI === 'cli' || !empty($GLOBALS['_thm_hooked'])) return;

require_once __DIR__ . '/lib/theme.php';

thm_hook_start();
