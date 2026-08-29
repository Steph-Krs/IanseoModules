<?php
/**
 * Endpoint AJAX du module SYNCHRO_FFTA — flux « dépôt ».
 * Une action par étape de l'assistant. Aucun dépôt n'est effectué ici :
 * la chaîne s'arrête volontairement à l'affichage du cadre de dépôt.
 */
// Avant tout chargement : toute sortie parasite (warning/notice, BOM) émise avant le JSON
// fausserait le Content-Length calculé par JsonOut (strlen du JSON seul) → réponse
// tronquée côté navigateur. On capture donc dès la première ligne, et on jette avant d'émettre.
ob_start();

define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/ExtranetClient.php');

CheckTourSession(true);
checkFullACL(AclCompetition, 'cExport', AclReadOnly);

// Serveur cible — production : le dépôt est validé et testé en conditions réelles.
$ITXT_BASE = ExtranetClient::BASE_PROD;

$action = $_POST['itxt_action'] ?? '';

/** Sortie JSON propre : on jette d'abord tout ce qui aurait pu être émis. */
function itxt_json($data) {
    while (ob_get_level()) { ob_end_clean(); }
    JsonOut($data);
}

/** Cookie jar de la session extranet, créé à la connexion et détruit à la déconnexion. */
function itxt_cookie_file(bool $create = false): ?string
{
    if ($create) {
        itxt_cookie_destroy();
        $f = tempnam(sys_get_temp_dir(), 'itxt_ck_');
        chmod($f, 0600);
        $_SESSION['ITXT_COOKIE'] = $f;

        return $f;
    }

    $f = $_SESSION['ITXT_COOKIE'] ?? null;

    return ($f && file_exists($f)) ? $f : null;
}

function itxt_cookie_destroy(): void
{
    $f = $_SESSION['ITXT_COOKIE'] ?? null;
    if ($f && file_exists($f)) {
        @unlink($f);
    }
    unset($_SESSION['ITXT_COOKIE']);
}

/**
 * Cookie extranet publié par le module AUTH (convention FFTA_EXTRANET_*, voir
 * CLAUDE.md racine), utilisable seulement s'il pointe sur le MÊME serveur que nous.
 * Il appartient à AUTH : on le lit, on ne le détruit jamais.
 */
function itxt_shared_cookie(string $base): ?string
{
    $c = $_SESSION['FFTA_EXTRANET_COOKIE'] ?? '';
    $b = $_SESSION['FFTA_EXTRANET_BASE']   ?? '';

    return ($c !== '' && rtrim($b, '/') === rtrim($base, '/') && file_exists($c)) ? $c : null;
}

/** Cookie du module s'il existe, sinon celui d'AUTH. */
function itxt_any_cookie(string $base): ?string
{
    return itxt_cookie_file() ?? itxt_shared_cookie($base);
}

function itxt_client(string $base): ExtranetClient
{
    $f = itxt_any_cookie($base);
    if (!$f) {
        itxt_json(['ok' => false, 'msg' => 'Aucune session extranet — connecte-toi d\'abord.', 'relogin' => true]);
    }

    return new ExtranetClient($f, $base);
}

// ── Rôle extranet aligné sur la vue AUTH (aucune UI de sélection quand AUTH est présent) ─
// Même principe que session.php (flux création) — dupliqué ici car ce flux garde encore
// ses propres helpers de cookie (itxt_*), non migrés sur session.php.

/** AUTH (module de comptes) est-il actif pour cette session ? */
function itxt_auth_present(): bool
{
    global $CFG;

    return !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
}

/** Enlève les accents, met en majuscules, compacte les espaces (copie de sfa_normalize). */
function itxt_normalize(string $s): string
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

/**
 * Nom de la structure AUTH actuellement active, tel que connu depuis dirigeant.ffta.fr
 * (AUTH_VIEWS — même source de noms que l'extranet). Voir sfa_auth_view_name (session.php).
 */
