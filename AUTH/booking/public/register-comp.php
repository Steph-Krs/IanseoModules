<?php
/**
 * public/register-comp.php — registration of a licensee for a competition.
 *
 * Every rule is checked again HERE, on the server, when writing: the calendar informs, it
 * does not allow. A public page can trust nothing the browser sends back.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/pricing.php';
require_once dirname(__DIR__) . '/lib/caps.php';
require_once dirname(__DIR__) . '/lib/targets.php';
require_once dirname(__DIR__) . '/lib/documents.php';   // bk_doc_distances
require_once dirname(__DIR__) . '/lib/payment.php';     // means of payment
require_once dirname(__DIR__) . '/lib/waitlist.php';

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? $_POST['t'] ?? 0);
$cfg    = bk_comp_config($tourId);
bk_money_tour($tourId);   // amounts of this page in its currency

/** Stops with a card "registration not possible". */
function bk_reg_stop($msg, $back = true)
{
    bk_head(bk_t('RegTitle'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('RegImpossible')) . '</h1>' . bk_msg('err', $msg)
       . ($back ? '<p class="bk-alt"><a href="' . bk_e(bk_public_url('calendar.php')) . '">' . bk_e(bk_t('BackCalendarPlain')) . '</a></p>' : '')
       . '</div>';
    bk_foot();
    exit;
}

// A competition not open cannot be registered for, even by direct address.
if (!$tourId || empty($cfg->BcIsOpen)) bk_reg_stop(bk_t('RegNotOpen'));

$rs = safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo, ToType FROM Tournament WHERE ToId = $tourId");
$tour = safe_fetch($rs);

// Competition over: no registration, even if the window was left open.
if ($tour && bk_is_finished($tour->ToWhenTo)) bk_reg_stop(bk_t('RegOver'));

// A place freed since the last look belongs to the waiting list, not to whoever opens
// this page first: the list is served BEFORE the places offered below are counted.
bk_waitlist_process($tourId);

// Federal identity of the SIGNED-IN archer: source of their club (which limits the group
// registration) and of their age (only an adult registers someone else).
$selfLue = bk_lookup_licence($archer->BaLicence);
if (!$selfLue) bk_reg_stop(bk_t('LicenceUnknown'), false);

// Group registration: an ADULT licensee may register a clubmate of THEIR club by typing
// their licence number. The SUBJECT of the registration is the signed-in archer by default
// ("self" mode); it becomes the clubmate found ("club" mode) as soon as a valid licence of
// their club is given. The rest of the page works on $lue (the subject) and $subjectLicence.
$canGroup   = bk_is_major($selfLue->LueCtrlCode);
$groupMode  = false;
$lue        = $selfLue;
$clubErr    = '';
$reqSubject = bk_clean_licence($_POST['subject'] ?? $_GET['subject'] ?? '');
if ($reqSubject !== '' && $reqSubject !== bk_clean_licence($archer->BaLicence)) {
    if (!$canGroup) {
        $clubErr = bk_t('OnlyAdultGroup');
    } else {
        $mate = bk_lookup_clubmate($reqSubject, $selfLue->LueCountry, (string) $selfLue->LueIocCode);
        if ($mate && bk_clean_licence($mate->LueCode) === bk_clean_licence($archer->BaLicence)) $mate = $lue;   // their own number
        elseif ($mate) { $groupMode = true; $lue = $mate; }
        else $clubErr = bk_t('MateUnknown');
    }
}
$subjectLicence = bk_clean_licence($lue->LueCode);

// Clubmates already registered by this archer and STILL in their club (shortcut of the
// group registration). The club is checked again: an archer who changed club is gone.
$mates = $canGroup ? bk_authored_clubmates($archer->BaId, $archer->BaLicence, $selfLue->LueCountry) : array();

$divisions = bk_reg_divisions($tourId);
$division  = (string) ($_POST['division'] ?? $_GET['division'] ?? '');
if ($division === '' || !isset($divisions[$division])) $division = (string) array_key_first($divisions);

$classes = $division !== '' ? bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $division) : array();
$class   = (string) ($_POST['class'] ?? '');
if ($class === '' || !isset($classes[$class])) $class = (string) array_key_first($classes);

$sessions = bk_comp_sessions($tourId);
$sesStates = bk_session_states($tourId, $cfg, $sessions);   // open, closed, waiting for the earlier ones…
$sessionOrder = intval($_POST['session'] ?? 0);
$request = trim((string) ($_POST['request'] ?? ''));

