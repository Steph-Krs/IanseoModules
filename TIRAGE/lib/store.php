<?php
/**
 * Data access of the live draw module: shows, categories, teams, and the state
 * the screens poll.
 *
 * SYNCHRONISATION. Every change bumps DwRevision. A screen polls with the last
 * revision it holds and receives the full state only when it moved, so an idle
 * screen costs one indexed read per poll. DwDataRevision moves only when the
 * lists themselves change (a team added, renamed, an image replaced), which is
 * what tells the commentator screen to reload its previous-season facts.
 *
 * Nothing here checks access rights: callers do, before calling.
 */

const TIR_SCENES = ['idle', 'category', 'summary'];
const TIR_STATS  = ['participations', 'wins', 'podiums', 'rank'];

/**
 * Kinds of list.
 *   teams   places are drawn, the screen reveals each team in the next slot;
 *   stages  nothing is drawn: the season's stages are shown one after the other,
 *           in their own order, as the speaker announces them. A stage is "shown"
 *           when it has a place, which keeps one mechanism for both kinds.
 */
const TIR_TYPES = ['teams', 'stages'];

/**
 * Fonts offered for the public screen, key to CSS stack.
 *
 * System fonts only: the screen often runs on a machine with no internet access,
 * and the artifact of a missing webfont is a fallback nobody chose.
 *
 * @return array
 */
function tir_fonts() {
    return [
        'impact'    => "Impact, 'Arial Narrow Bold', 'Haettenschweiler', sans-serif",
        'arial'     => "Arial, Helvetica, sans-serif",
        'segoe'     => "'Segoe UI', Tahoma, Geneva, sans-serif",
        'trebuchet' => "'Trebuchet MS', Helvetica, sans-serif",
        'verdana'   => "Verdana, Geneva, sans-serif",
        'calibri'   => "Calibri, Carlito, sans-serif",
        'georgia'   => "Georgia, serif",
        'palatino'  => "'Palatino Linotype', Palatino, serif",
        'times'     => "'Times New Roman', Times, serif",
    ];
}

/**
 * Appearance of a new show.
 *
 * @return array
 */
function tir_default_look() {
    return [
        'bg'        => '#02215e',
        'accent'    => '#ffffff',
        'text'      => '#ffffff',
        'fontTitle' => 'impact',
        'fontBody'  => 'arial',
        'sizeTitle' => 3.2,
        'sizeTeam'  => 1.6,
        'sizeRank'  => 1.5,
        'overlay'   => 0.35,
        'margin'    => 10,
        // Spacing inside a slot. The defaults reproduce the layout of the first
        // versions, where these values were fixed.
        'rankSpace'  => 2.6,   // width of the place number, in its own font size
        'statsSpace' => 0.6,   // between the team name and its statistics, rem
        'statGap'    => 1.1,   // between two statistics, in their font size
        'statWidth'  => 3.8,   // minimum width of one statistic, in its font size
        'ambient'   => 1,
        'slots'     => 1,
    ];
}

/**
 * Validate an appearance coming from a form or an old save.
 *
 * @param mixed $in Decoded look.
 * @return array A complete look with every value in range.
 */
