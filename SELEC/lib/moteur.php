<?php
/**
 * lib/moteur.php — les briques de calcul et leur orchestration.
 *
 * Un « mode de sélection » est une SUITE ORDONNÉE d'étapes ; chaque étape est
 * une brique paramétrée du catalogue ci-dessous. Ajouter une sélection revient
 * à écrire un JSON, pas à toucher ce fichier — c'est l'exigence d'architecture
 * du cahier des charges (§1 et §10 de la spec).
 *
 * Catalogue des briques (champ `type` d'une étape) :
 *   qualification   — un round de N×36 flèches, classé au score
 *   duels_simules   — M duels tirés pour le score seul (pas pour la victoire)
 *   tournoi         — tableau montante/descendante : classement + performance
 *   poule           — tous contre tous : victoires + performance
 *   journee         — cumul des points d'une journée, reclassé
 *   coupure         — on ne garde que les N premiers
 *   final           — classement de l'épreuve sur le cumul des journées
 *
 * Contrat commun à toutes les briques :
 *   - elles LISENT le contexte ($ctx) et n'écrivent que dans $ctx['etapes'][id]
 *   - elles produisent `lignes` (rang / points / valeur / détail / départage)
 *   - elles produisent `contrib` : ce que l'étape apporte aux critères
 *     transversaux (score brut, flèches, sets, victoires). Une brique de CUMUL
 *     (journee, final, coupure) ne contribue RIEN : ses composantes ont déjà
 *     été comptées par les étapes sources, les recompter fausserait la valeur
 *     moyenne de flèche.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/donnees.php';
require_once __DIR__ . '/classement.php';

// ─────────────────────────────────────────────────────────────────────────────
// Contexte
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Prépare le contexte de calcul d'une catégorie.
 *
 * @param int    $tourId  compétition ianseo
 * @param string $cat     code de l'épreuve ianseo qui porte les qualifications
 * @param array  $mode    le mode de sélection (snapshot figé, jamais le fichier vivant)
 * @param array  $binds   [étape][rôle] => code d'épreuve ianseo
 */
function selec_contexte($tourId, $cat, $mode, $binds = array())
{
    $tourId = intval($tourId);
    $ctx = array(
        'tour'     => $tourId,
        'cat'      => $cat,
        'mode'     => $mode,
        'binds'    => $binds,
        'tournoi'  => selec_tournoi($tourId),
        'archers'  => selec_archers($tourId, $cat),
        'etapes'   => array(),
        'alertes'  => array(),
        'barrages' => selec_barrages($tourId, $cat),
    );
    $ctx['quals'] = selec_quals($tourId, array_keys($ctx['archers']));
    // Étapes gelées : leurs scores ne se lisent plus dans Qualifications mais
    // dans la copie figée, ce qui rend leur classement inaltérable même si la
    // ligne de l'archer est réécrite plus tard (voir lib/archive.php).
    $ctx['gel']      = function_exists('selec_arch_etat')  ? selec_arch_etat($tourId)  : array();
    $ctx['archives'] = function_exists('selec_arch_lire')  ? selec_arch_lire($tourId)  : array();
    return $ctx;
}

