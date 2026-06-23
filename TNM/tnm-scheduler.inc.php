<?php
// ── TNM_Scheduler — Sous-classe Scheduler pour le module TNM ──────────────────
// Utilisé par PdfSchedule.php (programme) et PdfFop.php (plan de cible).
//
// Pass 1 (toujours, portée PAR CRÉNEAU horaire) :
//   Fusionne les poules d'un même (niveau, rencontre) dans le même créneau.
//   Ex : "Tour 1 Poule 1 Rencontre 1" + "Tour 1 Poule 2 Rencontre 1" (même heure)
//   → une seule ligne "Tour 1 Rencontre 1".
//   Portée par créneau : évite de supprimer les sessions d'un autre groupe
//   d'épreuves qui partage le même (niveau, rencontre) mais à une heure distincte.
//
// Pass 2 (FOP uniquement, $FopMergeRounds=true) :
//   Fusionne les rencontres successives d'un même niveau en chaînes.
//   Algorithme : chaque groupe de matchs commence toujours par la Rencontre 1.
//   On ancre chaque chaîne sur une occurrence de R1, puis on cherche R2 dans
//   un intervalle ≤ durée d'une rencontre, puis R3, etc.
//   Cela gère correctement les groupes consécutifs ou chevauchants et est
//   indépendant des codes épreuves (non fiables entre rencontres).
class TNM_Scheduler extends Scheduler {
    /** true = fusionner les rencontres (activé automatiquement par FOP()) */
    public $FopMergeRounds = false;

    function GetSchedule() {
        $schedule = parent::GetSchedule();

        foreach ($schedule as $date => &$sesGroups) {
            foreach ($sesGroups as $sesGroup => &$times) {

                // ── Pass 1 : fusion des poules ────────────────────────────────
                // Portée PAR CRÉNEAU : $seenLR réinitialisé à chaque $time.
                foreach ($times as $time => &$sessions) {
                    $seenLR   = [];
                    $poolDups = [];

                    foreach ($sessions as $sessionKey => &$distances) {
                        $parts = explode('|', $sessionKey);
                        if (count($parts) !== 5) continue;

                        $lrKey = $parts[0] . '|' . $parts[1] . '|' . $parts[4]; // niveau + rencontre

                        if (isset($seenLR[$lrKey])) {
                            $poolDups[] = $sessionKey;
                        } else {
                            $seenLR[$lrKey] = true;
                            $levelName = $parts[0] ?: get_text('LevelNum', 'RoundRobin', (int)$parts[1]);
                            $roundName = get_text('RoundNum', 'RoundRobin', (int)$parts[4]);
                            foreach ($distances as $dist => &$items) {
                                foreach ($items as &$item) {
                                    if ($item->Type === 'R') {
                                        $item->Text = $levelName . ' ' . $roundName;
                                    }
                                }
                            }
                        }
                    }
                    foreach ($poolDups as $key) unset($sessions[$key]);
                }
                // Libère toutes les références PHP pendantes laissées par les foreach &
                unset($sessions, $distances, $items, $item);

                if (!$this->FopMergeRounds) continue;

                // ── Pass 2 : fusion des rencontres (FOP uniquement) ───────────
                //
                // Étape A : indexer toutes les sessions R par (lKey, numéro de rencontre).
                // Chaque entrée : [time, startMin, dur, sessionKey].
                // Plusieurs sessions peuvent avoir le même numéro de rencontre si
                // des groupes d'épreuves différents sont en cours simultanément.
                //
                $byRound = []; // lKey => roundNum => [ {time, startMin, dur, sk}, ... ]

                foreach ($times as $time => $sessions) {
                    foreach ($sessions as $sessionKey => $distances) {
                        $parts = explode('|', $sessionKey);
                        if (count($parts) !== 5) continue;
                        $lKey  = $parts[0] . '|' . $parts[1];
                        $round = (int)$parts[4];

                        foreach ($distances as $dist => $items) {
                            foreach ($items as $item) {
                                if ($item->Type !== 'R') continue;
                                $hh = (int)substr($item->Start, 0, 2);
                                $mm = (int)substr($item->Start, 3, 2);
                                $byRound[$lKey][$round][] = [
                                    'time'     => $time,
                                    'startMin' => $hh * 60 + $mm,
                                    'dur'      => max(1, (int)$item->Duration),
                                    'sk'       => $sessionKey,
                                ];
                                break 2; // un item suffit par session
                            }
                        }
                    }
                }

                // Étape B : construire les chaînes de rencontres.
                // Chaque groupe commence obligatoirement par la Rencontre 1.
                // On ancre sur chaque occurrence de R1 et on cherche les rencontres
                // suivantes par proximité temporelle (gap ≤ durée d'une rencontre).
                //
                $used = []; // lKey => time => roundNum => true

                foreach ($byRound as $lKey => $rounds) {
                    if (empty($rounds[1])) continue;

                    // Trier les occurrences de R1 par heure croissante
                    usort($rounds[1], fn($a, $b) => $a['startMin'] - $b['startMin']);

                    foreach ($rounds[1] as $r1Entry) {
                        $t1 = $r1Entry['time'];
                        if (!empty($used[$lKey][$t1][1])) continue; // déjà dans une chaîne

                        // Construire la chaîne à partir de ce R1
                        $chain = [$t1 => $r1Entry];
                        $used[$lKey][$t1][1] = true;
                        $prev = $r1Entry;
                        $rNum = 2;

                        while (!empty($rounds[$rNum])) {
                            $expected = $prev['startMin'] + $prev['dur'];
                            $best     = null;
                            $bestGap  = PHP_INT_MAX;

                            foreach ($rounds[$rNum] as $entry) {
                                if (!empty($used[$lKey][$entry['time']][$rNum])) continue;
                                $gap = $entry['startMin'] - $expected;
                                // Accepter si le créneau commence après l'attendu et
                                // dans un délai ≤ durée d'une rencontre (marge de planning)
                                if ($gap >= 0 && $gap <= $prev['dur'] && $gap < $bestGap) {
                                    $best    = $entry;
                                    $bestGap = $gap;
                                }
                            }

                            if ($best === null) break; // rencontre suivante introuvable

                            $chain[$best['time']] = $best;
                            $used[$lKey][$best['time']][$rNum] = true;
                            $prev = $best;
                            $rNum++;
                        }

                        if (count($chain) <= 1) continue; // rien à fusionner

                        // Étape C : appliquer la fusion
                        ksort($chain);
                        $sortedTimes = array_keys($chain);
                        $keepTime    = $sortedTimes[0];
                        $last        = $chain[$sortedTimes[count($sortedTimes) - 1]];
                        $totalDur    = ($last['startMin'] + $last['dur']) - $r1Entry['startMin'];

                        // Étendre la durée du créneau conservé
                        $sk = $r1Entry['sk'];
                        if (isset($times[$keepTime][$sk])) {
                            foreach ($times[$keepTime][$sk] as $dist => &$items) {
                                foreach ($items as &$item) {
                                    if ($item->Type === 'R') $item->Duration = $totalDur;
                                }
                            }
                        }

                        // Supprimer les créneaux des rencontres suivantes
                        foreach (array_slice($sortedTimes, 1) as $t) {
                            $rmSk = $chain[$t]['sk'];
                            unset($times[$t][$rmSk]);
                            if (empty($times[$t])) unset($times[$t]);
                        }
                    }
                }
            }
        }

        return $schedule;
    }