function itxt_auth_view_name(): string
{
    $role  = $_SESSION['AUTH_ROLE']  ?? '';
    $scope = (string) ($_SESSION['AUTH_SCOPE'] ?? '');
    foreach (($_SESSION['AUTH_VIEWS'] ?? []) as $v) {
        if (($v['role'] ?? '') === $role && (string) ($v['scope'] ?? '') === $scope) {
            return trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($v['label'] ?? '')));
        }
    }

    return '';
}

/**
 * Valeur de rôle extranet (chxMxDrx) correspondant à la vue AUTH courante (AUTH_ROLE), ou
 * null si AUTH absent ou aucune correspondance. CLUB : pas de code d'agrément dans le
 * libellé extranet — un compte peut gérer PLUSIEURS clubs (cas réel vérifié : « Club
 * Fédération » est une structure administrative bien réelle, pas un leurre à écarter
 * systématiquement) → on départage alors par le NOM de la structure AUTH active
 * (itxt_auth_view_name), le seul signal fiable pour cela. Voir sfa_auth_matching_role
 * (session.php) pour la version jumelle du flux création.
 */
function itxt_auth_matching_role(array $roles): ?string
{
    $authRole = $_SESSION['AUTH_ROLE'] ?? '';
    $pattern  = [
        'FED'   => '/F[ée]d[ée]ration/iu',
        'ADMIN' => '/F[ée]d[ée]ration/iu',
        'CR'    => '/R[ée]gional|Ligue/iu',
        'CD'    => '/D[ée]partement(al)?/iu',
        'CLUB'  => '/Club/iu',
    ][$authRole] ?? null;

    if ($pattern === null) {
        return null;
    }

    $candidates = [];
    foreach ($roles as $r) {
        if (stripos($r['label'], 'informations personnelles') !== false) {
            continue;   // gestion de compte, pas un niveau organisationnel
        }
        if (preg_match($pattern, $r['label'])) {
            $candidates[] = $r;
        }
    }

    if (!$candidates) {
        return null;
    }
    if (count($candidates) === 1) {
        return $candidates[0]['value'];
    }

    $name = itxt_normalize(itxt_auth_view_name());
    if ($name !== '') {
        foreach ($candidates as $c) {
            if (strpos(itxt_normalize($c['label']), $name) !== false) {
                return $c['value'];
            }
        }
    }

    return $candidates[0]['value'];
}

/**
 * Si AUTH est présent, aligne le rôle extranet sur sa vue courante (bascule silencieuse,
 * sans UI) et retourne les rôles à jour. Sinon, ou sans correspondance, retourne les rôles
 * reçus tels quels — le sélecteur manuel de la page reste alors la seule voie.
 */
function itxt_sync_role_with_auth(ExtranetClient $client, array $roles): array
{
    if (!itxt_auth_present()) {
        return $roles;
    }
    $target = itxt_auth_matching_role($roles);
    if ($target === null) {
        return $roles;
    }

    $current = null;
    foreach ($roles as $r) {
        if (!empty($r['selected'])) {
            $current = $r['value'];
            break;
        }
    }
    if ($current === $target) {
        return $roles;
    }

    $sw = $client->switchRole($target);

    return !empty($sw['ok']) ? $sw['roles'] : $roles;
}

/** Compétition ianseo courante : sert au pré-remplissage et au rapprochement. */
function itxt_tournament(): stdClass
{
    $q = safe_r_sql('SELECT ToName, ToCommitee, ToComDescr, ToWhere, ToVenue, ToWhenFrom, ToWhenTo,
        ToCategory, ToTypeSubRule
        FROM Tournament WHERE ToId=' . intval($_SESSION['TourId']));

    $t = safe_fetch($q);
    // La ville de la compétition vit dans « Ville » (ToVenue) — ce que l'export FFTA utilise —
    // ou, pour une compétition créée à la main, dans « Lieu » (ToWhere). On expose la bonne.
    $t->City = ($t->ToVenue !== '') ? $t->ToVenue : $t->ToWhere;

    return $t;
}

/** Date d'un <input type="date"> (AAAA-MM-JJ) → format attendu par l'extranet (JJ/MM/AAAA). */
function itxt_date_fr(string $iso, string $fallback): string
{
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) ? $iso : $fallback;

    return date('d/m/Y', strtotime($d));
}

