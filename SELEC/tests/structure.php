<?php
/**
 * tests/structure.php — vérifie le déploiement du set et la génération de structure
 * sur la VRAIE base, dans une compétition jetable créée puis supprimée.
 *
 *   c:\ianseo\php\php.exe htdocs/Modules/Custom/SELEC/tests/structure.php
 *
 * Écritures : le set dans Modules/Sets/SELEC/, la ligne TourTypes du type
 * « Sélection », les libellés dans Common/Languages/, et une compétition d'essai
 * (identifiant très haut) intégralement supprimée à la fin. Aucune compétition
 * réelle n'est touchée.
 *
 * ⚠ Effet de bord assumé : les archers d'essai sont insérés avec un identifiant
 * explicite (original + 9 000 000) pour pouvoir être retrouvés et supprimés à
 * coup sûr. MySQL fait alors monter l'auto-increment d'`Entries` et de
 * `Qualifications` au-delà de 9 millions, définitivement. Sans conséquence
 * (la colonne est un INT UNSIGNED, plafond 4,29 milliards) mais à savoir : après
 * un passage de ce banc, les nouvelles inscriptions de la base portent des
 * identifiants à 7 chiffres. Ne jamais chercher les résidus de test par
 * « QuId > 9000000 » : ce critère attrape désormais des lignes légitimes.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$HOST = 'localhost'; $USER = 'ianseo'; $PASS = 'ianseo'; $DB = 'ianseo';
$TOUR = 999901;   // compétition jetable

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$READ = new mysqli($HOST, $USER, $PASS, $DB);
$WRIT = new mysqli($HOST, $USER, $PASS, $DB);
$READ->set_charset('utf8mb4'); $WRIT->set_charset('utf8mb4');
// ianseo pose ce mode SQL sur SES deux connexions (Common/Fun_DB.inc.php:107).
// Sans lui, une requête du cœur comme move2NextPhase() échoue en ligne de
// commande sur « FinMatchNo - 1 » alors qu'elle passe très bien sur le site :
// un banc de test qui ne le reproduit pas ment.
foreach (array($READ, $WRIT) as $cnx) {
    $cnx->query("SET session sql_mode = 'NO_UNSIGNED_SUBTRACTION'");
    $cnx->query("SET session optimizer_search_depth = 0");
}

function safe_r_sql($q)    { global $READ; return $READ->query($q); }
function safe_w_sql($q)    { global $WRIT; return $WRIT->query($q); }
function safe_fetch($r)    { return $r ? $r->fetch_object() : false; }
function safe_num_rows($r) { return $r ? $r->num_rows : 0; }
function safe_w_last_id()  { global $WRIT; return $WRIT->insert_id; }
function safe_w_affected_rows() { global $WRIT; return $WRIT->affected_rows; }
function safe_free_result($r) { if ($r instanceof mysqli_result) $r->free(); }
function safe_error($m)       { throw new Exception($m); }
function StrSafe_DB($v)    { global $READ; return "'" . $READ->real_escape_string((string) $v) . "'"; }
function IsBlocked($b)     { return false; }
if (!defined('BIT_BLOCK_TOURDATA')) define('BIT_BLOCK_TOURDATA', 1);

$_SESSION = array('TourId' => $TOUR, 'TourLocRule' => 'SELEC', 'TourType' => 0,
    'TourLocSubRule' => 'SelecTAECL2027E1');
$CFG = new stdClass();
$CFG->DOCUMENT_PATH = realpath(__DIR__ . '/../../../..') . DIRECTORY_SEPARATOR;
$CFG->INCLUDE_PATH  = rtrim($CFG->DOCUMENT_PATH, DIRECTORY_SEPARATOR);
$CFG->LANGUAGE_PATH = $CFG->DOCUMENT_PATH . 'Common' . DIRECTORY_SEPARATOR . 'Languages' . DIRECTORY_SEPARATOR;
$CFG->ROOT_DIR = '/';
// Les fichiers du cœur de ianseo s'incluent entre eux en chemin relatif à
// htdocs ; en ligne de commande, c'est config.php qui pose normalement ce
// chemin d'inclusion.
ini_set('include_path', get_include_path() . PATH_SEPARATOR . rtrim($CFG->DOCUMENT_PATH, DIRECTORY_SEPARATOR));

require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/donnees.php';
require_once __DIR__ . '/../lib/classement.php';
require_once __DIR__ . '/../lib/moteur.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/structure.php';
require_once __DIR__ . '/../lib/selfheal.php';

$OK = 0; $KO = 0; $ECHECS = array();
function t_eq($a, $o, $q) {
    global $OK, $KO, $ECHECS;
    if ($a === $o) { $OK++; return true; }
    $KO++; $ECHECS[] = "  ✗ $q : attendu " . var_export($a, true) . ", obtenu " . var_export($o, true);
    return false;
}
function t_vrai($c, $q) { return t_eq(true, (bool) $c, $q); }
function t_titre($s) { echo "\n── $s\n"; }

function nettoyer($TOUR) {
    // Qualifications N'A PAS de colonne de compétition : la suppression DOIT
    // joindre Entries, et passer AVANT la suppression des Entries elles-mêmes.
    // Sans cette jointure, un filtre géométrique toucherait toute la base.
    try {
        safe_w_sql("DELETE q FROM Qualifications q
            INNER JOIN Entries e ON e.EnId=q.QuId
            WHERE e.EnTournament=" . intval($TOUR));
    } catch (Exception $e) {}
    foreach (array(
        'Session' => 'SesTournament', 'DistanceInformation' => 'DiTournament',
        'Events' => 'EvTournament', 'EventClass' => 'EcTournament', 'Finals' => 'FinTournament',
        'Divisions' => 'DivTournament', 'Classes' => 'ClTournament', 'SubClass' => 'ScTournament',
        'Individuals' => 'IndTournament', 'Entries' => 'EnTournament',
        'TournamentDistances' => 'TdTournament', 'TargetFaces' => 'TfTournament',
        'SELEC_Bind' => 'SbTournament', 'SELEC_Config' => 'ScTournament',
        'SELEC_Results' => 'SrTournament', 'SELEC_Log' => 'SlTournament',
        'SELEC_Archive' => 'SaTournament', 'SELEC_Shootoff' => 'SoTournament',
        'FinSchedule' => 'FSTournament',
        'Tournament' => 'ToId',
    ) as $t => $col) {
        try { safe_w_sql("DELETE FROM `$t` WHERE `$col`=" . intval($TOUR)); } catch (Exception $e) {}
    }
}

selec_schema();
nettoyer($TOUR);   // au cas où un essai précédent aurait laissé des traces

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Déploiement du set « Sélection »');
$sh = selec_selfheal(true);
foreach ($sh['erreurs'] as $e) echo "  ! $e\n";
t_vrai($sh['ok'], 'auto-réparation sans erreur');
t_vrai($sh['type'] > 0, 'type de compétition créé (n° ' . $sh['type'] . ')');

$dst = $CFG->DOCUMENT_PATH . 'Modules/Sets/SELEC';
t_vrai(is_file($dst . '/sets.php'), 'Modules/Sets/SELEC/sets.php déployé');
t_vrai(is_file($dst . '/Setup_' . $sh['type'] . '_SELEC.php'), 'fichier de setup déployé');

$rs = safe_r_sql("SELECT TtId, TtType, TtDistance FROM TourTypes WHERE TtType='Type_FR_Selection'");
$tt = safe_fetch($rs);
t_vrai($tt !== false, 'ligne TourTypes présente');
if ($tt) t_eq(8, intval($tt->TtDistance), 'le type déclare 8 distances');

// Libellés : sans eux, ianseo affiche « [[SelecTAECL2027E1]@[fr]@[Install]] ».
$lg = (string) @file_get_contents($CFG->LANGUAGE_PATH . 'fr/Install.php');
t_vrai(strpos($lg, "\$lang['SelecTAECL2027E1']") !== false, 'libellé de la sous-règle 1 injecté');
t_vrai(strpos($lg, "\$lang['Setup-SELEC']") !== false, 'libellé du set injecté');
$lgT = (string) @file_get_contents($CFG->LANGUAGE_PATH . 'fr/Tournament.php');
t_vrai(strpos($lgT, "\$lang['Type_FR_Selection']") !== false, 'libellé du type injecté');

// Idempotence : rejouer ne doit pas empiler les blocs.
selec_selfheal(true);
$lg2 = (string) @file_get_contents($CFG->LANGUAGE_PATH . 'fr/Install.php');
t_eq(1, substr_count($lg2, 'SELEC-LANG BEGIN'), 'un seul bloc de libellés après deux passages');
t_eq(strlen($lg), strlen($lg2), 'fichier de langue inchangé au second passage');

// Le set doit être vu par le même mécanisme que ianseo (glob sur sets.php).
$SetType = array(); $TourTypes = array();
$rs = safe_r_sql("SELECT TtId, TtType FROM TourTypes");
while ($r = safe_fetch($rs)) $TourTypes["$r->TtId"] = $r->TtType;
function get_text($t, $m = '', $a = null) { return $t; }
foreach ((array) glob($CFG->DOCUMENT_PATH . 'Modules/Sets/*/sets.php') as $f) {
    if (basename(dirname($f)) === 'SELEC') include($f);
}
t_vrai(isset($SetType['SELEC']), 'ianseo découvrirait le set SELEC');
t_eq(2, count($SetType['SELEC']['rules'][(string) $sh['type']] ?? array()), 'deux sous-règles proposées');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Compétition jetable et génération de la structure');

