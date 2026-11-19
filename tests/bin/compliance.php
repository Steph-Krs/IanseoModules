#!/usr/bin/env php
<?php
/**
 * ianseo module compliance checker.
 *
 * Static analysis of a custom module against the rules the ianseo team asks of
 * externally contributed modules, plus the safety rules this codebase learned
 * the hard way. Run it before publishing any new version, from the repository:
 *
 *     php tests/bin/compliance.php GUIDE
 *     php tests/bin/compliance.php --all
 *     php tests/bin/compliance.php GUIDE --inventory       print the database-write inventory
 *     php tests/bin/compliance.php GUIDE --fix-inventory   rewrite it into the module's .md files
 *     php tests/bin/compliance.php --all --baseline=tests/compliance-baseline.json
 *                                  fail only on errors the baseline does not know
 *     php tests/bin/compliance.php --all --write-baseline=tests/compliance-baseline.json
 *
 * Exit status is 0 when nothing failed, 1 when at least one ERROR was reported
 * (with --baseline: at least one ERROR beyond the baseline), so it can gate a release.
 *
 * WHY IT LIVES IN tests/ AND NOT IN THE MODULES
 * It is the bench, not the product. No running installation has any use for a
 * checker: it answers a question asked once, before publishing, and tests/ is in no
 * module's files[], so it is never downloaded onto a server. Kept here it is a
 * single copy serving every module with the same rigour, run by GitHub on every
 * pull request, and free to grow as the ianseo team sends more remarks. Its
 * findings also reach the public documentation, because --fix-inventory writes the
 * database-write inventory into each module's README.
 *
 * THE BASELINE
 * Modules written before a rule existed carry known debt (untranslated text, byte
 * string functions…). tests/compliance-baseline.json counts the ERRORs already
 * there, per module, check and file; with --baseline only what goes beyond those
 * counts fails, so new code meets every rule while old debt is paid down module by
 * module. Paying some of it down: rewrite the baseline in the same commit.
 *
 * DELIBERATELY STANDALONE
 * The script never loads config.php. ianseo's config.php aborts when run from
 * the command line, and requiring a working database would make the check
 * impossible to run in CI or on a checkout. Everything below is derived from the
 * files themselves; the reference lists of core tables and column prefixes are
 * embedded and can be regenerated from any ianseo database with the queries
 * quoted next to them.
 *
 * WHAT IT CANNOT DO
 * A static checker sees text, not behaviour. It flags statements that look
 * unbounded and reports every write for human review; it does not prove a query
 * is safe. The fresh-install test on a throwaway database is still required, and
 * so is reading the diff.
 */

// ---------------------------------------------------------------------------
// Reference data
// ---------------------------------------------------------------------------

/**
 * Every table shipped by the ianseo core, spelled exactly as the core spells it.
 *
 * This is the authority for the "table names must match the code exactly" rule:
 * on Linux MySQL defaults to lower_case_table_names=0, where FROM entries and
 * FROM Entries are two different tables. Windows installs usually run with 1 or
 * 2 and compare case-insensitively, which is precisely why the mistake survives
 * development and only surfaces after publication.
 *
 * Regenerate with:
 *   SELECT TABLE_NAME FROM information_schema.TABLES
 *   WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
 */
const CMOD_CORE_TABLES = [
    'AccColors', 'AccEntries', 'AccOperationType', 'AccPrice', 'ACL', 'AclDetails',
    'AclFeatures', 'AclTemplates', 'AclTournaments', 'AclUserFeatures', 'AclUsers',
    'AvailableTarget', 'Awarded', 'Awards', 'BackNumber', 'BoinxSchedule', 'CasGrid',
    'CasGroupMatch', 'CasRankMatch', 'CasScore', 'CasTeam', 'CasTeamFinal',
    'CasTeamTarget', 'Classes', 'ClassWaEquivalents', 'ClubTeam', 'ClubTeamGrid',
    'ClubTeamGroupMatch', 'ClubTeamRankMatch', 'ClubTeamScore', 'Countries',
    'CountryLevels', 'DistanceInformation', 'Divisions', 'DocumentVersions',
    'ElabQualifications', 'Eliminations', 'Emails', 'Entries', 'EventClass', 'Events',
    'ExtraData', 'ExtraDataCountries', 'FinalReportA', 'FinalReportQ', 'Finals',
    'FinOdfTiming', 'FinSchedule', 'FinWarmup', 'Flags', 'GateLog', 'Grids',
    'HeartBeat', 'HhtData', 'HhtEvents', 'HhtSetup', 'HTTData', 'IdCardElements',
    'IdCards', 'Images', 'Individuals', 'IndOldPositions', 'InfoSystem',
    'InvolvedType', 'IrmTypes', 'IskData', 'IskDevices', 'Logs', 'LookUpEntries',
    'LookUpPaths', 'ModulesParameters', 'OdfDocuments', 'OdfMessageStatus',
    'OdfTranslations', 'OnLineIds', 'Parameters', 'Phases', 'Photos',
    'Qualifications', 'QualOldPositions', 'Rankings', 'RecAreas', 'RecBroken',
    'RecTargetFaces', 'RecTournament', 'Reviews', 'RoundRobinGrids',
    'RoundRobinGroup', 'RoundRobinLevel', 'RoundRobinMatches',
    'RoundRobinParticipants', 'RunArchery', 'RunArcheryParticipants',
    'RunArcheryRank', 'Scheduler', 'Session', 'SubClass', 'TargetFaces',
    'TargetGroups', 'Targets', 'TeamComponent', 'TeamDavis', 'TeamEligibleComponent',
    'TeamFinals', 'TeamFinComponent', 'TeamFinComponentLog', 'TeamFinComponentStats',
    'TeamQualificationDistances', 'Teams', 'Tournament', 'TournamentDistances',
    'TournamentInvolved', 'TourRecords', 'TourTypes', 'TVContents', 'TVOut',
    'TVParams', 'TVRules', 'TVSequence', 'Vegas', 'VegasAwards',
];

/**
 * Two-letter column prefixes already used by core tables.
 *
 * ianseo prefixes every column with a short abbreviation of its table (ToId,
 * EnCode, RrMatchTarget), which is what lets a reader of a multi-table join tell
 * where each column comes from. A module that reuses a core prefix destroys that
 * property exactly where it matters most, since module tables are almost always
 * joined to core ones.
 *
 * Regenerate with:
 *   SELECT DISTINCT LEFT(COLUMN_NAME, 2) FROM information_schema.COLUMNS
 *   WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME REGEXP '^[A-Za-z][A-Za-z]';
 */
const CMOD_CORE_COL_PREFIXES = [
    'Ac', 'AE', 'AO', 'AP', 'At', 'Aw', 'Bn', 'Bs', 'Ca', 'CG', 'Cl', 'Co', 'CR',
    'CT', 'Di', 'Dv', 'Ec', 'Ed', 'El', 'Em', 'En', 'Eq', 'Ev', 'Fi', 'Fl', 'Fr',
    'FS', 'Fw', 'GL', 'Gr', 'Hb', 'Hd', 'He', 'Hs', 'Ht', 'Ic', 'Im', 'In', 'Io',
    'Ir', 'Is', 'It', 'Lo', 'Lu', 'Mp', 'Od', 'Ol', 'Om', 'Pa', 'Ph', 'Qo', 'Qu',
    'Ra', 'Re', 'Rr', 'Rt', 'Sc', 'Se', 'Ta', 'Tc', 'Td', 'Te', 'Tf', 'Tg', 'Ti',
    'To', 'Tq', 'Tr', 'Tt', 'TV', 'Va', 'Ve',
];

/**
 * Languages every publishable module must ship.
 *
 * English is the fallback ianseo itself loads when a key is missing from the
 * user's language, so it is the one that may never have a hole in it.
 */
const CMOD_LANGUAGES = ['en', 'fr', 'it', 'de', 'es'];

/**
 * Column names that scope a statement to one competition.
 *
 * Used only as a hint when judging whether a write to a core table is bounded.
 * Its absence is reported for review, never treated as proof of a bug.
 */
const CMOD_SCOPE_COLUMNS = [
    'EnTournament', 'ToId', 'EvTournament', 'TourId', 'RrLevEvent', 'RrPartTournament',
    'AtTournament', 'SesTournament', 'ScTournament', 'TiTournament', 'EcTournament',
    'FsTournament', 'ImTournament',
];

/**
 * SQL statement heads that write.
 *
 * TRUNCATE, DROP and ALTER are in the list because a module has no business
 * issuing them against a core table, and the checker should say so loudly.
 */
const CMOD_WRITE_OPS = [
    'INSERT INTO', 'INSERT IGNORE INTO', 'REPLACE INTO', 'UPDATE', 'DELETE FROM',
    'TRUNCATE', 'TRUNCATE TABLE', 'DROP TABLE', 'ALTER TABLE', 'RENAME TABLE',
    // The IGNORE forms write exactly as much as the plain ones. Without them the
    // head "UPDATE" still matched but captured IGNORE as the table name, and a
    // table nobody recognises is dropped silently — so an UPDATE IGNORE was a
    // write that never reached the inventory. Found by writing one (GUIDE,
    // 2026-09-08). They sit before the short forms once the list is sorted
    // longest-first, which is what makes them win.
    'UPDATE IGNORE', 'DELETE IGNORE FROM',
];

/**
 * Words that can follow a write head but are never a table name.
 *
 * Capturing one of these means the statement was not understood, which is worse
 * than not matching at all: the write silently leaves the inventory. Reported so
 * that a form the parser does not know becomes visible instead of invisible.
 */
const CMOD_NOT_A_TABLE = ['IGNORE', 'LOW_PRIORITY', 'HIGH_PRIORITY', 'QUICK', 'DELAYED', 'INTO', 'FROM'];

// ---------------------------------------------------------------------------
// Report plumbing
// ---------------------------------------------------------------------------

$GLOBALS['cmod_report'] = [];

/**
 * Record one finding.
 *
 * @param string $level 'ERROR', 'WARN' or 'INFO'.
 * @param string $check Short name of the rule being checked.
 * @param string $message What was found.
 * @param string $where Optional "file:line".
 */
function cmod_say($level, $check, $message, $where = '') {
    $GLOBALS['cmod_report'][] = [
        'level' => $level, 'check' => $check, 'message' => $message, 'where' => $where,
    ];
}

function cmod_error($check, $message, $where = '') { cmod_say('ERROR', $check, $message, $where); }
function cmod_warn($check, $message, $where = '')  { cmod_say('WARN',  $check, $message, $where); }
function cmod_info($check, $message, $where = '')  { cmod_say('INFO',  $check, $message, $where); }

// ---------------------------------------------------------------------------
// Source scanning
// ---------------------------------------------------------------------------

/**
 * List the module's own source files, skipping data and third-party folders.
 *
 * @param string $dir Module root.
 * @param array $extensions Extensions to keep, without the dot.
 * @return array Absolute paths.
 */
