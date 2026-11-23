<?php
/**
 * Teams of a training, composed by the coach instead of being built by club.
 *
 * ianseo builds the teams of a team event from a "country" of each archer,
 * chosen by the event's EvTeamCreationMode (ifSqlForCountry(): 1 = EnCountry,
 * 2 = EnCountry2, 3 = EnCountry3). A training keeps the archer's club in
 * EnCountry, so that the individual results still show it, and gives each
 * composed team its own row of Countries:
 *   - team events read EnCountry2 (mode 2), teams coded TRNE01, TRNE02…;
 *   - mixed team events read EnCountry3 (mode 3), teams coded TRNM01…
 * An archer can thus be in one team and in a different mixed team. An archer
 * says yes to the team events (EnTeamFEvent, EnTeamMixEvent) when the coach
 * puts them in a team, at the creation or at any time afterwards, and no
 * longer when taken out. Nobody is in the division/class team ranking
 * (EnTeamClEvent): it reads the club when country 2 is empty, and would group
 * the others by club.
 */

const TRN_TEAM_PREFIX = ['team' => 'TRNE', 'mixed' => 'TRNM'];

/**
 * The composed teams of a competition.
 *
 * @param int $tourId
 * @return array ['team' => [CoId => ['name', 'members' => [EnId…]]], 'mixed' => …]
 */
