<?php
/**
 * tests/run.php — banc de test du moteur, en ligne de commande.
 *
 *   c:\ianseo\php\php.exe htdocs/Modules/Custom/SELEC/tests/run.php
 *
 * Deux familles de tests :
 *  - des cas construits (comparaison exacte, ex aequo, cascades) ;
 *  - des cas de RÉFÉRENCE repris du classeur Excel réellement utilisé par la DTN
 *    pour la sélection Europe 2026 (« Tableau gestion résultats sélection Europe
 *    CLASSIQUE.xlsx », feuille « J3 Tournois »). Ces cas sont la preuve que le
 *    moteur reproduit à l'identique ce que la DTN calculait à la main, y compris
 *    sur les égalités — c'est là que le tableur était le plus fragile.
 *
 * Le moteur ne touche PAS la base pour ces tests : on construit le contexte à la
 * main et on appelle les parties pures (selec_tournoi_points, briques de cumul).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../lib/classement.php';

// Les libs de lecture ne sont pas chargées : aucun accès base ici. On fournit
// donc les seules fonctions ianseo que le moteur peut appeler à l'exécution.
if (!function_exists('safe_r_sql'))  { function safe_r_sql($q)   { return false; } }
if (!function_exists('safe_w_sql'))  { function safe_w_sql($q)   { return false; } }
if (!function_exists('safe_fetch'))  { function safe_fetch($r)   { return false; } }
if (!function_exists('StrSafe_DB'))  { function StrSafe_DB($v)   { return "'" . addslashes((string) $v) . "'"; } }
if (!function_exists('safe_w_last_id')) { function safe_w_last_id() { return 0; } }

// Les vraies libs sont chargées telles quelles : elles n'accèdent à la base
// qu'à l'exécution, et les shims ci-dessus les font retourner « rien ».
require_once __DIR__ . '/../lib/moteur.php';

// ─────────────────────────────────────────────────────────────────────────────
// Micro-harnais
// ─────────────────────────────────────────────────────────────────────────────
$OK = 0; $KO = 0; $ECHECS = array();

function t_eq($attendu, $obtenu, $quoi)
{
    global $OK, $KO, $ECHECS;
    if ($attendu === $obtenu || (is_numeric($attendu) && is_numeric($obtenu) && abs($attendu - $obtenu) < 1e-9)) {
        $OK++;
        return true;
    }
    $KO++;
    $ECHECS[] = sprintf("  ✗ %s : attendu %s, obtenu %s", $quoi,
        var_export($attendu, true), var_export($obtenu, true));
    return false;
}

function t_titre($s) { echo "\n── $s\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// Fabriques de contexte
// ─────────────────────────────────────────────────────────────────────────────

/** Contexte minimal : archers nommés + barèmes de l'épreuve 1. */
function ctx_e1($noms)
{
    $archers = array();
    $i = 1;
    foreach ($noms as $n) { $archers[$i] = array('id' => $i, 'affiche' => $n); $i++; }
    return array(
        'tour' => 0, 'cat' => 'TEST', 'binds' => array(),
        'archers' => $archers, 'etapes' => array(), 'alertes' => array(), 'barrages' => array(),
        'quals' => array(),
        'mode' => array('baremes' => array(
            'R8'    => array('type' => 'rang', 'points' => array(8, 7, 6, 5, 4, 3, 2, 1)),
            'PERF8' => array('type' => 'rang', 'points' => array(4, 3.5, 3, 2.5, 2, 1.5, 1, 0.5)),
            'R6'    => array('type' => 'rang', 'points' => array(6, 5, 4, 3, 2, 1)),
            'PERF6' => array('type' => 'rang', 'points' => array(3, 2.5, 2, 1.5, 1, 0.5)),
            'VIC6'  => array('type' => 'valeur', 'table' => array('5' => 6, '4' => 5, '3' => 4, '2' => 3, '1' => 2, '0' => 1)),
        )),
    );
}