/**
 * Code discipline extranet (search[Discipline]) de la compétition ianseo.
 * Même correspondance que Modules/Sets/FR/exports/index.php ; sert uniquement à filtrer
 * la liste, jamais à produire le TXT. Retourne '' si la catégorie n'est pas reconnue.
 */
function itxt_discipline(stdClass $t): string
{
    switch ((int) $t->ToCategory) {
        case 1:
            if ($t->ToTypeSubRule === 'SetFrBeursault') {
                return 'B';
            }
            if ($t->ToWhenFrom >= '2019-01-01') {
                return 'T';
            }

            return $t->ToTypeSubRule === 'SetFRChampsFederal' ? 'E' : 'F';
        case 2:  return 'S';
        case 4:  return 'C';
        case 8:  return '3';
        case 16: return 'B';
    }

    return '';
}

function itxt_discipline_label(string $code): string
{
    $labels = [
        'T' => 'Tir à l\'Arc Extérieur', 'S' => 'Tir à 18m', 'C' => 'Tir en Campagne',
        '3' => 'Tir 3D', 'B' => 'Tir Beursault', 'F' => 'Tir Fita', 'E' => 'Tir Fédéral',
        'H' => 'Para-tir à l\'arc en extérieur', 'I' => 'Para-tir à l\'arc à 18m',
    ];

    return $labels[$code] ?? '';
}

/** La compétition ianseo comporte-t-elle des catégories para ? */
function itxt_has_para(stdClass $t): bool
{
    if (stripos((string) $t->ToTypeSubRule, 'Para') !== false) {
        return true;   // sous-règle Valide+Para (SetFRTAE-Para, SetFrSelectifPara…)
    }
    $q = safe_r_sql('SELECT 1 FROM Divisions
        WHERE DivTournament=' . intval($_SESSION['TourId']) . ' AND DivIsPara=1 LIMIT 1');

    return safe_num_rows($q) > 0;
}

/**
 * Disciplines extranet acceptables pour cette compétition. Une compétition Valide+Para
 * concerne DEUX épreuves extranet (valides + para) — l'extranet ne filtre que sur une
 * discipline, donc au-delà d'une, on élargit et on filtre côté module.
 * @return string[] ex. ['T','H'] (TAE valide+para) ou ['T'] (valides seul)
 */
function itxt_disciplines(stdClass $t): array
{
    $base = itxt_discipline($t);
    if ($base === '') {
        return [];
    }
    $set  = [$base];
    $para = ['T' => 'H', 'S' => 'I'];   // contrepartie para (extérieur / 18m)
    if (isset($para[$base]) && itxt_has_para($t)) {
        $set[] = $para[$base];
    }

    return $set;
}

/**
 * Génère le TXT résultats en appelant l'export OFFICIEL (Modules/Sets/FR/exports/index.php),
 * jamais en le réimplémentant. Requête HTTP interne portant la session ianseo courante ;
 * l'export renvoie un octet-stream quand le fichier est produit (sinon le formulaire HTML).
 *
 * @param string $lev  S = Sélectif (valides), SP = Sélectif Para. Ne JAMAIS utiliser N
 *                     (Championnat de France) : sans filtre, interdit dans ce module.
 * @return array|null ['content'=>string, 'filename'=>string] (nom officiel A+Discipline+Agrément.txt
 *                     lu dans l'en-tête Content-Disposition de l'export), ou null si échec
 */
function itxt_generate_txt(string $lev): ?array
{
    global $CFG;

    $sid  = session_id();
    $name = session_name();
    session_write_close();   // libère le verrou : la requête interne partage la même session

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url    = $scheme . '://' . $host . $CFG->ROOT_DIR
            . 'Modules/Sets/FR/exports/index.php?lev=' . urlencode($lev);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,   // on lit l'en-tête pour le nom de fichier officiel
        CURLOPT_COOKIE         => $name . '=' . $sid,   // même session ianseo
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,   // requête vers soi-même (certif local possible)
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp   = curl_exec($ch);
    $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    @session_start();   // réouvre la session pour la suite du script

    // TXT généré = octet-stream ; sinon (lev invalide, pas de données) = page HTML.
    if ($code !== 200 || stripos($ctype, 'octet-stream') === false || $resp === false) {
        return null;
    }

    $headers = substr($resp, 0, $hsize);
    $body    = substr($resp, $hsize);

    // Nom de fichier OFFICIEL généré par l'export (A + Discipline + Agrément + .txt).
    $filename = 'export.txt';
    if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^"\r\n;]+)"?/i', $headers, $m)) {
        $filename = trim($m[1]);
    }

    return ['content' => $body, 'filename' => $filename];
}

