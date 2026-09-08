<?php
/**
 * French strings of the authoring documentation, the "help" section.
 *
 * Keys must mirror languages/help/en.php, which is the fallback. Only wording
 * here: the page's structure lives in admin/help.php.
 */

/* Contents and shared table headers */
$lang['HlpContents']        = 'Sommaire';
$lang['HlpColField']        = 'Champ';
$lang['HlpColPurpose']      = 'Rôle';
$lang['HlpColOption']       = 'Option';
$lang['HlpColEffect']       = 'Effet';
$lang['HlpColAchievement']  = 'Distinction';
$lang['HlpColEarnedBy']     = 'Obtenue en';

/* 1. The idea */
$lang['HlpIdeaTitle']       = 'Le principe';
$lang['HlpIdeaP1']          = 'Une <b>formation</b> est une suite d\'<b>étapes</b> qui s\'affichent dans un panneau latéral, par-dessus n\'importe quelle page de ianseo. Chaque étape explique une action, peut la désigner visuellement — bouton surligné, flèche, info-bulle — puis attend qu\'elle ait réellement été faite avant de débloquer la suivante.';
$lang['HlpIdeaP2']          = 'Tout se construit depuis l\'éditeur visuel (<b>Nouvelle formation</b> ou <b>Éditer</b>) : pas besoin d\'écrire de code. Le JSON est généré pour vous, et reste accessible aux utilisateurs avancés dans la section repliée « JSON source ».';

/* 2. The course */
$lang['HlpCourseTitle']     = 'La formation';
$lang['HlpCourseFTitle']    = 'Titre';
$lang['HlpCourseVTitle']    = 'Le nom affiché dans le catalogue et en haut du panneau.';
$lang['HlpCourseFDesc']     = 'Description';
$lang['HlpCourseVDesc']     = 'Une phrase courte, affichée sous le titre dans le catalogue.';
$lang['HlpCourseFVersion']  = 'Version';
$lang['HlpCourseVVersion']  = 'Un numéro libre, par exemple <code>1.0</code>. Si vous l\'augmentez, les utilisateurs qui avaient terminé la version précédente sont signalés « version précédente ».';
$lang['HlpCourseFThumb']    = 'Vignette';
$lang['HlpCourseVThumb']    = 'Une image 16:9 optionnelle, affichée dans le catalogue pour reconnaître la formation d\'un coup d\'œil.';
$lang['HlpCourseFId']       = 'ID';
$lang['HlpCourseVId']       = 'Un identifiant unique, généré pour vous. À ne pas changer une fois la formation diffusée : c\'est la clé sur laquelle la progression est enregistrée.';

/* 3. The step */
$lang['HlpStepTitle']       = 'L\'étape';
$lang['HlpStepIntro']       = 'Chaque étape possède :';
$lang['HlpStepLiTitle']     = 'un <b>titre</b> ;';
$lang['HlpStepLiContent']   = 'un <b>contenu</b>, texte mis en forme — voir §4 ;';
$lang['HlpStepLiImage']     = 'une <b>image</b> optionnelle — voir §5 ;';
$lang['HlpStepLiPage']      = 'une <b>page par défaut</b> et ses <b>triggers</b> — voir §6 ;';
$lang['HlpStepLiOptions']   = 'des <b>options</b> : facultatif et non-permissif — voir §9.';
$lang['HlpStepOutro']       = 'Utilisez <b>+ Avant</b> et <b>+ Après</b> pour insérer des étapes, et les flèches <b>◀ ▶</b> pour naviguer entre elles. Le panneau de gauche est un <b>aperçu en temps réel</b> : ce que vous voyez correspond exactement à ce que verra l\'utilisateur.';

/* 4. Writing the content */
$lang['HlpContentTitle']    = 'Rédiger le contenu';
$lang['HlpContentIntro']    = 'La barre d\'outils au-dessus de l\'aperçu met le texte en forme :';
$lang['HlpContentLiFormat'] = '<b>gras</b>, <i>italique</i>, <u>souligné</u>, couleur du texte ;';
$lang['HlpContentLiLists']  = 'listes à puces et listes numérotées ;';
$lang['HlpContentLiTip']    = '<b>💡 Conseil</b> insère un encadré jaune, pour un conseil ou un avertissement ;';
$lang['HlpContentLiCode']   = '<b>&lt;/&gt;</b> met le texte sélectionné en style « code » — utile pour un nom de bouton ou un chemin.';
$lang['HlpContentTip']      = '<b>Dans un encadré conseil :</b> appuyez sur <b>Maj + Entrée</b> pour aller à la ligne <i>dans</i> l\'encadré. Appuyez sur <b>Entrée</b> seul pour <i>sortir</i> de l\'encadré et reprendre un texte normal en dessous.';

