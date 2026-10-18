<?php
/**
 * booking/lib/palmares.php — the archer's federation record ("Mon palmarès" of the extranet),
 * imported into their statistics.
 *
 * The archer exports the record from the extranet (Excel .xlsx) and picks the file on
 * public/stats-import.php. The file is read IN THEIR BROWSER (assets/palmares.js): only the rows
 * reach this server, never the file, and this server never calls the extranet — no credential
 * asked, no load here, nothing for the federation's site to tell apart from a normal visit.
 *
 * Here: checking the rows (they come from the browser: never trusted), keeping one line per
 * round shot (the extranet lists a result under every ranking it counts for — the age class,
 * the scratch…), storing them, and turning them into rounds of the statistics (lib/stats.php).
 *
 * The extranet names the ranking of a result, never its distance nor its target face. They come
 * from the label when it carries them ("… 2019 - 70m - 122cm"), otherwise from
 * lib/palmares-map.json: what was really shot per season, discipline, age class and bow, counted
 * from the national result files (tools/palmares-map.php, no personal data). Only the labels are
 * stored, so a better table places every result again without a new import.
 *
 * How sure a placement is ('conf'): exact = written in the label; data = the season's table
 * gives one distance and face for at least 97 % of the results of that class and bow;
 * probable = the table is split, or the season is outside it (before its first season: the
 * rules then were not always those of the first season known); unknown = nothing to go by.
 * Distinctions count only exact and data placements of official results.
 */

