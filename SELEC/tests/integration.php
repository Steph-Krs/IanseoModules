<?php
/**
 * tests/integration.php — vérifie la LECTURE des données ianseo sur la vraie base.
 *
 *   c:\ianseo\php\php.exe htdocs/Modules/Custom/SELEC/tests/integration.php [ToId] [EvCode]
 *
 * Ce banc ne teste pas les points (c'est le rôle de run.php) mais le pont avec
 * ianseo : décodage des flèches, lecture des qualifications distance par
 * distance, lecture des matchs et reconstruction du classement d'un tableau.
 * Il compare systématiquement ce que le module lit à ce que ianseo a lui-même
 * stocké — un écart signalé vaut mieux qu'un classement faux.
 *
 * Écritures : uniquement la création des tables SELEC_* (aucune donnée ianseo
 * n'est modifiée, aucun résultat n'est enregistré).
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$HOST = 'localhost'; $USER = 'ianseo'; $PASS = 'ianseo'; $DB = 'ianseo';

// ianseo utilise DEUX connexions distinctes (lecture / écriture) : on reproduit
// la même architecture, sinon des pièges comme LAST_INSERT_ID() restent invisibles.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$READ = new mysqli($HOST, $USER, $PASS, $DB);
$WRIT = new mysqli($HOST, $USER, $PASS, $DB);
$READ->set_charset('utf8mb4');
$WRIT->set_charset('utf8mb4');
// Même mode SQL que les connexions de ianseo (Common/Fun_DB.inc.php:107).
foreach (array($READ, $WRIT) as $cnx) {
    $cnx->query("SET session sql_mode = 'NO_UNSIGNED_SUBTRACTION'");
    $cnx->query("SET session optimizer_search_depth = 0");
}

function safe_r_sql($q)      { global $READ; return $READ->query($q); }
function safe_w_sql($q)      { global $WRIT; return $WRIT->query($q); }
function safe_fetch($r)      { return $r ? $r->fetch_object() : false; }
function safe_num_rows($r)   { return $r ? $r->num_rows : 0; }
function safe_w_last_id()    { global $WRIT; return $WRIT->insert_id; }
function StrSafe_DB($v)      { global $READ; return "'" . $READ->real_escape_string((string) $v) . "'"; }

$_SESSION = array();

require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/donnees.php';
require_once __DIR__ . '/../lib/classement.php';
require_once __DIR__ . '/../lib/moteur.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/archive.php';
require_once __DIR__ . '/../lib/sessions.php';

$OK = 0; $KO = 0; $ECHECS = array();
function t_eq($a, $o, $q) {
    global $OK, $KO, $ECHECS;
    if ($a === $o) { $OK++; return true; }
    $KO++; $ECHECS[] = "  ✗ $q : attendu " . var_export($a, true) . ", obtenu " . var_export($o, true);
    return false;
}
function t_vrai($c, $q) { return t_eq(true, (bool) $c, $q); }
function t_titre($s) { echo "\n── $s\n"; }

$ToId   = intval($argv[1] ?? 617);
$EvCode = (string) ($argv[2] ?? 'EFCL');

// ═════════════════════════════════════════════════════════════════════════════
t_titre("Schéma SELEC_*");
selec_schema();
foreach (array('SELEC_Config', 'SELEC_Bind', 'SELEC_Results', 'SELEC_Shootoff', 'SELEC_Log') as $t) {
    $rs = safe_r_sql("SELECT COUNT(*) n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . StrSafe_DB($t));
    $r = safe_fetch($rs);
    t_eq(1, intval($r->n), "table $t créée");
}
// Idempotence : rejouer ne doit rien casser.
unset($_SESSION['_selec_schema_v' . SELEC_SCHEMA_VERSION]);
selec_schema();
$OK++;
echo "  ✓ selec_schema() rejouée sans erreur\n";

// ═════════════════════════════════════════════════════════════════════════════
t_titre("Compétition $ToId / épreuve $EvCode");
$tour = selec_tournoi($ToId);
t_vrai($tour !== null, 'compétition lue');
if (!$tour) { echo "Compétition $ToId absente — test interrompu.\n"; exit(1); }
echo "  · {$tour['nom']} — {$tour['nb_dist']} distance(s), "
   . "{$tour['fleches_dist']} flèches/distance, X='{$tour['chars_x']}', 10='{$tour['chars_gold']}'\n";

$cats = selec_categories($ToId);
t_vrai(count($cats) > 0, 'épreuves individuelles lues');
t_vrai(isset($cats[$EvCode]), "épreuve $EvCode présente");
if (!isset($cats[$EvCode])) {
    echo "  Épreuves disponibles : " . implode(', ', array_slice(array_keys($cats), 0, 20)) . "\n";
    exit(1);
}

$archers = selec_archers($ToId, $EvCode);
echo "  · " . count($archers) . " archers dans $EvCode\n";
t_vrai(count($archers) > 0, 'archers lus');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Qualifications : le décodage des flèches doit reproduire les totaux ianseo');
$quals = selec_quals($ToId, array_keys($archers));
t_eq(count($archers), count($quals), 'une ligne de qualification par archer');

$ecarts = 0; $verifiees = 0; $sommeOk = 0; $sommeKo = 0;
foreach ($quals as $id => $q) {
    $tScore = 0; $tGold = 0; $tX = 0;
    for ($d = 1; $d <= $tour['nb_dist']; $d++) {
        if (empty($q[$d])) continue;
        if ($q[$d]['controle'] !== '') { $ecarts++; echo "  ! archer $id dist $d : {$q[$d]['controle']}\n"; }
        if ($q[$d]['fleches'] > 0) $verifiees++;
        $tScore += $q[$d]['score']; $tGold += $q[$d]['gold']; $tX += $q[$d]['x'];
    }
    // La somme des distances doit égaler le total stocké par ianseo.
    if ($tScore === intval($q['_total']['score']) && $tGold === intval($q['_total']['gold'])
        && $tX === intval($q['_total']['x'])) $sommeOk++; else $sommeKo++;
}
echo "  · $verifiees distances décodées flèche à flèche\n";
t_eq(0, $ecarts, 'aucun écart entre les flèches décodées et les totaux ianseo');
t_eq(0, $sommeKo, 'somme des distances = total de qualification ianseo (' . $sommeOk . ' archers)');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Classement de qualification : même ordre que celui de ianseo');
// ianseo range les archers de l'épreuve dans Individuals.IndRank : le module doit
// retrouver le même ordre sur score / X / 10 (sa cascade par défaut).
$ctx = array('tour' => $ToId, 'cat' => $EvCode, 'binds' => array(), 'barrages' => array(),
    'archers' => $archers, 'quals' => $quals, 'etapes' => array(), 'alertes' => array(),
    'mode' => array('baremes' => array('R8' => array('type' => 'rang', 'points' => array(8,7,6,5,4,3,2,1)))));
$dists = range(1, max(1, $tour['nb_dist']));
selec_brique_qualification($ctx, array('id' => 'Q', 'type' => 'qualification',
    'distances' => $dists, 'bareme' => 'R8', 'perimetre' => 'tous',
    'departage' => array(array('c' => 'x', 'quals' => array('Q')),
                         array('c' => 'dix', 'quals' => array('Q')),
                         array('c' => 'egalite'))));

$rs = safe_r_sql("SELECT IndId, IndRank FROM Individuals
    WHERE IndTournament=$ToId AND IndEvent=" . StrSafe_DB($EvCode) . " AND IndRank>0");
$ianseo = array();
while ($r = safe_fetch($rs)) $ianseo[intval($r->IndId)] = intval($r->IndRank);

if (!$ianseo) {
    echo "  · ianseo n'a pas encore calculé de classement pour cette épreuve — comparaison sautée\n";
} else {
    $diff = 0; $compares = 0;
    foreach ($ianseo as $id => $rangI) {
        if (!isset($ctx['etapes']['Q']['lignes'][$id])) continue;
        $compares++;
        if ($ctx['etapes']['Q']['lignes'][$id]['rang'] !== $rangI) {
            $diff++;
            if ($diff <= 5) {
                echo "  ! archer $id : ianseo $rangI, module "
                   . $ctx['etapes']['Q']['lignes'][$id]['rang'] . "\n";
            }
        }
    }
    echo "  · $compares archers comparés\n";
    t_eq(0, $diff, 'rangs identiques à ceux de ianseo');
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Matchs : sets, totaux et vainqueurs');
$matchs = selec_matchs($ToId, array($EvCode));
$nb = 0; $incoh = 0; $sansSets = 0;
foreach ($matchs as $id => $lot) {
    foreach ($lot as $m) {
        $nb++;
        if ($m['set_nb'] === 0) { $sansSets++; continue; }
        // FinScore doit être la somme des scores de set (le barrage est à part).
        if ($m['score'] > 0 && $m['score'] !== $m['set_total']) {
            $incoh++;
            if ($incoh <= 5) echo "  ! {$m['event']}#{$m['matchno']} : score {$m['score']} ≠ sets {$m['set_total']}\n";
        }
    }
}
echo "  · $nb lignes de match lues ($sansSets sans set joué)\n";
t_eq(0, $incoh, 'total de match = somme des sets sur toutes les lignes jouées');

// Chaque match joué a exactement un vainqueur et un perdant.
$paires = array();
foreach ($matchs as $id => $lot) {
    foreach ($lot as $cle => $m) {
        if ($m['gagne'] === null) continue;
        $p = min($m['matchno'], $m['matchno'] % 2 ? $m['matchno'] - 1 : $m['matchno'] + 1);
        $paires[$m['event'] . '|' . $p][] = $m['gagne'];
    }
}
$mauvais = 0;
foreach ($paires as $cle => $g) {
    if (count($g) === 2 && array_sum($g) !== 1) $mauvais++;
}
t_eq(0, $mauvais, 'un seul vainqueur par match');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Classement d\'un tableau de duels');
$cl = selec_classement_tableau($ToId, $EvCode);
echo "  · " . count($cl['rangs']) . " place(s) déterminée(s) par finale et petite finale\n";
echo "  · " . count($cl['sans_place']) . " archer(s) éliminés avant — leur place vient de la consolante\n";
// Un tableau de plus de 4 archers laisse forcément des éliminés sans place :
// c'est un constat, pas une anomalie. `incomplet` ne doit donc rien contenir.
t_eq(0, count($cl['incomplet']), 'un tableau complet ne produit aucune alerte');
if ($cl['rangs']) {
    $vus = array_count_values(array_values($cl['rangs']));
    $doublons = 0;
    foreach ($vus as $rang => $n) if ($n > 1) $doublons++;
    t_eq(0, $doublons, 'aucune place attribuée deux fois');
    t_vrai(in_array(1, $cl['rangs'], true), 'une première place existe');
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Bornage des écritures : aucune ne doit pouvoir déborder d\'une compétition');

// `Qualifications` n'a PAS de colonne de compétition : c'est la seule table où
// un WHERE mal borné toucherait toute la base. Aujourd'hui les identifiants
// viennent déjà d'une lecture bornée, donc un débordement ne se verrait pas à
// l'exécution — d'où ce contrôle sur le CODE, qui échouera le jour où quelqu'un
// retirera la jointure.
$libs = glob(__DIR__ . '/../lib/*.php');
$sansJointure = array();
$sansBorne = array();
foreach ($libs as $lib) {
    $src = (string) file_get_contents($lib);
    $nom = basename($lib);

    // Chaque appel d'écriture, découpé grossièrement sur la parenthèse fermante
    // suivie d'un point-virgule : suffisant pour isoler une requête.
    if (preg_match_all('/safe_w_sql\s*\((.*?)\)\s*;/is', $src, $m)) {
        foreach ($m[1] as $req) {
            $plat = preg_replace('/\s+/', ' ', $req);

            // Écriture sur Qualifications : la jointure sur Entries est la SEULE
            // borne possible.
            if (preg_match('/\b(UPDATE|DELETE\s+FROM)\s+Qualifications\b/i', $plat)
                && !preg_match('/\bEntries\b/i', $plat)) {
                $sansJointure[] = $nom . ' : ' . mb_substr($plat, 0, 70);
            }

            // Toute autre écriture sur une table de ianseo ou du module doit
            // porter une borne de compétition quelque part dans la requête.
            if (preg_match('/\b(UPDATE|DELETE\s+FROM)\s+([A-Za-z_]+)/i', $plat, $t)) {
                $table = $t[2];
                $exemptes = array('Qualifications');   // traitée juste au-dessus
                if (!in_array($table, $exemptes, true)
                    && !preg_match('/Tournament|TourId|ToId|\$tourId|\$tour\b|SlTournament/i', $plat)) {
                    $sansBorne[] = $nom . ' : ' . mb_substr($plat, 0, 70);
                }
            }
        }
    }
}
foreach ($sansJointure as $x) echo "  ! $x\n";
foreach ($sansBorne as $x)    echo "  ! $x\n";
t_eq(array(), $sansJointure, 'toute écriture sur Qualifications joint Entries');
t_eq(array(), $sansBorne, 'toute autre écriture porte une borne de compétition');

// Le module ne modifie jamais le schéma de ianseo : selec_colonne() refuse
// toute table qui ne lui appartient pas.
t_eq(false, selec_colonne('Entries', 'EnHack', 'INT'), 'ALTER refusé sur une table de ianseo');
t_eq(false, selec_colonne('SELEC_Config; DROP TABLE Entries', 'x', 'INT'), 'nom de table injecté refusé');
t_eq(false, selec_colonne('SELEC_Config', 'a`b', 'INT'), 'nom de colonne douteux refusé');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('10 et X d\'un duel : ianseo ne les compte pas, on les relit des flèches');

// Les compteurs de 10 et de X de ianseo n'existent que pour les qualifications.
// Le règlement départage pourtant les duels simulés aux X puis aux 10 : la seule
// source est la chaîne de flèches du match.
$tourD = selec_tournoi($ToId);
t_vrai($tourD['chars_gold'] !== '', 'la compétition déclare ses caractères de 10');
t_vrai($tourD['chars_x'] !== '',    'la compétition déclare ses caractères de X');

// K = X, L = 10, J = 9, A = manqué, espace = non tirée. Les caractères viennent
// de la compétition, jamais d'une liste écrite en dur.
$faux = array('val_max' => 10, 'chars_gold' => 'KL', 'chars_x' => 'K');
$d = selec_decoder_fleches('KLJKA ', $faux);
t_eq(5,  $d['fleches'], 'cinq flèches tirées, l\'espace n\'en est pas une');
t_eq(39, $d['score'],   'score décodé : 10+10+9+10+0');
t_eq(3,  $d['gold'],    'trois 10 — les X en font partie');
t_eq(2,  $d['x'],       'deux X, comptés à part');
t_eq(0,  selec_decoder_fleches('', $faux)['fleches'], 'chaîne vide : rien de lu');

// Une chaîne PARTIELLE ne doit jamais passer pour un comptage valable : elle
// donnerait un nombre de X trop bas sans que rien ne le signale. C'est la
// comparaison de son total au score du match qui fait foi.
$dPartiel = selec_decoder_fleches('KLJ', $faux);
t_eq(29, $dPartiel['score'], 'trois flèches seulement');
t_vrai($dPartiel['score'] !== 110, 'un total qui ne colle pas au match trahit une chaîne incomplète');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Libellés des critères de départage, sans contexte');

// Les en-têtes de colonnes du classement final sont posés avant toute lecture de
// base : ils doivent pouvoir être obtenus d'une définition seule, et dire
// EXACTEMENT ce que dit le pied de page, qui passe par selec_critere().
t_eq('Valeur moyenne de flèche', selec_critere_label(array('c' => 'valeur_fleche')), 'libellé — valeur de flèche');
t_eq('Meilleure qualification',  selec_critere_label(array('c' => 'qual_n', 'n' => 1)), 'libellé — meilleure qualification');
t_eq('3e meilleure qualification', selec_critere_label(array('c' => 'qual_n', 'n' => 3)), 'libellé — 3e qualification');
t_eq('Nombre de victoires',      selec_critere_label(array('c' => 'victoires')), 'libellé — victoires');
t_eq('Égalité conservée',        selec_critere_label(array('c' => 'egalite')), 'libellé — égalité');
t_eq('', selec_critere_label(array('c' => 'critere_qui_nexiste_pas')), 'critère inconnu — pas de libellé inventé');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Verrouillage ISK-NG : les bonnes clés, et rien que celles-là');

// Le format des clés appartient à ianseo (Api/ISK-NG/Lib.php) : on vérifie qu'on
// sait les trier par étape, pas qu'on sait les fabriquer — les fabriquer serait
// justement l'erreur, elles changeraient à la première mise à jour.
$fauxSessions = array(
    'Q|1|1'          => array('type' => 'Q', 'distance' => 1, 'libelle' => 'Départ 1'),
    'Q|1|2'          => array('type' => 'Q', 'distance' => 2, 'libelle' => 'Départ 1'),
    'Q|2|3'          => array('type' => 'Q', 'distance' => 3, 'libelle' => 'Départ 2'),
    'I|4|HCLT1'      => array('type' => 'I', 'distance' => 4, 'libelle' => 'Tournoi 1'),
    'I|0|HCLT1'      => array('type' => 'I', 'distance' => 0, 'libelle' => 'Tournoi 1'),
    'I|2|HCLT1B'     => array('type' => 'I', 'distance' => 2, 'libelle' => 'Consolante 1'),
    'I|4|HCLT2'      => array('type' => 'I', 'distance' => 4, 'libelle' => 'Tournoi 2'),
    'R|1|1|1|HCLP1'  => array('type' => 'R|1|1', 'distance' => 1, 'libelle' => 'Poule'),
);

$stQ = array('id' => 'Q1', 'type' => 'qualification', 'distances' => array(1, 2));
t_eq(array('Q|1|1', 'Q|1|2'), selec_lock_cles($stQ, array(), $fauxSessions),
    'une qualification prend ses distances, pas celles du départ voisin');

$stT = array('id' => 'T1', 'type' => 'tournoi');
t_eq(array('I|4|HCLT1', 'I|0|HCLT1', 'I|2|HCLT1B'),
    selec_lock_cles($stT, array('HCLT1', 'HCLT1B'), $fauxSessions),
    'un tournoi prend toutes les phases de son tableau ET de sa consolante');

$stP = array('id' => 'P1', 'type' => 'poule');
t_eq(array('R|1|1|1|HCLP1'), selec_lock_cles($stP, array('HCLP1'), $fauxSessions),
    'une poule prend ses tours de round robin');

// Garde-fou : un code d'épreuve numérique ne doit jamais ramasser une session de
// qualification, dont la clé finit elle aussi par un nombre.
t_eq(array(), selec_lock_cles($stT, array('1', '2', '3'), $fauxSessions),
    'un code d\'épreuve numérique n\'attrape aucune session de qualification');

// Une étape de calcul n'a rien à verrouiller : aucun bouton ne doit apparaître.
t_eq(array(), selec_lock_cles(array('id' => 'J1', 'type' => 'journee'), array(), $fauxSessions),
    'une journée ne porte aucune session');

// Pages de vérification : c'est la façon de TIRER qui décide, pas le type.
t_eq('Modules/Barcodes/GetScoreBarCode.php', selec_verif_page($stQ), 'qualification → code-barres qualification');
t_eq('Modules/Barcodes/GetFinScoreBarCode.php', selec_verif_page($stT), 'tournoi → code-barres duels');
t_eq('Modules/Barcodes/GetRobinScoreBarCode.php', selec_verif_page($stP), 'poule → code-barres round robin');
t_eq('', selec_verif_page(array('id' => 'J1', 'type' => 'journee')), 'une journée ne se vérifie pas, elle se calcule');
t_eq('Modules/Barcodes/GetScoreBarCode.php',
    selec_verif_page(array('id' => 'MS', 'type' => 'duels_simules',
        'source' => array('type' => 'distances', 'distances' => array(5)))),
    'des duels simulés tirés comme un round se vérifient en qualification');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Chaîne complète : ancrage → rattachement → calcul → enregistrement');
// N'écrit que dans les tables SELEC_*, et nettoie derrière lui. Utilise un mode
// de test taillé pour la compétition réelle (une qualification sur ses distances,
// un tournoi sur son tableau) : l'objectif est le CHEMIN, pas les points.

$avant = array();
foreach (array('SELEC_Config' => 'ScTournament', 'SELEC_Bind' => 'SbTournament',
               'SELEC_Results' => 'SrTournament', 'SELEC_Log' => 'SlTournament') as $t => $col) {
    $rs = safe_r_sql("SELECT COUNT(*) n FROM $t WHERE $col=$ToId");
    $avant[$t] = intval(safe_fetch($rs)->n);
}
if (array_sum($avant) > 0) {
    echo "  · la compétition $ToId porte déjà des données SELEC — section sautée pour ne rien écraser\n";
} else {
    $modeTest = array(
        'id' => 'TEST_INTEGRATION', 'libelle' => 'Mode de test', 'version' => '0.0.1',
        'familles' => array('QUAL' => 'Points de Qualification', 'TOURN' => 'Points de Tournoi'),
        'baremes' => array(
            'R8' => array('type' => 'rang', 'points' => array(8, 7, 6, 5, 4, 3, 2, 1)),
            'P8' => array('type' => 'rang', 'points' => array(4, 3.5, 3, 2.5, 2, 1.5, 1, 0.5)),
        ),
        'journees' => array('J1' => 'Journée de test'),
        'etapes' => array(
            array('id' => 'Q1', 'type' => 'qualification', 'journee' => 'J1', 'famille' => 'QUAL',
                'libelle' => 'Qualification', 'distances' => $dists, 'bareme' => 'R8',
                'perimetre' => 'tous',
                'departage' => array(array('c' => 'x', 'quals' => array('Q1')),
                                     array('c' => 'dix', 'quals' => array('Q1')),
                                     array('c' => 'egalite'))),
            array('id' => 'T1', 'type' => 'tournoi', 'journee' => 'J1', 'famille' => 'TOURN',
                'libelle' => 'Tableau', 'slots' => array('principal'),
                'bareme_classement' => 'R8', 'bareme_performance' => 'P8', 'bareme' => 'R8',
                'departage_performance' => array(array('c' => 'egalite')),
                'departage' => array(array('c' => 'egalite'))),
            array('id' => 'FINAL', 'type' => 'final', 'journee' => 'J1',
                'libelle' => 'Classement', 'sources' => array('Q1', 'T1'), 'perimetre' => 'tous',
                'departage' => array(
                    array('c' => 'valeur_fleche', 'quals' => array('Q1'), 'etapes' => array('T1')),
                    array('c' => 'egalite'))),
        ),
    );
    t_eq(array(), selec_mode_valider($modeTest), 'mode de test cohérent');

    // Ancrage direct (selec_config_ancrer ne lit que le catalogue livré).
    $now = date('Y-m-d H:i:s');
    safe_w_sql("INSERT INTO SELEC_Config SET ScTournament=$ToId,
        ScMode=" . StrSafe_DB($modeTest['id']) . ", ScModeVer='0.0.1',
        ScSnapshot=" . StrSafe_DB(json_encode($modeTest, JSON_UNESCAPED_UNICODE)) . ",
        ScOptions=" . StrSafe_DB(json_encode(array('categories' => array($EvCode)))) . ",
        ScSnapDate=" . StrSafe_DB($now) . ", ScUpdated=" . StrSafe_DB($now));

    $lu = selec_config_lire($ToId);
    t_vrai(is_array($lu['snapshot']), 'snapshot relu depuis la base');
    t_eq($modeTest['id'], $lu['snapshot']['id'], 'snapshot fidèle (identifiant)');
    t_eq(count($modeTest['etapes']), count($lu['snapshot']['etapes']), 'snapshot fidèle (étapes)');
    t_eq(array($EvCode), selec_categories_actives($ToId, $lu), 'catégorie active retenue');

    selec_bind_ecrire($ToId, $EvCode, 'T1', 'principal', $EvCode);
    $b = selec_binds_lire($ToId, $EvCode);
    t_eq($EvCode, $b['T1']['principal'] ?? '', 'rattachement relu');

    $ctxI = selec_calculer($ToId, $EvCode, $lu['snapshot'], $b);
    t_eq(count($archers), count($ctxI['etapes']['Q1']['lignes']), 'qualification calculée pour tous');
    t_vrai(!empty($ctxI['etapes']['T1']['lignes']), 'tournoi calculé');
    t_vrai(!empty($ctxI['etapes']['FINAL']['lignes']), 'classement final calculé');

    $n = selec_enregistrer($ctxI);
    t_vrai($n > 0, "$n lignes enregistrées");

    // Relecture : rangs et points doivent survivre à l'aller-retour en base.
    $rs = safe_r_sql("SELECT SrStep, SrEntry, SrRank, SrPointsC, SrValueNum, SrValueDen, SrExAequo
        FROM SELEC_Results WHERE SrTournament=$ToId AND SrCategory=" . StrSafe_DB($EvCode));
    $relu = array(); while ($r = safe_fetch($rs)) $relu[$r->SrStep][intval($r->SrEntry)] = $r;
    $diff = 0;
    foreach ($ctxI['etapes'] as $sid => $et) {
        foreach ($et['lignes'] as $id => $l) {
            $r = $relu[$sid][$id] ?? null;
            if (!$r || intval($r->SrRank) !== $l['rang'] || intval($r->SrPointsC) !== $l['points_c']
                || intval($r->SrValueNum) !== intval($l['num'])) $diff++;
        }
    }
    t_eq(0, $diff, 'aller-retour en base sans perte (rang, points, valeur exacte)');

    // Idempotence : recalculer et réenregistrer donne strictement la même chose.
    $ctxI2 = selec_calculer($ToId, $EvCode, $lu['snapshot'], $b);
    $n2 = selec_enregistrer($ctxI2);
    t_eq($n, $n2, 'second enregistrement : même nombre de lignes');
    $diff2 = 0;
    foreach ($ctxI['etapes'] as $sid => $et) {
        foreach ($et['lignes'] as $id => $l) {
            $l2 = $ctxI2['etapes'][$sid]['lignes'][$id] ?? null;
            if (!$l2 || $l2['rang'] !== $l['rang'] || $l2['points_c'] !== $l['points_c']) $diff2++;
        }
    }
    t_eq(0, $diff2, 'calcul idempotent');

    // Nettoyage : on ne laisse aucune trace sur la compétition réelle.
    foreach (array('SELEC_Results' => 'SrTournament', 'SELEC_Bind' => 'SbTournament',
                   'SELEC_Config' => 'ScTournament', 'SELEC_Log' => 'SlTournament') as $t => $col) {
        safe_w_sql("DELETE FROM $t WHERE $col=$ToId");
    }
    $reste = 0;
    foreach (array('SELEC_Config' => 'ScTournament', 'SELEC_Bind' => 'SbTournament',
                   'SELEC_Results' => 'SrTournament', 'SELEC_Log' => 'SlTournament') as $t => $col) {
        $rs = safe_r_sql("SELECT COUNT(*) n FROM $t WHERE $col=$ToId");
        $reste += intval(safe_fetch($rs)->n);
    }
    t_eq(0, $reste, 'nettoyage complet après le test');
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Catalogue des modes');
foreach (selec_modes_catalogue() as $id => $m) {
    $mode = selec_mode_charger($id);
    $err = selec_mode_valider($mode);
    if ($err) { $KO += count($err); foreach ($err as $e) $ECHECS[] = "  ✗ $id : $e"; }
    else { $OK++; echo "  ✓ $id — cohérent\n"; }
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 70) . "\n";
if ($KO === 0) echo "INTÉGRATION OK — $OK vérifications sur la base réelle.\n";
else {
    echo "$KO ÉCHEC(S) sur " . ($OK + $KO) . " vérifications :\n";
    foreach ($ECHECS as $e) echo "$e\n";
}
exit($KO === 0 ? 0 : 1);
