<?php
/**
 * AUTH module — admin/tickets.php
 * Management of the tickets (ADMIN only): sorted by date / precision, filtered by status,
 * status changes, deletion. Filing is done in ../tickets.php.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
// same lock as admin/index.php: real ADMIN when the authentication is on
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();

$sort   = ($_REQUEST['sort'] ?? 'date') === 'score' ? 'score' : 'date';
$status = $_REQUEST['status'] ?? '';
if (!array_key_exists($status, aut_ticket_statuses())) $status = '';

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $msg = aut_t('AtSessionExpired');
    } else {
        $id = intval($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'status') {
            aut_ticket_set_status($id, $_POST['value'] ?? '');
            $msg = aut_t('AtStatusSaved');
        } elseif ($action === 'respond') {
            aut_ticket_set_response($id, $_POST['response'] ?? '');
            $msg = aut_t('AtResponseSaved');
        } elseif ($action === 'delete') {
            aut_ticket_delete($id);
            $msg = aut_t('AtDeleted');
        }
    }
}

$counts  = aut_ticket_counts();
$tickets = aut_ticket_list($sort, $status);
$kinds   = aut_ticket_kinds();
$statuses = aut_ticket_statuses();

/** URL of the list keeping the other parameter. */
function tk_url($over = array())
{
    global $CFG, $sort, $status;
    $p = array('sort' => $sort, 'status' => $status);
    foreach ($over as $k => $v) $p[$k] = $v;
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/tickets.php' . ($p ? '?' . http_build_query($p) : '');
}

$PAGE_TITLE = aut_t('MenuTitle') . ' — ' . aut_t('MenuTickets');
include('Common/Templates/head.php');
?>
<style>
#aut-tk { max-width:920px; }
#aut-tk h1 { font-size:22px; color:#01367c; margin:0 0 12px; }
#aut-tk .aut-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px;
    background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-tk .aut-bar { display:flex; flex-wrap:wrap; gap:16px 24px; align-items:center; margin:0 0 16px;
    padding:10px 14px; background:#fff; border:1px solid #d2d4d6; border-radius:8px; }
#aut-tk .aut-tabs a, #aut-tk .aut-sort a { text-decoration:none; color:#4c4e50; font-size:13px;
    padding:5px 10px; border-radius:14px; }
#aut-tk .aut-tabs a.on, #aut-tk .aut-sort a.on { background:#0254a8; color:#fff; }
#aut-tk .aut-grp { display:flex; gap:6px; align-items:center; }
#aut-tk .aut-grp > b { font-size:12px; color:#7d8183; }
#aut-tk .aut-empty { color:#7d8183; font-style:italic; }
#aut-tk .tk { background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8;
    border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 12px; }
#aut-tk .tk-evolution { border-left-color:#1a8a3f; }
#aut-tk .tk-done { opacity:.7; }
#aut-tk .tk-rejected { opacity:.55; }
#aut-tk .tk-head { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:12px; color:#7d8183; }
#aut-tk .tk-badge { font-weight:700; font-size:11px; padding:2px 9px; border-radius:5px;
    background:#eaf2ff; color:#01367c; border:1px solid #c8ddf7; }
#aut-tk .tk-badge.evo { background:#e5f6ea; color:#0d6b2e; border-color:#a9dcb8; }
#aut-tk .tk-stars { color:#e6a700; letter-spacing:1px; }
#aut-tk .tk-stars i { color:#d9dce3; font-style:normal; }
#aut-tk .tk-status { margin-left:auto; font-weight:700; }
#aut-tk .tk-status.new { color:#0254a8; }
#aut-tk .tk-status.in_progress { color:#cb8137; }
#aut-tk .tk-status.done { color:#1a8a3f; }
#aut-tk .tk-status.rejected { color:#a80000; }
#aut-tk .tk h2 { font-size:16px; color:#20263d; margin:8px 0 6px; }
#aut-tk .tk-field { margin:8px 0 0; }
#aut-tk .tk-field b { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#7d8183; }
#aut-tk .tk-field div { white-space:pre-wrap; font-size:14px; color:#20263d; }
#aut-tk .tk-meta { margin-top:8px; font-size:12px; color:#7d8183; }
#aut-tk .tk-chan { font-size:11px; padding:2px 8px; border-radius:5px; background:#eef0f5; color:#4c4e50; }
#aut-tk .tk-resp { margin-top:10px; }
#aut-tk .tk-resp label { display:block; font-size:12px; color:#01367c; font-weight:600; margin-bottom:4px; }
#aut-tk .tk-resp .tk-mut { font-weight:400; color:#7d8183; }
#aut-tk .tk-resp textarea { width:100%; box-sizing:border-box; padding:7px 9px; font-size:13px;
    font-family:inherit; border:1px solid #d2d4d6; border-radius:6px; }
#aut-tk .tk-resp button { margin-top:6px; font-size:12px; padding:6px 12px; border-radius:6px;
    border:1px solid #0254a8; background:#0254a8; color:#fff; cursor:pointer; }