/* 5. Images */
$lang['HlpImagesTitle']     = 'Les images';
$lang['HlpImagesLiOne']     = 'Une image par étape au maximum, plus une vignette pour la formation.';
$lang['HlpImagesLiFormats'] = 'Les formats courants sont acceptés, <b>GIF animés compris</b>.';
$lang['HlpImagesLiRatio']   = 'L\'image est affichée en <b>16:9</b> : un autre format reçoit simplement des <b>bandes noires</b>, l\'image n\'est jamais déformée ni rognée.';
$lang['HlpImagesLiAbove']   = 'L\'image apparaît <b>au-dessus du texte</b> de l\'étape.';
$lang['HlpImagesLiOptional']= 'Les images sont <b>facultatives</b> : sans image, l\'étape s\'affiche normalement.';
$lang['HlpImagesNote']      = 'Les images sont intégrées à la formation elle-même, en base64. Évitez les fichiers lourds — un GIF de plusieurs mégaoctets ralentit la formation et sa synchronisation. L\'éditeur vous prévient au-delà de 2 Mo.';

/* 6. Triggers */
$lang['HlpTriggersTitle']   = 'Les triggers';
$lang['HlpTriggersIntro']   = 'Un trigger décrit ce que l\'utilisateur doit faire pour valider l\'étape. Une étape peut en contenir plusieurs : ils se déclenchent <b>dans l\'ordre</b> — glisser-déposer pour réordonner. Il existe deux familles.';
$lang['HlpTrigActionTitle'] = 'Action — l\'utilisateur fait quelque chose';
$lang['HlpTrigFPage']       = 'Page';
$lang['HlpTrigVPage']       = 'La page sur laquelle l\'action se fait. Vide = la page par défaut de l\'étape. <code>*</code> = <b>n\'importe quelle page</b>, ce dont les menus ont besoin puisqu\'ils existent partout.';
$lang['HlpTrigFType']       = 'Type';
$lang['HlpTrigVType']       = 'L\'événement attendu : clic, double-clic, changement, saisie, focus, survol, soumission… <code>— aucun</code> surligne l\'élément et laisse l\'utilisateur valider à la main.';
$lang['HlpTrigFSelector']   = 'Sélecteur';
$lang['HlpTrigVSelector']   = 'L\'élément, au format CSS — <code>#btnSave</code>, <code>.menu-item</code>. Il est mis en surbrillance, avec une flèche.';
$lang['HlpTrigFTooltip']    = 'Info-bulle';
$lang['HlpTrigVTooltip']    = 'Texte court optionnel affiché à côté de l\'élément surligné.';
$lang['HlpTrigFRequired']   = 'Oblig.';
$lang['HlpTrigVRequired']   = 'Si coché, l\'étape ne se valide pas tant que l\'action n\'est pas faite.';
$lang['HlpTrigStateTitle']  = 'État — on attend qu\'une condition devienne vraie';
$lang['HlpTrigStateP1']     = 'Au lieu d\'une action, l\'étape attend qu\'une <b>condition</b> dans ianseo soit remplie — « une compétition est ouverte », par exemple. Tant qu\'elle ne l\'est pas, un message l\'indique. La condition est revérifiée à chaque retour de l\'utilisateur sur l\'étape.';
$lang['HlpTrigStateP2']     = 'Une condition intégrée est toujours disponible : <b>📍 Page active</b>. Elle vérifie que l\'utilisateur se trouve sur une page donnée, indiquée dans le champ qui apparaît. Cette page <b>peut différer</b> de celle de l\'étape. Tant qu\'il n\'y est pas, l\'étape reste bloquée et le lien « Aller sur la page → » s\'affiche.';
$lang['HlpTrigStateP3']     = 'L\'autre condition intégrée est <b>🔎 Présence ou absence d\'un élément</b>. On donne un <b>sélecteur CSS</b> — les sélecteurs par préfixe <code>[id^="…"]</code> fonctionnent aussi — et on dit si l\'élément doit être <b>présent</b> ou <b>absent</b>. Utile pour attendre un message de confirmation, l\'ouverture d\'une fenêtre, la disparition d\'un indicateur de chargement. Cette condition est <b>revérifiée automatiquement</b>, car un tel élément peut apparaître ou disparaître sans que la page se recharge.';
$lang['HlpTrigStarNote']    = '<b>La page <code>*</code></b> fonctionne aussi bien comme page par défaut de l\'étape que sur chaque trigger individuellement. Un trigger en <code>*</code> reste actif quelle que soit la page affichée.';
$lang['HlpBranchTitle']     = 'Branches conditionnelles (⎇ Actif si…)';
$lang['HlpBranchIntro']     = 'Chaque trigger, action ou état, porte une condition d\'activation <b>⎇</b>. Par défaut un trigger est <b>toujours actif</b>. On peut le rendre conditionnel :';
$lang['HlpBranchLiIf']      = '<b>si</b> une condition est remplie — « si : une compétition est ouverte » ;';
$lang['HlpBranchLiIfNot']   = '<b>si PAS</b> une condition — « si PAS : une compétition est ouverte ».';
$lang['HlpBranchP']         = 'Un trigger dont la condition n\'est pas satisfaite est <b>ignoré</b>, et la séquence passe au suivant. C\'est ce qui permet à une formation de prendre des <b>chemins différents</b> selon l\'état de ianseo, puis de revenir à une suite commune :';
$lang['HlpBranchTip']       = 'Par exemple : « si PAS : une compétition est ouverte » sur un trigger qui guide la création d\'une compétition ; les triggers suivants, <b>sans condition</b>, sont communs aux deux cas. Pour un « sinon », mettez deux triggers — l\'un <i>si X</i>, l\'autre <i>si PAS X</i>.';

