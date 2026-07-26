<?php
/**
 * lib/arretes.php — import des arrêtés FFTA (fichiers Exalto : sélections
 * individuelles et dépôts d'équipes) vers un état de travail consolidé, puis
 * vers des classements dérivés utilisables comme n'importe quelle source du
 * moteur de placement.
 *
 * Aucune écriture dans Entries/Qualifications ici : voir lib/arretes_ecriture.php,
 * seul point qui touche ianseo. Ce fichier ne fait que lire les fichiers déposés,
 * les normaliser dans REP_ImpEtat (un JSON par compétition) et construire les
 * classements REP_ArrClassements/REP_ArrRangs.
 *
 * Format des fichiers Exalto — deux familles, reconnues par leur en-tête (jamais
 * par position, les colonnes varient d'un arrêté à l'autre) :
 *  - individuel  : une ligne = un archer (« sélectionnés »). En-tête avec Quota +
 *    Licence + Archer (nom complet fusionné) + Cat (+ Cat_sur, +HORS_F).
 *  - équipe      : une ligne = une équipe de 2 (double mixte) ou 4 (clubs), avec
 *    LICENCE1..N/NOM.../PRENOM.../CAT.../QUALIF... répétés, et un bloc capitaine
 *    (LIC_CAPITAINE...) qui devient une ligne « coach » séparée.
 * Les deux ont parfois des lignes décoratives avant l'en-tête réel (titre, date).
 */

if (!function_exists('rep_coll')) require_once __DIR__ . '/schema.php';

// ── Lecture brute des fichiers ───────────────────────────────────────────────

/** Indice de colonne (0-based) à partir de la référence Excel « A », « AB »… */
function rep_imp_col_index($lettres)
{
    $col = 0;
    foreach (str_split($lettres) as $ch) $col = $col * 26 + (ord($ch) - 64);
    return $col - 1;
}

/**
 * Lit un .xlsx sans dépendance externe (ZipArchive + regex sur le XML interne,
 * dans le même esprit que le parsing HTML de lib/ffta.php). Ne lit que la
 * première feuille : les exports Exalto n'en ont qu'une.
 * Retourne un tableau rectangulaire de lignes (chacune indexée 0..maxCol).
 */
function rep_imp_lire_xlsx($chemin)
{
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($chemin) !== true) return [];

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('#<si[^>]*>(.*?)</si>#s', $ss, $m)) {
        foreach ($m[1] as $si) {
            $txt = '';
            if (preg_match_all('#<t[^>]*>(.*?)</t>#s', $si, $mt)) {
                foreach ($mt[1] as $t) $txt .= html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $shared[] = $txt;
        }
    }

    $target = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($target) === false) {
        $noms = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) $noms[] = $n;
        }
        sort($noms);
        if ($noms) $target = $noms[0];
    }
    $sheet = $zip->getFromName($target);
    $zip->close();
    if ($sheet === false) return [];

    $rows = [];
    $maxCol = 0;
    if (preg_match_all('#<row[^>]*\br="(\d+)"[^>]*>(.*?)</row>#s', $sheet, $mr, PREG_SET_ORDER)) {
        foreach ($mr as $rowMatch) {
            $rIdx = intval($rowMatch[1]) - 1;
            $cells = [];
            // Alternative explicite entre cellule vide auto-fermante (<c .../>) et
            // cellule à paire ouvrante/fermante : une regex à paire unique traite le
            // « /> » d'une cellule vide comme un « > » ordinaire, avale la cellule
            // suivante comme si c'était son propre contenu et décale toute la ligne
            // d'une colonne — bug réel trouvé en testant sur un fichier Exalto où
            // « HORS_F » vide s'auto-ferme (<c r="D2" s="3"/>).
            if (preg_match_all('#<c\b([^>]*)/>|<c\b([^>]*)>(.*?)</c>#s', $rowMatch[2], $mc, PREG_SET_ORDER)) {
                foreach ($mc as $c) {
                    $vide = ($c[1] !== '');
                    $attrs = $vide ? $c[1] : $c[2];
                    $inner = $vide ? '' : $c[3];
                    if (!preg_match('/\br="([A-Z]+)\d+"/', $attrs, $mref)) continue;
                    $col = rep_imp_col_index($mref[1]);
                    $maxCol = max($maxCol, $col);
                    $type = preg_match('/\bt="([a-zA-Z]+)"/', $attrs, $mt2) ? $mt2[1] : 'n';
                    $val = '';
                    if ($type === 's') {
                        if (preg_match('#<v>(\d+)</v>#', $inner, $mv)) $val = $shared[intval($mv[1])] ?? '';
                    } elseif ($type === 'inlineStr' || $type === 'str') {
                        if (preg_match('#<t[^>]*>(.*?)</t>#s', $inner, $mv)) {
                            $val = html_entity_decode($mv[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    } elseif (preg_match('#<v>(.*?)</v>#s', $inner, $mv)) {
                        $val = $mv[1];
                    }
                    $cells[$col] = trim($val);
                }
            }
            $rows[$rIdx] = $cells;
        }
    }

    $out = [];
    $maxRow = $rows ? max(array_keys($rows)) : -1;
    for ($i = 0; $i <= $maxRow; $i++) {
        $ligne = [];
        $src = $rows[$i] ?? [];
        for ($c = 0; $c <= $maxCol; $c++) $ligne[$c] = $src[$c] ?? '';
        $out[] = $ligne;
    }
    return $out;
}

/** Lit un .csv (séparateur ; ou , détecté sur la première ligne). */
function rep_imp_lire_csv($chemin)
{
    $rows = [];
    $fh = @fopen($chemin, 'r');
    if (!$fh) return [];
    $premiere = fgets($fh);
    $sep = (substr_count((string) $premiere, ';') >= substr_count((string) $premiere, ',')) ? ';' : ',';
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $sep)) !== false) $rows[] = array_map('trim', $r);
    fclose($fh);
    return $rows;
}

