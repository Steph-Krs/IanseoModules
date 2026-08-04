<?php
/**
 * Construction des marchés et de leurs probabilités modèle.
 *
 * Cinq types :
 *   MATCH_WINNER  duel d'élimination — issues de groupe 'W' (le vainqueur) et 'S'
 *                 (le score exact, qui désigne déjà le vainqueur). Un seul pronostic
 *                 par duel, d'où l'impossibilité de se contredire.
 *   EVENT_WINNER  vainqueur de l'épreuve (propagation dans le tableau)
 *   QUAL_WINNER   meilleur score de qualification de l'épreuve
 *   QUAL_TOPN     un archer se qualifie-t-il pour le tableau final
 *   QUAL_OU       score final d'un archer au-dessus / en dessous d'un seuil
 *
 * 'SET_SCORE' ne désigne plus un type de marché : c'est le drapeau de configuration
 * qui autorise ou non le groupe 'S' dans les duels.
 *
 * Ce fichier ne produit que des probabilités : la transformation en cotes (marge,
 * mélange avec la masse des pronostics) vit dans engine.php.
 */
require_once __DIR__ . '/data.php';

const PRONO_QUAL_LOCK_ARROWS = 6;   // qualif verrouillée sur la dernière volée
const PRONO_BAND_WIDTH       = 3;   // largeur d'une tranche de total, à l'arc à poulies
// Garde-fou de coût pour le tiercé (Harville rang 3 = O(n^3)) : purement défensif,
// jamais atteint en pratique (le plus grand champ ianseo réel observé compte ~90
// archers sur une même épreuve individuelle).
const PRONO_TIERCE_MAX_FIELD = 150;

/**
 * Construit tous les marchés d'une compétition.
 * @return array liste de descripteurs de marché
 */
function prono_build(int $tid, array $cfg): array
{
    $tour = prono_tournament($tid);
    if (!$tour) return [];

    $allowedEvents  = array_filter(array_map('trim', explode('|', (string) ($cfg['PaCfEvents'] ?? ''))), 'strlen');
    $closedEvents   = array_filter(array_map('trim', explode('|', (string) ($cfg['PaCfClosedEvents'] ?? ''))), 'strlen');
    $closedCells    = array_filter(array_map('trim', explode('|', (string) ($cfg['PaCfClosedCells'] ?? ''))), 'strlen');
    $allowedMarkets = array_filter(array_map('trim', explode('|', (string) ($cfg['PaCfMarkets'] ?? ''))), 'strlen');
    $adultOnly      = !empty($cfg['PaCfAdultOnly']);
    $lockOnStart    = !empty($cfg['PaCfLockOnStart']);
    $temp           = prono_model_temperature();

    $out = [];
    foreach (prono_events($tid) as $key => $ev) {
        // La clé porte le préfixe « T: » pour les épreuves par équipes ; les
        // configurations antérieures ne stockaient que le code, encore accepté.
        if ($allowedEvents && !in_array($key, $allowedEvents, true)) continue;

        $parts = prono_participants($tid, $ev, $tour);
        if (count($parts) < 2) continue;

        if ($adultOnly) {
            $minors = 0;
            foreach ($parts as $a) if (prono_is_minor_class($a['class'])) $minors++;
            if ($minors > count($parts) / 2) continue;   // épreuve de jeunes → écartée
        }

        $prior = prono_field_prior($parts, $tour['maxArrow'] ?? 10);
        $fmt   = prono_format($ev, $parts);
        // Classement national (REPARTITION_EPREUVES) : amorce le profil des archers
        // individuels avant leur première flèche. Sans objet pour les équipes (pas de
        // classement national par équipe rapproché ici).
        $repPriors = $ev['team'] ? [] : prono_rep_priors($tid, $parts, $prior, $tour['maxArrow'] ?? 10);
        $ctx = [
            'tid' => $tid, 'ev' => $ev, 'tour' => $tour, 'archers' => $parts,
            'prior' => $prior, 'repPriors' => $repPriors, 'fmt' => $fmt, 'temp' => $temp,
            'lockOnStart' => $lockOnStart, 'allowed' => $allowedMarkets,
            'band' => max(1, min(50, (int) ($cfg['PaCfBandWidth'] ?? PRONO_BAND_WIDTH))),
        ];

        // Le drapeau « équipes » est posé ici plutôt que dans chaque descripteur :
        // il ne dépend que de l'épreuve.
        $built = array_merge(prono_build_elims($ctx), prono_build_quals($ctx));
        $shut  = in_array($key, $closedEvents, true);
        $team  = $ev['team'] ? 1 : 0;
        foreach ($built as &$m) {
            $m['team'] = $team;
            // Une cellule précise (épreuve × phase × type) peut être fermée à la main
            // depuis la grille, ou par la fermeture rapide (barre flottante, qui ferme
            // la prochaine phase de chaque épreuve selon son horaire prévu). Le duel
            // porte sa vraie phase de tableau dans la clé ; les autres types n'ont
            // qu'une seule cellule par épreuve (phase 0).
            $cellPhase = $m['type'] === 'MATCH_WINNER' ? prono_phase_of_slot((int) $m['key']) : 0;
            $cellClosed = in_array($team . ':' . $ev['code'] . ':' . $m['type'] . ':' . $cellPhase, $closedCells, true);

            // Épreuve fermée à la main (case à cocher globale) : tout ce qui n'est pas
            // déjà réglé se verrouille.
            if ($m['status'] !== 'SETTLED' && ($shut || $cellClosed)) {
                $m['status'] = 'LOCKED';
            }
        }
        unset($m);

        $out = array_merge($out, $built);
    }
    return $out;
}

