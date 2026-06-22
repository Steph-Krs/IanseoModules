<?php
// =============================================================================
// PdfSchedule.php — Programme de compétition TNM
// Même format qu'ianseo, mais les lignes Round Robin sont fusionnées par
// (niveau, rencontre) : "Tour 1 Rencontre 1" au lieu de "Tour 1 Poule 1 Rencontre 1"
// =============================================================================
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

// Fun_Scheduler.php utilise des chemins relatifs depuis htdocs (require 'Common/...')
chdir(HTDOCS);
require_once('Common/Lib/Fun_Scheduler.php');

// ── Sous-classe : fusionne les entrées de poules Round Robin ──────────────────
// GetSchedule() retourne $schedule[$date][$sesGroup][$time][$sessionKey][$dist][]
// Pour les matchs Round Robin, $sessionKey vaut "LevelName|Level|GroupName|Group|Round"
// On fusionne toutes les poules d'un même (niveau, rencontre) en une seule ligne
// et on met à jour le texte de l'item pour supprimer "Poule X".
class TNM_Scheduler extends Scheduler {
    function GetSchedule() {
        $schedule = parent::GetSchedule();

        foreach ($schedule as $date => &$sesGroups) {
            foreach ($sesGroups as $sesGroup => &$times) {
                foreach ($times as $time => &$sessions) {
                    $seen     = [];   // mergeKey → true (première occurrence)
                    $toRemove = [];   // session keys des doublons à supprimer

                    foreach ($sessions as $sessionKey => &$distances) {
                        // Clé Round Robin : "LevelName|Level|GroupName|Group|Round"
                        $parts = explode('|', $sessionKey);
                        if (count($parts) !== 5) continue;

                        // Clé de fusion = niveau + rencontre (sans numéro de poule)
                        $mergeKey = $parts[0] . '|' . $parts[1] . '|' . $parts[4];

                        if (isset($seen[$mergeKey])) {
                            // Doublon : même niveau + rencontre, poule différente → à supprimer
                            $toRemove[] = $sessionKey;
                        } else {
                            $seen[$mergeKey] = true;
                            // Première occurrence : corriger le texte "Tour 1 Poule X Rencontre N"
                            // → "Tour 1 Rencontre N"
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

                    foreach ($toRemove as $key) {
                        unset($sessions[$key]);
                    }
                }
            }
        }

        return $schedule;
    }
}

$Schedule = new TNM_Scheduler();
$pdf      = $Schedule->getSchedulePDF();
$pdf->Output();