function cmod_source_files($dir, $extensions = ['php']) {
    $skip = ['content', 'data', 'vendor', 'node_modules', '.git', 'dist'];
    $out  = [];

    // This file is excluded from its own analysis. It is a command-line tool
    // that never runs inside ianseo, and it necessarily holds SQL keywords and
    // table names as reference DATA — which any tool scanning for SQL reads as
    // statements. Checking it would report its dictionary, not its code.
    $self = realpath(__FILE__);

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            function ($file) use ($skip) {
                return !($file->isDir() && in_array($file->getFilename(), $skip, true));
            }
        )
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (!in_array(strtolower($f->getExtension()), $extensions, true)) continue;
        if ($self !== false && realpath($f->getPathname()) === $self) continue;
        $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

/**
 * Split a PHP file into its string literals and its comments.
 *
 * Uses the PHP tokeniser rather than regular expressions, so a table name
 * mentioned in a comment is never mistaken for a query, and an apostrophe inside
 * a French comment never swallows the rest of the file.
 *
 * @param string $file Absolute path.
 * @return array{strings: array, comments: array} Each entry is ['text' => …, 'line' => …].
 */
function cmod_tokenise($file) {
    $src = file_get_contents($file);
    $out = ['strings' => [], 'comments' => [], 'calls' => []];

    // A function call is a T_STRING followed by "(" — and NOT preceded by
    // "->", "::" or "function", which would make it a method, a class constant
    // or a declaration rather than a call to the global function of that name.
    $tokens = array_values(array_filter(token_get_all($src), function ($t) {
        return !is_array($t) || ($t[0] !== T_WHITESPACE);
    }));
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING) continue;
        if (($tokens[$i + 1] ?? null) !== '(') continue;

        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) continue;
        if (is_string($prev) && $prev === '$') continue;

        $out['calls'][] = ['name' => $t[1], 'line' => $t[2]];
    }

    foreach (token_get_all($src) as $t) {
        if (!is_array($t)) continue;
        [$id, $text, $line] = $t;

        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            $out['comments'][] = ['text' => $text, 'line' => $line];
        // Heredoc bodies arrive as T_ENCAPSED_AND_WHITESPACE, so they are covered
        // without naming a heredoc token of their own.
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE
               || $id === T_INLINE_HTML) {
            // The kind is kept because markup is a different thing from a string
            // literal: SQL can only hide in the latter, and prose in a page is
            // read through cmod_markup_parts() instead, which knows a class name
            // from a sentence.
            $out['strings'][] = [
                'text' => $text,
                'line' => $line,
                'kind' => $id === T_INLINE_HTML ? 'markup' : 'literal',
            ];
        }
    }
    return $out;
}

/**
 * Every table name a statement head points at, across the module's PHP.
 *
 * Only string literals are scanned, so prose is excluded. Statements built by
 * concatenation are still caught, because the table name almost always sits in
 * the same literal as the keyword that introduces it.
 *
 * @param array $files Absolute paths.
 * @return array List of ['op', 'table', 'file', 'line', 'sql'].
 */
function cmod_statements($files) {
    $heads = array_merge(CMOD_WRITE_OPS, ['FROM', 'JOIN', 'CREATE TABLE', 'CREATE TABLE IF NOT EXISTS']);

    // Longest first. Regex alternation is first-match, not longest-match, so
    // with "CREATE TABLE" ahead of "CREATE TABLE IF NOT EXISTS" the engine stops
    // at the short one and captures IF as the table name. Same trap for
    // TRUNCATE / TRUNCATE TABLE.
    usort($heads, function ($a, $b) { return strlen($b) - strlen($a); });

    $ops = implode('|', array_map(function ($o) {
        return str_replace(' ', '\\s+', preg_quote($o, '/'));
    }, $heads));

    $out = [];
    foreach ($files as $file) {
        foreach (cmod_tokenise($file)['strings'] as $s) {
            if (!preg_match_all(
                '/\b(' . $ops . ')\s+`?([A-Za-z][A-Za-z0-9_]*)`?/i',
                $s['text'], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) continue;

            foreach ($m as $hit) {
                $op    = strtoupper(preg_replace('/\s+/', ' ', $hit[1][0]));
                $table = $hit[2][0];
                $line  = $s['line'] + substr_count(substr($s['text'], 0, $hit[0][1]), "\n");

                // A modifier where a table name should be means this statement
                // was not understood. Say so: an unparsed write disappears from
                // the inventory instead of being reported.
                if (in_array(strtoupper($table), CMOD_NOT_A_TABLE, true)) {
                    // No module root here, so name the file from the module
                    // folder down, which is what every other finding shows.
                    $rel = preg_replace('~^.*[/\\\\]Custom[/\\\\][^/\\\\]+[/\\\\]~', '',
                                        str_replace('\\', '/', $file));
                    cmod_warn('inventory', "Could not read the table of a $op statement ("
                        . 'found "' . $table . '" where a name was expected). The write is missing '
                        . 'from the inventory below — teach the checker this form.',
                        $rel . ':' . $line);
                    continue;
                }

                // Keep the rest of the literal: it carries the WHERE clause the
                // boundedness check needs to look at.
                $tail = substr($s['text'], $hit[0][1], 900);
                $out[] = [
                    'op'    => $op,
                    'table' => $table,
                    'file'  => $file,
                    'line'  => $line,
                    'sql'   => preg_replace('/\s+/', ' ', $tail),
                ];
            }
        }
    }
    return $out;
}

/**
 * Table name to module-relative "file:line".
 *
 * @param array $st One entry from cmod_statements().
 * @param string $dir Module root, stripped from the front of the path.
 * @return string
 */
function cmod_where($st, $dir) {
    $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $st['file']), '/');
    return $rel . ':' . $st['line'];
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

/**
 * Module layout, manifest and documentation files.
 *
 * @param string $dir Module root.
 * @param string $name Module folder name.
 * @param array $version Decoded version.json.
 */
function cmod_check_structure($dir, $name, $version) {
    // _shared is the library every module depends on, not a module: it has no
    // module.json, no repository of its own and no README pair. Its code is held
    // to the same naming, safety and documentation rules as everything else.
    $isLibrary = ($name === '_shared');

    if (!$isLibrary && !is_file($dir . '/module.json')) {
        cmod_error('structure', 'module.json is missing: nothing identifies this folder as a managed module.');
    }
    if (!$version) {
        cmod_error('structure', 'version.json is missing or is not valid JSON.');
        return;
    }
    foreach (['version', 'files'] as $key) {
        if (!isset($version[$key])) cmod_error('structure', "version.json has no \"$key\" key.");
    }

    // Every declared file must exist, or an update will report failures forever.
    foreach ((array)($version['files'] ?? []) as $rel) {
        if (!is_file($dir . '/' . $rel)) {
            cmod_error('structure', "version.json lists \"$rel\", which does not exist on disk.");
        }
    }

    // Anything not declared will never reach an installation through an update.
    $declared = array_flip((array)($version['files'] ?? []));
    foreach (cmod_source_files($dir, ['php', 'js', 'css']) as $f) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $f), '/');
        // A test bench is published for contributors, never distributed: absent on purpose.
        if (preg_match('#(^|/)tests/#', $rel)) continue;
        if (!isset($declared[$rel])) {
            cmod_warn('structure', "\"$rel\" exists but is not in version.json files[]: an update will never deliver it.");
        }
    }

    // Both README languages, per the documentation rule. The shared library is
    // documented by the modules that use it, not by a README of its own.
    if ($isLibrary) return;

    // credits.php, in the four-variable format the ianseo team proposed ($Version, $Date,
    // $Authors, $Email): what the credits page of ianseo reads for an external module.
    if (!is_file($dir . '/credits.php')) {
        cmod_warn('credits', 'credits.php is missing ($Version, $Date, $Authors, $Email — model: GUIDE/credits.php).');
    } elseif (!isset($declared['credits.php'])) {
        cmod_warn('credits', 'credits.php is not listed in version.json files[].');
    }

    foreach (["README_$name.md" => 'English', "README_{$name}_FR.md" => 'French'] as $file => $lang) {
        if (!is_file($dir . '/' . $file)) {
            cmod_error('docs', "$lang README is missing: $file");
        } elseif (!isset($declared[$file])) {
            cmod_warn('docs', "$file is not listed in version.json files[].");
        }
    }
}

/**
 * Table and column naming against the core's conventions.
 *
 * @param array $statements Output of cmod_statements().
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_naming($statements, $files, $dir) {
    // --- Tables the module creates -----------------------------------------
    $created = [];
    foreach ($statements as $st) {
        if (strpos($st['op'], 'CREATE TABLE') === 0) $created[$st['table']] = $st;
    }

    foreach ($created as $table => $st) {
        $where = cmod_where($st, $dir);

        if (strpos($table, '_') !== false) {
            cmod_error('naming', "Table \"$table\" contains an underscore. No core table does; "
                . 'the convention is a PascalCase name with a short functional prefix (AclUsers, RoundRobinMatches).', $where);
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $table)) {
            cmod_error('naming', "Table \"$table\" is not PascalCase.", $where);
        }
        if (in_array($table, CMOD_CORE_TABLES, true)) {
            cmod_error('naming', "Table \"$table\" has the same name as a core ianseo table.", $where);
        }
    }

    // --- Casing consistency -------------------------------------------------
    // Every spelling of a name, grouped case-insensitively. More than one
    // spelling means the module works on a case-insensitive server and breaks on
    // a case-sensitive one.
    $spellings = [];
    foreach ($statements as $st) {
        $spellings[strtolower($st['table'])][$st['table']][] = cmod_where($st, $dir);
    }
    foreach ($spellings as $variants) {
        if (count($variants) < 2) continue;
        $names = implode(' / ', array_keys($variants));
        $first = reset($variants);
        cmod_error('casing', "The same table is written several ways: $names. "
            . 'On Linux (lower_case_table_names=0) only one of them resolves.', $first[0]);
    }

    // --- Core tables must be spelled the way the core spells them ------------
    $coreLower = [];
    foreach (CMOD_CORE_TABLES as $t) $coreLower[strtolower($t)] = $t;

    foreach ($spellings as $lower => $variants) {
        if (!isset($coreLower[$lower])) continue;
        foreach ($variants as $written => $places) {
            if ($written !== $coreLower[$lower]) {
                cmod_error('casing', "Core table written \"$written\" but the core spells it "
                    . "\"{$coreLower[$lower]}\".", $places[0]);
            }
        }
    }

    // --- Column prefixes ----------------------------------------------------
    // Read the body of each CREATE TABLE and collect the declared columns.
    foreach ($files as $file) {
        foreach (cmod_tokenise($file)['strings'] as $s) {
            if (!preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z][A-Za-z0-9_]*)`?\s*\((.*?)\)\s*ENGINE/is',
                $s['text'], $m, PREG_SET_ORDER
            )) continue;

            foreach ($m as $hit) {
                $table = $hit[1];
                $cols  = [];
                foreach (preg_split('/,\s*\n/', $hit[2]) as $line) {
                    if (preg_match('/^\s*`?([A-Z][A-Za-z0-9]*)`?\s+[A-Za-z]/', $line, $c)) {
                        // Skip index and constraint clauses, which also start a line.
                        if (preg_match('/^\s*(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN)\b/i', $line)) continue;
                        $cols[] = $c[1];
                    }
                }
                if (count($cols) < 2) continue;

                $where = $file . ':' . $s['line'];
                $rel   = cmod_where(['file' => $file, 'line' => $s['line']], $dir);

                // One prefix for the whole table, as the core does.
                $prefixes = [];
                foreach ($cols as $c) $prefixes[substr($c, 0, 2)] = true;
                if (count($prefixes) > 1) {
                    cmod_warn('naming', "Table \"$table\" mixes column prefixes ("
                        . implode(', ', array_keys($prefixes)) . '). ianseo uses one prefix per table.', $rel);
                }

                $prefix = substr($cols[0], 0, 2);
                if (in_array($prefix, CMOD_CORE_COL_PREFIXES, true)) {
                    cmod_error('naming', "Table \"$table\" uses column prefix \"$prefix\", already used by "
                        . 'core tables. Module tables are routinely joined to core ones, and a shared prefix '
                        . 'makes a join impossible to read and ambiguous to write.', $rel);
                }
            }
        }
    }
}

/**
 * Every write the module performs, and whether it can reach outside its scope.
 *
 * This is the check that exists because of the incident the ianseo team refers
 * to: an unbounded UPDATE that rewrote years of data. It reports rather than
 * judges, and the inventory it produces has to be reviewed by a human and
 * mirrored in the module's documentation.
 *
 * @param array $statements Output of cmod_statements().
 * @param string $dir Module root.
 * @param array $ownTables Tables the module creates.
 * @return array The write inventory, for the documentation section.
 */
