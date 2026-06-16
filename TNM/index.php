<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$IncludeJquery = true;
$PAGE_TITLE = 'Impression Poules';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$tourId = intval($_SESSION['TourId']);

// Épreuves
$rsEv = safe_r_sql(
    "SELECT EvCode, EvEventName FROM Events
     WHERE EvElimType=5 AND EvTeamEvent='1'
     AND EvTournament=$tourId AND EvCodeParent='' ORDER BY EvProgr"
);
$evList = [];
while ($r = safe_fetch($rsEv)) {
    $evList[] = ['code' => $r->EvCode, 'name' => $r->EvCode . ' – ' . get_text($r->EvEventName,'','',true)];
}

// Numéros de niveaux par épreuve : { "BB": [1,2], "CL": [1,2,3] }
$rsLev = safe_r_sql(
    "SELECT DISTINCT RrLevEvent, RrLevLevel, RrLevName FROM RoundRobinLevel
     WHERE RrLevTournament=$tourId ORDER BY RrLevEvent, RrLevLevel"
);
$levByEvent = [];
$levNameByEvLev = [];
while ($r = safe_fetch($rsLev)) {
    $levByEvent[$r->RrLevEvent][] = intval($r->RrLevLevel);
    $levNameByEvLev[$r->RrLevEvent.':'.$r->RrLevLevel] = $r->RrLevName ?: ('Tour '.$r->RrLevLevel);
}

// Numéros de poules par épreuve:niveau : { "BB:1": [1,2,3], "BB:2": [1,2] }
$rsGrp = safe_r_sql(
    "SELECT DISTINCT RrGrEvent, RrGrLevel, RrGrGroup FROM RoundRobinGroup
     WHERE RrGrTournament=$tourId ORDER BY RrGrEvent, RrGrLevel, RrGrGroup"
);
$grpByEvLev = [];
while ($r = safe_fetch($rsGrp)) {
    $grpByEvLev[$r->RrGrEvent.':'.$r->RrGrLevel][] = intval($r->RrGrGroup);
}
?>
<script>
var levByEvent = <?= json_encode($levByEvent, JSON_UNESCAPED_UNICODE) ?>;
var grpByEvLev = <?= json_encode($grpByEvLev, JSON_UNESCAPED_UNICODE) ?>;
var levNameByEvLev = <?= json_encode($levNameByEvLev, JSON_UNESCAPED_UNICODE) ?>;

function updateLevels() {
    var evChosen = Array.from(document.getElementById('selEvent').selectedOptions).map(o => o.value);
    var allEv    = evChosen.length === 0 || evChosen.includes('.');
    var sources  = allEv ? Object.keys(levByEvent) : evChosen;

    // Numéros distincts, triés
    var nums = new Set();
    sources.forEach(ev => (levByEvent[ev] || []).forEach(n => nums.add(n)));

    var levSel = document.getElementById('selLevel');
    levSel.innerHTML = '<option value=".">Tous les niveaux</option>';
    Array.from(nums).sort((a,b) => a-b).forEach(function(n) {
        var o = document.createElement('option');
        o.value = n; o.textContent = 'Tour ' + n;
        levSel.appendChild(o);
    });
    updateGroups();
}

function updateGroups() {
    var evChosen  = Array.from(document.getElementById('selEvent').selectedOptions).map(o => o.value);
    var levChosen = Array.from(document.getElementById('selLevel').selectedOptions).map(o => o.value);
    var allEv     = evChosen.length === 0 || evChosen.includes('.');
    var allLev    = levChosen.length === 0 || levChosen.includes('.');

    var evSrc  = allEv  ? Object.keys(levByEvent) : evChosen;
    var levNums = allLev ? null : levChosen.map(Number);

    var nums = new Set();
    evSrc.forEach(function(ev) {
        var lv = levNums || (levByEvent[ev] || []);
        lv.forEach(function(l) {
            (grpByEvLev[ev + ':' + l] || []).forEach(g => nums.add(g));
        });
    });

    var grpSel = document.getElementById('selGroup');
    grpSel.innerHTML = '<option value=".">Toutes les poules</option>';
    Array.from(nums).sort((a,b) => a-b).forEach(function(n) {
        var o = document.createElement('option');
        o.value = n; o.textContent = 'Poule ' + n;
        grpSel.appendChild(o);
    });
}

function launchPrint() {
    var params = [];

    function addSelected(selId, name) {
        var vals = Array.from(document.getElementById(selId).selectedOptions).map(o => o.value);
        if (vals.length === 0) vals = ['.'];
        vals.forEach(v => params.push(encodeURIComponent(name+'[]') + '=' + encodeURIComponent(v)));
    }

    addSelected('selEvent', 'event');
    addSelected('selLevel', 'level');
    addSelected('selGroup', 'group');
    if (document.getElementById('cbResults').checked) params.push('withResults=1');
    if (document.getElementById('cbAccColors').checked) params.push('useAccColors=1');

    window.open('PdfPools.php?' + params.join('&'), '_blank');
}

