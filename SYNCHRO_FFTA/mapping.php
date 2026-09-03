<?php
/**
 * SYNCHRO_FFTA — moteur de correspondance des types de compétition.
 *
 * Propose un type ianseo (ToType + sous-règle) à partir d'une épreuve de l'extranet,
 * d'après le fichier éditable MAPPING_TYPES_COMPETITION.md (embarqué dans le module — voir
 * sfa_mapping_file_path()) et les règles françaises réelles de ianseo (Modules/Sets/FR/sets.php).
 *
 * La proposition n'est jamais imposée : create.php la présélectionne dans un menu que
 * l'organisateur peut corriger. En cas de doute, on renvoie « non créable » plutôt que
 * de deviner.
 */

/**
 * Le module BOOKING (inscriptions en ligne, fusionné dans AUTH) est-il installé et actif ?
 * Détection par fichier — BOOKING vit sous Modules/Custom/AUTH/, hors du balayage de
 * module_exists() (réservé à Modules/*). Aucun require : juste savoir s'il est là.
 */
function sfa_booking_present(): bool
{
    global $CFG;

    return !empty($CFG->USERAUTH)
        && is_file($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/admin/competition.php');
}

/** Enlève les accents, met en majuscules, compacte les espaces. */
function sfa_normalize(string $s): string
{
    $s = strtr($s, [
        'À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A','Ã'=>'A','Å'=>'A','Ç'=>'C',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Î'=>'I','Ï'=>'I','Í'=>'I','Ì'=>'I',
        'Ô'=>'O','Ö'=>'O','Ó'=>'O','Ò'=>'O','Õ'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U','Ú'=>'U',
        'à'=>'A','â'=>'A','ä'=>'A','á'=>'A','ç'=>'C','è'=>'E','é'=>'E','ê'=>'E','ë'=>'E',
        'î'=>'I','ï'=>'I','ô'=>'O','ö'=>'O','ù'=>'U','û'=>'U','ü'=>'U',
    ]);
    $s = mb_strtoupper($s, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Premier token entre backticks d'une cellule Markdown, ou ''. */
function sfa_backtick(string $cell): string
{
    return preg_match('/`([^`]+)`/', $cell, $m) ? trim($m[1]) : '';
}

/**
 * Règles françaises réelles de ianseo, lues (non exécutées) dans sets.php.
 * Retour : ['types' => [ToType,...], 'rules' => [ToType => [cle => 'SetFRxxx']]].
 * Le d_SubRule attendu par le formulaire natif = cle + 1 (cf. Tournament/index.php).
 */
function sfa_fr_sets(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $file  = $GLOBALS['CFG']->DOCUMENT_PATH . 'Modules/Sets/FR/sets.php';
    $src   = is_readable($file) ? file_get_contents($file) : '';
    $src   = preg_replace('~^\s*//.*$~m', '', $src);   // ignore les lignes commentées
    $types = [];
    $rules = [];

    if (preg_match('/\$AllowedTypes\s*=\s*array\(([^)]*)\)/', $src, $m)) {
        foreach (preg_split('/\s*,\s*/', trim($m[1])) as $v) {
            if ($v !== '' && ctype_digit($v)) {
                $types[] = (int) $v;
            }
        }
    }

    // $SetType['FR']['rules']["3"]["12"]='SetFRTAE-Valides';
    if (preg_match_all(
        '/\$SetType\[\'FR\'\]\[\'rules\'\]\[\"?(\d+)\"?\]\[\"?(\d+)\"?\]\s*=\s*\'([^\']+)\'/',
        $src, $mm, PREG_SET_ORDER
    )) {
        foreach ($mm as $r) {
            $rules[(int) $r[1]][(int) $r[2]] = $r[3];
        }
    }

    return $cache = ['types' => $types, 'rules' => $rules];
}

/** d_SubRule (index attendu par le formulaire natif) pour une sous-règle d'un type, ou 0. */
function sfa_subrule_index(int $toType, string $subRule): int
{
    foreach (sfa_fr_sets()['rules'][$toType] ?? [] as $key => $code) {
        if ($code === $subRule) {
            return $key + 1;
        }
    }

    return 0;
}

/** Lit et découpe les lignes d'un tableau Markdown en gérant les pipes échappés `\|`. */
function sfa_md_rows(array $lines): array
{
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '|') {
            continue;
        }
        if (preg_match('/^\|[\s:|-]+\|?$/', $line)) {   // ligne de séparation ---|---
            continue;
        }
        $line  = str_replace('\\|', "\x01", $line);     // protège les pipes échappés
        $cells = array_map(function ($c) {
            return trim(str_replace("\x01", '|', $c));
        }, explode('|', trim($line, '|')));
        $rows[] = $cells;
    }

    return $rows;
}

/**
 * Chemin du fichier de correspondance. Embarqué DANS le module (pas à la racine du projet) et
 * inscrit dans version.json → files[] : une mise à jour du module (admin/update.php) le
 * resynchronise depuis GitHub sur tous les serveurs — pousser une correction sur le dépôt suffit
 * à la propager partout, sans copie manuelle serveur par serveur.
 */
function sfa_mapping_file_path(): string
{
    return __DIR__ . '/MAPPING_TYPES_COMPETITION.md';
}

/** Sections du fichier de mapping (par en-tête ##/###). */
function sfa_mapping_sections(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $file = sfa_mapping_file_path();
    $cache = [];
    if (!is_readable($file)) {
        return $cache;
    }

    $cur = '';
    foreach (preg_split('/\R/', file_get_contents($file)) as $line) {
        if (preg_match('/^#{2,3}\s+(.*)$/', $line, $m)) {
            $cur = sfa_normalize($m[1]);
            $cache[$cur] = [];
        } elseif ($cur !== '') {
            $cache[$cur][] = $line;
        }
    }

    return $cache;
}

/** Disciplines extranet : [code normalisé de libellé => code discipline]. */
function sfa_disciplines(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'DISCIPLINE') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $code = sfa_backtick($r[0] ?? '');
            $lib  = sfa_normalize($r[1] ?? '');
            if ($code !== '' && $lib !== '' && $code !== 'CODE') {
                $out[$lib] = $code;
            }
        }
        break;
    }

    return $out;
}

