<?php
/**
 * lib/transfert.php — emporter une sélection d'un serveur à l'autre.
 *
 * POURQUOI CE FICHIER EXISTE
 * ianseo sait déjà exporter une compétition entière (`Tournament/TournamentExport.php`,
 * fichier `.ianseo`) et la réimporter ailleurs : scores, épreuves, cibles,
 * horaires, tout y est. Mais sa liste de tables est **codée en dur** dans
 * `Common/Lib/Fun_Export.php` et ne contient aucune table de module. Un aller-
 * retour entre serveurs perdrait donc, en silence :
 *   - le règlement figé de la compétition et ses rattachements ;
 *   - les classements calculés ;
 *   - **les archives des étapes verrouillées** — scores et chaînes de flèches
 *     gelés, avec lesquels se réimpriment les feuilles de marque ;
 *   - les tirs de barrage saisis et le journal.
 *
 * Ce fichier produit le complément : un `.selec` à côté du `.ianseo`.
 *
 * CLÉ DE RAPPROCHEMENT : jamais `EnId`. L'identifiant d'inscription est un
 * auto-increment local, rien ne garantit qu'il survive à un import sur un autre
 * serveur. Chaque archer est donc désigné par **licence + division + classe**,
 * et le rapprochement se refait à l'arrivée. Une référence qui ne retombe pas
 * sur exactement un archer est signalée, jamais devinée.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/donnees.php';

if (!defined('SELEC_TRANSFERT_FORMAT')) define('SELEC_TRANSFERT_FORMAT', 1);

/** Référence stable d'un archer, indépendante des identifiants locaux. */
function selec_tr_ref($licence, $division, $classe)
{
    return strtoupper(trim((string) $licence)) . '|' . strtoupper(trim((string) $division))
         . '|' . strtoupper(trim((string) $classe));
}

