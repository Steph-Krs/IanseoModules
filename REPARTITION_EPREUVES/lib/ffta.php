<?php
/**
 * lib/ffta.php — client de l'iframe publique des classements nationaux FFTA.
 *
 * L'iframe intégrée sur ffta.fr/vie-sportive/resultats/classements-nationaux est
 * servie par l'extranet et répond SANS authentification. Deux appels suffisent :
 *   1. POST /iframe/classements.html  → la liste des classements d'une saison
 *   2. GET  /iframe/classements/{id}.html → le classement lui-même
 *
 * Trois pièges vérifiés en production :
 *  - la colonne « Arme » ne reprend pas les mots du libellé (en Campagne, le Bare
 *    Bow y est « Arc Nu » et le Longbow « Arc Droit ») ;
 *  - le nombre de colonnes change d'une discipline à l'autre : l'extérieur et le
 *    para ajoutent une colonne « Distance » → on lit l'en-tête, jamais des positions ;
 *  - dans un classement, la colonne « Cat » disparaît quand une seule catégorie
 *    d'âge est couverte → on repère les colonnes par leur format.
 */

define('REP_FFTA_BASE', 'https://extranet.ffta.fr');
define('REP_FFTA_UA', 'Mozilla/5.0 (compatible; ianseo-REPARTITION_EPREUVES/1.0)');

// Année la plus ancienne proposée dans les sélecteurs (les classements FFTA
// remontent plus loin, mais rien d'utile pour attribuer des cibles aujourd'hui).
if (!defined('REP_ANNEE_MIN')) define('REP_ANNEE_MIN', 2024);

/**
 * Disciplines du module, dans l'ordre d'affichage voulu.
 * « ffta » est le code envoyé à l'extranet, « sous » la sous-discipline éventuelle.
 * Aucun code n'est numérique : une clé « 3 » deviendrait un entier en PHP et les
 * comparaisons strictes avec la valeur d'un formulaire échoueraient silencieusement.
 */
function rep_disciplines()
{
    return [
        'S'  => ['ffta' => 'S', 'sous' => '',   'lib' => 'Tir à 18 m'],
        'TI' => ['ffta' => 'T', 'sous' => 'TI', 'lib' => "Tir à l'Arc Extérieur International"],
        'TN' => ['ffta' => 'T', 'sous' => 'TN', 'lib' => "Tir à l'Arc Extérieur National"],
        'C'  => ['ffta' => 'C', 'sous' => '',   'lib' => 'Tir en Campagne'],
        'D3' => ['ffta' => '3', 'sous' => '',   'lib' => 'Tir 3D'],
        'N'  => ['ffta' => 'N', 'sous' => '',   'lib' => 'Tir Nature'],
        'I'  => ['ffta' => 'I', 'sous' => '',   'lib' => "Para-tir à l'arc à 18 m"],
        'H'  => ['ffta' => 'H', 'sous' => '',   'lib' => "Para-tir à l'arc en extérieur"],
        'B'  => ['ffta' => 'B', 'sous' => '',   'lib' => 'Tir Beursault'],
    ];
}

/** Libellés seuls, pour les sélecteurs. */
function rep_ffta_disciplines()
{
    $out = [];
    foreach (rep_disciplines() as $code => $d) $out[$code] = $d['lib'];
    return $out;
}

/** Code de discipline valide, sinon la première de la liste. */
function rep_disc_valide($code)
{
    $d = rep_disciplines();
    $code = (string) $code;
    return isset($d[$code]) ? $code : 'S';
}

function rep_disc_lib($code)
{
    $d = rep_disciplines();
    return $d[(string) $code]['lib'] ?? (string) $code;
}

/** Les disciplines para demandent un niveau de classification en plus. */
function rep_disc_para($code)
{
    return in_array((string) $code, ['I', 'H'], true);
}

/**
 * Requête HTTP. $post = null → GET.
 * Retourne ['ok'=>bool, 'body'=>string, 'err'=>string, 'code'=>int].
 */
