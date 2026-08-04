<?php
/**
 * Moteur de probabilités du module PRONO.
 *
 * Tout part des flèches réellement tirées en qualification : ianseo stocke chaque
 * flèche sur un caractère dans QuD<n>Arrowstring (table $LetterPoint de
 * Common/Lib/ArrTargets.inc.php). On en tire une loi discrète par flèche et par
 * archer, régularisée vers la loi du plateau — ce qui évite des cotes absurdes
 * après six flèches tout en laissant l'écart de niveau s'exprimer.
 *
 * De cette loi découlent, par convolution exacte (jamais de Monte-Carlo) :
 *   - la loi du total d'une volée  → duel par sets (chaîne de Markov)
 *   - la loi du total d'un match   → duel en cumul (arc à poulies)
 *   - la loi du score final        → classement de qualification
 *
 * Aucune dépendance à ianseo : ce fichier est utilisable depuis le vhost public.
 */

const PRONO_MAXV = 12;   // valeur max d'une flèche (10 en extérieur, 11/12 ailleurs)

/** Valeur numérique de chaque lettre — extrait de $LetterPoint (colonne "N"). */
function prono_letter_values(): array
{
    static $v = null;
    if ($v !== null) return $v;
    return $v = [
        'A' => 0,  'B' => 1,  'C' => 2,  'D' => 3,  'E' => 4,  'F' => 5,
        'G' => 6,  'H' => 7,  'I' => 8,  'J' => 9,  'K' => 10, 'L' => 10,
        'M' => 11, 'N' => 12, 'O' => 1,  'P' => 0,  'Q' => 15, 'R' => 5,
        'V' => 20, 'W' => 16, 'X' => 11, 'Y' => 6,  'Z' => 5,
        '(' => 0,  ')' => 5,  '[' => 8,  ']' => 10,
    ];
}

/**
 * Compte les flèches d'une ou plusieurs arrowstrings dans un histogramme 0..PRONO_MAXV.
 * Les minuscules sont des points « douteux » de même valeur → strtoupper.
 */
function prono_arrow_counts(array $strings, array $counts = []): array
{
    if (!$counts) $counts = array_fill(0, PRONO_MAXV + 1, 0);
    $map = prono_letter_values();
    foreach ($strings as $s) {
        $s = strtoupper((string) $s);
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if (!isset($map[$c])) continue;          // ' ' = flèche pas encore tirée
            $val = $map[$c];
            if ($val <= PRONO_MAXV) $counts[$val]++;
        }
    }
    return $counts;
}

function prono_counts_total(array $counts): int
{
    return (int) array_sum($counts);
}

function prono_pmf_mean(array $p): float
{
    $m = 0.0;
    foreach ($p as $v => $q) $m += $v * $q;
    return $m;
}

function prono_pmf_sd(array $p): float
{
    $m = prono_pmf_mean($p);
    $v = 0.0;
    foreach ($p as $x => $q) $v += $q * ($x - $m) * ($x - $m);
    return sqrt(max(0.0, $v));
}

/**
 * Décale une loi discrète de $offset flèches (positif ou négatif, continu — pas
 * seulement un nombre entier de points), en écrêtant à [0, $maxV]. Sert à construire
 * un profil de départ personnalisé (ex. à partir d'un classement national) sans
 * changer la forme de la loi, seulement sa moyenne.
 *
 * $maxV DOIT être la valeur max d'une flèche de CETTE compétition (6 en campagne, 10
 * en extérieur...), pas la constante globale PRONO_MAXV (12, le max toutes disciplines
 * confondues) : sinon un décalage positif peut faire apparaître des valeurs de flèche
 * physiquement impossibles pour le format en cours (vu en campagne : un profil poussé
 * jusqu'à 8-9 points par flèche sur un barème qui plafonne à 6, projetant un score
 * final au-delà du maximum réellement atteignable).
 *
 * $offset non entier : interpolation linéaire entre le décalage entier inférieur et
 * supérieur, pas un simple arrondi — un classement national à peine plus favorable
 * doit produire une loi à peine différente, pas un saut brutal d'une flèche entière au
 * suivant (constaté : deux archers voisins au classement national, à score très
 * proche, pouvaient se retrouver dans deux paliers de décalage différents et afficher
 * des cotes de tiercé aussi éloignées que deux archers très écartés au classement).
 */