function trn_teams($tourId) {
    $tourId = intval($tourId);
    $out = ['team' => [], 'mixed' => []];
    $rs = safe_r_sql("SELECT CoId, CoCode, CoName FROM Countries
        WHERE CoTournament = $tourId AND (CoCode LIKE 'TRNE%' OR CoCode LIKE 'TRNM%')
        ORDER BY CoCode");
    while ($r = safe_fetch($rs)) {
        $kind = strncmp($r->CoCode, TRN_TEAM_PREFIX['mixed'], 4) === 0 ? 'mixed' : 'team';
        $out[$kind][intval($r->CoId)] = ['name' => (string)$r->CoName, 'members' => []];
    }
    $rs = safe_r_sql("SELECT EnId, EnCountry2, EnCountry3 FROM Entries WHERE EnTournament = $tourId ORDER BY EnFirstName, EnName");
    while ($r = safe_fetch($rs)) {
        if (isset($out['team'][intval($r->EnCountry2)])) $out['team'][intval($r->EnCountry2)]['members'][] = intval($r->EnId);
        if (isset($out['mixed'][intval($r->EnCountry3)])) $out['mixed'][intval($r->EnCountry3)]['members'][] = intval($r->EnId);
    }
    return $out;
}

/**
 * Which kinds of team event the competition has.
 *
 * @param int $tourId
 * @return array ['team' => bool, 'mixed' => bool]
 */
function trn_team_kinds($tourId) {
    $out = ['team' => false, 'mixed' => false];
    $rs = safe_r_sql("SELECT DISTINCT EvMixedTeam FROM Events
        WHERE EvTournament = " . intval($tourId) . " AND EvTeamEvent = 1 AND EvCodeParent = ''");
    while ($r = safe_fetch($rs)) $out[intval($r->EvMixedTeam) ? 'mixed' : 'team'] = true;
    return $out;
}

/**
 * Replace the composed teams of the open competition.
 *
 * @param int $tourId Must be the open competition.
 * @param array $posted ['team' => [['id' => CoId|0, 'name' => …, 'members' => [EnId…]], …], 'mixed' => …]
 */
function trn_teams_save($tourId, $posted) {
    $tourId = intval($tourId);
    $valid = [];
    $rs = safe_r_sql("SELECT EnId FROM Entries WHERE EnTournament = $tourId");
    while ($r = safe_fetch($rs)) $valid[intval($r->EnId)] = true;
    $current = trn_teams($tourId);

    safe_w_sql("UPDATE Entries SET EnCountry2 = 0, EnCountry3 = 0, EnTeamClEvent = 0, EnTeamFEvent = 0, EnTeamMixEvent = 0
        WHERE EnTournament = $tourId");

    foreach (['team', 'mixed'] as $kind) {
        $col = $kind === 'team' ? 'EnCountry2' : 'EnCountry3';
        $flag = $kind === 'team' ? 'EnTeamFEvent' : 'EnTeamMixEvent';
        $kept = [];
        $n = 0;
        $codes = [];
        $rs = safe_r_sql("SELECT CoCode FROM Countries WHERE CoTournament = $tourId AND CoCode LIKE '" . TRN_TEAM_PREFIX[$kind] . "%'");
        while ($r = safe_fetch($rs)) $codes[(string)$r->CoCode] = true;
        foreach ((array)($posted[$kind] ?? []) as $t) {
            $members = array_values(array_filter(array_unique(array_map('intval', (array)($t['members'] ?? []))),
                function ($id) use ($valid) { return isset($valid[$id]); }));
            $n++;
            $name = mb_substr(trim((string)($t['name'] ?? '')), 0, 30);
            if ($name === '') $name = trn_text($kind === 'team' ? 'TeamN' : 'MixedN', $n);
            $id = intval($t['id'] ?? 0);
            if ($id && isset($current[$kind][$id])) {
                safe_w_sql("UPDATE Countries SET CoName = " . StrSafe_DB($name)
                    . ", CoNameComplete = " . StrSafe_DB($name) . " WHERE CoId = $id AND CoTournament = $tourId");
            } else {
                // A code never reused within the competition: an existing team keeps its own.
                for ($k = 1; isset($codes[$code = TRN_TEAM_PREFIX[$kind] . sprintf('%02d', $k)]); $k++);
                $codes[$code] = true;
                safe_w_sql("INSERT INTO Countries SET CoTournament = $tourId, CoCode = " . StrSafe_DB($code)
                    . ", CoName = " . StrSafe_DB($name) . ", CoNameComplete = " . StrSafe_DB($name));
                $id = intval(safe_w_last_id());
            }
            $kept[$id] = true;
            if ($members) {
                safe_w_sql("UPDATE Entries SET $col = $id, $flag = 1
                    WHERE EnTournament = $tourId AND EnId IN (" . implode(',', $members) . ")");
            }
        }
        foreach (array_keys($current[$kind]) as $id) {
            if (!isset($kept[$id])) safe_w_sql("DELETE FROM Countries WHERE CoId = $id AND CoTournament = $tourId");
        }
    }

    safe_w_sql("UPDATE Events SET EvTeamCreationMode = IF(EvMixedTeam = 1, 3, 2)
        WHERE EvTournament = $tourId AND EvTeamEvent = 1");
    trn_recalc($tourId);
}

/**
 * Read the teams from the posted form fields team_name[kind][i], team_id[kind][i]
 * and team_members[kind][i] (comma-separated EnIds or archer row keys).
 *
 * @param array $post
 * @param array $map Optional row key => EnId, for the creation form.
 * @return array As expected by trn_teams_save().
 */
function trn_teams_from_post($post, $map = null) {
    $out = ['team' => [], 'mixed' => []];
    foreach (['team', 'mixed'] as $kind) {
        foreach ((array)($post['team_name'][$kind] ?? []) as $i => $name) {
            $members = [];
            foreach (explode(',', (string)($post['team_members'][$kind][$i] ?? '')) as $m) {
                $m = trim($m);
                if ($m === '') continue;
                if ($map !== null) {
                    if (isset($map[$m])) $members[] = $map[$m];
                } else {
                    $members[] = intval($m);
                }
            }
            $out[$kind][] = ['id' => intval($post['team_id'][$kind][$i] ?? 0), 'name' => (string)$name, 'members' => $members];
        }
    }
    return $out;
}

/**
 * The team editor: one block per kind, filled and run by assets/training.js.
 *
 * @param array $kinds From trn_team_kinds() or a format summary.
 * @param array $teams From trn_teams(), or empty lists.
 * @param array $people Key => label of the archers who can be chosen.
 * @return string
 */
function trn_teams_editor($kinds, $teams, $people) {
    $out = '';
    foreach (['team', 'mixed'] as $kind) {
        if (empty($kinds[$kind])) continue;
        $data = [];
        foreach ($teams[$kind] ?? [] as $id => $t) {
            $data[] = ['id' => $id, 'name' => $t['name'], 'members' => array_map('strval', $t['members'])];
        }
        $out .= '<div class="trn-teams" data-kind="' . $kind . '" data-teams="' . trn_esc(json_encode($data)) . '">'
            . '<h3>' . trn_t($kind === 'team' ? 'TeamsTitle' : 'MixedTitle') . '</h3>'
            . '<div class="trn-team-list"></div>'
            . '<button type="button" class="trn-btn trn-team-add">' . trn_t($kind === 'team' ? 'TeamAdd' : 'MixedAdd') . '</button>'
            . '</div>';
    }
    if ($out !== '') {
        $out = '<script type="application/json" id="trn-people">' . json_encode($people, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . $out;
    }
    return $out;
}
