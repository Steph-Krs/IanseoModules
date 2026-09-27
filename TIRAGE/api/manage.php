<?php
/**
 * JSON endpoint of the preparation and control pages.
 *
 * Every action needs the right to create a competition (AclRoot), since a draw
 * belongs to none. Actions that write also need a POST carrying the session's
 * anti-CSRF token. Answers use the ianseo envelope: error 0 for success, msg for
 * the reason of a failure, then the payload.
 *
 * Reading actions: load, state.
 * Preparation:     saveShow, addCategory, saveCategory, moveCategory, deleteCategory,
 *                  addTeams, saveTeam, moveTeam, deleteTeam, importEvents, linkClubs,
 *                  applySeason, uploadImage, clearImage, newTokens.
 * Control:         draw, unassign, undo, reset, scene, stats.
 */

require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/facts.php';
require_once dirname(__DIR__) . '/lib/legacy.php';

tir_require_admin();

$JSON   = ['error' => 1, 'msg' => ''];
$action = (string)($_REQUEST['action'] ?? '');

/**
 * Stop with an error message.
 *
 * @param string $key Language key.
 * @param mixed $a
 */
function tir_fail($key, $a = null) {
    JsonOut(['error' => 1, 'msg' => tir_text($key, $a)]);
}

/**
 * The show named by the request, or stop.
 *
 * @return array
 */
function tir_req_show() {
    $show = tir_show((int)($_REQUEST['id'] ?? 0));
    if (!$show) tir_fail('ErrNoShow');
    return $show;
}

/**
 * The category named by the request, or stop.
 *
 * @return object
 */
function tir_req_category() {
    $cat = tir_category((int)($_REQUEST['cat'] ?? 0));
    if (!$cat) tir_fail('ErrNoCategory');
    return $cat;
}

/**
 * The team named by the request, or stop.
 *
 * @return object
 */
function tir_req_team() {
    $team = tir_team((int)($_REQUEST['team'] ?? 0));
    if (!$team) tir_fail('ErrNoTeam');
    return $team;
}

/**
 * Comparable form of a club or team name: no accents, no punctuation, SAINT as ST.
 *
 * @param string $s
 * @return string
 */
function tir_name_key($s) {
    $s = mb_strtoupper(tir_clean_name($s));
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
    }
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = preg_replace('/\bSAINTE?\b/u', 'ST', $s);
    return trim($s);
}

/**
 * How well two names designate the same club, from 0 to 100.
 *
 * @param string $a
 * @param string $b
 * @return int
 */
function tir_name_score($a, $b) {
    $ka = tir_name_key($a);
    $kb = tir_name_key($b);
    if ($ka === '' || $kb === '') return 0;
    if ($ka === $kb) return 100;
    $ta = array_unique(explode(' ', $ka));
    $tb = array_unique(explode(' ', $kb));
    $common = count(array_intersect($ta, $tb));
    if ($common === min(count($ta), count($tb))) return 80;
    return (int)round(100 * $common / count(array_unique(array_merge($ta, $tb))));
}

$writes = ['saveShow', 'addCategory', 'saveCategory', 'moveCategory', 'deleteCategory', 'addTeams', 'saveTeam',
    'moveTeam', 'deleteTeam', 'importEvents', 'linkClubs', 'applySeason', 'uploadImage', 'clearImage', 'newTokens',
    'draw', 'unassign', 'undo', 'reset', 'scene', 'stats'];
if (in_array($action, $writes, true) && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tir_token_ok())) {
    tir_fail('ErrToken');
}

