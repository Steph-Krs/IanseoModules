<?php
/**
 * admin/mandate.php — competition mandate generator (organiser).
 *
 * Two roles:
 *  1. Settings page (ianseo look): template, colour, logos to show, automatic sections to hide,
 *     free text blocks.
 *  2. Preview (?print=1): standalone HTML document in the chosen colours. The rendering itself
 *     lives in bk_mandate_document() (lib), shared with the public view the archers read.
 *  3. Paper (?pdf=1): the same content through the core's PDF classes (lib/mandate-pdf.php).
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e, bk_date_*

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$cfg  = bk_comp_config($TOUR);
$msg  = '';
$err  = '';
$self = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/mandate.php';
$settingsUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (isset($_POST['set_visible'])) {
        // Visibility to the archers (same effect as the box of "What the archers see"). Three
        // states in BcShowMandate: 1 or 0 written explicitly. PRG (reload).
        $v = (intval($_POST['set_visible']) === 1) ? 1 : 0;
        safe_w_sql("UPDATE BookingCompetitions SET BcShowMandate = $v WHERE BcTournament = $TOUR");
        header('Location: ' . $self . ($v ? '?vis=1' : '?vis=0'));
        exit;
    } else {
        bk_mandate_save($TOUR, bk_mandate_from_post($_POST));
        $cfg = bk_comp_config($TOUR);
        $msg = bk_t('AmSaved');
    }
}
if (isset($_GET['vis'])) $msg = bk_t($_GET['vis'] === '1' ? 'AmNowVisible' : 'AmNowHidden');

$m    = bk_mandate_get($cfg);
$data = bk_mandate_data($TOUR);

// Visibility to the archers (three states in BcShowMandate; never visible without a mandate).
$hasMandate     = trim((string) ($cfg->BcMandate ?? '')) !== '';
$mandateVisible = bk_mandate_visible($cfg);

/* ================================================================== */
/* Preview and PDF — the shared renderings do the work (lib)          */
/* ================================================================== */
// bytes: an ASCII server variable
$scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$abs    = ($_SERVER['HTTP_HOST'] ?? '') ? $scheme . '://' . $_SERVER['HTTP_HOST'] : '';
$pdfIcon = '<img src="' . bk_e($CFG->ROOT_DIR . 'Common/Images/pdf.gif') . '" alt=""> ';
if (!empty($_GET['pdf']) && $data) {
    require_once dirname(__DIR__) . '/lib/mandate-pdf.php';
    bk_mandate_pdf($TOUR, $data, $m, array(
        'regUrl'  => $abs . bk_public_url('competition.php?t=' . $TOUR),
        'shopUrl' => $abs . bk_public_url('shop.php?t=' . $TOUR),
    ));
}
if (!empty($_GET['print']) && $data) {
    bk_mandate_document($data, $m, array(
        // Organiser: ianseo session open → logos through TourLogo.php.
        'logo'    => function ($type, $w) use ($CFG) {
            return $CFG->ROOT_DIR . 'Common/TourLogo.php?Type=' . $type . '&W=' . intval($w);
        },
        'regUrl'  => $abs . bk_public_url('competition.php?t=' . $TOUR),
        'shopUrl' => $abs . bk_public_url('shop.php?t=' . $TOUR),
        'toolbar' => '<a class="mn-print" href="' . bk_e($self) . '?pdf=1" target="_blank" rel="noopener">' . $pdfIcon . bk_e(bk_t('PrintOrPdf')) . '</a>'
                   . '<a class="mn-close" href="' . bk_e($self) . '">' . bk_e(bk_t('AmBackSettings')) . '</a>',
    ));
    exit;
}

/* ================================================================== */
/* Settings page                                                      */
/* ================================================================== */
$PAGE_TITLE = bk_t('MnuMandate');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm label { display:block; font-size:13px; font-weight:600; color:#01367c; margin:10px 0 3px; }
#bkadm select, #bkadm textarea, #bkadm input[type=text] { width:100%; max-width:520px; padding:7px 9px;
    border:1px solid #cfd3d6; border-radius:6px; font:inherit; font-size:14px; }
#bkadm textarea { min-height:60px; resize:vertical; }
#bkadm .bk-check { display:flex; align-items:center; gap:8px; font-weight:400; margin:6px 0; }
#bkadm .bk-check input { width:auto; }
#bkadm .bk-hint { font-size:12px; color:#7d8183; margin:4px 0 0; }
#bkadm .bk-btn { padding:8px 16px; border:1px solid #d2d4d6; border-radius:6px;
    background:#f7f7f7; color:#20263d; font-size:14px; cursor:pointer; text-decoration:none;
    display:inline-block; }
