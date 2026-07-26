<?php
/** ajax/blocs.php — lecture et modification des blocs du plan de départ. */
define('HTDOCS', dirname(__DIR__, 4));
require_once dirname(__DIR__) . '/lib/boot.php';
rep_check_token();

$action = $_POST['action'] ?? $_GET['action'] ?? 'etat';

/** Rejette toute géométrie hors du départ ou en chevauchement. */
function rep_valide_geo($tourId, $b, $ignorerId = 0)
{
    $departs = rep_departs($tourId);
    if (empty($departs[$b['session']])) return 'Départ inconnu.';
    $d = $departs[$b['session']];
    $fin = $d['premiere'] + $d['cibles'] - 1;

    if ($b['t1'] > $b['t2'] || $b['l1'] > $b['l2']) return 'Plage invalide : le début doit précéder la fin.';
    if ($b['t1'] < $d['premiere'] || $b['t2'] > $fin) {
        return 'Hors du départ (cibles ' . $d['premiere'] . ' à ' . $fin . ').';
    }
    if ($b['l1'] < 0 || $b['l2'] > $d['ath'] - 1) {
        return 'Hors du départ (lettres A à ' . rep_lettre($d['ath'] - 1) . ').';
    }
    foreach (rep_blocs($tourId, $b['session']) as $o) {
        if ($o['id'] == $ignorerId) continue;
        if (!($b['t2'] < $o['t1'] || $b['t1'] > $o['t2'] || $b['l2'] < $o['l1'] || $b['l1'] > $o['l2'])) {
            return 'Refusé : deux blocs se disputeraient les mêmes places sur cible.';
        }
    }
    return '';
}

/** Premier emplacement libre d'une taille donnée sur un départ. */
function rep_place_libre($tourId, $session, $larg, $haut)
{
    $departs = rep_departs($tourId);
    if (empty($departs[$session])) return null;
    $d = $departs[$session];
    $haut = min($haut, $d['ath']);
    $larg = min($larg, $d['cibles']);
    for ($l = 0; $l + $haut - 1 < $d['ath']; $l++) {
        for ($t = $d['premiere']; $t + $larg - 1 <= $d['premiere'] + $d['cibles'] - 1; $t++) {
            $cand = ['session' => $session, 't1' => $t, 't2' => $t + $larg - 1, 'l1' => $l, 'l2' => $l + $haut - 1];
            if (rep_valide_geo($tourId, $cand) === '') return $cand;
        }
    }
    return null;
}

/**
 * Effectif réel d'une épreuve : le nombre de participants (Individuals) si aucun
 * bloc de l'épreuve n'a activé « hors épr. », sinon le nombre étendu incluant les
 * archers de même arme/sexe (et classe si l'épreuve n'est pas Scratch) qui ne
 * participent à aucune épreuve individuelle. Recalculé à chaque appel : c'est ce
 * qui garde la colonne Effectif « en direct ».
 */
function rep_epreuve_nb_effectif($tourId, $cle, $cfg, $epr, $inclnpActif)
{
    if (!$inclnpActif) return intval($epr[$cle]['nb'] ?? 0);
    $archers = rep_archers_epreuve($tourId, $cle, $cfg['annee'], $cfg['discipline'],
                                    $cfg['set'] ?? '', true, $epr[$cle] ?? null);
    return count($archers);
}

