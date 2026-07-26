<?php
/** ajax/import.php — assistant « Import des arrêtés ». */
define('HTDOCS', dirname(__DIR__, 4));
require_once dirname(__DIR__) . '/lib/boot.php';
rep_check_token();

$action = $_POST['action'] ?? $_GET['action'] ?? 'etat';

/** État complet renvoyé à chaque étape : fichiers, consolidation, classements, épreuves. */
function rep_imp_etat_complet($tourId)
{
    $etat = rep_imp_etat_lire($tourId);
    $r = rep_imp_consolider($tourId);

    $set = rep_set_courant($tourId);
    $epr = rep_epreuves($tourId);
    $epreuves = [];
    foreach ($epr as $cle => $e) {
        $acId = rep_arr_mapping_lire($tourId, $cle);
        $suggere = false;
        if ($acId <= 0) {
            // Préremplit avec la même correspondance automatique que le moteur
            // (division + catégorie composite + sexe, désambiguïsée par
            // discipline si plusieurs sous-disciplines coexistent) : demandé
            // par l'utilisateur pour éviter une association manuelle inutile
            // dans le cas courant. Pas enregistrée tant que l'utilisateur ne
            // confirme pas (aucun appel à rep_arr_mapping_ecrire ici).
            $cl = rep_classement_arrete($tourId, $cle, $e, $set);
            if ($cl && !empty($cl['arrid'])) { $acId = intval($cl['arrid']); $suggere = true; }
        }
        $epreuves[] = ['cle' => $cle, 'nom' => $e['nom'], 'division' => $e['division'],
                       'sexe' => $e['sexe'], 'categorie' => $e['categorie'], 'classement' => $acId,
                       'suggere' => $suggere];
    }

    return [
        'ok' => true,
        'fichiers' => $etat['fichiers'],
        'consolide' => $r['consolide'],
        'nbConflits' => count($r['conflits']),
        'classements' => rep_arr_classements_liste($tourId),
        'epreuves' => $epreuves,
        'ofCoaOk' => rep_imp_of_coa_ok($tourId),
        'directPossible' => rep_imp_direct_possible(),
    ];
}