function rep_http($url, $post = null, $timeout = 40)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_USERAGENT      => REP_FFTA_UA,
            CURLOPT_ENCODING       => '',
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'body' => '', 'err' => $err ?: 'échec cURL', 'code' => $code];
        if ($code >= 400)    return ['ok' => false, 'body' => '', 'err' => 'HTTP ' . $code, 'code' => $code];
        return ['ok' => true, 'body' => $body, 'err' => '', 'code' => $code];
    }

    $opts = ['http' => [
        'method'  => $post === null ? 'GET' : 'POST',
        'header'  => "User-Agent: " . REP_FFTA_UA . "\r\n"
                   . ($post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
        'content' => $post === null ? '' : http_build_query($post),
        'timeout' => $timeout,
    ]];
    $body = @file_get_contents($url, false, stream_context_create($opts));
    if ($body === false) return ['ok' => false, 'body' => '', 'err' => 'requête impossible (cURL absent)', 'code' => 0];
    return ['ok' => true, 'body' => $body, 'err' => '', 'code' => 200];
}

/** Nettoie le contenu d'une cellule HTML : balises, entités, espaces. */
function rep_cellule($html)
{
    $txt = preg_replace('#<br\s*/?>#i', ' ', $html);
    $txt = strip_tags($txt);
    $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = preg_replace('/\s+/u', ' ', $txt);
    return trim($txt);
}

/** Découpe une ligne <tr> en cellules nettoyées. */
function rep_cellules($tr)
{
    $out = [];
    if (preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#si', $tr, $m)) {
        foreach ($m[1] as $c) $out[] = rep_cellule($c);
    }
    return $out;
}

/**
 * Idem, mais SANS strip_tags : sert à repérer une information qui n'existe que
 * dans une image/icône (ex. « Pré-inscrit » du classement, une coche sans texte).
 */
function rep_cellules_brutes($tr)
{
    $out = [];
    if (preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#si', $tr, $m)) {
        foreach ($m[1] as $c) $out[] = $c;
    }
    return $out;
}

/** « Homme » → H, « Femme » → F, « Mixte » → X. */
function rep_sexe_code($txt)
{
    $t = mb_strtolower(trim($txt), 'UTF-8');
    if (strpos($t, 'homme') === 0) return 'H';
    if (strpos($t, 'femme') === 0) return 'F';
    if (strpos($t, 'mixte') === 0) return 'X';
    return '';
}

/**
 * Code de catégorie d'âge : « U15 - U18 » → « U15-U18 », « U21 - Senior 1 » → « U21-S1 ».
 * Le cas « Scratch » se reconnaît au libellé, pas au nombre de tranches d'âge : la FFTA
 * nomme « Scratch … » aussi bien un classement couvrant 7 catégories qu'un autre en
 * couvrant 4.
 */
function rep_categorie_code($txt, $libelle = '')
{
    // Ancré au début : en para, « OPEN Scratch … » et « CHALLENGE Scratch … » ne
    // couvrent pas les mêmes tranches d'âge, il faut les conserver.
    if ($libelle !== '' && preg_match('/^\s*scratch\b/ui', $libelle)) return 'Scratch';
    return implode('-', rep_categories_elementaires($txt));
}

/**
 * Décompose la colonne « Catégorie d'âge » en catégories élémentaires :
 * « U15 - U18 » → ['U15','U18'], « U21 - Senior 1 » → ['U21','S1'].
 * Sert à fusionner les cases de la matrice sans perdre les en-têtes de ligne.
 */
function rep_categories_elementaires($txt)
{
    $codes = [];
    foreach (preg_split('/\s*-\s*/u', trim($txt)) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^Senior\s*([123])$/ui', $p, $mm)) $codes[] = 'S' . $mm[1];
        elseif (preg_match('/^(U\d{2})$/ui', $p, $mm))     $codes[] = mb_strtoupper($mm[1], 'UTF-8');
        else                                               $codes[] = $p;
    }
    return $codes;
}

/**
 * Ordre d'affichage des catégories, du plus jeune au plus âgé. « Scratch » ferme
 * la marche : ces classements gardent leur propre ligne au lieu d'être fusionnés
 * sur toutes les catégories qu'ils couvrent.
 */
function rep_ordre_categories()
{
    return ['U11', 'U13', 'U15', 'U18', 'U21', 'S1', 'S2', 'S3', 'Scratch'];
}

/**
 * Niveau de classification para, lu au début du libellé.
 * Sans lui, « OPEN Scratch Hommes Arc Classique » et « FEDERAL Scratch Hommes Arc
 * Classique » seraient indiscernables : même arme, même catégorie, même sexe.
 */
