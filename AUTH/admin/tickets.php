<?php
/**
 * Module AUTH — admin/tickets.php
 * Gestion des tickets (ADMIN uniquement) : tri par date / précision, filtre par
 * statut, changement de statut, suppression. Le dépôt se fait dans ../tickets.php.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
// même verrou que admin/index.php : ADMIN réel quand l'auth est active
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();

$sort   = ($_REQUEST['sort'] ?? 'date') === 'score' ? 'score' : 'date';
$status = $_REQUEST['status'] ?? '';
if (!array_key_exists($status, aut_ticket_statuses())) $status = '';

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $msg = 'Session expirée — action non effectuée.';
    } else {
        $id = intval($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'status') {
            aut_ticket_set_status($id, $_POST['value'] ?? '');
            $msg = 'Statut mis à jour.';
        } elseif ($action === 'respond') {
            aut_ticket_set_response($id, $_POST['response'] ?? '');
            $msg = 'Réponse enregistrée (visible par le déposant).';
        } elseif ($action === 'delete') {
            aut_ticket_delete($id);
            $msg = 'Ticket supprimé.';
        }
    }
}

$counts  = aut_ticket_counts();
$tickets = aut_ticket_list($sort, $status);
$kinds   = aut_ticket_kinds();
$statuses = aut_ticket_statuses();

/** URL de la liste en conservant l'autre paramètre. */
function tk_url($over = array())
{
    global $CFG, $sort, $status;
    $p = array('sort' => $sort, 'status' => $status);
    foreach ($over as $k => $v) $p[$k] = $v;
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/tickets.php' . ($p ? '?' . http_build_query($p) : '');
}

$PAGE_TITLE = 'Multi-comptes — Tickets';
include('Common/Templates/head.php');
?>
<style>
#aut-tk { max-width:920px; }
#aut-tk h1 { font-size:22px; color:#01367c; margin:0 0 12px; }
#aut-tk .aut-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px;
    background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-tk .aut-bar { display:flex; flex-wrap:wrap; gap:16px 24px; align-items:center; margin:0 0 16px;
    padding:10px 14px; background:#fff; border:1px solid #d2d4d6; border-radius:8px; }
#aut-tk .aut-tabs a, #aut-tk .aut-sort a { text-decoration:none; color:#4c4e50; font-size:13px;
    padding:5px 10px; border-radius:14px; }
#aut-tk .aut-tabs a.on, #aut-tk .aut-sort a.on { background:#0254a8; color:#fff; }
#aut-tk .aut-grp { display:flex; gap:6px; align-items:center; }
#aut-tk .aut-grp > b { font-size:12px; color:#7d8183; }
#aut-tk .aut-empty { color:#7d8183; font-style:italic; }
#aut-tk .tk { background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8;
    border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 12px; }
#aut-tk .tk-evolution { border-left-color:#1a8a3f; }
#aut-tk .tk-done { opacity:.7; }
#aut-tk .tk-rejected { opacity:.55; }
#aut-tk .tk-head { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:12px; color:#7d8183; }
#aut-tk .tk-badge { font-weight:700; font-size:11px; padding:2px 9px; border-radius:5px;
    background:#eaf2ff; color:#01367c; border:1px solid #c8ddf7; }
#aut-tk .tk-badge.evo { background:#e5f6ea; color:#0d6b2e; border-color:#a9dcb8; }
#aut-tk .tk-stars { color:#e6a700; letter-spacing:1px; }
#aut-tk .tk-stars i { color:#d9dce3; font-style:normal; }
#aut-tk .tk-status { margin-left:auto; font-weight:700; }
#aut-tk .tk-status.new { color:#0254a8; }
#aut-tk .tk-status.in_progress { color:#cb8137; }
#aut-tk .tk-status.done { color:#1a8a3f; }
#aut-tk .tk-status.rejected { color:#a80000; }
#aut-tk .tk h2 { font-size:16px; color:#20263d; margin:8px 0 6px; }
#aut-tk .tk-field { margin:8px 0 0; }
#aut-tk .tk-field b { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#7d8183; }
#aut-tk .tk-field div { white-space:pre-wrap; font-size:14px; color:#20263d; }
#aut-tk .tk-meta { margin-top:8px; font-size:12px; color:#7d8183; }
#aut-tk .tk-chan { font-size:11px; padding:2px 8px; border-radius:5px; background:#eef0f5; color:#4c4e50; }
#aut-tk .tk-resp { margin-top:10px; }
#aut-tk .tk-resp label { display:block; font-size:12px; color:#01367c; font-weight:600; margin-bottom:4px; }
#aut-tk .tk-resp .tk-mut { font-weight:400; color:#7d8183; }
#aut-tk .tk-resp textarea { width:100%; box-sizing:border-box; padding:7px 9px; font-size:13px;
    font-family:inherit; border:1px solid #d2d4d6; border-radius:6px; }
