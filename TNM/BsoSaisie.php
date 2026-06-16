<?php
// =============================================================================
// BsoSaisie.php — Saisie des scores BSO (mobile-first, multi-utilisateur)
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

$PAGE_TITLE = 'Saisie BSO';
$IncludeJquery = true;
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
// Header personnalisé (cf. BsoSaisie.php) : nom de la compétition + boutons
// ── Nom de la compétition (session ou DB) ────────────────────────────────────
$compName = $_SESSION['TourNameSafe'] ?? '';
if (!$compName) {
    $rs = safe_r_sql("SELECT ToName FROM Tournament WHERE ToId=$tourId LIMIT 1");
    if ($r = safe_fetch($rs)) $compName = $r->ToName;
}
$tnmBase = $CFG->ROOT_DIR . 'Modules/Custom/TNM/';
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
/* ── Layout mobile-first ─────────────────────────────────────────────── */
* { box-sizing: border-box; }
body { font-family: sans-serif; background: #f4f4f4; margin: 0; }

/* cache le menu ianseo*/
#TourInfo{display:none}
#navigation{display:none}
#tnm-nav{display:none}
.modal{dsplay:none}
#Content{padding:0;height:auto}

/* ── Header maison ── */

.bso-hdr{
    background:#002B92;color:#fff;
    padding:8px 12px;
    position:sticky;top:0;z-index:100;
    box-shadow:0 2px 6px rgba(0,0,0,.35);
}
.bso-hdr-name{
    font-size:.72em;opacity:.8;text-align:center;
    letter-spacing:.04em;text-transform:uppercase;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    margin-bottom:6px;
}
.bso-hdr-nav{display:flex;gap:6px}
.bso-nav-btn{
    flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;
    background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
    border-radius:8px;padding:5px 6px;
    color:#fff;text-decoration:none;font-size:.68em;line-height:1.2;
    -webkit-tap-highlight-color:transparent;
}
.bso-nav-btn:active{background:rgba(255,255,255,.3)}
.bso-nav-ico{font-size:1.5em;line-height:1}
.bso-body{padding: 8px; }


#selector { background:#fff; border-radius:8px; padding:12px; margin-bottom:10px;
            box-shadow:0 1px 4px rgba(0,0,0,.15); }
#selector select, #selector button {
    font-size:1em; padding:8px 10px; border-radius:6px; border:1px solid #bbb;
    width:100%; margin-top:6px; }
#selector button { background:#002B92; color:#fff; border:none; cursor:pointer; font-weight:bold; }
#selector button:active { background:#001a60; }

#round-info { background:#002B92; color:#fff; border-radius:8px; padding:10px 14px;
              margin-bottom:10px; font-size:.9em; display:none; }
#round-info span { font-weight:bold; font-size:1.1em; }

/* ── Tableau de saisie ───────────────────────────────────────────────── */
#saisie-table { width:100%; border-collapse:collapse; }
.row-team { background:#fff; border-radius:8px; margin-bottom:6px;
            box-shadow:0 1px 3px rgba(0,0,0,.12); display:flex;
            align-items:center; padding:8px; gap:8px; }