function tir_clean_look($in) {
    $look = tir_default_look();
    if (!is_array($in)) return $look;

    foreach (['bg', 'accent', 'text'] as $k) {
        if (isset($in[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$in[$k])) $look[$k] = (string)$in[$k];
    }
    $fonts = tir_fonts();
    foreach (['fontTitle', 'fontBody'] as $k) {
        if (isset($in[$k]) && isset($fonts[$in[$k]])) $look[$k] = $in[$k];
    }
    $ranges = [
        'sizeTitle' => [1.5, 7], 'sizeTeam' => [0.8, 4], 'sizeRank' => [0.8, 4],
        'overlay' => [0, 0.95], 'margin' => [0, 30],
        'rankSpace' => [1, 5], 'statsSpace' => [0, 4], 'statGap' => [0, 3], 'statWidth' => [1.5, 6],
    ];
    foreach ($ranges as $k => [$min, $max]) {
        if (isset($in[$k]) && is_numeric($in[$k])) $look[$k] = max($min, min($max, round((float)$in[$k], 2)));
    }
    foreach (['ambient', 'slots'] as $k) {
        if (isset($in[$k])) $look[$k] = $in[$k] ? 1 : 0;
    }
    return $look;
}

/**
 * A new secret for a screen link.
 *
 * @return string 32 hexadecimal characters.
 */
function tir_new_token() {
    return bin2hex(random_bytes(16));
}

/**
 * Integer or null, for the optional statistics.
 *
 * @param mixed $v
 * @return int|null
 */
function tir_int_or_null($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return max(0, min(32000, (int)$v));
}

/**
 * Collapse runs of spaces and trim, for names typed or imported.
 *
 * @param mixed $s
 * @param int $max Maximum length in characters.
 * @return string
 */
function tir_clean_name($s, $max = 120) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_substr($s, 0, $max);
}

/**
 * SQL literal for an optional integer.
 *
 * @param int|null $v
 * @return string
 */
function tir_sql_int($v) {
    return $v === null ? 'NULL' : (string)(int)$v;
}

/** Columns of DrawShows every reader needs, the image excluded. */
const TIR_SHOW_COLUMNS = 'DwId, DwTitle, DwSubtitle, DwSource, DwSeasonApplied, DwToken, DwSpeakerToken, DwScene,
    DwCategory, DwStats, DwLook, DwImageType, LENGTH(DwImage) AS DwImageLength, DwRevision, DwDataRevision,
    DwCreated, DwUpdated';

/**
 * Turn a DrawShows row into the array the rest of the module uses.
 *
 * @param object $r
 * @return array
 */
function tir_show_array($r) {
    $stats = array_values(array_intersect(TIR_STATS, explode(',', (string)$r->DwStats)));
    return [
        'id'            => (int)$r->DwId,
        'title'         => (string)$r->DwTitle,
        'subtitle'      => (string)$r->DwSubtitle,
        'source'        => (int)$r->DwSource,
        'seasonApplied' => (int)$r->DwSeasonApplied,
        'token'         => (string)$r->DwToken,
        'speakerToken'  => (string)$r->DwSpeakerToken,
        'scene'         => in_array($r->DwScene, TIR_SCENES, true) ? $r->DwScene : 'idle',
        'category'      => (int)$r->DwCategory,
        'stats'         => $stats,
        'look'          => tir_clean_look(json_decode((string)$r->DwLook, true)),
        'hasImage'      => (int)$r->DwImageLength > 0,
        'imageType'     => (string)$r->DwImageType,
        'revision'      => (int)$r->DwRevision,
        'dataRevision'  => (int)$r->DwDataRevision,
        'created'       => (string)$r->DwCreated,
        'updated'       => (string)$r->DwUpdated,
    ];
}

/**
 * One show by id.
 *
 * @param int $id
 * @return array|null
 */
function tir_show($id) {
    $id = (int)$id;
    if ($id <= 0) return null;
    $q = safe_r_sql("SELECT " . TIR_SHOW_COLUMNS . " FROM DrawShows WHERE DwId=$id");
    $r = safe_fetch($q);
    return $r ? tir_show_array($r) : null;
}

/**
 * The show a screen link opens, and which screen it is.
 *
 * Two tokens per show: the public screen's link can be handed to whoever runs
 * the video mixer, while the commentators' link also reveals the notes.
 *
 * @param string $token
 * @return array [show array or null, 'display' | 'speaker' | '']
 */
function tir_show_by_token($token) {
    $token = (string)$token;
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return [null, ''];
    $t = StrSafe_DB($token);
    $q = safe_r_sql("SELECT " . TIR_SHOW_COLUMNS . " FROM DrawShows WHERE DwToken=$t OR DwSpeakerToken=$t LIMIT 1");
    $r = safe_fetch($q);
    if (!$r) return [null, ''];
    $show = tir_show_array($r);
    return [$show, hash_equals($show['token'], $token) ? 'display' : 'speaker'];
}