function prono_shift_pmf(array $p, float $offset, int $maxV = PRONO_MAXV): array
{
    if (abs($offset) < 1e-9) return $p;

    $shiftInt = function (array $p, int $o) use ($maxV): array {
        $out = array_fill(0, $maxV + 1, 0.0);
        foreach ($p as $v => $q) {
            if ($q <= 0) continue;
            $nv = max(0, min($maxV, $v + $o));
            $out[$nv] += $q;
        }
        return $out;
    };

    $lo   = (int) floor($offset);
    $hi   = $lo + 1;
    $frac = $offset - $lo;
    $a    = $shiftInt($p, $lo);
    if ($frac < 1e-9) return prono_pmf_norm($a);

    $b   = $shiftInt($p, $hi);
    $out = [];
    foreach ($a as $v => $qa) $out[$v] = (1 - $frac) * $qa + $frac * ($b[$v] ?? 0.0);
    return prono_pmf_norm($out);
}

/**
 * Loi par flèche, régularisée vers $prior (loi du plateau) avec un poids exprimé
 * en nombre de flèches équivalentes : un archer n'ayant tiré que $w flèches est
 * à mi-chemin entre son propre profil et celui du plateau.
 */
function prono_pmf(array $counts, array $prior, float $w = 24.0): array
{
    $n   = prono_counts_total($counts);
    $den = $n + $w;
    if ($den <= 0) return $prior;

    $pmf = [];
    for ($v = 0; $v <= PRONO_MAXV; $v++) {
        $pmf[$v] = ($counts[$v] + $w * ($prior[$v] ?? 0)) / $den;
    }
    return prono_pmf_norm($pmf);
}

function prono_pmf_norm(array $p): array
{
    $s = array_sum($p);
    if ($s <= 0) return $p;
    foreach ($p as $k => $v) $p[$k] = $v / $s;
    return $p;
}

/**
 * $maxArrow : valeur maximale d'une flèche pour CETTE compétition (10 en extérieur/
 * salle, 6 en campagne...). Ne sert qu'au repli plateau vide ci-dessous — dès qu'une
 * seule flèche existe quelque part sur le plateau, la loi vient des flèches réelles
 * et $maxArrow n'intervient plus. Défaut 10 : valeur historique, correcte pour tous
 * les formats sauf la campagne (voir paris_tournament()/paris_field_prior()).
 */
function prono_counts_to_pmf(array $counts, int $maxArrow = 10): array
{
    $n = prono_counts_total($counts);
    if ($n <= 0) {
        // Plateau vide : loi neutre centrée à ~90 % de la valeur max d'une flèche
        // (9/10 en extérieur, 5/6 en campagne), le temps que les premières volées
        // tombent et prennent le relais.
        $p      = array_fill(0, PRONO_MAXV + 1, 0.0);
        $center = max(1, min(PRONO_MAXV - 1, (int) round($maxArrow * 0.9)));
        $p[$center - 1] = 0.25; $p[$center] = 0.4; $p[$center + 1] = 0.35;
        return $p;
    }
    $p = [];
    for ($v = 0; $v <= PRONO_MAXV; $v++) $p[$v] = $counts[$v] / $n;
    return $p;
}

/** Convolution de deux lois discrètes (index = valeur). */
function prono_conv(array $a, array $b): array
{
    $out = array_fill(0, (count($a) - 1) + (count($b) - 1) + 1, 0.0);
    foreach ($a as $i => $pa) {
        if ($pa <= 0) continue;
        foreach ($b as $j => $pb) {
            if ($pb <= 0) continue;
            $out[$i + $j] += $pa * $pb;
        }
    }
    return $out;
}

