<?php
/**
 * print.php — impression PDF du classement d'une étape.
 *
 *   print.php?step=<étape>[&cat=<catégorie>]
 *
 * Sans `cat`, toutes les catégories traitées sont imprimées à la suite.
 * Le classement est recalculé au moment de l'impression : une feuille sortie
 * après une correction de score porte forcément les bonnes valeurs (et une étape
 * verrouillée sort ses valeurs archivées, cf. lib/archive.php).
 *
 * MISE EN PAGE : celle des classements de ianseo, reprise à l'identique depuis
 * `Common/pdf/chunks/DivClasTeam.inc.php` — largeur utile 190 mm, bandeau de
 * groupe en gras 10 sur fond plein, en-têtes de colonnes en gras 7 hautes de 4,
 * lignes hautes de 4 avec les nombres en police fixe, filet de 0,5 sous les
 * en-têtes, saut de page géré par SamePage() avec rappel « suite ».
 * L'impression voulue n'existe pas telle quelle dans ianseo (aucun classement
 * natif ne porte des points de sélection), on en reprend donc la structure.
 *
 * La feuille ne montre pas que le rang et les points : elle montre les
 * COMPOSANTES et le critère qui a départagé chaque archer. Un classement de
 * sélection doit pouvoir être refait à la main depuis la feuille.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';
require_once($CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php');

$cfg = selec_config_lire($SELEC_TOUR);
if (!$cfg || !$cfg['snapshot']) die('Compétition non rattachée à un mode de sélection.');

$mode   = $cfg['snapshot'];
$stepId = (string) ($_GET['step'] ?? '');
$st     = selec_prepa_etape($mode, $stepId);
if (!$st) die('Étape inconnue.');

$cats = selec_categories_actives($SELEC_TOUR, $cfg);
$un   = (string) ($_GET['cat'] ?? '');
if ($un !== '' && in_array($un, $cats, true)) $cats = array($un);
$tousC = selec_categories($SELEC_TOUR);
$etatGel = selec_arch_etat($SELEC_TOUR);

// ── Nom de la famille de points : jamais un « Points » générique ────────────
// Le règlement distingue des pools qui ne se mélangent pas (Qualifications,
// Journaliers, Tournois, Tournois à 6, Tournois de Poule, Matchs Simulés) ;
// la colonne porte donc le nom exact de celui qu'elle attribue.
$famille = '';
if (!empty($st['famille']) && isset($mode['familles'][$st['famille']])) {
    $famille = $mode['familles'][$st['famille']];
}
$titre = (isset($st['libelle']) ? $st['libelle'] : $stepId);

// ── Colonnes, selon le type d'étape ─────────────────────────────────────────
$type = $st['type'];
$sources = (array) ($st['sources'] ?? array());
$avecPoints = !empty($st['bareme']);

$cols = array(
    array('t' => 'Rg',     'w' => 9,  'a' => 'R', 'f' => 'B'),
    array('t' => 'Archer', 'w' => 50, 'a' => 'L'),
    array('t' => 'Club',   'w' => 17, 'a' => 'L'),
);
if ($type === 'qualification' || $type === 'duels_simules') {
    $cols[] = array('t' => 'Score',   'w' => 14, 'a' => 'R', 'k' => 'score', 'n' => 1);
    $cols[] = array('t' => '10',      'w' => 10, 'a' => 'R', 'k' => 'dix',   'n' => 1);
    $cols[] = array('t' => 'X',       'w' => 10, 'a' => 'R', 'k' => 'x',     'n' => 1);
    $cols[] = array('t' => 'Flèches', 'w' => 13, 'a' => 'R', 'k' => 'fleches', 'n' => 1);
} elseif ($type === 'tournoi') {
    $cols[] = array('t' => 'Place',    'w' => 11, 'a' => 'R', 'k' => 'place',     'n' => 1);
    $cols[] = array('t' => 'Pts clt',  'w' => 13, 'a' => 'R', 'k' => 'pts_clt',  'p' => 1, 'n' => 1);
    $cols[] = array('t' => 'Sets',     'w' => 10, 'a' => 'R', 'k' => 'sets',      'n' => 1);
    $cols[] = array('t' => 'Moy. set', 'w' => 16, 'a' => 'R', 'k' => '@moyset',   'n' => 1);
    $cols[] = array('t' => 'Rg perf',  'w' => 13, 'a' => 'R', 'k' => 'rang_perf', 'n' => 1);
    $cols[] = array('t' => 'Pts perf', 'w' => 14, 'a' => 'R', 'k' => 'pts_perf', 'p' => 1, 'n' => 1);
    $cols[] = array('t' => 'Somme',    'w' => 13, 'a' => 'R', 'k' => 'somme',    'p' => 1, 'n' => 1);
    $cols[] = array('t' => 'V',        'w' => 8,  'a' => 'R', 'k' => 'victoires', 'n' => 1);
} elseif ($type === 'poule') {
    $cols[] = array('t' => 'V',        'w' => 9,  'a' => 'R', 'k' => 'victoires', 'n' => 1);
    $cols[] = array('t' => 'Pts clt',  'w' => 13, 'a' => 'R', 'k' => 'pts_clt',  'p' => 1, 'n' => 1);
    $cols[] = array('t' => 'Sets',     'w' => 10, 'a' => 'R', 'k' => 'sets',      'n' => 1);
    $cols[] = array('t' => 'Moy. set', 'w' => 16, 'a' => 'R', 'k' => '@moyset',   'n' => 1);
    $cols[] = array('t' => 'Rg perf',  'w' => 13, 'a' => 'R', 'k' => 'rang_perf', 'n' => 1);
    $cols[] = array('t' => 'Pts perf', 'w' => 14, 'a' => 'R', 'k' => 'pts_perf', 'p' => 1, 'n' => 1);
    $cols[] = array('t' => 'Somme',    'w' => 13, 'a' => 'R', 'k' => 'somme',    'p' => 1, 'n' => 1);
} else {
    // Une journée ne se relit pas seulement en points : « 8 » ne dit pas si
    // l'archer a fait 660 en tête ou 620 en huitième position. On place donc,
    // devant les points de chaque étape, ce qu'il y a RÉALISÉ et sa place —
    // « 660/1 ». La feuille redevient vérifiable sans ouvrir une autre page.
    $detail = ($type === 'journee');
    foreach ($sources as $s) {
        if ($detail) {
            // Un détail de tournoi porte deux grandeurs (« 1 pl. / 27,4853 ») :
            // il lui faut plus de place, et une police proportionnelle un cran
            // plus petite — c'est un libellé, pas une colonne de nombres à
            // aligner. Sans cela, quatre tournois ne tiennent pas en paysage.
            $tsrc = selec_pdf_type_source($mode, $s);
            $duel = in_array($tsrc, array('tournoi', 'poule'), true);
            $cols[] = $duel
                ? array('t' => 'Détail ' . $s, 'w' => 19, 'a' => 'R', 'k' => '@det:' . $s, 'fs' => 6.5)
                : array('t' => 'Détail ' . $s, 'w' => 15, 'a' => 'R', 'k' => '@det:' . $s, 'n' => 1);
        }
        $cols[] = array('t' => $s, 'w' => 12, 'a' => 'R', 'k' => '@src:' . $s, 'p' => 1, 'n' => 1);
    }
    // Le titre nomme les étapes additionnées : sans cela, deux « Total » de
    // journées différentes se ressemblent et rien ne dit ce qu'ils recouvrent.
    $libTotal = $sources ? ('Total pts ' . implode('+', $sources)) : 'Total';
    $cols[] = array('t' => $libTotal, 'w' => max(14, 3 + 1.45 * strlen($libTotal)),
        'a' => 'R', 'k' => 'total', 'p' => 1, 'n' => 1);
}
if ($avecPoints) {
    // 32 et non 30 : « Points de Matchs Simulés », le plus long des libellés de
    // famille livrés, mesure 30,5 mm en gras 7 et débordait de sa colonne.
    $cols[] = array('t' => $famille ?: 'Points', 'w' => 32, 'a' => 'R', 'k' => '@points',
        'p' => 1, 'n' => 1, 'f' => 'B');
}

// ── Détail des départages, sur le classement final seulement ────────────────
// Un classement final se conteste : il doit porter la VALEUR de chaque critère
// de départage, pas seulement le nom de celui qui a tranché. Sur les autres
// étapes la cascade tient en une ligne de pied de page — ici, il faut pouvoir
// refaire le calcul ligne à ligne.
$critDefs = array();
if ($type === 'final') {
    foreach ((array) ($st['departage'] ?? array()) as $dp) {
        if ((isset($dp['c']) ? $dp['c'] : '') === 'egalite') break;
        $inf = selec_critere_infos($dp);
        if (!$inf) continue;
        $critDefs[] = array('def' => $dp, 'id' => $inf['id'], 'label' => $inf['label']);
    }
}
// Les libellés de critère sont longs (« Valeur moyenne de flèche ») alors que
// leurs valeurs sont courtes : leur en-tête descend à 5,5 pt (`hfs`) pour ne pas
// manger la colonne du nom. Le nom complet reste lisible — l'abréger ou le
// numéroter obligerait à chercher ailleurs ce que compare la colonne.
$critDe = -1; $critA = -1;
foreach ($critDefs as $ic => $cd) {
    if ($critDe < 0) $critDe = count($cols);
    $critA = count($cols);
    $cols[] = array('t' => $cd['label'], 'w' => max(20, 3 + 1.05 * mb_strlen($cd['label'], 'UTF-8')),
        'a' => 'R', 'k' => '@crit:' . $ic, 'n' => 1, 'fs' => 6.5, 'hfs' => 5.5);
}
// Largeur de la colonne Départage : une cascade réduite à « égalité conservée »
// ne peut afficher que « ex aequo », il est inutile de lui réserver de quoi
// écrire « Meilleure qualification ». Ces millimètres-là manquent ailleurs.
$tieCourt = true;
foreach ((array) ($st['departage'] ?? array()) as $dp) {
    if ((isset($dp['c']) ? $dp['c'] : '') !== 'egalite') { $tieCourt = false; break; }
}
$cols[] = array('t' => 'Départage', 'w' => $tieCourt ? 18 : 34, 'a' => 'L', 'k' => '@tie');

// Largeur utile de ianseo : 190 mm en portrait, 277 en paysage. On répartit
// l'écart sur la colonne du nom plutôt que de laisser un tableau flottant.
$brut = 0;
foreach ($cols as $c) $brut += $c['w'];
$paysage = ($brut > 190);
$LARGEUR = $paysage ? 277 : 190;

// Garde-fou : le nom de l'archer ne descend jamais sous 34 mm. Une cascade de
// départage longue (un autre mode en aura) rognerait sinon la seule colonne qui
// rend la feuille utilisable. On reprend les millimètres sur les colonnes de
// critères, qui sont larges à cause de leur titre, pas de leur contenu.
$dispoNom = $cols[1]['w'] + ($LARGEUR - $brut);
if ($dispoNom < 34 && $critDe >= 0) {
    $manque = 34 - $dispoNom;
    for ($j = $critA; $j >= $critDe && $manque > 0.01; $j--) {
        $pris = min(max(0, $cols[$j]['w'] - 18), $manque);
        $cols[$j]['w'] -= $pris;
        $brut -= $pris;
        $manque -= $pris;
    }
}
$cols[1]['w'] += ($LARGEUR - $brut);

/** Type d'une étape du mode, par son identifiant. */
function selec_pdf_type_source($mode, $srcId)
{
    foreach ((array) ($mode['etapes'] ?? array()) as $s) {
        if ($s['id'] === $srcId) return (string) $s['type'];
    }
    return '';
}

