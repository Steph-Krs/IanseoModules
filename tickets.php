<?php
/**
 * Module AUTH — tickets.php
 * Dépôt d'un ticket (bug / demande d'évolution) par un organisateur connecté.
 * La gestion (tri, statut, suppression) se fait dans admin/tickets.php (ADMIN).
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

// Réservé aux organisateurs connectés (ou console locale sans auth) — même
// logique que la visibilité de l'entrée de menu, indépendante d'AUTH_ROOT.
if (empty($_SESSION['AUTH_User'])
    && !(empty($_SESSION['AUTH_ENABLE']) && isset($acl) && subFeatureAcl($acl, AclRoot, '') >= AclReadOnly)) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}
aut_ensure_schema();

// Compétition concernée : celle que l'organisateur a ouverte, s'il y en a une (« Nom (Code) »).
$openTour = '';
$openTid = intval($_SESSION['TourId'] ?? 0);
if ($openTid > 0) {
    $tr = safe_fetch(safe_r_sql("SELECT ToName, ToCode FROM Tournament WHERE ToId = " . $openTid));
    if ($tr) { $c = trim((string) $tr->ToCode); $openTour = trim((string) $tr->ToName) . ($c !== '' ? ' (' . $c . ')' : ''); }
}

$ok  = '';
$err = '';
$editId   = intval($_GET['edit'] ?? 0);
$kind     = $_POST['kind'] ?? 'bug';
$title    = trim($_POST['title'] ?? '');
$body     = trim($_POST['body'] ?? '');
$expected = trim($_POST['expected'] ?? '');
$page     = trim($_POST['page'] ?? '');

// Page concernée : pré-remplie depuis le référent si absente.
if ($page === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($p && stripos($p, '/tickets.php') === false) $page = $p;
}

// Identité du déposant (organisateur) : sert au dépôt ET à « Mes tickets ».
$user = $_SESSION['AUTH_User'] ?? 'console';
$role = '';
if (isset($_SESSION['AUTH_ROLE'])) {
    $role = (aut_roles()[$_SESSION['AUTH_ROLE']] ?? $_SESSION['AUTH_ROLE'])
          . (($_SESSION['AUTH_SCOPE'] ?? '') !== '' ? ' ' . $_SESSION['AUTH_SCOPE'] : '');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tid = intval($_POST['tid'] ?? 0);
    if (!aut_csrf_check()) {
        $err = 'Session expirée — merci de réessayer.';
        $editId = $tid;
    } elseif ($title === '' || $body === '') {
        $err = 'Indiquez au moins un titre et une description.';
        $editId = $tid;
    } elseif ($tid > 0) {   // modification d'un ticket existant
        if (aut_ticket_update($tid, $user, 'org', $kind, $title, $body, $expected, $page)) {
            // POST/Redirect/GET : un rafraîchissement ne re-poste rien.
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/tickets.php?ok=upd'); die();
        } else {
            $err = "Ce ticket n'est plus modifiable (pris en charge ou clôturé).";
            $editId = 0;
        }
    } else {                // nouveau dépôt
        aut_ticket_add($kind, $title, $body, $expected, $page, $user, $role, 'org', $openTour);
        CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/tickets.php?ok=new'); die();
    }
}

// Message de confirmation après redirection (le contenu du POST n'est plus rejoué).
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'upd') $ok = 'Votre ticket a été mis à jour.';
    elseif ($_GET['ok'] === 'new') $ok = 'Votre ticket a bien été enregistré. Vous pouvez suivre son évolution ci-dessous.';
}

// Chargement d'un ticket dans le formulaire pour modification (GET ?edit=).
if ($editId > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $et = aut_ticket_get($editId);
    if (aut_ticket_editable($et, $user, 'org')) {
        $kind = $et->TkKind; $title = $et->TkTitle; $body = (string) $et->TkBody;
        $expected = (string) $et->TkExpected; $page = (string) $et->TkPage;
    } else {
        $editId = 0;   // pas (ou plus) modifiable
    }
}
// Libellé affiché : la compétition figée du ticket en édition, sinon celle ouverte maintenant.
$tourLabel = ($editId > 0 && isset($et) && $et) ? (string) $et->TkTour : $openTour;

$mine = aut_ticket_my($user, 'org');

$isAdmin = !empty($_SESSION['AUTH_ROOT'])
    || (empty($_SESSION['AUTH_ENABLE']) && isset($acl) && subFeatureAcl($acl, AclRoot, '') == AclReadWrite);

$PAGE_TITLE = 'Signaler un bug / proposer une évolution';
include('Common/Templates/head.php');
?>
<style>
#aut-tk { max-width:760px; }
#aut-tk h1 { font-size:22px; color:#01367c; margin:0 0 6px; }
#aut-tk .aut-lead { color:#4c4e50; font-size:14px; margin:0 0 18px; }
#aut-tk .aut-card { background:#fff; border:1px solid #d2d4d6; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:18px 20px; }
#aut-tk label { display:block; font-weight:600; font-size:13px; color:#01367c; margin:14px 0 5px; }
#aut-tk input[type=text], #aut-tk textarea { width:100%; padding:9px 11px; font-size:14px;
    font-family:inherit; border:1px solid #d2d4d6; border-radius:6px; background:#fff; color:#20263d; }
#aut-tk textarea:focus, #aut-tk input:focus { outline:none; border-color:#0254a8; box-shadow:0 0 0 3px #a7d6ff; }
#aut-tk .aut-hint { font-size:12px; color:#7d8183; margin:4px 0 0; font-weight:400; }
#aut-tk .aut-kinds { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 4px; }
#aut-tk .aut-kind { flex:1 1 220px; border:2px solid #d2d4d6; border-radius:8px; padding:12px 14px;
    cursor:pointer; display:flex; gap:10px; align-items:flex-start; }
#aut-tk .aut-kind input { margin-top:3px; }
#aut-tk .aut-kind.on { border-color:#0254a8; background:#f0f4ff; }
#aut-tk .aut-kind b { color:#01367c; }
#aut-tk .aut-kind span { display:block; font-size:12px; color:#7d8183; margin-top:2px; }
#aut-tk .aut-btn { display:inline-block; margin-top:18px; padding:11px 22px; font-size:15px;
    font-weight:600; border:1px solid #0254a8; border-radius:6px; background:#0254a8; color:#fff; cursor:pointer; }
#aut-tk .aut-btn:hover { background:#01367c; border-color:#01367c; }
#aut-tk .aut-msg { padding:11px 14px; border-radius:6px; margin:0 0 16px; font-size:14px; }
#aut-tk .aut-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-tk .aut-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#aut-tk .aut-back { font-size:13px; margin-top:16px; }
#aut-tk .aut-mine-h { font-size:17px; color:#01367c; margin:26px 0 10px; }
#aut-tk .aut-mine { display:flex; flex-direction:column; gap:8px; }
#aut-tk .aut-mt { background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8;
    border-radius:8px; padding:10px 14px; }
#aut-tk .aut-mt-new { border-left-color:#0254a8; }
#aut-tk .aut-mt-in_progress { border-left-color:#cb8137; }
#aut-tk .aut-mt-done { border-left-color:#1a8a3f; }
#aut-tk .aut-mt-rejected { border-left-color:#a80000; }
#aut-tk .aut-mt-head { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:12px; color:#7d8183; }
#aut-tk .aut-mt-kind { font-weight:700; color:#01367c; }
#aut-tk .aut-mt-status { margin-left:auto; font-weight:700; }
#aut-tk .aut-s-new { color:#0254a8; }
#aut-tk .aut-s-done { color:#1a8a3f; }
#aut-tk .aut-s-rejected { color:#a80000; }
#aut-tk .aut-mt-title { font-size:15px; color:#20263d; margin:4px 0 0; }
#aut-tk .aut-mt-resp { margin:8px 0 0; padding:8px 10px; font-size:13px; background:#f0f4ff;
    border:1px solid #a7d6ff; border-radius:6px; color:#20263d; }
#aut-tk .aut-s-in_progress { color:#cb8137; }
#aut-tk .aut-mt-score { color:#e6a700; letter-spacing:1px; font-size:13px; }
#aut-tk .aut-mt-score i { color:#d9dce3; font-style:normal; }
#aut-tk .aut-mt-score-n { color:#7d8183; font-size:11px; letter-spacing:0; margin-left:3px; }
#aut-tk .aut-mt-act { margin-top:8px; }
#aut-tk .aut-mt-locked { font-size:12px; color:#7d8183; font-style:italic; }
#aut-tk .aut-editing { background:#fff6e6; border:1px solid #e8b96a; color:#8a5a26; border-radius:6px;
    padding:8px 12px; margin:0 0 12px; font-size:13px; }
</style>

<div id="aut-tk">
<h1>Signaler un bug / proposer une évolution</h1>
<p class="aut-lead">Décrivez le plus précisément possible : plus votre demande est claire
   (ce que vous attendez, le rendu souhaité), plus elle sera traitée rapidement.</p>

<?php if ($ok): ?>
  <div class="aut-msg aut-ok"><?= htmlspecialchars($ok) ?>
    <?php if ($isAdmin): ?><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/admin/tickets.php">Voir les tickets</a>.<?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($err): ?><div class="aut-msg aut-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="post" class="aut-card" id="autform">
  <?= aut_csrf_field() ?>
  <input type="hidden" name="tid" value="<?= intval($editId) ?>">

  <?php if ($editId): ?>
    <div class="aut-editing">✎ Modification de votre ticket —
      <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/tickets.php">annuler</a></div>
  <?php endif; ?>

  <label>Type de demande</label>
  <div class="aut-kinds">
    <label class="aut-kind" data-kind="bug">
      <input type="radio" name="kind" value="bug" <?= $kind !== 'evolution' ? 'checked' : '' ?>>
      <span><b>Bug</b><span>Quelque chose ne fonctionne pas comme prévu.</span></span>
    </label>
    <label class="aut-kind" data-kind="evolution">
      <input type="radio" name="kind" value="evolution" <?= $kind === 'evolution' ? 'checked' : '' ?>>
      <span><b>Évolution</b><span>Une amélioration ou une nouvelle fonctionnalité.</span></span>
    </label>
  </div>

  <label for="tk-title" id="lab-title">Résumé court</label>
  <input type="text" id="tk-title" name="title" maxlength="160" value="<?= htmlspecialchars($title) ?>"
         placeholder="En une phrase">

  <label for="tk-body" id="lab-body">Description</label>
  <textarea id="tk-body" name="body" rows="4" maxlength="5000"><?= htmlspecialchars($body) ?></textarea>
  <p class="aut-hint" id="hint-body"></p>

  <label for="tk-expected" id="lab-expected">Précisions</label>
  <textarea id="tk-expected" name="expected" rows="4" maxlength="5000"><?= htmlspecialchars($expected) ?></textarea>
  <p class="aut-hint" id="hint-expected"></p>

  <label for="tk-page">Page ou écran concerné <span class="aut-hint" style="font-weight:400">(facultatif)</span></label>
  <input type="text" id="tk-page" name="page" maxlength="255" value="<?= htmlspecialchars($page) ?>"
         placeholder="ex. Inscriptions en ligne, calendrier…">

  <?php if ($tourLabel !== ''): ?>
    <label>Compétition concernée</label>
    <p class="aut-hint" style="margin:0;font-size:14px;color:#20263d"><?= htmlspecialchars($tourLabel) ?>
      <span class="aut-hint">(compétition ouverte au moment du signalement)</span></p>
  <?php endif; ?>

  <button type="submit" class="aut-btn"><?= $editId ? 'Mettre à jour le ticket' : 'Envoyer le ticket' ?></button>
</form>

<?php if ($mine): ?>
<h2 class="aut-mine-h">Mes tickets</h2>
<div class="aut-mine">
  <?php foreach ($mine as $t):
      $st = aut_ticket_statuses()[$t->TkStatus] ?? $t->TkStatus;
      $stars = (int) round(intval($t->TkScore) / 20);
      $edit = aut_ticket_editable($t, $user, 'org'); ?>
    <div class="aut-mt aut-mt-<?= htmlspecialchars($t->TkStatus) ?>">
      <div class="aut-mt-head">
        <span class="aut-mt-kind"><?= htmlspecialchars(aut_ticket_kinds()[$t->TkKind] ?? $t->TkKind) ?></span>
        <span class="aut-mt-date"><?= htmlspecialchars(date('d/m/Y', strtotime($t->TkCreated))) ?></span>
        <span class="aut-mt-score" title="Indice de précision : plus votre demande est détaillée, plus il monte">
          <?php for ($i = 0; $i < 5; $i++) echo $i < $stars ? '★' : '<i>☆</i>'; ?>
          <span class="aut-mt-score-n"><?= intval($t->TkScore) ?>/100</span></span>
        <span class="aut-mt-status aut-s-<?= htmlspecialchars($t->TkStatus) ?>"><?= htmlspecialchars($st) ?></span>
      </div>
      <div class="aut-mt-title"><?= htmlspecialchars($t->TkTitle) ?></div>
      <?php if (trim((string) ($t->TkTour ?? '')) !== ''): ?>
        <div class="aut-hint" style="margin-top:2px">🏆 <?= htmlspecialchars($t->TkTour) ?></div>
      <?php endif; ?>
      <?php if (trim((string) $t->TkResponse) !== ''): ?>
        <div class="aut-mt-resp"><b>Réponse :</b> <?= nl2br(htmlspecialchars($t->TkResponse)) ?></div>
      <?php endif; ?>
      <div class="aut-mt-act">
        <?php if ($edit): ?>
          <a class="aut-mt-edit" href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/tickets.php?edit=<?= intval($t->TkId) ?>">Modifier / préciser</a>
        <?php else: ?>
          <span class="aut-mt-locked">Non modifiable (pris en charge ou clôturé)</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="aut-back"><a href="<?= $CFG->ROOT_DIR ?>index.php">← Retour</a></p>
</div>

<script>
(function () {
  var LABELS = {
    bug: {
      title: 'Résumé court du problème',
      body: 'Que se passe-t-il ?',
      bodyHint: 'Ce que vous faisiez, ce qui s’est affiché, le message d’erreur éventuel.',
      expected: 'Étapes pour reproduire / ce que vous attendiez',
      expectedHint: 'Les étapes précises aident à retrouver le problème.'
    },
    evolution: {
      title: 'Résumé court de votre idée',
      body: 'Qu’aimeriez-vous ?',
      bodyHint: 'Le besoin, l’objectif, le contexte d’usage.',
      expected: 'Comment cela devrait fonctionner / quel rendu attendu ?',
      expectedHint: 'Le plus important : décrivez le comportement ou l’affichage souhaité.'
    }
  };
  function apply(kind) {
    var L = LABELS[kind] || LABELS.bug;
    document.getElementById('lab-title').textContent = L.title;
    document.getElementById('lab-body').textContent = L.body;
    document.getElementById('hint-body').textContent = L.bodyHint;
    document.getElementById('lab-expected').textContent = L.expected;
    document.getElementById('hint-expected').textContent = L.expectedHint;
    Array.prototype.forEach.call(document.querySelectorAll('.aut-kind'), function (el) {
      el.classList.toggle('on', el.getAttribute('data-kind') === kind);
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[name="kind"]'), function (r) {
    r.addEventListener('change', function () { apply(this.value); });
  });
  var cur = document.querySelector('input[name="kind"]:checked');
  apply(cur ? cur.value : 'bug');
})();
</script>
<?php include('Common/Templates/tail.php'); ?>
