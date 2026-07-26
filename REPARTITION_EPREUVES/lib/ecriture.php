<?php
/**
 * lib/ecriture.php — LE SEUL point d'écriture du module dans Qualifications.
 *
 * INVARIANT — ne jamais assouplir :
 * Qualifications n'a AUCUNE colonne de compétition. Sa clé primaire est QuId et
 * tous ses index (QuSession, QuSession+QuTarget+QuLetter, QuTargetNo) sont globaux.
 * Un filtre géométrique — « le départ 1, cible 12 » — s'applique donc à toute la
 * base : mesuré sur une installation réelle, WHERE QuSession=1 touche 2011 lignes
 * réparties sur 19 compétitions. La table voisine AvailableTarget, elle, porte bien
 * AtTournament dans sa clé, ce qui laisse croire le contraire.
 *
 * Toute écriture d'ici est donc bornée par un EnId explicite, plus trois garde-fous
 * redondants (compétition, arme, individuel). Redondants volontairement : une erreur
 * de sélection produit alors « zéro ligne modifiée » au lieu d'un dégât silencieux.
 *
 * Une relecture post-écriture compare l'état obtenu à l'état attendu — un UPDATE
 * qui réécrit les mêmes valeurs renvoie 0 ligne affectée, ce compteur ne prouverait
 * donc rien.
 */

require_once __DIR__ . '/controles.php';

/** Liste d'identifiants → « 1,2,3 », vide → « 0 » (aucune ligne). */
function rep_in_list($ids)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? implode(',', $ids) : '0';
}

/**
 * Applique le plan.
 *
 * @param int   $tourId    compétition en cours ($_SESSION['TourId'])
 * @param array $sessions  départs à écrire ; vide = tous
 * @param bool  $apercu    true = ne rien écrire, seulement calculer
 * @param bool  $forcer    true = passer outre les avertissements (jamais les blocages)
 */
