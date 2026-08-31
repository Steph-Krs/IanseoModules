<?php
/**
 * Module AUTH — Statistiques d'usage du serveur (ADMIN uniquement).
 *
 * Lecture seule des compteurs agrégés de stats-usage.php (aucune donnée
 * personnelle) + quelques métriques métier issues des comptes/inscriptions.
 * Deux onglets, Organisateurs / Archers, pour distinguer les publics.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/legal-lib.php');
require_once(dirname(__DIR__) . '/stats-usage.php');

checkFullACL(AclRoot, '', AclReadWrite);
// même verrou que Update/index.php : réservé au compte ADMIN quand l'auth est active
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();
aut_stats_ensure_schema();

$root = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/';
$hasArchers = (bool) safe_fetch(safe_r_sql("SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BK_Archers'", false, true));

$days = intval($_GET['days'] ?? 30);
if (!in_array($days, array(7, 30, 90), true)) $days = 30;
$activeTab = (($_REQUEST['tab'] ?? '') === 'archers' && $hasArchers) ? 'archers' : 'org';

/* ---- Données ---- */
$publicUniq  = aut_stats_uniques('public', $days);
$publicViews = aut_stats_views('public', $days);

$data = array();
foreach (array('org', 'archer') as $sp) {
    $data[$sp] = array(
        'views'   => aut_stats_views($sp, $days),
        'uniques' => aut_stats_uniques($sp, $days),
        'daily'   => aut_stats_daily($sp, $days),
        'hourly'  => aut_stats_hourly($sp, $days),
        'top'     => aut_stats_top_pages($sp, $days, 8),
    );
}
$orgBiz = aut_stats_org_business($days);
$arcBiz = $hasArchers ? aut_stats_archer_business() : null;

/* ---- Helpers de rendu ---- */
function st_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

function st_kpi($value, $label, $sub = '') {
    echo '<div class="st-kpi"><div class="st-kv">' . st_h($value) . '</div>'
       . '<div class="st-kl">' . st_h($label) . '</div>'
       . ($sub !== '' ? '<div class="st-ks">' . st_h($sub) . '</div>' : '') . '</div>';
}

/** Barres verticales. $items = [ ['label'=>, 'value'=>, 'title'=>], ... ] */
function st_vbars($items) {
    $vals = array_map(function ($i) { return $i['value']; }, $items);
    $max = max(1, $vals ? max($vals) : 0);
    echo '<div class="st-chart">';
    foreach ($items as $i) {
        $hpc = round(100 * $i['value'] / $max);
        echo '<div class="st-col" title="' . st_h($i['title']) . '">'
           . '<div class="st-bar" style="height:' . $hpc . '%"></div>'
           . '<span class="st-xl">' . st_h($i['label']) . '</span></div>';
    }
    echo '</div>';
}

/** Barres horizontales pour le top pages. */
function st_hbars($items) {
    if (!$items) { echo '<p class="st-empty">Aucune donnée sur la période.</p>'; return; }
    $max = max(1, max(array_map(function ($i) { return $i['views']; }, $items)));
    echo '<div class="st-hb">';
    foreach ($items as $i) {
        $w = round(100 * $i['views'] / $max);
        echo '<div class="st-row"><span class="st-rl">' . st_h($i['page']) . '</span>'
           . '<span class="st-track"><span class="st-fill" style="width:' . $w . '%"></span></span>'
           . '<span class="st-rv">' . st_h($i['views']) . '</span></div>';
    }
    echo '</div>';
}

/** Prépare les items d'une série quotidienne. */
function st_daily_items($daily) {
    $out = array(); $i = 0;
    foreach ($daily as $d) {
        $lab = ($i % 5 === 0) ? substr($d['day'], 8, 2) : '';
        $dm  = substr($d['day'], 8, 2) . '/' . substr($d['day'], 5, 2);
        $out[] = array('label' => $lab, 'value' => $d['views'],
            'title' => "$dm — {$d['views']} vues, {$d['uniques']} visiteurs");
        $i++;
    }
    return $out;
}