/** Tirs de barrage saisis : [étape][EnId] => ordre (1 = gagnant). */
function selec_barrages($tourId, $cat)
{
    $out = array();
    $rs = safe_r_sql("SELECT SoStep, SoEntry, SoOrder FROM SELEC_Shootoff
        WHERE SoTournament=" . intval($tourId) . "
          AND SoCategory=" . StrSafe_DB($cat));
    while ($rs && ($r = safe_fetch($rs))) {
        $out[$r->SoStep][intval($r->SoEntry)] = intval($r->SoOrder);
    }
    return $out;
}

/** Épreuves ianseo rattachées à un rôle d'une étape. */
function selec_bind_events($ctx, $stepId, $slots)
{
    $out = array();
    foreach ((array) $slots as $slot) {
        if (!empty($ctx['binds'][$stepId][$slot])) $out[] = $ctx['binds'][$stepId][$slot];
    }
    return $out;
}

/** Barème nommé du mode. */
function selec_bareme($ctx, $nom)
{
    if ($nom === '' || $nom === null) return null;
    return isset($ctx['mode']['baremes'][$nom]) ? $ctx['mode']['baremes'][$nom] : null;
}

/** Nom affichable d'un archer, sûr même hors contexte complet (tests, alertes). */
function selec_nom($ctx, $id)
{
    return isset($ctx['archers'][$id]['affiche'])
        ? $ctx['archers'][$id]['affiche']
        : ('archer ' . $id);
}

/** Contribution d'un archer sur une étape (0 partout si l'étape n'en a pas). */
function selec_contrib_vide()
{
    return array(
        'score' => 0, 'fleches' => 0, 'gold' => 0, 'x' => 0,
        'sets' => 0, 'set_total' => 0, 'victoires' => 0, 'victoires_finales' => 0,
        'points_set' => 0, 'matchs' => 0,
    );
}

/** Somme des contributions d'un archer sur une liste d'étapes. */
function selec_contrib_somme($ctx, $id, $etapes)
{
    $t = selec_contrib_vide();
    foreach ((array) $etapes as $sid) {
        if (empty($ctx['etapes'][$sid]['contrib'][$id])) continue;
        foreach ($ctx['etapes'][$sid]['contrib'][$id] as $k => $v) {
            if (isset($t[$k])) $t[$k] += $v;
        }
    }
    return $t;
}

/** Points (centièmes) cumulés par un archer sur une liste d'étapes. */
function selec_points_somme($ctx, $id, $etapes)
{
    $t = 0;
    foreach ((array) $etapes as $sid) {
        if (isset($ctx['etapes'][$sid]['lignes'][$id]['points_c'])) {
            $t += intval($ctx['etapes'][$sid]['lignes'][$id]['points_c']);
        }
    }
    return $t;
}

// ─────────────────────────────────────────────────────────────────────────────
// Critères de départage
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fabrique un critère de la cascade.
 *
 * Chaque critère est explicite sur son périmètre : les étapes de qualification
 * (`quals`) et/ou les étapes à duels (`etapes`) sur lesquelles il porte. Rien
 * n'est deviné — c'est ce qui permet à « meilleure qualification » de désigner
 * les 2 qualifications du jour à un endroit et les 4 de l'épreuve à un autre.
 */
function selec_critere(&$ctx, $def, $stepId)
{
    $c = isset($def['c']) ? $def['c'] : '';
    $quals   = isset($def['quals'])   ? (array) $def['quals']   : array();
    $etapes  = isset($def['etapes'])  ? (array) $def['etapes']  : array();
    $sources = isset($def['sources']) ? (array) $def['sources'] : array();

    switch ($c) {

        case 'points': // cumul de points sur des étapes (valeur principale d'une journée)
            return array('id' => 'points', 'label' => 'Points cumulés',
                'fn' => function ($id) use (&$ctx, $sources) {
                    return selec_v_int(selec_points_somme($ctx, $id, $sources));
                });

        case 'score': // score brut cumulé sur des étapes
            return array('id' => 'score', 'label' => 'Score',
                'fn' => function ($id) use (&$ctx, $quals) {
                    $t = selec_contrib_somme($ctx, $id, $quals);
                    return selec_v_int($t['score']);
                });

        case 'somme_scores':
            return array('id' => 'somme_scores', 'label' => 'Somme des scores',
                'fn' => function ($id) use (&$ctx, $quals) {
                    $t = selec_contrib_somme($ctx, $id, $quals);
                    return selec_v_int($t['score']);
                });

        case 'x':
            return array('id' => 'x', 'label' => 'Nombre de X',
                'fn' => function ($id) use (&$ctx, $quals) {
                    $t = selec_contrib_somme($ctx, $id, $quals);
                    return selec_v_int($t['x']);
                });

        case 'dix':
            return array('id' => 'dix', 'label' => 'Nombre de 10',
                'fn' => function ($id) use (&$ctx, $quals) {
                    $t = selec_contrib_somme($ctx, $id, $quals);
                    return selec_v_int($t['gold']);
                });

        case 'qual_n': // n-ième MEILLEURE qualification parmi `quals` : score, puis X, puis 10
            $n = max(1, intval(isset($def['n']) ? $def['n'] : 1));
            return array('id' => 'qual' . $n, 'label' => selec_label_qual($n),
                'fn' => function ($id) use (&$ctx, $quals, $n) {
                    $liste = array();
                    foreach ($quals as $sid) {
                        if (empty($ctx['etapes'][$sid]['contrib'][$id])) continue;
                        $t = $ctx['etapes'][$sid]['contrib'][$id];
                        $liste[] = array(intval($t['score']), intval($t['x']), intval($t['gold']));
                    }
                    // Tri décroissant sur (score, X, 10) — la « meilleure » qualification
                    // au sens du règlement, pas l'ordre chronologique.
                    usort($liste, function ($a, $b) {
                        for ($i = 0; $i < 3; $i++) { if ($a[$i] !== $b[$i]) return $b[$i] <=> $a[$i]; }
                        return 0;
                    });
                    if (count($liste) < $n) return null;
                    return selec_v_vec($liste[$n - 1]);
                });

        case 'valeur_fleche': // total marqué / total de flèches tirées, en fraction exacte
            return array('id' => 'valeur_fleche', 'label' => 'Valeur moyenne de flèche',
                'fn' => function ($id) use (&$ctx, $quals, $etapes) {
                    $t = selec_contrib_somme($ctx, $id, array_merge($quals, $etapes));
                    return selec_v_frac($t['score'], $t['fleches']);
                });

        case 'moyenne_set': // total des sets tirés / nombre de sets, en fraction exacte
            return array('id' => 'moyenne_set', 'label' => 'Moyenne de set',
                'fn' => function ($id) use (&$ctx, $etapes) {
                    $t = selec_contrib_somme($ctx, $id, $etapes);
                    return selec_v_frac($t['set_total'], $t['sets']);
                });

        case 'victoires':
            return array('id' => 'victoires', 'label' => 'Nombre de victoires',
                'fn' => function ($id) use (&$ctx, $etapes) {
                    $t = selec_contrib_somme($ctx, $id, $etapes);
                    return selec_v_int($t['victoires']);
                });

        case 'victoires_finales':
            return array('id' => 'victoires_finales', 'label' => 'Victoires en finale de tournoi',
                'fn' => function ($id) use (&$ctx, $etapes) {
                    $t = selec_contrib_somme($ctx, $id, $etapes);
                    return selec_v_int($t['victoires_finales']);
                });

        case 'points_set':
            return array('id' => 'points_set', 'label' => 'Points de set',
                'fn' => function ($id) use (&$ctx, $etapes) {
                    $t = selec_contrib_somme($ctx, $id, $etapes);
                    return selec_v_int($t['points_set']);
                });

        case 'barrage': // saisi à la main : 1 = gagnant du barrage
            return array('id' => 'barrage', 'label' => 'Tir de barrage',
                'fn' => function ($id) use (&$ctx, $stepId) {
                    if (!isset($ctx['barrages'][$stepId][$id])) return null;
                    $o = intval($ctx['barrages'][$stepId][$id]);
                    return $o > 0 ? selec_v_int(-$o) : null;
                });

        case 'egalite':
            return array('id' => 'egalite', 'label' => 'Égalité conservée', 'fn' => function () { return null; });
    }

    $ctx['alertes'][] = "Étape $stepId : critère de départage inconnu « $c » — ignoré.";
    return null;
}

/**
 * Libellé d'un critère de départage, sans avoir à monter un contexte.
 *
 * Passe par `selec_critere()` avec un contexte jetable plutôt que de recopier
 * les libellés : deux listes finiraient par diverger, et un en-tête de colonne
 * qui ne dit pas la même chose que le pied de page d'un classement de sélection
 * est exactement le genre de détail qui fait douter du reste.
 */
function selec_critere_infos($def)
{
    $bidon = array('alertes' => array(), 'etapes' => array(), 'barrages' => array());
    $cr = selec_critere($bidon, $def, '');
    return $cr ? array('id' => $cr['id'], 'label' => $cr['label']) : null;
}

function selec_critere_label($def)
{
    $i = selec_critere_infos($def);
    return $i ? $i['label'] : '';
}

function selec_label_qual($n)
{
    if ($n === 1) return 'Meilleure qualification';
    return $n . 'e meilleure qualification';
}

/**
 * Nom courant d'un tour de duels, depuis GrPhase (0 = finale, 1 = bronze,
 * 2 = demi-finales, 4 = quarts, puis 8, 16, 32…). Le mot compte : un message
 * qui dit « en phase 4 » n'apprend rien à un arbitre, « en quarts de finale » si.
 */
function selec_label_phase($phase)
{
    $phase = intval($phase);
    if ($phase === 0) return 'en finale';
    if ($phase === 1) return 'au match pour la 3e place';
    if ($phase === 2) return 'en demi-finales';
    if ($phase === 4) return 'en quarts de finale';
    return 'en 1/' . $phase . ' de finale';
}

/** Construit la cascade complète d'une étape : valeur principale + départages. */
function selec_cascade(&$ctx, $stepId, $principal, $departage)
{
    $out = array();
    if ($principal) {
        $cr = selec_critere($ctx, $principal, $stepId);
        if ($cr) $out[] = $cr;
    }
    foreach ((array) $departage as $d) {
        $cr = selec_critere($ctx, $d, $stepId);
        if ($cr) $out[] = $cr;
        if ($cr && $cr['id'] === 'egalite') break;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Application d'un classement à une étape
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Range les archers, applique le barème et remplit `lignes`.
 * `$valeurFn` fournit la valeur affichable/stockée (fraction) ; `$bareme` peut
 * être null (étape de classement pur, sans points).
 */
function selec_appliquer(&$ctx, $stepId, $ids, $cascade, $bareme, $detailFn = null, $valeurCritere = null)
{
    $ordre = selec_ranger($ids, $cascade);
    $lignes = array();
    $labels = array();
    foreach ($cascade as $c) $labels[$c['id']] = $c['label'];
    if ($valeurCritere === null && $cascade) $valeurCritere = $cascade[0]['id'];

    foreach ($ordre as $o) {
        $id = $o['id'];
        $v  = isset($o['valeurs'][$valeurCritere]) ? $o['valeurs'][$valeurCritere] : null;
        list($num, $den) = selec_v_frac_parts($v);

        // Le barème « valeur » se lit sur la valeur classée (ex. nombre de
        // victoires en poule), le barème « rang » sur le rang.
        $ptsC = $bareme ? selec_bareme_points($bareme, $o['rang'], $num) : 0;

        $detail = $detailFn ? call_user_func($detailFn, $id, $o) : array();
        $detail['_criteres'] = array();
        foreach ($o['valeurs'] as $cid => $val) {
            if ($val === null) continue;
            list($n2, $d2) = selec_v_frac_parts($val);
            $detail['_criteres'][$cid] = array(
                'label' => isset($labels[$cid]) ? $labels[$cid] : $cid,
                'num' => $n2, 'den' => $d2,
            );
        }

        $lignes[$id] = array(
            'rang'     => $o['rang'],
            'points_c' => $ptsC,
            'num'      => $num,
            'den'      => $den,
            'tie'      => $o['tie'] === '' ? '' : (isset($labels[$o['tie']]) ? $labels[$o['tie']] : $o['tie']),
            'exaequo'  => $o['exaequo'] ? 1 : 0,
            'retenu'   => 1,
            'detail'   => $detail,
        );
    }
    return $lignes;
}

// ─────────────────────────────────────────────────────────────────────────────
// Briques
// ─────────────────────────────────────────────────────────────────────────────

/** Brique « qualification » : un round de N distances, classé au score. */
function selec_brique_qualification(&$ctx, $st)
{
    $sid = $st['id'];
    $dists = isset($st['distances']) ? (array) $st['distances'] : array();
    $contrib = array();
    $tire = array();

    // Une étape gelée est lue dans sa copie figée, jamais dans Qualifications.
    $gele = !empty($ctx['gel'][$sid]);

    foreach ($ctx['archers'] as $id => $a) {
        $t = selec_contrib_vide();
        $aTire = false;

        if ($gele && isset($ctx['archives'][$sid][$id])) {
            $arch = $ctx['archives'][$sid][$id];
            foreach ($dists as $d) {
                $d = intval($d);
                if (empty($arch['distances'][$d])) continue;
                $g = $arch['distances'][$d];
                $t['score']   += intval($g['score']);
                $t['gold']    += intval($g['gold']);
                $t['x']       += intval($g['x']);
                $t['fleches'] += intval($g['fleches']) > 0
                    ? intval($g['fleches'])
                    : (intval($g['score']) > 0 ? $ctx['tournoi']['fleches_dist'] : 0);
                if (intval($g['score']) > 0 || intval($g['fleches']) > 0) $aTire = true;
            }
            $contrib[$id] = $t;
            $tire[$id] = $aTire;
            continue;
        }
        if ($gele) {
            $ctx['alertes'][] = "Étape $sid gelée, mais " . selec_nom($ctx, $id)
                . " n'y figure pas : ses scores sont lus en base. Re-geler l'étape "
                . "pour l'inclure.";
        }

        foreach ($dists as $d) {
            $d = intval($d);
            if (empty($ctx['quals'][$id][$d])) continue;
            $q = $ctx['quals'][$id][$d];
            $t['score']   += $q['score'];
            $t['gold']    += $q['gold'];
            $t['x']       += $q['x'];
            $t['fleches'] += $q['fleches'];
            if ($q['tire']) $aTire = true;
            if ($q['controle'] !== '') {
                $ctx['alertes'][] = "Qualification $sid, distance $d, archer "
                    . selec_nom($ctx, $id) . " : les flèches ne recalculent pas "
                    . "les totaux ianseo (" . $q['controle'] . ").";
            }
        }
        $contrib[$id] = $t;
        $tire[$id] = $aTire;
    }
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => $contrib, 'lignes' => array(),
        'gele' => $gele);

    $cascade = selec_cascade($ctx, $sid,
        array('c' => 'score', 'quals' => array($sid)),
        isset($st['departage']) ? $st['departage'] : array(
            array('c' => 'x', 'quals' => array($sid)),
            array('c' => 'dix', 'quals' => array($sid)),
            array('c' => 'egalite')));

    $ids = array_keys($ctx['archers']);
    $lignes = selec_appliquer($ctx, $sid, $ids, $cascade, selec_bareme($ctx, isset($st['bareme']) ? $st['bareme'] : ''),
        function ($id, $o) use ($contrib, $tire, $dists) {
            $t = $contrib[$id];
            return array(
                'distances' => $dists,
                'score' => $t['score'], 'dix' => $t['gold'], 'x' => $t['x'],
                'fleches' => $t['fleches'], 'tire' => $tire[$id] ? 1 : 0,
            );
        });

    // Un archer qui n'a rien tiré ne « gagne » pas de points par défaut.
    foreach ($lignes as $id => &$l) {
        if (empty($tire[$id])) { $l['points_c'] = 0; $l['retenu'] = 0; }
    }
    unset($l);

    $ctx['etapes'][$sid]['lignes'] = $lignes;
}

/** Brique « duels simulés » : M duels tirés pour le score, jamais pour la victoire. */
function selec_brique_duels_simules(&$ctx, $st)
{
    $sid = $st['id'];
    $src = isset($st['source']) ? $st['source'] : array('type' => 'distances', 'distances' => array());
    $contrib = array();
    $detailSup = array();

    if (($src['type'] ?? '') === 'distances') {
        $dists = (array) ($src['distances'] ?? array());
        $gele = !empty($ctx['gel'][$sid]);
        foreach ($ctx['archers'] as $id => $a) {
            $t = selec_contrib_vide();
            foreach ($dists as $d) {
                $d = intval($d);
                // Même règle que les qualifications : une étape gelée se lit
                // dans sa copie figée.
                if ($gele && isset($ctx['archives'][$sid][$id]['distances'][$d])) {
                    $g = $ctx['archives'][$sid][$id]['distances'][$d];
                    $t['score']   += intval($g['score']);
                    $t['gold']    += intval($g['gold']);
                    $t['x']       += intval($g['x']);
                    $t['fleches'] += intval($g['fleches']);
                    continue;
                }
                if (empty($ctx['quals'][$id][$d])) continue;
                $q = $ctx['quals'][$id][$d];
                $t['score']   += $q['score'];
                $t['gold']    += $q['gold'];
                $t['x']       += $q['x'];
                $t['fleches'] += $q['fleches'];
            }
            $contrib[$id] = $t;
            $detailSup[$id] = array('distances' => $dists);
        }
    } else {
        // Duels réellement joués dans une ou plusieurs épreuves ianseo : on ne
        // retient que le SCORE (le règlement classe les duels simulés à la somme
        // des scores, la victoire n'y compte pas).
        $events = selec_bind_events($ctx, $sid, isset($src['slots']) ? $src['slots'] : array('principal'));
        $matchs = $events ? selec_matchs($ctx['tour'], $events) : array();
        $poules = $events ? selec_matchs_poule($ctx['tour'], $events) : array();
        // Le règlement départage les duels simulés aux X puis aux 10. ianseo ne
        // totalise ni les uns ni les autres sur un duel — ses compteurs
        // n'existent que pour les qualifications —, donc on les relit de la
        // chaîne de flèches. Sans chaîne (scores saisis volée par volée plutôt
        // que flèche par flèche), le départage n'a rien à comparer : il faut le
        // dire, pas laisser deux archers ex aequo par défaut d'information.
        $besoinFleches = false;
        foreach ((array) ($st['departage'] ?? array()) as $dp) {
            if (in_array((isset($dp['c']) ? $dp['c'] : ''), array('x', 'dix'), true)) $besoinFleches = true;
        }
        $sansFleches = array();

        foreach ($ctx['archers'] as $id => $a) {
            $t = selec_contrib_vide();
            $n = 0;
            foreach (array($matchs, $poules) as $lot) {
                if (empty($lot[$id])) continue;
                foreach ($lot[$id] as $m) {
                    // Un duel simulé se joue sur UN tour : les tours suivants du
                    // tableau existent (ianseo y propage les vainqueurs) mais ne
                    // se tirent pas. Les compter ferait grimper le nombre de
                    // duels sans qu'aucune flèche n'ait été lâchée.
                    if (!$m['set_nb'] && !$m['score'] && !$m['fleches']) continue;
                    $t['score']      += $m['set_total'];
                    $t['set_total']  += $m['set_total'];
                    $t['sets']       += $m['set_nb'];
                    $t['fleches']    += $m['fleches'];
                    $t['points_set'] += $m['points_set'];
                    $t['gold']       += intval($m['gold'] ?? 0);
                    $t['x']          += intval($m['x'] ?? 0);
                    $t['matchs']++;
                    $n++;
                    if ($besoinFleches && empty($m['fleches_completes'])) {
                        $sansFleches[$m['event'] . '#' . $m['matchno']] = true;
                    }
                }
            }
            $contrib[$id] = $t;
            $detailSup[$id] = array('duels' => $n, 'epreuves' => $events);
        }

        if ($sansFleches) {
            $ctx['alertes'][] = "Étape $sid : " . count($sansFleches) . " duel(s) dont le détail "
                . "flèche par flèche est absent ou incomplet — les 10 et les X n'y sont pas "
                . "comptables, et le départage aux X puis aux 10 restera sans effet pour ces "
                . "archers. Saisir les flèches (tablette ISK-NG, ou saisie flèche par flèche dans "
                . "ianseo) rend le départage possible.";
        }
        if (!$events) {
            $ctx['alertes'][] = "Étape $sid : aucune épreuve ianseo rattachée — duels simulés non calculés.";
        }
    }

    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => $contrib, 'lignes' => array());

    $cascade = selec_cascade($ctx, $sid,
        array('c' => 'score', 'quals' => array($sid)),
        isset($st['departage']) ? $st['departage'] : array(
            array('c' => 'x', 'quals' => array($sid)),
            array('c' => 'dix', 'quals' => array($sid)),
            array('c' => 'egalite')));

    $ids = selec_ids_actifs($ctx, $st);
    $ctx['etapes'][$sid]['lignes'] = selec_appliquer($ctx, $sid, $ids, $cascade,
        selec_bareme($ctx, isset($st['bareme']) ? $st['bareme'] : ''),
        function ($id, $o) use ($contrib, $detailSup) {
            $t = $contrib[$id];
            return array_merge(isset($detailSup[$id]) ? $detailSup[$id] : array(), array(
                'score' => $t['score'], 'dix' => $t['gold'], 'x' => $t['x'],
                'fleches' => $t['fleches'], 'sets' => $t['sets'],
            ));
        });
}

/**
 * Brique « tournoi » : tableau montante/descendante.
 *
 * Trois classements successifs, exactement comme le règlement :
 *   1. classement final du tableau  → Points de Classement
 *   2. classement à la moyenne de set → Points de Performance
 *   3. classement à la SOMME des deux → Points de Tournoi (barème final)
 * L'égalité à l'étape 3 est conservée (mêmes points), conformément au §3.2.
 */
function selec_brique_tournoi(&$ctx, $st)
{
    $sid    = $st['id'];
    $slots  = isset($st['slots']) ? (array) $st['slots'] : array('principal', 'consolante');
    $events = selec_bind_events($ctx, $sid, $slots);

    $contrib = array();
    $places  = array();

    if (!$events) {
        $ctx['alertes'][] = "Étape $sid : aucune épreuve ianseo rattachée — tournoi non calculé.";
        $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => array(), 'lignes' => array());
        return;
    }

    $matchs = selec_matchs($ctx['tour'], $events);
    $sansPlace = array();
    foreach ($events as $ev) {
        $cl = selec_classement_tableau($ctx['tour'], $ev);
        foreach ($cl['rangs'] as $id => $r) $places[$id] = $r;
        foreach ($cl['incomplet'] as $msg) $ctx['alertes'][] = "Étape $sid : $msg";
        foreach ($cl['sans_place'] as $id => $ph) {
            if (!isset($sansPlace[$id])) $sansPlace[$id] = array('phase' => $ph, 'event' => $ev);
        }
    }

    foreach (selec_places_manquantes($places, $sansPlace) as $id => $info) {
        $ctx['alertes'][] = "Étape $sid : " . selec_nom($ctx, $id) . " reste sans place — éliminé "
            . selec_label_phase($info['phase']) . " de " . $info['event'] . ", et aucune épreuve "
            . "de cette étape ne classe les places suivantes. Il manque une consolante, ou elle "
            . "n'est pas rattachée à l'étape.";
    }

    // Participants = les archers de la catégorie qui ont au moins un match.
    $ids = array();
    foreach ($ctx['archers'] as $id => $a) {
        if (!empty($matchs[$id])) $ids[] = $id;
    }

    foreach ($ids as $id) {
        $t = selec_contrib_vide();
        foreach ($matchs[$id] as $m) {
            $t['score']      += $m['set_total'];
            $t['set_total']  += $m['set_total'];
            $t['sets']       += $m['set_nb'];
            $t['fleches']    += $m['fleches'];
            $t['points_set'] += $m['points_set'];
            $t['matchs']++;
            if ($m['gagne'] === 1) {
                $t['victoires']++;
                // « Victoire finale » = victoire dans le match de finale du tableau
                // (GrPhase 0), y compris la finale d'une consolante.
                if ($m['phase'] === 0) $t['victoires_finales']++;
            }
            if ($m['score'] !== $m['set_total'] && $m['score'] > 0) {
                $ctx['alertes'][] = "Étape $sid, " . selec_nom($ctx, $id)
                    . " : le total du match (" . $m['score'] . ") ne correspond pas à la somme "
                    . "des sets (" . $m['set_total'] . ") — match " . $m['event'] . '#' . $m['matchno'] . '.';
            }
        }
        $contrib[$id] = $t;
    }
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => $contrib, 'lignes' => array(),
        'places' => $places, 'events' => $events);

    selec_tournoi_points($ctx, $st, $ids, $places, $events);
}