/**
 * Ce qu'un archer a réalisé dans une étape source, résumé en une case.
 *
 * La grandeur affichée dépend de la façon dont l'étape se gagne :
 *  - qualification, duels simulés : le score et la place — « 660/1 » ;
 *  - tournoi, poule : la place ET le niveau de performance, la moyenne de volée
 *    — « 1 pl. / 27,4853 ». Un tournoi ne se résume pas à sa place : deux
 *    archers sortis au même tour n'ont pas tiré pareil, et c'est précisément la
 *    moyenne de volée qui attribue les Points de Performance.
 *
 * Afficher partout « le score » n'aurait aucun sens pour un tournoi, où le total
 * marqué ne classe personne.
 */
function selec_pdf_detail($ctx, $mode, $srcId, $archerId)
{
    if (empty($ctx['etapes'][$srcId]['lignes'][$archerId])) return '—';
    $l = $ctx['etapes'][$srcId]['lignes'][$archerId];
    $d = isset($l['detail']) ? $l['detail'] : array();
    $type = selec_pdf_type_source($mode, $srcId);

    if ($type === 'tournoi' || $type === 'poule') {
        // En poule il n'y a pas de tableau, donc pas de « place » : c'est le
        // rang de l'étape qui en tient lieu.
        $place = ($type === 'tournoi') ? intval($d['place'] ?? 0) : intval($l['rang']);
        if (!$place) return '—';
        $perf = selec_fmt_frac($d['set_total'] ?? 0, $d['sets'] ?? 0);
        return $place . ' pl. / ' . $perf;
    }

    $val = intval($d['score'] ?? 0);
    if (!$val) return '—';
    return $val . '/' . intval($l['rang']);
}

