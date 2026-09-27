<?php
/**
 * French strings of the live draw module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Tirage au sort';
$lang['MenuShows'] = 'Tirages';
$lang['MenuUpdate'] = 'Mise à jour module';
$lang['UpdateTitle'] = 'Tirage au sort — mise à jour du module';

// Home page
$lang['HomeLead'] = 'Les écrans d\'un tirage au sort en public : un écran pour la salle ou la diffusion, une régie pour enregistrer chaque équipe tirée, et un écran pour le micro et les commentateurs. Un tirage n\'est lié à aucune compétition.';
$lang['ShowsTitle'] = 'Tirages';
$lang['ShowsNone'] = 'Aucun tirage pour l\'instant. Créez-en un ci-dessous, ou importez une sauvegarde de l\'ancienne page de tirage.';
$lang['ColTitle'] = 'Tirage';
$lang['ColProgress'] = 'Avancement';
$lang['ColUpdated'] = 'Dernière modification';
$lang['ColScreens'] = 'Écrans';
$lang['Progress'] = '{$a[drawn]} place(s) tirée(s) sur {$a[total]}';
$lang['OpenControl'] = 'Régie';
$lang['OpenEdit'] = 'Paramètres et équipes';
$lang['OpenDisplay'] = 'Écran public';
$lang['OpenSpeaker'] = 'Commentateurs';
$lang['Duplicate'] = 'Copier';
$lang['DuplicateHint'] = 'Recopie les listes, l\'historique, les notes et l\'apparence dans un nouveau tirage, sans aucune place tirée — c\'est ainsi que commence le tirage de l\'année suivante.';
$lang['ConfirmDeleteShow'] = 'Supprimer le tirage « {$a} » avec ses listes, son historique et ses notes ?';
$lang['Delete'] = 'Supprimer';
$lang['SourceNone'] = 'Aucune compétition N-1';
$lang['CreateTitle'] = 'Nouveau tirage';
$lang['FieldTitle'] = 'Titre';
$lang['FieldTitlePlaceholder'] = 'Tirage au sort D1 2027';
$lang['FieldSource'] = 'Compétition de la saison N-1';
$lang['FieldSourceHint'] = 'Facultatif. La compétition ianseo de la saison qui vient de se jouer : ses équipes peuvent être importées, et les commentateurs disposent de la saison, des résultats et des archers de chaque équipe.';
$lang['Create'] = 'Créer';
$lang['ImportTitle'] = 'Importer une sauvegarde de l\'ancienne page de tirage';
$lang['ImportLead'] = 'La page HTML autonome utilisée avant ce module exportait son état dans un fichier JSON. Donnez ce fichier, et la page elle-même si vous l\'avez encore : les noms des catégories et les listes complètes d\'équipes ne sont écrits que dans la page. Les places tirées ne sont pas importées.';
$lang['ImportSave'] = 'Sauvegarde (.json)';
$lang['ImportPage'] = 'Ancienne page (.html), facultative';
$lang['Import'] = 'Importer';
$lang['CopyOf'] = 'Copie de {$a}';
$lang['LegacyTitle'] = 'Tirage importé';
$lang['LegacyCategory'] = 'Catégorie {$a}';
$lang['EditTitle'] = 'Paramètres et équipes';
$lang['ControlTitle'] = 'Régie';
$lang['SpeakerTitle'] = 'Commentateurs';
$lang['BackToShows'] = 'Tous les tirages';
$lang['Loading'] = 'Chargement…';

// Errors and messages
$lang['ErrToken'] = 'La session a expiré. Rechargez la page puis recommencez.';
$lang['ErrNameEmpty'] = 'Un nom est obligatoire.';
$lang['ErrNoShow'] = 'Ce tirage n\'existe plus.';
$lang['ErrNoCategory'] = 'Cette catégorie n\'existe plus.';
$lang['ErrNoTeam'] = 'Cette équipe n\'existe plus.';
$lang['ErrNoSource'] = 'Choisissez d\'abord la compétition de la saison N-1.';
$lang['ErrField'] = 'Champ inconnu.';
$lang['ErrAction'] = 'Action inconnue.';
$lang['ErrImage'] = 'Le fichier n\'est pas une image acceptée (JPEG, PNG, WebP ou GIF, 5 Mo au plus).';
$lang['ErrLink'] = 'Ce lien d\'écran n\'est pas valable. Il a peut-être été renouvelé : demandez le nouveau.';
$lang['ErrLegacyFormat'] = 'Ce fichier n\'est pas une sauvegarde de l\'ancienne page de tirage.';
$lang['ErrLegacyEmpty'] = 'La sauvegarde ne contient aucune catégorie.';
$lang['ErrNetwork'] = 'Le serveur n\'a pas répondu. Vérifiez la connexion et recommencez.';
$lang['ErrSeasonApplied'] = 'Cette saison a déjà été reportée dans l\'historique.';
$lang['ErrNoEventChecked'] = 'Cochez au moins une épreuve.';
$lang['MsgImported'] = '{$a} équipe(s) importée(s).';
$lang['MsgLinked'] = '{$a} équipe(s) reliée(s) à leur club.';
$lang['MsgSeasonApplied'] = 'Historique mis à jour pour {$a[updated]} équipe(s) ; classement N-1 effacé pour {$a[cleared]} équipe(s) absente(s) de cette saison.';

// Preparation page
$lang['SectionGeneral'] = 'Tirage';
$lang['FieldSubtitle'] = 'Sous-titre';
$lang['LinksTitle'] = 'Liens des écrans';
$lang['LinksHint'] = 'Ouvrez ces liens sur les machines qui affichent les écrans. Aucun compte ianseo n\'est nécessaire : quiconque a un lien voit cet écran, ne donnez donc le lien des commentateurs qu\'aux commentateurs.';
$lang['Copy'] = 'Copier';
$lang['Copied'] = 'Lien copié.';
$lang['Open'] = 'Ouvrir';
$lang['NewTokens'] = 'Renouveler les liens';
$lang['NewTokensHint'] = 'Les liens actuels cessent aussitôt de fonctionner.';
$lang['ConfirmNewTokens'] = 'Renouveler les deux liens ? Les écrans déjà ouverts ne se mettront plus à jour tant qu\'ils ne seront pas rouverts avec les nouveaux liens.';
$lang['SectionSeasonNone'] = 'Saison N-1';
$lang['SeasonNoSource'] = 'Choisissez plus haut la compétition de la saison N-1 pour importer ses équipes et donner aux commentateurs la saison de chaque équipe.';
$lang['SectionSeason'] = 'Saison N-1 — {$a}';
$lang['ImportEventsLead'] = 'Chaque épreuve par équipes cochée devient une catégorie contenant ses équipes, reliées à leur club. Une épreuve déjà présente ne reçoit que les équipes qui lui manquent.';
$lang['AlreadyListed'] = 'déjà présente';
$lang['NoTeamEvent'] = 'Cette compétition n\'a aucune épreuve par équipes.';
$lang['ImportEvents'] = 'Importer les équipes';
$lang['LinkClubs'] = 'Relier les équipes aux clubs';
$lang['LinkClubsHint'] = 'Pour les équipes saisies ou importées par leur nom : retrouve leur club dans l\'épreuve liée en comparant les noms.';
$lang['ApplySeason'] = 'Reporter la saison {$a} dans l\'historique';
$lang['ApplySeasonHint'] = 'Une seule fois : +1 participation par équipe de cette saison, +1 victoire pour le champion, +1 podium pour les trois premiers, classement N-1 remplacé par le classement final.';
$lang['SeasonAppliedPill'] = 'Saison {$a} reportée dans l\'historique';
$lang['ConfirmApplySeason'] = 'Reporter la saison {$a} dans l\'historique de toutes les équipes reliées ? Cette opération ne peut être faite qu\'une fois.';
$lang['NationalOn'] = 'Classements nationaux disponibles : les commentateurs voient le rang national de chaque archer.';
$lang['NationalOff'] = 'Classements nationaux indisponibles. Ils sont téléchargés par le module REPARTITION_EPREUVES.';
$lang['FieldName'] = 'Nom';
$lang['FieldEvent'] = 'Épreuve N-1';
$lang['EventNone'] = 'Aucune épreuve liée';
$lang['TeamCount'] = '{$a} équipe(s)';
$lang['MoveUp'] = 'Monter';
$lang['MoveDown'] = 'Descendre';
$lang['DeleteCategory'] = 'Supprimer la catégorie';
$lang['ConfirmDeleteCategory'] = 'Supprimer cette catégorie et toutes ses équipes ?';
$lang['ColTeam'] = 'Équipe';
$lang['ColClub'] = 'Code club';
$lang['ColHint'] = 'Saison N-1';
$lang['ColHintTitle'] = 'Classement final dans l\'épreuve liée de la compétition N-1';
$lang['ColNote'] = 'Note pour les commentateurs';
$lang['NoTeam'] = 'Aucune équipe dans cette catégorie.';
$lang['DeleteTeam'] = 'Supprimer l\'équipe';
$lang['ConfirmDeleteTeam'] = 'Supprimer l\'équipe « {$a} » ?';
$lang['AddTeams'] = 'Ajouter des équipes — une par ligne, suivie si besoin de ; et du code club';
$lang['AddTeamsPlaceholder'] = 'RIOM;0163157';
$lang['Add'] = 'Ajouter';
$lang['AddCategory'] = 'Ajouter une catégorie';
$lang['AddCategoryHint'] = 'Une catégorie est une liste à part : des équipes dont on tire l\'ordre, ou les manches de la saison présentées une à une au fil du micro.';
$lang['SectionLook'] = 'Écran public';
$lang['Preview'] = 'Aperçu';
$lang['LookBg'] = 'Couleur de fond';
$lang['LookAccent'] = 'Couleur d\'accent';
$lang['LookText'] = 'Couleur du texte';
$lang['LookFontTitle'] = 'Police des titres';
$lang['LookFontBody'] = 'Police du texte';
$lang['LookSizeTitle'] = 'Taille du titre';
$lang['LookSizeTeam'] = 'Taille des noms d\'équipe';
$lang['LookSizeRank'] = 'Taille des numéros de place';
$lang['LookMargin'] = 'Marges haut et bas';
$lang['LookOverlay'] = 'Voile sur l\'image';
$lang['LookAmbient'] = 'Animation de fond';
$lang['LookSlots'] = 'Afficher les places restant à tirer';
$lang['LookImage'] = 'Image de fond';
$lang['LookImageHint'] = 'JPEG, PNG, WebP ou GIF, 5 Mo au plus. Le voile, dans la couleur de fond, garde le texte lisible.';
$lang['ImageSet'] = 'Image en place';
$lang['ImageNone'] = 'Aucune image';
$lang['ImageClear'] = 'Retirer l\'image';

// History columns
$lang['StatParticipations'] = 'Participations';
$lang['StatWins'] = 'Victoires';
$lang['StatPodiums'] = 'Podiums';
$lang['StatRank'] = 'Classement N-1';
$lang['StatParticipationsShort'] = 'Part.';
$lang['StatWinsShort'] = 'Vict.';
$lang['StatPodiumsShort'] = 'Podiums';
$lang['StatRankShort'] = 'Cl. N-1';

// Control page
$lang['OnAir'] = 'Sur l\'écran public';
$lang['SceneIdle'] = 'Attente';
$lang['SceneCategory'] = 'Catégorie en cours';
$lang['SceneSummary'] = 'Récapitulatif';
$lang['Categories'] = 'Catégories';
$lang['StatsShown'] = 'Historique affiché';
$lang['UndoLast'] = 'Annuler la dernière place';
$lang['ResetCategory'] = 'Réinitialiser la catégorie';
$lang['ConfirmReset'] = 'Retirer toutes les places tirées dans « {$a} » ?';
$lang['SearchTeam'] = 'Rechercher une équipe — Entrée la tire quand une seule correspond';
$lang['NextPlace'] = 'Prochaine place';
$lang['CategoryComplete'] = 'Toutes les places sont tirées';
$lang['PlaceN'] = 'Place {$a}';
$lang['Unassign'] = 'Retirer cette place';
$lang['NoMatch'] = 'Aucune équipe ne correspond.';
$lang['NoCategory'] = 'Ce tirage n\'a pas encore de catégorie : ajoutez-les sur la page de préparation.';
$lang['NothingDrawn'] = 'Rien n\'est encore tiré.';
$lang['DrawOrder'] = 'Ordre tiré';
$lang['LeftToDraw'] = 'Restent à tirer';
$lang['PreviewTitle'] = 'L\'écran public en ce moment';

// Public screen
$lang['DrawKicker'] = 'Tirage au sort';
$lang['WaitingDraw'] = 'En attente du tirage…';

// Commentators' screen
$lang['SpeakerPick'] = 'Choisissez une équipe dans les listes.';
$lang['NotDrawnYet'] = 'Pas encore tirée';
$lang['NewInEvent'] = 'Absente de cette épreuve en {$a}';
$lang['SeasonTitle'] = 'Saison {$a}';
$lang['NotInEvent'] = 'Cette équipe n\'a pas participé à cette épreuve en {$a[year]}.';
$lang['AlsoIn'] = 'Le club avait aussi une équipe en :';
$lang['FactRank'] = 'Classement final';
$lang['FactPoints'] = 'Points au classement';
$lang['FactMatches'] = 'Matchs gagnés–perdus';
$lang['FactAvg'] = 'Points par flèche';
$lang['FactBest'] = 'Meilleur match, par flèche';
$lang['FactStreak'] = 'Plus longue série de victoires';
$lang['FactShootOffs'] = 'Barrages gagnés–perdus';
$lang['FactSets'] = 'Points de set pour–contre';
$lang['FactForm'] = 'Derniers matchs :';
$lang['FactStage'] = 'Manche';
$lang['FactQualification'] = 'Qualification';
$lang['FactBonus'] = 'bonus {$a}';
$lang['CompositionTitle'] = 'Archers en {$a}';
$lang['ColArcher'] = 'Archer';
$lang['NationalOutdoor'] = 'Classement national TAE {$a}';
$lang['NationalIndoor'] = 'Classement national salle {$a}';
$lang['SpeakerNoClub'] = 'Pas de code club pour cette équipe : sa saison N-1 ne peut pas être retrouvée. Ajoutez-le sur la page de préparation.';
$lang['Won'] = 'Gagné';
$lang['Lost'] = 'Perdu';
$lang['Ordinal1'] = '{$a}er';
$lang['OrdinalN'] = '{$a}e';

// Version 0.2.0: stages, list for the ianseo competition, medals, settings page
$lang['FieldType'] = 'Type de liste';
$lang['TypeTeams'] = 'Équipes à tirer';
$lang['TypeStages'] = 'Manches présentées une à une';
$lang['StageCount'] = '{$a} manche(s)';
$lang['StageN'] = 'Manche {$a}';
$lang['ColStageName'] = 'Lieu';
$lang['ColStageDetail'] = 'Dates et précisions';
$lang['AddStages'] = 'Ajouter des manches — une par ligne, suivie si besoin de ; et des dates';
$lang['AddStagesPlaceholder'] = 'Smarves;17 et 18 avril 2027';
$lang['StagesHint'] = 'Rien n\'est tiré ici : l\'écran public présente les manches dans cet ordre, une carte après l\'autre, au fil du discours du micro.';
$lang['NoStage'] = 'Aucune manche dans cette liste.';
$lang['DeleteStage'] = 'Supprimer la manche';
$lang['ShowStage'] = 'Afficher';
$lang['ShowNextStage'] = 'Afficher la manche suivante';
$lang['HideStage'] = 'Masquer à nouveau cette manche';
$lang['StagesShown'] = '{$a[shown]} manche(s) affichée(s) sur {$a[total]}';
$lang['AllStagesShown'] = 'Toutes les manches sont affichées';
$lang['StagesList'] = 'Manches';
$lang['StageOnScreen'] = 'à l\'écran';
$lang['StageUpcoming'] = 'pas encore affichée';
$lang['PasteTitle'] = 'Liste pour la compétition ianseo';
$lang['PasteHint'] = 'Codes clubs dans l\'ordre tiré. Collez-les dans la zone de texte sous la colonne {$a} de l\'écran Setup de la compétition de D1 de la nouvelle saison.';
$lang['PasteHintNoEvent'] = 'Codes clubs dans l\'ordre tiré. Collez-les dans la zone de texte de la colonne correspondante de l\'écran Setup de la compétition de D1 de la nouvelle saison.';
$lang['PasteMissingCode'] = 'Pas de code club pour : {$a}. Ajoutez-le d\'abord dans les paramètres — sans lui, toutes les équipes suivantes remonteraient d\'une place dans ianseo.';
$lang['PasteIncomplete'] = 'Seulement {$a[drawn]} place(s) tirée(s) sur {$a[total]} pour l\'instant';
$lang['CopyList'] = 'Copier la liste';
$lang['ListCopied'] = 'Liste copiée.';
$lang['MedalGold'] = 'Meilleure valeur de la catégorie';
$lang['MedalSilver'] = 'Deuxième valeur de la catégorie';
$lang['MedalBronze'] = 'Troisième valeur de la catégorie';
$lang['EditLead'] = 'Chaque modification est enregistrée dès que vous quittez le champ : il n\'y a pas de bouton à valider. Revenez ici depuis la liste des tirages ou depuis la régie.';

// Version 0.2.1: commentators' screen
$lang['BackToLive'] = 'Revenir au direct';

// Version 0.2.3: spacing inside a slot of the public screen
$lang['LookRankSpace'] = 'Espace numéro → équipe';
$lang['LookStatsSpace'] = 'Espace équipe → statistiques';
$lang['LookStatGap'] = 'Espace entre statistiques';
$lang['LookStatWidth'] = 'Largeur d\'une statistique';