function cmod_check_writes($statements, $dir, $ownTables) {
    $writeOps  = array_flip(CMOD_WRITE_OPS);
    $inventory = ['core' => [], 'own' => []];

    foreach ($statements as $st) {
        if (!isset($writeOps[$st['op']])) continue;

        $table = $st['table'];
        $where = cmod_where($st, $dir);
        $isOwn = isset($ownTables[$table]);
        $isCore = in_array($table, CMOD_CORE_TABLES, true);

        if (!$isCore && !$isOwn) continue;   // not a table we can reason about

        $entry = ['op' => $st['op'], 'table' => $table, 'where' => $where, 'notes' => []];

        if ($isCore) {
            // Structural statements against the core are never acceptable from a module.
            if (in_array($st['op'], ['DROP TABLE', 'TRUNCATE', 'TRUNCATE TABLE', 'ALTER TABLE', 'RENAME TABLE'], true)) {
                cmod_error('writes', "{$st['op']} on core table \"$table\". A module must not change "
                    . 'the shape of the core schema.', $where);
                $entry['notes'][] = 'structural change to a core table';
            }

            // Qualifications has no competition column at all: its primary key is
            // QuId and every index is global. A filter that looks geometric
            // (session, target, letter) therefore matches rows belonging to every
            // competition in the database. The only safe form joins Entries or
            // lists explicit ids.
            if ($table === 'Qualifications' && in_array($st['op'], ['UPDATE', 'DELETE FROM'], true)) {
                $joinsEntries = stripos($st['sql'], 'Entries') !== false;
                $explicitIds  = preg_match('/\b(QuId|EnId)\s+IN\s*\(/i', $st['sql']);
                if (!$joinsEntries && !$explicitIds) {
                    cmod_error('writes', 'Write to Qualifications without joining Entries and without an '
                        . 'explicit id list. Qualifications carries no competition column, so this statement '
                        . 'reaches rows of every competition in the database.', $where);
                    $entry['notes'][] = 'UNBOUNDED — must join Entries';
                }
            }

            // General boundedness hint for the remaining writes.
            if (in_array($st['op'], ['UPDATE', 'DELETE FROM'], true)) {
                $scoped = false;
                foreach (CMOD_SCOPE_COLUMNS as $col) {
                    if (stripos($st['sql'], $col) !== false) { $scoped = true; break; }
                }
                if (!$scoped && !preg_match('/\bIN\s*\(/i', $st['sql'])) {
                    cmod_warn('writes', "{$st['op']} on core table \"$table\" shows no competition column "
                        . 'and no explicit id list. Confirm by hand that it cannot reach another competition.', $where);
                    $entry['notes'][] = 'review scope by hand';
                }
            }
            $inventory['core'][] = $entry;
        } else {
            $inventory['own'][] = $entry;
        }
    }
    return $inventory;
}

/**
 * menu.php is loaded on every ianseo page, so a failure there takes the whole
 * site down — not just the module.
 *
 * safe_r_sql() and safe_w_sql() route any SQL error to safe_error(), which sends
 * a 404 header and calls exit(). No try/catch intercepts it. One missing table
 * (error 1146) is enough, and because get_which_menu() includes every module's
 * menu.php on every page, the result is a completely unreachable installation.
 *
 * @param string $dir Module root.
 */
function cmod_check_menu($dir) {
    $menu = $dir . '/menu.php';
    if (!is_file($menu)) return;

    foreach (cmod_tokenise($menu)['strings'] as $s) {
        foreach (CMOD_WRITE_OPS as $op) {
            $pattern = '/\b' . str_replace(' ', '\\s+', preg_quote($op, '/')) . '\s+`?[A-Za-z]/i';
            if (preg_match($pattern, $s['text'])) {
                cmod_error('menu', "menu.php contains a $op statement. A menu reads; it never writes. "
                    . 'Table creation and file deployment belong in the module\'s own bootstrap, which only '
                    . 'its own pages load.', 'menu.php:' . $s['line']);
            }
        }
    }

    // Reads on the module's own tables need a guard, because those tables may
    // legitimately not exist yet on a fresh installation.
    $src = file_get_contents($menu);
    if (preg_match_all('/safe_r_sql\s*\((.{0,400}?)\)\s*;/s', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $call) {
            $args = $call[1];
            $guarded = preg_match('/,\s*(false|true)\s*,\s*true\s*$/s', $args)
                    || stripos($args, 'information_schema') !== false;
            if (!$guarded) {
                cmod_warn('menu', 'safe_r_sql() in menu.php without the third argument $force set to true '
                    . 'and without an information_schema guard. If the table is missing, this kills every '
                    . 'page of the installation, not just this module.', 'menu.php');
            }
        }
    }
}

/**
 * Ordering rules inside the module's schema function.
 *
 * Two mirror-image rules, both of which fail silently on the developer's own
 * database because it has had the tables for months:
 *   - a column is added AFTER the CREATE TABLE of its table;
 *   - a table is renamed BEFORE the CREATE TABLE of its new name.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_schema_order($files, $dir) {
    foreach ($files as $file) {
        $src = file_get_contents($file);

        // Position of each CREATE TABLE in the file.
        $creates = [];
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z][A-Za-z0-9_]*)`?/i',
            $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) $creates[$hit[1][0]] = $hit[0][1];
        }
        if (!$creates) continue;

        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');

        // Column additions must come after their CREATE — unless the code has
        // already proved the table is there.
        if (preg_match_all('/(?:cmod_add_column|\w+_colonne|ALTER\s+TABLE)\s*\(?\s*[\'"`]?([A-Za-z][A-Za-z0-9_]*)/i',
            $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $table = $hit[1][0];
                $at    = $hit[0][1];
                if (!isset($creates[$table]) || $at >= $creates[$table]) continue;

                // An explicit existence test on the same table, anywhere earlier
                // in the file, makes the position harmless: the ALTER cannot run
                // on a database that lacks the table. cmod_add_column() carries
                // that test inside itself, hence its presence in the pattern.
                $before  = substr($src, 0, $at);
                $guarded = preg_match(
                    '/(?:SHOW\s+TABLES\s+LIKE\s*[\'"]' . preg_quote($table, '/') . '|'
                  . 'cmod_table_exists\s*\(\s*[\'"]' . preg_quote($table, '/') . '|'
                  . 'information_schema\.TABLES|cmod_add_column)/i',
                    $before
                );
                if ($guarded) continue;

                $line = substr_count($before, "\n") + 1;
                cmod_error('schema-order', "A column is added to \"$table\" before its CREATE TABLE in the "
                    . 'same file, with no existence test in between. This passes unnoticed on a database that '
                    . 'already has the table and breaks every fresh installation, aborting the schema function '
                    . 'so that none of the tables declared after it are created either.', "$rel:$line");
            }
        }

        // Renames must come before the CREATE of their target.
        if (preg_match_all('/cmod_rename_table\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]/i',
            $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $target = $hit[2][0];
                $at     = $hit[0][1];
                if (isset($creates[$target]) && $at > $creates[$target]) {
                    $line = substr_count(substr($src, 0, $at), "\n") + 1;
                    cmod_error('schema-order', "Table \"$target\" is created before the rename that should have "
                        . 'produced it. CREATE will make an empty table, the rename will then refuse to run, and '
                        . 'the module will serve empty data while the real rows stay under the old name — with '
                        . 'no SQL error anywhere.', "$rel:$line");
                }
            }
        }
    }
}

/**
 * Comments of a JavaScript file, with their line numbers.
 *
 * A thin wrapper over cmod_js_scan(): most callers want the comments alone.
 *
 * A single scan of the file rather than regular expressions, because comments
 * and strings can only be told apart in order. Blanking strings first — the
 * obvious shortcut — fails on the very first English comment: the apostrophe in
 * "ianseo's" opens a string that then runs to the next apostrophe, hundreds of
 * lines away, taking every comment in between with it.
 *
 * Regular-expression literals ARE modelled, and have to be: a regex as ordinary
 * as /"/g holds a quote, and reading that quote as the start of a string sends
 * the scan hunting for a closing one hundreds of lines away, swallowing every
 * comment in between. Whether a "/" opens a regex or means division is decided
 * the way JavaScript itself decides it — by what came just before.
 *
 * @param string $file Absolute path.
 * @return array Entries of ['text' => …, 'line' => …].
 */
function cmod_js_comments($file) {
    return cmod_js_scan(file_get_contents($file))['comments'];
}

/**
 * Split a piece of JavaScript into its comments and its string literals.
 *
 * The scan is shared because both halves come out of the same pass: comments and
 * strings can only be told apart in order, and the caller usually wants one or
 * the other of the two lists this produces.
 *
 * @param string $src JavaScript source.
 * @param int $startLine Line the source begins on, for a block lifted out of a page.
 * @return array{comments: array, strings: array} Entries of ['text' => …, 'line' => …].
 */