if (defined('BK_PAL_LOADED')) return;
define('BK_PAL_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/archer.php';
require_once __DIR__ . '/distinctions.php';

/**
 * The record page of the extranet (the archer's own session, in their own browser). The season
 * cannot be chosen from here: it is a form sent by POST, which another site's page sends without
 * the extranet's session cookie (SameSite=Lax), and the address ignores it (the form's field in
 * the query string, with or without its operation field: tried 2026-10-18). The archer picks
 * every season by hand (step 2).
 */
function bk_pal_extranet_url()
{
    return 'https://extranet.ffta.fr/mon_palmares.html';
}

/** Most rows accepted in one import: a whole career listed under two rankings each. */
function bk_pal_max_rows()
{
    return 5000;
}

/** Lower case, without accents, single spaces: labels compared whatever their spelling. */
function bk_pal_fold($s)
{
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $s = strtr($s, array('é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
                         'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
                         'ç' => 'c', 'œ' => 'oe', '’' => "'"));
    return preg_replace('/\s+/', ' ', $s);
}

/* ------------------------------------------------------------------ */
/* Labels of the extranet                                              */
/* ------------------------------------------------------------------ */

/**
 * Season and discipline of a heading of the record (the word for "individual", the discipline,
 * the year): ['season', 'name' (the discipline as written), 'code' (the national files'
 * discipline: S 18 m, F the old international round, E the old national round, T outdoor from
 * 2019, C field, N nature, 3 3D, B Beursault, H/I para, P youth tournament, D 1440 round, ''
 * unknown)]. Null for anything but an individual heading.
 */
function bk_pal_heading($heading)
{
    $h = trim((string) $heading);
    if (!preg_match('/^individuel\s+(.+?)\s+(\d{4})$/iu', $h, $m)) return null;
    $season = intval($m[2]);
    $f = bk_pal_fold($m[1]);
    $late = $season >= 2019;
    if (strpos($f, 'para') !== false)                                   $code = strpos($f, '18') !== false ? 'I' : 'H';
    elseif (preg_match('/salle|\b(18|25) ?m\b/', $f))                   $code = 'S';
    elseif (strpos($f, 'campagne') !== false)                           $code = 'C';
    elseif (strpos($f, 'nature') !== false)                             $code = 'N';
    elseif (preg_match('/\b3d\b/', $f))                                 $code = '3';
    elseif (strpos($f, 'beursault') !== false)                          $code = 'B';
    elseif (strpos($f, '1440') !== false)                               $code = 'D';
    elseif (preg_match('/tournoi|\bu11\b/', $f))                        $code = 'P';
    elseif (strpos($f, 'exterieur') !== false)                          $code = 'T';
    elseif (preg_match('/fita|2 ?x ?70|international/', $f))            $code = $late ? 'T' : 'F';
    elseif (preg_match('/federal|2 ?x ?50|national/', $f))              $code = $late ? 'T' : 'E';
    else                                                                $code = '';
    return array('season' => $season, 'name' => trim($m[1]), 'code' => $code);
}

/**
 * A ranking label: age classes, sex, bow, year, and from 2019 outdoors the distance and face
 * ("… 2019 - 70m - 122cm"). Returns ['name' (without year nor distance), 'classes' (age classes
 * named, in the codes of the national files: P B M C J S V SV, S1 S2 S3, U11…U21; a range
 * names both ends), 'scratch', 'para', 'sex' (M / F / ''), 'bow' (the text after the sex),
 * 'dist', 'face' (0 when not written)].
 */
function bk_pal_category($label)
{
    $s = trim((string) $label);
    $dist = 0; $face = 0;
    if (preg_match('/\s*-\s*(\d{1,3})\s*m\s*-\s*(\d{2,3})\s*cm\s*$/iu', $s, $m)) {
        $dist = intval($m[1]); $face = intval($m[2]);
        $s = trim(mb_substr($s, 0, mb_strlen($s) - mb_strlen($m[0])));
    }
    $s = trim(preg_replace('/\s+\d{4}$/', '', $s));
    $f = bk_pal_fold($s);
    $sex = ''; $age = $f; $bow = '';
    if (preg_match('/^(.*?)\b(hommes?|femmes?|mixtes?)\b(.*)$/', $f, $m)) {
        $age = trim($m[1]); $bow = trim($m[3]);
        $sex = $m[2][0] === 'h' ? 'M' : ($m[2][0] === 'f' ? 'F' : '');
    }
    $para = (bool) preg_match('/\b(open|federal|hv|w1|support|criterium|challenge|sourd|nei|st[1-4]?|b[1-3])\b/', $age);
    $classes = array();
    $pat = '/\bu(11|13|15|18|21)\b|\bsuper ?-?veterans?\b|\bveterans?\b|\bseniors? ?([123])\b|\bs([123])\b'
         . '|\bseniors\b|\badultes?\b|\bsenior\b|\bpoussins?\b|\bbenjamins?\b|\bminimes?\b|\bcadets?\b|\bjuniors?\b|\bjeunes?\b/';
    if (preg_match_all($pat, $age, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) {
            $w = $x[0];
            if (!empty($x[1]))                          $add = array('U' . $x[1]);
            elseif (strpos($w, 'super') === 0)          $add = array('SV');
            elseif (strpos($w, 'veteran') === 0)        $add = array('V');
            elseif (!empty($x[2]))                      $add = array('S' . $x[2]);
            elseif (!empty($x[3]))                      $add = array('S' . $x[3]);
            elseif ($w === 'seniors' || strpos($w, 'adulte') === 0) $add = array('U21', 'S1', 'S2', 'S3');
            elseif ($w === 'senior')                    $add = array('S');
            elseif (strpos($w, 'poussin') === 0)        $add = array('P');
            elseif (strpos($w, 'benjamin') === 0)       $add = array('B');
            elseif (strpos($w, 'minime') === 0)         $add = array('M');
            elseif (strpos($w, 'cadet') === 0)          $add = array('C');
            elseif (strpos($w, 'junior') === 0)         $add = array('J');
            else                                        $add = array('U13', 'U15', 'U18');
            foreach ($add as $c) if (!in_array($c, $classes, true)) $classes[] = $c;
        }
    }
    return array('name' => $s, 'classes' => $classes, 'scratch' => strpos($f, 'scratch') !== false, 'para' => $para,
                 'sex' => $sex, 'bow' => $bow, 'dist' => $dist, 'face' => $face);
}

/**
 * Bow of a ranking label, as the federation codes it — which is also the division code of the
 * core's French set: CL, CO, BB, AD, AC, TL. In 3D and nature the federation (and the set) code
 * the bare bow CL and the bare compound CO.
 */
function bk_pal_bow($bowText, $code)
{
    $b = ' ' . bk_pal_fold($bowText) . ' ';
    $woods = in_array($code, array('3', 'N'), true);
    if (strpos($b, 'poulie') !== false)                         return 'CO';
    if (strpos($b, 'classique') !== false)                      return 'CL';
    if (preg_match('/bare ?bow|\bnu\b/', $b))                   return $woods ? 'CL' : 'BB';
    if (strpos($b, 'droit') !== false || strpos($b, 'longbow') !== false) return 'AD';
    if (strpos($b, 'chasse') !== false)                         return 'AC';
    if (strpos($b, 'libre') !== false)                          return 'TL';
    return '';
}

/**
 * Age class code of the national files for a class named in another system of age classes:
 * up to 2018 P B M C J S V SV, 2019-2022 P B M C J S1 S2 S3, from 2023 U11 U13 U15 U18 U21 S1
 * S2 S3 (same age bands, other names).
 */
function bk_pal_cls_in($code, $season)
{
    $toNew = array('P' => 'U11', 'B' => 'U13', 'M' => 'U15', 'C' => 'U18', 'J' => 'U21', 'S' => 'S1', 'V' => 'S2', 'SV' => 'S3');
    $toOld = array('U11' => 'P', 'U13' => 'B', 'U15' => 'M', 'U18' => 'C', 'U21' => 'J');
    if ($season >= 2023) return $toNew[$code] ?? $code;
    $c = $toOld[$code] ?? $code;
    if ($season >= 2019) return in_array($c, array('S', 'V', 'SV'), true) ? $toNew[$c] : $c;
    $toSenior = array('S1' => 'S', 'S2' => 'V', 'S3' => 'SV');
    return $toSenior[$c] ?? $c;
}

/** Age class (U11…S3, the distinctions' names) of a code of the national files. */
function bk_pal_age($code)
{
    $m = array('P' => 'U11', 'B' => 'U13', 'M' => 'U15', 'C' => 'U18', 'J' => 'U21', 'S' => 'S1', 'V' => 'S2', 'SV' => 'S3');
    return $m[$code] ?? $code;
}

/** Age class of an archer born in $birthYear during $season, in that season's codes; '' unknown. */
function bk_pal_cls_for_year($season, $birthYear)
{
    if ($birthYear < 1900 || $birthYear >= $season) return '';
    $a = $season - $birthYear;
    $young = $a <= 10 ? 'U11' : ($a <= 12 ? 'U13' : ($a <= 14 ? 'U15' : ($a <= 17 ? 'U18' : ($a <= 20 ? 'U21' : ''))));
    if ($young !== '') return bk_pal_cls_in($young, $season);
    if ($season >= 2019) return $a <= 39 ? 'S1' : ($a <= 59 ? 'S2' : 'S3');
    return $a <= 49 ? 'S' : ($a <= 59 ? 'V' : 'SV');
}

/**
 * Birth year of a licence: the account's, else the federation's licence file (LookUpEntries,
 * date of birth in LueCtrlCode); 0 unknown.
 */
function bk_pal_birth_year($licence)
{
    $lic = StrSafe_DB(bk_clean_licence($licence));
    $a = safe_fetch(safe_r_sql("SELECT BaBirthYear FROM BookingArchers WHERE BaLicence = $lic"));
    if ($a && intval($a->BaBirthYear) > 1900) return intval($a->BaBirthYear);
    $l = safe_fetch(safe_r_sql("SELECT LueCtrlCode FROM LookUpEntries WHERE LueCode = $lic AND LueIocCode = 'FRA'"));
    return $l && preg_match('/^(\d{4})-/', (string) $l->LueCtrlCode, $m) && intval($m[1]) > 1900 ? intval($m[1]) : 0;
}

/**
 * The archer's age class per season, read in their own record: [season => class code], from the
 * rankings that name one class (the most frequent when a season has several). It places a
 * result listed only in a scratch when the birth year is unknown.
 */
function bk_pal_season_classes(array $pairs)
{
    $n = array();
    foreach ($pairs as $p) {
        list($season, $label) = $p;
        $c = bk_pal_category($label);
        if ($c['para'] || $c['scratch'] || count($c['classes']) !== 1) continue;
        $cl = bk_pal_cls_in($c['classes'][0], $season);
        $n[$season][$cl] = ($n[$season][$cl] ?? 0) + 1;
    }
    $out = array();
    foreach ($n as $season => $counts) { arsort($counts); $out[$season] = key($counts); }
    return $out;
}

/** lib/palmares-map.json, read once. */
function bk_pal_map()
{
    static $map = null;
    if ($map === null) {
        $j = json_decode((string) @file_get_contents(__DIR__ . '/palmares-map.json'), true);
        $map = is_array($j) && isset($j['map']) ? $j : array('seasons' => array(0, 0), 'map' => array());
    }
    return $map;
}

/**
 * lib/palmares-rules.json, read once: the rules written by hand (seasons the national files do
 * not cover, or a correction). One rule = a season range, a discipline code, an age class (codes
 * of those seasons) and a bow → distance (or peg) and face; 'verified' = checked by a person, the
 * placement is then sure. A malformed file gives no rule (tools/palmares-rules.php checks it).
 */
function bk_pal_rules()
{
    static $rules = null;
    if ($rules === null) {
        $rules = array();
        $j = json_decode((string) @file_get_contents(__DIR__ . '/palmares-rules.json'), true);
        foreach ((array) ($j['rules'] ?? array()) as $r) {
            if (!is_array($r) || !isset($r['disc'], $r['class'], $r['bow'], $r['dist'])) continue;
            $rules[] = array('from' => intval($r['from'] ?? 0), 'to' => intval($r['to'] ?? 9999),
                'disc' => (string) $r['disc'], 'class' => (string) $r['class'], 'bow' => (string) $r['bow'],
                'dist' => intval($r['dist']), 'face' => intval($r['face'] ?? 0), 'verified' => !empty($r['verified']));
        }
    }
    return $rules;
}

/** The first rule written by hand for a discipline code, season, age class and bow, or null. */
function bk_pal_rule($code, $season, $cls, $bow)
{
    foreach (bk_pal_rules() as $r) {
        if ($r['disc'] === $code && $r['class'] === $cls && $r['bow'] === $bow && $season >= $r['from'] && $season <= $r['to']) return $r;
    }
    return null;
}

/**
 * Distance and face for a discipline code, season, age classes (national files' codes of that
 * season) and bow: ['dist', 'face', 'conf'] or null. A rule written by hand comes first
 * (bk_pal_rules) — sure once verified; then what was most shot that season; a season outside
 * the national files reads the closest one known, as 'probable'.
 */
function bk_pal_lookup($code, $season, array $classes, $bow)
{
    if ($code === '' || $bow === '' || !$classes) return null;
    // By hand: every class named must have its rule, all giving the same distance and face.
    $hits = array();
    foreach ($classes as $cl) {
        $r = bk_pal_rule($code, $season, $cl, $bow);
        if (!$r) { $hits = array(); break; }
        $hits[$r['dist'] . '/' . $r['face']][] = $r;
    }
    if (count($hits) === 1) {
        $list = reset($hits);
        $sure = !in_array(false, array_column($list, 'verified'), true);
        return array('dist' => $list[0]['dist'], 'face' => $list[0]['face'], 'conf' => $sure ? 'data' : 'probable');
    }
    $j = bk_pal_map();
    list($lo, $hi) = array_map('intval', $j['seasons']);
    if ($lo <= 0) return null;
    $s = max($lo, min($hi, $season));
    $c = $code;
    // Outdoors, the codes changed in 2019: F and E (the old rounds) became T.
    if ($s >= 2019 && in_array($c, array('F', 'E'), true)) $c = 'T';
    $sum = array(); $tot = 0;
    foreach ($classes as $cl) {
        $e = $j['map'][$s . '|' . $c . '|' . bk_pal_cls_in($cl, $s) . '|' . $bow] ?? null;
        if (!$e) continue;
        foreach ($e['df'] as $df) {
            // A distance of 0 is a value the organiser did not enter, not a distance.
            if ($df[0] <= 0) continue;
            $k = $df[0] . '/' . $df[1];
            $sum[$k] = ($sum[$k] ?? 0) + $df[2] * $e['n'];
            $tot += $df[2] * $e['n'];
        }
    }
    if (!$sum) return null;
    arsort($sum);
    $k = key($sum);
    list($d, $f) = array_map('intval', explode('/', $k));
    $share = $sum[$k] / $tot;
    return array('dist' => $d, 'face' => $f, 'conf' => ($s === $season && $share >= 0.97) ? 'data' : 'probable');
}

/* ------------------------------------------------------------------ */
/* Import: checking, one line per round, storing                       */
/* ------------------------------------------------------------------ */

/**
 * Checks what the browser sent ({"lic": …, "rows": [{d, c, dt, ev, pl, sc, off, cnt}]}) for the
 * archer signed in. Returns ['error' => key of the message] or ['rows' => valid rows,
 * 'skipped' => rows refused]. Team headings and malformed rows are left out, not fatal; a
 * record of another licence is refused as a whole.
 */
function bk_pal_check($json, $licence)
{
    // bytes: a size limit on the raw request body
    if (strlen((string) $json) > 3 * 1024 * 1024) return array('error' => 'PalErrTooBig');
    $p = json_decode((string) $json, true);
    if (!is_array($p) || !isset($p['rows']) || !is_array($p['rows'])) return array('error' => 'PalErrFormat');
    $lic = is_string($p['lic'] ?? null) ? bk_clean_licence($p['lic']) : '';
    if (!preg_match('/^[0-9]{6,7}[A-Z]$/', $lic)) return array('error' => 'PalErrNoLicence');
    if ($lic !== bk_clean_licence($licence)) return array('error' => 'PalErrLicence', 'lic' => $lic);
    if (count($p['rows']) > bk_pal_max_rows()) return array('error' => 'PalErrTooBig');
    $str = function ($v, $max) { return is_string($v) && $v !== '' && mb_strlen($v) <= $max ? trim($v) : null; };
    $int = function ($v, $max) { return is_int($v) && $v >= 0 && $v <= $max ? $v : null; };
    $tomorrow = date('Y-m-d', strtotime(bk_today() . ' +1 day'));
    $out = array(); $skipped = 0;
    foreach ($p['rows'] as $r) {
        if (!is_array($r)) { $skipped++; continue; }
        $d = $str($r['d'] ?? null, 100); $c = $str($r['c'] ?? null, 160); $ev = $str($r['ev'] ?? null, 200);
        $dt = is_string($r['dt'] ?? null) ? $r['dt'] : '';
        $pl = $int($r['pl'] ?? null, 65535); $sc = $int($r['sc'] ?? null, 999999);
        $h = $d !== null ? bk_pal_heading($d) : null;
        $okDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dt, $m) && checkdate(intval($m[2]), intval($m[3]), intval($m[1]))
            && $dt >= '1970-01-01' && $dt <= $tomorrow;
        if (!$h || $c === null || $ev === null || !$okDate || $pl === null || $sc === null) { $skipped++; continue; }
        $out[] = array('d' => $d, 'season' => $h['season'], 'c' => $c, 'dt' => $dt, 'ev' => $ev, 'pl' => $pl, 'sc' => $sc,
                       'off' => !empty($r['off']) ? 1 : 0, 'cnt' => array_key_exists('cnt', $r) && empty($r['cnt']) ? 0 : 1);
    }
    return array('rows' => $out, 'skipped' => $skipped);
}

