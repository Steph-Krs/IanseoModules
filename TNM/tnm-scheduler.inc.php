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

    /** Active la fusion des rencontres puis délègue au FOP parent. */
    function FOP($Output = true) {
        $this->FopMergeRounds = true;
        return parent::FOP($Output);
    }
}