/** Lignes du tableau de correspondance §3 : disc, format, champ, toType, sub. */
function sfa_base_rows(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'TABLEAU DE CORRESPONDANCE') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $disc = sfa_backtick($r[0] ?? '');
            if ($disc === '') {
                continue;   // en-tête, ligne « ajouter des lignes »…
            }
            $out[] = [
                'disc'   => $disc,
                'fmt'    => sfa_normalize($r[1] ?? ''),
                'champ'  => sfa_backtick($r[2] ?? ''),
                'toType' => ctype_digit($r[3] ?? '') ? (int) $r[3] : 0,
                'sub'    => sfa_backtick($r[4] ?? ''),
            ];
        }
        break;
    }

    return $out;
}

/** Règles d'affinage §3bis : [toType => [ ['re'=>regex, 'sub'=>SetFRxxx], ... ]]. */
function sfa_refine_rules(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (!preg_match('/^TYPE\s+(\d+)/', $title, $m)) {
            continue;
        }
        $toType = (int) $m[1];
        foreach (sfa_md_rows($lines) as $r) {
            $re  = sfa_backtick($r[1] ?? '');
            $sub = sfa_backtick($r[2] ?? '');
            if ($re !== '' && $sub !== '') {
                $out[$toType][] = ['re' => $re, 'sub' => $sub];
            }
        }
    }

    return $out;
}

/**
 * Propose un type ianseo pour une épreuve de l'extranet.
 *
 * @param string $disciplineText texte « Discipline » de la page épreuve (ex. « Tir 3D — 1 X 24 CIBLES »)
 * @param string $formatText     libellé de format / caractéristiques
 * @param string $typeEpreuve    « Type d'épreuve » (individuel / par équipe / championnat…)
 * @param string $name           nom de l'épreuve (pour l'affinage regex)
 * @param bool   $valideParaTag  l'épreuve porte le tag « Valide + Para » → sous-règle Para
 * @return array ['creatable'=>bool,'toType'=>int,'sub'=>string,'subIdx'=>int,'why'=>string]
 */