function rep_imp_lire_fichier($chemin, $nomOriginal)
{
    $ext = strtolower(pathinfo((string) $nomOriginal, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') return rep_imp_lire_csv($chemin);
    return rep_imp_lire_xlsx($chemin);
}

// ── Détection du format ───────────────────────────────────────────────────────

/** En-tête normalisé : sans accent, en MAJUSCULES, séparateurs réduits à « _ ». */
function rep_imp_norm_entete($s)
{
    $s = trim((string) $s);
    $map = ['À'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I',
            'Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ÿ'=>'Y',
            'à'=>'A','â'=>'A','ä'=>'A','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E','î'=>'I','ï'=>'I',
            'ô'=>'O','ö'=>'O','ù'=>'U','û'=>'U','ü'=>'U','ç'=>'C','ÿ'=>'Y'];
    $s = strtr($s, $map);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/[^A-Z0-9]+/', '_', $s);
    return trim($s, '_');
}

/**
 * Détecte le type de fichier (individuel/équipe) et construit le dictionnaire
 * colonne → champ canonique, en cherchant la ligne d'en-tête dans les 12
 * premières lignes (les dépôts d'équipe ont des lignes de titre avant).
 * Retourne ['type','sousType','headerIdx','dico'=>[idx=>champ],'inconnues'=>[idx=>libellé],'nbArchers','err'].
 */
function rep_imp_detecter($rows)
{
    $maxScan = min(count($rows), 12);
    for ($i = 0; $i < $maxScan; $i++) {
        $norm = array_map('rep_imp_norm_entete', $rows[$i]);
        $hasQuota = in_array('QUOTA', $norm, true);
        if (!$hasQuota) continue;
        $hasLicN = false;
        foreach ($norm as $h) if (preg_match('/^LICENCE\d+$/', $h)) { $hasLicN = true; break; }
        if ($hasLicN) return rep_imp_detecter_equipe($rows, $i, $norm);
        if (in_array('LICENCE', $norm, true) && in_array('ARCHER', $norm, true)) {
            return rep_imp_detecter_individuel($rows, $i, $norm);
        }
    }
    return ['type' => '', 'sousType' => '', 'headerIdx' => -1, 'dico' => [], 'inconnues' => [], 'nbArchers' => 0,
            'err' => "Format non reconnu : aucun en-tête « Quota / Licence » trouvé dans les 12 premières lignes."];
}

function rep_imp_detecter_individuel($rows, $headerIdx, $norm)
{
    $connu = [
        'QUOTA' => 'quota', 'LICENCE' => 'licence', 'ARCHER' => 'archer_complet', 'HORS_F' => 'etranger',
        'CAT' => 'cat', 'CAT_SUR' => 'catsur', 'SEXE' => 'sexe', 'ARME' => 'division',
        'AGREE' => 'clubcode', 'AGRE' => 'clubcode', 'CLUB' => 'clubnom', 'PARA' => 'para',
        'MAIL_PERSONNEL' => '', 'MAIL_PERSONNE' => '', 'MAIL' => '',
    ];
    $dico = []; $inconnues = [];
    foreach ($norm as $i => $h) {
        if ($h === '') continue;
        if (array_key_exists($h, $connu)) { if ($connu[$h] !== '') $dico[$i] = $connu[$h]; continue; }
        $inconnues[$i] = $rows[$headerIdx][$i];
    }
    return ['type' => 'individuel', 'sousType' => '', 'headerIdx' => $headerIdx,
            'dico' => $dico, 'inconnues' => $inconnues, 'nbArchers' => 0, 'err' => ''];
}

function rep_imp_detecter_equipe($rows, $headerIdx, $norm)
{
    $max = 0;
    foreach ($norm as $h) if (preg_match('/^LICENCE(\d+)$/', $h, $m)) $max = max($max, intval($m[1]));

    $top = [
        'QUOTA' => 'team_quota', 'SEXE' => 'team_sexe', 'ARME' => 'division', 'AGREMENT' => 'clubcode',
        'CLUB_ABREGE' => 'clubnom', 'MAIL_CORRES' => '',
        'LIC_CAPITAINE' => 'cap_licence', 'NOM_CAPITAINE' => 'cap_nom', 'PRENOM_CAPITAINE' => 'cap_prenom',
        'AGREMENT_CAPITAINE' => 'cap_clubcode', 'CLUB_ABREG_CAPITAINE' => 'cap_clubnom',
    ];
    $dico = []; $inconnues = [];
    foreach ($norm as $i => $h) {
        if ($h === '') continue;
        if (array_key_exists($h, $top)) { if ($top[$h] !== '') $dico[$i] = $top[$h]; continue; }
        if (preg_match('/^(LICENCE|NOM|PRENOM|SEXE|CAT|ARME|QUALIF)(\d+)$/', $h, $m)) {
            $dico[$i] = 'a' . $m[2] . '_' . strtolower($m[1]);
            continue;
        }
        $inconnues[$i] = $rows[$headerIdx][$i];
    }
    $sousType = $max >= 3 ? 'EQ' : 'DM';   // EQ = équipes de clubs (4), DM = double mixte (2)
    return ['type' => 'equipe', 'sousType' => $sousType, 'headerIdx' => $headerIdx,
            'dico' => $dico, 'inconnues' => $inconnues, 'nbArchers' => $max, 'err' => ''];
}

// ── Analyse d'une ligne ───────────────────────────────────────────────────────

/** « H »/« Homme » → H, « F »/« Femme » → F, sinon vide (jamais deviné). */
function rep_imp_sexe_code($txt)
{
    $t = mb_strtoupper(trim((string) $txt), 'UTF-8');
    if ($t === 'H' || strpos($t, 'HOM') === 0) return 'H';
    if ($t === 'F' || strpos($t, 'FEM') === 0) return 'F';
    return '';
}

/**
 * Sexe D'ÉQUIPE (format EQ, colonne SEXE au niveau équipe) — DISTINCT de
 * rep_imp_sexe_code() : ici « X »/« Mixte » est une valeur À PART ENTIÈRE (une
 * équipe mixte hommes/femmes est une épreuve ianseo différente d'une équipe
 * mono-sexe, ex. « U15 Mixte » vs « U21 Femme »/« U21 Homme »), jamais réduite
 * à '' comme le ferait rep_imp_sexe_code(). Sert UNIQUEMENT à regrouper les
 * classements d'équipe par épreuve réelle (rep_imp_classement_equipe_construire()) —
 * ne jamais l'utiliser pour le sexe d'un ARCHER (un archer n'est jamais « X »).
 */
function rep_imp_team_sexe_code($txt)
{
    $t = mb_strtoupper(trim((string) $txt), 'UTF-8');
    if ($t === 'H' || strpos($t, 'HOM') === 0) return 'H';
    if ($t === 'F' || strpos($t, 'FEM') === 0) return 'F';
    if ($t === 'X' || strpos($t, 'MIX') === 0) return 'X';
    return '';
}

/**
 * Sépare un nom complet fusionné (« ARCHER » des fichiers individuels) en
 * nom / prénom. Heuristique : le dernier mot est le prénom, le reste le nom —
 * fonctionne pour l'immense majorité des cas mais jamais garanti (prénoms
 * composés sans tiret) : la ligne est marquée « nom_devine » pour que
 * l'organisateur la relise avant validation.
 */
function rep_imp_decouper_nom($complet)
{
    $complet = trim(preg_replace('/\s+/u', ' ', (string) $complet));
    if ($complet === '') return ['', ''];
    $mots = explode(' ', $complet);
    $prenom = array_pop($mots);
    $nom = implode(' ', $mots);
    if ($nom === '') { $nom = $prenom; $prenom = ''; }
    return [$nom, $prenom];
}

function rep_imp_valeur($row, $detect, $champ)
{
    foreach ($detect['dico'] as $idx => $c) if ($c === $champ) return trim($row[$idx] ?? '');
    return '';
}

/**
 * Convention de suffixe de classe — ne se lit NULLE PART dans le contenu du
 * fichier, seulement dans son nom (précisé par l'utilisateur) : un TAE
 * International utilise F/H, un TAE National M/W. Jamais deviné en silence :
 * rep_imp_convention_depuis_nom() ne fait que PRÉ-REMPLIR le sélecteur de
 * l'assistant, qui reste modifiable avant l'import de chaque fichier.
 */
function rep_imp_convention_depuis_nom($nomFichier)
{
    $n = mb_strtoupper((string) $nomFichier, 'UTF-8');
    // Vérifier « INTER » avant « NATIONAL » : "INTERNATIONAL" contient
    // littéralement la sous-chaîne "NATIONAL" (positions 6-13).
    if (strpos($n, 'INTER') !== false)    return 'FH';
    if (strpos($n, 'NATIONAL') !== false) return 'MW';
    return 'FH';   // repli neutre, à confirmer par l'utilisateur dans tous les cas
}

/** Lettre de suffixe de classe selon la convention retenue pour CE fichier. */
function rep_imp_suffixe_classe($sexe, $convention)
{
    if ($convention === 'MW') return ($sexe === 'F') ? 'W' : 'M';
    return ($sexe === 'F') ? 'F' : 'H';
}

/** Fichier individuel → lignes 'archer', une par ligne source. */
function rep_imp_parser_individuel($rows, $detect, $convention = 'FH')
{
    $lignes = [];
    for ($i = $detect['headerIdx'] + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $licence = mb_strtoupper(rep_imp_valeur($row, $detect, 'licence'), 'UTF-8');
        if ($licence === '') continue;   // ligne vide

        $complet = rep_imp_valeur($row, $detect, 'archer_complet');
        list($nom, $prenom) = rep_imp_decouper_nom($complet);
        $sexe = rep_imp_sexe_code(rep_imp_valeur($row, $detect, 'sexe'));
        $cat = mb_strtoupper(rep_imp_valeur($row, $detect, 'cat'), 'UTF-8');
        $catsur = mb_strtoupper(rep_imp_valeur($row, $detect, 'catsur'), 'UTF-8');
        if ($catsur === '') $catsur = $cat;

        // La classe de l'archer se construit sur Cat_sur (catégorie de
        // surclassement) quand elle existe, PAS sur Cat brut : précisé par
        // l'utilisateur ("s'il existe une Cat_sur, c'est elle qui prend la main
        // sur Cat"). $catsur est déjà rabattu sur $cat s'il est vide (ci-dessus).
        $lignes[] = [
            'role' => 'archer', 'licence' => $licence, 'nom' => $nom, 'prenom' => $prenom,
            'nom_devine' => ($complet !== ''),
            'sexe' => $sexe, 'division' => mb_strtoupper(rep_imp_valeur($row, $detect, 'division'), 'UTF-8'),
            'class' => ($catsur !== '' && $sexe !== '') ? ($catsur . rep_imp_suffixe_classe($sexe, $convention)) : '',
            'clubcode' => rep_imp_valeur($row, $detect, 'clubcode'),
            'clubnom' => rep_imp_valeur($row, $detect, 'clubnom'),
            'cat' => $cat, 'catsur' => $catsur,
            'quota' => intval(rep_imp_valeur($row, $detect, 'quota')),
            'indiv' => true, 'equipe' => false, 'doublemixte' => false,
            'etranger' => rep_imp_valeur($row, $detect, 'etranger'),
            'para' => rep_imp_valeur($row, $detect, 'para'),
        ];
    }
    return $lignes;
}

/** Fichier équipe (EQ ou DM) → lignes 'archer' (une par membre) + 'coach'. */
function rep_imp_parser_equipe($rows, $detect, $convention = 'FH')
{
    $lignes = [];
    $sousType = $detect['sousType'];
    for ($i = $detect['headerIdx'] + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $teamDivision = mb_strtoupper(rep_imp_valeur($row, $detect, 'division'), 'UTF-8');
        $teamClubCode = rep_imp_valeur($row, $detect, 'clubcode');
        $teamClubNom  = rep_imp_valeur($row, $detect, 'clubnom');
        $teamQuota    = intval(rep_imp_valeur($row, $detect, 'team_quota'));
        // Format EQ (équipes de clubs) : une seule colonne SEXE, par équipe (pas par
        // archer) — H/F pour une équipe mono-sexe, X pour une équipe mixte (mélange
        // hommes/femmes, différent du double mixte). rep_imp_sexe_code('X') renvoie
        // '' : sur une équipe X, le sexe de chaque archer reste inconnu à ce stade
        // (résolu par une autre source, ou par défaut en consolidation).
        $teamSexe = rep_imp_sexe_code(rep_imp_valeur($row, $detect, 'team_sexe'));
        // Sexe D'ÉQUIPE brut (H/F/X), conservé pour regrouper les classements
        // d'équipe par épreuve réelle — voir rep_imp_team_sexe_code(). Distinct
        // de $teamSexe ci-dessus (qui réduit X à '' pour l'hérédité de sexe
        // ARCHER, un usage différent).
        $equipeSexe = rep_imp_team_sexe_code(rep_imp_valeur($row, $detect, 'team_sexe'));

        $auMoinsUnArcher = false;
        for ($a = 1; $a <= $detect['nbArchers']; $a++) {
            $lic = mb_strtoupper(rep_imp_valeur($row, $detect, "a{$a}_licence"), 'UTF-8');
            if ($lic === '') continue;
            $auMoinsUnArcher = true;

            $sexe = rep_imp_sexe_code(rep_imp_valeur($row, $detect, "a{$a}_sexe"));
            if ($sexe === '' && $teamSexe !== '') $sexe = $teamSexe;   // DM : par archer ; EQ H/F : hérité de l'équipe
            $cat  = mb_strtoupper(rep_imp_valeur($row, $detect, "a{$a}_cat"), 'UTF-8');
            $div  = mb_strtoupper(rep_imp_valeur($row, $detect, "a{$a}_arme"), 'UTF-8');
            if ($div === '') $div = $teamDivision;

            $lignes[] = [
                'role' => 'archer', 'licence' => $lic,
                'nom' => rep_imp_valeur($row, $detect, "a{$a}_nom"),
                'prenom' => rep_imp_valeur($row, $detect, "a{$a}_prenom"),
                'nom_devine' => false,
                'sexe' => $sexe, 'division' => $div,
                'class' => ($cat !== '' && $sexe !== '') ? ($cat . rep_imp_suffixe_classe($sexe, $convention)) : '',
                'clubcode' => $teamClubCode, 'clubnom' => $teamClubNom,
                'cat' => $cat, 'catsur' => '',
                'quota' => $teamQuota, 'equipe_sexe' => $equipeSexe,
                // Indice de LIGNE source (pas le quota, qui repart à 1 à chaque
                // nouvelle épreuve du même fichier — un même club peut alors
                // partager quota+clubcode entre deux équipes différentes, ex.
                // « RIOM » quota=1 en mixte ET quota=1 en Femmes) : seul moyen
                // fiable de regrouper les 2-4 archers d'UNE MÊME équipe sans
                // fusionner à tort deux équipes distinctes du même club — voir
                // rep_imp_classement_equipe_construire().
                'equipe_ligne' => $i,
                'indiv' => false,
                // Un archer nommé (licence non vide) dans une ligne d'équipe/DM EST
                // sélectionné pour cette épreuve — QUALIF{i} n'est PAS un drapeau de
                // sélection : sur les fichiers réels (Jeune 2026), il vaut
                // systématiquement vide pour un archer SEULEMENT équipe/DM et rempli
                // (« CH.FR.JEUNE ») pour un archer qui a AUSSI une place individuelle —
                // c'est un renvoi vers la qualification individuelle, pas un accord
                // de participation équipe. S'y fier revenait à exclure à tort les
                // archers équipe/DM-seuls (bug réel signalé par l'utilisateur).
                'equipe' => ($sousType === 'EQ'),
                'doublemixte' => ($sousType === 'DM'),
                'etranger' => '',
                'para' => '',
            ];
        }
        if (!$auMoinsUnArcher) continue;   // ligne décorative (titre, date…)

        // Le capitaine d'une équipe est le coach : licencié à part entière, mais
        // sans place de tir — traité en ligne distincte même si la même licence
        // apparaît par ailleurs comme archer (règle explicite du club).
        $capLic = mb_strtoupper(rep_imp_valeur($row, $detect, 'cap_licence'), 'UTF-8');
        if ($capLic !== '') {
            $lignes[] = [
                'role' => 'coach', 'licence' => $capLic,
                'nom' => rep_imp_valeur($row, $detect, 'cap_nom'),
                'prenom' => rep_imp_valeur($row, $detect, 'cap_prenom'),
                'nom_devine' => false,
                'sexe' => '', 'division' => 'OF', 'class' => 'COA',
                'clubcode' => rep_imp_valeur($row, $detect, 'cap_clubcode') ?: $teamClubCode,
                'clubnom' => rep_imp_valeur($row, $detect, 'cap_clubnom') ?: $teamClubNom,
                'cat' => '', 'catsur' => '', 'quota' => 0,
                'indiv' => false, 'equipe' => false, 'doublemixte' => false, 'etranger' => '', 'para' => '',
            ];
        }
    }
    return $lignes;
}

function rep_imp_parser($rows, $detect, $convention = 'FH')
{
    if ($detect['type'] === 'equipe')     return rep_imp_parser_equipe($rows, $detect, $convention);
    if ($detect['type'] === 'individuel') return rep_imp_parser_individuel($rows, $detect, $convention);
    return [];
}

// ── État de travail (REP_ImpEtat) ────────────────────────────────────────────

function rep_imp_etat_lire($tourId)
{
    $rs = safe_r_sql("SELECT IeDonnees FROM REP_ImpEtat WHERE IeTournament=" . intval($tourId));
    $r  = $rs ? safe_fetch($rs) : null;
    $j  = $r ? json_decode($r->IeDonnees, true) : null;
    if (!is_array($j)) $j = [];
    if (!isset($j['fichiers']) || !is_array($j['fichiers']))       $j['fichiers'] = [];
    if (!isset($j['lignes']) || !is_array($j['lignes']))           $j['lignes'] = [];
    if (!isset($j['resolutions']) || !is_array($j['resolutions'])) $j['resolutions'] = [];
    return $j;
}

function rep_imp_etat_ecrire($tourId, $etat)
{
    $tourId = intval($tourId);
    $json = json_encode($etat, JSON_UNESCAPED_UNICODE);
    $now  = StrSafe_DB(date('Y-m-d H:i:s'));
    safe_w_sql("INSERT INTO REP_ImpEtat (IeTournament, IeDonnees, IeUpdated)
        VALUES ($tourId, " . StrSafe_DB($json) . ", $now)
        ON DUPLICATE KEY UPDATE IeDonnees=" . StrSafe_DB($json) . ", IeUpdated=$now");
}

function rep_imp_reinitialiser($tourId)
{
    safe_w_sql("DELETE FROM REP_ImpEtat WHERE IeTournament=" . intval($tourId));
}

/** Ajoute un fichier déjà parsé (lignes normalisées) à l'état. Retourne l'id de fichier. */
function rep_imp_ajouter_fichier($tourId, $type, $sousType, $nomFichier, $lignes, $convention = 'FH')
{
    $etat = rep_imp_etat_lire($tourId);
    $id = 1;
    foreach ($etat['fichiers'] as $f) $id = max($id, intval($f['id']) + 1);
    $etat['fichiers'][] = [
        'id' => $id, 'type' => $type, 'sous' => $sousType, 'nom' => $nomFichier,
        'nb' => count($lignes), 'quand' => date('Y-m-d H:i:s'), 'convention' => $convention,
    ];
    foreach ($lignes as $l) { $l['fichier'] = $id; $etat['lignes'][] = $l; }
    rep_imp_etat_ecrire($tourId, $etat);
    return $id;
}

function rep_imp_supprimer_fichier($tourId, $ficheId)
{
    $etat = rep_imp_etat_lire($tourId);
    $ficheId = intval($ficheId);
    $etat['fichiers'] = array_values(array_filter($etat['fichiers'], function ($f) use ($ficheId) {
        return intval($f['id']) !== $ficheId;
    }));
    $etat['lignes'] = array_values(array_filter($etat['lignes'], function ($l) use ($ficheId) {
        return intval($l['fichier']) !== $ficheId;
    }));
    rep_imp_etat_ecrire($tourId, $etat);
}

function rep_imp_resoudre($tourId, $licence, $role, $champ, $valeur)
{
    $etat = rep_imp_etat_lire($tourId);
    $etat['resolutions']["$licence|$role|$champ"] = $valeur;
    rep_imp_etat_ecrire($tourId, $etat);
}

// ── Consolidation multi-fichiers ─────────────────────────────────────────────

/**
 * Fusionne toutes les lignes par (licence, rôle) — archer et coach d'une même
 * licence restent DEUX entrées distinctes, jamais fusionnées. Un champ qui
 * diverge entre fichiers reste en conflit tant qu'une résolution n'a pas été
 * enregistrée (individuelle ou en masse) ; les drapeaux (indiv/équipe/double
 * mixte) se cumulent en OU logique — un seul fichier qui l'accorde suffit.
 * Retourne ['consolide'=>[ [...] ], 'conflits'=>[cle=>[champ=>[valeur=>[sources]]]]].
 */
function rep_imp_consolider($tourId)
{
    $etat = rep_imp_etat_lire($tourId);
    $fichiers = [];
    foreach ($etat['fichiers'] as $f) $fichiers[intval($f['id'])] = $f;

    $champsSuivis = ['nom', 'prenom', 'sexe', 'division', 'class', 'clubcode', 'clubnom'];
    // Jamais bloquants (voir plus bas) : sans incidence sur l'attribution, demandé
    // par l'utilisateur pour ne plus avoir à valider une simple divergence de
    // graphie/orthographe entre deux fichiers.
    $champsSansConflit = ['nom', 'prenom', 'sexe'];
    $groupes = [];
    foreach ($etat['lignes'] as $l) {
        $cle = $l['licence'] . '|' . $l['role'];
        $l['fichier_label'] = isset($fichiers[intval($l['fichier'])])
            ? ($fichiers[intval($l['fichier'])]['sous'] ?: $fichiers[intval($l['fichier'])]['type'])
            : ('#' . $l['fichier']);
        $groupes[$cle][] = $l;
    }

    $consolide = [];
    $conflits = [];
    foreach ($groupes as $cle => $lignes) {
        list($licence, $role) = array_pad(explode('|', $cle, 2), 2, '');
        $out = ['licence' => $licence, 'role' => $role];
        $champConflits = [];

        foreach ($champsSuivis as $c) {
            $valeurs = [];
            foreach ($lignes as $l) {
                $v = trim((string) ($l[$c] ?? ''));
                if ($v === '') continue;
                $valeurs[$v][] = $l['fichier_label'];
            }
            $resKey = $licence . '|' . $role . '|' . $c;
            if (isset($etat['resolutions'][$resKey])) {
                $out[$c] = $etat['resolutions'][$resKey];
            } elseif (in_array($c, $champsSansConflit, true)) {
                // Nom/prénom/sexe : jamais bloquant — sans incidence sur l'attribution
                // (demandé par l'utilisateur), la première valeur rencontrée suffit,
                // pas la peine de faire valider un choix entre plusieurs graphies.
                $out[$c] = $valeurs ? array_key_first($valeurs) : '';
            } elseif (count($valeurs) <= 1) {
                $out[$c] = $valeurs ? array_key_first($valeurs) : '';
            } else {
                uasort($valeurs, function ($a, $b) { return count($b) - count($a); });   // le plus fréquent d'abord
                $out[$c] = array_key_first($valeurs);
                $champConflits[$c] = $valeurs;
            }
        }

        $indiv = false; $equipe = false; $dm = false; $etranger = ''; $devine = true; $quota = 0;
        $para = ''; $catFallback = '';
        foreach ($lignes as $l) {
            if (!empty($l['indiv']))       $indiv = true;
            if (!empty($l['equipe']))      $equipe = true;
            if (!empty($l['doublemixte'])) $dm = true;
            if (!empty($l['etranger']))    $etranger = $l['etranger'];
            if (empty($l['nom_devine']))   $devine = false;
            if (!empty($l['quota']) && !$quota) $quota = intval($l['quota']);
            if ($para === '' && !empty($l['para'])) $para = $l['para'];
            if ($catFallback === '' && !empty($l['cat'])) $catFallback = $l['cat'];
        }
        // Règle validée : un archer étranger (HORS_F) ne participe jamais à
        // l'épreuve individuelle, même si une source l'y indiquait par erreur.
        if ($role === 'archer' && $etranger !== '') $indiv = false;

        // Sexe inconnu partout (ex. seule source = équipe de clubs mixte "X", sans
        // homologue individuel) : on ne bloque pas l'inscription pour autant — on
        // suppose « H » par défaut, ianseo réassignera le bon sexe depuis sa base
        // fédérale (LookUpEntries) au moment de l'import réel.
        if ($role === 'archer' && $out['class'] === '' && $catFallback !== '') {
            if ($out['sexe'] === '') $out['sexe'] = 'H';
            $out['class'] = $catFallback . $out['sexe'];
        }

        $out['indiv'] = $indiv;
        $out['equipe'] = $equipe;
        $out['doublemixte'] = $dm;
        $out['etranger'] = $etranger;
        $out['para'] = $para;
        $out['quota'] = $quota;
        $out['nom_devine'] = $devine;
        $out['sources'] = array_values(array_unique(array_column($lignes, 'fichier_label')));
        $out['conflit'] = !empty($champConflits);
        $out['incomplet'] = ($out['licence'] === '' || $out['division'] === '' || $out['class'] === '');
        $out['candidats'] = $champConflits;   // champ => [valeur => [sources]], pour l'UI

        if ($champConflits) $conflits[$cle] = $champConflits;
        $consolide[$cle] = $out;
    }

    usort($consolide, function ($a, $b) {
        if ($a['role'] !== $b['role']) return $a['role'] === 'archer' ? -1 : 1;
        return strcasecmp($a['nom'] . $a['prenom'], $b['nom'] . $b['prenom']);
    });
    return ['consolide' => $consolide, 'conflits' => $conflits];
}

/** Valide en masse tous les conflits restants sur leur valeur majoritaire actuelle. */
function rep_imp_resoudre_tout($tourId)
{
    $r = rep_imp_consolider($tourId);
    $n = 0;
    foreach ($r['consolide'] as $c) {
        if (empty($c['conflit'])) continue;
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'nom', $c['nom']);
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'prenom', $c['prenom']);
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'sexe', $c['sexe']);
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'division', $c['division']);
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'class', $c['class']);
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'clubcode', $c['clubcode']);
        rep_imp_resoudre($tourId, $c['licence'], $c['role'], 'clubnom', $c['clubnom']);
        $n++;
    }
    return $n;
}

