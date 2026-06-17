<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

$IncludeJquery = true;
$PAGE_TITLE = 'Aide répartition des poules';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

// Épreuves configurées BSO
$rsEv = safe_r_sql(
    "SELECT c.BcEvent, e.EvEventName FROM TNM_BsoConfig c
     JOIN Events e ON e.EvTournament=c.BcTournament AND e.EvCode=c.BcEvent
     WHERE c.BcTournament=$tourId ORDER BY e.EvProgr"
);
$evList = [];
while ($r = safe_fetch($rsEv))
    $evList[] = ['code' => $r->BcEvent, 'name' => get_text($r->EvEventName, '', '', true)];
?>
<style>
/* ── Tour 2 – tableau de conflits ── */
.pa-table { border-collapse: collapse; width: 100%; margin-bottom: 14px; }
.pa-table th, .pa-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; font-size: .85em; }
.pa-table th { background: #002B92; color: #fff; }
.pa-table td.team-name { text-align: left; font-weight: bold; }
.pa-cell-ok        { background: #e6f7e9; }
.pa-cell-bad       { background: #fdecea; }
.pa-cell-highlight { box-shadow: inset 0 0 0 2px #f0a500; }
.pa-empty { color: #999; font-style: italic; padding: 10px; }

/* ── Tour 1 – cartes de poules ── */
.pa-pool-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 10px; }
.pa-pool-card { border: 1px solid #ccc; border-radius: 4px; min-width: 180px; overflow: hidden; }
.pa-pool-title { background: #002B92; color: #fff; padding: 4px 8px; font-size: 13px; font-weight: bold; text-align: center; }
.pa-team { padding: 4px 8px; font-size: 12px; border-top: 1px solid #eee; }
.pa-team-dept   { background: #fdecea; border-left: 4px solid #c00; }
.pa-team-region { background: #fff3e0; border-left: 4px solid #f0a500; }
.pa-team-ok     { background: #e6f7e9; border-left: 4px solid #2e7d32; }
.pa-team-code   { font-size: 10px; color: #666; font-family: monospace; display: inline-block; margin-right: 4px; }

/* ── Légende ── */
.pa-legend { font-size: 12px; margin: 8px 0 12px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
.pa-legend-dot { width: 12px; height: 12px; border-radius: 2px; display: inline-block; margin-right: 4px; vertical-align: middle; }
</style>

<table class="Tabella" style="margin-bottom:12px">
<tr>
    <td class="Right" style="white-space:nowrap">Épreuve :</td>
    <td>
        <select id="sel-event">
            <option value="">— Choisir —</option>
            <?php foreach ($evList as $ev): ?>
                <option value="<?= htmlspecialchars($ev['code']) ?>"><?= htmlspecialchars($ev['code'] . ' – ' . $ev['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="btn-refresh">Réactualiser</button>
    </td>
</tr>
</table>

<!-- ── Tour 1 ──────────────────────────────────────────────────────────────── -->
<table class="Tabella" style="margin-bottom:16px">
<tr>
    <th class="TitleLeft p-2" onclick="toggleSec('t1')" style="cursor:pointer">
        <i id="cmd-t1" class="fa-solid fa-caret-down fa-lg mr-1"></i>
        Aide à la répartition Tour 1
    </th>
</tr>
<tr id="view-t1"><td style="padding:12px 16px">
    <div class="pa-legend">
        <span><span class="pa-legend-dot" style="background:#fdecea;border:2px solid #c00"></span> Même département (conflit fort)</span>
        <span><span class="pa-legend-dot" style="background:#fff3e0;border:2px solid #f0a500"></span> Même région, département différent</span>
        <span><span class="pa-legend-dot" style="background:#e6f7e9;border:2px solid #2e7d32"></span> Pas de conflit géographique</span>
    </div>
    <div id="pa-t1"><p class="pa-empty">Sélectionnez une épreuve.</p></div>
</td></tr>
</table>

<!-- ── Tour 2 ──────────────────────────────────────────────────────────────── -->
<table class="Tabella" style="margin-bottom:16px">
<tr>
    <th class="TitleLeft p-2" onclick="toggleSec('t2')" style="cursor:pointer">
        <i id="cmd-t2" class="fa-solid fa-caret-down fa-lg mr-1"></i>
        Aide à la répartition Tour 2 (poules sans répétition)
    </th>
</tr>
<tr id="view-t2"><td style="padding:12px 16px">
    <h3 style="margin-top:0">Poules Principales – PP</h3>
    <div id="pa-pp"><p class="pa-empty">Sélectionnez une épreuve.</p></div>
    <h3>Poules de Classement – PC</h3>
    <div id="pa-pc"><p class="pa-empty">Sélectionnez une épreuve.</p></div>
</td></tr>
</table>

<!-- ── Tirage au sort ────────────────────────────────────────────────────────── -->
<table class="Tabella" style="margin-bottom:16px">
<tr>
    <th class="TitleLeft p-2" onclick="toggleSec('draw')" style="cursor:pointer">
        <i id="cmd-draw" class="fa-solid fa-caret-down fa-lg mr-1"></i>
        Tirage au sort
    </th>
</tr>
<tr id="view-draw"><td style="padding:12px 16px">
    <p style="margin:0 0 12px;font-size:.88em;color:#444">
        En cas d'égalité parfaite, attribuez un numéro aléatoire à chaque équipe.
        L'équipe ayant le numéro le plus petit obtient le meilleur classement.
        Cliquez sur <strong>Tirer</strong> pour générer un nouveau tirage.
    </p>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <label style="font-size:.9em;font-weight:bold">Nombre d'équipes à départager :</label>
        <input type="number" id="draw-count" value="2" min="2" max="20"
               style="width:64px;padding:4px 6px;border:1px solid #bbb;border-radius:4px;font-size:1em">
        <button type="button" id="btn-draw"
                style="background:#002B92;color:#fff;border:none;border-radius:4px;
                       padding:5px 18px;font-size:.9em;cursor:pointer">
            Tirer
        </button>
    </div>
    <div id="draw-result" style="display:flex;gap:10px;flex-wrap:wrap"></div>
</td></tr>
</table>

<script>
var ROOT = '<?= $CFG->ROOT_DIR ?>Modules/Custom/TNM/bso-action.php';

$('#btn-refresh').on('click', load);
$('#sel-event').on('change', load);

function toggleSec(id) {
    var row  = document.getElementById('view-' + id);
    var icon = document.getElementById('cmd-' + id);
    if (row.style.display === 'none') {
        row.style.display = 'table-row';
        icon.classList.remove('fa-caret-right');
        icon.classList.add('fa-caret-down');
    } else {
        row.style.display = 'none';
        icon.classList.remove('fa-caret-down');
        icon.classList.add('fa-caret-right');
    }
}

function load() {
    var ev = $('#sel-event').val();
    if (!ev) {
        $('#pa-t1').html('<p class="pa-empty">Sélectionnez une épreuve.</p>');
        $('#pa-pp, #pa-pc').html('<p class="pa-empty">Sélectionnez une épreuve.</p>');
        return;
    }

    // Tour 1 — composition des poules avec conflits géographiques
    $.getJSON(ROOT, {act: 'getPoolsTour1', event: ev}, function (data) {
        if (data.error) {
            $('#pa-t1').html('<p class="pa-empty">' + escapeHtml(data.msg || 'Erreur') + '</p>');
            return;
        }
        $('#pa-t1').html(buildTour1(data.pools, data.conflicts));
    }).fail(function () {
        $('#pa-t1').html('<p class="pa-empty">Erreur réseau.</p>');
    });

    // Tour 2 — aide à la répartition (matrice conflits poules d'origine)
    $.getJSON(ROOT, {act: 'getPoolsAssist', event: ev}, function (data) {
        if (data.error) {
            $('#pa-pp').html('<p class="pa-empty">' + escapeHtml(data.msg || 'Erreur') + '</p>');
            $('#pa-pc').html('');
            return;
        }
        $('#pa-pp').html(buildTable(data.pp));
        $('#pa-pc').html(buildTable(data.pc));
    }).fail(function () {
        $('#pa-pp').html('<p class="pa-empty">Erreur réseau.</p>');
        $('#pa-pc').html('');
    });
}

// ── Tour 1 : cartes de poules colorées par conflit géographique ───────────────
function buildTour1(pools, conflicts) {
    if (!pools || pools.length === 0)
        return '<p class="pa-empty">Aucune poule Tour 1 trouvée — le classement de qualification doit être validé d\'abord.</p>';

    var summary = '';
    if (conflicts === 0) {
        summary = '<p style="color:#2e7d32;font-weight:bold;margin:0 0 8px">✓ Aucun conflit géographique dans les poules Tour 1.</p>';
    } else {
        summary = '<p style="color:#c00;font-weight:bold;margin:0 0 8px">⚠ ' + conflicts + ' équipe(s) en conflit géographique.</p>';
    }

    var html = summary + '<div class="pa-pool-grid">';
    pools.forEach(function (pool) {
        html += '<div class="pa-pool-card">';
        html += '<div class="pa-pool-title">Poule ' + pool.group + '</div>';
        pool.teams.forEach(function (t) {
            var cls = t.deptConflict ? 'pa-team-dept' : (t.regionConflict ? 'pa-team-region' : 'pa-team-ok');
            html += '<div class="pa-team ' + cls + '">';
            html += '<span class="pa-team-code">' + escapeHtml(t.code) + '</span>';
            html += escapeHtml(t.name);
            html += '</div>';
        });
        html += '</div>';
    });
    html += '</div>';
    return html;
}

// ── Tour 2 : matrice conflits poules d'origine ────────────────────────────────
function buildTable(seg) {
    if (!seg || !seg.rows.length)
        return '<p class="pa-empty">Aucune équipe à classer pour ce segment.</p>';

    var html = '<table class="pa-table"><tr><th>Équipe à placer – Poule</th>';
    seg.columns.forEach(function (c) {
        html += '<th>' + escapeHtml(c.label) + '<br>poule ' + c.dest + '</th>';
    });
    html += '</tr>';

    seg.rows.forEach(function (row) {
        html += '<tr><td class="team-name">' + escapeHtml(row.name) + ' – Poule ' + row.origin + '</td>';
        row.cells.forEach(function (cell, i) {
            var liste = cell.liste.map(function (p) {
                var txt = String(p);
                return (p === row.origin) ? '<strong>' + txt + '</strong>' : txt;
            }).join(' - ');
            if (!liste) liste = '—';
            var cls = (cell.conflict ? 'pa-cell-bad' : 'pa-cell-ok') + (cell.highlight ? ' pa-cell-highlight' : '');
            var symbol = cell.conflict ? '❌' : '✅';
            html += '<td class="' + cls + '">' + symbol + ' ' + liste + '</td>';
        });
        html += '</tr>';
    });

    html += '</table>';
    return html;
}

function escapeHtml(s) {
    return $('<div>').text(s || '').html();
}

// ── Tirage au sort ─────────────────────────────────────────────────────────────
document.getElementById('btn-draw').addEventListener('click', function () {
    var n = Math.max(2, Math.min(20, parseInt(document.getElementById('draw-count').value, 10) || 2));
    var numbers = [];
    // génère n entiers distincts entre 1 et 99
    while (numbers.length < n) {
        var r = Math.floor(Math.random() * 99) + 1;
        if (numbers.indexOf(r) === -1) numbers.push(r);
    }

    // calcule le classement : le numéro le plus élevé = 1er
    var sorted = numbers.slice().sort(function (a, b) { return a - b; });
    var ranks = numbers.map(function (v) { return sorted.indexOf(v) + 1; });

    var suffixes = ['er', 'ème', 'ème', 'ème'];
    var html = '';
    for (var i = 0; i < n; i++) {
        var rank = ranks[i];
        var suffix = rank === 1 ? 'er' : 'ème';
        var isFirst = rank === 1;
        html += '<div class="draw-card">'
              + '<div class="draw-label">Equipe ' + (i + 1) + '</div>'
              + '<div class="draw-number">' + numbers[i] + '</div>'
              + '<div class="draw-rank' + (isFirst ? ' draw-rank-first' : '') + '">' + rank + suffix + '</div>'
              + '</div>';
    }
    document.getElementById('draw-result').innerHTML = html;
});
</script>

<style>
.draw-card {
    border: 2px solid #002B92;
    border-radius: 6px;
    min-width: 80px;
    text-align: center;
    overflow: hidden;
}
.draw-label {
    background: #002B92;
    color: #fff;
    font-size: .8em;
    font-weight: bold;
    padding: 4px 8px;
}
.draw-number {
    font-size: 2em;
    font-weight: bold;
    color: #002B92;
    padding: 10px 8px 4px;
}
.draw-rank {
    font-size: .8em;
    color: #555;
    padding: 0 8px 8px;
}
.draw-rank-first {
    color: #c07000;
    font-weight: bold;
}
</style>

<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