function cmod_js_scan($src, $startLine = 1) {
    $n    = strlen($src);
    $out  = ['comments' => [], 'strings' => []];
    $line = $startLine;
    $i    = 0;

    // The last character that could end an expression. After one of these a "/"
    // is division; anywhere else it opens a regular expression.
    $prev = '';

    while ($i < $n) {
        $c    = $src[$i];
        $next = $i + 1 < $n ? $src[$i + 1] : '';

        if ($c === "\n") { $line++; $i++; continue; }

        // Line comment
        if ($c === '/' && $next === '/') {
            $start = $i;
            while ($i < $n && $src[$i] !== "\n") $i++;
            $out['comments'][] = ['text' => substr($src, $start, $i - $start), 'line' => $line];
            continue;
        }

        // Block comment
        if ($c === '/' && $next === '*') {
            $start = $i;
            $commentLine = $line;
            $i += 2;
            while ($i < $n && !($src[$i] === '*' && $i + 1 < $n && $src[$i + 1] === '/')) {
                if ($src[$i] === "\n") $line++;
                $i++;
            }
            $i = min($n, $i + 2);
            $out['comments'][] = ['text' => substr($src, $start, $i - $start), 'line' => $commentLine];
            continue;
        }

        // String or template literal: skip it whole, honouring escapes.
        if ($c === '"' || $c === "'" || $c === '`') {
            $quote     = $c;
            $textStart = $i + 1;
            $textLine  = $line;
            $i++;
            while ($i < $n) {
                if ($src[$i] === '\\') { $i += 2; continue; }
                if ($src[$i] === "\n") $line++;          // only legal in a template
                if ($src[$i] === $quote) { $i++; break; }
                $i++;
            }
            $out['strings'][] = [
                'text' => substr($src, $textStart, max(0, $i - 1 - $textStart)),
                'line' => $textLine,
            ];
            $prev = $quote;                              // a string ends an expression
            continue;
        }

        // Regular-expression literal. Skipped whole, so quotes and slashes
        // inside it are never mistaken for the start of something else. A
        // character class is tracked because "/" is literal within one.
        if ($c === '/' && !preg_match('/[A-Za-z0-9_$\)\]\}]/', $prev)) {
            $i++;
            $inClass = false;
            while ($i < $n) {
                $ch = $src[$i];
                if ($ch === '\\')            { $i += 2; continue; }
                if ($ch === "\n")            { $line++; break; }   // unterminated: give up on this line
                if ($ch === '[')             $inClass = true;
                elseif ($ch === ']')         $inClass = false;
                elseif ($ch === '/' && !$inClass) { $i++; break; }
                $i++;
            }
            $prev = '/';
            continue;
        }

        if (trim($c) !== '') $prev = $c;
        $i++;
    }
    return $out;
}

/** Stands in for a PHP block once the markup of a page has been isolated. */
define('CMOD_PHP_MARK', "\x00");

/**
 * Words that give away French prose, and the accents English does not use.
 *
 * Deliberately narrow: "session", "action", "important" and friends are the same
 * word in both languages and would produce nothing but false positives. "plus"
 * is deliberately absent for the same reason — it was the only entry that ever
 * produced one.
 *
 * @return array{words: string, accents: string} Two regular expressions.
 */
function cmod_french() {
    return [
        'words' => '/\b(les|des|une|dans|pour|avec|sont|être|cette|cet|ces|qui|que|'
                 . 'donc|ainsi|alors|toujours|jamais|aucun|aucune|chaque|depuis|'
                 . 'lorsque|puisque|sinon|selon|entre|vers|sous|leur|leurs|elle|'
                 . 'nous|vous|c\'est|n\'est|d\'un|d\'une|l\'on|'
                 . 'choisir|supprimer|ajouter|enregistrer|annuler|retirer|'
                 . 'formation|étape|étapes|aucun|invalide|inconnue|manquant|'
                 . 'veuillez|toujours|soumission|survol|changement|clic)\b/iu',
        'accents' => '/[àâäçéèêëîïôöùûüÿœæ]/u',
    ];
}

/**
 * Split a page into the languages it is written in: markup, script and style.
 *
 * A .php page of a module is three languages in one file, and the PHP tokeniser
 * only understands one of them. Everything between <?php and ?> is blanked
 * first — newlines kept, so line numbers stay true — and what remains is real
 * markup, out of which <script> and <style> bodies are lifted.
 *
 * @param string $file Absolute path.
 * @return array{html: array, js: array, css: array} Chunks of ['text' => …, 'line' => …].
 */
function cmod_markup_parts($file) {
    $src = file_get_contents($file);

    /* Blank the PHP, keeping the newlines so a reported line number is the line
       an editor will open, and leaving a NUL byte where the expression stood.
       The marker matters: a checker that cannot tell "nothing was here" from
       "an expression was here" reads a button whose label is a symbol followed
       by a call to the translation function as a button labelled by a symbol
       alone. NUL cannot occur in source, so it is never mistaken for content.

       Note the comment style: a closing PHP tag inside a // comment really does
       close the block, so the example above is described rather than written. */
    $markup = preg_replace_callback('/<\?(?:php|=)?.*?(?:\?>|$)/s', function ($m) {
        return CMOD_PHP_MARK . str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);

    $out = ['html' => [], 'js' => [], 'css' => []];

    // Lift <script> and <style> bodies out, then blank them in the markup so
    // that what is left really is markup. Offsets are taken before any blanking,
    // which is why the two passes are separate.
    foreach ([['script', 'js'], ['style', 'css']] as [$tag, $key]) {
        $pattern = '#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is';
        if (preg_match_all($pattern, $markup, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $out[$key][] = [
                    'text' => $hit[1][0],
                    'line' => substr_count(substr($markup, 0, $hit[1][1]), "\n") + 1,
                ];
            }
        }
        $markup = preg_replace_callback($pattern, function ($m) {
            return str_repeat("\n", substr_count($m[0], "\n"));
        }, $markup);
    }

    $out['html'][] = ['text' => $markup, 'line' => 1];
    return $out;
}

/**
 * The text a visitor of a page actually reads: element content and the
 * attributes that carry wording.
 *
 * class, id, style and the rest are markup, not language, and are left out —
 * "ge-btn-del" must not be read as prose.
 *
 * @param string $html Markup with the PHP already blanked out.
 * @param int $startLine Line the markup begins on.
 * @return array Entries of ['text' => …, 'line' => …].
 */
function cmod_html_text($html, $startLine = 1) {
    $out = [];

    // Attributes that hold wording. value= is included: a submit button's label
    // lives there.
    if (preg_match_all('/\b(title|placeholder|alt|value|aria-label)\s*=\s*"([^"]*)"/i',
                       $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $out[] = [
                'text' => $hit[2][0],
                'line' => $startLine + substr_count(substr($html, 0, $hit[0][1]), "\n"),
            ];
        }
    }

    // Text between tags, and HTML comments, which are prose as much as any other.
    $offset = 0;
    foreach (preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE) as $piece) {
        [$text, $at] = $piece;
        if ($text === '' || $text[0] === '<') continue;
        if (trim($text) !== '') {
            $out[] = ['text' => $text, 'line' => $startLine + substr_count(substr($html, 0, $at), "\n")];
        }
    }
    return $out;
}

/**
 * Documentation inside the code: a header on every file, and English throughout.
 *
 * Covers JavaScript as well as PHP. The player of a module like this one holds
 * as much logic as its server side, and a reviewer reading it should not have to
 * work in a second language.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_comments($files, $dir) {
    $fr = cmod_french();

    // JavaScript carries as much of this module's logic as PHP does.
    $all = array_merge($files, cmod_source_files($dir, ['js']));

    foreach ($all as $file) {
        $rel  = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        $isJs = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'js';

        if ($isJs) {
            $comments = cmod_js_comments($file);
        } else {
            $comments = cmod_tokenise($file)['comments'];

            // A page is three languages in one file, and the PHP tokeniser sees
            // only one of them: a comment inside an inline <script>, a CSS
            // comment or an HTML comment is invisible to it. They are prose all
            // the same, and were where French survived a first translation pass.
            $parts = cmod_markup_parts($file);
            foreach ($parts['js'] as $block) {
                foreach (cmod_js_scan($block['text'], $block['line'])['comments'] as $c) {
                    $comments[] = $c;
                }
            }
            foreach (array_merge($parts['css'], $parts['html']) as $block) {
                foreach (['#/\*.*?\*/#s', '/<!--.*?-->/s'] as $pattern) {
                    if (!preg_match_all($pattern, $block['text'], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
                    foreach ($m as $hit) {
                        $comments[] = [
                            'text' => $hit[0][0],
                            'line' => $block['line'] + substr_count(substr($block['text'], 0, $hit[0][1]), "\n"),
                        ];
                    }
                }
            }
        }

        // A header comment in the first lines, saying what the file does.
        $hasHeader = false;
        foreach ($comments as $c) {
            if ($c['line'] <= 6 && strlen(trim($c['text'])) > 40) { $hasHeader = true; break; }
        }
        if (!$hasHeader) {
            cmod_error('docs', 'No header comment explaining what this file does.', $rel . ':1');
        }

        // Translation files legitimately quote foreign words in otherwise English
        // comments, when they record which core wording they follow, so the
        // accent rule would fire on correct code. The vocabulary rule still
        // applies to them.
        $isLanguageFile = strpos($rel, 'languages/') === 0;

        foreach ($comments as $c) {
            // What a comment QUOTES is not what it is written in: the label of a foreign page
            // it matches (« Me déconnecter »), a proper name ("Fédération"), a URL. Only the
            // words around the quotes are the comment's own language.
            $own = preg_replace(array('/«[^»]*»/u', '/“[^”]*”/u', '/"[^"\n]*"/', '#\bhttps?://\S+#'), ' ', $c['text']);
            $accented = !$isLanguageFile && preg_match($fr['accents'], $own);
            // Quotes removed, a French comment may have nothing left that the lists above know
            // ("ou par le coupe-circuit, format « … »"). So when the original carried accents,
            // what is left must read as English: more English function words than French ones.
            if (!$accented && !$isLanguageFile && (preg_match($fr['accents'], $c['text']) || preg_match($fr['words'], $c['text']))) {
                $en  = preg_match_all('/\b(the|of|and|to|is|in|for|with|on|at|by|from|it|be|are|as|an|or|this|that|not|no|a)\b/i', $own);
                $frw = preg_match_all('/\b(le|la|les|de|des|du|un|une|et|ou|par|pour|sur|en|au|aux|ne|pas|est|il|elle|on|ce|cet|cette|qui|que|se|si|sans|avec|dans|plus|ici|tel|comme|mais|donc|car|leur|son|sa|ses|nous|vous)\b/iu', $own);
                $accented = $en <= $frw;
            }
            if ($accented || preg_match($fr['words'], $own)) {
                cmod_error('docs', 'Comment is not in English.', $rel . ':' . $c['line']);
            }
        }
    }
}

