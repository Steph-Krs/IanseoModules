<?php
/**
 * AUTH module — tickets.php
 * Filing of a ticket (bug / improvement request) by a signed-in organiser.
 * Managing them (sorting, status, deletion) is done in admin/tickets.php (ADMIN).
 *
 * The wording shared with the competitor's page (booking/public/tickets.php) is read from the
 * "booking" section of the language files, so it is translated once.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

// Reserved to signed-in organisers (or the local console without authentication) — same logic
// as the visibility of the menu entry, independent of AUTH_ROOT.
if (empty($_SESSION['AUTH_User'])
    && !(empty($_SESSION['AUTH_ENABLE']) && isset($acl) && subFeatureAcl($acl, AclRoot, '') >= AclReadOnly)) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}
aut_ensure_schema();

$tk = function ($key, $a = null) { return aut_text($key, $a, 'booking'); };
$e = function ($s) { return htmlspecialchars((string) $s); };

// Competition concerned: the one the organiser has open, if any ("Name (Code)").
$openTour = '';
$openTid = intval($_SESSION['TourId'] ?? 0);
if ($openTid > 0) {
    $tr = safe_fetch(safe_r_sql("SELECT ToName, ToCode FROM Tournament WHERE ToId = " . $openTid));
    if ($tr) { $c = trim((string) $tr->ToCode); $openTour = trim((string) $tr->ToName) . ($c !== '' ? ' (' . $c . ')' : ''); }
}

$ok  = '';
$err = '';
$editId   = intval($_GET['edit'] ?? 0);
$kind     = $_POST['kind'] ?? 'bug';
$title    = trim($_POST['title'] ?? '');
$body     = trim($_POST['body'] ?? '');
$expected = trim($_POST['expected'] ?? '');
$page     = trim($_POST['page'] ?? '');

// Page concerned: filled from the referrer when missing.
if ($page === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($p && stripos($p, '/tickets.php') === false) $page = $p;
}

// Identity of the author (organiser): used for the filing AND for "My tickets".
$user = $_SESSION['AUTH_User'] ?? 'console';
$role = '';
if (isset($_SESSION['AUTH_ROLE'])) {
    $role = (aut_roles()[$_SESSION['AUTH_ROLE']] ?? $_SESSION['AUTH_ROLE'])
          . (($_SESSION['AUTH_SCOPE'] ?? '') !== '' ? ' ' . $_SESSION['AUTH_SCOPE'] : '');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tid = intval($_POST['tid'] ?? 0);
    if (!aut_csrf_check()) {
        $err = aut_t('TkSessionExpired');
        $editId = $tid;
    } elseif ($title === '' || $body === '') {
        $err = $tk('TkNeedTitle');
        $editId = $tid;
    } elseif ($tid > 0) {   // change of an existing ticket
        if (aut_ticket_update($tid, $user, 'org', $kind, $title, $body, $expected, $page)) {
            // POST/Redirect/GET: a refresh posts nothing again.
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/tickets.php?ok=upd'); die();
        } else {
            $err = $tk('TkLocked');
            $editId = 0;
        }
    } else {                // new filing
        aut_ticket_add($kind, $title, $body, $expected, $page, $user, $role, 'org', $openTour);
        CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/tickets.php?ok=new'); die();
    }
}

// Confirmation after the redirection (the POST content is not replayed).
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'upd') $ok = $tk('TkUpdated');
    elseif ($_GET['ok'] === 'new') $ok = aut_t('TkOrgSaved');
}

// A ticket loaded into the form to be changed (GET ?edit=).
if ($editId > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $et = aut_ticket_get($editId);
    if (aut_ticket_editable($et, $user, 'org')) {
        $kind = $et->TkKind; $title = $et->TkTitle; $body = (string) $et->TkBody;
        $expected = (string) $et->TkExpected; $page = (string) $et->TkPage;
    } else {
        $editId = 0;   // not (or no longer) editable
    }
}
// Label shown: the competition frozen in the ticket being edited, otherwise the one open now.
$tourLabel = ($editId > 0 && isset($et) && $et) ? (string) $et->TkTour : $openTour;

$mine = aut_ticket_my($user, 'org');

$isAdmin = !empty($_SESSION['AUTH_ROOT'])
    || (empty($_SESSION['AUTH_ENABLE']) && isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite);

$PAGE_TITLE = aut_t('BarReportTitle');
include('Common/Templates/head.php');
?>
<style>
#aut-tk { max-width:760px; }
#aut-tk h1 { font-size:22px; color:#01367c; margin:0 0 6px; }
#aut-tk .aut-lead { color:#4c4e50; font-size:14px; margin:0 0 18px; }
#aut-tk .aut-card { background:#fff; border:1px solid #d2d4d6; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:18px 20px; }
#aut-tk label { display:block; font-weight:600; font-size:13px; color:#01367c; margin:14px 0 5px; }
#aut-tk input[type=text], #aut-tk textarea { width:100%; padding:9px 11px; font-size:14px;
    font-family:inherit; border:1px solid #d2d4d6; border-radius:6px; background:#fff; color:#20263d; }
#aut-tk textarea:focus, #aut-tk input:focus { outline:none; border-color:#0254a8; box-shadow:0 0 0 3px #a7d6ff; }
#aut-tk .aut-hint { font-size:12px; color:#7d8183; margin:4px 0 0; font-weight:400; }
#aut-tk .aut-kinds { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 4px; }
#aut-tk .aut-kind { flex:1 1 220px; border:2px solid #d2d4d6; border-radius:8px; padding:12px 14px;
    cursor:pointer; display:flex; gap:10px; align-items:flex-start; }
#aut-tk .aut-kind input { margin-top:3px; }
#aut-tk .aut-kind.on { border-color:#0254a8; background:#f0f4ff; }
#aut-tk .aut-kind b { color:#01367c; }
#aut-tk .aut-kind span { display:block; font-size:12px; color:#7d8183; margin-top:2px; }
#aut-tk .aut-btn { display:inline-block; margin-top:18px; padding:11px 22px; font-size:15px;
    font-weight:600; border:1px solid #0254a8; border-radius:6px; background:#0254a8; color:#fff; cursor:pointer; }
#aut-tk .aut-btn:hover { background:#01367c; border-color:#01367c; }
#aut-tk .aut-msg { padding:11px 14px; border-radius:6px; margin:0 0 16px; font-size:14px; }
#aut-tk .aut-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-tk .aut-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#aut-tk .aut-back { font-size:13px; margin-top:16px; }
#aut-tk .aut-mine-h { font-size:17px; color:#01367c; margin:26px 0 10px; }
#aut-tk .aut-mine { display:flex; flex-direction:column; gap:8px; }
#aut-tk .aut-mt { background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8;
    border-radius:8px; padding:10px 14px; }
#aut-tk .aut-mt-new { border-left-color:#0254a8; }
#aut-tk .aut-mt-in_progress { border-left-color:#cb8137; }
#aut-tk .aut-mt-done { border-left-color:#1a8a3f; }
#aut-tk .aut-mt-rejected { border-left-color:#a80000; }
#aut-tk .aut-mt-head { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:12px; color:#7d8183; }
#aut-tk .aut-mt-kind { font-weight:700; color:#01367c; }
#aut-tk .aut-mt-status { margin-left:auto; font-weight:700; }
#aut-tk .aut-s-new { color:#0254a8; }
#aut-tk .aut-s-done { color:#1a8a3f; }
#aut-tk .aut-s-rejected { color:#a80000; }
#aut-tk .aut-mt-title { font-size:15px; color:#20263d; margin:4px 0 0; }
#aut-tk .aut-mt-resp { margin:8px 0 0; padding:8px 10px; font-size:13px; background:#f0f4ff;
    border:1px solid #a7d6ff; border-radius:6px; color:#20263d; }
#aut-tk .aut-s-in_progress { color:#cb8137; }
#aut-tk .aut-mt-score { color:#e6a700; letter-spacing:1px; font-size:13px; }
#aut-tk .aut-mt-score i { color:#d9dce3; font-style:normal; }
#aut-tk .aut-mt-score-n { color:#7d8183; font-size:11px; letter-spacing:0; margin-left:3px; }
#aut-tk .aut-mt-act { margin-top:8px; }
#aut-tk .aut-mt-locked { font-size:12px; color:#7d8183; font-style:italic; }
#aut-tk .aut-editing { background:#fff6e6; border:1px solid #e8b96a; color:#8a5a26; border-radius:6px;
    padding:8px 12px; margin:0 0 12px; font-size:13px; }
</style>
<?php
$root = $CFG->ROOT_DIR;
echo '<div id="aut-tk">' . "\n"
    . '<h1>' . $e(aut_t('BarReportTitle')) . "</h1>\n"
    . '<p class="aut-lead">' . $e($tk('TkIntro')) . "</p>\n";

if ($ok) {
    echo '<div class="aut-msg aut-ok">' . $e($ok)
        . ($isAdmin ? ' <a href="' . $root . 'Modules/Custom/AUTH/admin/tickets.php">' . $e(aut_t('TkSeeAll')) . '</a>.' : '')
        . "</div>\n";
}
if ($err) echo '<div class="aut-msg aut-err">' . $e($err) . "</div>\n";

echo '<form method="post" class="aut-card" id="autform">' . aut_csrf_field()
    . '<input type="hidden" name="tid" value="' . intval($editId) . '">' . "\n";
if ($editId) {
    echo '<div class="aut-editing">' . $e($tk('TkEditing'))
        . ' <a href="' . $root . 'Modules/Custom/AUTH/tickets.php">' . $e($tk('TkCancelEdit')) . "</a></div>\n";
}
echo '<label>' . $e($tk('TkKind')) . "</label>\n"
    . '<div class="aut-kinds">'
    . '<label class="aut-kind" data-kind="bug">'
    . '<input type="radio" name="kind" value="bug"' . ($kind !== 'evolution' ? ' checked' : '') . '>'
    . '<span><b>' . $e($tk('TkBug')) . '</b><span>' . $e($tk('TkBugHint')) . '</span></span></label>'
    . '<label class="aut-kind" data-kind="evolution">'
    . '<input type="radio" name="kind" value="evolution"' . ($kind === 'evolution' ? ' checked' : '') . '>'
    . '<span><b>' . $e($tk('TkEvo')) . '</b><span>' . $e($tk('TkEvoHint')) . '</span></span></label>'
    . "</div>\n"
    . '<label for="tk-title" id="lab-title">' . $e($tk('TkShort')) . '</label>'
    . '<input type="text" id="tk-title" name="title" maxlength="160" value="' . $e($title) . '" placeholder="' . $e($tk('TkShortPh')) . '">' . "\n"
    . '<label for="tk-body" id="lab-body">' . $e($tk('TkDescription')) . '</label>'
    . '<textarea id="tk-body" name="body" rows="4" maxlength="5000">' . $e($body) . '</textarea>'
    . '<p class="aut-hint" id="hint-body"></p>' . "\n"
    . '<label for="tk-expected" id="lab-expected">' . $e($tk('TkDetails')) . '</label>'
    . '<textarea id="tk-expected" name="expected" rows="4" maxlength="5000">' . $e($expected) . '</textarea>'
    . '<p class="aut-hint" id="hint-expected"></p>' . "\n"
    . '<label for="tk-page">' . $e($tk('TkPage')) . ' <span class="aut-hint" style="font-weight:400">' . $e($tk('OptionalParen')) . '</span></label>'
    . '<input type="text" id="tk-page" name="page" maxlength="255" value="' . $e($page) . '" placeholder="' . $e(aut_t('TkOrgPagePh')) . '">' . "\n";
if ($tourLabel !== '') {
    echo '<label>' . $e($tk('TkComp')) . '</label>'
        . '<p class="aut-hint" style="margin:0;font-size:14px;color:#20263d">' . $e($tourLabel)
        . ' <span class="aut-hint">(' . $e(aut_t('TkOrgOpenComp')) . ")</span></p>\n";
}
echo '<button type="submit" class="aut-btn">' . $e($tk($editId ? 'TkUpdateBtn' : 'TkSendBtn')) . "</button>\n"
    . "</form>\n";

if ($mine) {
    $statuses = aut_ticket_statuses();
    $kinds = aut_ticket_kinds();
    echo '<h2 class="aut-mine-h">' . $e($tk('TkMine')) . "</h2>\n" . '<div class="aut-mine">' . "\n";
    foreach ($mine as $t) {
        $st = $statuses[$t->TkStatus] ?? $t->TkStatus;
        $stars = (int) round(intval($t->TkScore) / 20);
        $starsHtml = '';
        for ($i = 0; $i < 5; $i++) $starsHtml .= $i < $stars ? '★' : '<i>☆</i>';
        echo '<div class="aut-mt aut-mt-' . $e($t->TkStatus) . '">'
            . '<div class="aut-mt-head">'
            . '<span class="aut-mt-kind">' . $e($kinds[$t->TkKind] ?? $t->TkKind) . '</span>'
            . '<span class="aut-mt-date">' . $e(date('d/m/Y', strtotime($t->TkCreated))) . '</span>'
            . '<span class="aut-mt-score" title="' . $e($tk('TkScoreTip')) . '">' . $starsHtml
            . '<span class="aut-mt-score-n">' . intval($t->TkScore) . '/100</span></span>'
            . '<span class="aut-mt-status aut-s-' . $e($t->TkStatus) . '">' . $e($st) . '</span>'
            . '</div>'
            . '<div class="aut-mt-title">' . $e($t->TkTitle) . '</div>';
        if (trim((string) ($t->TkTour ?? '')) !== '') {
            echo '<div class="aut-hint" style="margin-top:2px">🏆 ' . $e($t->TkTour) . '</div>';
        }
        if (trim((string) $t->TkResponse) !== '') {
            echo '<div class="aut-mt-resp"><b>' . $e($tk('TkResponse')) . '</b> ' . nl2br($e($t->TkResponse)) . '</div>';
        }
        echo '<div class="aut-mt-act">'
            . (aut_ticket_editable($t, $user, 'org')
                ? '<a class="aut-mt-edit" href="' . $root . 'Modules/Custom/AUTH/tickets.php?edit=' . intval($t->TkId) . '">' . $e($tk('TkEditBtn')) . '</a>'
                : '<span class="aut-mt-locked">' . $e($tk('TkNotEditable')) . '</span>')
            . "</div></div>\n";
    }
    echo "</div>\n";
}

echo '<p class="aut-back"><a href="' . $root . 'index.php">← ' . $e(aut_t('TkBack')) . "</a></p>\n"
    . "</div>\n";

$labels = array();
foreach (array('bug' => 'Bug', 'evolution' => 'Evo') as $k => $p) {
    $labels[$k] = array(
        'title'        => $tk('Tk' . $p . 'Title'),
        'body'         => $tk('Tk' . $p . 'Body'),
        'bodyHint'     => $tk('Tk' . $p . 'BodyHint'),
        'expected'     => $tk('Tk' . $p . 'Expected'),
        'expectedHint' => $tk('Tk' . $p . 'ExpectedHint'),
    );
}
echo '<script>var AUT_TK_LABELS = ' . json_encode($labels) . ";</script>\n";
?>
<script>
(function () {
  var LABELS = AUT_TK_LABELS;
  function apply(kind) {
    var L = LABELS[kind] || LABELS.bug;
    document.getElementById('lab-title').textContent = L.title;
    document.getElementById('lab-body').textContent = L.body;
    document.getElementById('hint-body').textContent = L.bodyHint;
    document.getElementById('lab-expected').textContent = L.expected;
    document.getElementById('hint-expected').textContent = L.expectedHint;
    Array.prototype.forEach.call(document.querySelectorAll('.aut-kind'), function (el) {
      el.classList.toggle('on', el.getAttribute('data-kind') === kind);
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[name="kind"]'), function (r) {
    r.addEventListener('change', function () { apply(this.value); });
  });
  var cur = document.querySelector('input[name="kind"]:checked');
  apply(cur ? cur.value : 'bug');
})();
</script>
<?php
include('Common/Templates/tail.php');