/**
 * One line per round shot. The extranet lists a result under each ranking it counts for (its
 * age class, the scratch, sometimes two spellings of one class): within a heading, a round
 * (date, event, score) is kept as many times as the ranking that lists it most often, taken
 * from the most precise ranking — one age class, then several, then the scratch.
 *
 * 'scr' = the round counts in a scratch ranking. A scratch is shot at the adult distance: a
 * young archer's round it counts (and does not strike out, "non pris en compte") was shot
 * there, not at the distance of their class — the only clue the record gives of a cadet
 * shooting 70 m (bk_pal_round).
 */
function bk_pal_dedupe(array $rows)
{
    $rank = function ($c) {
        $x = bk_pal_category($c);
        if ($x['para']) return 0;
        if ($x['scratch']) return 1;
        return count($x['classes']) === 1 ? 3 : 2;
    };
    $groups = array();
    foreach ($rows as $r) $groups[$r['d']][$r['c']][$r['dt'] . '|' . $r['ev'] . '|' . $r['sc']][] = $r;
    $out = array();
    foreach ($groups as $cats) {
        $prio = array(); $scr = array();
        foreach ($cats as $c => $keys) {
            $prio[$c] = $rank($c);
            if ($prio[$c] !== 1) continue;
            foreach ($keys as $k => $list) foreach ($list as $r) if ($r['cnt']) $scr[$k] = true;
        }
        uksort($cats, function ($a, $b) use ($prio) { return $prio[$b] - $prio[$a]; });
        $done = array();
        foreach ($cats as $c => $keys) {
            foreach ($keys as $k => $list) {
                if (isset($done[$k])) continue;
                $done[$k] = true;
                $most = 0;
                foreach ($cats as $other) $most = max($most, count($other[$k] ?? array()));
                for ($i = 0; $i < $most; $i++) $out[] = $list[min($i, count($list) - 1)] + array('scr' => isset($scr[$k]) ? 1 : 0);
            }
        }
    }
    usort($out, function ($a, $b) { return strcmp($a['dt'], $b['dt']); });
    return $out;
}