/** Identifiant d'un archer par son nom dans un contexte. */
function idpar(&$ctx, $nom)
{
    foreach ($ctx['archers'] as $id => $a) if ($a['affiche'] === $nom) return $id;
    throw new Exception("archer inconnu : $nom");
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('1. Comparaison exacte de fractions (jamais de flottant)');

// 348/13 apparaît deux fois dans le tournoi 4 de J3 2026 (VALLADONT et VIALE) :
// c'est une VRAIE égalité, elle doit être détectée comme telle.
t_eq(0, selec_cmp(selec_v_frac(348, 13), selec_v_frac(348, 13)), '348/13 = 348/13');
// Même valeur écrite autrement : 706/75 et 1412/150.
t_eq(0, selec_cmp(selec_v_frac(706, 75), selec_v_frac(1412, 150)), '706/75 = 1412/150');
// Écart d'un point sur un dénominateur différent.
t_eq(1, selec_cmp(selec_v_frac(343, 12), selec_v_frac(331, 12)), '343/12 > 331/12');
t_eq(-1, selec_cmp(selec_v_frac(305, 11), selec_v_frac(361, 13)), '305/11 < 361/13');
// Vecteur (score, X, 10) comparé lexicographiquement.
t_eq(1, selec_cmp(selec_v_vec(array(654, 8, 21)), selec_v_vec(array(654, 7, 25))), 'même score, plus de X');
t_eq(-1, selec_cmp(selec_v_vec(array(654, 8, 21)), selec_v_vec(array(655, 0, 0))), 'score prime sur X');
t_eq(0, selec_cmp(selec_v_vec(array(654, 8, 21)), selec_v_vec(array(654, 8, 21))), 'triplet identique');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('2. Classement sportif : 1, 2, 2, 4 et points identiques aux ex aequo');

$vals = array(1 => 12, 2 => 10, 3 => 10, 4 => 8);
$casc = array(
    array('id' => 'v', 'label' => 'Valeur', 'fn' => function ($id) use ($vals) { return selec_v_int($vals[$id]); }),
    array('id' => 'egalite', 'label' => 'Égalité conservée', 'fn' => function () { return null; }),
);
$r = selec_ranger(array_keys($vals), $casc);
$rangs = array(); foreach ($r as $o) $rangs[$o['id']] = $o['rang'];
t_eq(1, $rangs[1], 'rang du 1er');
t_eq(2, $rangs[2], 'rang du 2e');
t_eq(2, $rangs[3], 'rang du 3e (ex aequo)');
t_eq(4, $rangs[4], 'rang du suivant après une égalité à 2');
$ex = array(); foreach ($r as $o) $ex[$o['id']] = $o['exaequo'];
t_eq(true, (bool) $ex[2], 'ex aequo signalé sur le premier des deux');
t_eq(true, (bool) $ex[3], 'ex aequo signalé sur le second des deux');
t_eq(false, (bool) $ex[1], 'le 1er n\'est pas ex aequo');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('3. RÉFÉRENCE DTN — Tournoi 1 de J3, sélection Europe 2026');

// place finale, nombre de sets tirés, total des sets (source : classeur DTN).
$T1 = array(
    // nom                      place  sets  total
    'CHIRAULT THOMAS'      => array(1, 12, 343),
    'ADDIS BAPTISTE'       => array(2, 12, 331),
    'VALLADONT JEAN-CHARLES' => array(3, 11, 305),
    'RENAUDINEAU ALEXIS'   => array(4, 13, 361),
    'ARMAND RAPHAEL'       => array(5, 13, 358),
    'VIALE ROMAIN'         => array(6, 14, 380),
    'FICHET ROMAIN'        => array(7, 13, 346),
    'BERNARDI NICOLAS'     => array(8, 14, 377),
);
// attendu : rang de performance, points de perf, total, rang total, Points de Tournoi
$T1_attendu = array(
    'CHIRAULT THOMAS'        => array(1, 400, 1200, 1, 800),
    'ADDIS BAPTISTE'         => array(4, 250,  950, 2, 700),
    'VALLADONT JEAN-CHARLES' => array(3, 300,  900, 3, 600),
    'RENAUDINEAU ALEXIS'     => array(2, 350,  850, 4, 500),
    'ARMAND RAPHAEL'         => array(5, 200,  600, 5, 400),
    'VIALE ROMAIN'           => array(6, 150,  450, 6, 300),
    'FICHET ROMAIN'          => array(8,  50,  250, 7, 200),
    'BERNARDI NICOLAS'       => array(7, 100,  200, 8, 100),
);

function joue_tournoi($sid, $data, $bareme = 'R8', $perf = 'PERF8')
{
    $ctx = ctx_e1(array_keys($data));
    $contrib = array(); $places = array(); $ids = array();
    foreach ($data as $nom => $d) {
        $id = idpar($ctx, $nom);
        $ids[] = $id;
        $places[$id] = $d[0];
        $c = selec_contrib_vide();
        $c['sets'] = $d[1]; $c['set_total'] = $d[2]; $c['score'] = $d[2];
        $c['fleches'] = $d[1] * 3;
        $contrib[$id] = $c;
    }
    $st = array('id' => $sid, 'type' => 'tournoi',
        'bareme_classement' => $bareme, 'bareme_performance' => $perf, 'bareme' => $bareme,
        'departage_performance' => array(array('c' => 'egalite')),
        'departage' => array(array('c' => 'egalite')));
    $ctx['etapes'][$sid] = array('def' => $st, 'contrib' => $contrib, 'lignes' => array());
    selec_tournoi_points($ctx, $st, $ids, $places, array());
    return $ctx;
}

$ctx = joue_tournoi('T1', $T1);
foreach ($T1_attendu as $nom => $att) {
    $id = idpar($ctx, $nom);
    $l = $ctx['etapes']['T1']['lignes'][$id];
    t_eq($att[0], $l['detail']['rang_perf'], "T1 $nom — rang de performance");
    t_eq($att[1], $l['detail']['pts_perf'],  "T1 $nom — points de performance");
    t_eq($att[2], $l['detail']['somme'],     "T1 $nom — somme classement+performance");
    t_eq($att[3], $l['rang'],                "T1 $nom — rang sur la somme");
    t_eq($att[4], $l['points_c'],            "T1 $nom — Points de Tournoi");
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('4. RÉFÉRENCE DTN — Tournoi 4 de J3 2026 (trois égalités, dont une exacte)');

$T4 = array(
    'BERNARDI NICOLAS'       => array(1, 14, 385),
    'ADDIS BAPTISTE'         => array(2, 13, 362),
    'CHIRAULT THOMAS'        => array(3, 14, 386),
    'VALLADONT JEAN-CHARLES' => array(4, 13, 348),
    'ARMAND RAPHAEL'         => array(5, 15, 406),
    'RENAUDINEAU ALEXIS'     => array(6, 14, 368),
    'VIALE ROMAIN'           => array(7, 13, 348),
    'FICHET ROMAIN'          => array(8, 14, 380),
);
$T4_attendu = array(
    // VALLADONT et VIALE sont à 348/13 exactement : rang de performance 6 pour
    // les deux, 1,5 point chacun (le rang 7 est consommé).
    'BERNARDI NICOLAS'       => array(3, 300, 1100, 1, 800),
    'ADDIS BAPTISTE'         => array(1, 400, 1100, 1, 800),
    'CHIRAULT THOMAS'        => array(2, 350,  950, 3, 600),
    'VALLADONT JEAN-CHARLES' => array(6, 150,  650, 4, 500),
    'ARMAND RAPHAEL'         => array(5, 200,  600, 5, 400),
    'RENAUDINEAU ALEXIS'     => array(8,  50,  350, 6, 300),
    'VIALE ROMAIN'           => array(6, 150,  350, 6, 300),
    'FICHET ROMAIN'          => array(4, 250,  350, 6, 300),
);

$ctx4 = joue_tournoi('T4', $T4);
foreach ($T4_attendu as $nom => $att) {
    $id = idpar($ctx4, $nom);
    $l = $ctx4['etapes']['T4']['lignes'][$id];
    t_eq($att[0], $l['detail']['rang_perf'], "T4 $nom — rang de performance");
    t_eq($att[1], $l['detail']['pts_perf'],  "T4 $nom — points de performance");
    t_eq($att[2], $l['detail']['somme'],     "T4 $nom — somme classement+performance");
    t_eq($att[3], $l['rang'],                "T4 $nom — rang sur la somme");
    t_eq($att[4], $l['points_c'],            "T4 $nom — Points de Tournoi");
}
// L'égalité doit être VISIBLE, pas seulement produire les mêmes points.
t_eq(1, $ctx4['etapes']['T4']['lignes'][idpar($ctx4, 'BERNARDI NICOLAS')]['exaequo'], 'T4 — égalité signalée (BERNARDI)');
t_eq(1, $ctx4['etapes']['T4']['lignes'][idpar($ctx4, 'ADDIS BAPTISTE')]['exaequo'],   'T4 — égalité signalée (ADDIS)');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('5. RÉFÉRENCE DTN — Points Journaliers de J3 2026');

$noms = array_keys($T1);
$ctxJ = ctx_e1($noms);
// Points de tournoi réellement obtenus (T2+T3 regroupés : seul leur cumul entre
// dans le classement de la journée).
$pts = array(
    'CHIRAULT THOMAS'        => array(8, 16, 6),
    'ADDIS BAPTISTE'         => array(7, 13, 8),
    'VALLADONT JEAN-CHARLES' => array(6,  9, 5),
    'RENAUDINEAU ALEXIS'     => array(5,  5, 3),
    'ARMAND RAPHAEL'         => array(4,  7, 4),
    'VIALE ROMAIN'           => array(3, 11, 3),
    'FICHET ROMAIN'          => array(2,  4, 3),
    'BERNARDI NICOLAS'       => array(1,  8, 8),
);
foreach (array('T1', 'T23', 'T4') as $k => $sid) {
    $ctxJ['etapes'][$sid] = array('def' => array('id' => $sid), 'contrib' => array(), 'lignes' => array());
    foreach ($pts as $nom => $p) {
        $ctxJ['etapes'][$sid]['lignes'][idpar($ctxJ, $nom)] = array(
            'rang' => 0, 'points_c' => $p[$k] * 100, 'num' => 0, 'den' => 1,
            'tie' => '', 'exaequo' => 0, 'retenu' => 1, 'detail' => array());
    }
}
selec_brique_journee($ctxJ, array('id' => 'J3', 'type' => 'journee',
    'sources' => array('T1', 'T23', 'T4'), 'bareme' => 'R8',
    'perimetre' => 'tous', 'departage' => array(array('c' => 'egalite'))));

$J3_attendu = array(
    // cumul T1..T4, rang, Points Journaliers
    'CHIRAULT THOMAS'        => array(3000, 1, 800),
    'ADDIS BAPTISTE'         => array(2800, 2, 700),
    'VALLADONT JEAN-CHARLES' => array(2000, 3, 600),
    'BERNARDI NICOLAS'       => array(1700, 4, 500),
    'VIALE ROMAIN'           => array(1700, 4, 500),
    'ARMAND RAPHAEL'         => array(1500, 6, 300),
    'RENAUDINEAU ALEXIS'     => array(1300, 7, 200),
    'FICHET ROMAIN'          => array( 900, 8, 100),
);
foreach ($J3_attendu as $nom => $att) {
    $l = $ctxJ['etapes']['J3']['lignes'][idpar($ctxJ, $nom)];
    t_eq($att[0], $l['detail']['total'], "J3 $nom — cumul des Points de Tournoi");
    t_eq($att[1], $l['rang'],            "J3 $nom — rang de la journée");
    t_eq($att[2], $l['points_c'],        "J3 $nom — Points Journaliers");
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('6. RÉFÉRENCE DTN — valeur moyenne de flèche à l\'issue de J3 2026');

// Le classeur cumule qualifications ET duels : 96 « sets » de qualification
// (4 × 72 flèches = 288) plus les sets de duels.
$vf = array(
    'CHIRAULT THOMAS'        => array(2661, 288, 1483, 53, 9.2706935123042502),
    'ADDIS BAPTISTE'         => array(2665, 288, 1462, 53, 9.2326621923937360),
    'VALLADONT JEAN-CHARLES' => array(2615, 288, 1429, 53, 9.0469798657718119),
    'RENAUDINEAU ALEXIS'     => array(2642, 288, 1540, 57, 9.1111111111111107),
);
$ctxV = ctx_e1(array_keys($vf));
$ctxV['etapes']['Q'] = array('def' => array('id' => 'Q'), 'contrib' => array(), 'lignes' => array());
$ctxV['etapes']['D'] = array('def' => array('id' => 'D'), 'contrib' => array(), 'lignes' => array());
foreach ($vf as $nom => $d) {
    $id = idpar($ctxV, $nom);
    $q = selec_contrib_vide(); $q['score'] = $d[0]; $q['fleches'] = $d[1];
    $m = selec_contrib_vide(); $m['score'] = $d[2]; $m['sets'] = $d[3]; $m['fleches'] = $d[3] * 3;
    $ctxV['etapes']['Q']['contrib'][$id] = $q;
    $ctxV['etapes']['D']['contrib'][$id] = $m;
}
$cr = selec_critere($ctxV, array('c' => 'valeur_fleche', 'quals' => array('Q'), 'etapes' => array('D')), 'X');
foreach ($vf as $nom => $d) {
    $v = call_user_func($cr['fn'], idpar($ctxV, $nom));
    t_eq(round($d[4], 10), round(selec_v_float($v), 10), "valeur de flèche — $nom");
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('7. Barème « valeur » : points de poule par nombre de victoires');

$b = array('type' => 'valeur', 'table' => array('5' => 6, '4' => 5, '3' => 4, '2' => 3, '1' => 2, '0' => 1));
t_eq(600, selec_bareme_points($b, 1, 5), '5 victoires → 6 points');
t_eq(400, selec_bareme_points($b, 4, 3), '3 victoires → 4 points, quel que soit le rang');
t_eq(400, selec_bareme_points($b, 3, 3), '3 victoires → 4 points (autre rang, même valeur)');
t_eq(100, selec_bareme_points($b, 6, 0), '0 victoire → 1 point');
$r8 = array('type' => 'rang', 'points' => array(8, 7, 6, 5, 4, 3, 2, 1));
t_eq(350, selec_bareme_points(array('type' => 'rang', 'points' => array(4, 3.5, 3)), 2), 'demi-point exact (3,5 → 350)');
t_eq(0, selec_bareme_points($r8, 9), 'au-delà du barème → 0');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('8. Cascade de départage d\'une journée (cas construit)');

// Deux archers à 13 points journaliers, même somme de scores : on descend sur la
// meilleure qualification, puis la seconde.
$ctxD = ctx_e1(array('A', 'B', 'C'));
$A = idpar($ctxD, 'A'); $B = idpar($ctxD, 'B'); $C = idpar($ctxD, 'C');
foreach (array('Q1', 'Q2') as $sid) {
    $ctxD['etapes'][$sid] = array('def' => array('id' => $sid), 'contrib' => array(), 'lignes' => array());
}
// A : 650 + 640 (meilleure 650, 12 X) ; B : 640 + 650 (meilleure 650, 10 X) ; C : 600 + 600
$mk = function ($score, $x, $gold) { $c = selec_contrib_vide(); $c['score'] = $score; $c['x'] = $x; $c['gold'] = $gold; $c['fleches'] = 72; return $c; };
$ctxD['etapes']['Q1']['contrib'] = array($A => $mk(650, 12, 30), $B => $mk(640, 9, 28), $C => $mk(600, 5, 20));
$ctxD['etapes']['Q2']['contrib'] = array($A => $mk(640, 8, 27), $B => $mk(650, 10, 31), $C => $mk(600, 5, 20));
foreach (array('Q1' => array($A => 8, $B => 7, $C => 6), 'Q2' => array($A => 7, $B => 8, $C => 6)) as $sid => $pp) {
    foreach ($pp as $id => $p) {
        $ctxD['etapes'][$sid]['lignes'][$id] = array('rang' => 0, 'points_c' => $p * 100,
            'num' => 0, 'den' => 1, 'tie' => '', 'exaequo' => 0, 'retenu' => 1, 'detail' => array());
    }
}
selec_brique_journee($ctxD, array('id' => 'J1', 'type' => 'journee',
    'sources' => array('Q1', 'Q2'), 'bareme' => 'R8', 'perimetre' => 'tous',
    'departage' => array(
        array('c' => 'somme_scores', 'quals' => array('Q1', 'Q2')),
        array('c' => 'qual_n', 'n' => 1, 'quals' => array('Q1', 'Q2')),
        array('c' => 'qual_n', 'n' => 2, 'quals' => array('Q1', 'Q2')),
        array('c' => 'egalite'))));

$lA = $ctxD['etapes']['J1']['lignes'][$A];
$lB = $ctxD['etapes']['J1']['lignes'][$B];
t_eq(1500, $lA['detail']['total'], 'A — 15 points journaliers');
t_eq(1500, $lB['detail']['total'], 'B — 15 points journaliers');
t_eq(1, $lA['rang'], 'A devant B');
t_eq(2, $lB['rang'], 'B derrière A');
t_eq('Meilleure qualification', $lB['tie'], 'départagés par la meilleure qualification');
// Le PREMIER du groupe doit porter l'information lui aussi : c'est bien lui qui
// doit sa place au départage, pas seulement celui qu'il précède.
t_eq('Meilleure qualification', $lA['tie'], 'le premier du groupe affiche aussi le départage');
t_eq(0, $lA['exaequo'], 'A n\'est pas ex aequo');
// C, seul sur ses points, n'a été départagé de personne : la case reste vide.
// « Départagé au total des points » dans un classement aux points n'apprend rien.
t_eq('', $ctxD['etapes']['J1']['lignes'][$C]['tie'], 'aucun départage affiché sans égalité');

// Même cas, mais cascade coupée juste après la somme des scores : l'égalité doit
// être conservée et les points identiques.
$ctxD2 = $ctxD;
unset($ctxD2['etapes']['J1']);
selec_brique_journee($ctxD2, array('id' => 'J1', 'type' => 'journee',
    'sources' => array('Q1', 'Q2'), 'bareme' => 'R8', 'perimetre' => 'tous',
    'departage' => array(
        array('c' => 'somme_scores', 'quals' => array('Q1', 'Q2')),
        array('c' => 'egalite'))));
$l2A = $ctxD2['etapes']['J1']['lignes'][$A];
$l2B = $ctxD2['etapes']['J1']['lignes'][$B];
t_eq(1, $l2A['rang'], 'A — rang 1 (égalité conservée)');
t_eq(1, $l2B['rang'], 'B — rang 1 (égalité conservée)');
t_eq($l2A['points_c'], $l2B['points_c'], 'mêmes points aux ex aequo');
t_eq(800, $l2A['points_c'], 'les deux ex aequo prennent les points du 1er');
t_eq(600, $ctxD2['etapes']['J1']['lignes'][$C]['points_c'], 'le suivant prend les points du 3e');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('9. Coupure : égalité sur la barre → tous retenus + alerte');

$ctxC = ctx_e1(array('A', 'B', 'C', 'D'));
$ctxC['etapes']['J'] = array('def' => array('id' => 'J'), 'contrib' => array(), 'lignes' => array());
$pts = array('A' => 16, 'B' => 12, 'C' => 12, 'D' => 8);
foreach ($pts as $nom => $p) {
    $ctxC['etapes']['J']['lignes'][idpar($ctxC, $nom)] = array('rang' => 0, 'points_c' => $p * 100,
        'num' => 0, 'den' => 1, 'tie' => '', 'exaequo' => 0, 'retenu' => 1, 'detail' => array());
}
selec_brique_coupure($ctxC, array('id' => 'CUT', 'type' => 'coupure',
    'sources' => array('J'), 'retenus' => 2, 'perimetre' => 'tous',
    'departage' => array(array('c' => 'egalite'))));
t_eq(1, $ctxC['etapes']['CUT']['lignes'][idpar($ctxC, 'B')]['retenu'], 'B retenu');
t_eq(1, $ctxC['etapes']['CUT']['lignes'][idpar($ctxC, 'C')]['retenu'], 'C retenu malgré l\'égalité sur la barre');
t_eq(0, $ctxC['etapes']['CUT']['lignes'][idpar($ctxC, 'D')]['retenu'], 'D écarté');
t_eq(1, count($ctxC['alertes']), 'une alerte de barrage émise');
t_eq(true, strpos($ctxC['alertes'][0], 'tir de barrage') !== false, 'l\'alerte demande un tir de barrage');

// Avec un barrage saisi, l'égalité se tranche et l'alerte disparaît.
$ctxC2 = ctx_e1(array('A', 'B', 'C', 'D'));
$ctxC2['etapes']['J'] = $ctxC['etapes']['J'];
$ctxC2['barrages'] = array('CUT' => array(idpar($ctxC2, 'C') => 1, idpar($ctxC2, 'B') => 2));
selec_brique_coupure($ctxC2, array('id' => 'CUT', 'type' => 'coupure',
    'sources' => array('J'), 'retenus' => 2, 'perimetre' => 'tous',
    'departage' => array(array('c' => 'barrage'), array('c' => 'egalite'))));
t_eq(2, $ctxC2['etapes']['CUT']['lignes'][idpar($ctxC2, 'C')]['rang'], 'C passe 2e par le barrage');
t_eq(3, $ctxC2['etapes']['CUT']['lignes'][idpar($ctxC2, 'B')]['rang'], 'B passe 3e');
t_eq(0, $ctxC2['etapes']['CUT']['lignes'][idpar($ctxC2, 'B')]['retenu'], 'B n\'est plus retenu');
t_eq(0, count($ctxC2['alertes']), 'plus d\'alerte une fois le barrage saisi');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('10. Poule : points par victoires + performance (règlement 2027)');

$ctxP = ctx_e1(array('P1', 'P2', 'P3', 'P4', 'P5', 'P6'));
// victoires, sets, total des sets
$poule = array(
    'P1' => array(5, 15, 420),
    'P2' => array(3, 17, 459),  // 27,0
    'P3' => array(3, 16, 432),  // 27,0 exactement aussi → égalité de performance
    'P4' => array(2, 18, 468),
    'P5' => array(1, 17, 425),
    'P6' => array(1, 16, 400),
);
$ids = array(); $contrib = array();
foreach ($poule as $nom => $d) {
    $id = idpar($ctxP, $nom); $ids[] = $id;
    $c = selec_contrib_vide();
    $c['victoires'] = $d[0]; $c['sets'] = $d[1]; $c['set_total'] = $d[2];
    $c['score'] = $d[2]; $c['fleches'] = $d[1] * 3;
    $contrib[$id] = $c;
}
$stP = array('id' => 'POULE', 'type' => 'poule',
    'bareme_classement' => 'VIC6', 'bareme_performance' => 'PERF6', 'bareme' => 'R6',
    'departage_classement' => array(array('c' => 'egalite')),
    'departage_performance' => array(array('c' => 'egalite')),
    'departage' => array(array('c' => 'egalite')));
$ctxP['etapes']['POULE'] = array('def' => $stP, 'contrib' => $contrib, 'lignes' => array());
selec_poule_points($ctxP, $stP, $ids);

t_eq(600, $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P1')]['detail']['pts_clt'], 'P1 — 5 victoires → 6 points de classement');
t_eq(400, $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P2')]['detail']['pts_clt'], 'P2 — 3 victoires → 4 points');
t_eq(400, $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P3')]['detail']['pts_clt'], 'P3 — 3 victoires → 4 points (même valeur, pas de rang)');
t_eq(200, $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P5')]['detail']['pts_clt'], 'P5 — 1 victoire → 2 points');
t_eq(200, $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P6')]['detail']['pts_clt'], 'P6 — 1 victoire → 2 points');
// 459/17 = 27 et 432/16 = 27 : égalité exacte de moyenne de set.
$rp2 = $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P2')]['detail']['rang_perf'];
$rp3 = $ctxP['etapes']['POULE']['lignes'][idpar($ctxP, 'P3')]['detail']['rang_perf'];
t_eq($rp2, $rp3, '459/17 et 432/16 : même rang de performance (27,0 exact)');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('11. Validation d\'un mode : le catalogue livré doit être cohérent');

require_once __DIR__ . '/../lib/config.php';
foreach (array('TAE_CL_2027_E1', 'TAE_CL_2027_E2') as $mid) {
    $m = selec_mode_charger($mid);
    t_eq(true, is_array($m), "mode $mid lisible");
    if (!$m) continue;
    $err = selec_mode_valider($m);
    if ($err) {
        foreach ($err as $e) $ECHECS[] = "  ✗ mode $mid : $e";
        $KO += count($err);
    } else {
        $OK++;
        echo "  ✓ $mid — " . count($m['etapes']) . " étapes, "
            . count($m['baremes']) . " barèmes, cohérent\n";
    }
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('12. Structure ianseo déduite du mode');

require_once __DIR__ . '/../lib/structure.php';

// Phase de départ d'un tableau : 8 archers → quarts (4), 4 archers → demies (2).
t_eq(4, selec_structure_phase(8), 'tableau de 8 → phase 4 (quarts)');
t_eq(2, selec_structure_phase(4), 'tableau de 4 → phase 2 (demies)');
t_eq(4, selec_structure_phase(6), 'tableau de 6 → phase 4 (deux byes)');
t_eq(8, selec_structure_phase(8 + 1), 'tableau de 9 → phase 8');

// Codes d'épreuve : 10 caractères au plus, suffixe préservé.
t_eq('SHCLT1',  selec_structure_code_brut('SHCL', 'T1', 'principal'),  'code du tableau principal');
t_eq('SHCLT1B', selec_structure_code_brut('SHCL', 'T1', 'consolante'), 'code de la consolante');
$long = selec_structure_code_brut('NU18FCLXYZ', 'T1', 'consolante');
t_eq(true, strlen($long) <= 10, 'code tronqué à 10 caractères (' . $long . ')');
t_eq('T1B', substr($long, -3), 'le suffixe survit à la troncature');

// Les modes livrés doivent décrire une structure réalisable.
foreach (array('TAE_CL_2027_E1', 'TAE_CL_2027_E2') as $mid) {
    $m = selec_mode_charger($mid);
    if (!$m) continue;

    $dists = array(); $sessions = array(); $nEv = 0; $noteDuel3 = false;
    foreach ($m['etapes'] as $st) {
        foreach (selec_structure_distances($st) as $d) $dists[] = intval($d);
        if ($st['type'] === 'qualification') {
            $s = isset($st['structure']) ? $st['structure'] : array();
            t_eq(true, isset($s['session']), "$mid/{$st['id']} — session déclarée");
            if (isset($s['session'])) $sessions[] = intval($s['session']);
            t_eq(6, intval($s['volees'] ?? 0),  "$mid/{$st['id']} — 6 volées");
            t_eq(6, intval($s['fleches'] ?? 0), "$mid/{$st['id']} — de 6 flèches");
        }
        foreach (selec_structure_slots($st) as $slot => $spec) {
            $nEv++;
            if (!empty($spec['note']) && strpos($spec['note'], 'duel à 3') !== false) $noteDuel3 = true;
        }
    }

    // Une série ne peut servir qu'une fois, et il n'y en a que 8 dans ianseo.
    t_eq(count($dists), count(array_unique($dists)), "$mid — aucune série utilisée deux fois");
    t_eq(true, max($dists) <= SELEC_MAX_DISTANCES,
        "$mid — " . max($dists) . " séries au plus (limite " . SELEC_MAX_DISTANCES . ")");
    t_eq(count($sessions), count(array_unique($sessions)), "$mid — un numéro de session par qualification");
    echo "  · $mid : " . count($sessions) . " départs, " . max($dists) . " séries, "
        . $nEv . " épreuves de duels par catégorie\n";
    $OK++;
}

// L'épreuve 1 consomme exactement les 8 emplacements : c'est ce qui interdit de
// loger les duels simulés en distances de qualification.
$m1 = selec_mode_charger('TAE_CL_2027_E1');
$d1 = array();
foreach ($m1['etapes'] as $st) foreach (selec_structure_distances($st) as $d) $d1[] = intval($d);
t_eq(8, count($d1), 'épreuve 1 : les 8 séries sont utilisées par les qualifications');
$ms = null;
foreach ($m1['etapes'] as $st) if ($st['id'] === 'MS') $ms = $st;
t_eq('evenements', $ms['source']['type'] ?? '', 'duels simulés en épreuve de duels, pas en distances');
// Un TABLEAU par duel simulé — pas une épreuve « tous contre tous » : ianseo y
// imposerait n−1 tours (7 pour 8 archers) alors que le règlement en veut 5, et
// des appariements qu'on ne maîtrise pas.
$slotsMs = selec_structure_slots($ms);
t_eq(5, count($slotsMs), 'duels simulés : une épreuve par duel');
t_eq(array('ds1', 'ds2', 'ds3', 'ds4', 'ds5'), array_keys($slotsMs), 'rôles nommés ds1…ds5');
t_eq('tableau', $slotsMs['ds1']['type'], 'chaque duel est un tableau');
t_eq(4, intval($slotsMs['ds1']['phase']), 'tableau de 8 → premier tour en quarts');
t_eq(1, intval($slotsMs['ds1']['cumul']), 'duels simulés en cumul, pas en sets');
t_eq(false, isset($slotsMs['consolante']), 'pas de consolante sur un duel simulé');

// Les codes d'épreuve doivent différer d'un duel à l'autre, sinon les cinq
// tableaux s'écraseraient l'un l'autre.
$codes = array();
foreach (array_keys($slotsMs) as $slot) $codes[] = selec_structure_code_brut('HCL', 'MS', $slot);
t_eq(array('HCLMS1', 'HCLMS2', 'HCLMS3', 'HCLMS4', 'HCLMS5'), $codes, 'un code par duel simulé');
t_eq(5, count(array_unique($codes)), 'aucun code de duel en double');

// Appariement par voisins de classement : 1-2, 3-4, 5-6, 7-8. Pas de rotation —
// les archers gardent leur place du premier au dernier duel simulé.
t_eq(array(array(1, 2), array(3, 4), array(5, 6), array(7, 8)),
    selec_paires_classement(8), 'duels simulés : les voisins de classement s\'affrontent');
t_eq(array(array(1, 2), array(3, 4), array(5, 6)),
    selec_paires_classement(6), 'même règle à 6 archers');
// Effectif impair : la dernière place reste vide, personne n'est oublié.
$p7 = selec_paires_classement(7);
$vus = array();
foreach ($p7 as $p) { $vus[$p[0]] = true; $vus[$p[1]] = true; }
t_eq(8, count($vus), '7 archers : la 8e place complète le dernier duel');

// Le tournoi à 6 doit signaler le duel à 3 : c'est le seul format que ianseo ne
// sait pas jouer, il ne doit pas passer inaperçu.
t_eq(true, $noteDuel3, 'le duel à 3 est signalé sur le tournoi à 6');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('13. Familles de points nommées, et départages configurables partout');

// §9.7 du cahier des charges : ne jamais afficher un « Points » générique qui
// laisserait croire à un pool commun entre les familles.
$attenduFamilles = array(
    'TAE_CL_2027_E1' => array(
        'QUAL' => 'Points de Qualifications', 'JOUR' => 'Points Journaliers',
        'TOURN' => 'Points de Tournois', 'SIMUL' => 'Points de Matchs Simulés'),
    'TAE_CL_2027_E2' => array(
        'QUAL' => 'Points de Qualifications', 'JOUR' => 'Points Journaliers',
        'TOURN6' => 'Points de Tournois à 6', 'POULE' => 'Points de Tournois de Poule'),
);
foreach ($attenduFamilles as $mid => $familles) {
    $m = selec_mode_charger($mid);
    if (!$m) continue;
    t_eq($familles, $m['familles'], "$mid — familles de points nommées");

    // Toute étape qui attribue des points doit déclarer SA famille : c'est elle
    // qui titre la colonne, à l'écran comme à l'impression.
    foreach ($m['etapes'] as $st) {
        if (empty($st['bareme'])) continue;
        t_eq(true, !empty($st['famille']) && isset($m['familles'][$st['famille']]),
            "$mid/{$st['id']} — famille de points déclarée");
    }

    // Départages : configurables étape par étape, et brique par brique pour les
    // classements intermédiaires d'un tournoi ou d'une poule.
    foreach ($m['etapes'] as $st) {
        if (in_array($st['type'], array('qualification', 'duels_simules', 'journee',
                                        'coupure', 'final'), true)) {
            t_eq(true, isset($st['departage']) && is_array($st['departage']),
                "$mid/{$st['id']} — cascade de départage propre à l'étape");
            $der = end($st['departage']);
            t_eq('egalite', $der['c'] ?? '',
                "$mid/{$st['id']} — la cascade se termine par « égalité conservée »");
        }
        if (in_array($st['type'], array('tournoi', 'poule'), true)) {
            t_eq(true, isset($st['departage']),
                "$mid/{$st['id']} — départage de la somme classement+performance");
            t_eq(true, isset($st['departage_performance']),
                "$mid/{$st['id']} — départage propre au classement de performance");
        }
    }
}

// Le libellé de famille doit remonter jusqu'au titre de colonne.
$m1 = selec_mode_charger('TAE_CL_2027_E1');
$parId = array();
foreach ($m1['etapes'] as $st) $parId[$st['id']] = $st;
t_eq('Points de Qualifications', $m1['familles'][$parId['Q1']['famille']], 'Q1 attribue les Points de Qualifications');
t_eq('Points Journaliers',       $m1['familles'][$parId['J1']['famille']], 'J1 attribue les Points Journaliers');
t_eq('Points de Tournois',       $m1['familles'][$parId['T1']['famille']], 'T1 attribue les Points de Tournois');
t_eq('Points de Matchs Simulés', $m1['familles'][$parId['MS']['famille']], 'MS attribue les Points de Matchs Simulés');

// Le module classe, il ne sélectionne pas : le classement final ne désigne aucun
// « qualifié ». Le nombre d'archers retenus appartient à la DTN.
foreach (array('TAE_CL_2027_E1', 'TAE_CL_2027_E2') as $mid) {
    $m = selec_mode_charger($mid);
    foreach ($m['etapes'] as $st) {
        if ($st['type'] !== 'final') continue;
        t_eq(0, intval($st['qualifies'] ?? 0), "$mid — le classement final ne désigne aucun qualifié");
    }
}

// La coupure, elle, reste nécessaire : c'est le règlement qui limite les
// tournois à 8 archers, pas une décision de sélection.
$cut = null;
foreach ($m1['etapes'] as $st) if ($st['type'] === 'coupure') $cut = $st;
t_eq(8, intval($cut['retenus'] ?? 0), 'la coupure de l\'épreuve 1 borne bien les tournois à 8 archers');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('14. Classement figé pour qui n\'a pas pris part à la suite de l\'épreuve');

// Cinq archers, coupure à 2 : A et B continuent, C, D et E s'arrêtent là.
// C, D et E sont tous à 0 point sur la suite — laissés dans la cascade du
// classement final, ils seraient réordonnés par un critère qui n'est pas celui
// de leur classement. Ils doivent garder l'ordre de la coupure, départage
// compris. Cas réel : sur une compétition d'essai, les rangs 11/12/13 du
// classement final correspondaient aux rangs 12/15/11 de la coupure.
$ctxF = ctx_e1(array('A', 'B', 'C', 'D', 'E'));
$idF = array();
foreach (array('A','B','C','D','E') as $nm) $idF[$nm] = idpar($ctxF, $nm);

$ctxF['mode']['etapes'] = array(
    array('id' => 'Q1', 'type' => 'qualification'),
    array('id' => 'CUT', 'type' => 'coupure'),
    array('id' => 'T1', 'type' => 'tournoi'),
);
// Points de journée : A 8, B 7, et C/D/E tous à 3 — donc à égalité stricte.
$ctxF['etapes']['J1'] = array('def' => array('id' => 'J1'), 'contrib' => array(), 'lignes' => array());
foreach (array('A' => 8, 'B' => 7, 'C' => 3, 'D' => 3, 'E' => 3) as $nm => $p) {
    $ctxF['etapes']['J1']['lignes'][$idF[$nm]] = array('rang' => 0, 'points_c' => $p * 100,
        'num' => 0, 'den' => 1, 'tie' => '', 'exaequo' => 0, 'retenu' => 1, 'detail' => array());
}
// Qualification : c'est elle qui départage la coupure. Ordre voulu C > D > E.
$mkq = function ($score, $x, $gold, $fleches) {
    $c = selec_contrib_vide();
    $c['score'] = $score; $c['x'] = $x; $c['gold'] = $gold; $c['fleches'] = $fleches;
    return $c;
};
$ctxF['etapes']['Q1'] = array('def' => array('id' => 'Q1'), 'contrib' => array(
    $idF['A'] => $mkq(660, 20, 40, 72), $idF['B'] => $mkq(650, 18, 38, 72),
    $idF['C'] => $mkq(600, 10, 30, 72), $idF['D'] => $mkq(590, 9, 28, 72),
    $idF['E'] => $mkq(580, 8, 26, 72),
), 'lignes' => array());
// Duels : seuls A et B ont tiré.
$ctxF['etapes']['T1'] = array('def' => array('id' => 'T1'), 'contrib' => array(), 'lignes' => array());
foreach (array('A', 'B') as $nm) {
    $c = selec_contrib_vide();
    $c['matchs'] = 3; $c['score'] = 300; $c['fleches'] = 45; $c['victoires'] = 2;
    $ctxF['etapes']['T1']['contrib'][$idF[$nm]] = $c;
}

selec_brique_coupure($ctxF, array('id' => 'CUT', 'type' => 'coupure', 'sources' => array('J1'),
    'retenus' => 2, 'perimetre' => 'tous',
    'departage' => array(array('c' => 'qual_n', 'n' => 1, 'quals' => array('Q1')),
                         array('c' => 'egalite'))));

$cutRangs = array();
foreach ($ctxF['etapes']['CUT']['lignes'] as $id => $l) $cutRangs[$id] = $l['rang'];
t_eq(array(1, 2, 3, 4, 5), array_values($cutRangs), 'la coupure ordonne C, D, E à la qualification');

// Le classement final : la cascade cite la valeur de flèche EN PREMIER, qui
// classerait C, D, E dans le même ordre… mais on veut vérifier que ce n'est PAS
// elle qui décide pour eux. On inverse donc leur valeur de flèche.
$ctxF['etapes']['Q1']['contrib'][$idF['E']]['fleches'] = 60;   // meilleure valeur/flèche
$ctxF['etapes']['Q1']['contrib'][$idF['C']]['fleches'] = 90;   // moins bonne

$stFinal = array('id' => 'FIN', 'type' => 'final', 'sources' => array('J1'), 'perimetre' => 'tous',
    'fige_apres_coupure' => 'CUT',
    'departage' => array(
        array('c' => 'valeur_fleche', 'quals' => array('Q1'), 'etapes' => array('T1')),
        array('c' => 'egalite')));
selec_brique_final($ctxF, $stFinal);
$fin = $ctxF['etapes']['FIN']['lignes'];

t_eq(1, $fin[$idF['A']]['rang'], 'A reste 1er');
t_eq(2, $fin[$idF['B']]['rang'], 'B reste 2e');
t_eq(3, $fin[$idF['C']]['rang'], 'C garde le 3e rang de la coupure malgré sa valeur de flèche');
t_eq(4, $fin[$idF['D']]['rang'], 'D garde le 4e rang de la coupure');
t_eq(5, $fin[$idF['E']]['rang'], 'E garde le 5e rang, sans remonter sur sa valeur de flèche');

// La ligne doit porter la marque du gel : c'est elle qui vide les colonnes des
// étapes non disputées à l'impression.
t_eq('CUT', $fin[$idF['C']]['detail']['fige'], 'la ligne figée nomme la coupure de référence');
t_eq(false, isset($fin[$idF['A']]['detail']['fige']), 'un archer qui a tiré n\'est pas marqué figé');

// Sans l'option, l'ancien comportement demeure : tout le monde passe dans la
// cascade du classement final. C'est le règlement qui décide, pas le code.
unset($ctxF['etapes']['FIN']);
$sansGel = $stFinal;
unset($sansGel['fige_apres_coupure']);
selec_brique_final($ctxF, $sansGel);
t_eq(5, $ctxF['etapes']['FIN']['lignes'][$idF['C']]['rang'],
    'sans l\'option, C retombe au 5e rang par la valeur de flèche');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('15. Départage affiché : seulement là où il a réellement servi');

// Quatre archers, classés au score, départagés aux X puis aux 10.
//   P 660 · Q 650/8X · R 650/6X · S 640
// Q et R sont les SEULS à avoir eu besoin d'un départage. P et S se séparent au
// score, qui est le principe même du classement : leur case doit rester vide.
$sc = array(1 => 660, 2 => 650, 3 => 650, 4 => 640);
$xs = array(1 => 5,   2 => 8,   3 => 6,   4 => 4);
$dx = array(1 => 20,  2 => 18,  3 => 18,  4 => 15);
$cascade = array(
    array('id' => 'score', 'label' => 'Score', 'fn' => function ($i) use ($sc) { return selec_v_int($sc[$i]); }),
    array('id' => 'x',     'label' => 'Nombre de X', 'fn' => function ($i) use ($xs) { return selec_v_int($xs[$i]); }),
    array('id' => 'dix',   'label' => 'Nombre de 10', 'fn' => function ($i) use ($dx) { return selec_v_int($dx[$i]); }),
    array('id' => 'egalite', 'label' => 'Égalité conservée', 'fn' => null),
);
$r = array();
foreach (selec_ranger(array(1, 2, 3, 4), $cascade) as $o) $r[$o['id']] = $o;

t_eq(array(1 => 1, 2 => 2, 3 => 3, 4 => 4),
    array(1 => $r[1]['rang'], 2 => $r[2]['rang'], 3 => $r[3]['rang'], 4 => $r[4]['rang']),
    'rangs 1-2-3-4 au score puis aux X');
t_eq('', $r[1]['tie'], 'le premier, seul sur son score, n\'affiche aucun départage');
t_eq('', $r[4]['tie'], 'le dernier, seul sur son score, n\'affiche aucun départage');
t_eq('x', $r[2]['tie'], 'Q est départagé aux X — et le premier des deux le porte');
t_eq('x', $r[3]['tie'], 'R est départagé aux X');

// Égalité que la cascade ne sépare pas : les deux restent ex aequo, et le disent.
$xs2 = $xs; $xs2[3] = 8; $dx2 = $dx;
$cascade2 = $cascade;
$cascade2[1]['fn'] = function ($i) use ($xs2) { return selec_v_int($xs2[$i]); };
$cascade2[2]['fn'] = function ($i) use ($dx2) { return selec_v_int($dx2[$i]); };
$r2 = array();
foreach (selec_ranger(array(1, 2, 3, 4), $cascade2) as $o) $r2[$o['id']] = $o;
t_eq(2, $r2[3]['rang'], 'Q et R ex aequo au 2e rang');
t_eq('ex aequo', $r2[2]['tie'], 'le premier ex aequo le dit');
t_eq('ex aequo', $r2[3]['tie'], 'le second ex aequo le dit');
t_eq('', $r2[1]['tie'], 'le premier au score reste sans mention');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('16. Place manquante : ne rien signaler tant que la consolante n\'a pas parlé');

// Bug réel corrigé en 0.6.4 : les 4 perdants de quarts étaient signalés « place
// non déterminée — départage manuel requis » sur CHAQUE tournoi, alors que la
// consolante les classait 5 à 8 juste après. Quatre fausses alertes par tournoi.
$sansPlace = array(
    101 => array('phase' => 4, 'event' => 'HCLT1'),
    102 => array('phase' => 4, 'event' => 'HCLT1'),
    103 => array('phase' => 4, 'event' => 'HCLT1'),
    104 => array('phase' => 4, 'event' => 'HCLT1'),
);

// Tableau principal seul : personne n'est encore classé 5-8, mais on ne dit rien.
$placesPrincipal = array(201 => 1, 202 => 2, 203 => 3, 204 => 4);
$manque = selec_places_manquantes($placesPrincipal, $sansPlace);
t_eq(4, count($manque), 'sans consolante, les quatre éliminés restent effectivement sans place');

// Une fois la consolante fusionnée, plus aucune alerte : c'est le cas NORMAL.
$placesFusion = $placesPrincipal + array(101 => 5, 102 => 6, 103 => 7, 104 => 8);
t_eq(array(), selec_places_manquantes($placesFusion, $sansPlace),
    'consolante lue : aucun archer sans place, donc aucune alerte');

// Consolante partielle (2 places sur 4) : on ne signale QUE les deux restants.
$partiel = $placesPrincipal + array(101 => 5, 102 => 6);
$manque = selec_places_manquantes($partiel, $sansPlace);
t_eq(array(103, 104), array_keys($manque), 'seuls les archers réellement sans place sont signalés');

// Le message doit nommer le tour, pas le nombre brut de GrPhase : « en phase 4 »
// n'apprend rien à un arbitre.
t_eq('en finale',                  selec_label_phase(0), 'libellé de phase — finale');
t_eq('au match pour la 3e place',  selec_label_phase(1), 'libellé de phase — bronze');
t_eq('en demi-finales',            selec_label_phase(2), 'libellé de phase — demies');
t_eq('en quarts de finale',        selec_label_phase(4), 'libellé de phase — quarts');
t_eq('en 1/8 de finale',           selec_label_phase(8), 'libellé de phase — huitièmes');

// ═════════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 70) . "\n";
if ($KO === 0) {
    echo "TOUS LES TESTS PASSENT — $OK vérifications.\n";
} else {
    echo "$KO ÉCHEC(S) sur " . ($OK + $KO) . " vérifications :\n";
    foreach ($ECHECS as $e) echo "$e\n";
}
exit($KO === 0 ? 0 : 1);