function prono_market_allowed(array $ctx, string $type): bool
{
    return !$ctx['allowed'] || in_array($type, $ctx['allowed'], true);
}

/**
 * Clé stable d'un archer (numéro de licence), utilisée comme code de sélection et
 * dans les clés de marché. Réimporter la compétition renumérote les EnId : sans
 * cette indirection, tous les pronostics pris avant l'import deviendraient orphelins.
 */
function prono_akey(array $ctx, int $athlete): string
{
    return $ctx['archers'][$athlete]['key'] ?? ('#' . $athlete);
}

/** Température de calibrage mesurée par admin/calibrate.php. */
function prono_model_temperature(): float
{
    $f = prono_root() . '/data/model.local.json';
    if (is_readable($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j) && isset($j['temperature'])) return max(0.2, min(3.0, (float) $j['temperature']));
    }
    return 1.0;
}

/**
 * Format de match, selon que l'épreuve est individuelle ou par équipes.
 *
 * Individuel : 5 volées de 3 flèches, premier à 6 points de set, barrage à 1 flèche.
 * Équipes    : 4 volées de 2 flèches par archer, premier à 5 points de set, barrage
 *              à 1 flèche par archer. Vaut aussi pour le double mixte (2 archers).
 */
function prono_format(array $ev, array $parts): array
{
    if (empty($ev['team'])) {
        return ['target' => 6, 'maxSets' => 5, 'perEnd' => 3, 'ends' => 5, 'size' => 1];
    }
    $size = 3;
    foreach ($parts as $p) {
        if (!empty($p['size'])) { $size = (int) $p['size']; break; }
    }
    return ['target' => 5, 'maxSets' => 4, 'perEnd' => 2 * $size, 'ends' => 4, 'size' => $size];
}

/**
 * Lois par participant : la flèche, la volée, et le barrage.
 *
 * Pour une équipe, la volée n'est pas celle d'un tireur moyen : c'est la somme de
 * 2 flèches de chaque archer. On convolue donc les lois individuelles, ce qui reflète
 * correctement une équipe déséquilibrée (deux pointures et un maillon faible n'ont pas
 * la même dispersion que trois archers moyens de même total).
 */
function prono_profiles(array $parts, array $prior, array $fmt, array $repPriors = []): array
{
    $p = [];
    foreach ($parts as $id => $a) {
        $arrow   = prono_pmf($a['counts'], $repPriors[$id] ?? $prior);
        $members = $a['members'] ?? [];

        // Repli sur le tireur moyen si la composition déclarée ne colle pas au format
        // (équipe incomplète) : mieux vaut une loi approchée qu'une volée du mauvais
        // nombre de flèches, qui fausserait la comparaison.
        if ($members && count($members) * 2 === $fmt['perEnd']) {
            $end = $so = null;
            foreach ($members as $mc) {
                $mp  = prono_pmf($mc, $prior);
                $two = prono_conv($mp, $mp);
                $end = $end === null ? $two : prono_conv($end, $two);
                $so  = $so  === null ? $mp  : prono_conv($so, $mp);
            }
        } else {
            $end = prono_conv_pow($arrow, $fmt['perEnd']);
            $so  = prono_conv_pow($arrow, max(1, $fmt['size']));
        }

        $p[$id] = [
            'arrow' => $arrow,
            'end'   => $end,
            'so'    => $so,
            'mu'    => prono_pmf_mean($arrow),
            'sd'    => prono_pmf_sd($arrow),
        ];
    }
    return $p;
}

/**
 * Tranches de total final pour un duel à l'arc à poulies.
 *
 * On ne propose pas un total exact : sur 150 points la meilleure valeur tourne autour
 * de 3 % de chances, personne ne jouerait. On découpe donc la plage crédible en
 * tranches de largeur fixe, et la probabilité de chaque tranche est la masse exacte
 * de la loi du total — **conditionnée à la victoire**, puisque la tranche désigne le
 * vainqueur autant que son score.
 *
 * @return array<int, array{lo:int, hi:int, p:float}>
 */