#aut-tk .tk-resp button { margin-top:6px; font-size:12px; padding:6px 12px; border-radius:6px;
    border:1px solid #0254a8; background:#0254a8; color:#fff; cursor:pointer; }
#aut-tk .tk-resp button:hover { background:#01367c; }
#aut-tk .tk-act { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; padding-top:10px; border-top:1px solid #eef; }
#aut-tk .tk-act form { margin:0; }
#aut-tk .tk-act button { font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #d2d4d6;
    background:#f7f7f7; color:#20263d; cursor:pointer; }
#aut-tk .tk-act button:hover { background:#eef2f8; }
#aut-tk .tk-copy { background:#f0f4ff; border-color:#a7d6ff; color:#0254a8; font-weight:600; }
#aut-tk .tk-copy:hover { background:#dbe7ff; }
#aut-tk .tk-copy.ok { background:#d2f4cd; border-color:#75ae77; color:#04ac0b; }
#aut-tk .tk-act .b-del { color:#c0392b; border-color:#e8b4ae; background:#fff; }
#aut-tk .tk-act .b-del:hover { background:#ffd6db; }
</style>

<div id="aut-tk">
<h1>Tickets</h1>
<?php if ($msg): ?><div class="aut-msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="aut-bar">
  <div class="aut-grp aut-tabs"><b>Statut</b>
    <a class="<?= $status === '' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('status' => ''))) ?>">Tous (<?= $counts['all'] ?>)</a>
    <a class="<?= $status === 'new' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('status' => 'new'))) ?>">Nouveaux (<?= $counts['new'] ?>)</a>
    <a class="<?= $status === 'in_progress' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('status' => 'in_progress'))) ?>">En cours (<?= $counts['in_progress'] ?>)</a>
    <a class="<?= $status === 'done' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('status' => 'done'))) ?>">Traités (<?= $counts['done'] ?>)</a>
    <a class="<?= $status === 'rejected' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('status' => 'rejected'))) ?>">Rejetés (<?= $counts['rejected'] ?>)</a>
  </div>
  <div class="aut-grp aut-sort"><b>Trier</b>
    <a class="<?= $sort === 'date' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('sort' => 'date'))) ?>">Par date</a>
    <a class="<?= $sort === 'score' ? 'on' : '' ?>" href="<?= htmlspecialchars(tk_url(array('sort' => 'score'))) ?>">Par précision</a>
  </div>
</div>

<?php if (!$tickets): ?>
  <p class="aut-empty">Aucun ticket dans cette vue.</p>