/** Table EnId → référence, pour tous les archers d'une compétition. */
function selec_tr_refs($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT EnId, EnCode, EnDivision, EnClass, EnName, EnFirstName
        FROM Entries WHERE EnTournament=" . intval($tourId));
    while ($rs && ($r = safe_fetch($rs))) {
        $out[intval($r->EnId)] = array(
            'ref'     => selec_tr_ref($r->EnCode, $r->EnDivision, $r->EnClass),
            'licence' => $r->EnCode,
            'nom'     => trim($r->EnName . ' ' . $r->EnFirstName),
        );
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Export
// ─────────────────────────────────────────────────────────────────────────────

/** Construit le contenu du fichier de transfert. */
function selec_transfert_export($tourId)
{
    $tourId = intval($tourId);
    $refs = selec_tr_refs($tourId);
    $tour = selec_tournoi($tourId);

    $out = array(
        'format'  => SELEC_TRANSFERT_FORMAT,
        'module'  => function_exists('selec_sh_version') ? selec_sh_version() : '',
        'date'    => date('Y-m-d H:i:s'),
        'tournoi' => array('code' => $tour ? $tour['code'] : '', 'nom' => $tour ? $tour['nom'] : ''),
        'archers' => array(),
        'config'  => null,
        'binds'   => array(),
        'results' => array(),
        'archive' => array(),
        'shootoff' => array(),
        'log'     => array(),
    );

    // Les archers cités, avec de quoi les reconnaître à l'arrivée.
    foreach ($refs as $id => $a) {
        $out['archers'][$a['ref']] = array('licence' => $a['licence'], 'nom' => $a['nom']);
    }

    $rs = safe_r_sql("SELECT * FROM SELEC_Config WHERE ScTournament=$tourId");
    if ($rs && ($r = safe_fetch($rs))) {
        $out['config'] = array(
            'mode' => $r->ScMode, 'version' => $r->ScModeVer,
            'snapshot' => $r->ScSnapshot, 'options' => $r->ScOptions,
            'date' => $r->ScSnapDate,
        );
    }

    $rs = safe_r_sql("SELECT SbCategory, SbStep, SbSlot, SbEvent, SbOrder
        FROM SELEC_Bind WHERE SbTournament=$tourId");
    while ($rs && ($r = safe_fetch($rs))) {
        $out['binds'][] = array($r->SbCategory, $r->SbStep, $r->SbSlot, $r->SbEvent, intval($r->SbOrder));
    }

    $rs = safe_r_sql("SELECT * FROM SELEC_Results WHERE SrTournament=$tourId");
    while ($rs && ($r = safe_fetch($rs))) {
        $id = intval($r->SrEntry);
        if (!isset($refs[$id])) continue;
        $out['results'][] = array(
            'ref' => $refs[$id]['ref'], 'cat' => $r->SrCategory, 'step' => $r->SrStep,
            'rang' => intval($r->SrRank), 'points' => intval($r->SrPointsC),
            'num' => intval($r->SrValueNum), 'den' => intval($r->SrValueDen),
            'tie' => $r->SrTie, 'ex' => intval($r->SrExAequo), 'ret' => intval($r->SrRetenu),
            'detail' => $r->SrDetail, 'maj' => $r->SrUpdated,
        );
    }

    $rs = safe_r_sql("SELECT * FROM SELEC_Archive WHERE SaTournament=$tourId");
    while ($rs && ($r = safe_fetch($rs))) {
        $id = intval($r->SaEntry);
        if (!isset($refs[$id])) continue;
        $out['archive'][] = array(
            'ref' => $refs[$id]['ref'], 'step' => $r->SaStep,
            'session' => intval($r->SaSession), 'cible' => intval($r->SaTarget), 'lettre' => $r->SaLetter,
            'score' => intval($r->SaScore), 'gold' => intval($r->SaGold), 'x' => intval($r->SaX),
            'fleches' => intval($r->SaArrows), 'data' => $r->SaData,
            'date' => $r->SaDate, 'user' => $r->SaUser,
        );
    }

    $rs = safe_r_sql("SELECT * FROM SELEC_Shootoff WHERE SoTournament=$tourId");
    while ($rs && ($r = safe_fetch($rs))) {
        $id = intval($r->SoEntry);
        if (!isset($refs[$id])) continue;
        $out['shootoff'][] = array(
            'ref' => $refs[$id]['ref'], 'cat' => $r->SoCategory, 'step' => $r->SoStep,
            'ordre' => intval($r->SoOrder), 'note' => $r->SoNote, 'date' => $r->SoDate,
        );
    }

    $rs = safe_r_sql("SELECT SlCategory, SlDate, SlUser, SlAction, SlDetail
        FROM SELEC_Log WHERE SlTournament=$tourId ORDER BY SlId");
    while ($rs && ($r = safe_fetch($rs))) {
        $out['log'][] = array($r->SlCategory, $r->SlDate, $r->SlUser, $r->SlAction, $r->SlDetail);
    }

    return $out;
}

/** Sérialise pour le téléchargement (JSON compressé, lisible après décompression). */
function selec_transfert_fichier($contenu)
{
    return gzencode(json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 9);
}

/** Relit un fichier de transfert. Retourne null si illisible. */
function selec_transfert_lire($brut)
{
    $txt = @gzdecode($brut);
    if ($txt === false) $txt = $brut;          // fichier déjà décompressé
    $j = json_decode((string) $txt, true);
    return is_array($j) && isset($j['format']) ? $j : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Import
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ce que donnerait l'import, sans rien écrire : archers retrouvés, introuvables,
 * ambigus, et volumes concernés. On regarde avant d'écraser.
 */
function selec_transfert_analyse($tourId, $data)
{
    $tourId = intval($tourId);
    $out = array('ok' => false, 'erreurs' => array(), 'alertes' => array(),
        'trouves' => 0, 'manquants' => array(), 'ambigus' => array(),
        'compte' => array('results' => 0, 'archive' => 0, 'shootoff' => 0, 'binds' => 0, 'log' => 0),
        'config' => null);

    if (!$data || intval($data['format'] ?? 0) !== SELEC_TRANSFERT_FORMAT) {
        $out['erreurs'][] = 'Fichier illisible ou d\'un format inconnu.';
        return $out;
    }

    // Rapprochement par licence + division + classe. Une référence qui tombe sur
    // plusieurs inscriptions (un archer inscrit deux fois) est signalée : on ne
    // choisit pas à la place de l'opérateur.
    $locales = array();
    foreach (selec_tr_refs($tourId) as $id => $a) $locales[$a['ref']][] = $id;

    foreach ((array) ($data['archers'] ?? array()) as $ref => $a) {
        if (!isset($locales[$ref])) {
            $out['manquants'][] = $a['nom'] . ' (' . $a['licence'] . ')';
        } elseif (count($locales[$ref]) > 1) {
            $out['ambigus'][] = $a['nom'] . ' (' . $a['licence'] . ') — '
                . count($locales[$ref]) . ' inscriptions identiques';
        } else {
            $out['trouves']++;
        }
    }

    $out['compte']['results']  = count((array) ($data['results'] ?? array()));
    $out['compte']['archive']  = count((array) ($data['archive'] ?? array()));
    $out['compte']['shootoff'] = count((array) ($data['shootoff'] ?? array()));
    $out['compte']['binds']    = count((array) ($data['binds'] ?? array()));
    $out['compte']['log']      = count((array) ($data['log'] ?? array()));
    $out['config'] = $data['config'] ?? null;

    if ($out['manquants']) {
        $out['alertes'][] = count($out['manquants']) . ' archer(s) du fichier n\'existent pas dans '
            . 'cette compétition : leurs classements et leurs archives ne seront pas repris.';
    }
    if ($out['ambigus']) {
        $out['alertes'][] = count($out['ambigus']) . ' référence(s) désignent plusieurs '
            . 'inscriptions : elles seront ignorées, à reprendre à la main.';
    }
    if (!$out['trouves']) {
        $out['erreurs'][] = 'Aucun archer du fichier ne correspond à cette compétition — '
            . 'êtes-vous sur la bonne ?';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}

/**
 * Écrit le contenu du fichier dans cette compétition.
 * Remplace intégralement les données SELEC de la compétition visée.
 */
function selec_transfert_importer($tourId, $data)
{
    $tourId = intval($tourId);
    $res = array('ok' => false, 'faits' => array(), 'erreurs' => array());

    $an = selec_transfert_analyse($tourId, $data);
    if (!$an['ok']) { $res['erreurs'] = $an['erreurs']; return $res; }

    $locales = array();
    foreach (selec_tr_refs($tourId) as $id => $a) $locales[$a['ref']][] = $id;
    $vers = function ($ref) use ($locales) {
        return (isset($locales[$ref]) && count($locales[$ref]) === 1) ? $locales[$ref][0] : 0;
    };

    foreach (array('SELEC_Results' => 'SrTournament', 'SELEC_Archive' => 'SaTournament',
                   'SELEC_Shootoff' => 'SoTournament', 'SELEC_Bind' => 'SbTournament',
                   'SELEC_Config' => 'ScTournament') as $t => $col) {
        safe_w_sql("DELETE FROM `$t` WHERE `$col`=$tourId");
    }

    if (!empty($data['config'])) {
        $c = $data['config'];
        safe_w_sql("INSERT INTO SELEC_Config SET ScTournament=$tourId,
            ScMode=" . StrSafe_DB($c['mode']) . ",
            ScModeVer=" . StrSafe_DB($c['version']) . ",
            ScSnapshot=" . StrSafe_DB($c['snapshot']) . ",
            ScOptions=" . StrSafe_DB($c['options']) . ",
            ScSnapDate=" . StrSafe_DB($c['date']) . ",
            ScUpdated=" . StrSafe_DB(date('Y-m-d H:i:s')));
        $res['faits'][] = 'Règlement figé repris : ' . htmlspecialchars($c['mode'])
            . ' (version ' . htmlspecialchars($c['version']) . ').';
    }

    $n = 0;
    foreach ((array) ($data['binds'] ?? array()) as $b) {
        safe_w_sql("INSERT INTO SELEC_Bind SET SbTournament=$tourId,
            SbCategory=" . StrSafe_DB($b[0]) . ", SbStep=" . StrSafe_DB($b[1]) . ",
            SbSlot=" . StrSafe_DB($b[2]) . ", SbEvent=" . StrSafe_DB($b[3]) . ",
            SbOrder=" . intval($b[4]) . "
            ON DUPLICATE KEY UPDATE SbEvent=VALUES(SbEvent)");
        $n++;
    }
    $res['faits'][] = "$n rattachement(s) d'épreuve.";

    $n = 0; $perdus = 0;
    foreach ((array) ($data['results'] ?? array()) as $r) {
        $id = $vers($r['ref']);
        if (!$id) { $perdus++; continue; }
        $val = $r['den'] ? $r['num'] / $r['den'] : 0;
        safe_w_sql("INSERT INTO SELEC_Results SET SrTournament=$tourId,
            SrCategory=" . StrSafe_DB($r['cat']) . ", SrStep=" . StrSafe_DB($r['step']) . ",
            SrEntry=$id, SrRank=" . intval($r['rang']) . ", SrPointsC=" . intval($r['points']) . ",
            SrValue=" . StrSafe_DB(number_format($val, 6, '.', '')) . ",
            SrValueNum=" . intval($r['num']) . ", SrValueDen=" . max(1, intval($r['den'])) . ",
            SrTie=" . StrSafe_DB($r['tie']) . ", SrExAequo=" . intval($r['ex']) . ",
            SrRetenu=" . intval($r['ret']) . ", SrDetail=" . StrSafe_DB($r['detail']) . ",
            SrUpdated=" . StrSafe_DB($r['maj']));
        $n++;
    }
    $res['faits'][] = "$n ligne(s) de classement" . ($perdus ? " ($perdus non rapprochées)" : '') . '.';

    $n = 0; $perdus = 0;
    foreach ((array) ($data['archive'] ?? array()) as $a) {
        $id = $vers($a['ref']);
        if (!$id) { $perdus++; continue; }
        safe_w_sql("INSERT INTO SELEC_Archive SET SaTournament=$tourId,
            SaStep=" . StrSafe_DB($a['step']) . ", SaEntry=$id,
            SaSession=" . intval($a['session']) . ", SaTarget=" . intval($a['cible']) . ",
            SaLetter=" . StrSafe_DB($a['lettre']) . ", SaScore=" . intval($a['score']) . ",
            SaGold=" . intval($a['gold']) . ", SaX=" . intval($a['x']) . ",
            SaArrows=" . intval($a['fleches']) . ", SaData=" . StrSafe_DB($a['data']) . ",
            SaDate=" . StrSafe_DB($a['date']) . ", SaUser=" . StrSafe_DB($a['user']));
        $n++;
    }
    $res['faits'][] = "$n archive(s) d'étape verrouillée" . ($perdus ? " ($perdus non rapprochées)" : '')
        . ' — scores et flèches figés, feuilles de marque réimprimables.';

    $n = 0;
    foreach ((array) ($data['shootoff'] ?? array()) as $s) {
        $id = $vers($s['ref']);
        if (!$id) continue;
        safe_w_sql("INSERT INTO SELEC_Shootoff SET SoTournament=$tourId,
            SoCategory=" . StrSafe_DB($s['cat']) . ", SoStep=" . StrSafe_DB($s['step']) . ",
            SoEntry=$id, SoOrder=" . intval($s['ordre']) . ",
            SoNote=" . StrSafe_DB($s['note']) . ", SoDate=" . StrSafe_DB($s['date']));
        $n++;
    }
    if ($n) $res['faits'][] = "$n tir(s) de barrage.";

    // Le journal est repris tel quel : c'est l'historique des décisions.
    $n = 0;
    foreach ((array) ($data['log'] ?? array()) as $l) {
        safe_w_sql("INSERT INTO SELEC_Log SET SlTournament=$tourId,
            SlCategory=" . StrSafe_DB($l[0]) . ", SlDate=" . StrSafe_DB($l[1]) . ",
            SlUser=" . StrSafe_DB($l[2]) . ", SlAction=" . StrSafe_DB($l[3]) . ",
            SlDetail=" . StrSafe_DB($l[4]));
        $n++;
    }
    if ($n) $res['faits'][] = "$n ligne(s) de journal reprises.";

    selec_log($tourId, 'import', array('archers' => $an['trouves'],
        'manquants' => count($an['manquants']), 'ambigus' => count($an['ambigus'])));
    $res['ok'] = true;
    return $res;
}