/**
 * Archers réellement sans place, une fois TOUTES les épreuves de l'étape lues.
 *
 * Un perdant de quart n'a pas de place dans le tableau principal : c'est la
 * consolante qui la lui donne. Signaler épreuve par épreuve déclencherait
 * l'alerte sur un tournoi parfaitement complet — et une alerte qui crie à tort
 * use la confiance qu'on accorde aux vraies. D'où cette fonction pure, appelée
 * après la fusion et testable sans base.
 *
 * @param array $places    [EnId => rang] fusionné sur toutes les épreuves
 * @param array $sansPlace [EnId => ['phase'=>int, 'event'=>string]]
 * @return array le sous-ensemble de $sansPlace qui n'a reçu aucun rang
 */
function selec_places_manquantes($places, $sansPlace)
{
    $out = array();
    foreach ((array) $sansPlace as $id => $info) {
        if (isset($places[$id])) continue;
        $out[$id] = $info;
    }
    return $out;
}

/**
 * Partie PURE du tournoi : les trois classements successifs, sans aucune lecture
 * de base. Séparée pour être rejouable telle quelle sur des données de référence
 * (voir tests/) — c'est ici que vit la justesse des points, il faut pouvoir la
 * confronter au tableur DTN sans monter une compétition.
 */
function selec_tournoi_points(&$ctx, $st, $ids, $places, $events = array())
{
    $sid = $st['id'];
    $contrib = isset($ctx['etapes'][$sid]['contrib']) ? $ctx['etapes'][$sid]['contrib'] : array();

    // 1) Points de Classement du tournoi.
    $bClt = selec_bareme($ctx, isset($st['bareme_classement']) ? $st['bareme_classement'] : '');
    $ptsClt = array();
    foreach ($ids as $id) {
        $r = isset($places[$id]) ? $places[$id] : 0;
        $ptsClt[$id] = $r > 0 ? selec_bareme_points($bClt, $r) : 0;
        if ($r <= 0) {
            $ctx['alertes'][] = "Étape $sid : place finale inconnue pour "
                . selec_nom($ctx, $id) . " — 0 point de classement attribué.";
        }
    }

    // 2) Points de Performance, sur la moyenne de set du tournoi.
    $cascPerf = selec_cascade($ctx, $sid,
        array('c' => 'moyenne_set', 'etapes' => array($sid)),
        isset($st['departage_performance']) ? $st['departage_performance'] : array(array('c' => 'egalite')));
    $rangPerf = selec_ranger($ids, $cascPerf);
    $bPerf = selec_bareme($ctx, isset($st['bareme_performance']) ? $st['bareme_performance'] : '');
    $ptsPerf = array(); $rgPerf = array();
    foreach ($rangPerf as $o) {
        $ptsPerf[$o['id']] = selec_bareme_points($bPerf, $o['rang']);
        $rgPerf[$o['id']]  = $o['rang'];
    }

    // 3) Somme des deux composantes → nouveau classement → barème final.
    $somme = array();
    foreach ($ids as $id) $somme[$id] = $ptsClt[$id] + (isset($ptsPerf[$id]) ? $ptsPerf[$id] : 0);

    $cascade = array(
        array('id' => 'somme', 'label' => 'Classement + Performance',
              'fn' => function ($id) use ($somme) { return selec_v_int($somme[$id]); }),
    );
    foreach (isset($st['departage']) ? $st['departage'] : array(array('c' => 'egalite')) as $d) {
        $cr = selec_critere($ctx, $d, $sid);
        if ($cr) $cascade[] = $cr;
        if ($cr && $cr['id'] === 'egalite') break;
    }

    $lignes = selec_appliquer($ctx, $sid, $ids, $cascade,
        selec_bareme($ctx, isset($st['bareme']) ? $st['bareme'] : ''),
        function ($id, $o) use ($contrib, $places, $ptsClt, $ptsPerf, $rgPerf, $somme, $events) {
            $t = $contrib[$id];
            return array(
                'epreuves'    => $events,
                'place'       => isset($places[$id]) ? $places[$id] : 0,
                'pts_clt'     => $ptsClt[$id],
                'rang_perf'   => isset($rgPerf[$id]) ? $rgPerf[$id] : 0,
                'pts_perf'    => isset($ptsPerf[$id]) ? $ptsPerf[$id] : 0,
                'somme'       => $somme[$id],
                'sets'        => $t['sets'],
                'set_total'   => $t['set_total'],
                'victoires'   => $t['victoires'],
                'fleches'     => $t['fleches'],
                'matchs'      => $t['matchs'],
            );
        }, 'somme');

    $ctx['etapes'][$sid]['lignes'] = $lignes;
}

