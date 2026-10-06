<?php
/**
 * public/registrations.php — "My registrations": consultation and cancellation, waiting lists,
 * balance of each competition, registrations made for clubmates.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/targets.php';
require_once dirname(__DIR__) . '/lib/payment.php';
require_once dirname(__DIR__) . '/lib/mandate.php';   // bk_mandate_visible
require_once dirname(__DIR__) . '/lib/waitlist.php';
require_once dirname(__DIR__, 2) . '/shop/lib/link.php';   // shop of a competition

$archer = bk_require_archer();

/**
 * Due, paid and remaining of an account (bk_due_total), on one line, in the currency of
 * competition $tourId. $known false: competition over and its organiser records no payment
 * here (bk_ledger_tracked) — the amount only.
 */
function rg_balance($d, $known, $tourId)
{
    $f = function ($v) use ($tourId) { return bk_e(bk_eur($v, false, $tourId)); };
    if (!$known) return bk_t('BalanceUnknown', $f($d['total']));
    if ($d['remaining'] < -0.005) $st = '<span class="bk-tag">' . bk_t('BalanceOver', $f(-$d['remaining'])) . '</span>';
    elseif ($d['remaining'] <= 0.005) $st = '<span class="bk-tag bk-tag-on">' . bk_e(bk_t('StateSettled')) . '</span>';
    else $st = '<span class="bk-due-wait">' . bk_t('BalanceLeft', $f($d['remaining'])) . '</span>';
    return bk_t('BalanceLine', array('due' => $f($d['total']), 'paid' => $f($d['paid']), 'state' => $st));
}

/** Means of payment of a competition, as a list; the one chosen ($chosen) marked with $badge. */
function rg_paylist($pay, $chosen = null, $badge = '')
{
    $h = '<ul>';
    foreach ($pay as $pi) {
        $isChosen = $chosen ? $chosen($pi) : false;
        $h .= '<li' . ($isChosen ? ' class="bk-pay-chosen"' : '') . '>' . bk_e($pi['label'])
            . ' <span class="bk-hint">(' . bk_e($pi['whenLabel']) . ')</span>' . ($pi['info'] !== '' ? ' — ' . bk_e($pi['info']) : '');
        if ($isChosen) $h .= ' <span class="bk-pay-badge">' . bk_e($badge) . '</span>';
        $h .= '</li>';
    }
    return $h . '</ul>';
}

/** The tags of one registration: category, departure, events, validation, target, author. */
function rg_tags($r, $withPerson = false)
{
    $h = '<p class="bk-tags">';
    if ($withPerson) {
        $h .= '<span class="bk-tag bk-tag-on">' . bk_e(trim($r->EnFirstName . ' ' . $r->EnName)) . '</span>'
            . '<span class="bk-tag">' . bk_e($r->EnCode) . '</span>';
    }
    $h .= '<span class="bk-tag">' . bk_e($r->DivDescription ?: $r->EnDivision) . '</span>'
        . '<span class="bk-tag">' . bk_e($r->ClDescription ?: $r->EnClass) . '</span>'
        . '<span class="bk-tag">' . bk_e(bk_t('DepCap', intval($r->QuSession))) . '</span>';
    if (isset($r->EnIndClEvent) && intval($r->EnIndClEvent) === 0) {
        $h .= '<span class="bk-tag" title="' . bk_e(bk_t('OutOfEventsTip')) . '">' . bk_e(bk_t('OutOfEvents')) . '</span>';
    }
    if (isset($r->BrValidated) && intval($r->BrValidated) === 0) {
        $h .= '<span class="bk-tag bk-tag-wait">' . bk_e(bk_t('PendingVal')) . '</span>';
    } elseif (!empty($r->BcShowAssignment) && intval($r->QuTarget) > 0) {
        $h .= '<span class="bk-tag bk-tag-on">' . bk_e(bk_t('TargetX', intval($r->QuTarget) . $r->QuLetter)) . '</span>';
    } elseif (!empty($r->BcShowAssignment) && !$withPerson) {
        $h .= '<span class="bk-tag">' . bk_e(bk_t('TargetNone')) . '</span>';
    }
    if (!$withPerson) {
        $by = array('MANAGER' => 'ByClub', 'CLUB' => 'ByClubmate', 'IMPORT' => 'ByOrganiser');
        if (isset($by[$r->BrByRole])) $h .= '<span class="bk-tag">' . bk_e(bk_t($by[$r->BrByRole])) . '</span>';
    }
    return $h . '</p>';
}

