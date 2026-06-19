<?php
define('HTDOCS', dirname(dirname(dirname(dirname(__FILE__)))));
require_once(HTDOCS . '/config.php');

$PAGE_TITLE = 'Guide FFTA';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

/* Charger les formations disponibles */
$formations = [];
$dir = __DIR__ . '/content/';
foreach (glob($dir . '*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['id'])) continue;
    $formations[] = [
        'id'          => $data['id'],
        'title'       => $data['title'] ?? '(sans titre)',
        'description' => $data['description'] ?? '',
        'steps_count' => count($data['steps'] ?? []),
        'version'     => $data['version'] ?? '1.0',
    ];
}
usort($formations, function ($a, $b) { return strcmp($a['title'], $b['title']); });
?>

<style>
.guide-catalogue, .guide-card, .guide-btn-start, .guide-btn-resume {
  font-family: "Poppins", "PoppinsFallback", "Helvetica", sans-serif;
}
.guide-catalogue { display: flex; flex-wrap: wrap; gap: 20px; margin: 20px 0; }

.guide-card {
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 20px;
  width: 320px;
  position: relative;
  background: #fff;
}
.guide-card h2 {
  color: #0254a8;
  margin: 0 0 8px;
  font-size: 16px;
}
.guide-card p {
  color: #555;
  font-size: 13px;
  margin: 0 0 10px;
}
.guide-card-meta {
  color: #999 !important;
  font-size: 12px !important;
}
.guide-card-done {
  border-left: 4px solid #27ae60;
}
.guide-card-active {
  border-left: 4px solid #0254a8;
}
.guide-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: bold;
  margin-bottom: 8px;
}
.guide-badge-done   { background: #d4f0de; color: #1a7a3a; }
.guide-badge-active { background: #e8f0ff; color: #0254a8; }
.guide-badge-old    { background: #f0e6c8; color: #7a5a00; }
.guide-card-old     { border-left: 4px solid #f5a623; }

.guide-btn-start,
.guide-btn-resume {
  margin-top: 10px;
  margin-right: 6px;
  padding: 7px 18px;
  border-radius: 5px;
  border: none;
  cursor: pointer;
  font-size: 13px;
  font-weight: bold;
}
.guide-btn-start  { background: linear-gradient(80deg, #0254a8 10%, #082c7c 100%); color: #fff; }
.guide-btn-start:hover  { opacity: .88; }
.guide-btn-resume { background: #0254a8; color: #fff; }
.guide-btn-resume:hover { background: #082c7c; }

.guide-intro {
  background: #eef4ff;
  border-left: 4px solid #0254a8;
  padding: 12px 16px;
  border-radius: 0 6px 6px 0;
  margin-bottom: 24px;
  color: #333;
  font-size: 14px;
  max-width: 700px;
}
</style>

<h1>Guide FFTA</h1>

<div class="guide-intro">
  Bienvenue dans le Guide interactif FFTA pour ianseo.<br>
  Sélectionnez une formation ci-dessous pour la lancer ou la reprendre.
  Le guide s'affiche dans un panneau latéral et vous accompagne page par page.
</div>

<?php if (empty($formations)): ?>
  <p><i>Aucune formation disponible pour le moment.</i></p>
<?php else: ?>
<div class="guide-catalogue" id="guide-catalogue">
<?php foreach ($formations as $f): ?>
  <div class="guide-card" id="card-<?= htmlspecialchars($f['id']) ?>">
    <h2><?= htmlspecialchars($f['title']) ?></h2>
    <p><?= htmlspecialchars($f['description']) ?></p>
    <p class="guide-card-meta"><?= $f['steps_count'] ?> étapes</p>
    <div id="badge-<?= htmlspecialchars($f['id']) ?>"></div>
    <div>
      <button class="guide-btn-start"
              data-fid="<?= htmlspecialchars($f['id']) ?>"
              onclick="GuideStart(this.dataset.fid)">
        ▶ Commencer
      </button>
      <button class="guide-btn-resume"
              id="resume-<?= htmlspecialchars($f['id']) ?>"
              data-fid="<?= htmlspecialchars($f['id']) ?>"
              style="display:none"
              onclick="GuideResume(this.dataset.fid)">
        ↺ Reprendre
      </button>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
/* Versions des formations courantes (depuis PHP) */
var _guideFormVers = <?= json_encode(array_column($formations, 'version', 'id')) ?>;

document.addEventListener('DOMContentLoaded', function () {
  var lsState = null, lsDone = [];
  try { lsState = JSON.parse(localStorage.getItem('guide_state')); } catch(e) {}
  try { lsDone  = JSON.parse(localStorage.getItem('guide_completed')) || []; } catch(e) {}

  /* Charger la progression depuis le serveur */
  var root = (typeof WebDir !== 'undefined') ? WebDir : '/';
  var xhr  = new XMLHttpRequest();
  xhr.open('GET', root + 'Modules/Custom/GUIDE/guide-api.php?action=progress-all', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    var srv = {};
    try { srv = JSON.parse(xhr.responseText) || {}; } catch(e) {}
    updateBadges(srv);
  };
  xhr.send();

  function updateBadges(srv) {
    document.querySelectorAll('.guide-card').forEach(function (card) {
      var id     = card.id.replace('card-', '');
      var badge  = document.getElementById('badge-'  + id);
      var resume = document.getElementById('resume-' + id);
      var s      = srv[id];
      var currVer = _guideFormVers[id] || '1.0';

      if (s) {
        if (s.status === 'termine' || s.status === 'obsolete') {
          var oldVersion = (s.form_ver && currVer && s.form_ver !== currVer) || s.status === 'obsolete';
          if (oldVersion) {
            card.classList.add('guide-card-old');
            if (badge) badge.innerHTML = '<span class="guide-badge guide-badge-old">✓ Terminée (version précédente)</span>';
          } else {
            card.classList.add('guide-card-done');
            if (badge) badge.innerHTML = '<span class="guide-badge guide-badge-done">✓ Terminée</span>';
          }
        } else if (s.status === 'en_cours' && s.step > 0) {
          card.classList.add('guide-card-active');
          if (badge) badge.innerHTML = '<span class="guide-badge guide-badge-active">En cours — étape ' + (s.step + 1) + '</span>';
          if (resume) resume.style.display = 'inline-block';
        }
      } else {
        /* Fallback localStorage si pas de données serveur */
        if (lsDone.indexOf(id) !== -1) {
          card.classList.add('guide-card-done');
          if (badge) badge.innerHTML = '<span class="guide-badge guide-badge-done">✓ Terminée</span>';
        }
        if (lsState && lsState.active && lsState.formation_id === id && lsState.step_index > 0) {
          card.classList.add('guide-card-active');
          if (badge && lsDone.indexOf(id) === -1) {
            badge.innerHTML = '<span class="guide-badge guide-badge-active">En cours — étape ' + (lsState.step_index + 1) + '</span>';
          }
          if (resume) resume.style.display = 'inline-block';
        }
      }
    });
  }
});
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