/**
 * Brique « poule » : tous contre tous.
 *
 * Points de Classement attribués soit DIRECTEMENT par nombre de victoires
 * (barème de type `valeur` — c'est le règlement 2027 : 5 victoires → 6 points,
 * 4 → 5 … 0 → 1, deux archers à 3 victoires ont donc tous deux 4 points), soit
 * par rang si le mode déclare un barème de type `rang`. Puis Performance sur la
 * moyenne de set, somme, reclassement, barème final.
 */
function selec_brique_poule(&$ctx, $st)
{
    $sid    = $st['id'];
    $slots  = isset($st['slots']) ? (array) $st['slots'] : array('poule');
    $events = selec_bind_events($ctx, $sid, $slots);

    if (!$events) {
        $ctx['alertes'][] = "Étape $sid : aucune épreuve ianseo rattachée — poule non calculée.";
        $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => array(), 'lignes' => array());
        return;
    }

    $matchs = selec_matchs_poule($ctx['tour'], $events);
    if (!$matchs) $matchs = selec_matchs($ctx['tour'], $events); // poule jouée en tableau

    $ids = array();
    foreach ($ctx['archers'] as $id => $a) if (!empty($matchs[$id])) $ids[] = $id;

    $contrib = array();
    foreach ($ids as $id) {
        $t = selec_contrib_vide();
        foreach ($matchs[$id] as $m) {
            $t['score']      += $m['set_total'];
            $t['set_total']  += $m['set_total'];
            $t['sets']       += $m['set_nb'];
            $t['fleches']    += $m['fleches'];
            $t['points_set'] += $m['points_set'];
            $t['matchs']++;
            if ($m['gagne'] === 1) $t['victoires']++;
        }
        $contrib[$id] = $t;
    }
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => $contrib, 'lignes' => array(), 'events' => $events);

    selec_poule_points($ctx, $st, $ids, $events);
}