/**
 * Control flow written as template tags around blocks of HTML.
 *
 * The pattern ianseo objects to is a loop or a test whose body is markup:
 * a foreach opened in one PHP block, several lines of HTML, an endforeach in
 * another. Nested two or three deep, with the attributes of each element built
 * from short echo tags, the result is a page nobody can follow — which is the
 * argument that matters. (The other argument sometimes given, that switching
 * between HTML and the interpreter is slow, does not hold: PHP compiles inline
 * HTML to a literal echo. It is a readability rule, and their house style.)
 *
 * Building the markup in PHP — echo, or a small function per block — keeps the
 * structure of the page in one language, so an editor can indent it, a search
 * can find it, and a reviewer can see where a loop ends.
 *
 * Counted rather than pinpointed: one report per file with the number of
 * template control structures, because the fix is to rewrite the page, not to
 * patch a line.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_templating($files, $dir) {
    foreach ($files as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        $src = file_get_contents($file);

        // An opening tag immediately followed by control flow, and the matching
        // alternative-syntax closers. Both halves of the same habit.
        $n = preg_match_all(
            '/<\?php\s+(?:\}\s*)?(?:if|elseif|else|foreach|for|while|switch|end(?:if|foreach|for|while|switch))\b/i',
            $src, $m);
        if ($n < 3) continue;   // one or two are a legitimate conditional block

        cmod_warn('templating',
            "$n template control structures wrap blocks of HTML here. ianseo asks that the markup "
            . 'be produced from PHP instead, so the structure of the page stays readable.',
            "$rel:1");
    }
}

/**
 * Documents printed by the browser instead of the core's PDF classes.
 *
 * ianseo's remark: "I have a match plan with 64 targets… press print, the result
 * is not readable". A browser cannot paginate a large table, hold a width or
 * repeat headers. Paper documents go through Common/pdf/ (IanseoPdf, OrisPDF,
 * ResultPDF…) and, for a rule-specific part, a chunk loaded by PdfChunkLoader.
 * window.print() is therefore an error wherever it appears, in a page or a
 * script of the module.
 *
 * @param string $dir Module root.
 */
function cmod_check_print($dir) {
    foreach (cmod_source_files($dir, ['php', 'js']) as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        foreach (file($file) as $i => $line) {
            if (!preg_match('/\bwindow\.print\s*\(|[="\':;{\s]print\(\)/', $line)) continue;
            if (preg_match('/^\s*(\/\/|\*|#)/', $line)) continue;   // a comment naming it
            cmod_error('pdf',
                'This document is printed by the browser. ianseo asks that anything meant for paper '
                . 'be produced with the PDF classes of the core (Common/pdf/), which paginate, keep '
                . 'the width and repeat the headers.',
                "$rel:" . ($i + 1));
        }
    }
}

/**
 * Resolve a path segment by segment, comparing names exactly.
 *
 * Windows (and macOS) find "Lib/Tools.php" when the disk holds "lib/tools.php";
 * Linux, where most servers run, does not. file_exists() cannot tell the two cases
 * apart on Windows, hence the walk through the real directory listings.
 *
 * @param string $base Existing directory the path is relative to.
 * @param string $rel Relative path, "/" separated.
 * @return array ['ok'] | ['case', string $onDisk] | ['missing']
 */
function cmod_case_resolve($base, $rel) {
    static $listing = [];
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', rtrim($base, '/\\'))) as $p) $parts[] = $p;
    $fixed = count($parts);     // the base itself is trusted as given
    $wrong = false;
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (count($parts) > 1) array_pop($parts);
            $fixed = min($fixed, count($parts));
            continue;
        }
        $dir = implode('/', $parts);
        if (!isset($listing[$dir])) $listing[$dir] = is_dir($dir) ? array_diff(scandir($dir), ['.', '..']) : [];
        if (in_array($seg, $listing[$dir], true)) {
            $parts[] = $seg;
            continue;
        }
        $match = null;
        foreach ($listing[$dir] as $entry) {
            if (strcasecmp($entry, $seg) === 0) { $match = $entry; break; }
        }
        if ($match === null) return ['missing'];
        $parts[] = $match;
        $wrong = true;
    }
    return $wrong ? ['case', implode('/', array_slice($parts, $fixed))] : ['ok'];
}

/**
 * File names written with a case that differs from the disk: require/include,
 * paths built from __DIR__, $CFG->DOCUMENT_PATH, HTDOCS or $CFG->ROOT_DIR, src/href
 * of a page, url() of a stylesheet.
 *
 * Only paths spelled entirely in a literal are checked; one built from a variable
 * is beyond a static check, and a path that does not exist at all is left alone
 * (optional file, generated file, file of another module).
 *
 * @param string $dir Module root.
 */
function cmod_check_file_case($dir) {
    // The ianseo the module sits in (htdocs/Modules/Custom/<MODULE>). Core files are only
    // checked when that ianseo is present; a bare checkout checks the module's own files.
    $htdocs = str_replace('\\', '/', getenv('CMOD_HTDOCS') ?: dirname($dir, 3));
    $seen   = [];

    foreach (cmod_source_files($dir, ['php', 'js', 'css', 'html', 'htm']) as $file) {
        $rel  = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        $here = str_replace('\\', '/', dirname($file));
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        foreach (file($file) as $i => $line) {
            if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line)) continue;
            $refs = [];   // [base, path]

            if ($ext === 'php') {
                // __DIR__, dirname(__DIR__), dirname(__DIR__, 2), dirname(dirname(__DIR__))
                if (preg_match_all('/((?:dirname\(\s*)*)__DIR__\s*(?:,\s*(\d+)\s*)?\)*\s*\.\s*([\'"])([^\'"$]+)\3/', $line, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) {
                        $up = substr_count($x[1], 'dirname(');
                        if ($x[2] !== '' && $up > 0) $up += (int)$x[2] - 1;
                        $base = $here;
                        for ($k = 0; $k < $up; $k++) $base = dirname($base);
                        $refs[] = [$base, $x[4]];
                    }
                }
                if (preg_match_all('/(?:\$CFG->DOCUMENT_PATH|\$CFG->ROOT_DIR|\bHTDOCS)\s*\.\s*([\'"])([^\'"$]+)\1/', $line, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) $refs[] = [$htdocs, $x[2]];
                }
                // A bare relative include: PHP tries the include path (htdocs), then the
                // directory of the script.
                if (preg_match_all('/\b(?:require|include)(?:_once)?\s*\(?\s*([\'"])([^\'"$\/][^\'"$]*\.php)\1/', $line, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) {
                        $a = cmod_case_resolve($htdocs, $x[2]);
                        $refs[] = [$a[0] === 'missing' ? $here : $htdocs, $x[2]];
                    }
                }
            }
            if ($ext !== 'js' && preg_match_all('/\b(?:src|href)\s*=\s*([\'"])([^\'"<>{}$?#]+)/i', $line, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $p = $x[2];
                    if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#)#i', $p)) continue;
                    $refs[] = [$p[0] === '/' ? $htdocs : $here, ltrim($p, '/')];
                }
            }
            if ($ext === 'css' && preg_match_all('/url\(\s*([\'"]?)([^\'")?#]+)\1/i', $line, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $x[2])) continue;
                    $refs[] = [$x[2][0] === '/' ? $htdocs : $here, ltrim($x[2], '/')];
                }
            }

            foreach ($refs as [$base, $path]) {
                $path = preg_replace('/[?#].*$/', '', trim($path));
                if ($path === '' || substr($path, -1) === '/' || strpbrk($path, '*%{}') !== false) continue;
                $r = cmod_case_resolve($base, $path);
                if ($r[0] !== 'case') continue;
                $key = "$rel|$path";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                cmod_error('filecase',
                    "\"$path\" is spelled differently on disk (\"{$r[1]}\"). Windows finds it anyway; "
                    . 'a Linux server does not, and the page stops there.',
                    "$rel:" . ($i + 1));
            }
        }
    }
}

/**
 * PHP statements of a file, each with the SQL its string pieces spell.
 *
 * A statement ends at a ";" outside brackets, or at a block brace. Its SQL is
 * the concatenation of its string literals, every other piece (a variable, a
 * call) standing as " 1 ": enough to see the tables and aliases of a query built
 * across several concatenations, which a single literal does not show.
 *
 * @param string $file PHP file.
 * @return array [['sql' => string, 'line' => int], …]
 */
function cmod_sql_statements($file) {
    $toks = token_get_all(file_get_contents($file));
    $out = [];
    $sql = ''; $line = 0; $stack = [];
    $cut = function () use (&$out, &$sql, &$line) {
        if (preg_match('/\b(SELECT|UPDATE|DELETE|INSERT|REPLACE)\b/', $sql)) $out[] = ['sql' => $sql, 'line' => $line];
        $sql = ''; $line = 0;
    };
    foreach ($toks as $t) {
        $txt = is_array($t) ? $t[1] : $t;
        if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $stack[] = 'c'; continue; }
        if ($txt === '(' || $txt === '[') { $stack[] = $txt; continue; }
        if ($txt === ')' || $txt === ']') { array_pop($stack); continue; }
        if ($txt === '}' && end($stack) === 'c') { array_pop($stack); continue; }
        if (!$stack && ($txt === '{' || $txt === '}' || $txt === ';')) { $cut(); continue; }
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $v = $t[0] === T_CONSTANT_ENCAPSED_STRING ? substr($t[1], 1, -1) : $t[1];   // bytes: ASCII quotes
            $sql .= $v;
            if (!$line) $line = $t[2];
        } elseif (is_array($t) && in_array($t[0], [T_VARIABLE, T_STRING, T_LNUMBER], true)) {
            $sql .= ' 1 ';
        }
    }
    $cut();
    return $out;
}

