<?php
// =============================================================================
// BsoCommentateur.php — Vue live commentateur (mobile-first, polling)
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);
$PAGE_TITLE = 'Commentateur BSO';
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
<!DOCTYPE html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $PAGE_TITLE ?></title>
<script src="<?= $CFG->ROOT_DIR ?>Common/js/jquery.min.js"></script>
<style>
* { box-sizing: border-box; }
/* ── Layout mobile-first ─────────────────────────────────────────────── */
* { box-sizing: border-box; }
body { font-family: sans-serif; background: #f4f4f4; margin: 0; }

/* cache le menu ianseo*/
#TourInfo{display:none}
#navigation{display:none}
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

#selector { background:#fff; border-radius:8px; padding:10px; margin-bottom:10px;
            box-shadow:0 1px 4px rgba(0,0,0,.15); }
#selector select { font-size:1em; padding:8px 10px; border-radius:6px; border:1px solid #bbb;
                   width:100%; margin-top:6px; }
.toggles { margin-top:8px; display:flex; gap:14px; flex-wrap:wrap; font-size:.85em; }

#round-info { background:#002B92; color:#fff; border-radius:8px; padding:8px 14px;
              margin-bottom:10px; font-size:.9em; text-align:center; display:none; }
#round-info span { font-weight:bold; }

.table-wrap { overflow-x:auto; }
table.bso { width:100%; border-collapse:collapse; background:#fff; border-radius:8px;
            overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.1); font-size:.85em; }
