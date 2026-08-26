<?php
/**
 * lib/classement.php — comparaison exacte, cascades de départage, barèmes.
 *
 * C'est le cœur « juste » du module : sur une sélection en équipe de France,
 * une égalité déclarée à tort (ou ratée) change le classement final. Deux
 * principes non négociables :
 *
 *  1. AUCUNE comparaison en virgule flottante. Une moyenne de set ou une valeur
 *     de flèche est une FRACTION d'entiers (total / nombre) : on compare par
 *     produit croisé, jamais après division. 706/75 et 1412/150 sont égaux ;
 *     leurs quotients flottants peuvent ne pas l'être.
 *  2. Toute égalité résiduelle est CONSERVÉE et visible. Le moteur ne départage
 *     jamais par un critère qui n'est pas dans la cascade configurée (ordre
 *     alphabétique, EnId, ordre de lecture SQL…).
 *
 * Rangs : classement sportif standard (1, 2, 2, 4) — deux ex aequo au 2e rang
 * reçoivent tous deux les points du 2e, le suivant ceux du 4e. C'est la règle
 * FFTA (« les archers à égalité obtiendraient le même nombre de points ») et le
 * comportement du tableur DTN (RANK + LOOKUP).
 */

// ─────────────────────────────────────────────────────────────────────────────
// Valeurs comparables
// ─────────────────────────────────────────────────────────────────────────────

/** Valeur entière comparable (plus grand = meilleur). */
function selec_v_int($n)   { return array('t' => 'i', 'n' => intval($n)); }

/** Fraction comparable (plus grand = meilleur). Dénominateur nul → valeur absente. */
function selec_v_frac($num, $den)
{
    $den = intval($den);
    if ($den <= 0) return null;
    return array('t' => 'f', 'n' => intval($num), 'd' => $den);
}

/** Vecteur d'entiers comparé lexicographiquement (score, puis X, puis 10…). */
function selec_v_vec($vals)
{
    $v = array();
    foreach ((array) $vals as $x) $v[] = intval($x);
    return array('t' => 'v', 'v' => $v);
}

/**
 * Compare deux valeurs comparables. Retourne > 0 si $a est meilleur que $b,
 * < 0 si moins bon, 0 si indiscernables (ou si l'une des deux est absente).
 */
function selec_cmp($a, $b)
{
    if ($a === null || $b === null) return 0;
    if ($a['t'] !== $b['t']) return 0; // types hétérogènes : on ne tranche pas

    if ($a['t'] === 'i') {
        return $a['n'] <=> $b['n'];
    }
    if ($a['t'] === 'f') {
        // Produit croisé, dénominateurs strictement positifs par construction.
        return ($a['n'] * $b['d']) <=> ($b['n'] * $a['d']);
    }
    if ($a['t'] === 'v') {
        $n = max(count($a['v']), count($b['v']));
        for ($i = 0; $i < $n; $i++) {
            $x = isset($a['v'][$i]) ? $a['v'][$i] : 0;
            $y = isset($b['v'][$i]) ? $b['v'][$i] : 0;
            if ($x !== $y) return $x <=> $y;
        }
        return 0;
    }
    return 0;
}

/** Rend une valeur comparable affichable (float pour les fractions). */
function selec_v_float($v)
{
    if ($v === null) return null;
    if ($v['t'] === 'i') return (float) $v['n'];
    if ($v['t'] === 'f') return $v['d'] ? $v['n'] / $v['d'] : null;
    if ($v['t'] === 'v') return isset($v['v'][0]) ? (float) $v['v'][0] : null;
    return null;
}