// Faces really possible for this category, from the competition setup — never a free list.
// Their sizes (cm) are kept too, to show them under the category.
$facesDispo = array();
$faceSizes  = array();
if ($division !== '' && $class !== '') {
    $fi = bk_with_tournament($tourId, function () use ($tourId, $division, $class) {
        $raw = bk_caps_faces_for($tourId, $division, $class);
        $sizes = array();
        foreach ($raw as $f) if (intval($f['cm']) > 0) $sizes[intval($f['cm'])] = true;
        krsort($sizes);
        return array('choices' => bk_caps_face_choices($tourId, $division, $class, $raw ?: null),
                     'sizes' => array_keys($sizes));
    });
    $facesDispo = $fi['choices'];
    $faceSizes  = $fi['sizes'];
}

// Distances of the category (lets the archer check outdoor I / outdoor N). Only DISTINCT
// distances (a competition often declares the same metres in two blocks D1/D2).
$catDists = ($division !== '' && $class !== '' && !empty($tour->ToType))
    ? bk_doc_distances($tourId, $tour->ToType, $division, $class) : array();
$catMetres = array();
foreach ($catDists as $d) if (intval($d['metres']) > 0) $catMetres[intval($d['metres'])] = true;
krsort($catMetres);
$catMetres = array_keys($catMetres);

// SPECIFIC gauge: places left PER DEPARTURE for this PROFILE (bow type + category + face
// chosen), on top of the overall gauge. 0 ⇒ the departure is full for this profile and the
// registration is refused there (admission check, face sharing).
$curFace = 0;
if ($facesDispo) {
    $want = intval($_POST['face'] ?? 0);
    $curFace = ($want && isset($facesDispo[$want])) ? $want : intval(array_key_first($facesDispo));
}
$profileLeft = array();
if ($division !== '' && $class !== '' && $curFace > 0 && function_exists('bk_profile_remaining')) {
    $profileLeft = bk_with_tournament($tourId, function () use ($tourId, $sessions, $division, $class, $curFace) {
        $out = array();
        foreach ($sessions as $s) {
            $r = bk_profile_remaining($tourId, intval($s->SesOrder), $division, $class, $curFace);
            $out[intval($s->SesOrder)] = ($r === null) ? null : intval($r);
        }
        return $out;
    });
}

// Registrations this archer already has on this competition: to offer another departure and
// announce the effect on the events.
$mine = bk_reg_existing($tourId, $subjectLicence);
$mineSessions = array();
foreach ($mine as $d) $mineSessions[intval($d->QuSession)] = true;
// One registration per archer (option): this subject already has theirs.
$singleDone = !empty($cfg->BcSingleReg) && $mine;

// Waiting list (lib/waitlist.php): with the list on, a departure full for this profile
// stays selectable and the same form puts the archer on its list instead of registering.
// One waiting request per weapon: a departure of a weapon already waited for is not offered.
$waitOn = bk_waitlist_on($cfg);
$myWait = array();   // weapon => waiting row of the subject of this form
foreach (bk_waitlist_for_archer($archer->BaId, $archer->BaLicence) as $w) {
    if (intval($w->BwTournament) === $tourId && intval($w->BwStatus) === 0
        && bk_clean_licence($w->BwLicence) === $subjectLicence) $myWait[$w->BwDivision] = $w;
}
$sessionFull = array();   // departure => full for this profile
foreach ($sessions as $s) {
    $o = intval($s->SesOrder);
    $sessionFull[$o] = bk_waitlist_full($s, $profileLeft[$o] ?? null);
}

// Tariff: origin and rank are fixed for this registration (the club and the number of
// registrations already made do not depend on the form); only category and departure change
// the price, computed again live on the browser side.
$pricing  = bk_pricing_get($cfg);
$provTier = bk_prov_tier($pricing, $lue->LueCountry);
$nextRank = count($mine) + 1;
$provDelta = $provTier === 'dept' ? (float) $pricing['prov']['dept']
           : ($provTier === 'region' ? (float) $pricing['prov']['region'] : 0.0);
$rankDelta = 0.0; $rankTh = 0;
foreach ($pricing['rank'] as $th => $d) {
    $th = (int) $th;
    if ($nextRank >= $th && $th > $rankTh) { $rankTh = $th; $rankDelta = (float) $d; }
}
$calc = bk_price_calc($cfg->BcFee, $pricing, $division, $class, $sessionOrder, $provTier, $nextRank);
$showPrice = ((float) $cfg->BcFee > 0) || bk_pricing_is_advanced($pricing);