/* 7. Finding a CSS selector */
$lang['HlpSelectorsTitle']  = 'Trouver un sélecteur CSS';
$lang['HlpSelIntro']        = 'Pour cibler un élément — bouton, champ, lien :';
$lang['HlpSelLiInspect']    = 'Sur la page concernée, faites un <b>clic droit</b> sur l\'élément et choisissez <b>Inspecter</b> (ou touche <b>F12</b>).';
$lang['HlpSelLiId']         = 'Repérez son <code>id</code>, par exemple <code>id="btnSave"</code> : le sélecteur est <code>#btnSave</code>.';
$lang['HlpSelLiClass']      = 'À défaut d\'<code>id</code>, utilisez une classe — <code>class="btn-primary"</code> donne <code>.btn-primary</code>.';
$lang['HlpSelLiStable']     = 'Préférez toujours quelque chose de <b>stable et unique</b> sur la page.';
$lang['HlpSelTip']          = 'Dans l\'inspecteur, clic droit sur la ligne de l\'élément puis <b>Copier</b> → <b>Copier le sélecteur</b>.';
$lang['HlpSelDynTitle']     = 'Les id numérotés, qui changent à chaque fois';
$lang['HlpSelDynP1']        = 'Certains id de ianseo portent un numéro d\'enregistrement qui change selon le participant ou la compétition, comme <code>#d_q_QuSession_25360</code> ou <code>#d_QuD1Score_25360</code>. Un sélecteur exact ne fonctionnerait qu\'une fois. Utilisez un <b>sélecteur par préfixe</b> :';
$lang['HlpSelDynLiPrefix']  = '<code>[id^="d_q_QuSession_"]</code> — un id qui <b>commence par</b> ce préfixe ;';
$lang['HlpSelDynLiOther']   = '<code>[id$="_suffixe"]</code> — qui finit par ; <code>[id*="milieu"]</code> — qui contient.';
$lang['HlpSelDynP2']        = 'Le trigger se déclenche alors sur <b>n\'importe quel</b> élément correspondant — la case Départ de n\'importe quel participant, par exemple — et la flèche désigne le premier trouvé. L\'<b>enregistreur de triggers</b> repère ces id numérotés tout seul et écrit le sélecteur par préfixe à votre place.';