switch ($action) {

    case 'etat':
        JsonOut(rep_imp_etat_complet($REP_TOUR));
        break;

    case 'televerser':
        if (empty($_FILES['fichier']['name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            JsonOut(['ok' => false, 'err' => 'Aucun fichier reçu.']);
        }
        $nom = $_FILES['fichier']['name'];
        $rows = rep_imp_lire_fichier($_FILES['fichier']['tmp_name'], $nom);
        if (!$rows) {
            JsonOut(['ok' => false, 'err' => "Fichier illisible (xlsx/csv attendu) : $nom"]);
        }
        $detect = rep_imp_detecter($rows);
        if ($detect['type'] === '') {
            JsonOut(['ok' => false, 'err' => $detect['err'], 'inconnues' => $detect['inconnues']]);
        }

        // Validation minimale : sans ces champs, la ligne ne serait pas exploitable.
        $requis = $detect['type'] === 'individuel'
            ? ['licence', 'archer_complet', 'quota']
            : ['clubcode'];
        $dicoChamps = array_values($detect['dico']);
        $manquants = [];
        foreach ($requis as $c) if (!in_array($c, $dicoChamps, true)) $manquants[] = $c;
        if ($manquants) {
            JsonOut(['ok' => false,
                'err' => "En-tête reconnu comme « " . $detect['type'] . " » mais colonnes essentielles absentes : "
                       . implode(', ', $manquants) . ". Vérifiez le fichier.",
                'inconnues' => $detect['inconnues']]);
        }
        if ($detect['type'] === 'equipe' && $detect['nbArchers'] < 2) {
            JsonOut(['ok' => false, 'err' => "Dépôt d'équipe reconnu mais aucune colonne LICENCE1/2… trouvée."]);
        }

        // Convention de suffixe de classe (F/H ou M/W) : ne se lit nulle part dans
        // le contenu du fichier, seulement dans son nom — jamais appliquée sans
        // confirmation de l'utilisateur (rep_imp_convention_depuis_nom() ne fait
        // que pré-remplir le sélecteur côté assistant).
        $convention = isset($_POST['convention']) && $_POST['convention'] !== ''
            ? (((string) $_POST['convention']) === 'MW' ? 'MW' : 'FH')
            : rep_imp_convention_depuis_nom($nom);

        $lignes = rep_imp_parser($rows, $detect, $convention);
        if (!$lignes) {
            JsonOut(['ok' => false, 'err' => "Aucune ligne exploitable dans ce fichier (en-tête reconnu, mais 0 ligne de données)."]);
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') $label = $detect['type'] === 'equipe' ? $detect['sousType'] : 'individuel';
        $ficheId = rep_imp_ajouter_fichier($REP_TOUR, $detect['type'], $label, $nom, $lignes, $convention);

        $etat = rep_imp_etat_complet($REP_TOUR);
        $etat['fiche'] = ['id' => $ficheId, 'type' => $detect['type'], 'sousType' => $detect['sousType'],
                           'nb' => count($lignes), 'inconnues' => array_values($detect['inconnues'])];
        JsonOut($etat);
        break;

    case 'supprimer_fichier':
        rep_imp_supprimer_fichier($REP_TOUR, intval($_POST['id'] ?? 0));
        JsonOut(rep_imp_etat_complet($REP_TOUR));
        break;

    case 'reinitialiser':
        rep_imp_reinitialiser($REP_TOUR);
        JsonOut(rep_imp_etat_complet($REP_TOUR));
        break;

    case 'resoudre':
        $licence = (string) ($_POST['licence'] ?? '');
        $role    = (string) ($_POST['role'] ?? '');
        $champ   = (string) ($_POST['champ'] ?? '');
        $valeur  = (string) ($_POST['valeur'] ?? '');
        if ($licence === '' || $role === '' || $champ === '') {
            JsonOut(['ok' => false, 'err' => 'Paramètres incomplets.']);
        }
        rep_imp_resoudre($REP_TOUR, $licence, $role, $champ, $valeur);
        JsonOut(rep_imp_etat_complet($REP_TOUR));
        break;

    case 'resoudre_tout':
        $n = rep_imp_resoudre_tout($REP_TOUR);
        $etat = rep_imp_etat_complet($REP_TOUR);
        $etat['resolus'] = $n;
        JsonOut($etat);
        break;

    case 'construire_classements':
        $ind = rep_imp_classement_individuel_construire($REP_TOUR);
        $equ = rep_imp_classement_equipe_construire($REP_TOUR);
        // Associe tout de suite les suggestions non ambiguës : sans ça, la
        // construction ne fait qu'afficher un badge « suggéré » et il fallait
        // rouvrir chaque sélecteur d'épreuve pour l'enregistrer réellement —
        // un clic devait en fait en cacher deux (bug d'ergonomie signalé).
        $assoc = rep_arr_associer_suggestions($REP_TOUR, rep_set_courant($REP_TOUR));
        $etat = rep_imp_etat_complet($REP_TOUR);
        $etat['construits'] = ['individuel' => $ind, 'equipe' => $equ];
        $etat['associes'] = $assoc;
        JsonOut($etat);
        break;

    case 'reinitialiser_classements':
        $n = rep_arr_classements_reinitialiser($REP_TOUR);
        $etat = rep_imp_etat_complet($REP_TOUR);
        $etat['classementsReinitialises'] = $n;
        JsonOut($etat);
        break;

    case 'associer_classement':
        $event = (string) ($_POST['event'] ?? '');
        $acId  = intval($_POST['classement'] ?? 0);
        if ($event === '') JsonOut(['ok' => false, 'err' => 'Épreuve manquante.']);
        rep_arr_mapping_ecrire($REP_TOUR, $event, $acId);
        JsonOut(rep_imp_etat_complet($REP_TOUR));
        break;

    case 'apercu_ecriture':
        $p = rep_imp_preparer_ecriture($REP_TOUR);
        JsonOut(['ok' => true, 'pretes' => $p['pretes'], 'ignorees' => $p['ignorees']]);
        break;

    case 'telecharger':
        $p = rep_imp_preparer_ecriture($REP_TOUR);
        $avecEntete = !empty($_POST['entete']);
        $avecBom    = !empty($_POST['bom']);
        JsonOut(['ok' => true, 'contenu' => rep_imp_generer_csv($p['pretes'], $avecEntete, $avecBom),
                  'nb' => count($p['pretes']), 'ignorees' => count($p['ignorees'])]);
        break;

    case 'ecrire_direct':
        if (!rep_imp_direct_possible()) {
            JsonOut(['ok' => false, 'err' => "Votre compte n'a pas les droits « Participants » requis par l'import natif ianseo. Utilisez le fichier à réimporter manuellement."]);
        }
        $p = rep_imp_preparer_ecriture($REP_TOUR);
        if (!$p['pretes']) {
            JsonOut(['ok' => false, 'err' => 'Aucune ligne prête à importer (tout est déjà présent, en conflit, ou incomplet).']);
        }
        $res = rep_imp_ecrire_direct($REP_TOUR, $p['pretes']);
        $res['ignorees'] = count($p['ignorees']);
        $res['tentees'] = count($p['pretes']);
        JsonOut($res);
        break;

    case 'dm_apercu':
        JsonOut(['ok' => true, 'clubs' => rep_imp_dm_clubs($REP_TOUR)]);
        break;

    case 'dm_appliquer':
        $enIds = array_filter(array_map('intval', explode(',', (string) ($_POST['enids'] ?? ''))));
        $n = rep_imp_dm_appliquer($REP_TOUR, $enIds);
        JsonOut(['ok' => true, 'n' => $n]);
        break;

    // Mode automatique (demandé par l'utilisateur) : enchaîne en un seul appel
    // construction des classements + association automatique, écriture directe
    // dans ianseo, et propagation double mixte par club — exactement les étapes
    // que l'utilisateur faisait à la main l'une après l'autre sans rien avoir à
    // changer sur les fichiers déjà testés. S'arrête AVANT toute écriture s'il
    // reste un conflit non résolu (division/classe/club — jamais nom/prénom/sexe,
    // qui ne sont plus des conflits depuis cette version) : seule une
    // incohérence réellement problématique doit faire intervenir l'utilisateur.
    case 'mode_auto':
        $r = rep_imp_consolider($REP_TOUR);
        $bloquants = [];
        foreach ($r['consolide'] as $c) {
            if (!empty($c['conflit'])) {
                $bloquants[] = ['licence' => $c['licence'], 'nom' => trim($c['nom'] . ' ' . $c['prenom']),
                    'champs' => implode(', ', array_keys($c['candidats']))];
            }
        }
        if ($bloquants) {
            $etat = rep_imp_etat_complet($REP_TOUR);
            $etat['modeAuto'] = ['ok' => false, 'bloquants' => $bloquants];
            JsonOut($etat);
        }

        $set = rep_set_courant($REP_TOUR);
        $ind = rep_imp_classement_individuel_construire($REP_TOUR);
        $equ = rep_imp_classement_equipe_construire($REP_TOUR);
        $assoc = rep_arr_associer_suggestions($REP_TOUR, $set);

        $ecriture = null;
        $directOk = rep_imp_direct_possible();
        if ($directOk) {
            $p = rep_imp_preparer_ecriture($REP_TOUR);
            if ($p['pretes']) {
                $ecriture = rep_imp_ecrire_direct($REP_TOUR, $p['pretes']);
                $ecriture['ignorees'] = count($p['ignorees']);
                $ecriture['tentees'] = count($p['pretes']);
            } else {
                $ecriture = ['ok' => true, 'tentees' => 0, 'ignorees' => count($p['ignorees']),
                    'manquantes' => [], 'modifications' => []];
            }
        }

        // Propagation double mixte par club : coche tous les archers éligibles
        // pas encore marqués (jamais ceux déjà à 1, jamais un retrait).
        $enidsDm = [];
        foreach (rep_imp_dm_clubs($REP_TOUR) as $club) {
            foreach ($club['archers'] as $a) if (empty($a['dejaDm'])) $enidsDm[] = $a['enid'];
        }
        $dmN = $enidsDm ? rep_imp_dm_appliquer($REP_TOUR, $enidsDm) : 0;

        $etat = rep_imp_etat_complet($REP_TOUR);
        $etat['modeAuto'] = [
            'ok' => true,
            'classements' => ['individuel' => count($ind), 'equipe' => count($equ)],
            'associes' => $assoc,
            'directPossible' => $directOk,
            'ecriture' => $ecriture,
            'dm' => $dmN,
        ];
        JsonOut($etat);
        break;

    default:
        JsonOut(['ok' => false, 'err' => 'Action inconnue.']);
}
