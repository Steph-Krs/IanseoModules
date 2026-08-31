<?php
/**
 * lib/cohabitation.php — cohabitation des blasons sur une même cible (M7).
 *
 * Règles FFTA fournies (voir REGLES_COHABITATION.md). Modèle UNIFIÉ qui reproduit
 * toutes les combinaisons : chaque cible a un BUDGET physique et chaque blason un
 * COÛT (fraction de cible occupée) ; une cible est valide si Σcoûts ≤ budget ET
 * nombre d'archers ≤ rythme (SesAth4Target).
 *
 *   18 m (buttress 4×40cm) : budget 4 — 40cm→1, 60cm→2, 80→4.
 *   TAE  (blason 80)       : budget 3 — 80 réduit→1, plein (60/80/122)→3.
 *
 * Le coût prend toute la cible (= budget) pour un blason non reconnu → jamais de
 * sur-remplissage : dans le doute, la cible n'accueille qu'un archer.
 *
 * Pur (aucune écriture) : sert au contrôle d'admission à l'inscription (jauge par
 * catégorie/blason + refus si plus de place) ET à l'éligibilité du placement.
 * Parcours (pelotons) : modèle distinct (taille/quota club/équilibre), voir plus tard.
 */

if (defined('BK_COHAB_LOADED')) return;
define('BK_COHAB_LOADED', true);

/** Disciplines où la cohabitation de blasons est régie par des règles fermes. */
function bk_cohabit_enabled($disc)
{
    return $disc === 'ext' || $disc === 'salle';
}

/** Budget physique d'une cible (en unités de coût). Surcharge : config.local.json → cohabitation.budget. */
function bk_cohabit_budget($disc)
{
    $ov = bk_cohabit_conf('budget', $disc);
    if ($ov !== null) return max(1, intval($ov));
    switch ($disc) {
        case 'salle': return 4;   // 18 m : jusqu'à 4 blasons de 40cm sur le buttress
        case 'ext':   return 3;   // TAE : 3 réduits-80 ou 1 blason plein
        default:      return 1;
    }
}

/** Petit accès aux surcharges de config.local.json (facultatif, jamais requis). */
function bk_cohabit_conf($key, $disc)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = array();
        $f = dirname(__DIR__) . '/config.local.json';
        if (is_file($f)) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j) && isset($j['cohabitation']) && is_array($j['cohabitation'])) $cfg = $j['cohabitation'];
        }
    }
    if (isset($cfg[$key][$disc])) return $cfg[$key][$disc];
    if (isset($cfg[$key]) && !is_array($cfg[$key])) return $cfg[$key];
    return null;
}

/** Minuscule sans accents, pour analyser un nom de blason quel que soit l'encodage. */
function bk_face_norm($s)
{
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $from = array('à','â','ä','é','è','ê','ë','î','ï','ô','ö','ù','û','ü','ç');
    $to   = array('a','a','a','e','e','e','e','i','i','o','o','u','u','u','c');
    return str_replace($from, $to, $s);
}

/**
 * Classe un blason d'après son nom (TargetFaces.TfName, jeu FFTA).
 * Retour : ['dia'=>40|60|80|122|0, 'type'=>'mono'|'tri'|'full'|'reduit'|'peg'|'', 'raw'=>nom].
 */
function bk_face_class($tfName)
{
    $n = bk_face_norm($tfName);
    $type = '';
    if (strpos($n, 'piquet') !== false)                        $type = 'peg';
    elseif (strpos($n, 'reduit') !== false)                    $type = 'reduit';
    elseif (strpos($n, 'trispot') !== false)                   $type = 'tri';
    elseif (strpos($n, 'monospot') !== false || strpos($n, 'unique') !== false) $type = 'mono';
    elseif (strpos($n, 'complet') !== false || strpos($n, 'classique') !== false
         || strpos($n, 'poulie') !== false || strpos($n, 'blason') !== false)   $type = 'full';

    // Diamètre : premier jeton plausible. Bornes numériques (pas \b, qui échoue sur
    // « 40cm » car chiffres et lettres sont tous des caractères de mot) ; les fourchettes
    // de zones « 6-10 »/« 5-10 » ne matchent pas (5/6/10 ne sont pas des diamètres).
    $dia = 0;
    if (preg_match('/(?<!\d)(122|80|60|40)(?!\d)/', $n, $m)) $dia = intval($m[1]);

    return array('dia' => $dia, 'type' => $type, 'raw' => (string) $tfName);
}

