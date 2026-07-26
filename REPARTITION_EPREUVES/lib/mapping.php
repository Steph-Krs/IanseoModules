<?php
/**
 * lib/mapping.php — correspondances épreuve ianseo ↔ classement national FFTA,
 * et catalogue des règles du moteur.
 *
 * Les correspondances sont rangées par « set » de compétition, c'est-à-dire par
 * `Tournament.ToTypeSubRule` (SetFRChampionship, SetFRTAE-Valides, SetFRTAE-Para…).
 * Deux compétitions du même set partagent donc leurs correspondances : on ne les
 * saisit qu'une fois. Chaque ÉPREUVE porte sa propre discipline (depuis 1.4.0,
 * rep_mapping_epreuves_set()) : un même set peut mélanger plusieurs disciplines
 * (TAE International et National, par exemple) sans que l'une écrase l'autre.
 *
 * data/mapping.json figure volontairement dans files[] de version.json : la version
 * du dépôt fait autorité. Une correction faite depuis l'interface doit donc être
 * poussée sur GitHub, sinon la prochaine mise à jour du module la remplacera.
 */

function rep_data_dir()
{
    return dirname(__DIR__) . '/data';
}

/** Lecture du fichier de correspondances. Retourne toujours un tableau exploitable. */
function rep_mapping_lire()
{
    global $REP_MAPPING_CACHE;
    if (is_array($REP_MAPPING_CACHE)) return $REP_MAPPING_CACHE;
    $f = rep_data_dir() . '/mapping.json';
    $j = is_readable($f) ? json_decode(file_get_contents($f), true) : null;
    if (!is_array($j)) $j = [];
    if (!isset($j['sets']) || !is_array($j['sets']))               $j['sets'] = [];
    if (!isset($j['disciplines']) || !is_array($j['disciplines'])) $j['disciplines'] = [];
    $REP_MAPPING_CACHE = $j;
    return $j;
}

/** Écriture du fichier de correspondances (écriture atomique). */
function rep_mapping_ecrire($map)
{
    $f   = rep_data_dir() . '/mapping.json';
    $tmp = $f . '.tmp';
    $txt = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($txt === false) return false;
    if (@file_put_contents($tmp, $txt . "\n") === false) return false;
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    return true;
}

/** Catalogue des règles (sources, parcours, sens, règlement, table TAE). */
function rep_regles()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $f = rep_data_dir() . '/regles.json';
    $j = is_readable($f) ? json_decode(file_get_contents($f), true) : null;
    if (!is_array($j)) {
        $j = [
            'sources'   => [['id' => 0, 'libelle' => 'Classement national']],
            'parcours'  => [['id' => 0, 'libelle' => 'Par cible']],
            'sens'      => [['id' => 0, 'libelle' => 'Croissant'], ['id' => 1, 'libelle' => 'Décroissant']],
            'reglement' => ['max_meme_club_par_cible' => 2, 'texte' => ''],
        ];
    }
    $cache = $j;
    return $cache;
}

/** Nombre maximal d'archers d'un même club sur une cible (règlement fédéral). */
function rep_max_club()
{
    $r = rep_regles();
    return max(1, intval($r['reglement']['max_meme_club_par_cible'] ?? 2));
}

/** Le « set » de la compétition : Tournament.ToTypeSubRule. */
function rep_set_courant($tourId)
{
    $rs = safe_r_sql("SELECT ToTypeSubRule FROM Tournament WHERE ToId=" . intval($tourId));
    $r  = $rs ? safe_fetch($rs) : null;
    return $r ? trim((string) $r->ToTypeSubRule) : '';
}

