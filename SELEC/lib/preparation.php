<?php
/**
 * lib/preparation.php — préparer l'étape suivante depuis le classement de la précédente.
 *
 * Deux gestes, selon ce que l'étape suivante attend :
 *
 *  1. **Un départ de qualification** → replacer les archers sur la session à venir,
 *     dans l'ordre du classement de référence. C'est exactement ce que fait
 *     `Partecipants/TargetFromRank.php`, appliqué automatiquement à toutes les
 *     catégories du départ et avec le bon classement de base.
 *
 *  2. **Un tournoi** → valider les qualifications et générer le tableau. C'est la
 *     séquence de `Final/Individual/AbsIndividual.php` : écrire `Individuals.IndRank`
 *     (l'ordre des têtes de série), recréer la grille, placer les archers en
 *     joignant `IndRank` à `Grids.GrPosition`, marquer l'épreuve validée, puis
 *     propager les byes.
 *
 * Toujours en deux temps : `selec_prepa_plan()` dit ce qui sera fait, l'opérateur
 * regarde, `selec_prepa_appliquer()` le fait. Sur une sélection, un placement de
 * tableau ne se lance pas à l'aveugle.
 *
 * REFUS DE PRINCIPE : jamais de régénération d'un tableau qui porte déjà des
 * scores. Ce serait la seule façon pour ce module de détruire des résultats.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/donnees.php';
require_once __DIR__ . '/moteur.php';

// ─────────────────────────────────────────────────────────────────────────────
// Quelle étape préparer, et sur quel classement
// ─────────────────────────────────────────────────────────────────────────────

/** Une étape a-t-elle quelque chose à préparer sur le terrain ? */
function selec_prepa_preparable($st)
{
    if ($st['type'] === 'qualification') return 'session';
    if ($st['type'] === 'tournoi')       return 'tableau';
    if ($st['type'] === 'poule')         return 'poule';
    // Duels simulés en épreuves : autant de TABLEAUX que de duels, dont seul le
    // premier tour se tire. Même machinerie qu'un tournoi, à ceci près que les
    // appariements tournent d'un duel à l'autre.
    if ($st['type'] === 'duels_simules'
        && (($st['source']['type'] ?? '') === 'evenements')) return 'tableau';
    return '';
}

/** Première étape préparable qui suit $stepId dans le mode. */
function selec_prepa_cible($mode, $stepId)
{
    $vu = false;
    foreach ((array) $mode['etapes'] as $st) {
        if (!$vu) { if ($st['id'] === $stepId) $vu = true; continue; }
        if (selec_prepa_preparable($st)) return $st;
    }
    return null;
}

/**
 * Classement de référence déclaré par le mode pour préparer une étape.
 * Les qualifications le portent dans `prepare.base`, les tournois dans
 * `seeding.source` — deux noms parce que ce sont deux notions différentes :
 * l'ordre des cibles d'un départ, et l'ordre des têtes de série d'un tableau.
 */
function selec_prepa_base_declaree($st)
{
    if (!empty($st['prepare']['base']))  return $st['prepare']['base'];
    if (!empty($st['seeding']['source'])) return $st['seeding']['source'];
    return '';
}

/** Étapes dont le classement peut servir de base (celles qui en produisent un). */
function selec_prepa_bases_possibles($mode, $avant)
{
    $out = array();
    foreach ((array) $mode['etapes'] as $st) {
        if ($st['id'] === $avant) break;
        if (in_array($st['type'], array('qualification', 'journee', 'coupure', 'tournoi',
                                        'poule', 'duels_simules', 'final'), true)) {
            $out[$st['id']] = isset($st['libelle']) ? $st['libelle'] : $st['id'];
        }
    }
    return $out;
}

