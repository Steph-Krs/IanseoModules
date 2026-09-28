<?php
/**
 * French strings of the scorecard splitter. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Feuilles de marque par club ou archer';
$lang['MenuPrint'] = 'Imprimer les feuilles de marque';
$lang['MenuUpdate'] = 'Mise à jour module';
$lang['UpdateTitle'] = 'Feuilles de marque par club ou archer — mise à jour du module';
$lang['BackToPage'] = 'Retour aux feuilles de marque';

// Page
$lang['Lead'] = 'Les feuilles de marque de la compétition ouverte, découpées en fichiers : un par club, avec tous ses archers, ou un par archer. Seules les pages qui portent au moins un archer sont imprimées, quel que soit le nombre d\'emplacements par cible ; les emplacements libres d\'une page imprimée restent des grilles vierges. L\'impression des feuilles de marque de ianseo n\'est pas modifiée.';
$lang['OptionsTitle'] = 'Feuilles de marque';
$lang['SessionsTitle'] = 'Départs';
$lang['SessionCards'] = '({$a} feuilles)';
$lang['LayoutTitle'] = 'Mise en page';
$lang['HideHeaderText'] = 'Masquer le texte de l\'en-tête de page (titre, organisateur, lieu, dates) : seulement ses images, quand l\'image d\'en-tête dit déjà tout';
$lang['HideTarget'] = 'Masquer le numéro de cible';
$lang['HideTargetHint'] = 'Pour une compétition où les emplacements servent seulement à donner à chaque archer ses propres feuilles, comme un challenge tiré dans les clubs : le numéro ne dit rien à l\'archer.';

// Archive
$lang['ArchiveTitle'] = 'Archive';
$lang['ModeClub'] = 'Un fichier par club, nommé d\'après son code';
$lang['ModeArcher'] = 'Un fichier par archer, nommé d\'après son numéro de licence';
$lang['ZipButton'] = 'Télécharger le ZIP';
$lang['Starting'] = 'Préparation…';
$lang['Progress'] = '{$a[done]} / {$a[total]} fichiers';
$lang['Packing'] = 'Assemblage de l\'archive…';
$lang['Ready'] = 'L\'archive est prête : le téléchargement démarre.';

// List of clubs
$lang['ClubsTitle'] = 'Clubs ({$a})';
$lang['ClubsHint'] = 'Le PDF d\'un club reprend les options ci-dessus.';
$lang['ColCode'] = 'Code';
$lang['ColClub'] = 'Club';
$lang['ColArchers'] = 'Archers';
$lang['ColCards'] = 'Feuilles';
$lang['ColPdf'] = 'PDF';
$lang['ClubPdf'] = 'PDF de {$a}';
$lang['NoClub'] = 'Sans club';
$lang['Total'] = 'Total';

// What cannot be printed
$lang['NothingToPrint'] = 'Aucun archer n\'est encore sur une cible dans les départs de qualification : il n\'y a rien à imprimer.';
$lang['NotTargetArchery'] = 'Ce module imprime des feuilles de marque de tir sur cible, et cette compétition est une compétition de parcours ou de 3D.';
$lang['WarnOutside'] = '{$a} feuille(s) sont sur un emplacement hors des cibles de leur départ, ou au-delà de son nombre d\'archers par cible. Comme l\'impression de ianseo, le module ne les imprime pas.';
$lang['WarnTwice'] = '{$a} emplacement(s) sont donnés à plusieurs archers. Une seule feuille tient sur un emplacement : vérifiez l\'attribution des cibles.';
$lang['WarnUnplaced'] = '{$a} archer(s) n\'ont pas encore de cible, donc pas de feuille.';

// Errors
$lang['ErrAccess'] = 'Aucune compétition n\'est ouverte, ou vous n\'avez pas le droit d\'imprimer ses feuilles de marque.';
$lang['ErrToken'] = 'La session a expiré. Rechargez la page et recommencez.';
$lang['ErrNoSession'] = 'Choisissez au moins un départ.';
$lang['ErrNothing'] = 'Rien à imprimer avec ces options.';
$lang['ErrZip'] = 'L\'extension zip de PHP manque sur ce serveur : le PDF de chaque club reste disponible depuis la liste ci-dessous.';
$lang['ErrTemp'] = 'Impossible d\'écrire dans le dossier temporaire du serveur.';
$lang['ErrJob'] = 'Ce téléchargement a expiré ou est inconnu. Relancez-le.';
$lang['ErrServer'] = 'Le serveur n\'a pas répondu comme prévu (HTTP {$a[status]}).';
$lang['NotFound'] = 'Rien à imprimer pour ce fichier avec ces options.';
