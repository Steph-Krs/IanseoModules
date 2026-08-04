<?php
/**
 * Calibrage du modèle sur les compétitions déjà en base.
 *
 * Le modèle traite les flèches d'un archer comme indépendantes, ce qui sous-estime
 * beaucoup sa variabilité réelle (forme du jour, vent, nerfs sont corrélés d'une
 * flèche à l'autre). Résultat brut : des probabilités bien trop tranchées.
 *
 * On corrige par une température unique, ajustée par maximum de vraisemblance sur
 * les matchs réellement joués : p' = p^t / (p^t + (1-p)^t).
 */
require_once __DIR__ . '/markets.php';

/**
 * Échantillons (probabilité modèle, issue réelle) tirés des matchs terminés.
 * La probabilité est calculée d'avant-match — exactement la situation où un pronostic
 * est pris — à partir des seules qualifications.
 */
function prono_calibration_samples(?array $tids = null, int $maxTournaments = 40): array
{
    if ($tids === null) {
        $tids = array_map('intval', array_column(prono_all(
            'SELECT DISTINCT FinTournament FROM Finals ORDER BY FinTournament DESC LIMIT ?',
            [$maxTournaments]
        ), 'FinTournament'));
    }

    $samples = [];
    foreach ($tids as $tid) {
        $tour = prono_tournament($tid);
        if (!$tour) continue;

        foreach (prono_events($tid) as $ev) {
            // Calibrage sur les duels individuels seulement : le format par équipes
            // diffère (4 sets, premier à 5) et la base ne contient pas encore assez
            // de matchs par équipes joués pour en tirer quoi que ce soit.
            if ($ev['team']) continue;

            $code    = $ev['code'];
            $archers = prono_archers($tid, $code, $tour);
            if (count($archers) < 4) continue;

            $shot = 0;
            foreach ($archers as $a) $shot += $a['shot'];
            if ($shot < 200) continue;                       // pas de flèches → rien à modéliser

            $prior = prono_field_prior($archers, $tour['maxArrow'] ?? 10);
            $fmt   = prono_format($ev, $archers);
            $ctx   = ['tid' => $tid, 'ev' => $ev, 'tour' => $tour, 'archers' => $archers,
                      'prior' => $prior, 'fmt' => $fmt, 'temp' => 1.0,
                      'lockOnStart' => true, 'allowed' => []];
            $prof  = prono_profiles($archers, $prior, $fmt);
            $slots = prono_slots($tid, $code);

            foreach ($slots as $n => $sa) {
                if ($n % 2 !== 0) continue;
                $sb = $slots[$n + 1] ?? null;
                if (!$sb) continue;

                $a = $sa['athlete'];
                $b = $sb['athlete'];
                if ($a <= 0 || $b <= 0) continue;
                if (!isset($prof[$a]) || !isset($prof[$b])) continue;
                if ($sa['irm'] || $sb['irm']) continue;
                if (!$sa['win'] && !$sb['win']) continue;     // match non joué

                // Un archer sans flèche de qualification n'apporte aucune information.
                if ($archers[$a]['shot'] < 30 || $archers[$b]['shot'] < 30) continue;

                $p = prono_duel($ctx, $prof, $a, $b, null);
                $samples[] = [$p, $sa['win'] ? 1 : 0];
            }
        }
    }
    return $samples;
}

function prono_loglik(array $samples, float $t): float
{
    $ll = 0.0;
    foreach ($samples as [$p, $y]) {
        $q = prono_temper($p, $t);
        $q = min(max($q, 1e-6), 1 - 1e-6);
        $ll += $y ? log($q) : log(1 - $q);
    }
    return $ll;
}

function prono_brier(array $samples, float $t): float
{
    $s = 0.0;
    foreach ($samples as [$p, $y]) {
        $q = prono_temper($p, $t);
        $s += ($q - $y) * ($q - $y);
    }
    return $samples ? $s / count($samples) : 0.0;
}

/** Recherche de la température par section dorée sur [0.05, 3]. */
function prono_fit_temperature(array $samples): array
{
    if (count($samples) < 30) {
        return ['t' => 1.0, 'n' => count($samples), 'error' => 'échantillon insuffisant'];
    }

    $lo = 0.05; $hi = 3.0;
    $phi = (sqrt(5) - 1) / 2;
    $x1 = $hi - $phi * ($hi - $lo);
    $x2 = $lo + $phi * ($hi - $lo);
    $f1 = prono_loglik($samples, $x1);
    $f2 = prono_loglik($samples, $x2);

    for ($i = 0; $i < 60 && ($hi - $lo) > 1e-4; $i++) {
        if ($f1 < $f2) {
            $lo = $x1; $x1 = $x2; $f1 = $f2;
            $x2 = $lo + $phi * ($hi - $lo);
            $f2 = prono_loglik($samples, $x2);
        } else {
            $hi = $x2; $x2 = $x1; $f2 = $f1;
            $x1 = $hi - $phi * ($hi - $lo);
            $f1 = prono_loglik($samples, $x1);
        }
    }
    $t = round(($lo + $hi) / 2, 3);

    // Diagramme de fiabilité : prédit vs observé, après correction
    $bins = [];
    foreach ($samples as [$p, $y]) {
        $q = prono_temper($p, $t);
        $k = min(9, (int) floor($q * 10));
        if (!isset($bins[$k])) $bins[$k] = ['n' => 0, 'pred' => 0.0, 'obs' => 0];
        $bins[$k]['n']++;
        $bins[$k]['pred'] += $q;
        $bins[$k]['obs']  += $y;
    }
    ksort($bins);
    foreach ($bins as $k => &$b) {
        $b['pred'] = $b['pred'] / $b['n'];
        $b['obs']  = $b['obs'] / $b['n'];
    }
    unset($b);

    return [
        't'          => $t,
        'n'          => count($samples),
        'll_before'  => round(prono_loglik($samples, 1.0), 1),
        'll_after'   => round(prono_loglik($samples, $t), 1),
        'brier_before' => round(prono_brier($samples, 1.0), 4),
        'brier_after'  => round(prono_brier($samples, $t), 4),
        'bins'       => $bins,
    ];
}

function prono_save_temperature(float $t, array $meta = []): void
{
    file_put_contents(
        prono_root() . '/data/model.local.json',
        json_encode(['temperature' => $t, 'fitted' => date('c')] + $meta,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}
