<?php
define('HTDOCS', dirname(dirname(dirname(dirname(__FILE__)))));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib/guide-lib.inc.php');

$PAGE_TITLE = 'Guide FFTA';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

/* Contenus triés par order (lib) */
$all        = guide_content_list(true);
$formations = [];
$checklists = [];
$faqs       = [];
foreach ($all as $c) {
    if     ($c['type'] === 'checklist') $checklists[] = $c;
    elseif ($c['type'] === 'faq')       $faqs[]       = $c;
    else                                $formations[] = $c;
}

/* Groupes/sous-groupes dans l'ordre d'apparition (liste déjà triée par order) */
$groups = [];
foreach ($formations as $f) {
    $g  = $f['group'] !== '' ? $f['group'] : 'Formations';
    $sg = $f['subgroup'];
    $groups[$g][$sg][] = $f;
}
?>

<style>
.guide-catalogue, .guide-card, .guide-btn-start, .guide-btn-resume, .gd-act, .gd-group, .gd-subgroup {
  font-family: "Poppins", "PoppinsFallback", "Helvetica", sans-serif;
}
.gd-group {
  color: #082c7c; font-size: 17px; margin: 26px 0 4px;
  padding-bottom: 5px; border-bottom: 2px solid #dde6f5;
}
.gd-subgroup { color: #4a5580; font-size: 13px; font-weight: 600; margin: 12px 0 2px; }
.guide-catalogue { display: flex; flex-wrap: wrap; gap: 20px; margin: 14px 0; }

.guide-card {
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 20px;
  width: 320px;
  position: relative;
  background: #fff;
  display: flex;
  flex-direction: column;
}
.guide-card-img {
  position: relative;
  width: 100%;
  padding-top: 56.25%; /* 16:9 */
  background: #000;
  border-radius: 6px;
  overflow: hidden;
  margin-bottom: 12px;
}
.guide-card-img img {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  object-fit: contain;
  display: block;
}
.guide-card h2 { color: #0254a8; margin: 0 0 8px; font-size: 16px; }
.guide-card p  { color: #555; font-size: 13px; margin: 0 0 10px; }
.guide-card-meta { color: #999 !important; font-size: 12px !important; }
.guide-card-done   { border-left: 4px solid #27ae60; }
.guide-card-active { border-left: 4px solid #0254a8; }
.guide-card-old    { border-left: 4px solid #f5a623; }
.guide-badge {
  display: inline-block; padding: 2px 8px; border-radius: 10px;
  font-size: 11px; font-weight: bold; margin: 0 4px 8px 0;
}
.guide-badge-done   { background: #d4f0de; color: #1a7a3a; }
.guide-badge-active { background: #e8f0ff; color: #0254a8; }
.guide-badge-old    { background: #f0e6c8; color: #7a5a00; }

/* Cibles (gamification) */
.gd-target { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; margin-bottom: 8px; }
.gd-t-bronze { background: #f3e4d7; color: #a05a2c; }
.gd-t-argent { background: #e8eaee; color: #5a616e; }
.gd-t-or     { background: #faf0cc; color: #a07908; }

/* Activités */
.gd-acts { margin-top: auto; padding-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
.gd-act {
  padding: 7px 13px; border-radius: 5px; border: 1px solid #c8d4ec;
  background: #fff; color: #2a2f5a; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: all .5s ease;
}
.gd-act:hover { border-color: #0254a8; color: #0254a8; background: #eef4ff; transition: all .5s ease; }
.gd-act-main { background: linear-gradient(80deg, #0254a8 10%, #082c7c 100%); color: #fff; border: 1px solid linear-gradient(80deg, #0254a8 10%, #082c7c 100%); transition: all 1s ease; }
.gd-act-main:hover { border: 1px solid #082c7c; opacity: .88; color: #082c7c; transition: all 1s ease; }
.gd-act .ok { color: #1a8a4a; }

/* Progression globale */
.gd-progress {
  background: #f7f9ff; border: 1px solid #dde2f5; border-radius: 10px;
  padding: 12px 18px; margin-bottom: 6px; font-size: 13px; color: #333;
  display: flex; gap: 22px; flex-wrap: wrap; align-items: center;
}
.gd-progress b { color: #082c7c; }

.guide-intro {
  background: #eef4ff;
  border-left: 4px solid #0254a8;
  padding: 12px 16px;
  border-radius: 0 6px 6px 0;
  margin-bottom: 18px;
  color: #333;
  font-size: 14px;
  max-width: 700px;
}
.gd-settings { margin: 26px 0 8px; font-size: 12px; color: #666; }
.gd-settings label { cursor: pointer; display: inline-flex; align-items: center; gap: 7px; }
</style>

<h1>Guide FFTA</h1>

<div class="guide-intro">
  Bienvenue dans le Guide interactif FFTA pour ianseo.<br>
  Chaque formation propose jusqu'à trois activités : le <b>guide pas-à-pas</b>, un <b>QCM</b> et un
  <b>défi</b> à réaliser sans aide. Réussissez-les toutes pour décrocher la <b>cible d'or</b> 🎯.
</div>

<div class="gd-progress" id="gd-progress" style="display:none">
  <span>📚 Formations terminées : <b id="gd-p-done">0</b> / <b id="gd-p-total"><?= count($formations) ?></b></span>
  <span>🎯 Cibles d'or : <b id="gd-p-gold">0</b></span>
</div>

<?php if (empty($formations) && empty($checklists) && empty($faqs)): ?>
  <p><i>Aucune formation disponible pour le moment.</i></p>
<?php endif; ?>

<?php foreach ($groups as $gname => $subs): ?>
  <h2 class="gd-group"><?= htmlspecialchars($gname) ?></h2>
  <?php foreach ($subs as $sgname => $cards): ?>
    <?php if ($sgname !== ''): ?><h3 class="gd-subgroup"><?= htmlspecialchars($sgname) ?></h3><?php endif; ?>
    <div class="guide-catalogue">
    <?php foreach ($cards as $f): ?>
      <div class="guide-card" id="card-<?= htmlspecialchars($f['id']) ?>"
           data-quiz="<?= $f['has_quiz'] ? 1 : 0 ?>" data-chall="<?= $f['has_challenge'] ? 1 : 0 ?>">
        <?php if (!empty($f['image'])): ?>
          <div class="guide-card-img"><img src="<?= htmlspecialchars($f['image']) ?>" alt=""></div>
        <?php endif; ?>
        <h2><?= htmlspecialchars($f['title']) ?></h2>
        <p><?= htmlspecialchars($f['description']) ?></p>
        <p class="guide-card-meta"><?= $f['steps_count'] ?> étapes<?=
          $f['has_quiz'] ? ' · QCM' : '' ?><?= $f['has_challenge'] ? ' · Défi' : '' ?></p>
        <div id="badge-<?= htmlspecialchars($f['id']) ?>"></div>
        <div class="gd-acts">
          <button class="gd-act gd-act-main"
                  data-fid="<?= htmlspecialchars($f['id']) ?>"
                  onclick="GuideStart(this.dataset.fid)">▶ Guide</button>
          <button class="gd-act"
                  id="resume-<?= htmlspecialchars($f['id']) ?>"
                  data-fid="<?= htmlspecialchars($f['id']) ?>"
                  style="display:none"
                  onclick="GuideResume(this.dataset.fid)">↺ Reprendre</button>
          <?php if ($f['has_quiz']): ?>
            <button class="gd-act" id="quiz-<?= htmlspecialchars($f['id']) ?>"
                    data-fid="<?= htmlspecialchars($f['id']) ?>"
                    onclick="GuideStartQuiz(this.dataset.fid)">📝 QCM</button>
          <?php endif; ?>
          <?php if ($f['has_challenge']): ?>
            <button class="gd-act" id="chall-<?= htmlspecialchars($f['id']) ?>"
                    data-fid="<?= htmlspecialchars($f['id']) ?>"
                    onclick="GuideStartChallenge(this.dataset.fid)">🎯 Défi</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php if (!empty($checklists)): ?>
  <h2 class="gd-group">🧰 Checklists</h2>
  <div class="guide-catalogue">
  <?php foreach ($checklists as $c): ?>
    <div class="guide-card">
      <h2><?= htmlspecialchars($c['title']) ?></h2>
      <p><?= htmlspecialchars($c['description']) ?></p>
      <div class="gd-acts">
        <button class="gd-act gd-act-main" data-fid="<?= htmlspecialchars($c['id']) ?>"
                onclick="GuideStartTool(this.dataset.fid)">☑ Ouvrir la checklist</button>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($faqs)): ?>
  <h2 class="gd-group">🛟 Dépannage</h2>
  <div class="guide-catalogue">
  <?php foreach ($faqs as $c): ?>
    <div class="guide-card">
      <h2><?= htmlspecialchars($c['title']) ?></h2>
      <p><?= htmlspecialchars($c['description']) ?></p>
      <div class="gd-acts">
        <button class="gd-act gd-act-main" data-fid="<?= htmlspecialchars($c['id']) ?>"
                onclick="GuideStartTool(this.dataset.fid)">🛟 Ouvrir le dépannage</button>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="gd-settings">
  <label>
    <input type="checkbox" id="gd-ctx-toggle">
    💡 Aide contextuelle — propose les formations liées à la page ianseo affichée
  </label>
</div>

<script>
/* Versions des formations courantes (depuis PHP) */
var _guideFormVers = <?= json_encode(array_column($formations, 'version', 'id')) ?>;

document.addEventListener('DOMContentLoaded', function () {
  /* Clés localStorage suffixées par compte — mêmes clés que guide.js (GUIDE_USER via menu.php) */
  var sfx = (typeof window.GUIDE_USER === 'string' && window.GUIDE_USER) ? '::' + window.GUIDE_USER : '';
  var lsState = null, lsDone = [];
  try { lsState = JSON.parse(localStorage.getItem('guide_state' + sfx)); } catch(e) {}
  try { lsDone  = JSON.parse(localStorage.getItem('guide_completed' + sfx)) || []; } catch(e) {}

  /* Toggle aide contextuelle — avec un compte : préférence serveur (suit l'utilisateur
     d'un poste à l'autre) ; sans compte : localStorage (comportement historique) */
  var root = (typeof WebDir !== 'undefined') ? WebDir : '/';
  var hasUser = (typeof window.GUIDE_USER === 'string' && window.GUIDE_USER !== '');
  var ctx = document.getElementById('gd-ctx-toggle');
  ctx.checked = (hasUser && typeof window.GUIDE_CTX !== 'undefined' && window.GUIDE_CTX !== null)
    ? (window.GUIDE_CTX != 0)
    : (localStorage.getItem('guide_ctx_help' + sfx) !== '0');
  ctx.addEventListener('change', function () {
    if (hasUser) {
      window.GUIDE_CTX = ctx.checked ? 1 : 0;
      var px = new XMLHttpRequest();
      px.open('POST', root + 'Modules/Custom/GUIDE/guide-api.php?action=pref', true);
      px.setRequestHeader('Content-Type', 'application/json');
      px.send(JSON.stringify({ ctx_help: ctx.checked ? 1 : 0 }));
    } else {
      localStorage.setItem('guide_ctx_help' + sfx, ctx.checked ? '1' : '0');
    }
  });

  /* Charger la progression depuis le serveur */
  var xhr  = new XMLHttpRequest();
  xhr.open('GET', root + 'Modules/Custom/GUIDE/guide-api.php?action=progress-all', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    var srv = {};
    try { srv = JSON.parse(xhr.responseText) || {}; } catch(e) {}
    updateBadges(srv);
  };
  xhr.send();

  function targetBadge(lvl) {
    var labels = { bronze: 'Cible de bronze', argent: "Cible d'argent", or: "Cible d'or" };
    return '<span class="gd-target gd-t-' + lvl + '">🎯 ' + labels[lvl] + '</span>';
  }

  function updateBadges(srv) {
    var doneCount = 0, goldCount = 0;
    document.querySelectorAll('.guide-card[id^="card-"]').forEach(function (card) {
      var id     = card.id.replace('card-', '');
      var badge  = document.getElementById('badge-'  + id);
      var resume = document.getElementById('resume-' + id);
      var s      = srv[id];
      var currVer  = _guideFormVers[id] || '1.0';
      var hasQuiz  = card.dataset.quiz  === '1';
      var hasChall = card.dataset.chall === '1';

      var guideDone = false, oldVersion = false;
      if (s) {
        if (s.status === 'termine' || s.status === 'obsolete') {
          guideDone  = true;
          oldVersion = (s.form_ver && currVer && s.form_ver !== currVer) || s.status === 'obsolete';
        } else if (s.status === 'en_cours' && s.step > 0) {
          card.classList.add('guide-card-active');
          if (badge) badge.innerHTML = '<span class="guide-badge guide-badge-active">En cours — étape ' + (s.step + 1) + '</span>';
          if (resume) resume.style.display = 'inline-block';
        }
      } else if (lsDone.indexOf(id) !== -1) {
        guideDone = true; /* fallback localStorage */
      } else if (lsState && lsState.active && lsState.formation_id === id && lsState.step_index > 0) {
        card.classList.add('guide-card-active');
        if (badge) badge.innerHTML = '<span class="guide-badge guide-badge-active">En cours — étape ' + (lsState.step_index + 1) + '</span>';
        if (resume) resume.style.display = 'inline-block';
      }

      /* Coches sur les activités réussies */
      if (s && s.quiz)      { var q = document.getElementById('quiz-'  + id); if (q) q.innerHTML = '📝 QCM <span class="ok">✓</span>'; }
      if (s && s.challenge) { var c = document.getElementById('chall-' + id); if (c) c.innerHTML = '🎯 Défi <span class="ok">✓</span>'; }

      if (guideDone) {
        doneCount++;
        card.classList.add(oldVersion ? 'guide-card-old' : 'guide-card-done');
        /* Niveau de cible : guide=bronze, +1 activité=argent, tout=or */
        var avail = 1 + (hasQuiz ? 1 : 0) + (hasChall ? 1 : 0);
        var done  = 1 + ((s && s.quiz) ? 1 : 0) + ((s && s.challenge) ? 1 : 0);
        if (done > avail) done = avail;
        var lvl = (done >= avail) ? 'or' : (done >= 2 ? 'argent' : 'bronze');
        if (lvl === 'or') goldCount++;
        if (badge) {
          badge.innerHTML = targetBadge(lvl) +
            (oldVersion ? '<span class="guide-badge guide-badge-old">version précédente</span>' : '');
        }
      }
    });

    document.getElementById('gd-p-done').textContent = doneCount;
    document.getElementById('gd-p-gold').textContent = goldCount;
    document.getElementById('gd-progress').style.display = '';
  }
});
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