/**
 * Recopie les lignes d'une table d'une compétition vers une autre, sans jamais
 * supposer la liste des colonnes : le schéma ianseo évolue d'une version à
 * l'autre, et un test qui code les colonnes en dur casse à la première mise à jour.
 */
function copier_table($table, $col, $from, $to, $extra = '', $override = array())
{
    $rs = safe_r_sql("SELECT COLUMN_NAME c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . StrSafe_DB($table) . "
        ORDER BY ORDINAL_POSITION");
    $cols = array();
    while ($r = safe_fetch($rs)) $cols[] = $r->c;
    if (!$cols) return 0;
    $sel = array();
    foreach ($cols as $c) {
        if ($c === $col && $col !== '')  $sel[] = isset($override[$c]) ? $override[$c] . " AS `$c`" : intval($to) . " AS `$c`";
        elseif (isset($override[$c]))    $sel[] = $override[$c] . " AS `$c`";
        else                             $sel[] = "`$c`";
    }
    // $col vide (ou $from nul) : la table n'a pas de colonne de compétition —
    // c'est le cas de Qualifications — et le filtre vient entièrement de $extra.
    $where = ($col !== '' && $from) ? "`$col`=" . intval($from) . ($extra ? " AND $extra" : '')
                                   : ($extra ? $extra : '1=1');
    safe_w_sql("INSERT INTO `$table` (`" . implode('`,`', $cols) . "`)
        SELECT " . implode(',', $sel) . " FROM `$table` WHERE $where");
    return safe_w_affected_rows();
}

// La compétition d'essai est un CLONE d'une compétition réelle (617), à laquelle
// on change seulement le type : divisions, classes et portées sont donc celles
// que ianseo produit vraiment, pas une reconstitution approximative.
$SRC = 617;
copier_table('Tournament', 'ToId', $SRC, $TOUR, '', array('ToCode' => "'ZZTEST99'"));
safe_w_sql("UPDATE Tournament SET ToCode='ZZTEST99', ToName='Essai SELEC — supprimable',
    ToType=" . intval($sh['type']) . ", ToTypeName='Type_FR_Selection',
    ToTypeSubRule='SelecTAECL2027E1', ToLocRule='SELEC', ToNumDist=8, ToNumSession=1,
    ToOnlineId=0 WHERE ToId=$TOUR");
copier_table('Divisions', 'DivTournament', $SRC, $TOUR);
copier_table('Classes', 'ClTournament', $SRC, $TOUR);
// Blasons : la source n'a que 2 séries, donc TfT3..TfT8 y sont à zéro —
// exactement la situation qu'une compétition de sélection à 8 séries produit.
copier_table('TargetFaces', 'TfTournament', $SRC, $TOUR);
copier_table('Events', 'EvTournament', $SRC, $TOUR, "EvCode='EFCL' AND EvTeamEvent=0");
copier_table('EventClass', 'EcTournament', $SRC, $TOUR, "EcCode='EFCL' AND EcTeamEvent=0");
safe_w_sql("UPDATE Events SET EvCode='SHCL', EvRecCategory='SHCL', EvWaCategory='SHCL',
    EvEventName='Senior Homme Arc Classique' WHERE EvTournament=$TOUR");
safe_w_sql("UPDATE EventClass SET EcCode='SHCL' WHERE EcTournament=$TOUR");

$rs = safe_r_sql("SELECT COUNT(*) n FROM EventClass WHERE EcTournament=$TOUR AND EcCode='SHCL'");
$nPortee = intval(safe_fetch($rs)->n);
t_vrai($nPortee > 0, "épreuve de qualification d'essai avec sa portée ($nPortee ligne(s))");

$mode = selec_mode_charger('TAE_CL_2027_E1');
$plan = selec_structure_plan($TOUR, $mode, array('SHCL'));

t_eq(4, count($plan['sessions']), 'quatre départs planifiés');
t_eq(8, $plan['distances'], 'huit séries au total');
t_eq(array(1, 2), $plan['sessions'][0]['distances'], 'départ 1 → séries 1 et 2');
t_eq(array(3, 4), $plan['sessions'][1]['distances'], 'départ 2 → séries 3 et 4');
t_eq(array(5, 6), $plan['sessions'][2]['distances'], 'départ 3 → séries 5 et 6');
t_eq(array(7, 8), $plan['sessions'][3]['distances'], 'départ 4 → séries 7 et 8');
t_eq(17, count($plan['epreuves']), '17 épreuves de duels (6 tournois × 2 + 5 duels simulés)');

$res = selec_structure_appliquer($TOUR, $mode, array('SHCL'));
foreach ($res['erreurs'] as $e) echo "  ! $e\n";
t_eq(0, count($res['erreurs']), 'génération sans erreur');
t_eq(4, $res['sessions'], 'quatre sessions créées');
t_eq(17, $res['epreuves'], 'dix-sept épreuves créées');

// Vérification en base : chaque session porte SES séries, et elles seules.
$di = array();
$rs = safe_r_sql("SELECT DiSession, DiDistance, DiEnds, DiArrows FROM DistanceInformation
    WHERE DiTournament=$TOUR AND DiType='Q' ORDER BY DiSession, DiDistance");
while ($r = safe_fetch($rs)) $di[intval($r->DiSession)][] = array(intval($r->DiDistance), intval($r->DiEnds), intval($r->DiArrows));
t_eq(array(array(1, 6, 6), array(2, 6, 6)), $di[1] ?? array(), 'session 1 en base : séries 1-2, 6 volées de 6');
t_eq(array(array(3, 6, 6), array(4, 6, 6)), $di[2] ?? array(), 'session 2 en base : séries 3-4');
t_eq(array(array(7, 6, 6), array(8, 6, 6)), $di[4] ?? array(), 'session 4 en base : séries 7-8');

$rs = safe_r_sql("SELECT ToNumSession FROM Tournament WHERE ToId=$TOUR");
t_eq(4, intval(safe_fetch($rs)->ToNumSession), 'la compétition déclare 4 départs');

// Le tableau principal et sa consolante, correctement liés.
$rs = safe_r_sql("SELECT EvCode, EvFinalFirstPhase, EvNumQualified, EvWinnerFinalRank,
                         EvCodeParent, EvCodeParentWinnerBranch, EvFinEnds, EvFinArrows, EvMatchMode
    FROM Events WHERE EvTournament=$TOUR AND EvCode IN ('SHCLT1','SHCLT1B')");
$ev = array(); while ($r = safe_fetch($rs)) $ev[$r->EvCode] = $r;
t_vrai(isset($ev['SHCLT1']),  'tableau principal du tournoi 1 créé');
t_vrai(isset($ev['SHCLT1B']), 'consolante du tournoi 1 créée');
if (isset($ev['SHCLT1'])) {
    t_eq(4, intval($ev['SHCLT1']->EvFinalFirstPhase), 'principal : départ en quarts (phase 4)');
    t_eq(8, intval($ev['SHCLT1']->EvNumQualified),    'principal : 8 archers');
    t_eq(1, intval($ev['SHCLT1']->EvWinnerFinalRank), 'principal : le vainqueur est 1er');
    t_eq(5, intval($ev['SHCLT1']->EvFinEnds),         'principal : 5 volées');
    t_eq(3, intval($ev['SHCLT1']->EvFinArrows),       'principal : de 3 flèches');
    t_eq(1, intval($ev['SHCLT1']->EvMatchMode),       'principal : duels en sets');
}
if (isset($ev['SHCLT1B'])) {
    t_eq(2, intval($ev['SHCLT1B']->EvFinalFirstPhase),        'consolante : départ en demies (phase 2)');
    t_eq('SHCLT1', $ev['SHCLT1B']->EvCodeParent,              'consolante : rattachée au principal');
    t_eq(0, intval($ev['SHCLT1B']->EvCodeParentWinnerBranch), 'consolante : branche des perdants');
    t_eq(5, intval($ev['SHCLT1B']->EvWinnerFinalRank),        'consolante : son vainqueur est 5e');
}

// Portée recopiée depuis l'épreuve de qualification.
$rs = safe_r_sql("SELECT COUNT(*) n FROM EventClass WHERE EcTournament=$TOUR AND EcCode='SHCLT1'");
t_eq($nPortee, intval(safe_fetch($rs)->n), 'portée du tableau recopiée (division/classe)');

// Grilles de matchs vides prêtes à recevoir les archers.
$rs = safe_r_sql("SELECT COUNT(*) n FROM Finals WHERE FinTournament=$TOUR AND FinEvent='SHCLT1'");
// 8 lignes en quarts + 4 en demies + 2 en petite finale + 2 en finale.
t_eq(16, intval(safe_fetch($rs)->n), 'grille du principal : 16 lignes de match');

// Rattachements du module renseignés tout seuls.
$b = selec_binds_lire($TOUR, 'SHCL');
t_eq('SHCLT1',  $b['T1']['principal']  ?? '', 'rattachement T1/principal automatique');
t_eq('SHCLT1B', $b['T1']['consolante'] ?? '', 'rattachement T1/consolante automatique');
t_vrai(!empty($b['MS']['ds1']) && !empty($b['MS']['ds5']), 'rattachement des cinq duels simulés automatique');

// Idempotence : relancer ne crée rien de plus et n'écrase rien.
$res2 = selec_structure_appliquer($TOUR, $mode, array('SHCL'));
t_eq(0, $res2['epreuves'], 'second passage : aucune épreuve en double');
$rs = safe_r_sql("SELECT COUNT(*) n FROM Events WHERE EvTournament=$TOUR AND EvTeamEvent=0");
t_eq(18, intval(safe_fetch($rs)->n), 'toujours 18 épreuves (1 qualification + 17 duels)');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Préparation : placement sur le départ suivant et génération des tableaux');

require_once __DIR__ . '/../lib/preparation.php';

// Huit archers réels de la compétition source, recopiés avec un identifiant
// décalé pour ne jamais toucher aux originaux.
$OFFSET = 9000000;
$rs = safe_r_sql("SELECT q.QuId FROM Qualifications q
    INNER JOIN Entries e ON e.EnId=q.QuId
    INNER JOIN Individuals i ON i.IndId=e.EnId AND i.IndTournament=e.EnTournament
    WHERE e.EnTournament=$SRC AND i.IndEvent='EFCL' AND q.QuScore>0
    ORDER BY q.QuScore DESC LIMIT 8");
$ids = array(); while ($r = safe_fetch($rs)) $ids[] = intval($r->QuId);
t_eq(8, count($ids), 'huit archers sources retenus');

if (count($ids) === 8) {
    $in = implode(',', $ids);
    copier_table('Entries', 'EnTournament', $SRC, $TOUR, "EnId IN ($in)",
        array('EnId' => "`EnId`+$OFFSET", 'EnOnlineId' => '0'));
    // Qualifications n'a pas de colonne de compétition : on borne par la liste d'ids.
    copier_table('Qualifications', 'QuId', 0, 0, "QuId IN ($in)",
        array('QuId' => "`QuId`+$OFFSET", 'QuSession' => '1', 'QuTarget' => '0', 'QuLetter' => "''"));
    copier_table('Individuals', 'IndTournament', $SRC, $TOUR, "IndId IN ($in) AND IndEvent='EFCL'",
        array('IndId' => "`IndId`+$OFFSET", 'IndEvent' => "'SHCL'"));

    // Volontairement, on ne peuple PAS Individuals pour les épreuves de duels :
    // c'est à la préparation du tableau de le faire (selec_prepa_individuals).
    // Bug réel : sans ce rattachement, tous les archers ressortaient
    // « n'appartient pas à l'épreuve HCLT1 » et le tournoi ne pouvait pas être
    // généré. Le test ci-dessous vérifie que la préparation s'en charge seule.

    $rs = safe_r_sql("SELECT COUNT(*) n FROM Entries WHERE EnTournament=$TOUR AND EnAthlete=1");
    t_eq(8, intval(safe_fetch($rs)->n), 'huit archers dans la compétition d\'essai');

    // Classements calculés depuis les scores réellement recopiés.
    $binds = selec_binds_lire($TOUR, 'SHCL');
    $ctx = selec_calculer($TOUR, 'SHCL', $mode, $binds);
    $classements = array('SHCL' => $ctx);
    t_eq(8, count($ctx['etapes']['Q1']['lignes']), 'Q1 classée pour les huit archers');

    // ── 0. Les épreuves de duels ne sont PAS des catégories ─────────────────
    // Elles portent la même portée que SHCL, donc les mêmes archers : les
    // compter comme catégories placerait chaque archer treize fois, et seule la
    // dernière affectation de cible survivrait. Bug réel signalé sur le terrain.
    $liees = selec_evenements_lies($TOUR);
    t_eq(17, count($liees), 'dix-sept épreuves de duels rattachées au module');
    $actives = selec_categories_actives($TOUR, selec_config_lire($TOUR));
    t_eq(array('SHCL'), $actives, 'une seule catégorie retenue : l\'épreuve de qualification');
    $toutes = selec_categories($TOUR);
    t_eq(18, count($toutes), 'ianseo en compte bien dix-huit au total');

    // ── 1. Après Q1, on prépare Q2 : départ n° 2, ordre du classement de Q1 ──
    $planS = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'Q1');
    t_eq('session', $planS['type'], 'après Q1, l\'étape à préparer est un départ');
    t_eq('Q2', $planS['cible'], 'l\'étape préparée est bien Q2');
    t_eq('Q1', $planS['base'], 'classement de référence : la qualification précédente');
    t_eq(2, intval($planS['session'] ?? 0), 'départ n° 2 visé');
    t_vrai($planS['ok'], 'plan de départ exécutable');
    t_eq(8, count($planS['lignes']), 'huit archers à placer');
    // Le 1er du classement prend la première place du départ.
    $prem = $planS['lignes'][0];
    t_eq(1, intval($prem['rang']), 'le premier placé est le 1er de Q1');

    $appS = selec_prepa_appliquer($TOUR, $planS);
    t_eq(0, count($appS['erreurs']), 'placement sans erreur');

    $rs = safe_r_sql("SELECT q.QuId, q.QuSession, q.QuTarget, q.QuLetter, q.QuD1Score
        FROM Qualifications q INNER JOIN Entries e ON e.EnId=q.QuId
        WHERE e.EnTournament=$TOUR ORDER BY q.QuTarget, q.QuLetter");
    $places = array(); $scoresIntacts = true;
    while ($r = safe_fetch($rs)) {
        $places[] = array(intval($r->QuSession), intval($r->QuTarget), $r->QuLetter);
        if (intval($r->QuD1Score) <= 0) $scoresIntacts = false;
    }
    t_eq(8, count($places), 'huit archers replacés');
    t_eq(2, $places[0][0], 'tous sur le départ n° 2');
    t_vrai($scoresIntacts, 'les scores de la série 1 sont intacts après le déplacement');
    // Le premier du classement est sur la première cible/lettre disponible.
    $premId = intval($prem['id']);
    $rs = safe_r_sql("SELECT QuTarget, QuLetter FROM Qualifications WHERE QuId=$premId");
    $p1 = safe_fetch($rs);
    t_eq($prem['cible'] . $prem['lettre'], intval($p1->QuTarget) . $p1->QuLetter,
        'le 1er de Q1 occupe la cible annoncée par le plan');

    // Un archer = une place, une place = un archer.
    $cles = array(); $arch = array();
    foreach ($places as $p) $cles[] = $p[1] . $p[2];
    t_eq(count($cles), count(array_unique($cles)), 'aucune place occupée deux fois');
    $rs = safe_r_sql("SELECT COUNT(DISTINCT q.QuId) n FROM Qualifications q
        INNER JOIN Entries e ON e.EnId=q.QuId WHERE e.EnTournament=$TOUR AND q.QuTarget>0");
    t_eq(8, intval(safe_fetch($rs)->n), 'aucun archer placé deux fois');

    // ── 1 bis. Plages explicites : deux catégories qui s'enchaînent ─────────
    // La seconde doit pouvoir compléter la dernière cible de la première.
    t_vrai(isset($planS['par_cible']) && $planS['par_cible'] >= 1, 'places par cible connues');
    t_vrai(count($planS['categories']) >= 1, 'le plan expose les catégories et leurs plages');
    $c0 = $planS['categories'][0];
    t_eq('SHCL', $c0['code'], 'la catégorie du plan est la bonne');
    t_eq(8, intval($c0['nb']), 'huit archers dans la catégorie');
    t_eq(8, intval($c0['capacite']), 'la plage proposée couvre exactement l\'effectif');

    // Plage imposée à la main : on démarre en cible 5, lettre A.
    $ppc = intval($planS['par_cible']);
    $finLettre = chr(65 + (($ppc >= 4) ? 3 : ($ppc - 1)));
    $depart = ($ppc >= 4) ? '5A' : '5A';
    $planM = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'Q1', '',
        array('SHCL' => array('actif' => 1, 'de' => $depart, 'a' => '12' . $finLettre)));
    t_vrai($planM['ok'], 'plan avec plage imposée exécutable');
    t_eq(5, intval($planM['lignes'][0]['cible']), 'le 1er du classement démarre bien en cible 5');
    t_eq('A', $planM['lignes'][0]['lettre'], 'et sur la lettre A');

    // Plage trop courte : le module le dit et ne place pas tout le monde.
    $planC = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'Q1', '',
        array('SHCL' => array('actif' => 1, 'de' => '1A', 'a' => '1A')));
    t_eq(1, count($planC['lignes']), 'une seule place demandée, un seul archer placé');
    $alerteCapacite = false;
    foreach ($planC['alertes'] as $al) if (strpos($al, 'sans cible') !== false) $alerteCapacite = true;
    t_vrai($alerteCapacite, 'le manque de places est signalé');

    // Catégorie décochée : rien à placer, et le module refuse plutôt que d'agir.
    $planN = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'Q1', '',
        array('SHCL' => array('actif' => 0, 'de' => '1A', 'a' => '8A')));
    t_vrai(!$planN['ok'], 'aucune catégorie cochée : plan non exécutable');

    // Plage hors du départ : refus explicite, pas de placement approximatif.
    $planX = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'Q1', '',
        array('SHCL' => array('actif' => 1, 'de' => '999A', 'a' => '999Z')));
    t_vrai(!$planX['ok'], 'plage inexistante : plan non exécutable');
    $alerteInvalide = false;
    foreach ($planX['alertes'] as $al) if (strpos($al, 'invalide') !== false) $alerteInvalide = true;
    t_vrai($alerteInvalide, 'la plage invalide est signalée');

    // ── 1 ter. Deux catégories à la suite : la seconde complète la cible ────
    // Cas explicitement demandé : après les hommes, les femmes doivent pouvoir
    // finir la dernière cible entamée plutôt que d'en ouvrir une nouvelle.
    $rs = safe_r_sql("SELECT q.QuId FROM Qualifications q
        INNER JOIN Entries e ON e.EnId=q.QuId
        INNER JOIN Individuals i ON i.IndId=e.EnId AND i.IndTournament=e.EnTournament
        WHERE e.EnTournament=$SRC AND i.IndEvent='EFCL' AND q.QuScore>0
          AND q.QuId NOT IN ($in) ORDER BY q.QuScore DESC LIMIT 3");
    $ids2 = array(); while ($r = safe_fetch($rs)) $ids2[] = intval($r->QuId);

    if (count($ids2) === 3) {
        $in2 = implode(',', $ids2);
        copier_table('Entries', 'EnTournament', $SRC, $TOUR, "EnId IN ($in2)",
            array('EnId' => "`EnId`+$OFFSET", 'EnOnlineId' => '0'));
        copier_table('Qualifications', 'QuId', 0, 0, "QuId IN ($in2)",
            array('QuId' => "`QuId`+$OFFSET", 'QuSession' => '1', 'QuTarget' => '0', 'QuLetter' => "''"));
        copier_table('Events', 'EvTournament', $TOUR, $TOUR, "EvCode='SHCL'",
            array('EvCode' => "'SFCL'", 'EvEventName' => "'Senior Femme Arc Classique'",
                  'EvRecCategory' => "'SFCL'", 'EvWaCategory' => "'SFCL'", 'EvProgr' => '2'));
        copier_table('Individuals', 'IndTournament', $SRC, $TOUR,
            "IndId IN ($in2) AND IndEvent='EFCL'",
            array('IndId' => "`IndId`+$OFFSET", 'IndEvent' => "'SFCL'", 'IndRank' => '0'));

        $ctxF = selec_calculer($TOUR, 'SFCL', $mode, array());
        $deux = array('SHCL' => $ctx, 'SFCL' => $ctxF);
        t_eq(3, count($ctxF['archers']), 'trois archères dans la seconde catégorie');

        $planD = selec_prepa_plan($TOUR, $mode, array('SHCL', 'SFCL'), $deux, 'Q1');
        t_vrai($planD['ok'], 'plan à deux catégories exécutable');
        t_eq(2, count($planD['categories']), 'les deux catégories sont proposées');
        t_eq(11, count($planD['lignes']), 'onze archers placés au total');

        // La seconde catégorie démarre exactement après la dernière place de la
        // première — sur la même cible s'il y reste de la place.
        $finH = $planD['categories'][0]['a'];
        $debF = $planD['categories'][1]['de'];
        $iFin = selec_prepa_place_index(selec_prepa_places($TOUR, 2), $finH);
        $iDeb = selec_prepa_place_index(selec_prepa_places($TOUR, 2), $debF);
        t_eq($iFin + 1, $iDeb, 'les femmes reprennent à la place suivant les hommes');

        echo "  · départ n° " . $planD['session'] . ", " . $planD['par_cible'] . " place(s) par cible : "
           . $planD['categories'][0]['code'] . ' ' . $finH . ' ← ' . $planD['categories'][0]['de']
           . '  puis  ' . $planD['categories'][1]['code'] . ' ' . $debF
           . ' → ' . $planD['categories'][1]['a'] . "\n";

        // Cas décisif : une catégorie qui ne remplit PAS sa dernière cible. Avec
        // 3 archères en premier et 4 places par cible, la cible 1 reste entamée
        // et la catégorie suivante doit y prendre la place restante — c'est
        // précisément le comportement demandé.
        $ppc2 = intval($planD['par_cible']);
        $planE = selec_prepa_plan($TOUR, $mode, array('SFCL', 'SHCL'), $deux, 'Q1');
        t_vrai($planE['ok'], 'plan avec la petite catégorie en premier');
        $finF = $planE['categories'][0]['a'];
        $debH = $planE['categories'][1]['de'];
        echo "  · ordre inverse : SFCL → $finF  puis  SHCL → $debH\n";
        if ($ppc2 > 3) {
            t_eq('1C', $finF, 'les 3 archères occupent 1A à 1C');
            t_eq('1D', $debH, 'le 1er homme complète la cible 1 au lieu d\'en ouvrir une neuve');
            $cibleFinF = intval(preg_replace('/[A-Z]+$/', '', $finF));
            $cibleDebH = intval(preg_replace('/[A-Z]+$/', '', $debH));
            t_eq($cibleFinF, $cibleDebH, 'même cible pour la fin d\'une catégorie et le début de la suivante');
        }
        $vuesE = array();
        foreach ($planE['lignes'] as $l) $vuesE[] = $l['cible'] . $l['lettre'];
        t_eq(count($vuesE), count(array_unique($vuesE)), 'ordre inverse : aucune place en double');
        t_eq(11, count($vuesE), 'ordre inverse : les onze archers sont placés');

        // Aucune place partagée entre les deux catégories.
        $vues = array();
        foreach ($planD['lignes'] as $l) $vues[] = $l['cible'] . $l['lettre'];
        t_eq(count($vues), count(array_unique($vues)), 'aucune place attribuée à deux archers');

        // Chevauchement volontaire : le module refuse de trancher en silence.
        $planO = selec_prepa_plan($TOUR, $mode, array('SHCL', 'SFCL'), $deux, 'Q1', '', array(
            'SHCL' => array('actif' => 1, 'de' => '1A', 'a' => '4A'),
            'SFCL' => array('actif' => 1, 'de' => '1A', 'a' => '4A'),
        ));
        $alerteChevauchement = false;
        foreach ($planO['alertes'] as $al) if (strpos($al, 'chevauchement') !== false) $alerteChevauchement = true;
        t_vrai($alerteChevauchement, 'un chevauchement de plages est signalé');
        $vues2 = array();
        foreach ($planO['lignes'] as $l) $vues2[] = $l['cible'] . $l['lettre'];
        t_eq(count($vues2), count(array_unique($vues2)), 'et aucune place n\'est donnée deux fois');

        // On remet la première catégorie seule pour la suite du banc.
        safe_w_sql("DELETE i FROM Individuals i WHERE i.IndTournament=$TOUR AND i.IndEvent='SFCL'");
        safe_w_sql("DELETE FROM Events WHERE EvTournament=$TOUR AND EvCode='SFCL'");
    }

    // ── 1 quater. Le gel protège une étape tirée d'un écrasement ────────────
    // Le vrai danger : la page de saisie manuelle de ianseo propose TOUTES les
    // séries quelle que soit la session en cours. Un mauvais choix réécrit une
    // qualification déjà tirée, sans un mot. Une étape gelée doit y survivre.
    require_once __DIR__ . '/../lib/archive.php';

    $etatAvant = selec_arch_etat($TOUR);
    t_eq(0, count($etatAvant), 'aucune étape gelée au départ');

    $g = selec_arch_geler($TOUR, $mode, 'Q1', array('SHCL'));
    t_vrai($g['ok'], 'gel de Q1 réussi');
    t_eq(8, $g['archers'], 'huit archers archivés');

    $arch = selec_arch_lire($TOUR, 'Q1');
    t_eq(8, count($arch['Q1']), 'huit lignes relues depuis l\'archive');
    $unId = array_key_first($arch['Q1']);
    t_vrai(!empty($arch['Q1'][$unId]['distances'][1]['arrowstring']),
        'la chaîne de flèches de la série 1 est archivée');
    t_vrai($arch['Q1'][$unId]['score'] > 0, 'le score de l\'étape est archivé');

    // Classement de référence avant sabotage.
    $ctxG = selec_calculer($TOUR, 'SHCL', $mode, selec_binds_lire($TOUR, 'SHCL'));
    $refQ1 = array();
    foreach ($ctxG['etapes']['Q1']['lignes'] as $id => $l) $refQ1[$id] = $l['rang'] . '/' . $l['points_c'];
    t_eq(true, !empty($ctxG['etapes']['Q1']['gele']), 'le moteur signale l\'étape comme gelée');

    // Sabotage : on écrase les séries 1 et 2 comme le ferait une saisie manuelle
    // faite sur la mauvaise distance.
    safe_w_sql("UPDATE Qualifications q INNER JOIN Entries e ON e.EnId=q.QuId
        SET q.QuD1Score=1, q.QuD1Gold=0, q.QuD1Xnine=0, q.QuD1Arrowstring='',
            q.QuD2Score=1, q.QuD2Gold=0, q.QuD2Xnine=0, q.QuD2Arrowstring=''
        WHERE e.EnTournament=$TOUR");

    $ctxS = selec_calculer($TOUR, 'SHCL', $mode, selec_binds_lire($TOUR, 'SHCL'));
    $apresQ1 = array();
    foreach ($ctxS['etapes']['Q1']['lignes'] as $id => $l) $apresQ1[$id] = $l['rang'] . '/' . $l['points_c'];
    t_eq($refQ1, $apresQ1, 'le classement de Q1 est INCHANGÉ malgré l\'écrasement des scores');

    // Les écarts doivent être visibles, pas silencieux.
    $ec = selec_arch_ecarts($TOUR, $mode, 'Q1', array('SHCL'));
    t_vrai(!$ec['identique'], 'les écarts avec la base sont détectés');
    t_vrai(count($ec['ecarts']) > 0, count($ec['ecarts']) . ' écart(s) listés pour l\'opérateur');
    t_eq(8, $ec['archers'], 'les huit archers archivés sont comparés');

    // ── Témoin : une compétition VOISINE ne doit pas bouger d'un octet ──────
    // `Qualifications` n'a pas de colonne de compétition ; c'est la seule table
    // du module où un WHERE mal borné déborderait sur toute la base. Sur un
    // serveur de production qui héberge des centaines de compétitions, ce test
    // vaut mieux qu'une relecture du code.
    $temoin = null;
    $rsT = safe_r_sql("SELECT q.* FROM Qualifications q
        INNER JOIN Entries e ON e.EnId=q.QuId
        WHERE e.EnTournament<>$TOUR ORDER BY q.QuId LIMIT 1");
    if ($rsT) $temoin = safe_fetch($rsT);
    t_vrai($temoin !== null && $temoin !== false, 'une compétition témoin existe pour le contrôle');

    // Retour en arrière : on remet les valeurs gelées dans ianseo.
    $rst = selec_arch_restaurer($TOUR, $mode, 'Q1', false);
    t_vrai($rst['ok'], 'restauration effectuée');
    t_eq(8, $rst['archers'], 'huit archers restaurés');
    $ec2 = selec_arch_ecarts($TOUR, $mode, 'Q1', array('SHCL'));
    t_vrai($ec2['identique'], 'plus aucun écart après restauration');

    // Le total de la ligne doit avoir été recalculé, pas laissé à la valeur sabotée.
    $rs = safe_r_sql("SELECT q.QuScore, q.QuD1Score+q.QuD2Score+q.QuD3Score+q.QuD4Score
        +q.QuD5Score+q.QuD6Score+q.QuD7Score+q.QuD8Score AS somme
        FROM Qualifications q INNER JOIN Entries e ON e.EnId=q.QuId
        WHERE e.EnTournament=$TOUR");
    $totOk = true;
    while ($r = safe_fetch($rs)) if (intval($r->QuScore) !== intval($r->somme)) $totOk = false;
    t_vrai($totOk, 'les totaux de ligne sont cohérents avec les séries après restauration');

    // La restauration a réécrit des scores ET recalculé des totaux de ligne :
    // le témoin d'à côté doit être rigoureusement identique.
    if ($temoin) {
        $rsT = safe_r_sql("SELECT * FROM Qualifications WHERE QuId=" . intval($temoin->QuId));
        $apres = $rsT ? safe_fetch($rsT) : null;
        $diff = array();
        foreach ((array) $temoin as $col => $val) {
            if ($col === 'QuTimestamp') continue;   // horodatage technique de ianseo
            if (!$apres || ((array) $apres)[$col] !== $val) $diff[] = $col;
        }
        t_eq(array(), $diff, 'aucune colonne d\'une autre compétition n\'a bougé');
    }

    // Dégel : l'archive part, les scores restent.
    $rs = safe_r_sql("SELECT SUM(q.QuD1Score) s FROM Qualifications q
        INNER JOIN Entries e ON e.EnId=q.QuId WHERE e.EnTournament=$TOUR");
    $avantDegel = intval(safe_fetch($rs)->s);
    selec_arch_degeler($TOUR, 'Q1');
    t_eq(0, count(selec_arch_etat($TOUR)), 'plus aucune étape gelée après dégel');
    $rs = safe_r_sql("SELECT SUM(q.QuD1Score) s FROM Qualifications q
        INNER JOIN Entries e ON e.EnId=q.QuId WHERE e.EnTournament=$TOUR");
    t_eq($avantDegel, intval(safe_fetch($rs)->s), 'le dégel ne touche pas aux scores');

    // ── 1 quinquies. La préparation gèle toute seule ─────────────────────────
    $planG = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'Q1');
    $appG = selec_prepa_appliquer($TOUR, $planG, $mode, array('SHCL'), 'Q1');
    t_eq(0, count($appG['erreurs']), 'préparation avec gel sans erreur');
    $etatG = selec_arch_etat($TOUR);
    t_vrai(isset($etatG['Q1']), 'préparer la suite a verrouillé Q1');
    t_eq(8, $etatG['Q1']['archers'], 'les huit archers sont archivés par la préparation');
    $mentionGel = false;
    foreach ($appG['faits'] as $f) if (strpos($f, 'errouill') !== false) $mentionGel = true;
    t_vrai($mentionGel, 'le compte rendu annonce le verrouillage');

    // ── 2. Après CUT8, on prépare T1 : validation et tableau ────────────────
    $planT = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'CUT8');
    t_eq('tableau', $planT['type'], 'après la coupure, l\'étape à préparer est un tableau');
    t_eq('T1', $planT['cible'], 'le tableau préparé est celui du tournoi 1');
    t_eq('CUT8', $planT['base'], 'têtes de série issues du classement de la coupure');
    t_vrai($planT['ok'], 'plan de tableau exécutable');
    t_eq(8, count($planT['lignes']), 'huit têtes de série');
    t_eq(1, intval($planT['lignes'][0]['serie']), 'la première tête de série est le n° 1');

    // Avant préparation, aucun archer n'est rattaché aux épreuves de duels :
    // c'est l'état réel après une génération de structure.
    $rs = safe_r_sql("SELECT COUNT(*) n FROM Individuals
        WHERE IndTournament=$TOUR AND IndEvent='SHCLT1'");
    t_eq(0, intval(safe_fetch($rs)->n), 'aucun rattachement aux duels avant préparation');

    $appT = selec_prepa_appliquer($TOUR, $planT);
    foreach ($appT['erreurs'] as $e) echo "  ! $e\n";
    t_eq(0, count($appT['erreurs']), 'génération du tableau sans erreur');

    // La préparation doit avoir rattaché les archers elle-même, depuis la portée
    // de l'épreuve — sinon tous ressortent « n'appartient pas à l'épreuve ».
    // Toute la portée est rattachée (comme le fait ianseo) ; seuls les qualifiés
    // reçoivent un rang de tête de série, les autres restent à 0.
    $rs = safe_r_sql("SELECT COUNT(*) n FROM Individuals
        WHERE IndTournament=$TOUR AND IndEvent='SHCLT1'");
    t_vrai(intval(safe_fetch($rs)->n) >= 8, 'la portée du tableau principal est rattachée');
    $rs = safe_r_sql("SELECT COUNT(*) n FROM Individuals
        WHERE IndTournament=$TOUR AND IndEvent='SHCLT1' AND IndRank>0");
    t_eq(8, intval(safe_fetch($rs)->n), 'huit têtes de série sur le tableau principal');

    // La consolante n'a pas de portée : elle ne reçoit personne, et c'est voulu.
    $rs = safe_r_sql("SELECT COUNT(*) n FROM Individuals
        WHERE IndTournament=$TOUR AND IndEvent='SHCLT1B'");
    t_eq(0, intval(safe_fetch($rs)->n), 'la consolante ne rattache aucun archer par catégorie');

    // Les huit archers doivent occuper les huit places de quart de finale, à la
    // position exacte de leur tête de série (Grids.GrPosition).
    $rs = safe_r_sql("SELECT f.FinMatchNo, f.FinAthlete, g.GrPosition
        FROM Finals f INNER JOIN Grids g ON g.GrMatchNo=f.FinMatchNo
        WHERE f.FinTournament=$TOUR AND f.FinEvent='SHCLT1' AND g.GrPhase=4
        ORDER BY g.GrPosition");
    $grille = array();
    while ($r = safe_fetch($rs)) if (intval($r->FinAthlete)) $grille[intval($r->GrPosition)] = intval($r->FinAthlete);
    t_eq(8, count($grille), 'les huit places de quart de finale sont occupées');

    $serie = array();
    foreach ($planT['lignes'] as $l) $serie[intval($l['serie'])] = intval($l['id']);
    $bonnePlace = 0;
    foreach ($serie as $s => $id) if (isset($grille[$s]) && $grille[$s] === $id) $bonnePlace++;
    t_eq(8, $bonnePlace, 'chaque archer est à la position de sa tête de série');
    // Tableau standard à 8 : le n° 1 rencontre le n° 8, le 2 le 7.
    t_eq($serie[1], $grille[1] ?? 0, 'tête de série 1 en position 1');
    t_eq($serie[8], $grille[8] ?? 0, 'tête de série 8 en position 8');

    $rs = safe_r_sql("SELECT EvShootOff FROM Events WHERE EvTournament=$TOUR AND EvCode='SHCLT1'");
    t_eq(1, intval(safe_fetch($rs)->EvShootOff), 'qualification marquée validée sur l\'épreuve');

    // ── 3. Un tableau qui porte des scores ne se régénère pas ───────────────
    safe_w_sql("UPDATE Finals SET FinScore=137 WHERE FinTournament=$TOUR
        AND FinEvent='SHCLT1' AND FinMatchNo=8");
    $planT2 = selec_prepa_plan($TOUR, $mode, array('SHCL'), $classements, 'CUT8');
    t_vrai(!$planT2['ok'], 'régénération refusée quand des scores existent');
    t_vrai(strpos($planT2['bloquant'], 'scores') !== false, 'le refus dit pourquoi');
    $appT2 = selec_prepa_appliquer($TOUR, $planT2);
    t_vrai(!$appT2['ok'], 'et l\'application refuse aussi');
    $rs = safe_r_sql("SELECT FinScore FROM Finals WHERE FinTournament=$TOUR
        AND FinEvent='SHCLT1' AND FinMatchNo=8");
    t_eq(137, intval(safe_fetch($rs)->FinScore), 'le score existant est toujours là');
}

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Blason, cibles et horaires des duels');

// ── Le blason décide si l'impression « Score du match » sort une page ────────
// `Obj_Rank_GridInd` fait un INNER JOIN sur Targets ; la table commence à
// TarId=1. Une épreuve à EvFinalTargetType=0 ne renvoie AUCUNE ligne, et la
// feuille de marque sort vide sans le moindre message. Bug réel rencontré.
$rs = safe_r_sql("SELECT MIN(TarId) t FROM Targets");
$tarMin = intval(safe_fetch($rs)->t);
t_eq(1, $tarMin, 'la table Targets commence à TarId=1 (il n\'existe pas de blason 0)');

$rs = safe_r_sql("SELECT EvCode, EvFinalTargetType, EvTargetSize FROM Events
    WHERE EvTournament=$TOUR AND EvTeamEvent=0 AND EvCode IN ('SHCLT1','SHCLT1B')");
$blasons = array();
while ($r = safe_fetch($rs)) $blasons[$r->EvCode] = intval($r->EvFinalTargetType);
t_vrai(($blasons['SHCLT1'] ?? 0) > 0,  'le tableau principal porte un blason réel');
t_vrai(($blasons['SHCLT1B'] ?? 0) > 0, 'la consolante aussi');

// La jointure de ianseo doit ramener les lignes du tableau.
$rs = safe_r_sql("SELECT COUNT(*) n FROM Finals f
    INNER JOIN Grids g ON g.GrMatchNo=f.FinMatchNo
    INNER JOIN Events e ON e.EvCode=f.FinEvent AND e.EvTournament=f.FinTournament AND e.EvTeamEvent=0
    INNER JOIN Targets t ON t.TarId=e.EvFinalTargetType
    WHERE f.FinTournament=$TOUR AND f.FinEvent='SHCLT1' AND g.GrPhase=4");
t_eq(8, intval(safe_fetch($rs)->n), 'la jointure Targets de ianseo ramène bien les 8 quarts');

// ── Blasons de toutes les séries ────────────────────────────────────────────
// Compétition → Blasons affiche une colonne par distance jusqu'à ToNumDist.
// Le fichier de setup ne pose le blason que sur les premières séries ; ajouter
// des distances sans le reporter laisse des séries sans blason. Bug réel.
$rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId=$TOUR");
$nbDist = intval(safe_fetch($rs)->ToNumDist);
t_eq(8, $nbDist, 'la compétition déclare huit séries');

// On casse volontairement pour vérifier que la réparation les rattrape.
safe_w_sql("UPDATE TargetFaces SET TfT3=0, TfW3=0, TfT7=0, TfW7=0 WHERE TfTournament=$TOUR");
$bl = selec_structure_blasons_completer($TOUR);
t_vrai($bl['faces'] > 0, 'des blasons incomplets détectés');
t_vrai($bl['series'] > 0, $bl['series'] . ' série(s) complétée(s)');

$rs = safe_r_sql("SELECT TfId, TfName, TfT1, TfW1, TfT2, TfT3, TfT4, TfT5, TfT6, TfT7, TfT8
    FROM TargetFaces WHERE TfTournament=$TOUR");
$trous = 0; $incoherents = 0; $faces = 0;
while ($tf = safe_fetch($rs)) {
    $faces++;
    if (intval($tf->TfT1) <= 0) continue;
    for ($i = 2; $i <= $nbDist; $i++) {
        $c = 'TfT' . $i;
        if (intval($tf->$c) <= 0) $trous++;
        elseif (intval($tf->$c) !== intval($tf->TfT1)) $incoherents++;
    }
}
t_vrai($faces > 0, "$faces blason(s) déclarés");
t_eq(0, $trous, 'plus aucune série sans blason');
t_eq(0, $incoherents, 'toutes les séries reprennent le blason de la première');

// Une série réglée à la main ne doit pas être écrasée.
safe_w_sql("UPDATE TargetFaces SET TfT4=9, TfW4=80 WHERE TfTournament=$TOUR AND TfId=1");
selec_structure_blasons_completer($TOUR);
$rs = safe_r_sql("SELECT TfT4, TfW4 FROM TargetFaces WHERE TfTournament=$TOUR AND TfId=1");
$r4 = safe_fetch($rs);
t_eq(9, intval($r4->TfT4), 'une série réglée à la main garde son blason');
t_eq(80, intval($r4->TfW4), 'et son diamètre');

// Idempotence : un second passage ne change plus rien.
$bl2 = selec_structure_blasons_completer($TOUR);
t_eq(0, $bl2['faces'], 'second passage : plus rien à compléter');

// ── Une consolante ne porte ni division ni classe ───────────────────────────
$rs = safe_r_sql("SELECT COUNT(*) n FROM EventClass WHERE EcTournament=$TOUR AND EcCode='SHCLT1B'");
t_eq(0, intval(safe_fetch($rs)->n), 'la consolante n\'a aucune portée division/classe');
$rs = safe_r_sql("SELECT COUNT(*) n FROM EventClass WHERE EcTournament=$TOUR AND EcCode='SHCLT1'");
t_vrai(intval(safe_fetch($rs)->n) > 0, 'le tableau principal en a une');

// ── Cibles et horaires ──────────────────────────────────────────────────────
$optDuels = array('duels' => array(
    'duree' => 35,
    'cibles' => array('SHCL' => 1),
    'horaires' => array('T1' => array('date' => '2026-09-03', 'heure' => '09:00')),
));
$pl = selec_structure_planning($TOUR, $mode, array('SHCL'), $optDuels);
t_eq(0, count($pl['erreurs']), 'attribution des cibles et horaires sans erreur');
t_eq(24, $pl['lignes'], '24 affectations : 8 quarts + 8 demies + 8 finales/bronze');

$rs = safe_r_sql("SELECT s.FSEvent, s.FSMatchNo, g.GrPhase, s.FSTarget+0 AS cible,
                         s.FSScheduledTime AS h, s.FSScheduledLen AS d, s.FSLetter
    FROM FinSchedule s INNER JOIN Grids g ON g.GrMatchNo=s.FSMatchNo
    WHERE s.FSTournament=$TOUR AND s.FSTeamEvent=0 AND s.FSEvent IN ('SHCLT1','SHCLT1B')
    ORDER BY s.FSEvent, g.GrPhase DESC, s.FSMatchNo");
$sched = array();
while ($r = safe_fetch($rs)) {
    $sched[$r->FSEvent][intval($r->GrPhase)][intval($r->FSMatchNo)] =
        array(intval($r->cible), substr($r->h, 0, 5), intval($r->d), $r->FSLetter);
}

// Quarts : 8 archers sur les cibles 1 à 8, à 09:00.
t_eq(8, count($sched['SHCLT1'][4] ?? array()), 'huit emplacements en quart de finale');
t_eq(1, $sched['SHCLT1'][4][8][0]  ?? 0, 'le match 8 est en cible 1');
t_eq(8, $sched['SHCLT1'][4][15][0] ?? 0, 'le match 15 est en cible 8');
t_eq('09:00', $sched['SHCLT1'][4][8][1] ?? '', 'les quarts commencent à 09:00');
t_eq(35, $sched['SHCLT1'][4][8][2] ?? 0, 'un duel dure 35 minutes');
t_eq('0001', $sched['SHCLT1'][4][8][3] ?? '', 'FSLetter reprend la cible (un archer par cible)');

// Demies : le principal garde 1 à 4, la consolante récupère 5 à 8, à 09:35.
t_eq(1, $sched['SHCLT1'][2][4][0]  ?? 0, 'demi-finale : le principal reprend en cible 1');
t_eq(4, $sched['SHCLT1'][2][7][0]  ?? 0, 'le principal occupe jusqu\'à la cible 4');
t_eq(5, $sched['SHCLT1B'][2][4][0] ?? 0, 'la consolante récupère la cible 5');
t_eq(8, $sched['SHCLT1B'][2][7][0] ?? 0, 'jusqu\'à la cible 8');
t_eq('09:35', $sched['SHCLT1'][2][4][1]  ?? '', 'les demies enchaînent à 09:35');
t_eq('09:35', $sched['SHCLT1B'][2][4][1] ?? '', 'la consolante suit l\'horaire de son épreuve mère');

// Finale et petite finale : même créneau, cibles 1-4 pour le principal, 5-8 pour la fille.
t_eq(1, $sched['SHCLT1'][0][0][0] ?? 0, 'finale en cible 1');
t_eq(2, $sched['SHCLT1'][0][1][0] ?? 0, 'finale, second archer en cible 2');
t_eq(3, $sched['SHCLT1'][1][2][0] ?? 0, 'petite finale en cible 3');
t_eq(4, $sched['SHCLT1'][1][3][0] ?? 0, 'petite finale, second archer en cible 4');
t_eq(5, $sched['SHCLT1B'][0][0][0] ?? 0, 'finale de consolante en cible 5');
t_eq(7, $sched['SHCLT1B'][1][2][0] ?? 0, 'petite finale de consolante en cible 7');
t_eq('10:10', $sched['SHCLT1'][0][0][1] ?? '', 'finales à 10:10');
t_eq('10:10', $sched['SHCLT1'][1][2][1] ?? '', 'petite finale au même créneau que la finale');

// Une cible, un archer : aucune cible en double sur un même créneau.
$parCreneau = array();
foreach ($sched as $ev => $phases) {
    foreach ($phases as $ph => $matchs) {
        foreach ($matchs as $m => $v) $parCreneau[$v[1]][] = $v[0];
    }
}
$doublons = 0;
foreach ($parCreneau as $h => $cibles) {
    if (count($cibles) !== count(array_unique($cibles))) $doublons++;
}
t_eq(0, $doublons, 'aucune cible occupée deux fois sur un même créneau');
foreach ($parCreneau as $h => $cibles) {
    t_eq(8, count($cibles), "créneau $h : huit archers, huit cibles");
}

// Idempotence : relancer réécrit les mêmes valeurs.
$pl2 = selec_structure_planning($TOUR, $mode, array('SHCL'), $optDuels);
t_eq(24, $pl2['lignes'], 'seconde attribution : mêmes 24 affectations');
$rs = safe_r_sql("SELECT COUNT(*) n FROM FinSchedule WHERE FSTournament=$TOUR AND FSTeamEvent=0");
t_eq(24, intval(safe_fetch($rs)->n), 'toujours 24 lignes, aucun doublon');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Transfert entre serveurs : aller-retour sans perte');

require_once __DIR__ . '/../lib/transfert.php';

// La compétition d'essai n'était rattachée à aucun mode : on l'ancre, sinon il
// n'y aurait pas de règlement figé à transporter.
$maintenant = date('Y-m-d H:i:s');
safe_w_sql("INSERT INTO SELEC_Config SET ScTournament=$TOUR,
    ScMode=" . StrSafe_DB($mode['id']) . ", ScModeVer=" . StrSafe_DB($mode['version']) . ",
    ScSnapshot=" . StrSafe_DB(json_encode($mode, JSON_UNESCAPED_UNICODE)) . ",
    ScOptions=" . StrSafe_DB(json_encode(array('categories' => array('SHCL')))) . ",
    ScSnapDate=" . StrSafe_DB($maintenant) . ", ScUpdated=" . StrSafe_DB($maintenant) . "
    ON DUPLICATE KEY UPDATE ScSnapshot=VALUES(ScSnapshot)");

// On gèle une étape pour avoir des archives à transporter.
selec_arch_geler($TOUR, $mode, 'Q1', array('SHCL'));
$ctxT = selec_calculer($TOUR, 'SHCL', $mode, selec_binds_lire($TOUR, 'SHCL'));
selec_enregistrer($ctxT);

$avant = array();
foreach (array('SELEC_Config' => 'ScTournament', 'SELEC_Bind' => 'SbTournament',
               'SELEC_Results' => 'SrTournament', 'SELEC_Archive' => 'SaTournament') as $t => $c) {
    $rs = safe_r_sql("SELECT COUNT(*) n FROM `$t` WHERE `$c`=$TOUR");
    $avant[$t] = intval(safe_fetch($rs)->n);
}
t_vrai($avant['SELEC_Archive'] > 0, 'des archives existent avant l\'export');
t_vrai($avant['SELEC_Results'] > 0, 'des classements existent avant l\'export');

$exp = selec_transfert_export($TOUR);
t_eq(SELEC_TRANSFERT_FORMAT, $exp['format'], 'format du fichier');
t_eq($avant['SELEC_Archive'], count($exp['archive']), 'toutes les archives sont dans le fichier');
t_eq($avant['SELEC_Results'], count($exp['results']), 'tous les classements aussi');
t_vrai($exp['config'] !== null, 'le règlement figé est dans le fichier');

// Le fichier doit survivre à un aller-retour disque.
$bin = selec_transfert_fichier($exp);
$relu = selec_transfert_lire($bin);
t_vrai($relu !== null, 'fichier relu après compression');
t_eq(count($exp['archive']), count($relu['archive']), 'archives intactes après relecture');

// Aucune référence d'archer ne doit être un identifiant local : elles doivent
// contenir la licence, sinon le rapprochement ne survivrait pas à un import.
$refOk = true;
foreach ($exp['archive'] as $a) if (strpos($a['ref'], '|') === false) $refOk = false;
t_vrai($refOk, 'les archers sont désignés par licence + division + classe, pas par identifiant');

// Import dans la MÊME compétition après effacement : simule le serveur d'arrivée.
foreach (array('SELEC_Results' => 'SrTournament', 'SELEC_Archive' => 'SaTournament',
               'SELEC_Bind' => 'SbTournament', 'SELEC_Config' => 'ScTournament') as $t => $c) {
    safe_w_sql("DELETE FROM `$t` WHERE `$c`=$TOUR");
}
$rs = safe_r_sql("SELECT COUNT(*) n FROM SELEC_Archive WHERE SaTournament=$TOUR");
t_eq(0, intval(safe_fetch($rs)->n), 'tout effacé avant l\'import');

$an = selec_transfert_analyse($TOUR, $relu);
t_vrai($an['ok'], 'analyse du fichier concluante');
t_eq(0, count($an['manquants']), 'aucun archer introuvable');
t_eq(0, count($an['ambigus']), 'aucune référence ambiguë');

$imp = selec_transfert_importer($TOUR, $relu);
t_eq(0, count($imp['erreurs']), 'import sans erreur');
foreach (array('SELEC_Config' => 'ScTournament', 'SELEC_Bind' => 'SbTournament',
               'SELEC_Results' => 'SrTournament', 'SELEC_Archive' => 'SaTournament') as $t => $c) {
    $rs = safe_r_sql("SELECT COUNT(*) n FROM `$t` WHERE `$c`=$TOUR");
    t_eq($avant[$t], intval(safe_fetch($rs)->n), "$t : même nombre de lignes après import");
}

// Et surtout : le classement recalculé doit être identique, donc les archives
// ont bien traversé — c'est tout l'enjeu.
$ctxT2 = selec_calculer($TOUR, 'SHCL', $mode, selec_binds_lire($TOUR, 'SHCL'));
$a1 = array(); foreach ($ctxT['etapes']['Q1']['lignes'] as $id => $l) $a1[$id] = $l['rang'] . '/' . $l['points_c'];
$a2 = array(); foreach ($ctxT2['etapes']['Q1']['lignes'] as $id => $l) $a2[$id] = $l['rang'] . '/' . $l['points_c'];
t_eq($a1, $a2, 'classement identique après aller-retour');
t_vrai(!empty($ctxT2['etapes']['Q1']['gele']), 'l\'étape est toujours verrouillée après import');

// Un fichier d'une autre compétition ne doit pas s'importer en silence.
$faux = $relu;
$faux['archers'] = array('XXXXXXX|CL|S1H' => array('licence' => 'XXXXXXX', 'nom' => 'INCONNU'));
$anF = selec_transfert_analyse($TOUR, $faux);
t_vrai(!$anF['ok'], 'un fichier sans archer commun est refusé');

// ═════════════════════════════════════════════════════════════════════════════
t_titre('Nettoyage');
nettoyer($TOUR);
$reste = 0;
foreach (array('Tournament' => 'ToId', 'Events' => 'EvTournament', 'Session' => 'SesTournament',
               'DistanceInformation' => 'DiTournament', 'Finals' => 'FinTournament',
               'SELEC_Bind' => 'SbTournament') as $t => $col) {
    $rs = safe_r_sql("SELECT COUNT(*) n FROM `$t` WHERE `$col`=$TOUR");
    $reste += intval(safe_fetch($rs)->n);
}
t_eq(0, $reste, 'compétition d\'essai intégralement supprimée');

echo "\n" . str_repeat('═', 70) . "\n";
if ($KO === 0) echo "STRUCTURE OK — $OK vérifications sur la base réelle.\n";
else {
    echo "$KO ÉCHEC(S) sur " . ($OK + $KO) . " vérifications :\n";
    foreach ($ECHECS as $e) echo "$e\n";
}
exit($KO === 0 ? 0 : 1);

