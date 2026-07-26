<?php
/**
 * lib/controles.php — les contrôles exécutés avant toute affectation.
 *
 * Quatre contrôles sont bloquants : ils portent sur la cohérence, dont le module
 * est responsable. Le cinquième — le règlement fédéral — est un avertissement :
 * le module place par lots et ne permet pas la retouche archer par archer, la
 * correction se fait ensuite dans ianseo.
 */

require_once __DIR__ . '/moteur.php';

define('REP_OK', 'ok');
define('REP_WARN', 'warn');
define('REP_STOP', 'stop');

function rep_controles($tourId)
{
    $tourId  = intval($tourId);
    $plan    = rep_placer_tout($tourId);
    $blocs   = $plan['blocs'];
    $out     = [];
    $blocsKo = [];

    // ── 1. Un archer, une seule place ────────────────────────────────────────
    // Deux blocs d'une même épreuve ne doivent pas prélever les mêmes positions
    // dans la liste ordonnée des archers.
    $parEpreuve = [];
    foreach ($blocs as $b) $parEpreuve[$b['cle']][] = $b;
    $conflits = [];
    foreach ($parEpreuve as $cle => $liste) {
        $n = count($liste);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a1 = $liste[$i]['depuis']; $z1 = $a1 + rep_places($liste[$i]) - 1;
                $a2 = $liste[$j]['depuis']; $z2 = $a2 + rep_places($liste[$j]) - 1;
                if (!($z1 < $a2 || $a1 > $z2)) {
                    $conflits[$cle] = true;
                    $blocsKo[$liste[$i]['id']] = true;
                    $blocsKo[$liste[$j]['id']] = true;
                }
            }
        }
    }
    $out[] = [
        'id'    => 'rangs',
        'sev'   => $conflits ? REP_STOP : REP_OK,
        'titre' => 'Un archer, une seule place',
        'nb'    => $conflits ? (count($conflits) . ' épreuve(s)') : '0 conflit',
        'detail' => $conflits
            ? 'Blocs qui réclament les mêmes archers : ' . implode(', ', array_keys($conflits))
              . '. Corrigez la colonne « Depuis n° ».'
            : "Aucune position n'est réclamée par deux blocs d'une même épreuve.",
    ];

    // ── 2. Une cible-lettre, un seul archer ──────────────────────────────────
    $geo = [];
    foreach ($blocs as $b1) {
        foreach ($blocs as $b2) {
            if ($b1['id'] >= $b2['id'] || $b1['session'] != $b2['session']) continue;
            if (!($b1['t2'] < $b2['t1'] || $b1['t1'] > $b2['t2']
               || $b1['l2'] < $b2['l1'] || $b1['l1'] > $b2['l2'])) {
                $geo[] = 'départ ' . $b1['session'] . ' : ' . $b1['cle'] . ' et ' . $b2['cle'];
                $blocsKo[$b1['id']] = true;
                $blocsKo[$b2['id']] = true;
            }
        }
    }
    $out[] = [
        'id'    => 'geometrie',
        'sev'   => $geo ? REP_STOP : REP_OK,
        'titre' => 'Une cible-lettre, un seul archer',
        'nb'    => $geo ? (count($geo) . ' chevauchement(s)') : '0 conflit',
        'detail' => $geo
            ? implode(' · ', array_slice($geo, 0, 5))
            : "Aucun chevauchement géométrique : l'éditeur le refuse déjà à la souris.",
    ];

    // ── 3. Un archer ne tire qu'une fois par départ ──────────────────────────
    // Règle fédérale, et cas réel dans la base : une même licence peut porter
    // plusieurs inscriptions, y compris avec la même arme et la même catégorie.
    $parDepart = [];
    foreach ($plan['affectations'] as $a) {
        $parDepart[$a['session']][$a['licence']][] = $a;
    }
    $doublons = [];
    foreach ($parDepart as $ses => $parLic) {
        foreach ($parLic as $lic => $lignes) {
            if (count($lignes) < 2) continue;
            $eps = [];
            foreach ($lignes as $l) $eps[] = $l['division'] . ' ' . $l['class'];
            $doublons[] = [
                'session'  => $ses,
                'nom'      => $lignes[0]['nom'],
                'licence'  => $lic,
                'club'     => $lignes[0]['club'],
                'epreuves' => array_values(array_unique($eps)),
            ];
        }
    }
    $txt = [];
    foreach (array_slice($doublons, 0, 6) as $d) {
        $txt[] = $d['nom'] . ' (licence ' . $d['licence'] . ($d['club'] !== '' ? ', ' . $d['club'] : '')
               . ') — départ ' . $d['session'] . ' : ' . implode(' et ', $d['epreuves']);
    }
    $out[] = [
        'id'    => 'participation',
        'sev'   => $doublons ? REP_STOP : REP_OK,
        'titre' => 'Un archer ne tire qu\'une fois par départ',
        'nb'    => count($plan['affectations']) . ' inscr.',
        'detail' => $doublons
            ? implode(' · ', $txt)
              . ' — à régler dans les inscriptions ianseo : le module place des lots, '
              . 'il ne peut pas arbitrer une double participation.'
            : "Aucune double inscription détectée sur un même départ.",
        'identites' => $doublons,
    ];

    // ── 4. Assez de places pour chaque épreuve ───────────────────────────────
    $manque = [];
    $vide   = [];
    foreach ($parEpreuve as $cle => $liste) {
        $places = 0;
        foreach ($liste as $b) $places += rep_places($b);
        $nb = isset($plan['archers'][$cle]) ? count($plan['archers'][$cle]) : 0;
        $r  = $nb - $places;
        if ($r > 0)      $manque[] = $cle . ' : ' . $r;
        elseif ($r < 0)  $vide[]   = $cle . ' : ' . (-$r);
    }
    $out[] = [
        'id'    => 'effectifs',
        'sev'   => $manque ? REP_STOP : ($vide ? REP_WARN : REP_OK),
        'titre' => 'Assez de places pour chaque épreuve',
        'nb'    => $manque ? (count($manque) . ' épreuve(s)') : ($vide ? count($vide) . ' épreuve(s)' : 'complet'),
        'detail' => $manque
            ? 'Archers sans place — ' . implode(' · ', $manque)
            : ($vide ? 'Places non pourvues, sans gravité — ' . implode(' · ', $vide)
                     : 'Chaque épreuve dispose du nombre de places nécessaire.'),
    ];

    // ── 5. Aucun archer seul sur une cible — avertissement ───────────────────
    $phys = [];
    foreach ($plan['affectations'] as $a) {
        $k = $a['session'] . ':' . $a['target'];
        $phys[$k] = ($phys[$k] ?? 0) + 1;
    }
    $seuls = [];
    foreach ($phys as $k => $n) {
        if ($n !== 1) continue;
        list($s, $t) = explode(':', $k);
        $seuls[] = ['session' => intval($s), 'cible' => intval($t)];
    }
    usort($seuls, function ($a, $b) {
        return $a['session'] - $b['session'] ?: $a['cible'] - $b['cible'];
    });
    $rebIds = rep_blocs_rebalancables($plan);
    $apSeuls = [];
    foreach (array_slice($seuls, 0, 8) as $x) $apSeuls[] = 'départ ' . $x['session'] . ' / cible ' . $x['cible'];
    $out[] = [
        'id'    => 'seuls',
        'sev'   => $seuls ? REP_WARN : REP_OK,
        'titre' => 'Aucun archer seul sur une cible',
        'nb'    => $seuls ? (count($seuls) . ' cible(s)') : 'conforme',
        'detail' => $seuls
            ? implode(' · ', $apSeuls) . (count($seuls) > 8 ? ' … et ' . (count($seuls) - 8) . ' autre(s)' : '')
              . ($rebIds
                    ? ' — un report depuis la cible précédente est possible.'
                    : ' — non réparti automatiquement (la cible voisine n\'a pas assez d\'archers à céder).')
            : 'Chaque cible occupée reçoit au moins deux archers.',
        'rebalancables' => count($rebIds),
    ];

    // ── 6. Règlement fédéral — avertissement, jamais bloquant ────────────────
    $max = rep_max_club();
    $parCible = [];
    foreach ($plan['affectations'] as $a) {
        if ($a['club'] === '') continue;
        $parCible[$a['session'] . ':' . $a['target']][] = $a['club'];
    }
    $fautives = [];
    foreach ($parCible as $cle => $clubs) {
        $cpt = array_count_values($clubs);
        arsort($cpt);
        $pire = reset($cpt);
        if ($pire > $max) {
            list($ses, $cib) = explode(':', $cle);
            $fautives[] = ['session' => intval($ses), 'cible' => intval($cib),
                           'nb' => $pire, 'club' => key($cpt)];
        }
    }
    usort($fautives, function ($a, $b) {
        return $b['nb'] - $a['nb'] ?: ($a['session'] - $b['session'] ?: $a['cible'] - $b['cible']);
    });
    $ap = [];
    foreach (array_slice($fautives, 0, 6) as $f) {
        $ap[] = 'départ ' . $f['session'] . ' / cible ' . $f['cible'] . ' : ' . $f['nb'] . '× ' . $f['club'];
    }
    $reg      = rep_regles();
    $aBrasser = rep_blocs_a_brasser($plan);
    $out[] = [
        'id'    => 'reglement',
        'sev'   => $fautives ? REP_WARN : REP_OK,
        'titre' => 'Règlement — ' . $max . ' archers maximum d\'un même club par cible',
        'nb'    => $fautives ? (count($fautives) . ' cible(s)') : 'conforme',
        'detail' => ($fautives
            ? implode(' · ', $ap) . (count($fautives) > 6 ? ' … et ' . (count($fautives) - 6) . ' autre(s)' : '')
              . ($aBrasser ? ' — un brassage automatique peut ramener ces cibles dans la règle.' : '')
            : 'Aucune cible ne réunit plus de ' . $max . ' licenciés d\'un même club.')
            . ' — ' . ($reg['reglement']['texte'] ?? ''),
        'cibles'    => $fautives,
        'brassables' => count($aBrasser),
    ];

    $stop = 0; $warn = 0;
    foreach ($out as $c) {
        if ($c['sev'] === REP_STOP) $stop++;
        elseif ($c['sev'] === REP_WARN) $warn++;
    }

    return [
        'controles'     => $out,
        'stop'          => $stop,
        'warn'          => $warn,
        'blocsKo'       => array_keys($blocsKo),
        'rebalancables' => $rebIds,
        'brassables'    => $aBrasser,
        'total'         => count($plan['affectations']),
    ];
}