/**
 * Stores the rounds of an import (bk_pal_dedupe) for a licence. A season present in the file
 * replaces the one stored, the others stay: the export of one season updates that season only.
 * Returns the number of rows written.
 */
function bk_pal_store($licence, array $rounds)
{
    $lic = StrSafe_DB(bk_clean_licence($licence));
    $seasons = array_values(array_unique(array_map(function ($r) { return intval($r['season']); }, $rounds)));
    if (!$seasons) return 0;
    $now = StrSafe_DB((new DateTime('now', bk_server_tz()))->format('Y-m-d H:i:s'));
    safe_w_sql("START TRANSACTION");
    safe_w_sql("DELETE FROM BookingPalmares WHERE BpLicence = $lic AND BpSeason IN (" . implode(',', $seasons) . ")");
    foreach (array_chunk($rounds, 200) as $chunk) {
        $vals = array();
        foreach ($chunk as $r) {
            $h = bk_pal_heading($r['d']);
            $vals[] = '(' . $lic . ', ' . intval($r['season']) . ', ' . StrSafe_DB($h['name']) . ', ' . StrSafe_DB($r['c']) . ', '
                . StrSafe_DB($r['dt']) . ', ' . StrSafe_DB($r['ev']) . ', ' . intval($r['pl']) . ', ' . intval($r['sc']) . ', '
                . intval($r['off']) . ', ' . intval($r['cnt']) . ', ' . intval($r['scr'] ?? 0) . ', ' . $now . ')';
        }
        safe_w_sql("INSERT INTO BookingPalmares (BpLicence, BpSeason, BpDiscipline, BpCategory, BpDate, BpEvent, BpRank,
            BpScore, BpOfficial, BpCounted, BpScratch, BpImported) VALUES " . implode(', ', $vals));
    }
    safe_w_sql("COMMIT");
    return count($rounds);
}

