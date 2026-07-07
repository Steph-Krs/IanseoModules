<?php
define('HTDOCS', dirname(dirname(dirname(dirname(dirname(__FILE__))))));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib/guide-lib.inc.php');

guide_check_admin();

$PAGE_TITLE = 'Guide FFTA — Aide à la création';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>

<style>
.help-wrap { max-width: 860px; line-height: 1.6; color: #333; }
.help-wrap h2 { color: #0254a8; font-size: 18px; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #dde6f5; }
.help-wrap h3 { color: #082c7c; font-size: 15px; margin: 18px 0 6px; }
.help-wrap p  { margin: 0 0 10px; }
.help-wrap ul { margin: 0 0 12px; padding-left: 20px; }
.help-wrap li { margin-bottom: 5px; }
.help-wrap code { background: #eef2ff; border: 1px solid #c5cef5; border-radius: 3px; padding: 1px 6px; font-size: 12px; color: #082c7c; font-weight: 600; }
.help-wrap table { border-collapse: collapse; width: 100%; margin: 6px 0 14px; font-size: 13px; }
.help-wrap th { background: #0254a8; color: #fff; padding: 7px 11px; text-align: left; }
.help-wrap td { padding: 7px 11px; border-bottom: 1px solid #eef0f8; vertical-align: top; }
.help-wrap tr:hover td { background: #f7f9ff; }
.help-tip { background: #fff8e6; border-left: 3px solid #f5a623; padding: 9px 13px; border-radius: 0 6px 6px 0; margin: 10px 0; color: #664d00; font-size: 13px; }
.help-note { background: #eef4ff; border-left: 3px solid #0254a8; padding: 9px 13px; border-radius: 0 6px 6px 0; margin: 10px 0; font-size: 13px; }
.help-toc { background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 8px; padding: 12px 18px; margin-bottom: 10px; }
.help-toc a { color: #0254a8; text-decoration: none; }
.help-toc a:hover { text-decoration: underline; }
</style>

<h1>Guide FFTA — Comment créer une formation</h1>
<p><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/GUIDE/admin/">← Retour à l'administration</a></p>

<div class="help-wrap">

<div class="help-toc">
  <b>Sommaire</b>
  <ul style="margin:6px 0 0">
    <li><a href="#concept">1. Le principe</a></li>
    <li><a href="#formation">2. La formation</a></li>
    <li><a href="#etape">3. L'étape</a></li>
    <li><a href="#contenu">4. Rédiger le contenu</a></li>
    <li><a href="#images">5. Les images</a></li>
    <li><a href="#triggers">6. Les triggers (déclencheurs)</a></li>
    <li><a href="#selecteurs">7. Trouver un sélecteur CSS</a></li>
    <li><a href="#enregistrer">8. Enregistrer les triggers automatiquement</a></li>
    <li><a href="#options">9. Options d'étape</a></li>
    <li><a href="#activites">10. QCM et Défi (cibles bronze/argent/or)</a></li>
    <li><a href="#parcours">11. Parcours, checklists, FAQ, aide contextuelle</a></li>
    <li><a href="#comptes">12. Comptes utilisateurs (serveur en ligne)</a></li>
  </ul>
</div>

<h2 id="concept">1. Le principe</h2>
<p>
  Une <b>formation</b> est une suite d'<b>étapes</b> qui s'affichent dans un panneau latéral,
  par-dessus n'importe quelle page de ianseo. Chaque étape explique une action à l'utilisateur
  et peut le guider visuellement (surbrillance d'un bouton, flèche, info-bulle) puis attendre
  qu'il réalise l'action avant de débloquer l'étape suivante.
</p>
<p>
  Tout se crée depuis l'éditeur visuel (<b>Nouvelle formation</b> ou <b>Éditer</b>) :
  pas besoin d'écrire de code. Le JSON est généré automatiquement, mais reste accessible
  pour les utilisateurs avancés (section repliée « JSON source »).
</p>

<h2 id="formation">2. La formation</h2>
<table>
  <tr><th>Champ</th><th>Rôle</th></tr>
  <tr><td><b>Titre</b></td><td>Nom affiché dans le catalogue et en haut du panneau.</td></tr>
  <tr><td><b>Description</b></td><td>Courte phrase d'accroche, affichée sous le titre dans le catalogue.</td></tr>
  <tr><td><b>Version</b></td><td>Numéro libre (ex. <code>1.0</code>). Si vous l'augmentez, les utilisateurs qui avaient terminé l'ancienne version sont signalés « version précédente ».</td></tr>
  <tr><td><b>Vignette</b></td><td>Image optionnelle (16:9) affichée dans le catalogue pour identifier la formation d'un coup d'œil.</td></tr>
  <tr><td><b>ID</b></td><td>Identifiant unique auto-généré. À ne pas changer une fois la formation diffusée.</td></tr>
</table>

<h2 id="etape">3. L'étape</h2>
<p>Chaque étape possède :</p>
<ul>
  <li>un <b>titre</b> ;</li>
  <li>un <b>contenu</b> (texte mis en forme, voir §4) ;</li>
  <li>une <b>image</b> optionnelle (voir §5) ;</li>
  <li>une <b>page par défaut</b> et des <b>triggers</b> (voir §6) ;</li>
  <li>des <b>options</b> (facultatif, non-permissif — voir §8).</li>
</ul>
<p>
  Utilisez les boutons <b>+ Avant</b> / <b>+ Après</b> pour insérer des étapes, et les flèches
  <b>◀ ▶</b> pour naviguer entre elles. Le panneau de gauche est une <b>aperçu en temps réel</b> :
  ce que vous voyez correspond exactement à ce que verra l'utilisateur.
</p>

<h2 id="contenu">4. Rédiger le contenu</h2>
<p>La barre d'outils au-dessus de l'aperçu permet de mettre en forme le texte :</p>
<ul>
  <li><b>Gras</b>, <i>italique</i>, <u>souligné</u>, couleur du texte ;</li>
  <li>listes à puces et listes numérotées ;</li>
  <li><b>💡 Conseil</b> : insère un encadré jaune (conseil / avertissement) ;</li>
  <li><b>&lt;/&gt;</b> : met le texte sélectionné en style « code » (utile pour un nom de bouton, un chemin…).</li>
</ul>
<div class="help-tip">
  <b>Astuce encadré conseil :</b> à l'intérieur d'un encadré, appuyez sur <b>Maj + Entrée</b>
  pour aller à la ligne <i>dans</i> l'encadré. Appuyez sur <b>Entrée</b> seul pour <i>sortir</i>
  de l'encadré et reprendre un texte normal en dessous.
</div>

<h2 id="images">5. Les images</h2>
<ul>
  <li>Une image par étape maximum, plus une vignette pour la formation.</li>
  <li>Formats classiques acceptés, <b>y compris les GIF animés</b>.</li>
  <li>L'image est automatiquement affichée en <b>16:9</b> : si elle a un autre format, des
      <b>bandes noires</b> sont ajoutées (l'image n'est jamais déformée ni rognée).</li>
  <li>L'image s'affiche <b>au-dessus du texte</b> de l'étape.</li>
  <li>Les images sont <b>facultatives</b> : sans image, l'étape s'affiche normalement.</li>
</ul>
<div class="help-note">
  Les images sont intégrées directement dans la formation (format base64). Évitez les fichiers
  trop lourds — un GIF de plusieurs Mo alourdit la formation et sa synchronisation. L'éditeur
  vous prévient au-delà de 2 Mo.
</div>

<h2 id="triggers">6. Les triggers (déclencheurs)</h2>
<p>
  Un trigger décrit ce que l'utilisateur doit faire pour valider l'étape. Une étape peut en
  contenir plusieurs : ils se déclenchent <b>dans l'ordre</b> (glisser-déposer pour réordonner).
  Il existe deux familles :
</p>

<h3>⚡ Action — l'utilisateur fait quelque chose</h3>
<table>
  <tr><th>Champ</th><th>Rôle</th></tr>
  <tr><td><b>Page</b></td><td>Page sur laquelle l'action doit se faire. Vide = la page par défaut de l'étape. <code>*</code> = <b>n'importe quelle page</b> (pratique pour les menus, présents partout).</td></tr>
  <tr><td><b>Type</b></td><td>Événement attendu : clic, double-clic, changement, saisie, focus, survol, soumission… (<code>— aucun</code> = simple surbrillance, l'utilisateur valide à la main).</td></tr>
  <tr><td><b>Sélecteur</b></td><td>Élément ciblé, au format CSS (ex. <code>#btnSave</code>, <code>.menu-item</code>). Il est mis en surbrillance avec une flèche.</td></tr>
  <tr><td><b>Info-bulle</b></td><td>Petit texte optionnel affiché à côté de l'élément surligné.</td></tr>
  <tr><td><b>Oblig.</b></td><td>Si coché, l'étape ne se valide pas tant que l'action n'est pas faite.</td></tr>
</table>

<h3>✓ État — on attend qu'une condition soit vraie</h3>
<p>
  Au lieu d'une action, l'étape attend qu'une <b>condition</b> côté ianseo soit remplie
  (ex. « une compétition est ouverte »). Tant qu'elle ne l'est pas, un message l'indique.
  La condition est re-vérifiée à chaque visite de l'étape.
</p>
<p>
  Une condition intégrée est toujours disponible : <b>📍 Page active</b>. Elle vérifie que
  l'utilisateur se trouve bien sur une page donnée — indiquée dans le champ qui apparaît
  alors. Cette page <b>peut être différente</b> de la page de l'étape. Tant que l'utilisateur
  n'y est pas, l'étape reste bloquée et le lien « Aller sur la page → » s'affiche.
</p>
<p>
  Autre condition intégrée : <b>🔎 Présence / absence d'un élément</b>. On indique un
  <b>sélecteur CSS</b> (les sélecteurs dynamiques <code>[id^="…"]</code> fonctionnent) et si
  l'élément doit être <b>présent</b> ou <b>absent</b> à l'écran. Utile pour attendre l'apparition
  d'un message de confirmation, l'ouverture d'une fenêtre, la disparition d'un chargement, etc.
  La condition est <b>re-vérifiée automatiquement</b> (l'élément peut apparaître ou disparaître
  sans rechargement de page).
</p>

<div class="help-note">
  <b>Le sélecteur <code>*</code> pour la page</b> fonctionne aussi bien au niveau de la
  <b>page par défaut de l'étape</b> qu'au niveau de <b>chaque trigger individuellement</b>.
  Un trigger en <code>*</code> reste actif quelle que soit la page affichée.
</div>

<h3>Branches conditionnelles (⎇ Actif si…)</h3>
<p>
  Chaque trigger (action ou état) a un sélecteur <b>⎇</b> « condition d'activation ». Par défaut
  le trigger est <b>toujours actif</b>. On peut le rendre <b>conditionnel</b> :
</p>
<ul>
  <li><b>si</b> une condition est remplie (ex. « si : Compétition ouverte ») ;</li>
  <li><b>si PAS</b> une condition (ex. « si PAS : Compétition ouverte »).</li>
</ul>
<p>
  Un trigger dont la condition n'est pas satisfaite est <b>ignoré</b> (la séquence passe au suivant).
  Cela permet des <b>parcours différents</b> selon l'état de ianseo, puis de revenir à une suite commune :
</p>
<div class="help-tip">
  Exemple : « si PAS : Compétition ouverte » → trigger qui guide la création d'une compétition ;
  les triggers suivants <b>sans condition</b> sont communs aux deux cas. Astuce : pour un « sinon »,
  mettez deux triggers, l'un en <i>si X</i>, l'autre en <i>si PAS X</i>.
</div>

<h2 id="selecteurs">7. Trouver un sélecteur CSS</h2>
<p>Pour cibler un élément (bouton, champ, lien) :</p>
<ul>
  <li>Sur la page concernée, faites un <b>clic droit</b> sur l'élément → <b>Inspecter</b> (ou touche <b>F12</b>).</li>
  <li>Repérez son <code>id</code> (ex. <code>id="btnSave"</code>) → le sélecteur est <code>#btnSave</code>.</li>
  <li>À défaut d'<code>id</code>, utilisez une classe (ex. <code>class="btn-primary"</code>) → <code>.btn-primary</code>.</li>
  <li>Préférez toujours un identifiant <b>stable et unique</b> sur la page.</li>
</ul>
<div class="help-tip">
  Astuce : dans l'inspecteur, clic droit sur la ligne de l'élément → <b>Copier</b> → <b>Copier le sélecteur</b>.
</div>

<h3>Sélecteurs dynamiques (id qui change à chaque fois)</h3>
<p>
  Certains id ianseo contiennent un numéro d'enregistrement qui change selon le participant ou la
  compétition (ex. <code>#d_q_QuSession_25360</code>, <code>#d_QuD1Score_25360</code>). Un sélecteur
  exact ne fonctionnerait qu'une fois. Utilisez un <b>sélecteur par préfixe</b> :
</p>
<ul>
  <li><code>[id^="d_q_QuSession_"]</code> — id qui <b>commence par</b> ce préfixe ;</li>
  <li><code>[id$="_suffixe"]</code> — id qui <b>finit par</b> ; <code>[id*="milieu"]</code> — id qui <b>contient</b>.</li>
</ul>
<p>
  Le trigger se déclenche alors sur <b>n'importe quel</b> élément correspondant (ex. la case Session de
  n'importe quel participant), et la flèche indique le premier trouvé.
  L'<b>enregistreur de triggers</b> détecte automatiquement ces id à suffixe numérique et génère
  le sélecteur par préfixe à votre place.
</p>

<h2 id="enregistrer">8. Enregistrer les triggers automatiquement</h2>
<p>
  Plutôt que de saisir les sélecteurs à la main, le bouton <b>🔴 Enregistrer les triggers</b>
  (dans les options de l'étape) permet de les capturer en cliquant directement dans ianseo :
</p>
<ul>
  <li>La formation est <b>d'abord enregistrée</b>, puis vous êtes redirigé vers la page de l'étape (ou l'accueil).</li>
  <li>Un <b>panneau rouge</b> apparaît. <b>Chaque clic</b> que vous faites dans ianseo est enregistré comme trigger
      (le clic fonctionne normalement — vous pouvez naviguer entre les pages, l'enregistrement continue).</li>
  <li><b>📍 Page active</b> : ajoute un trigger d'état qui vérifie la présence sur la page courante.</li>
  <li><b>⏸ Pause</b> : suspend la capture (pour cliquer sans enregistrer). <b>↶ Annuler</b> : retire le dernier trigger.</li>
  <li><b>✓ Terminer</b> : revient à l'éditeur et ajoute les triggers capturés à l'étape. <b>✕</b> : abandonne.</li>
</ul>
<div class="help-tip">
  Après l'enregistrement, <b>relisez les triggers</b> ajoutés (type, info-bulle, obligatoire…), ajustez si besoin,
  puis <b>enregistrez la formation</b>. Les sélecteurs générés sont robustes mais pas infaillibles sur les
  éléments très dynamiques.
</div>

<h2 id="options">9. Options d'étape</h2>
<table>
  <tr><th>Option</th><th>Effet</th></tr>
  <tr><td><b>Facultatif</b></td><td>Affiche un bouton « Marquer comme fait » : l'utilisateur peut valider l'étape lui-même sans réaliser l'action.</td></tr>
  <tr><td><b>Non-permissif</b></td><td>Bloque tout clic <b>hors</b> de l'élément attendu : l'utilisateur ne peut interagir qu'avec la cible. Le panneau clignote en rouge si un clic est bloqué.</td></tr>
</table>

<h2 id="activites">10. QCM et Défi — cibles bronze / argent / or</h2>
<p>
  Chaque formation peut proposer jusqu'à <b>trois activités</b> : le <b>guide</b> pas-à-pas, un
  <b>QCM</b> et un <b>défi</b>. À la fin du guide, l'utilisateur est invité à enchaîner sur le QCM,
  puis le défi, puis la formation suivante. Chaque activité réussie fait progresser la distinction :
</p>
<table>
  <tr><th>Distinction</th><th>Condition</th></tr>
  <tr><td>🎯 Cible de bronze</td><td>Guide terminé</td></tr>
  <tr><td>🎯 Cible d'argent</td><td>Guide + une autre activité</td></tr>
  <tr><td>🎯 Cible d'or</td><td>Toutes les activités disponibles réussies</td></tr>
</table>
<ul>
  <li><b>QCM</b> (section « 📝 QCM » de l'éditeur) : questions à 2-4 choix, <b>une ou plusieurs</b>
      bonnes réponses cochées (l'utilisateur doit alors sélectionner exactement les bonnes), explication
      optionnelle, score minimal (70 % par défaut), option d'affichage des réponses en <b>ordre aléatoire</b>.</li>
  <li><b>Défi</b> (section « 🎯 Défi ») : une consigne, et des <b>conditions d'état</b> qui vérifient
      le résultat dans ianseo (l'utilisateur agit sans aucune aide). Les conditions se créent dans le
      <b>constructeur de conditions</b> (bouton ⚡ de l'administration), avec un test en direct sur la
      compétition ouverte.</li>
</ul>
<p>Des conditions prêtes à l'emploi couvrent le déroulé type d'une compétition FFTA :</p>
<ul>
  <li><b>Au moins 1 participant inscrit</b>, <b>au moins 1 arbitre déclaré</b> (officiels de type
      arbitre), <b>au moins 1 cible attribuée</b>, <b>au moins 1 score saisi</b> — vérifiées sur la
      compétition ouverte ;</li>
  <li><b>Page de téléchargement du fichier résultats FFTA visitée</b> — utilise le check
      « <b>Page visitée</b> » du constructeur : la visite d'une page donnée est mémorisée
      <b>par utilisateur et par compétition</b> (cochez « n'importe quelle compétition » pour une
      visite globale). Idéal pour un défi « envoie tes résultats à la fédération ».</li>
</ul>

<h2 id="parcours">11. Parcours, checklists, FAQ, aide contextuelle</h2>
<ul>
  <li><b>Parcours</b> : les champs <b>Groupe</b>, <b>Sous-groupe</b> et <b>Ordre</b> de la formation
      organisent le catalogue en sections ordonnées. La « formation suivante » proposée en fin de
      formation suit cet ordre.</li>
  <li><b>Checklists</b> : quelques questions à boutons, puis une liste de tâches adaptée aux réponses.
      Les items avec une condition se cochent automatiquement. Création via « + Checklist » (édition JSON).</li>
  <li><b>FAQ interactive</b> : un arbre question → réponses → solution, pour guider le dépannage.
      Création via « + FAQ » (édition JSON).</li>
  <li><b>Aide contextuelle</b> : activée par défaut, le bouton flottant 🎯 signale (pastille orange)
      les contenus liés à la page ianseo affichée. Désactivable depuis le catalogue ou le panneau.</li>
</ul>

<h2 id="comptes">12. Comptes utilisateurs (serveur en ligne)</h2>
<p>
  Si le serveur utilise un module de comptes (authentification ianseo, ex. serveur fédéral),
  <b>chaque compte a son propre suivi</b> : formations en cours, étapes validées, QCM, défis et
  distinctions sont enregistrés par utilisateur, sans impacter les autres. La bannière
  « Apprendre à utiliser ianseo » s'affiche pour un compte qui ne voit encore <b>aucune
  compétition</b> (nouvel organisateur). Les <b>formations</b> elles-mêmes restent communes à tout
  le serveur. Sans module de comptes, rien ne change : le suivi est simplement celui de
  l'installation.
</p>
<ul>
  <li><b>Administration réservée</b> : ces pages d'administration (éditeur, conditions, mises à
      jour…) ne sont accessibles qu'à l'<b>administrateur du serveur</b> quand l'authentification
      est active — un compte club/comité n'y a pas accès et ne voit pas l'entrée de menu.</li>
  <li><b>Aide contextuelle</b> : le réglage (activée/désactivée) est <b>propre à chaque compte</b>
      et le suit d'un ordinateur à l'autre. Sans compte, il reste mémorisé dans le navigateur.</li>
</ul>

<div class="help-note" style="margin-top:24px">
  Une fois la formation prête, enregistrez-la. Elle apparaît immédiatement dans le catalogue
  et peut être partagée via le système de mises à jour GitHub (page <b>Mises à jour</b>).
</div>

</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
