<?php
/**
 * lib/mandate.php — the competition's mandate (document of the organiser).
 *
 * On screen it is HTML (bk_mandate_document); on paper, the same content goes through the core's
 * PDF classes (lib/mandate-pdf.php). Its content is FILLED IN
 * AUTOMATICALLY from the competition (name, dates, place, departures, categories, tariffs,
 * means of payment); the organiser only adds free text blocks, a template and a colour. No
 * free layout, no word processor — the frame stays simple and consistent.
 *
 * The logos (top left / top right / bottom) are those already uploaded in ianseo
 * (Tournament/ManLogo.php: columns ToImgL / ToImgR / ToImgB), served by Common/TourLogo.php —
 * never asked again by the module.
 */

if (defined('BK_MANDATE_LOADED')) return;
define('BK_MANDATE_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/ffta-event.php';   // itinerary links
require_once __DIR__ . '/field-events.php';  // what can really be shot (bk_field_possible)

/** Available templates: key => readable label. */
function bk_mandate_templates()
{
    return array(
        'sobre'   => bk_t('MnTplSobre'),
        'moderne' => bk_t('MnTplModerne'),
        'bandeau' => bk_t('MnTplBandeau'),
        'encadre' => bk_t('MnTplEncadre'),
        'ligne'   => bk_t('MnTplLigne'),
        'compact' => bk_t('MnTplCompact'),
    );
}

/**
 * Templates of the SHAREABLE PICTURE (share.php), distinct from the mandate's but driven by
 * the SAME colour (common identity). key => label. The drawing (canvas) lives in share.php —
 * to add or change a template: add a key here AND its case in the switch of share.php.
 */
function bk_share_templates()
{
    return array(
        'bandeau' => bk_t('ShTplBandeau'),
        'degrade' => bk_t('ShTplDegrade'),
        'encadre' => bk_t('ShTplEncadre'),
        'moitie'  => bk_t('ShTplMoitie'),
        'epure'   => bk_t('ShTplEpure'),
    );
}

/** Free text blocks offered (key => label). An empty block is not shown. */
function bk_mandate_sections()
{
    return array(
        'intro'    => bk_t('MnSecIntro'),
        'access'   => bk_t('MnSecAccess'),
        'lodging'  => bk_t('MnSecLodging'),
        'catering' => bk_t('MnSecCatering'),
        'awards'   => bk_t('MnSecAwards'),
        'contact'  => bk_t('MnSecContact'),
        'misc'     => bk_t('MnSecMisc'),
    );
}

/** Automatic sections that can be hidden (key => label). */
function bk_mandate_auto_sections()
{
    return array(
        'sessions'   => bk_t('MnSessions'),
        'program'    => bk_t('MnProgram'),
        'staff'      => bk_t('MnStaff'),
        // Key kept from when the section listed bows and classes: the organisers' choice stays.
        'categories' => bk_t('MnFormat'),
        'fees'       => bk_t('MnFees'),
        'payment'    => bk_t('PayMeansTitle'),
        'shop'       => bk_t('Shop'),
        'register'   => bk_t('Brand'),
    );
}

/**
 * Settings of the mandate (from the BcMandate JSON column), with sound defaults: a competition
 * never set up still gives a correct mandate.
 */
function bk_mandate_get($cfg)
{
    // Default: every automatic section shown (including 'shop' — the key MUST come from
    // bk_mandate_auto_sections(), otherwise a section added later would never be read back
    // from the JSON — real bug on the shop).
    $show = array();
    foreach (bk_mandate_auto_sections() as $k => $_l) $show[$k] = 1;
    $d = array(
        'template'       => 'sobre',
        'share_template' => 'bandeau',                  // template of the shareable picture (share.php)
        'color'          => '#0254a8',                  // default blue (shared by mandate and picture)
        'logos'          => array('L' => 1, 'R' => 1, 'B' => 1),
        'show'           => $show,
        'blocks'         => array(),                    // section key => free text
    );
    $raw = is_object($cfg) ? ($cfg->BcMandate ?? null) : (is_array($cfg) ? ($cfg['BcMandate'] ?? null) : null);
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (!empty($j['template']) && array_key_exists($j['template'], bk_mandate_templates())) {
                $d['template'] = $j['template'];
            }
            if (!empty($j['share_template']) && array_key_exists($j['share_template'], bk_share_templates())) {
                $d['share_template'] = $j['share_template'];
            }
            if (!empty($j['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $j['color'])) {
                // bytes: a #rrggbb colour is ASCII
                $d['color'] = strtolower($j['color']);
            }
            foreach (array('L', 'R', 'B') as $k) {
                $d['logos'][$k] = empty($j['logos'][$k]) ? 0 : 1;
            }
            foreach ($d['show'] as $k => $_v) {
                $d['show'][$k] = isset($j['show'][$k]) ? (empty($j['show'][$k]) ? 0 : 1) : 1;
            }
            if (!empty($j['blocks']) && is_array($j['blocks'])) {
                foreach (bk_mandate_sections() as $sk => $_l) {
                    $t = trim((string) ($j['blocks'][$sk] ?? ''));
                    if ($t !== '') $d['blocks'][$sk] = $t;
                }
            }
        }
    }
    return $d;
}

/** Builds the settings from a POST. Fields are validated and limited here. */
function bk_mandate_from_post($post)
{
    $tpl = (string) ($post['template'] ?? 'sobre');
    if (!array_key_exists($tpl, bk_mandate_templates())) $tpl = 'sobre';

    $stpl = (string) ($post['share_template'] ?? 'bandeau');
    if (!array_key_exists($stpl, bk_share_templates())) $stpl = 'bandeau';

    // bytes: a #rrggbb colour is ASCII
    $color = strtolower((string) ($post['color'] ?? '#0254a8'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#0254a8';

    $logos = array();
    foreach (array('L', 'R', 'B') as $k) $logos[$k] = empty($post['logo_' . $k]) ? 0 : 1;

    $show = array();
    foreach (bk_mandate_auto_sections() as $k => $_l) $show[$k] = empty($post['show_' . $k]) ? 0 : 1;

    $blocks = array();
    foreach (bk_mandate_sections() as $sk => $_l) {
        $t = trim((string) ($post['block_' . $sk] ?? ''));
        if ($t !== '') $blocks[$sk] = mb_substr($t, 0, 4000);
    }
    return array('template' => $tpl, 'share_template' => $stpl, 'color' => $color,
                 'logos' => $logos, 'show' => $show, 'blocks' => $blocks);
}

/** Saves the mandate's settings (JSON in BcMandate), leaving the rest of the row alone. */
function bk_mandate_save($tourId, $data)
{
    bk_schema();
    $tourId = intval($tourId);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $set  = "BcMandate = " . StrSafe_DB($json);
    safe_w_sql("INSERT INTO BookingCompetitions SET BcTournament = $tourId, $set
        ON DUPLICATE KEY UPDATE $set");
}

/**
 * Palette derived from a primary colour: light shade (backgrounds), dark shade (titles) and a
 * text colour readable ON the primary (from its luminance).
 */
function bk_mandate_palette($hex)
{
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $hex)) $hex = '#0254a8';
    // bytes: a #rrggbb colour is ASCII
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $mix = function ($c, $target, $ratio) { return (int) round($c + ($target - $c) * $ratio); };
    $light = sprintf('#%02x%02x%02x', $mix($r, 255, 0.90), $mix($g, 255, 0.90), $mix($b, 255, 0.90));
    $dark  = sprintf('#%02x%02x%02x', $mix($r, 0, 0.30), $mix($g, 0, 0.30), $mix($b, 0, 0.30));
    $lum   = 0.299 * $r + 0.587 * $g + 0.114 * $b;   // contrast of the text on the primary
    // bytes: a #rrggbb colour is ASCII
    return array('primary' => strtolower($hex), 'light' => $light, 'dark' => $dark,
                 'on' => ($lum > 150 ? '#20263d' : '#ffffff'));
}

/**
 * Products that can be pre-ordered at the points of sale of a competition (switched on), for
 * the mandate: [['label', 'price', 'description', 'option', 'variants' => [['label']]]].
 */
function bk_mandate_shop($tourId)
{
    if (!bk_shp()) return array();
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT SpId, SpName, SpPrice, SpDescription, SpOptionName FROM ShopProducts
        INNER JOIN ShopStands ON SdId = SpStand
        INNER JOIN ShopSettings ON SgTournament = SpTournament
        WHERE SpTournament = $tourId AND SpActive = 1 AND SpPreorder = 1 AND SdActive = 1 AND SgPreorders = 1
        ORDER BY SdOrder, SdId, SpOrder, SpId");
    while ($r = safe_fetch($rs)) {
        $out[intval($r->SpId)] = array('label' => (string) $r->SpName, 'price' => (float) $r->SpPrice,
            'description' => (string) $r->SpDescription, 'option' => (string) $r->SpOptionName, 'variants' => array());
    }
    if ($out) {
        $rs = safe_r_sql("SELECT SwProduct, SwLabel FROM ShopVariants WHERE SwProduct IN (" . implode(',', array_keys($out)) . ")
            ORDER BY SwOrder, SwId");
        while ($r = safe_fetch($rs)) $out[intval($r->SwProduct)]['variants'][] = array('label' => (string) $r->SwLabel);
    }
    return array_values($out);
}

/** Absolute address of the shop of a competition for the mandate ('' when it has none). $abs: scheme and host. */
function bk_mandate_shop_url($tourId, $abs)
{
    require_once dirname(__DIR__, 2) . '/shop/lib/link.php';
    $url = shp_public_links($tourId)['shop'];
    return $url !== '' ? $abs . $url : '';
}

/**
 * Automatic data of the mandate, from the competition. null when the competition does not
 * exist. Does NOT assume the registration is open (the organiser may prepare the mandate
 * before opening it).
 */
/** Absolute address of the public invitation (to share with everyone, no sign-in needed). */
function bk_mandate_public_url($tourId)
{
    // bytes: an ASCII server variable
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . bk_public_url('mandate.php?t=' . intval($tourId));
}

/** A link and its Copy button (organiser pages). The button's labels are read from its data. */
function bk_copy_link_html($url)
{
    return '<a href="' . bk_e($url) . '" target="_blank" rel="noopener">' . bk_e($url) . '</a> '
        . '<button type="button" class="sa-btn bk-copy" data-url="' . bk_e($url) . '" data-done="' . bk_e(bk_t('CopiedBtn')) . '"'
        . ' onclick="var b=this;navigator.clipboard.writeText(b.dataset.url).then(function(){var t=b.textContent;b.textContent=b.dataset.done;setTimeout(function(){b.textContent=t;},1500);});">'
        . bk_e(bk_t('CopyBtn')) . '</button>';
}

/**
 * Tariff of the invitation as a table: one row per fixed price (base, then each category rule
 * with the categories it covers), one column per departure when a departure changes the price
 * (a single column otherwise); then the adjustments the table cannot hold (origin, several
 * registrations), as short lines. ['cols' => [order => label], 'rows' => [['label', 'sub',
 * 'cells' => [order => amount]]], 'notes' => [['label', 'amount']]].
 */
function bk_mandate_fee_table($tourId, $base, $p, $sessions)
{
    $tourId = intval($tourId);
    $cols = array();
    if ($p['departures']) {
        foreach ($sessions as $s) {
            $ss = bk_session_start($s);
            $cols[intval($s->SesOrder)] = bk_t('DepCap', intval($s->SesOrder)) . ($ss !== '' ? ' — ' . bk_date_time($ss) : '');
        }
    }
    if (!$cols) $cols = array(0 => bk_t('MnFeeTariff'));

    $names = array('div' => array(), 'cls' => array());
    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions WHERE DivTournament = $tourId");
    while ($r = safe_fetch($rs)) $names['div'][(string) $r->DivId] = (string) $r->DivDescription;
    $rs = safe_r_sql("SELECT ClId, ClDescription FROM Classes WHERE ClTournament = $tourId");
    while ($r = safe_fetch($rs)) $names['cls'][(string) $r->ClId] = (string) $r->ClDescription;

    $rows = array();
    foreach (bk_price_cat_lines($base, $p) as $line) {
        $sub = array();
        foreach (array('div', 'cls') as $k) {
            if ($line[$k]) $sub[] = implode(', ', array_map(function ($c) use ($names, $k) { return $names[$k][$c] ?? $c; }, $line[$k]));
        }
        $cells = array();
        foreach (array_keys($cols) as $ord) $cells[$ord] = max(0.0, $line['amount'] + (float) ($p['departures'][(string) $ord] ?? 0));
        $rows[] = array('label' => $line['label'], 'sub' => implode(' — ', $sub), 'cells' => $cells);
    }

    $notes = array();
    if ($p['prov']['dept'] != 0.0 && $p['prov']['deptCode'] !== '') $notes[] = array(bk_t('MnFeeDept', $p['prov']['deptCode']), $p['prov']['dept']);
    if ($p['prov']['region'] != 0.0 && $p['prov']['regionCode'] !== '') $notes[] = array(bk_t('MnFeeRegion', bk_region_name($p['prov']['regionCode'])), $p['prov']['region']);
    $rank = $p['rank'];
    ksort($rank, SORT_NUMERIC);
    foreach ($rank as $th => $d) $notes[] = array(bk_t('MnFeeRank', intval($th)), (float) $d);
    return array('cols' => $cols, 'rows' => $rows, 'notes' => $notes);
}

/**
 * Events of the invitation as a table (user's design, 2026-11-15): one row per bow, the classes in
 * columns, women on the left and men on the right; an event over several classes is one merged
 * cell, which lists those classes from the youngest to the oldest ("U13F-U15F"). Everything comes
 * from ianseo as set up — divisions, classes, events: a class the organiser adds (U14H) appears by
 * itself, nothing is named or grouped here. The events holding both sexes (ClSex 0 and 1), or a
 * class without a sex (-1), go to a second part below, "Mixed", with its own class headings. A class
 * in two events of one bow splits the row of that bow in two lines.
 *
 * French outdoor target archery (ToLocRule FR, ToType 3) shoots the international and the national
 * rounds together: their events are shown apart, "TAE I" (classes ending in F or H) then "TAE N"
 * (classes ending in W or M), each with its women | men part then its mixed part; an event with
 * any other class (discovery…) goes to "Other". Rule given by the user, from the French set.
 *
 * $team: the team events (their classes: every group of team members, EventClass.EcTeamEvent > 0);
 * their cells show the event's name, which says its format. $okCats / $okEvents: what can really be
 * shot ('DIV|CLS' => true, 'ind|CODE' / 'team|CODE' => true), or null for everything. $places
 * (individual events): each block also tells the places still open to it (bk_mandate_block_places).
 * Returns null when there is no such event, else ['groups' => [['title' => label or '',
 * 'fh' => part|null, 'mx' => part|null]…], 'duels' => bool, 'at' => local time of the places or ''];
 * a part: ['cols' => [ClId…], 'women' => count of the women columns (0 in the mixed part),
 * 'rows' => [['div' => label, 'lanes' => [[block…]…]]…]], a block: ['s', 'e' (columns, inclusive),
 * 'label', 'name', 'duels', 'places' (int or null)].
 */
function bk_mandate_event_grid($tourId, $team = false, $okCats = null, $okEvents = null, $places = false)
{
    $tourId = intval($tourId);
    $tr = safe_fetch(safe_r_sql("SELECT ToLocRule, ToType FROM Tournament WHERE ToId = $tourId"));
    $tae = $tr && strtoupper(trim((string) $tr->ToLocRule)) === 'FR' && intval($tr->ToType) === 3;
    $divs = array();
    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions WHERE DivTournament = $tourId AND DivAthlete = 1 ORDER BY DivViewOrder, DivId");
    while ($r = safe_fetch($rs)) $divs[(string) $r->DivId] = (string) $r->DivDescription;
    $cls = array(); $rank = 0;
    $rs = safe_r_sql("SELECT ClId, ClSex, ClAgeFrom FROM Classes WHERE ClTournament = $tourId AND ClAthlete = 1 ORDER BY ClAgeFrom, ClViewOrder, ClId");
    while ($r = safe_fetch($rs)) {
        $id = (string) $r->ClId;
        $sex = intval($r->ClSex);
        $grp = '';
        if ($tae) $grp = preg_match('/[FH]$/', $id) ? 'I' : (preg_match('/[WM]$/', $id) ? 'N' : 'O');
        $cls[$id] = array('sex' => in_array($sex, array(0, 1), true) ? $sex : -1, 'rank' => $rank++, 'grp' => $grp);
    }

    $kind = $team ? 'team' : 'ind';
    $events = array();
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvFinalFirstPhase FROM Events
        WHERE EvTournament = $tourId AND EvTeamEvent = " . ($team ? 1 : 0) . " AND EvCodeParent = '' ORDER BY EvProgr, EvCode");
    while ($r = safe_fetch($rs)) {
        if ($okEvents !== null && empty($okEvents[$kind . '|' . $r->EvCode])) continue;
        $events[(string) $r->EvCode] = array('name' => (string) $r->EvEventName, 'duels' => intval($r->EvFinalFirstPhase) > 0, 'cells' => array());
    }
    if (!$events) return null;
    $rs = safe_r_sql("SELECT DISTINCT EcCode, EcDivision, EcClass FROM EventClass
        WHERE EcTournament = $tourId AND EcTeamEvent " . ($team ? '> 0' : '= 0'));
    while ($r = safe_fetch($rs)) {
        $c = (string) $r->EcCode; $d = (string) $r->EcDivision; $k = (string) $r->EcClass;
        if (!isset($events[$c], $divs[$d], $cls[$k])) continue;
        if ($okCats !== null && empty($okCats[$d . '|' . $k])) continue;
        $events[$c]['cells'][$d . '|' . $k] = array($d, $k);
    }
    // The group (TAE I / TAE N / other, or a single one) and the part of each event.
    foreach ($events as $code => &$ev) {
        if (!$ev['cells']) { unset($events[$code]); continue; }
        $sexes = array(); $grps = array();
        foreach ($ev['cells'] as $cell) { $sexes[$cls[$cell[1]]['sex']] = true; $grps[$cls[$cell[1]]['grp']] = true; }
        $ev['part'] = (isset($sexes[-1]) || count($sexes) > 1) ? 'mx' : 'fh';
        $ev['grp'] = count($grps) === 1 ? (string) key($grps) : 'O';
    }
    unset($ev);
    if (!$events) return null;

    $calc = $places && !$team ? bk_mandate_places_ctx($tourId) : null;
    $out = array('groups' => array(), 'duels' => false, 'at' => $calc ? $calc['at'] : '');
    $titles = $tae ? array('I' => bk_t('MnTaeI'), 'N' => bk_t('MnTaeN'), 'O' => bk_t('MnEvOther')) : array('' => '');
    foreach ($titles as $grp => $title) {
        $g = array('title' => $title, 'fh' => null, 'mx' => null);
        foreach (array('fh', 'mx') as $part) {
            $mine = array_filter($events, function ($ev) use ($grp, $part) { return $ev['grp'] === (string) $grp && $ev['part'] === $part; });
            if ($mine) $g[$part] = bk_mandate_grid_part($mine, $part, $divs, $cls, $team, $calc, $out['duels']);
        }
        if ($g['fh'] || $g['mx']) $out['groups'][] = $g;
    }
    return $out['groups'] ? $out : null;
}

/** One part (women | men, or mixed) of bk_mandate_event_grid, for the events $events. */
function bk_mandate_grid_part($events, $part, $divs, $cls, $team, &$calc, &$duels)
{
    $used = array();
    foreach ($events as $ev) foreach ($ev['cells'] as $cell) $used[$cell[1]] = true;
    $ids = array_keys($used);
    usort($ids, function ($a, $b) use ($cls) { return $cls[$a]['rank'] <=> $cls[$b]['rank']; });
    $women = 0;
    if ($part === 'fh') {
        $w = array_values(array_filter($ids, function ($k) use ($cls) { return $cls[$k]['sex'] === 1; }));
        $m = array_values(array_filter($ids, function ($k) use ($cls) { return $cls[$k]['sex'] === 0; }));
        $ids = array_merge($w, $m);
        $women = count($w);
    }
    $colOf = array_flip($ids);
    $rows = array();
    foreach ($divs as $d => $divDesc) {
        $blocks = array();
        foreach ($events as $ev) {
            $idx = array();
            foreach ($ev['cells'] as $cell) if ($cell[0] === $d) $idx[] = $colOf[$cell[1]];
            if (!$idx) continue;
            sort($idx);
            // Contiguous runs, never across the women | men boundary.
            $s = $prev = $idx[0];
            foreach (array_merge(array_slice($idx, 1), array(null)) as $x) {
                if ($x !== null && $x === $prev + 1 && !($women && $x === $women)) { $prev = $x; continue; }
                $run = array_slice($ids, $s, $prev - $s + 1);
                $blocks[] = array('s' => $s, 'e' => $prev, 'name' => $ev['name'], 'duels' => $ev['duels'],
                    // A team event by its name (it says the format); an individual one by its classes.
                    'label' => $team ? $ev['name'] : implode('-', $run),
                    'places' => $calc ? bk_mandate_block_places($calc, $d, $run) : null);
                if ($ev['duels']) $duels = true;
                if ($x !== null) $s = $prev = $x;
            }
        }
        if (!$blocks) continue;
        usort($blocks, function ($a, $b) { return ($a['s'] <=> $b['s']) ?: ($b['e'] <=> $a['e']); });
        $lanes = array(); $ends = array();
        foreach ($blocks as $b) {
            $l = 0;
            while (isset($ends[$l]) && $ends[$l] >= $b['s']) $l++;
            $ends[$l] = $b['e'];
            $lanes[$l][] = $b;
        }
        $rows[] = array('div' => $divDesc, 'lanes' => $lanes);
    }
    return $rows ? array('cols' => $ids, 'women' => $women, 'rows' => $rows) : null;
}

/**
 * What the places of the event blocks need, read once: each departure (its places left, its
 * placement context) and the faces of the competition. See bk_mandate_block_places.
 */
function bk_mandate_places_ctx($tourId)
{
    require_once __DIR__ . '/places.php';
    $tourId = intval($tourId);
    $at = safe_fetch(safe_r_sql("SELECT " . bk_local_now_sql() . " AS n FROM Tournament WHERE ToId = $tourId"));
    $c = array('tour' => $tourId, 'at' => $at ? (string) $at->n : '', 'faces' => bk_caps_faces($tourId), 'choices' => array(), 'ses' => array());
    foreach (bk_comp_sessions($tourId) as $s) {
        $o = intval($s->SesOrder);
        $c['ses'][$o] = array('left' => max(0, intval($s->Places) - intval($s->Pris)), 'ctx' => bk_profile_ctx($tourId, $o));
    }
    return $c;
}

/**
 * Places still open to one block of an event (bow $div, classes $classes), all departures together:
 * on each departure where one of these classes is admitted, the places of their profiles (class ×
 * face it may choose) taken together (bk_profile_left_multi, the registration gauge), never more
 * than the places left on the departure.
 */
function bk_mandate_block_places(&$c, $div, $classes)
{
    $total = 0;
    foreach ($c['ses'] as $o => &$s) {
        $profiles = array();
        foreach ($classes as $cl) {
            if (!bk_cat_allowed($c['tour'], $div, $cl, $o)) continue;
            $k = $div . '|' . $cl;
            if (!isset($c['choices'][$k])) $c['choices'][$k] = array_keys(bk_caps_face_choices($c['tour'], $div, $cl, $c['faces']));
            foreach ($c['choices'][$k] ?: array(0) as $f) $profiles[] = array($cl, $f);
        }
        if (!$profiles) continue;
        if ($s['ctx'] === null) { $total += $s['left']; continue; }
        $total += min($s['left'], bk_profile_left_multi($s['ctx'], $div, $profiles));
    }
    unset($s);
    return $total;
}

/**
 * The table of bk_mandate_event_grid as HTML, for the screen and for the PDF (TCPDF's writeHTML):
 * group by group (TAE I, TAE N, other — or a single one), the women | men part then the mixed part,
 * each with its own headings, as tables of the same width that read as one. The group's name sits in
 * the first column, over the two heading rows. $st: colours ['head', 'cell', 'line', 'duel', 'on']
 * and 'pdf' => bool.
 */
function bk_mandate_event_grid_html($g, $st)
{
    $pdf = !empty($st['pdf']);
    $first = 16;
    $out = '';
    foreach ($g['groups'] as $grp) foreach (array('fh', 'mx') as $part) {
        $p = $grp[$part];
        if (!$p) continue;
        $title = $grp['title'] !== '' ? '<b>' . bk_e($grp['title']) . '</b>' : '';
        $n = count($p['cols']);
        $w = round((100 - $first) / max(1, $n), 3);
        $td = function ($attrs, $html, $bg = '', $color = '') use ($pdf, $st) {
            $style = 'border:0.3px solid ' . $st['line'] . ';' . ($color !== '' ? 'color:' . $color . ';' : '');
            return '<td' . $attrs . ($bg !== '' ? ($pdf ? ' bgcolor="' . $bg . '"' : '') : '')
                . ' style="' . $style . ($bg !== '' && !$pdf ? 'background:' . $bg . ';' : '') . '">' . $html . '</td>';
        };
        $th = function ($attrs, $html) use ($st, $pdf) {
            return '<th' . $attrs . ($pdf ? ' bgcolor="' . $st['head'] . '"' : '') . ' style="border:0.3px solid ' . $st['line']
                . ';text-align:center;' . ($pdf ? '' : 'background:' . $st['head'] . ';') . '">' . $html . '</th>';
        };
        $out .= '<table class="mn-evg" cellpadding="2" style="width:100%;border-collapse:collapse;table-layout:fixed">';
        // The browser sizes a fixed table on its first row, a single merged cell in the mixed part.
        if (!$pdf) $out .= '<colgroup><col style="width:' . $first . '%">' . str_repeat('<col style="width:' . $w . '%">', $n) . '</colgroup>';
        $out .= '<tr>' . $th(' rowspan="2" width="' . $first . '%"', $title);
        if ($part === 'fh') {
            if ($p['women']) $out .= $th(' colspan="' . $p['women'] . '" width="' . round($w * $p['women'], 3) . '%"', '<b>' . bk_e(bk_t('MnWomen')) . '</b>');
            if ($n - $p['women']) $out .= $th(' colspan="' . ($n - $p['women']) . '" width="' . round($w * ($n - $p['women']), 3) . '%"', '<b>' . bk_e(bk_t('MnMen')) . '</b>');
        } else {
            $out .= $th(' colspan="' . $n . '" width="' . round($w * $n, 3) . '%"', '<b>' . bk_e(bk_t('MnMixed')) . '</b>');
        }
        $out .= '</tr><tr>';
        foreach ($p['cols'] as $c) $out .= $th(' width="' . $w . '%"', bk_e($c));
        $out .= '</tr>';
        foreach ($p['rows'] as $row) {
            foreach ($row['lanes'] as $li => $lane) {
                $out .= '<tr nobr="true">' . $td(' width="' . $first . '%"', $li === 0 ? '<b>' . bk_e($row['div']) . '</b>' : '');
                $c = 0;
                foreach ($lane as $b) {
                    for (; $c < $b['s']; $c++) $out .= $td(' width="' . $w . '%"', '');
                    $span = $b['e'] - $b['s'] + 1;
                    // A long label in a single narrow column: a smaller font rather than a word cut anywhere.
                    $lab = bk_e($b['label']);
                    if ($w * $span < 9 && mb_strlen($b['label']) > 5) $lab = '<span class="mn-evg-s" style="font-size:' . ($pdf ? '6pt' : '9px;white-space:nowrap;letter-spacing:-.2px') . '">' . $lab . '</span>';
                    // Places still open to the event (online invitation, registration open).
                    if (isset($b['places']) && $b['places'] !== null) {
                        $pl = intval($b['places']);
                        // A narrow cell: the number alone (the note under the table says what it is).
                        $txt = $w * $span < 9 ? (string) $pl : ($pl > 0 ? bk_t($pl > 1 ? 'PlacesMany' : 'PlacesOne', $pl) : bk_t('StFull'));
                        $lab .= '<br><span class="mn-evg-p' . ($pl > 0 ? '' : ' mn-evg-full') . '">' . bk_e($txt) . '</span>';
                    }
                    $out .= $td(' colspan="' . $span . '" width="' . round($w * $span, 3) . '%" align="center"'
                        . ($pdf ? '' : ' title="' . bk_e($b['name']) . '"' . ($b['duels'] ? ' class="mn-evg-d"' : '')),
                        $lab, $b['duels'] ? $st['duel'] : $st['cell'], $b['duels'] ? $st['on'] : '');
                    $c = $b['e'] + 1;
                }
                for (; $c < $n; $c++) $out .= $td(' width="' . $w . '%"', '');
                $out .= '</tr>';
            }
        }
        $out .= '</table>';
    }
    if ($g['duels']) {
        $out .= '<p class="mn-evg-legend" style="margin:4px 0 0;font-size:' . ($pdf ? '7.5pt' : '12px') . ';color:#4c4e50">'
            . '<span style="' . ($pdf ? 'background-color:' : 'background:') . $st['duel'] . ';color:' . $st['on'] . ';">&nbsp;' . bk_e(bk_t('MnDuels')) . '&nbsp;</span> '
            . bk_e(bk_t('MnEvDuelsLegend')) . '</p>';
    }
    return $out;
}

function bk_mandate_data($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToId, ToCode, ToName, ToWhere, ToComDescr, ToCommitee,
                ToWhenFrom, ToWhenTo, ToType, ToTypeName, ToTypeSubRule,
                LENGTH(ToImgL) AS HasL, LENGTH(ToImgR) AS HasR, LENGTH(ToImgB) AS HasB
        FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return null;

    $cfg  = bk_comp_config($tourId);
    $disc = bk_comp_discipline($t->ToType, $t->ToTypeSubRule, $t->ToTypeName);
    $labels = bk_disc_labels();

    // What can really be shot (categories open to online registration, field constraints): the
    // invitation lists only that. Nothing worked out (no departure set up) → everything, as before.
    $poss = bk_field_possible($tourId);
    $filter = (bool) $poss['cats'];

    $divisions = array(); $classes = array();
    $okDiv = $okCls = array();
    foreach (array_keys($poss['cats']) as $dc) { list($d, $c) = explode('|', $dc, 2); $okDiv[$d] = true; $okCls[$c] = true; }
    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions
        WHERE DivTournament = $tourId AND DivAthlete = 1 ORDER BY DivViewOrder, DivId");
    while ($r = safe_fetch($rs)) if (!$filter || isset($okDiv[$r->DivId])) $divisions[] = $r->DivDescription;
    $rs = safe_r_sql("SELECT ClId, ClDescription FROM Classes
        WHERE ClTournament = $tourId AND ClAthlete = 1 ORDER BY ClAgeFrom, ClId");
    while ($r = safe_fetch($rs)) if (!$filter || isset($okCls[$r->ClId])) $classes[] = $r->ClDescription;

    // Form of the competition (rules B.1.1): type and sub-rule in the core's own words, events
    // with their duels, faces of the competition.
    $events = array(); $duels = false;
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvTeamEvent, EvFinalFirstPhase FROM Events
        WHERE EvTournament = $tourId AND EvCodeParent = '' ORDER BY EvTeamEvent, EvProgr");
    while ($r = safe_fetch($rs)) {
        if ($filter && empty($poss['events'][(intval($r->EvTeamEvent) > 0 ? 'team' : 'ind') . '|' . $r->EvCode])) continue;
        $d = intval($r->EvFinalFirstPhase) > 0;
        $duels = $duels || $d;
        $events[] = array('name' => (string) $r->EvEventName, 'team' => intval($r->EvTeamEvent) > 0, 'duels' => $d);
    }
    $faces = array();
    $rs = safe_r_sql("SELECT TfId, TfName FROM TargetFaces WHERE TfTournament = $tourId ORDER BY TfW1 DESC, TfName");
    while ($r = safe_fetch($rs)) {
        $n = trim((string) $r->TfName);
        if ($n === '' || in_array($n, $faces, true)) continue;
        if ($filter && $poss['faces'] && empty($poss['faces'][intval($r->TfId)])) continue;
        $faces[] = $n;
    }
    $format = array_filter(array(
        $t->ToTypeName ? get_text($t->ToTypeName, 'Tournament') : '',
        $t->ToTypeSubRule ? get_text($t->ToTypeSubRule, 'Install') : '',
    ));

    $pricing = bk_pricing_get($cfg);
    $sessions = bk_comp_sessions($tourId);

    // Subject of the e-mails written from the invitation: code, town, first day.
    $ff = bk_ffta_row($tourId);
    $town = trim((string) ($ff->FeVenueCity ?? ''));
    if ($town === '') $town = trim((string) ($ff->ToVenue ?? ''));
    if ($town === '') $town = trim((string) $t->ToWhere);
    $mailSubject = implode(' - ', array_filter(array(trim((string) $t->ToCode), $town, bk_date_fr($t->ToWhenFrom)), 'strlen'));

    return array(
        'tour'        => $t,
        'cfg'         => $cfg,
        'disc'        => $disc,
        'discLabel'   => $labels[$disc['key']] ?? $disc['key'],
        // bytes: ToCommitee is an ASCII approval number
        'region'      => bk_region_name(substr((string) $t->ToCommitee, 0, 2)),
        'sessions'    => $sessions,
        'divisions'   => $divisions,
        'classes'     => $classes,
        'format'      => implode(' — ', $format),
        'duels'       => $duels,
        'events'      => $events,
        'eventGrid'   => bk_mandate_event_grid($tourId, false, $filter ? $poss['cats'] : null, $filter ? $poss['events'] : null),
        'teamGrid'    => bk_mandate_event_grid($tourId, true, $filter ? $poss['cats'] : null, $filter ? $poss['events'] : null),
        'poss'        => $filter ? array($poss['cats'], $poss['events']) : array(null, null),
        'faces'       => $faces,
        'program'     => bk_mandate_program($tourId),
        'itinerary'   => bk_itinerary_links($tourId),
        'staff'       => bk_mandate_staff($tourId),
        'pay'         => bk_payinfo_get($cfg),
        'fee'         => (float) $cfg->BcFee,
        'feeAdvanced' => bk_pricing_is_advanced($pricing),
        'pricing'     => $pricing,
        'feeTable'    => bk_mandate_fee_table($tourId, (float) $cfg->BcFee, $pricing, $sessions),
        'deadline'    => $cfg->BcOpenTo ?? null,
        'shop'        => bk_mandate_shop($tourId),
        'mailSubject' => $mailSubject,
    );
}

/**
 * Field staff of the competition (Competition › Staff on field): [['role', 'names' => [...]]], in
 * the order and with the role names of the core's own printout (Tournament/PrnStaffField.php):
 * judges, director of shooting, jury, then the organisation.
 */
function bk_mandate_staff($tourId)
{
    $rs = safe_r_sql("SELECT ItDescription, TiName, TiGivenName
        FROM TournamentInvolved
        INNER JOIN InvolvedType ON ItId = TiType
        WHERE TiTournament = " . intval($tourId) . "
        ORDER BY ItJudge = 0, ItJudge, ItDoS = 0, ItDoS, ItJury = 0, ItJury, ItOC = 0, ItOC, TiName, TiGivenName");
    $out = array();
    while ($r = safe_fetch($rs)) {
        $role = get_text($r->ItDescription, 'Tournament');
        $name = trim(mb_strtoupper((string) $r->TiName, 'UTF-8') . ' ' . $r->TiGivenName);
        if ($name === '') continue;
        $n = count($out);
        if ($n && $out[$n - 1]['role'] === $role) $out[$n - 1]['names'][] = $name;
        else $out[] = array('role' => $role, 'names' => array($name));
    }
    return $out;
}

/**
 * Full programme of the competition, as the core lays it out (Scheduler::getScheduleHTML(): the
 * text lines such as the opening of the registration desk or the equipment inspection, the
 * warm-up, the start of each departure with its comments, the finals). '' when nothing is
 * scheduled yet.
 *
 * Kept from it: the table, its rows and cells, their classes and colspans. Its links lead to
 * organiser screens, and its texts are typed by the organiser — tags and attributes are not
 * trusted in a page read by the archers.
 */
function bk_mandate_program($tourId)
{
    global $CFG;
    require_once $CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Scheduler.php';
    ob_start();
    $sch = new Scheduler(intval($tourId));
    $html = (string) @$sch->getScheduleHTML();
    ob_end_clean();
    if (strpos($html, '<td') === false) return '';

    $html = strip_tags($html, '<table><tr><th><td><b><i><br><span>');
    // Rows whose cells are all empty (the core leaves some after a text line): a blank gap.
    $html = preg_replace('~<tr[^>]*>(?:\s*<t[dh][^>]*>\s*</t[dh]>)+\s*</tr>~i', '', $html);
    return preg_replace_callback('~<(/?)(table|tr|th|td|b|i|br|span)\b([^>]*)>~i', function ($m) {
        // bytes: tag and attribute names are ASCII (matched by the patterns above)
        $tag = strtolower($m[2]);
        if ($m[1] === '/') return '</' . $tag . '>';
        $keep = '';
        if (preg_match_all('~\b(class|colspan)\s*=\s*"([^"]*)"~i', $m[3], $a, PREG_SET_ORDER)) {
            foreach ($a as $x) {
                // bytes: an ASCII attribute name
                $keep .= ' ' . strtolower($x[1]) . '="' . htmlspecialchars(html_entity_decode($x[2], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        return '<' . $tag . $keep . '>';
    }, $html);
}

/**
 * May the archers read the mandate? Three states on BcShowMandate:
 *  - NULL (never chosen) → visible as soon as a mandate exists (the requested default);
 *  - 1                   → visible (when a mandate exists);
 *  - 0                   → hidden.
 * Never visible without a mandate (nothing to show).
 */
function bk_mandate_visible($cfg)
{
    // Level 2 (simple publication): the mandate is filled from the data and always published,
    // even without explicit settings (BcMandate may be empty).
    $lvl = intval(is_object($cfg) ? ($cfg->BcPublishLevel ?? 0) : (is_array($cfg) ? ($cfg['BcPublishLevel'] ?? 0) : 0));
    if ($lvl == 2) return true;

    $raw  = is_object($cfg) ? ($cfg->BcMandate ?? null) : (is_array($cfg) ? ($cfg['BcMandate'] ?? null) : null);
    $has  = trim((string) $raw) !== '';
    $flag = is_object($cfg) ? ($cfg->BcShowMandate ?? null) : (is_array($cfg) ? ($cfg['BcShowMandate'] ?? null) : null);
    if ($flag === null || $flag === '') return $has;
    return $has && intval($flag) === 1;
}

/**
 * Official ianseo documents that can be relayed (the organiser opts in). Each entry:
 * key => ['label', 'icon', 'flag' (BookingCompetitions column), 'script' (core generator), 'params'
 * (controlled GET parameters)]. The scripts are PDF entry points of the core (Prn*.php) that
 * work on the current competition session.
 */
function bk_doc_defs()
{
    return array(
        'program' => array(
            'label' => bk_t('DocProgram'), 'icon' => '📋',
            'flag' => 'BcShowProgram', 'has' => 'bk_has_program',
            'script' => 'Scheduler/PrnScheduler.php',
            'params' => array('Finalists' => '1', 'PageBreaks' => ''),
        ),
        'participants' => array(
            'label' => bk_t('DocByClub'), 'icon' => '👥',
            'flag' => 'BcShowParticipants', 'has' => 'bk_has_participants',
            'script' => 'Partecipants/PrnCountry.php', 'params' => array(),
        ),
        'participants_target' => array(
            'label' => bk_t('DocByTarget'), 'icon' => '🎯',
            'flag' => 'BcShowParticipants', 'has' => 'bk_has_placements',
            'script' => 'Partecipants/PrnSession.php', 'params' => array(),
        ),
        // "Results" (BcShowResults box) gives several buttons depending on how far the
        // competition went; each one only shows with its content.
        'qualifications' => array(
            'label' => bk_t('DocResQual'), 'icon' => '🏅',
            'flag' => 'BcShowResults', 'has' => 'bk_has_results',
            'script' => 'Qualification/PrnCompleteAbs.php', 'params' => array(),
        ),
        // 'events' => 'ind'/'team': the relay passes the explicit LIST of events (bk_final_events)
        // instead of "." (all) — otherwise the generator also prints the categories WITHOUT
        // matches. Same as the select id="IndividualEvents" / "TeamEvents" of Final/PrintOut.php
        // (EvFinalFirstPhase != 0), without the "all" option.
        'duels_ind' => array(
            'label' => bk_t('DocResInd'), 'icon' => '🏹',
            'flag' => 'BcShowResults', 'has' => 'bk_has_ind_finals',
            'script' => 'Final/Individual/PrnIndividual.php', 'events' => 'ind',
            'params' => array('IncRankings' => '1', 'IncBrackets' => '1',
                              'ShowTargetNo' => '1', 'ShowSchedule' => '1', 'OrisABD' => 'AB'),
        ),
        'duels_team' => array(
            'label' => bk_t('DocResTeam'), 'icon' => '🏆',
            'flag' => 'BcShowResults', 'has' => 'bk_has_team_finals',
            'script' => 'Final/Team/PrnTeam.php', 'events' => 'team',
            'params' => array('IncRankings' => '1', 'IncBrackets' => '1',
                              'ShowTargetNo' => '1', 'ShowSchedule' => '1', 'OrisABD' => 'AB'),
        ),
    );
}

/** At least one participant on this competition? */
function bk_has_participants($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Entries
        WHERE EnTournament = " . intval($tourId) . " AND EnAthlete = 1 LIMIT 1"));
    return (bool) $r;
}

/** At least one qualification result (score entered)? */
function bk_has_results($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1
        FROM Qualifications INNER JOIN Entries ON EnId = QuId
        WHERE EnTournament = " . intval($tourId) . " AND QuScore > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Is there a programme to show? Same logic as the core's scheduler
 * (Common/Lib/Fun_Scheduler.php): either an item TYPED in the scheduler (Scheduler table), or a
 * TIMED departure (DistanceInformation with a date and a start or warm-up time).
 */
function bk_has_program($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Scheduler WHERE SchTournament = $tourId LIMIT 1"));
    if ($r) return true;
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM DistanceInformation
        WHERE DiTournament = $tourId AND DiDay > 0 AND (DiStart > 0 OR DiWarmStart > 0) LIMIT 1"));
    return (bool) $r;
}

/** At least one archer on a target? (for the participants BY TARGET list) */
function bk_has_placements($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1
        FROM Qualifications INNER JOIN Entries ON EnId = QuId
        WHERE EnTournament = " . intval($tourId) . " AND QuTarget > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Were INDIVIDUAL matches REALLY generated? Finals holds the bracket STRUCTURE (rows with
 * FinAthlete=0) as soon as the events exist; a real match only exists once an archer is in it →
 * FinAthlete>0 required (real bug: competition 722 had 102 empty Finals rows, the button showed).
 */
function bk_has_ind_finals($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM Finals
        WHERE FinTournament = " . intval($tourId) . " AND FinAthlete > 0 LIMIT 1"));
    return (bool) $r;
}

/** TEAM matches really generated? (TeamFinals with a team in it, TfTeam>0) */
function bk_has_team_finals($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT 1 FROM TeamFinals
        WHERE TfTournament = " . intval($tourId) . " AND TfTeam > 0 LIMIT 1"));
    return (bool) $r;
}

/**
 * Events (EvCode) offered by the core's print selector of the matches (Final/PrintOut.php:
 * select id="IndividualEvents"/"TeamEvents") — those with a final bracket
 * (EvFinalFirstPhase != 0), without the "all" option ("."). Passing them explicitly keeps
 * the generator from printing the categories without matches.
 */
function bk_final_events($tourId, $team)
{
    $tourId = intval($tourId);
    $t = $team ? '1' : '0';
    $out = array();
    $rs = safe_r_sql("SELECT EvCode FROM Events
        WHERE EvTeamEvent = '$t' AND EvTournament = $tourId
          AND EvFinalFirstPhase <> 0 AND EvCodeParent = '' ORDER BY EvProgr");
    while ($r = safe_fetch($rs)) $out[] = $r->EvCode;
    return $out;
}

/**
 * Documents of the competition the archers may read. Returns a list of
 * ['key', 'label', 'icon', 'url', 'external'?]: mandate (when visible) + ianseo.net link (when
 * set) + the official ianseo documents whose box the organiser ticked.
 */
function bk_docs_list($cfg, $tourId)
{
    $tourId = intval($tourId);
    $out = array();
    if (bk_mandate_visible($cfg)) {
        $out[] = array('key' => 'mandat', 'icon' => '📄', 'label' => bk_t('DocMandate'),
            'url' => bk_public_url('mandate.php?t=' . $tourId));
    }
    foreach (bk_doc_defs() as $key => $d) {
        $flag = $d['flag'];
        $on = is_object($cfg) ? ($cfg->$flag ?? 0) : (is_array($cfg) ? ($cfg[$flag] ?? 0) : 0);
        if (intval($on) !== 1) continue;
        // A document only makes sense with content (bk_has_* of the def): no programme
        // without times, no participants without entries, no results without scores, no
        // matches without a generated bracket.
        if (!empty($d['has']) && function_exists($d['has']) && !call_user_func($d['has'], $tourId)) continue;
        $out[] = array('key' => $key, 'icon' => $d['icon'], 'label' => $d['label'],
            'url' => bk_public_url('document.php?t=' . $tourId . '&doc=' . $key));
    }
    $url = trim((string) (is_object($cfg) ? ($cfg->BcIanseoUrl ?? '') : ($cfg['BcIanseoUrl'] ?? '')));
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        $out[] = array('key' => 'ianseo', 'icon' => '🔗', 'label' => bk_t('DocIanseo'),
            'url' => $url, 'external' => true);
    }
    return $out;
}

/**
 * LIMITED relay to an official ianseo PDF generator. The sensitive point: the core's generators
 * need a competition session and an organiser ACL. So, FOR THIS SINGLE REQUEST and THIS SINGLE
 * competition, it sets:
 *  - the competition session (CreateTourSession, like bk_with_tournament);
 *  - a read right LIMITED to this code (AUTH_COMP = [code]), NEVER AUTH_ROOT (see authCheckACL:
 *    the grant comes from aut_code_allowed(code), not from an admin role) — so the elevated
 *    context cannot touch any other competition.
 * The archer's WHOLE session is saved then restored (finally + register_shutdown_function as a
 * net: an Output()/exit of the core script must never leave the archer elevated). The caller
 * ALREADY checked the archer, the tourId and the permission box.
 */
function bk_doc_relay($tourId, $code, $spec)
{
    global $CFG;
    $tourId = intval($tourId);

    $saved = $_SESSION;
    $done  = false;
    register_shutdown_function(function () use ($saved, &$done) {
        if (!$done) $_SESSION = $saved;
    });

    try {
        CreateTourSession($tourId);                 // empties the session, sets TourId/TourCode + settings
        $_SESSION['AUTH_User']   = '__doc_relay__'; // dummy non-empty identity (grant depends on the code)
        $_SESSION['AUTH_ENABLE'] = 1;
        unset($_SESSION['AUTH_ROOT']);              // NEVER admin
        $_SESSION['AUTH_COMP']   = array((string) $code);   // limits the right to THIS competition

        foreach ((array) ($spec['params'] ?? array()) as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; }

        // Matches: explicit list of the events with a bracket (never "all", which would also
        // print the categories without matches). See bk_final_events / bk_doc_defs.
        if (!empty($spec['events'])) {
            $evs = bk_final_events($tourId, $spec['events'] === 'team');
            if ($evs) { $_GET['Event'] = $evs; $_REQUEST['Event'] = $evs; }
        }

        include $CFG->DOCUMENT_PATH . $spec['script'];   // generates and streams the official PDF
    } finally {
        $_SESSION = $saved;
        $done = true;
    }
    exit;
}

/* ------------------------------------------------------------------ */
/* Bib (Qualification badge) — printed PER ARCHER                     */
/* ------------------------------------------------------------------ */

/**
 * Number of the bib template to use: the FIRST "Qualification" badge (lowest IcNumber) of the
 * competition — "the first of the list" (0 on competition 185). null when there is no Q
 * template (nothing to print).
 */
function bk_dossard_card($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT MIN(IcNumber) AS n FROM IdCards
        WHERE IcTournament = " . intval($tourId) . " AND IcType = 'Q'"));
    return ($r && $r->n !== null) ? intval($r->n) : null;
}

/** Is the bib offered to the archers? (opt-in; always on at level 2, like the mandate) */
function bk_dossard_visible($cfg)
{
    $lvl = intval(is_object($cfg) ? ($cfg->BcPublishLevel ?? 0) : (is_array($cfg) ? ($cfg['BcPublishLevel'] ?? 0) : 0));
    if ($lvl == 2) return true;
    $flag = is_object($cfg) ? ($cfg->BcShowDossard ?? 0) : (is_array($cfg) ? ($cfg['BcShowDossard'] ?? 0) : 0);
    return intval($flag) === 1;
}

/** Is there a printable bib on this competition? (option on AND a Q template) */
function bk_dossard_available($cfg, $tourId)
{
    return bk_dossard_visible($cfg) && bk_dossard_card($tourId) !== null;
}

/**
 * Joins + condition of the archer's OWN participations entered by the organiser in ianseo itself
 * (participants list, import), read live from Entries: same rule as the 'IMPORT' rows of
 * bk_my_registrations, so a participation listed in "My registrations" also has its documents.
 * Requires Entries in the FROM clause.
 */
function bk_dossard_organiser_sql($lic)
{
    return "INNER JOIN BookingCompetitions ON BcTournament = EnTournament
        LEFT  JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnCode = $lic AND EnAthlete = 1 AND BrEnId IS NULL
          AND (BcPublishLevel >= 2 OR BcPayments = 1)";
}

/**
 * Registrations whose bib (and official score sheet) the archer may print on THIS competition:
 * their own (BrLicence = their licence), those they made (BrArcher = them, a club mate's
 * registration), and their own participations entered by the organiser (bk_dossard_organiser_sql).
 * One row per registration (departure).
 */
function bk_dossard_entries($tourId, $archer)
{
    $tourId = intval($tourId);
    $lic  = StrSafe_DB($archer->BaLicence);
    $baid = intval($archer->BaId);
    $cols = "EnFirstName, EnName, QuSession, QuTarget, QuLetter, DivDescription, ClDescription";
    $joins = "INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Divisions      ON DivTournament = $tourId AND DivId = EnDivision
        LEFT  JOIN Classes        ON ClTournament = $tourId AND ClId = EnClass";
    $rs = safe_r_sql("(SELECT BrEnId, BrLicence, (BrLicence = $lic) AS BkOwn, $cols
        FROM BookingRegistrations
        INNER JOIN Entries        ON EnId = BrEnId
        $joins
        WHERE BrTournament = $tourId AND (BrLicence = $lic OR BrArcher = $baid))
      UNION ALL
        (SELECT EnId, EnCode, 1, $cols
        FROM Entries
        $joins
        " . bk_dossard_organiser_sql($lic) . " AND EnTournament = $tourId)
        ORDER BY BkOwn DESC, EnFirstName, EnName, QuSession");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * May the archer print the bib of this registration (EnId)? Returns the row (BrTournament) if so,
 * null otherwise. Defence against a forged ?enid.
 */
function bk_dossard_can($enId, $archer)
{
    $enId = intval($enId);
    $lic  = StrSafe_DB($archer->BaLicence);
    $baid = intval($archer->BaId);
    $row = safe_fetch(safe_r_sql("SELECT BrTournament, BrLicence FROM BookingRegistrations
        WHERE BrEnId = $enId AND (BrLicence = $lic OR BrArcher = $baid)"));
    if ($row) return $row;
    return safe_fetch(safe_r_sql("SELECT EnTournament AS BrTournament, EnCode AS BrLicence
        FROM Entries
        " . bk_dossard_organiser_sql($lic) . " AND EnId = $enId")) ?: null;
}

/**
 * LIMITED relay to the core's badge generator (Accreditation/CardCustom.php), for one or SEVERAL
 * registrations (Entries[] = list of EnId) with the Qualification template $card. CardCustom.php
 * fills the slots of each A4 itself ("fill the pages"). Same elevated, limited context as
 * bk_doc_relay.
 */
function bk_doc_relay_bib($tourId, $code, $enIds, $card)
{
    $enIds = array_values(array_map('intval', (array) $enIds));
    bk_doc_relay($tourId, $code, array(
        'script' => 'Accreditation/CardCustom.php',
        'params' => array(
            'CardType'   => 'Q',
            'CardNumber' => intval($card),
            'Entries'    => $enIds,   // EnId in (...) filter in CommonCard.php
        ),
    ));
}

/**
 * Filling order of the bib slots: LEFT→RIGHT then TOP→BOTTOM.
 *
 * CardCustom.php fills the slots in the order of the template's offsets (Badges[0] = first
 * OffsetX × first OffsetY). Some templates have a "reversed" OffsetX (e.g. competition 185:
 * "105;0" → first slot top RIGHT), so a single bib lands top right. The offsets are SORTED
 * ascending: the positions stay the same (a full page is unchanged), only the filling ORDER
 * becomes natural → a single bib goes top left, several fill from the top left. Idempotent
 * (writes only when needed).
 */
function bk_dossard_normalize_layout($tourId, $card)
{
    $tourId = intval($tourId);
    $card   = intval($card);
    $r = safe_fetch(safe_r_sql("SELECT IcSettings FROM IdCards
        WHERE IcTournament = $tourId AND IcType = 'Q' AND IcNumber = $card"));
    if (!$r || (string) $r->IcSettings === '') return;
    $o = @unserialize((string) $r->IcSettings);
    if (!is_array($o) || !isset($o['OffsetX'], $o['OffsetY'])) return;

    $sortAxis = function ($csv) {
        $vals = array_values(array_filter(array_map('trim', explode(';', (string) $csv)),
            function ($v) { return $v !== ''; }));
        usort($vals, function ($a, $b) { return ((float) $a) <=> ((float) $b); });
        return implode(';', $vals);
    };
    $nx = $sortAxis($o['OffsetX']);
    $ny = $sortAxis($o['OffsetY']);
    if ($nx === (string) $o['OffsetX'] && $ny === (string) $o['OffsetY']) return;   // already natural

    $o['OffsetX'] = $nx;
    $o['OffsetY'] = $ny;
    safe_w_sql("UPDATE IdCards SET IcSettings = " . StrSafe_DB(serialize($o))
        . " WHERE IcTournament = $tourId AND IcType = 'Q' AND IcNumber = $card");
}

/**
 * Renders the STANDALONE HTML document of the mandate (doctype → /html) and prints it. Shared by
 * the organiser's preview (admin) and the public view (archer): only the CONTEXT changes.
 *
 * $ctx:
 *   'logo'    => callable($type, $width): URL of a logo (L/R/B) — differs whether the caller has
 *                an organiser session (TourLogo.php) or not (limited public endpoint);
 *   'regUrl'  => absolute URL of the online registration;
 *   'shopUrl' => absolute URL of the shop;
 *   'toolbar' => HTML of the top bar (buttons), or '' to leave it out.
 */
function bk_mandate_document($data, $m, $ctx)
{
    $pal = bk_mandate_palette($m['color']);
    $t   = $data['tour'];
    $logo = $ctx['logo'];
    $regUrl  = (string) ($ctx['regUrl'] ?? '');
    $shopUrl = (string) ($ctx['shopUrl'] ?? '');
    $toolbar = (string) ($ctx['toolbar'] ?? '');

    $block = function ($key) use ($m, $data) {
        if (empty($m['blocks'][$key])) return '';
        return '<div class="mn-free"><h2>' . bk_e(bk_mandate_sections()[$key])
             . '</h2><div class="mn-text">' . nl2br(bk_linkify_contact($m['blocks'][$key], $data['mailSubject'])) . '</div></div>';
    };

    header('Content-Type: text/html; charset=utf-8');
    $tourId = intval($t->ToId);
    $grey = function ($text) { return ' <span style="color:#7d8183">(' . bk_e($text) . ')</span>'; };
    $chips = function ($list) {
        $h = '';
        foreach ($list as $x) $h .= '<span class="mn-chip">' . bk_e($x) . '</span>';
        return '<div class="mn-chips">' . $h . '</div>';
    };

    echo '<!DOCTYPE html><html lang="' . bk_e(aut_lang_code()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . bk_e(bk_t('MnTitle', $t->ToName)) . '</title>'
        . '<style>:root{ --pri: ' . $pal['primary'] . '; --light: ' . $pal['light'] . '; --dark: ' . $pal['dark']
        . '; --on: ' . $pal['on'] . '; }</style>';
    ?>
<style>
*{ box-sizing:border-box; }
body{ margin:0; background:#e9edf2; color:#20263d;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  font-size:14px; line-height:1.5; overflow-wrap:break-word; word-break:break-word; }
.mn-title h1, .mn-text, ul.mn-list li, .mn-chip, .mn-meta td, .mn-reg, .mn-free { overflow-wrap:break-word; word-break:break-word; }
.mn-reg a, .mn-free a { word-break:break-all; }
.mn-bar{ position:sticky; top:0; display:flex; gap:8px; justify-content:center;
  padding:10px; background:#20263d; z-index:5; }
.mn-bar button, .mn-bar a{ font:inherit; font-size:13px; padding:8px 16px; border-radius:6px;
  border:0; cursor:pointer; text-decoration:none; }
.mn-bar .mn-print{ background:var(--pri); color:var(--on); font-weight:600; }
.mn-bar img{ vertical-align:middle; margin-right:4px; }
.mn-bar .mn-close{ background:#4a4f63; color:#fff; }
.mn-page{ max-width:800px; margin:18px auto; background:#fff; padding:0;
  box-shadow:0 2px 14px rgba(0,0,0,.14); }
.mn-inner{ padding:26px 34px 34px; }

/* Header */
.mn-head{ display:flex; align-items:center; gap:16px; }
.mn-head img{ max-height:64px; max-width:130px; object-fit:contain; flex:0 0 auto; }
.mn-head .mn-title{ flex:1; text-align:center; }
.mn-title h1{ margin:0; font-size:26px; color:var(--dark); line-height:1.15; }
.mn-title .mn-sub{ margin:6px 0 0; color:#4c4e50; font-size:14px; }
.mn-title .mn-org{ margin:2px 0 0; color:#7d8183; font-size:13px; }
.mn-itin a{ color:inherit; font-weight:600; }

/* Sections */
h2{ font-size:16px; margin:22px 0 8px; color:var(--dark);
  border-bottom:2px solid var(--pri); padding-bottom:4px; }
.mn-meta{ width:100%; border-collapse:collapse; }
.mn-meta th,.mn-meta td{ text-align:left; padding:6px 10px; border-bottom:1px solid #e3e6ea; font-size:14px; }
.mn-meta th{ width:170px; color:var(--dark); font-weight:600; white-space:nowrap; }
ul.mn-list{ margin:6px 0 0; padding-left:18px; }
ul.mn-list li{ margin:3px 0; }
.mn-chips{ display:flex; flex-wrap:wrap; gap:6px; margin:4px 0 0; }
.mn-chip{ background:var(--light); border:1px solid var(--pri); color:var(--dark);
  border-radius:5px; padding:2px 9px; font-size:13px; }
.mn-format{ margin:4px 0 0; }
.mn-prog table{ width:100%; border-collapse:collapse; }
.mn-prog th{ text-align:left; background:var(--light); color:var(--dark); padding:4px 8px; font-size:13px; }
.mn-prog th.SchGroup{ background:none; padding-top:6px; }
.mn-prog td{ padding:3px 8px; vertical-align:top; border-bottom:1px solid #eef0f3; font-size:13px; }
.mn-prog td:first-child{ white-space:nowrap; width:1%; color:#4c4e50; }
.mn-prog .SchTitle{ font-weight:700; color:var(--dark); }
.mn-prog .SchSubTitle{ font-weight:600; }
.mn-text{ white-space:normal; }
.mn-fees{ border-collapse:collapse; margin:4px 0 0; max-width:100%; }
.mn-fees th{ background:var(--light); color:var(--dark); font-size:13px; padding:4px 10px; text-align:center; }
.mn-fees td{ padding:4px 10px; border-bottom:1px solid #e3e6ea; vertical-align:top; }
.mn-fees td.mn-fee-v{ text-align:center; white-space:nowrap; }
.mn-fee-sub{ color:#7d8183; font-size:12px; }
.mn-evg-wrap{ overflow-x:auto; }
.mn-evg-wrap table{ min-width:660px; font-size:12px; line-height:1.25; }
.mn-evg-wrap table + table{ margin-top:-1px; }
.mn-evg-wrap th{ color:var(--dark); font-weight:600; padding:3px 2px; }
.mn-evg-wrap td{ padding:3px 1px; hyphens:auto; -webkit-hyphens:auto; overflow-wrap:break-word; }
.mn-evg-wrap td[title]{ cursor:help; }
.mn-evg-p{ font-size:10px; color:#4c4e50; white-space:nowrap; }
.mn-evg-full{ color:#b3261e; font-weight:600; }
/* An event with matches is drawn in the invitation's colour: its places take the cell's own text colour. */
.mn-evg-d .mn-evg-p, .mn-evg-d .mn-evg-full{ color:inherit; }
.mn-evg-d .mn-evg-full{ text-decoration:underline; }
.mn-free{ margin-top:4px; }
.mn-reg{ background:var(--light); border:1px solid var(--pri); border-radius:8px;
  padding:12px 14px; margin-top:6px; }
.mn-reg a{ color:var(--dark); font-weight:600; word-break:break-all; }
.mn-bottom{ text-align:center; margin-top:26px; padding-top:14px; border-top:1px solid #e3e6ea; }
.mn-bottom img{ max-height:70px; max-width:100%; object-fit:contain; }

/* Template: Modern — side accent */
body.tpl-moderne .mn-inner{ border-left:10px solid var(--pri); }
body.tpl-moderne .mn-head .mn-title{ text-align:left; }
body.tpl-moderne .mn-title h1{ font-size:30px; }

/* Template: Compact — dense */
body.tpl-compact{ font-size:13px; }
body.tpl-compact .mn-inner{ padding:18px 24px 24px; }
body.tpl-compact .mn-title h1{ font-size:22px; }
body.tpl-compact h2{ font-size:14px; margin:14px 0 5px; }
body.tpl-compact .mn-meta th,.tpl-compact .mn-meta td{ padding:3px 8px; }

/* Template: Banner — full-colour header */
body.tpl-bandeau .mn-inner{ padding-top:0; }
body.tpl-bandeau .mn-head{ background:var(--pri); color:var(--on); margin:0 -34px 18px;
  padding:22px 34px; align-items:center; }
body.tpl-bandeau .mn-title h1{ color:var(--on); }
body.tpl-bandeau .mn-title .mn-sub, body.tpl-bandeau .mn-title .mn-org{ color:var(--on); opacity:.92; }
body.tpl-bandeau .mn-head img{ background:#fff; border-radius:6px; padding:4px; }
body.tpl-bandeau h2{ border-bottom-width:3px; }

/* Template: Framed — bordered page */
body.tpl-encadre .mn-page{ border:3px solid var(--pri); }
body.tpl-encadre .mn-inner{ padding:24px 30px 30px; }
body.tpl-encadre h2{ border:0; background:var(--light); color:var(--dark);
  padding:6px 12px; border-left:5px solid var(--pri); border-radius:0 4px 4px 0; }

/* Template: Clean — thin rules, spaced titles */
body.tpl-ligne h2{ border-bottom:1px solid var(--pri); text-transform:uppercase;
  letter-spacing:.12em; font-size:13px; font-weight:700; color:var(--pri); }
body.tpl-ligne .mn-chip{ background:#fff; }
body.tpl-ligne .mn-title h1{ font-weight:600; letter-spacing:.01em; }

/* Mobile: smaller logos, so they do not crush the central title on a narrow screen */
@media (max-width:600px){
  .mn-head{ gap:10px; }
  .mn-head img{ max-height:44px; max-width:74px; }
  .mn-inner{ padding:18px 16px 24px; }
  .mn-title h1{ font-size:21px; }
  body.tpl-bandeau .mn-head{ margin:0 -16px 16px; padding:16px; }
}

@media print{
  body{ background:#fff; }
  .mn-bar{ display:none; }
  .mn-page{ box-shadow:none; margin:0; max-width:none; }
  .mn-inner{ padding:0 6mm; }
  h2{ break-after:avoid; }
  .mn-free,.mn-reg{ break-inside:avoid; }
}
</style>
</head>
<?php
    $out = '<body class="tpl-' . bk_e($m['template']) . '">'
        . ($toolbar !== '' ? '<div class="mn-bar no-print">' . $toolbar . '</div>' : '')
        . '<div class="mn-page"><div class="mn-inner"><div class="mn-head">'
        . ((!empty($m['logos']['L']) && intval($t->HasL) > 0) ? '<img src="' . bk_e($logo('L', 400)) . '" alt="">' : '')
        . '<div class="mn-title"><h1>' . bk_e($t->ToName) . '</h1><p class="mn-sub">' . bk_e($data['discLabel'])
        . ($t->ToWhere ? ' — ' . bk_e($t->ToWhere) : '') . ' — ' . bk_e(bk_date_range($t->ToWhenFrom, $t->ToWhenTo)) . '</p>';
    $org = array();
    if ($t->ToComDescr) $org[] = bk_t('OrganisedBy', $t->ToComDescr);
    if ($data['region']) $org[] = $data['region'];
    if ($org) $out .= '<p class="mn-org">' . bk_e(implode(' — ', $org)) . '</p>';
    if (!empty($data['itinerary'])) {
        $links = array();
        foreach ($data['itinerary'] as $label => $url) {
            $tab = strpos($url, 'https://') === 0 ? ' target="_blank" rel="noopener"' : '';   // intent:// stays in the page
            $links[] = '<a href="' . bk_e($url) . '"' . $tab . '>' . bk_e($label) . '</a>';
        }
        $out .= '<p class="mn-org mn-itin">' . bk_e(bk_t('Itinerary')) . ' ' . implode(' · ', $links) . '</p>';
    }
    $out .= '</div>'
        . ((!empty($m['logos']['R']) && intval($t->HasR) > 0) ? '<img src="' . bk_e($logo('R', 400)) . '" alt="">' : '')
        . '</div>' . $block('intro');

    // Form of the competition (rules B.1.1): type, sub-rule, duels. Its events and faces are at the
    // end, under the online registration ("Events of the competition").
    if (!empty($m['show']['categories'])) {
        $form = '';
        if ($data['format'] !== '' || $data['events']) {
            $form .= '<p class="mn-format"><b>' . bk_e($data['format']) . '</b>' . ($data['format'] !== '' ? ' — ' : '')
                . bk_e(bk_t($data['duels'] ? 'MnWithDuels' : 'MnNoDuel')) . '</p>';
        }
        if ($form !== '') $out .= '<h2>' . bk_e(bk_t('MnFormat')) . '</h2>' . $form;
    }

    // Full programme, as the core lays it out (bk_mandate_program, already filtered).
    if (!empty($m['show']['program']) && $data['program'] !== '') {
        $out .= '<h2>' . bk_e(bk_t('MnProgram')) . '</h2><div class="mn-prog">' . $data['program'] . '</div>';
    }

    // Field staff: officials and people in charge, one line per role.
    if (!empty($m['show']['staff']) && $data['staff']) {
        $out .= '<h2>' . bk_e(bk_t('MnStaff')) . '</h2><table class="mn-meta">';
        foreach ($data['staff'] as $st) {
            $out .= '<tr><th>' . bk_e($st['role']) . '</th><td>' . bk_e(implode(', ', $st['names'])) . '</td></tr>';
        }
        $out .= '</table>';
    }

    if (!empty($m['show']['sessions']) && $data['sessions']) {
        $out .= '<h2>' . bk_e(bk_t('MnSessions')) . '</h2><ul class="mn-list">';
        foreach ($data['sessions'] as $s) {
            $ss = bk_session_start($s);
            $places = intval($s->Places);
            $out .= '<li><b>' . bk_e(bk_t('DepCap', intval($s->SesOrder))) . '</b>' . ($s->SesName ? ' — ' . bk_e($s->SesName) : '')
                . ($ss !== '' ? ' — ' . bk_e(bk_date_time($ss)) : '')
                . ' — ' . bk_e(bk_t($places > 1 ? 'PlacesMany' : 'PlacesOne', $places)) . '</li>';
        }
        $out .= '</ul>';
    }

    if (!empty($m['show']['fees'])) {
        $out .= '<h2>' . bk_e(bk_t('MnFees')) . '</h2>';
        if ($data['fee'] <= 0 && !$data['feeAdvanced']) {
            $out .= '<p style="margin:0">' . bk_e(bk_t('MnFree')) . '</p>';
        } elseif (!$data['feeAdvanced']) {
            $out .= '<p style="margin:0"><b>' . bk_e(bk_t('MnBaseFee')) . '</b> ' . bk_e(bk_eur($data['fee'], false, $tourId)) . '</p>';
        } else {
            // Fixed prices by category (rows) and departure (columns), then the other adjustments.
            $ft = $data['feeTable'];
            $out .= '<div style="overflow-x:auto"><table class="mn-fees"><tr><th></th>';
            foreach ($ft['cols'] as $lab) $out .= '<th>' . bk_e($lab) . '</th>';
            $out .= '</tr>';
            foreach ($ft['rows'] as $r) {
                $out .= '<tr><td><b>' . bk_e($r['label']) . '</b>' . ($r['sub'] !== '' ? '<div class="mn-fee-sub">' . bk_e($r['sub']) . '</div>' : '') . '</td>';
                foreach ($r['cells'] as $v) $out .= '<td class="mn-fee-v">' . bk_e(bk_eur($v, false, $tourId)) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</table></div>';
            if ($ft['notes']) {
                $out .= '<p style="margin:8px 0 2px"><b>' . bk_e(bk_t('MnFeeAdjustTitle')) . '</b></p><ul class="mn-list">';
                foreach ($ft['notes'] as $n) $out .= '<li>' . bk_e($n[0]) . ' ' . bk_e(bk_eur($n[1], true, $tourId)) . '</li>';
                $out .= '</ul>';
            }
            $out .= '<p class="mn-text" style="margin:4px 0 0; color:#4c4e50">' . bk_e(bk_t('MnFeeExact')) . '</p>';
        }
    }

    if (!empty($m['show']['payment']) && $data['pay']) {
        $out .= '<h2>' . bk_e(bk_t('PayMeansTitle')) . '</h2><ul class="mn-list">';
        foreach ($data['pay'] as $pi) {
            $out .= '<li>' . bk_e($pi['label']) . $grey($pi['whenLabel']) . ($pi['info'] !== '' ? ' — ' . bk_linkify($pi['info']) : '') . '</li>';
        }
        $out .= '</ul>';
    }

    if (!empty($m['show']['register']) && $regUrl !== '') {
        $out .= '<h2>' . bk_e(bk_t('Brand')) . '</h2><div class="mn-reg"><p style="margin:0 0 4px">' . bk_e(bk_t('MnRegOnline')) . '</p>'
            . '<p style="margin:0"><a href="' . bk_e($regUrl) . '">' . bk_e($regUrl) . '</a></p>';
        if (!empty($data['deadline']) && bk_date_fr($data['deadline']) !== '') {
            $out .= '<p style="margin:6px 0 0"><b>' . bk_e(bk_t('MnDeadline')) . '</b> '
                . bk_e(bk_t('DateOn', bk_date_fr($data['deadline']))) . '</p>';
        }
        $out .= '</div>';
    }

    if (!empty($m['show']['shop']) && !empty($data['shop']) && $shopUrl !== '') {
        $out .= '<h2>' . bk_e(bk_t('Shop')) . '</h2><ul class="mn-list">';
        foreach ($data['shop'] as $it) {
            $out .= '<li>' . bk_e($it['label']) . ($it['price'] > 0 ? ' — ' . bk_e(bk_eur($it['price'], false, $tourId)) : '')
                . ($it['description'] !== '' ? $grey($it['description']) : '');
            $vlabels = array();
            foreach ($it['variants'] as $v) {
                $vl = trim((string) $v['label']);
                if ($vl !== '') $vlabels[] = $vl;
            }
            if ($vlabels) {
                $out .= '<div style="color:#4c4e50; font-size:13px">'
                    . bk_e(bk_t('LabelColon', $it['option'] !== '' ? $it['option'] : bk_t('MnOptions')))
                    . ' ' . bk_e(implode(', ', $vlabels)) . '</div>';
            }
            $out .= '</li>';
        }
        $out .= '</ul><p class="mn-text" style="margin:4px 0 0; color:#4c4e50">' . bk_e(bk_t('MnShopOnline'))
            . ' <a href="' . bk_e($shopUrl) . '" style="color:var(--dark)">' . bk_e($shopUrl) . '</a></p>';
    }

    foreach (array('access', 'lodging', 'catering', 'awards', 'misc', 'contact') as $k) $out .= $block($k);

    // Events of the competition, last of the invitation (user's choice, 2026-11-15: it can be long):
    // the tables by bow and class, individual then team (bk_mandate_event_grid), with
    // the places still open to each individual event while the registration is open, then faces.
    if (!empty($m['show']['categories'])) {
        $st = array('head' => 'var(--light)', 'cell' => '#fff', 'line' => '#c9ced6', 'duel' => 'var(--pri)', 'on' => 'var(--on)', 'pdf' => false);
        $ind = $data['eventGrid'];
        if ($ind && !empty($data['cfg']->BcOpen)) {
            $ind = bk_mandate_event_grid($tourId, false, $data['poss'][0], $data['poss'][1], true) ?: $ind;
        }
        $ev = '';
        $sub = function ($key) { return '<p style="margin:8px 0 4px"><b>' . bk_e(bk_t($key)) . '</b></p>'; };
        if ($ind) {
            $ev .= $sub('MnEventsInd') . '<div class="mn-evg-wrap">' . bk_mandate_event_grid_html($ind, $st) . '</div>';
            if ($ind['at'] !== '') {
                $ev .= '<p class="mn-text" style="margin:4px 0 0; color:#4c4e50; font-size:12px">' . bk_e(bk_t('MnPlacesNote'))
                    . ' ' . bk_e(bk_t('AcPlacesAt', bk_date_time($ind['at']))) . '</p>';
            }
        }
        if ($data['teamGrid']) {
            $ev .= $sub('MnEventsTeam') . '<div class="mn-evg-wrap">' . bk_mandate_event_grid_html($data['teamGrid'], $st) . '</div>';
        }
        // Events without any class to show (set up without categories): their names, as before.
        foreach (array(false => array('MnEventsInd', $ind), true => array('MnEventsTeam', $data['teamGrid'])) as $team => $x) {
            if ($x[1]) continue;
            $list = array();
            foreach ($data['events'] as $e1) if ($e1['team'] === (bool) $team) $list[] = $e1['name'] . ($e1['duels'] ? ' · ' . bk_t('MnDuels') : '');
            if ($list) $ev .= $sub($x[0]) . $chips($list);
        }
        if (!$data['events']) {
            if ($data['divisions']) $ev .= $sub('MnBows') . $chips($data['divisions']);
            if ($data['classes']) $ev .= $sub('MnClasses') . $chips($data['classes']);
        }
        if ($data['faces']) $ev .= $sub('MnFaces') . $chips($data['faces']);
        if ($ev !== '') $out .= '<h2>' . bk_e(bk_t('MnEventsTitle')) . '</h2>' . $ev;
    }
    if (!empty($m['logos']['B']) && intval($t->HasB) > 0) {
        $out .= '<div class="mn-bottom"><img src="' . bk_e($logo('B', 1000)) . '" alt=""></div>';
    }
    echo $out . '</div></div></body></html>';
}
