<?php
/**
 * SYNCHRO_FFTA — AJAX endpoints of the « creation from the extranet » flow.
 * Works WITHOUT an open competition. Creates no competition itself (create-run.php does): it
 * searches the FFTA calendar, reads an event and proposes the ianseo settings. The events read
 * are kept in the FftaEvents table (lib/events.php) for every module of the server.
 */
// Before anything is loaded: any stray output (warning/notice, BOM) sent before the JSON would
// break the Content-Length computed by JsonOut (length of the JSON alone) → truncated answer in
// the browser. Output is therefore captured from the first line and dropped before answering.
ob_start();

define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/ExtranetClient.php');
require_once(__DIR__ . '/mapping.php');
require_once(__DIR__ . '/session.php');
require_once(__DIR__ . '/lib/schema.php');
require_once(__DIR__ . '/lib/events.php');

CheckTourSession(false);

// Droit de créer une compétition, calqué sur la page native (Tournament/index.php) :
// on ne bloque que si AUTH est actif ET l'utilisateur n'a pas le droit. Sur localhost
// (AUTH court-circuité, AUTH_ENABLE vide), la création reste permise comme pour « Nouveau ».
$sfaAuthOn = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
if ($sfaAuthOn && empty($_SESSION['AUTH_ROOT']) && !possibleFeature(AclRoot, AclReadWrite)) {
    http_response_code(403);
    sfa_json(['ok' => false, 'msg' => 'Droit de création requis.']);
}

sfa_schema();

// La base extranet vient de sfa_base('ext') (session.php) — production pour la création.
$action = $_POST['sfa_action'] ?? '';

/** Sortie JSON propre : on jette d'abord tout ce qui aurait pu être émis. */
function sfa_json($data) {
    while (ob_get_level()) { ob_end_clean(); }
    JsonOut($data);
}

/** ianseo time offset (±hh:mm) of Paris on that ISO date. */
function sfa_timezone(string $isoDate): string
{
    try {
        return (new DateTime($isoDate, new DateTimeZone('Europe/Paris')))->format('P');
    } catch (Exception $e) {
        return '+01:00';
    }
}

/** [y, m, d] of an ISO date, today when empty. */
function sfa_ymd(?string $isoDate): array
{
    $t = $isoDate ? strtotime($isoDate) : time();

    return ['y' => (int) date('Y', $t), 'm' => (int) date('n', $t), 'd' => (int) date('j', $t)];
}