/**
 * Table aliases with no reason.
 *
 * Every column name of ianseo is unique — that is what the prefixes (En, Qu,
 * Ev, To…) are for — so "FROM Entries e INNER JOIN Qualifications q ON
 * e.EnId = q.QuId" says nothing that "FROM Entries INNER JOIN Qualifications
 * ON EnId = QuId" does not, and is heavier to read. The ianseo team asks for
 * the plain form. An alias keeps its reason when the same table appears twice
 * in the statement (an archer and their opponent), and a derived table needs
 * one by SQL's own rule: neither is reported.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_aliases($files, $dir) {
    $kw = '/^(on|where|inner|left|right|join|set|group|order|limit|using|union|natural|cross|values|select|and|or|as|having|for|lock|into|use|force|ignore|partition|outer|full|window|straight_join)$/i';
    foreach ($files as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        if (strpos($rel, 'languages/') === 0) continue;
        foreach (cmod_sql_statements($file) as $st) {
            if (!preg_match_all('/\b(?:FROM|JOIN|UPDATE)\s+`?([A-Z][A-Za-z0-9_]*)`?(?:\s+(?:AS\s+)?([a-z][a-z0-9_]{0,3}))?(?=[\s,)]|$)/',
                                $st['sql'], $m, PREG_SET_ORDER)) continue;
            $count = [];
            foreach ($m as $x) $count[$x[1]] = ($count[$x[1]] ?? 0) + 1;
            $named = [];
            foreach ($m as $x) {
                if (empty($x[2]) || preg_match($kw, $x[2]) || $count[$x[1]] > 1) continue;
                $named[] = $x[1] . ' ' . $x[2];
            }
            if (!$named) continue;
            cmod_warn('alias',
                'Table alias with no reason (' . implode(', ', array_unique($named)) . '): every ianseo '
                . 'column name is unique, so ianseo asks for the plain table name. Keep an alias only '
                . 'for a table that appears twice in the query.',
                "$rel:{$st['line']}");
        }
    }
}

/**
 * JSON answers outside JsonOut(), or without the error / msg envelope.
 *
 * ianseo's convention for an AJAX entry point: JsonOut($array) from the core
 * (headers, encoding, end of script), and an envelope where $JSON['error'] is 0
 * on success and anything else on failure, with $JSON['msg'] carrying the
 * message — so every caller tests the same key. A hand-made json_encode answer
 * drifts from both.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_json($files, $dir) {
    foreach ($files as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        $src = file_get_contents($file);
        foreach (preg_split('/\R/', $src) as $i => $line) {
            if (preg_match('/^\s*(\/\/|\*|#)/', $line)) continue;
            if (preg_match('/\b(echo|print|die|exit)\s*\(?\s*json_encode\s*\(/i', $line)
                || preg_match('/header\s*\(\s*[\'"]Content-Type:\s*application\/json/i', $line)) {
                cmod_warn('json', 'JSON answer written by hand: use JsonOut() of the core, with the '
                    . "envelope 'error' (0 = success) and 'msg'.", "$rel:" . ($i + 1));
            }
        }
        // JsonOut(array(…)) / JsonOut([…]) whose literal array has no 'error' key.
        if (!preg_match_all('/\bJsonOut\s*\(\s*(array\s*\(|\[)/i', $src, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as $hit) {
            $start = $hit[1] + strlen($hit[0]);   // bytes: offsets from preg
            $depth = 1; $j = $start; $len = strlen($src);   // bytes: offsets from preg
            for (; $j < $len && $depth > 0; $j++) {
                $c = $src[$j];
                if ($c === '(' || $c === '[') $depth++;
                elseif ($c === ')' || $c === ']') $depth--;
            }
            $arg = substr($src, $start, $j - $start);   // bytes: offsets from preg
            if (preg_match('/[\'"]error[\'"]\s*=>/', $arg)) continue;
            cmod_warn('json', "JsonOut() answer without the 'error' key: ianseo's envelope is "
                . "'error' (0 = success, anything else = failure) and 'msg'.",
                "$rel:" . (substr_count(substr($src, 0, $hit[1]), "\n") + 1));   // bytes: offsets from preg
        }
    }
}

/**
 * $_SESSION['TourId'] tested as if it could be missing or textual.
 *
 * The core guarantees an integer ≥ 0, 0 meaning "no competition open". isset(),
 * empty() or a comparison with '' tell the reader the value might be absent,
 * which it never is: noise that hides the real test (> 0). The defensive
 * intval($_SESSION['TourId'] ?? 0) stays accepted.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_tourid($files, $dir) {
    $tid = '\$_SESSION\s*\[\s*[\'"]TourId[\'"]\s*\]';
    $re = '/(isset|empty)\s*\(\s*' . $tid . '\s*\)|' . $tid . '\s*(===|!==|==|!=)\s*(\'\'|"")|' . $tid . '\s*\?\?\s*(\'\'|"")/';
    foreach ($files as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        foreach (file($file) as $i => $line) {
            if (!preg_match($re, $line) || preg_match('/^\s*(\/\/|\*|#)/', $line)) continue;
            cmod_warn('tourid', "\$_SESSION['TourId'] is always an integer ≥ 0 (0 = no competition): test "
                . "> 0, not its presence or an empty string.", "$rel:" . ($i + 1));
        }
    }
}

/**
 * Controls labelled by a symbol alone, with nothing a reader can translate.
 *
 * ianseo asks that a control carry either TEXT — which changes with the
 * language — or one of the icons the core already ships, so that the same
 * picture means the same thing across the whole application. An invented
 * pictogram means whatever the author hoped it meant, in every language at
 * once, and a printer icon that is not ianseo's printer icon teaches the user
 * nothing they can reuse.
 *
 * What is reported is narrow and unambiguous: a <button> or a link styled as
 * one whose visible label holds no letter at all AND which carries no title or
 * aria-label. A symbol beside translated words is fine — the words carry the
 * meaning. A symbol alone with a translated tooltip is fine too. A symbol alone
 * with nothing is not.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_icon_labels($files, $dir) {
    foreach ($files as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        if (strpos($rel, 'languages/') === 0) continue;

        foreach (cmod_markup_parts($file)['html'] as $block) {
            if (!preg_match_all('#<button\b([^>]*)>(.*?)</button>#is', $block['text'], $m,
                                PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;

            foreach ($m as $hit) {
                [$attrs, $label] = [$hit[1][0], $hit[2][0]];

                // The label, or the title, is produced by PHP — almost always a
                // call to the translation function. Nothing to say about it.
                if (strpos($hit[0][0], CMOD_PHP_MARK) !== false) continue;

                $text = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') continue;                                      // empty control
                if (preg_match('/\p{L}/u', $text)) continue;                     // has words
                if (preg_match('/\b(title|aria-label)\s*=/i', $attrs)) continue; // has a tooltip

                cmod_warn('icons',
                    'This control is labelled "' . $text . '" and nothing else: no text to '
                    . 'translate and no title. Give it a translated label or title, or use one '
                    . "of the core's own icons (Common/Images/pdf.gif, pdfOris.gif…).",
                    "$rel:" . ($block['line'] + substr_count(substr($block['text'], 0, $hit[0][1]), "\n")));
            }
        }
    }
}

/**
 * A UTF-8 BOM at the head of a file, which PHP sends as content.
 *
 * The three bytes sit BEFORE the opening tag, so PHP has no way to know they
 * are an encoding marker: it echoes them the moment the file is included. In
 * front of an API answer they make JSON.parse throw, and because every caller
 * wraps its parse in a try/catch and falls back to a default, nothing looks
 * broken — the feature simply never works. In front of a page they are output
 * before the first header(), which is how "headers already sent" appears in a
 * file that plainly sends none.
 *
 * Windows editors and PowerShell's Set-Content add one without being asked, so
 * this is a mistake of the tooling rather than of the author, and it is
 * invisible in every editor. Only a check finds it.
 *
 * @param string $dir Module root.
 */
function cmod_check_bom($dir) {
    foreach (cmod_source_files($dir, ['php', 'json', 'js', 'css']) as $file) {
        $fh = fopen($file, 'rb');
        if ($fh === false) continue;
        $head = fread($fh, 3);
        fclose($fh);
        if ($head !== "\xEF\xBB\xBF") continue;

        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        cmod_error('encoding',
            'The file starts with a UTF-8 BOM. PHP sends those three bytes as content: '
            . 'in front of JSON they make JSON.parse throw, in front of a page they break header(). '
            . 'Save the file as UTF-8 without BOM.',
            "$rel:1");
    }
}

/**
 * Byte-oriented string functions, which must not be used on international text.
 *
 * substr() counts BYTES. On a name, a first name, a club, a description — any
 * field a human typed — cutting at a byte boundary can split a character in
 * half: substr('Élodie', 0, 1) is not "É" but the first half of it, and what
 * reaches the screen, the database or the export is an invalid sequence. The
 * whole family behaves the same way: strlen, strtoupper, ucfirst, str_pad,
 * str_split, strrev, wordwrap. Each has an mb_ counterpart that counts
 * characters.
 *
 * Byte functions are not banned — they are right for a hash, a URL path, an
 * offset that came from strpos(), a Content-Length. What is banned is using one
 * without saying why, because a reviewer then cannot tell a deliberate byte
 * operation from an oversight. A comment carrying the word "bytes" on the line
 * or just above it is the whole escape hatch, and it doubles as the explanation
 * the next reader needs.
 *
 * The two families are not equally dangerous, and are not reported alike:
 *   - splitting and measuring — substr, strlen, str_split, strrev, wordwrap,
 *     str_pad — can cut a character in two and produce a broken string. ERROR.
 *   - case folding — strtolower, strtoupper, ucfirst, ucwords — never breaks a
 *     string; it simply leaves "É" alone where mb_strtolower would fold it. It
 *     is a correctness problem, not a corruption one. WARN.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_bytes($files, $dir) {
    // The counterpart to reach for, and how bad it is to have missed it.
    // mb_str_pad needs PHP 8.3, so it is named as a hint, not an instruction.
    $mb = [
        'substr'     => ['mb_substr', 'error'],
        'strlen'     => ['mb_strlen', 'error'],
        'str_split'  => ['mb_str_split', 'error'],
        'strrev'     => ['a multibyte-aware reversal', 'error'],
        'wordwrap'   => ['a multibyte-aware wrap', 'error'],
        'str_pad'    => ['mb_str_pad (PHP 8.3), or padding computed with mb_strlen', 'error'],
        'strtolower' => ['mb_strtolower', 'warn'],
        'strtoupper' => ['mb_strtoupper', 'warn'],
        'ucfirst'    => ['mb_convert_case(…, MB_CASE_TITLE)', 'warn'],
        'ucwords'    => ['mb_convert_case(…, MB_CASE_TITLE)', 'warn'],
    ];

    // A value the same statement constrains to ASCII cannot hold a character to
    // split or to fold: an id being normalised to [a-z0-9-] is the usual case,
    // and reporting it would bury the findings that matter.
    $asciiFiltered = '/preg_(replace|match)\s*\(\s*[\'"][^\'"]*\[\^?[A-Za-z0-9_.\\\\\-]*\]/';

    foreach ($files as $file) {
        $rel   = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        foreach (cmod_tokenise($file)['calls'] as $call) {
            if (!isset($mb[$call['name']])) continue;
            [$fix, $level] = $mb[$call['name']];

            $line = $lines[$call['line'] - 1] ?? '';
            if (preg_match($asciiFiltered, $line)) continue;

            // "bytes" on the line itself, or in the three lines above it —
            // enough room for the reason to be a sentence rather than a label.
            $context = implode("\n", array_slice($lines, max(0, $call['line'] - 4), 4));
            if (stripos($context, 'bytes') !== false) continue;

            $say = $level === 'error' ? 'cmod_error' : 'cmod_warn';
            $say('mbstring',
                $call['name'] . '() works on bytes, not characters. Use ' . $fix
                . ', or say why bytes are right here in a comment mentioning "bytes".',
                "$rel:" . $call['line']);
        }
    }
}

/**
 * Text a user reads, which must come from the language files and not the code.
 *
 * The rule ianseo asks for is that a module be internationalisable, and the
 * mechanism for that is get_text(): a page holds its markup, the language files
 * hold its words. A sentence written straight into the markup, a placeholder, a
 * button label or an alert has escaped that mechanism, and no amount of
 * translating the language files will ever reach it.
 *
 * Detecting "not translated" in general is not possible; detecting "still in the
 * author's own language" is, and that is the mistake that actually happens. What
 * is scanned is the text a visitor sees — element content, the attributes that
 * carry wording, and JavaScript string literals — never class names, selectors
 * or SQL.
 *
 * @param array $files Module PHP files.
 * @param string $dir Module root.
 */