/** Loi de la somme de $n tirages i.i.d. — exponentiation rapide. */
function prono_conv_pow(array $p, int $n): array
{
    if ($n <= 0) return [1.0];
    $result = null;
    $base   = $p;
    while ($n > 0) {
        if ($n & 1) $result = $result === null ? $base : prono_conv($result, $base);
        $n >>= 1;
        if ($n > 0) $base = prono_conv($base, $base);
    }
    return $result;
}

/**
 * P(A + $offset > B), P(=), P(<) pour deux lois discrètes indépendantes.
 */
function prono_compare(array $a, array $b, int $offset = 0): array
{
    $nb  = count($b);
    $cum = array_fill(0, $nb + 1, 0.0);         // cum[k] = P(B < k)
    for ($k = 0; $k < $nb; $k++) $cum[$k + 1] = $cum[$k] + $b[$k];

    $win = $tie = 0.0;
    foreach ($a as $i => $pa) {
        if ($pa <= 0) continue;
        $x = $i + $offset;
        if ($x < 0)   continue;                  // A sous toute valeur possible de B
        $lt  = $x >= $nb ? $cum[$nb] : $cum[$x];
        $eq  = ($x >= 0 && $x < $nb) ? $b[$x] : 0.0;
        $win += $pa * $lt;
        $tie += $pa * $eq;
    }
    return [$win, $tie, max(0.0, 1.0 - $win - $tie)];
}

/**
 * Duel par sets (arc classique) — chaîne de Markov sur l'état (points de set).
 * 2 points au vainqueur du set, 1 partout en cas d'égalité, premier à $target.
 * À $maxSets sets sans vainqueur (5-5), tir de barrage.
 *
 * $ptsA / $ptsB / $setsPlayed permettent de repartir de l'état courant d'un match
 * en direct : la cote se recalcule volée après volée.
 *
 * @return array{win: float, scores: array<string, float>}
 */
function prono_match_sets(
    array $endA, array $endB,
    int $ptsA = 0, int $ptsB = 0, int $setsPlayed = 0,
    ?float $soWin = null, int $target = 6, int $maxSets = 5
): array {
    [$w, $t, $l] = prono_compare($endA, $endB);
    if ($soWin === null) $soWin = 0.5;

    $states = [$ptsA . '|' . $ptsB => 1.0];
    $scores = [];
    $win    = 0.0;

    for ($s = $setsPlayed; ; $s++) {
        $next = [];
        foreach ($states as $key => $p) {
            [$a, $b] = array_map('intval', explode('|', $key));

            if ($a >= $target || $b >= $target) {
                $k = $a . '-' . $b;
                $scores[$k] = ($scores[$k] ?? 0) + $p;
                if ($a > $b) $win += $p;
                continue;
            }
            if ($s >= $maxSets) {                       // 5-5 → barrage à une flèche
                $ka = ($a + 1) . '-' . $b;
                $kb = $a . '-' . ($b + 1);
                $scores[$ka] = ($scores[$ka] ?? 0) + $p * $soWin;
                $scores[$kb] = ($scores[$kb] ?? 0) + $p * (1 - $soWin);
                $win += $p * $soWin;
                continue;
            }

            $k1 = ($a + 2) . '|' . $b;
            $k2 = ($a + 1) . '|' . ($b + 1);
            $k3 = $a . '|' . ($b + 2);
            $next[$k1] = ($next[$k1] ?? 0) + $p * $w;
            $next[$k2] = ($next[$k2] ?? 0) + $p * $t;
            $next[$k3] = ($next[$k3] ?? 0) + $p * $l;
        }
        if (!$next) break;
        $states = $next;
    }

    arsort($scores);
    return ['win' => $win, 'scores' => $scores];
}

/**
 * Duel en cumul (arc à poulies) : le total des flèches restantes s'ajoute au score
 * acquis, égalité départagée au barrage.
 */
