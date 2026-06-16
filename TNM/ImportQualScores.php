<?php
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

$tourId = intval($_SESSION['TourId']);

$rsTour = safe_r_sql("SELECT ToId, ToName FROM Tournament WHERE ToId=$tourId LIMIT 1");
if (!($tour = safe_fetch($rsTour))) {
    die('Compétition introuvable (TourId=' . $tourId . '). Vérifiez que la session est ouverte sur une compétition valide.');
}

// =============================================================================
// ACTION : RESET SCORES
// =============================================================================
$resetDone  = false;
$resetCount = 0;

if (!empty($_POST['act']) && $_POST['act'] === 'reset') {
    safe_w_sql("DELETE FROM Teams WHERE TeTournament=$tourId");
    safe_w_sql(
        "UPDATE RoundRobinMatches
         SET RrMatchAthlete=0, RrMatchSubTeam=0
         WHERE RrMatchTournament=$tourId AND RrMatchTeam=1"
    );
    safe_w_sql(
        "UPDATE Events SET EvE1ShootOff=0
         WHERE EvTeamEvent=1 AND EvTournament=$tourId"
    );
    safe_w_sql(
        "UPDATE Qualifications
         INNER JOIN Entries ON QuId=EnId AND EnTournament=$tourId
         SET QuD1Score=0, QuScore=0, QuD1Hits=0, QuHits=0, QuD1Rank=0, QuClRank=0"
    );
    $resetCount = safe_w_affected_rows();
    $resetDone  = true;
}

// =============================================================================
// ACTION : IMPORT SCORES
// =============================================================================
$imported   = 0;
$rowResults = [];
$doPostCalc = false;

if (!empty($_POST['txtScores'])) {
    $raw   = str_replace("\r", "", trim($_POST['txtScores']));
    $lines = explode("\n", $raw);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $cols = explode("\t", $line);

        if (count($cols) < 2) {
            $rowResults[] = ['licence' => htmlspecialchars($line), 'name' => '', 'score' => '',
                'ok' => false, 'msg' => 'Ligne invalide : 2 colonnes attendues (Licence [tab] Score)'];
            continue;
        }

        $licence  = trim($cols[0]);
        $scoreRaw = trim($cols[1]);

        if (!preg_match('/^[\w\-\.]+$/', $licence)) {
            $rowResults[] = ['licence' => htmlspecialchars($licence), 'name' => '', 'score' => $scoreRaw,
                'ok' => false, 'msg' => 'Numéro de licence invalide'];
            continue;
        }

        if (!preg_match('/^\d+$/', $scoreRaw)) {
            $rowResults[] = ['licence' => htmlspecialchars($licence), 'name' => '', 'score' => htmlspecialchars($scoreRaw),
                'ok' => false, 'msg' => 'Score non numérique'];
            continue;
        }

        $score = intval($scoreRaw);
        if ($score < 0 || $score > 9999) {
            $rowResults[] = ['licence' => htmlspecialchars($licence), 'name' => '', 'score' => $score,
                'ok' => false, 'msg' => 'Score hors limites (0–9999)'];
            continue;
        }

        $rsEn = safe_r_sql(
            "SELECT EnId, EnName, EnFirstName FROM Entries
             WHERE EnTournament=$tourId AND EnCode=" . StrSafe_DB($licence) . " LIMIT 1"
        );
        $en = safe_fetch($rsEn);
        if (!$en) {
            $rowResults[] = ['licence' => htmlspecialchars($licence), 'name' => '', 'score' => $score,
                'ok' => false, 'msg' => 'Licence introuvable dans cette compétition'];
            continue;
        }

        safe_w_sql(
            "UPDATE Qualifications SET QuD1Score=$score, QuScore=$score, QuD1Hits=10, QuHits=10
             WHERE QuId=$en->EnId"
        );

        $rowResults[] = [
            'licence' => htmlspecialchars($licence),
            'name'    => htmlspecialchars($en->EnFirstName . ' ' . $en->EnName),
            'score'   => $score,
            'ok'      => true,
            'msg'     => 'OK',
        ];
        $imported++;
    }

    $doPostCalc = ($imported > 0);
}