// Payment choices offered to the competitor (means + when), when there is a fee.
$payChoices = array(); $payDecl = '';
if ($showPrice) {
    $payChoices = bk_payinfo_choices(bk_payinfo_get($cfg));
    if ($payChoices) {
        $pRow = bk_payment_get($tourId, $subjectLicence);
        if ($pRow && $pRow->PyDeclMethod !== '') $payDecl = $pRow->PyDeclMethod . '|' . $pRow->PyDeclWhen;
    }
}

// Clubmates already registered, for the wish "on the same target as…".
$clubmates = array();
if ($lue->LueCountry) {
    $rs = safe_r_sql("SELECT EnCode, EnFirstName, EnName, QuSession
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Countries ON CoId = EnCountry
        WHERE EnTournament = " . intval($tourId) . " AND EnAthlete = 1
          AND CoCode = " . StrSafe_DB($lue->LueCountry) . "
          AND EnCode <> " . StrSafe_DB($subjectLicence) . "
        ORDER BY EnFirstName, EnName");
    while ($r = safe_fetch($rs)) $clubmates[] = $r;
}

// Letters available on this departure (A, B, C… from SesAth4Target).
$letters = array();
foreach ($sessions as $s) {
    if (intval($s->SesOrder) === $sessionOrder || (!$sessionOrder && !$letters)) {
        for ($i = 0; $i < intval($s->SesAth4Target); $i++) $letters[] = chr(65 + $i);
    }
}

$err = '';
$geo = bk_comp_archer_blocked($cfg, $lue->LueCountry);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['go'] ?? '') === '1') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif ($class === '' || $division === '') {
        $err = bk_t('ChooseWeaponCat');
    } elseif ((string) ($_POST['class'] ?? '') !== $class) {
        // The category posted is not in the allowed list: it is not replaced silently, the
        // archer is told (forged POST or stale form).
        $err = bk_t('CatNotForAge');
    } elseif ($groupMode && !$canGroup) {
        // Guard on the write side: a forged POST must not get round the age rule.
        $err = bk_t('OnlyAdultGroup');
    } elseif ($waitOn && !empty($sessionFull[$sessionOrder])) {
        // Full departure: the SERVER decides to queue, from the state seen after the list
        // was served above — never the label of the button, which is only a display.
        $res = bk_waitlist_join($tourId, $cfg, $lue, $division, $class, $curFace, $sessionOrder,
            array('role' => $groupMode ? 'CLUB' : 'SELF', 'who' => $archer->BaLicence, 'archer' => $archer->BaId),
            array('letter' => $_POST['letter'] ?? '', 'with' => $_POST['with'] ?? '', 'request' => $request,
                  'pay' => $_POST['pay_choice'] ?? ''));
        if (!empty($res['ok'])) {
            bk_log('WAIT_JOIN', $subjectLicence);
            bk_waitlist_process($tourId);   // a place may have freed meanwhile: then registered at once
            bk_redirect('registrations.php?wait=1&t=' . $tourId);
        }
        $err = $res['msg'];
    } else {
        $err = bk_reg_blocked($tourId, $cfg, $subjectLicence, $lue->LueCountry,
            $division, $class, $sessionOrder, $lue);
        if ($err === '') {
            $res = bk_register($tourId, $lue, $division, $class, $sessionOrder, $request, array(
                'role'   => $groupMode ? 'CLUB' : 'SELF',
                'who'    => $archer->BaLicence,   // author of the registration
                'archer' => $archer->BaId,
            ), array(
                'face'   => intval($_POST['face'] ?? 0),
                'letter' => (string) ($_POST['letter'] ?? ''),
                'with'   => (string) ($_POST['with'] ?? ''),
            ));
            if (!empty($res['ok'])) {
                bk_log('REG_NEW', $subjectLicence);
                // Means of payment wished, given to the SUBJECT (amount due of their account)
                // — informs the organiser, also for a group registration.
                if (!empty($_POST['pay_choice'])) {
                    $pc = explode('|', (string) $_POST['pay_choice'], 2);
                    if (count($pc) === 2) bk_payment_declare($tourId, $subjectLicence, $pc[0], $pc[1]);
                }
                // Placement only when the registration is validated (automatic). With manual
                // validation, the placement waits for the organiser.
                if (!empty($res['validated'])) {
                    bk_replan_session($tourId, $sessionOrder, $cfg);
                }
                bk_redirect('registrations.php?ok=1&t=' . $tourId
                    . ($groupMode ? '&s=' . rawurlencode($subjectLicence) : ''));
            }
            $err = $res['msg'] ?? bk_t('RegFailed');
        }
    }
}