function prono_match_cumul(
    array $arrowA, array $arrowB,
    int $arrowsLeft, int $scoreA = 0, int $scoreB = 0, ?float $soWin = null
): array {
    if ($soWin === null) $soWin = 0.5;
    if ($arrowsLeft <= 0) {
        $win = $scoreA > $scoreB ? 1.0 : ($scoreA < $scoreB ? 0.0 : $soWin);
        return ['win' => $win, 'scores' => []];
    }
    $ra = prono_conv_pow($arrowA, $arrowsLeft);
    $rb = prono_conv_pow($arrowB, $arrowsLeft);
    [$w, $t] = prono_compare($ra, $rb, $scoreA - $scoreB);
    return ['win' => $w + $t * $soWin, 'scores' => []];
}

/** Probabilité que A gagne un barrage à une flèche (égalité de valeur = pile ou face). */
function prono_shootoff(array $arrowA, array $arrowB): float
{
    [$w, $t] = prono_compare($arrowA, $arrowB);
    return $w + $t / 2;
}

/**
 * Recalibrage de la confiance du modèle : p' = p^t / (p^t + (1-p)^t).
 * t < 1 rapproche de 50/50 (modèle trop sûr de lui), t > 1 écarte.
 * Valeur mesurée par admin/calibrate.php sur les compétitions déjà en base.
 */
function prono_temper(float $p, float $t): float
{
    if ($t == 1.0) return $p;
    $p = min(max($p, 1e-9), 1 - 1e-9);
    $a = pow($p, $t);
    $b = pow(1 - $p, $t);
    return $a / ($a + $b);
}

// ─── Loi normale (pas d'ext-stats en standard sous XAMPP) ────────────────────

function prono_norm_cdf(float $x): float
{
    // erf par Abramowitz & Stegun 7.1.26 (erreur < 1,5e-7, largement suffisant ici)
    $s = $x < 0 ? -1 : 1;
    $z = abs($x) / M_SQRT2;
    $t = 1 / (1 + 0.3275911 * $z);
    $poly = ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t;
    $erf  = 1 - $poly * exp(-$z * $z);
    return 0.5 * (1 + $s * $erf);
}

// ─── Classement de qualification ─────────────────────────────────────────────

/**
 * Probabilités de rang final en qualification.
 *
 * $archers : [id => ['score'=>int, 'left'=>int, 'mu'=>float, 'sd'=>float]]
 *   left = flèches restantes ; 0 = score définitif (masse ponctuelle).
 * $targets : rangs à évaluer, ex. [1, 8] → P(1er) et P(top 8).
 * $only    : ids à évaluer (les autres ne servent que d'adversaires) — borne le coût.
 *
 * Intégration sur grille + Poisson-binomiale tronquée : exact à la discrétisation
 * près, et sans simulation.
 *
 * @return array<int, array<int, float>>  [id => [rang => probabilité]]
 */
function prono_qual_ranks(array $archers, array $targets = [1, 8], ?array $only = null, int $grid = 90): array
{
    if (!$archers) return [];
    $maxK = max($targets);

    $dist = [];
    $lo = INF; $hi = -INF;
    foreach ($archers as $id => $a) {
        $left = max(0, (int) $a['left']);
        $mean = $a['score'] + $left * $a['mu'];
        $sd   = $left > 0 ? sqrt($left) * max(0.05, $a['sd']) : 0.0;
        $dist[$id] = ['m' => $mean, 's' => $sd];
        $lo = min($lo, $mean - 4 * $sd - 1);
        $hi = max($hi, $mean + 4 * $sd + 1);
    }
    if (!is_finite($lo) || $hi <= $lo) return [];

    $ids  = $only === null ? array_keys($archers) : array_values(array_intersect(array_keys($archers), $only));
    $step = ($hi - $lo) / $grid;
    $out  = [];

    foreach ($ids as $id) {
        $me   = $dist[$id];
        $acc  = array_fill_keys($targets, 0.0);

        for ($g = 0; $g < $grid; $g++) {
            $x = $lo + ($g + 0.5) * $step;

            // densité de l'archer évalué au point x (masse ponctuelle si terminé)
            if ($me['s'] > 0) {
                $z  = ($x - $me['m']) / $me['s'];
                $fx = exp(-0.5 * $z * $z) / ($me['s'] * sqrt(2 * M_PI)) * $step;
            } else {
                $fx = ($x - $step / 2 <= $me['m'] && $me['m'] < $x + $step / 2) ? 1.0 : 0.0;
            }
            if ($fx <= 1e-12) continue;

            // loi du nombre d'adversaires qui dépassent x (tronquée à maxK)
            $dp = array_fill(0, $maxK + 1, 0.0);
            $dp[0] = 1.0;
            foreach ($dist as $oid => $o) {
                if ($oid == $id) continue;
                $p = $o['s'] > 0 ? 1 - prono_norm_cdf(($x - $o['m']) / $o['s']) : ($o['m'] > $x ? 1.0 : 0.0);
                if ($p <= 0) continue;
                for ($k = $maxK; $k >= 1; $k--) $dp[$k] = $dp[$k] * (1 - $p) + $dp[$k - 1] * $p;
                $dp[0] *= (1 - $p);
            }

            // dp[k] = P(exactement k adversaires devant) → mon rang vaut k+1
            $cum = 0.0;
            for ($k = 0; $k <= $maxK; $k++) {
                $cum += $dp[$k];
                if (isset($acc[$k + 1])) $acc[$k + 1] += $fx * $cum;
            }
        }

        $res = [];
        foreach ($targets as $K) $res[$K] = min(1.0, max(0.0, $acc[$K]));
        $out[$id] = $res;
    }
    return $out;
}