/** Numérateur/dénominateur d'une valeur comparable, pour stockage exact. */
function selec_v_frac_parts($v)
{
    if ($v === null) return array(0, 1);
    if ($v['t'] === 'i') return array($v['n'], 1);
    if ($v['t'] === 'f') return array($v['n'], $v['d']);
    if ($v['t'] === 'v') return array(isset($v['v'][0]) ? $v['v'][0] : 0, 1);
    return array(0, 1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Classement avec cascade de départage
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Classe des archers.
 *
 * @param array $ids       identifiants à classer
 * @param array $criteres  cascade ordonnée. Chaque entrée :
 *                         ['id'=>'x', 'label'=>'Nombre de X', 'fn'=>function($id){…}]
 *                         Le premier critère est la valeur PRINCIPALE du
 *                         classement ; les suivants ne servent qu'à départager.
 *                         Un critère d'id 'egalite' arrête la cascade : tout ce
 *                         qui reste à égalité le demeure.
 *
 * @return array liste ordonnée : ['id','rang','exaequo','tie','valeurs'=>[cid=>v]]
 *         `tie` ne nomme un critère que lorsqu'un départage a été NÉCESSAIRE,
 *         c'est-à-dire pour les archers à égalité sur le critère principal.
 *         Vide partout ailleurs : dans un classement au score, « départagé au
 *         score » n'apprend rien. 'ex aequo' si aucun critère n'a séparé.
 */
function selec_ranger($ids, $criteres)
{
    $ids = array_values(array_map('intval', (array) $ids));
    if (!$ids) return array();

    // Évaluation de tous les critères pour tous les archers (une seule fois).
    $vals = array();
    foreach ($ids as $id) {
        $vals[$id] = array();
        foreach ($criteres as $c) {
            if ($c['id'] === 'egalite') { $vals[$id]['egalite'] = null; continue; }
            $vals[$id][$c['id']] = call_user_func($c['fn'], $id);
        }
    }

    // Tri : cascade jusqu'au critère 'egalite' (exclu) ou jusqu'au bout.
    $ordre = array();
    foreach ($criteres as $c) {
        if ($c['id'] === 'egalite') break;
        $ordre[] = $c['id'];
    }

    usort($ids, function ($a, $b) use ($vals, $ordre) {
        foreach ($ordre as $cid) {
            $r = selec_cmp($vals[$a][$cid], $vals[$b][$cid]);
            if ($r !== 0) return -$r; // décroissant : meilleur en premier
        }
        // Deux archers réellement indiscernables : on les range par EnId pour que
        // l'ordre d'AFFICHAGE soit stable d'un recalcul à l'autre (deux ex aequo
        // qui permutent d'un rafraîchissement à l'autre passeraient pour un bug).
        // Sans incidence sur le rang : celui-ci se déduit des VALEURS, pas de la
        // position — et le drapeau `exaequo` reste posé.
        return $a <=> $b;
    });

    // Attribution des rangs (classement sportif : 1, 2, 2, 4).
    $out = array();
    $rang = 0;
    $precId = null;
    foreach ($ids as $i => $id) {
        $exaequo = false;
        if ($precId === null) {
            $rang = 1;
        } elseif (selec_separateur($vals, $precId, $id, $ordre) === null) {
            $exaequo = true;   // rang inchangé
        } else {
            $rang = $i + 1;
        }
        $out[] = array(
            'id'      => $id,
            'rang'    => $rang,
            'exaequo' => $exaequo,
            'tie'     => '',
            'valeurs' => $vals[$id],
        );
        $precId = $id;
    }

    // Marque aussi comme ex aequo le PREMIER d'un groupe d'égalité (sinon seul
    // le second des deux porterait le drapeau).
    $n = count($out);
    for ($i = 0; $i < $n - 1; $i++) {
        if (!empty($out[$i + 1]['exaequo'])) $out[$i]['exaequo'] = true;
    }

    // ── Départage affiché ───────────────────────────────────────────────────
    // Le critère PRINCIPAL est le principe même du classement : dire d'un archer
    // qu'il a été « départagé au score » dans un classement au score n'apprend
    // rien et noie l'information utile. On n'annote donc QUE les archers à
    // égalité sur ce critère — ceux pour qui un départage a réellement été
    // nécessaire — et on annote TOUT le groupe, premier compris : c'est bien lui
    // qui doit sa place au départage, pas seulement ceux qui le suivent.
    $principal = $ordre ? $ordre[0] : null;
    if ($principal !== null) {
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && selec_cmp($vals[$out[$j]['id']][$principal],
                                            $vals[$out[$j + 1]['id']][$principal]) === 0) {
                $j++;
            }
            for ($k = $i; $j > $i && $k <= $j; $k++) {
                $sep = ($k > $i)
                    ? selec_separateur($vals, $out[$k - 1]['id'], $out[$k]['id'], $ordre)
                    : null;
                // Premier du groupe, ou indiscernable de son prédécesseur : c'est
                // le critère qui le sépare du SUIVANT qui explique sa place.
                if ($sep === null && $k < $j) {
                    $sep = selec_separateur($vals, $out[$k]['id'], $out[$k + 1]['id'], $ordre);
                }
                $out[$k]['tie'] = $sep === null ? 'ex aequo' : $sep;
            }
            $i = $j + 1;
        }
    }

    return $out;
}