/**
 * Partie PURE de la poule (voir selec_tournoi_points pour la raison de cette
 * séparation : les points doivent être rejouables sans base).
 */
function selec_poule_points(&$ctx, $st, $ids, $events = array())
{
    $sid = $st['id'];
    $contrib = isset($ctx['etapes'][$sid]['contrib']) ? $ctx['etapes'][$sid]['contrib'] : array();

    // 1) Points de Classement de la poule.
    $bClt = selec_bareme($ctx, isset($st['bareme_classement']) ? $st['bareme_classement'] : '');
    $ptsClt = array(); $rgClt = array();
    if ($bClt && ($bClt['type'] ?? 'rang') === 'valeur') {
        foreach ($ids as $id) $ptsClt[$id] = selec_bareme_points($bClt, 0, $contrib[$id]['victoires']);
        // Rang indicatif, uniquement pour l'affichage.
        $casc = selec_cascade($ctx, $sid,
            array('c' => 'victoires', 'etapes' => array($sid)),
            isset($st['departage_classement']) ? $st['departage_classement'] : array(array('c' => 'egalite')));
        foreach (selec_ranger($ids, $casc) as $o) $rgClt[$o['id']] = $o['rang'];
    } else {
        $casc = selec_cascade($ctx, $sid,
            array('c' => 'victoires', 'etapes' => array($sid)),
            isset($st['departage_classement']) ? $st['departage_classement'] : array(array('c' => 'egalite')));
        foreach (selec_ranger($ids, $casc) as $o) {
            $rgClt[$o['id']]  = $o['rang'];
            $ptsClt[$o['id']] = selec_bareme_points($bClt, $o['rang']);
        }
    }

    // 2) Performance sur la moyenne de set.
    $cascPerf = selec_cascade($ctx, $sid,
        array('c' => 'moyenne_set', 'etapes' => array($sid)),
        isset($st['departage_performance']) ? $st['departage_performance'] : array(array('c' => 'egalite')));
    $bPerf = selec_bareme($ctx, isset($st['bareme_performance']) ? $st['bareme_performance'] : '');
    $ptsPerf = array(); $rgPerf = array();
    foreach (selec_ranger($ids, $cascPerf) as $o) {
        $ptsPerf[$o['id']] = selec_bareme_points($bPerf, $o['rang']);
        $rgPerf[$o['id']]  = $o['rang'];
    }

    // 3) Somme → classement → barème final.
    $somme = array();
    foreach ($ids as $id) $somme[$id] = $ptsClt[$id] + (isset($ptsPerf[$id]) ? $ptsPerf[$id] : 0);

    $cascade = array(array('id' => 'somme', 'label' => 'Classement + Performance',
        'fn' => function ($id) use ($somme) { return selec_v_int($somme[$id]); }));
    foreach (isset($st['departage']) ? $st['departage'] : array(array('c' => 'egalite')) as $d) {
        $cr = selec_critere($ctx, $d, $sid);
        if ($cr) $cascade[] = $cr;
        if ($cr && $cr['id'] === 'egalite') break;
    }

    $ctx['etapes'][$sid]['lignes'] = selec_appliquer($ctx, $sid, $ids, $cascade,
        selec_bareme($ctx, isset($st['bareme']) ? $st['bareme'] : ''),
        function ($id, $o) use ($contrib, $ptsClt, $rgClt, $ptsPerf, $rgPerf, $somme, $events) {
            $t = $contrib[$id];
            return array(
                'epreuves'  => $events,
                'victoires' => $t['victoires'],
                'rang_clt'  => isset($rgClt[$id]) ? $rgClt[$id] : 0,
                'pts_clt'   => $ptsClt[$id],
                'rang_perf' => isset($rgPerf[$id]) ? $rgPerf[$id] : 0,
                'pts_perf'  => isset($ptsPerf[$id]) ? $ptsPerf[$id] : 0,
                'somme'     => $somme[$id],
                'sets'      => $t['sets'], 'set_total' => $t['set_total'],
                'fleches'   => $t['fleches'], 'matchs' => $t['matchs'],
            );
        }, 'somme');
}