function rep_niveau_code($libelle)
{
    $niveaux = ['HV LIBRE', 'HV2-3', 'HV1', 'SUPPORT 1', 'SUPPORT 2', 'W1',
                'OPEN', 'FEDERAL', 'CHALLENGE', 'CRITERIUM'];
    $l = mb_strtoupper(trim($libelle), 'UTF-8');
    foreach ($niveaux as $n) {
        if (strpos($l, $n) === 0) return $n;
    }
    return '';
}

/**
 * Sous-discipline du Tir à l'Arc Extérieur : international ou national.
 * La FFTA les publie sous un seul code de discipline ; c'est le couple
 * distance / blason qui les sépare (cf. le règlement fédéral des distances).
 * Table surchargeable dans data/regles.json (clé « tae »).
 */
function rep_tae_sous($arme, $categorie, $distance)
{
    $reg = function_exists('rep_regles') ? rep_regles() : [];
    if (!empty($reg['tae']['exceptions'])) {
        foreach ($reg['tae']['exceptions'] as $e) {
            if (($e['arme'] ?? '') === $arme
                && ($e['distance'] ?? '') === $distance
                && (empty($e['categorie']) || $e['categorie'] === $categorie)) {
                return $e['sous'];
            }
        }
    }

    $d = preg_replace('/\s+/', '', mb_strtolower($distance, 'UTF-8')); // « 70m-122cm »
    $a = mb_strtolower($arme, 'UTF-8');

    if (strpos($a, 'nu') !== false) return 'TN';           // l'arc nu n'existe qu'en national

    if (strpos($a, 'poulies') !== false) {
        if ($d === '50m-80cm' || $d === '40m-80cm') return 'TI';
        return 'TN';                                       // 30m-80cm et 50m-122cm
    }

    // Arc classique
    if ($d === '70m-122cm' || $d === '60m-122cm' || $d === '40m-80cm') return 'TI';
    if ($d === '50m-122cm' || $d === '15m-80cm') return 'TN';
    if ($d === '30m-80cm') return ($categorie === 'U13') ? 'TI' : 'TN';
    if ($d === '20m-80cm') return ($categorie === 'U11') ? 'TI' : 'TN';
    return 'TN';
}

/**
 * Liste des classements d'une discipline du module.
 * Retourne ['ok','err','liste'=>[ ['ffta','libelle','sexe','arme','categorie',
 *           'niveau','distance'], … ]]
 */