function prono_total_bands(array $mine, array $other, int $arrows, int $width = PRONO_BAND_WIDTH): array
{
    $me  = prono_conv_pow($mine, $arrows);
    $opp = prono_conv_pow($other, $arrows);

    // P(je marque x ET je gagne) : l'adversaire finit en dessous, égalité au barrage.
    $cum = 0.0;
    $win = [];
    for ($x = 0; $x < count($opp); $x++) { $win[$x] = $cum; $cum += $opp[$x]; }

    $joint = [];
    $total = 0.0;
    foreach ($me as $x => $p) {
        if ($p <= 0) continue;
        $below = $win[$x] ?? $cum;
        $tie   = $opp[$x] ?? 0.0;
        $joint[$x] = $p * ($below + $tie / 2);
        $total += $joint[$x];
    }
    if ($total <= 0) return [];

    // Plage crédible : on écarte les queues qui ne valent rien à l'affichage.
    $keys = array_keys(array_filter($joint, fn($p) => $p / $total > 0.0005));
    if (!$keys) return [];
    $lo = (int) floor(min($keys) / $width) * $width;
    $hi = (int) max($keys);

    $bands = [];
    for ($a = $lo; $a <= $hi; $a += $width) {
        $p = 0.0;
        for ($x = $a; $x < $a + $width; $x++) $p += $joint[$x] ?? 0.0;
        if ($p / $total > 0.01) $bands[] = ['lo' => $a, 'hi' => $a + $width - 1, 'p' => $p];
    }
    return $bands;
}

// ─── Élimination ─────────────────────────────────────────────────────────────

/**
 * Probabilité que $a batte $b, en tenant compte de l'état réel du match quand il
 * est connu (terminé → certitude ; en cours → reprise de la chaîne à l'état courant).
 */
function prono_duel(array $ctx, array $prof, int $a, int $b, ?array $live = null): float
{
    static $cache = [];
    $key = $a . ':' . $b . ':' . ($live ? $live['a'] . '-' . $live['b'] . '-' . $live['sets'] : '');
    if (isset($cache[$key])) return $cache[$key];

    if (!isset($prof[$a]) || !isset($prof[$b])) return $cache[$key] = 0.5;

    $fmt = $ctx['fmt'];
    $so  = prono_shootoff($prof[$a]['so'], $prof[$b]['so']);

    if ($ctx['ev']['sets']) {
        $r = prono_match_sets(
            $prof[$a]['end'], $prof[$b]['end'],
            $live['a'] ?? 0, $live['b'] ?? 0, $live['sets'] ?? 0, $so,
            $fmt['target'], $fmt['maxSets']
        );
        $p = $r['win'];
    } else {
        $left = $live['left'] ?? ($fmt['perEnd'] * $fmt['ends']);
        $p = prono_match_cumul(
            $prof[$a]['arrow'], $prof[$b]['arrow'],
            $left, $live['sa'] ?? 0, $live['sb'] ?? 0, $so
        )['win'];
    }
    return $cache[$key] = prono_temper($p, $ctx['temp']);
}