function initRankLevels() {
    var levNames = levNameByEvLev || {};
    var allLevels = new Set();
    Object.values(levByEvent).forEach(function(arr) { arr.forEach(function(n) { allLevels.add(n); }); });

    var sel = document.getElementById('selRankLevel');
    Array.from(allLevels).sort(function(a,b){return a-b;}).forEach(function(n) {
        var names = new Set();
        Object.keys(levByEvent).forEach(function(ev) {
            var key = ev + ':' + n;
            if (levNames[key]) names.add(levNames[key]);
        });

        var label = 'Tour ' + n;
        if (names.size === 1) label = Array.from(names)[0];

        var o = document.createElement('option');
        o.value = n; o.textContent = label;
        sel.appendChild(o);
    });
}

function launchRanking() {
    var params = [];
    function addSel(id, name) {
        var vals = Array.from(document.getElementById(id).selectedOptions).map(function(o){return o.value;});
        if (vals.length === 0) vals = ['.'];
        vals.forEach(function(v){ params.push(encodeURIComponent(name+'[]')+'='+encodeURIComponent(v)); });
    }
    addSel('selRankEvent', 'event');
    addSel('selRankLevel', 'level');
    if (document.getElementById('cbRankAccColors').checked) params.push('useAccColors=1');
    window.open('PdfRanking.php?' + params.join('&'), '_blank');
}
</script>
<?php

echo '<table class="Tabella">';
echo '<tr><th class="Title" colspan="5">Impression des Poules</th></tr>';
echo '<tr>';
foreach (['Épreuve', 'Tour', 'Poule', 'Options', ''] as $h)
    echo '<th class="SubTitle">' . $h . '</th>';
echo '</tr><tr>';

echo '<td class="Center">';
echo '<select id="selEvent" multiple="multiple" size="6" onchange="updateLevels()">';
echo '<option value=".">Toutes les épreuves</option>';
foreach ($evList as $ev)
    echo '<option value="'.htmlspecialchars($ev['code']).'">'.htmlspecialchars($ev['name']).'</option>';
echo '</select></td>';

echo '<td class="Center">';
echo '<select id="selLevel" multiple="multiple" size="6" onchange="updateGroups()">';
echo '<option value="." selected>Tous les niveaux</option>';
echo '</select></td>';

echo '<td class="Center">';
echo '<select id="selGroup" multiple="multiple" size="6">';
echo '<option value="." selected>Toutes les poules</option>';
echo '</select></td>';

echo '<td class="Center" style="vertical-align:middle">';
echo '<label><input type="checkbox" id="cbResults" checked>&nbsp;Avec résultats</label><br>';
echo '<label><input type="checkbox" id="cbAccColors" checked>&nbsp;Couleurs AccColors</label>';
echo '</td>';

echo '<td class="Center" style="vertical-align:middle">';
echo '<div class="Button" onclick="launchPrint()">Imprimer les poules</div>';
echo '</td>';

echo '</tr></table>';
echo '<script>updateLevels();</script>';

// ── SECTION CLASSEMENT ────────────────────────────────────────────────────────
echo '<br>';
echo '<table class="Tabella">';
echo '<tr><th class="Title" colspan="5">Classement général <i style="font-size:10px;">*à imprimer après validation du tour</i></th></tr>';
echo '<tr>';
foreach (['Épreuve', 'Tour(s)', 'Options', '', ''] as $h)
    echo '<th class="SubTitle">' . $h . '</th>';
echo '</tr><tr>';

echo '<td class="Center">';
echo '<select id="selRankEvent" multiple="multiple" size="5">';
echo '<option value=".">Toutes les épreuves</option>';
foreach ($evList as $ev)
    echo '<option value="'.htmlspecialchars($ev['code']).'">'.htmlspecialchars($ev['name']).'</option>';
echo '</select></td>';

echo '<td class="Center">';
echo '<select id="selRankLevel" multiple="multiple" size="5">';
echo '<option value=".">Tous les tours</option>';
// Peuplé par JS via initRankLevels()
echo '</select></td>';

echo '<td class="Center" style="vertical-align:middle">';
echo '<label><input type="checkbox" id="cbRankAccColors" checked>&nbsp;Couleurs AccColors</label>';
echo '</td>';
echo '<td></td>';
echo '<td class="Center" style="vertical-align:middle">';
echo '<div class="Button" onclick="launchRanking()">Imprimer le classement</div>';
echo '</td>';
echo '</tr></table>';
echo '<script>initRankLevels();</script>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');