$mateName = trim($lue->LueFamilyName . ' ' . $lue->LueName);
$sel = function ($on) { return $on ? ' selected' : ''; };

bk_head(bk_t('RegTitle'));
echo '<div class="bk-block" style="margin-bottom:16px"><h2>' . bk_e($tour->ToName) . '</h2><p class="bk-meta">'
    . '<span>' . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . '</span>'
    . ($tour->ToWhere ? '<span>' . bk_e($tour->ToWhere) . '</span>' : '') . '</p></div>';

if ($geo !== '') {
    echo bk_msg('err', $geo);
    bk_foot();
    exit;
}
echo ($err ? bk_msg('err', $err) : '') . ($clubErr ? bk_msg('err', $clubErr) : '');

// Who is registered.
echo '<div class="bk-block"><h2>' . bk_e(bk_t($groupMode ? 'RegClubMate' : 'YourInfo')) . '</h2><dl class="bk-dl">'
    . '<dt>' . bk_e(bk_t('Licence')) . '</dt><dd>' . bk_e($lue->LueCode) . '</dd>'
    . '<dt>' . bk_e(bk_t('FullName')) . '</dt><dd>' . bk_e($lue->LueFamilyName) . ' ' . bk_e($lue->LueName) . '</dd>'
    . '<dt>' . bk_e(bk_t('BornOn')) . '</dt><dd>' . bk_e(bk_date_fr($lue->LueCtrlCode)) . '</dd>'
    . '<dt>' . bk_e(bk_t('Club')) . '</dt><dd>' . bk_e($lue->LueCoDescr) . '</dd></dl>';
if ($groupMode) {
    echo '<p class="bk-hint">' . bk_e(bk_t('GroupHint', $archer->BaLicence)) . '</p>'
        . '<p><a class="bk-btn" href="' . bk_e(bk_public_url('register-comp.php?t=' . $tourId)) . '">' . bk_e(bk_t('BackOwnReg')) . '</a></p>';
} else {
    echo '<p class="bk-hint">' . bk_e(bk_t('InfoFromFile')) . '</p>';
}
if ($canGroup) {
    echo '<details class="bk-group-switch"' . ((($clubErr || $singleDone) && !$groupMode) ? ' open' : '') . '><summary>'
        . bk_e(bk_t($groupMode ? 'RegisterOtherMate' : 'RegisterMate')) . '</summary><div class="bk-group-body">';
    if ($mates) {
        echo '<label for="matesel">' . bk_e(bk_t('MateAlready')) . '</label>'
            . '<select id="matesel" data-base="' . bk_e(bk_public_url('register-comp.php?t=' . $tourId . '&subject=')) . '"'
            . ' onchange="if(this.value){location.href=this.getAttribute(\'data-base\')+encodeURIComponent(this.value);}">'
            . '<option value="">' . bk_e(bk_t('Choose')) . '</option>';
        foreach ($mates as $lic => $name) {
            echo '<option value="' . bk_e($lic) . '"' . $sel($groupMode && $lic === $subjectLicence) . '>'
                . bk_e($name) . ' (' . bk_e($lic) . ')</option>';
        }
        echo '</select><p class="bk-hint" style="margin-bottom:8px">' . bk_e(bk_t('OrNewLicence')) . '</p>';
    } else {
        echo '<p class="bk-hint">' . bk_t('MateHint') . '</p>';
    }
    echo '<form method="get" action="' . bk_e(bk_public_url('register-comp.php')) . '" class="bk-group-form">'
        . '<input type="hidden" name="t" value="' . intval($tourId) . '">'
        . '<label for="subjlic">' . bk_e(bk_t('LicenceNumber')) . '</label>'
        . '<input type="text" id="subjlic" name="subject" placeholder="' . bk_e(bk_t('ExampleX', $archer->BaLicence)) . '" autocomplete="off" required>'
        . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('Continue')) . '</button></form></div></details>';
}
echo '</div>';

if ($singleDone) {
    // Only a clubmate can still be registered, through the box above.
    echo '<div class="bk-block" style="margin-top:14px"><h2>' . bk_e($groupMode ? $mateName : bk_t('YourReg')) . '</h2>'
        . '<p class="bk-blocked">' . bk_e(bk_t($groupMode ? 'SingleDoneMate' : 'SingleDoneSelf')) . '</p>'
        . '<p><a class="bk-btn" href="' . bk_e(bk_public_url('registrations.php?t=' . $tourId)) . '">' . bk_e(bk_t('NavMyRegs')) . '</a></p></div>';
    bk_foot();
    exit;
}

