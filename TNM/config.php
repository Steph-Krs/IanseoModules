<?php
// =============================================================================
// config.php — Configuration TNM par épreuve (stockage DB : TNM_BsoConfig)
// =============================================================================
define('HTDOCS', dirname(dirname(dirname(__DIR__))));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

// ── Schéma DB ─────────────────────────────────────────────────────────────────
// CREATE TABLE IF NOT EXISTS est idempotent — safe à chaque chargement de config.php.
// Pour les futures modifications de colonnes, vérifier via information_schema.COLUMNS
// avant l'ALTER TABLE (pattern en commentaire en fin de section).
// Version installée lue depuis version.json local — mis à jour par le mécanisme de MàJ.
$_lvJson = @json_decode(@file_get_contents(__DIR__ . '/version.json'), true);
define('TNM_VERSION', $_lvJson['version'] ?? '0.0.0');
unset($_lvJson);

// $GLOBALS['_tnm_tables_ok'] est posé par menu.php en tout début de requête.
// S'il vaut false ici, les tables n'existent pas encore → fraîche installation.
$tnmFreshInstall = !($GLOBALS['_tnm_tables_ok'] ?? false);

safe_r_sql("CREATE TABLE IF NOT EXISTS TNM_BsoConfig (
    BcTournament  SMALLINT    NOT NULL,
    BcEvent       VARCHAR(10) NOT NULL,
    BcBsoCount    SMALLINT    NOT NULL DEFAULT 10,
    BcStartTarget SMALLINT    NOT NULL DEFAULT 1,
    BcSchedule    TEXT,
    BcUpdated     DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    BcSkipCheck   TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (BcTournament, BcEvent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

safe_r_sql("CREATE TABLE IF NOT EXISTS TNM_BsoVolee (
    BvTournament  SMALLINT    NOT NULL,
    BvEvent       VARCHAR(10) NOT NULL,
    BvRound       TINYINT     NOT NULL,
    BvTeam        INT         NOT NULL,
    BvTarget      SMALLINT,
    BvScore       SMALLINT,
    BvStatus      TINYINT,
    BvManual      TINYINT(1)  NOT NULL DEFAULT 0,
    BvUpdated     DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    BvRank        TINYINT     NULL,
    PRIMARY KEY (BvTournament, BvEvent, BvRound, BvTeam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($tnmFreshInstall) $GLOBALS['_tnm_tables_ok'] = true;

// ── Template migration future ─────────────────────────────────────────────────
// $rs = safe_r_sql("SELECT 1 FROM information_schema.COLUMNS
//     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='TNM_BsoConfig' AND COLUMN_NAME='BcNewCol'");
// if (!safe_fetch($rs))
//     safe_r_sql("ALTER TABLE TNM_BsoConfig ADD COLUMN BcNewCol TINYINT NOT NULL DEFAULT 0");

// ── Vérification mise à jour GitHub ──────────────────────────────────────────
// Cache session 1h. ?refresh_ver=1 force un nouveau contrôle immédiat.
define('TNM_GITHUB_JSON', 'https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/TNM/version.json');
define('TNM_GITHUB_RAW',  'https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/TNM/');

if (!empty($_GET['refresh_ver'])) unset($_SESSION['_tnm_ver']);

$tnmRemoteVer = null;
if (empty($_SESSION['_tnm_ver']) || (time() - ($_SESSION['_tnm_ver']['ts'] ?? 0)) > 3600) {
    $_ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $_SESSION['_tnm_ver'] = ['ts' => time(), 'raw' => @file_get_contents(TNM_GITHUB_JSON, false, $_ctx) ?: null];
    unset($_ctx);
}
if (!empty($_SESSION['_tnm_ver']['raw'])) {
    $_rem = json_decode($_SESSION['_tnm_ver']['raw'], true);
    if (is_array($_rem) && isset($_rem['version']) && version_compare($_rem['version'], TNM_VERSION, '>'))
        $tnmRemoteVer = $_rem;
    unset($_rem);
}

// ── Mise à jour en 1 clic ─────────────────────────────────────────────────────
$tnmUpdateResults = null;
$tnmUpdateError   = null;
if (($_POST['act'] ?? '') === 'tnm_update') {
    if (!hasFullACL(AclRobin, '', AclReadWrite)) {
        $tnmUpdateError = 'Droits insuffisants (ReadWrite requis).';
    } elseif (!$tnmRemoteVer || empty($tnmRemoteVer['files'])) {
        $tnmUpdateError = 'Aucune mise à jour disponible ou clé "files" manquante dans version.json.';
    } else {
        $_ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $tnmUpdateResults = [];
        foreach ($tnmRemoteVer['files'] as $_f) {
            // Protection traversée de chemin : uniquement noms simples
            if (!preg_match('/^[\w.\-]+$/', $_f) || str_contains($_f, '..')) {
                $tnmUpdateResults[$_f] = '⚠ Nom invalide — ignoré';
                continue;
            }
            $_content = @file_get_contents(TNM_GITHUB_RAW . $_f, false, $_ctx);
            if ($_content === false || $_content === '') {
                $tnmUpdateResults[$_f] = '✗ Téléchargement échoué';
            } elseif (file_put_contents(__DIR__ . '/' . $_f, $_content) === false) {
                $tnmUpdateResults[$_f] = '✗ Écriture impossible (permissions ?)';
            } else {
                $tnmUpdateResults[$_f] = '✓';
            }
        }
        unset($_SESSION['_tnm_ver'], $_ctx, $_f, $_content); // Forcer re-contrôle au prochain chargement
    }
}

// ── Helpers DB (même signature que l'ancienne version JSON) ───────────────────
// PdfPools.php et PdfRanking.php peuvent remplacer leur copie locale par ceci.
function getTNMEventValue($tourId, $evCode, $key, $default = null) {
    $map = ['bso_count' => 'BcBsoCount', 'start_target' => 'BcStartTarget'];
    $col = $map[$key] ?? null;
    if (!$col) return $default;
    $rs = safe_r_sql("SELECT $col FROM TNM_BsoConfig
                      WHERE BcTournament=$tourId AND BcEvent=".StrSafe_DB($evCode));
    $r = safe_fetch($rs);
    return ($r && $r->$col !== null) ? $r->$col : $default;
}

// ── Épreuves Round Robin de la compétition ────────────────────────────────────
$rsEv = safe_r_sql(
    "SELECT EvCode, EvEventName FROM Events
     WHERE EvElimType=5 AND EvTeamEvent='1'
     AND EvTournament=$tourId AND EvCodeParent='' ORDER BY EvProgr"
);
$evList = [];
while ($r = safe_fetch($rsEv))
    $evList[] = ['code' => $r->EvCode, 'name' => get_text($r->EvEventName,'','',true)];

// ── Équipes BSO déjà initialisées (round 1) ───────────────────────────────────
$teamsCount = [];
$rsTC = safe_r_sql("SELECT BvEvent, COUNT(*) as cnt FROM TNM_BsoVolee
                    WHERE BvTournament=$tourId AND BvRound=1 GROUP BY BvEvent");
while ($r = safe_fetch($rsTC)) $teamsCount[$r->BvEvent] = intval($r->cnt);

// ── Traitement POST ───────────────────────────────────────────────────────────
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bso_count'])) {
    foreach ($_POST['bso_count'] as $evCode => $val) {
        $evCode   = preg_replace('/[^A-Za-z0-9]/', '', $evCode);
        $bso      = max(4, min(20, intval($val)));
        $tgt      = max(1, intval($_POST['start_target'][$evCode] ?? 1));
        $time     = trim($_POST['start_time'][$evCode] ?? '');
        $schedule = json_encode(['1' => $time], JSON_UNESCAPED_UNICODE);
        $skip = !empty($_POST['skip_check'][$evCode]) ? 1 : 0;

        safe_r_sql("INSERT INTO TNM_BsoConfig
            (BcTournament, BcEvent, BcBsoCount, BcStartTarget, BcSchedule, BcSkipCheck)
            VALUES ($tourId, ".StrSafe_DB($evCode).", $bso, $tgt, ".StrSafe_DB($schedule).", $skip)
            ON DUPLICATE KEY UPDATE
                BcBsoCount=$bso, BcStartTarget=$tgt, BcSchedule=".StrSafe_DB($schedule).", BcSkipCheck=$skip");
    }
    $saved = true;
}

// ── Lecture configs actuelles (fallback JSON si DB vide) ──────────────────────
$configs = [];
$rsC = safe_r_sql("SELECT * FROM TNM_BsoConfig WHERE BcTournament=$tourId");
while ($r = safe_fetch($rsC)) $configs[$r->BcEvent] = $r;

$jsonFallback = [];
$jf = __DIR__ . '/tnm_config.json';
if (file_exists($jf)) {
    $jd = json_decode(file_get_contents($jf), true) ?? [];
    $jsonFallback = $jd[$tourId]['events'] ?? [];
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Configuration TNM';
$IncludeJquery = true;
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.tnm td,.tnm th{padding:6px 10px}
.tnm-note{color:#666;font-size:.85em}
.btn-bso{font-size:.8em;padding:4px 8px;border-radius:4px;border:none;cursor:pointer;margin:2px 0;display:block;width:100%}
.btn-confirm{background:#002B92;color:#fff}
.btn-reset{background:#f0a500;color:#fff}
.btn-recalc{background:#36d93e;color:#fff}
.btn-delete{background:#d9363e;color:#fff}
.btn-bso:disabled{opacity:.5;cursor:default}
</style>
<?php

echo '<table class="Tabella">';
echo '<tr><th class="Title" colspan="2">Configuration – Trophée National des Mixtes</th></tr>';
if ($saved)
    echo '<tr><td colspan="2" class="Center" style="color:green;font-weight:bold;padding:8px">✓ Sauvegardé</td></tr>';
if ($tnmFreshInstall)
    echo '<tr><td colspan="2" class="Center" style="color:#1a7a3a;font-weight:bold;padding:8px">✓ Module TNM installé avec succès</td></tr>';

// ── Résultats de mise à jour ───────────────────────────────────────────────────
if ($tnmUpdateError)
    echo '<tr><td colspan="2" style="color:#c00;padding:6px 14px">✗ ' . htmlspecialchars($tnmUpdateError) . '</td></tr>';
if ($tnmUpdateResults !== null) {
    $ok  = count(array_filter($tnmUpdateResults, fn($v) => $v === '✓'));
    $err = count($tnmUpdateResults) - $ok;
    echo '<tr><td colspan="2" style="background:#f0fff4;padding:8px 14px;border-top:2px solid #2a7">';
    echo '<strong style="color:#1a7a3a">Mise à jour effectuée : ' . $ok . ' fichier(s) ✓'
       . ($err ? ', <span style="color:#c00">' . $err . ' erreur(s)</span>' : '') . '</strong>';
    echo ' &mdash; <em>Rechargez la page pour appliquer la nouvelle version.</em><br><small style="color:#555">';
    foreach ($tnmUpdateResults as $_f => $_r) echo htmlspecialchars($_f) . ' : ' . $_r . ' &nbsp;';
    echo '</small></td></tr>';
    unset($ok, $err, $_f, $_r);
}

echo '<tr><td class="Right">Compétition :</td><td><strong>'.htmlspecialchars($_SESSION['TourNameSafe'] ?? '').' (ID : '.$tourId.')</strong></td></tr>';

// ── Ligne version + contrôle de mise à jour ───────────────────────────────────
echo '<tr><td class="Right" style="color:#999;font-size:.8em">Version module :</td><td style="font-size:.8em">';
echo 'v' . TNM_VERSION . ' &nbsp;';
if ($tnmRemoteVer) {
    echo '<span style="color:#c07000;font-weight:bold">⚠ v' . htmlspecialchars($tnmRemoteVer['version']) . ' disponible</span>';
    if (!empty($tnmRemoteVer['notes']))
        echo ' <span style="color:#888">— ' . htmlspecialchars($tnmRemoteVer['notes']) . '</span>';
    echo ' &nbsp;';
    echo '<form method="POST" style="display:inline">'
       . '<input type="hidden" name="act" value="tnm_update">'
       . '<button type="submit" style="background:#002B92;color:#fff;border:none;border-radius:3px;padding:2px 12px;cursor:pointer;font-size:.85em">⬇ Mettre à jour</button>'
       . '</form>';
} else {
    echo '<span style="color:#2a7">✓ à jour</span>';
}
echo ' &nbsp;<a href="?refresh_ver=1" style="color:#aaa;font-size:.85em;text-decoration:none" title="Forcer la vérification GitHub">↺ Vérifier</a>';
echo '</td></tr>';
echo '</table><br>';

if (empty($evList)) {
    echo '<p class="Center" style="color:#c00">Aucune épreuve Round Robin trouvée.</p>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); exit;
}

echo '<form method="POST">';
echo '<table class="Tabella tnm" style="width:100%">';

// ── En-tête ───────────────────────────────────────────────────────────────────
echo '<tr><th class="SubTitle" colspan="8">Big Shoot Off — Paramètres par épreuve</th></tr>';
echo '<tr style="background:#eef">';
foreach (['Code','Épreuve','BSO qualifiés','Cible départ','Heure début','Impact','Actions BSO','Vérif. nb qualifiés'] as $h)
    echo '<th class="SubTitle">'.$h.'</th>';
echo '</tr>';

// ── Une ligne par épreuve ─────────────────────────────────────────────────────
foreach ($evList as $ev) {
    $c  = $ev['code'];
    $cs = htmlspecialchars($c);
    $hasTeams = ($teamsCount[$c] ?? 0) > 0;
    $skip = isset($configs[$c]) ? intval($configs[$c]->BcSkipCheck) : 0;

    if (isset($configs[$c])) {
        $bso  = intval($configs[$c]->BcBsoCount);
        $tgt  = intval($configs[$c]->BcStartTarget);
        $sch  = json_decode($configs[$c]->BcSchedule ?? '{}', true) ?? [];
        $time = $sch['1'] ?? '';
    } else {
        $bso  = intval($jsonFallback[$c]['bso_count'] ?? 10);
        $tgt  = 1; $time = '';
    }

    echo '<tr>';
    echo '<td class="Center"><strong>'.$cs.'</strong></td>';
    echo '<td>'.htmlspecialchars($ev['name']).'</td>';
    echo '<td class="Center"><input type="number" name="bso_count['.$cs.']" value="'.$bso.'"
          min="4" max="20" style="width:55px;text-align:center"
          oninput="upd(this)" data-ev="'.$cs.'" data-f="bso"></td>';
    echo '<td class="Center"><input type="number" name="start_target['.$cs.']" value="'.$tgt.'"
          min="1" max="200" style="width:55px;text-align:center"
          oninput="upd(this)" data-ev="'.$cs.'" data-f="tgt"></td>';
    echo '<td class="Center"><input type="time" name="start_time['.$cs.']"
          value="'.htmlspecialchars($time).'"
          style="width:90px"></td>';
    echo '<td><span id="i_'.$cs.'" class="tnm-note">'.($bso*2).' éq. PP · cibles '.$tgt.'–'.($tgt+$bso-1).'</span></td>';

    // ── Actions BSO ───────────────────────────────────────────────────────────
    echo '<td style="min-width:130px">';
    $cnt = $teamsCount[$c] ?? 0;
    echo "<button type='button' class='btn-bso btn-confirm' id='btn-confirm-".$cs."'
          onclick='confirmTeams(".json_encode($c).", this)'>".
         ($cnt > 0 ? '✓ '.$cnt.' équipes' : 'Confirmer équipes').'</button>';
    if ($hasTeams) {
        echo "<button type='button' class='btn-bso btn-reset' id='btn-reset-".$cs."'
              onclick='resetScores(".json_encode($c).", this)'>Reset scores</button>";
        echo "<button type='button' class='btn-bso btn-recalc' id='btn-recalc-".$cs."'
              onclick='recalcAll(".json_encode($c).", this)'>Recalculer scores</button>";
        echo "<button type='button' class='btn-bso btn-delete' id='btn-delete-".$cs."'
              onclick='deleteTeams(".json_encode($c).", this)'>Supprimer équipes</button>";
    } else {
        echo '<span id="extra-'.$cs.'"></span>';
    }
    echo '</td>';
    echo '<td class="Center"><label><input type="checkbox" name="skip_check['.$cs.']"'
   . ($skip ? ' checked' : '') . '> Ignorer</label></td>';
    echo '</tr>';
}

echo '<tr><td colspan="6" class="Center" style="padding:14px">';
echo '<div class="Button" onclick="this.closest(\'form\').submit()">Enregistrer toutes les épreuves</div>';
echo '</td>';
echo '<td colspan="2" class="Center" style="padding:14px">';
echo '<label><input type="checkbox" id="ScheduleAccColors" checked>&nbsp;Couleurs AccColors</label><br><br>';
echo '<div class="Button" onclick="printBSO()" style="margin-bottom:6px">Planning BSO</div>';
echo '<div class="Button" onclick="printScheduleTNM()" style="margin-bottom:6px">Programme de compétition</div>';
echo '<div class="Button" onclick="printFopTNM()">Plan de cible</div>';
echo '</td></tr>';
echo '<tr><th colspan="8" class="Button" onclick="window.location.href=\''.$CFG->ROOT_DIR.'Modules/Custom/TNM/PoolRankingEdit.php\'">Édition manuelle classement poule (DNS/DNF)</th></tr>';
echo '</table></form>';


$bsoActionUrl = $CFG->ROOT_DIR . 'Modules/Custom/TNM/bso-action.php';
?>
<div id="cfg-msg" style="text-align:center;padding:8px;min-height:24px;font-size:.9em"></div>
<script>
var BSO_URL = '<?= $bsoActionUrl ?>';

function showMsg(msg, ok) {
    var el = document.getElementById('cfg-msg');
    el.textContent = msg;
    el.style.color = ok ? 'green' : '#c00';
    setTimeout(function(){ el.textContent=''; }, 4000);
}

function upd(el) {
    var ev = el.dataset.ev;
    var bso = parseInt(document.querySelector('[data-ev="'+ev+'"][data-f="bso"]').value)||0;
    var tgt = parseInt(document.querySelector('[data-ev="'+ev+'"][data-f="tgt"]').value)||1;
    var s = document.getElementById('i_'+ev);
    if (s) s.textContent = (bso*2)+' éq. PP · cibles '+tgt+'–'+(tgt+bso-1);
}

function confirmTeams(ev, btn) {
    console.log('Confirm teams for', ev);
    btn.disabled = true; btn.textContent = '...';
    $.getJSON(BSO_URL, {act:'initVolee', event:ev, round:1}, function(data) {
        if (!data.error) {
            console.log('Teams initialized:', data.teams);
            var n = data.teams ? data.teams.length : '?';
            btn.textContent = '✓ '+n+' équipes';
            // Afficher les boutons reset/supprimer si absents
            var extra = document.getElementById('extra-'+ev);
            if (extra) {
                extra.innerHTML =
                    '<button type="button" class="btn-bso btn-reset" onclick="resetScores('+JSON.stringify(ev)+', this)">Reset scores</button>' +
                    '<button type="button" class="btn-bso btn-recalc" onclick="recalcAll('+JSON.stringify(ev)+', this)">Recalculer scores</button>' +
                    '<button type="button" class="btn-bso btn-delete" onclick="deleteTeams('+JSON.stringify(ev)+', this)">Supprimer équipes</button>';
            }
            showMsg('✓ '+n+' équipes initialisées pour '+ev, true);
        } else {
            btn.disabled = false; btn.textContent = 'Confirmer équipes';
            showMsg('Erreur : '+(data.msg||'inconnue'), false);
        }
    }).fail(function(){ btn.disabled=false; btn.textContent='Confirmer équipes'; showMsg('Erreur réseau', false); });
}

function recalcAll(ev, btn) {
    if (!confirm('Recalculer tous les scores BSO de '+ev+' ?')) return;
    btn.disabled = true;
    $.getJSON(BSO_URL, {act:'recalcAll', event:ev}, function(data) {
        btn.disabled = false;
        showMsg(data.error ? ('Erreur : '+(data.msg||'')) : '✓ Scores recalculés ('+ev+')', !data.error);
    });
}

function resetScores(ev, btn) {
    if (!confirm('Remettre à zéro tous les scores BSO de '+ev+' ?')) return;
    btn.disabled = true;
    $.getJSON(BSO_URL, {act:'resetScores', event:ev}, function(data) {
        btn.disabled = false;
        showMsg(data.error ? ('Erreur : '+(data.msg||'')) : '✓ Scores remis à zéro ('+ev+')', !data.error);
    });
}

function deleteTeams(ev, btn) {
    if (!confirm('Supprimer toutes les équipes BSO de '+ev+' ?\nLes scores saisis seront perdus.')) return;
    btn.disabled = true;
    $.getJSON(BSO_URL, {act:'deleteTeams', event:ev}, function(data) {
        btn.disabled = false;
        if (!data.error) {
            var confirmBtn = document.getElementById('btn-confirm-'+ev);
            if (confirmBtn) { confirmBtn.disabled=false; confirmBtn.textContent='Confirmer équipes'; }
            btn.parentNode.querySelector('.btn-reset') && btn.parentNode.querySelector('.btn-reset').remove();
            btn.parentNode.querySelector('.btn-recalc') && btn.parentNode.querySelector('.btn-recalc').remove();
            btn.remove();
            showMsg('✓ Équipes supprimées ('+ev+')', true);
        } else {
            showMsg('Erreur : '+(data.msg||''), false);
        }
    });
}

function printScheduleTNM() {
    window.open('<?= $CFG->ROOT_DIR ?>Modules/Custom/TNM/PdfSchedule.php', '_blank');
}

function printFopTNM() {
    window.open('<?= $CFG->ROOT_DIR ?>Scheduler/index.php?fop=1', '_blank');
}

function printBSO() {
    var params = [];

    /*function addSelected(selId, name) {
        var vals = Array.from(document.getElementById(selId).selectedOptions).map(o => o.value);
        if (vals.length === 0) vals = ['.'];
        vals.forEach(v => params.push(encodeURIComponent(name+'[]') + '=' + encodeURIComponent(v)));
    }*/
    
    if (document.getElementById('ScheduleAccColors').checked) params.push('useAccColors=1');
    window.open('PdfBsoPlanning.php?' + params.join('&'), '_blank');
}
</script>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');