function cmod_check_strings($files, $dir) {
    $fr = cmod_french();

    // A string that is plainly not prose: a CSS selector, a path, a format, a
    // piece of markup with no words of its own.
    $notProse = '/^[\s\d\W]*$/u';

    // A lone lower-case token is an identifier, not a sentence: a JSON field, a
    // CSS class, an action name. "formation" is a field of this module's own
    // content format and appears everywhere; reporting it as untranslated prose
    // would bury the real findings.
    $identifier = '/^[a-z0-9][a-z0-9_.\-\/]*$/';

    $report = function ($rel, $line, $text) use ($fr, $notProse, $identifier) {
        // Whatever PHP produced there is not part of the literal text.
        $text = str_replace(CMOD_PHP_MARK, '', $text);
        $raw  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($raw);
        if ($text === '' || preg_match($notProse, $text)) return;
        if (preg_match($identifier, $text)) return;
        // Not prose either:
        // - a lone character with nothing around it: an entry of an accent-folding table
        //   ('é' => 'e'). With spaces around (' à ') it is a word of a sentence, and reported;
        // - a regular expression: matching a foreign page is exactly where foreign words belong;
        // - a SQL statement, recognised by its structure rather than its first word alone (a
        //   message may well begin with "ALTER").
        if (mb_strlen($raw) === 1) return;
        if (preg_match('%^([/#~]).*\1[a-zA-Z]*$%s', $text)) return;
        if (preg_match('/^\s*(SELECT\b.*\bFROM|INSERT\s+(IGNORE\s+)?INTO|UPDATE\s+\S+\s+(.*\s)?SET|DELETE\s+FROM|REPLACE\s+INTO|(CREATE|ALTER|DROP)\s+(TABLE|VIEW|INDEX)|SHOW\s+(COLUMNS|TABLES|INDEX))\b/is', $text)) return;
        if (!preg_match($fr['words'], $text) && !preg_match($fr['accents'], $text)) return;

        // mb_ throughout: the text being quoted is exactly the kind that has
        // accents in it, and cutting at 57 bytes would print half a character.
        $short = preg_replace('/\s+/', ' ', $text);
        if (mb_strlen($short) > 60) $short = mb_substr($short, 0, 57) . '…';
        cmod_error('i18n', "Text is not translated: \"$short\"", "$rel:$line");
    };

    foreach (array_merge($files, cmod_source_files($dir, ['js'])) as $file) {
        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');

        // The language files are the one place another language belongs, and the
        // compliance tool itself holds a French word list as reference data.
        if (strpos($rel, 'languages/') === 0) continue;

        // credits.php is names and an address. A person's name is a proper noun,
        // not a string to translate, and it is exactly the kind that carries an
        // accent.
        if ($rel === 'credits.php') continue;

        // A test bench (tests/, command line, never distributed) shows nothing to a
        // visitor: its French strings are DATA — face names as ianseo's French set
        // writes them, archer names — that the code under test must recognise.
        if (preg_match('#(^|/)tests/#', $rel)) continue;

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'js') {
            foreach (cmod_js_scan(file_get_contents($file))['strings'] as $s) {
                $report($rel, $s['line'], $s['text']);
            }
            continue;
        }

        // PHP literals: an error message, an alert, a label built in code. The
        // markup chunks are left to cmod_markup_parts() below, which reads them
        // as a page rather than as one long string.
        foreach (cmod_tokenise($file)['strings'] as $s) {
            if ($s['kind'] !== 'literal' || $s['text'] === '') continue;
            $report($rel, $s['line'], trim($s['text'], "'\""));
        }

        // Markup and inline script.
        $parts = cmod_markup_parts($file);
        foreach ($parts['html'] as $block) {
            foreach (cmod_html_text($block['text'], $block['line']) as $t) {
                $report($rel, $t['line'], $t['text']);
            }
        }
        foreach ($parts['js'] as $block) {
            foreach (cmod_js_scan($block['text'], $block['line'])['strings'] as $s) {
                $report($rel, $s['line'], $s['text']);
            }
        }
    }
}

/**
 * Translation files, following the core's own mechanism.
 *
 * ianseo resolves get_text($key, $module) against
 * Common/Languages/<lang>/<module>.php, each returning a $lang array, and falls
 * back to English when a key is absent. A module therefore needs an English file
 * with every key, and translations that do not drift away from it.
 *
 * @param string $dir Module root.
 * @param string $name Module folder name.
 */
function cmod_check_i18n($dir, $name) {
    $langDir = $dir . '/languages';
    if (!is_dir($langDir)) {
        cmod_error('i18n', 'No languages/ folder. A publishable module ships translations in the core format ('
            . implode(', ', CMOD_LANGUAGES) . ').');
        return;
    }

    // Sections, the way the core splits its own translations into Common.php,
    // Tournament.php, Errors.php and so on. Each subfolder is one section and is
    // held to the same completeness rule as the module's main strings.
    foreach (glob($langDir . '/*', GLOB_ONLYDIR) as $sub) {
        cmod_check_i18n_files($sub, 'languages/' . basename($sub));
    }
    cmod_check_i18n_files($langDir, 'languages');

    // Keys the browser side asks for must actually exist. A missing one is
    // silent at runtime — the client helper falls back to showing the key — so
    // nothing but a check like this one catches it before a user does.
    // A script may take its texts from a section (languages/<section>/en.php, passed by its
    // page) as well as from the module's main file: every English key counts.
    $enKeys = [];
    foreach (array_merge([$langDir . '/en.php'], glob($langDir . '/*/en.php') ?: []) as $enFile) {
        if (!is_file($enFile)) continue;
        $lang = [];
        include $enFile;
        if (is_array($lang)) $enKeys += array_flip(array_keys($lang));
    }

    foreach (cmod_source_files($dir, ['js']) as $file) {
        $src = file_get_contents($file);
        if (!preg_match_all('/\bT\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\)/', $src, $m)) continue;

        $rel = ltrim(str_replace([$dir, '\\'], ['', '/'], $file), '/');
        foreach (array_unique($m[1]) as $k) {
            if (!isset($enKeys[$k])) {
                cmod_error('i18n', "$rel asks for the translation key \"$k\", which languages/en.php "
                    . 'does not define. At runtime this shows the key itself, with no error.', $rel);
            }
        }
    }
}

/**
 * One folder of language files: every language present, and the same keys in all.
 *
 * @param string $langDir Absolute path to the folder.
 * @param string $label How to name it in a finding.
 */
function cmod_check_i18n_files($langDir, $label) {
    $keys = [];
    foreach (CMOD_LANGUAGES as $code) {
        $file = "$langDir/$code.php";
        if (!is_file($file)) {
            cmod_error('i18n', "Missing translation file: $label/$code.php");
            continue;
        }
        $lang = [];
        include $file;                 // the file defines $lang, as the core's do
        if (!is_array($lang) || !$lang) {
            cmod_error('i18n', "$label/$code.php does not define a non-empty \$lang array. "
                . "Language files follow the core's format: one \$lang array per section.");
            continue;
        }
        $keys[$code] = array_keys($lang);
    }

    if (!isset($keys['en'])) return;

    foreach ($keys as $code => $k) {
        if ($code === 'en') continue;
        $missing = array_diff($keys['en'], $k);
        $extra   = array_diff($k, $keys['en']);
        if ($missing) {
            cmod_warn('i18n', "$label/$code.php is missing " . count($missing) . ' key(s): '
                . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '…' : ''));
        }
        if ($extra) {
            cmod_warn('i18n', "$label/$code.php has " . count($extra) . ' key(s) absent from English: '
                . implode(', ', array_slice($extra, 0, 8)) . (count($extra) > 8 ? '…' : ''));
        }
    }
}

// ---------------------------------------------------------------------------
// Write inventory, as a documentation section
// ---------------------------------------------------------------------------

/**
 * Render the write inventory as Markdown.
 *
 * The generated block is what belongs at the end of each of the module's .md
 * files. Generating it rather than writing it by hand is the point: it cannot
 * drift, and the checker fails when the file no longer matches the code, which
 * turns "review every new write" into something that happens automatically.
 *
 * @param array $inventory Output of cmod_check_writes().
 * @return string Markdown, between the marker comments.
 */
function cmod_inventory_markdown($inventory) {
    /* The tool is not named here: this block lands in a public README, and the
       checker that writes it is part of our bench, not of what a reader has. */
    $out = "<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->\n";
    $out .= "## Database writes\n\n";

    $section = function ($title, $rows, $empty) {
        $s = "### $title\n\n";
        if (!$rows) return $s . "$empty\n\n";
        $s .= "| Statement | Table | Location | Notes |\n|---|---|---|---|\n";
        foreach ($rows as $r) {
            $notes = $r['notes'] ? implode('; ', $r['notes']) : '—';
            $s .= "| `{$r['op']}` | `{$r['table']}` | `{$r['where']}` | $notes |\n";
        }
        return $s . "\n";
    };

    $out .= $section('ianseo core tables', $inventory['core'],
        'None. This module never writes to a core ianseo table.');
    $out .= $section('Tables owned by this module', $inventory['own'],
        'None. This module creates no table of its own.');

    $out .= "<!-- END DATABASE WRITES -->\n";
    return $out;
}

/**
 * Replace the generated block in a Markdown file, or append it.
 *
 * @param string $file Absolute path to the .md file.
 * @param string $block Output of cmod_inventory_markdown().
 * @return bool True when the file changed.
 */
function cmod_inventory_apply($file, $block) {
    if (!is_file($file)) return false;
    $src = file_get_contents($file);

    $begin = '<!-- BEGIN DATABASE WRITES';
    $end   = '<!-- END DATABASE WRITES -->';

    $b = strpos($src, $begin);
    $e = strpos($src, $end);

    $new = ($b !== false && $e !== false && $e > $b)
        ? substr($src, 0, $b) . $block . substr($src, $e + strlen($end) + 1)
        : rtrim($src) . "\n\n" . $block;

    if ($new === $src) return false;
    file_put_contents($file, $new);
    return true;
}

