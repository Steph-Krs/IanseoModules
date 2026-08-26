<?php
/**
 * lib/sessions.php — verrouillage des sessions ISK-NG, étape par étape.
 *
 * ianseo verrouille la saisie tablette session par session, depuis
 * `Api/ISK-NG/Sessions.php` : une case par couple (session, distance) pour les
 * qualifications, par couple (phase, épreuve) pour les duels. Le verrou vit dans
 * le paramètre de module `ISK-NG / LockedSessions`, une simple liste de clés.
 *
 * Ce fichier ne réinvente NI le format de clé NI le stockage : il appelle
 * `GetLockableSessions()` de ianseo pour obtenir les clés réellement existantes,
 * puis les filtre par étape de sélection. Deux raisons de ne pas fabriquer les
 * clés nous-mêmes : elles changeraient sous nos pieds à la première mise à jour
 * de ianseo, et une clé inventée qui ne correspond à aucune session verrouille
 * dans le vide sans que rien ne le signale.
 *
 * L'écriture se fait en UN SEUL setModuleParameter par bascule. Enchaîner N
 * appels à `Sessions-Toggle.php` (un par distance) ferait relire et réécrire la
 * liste entière N fois : deux clics rapprochés se marcheraient dessus et un
 * verrou serait perdu.
 */

if (!defined('SELEC_LOCK_MODULE')) define('SELEC_LOCK_MODULE', 'ISK-NG');
if (!defined('SELEC_LOCK_PARAM'))  define('SELEC_LOCK_PARAM', 'LockedSessions');

/**
 * Toutes les sessions verrouillables de la compétition, telles que ianseo les
 * voit : [LockKey => ['type'=>'Q|E|I|T|R…', 'distance'=>n, 'libelle'=>…]].
 */
function selec_lock_sessions($tourId)
{
    global $CFG;
    $tourId = intval($tourId);
    if (!$tourId) return array();

    // Fichier de fonctions pures (aucun effet de bord au chargement), mais qui
    // n'est pas dans le chemin d'inclusion du module : on ne le charge que s'il
    // manque, comme pour les autres briques du cœur.
    if (!function_exists('GetLockableSessions')) {
        $f = $CFG->DOCUMENT_PATH . 'Api/ISK-NG/Lib.php';
        if (!is_file($f)) return array();
        require_once($f);
    }
    // GetLockableSessions() lit $_SESSION['TourId'] : sur cette page c'est déjà
    // la bonne compétition, mais on ne parie pas là-dessus. Le `finally` garantit
    // que la session retrouve sa compétition quoi qu'il arrive — la laisser
    // pointer ailleurs ferait écrire la suite de la requête dans la mauvaise.
    $memo = isset($_SESSION['TourId']) ? $_SESSION['TourId'] : null;
    try {
        $_SESSION['TourId'] = $tourId;
        $sql = GetLockableSessions();
    } finally {
        if ($memo === null) unset($_SESSION['TourId']); else $_SESSION['TourId'] = $memo;
    }

    $out = array();
    $rs = safe_r_sql($sql);
    while ($rs && ($r = safe_fetch($rs))) {
        $out[$r->LockKey] = array(
            'type'     => (string) $r->SesType,
            'distance' => intval($r->Distance),
            'libelle'  => (string) $r->Description,
        );
    }
    return $out;
}

/**
 * Page ianseo de vérification des feuilles de marque au code-barres, pour une
 * étape donnée. Chemin relatif à ROOT_DIR, ou '' si l'étape n'est pas tirée
 * (journée, coupure, classement final : rien à vérifier, ce sont des calculs).
 *
 * Le choix ne se fait pas sur le type d'étape mais sur la façon dont elle est
 * TIRÉE : des duels simulés tirés comme un round de qualification se vérifient
 * avec la page des qualifications, pas avec celle des duels.
 */
function selec_verif_page($st)
{
    $base = 'Modules/Barcodes/';
    if (selec_arch_distances($st)) return $base . 'GetScoreBarCode.php';

    switch ((string) ($st['type'] ?? '')) {
        case 'qualification':   return $base . 'GetScoreBarCode.php';
        case 'poule':           return $base . 'GetRobinScoreBarCode.php';
        case 'tournoi':
        case 'duels_simules':   return $base . 'GetFinScoreBarCode.php';
    }
    return '';
}

/** Liste des clés actuellement verrouillées. */
function selec_lock_verrous($tourId)
{
    $v = getModuleParameter(SELEC_LOCK_MODULE, SELEC_LOCK_PARAM, array(), intval($tourId));
    return is_array($v) ? array_values(array_unique(array_map('strval', $v))) : array();
}