/** Saison, discipline et set retenus pour une compétition. */
function rep_config_lire($tourId)
{
    $tourId = intval($tourId);
    $set    = rep_set_courant($tourId);

    $rs = safe_r_sql("SELECT * FROM REP_Config WHERE RcTournament=$tourId");
    $r  = $rs ? safe_fetch($rs) : null;
    if ($r) {
        return ['annee' => intval($r->RcAnnee), 'discipline' => rep_disc_valide($r->RcDiscipline), 'set' => $set];
    }

    // Jamais configurée : on reprend la discipline déjà retenue pour ce set.
    $map  = rep_mapping_lire();
    $disc = $map['sets'][$set]['discipline'] ?? '';
    return [
        'annee'      => intval(date('Y')),
        'discipline' => $disc !== '' ? rep_disc_valide($disc) : 'S',
        'set'        => $set,
    ];
}

function rep_config_ecrire($tourId, $annee, $discipline)
{
    $tourId = intval($tourId);
    $disc   = rep_disc_valide($discipline);
    $set    = rep_set_courant($tourId);
    $now    = StrSafe_DB(date('Y-m-d H:i:s'));
    safe_w_sql("INSERT INTO REP_Config (RcTournament, RcAnnee, RcDiscipline, RcSet, RcUpdated)
        VALUES ($tourId, " . intval($annee) . ", " . StrSafe_DB($disc) . ", " . StrSafe_DB($set) . ", $now)
        ON DUPLICATE KEY UPDATE RcAnnee=" . intval($annee) . ",
        RcDiscipline=" . StrSafe_DB($disc) . ", RcSet=" . StrSafe_DB($set) . ", RcUpdated=$now");
}

/**
 * Valeurs par défaut de bloc pour la compétition (préremplissent les nouveaux
 * blocs — action « ajouter » — et peuvent être appliquées d'un coup à tous
 * les blocs existants — action « defaut_appliquer », ajax/blocs.php). Jamais
 * configurées : repli sur les valeurs historiques (celles utilisées avant que
 * ce panneau n'existe — un bloc tout neuf en cible-priorité, ordre alphabétique
 * en source, aucune option).
 */
function rep_bloc_defaut_lire($tourId)
{
    // 0 = REP_SRC_CLASSEMENT, 7 = REP_SRC_ALPHA (lib/moteur.php) — valeurs
    // littérales, pas les constantes : ce fichier peut se charger sans
    // moteur.php (mapping.php ne le requiert pas), les constantes n'y seraient
    // pas forcément définies.
    $defaut = ['inclnp' => 0, 'sc' => 0, 'sl' => 0, 'ciblePriorite' => 1,
               'src' => 0, 'src2' => 7, 'br' => 0, 'serpentin' => 0];
    $rs = safe_r_sql("SELECT RcBlocDefaut FROM REP_Config WHERE RcTournament=" . intval($tourId));
    $r  = $rs ? safe_fetch($rs) : null;
    if ($r && !empty($r->RcBlocDefaut)) {
        $j = json_decode($r->RcBlocDefaut, true);
        if (is_array($j)) $defaut = array_merge($defaut, $j);
    }
    return $defaut;
}

function rep_bloc_defaut_ecrire($tourId, $defaut)
{
    $tourId = intval($tourId);
    $now = StrSafe_DB(date('Y-m-d H:i:s'));
    $json = StrSafe_DB(json_encode($defaut, JSON_UNESCAPED_UNICODE));
    safe_w_sql("INSERT INTO REP_Config (RcTournament, RcBlocDefaut, RcUpdated)
        VALUES ($tourId, $json, $now)
        ON DUPLICATE KEY UPDATE RcBlocDefaut=$json, RcUpdated=$now");
}

/**
 * Épreuves mémorisées pour un set, chaque entrée normalisée avec SA PROPRE
 * discipline. Migration transparente depuis l'ancien format (< 1.4.0) où la
 * discipline était unique pour tout le set (`sets[$set]['discipline']`) : une
 * entrée qui n'a pas encore sa propre discipline hérite de cette valeur ancienne.
 */
function rep_mapping_epreuves_set($map, $set)
{
    $s = $map['sets'][$set] ?? [];
    $legacy = $s['discipline'] ?? '';
    $out = [];
    foreach (($s['epreuves'] ?? []) as $cle => $m) {
        if (!is_array($m)) continue;
        if (empty($m['discipline'])) $m['discipline'] = $legacy;
        $out[$cle] = $m;
    }
    return $out;
}

/**
 * Correspondances applicables pour un set : toutes les épreuves mémorisées,
 * chacune avec sa propre discipline — un même championnat (« Adulte ») peut très
 * bien avoir des épreuves en TAE International et d'autres en TAE National.
 */
function rep_mapping_actif($set)
{
    if ($set === '') return [];
    return rep_mapping_epreuves_set(rep_mapping_lire(), $set);
}

/**
 * Enregistre/fusionne les correspondances d'un set. $epreuves ne contient QUE les
 * lignes à modifier pour CET appel : une entrée (avec sa discipline propre) est
 * ajoutée/mise à jour, une entrée à `null` supprime la correspondance existante.
 * Les épreuves absentes de $epreuves restent inchangées — condition nécessaire
 * pour que parcourir plusieurs disciplines (TAE International puis National, par
 * exemple) et enregistrer à chaque fois n'efface pas ce qui a été saisi sous
 * l'autre discipline (bug réel signalé par l'utilisateur : l'ancien format ne
 * retenait qu'UNE discipline par set, la seconde écrasait la première).
 */
function rep_mapping_enregistrer($set, $epreuves)
{
    if ($set === '') return false;   // sans set, pas de mémorisation d'épreuve
    $map = rep_mapping_lire();
    $existant = rep_mapping_epreuves_set($map, $set);
    foreach ($epreuves as $cle => $val) {
        if ($val === null) { unset($existant[$cle]); continue; }
        $existant[$cle] = $val;
    }
    ksort($existant);
    $map['sets'][$set] = ['epreuves' => $existant];   // format legacy 'discipline' abandonné dès la 1ère réécriture
    ksort($map['sets']);
    if (!rep_mapping_ecrire($map)) return false;
    $GLOBALS['REP_MAPPING_CACHE'] = $map;   // le cache doit refléter l'écriture
    return true;
}

/**
 * Classement FFTA associé à une épreuve, ou null si aucune correspondance. La
 * discipline utilisée est celle mémorisée AVEC l'épreuve (chaque épreuve a la
 * sienne depuis 1.4.0 — un même championnat peut mélanger TAE International et
 * National) ; $discipline (page courante) ne sert que de repli si l'entrée n'en
 * a jamais reçu une (ne devrait plus arriver, rep_mapping_epreuves_set migre).
 * Retourne ['ccid','arrid'=>0,'libelle','nb','maj','arme','categorie','sexe','niveau','discipline'].
 *
 * Ne regarde PAS le classement d'arrêté — voir rep_classement_arrete(), consultée
 * explicitement par le moteur pour les sources qui le demandent (l'arrêté n'est
 * plus une priorité automatique et silencieuse depuis 1.4.0 : c'est un choix de
 * source explicite, comme les autres).
 */
function rep_classement_epreuve($tourId, $cle, $annee, $discipline, $set = null)
{
    if ($set === null) $set = '';

    $act = rep_mapping_actif($set);
    if (empty($act[$cle])) return null;
    $m = $act[$cle];
    $niveau = $m['niveau'] ?? '';
    $disc = $m['discipline'] !== '' ? $m['discipline'] : $discipline;

    $rs = safe_r_sql("SELECT * FROM REP_Classements
        WHERE CcAnnee=" . intval($annee) . "
          AND CcDiscipline=" . StrSafe_DB($disc) . "
          AND CcArme=" . StrSafe_DB($m['arme']) . "
          AND CcCategorie=" . StrSafe_DB($m['categorie']) . "
          AND CcSexe=" . StrSafe_DB($m['sexe']) . "
          AND CcNiveau=" . StrSafe_DB($niveau) . " LIMIT 1");
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r) {
        return ['ccid' => 0, 'arrid' => 0, 'libelle' => '', 'nb' => 0, 'maj' => '', 'arme' => $m['arme'],
                'categorie' => $m['categorie'], 'sexe' => $m['sexe'], 'niveau' => $niveau, 'discipline' => $disc];
    }
    return ['ccid' => intval($r->CcId), 'arrid' => 0, 'libelle' => $r->CcLibelle, 'nb' => intval($r->CcNbArchers),
            'maj' => $r->CcMaj, 'arme' => $r->CcArme, 'categorie' => $r->CcCategorie,
            'sexe' => $r->CcSexe, 'niveau' => $r->CcNiveau, 'discipline' => $disc];
}

/**
 * Classement d'arrêté associé À UNE ÉPREUVE (individuel), ou null. Propre à
 * CETTE compétition (contrairement à REP_Classements/FFTA, réutilisable entre
 * compétitions d'un même set) — jamais consulté automatiquement pour le
 * PLACEMENT : c'est le moteur qui l'appelle explicitement pour les sources
 * « classement de l'arrêté » / « par club selon l'arrêté individuel ».
 *
 * Trois façons de le trouver, dans cet ordre :
 *  1. Association manuelle (REP_ArrMapping, page Import des arrêtés) — utile
 *     pour les cas vraiment ambigus qu'aucune règle automatique ne résout.
 *  2. À défaut, correspondance automatique par division + catégorie COMPOSITE
 *     + sexe de l'épreuve : la catégorie composite se reconstruit depuis les
 *     classes réelles de l'épreuve ($epreuveDef['classes'], ex. « U18H »,
 *     « U21H ») avec rep_imp_categorie_composite() — la MÊME fonction que la
 *     construction du classement (lib/arretes.php), pour que « U18-U21 » des
 *     deux côtés désigne exactement le même regroupement. Ne dépend PAS du
 *     champ 'categorie'/'scratch' de rep_epreuves(), qui vaut à tort 'Scratch'
 *     dès qu'une épreuve regroupe plusieurs catégories d'âge par manque
 *     d'effectif (ex. poulies jeunes) — un vrai faux Scratch, à ne pas
 *     confondre avec une catégorie Élite ouverte à tous âges.
 *  3. Si CE couple division+catégorie+sexe existe sous PLUSIEURS conventions
 *     (compétition qui mélange TAE International et National, chacun sa
 *     propre liste d'archers) : on tranche via la discipline mémorisée pour
 *     CETTE épreuve dans les correspondances FFTA (mapping.php, $set) —
 *     International → F/H, National → M/W (règle validée par l'utilisateur).
 *     Sans discipline mémorisée ou sans correspondance dans le couple
 *     attendu, on reste sur l'ambiguïté : null, association manuelle requise.
 *
 * Même forme de retour que rep_classement_epreuve(), 'arrid' renseigné.
 */
function rep_classement_arrete($tourId, $cle, $epreuveDef = null, $set = '')
{
    $tourId = intval($tourId);
    $acId = 0;
    if (function_exists('rep_arr_mapping_lire')) $acId = rep_arr_mapping_lire($tourId, $cle);

    if ($acId <= 0 && $epreuveDef && !empty($epreuveDef['division'])
        && ($epreuveDef['sexe'] ?? '') !== '' && $epreuveDef['sexe'] !== 'X') {
        $categorie = '';
        if (function_exists('rep_imp_categorie_composite') && !empty($epreuveDef['classes'])) {
            $bare = [];
            // [HFMW] : les 4 lettres de suffixe de classe possibles (F/H = TAE
            // International, M/W = National) — un « W » manquant ici laissait
            // "S3W" au lieu de "S3" et cassait le rapprochement (bug réel : S3WCL
            // retombait en alphabétique faute de correspondance de catégorie).
            foreach ($epreuveDef['classes'] as $c) $bare[] = preg_replace('/[HFMW]$/', '', (string) $c);
            $categorie = rep_imp_categorie_composite($bare);
        }
        if ($categorie !== '') {
            $candidats = [];   // convention (ou '?' si vide) => AcId
            $rsC = safe_r_sql("SELECT AcId, AcConvention FROM REP_ArrClassements
                WHERE AcTournament=$tourId AND AcType='I'
                  AND AcDivision=" . StrSafe_DB($epreuveDef['division']) . "
                  AND AcCategorie=" . StrSafe_DB($categorie) . "
                  AND AcSexe=" . StrSafe_DB($epreuveDef['sexe']));
            while ($rsC && $rC = safe_fetch($rsC)) {
                $candidats[$rC->AcConvention !== '' ? $rC->AcConvention : '?'] = intval($rC->AcId);
            }
            if (count($candidats) === 1) {
                $acId = reset($candidats);
            } elseif (count($candidats) > 1 && $set !== '') {
                $mapAct = function_exists('rep_mapping_actif') ? rep_mapping_actif($set) : [];
                $discEp = $mapAct[$cle]['discipline'] ?? '';
                $convAttendue = ($discEp === 'TN') ? 'MW' : (($discEp === 'TI') ? 'FH' : '');
                if ($convAttendue !== '' && isset($candidats[$convAttendue])) $acId = $candidats[$convAttendue];
            }
        }
    }
    if ($acId <= 0) return null;

    $rs = safe_r_sql("SELECT AcLibelle, AcMaj, (SELECT COUNT(*) FROM REP_ArrRangs WHERE ArClassement=AcId) AS nb
        FROM REP_ArrClassements WHERE AcId=" . intval($acId));
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r) return null;
    return ['ccid' => 0, 'arrid' => $acId, 'libelle' => $r->AcLibelle, 'nb' => intval($r->nb),
            'maj' => $r->AcMaj, 'arme' => '', 'categorie' => '', 'sexe' => '', 'niveau' => '', 'discipline' => ''];
}

/**
 * Classement FFTA suggéré par défaut pour une épreuve, d'après son arme, son sexe
 * et sa catégorie (Scratch → classement Scratch). Retourne la clé
 * arme|categorie|sexe|niveau ou '' si rien de probant. $choix = liste FFTA de la
 * discipline (clé arme|categorie|sexe|niveau => libellé), fournie par la page.
 */
function rep_mapping_suggestion($epreuve, $choix, $discipline)
{
    if (!$choix) return '';
    $armeFfta = rep_arme_ffta($epreuve['division'], $discipline);
    $sexe = $epreuve['sexe'];
    $cat  = $epreuve['categorie'];
    // 1) correspondance exacte arme + catégorie + sexe (niveau vide)
    foreach ($choix as $cle => $lib) {
        list($a, $c, $s, $n) = array_pad(explode('|', $cle), 4, '');
        if ($n === '' && $a === $armeFfta && $s === $sexe && $c === $cat) return $cle;
    }
    // 2) même arme + sexe, catégorie « Scratch » à défaut
    foreach ($choix as $cle => $lib) {
        list($a, $c, $s, $n) = array_pad(explode('|', $cle), 4, '');
        if ($n === '' && $a === $armeFfta && $s === $sexe && strcasecmp($c, 'Scratch') === 0) return $cle;
    }
    return '';
}

/** Nom d'arme FFTA à partir du code de division ianseo (dépend de la discipline). */
function rep_arme_ffta($division, $discipline)
{
    // Campagne : BB = « Arc Nu », LB/AD = « Arc Droit ». 3D : BB = « Arc Nu »,
    // TL = « Arc Libre », AC/AD = « Arc Chasse »/« Arc Droit », CN/CO = « Arc à Poulies nu ».
    $c = strtoupper((string) $discipline);
    $d = strtoupper((string) $division);
    $map = [
        'CL' => 'Arc Classique',
        'CO' => 'Arc à Poulies',
        'BB' => ($c === 'C' || $c === '3' || $c === 'D3') ? 'Arc Nu' : 'Arc Nu',
        'AD' => 'Arc Droit',
        'LB' => 'Arc Droit',
        'TL' => 'Arc Libre',
        'AC' => 'Arc Chasse',
        'CN' => 'Arc à Poulies nu',
    ];
    return $map[$d] ?? '';
}

/**
 * Épreuves individuelles de la compétition, telles que ianseo les regroupe
 * (table `Individuals`, `IndEvent` = code d'épreuve). Une épreuve « Scratch »
 * réunit plusieurs catégories d'âge d'une même arme et d'un même sexe.
 *
 * Retourne [ 'SFCL' => ['event','nom','division','sexe','categorie','scratch',
 *            'classes'=>['S1F',…], 'nb'], … ] indexé par code d'épreuve.
 */
function rep_epreuves($tourId)
{
    $tourId = intval($tourId);
    $out = [];
    $rs = safe_r_sql("SELECT ev.EvCode, ev.EvEventName, ev.EvProgr,
            GROUP_CONCAT(DISTINCT e.EnDivision ORDER BY e.EnDivision SEPARATOR ',') AS divs,
            GROUP_CONCAT(DISTINCT e.EnClass ORDER BY e.EnClass SEPARATOR ',') AS classes,
            GROUP_CONCAT(DISTINCT e.EnSex SEPARATOR ',') AS sexes,
            COUNT(*) AS nb
        FROM Events ev
        JOIN Individuals i ON i.IndTournament=ev.EvTournament AND i.IndEvent=ev.EvCode
        JOIN Entries e ON e.EnId=i.IndId AND e.EnTournament=i.IndTournament AND e.EnAthlete=1
        WHERE ev.EvTournament=$tourId AND ev.EvTeamEvent=0
        GROUP BY ev.EvCode, ev.EvEventName, ev.EvProgr
        ORDER BY ev.EvProgr, ev.EvCode");
    while ($rs && $r = safe_fetch($rs)) {
        $nonVide = function ($v) { return $v !== ''; };   // garder « 0 » (sexe homme)
        $divs    = array_values(array_filter(explode(',', $r->divs), $nonVide));
        $classes = array_values(array_filter(explode(',', $r->classes), $nonVide));
        $sexes   = array_values(array_filter(explode(',', $r->sexes), $nonVide));
        $division = count($divs) === 1 ? $divs[0] : implode('/', $divs);
        $sexe = count($sexes) === 1 ? ($sexes[0] == 1 ? 'F' : 'H') : 'X';

        // Catégorie pour le classement : « Scratch » si plusieurs tranches d'âge,
        // sinon l'âge unique (S1F → S1, U18H → U18).
        $ages = [];
        // [HFMW] : idem rep_classement_arrete() — le suffixe « W » (National,
        // convention M/W) doit être ôté comme F/H/M, sinon "S3W" reste une
        // pseudo-catégorie à part entière au lieu de se réduire à "S3".
        foreach ($classes as $c) $ages[preg_replace('/[HFMW]$/', '', $c)] = true;
        $scratch = count($ages) > 1;
        $categorie = $scratch ? 'Scratch' : (count($ages) === 1 ? key($ages) : '');

        $out[$r->EvCode] = [
            'event'     => $r->EvCode,
            'nom'       => $r->EvEventName !== '' ? $r->EvEventName : $r->EvCode,
            'division'  => $division,
            'sexe'      => $sexe,
            'categorie' => $categorie,
            'scratch'   => $scratch,
            'classes'   => array_values($classes),
            'nb'        => intval($r->nb),
        ];
    }
    return $out;
}

/**
 * Classes (Division+Classe) présentes dans PLUSIEURS épreuves individuelles.
 * Retourne [ 'CLS1H' => ['SFCL','...'], … ] (cas rare à signaler).
 */
function rep_classes_multi_epreuves($tourId)
{
    $tourId = intval($tourId);
    $parClasse = [];
    $rs = safe_r_sql("SELECT DISTINCT i.IndEvent, e.EnDivision, e.EnClass
        FROM Individuals i
        JOIN Entries e ON e.EnId=i.IndId AND e.EnTournament=i.IndTournament AND e.EnAthlete=1
        JOIN Events ev ON ev.EvTournament=i.IndTournament AND ev.EvCode=i.IndEvent AND ev.EvTeamEvent=0
        WHERE i.IndTournament=$tourId");
    while ($rs && $r = safe_fetch($rs)) {
        $parClasse[$r->EnDivision . $r->EnClass][] = $r->IndEvent;
    }
    $out = [];
    foreach ($parClasse as $cls => $evs) {
        $evs = array_values(array_unique($evs));
        if (count($evs) > 1) $out[$cls] = $evs;
    }
    return $out;
}

/** Ordre manuel des clubs d'une épreuve : liste de numéros de club (Countries.CoCode). */
function rep_ordre_clubs_lire($tourId, $event)
{
    $out = [];
    $rs = safe_r_sql("SELECT OoClub FROM REP_OrdreClub
        WHERE OoTournament=" . intval($tourId) . "
          AND OoEvent=" . StrSafe_DB($event) . "
        ORDER BY OoPos");
    while ($rs && $r = safe_fetch($rs)) $out[] = $r->OoClub;
    return $out;
}

/** Enregistre l'ordre manuel des clubs d'une épreuve (remplacement complet). */
function rep_ordre_clubs_ecrire($tourId, $event, $clubs)
{
    $tourId = intval($tourId);
    safe_w_sql("DELETE FROM REP_OrdreClub
        WHERE OoTournament=$tourId AND OoEvent=" . StrSafe_DB($event));
    $pos = 0;
    $vals = [];
    foreach ($clubs as $code) {
        $code = trim((string) $code);
        if ($code === '' || !preg_match('/^[0-9A-Za-z]{1,12}$/', $code)) continue;
        $vals[] = "($tourId, " . StrSafe_DB($event) . ", " . StrSafe_DB($code) . ", " . (++$pos) . ")";
    }
    if ($vals) {
        safe_w_sql("INSERT INTO REP_OrdreClub (OoTournament, OoEvent, OoClub, OoPos)
            VALUES " . implode(',', $vals));
    }
    return $pos;
}

/**
 * Clubs présents dans une épreuve, dans l'ordre effectif : ceux dont l'ordre est
 * enregistré d'abord (dans cet ordre), puis les autres par meilleur classé national.
 * Retourne [ ['code','nom','nb','rang'|null], … ].
 */
function rep_clubs_epreuve($tourId, $event, $annee, $discipline, $set = '')
{
    require_once __DIR__ . '/moteur.php';
    $archers = rep_archers_epreuve($tourId, $event, $annee, $discipline, $set);

    $clubs = [];
    foreach ($archers as $a) {
        $code = $a['clubcode'] !== '' ? $a['clubcode'] : '(sans)';
        if (!isset($clubs[$code])) {
            $clubs[$code] = ['code' => $code, 'nom' => $a['club'] !== '' ? $a['club'] : 'Sans club',
                             'nb' => 0, 'rang' => null];
        }
        $clubs[$code]['nb']++;
        if ($a['rang'] !== null && ($clubs[$code]['rang'] === null || $a['rang'] < $clubs[$code]['rang'])) {
            $clubs[$code]['rang'] = $a['rang'];
        }
    }

    $stored  = rep_ordre_clubs_lire($tourId, $event);
    $ordered = [];
    $vus     = [];
    foreach ($stored as $code) {
        if (isset($clubs[$code])) { $ordered[] = $clubs[$code]; $vus[$code] = true; }
    }
    $reste = [];
    foreach ($clubs as $code => $c) if (!isset($vus[$code])) $reste[] = $c;
    usort($reste, function ($x, $y) {
        if ($x['rang'] === null && $y['rang'] === null) return strcasecmp($x['nom'], $y['nom']);
        if ($x['rang'] === null) return 1;
        if ($y['rang'] === null) return -1;
        return $x['rang'] - $y['rang'];
    });
    return array_merge($ordered, $reste);
}

// rep_classement_epreuve() et rep_classement_arrete() sont définies plus haut
// dans ce fichier (avant rep_mapping_suggestion).
