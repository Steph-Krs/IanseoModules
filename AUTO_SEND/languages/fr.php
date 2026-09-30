<?php
/**
 * French strings of the scheduled upload module. Keys missing here fall back
 * to the English file, which holds the reference list.
 */

$lang['ModuleName'] = 'Envoi automatique';
$lang['MenuSettings'] = 'Réglages et état';
$lang['MenuUpdate'] = 'Mettre à jour le module';
$lang['UpdateTitle'] = 'Envoi automatique — mise à jour';
$lang['BackToSettings'] = 'Retour aux réglages';

$lang['PageTitle'] = 'Envoi automatique vers ianseo.net';
$lang['PageLead'] = 'Ouvre et ferme la saisie des scores et envoie les résultats sur ianseo.net tout seul, aux heures réglées ci-dessous, sans ordinateur laissé sur la page d\'envoi. Le travail est fait par une tâche que le serveur lance chaque minute.';

$lang['WarnNoCredentials'] = 'Les codes ianseo.net de cette compétition ne sont pas enregistrés : aucun envoi n\'est possible. Saisissez-les en cochant « mémoriser » :';
$lang['WarnSimulation'] = 'Mode simulation : les résultats sont construits comme pour un vrai envoi, mais rien n\'est envoyé sur ianseo.net. Décochez-le plus bas avant la vraie période.';

$lang['StatusTitle'] = 'État';
$lang['Loading'] = 'Chargement…';

$lang['PeriodTitle'] = 'Période';
$lang['Enabled'] = 'Programmation active';
$lang['EnabledHint'] = 'Décochée, rien ne se fait automatiquement et la saisie reste dans son état.';
$lang['Start'] = 'Début';
$lang['End'] = 'Fin';
$lang['TimeZone'] = 'Fuseau horaire';
$lang['Interval'] = 'Envoi toutes les';
$lang['Minutes'] = 'minutes';
$lang['PeriodSaved'] = 'Période enregistrée : du {$a[start]} au {$a[end]}.';
$lang['TimeZoneHint'] = 'Les heures sont celles du fuseau choisi, changements d\'heure compris : une période qui commence en heure d\'hiver et finit en heure d\'été garde les heures saisies ici. Une heure qui n\'existe pas la nuit du changement (02:00–03:00 au printemps) est refusée.';
$lang['IntervalHint'] = '{$a} minutes au minimum. Après un échec, l\'essai suivant vient plus tôt (1, 2, 4… minutes), et le rythme normal reprend au premier succès.';

$lang['SessionsTitle'] = 'Saisie des scores (ISK-NG)';
$lang['SessionsNoRight'] = 'Vos droits ne permettent pas de gérer la saisie ISK-NG : ces réglages sont affichés en lecture seule.';
$lang['SessionsManage'] = 'Ouvrir la saisie au début et la fermer à la fin';
$lang['SessionsHint'] = 'Les sessions cochées sont ouvertes au début et fermées à la fin, une seule fois chacune : une session verrouillée à la main pendant la période reste verrouillée. À la fin, la saisie est fermée avant le dernier envoi, pour qu\'il contienne tous les scores saisis.';
$lang['SessionsNone'] = 'Aucune session de saisie dans cette compétition pour l\'instant.';

$lang['ItemsTitle'] = 'Ce qui est envoyé';
$lang['ItemsHint'] = 'Les mêmes listes que sur la page d\'envoi de ianseo. Un élément marqué « pas encore disponible » est envoyé dès que ianseo le propose (grilles une fois les barrages résolus, médailles une fois attribuées).';
$lang['ItemWaiting'] = '(pas encore disponible)';
$lang['ItemMissing'] = '(n\'existe plus dans la compétition)';

$lang['PingTitle'] = 'Surveillance extérieure (facultative)';
$lang['PingUrl'] = 'Adresse de surveillance (healthchecks.io ou compatible)';
$lang['PingHint'] = 'Le serveur appelle cette adresse après chaque envoi et au moins une fois par intervalle. Si les appels s\'arrêtent — serveur arrêté, réseau coupé, envois en échec — le service de surveillance vous prévient par e-mail ou SMS. Rien n\'est envoyé en simulation. Voir le README du module.';

$lang['SimulationTitle'] = 'Simulation';
$lang['Simulation'] = 'Simulation : ne rien envoyer sur ianseo.net';
$lang['SimulationHint'] = 'Tout se déroule pour de vrai — horaires, saisie, construction des résultats — sauf l\'envoi lui-même, qui est imité. Utile pour vérifier les réglages et mesurer le coût sur le serveur avant la vraie période.';

$lang['Save'] = 'Enregistrer';

$lang['TaskTitle'] = 'Tâche planifiée sur le serveur';
$lang['TaskExplain'] = 'PHP ne s\'exécute que lorsqu\'une page est demandée : rien dans ianseo ne peut se réveiller seul à une heure donnée. Le planificateur du serveur lance donc la tâche du module chaque minute ; elle regarde ce qui est dû (ouverture, envoi, fermeture) et s\'arrête aussitôt quand rien ne l\'est. Sans cette tâche, rien ne se passe.';
$lang['TaskInstallLinux'] = 'À installer une fois, en administrateur du serveur (Linux, serveur web sous l\'utilisateur www-data) :';
$lang['TaskInstallWindows'] = 'À installer une fois, dans une invite de commandes lancée en administrateur :';
$lang['TaskReadme'] = 'Le README du module explique comment vérifier que la tâche tourne et comment la retirer.';