/** Brique « journée » : cumul des points du jour, reclassé, nouveau barème. */
function selec_brique_journee(&$ctx, $st)
{
    $sid = $st['id'];
    $sources = isset($st['sources']) ? (array) $st['sources'] : array();
    // Une journée ne contribue RIEN : ses composantes sont déjà comptées.
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => array(), 'lignes' => array());

    $cascade = selec_cascade($ctx, $sid,
        array('c' => 'points', 'sources' => $sources),
        isset($st['departage']) ? $st['departage'] : array(array('c' => 'egalite')));

    $ids = selec_ids_actifs($ctx, $st);
    $ctx['etapes'][$sid]['lignes'] = selec_appliquer($ctx, $sid, $ids, $cascade,
        selec_bareme($ctx, isset($st['bareme']) ? $st['bareme'] : ''),
        function ($id, $o) use (&$ctx, $sources) {
            $d = array('sources' => $sources, 'par_source' => array());
            foreach ($sources as $s) {
                $d['par_source'][$s] = isset($ctx['etapes'][$s]['lignes'][$id]['points_c'])
                    ? intval($ctx['etapes'][$s]['lignes'][$id]['points_c']) : 0;
            }
            $d['total'] = array_sum($d['par_source']);
            return $d;
        });
}

/** Brique « coupure » : classement intermédiaire, N archers retenus pour la suite. */
function selec_brique_coupure(&$ctx, $st)
{
    $sid = $st['id'];
    $sources = isset($st['sources']) ? (array) $st['sources'] : array();
    $n = intval(isset($st['retenus']) ? $st['retenus'] : 0);
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => array(), 'lignes' => array());

    $cascade = selec_cascade($ctx, $sid,
        array('c' => 'points', 'sources' => $sources),
        isset($st['departage']) ? $st['departage'] : array(array('c' => 'egalite')));

    $ids = selec_ids_actifs($ctx, $st);
    $lignes = selec_appliquer($ctx, $sid, $ids, $cascade, null,
        function ($id, $o) use (&$ctx, $sources) {
            $d = array('sources' => $sources, 'par_source' => array());
            foreach ($sources as $s) {
                $d['par_source'][$s] = isset($ctx['etapes'][$s]['lignes'][$id]['points_c'])
                    ? intval($ctx['etapes'][$s]['lignes'][$id]['points_c']) : 0;
            }
            $d['total'] = array_sum($d['par_source']);
            return $d;
        });

    // Retenus = les N premiers rangs. Une égalité SUR la barre laisse plus de N
    // archers retenus et le signale : c'est exactement le cas où le règlement
    // impose un tir de barrage, il ne doit surtout pas être tranché en silence.
    $surLaBarre = array();
    foreach ($lignes as $id => &$l) {
        $l['retenu'] = ($n > 0 && $l['rang'] <= $n) ? 1 : 0;
        if ($n > 0 && $l['rang'] === $n && $l['exaequo']) $surLaBarre[] = $id;
    }
    unset($l);

    // Une égalité sur la barre laisse le classement indéterminé à cet endroit :
    // c'est un fait de classement, pas une décision de sélection. Le module le
    // signale et n'en tire aucune conclusion.
    if (count($surLaBarre) > 1) {
        $noms = array();
        foreach ($surLaBarre as $id) $noms[] = selec_nom($ctx, $id);
        $ctx['alertes'][] = "Étape $sid : " . count($surLaBarre) . " archers ex aequo au "
            . $n . "e rang (" . implode(', ', $noms) . ") — le règlement prévoit un tir de "
            . "barrage pour départager ce rang.";
    }

    $ctx['etapes'][$sid]['lignes'] = $lignes;
    $ctx['coupure'] = $sid;
}

