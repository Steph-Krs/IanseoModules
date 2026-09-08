<?php
/**
 * Catalogue of the Interactive Guide module: the landing page users open to
 * pick a course.
 *
 * Content items come from content/*.json and are grouped exactly as those files
 * declare (group, subgroup, order), so adding a course is a matter of dropping
 * a file in — nothing here enumerates them.
 *
 * Progress badges are filled in by the browser rather than rendered server-side.
 * Two reasons: the page is cacheable that way, and a user without an account
 * module has their progress in localStorage, which only the client can read.
 */

// Walk up to the ianseo root instead of counting directory levels, so the module
// keeps working if it is installed somewhere other than Modules/Custom/.
$_guide_root = __DIR__;
while ($_guide_root !== dirname($_guide_root) && !is_file($_guide_root . '/config.php')) {
    $_guide_root = dirname($_guide_root);
}
define('HTDOCS', $_guide_root);
unset($_guide_root);

require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib/guide-lib.inc.php');

// This is a page of the module, so it is one of the places allowed to create
// the schema — menu.php is not. Opening the catalogue is what installs the
// tables on a new installation and applies migrations after an update.
guide_ensure_schema();

$PAGE_TITLE = guide_text('CatalogueTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

$moduleUrl = function_exists('cmod_url') ? cmod_url(__DIR__) : $CFG->ROOT_DIR . 'Modules/Custom/GUIDE/';

/* Content is already sorted by order then title (see guide_content_list). */
$all        = guide_content_list(true);
$formations = [];
$checklists = [];
$faqs       = [];
foreach ($all as $c) {
    if     ($c['type'] === 'checklist') $checklists[] = $c;
    elseif ($c['type'] === 'faq')       $faqs[]       = $c;
    else                                $formations[] = $c;
}

/* Groups and subgroups keep the order in which they first appear, which is the
   order the sorted list gives them — so the learning path reads top to bottom. */
$groups = [];
foreach ($formations as $f) {
    $g  = $f['group'] !== '' ? $f['group'] : guide_text('DefaultGroup');
    $groups[$g][$f['subgroup']][] = $f;
}
?>

<style>
/* Scoped to this page's own class names: the module never touches ianseo's CSS. */
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
/* Fixed 16:9 frame with the image contained inside it, so thumbnails of any
   aspect ratio line up instead of making the cards different heights. */
.guide-card-img {
  position: relative;
  width: 100%;
  padding-top: 56.25%;
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

/* Achievement levels */
.gd-target { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; margin-bottom: 8px; }
.gd-t-bronze { background: #f3e4d7; color: #a05a2c; }
.gd-t-silver { background: #e8eaee; color: #5a616e; }
.gd-t-gold   { background: #faf0cc; color: #a07908; }

/* Activity buttons */
.gd-acts { margin-top: auto; padding-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
.gd-act {
  padding: 7px 13px; border-radius: 5px; border: 1px solid #c8d4ec;
  background: #fff; color: #2a2f5a; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: all .5s ease;
}
.gd-act:hover { border-color: #0254a8; color: #0254a8; background: #eef4ff; transition: all .5s ease; }
.gd-act-main { background: linear-gradient(80deg, #0254a8 10%, #082c7c 100%); color: #fff; border: 1px solid #082c7c; transition: all 1s ease; }
.gd-act-main:hover { border: 1px solid #082c7c; opacity: .88; color: #082c7c; transition: all 1s ease; }
.gd-act .ok { color: #1a8a4a; }

/* Overall progress */
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

<?php
/**
 * THE CATALOGUE IS BUILT IN PHP, NOT WOVEN OUT OF TEMPLATE TAGS.
 *
 * ianseo asks that a file be in one language at a time rather than alternating
 * between markup and the interpreter: with a loop opened in one <?php block and
 * closed three screens further down in another, a reader has to work out line by
 * line which side of the fence they are on, the indentation cannot follow both,
 * and a misplaced end of loop is invisible. So everything below is ordinary PHP
 * producing a string, and the only literal markup left on the page is the two
 * static blocks that contain no logic at all, the stylesheet and the script.
 */

/** Every value that reaches the page goes through this. */
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

/**
 * One catalogue card for a course: thumbnail, title, summary, the slot the
 * browser fills with a progress badge, and one button per activity the course
 * actually carries.
 */
$courseCard = function (array $f) use ($esc) {
    $id   = $esc($f['id']);
    $html = '<div class="guide-card" id="card-' . $id . '"'
          . ' data-quiz="'  . ($f['has_quiz']      ? '1' : '0') . '"'
          . ' data-chall="' . ($f['has_challenge'] ? '1' : '0') . '">';

    if (!empty($f['image'])) {
        $html .= '<div class="guide-card-img"><img src="' . $esc($f['image']) . '" alt=""></div>';
    }

    $meta = $esc(guide_text('StepsCount', $f['steps_count']));
    if ($f['has_quiz'])      $meta .= ' · ' . $esc(guide_text('Quiz'));
    if ($f['has_challenge']) $meta .= ' · ' . $esc(guide_text('Challenge'));

    $html .= '<h2>' . $esc($f['title']) . '</h2>'
           . '<p>' . $esc($f['description']) . '</p>'
           . '<p class="guide-card-meta">' . $meta . '</p>'
           . '<div id="badge-' . $id . '"></div>'
           . '<div class="gd-acts">'
           . '<button class="gd-act gd-act-main" data-fid="' . $id . '"'
           . ' onclick="GuideStart(this.dataset.fid)">▶ ' . $esc(guide_text('CmdGuide')) . '</button>'
           . '<button class="gd-act" id="resume-' . $id . '" data-fid="' . $id . '" style="display:none"'
           . ' onclick="GuideResume(this.dataset.fid)">↺ ' . $esc(guide_text('CmdResume')) . '</button>';

    if ($f['has_quiz']) {
        $html .= '<button class="gd-act" id="quiz-' . $id . '" data-fid="' . $id . '"'
               . ' onclick="GuideStartQuiz(this.dataset.fid)">📝 ' . $esc(guide_text('Quiz')) . '</button>';
    }
    if ($f['has_challenge']) {
        $html .= '<button class="gd-act" id="chall-' . $id . '" data-fid="' . $id . '"'
               . ' onclick="GuideStartChallenge(this.dataset.fid)">🎯 ' . $esc(guide_text('Challenge')) . '</button>';
    }

    return $html . '</div></div>';
};

/**
 * A checklist or a troubleshooter: same shell, one button, no progress badge —
 * these are tools you open, not courses you complete.
 */
$toolCard = function (array $c, $icon, $label) use ($esc) {
    return '<div class="guide-card">'
         . '<h2>' . $esc($c['title']) . '</h2>'
         . '<p>' . $esc($c['description']) . '</p>'
         . '<div class="gd-acts">'
         . '<button class="gd-act gd-act-main" data-fid="' . $esc($c['id']) . '"'
         . ' onclick="GuideStartTool(this.dataset.fid)">' . $icon . ' ' . $esc($label) . '</button>'
         . '</div></div>';
};

/** A titled row of tool cards, or nothing at all when there are none. */
$toolSection = function (array $items, $sectionIcon, $sectionLabel, $icon, $label) use ($esc, $toolCard) {
    if (empty($items)) return '';
    $html = '<h2 class="gd-group">' . $sectionIcon . ' ' . $esc($sectionLabel) . '</h2>'
          . '<div class="guide-catalogue">';
    foreach ($items as $c) $html .= $toolCard($c, $icon, $label);
    return $html . '</div>';
};

$page = '<h1>' . $esc(guide_text('CatalogueTitle')) . '</h1>'
      /* The intro carries deliberate <b> markup from the language file, so it is
         the one string on this page that is not escaped. */
      . '<div class="guide-intro">' . guide_text('CatalogueIntro') . '</div>'
      . '<div class="gd-progress" id="gd-progress" style="display:none">'
      . '<span>📚 ' . $esc(guide_text('StatCompleted')) . ' : '
      . '<b id="gd-p-done">0</b> / <b id="gd-p-total">' . count($formations) . '</b></span>'
      . '<span>🎯 ' . $esc(guide_text('StatGold')) . ' : <b id="gd-p-gold">0</b></span>'
      . '</div>';

if (empty($formations) && empty($checklists) && empty($faqs)) {
    $page .= '<p><i>' . $esc(guide_text('CatalogueEmpty')) . '</i></p>';
}

foreach ($groups as $gname => $subs) {
    $page .= '<h2 class="gd-group">' . $esc($gname) . '</h2>';
    foreach ($subs as $sgname => $cards) {
        if ($sgname !== '') $page .= '<h3 class="gd-subgroup">' . $esc($sgname) . '</h3>';
        $page .= '<div class="guide-catalogue">';
        foreach ($cards as $f) $page .= $courseCard($f);
        $page .= '</div>';
    }
}

$page .= $toolSection($checklists, '🧰', guide_text('Checklists'),
                      '☑', guide_text('OpenChecklist'));
$page .= $toolSection($faqs, '🛟', guide_text('Troubleshooting'),
                      '🛟', guide_text('OpenTroubleshooting'));

$page .= '<div class="gd-settings"><label>'
       . '<input type="checkbox" id="gd-ctx-toggle"> 💡 '
       . $esc(guide_text('ContextHelpToggle'))
       . '</label></div>';

echo $page;
?>

<script>
/* Current version of each course, so the client can tell that progress was made
   against an older version and mark the card accordingly. */
var _guideFormVers = <?= json_encode(array_column($formations, 'version', 'id')) ?>;
var _guideApi      = <?= json_encode($moduleUrl . 'guide-api.php') ?>;

document.addEventListener('DOMContentLoaded', function () {
  var T = window.GUIDE_T || {};
  function t(k) { return T[k] || k; }

  /* localStorage keys carry the account as a suffix, exactly as guide.js builds
     them, so two accounts sharing a browser do not share their progress. */
  var sfx = (typeof window.GUIDE_USER === 'string' && window.GUIDE_USER) ? '::' + window.GUIDE_USER : '';
  var lsState = null, lsDone = [];
  try { lsState = JSON.parse(localStorage.getItem('guide_state' + sfx)); } catch (e) {}
  try { lsDone  = JSON.parse(localStorage.getItem('guide_completed' + sfx)) || []; } catch (e) {}

  /* With an account, the contextual-help preference lives on the server so it
     follows the user from one machine to another; without one, localStorage. */
  var hasUser = (typeof window.GUIDE_USER === 'string' && window.GUIDE_USER !== '');
  var ctx = document.getElementById('gd-ctx-toggle');
  ctx.checked = (hasUser && typeof window.GUIDE_CTX !== 'undefined' && window.GUIDE_CTX !== null)
    ? (window.GUIDE_CTX != 0)
    : (localStorage.getItem('guide_ctx_help' + sfx) !== '0');

  ctx.addEventListener('change', function () {
    if (hasUser) {
      window.GUIDE_CTX = ctx.checked ? 1 : 0;
      var px = new XMLHttpRequest();
      px.open('POST', _guideApi + '?action=pref', true);
      px.setRequestHeader('Content-Type', 'application/json');
      px.send(JSON.stringify({ ctx_help: ctx.checked ? 1 : 0 }));
    } else {
      localStorage.setItem('guide_ctx_help' + sfx, ctx.checked ? '1' : '0');
    }
  });

  var xhr = new XMLHttpRequest();
  xhr.open('GET', _guideApi + '?action=progress-all', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    var srv = {};
    // The API answers in the ianseo envelope: error / msg, then the payload.
    try { srv = (JSON.parse(xhr.responseText) || {}).progress || {}; } catch (e) {}
    updateBadges(srv);
  };
  xhr.send();

  function targetBadge(level) {
    var labels = { bronze: t('TargetBronze'), silver: t('TargetSilver'), gold: t('TargetGold') };
    return '<span class="gd-target gd-t-' + level + '">🎯 ' + labels[level] + '</span>';
  }

  function inProgress(step) {
    /* Step numbers are shown one-based; they are stored zero-based. */
    return '<span class="guide-badge guide-badge-active">'
         + (t('InProgressStep') || '').replace('{$a}', step + 1) + '</span>';
  }

  function updateBadges(srv) {
    var doneCount = 0, goldCount = 0;

    document.querySelectorAll('.guide-card[id^="card-"]').forEach(function (card) {
      var id      = card.id.replace('card-', '');
      var badge   = document.getElementById('badge-'  + id);
      var resume  = document.getElementById('resume-' + id);
      var s       = srv[id];
      var currVer = _guideFormVers[id] || '1.0';
      var hasQuiz  = card.dataset.quiz  === '1';
      var hasChall = card.dataset.chall === '1';

      var guideDone = false, oldVersion = false;

      if (s) {
        if (s.status === 'termine' || s.status === 'obsolete') {
          guideDone  = true;
          oldVersion = (s.form_ver && currVer && s.form_ver !== currVer) || s.status === 'obsolete';
        } else if (s.status === 'en_cours' && s.step > 0) {
          card.classList.add('guide-card-active');
          if (badge)  badge.innerHTML = inProgress(s.step);
          if (resume) resume.style.display = 'inline-block';
        }
      } else if (lsDone.indexOf(id) !== -1) {
        /* No server row: fall back to what this browser remembers. */
        guideDone = true;
      } else if (lsState && lsState.active && lsState.formation_id === id && lsState.step_index > 0) {
        card.classList.add('guide-card-active');
        if (badge)  badge.innerHTML = inProgress(lsState.step_index);
        if (resume) resume.style.display = 'inline-block';
      }

      if (s && s.quiz) {
        var q = document.getElementById('quiz-' + id);
        if (q) q.innerHTML = '📝 ' + t('Quiz') + ' <span class="ok">✓</span>';
      }
      if (s && s.challenge) {
        var c = document.getElementById('chall-' + id);
        if (c) c.innerHTML = '🎯 ' + t('Challenge') + ' <span class="ok">✓</span>';
      }

      if (guideDone) {
        doneCount++;
        card.classList.add(oldVersion ? 'guide-card-old' : 'guide-card-done');

        /* Guide alone earns bronze, one extra activity silver, everything the
           course offers gold — so a course without a quiz can still reach gold. */
        var avail = 1 + (hasQuiz ? 1 : 0) + (hasChall ? 1 : 0);
        var done  = 1 + ((s && s.quiz) ? 1 : 0) + ((s && s.challenge) ? 1 : 0);
        if (done > avail) done = avail;

        var level = (done >= avail) ? 'gold' : (done >= 2 ? 'silver' : 'bronze');
        if (level === 'gold') goldCount++;
        if (badge) {
          badge.innerHTML = targetBadge(level)
            + (oldVersion ? '<span class="guide-badge guide-badge-old">' + t('PreviousVersion') + '</span>' : '');
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