/** Classe d'un blason à partir de son TfId (lecture TargetFaces + cache par tournoi). */
function bk_face_class_by_id($tourId, $tfId)
{
    static $cache = array();
    $tfId = intval($tfId);
    $key  = intval($tourId) . ':' . $tfId;
    if (!isset($cache[$key])) {
        $name = '';
        if ($tfId > 0) {
            $r = safe_fetch(safe_r_sql("SELECT TfName FROM TargetFaces
                WHERE TfId = $tfId AND TfTournament = " . intval($tourId)));
            if ($r) $name = $r->TfName;
        }
        $cache[$key] = bk_face_class($name);
    }
    return $cache[$key];
}

/** Coût d'un blason PIN (fraction de cible occupée par un blason posé) selon la discipline. */
function bk_face_cost($class, $disc)
{
    $b = bk_cohabit_budget($disc);
    if (!is_array($class)) return $b;
    $dia  = intval($class['dia'] ?? 0);
    $type = (string) ($class['type'] ?? '');

    if ($disc === 'ext') {
        return ($type === 'reduit') ? 1 : $b;    // réduit = 1/3 ; blason plein = toute la cible
    }
    if ($disc === 'salle') {                      // 18 m : selon le diamètre
        if ($dia && $dia <= 40) return 1;
        if ($dia && $dia <= 60) return 2;
        return $b;                                // 80 (ou inconnu) = toute la cible
    }
    return $b;
}

/**
 * Un blason est-il PARTAGEABLE (plusieurs archers d'une même catégorie tirent le
 * même blason, une seule cible à tour de rôle) ? En TAE, seuls les blasons PLEINS
 * (60/80/122) le sont — c'est pourquoi une cible « 1 blason de 122 » porte plusieurs
 * archers. Ailleurs (réduits TAE, tout le 18 m), chaque archer a son propre blason.
 */
function bk_face_shareable($class, $disc)
{
    if ($disc === 'ext') return (string) ($class['type'] ?? '') === 'full';
    return false;
}

/** Clé d'un blason (diamètre|type) pour regrouper les blasons partagés identiques. */
function bk_face_key($class)
{
    return intval($class['dia'] ?? 0) . '|' . (string) ($class['type'] ?? '');
}

/**
 * Coût total des BLASONS POSÉS pour un ensemble d'archers : un blason partageable
 * n'est compté qu'UNE fois (les archers de même catégorie tirent le même) ; un blason
 * par archer sinon.
 */
function bk_cohabit_pins_cost($faces, $disc)
{
    $seen = array();
    $cost = 0;
    foreach ($faces as $f) {
        if (bk_face_shareable($f, $disc)) {
            $k = bk_face_key($f);
            if (isset($seen[$k])) continue;       // blason partagé déjà posé
            $seen[$k] = true;
        }
        $cost += bk_face_cost($f, $disc);
    }
    return $cost;
}

/**
 * L'ensemble de blasons $faces (une classe par archer) peut-il partager une cible de
 * rythme $rhythm dans cette discipline ? (Σ coûts des blasons posés ≤ budget ET nb archers ≤ rythme.)
 */
function bk_cohabit_ok($faces, $disc, $rhythm)
{
    $rhythm = max(1, intval($rhythm));
    if (count($faces) > $rhythm) return false;
    if (!bk_cohabit_enabled($disc)) return true;
    return bk_cohabit_pins_cost($faces, $disc) <= bk_cohabit_budget($disc);
}

/**
 * Combien d'archers de blason $newFace peut-on ENCORE ajouter à une cible portant déjà
 * $current (une classe par archer), rythme $rhythm ? 0 = plus de place pour ce blason.
 * Un blason partageable déjà posé se rejoint sans coût (seul le rythme limite).
 */
function bk_cohabit_max_add($current, $newFace, $disc, $rhythm)
{
    $rhythm = max(1, intval($rhythm));
    $byCount = $rhythm - count($current);
    if ($byCount <= 0) return 0;
    if (!bk_cohabit_enabled($disc)) return $byCount;

    $b = bk_cohabit_budget($disc);

    // Rejoindre un blason partageable identique déjà posé : aucun coût supplémentaire.
    if (bk_face_shareable($newFace, $disc)) {
        $k = bk_face_key($newFace);
        foreach ($current as $f) {
            if (bk_face_shareable($f, $disc) && bk_face_key($f) === $k) return $byCount;
        }
    }

    $used = bk_cohabit_pins_cost($current, $disc);
    $cost = bk_face_cost($newFace, $disc);
    if ($cost <= 0) $cost = $b;
    $byBudget = intdiv(max(0, $b - $used), $cost);
    return max(0, min($byCount, $byBudget));
}
