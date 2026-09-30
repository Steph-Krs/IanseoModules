<?php
/**
 * French strings of the theme module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Thème';
$lang['Mode_auto'] = 'Automatique';
$lang['Mode_light'] = 'Clair';
$lang['Mode_dark'] = 'Sombre';
$lang['MenuColours'] = 'Couleurs…';
$lang['MenuUpdate'] = 'Mise à jour module';
$lang['UpdateTitle'] = 'Thème — mise à jour du module';
$lang['BackToPage'] = 'Retour au thème';

// Page
$lang['Lead'] = 'L\'apparence de ianseo dans ce navigateur : claire ou sombre, et dans quelle couleur.';
$lang['ModeTitle'] = 'Mode';
$lang['ModeHint'] = 'Automatique suit le réglage clair ou sombre de cet ordinateur, et change avec lui.';
$lang['PaletteTitle'] = 'Couleur';
$lang['PaletteHint'] = 'Donnez à chacune de vos installations de ianseo sa propre couleur, pour les distinguer d\'un coup d\'œil. Chaque couleur existe en version claire et en version sombre.';
$lang['StoredNote'] = 'Ces réglages sont conservés par ce navigateur, pour cette installation de ianseo seulement : les autres personnes et les autres installations ne sont pas concernées. Les impressions et les fichiers PDF ne changent pas.';
$lang['DebugNote'] = 'Le mode debug est actif : ses couleurs orange remplacent la couleur choisie, pour qu\'il ne passe pas inaperçu.';

// Palettes
$lang['Palette_ianseo'] = 'Bleu ianseo';
$lang['Palette_green'] = 'Vert sapin';
$lang['Palette_teal'] = 'Turquoise';
$lang['Palette_olive'] = 'Olive';
$lang['Palette_violet'] = 'Violet';
$lang['Palette_raspberry'] = 'Framboise';
$lang['Palette_burgundy'] = 'Bordeaux';
$lang['Palette_slate'] = 'Ardoise';

// Pages without a menu (administrator)
$lang['HookTitle'] = 'Pages sans menu';
$lang['HookText'] = 'Les fenêtres pop-up, comme la fiche d\'un participant, et les pages Speaker n\'affichent pas de menu : le thème ne peut pas les atteindre seul. L\'activation ajoute quelques lignes à Common/DebugOverrides.php, un fichier que les mises à jour de ianseo ne touchent pas. Elles donnent le thème à toutes les pages dessinées avec les couleurs standard de ianseo, sans éclair de couleur claire pendant le chargement ; les écrans publics (sortie TV, applications de marque) ne sont pas concernés. Ces lignes ne font plus rien une fois le module désinstallé.';
$lang['HookOn'] = 'Activé.';
$lang['HookOff'] = 'Non activé : seules les pages avec menu suivent le thème.';
$lang['HookEnable'] = 'Activer';
$lang['HookDisable'] = 'Désactiver';
$lang['HookDone'] = 'C\'est fait.';
$lang['HookFailed'] = 'Le fichier n\'a pas pu être écrit.';
$lang['HookNotWritable'] = 'Le serveur web ne peut pas écrire {$a}. Pour l\'activer à la main, placez ces lignes tout en haut de ce fichier, en le créant s\'il n\'existe pas :';
$lang['HookNotWritableOff'] = 'Le serveur web ne peut pas écrire {$a}. Pour le désactiver à la main, retirez de ce fichier les lignes comprises entre THEME BEGIN et THEME END.';