function rep_appliquer($tourId, $sessions = [], $apercu = true, $forcer = false)
{
    $tourId   = intval($tourId);
    $sessions = array_values(array_unique(array_map('intval', $sessions)));

    $plan  = rep_placer_tout($tourId);
    $ctrl  = rep_controles($tourId);

    $lignes = [];
    foreach ($plan['affectations'] as $a) {
        if ($sessions && !in_array(intval($a['session']), $sessions, true)) continue;
        $lignes[] = $a;
    }

    $res = [
        'ok'        => true,
        'apercu'    => (bool) $apercu,
        'attendu'   => count($lignes),
        'verifie'   => 0,
        'ecrit'     => false,
        'controles' => $ctrl,
        'lignes'    => $lignes,
        'err'       => '',
    ];

    if (!$lignes) {
        $res['ok']  = false;
        $res['err'] = 'Aucun archer à placer : vérifiez les blocs et les correspondances.';
        return $res;
    }
    if ($apercu) return $res;

    if ($ctrl['stop'] > 0) {
        $res['ok']  = false;
        $res['err'] = $ctrl['stop'] . ' contrôle(s) bloquant(s) : corrigez avant d\'appliquer.';
        return $res;
    }
    if ($ctrl['warn'] > 0 && !$forcer) {
        $res['ok']  = false;
        $res['err'] = $ctrl['warn'] . ' avertissement(s) : confirmez pour appliquer malgré tout.';
        return $res;
    }

    // ── Les identifiants concernés, explicitement ────────────────────────────
    $enidsPlaces = [];
    foreach ($lignes as $l) $enidsPlaces[] = intval($l['enid']);

    // Les inscriptions des mêmes épreuves qui ne sont plus placées par ce plan :
    // on les libère, mais uniquement sur les départs écrits, et uniquement pour
    // des EnId explicites — jamais par plage de cibles.
    $epreuves = [];
    foreach ($plan['blocs'] as $b) {
        if ($sessions && !in_array(intval($b['session']), $sessions, true)) continue;
        $epreuves[$b['cle']] = $b;
    }
    $enidsEpreuves = [];
    foreach ($epreuves as $cle => $b) {
        $liste = $plan['archers'][$cle] ?? [];
        foreach ($liste as $a) $enidsEpreuves[] = intval($a['enid']);
    }
    $aLiberer = array_values(array_diff($enidsEpreuves, $enidsPlaces));

    $sesFiltre = $sessions ? ' AND q.QuSession IN (' . rep_in_list($sessions) . ') ' : ' ';

    if ($aLiberer) {
        foreach (array_chunk($aLiberer, 200) as $paquet) {
            safe_w_sql("UPDATE Qualifications q
                INNER JOIN Entries e ON e.EnId = q.QuId
                   SET q.QuTarget = 0, q.QuLetter = '', q.QuTargetNo = ''
                 WHERE q.QuId IN (" . rep_in_list($paquet) . ")
                   AND e.EnTournament = $tourId
                   AND e.EnAthlete = 1"
                 . $sesFiltre);
        }
    }

    // ── L'écriture, une inscription à la fois ────────────────────────────────
    foreach ($lignes as $l) {
        $enid   = intval($l['enid']);
        $ses    = intval($l['session']);
        $target = intval($l['target']);
        $lettre = $l['letter'];
        $tno    = rep_target_no($ses, $target, $lettre);

        safe_w_sql("UPDATE Qualifications q
            INNER JOIN Entries e ON e.EnId = q.QuId
               SET q.QuSession   = $ses,
                   q.QuTarget    = $target,
                   q.QuLetter    = " . StrSafe_DB($lettre) . ",
                   q.QuTargetNo  = " . StrSafe_DB($tno) . "
             WHERE q.QuId         = $enid
               AND e.EnTournament = $tourId
               AND e.EnDivision   = " . StrSafe_DB($l['division']) . "
               AND e.EnClass      = " . StrSafe_DB($l['class']) . "
               AND e.EnAthlete    = 1");
    }

    // ── Relecture : l'état obtenu est-il bien l'état attendu ? ───────────────
    // Un UPDATE qui réécrit des valeurs identiques renvoie 0 ligne affectée :
    // le compteur de MySQL ne prouverait rien, la relecture si.
    $verifie = 0;
    foreach (array_chunk($lignes, 200) as $paquet) {
        $couples = [];
        foreach ($paquet as $l) {
            $couples[] = '(' . intval($l['enid']) . ','
                       . StrSafe_DB(rep_target_no($l['session'], $l['target'], $l['letter'])) . ')';
        }
        $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Qualifications q
            INNER JOIN Entries e ON e.EnId = q.QuId
            WHERE e.EnTournament = $tourId
              AND (q.QuId, q.QuTargetNo) IN (" . implode(',', $couples) . ")");
        $r = $rs ? safe_fetch($rs) : null;
        if ($r) $verifie += intval($r->n);
    }

    $res['ecrit']   = true;
    $res['verifie'] = $verifie;
    if ($verifie !== $res['attendu']) {
        $res['ok']  = false;
        $res['err'] = 'Écriture incomplète : ' . $verifie . ' inscription(s) vérifiée(s) sur '
                    . $res['attendu'] . ' attendue(s). Les inscriptions manquantes n\'ont probablement '
                    . 'pas de ligne dans Qualifications, ou leur épreuve a changé entre le calcul et l\'écriture.';
    }

    rep_journal($tourId, $sessions, $res);
    return $res;
}

/** Trace de l'écriture, avec le détail nécessaire pour revenir en arrière. */
function rep_journal($tourId, $sessions, $res)
{
    $detail = [];
    foreach ($res['lignes'] as $l) {
        $detail[] = [
            'enid' => intval($l['enid']),
            'lic'  => $l['licence'],
            'ses'  => intval($l['session']),
            'tno'  => rep_target_no($l['session'], $l['target'], $l['letter']),
        ];
    }
    $qui = '';
    if (!empty($_SESSION['AUTH_User']))      $qui = (string) $_SESSION['AUTH_User'];
    elseif (!empty($_SESSION['UserLogged'])) $qui = (string) $_SESSION['UserLogged'];

    safe_w_sql("INSERT INTO REP_Journal
        (CjTournament, CjSession, CjQuand, CjQui, CjAttendu, CjLignes, CjDetail) VALUES ("
        . intval($tourId) . ", "
        . (count($sessions) === 1 ? intval($sessions[0]) : 0) . ", "
        . StrSafe_DB(date('Y-m-d H:i:s')) . ", "
        . StrSafe_DB(mb_substr($qui, 0, 64)) . ", "
        . intval($res['attendu']) . ", "
        . intval($res['verifie']) . ", "
        . StrSafe_DB(json_encode($detail, JSON_UNESCAPED_UNICODE)) . ")");
}

/**
 * Libère les cibles attribuées aux archers d'une ÉPREUVE (via Individuals) :
 * QuTarget / QuLetter / QuTargetNo remis à vide. Borné par EnTournament — donc à
 * cette seule compétition, jamais de débordement.
 * Retourne le nombre d'inscriptions concernées.
 */
function rep_liberer_epreuve($tourId, $event)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId
        INNER JOIN Individuals i ON i.IndId = e.EnId AND i.IndTournament = e.EnTournament
        WHERE e.EnTournament = $tourId AND e.EnAthlete = 1
          AND i.IndEvent = " . StrSafe_DB($event) . "
          AND q.QuTargetNo <> ''");
    $n = ($rs && $r = safe_fetch($rs)) ? intval($r->n) : 0;

    safe_w_sql("UPDATE Qualifications q
        INNER JOIN Entries e ON e.EnId = q.QuId
        INNER JOIN Individuals i ON i.IndId = e.EnId AND i.IndTournament = e.EnTournament
           SET q.QuTarget = 0, q.QuLetter = '', q.QuTargetNo = ''
         WHERE e.EnTournament = $tourId AND e.EnAthlete = 1
           AND i.IndEvent = " . StrSafe_DB($event));
    return $n;
}

/** Nombre d'inscriptions déjà placées, par épreuve (clé = code d'épreuve). */
function rep_affectations_par_epreuve($tourId)
{
    $tourId = intval($tourId);
    $out = [];
    $rs = safe_r_sql("SELECT i.IndEvent AS ev, COUNT(*) AS n
        FROM Individuals i
        INNER JOIN Entries e ON e.EnId = i.IndId AND e.EnTournament = i.IndTournament
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        WHERE e.EnTournament = $tourId AND e.EnAthlete = 1 AND q.QuTargetNo <> ''
        GROUP BY i.IndEvent");
    while ($rs && $r = safe_fetch($rs)) {
        $out[$r->ev] = intval($r->n);
    }
    return $out;
}

/** Les dernières écritures d'une compétition, pour la page principale. */
function rep_journal_recent($tourId, $limite = 5)
{
    $out = [];
    $rs = safe_r_sql("SELECT CjId, CjSession, CjQuand, CjQui, CjAttendu, CjLignes
        FROM REP_Journal WHERE CjTournament=" . intval($tourId) . "
        ORDER BY CjId DESC LIMIT " . intval($limite));
    while ($rs && $r = safe_fetch($rs)) {
        $out[] = [
            'id'      => intval($r->CjId),
            'session' => intval($r->CjSession),
            'quand'   => $r->CjQuand,
            'qui'     => $r->CjQui,
            'attendu' => intval($r->CjAttendu),
            'lignes'  => intval($r->CjLignes),
        ];
    }
    return $out;
}
