<?php
/**
 * Deployed from Modules/Custom/AUTH/dist/ — do not edit here, edit the source copy in the
 * module then redeploy (admin/deploy.php).
 *
 * Included by config.php on every page when $CFG->USERAUTH is on.
 */
if (!isset($CFG)) die();

require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');
aut_request_bootstrap();