// ─── Tiercé de qualification (formule de Harville) ───────────────────────────
//
// Un tiercé (« comme aux courses hippiques ») se joue sur les seules probabilités
// de victoire : Harville en déduit, sans nouvelle intégration sur grille, qui a des
// chances d'arriver 2e ou 3e, et la probabilité d'un ordre précis pour 3 noms donnés.
// C'est le modèle standard des paris hippiques à ordre (tiercé/trifecta) quand seules
// les cotes de victoire sont connues — pas une simulation, une formule fermée.

/**
 * P(rang 1), P(rang 2), P(rang 3) pour chaque participant, à partir des seules
 * probabilités de victoire (rang 1). Coût en O(n^3) sur le rang 3 : sans objet en
 * pratique pour un champ de qualification ianseo réel (quelques dizaines à une
 * centaine d'archers) — $winProb porte tout le champ, voir $tierceFocus dans
 * prono_build_quals (borné défensivement à PRONO_TIERCE_MAX_FIELD, jamais atteint).
 *
 * @param array<int,float> $winProb [id => P(finit 1er)] — pas nécessaire de sommer
 *        exactement à 1, mais doit rester une probabilité par personne (<1).
 * @return array<int,array<int,float>> [id => [1=>P, 2=>P, 3=>P]]
 */
function prono_harville_ranks(array $winProb): array
{
    $ids = array_keys($winProb);
    $out = [];
    foreach ($ids as $i) $out[$i] = [1 => min(0.999999, max(0.0, $winProb[$i])), 2 => 0.0, 3 => 0.0];

    foreach ($ids as $i) {
        $p2 = 0.0;
        foreach ($ids as $j) {
            if ($j === $i) continue;
            $dj = 1 - $winProb[$j];
            if ($dj > 1e-9) $p2 += $winProb[$j] * $winProb[$i] / $dj;
        }
        $out[$i][2] = $p2;
    }

    foreach ($ids as $i) {
        $p3 = 0.0;
        foreach ($ids as $j) {
            if ($j === $i) continue;
            $dj = 1 - $winProb[$j];
            if ($dj <= 1e-9) continue;
            foreach ($ids as $k) {
                if ($k === $i || $k === $j) continue;
                $dk = $dj - $winProb[$k];
                if ($dk > 1e-9) $p3 += $winProb[$j] * ($winProb[$k] / $dj) * ($winProb[$i] / $dk);
            }
        }
        $out[$i][3] = $p3;
    }
    return $out;
}