#bkadm .bk-btn-primary { background:#0254a8; border-color:#0254a8; color:#fff; font-weight:600; }
#bkadm .bk-cols { display:flex; flex-wrap:wrap; gap:6px 24px; }
#bkadm .bk-two { display:flex; flex-wrap:wrap; gap:2px 28px; }
#bkadm .bk-two > div { flex:1 1 240px; min-width:0; }
#bkadm .bk-color-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
#bkadm input[type=color] { width:48px; height:34px; padding:0; border:1px solid #cfd3d6; border-radius:6px; cursor:pointer; }
#bkadm .bk-swatch { width:26px; height:26px; border-radius:6px; border:1px solid rgba(0,0,0,.15); cursor:pointer; }
#bkadm .bk-disabled { color:#a2a6a9; }
#bkadm .bk-vis { border-left:4px solid #cfd3d6; }
#bkadm .bk-vis.on  { border-left-color:#1a8a3f; background:#f2fbf4; }
#bkadm .bk-vis.off { border-left-color:#cb8137; background:#fdf7ee; }
#bkadm .bk-vis.on  .bk-vis-state { color:#1a8a3f; }
#bkadm .bk-vis.off .bk-vis-state { color:#c0392b; }
</style>
<?php
$check = function ($name, $checked, $label, $extra = '', $cls = '') {
    return '<label class="bk-check' . $cls . '"><input type="checkbox" name="' . bk_e($name) . '" value="1"'
        . ($checked ? ' checked' : '') . $extra . '> ' . bk_e($label) . '</label>';
};
$select = function ($name, $list, $current) {
    $o = '';
    foreach ($list as $k => $lab) $o .= '<option value="' . bk_e($k) . '"' . ($current === $k ? ' selected' : '') . '>' . bk_e($lab) . '</option>';
    return '<select id="' . $name . '" name="' . $name . '">' . $o . '</select>';
};

$out = '<div id="bkadm"><h1>' . bk_e(bk_t('MnuMandate')) . '</h1>'
    . '<p style="font-size:13px"><a href="' . $settingsUrl . '">← ' . bk_e(bk_t('Brand')) . '</a></p>'
    . ($msg ? '<div class="bk-msg bk-ok">' . bk_e($msg) . '</div>' : '')
    . ($err ? '<div class="bk-msg bk-err">' . bk_e($err) . '</div>' : '');

// Visibility to the archers.
$out .= '<div class="bk-sec bk-vis ' . ($mandateVisible ? 'on' : 'off') . '"><h2 style="margin-bottom:6px">' . bk_e(bk_t('AmVisTitle')) . '</h2>';
if (!$hasMandate) {
    $out .= '<p style="margin:0;font-size:13px">' . bk_t('AmNotSaved') . '</p>';
} else {
    $out .= '<p style="margin:0 0 8px;font-size:14px">' . bk_e(bk_t('AmState')) . ' <b class="bk-vis-state">'
        . bk_e(bk_t($mandateVisible ? 'AmVisible' : 'AmHidden')) . '</b> <span class="bk-hint" style="display:inline">'
        . bk_e(bk_t('AmWhere')) . '</span></p>'
        . '<form method="post" style="margin:0">' . bk_csrf_field()
        . '<input type="hidden" name="set_visible" value="' . ($mandateVisible ? '0' : '1') . '">'
        . '<button type="submit" class="bk-btn' . ($mandateVisible ? '' : ' bk-btn-primary') . '">'
        . bk_e(bk_t($mandateVisible ? 'AmHideBtn' : 'AmShowBtn')) . '</button></form>';
}
$out .= '<p class="bk-hint" style="margin-top:8px">' . bk_t('AmSameBox', bk_e($settingsUrl)) . '</p></div>'
    . '<p style="font-size:13px; color:#4c4e50; max-width:640px">' . bk_t('AmIntro') . '</p>';