/**
 * Removes everything imported for a licence (the archer's button, account deletion). Error 1146
 * tolerated: a deletion never stops a page because the schema has not been replayed yet.
 */
function bk_pal_forget($licence)
{
    safe_w_sql("DELETE FROM BookingPalmares WHERE BpLicence = " . StrSafe_DB(bk_clean_licence($licence)), false, array(1146));
}

/** What is stored for a licence: ['n', 'first', 'last' (seasons), 'when' (last import)] or null. */
function bk_pal_status($licence)
{
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n, MIN(BpSeason) AS s0, MAX(BpSeason) AS s1, MAX(BpImported) AS w
        FROM BookingPalmares WHERE BpLicence = " . StrSafe_DB(bk_clean_licence($licence))));
    return $r && intval($r->n) > 0
        ? array('n' => intval($r->n), 'first' => intval($r->s0), 'last' => intval($r->s1), 'when' => (string) $r->w) : null;
}

/* ------------------------------------------------------------------ */
/* Rounds of the statistics                                            */
/* ------------------------------------------------------------------ */

/** Name of the core's French set for a peg code of the national files (1 red, 2 blue, 3 white, 4 pink). */
function bk_pal_peg($n)
{
    $m = array(1 => 'Piquet Rouge', 2 => 'Piquet Bleu', 3 => 'Piquet Blanc', 4 => 'Piquet Rose');
    return $m[intval($n)] ?? '';
}