#aut-tk .tk-resp button:hover { background:#01367c; }
#aut-tk .tk-act { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; padding-top:10px; border-top:1px solid #eef; }
#aut-tk .tk-act form { margin:0; }
#aut-tk .tk-act button { font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #d2d4d6;
    background:#f7f7f7; color:#20263d; cursor:pointer; }
#aut-tk .tk-act button:hover { background:#eef2f8; }
#aut-tk .tk-copy { background:#f0f4ff; border-color:#a7d6ff; color:#0254a8; font-weight:600; }
#aut-tk .tk-copy:hover { background:#dbe7ff; }
#aut-tk .tk-copy.ok { background:#d2f4cd; border-color:#75ae77; color:#04ac0b; }
#aut-tk .tk-act .b-del { color:#c0392b; border-color:#e8b4ae; background:#fff; }
#aut-tk .tk-act .b-del:hover { background:#ffd6db; }
</style>
<?php
$e = function ($s) { return htmlspecialchars((string) $s); };
$tab = function ($value, $key, $n) use ($e, $status) {
    return '<a class="' . ($status === $value ? 'on' : '') . '" href="' . $e(tk_url(array('status' => $value))) . '">'
        . $e(aut_t($key, $n)) . '</a>';
};
// One status button of a ticket (a small form that keeps the current view).
$act = function ($t, $action, $value, $label, $title = '', $confirm = '', $class = '') use ($e, $sort, $status) {
    return '<form method="post"' . ($confirm !== '' ? ' data-confirm="' . $e($confirm) . '" onsubmit="return confirm(this.dataset.confirm)"' : '') . '>'
        . aut_csrf_field()
        . '<input type="hidden" name="sort" value="' . $e($sort) . '">'
        . '<input type="hidden" name="status" value="' . $e($status) . '">'
        . '<input type="hidden" name="action" value="' . $action . '">'
        . ($value !== '' ? '<input type="hidden" name="value" value="' . $value . '">' : '')
        . '<input type="hidden" name="id" value="' . intval($t->TkId) . '">'
        . '<button type="submit"' . ($class !== '' ? ' class="' . $class . '"' : '') . ($title !== '' ? ' title="' . $e($title) . '"' : '') . '>'
        . $e($label) . "</button></form>\n";
};

echo '<div id="aut-tk">' . "\n"
    . '<h1>' . $e(aut_t('MenuTickets')) . "</h1>\n"
    . ($msg ? '<div class="aut-msg">' . $e($msg) . "</div>\n" : '')
    . '<div class="aut-bar">'
    . '<div class="aut-grp aut-tabs"><b>' . $e(aut_t('AtStatus')) . '</b>'
    . $tab('', 'AtAll', $counts['all'])
    . $tab('new', 'AtNew', $counts['new'])
    . $tab('in_progress', 'AtInProgress', $counts['in_progress'])
    . $tab('done', 'AtDone', $counts['done'])
    . $tab('rejected', 'AtRejected', $counts['rejected'])
    . '</div>'
    . '<div class="aut-grp aut-sort"><b>' . $e(aut_t('AtSort')) . '</b>'
    . '<a class="' . ($sort === 'date' ? 'on' : '') . '" href="' . $e(tk_url(array('sort' => 'date'))) . '">' . $e(aut_t('AtByDate')) . '</a>'
    . '<a class="' . ($sort === 'score' ? 'on' : '') . '" href="' . $e(tk_url(array('sort' => 'score'))) . '">' . $e(aut_t('AtByScore')) . '</a>'
    . "</div>\n</div>\n";