// ── Classements dérivés de l'arrêté ──────────────────────────────────────────

/**
 * Étiquette composite d'un ensemble de catégories brutes (sans suffixe de sexe),
 * triées dans l'ordre d'âge (rep_ordre_categories()) : ["U18","U21"] → "U18-U21",
 * ["S1"] → "S1". Sert à la fois à nommer un classement qui regroupe plusieurs
 * catégories (faute d'effectif — ex. poulies jeunes) et à reconstruire la même
 * étiquette côté épreuve ianseo pour les associer automatiquement, sans dépendre
 * d'un texte de libellé.
 */
function rep_imp_categorie_composite($cats)
{
    $ordre = array_flip(function_exists('rep_ordre_categories') ? rep_ordre_categories() : []);
    $uniques = array_values(array_unique(array_filter(array_map('trim', (array) $cats), function ($v) {
        return $v !== '';
    })));
    usort($uniques, function ($a, $b) use ($ordre) {
        $pa = $ordre[$a] ?? 999;
        $pb = $ordre[$b] ?? 999;
        if ($pa !== $pb) return $pa - $pb;
        return strcasecmp($a, $b);
    });
    return implode('-', $uniques);
}

/**
 * (Re)construit les classements individuels dérivés des fichiers « individuel ».
 * Les blocs de classement sont détectés par RUPTURE DE QUOTA (nouveau fichier,
 * division ou sexe qui change, ou Quota qui repart à une valeur déjà vue) —
 * PAS en regroupant par catégorie brute (Cat_sur/Cat). Une catégorie ne suffit
 * pas : un arrêté peut regrouper plusieurs catégories d'âge dans UNE SEULE liste
 * ordonnée continue faute d'effectif (ex. U18+U21 en poulies) — re-fractionner
 * par catégorie casserait cet ordre officiel puisque chaque sous-groupe serait
 * renuméroté 1..N indépendamment (bug réel signalé par l'utilisateur : l'épreuve
 * U21HCO, qui regroupe U18 et U21 côté ianseo, ne correspondait pas au fichier).
 * La rupture de Quota, elle, reproduit fidèlement le découpage réel du fichier
 * quel que soit le nombre de catégories embarquées dans chaque bloc.
 *
 * La convention de suffixe de classe (F/H ou M/W) du fichier fait aussi partie
 * de la clé : sans elle, une compétition qui mélange TAE International (F/H) et
 * National (M/W) fusionnerait par erreur leurs classements dès qu'ils partagent
 * division+catégorie+sexe (bug réel signalé par l'utilisateur).
 */
