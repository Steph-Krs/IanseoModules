<?php
/**
 * EXPORT_LISTE — Export de la liste des participants.
 *
 * Produit un CSV dont les 10 premières colonnes reprennent EXACTEMENT le format
 * attendu par l'import par liste (Partecipants/ListLoad.php), suivies de deux
 * colonnes supplémentaires : N° d'agrément (code club) et nom du club.
 *
 * Colonnes :
 *   1  Numéro de licence      (EnCode)
 *   2  Départ / session       (QuSession)
 *   3  Division / arme        (EnDivision)
 *   4  Classe                 (EnClass)
 *   5  Cible                  (QuTarget + QuLetter)
 *   6  Qualif individuelle    (EnIndClEvent)
 *   7  Qualif par équipe      (EnTeamClEvent)
 *   8  Finale individuelle    (EnIndFEvent)
 *   9  Finale par équipe      (EnTeamFEvent)
 *   10 Double mixte           (EnTeamMixEvent)
 *   11 N° d'agrément          (CoCode)   ← ajout
 *   12 Nom du club            (CoName)   ← ajout
 *
 * Module lecture seule : aucune écriture, aucune table custom.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

$tourId = intval($_SESSION['TourId']);

// ── Génération du CSV (mode téléchargement) ──────────────────────────────────
if (isset($_REQUEST['download'])) {
    $sep       = ';';                              // ianseo réimporte le ";" (converti en tab)
    $withHeader = !empty($_REQUEST['header']);
    $withBom    = !empty($_REQUEST['bom']);
    $session    = (isset($_REQUEST['session']) && $_REQUEST['session'] !== '')
                ? intval($_REQUEST['session']) : null;

    $sql = "SELECT
                TRIM(EnCode)            AS Licence,
                IFNULL(QuSession, '')   AS Depart,
                TRIM(EnDivision)        AS Division,
                TRIM(EnClass)           AS Classe,
                IF(QuTarget > 0, CONCAT(QuTarget, QuLetter), '') AS Cible,
                EnIndClEvent            AS QualifInd,
                EnTeamClEvent           AS QualifEqu,
                EnIndFEvent             AS FinaleInd,
                EnTeamFEvent            AS FinaleEqu,
                EnTeamMixEvent          AS DoubleMixte,
                IFNULL(CoCode, '')      AS Agrement,
                IFNULL(CoName, '')      AS Club
            FROM Entries e
            LEFT JOIN Qualifications q ON e.EnId = q.QuId
            LEFT JOIN Countries c ON e.EnCountry = c.CoId AND e.EnTournament = c.CoTournament
            WHERE e.EnTournament = " . StrSafe_DB($tourId);
    if ($session !== null) {
        $sql .= " AND QuSession = " . StrSafe_DB($session);
    }
    $sql .= " ORDER BY Agrement, EnDivision, EnClass, EnName, EnFirstName";

    $rs = safe_r_sql($sql);

    $fname = 'participants_' . $tourId . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store');

    if ($withBom) echo "\xEF\xBB\xBF";           // aide Excel à lire les accents (voir note UI)

    // Retire tout séparateur / saut de ligne qui casserait le découpage en colonnes.
    $clean = function ($v) use ($sep) {
        return str_replace([$sep, "\t", "\r", "\n"], ' ', (string)$v);
    };

    if ($withHeader) {
        echo implode($sep, [
            'Licence', 'Depart', 'Division', 'Classe', 'Cible',
            'QualifInd', 'QualifEquipe', 'FinaleInd', 'FinaleEquipe', 'DoubleMixte',
            'NoAgrement', 'Club',
        ]) . "\r\n";
    }

    while ($r = safe_fetch($rs)) {
        echo implode($sep, [
            $clean($r->Licence),
            $clean($r->Depart),
            $clean($r->Division),
            $clean($r->Classe),
            $clean($r->Cible),
            $clean($r->QualifInd),
            $clean($r->QualifEqu),
            $clean($r->FinaleInd),
            $clean($r->FinaleEqu),
            $clean($r->DoubleMixte),
            $clean($r->Agrement),
            $clean($r->Club),
        ]) . "\r\n";
    }
    exit;
}

// ── Page (formulaire) ────────────────────────────────────────────────────────
// Nombre de participants + sessions disponibles pour le sélecteur.
$rs = safe_r_sql("SELECT COUNT(*) AS n FROM Entries WHERE EnTournament = " . StrSafe_DB($tourId));
$total = ($r = safe_fetch($rs)) ? intval($r->n) : 0;

$sessions = [];
$rs = safe_r_sql("SELECT DISTINCT QuSession
    FROM Entries e JOIN Qualifications q ON e.EnId = q.QuId
    WHERE e.EnTournament = " . StrSafe_DB($tourId) . " AND QuSession > 0
    ORDER BY QuSession");
while ($r = safe_fetch($rs)) $sessions[] = intval($r->QuSession);

$self = $CFG->ROOT_DIR . 'Modules/Custom/EXPORT_LISTE/index.php';

$PAGE_TITLE = 'Export de la liste des participants';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
/* Charte FFTA (ffta.fr) — voir CHARTE_GRAPHIQUE.md à la racine du projet */
#expl { --bleu:#0254a8; --bleu-fonce:#01367c; --bleu-clair:#f0f4ff; --gris:#4c4e50;
        --bord:#d2d4d6; --fond:#f7f7f7; max-width:760px; }