/**
 * One stored line → a round of lib/stats.php (same keys as bk_stats_entry_rounds, plus 'src' =
 * 'pal', 'conf' and 'badge' = may earn a distinction). $birthYear places the archer in a ranking
 * shared by several classes, else $seasonCls (bk_pal_season_classes). Null for a line that
 * cannot be placed at all.
 */
function bk_pal_round($row, $birthYear, array $seasonCls = array())
{
    $season = intval($row->BpSeason);
    $h = bk_pal_heading('Individuel ' . $row->BpDiscipline . ' ' . $season);
    $cat = bk_pal_category($row->BpCategory);
    if (!$h) return null;
    $code = $h['code'];
    $bow = bk_pal_bow($cat['bow'], $code);

    // Age class: the one named, else the archer's that season (when the ranking names it).
    $mine = bk_pal_cls_for_year($season, intval($birthYear));
    if ($mine === '') $mine = $seasonCls[$season] ?? '';
    $named = array_map(function ($c) use ($season) { return bk_pal_cls_in($c, $season); }, $cat['classes']);
    if (count($named) === 1)                          $cls = $named[0];
    elseif ($mine !== '' && (!$named || in_array($mine, $named, true))) $cls = $mine;
    else                                              $cls = $named[0] ?? '';
    $age = $cls !== '' ? bk_pal_age($cls) : '';

    // Distance and face: written in the label, or the table of what was shot — at the adult
    // distance for a round that counts in a scratch ranking (bk_pal_dedupe).
    $dist = $cat['dist']; $face = $cat['face']; $conf = 'exact';
    if ($dist <= 0) {
        $look = !empty($row->BpScratch) ? bk_pal_lookup($code, $season, array(bk_pal_cls_in('S1', $season)), $bow) : null;
        if (!$look && $cls !== '') $look = bk_pal_lookup($code, $season, array($cls), $bow);
        if (!$look && $named) $look = bk_pal_lookup($code, $season, $named, $bow);
        if ($look) { $dist = $look['dist']; $face = $look['face']; $conf = $look['conf']; }
        else       { $conf = 'unknown'; }
    }

    $score = intval($row->BpScore);
    $honours = null;
    if ($code === 'B' && $score >= 1000) {
        // Beursault: the federation writes honours × 1000 + points (40097 = 40 honours, 97 points).
        $honours = intdiv($score, 1000);
        $score %= 1000;
    }

    $pegs = in_array($code, array('C', 'N', '3'), true);
    $disc = 'other'; $max = 0; $arrows = 0;
    switch ($code) {
        case 'S':
            $disc = $score <= 600 ? 'indoor' : 'other';
            $max = $score <= 600 ? 600 : 1200; $arrows = $score <= 600 ? 60 : 120;
            break;
        case 'F': case 'E': case 'T':
            $max = 720; $arrows = 72;
            if ($score > 720) { $max = 1440; $arrows = 144; break; }
            if ($code === 'F') $disc = 'outdoor_int';
            elseif ($code === 'E') $disc = 'outdoor_nat';
            else $disc = bk_pal_outdoor($bow, $age, $dist, $face);
            break;
        case 'C': $disc = 'field';  $max = $score <= 432 ? 432 : 864;   $arrows = $score <= 432 ? 72 : 144; break;
        case '3': $disc = '3d';     $max = $score <= 528 ? 528 : 1056;  $arrows = $score <= 528 ? 48 : 96;  break;
        case 'N': $disc = 'nature'; $max = $score <= 735 ? 735 : 1470;  $arrows = $score <= 735 ? 42 : 84;  break;
        case 'B': $disc = 'beursault'; $max = 160; $arrows = 40; break;
        case 'D': $max = 1440; $arrows = 144; break;
    }
    if ($cat['para'] || $bow === '') $disc = 'other';

    $faceKey = ''; $faceLabel = ''; $faceW = 0;
    if ($pegs) {
        $peg = bk_pal_peg($dist);
        if ($peg !== '') {
            // bytes: a hash of the face name, used as an ASCII key (the same as lib/stats.php)
            $faceKey = 'p' . substr(md5(mb_strtolower($peg, 'UTF-8')), 0, 6);
            $faceLabel = bk_t('PalPeg' . intval($dist));
        }
        $dist = 0;
    } elseif ($disc !== 'beursault' && $face > 0) {
        $faceW = $face; $faceKey = (string) $face; $faceLabel = $face . ' cm';
    }
    $walk = 0;
    if (in_array($code, array('S', 'F', 'E', 'T'), true) && $dist > 0) {
        $walk = intval($arrows / ($code === 'S' ? 3 : 6)) * 2 * $dist;
    }

    $date = (string) $row->BpDate;
    $official = intval($row->BpOfficial) === 1;
    return array(
        'tour' => 'pal:' . $date . ':' . md5((string) $row->BpEvent), 'code' => '', 'name' => (string) $row->BpEvent,
        'where' => '', 'date' => $date, 'dateTo' => $date,
        'disc' => $disc, 'bow' => $bow, 'bowName' => $bow !== '' ? bk_t('PalBow' . ($pegs && in_array($bow, array('CL', 'CO'), true) ? 'W' : '') . $bow) : $cat['bow'],
        'cls' => $age . ($cat['sex'] === 'F' ? 'F' : 'H'), 'clsName' => $cat['name'],
        'age' => $age, 'sex' => $cat['sex'],
        'dist' => $dist, 'distText' => $dist > 0 ? $dist . 'm' : '',
        'faceW' => $faceW, 'faceKey' => $faceKey, 'faceLabel' => $faceLabel,
        'combo' => $disc . '|' . $bow . '|' . $dist . '|' . $faceKey,
        'score' => $score, 'max' => $max, 'best1' => 0,
        'complete' => true, 'rank' => intval($row->BpRank),
        'arrows' => $arrows, 'walk' => $walk, 'honours' => $honours,
        'src' => 'pal', 'conf' => $conf, 'official' => $official,
        'badge' => $official && intval($row->BpCounted) === 1 && in_array($conf, array('exact', 'data'), true),
    );
}