if (!$tickets) {
    echo '<p class="aut-empty">' . $e(aut_t('AtNone')) . "</p>\n";
}
foreach ($tickets as $t) {
    $isEvo = $t->TkKind === 'evolution';
    $stars = (int) round(intval($t->TkScore) / 20);
    $bodyLab = aut_t($isEvo ? 'AtWish' : 'AtProblem');
    $expLab  = aut_t($isEvo ? 'AtExpectedResult' : 'AtRepro');
    $stLab   = $statuses[$t->TkStatus] ?? $t->TkStatus;
    $kindLab = $kinds[$t->TkKind] ?? $t->TkKind;
    $chanLab = aut_t(($t->TkChannel ?? 'org') === 'archer' ? 'AtCompetitor' : 'AtOrganiser');
    $when = date('d/m/Y H:i', strtotime($t->TkCreated));
    // Structured text ready to paste ("Copy" button).
    $copy = '[' . $kindLab . '] ' . $t->TkTitle . "\n"
          . aut_t('AtCopyOrigin', $chanLab . ' (' . $t->TkUser . ($t->TkRole ? ' — ' . $t->TkRole : '') . ')') . "\n"
          . aut_t('AtCopyStatus', array('status' => $stLab, 'score' => intval($t->TkScore), 'when' => $when)) . "\n"
          . (trim((string) ($t->TkTour ?? '')) !== '' ? aut_t('AtCopyComp', $t->TkTour) . "\n" : '')
          . ($t->TkPage ? aut_t('AtCopyPage', $t->TkPage) . "\n" : '')
          . "\n" . aut_t('AtCopyLabel', $bodyLab) . "\n" . trim((string) $t->TkBody) . "\n"
          . (trim((string) $t->TkExpected) !== ''
              ? "\n" . aut_t('AtCopyLabel', $expLab) . "\n" . trim((string) $t->TkExpected) . "\n" : '');
    $starsHtml = '';
    for ($i = 0; $i < 5; $i++) $starsHtml .= $i < $stars ? '★' : '<i>★</i>';

    echo '<article class="tk tk-' . ($isEvo ? 'evolution' : 'bug') . ' tk-' . $e($t->TkStatus) . '">'
        . '<div class="tk-head">'
        . '<span class="tk-badge ' . ($isEvo ? 'evo' : '') . '">' . $e($kindLab) . '</span>'
        . '<span class="tk-chan">' . $e($chanLab) . '</span>'
        . '<span>' . $e($when) . '</span>'
        . '<span class="tk-stars" title="' . $e(aut_t('AtScoreTip', intval($t->TkScore))) . '">' . $starsHtml . '</span>'
        . '<span class="tk-status ' . $e($t->TkStatus) . '">' . $e($stLab) . '</span>'
        . "</div>\n"
        . '<h2>' . $e($t->TkTitle) . "</h2>\n";
    if (trim((string) $t->TkBody) !== '') {
        echo '<div class="tk-field"><b>' . $e($bodyLab) . '</b><div>' . $e($t->TkBody) . "</div></div>\n";
    }
    if (trim((string) $t->TkExpected) !== '') {
        echo '<div class="tk-field"><b>' . $e($expLab) . '</b><div>' . $e($t->TkExpected) . "</div></div>\n";
    }
    if (trim((string) ($t->TkTour ?? '')) !== '') {
        echo '<p class="tk-meta">🏆 ' . aut_t('AtComp', '<b>' . $e($t->TkTour) . '</b>') . "</p>\n";
    }
    echo '<p class="tk-meta">' . aut_t('AtFiledBy', '<b>' . $e($t->TkUser) . '</b>')
        . ($t->TkRole ? ' (' . $e($t->TkRole) . ')' : '')
        . ($t->TkPage ? ' — ' . $e(aut_t('AtPage', $t->TkPage)) : '') . "</p>\n";

    echo '<form method="post" class="tk-resp">' . aut_csrf_field()
        . '<input type="hidden" name="sort" value="' . $e($sort) . '">'
        . '<input type="hidden" name="status" value="' . $e($status) . '">'
        . '<input type="hidden" name="action" value="respond">'
        . '<input type="hidden" name="id" value="' . intval($t->TkId) . '">'
        . '<label>' . $e(aut_t('AtResponse')) . ' <span class="tk-mut">(' . $e(aut_t('AtResponseVisible')) . ')</span></label>'
        . '<textarea name="response" rows="2" placeholder="' . $e(aut_t('AtResponsePh')) . '">' . $e((string) $t->TkResponse) . '</textarea>'
        . '<button type="submit">' . $e(aut_t('AtResponseSave')) . "</button></form>\n";

    echo '<div class="tk-act">'
        . '<button type="button" class="tk-copy" onclick="tkCopy(this)" title="' . $e(aut_t('AtCopyTip')) . '">📋 ' . $e(aut_t('AtCopy')) . '</button>'
        . '<pre class="tk-copy-src" hidden>' . $e($copy) . "</pre>\n"
        . ($t->TkStatus !== 'in_progress' ? $act($t, 'status', 'in_progress', aut_t('AtTake'), aut_t('AtTakeTip')) : '')
        . ($t->TkStatus !== 'done' ? $act($t, 'status', 'done', aut_t('AtMarkDone')) : '')
        . ($t->TkStatus !== 'rejected' ? $act($t, 'status', 'rejected', aut_t('AtReject')) : '')
        . ($t->TkStatus !== 'new' ? $act($t, 'status', 'new', aut_t('AtReopen')) : '')
        . $act($t, 'delete', '', aut_t('UsDelete'), '', aut_t('AtConfirmDelete'), 'b-del')
        . "</div>\n</article>\n";
}
echo "</div>\n"
    . '<script>var AUT_TK_COPIED = ' . json_encode(aut_t('AtCopied')) . ";</script>\n";
?>
<script>
function tkCopy(btn) {
  var src = btn.closest('.tk').querySelector('.tk-copy-src');
  var txt = src ? src.textContent : '';
  function done() { btn.classList.add('ok'); var o = btn.textContent; btn.textContent = AUT_TK_COPIED;
    setTimeout(function () { btn.textContent = o; btn.classList.remove('ok'); }, 1600); }
  function fallback(t) { var ta = document.createElement('textarea'); ta.value = t;
    ta.style.position = 'fixed'; ta.style.left = '-9999px'; document.body.appendChild(ta);
    ta.focus(); ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(done, function () { fallback(txt); done(); });
  } else { fallback(txt); done(); }
}
</script>
<?php
include('Common/Templates/tail.php');
