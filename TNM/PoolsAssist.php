<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

$IncludeJquery = true;
$PAGE_TITLE = 'Aide répartition Tour 2';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

// Épreuves configurées BSO
$rsEv = safe_r_sql(
    "SELECT c.BcEvent, e.EvEventName FROM TNM_BsoConfig c
     JOIN Events e ON e.EvTournament=c.BcTournament AND e.EvCode=c.BcEvent
     WHERE c.BcTournament=$tourId ORDER BY e.EvProgr"
);
$evList = [];
while ($r = safe_fetch($rsEv))
    $evList[] = ['code'=>$r->BcEvent, 'name'=>get_text($r->EvEventName,'','',true)];
?>
<style>
.pa-table { border-collapse: collapse; width: 100%; margin-bottom: 14px; }
.pa-table th, .pa-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; font-size: .85em; }
.pa-table th { background: #002B92; color: #fff; }
.pa-table td.team-name { text-align: left; font-weight: bold; }
.pa-cell-ok   { background: #e6f7e9; }
.pa-cell-bad  { background: #fdecea; }
.pa-cell-highlight { box-shadow: inset 0 0 0 2px #f0a500; }
.pa-empty { color: #999; font-style: italic; padding: 10px; }
</style>

<table class="Tabella">
<tr><th class="Title" colspan="2">Aide à la répartition Tour 2 (poules sans répétition)</th></tr>
<tr>
    <td class="Right">Épreuve :</td>
    <td>
        <select id="sel-event">
            <option value="">— Choisir —</option>
            <?php foreach ($evList as $ev): ?>
                <option value="<?= htmlspecialchars($ev['code']) ?>"><?= htmlspecialchars($ev['code'].' – '.$ev['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="btn-refresh">Réactualiser</button>
    </td>
</tr>
</table>

<h3>Poules Principales – PP</h3>
<div id="pa-pp"></div>

<h3>Poules de Classement – PC</h3>
<div id="pa-pc"></div>

<script>
var ROOT = '<?= $CFG->ROOT_DIR ?>Modules/Custom/TNM/bso-action.php';

$('#btn-refresh').on('click', load);
$('#sel-event').on('change', load);

function load() {
    var ev = $('#sel-event').val();
    if (!ev) { $('#pa-pp, #pa-pc').empty(); return; }
    $.getJSON(ROOT, {act:'getPoolsAssist', event:ev}, function(data) {
        if (data.error) {
            $('#pa-pp').html('<p class="pa-empty">'+(data.msg||'Erreur')+'</p>');
            $('#pa-pc').empty();
            return;
        }
        $('#pa-pp').html(buildTable(data.pp));
        $('#pa-pc').html(buildTable(data.pc));
    });
}

function buildTable(seg) {
    if (!seg.rows.length) return '<p class="pa-empty">Aucune équipe à classer pour ce segment.</p>';

    var html = '<table class="pa-table"><tr><th>Équipe à placer – Poule</th>';
    seg.columns.forEach(function(c) {
        html += '<th>'+c.label+'<br>poule '+c.dest+'</th>';
    });
    html += '</tr>';

    seg.rows.forEach(function(row) {
        html += '<tr><td class="team-name">'+escapeHtml(row.name)+' – Poule '+row.origin+'</td>';
        row.cells.forEach(function(cell, i) {
            var col = seg.columns[i];
            var liste = cell.liste.map(function(p) {
                var txt = String(p);
                return (p === row.origin) ? '<strong>'+txt+'</strong>' : txt;
            }).join(' - ');
            if (!liste) liste = '—';

            var cls = (cell.conflict ? 'pa-cell-bad' : 'pa-cell-ok') + (cell.highlight ? ' pa-cell-highlight' : '');
            var symbol = cell.conflict ? '❌' : '✅';
            html += '<td class="'+cls+'">'+symbol+' '+liste+'</td>';
        });
        html += '</tr>';
    });

    html += '</table>';
    return html;
}

function escapeHtml(s) {
    return $('<div>').text(s).html();
}
</script>

<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');