/**
 * A-t-il pris part à AU MOINS UNE étape postérieure à la coupure ?
 *
 * Sert au gel du classement final : un archer écarté à la coupure ne tire plus
 * rien ensuite, son classement ne peut donc plus évoluer. Le test porte sur les
 * étapes qui viennent APRÈS la coupure dans le règlement, quel que soit leur
 * type — l'épreuve 2 enchaîne une qualification (Q3) après sa coupure, pas
 * seulement des duels. Critère FACTUEL — a-t-il tiré — jamais « a-t-il été
 * retenu » : le module classe, il ne sélectionne pas.
 */
function selec_a_participe_apres($ctx, $coupureId, $id)
{
    $apres = false;
    foreach ((array) ($ctx['mode']['etapes'] ?? array()) as $s) {
        if ($s['id'] === $coupureId) { $apres = true; continue; }
        if (!$apres) continue;
        if (!in_array($s['type'], array('qualification', 'tournoi', 'poule', 'duels_simules'), true)) continue;
        if (empty($ctx['etapes'][$s['id']]['contrib'][$id])) continue;
        $c = $ctx['etapes'][$s['id']]['contrib'][$id];
        if (intval($c['matchs'] ?? 0) > 0 || intval($c['fleches'] ?? 0) > 0) return true;
    }
    return false;
}

/** Brique « final » : classement de l'épreuve sur le cumul des journées. */
function selec_brique_final(&$ctx, $st)
{
    $sid = $st['id'];
    $sources = isset($st['sources']) ? (array) $st['sources'] : array();
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => array(), 'lignes' => array());

    $cascade = selec_cascade($ctx, $sid,
        array('c' => 'points', 'sources' => $sources),
        isset($st['departage']) ? $st['departage'] : array(array('c' => 'egalite')));

    // Périmètre : tous les archers de la catégorie par défaut. Ceux écartés par
    // une coupure ont 0 point sur les journées suivantes et se rangent donc
    // naturellement derrière, sans qu'aucun cas particulier soit nécessaire.
    $ids = (isset($st['perimetre']) && $st['perimetre'] === 'retenus')
        ? selec_ids_actifs($ctx, array('perimetre' => 'retenus'))
        : array_keys($ctx['archers']);

    $detailFn = function ($id, $o) use (&$ctx, $sources) {
        $d = array('sources' => $sources, 'par_source' => array());
        foreach ($sources as $s) {
            $d['par_source'][$s] = isset($ctx['etapes'][$s]['lignes'][$id]['points_c'])
                ? intval($ctx['etapes'][$s]['lignes'][$id]['points_c']) : 0;
        }
        $d['total'] = array_sum($d['par_source']);
        return $d;
    };

    // ── Gel du classement des archers écartés à la coupure ──────────────────
    // Ils n'ont plus rien tiré : leur classement est FIGÉ tel qu'il était à la
    // coupure, départage de la coupure compris. Les laisser passer dans la
    // cascade du classement final les réordonnerait — tous à zéro point sur la
    // suite, donc à égalité, ils seraient départagés par un critère qui n'est
    // pas celui de leur classement. Deux rangs différents pour un même archer
    // sur une sélection, c'est ce qu'on ne peut pas se permettre.
    $fige = (string) ($st['fige_apres_coupure'] ?? '');
    if ($fige !== '' && !empty($ctx['etapes'][$fige]['lignes'])) {
        return selec_final_avec_gel($ctx, $st, $sid, $ids, $cascade, $detailFn, $fige);
    }

    $lignes = selec_appliquer($ctx, $sid, $ids, $cascade, null, $detailFn);

    $q = intval(isset($st['qualifies']) ? $st['qualifies'] : 0);
    if ($q > 0) {
        $surLaBarre = array();
        foreach ($lignes as $id => &$l) {
            $l['retenu'] = ($l['rang'] <= $q) ? 1 : 0;
            if ($l['rang'] === $q && $l['exaequo']) $surLaBarre[] = $id;
        }
        unset($l);
        if (count($surLaBarre) > 1) {
            $noms = array();
            foreach ($surLaBarre as $id) $noms[] = selec_nom($ctx, $id);
            $ctx['alertes'][] = "Étape $sid : " . count($surLaBarre) . " archers ex aequo au "
                . $q . "e rang (" . implode(', ', $noms) . ") — départage à trancher.";
        }
    }

    $ctx['etapes'][$sid]['lignes'] = $lignes;
}

