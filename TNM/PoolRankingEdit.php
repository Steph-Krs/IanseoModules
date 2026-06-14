<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

$IncludeJquery = true;
$PAGE_TITLE = 'Édition manuelle classement poule';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

// Épreuves Round Robin
$rsEv = safe_r_sql(
    "SELECT EvCode, EvEventName FROM Events
     WHERE EvElimType=5 AND EvTeamEvent='1'
     AND EvTournament=$tourId AND EvCodeParent='' ORDER BY EvProgr"
);
$evList = [];
while ($r = safe_fetch($rsEv))
    $evList[] = ['code'=>$r->EvCode, 'name'=>get_text($r->EvEventName,'','',true)];

// Niveaux par épreuve
$rsLev = safe_r_sql(
    "SELECT DISTINCT RrLevEvent, RrLevLevel FROM RoundRobinLevel
     WHERE RrLevTournament=$tourId ORDER BY RrLevEvent, RrLevLevel"
);
$levByEvent = [];
while ($r = safe_fetch($rsLev)) $levByEvent[$r->RrLevEvent][] = intval($r->RrLevLevel);

// Groupes par épreuve:niveau
$rsGrp = safe_r_sql(
    "SELECT DISTINCT RrGrEvent, RrGrLevel, RrGrGroup FROM RoundRobinGroup
     WHERE RrGrTournament=$tourId ORDER BY RrGrEvent, RrGrLevel, RrGrGroup"
);
$grpByEvLev = [];
while ($r = safe_fetch($rsGrp)) $grpByEvLev[$r->RrGrEvent.':'.$r->RrGrLevel][] = intval($r->RrGrGroup);
?>
<style>
.pre-table { border-collapse: collapse; width: 100%; }
.pre-table th, .pre-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; font-size: .9em; }
.pre-table th { background: #002B92; color: #fff; }
.pre-table td.team-name { text-align: left; font-weight: bold; }
.pre-table input { width: 70px; text-align: center; }
.pre-table select { width: 80px; }
.pre-msg { margin-top: 8px; min-height: 20px; font-size: .9em; }
</style>

<table class="Tabella">
<tr><th class="Title" colspan="2">Édition manuelle du classement de poule</th></tr>
<tr>
    <td class="Right">Épreuve :</td>
    <td><select id="sel-event"><option value="">— Choisir —</option>
        <?php foreach ($evList as $ev): ?>
            <option value="<?= htmlspecialchars($ev['code']) ?>"><?= htmlspecialchars($ev['code'].' – '.$ev['name']) ?></option>
        <?php endforeach; ?>
    </select></td>
</tr>
<tr>
    <td class="Right">Tour :</td>
    <td><select id="sel-level" disabled><option value="">—</option></select></td>
</tr>
<tr>
    <td class="Right">Poule :</td>
    <td><select id="sel-group" disabled><option value="">—</option></select></td>
</tr>
</table>
<br>

<table class="pre-table" id="pool-table" style="display:none">
<thead>
<tr>
    <th>Équipe</th>
    <th>Pl. Poule (avant SO)</th>
    <th>Pl. Poule (finale)</th>
    <th>Points</th>
    <th>Diff.</th>
    <th>Pts-sets</th>
    <th>Statut</th>
</tr>
</thead>
<tbody id="pool-body"></tbody>
</table>

<div style="text-align:center;margin-top:14px">
    <div class="Button" id="btn-save" style="display:none">Enregistrer</div>
</div>
<div class="pre-msg" id="pre-msg" style="text-align:center"></div>

<script>
var ROOT = '<?= $CFG->ROOT_DIR ?>Modules/Custom/TNM/bso-action.php';
var levByEvent = <?= json_encode($levByEvent, JSON_UNESCAPED_UNICODE) ?>;
var grpByEvLev = <?= json_encode($grpByEvLev, JSON_UNESCAPED_UNICODE) ?>;

var IRM_TYPES = {0:'—', 5:'DNF', 10:'DNS', 15:'DSQ', 20:'DQB'};

$('#sel-event').on('change', function() {
    var ev = this.value;
    var sel = $('#sel-level').empty().append('<option value="">—</option>');
    if (ev && levByEvent[ev]) {
        levByEvent[ev].forEach(function(l) {
            sel.append($('<option>').val(l).text('Tour '+l));
        });
        sel.prop('disabled', false);
    } else {
        sel.prop('disabled', true);
    }
    $('#sel-group').empty().append('<option value="">—</option>').prop('disabled', true);
    $('#pool-table, #btn-save').hide();
});

$('#sel-level').on('change', function() {
    var ev = $('#sel-event').val();
    var lv = this.value;
    var sel = $('#sel-group').empty().append('<option value="">—</option>');
    if (ev && lv && grpByEvLev[ev+':'+lv]) {
        grpByEvLev[ev+':'+lv].forEach(function(g) {
            sel.append($('<option>').val(g).text('Poule '+g));
        });
        sel.prop('disabled', false);
    } else {
        sel.prop('disabled', true);
    }
    $('#pool-table, #btn-save').hide();
});

$('#sel-group').on('change', load);

function load() {
    var ev = $('#sel-event').val(), lv = $('#sel-level').val(), gr = $('#sel-group').val();
    if (!ev || !lv || !gr) { $('#pool-table, #btn-save').hide(); return; }

    $.getJSON(ROOT, {act:'getPoolTeams', event:ev, level:lv, group:gr}, function(data) {
        if (data.error) { $('#pre-msg').text('Erreur : '+(data.msg||'')); return; }

        var body = $('#pool-body').empty();
        data.rows.forEach(function(r) {
            var tr = $('<tr>').attr('data-team', r.team);
            tr.append($('<td class="team-name">').text(r.name || ('#'+r.team)));
            tr.append(numInput('groupRankBefSO', r.groupRankBefSO));
            tr.append(numInput('groupRank', r.groupRank));
            tr.append(numInput('points', r.points));
            tr.append(numInput('tieBreaker', r.tieBreaker));
            tr.append(numInput('tieBreaker2', r.tieBreaker2));

            var sel = $('<select>').attr('data-field', 'irmType');
            Object.keys(IRM_TYPES).forEach(function(k) {
                sel.append($('<option>').val(k).text(IRM_TYPES[k]));
            });
            sel.val(r.irmType);
            tr.append($('<td>').append(sel));

            body.append(tr);
        });

        $('#pool-table, #btn-save').show();
        $('#pre-msg').text('');
    });
}

function numInput(field, val) {
    return $('<td>').append($('<input type="number">').attr('data-field', field).val(val));
}

$('#btn-save').on('click', function() {
    var ev = $('#sel-event').val(), lv = $('#sel-level').val(), gr = $('#sel-group').val();
    var rows = $('#pool-body tr');
    var pending = rows.length;
    var errors = 0;

    $('#pre-msg').text('Enregistrement...');

    rows.each(function() {
        var tr = $(this);
        var params = {
            act:'setPoolTeamRanking', event:ev, level:lv, group:gr, team:tr.data('team'),
            groupRankBefSO: tr.find('[data-field="groupRankBefSO"]').val(),
            groupRank:      tr.find('[data-field="groupRank"]').val(),
            points:         tr.find('[data-field="points"]').val(),
            tieBreaker:     tr.find('[data-field="tieBreaker"]').val(),
            tieBreaker2:    tr.find('[data-field="tieBreaker2"]').val(),
            irmType:        tr.find('[data-field="irmType"]').val(),
        };
        $.getJSON(ROOT, params, function(data) {
            if (data.error) errors++;
            pending--;
            if (pending === 0) {
                $('#pre-msg').text(errors === 0 ? '✓ Enregistré' : (errors+' erreur(s)'));
            }
        });
    });
});
</script>

<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');