<?php
/**
 * lib/config.php — catalogue des modes de sélection et rattachement d'une compétition.
 *
 * Un mode vit dans modes/<ID>.json : c'est de la CONFIGURATION, pas du code.
 * Ajouter la sélection Arc à Poulies, Para ou Jeunes revient à écrire un de ces
 * fichiers en réutilisant les briques existantes.
 *
 * Point critique : au rattachement, le JSON est FIGÉ dans SELEC_Config.ScSnapshot.
 * Une mise à jour du module qui corrigerait un barème ne doit jamais changer
 * rétroactivement le classement d'une compétition déjà tirée. Le ré-ancrage est
 * une action explicite, journalisée.
 */

require_once __DIR__ . '/schema.php';

/** Dossier des modes livrés avec le module. */
function selec_modes_dir()
{
    return __DIR__ . '/../modes';
}

/** Catalogue : [id => ['id','libelle','version','reference','fichier','points_ouverts']]. */
function selec_modes_catalogue()
{
    $out = array();
    foreach ((array) glob(selec_modes_dir() . '/*.json') as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (!is_array($j) || empty($j['id'])) continue;
        $out[$j['id']] = array(
            'id'      => $j['id'],
            'libelle' => isset($j['libelle']) ? $j['libelle'] : $j['id'],
            'version' => isset($j['version']) ? $j['version'] : '0',
            'reference' => isset($j['reference']) ? $j['reference'] : '',
            'fichier' => $f,
            'points_ouverts' => isset($j['points_ouverts']) ? $j['points_ouverts'] : array(),
            'etapes'  => isset($j['etapes']) ? count($j['etapes']) : 0,
        );
    }
    ksort($out);
    return $out;
}

/** Charge un mode depuis le catalogue (fichier vivant, pas le snapshot). */
function selec_mode_charger($id)
{
    $f = selec_modes_dir() . '/' . basename((string) $id) . '.json';
    if (!is_file($f)) return null;
    $j = json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/**
 * Vérifie la cohérence interne d'un mode avant de l'ancrer : références
 * d'étapes, barèmes nommés, types de briques. Retourne la liste des problèmes.
 * Une configuration incohérente doit être refusée à la porte, pas produire un
 * classement faux trois jours plus tard.
 */
function selec_mode_valider($mode)
{
    $err = array();
    if (!is_array($mode) || empty($mode['etapes'])) return array('Mode vide ou illisible.');

    $types = array('qualification', 'duels_simules', 'tournoi', 'poule', 'journee', 'coupure', 'final');
    $ids = array();
    foreach ($mode['etapes'] as $st) {
        if (empty($st['id']))   { $err[] = 'Une étape n\'a pas d\'identifiant.'; continue; }
        if (isset($ids[$st['id']])) $err[] = "Identifiant d'étape en double : {$st['id']}.";
        $ids[$st['id']] = true;
        if (empty($st['type']) || !in_array($st['type'], $types, true)) {
            $err[] = "Étape {$st['id']} : type inconnu « " . (isset($st['type']) ? $st['type'] : '') . " ».";
        }
    }

    $baremes = isset($mode['baremes']) ? $mode['baremes'] : array();
    $vus = array();
    foreach ($mode['etapes'] as $st) {
        if (empty($st['id'])) continue;
        $sid = $st['id'];

        foreach (array('bareme', 'bareme_classement', 'bareme_performance') as $b) {
            if (!empty($st[$b]) && !isset($baremes[$st[$b]])) {
                $err[] = "Étape $sid : barème « {$st[$b]} » non défini.";
            }
        }
        foreach ((array) (isset($st['sources']) ? $st['sources'] : array()) as $s) {
            if (!isset($vus[$s])) $err[] = "Étape $sid : source « $s » inconnue ou postérieure.";
        }
        foreach ((array) (isset($st['departage']) ? $st['departage'] : array()) as $d) {
            foreach (array('quals', 'etapes') as $k) {
                foreach ((array) (isset($d[$k]) ? $d[$k] : array()) as $s) {
                    if ($s !== $sid && !isset($vus[$s]) && !isset($ids[$s])) {
                        $err[] = "Étape $sid : départage référence l'étape inconnue « $s ».";
                    }
                }
            }
        }
        $vus[$sid] = true;
    }

    if (!empty($mode['familles'])) {
        foreach ($mode['etapes'] as $st) {
            if (!empty($st['famille']) && !isset($mode['familles'][$st['famille']])) {
                $err[] = "Étape {$st['id']} : famille de points « {$st['famille']} » non déclarée.";
            }
        }
    }
    return $err;
}

/** Configuration d'une compétition : mode ancré + snapshot + options. */
function selec_config_lire($tourId)
{
    $rs = safe_r_sql("SELECT * FROM SELEC_Config WHERE ScTournament=" . intval($tourId));
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r) return null;
    $snap = json_decode((string) $r->ScSnapshot, true);
    return array(
        'tour'     => intval($r->ScTournament),
        'mode'     => $r->ScMode,
        'version'  => $r->ScModeVer,
        'snapshot' => is_array($snap) ? $snap : null,
        'options'  => (array) json_decode((string) $r->ScOptions, true),
        'date'     => $r->ScSnapDate,
        'maj'      => $r->ScUpdated,
    );
}

