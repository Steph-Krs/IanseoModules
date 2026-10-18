<?php
/**
 * admin/distinctions.php — gallery of the national distinction badges.
 *
 * Every badge the rules know, drawn from the files of booking/distinctions/, with the score to
 * reach, and how to change a picture. Read only: the files are edited outside ianseo (text
 * editor or Inkscape), and this page shows the result as soon as it is reloaded — the picture
 * addresses carry bk_dist_version(), which changes with any file of the folder.
 *
 * Same guard as the update pages: AclRoot, plus the server administrator view when an account
 * is signed in.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/adm-lib.php');
require_once(dirname(__DIR__, 2) . '/_shared/update-lib.php');
require_once(dirname(__DIR__) . '/booking/lib/distinctions.php');

upd_admin_guard();

$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$bt = function ($k, $a = null) { return aut_text($k, $a, 'booking'); };
$base = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/';

$errors = array();
$rules = bk_dist_rules($err);
if ($err !== '') $errors[] = $err;
bk_dist_looks($err);
if ($err !== '') $errors[] = $err;
foreach (array('level', 'star') as $fam) {
    if (!$errors && bk_dist_svg($fam, array(), array(), $err) === '') $errors[] = $err;
}

$PAGE_TITLE = aut_t('MenuDistinctions');
$JS_SCRIPT = aut_adm_assets(array(), false);
include('Common/Templates/head.php');
echo aut_adm_open_plain('admin/distinctions.php');

// Colours from the core's variables (colors.css): the THEME module's dark mode follows them.
echo '<style>
#aut-dist h1 { font-size:22px; color:var(--header-dark, #004488); margin:0 0 4px; }
#aut-dist .lead { font-size:14px; margin:0 0 14px; }
#aut-dist .err { background:var(--light-red, #FFA0A0); color:var(--dark-red, #E00000); padding:10px 13px; border-radius:6px; margin:0 0 14px; font-weight:bold; }
#aut-dist table.Tabella { margin:0 0 6px; }
#aut-dist table.Tabella td { padding:4px 8px; }
#aut-dist code { font-size:12px; }
#aut-dist .note { color:var(--text-muted, #868e96); font-size:13px; margin:6px 0 18px; max-width:980px; }
#aut-dist h2 { font-size:17px; color:var(--header-dark, #004488); margin:22px 0 2px; }
#aut-dist .rounds { color:var(--text-muted, #868e96); font-size:13px; margin:0 0 8px; }
#aut-dist h3 { font-size:14px; margin:12px 0 6px; }
#aut-dist h3 span { font-weight:normal; color:var(--text-muted, #868e96); font-size:12px; }
#aut-dist .row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
#aut-dist .tile { width:104px; text-align:center; font-size:12px; }
#aut-dist .tile a { display:flex; align-items:flex-end; justify-content:center; height:112px; }
#aut-dist .tile img { max-width:100px; max-height:110px; }
#aut-dist .tile b { display:block; margin-top:3px; }
#aut-dist .tile small { display:block; color:var(--text-muted, #868e96); }
#aut-dist .bk-dist-tile { display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box;
    height:calc(var(--dt-h) * 0.62); width:100px; padding:4px 6px; border:3px solid var(--dt-bd); border-radius:10px;
    background:var(--dt-bg); color:var(--dt-fg); font-weight:bold; font-size:12px; line-height:1.15; text-align:center; }
</style>' . "\n";

$out = '<div id="aut-dist"><h1>' . $e(aut_t('MenuDistinctions')) . '</h1>'
    . '<p class="lead">' . $e(aut_t('DsLead', 'Modules/Custom/AUTH/booking/distinctions/')) . '</p>';
foreach ($errors as $m) $out .= '<div class="err">' . $e(aut_t('DsErr', $m)) . '</div>';

$files = array(
    'badge-level.svg' => 'DsFileLevel',
    'badge-star.svg'  => 'DsFileStar',
    'logo.svg'        => 'DsFileLogo',
    'looks.json'      => 'DsFileLooks',
    'rules.json'      => 'DsFileRules',
);
$out .= '<table class="Tabella"><tr><th class="Title" colspan="2">' . $e(aut_t('DsHowTitle')) . '</th></tr>'
    . '<tr><th>' . $e(aut_t('DsColFile')) . '</th><th>' . $e(aut_t('DsColWhat')) . '</th></tr>';
foreach ($files as $f => $k) $out .= '<tr><td><code>' . $e($f) . '</code></td><td>' . $e(aut_t($k)) . '</td></tr>';
$out .= '</table><p class="note">' . $e(aut_t('DsHowTools')) . '<br>' . $e(aut_t('DsHowUpdate')) . '</p>';

foreach ((array) ($rules['disciplines'] ?? array()) as $disc => $d) {
    if (!preg_match('/^[a-z0-9_]+$/', (string) $disc)) continue;
    $out .= '<h2>' . $e($bt('DistDisc' . $disc)) . '</h2><p class="rounds">' . $e($bt('DistRounds' . $disc)) . '</p>';
    foreach (bk_dist_sets($disc) as $set) {
        $setName = $bt('DistSet' . ($set['id'] ?? ''));
        foreach (bk_dist_columns($set) as $colId => $texts) {
            foreach ($texts as $text => $ages) {
                $ctx = array('disc' => $disc, 'set' => (string) $set['id'], 'col' => (string) $colId, 'text' => (string) $text);
                $title = ($setName !== '' ? $setName . ' — ' : '') . $bt('DistBow' . $colId) . ($text !== '' ? ' — ' . $text : '');
                $out .= '<h3>' . $e($title) . ($ages ? ' <span>' . $e($bt('DistAges', implode(', ', $ages))) . '</span>' : '')
                    . '</h3><div class="row">';
                foreach (bk_dist_ladder($ctx) as $lv) {
                    $name = $bt('DistLvl' . $lv['id']);
                    $pic = bk_dist_visual($ctx, $lv['id'], $name, $base, 100);
                    if ($lv['drawn']) {
                        $url = $base . bk_dist_url($disc, $ctx['set'], $ctx['col'], $lv['id'], $ctx['text']);
                        $pic = '<a href="' . $e($url) . '" target="_blank" title="' . $e(aut_t('DsOpen')) . '">' . $pic . '</a>';
                    } else {
                        $pic = '<a>' . $pic . '</a>';
                    }
                    $out .= '<div class="tile">' . $pic . '<b>' . $e($name) . '</b><span>'
                        . $e($bt(bk_dist_measure($ctx) === 'honours' ? 'DistHonours' : 'DistPts', $lv['score'])) . '</span>'
                        . ($lv['face'] ? '<small>' . $e($bt($lv['trispot'] ? 'DistFaceOrTri' : 'DistFace', $lv['face'])) . '</small>' : '')
                        . ($lv['ages'] ? '<small>' . $e($bt('DistAges', implode(', ', $lv['ages']))) . '</small>' : '')
                        . '</div>';
                }
                $out .= '</div>';
            }
        }
    }
}
echo $out . '</div>';

echo aut_adm_close();
include('Common/Templates/tail.php');