/* 8. Recording triggers */
$lang['HlpRecordTitle']     = 'Enregistrer les triggers automatiquement';
$lang['HlpRecIntro']        = 'Plutôt que de saisir les sélecteurs à la main, <b>🔴 Enregistrer les triggers</b>, dans les options de l\'étape, les capture en cliquant directement dans ianseo :';
$lang['HlpRecLiSaved']      = 'La formation est <b>d\'abord enregistrée</b>, puis vous êtes redirigé vers la page de l\'étape (ou l\'accueil).';
$lang['HlpRecLiPanel']      = 'Un <b>panneau rouge</b> apparaît. <b>Chaque clic</b> que vous faites dans ianseo est enregistré comme trigger, et le clic fonctionne normalement — vous pouvez naviguer entre les pages, l\'enregistrement continue.';
$lang['HlpRecLiPage']       = '<b>📍 Page active</b> ajoute un trigger d\'état qui vérifie la présence sur la page où vous êtes.';
$lang['HlpRecLiPause']      = '<b>⏸ Pause</b> suspend la capture, pour cliquer sans enregistrer. <b>↶ Annuler</b> retire le dernier trigger.';
$lang['HlpRecLiFinish']     = '<b>✓ Terminer</b> revient à l\'éditeur et ajoute ce qui a été capturé. <b>✕</b> abandonne.';
$lang['HlpRecTip']          = 'Ensuite, <b>relisez les triggers</b> — type, info-bulle, obligatoire — ajustez ce qui doit l\'être, puis <b>enregistrez la formation</b>. Les sélecteurs générés sont robustes mais pas infaillibles sur les éléments très dynamiques.';

/* 9. Step options */
$lang['HlpOptionsTitle']    = 'Options d\'étape';
$lang['HlpOptFOptional']    = 'Facultatif';
$lang['HlpOptVOptional']    = 'Affiche un bouton « Marquer comme fait » : l\'utilisateur peut valider l\'étape sans réaliser l\'action.';
$lang['HlpOptFStrict']      = 'Non-permissif';
$lang['HlpOptVStrict']      = 'Bloque tout clic <b>hors</b> de l\'élément attendu : l\'utilisateur ne peut interagir qu\'avec la cible. Le panneau clignote en rouge quand un clic est bloqué.';

/* 10. Quiz and challenge */
$lang['HlpActivitiesTitle'] = 'QCM et défi — bronze, argent et or';
$lang['HlpActIntro']        = 'Une formation peut proposer jusqu\'à <b>trois activités</b> : le <b>guide</b> pas-à-pas, un <b>QCM</b> et un <b>défi</b>. À la fin du guide, l\'utilisateur est invité à enchaîner sur le QCM, puis le défi, puis la formation suivante. Chaque activité réussie fait monter la distinction :';
$lang['HlpActBronze']       = 'Terminant le guide';
$lang['HlpActSilver']       = 'Le guide plus une autre activité';
$lang['HlpActGold']         = 'Toutes les activités proposées par la formation';
$lang['HlpActLiQuiz']       = '<b>QCM</b> (section « 📝 QCM » de l\'éditeur) : des questions à deux à quatre choix, <b>une ou plusieurs</b> bonnes réponses cochées — l\'utilisateur doit alors sélectionner exactement le bon ensemble — une explication optionnelle, un score minimal (70 % par défaut), et une option d\'affichage des réponses en <b>ordre aléatoire</b>.';
$lang['HlpActLiChallenge']  = '<b>Défi</b> (section « 🎯 Défi ») : une consigne, et des <b>conditions d\'état</b> qui vérifient le résultat dans ianseo, l\'utilisateur agissant sans aucune aide. Les conditions se créent dans le <b>constructeur de conditions</b> (bouton ⚡ de l\'administration), qui sait les tester en direct sur la compétition ouverte.';
$lang['HlpActShipped']      = 'Les conditions livrées avec le module couvrent le déroulé type d\'une compétition :';
$lang['HlpActLiState']      = '<b>au moins un participant inscrit</b>, <b>au moins un arbitre déclaré</b>, <b>au moins une cible attribuée</b>, <b>au moins un score saisi</b> — toutes vérifiées sur la compétition ouverte ;';
$lang['HlpActLiVisited']    = '<b>une page donnée a été visitée</b> — le check « <b>Page visitée</b> » du constructeur. Une visite est mémorisée <b>par utilisateur et par compétition</b> ; cochez « n\'importe quelle compétition » pour une visite globale. Idéal pour un défi du genre « envoie tes résultats à la fédération ».';