// =============================================================================
// PRÉ-CALCUL DU CLASSEMENT QUALIFICATION → POULES
// =============================================================================
$qualEvents = [];
$rsEvts = safe_r_sql(
    "SELECT DISTINCT TeEvent AS EvCode, EvEventName
     FROM Teams
     INNER JOIN Events ON EvTournament=TeTournament AND EvCode=TeEvent AND EvTeamEvent=1
     WHERE TeTournament=$tourId AND TeFinEvent=1
     ORDER BY TeEvent"
);
while ($ev = safe_fetch($rsEvts)) {
    $rsTeams = safe_r_sql(
        "SELECT t.TeCoId, t.TeSubTeam, t.TeScore, t.TeGold, t.TeXNine,
                co.CoName, co.CoCode
         FROM Teams t
         INNER JOIN Countries co ON co.CoId=t.TeCoId
         WHERE t.TeTournament=$tourId AND t.TeEvent=" . StrSafe_DB($ev->EvCode) . " AND t.TeFinEvent=1"
    );
    $teams = [];
    while ($t = safe_fetch($rsTeams)) {
        $teams[] = $t;
    }

    usort($teams, function ($a, $b) {
        $aZero = ($a->TeScore == 0);
        $bZero = ($b->TeScore == 0);
        if ($aZero !== $bZero) return $aZero ? 1 : -1;
        if ($a->TeScore !== $b->TeScore) return $b->TeScore <=> $a->TeScore;
        if ($a->TeGold  !== $b->TeGold)  return $b->TeGold  <=> $a->TeGold;
        if ($a->TeXNine !== $b->TeXNine) return $b->TeXNine <=> $a->TeXNine;
        return crc32((string)$a->TeCoId) <=> crc32((string)$b->TeCoId);
    });

    foreach ($teams as $i => $t) {
        $teams[$i]->ComputedRank = $i + 1;
    }

    $rsVal   = safe_r_sql("SELECT EvE1ShootOff FROM Events WHERE EvTournament=$tourId AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($ev->EvCode) . " LIMIT 1");
    $valRow  = safe_fetch($rsVal);

    $rsPool  = safe_r_sql("SELECT RrLevGroups FROM RoundRobinLevel WHERE RrLevTournament=$tourId AND RrLevTeam=1 AND RrLevLevel=1 AND RrLevEvent=" . StrSafe_DB($ev->EvCode) . " LIMIT 1");
    $poolRow = safe_fetch($rsPool);
    $poolCount = ($poolRow && intval($poolRow->RrLevGroups) > 0)
        ? intval($poolRow->RrLevGroups)
        : max(1, (int)ceil(count($teams) / 4));

    $qualEvents[] = [
        'code'      => $ev->EvCode,
        'name'      => get_text($ev->EvEventName, '', '', true),
        'teams'     => $teams,
        'validated' => $valRow ? (bool)$valRow->EvE1ShootOff : false,
        'poolCount' => $poolCount,
    ];
}

// =============================================================================
// RENDU HTML
// =============================================================================
$PAGE_TITLE    = 'Import scores qualification — TNM';
$IncludeJquery = true;
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.tnm-table { border-collapse: collapse; width: 100%; font-size: 13px; }
.tnm-table th, .tnm-table td { border: 1px solid #ccc; padding: 3px 7px; }
.tnm-table thead th { background: #002B92; color: #fff; }
.row-ok  { background: #e6f9e6; }
.row-err { background: #fde8e8; }
.badge-valid   { color: #2e7d32; font-weight: bold; }
.badge-pending { color: #e67e00; font-weight: bold; }
.btn-danger  { background: #c00; color: #fff; border: none; padding: 6px 18px; font-size: 14px; cursor: pointer; border-radius: 3px; }
.btn-danger:hover  { background: #900; }
.btn-primary { background: #002B92; color: #fff; border: none; padding: 6px 18px; font-size: 14px; cursor: pointer; border-radius: 3px; }
.btn-primary:hover { background: #001a5c; }
#ev-select { font-size: 14px; padding: 4px 8px; width: 100%; box-sizing: border-box; margin-bottom: 10px; }
.ev-block { display: none; }
#val-progress p { margin: 2px 0; font-size: 13px; }
#post-calc-box { padding: 10px 14px; border: 1px solid #aac; background: #f0f4ff; border-radius: 4px; margin-top: 12px; }
#post-calc-box p { margin: 3px 0; font-size: 13px; }

/* ── Tableau snake ── */
.snake-pool-num { text-align: center; font-weight: bold; color: #002B92; font-size: 13px; white-space: nowrap; padding: 4px 8px !important; background: #f0f4ff; }
.snake-cell { font-size: 12px; padding: 3px 6px !important; vertical-align: middle; }
.draggable-cell { cursor: grab; }
.draggable-cell:active { cursor: grabbing; }
.draggable-cell.drag-over { outline: 2px solid #002B92; background: #dce8ff !important; }
.snake-cell-content { display: flex; justify-content: space-between; align-items: center; gap: 4px; }
.drag-grip { color: #ccc; font-size: 13px; flex-shrink: 0; }
.snake-cell-info { min-width: 0; flex: 1; }
.snake-cell-rank { color: #aaa; font-size: 10px; }
.snake-cell-code { font-family: monospace; font-size: 11px; color: #555; }
.snake-cell-score { color: #333; font-size: 12px; font-weight: bold; white-space: nowrap; flex-shrink: 0; }
.snake-cell-bye { color: #ddd; text-align: center; background: #fafafa !important; }
</style>

<?php // ── SECTION 1 : Gestion des qualifications ──────────────────────────────── ?>
<table class="Tabella" style="margin-bottom:16px">
<tr>
    <th class="TitleLeft p-2" onclick="toggleSec('s1')" style="cursor:pointer">
        <i id="cmd-s1" class="fa-solid fa-caret-down fa-lg mr-1"></i>
        1. Gestion des qualifications
    </th>
</tr>
<tr id="view-s1"><td style="padding:12px 16px">

    <?php if ($resetDone): ?>
        <p style="color:green;font-weight:bold;margin-top:0">✔ Reset effectué — <?= $resetCount ?> participant<?= $resetCount > 1 ? 's' : '' ?> remis à zéro.</p>
    <?php endif; ?>

    <div style="display:flex;gap:24px;align-items:flex-start">

        <div style="flex:2">
            <strong>Importer des scores</strong>
            <p style="margin:4px 0">Collez les données du tableur (une ligne par archer)&nbsp;:</p>
            <pre style="background:#f5f5f5;padding:4px 10px;border:1px solid #ccc;font-size:12px;display:inline-block;margin:0 0 8px">Licence[tab]Score
1062728W[tab]92
0987654Z[tab]285</pre>
            <form method="post">
                <input type="hidden" name="act" value="import">
                <textarea name="txtScores" rows="8" style="width:100%;font-family:monospace;font-size:13px;box-sizing:border-box" placeholder="Collez ici (Licence [tab] Score)"><?= isset($_POST['txtScores']) ? htmlspecialchars($_POST['txtScores']) : '' ?></textarea>
                <br><br>
                <button type="submit" class="btn-primary">Importer les scores</button>
            </form>
        </div>
        

        <div style="flex:1;border-left:1px solid #ddd;padding-left:20px">
            <p>Compétition active&nbsp;: <strong><?= htmlspecialchars($tour->ToName) ?></strong> (ID&nbsp;<?= $tourId ?>)</p>

            <strong style="color:#c00;cursor:pointer" onclick="toggleReset()">
                <i id="cmd-reset" class="fa-solid fa-caret-right mr-1"></i>Réinitialiser les scores
            </strong>
            <div id="view-reset" style="display:none">
                <p style="margin:8px 0;font-size:13px">Remet à zéro les scores, classements et nombre de flèches de <strong>tous les participants</strong>, supprime les équipes et déaffecte les matchs de poules.<br>
                <small>Cette opération est irréversible.</small></p>
                <form method="post" onsubmit="return confirm('Remettre tous les scores à 0 ?\nCette opération est irréversible.')">
                    <input type="hidden" name="act" value="reset">
                    <button type="submit" class="btn-danger">Remettre les scores à 0</button>
                </form>
            </div>
        </div>

    </div>

    <?php if (!empty($rowResults)): ?>
    <hr style="margin:16px 0">
    <h4 style="margin:0 0 8px">
        Résultat : <?= $imported ?> importé<?= $imported > 1 ? 's' : '' ?>
        sur <?= count($rowResults) ?> ligne<?= count($rowResults) > 1 ? 's' : '' ?>
    </h4>
    <table class="tnm-table">
        <thead>
            <tr><th>Statut</th><th>Licence</th><th>Archer</th><th>Score</th><th>Message</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rowResults as $r): ?>
            <tr class="<?= $r['ok'] ? 'row-ok' : 'row-err' ?>">
                <td><?= $r['ok'] ? '✔' : '✘' ?></td>
                <td><?= $r['licence'] ?></td>
                <td><?= $r['name'] ?></td>
                <td><?= $r['score'] ?></td>
                <td><?= $r['msg'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($doPostCalc): ?>
    <div id="post-calc-box">
        <strong>Recalcul en cours…</strong>
        <div id="post-calc-status"></div>
    </div>
    <script>
    (function () {
        var qualUrl = <?= json_encode($CFG->ROOT_DIR . 'Qualification/') ?>;
        var box = document.getElementById('post-calc-status');
        function step(msg, ok) {
            var color = ok === undefined ? '#333' : (ok ? 'green' : '#c00');
            box.innerHTML += '<p style="color:' + color + '">' + msg + '</p>';
        }
        step('Création des équipes…');
        $.getJSON(qualUrl + 'MakeTeams.php').done(function (d) {
            step(d.msg, d.error === 0);
            $.getJSON(qualUrl + 'MakeTeamsAbs.php').done(function (d2) {
                step(d2.msg, d2.error === 0);
                step('Calcul classement distance 1…');
                $.getJSON(qualUrl + 'CalcRank.php', {Dist: 1}).done(function (d3) {
                    step(d3.msg, d3.error === 0);
                    step('Calcul classement total…');
                    $.getJSON(qualUrl + 'CalcRank.php').done(function (d4) {
                        step(d4.msg, d4.error === 0);
                        step('Terminé — rechargez la page pour mettre à jour le classement.', true);
                        document.querySelector('#post-calc-box strong').textContent = 'Recalcul terminé.';
                    }).fail(function () { step('Erreur CalcRank total.', false); });
                }).fail(function () { step('Erreur CalcRank distance 1.', false); });
            }).fail(function () { step('Erreur MakeTeamsAbs.', false); });
        }).fail(function () { step('Erreur MakeTeams.', false); });
    })();
    </script>
    <?php endif; ?>

</td></tr>
</table>

<?php // ── SECTION 2 : Validation ─────────────────────────────────────────────── ?>
<table class="Tabella" style="margin-bottom:16px">
<tr>
    <th class="TitleLeft p-2" onclick="toggleSec('s2')" style="cursor:pointer">
        <i id="cmd-s2" class="fa-solid fa-caret-down fa-lg mr-1"></i>
        2. Validation qualification → poules
    </th>
</tr>
<tr id="view-s2"><td style="padding:12px 16px">
<?php if (empty($qualEvents)): ?>
    <p style="color:#888"><em>Aucune équipe qualifiée trouvée (lancez d'abord l'import et le recalcul).</em></p>
<?php else: ?>
    <p style="font-size:13px;margin-top:0">Scores issus du dernier CalcRank. Glissez-déposez chaque équipe (⠿) vers la place souhaitée pour ajuster le classement avant de valider.</p>

    <select id="ev-select" onchange="showEvent(this.value)">
    <?php foreach ($qualEvents as $evData): ?>
        <option value="<?= htmlspecialchars($evData['code']) ?>">
            <?= $evData['validated'] ? '✓' : '●' ?> <?= htmlspecialchars($evData['code'] . ' — ' . $evData['name']) ?>
        </option>
    <?php endforeach; ?>
    </select>

    <?php foreach ($qualEvents as $evData): ?>
    <div id="ev-<?= htmlspecialchars($evData['code']) ?>" class="ev-block">

        <p style="margin:0 0 10px">
            <?php if ($evData['validated']): ?>
                <span class="badge-valid">✓ Classement déjà validé</span>
            <?php else: ?>
                <span class="badge-pending">● En attente de validation</span>
            <?php endif; ?>
            <span style="font-size:12px;color:#888;margin-left:12px"><?= count($evData['teams']) ?> équipes — <?= $evData['poolCount'] ?> poules</span>
        </p>

        <div id="snake-<?= htmlspecialchars($evData['code']) ?>"></div>

        <br>
        <button class="btn-primary" data-ev="<?= htmlspecialchars($evData['code']) ?>" onclick="validateCurrent(this.dataset.ev, this)">
            Valider le classement <?= htmlspecialchars($evData['code']) ?>
        </button>

    </div>
    <?php endforeach; ?>

    <div id="val-progress" style="margin-top:8px"></div>
<?php endif; ?>
</td></tr>
</table>

<script>
var validateUrl    = <?= json_encode($CFG->ROOT_DIR . 'Modules/Custom/TNM/QualValidate-action.php') ?>;
var validateEvents = <?= json_encode(array_map(function ($e) { return $e['code']; }, $qualEvents)) ?>;

var teamsData  = {};
var poolCounts = {};
<?php foreach ($qualEvents as $evData): ?>
teamsData[<?= json_encode($evData['code']) ?>] = <?= json_encode(array_map(function ($t) {
    return ['coId'    => intval($t->TeCoId),
            'subTeam' => intval($t->TeSubTeam),
            'code'    => $t->CoCode,
            'name'    => $t->CoName,
            'score'   => intval($t->TeScore)];
}, $evData['teams'])) ?>;
poolCounts[<?= json_encode($evData['code']) ?>] = <?= intval($evData['poolCount']) ?>;
<?php endforeach; ?>

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Toggle reset panel ────────────────────────────────────────────────────────
function toggleReset() {
    var el   = document.getElementById('view-reset');
    var icon = document.getElementById('cmd-reset');
    if (el.style.display === 'none') {
        el.style.display = 'block';
        icon.classList.replace('fa-caret-right', 'fa-caret-down');
    } else {
        el.style.display = 'none';
        icon.classList.replace('fa-caret-down', 'fa-caret-right');
    }
}

// ── Collapsible sections ──────────────────────────────────────────────────────
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

// ── Event switcher ────────────────────────────────────────────────────────────
function showEvent(code) {
    document.querySelectorAll('.ev-block').forEach(function (d) { d.style.display = 'none'; });
    if (code) {
        document.getElementById('ev-' + code).style.display = 'block';
        renderSnake(code);
    }
}
if (validateEvents.length > 0) showEvent(validateEvents[0]);

// ── Tableau snake ─────────────────────────────────────────────────────────────
function renderSnake(evCode) {
    var teams = teamsData[evCode] || [];
    var P     = poolCounts[evCode] || Math.ceil(teams.length / 4);
    var N     = teams.length;
    var el    = document.getElementById('snake-' + evCode);
    if (!el || N === 0 || P === 0) return;

    var html = '<table class="tnm-table" style="table-layout:fixed" data-ev="' + escHtml(evCode) + '">';
    html += '<thead><tr>';
    html += '<th style="width:52px;text-align:center">Poule</th>';
    html += '<th>Place 1</th><th>Place 2</th><th>Place 3</th><th>Place 4</th>';
    html += '</tr></thead><tbody>';

    for (var i = 1; i <= P; i++) {
        var ranks = [i, 2*P+1-i, 2*P+i, 4*P+1-i];
        var bg    = (i % 2 === 0) ? ' style="background:#fafafa"' : '';
        html += '<tr' + bg + '>';
        html += '<td class="snake-pool-num">' + i + '</td>';
        for (var c = 0; c < 4; c++) {
            var r = ranks[c];
            if (r >= 1 && r <= N) {
                var t = teams[r - 1];
                html += '<td class="snake-cell draggable-cell" draggable="true" data-rank="' + r + '">';
                html += '<div class="snake-cell-content">';
                html += '<span class="drag-grip">⠿</span>';
                html += '<div class="snake-cell-info">';
                html += '<span class="snake-cell-rank">#' + r + '</span> ';
                html += '<span class="snake-cell-code">' + escHtml(t.code) + '</span> ';
                html += '<span class="snake-cell-name">' + escHtml(t.name) + '</span>';
                html += '</div>';
                html += '<span class="snake-cell-score">' + t.score + '</span>';
                html += '</div>';
                html += '</td>';
            } else {
                html += '<td class="snake-cell snake-cell-bye">—</td>';
            }
        }
        html += '</tr>';
    }

    html += '</tbody></table>';
    el.innerHTML = html;
}

// ── Drag & drop snake (déplacement individuel d'équipe) ──────────────────────
var cellDragSrc = null;

document.addEventListener('dragstart', function (e) {
    var td = e.target.closest('td.draggable-cell');
    if (!td) return;
    cellDragSrc = td;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', '');
    setTimeout(function () { td.style.opacity = '0.4'; }, 0);
});

document.addEventListener('dragend', function () {
    document.querySelectorAll('td.draggable-cell.drag-over').forEach(function (c) { c.classList.remove('drag-over'); });
    if (cellDragSrc) { cellDragSrc.style.opacity = ''; }
    cellDragSrc = null;
});

document.addEventListener('dragover', function (e) {
    var td = e.target.closest('td.draggable-cell');
    if (!td || !cellDragSrc || td === cellDragSrc) return;
    e.preventDefault();
    document.querySelectorAll('td.draggable-cell.drag-over').forEach(function (c) { c.classList.remove('drag-over'); });
    td.classList.add('drag-over');
});

document.addEventListener('drop', function (e) {
    var td = e.target.closest('td.draggable-cell');
    if (!td || !cellDragSrc || td === cellDragSrc) return;
    e.preventDefault();
    e.stopPropagation();
    td.classList.remove('drag-over');

    var rSrc   = parseInt(cellDragSrc.dataset.rank);
    var rDst   = parseInt(td.dataset.rank);
    var evCode = td.closest('table').dataset.ev;
    var teams  = teamsData[evCode];
    if (!teams || isNaN(rSrc) || isNaN(rDst) || rSrc === rDst) return;

    // MOVE : l'équipe à la position rSrc prend la position rDst,
    // les équipes entre les deux se décalent d'une place.
    var item = teams.splice(rSrc - 1, 1)[0];
    teams.splice(rDst - 1, 0, item);

    teamsData[evCode] = teams;
    renderSnake(evCode);
});

// ── Validation ────────────────────────────────────────────────────────────────
function validateCurrent(evCode, btn) {
    btn.disabled = true;
    var box = document.getElementById('val-progress');
    box.innerHTML = '';

    function step(msg, ok) {
        var color = ok === undefined ? '#333' : (ok ? 'green' : '#c00');
        box.innerHTML += '<p style="color:' + color + '">' + msg + '</p>';
    }

    var ranks = (teamsData[evCode] || []).map(function (t, idx) {
        return { coId: String(t.coId), subTeam: String(t.subTeam), rank: idx + 1 };
    });

    step('Validation ' + evCode + '…');
    $.ajax({
        url: validateUrl,
        type: 'POST',
        data: { event: evCode, ranks: JSON.stringify(ranks) },
        dataType: 'json'
    }).done(function (d) {
        step(evCode + ' : ' + d.msg, d.error === 0);
        if (d.error === 0) {
            var badge = document.querySelector('#ev-' + evCode + ' .badge-pending');
            if (badge) { badge.className = 'badge-valid'; badge.textContent = '✓ Classement validé'; }
            var sel = document.getElementById('ev-select');
            for (var k = 0; k < sel.options.length; k++) {
                if (sel.options[k].value === evCode) {
                    sel.options[k].text = sel.options[k].text.replace(/^●/, '✓');
                    break;
                }
            }
        }
        btn.disabled = false;
    }).fail(function () {
        step(evCode + ' : erreur réseau.', false);
        btn.disabled = false;
    });
}
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