/**
 * Is the generated block in this file up to date?
 *
 * @param string $file Absolute path to the .md file.
 * @param string $block Expected block.
 * @return bool
 */
function cmod_inventory_matches($file, $block) {
    if (!is_file($file)) return false;
    $src = file_get_contents($file);
    $b = strpos($src, '<!-- BEGIN DATABASE WRITES');
    $e = strpos($src, '<!-- END DATABASE WRITES -->');
    if ($b === false || $e === false || $e < $b) return false;
    $current = substr($src, $b, $e + strlen('<!-- END DATABASE WRITES -->') - $b + 1);
    return trim($current) === trim($block);
}

// ---------------------------------------------------------------------------
// Driver
// ---------------------------------------------------------------------------

/**
 * Run every check against one module.
 *
 * @param string $dir Module root.
 * @param string $name Module folder name.
 * @param array $opts Command-line switches.
 * @return array The write inventory.
 */
function cmod_check_module($dir, $name, $opts) {
    $version = is_file($dir . '/version.json')
        ? json_decode(file_get_contents($dir . '/version.json'), true)
        : null;

    $files      = cmod_source_files($dir, ['php']);
    $statements = cmod_statements($files);

    $ownTables = [];
    foreach ($statements as $st) {
        if (strpos($st['op'], 'CREATE TABLE') === 0) $ownTables[$st['table']] = true;
    }

    cmod_check_structure($dir, $name, $version);
    cmod_check_naming($statements, $files, $dir);
    $inventory = cmod_check_writes($statements, $dir, $ownTables);
    cmod_check_menu($dir);
    cmod_check_schema_order($files, $dir);
    cmod_check_comments($files, $dir);
    cmod_check_strings($files, $dir);
    cmod_check_bytes($files, $dir);
    cmod_check_bom($dir);
    cmod_check_icon_labels($files, $dir);
    cmod_check_templating($files, $dir);
    cmod_check_print($dir);
    cmod_check_file_case($dir);
    cmod_check_aliases($files, $dir);
    cmod_check_json($files, $dir);
    cmod_check_tourid($files, $dir);
    cmod_check_i18n($dir, $name);

    // version.json must declare the tables, so the uninstaller can offer to drop
    // them and so a reader knows what the module owns.
    $declared = array_flip((array)($version['tables'] ?? []));
    foreach (array_keys($ownTables) as $t) {
        if (!isset($declared[$t])) {
            cmod_warn('structure', "Table \"$t\" is created but not listed in version.json tables[].");
        }
    }

    // The documentation must mirror the code. The shared library has no README
    // of its own, and writes nothing that is not on behalf of a module.
    if ($name === '_shared') return $inventory;

    $block = cmod_inventory_markdown($inventory);
    // The READMEs always, plus the documents a maintainer lists in CMOD_EXTRA_DOCS
    // (comma-separated); any other document of the module root that already carries
    // the inventory block is kept in step with them.
    $docs = array_merge(["README_$name.md", "README_{$name}_FR.md"],
        array_filter(array_map('trim', explode(',', (string)getenv('CMOD_EXTRA_DOCS')))));
    foreach (glob($dir . '/*.md') as $other) {
        if (!in_array(basename($other), $docs, true) && strpos((string)file_get_contents($other), '<!-- BEGIN DATABASE WRITES') !== false) {
            $docs[] = basename($other);
        }
    }
    foreach ($docs as $md) {
        $path = $dir . '/' . $md;
        if (!is_file($path)) continue;

        if (!empty($opts['fix-inventory'])) {
            if (cmod_inventory_apply($path, $block)) cmod_info('inventory', "Updated $md.");
        } elseif (!cmod_inventory_matches($path, $block)) {
            cmod_error('inventory', "$md does not carry an up-to-date database-write inventory. "
                . 'Run with --fix-inventory, then read the diff: a new line here means a new write '
                . 'that has to be reviewed.');
        }
    }
    return $inventory;
}

/**
 * Print the report and return the process exit status.
 *
 * @param string $name Module name.
 * @return int 0 when no ERROR was recorded.
 */
function cmod_print_report($name) {
    $rows   = $GLOBALS['cmod_report'];
    $counts = ['ERROR' => 0, 'WARN' => 0, 'INFO' => 0];

    echo str_repeat('=', 78) . "\n";
    echo "  Module: $name\n";
    echo str_repeat('=', 78) . "\n\n";

    if (!$rows) {
        echo "  Nothing to report.\n\n";
        return 0;
    }

    // Group by check so related findings read together.
    $byCheck = [];
    foreach ($rows as $r) {
        $counts[$r['level']]++;
        $byCheck[$r['check']][] = $r;
    }

    foreach ($byCheck as $check => $items) {
        echo "-- $check " . str_repeat('-', max(1, 72 - strlen($check))) . "\n";
        foreach ($items as $r) {
            $where = $r['where'] !== '' ? "  [{$r['where']}]" : '';
            echo sprintf("  %-5s %s%s\n", $r['level'], $r['message'], $where);
        }
        echo "\n";
    }

    echo sprintf("  %d error(s), %d warning(s), %d note(s)\n\n",
        $counts['ERROR'], $counts['WARN'], $counts['INFO']);

    return $counts['ERROR'] > 0 ? 1 : 0;
}

/**
 * @param string $name Module name.
 * @return array "module|check|file" => number of ERRORs: the unit the baseline counts.
 *               Lines are left out, so that unrelated edits above a known finding do
 *               not make it look new.
 */
function cmod_error_counts($name) {
    $out = [];
    foreach ($GLOBALS['cmod_report'] as $r) {
        if ($r['level'] !== 'ERROR') continue;
        $key = $name . '|' . $r['check'] . '|' . preg_replace('/:\d+$/', '', $r['where']);
        $out[$key] = ($out[$key] ?? 0) + 1;
    }
    return $out;
}

/**
 * Report against the baseline: the known debt in one line, the new errors in full.
 *
 * @param string $name Module name.
 * @param array $baseline "module|check|file" => count.
 * @return int 0 when nothing goes beyond the baseline.
 */
function cmod_print_against_baseline($name, array $baseline) {
    $counts = cmod_error_counts($name);
    $known  = 0;
    $new    = [];
    foreach ($counts as $key => $n) {
        $allowed = (int)($baseline[$key] ?? 0);
        $known  += min($n, $allowed);
        if ($n > $allowed) $new[$key] = $n - $allowed;
    }
    if (!$new) {
        echo "  ok    $name" . ($known ? " — $known known error(s) in the baseline, none new" : ' — no error') . "\n";
        return 0;
    }
    echo "  FAIL  $name — " . array_sum($new) . " error(s) beyond the baseline" . ($known ? " ($known known)" : '') . "\n";
    foreach ($GLOBALS['cmod_report'] as $r) {
        if ($r['level'] !== 'ERROR') continue;
        $key = $name . '|' . $r['check'] . '|' . preg_replace('/:\d+$/', '', $r['where']);
        if (!isset($new[$key])) continue;
        $where = $r['where'] !== '' ? "  [{$r['where']}]" : '';
        echo "        {$r['check']}: {$r['message']}$where\n";
    }
    foreach ($new as $key => $n) {
        if (($baseline[$key] ?? 0) > 0) {
            echo "        ($key: $n more than the " . $baseline[$key] . " known — the lines above include the known ones)\n";
        }
    }
    return 1;
}

// --- Command line -----------------------------------------------------------

// Only when this file IS the program, or when a launcher asks for it (CMOD_RUN).
// Its scanners — cmod_js_scan(), cmod_markup_parts(), cmod_tokenise() — are useful
// to other tools of the test bench, and a require of this file should hand them
// over without also running a check and exiting.
if (!defined('CMOD_RUN') && realpath(__FILE__) !== realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) return;

if (PHP_SAPI !== 'cli') {
    // Deliberately CLI-only. Exposing a checker that reads and rewrites files
    // over HTTP would need an authentication path of its own, and there is no
    // reason to have one: this runs before publication, not in production.
    header('HTTP/1.0 403 Forbidden');
    echo 'compliance.php runs from the command line only.';
    exit;
}

$argvRest = array_slice($argv, 1);
$opts     = [];
$targets  = [];
foreach ($argvRest as $a) {
    if (strpos($a, '--') === 0) {
        $kv = explode('=', substr($a, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? true;
    } else {
        $targets[] = $a;
    }
}

/* The modules sit beside tests/ at the root of the repository. CMOD_CUSTOM_DIR
   (constant, or environment variable) points the checker at another folder of
   modules: an installation's Modules/Custom, or a modified copy of a module. */
$customDir = defined('CMOD_CUSTOM_DIR')
    ? CMOD_CUSTOM_DIR
    : (getenv('CMOD_CUSTOM_DIR') ?: dirname(__DIR__, 2));

if (!is_dir($customDir)) {
    echo "Cannot find the modules: $customDir does not exist.\n";
    exit(2);
}

if (!empty($opts['all'])) {
    $targets = [];
    foreach (glob($customDir . '/*/module.json') as $mj) $targets[] = basename(dirname($mj));
}

if (!$targets) {
    echo "Usage: php tests/bin/compliance.php <MODULE> [MODULE…] | --all\n";
    echo "       --inventory              print the database-write inventory and stop\n";
    echo "       --fix-inventory          rewrite the inventory section in the module's .md files\n";
    echo "       --baseline=FILE          fail only on errors beyond the baseline\n";
    echo "       --write-baseline=FILE    record the current errors as the baseline\n";
    exit(2);
}

$baseline = null;
if (isset($opts['baseline'])) {
    $baseline = json_decode((string)@file_get_contents((string)$opts['baseline']), true);
    if (!is_array($baseline)) {
        echo "Cannot read the baseline {$opts['baseline']}.\n";
        exit(2);
    }
}
$record = [];

$status = 0;
foreach ($targets as $name) {
    $name = basename($name);
    $dir  = $customDir . '/' . $name;
    if (!is_dir($dir)) {
        echo "No such module: $name\n";
        $status = 2;
        continue;
    }

    $GLOBALS['cmod_report'] = [];
    $inventory = cmod_check_module($dir, $name, $opts);

    if (!empty($opts['inventory'])) {
        echo cmod_inventory_markdown($inventory);
        continue;
    }
    if (isset($opts['write-baseline'])) {
        $record += cmod_error_counts($name);
    } elseif ($baseline !== null) {
        $status = max($status, cmod_print_against_baseline($name, $baseline));
    } else {
        $status = max($status, cmod_print_report($name));
    }
}

if (isset($opts['write-baseline'])) {
    ksort($record);
    file_put_contents((string)$opts['write-baseline'],
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo count($record) . ' entr(ies), ' . array_sum($record) . " error(s) recorded in {$opts['write-baseline']}\n";
}

exit($status);