/* 11. Learning path and the other content types */
$lang['HlpPathTitle']       = 'Parcours, checklists, dépannage, aide contextuelle';
$lang['HlpPathLiPath']      = '<b>Parcours</b> : les champs <b>Groupe</b>, <b>Sous-groupe</b> et <b>Ordre</b> organisent le catalogue en sections ordonnées. La « formation suivante » proposée à la fin suit cet ordre.';
$lang['HlpPathLiChecklist'] = '<b>Checklists</b> : quelques questions à boutons, puis une liste de tâches adaptée aux réponses. Les items porteurs d\'une condition se cochent tout seuls. Création via « + Checklist » (édition JSON).';
$lang['HlpPathLiTrouble']   = '<b>Dépannage</b> : un arbre question → réponses → solution. Création via « + Dépannage » (édition JSON).';
$lang['HlpPathLiContext']   = '<b>Aide contextuelle</b> : activée par défaut. Le bouton flottant 🎯 affiche une pastille orange quand un contenu existe pour la page ianseo affichée. Désactivable depuis le catalogue ou le panneau.';

/* 12. User accounts */
$lang['HlpAccountsTitle']   = 'Comptes utilisateurs (serveur partagé)';
$lang['HlpAccountsP']       = 'Quand le serveur utilise un module de comptes, <b>chaque compte a sa propre progression</b> : formations en cours, étapes validées, QCM, défis et distinctions sont enregistrés par utilisateur, sans impacter les autres. La bannière « Apprendre à utiliser ianseo » s\'affiche pour un compte qui ne voit <b>aucune compétition</b>, signe d\'un nouvel organisateur. Les <b>formations</b> elles-mêmes restent communes à tout le serveur. Sans module de comptes, rien ne change : la progression est simplement celle de l\'installation.';

/* 13. Translating a course */
$lang['HlpTranslateTitle']  = 'Traduire une formation';
$lang['HlpTransP1']         = 'Une formation porte toutes les langues dans lesquelles elle a été écrite, <b>à l\'intérieur de son propre fichier</b> : elle reste un seul document avec un seul numéro de version, et le mécanisme de mise à jour n\'a qu\'une chose à comparer.';
$lang['HlpTransLiBase']     = '<b>Langue d\'origine</b> indique dans quelle langue la formation a été écrite — la langue de tous les champs pas encore traduits. La langue d\'édition ne peut pas répondre à cette question : elle dit où vous tapez maintenant, pas ce qu\'est déjà le texte existant. Sans elle, la première traduction d\'un champ rangerait le texte d\'origine sous la langue que vous étiez en train de taper, et la formation porterait son original comme traduction. Elle vaut votre propre langue par défaut, et ne se change que si vous reprenez une formation écrite par quelqu\'un d\'autre.';
$lang['HlpTransLiEdit']     = '<b>Langue d\'édition</b> choisit la langue que les champs affichent et écrivent. En changer ne touche pas aux autres langues.';
$lang['HlpTransLiEmpty']    = 'Un champ pas encore traduit s\'affiche <b>vide</b>, et non avec le texte d\'origine — pour que ce qui manque encore se voie d\'un coup d\'œil.';
$lang['HlpTransNote']       = 'Seul le <b>texte</b> d\'un champ est traduit. La structure autour — étapes, triggers, sélecteurs, pages — n\'est jamais dupliquée, ce qui empêche les langues de diverger. Une formation dont la version traduite désignerait un autre bouton que l\'originale serait pire qu\'une formation sans traduction du tout.';
$lang['HlpTransP2']         = 'Le lecteur obtient la formation dans la langue de son interface. À défaut, l\'anglais ; à défaut, la langue dans laquelle la formation a été écrite — une formation traduite dans une seule langue est donc quand même servie, jamais affichée vide. Une langue régionale compte au passage comme sa langue parente : un lecteur en français canadien obtient le texte français avant qu\'on envisage l\'anglais.';