function rep_imp_classement_individuel_construire($tourId)
{
    $tourId = intval($tourId);
    $etat = rep_imp_etat_lire($tourId);
    $fichiers = [];
    foreach ($etat['fichiers'] as $f) $fichiers[intval($f['id'])] = $f;

    $blocs = [];
    $ib = -1;
    foreach ($etat['lignes'] as $l) {
        if ($l['role'] !== 'archer') continue;
        $f = $fichiers[intval($l['fichier'])] ?? null;
        if (!$f || $f['type'] !== 'individuel') continue;
        if ($l['division'] === '' || $l['sexe'] === '') continue;
        $cat = $l['catsur'] !== '' ? $l['catsur'] : $l['cat'];
        if ($cat === '') continue;
        $quota = intval($l['quota']);
        $convention = $f['convention'] ?? 'FH';

        $rupture = ($ib < 0)
            || $blocs[$ib]['fichier'] !== intval($l['fichier'])
            || $blocs[$ib]['division'] !== $l['division']
            || $blocs[$ib]['sexe'] !== $l['sexe']
            || $quota <= $blocs[$ib]['dernierQuota'];
        if ($rupture) {
            $blocs[] = ['fichier' => intval($l['fichier']), 'division' => $l['division'], 'sexe' => $l['sexe'],
                        'convention' => $convention, 'cats' => [], 'lignes' => [], 'dernierQuota' => 0];
            $ib++;
        }
        $blocs[$ib]['cats'][$cat] = true;
        $blocs[$ib]['lignes'][] = $l;
        $blocs[$ib]['dernierQuota'] = $quota;
    }

    // Fusionne les blocs qui retombent sur la même clé (division+catégorie
    // composite+sexe+convention) — arrive si le même groupe est réparti en
    // plusieurs plages non contiguës dans le fichier source.
    $parCle = [];
    foreach ($blocs as $b) {
        $categorie = rep_imp_categorie_composite(array_keys($b['cats']));
        $cle = $b['division'] . '|' . $categorie . '|' . $b['sexe'] . '|' . $b['convention'];
        if (!isset($parCle[$cle])) {
            $parCle[$cle] = [
                'division' => $b['division'], 'categorie' => $categorie, 'sexe' => $b['sexe'],
                'sousType' => '', 'convention' => $b['convention'], 'lignes' => [],
                'libelle' => 'Arrêté (individuel) — ' . $b['division'] . ' ' . $categorie . ' '
                           . ($b['sexe'] === 'F' ? 'F' : 'H') . ' (' . ($b['convention'] === 'MW' ? 'M/W' : 'F/H') . ')',
            ];
        }
        $parCle[$cle]['lignes'] = array_merge($parCle[$cle]['lignes'], $b['lignes']);
    }
    return rep_imp_classement_ecrire($tourId, 'I', array_values($parCle));
}