/**
 * Premier critère de la cascade qui sépare deux archers, ou null s'ils sont
 * indiscernables. Isolé parce que le rang ET le départage affiché s'en servent,
 * et qu'ils doivent répondre exactement pareil.
 */
function selec_separateur($vals, $a, $b, $ordre)
{
    foreach ($ordre as $cid) {
        if (selec_cmp($vals[$a][$cid], $vals[$b][$cid]) !== 0) return $cid;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Barèmes
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Points d'un barème, en CENTIÈMES (entier) — jamais en flottant : un barème
 * de performance vaut 3,5 ou 0,5, et des sommes de flottants comparées entre
 * elles finissent par mentir. Tout le moteur additionne des centièmes.
 *
 * Deux types :
 *  - 'rang'   : points[] indexé par le rang (1er = index 0), 0 au-delà.
 *  - 'valeur' : table associative valeur → points (ex. nombre de victoires en
 *               poule : 5→6, 4→5 … 0→1). Le règlement 2027 attribue les points
 *               de poule DIRECTEMENT par nombre de victoires, pas par rang.
 */
function selec_bareme_points($bareme, $rang, $valeur = null)
{
    if (!is_array($bareme)) return 0;
    $type = isset($bareme['type']) ? $bareme['type'] : 'rang';

    if ($type === 'valeur') {
        $table = isset($bareme['table']) ? $bareme['table'] : array();
        $cle = (string) intval($valeur);
        if (array_key_exists($cle, $table)) return selec_centiemes($table[$cle]);
        return selec_centiemes(isset($bareme['defaut']) ? $bareme['defaut'] : 0);
    }

    $pts = isset($bareme['points']) ? array_values($bareme['points']) : array();
    $r = intval($rang);
    if ($r >= 1 && $r <= count($pts)) return selec_centiemes($pts[$r - 1]);
    return selec_centiemes(isset($bareme['defaut']) ? $bareme['defaut'] : 0);
}

/** Convertit des points « humains » (8, 3.5, 0.5) en centièmes entiers. */
function selec_centiemes($p)
{
    return (int) round(floatval($p) * 100);
}

/** Formate des centièmes pour l'affichage : 350 → « 3,5 », 800 → « 8 ». */
function selec_fmt_points($c)
{
    $c = intval($c);
    $v = $c / 100;
    $s = rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    return $s === '' ? '0' : $s;
}

/**
 * Formate une fraction pour l'affichage (moyenne de volée, valeur de flèche).
 *
 * Quatre décimales par défaut : une moyenne de volée sert de départage, il faut
 * voir à l'écran où l'écart se joue. La comparaison, elle, reste exacte en
 * fraction — ce format n'est QUE de l'affichage et ne décide de rien.
 */
function selec_fmt_frac($num, $den, $dec = 4)
{
    if (!$den) return '—';
    return number_format($num / $den, $dec, ',', ' ');
}
