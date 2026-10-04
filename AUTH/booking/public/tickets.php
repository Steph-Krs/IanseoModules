<?php
/**
 * booking/public/tickets.php — report a bug / suggest an improvement (competitor).
 *
 * Reuses the ticket system of the AUTH module (merged). The competitor is identified by their
 * licence (bk_require_archer); channel 'archer' → they only see their own tickets. Public #bk
 * look, booking CSRF.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib.php';   // aut_ticket_* functions of the AUTH module

$archer = bk_require_archer();
aut_ensure_schema();

$ok  = '';
$err = '';
$editId   = intval($_GET['edit'] ?? 0);
$kind     = $_POST['kind'] ?? 'bug';
$title    = trim($_POST['title'] ?? '');
$body     = trim($_POST['body'] ?? '');
$expected = trim($_POST['expected'] ?? '');
$page     = trim($_POST['page'] ?? '');
if ($page === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($p && stripos($p, '/tickets.php') === false) $page = $p;
}

$lic = $archer->BaLicence;

// Competition concerned: the id comes from the hidden field (POST), from ?t=, or from the page
// the archer comes from (referer ?t=). The NAME is read from the database (id → "Name (Code)"):
// the label is never given by the client, only an id from which the real name is read.
$tourId = intval($_POST['tour_id'] ?? $_GET['t'] ?? 0);
if ($tourId <= 0 && !empty($_SERVER['HTTP_REFERER'])) {
    parse_str((string) parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $rq);
    if (!empty($rq['t'])) $tourId = intval($rq['t']);
}
$tourLabel = '';
if ($tourId > 0) {
    $tr = safe_fetch(safe_r_sql("SELECT ToName, ToCode FROM Tournament WHERE ToId = " . $tourId));
    if ($tr) { $tc = trim((string) $tr->ToCode); $tourLabel = trim((string) $tr->ToName) . ($tc !== '' ? ' (' . $tc . ')' : ''); }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tid = intval($_POST['tid'] ?? 0);
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired'); $editId = $tid;
    } elseif ($title === '' || $body === '') {
        $err = bk_t('TkNeedTitle'); $editId = $tid;
    } elseif ($tid > 0) {
        if (aut_ticket_update($tid, $lic, 'archer', $kind, $title, $body, $expected, $page)) {
            bk_redirect('tickets.php?ok=upd');   // POST/Redirect/GET: F5 posts nothing again
        } else {
            $err = bk_t('TkLocked'); $editId = 0;
        }
    } else {
        aut_ticket_add($kind, $title, $body, $expected, $page, $lic,
            trim($archer->BaFamilyName . ' ' . $archer->BaName), 'archer', $tourLabel);
        bk_redirect('tickets.php?ok=new');
    }
}

// Confirmation after the redirection (the POST is not replayed on refresh).
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'upd') $ok = bk_t('TkUpdated');
    elseif ($_GET['ok'] === 'new') $ok = bk_t('TkSaved');
}

// A ticket loaded in the form to be changed (GET ?edit=).
if ($editId > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $et = aut_ticket_get($editId);
    if (aut_ticket_editable($et, $lic, 'archer')) {
        $kind = $et->TkKind; $title = $et->TkTitle; $body = (string) $et->TkBody;
        $expected = (string) $et->TkExpected; $page = (string) $et->TkPage;
        $tourLabel = (string) $et->TkTour;   // competition frozen with the ticket being edited
    } else {
        $editId = 0;
    }
}

$mine     = aut_ticket_my($lic, 'archer');
$kinds    = aut_ticket_kinds();
$statuses = aut_ticket_statuses();

bk_head(bk_t('NavReport'));
?>
<style>
#bk .bktk-kinds { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 6px; }
#bk .bktk-kind { flex:1 1 220px; border:2px solid #d2d4d6; border-radius:8px; padding:12px 14px;
    cursor:pointer; display:flex; gap:10px; align-items:flex-start; background:#fff; }
#bk .bktk-kind input { margin-top:3px; }
#bk .bktk-kind.on { border-color:#0254a8; background:#f0f4ff; }
#bk .bktk-kind b { color:#01367c; }
#bk .bktk-kind span { display:block; font-size:12px; color:#7d8183; margin-top:2px; }
#bk .bktk-mine { display:flex; flex-direction:column; gap:8px; margin-top:10px; }
#bk .bktk-mt { background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8;
    border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:10px 14px; }
#bk .bktk-mt-new { border-left-color:#0254a8; }
#bk .bktk-mt-in_progress { border-left-color:#cb8137; }
#bk .bktk-mt-done { border-left-color:#1a8a3f; }
#bk .bktk-mt-rejected { border-left-color:#a80000; }
#bk .bktk-mt-head { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:12px; color:#7d8183; }
#bk .bktk-mt-kind { font-weight:700; color:#01367c; }
#bk .bktk-mt-status { margin-left:auto; font-weight:700; }
#bk .bktk-s-new { color:#0254a8; }
#bk .bktk-s-done { color:#1a8a3f; }
#bk .bktk-s-rejected { color:#a80000; }
#bk .bktk-mt-title { font-size:15px; color:#20263d; margin:4px 0 0; }
#bk .bktk-mt-resp { margin:8px 0 0; padding:8px 10px; font-size:13px; background:#f0f4ff;
    border:1px solid #a7d6ff; border-radius:6px; }
#bk .bktk-s-in_progress { color:#cb8137; }
#bk .bktk-mt-score { color:#e6a700; letter-spacing:1px; font-size:13px; }
#bk .bktk-mt-score i { color:#d9dce3; font-style:normal; }
#bk .bktk-mt-score-n { color:#7d8183; font-size:11px; letter-spacing:0; margin-left:3px; }
#bk .bktk-mt-act { margin-top:10px; }
#bk .bktk-mt-act .bk-btn { margin-top:0; padding:6px 12px; font-size:13px; }
#bk .bktk-mt-locked { font-size:12px; color:#7d8183; font-style:italic; }
#bk .bktk-editing { background:#fff6e6; border:1px solid #e8b96a; color:#8a5a26; border-radius:6px;
    padding:8px 12px; margin:0 0 12px; font-size:13px; }
</style>
<?php
$radio = function ($value, $checked, $titleKey, $hintKey) {
    return '<label class="bktk-kind" data-kind="' . $value . '"><input type="radio" name="kind" value="' . $value . '"' . ($checked ? ' checked' : '') . '>'
        . '<span><b>' . bk_e(bk_t($titleKey)) . '</b><span>' . bk_e(bk_t($hintKey)) . '</span></span></label>';
};
$out = '<h1>' . bk_e(bk_t('NavReportTitle')) . '</h1>'
    . '<p class="bk-hint" style="margin:0 0 16px">' . bk_e(bk_t('TkIntro')) . '</p>'
    . ($ok ? bk_msg('ok', $ok) : '') . ($err ? bk_msg('err', $err) : '')
    . '<form method="post" class="bk-block" id="bktkform">' . bk_csrf_field()
    . '<input type="hidden" name="tid" value="' . intval($editId) . '">'
    . ($editId ? '<div class="bktk-editing">' . bk_e(bk_t('TkEditing')) . ' <a href="' . bk_e(bk_public_url('tickets.php')) . '">'
        . bk_e(bk_t('TkCancelEdit')) . '</a></div>' : '')
    . '<label>' . bk_e(bk_t('TkKind')) . '</label><div class="bktk-kinds">'
    . $radio('bug', $kind !== 'evolution', 'TkBug', 'TkBugHint')
    . $radio('evolution', $kind === 'evolution', 'TkEvo', 'TkEvoHint') . '</div>'
    . '<label for="tk-title" id="lab-title">' . bk_e(bk_t('TkShort')) . '</label>'
    . '<input type="text" id="tk-title" name="title" maxlength="160" value="' . bk_e($title) . '" placeholder="' . bk_e(bk_t('TkShortPh')) . '">'
    . '<label for="tk-body" id="lab-body">' . bk_e(bk_t('TkDescription')) . '</label>'
    . '<textarea id="tk-body" name="body" rows="4" maxlength="5000">' . bk_e($body) . '</textarea><p class="bk-hint" id="hint-body"></p>'
    . '<label for="tk-expected" id="lab-expected">' . bk_e(bk_t('TkDetails')) . '</label>'
    . '<textarea id="tk-expected" name="expected" rows="4" maxlength="5000">' . bk_e($expected) . '</textarea><p class="bk-hint" id="hint-expected"></p>'
    . '<label for="tk-page">' . bk_e(bk_t('TkPage')) . ' <span class="bk-hint" style="font-weight:400">' . bk_e(bk_t('OptionalParen')) . '</span></label>'
    . '<input type="text" id="tk-page" name="page" maxlength="255" value="' . bk_e($page) . '" placeholder="' . bk_e(bk_t('TkPagePh')) . '">'
    . ((!$editId && $tourId > 0) ? '<input type="hidden" name="tour_id" value="' . intval($tourId) . '">' : '')
    . ($tourLabel !== '' ? '<label>' . bk_e(bk_t('TkComp')) . '</label><p class="bk-hint" style="margin:0;font-size:14px;color:#20263d">🏆 ' . bk_e($tourLabel) . '</p>' : '')
    . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t($editId ? 'TkUpdateBtn' : 'TkSendBtn')) . '</button></form>';

if ($mine) {
    $out .= '<h2 style="font-size:17px;color:#01367c;margin:24px 0 10px">' . bk_e(bk_t('TkMine')) . '</h2><div class="bktk-mine">';
    foreach ($mine as $t) {
        $st = $statuses[$t->TkStatus] ?? $t->TkStatus;
        $stars = (int) round(intval($t->TkScore) / 20);
        $starHtml = '';
        for ($i = 0; $i < 5; $i++) $starHtml .= $i < $stars ? '★' : '<i>☆</i>';
        $out .= '<div class="bktk-mt bktk-mt-' . bk_e($t->TkStatus) . '"><div class="bktk-mt-head">'
            . '<span class="bktk-mt-kind">' . bk_e($kinds[$t->TkKind] ?? $t->TkKind) . '</span>'
            . '<span>' . bk_e(date('d/m/Y', strtotime($t->TkCreated))) . '</span>'
            . '<span class="bktk-mt-score" title="' . bk_e(bk_t('TkScoreTip')) . '">' . $starHtml
            . ' <span class="bktk-mt-score-n">' . intval($t->TkScore) . '/100</span></span>'
            . '<span class="bktk-mt-status bktk-s-' . bk_e($t->TkStatus) . '">' . bk_e($st) . '</span></div>'
            . '<div class="bktk-mt-title">' . bk_e($t->TkTitle) . '</div>'
            . (trim((string) ($t->TkTour ?? '')) !== '' ? '<div class="bk-hint" style="margin-top:2px">🏆 ' . bk_e($t->TkTour) . '</div>' : '')
            . (trim((string) $t->TkResponse) !== '' ? '<div class="bktk-mt-resp"><b>' . bk_e(bk_t('TkResponse')) . '</b> ' . nl2br(bk_e($t->TkResponse)) . '</div>' : '')
            . '<div class="bktk-mt-act">' . (aut_ticket_editable($t, $lic, 'archer')
                ? '<a class="bk-btn" href="' . bk_e(bk_public_url('tickets.php?edit=' . intval($t->TkId))) . '">' . bk_e(bk_t('TkEditBtn')) . '</a>'
                : '<span class="bktk-mt-locked">' . bk_e(bk_t('TkNotEditable')) . '</span>') . '</div></div>';
    }
    $out .= '</div>';
}
echo $out;
$labels = array(
    'bug' => array('title' => bk_t('TkBugTitle'), 'body' => bk_t('TkBugBody'), 'bodyHint' => bk_t('TkBugBodyHint'),
        'expected' => bk_t('TkBugExpected'), 'expectedHint' => bk_t('TkBugExpectedHint')),
    'evolution' => array('title' => bk_t('TkEvoTitle'), 'body' => bk_t('TkEvoBody'), 'bodyHint' => bk_t('TkEvoBodyHint'),
        'expected' => bk_t('TkEvoExpected'), 'expectedHint' => bk_t('TkEvoExpectedHint')),
);
?>
<script>
(function () {
  var L = <?= json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  function apply(k) {
    var d = L[k] || L.bug;
    document.getElementById('lab-title').textContent = d.title;
    document.getElementById('lab-body').textContent = d.body;
    document.getElementById('hint-body').textContent = d.bodyHint;
    document.getElementById('lab-expected').textContent = d.expected;
    document.getElementById('hint-expected').textContent = d.expectedHint;
    Array.prototype.forEach.call(document.querySelectorAll('.bktk-kind'), function (el) {
      el.classList.toggle('on', el.getAttribute('data-kind') === k);
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[name="kind"]'), function (r) {
    r.addEventListener('change', function () { apply(this.value); });
  });
  var c = document.querySelector('input[name="kind"]:checked');
  apply(c ? c.value : 'bug');
})();
</script>
<?php
bk_foot();