/**
 * Rattache une compétition à un mode et fige son JSON.
 * Retourne ['ok'=>bool,'erreurs'=>[]].
 */
function selec_config_ancrer($tourId, $modeId, $options = array())
{
    $tourId = intval($tourId);
    $mode = selec_mode_charger($modeId);
    if (!$mode) return array('ok' => false, 'erreurs' => array("Mode « $modeId » introuvable."));

    $err = selec_mode_valider($mode);
    if ($err) return array('ok' => false, 'erreurs' => $err);

    $now = date('Y-m-d H:i:s');
    safe_w_sql("INSERT INTO SELEC_Config SET
        ScTournament=$tourId,
        ScMode=" . StrSafe_DB($mode['id']) . ",
        ScModeVer=" . StrSafe_DB(isset($mode['version']) ? $mode['version'] : '') . ",
        ScSnapshot=" . StrSafe_DB(json_encode($mode, JSON_UNESCAPED_UNICODE)) . ",
        ScOptions=" . StrSafe_DB(json_encode((array) $options, JSON_UNESCAPED_UNICODE)) . ",
        ScSnapDate=" . StrSafe_DB($now) . ",
        ScUpdated=" . StrSafe_DB($now) . "
        ON DUPLICATE KEY UPDATE
            ScMode=VALUES(ScMode), ScModeVer=VALUES(ScModeVer),
            ScSnapshot=VALUES(ScSnapshot), ScOptions=VALUES(ScOptions),
            ScSnapDate=VALUES(ScSnapDate), ScUpdated=VALUES(ScUpdated)");

    selec_log($tourId, 'ancrage', array('mode' => $mode['id'], 'version' => $mode['version'] ?? ''));
    return array('ok' => true, 'erreurs' => array());
}

/** Met à jour les seules options (sans toucher au snapshot du mode). */
function selec_options_ecrire($tourId, $options)
{
    safe_w_sql("UPDATE SELEC_Config SET
        ScOptions=" . StrSafe_DB(json_encode((array) $options, JSON_UNESCAPED_UNICODE)) . ",
        ScUpdated=" . StrSafe_DB(date('Y-m-d H:i:s')) . "
        WHERE ScTournament=" . intval($tourId));
    selec_log($tourId, 'options', $options);
}

// ─────────────────────────────────────────────────────────────────────────────
// Rattachement étape/rôle → épreuve ianseo
// ─────────────────────────────────────────────────────────────────────────────

/** Toutes les liaisons d'une compétition : [catégorie][étape][rôle] => EvCode. */
function selec_binds_tous($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT SbCategory, SbStep, SbSlot, SbEvent FROM SELEC_Bind
        WHERE SbTournament=" . intval($tourId) . " ORDER BY SbCategory, SbOrder, SbStep");
    while ($rs && ($r = safe_fetch($rs))) {
        $out[$r->SbCategory][$r->SbStep][$r->SbSlot] = $r->SbEvent;
    }
    return $out;
}

/** Liaisons d'une seule catégorie : [étape][rôle] => EvCode. */
function selec_binds_lire($tourId, $cat)
{
    $tous = selec_binds_tous($tourId);
    return isset($tous[$cat]) ? $tous[$cat] : array();
}

/** Écrit (ou efface, si $event vide) une liaison. */
function selec_bind_ecrire($tourId, $cat, $step, $slot, $event, $ordre = 0)
{
    $tourId = intval($tourId);
    if ($event === '' || $event === null) {
        safe_w_sql("DELETE FROM SELEC_Bind WHERE SbTournament=$tourId
            AND SbCategory=" . StrSafe_DB($cat) . "
            AND SbStep=" . StrSafe_DB($step) . "
            AND SbSlot=" . StrSafe_DB($slot));
        return;
    }
    safe_w_sql("INSERT INTO SELEC_Bind SET
        SbTournament=$tourId,
        SbCategory=" . StrSafe_DB($cat) . ",
        SbStep=" . StrSafe_DB($step) . ",
        SbSlot=" . StrSafe_DB($slot) . ",
        SbEvent=" . StrSafe_DB($event) . ",
        SbOrder=" . intval($ordre) . "
        ON DUPLICATE KEY UPDATE SbEvent=VALUES(SbEvent), SbOrder=VALUES(SbOrder)");
}

/**
 * Épreuves ianseo qui portent un tournoi, une consolante ou une poule.
 *
 * Elles ont la MÊME portée (division/classe) que l'épreuve de qualification dont
 * elles sont issues : ianseo y range donc les mêmes archers, et elles
 * ressemblent à s'y méprendre à des catégories. Ce sont des supports de duels,
 * jamais des catégories de sélection — les confondre reviendrait à traiter
 * chaque archer autant de fois qu'il a de tournois.
 */
function selec_evenements_lies($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT DISTINCT SbEvent FROM SELEC_Bind
        WHERE SbTournament=" . intval($tourId) . " AND SbEvent<>''");
    while ($rs && ($r = safe_fetch($rs))) $out[$r->SbEvent] = true;
    return $out;
}

/**
 * Codes des catégories réellement traitées.
 *
 * Aucune sélection explicite = toutes les épreuves individuelles avec archers :
 * un module qui ne calcule rien tant qu'on n'a pas coché de case donnerait
 * l'impression de ne pas fonctionner. Les épreuves de duels créées par le
 * module en sont toujours retirées, y compris si une ancienne configuration les
 * avait enregistrées comme catégories.
 */
function selec_categories_actives($tourId, $cfg = null)
{
    if ($cfg === null) $cfg = selec_config_lire($tourId);
    $lies = selec_evenements_lies($tourId);
    $connues = array();
    foreach (array_keys(selec_categories($tourId)) as $c) {
        if (!isset($lies[$c])) $connues[] = $c;
    }
    $choix = ($cfg && !empty($cfg['options']['categories'])) ? (array) $cfg['options']['categories'] : array();
    if (!$choix) return $connues;
    return array_values(array_intersect($choix, $connues));
}

/** Étapes du mode qui ont besoin d'une épreuve ianseo, avec leurs rôles. */
function selec_etapes_a_lier($mode)
{
    $out = array();
    foreach ((array) (isset($mode['etapes']) ? $mode['etapes'] : array()) as $st) {
        if (empty($st['id'])) continue;
        $slots = array();
        if ($st['type'] === 'tournoi')  $slots = isset($st['slots']) ? (array) $st['slots'] : array('principal', 'consolante');
        elseif ($st['type'] === 'poule') $slots = isset($st['slots']) ? (array) $st['slots'] : array('poule');
        elseif ($st['type'] === 'duels_simules'
            && (isset($st['source']['type']) && $st['source']['type'] === 'evenements')) {
            $slots = isset($st['source']['slots']) ? (array) $st['source']['slots'] : array('principal');
        }
        if ($slots) {
            $out[$st['id']] = array(
                'libelle' => isset($st['libelle']) ? $st['libelle'] : $st['id'],
                'type'    => $st['type'],
                'slots'   => $slots,
            );
        }
    }
    return $out;
}