function prono_build_elims(array $ctx): array
{
    $tid   = $ctx['tid'];
    $code  = $ctx['ev']['code'];
    $fmt   = $ctx['fmt'];
    $slots = prono_slots($tid, $code, $ctx['ev']['team']);
    if (!$slots) return [];

    $prof = prono_profiles($ctx['archers'], $ctx['prior'], $fmt, $ctx['repPriors'] ?? []);
    $out  = [];

    // Matchs réels : les deux slots portent un archer.
    $matches = [];
    foreach ($slots as $n => $s) {
        if ($n % 2 !== 0) continue;
        $b = $slots[$n + 1] ?? null;
        if (!$b) continue;
        if ($s['athlete'] <= 0 || $b['athlete'] <= 0) continue;
        $matches[$n] = [$s, $b];
    }

    foreach ($matches as $n => [$sa, $sb]) {
        $state = prono_match_state($sa, $sb);
        $ids   = [$sa['athlete'], $sb['athlete']];
        $names = [
            $ctx['archers'][$ids[0]]['name'] ?? ('#' . $ids[0]),
            $ctx['archers'][$ids[1]]['name'] ?? ('#' . $ids[1]),
        ];
        $phase = prono_phase_of_slot($n);
        $label = $names[0] . ' — ' . $names[1];
        $sub   = $ctx['ev']['name'] . ' · ' . prono_phase_label($phase, $n);

        $live = null;
        if ($state === 'live') {
            $ls = prono_live_sets($sa, $sb);
            $live = $ctx['ev']['sets']
                ? $ls
                : ['sa' => $sa['score'], 'sb' => $sb['score'],
                   'left' => max(0, ($fmt['ends'] - $ls['sets']) * $fmt['perEnd'])];
            $sub .= $ctx['ev']['sets']
                ? ' · en cours ' . $ls['a'] . '-' . $ls['b']
                : ' · en cours ' . $sa['score'] . '-' . $sb['score'];
        } elseif ($state === 'done') {
            $sub .= $ctx['ev']['sets']
                ? ' · terminé ' . $sa['setScore'] . '-' . $sb['setScore']
                : ' · terminé ' . $sa['score'] . '-' . $sb['score'];
        }

        if ($state === 'done') {
            $pA = $sa['win'] ? 1.0 : 0.0;
            if (!$sa['win'] && !$sb['win']) $pA = 0.5;      // double IRM, marché annulé
        } else {
            $pA = prono_duel($ctx, $prof, $ids[0], $ids[1], $live);
        }

        $status = $state === 'done' ? 'SETTLED'
                : (($state === 'live' && $ctx['lockOnStart']) ? 'LOCKED' : 'OPEN');

        if (!prono_market_allowed($ctx, 'MATCH_WINNER')) continue;

        // Un seul marché par duel. Groupe 'W' : le vainqueur. Groupe 'S' : le score
        // exact, qui désigne déjà le vainqueur — d'où l'impossibilité de se
        // contredire, et un seul pronostic à poser au lieu de deux.
        $sels = [
            ['group' => 'W', 'code' => prono_akey($ctx, $ids[0]), 'label' => $names[0],
             'athlete' => $ids[0], 'prob' => $pA,
             'result' => $state === 'done' ? (int) (bool) $sa['win'] : -1, 'sort' => 0],
            ['group' => 'W', 'code' => prono_akey($ctx, $ids[1]), 'label' => $names[1],
             'athlete' => $ids[1], 'prob' => 1 - $pA,
             'result' => $state === 'done' ? (int) (bool) $sb['win'] : -1, 'sort' => 1],
        ];

        // Arc à poulies : pas de sets, un total de points. On propose des tranches du
        // total du vainqueur — un total exact sur 150 points serait injouable, une
        // tranche reste devinable et sa valeur suit sa probabilité réelle.
        if (!$ctx['ev']['sets'] && prono_market_allowed($ctx, 'SET_SCORE')) {
            if ($state === 'todo') {
                $tot = $fmt['perEnd'] * $fmt['ends'];
                foreach ([0, 1] as $w) {
                    foreach (prono_total_bands($prof[$ids[$w]]['arrow'], $prof[$ids[1 - $w]]['arrow'],
                                               $tot, $ctx['band']) as $b) {
                        // Les bornes sont inscrites dans le code : changer la largeur
                        // plus tard ne doit pas rejuger un pronostic déjà posé.
                        $sels[] = [
                            'group' => 'S', 'code' => ($w ? 'B' : 'A') . $b['lo'] . '-' . $b['hi'],
                            'label' => $names[$w] . ' ' . $b['lo'] . '-' . $b['hi'],
                            'athlete' => $ids[$w], 'prob' => $b['p'], 'result' => -1,
                            'sort' => 10 + $w * 100 + $b['lo'],
                        ];
                    }
                }
            } elseif ($state === 'done') {
                // Les tranches ne sont pas reconstruites une fois le duel joué : on
                // reprend celles qui ont été proposées et on les tranche au résultat,
                // sans quoi le marché resterait indéfiniment en attente.
                $winner = $sa['win'] ? 0 : ($sb['win'] ? 1 : -1);
                $score  = $winner === 0 ? (int) $sa['score'] : (int) $sb['score'];

                foreach (prono_all(
                    "SELECT s.PaSeCode, s.PaSeLabel, s.PaSeAthlete
                     FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                     WHERE m.PaMkTournament = ? AND m.PaMkTeam = ? AND m.PaMkEvent = ?
                       AND m.PaMkType = 'MATCH_WINNER' AND m.PaMkKey = ? AND s.PaSeGroup = 'S'",
                    [$tid, $ctx['ev']['team'] ? 1 : 0, $code, (string) $n]) as $old) {

                    // Bornes lues dans le code : un pronostic reste jugé sur la tranche
                    // qui lui a été proposée, quelle que soit la largeur configurée
                    // depuis. Ancien format sans borne haute : largeur d'alors, 3.
                    if (!preg_match('/^([AB])(\d+)(?:-(\d+))?$/', (string) $old['PaSeCode'], $cm)) continue;
                    $side = $cm[1] === 'B' ? 1 : 0;
                    $lo   = (int) $cm[2];
                    $hi   = isset($cm[3]) && $cm[3] !== '' ? (int) $cm[3] : $lo + 2;
                    $ok   = $winner === $side && $score >= $lo && $score <= $hi;

                    $sels[] = [
                        'group' => 'S', 'code' => $old['PaSeCode'], 'label' => $old['PaSeLabel'],
                        'athlete' => (int) $old['PaSeAthlete'], 'prob' => $ok ? 1 : 0,
                        'result' => (int) $ok, 'sort' => 10 + $side * 100 + $lo,
                    ];
                }
            }
        }

        // Le score n'est proposé qu'en sets, et seulement avant le début du match :
        // en cours, la plupart des scores sont déjà impossibles.
        if ($ctx['ev']['sets'] && prono_market_allowed($ctx, 'SET_SCORE') && $state !== 'live') {
            $so = prono_shootoff($prof[$ids[0]]['so'], $prof[$ids[1]]['so']);
            $r  = prono_match_sets($prof[$ids[0]]['end'], $prof[$ids[1]]['end'], 0, 0, 0, $so,
                                   $fmt['target'], $fmt['maxSets']);
            $i = 10;
            foreach ($r['scores'] as $sc => $p) {
                [$x, $y] = array_map('intval', explode('-', $sc));
                $winner  = $x > $y ? 0 : 1;
                $result  = -1;
                if ($state === 'done') {
                    $result = ((int) $sa['setScore'] === $x && (int) $sb['setScore'] === $y) ? 1 : 0;
                }
                $sels[] = [
                    'group' => 'S', 'code' => $sc,
                    'label' => $names[$winner] . ' ' . max($x, $y) . '-' . min($x, $y),
                    'athlete' => $ids[$winner], 'prob' => $p, 'result' => $result, 'sort' => $i++,
                ];
            }
        }

        $out[] = [
            'event' => $code, 'type' => 'MATCH_WINNER', 'key' => (string) $n,
            'label' => $label, 'sub' => $sub, 'phase' => $phase, 'sort' => $n,
            'status' => $status, 'sels' => $sels,
        ];
    }

    if (prono_market_allowed($ctx, 'EVENT_WINNER')) {
        $m = prono_build_event_winner($ctx, $prof, $slots, $matches);
        if ($m) $out[] = $m;
    }
    return $out;
}