function sfa_propose(string $disciplineText, string $formatText, string $typeEpreuve, string $name, bool $valideParaTag = false): array
{
    $none = ['creatable' => false, 'toType' => 0, 'sub' => '', 'subIdx' => 0, 'why' => ''];

    // 1) Discipline extranet → code, par le plus long libellé contenu dans le texte.
    $normDisc = sfa_normalize($disciplineText);
    $disc = '';
    $best = 0;
    foreach (sfa_disciplines() as $lib => $code) {
        if ($lib !== '' && strpos($normDisc, $lib) !== false && mb_strlen($lib) > $best) {
            $disc = $code;
            $best = mb_strlen($lib);
        }
    }
    if ($disc === '') {
        $why = is_readable(sfa_mapping_file_path())
            ? 'Discipline extranet non reconnue.'
            : 'Fichier de correspondance introuvable sur ce serveur (' . sfa_mapping_file_path()
              . ') — mettez à jour le module SYNCHRO_FFTA (admin/update.php) pour le récupérer '
              . 'depuis GitHub.';

        return $none + ['why' => $why];
    }

    // 2) Meilleure ligne §3 pour cette discipline (format + individuel/équipe).
    $normFmt  = sfa_normalize($formatText . ' ' . $disciplineText);
    $isTeam   = strpos(sfa_normalize($typeEpreuve), 'EQUIPE') !== false;
    $row      = null;
    $rowScore = PHP_INT_MIN;
    foreach (sfa_base_rows() as $cand) {
        if ($cand['disc'] !== $disc) {
            continue;
        }
        $score = 0;
        if ($cand['fmt'] !== '' && strpos($normFmt, $cand['fmt']) !== false) {
            $score += mb_strlen($cand['fmt']);
        }
        $champTeam = ($cand['champ'] !== '' && $cand['champ'][0] === 'E');
        if ($champTeam === $isTeam) {
            $score += 5;
        } else {
            $score -= 50;   // ne pas coller une ligne équipe à un individuel, et inversement
        }
        if ($score > $rowScore) {
            $rowScore = $score;
            $row = $cand;
        }
    }

    if ($row === null || $row['toType'] === 0) {
        return $none + ['why' => 'Type ianseo indisponible pour cette discipline (voir MAPPING_TYPES_COMPETITION.md).'];
    }

    $toType = $row['toType'];
    $sub    = $row['sub'];

    // 3) Affinage §3bis par le nom de l'épreuve.
    foreach (sfa_refine_rules()[$toType] ?? [] as $rule) {
        if (@preg_match('/' . str_replace('/', '\/', $rule['re']) . '/iu', sfa_normalize($name))) {
            $sub = $rule['sub'];
            break;
        }
    }

    // 4) Tag « Valide + Para » → sous-règle Para du type (englobe valides et para).
    // Une compétition Valide+Para se crée en une seule compétition ianseo avec cette règle.
    if ($valideParaTag) {
        $paraByType = [3 => 'SetFRTAE-Para', 6 => 'SetFrSelectifPara',
                       7 => 'SetFrSelectifPara', 8 => 'SetFrSelectifPara'];
        if (isset($paraByType[$toType]) && sfa_subrule_index($toType, $paraByType[$toType]) > 0) {
            $sub = $paraByType[$toType];
        }
    }

    $subIdx = $sub !== '' ? sfa_subrule_index($toType, $sub) : 0;

    return [
        'creatable' => true,
        'toType'    => $toType,
        'sub'       => $sub,
        'subIdx'    => $subIdx,
        'why'       => '',
    ];
}

/**
 * Famille de discipline par ToType, lue dans la colonne « Famille » du §2 (`### Types de
 * compétition (ToType)`). Retour : [ToType => 'TAE'|'18m'|'Campagne'|'3D'|'Beursault'|...].
 */