/**
 * (Re)construit les classements d'équipe dérivés des fichiers « équipe », groupés
 * par division + type (EQ = équipes de clubs, DM = double mixte) et triés par le
 * Quota de l'équipe. Une seule ligne par club (les membres partagent le même
 * quota) : ArCle = code club.
 */
/**
 * (Re)construit les classements d'équipe dérivés des fichiers « équipe » —
 * groupés par division + sous-type (EQ/DM), catégorie composite ET sexe
 * d'équipe, PAS seulement division + sous-type (avant 1.7.2). Un même fichier
 * de dépôt peut lister PLUSIEURS épreuves ianseo à la suite (ex. un dépôt
 * Jeune : équipes mixtes U11-U15 en quota 1..16, puis équipes Femmes U18-U21
 * en quota 1..15, puis équipes Hommes U18-U21 en quota 1..16) — un même club
 * apparaît alors PLUSIEURS FOIS dans le fichier, une fois par épreuve à
 * laquelle il participe, chacune avec SON PROPRE rang dans SON PROPRE
 * classement. Les regrouper sans distinguer sexe/catégorie mélangeait à tort
 * ces rangs dans un seul classement (bug réel signalé par l'utilisateur : un
 * club avec une équipe Femmes ET une équipe Hommes voyait ses deux rangs
 * fusionnés, cassant à la fois l'ordre et l'appariement à la bonne épreuve).
 *
 * Détection par RUPTURE DE QUOTA (comme rep_imp_classement_individuel_construire()) :
 * plus robuste qu'un simple regroupement par sexe/catégorie, ça reste correct
 * même si un même sexe/catégorie apparaissait deux fois dans le fichier pour
 * une autre raison. Catégorie composite : rep_imp_categorie_composite(), la
 * MÊME fonction que pour l'individuel — un classement d'équipe peut regrouper
 * plusieurs catégories d'âge (ex. « U15 Mixte » couvre U11/U13/U15).
 */