/**
 * Probabilité d'un tiercé précis (Harville) : $a en 1er, $b en 2e, $c en 3e — puis,
 * en sommant les 6 permutations du même trio, la probabilité que ce soient CES
 * TROIS-LÀ qui occupent le podium, peu importe l'ordre (« tiercé désordre »).
 *
 * @param array<int,float> $winProb [id => P(finit 1er)]
 * @return array{order:float, anyOrder:float}
 */
function prono_harville_triple(array $winProb, int $a, int $b, int $c): array
{
    $p = static fn($x) => $winProb[$x] ?? 0.0;
    $chain = static function (int $x, int $y, int $z) use ($p): float {
        $px = $p($x);
        $dx = 1 - $px;
        if ($dx <= 1e-9) return 0.0;
        $py = $p($y);
        $dy = $dx - $py;
        if ($dy <= 1e-9) return 0.0;
        return $px * ($py / $dx) * ($p($z) / $dy);
    };

    $order = $chain($a, $b, $c);
    $anyOrder = $chain($a, $b, $c) + $chain($a, $c, $b) + $chain($b, $a, $c)
              + $chain($b, $c, $a) + $chain($c, $a, $b) + $chain($c, $b, $a);
    return ['order' => $order, 'anyOrder' => $anyOrder];
}

/**
 * Répartition entre les 3 issues du score de qualification (« - de X1 », « X1-X2 »,
 * « + de X2 » — voir prono_rep_anchor_top1/cut() dans data.php pour X1/X2), à partir
 * du principe énoncé par l'organisateur plutôt que d'une loi normale calée sur la
 * projection interne du modèle.
 *
 * Essayé d'abord avec une loi normale (moyenne/écart-type d'un moteur à grille +
 * Poisson-binomiale accumulant les moments de « qui que ce soit atteint x à ce
 * rang », retiré depuis, devenu mort) :
 * bug réel constaté mi-août 2026, le résultat était beaucoup trop sensible au petit
 * écart entre la moyenne projetée et les seuils — pour des champs pourtant comparables,
 * tantôt 99 % de la masse sur « + de X2 », tantôt 99 % sur « - de X1 ». La moyenne
 * projetée par le modèle (un « rang 1 » = le max de plusieurs archers) tend en plus à
 * être structurellement plus haute que n'importe quelle moyenne individuelle, la
 * rapprochant artificiellement de X2 (déjà le record de la saison).
 *
 * Principe retenu : rester dans l'intervalle déjà réalisé (X1-X2) par un candidat de
 * référence est l'issue normale, donc la plus probable. Au-delà, un record individuel
 * ne demande qu'UN SEUL candidat de référence en réussite ; rester sous X1 demande que
 * TOUS les candidats de référence soient simultanément en méforme — un événement
 * conjoint, donc plus rare qu'un record isolé, même si chacun pris seul serait aussi
 * improbable l'un que l'autre.
 *
 * @param int $n Nombre de candidats de référence (3 pour le podium national ou le
 *              voisinage du cut — voir prono_rep_anchor_top1/cut()).
 * @return array{lo:float, mid:float, hi:float}
 */
function prono_qual_three_way(int $n): array
{
    $n = max(1, $n);
    // Probabilités individuelles, par candidat de référence, un jour de compétition :
    // ~1 fois sur 2 sous son propre plancher de saison (CrS3, déjà un score solide, pas
    // sa pire sortie réelle) ; ~1 fois sur 13 au-dessus du record de la catégorie.
    $pBad    = 0.5;
    $pRecord = 0.075;

    $lo  = $pBad ** $n;
    $hi  = 1 - (1 - $pRecord) ** $n;
    return ['lo' => $lo, 'mid' => max(0.0, 1 - $lo - $hi), 'hi' => $hi];
}

/** P(score final > $threshold) pour un archer en cours de qualification. */
function prono_qual_over(array $a, float $threshold): float
{
    $left = max(0, (int) $a['left']);
    $mean = $a['score'] + $left * $a['mu'];
    if ($left <= 0) return $mean > $threshold ? 1.0 : 0.0;
    $sd = sqrt($left) * max(0.05, $a['sd']);
    return 1 - prono_norm_cdf(($threshold - $mean) / $sd);
}
