<?php
/**
 * Scheduled task of the module: run it every minute from the system's scheduler.
 *
 *     * * * * * www-data nice -n 10 /usr/bin/php <ianseo>/Modules/Custom/AUTO_SEND/cron.php 2>&1 | logger -t ianseo-autosend
 *
 * The exact line for this installation is shown on the module's page, and the
 * README explains how to install it. See lib/runner.php for what a run does and
 * why it runs every minute.
 *
 * Silent when all is well: whatever it prints is an error worth reading in the
 * system log.
 */

require __DIR__ . '/lib/cli.php';
require_once __DIR__ . '/lib/runner.php';

aus_tick();