table.bso th, table.bso td { padding:6px 8px; text-align:center; white-space:nowrap; border-bottom:1px solid #eee; }
table.bso th { background:#002B92; color:#fff; font-size:.8em; }
table.bso td.name { text-align:left; font-weight:bold; white-space:normal; }
table.bso td.code { font-size:.75em; color:#777; font-weight:normal; }

tr.qualifie  { background:#55cc55; color:#000 }
tr.elimine   { background:#aa0000; color:#fff }
tr.tiebreak  { background:#ffff00; }
tr.eliminated-prev { background:#eee; color:#888; }
tr.eliminated-prev td.name { color:#888; }

.sep-row td { background:#ddd; font-weight:bold; text-align:left; padding:6px 8px; font-size:.8em; color:#555; }

.hide-qualif .col-qualif,
.hide-tour1  .col-tour1,
.hide-tour2  .col-tour2 { display:none; }
</style>
</head>


<header class="bso-hdr">
    <div class="bso-hdr-name"><?= htmlspecialchars($compName) ?></div>
    <nav class="bso-hdr-nav">

        <a href="<?= $CFG->ROOT_DIR ?>Main.php" class="bso-nav-btn">
            <span class="bso-nav-ico">🏠</span>Menu
        </a>

        <a href="<?= $tnmBase ?>index.php" class="bso-nav-btn">
            <span class="bso-nav-ico">🖨️</span>Impressions
        </a>

        <a href="<?= $tnmBase ?>BsoSaisie.php" class="bso-nav-btn">
            <span class="bso-nav-ico">✏️</span>Saisie
        </a>

    </nav>
</header>


<div id="selector">
    <label><strong>Épreuve</strong></label>
    <select id="sel-event"><option value="">— Choisir —</option></select>
    <div class="toggles">
        <label><input type="checkbox" id="cbQualif" checked> Scores qualification</label>
        <label><input type="checkbox" id="cbTour1" checked> Infos Tour 1</label>
        <label><input type="checkbox" id="cbTour2" checked> Infos Tour 2</label>
    </div>
</div>

<div id="round-info">Volée <span id="ri-round">—</span></div>

<div class="table-wrap">
<table class="bso" id="bso-table">
    <thead>
    <tr>
        <th>Cl.</th>
        <th>Cible</th>
        <th class="name">Équipe</th>
        <th>Scores</th>
        <th class="col-qualif">Qualif</th>
        <th class="col-tour1">Tour 1</th>
        <th class="col-tour2">Tour 2</th>
    </tr>
    </thead>
    <tbody id="bso-body"></tbody>
</table>
</div>

<script>
var ROOT = '<?= $CFG->ROOT_DIR ?>Modules/Custom/TNM/bso-action.php';
var currentEvent = null;
var refreshTimer = null;
var REFRESH_MS = 1500;

$.getJSON(ROOT, {act:'getEvents'}, function(data) {
    if (data.error) return;
    data.rows.forEach(function(ev) {
        $('#sel-event').append($('<option>').val(ev.code).text(ev.code+' – '+ev.name));
    });
});

$('#sel-event').on('change', function() {
    currentEvent = this.value;
    clearTimeout(refreshTimer);
    if (currentEvent) load();
    else { $('#bso-body').empty(); $('#round-info').hide(); }
});

['#cbQualif','#cbTour1','#cbTour2'].forEach(function(id) {
    $(id).on('change', updateColumnVisibility);
});
function updateColumnVisibility() {
    var t = $('#bso-table');
    t.toggleClass('hide-qualif', !$('#cbQualif').is(':checked'));
    t.toggleClass('hide-tour1',  !$('#cbTour1').is(':checked'));
    t.toggleClass('hide-tour2',  !$('#cbTour2').is(':checked'));
}
updateColumnVisibility();

function fmtScores(scores) {
    return scores.map(function(s){ return s !== null ? s : '—'; }).join(' - ');
}
function fmtPool(p) {
    if (!p) return '—';
    var suffix = p.rank === 1 ? 'er' : 'ème';
    return p.rank + '<sup>' + suffix + '</sup><br>Poule ' + p.group;
}

function load() {
    clearTimeout(refreshTimer);
    $.getJSON(ROOT, {act:'getCommentateur', event:currentEvent}, function(data) {
        if (data.error) { scheduleRefresh(); return; }

        $('#ri-round').text(data.currentRound);
        $('#round-info').show();

        // L'égalité doit être départagée si tous les scores de la volée en cours
        // sont saisis pour une équipe encore en statut indéterminé.
        var allScored = data.current.length > 0 && data.current.every(function(r) {
            return r.scores[r.scores.length - 1] !== null;
        });

        var body = $('#bso-body').empty();

        data.current.forEach(function(r) {
            var cls = '';
            if (r.status === 0)      cls = 'elimine';
            else if (r.status === 1) cls = 'qualifie';
            else if (r.status === null && allScored) cls = 'tiebreak';

            var tr = $('<tr>').addClass(cls);
            tr.append($('<td style="font-size:1.5em;font-weight:bold;">').text(r.rank !== null ? r.rank : '—'));
            tr.append($('<td>').text(r.target));

            var nameTd = $('<td class="name" style="font-size:1.5em;font-weight:bold;">').text(r.name || '—');
            if (r.code) nameTd.append($('<div class="code" style="font-size:0.75em;font-weight:normal;">').text(r.code));
            tr.append(nameTd);

            tr.append($('<td style="font-size:1.5em;font-weight:bold;">').text(fmtScores(r.scores)));
            tr.append($('<td class="col-qualif">').text(r.teScore !== null ? r.teScore : '—'));
            tr.append($('<td class="col-tour1">').html(fmtPool(r.pools[1])));
            tr.append($('<td class="col-tour2">').html(fmtPool(r.pools[2])));
            body.append(tr);
        });

        if (data.eliminated.length > 0) {
            var sep = $('<tr class="sep-row">');
            sep.append($('<td colspan="7">').text('Équipes éliminées'));
            body.append(sep);

            data.eliminated.forEach(function(r) {
                var tr = $('<tr class="eliminated-prev">');
                tr.append($('<td>').text(r.rank));
                tr.append($('<td>').text('—'));

                var nameTd = $('<td class="name">').text(r.name || '—');
                if (r.code) nameTd.append($('<div class="code">').text(r.code));
                tr.append(nameTd);

                tr.append($('<td>').text(fmtScores(r.scores)));
                tr.append($('<td class="col-qualif">').text(r.teScore !== null ? r.teScore : '—'));
                tr.append($('<td class="col-tour1">').html(fmtPool(r.pools[1])));
                tr.append($('<td class="col-tour2">').html(fmtPool(r.pools[2])));
                body.append(tr);
            });
        }

        scheduleRefresh();
    }).fail(scheduleRefresh);
}

function scheduleRefresh() {
    refreshTimer = setTimeout(load, REFRESH_MS);
}
</script>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');