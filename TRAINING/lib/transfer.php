<?php
/**
 * Moving the configuration of the module from one installation to another:
 * reference formats, regular archers and groups, in one JSON file.
 *
 * The person who prepares the formats on one server hands them to another
 * training centre. Nothing in the file is trusted on import: each format
 * snapshot is unpacked without objects, stripped again and summarised again
 * here, and a snapshot written by a more recent ianseo than this one is
 * refused, since the core's import would refuse it at creation anyway.
 */

const TRN_TRANSFER_KIND = 'ianseo-training';
const TRN_TRANSFER_VERSION = 1;

/**
 * Build the export.
 *
 * @param int[] $formats Formats to include.
 * @param bool $archers Include the regular archers and the groups.
 * @return array Ready for json_encode().
 */
function trn_export($formats, $archers) {
    $out = ['kind' => TRN_TRANSFER_KIND, 'version' => TRN_TRANSFER_VERSION, 'exported' => date('Y-m-d H:i:s'),
            'formats' => [], 'groups' => [], 'archers' => []];
    $ids = array_filter(array_map('intval', (array)$formats));
    if ($ids) {
        $rs = safe_r_sql("SELECT TmName, TmSimple, TmSource, TmData FROM TrainingTemplates
            WHERE TmId IN (" . implode(',', $ids) . ") ORDER BY TmName");
        while ($r = safe_fetch($rs)) {
            $out['formats'][] = ['name' => (string)$r->TmName, 'simple' => intval($r->TmSimple), 'source' => (string)$r->TmSource,
                                 'data' => base64_encode((string)$r->TmData)];
        }
    }
    if ($archers) {
        $groups = trn_groups();
        $out['groups'] = array_values($groups);
        foreach (trn_archers() as $a) {
            $gn = [];
            foreach ($a->Groups as $g) if (isset($groups[$g])) $gn[] = $groups[$g];
            $out['archers'][] = [
                'code' => $a->ArCode, 'ioc' => $a->ArIocCode, 'family' => $a->ArFamilyName, 'given' => $a->ArGivenName,
                'sex' => intval($a->ArSex), 'dob' => trn_is_date($a->ArDob) ? $a->ArDob : '', 'club' => $a->ArClubCode,
                'clubname' => $a->ArClubName, 'div' => $a->ArDivision, 'cls' => $a->ArClass, 'active' => intval($a->ArActive),
                'groups' => $gn,
            ];
        }
    }
    return $out;
}

/**
 * Read an export.
 *
 * @param array $data Decoded JSON.
 * @param bool $replace A format with the same name as an imported one is replaced instead of kept beside it.
 * @return array ['formats' => N, 'replaced' => N, 'archers' => N, 'updated' => N, 'groups' => N, 'refused' => [names]]
 */
function trn_import($data, $replace) {
    $res = ['formats' => 0, 'replaced' => 0, 'archers' => 0, 'updated' => 0, 'groups' => 0, 'refused' => []];
    $dbVersion = (string)GetParameter('DBUpdate');

    $byName = [];
    foreach (trn_tpl_list() as $f) $byName[mb_strtolower($f->TmName)] = intval($f->TmId);
    foreach ((array)($data['formats'] ?? []) as $f) {
        $name = mb_substr(trim((string)($f['name'] ?? '')), 0, 100);
        $blob = base64_decode((string)($f['data'] ?? ''), true);
        $gara = $blob !== false ? @unserialize(@gzuncompress($blob), ['allowed_classes' => false]) : false;
        if ($name === '' || !is_array($gara) || empty($gara['Tournament']) || !is_array($gara['Tournament'])) {
            $res['refused'][] = $name !== '' ? $name : '?';
            continue;
        }
        if ((string)($gara['Tournament']['ToDbVersion'] ?? '') > $dbVersion) {
            $res['refused'][] = $name;
            continue;
        }
        trn_tpl_strip($gara);
        $set = "TmName = " . StrSafe_DB($name)
            . ", TmSimple = " . (intval($f['simple'] ?? 1) ? 1 : 0)
            . ", TmSource = " . StrSafe_DB(mb_substr((string)($f['source'] ?? ''), 0, 8))
            . ", TmRefStart = " . StrSafe_DB(trn_tpl_ref_start($gara))
            . ", TmSummary = " . StrSafe_DB(json_encode(trn_tpl_summary($gara)))
            . ", TmData = " . StrSafe_DB(gzcompress(serialize($gara), 9))
            . ", TmUpdated = NOW()";
        $key = mb_strtolower($name);
        if ($replace && isset($byName[$key])) {
            safe_w_sql("UPDATE TrainingTemplates SET $set WHERE TmId = " . $byName[$key]);
            $res['replaced']++;
        } else {
            safe_w_sql("INSERT INTO TrainingTemplates SET $set, TmCreated = NOW()");
            $byName[$key] = intval(safe_w_last_id());
            $res['formats']++;
        }
    }

    $groups = array_flip(array_map('mb_strtolower', trn_groups()));
    $groupId = function ($name) use (&$groups, &$res) {
        $name = mb_substr(trim((string)$name), 0, 64);
        if ($name === '') return 0;
        $key = mb_strtolower($name);
        if (!isset($groups[$key])) {
            safe_w_sql("INSERT INTO TrainingGroups SET GpName = " . StrSafe_DB($name));
            $groups[$key] = intval(safe_w_last_id());
            $res['groups']++;
        }
        return $groups[$key];
    };
    foreach ((array)($data['groups'] ?? []) as $g) $groupId($g);

    // An archer already here is recognised by the licence, otherwise by name and date of birth.
    $known = [];
    foreach (trn_archers() as $a) {
        if ($a->ArCode !== '') $known['c:' . $a->ArCode] = intval($a->ArId);
        $known['n:' . mb_strtoupper($a->ArFamilyName . '|' . $a->ArGivenName . '|' . $a->ArDob)] = intval($a->ArId);
    }
    foreach ((array)($data['archers'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $clean = trn_archer_clean($a);
        if ($clean['ArFamilyName'] === '') continue;
        $id = ($clean['ArCode'] !== '' ? ($known['c:' . $clean['ArCode']] ?? 0) : 0)
            ?: ($known['n:' . mb_strtoupper($clean['ArFamilyName'] . '|' . $clean['ArGivenName'] . '|' . $clean['ArDob'])] ?? 0);
        $new = trn_archer_save($clean, $id);
        if ($id) $res['updated']++; else $res['archers']++;
        $gids = [];
        foreach ((array)($a['groups'] ?? []) as $g) {
            $gid = $groupId($g);
            if ($gid) $gids[] = $gid;
        }
        trn_archer_set_groups($new, $gids);
        if ($clean['ArCode'] !== '') $known['c:' . $clean['ArCode']] = $new;
    }
    return $res;
}
