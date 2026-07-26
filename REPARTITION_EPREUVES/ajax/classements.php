<?php
/** ajax/classements.php — liste et téléchargement des classements nationaux. */
define('HTDOCS', dirname(__DIR__, 4));
require_once dirname(__DIR__) . '/lib/boot.php';
rep_check_token();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$annee  = intval($_POST['annee'] ?? $_GET['annee'] ?? date('Y'));
$disc   = rep_disc_valide($_POST['discipline'] ?? $_GET['discipline'] ?? 'S');

switch ($action) {

    // Matrice armes × catégories : ce que la FFTA publie, croisé avec ce qu'on a en base.
    case 'matrice':
        rep_config_ecrire($REP_TOUR, $annee, $disc);
        $dist = rep_ffta_liste($annee, $disc);
        if (!$dist['ok']) JsonOut(['ok' => false, 'err' => $dist['err']]);
        if (!empty($dist['vide'])) {
            JsonOut(['ok' => true, 'annee' => $annee, 'discipline' => $disc, 'vide' => true,
                     'armes' => [], 'categories' => [], 'cases' => [], 'total' => 0]);
        }
        $locaux = rep_classements_locaux($annee, $disc);

        // Les lignes sont les catégories ÉLÉMENTAIRES : un classement qui en regroupe
        // plusieurs (« U15-U18 », « Scratch ») occupera une seule case fusionnée sur
        // les lignes correspondantes, les en-têtes de ligne restant distinctes.
        $armes  = [];
        $cats   = [];
        $grille = [];
        foreach ($dist['liste'] as $c) {
            // En para, la classification (OPEN, FEDERAL, HV1…) est un niveau de plus :
            // elle devient une colonne à part entière, sinon deux classements
            // différents se retrouveraient dans la même case.
            $col  = ($c['niveau'] !== '' ? $c['niveau'] . ' — ' : '') . $c['arme'];
            $sexe = $c['sexe'] !== '' ? $c['sexe'] : 'X';
            if (!in_array($col, $armes, true)) $armes[] = $col;

            $cle = rep_cle_classement($c['arme'], $c['categorie'], '', $c['niveau']);
            $loc = $locaux[$c['ffta']] ?? null;
            $case = [
                'ffta'     => $c['ffta'],
                'libelle'  => $c['libelle'],
                'distance' => $c['distance'],
                'maj'      => $loc ? $loc['maj'] : '',
                'nb'       => $loc ? $loc['nb'] : 0,
            ];
            // Les classements « Scratch » gardent leur propre ligne : les fusionner sur
            // toutes les catégories qu'ils couvrent masquerait les classements par
            // catégorie qui coexistent avec eux.
            $lignes = ($c['categorie'] === 'Scratch') ? ['Scratch'] : $c['categories'];
            foreach ($lignes as $ce) {
                if (!in_array($ce, $cats, true)) $cats[] = $ce;
                if (!isset($grille[$col][$ce])) {
                    $grille[$col][$ce] = ['cle' => $cle, 'libelle' => $c['libelle'],
                                          'groupe' => $c['categorie'], 'sexes' => []];
                }
                $grille[$col][$ce]['sexes'][$sexe] = $case;
            }
        }

        // Ordre des lignes : du plus jeune au plus âgé, puis les intitulés inconnus.
        $ordre = rep_ordre_categories();
        usort($cats, function ($a, $b) use ($ordre) {
            $ia = array_search($a, $ordre, true);
            $ib = array_search($b, $ordre, true);
            if ($ia === false && $ib === false) return strcasecmp($a, $b);
            if ($ia === false) return 1;
            if ($ib === false) return -1;
            return $ia - $ib;
        });

        JsonOut(['ok' => true, 'annee' => $annee, 'discipline' => $disc, 'vide' => false,
                 'armes' => $armes, 'categories' => $cats, 'grille' => $grille,
                 'total' => count($dist['liste'])]);
        break;

    // Téléchargement d'un lot de classements, désignés par leur identifiant FFTA.
    case 'telecharger':
        $ids = $_POST['ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        if (!$ids) JsonOut(['ok' => false, 'err' => 'Aucun classement sélectionné.']);
        if (count($ids) > 60) JsonOut(['ok' => false, 'err' => 'Trop de classements d\'un coup (60 maximum).']);

        $dist = rep_ffta_liste($annee, $disc);
        if (!$dist['ok']) JsonOut(['ok' => false, 'err' => $dist['err']]);
        if (!empty($dist['vide'])) JsonOut(['ok' => false, 'err' => 'Aucun classement publié pour cette discipline.']);
        $index = [];
        foreach ($dist['liste'] as $c) $index[$c['ffta']] = $c;

        $ok = 0; $nb = 0; $err = [];
        foreach ($ids as $id) {
            if (empty($index[$id])) { $err[] = "classement $id introuvable dans la saison"; continue; }
            $r = rep_ffta_enregistrer($annee, $disc, $index[$id]);
            if ($r['ok']) { $ok++; $nb += $r['nb']; }
            else          { $err[] = $index[$id]['libelle'] . ' : ' . $r['err']; }
        }
        JsonOut(['ok' => empty($err), 'charges' => $ok, 'archers' => $nb,
                 'err' => $err ? implode(' · ', $err) : '']);
        break;

    // Suppression ciblée : des classements désignés par leur identifiant FFTA.
    case 'supprimer':
        $ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        if (!$ids) JsonOut(['ok' => false, 'err' => 'Aucun classement à supprimer.']);
        $in = implode(',', $ids);
        safe_w_sql("DELETE r FROM REP_Rangs r
            JOIN REP_Classements c ON c.CcId = r.CrClassement WHERE c.CcFfta IN ($in)");
        $rs = safe_r_sql("SELECT COUNT(*) AS n FROM REP_Classements WHERE CcFfta IN ($in)");
        $n  = ($rs && $r = safe_fetch($rs)) ? intval($r->n) : 0;
        safe_w_sql("DELETE FROM REP_Classements WHERE CcFfta IN ($in)");
        JsonOut(['ok' => true, 'supprimes' => $n]);
        break;

    // Vidage complet : tous les classements, toutes saisons et disciplines. Ne touche
    // ni aux plans de départ (REP_Blocs) ni aux cibles déjà écrites dans Qualifications.
    case 'vider':
        $rs = safe_r_sql("SELECT COUNT(*) AS n FROM REP_Classements");
        $n  = ($rs && $r = safe_fetch($rs)) ? intval($r->n) : 0;
        safe_w_sql("DELETE FROM REP_Rangs");
        safe_w_sql("DELETE FROM REP_Classements");
        JsonOut(['ok' => true, 'supprimes' => $n]);
        break;

    default:
        JsonOut(['ok' => false, 'err' => 'Action inconnue.']);
}