/** Rend une valeur comparable lisible, selon son type. */
function selec_pdf_valeur($v)
{
    if ($v === null) return '—';
    if ($v['t'] === 'i') return (string) $v['n'];
    // Valeur de flèche : 6 décimales, comme dans les statistiques par archer.
    // C'est un critère de départage, il faut voir où l'écart se joue.
    if ($v['t'] === 'f') return selec_fmt_frac($v['n'], $v['d'], 6);
    if ($v['t'] === 'v') return implode('/', $v['v']);
    return '—';
}

/** Étape du mode, par son identifiant. */
function selec_pdf_etape($mode, $sid)
{
    foreach ((array) ($mode['etapes'] ?? array()) as $s) {
        if ($s['id'] === $sid) return $s;
    }
    return null;
}

/**
 * Ce qu'un archer au classement figé a le droit d'afficher.
 *
 * Son classement est celui de la coupure : seules les colonnes qui ont servi à
 * l'établir ont un sens. Les autres — les journées qu'il n'a pas disputées, les
 * critères propres à la cascade du classement final — restent VIDES. Y écrire
 * « 0 » laisserait croire à un résultat nul là où il n'y a pas eu de tir.
 *
 * @return array ['sources' => [ids autorisés], 'crits' => [ids de critères autorisés]]
 */