/**
 * Nombre d'inscrits par côté (valides / para) dans la compétition ianseo.
 * Sert à décider où déposer : côté para → épreuve para, côté valides → épreuve valides.
 */
function itxt_side_counts(): array
{
    // Seules les divisions « athlète » (DivAthlete='1') sont des participants ;
    // les autres (équipes, divisions techniques) ne se déposent pas.
    $q = safe_r_sql('SELECT DivIsPara AS p, COUNT(*) AS n FROM Entries
        INNER JOIN Divisions ON DivId=EnDivision AND DivTournament=EnTournament
        WHERE EnTournament=' . intval($_SESSION['TourId']) . " AND DivAthlete='1'
        GROUP BY DivIsPara");

    $out = ['valides' => 0, 'para' => 0];
    while ($r = safe_fetch($q)) {
        if ((int) $r->p === 1) {
            $out['para'] = (int) $r->n;
        } else {
            $out['valides'] = (int) $r->n;
        }
    }

    return $out;
}

/** Score de ressemblance entre une ligne extranet et la compétition ianseo. */
function itxt_score(array $ev, stdClass $t): int
{
    $score = 0;

    $dates = [];
    foreach (preg_split('/\s+/', $ev['dates']) as $d) {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $d, $m)) {
            $dates[] = "$m[3]-$m[2]-$m[1]";
        }
    }
    if (in_array($t->ToWhenFrom, $dates, true)) {
        $score += 50;
    }
    if (in_array($t->ToWhenTo, $dates, true)) {
        $score += 20;
    }

    if ($t->ToCommitee !== '' && strpos($ev['organisateur'], $t->ToCommitee) !== false) {
        $score += 40;
    }

    foreach ([[$t->City, $ev['lieu'], 25], [$t->ToName, $ev['nom'], 20]] as [$a, $b, $w]) {
        if ($a === '' || $b === '') {
            continue;
        }
        similar_text(mb_strtoupper($a), mb_strtoupper($b), $pct);
        $score += (int) round($w * $pct / 100);
    }

    return $score;
}