function rep_ffta_liste($annee, $discipline, $type = 'I')
{
    $discipline = rep_disc_valide($discipline);
    $def = rep_disciplines()[$discipline];

    $r = rep_http(REP_FFTA_BASE . '/iframe/classements.html', [
        'operation'          => 'search',
        'search[Annee]'      => $annee,
        'search[Type]'       => $type,
        'search[Sexe]'       => 'all',
        'search[Discipline]' => $def['ffta'],
        'search[Catage]'     => 'all',
        'search[Arme]'       => 'all',
        'search[Distance]'   => 'all',
        'StartGen'           => 'Filtrer',
    ]);
    if (!$r['ok']) return ['ok' => false, 'err' => $r['err'], 'liste' => []];

    // Les colonnes sont repérées par l'en-tête : l'extérieur et le para en ont une
    // de plus (Distance) que la salle, la campagne ou le 3D.
    $col = ['nom' => 0, 'distance' => -1, 'sexe' => 2, 'arme' => 3, 'cat' => 4];
    if (preg_match('#<thead>(.*?)</thead>#si', $r['body'], $mh)) {
        foreach (rep_cellules($mh[1]) as $i => $t) {
            $t = mb_strtolower($t, 'UTF-8');
            if (strpos($t, 'nom') === 0)        $col['nom'] = $i;
            elseif (strpos($t, 'distance') === 0) $col['distance'] = $i;
            elseif (strpos($t, 'sexe') === 0)     $col['sexe'] = $i;
            elseif (strpos($t, 'arme') === 0)     $col['arme'] = $i;
            elseif (strpos($t, 'cat') === 0)      $col['cat'] = $i;
        }
    }

    $liste = [];
    if (preg_match_all('#<tr[^>]*data-href="/iframe/classements/(\d+)\.html"[^>]*>(.*?)</tr>#si',
                       $r['body'], $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $c = rep_cellules($row[2]);
            if (!isset($c[$col['cat']], $c[$col['arme']], $c[$col['nom']])) continue;

            $libelle = $c[$col['nom']];
            $dist    = ($col['distance'] >= 0 && isset($c[$col['distance']])) ? $c[$col['distance']] : '';
            $arme    = $c[$col['arme']];
            $cat     = rep_categorie_code($c[$col['cat']], $libelle);

            if ($def['sous'] !== '' && rep_tae_sous($arme, $cat, $dist) !== $def['sous']) continue;

            $liste[] = [
                'ffta'       => intval($row[1]),
                'libelle'    => $libelle,
                'sexe'       => rep_sexe_code($c[$col['sexe']] ?? ''),
                'arme'       => $arme,
                'categorie'  => $cat,
                'categories' => rep_categories_elementaires($c[$col['cat']]),
                'niveau'     => rep_disc_para($discipline) ? rep_niveau_code($libelle) : '',
                'distance'   => $dist,
            ];
        }
    }
    if (!$liste) {
        // Une discipline peut n'avoir aucun classement publié (le Beursault, par
        // exemple) : ce n'est pas une panne. On ne parle d'erreur que si la page
        // annonce des résultats qu'on n'a pas su lire.
        $annonce = preg_match('/R.sultats\s*:\s*(\d+)/ui', $r['body'], $mr) ? intval($mr[1]) : 0;
        if ($annonce > 0) {
            return ['ok' => false, 'liste' => [], 'vide' => false,
                    'err' => "$annonce classements annoncés mais aucun lu — la page de l'extranet a changé"];
        }
        return ['ok' => true, 'err' => '', 'liste' => [], 'vide' => true];
    }
    return ['ok' => true, 'err' => '', 'liste' => $liste, 'vide' => false];
}

/** Clé d'identité d'un classement, côté FFTA comme côté correspondances. */
function rep_cle_classement($arme, $categorie, $sexe, $niveau = '')
{
    return $arme . '|' . $categorie . '|' . $sexe . '|' . $niveau;
}

/** Identifiant FFTA d'un classement à partir de sa clé, dans une liste $dist. 0 si absent. */
function rep_ffta_id_pour($dist, $cle)
{
    if (empty($dist['liste'])) return 0;
    foreach ($dist['liste'] as $c) {
        if (rep_cle_classement($c['arme'], $c['categorie'], $c['sexe'], $c['niveau']) === $cle) {
            return intval($c['ffta']);
        }
    }
    return 0;
}

/**
 * Lecture d'un classement : liste ordonnée des archers.
 * Retourne ['ok','err','titre','archers'=>[ ['rang','licence','nom','categorie',
 *           'clubcode','clubnom','moyenne','s1','s2','s3','quota','preinscrit'], … ]]
 * s1/s2/s3 : les scores comptant pour le classement, triés décroissant (s1 = le
 * meilleur) — s3 vaut 0 pour les disciplines qui n'en comptent que 2 (ex. Para
 * extérieur). quota : place de qualification au Championnat de France (0 = aucune).
 * preinscrit : 1 si l'archer s'est pré-inscrit au Championnat de France (« Hors
 * quota » y compte aussi comme pré-inscrit — seule l'icône est lue, la nuance
 * reste visible via `quota`).
 */