function selec_pdf_perimetre_fige($ctx, $mode, $coupureId)
{
    $cut = selec_pdf_etape($mode, $coupureId);
    if (!$cut) return array('sources' => array(), 'crits' => array());

    $crits = array();
    foreach ((array) ($cut['departage'] ?? array()) as $dp) {
        if ((isset($dp['c']) ? $dp['c'] : '') === 'egalite') break;
        $cr = selec_critere($ctx, $dp, $coupureId);
        if ($cr) $crits[$cr['id']] = true;
    }
    return array(
        'sources' => array_flip((array) ($cut['sources'] ?? array())),
        'crits'   => $crits,
    );
}

/** Bandeau de groupe + en-têtes de colonnes, à la manière des classements ianseo. */
function selec_pdf_entete($pdf, $descr, $sousTitre, $cols, $largeur, $suite = false)
{
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell($largeur, 6, $descr, 1, 1, 'C', 1);
    if ($suite) {
        $pdf->SetXY($largeur - 20, $pdf->GetY() - 6);
        $pdf->SetFont($pdf->FontStd, '', 6);
        $pdf->Cell(30, 6, $pdf->Continue, 0, 1, 'R', 0);
    }
    if ($sousTitre !== '') {
        $pdf->SetFont($pdf->FontStd, '', 7);
        $pdf->Cell($largeur, 4, $sousTitre, 0, 1, 'L', 0);
    }
    $n = count($cols);
    foreach ($cols as $i => $c) {
        // `hfs` : un titre long (un critère de départage) descend d'un cran
        // plutôt que d'imposer sa largeur à toute la colonne.
        $pdf->SetFont($pdf->FontStd, 'B', isset($c['hfs']) ? $c['hfs'] : 7);
        $pdf->Cell($c['w'], 4, $c['t'], 1, ($i === $n - 1 ? 1 : 0), $c['a'], 1);
    }
    $pdf->SetFont($pdf->FontStd, '', 1);
    $pdf->Cell($largeur, 0.5, '', 1, 1, 'C', 0);
}

