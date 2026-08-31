<?php
/**
 * booking/public/tickets.php — Signaler un bug / proposer une évolution (compétiteur).
 *
 * Réutilise le système de tickets du module AUTH (fusionné). Le compétiteur est
 * identifié par sa licence (bk_require_archer) ; canal 'archer' → il ne voit que
 * ses propres tickets. Habillage public #bk, CSRF BOOKING.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib.php';   // fonctions aut_ticket_* du module AUTH

$archer = bk_require_archer();
aut_ensure_schema();

$ok  = '';
$err = '';
$editId   = intval($_GET['edit'] ?? 0);
$kind     = $_POST['kind'] ?? 'bug';
$title    = trim($_POST['title'] ?? '');
$body     = trim($_POST['body'] ?? '');
$expected = trim($_POST['expected'] ?? '');
$page     = trim($_POST['page'] ?? '');
if ($page === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($p && stripos($p, '/tickets.php') === false) $page = $p;
}

$lic = $archer->BaLicence;

// Compétition concernée : l'id vient du champ caché (POST), de ?t=, ou de la fiche d'où
// l'archer arrive (referer ?t=). On résout le NOM en base (id → « Nom (Code) ») : le libellé
// n'est donc jamais dicté par le client, seulement un id d'où le nom réel est relu.
$tourId = intval($_POST['tour_id'] ?? $_GET['t'] ?? 0);
if ($tourId <= 0 && !empty($_SERVER['HTTP_REFERER'])) {
    parse_str((string) parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $rq);
    if (!empty($rq['t'])) $tourId = intval($rq['t']);
}
$tourLabel = '';
if ($tourId > 0) {
    $tr = safe_fetch(safe_r_sql("SELECT ToName, ToCode FROM Tournament WHERE ToId = " . $tourId));
    if ($tr) { $tc = trim((string) $tr->ToCode); $tourLabel = trim((string) $tr->ToName) . ($tc !== '' ? ' (' . $tc . ')' : ''); }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tid = intval($_POST['tid'] ?? 0);
    if (!bk_csrf_check()) {
        $err = 'Session expirée — merci de réessayer.'; $editId = $tid;
    } elseif ($title === '' || $body === '') {
        $err = 'Indiquez au moins un titre et une description.'; $editId = $tid;
    } elseif ($tid > 0) {
        if (aut_ticket_update($tid, $lic, 'archer', $kind, $title, $body, $expected, $page)) {
            bk_redirect('tickets.php?ok=upd');   // POST/Redirect/GET : F5 ne re-poste rien
        } else {
            $err = "Ce ticket n'est plus modifiable (pris en charge ou clôturé)."; $editId = 0;
        }
    } else {
        aut_ticket_add($kind, $title, $body, $expected, $page, $lic,
            trim($archer->BaFamilyName . ' ' . $archer->BaName), 'archer', $tourLabel);
        bk_redirect('tickets.php?ok=new');
    }
}

// Message de confirmation après redirection (le POST n'est plus rejoué au rafraîchissement).
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'upd') $ok = 'Votre ticket a été mis à jour.';
    elseif ($_GET['ok'] === 'new') $ok = 'Merci ! Votre ticket a bien été enregistré. Vous pouvez suivre son évolution ci-dessous.';
}

// Chargement d'un ticket dans le formulaire pour modification (GET ?edit=).
if ($editId > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $et = aut_ticket_get($editId);
    if (aut_ticket_editable($et, $lic, 'archer')) {
        $kind = $et->TkKind; $title = $et->TkTitle; $body = (string) $et->TkBody;
        $expected = (string) $et->TkExpected; $page = (string) $et->TkPage;
        $tourLabel = (string) $et->TkTour;   // compétition figée du ticket en édition
    } else {
        $editId = 0;
    }
}

$mine     = aut_ticket_my($lic, 'archer');
$kinds    = aut_ticket_kinds();
$statuses = aut_ticket_statuses();

bk_head('Signaler');
?>
<style>
#bk .bktk-kinds { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 6px; }
#bk .bktk-kind { flex:1 1 220px; border:2px solid #d2d4d6; border-radius:8px; padding:12px 14px;
    cursor:pointer; display:flex; gap:10px; align-items:flex-start; background:#fff; }
#bk .bktk-kind input { margin-top:3px; }
#bk .bktk-kind.on { border-color:#0254a8; background:#f0f4ff; }
#bk .bktk-kind b { color:#01367c; }
#bk .bktk-kind span { display:block; font-size:12px; color:#7d8183; margin-top:2px; }
#bk .bktk-mine { display:flex; flex-direction:column; gap:8px; margin-top:10px; }
#bk .bktk-mt { background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8;
    border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:10px 14px; }
#bk .bktk-mt-new { border-left-color:#0254a8; }
#bk .bktk-mt-in_progress { border-left-color:#cb8137; }
#bk .bktk-mt-done { border-left-color:#1a8a3f; }
#bk .bktk-mt-rejected { border-left-color:#a80000; }
#bk .bktk-mt-head { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:12px; color:#7d8183; }
#bk .bktk-mt-kind { font-weight:700; color:#01367c; }
#bk .bktk-mt-status { margin-left:auto; font-weight:700; }
#bk .bktk-s-new { color:#0254a8; }
#bk .bktk-s-done { color:#1a8a3f; }
#bk .bktk-s-rejected { color:#a80000; }
#bk .bktk-mt-title { font-size:15px; color:#20263d; margin:4px 0 0; }
#bk .bktk-mt-resp { margin:8px 0 0; padding:8px 10px; font-size:13px; background:#f0f4ff;
    border:1px solid #a7d6ff; border-radius:6px; }
#bk .bktk-s-in_progress { color:#cb8137; }
#bk .bktk-mt-score { color:#e6a700; letter-spacing:1px; font-size:13px; }
#bk .bktk-mt-score i { color:#d9dce3; font-style:normal; }
#bk .bktk-mt-score-n { color:#7d8183; font-size:11px; letter-spacing:0; margin-left:3px; }
#bk .bktk-mt-act { margin-top:10px; }
#bk .bktk-mt-act .bk-btn { margin-top:0; padding:6px 12px; font-size:13px; }
#bk .bktk-mt-locked { font-size:12px; color:#7d8183; font-style:italic; }
#bk .bktk-editing { background:#fff6e6; border:1px solid #e8b96a; color:#8a5a26; border-radius:6px;
    padding:8px 12px; margin:0 0 12px; font-size:13px; }
</style>

<h1>Signaler un bug / proposer une évolution</h1>
<p class="bk-hint" style="margin:0 0 16px">Décrivez le plus précisément possible : plus votre demande
   est claire (ce que vous attendez, le rendu souhaité), plus elle sera traitée rapidement.</p>

<?php if ($ok): ?><?= bk_msg('ok', $ok) ?><?php endif; ?>
<?php if ($err): ?><?= bk_msg('err', $err) ?><?php endif; ?>

<form method="post" class="bk-block" id="bktkform">
  <?= bk_csrf_field() ?>
  <input type="hidden" name="tid" value="<?= intval($editId) ?>">
  <?php if ($editId): ?>
    <div class="bktk-editing">✎ Modification de votre ticket —
      <a href="<?= bk_e(bk_public_url('tickets.php')) ?>">annuler</a></div>
  <?php endif; ?>

  <label>Type de demande</label>
  <div class="bktk-kinds">
    <label class="bktk-kind" data-kind="bug">
      <input type="radio" name="kind" value="bug" <?= $kind !== 'evolution' ? 'checked' : '' ?>>
      <span><b>Bug</b><span>Quelque chose ne fonctionne pas comme prévu.</span></span>
    </label>
    <label class="bktk-kind" data-kind="evolution">
      <input type="radio" name="kind" value="evolution" <?= $kind === 'evolution' ? 'checked' : '' ?>>
      <span><b>Évolution</b><span>Une amélioration ou une nouvelle fonctionnalité.</span></span>
    </label>
  </div>

  <label for="tk-title" id="lab-title">Résumé court</label>
  <input type="text" id="tk-title" name="title" maxlength="160" value="<?= bk_e($title) ?>" placeholder="En une phrase">

  <label for="tk-body" id="lab-body">Description</label>
  <textarea id="tk-body" name="body" rows="4" maxlength="5000"><?= bk_e($body) ?></textarea>
  <p class="bk-hint" id="hint-body"></p>

  <label for="tk-expected" id="lab-expected">Précisions</label>
  <textarea id="tk-expected" name="expected" rows="4" maxlength="5000"><?= bk_e($expected) ?></textarea>
  <p class="bk-hint" id="hint-expected"></p>

  <label for="tk-page">Page ou écran concerné <span class="bk-hint" style="font-weight:400">(facultatif)</span></label>
  <input type="text" id="tk-page" name="page" maxlength="255" value="<?= bk_e($page) ?>" placeholder="ex. calendrier, inscription, boutique…">

  <?php if (!$editId && $tourId > 0): ?><input type="hidden" name="tour_id" value="<?= intval($tourId) ?>"><?php endif; ?>
  <?php if ($tourLabel !== ''): ?>
    <label>Compétition concernée</label>
    <p class="bk-hint" style="margin:0;font-size:14px;color:#20263d">🏆 <?= bk_e($tourLabel) ?></p>
  <?php endif; ?>

  <button type="submit" class="bk-btn bk-btn-primary"><?= $editId ? 'Mettre à jour le ticket' : 'Envoyer le ticket' ?></button>
</form>

<?php if ($mine): ?>
<h2 style="font-size:17px;color:#01367c;margin:24px 0 10px">Mes tickets</h2>
<div class="bktk-mine">
  <?php foreach ($mine as $t):
      $st = $statuses[$t->TkStatus] ?? $t->TkStatus;
      $stars = (int) round(intval($t->TkScore) / 20);
      $edit = aut_ticket_editable($t, $lic, 'archer'); ?>
    <div class="bktk-mt bktk-mt-<?= bk_e($t->TkStatus) ?>">
      <div class="bktk-mt-head">
        <span class="bktk-mt-kind"><?= bk_e($kinds[$t->TkKind] ?? $t->TkKind) ?></span>
        <span><?= bk_e(date('d/m/Y', strtotime($t->TkCreated))) ?></span>
        <span class="bktk-mt-score" title="Indice de précision : plus votre demande est détaillée, plus il monte">
          <?php for ($i = 0; $i < 5; $i++) echo $i < $stars ? '★' : '<i>☆</i>'; ?>
          <span class="bktk-mt-score-n"><?= intval($t->TkScore) ?>/100</span></span>
        <span class="bktk-mt-status bktk-s-<?= bk_e($t->TkStatus) ?>"><?= bk_e($st) ?></span>
      </div>
      <div class="bktk-mt-title"><?= bk_e($t->TkTitle) ?></div>
      <?php if (trim((string) ($t->TkTour ?? '')) !== ''): ?>
        <div class="bk-hint" style="margin-top:2px">🏆 <?= bk_e($t->TkTour) ?></div>
      <?php endif; ?>
      <?php if (trim((string) $t->TkResponse) !== ''): ?>
        <div class="bktk-mt-resp"><b>Réponse :</b> <?= nl2br(bk_e($t->TkResponse)) ?></div>
      <?php endif; ?>
      <div class="bktk-mt-act">
        <?php if ($edit): ?>
          <a class="bk-btn" href="<?= bk_e(bk_public_url('tickets.php?edit=' . intval($t->TkId))) ?>">Modifier / préciser</a>
        <?php else: ?>
          <span class="bktk-mt-locked">Non modifiable (pris en charge ou clôturé)</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
  var L = {
    bug: { title:'Résumé court du problème', body:'Que se passe-t-il ?',
      bodyHint:'Ce que vous faisiez, ce qui s’est affiché, le message d’erreur éventuel.',
      expected:'Étapes pour reproduire / ce que vous attendiez', expectedHint:'Les étapes précises aident à retrouver le problème.' },
    evolution: { title:'Résumé court de votre idée', body:'Qu’aimeriez-vous ?',
      bodyHint:'Le besoin, l’objectif, le contexte d’usage.',
      expected:'Comment cela devrait fonctionner / quel rendu attendu ?', expectedHint:'Le plus important : décrivez le comportement ou l’affichage souhaité.' }
  };
  function apply(k) {
    var d = L[k] || L.bug;
    document.getElementById('lab-title').textContent = d.title;
    document.getElementById('lab-body').textContent = d.body;
    document.getElementById('hint-body').textContent = d.bodyHint;
    document.getElementById('lab-expected').textContent = d.expected;
    document.getElementById('hint-expected').textContent = d.expectedHint;
    Array.prototype.forEach.call(document.querySelectorAll('.bktk-kind'), function (el) {
      el.classList.toggle('on', el.getAttribute('data-kind') === k);
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[name="kind"]'), function (r) {
    r.addEventListener('change', function () { apply(this.value); });
  });
  var c = document.querySelector('input[name="kind"]:checked');
  apply(c ? c.value : 'bug');
})();
</script>
<?php bk_foot(); ?>