function sfa_session_families(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'TYPES DE COMPETITION') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $toType = sfa_backtick($r[0] ?? '');
            $fam    = sfa_backtick($r[3] ?? '');
            if (ctype_digit($toType) && $fam !== '') {
                $out[(int) $toType] = $fam;
            }
        }
        break;
    }

    return $out;
}

/**
 * Bornes du nombre d'archers par cible/peloton (§5.A), par famille de discipline.
 * Retour : [famille => ['min'=>int,'max'=>int,'default'=>int,'fixed'=>bool]].
 * `fixed` (min===max) signale un champ à afficher grisé, sans choix possible (ex. Beursault).
 */
function sfa_rythme_bounds(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'ARCHERS PAR CIBLE') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $fam = sfa_backtick($r[0] ?? '');
            if ($fam === '' || !ctype_digit($r[1] ?? '') || !ctype_digit($r[2] ?? '') || !ctype_digit($r[3] ?? '')) {
                continue;
            }
            $min = (int) $r[1];
            $max = (int) $r[2];
            $out[$fam] = ['min' => $min, 'max' => $max, 'default' => (int) $r[3], 'fixed' => $min === $max];
        }
        break;
    }

    return $out;
}

/**
 * Configuration du nombre de pelotons autorisés (§5.B), par famille de discipline.
 * Retour : [famille => ['mode'=>'stepper','default'=>int]]
 *        ou [famille => ['mode'=>'toggle','off'=>int,'on'=>int]] (case « pelotons bis autorisés »).
 */
function sfa_pelotons_config(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'PELOTONS AUTORISES') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $fam  = sfa_backtick($r[0] ?? '');
            $mode = sfa_normalize($r[1] ?? '');
            if ($fam === '' || $mode === '') {
                continue;
            }
            if (strpos($mode, 'STEPPER') !== false) {
                $out[$fam] = ['mode' => 'stepper', 'default' => ctype_digit($r[2] ?? '') ? (int) $r[2] : 24];
            } elseif (strpos($mode, 'TOGGLE') !== false) {
                $out[$fam] = [
                    'mode' => 'toggle',
                    'off'  => ctype_digit($r[3] ?? '') ? (int) $r[3] : 0,
                    'on'   => ctype_digit($r[4] ?? '') ? (int) $r[4] : 0,
                ];
            }
        }
        break;
    }

    return $out;
}

/**
 * Libellé du rythme de tir (§5.D), pour le commentaire de planning du départ.
 * Retour : [famille => [archers => 'AB-CD']], la famille '*' valant pour toutes les disciplines.
 */
function sfa_rythme_labels(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'LIBELLE DU RYTHME') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $fam = sfa_backtick($r[0] ?? '');
            $lib = sfa_backtick($r[2] ?? '');
            if ($fam !== '' && $lib !== '' && ctype_digit($r[1] ?? '')) {
                $out[$fam][(int) $r[1]] = $lib;
            }
        }
        break;
    }

    return $out;
}

/**
 * Libellé du rythme pour une famille et un nombre d'archers/cible : la ligne de la famille prime
 * sur la ligne générique '*'. Chaîne vide si inconnu — l'appelant omet alors la mention plutôt
 * que d'inventer un libellé.
 */
function sfa_rythme_label(string $family, int $archers): string
{
    $all = sfa_rythme_labels();

    return $all[$family][$archers] ?? $all['*'][$archers] ?? '';
}

/**
 * Durées de départ connues (§5.C), par famille et nombre d'archers/cible.
 * Retour : [famille => [archers => minutes]]. Table volontairement incomplète : une combinaison
 * absente laisse le champ « Durée » libre côté formulaire, sans valeur devinée.
 */
function sfa_session_durations(): array
{
    $out = [];
    foreach (sfa_mapping_sections() as $title => $lines) {
        if (strpos($title, 'DUREE DU DEPART') === false) {
            continue;
        }
        foreach (sfa_md_rows($lines) as $r) {
            $fam = sfa_backtick($r[0] ?? '');
            if ($fam === '' || !ctype_digit($r[1] ?? '') || !ctype_digit($r[2] ?? '')) {
                continue;
            }
            $out[$fam][(int) $r[1]] = (int) $r[2];
        }
        break;
    }

    return $out;
}