/** Cancellation form of a registration. */
function rg_cancel_form($enId, $confirmKey, $labelKey)
{
    return '<form method="post" onsubmit="return confirm(' . bk_e(json_encode(bk_t($confirmKey), JSON_UNESCAPED_UNICODE)) . ')">'
        . bk_csrf_field() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="enid" value="' . intval($enId) . '">'
        . '<button type="submit" class="bk-btn bk-btn-danger">' . bk_e(bk_t($labelKey)) . '</button></form>';
}

$err = '';
$ok  = !empty($_GET['ok']) ? bk_t('RegSaved') : (!empty($_GET['wait']) ? bk_t('WaitSaved') : '');

// Registration confirmed: amount + means of payment of the competition just registered for,
// shown prominently (when the archer expects them).
$okDue = null; $okPay = array(); $okSubject = null;
$okTour = intval($_GET['t'] ?? 0);
if (!empty($_GET['ok']) && $okTour > 0) {
    // Group registration: ?s=<licence> names the clubmate registered. Someone else's details
    // are shown only after checking AGAIN that it is a clubmate of the signed-in archer
    // (guard against a forged ?s=).
    $okSubjectLic = bk_clean_licence($_GET['s'] ?? '');
    if ($okSubjectLic !== '' && $okSubjectLic !== bk_clean_licence($archer->BaLicence)) {
        $selfLue = bk_lookup_licence($archer->BaLicence);
        if ($selfLue) $okSubject = bk_lookup_clubmate($okSubjectLic, $selfLue->LueCountry);
    }
    $okLic = $okSubject ? $okSubjectLic : $archer->BaLicence;
    $okDue = bk_due_total($okTour, $okLic);
    if ($okDue['remaining'] > 0.005) {
        $okPay = bk_payinfo_get(bk_comp_config($okTour));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $enid = intval($_POST['enid'] ?? 0);
        // Keep the competition BEFORE the removal: the row is gone afterwards.
        $rsT = safe_r_sql("SELECT BrTournament FROM BookingRegistrations WHERE BrEnId = $enid");
        $rT  = safe_fetch($rsT);
        $res = bk_unregister($enid, $archer->BaId, $archer->BaLicence);
        if (!empty($res['ok'])) {
            bk_log('REG_CANCEL', $archer->BaLicence);
            if ($rT) {
                bk_replan_all(intval($rT->BrTournament), bk_comp_config(intval($rT->BrTournament)));
                bk_waitlist_process(intval($rT->BrTournament));   // the place goes to the waiting list
            }
            $ok = bk_t('RegCancelled');
        } else {
            $err = $res['msg'] ?? bk_t('CancelFailed');
        }
    } elseif (($_POST['action'] ?? '') === 'leave_wait') {
        if (bk_waitlist_leave(intval($_POST['w'] ?? 0), $archer->BaId, $archer->BaLicence)) {
            bk_log('WAIT_LEAVE', $archer->BaLicence);
            $ok = bk_t('WaitLeft');
        } else {
            $err = bk_t('WaitGone');
        }
    }
}

// Waiting lists of this archer (and those they put clubmates on): still waiting, or
// what happened since their last visit — then marked as seen.
$waits = bk_waitlist_for_archer($archer->BaId, $archer->BaLicence);

$regs = bk_my_registrations($archer->BaLicence);
// Open satisfaction surveys of this archer, one query for the whole page.
require_once dirname(__DIR__) . '/lib/survey.php';
$svOpen = bk_survey_open_for($archer->BaLicence);
// Registrations the archer made FOR clubmates (group).
$authored = bk_authored_registrations($archer->BaId, $archer->BaLicence);