function rep_imp_classement_equipe_construire($tourId)
{
    $tourId = intval($tourId);
    $etat = rep_imp_etat_lire($tourId);
    $fichiers = [];
    foreach ($etat['fichiers'] as $f) $fichiers[intval($f['id'])] = $f;

    // Reconstitue une ligne par ÉQUIPE (ses 2-4 archers partagent fichier +
    // quota + code club) à partir des lignes archer déjà parsées, DANS
    // L'ORDRE DU FICHIER SOURCE (jamais l'ordre, retrié, de la consolidation)
    // — indispensable à la détection de rupture ci-dessous.
    $equipes = [];
    foreach ($etat['lignes'] as $l) {
        if ($l['role'] !== 'archer') continue;
        $f = $fichiers[intval($l['fichier'])] ?? null;
        if (!$f || $f['type'] !== 'equipe') continue;
        if ($l['clubcode'] === '') continue;
        // Indice de ligne source (fichier + numéro de ligne), PAS quota+clubcode :
        // le quota repart à 1 à chaque nouvelle épreuve du même fichier, un même
        // club peut donc partager quota+clubcode entre deux équipes distinctes
        // (bug réel trouvé en testant : « RIOM » quota=1 en équipe mixte ET
        // quota=1 en équipe Femmes — sans l'indice de ligne, la seconde équipe
        // fusionnait à tort ses catégories dans la première).
        $cleEquipe = $l['fichier'] . '|' . ($l['equipe_ligne'] ?? ($l['quota'] . '|' . $l['clubcode']));
        if (!isset($equipes[$cleEquipe])) {
            $equipes[$cleEquipe] = [
                'fichier' => intval($l['fichier']), 'division' => $l['division'], 'sousType' => $f['sous'],
                'quota' => intval($l['quota']), 'clubcode' => $l['clubcode'], 'clubnom' => $l['clubnom'],
                'sexe' => $l['equipe_sexe'] ?? '', 'cats' => [],
            ];
        }
        if ($l['cat'] !== '') $equipes[$cleEquipe]['cats'][$l['cat']] = true;
    }

    $blocs = [];
    $ib = -1;
    foreach ($equipes as $eq) {
        $rupture = ($ib < 0)
            || $blocs[$ib]['fichier'] !== $eq['fichier']
            || $blocs[$ib]['division'] !== $eq['division']
            || $eq['quota'] <= $blocs[$ib]['dernierQuota'];
        if ($rupture) {
            $blocs[] = ['fichier' => $eq['fichier'], 'division' => $eq['division'], 'sousType' => $eq['sousType'],
                        'sexes' => [], 'cats' => [], 'equipes' => [], 'dernierQuota' => 0];
            $ib++;
        }
        $blocs[$ib]['equipes'][] = $eq;
        if ($eq['sexe'] !== '') $blocs[$ib]['sexes'][$eq['sexe']] = true;
        foreach ($eq['cats'] as $c => $_) $blocs[$ib]['cats'][$c] = true;
        $blocs[$ib]['dernierQuota'] = $eq['quota'];
    }

    // Fusionne les blocs qui retombent sur la même clé (division + sous-type +
    // catégorie composite + sexe) — arrive si un même groupe est réparti en
    // plusieurs plages non contiguës du fichier source.
    $parCle = [];
    foreach ($blocs as $b) {
        $categorie = rep_imp_categorie_composite(array_keys($b['cats']));
        // Sexe du bloc : celui, unique, de ses équipes ; vide si jamais
        // renseigné (repli neutre — comportement d'avant cette version, un
        // seul classement pour toute la division).
        $sexe = count($b['sexes']) === 1 ? array_key_first($b['sexes']) : (count($b['sexes']) > 1 ? 'X' : '');
        $cle = $b['division'] . '|' . $b['sousType'] . '|' . $categorie . '|' . $sexe;
        if (!isset($parCle[$cle])) {
            $libelleType = $b['sousType'] === 'DM' ? 'Double Mixte' : 'Équipes de clubs';
            $libelleSexe = $sexe === 'F' ? ' Femmes' : ($sexe === 'H' ? ' Hommes' : ($sexe === 'X' ? ' Mixte' : ''));
            $parCle[$cle] = [
                'division' => $b['division'], 'categorie' => $categorie, 'sexe' => $sexe,
                'sousType' => $b['sousType'], 'convention' => '', 'lignes' => [],
                'libelle' => 'Arrêté (équipe) — ' . $b['division']
                           . ($categorie !== '' ? ' ' . $categorie : '') . $libelleSexe . ' — ' . $libelleType,
            ];
        }
        foreach ($b['equipes'] as $eq) {
            $parCle[$cle]['lignes'][] = ['licence' => $eq['clubcode'], 'nom' => $eq['clubnom'],
                'clubcode' => $eq['clubcode'], 'clubnom' => $eq['clubnom'], 'quota' => $eq['quota']];
        }
    }
    return rep_imp_classement_ecrire($tourId, 'E', array_values($parCle));
}

/**
 * Fabrique commune aux deux constructeurs ci-dessus (upsert par libellé).
 * $descripteurs : liste de ['division','categorie','sexe','sousType','convention',
 * 'libelle','lignes'] déjà entièrement calculés par l'appelant. AcDivision/
 * AcSousType/AcCategorie/AcSexe/AcConvention permettent au moteur de retrouver un
 * classement SANS dépendre du texte du libellé : équipe par division (sources
 * « par club selon l'arrêté équipe/double mixte »), individuel par division +
 * catégorie + sexe (+ convention si plusieurs sous-disciplines coexistent) pour
 * l'association automatique épreuve → classement (rep_classement_arrete()).
 */