/** Prépare les items d'une répartition horaire. */
function st_hourly_items($hours) {
    $out = array();
    for ($h = 0; $h < 24; $h++) {
        $out[] = array('label' => ($h % 3 === 0) ? sprintf('%02d', $h) : '',
            'value' => $hours[$h], 'title' => sprintf('%02d h — %d vues', $h, $hours[$h]));
    }
    return $out;
}

/** Rend un onglet complet (graphiques d'audience communs org/archer). */
function st_render_traffic($d, $days) {
    ?>
    <div class="st-cards">
        <?php st_kpi($d['views'],   'Pages vues',       "sur $days j"); ?>
        <?php st_kpi($d['uniques'], 'Visiteurs uniques', "sur $days j"); ?>
    </div>
    <h3 class="st-h3">Fréquentation — pages vues par jour</h3>
    <?php st_vbars(st_daily_items($d['daily'])); ?>
    <h3 class="st-h3">Charge — pages vues par heure <span class="st-note">(cumul de la période : repérer les pics)</span></h3>
    <?php st_vbars(st_hourly_items($d['hourly'])); ?>
    <h3 class="st-h3">Pages les plus consultées</h3>
    <?php st_hbars($d['top']); ?>
    <?php
}

$PAGE_TITLE = 'Statistiques d’usage';
include('Common/Templates/head.php');
$cookieUrl = function_exists('aut_legal_url') ? aut_legal_url('cookies') : '';
?>
<style>
#aut-tabs { display:flex; gap:8px; margin:6px 0 14px; }
#aut-tabs button { padding:9px 18px; border:1px solid #c9d4df; border-radius:6px 6px 0 0;
    background:#eef2f6; color:#334; font-size:14px; cursor:pointer; }
#aut-tabs button.on { background:#1a4f8b; color:#fff; border-color:#1a4f8b; font-weight:600; }
.aut-pane { display:none; }
.aut-pane.on { display:block; }
.st-intro { font-size:12.5px; color:#4c4e50; background:#f2f6fb; border:1px solid #d5e2f0;
    border-radius:6px; padding:9px 12px; margin:0 0 14px; }