bk_head(bk_t('NavMyRegs'));

if ($ok && $okDue && $okDue['remaining'] > 0.005) {
    echo '<div class="bk-confirm"><p>'
        . ($okSubject ? bk_t('ConfirmSavedFor', bk_e(trim($okSubject->LueFamilyName . ' ' . $okSubject->LueName))) : bk_t('ConfirmSaved'))
        . ' ' . bk_t('ConfirmLeft', bk_e(bk_eur($okDue['remaining'], false, $okTour)))
        . ' <span class="bk-hint">' . bk_e(bk_t('ConfirmLeftHint')) . '</span></p>';
    if ($okPay) echo '<p class="bk-confirm-h">' . bk_e(bk_t('PayMeans')) . '</p>' . rg_paylist($okPay);
    echo '</div>';
} elseif ($ok) {
    echo bk_msg('ok', $ok);
}
if (!empty($_GET['ok']) && $okTour > 0) {
    echo '<p class="bk-confirm-share"><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('share.php?t=' . $okTour)) . '">'
        . bk_e(bk_t('ShareBig')) . '</a></p>';
}
echo $err ? bk_msg('err', $err) : '';

// Waiting lists.
if ($waits) {
    echo '<section class="bk-block bk-wait" style="margin-bottom:16px"><h2>' . bk_e(bk_t('WaitLists')) . '</h2>';
    foreach ($waits as $w) {
        $self = bk_clean_licence($w->BwLicence) === bk_clean_licence($archer->BaLicence);
        $who  = $self ? '' : ' — ' . bk_t('ForWhom', trim($w->LueFamilyName . ' ' . $w->LueName) . ' (' . $w->BwLicence . ')');
        $what = ($w->DivDescription ?: $w->BwDivision) . ', ' . ($w->ClDescription ?: $w->BwClass) . ', '
              . (intval($w->BwSession) ? bk_t('DepLower', intval($w->BwSession)) : bk_t('AnyDep'));
        echo '<div class="bk-reg"><p><b>' . bk_e($w->ToName) . '</b> <span class="bk-hint">'
            . bk_e(bk_date_range($w->ToWhenFrom, $w->ToWhenTo) . $who) . '</span></p><p class="bk-tags">'
            . '<span class="bk-tag">' . bk_e($what) . '</span>';
        if (intval($w->BwStatus) === 0) {
            echo '<span class="bk-tag bk-tag-wait">' . bk_e(bk_t('Position', bk_waitlist_position($w))) . '</span></p>'
                . '<form method="post" onsubmit="return confirm(' . bk_e(json_encode(bk_t('WaitLeaveConfirm'), JSON_UNESCAPED_UNICODE)) . ')">'
                . bk_csrf_field() . '<input type="hidden" name="action" value="leave_wait"><input type="hidden" name="w" value="' . intval($w->BwId) . '">'
                . '<button type="submit" class="bk-btn bk-btn-danger">' . bk_e(bk_t('WaitLeaveBtn')) . '</button></form>';
        } elseif (intval($w->BwStatus) === 1) {
            echo '<span class="bk-tag bk-tag-on">' . bk_e(bk_t('WaitGotTag')) . '</span></p><p class="bk-org">'
                . bk_e(bk_t($self ? 'WaitGotTextSelf' : 'WaitGotTextMate', array('date' => bk_date_fr($w->BwDone), 'dep' => intval($w->BwSession))))
                . '</p>';
        } else {
            echo '<span class="bk-tag">' . bk_e(bk_t('WaitRemovedTag')) . '</span></p><p class="bk-org">' . bk_e($w->BwNote) . '</p>';
        }
        echo '</div>';
    }
    echo '</section>';
    bk_waitlist_mark_seen($archer->BaId, $archer->BaLicence);
}

