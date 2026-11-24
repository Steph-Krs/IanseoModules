<?php
/**
 * French strings of the training module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Entraînement';
$lang['MenuCreate'] = 'Créer un entraînement';
$lang['MenuHome'] = 'Entraînements';
$lang['MenuDashboard'] = 'Tableau de bord de l\'entraînement';
$lang['MenuParticipants'] = 'Archers de l\'entraînement';
$lang['MenuTeams'] = 'Équipes';
$lang['MenuRanking'] = 'Classement et duels';
$lang['MenuResults'] = 'Résultats';
$lang['MenuFormats'] = 'Formats de référence';
$lang['MenuArchers'] = 'Archers habituels';
$lang['MenuFull'] = 'Menu complet sur ce navigateur';
$lang['MenuSimple'] = 'Menu simplifié sur ce navigateur';
$lang['MenuIsFull'] = 'Ce navigateur affiche le menu complet d\'ianseo.';
$lang['MenuIsSimple'] = 'Ce navigateur affiche le menu simplifié des entraînements.';
$lang['MenuUpdate'] = 'Mise à jour module';
$lang['UpdateTitle'] = 'Entraînement - mise à jour du module';
$lang['BackToPage'] = 'Retour aux entraînements';
$lang['DateFmt'] = 'd/m/Y';

// Common words
$lang['Save'] = 'Enregistrer';
$lang['Saved'] = 'Enregistré.';
$lang['Cancel'] = 'Annuler';
$lang['Delete'] = 'Supprimer';
$lang['Edit'] = 'Modifier';
$lang['Add'] = 'Ajouter';
$lang['Remove'] = 'Retirer';
$lang['Rename'] = 'Renommer';
$lang['Open'] = 'Ouvrir';
$lang['Choose'] = 'Choisir';
$lang['Filter'] = 'Filtrer…';
$lang['CheckAll'] = 'Tout cocher';
$lang['BackDashboard'] = 'Retour au tableau de bord';
$lang['SessionN'] = 'Départ {$a}';
$lang['SexM'] = 'Homme';
$lang['SexF'] = 'Femme';
$lang['DuelsOnly'] = 'Duels seuls';
$lang['ErrToken'] = 'Le formulaire avait expiré. Veuillez recommencer.';

// Columns
$lang['ColDate'] = 'Date';
$lang['ColName'] = 'Nom';
$lang['ColFormat'] = 'Format';
$lang['ColArchers'] = 'Archers';
$lang['ColArcher'] = 'Archer';
$lang['ColCategory'] = 'Catégorie';
$lang['ColSession'] = 'Départ';
$lang['ColTarget'] = 'Cible';
$lang['ColContent'] = 'Contenu';
$lang['ColUpdated'] = 'Mis à jour';

// Home
$lang['HomeTitle'] = 'Entraînements';
$lang['HomeLead'] = 'Un entraînement est une vraie compétition ianseo créée en quelques clics depuis un format de référence : choisissez le format, la date et l\'heure de départ, cochez les archers, et tout le reste est prêt - catégories, épreuves, blasons, distances, programme. L\'entraîneur suit ensuite les étapes du tableau de bord.';
$lang['HomeRuns'] = 'Entraînements déjà créés';
$lang['HomeNoRun'] = 'Aucun entraînement pour l\'instant.';
$lang['HomeNoFormat'] = 'Aucun format de référence pour l\'instant. Préparez une compétition dans ianseo avec tous les réglages voulus, ouvrez-la, puis enregistrez-la sur la page Formats de référence.';
$lang['HomePrepare'] = 'Préparer les entraînements';

// Formats
$lang['FormatsTitle'] = 'Formats de référence';
$lang['FormatsLead'] = 'Un format est la copie d\'une compétition entière sans ses archers : divisions et classes, épreuves, blasons, distances, images, paramètres de la compétition et programme. Une fois enregistré, la compétition d\'origine n\'est plus nécessaire et peut être supprimée.';
$lang['FormatsFromOpen'] = 'Enregistrer la compétition ouverte';
$lang['FormatsNoOpen'] = 'Ouvrez d\'abord la compétition à enregistrer comme format.';
$lang['FormatsOpenIs'] = 'Compétition ouverte : {$a[code]} - {$a[name]}';
$lang['FormatName'] = 'Nom du format';
$lang['FormatSimple'] = 'Menu simplifié pour les entraînements créés depuis ce format';
$lang['FormatSimpleShort'] = 'Menu simplifié';
$lang['FormatReplace'] = 'Remplacer un format existant';
$lang['FormatReplaceNone'] = 'Non, créer un nouveau format';
$lang['FormatsWhatKept'] = 'Les archers, équipes, scores et résultats ne sont pas gardés, ni les codes ianseo.net de la compétition. Les horaires du programme sont gardés en écarts par rapport au premier départ.';
$lang['FormatSave'] = 'Enregistrer comme format';
$lang['FormatSaved'] = 'Format « {$a} » enregistré.';
$lang['FormatReplaced'] = 'Format « {$a} » remplacé par la compétition ouverte.';
$lang['FormatsList'] = 'Formats enregistrés';
$lang['FormatContent'] = '{$a[type]} - {$a[sessions]} départ(s) - épreuves : {$a[events]}';
$lang['FormatSource'] = 'Depuis {$a}';
$lang['FormatDeleteConfirm'] = 'Supprimer le format « {$a} » ? Les entraînements déjà créés ne sont pas touchés.';
$lang['FormatDeleted'] = 'Format supprimé.';

// Regular archers
$lang['ArchersTitle'] = 'Archers habituels';
$lang['ArchersLead'] = 'Les archers qui participent souvent aux entraînements : il suffit de les cocher à la création d\'un entraînement. Les groupes cochent plusieurs archers d\'un coup.';
$lang['ArcherAdd'] = 'Ajouter un archer';
$lang['ArcherEdit'] = 'Modifier un archer';
$lang['LookupLabel'] = 'Chercher dans le fichier des licences :';
$lang['LookupPlaceholder'] = 'licence ou nom';
$lang['LookupNone'] = 'Personne trouvé dans le fichier des licences.';
$lang['FieldFamily'] = 'Nom';
$lang['FieldGiven'] = 'Prénom';
$lang['FieldSex'] = 'Sexe';
$lang['FieldDob'] = 'Date de naissance';
$lang['FieldCode'] = 'Licence';
$lang['FieldIoc'] = 'Pays';
$lang['FieldClub'] = 'Club';
$lang['FieldClubName'] = 'Nom du club';
$lang['FieldDiv'] = 'Division (arme)';
$lang['FieldCls'] = 'Classe';
$lang['FieldActive'] = 'Proposé à la création d\'un entraînement';
$lang['FieldGroups'] = 'Groupes';
$lang['FieldDate'] = 'Date';
$lang['FieldStart'] = 'Heure de départ';
$lang['ErrNoName'] = 'Le nom est obligatoire.';
$lang['ArchersList'] = 'Archers ({$a})';
$lang['ArchersNone'] = 'Aucun archer habituel pour l\'instant.';
$lang['ArcherDeleteConfirm'] = 'Retirer {$a} des archers habituels ?';
$lang['ArcherDeleted'] = 'Archer supprimé.';
$lang['GroupsTitle'] = 'Groupes';
$lang['GroupNew'] = 'Nouveau groupe';
$lang['GroupDeleteConfirm'] = 'Supprimer le groupe « {$a} » ? Ses archers restent dans la liste.';
$lang['ImportTitle'] = 'Copier des archers de la compétition ouverte';
$lang['ImportNone'] = 'Tous les archers de la compétition ouverte sont déjà dans la liste.';
$lang['ImportDo'] = 'Copier les archers cochés';
$lang['ArchersImported'] = '{$a} archer(s) copié(s).';

// Creation
$lang['CreateTitle'] = 'Créer un entraînement';
$lang['ChooseFormat'] = '- choisir un format -';
$lang['CreateDays'] = 'Ce format dure {$a} jours : les jours suivants sont décalés aussi.';
$lang['CreateShift'] = 'Tous les horaires du programme suivent l\'heure de départ en gardant les écarts du format.';
$lang['QualSim'] = 'Duels seuls (qualifications simulées)';
$lang['QualSimHelp'] = 'Chaque archer reçoit un point ; l\'ordre des tableaux se choisit ensuite sur la page Classement et duels.';
$lang['PlaceAuto'] = 'Placer les archers sur les cibles';
$lang['CreateArchers'] = 'Archers';
$lang['CountPicked'] = '{$a} archer(s) coché(s)';
$lang['CreateDo'] = 'Créer l\'entraînement';
$lang['Created'] = 'Entraînement {$a[code]} créé avec {$a[n]} archer(s).';
$lang['CreatedSkipped'] = '{$a} archer(s) n\'ont pas pu être inscrits : catégorie absente du format.';
$lang['ErrNoFormat'] = 'Choisissez un format.';
$lang['ErrDate'] = 'La date n\'est pas valide.';
$lang['ErrNoArcher'] = 'Cochez au moins un archer.';
$lang['ErrNoCode'] = 'Tous les codes de ce jour sont pris (99 entraînements).';
$lang['ErrImport'] = 'Le format a été enregistré par un ianseo plus récent : mettez d\'abord ianseo à jour.';

// Teams
$lang['TeamsTitleAll'] = 'Équipes';
$lang['TeamsTitle'] = 'Équipes';
$lang['MixedTitle'] = 'Équipes mixtes';
$lang['TeamN'] = 'Équipe {$a}';
$lang['MixedN'] = 'Mixte {$a}';
$lang['TeamAdd'] = 'Ajouter une équipe';
$lang['MixedAdd'] = 'Ajouter une équipe mixte';
$lang['AddArcher'] = '+ archer';
$lang['RemoveTeam'] = 'Retirer l\'équipe';
$lang['TeamsHelpCreate'] = 'Seuls les archers cochés peuvent être choisis. Les équipes peuvent aussi être composées ou modifiées plus tard, depuis le tableau de bord.';
$lang['TeamsHelp'] = 'Composez les équipes maintenant ou à tout moment : avant, pendant ou après les qualifications. Un archer peut être dans une équipe et dans une équipe mixte différente. Les tableaux par équipes se remplissent sur la page Classement et duels.';
$lang['TeamsNone'] = 'Cet entraînement n\'a pas d\'épreuve par équipes.';

// Dashboard
$lang['DashTitle'] = 'Tableau de bord de l\'entraînement';
$lang['DashNotRun'] = 'Cette compétition n\'a pas été créée par le module Entraînement ; le tableau de bord fonctionne quand même.';
$lang['StepArchers'] = 'Archers';
$lang['StepTargets'] = 'Cibles';
$lang['StepScorecards'] = 'Feuilles de marque';
$lang['StepScores'] = 'Scores';
$lang['StepQualSim'] = 'Qualifications';
$lang['StepDuels'] = 'Duels';
$lang['StepResults'] = 'Résultats';
$lang['DashArchers'] = '{$a} archer(s) dans l\'entraînement.';
$lang['DashTeams'] = '{$a} équipe(s) composée(s)';
$lang['DashPlaced'] = '{$a[on]} archer(s) sur {$a[all]} placé(s) sur une cible';
$lang['PlaceDo'] = 'Placer les archers sans cible';
$lang['PlaceAgain'] = 'Tout replacer';
$lang['PlaceAgainConfirm'] = 'Retirer toutes les cibles de ce départ et replacer tout le monde ?';
$lang['PlaceManual'] = 'Plan des cibles : déplacer des archers à la main';
$lang['Placed'] = '{$a} archer(s) placé(s).';
$lang['PlacedLeft'] = '{$a} archer(s) n\'ont pas pu être placés : pas assez de cibles dans le départ.';
$lang['ScorecardsOf'] = 'Feuilles de marque - {$a}';
$lang['MoreOptions'] = 'Plus d\'options';
$lang['DashScores'] = '{$a[done]} archer(s) sur {$a[all]} ont un score.';
$lang['QualRanking'] = 'Classement des qualifications';
$lang['QualRankingTeam'] = 'Classement des qualifications par équipes';
$lang['QualSimDone'] = 'Simulées : chaque archer a un point. Choisissez l\'ordre des tableaux sur la page Classement et duels.';
$lang['EventLaunched'] = 'tableau rempli';
$lang['EventWaiting'] = 'tableau à remplir';
$lang['EventCore'] = 'éliminatoires ou poules : écrans d\'ianseo';
$lang['FinalRanking'] = 'Classement final';
$lang['FinalRankingTeam'] = 'Classement final par équipes';

// Participants
$lang['ParticipantsTitle'] = 'Archers de l\'entraînement';
$lang['ParticipantsIn'] = 'Dans l\'entraînement ({$a})';
$lang['ParticipantsAdd'] = 'Ajouter des archers habituels';
$lang['ParticipantsAllIn'] = 'Tous les archers habituels sont déjà dans l\'entraînement.';
$lang['ParticipantsAddDo'] = 'Ajouter les archers cochés';
$lang['ArchersAdded'] = '{$a} archer(s) ajouté(s).';
$lang['ArcherRemoveConfirm'] = 'Retirer {$a} de l\'entraînement, avec ses scores ?';
$lang['ArcherRemoved'] = 'Archer retiré.';

// Ranking and duels
$lang['RankingTitle'] = 'Classement et duels';
$lang['RankingLead'] = 'Chaque liste est l\'ordre du tableau, le meilleur en premier : l\'ordre des qualifications, les ex-aequo côte à côte. Faites glisser les noms, ou utilisez les flèches, puis remplissez le tableau.';
$lang['RankingNone'] = 'Cet entraînement n\'a pas d\'épreuve avec duels.';
$lang['RankingCore'] = 'Cette épreuve a des éliminatoires ou des poules : utilisez l\'écran d\'ianseo.';
$lang['OpenCore'] = 'Ouvrir';
$lang['RankingNoArcher'] = 'Aucun archer dans cette épreuve.';
$lang['RankingNoTeam'] = 'Pas encore d\'équipe : composez les équipes, et vérifiez que leurs archers ont un score.';
$lang['RankingQualified'] = 'Les {$a[n]} premiers sur {$a[all]} vont dans le tableau.';
$lang['Tie'] = 'ex-aequo';
$lang['MoveUp'] = 'Monter';
$lang['MoveDown'] = 'Descendre';
$lang['Launch'] = 'Remplir le tableau';
$lang['Relaunch'] = 'Remplir à nouveau le tableau';
$lang['RelaunchConfirm'] = 'Le tableau de {$a} est déjà rempli : le remplir à nouveau efface les duels déjà saisis. Continuer ?';
$lang['DuelsLaunched'] = 'Tableau de {$a} rempli.';
$lang['ErrLaunch'] = 'Le tableau de {$a} n\'a pas pu être rempli : rechargez la page et recommencez.';

// Import and export
$lang['MenuTransfer'] = 'Import / export';
$lang['TransferTitle'] = 'Import / export';
$lang['TransferLead'] = 'Transférer les formats de référence, les archers habituels et leurs groupes vers une autre installation, par exemple pour configurer de la même façon un autre centre d\'entraînement.';
$lang['ExportTitle'] = 'Exporter';
$lang['ExportArchers'] = 'Archers habituels ({$a[archers]}) et groupes ({$a[groups]})';
$lang['ExportPersonal'] = 'Le fichier contient alors des données personnelles (noms, dates de naissance, licences) : conservez-le comme toute liste d\'archers.';
$lang['ExportDo'] = 'Télécharger le fichier';
$lang['ExportNothing'] = 'Choisissez au moins un format, ou les archers.';
$lang['ImportFileTitle'] = 'Importer';
$lang['ImportReplace'] = 'Remplacer un format qui porte le même nom';
$lang['ImportHelp'] = 'Un archer déjà dans la liste (même licence, sinon même nom et même date de naissance) est mis à jour d\'après le fichier ; les autres sont ajoutés. Les groupes sont reconnus par leur nom. Un format enregistré par un ianseo plus récent que celui-ci est refusé : mettez d\'abord ianseo à jour.';
$lang['ImportFileDo'] = 'Importer le fichier';
$lang['ImportedSummary'] = 'Importé : {$a[formats]} nouveau(x) format(s), {$a[replaced]} remplacé(s), {$a[archers]} nouvel(aux) archer(s), {$a[updated]} mis à jour, {$a[groups]} nouveau(x) groupe(s).';
$lang['ImportRefused'] = 'Formats refusés (illisibles, ou enregistrés par un ianseo plus récent) : {$a}';
$lang['ErrFile'] = 'Ce fichier n\'est pas un export du module Entraînement.';
$lang['ErrFileSize'] = 'Le fichier n\'a pas pu être reçu : il dépasse peut-être ce que le serveur accepte.';
$lang['ClassAuto'] = 'La classe se remplit d\'après la date de naissance et le sexe ; elle est recalculée pour la date de chaque entraînement.';

// ISK-Lite
$lang['IskTitle'] = 'Saisie des scores avec ISK-Lite';
$lang['IskLead'] = 'Chaque entraînement est configuré pour la saisie avec l\'application ISK dans sa version gratuite (ISK-Lite) : les feuilles de marque portent les QR codes de l\'application, et les scores arrivent seuls dans ianseo.';
$lang['IskUrl'] = 'Adresse du serveur pour les téléphones :';
$lang['IskUrlHelp'] = 'Donnée à chaque nouvel entraînement. Les téléphones doivent joindre cette adresse : indiquez l\'adresse de cet ordinateur sur le réseau, pas localhost.';
$lang['IskUrlNone'] = 'Pas encore d\'adresse : les entraînements auront ISK-Lite sans adresse de serveur, à saisir dans les informations de la compétition. Indiquez l\'adresse de cet ordinateur sur le réseau, pas localhost.';
$lang['IskUrlBad'] = 'Cette adresse n\'est pas lisible. Exemple : http://192.168.1.10';

// Targets and rhythm
$lang['ManualQualEntry'] = 'Saisie manuelle des qualifications';
$lang['Rhythm'] = 'Archers par cible :';
$lang['RhythmConfirm'] = 'Passer en {$a} et replacer tous les archers de ce départ ?';
$lang['RhythmChanged'] = 'Nombre d\'archers par cible changé : tout le monde a été replacé.';
$lang['TargetsMissing'] = '{$a[off]} archer(s) sans cible : le départ a {$a[have]} cibles, il en faut {$a[need]} pour placer tout le monde en {$a[rhythm]}.';
$lang['TargetsOff'] = '{$a[off]} archer(s) sans cible : utilisez Placer les archers sans cible.';
$lang['TargetsHave'] = '{$a} cibles';
$lang['TargetsAdjust'] = 'Ajuster à {$a} cibles';
$lang['TargetsAdjusted'] = 'Le départ a maintenant {$a} cibles : tout le monde a été replacé.';

// Fictitious competitors and follow-up
$lang['GhostName'] = 'Fictif';
$lang['GhostClub'] = 'Concurrents fictifs';
$lang['GhostsScored'] = 'Scores tirés pour {$a} concurrent(s) fictif(s).';
$lang['DashGhosts'] = '{$a} concurrent(s) fictif(s) : leurs scores sont tirés autour de leur moyenne.';
$lang['GhostsRedraw'] = 'Tirer de nouveau leurs scores';
$lang['GhostsAdded'] = '{$a} concurrent(s) fictif(s) ajouté(s).';
$lang['GhostBadge'] = 'fictif, {$a} par flèche';
$lang['GhostsTitle'] = 'Concurrents fictifs';
$lang['GhostsLead'] = 'Des adversaires sans archer derrière : leurs scores de qualification sont tirés autour d\'une moyenne par flèche, et ils participent aux classements, aux équipes et aux tableaux. Ils n\'ont ni cible ni feuille de marque ; leurs duels se saisissent comme les autres.';
$lang['GhostsCount'] = 'Combien';
$lang['GhostsAvg'] = 'Moyenne par flèche';
$lang['GhostsSpread'] = 'Écart par flèche';
$lang['GhostsAdd'] = 'Ajouter les concurrents fictifs';
$lang['MenuFollowup'] = 'Suivi des archers';
$lang['FollowupTitle'] = 'Suivi des archers';
$lang['FollowupLead'] = 'Tous les tirs d\'un archer sur ce serveur, entraînements et vraies compétitions, par situation. Les entraînements restent même une fois supprimés d\'ianseo. Cherchez n\'importe quel archer par licence ou par nom ; les archers habituels sont listés ci-dessous.';
$lang['FollowupEmpty'] = 'Aucun résultat pour l\'instant.';
$lang['FollowupOf'] = 'Suivi - {$a}';
$lang['FollowupNone'] = 'Aucun résultat pour cet archer.';
$lang['BackFollowup'] = 'Retour à la liste';
$lang['Search'] = 'Chercher';
$lang['SituationsTitle'] = 'Par situation';
$lang['ChartTitle'] = 'Moyenne par flèche';
$lang['HistoryTitle'] = 'Tous les tirs';
$lang['KindTraining'] = 'Entraînement';
$lang['KindCompetition'] = 'Compétition';
$lang['DecimalSep'] = ',';
$lang['ColKind'] = 'Type';
$lang['ColShoots'] = 'Tirs';
$lang['ColAvg'] = 'Moyenne';
$lang['ColPerArrow'] = 'Par flèche';
$lang['ColBest'] = 'Meilleur';
$lang['ColLast'] = 'Dernier';
$lang['ColDuels'] = 'Duels gagnés - perdus';
$lang['ColTrainings'] = 'Entraînements';
$lang['ColCompetitions'] = 'Compétitions';
$lang['ColLastShoot'] = 'Dernier tir';
$lang['CsvRun'] = 'Résultats de cet entraînement (CSV)';
$lang['CsvAll'] = 'Tous les entraînements (CSV)';
$lang['CsvArcher'] = 'Cet archer (CSV)';
$lang['CsvDate'] = 'Date';
$lang['CsvCode'] = 'Code';
$lang['CsvName'] = 'Compétition';
$lang['CsvKind'] = 'Type';
$lang['CsvFormat'] = 'Format';
$lang['CsvLicence'] = 'Licence';
$lang['CsvFamily'] = 'Nom';
$lang['CsvGiven'] = 'Prénom';
$lang['CsvDivision'] = 'Division';
$lang['CsvClass'] = 'Classe';
$lang['CsvDistances'] = 'Distances';
$lang['CsvFace'] = 'Blason';
$lang['CsvScore'] = 'Score';
$lang['CsvArrows'] = 'Flèches';
$lang['CsvGolds'] = '10';
$lang['CsvXNine'] = '9';
$lang['CsvQualRank'] = 'Rang qualif';
$lang['CsvEvent'] = 'Épreuve';
$lang['CsvFinalRank'] = 'Rang final';
$lang['CsvWon'] = 'Duels gagnés';
$lang['CsvLost'] = 'Duels perdus';