switch ($action) {

    case 'load':
        $show   = tir_req_show();
        $source = tir_source($show['source']);
        $cats   = tir_categories($show['id'], true);

        $events  = $source ? tir_source_events($source['id']) : [];
        $linked  = array_values(array_unique(array_filter(array_column($cats, 'event'))));
        $ranking = $source ? tir_source_ranking($source['id'], $linked, false) : [];

        $clubs = [];
        foreach ($ranking as $code => $ev) {
            foreach ($ev['items'] as $club => $item) {
                $clubs[$code][] = ['code' => (string)$club, 'name' => (string)$item['countryName'], 'rank' => (int)$item['rank']];
            }
            usort($clubs[$code], function ($a, $b) { return strcmp($a['name'], $b['name']); });
        }
        foreach ($cats as &$c) {
            foreach ($c['teams'] as &$t) {
                $item = $ranking[$c['event']]['items'][$t['club']] ?? null;
                $t['hint'] = $item ? (int)$item['rank'] : null;
            }
        }
        unset($c, $t);

        $base = tir_url();
        $JSON = [
            'error'        => 0,
            'msg'          => '',
            'show'         => $show,
            'source'       => $source,
            'categories'   => $cats,
            'competitions' => tir_competitions(),
            'events'       => $events,
            'clubs'        => (object)$clubs,
            'fonts'        => array_keys(tir_fonts()),
            'national'     => (bool)tir_table_exists('REP_Rangs'),
            'links'        => [
                'display' => $base . 'display.php?t=' . $show['token'],
                'speaker' => $base . 'speaker.php?t=' . $show['speakerToken'],
                'control' => $base . 'control.php?id=' . $show['id'],
            ],
        ];
        break;

    case 'state':
        $show = tir_req_show();
        $JSON = ['error' => 0, 'msg' => '', 'revision' => $show['revision']];
        if ((int)($_REQUEST['rev'] ?? 0) !== $show['revision']) {
            $JSON['state'] = tir_state($show, 'admin');
        }
        break;

    case 'saveShow':
        $show = tir_req_show();
        $set  = [];
        if (isset($_POST['title']))    $set[] = 'DwTitle=' . StrSafe_DB(tir_clean_name($_POST['title']));
        if (isset($_POST['subtitle'])) $set[] = 'DwSubtitle=' . StrSafe_DB(tir_clean_name($_POST['subtitle'], 160));
        if (isset($_POST['source'])) {
            $src = (int)$_POST['source'];
            if ($src && !tir_source($src)) tir_fail('ErrNoSource');
            $set[] = 'DwSource=' . $src;
        }
        if (isset($_POST['look'])) {
            // An unreadable look is refused: cleaning it would silently reset every setting.
            $look = json_decode((string)$_POST['look'], true);
            if (!is_array($look)) tir_fail('ErrField');
            $set[] = 'DwLook=' . StrSafe_DB(json_encode(tir_clean_look($look)));
        }
        if ($set) {
            safe_w_sql('UPDATE DrawShows SET ' . implode(', ', $set) . ' WHERE DwId=' . $show['id']);
            tir_touch($show['id'], true);
        }
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'addCategory':
        $show = tir_req_show();
        $name = tir_clean_name($_POST['name'] ?? '');
        if ($name === '') tir_fail('ErrNameEmpty');
        tir_add_category($show['id'], $name, $_POST['event'] ?? '', $_POST['type'] ?? 'teams');
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'saveCategory':
        $cat = tir_req_category();
        $set = [];
        if (isset($_POST['name'])) {
            $name = tir_clean_name($_POST['name']);
            if ($name === '') tir_fail('ErrNameEmpty');
            $set[] = 'DcName=' . StrSafe_DB($name);
        }
        if (isset($_POST['event'])) $set[] = 'DcEvent=' . StrSafe_DB(tir_clean_event($_POST['event']));
        $toStages = isset($_POST['type']) && tir_clean_type($_POST['type']) === 'stages' && $cat->DcType !== 'stages';
        if (isset($_POST['type'])) {
            $set[] = 'DcType=' . StrSafe_DB(tir_clean_type($_POST['type']));
            // Stages belong to no team event of the previous season.
            if (tir_clean_type($_POST['type']) === 'stages') $set[] = "DcEvent=''";
        }
        if ($set) {
            safe_w_sql('UPDATE DrawCategories SET ' . implode(', ', $set) . ' WHERE DcId=' . (int)$cat->DcId);
            if ($toStages) tir_split_stage_names($cat->DcId);
            tir_touch($cat->DcShow, true);
        }
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'moveCategory':
        $cat  = tir_req_category();
        $dir  = (int)($_POST['dir'] ?? 0) < 0 ? -1 : 1;
        $ids  = [];
        $q = safe_r_sql("SELECT DcId FROM DrawCategories WHERE DcShow=" . (int)$cat->DcShow . " ORDER BY DcOrder, DcId");
        while ($r = safe_fetch($q)) $ids[] = (int)$r->DcId;
        $i = array_search((int)$cat->DcId, $ids, true);
        $j = $i + $dir;
        if ($i !== false && $j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $k => $id) safe_w_sql("UPDATE DrawCategories SET DcOrder=" . ($k + 1) . " WHERE DcId=$id");
            tir_touch($cat->DcShow, true);
        }
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'deleteCategory':
        $cat = tir_req_category();
        safe_w_sql("DELETE FROM DrawTeams WHERE DtCategory=" . (int)$cat->DcId);
        safe_w_sql("DELETE FROM DrawCategories WHERE DcId=" . (int)$cat->DcId);
        safe_w_sql("UPDATE DrawShows SET DwCategory=0 WHERE DwId=" . (int)$cat->DcShow . " AND DwCategory=" . (int)$cat->DcId);
        tir_touch($cat->DcShow, true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'addTeams':
        $cat   = tir_req_category();
        $added = 0;
        // After the separator: the club code of a team, or the dates of a stage.
        $stages = $cat->DcType === 'stages';
        foreach (preg_split('/\R/u', (string)($_POST['lines'] ?? '')) as $line) {
            $parts = preg_split('/\s*[;\t]\s*/u', trim($line), 2);
            $name  = tir_clean_name($parts[0] ?? '');
            if ($name === '') continue;
            if ($stages) tir_add_team($cat->DcId, $name, '', [], $parts[1] ?? '');
            else tir_add_team($cat->DcId, $name, $parts[1] ?? '');
            $added++;
        }
        if (!$added) tir_fail('ErrNameEmpty');
        tir_touch($cat->DcShow, true);
        $JSON = ['error' => 0, 'msg' => '', 'added' => $added];
        break;

    case 'saveTeam':
        $team  = tir_req_team();
        $field = (string)($_POST['field'] ?? '');
        $value = $_POST['value'] ?? '';
        $columns = ['participations' => 'DtParticipations', 'wins' => 'DtWins', 'podiums' => 'DtPodiums', 'rank' => 'DtRankPrev'];
        if ($field === 'name') {
            $name = tir_clean_name($value);
            if ($name === '') tir_fail('ErrNameEmpty');
            $sql = 'DtName=' . StrSafe_DB($name);
        } elseif ($field === 'club') {
            $sql = 'DtClub=' . StrSafe_DB(tir_clean_club($value));
        } elseif ($field === 'detail') {
            $sql = 'DtDetail=' . StrSafe_DB(tir_clean_name($value, 160));
        } elseif ($field === 'note') {
            $sql = 'DtNote=' . StrSafe_DB(mb_substr(trim((string)$value), 0, 4000));
        } elseif (isset($columns[$field])) {
            $sql = $columns[$field] . '=' . tir_sql_int(tir_int_or_null($value));
        } else {
            tir_fail('ErrField');
        }
        safe_w_sql("UPDATE DrawTeams SET $sql WHERE DtId=" . (int)$team->DtId);
        tir_touch($team->DcShow, true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'moveTeam':
        $team = tir_req_team();
        $dir  = (int)($_POST['dir'] ?? 0) < 0 ? -1 : 1;
        $ids  = [];
        $q = safe_r_sql("SELECT DtId FROM DrawTeams WHERE DtCategory=" . (int)$team->DtCategory . " ORDER BY DtOrder, DtId");
        while ($r = safe_fetch($q)) $ids[] = (int)$r->DtId;
        $i = array_search((int)$team->DtId, $ids, true);
        $j = $i + $dir;
        if ($i !== false && $j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $k => $id) safe_w_sql("UPDATE DrawTeams SET DtOrder=" . ($k + 1) . " WHERE DtId=$id");
            tir_touch($team->DcShow, true);
        }
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'deleteTeam':
        $team = tir_req_team();
        if ((int)$team->DtPosition > 0) tir_unassign_team($team);
        safe_w_sql("DELETE FROM DrawTeams WHERE DtId=" . (int)$team->DtId);
        tir_touch($team->DcShow, true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'importEvents':
        $show   = tir_req_show();
        $source = tir_source($show['source']);
        if (!$source) tir_fail('ErrNoSource');
        $names = array_column(tir_source_events($source['id']), 'name', 'code');
        $added = 0;
        foreach ((array)($_POST['events'] ?? []) as $event) {
            $event = tir_clean_event($event);
            if ($event === '' || !isset($names[$event])) continue;

            $q = safe_r_sql("SELECT DcId FROM DrawCategories WHERE DcShow={$show['id']} AND DcEvent=" . StrSafe_DB($event) . " LIMIT 1");
            $r = safe_fetch($q);
            $catId = $r ? (int)$r->DcId : tir_add_category($show['id'], $names[$event], $event);

            $known = [];
            $q = safe_r_sql("SELECT DtClub FROM DrawTeams WHERE DtCategory=$catId AND DtClub<>''");
            while ($t = safe_fetch($q)) $known[$t->DtClub] = true;

            foreach (tir_source_clubs($source['id'], $event) as $club) {
                if (isset($known[$club['code']])) continue;
                tir_add_team($catId, $club['name'], $club['code']);
                $added++;
            }
        }
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => tir_text('MsgImported', $added)];
        break;

    case 'linkClubs':
        $show   = tir_req_show();
        $source = tir_source($show['source']);
        if (!$source) tir_fail('ErrNoSource');
        $linked = 0;
        foreach (tir_categories($show['id'], true) as $c) {
            if ($c['event'] === '') continue;
            $clubs = tir_source_clubs($source['id'], $c['event']);
            $taken = array_flip(array_filter(array_column($c['teams'], 'club')));

            // Best pairs first, so two teams never compete for the same club.
            $pairs = [];
            foreach ($c['teams'] as $t) {
                if ($t['club'] !== '') continue;
                foreach ($clubs as $club) {
                    if (isset($taken[$club['code']])) continue;
                    $score = tir_name_score($t['name'], $club['name']);
                    if ($score >= 60) $pairs[] = [$score, $t['id'], $club['code']];
                }
            }
            usort($pairs, function ($a, $b) { return $b[0] - $a[0]; });
            $done = [];
            foreach ($pairs as [$score, $teamId, $code]) {
                if (isset($done[$teamId]) || isset($taken[$code])) continue;
                safe_w_sql("UPDATE DrawTeams SET DtClub=" . StrSafe_DB($code) . " WHERE DtId=$teamId");
                $done[$teamId] = true;
                $taken[$code]  = true;
                $linked++;
            }
        }
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => tir_text('MsgLinked', $linked)];
        break;

    case 'applySeason':
        $show   = tir_req_show();
        $source = tir_source($show['source']);
        if (!$source) tir_fail('ErrNoSource');
        if ($show['seasonApplied'] === $source['id']) tir_fail('ErrSeasonApplied');

        $cats    = tir_categories($show['id'], true);
        $ranking = tir_source_ranking($source['id'], array_filter(array_column($cats, 'event')), false);
        $updated = 0; $cleared = 0;
        foreach ($cats as $c) {
            if ($c['event'] === '') continue;
            foreach ($c['teams'] as $t) {
                if ($t['club'] === '') continue;
                $item = $ranking[$c['event']]['items'][$t['club']] ?? null;
                if (!$item) {
                    safe_w_sql("UPDATE DrawTeams SET DtRankPrev=NULL WHERE DtId={$t['id']}");
                    $cleared++;
                    continue;
                }
                $rank = (int)$item['rank'];
                $s    = $t['stats'];
                $sql  = 'DtParticipations=' . ((int)$s['participations'] + 1) . ', DtRankPrev=' . ($rank > 0 ? $rank : 'NULL');
                if ($rank === 1) $sql .= ', DtWins=' . ((int)$s['wins'] + 1);
                if ($rank >= 1 && $rank <= 3) $sql .= ', DtPodiums=' . ((int)$s['podiums'] + 1);
                safe_w_sql("UPDATE DrawTeams SET $sql WHERE DtId={$t['id']}");
                $updated++;
            }
        }
        safe_w_sql("UPDATE DrawShows SET DwSeasonApplied={$source['id']} WHERE DwId={$show['id']}");
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => tir_text('MsgSeasonApplied', ['updated' => $updated, 'cleared' => $cleared])];
        break;

    case 'uploadImage':
        $show = tir_req_show();
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) tir_fail('ErrImage');
        $image = tir_check_image((string)file_get_contents($file['tmp_name']));
        if (!$image) tir_fail('ErrImage');
        safe_w_sql("UPDATE DrawShows SET DwImage=" . StrSafe_DB(base64_encode($image['data'])) . ",
            DwImageType=" . StrSafe_DB($image['type']) . " WHERE DwId={$show['id']}");
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'clearImage':
        $show = tir_req_show();
        safe_w_sql("UPDATE DrawShows SET DwImage=NULL, DwImageType='' WHERE DwId={$show['id']}");
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'newTokens':
        $show = tir_req_show();
        safe_w_sql("UPDATE DrawShows SET DwToken='" . tir_new_token() . "', DwSpeakerToken='" . tir_new_token() . "'
            WHERE DwId={$show['id']}");
        tir_touch($show['id'], true);
        $JSON = ['error' => 0, 'msg' => ''];
        break;

    case 'draw':
    case 'unassign':
        $team = tir_req_team();
        if ($action === 'draw') tir_draw_team($team);
        else tir_unassign_team($team);
        tir_touch($team->DcShow);
        $JSON = ['error' => 0, 'msg' => '', 'state' => tir_state(tir_show($team->DcShow), 'admin')];
        break;

    case 'undo':
    case 'reset':
        $cat = tir_req_category();
        if ($action === 'undo') tir_undo_last($cat->DcId);
        else tir_reset_category($cat->DcId);
        tir_touch($cat->DcShow);
        $JSON = ['error' => 0, 'msg' => '', 'state' => tir_state(tir_show($cat->DcShow), 'admin')];
        break;

    case 'scene':
        $show  = tir_req_show();
        $scene = (string)($_POST['scene'] ?? '');
        if (!in_array($scene, TIR_SCENES, true)) tir_fail('ErrField');
        $set = "DwScene='$scene'";
        if (isset($_POST['cat'])) {
            $cat = tir_category((int)$_POST['cat']);
            if ($cat && (int)$cat->DcShow === $show['id']) $set .= ', DwCategory=' . (int)$cat->DcId;
        }
        safe_w_sql("UPDATE DrawShows SET $set WHERE DwId={$show['id']}");
        tir_touch($show['id']);
        $JSON = ['error' => 0, 'msg' => '', 'state' => tir_state(tir_show($show['id']), 'admin')];
        break;

    case 'stats':
        $show = tir_req_show();
        $list = array_values(array_intersect(TIR_STATS, explode(',', (string)($_POST['list'] ?? ''))));
        safe_w_sql("UPDATE DrawShows SET DwStats=" . StrSafe_DB(implode(',', $list)) . " WHERE DwId={$show['id']}");
        tir_touch($show['id']);
        $JSON = ['error' => 0, 'msg' => '', 'state' => tir_state(tir_show($show['id']), 'admin')];
        break;

    default:
        tir_fail('ErrAction');
}

JsonOut($JSON);
