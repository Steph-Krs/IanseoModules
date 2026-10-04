<?php
/**
 * public/login.php — SINGLE ENTRY.
 *
 * This page no longer handles the sign-in: it REDIRECTS to the server's UNIFIED sign-in page
 * (Custom/AUTH/login.php), competitor tab (?p=comp). One door for the whole server (organiser
 * and competitor) instead of two — which closes the trap of the two sign-in pages (the
 * attestation cookie was not kept because only the unified page stored it).
 *
 * Every former link and redirection (bk_require_archer → 'login.php') therefore ends on the
 * unified page, which handles the competitor flow (federation relay, MFA, keeping the session
 * cookie) and sends to the licensee space once signed in.
 */
require_once __DIR__ . '/boot.php';
global $CFG;
header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp');
exit;