if (!$regs && !$authored) {
    echo '<p class="bk-empty">' . bk_e(bk_t('NoRegs')) . ' <a href="' . bk_e(bk_public_url('calendar.php')) . '">'
        . bk_e(bk_t('SeeOpenComps')) . '</a>.</p>';
}
if ($authored) {
    echo '<div class="bk-tabs" id="bk-tabs" role="tablist">'
        . '<button type="button" class="bk-tab on" data-tab="mine">' . bk_e(bk_t('NavMyRegs')) . '</button>'
        . '<button type="button" class="bk-tab" data-tab="club">' . bk_e(bk_t('NavClub')) . ' <span class="bk-tab-count">' . count($authored) . '</span></button>'
        . '</div>';
}

echo '<div class="bk-tabpanel" data-panel="mine">';
if ($regs) {
    echo '<p style="margin:0 0 12px"><a class="bk-btn" href="' . bk_e(bk_public_url('calendar-ics.php')) . '">' . bk_e(bk_t('AddAgenda')) . '</a>'
        . ' <span class="bk-hint">' . bk_e(bk_t('AgendaHint')) . '</span></p>';
}
if (!$regs && $authored) echo '<p class="bk-empty">' . bk_e(bk_t('NoOwnRegs')) . '</p>';

if ($regs) {
    // One card per competition, one block per departure.
    $groups = array();
    foreach ($regs as $r) {
        $t = intval($r->BrTournament);
        if (!isset($groups[$t])) $groups[$t] = array('c' => $r, 'regs' => array());
        $groups[$t]['regs'][] = $r;
    }
    // Disciplines present, for the filter.
    $labels = bk_disc_labels();
    $discList = array();
    foreach ($groups as $g0) {
        $dd0 = bk_comp_discipline($g0['c']->ToType, $g0['c']->ToTypeSubRule, $g0['c']->ToTypeName);
        $discList[$dd0['key']] = $labels[$dd0['key']] ?? $dd0['key'];
    }
    asort($discList);

    echo '<div class="bk-reg-filters" id="bk-regfilters"><div class="bk-rf-status">'
        . '<button type="button" class="bk-rf-btn on" data-past="all">' . bk_e(bk_t('All')) . '</button>'
        . '<button type="button" class="bk-rf-btn" data-past="0">' . bk_e(bk_t('Upcoming')) . '</button>'
        . '<button type="button" class="bk-rf-btn" data-past="1">' . bk_e(bk_t('Past')) . '</button></div>';
    if (count($discList) > 1) {
        echo '<label class="bk-rf-disc">' . bk_e(bk_t('Discipline')) . ' <select id="bk-rf-discsel"><option value="all">' . bk_e(bk_t('All')) . '</option>';
        foreach ($discList as $dk => $dlab) echo '<option value="' . bk_e($dk) . '">' . bk_e($dlab) . '</option>';
        echo '</select></label>';
    }
    echo '</div><div class="bk-list">';

    foreach ($groups as $t => $g) {
        $c = $g['c'];
        $nb = count($g['regs']);
        $due  = bk_due_total($t, bk_clean_licence($archer->BaLicence));
        $paid = $due['remaining'] <= 0.005;
        $free = $due['total'] <= 0 && abs($due['paid']) < 0.005;
        $pastG = bk_is_finished($c->ToWhenTo) ? 1 : 0;
        $known = !$pastG || bk_ledger_tracked($t);   // over and not recorded here: nothing is claimed
        $pay  = (!$free && !$paid && $known) ? bk_payinfo_get(bk_comp_config($t)) : array();
        $ddG  = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
        $declRow = (!$free && !$paid) ? bk_payment_get($t, $archer->BaLicence) : null;
        $declM = $declRow ? (string) $declRow->PyDeclMethod : '';
        $declW = $declRow ? (string) $declRow->PyDeclWhen : '';

        echo '<article class="bk-item" data-past="' . $pastG . '" data-disc="' . bk_e($ddG['key']) . '"><div class="bk-item-main">'
            . '<h2 class="bk-item-h"><span class="bk-item-ic">' . bk_disc_icon($ddG['key'], 24) . '</span>' . bk_e($c->ToName) . '</h2>'
            . '<p class="bk-meta"><span>' . bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) . '</span>'
            . ($c->ToWhere ? '<span>' . bk_e($c->ToWhere) . '</span>' : '')
            . '<span class="bk-code">' . bk_e(bk_t($nb > 1 ? 'DepCountMany' : 'DepCountOne', $nb)) . '</span></p>'
            . '<div class="bk-reg-list">';
        foreach ($g['regs'] as $r) {
            echo '<div class="bk-reg">' . rg_tags($r)
                . (trim((string) $r->BrRequest) !== '' ? '<p class="bk-org">' . bk_e(bk_t('RequestX', $r->BrRequest)) . '</p>' : '')
                . '<div class="bk-reg-act">'
                . (!empty($r->BcAllowScoresheet) ? '<a class="bk-btn" href="' . bk_e(bk_public_url('scoresheet-official.php?enid=' . intval($r->BrEnId)))
                    . '" target="_blank" rel="noopener">' . bk_e(bk_t('Scoresheet')) . '</a>' : '')
                . ((!empty($c->BcIsOpen) && $r->BrByRole !== 'IMPORT') ? rg_cancel_form($r->BrEnId, 'CancelDepConfirm', 'CancelDepBtn') : '')
                . '</div></div>';
        }
        echo '</div>';

        if ($pay) {
            $isChosen = function ($pi) use ($declM, $declW) {
                return $declM !== '' && $pi['m'] === $declM && ($pi['when'] === 'both' || $pi['when'] === $declW);
            };
            $badge = bk_t('YourChoiceBadge') . ($declW ? ' — ' . bk_t($declW === 'before' ? 'WhenBeforeShort' : 'WhenOnsiteShort') : '');
            echo '<div class="bk-payinfo"><b>' . bk_e(bk_t('PayMeansTitle')) . '</b>' . rg_paylist($pay, $isChosen, $badge);
            if ($declM !== '' && !array_filter($pay, $isChosen)) {
                echo '<p class="bk-hint">' . bk_t('YourChoice', bk_e(bk_payment_decl_label($declM, $declW))) . '</p>';
            }
            echo '</div>';
        }
        echo '</div><div class="bk-item-act">';
        if (isset($svOpen[$t])) {
            echo '<p><a class="bk-btn bk-btn-primary" href="' . bk_e(bk_public_url('survey.php?t=' . $t)) . '">'
                . bk_e(bk_t(intval($svOpen[$t]->Answered) ? 'SurveyEditIcon' : 'SurveyGiveIcon')) . '</a></p>';
        }
        echo ($free ? '' : '<p class="bk-due">' . rg_balance($due, $known, $t) . '</p>')
            . '<p><a class="bk-btn" href="' . bk_e(bk_public_url('receipt.php?comp=' . $t)) . '">' . bk_e(bk_t('AccountReceipt')) . '</a></p>';
        // Pre-orders of the food & shop module, while its deadline is not passed.
        if (!$pastG) {
            $shpLinks = shp_public_links($t);
            if ($shpLinks['preorder'] !== '') {
                echo '<p><a class="bk-btn" href="' . bk_e($shpLinks['preorder']) . '">' . bk_e(shp_t('ShCusPreorderBtn')) . '</a></p>';
            }
        }
        if (bk_docs_list($c, $t) || bk_dossard_available($c, $t)) {
            echo '<p><a class="bk-btn" href="' . bk_e(bk_public_url('documents.php?t=' . $t)) . '">' . bk_e(bk_t('DocsBtn')) . '</a></p>';
        }
        echo '<p><a class="bk-btn" href="' . bk_e(bk_public_url('share.php?t=' . $t)) . '">' . bk_e(bk_t('ShareBtn')) . '</a></p>'
            . (!empty($c->BcIsOpen)
                ? '<p><a class="bk-btn" href="' . bk_e(bk_public_url('register-comp.php?t=' . $t)) . '">' . bk_e(bk_t('AddReg')) . '</a></p>'
                : '<p class="bk-hint">' . bk_e(bk_t('RegsClosed')) . '</p>')
            . '</div></article>';
    }
    echo '</div><p class="bk-empty" id="bk-reg-none" hidden>' . bk_e(bk_t('NoRegMatch')) . '</p>';
    ?>
  <script>
  (function () {
    var f = document.getElementById('bk-regfilters'); if (!f) return;
    var items = document.querySelectorAll('#bk .bk-list .bk-item');
    var none = document.getElementById('bk-reg-none');
    var status = 'all', disc = 'all';
    function apply() {
      var shown = 0;
      Array.prototype.forEach.call(items, function (a) {
        var ok = (status === 'all' || a.getAttribute('data-past') === status)
              && (disc === 'all' || a.getAttribute('data-disc') === disc);
        a.style.display = ok ? '' : 'none'; if (ok) shown++;
      });
      if (none) none.hidden = shown > 0;
    }
    Array.prototype.forEach.call(f.querySelectorAll('.bk-rf-btn'), function (b) {
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(f.querySelectorAll('.bk-rf-btn'), function (x) { x.classList.remove('on'); });
        b.classList.add('on'); status = b.getAttribute('data-past'); apply();
      });
    });
    var sel = document.getElementById('bk-rf-discsel');
    if (sel) sel.addEventListener('change', function () { disc = this.value; apply(); });
  })();
  </script>
    <?php
}