#expl .card { border:1px solid var(--bord); border-radius:6px; background:#fff;
              box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
#expl .card > h3 { margin:0; padding:10px 14px; font-size:14px; color:#fff;
                   background:var(--bleu); border-radius:5px 5px 0 0; }
#expl .card > div { padding:14px; }
#expl .banner { background:var(--bleu-clair); border-left:4px solid var(--bleu); color:var(--gris);
                border-radius:0 6px 6px 0; padding:10px 14px; margin-bottom:16px; font-size:13px; }
#expl .banner b { color:var(--bleu-fonce); }
#expl label.opt { display:flex; align-items:flex-start; gap:8px; margin:10px 0; font-size:13px; color:var(--gris); }
#expl label.opt span small { display:block; color:#7d8183; font-weight:normal; }
#expl label.opt b { color:var(--bleu-fonce); }
#expl select { padding:7px 9px; border:1px solid var(--bord); border-radius:6px; font-size:13px; }
#expl button.primary { background:var(--bleu); border:1px solid var(--bleu); color:#fff; font-weight:600;
                       padding:9px 20px; font-size:14px; border-radius:6px; cursor:pointer; }
#expl button.primary:hover { background:var(--bleu-fonce); }
#expl table.cols { border-collapse:collapse; font-size:12px; margin-top:6px; }
#expl table.cols td { border:1px solid #e2e5ea; padding:3px 8px; }
#expl table.cols td.n { background:var(--fond); text-align:right; color:#7d8183; width:34px; }
#expl table.cols td.add { background:#eafaf0; }
</style>

<div id="expl">

    <div class="banner">
        <b><?= $total ?></b> participant<?= $total > 1 ? 's' : '' ?> dans la compétition ouverte.
        Le fichier reprend les <b>10 colonnes du format d'import par liste</b>, puis ajoute le
        <b>N° d'agrément</b> et le <b>nom du club</b>.
    </div>

    <form class="card" method="get" action="<?= htmlspecialchars($self) ?>">
        <h3>Options d'export</h3>
        <div>
            <label class="opt" style="display:block">
                <b>Départ (session)</b><br>
                <select name="session">
                    <option value="">Tous les départs</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?= $s ?>">Départ <?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="opt">
                <input type="checkbox" name="header" value="1">
                <span><b>Ajouter une ligne d'en-tête</b> (noms des colonnes)
                    <small>À laisser décoché pour un fichier directement réimportable dans ianseo
                    (l'import ne veut pas de ligne d'en-tête).</small></span>
            </label>

            <label class="opt">
                <input type="checkbox" name="bom" value="1">
                <span><b>Compatible Excel</b> (accents corrects au double-clic)
                    <small>Ajoute un marqueur UTF-8 (BOM). Pratique pour consulter dans Excel, mais
                    à laisser décoché si le fichier doit être réimporté dans ianseo.</small></span>
            </label>

            <div style="margin-top:14px">
                <button class="primary" type="submit" name="download" value="1">Télécharger le CSV</button>
            </div>
        </div>
    </form>

    <div class="card">
        <h3>Colonnes du fichier</h3>
        <div>
            <table class="cols">
                <tr><td class="n">1</td><td>Numéro de licence</td></tr>
                <tr><td class="n">2</td><td>Départ (session)</td></tr>
                <tr><td class="n">3</td><td>Division (arme)</td></tr>
                <tr><td class="n">4</td><td>Classe</td></tr>
                <tr><td class="n">5</td><td>Cible</td></tr>
                <tr><td class="n">6</td><td>Qualification individuelle</td></tr>
                <tr><td class="n">7</td><td>Qualification par équipe</td></tr>
                <tr><td class="n">8</td><td>Finale individuelle</td></tr>
                <tr><td class="n">9</td><td>Finale par équipe</td></tr>
                <tr><td class="n">10</td><td>Double mixte</td></tr>
                <tr><td class="n">11</td><td class="add"><b>N° d'agrément</b> (code club)</td></tr>
                <tr><td class="n">12</td><td class="add"><b>Nom du club</b></td></tr>
            </table>
            <p style="font-size:12px;color:#7d8183;margin:12px 0 0">
                Séparateur : point-virgule (<code>;</code>). Pour réimporter dans ianseo, retirez
                les colonnes 11 et 12 (elles ne font pas partie du format d'import) et n'incluez pas
                de ligne d'en-tête.
            </p>
        </div>
    </div>

</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
