<?php
/**
 * public/legal-accept.php — acceptance of the terms of use by a connected ARCHER.
 *
 * BLOCKING screen: bk_require_archer() sends here every connected archer who has not accepted
 * the current version of the terms. The acceptance is TIMESTAMPED (BookingArchers.BaCguAt) and
 * VERSIONED (BaCguVer). bk_require_archer exempts this page itself (no loop).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__, 2) . '/legal-lib.php';

$archer = bk_require_archer();

// Already accepted → their space.
if (aut_legal_archer_ok($archer)) bk_redirect('index.php');

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (empty($_POST['accept'])) {
        $err = bk_t('CguTickBox');
    } else {
        aut_legal_archer_record($archer->BaId);
        bk_log('CGU_ACCEPT', $archer->BaLicence);
        bk_redirect('index.php');
    }
}

bk_head(bk_t('CguHeadTitle'), 'card');
?>
<style>
/* The "card" layout of bk.css caps .bk-main at 420px: widened here, and .bk-cgu fills 100%
   (never a vw value, which would overflow). Centred by #bk.bk-card. */
#bk.bk-card .bk-main { max-width:820px; }
#bk .bk-cgu { width:100%; max-width:100%; box-sizing:border-box; }
#bk .bk-cgu-box { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:4px 16px;
    max-height:46vh; overflow:auto; box-shadow:inset 0 -10px 12px -12px rgba(0,0,0,.15); text-align:left;
    box-sizing:border-box; overflow-wrap:anywhere; }
#bk .bk-cgu-box h2 { font-size:15px; color:#0254a8; margin:15px 0 5px; }
#bk .bk-cgu-box h2:first-child { margin-top:8px; }
#bk .bk-cgu-box p, #bk .bk-cgu-box li { font-size:13px; }
#bk .bk-cgu-box ul { padding-left:20px; }
#bk .bk-cgu .lg-note { margin:14px 0 0; padding:10px 12px; background:#fdf7e6; border:1px solid #eadfb8; border-radius:8px; text-align:left; }
#bk .bk-cgu .lg-note p { margin:0; font-size:12px; color:#6a5a2a; }
#bk .bk-cgu-links { margin:12px 0; font-size:13px; text-align:left; }
#bk .bk-cgu-links a { margin-right:14px; }
#bk .bk-cgu-accept { display:flex; align-items:flex-start; gap:10px; margin:14px 0; font-size:14px; text-align:left; }
#bk .bk-cgu-accept input { margin-top:3px; width:18px; height:18px; }
#bk .bk-cgu-row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
#bk .bk-cgu-out { color:#8a92a0; text-decoration:none; font-size:13px; }
</style>
<?php
$link = function ($doc, $key) {
    return '<a href="' . bk_e(aut_legal_url($doc)) . '" target="_blank" rel="noopener">' . bk_e(bk_t($key)) . '</a> ';
};
echo '<div class="bk-cgu"><h1 style="text-align:left">' . bk_e(bk_t('CguTitle')) . '</h1>'
    . '<p class="bk-hint" style="text-align:left">' . bk_e(bk_t('CguIntro')) . '</p>'
    . ($err ? bk_msg('err', $err) : '')
    . '<div class="bk-cgu-box">' . aut_legal_render('cgu') . '</div>'
    . '<p class="bk-cgu-links">' . bk_e(bk_t('CguFullDocs')) . ' '
    . $link('cgu', 'CguShort') . $link('confidentialite', 'Privacy') . $link('mentions', 'LegalNotice') . $link('cookies', 'Cookies') . '</p>'
    . '<form method="post">' . bk_csrf_field()
    . '<label class="bk-cgu-accept"><input type="checkbox" name="accept" value="1"><span>'
    . bk_t('CguAccept', bk_e(aut_legal_version())) . '</span></label>'
    . '<div class="bk-cgu-row"><button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('CguAcceptBtn')) . '</button>'
    . '<a class="bk-cgu-out" href="' . bk_e(bk_public_url('logout.php')) . '">' . bk_e(bk_t('CguRefuse')) . '</a></div>'
    . '</form></div>';
bk_foot();
