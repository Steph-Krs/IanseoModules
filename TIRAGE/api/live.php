<?php
/**
 * JSON endpoint polled by the public screen and the commentators' screen.
 *
 * No ianseo session is needed: the secret token of the link is the access. A
 * poll carries the revision the screen already holds; when nothing moved, the
 * answer is that revision alone and costs one indexed read. The commentators'
 * token also opens the previous-season facts (facts=1), which are heavier and
 * requested only when the lists change.
 *
 * The PHP session is released as soon as the module is booted: a screen polls
 * every second, and a held session lock would queue the requests of any other
 * tab of the same browser behind it.
 */

require_once dirname(__DIR__) . '/lib/boot.php';
session_write_close();

$token = (string)($_GET['t'] ?? '');

if (!empty($_GET['facts'])) {
    require_once dirname(__DIR__) . '/lib/facts.php';
    [$show, $role] = tir_show_by_token($token);
    if (!$show || $role !== 'speaker') JsonOut(['error' => 1, 'msg' => tir_text('ErrLink')]);
    JsonOut(['error' => 0, 'msg' => '', 'dataRevision' => $show['dataRevision'], 'facts' => tir_facts($show)]);
}

$current = tir_revision_by_token($token);
if (!$current) JsonOut(['error' => 1, 'msg' => tir_text('ErrLink')]);

if ($current === (int)($_GET['rev'] ?? 0)) {
    JsonOut(['error' => 0, 'msg' => '', 'revision' => $current]);
}

[$show, $role] = tir_show_by_token($token);
if (!$show) JsonOut(['error' => 1, 'msg' => tir_text('ErrLink')]);
JsonOut(['error' => 0, 'msg' => '', 'revision' => $show['revision'], 'role' => $role, 'state' => tir_state($show, $role)]);
