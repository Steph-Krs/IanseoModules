<?php
/**
 * SYNCHRO_FFTA — moteur de correspondance des types de compétition.
 *
 * Propose un type ianseo (ToType + sous-règle) à partir d'une épreuve de l'extranet,
 * d'après le fichier éditable MAPPING_TYPES_COMPETITION.md (racine du projet) et les
 * règles françaises réelles de ianseo (Modules/Sets/FR/sets.php).
 *
 * La proposition n'est jamais imposée : create.php la présélectionne dans un menu que
 * l'organisateur peut corriger. En cas de doute, on renvoie « non créable » plutôt que
 * de deviner.
 */

/** Racine du projet (au-dessus de htdocs), où vivent les fichiers de référence. */
function sfa_project_root(): string
{
    return dirname(HTDOCS);
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

/** Sections du fichier de mapping (par en-tête ##/###). */
function sfa_mapping_sections(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $file = sfa_project_root() . '/MAPPING_TYPES_COMPETITION.md';
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
        return $none + ['why' => 'Discipline extranet non reconnue.'];
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