<?php else: foreach ($tickets as $t):
    $isEvo = $t->TkKind === 'evolution';
    $stars = (int) round(intval($t->TkScore) / 20);
    $bodyLab = $isEvo ? 'Souhait' : 'Problème';
    $expLab  = $isEvo ? 'Rendu attendu' : 'Reproduction / attendu';
    $stLab   = $statuses[$t->TkStatus] ?? $t->TkStatus;
    // Texte structuré prêt à coller (bouton « Copier »).
    $copy = '[' . ($kinds[$t->TkKind] ?? $t->TkKind) . '] ' . $t->TkTitle . "\n"
          . 'Origine : ' . (($t->TkChannel ?? 'org') === 'archer' ? 'Compétiteur' : 'Organisateur')
          . ' (' . $t->TkUser . ($t->TkRole ? ' — ' . $t->TkRole : '') . ")\n"
          . 'Statut : ' . $stLab . ' · Précision ' . intval($t->TkScore) . '/100 · '
          . date('d/m/Y H:i', strtotime($t->TkCreated)) . "\n"
          . ($t->TkPage ? 'Page : ' . $t->TkPage . "\n" : '')
          . "\n" . $bodyLab . " :\n" . trim((string) $t->TkBody) . "\n"
          . (trim((string) $t->TkExpected) !== ''
              ? "\n" . $expLab . " :\n" . trim((string) $t->TkExpected) . "\n" : ''); ?>
  <article class="tk tk-<?= $isEvo ? 'evolution' : 'bug' ?> tk-<?= htmlspecialchars($t->TkStatus) ?>">
    <div class="tk-head">
      <span class="tk-badge <?= $isEvo ? 'evo' : '' ?>"><?= htmlspecialchars($kinds[$t->TkKind] ?? $t->TkKind) ?></span>
      <span class="tk-chan"><?= ($t->TkChannel ?? 'org') === 'archer' ? 'Compétiteur' : 'Organisateur' ?></span>
      <span><?= htmlspecialchars(date('d/m/Y H:i', strtotime($t->TkCreated))) ?></span>
      <span class="tk-stars" title="Précision <?= intval($t->TkScore) ?>/100"><?php
          for ($i = 0; $i < 5; $i++) echo $i < $stars ? '★' : '<i>★</i>'; ?></span>
      <span class="tk-status <?= htmlspecialchars($t->TkStatus) ?>"><?= htmlspecialchars($stLab) ?></span>
    </div>

    <h2><?= htmlspecialchars($t->TkTitle) ?></h2>

    <?php if (trim((string) $t->TkBody) !== ''): ?>
      <div class="tk-field"><b><?= $bodyLab ?></b><div><?= htmlspecialchars($t->TkBody) ?></div></div>
    <?php endif; ?>
    <?php if (trim((string) $t->TkExpected) !== ''): ?>
      <div class="tk-field"><b><?= $expLab ?></b><div><?= htmlspecialchars($t->TkExpected) ?></div></div>
    <?php endif; ?>

    <p class="tk-meta">
      Déposé par <b><?= htmlspecialchars($t->TkUser) ?></b><?= $t->TkRole ? ' (' . htmlspecialchars($t->TkRole) . ')' : '' ?>
      <?= $t->TkPage ? ' — page : ' . htmlspecialchars($t->TkPage) : '' ?>
    </p>

    <form method="post" class="tk-resp"><?= aut_csrf_field() ?>
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
      <input type="hidden" name="action" value="respond">
      <input type="hidden" name="id" value="<?= intval($t->TkId) ?>">
      <label>Réponse au déposant <span class="tk-mut">(visible par lui)</span></label>
      <textarea name="response" rows="2" placeholder="Optionnel — ex. « Corrigé », « Prévu prochainement », « Besoin de précisions »…"><?= htmlspecialchars((string) $t->TkResponse) ?></textarea>
      <button type="submit">Enregistrer la réponse</button>
    </form>

    <div class="tk-act">
      <button type="button" class="tk-copy" onclick="tkCopy(this)" title="Copier le ticket pour le coller ailleurs">📋 Copier</button>
      <pre class="tk-copy-src" hidden><?= htmlspecialchars($copy) ?></pre>
      <?php if ($t->TkStatus !== 'in_progress'): ?>
        <form method="post"><?= aut_csrf_field() ?>
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
          <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
          <input type="hidden" name="action" value="status"><input type="hidden" name="value" value="in_progress">
          <input type="hidden" name="id" value="<?= intval($t->TkId) ?>">
          <button type="submit" title="Verrouille la modification par le déposant">Prendre en charge</button></form>
      <?php endif; ?>
      <?php if ($t->TkStatus !== 'done'): ?>
        <form method="post"><?= aut_csrf_field() ?>
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
          <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
          <input type="hidden" name="action" value="status"><input type="hidden" name="value" value="done">
          <input type="hidden" name="id" value="<?= intval($t->TkId) ?>">
          <button type="submit">Marquer traité</button></form>
      <?php endif; ?>
      <?php if ($t->TkStatus !== 'rejected'): ?>
        <form method="post"><?= aut_csrf_field() ?>
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
          <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
          <input type="hidden" name="action" value="status"><input type="hidden" name="value" value="rejected">
          <input type="hidden" name="id" value="<?= intval($t->TkId) ?>">
          <button type="submit">Rejeter</button></form>
      <?php endif; ?>
      <?php if ($t->TkStatus !== 'new'): ?>
        <form method="post"><?= aut_csrf_field() ?>
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
          <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
          <input type="hidden" name="action" value="status"><input type="hidden" name="value" value="new">
          <input type="hidden" name="id" value="<?= intval($t->TkId) ?>">
          <button type="submit">Rouvrir</button></form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Supprimer définitivement ce ticket ?')"><?= aut_csrf_field() ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= intval($t->TkId) ?>">
        <button type="submit" class="b-del">Supprimer</button></form>
    </div>
  </article>
<?php endforeach; endif; ?>
</div>
<script>
function tkCopy(btn) {
  var src = btn.closest('.tk').querySelector('.tk-copy-src');
  var txt = src ? src.textContent : '';
  function done() { btn.classList.add('ok'); var o = btn.textContent; btn.textContent = 'Copié ✓';
    setTimeout(function () { btn.textContent = o; btn.classList.remove('ok'); }, 1600); }
  function fallback(t) { var ta = document.createElement('textarea'); ta.value = t;
    ta.style.position = 'fixed'; ta.style.left = '-9999px'; document.body.appendChild(ta);
    ta.focus(); ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(done, function () { fallback(txt); done(); });
  } else { fallback(txt); done(); }
}
</script>
<?php include('Common/Templates/tail.php'); ?>