if (intval($lue->LueStatus) === 9) {
    // Licence without practice: no registration for this subject.
    echo '<div class="bk-block" style="margin-top:14px"><h2>' . bk_e($groupMode ? $mateName : bk_t('YourReg')) . '</h2>'
        . '<p class="bk-blocked">' . bk_t(($groupMode ? 'NoPracticeMate' : 'NoPracticeSelf')
            . (($lue->LueIocCode ?? 'FRA') !== 'FRA' ? 'Other' : '')) . '</p></div>';
    bk_foot();
    exit;
}

echo '<form method="post" class="bk-block" style="margin-top:14px" id="bkreg">' . bk_csrf_field()
    . '<input type="hidden" name="t" value="' . intval($tourId) . '"><input type="hidden" name="go" value="1">'
    . ($groupMode ? '<input type="hidden" name="subject" value="' . bk_e($subjectLicence) . '">' : '')
    . '<h2>' . bk_e($groupMode ? bk_t('TheirReg', $mateName) : bk_t('YourReg')) . '</h2>';

// Bow type and category: a change reloads the form (the categories depend on the bow type).
echo '<label for="division">' . bk_e(bk_t('Weapon')) . '</label>'
    . '<select id="division" name="division" onchange="this.form.go.value=\'0\';this.form.submit()">';
foreach ($divisions as $k => $lab) echo '<option value="' . bk_e($k) . '"' . $sel($division === (string) $k) . '>' . bk_e($lab) . '</option>';
echo '</select><label for="class">' . bk_e(bk_t('Category')) . '</label>';
if (!$classes) {
    echo '<p class="bk-blocked">' . bk_e(bk_t('NoCatForAge')) . '</p>';
} else {
    echo '<select id="class" name="class" onchange="this.form.go.value=\'0\';this.form.submit()">';
    foreach ($classes as $k => $lab) echo '<option value="' . bk_e($k) . '"' . $sel($class === (string) $k) . '>' . bk_e($lab) . '</option>';
    echo '</select><p class="bk-hint">' . bk_e(bk_t('CatFromBirth')) . '</p>';
    if ($catMetres || $faceSizes) {
        echo '<p class="bk-catinfo">'
            . ($catMetres ? '<b>' . bk_e(bk_t('DistanceLbl')) . '</b> ' . bk_e(implode(' / ', array_map(function ($m) { return $m . ' m'; }, $catMetres))) : '')
            . ($faceSizes ? ($catMetres ? ' &nbsp;—&nbsp; ' : '') . '<b>' . bk_e(bk_t('FaceLbl')) . '</b> '
                . bk_e(implode(' / ', array_map(function ($cm) { return $cm . ' cm'; }, $faceSizes))) : '')
            . '</p>';
    }
}

// Face: chosen among those the setup plans for the category. A change reloads the form:
// the gauge "places for your face" per departure depends on it, computed on the server.
if (count($facesDispo) > 1) {
    echo '<label for="face">' . bk_e(bk_t('FaceType')) . '</label>'
        . '<select id="face" name="face" onchange="this.form.go.value=\'0\';this.form.submit()">';
    foreach ($facesDispo as $id => $lab) {
        echo '<option value="' . intval($id) . '"' . $sel(intval($_POST['face'] ?? 0) === intval($id)) . '>' . bk_e($lab) . '</option>';
    }
    echo '</select><p class="bk-hint">' . bk_e(bk_t('FaceTypeHint')) . '</p>';
} elseif ($facesDispo) {
    echo '<label>' . bk_e(bk_t('Face')) . '</label><p class="bk-fixed">' . bk_e(reset($facesDispo))
        . ' <span class="bk-hint">' . bk_e(bk_t('FaceForCat')) . '</span></p>';
}

// Waiting rows of this archer on this competition (one per weapon).
foreach ($myWait as $w) {
    $what = ($w->DivDescription ?: $w->BwDivision) . ', '
        . (intval($w->BwSession) ? bk_t('DepLower', intval($w->BwSession)) : bk_t('AnyDep'));
    echo '<p class="bk-note">' . bk_t($groupMode ? 'WaitOnListMate' : 'WaitOnListYou',
        array('what' => bk_e($what), 'pos' => bk_waitlist_position($w))) . '</p>';
}

// Departures, with what is left of each one.
$free = 0; $waitable = 0; $selWait = false;
echo '<label for="session">' . bk_e(bk_t('DepartureLbl')) . '</label><select id="session" name="session" required>'
    . '<option value="">' . bk_e(bk_t('Choose')) . '</option>';