function rep_ffta_classement($fftaId)
{
    $r = rep_http(REP_FFTA_BASE . '/iframe/classements/' . intval($fftaId) . '.html');
    if (!$r['ok']) return ['ok' => false, 'err' => $r['err'], 'titre' => '', 'archers' => []];

    $titre = '';
    if (preg_match('#<h[1-6][^>]*>\s*(Classement[^<]*)</h[1-6]>#si', $r['body'], $mt)) {
        $titre = rep_cellule($mt[1]);
    }

    $archers = [];
    if (preg_match_all('#<tr[^>]*>(.*?)</tr>#si', $r['body'], $m)) {
        foreach ($m[1] as $tr) {
            $c = rep_cellules($tr);
            if (count($c) < 8) continue;

            // Colonnes repérées par leur format : la FFTA supprime la colonne « Cat »
            // quand le classement ne couvre qu'une seule catégorie d'âge.
            $li = -1;
            foreach ($c as $i => $val) {
                if (preg_match('/^\d{6,8}[A-Za-z]$/', $val)) { $li = $i; break; }
            }
            if ($li < 0) continue;

            // Numéro de club : 7 caractères commençant par un chiffre. Pas « 7 chiffres » :
            // la Corse utilise 052A005. La longueur le distingue de la licence (8).
            $ci = -1;
            for ($i = $li + 2; $i < count($c) - 1; $i++) {
                if (preg_match('/^[0-9][0-9A-Z]{6}$/i', $c[$i])) { $ci = $i; break; }
            }
            if ($ci < 0) continue;

            $rang  = ($li >= 1 && ctype_digit($c[$li - 1])) ? intval($c[$li - 1]) : count($archers) + 1;
            $quota = ($li >= 2 && ctype_digit($c[$li - 2])) ? intval($c[$li - 2]) : 0;

            // Préinscription au Championnat de France : une icône (check.png, titre
            // « Pré-inscrit » [- Hors quota]), SANS texte une fois strip_tags passé —
            // seule la cellule BRUTE (avant nettoyage) le révèle. Toujours 3 cellules
            // avant la licence (Inscr., Quota, Rang), quelle que soit la discipline
            // (vérifié : présent même quand la colonne « Cat » disparaît, qui elle est
            // APRÈS la licence). « Hors quota » (pré-inscrit au-delà du quota) compte
            // ici comme pré-inscrit — seul un OUI/NON est demandé, la nuance reste
            // visible via `quota` (0 = pas de place de qualification propre).
            $preinscrit = 0;
            if ($li >= 3) {
                $brut = rep_cellules_brutes($tr);
                if (isset($brut[$li - 3]) && stripos($brut[$li - 3], 'check.png') !== false) $preinscrit = 1;
            }

            // Colonnes de score : « S1 S2 [S3] Moy. » (2 ou 3 colonnes de score selon
            // la discipline — ex. 3 en Classique/Poulies/Campagne/3D/Nature/Para 18m,
            // 2 seulement en Para extérieur), toujours triées décroissant, juste après
            // le club, puis « Préinscrire » (non numérique) en dernier. On prend donc
            // TOUTES les cellules numériques après le club dans l'ordre : la dernière
            // est Moy., les précédentes sont S1..Sn (2 ou 3 selon discipline) — robuste
            // au nombre exact de colonnes de score sans le supposer fixe.
            $scores = [];
            for ($k = $ci + 2; $k < count($c); $k++) {
                if (ctype_digit($c[$k])) $scores[] = intval($c[$k]);
            }
            $moy = $scores ? array_pop($scores) : 0;   // dernière valeur numérique
            $s1  = $scores[0] ?? 0;
            $s2  = $scores[1] ?? 0;
            $s3  = $scores[2] ?? 0;   // absent (0) pour les disciplines à 2 scores (ex. Para extérieur)

            $archers[] = [
                'rang'      => $rang,
                'licence'   => mb_strtoupper($c[$li], 'UTF-8'),
                'nom'       => $c[$li + 1],
                'categorie' => ($ci - $li === 3) ? $c[$li + 2] : '',
                'clubcode'  => $c[$ci],
                'clubnom'   => $c[$ci + 1],
                'moyenne'   => $moy,
                's1'        => $s1,
                's2'        => $s2,
                's3'        => $s3,
                'quota'     => $quota,
                'preinscrit' => $preinscrit,
            ];
        }
    }
    if (!$archers) return ['ok' => false, 'err' => 'aucun archer lu dans ce classement', 'titre' => $titre, 'archers' => []];
    return ['ok' => true, 'err' => '', 'titre' => $titre, 'archers' => $archers];
}

/**
 * Télécharge un classement et le range en base (remplacement complet).
 * $meta vient de rep_ffta_liste(). Retourne ['ok','err','nb'].
 */
