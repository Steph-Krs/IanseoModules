<?php
// =============================================================================
// config.php — Configuration TNM par épreuve
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

// ── Fonctions JSON ────────────────────────────────────────────────────────────
define('TNM_CONFIG_FILE', __DIR__ . '/tnm_config.json');

function readTNMConfig() {
    if (!file_exists(TNM_CONFIG_FILE)) return [];
    return json_decode(file_get_contents(TNM_CONFIG_FILE), true) ?? [];
}
function writeTNMConfig($data) {
    file_put_contents(TNM_CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
// Lecture d'une valeur globale à la compétition
function getTNMValue($tourId, $key, $default = null) {
    $cfg = readTNMConfig();
    return $cfg[$tourId][$key] ?? $default;
}
// Lecture d'une valeur propre à une épreuve
function getTNMEventValue($tourId, $evCode, $key, $default = null) {
    $cfg = readTNMConfig();
    return $cfg[$tourId]['events'][$evCode][$key] ?? $default;
}
// Écriture d'une valeur propre à une épreuve
function setTNMEventValue($tourId, $evCode, $key, $value) {
    $cfg = readTNMConfig();
    $cfg[$tourId]['events'][$evCode][$key] = $value;
    writeTNMConfig($cfg);
}

// ── Épreuves Round Robin de la compétition ────────────────────────────────────
$rsEv = safe_r_sql(
    "SELECT EvCode, EvEventName FROM Events
     WHERE EvElimType=5 AND EvTeamEvent='1'
     AND EvTournament=$tourId AND EvCodeParent=''
     ORDER BY EvProgr"
);
$evList = [];
while ($r = safe_fetch($rsEv)) {
    $evList[] = ['code' => $r->EvCode, 'name' => get_text($r->EvEventName, '', '', true)];
}

// ── Traitement formulaire ─────────────────────────────────────────────────────
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bso_count'])) {
    foreach ($_POST['bso_count'] as $evCode => $val) {
        $evCode = preg_replace('/[^A-Za-z0-9]/', '', $evCode); // sécurité
        $bso    = max(4, min(20, intval($val)));
        setTNMEventValue($tourId, $evCode, 'bso_count', $bso);
    }
    $saved = true;
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Configuration TNM';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
    .tnm-cfg-table td, .tnm-cfg-table th { padding: 6px 10px; }
    .tnm-cfg-note { color: #888; font-size: 0.85em; }
</style>
<?php

echo '<table class="Tabella">';
echo '<tr><th class="Title" colspan="2">Configuration – Trophée National des Mixtes</th></tr>';
if ($saved) {
    echo '<tr><td colspan="2" class="Center" style="color:green;font-weight:bold;padding:8px">✓ Configuration sauvegardée</td></tr>';
}
echo '<tr>';
echo '<td class="Right w-30">Compétition&nbsp;:</td>';
echo '<td><strong>'.htmlspecialchars($_SESSION['TourNameSafe'] ?? '').' (ID&nbsp;: '.$tourId.')</strong></td>';
echo '</tr>';
echo '</table>';

if (empty($evList)) {
    echo '<p class="Center" style="color:#c00">Aucune épreuve Round Robin par équipes trouvée pour cette compétition.</p>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

echo '<br>';
echo '<form method="POST">';
echo '<table class="Tabella tnm-cfg-table" style="width:100%">';

// ── En-tête ───────────────────────────────────────────────────────────────────
echo '<tr><th class="SubTitle" colspan="4">Big Shoot Off — Paramètres par épreuve</th></tr>';
echo '<tr style="background:#eef">';
echo '<th class="SubTitle" style="width:8%">Code</th>';
echo '<th class="SubTitle" style="width:30%">Épreuve</th>';
echo '<th class="SubTitle" style="width:18%">Qualifiés BSO<br><small>(entre 4 et 20)</small></th>';
echo '<th class="SubTitle">Impact sur le Tour 2</th>';
echo '</tr>';

// ── Une ligne par épreuve ─────────────────────────────────────────────────────
foreach ($evList as $ev) {
    $evCode   = $ev['code'];
    $evName   = $ev['name'];
    $bsoCurr  = intval(getTNMEventValue($tourId, $evCode, 'bso_count', 10));
    $mainLimit = $bsoCurr * 2;
    $inputName = 'bso_count[' . htmlspecialchars($evCode) . ']';

    echo '<tr>';
    echo '<td class="Center"><strong>'.htmlspecialchars($evCode).'</strong></td>';
    echo '<td>'.htmlspecialchars($evName).'</td>';
    echo '<td class="Center">';
    echo '<input type="number" name="'.$inputName.'" value="'.$bsoCurr.'"
               min="4" max="20"
               style="width:60px;font-size:1.1em;text-align:center"
               oninput="updateInfo(this)"
               data-ev="'.htmlspecialchars($evCode).'">';
    echo '</td>';
    echo '<td>';
    echo '<span id="info_'.htmlspecialchars($evCode).'" class="tnm-cfg-note">';
    echo $mainLimit.' équipes en poules principales Tour&nbsp;2 ('.$bsoCurr.'&nbsp;×&nbsp;2)';
    echo '</span>';
    echo '</td>';
    echo '</tr>';
}

echo '<tr><td colspan="4" class="Center" style="padding:14px">';
echo '<div class="Button" onclick="this.closest(\'form\').submit()">Enregistrer toutes les épreuves</div>';
echo '</td></tr>';
echo '</table>';
echo '</form>';

?>
<script>
function updateInfo(input) {
    var ev  = input.getAttribute('data-ev');
    var bso = parseInt(input.value) || 0;
    var el  = document.getElementById('info_' + ev);
    if (el) el.textContent = (bso * 2) + ' équipes en poules principales Tour 2 (' + bso + ' × 2)';
}
</script>
<?php

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');