function rep_etat_complet($tourId)
{
    $cfg     = rep_config_lire($tourId);
    $departs = rep_departs($tourId);
    $blocs   = rep_blocs($tourId);
    $epr     = rep_epreuves($tourId);

    // « hors épr. » actif dès qu'un seul bloc de l'épreuve l'a coché ; idem pour
    // les sources qui demandent le classement d'arrêté individuel (3 et 4) — sans
    // ça, la colonne Effectif affichait « sans classement » même quand le bloc
    // utilisait bien un classement d'arrêté valide (bug réel signalé par
    // l'utilisateur : rien ne distinguait « pas de classement » de « classement
    // FFTA non consulté parce que la source demandée est l'arrêté »).
    $inclnpParEv = [];
    $arrIndParEv = [];
    foreach ($blocs as $b) {
        if (!empty($b['inclnp'])) $inclnpParEv[$b['event']] = true;
        if (in_array($b['src'], [REP_SRC_ARRETE_IND, REP_SRC_CLUBS_ARR_IND], true)) $arrIndParEv[$b['event']] = true;
    }

    // Effectif, classement et nombre de cibles déjà attribuées, par épreuve.
    $affecte  = rep_affectations_par_epreuve($tourId);
    $epreuves = [];
    foreach ($epr as $cle => $e) {
        $cl = !empty($arrIndParEv[$cle])
            ? rep_classement_arrete($tourId, $cle, $e, $cfg['set'] ?? '')
            : rep_classement_epreuve($tourId, $cle, $cfg['annee'], $cfg['discipline'], $cfg['set'] ?? '');
        $classe = $cl && ($cl['ccid'] || !empty($cl['arrid']));
        $epreuves[$cle] = [
            'cle'      => $cle,
            'event'    => $e['event'],
            'division' => $e['division'],
            'sexe'     => $e['sexe'],
            'libelle'  => $e['event'],
            'nom'      => $e['nom'],
            'classes'  => implode(', ', $e['classes']),
            'nb'       => rep_epreuve_nb_effectif($tourId, $cle, $cfg, $epr, !empty($inclnpParEv[$cle])),
            'classement' => $classe ? $cl['libelle'] : '',
            'classe'   => $classe,
            'affecte'  => $affecte[$cle] ?? 0,
            // Disponibilité de chaque source pour CETTE épreuve : sert à griser
            // dans le sélecteur « Source de l'ordre » celles sans classement réel
            // derrière elles, pour ne plus jamais retomber en silence sur l'ordre
            // alphabétique sans que ce soit visible (bug réel signalé par
            // l'utilisateur : S3WCL en alphabétique sans aucun signal).
            'sourcesDispo' => rep_sources_dispo($tourId, $cle, $e, $cfg),
        ];
    }
    // Cases vides (aucun archer) par bloc, pour la vue graphique du plan des
    // départs (croix grises) : la différence entre les cases géométriques du
    // bloc et celles réellement remplies par le placement — fonctionne quel
    // que soit l'algorithme (ordre simple ou groupement par club, où les trous
    // ne sont pas forcément en fin de bloc) puisqu'on compare au résultat réel
    // plutôt que de re-déduire une position. Demandé par l'utilisateur pour
    // repérer les trous avant d'appliquer, et réorganiser si besoin.
    $plan = rep_placer_tout($tourId);
    foreach ($blocs as &$b) {
        $remplies = [];
        foreach (($plan['parBloc'][$b['id']] ?? []) as $r) $remplies[$r['t'] . '|' . $r['l']] = true;
        $vides = [];
        for ($t = $b['t1']; $t <= $b['t2']; $t++) {
            for ($l = $b['l1']; $l <= $b['l2']; $l++) {
                if (!isset($remplies[$t . '|' . $l])) $vides[] = ['t' => $t, 'l' => $l];
            }
        }
        $b['vides'] = $vides;
    }
    unset($b);

    $ctrl = rep_controles($tourId);
    $reg  = rep_regles();
    return [
        'ok'        => true,
        'config'    => $cfg,
        'departs'   => array_values($departs),
        'blocs'     => $blocs,
        'epreuves'  => $epreuves,
        'controles' => $ctrl,
        'journal'   => rep_journal_recent($tourId, 3),
        // Valeurs par défaut de bloc (1.9.0) : préremplissent « ajouter » et
        // s'appliquent d'un coup à tous les blocs via l'action 'defaut_appliquer'.
        'blocDefaut' => rep_bloc_defaut_lire($tourId),
        'regles'    => [
            'sources'   => $reg['sources'] ?? [],
            'reglement' => $reg['reglement']['texte'] ?? '',
            'max_club'  => rep_max_club(),
        ],
    ];
}