/**
 * Clés de verrou qui appartiennent à une étape de sélection.
 *
 * Deux familles seulement, parce que le règlement n'en connaît pas d'autres :
 *  - une étape tirée sur des DISTANCES (qualification, duels simulés tirés comme
 *    un round) → clés « Q|session|distance », filtrées sur la distance, qui est
 *    propre à l'étape dans tous les modes livrés ;
 *  - une étape tirée en DUELS → clés « I|phase|épreuve » et « R|niveau|groupe|
 *    tour|épreuve », filtrées sur le code d'épreuve, toujours en dernier segment.
 *
 * @param array $events codes d'épreuve liés à l'étape, toutes catégories confondues
 */
function selec_lock_cles($st, $events, $sessions)
{
    $cles = array();
    $dists = selec_arch_distances($st);

    if ($dists) {
        foreach ($sessions as $k => $s) {
            if ($s['type'] === 'Q' && in_array($s['distance'], $dists, true)) $cles[] = $k;
        }
        return $cles;
    }

    $ev = array();
    foreach ((array) $events as $e) if ($e !== '' && $e !== null) $ev[(string) $e] = true;
    if (!$ev) return array();

    foreach ($sessions as $k => $s) {
        // Une clé de qualification finit par un numéro de distance, jamais par un
        // code d'épreuve : l'exclure évite qu'un code d'épreuve numérique ne
        // vienne, un jour, ramasser une session de qualification par accident.
        if ($s['type'] === 'Q') continue;
        $seg = explode('|', $k);
        $dernier = end($seg);
        if (isset($ev[$dernier])) $cles[] = $k;
    }
    return $cles;
}

/** Épreuves liées à une étape, toutes catégories confondues. */
function selec_lock_events($tourId, $stepId)
{
    $out = array();
    foreach (selec_binds_tous($tourId) as $cat => $etapes) {
        if (empty($etapes[$stepId])) continue;
        foreach ($etapes[$stepId] as $slot => $ev) if ($ev !== '') $out[(string) $ev] = true;
    }
    return array_keys($out);
}

/**
 * État de verrouillage de chaque étape du mode.
 *
 * @return array [stepId => ['total'=>n, 'verrouillees'=>n, 'etat'=>'aucun|partiel|tout']]
 */
function selec_lock_etat($tourId, $mode)
{
    $sessions = selec_lock_sessions($tourId);
    $verrous  = array_flip(selec_lock_verrous($tourId));
    $out = array();

    foreach ((array) ($mode['etapes'] ?? array()) as $st) {
        $cles = selec_lock_cles($st, selec_lock_events($tourId, $st['id']), $sessions);
        if (!$cles) continue;
        $n = 0;
        foreach ($cles as $k) if (isset($verrous[$k])) $n++;
        $out[$st['id']] = array(
            'total'        => count($cles),
            'verrouillees' => $n,
            'etat'         => $n === 0 ? 'aucun' : ($n === count($cles) ? 'tout' : 'partiel'),
        );
    }
    return $out;
}

/**
 * Verrouille ou déverrouille d'un coup toutes les sessions d'une étape.
 *
 * @param string $sens 'lock', 'unlock', ou '' pour basculer (tout verrouiller
 *                     tant qu'il reste une session ouverte, tout ouvrir sinon)
 * @return array ['ok'=>bool, 'etat'=>…, 'total'=>n, 'verrouillees'=>n, 'err'=>…]
 */
function selec_lock_basculer($tourId, $mode, $stepId, $sens = '')
{
    $tourId = intval($tourId);
    $st = selec_prepa_etape($mode, $stepId);
    if (!$st) return array('ok' => false, 'err' => 'Étape inconnue.');

    $sessions = selec_lock_sessions($tourId);
    $cles = selec_lock_cles($st, selec_lock_events($tourId, $stepId), $sessions);
    if (!$cles) {
        return array('ok' => false, 'err' => "Aucune session de saisie ne correspond à cette étape. "
            . "Les sessions n'existent qu'une fois les scores ouverts à la saisie tablette.");
    }

    $verrous = selec_lock_verrous($tourId);
    $index   = array_flip($verrous);
    $dejaTout = true;
    foreach ($cles as $k) if (!isset($index[$k])) { $dejaTout = false; break; }

    if ($sens !== 'lock' && $sens !== 'unlock') $sens = $dejaTout ? 'unlock' : 'lock';

    if ($sens === 'lock') {
        foreach ($cles as $k) $index[$k] = true;
    } else {
        foreach ($cles as $k) unset($index[$k]);
    }

    // Un seul écrit : la liste complète, verrous des autres étapes compris.
    setModuleParameter(SELEC_LOCK_MODULE, SELEC_LOCK_PARAM, array_keys($index), $tourId);

    $n = ($sens === 'lock') ? count($cles) : 0;
    return array('ok' => true, 'sens' => $sens, 'total' => count($cles), 'verrouillees' => $n,
        'etat' => $n === 0 ? 'aucun' : 'tout');
}
