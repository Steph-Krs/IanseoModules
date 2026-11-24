<?php
/**
 * Regular archers and their groups.
 *
 * The list belongs to the whole installation, like the formats: every coach
 * sees the same archers. An archer is filled by hand, from the licence file
 * already loaded in ianseo (LookUpEntries), or from the entries of a
 * competition, and only ever copied into a competition at its creation.
 */

/**
 * The regular archers.
 *
 * @param bool $activeOnly
 * @return array ArId => row, with Groups = list of GpId.
 */
function trn_archers($activeOnly = false) {
    $out = [];
    $rs = safe_r_sql("SELECT ArId, ArCode, ArIocCode, ArFamilyName, ArGivenName, ArSex, ArDob, ArClubCode,
            ArClubName, ArDivision, ArClass, ArActive, GROUP_CONCAT(GmGroup) AS GroupList
        FROM TrainingArchers LEFT JOIN TrainingGroupMembers ON GmArcher = ArId
        " . ($activeOnly ? "WHERE ArActive = 1" : "") . "
        GROUP BY ArId
        ORDER BY ArFamilyName, ArGivenName, ArId");
    while ($r = safe_fetch($rs)) {
        $r->Groups = $r->GroupList ? array_map('intval', explode(',', $r->GroupList)) : [];
        $out[intval($r->ArId)] = $r;
    }
    return $out;
}

/**
 * Clean the fields of an archer form.
 *
 * @param array $in Raw values.
 * @return array Column => value.
 */
function trn_archer_clean($in) {
    $dob = trim((string)($in['dob'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !strtotime($dob)) $dob = null;
    return [
        'ArCode'       => mb_substr(trim((string)($in['code'] ?? '')), 0, 25),
        'ArIocCode'    => mb_strtoupper(mb_substr(trim((string)($in['ioc'] ?? '')), 0, 5)),
        'ArFamilyName' => mb_strtoupper(mb_substr(trim((string)($in['family'] ?? '')), 0, 60)),
        'ArGivenName'  => mb_substr(trim((string)($in['given'] ?? '')), 0, 30),
        'ArSex'        => intval($in['sex'] ?? 0) ? 1 : 0,
        'ArDob'        => $dob,
        'ArClubCode'   => mb_strtoupper(mb_substr(trim((string)($in['club'] ?? '')), 0, 10)),
        'ArClubName'   => mb_substr(trim((string)($in['clubname'] ?? '')), 0, 80),
        'ArDivision'   => trim((string)($in['div'] ?? '')) !== '' ? mb_substr(trim((string)$in['div']), 0, 4) : TRN_DEFAULT_DIVISION,
        'ArClass'      => mb_substr(trim((string)($in['cls'] ?? '')), 0, 6),
        'ArActive'     => !isset($in['active']) || intval($in['active']) ? 1 : 0,
    ];
}

/**
 * Add or update a regular archer.
 *
 * @param array $data Columns from trn_archer_clean().
 * @param int $id 0 for a new archer.
 * @return int The archer.
 */
function trn_archer_save($data, $id = 0) {
    $set = [];
    foreach ($data as $col => $val) {
        $set[] = $col . ' = ' . (is_null($val) ? 'NULL' : StrSafe_DB($val));
    }
    $id = intval($id);
    if ($id) {
        safe_w_sql("UPDATE TrainingArchers SET " . implode(', ', $set) . " WHERE ArId = $id");
        return $id;
    }
    safe_w_sql("INSERT INTO TrainingArchers SET " . implode(', ', $set) . ", ArCreated = NOW()");
    return intval(safe_w_last_id());
}

/**
 * Delete a regular archer and their group memberships.
 *
 * @param int $id
 */
function trn_archer_delete($id) {
    $id = intval($id);
    safe_w_sql("DELETE FROM TrainingGroupMembers WHERE GmArcher = $id");
    safe_w_sql("DELETE FROM TrainingArchers WHERE ArId = $id");
}

/**
 * Put an archer in exactly the given groups.
 *
 * @param int $id
 * @param int[] $groups
 */
function trn_archer_set_groups($id, $groups) {
    $id = intval($id);
    safe_w_sql("DELETE FROM TrainingGroupMembers WHERE GmArcher = $id");
    $valid = trn_groups();
    foreach (array_unique(array_map('intval', (array)$groups)) as $g) {
        if (isset($valid[$g])) safe_w_sql("INSERT IGNORE INTO TrainingGroupMembers SET GmGroup = $g, GmArcher = $id");
    }
}

/**
 * The groups.
 *
 * @return array GpId => name.
 */
function trn_groups() {
    $out = [];
    $rs = safe_r_sql("SELECT GpId, GpName FROM TrainingGroups ORDER BY GpName, GpId");
    while ($r = safe_fetch($rs)) $out[intval($r->GpId)] = (string)$r->GpName;
    return $out;
}

/**
 * Add or rename a group.
 *
 * @param string $name
 * @param int $id 0 for a new group.
 */
function trn_group_save($name, $id = 0) {
    $name = mb_substr(trim((string)$name), 0, 64);
    if ($name === '') return;
    $id = intval($id);
    if ($id) {
        safe_w_sql("UPDATE TrainingGroups SET GpName = " . StrSafe_DB($name) . " WHERE GpId = $id");
    } else {
        safe_w_sql("INSERT INTO TrainingGroups SET GpName = " . StrSafe_DB($name));
    }
}

/**
 * Delete a group; its archers stay.
 *
 * @param int $id
 */
function trn_group_delete($id) {
    $id = intval($id);
    safe_w_sql("DELETE FROM TrainingGroupMembers WHERE GmGroup = $id");
    safe_w_sql("DELETE FROM TrainingGroups WHERE GpId = $id");
}

/**
 * Search the licence file loaded in ianseo.
 *
 * @param string $q Licence number, or the start of a family name, optionally
 *                  followed by the start of a given name.
 * @return array Up to 20 rows ready for the archer form.
 */
function trn_lookup_search($q) {
    $q = trim((string)$q);
    // bytes: the length only rules out a query too short to search, any character counts.
    if (mb_strlen($q) < 2) return [];
    $words = preg_split('/\s+/', $q);
    $where = "LueCode = " . StrSafe_DB($q)
        . " OR LueFamilyName LIKE " . StrSafe_DB($words[0] . '%')
        . (count($words) > 1 ? " AND LueName LIKE " . StrSafe_DB($words[1] . '%') : '');
    $out = [];
    $rs = safe_r_sql("SELECT LueCode, LueIocCode, LueFamilyName, LueName, LueSex, LueCtrlCode, LueCountry,
            LueCoDescr, LueDivision, LueClass
        FROM LookUpEntries WHERE $where
        ORDER BY LueFamilyName, LueName LIMIT 40");
    $seen = [];
    while ($r = safe_fetch($rs)) {
        $key = $r->LueIocCode . '|' . $r->LueCode;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = [
            'code' => (string)$r->LueCode, 'ioc' => (string)$r->LueIocCode,
            'family' => (string)$r->LueFamilyName, 'given' => (string)$r->LueName,
            'sex' => intval($r->LueSex), 'dob' => trn_is_date($r->LueCtrlCode) ? (string)$r->LueCtrlCode : '',
            'club' => (string)$r->LueCountry, 'clubname' => (string)$r->LueCoDescr,
            'div' => (string)$r->LueDivision, 'cls' => (string)$r->LueClass,
        ];
        if (count($out) >= 20) break;
    }
    return $out;
}

/**
 * Archers of a competition, for copying into the regular list.
 *
 * @param int $tourId
 * @return array EnId => row.
 */
function trn_competition_archers($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT EnId, EnCode, EnIocCode, EnFirstName, EnName, EnSex, EnDob, EnDivision, EnClass,
            CoCode, CoName, CoNameComplete
        FROM Entries LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnTournament = " . intval($tourId) . " AND EnAthlete = 1 AND COALESCE(CoCode, '') <> '" . TRN_GHOST_CLUB . "'
        ORDER BY EnFirstName, EnName");
    while ($r = safe_fetch($rs)) $out[intval($r->EnId)] = $r;
    return $out;
}

/**
 * Copy archers of a competition into the regular list, skipping a licence
 * number already there.
 *
 * @param int $tourId
 * @param int[] $enIds
 * @return int Archers added.
 */
function trn_import_from_competition($tourId, $enIds) {
    $known = [];
    foreach (trn_archers() as $a) {
        if ($a->ArCode !== '') $known[$a->ArCode] = true;
    }
    $added = 0;
    $all = trn_competition_archers($tourId);
    foreach (array_map('intval', (array)$enIds) as $id) {
        if (!isset($all[$id])) continue;
        $e = $all[$id];
        if ($e->EnCode !== '' && isset($known[$e->EnCode])) continue;
        trn_archer_save(trn_archer_clean([
            'code' => $e->EnCode, 'ioc' => $e->EnIocCode, 'family' => $e->EnFirstName, 'given' => $e->EnName,
            'sex' => $e->EnSex, 'dob' => trn_is_date($e->EnDob) ? $e->EnDob : '',
            'club' => $e->CoCode, 'clubname' => $e->CoNameComplete ?: $e->CoName,
            'div' => $e->EnDivision, 'cls' => $e->EnClass,
        ]));
        if ($e->EnCode !== '') $known[$e->EnCode] = true;
        $added++;
    }
    return $added;
}