switch ($action) {

    case 'etat':
        JsonOut(rep_etat_complet($REP_TOUR));
        break;

    case 'ajouter':
        $cle = (string) ($_POST['cle'] ?? '');
        $ses = intval($_POST['session'] ?? 0);
        $epr = rep_epreuves($REP_TOUR);
        if (empty($epr[$cle])) JsonOut(['ok' => false, 'err' => 'Épreuve inconnue dans cette compétition.']);
        $departs = rep_departs($REP_TOUR);
        if (empty($departs[$ses])) JsonOut(['ok' => false, 'err' => 'Départ inconnu.']);

        // Préremplit avec les valeurs par défaut de la compétition (panneau
        // « Défauts des blocs ») plutôt que des valeurs figées.
        $def = rep_bloc_defaut_lire($REP_TOUR);

        // Taille par défaut : de quoi loger tout l'effectif sur toute la hauteur — en
        // tenant compte de « hors épr. » DÈS LA CRÉATION quand le défaut l'active (même
        // calcul que le redimensionnement de l'action 'modifier' au changement de cette
        // case). Sans ça, un bloc créé avec « hors épr. » déjà coché par défaut restait
        // dimensionné pour l'effectif de base seul — les archers hors épreuve manquaient
        // sur le bloc visuel, l'effectif affiché et le total du départ, jusqu'à décocher
        // puis recocher la case à la main sur CE bloc (bug réel signalé par l'utilisateur).
        $autresPlaces = 0;
        $autresInclnp = false;
        foreach (rep_blocs($REP_TOUR) as $bb) {
            if ($bb['cle'] !== $cle) continue;
            $autresPlaces += rep_places($bb);
            if (!empty($bb['inclnp'])) $autresInclnp = true;
        }
        $cfgR  = rep_config_lire($REP_TOUR);
        $nbEff = rep_epreuve_nb_effectif($REP_TOUR, $cle, $cfgR, $epr, !empty($def['inclnp']) || $autresInclnp);
        $besoin = max(1, $nbEff - $autresPlaces);

        $haut = $departs[$ses]['ath'];
        $larg = max(1, (int) ceil($besoin / max(1, $haut)));
        $pos  = null;
        while ($larg > 0 && !$pos) { $pos = rep_place_libre($REP_TOUR, $ses, $larg, $haut); $larg--; }
        if (!$pos) {
            $larg = min($besoin, $departs[$ses]['cibles']);
            while ($larg > 0 && !$pos) { $pos = rep_place_libre($REP_TOUR, $ses, $larg, 1); $larg--; }
        }
        if (!$pos) JsonOut(['ok' => false, 'err' => 'Aucun emplacement libre sur ce départ.']);

        // Le nouveau bloc démarre après le dernier rang déjà consommé par l'épreuve,
        // pour qu'aucun archer ne soit servi deux fois.
        $depuis = 1;
        foreach (rep_blocs($REP_TOUR) as $b) {
            if ($b['cle'] !== $cle) continue;
            $depuis = max($depuis, $b['depuis'] + rep_places($b));
        }
        safe_w_sql("INSERT INTO REP_Blocs
            (CbTournament, CbSession, CbEvent, CbT1, CbT2, CbL1, CbL2,
             CbSource, CbSource2, CbParcours, CbSerpentin, CbSensLettres, CbSensCibles,
             CbDepuis, CbBrassage, CbInclureNP, CbUpdated)
            VALUES ($REP_TOUR, $ses, " . StrSafe_DB($cle) . ",
             {$pos['t1']}, {$pos['t2']}, {$pos['l1']}, {$pos['l2']},
             {$def['src']}, {$def['src2']}, {$def['ciblePriorite']}, {$def['serpentin']},
             {$def['sl']}, {$def['sc']}, $depuis, {$def['br']}, {$def['inclnp']}, "
             . StrSafe_DB(date('Y-m-d H:i:s')) . ")");
        JsonOut(rep_etat_complet($REP_TOUR));
        break;

    case 'dupliquer':
        $id = intval($_POST['id'] ?? 0);
        $src = null;
        foreach (rep_blocs($REP_TOUR) as $b) if ($b['id'] === $id) $src = $b;
        if (!$src) JsonOut(['ok' => false, 'err' => 'Bloc introuvable.']);
        $larg = $src['t2'] - $src['t1'] + 1;
        $haut = $src['l2'] - $src['l1'] + 1;
        $pos  = rep_place_libre($REP_TOUR, $src['session'], $larg, $haut);
        if (!$pos) $pos = rep_place_libre($REP_TOUR, $src['session'], 1, $haut);
        if (!$pos) JsonOut(['ok' => false, 'err' => 'Plus de place libre sur ce départ.']);
        $depuis = 1;
        foreach (rep_blocs($REP_TOUR) as $b) {
            if ($b['cle'] !== $src['cle']) continue;
            $depuis = max($depuis, $b['depuis'] + rep_places($b));
        }
        safe_w_sql("INSERT INTO REP_Blocs
            (CbTournament, CbSession, CbEvent, CbT1, CbT2, CbL1, CbL2,
             CbSource, CbSource2, CbParcours, CbSerpentin, CbSensLettres, CbSensCibles, CbDepuis, CbBrassage, CbInclureNP, CbUpdated)
            VALUES ($REP_TOUR, {$src['session']}, " . StrSafe_DB($src['event']) . ",
             {$pos['t1']}, {$pos['t2']}, {$pos['l1']}, {$pos['l2']},
             {$src['src']}, {$src['src2']}, {$src['ciblePriorite']}, {$src['serpentin']}, {$src['sl']}, {$src['sc']}, $depuis, {$src['br']}, {$src['inclnp']}, "
             . StrSafe_DB(date('Y-m-d H:i:s')) . ")");
        JsonOut(rep_etat_complet($REP_TOUR));
        break;

    case 'supprimer':
        $id  = intval($_POST['id'] ?? 0);
        $src = null;
        foreach (rep_blocs($REP_TOUR) as $b) if ($b['id'] === $id) $src = $b;
        safe_w_sql("DELETE FROM REP_Blocs WHERE CbId=$id AND CbTournament=$REP_TOUR");
        // Sur demande, on libère aussi les cibles déjà attribuées aux archers de
        // l'épreuve — borné à la compétition et à l'épreuve, jamais de débordement.
        $libere = 0;
        if ($src && !empty($_POST['liberer'])) {
            $libere = rep_liberer_epreuve($REP_TOUR, $src['event']);
        }
        $etat = rep_etat_complet($REP_TOUR);
        $etat['libere'] = $libere;
        JsonOut($etat);
        break;

    // Applique le rééquilibrage de la dernière cible aux blocs où c'est possible.
    case 'repartir':
        $ctrl = rep_controles($REP_TOUR);
        $n = 0;
        foreach ($ctrl['rebalancables'] as $bid) {
            safe_w_sql("UPDATE REP_Blocs SET CbRebalance=1, CbUpdated=" . StrSafe_DB(date('Y-m-d H:i:s'))
                . " WHERE CbId=" . intval($bid) . " AND CbTournament=$REP_TOUR");
            $n++;
        }
        $etat = rep_etat_complet($REP_TOUR);
        $etat['repartis'] = $n;
        JsonOut($etat);
        break;

    // Applique le brassage fédéral (au plus 2 archers d'un club par cible) aux blocs
    // qui enfreignent la règle. Le mélange strict, lui, se choisit bloc par bloc.
    case 'brasser':
        $ctrl = rep_controles($REP_TOUR);
        $n = 0;
        foreach ($ctrl['brassables'] as $bid) {
            safe_w_sql("UPDATE REP_Blocs SET CbBrassage=1, CbUpdated=" . StrSafe_DB(date('Y-m-d H:i:s'))
                . " WHERE CbId=" . intval($bid) . " AND CbTournament=$REP_TOUR AND CbBrassage=0");
            $n++;
        }
        $etat = rep_etat_complet($REP_TOUR);
        $etat['brasses'] = $n;
        JsonOut($etat);
        break;

    case 'modifier':
        $id = intval($_POST['id'] ?? 0);
        $src = null;
        foreach (rep_blocs($REP_TOUR) as $b) if ($b['id'] === $id) $src = $b;
        if (!$src) JsonOut(['ok' => false, 'err' => 'Bloc introuvable.']);

        $inclnpChange = isset($_POST['inclnp'])
            && ((intval($_POST['inclnp']) ? 1 : 0) !== $src['inclnp']);

        $n = $src;
        foreach (['session', 't1', 't2', 'l1', 'l2', 'src', 'src2', 'ciblePriorite', 'serpentin',
                  'sl', 'sc', 'depuis', 'br', 'inclnp'] as $f) {
            if (isset($_POST[$f]) && $_POST[$f] !== '') $n[$f] = intval($_POST[$f]);
        }
        $n['depuis'] = max(1, $n['depuis']);
        $n['src']    = max(0, min(7, $n['src']));
        $n['src2']   = max(0, min(7, $n['src2']));
        $n['ciblePriorite'] = $n['ciblePriorite'] ? 1 : 0;
        $n['serpentin']     = $n['serpentin'] ? 1 : 0;
        $n['sl']     = $n['sl'] ? 1 : 0;
        $n['sc']     = $n['sc'] ? 1 : 0;
        // « Féd. » ne se règle plus par bloc (voir contrôles règlement, bouton
        // « Brasser ») : seul « Mélange » (2) reste ajustable ici. On laisse
        // passer 1 (fédéral) tel quel s'il était déjà posé par ce bouton — la
        // case à cocher « Options » ne peut plus, elle, produire que 0 ou 2.
        $n['br']     = in_array($n['br'], [0, 1, 2], true) ? $n['br'] : 0;
        $n['inclnp'] = !empty($n['inclnp']) ? 1 : 0;

        // Bascule de « hors épr. » : redimensionne ce bloc (largeur en cibles, à
        // hauteur inchangée) pour coller au nouvel effectif de l'épreuve. Best-effort :
        // si ça collide avec un autre bloc, on n'ajuste rien et l'utilisateur redimensionne
        // à la main — l'écriture reste bloquée par le contrôle d'effectif de toute façon.
        if ($inclnpChange) {
            $cfgR = rep_config_lire($REP_TOUR);
            $eprR = rep_epreuves($REP_TOUR);
            $autresPlaces = 0;
            $autresInclnp = false;
            foreach (rep_blocs($REP_TOUR) as $bb) {
                if ($bb['id'] == $id) continue;
                if ($bb['event'] !== $src['event']) continue;
                $autresPlaces += rep_places($bb);
                if (!empty($bb['inclnp'])) $autresInclnp = true;
            }
            $nbEff = rep_epreuve_nb_effectif($REP_TOUR, $src['event'], $cfgR, $eprR,
                                              $n['inclnp'] || $autresInclnp);
            $besoin = max(1, $nbEff - $autresPlaces);
            $haut = $n['l2'] - $n['l1'] + 1;
            $larg = max(1, (int) ceil($besoin / $haut));
            $dep  = rep_departs($REP_TOUR)[$n['session']] ?? null;
            if ($dep) {
                $maxT2 = $dep['premiere'] + $dep['cibles'] - 1;
                $t2new = min($maxT2, $n['t1'] + $larg - 1);
                $essai = $n; $essai['t2'] = $t2new;
                if ($t2new >= $n['t1'] && rep_valide_geo($REP_TOUR, $essai, $id) === '') {
                    $n['t2'] = $t2new;
                }
            }
        }

        // Changement de départ : on repose le bloc sur le premier emplacement libre.
        if ($n['session'] !== $src['session']) {
            $pos = rep_place_libre($REP_TOUR, $n['session'],
                                   $n['t2'] - $n['t1'] + 1, $n['l2'] - $n['l1'] + 1);
            if (!$pos) JsonOut(['ok' => false, 'err' => "Le départ visé n'a pas d'emplacement libre de cette taille."]);
            $n['t1'] = $pos['t1']; $n['t2'] = $pos['t2'];
            $n['l1'] = $pos['l1']; $n['l2'] = $pos['l2'];
        }

        $err = rep_valide_geo($REP_TOUR, $n, $id);
        if ($err !== '') JsonOut(['ok' => false, 'err' => $err]);

        safe_w_sql("UPDATE REP_Blocs SET
            CbSession={$n['session']}, CbT1={$n['t1']}, CbT2={$n['t2']},
            CbL1={$n['l1']}, CbL2={$n['l2']}, CbSource={$n['src']}, CbSource2={$n['src2']},
            CbParcours={$n['ciblePriorite']}, CbSerpentin={$n['serpentin']},
            CbSensLettres={$n['sl']}, CbSensCibles={$n['sc']}, CbDepuis={$n['depuis']},
            CbBrassage={$n['br']}, CbInclureNP={$n['inclnp']}, CbUpdated=" . StrSafe_DB(date('Y-m-d H:i:s')) . "
            WHERE CbId=$id AND CbTournament=$REP_TOUR");
        JsonOut(rep_etat_complet($REP_TOUR));
        break;

    // Valeurs par défaut de bloc (1.9.0) : mêmes champs qu'un bloc, mais sans
    // identifiant — s'enregistrent au fil des changements (comme un bloc),
    // préremplissent « ajouter », et peuvent être appliquées d'un coup à tous
    // les blocs existants via 'defaut_appliquer'. Jamais de position ni de
    // départ ici : ces réglages sont propres à chaque bloc, pas à la compétition.
    case 'defaut_modifier':
        $def = rep_bloc_defaut_lire($REP_TOUR);
        foreach (['inclnp', 'sc', 'sl', 'ciblePriorite', 'src', 'src2', 'br', 'serpentin'] as $f) {
            if (isset($_POST[$f]) && $_POST[$f] !== '') $def[$f] = intval($_POST[$f]);
        }
        $def['src']  = max(0, min(7, $def['src']));
        $def['src2'] = max(0, min(7, $def['src2']));
        $def['ciblePriorite'] = $def['ciblePriorite'] ? 1 : 0;
        $def['serpentin']     = $def['serpentin'] ? 1 : 0;
        $def['sl'] = $def['sl'] ? 1 : 0;
        $def['sc'] = $def['sc'] ? 1 : 0;
        $def['br'] = in_array($def['br'], [0, 2], true) ? $def['br'] : 0;   // jamais 1 (fédéral) par défaut : voir contrôles
        $def['inclnp'] = !empty($def['inclnp']) ? 1 : 0;
        rep_bloc_defaut_ecrire($REP_TOUR, $def);
        JsonOut(rep_etat_complet($REP_TOUR));
        break;

    // Applique les valeurs par défaut ACTUELLEMENT enregistrées à TOUS les
    // blocs de la compétition (tous départs) — jamais leur position/départ/
    // épreuve/« depuis n° », seulement les réglages partagés par le panneau
    // de défauts. Borné à la compétition comme toute écriture du module.
    case 'defaut_appliquer':
        $def = rep_bloc_defaut_lire($REP_TOUR);
        $nAvant = count(rep_blocs($REP_TOUR));   // affecte inconditionnellement tous les blocs du tournoi
        safe_w_sql("UPDATE REP_Blocs SET
            CbSource={$def['src']}, CbSource2={$def['src2']},
            CbParcours={$def['ciblePriorite']}, CbSerpentin={$def['serpentin']},
            CbSensLettres={$def['sl']}, CbSensCibles={$def['sc']},
            CbBrassage={$def['br']}, CbInclureNP={$def['inclnp']},
            CbUpdated=" . StrSafe_DB(date('Y-m-d H:i:s')) . "
            WHERE CbTournament=$REP_TOUR");
        $etat = rep_etat_complet($REP_TOUR);
        $etat['defautsAppliques'] = $nAvant;
        JsonOut($etat);
        break;

    default:
        JsonOut(['ok' => false, 'err' => 'Action inconnue.']);
}