function rep_imp_classement_ecrire($tourId, $type, $descripteurs)
{
    $crees = [];
    $now = StrSafe_DB(date('Y-m-d H:i:s'));
    foreach ($descripteurs as $d) {
        $lignes = $d['lignes'];
        usort($lignes, function ($x, $y) { return intval($x['quota']) - intval($y['quota']); });

        $rs = safe_r_sql("SELECT AcId FROM REP_ArrClassements
            WHERE AcTournament=" . intval($tourId) . " AND AcType=" . StrSafe_DB($type) . "
              AND AcLibelle=" . StrSafe_DB($d['libelle']));
        $r = $rs ? safe_fetch($rs) : null;
        if ($r) {
            $acId = intval($r->AcId);
            safe_w_sql("UPDATE REP_ArrClassements SET AcMaj=$now, AcDivision=" . StrSafe_DB($d['division'])
                . ", AcSousType=" . StrSafe_DB($d['sousType']) . ", AcCategorie=" . StrSafe_DB($d['categorie'])
                . ", AcSexe=" . StrSafe_DB($d['sexe']) . ", AcConvention=" . StrSafe_DB($d['convention'])
                . " WHERE AcId=$acId");
            safe_w_sql("DELETE FROM REP_ArrRangs WHERE ArClassement=$acId");
        } else {
            safe_w_sql("INSERT INTO REP_ArrClassements
                (AcTournament, AcType, AcDivision, AcSousType, AcCategorie, AcSexe, AcConvention, AcLibelle, AcMaj)
                VALUES (" . intval($tourId) . ", " . StrSafe_DB($type) . ", " . StrSafe_DB($d['division']) . ", "
                . StrSafe_DB($d['sousType']) . ", " . StrSafe_DB($d['categorie']) . ", " . StrSafe_DB($d['sexe']) . ", "
                . StrSafe_DB($d['convention']) . ", " . StrSafe_DB($d['libelle']) . ", $now)");
            // safe_w_last_id() — PAS "SELECT LAST_INSERT_ID()" via safe_r_sql() : ianseo
            // sépare connexion d'écriture et de lecture (voir CLAUDE.md), et
            // LAST_INSERT_ID() est propre à CHAQUE connexion MySQL — le lire sur la
            // connexion de lecture renvoyait systématiquement 0 (bug réel confirmé en
            // testant : la ligne AcRangs n'était alors jamais insérée pour un classement
            // tout juste créé, corrigée seulement au clic suivant qui retombe sur le
            // chemin UPDATE — c'était le « il faut cliquer 2 fois » signalé par
            // l'utilisateur quand aucun classement n'existait encore).
            $acId = safe_w_last_id();
        }
        if (!$acId) continue;

        $rang = 0; $vals = [];
        foreach ($lignes as $l) {
            $rang++;
            $vals[] = "($acId, $rang, " . StrSafe_DB($l['licence']) . ", " . StrSafe_DB($l['nom']) . ", "
                    . StrSafe_DB($l['clubcode']) . ", " . StrSafe_DB($l['clubnom']) . ")";
        }
        if ($vals) {
            safe_w_sql("INSERT INTO REP_ArrRangs (ArClassement, ArRang, ArCle, ArNom, ArClubCode, ArClubNom)
                VALUES " . implode(',', $vals));
        }
        $crees[] = ['id' => $acId, 'libelle' => $d['libelle'], 'nb' => count($lignes), 'type' => $type];
    }
    return $crees;
}

/**
 * Réinitialise les classements dérivés de l'arrêté (REP_ArrClassements/REP_ArrRangs) et
 * les associations épreuve → classement (REP_ArrMapping, sinon elles pointeraient vers
 * un AcId supprimé) — PAS les fichiers déposés ni la consolidation (REP_ImpEtat), qui
 * restent : permet de reconstruire les classements proprement (rejouer « Construire /
 * actualiser ») sans devoir re-déposer les fichiers. Demandé par l'utilisateur.
 * Retourne le nombre de classements supprimés.
 */
function rep_arr_classements_reinitialiser($tourId)
{
    $tourId = intval($tourId);
    $ids = [];
    $rs = safe_r_sql("SELECT AcId FROM REP_ArrClassements WHERE AcTournament=$tourId");
    while ($rs && $r = safe_fetch($rs)) $ids[] = intval($r->AcId);
    if ($ids) {
        safe_w_sql("DELETE FROM REP_ArrRangs WHERE ArClassement IN (" . implode(',', $ids) . ")");
        safe_w_sql("DELETE FROM REP_ArrClassements WHERE AcTournament=$tourId");
    }
    safe_w_sql("DELETE FROM REP_ArrMapping WHERE AmTournament=$tourId");
    return count($ids);
}

/** Classements d'arrêté existants pour la compétition (pour le sélecteur). */
function rep_arr_classements_liste($tourId)
{
    $out = [];
    $rs = safe_r_sql("SELECT ac.AcId, ac.AcType, ac.AcLibelle, ac.AcMaj,
            (SELECT COUNT(*) FROM REP_ArrRangs WHERE ArClassement=ac.AcId) AS nb
        FROM REP_ArrClassements ac
        WHERE ac.AcTournament=" . intval($tourId) . "
        ORDER BY ac.AcType, ac.AcLibelle");
    while ($rs && $r = safe_fetch($rs)) {
        $out[] = ['id' => intval($r->AcId), 'type' => $r->AcType, 'libelle' => $r->AcLibelle,
                  'nb' => intval($r->nb), 'maj' => $r->AcMaj];
    }
    return $out;
}

/**
 * Rangs de club du classement d'équipe d'arrêté (EQ ou DM) d'une division, pour
 * les sources moteur « par club selon l'arrêté équipe/double mixte ». Depuis
 * 1.7.2, une division+sous-type peut avoir PLUSIEURS classements d'équipe
 * (scindés par catégorie composite et sexe, voir
 * rep_imp_classement_equipe_construire() : un club peut avoir une équipe
 * Femmes ET une équipe Hommes, chacune sa propre épreuve ianseo) — $epreuveDef
 * permet de choisir celui qui correspond réellement à l'épreuve appelante.
 * Retourne [CODE_CLUB => rang] (clés en majuscules), ou [] si aucun classement
 * ne convient.
 */
function rep_arr_rangs_clubs($tourId, $division, $sousType, $epreuveDef = null)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT AcId, AcCategorie, AcSexe FROM REP_ArrClassements
        WHERE AcTournament=$tourId AND AcType='E'
          AND AcDivision=" . StrSafe_DB($division) . " AND AcSousType=" . StrSafe_DB($sousType));
    $candidats = [];
    while ($rs && $r = safe_fetch($rs)) $candidats[] = $r;
    if (!$candidats) return [];

    $acId = 0;
    if (!$epreuveDef) {
        $acId = intval($candidats[0]->AcId);   // repli historique : appelant sans épreuve à fournir
    } else {
        // Catégorie brute de l'épreuve (mêmes lettres à ôter que rep_classement_arrete()) :
        // un classement d'équipe convient si sa catégorie COMPOSITE la couvre — pas une
        // égalité stricte, un même classement d'équipe pouvant regrouper plusieurs
        // épreuves individuelles d'âges différents (ex. « U15 Mixte » couvre U11/U13/U15).
        $categoriesBrutes = [];
        if (!empty($epreuveDef['classes'])) {
            foreach ($epreuveDef['classes'] as $c) $categoriesBrutes[] = preg_replace('/[HFMW]$/', '', (string) $c);
        }
        $sexeEpreuve = $epreuveDef['sexe'] ?? '';
        foreach ($candidats as $c) {
            $categoriesClub = $c->AcCategorie !== '' ? explode('-', $c->AcCategorie) : [];
            $sexeOk = ($c->AcSexe === '' || $c->AcSexe === 'X' || $sexeEpreuve === '' || $c->AcSexe === $sexeEpreuve);
            $catOk  = !$categoriesBrutes || !$categoriesClub || array_intersect($categoriesBrutes, $categoriesClub);
            if ($sexeOk && $catOk) { $acId = intval($c->AcId); break; }
        }
        // Rien de compatible : pas de classement pour CETTE épreuve précise (même si
        // la division+sous-type en a un pour une AUTRE) — mieux vaut [] qu'un
        // classement au hasard, silencieusement faux.
    }
    if (!$acId) return [];

    $out = [];
    $rs2 = safe_r_sql("SELECT ArCle, ArRang FROM REP_ArrRangs WHERE ArClassement=$acId");
    while ($rs2 && $rr = safe_fetch($rs2)) {
        $cle = mb_strtoupper($rr->ArCle, 'UTF-8');
        $rang = intval($rr->ArRang);
        if (!isset($out[$cle]) || $rang < $out[$cle]) $out[$cle] = $rang;
    }
    return $out;
}

/**
 * Clubs ayant une équipe double mixte dans l'arrêté, avec TOUS les archers du
 * club éligibles selon la portée RÉELLE de l'épreuve ianseo double mixte liée
 * (Events.EvMixedTeam=1, jointe à EventClass pour les division/classe
 * acceptées) — PAS seulement les classes des 2 archers nommés dans le dépôt.
 *
 * Règle demandée par l'utilisateur, double mixte SEULEMENT (jamais équipe de
 * clubs, qui garde ses 4 archers nommés sans plus) : la composition définitive
 * du double mixte se valide plus tard dans un écran dédié de ianseo, à partir
 * des archers qui tiennent déjà le drapeau double mixte — s'il ne porte que sur
 * les 2 nommés dans l'arrêté, ce futur écran ne peut pas proposer un
 * remplaçant du même club pourtant éligible. Les archers d'un club dans une
 * AUTRE catégorie (division/classe non acceptée par cette épreuve précise) ne
 * sont jamais touchés.
 *
 * EventClass n'est PAS une simple copie de EvTeamEvent : c'est la table de
 * portée elle-même (division/classe acceptées par groupe de règle), jointe sur
 * EcCode=EvCode — indépendante de qui porte actuellement le drapeau
 * EnTeamMixEvent, donc capable de révéler des archers eligibles qui ne sont
 * pas dans l'arrêté du tout (pas de EnTeamMixEvent) ou de club sans dépôt DM.
 * Ici on ne part QUE des clubs qui ONT un dépôt DM (règle de l'utilisateur),
 * pas de tous les clubs éligibles.
 *
 * Retourne [ ['clubcode','clubnom','division','classes'=>[...],
 *             'archers'=>[ ['enid','licence','nom','class','dejaDm'=>bool], … ] ], … ].
 */
function rep_imp_dm_clubs($tourId)
{
    $tourId = intval($tourId);
    $r = rep_imp_consolider($tourId);

    // (clubcode => division => nom du club) : clubs avec au moins un archer
    // dans un dépôt double mixte, par division (un club peut avoir des équipes
    // DM dans plusieurs armes).
    $clubs = [];
    foreach ($r['consolide'] as $l) {
        if ($l['role'] !== 'archer' || empty($l['doublemixte']) || $l['clubcode'] === '') continue;
        $clubs[$l['clubcode']][$l['division']] = $l['clubnom'];
    }

    $out = [];
    foreach ($clubs as $clubcode => $divisions) {
        foreach ($divisions as $division => $clubnom) {
            $classes = [];
            $rsC = safe_r_sql("SELECT DISTINCT ec.EcClass FROM EventClass ec
                JOIN Events ev ON ev.EvCode=ec.EcCode AND ev.EvTournament=ec.EcTournament
                WHERE ev.EvTournament=$tourId AND ev.EvTeamEvent=1 AND ev.EvMixedTeam=1
                  AND ec.EcDivision=" . StrSafe_DB($division));
            while ($rsC && $rc = safe_fetch($rsC)) {
                if (trim((string) $rc->EcClass) !== '') $classes[] = trim($rc->EcClass);
            }
            if (!$classes) continue;   // aucune épreuve double mixte ianseo pour cette division : rien à proposer

            $in = implode(',', array_map('StrSafe_DB', array_unique($classes)));
            $rsA = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnFirstName, e.EnName, e.EnClass, e.EnTeamMixEvent
                FROM Entries e
                JOIN Countries c ON c.CoId=e.EnCountry AND c.CoTournament=e.EnTournament
                WHERE e.EnTournament=$tourId AND e.EnAthlete=1 AND e.EnDivision=" . StrSafe_DB($division) . "
                  AND e.EnClass IN ($in) AND c.CoCode " . rep_coll() . " = " . StrSafe_DB($clubcode));
            $archers = [];
            while ($rsA && $ra = safe_fetch($rsA)) {
                $archers[] = ['enid' => intval($ra->EnId), 'licence' => $ra->EnCode,
                    'nom' => trim($ra->EnFirstName . ' ' . $ra->EnName), 'class' => $ra->EnClass,
                    'dejaDm' => intval($ra->EnTeamMixEvent) === 1];
            }
            if (!$archers) continue;
            $out[] = ['clubcode' => $clubcode, 'clubnom' => $clubnom, 'division' => $division,
                'classes' => array_values(array_unique($classes)), 'archers' => $archers];
        }
    }
    return $out;
}

/** Association épreuve → classement d'arrêté, propre à la compétition. */
function rep_arr_mapping_lire($tourId, $event)
{
    $rs = safe_r_sql("SELECT AmClassement FROM REP_ArrMapping
        WHERE AmTournament=" . intval($tourId) . " AND AmEvent=" . StrSafe_DB($event));
    $r = $rs ? safe_fetch($rs) : null;
    return $r ? intval($r->AmClassement) : 0;
}

function rep_arr_mapping_ecrire($tourId, $event, $acId)
{
    $tourId = intval($tourId);
    $acId = intval($acId);
    if ($acId <= 0) {
        safe_w_sql("DELETE FROM REP_ArrMapping WHERE AmTournament=$tourId AND AmEvent=" . StrSafe_DB($event));
        return;
    }
    safe_w_sql("INSERT INTO REP_ArrMapping (AmTournament, AmEvent, AmClassement)
        VALUES ($tourId, " . StrSafe_DB($event) . ", $acId)
        ON DUPLICATE KEY UPDATE AmClassement=$acId");
}

/**
 * Enregistre automatiquement, pour chaque épreuve SANS association déjà
 * enregistrée, sa suggestion de classement d'arrêté (rep_classement_arrete()
 * — correspondance non ambiguë division+catégorie+sexe(+convention), jamais
 * un devinage risqué) — appelée juste après « Construire / actualiser les
 * classements » pour que construction ET association se fassent en UN seul
 * clic. Avant, la suggestion n'était qu'affichée (badge « suggéré », pas
 * enregistrée) : il fallait rouvrir le sélecteur de chaque épreuve pour
 * l'enregistrer réellement — bug d'ergonomie signalé par l'utilisateur (« il
 * faut cliquer 2 fois »). N'écrase JAMAIS une association déjà enregistrée
 * (manuelle ou déjà auto-associée). Retourne le nombre d'épreuves associées.
 */
function rep_arr_associer_suggestions($tourId, $set)
{
    $tourId = intval($tourId);
    $epr = rep_epreuves($tourId);
    $n = 0;
    foreach ($epr as $cle => $e) {
        if (rep_arr_mapping_lire($tourId, $cle) > 0) continue;
        $cl = rep_classement_arrete($tourId, $cle, $e, $set);
        if ($cl && !empty($cl['arrid'])) {
            rep_arr_mapping_ecrire($tourId, $cle, intval($cl['arrid']));
            $n++;
        }
    }
    return $n;
}