$pdf = new ResultPDF($titre, !$paysage);
$pdf->Continue = 'suite';

$premiere = true;
foreach ($cats as $cat) {
    $binds = selec_binds_lire($SELEC_TOUR, $cat);
    $ctx = selec_calculer($SELEC_TOUR, $cat, $mode, $binds);
    $lignes = isset($ctx['etapes'][$stepId]['lignes']) ? $ctx['etapes'][$stepId]['lignes'] : array();

    $descr = $titre . '  —  ' . (isset($tousC[$cat]) ? $tousC[$cat]['nom'] : $cat);
    $sous  = $mode['libelle'];
    if ($famille) $sous .= '   ·   attribue les ' . $famille;
    if (isset($etatGel[$stepId])) $sous .= '   ·   étape verrouillée le ' . $etatGel[$stepId]['date'];
    $sous .= '   ·   édité le ' . date('d/m/Y à H:i');

    // Bandeau + en-têtes + au moins trois lignes doivent tenir sur la page.
    if (!$premiere) $pdf->SetY($pdf->GetY() + 5);
    if (!$pdf->SamePage(6 + 4 + 4 + 12)) $pdf->AddPage();
    $premiere = false;
    selec_pdf_entete($pdf, $descr, $sous, $cols, $LARGEUR);

    if (!$lignes) {
        $pdf->SetFont($pdf->FontStd, 'I', 8);
        $pdf->Cell($LARGEUR, 5, 'Aucun résultat pour cette étape.', 1, 1, 'L', 0);
        continue;
    }

    // Critères de départage, évalués sur le contexte de CETTE catégorie. Les
    // en-têtes, eux, sont posés une fois pour tout le document.
    $crits = array();
    foreach ($critDefs as $cd) {
        $cr = selec_critere($ctx, $cd['def'], $stepId);
        $crits[] = $cr ? $cr['fn'] : null;
    }
    // Périmètre d'affichage des archers au classement figé, calculé une fois par
    // coupure rencontrée (il n'y en a qu'une en pratique).
    $perimetres = array();
    $nbFiges = 0;
    $coupure = null;

    uasort($lignes, function ($a, $b) { return $a['rang'] <=> $b['rang']; });
    $n = count($cols);
    foreach ($lignes as $id => $l) {
        if (!$pdf->SamePage(4)) {
            $pdf->AddPage();
            selec_pdf_entete($pdf, $descr, '', $cols, $LARGEUR, true);
        }
        $d = $l['detail'];
        $a = isset($ctx['archers'][$id]) ? $ctx['archers'][$id] : array();

        // Classement figé à la coupure : seules les colonnes qui l'ont établi
        // sont renseignées, les autres restent vides.
        $per = null;
        if (!empty($d['fige'])) {
            $cid = $d['fige'];
            if (!isset($perimetres[$cid])) $perimetres[$cid] = selec_pdf_perimetre_fige($ctx, $mode, $cid);
            $per = $perimetres[$cid];
            $coupure = selec_pdf_etape($mode, $cid);
            $nbFiges++;
        }

        $vals = array(
            $l['rang'] . ($l['exaequo'] ? '=' : ''),
            isset($a['affiche']) ? $a['affiche'] : ('archer ' . $id),
            isset($a['club']) ? $a['club'] : '',
        );
        foreach (array_slice($cols, 3) as $c) {
            $k = $c['k'];
            if ($k === '@points')      $v = selec_fmt_points($l['points_c']);
            elseif ($k === '@tie')     $v = $l['exaequo'] ? 'ex aequo' : (string) $l['tie'];
            elseif ($k === '@moyset')  $v = selec_fmt_frac($d['set_total'] ?? 0, $d['sets'] ?? 0);
            elseif (strpos($k, '@det:') === 0) $v = selec_pdf_detail($ctx, $mode, substr($k, 5), $id);
            elseif (strpos($k, '@src:') === 0) {
                $s = substr($k, 5);
                $v = ($per && !isset($per['sources'][$s]))
                    ? '' : selec_fmt_points($d['par_source'][$s] ?? 0);
            }
            elseif (strpos($k, '@crit:') === 0) {
                $ic = intval(substr($k, 6));
                if (empty($crits[$ic])) $v = '—';
                elseif ($per && !isset($per['crits'][$critDefs[$ic]['id']])) $v = '';
                else $v = selec_pdf_valeur(call_user_func($crits[$ic], $id));
            }
            elseif (!empty($c['p']))   $v = selec_fmt_points($d[$k] ?? 0);
            else                       $v = (string) intval($d[$k] ?? 0);
            $vals[] = $v;
        }

        $i = 0;
        while ($i < $n) {
            $c = $cols[$i];
            // Nombres en police fixe : c'est ce qui aligne les colonnes chiffrées
            // dans tous les classements de ianseo. `fs` permet à une colonne de
            // libellé composite de descendre d'un cran sans toucher aux autres.
            $police = !empty($c['n']) ? $pdf->FontFix : $pdf->FontStd;
            $taille = isset($c['fs']) ? $c['fs'] : (!empty($c['n']) ? 7.5 : 7);
            $pdf->SetFont($police, !empty($c['f']) ? $c['f'] : '', $taille);
            $pdf->Cell($c['w'], 4, $vals[$i], 1, 0, $c['a'], 0);
            $i++;
        }
        $pdf->Ln();
    }

    // ── Cascade de départage appliquée ──────────────────────────────────────
    $casc = array();
    foreach ((array) ($st['departage'] ?? array()) as $dp) {
        $c = isset($dp['c']) ? $dp['c'] : '';
        if ($c === 'egalite') { $casc[] = 'égalité conservée'; break; }
        $cr = selec_critere($ctx, $dp, $stepId);
        if ($cr) $casc[] = $cr['label'];
    }
    if ($casc) {
        if (!$pdf->SamePage(8)) $pdf->AddPage();
        $pdf->SetFont($pdf->FontStd, 'I', 6.5);
        $pdf->MultiCell($LARGEUR, 3.5, 'Départage appliqué, dans l\'ordre : ' . implode(' > ', $casc)
            . '. Deux archers que cette cascade ne sépare pas gardent le même rang et les mêmes points.',
            0, 'L', 0);
    }

    // Les cases vides ne sont pas un oubli : elles disent qu'il n'y a pas eu de
    // tir. La feuille l'écrit, sinon un lecteur y verra une donnée manquante.
    if ($nbFiges && $coupure) {
        $cascCut = array();
        foreach ((array) ($coupure['departage'] ?? array()) as $dp) {
            $c = isset($dp['c']) ? $dp['c'] : '';
            if ($c === 'egalite') break;
            $cr = selec_critere($ctx, $dp, $coupure['id']);
            if ($cr) $cascCut[] = $cr['label'];
        }
        $src = implode('+', (array) ($coupure['sources'] ?? array()));
        if (!$pdf->SamePage(8)) $pdf->AddPage();
        $pdf->SetFont($pdf->FontStd, 'I', 6.5);
        $pdf->MultiCell($LARGEUR, 3.5,
            $nbFiges . ' archer(s) n\'ont pas pris part à la suite de l\'épreuve : leur classement '
            . 'est celui établi à l\'issue de ' . $src . ', départage compris'
            . ($cascCut ? ' (' . implode(' > ', $cascCut) . ')' : '')
            . '. Les colonnes des étapes qu\'ils n\'ont pas disputées restent vides — il n\'y a pas '
            . 'eu de tir, ce n\'est pas un résultat nul.',
            0, 'L', 0);
    }
    // Les alertes restent à l'ÉCRAN et n'entrent jamais dans le PDF : décision
    // explicite de l'utilisateur. Une feuille de classement circule, s'affiche,
    // se photographie — elle doit porter le résultat, pas les notes de travail de
    // l'organisateur. C'est sur classement.php qu'on vérifie avant d'imprimer.
}

$nom = 'SELEC-' . preg_replace('/[^A-Za-z0-9_-]/', '', $stepId)
     . ($un !== '' ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $un) : '') . '.pdf';
$pdf->Output($nom, 'I');