// Templates and colour.
$swatches = '';
foreach (array('#0254a8', '#c0392b', '#1a8a3f', '#7d3c98', '#d35400', '#00838f', '#e67e22', '#20263d') as $sw) {
    $swatches .= '<span class="bk-swatch" style="background:' . bk_e($sw) . '" data-c="' . bk_e($sw) . '" title="' . bk_e($sw) . '"></span>';
}
$out .= '<form method="post">' . bk_csrf_field()
    . '<div class="bk-sec"><h2>' . bk_e(bk_t('AmTplTitle')) . '</h2><div class="bk-two">'
    . '<div><label for="template">' . bk_e(bk_t('AmTplMandate')) . '</label>' . $select('template', bk_mandate_templates(), $m['template'])
    . '<p class="bk-hint">' . bk_e(bk_t('AmTplMandateHint')) . '</p></div>'
    . '<div><label for="share_template">' . bk_e(bk_t('AmTplShare')) . '</label>' . $select('share_template', bk_share_templates(), $m['share_template'])
    . '<p class="bk-hint">' . bk_e(bk_t('AmTplShareHint')) . '</p></div></div>'
    . '<label for="color">' . bk_e(bk_t('AmColour')) . '</label><div class="bk-color-row">'
    . '<input type="color" id="color" name="color" value="' . bk_e($m['color']) . '">'
    . '<input type="text" id="colorhex" value="' . bk_e($m['color']) . '" maxlength="7" style="width:110px; font-family:monospace" aria-label="' . bk_e(bk_t('AmHex')) . '">'
    . '<span style="font-size:12px; color:#7d8183">' . bk_e(bk_t('AmFreeChoice')) . '</span>' . $swatches . '</div>'
    . '<p class="bk-hint">' . bk_e(bk_t('AmColourHint')) . '</p>';
echo $out;
?>
  <script>
  (function () {
    var col = document.getElementById('color'), hex = document.getElementById('colorhex');
    function norm(v){ v = (v || '').trim(); if (v && v[0] !== '#') v = '#' + v; return v; }
    col.addEventListener('input', function () { hex.value = col.value; });
    hex.addEventListener('input', function () { var v = norm(hex.value); if (/^#[0-9a-fA-F]{6}$/.test(v)) col.value = v; });
    hex.addEventListener('blur', function () { hex.value = col.value; });
    Array.prototype.forEach.call(document.querySelectorAll('.bk-swatch'), function (s) {
      s.addEventListener('click', function () { col.value = s.getAttribute('data-c'); hex.value = col.value; });
    });
  })();
  </script>
<?php
// Logos, automatic sections, free blocks.
$out = '</div><div class="bk-sec"><h2>' . bk_e(bk_t('AmLogos')) . '</h2>'
    . '<p class="bk-hint" style="margin-top:0">' . bk_t('AmLogosHint', bk_e($CFG->ROOT_DIR . 'Tournament/ManLogo.php')) . '</p>';
$has = $data
    ? array('L' => intval($data['tour']->HasL), 'R' => intval($data['tour']->HasR), 'B' => intval($data['tour']->HasB))
    : array('L' => 0, 'R' => 0, 'B' => 0);
foreach (array('L' => 'AmLogoL', 'R' => 'AmLogoR', 'B' => 'AmLogoB') as $k => $key) {
    $present = $has[$k] > 0;
    $out .= $check('logo_' . $k, !empty($m['logos'][$k]) && $present, bk_t($key) . ($present ? '' : ' — ' . bk_t('AmNoImage')),
        $present ? '' : ' disabled', $present ? '' : ' bk-disabled');
}
$out .= '</div><div class="bk-sec"><h2>' . bk_e(bk_t('AmAutoTitle')) . '</h2><div class="bk-cols">';
foreach (bk_mandate_auto_sections() as $k => $lab) $out .= $check('show_' . $k, !empty($m['show'][$k]), $lab);
$out .= '</div></div><div class="bk-sec"><h2>' . bk_e(bk_t('AmBlocksTitle')) . '</h2>'
    . '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('AmBlocksHint')) . '</p>';
foreach (bk_mandate_sections() as $k => $lab) {
    $out .= '<label for="block_' . bk_e($k) . '">' . bk_e($lab) . '</label>'
        . '<textarea id="block_' . bk_e($k) . '" name="block_' . bk_e($k) . '" maxlength="4000">' . bk_e($m['blocks'][$k] ?? '') . '</textarea>';
}
$out .= '</div><p><button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('AmSave')) . '</button> &nbsp; '
    . '<a class="bk-btn" href="' . bk_e($self) . '?print=1" target="_blank" rel="noopener">' . bk_e(bk_t('AmPreview')) . '</a> &nbsp; '
    . '<a class="bk-btn" href="' . bk_e($self) . '?pdf=1" target="_blank" rel="noopener">' . $pdfIcon . bk_e(bk_t('PrintOrPdf')) . '</a></p>'
    . '</form></div>';
echo $out;
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