foreach ($sessions as $s) {
    $o = intval($s->SesOrder);
    $left = max(0, intval($s->Places) - intval($s->Pris));
    $taken = isset($mineSessions[$o]);
    // Specific gauge: places for THIS profile. null = no constraint known.
    $pl = array_key_exists($o, $profileLeft) ? $profileLeft[$o] : null;
    $profFull = ($pl !== null && $pl < 1);
    // Departure closed, not open yet or waiting for the earlier ones (lib/sessionrules.php).
    $shut = isset($sesStates[$o]) && !$sesStates[$o]['open'];
    $avail = ($left > 0 && !$taken && !$profFull && !$shut);
    // Full departure: selectable when the waiting list is on and this weapon is not already
    // waited for — the form then joins the list (data-wait, script below).
    $wait = !$avail && !$taken && !$shut && $waitOn && !isset($myWait[$division]);
    if ($avail) $free++;
    if ($wait) $waitable++;
    if ($wait && $sessionOrder === $o) $selWait = true;

    $label = bk_t('DepCap', $o) . ($s->SesName ? ' — ' . $s->SesName : '');
    $ss = bk_session_start($s);
    if ($ss !== '') {
        $hm = substr($ss, 11, 5);   // bytes: ASCII time
        $label .= ' (' . (($hm !== '' && $hm !== '00:00')
            ? bk_t('DateAt', array('date' => bk_date_fr($ss), 'time' => date(bk_t('TimeFormat'), strtotime($ss))))
            : bk_date_fr($ss)) . ')';
    }
    if ($taken) {
        $state = bk_t('StAlready');
    } elseif ($shut) {
        $state = bk_session_state_text($sesStates[$o]);
    } elseif (!$avail) {
        $state = bk_t($left === 0 ? 'StFull' : 'StFullFace');
        if ($wait) $state .= ' · ' . bk_t('StWait');
        elseif ($waitOn && isset($myWait[$division])) $state .= ' · ' . bk_t('StWaitAlready');
    } else {
        $state = bk_t($left > 1 ? 'PlacesMany' : 'PlacesOne', $left);
        if ($pl !== null) $state .= ' · ' . bk_t('StForFace', intval($pl));
    }
    echo '<option value="' . $o . '"' . ((!$avail && !$wait) ? ' disabled' : '') . ($wait ? ' data-wait="1"' : '')
        . $sel($sessionOrder === $o) . '>' . bk_e($label . ' — ' . $state) . '</option>';
}
echo '</select>';
if (!$free && !$waitable) {
    echo '<p class="bk-blocked">' . bk_e(bk_t('NoDepAvail')) . '</p>';
} else {
    echo '<p class="bk-hint">' . bk_e(bk_t('ForFaceHint') . ' ' . bk_t($waitable ? 'ForFaceWait' : 'ForFaceNoWait')) . '</p>';
}

// Shown when the departure chosen is full (script below; rendered visible already when the
// page comes back with such a departure selected, so it also reads without script).
echo '<div class="bk-note" id="bk-wait-note"' . ($selWait ? '' : ' hidden') . '>'
    . bk_t($groupMode ? 'WaitNoteMate' : 'WaitNoteSelf') . '</div>';

// Extra shoot: with the same weapon, only the first shoot OF THE COMPETITION counts for its
// ranking (lib/registration.php, bk_events_to_first_shoot) — an earlier departure added
// later takes the ranking over from the one already registered.
if ($mine) {
    $sameWeapon = array();
    foreach ($mine as $d) if ((string) $d->EnDivision === (string) $division) $sameWeapon[] = intval($d->QuSession);
    sort($sameWeapon);
    $n = count($mine);
    echo '<div class="bk-note">' . bk_t(($groupMode ? 'ExtraMate' : 'ExtraSelf') . ($n > 1 ? 'Many' : 'One'), $n) . ' ';
    if ($sameWeapon) {
        $last = array_pop($sameWeapon);
        echo bk_t($groupMode ? 'ExtraSameMate' : 'ExtraSameSelf', array(
            'weapon' => bk_e($divisions[$division] ?? $division),
            'where'  => $sameWeapon ? bk_t('AtDeps', array('list' => implode(', ', $sameWeapon), 'last' => $last)) : bk_t('AtDep', $last),
            'then'   => $sameWeapon ? bk_t('ThenOthersWill') : bk_t('ThenDepWill', $last),
        ));
    } else {
        echo bk_e(bk_t('ExtraOtherWeapon'));
    }
    echo '</div>';
}