/**
 * Classement final en deux blocs : ceux qui ont continué, puis ceux dont le
 * classement est figé à la coupure.
 *
 *  - les premiers sont classés par la cascade du classement final, entre eux
 *    seulement — c'est ce que le règlement décrit ;
 *  - les seconds reprennent TEL QUEL l'ordre de la coupure, son départage
 *    compris, et se rangent derrière. Leur ligne porte `fige` : les impressions
 *    savent alors laisser vides les colonnes des étapes qu'ils n'ont pas
 *    disputées, plutôt que d'y afficher des zéros qui ressemblent à un résultat.
 *
 * Le rang du second bloc repart à « nombre de classés du premier bloc + 1 » —
 * jamais au rang brut de la coupure : sur un forfait, deux archers porteraient
 * le même numéro sans être ex aequo.
 */
function selec_final_avec_gel(&$ctx, $st, $sid, $ids, $cascade, $detailFn, $fige)
{
    $base = $ctx['etapes'][$fige]['lignes'];

    $avec = array(); $sans = array();
    foreach ($ids as $id) {
        if (selec_a_participe_apres($ctx, $fige, $id)) $avec[] = $id;
        elseif (isset($base[$id])) $sans[] = $id;
        else $avec[] = $id;   // absent de la coupure : rien à figer, on le classe
    }

    $lignes = $avec ? selec_appliquer($ctx, $sid, $avec, $cascade, null, $detailFn) : array();

    usort($sans, function ($a, $b) use ($base) {
        $ra = intval($base[$a]['rang']);
        $rb = intval($base[$b]['rang']);
        if ($ra !== $rb) return $ra <=> $rb;
        return $a <=> $b;   // ordre d'affichage stable entre ex aequo
    });

    $decalage = count($avec);
    $pos = 0; $rangGroupe = 0; $precRang = null;
    foreach ($sans as $id) {
        $pos++;
        $rc = intval($base[$id]['rang']);
        if ($precRang === null || $rc !== $precRang) $rangGroupe = $pos;
        $precRang = $rc;

        $d = call_user_func($detailFn, $id, null);
        $d['fige'] = $fige;
        $lignes[$id] = array(
            'rang'     => $decalage + $rangGroupe,
            'points_c' => 0,
            'num'      => intval($d['total']),
            'den'      => 1,
            'tie'      => $base[$id]['tie'],
            'exaequo'  => $base[$id]['exaequo'],
            'retenu'   => 1,
            'detail'   => $d,
        );
    }

    $ctx['etapes'][$sid]['lignes'] = $lignes;
}

/**
 * Archers concernés par une étape : tous, ou seulement ceux retenus par la
 * dernière coupure franchie. Une étape peut forcer le périmètre.
 */
function selec_ids_actifs($ctx, $st)
{
    $per = isset($st['perimetre']) ? $st['perimetre'] : 'retenus';
    if ($per === 'tous' || empty($ctx['coupure'])) return array_keys($ctx['archers']);

    $sid = $ctx['coupure'];
    $ids = array();
    foreach ($ctx['etapes'][$sid]['lignes'] as $id => $l) {
        if (!empty($l['retenu'])) $ids[] = $id;
    }
    return $ids ? $ids : array_keys($ctx['archers']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Orchestration
// ─────────────────────────────────────────────────────────────────────────────

/** Exécute toutes les étapes du mode dans l'ordre. Retourne le contexte enrichi. */
function selec_calculer($tourId, $cat, $mode, $binds = array())
{
    $ctx = selec_contexte($tourId, $cat, $mode, $binds);
    if (!$ctx['tournoi']) { $ctx['alertes'][] = 'Compétition introuvable.'; return $ctx; }

    $etapes = isset($mode['etapes']) ? $mode['etapes'] : array();
    foreach ($etapes as $st) {
        if (empty($st['id']) || empty($st['type'])) {
            $ctx['alertes'][] = 'Étape sans identifiant ou sans type — ignorée.';
            continue;
        }
        switch ($st['type']) {
            case 'qualification':  selec_brique_qualification($ctx, $st);  break;
            case 'duels_simules':  selec_brique_duels_simules($ctx, $st);  break;
            case 'tournoi':        selec_brique_tournoi($ctx, $st);        break;
            case 'poule':          selec_brique_poule($ctx, $st);          break;
            case 'journee':        selec_brique_journee($ctx, $st);        break;
            case 'coupure':        selec_brique_coupure($ctx, $st);        break;
            case 'final':          selec_brique_final($ctx, $st);          break;
            default:
                $ctx['alertes'][] = "Étape {$st['id']} : type inconnu « {$st['type']} » — ignorée.";
        }
    }
    return $ctx;
}

/** Enregistre les résultats calculés (remplace intégralement ceux de la catégorie). */
function selec_enregistrer($ctx)
{
    $tour = intval($ctx['tour']);
    $cat  = $ctx['cat'];
    $now  = date('Y-m-d H:i:s');

    safe_w_sql("DELETE FROM SELEC_Results
        WHERE SrTournament=$tour AND SrCategory=" . StrSafe_DB($cat));

    $n = 0;
    foreach ($ctx['etapes'] as $sid => $et) {
        foreach ($et['lignes'] as $id => $l) {
            $val = $l['den'] ? $l['num'] / $l['den'] : 0;
            safe_w_sql("INSERT INTO SELEC_Results SET
                SrTournament=$tour,
                SrCategory=" . StrSafe_DB($cat) . ",
                SrStep=" . StrSafe_DB($sid) . ",
                SrEntry=" . intval($id) . ",
                SrRank=" . intval($l['rang']) . ",
                SrPointsC=" . intval($l['points_c']) . ",
                SrValue=" . StrSafe_DB(number_format($val, 6, '.', '')) . ",
                SrValueNum=" . intval($l['num']) . ",
                SrValueDen=" . intval($l['den'] ? $l['den'] : 1) . ",
                SrTie=" . StrSafe_DB(mb_substr((string) $l['tie'], 0, 80)) . ",
                SrExAequo=" . intval($l['exaequo']) . ",
                SrRetenu=" . intval($l['retenu']) . ",
                SrDetail=" . StrSafe_DB(json_encode($l['detail'], JSON_UNESCAPED_UNICODE)) . ",
                SrUpdated=" . StrSafe_DB($now));
            $n++;
        }
    }
    selec_log($tour, 'calcul', array('lignes' => $n, 'alertes' => count($ctx['alertes'])), $cat);
    return $n;
}