/** Étape du mode par son identifiant. */
function selec_prepa_etape($mode, $stepId)
{
    foreach ((array) $mode['etapes'] as $st) if ($st['id'] === $stepId) return $st;
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cibles disponibles d'une session
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Emplacements (cible + lettre) d'une session, dans l'ordre de tir.
 * Même définition que createAvailableTargetSQL() de ianseo, recalculée ici en
 * PHP : la version SQL construit une UNION d'autant de lignes qu'il y a de
 * places, ce qui n'a pas d'intérêt quand on a déjà la ligne Session.
 */
function selec_prepa_places($tourId, $session)
{
    $rs = safe_r_sql("SELECT SesFirstTarget, SesTar4Session, SesAth4Target
        FROM Session WHERE SesTournament=" . intval($tourId) . "
          AND SesType='Q' AND SesOrder=" . intval($session));
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r) return array();

    $premiere = max(1, intval($r->SesFirstTarget));
    $nbCibles = intval($r->SesTar4Session);
    $nbLettres = max(1, intval($r->SesAth4Target));
    $out = array();
    for ($t = $premiere; $t < $premiere + $nbCibles; $t++) {
        for ($l = 0; $l < $nbLettres; $l++) {
            $out[] = array('cible' => $t, 'lettre' => chr(65 + $l));
        }
    }
    return $out;
}

/** Étiquette d'une place : numéro de cible + lettre, ex. « 12B ». */
function selec_prepa_place_cle($cible, $lettre) { return intval($cible) . strtoupper((string) $lettre); }

/**
 * Position d'une place dans l'ordre de tir d'un départ, ou -1 si elle n'existe
 * pas. L'ordre est celui du pas de tir : 1A, 1B, 1C, 2A, 2B… — c'est lui qui
 * permet de « continuer sur la cible en cours » quand une catégorie enchaîne
 * la précédente.
 */
function selec_prepa_place_index($places, $etiquette)
{
    $etiquette = strtoupper(trim((string) $etiquette));
    foreach ($places as $i => $p) {
        if (selec_prepa_place_cle($p['cible'], $p['lettre']) === $etiquette) return $i;
    }
    return -1;
}

/** Places déjà occupées dans une session par des archers hors de $exclus. */
function selec_prepa_occupees($tourId, $session, $exclus = array())
{
    $out = array();
    $ex = array_map('intval', array_values($exclus));
    $rs = safe_r_sql("SELECT q.QuId, q.QuTarget, q.QuLetter
        FROM Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId
        WHERE e.EnTournament=" . intval($tourId) . "
          AND q.QuSession=" . intval($session) . " AND q.QuTarget>0"
        . ($ex ? " AND q.QuId NOT IN (" . implode(',', $ex) . ")" : ''));
    while ($rs && ($r = safe_fetch($rs))) {
        $out[intval($r->QuTarget) . $r->QuLetter] = intval($r->QuId);
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Plan
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Décrit la préparation de l'étape qui suit $stepId.
 *
 * @param array $classements [catégorie => contexte de calcul] déjà calculés
 * @return array ['ok','type','cible','cible_lib','base','base_lib','bases',
 *                'lignes','alertes','bloquant']
 */
function selec_prepa_plan($tourId, $mode, $cats, $classements, $stepId, $baseChoisie = '', $plages = array())
{
    $out = array('ok' => false, 'type' => '', 'cible' => '', 'cible_lib' => '',
        'base' => '', 'base_lib' => '', 'bases' => array(), 'lignes' => array(),
        'alertes' => array(), 'bloquant' => '');

    $cible = selec_prepa_cible($mode, $stepId);
    if (!$cible) {
        $out['bloquant'] = 'Aucune étape à préparer après celle-ci — c\'est la dernière du règlement.';
        return $out;
    }
    $out['type']      = selec_prepa_preparable($cible);
    $out['cible']     = $cible['id'];
    $out['cible_lib'] = isset($cible['libelle']) ? $cible['libelle'] : $cible['id'];
    $out['bases']     = selec_prepa_bases_possibles($mode, $cible['id']);

    $base = $baseChoisie !== '' ? $baseChoisie : selec_prepa_base_declaree($cible);
    if ($base === '' || !isset($out['bases'][$base])) {
        // Repli : le dernier classement produit avant l'étape à préparer.
        $cles = array_keys($out['bases']);
        $base = $cles ? end($cles) : '';
    }
    if ($base === '') {
        $out['bloquant'] = 'Aucun classement disponible pour ordonner les archers.';
        return $out;
    }
    $out['base']     = $base;
    $out['base_lib'] = $out['bases'][$base];

    if ($out['type'] === 'poule') {
        $out['bloquant'] = 'Cette étape se joue en « tous contre tous » : l\'épreuve est créée et '
            . 'rattachée, mais les tours se génèrent depuis le menu Round Robin de ianseo. '
            . 'Le module ne les fabrique pas encore.';
        return $out;
    }

    if ($out['type'] === 'session') {
        return selec_prepa_plan_session($tourId, $mode, $cats, $classements, $cible, $base, $out, $plages);
    }
    return selec_prepa_plan_tableau($tourId, $mode, $cats, $classements, $cible, $base, $out);
}

/**
 * Plan d'un départ : quelle catégorie sur quelle plage de cibles, et qui tire où.
 *
 * Le placement n'est PAS deviné : l'opérateur choisit les catégories à replacer
 * et, pour chacune, la plage de places (« de 3B à 9A »). Le module ne fait que
 * proposer un enchaînement par défaut — chaque catégorie démarre à la première
 * place libre après la précédente, ce qui permet de compléter la dernière cible
 * des hommes avec les premières femmes plutôt que de laisser des trous.
 *
 * Invariants tenus : un archer occupe une seule place, une place reçoit un seul
 * archer, et le nombre de places par cible est celui du départ (SesAth4Target).
 *
 * @param array $plages [catégorie => ['actif'=>bool,'de'=>'3B','a'=>'9A']]
 */
function selec_prepa_plan_session($tourId, $mode, $cats, $classements, $cible, $base, $out, $plages = array())
{
    $ses = intval($cible['structure']['session'] ?? 0);
    if (!$ses) {
        $out['bloquant'] = "L'étape « {$out['cible_lib']} » ne déclare pas de départ dans le mode.";
        return $out;
    }
    $out['session'] = $ses;

    $rs = safe_r_sql("SELECT SesName, SesFirstTarget, SesTar4Session, SesAth4Target
        FROM Session WHERE SesTournament=" . intval($tourId) . "
          AND SesType='Q' AND SesOrder=$ses");
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r) {
        $out['bloquant'] = "Le départ n° $ses n'existe pas encore. Générez d'abord la structure "
            . "depuis la page de configuration.";
        return $out;
    }
    $out['session_nom']  = $r->SesName;
    $out['cible_min']    = intval($r->SesFirstTarget);
    $out['cible_max']    = intval($r->SesFirstTarget) + intval($r->SesTar4Session) - 1;
    $out['par_cible']    = max(1, intval($r->SesAth4Target));
    $out['lettres']      = array();
    for ($i = 0; $i < $out['par_cible']; $i++) $out['lettres'][] = chr(65 + $i);

    $places = selec_prepa_places($tourId, $ses);
    if (!$places) {
        $out['bloquant'] = "Le départ n° $ses ne déclare aucune cible (voir la configuration des sessions).";
        return $out;
    }
    $out['nb_places'] = count($places);

    // ── Archers par catégorie, dans l'ordre du classement de référence ───────
    $parCat = array();
    $concernes = array();
    foreach ($cats as $cat) {
        if (empty($classements[$cat])) continue;
        $ctx = $classements[$cat];
        $lignes = isset($ctx['etapes'][$base]['lignes']) ? $ctx['etapes'][$base]['lignes'] : array();
        $liste = array();
        foreach ($ctx['archers'] as $id => $a) {
            $liste[] = array(
                'id'     => $id,
                'cat'    => $cat,
                'nom'    => $a['affiche'],
                'club'   => $a['club'],
                'rang'   => (isset($lignes[$id]['rang']) && $lignes[$id]['rang'] > 0) ? intval($lignes[$id]['rang']) : 99999,
                'classe' => isset($lignes[$id]),
            );
        }
        usort($liste, function ($a, $b) {
            if ($a['rang'] !== $b['rang']) return $a['rang'] <=> $b['rang'];
            return strcmp($a['nom'], $b['nom']);
        });
        $parCat[$cat] = array(
            'code'    => $cat,
            'nom'     => isset($ctx['etapes'][$base]) ? $cat : $cat,
            'archers' => $liste,
            'nb'      => count($liste),
            'classe'  => (bool) $lignes,
        );
        foreach ($liste as $l) $concernes[] = $l['id'];
    }
    if (!$parCat) { $out['bloquant'] = 'Aucune catégorie à replacer.'; return $out; }

    $noms = selec_categories($tourId);
    foreach ($parCat as $cat => &$c) $c['nom'] = isset($noms[$cat]) ? $noms[$cat]['nom'] : $cat;
    unset($c);

    // ── Première saisie : rien n'est encore choisi, on propose l'enchaînement ─
    $premiereFois = empty($plages);
    $occupees = selec_prepa_occupees($tourId, $ses, $concernes);

    if ($premiereFois) {
        $curseur = 0;
        foreach ($parCat as $cat => $c) {
            // On saute les places tenues par des archers hors du plan.
            while ($curseur < count($places)
                && isset($occupees[selec_prepa_place_cle($places[$curseur]['cible'], $places[$curseur]['lettre'])])) {
                $curseur++;
            }
            $debut = $curseur;
            $fin = min(count($places) - 1, $debut + max(0, $c['nb'] - 1));
            $plages[$cat] = array(
                'actif' => $c['nb'] > 0,
                'de' => isset($places[$debut]) ? selec_prepa_place_cle($places[$debut]['cible'], $places[$debut]['lettre']) : '',
                'a'  => isset($places[$fin])   ? selec_prepa_place_cle($places[$fin]['cible'], $places[$fin]['lettre'])   : '',
            );
            $curseur = $fin + 1;
        }
    }

    // ── Attribution place par place ─────────────────────────────────────────
    $prises = array();   // étiquette => catégorie, pour détecter les collisions
    $out['categories'] = array();
    $totalPlaces = 0;

    foreach ($parCat as $cat => $c) {
        $pl = isset($plages[$cat]) ? $plages[$cat] : array();
        $actif = !empty($pl['actif']);
        $de = isset($pl['de']) ? strtoupper(trim($pl['de'])) : '';
        $a  = isset($pl['a'])  ? strtoupper(trim($pl['a']))  : '';

        $bloc = array('code' => $cat, 'nom' => $c['nom'], 'nb' => $c['nb'],
            'actif' => $actif, 'de' => $de, 'a' => $a, 'capacite' => 0, 'lignes' => array());

        if (!$actif) { $out['categories'][] = $bloc; continue; }

        $iDe = selec_prepa_place_index($places, $de);
        $iA  = selec_prepa_place_index($places, $a);
        if ($iDe < 0 || $iA < 0) {
            $out['alertes'][] = $c['nom'] . " : plage « $de → $a » invalide pour ce départ "
                . "(cibles " . $out['cible_min'] . " à " . $out['cible_max']
                . ", lettres " . implode('/', $out['lettres']) . ").";
            $out['categories'][] = $bloc;
            continue;
        }
        if ($iA < $iDe) { list($iDe, $iA) = array($iA, $iDe); }
        $bloc['capacite'] = $iA - $iDe + 1;
        $totalPlaces += $bloc['capacite'];

        if (!$c['classe']) {
            $out['alertes'][] = $c['nom'] . " : le classement « {$out['base_lib']} » est vide, "
                . "les archers seront placés par ordre alphabétique.";
        }
        if ($bloc['capacite'] < $c['nb']) {
            $out['alertes'][] = $c['nom'] . ' : ' . $c['nb'] . ' archers pour ' . $bloc['capacite']
                . ' place(s) — les derniers du classement resteront sans cible.';
        }

        $k = 0;
        foreach ($c['archers'] as $arch) {
            $place = null;
            // On avance jusqu'à une place réellement attribuable.
            while ($iDe + $k <= $iA) {
                $p = $places[$iDe + $k];
                $cle = selec_prepa_place_cle($p['cible'], $p['lettre']);
                $k++;
                if (isset($occupees[$cle])) {
                    $out['alertes'][] = "Place $cle déjà occupée par un archer hors de ce placement "
                        . "— laissée telle quelle.";
                    continue;
                }
                if (isset($prises[$cle])) {
                    $out['alertes'][] = "Place $cle demandée par deux catégories ("
                        . $prises[$cle] . " et " . $c['nom'] . ") — chevauchement de plages à corriger.";
                    continue;
                }
                $prises[$cle] = $c['nom'];
                $place = $p;
                break;
            }
            $arch['cible']  = $place ? $place['cible'] : 0;
            $arch['lettre'] = $place ? $place['lettre'] : '';
            if (!$arch['classe']) {
                $out['alertes'][] = $arch['nom'] . ' (' . $c['nom'] . ") n'apparaît pas dans le "
                    . "classement de référence — placé en fin de catégorie.";
            }
            $bloc['lignes'][] = $arch;
            if ($place) $out['lignes'][] = $arch;
        }
        $out['categories'][] = $bloc;
    }

    if (!$out['lignes']) {
        $out['bloquant'] = 'Aucun archer ne peut être placé : vérifiez les catégories cochées et '
            . 'les plages de cibles.';
        return $out;
    }
    $out['total_places'] = $totalPlaces;
    $out['ok'] = true;
    return $out;
}

/**
 * Appariements du premier tour d'un tableau, LUS dans la grille de ianseo.
 *
 * On ne recopie pas « 1 contre 8, 4 contre 5… » : la table `Grids` est la
 * référence, elle décrit la grille officielle et c'est elle que ianseo utilise
 * ensuite pour propager les vainqueurs. Retourne [[posA, posB], …].
 */
function selec_bracket_paires($phase)
{
    $rs = safe_r_sql("SELECT GrMatchNo, GrPosition FROM Grids
        WHERE GrPhase=" . intval($phase) . " ORDER BY GrMatchNo");
    $pos = array();
    while ($rs && ($r = safe_fetch($rs))) $pos[intval($r->GrMatchNo)] = intval($r->GrPosition);
    $nos = array_keys($pos);
    sort($nos);
    $out = array();
    for ($i = 0; $i + 1 < count($nos); $i += 2) $out[] = array($pos[$nos[$i]], $pos[$nos[$i + 1]]);
    return $out;
}

/**
 * Place les têtes de série dans la grille pour un duel simulé.
 *
 * Un tableau apparie le 1er au dernier ; un duel simulé apparie les voisins de
 * classement. On ne touche pas à la grille de ianseo — c'est elle qui sait
 * propager les vainqueurs — on écrit à la place des POSITIONS décalées : le
 * 2e du classement prend la position 8, celle que la grille oppose à la 1re.
 * C'est ce mensonge de position, et lui seul, qui produit un 1 contre 2.
 *
 * Effet recherché ensuite : les emplacements de match se suivent dans l'ordre
 * des numéros de match, donc les cibles aussi — la tête de série n° s se
 * retrouve sur la s-ième cible du bloc, dans l'ordre du classement.
 *
 * @return array [tête de série => position dans la grille]
 */
function selec_prepa_positions_duel($effectif, $phase)
{
    $bracket = selec_bracket_paires($phase);
    $paires  = selec_paires_classement($effectif);
    $out = array();
    foreach ($bracket as $i => $bp) {
        if (!isset($paires[$i])) break;
        $out[$paires[$i][0]] = $bp[0];
        $out[$paires[$i][1]] = $bp[1];
    }
    return $out;
}

/** Plan d'un tableau : qui est tête de série n° X, et dans quelle épreuve. */
function selec_prepa_plan_tableau($tourId, $mode, $cats, $classements, $cible, $base, $out)
{
    if ($cible['type'] === 'duels_simules') {
        return selec_prepa_plan_duels($tourId, $mode, $cats, $classements, $cible, $base, $out);
    }
    $effectif = intval($cible['structure']['effectif'] ?? 8);
    $out['effectif'] = $effectif;
    $binds = selec_binds_tous_local($tourId);
    $scores = array();

    foreach ($cats as $cat) {
        if (empty($classements[$cat])) continue;
        $ctx = $classements[$cat];
        $principal  = $binds[$cat][$cible['id']]['principal']  ?? '';
        $consolante = $binds[$cat][$cible['id']]['consolante'] ?? '';
        if ($principal === '') {
            $out['alertes'][] = "Catégorie $cat : aucune épreuve rattachée au tableau principal.";
            continue;
        }

        // Un tableau qui porte déjà des scores ne se régénère pas.
        foreach (array($principal, $consolante) as $ev) {
            if ($ev === '') continue;
            $rs = safe_r_sql("SELECT COUNT(*) n FROM Finals
                WHERE FinTournament=" . intval($tourId) . " AND FinEvent=" . StrSafe_DB($ev) . "
                  AND (FinScore>0 OR FinSetScore>0)");
            if ($rs && ($r = safe_fetch($rs)) && intval($r->n) > 0) $scores[] = $ev;
        }

        $lignes = isset($ctx['etapes'][$base]['lignes']) ? $ctx['etapes'][$base]['lignes'] : array();
        if (!$lignes) {
            $out['alertes'][] = "Catégorie $cat : le classement « {$out['base_lib']} » est vide, "
                . "impossible d'établir les têtes de série.";
            continue;
        }

        $liste = array();
        foreach ($lignes as $id => $l) {
            if (isset($l['retenu']) && !$l['retenu']) continue;
            $liste[] = array('id' => $id, 'rang' => intval($l['rang']),
                'exaequo' => !empty($l['exaequo']), 'nom' => selec_nom($ctx, $id));
        }
        usort($liste, function ($a, $b) {
            if ($a['rang'] !== $b['rang']) return $a['rang'] <=> $b['rang'];
            return strcmp($a['nom'], $b['nom']);
        });
        $liste = array_slice($liste, 0, $effectif);

        // Une égalité dans les têtes de série n'est pas anodine : deux archers au
        // même rang produiraient un tableau arbitraire. On le dit.
        foreach ($liste as $l) {
            if ($l['exaequo']) {
                $out['alertes'][] = "Catégorie $cat : " . $l['nom'] . " est ex aequo au rang "
                    . $l['rang'] . " dans le classement de référence — la tête de série sera "
                    . "arbitraire tant que l'égalité n'est pas tranchée.";
            }
        }
        if (count($liste) < $effectif) {
            $out['alertes'][] = "Catégorie $cat : " . count($liste) . " archer(s) pour un tableau de "
                . $effectif . " — les places manquantes seront des byes.";
        }

        foreach ($liste as $i => $l) {
            $out['lignes'][] = array(
                'cat' => $cat, 'id' => $l['id'], 'nom' => $l['nom'],
                'rang' => $l['rang'], 'serie' => $i + 1,
                'event' => $principal, 'consolante' => $consolante,
                'exaequo' => $l['exaequo'],
            );
        }
    }

    if ($scores) {
        $out['bloquant'] = 'Des scores sont déjà saisis dans ' . implode(', ', array_unique($scores))
            . '. Régénérer le tableau les effacerait : le module refuse. Supprimez d\'abord ces '
            . 'scores dans ianseo si le tableau doit vraiment être refait.';
        return $out;
    }
    if (!$out['lignes']) {
        $out['bloquant'] = 'Aucun archer à placer dans les tableaux.';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}

/**
 * Plan des duels simulés : un tableau par duel, appariements en rotation.
 *
 * Les mêmes archers sont placés dans les N épreuves, mais à des positions
 * différentes d'une épreuve à l'autre. Le classement de référence ne sert qu'à
 * fixer les numéros de tête de série ; c'est la rotation qui décide ensuite qui
 * rencontre qui.
 */
function selec_prepa_plan_duels($tourId, $mode, $cats, $classements, $cible, $base, $out)
{
    $effectif = intval($cible['structure']['effectif'] ?? 8);
    $phase    = selec_structure_phase($effectif);
    $slots    = (array) ($cible['source']['slots'] ?? array());
    $out['effectif'] = $effectif;
    $out['duels']    = count($slots);
    $binds  = selec_binds_tous_local($tourId);
    $scores = array();

    foreach ($cats as $cat) {
        if (empty($classements[$cat])) continue;
        $ctx = $classements[$cat];

        $lignes = isset($ctx['etapes'][$base]['lignes']) ? $ctx['etapes'][$base]['lignes'] : array();
        if (!$lignes) {
            $out['alertes'][] = "Catégorie $cat : le classement « {$out['base_lib']} » est vide, "
                . "impossible d'établir les têtes de série.";
            continue;
        }

        $liste = array();
        foreach ($lignes as $id => $l) {
            if (isset($l['retenu']) && !$l['retenu']) continue;
            $liste[] = array('id' => $id, 'rang' => intval($l['rang']),
                'exaequo' => !empty($l['exaequo']), 'nom' => selec_nom($ctx, $id));
        }
        usort($liste, function ($a, $b) {
            if ($a['rang'] !== $b['rang']) return $a['rang'] <=> $b['rang'];
            return strcmp($a['nom'], $b['nom']);
        });
        $liste = array_slice($liste, 0, $effectif);
        if (count($liste) < $effectif) {
            $out['alertes'][] = "Catégorie $cat : " . count($liste) . " archer(s) pour "
                . $effectif . " places — des duels resteront incomplets.";
        }

        foreach ($slots as $k => $slot) {
            $ev = $binds[$cat][$cible['id']][$slot] ?? '';
            if ($ev === '') {
                $out['alertes'][] = "Catégorie $cat : aucune épreuve rattachée au duel « $slot ».";
                continue;
            }
            $rs = safe_r_sql("SELECT COUNT(*) n FROM Finals
                WHERE FinTournament=" . intval($tourId) . " AND FinEvent=" . StrSafe_DB($ev) . "
                  AND (FinScore>0 OR FinSetScore>0)");
            if ($rs && ($r = safe_fetch($rs)) && intval($r->n) > 0) { $scores[] = $ev; continue; }

            // Mêmes positions pour les N duels : les archers gardent leur place
            // et leur cible du premier au dernier.
            $positions = selec_prepa_positions_duel($effectif, $phase);
            foreach ($liste as $i => $l) {
                $serie = $positions[$i + 1] ?? ($i + 1);
                $out['lignes'][] = array(
                    'cat' => $cat, 'id' => $l['id'], 'nom' => $l['nom'],
                    'rang' => $l['rang'], 'serie' => $serie, 'duel' => $k + 1,
                    'event' => $ev, 'consolante' => '',
                    'exaequo' => $l['exaequo'],
                );
            }
        }
    }

    if ($scores) {
        $out['bloquant'] = 'Des scores sont déjà saisis dans ' . implode(', ', array_unique($scores))
            . '. Régénérer ces duels les effacerait : le module refuse. Supprimez d\'abord ces '
            . 'scores dans ianseo si les duels doivent vraiment être refaits.';
        return $out;
    }
    if (!$out['lignes']) {
        $out['bloquant'] = 'Aucun archer à placer dans les duels simulés.';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Application
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Exécute le plan. Retourne ['ok','faits','erreurs'].
 *
 * Avant tout déplacement, l'étape qui vient d'être tirée est GELÉE (et toutes
 * celles à séries qui la précèdent et ne le seraient pas encore) : c'est ce qui
 * rend le passage à l'étape suivante sans risque, puisque les scores figés ne
 * dépendent plus de ce que contiendra `Qualifications` par la suite.
 * Voir lib/archive.php pour la raison de fond.
 *
 * @param array  $mode   le règlement figé de la compétition (pour geler)
 * @param array  $cats   catégories traitées
 * @param string $depuis identifiant de l'étape terminée (celle du bouton)
 */
function selec_prepa_appliquer($tourId, $plan, $mode = null, $cats = array(), $depuis = '')
{
    $tourId = intval($tourId);
    $res = array('ok' => false, 'faits' => array(), 'erreurs' => array(), 'gelees' => array());
    if (empty($plan['ok'])) {
        $res['erreurs'][] = $plan['bloquant'] ? $plan['bloquant'] : 'Plan non exécutable.';
        return $res;
    }

    $gel = array();
    if ($mode && $depuis !== '' && function_exists('selec_arch_geler_jusqua')) {
        $g = selec_arch_geler_jusqua($tourId, $mode, $depuis, $cats);
        foreach ($g['erreurs'] as $e) $res['erreurs'][] = $e;
        $gel = $g['gelees'];
    }

    if ($plan['type'] === 'session')      $r = selec_prepa_appliquer_session($tourId, $plan);
    elseif ($plan['type'] === 'tableau')  $r = selec_prepa_appliquer_tableau($tourId, $plan);
    else {
        $res['erreurs'][] = 'Type de préparation non pris en charge : ' . $plan['type'];
        return $res;
    }

    $r['gelees'] = $gel;
    if ($gel) {
        $bouts = array();
        foreach ($gel as $sid => $n) $bouts[] = "$sid ($n archers)";
        array_unshift($r['faits'], 'Étape(s) verrouillée(s) et archivée(s) : ' . implode(', ', $bouts)
            . '. Leurs scores et leurs flèches sont figés : ils ne bougeront plus, quoi qu\'il '
            . 'arrive ensuite dans la saisie.');
    }
    foreach ($res['erreurs'] as $e) $r['erreurs'][] = $e;
    return $r;
}

/** Replace les archers sur le départ à venir. Ne touche jamais aux scores. */
function selec_prepa_appliquer_session($tourId, $plan)
{
    $res = array('ok' => true, 'faits' => array(), 'erreurs' => array());
    $ses = intval($plan['session']);
    $now = date('Y-m-d H:i:s');
    $n = 0; $sansPlace = 0;

    // Dernier contrôle avant d'écrire : un archer ne peut occuper qu'une place,
    // une place ne peut recevoir qu'un archer. Le plan est censé le garantir ;
    // on le revérifie parce qu'un placement faux ne se voit qu'au pas de tir.
    $vusArchers = array(); $vusPlaces = array();
    foreach ($plan['lignes'] as $l) {
        if (empty($l['cible'])) continue;
        $id = intval($l['id']);
        $cle = selec_prepa_place_cle($l['cible'], $l['lettre']);
        if (isset($vusArchers[$id])) {
            $res['erreurs'][] = $l['nom'] . ' apparaît deux fois dans le placement — rien écrit.';
        }
        if (isset($vusPlaces[$cle])) {
            $res['erreurs'][] = "Deux archers sur la place $cle (" . $vusPlaces[$cle] . ' et '
                . $l['nom'] . ') — rien écrit.';
        }
        $vusArchers[$id] = true;
        $vusPlaces[$cle] = $l['nom'];
    }
    if ($res['erreurs']) { $res['ok'] = false; return $res; }

    foreach ($plan['lignes'] as $l) {
        $id = intval($l['id']);
        if (empty($l['cible'])) { $sansPlace++; continue; }
        // Même écriture que Partecipants/TargetFromRank.php : session, cible et
        // lettre seulement. QuD*Score n'est jamais touché, les scores déjà tirés
        // sur les séries précédentes restent en place.
        //
        // ⚠ `Qualifications` n'a PAS de colonne de compétition : un WHERE QuId
        // seul viserait la bonne ligne aujourd'hui, mais rien n'empêcherait
        // demain un identifiant venu d'ailleurs de déplacer un archer d'une AUTRE
        // compétition. Sur un serveur qui en héberge des centaines, la jointure
        // sur Entries est la seule borne, et elle doit être dans la requête —
        // pas dans un contrôle qu'une refonte pourrait retirer.
        safe_w_sql("UPDATE Qualifications q
            INNER JOIN Entries e ON e.EnId=q.QuId AND e.EnTournament=$tourId
            SET q.QuSession=$ses,
                q.QuTarget=" . intval($l['cible']) . ",
                q.QuLetter=" . StrSafe_DB($l['lettre']) . ",
                q.QuBacknoPrinted=0,
                q.QuTimestamp=q.QuTimestamp
            WHERE q.QuId=$id");
        safe_w_sql("UPDATE Entries SET EnTimestamp=" . StrSafe_DB($now) . ",
            EnMainInfoUpdate=" . StrSafe_DB($now) . " WHERE EnId=$id AND EnTournament=$tourId");
        $n++;
    }

    $detail = array();
    foreach ((array) ($plan['categories'] ?? array()) as $c) {
        if (empty($c['actif'])) continue;
        $detail[] = $c['nom'] . ' : ' . $c['de'] . ' → ' . $c['a'];
    }
    $res['faits'][] = "$n archer(s) placés sur le départ n° $ses (« {$plan['session_nom']} »), "
        . "dans l'ordre du classement « {$plan['base_lib']} ».";
    if ($detail) $res['faits'][] = implode(' · ', $detail);
    if ($sansPlace) $res['faits'][] = "$sansPlace archer(s) sans cible faute de place.";
    selec_log($tourId, 'prepa-session', array('session' => $ses, 'base' => $plan['base'], 'places' => $n));
    return $res;
}

/**
 * Rattache les archers aux épreuves de duels (table `Individuals`).
 *
 * ianseo peuple `Individuals` depuis la portée `EventClass` de chaque épreuve —
 * c'est ce que fait `MakeIndividuals()` (`Qualification/Fun_Qualification.local.inc.php`).
 * On reprend SA requête d'insertion, à l'identique, en la bornant aux épreuves
 * que l'on s'apprête à remplir. Deux raisons de ne pas appeler la fonction
 * elle-même : elle balaie toute la compétition et **supprime** au passage les
 * lignes qui ne correspondent plus à une portée, ce qui est un rayon d'action
 * bien trop large au moment de générer un tableau ; et son fichier redéclare la
 * couche SQL, ce qui la rend inatteignable selon le point d'entrée.
 *
 * Une consolante n'a pas de portée : elle ne reçoit donc aucune ligne, et c'est
 * voulu — elle récupère les perdants de son épreuve parente, sans considération
 * de catégorie.
 *
 * Idempotent : le LEFT JOIN ... IS NULL empêche tout doublon.
 *
 * @return int nombre de rattachements créés
 */
function selec_prepa_individuals($tourId, $events)
{
    $tourId = intval($tourId);
    $in = array();
    foreach ((array) $events as $e) { if ($e !== '' && $e !== null) $in[] = StrSafe_DB($e); }
    if (!$in) return 0;
    $in = implode(',', $in);

    safe_w_sql("INSERT INTO Individuals (IndId, IndEvent, IndTournament, IndTimestamp)
        SELECT EnId, EcCode, EnTournament, " . StrSafe_DB(date('Y-m-d H:i:s')) . "
        FROM Entries
        LEFT JOIN ExtraData ON EnId=EdId AND EdType='P'
        INNER JOIN EventClass ON EnTournament=EcTournament AND EcTeamEvent=0
            AND EnDivision=EcDivision AND EnClass=EcClass
            AND IF(EcSubClass='', true, EcSubClass=EnSubClass)
            AND (IFNULL(EdExtra,0) & EcExtraAddons) = EcExtraAddons
        LEFT JOIN Individuals ON IndId=EnId AND IndTournament=EnTournament AND IndEvent=EcCode
        WHERE EnTournament=$tourId AND IndEvent IS NULL
          AND EnIndFEvent=1 AND EnStatus<=1
          AND EcCode IN ($in)");
    return safe_w_affected_rows();
}

/**
 * Valide les qualifications et génère les tableaux.
 *
 * Reproduit la séquence de Final/Individual/AbsIndividual.php, dans le même
 * ordre — c'est ianseo qui sait remplir une grille, on ne réinvente rien :
 *   1. Individuals.IndRank = l'ordre des têtes de série voulu ;
 *   2. la grille est vidée puis recréée (CreateFinalsInd) ;
 *   3. FinAthlete se remplit en joignant IndRank à Grids.GrPosition ;
 *   4. Events.EvShootOff=1 marque la qualification validée ;
 *   5. move2NextPhase() propage les byes du premier tour.
 */
function selec_prepa_appliquer_tableau($tourId, $plan)
{
    global $CFG;
    $res = array('ok' => true, 'faits' => array(), 'erreurs' => array());

    // Chaque brique de ianseo n'est chargée que si elle manque : ces fichiers
    // s'incluent entre eux et redéclarent des fonctions communes, un require
    // inconditionnel casse selon le point d'entrée.
    if (!function_exists('CreateFinalsInd'))  require_once($CFG->DOCUMENT_PATH . 'Modules/Sets/lib.php');
    if (!function_exists('valueFirstPhase'))  require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Phases.inc.php');
    if (!function_exists('move2NextPhase'))   require_once($CFG->DOCUMENT_PATH . 'Final/Fun_ChangePhase.inc.php');
    if (!class_exists('Obj_RankFactory'))     @include_once($CFG->DOCUMENT_PATH . 'Common/Lib/Obj_RankFactory.php');

    $parEvent = array();
    foreach ($plan['lignes'] as $l) $parEvent[$l['event']][] = $l;

    $tousEvents = array();
    foreach ($parEvent as $ev => $lignes) {
        $tousEvents[] = $ev;
        if (!empty($lignes[0]['consolante'])) $tousEvents[] = $lignes[0]['consolante'];
    }
    $tousEvents = array_values(array_unique(array_filter($tousEvents)));
    if (!$tousEvents) { $res['erreurs'][] = 'Aucune épreuve à préparer.'; $res['ok'] = false; return $res; }

    $inEvents = array();
    foreach ($tousEvents as $ev) $inEvents[] = StrSafe_DB($ev);
    $inEvents = implode(',', $inEvents);

    // Les lignes Individuals doivent exister pour les épreuves de tableau : sans
    // elles, la jointure de placement ne trouve personne et tous les archers
    // ressortent « n'appartient pas à l'épreuve ».
    $manque = selec_prepa_individuals($tourId, $tousEvents);
    if ($manque) $res['faits'][] = $manque . " archer(s) rattaché(s) aux épreuves de duels.";

    // 1) Ordre des têtes de série. Les archers hors tableau sont remis à 0 pour
    //    ne pas être ramassés par la jointure de placement.
    safe_w_sql("UPDATE Individuals SET IndRank=0, IndTimestamp=" . StrSafe_DB(date('Y-m-d H:i:s')) . "
        WHERE IndTournament=$tourId AND IndEvent IN ($inEvents)");
    $nSeed = 0;
    foreach ($parEvent as $ev => $lignes) {
        foreach ($lignes as $l) {
            // Contrôle d'APPARTENANCE, pas de lignes affectées : un UPDATE qui
            // réécrit la même valeur renvoie 0 ligne affectée et ferait croire à
            // tort que l'archer n'est pas dans l'épreuve.
            $rs = safe_r_sql("SELECT IndId FROM Individuals
                WHERE IndTournament=$tourId AND IndEvent=" . StrSafe_DB($ev) . "
                  AND IndId=" . intval($l['id']));
            if (!$rs || !safe_fetch($rs)) {
                $res['erreurs'][] = $l['nom'] . " n'est pas rattaché à l'épreuve $ev : sa division "
                    . "et sa classe ne correspondent à aucune ligne de portée de cette épreuve "
                    . "(Épreuves individuelles → " . $ev . "), ou son inscription n'est pas "
                    . "marquée « participe aux duels ». Non placé.";
                continue;
            }
            safe_w_sql("UPDATE Individuals SET IndRank=" . intval($l['serie']) . "
                WHERE IndTournament=$tourId AND IndEvent=" . StrSafe_DB($ev) . "
                  AND IndId=" . intval($l['id']));
            $nSeed++;
        }
    }

    // 2) Grilles remises à neuf.
    safe_w_sql("DELETE FROM Finals WHERE FinTournament=$tourId AND FinEvent IN ($inEvents)");
    CreateFinalsInd($tourId, $inEvents);

    // 3) Placement : c'est la requête de AbsIndividual.php, à l'identique.
    $q = safe_r_sql("SELECT IndId, IndRank, IndEvent, GrMatchNo, EvFinalFirstPhase
        FROM Individuals
        INNER JOIN Events ON IndTournament=EvTournament AND IndEvent=EvCode AND EvTeamEvent=0
        INNER JOIN Phases ON PhId=EvFinalFirstPhase AND (PhIndTeam & 1) = 1
        INNER JOIN Grids ON GrPhase=greatest(PhId,PhLevel)
            AND (IndRank-EvFirstQualified+1)=IF(EvFinalFirstPhase=48, GrPosition2,
                IF(GrPosition>EvNumQualified, 0, GrPosition))
        WHERE IndRank BETWEEN EvFirstQualified AND (EvNumQualified+EvFirstQualified-1)
          AND IndTournament=$tourId AND EvCode IN ($inEvents)
        ORDER BY EvCode, IndRank ASC, GrMatchNo ASC");
    $phases = array(); $nPlaces = 0;
    while ($q && ($r = safe_fetch($q))) {
        safe_w_sql("UPDATE Finals SET FinAthlete=" . intval($r->IndId) . ",
            FinDateTime=" . StrSafe_DB(date('Y-m-d H:i:s')) . "
            WHERE FinTournament=$tourId AND FinEvent=" . StrSafe_DB($r->IndEvent) . "
              AND FinMatchNo=" . intval($r->GrMatchNo));
        if (!isset($phases[$r->IndEvent])) $phases[$r->IndEvent] = valueFirstPhase($r->EvFinalFirstPhase);
        $nPlaces++;
    }

    // 4) Qualification validée pour ces épreuves.
    safe_w_sql("UPDATE Events SET EvShootOff=1
        WHERE EvTournament=$tourId AND EvTeamEvent=0 AND EvCode IN ($inEvents)");
    if (function_exists('set_qual_session_flags')) set_qual_session_flags();

    // 5) Byes du premier tour + classement final des épreuves touchées.
    $recalc = array();
    foreach ($phases as $ev => $phase) {
        // 4e argument = la compétition. Sans lui, ianseo retombe sur
        // $_SESSION['TourId'] : c'est la même ici, mais une écriture ne doit pas
        // dépendre d'un état implicite quand la borne peut être explicite.
        if (function_exists('move2NextPhase')) move2NextPhase($phase, $ev, null, $tourId);
        $recalc[] = $ev . '@-3';
    }
    if ($recalc && class_exists('Obj_RankFactory')) {
        try { Obj_RankFactory::create('FinalInd', array('eventsC' => $recalc))->calculate(); }
        catch (Exception $e) { $res['erreurs'][] = 'Recalcul du classement final : ' . $e->getMessage(); }
    }

    $res['faits'][] = "$nSeed tête(s) de série écrites, $nPlaces archer(s) placés dans "
        . count($tousEvents) . " épreuve(s) : " . implode(', ', $tousEvents) . '.';
    $res['faits'][] = "Classement de référence : « {$plan['base_lib']} ».";
    selec_log($tourId, 'prepa-tableau', array('cible' => $plan['cible'], 'base' => $plan['base'],
        'events' => $tousEvents, 'places' => $nPlaces));
    return $res;
}