switch ($action) {

    case 'status':
        $f = sfa_any_cookie('ext');
        if (!$f) {
            sfa_json(['ok' => true, 'logged' => false]);
        }
        $shared = sfa_is_shared('ext');
        $client = new ExtranetClient($f, sfa_base('ext'));
        // The calendar page itself: it checks the session AND gives the search form, which the
        // first search then does not have to read again.
        $res    = $client->session(ExtranetClient::CAL_PAGE);
        if (!$res['ok']) {
            if (!empty($res['wait'])) {
                sfa_json($res);   // the page waits and asks again
            }
            // Offline or blocked: the session is not dead, the cookie is kept and the reason said.
            if (!empty($res['offline']) || !empty($res['blocked'])) {
                sfa_json(['ok' => true, 'logged' => false, 'offline' => !empty($res['offline']),
                          'blocked' => !empty($res['blocked']), 'msg' => $res['msg'] ?? '']);
            }
            if (!$shared) {
                sfa_own_cookie_destroy('ext');
            }
            sfa_json(['ok' => true, 'logged' => false]);
        }
        // AUTH présent : le rôle extranet suit sa vue, sans sélecteur manuel (create.php).
        $roles = sfa_sync_role_with_auth($client, $res['roles']);
        sfa_json(['ok' => true, 'logged' => true, 'roles' => $roles, 'shared' => $shared,
                  'disciplines' => $client->calendarDisciplines()]);
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
        if (!empty($out['ok'])) {
            // AUTH présent : le rôle extranet suit sa vue, sans sélecteur manuel (create.php).
            $extClient    = new ExtranetClient(sfa_own_cookie('ext'), sfa_base('ext'));
            $out['roles'] = sfa_sync_role_with_auth($extClient, $out['roles'] ?? []);
            $out['disciplines'] = $extClient->calendarDisciplines();
        }
        $out['dir'] = ['ok' => !empty($res['dir']['ok']), 'msg' => $res['dir']['msg'] ?? ''];
        sfa_json($out);
        break;

    case 'role':
        $client = sfa_client('ext');
        sfa_json($client->switchRole($_POST['sfa_role'] ?? ''));
        break;

    case 'logout':
        sfa_logout();   // nos deux cookies ; ne touche jamais ceux d'AUTH
        sfa_json(['ok' => true]);
        break;

    case 'list':
        $client = sfa_client('ext');
        $from   = $_POST['sfa_from'] ?? '';
        $to     = $_POST['sfa_to']   ?? '';
        $from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? date('d/m/Y', strtotime($from)) : $from;
        $to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? date('d/m/Y', strtotime($to))   : $to;
        // A value of the calendar's own discipline list (« S|all », « C|12 CONNUES + 12 INCONNUES »):
        // free text, only sent back to the extranet form.
        $disc   = (string) ($_POST['sfa_disc'] ?? 'all');
        if ($disc === '' || mb_strlen($disc) > 120 || preg_match('/[\x00-\x1F]/', $disc)) {
            $disc = 'all';
        }
        $res = $client->calendar($from, $to, $disc);
        if (!empty($res['ok'])) {
            sfa_events_save_list($res['events']);
            $res['events'] = ExtranetClient::groupPara($res['events']);   // fusionne les lignes Valide+Para
        }
        sfa_json($res);
        break;

    case 'event':
        // The event as the last search kept it: nothing the page sends is trusted for it.
        $id  = preg_replace('/\D/', '', (string) ($_POST['sfa_id'] ?? ''));
        $row = $id !== '' ? sfa_event_get((int) $id) : null;
        if (!$row) {
            sfa_json(['ok' => false, 'msg' => 'Cette épreuve n\'est plus dans la liste : relancez la recherche.']);
        }

        // Venue: the « Détail » box of the calendar, unless it was read a short while ago.
        $venueMsg = '';
        if (!sfa_event_detail_fresh($row)) {
            $detail = sfa_client('ext')->eventDetail($id);
            if (!empty($detail['wait'])) {
                sfa_json($detail);   // the page waits and asks again
            }
            if (!empty($detail['ok'])) {
                sfa_events_save_detail((int) $id, $detail);
                $row = sfa_event_get((int) $id);
            } else {
                $venueMsg = $detail['msg'] ?? '';
            }
        }

        $code     = sfa_event_code($id, (string) $row->FeDateFrom);
        // bytes: the code and the event number are ASCII
        $codeWarn = strlen($code) > 8
            ? 'Code trop long (' . strlen($code) . ' car., max 8) — n° d\'épreuve à ' . strlen($id) . ' chiffres.'
            : '';

        // Same text as the calendar shows (« Tir 3D - 1 X 24 CIBLES - Duels »): the §3 rows of the
        // mapping file are matched on it, duels included.
        $head = $row->FeDiscipline
            . ($row->FeFormat !== '' ? ' - ' . $row->FeFormat : '')
            . ($row->FeDuels ? ' - Duels' : '');
        $validePara = !empty($row->FeValidePara) || !empty($_POST['sfa_vp']);
        $prop = sfa_propose($head, $head, $row->FeChampionship, $row->FeName, $validePara);

        // Already created on this server: said at once, before the form is filled in for nothing.
        $exists = null;
        $q = safe_r_sql('SELECT ToId, ToName FROM Tournament WHERE ToCode=' . StrSafe_DB($code));
        if ($r = safe_fetch($q)) {
            $exists = ['id' => (int) $r->ToId, 'name' => $r->ToName];
        }

        $fromD = sfa_ymd($row->FeDateFrom);
        $toD   = sfa_ymd($row->FeDateTo ?: $row->FeDateFrom);
        $city  = preg_match(SFA_VENUE_UNKNOWN, trim($row->FeCity)) ? '' : $row->FeCity;

        sfa_json([
            'ok'       => true,
            'exists'   => $exists,
            'proposal' => $prop,
            'event'    => [
                'discipline'  => $head,
                'type'        => $row->FeChampionship,
                'duels'       => (bool) $row->FeDuels,
                'distinction' => $row->FeDistinction,
            ],
            'venue'    => [
                'text' => sfa_event_venue_text($row),
                'lat'  => $row->FeLatitude !== null ? (float) $row->FeLatitude : null,
                'lon'  => $row->FeLongitude !== null ? (float) $row->FeLongitude : null,
                'msg'  => $venueMsg,
            ],
            'prefill'  => [
                'eprv'     => $id,
                'code'     => $code,
                'codeWarn' => $codeWarn,
                'name'     => $row->FeName,
                'commitee' => $row->FeOrgCode,
                'comdescr' => $row->FeOrgName,
                'where'    => $city,
                'country'  => 'FRA',
                'fromY'    => $fromD['y'], 'fromM' => $fromD['m'], 'fromD' => $fromD['d'],
                'toY'      => $toD['y'],   'toM'   => $toD['m'],   'toD'   => $toD['d'],
                'timezone' => sfa_timezone($row->FeDateFrom ?: date('Y-m-d')),
            ],
        ]);
        break;

    default:
        http_response_code(400);
        sfa_json(['ok' => false, 'msg' => 'Action inconnue.']);
}
