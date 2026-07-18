<?php
/**
 * SYNCHRO_FFTA — endpoints AJAX du flux « création depuis l'extranet ».
 * Fonctionne SANS compétition ouverte. Ne crée rien lui-même : la création
 * réelle est faite par le formulaire natif de ianseo (Tournament/index.php),
 * ce endpoint ne fait que lister, lire et proposer.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/ExtranetClient.php');
require_once(__DIR__ . '/mapping.php');
require_once(__DIR__ . '/session.php');

CheckTourSession(false);

// Droit de créer une compétition, calqué sur la page native (Tournament/index.php) :
// on ne bloque que si AUTH est actif ET l'utilisateur n'a pas le droit. Sur localhost
// (AUTH court-circuité, AUTH_ENABLE vide), la création reste permise comme pour « Nouveau ».
$sfaAuthOn = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
if ($sfaAuthOn && empty($_SESSION['AUTH_ROOT']) && !possibleFeature(AclRoot, AclReadWrite)) {
    http_response_code(403);
    JsonOut(['ok' => false, 'msg' => 'Droit de création requis.']);
}

$SFA_BASE = ExtranetClient::BASE_PPROD;
$action   = $_POST['sfa_action'] ?? '';

/** Toutes les dates jj/mm/aaaa d'un texte, triées. */
function sfa_dates(string $s): array
{
    preg_match_all('#(\d{2})/(\d{2})/(\d{4})#', $s, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $d) {
        $out[] = ['y' => (int) $d[3], 'm' => (int) $d[2], 'd' => (int) $d[1]];
    }
    usort($out, function ($a, $b) {
        return [$a['y'], $a['m'], $a['d']] <=> [$b['y'], $b['m'], $b['d']];
    });

    return $out;
}

/** Saison sportive (2 chiffres) : mois ≥ septembre → année+1, sinon année. */
function sfa_season(array $date): string
{
    $y = $date['y'] + ($date['m'] >= 9 ? 1 : 0);

    return substr((string) $y, -2);
}

/** Décalage horaire ianseo (±hh:mm) de Paris à cette date. */
function sfa_timezone(array $date): string
{
    try {
        $dt = new DateTime(sprintf('%04d-%02d-%02d', $date['y'], $date['m'], $date['d']),
            new DateTimeZone('Europe/Paris'));

        return $dt->format('P');
    } catch (Exception $e) {
        return '+01:00';
    }
}

switch ($action) {

    case 'status':
        $f = sfa_any_cookie('ext');
        if (!$f) {
            JsonOut(['ok' => true, 'logged' => false]);
        }
        $shared = sfa_is_shared('ext');
        $res    = (new ExtranetClient($f, sfa_base('ext')))->session();
        if (!$res['ok']) {
            if (!$shared) {
                sfa_own_cookie_destroy('ext');
            }
            JsonOut(['ok' => true, 'logged' => false]);
        }
        JsonOut(['ok' => true, 'logged' => true, 'roles' => $res['roles'], 'shared' => $shared]);
        break;

    case 'login':
        $user = $_POST['sfa_user'] ?? '';
        $pass = $_POST['sfa_pass'] ?? '';
        $otp  = $_POST['sfa_otp']  ?? '';
        unset($_POST['sfa_user'], $_POST['sfa_pass'], $_POST['sfa_otp']);
        // Ouvre les deux espaces (un minimum de saisies). La création a besoin de
        // l'extranet : son résultat pilote l'écran ; le statut dirigeant est joint.
        $res = sfa_login($user, $pass, $otp, ['ext', 'dir']);
        $out = $res['ext'];
        $out['dir'] = ['ok' => !empty($res['dir']['ok']), 'msg' => $res['dir']['msg'] ?? ''];
        JsonOut($out);
        break;

    case 'role':
        $client = sfa_client('ext');
        JsonOut($client->switchRole($_POST['sfa_role'] ?? ''));
        break;

    case 'logout':
        sfa_logout();   // nos deux cookies ; ne touche jamais ceux d'AUTH
        JsonOut(['ok' => true]);
        break;

    case 'list':
        $client = sfa_client('ext');
        $from   = $_POST['sfa_from'] ?? '';
        $to     = $_POST['sfa_to']   ?? '';
        $from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? date('d/m/Y', strtotime($from)) : $from;
        $to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? date('d/m/Y', strtotime($to))   : $to;
        $res    = $client->listEvents($from, $to, 'all');
        if (!empty($res['ok'])) {
            $res['events'] = ExtranetClient::groupPara($res['events']);   // fusionne les lignes Valide+Para
        }
        JsonOut($res);
        break;

    case 'event':
        $client = sfa_client('ext');
        $res    = $client->event($_POST['sfa_id'] ?? '');
        if (!$res['ok']) {
            JsonOut($res);
        }

        $d       = $res['details'];
        $dates   = sfa_dates($d['Date'] ?? '');
        $fromD   = $dates[0] ?? ['y' => (int) date('Y'), 'm' => (int) date('n'), 'd' => (int) date('j')];
        $toD     = end($dates) ?: $fromD;
        $orga    = $d['Structure Organisatrice'] ?? '';
        $agrement = trim(explode('-', $orga)[0] ?? '');
        $comdescr = trim(substr($orga, strlen($agrement) + 1), " -\t");
        $epreuve  = preg_replace('/\D/', '', (string) ($res['id'] ?? ''));

        $code     = 'F' . sfa_season($fromD) . $epreuve;
        $codeWarn = strlen($code) > 8
            ? 'Code trop long (' . strlen($code) . ' car., max 8) — n° d\'épreuve à ' . strlen($epreuve) . ' chiffres.'
            : '';

        // Tag « Valide + Para » : détecté sur la ligne de liste (transmis par le client),
        // ou dans les infos de la page épreuve par sécurité.
        $validePara = !empty($_POST['sfa_vp'])
            || stripos(implode(' ', $d), 'valide + para') !== false;

        $prop = sfa_propose(
            $d['Discipline'] ?? '',
            $res['details']['Caractéristiques'] ?? ($d['Discipline'] ?? ''),
            $d['Type d\'épreuve'] ?? '',
            $d['Nom de l\'épreuve'] ?? '',
            $validePara
        );

        JsonOut([
            'ok'       => true,
            'details'  => $d,
            'proposal' => $prop,
            'prefill'  => [
                'code'     => $code,
                'codeWarn' => $codeWarn,
                'name'     => $d['Nom de l\'épreuve'] ?? '',
                'commitee' => $agrement,
                'comdescr' => $comdescr,
                'where'    => $d['Lieu'] ?? '',
                'country'  => 'FRA',
                'fromY'    => $fromD['y'], 'fromM' => $fromD['m'], 'fromD' => $fromD['d'],
                'toY'      => $toD['y'],   'toM'   => $toD['m'],   'toD'   => $toD['d'],
                'timezone' => sfa_timezone($fromD),
            ],
        ]);
        break;

    default:
        http_response_code(400);
        JsonOut(['ok' => false, 'msg' => 'Action inconnue.']);
}