function rep_ffta_enregistrer($annee, $discipline, $meta)
{
    $cl = rep_ffta_classement($meta['ffta']);
    if (!$cl['ok']) return ['ok' => false, 'err' => $cl['err'], 'nb' => 0];

    $ffta = intval($meta['ffta']);
    $now  = date('Y-m-d H:i:s');
    $champs = "CcAnnee=" . intval($annee) . ",
        CcDiscipline=" . StrSafe_DB($discipline) . ",
        CcArme=" . StrSafe_DB($meta['arme']) . ",
        CcSexe=" . StrSafe_DB($meta['sexe']) . ",
        CcCategorie=" . StrSafe_DB($meta['categorie']) . ",
        CcNiveau=" . StrSafe_DB($meta['niveau'] ?? '') . ",
        CcDistance=" . StrSafe_DB($meta['distance'] ?? '') . ",
        CcLibelle=" . StrSafe_DB($meta['libelle']) . ",
        CcNbArchers=" . count($cl['archers']) . ",
        CcMaj=" . StrSafe_DB($now);

    $rs  = safe_r_sql("SELECT CcId FROM REP_Classements WHERE CcFfta=$ffta");
    $row = $rs ? safe_fetch($rs) : null;
    if ($row) {
        $ccId = intval($row->CcId);
        safe_w_sql("UPDATE REP_Classements SET $champs WHERE CcId=$ccId");
        safe_w_sql("DELETE FROM REP_Rangs WHERE CrClassement=$ccId");
    } else {
        safe_w_sql("INSERT INTO REP_Classements SET CcFfta=$ffta, $champs");
        $rs2 = safe_r_sql("SELECT CcId FROM REP_Classements WHERE CcFfta=$ffta");
        $r2  = $rs2 ? safe_fetch($rs2) : null;
        if (!$r2) return ['ok' => false, 'err' => 'insertion du classement impossible', 'nb' => 0];
        $ccId = intval($r2->CcId);
    }

    $vals = [];
    $vus  = [];
    foreach ($cl['archers'] as $a) {
        $rang = intval($a['rang']);
        if (isset($vus[$rang])) continue;   // la clé primaire est (classement, rang)
        $vus[$rang] = true;
        $vals[] = "($ccId, $rang, " . StrSafe_DB($a['licence']) . ", " . StrSafe_DB($a['nom']) . ", "
                . StrSafe_DB($a['categorie']) . ", " . StrSafe_DB($a['clubcode']) . ", "
                . StrSafe_DB($a['clubnom']) . ", " . intval($a['moyenne']) . ", " . intval($a['s1'] ?? 0)
                . ", " . intval($a['s2'] ?? 0) . ", " . intval($a['s3'] ?? 0) . ", " . intval($a['quota'])
                . ", " . intval($a['preinscrit'] ?? 0) . ")";
        if (count($vals) >= 200) {
            safe_w_sql("INSERT INTO REP_Rangs (CrClassement, CrRang, CrLicence, CrNom, CrCategorie,
                        CrClubCode, CrClubNom, CrMoyenne, CrS1, CrS2, CrS3, CrQuota, CrPreinscrit) VALUES "
                        . implode(',', $vals));
            $vals = [];
        }
    }
    if ($vals) {
        safe_w_sql("INSERT INTO REP_Rangs (CrClassement, CrRang, CrLicence, CrNom, CrCategorie,
                    CrClubCode, CrClubNom, CrMoyenne, CrS1, CrS2, CrS3, CrQuota, CrPreinscrit) VALUES "
                    . implode(',', $vals));
    }

    return ['ok' => true, 'err' => '', 'nb' => count($vus)];
}

/** Ce qui est déjà en base pour une saison et une discipline, indexé par identifiant FFTA. */
function rep_classements_locaux($annee, $discipline)
{
    $out = [];
    $rs = safe_r_sql("SELECT * FROM REP_Classements
        WHERE CcAnnee=" . intval($annee) . " AND CcDiscipline=" . StrSafe_DB($discipline));
    while ($rs && $r = safe_fetch($rs)) {
        $out[intval($r->CcFfta)] = [
            'ccid'      => intval($r->CcId),
            'arme'      => $r->CcArme,
            'sexe'      => $r->CcSexe,
            'categorie' => $r->CcCategorie,
            'niveau'    => $r->CcNiveau,
            'distance'  => $r->CcDistance,
            'libelle'   => $r->CcLibelle,
            'nb'        => intval($r->CcNbArchers),
            'maj'       => $r->CcMaj,
        ];
    }
    return $out;
}