.st-period { margin:0 0 14px; font-size:13px; }
.st-period a { display:inline-block; padding:4px 12px; border:1px solid #c9d4df; border-radius:16px;
    margin-right:6px; text-decoration:none; color:#33506f; }
.st-period a.on { background:#0254a8; color:#fff; border-color:#0254a8; font-weight:600; }
.st-cards { display:flex; flex-wrap:wrap; gap:12px; margin:4px 0 8px; }
.st-kpi { flex:1 1 130px; min-width:130px; background:#fff; border:1px solid #d5dee8;
    border-radius:8px; padding:12px 14px; }
.st-kv { font-size:26px; font-weight:700; color:#01367c; line-height:1.1; }
.st-kl { font-size:13px; color:#33506f; margin-top:2px; }
.st-ks { font-size:11px; color:#8a97a5; margin-top:1px; }
.st-h3 { color:#01367c; font-size:14px; margin:20px 0 8px; }
.st-note { font-weight:400; color:#8a97a5; font-size:11px; }
.st-chart { display:flex; align-items:flex-end; gap:2px; height:130px; padding:8px 4px 0;
    background:#fbfcfe; border:1px solid #e5ebf2; border-radius:6px; }
.st-col { flex:1 1 0; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
.st-bar { width:72%; min-height:2px; background:linear-gradient(#4a86c6,#0254a8); border-radius:3px 3px 0 0; }
.st-xl { font-size:9px; color:#8a97a5; margin-top:3px; height:11px; }
.st-hb { display:flex; flex-direction:column; gap:6px; }
.st-row { display:flex; align-items:center; gap:8px; font-size:12.5px; }
.st-rl { flex:0 0 190px; color:#33506f; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.st-track { flex:1 1 auto; height:14px; background:#eef2f6; border-radius:7px; overflow:hidden; }
.st-fill { display:block; height:100%; background:linear-gradient(90deg,#4a86c6,#0254a8); }
.st-rv { flex:0 0 auto; color:#01367c; font-weight:600; min-width:38px; text-align:right; }
.st-empty { color:#8a97a5; font-size:13px; font-style:italic; }
.st-roles { font-size:12.5px; color:#4c4e50; margin:6px 0 0; }
.st-roles code { background:#eef2f6; padding:1px 5px; border-radius:4px; }
@media (max-width:600px){ .st-rl { flex-basis:120px; } }
</style>

<div class="st-intro">
    Mesure d’audience <b>agrégée</b> : aucune donnée personnelle, aucune adresse IP, aucun parcours
    nominatif. Les utilisateurs connectés sont comptés par leur compte ; les visiteurs anonymes de la
    page d’accueil via un cookie de mesure d’audience <b>exempté de consentement</b><?php
    if ($cookieUrl) echo ' (voir la <a href="' . st_h($cookieUrl) . '">politique cookies</a>)'; ?>.
</div>

<div class="st-period">Période :
    <?php foreach (array(7 => '7 jours', 30 => '30 jours', 90 => '90 jours') as $k => $lab): ?>
        <a href="?tab=<?= st_h($activeTab) ?>&amp;days=<?= $k ?>" class="<?= $days === $k ? 'on' : '' ?>"><?= st_h($lab) ?></a>
    <?php endforeach; ?>
    &nbsp;·&nbsp; <span class="st-note">Accueil (anonyme) : <?= (int) $publicUniq ?> visiteurs · <?= (int) $publicViews ?> vues</span>
</div>

<div id="aut-tabs">
  <button type="button" data-pane="org" class="<?= $activeTab === 'org' ? 'on' : '' ?>">🏹 Organisateurs</button>
  <?php if ($hasArchers): ?><button type="button" data-pane="archers" class="<?= $activeTab === 'archers' ? 'on' : '' ?>">🎯 Archers</button><?php endif; ?>
</div>

<div class="aut-pane<?= $activeTab === 'org' ? ' on' : '' ?>" id="pane-org">
    <div class="st-cards">
        <?php
        st_kpi($orgBiz['total'],  'Comptes organisateurs');
        st_kpi($orgBiz['active'], 'Comptes actifs');
        st_kpi($orgBiz['logins'], 'Connexions', "sur $days j");
        ?>
    </div>
    <p class="st-roles">Répartition par rôle :
        <?php
        $lbl = array('CLUB' => 'Club', 'CD' => 'CD', 'CR' => 'CR', 'FED' => 'FFTA', 'ADMIN' => 'Admin');
        $parts = array();
        foreach ($lbl as $k => $v) if (!empty($orgBiz['roles'][$k])) $parts[] = '<code>' . st_h($v) . ' ' . (int) $orgBiz['roles'][$k] . '</code>';
        echo $parts ? implode(' ', $parts) : '<span class="st-empty">aucun compte</span>';
        ?>
    </p>
    <?php st_render_traffic($data['org'], $days); ?>
</div>

<?php if ($hasArchers): ?>
<div class="aut-pane<?= $activeTab === 'archers' ? ' on' : '' ?>" id="pane-archers">
    <div class="st-cards">
        <?php
        st_kpi($arcBiz['total'],     'Comptes archers');
        st_kpi($arcBiz['active'],    'Comptes actifs');
        st_kpi($arcBiz['conv_rate'] . ' %', 'Taux de conversion', $arcBiz['converted'] . ' inscrits / ' . $arcBiz['total']);
        st_kpi($arcBiz['registrars'], 'Inscrivent d’autres archers');
        ?>
    </div>
    <?php st_render_traffic($data['archer'], $days); ?>
</div>
<?php endif; ?>

<script>
(function () {
    var tabs = [].slice.call(document.querySelectorAll('#aut-tabs button'));
    tabs.forEach(function (b) {
        b.addEventListener('click', function () {
            var p = b.getAttribute('data-pane');
            tabs.forEach(function (x) { x.classList.toggle('on', x === b); });
            [].forEach.call(document.querySelectorAll('.aut-pane'), function (x) { x.classList.toggle('on', x.id === 'pane-' + p); });
        });
    });
})();
</script>
<?php
include('Common/Templates/tail.php');