    // ── FOP personnalisé : même rendu qu'ianseo, couleurs via AccColors ──────────
    function FOP($Output = true) {
        $this->FopMergeRounds = true;

        // Chargement des AccColors du tournoi
        $colorMap = [];
        $rs = safe_r_sql("SELECT AcDivClass, AcColor FROM AccColors WHERE AcTournament=" . (int)$this->TourId);
        while ($r = safe_fetch($rs)) {
            $colorMap[] = ['pattern' => $r->AcDivClass, 'color' => $r->AcColor];
        }

        // ── Construction du tableau $FOP ──────────────────────────────────────
        // Palette séquentielle pour Q, Z, E (identique au parent FOP)
        $terne = [[0,255,0],[255,153,255],[255,255,204],[153,153,255],[255,153,0],[204,255,204],[51,204,204]];
        $ColorArray = [];
        foreach ($terne as $col) { $ColorArray[] = [$col[0],$col[1],$col[2]]; }
        foreach ($terne as $col) { $ColorArray[] = [$col[1],$col[2],$col[0]]; }
        foreach ($terne as $col) { $ColorArray[] = [$col[2],$col[0],$col[1]]; }
        foreach ($terne as $col) { $ColorArray[] = [$col[0],$col[2],$col[1]]; }
        foreach ($terne as $col) { $ColorArray[] = [$col[1],$col[0],$col[2]]; }
        foreach ($terne as $col) { $ColorArray[] = [$col[2],$col[1],$col[0]]; }
        $ColorAssignment = [];
        $MaxColor  = count($ColorArray);
        $ColorIndex = 0;

        $FOP         = [];
        $Done        = [];
        $DistanceMin = 999;
        $DistanceMax = 0;

        foreach ($this->GetSchedule() as $Date => $SesGroups) {
            foreach ($SesGroups as $SesGroup => $Times) {
                $FOP[$SesGroup][$Date] = ['min' => 0, 'max' => 0, 'times' => []];
                ksort($Times);

                foreach ($Times as $Time => $Sessions) {
                    foreach ($Sessions as $Session => $Distances) {
                        foreach ($Distances as $Distance => $Items) {
                            foreach ($Items as $Item) {

                                // Z sans cible → aucun rendu, pas de slot à créer
                                if ($Item->Type === 'Z' && !$Item->Target) continue;

                                $slot = &$FOP[$SesGroup][$Date]['times'][$Time][$Distance];
                                if (empty($slot)) {
                                    $slot = ['time' => '', 'text' => [], 'targets' => [], 'min' => 0, 'max' => 0];
                                }
                                if (empty($slot['time'])) {
                                    $slot['time'] = $Item->Start;
                                    if ($Item->Duration) {
                                        $slot['time'] .= '-' . addMinutes($Item->Start, $Item->Duration);
                                    }
                                }

                                if ($Item->Type === 'Z') {
                                    // Ligne manuelle avec cibles pré-affectées (format "range@dist@event@face,...")
                                    foreach (array_merge(explode(' - ', $Item->Title), explode(' - ', $Item->SubTitle), explode(' - ', $Item->Text)) as $txt) {
                                        if ($txt && !in_array($txt, $slot['text'])) $slot['text'][] = strip_tags($txt);
                                    }
                                    foreach (explode(',', $Item->Target) as $blk) {
                                        $tp  = explode('@', $blk);
                                        $rng = explode('-', $tp[0]);
                                        $bl  = new TargetButt();
                                        $bl->Distance = $tp[1];
                                        $DistanceMin = min($DistanceMin, $tp[1]);
                                        $DistanceMax = max($DistanceMax, $tp[1]);
                                        if (!empty($tp[2])) $bl->Event  = $tp[2];
                                        if (!empty($tp[3])) $bl->Target = $tp[3];
                                        $r0 = $rng[0]; $r1 = count($rng) > 1 ? $rng[1] : $rng[0];
                                        $bl->Range = [$r0, $r1];
                                        $slot['min'] = $slot['min'] ? min($slot['min'], $r0) : $r0;
                                        $FOP[$SesGroup][$Date]['min'] = $FOP[$SesGroup][$Date]['min'] ? min($FOP[$SesGroup][$Date]['min'], $r0) : $r0;
                                        $slot['max'] = max($slot['max'], $r1);
                                        $FOP[$SesGroup][$Date]['max'] = max($FOP[$SesGroup][$Date]['max'], $r1);
                                        if (!in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                    }

                                } elseif (!$Item->Warmup) {

                                    if ($Item->Type === 'R' && empty($Done[$Date][$Time]['R'][$Distance])) {
                                        $Done[$Date][$Time]['R'][$Distance] = true;
                                        $this->_fopBuildMatchSlot(
                                            $Date, $Time, $Distance,
                                            $FOP[$SesGroup][$Date],
                                            $colorMap, $DistanceMin, $DistanceMax
                                        );
                                    } elseif (($Item->Type === 'Q' || $Item->Type === 'E') && empty($Done[$Date][$Time][$Item->Type][$Distance])) {
                                        $Done[$Date][$Time][$Item->Type][$Distance] = true;
                                        $tmp2 = preg_replace('/\([^)]+\)/sim', '', $Item->Title.' - '.$Item->SubTitle.' - '.$Item->Text);
                                        foreach (preg_split('/( - )|(, )/', $tmp2) as $txt) {
                                            if ($txt && !in_array($txt, $slot['text'])) $slot['text'][] = strip_tags($txt);
                                        }
                                        if ($Item->Type === 'Q') {
                                            if ($Item->Target) {
                                                foreach (explode(',', $Item->Target) as $blk) {
                                                    $tp  = explode('@', $blk);
                                                    $rng = explode('-', $tp[0]);
                                                    $bl  = new TargetButt();
                                                    $bl->Distance = $tp[1];
                                                    $DistanceMin = min($DistanceMin, $tp[1]);
                                                    $DistanceMax = max($DistanceMax, $tp[1]);
                                                    if (!empty($tp[2])) $bl->Event  = $tp[2];
                                                    if (!empty($tp[3])) $bl->Target = $tp[3];
                                                    $cKey = "{$bl->Distance}-{$bl->Event}";
                                                    if (empty($ColorAssignment[$cKey])) { $ColorAssignment[$cKey] = $ColorArray[$ColorIndex % $MaxColor]; $ColorIndex++; }
                                                    $bl->Colour = $ColorAssignment[$cKey];
                                                    $r0 = $rng[0]; $r1 = count($rng) > 1 ? $rng[1] : $rng[0];
                                                    $bl->Range = [$r0, $r1];
                                                    $slot['min'] = $slot['min'] ? min($slot['min'], $r0) : $r0;
                                                    $FOP[$SesGroup][$Date]['min'] = $FOP[$SesGroup][$Date]['min'] ? min($FOP[$SesGroup][$Date]['min'], $r0) : $r0;
                                                    $slot['max'] = max($slot['max'], $r1);
                                                    $FOP[$SesGroup][$Date]['max'] = max($FOP[$SesGroup][$Date]['max'], $r1);
                                                    if (!in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                }
                                            } elseif (is_numeric($Date[0])) {
                                                $SesFilter = $this->SesLocations ? " AND SesLocation IN (".implode(',', StrSafe_DB($this->SesLocations)).") AND IF(SesFirstTarget>0, QuTarget BETWEEN SesFirstTarget AND SesFirstTarget+SesTar4Session-1, TRUE)" : '';
                                                $rsD = safe_r_sql("SELECT * FROM DistanceInformation WHERE DiTournament={$this->TourId} AND DiType='Q' AND DiDistance=$Distance AND ((DiDay='$Date' AND DiStart='$Time') OR (DiDay=0 AND DiSession=$Session))");
                                                while ($di = safe_fetch($rsD)) {
                                                    $rsQ = safe_r_sql("SELECT DISTINCT SesAth4Target, QuTarget TargetNo,
                                                            IFNULL(Td{$di->DiDistance},'.{$di->DiDistance}.') AS Distance,
                                                            TarDescr, TarDim
                                                        FROM Entries
                                                        INNER JOIN Qualifications ON EnId=QuId
                                                        INNER JOIN DistanceInformation ON QuSession=DiSession
                                                            AND DiTournament={$this->TourId} AND DiDistance={$di->DiDistance}
                                                            AND ((DiDay='$Date' AND DiStart='$Time') OR (DiDay=0 AND DiSession=$Session))
                                                        INNER JOIN Session ON SesOrder=QuSession AND SesType='Q' AND SesTournament={$this->TourId} $SesFilter
                                                        LEFT JOIN TournamentDistances ON CONCAT(TRIM(EnDivision),TRIM(EnClass)) LIKE TdClasses AND EnTournament=TdTournament
                                                        LEFT JOIN (SELECT TfId, TarDescr, TfW{$di->DiDistance} AS TarDim, TfTournament
                                                            FROM TargetFaces INNER JOIN Targets ON TfT{$di->DiDistance}=TarId) tf
                                                            ON TfTournament=EnTournament AND TfId=EnTargetFace
                                                        WHERE EnTournament={$this->TourId}
                                                        ".($this->TargetsInvolved ? ' HAVING '.sprintf($this->TargetsInvolved, 'TargetNo') : '')."
                                                        ORDER BY TargetNo, Distance DESC, TargetNo, TarDescr, TarDim");
                                                    $bl = null; $k = '';
                                                    while ($w = safe_fetch($rsQ)) {
                                                        $cKey = "{$w->TarDescr} {$w->TarDim}";
                                                        if (empty($ColorAssignment[$cKey])) { $ColorAssignment[$cKey] = $ColorArray[$ColorIndex % $MaxColor]; $ColorIndex++; }
                                                        if (!$bl || $k !== "{$w->TarDescr} {$w->TarDim} {$w->Distance}") {
                                                            if ($bl && !in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                            $bl = new TargetButt();
                                                            $bl->Target    = get_text($w->TarDescr)." $w->TarDim cm";
                                                            $bl->Distance  = $w->Distance;
                                                            $bl->Event     = get_text('Q-Session', 'Tournament');
                                                            $bl->ArcTarget = $w->SesAth4Target;
                                                            $bl->Range     = [$w->TargetNo, $w->TargetNo];
                                                            $bl->Colour    = $ColorAssignment[$cKey];
                                                            $DistanceMin = min($DistanceMin, $w->Distance);
                                                            $DistanceMax = max($DistanceMax, $w->Distance);
                                                            if (!$slot['min']) $slot['min'] = $w->TargetNo;
                                                            if (!$FOP[$SesGroup][$Date]['min']) $FOP[$SesGroup][$Date]['min'] = $w->TargetNo;
                                                        } elseif ($w->TargetNo === $bl->Range[1] + 1) {
                                                            $bl->Range[1] = $w->TargetNo;
                                                        } else {
                                                            if (!in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                            $bl = new TargetButt();
                                                            $bl->Target    = get_text($w->TarDescr)." $w->TarDim cm";
                                                            $bl->Distance  = $w->Distance;
                                                            $bl->Event     = get_text('Q-Session', 'Tournament');
                                                            $bl->ArcTarget = $w->SesAth4Target;
                                                            $bl->Range     = [$w->TargetNo, $w->TargetNo];
                                                            $bl->Colour    = $ColorAssignment[$cKey];
                                                        }
                                                        $slot['min'] = min($slot['min'] ?: $w->TargetNo, $w->TargetNo);
                                                        $FOP[$SesGroup][$Date]['min'] = min($FOP[$SesGroup][$Date]['min'] ?: $w->TargetNo, $w->TargetNo);
                                                        $slot['max'] = max($slot['max'], $w->TargetNo);
                                                        $FOP[$SesGroup][$Date]['max'] = max($FOP[$SesGroup][$Date]['max'], $w->TargetNo);
                                                        $k = "{$w->TarDescr} {$w->TarDim} {$w->Distance}";
                                                    }
                                                    if ($bl && !in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                }
                                            }
                                        }
                                    }

                                } else {
                                    // Warmup : texte
                                    if ($Item->Comments) {
                                        if (!in_array($Item->Comments, $slot['text'])) $slot['text'][] = strip_tags($Item->Comments);
                                    } else {
                                        $lnk = ($Item->Type === 'I' || $Item->Type === 'T')
                                            ? $Item->Text.': '.$Item->Events.' '.get_text('WarmUp', 'Tournament')
                                            : ' '.get_text('WarmUp', 'Tournament');
                                        if (!in_array($lnk, $slot['text'])) $slot['text'][] = strip_tags($lnk);
                                    }
                                    // Warmup : cibles depuis la DB
                                    if (empty($Done[$Date][$Time][$Item->Type][$Distance])) {
                                        $Done[$Date][$Time][$Item->Type][$Distance] = true;
                                        if ($Item->Type === 'Q') {
                                            if ($Item->Target) {
                                                foreach (explode(',', $Item->Target) as $blk) {
                                                    $tp  = explode('@', $blk);
                                                    $rng = explode('-', $tp[0]);
                                                    $bl  = new TargetButt();
                                                    $bl->Distance = $tp[1];
                                                    $DistanceMin = min($DistanceMin, $tp[1]);
                                                    $DistanceMax = max($DistanceMax, $tp[1]);
                                                    if (!empty($tp[2])) $bl->Event  = $tp[2];
                                                    if (!empty($tp[3])) $bl->Target = $tp[3];
                                                    $r0 = $rng[0]; $r1 = count($rng) > 1 ? $rng[1] : $rng[0];
                                                    $bl->Range = [$r0, $r1];
                                                    $slot['min'] = $slot['min'] ? min($slot['min'], $r0) : $r0;
                                                    $FOP[$SesGroup][$Date]['min'] = $FOP[$SesGroup][$Date]['min'] ? min($FOP[$SesGroup][$Date]['min'], $r0) : $r0;
                                                    $slot['max'] = max($slot['max'], $r1);
                                                    $FOP[$SesGroup][$Date]['max'] = max($FOP[$SesGroup][$Date]['max'], $r1);
                                                    if (!in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                }
                                            } else {
                                                $SesFilter = $this->SesLocations ? " AND SesLocation IN (".implode(',', StrSafe_DB($this->SesLocations)).") AND IF(SesFirstTarget>0, QuTarget BETWEEN SesFirstTarget AND SesFirstTarget+SesTar4Session-1, TRUE)" : '';
                                                $rsD = safe_r_sql("SELECT * FROM DistanceInformation WHERE DiTournament={$this->TourId} AND DiDay='$Date' AND DiWarmStart='$Time'");
                                                while ($di = safe_fetch($rsD)) {
                                                    $rsQ = safe_r_sql("SELECT DISTINCT SesAth4Target, QuTarget AS TargetNo,
                                                            IFNULL(Td{$di->DiDistance},'.{$di->DiDistance}.') AS Distance,
                                                            TarDescr, TarDim, DiDay, DiStart, DiWarmStart
                                                        FROM Entries
                                                        INNER JOIN Qualifications ON EnId=QuId
                                                        INNER JOIN DistanceInformation ON QuSession=DiSession
                                                            AND DiTournament={$this->TourId} AND DiDistance={$di->DiDistance}
                                                            AND DiDay='$Date' AND DiWarmStart='$Time'
                                                        INNER JOIN Session ON SesOrder=QuSession AND SesType='Q' AND SesTournament={$this->TourId} $SesFilter
                                                        LEFT JOIN TournamentDistances ON CONCAT(TRIM(EnDivision),TRIM(EnClass)) LIKE TdClasses AND EnTournament=TdTournament
                                                        LEFT JOIN (SELECT TfId, TarDescr, TfW{$di->DiDistance} AS TarDim, TfTournament
                                                            FROM TargetFaces INNER JOIN Targets ON TfT{$di->DiDistance}=TarId) tf
                                                            ON TfTournament=EnTournament AND TfId=EnTargetFace
                                                        WHERE EnTournament={$this->TourId}
                                                        ORDER BY TargetNo, Distance DESC, TargetNo, TarDescr, TarDim");
                                                    $bl = null; $k = '';
                                                    while ($w = safe_fetch($rsQ)) {
                                                        if (!$bl || $k !== "{$w->TarDescr} {$w->TarDim} {$w->Distance}") {
                                                            if ($bl && !in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                            $bl = new TargetButt();
                                                            $bl->Target    = get_text($w->TarDescr)." $w->TarDim cm";
                                                            $bl->Distance  = $w->Distance;
                                                            $bl->Event     = get_text('WarmUp', 'Tournament');
                                                            $bl->ArcTarget = $w->SesAth4Target;
                                                            $bl->Range     = [$w->TargetNo, $w->TargetNo];
                                                            $DistanceMin = min($DistanceMin, $w->Distance);
                                                            $DistanceMax = max($DistanceMax, $w->Distance);
                                                            if (!$slot['min']) $slot['min'] = $w->TargetNo;
                                                            if (!$FOP[$SesGroup][$Date]['min']) $FOP[$SesGroup][$Date]['min'] = $w->TargetNo;
                                                        } elseif ($w->TargetNo === $bl->Range[1] + 1) {
                                                            $bl->Range[1] = $w->TargetNo;
                                                        } else {
                                                            if (!in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                            $bl = new TargetButt();
                                                            $bl->Target    = get_text($w->TarDescr)." $w->TarDim cm";
                                                            $bl->Distance  = $w->Distance;
                                                            $bl->Event     = get_text('WarmUp', 'Tournament');
                                                            $bl->ArcTarget = $w->SesAth4Target;
                                                            $bl->Range     = [$w->TargetNo, $w->TargetNo];
                                                        }
                                                        $slot['min'] = min($slot['min'] ?: $w->TargetNo, $w->TargetNo);
                                                        $FOP[$SesGroup][$Date]['min'] = min($FOP[$SesGroup][$Date]['min'] ?: $w->TargetNo, $w->TargetNo);
                                                        $slot['max'] = max($slot['max'], $w->TargetNo);
                                                        $FOP[$SesGroup][$Date]['max'] = max($FOP[$SesGroup][$Date]['max'], $w->TargetNo);
                                                        $k = "{$w->TarDescr} {$w->TarDim} {$w->Distance}";
                                                    }
                                                    if ($bl && !in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                }
                                            }
                                        } elseif ($Item->Type === 'I' || $Item->Type === 'T') {
                                            $rsW = safe_r_sql("SELECT FwEvent, FwTargets, FwOptions, EvDistance, TarDescr, EvTargetSize, EvMaxTeamPerson
                                                FROM FinWarmup
                                                INNER JOIN Events ON FwEvent=EvCode AND FwTeamEvent=EvTeamEvent AND FwTournament=EvTournament
                                                LEFT JOIN Targets ON EvFinalTargetType=TarId
                                                LEFT JOIN Session ON SesTournament=FwTournament AND SesType='F'
                                                    AND CONCAT(FwDay,' ',FwMatchTime) BETWEEN SesDtStart AND SesDtEnd
                                                    AND IF(SesEvents='',TRUE,FIND_IN_SET(CONCAT(EvTeamEvent,EvCode),SesEvents))
                                                WHERE FwTournament=".(int)$this->TourId."
                                                    AND DATE_FORMAT(FwDay,'%Y-%m-%d')='$Date' AND FwTime='$Time' AND FwTargets!=''
                                                ".($this->SesLocations ? " AND SesLocation IN (".implode(',', StrSafe_DB($this->SesLocations)).")" : '')."
                                                ORDER BY FwTargets, FwEvent");
                                            $rows = []; $RowTgts = [];
                                            while ($u = safe_fetch($rsW)) {
                                                foreach (explode(',', $u->FwTargets) as $range) {
                                                    $tmp2  = explode('-', $range);
                                                    $tList = count($tmp2) > 1 ? range((int)$tmp2[0], (int)$tmp2[1]) : [(int)$tmp2[0]];
                                                    foreach ($tList as $tgt) {
                                                        $DistanceMin = min($DistanceMin, $u->EvDistance);
                                                        $DistanceMax = max($DistanceMax, $u->EvDistance);
                                                        $rows[$u->FwEvent][$tgt] = [
                                                            'd'  => $u->EvDistance,
                                                            'e'  => $u->FwEvent,
                                                            'f'  => get_text($u->TarDescr)." $u->EvTargetSize cm",
                                                            'ph' => get_text('WarmUp', 'Tournament'),
                                                            'mp' => $u->EvMaxTeamPerson,
                                                            'l'  => empty($RowTgts[$tgt]) ? 0 : 1,
                                                        ];
                                                        $RowTgts[$tgt] = 1;
                                                    }
                                                }
                                            }
                                            $bl = null; $k = '';
                                            foreach ($rows as $tgts) {
                                                ksort($tgts);
                                                foreach ($tgts as $tgt => $def) {
                                                    if (!$bl || $k !== "{$def['d']}-{$def['e']}") {
                                                        if ($bl && !in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                        $bl = new TargetButt();
                                                        $bl->Target   = $def['f'];
                                                        $bl->Event    = $def['e'];
                                                        $bl->Distance = $def['d'];
                                                        $bl->Range    = [$tgt, $tgt];
                                                        if (!empty($def['ph'])) $bl->Phase = $def['ph'];
                                                        if (!empty($def['l']))  $bl->Line  = $def['l'];
                                                        if (!$slot['min']) $slot['min'] = $tgt;
                                                        if (!$FOP[$SesGroup][$Date]['min']) $FOP[$SesGroup][$Date]['min'] = $tgt;
                                                    } elseif ($tgt === $bl->Range[1] + 1) {
                                                        $bl->Range[1] = $tgt;
                                                    } else {
                                                        if (!in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                        $bl = new TargetButt();
                                                        $bl->Target   = $def['f'];
                                                        $bl->Event    = $def['e'];
                                                        $bl->Distance = $def['d'];
                                                        $bl->Range    = [$tgt, $tgt];
                                                        if (!empty($def['ph'])) $bl->Phase = $def['ph'];
                                                        if (!empty($def['l']))  $bl->Line  = $def['l'];
                                                    }
                                                    $slot['min'] = min($slot['min'] ?: $tgt, $tgt);
                                                    $FOP[$SesGroup][$Date]['min'] = min($FOP[$SesGroup][$Date]['min'] ?: $tgt, $tgt);
                                                    $slot['max'] = max($slot['max'], $tgt);
                                                    $FOP[$SesGroup][$Date]['max'] = max($FOP[$SesGroup][$Date]['max'], $tgt);
                                                    $k = "{$def['d']}-{$def['e']}";
                                                }
                                                if ($bl && !in_array($bl, $slot['targets'])) $slot['targets'][] = $bl;
                                                $k = '';
                                            }
                                        }
                                    }
                                }
                                unset($slot);
                            }
                        }
                    }
                }
            }
        }

        // ── Rendu PDF (reproduction fidèle du parent) ─────────────────────────
        include_once('Common/pdf/ResultPDF.inc.php');

        $FirstPage     = true;
        $DistHeight    = 4;
        $TgtHeight     = 3;
        $EventHeight   = 4;
        $PhaseHeight   = 4;
        $TgtFaceHeight = 3;
        $ArcTgtHeight  = 2;
        $TimeWidth     = 20;

        foreach ($FOP as $GroupSession => $Days) {
            foreach ($Days as $Day => $Blocks) {
                if (!$Blocks['min'] && !$Blocks['max']) continue;
                $Portrait = ($Blocks['max'] - $Blocks['min'] <= 32);
                if ($FirstPage) {
                    $pdf = new ResultPDF(get_text('FopSetup'), $Portrait);
                    $pdf->Version = $this->FopVersion;
                    $pdf->SetCellPadding(0.1);
                    $pdf->SetFillColor(200);
                    $pdf->SetTextColor(0);
                } else {
                    $pdf->AddPage($Portrait ? 'P' : 'L');
                }
                $FirstPage = false;
                $FirstDate = true;

                if ($this->FopSingleLocations && !empty($this->LocationsToPrint[0]->Loc)) {
                    $pdf->SetFont('', 'B', 14);
                    $pdf->setFillColor(200);
                    $pdf->Cell(0, 0, $this->LocationsToPrint[0]->Loc, '', 1, 'C', 1);
                }
                $Title = '';
                if ($Day[0] !== 'S') {
                    $Title = formatTextDate($Day, true) . ($GroupSession ? " - $GroupSession" : '');
                    $pdf->SetFont('', 'B', $Portrait ? 18 : 25);
                    $pdf->Cell(0, 0, $Title, 'B', 1, ($Portrait && $this->FopVersionText) ? 'L' : 'C');
                    $pdf->dy(-5, true);
                    $pdf->SetFontSize(7);
                    $pdf->setX($pdf->getPageWidth() - 50);
                    $pdf->Cell(40, 0, $this->FopVersionText, '', 0, 'R');
                }
                $pdf->SetFont('', '', 8);

                $TgtWidthOrg = min(9, ($pdf->getPageWidth() - 21 - $TimeWidth) / (1 + $Blocks['max'] - $Blocks['min']));
                $pdf->ln(6);

                $SecondColumn   = 0;
                if ($Blocks['max'] - $Blocks['min'] < 4) {
                    $SecondColumn = 20 + (($pdf->getPageWidth() - 30) / 2);
                }

                $CurrentXOffset = 0;
                $StartY         = 0;
                $MaxY           = 0;
                $LastBlock      = end($Blocks['times']);

                foreach ($Blocks['times'] as $Time => $Distances) {
                    $oldBlockTime = '';
                    foreach ($Distances as $SubDist => $Block) {
                        if (!($CurrentXOffset % 2) || !$SecondColumn) {
                            if (!$pdf->SamePage(11 + $DistHeight + $TgtHeight + $EventHeight + $PhaseHeight + $TgtFaceHeight + $ArcTgtHeight)) {
                                $pdf->AddPage();
                                $FirstDate = true;
                                if ($this->FopSingleLocations) {
                                    $pdf->SetFont('', 'B', 12);
                                    $pdf->setFillColor(200);
                                    $pdf->Cell(0, 0, $this->FopLocations[0]->Loc, '', 1, 'C', 1);
                                }
                                $pdf->SetFont('', 'B', 16);
                                $pdf->Cell(0, 0, $Title . ' (' . get_text('Continue') . ')', 'B', 1, 'C');
                                $pdf->dy(-4, true);
                                $pdf->SetFontSize(7);
                                $pdf->Cell(0, 0, $this->FopVersionText, '', 0, 'R');
                                $pdf->SetFont('', '', 8);
                                $pdf->ln(7);
                                $MaxY = 0;
                            }
                        }
                        if (!$FirstDate && ($Block !== $LastBlock || !$SecondColumn)) {
                            $pdf->setY($MaxY, false);
                            $pdf->SetLineStyle(['width' => 0.5, 'color' => [128]]);
                            $tmp = $pdf->getMargins();
                            $pdf->Line($tmp['left'], $pdf->getY(), $tmp['left'] + $pdf->getPageWidth() - $SecondColumn - 20, $pdf->getY());
                            $pdf->SetLineStyle(['width' => .1, 'color' => [0]]);
                            $pdf->ln(2);
                        }
                        $FirstDate = false;

                        $Y = $pdf->getY();
                        if ($CurrentXOffset % 2 && $SecondColumn) {
                            $pdf->SetLeftMargin($SecondColumn);
                            $pdf->setY($StartY, true);
                            $Y = $pdf->getY();
                        } else {
                            $pdf->SetLeftMargin(10);
                            $pdf->setX(10);
                        }
                        $CurrentXOffset++;
                        $StartY = $Y;

                        $pdf->SetFont('', 'B', 10);
                        $pdf->Cell($TimeWidth, 0, $oldBlockTime === $Block['time'] ? '' : $Block['time'], 0, 1);
                        $oldBlockTime = $Block['time'];
                        $pdf->SetFont('', '', 7);
                        foreach ($Block['text'] as $txt) {
                            $pdf->Cell($TimeWidth, 3, mb_substr($txt, 0, 30, 'UTF-8'), '', 1);
                        }
                        $pdf->setY($Y);
                        $MaxOffset = 0;
                        $pdf->SetFont('', '', 8);

                        $TargetFacesBlocks = [];
                        $CurFace           = '£$';
                        $ArcPerTarget      = [];
                        $CurArcNum         = -10;
                        $TgtWidth          = $TgtWidthOrg;
                        $Max               = $Blocks['max'];
                        $Min               = $Blocks['min'];

                        $tmp = $pdf->getMargins();
                        $pdf->setX($tmp['left'] + 1 + $TimeWidth);
                        $this->PrintTargetLinePdf($pdf, $TgtWidth, $TgtHeight, $Min, $Max);
                        $pdf->ln();
                        $OrgY    = $pdf->getY();
                        $larCell = $TgtWidth / 5;

                        foreach ($Block['targets'] as $Range) {
                            $Y = $OrgY;
                            $pdf->SetFillColor($Range->Colour[0], $Range->Colour[1], $Range->Colour[2]);
                            $RangeWidth = (1 + $Range->Range[1] - $Range->Range[0]) * $TgtWidth;
                            $RangeStart = $tmp['left'] + 1 + $TimeWidth + $TgtWidth * ($Range->Range[0] - $Blocks['min']);
                            $Offset     = min(8, max(0, ((intval($DistanceMax) - intval($DistanceMin)) / 5) - (intval($Range->Distance) / 5)));
                            $MaxOffset  = max($MaxOffset, $Offset);

                            if (!empty($Range->Line)) {
                                $Y += $DistHeight + $Offset + $EventHeight + ($Range->Phase ? $PhaseHeight : 0) + $ArcTgtHeight + 3.5;
                            }

                            $pdf->setXY($RangeStart, $Y);
                            $pdf->Cell($RangeWidth, $DistHeight + $Offset, $Range->Distance, '1', 0, 'C');
                            $Y += $DistHeight + $Offset;

                            $pdf->SetFont('', 'B');
                            $pdf->setXY($RangeStart, $Y);
                            $pdf->Cell($RangeWidth, $EventHeight, $Range->Event, 'LTR', 0, 'C', 1);
                            $pdf->SetFont('', '');
                            $Y += $EventHeight;
                            $pdf->setY($Y);

                            if ($Range->Phase) {
                                $pdf->SetFont('', 'B');
                                $pdf->setXY($RangeStart, $Y);
                                $pdf->Cell($RangeWidth, $PhaseHeight, $Range->Phase, 'LBR', 0, 'C', 1);
                                $pdf->SetFont('', '');
                                $Y += $PhaseHeight;
                            }

                            if ($Range->ArcTarget && $Range->ArcTarget <= 4) {
                                foreach (range($Range->Range[0], $Range->Range[1]) as $tgt) {
                                    $colX = $tmp['left'] + 1 + $TimeWidth + $TgtWidth * ($tgt - $Blocks['min']);
                                    $pdf->SetFillColor(255);
                                    $pdf->Rect($colX, $Y, $TgtWidth, $ArcTgtHeight, "DF");
                                    $pdf->SetFillColor(127);
                                    if ($Range->ArcTarget & 4) {
                                        $pdf->Rect($colX + 1 * $larCell - 0.5, $Y + 0.5, $larCell, 1, "DF");
                                        $pdf->Rect($colX + 2 * $larCell - 0.5, $Y + 0.5, $larCell, 1, "DF");
                                        $pdf->Rect($colX + 3 * $larCell - 0.5, $Y + 0.5, $larCell, 1, "DF");
                                        $pdf->Rect($colX + 4 * $larCell - 0.5, $Y + 0.5, $larCell, 1, "DF");
                                    } else {
                                        if ($Range->ArcTarget & 1) {
                                            $pdf->Rect($colX + 2 * $larCell, $Y + 0.5, $larCell, 1, "DF");
                                        }
                                        if ($Range->ArcTarget & 2) {
                                            $pdf->Rect($colX + 1 * $larCell, $Y + 0.5, $larCell, 1, "DF");
                                            $pdf->Rect($colX + 3 * $larCell, $Y + 0.5, $larCell, 1, "DF");
                                        }
                                    }
                                }
                                $Y += $ArcTgtHeight;
                                $GetArcPerTarget = false;
                            } else {
                                $GetArcPerTarget = true;
                            }

                            if ($CurFace !== $Range->Target) {
                                $CurFace = $Range->Target;
                                $TargetFacesBlocks[$CurFace][] = [$Range->Range[0], $Range->Range[1], $Y];
                                $TargetIndex = count($TargetFacesBlocks[$CurFace]) - 1;
                                $CurArcNum   = -10;
                            }
                            if ($Range->Range[0] < $TargetFacesBlocks[$CurFace][$TargetIndex][0]) $TargetFacesBlocks[$CurFace][$TargetIndex][0] = $Range->Range[0];
                            if ($Range->Range[1] > $TargetFacesBlocks[$CurFace][$TargetIndex][1]) $TargetFacesBlocks[$CurFace][$TargetIndex][1] = $Range->Range[1];
                            $TargetFacesBlocks[$CurFace][$TargetIndex][2] = max($Y, $TargetFacesBlocks[$CurFace][$TargetIndex][2]);

                            if ($GetArcPerTarget) {
                                if ($CurArcNum !== $Range->ArcTarget) {
                                    $CurArcNum = $Range->ArcTarget;
                                    $ArcPerTarget[$CurArcNum][] = [$Range->Range[0], $Range->Range[1], $Y];
                                    $ArcPerTargetIndex = count($ArcPerTarget[$CurArcNum]) - 1;
                                }
                                if ($Range->Range[0] < $ArcPerTarget[$CurArcNum][$ArcPerTargetIndex][0]) $ArcPerTarget[$CurArcNum][$ArcPerTargetIndex][0] = $Range->Range[0];
                                if ($Range->Range[1] > $ArcPerTarget[$CurArcNum][$ArcPerTargetIndex][1]) $ArcPerTarget[$CurArcNum][$ArcPerTargetIndex][1] = $Range->Range[1];
                                $ArcPerTarget[$CurArcNum][$ArcPerTargetIndex][2] = max($Y, $ArcPerTarget[$CurArcNum][$ArcPerTargetIndex][2]);
                            }
                        } // foreach targets

                        $pdf->SetFontSize(7);
                        $Gap = $pdf->getY();
                        if (empty($Block['targets'])) $Gap = $pdf->getY() + 10;

                        foreach ($TargetFacesBlocks as $Targetface => $Ranges) {
                            if (!$Targetface) continue;
                            foreach ($Ranges as $Range) {
                                $RangeWidth = (1 + $Range[1] - $Range[0]) * $TgtWidth;
                                $RangeStart = $tmp['left'] + 1 + $TimeWidth + $TgtWidth * ($Range[0] - $Blocks['min']);
                                $pdf->setXY($RangeStart, $Range[2]);
                                $pdf->Cell($RangeWidth, $TgtFaceHeight, $Targetface, 'LR', 1, 'C');
                                $Gap = max($Gap, $pdf->getY());
                            }
                        }
                        foreach ($ArcPerTarget as $Targetface => $Ranges) {
                            if (!$Targetface) continue;
                            foreach ($Ranges as $Range) {
                                $RangeWidth = (1 + $Range[1] - $Range[0]) * $TgtWidth;
                                $RangeStart = $tmp['left'] + 1 + $TimeWidth + $TgtWidth * ($Range[0] - $Blocks['min']);
                                $pdf->setXY($RangeStart, $Range[2] + $TgtFaceHeight);
                                $pdf->Cell($RangeWidth, $ArcTgtHeight, $Targetface . ' Arc/Tgt', 'LR', 1, 'C');
                                $Gap = max($Gap, $pdf->getY());
                            }
                        }
                        $pdf->SetFontSize(8);
                        $pdf->SetY($Gap + 3, true);
                        $MaxY = max($MaxY, $pdf->getY());
                    } // foreach Distances
                } // foreach Times
            } // foreach Days
        } // foreach FOP

        if (empty($pdf)) {
            $pdf = new ResultPDF(get_text('FopSetup'));
        }
        if ($Output) {
            $pdf->Output();
            die();
        }
        return $pdf;
    }

    // ── Construit les TargetButt pour un créneau de matchs Round Robin ────────
    private function _fopBuildMatchSlot($Date, $Time, $Distance, &$DayBlock, $colorMap, &$DistanceMin, &$DistanceMax) {
        $TimeFormat = get_text('TimeFmt');
        $sql = "SELECT '' as Warmup, RrMatchEvent as FSEvent, RrMatchTeam as FSTeamEvent,
                    concat_ws('-', RrMatchLevel, RrMatchGroup, RrMatchRound) as GrPhase,
                    RrMatchMatchNo as FsMatchNo, RrMatchTarget as FsTarget, '' as TargetTo,
                    RrLevArrows as EvMatchArrowsNo, RrLevMatchMode as EvMatchMode,
                    EvMixedTeam, EvTeamEvent, UNIX_TIMESTAMP(RrMatchScheduledDate) as SchDate,
                    DATE_FORMAT(RrMatchScheduledTime,'$TimeFormat') as SchTime, EvFinalFirstPhase,
                    RrLevEnds AS `ends`, RrLevArrows AS `arrows`, RrLevSO AS `so`,
                    EvMaxTeamPerson,
                    group_concat(distinct if(instr('ABCD', right(RrMatchTarget,1))>0, right(RrMatchTarget,1), '')
                        order by right(RrMatchTarget,1) separator '') as Persons,
                    RrMatchScheduledDate as FSScheduledDate,
                    RrMatchScheduledTime as FSScheduledTime, EvDistance, TarDescr, EvTargetSize,
                    EvWinnerFinalRank
                FROM RoundRobinMatches
                INNER JOIN RoundRobinLevel ON RrLevTournament=RrMatchTournament AND RrLevTeam=RrMatchTeam
                    AND RrLevEvent=RrMatchEvent AND RrLevLevel=RrMatchLevel
                INNER JOIN Events ON EvCode=RrMatchEvent AND EvTeamEvent=RrMatchTeam AND EvTournament=RrMatchTournament
                LEFT JOIN Session ON SesTournament=EvTournament AND SesType='R'
                    AND concat(RrMatchScheduledDate,' ',RrMatchScheduledTime) BETWEEN SesDtStart AND SesDtEnd
                    AND if(SesEvents='', true, find_in_set(concat(EvTeamEvent,EvCode), SesEvents))
                LEFT JOIN Targets ON EvFinalTargetType=TarId
                WHERE RrMatchTournament=" . (int)$this->TourId . "
                    AND RrMatchScheduledDate='$Date' AND RrMatchScheduledTime='$Time'
                    AND RrMatchTarget!=''
                " . ($this->SesLocations ? " AND SesLocation IN (" . implode(',', StrSafe_DB($this->SesLocations)) . ")" : '') . "
                GROUP BY RrMatchEvent, RrMatchTarget+0
                " . ($this->TargetsInvolved ? ' HAVING ' . sprintf($this->TargetsInvolved, 'FsTarget+0') : '') . "
                ORDER BY Warmup ASC, RrMatchEvent, RrMatchTarget ASC, RrMatchMatchNo ASC";

        $rows = [];
        $t    = safe_r_sql($sql);
        while ($u = safe_fetch($t)) {
            $EndsArrows = get_text('EventDetailsShort', 'Tournament', [$u->ends, $u->arrows]);
            if (!in_array($EndsArrows, $DayBlock['times'][$Time][$Distance]['text'])) {
                $DayBlock['times'][$Time][$Distance]['text'][] = $EndsArrows;
            }

            $u->FsTarget  = intval($u->FsTarget);
            $DistanceMin  = min($DistanceMin, $u->EvDistance);
            $DistanceMax  = max($DistanceMax, $u->EvDistance);

            $rows[$u->FSEvent][$u->FsTarget] = [
                'd'  => $u->EvDistance,
                'e'  => $u->FSEvent,
                'c'  => $this->_accColorRgb($u->FSEvent, $colorMap),
                'f'  => get_text($u->TarDescr) . " $u->EvTargetSize cm",
                'p'  => $u->Persons,
                'mp' => $u->EvMaxTeamPerson,
                'w'  => 0,
                'ph' => '',
            ];
        }

        // Consolidation en blocs TargetButt consécutifs
        $bl = null;
        $k  = '';
        foreach ($rows as $tgts) {
            ksort($tgts);
            foreach ($tgts as $tgt => $def) {
                $gk = "{$def['d']}-{$def['e']}-{$def['w']}-{$def['ph']}";
                if (empty($bl) || $k !== $gk) {
                    if ($k && !in_array($bl, $DayBlock['times'][$Time][$Distance]['targets'])) {
                        $DayBlock['times'][$Time][$Distance]['targets'][] = $bl;
                    }
                    $bl = $this->_newTargetButt($def, $tgt);
                    if (!$DayBlock['times'][$Time][$Distance]['min']) $DayBlock['times'][$Time][$Distance]['min'] = $tgt;
                    if (!$DayBlock['min'])                             $DayBlock['min'] = $tgt;
                } elseif ($tgt === $bl->Range[1] + 1) {
                    $bl->Range[1] = $tgt;
                } else {
                    if (!in_array($bl, $DayBlock['times'][$Time][$Distance]['targets'])) {
                        $DayBlock['times'][$Time][$Distance]['targets'][] = $bl;
                    }
                    $bl = $this->_newTargetButt($def, $tgt);
                }
                $DayBlock['times'][$Time][$Distance]['min'] = min($DayBlock['times'][$Time][$Distance]['min'] ?: $tgt, $tgt);
                $DayBlock['min']                            = min($DayBlock['min'] ?: $tgt, $tgt);
                $DayBlock['times'][$Time][$Distance]['max'] = max($DayBlock['times'][$Time][$Distance]['max'], $tgt);
                $DayBlock['max']                            = max($DayBlock['max'], $tgt);
                $k = $gk;
            }
            if ($k && !in_array($bl, $DayBlock['times'][$Time][$Distance]['targets'])) {
                $DayBlock['times'][$Time][$Distance]['targets'][] = $bl;
            }
            $k = '';
        }
    }

    // ── Crée un objet TargetButt à partir d'une ligne $rows ──────────────────
    private function _newTargetButt($def, $tgt) {
        $bl           = new TargetButt();
        $bl->Target   = $def['f'];
        $bl->Event    = $def['e'];
        $bl->Distance = $def['d'];
        $bl->Range    = [$tgt, $tgt];
        $bl->Colour   = $def['c'];
        if ($def['ph']) $bl->Phase = $def['ph'];
        if ($def['p']) {
            $bl->ArcTarget = strlen($def['p']);
            if (strlen($def['p']) < 4 && strstr($def['p'], 'C')) $bl->Line = 1;
        } else {
            $bl->ArcTarget = $def['mp'];
        }
        return $bl;
    }

    // ── AccColor → [R, G, B] ; gris par défaut si aucune correspondance ───────
    private function _accColorRgb($evCode, $colorMap) {
        foreach ($colorMap as $entry) {
            foreach (preg_split('/\s+/', trim($entry['pattern']), -1, PREG_SPLIT_NO_EMPTY) as $pat) {
                $regex = '/^' . str_replace('%', '.*', preg_quote($pat, '/')) . '$/i';
                if (preg_match($regex, $evCode)) {
                    $hex = ltrim($entry['color'], '#');
                    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
                }
            }
        }
        return [223, 223, 223];
    }
}