/**
 * Vainqueur de l'épreuve, par propagation dans le tableau.
 *
 * ianseo range le tableau en arbre binaire : le vainqueur du slot n monte au slot
 * floor(n/2) — décalé de 2 en demi-finale. On propage donc une distribution
 * d'occupation slot par slot, en forçant les matchs déjà joués à leur résultat réel.
 */
function prono_build_event_winner(array $ctx, array $prof, array $slots, array $matches): ?array
{
    $first = $ctx['ev']['firstPhase'];
    if ($first < 2) return null;

    $occ = [];
    for ($n = 2 * $first; $n < 4 * $first; $n++) {
        $id = $slots[$n]['athlete'] ?? 0;
        if ($id > 0) $occ[$n] = [$id => 1.0];
    }

    $advance = function (int $n) use (&$occ, $ctx, $prof, $slots, $matches) {
        $A = $occ[$n] ?? [];
        $B = $occ[$n + 1] ?? [];
        if (!$A && !$B) return [[], []];
        if (!$A) return [$B, []];                       // exempt
        if (!$B) return [$A, []];

        $state = isset($matches[$n]) ? prono_match_state($slots[$n], $slots[$n + 1]) : 'todo';
        $live  = null;
        if ($state === 'live' && $ctx['ev']['sets']) $live = prono_live_sets($slots[$n], $slots[$n + 1]);

        $win = $lose = [];
        foreach ($A as $a => $pa) {
            foreach ($B as $b => $pb) {
                $joint = $pa * $pb;
                if ($joint <= 0) continue;
                if ($state === 'done') {
                    $p = ((int) $slots[$n]['win'] === 1 && (int) $slots[$n]['athlete'] === $a) ? 1.0
                       : ((int) $slots[$n + 1]['win'] === 1 && (int) $slots[$n + 1]['athlete'] === $b ? 0.0
                       : prono_duel($ctx, $prof, $a, $b, null));
                } else {
                    $p = prono_duel($ctx, $prof, $a, $b, $live);
                }
                $win[$a]  = ($win[$a] ?? 0) + $joint * $p;
                $win[$b]  = ($win[$b] ?? 0) + $joint * (1 - $p);
                $lose[$b] = ($lose[$b] ?? 0) + $joint * $p;
                $lose[$a] = ($lose[$a] ?? 0) + $joint * (1 - $p);
            }
        }
        return [$win, $lose];
    };

    $merge = function (array &$dst, array $src) {
        foreach ($src as $id => $p) $dst[$id] = ($dst[$id] ?? 0) + $p;
    };

    for ($phase = $first; $phase >= 2; $phase = intdiv($phase, 2)) {
        for ($n = 2 * $phase; $n < 4 * $phase; $n += 2) {
            [$win, $lose] = $advance($n);
            $target = prono_slot_target($n);
            if ($target >= 0 && $win) {
                if (!isset($occ[$target])) $occ[$target] = [];
                $merge($occ[$target], $win);
            }
            if ($phase === 2 && $lose) {                  // demi-finalistes battus → petite finale
                $bronze = $n === 4 ? 2 : 3;
                if (!isset($occ[$bronze])) $occ[$bronze] = [];
                $merge($occ[$bronze], $lose);
            }
        }
    }

    [$champ] = $advance(0);
    [$third] = $advance(2);
    if (!$champ) return null;

    $podium = [];
    foreach (($occ[0] ?? []) as $id => $p) $podium[$id] = ($podium[$id] ?? 0) + $p;   // finaliste = top 2
    foreach (($occ[1] ?? []) as $id => $p) $podium[$id] = ($podium[$id] ?? 0) + $p;
    foreach ($third as $id => $p)          $podium[$id] = ($podium[$id] ?? 0) + $p;

    $finalDone = isset($matches[0]) && prono_match_state($slots[0], $slots[1]) === 'done';

    // Le tableau lancé, le marché se ferme : chaque duel joué réduit le champ des
    // possibles, et pronostiquer le vainqueur en cours de route n'aurait plus de sens.
    $started = false;
    foreach ($matches as $n => [$sa, $sb]) {
        if (prono_match_state($sa, $sb) !== 'todo') { $started = true; break; }
    }

    arsort($champ);
    $sels = [];
    $i = 0;
    foreach ($champ as $id => $p) {
        // On garde tout ce qui est encore mathématiquement en lice, même très peu
        // probable : c'est l'absence de la liste qui signifie « éliminé », et
        // engine.php s'appuie dessus pour retirer les issues mortes.
        if ($p <= 1e-9 && !$finalDone) continue;
        $result = -1;
        if ($finalDone) {
            $winnerId = (int) $slots[0]['win'] === 1 ? (int) $slots[0]['athlete'] : (int) $slots[1]['athlete'];
            $result = ($id === $winnerId) ? 1 : 0;
        }
        $sels[] = [
            'code' => prono_akey($ctx, $id),
            'label' => $ctx['archers'][$id]['name'] ?? ('#' . $id),
            'athlete' => $id, 'prob' => $p, 'result' => $result, 'sort' => $i++,
            'extra' => ['podium' => round($podium[$id] ?? 0, 4)],
        ];
    }
    if (count($sels) < 2) return null;

    return [
        'event' => $ctx['ev']['code'], 'type' => 'EVENT_WINNER', 'key' => 'ALL',
        'label' => 'Vainqueur — ' . $ctx['ev']['name'],
        'sub' => 'Tableau final' . ($started && !$finalDone ? ' · pronostics clos' : ''),
        'phase' => 999, 'sort' => 0,
        'status' => $finalDone ? 'SETTLED' : ($started ? 'LOCKED' : 'OPEN'),
        'sels' => $sels,
    ];
}