// Accounts on competitions without an online registration of this archer (entered by the
// organiser in ianseo, shop only): their balance and receipt belong here too.
$regTours = array();
foreach ($regs as $r) $regTours[intval($r->BrTournament)] = true;
$others = array_filter(bk_archer_accounts(bk_clean_licence($archer->BaLicence)), function ($x) use ($regTours) {
    return !isset($regTours[$x['ToId']]);
});
if ($others) {
    echo '<section class="bk-block" style="margin-top:16px"><h2>' . bk_e(bk_t('OtherComps')) . '</h2>'
        . '<p class="bk-hint">' . bk_e(bk_t('OtherCompsHint')) . '</p>';
    foreach ($others as $x) {
        echo '<div class="bk-reg"><p><b>' . bk_e($x['ToName']) . '</b> <span class="bk-hint">'
            . bk_e(bk_date_range($x['ToWhenFrom'], $x['ToWhenTo']) . ($x['ToWhere'] ? ' — ' . $x['ToWhere'] : '')) . '</span></p>'
            . '<p class="bk-due">' . rg_balance(array('total' => $x['due'], 'paid' => $x['paid'], 'remaining' => $x['remaining']),
                !$x['past'] || $x['tracked'], $x['ToId']) . '</p>'
            . '<p><a class="bk-btn" href="' . bk_e(bk_public_url('receipt.php?comp=' . $x['ToId'])) . '">' . bk_e(bk_t('AccountReceipt')) . '</a>'
            . (!$x['past'] && ($xl = shp_public_links($x['ToId'])['shop']) !== ''
                ? ' <a class="bk-btn" href="' . bk_e($xl) . '">' . bk_e(shp_t('ShCusBookingBtn')) . '</a>' : '')
            . '</p></div>';
    }
    echo '</section>';
}
// Payer trust index (AUTH core): the archer's own level and why, in its alert and block modes.
if (is_file(dirname(__DIR__, 2) . '/trust-lib.php')) {
    require_once dirname(__DIR__, 2) . '/trust-lib.php';
    $trustMe = aut_trust_archer_html(bk_clean_licence($archer->BaLicence));
    if ($trustMe !== '') echo '<section class="bk-block" style="margin-top:16px">' . $trustMe . '</section>';
}
echo '</div>';   // panel "mine"

