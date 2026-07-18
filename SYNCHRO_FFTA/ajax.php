<?php
/**
 * Endpoint AJAX du module SYNCHRO_FFTA — flux « dépôt ».
 * Une action par étape de l'assistant. Aucun dépôt n'est effectué ici :
 * la chaîne s'arrête volontairement à l'affichage du cadre de dépôt.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/ExtranetClient.php');

CheckTourSession(true);
checkFullACL(AclCompetition, 'cExport', AclReadOnly);

// Serveur cible — préproduction tant que la navigation n'est pas validée.
$ITXT_BASE = ExtranetClient::BASE_PPROD;

$action = $_POST['itxt_action'] ?? '';

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
        JsonOut(['ok' => false, 'msg' => 'Aucune session extranet — connecte-toi d\'abord.', 'relogin' => true]);
    }

    return new ExtranetClient($f, $base);
}

/** Compétition ianseo courante : sert au pré-remplissage et au rapprochement. */
function itxt_tournament(): stdClass
{
    $q = safe_r_sql('SELECT ToName, ToCommitee, ToComDescr, ToWhere, ToWhenFrom, ToWhenTo,
        ToCategory, ToTypeSubRule
        FROM Tournament WHERE ToId=' . intval($_SESSION['TourId']));

    return safe_fetch($q);
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

    foreach ([[$t->ToWhere, $ev['lieu'], 25], [$t->ToName, $ev['nom'], 20]] as [$a, $b, $w]) {
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
            JsonOut(['ok' => true, 'logged' => false]);
        }

        $client = new ExtranetClient($f, $ITXT_BASE);
        $res    = $client->session();
        if (!$res['ok']) {
            if ($own) {
                itxt_cookie_destroy();   // le cookie d'AUTH ne nous appartient pas : on n'y touche pas
            }
            JsonOut(['ok' => true, 'logged' => false]);
        }

        JsonOut([
            'ok'     => true,
            'logged' => true,
            'roles'  => $res['roles'],
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
            JsonOut($res);
        }

        $t = itxt_tournament();
        JsonOut([
            'ok'    => true,
            'base'  => $ITXT_BASE,
            'roles' => $res['roles'],
            'tour'  => [
                'nom'       => $t->ToName,
                'lieu'      => $t->ToWhere,
                'club'      => $t->ToCommitee . ' — ' . $t->ToComDescr,
                'du'        => date('d/m/Y', strtotime($t->ToWhenFrom)),
                'au'        => date('d/m/Y', strtotime($t->ToWhenTo)),
            ],
        ]);
        break;

    case 'role':
        $client = itxt_client($ITXT_BASE);
        JsonOut($client->switchRole($_POST['itxt_role'] ?? ''));
        break;

    case 'list':
        $client = itxt_client($ITXT_BASE);
        $t      = itxt_tournament();

        $from = itxt_date_fr($_POST['itxt_from'] ?? '', $t->ToWhenFrom);
        $to   = itxt_date_fr($_POST['itxt_to']   ?? '', $t->ToWhenTo);

        $disc  = itxt_discipline($t);
        $fDisc = !empty($_POST['itxt_f_disc']) && $disc !== '';
        $fOrg  = !empty($_POST['itxt_f_org'])  && $t->ToCommitee !== '';

        $res = $client->listEvents($from, $to, $fDisc ? $disc : 'all');
        if (!$res['ok']) {
            JsonOut($res);
        }

        $res['total'] = count($res['events']);
        if ($fOrg) {
            $res['events'] = array_values(array_filter($res['events'], function ($ev) use ($t) {
                return strpos($ev['organisateur'], $t->ToCommitee) !== false;
            }));
        }

        $res['filters'] = [
            'discipline'  => ['code' => $disc, 'label' => itxt_discipline_label($disc), 'on' => $fDisc],
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
        JsonOut($res);
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
                'lieu'      => ['ianseo' => $t->ToWhere, 'extranet' => $res['details']['Lieu'] ?? ''],
            ];
        }

        JsonOut($res);
        break;

    case 'logout':
        itxt_cookie_destroy();
        JsonOut(['ok' => true]);
        break;

    default:
        http_response_code(400);
        JsonOut(['ok' => false, 'msg' => 'Action inconnue.']);
}