// ─── Qualifications ──────────────────────────────────────────────────────────

function prono_build_quals(array $ctx): array
{
    $archers = $ctx['archers'];
    $tour    = $ctx['tour'];

    // Les marchés de qualification se construisent dès l'inscription, avant toute
    // flèche tirée : attendre un score pour ouvrir le marché prive les pronostics
    // les plus précoces de tout intérêt, et biaise le champ vers les premiers partis.
    // prono_archers()/prono_teams() énumèrent déjà tous les inscrits de l'épreuve
    // (LEFT JOIN Qualifications), flèches tirées ou non.
    $left = 0;
    foreach ($archers as $a) $left += $a['left'];
    $running = $left > 0;

    // Classement national (REPARTITION_EPREUVES), pour ancrer les fourchettes de score
    // (ci-dessous) sur des valeurs absolues réelles plutôt que la seule projection du
    // modèle. Sans objet pour les équipes (pas de classement national par équipe).
    $repClassement = $ctx['ev']['team'] ? null : prono_rep_classement($ctx['tid'], $archers);

    $prof  = prono_profiles($archers, $ctx['prior'], $ctx['fmt'], $ctx['repPriors'] ?? []);
    $field = [];
    foreach ($archers as $id => $a) {
        if ($a['irm']) continue;
        $field[$id] = [
            'score' => $a['score'], 'left' => $a['left'],
            'mu' => $prof[$id]['mu'], 'sd' => $prof[$id]['sd'],
        ];
    }
    if (count($field) < 2) return [];

    // Verrouillage sur la dernière volée : au-delà, le score est quasi acquis.
    $minLeft = min(array_column($field, 'left'));
    $status  = !$running ? 'SETTLED' : ($minLeft <= PRONO_QUAL_LOCK_ARROWS ? 'LOCKED' : 'OPEN');

    // Projection courante, pour choisir les archers dignes d'un marché
    $proj = [];
    foreach ($field as $id => $f) $proj[$id] = $f['score'] + $f['left'] * $f['mu'];
    arsort($proj);
    $order = array_keys($proj);

    $qualifiers = $ctx['ev']['firstPhase'] > 0 ? $ctx['ev']['firstPhase'] * 2 : 0;

    $out  = [];
    $name = fn($id) => $archers[$id]['name'] ?? ('#' . $id);

    // Rangs réels une fois la qualification terminée (QuClRank, déjà lu par
    // paris_archers()/paris_teams()) — sert au règlement des 3 marchés ci-dessous.
    $actualRank = [];
    if (!$running) {
        foreach ($archers as $id => $a) {
            $r = (int) $a['rank'];
            if ($r >= 1) $actualRank[$r] = $id;
        }
    }

    // ── Tiercé de qualification : qui finit 1er, 2e, 3e ? Comme aux courses
    // hippiques — ordre exact (gain maximum), même trio dans le désordre (gain
    // partiel) ou rien si l'un des trois noms est faux. Un seul pronostic à 3 noms
    // (voir engine.php pour le règlement), jamais 3 lignes indépendantes.
    if (prono_market_allowed($ctx, 'QUAL_TIERCE')) {
        // Tout le monde doit pouvoir être choisi à chaque position : la seule
        // contrainte est de ne pas jouer 3 fois le même nom (imposé côté saisie,
        // public/index.php + public/api.php). On calcule donc Harville sur le champ
        // ENTIER — pas de coût prohibitif en pratique : O(n) sur le rang 1, O(n^3) sur
        // le rang 3, mais un champ de qualification ianseo réel compte au plus
        // quelques dizaines à une centaine d'archers (garde-fou défensif au-delà,
        // voir PRONO_TIERCE_MAX_FIELD). Une probabilité proche de zéro reste affichée
        // (et pariable) : c'est prono_refresh_odds() qui la plancher pour l'affichage
        // et le calcul des points, comme pour tous les autres marchés.
        $tierceFocus = count($order) > PRONO_TIERCE_MAX_FIELD
            ? array_slice($order, 0, PRONO_TIERCE_MAX_FIELD)
            : $order;

        if (count($tierceFocus) >= 3) {
            $win1 = prono_qual_ranks($field, [1], $tierceFocus);
            $winProb = [];
            foreach ($tierceFocus as $id) $winProb[$id] = $win1[$id][1] ?? 0.0;
            $hRanks = prono_harville_ranks($winProb);

            $sels = [];
            $i = 0;
            foreach ([1 => 'R1', 2 => 'R2', 3 => 'R3'] as $rk => $grp) {
                foreach ($tierceFocus as $id) {
                    $p = $hRanks[$id][$rk] ?? 0.0;
                    $result = $running ? -1 : (int) (($actualRank[$rk] ?? null) === $id);
                    // Un même archer candidate aux 3 positions : le code doit porter le
                    // groupe, sinon les 3 lignes se disputeraient la même clé (marché, code).
                    $sels[] = [
                        'group' => $grp, 'code' => $grp . ':' . prono_akey($ctx, $id), 'label' => $name($id),
                        'athlete' => $id, 'prob' => $p, 'result' => $result, 'sort' => $i++,
                    ];
                }
            }
            // Au moins 2 candidats par position, sans quoi le tiercé n'a aucun
            // intérêt (un seul nom possible par case).
            if (count($sels) >= 6) {
                $out[] = [
                    'event' => $ctx['ev']['code'], 'type' => 'QUAL_TIERCE', 'key' => 'ALL',
                    'label' => 'Tiercé de qualification — ' . $ctx['ev']['name'],
                    'sub' => 'Qui finit 1er, 2e et 3e ? Ordre exact ou désordre, comme au tiercé',
                    'phase' => 998, 'sort' => 0, 'status' => $status, 'sels' => $sels,
                ];
            }
        }
    }

    // ── Score du premier qualifié et du cut (dernier qualifié), en fourchette —
    // seules les deux valeurs qui comptent vraiment pour la suite du tableau.
    // Tant que la qualification tourne, on recalcule la fourchette à chaque passage
    // (la vraisemblance bouge avec les scores qui tombent) ; une fois réglée, on
    // relit les fourchettes déjà proposées et on les tranche au résultat réel, sans
    // quoi changer la largeur des tranches en cours de route rejugerait un
    // pronostic déjà posé (même principe que les tranches de l'arc à poulies).
    // Un « cut » n'existe que si le champ dépasse le nombre de qualifiés — sinon tout
    // le monde passe et la question n'a pas de sens (et, pire, une statistique d'ordre
    // à un rang plus grand que le champ ne peut mathématiquement jamais se réaliser).
    $qualBandTargets = ['QUAL_TOP1' => 1];
    if ($qualifiers > 0 && $qualifiers < count($order)) $qualBandTargets['QUAL_CUT'] = $qualifiers;

    foreach ($qualBandTargets as $type => $rank) {
        if (!prono_market_allowed($ctx, $type)) continue;

        // Simplifié à 3 issues (« - de X1 », « X1-X2 », « + de X2 »), ancrées sur le
        // classement national plutôt que construites par tranches de largeur fixe
        // autour d'une projection du modèle interne : X1/X2 sont des points de repère
        // réels (meilleurs scores individuels de la catégorie — prono_rep_anchor_top1/
        // cut() dans data.php), lisibles pour un joueur qui suit le classement, et
        // adaptés d'eux-mêmes à toute catégorie/arme/discipline puisqu'ils viennent
        // des scores RÉELS de CETTE catégorie. Sans classement national résolu pour
        // cette épreuve, le marché n'existe pas : il n'y a pas de repère fiable pour
        // fixer X1/X2 autrement qu'arbitrairement.
        if (!$repClassement) continue;
        $anchor = $type === 'QUAL_TOP1'
            ? prono_rep_anchor_top1($repClassement, $archers)
            : prono_rep_anchor_cut($repClassement, $archers, $rank);
        if (!$anchor) continue;
        $x1 = $anchor['lo'];
        $x2 = $anchor['hi'];

        if ($running) {
            // Répartition entre les 3 issues : voir prono_qual_three_way() (lib/model.php)
            // pour le principe retenu — rester dans l'intervalle déjà réalisé est le plus
            // probable, un record individuel (un seul candidat suffit) plus probable
            // qu'une contre-performance simultanée de tout le groupe de référence.
            $p = prono_qual_three_way($anchor['n']);

            $sels = [
                ['code' => 'LO:' . $x1, 'label' => '- de ' . round($x1) . ' pts',
                 'athlete' => 0, 'prob' => $p['lo'], 'result' => -1, 'sort' => 0],
                ['code' => 'MID:' . $x1 . ':' . $x2, 'label' => round($x1) . '-' . round($x2) . ' pts',
                 'athlete' => 0, 'prob' => $p['mid'], 'result' => -1, 'sort' => 1],
                ['code' => 'HI:' . $x2, 'label' => '+ de ' . round($x2) . ' pts',
                 'athlete' => 0, 'prob' => $p['hi'], 'result' => -1, 'sort' => 2],
            ];
        } else {
            $actualId = $actualRank[$rank] ?? null;
            if ($actualId === null || !isset($archers[$actualId])) continue;
            $actualScore = (int) $archers[$actualId]['score'];

            // Les seuils déjà proposés restent ceux du règlement (même principe que les
            // tranches poulies) : relus depuis la base plutôt que recalculés, au cas où
            // le classement national aurait changé depuis (mise à jour REPARTITION_EPREUVES).
            $sels = [];
            foreach (prono_all(
                "SELECT s.PaSeCode, s.PaSeLabel
                 FROM PRONO_Selections s INNER JOIN PRONO_Markets m ON m.PaMkId = s.PaSeMarket
                 WHERE m.PaMkTournament = ? AND m.PaMkTeam = ? AND m.PaMkEvent = ?
                   AND m.PaMkType = ? AND m.PaMkKey = 'ALL'",
                [$ctx['tid'], $ctx['ev']['team'] ? 1 : 0, $ctx['ev']['code'], $type]
            ) as $old) {
                if (!preg_match('/^(LO|MID|HI):(-?[\d.]+)(?::(-?[\d.]+))?$/', (string) $old['PaSeCode'], $cm)) continue;
                $kind = $cm[1];
                $sort = $kind === 'LO' ? 0 : ($kind === 'MID' ? 1 : 2);
                if ($kind === 'LO') {
                    $ok = $actualScore < (float) $cm[2];
                } elseif ($kind === 'HI') {
                    $ok = $actualScore > (float) $cm[2];
                } else {
                    $ok = $actualScore >= (float) $cm[2] && $actualScore <= (float) ($cm[3] ?? $cm[2]);
                }
                $sels[] = [
                    'code' => $old['PaSeCode'], 'label' => $old['PaSeLabel'], 'athlete' => 0,
                    'prob' => $ok ? 1 : 0, 'result' => (int) $ok, 'sort' => $sort,
                ];
            }
            if (!$sels) continue;
        }

        $label = $type === 'QUAL_TOP1'
            ? 'Score du 1er qualifié — ' . $ctx['ev']['name']
            : 'Score du cut (dernier qualifié) — ' . $ctx['ev']['name'];
        $out[] = [
            'event' => $ctx['ev']['code'], 'type' => $type, 'key' => 'ALL',
            'label' => $label, 'sub' => 'Qualifications · classement national',
            'phase' => $type === 'QUAL_TOP1' ? 997 : 996, 'sort' => 0,
            'status' => $status, 'sels' => $sels,
        ];
    }

    return $out;
}