/**
 * Current revision of a show, the one read a polling screen makes when idle.
 *
 * @param string $token
 * @return int 0 when the token opens nothing.
 */
function tir_revision_by_token($token) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return 0;
    $t = StrSafe_DB($token);
    $q = safe_r_sql("SELECT DwRevision FROM DrawShows WHERE DwToken=$t OR DwSpeakerToken=$t LIMIT 1");
    $r = safe_fetch($q);
    return $r ? (int)$r->DwRevision : 0;
}

/**
 * Record that a show changed, so every screen reloads its state.
 *
 * @param int $id
 * @param bool $data True when the lists changed, not just what is on air.
 */
function tir_touch($id, $data = false) {
    safe_w_sql("UPDATE DrawShows SET DwRevision=DwRevision+1"
        . ($data ? ", DwDataRevision=DwDataRevision+1" : '')
        . ", DwUpdated=NOW() WHERE DwId=" . (int)$id);
}

/**
 * All shows, newest first, with their progress.
 *
 * @return array
 */
function tir_show_list() {
    $out = [];
    $q = safe_r_sql("SELECT " . TIR_SHOW_COLUMNS . ",
            (SELECT COUNT(*) FROM DrawTeams INNER JOIN DrawCategories ON DcId=DtCategory WHERE DcShow=DwId) AS NbTeams,
            (SELECT COUNT(*) FROM DrawTeams INNER JOIN DrawCategories ON DcId=DtCategory WHERE DcShow=DwId AND DtPosition>0) AS NbDrawn
        FROM DrawShows ORDER BY DwId DESC");
    while ($r = safe_fetch($q)) {
        $s = tir_show_array($r);
        $s['teams'] = (int)$r->NbTeams;
        $s['drawn'] = (int)$r->NbDrawn;
        $out[] = $s;
    }
    return $out;
}

/**
 * Create an empty show.
 *
 * @param string $title
 * @param int $source Previous-season competition, 0 for none.
 * @return int New show id.
 */
function tir_create_show($title, $source = 0) {
    $look = StrSafe_DB(json_encode(tir_default_look()));
    safe_w_sql("INSERT INTO DrawShows SET DwTitle=" . StrSafe_DB(tir_clean_name($title)) . ",
        DwSource=" . (int)$source . ", DwToken='" . tir_new_token() . "', DwSpeakerToken='" . tir_new_token() . "',
        DwStats='', DwLook=$look, DwCreated=NOW(), DwUpdated=NOW()");
    return (int)safe_w_last_id();
}

/**
 * Delete a show with its categories and teams.
 *
 * @param int $id
 */
function tir_delete_show($id) {
    $id = (int)$id;
    safe_w_sql("DELETE FROM DrawTeams WHERE DtCategory IN (SELECT DcId FROM DrawCategories WHERE DcShow=$id)");
    safe_w_sql("DELETE FROM DrawCategories WHERE DcShow=$id");
    safe_w_sql("DELETE FROM DrawShows WHERE DwId=$id");
}

/**
 * Copy a show — lists, history, notes and appearance — with no place drawn.
 *
 * This is how next year's draw starts from this year's: the teams that stay are
 * already there with their history, and the links are new.
 *
 * @param int $id
 * @return int New show id, 0 when the source does not exist.
 */
function tir_duplicate_show($id) {
    $show = tir_show($id);
    if (!$show) return 0;

    safe_w_sql("INSERT INTO DrawShows (DwTitle, DwSubtitle, DwSource, DwSeasonApplied, DwToken, DwSpeakerToken, DwScene,
            DwCategory, DwStats, DwLook, DwImage, DwImageType, DwCreated, DwUpdated)
        SELECT " . StrSafe_DB(tir_clean_name(tir_text('CopyOf', $show['title']))) . ", DwSubtitle, DwSource, DwSeasonApplied,
            '" . tir_new_token() . "', '" . tir_new_token() . "', 'idle', 0, DwStats, DwLook, DwImage, DwImageType, NOW(), NOW()
        FROM DrawShows WHERE DwId=" . $show['id']);
    $newId = (int)safe_w_last_id();

    $q = safe_r_sql("SELECT DcId, DcOrder, DcName, DcEvent, DcType FROM DrawCategories WHERE DcShow={$show['id']} ORDER BY DcOrder, DcId");
    while ($c = safe_fetch($q)) {
        safe_w_sql("INSERT INTO DrawCategories SET DcShow=$newId, DcOrder=" . (int)$c->DcOrder . ",
            DcName=" . StrSafe_DB($c->DcName) . ", DcEvent=" . StrSafe_DB($c->DcEvent) . ", DcType=" . StrSafe_DB($c->DcType));
        $newCat = (int)safe_w_last_id();
        safe_w_sql("INSERT INTO DrawTeams (DtCategory, DtName, DtDetail, DtClub, DtPosition, DtOrder, DtParticipations, DtWins, DtPodiums, DtRankPrev, DtNote)
            SELECT $newCat, DtName, DtDetail, DtClub, 0, DtOrder, DtParticipations, DtWins, DtPodiums, DtRankPrev, DtNote
            FROM DrawTeams WHERE DtCategory=" . (int)$c->DcId . " ORDER BY DtOrder, DtId");
    }
    return $newId;
}

/**
 * Add a category at the end of a show.
 *
 * @param int $showId
 * @param string $name
 * @param string $event Team event code of the previous-season competition, or ''.
 * @param string $type One of TIR_TYPES.
 * @return int New category id.
 */
function tir_add_category($showId, $name, $event = '', $type = 'teams') {
    $showId = (int)$showId;
    $type   = tir_clean_type($type);
    $q = safe_r_sql("SELECT COALESCE(MAX(DcOrder), 0) + 1 AS n FROM DrawCategories WHERE DcShow=$showId");
    $order = (int)safe_fetch($q)->n;
    safe_w_sql("INSERT INTO DrawCategories SET DcShow=$showId, DcOrder=$order,
        DcName=" . StrSafe_DB(tir_clean_name($name)) . ",
        DcEvent=" . StrSafe_DB($type === 'teams' ? tir_clean_event($event) : '') . ",
        DcType=" . StrSafe_DB($type));
    return (int)safe_w_last_id();
}

/**
 * A kind of list, defaulting to a team list.
 *
 * @param mixed $type
 * @return string
 */
function tir_clean_type($type) {
    return in_array($type, TIR_TYPES, true) ? $type : 'teams';
}

/**
 * Split entries written "Place - dates" into a name and a detail line.
 *
 * The draw page used before this module had no kind of list for the stages: they
 * were entered as teams named "Smarves - 18 et 19 Avril 2026". Turning such a list
 * into a list of stages recovers the two parts. Entries that already have a
 * detail, or no separator, are left alone, so running it twice changes nothing.
 *
 * @param int $catId
 * @return int Entries split.
 */
function tir_split_stage_names($catId) {
    $n = 0;
    $q = safe_r_sql("SELECT DtId, DtName FROM DrawTeams WHERE DtCategory=" . (int)$catId . " AND DtDetail=''");
    while ($r = safe_fetch($q)) {
        $parts = preg_split('/\s+[-–—]\s+/u', (string)$r->DtName, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') continue;
        safe_w_sql("UPDATE DrawTeams SET DtName=" . StrSafe_DB(tir_clean_name($parts[0])) . ",
            DtDetail=" . StrSafe_DB(tir_clean_name($parts[1], 160)) . " WHERE DtId=" . (int)$r->DtId);
        $n++;
    }
    return $n;
}

/**
 * An event code as ianseo stores them.
 *
 * @param mixed $event
 * @return string
 */
function tir_clean_event($event) {
    return preg_match('/^[A-Za-z0-9_\-]{1,10}$/', (string)$event) ? (string)$event : '';
}

/**
 * A club code as ianseo stores them (Countries.CoCode).
 *
 * @param mixed $club
 * @return string
 */
function tir_clean_club($club) {
    return preg_match('/^[A-Za-z0-9_\-]{1,10}$/', trim((string)$club)) ? trim((string)$club) : '';
}

/**
 * Add a team to a category.
 *
 * @param int $catId
 * @param string $name
 * @param string $club
 * @param array $stats participations, wins, podiums, rank — each optional.
 * @param string $detail Second line of a stage (its dates), '' for a team.
 * @return int New team id.
 */
function tir_add_team($catId, $name, $club = '', $stats = [], $detail = '') {
    $catId = (int)$catId;
    // At the end of the list: rows of version 1 all carry order 0 and keep their entry order.
    $q = safe_r_sql("SELECT COALESCE(MAX(DtOrder), 0) + 1 AS n FROM DrawTeams WHERE DtCategory=$catId");
    $order = (int)safe_fetch($q)->n;
    safe_w_sql("INSERT INTO DrawTeams SET DtCategory=$catId, DtOrder=$order,
        DtName=" . StrSafe_DB(tir_clean_name($name)) . ",
        DtDetail=" . StrSafe_DB(tir_clean_name($detail, 160)) . ",
        DtClub=" . StrSafe_DB(tir_clean_club($club)) . ",
        DtParticipations=" . tir_sql_int(tir_int_or_null($stats['participations'] ?? null)) . ",
        DtWins=" . tir_sql_int(tir_int_or_null($stats['wins'] ?? null)) . ",
        DtPodiums=" . tir_sql_int(tir_int_or_null($stats['podiums'] ?? null)) . ",
        DtRankPrev=" . tir_sql_int(tir_int_or_null($stats['rank'] ?? null)));
    return (int)safe_w_last_id();
}

/**
 * A category with the id of the show it belongs to.
 *
 * @param int $catId
 * @return object|null
 */
function tir_category($catId) {
    $q = safe_r_sql("SELECT DcId, DcShow, DcOrder, DcName, DcEvent, DcType FROM DrawCategories WHERE DcId=" . (int)$catId);
    return safe_fetch($q) ?: null;
}

/**
 * A team with the ids of its category and show.
 *
 * @param int $teamId
 * @return object|null
 */
function tir_team($teamId) {
    $q = safe_r_sql("SELECT DrawTeams.*, DcShow, DcEvent, DcType FROM DrawTeams
        INNER JOIN DrawCategories ON DcId=DtCategory WHERE DtId=" . (int)$teamId);
    return safe_fetch($q) ?: null;
}

/**
 * Categories of a show with their teams.
 *
 * @param int $showId
 * @param bool $full Include club codes and notes (not for the public screen).
 * @return array
 */
function tir_categories($showId, $full) {
    $showId = (int)$showId;
    $cats = [];
    $q = safe_r_sql("SELECT DcId, DcName, DcEvent, DcType FROM DrawCategories WHERE DcShow=$showId ORDER BY DcOrder, DcId");
    while ($r = safe_fetch($q)) {
        $cats[(int)$r->DcId] = [
            'id' => (int)$r->DcId, 'name' => $r->DcName, 'event' => $r->DcEvent,
            'type' => tir_clean_type($r->DcType), 'teams' => [],
        ];
    }
    if (!$cats) return [];

    // List order: what numbers the stages. A team list is shown sorted by name anyway.
    $q = safe_r_sql("SELECT DtId, DtCategory, DtName, DtDetail, DtClub, DtPosition, DtDrawn, DtParticipations, DtWins,
            DtPodiums, DtRankPrev, DtNote
        FROM DrawTeams INNER JOIN DrawCategories ON DcId=DtCategory
        WHERE DcShow=$showId ORDER BY DtOrder, DtId");
    while ($r = safe_fetch($q)) {
        $team = [
            'id'       => (int)$r->DtId,
            'name'     => $r->DtName,
            'detail'   => (string)$r->DtDetail,
            'position' => (int)$r->DtPosition,
            'drawn'    => $r->DtDrawn ? (string)$r->DtDrawn : '',
            'stats'    => [
                'participations' => $r->DtParticipations === null ? null : (int)$r->DtParticipations,
                'wins'           => $r->DtWins === null ? null : (int)$r->DtWins,
                'podiums'        => $r->DtPodiums === null ? null : (int)$r->DtPodiums,
                'rank'           => $r->DtRankPrev === null ? null : (int)$r->DtRankPrev,
            ],
        ];
        if ($full) {
            $team['club'] = $r->DtClub;
            $team['note'] = (string)$r->DtNote;
        }
        $cats[(int)$r->DtCategory]['teams'][] = $team;
    }
    return array_values($cats);
}

/**
 * Everything a screen needs to draw itself.
 *
 * @param array $show
 * @param string $role 'display', 'speaker' or 'admin'.
 * @return array
 */
function tir_state($show, $role) {
    $state = [
        'id'           => $show['id'],
        'title'        => $show['title'],
        'subtitle'     => $show['subtitle'],
        'scene'        => $show['scene'],
        'category'     => $show['category'],
        'stats'        => $show['stats'],
        'look'         => $show['look'],
        'fonts'        => tir_fonts(),
        'image'        => $show['hasImage'] ? $show['dataRevision'] : 0,
        'revision'     => $show['revision'],
        'dataRevision' => $show['dataRevision'],
        'categories'   => tir_categories($show['id'], $role !== 'display'),
    ];
    return $state;
}

/**
 * Next free place of a category.
 *
 * @param int $catId
 * @return int
 */
function tir_next_position($catId) {
    $q = safe_r_sql("SELECT COALESCE(MAX(DtPosition), 0) + 1 AS n FROM DrawTeams WHERE DtCategory=" . (int)$catId);
    return (int)safe_fetch($q)->n;
}

/**
 * Give a team the next place of its category, and put its category on air.
 *
 * The DtPosition=0 condition makes a double click harmless: the second update
 * matches nothing.
 *
 * @param object $team Row from tir_team().
 */
function tir_draw_team($team) {
    if ((int)$team->DtPosition > 0) return;
    $cat  = (int)$team->DtCategory;
    $next = tir_next_position($cat);
    safe_w_sql("UPDATE DrawTeams SET DtPosition=$next, DtDrawn=NOW() WHERE DtId=" . (int)$team->DtId . " AND DtPosition=0");
    safe_w_sql("UPDATE DrawShows SET DwCategory=$cat, DwScene='category' WHERE DwId=" . (int)$team->DcShow);
}

/**
 * Withdraw a team's place and close the gap behind it.
 *
 * @param object $team Row from tir_team().
 */
function tir_unassign_team($team) {
    $pos = (int)$team->DtPosition;
    if ($pos <= 0) return;
    $cat = (int)$team->DtCategory;
    safe_w_sql("UPDATE DrawTeams SET DtPosition=0, DtDrawn=NULL WHERE DtId=" . (int)$team->DtId);
    safe_w_sql("UPDATE DrawTeams SET DtPosition=DtPosition-1 WHERE DtCategory=$cat AND DtPosition>$pos");
}

/**
 * Withdraw the last place given in a category.
 *
 * @param int $catId
 */
function tir_undo_last($catId) {
    $catId = (int)$catId;
    safe_w_sql("UPDATE DrawTeams SET DtPosition=0, DtDrawn=NULL
        WHERE DtCategory=$catId AND DtPosition>0 AND DtPosition=(SELECT m FROM (SELECT MAX(DtPosition) AS m FROM DrawTeams WHERE DtCategory=$catId) x)");
}

/**
 * Withdraw every place of a category.
 *
 * @param int $catId
 */
function tir_reset_category($catId) {
    safe_w_sql("UPDATE DrawTeams SET DtPosition=0, DtDrawn=NULL WHERE DtCategory=" . (int)$catId);
}