// Wishes offered by the organiser.
if ($cfg->BcWishLetter || $cfg->BcWishWith || $cfg->BcWishFree) {
    echo '<fieldset class="bk-wishes"><legend>' . bk_e(bk_t('MyWishes')) . ' <span class="bk-opt">' . bk_e(bk_t('Optional')) . '</span></legend>';
    if ($cfg->BcWishLetter) {
        echo '<label for="letter">' . bk_e(bk_t('WishLetter')) . '</label><select id="letter" name="letter">'
            . '<option value="">' . bk_e(bk_t('NoMatter')) . '</option>';
        foreach ($letters as $L) echo '<option value="' . bk_e($L) . '"' . $sel(($_POST['letter'] ?? '') === $L) . '>' . bk_e($L) . '</option>';
        echo '</select>';
    }
    if ($cfg->BcWishWith) {
        echo '<label for="with">' . bk_e(bk_t('WishWith')) . '</label>';
        if ($clubmates) {
            echo '<select id="with" name="with"><option value="">' . bk_e(bk_t('NoMatter')) . '</option>';
            foreach ($clubmates as $c) {
                echo '<option value="' . bk_e($c->EnCode) . '"' . $sel(($_POST['with'] ?? '') === $c->EnCode) . '>'
                    . bk_e($c->EnFirstName . ' ' . $c->EnName) . ' (' . bk_e(bk_t('DepLower', intval($c->QuSession))) . ')</option>';
            }
            echo '</select><p class="bk-hint">' . bk_e(bk_t('WishWithHint')) . '</p>';
        } else {
            echo '<p class="bk-fixed bk-hint">' . bk_e(bk_t('WishNoMate')) . '</p><input type="hidden" name="with" value="">';
        }
    }
    if ($cfg->BcWishFree) {
        echo '<label for="request">' . bk_e(bk_t('WishFree')) . '</label>'
            . '<textarea id="request" name="request" rows="2" maxlength="2000" placeholder="' . bk_e(bk_t('WishFreePh')) . '">'
            . bk_e($request) . '</textarea><p class="bk-hint">' . bk_e(bk_t('WishFreeHint')) . '</p>';
    }
    if ($cfg->BcWishLetter || $cfg->BcWishWith) echo '<p class="bk-hint">' . bk_e(bk_t('WishPlaceHint')) . '</p>';
    echo '</fieldset>';
}

// Tariff, recomputed live by the script below.
if ($showPrice) {
    echo '<div class="bk-price" id="bk-price"><h3>' . bk_e(bk_t('Tariff')) . '</h3>'
        . '<table class="bk-price-t"><tbody id="bk-price-lines">';
    foreach ($calc['lines'] as $i => $ln) {
        echo '<tr><td>' . bk_e($ln['label']) . '</td><td class="bk-price-num">' . bk_e(bk_eur($ln['amount'], $i > 0)) . '</td></tr>';
    }
    echo '</tbody></table><p class="bk-price-tot">' . bk_e(bk_t('TotalLbl')) . ' <b id="bk-price-total">'
        . bk_e(bk_eur($calc['total'])) . '</b></p><p class="bk-hint">' . bk_e(bk_t('PriceEstimate')) . '</p></div>';
}

if ($payChoices) {
    echo '<fieldset class="bk-wishes"><legend>' . bk_e(bk_t('Payment')) . ' <span class="bk-opt">' . bk_e(bk_t('Optional')) . '</span></legend>'
        . '<p class="bk-hint">' . bk_e(bk_t('PayHint')) . '</p>'
        . '<label for="pay_choice">' . bk_e(bk_t('PayChoiceLbl')) . '</label><select id="pay_choice" name="pay_choice">'
        . '<option value="">' . bk_e(bk_t('PayLater')) . '</option>';
    foreach ($payChoices as $pc) echo '<option value="' . bk_e($pc['value']) . '"' . $sel($payDecl === $pc['value']) . '>' . bk_e($pc['label']) . '</option>';
    echo '</select>';
    // A list cannot hold links: the means whose details carry a web address (online payment
    // page, pot…) are repeated below it, clickable.
    $payLinks = array_filter(bk_payinfo_get($cfg), function ($pi) { return bk_linkify($pi['info']) !== bk_e($pi['info']); });
    if ($payLinks) echo '<div class="bk-payinfo">' . bk_paylist_html($payLinks) . '</div>';
    echo '</fieldset>';
}