if ($authored) {
    // Registrations made for clubmates — grouped by competition.
    $ag = array();
    foreach ($authored as $r) {
        $t = intval($r->BrTournament);
        if (!isset($ag[$t])) $ag[$t] = array('c' => $r, 'regs' => array());
        $ag[$t]['regs'][] = $r;
    }
    echo '<div class="bk-tabpanel" data-panel="club" hidden><section class="bk-authored">'
        . '<p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('ClubTabHint')) . '</p><div class="bk-authored-list">';
    foreach ($ag as $t => $g) {
        $c = $g['c'];
        $ddC = bk_comp_discipline($c->ToType, $c->ToTypeSubRule, $c->ToTypeName);
        $payC = bk_payinfo_get(bk_comp_config($t));   // means accepted (competition level)
        $anyUnpaid = false;
        echo '<article class="bk-item"><div class="bk-item-main">'
            . '<h3 class="bk-item-h"><span class="bk-item-ic">' . bk_disc_icon($ddC['key'], 22) . '</span>' . bk_e($c->ToName) . '</h3>'
            . '<p class="bk-meta"><span>' . bk_e(bk_date_range($c->ToWhenFrom, $c->ToWhenTo)) . '</span>'
            . ($c->ToWhere ? '<span>' . bk_e($c->ToWhere) . '</span>' : '') . '</p><div class="bk-reg-list">';
        foreach ($g['regs'] as $r) {
            $dueA  = bk_due_total($t, $r->BrLicence);
            $pyA   = bk_payment_get($t, $r->BrLicence);
            $paidA = $dueA['remaining'] <= 0.005;
            $declA = $pyA ? bk_payment_decl_label($pyA->PyDeclMethod ?? '', $pyA->PyDeclWhen ?? '') : '';
            if ($dueA['total'] > 0 && !$paidA) $anyUnpaid = true;
            echo '<div class="bk-reg">' . rg_tags($r, true);
            if ($dueA['total'] > 0 || abs($dueA['paid']) >= 0.005) {
                echo '<p class="bk-org">' . rg_balance($dueA, !bk_is_finished($c->ToWhenTo) || bk_ledger_tracked($t), $t)
                    . ($declA ? '&nbsp;·&nbsp; ' . bk_t('ChoiceX', bk_e($declA)) : '') . '</p>';
            }
            if (!empty($r->BcAllowScoresheet) || !empty($c->BcIsOpen)) {
                echo '<div class="bk-reg-act">'
                    . (!empty($r->BcAllowScoresheet) ? '<a class="bk-btn" href="' . bk_e(bk_public_url('scoresheet-official.php?enid=' . intval($r->BrEnId)))
                        . '" target="_blank" rel="noopener">' . bk_e(bk_t('Scoresheet')) . '</a>' : '')
                    . (!empty($c->BcIsOpen) ? rg_cancel_form($r->BrEnId, 'CancelRegConfirm', 'CancelBtn') : '')
                    . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        if ($payC && $anyUnpaid) echo '<div class="bk-payinfo"><b>' . bk_e(bk_t('PayMeansTitle')) . '</b>' . rg_paylist($payC) . '</div>';
        echo '</div></article>';
    }
    echo '</div></section></div>';
    ?>
  <script>
  (function () {
    var tabs = document.getElementById('bk-tabs'); if (!tabs) return;
    var panels = document.querySelectorAll('#bk .bk-tabpanel');
    Array.prototype.forEach.call(tabs.querySelectorAll('.bk-tab'), function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-tab');
        Array.prototype.forEach.call(tabs.querySelectorAll('.bk-tab'), function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        Array.prototype.forEach.call(panels, function (p) { p.hidden = (p.getAttribute('data-panel') !== key); });
      });
    });
  })();
  </script>
    <?php
}
?>
<script>
// Competition cards that fold: closed by default (title and dates only), a click on the head
// shows everything. One by one.
(function () {
  Array.prototype.forEach.call(document.querySelectorAll('#bk .bk-item'), function (it) {
    var h = it.querySelector('.bk-item-h'); if (!h) return;
    it.classList.add('bk-collapsible');
    var tog = document.createElement('span');
    tog.className = 'bk-item-toggle'; tog.setAttribute('aria-hidden', 'true'); tog.textContent = '⌄';
    h.appendChild(tog);
    function toggle() { it.classList.toggle('bk-open'); }
    h.addEventListener('click', toggle);
    var meta = it.querySelector('.bk-meta');
    if (meta) { meta.style.cursor = 'pointer'; meta.addEventListener('click', toggle); }
  });
})();
</script>
<?php bk_foot(); ?>