.row-team.qualifie  { border-left:5px solid #2ea84e; }
.row-team.elimine   { border-left:5px solid #d9363e; opacity:.6; }
.row-team.en-attente{ border-left:5px solid #aaa; }

.cell-tgt  { width:38px; text-align:center; font-size:.85em; color:#555;
             font-weight:bold; background:#eee; border-radius:4px; padding:4px; flex-shrink:0; }
.cell-name { flex:1; font-weight:bold; font-size:.95em; line-height:1.2; }
.cell-code { font-size:.75em; color:#777; }
.cell-score{ width:90px; flex-shrink:0; }
.cell-score input {
    width:100%; font-size:1.4em; font-weight:bold; text-align:center;
    padding:6px 2px; border:2px solid #ccc; border-radius:6px;
    -moz-appearance:textfield; }
.cell-score input:focus { border-color:#002B92; outline:none; }
.cell-score input.saved { border-color:#2ea84e; background:#f0fff4; }
.cell-score input.saving{ border-color:#f0a500; background:#fffde7; }

.cell-status { width:42px; flex-shrink:0; display:flex; flex-direction:column; gap:4px; }
.btn-ok  { background:#2ea84e; color:#fff; border:none; border-radius:5px;
           padding:6px 0; width:100%; font-size:.8em; cursor:pointer; font-weight:bold; }
.btn-ko  { background:#d9363e; color:#fff; border:none; border-radius:5px;
           padding:6px 0; width:100%; font-size:.8em; cursor:pointer; font-weight:bold; }
.btn-ok:active { background:#1d7a37; }
.btn-ko:active { background:#a02028; }
.btn-ok.active { box-shadow:0 0 0 2px #2ea84e, 0 0 0 4px #fff; }
.btn-ko.active { box-shadow:0 0 0 2px #d9363e, 0 0 0 4px #fff; }
.btn-rank { background:#f0a500; color:#fff; border:none; border-radius:5px;
            padding:6px 0; width:100%; font-size:.75em; cursor:pointer; font-weight:bold; margin-top:2px; }
.rank-badge { text-align:center; font-size:.8em; font-weight:bold; color:#555; margin-top:2px; }
.rank-badge.rank-1 { color:#f0a500; font-size:1.1em; }
.btn-rank.active { box-shadow:0 0 0 2px #f0a500, 0 0 0 4px #fff; }

#btn-next-round {
    font-size:1em; padding:8px 10px; border-radius:6px; border:1px solid #bbb;
    width:100%; margin-top:6px;
    background:#00922B; color:#fff; border:none; cursor:pointer; font-weight:bold; }
#btn-next-round:active { background:#00601a; }

#refresh-bar { text-align:right; font-size:.75em; color:#999; padding:2px 0 6px; }
</style>


<header class="bso-hdr">
    <div class="bso-hdr-name"><?= htmlspecialchars($compName) ?></div>
    <nav class="bso-hdr-nav">

        <a href="<?= $CFG->ROOT_DIR ?>Main.php" class="bso-nav-btn">
            <span class="bso-nav-ico">🏠</span>Menu
        </a>

        <a href="<?= $tnmBase ?>index.php" class="bso-nav-btn">
            <span class="bso-nav-ico">🖨️</span>Impressions
        </a>

        <a href="<?= $tnmBase ?>BsoCommentateur.php" class="bso-nav-btn">
            <span class="bso-nav-ico">📺</span>Commentateur
        </a>

    </nav>
</header>

<div class="bso-body">
<div id="selector">
    <label><strong>Épreuve</strong></label>
    <select id="sel-event"><option value="">— Choisir —</option></select>
    <label style="margin-top:8px;display:block"><strong>Volée</strong></label>
    <select id="sel-round" disabled>
        <option value="1">Volée 1</option>
        <option value="2">Volée 2</option>
        <option value="3">Volée 3</option>
        <option value="4">Volée 4</option>
    </select>
    <button id="btn-load" disabled>Charger</button>
</div>

<div id="round-info">
    Volée <span id="ri-round">—</span> — <span id="ri-event">—</span>
    &nbsp;|&nbsp; <span id="ri-count">—</span> équipes
</div>



<div id="refresh-bar">actualisation auto</div>
<div id="teams-container"></div>
</div><!-- /.bso-body -->


<script>
var ROOT = '<?= $CFG->ROOT_DIR ?>Modules/Custom/TNM/bso-action.php';
var tourId = <?= $tourId ?>;
var currentEvent = null;
var currentRound = 1;
var serverDate   = '';
var refreshTimer = null;
var REFRESH_MS   = 1500;
var pendingSaves = {}; // teamId → timeout
var OPTION_DEFS = {
    qualify:   {label:'✓',         status:1, rank:null, cls:'btn-ok',
                isActive:r => r.status===1 && r.rank===null},
    eliminate: {label:'✗',         status:0, rank:null, cls:'btn-ko',
                isActive:r => r.status===0 && r.rank===null},
    winner:    {label:'Vainqueur', status:1, rank:1,    cls:'btn-ok',
                isActive:r => r.status===1 && r.rank===1},
    rank4:     {label:'4ème',      status:0, rank:4,    cls:'btn-rank',
                isActive:r => r.status===0 && r.rank===4},
    rank3:     {label:'3ème',      status:0, rank:3,    cls:'btn-rank',
                isActive:r => r.status===0 && r.rank===3}
};
var eventBsoCount = {};
var currentBsoCount = 10;

// ── Init : charger les épreuves ───────────────────────────────────────────────
$.getJSON(ROOT, {act:'getEvents'}, function(data) {
    if (data.error) return;
    data.rows.forEach(function(ev) {
        eventBsoCount[ev.code] = ev.bso_count;
        $('#sel-event').append($('<option>').val(ev.code).text(ev.code+' – '+ev.name));
    });
    $('#sel-event').prop('disabled', false);
});

$('#sel-event').on('change', function() {
    var ok = this.value !== '';
    $('#sel-round, #btn-load').prop('disabled', !ok);
});

$('#btn-load').on('click', function() {
    currentEvent = $('#sel-event').val();
    currentBsoCount = eventBsoCount[currentEvent] || 10;
    currentRound = parseInt($('#sel-round').val());
    serverDate = '';
    loadVolee(true);
});

function checkRoundComplete(rows, totalRounds) {
    var complete = rows.length > 0
        && rows.every(r => r.score !== null && r.status !== null)
        && currentRound < totalRounds;

    var btn = $('#btn-next-round');
    if (complete && btn.length === 0) {
        $('<button id="btn-next-round">Volée suivante</button><div style="height:8px"></div>')
            .prependTo('#teams-container');
    } else if (!complete) {
        btn.next('div').remove();
        btn.remove();
    }
}

// ── Chargement / refresh de la volée ─────────────────────────────────────────
function loadVolee(full) {
    clearTimeout(refreshTimer);
    var params = {act:'getVolee', event:currentEvent, round:currentRound};
    if (!full && serverDate) params.since = serverDate;

    $.getJSON(ROOT, params, function(data) {
        if (data.error) { scheduleRefresh(); return; }
        serverDate = data.serverDate;

        if (full) {
            renderTeams(data.rows);
        } else {
            data.rows.forEach(updateTeamRow);
        }
        updateRoundInfo(data.rows.length);
        var totalRounds = currentBsoCount > 8 ? 4 : 3;
        checkRoundComplete(data.rows, totalRounds);

        $('#btn-next-round').on('click', function() {
            var nextRound = currentRound + 1;
            $.getJSON(ROOT, {act:'initVolee', event:currentEvent, round:nextRound}, function(data) {
                if (data.error) { alert('Erreur : '+(data.msg||'')); return; }
                currentRound = nextRound;
                $('#sel-round').val(nextRound);
                serverDate = '';
                loadVolee(true);
            });
        });

        $('#refresh-bar').text('dernière màj : '+new Date().toLocaleTimeString());
        scheduleRefresh();
    }).fail(scheduleRefresh);
}

function scheduleRefresh() {
    refreshTimer = setTimeout(function(){ loadVolee(false); }, REFRESH_MS);
}

// À placer UNE SEULE FOIS, en dehors de loadVolee/renderTeams
$('#teams-container').on('click', '#btn-next-round', function() {
    var nextRound = currentRound + 1;
    $.getJSON(ROOT, {act:'initVolee', event:currentEvent, round:nextRound}, function(data) {
        if (data.error) { alert('Erreur : '+(data.msg||'')); return; }
        currentRound = nextRound;
        $('#sel-round').val(nextRound);
        serverDate = '';
        loadVolee(true);
    });
});

// ── Rendu initial ─────────────────────────────────────────────────────────────
function renderTeams(rows) {
    var c = $('#teams-container').empty();
    c.append('<button id="btn-next-round">Volée suivante</button><div style="height:8px"></div>');
    rows.forEach(function(r) {
        c.append(buildRow(r));
    });
    $('#round-info').show();
}

function buildRow(r) {
    var statusClass = r.status === 1 ? 'qualifie' : r.status === 0 ? 'elimine' : 'en-attente';
    var div = $('<div class="row-team '+statusClass+'" id="row-'+r.team+'">');

    div.append($('<div class="cell-tgt">').text(r.target));

    var nameDiv = $('<div class="cell-name">');
    nameDiv.append($('<div>').text(r.name || '—'));
    nameDiv.append($('<div class="cell-code">').text(r.code || ''));
    div.append(nameDiv);

    var scoreDiv = $('<div class="cell-score">');
    var input = $('<input type="number" min="0" max="400" placeholder="—">')
        .val(r.score !== null ? r.score : '')
        .attr('data-team', r.team)
        .on('input', function() { onScoreInput(this); })
        .on('blur',  function() { flushScore(r.team); });
    if (r.score !== null) input.addClass('saved');
    scoreDiv.append(input);
    div.append(scoreDiv);

    div.append(buildStatusCell(r));
    return div;
}

function updateTeamRow(r) {
    var row = $('#row-'+r.team);
    if (!row.length) return;

    row.removeClass('qualifie elimine en-attente');
    row.addClass(r.status===1?'qualifie':r.status===0?'elimine':'en-attente');

    var input = row.find('input[data-team="'+r.team+'"]');
    if (!input.is(':focus') && !pendingSaves[r.team]) {
        input.val(r.score !== null ? r.score : '');
        input.toggleClass('saved', r.score !== null);
    }

    row.find('.cell-status').replaceWith(buildStatusCell(r));
}

function updateRoundInfo(count) {
    $('#ri-event').text($('#sel-event option:selected').text());
    $('#ri-round').text(currentRound);
    $('#ri-count').text(count);
}

// ── Saisie score (debounce 800ms) ─────────────────────────────────────────────
function onScoreInput(input) {
    var team = $(input).data('team');
    $(input).removeClass('saved').addClass('saving');
    clearTimeout(pendingSaves[team]);
    pendingSaves[team] = setTimeout(function() { flushScore(team); }, 800);
}

function flushScore(team) {
    clearTimeout(pendingSaves[team]);
    delete pendingSaves[team];
    var input = $('input[data-team="'+team+'"]');
    var val   = input.val();
    if (val === '') return;
    $.getJSON(ROOT, {act:'saveScore', event:currentEvent, round:currentRound,
                     team:team, score:parseInt(val)}, function(data) {
        if (!data.error) input.removeClass('saving').addClass('saved');
        else             input.removeClass('saving saved');
    });
}

// ── Statut qualifié / éliminé ─────────────────────────────────────────────────
function buildStatusCell(r) {
    var div = $('<div class="cell-status">');
    (r.options || []).forEach(function(opt) {
        var def = OPTION_DEFS[opt];
        if (!def) return;
        var btn = $('<button>').addClass(def.cls).text(def.label)
            .on('click', function() { setStatus(r.team, def.status, def.rank); })
            .appendTo(div);
        if (def.isActive(r)) btn.addClass('active');
    });
    // badge de place uniquement si pas de boutons (place définitive, non ambiguë)
    if ((r.options||[]).length === 0) {
        if (r.status === 0 && r.rank !== null) div.append($('<div class="rank-badge">').text(r.rank+'e'));
        if (r.status === 1 && r.rank === 1)     div.append($('<div class="rank-badge rank-1">').text('🏆 1er'));
    }
    return div;
}

function setStatus(team, status, rank) {
    var params = {act:'setStatus', event:currentEvent, round:currentRound, team:team, status:status};
    if (rank !== null) params.rank = rank;
    $.getJSON(ROOT, params, function(data) {
        if (!data.error) loadVolee(true); // recharge complète : les recalculs peuvent toucher d'autres lignes
    });
}
</script>