$lang['HeartbeatOk'] = 'La tâche planifiée tourne.';
$lang['HeartbeatNever'] = 'La tâche planifiée n\'a jamais tourné sur ce serveur : voir « Tâche planifiée sur le serveur » plus bas.';
$lang['HeartbeatLate'] = 'La tâche planifiée n\'a pas tourné depuis {$a} minutes.';

$lang['PhaseDisabled'] = 'Programmation inactive.';
$lang['PhaseIncomplete'] = 'Programmation incomplète : le début ou la fin manque.';
$lang['PhaseWaiting'] = 'En attente du début, le {$a}.';
$lang['PhaseRunning'] = 'Période en cours, jusqu\'au {$a}.';
$lang['PhaseFinishing'] = 'Période terminée : dernier envoi en cours.';
$lang['PhaseDone'] = 'Période terminée depuis le {$a}.';

$lang['KindSend'] = 'Envoi';
$lang['KindManual'] = 'Envoi demandé';
$lang['KindFinal'] = 'Dernier envoi';
$lang['KindOpen'] = 'Saisie ouverte';
$lang['KindClose'] = 'Saisie fermée';

$lang['OutSent'] = 'Envoyé sur ianseo.net.';
$lang['OutSentSimulated'] = 'Simulé : construit, rien d\'envoyé.';
$lang['OutNothingYet'] = 'Rien à envoyer pour l\'instant : les éléments choisis ne sont pas disponibles.';
$lang['OutSessionsOpened'] = 'Saisie ouverte :';
$lang['OutSessionsClosed'] = 'Saisie fermée :';
$lang['OutErrNoPlan'] = 'Aucune programmation pour cette compétition.';
$lang['OutErrNoCompetition'] = 'La compétition n\'existe plus.';
$lang['OutErrNothingSelected'] = 'Rien n\'est choisi pour l\'envoi.';
$lang['OutErrNoCredentials'] = 'Les codes ianseo.net de la compétition ne sont pas enregistrés.';
$lang['OutErrPublicationLocked'] = 'La publication est verrouillée pour cette compétition.';
$lang['OutErrCredentialsCheck'] = 'ianseo.net injoignable, ou codes refusés :';
$lang['OutErrIanseoNet'] = 'ianseo.net a refusé l\'envoi :';
$lang['OutErrWorker'] = 'L\'envoi s\'est arrêté sur une erreur :';
$lang['OutErrTimeout'] = 'L\'envoi a duré plus de {$a} secondes et a été arrêté.';
$lang['OutErrStart'] = 'L\'envoi n\'a pas pu être lancé sur le serveur.';

$lang['ErrToken'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
$lang['ErrAccess'] = 'Accès refusé.';
$lang['ErrTimeZone'] = 'Fuseau horaire inconnu.';
$lang['ErrDate'] = 'date ou heure invalide.';
$lang['ErrTimeGap'] = 'cette heure n\'existe pas dans ce fuseau (changement d\'heure).';
$lang['ErrEndBeforeStart'] = 'La fin doit venir après le début.';
$lang['ErrInterval'] = 'L\'intervalle doit être compris entre {$a[min]} et {$a[max]} minutes.';
$lang['ErrPingUrl'] = 'L\'adresse de surveillance doit être une adresse web commençant par https:// ou http://.';
$lang['ErrDatesRequired'] = 'Une programmation active demande un début et une fin.';
$lang['ErrEndPast'] = 'La fin est déjà passée.';
$lang['ErrNoSession'] = 'Cochez au moins une session de saisie, ou décochez l\'ouverture et la fermeture de la saisie.';
$lang['ErrRunArchery'] = 'Les compétitions de Run Archery ne sont pas prises en charge par ce module.';

$lang['SavedDisabled'] = 'Enregistré. La programmation est inactive.';
$lang['SavedRunning'] = 'Enregistré. La période a commencé : la tâche planifiée agit dans la minute.';
$lang['SavedWaiting'] = 'Enregistré. La tâche planifiée agira au début de la période.';

$lang['JsLoadError'] = 'L\'état n\'a pas pu être lu.';
$lang['JsTask'] = 'Tâche';
$lang['JsSimulation'] = 'simulation';
$lang['JsLastSuccess'] = 'Dernier envoi réussi :';
$lang['JsNever'] = 'jamais';
$lang['JsNextSend'] = 'Prochain envoi :';
$lang['JsFailures'] = 'Échecs consécutifs :';
$lang['JsSessions'] = 'Saisie :';
$lang['JsOpen'] = 'ouverte';
$lang['JsClosed'] = 'fermée';
$lang['JsSendNow'] = 'Envoyer maintenant';
$lang['JsSendNowPending'] = 'Envoi demandé…';
$lang['JsSendNowHint'] = 'La tâche planifiée le fait dans la minute, imité si la simulation est cochée.';
$lang['JsHistory'] = 'Historique';
$lang['JsNoRun'] = 'Rien de fait pour l\'instant.';
$lang['JsWhen'] = 'Quand';
$lang['JsKind'] = 'Action';
$lang['JsResult'] = 'Résultat';
$lang['JsDuration'] = 'Durée';
$lang['JsSize'] = 'Taille';
$lang['JsDetail'] = 'Détail';
$lang['JsOk'] = 'OK';
$lang['JsKo'] = 'échec';
$lang['JsAll'] = 'tout';
$lang['JsNone'] = 'aucun';