/**
 * International or national distances of an outdoor round from 2019 (one code for both in the
 * national files): the distance of the class at international level (the badges' table),
 * 60 m or more with a recurve, a compound on the 80 cm face; anything else is national.
 */
function bk_pal_outdoor($bow, $age, $dist, $face)
{
    if ($dist <= 0) return 'outdoor_int';
    if ($bow === 'CO') return $face === 80 ? 'outdoor_int' : 'outdoor_nat';
    if ($bow !== 'CL') return 'outdoor_nat';
    if ($dist >= 60) return 'outdoor_int';
    return $age !== '' && bk_dist_context('outdoor_int', 'CL', $age, $dist . 'm') ? 'outdoor_int' : 'outdoor_nat';
}

/** Every imported round of a licence, oldest first. */
function bk_pal_rounds($licence)
{
    $lic = StrSafe_DB(bk_clean_licence($licence));
    $birth = bk_pal_birth_year($licence);
    $rs = safe_r_sql("SELECT BpSeason, BpDiscipline, BpCategory, BpDate, BpEvent, BpRank, BpScore, BpOfficial, BpCounted, BpScratch
        FROM BookingPalmares WHERE BpLicence = $lic ORDER BY BpDate, BpId");
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[] = $r;
    $seasonCls = bk_pal_season_classes(array_map(function ($r) { return array(intval($r->BpSeason), $r->BpCategory); }, $rows));
    $out = array();
    foreach ($rows as $r) if ($x = bk_pal_round($r, $birth, $seasonCls)) $out[] = $x;
    return $out;
}

/**
 * What an import would bring, for the page to show before anything is written: per season
 * (newest first) and heading, the rounds, those already known on this server (same day, same
 * score) and how each ranking is placed. $rounds = bk_pal_dedupe(); $local = the archer's rounds
 * on this server (bk_stats_local_rounds).
 */
function bk_pal_preview(array $rounds, array $local, $birthYear)
{
    $known = bk_pal_local_keys($local);
    $seasonCls = bk_pal_season_classes(array_map(function ($r) { return array($r['season'], $r['c']); }, $rounds));
    $seasons = array();
    $tot = array('rounds' => 0, 'known' => 0, 'badge' => 0);
    foreach ($rounds as $r) {
        $h = bk_pal_heading($r['d']);
        $row = (object) array('BpSeason' => $r['season'], 'BpDiscipline' => $h['name'], 'BpCategory' => $r['c'], 'BpDate' => $r['dt'],
                              'BpEvent' => $r['ev'], 'BpRank' => $r['pl'], 'BpScore' => $r['sc'], 'BpOfficial' => $r['off'],
                              'BpCounted' => $r['cnt'], 'BpScratch' => $r['scr'] ?? 0);
        $x = bk_pal_round($row, $birthYear, $seasonCls);
        $s = &$seasons[$r['season']][$h['name']];
        if (!$s) $s = array('n' => 0, 'known' => 0, 'cats' => array());
        $s['n']++; $tot['rounds']++;
        if ($x && isset($known[$x['date'] . '|' . $x['score']])) { $s['known']++; $tot['known']++; }
        elseif ($x && $x['badge']) $tot['badge']++;
        $cn = bk_pal_category($r['c'])['name'];
        if ($x) {
            $w = array();
            if ($x['dist'] > 0) $w[] = $x['dist'] . ' m';
            if ($x['faceLabel'] !== '') $w[] = $x['faceLabel'];
            $w = implode(' · ', $w);
            // One line per ranking and placement: a scratch of a young archer may hold two distances.
            $ck = $cn . '|' . $w . '|' . $x['conf'];
            // The discipline tells international from national distances; indoors it would only
            // repeat the distance ("Tir à 18 m · 18 m").
            $disc = $x['disc'] === 'indoor' ? '' : bk_t('DistDisc' . $x['disc']);
            if (!isset($s['cats'][$ck])) $s['cats'][$ck] = array('name' => $cn, 'disc' => $disc, 'where' => $w, 'conf' => $x['conf'], 'n' => 0);
            $s['cats'][$ck]['n']++;
        }
        unset($s);
    }
    krsort($seasons);
    $out = array();
    foreach ($seasons as $season => $heads) {
        foreach ($heads as $name => $s) {
            $out[] = array('season' => $season, 'heading' => $name, 'n' => $s['n'], 'known' => $s['known'], 'cats' => array_values($s['cats']));
        }
    }
    return array('total' => $tot, 'groups' => $out);
}

/** "date|score" of every day of the archer's rounds on this server: an import never counts them twice. */
function bk_pal_local_keys(array $local)
{
    $k = array();
    foreach ($local as $r) {
        $d = $r['date'];
        for ($i = 0; $i < 8 && $d <= $r['dateTo']; $i++) {
            $k[$d . '|' . $r['score']] = true;
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }
    return $k;
}
