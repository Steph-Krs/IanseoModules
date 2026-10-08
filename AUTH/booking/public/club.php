<?php
/**
 * public/club.php — a manager registers the archers of their club.
 *
 * The scope is checked again at every write (bk_scope_covers): a manager must never be able to
 * register an archer outside their club, even with a forged form.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/club.php';

$archer = bk_require_archer();
$scopes = bk_manager_scopes($archer);

if (!$scopes) {
    bk_head(bk_t('ClubHead'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('ClubRestricted')) . '</h1>'
       . bk_msg('err', bk_t('NotManager') . ' ' . bk_t('ClubAskRight'))
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url()) . '">' . bk_e(bk_t('BackToMySpace')) . '</a></p></div>';
    bk_foot();
    exit;
}

$tourId = intval($_GET['t'] ?? $_POST['t'] ?? 0);
$comps  = bk_comp_calendar();
$cfg    = $tourId ? bk_comp_config($tourId) : null;
$search = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));

$err = ''; $ok = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['go'] ?? '') === '1') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (!$tourId || !$cfg || empty($cfg->BcIsOpen)) {
        $err = bk_t('ClubRegsNotOpen');
    } else {
        $licence  = bk_clean_licence($_POST['licence'] ?? '');
        $division = (string) ($_POST['division'] ?? '');
        $session  = intval($_POST['session'] ?? 0);
        $request  = trim((string) ($_POST['request'] ?? ''));

        $lue = bk_lookup_licence($licence);
        // The category is not asked: it follows from age, sex and bow. The most specific one the
        // rules allow is taken (the list is already sorted that way) — it cannot disagree with
        // the chosen bow.
        $class = '';
        if ($lue) {
            $cl = bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $division);
            $class = (string) array_key_first($cl);
        }

        if (!$lue) {
            $err = bk_t('ClubUnknownLicence');
        } elseif ($class === '') {
            $err = bk_t('ClubNoCategory');
        } elseif (!bk_scope_covers($scopes, $lue->LueCountry)) {
            // Deciding check: out of scope, refused whatever happens.
            bk_log('CLUB_OUT_OF_SCOPE', $archer->BaLicence);
            $err = bk_t('ClubOutOfScope');
        } else {
            $err = bk_reg_blocked($tourId, $cfg, $licence, $lue->LueCountry,
                $division, $class, $session, $lue);
            if ($err === '') {
                $res = bk_register($tourId, $lue, $division, $class, $session, $request, array(
                    'role' => 'MANAGER', 'who' => $archer->BaLicence, 'archer' => 0,
                ));
                if (!empty($res['ok'])) {
                    bk_log('REG_CLUB', $archer->BaLicence);
                    $ok = bk_t('ClubRegistered', $lue->LueFamilyName . ' ' . $lue->LueName);
                } else {
                    $err = $res['msg'] ?? bk_t('RegFailed');
                }
            }
        }
    }
}

$members  = bk_club_members($scopes, $search);
$labels   = bk_scope_labels($scopes);
$sessions = $tourId ? bk_comp_sessions($tourId) : array();
$divs     = $tourId ? bk_reg_divisions($tourId) : array();

// Already registered on this competition (not offered again).
$already = array();
if ($tourId) {
    $rs = safe_r_sql("SELECT EnCode FROM Entries WHERE EnTournament = " . intval($tourId));
    while ($r = safe_fetch($rs)) $already[bk_clean_licence($r->EnCode)] = true;
}

bk_head(bk_t('ClubHead'));
$out = '<h1>' . bk_e(bk_t('ClubTitle')) . '</h1>'
    . '<p class="bk-org">' . bk_e(bk_t('ClubScope', implode(', ', $labels) ?: implode(', ', $scopes))) . '</p>'
    . ($ok ? bk_msg('ok', $ok) : '') . ($err ? bk_msg('err', $err) : '');

$opts = '<option value="">' . bk_e(bk_t('ChooseDash')) . '</option>';
foreach ($comps as $c) {
    $opts .= '<option value="' . intval($c->BcTournament) . '"' . ($tourId === intval($c->BcTournament) ? ' selected' : '') . '>'
        . bk_e($c->ToName . ' (' . bk_date_range($c->ToWhenFrom, $c->ToWhenTo) . ')') . '</option>';
}
$out .= '<form class="bk-filters" method="get">'
    . '<label>' . bk_e(bk_t('ClubComp')) . ' <select name="t" onchange="this.form.submit()">' . $opts . '</select></label>'
    . '<label>' . bk_e(bk_t('ClubSearch')) . ' <input type="text" name="q" value="' . bk_e($search) . '" placeholder="' . bk_e(bk_t('ClubSearchPh')) . '"></label>'
    . '<button type="submit" class="bk-btn">' . bk_e(bk_t('Filter')) . '</button></form>'
    . ($tourId ? '<p><a class="bk-btn" href="' . bk_e(bk_public_url('receipt.php?club=1&t=' . $tourId)) . '">' . bk_e(bk_t('ClubStatementBtn')) . '</a></p>' : '');

if (!$tourId) {
    $out .= '<p class="bk-empty">' . bk_e(bk_t('ClubPickComp')) . '</p>';
} elseif (empty($cfg->BcIsOpen)) {
    $out .= bk_msg('err', bk_t('ClubRegsNotOpen'));
} elseif (!$members) {
    $out .= '<p class="bk-empty">' . bk_e(bk_t('ClubNoMatch')) . '</p>';
} else {
    $divOpts = '';
    foreach ($divs as $k => $lab) $divOpts .= '<option value="' . bk_e($k) . '">' . bk_e($lab) . '</option>';
    $sesOpts = '';
    $sesStates = bk_session_states($tourId, $cfg, $sessions);
    foreach ($sessions as $s) {
        $left = max(0, intval($s->Places) - intval($s->Pris));
        $st = $sesStates[intval($s->SesOrder)] ?? array('open' => true);
        $sesOpts .= '<option value="' . intval($s->SesOrder) . '"' . ($left === 0 || !$st['open'] ? ' disabled' : '') . '>'
            . bk_e(bk_t('DepCap', intval($s->SesOrder)) . ' (' . ($st['open'] ? $left : bk_session_state_text($st)) . ')') . '</option>';
    }
    $out .= '<div class="bk-list">';
    foreach ($members as $m) {
        $registered = isset($already[bk_clean_licence($m->LueCode)]);
        $geo = bk_comp_archer_blocked($cfg, $m->LueCountry);
        $out .= '<article class="bk-item' . (($registered || $geo) ? ' bk-item-off' : '') . '">'
            . '<div class="bk-item-main"><h2>' . bk_e($m->LueFamilyName . ' ' . $m->LueName) . '</h2>'
            . '<p class="bk-meta"><span>' . bk_e($m->LueCode) . '</span><span>' . bk_e(bk_t('BornOnX', bk_date_fr($m->LueCtrlCode))) . '</span>'
            . '<span>' . bk_e($m->LueCoDescr) . '</span></p></div><div class="bk-item-act">';
        if ($registered) {
            $out .= '<p class="bk-tag bk-tag-on">' . bk_e(bk_t('AlreadyRegistered')) . '</p>';
        } elseif ($geo) {
            $out .= '<p class="bk-blocked">' . bk_e($geo) . '</p>';
        } else {
            $out .= '<form method="post" class="bk-inline">' . bk_csrf_field()
                . '<input type="hidden" name="t" value="' . intval($tourId) . '"><input type="hidden" name="q" value="' . bk_e($search) . '">'
                . '<input type="hidden" name="go" value="1"><input type="hidden" name="licence" value="' . bk_e($m->LueCode) . '">'
                . '<select name="division" required>' . $divOpts . '</select> <select name="session" required>' . $sesOpts . '</select> '
                . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('ClubRegisterBtn')) . '</button></form>'
                . '<p class="bk-hint">' . bk_e(bk_t('ClubCatHint')) . '</p>';
        }
        $out .= '</div></article>';
    }
    $out .= '</div><p class="bk-hint">' . bk_e(bk_t('ClubFirst60')) . '</p>';
}
echo $out;
bk_foot();