$lblReg  = bk_t($groupMode ? 'ConfirmMate' : 'ConfirmSelf');
$lblWait = bk_t($groupMode ? 'WaitJoinMate' : 'WaitJoinSelf');
echo '<button type="submit" class="bk-btn bk-btn-primary" id="bk-submit" data-reg="' . bk_e($lblReg)
    . '" data-wait="' . bk_e($lblWait) . '"' . ((!$classes || (!$free && !$waitable)) ? ' disabled' : '') . '>'
    . bk_e($selWait ? $lblWait : $lblReg) . '</button></form>';
?>
<script>
// Full departure chosen: the button says what will happen (the server decides anyway).
(function () {
  var sel = document.getElementById('session'), btn = document.getElementById('bk-submit'),
      note = document.getElementById('bk-wait-note');
  if (!sel || !btn) return;
  function upd() {
    var o = sel.options[sel.selectedIndex], wait = !!(o && o.getAttribute('data-wait'));
    btn.textContent = btn.getAttribute(wait ? 'data-wait' : 'data-reg');
    if (note) note.hidden = !wait;
  }
  sel.addEventListener('change', upd);
  upd();
})();
</script>
<?php if ($showPrice): ?>
<script>
var BK_PRICE = <?= json_encode(array(
    'base'      => (float) $cfg->BcFee,
    'cats'      => array_map(function ($c) {
                       return array('label' => $c['label'], 'div' => $c['div'],
                                    'cls' => $c['cls'], 'price' => (float) $c['price']);
                   }, $pricing['categories']),
    'deps'      => (object) $pricing['departures'],
    'prov'      => $provDelta,
    'provLabel' => $provTier === 'dept' ? bk_t('PriceDept') : ($provTier === 'region' ? bk_t('PriceRegion') : ''),
    'rank'      => $rankDelta,
    'rankLabel' => $rankTh > 0 ? bk_t('PriceRank', $nextRank) : '',
    // Texts of the visitor's language, number separators of the core, currency of the competition.
    't'         => array('base' => bk_t('PriceBase'), 'cat' => bk_t('PriceCat'), 'catNamed' => bk_t('PriceCatNamed'),
                         'dep' => bk_t('DepCap'), 'cur' => bk_currency($tourId)) + bk_number_seps(),
), JSON_UNESCAPED_UNICODE) ?>;
(function () {
  var f = document.getElementById('bkreg'); if (!f) return;
  var T = BK_PRICE.t;
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  // As the server (bk_eur): number with the separators of the language, a space, the currency.
  function eur(n, signed){
    var s = n < 0 ? '−' : (signed ? '+' : ''), p = Math.abs(n).toFixed(2).split('.');
    return s + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, T.thousands) + T.dec + p[1] + ' ' + T.cur;
  }
  function calc() {
    var div = f['division'] ? f['division'].value : '',
        cls = f['class'] ? f['class'].value : '',
        ses = f['session'] ? f['session'].value : '';
    var base = BK_PRICE.base, label = T.base;
    for (var i = 0; i < BK_PRICE.cats.length; i++) {
      var c = BK_PRICE.cats[i];
      var okD = !c.div.length || c.div.indexOf(div) >= 0;
      var okC = !c.cls.length || c.cls.indexOf(cls) >= 0;
      if (okD && okC) { base = c.price; label = c.label ? T.catNamed.replace('{$a}', c.label) : T.cat; break; }
    }
    var lines = [[label, base, false]], total = base;
    if (ses && BK_PRICE.deps[ses] !== undefined) { lines.push([T.dep.replace('{$a}', ses), BK_PRICE.deps[ses], true]); total += BK_PRICE.deps[ses]; }
    if (BK_PRICE.prov) { lines.push([BK_PRICE.provLabel, BK_PRICE.prov, true]); total += BK_PRICE.prov; }
    if (BK_PRICE.rank) { lines.push([BK_PRICE.rankLabel, BK_PRICE.rank, true]); total += BK_PRICE.rank; }
    total = Math.max(0, total);
    var html = '';
    for (var j = 0; j < lines.length; j++) html += '<tr><td>' + esc(lines[j][0]) + '</td><td class="bk-price-num">' + eur(lines[j][1], lines[j][2]) + '</td></tr>';
    var body = document.getElementById('bk-price-lines'); if (body) body.innerHTML = html;
    var tot = document.getElementById('bk-price-total'); if (tot) tot.textContent = eur(total, false);
  }
  ['class', 'session'].forEach(function (n) { if (f[n]) f[n].addEventListener('change', calc); });
  calc();
})();
</script>
<?php endif; ?>
<?php bk_foot(); ?>