switch ($action) {

    // Session extranet déjà ouverte pour cette session ianseo ? Le mot de passe
    // n'est demandé qu'une fois : tant que le cookie vit, on reprend la main.
    case 'status':
        $own    = itxt_cookie_file();
        $shared = $own ? null : itxt_shared_cookie($ITXT_BASE);
        $f      = $own ?? $shared;
        if (!$f) {
            itxt_json(['ok' => true, 'logged' => false]);
        }

        $client = new ExtranetClient($f, $ITXT_BASE);
        $res    = $client->session();
        if (!$res['ok']) {
            // Hors ligne : la session n'est pas morte, on garde le cookie et on le dit.
            if (!empty($res['offline'])) {
                itxt_json(['ok' => true, 'logged' => false, 'offline' => true, 'msg' => $res['msg'] ?? '']);
            }
            if ($own) {
                itxt_cookie_destroy();   // le cookie d'AUTH ne nous appartient pas : on n'y touche pas
            }
            itxt_json(['ok' => true, 'logged' => false]);
        }

        // AUTH présent : le rôle extranet suit sa vue, sans sélecteur manuel (index.php).
        $roles = itxt_sync_role_with_auth($client, $res['roles']);
        itxt_json([
            'ok'     => true,
            'logged' => true,
            'roles'  => $roles,
            'shared' => $shared !== null,   // session ouverte par la connexion ianseo (AUTH)
        ]);
        break;

    case 'login':
        $user = $_POST['itxt_user'] ?? '';
        $pass = $_POST['itxt_pass'] ?? '';
        unset($_POST['itxt_user'], $_POST['itxt_pass']);

        $client = new ExtranetClient(itxt_cookie_file(true), $ITXT_BASE);
        $res    = $client->login($user, $pass);

        $user = str_repeat("\0", max(1, strlen($user)));
        $pass = str_repeat("\0", max(1, strlen($pass)));
        unset($user, $pass);

        if (!$res['ok']) {
            itxt_cookie_destroy();
            itxt_json($res);
        }

        // AUTH présent : le rôle extranet suit sa vue, sans sélecteur manuel (index.php).
        $roles = itxt_sync_role_with_auth($client, $res['roles']);

        $t = itxt_tournament();
        itxt_json([
            'ok'    => true,
            'base'  => $ITXT_BASE,
            'roles' => $roles,
            'tour'  => [
                'nom'       => $t->ToName,
                'lieu'      => $t->City,
                'club'      => $t->ToCommitee . ' — ' . $t->ToComDescr,
                'du'        => date('d/m/Y', strtotime($t->ToWhenFrom)),
                'au'        => date('d/m/Y', strtotime($t->ToWhenTo)),
            ],
        ]);
        break;

    case 'role':
        $client = itxt_client($ITXT_BASE);
        itxt_json($client->switchRole($_POST['itxt_role'] ?? ''));
        break;

    case 'list':
        $client = itxt_client($ITXT_BASE);
        $t      = itxt_tournament();

        $from = itxt_date_fr($_POST['itxt_from'] ?? '', $t->ToWhenFrom);
        $to   = itxt_date_fr($_POST['itxt_to']   ?? '', $t->ToWhenTo);

        $discSet = itxt_disciplines($t);
        $fDisc   = !empty($_POST['itxt_f_disc']) && !empty($discSet);
        $fOrg    = !empty($_POST['itxt_f_org'])  && $t->ToCommitee !== '';

        // L'extranet ne filtre que sur UNE discipline. Un seul code → filtre extranet ;
        // plusieurs (Valide+Para) → on demande tout puis on filtre au jeu côté module.
        $extDisc = ($fDisc && count($discSet) === 1) ? $discSet[0] : 'all';

        $res = $client->listEvents($from, $to, $extDisc);
        if (!$res['ok']) {
            itxt_json($res);
        }

        $res['total'] = count($res['events']);

        // Filtre discipline côté module quand le jeu compte plusieurs disciplines.
        if ($fDisc && count($discSet) > 1) {
            $labels = array_map('itxt_discipline_label', $discSet);
            $res['events'] = array_values(array_filter($res['events'], function ($ev) use ($labels) {
                $carac = ltrim($ev['carac']);
                foreach ($labels as $lab) {
                    if ($lab !== '' && stripos($carac, $lab) === 0) {   // discipline en tête des caractéristiques
                        return true;
                    }
                }
                return false;
            }));
        }

        if ($fOrg) {
            $res['events'] = array_values(array_filter($res['events'], function ($ev) use ($t) {
                return strpos($ev['organisateur'], $t->ToCommitee) !== false;
            }));
        }

        // Regroupe la ligne valides et la ligne para d'une même compétition (para_id).
        $res['events'] = ExtranetClient::groupPara($res['events']);

        $discLabels = implode(' + ', array_filter(array_map('itxt_discipline_label', $discSet)));
        $res['filters'] = [
            'discipline'  => ['code' => implode('+', $discSet), 'label' => $discLabels, 'on' => $fDisc],
            'agrement'    => ['code' => $t->ToCommitee, 'on' => $fOrg],
        ];

        $best = -1;
        foreach ($res['events'] as $i => &$ev) {
            $ev['score'] = itxt_score($ev, $t);
            if ($best < 0 || $ev['score'] > $res['events'][$best]['score']) {
                $best = $i;
            }
        }
        unset($ev);

        $res['suggested'] = ($best >= 0 && $res['events'][$best]['score'] >= 60)
            ? $res['events'][$best]['id'] : null;
        itxt_json($res);
        break;

    case 'event':
        $client = itxt_client($ITXT_BASE);
        $res    = $client->event($_POST['itxt_id'] ?? '');

        // Le cadre de dépôt est chargé dans la foulée : c'est ce que l'utilisateur
        // veut voir, inutile de le lui faire demander par un clic de plus.
        if (!empty($res['ok']) && !empty($res['can_insert'])) {
            $res['insert'] = $client->insertForm($res['vid']);
        }

        if (!empty($res['ok'])) {
            $t              = itxt_tournament();
            $res['compare'] = [
                'agrement'  => ['ianseo' => $t->ToCommitee, 'extranet' => $res['details']['Structure Organisatrice'] ?? ''],
                'date'      => ['ianseo' => date('d/m/Y', strtotime($t->ToWhenFrom)), 'extranet' => $res['details']['Date'] ?? ''],
                'lieu'      => ['ianseo' => $t->City, 'extranet' => $res['details']['Lieu'] ?? ''],
            ];
            $res['counts'] = itxt_side_counts();   // archers valides / para
        }

        itxt_json($res);
        break;

    case 'deposit':
        $client = itxt_client($ITXT_BASE);
        $email  = trim($_POST['itxt_email'] ?? '');
        $vVid   = trim($_POST['itxt_valides_vid'] ?? '');    // épreuve extranet valides
        $pVid   = trim($_POST['itxt_para_vid'] ?? '');       // épreuve extranet para

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            itxt_json(['ok' => false, 'msg' => 'Adresse e-mail invalide.']);
        }

        $counts  = itxt_side_counts();
        $reports = [];

        // Le nom du fichier (A + Discipline + Agrément + .txt) vient de l'export lui-même :
        // c'est ce que l'extranet vérifie. On ne le fabrique jamais à la main.
        // Valides = 'S' (Sélectif) ; para = 'SP'. Jamais 'N' (Championnat de France, sans filtre).

        if ($counts['valides'] > 0) {
            if ($vVid === '') {
                $reports['valides'] = ['ok' => false, 'msg' => 'Archers valides présents, mais épreuve valides introuvable sur l\'extranet.'];
            } else {
                $g = itxt_generate_txt('S');
                $reports['valides'] = (!$g || $g['content'] === '')
                    ? ['ok' => false, 'msg' => 'Export valides vide (aucun résultat ?).']
                    : $client->deposit($vVid, $email, $g['content'], $g['filename']);
            }
        }

        if ($counts['para'] > 0) {
            if ($pVid === '') {
                $reports['para'] = ['ok' => false, 'msg' => 'Épreuve para absente de l\'extranet : la compétition '
                    . 'n\'a probablement pas été déclarée « Valide + Para » au calendrier fédéral. Corrigez la '
                    . 'déclaration sur l\'extranet pour déposer les résultats para.'];
            } else {
                $g = itxt_generate_txt('SP');
                $reports['para'] = (!$g || $g['content'] === '')
                    ? ['ok' => false, 'msg' => 'Export para vide (aucun résultat ?).']
                    : $client->deposit($pVid, $email, $g['content'], $g['filename']);
            }
        }

        if (empty($reports)) {
            itxt_json(['ok' => false, 'msg' => 'Aucun archer à déposer dans cette compétition.']);
        }
        itxt_json(['ok' => true, 'reports' => $reports]);
        break;

    case 'logout':
        itxt_cookie_destroy();
        itxt_json(['ok' => true]);
        break;

    default:
        http_response_code(400);
        itxt_json(['ok' => false, 'msg' => 'Action inconnue.']);
}
