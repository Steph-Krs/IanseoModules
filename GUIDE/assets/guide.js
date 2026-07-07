/* Guide FFTA — moteur de tutoriel v3 */
(function () {
  'use strict';

  var LS_STATE = 'guide_state';
  var LS_DONE  = 'guide_completed';
  var LS_SIDE  = 'guide_panel_side';
  var LS_WIDE  = 'guide_panel_wide';

  var state       = null;
  var formation   = null;
  var panel, fab;
  var _triggerOff      = null; // cleanup du listener actif
  var _syncTimer       = null; // debounce sync serveur
  var _triggerIdx      = 0;   // index du trigger courant dans l'étape
  var _doneTriggerMask = {};  // { index: true } des triggers déjà déclenchés
  var _navigating      = false; // page en cours de déchargement (submit/navigation ianseo)

  /* ===== Traceur de diagnostic (survit aux rechargements) =====
     Console : GuideDebug(true) pour activer/réinitialiser, reproduire, puis GuideDebug() pour afficher. */
  var LS_DBG = 'guide_debug';
  function _dbgOn() { try { return localStorage.getItem('guide_debug_on') === '1'; } catch (e) { return false; } }
  function _dbg(msg) {
    if (!_dbgOn()) return;
    try {
      var arr = JSON.parse(localStorage.getItem(LS_DBG)) || [];
      arr.push({ t: new Date().toISOString().slice(11, 23), p: location.pathname, m: msg });
      if (arr.length > 300) arr = arr.slice(-300);
      localStorage.setItem(LS_DBG, JSON.stringify(arr));
    } catch (e) {}
  }
  window.GuideDebug = function (on) {
    if (on === true)  { localStorage.setItem('guide_debug_on', '1'); localStorage.removeItem(LS_DBG); console.log('[Guide] debug ON — reproduisez le bug, puis tapez GuideDebug()'); return; }
    if (on === false) { localStorage.setItem('guide_debug_on', '0'); console.log('[Guide] debug OFF'); return; }
    var arr = [];
    try { arr = JSON.parse(localStorage.getItem(LS_DBG)) || []; } catch (e) {}
    console.log('%c[Guide] trace (' + arr.length + ' évènements) :', 'font-weight:bold');
    arr.forEach(function (e) { console.log(e.t + '  ' + e.p + '  ' + e.m); });
    return arr;
  };

  /* ===== Init ===== */

  function guideInit() {
    panel = document.getElementById('guide-panel');
    fab   = document.getElementById('guide-fab');
    if (!panel) return;

    // Permettre au guide de s'afficher dans les popups ianseo (PopEdit.php…), qui n'incluent pas
    // get_which_menu() — donc pas notre injection serveur. On intercepte window.open côté parent.
    setupPopupInjection();

    document.getElementById('guide-panel-min').addEventListener('click', hidePanel);
    document.getElementById('guide-panel-max').addEventListener('click', togglePanelWide);
    document.getElementById('guide-panel-close').addEventListener('click', stopFormation);
    document.getElementById('guide-panel-toggle-side').addEventListener('click', togglePanelSide);
    document.getElementById('guide-btn-prev').addEventListener('click', prevStep);
    document.getElementById('guide-btn-next').addEventListener('click', nextStep);
    document.getElementById('guide-btn-restart').addEventListener('click', restartTriggers);
    document.getElementById('guide-btn-back').addEventListener('click', backOneTrigger);
    document.getElementById('guide-btn-validate').addEventListener('click', toggleValidate);
    fab.addEventListener('click', onFabClick);

    // Page en cours de déchargement → ne plus reculer (éviter de régresser la progression
    // sauvegardée pendant une soumission de formulaire / navigation ianseo).
    window.addEventListener('beforeunload', function () { _navigating = true; });
    window.addEventListener('pagehide',     function () { _navigating = true; });

    // Mode enregistrement de triggers (prioritaire sur le mode formation)
    if (recActive()) { recInit(); return; }

    applyPanelSide(loadSide());
    applyWide(loadWide());

    state = loadState();
    _dbg('INIT trigger_index=' + (state && state.trigger_index) + ' step=' + (state && state.step_index) +
         ' active=' + (state && state.active) + ' validated=' + (state && state.validated ? Object.keys(state.validated).join(',') : '-'));

    // Sync état local → serveur au chargement (rattrape les navigations interrompues)
    if (state && state.active && state.gp_id && (!state.mode || state.mode === 'guide')) {
      serverPost('update', {
        gp_id: state.gp_id, step: state.step_index || 0,
        status: 'en_cours', validated: state.validated || {}
      }, null);
    }

    if (state && state.active && state.mode === 'quiz' && state.formation_id) {
      fetchFormation(state.formation_id, function (f) {
        formation = f;
        if (!formation || !formation.quiz) { resetState(); showFabIfNeeded(); return; }
        renderQuiz(); showPanel();
      });
    } else if (state && state.active && state.mode === 'defi' && state.formation_id) {
      fetchFormation(state.formation_id, function (f) {
        formation = f;
        if (!formation || !formation.challenge) { resetState(); showFabIfNeeded(); return; }
        renderChallenge(); showPanel();
      });
    } else if (state && state.active && (state.mode === 'checklist' || state.mode === 'faq') && state.tool_id) {
      fetchFormation(state.tool_id, function (f) {
        formation = f;
        if (!formation) { resetState(); showFabIfNeeded(); return; }
        if (state.mode === 'checklist') renderChecklist(); else renderFaq();
        showPanel();
      });
    } else if (state && state.active && state.formation_id) {
      fetchFormation(state.formation_id, function (f) {
        formation = f;
        if (!formation) { resetState(); showFabIfNeeded(); return; }
        if (state.step_index >= formation.steps.length) state.step_index = 0;
        renderStep();
        showPanel();
      });
    } else {
      maybeContextHelp();
    }
  }

  // Exécute l'init même si le DOM est déjà prêt (cas des popups où guide.js est injecté après chargement).
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', guideInit);
  else guideInit();

  /* ===== API publique ===== */

  window.GuideStart = function (formationId) {
    clearHighlight(); clearTrigger(); clearTourWarning();
    fetchFormation(formationId, function (f) {
      formation = f;
      if (!formation) { alert('Formation introuvable.'); return; }
      state = { active: true, formation_id: formationId, step_index: 0, validated: {}, gp_id: null };
      saveState();
      serverPost('start', {
        formation_id:      formationId,
        formation_version: formation.version || '1.0'
      }, function (data) {
        if (data && data.gp_id) {
          state.gp_id = data.gp_id;
          saveState();
          // Synchroniser si l'utilisateur a déjà avancé avant la réponse serveur
          serverPost('update', {
            gp_id: state.gp_id, step: state.step_index || 0,
            status: 'en_cours', validated: state.validated || {}
          }, null);
        }
      });
      renderStep();
      showPanel();
    });
  };

  window.GuideResume = function (formationId) {
    var saved = loadState();
    state = (saved && saved.active && saved.formation_id === formationId)
      ? saved
      : { active: true, formation_id: formationId, step_index: 0, validated: {}, gp_id: null };
    saveState();

    fetchFormation(formationId, function (f) {
      formation = f;
      if (!formation) { alert('Formation introuvable.'); return; }
      if (state.step_index >= formation.steps.length) state.step_index = 0;

      // Toujours vérifier le serveur — le gp_id peut être absent si localStorage a été effacé
      fetchServerProgress(formationId, function (srv) {
        clearTourWarning();
        if (srv) {
          if (!state.gp_id) state.gp_id = srv.gp_id;
          if (srv.step >= state.step_index) {
            state.step_index = srv.step;
            state.validated  = srv.validated || {};
          }
          saveState();
          if (srv.tour_id && srv.current_tour_id && srv.tour_id !== srv.current_tour_id) {
            showTourWarning();
          }
        }
        renderStep(); showPanel();
      });
    });
  };

  window.GuideIsActive    = function () { return !!(state && state.active); };
  window.GuideIsCompleted = function (id) { return loadCompleted().indexOf(id) !== -1; };

  /* ===== Position gauche / droite ===== */

  function loadSide() { return localStorage.getItem(LS_SIDE) || 'right'; }
  function saveSide(s) { localStorage.setItem(LS_SIDE, s); }

  function applyPanelSide(side) {
    var toggle = document.getElementById('guide-panel-toggle-side');
    if (side === 'left') {
      panel.classList.add('guide-panel-left');
      if (fab) fab.classList.add('guide-fab-left');
      if (toggle) { toggle.textContent = '→'; toggle.title = 'Déplacer vers la droite'; }
    } else {
      panel.classList.remove('guide-panel-left');
      if (fab) fab.classList.remove('guide-fab-left');
      if (toggle) { toggle.textContent = '←'; toggle.title = 'Déplacer vers la gauche'; }
    }
  }

  function togglePanelSide() {
    var next = loadSide() === 'right' ? 'left' : 'right';
    saveSide(next); applyPanelSide(next);
  }

  /* ===== Largeur normale / agrandie ===== */

  function loadWide() { return localStorage.getItem(LS_WIDE) === '1'; }

  function applyWide(wide) {
    if (!panel) return;
    panel.classList.toggle('guide-panel-wide', wide);
    var btn = document.getElementById('guide-panel-max');
    if (btn) {
      btn.textContent = wide ? '❐' : '▢';
      btn.title       = wide ? 'Taille normale' : 'Agrandir';
    }
    if (_highlighted) placeArrow(_highlighted);
  }

  function togglePanelWide() {
    var next = !loadWide();
    localStorage.setItem(LS_WIDE, next ? '1' : '0');
    applyWide(next);
  }

  /* ===== Navigation ===== */

  function prevStep() {
    if (!state || state.step_index <= 0) return;
    _dbg('prevStep → step ' + (state.step_index - 1) + ', reset trigger_index=0');
    clearHighlight(); clearTrigger();
    state.step_index--;
    state.trigger_index = 0;
    saveState(); scheduleSync(); renderStep();
  }

  function nextStep() {
    if (!formation || !state) return;
    if (!isStepDone(formation.steps[state.step_index])) return;
    if (state.step_index >= formation.steps.length - 1) { completeFormation(); return; }
    _dbg('nextStep → step ' + (state.step_index + 1) + ', reset trigger_index=0');
    clearHighlight(); clearTrigger();
    state.step_index++;
    state.trigger_index = 0;
    saveState(); scheduleSync(); renderStep();
  }

  // Index du trigger actionnable précédent (saute les sous-étapes non actionnables).
  function prevActionableIdx(step, fromIdx) {
    var triggers = step.triggers || [];
    var i = fromIdx - 1;
    while (i >= 0) {
      var t = triggers[i];
      var isAction = !t.kind || t.kind === 'action';
      if (isAction ? (t.trigger || t.required) : t.required) return i;
      i--;
    }
    return -1;
  }

  // 🔄 Recommencer : remet les indications de l'étape à zéro (réinitialisation volontaire).
  function restartTriggers() {
    if (!formation || !state) return;
    var step = formation.steps[state.step_index];
    if (!(step.triggers || []).length) return;
    _dbg('restartTriggers (manuel) → trigger_index=0');
    clearHighlight(); clearTrigger();
    _triggerIdx = 0;
    _doneTriggerMask = {};
    if (state.validated) delete state.validated[step.id];
    state.trigger_index = 0;
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
    startTriggerSequence(step);
  }

  // ↶ Revenir d'une indication : recule au trigger actionnable précédent (manuel, pas de cascade).
  function backOneTrigger() {
    if (!formation || !state) return;
    var step = formation.steps[state.step_index];
    var i = prevActionableIdx(step, _triggerIdx);
    if (i < 0) return;
    _dbg('backOneTrigger (manuel) ' + _triggerIdx + ' → ' + i);
    clearHighlight(); clearTrigger();
    for (var k = i; k < (step.triggers || []).length; k++) delete _doneTriggerMask[k];
    _triggerIdx = i;
    if (state.validated) delete state.validated[step.id];
    state.trigger_index = i;
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
    startTriggerSequence(step);
  }

  // Active/désactive (et masque sans trigger) les boutons Recommencer / Revenir.
  function updateTriggerNavBtns() {
    var back    = document.getElementById('guide-btn-back');
    var restart = document.getElementById('guide-btn-restart');
    if (!back || !restart || !formation || !state) return;
    var step      = formation.steps[state.step_index];
    var nTrig     = (step.triggers || []).length;
    var validated = !!(state.validated && state.validated[step.id]);
    var show      = nTrig > 0;
    back.style.display    = show ? '' : 'none';
    restart.style.display = show ? '' : 'none';
    back.disabled    = prevActionableIdx(step, _triggerIdx) < 0;
    restart.disabled = (_triggerIdx === 0 && !validated);
  }

  function completeFormation() {
    if (!state) return;
    var done = loadCompleted();
    if (done.indexOf(state.formation_id) === -1) {
      done.push(state.formation_id);
      localStorage.setItem(LS_DONE, JSON.stringify(done));
    }
    clearHighlight(); clearTrigger();
    serverPost('update', {
      gp_id: state.gp_id, step: state.step_index || 0,
      status: 'termine', validated: state.validated || {}
    }, null);
    state.active = false;
    state.mode = null;
    saveState();
    renderCompletionView(); // le panneau reste ouvert : QCM / défi / formation suivante
  }

  function stopFormation() {
    if (!state || !state.active) { formation = null; hidePanel(); return; }
    var isTool = (state.mode === 'checklist' || state.mode === 'faq' || state.mode === 'quiz' || state.mode === 'defi');
    var msg = isTool
      ? 'Fermer ?\nVotre progression locale est conservée.'
      : 'Quitter la formation ?\nVotre progression est sauvegardée — vous pourrez reprendre ici.';
    if (!confirm(msg)) return;
    clearHighlight(); clearTrigger(); clearTourWarning();
    if (!isTool && state.gp_id) {
      serverPost('update', {
        gp_id: state.gp_id, step: state.step_index || 0,
        status: 'en_cours', validated: state.validated || {}
      }, null);
    }
    resetState(); formation = null;
    hidePanel();
  }

  /* ===== Validation d'une étape ===== */

  function isStepDone(step) {
    if (!step) return true;
    var triggers = step.triggers || [];
    var hasRequired = triggers.some(function(t) { return t.required; });
    if (!hasRequired) return true;
    return !!(state && state.validated && state.validated[step.id]);
  }

  function updateNextBtn() {
    var btn = document.getElementById('guide-btn-next');
    if (!btn || !formation || !state) return;
    var step   = formation.steps[state.step_index];
    var isLast = state.step_index === formation.steps.length - 1;
    var done   = isStepDone(step);
    btn.disabled    = !done;
    btn.textContent = isLast ? 'Terminer ✓' : 'Suivant ▶';
    btn.classList.toggle('guide-btn-locked', !done);
  }

  function toggleValidate() {
    if (!state || !formation) return;
    var step     = formation.steps[state.step_index];
    var triggers = step.triggers || [];
    if (!state.validated) state.validated = {};
    if (state.validated[step.id]) {
      delete state.validated[step.id];
      _doneTriggerMask = {}; _triggerIdx = 0;
      state.trigger_index = 0;
      if (triggers.length) startTriggerSequence(step);
    } else {
      state.validated[step.id] = true;
      clearHighlight(); clearTrigger();
    }
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
  }

  function renderValidateBtn() {
    var wrap = document.getElementById('guide-panel-validate');
    var btn  = document.getElementById('guide-btn-validate');
    if (!btn || !formation || !state) return;
    var step            = formation.steps[state.step_index];
    var needsValidation = (step.triggers || []).some(function(t) { return t.required; });
    var isStrict        = step.optional === false;
    var done            = !!(state.validated && state.validated[step.id]);

    if (wrap) wrap.style.display = (needsValidation && !isStrict) ? '' : 'none';
    btn.textContent = done ? '✓ Fait' : '☐ Marquer comme fait';
    if (done) btn.classList.add('guide-validated');
    else      btn.classList.remove('guide-validated');
  }

  /* ===== Système de triggers séquentiels ===== */

  function startTriggerSequence(step) {
    clearHighlight(); clearTrigger();
    if (!(step.triggers || []).length) return;
    attachCurrentTrigger(step);
  }

  function attachCurrentTrigger(step) {
    clearTrigger(); clearConditionWait();
    updateTriggerNavBtns();
    var triggers = step.triggers || [];

    if (_triggerIdx >= triggers.length) {
      _dbg('attach END idx=' + _triggerIdx + ' allRequiredDone=' + allRequiredDone(step));
      clearHighlight();
      if (allRequiredDone(step)) autoValidateStep(step);
      return;
    }

    var t = triggers[_triggerIdx];
    _dbg('attach idx=' + _triggerIdx + ' ' + (t.kind || 'action') + ' sel=' + (t.selector || t.condition || '-') +
         ' req=' + !!t.required + (t.when ? ' when=' + t.when : '') + (t.when_not ? ' whenNot=' + t.when_not : ''));

    // Sous-étape non actionnable (sans action ni obligation) → passer
    var isAction  = !t.kind || t.kind === 'action';
    var actionable = isAction ? (t.trigger || t.required) : t.required;
    if (!actionable) { skipCurrentTrigger(step); return; }

    // Condition d'activation (branche conditionnelle) — évaluation possiblement asynchrone
    if (t.when || t.when_not) {
      var idxAtEval = _triggerIdx;
      clearHighlight();
      evaluateGate(t, function (active) {
        // Garde anti-course : la séquence a-t-elle changé pendant l'évaluation serveur ?
        if (!state || !formation || formation.steps[state.step_index] !== step || _triggerIdx !== idxAtEval) return;
        if (active) proceedWithTrigger(step, t);
        else        skipCurrentTrigger(step);
      });
      return;
    }

    proceedWithTrigger(step, t);
  }

  // Marque le trigger courant comme "fait" et passe au suivant (sous-étape passée ou branche non prise).
  function skipCurrentTrigger(step) {
    _dbg('SKIP idx=' + _triggerIdx);
    _doneTriggerMask[_triggerIdx] = true;
    _triggerIdx++;
    persistTriggerIdx();
    attachCurrentTrigger(step);
  }

  // Évalue la condition d'activation d'un trigger. 'when' = actif si remplie ; 'when_not' = actif si NON remplie.
  function evaluateGate(t, cb) {
    var checks = [];
    if (t.when)     checks.push({ cond: t.when,     want: true  });
    if (t.when_not) checks.push({ cond: t.when_not, want: false });
    if (!checks.length) { cb(true); return; }
    var remaining = checks.length, active = true;
    checks.forEach(function (c) {
      checkCondition(c.cond, function (met) {
        if ((!!met) !== c.want) active = false;
        if (--remaining === 0) cb(active);
      });
    });
  }

  function proceedWithTrigger(step, t) {
    // ---- Trigger état (condition serveur ou page active) ----
    if (t.kind === 'etat') {
      clearHighlight();
      evaluateEtat(step, t, function (met, label) {
        _dbg('etat idx=' + _triggerIdx + ' cond=' + (t.condition || '') + ' page=' + (t.page || '') + ' met=' + met);
        if (met) {
          onTriggerFired(step);
        } else if (t.condition === '__page') {
          // Le lien "aller sur la page" est géré par updatePageInfo() — pas de message redondant
          clearConditionWait();
        } else {
          showConditionWait(label);
          if (t.condition === '__css') startEtatPoll(step, t); // élément dynamique → re-vérifie
        }
      });
      return;
    }

    // ---- Trigger action (événement DOM) ----
    var tPage = triggerPage(step, t);
    clearHighlight();

    if (!isOnRightPage(tPage)) return; // mauvaise page : updatePageInfo affiche le lien

    if (t.selector) {
      // Aucun recul automatique : on cale la surbrillance sur la cible si présente (ou son parent
      // visible si masquée/hors-écran), sinon on attend qu'elle apparaisse (applyHighlight via la
      // surveillance). L'écouteur d'événement est délégué sur document → il reste actif même si la
      // cible n'est pas encore là.
      _curSelector = t.selector;
      _hint = t.hint || '';
      applyHighlight();         // surligne l'élément (ou son ancêtre visible le plus proche)
      startHighlightTracking(); // listeners scroll/resize + surveillance par re-requête
      if (step.strict_click) enableStrictClick(t.selector);
    }

    // Trigger manuel (trigger: null) ou sans cible → l'utilisateur clique "Marquer comme fait"
    if (!t.trigger || !t.selector) return;

    bindTriggerEvent(step, t);
  }

  // Écouteur délégué sur document : résiste aux re-render partiels de ianseo
  // (le nœud peut être remplacé, on ne garde aucune référence directe).
  function bindTriggerEvent(step, t) {
    // Pré-validation 'change' : champ déjà rempli / coché
    if (t.trigger === 'change') {
      var pre = document.querySelector(t.selector);
      if (pre) {
        var done = false;
        if (pre.type === 'checkbox') done = pre.checked;
        else if (pre.type !== 'file') done = !!(pre.value && pre.value !== '' && pre.value !== '0' && pre.value !== '-1');
        if (done) { onTriggerFired(step); return; }
      }
    }
    var handler = function (e) {
      var hit = (e.target && e.target.closest) ? e.target.closest(t.selector) : null;
      if (!hit) return;
      if (t.trigger === 'change' && hit.type === 'checkbox' && !hit.checked) return;
      document.removeEventListener(t.trigger, handler, true);
      _triggerOff = null;
      onTriggerFired(step);
    };
    document.addEventListener(t.trigger, handler, true);
    _triggerOff = function () { document.removeEventListener(t.trigger, handler, true); };
  }

  function onTriggerFired(step) {
    var triggers = step.triggers || [];
    _doneTriggerMask[_triggerIdx] = true;
    _triggerIdx++;
    while (_triggerIdx < triggers.length && _doneTriggerMask[_triggerIdx]) _triggerIdx++;
    _dbg('FIRED → idx=' + _triggerIdx);
    persistTriggerIdx();
    attachCurrentTrigger(step);
    updatePageInfo(step);
    if (allRequiredDone(step)) autoValidateStep(step);
  }

  // Persistance MONOTONE de la progression dans l'étape : ne descend jamais (anti-régression).
  // Seules les avancées réelles (onTriggerFired, skipCurrentTrigger) sont sauvegardées.
  // (Les changements d'étape réinitialisent explicitement state.trigger_index à 0 dans prev/nextStep.)
  function persistTriggerIdx() {
    if (!state) return;
    if (typeof state.trigger_index !== 'number' || _triggerIdx > state.trigger_index) {
      _dbg('PERSIST trigger_index ' + state.trigger_index + ' → ' + _triggerIdx);
      state.trigger_index = _triggerIdx;
      saveState();
    } else {
      _dbg('persist skipped (monotone) _triggerIdx=' + _triggerIdx + ' saved=' + state.trigger_index);
    }
  }

  function allRequiredDone(step) {
    return (step.triggers || []).every(function (t, i) {
      return !t.required || !!_doneTriggerMask[i];
    });
  }

  function isElementVisible(el) {
    if (!el || !el.getClientRects || !el.getClientRects().length) return false;
    var style = window.getComputedStyle(el);
    if (style.visibility === 'hidden' || style.display === 'none' || parseFloat(style.opacity) === 0) return false;
    var rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return false;
    // Masquage par positionnement hors-écran (menus Suckerfish ianseo : left/top: -9999px).
    // Un élément simplement défilé hors viewport garde une coordonnée document >= 0 ; un menu caché
    // est à une coordonnée absurde (ex : top -99736px) → on le considère non visible.
    var absTop  = rect.top  + (window.scrollY || window.pageYOffset || 0);
    var absLeft = rect.left + (window.scrollX || window.pageXOffset || 0);
    if (absTop < -1000 || absLeft < -1000) return false;
    return true;
  }

  function clearTrigger() {
    if (_triggerOff) { _triggerOff(); _triggerOff = null; }
    stopEtatPoll();
    disableStrictClick();
  }

  function autoValidateStep(step) {
    if (!state) return;
    _dbg('AUTOVALIDATE step=' + step.id);
    if (!state.validated) state.validated = {};
    state.validated[step.id] = true;
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
  }

  /* ===== Rendu d'une étape ===== */

  function renderStep() {
    if (!formation || !state) return;
    showNav(true);
    var step  = formation.steps[state.step_index];
    var total = formation.steps.length;
    var idx   = state.step_index;
    var pct   = total > 1 ? Math.round(idx / (total - 1) * 100) : 100;

    document.getElementById('guide-panel-formation-name').textContent = formation.title;
    document.getElementById('guide-panel-progress-fill').style.width  = pct + '%';
    document.getElementById('guide-panel-progress-text').textContent  = 'Étape ' + (idx + 1) + ' / ' + total;
    renderStepImage(step);
    document.getElementById('guide-panel-step-title').textContent     = step.title;
    document.getElementById('guide-panel-step-content').innerHTML     = sanitizeContent(step.content);

    document.getElementById('guide-btn-prev').disabled = (idx === 0);
    _doneTriggerMask = {};
    _triggerIdx = state.trigger_index || 0;
    for (var i = 0; i < _triggerIdx; i++) _doneTriggerMask[i] = true;
    _dbg('renderStep step=' + step.id + ' stepIdx=' + idx + ' trigger_index=' + state.trigger_index +
         ' validated=' + !!(state.validated && state.validated[step.id]) + ' nTriggers=' + (step.triggers || []).length);
    updatePageInfo(step);
    renderValidateBtn();
    updateNextBtn();
    updateTriggerNavBtns();
    if (state && state.validated && state.validated[step.id]) {
      var requiredEtat = (step.triggers || []).filter(function (t) { return t.kind === 'etat' && t.required; });
      if (requiredEtat.length > 0) {
        revalidateEtat(step, requiredEtat);
      } else {
        clearHighlight(); clearTrigger();
      }
    } else {
      startTriggerSequence(step);
    }
  }

  function renderStepImage(step) {
    var wrap = document.getElementById('guide-panel-step-image');
    if (!wrap) return;
    if (step.image && /^data:image\//.test(step.image)) {
      wrap.innerHTML = '';
      var box = document.createElement('div');
      box.className = 'guide-img-16x9';
      var img = document.createElement('img');
      img.src = step.image;
      img.alt = '';
      box.appendChild(img);
      wrap.appendChild(box);
      wrap.style.display = '';
    } else {
      wrap.style.display = 'none';
      wrap.innerHTML = '';
    }
  }

  function evaluateEtat(step, t, cb) {
    if (t.condition === '__page') {
      var page = t.page || step.page || null;
      cb(isOnRightPage(page), 'Vous devez être sur la page : ' + (page || '(non définie)'));
    } else if (t.condition === '__css') {
      var present = cssElementVisible(t.selector);
      var met = t.absent ? !present : present;
      var label = t.hint || (t.absent
        ? 'En attente : l\'élément indiqué doit disparaître.'
        : 'En attente : l\'élément indiqué doit apparaître à l\'écran.');
      cb(met, label);
    } else {
      checkCondition(t.condition || '', cb);
    }
  }

  // Présence "visible à l'écran" d'un sélecteur (gère les sélecteurs dynamiques [id^="…"]).
  function cssElementVisible(selector) {
    if (!selector) return false;
    try {
      var el = document.querySelector(selector);
      return !!(el && isElementVisible(el));
    } catch (e) { return false; }
  }

  // Polling d'un état client (__css) : l'élément peut apparaître/disparaître sans rechargement.
  var _etatPoll = null;
  function startEtatPoll(step, t) {
    stopEtatPoll();
    _etatPoll = setInterval(function () {
      if (!state || !formation || formation.steps[state.step_index] !== step) { stopEtatPoll(); return; }
      evaluateEtat(step, t, function (met) {
        if (met) { stopEtatPoll(); clearConditionWait(); onTriggerFired(step); }
      });
    }, 700);
  }
  function stopEtatPoll() { if (_etatPoll) { clearInterval(_etatPoll); _etatPoll = null; } }

  // Re-validation d'un état en tenant compte de sa condition d'activation :
  // une branche non prise (gate faux) ne doit pas pouvoir dé-valider l'étape.
  function gateThenEtat(step, t, cb) {
    evaluateGate(t, function (active) {
      if (!active) { cb(true); return; }
      evaluateEtat(step, t, function (met) { cb(met); });
    });
  }

  function revalidateEtat(step, etatTriggers) {
    _dbg('revalidateEtat: ' + etatTriggers.length + ' état(s) requis sur étape validée');
    var remaining = etatTriggers.length;
    var allMet    = true;
    etatTriggers.forEach(function (t) {
      gateThenEtat(step, t, function (met) {
        _dbg('  revalidate cond=' + (t.condition || '') + ' page=' + (t.page || '') + ' met=' + met);
        if (!met) allMet = false;
        if (--remaining === 0) {
          if (allMet) {
            _dbg('revalidateEtat → tout OK, étape reste validée');
            clearHighlight(); clearTrigger();
          } else {
            _dbg('revalidateEtat → ÉCHEC : dé-validation + RESET trigger_index=0, relance séquence');
            delete state.validated[step.id];
            state.trigger_index = 0;
            _triggerIdx      = 0;
            _doneTriggerMask = {};
            saveState();
            renderValidateBtn();
            updateNextBtn();
            startTriggerSequence(step);
          }
        }
      });
    });
  }

  /* ===== Surbrillance ===== */

  var _highlighted     = null;
  var _arrow           = null;
  var _hint            = '';
  var _strictClickOff  = null;
  var _visWatch        = null;
  var _curSelector     = null;  // sélecteur du trigger courant (re-requêté, jamais de noeud périmé)

  function nearestVisibleAncestor(el) {
    var node = el;
    while (node && node.nodeType === 1 && node !== document.body) {
      if (isElementVisible(node)) return node;
      node = node.parentElement;
    }
    return null;
  }

  function isInViewport(el) {
    var r = el.getBoundingClientRect();
    var vh = window.innerHeight || document.documentElement.clientHeight;
    var vw = window.innerWidth  || document.documentElement.clientWidth;
    return r.top >= 0 && r.left >= 0 && r.bottom <= vh && r.right <= vw;
  }

  // Place/replace la surbrillance en re-requêtant le sélecteur courant.
  // Cible visible → on la surligne ; cible masquée (sous-menu fermé) → ancêtre visible le plus proche ;
  // rien de visible → on retire la flèche (en attente, sans fantôme).
  function applyHighlight() {
    if (!_curSelector) return;
    var target = document.querySelector(_curSelector);
    var anchor = target ? (isElementVisible(target) ? target : nearestVisibleAncestor(target)) : null;
    if (anchor === _highlighted) return; // déjà calé sur le bon élément
    if (_highlighted) { _highlighted.classList.remove('guide-highlight'); _highlighted = null; }
    removeArrow();
    if (!anchor) return;
    _highlighted = anchor;
    anchor.classList.add('guide-highlight');
    if (!isInViewport(anchor)) anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
    placeArrow(anchor);
  }

  function startHighlightTracking() {
    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onResize);
    document.addEventListener('scroll', onResize, true);
    startVisibilityWatch();
  }

  // Surveillance par re-requête (résiste aux re-render ianseo) : on ne se fie jamais à un noeud mémorisé.
  // applyHighlight() ré-ancre sur la cible (ou son parent visible si masquée/hors-écran) et retire la
  // flèche si la cible est absente. JAMAIS de retour en arrière : après un rechargement où la page a
  // changé (post-import LookupTableLoad), les éléments des triggers précédents sont absents — un recul
  // remonterait jusqu'au menu (toujours présent dans la nav) → fausse impression de redémarrage.
  // On reste sur le trigger courant et la surbrillance réapparaît dès que la cible revient.
  function startVisibilityWatch() {
    stopVisibilityWatch();
    _visWatch = setInterval(visualWatchTick, 500);
  }
  function stopVisibilityWatch() {
    if (_visWatch) { clearInterval(_visWatch); _visWatch = null; }
  }
  function visualWatchTick() {
    if (_navigating) { stopVisibilityWatch(); return; }
    if (!_curSelector) { stopVisibilityWatch(); return; }
    applyHighlight();
  }

  function enableStrictClick(selector) {
    disableStrictClick();
    var handler = function (e) {
      var panel = document.getElementById('guide-panel');
      if (panel && panel.contains(e.target)) return;
      // Ne jamais bloquer les contrôles du guide lui-même (FAB, recorder)
      if (e.target.closest && e.target.closest('#guide-fab, #guide-rec')) return;
      try { if (e.target.closest && e.target.closest(selector)) return; } catch (ex) {}
      e.preventDefault();
      e.stopImmediatePropagation();
      flashPanel();
    };
    document.addEventListener('click',     handler, true);
    document.addEventListener('mousedown', handler, true);
    _strictClickOff = function () {
      document.removeEventListener('click',     handler, true);
      document.removeEventListener('mousedown', handler, true);
    };
  }

  function disableStrictClick() {
    if (_strictClickOff) { _strictClickOff(); _strictClickOff = null; }
  }

  function flashPanel() {
    var panel = document.getElementById('guide-panel');
    if (!panel) return;
    panel.classList.remove('guide-panel-flash');
    void panel.offsetWidth;
    panel.classList.add('guide-panel-flash');
  }

  function placeArrow(el) {
    removeArrow();
    var rect      = el.getBoundingClientRect();
    var container = document.createElement('div');
    container.id  = 'guide-highlight-arrow';

    var emoji = document.createElement('div');
    emoji.textContent = '👆';
    container.appendChild(emoji);

    if (_hint) {
      var bubble = document.createElement('div');
      bubble.id  = 'guide-highlight-hint';
      bubble.textContent = _hint;
      container.appendChild(bubble);
    }

    container.style.top  = (rect.bottom + 6) + 'px';
    container.style.left = (rect.left + rect.width / 2 - 11) + 'px';
    document.body.appendChild(container);
    _arrow = container;
    avoidPanelOverlap(el);
  }

  // Empêche le panneau de recouvrir l'ÉLÉMENT indiqué (on ignore la flèche).
  // Règle : élément à l'intérieur du panneau → bascule de côté ; si toujours à l'intérieur
  // après bascule → réduit exceptionnellement le panneau de moitié, le temps du trigger.
  // On calcule la position FINALE du panneau (indépendante de l'animation de bascule).
  var _panelDodged = false;
  var _panelShrunk = false;

  function rectsOverlap(a, b) {
    return !(a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom);
  }

  // Rectangle qu'occuperait le panneau pour un côté donné (ancré en bas, marge 20px).
  function panelSideRect(side, w, h) {
    var vw = window.innerWidth  || document.documentElement.clientWidth;
    var vh = window.innerHeight || document.documentElement.clientHeight;
    var bottom = vh - 20;
    var top    = Math.max(20, bottom - h);
    return (side === 'left')
      ? { left: 20,          right: 20 + w,     top: top, bottom: bottom }
      : { left: vw - 20 - w, right: vw - 20,    top: top, bottom: bottom };
  }

  function avoidPanelOverlap(el) {
    if (!panel || panel.style.display === 'none') return;
    var elRect = el.getBoundingClientRect();
    var pr = panel.getBoundingClientRect();
    var w = pr.width || 360, h = pr.height || 300;
    var side = panel.classList.contains('guide-panel-left') ? 'left' : 'right';

    // 1) L'élément est-il à l'intérieur du panneau (à sa position finale) ?
    if (!rectsOverlap(panelSideRect(side, w, h), elRect)) return;

    // 2) Déplacer de l'autre côté (une seule fois)
    if (!_panelDodged) {
      _panelDodged = true;
      side = (side === 'right') ? 'left' : 'right';
      applyPanelSide(side); // visuel seulement, non sauvegardé
    }

    // 3) Toujours à l'intérieur après déplacement → réduire de moitié (une seule fois)
    if (!_panelShrunk && rectsOverlap(panelSideRect(side, w, h), elRect)) {
      _panelShrunk = true;
      panel.classList.add('guide-panel-half');
    }
  }

  function removeArrow() {
    if (_arrow) { _arrow.parentNode && _arrow.parentNode.removeChild(_arrow); _arrow = null; }
  }

  function onResize() { if (_highlighted) placeArrow(_highlighted); }

  function clearHighlight() {
    stopVisibilityWatch();
    _curSelector = null;
    if (_panelShrunk) { _panelShrunk = false; if (panel) panel.classList.remove('guide-panel-half'); }
    if (_panelDodged) { _panelDodged = false; applyPanelSide(loadSide()); }
    if (_highlighted) { _highlighted.classList.remove('guide-highlight'); _highlighted = null; }
    _hint = '';
    removeArrow();
    clearConditionWait();
    window.removeEventListener('resize', onResize);
    window.removeEventListener('scroll', onResize);
    document.removeEventListener('scroll', onResize, true);
  }

  /* ===== Visibilité panneau ===== */

  /* ===== Avertissement compétition différente ===== */

  var WARN_ID = 'guide-tour-warn';

  function showTourWarning() {
    if (document.getElementById(WARN_ID)) return;
    var warn = document.createElement('div');
    warn.id = WARN_ID;
    warn.style.cssText = [
      'background:#fff8e6', 'border-left:3px solid #f5a623',
      'padding:7px 12px', 'font-size:11px', 'color:#664d00',
      'line-height:1.5', 'cursor:pointer'
    ].join(';');
    warn.title = 'Cliquez pour masquer';
    warn.innerHTML = '⚠️ <b>Formation commencée dans une autre compétition.</b><br>' +
      'Certains menus ou sélecteurs peuvent être absents de cette compétition.';
    warn.addEventListener('click', clearTourWarning);
    var prog = document.getElementById('guide-panel-progress');
    if (prog) prog.insertAdjacentElement('afterend', warn);
  }

  function clearTourWarning() {
    var w = document.getElementById(WARN_ID);
    if (w) w.parentNode.removeChild(w);
  }

  function showPanel() { panel.style.display = 'flex'; if (fab) fab.style.display = 'none'; }

  // Réduire = PAUSE : on détache le trigger actif ET le mode non-permissif (sinon le blocage
  // des clics empêcherait même de rouvrir via le FAB). Rouvrir (FAB) ré-attache tout (play).
  function hidePanel() {
    panel.style.display = 'none';
    clearHighlight();
    clearTrigger();
    showFabIfNeeded();
    if (!state || !state.active) maybeContextHelp();
  }

  function showFabIfNeeded() {
    if (!fab) return;
    if (state && state.active) {
      fab.style.display = 'flex';
      fab.classList.remove('guide-fab-ctx');
      return;
    }
    if (_ctxItems && _ctxItems.length && ctxEnabled()) {
      fab.style.display = 'flex';
      fab.classList.add('guide-fab-ctx');
      return;
    }
    fab.style.display = 'none';
  }

  function renderCurrentMode() {
    if (state.mode === 'quiz')           renderQuiz();
    else if (state.mode === 'defi')      renderChallenge();
    else if (state.mode === 'checklist') renderChecklist();
    else if (state.mode === 'faq')       renderFaq();
    else                                 renderStep();
  }

  function onFabClick() {
    if (state && state.active) {
      if (formation) { renderCurrentMode(); showPanel(); return; }
      fetchFormation(state.tool_id || state.formation_id, function (f) {
        formation = f;
        if (formation) { renderCurrentMode(); showPanel(); }
      });
      return;
    }
    if (_ctxItems && _ctxItems.length) { renderContextPanel(); showPanel(); return; }
    window.location.href = buildUrl('/Modules/Custom/GUIDE/');
  }

  /* ===== Sync serveur ===== */

  function scheduleSync() {
    if (_syncTimer) clearTimeout(_syncTimer);
    _syncTimer = setTimeout(function () {
      if (!state || !state.gp_id) return;
      serverPost('update', {
        gp_id: state.gp_id, step: state.step_index || 0,
        status: 'en_cours', validated: state.validated || {}
      }, null);
    }, 800);
  }

  function fetchServerProgress(formId, cb) {
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=progress&f=' + encodeURIComponent(formId);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try { cb(xhr.status === 200 ? JSON.parse(xhr.responseText) : null); }
      catch (e) { cb(null); }
    };
    xhr.send();
  }

  function serverPost(action, body, cb) {
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=' + action;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    if (cb) {
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        try { cb(xhr.status === 200 ? JSON.parse(xhr.responseText) : null); }
        catch (e) { cb(null); }
      };
    }
    xhr.send(JSON.stringify(body));
  }

  function fetchFormation(id, cb) {
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?f=' + encodeURIComponent(id);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try { cb(xhr.status === 200 ? JSON.parse(xhr.responseText) : null); }
      catch (e) { cb(null); }
    };
    xhr.send();
  }

  /* ===== Utilitaires ===== */

  function apiRoot()        { return (typeof WebDir !== 'undefined') ? WebDir : '/'; }
  function buildUrl(path)   { return apiRoot().replace(/\/$/, '') + path; }

  function sanitizeContent(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    tmp.querySelectorAll('ul, ol, li').forEach(function(el) {
      el.removeAttribute('style');
    });
    return tmp.innerHTML;
  }

  function normPath(p) {
    // /foo/index.php → /foo/   (même page côté serveur web)
    return p.replace(/\/index\.php$/, '/');
  }

  function isOnRightPage(page) {
    if (!page || page === '*') return true;
    var base  = apiRoot().replace(/\/$/, '');
    var qIdx  = page.indexOf('?');
    var pagePath   = qIdx === -1 ? page : page.slice(0, qIdx);
    var pageSearch = qIdx === -1 ? ''   : page.slice(qIdx);

    var normPage    = normPath(base + pagePath);
    var normCurrent = normPath(window.location.pathname);

    if (pageSearch) {
      return normCurrent === normPage && window.location.search === pageSearch;
    }
    return normCurrent === normPage;
  }

  function checkCondition(cid, cb) {
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=check-condition&cid=' + encodeURIComponent(cid);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try {
        var data = JSON.parse(xhr.responseText);
        cb(data.met === true, data.label || cid);
      } catch (e) { cb(false, cid); }
    };
    xhr.send();
  }

  function showConditionWait(label) {
    var el = document.getElementById('guide-panel-condition-wait');
    if (!el) return;
    el.innerHTML = '<p class="guide-condition-wait">🔍 Condition requise non satisfaite :<br><b>' + esc(label) + '</b></p>';
    el.style.display = '';
  }

  function clearConditionWait() {
    var el = document.getElementById('guide-panel-condition-wait');
    if (el) { el.style.display = 'none'; el.innerHTML = ''; }
  }

  function triggerPage(step, t) {
    return (t && t.page) || step.page || null;
  }

  function updatePageInfo(step) {
    var triggers = step.triggers || [];
    var t        = triggers[_triggerIdx] || null;
    var page     = triggerPage(step, t);
    var pageInfo = document.getElementById('guide-panel-page-info');
    if (!pageInfo) return;
    if (page && !isOnRightPage(page)) {
      pageInfo.style.display = '';
      pageInfo.innerHTML =
        '<p class="guide-page-warning">📍 Cette étape s\'effectue sur une autre page :<br>' +
        '<a href="' + esc(buildUrl(page)) + '" class="guide-page-link">Aller sur la page →</a></p>';
    } else {
      pageInfo.style.display = 'none';
      pageInfo.innerHTML = '';
    }
  }

  function loadState() {
    try { return JSON.parse(localStorage.getItem(LS_STATE)); } catch (e) { return null; }
  }
  function saveState()  { localStorage.setItem(LS_STATE, JSON.stringify(state)); }
  function resetState() { state = { active: false }; saveState(); }

  function loadCompleted() {
    try { return JSON.parse(localStorage.getItem(LS_DONE)) || []; } catch (e) { return []; }
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /* ===== Vues du panneau (QCM, défi, checklist, FAQ, fin de formation) ===== */

  function showNav(show) {
    var n = document.getElementById('guide-panel-nav');
    if (n) n.style.display = show ? 'flex' : 'none';
  }

  // Remplit le panneau avec une vue non-guide : masque les zones spécifiques au guide.
  function setPanelView(titleText, contentNode, progressText) {
    clearHighlight();
    var img = document.getElementById('guide-panel-step-image');
    if (img) { img.style.display = 'none'; img.innerHTML = ''; }
    var pageInfo = document.getElementById('guide-panel-page-info');
    if (pageInfo) { pageInfo.style.display = 'none'; pageInfo.innerHTML = ''; }
    var vw = document.getElementById('guide-panel-validate');
    if (vw) vw.style.display = 'none';
    showNav(false);
    clearTourWarning();
    document.getElementById('guide-panel-formation-name').textContent = formation ? (formation.title || '') : '';
    document.getElementById('guide-panel-progress-text').textContent  = progressText || '';
    document.getElementById('guide-panel-progress-fill').style.width  = '0%';
    document.getElementById('guide-panel-step-title').textContent     = titleText || '';
    var c = document.getElementById('guide-panel-step-content');
    c.innerHTML = '';
    if (contentNode) c.appendChild(contentNode);
  }

  function ctaBtn(html, cls, onClick) {
    var b = document.createElement('button');
    b.className = 'guide-cta' + (cls ? ' ' + cls : '');
    b.innerHTML = html;
    b.addEventListener('click', onClick);
    return b;
  }

  function fetchNextFormation(fid, cb) {
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=next&f=' + encodeURIComponent(fid);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try { var d = JSON.parse(xhr.responseText); cb(d && d.next ? d.next : null); }
      catch (e) { cb(null); }
    };
    xhr.send();
  }

  function hasQuiz(f)      { return !!(f && f.quiz && f.quiz.questions && f.quiz.questions.length); }
  function hasChallenge(f) { return !!(f && f.challenge && f.challenge.conditions && f.challenge.conditions.length); }

  // Badge cible : niveau selon les activités réussies parmi celles disponibles
  function badgeLevel(f, srv) {
    var avail = 1 + (hasQuiz(f) ? 1 : 0) + (hasChallenge(f) ? 1 : 0);
    var done  = 1 + ((srv && srv.quiz) ? 1 : 0) + ((srv && srv.challenge) ? 1 : 0);
    if (done > avail) done = avail;
    if (done >= avail) return 'or';
    return done >= 2 ? 'argent' : 'bronze';
  }

  function badgeHtml(lvl) {
    var label = lvl === 'or' ? "Cible d'or" : (lvl === 'argent' ? "Cible d'argent" : 'Cible de bronze');
    return '<b class="guide-badge-' + lvl + '">🎯 ' + label + '</b>';
  }

  function updateDoneBadge(fid) {
    fetchServerProgress(fid, function (s) {
      var el = document.getElementById('guide-done-badge');
      if (!el || !formation) return;
      var lvl = badgeLevel(formation, s);
      el.innerHTML = 'Distinction : ' + badgeHtml(lvl) +
        (lvl !== 'or' ? '<br><span style="font-size:11px;color:#888">Réussissez toutes les activités pour la cible d\'or.</span>' : '');
    });
  }

  /* ---- Écran de fin de guide ---- */

  function renderCompletionView() {
    var f = formation;
    if (!f) return;
    var wrap = document.createElement('div');
    var p = document.createElement('p');
    p.innerHTML = '<b>Félicitations !</b> Vous avez terminé le guide de cette formation.';
    wrap.appendChild(p);
    var badge = document.createElement('p');
    badge.id = 'guide-done-badge';
    wrap.appendChild(badge);
    if (hasQuiz(f))      wrap.appendChild(ctaBtn('📝 Passer au QCM', 'guide-cta-main', function () { GuideStartQuiz(f.id); }));
    if (hasChallenge(f)) wrap.appendChild(ctaBtn('🎯 Relever le défi', hasQuiz(f) ? '' : 'guide-cta-main', function () { GuideStartChallenge(f.id); }));
    var nextHolder = document.createElement('div');
    wrap.appendChild(nextHolder);
    wrap.appendChild(ctaBtn('🏠 Retour au catalogue', 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    setPanelView('🎉 Formation terminée !', wrap, f.title);
    document.getElementById('guide-panel-progress-fill').style.width = '100%';
    updateDoneBadge(f.id);
    fetchNextFormation(f.id, function (n) {
      if (n) nextHolder.appendChild(ctaBtn('▶ Formation suivante : ' + esc(n.title), (hasQuiz(f) || hasChallenge(f)) ? '' : 'guide-cta-main', function () { GuideStart(n.id); }));
    });
  }

  /* ---- QCM ---- */

  window.GuideStartQuiz = function (fid) {
    clearHighlight(); clearTrigger();
    fetchFormation(fid, function (f) {
      if (!f || !hasQuiz(f)) { alert('Pas de QCM pour cette formation.'); return; }
      formation = f;
      state = { active: true, formation_id: fid, mode: 'quiz', qi: 0, qok: 0 };
      saveState();
      renderQuiz(); showPanel();
    });
  };

  // 'correct' : index (héritage) ou tableau d'index (réponses multiples)
  function quizCorrectSet(q) {
    return Array.isArray(q.correct) ? q.correct.slice() : [q.correct || 0];
  }

  function shuffleArr(a) {
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function renderQuiz() {
    var qs = formation.quiz.questions;
    var i  = state.qi || 0;
    if (i >= qs.length) { renderQuizResult(); return; }
    var q       = qs[i];
    var correct = quizCorrectSet(q);
    var multi   = correct.length > 1;
    var wrap    = document.createElement('div');

    if (multi) {
      var note = document.createElement('p');
      note.style.cssText = 'font-size:11px;color:#7c5cbf;margin:0 0 6px';
      note.textContent = '☑ Plusieurs réponses possibles — sélectionnez puis validez.';
      wrap.appendChild(note);
    }

    // Ordre d'affichage (aléatoire si demandé), chaque bouton garde son index d'origine
    var order = (q.choices || []).map(function (_, k) { return k; });
    if (formation.quiz.shuffle) shuffleArr(order);

    order.forEach(function (orig) {
      var b = ctaBtn(esc(q.choices[orig]), 'guide-quiz-choice', function () {
        if (wrap._answered) return;
        if (multi) { b._sel = !b._sel; b.classList.toggle('sel', b._sel); }
        else answerQuiz([orig], wrap, q, correct);
      });
      b._orig = orig;
      wrap.appendChild(b);
    });

    if (multi) {
      wrap.appendChild(ctaBtn('Valider la réponse ✓', 'guide-cta-main', function () {
        if (wrap._answered) return;
        var sel = [];
        var btns = wrap.querySelectorAll('.guide-quiz-choice');
        for (var k = 0; k < btns.length; k++) if (btns[k]._sel) sel.push(btns[k]._orig);
        if (!sel.length) return; // toujours au moins une réponse
        answerQuiz(sel, wrap, q, correct);
      }));
    }

    setPanelView(q.q || '', wrap, 'QCM — Question ' + (i + 1) + ' / ' + qs.length);
    document.getElementById('guide-panel-progress-fill').style.width = Math.round(i / qs.length * 100) + '%';
  }

  function answerQuiz(selected, wrap, q, correct) {
    if (wrap._answered) return;
    wrap._answered = true;
    // Bonne réponse = exactement l'ensemble des réponses correctes
    var ok = selected.length === correct.length && selected.every(function (s) { return correct.indexOf(s) !== -1; });
    if (ok) state.qok = (state.qok || 0) + 1;
    var btns = wrap.querySelectorAll('.guide-quiz-choice');
    for (var k = 0; k < btns.length; k++) {
      var b = btns[k];
      b.disabled = true;
      if (correct.indexOf(b._orig) !== -1) b.classList.add('guide-quiz-good');
      else if (selected.indexOf(b._orig) !== -1) b.classList.add('guide-quiz-bad');
      b.classList.remove('sel');
    }
    var fb = document.createElement('div');
    fb.className = 'guide-quiz-fb ' + (ok ? 'ok' : 'ko');
    fb.innerHTML = (ok ? '✅ Bonne réponse !' : '❌ Mauvaise réponse.') + (q.explain ? '<br>' + esc(q.explain) : '');
    wrap.appendChild(fb);
    var isLast = (state.qi || 0) >= formation.quiz.questions.length - 1;
    wrap.appendChild(ctaBtn(isLast ? 'Voir le résultat ▶' : 'Question suivante ▶', 'guide-cta-main', function () {
      state.qi = (state.qi || 0) + 1;
      saveState();
      renderQuiz();
    }));
    saveState();
  }

  function renderQuizResult() {
    var f     = formation;
    var total = f.quiz.questions.length;
    var ok    = state.qok || 0;
    var score = Math.round(ok / total * 100);
    var pass  = f.quiz.pass_score || 70;
    var passed = score >= pass;
    var wrap = document.createElement('div');
    var p = document.createElement('p');
    if (passed) {
      p.innerHTML = '✅ <b>QCM réussi !</b> Score : ' + score + '% (' + ok + '/' + total + ').';
      wrap.appendChild(p);
      var badge = document.createElement('p');
      badge.id = 'guide-done-badge';
      wrap.appendChild(badge);
      serverPost('activity', { formation_id: f.id, activity: 'quiz' }, function () { updateDoneBadge(f.id); });
      if (hasChallenge(f)) wrap.appendChild(ctaBtn('🎯 Relever le défi', 'guide-cta-main', function () { GuideStartChallenge(f.id); }));
      var nh = document.createElement('div');
      wrap.appendChild(nh);
      fetchNextFormation(f.id, function (n) {
        if (n) nh.appendChild(ctaBtn('▶ Formation suivante : ' + esc(n.title), hasChallenge(f) ? '' : 'guide-cta-main', function () { GuideStart(n.id); }));
      });
      state.active = false; state.mode = null; saveState();
    } else {
      p.innerHTML = '❌ Score : ' + score + '% (' + ok + '/' + total + ') — il faut au moins ' + pass + '%.';
      wrap.appendChild(p);
      wrap.appendChild(ctaBtn('↺ Réessayer', 'guide-cta-main', function () {
        state.qi = 0; state.qok = 0; saveState();
        renderQuiz();
      }));
    }
    wrap.appendChild(ctaBtn('🏠 Retour au catalogue', 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    setPanelView(passed ? '🎉 QCM réussi !' : 'Résultat du QCM', wrap, f.title + ' — QCM');
    document.getElementById('guide-panel-progress-fill').style.width = '100%';
  }

  /* ---- Défi ---- */

  var _defiTimer = null;

  window.GuideStartChallenge = function (fid) {
    clearHighlight(); clearTrigger();
    fetchFormation(fid, function (f) {
      if (!f || !hasChallenge(f)) { alert('Pas de défi pour cette formation.'); return; }
      formation = f;
      state = { active: true, formation_id: fid, mode: 'defi' };
      saveState();
      renderChallenge(); showPanel();
    });
  };

  function renderChallenge() {
    var c = formation.challenge;
    var wrap = document.createElement('div');
    if (c.intro) {
      var d = document.createElement('div');
      d.innerHTML = sanitizeContent(c.intro);
      wrap.appendChild(d);
    }
    var list = document.createElement('div');
    list.id = 'guide-defi-conds';
    (c.conditions || []).forEach(function (cid) {
      var row = document.createElement('div');
      row.className = 'guide-defi-cond';
      row.dataset.cid = cid;
      row.innerHTML = '<span class="guide-defi-status">⏳</span> <span class="guide-defi-label">' + esc(cid) + '</span>';
      list.appendChild(row);
    });
    wrap.appendChild(list);
    wrap.appendChild(ctaBtn('🔍 Vérifier maintenant', 'guide-cta-main', runDefiCheck));
    var hint = document.createElement('p');
    hint.className = 'guide-defi-hint';
    hint.textContent = 'Vérification automatique toutes les 10 secondes.';
    wrap.appendChild(hint);
    setPanelView('🎯 Défi', wrap, formation.title + ' — Défi');
    runDefiCheck();
    if (_defiTimer) clearInterval(_defiTimer);
    _defiTimer = setInterval(function () {
      if (!state || state.mode !== 'defi') { clearInterval(_defiTimer); _defiTimer = null; return; }
      runDefiCheck();
    }, 10000);
  }

  function runDefiCheck() {
    var rows = document.querySelectorAll('#guide-defi-conds .guide-defi-cond');
    if (!rows.length) return;
    var remaining = rows.length;
    var allMet = true;
    Array.prototype.forEach.call(rows, function (row) {
      checkCondition(row.dataset.cid, function (met, label) {
        row.querySelector('.guide-defi-status').textContent = met ? '✅' : '❌';
        row.querySelector('.guide-defi-label').textContent  = label || row.dataset.cid;
        row.classList.toggle('met', met);
        if (!met) allMet = false;
        if (--remaining === 0 && allMet) defiSuccess();
      });
    });
  }

  function defiSuccess() {
    if (!state || state.mode !== 'defi') return;
    if (_defiTimer) { clearInterval(_defiTimer); _defiTimer = null; }
    var f = formation;
    serverPost('activity', { formation_id: f.id, activity: 'challenge' }, function () { updateDoneBadge(f.id); });
    state.active = false; state.mode = null; saveState();
    var wrap = document.createElement('div');
    var p = document.createElement('p');
    p.innerHTML = '<b>Bravo !</b> Toutes les conditions du défi sont remplies.';
    wrap.appendChild(p);
    var badge = document.createElement('p');
    badge.id = 'guide-done-badge';
    wrap.appendChild(badge);
    var nh = document.createElement('div');
    wrap.appendChild(nh);
    wrap.appendChild(ctaBtn('🏠 Retour au catalogue', 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    setPanelView('🏆 Défi réussi !', wrap, f.title + ' — Défi');
    document.getElementById('guide-panel-progress-fill').style.width = '100%';
    fetchNextFormation(f.id, function (n) {
      if (n) nh.appendChild(ctaBtn('▶ Formation suivante : ' + esc(n.title), 'guide-cta-main', function () { GuideStart(n.id); }));
    });
  }

  /* ---- Outils : checklist & FAQ ---- */

  window.GuideStartTool = function (id) {
    clearHighlight(); clearTrigger();
    fetchFormation(id, function (f) {
      if (!f) { alert('Contenu introuvable.'); return; }
      formation = f;
      if (f.type === 'checklist') {
        // Conserver l'avancement local si on rouvre la même checklist
        var prev = loadState();
        if (prev && prev.tool_id === id && prev.mode === 'checklist') state = prev;
        else state = { active: true, tool_id: id, mode: 'checklist', qidx: 0, tags: [], answers: null, checked: {} };
        state.active = true;
        saveState();
        renderChecklist();
      } else if (f.type === 'faq') {
        state = { active: true, tool_id: id, mode: 'faq', node: 'start', hist: [] };
        saveState();
        renderFaq();
      } else {
        window.GuideStart(id);
        return;
      }
      showPanel();
    });
  };

  function renderChecklist() {
    var t  = formation;
    var qs = t.questions || [];
    if (qs.length && !state.answers) { renderChecklistQuestion(); return; }
    var tags  = state.tags || [];
    var items = [];
    (t.items || []).forEach(function (it, idx) {
      if (!it.tags || !it.tags.length) { it._idx = idx; items.push(it); return; }
      for (var k = 0; k < it.tags.length; k++) {
        if (tags.indexOf(it.tags[k]) !== -1) { it._idx = idx; items.push(it); return; }
      }
    });
    var wrap = document.createElement('div');
    items.forEach(function (it) {
      var key = 'i' + it._idx;
      var row = document.createElement('label');
      row.className = 'guide-ck-item';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = !!(state.checked && state.checked[key]);
      cb.addEventListener('change', function () {
        if (!state.checked) state.checked = {};
        state.checked[key] = cb.checked;
        row.classList.toggle('done', cb.checked);
        saveState();
        updateCkProgress();
      });
      row.appendChild(cb);
      var span = document.createElement('span');
      span.innerHTML = esc(it.label) + (it.page ? ' <a href="' + esc(buildUrl(it.page)) + '" class="guide-ck-link" title="Aller sur la page">↗</a>' : '');
      row.appendChild(span);
      if (cb.checked) row.classList.add('done');
      wrap.appendChild(row);
      // Auto-cochage par condition (sans re-render : mise à jour ciblée)
      if (it.condition && !cb.checked) {
        checkCondition(it.condition, function (met) {
          if (met && !cb.checked) {
            cb.checked = true;
            row.classList.add('done');
            if (!state.checked) state.checked = {};
            state.checked[key] = true;
            saveState();
            updateCkProgress();
          }
        });
      }
    });
    if (qs.length) {
      wrap.appendChild(ctaBtn('↺ Refaire le questionnaire', 'guide-cta-ghost', function () {
        state.answers = null; state.tags = []; state.qidx = 0;
        saveState();
        renderChecklist();
      }));
    }
    setPanelView(t.title || 'Checklist', wrap, '');
    updateCkProgress();
  }

  function updateCkProgress() {
    var rows  = document.querySelectorAll('#guide-panel-step-content .guide-ck-item');
    var done  = document.querySelectorAll('#guide-panel-step-content .guide-ck-item.done');
    var total = rows.length;
    document.getElementById('guide-panel-progress-text').textContent = 'Checklist — ' + done.length + ' / ' + total;
    document.getElementById('guide-panel-progress-fill').style.width = (total ? Math.round(done.length / total * 100) : 0) + '%';
  }

  function renderChecklistQuestion() {
    var t  = formation;
    var qs = t.questions || [];
    var i  = state.qidx || 0;
    if (i >= qs.length) {
      state.answers = true;
      saveState();
      renderChecklist();
      return;
    }
    var q = qs[i];
    var wrap = document.createElement('div');
    (q.choices || []).forEach(function (ch) {
      wrap.appendChild(ctaBtn(esc(ch.label), 'guide-cta-main', function () {
        state.tags = (state.tags || []).concat(ch.tags || []);
        state.qidx = i + 1;
        saveState();
        renderChecklistQuestion();
      }));
    });
    setPanelView(q.q || '', wrap, 'Question ' + (i + 1) + ' / ' + qs.length);
  }

  function renderFaq() {
    var nodes = formation.nodes || {};
    var nid = state.node || 'start';
    var n = nodes[nid] || nodes.start;
    var wrap = document.createElement('div');
    if (!n) {
      wrap.textContent = 'FAQ vide ou nœud "start" manquant.';
      setPanelView(formation.title || 'FAQ', wrap, '');
      return;
    }
    if (n.solution) {
      var d = document.createElement('div');
      d.className = 'guide-faq-sol';
      d.innerHTML = sanitizeContent(n.solution);
      wrap.appendChild(d);
      if (n.page)      wrap.appendChild(ctaBtn('📍 Aller sur la page', 'guide-cta-main', function () { window.location.href = buildUrl(n.page); }));
      if (n.formation) wrap.appendChild(ctaBtn('🎓 Lancer la formation liée', '', function () { GuideStart(n.formation); }));
    } else {
      var p = document.createElement('p');
      p.textContent = n.q || '';
      wrap.appendChild(p);
      (n.answers || []).forEach(function (a) {
        wrap.appendChild(ctaBtn(esc(a.label), 'guide-faq-a', function () {
          state.hist = (state.hist || []).concat([nid]);
          state.node = a.next;
          saveState();
          renderFaq();
        }));
      });
    }
    if ((state.hist || []).length) {
      wrap.appendChild(ctaBtn('↶ Retour', 'guide-cta-ghost', function () {
        var h = state.hist || [];
        state.node = h.pop() || 'start';
        state.hist = h;
        saveState();
        renderFaq();
      }));
    }
    if (nid !== 'start') {
      wrap.appendChild(ctaBtn('⟲ Recommencer', 'guide-cta-ghost', function () {
        state.node = 'start'; state.hist = [];
        saveState();
        renderFaq();
      }));
    }
    setPanelView(formation.title || 'Dépannage', wrap, 'Dépannage');
  }

  /* ---- Aide contextuelle ---- */

  var _ctxItems = [];

  function ctxEnabled() { return localStorage.getItem('guide_ctx_help') !== '0'; }

  function maybeContextHelp() {
    if (state && state.active) return;
    if (!ctxEnabled()) { showFabIfNeeded(); return; }
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=context&path=' + encodeURIComponent(window.location.pathname);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try { _ctxItems = JSON.parse(xhr.responseText) || []; } catch (e) { _ctxItems = []; }
      showFabIfNeeded();
    };
    xhr.send();
  }

  function renderContextPanel() {
    formation = null;
    var wrap = document.createElement('div');
    var p = document.createElement('p');
    p.textContent = 'Contenus du Guide FFTA liés à cette page :';
    wrap.appendChild(p);
    var icons = { formation: '🎓', checklist: '🧰', faq: '🛟' };
    _ctxItems.forEach(function (it) {
      wrap.appendChild(ctaBtn((icons[it.type] || '📄') + ' ' + esc(it.title), 'guide-cta-main', function () {
        if (it.type === 'formation') GuideStart(it.id);
        else GuideStartTool(it.id);
      }));
    });
    wrap.appendChild(ctaBtn('🏠 Tout le catalogue', 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    var off = document.createElement('p');
    off.innerHTML = '<a href="#" style="font-size:11px;color:#999">Désactiver l\'aide contextuelle</a>';
    off.querySelector('a').addEventListener('click', function (e) {
      e.preventDefault();
      localStorage.setItem('guide_ctx_help', '0');
      _ctxItems = [];
      hidePanel();
    });
    wrap.appendChild(off);
    setPanelView('💡 Aide contextuelle', wrap, '');
  }

  /* ===== Injection du guide dans les popups ianseo (PopEdit.php…) =====
     Les popups utilisent head-popup.php qui n'appelle pas get_which_menu() → notre panneau n'y est
     pas injecté côté serveur. On enveloppe window.open (parent) pour injecter, dans la popup (même
     origine), le CSS + le balisage du panneau/recorder + une instance de guide.js qui s'auto-initialise. */

  function setupPopupInjection() {
    if (window._guidePopupWrapped) return;
    window._guidePopupWrapped = true;
    var orig = window.open;
    if (typeof orig !== 'function') return;
    window.open = function () {
      var w = orig.apply(window, arguments);
      try { if (w) watchPopup(w); } catch (e) {}
      return w;
    };
  }

  function shouldGuidePopup() {
    if (recActive()) return true;
    var s = loadState();
    return !!(s && s.active);
  }

  function watchPopup(win) {
    var ticks = 0;
    var iv = setInterval(function () {
      if (++ticks > 2400) { clearInterval(iv); return; } // garde-fou (~30 min)
      var doc;
      try {
        if (win.closed) { clearInterval(iv); return; }
        doc = win.document;                                   // lève si cross-origin
        if (!doc || doc.readyState !== 'complete' || !doc.body) return;
      } catch (e) { clearInterval(iv); return; }              // autre origine → on abandonne
      if (!shouldGuidePopup()) return;                         // rien à afficher
      if (!doc.getElementById('guide-panel')) injectGuide(win);// (ré)injecte après chargement/rechargement
    }, 750);
  }

  // Réutilise l'URL (versionnée ?v=mtime) d'un asset déjà chargé dans la fenêtre parente.
  function guideAssetUrl(rx, fallback) {
    var tags = document.querySelectorAll('link[href],script[src]');
    for (var i = 0; i < tags.length; i++) {
      var u = tags[i].href || tags[i].src;
      if (u && rx.test(u)) return u;
    }
    return fallback;
  }

  function injectGuide(win) {
    try {
      var doc = win.document;
      if (!doc || doc.getElementById('guide-panel')) return;
      var root = apiRoot();
      var head = doc.head || doc.documentElement;

      var sVar = doc.createElement('script');
      sVar.textContent = 'var WebDir=' + JSON.stringify(root) + ';';
      head.appendChild(sVar);

      var link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = guideAssetUrl(/Modules\/Custom\/GUIDE\/assets\/guide\.css/, root + 'Modules/Custom/GUIDE/assets/guide.css');
      head.appendChild(link);

      // Copie du panneau + FAB + recorder depuis la fenêtre parente (sans état d'affichage hérité)
      ['guide-panel', 'guide-fab', 'guide-rec'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        var imported = doc.importNode(el, true);
        imported.style.display = 'none';
        if (imported.classList) imported.classList.remove('guide-panel-wide', 'guide-panel-left', 'guide-fab-left');
        doc.body.appendChild(imported);
      });

      // Instance de guide.js dans la popup : s'auto-initialise (DOM déjà prêt)
      var sc = doc.createElement('script');
      sc.src = guideAssetUrl(/Modules\/Custom\/GUIDE\/assets\/guide\.js/, root + 'Modules/Custom/GUIDE/assets/guide.js');
      doc.body.appendChild(sc);
    } catch (e) { /* popup fermée / cross-origin */ }
  }

  /* ===== Enregistreur de triggers ===== */

  var LS_REC = 'guide_rec';
  var _rec = null;
  var _recClickOff = null;

  function recLoad() { try { return JSON.parse(localStorage.getItem(LS_REC)); } catch (e) { return null; } }
  function recSave() { localStorage.setItem(LS_REC, JSON.stringify(_rec)); }
  function recClearStorage() { localStorage.removeItem(LS_REC); }
  function recActive() { var r = recLoad(); return !!(r && r.active); }
  // Recharge _rec depuis localStorage avant toute modif : parent et popup partagent le même
  // enregistrement, on évite ainsi qu'une fenêtre écrase les triggers ajoutés par l'autre.
  function recResync() { var fresh = recLoad(); if (fresh && fresh.active) _rec = fresh; }

  function recInit() {
    _rec = recLoad();
    var box = document.getElementById('guide-rec');
    if (!box || !_rec) return;
    if (panel) panel.style.display = 'none';
    if (fab)   fab.style.display = 'none';
    box.style.display = 'flex';

    document.getElementById('guide-rec-pause').addEventListener('click', recTogglePause);
    document.getElementById('guide-rec-page').addEventListener('click', recAddPage);
    document.getElementById('guide-rec-undo').addEventListener('click', recUndo);
    document.getElementById('guide-rec-done').addEventListener('click', recDone);
    document.getElementById('guide-rec-close').addEventListener('click', recAbort);

    recRenderList();
    recUpdatePauseBtn();
    if (!_rec.paused) recAttachClicks();
  }

  function recAttachClicks() {
    recDetachClicks();
    var handler = function (e) {
      var box = document.getElementById('guide-rec');
      if (box && box.contains(e.target)) return;
      if (panel && panel.contains(e.target)) return;
      // Ne pas enregistrer sur les pages du module GUIDE (catalogue/admin)
      if (/\/Modules\/Custom\/GUIDE\//.test(window.location.pathname)) return;

      var target = e.target.closest('a,button,input,select,textarea,label,[onclick],[role="button"],[role="menuitem"]') || e.target;
      if (!target || target === document.body || target === document.documentElement) return;
      var sel = buildSelector(target);
      if (!sel) return;

      var tag  = target.tagName.toLowerCase();
      var type = (tag === 'input' || tag === 'select' || tag === 'textarea') ? 'change' : 'click';
      recResync();
      _rec.triggers.push({ kind: 'action', trigger: type, selector: sel, page: currentPagePath(), required: true });
      recSave();
      recRenderList();
      // Ne pas empêcher l'action : l'utilisateur doit pouvoir naviguer / interagir
    };
    document.addEventListener('click', handler, true);
    _recClickOff = function () { document.removeEventListener('click', handler, true); };
  }
  function recDetachClicks() { if (_recClickOff) { _recClickOff(); _recClickOff = null; } }

  function recTogglePause() {
    _rec.paused = !_rec.paused;
    recSave(); recUpdatePauseBtn();
    if (_rec.paused) recDetachClicks(); else recAttachClicks();
  }
  function recUpdatePauseBtn() {
    var b = document.getElementById('guide-rec-pause');
    if (b) b.textContent = _rec.paused ? '⏺ Reprendre' : '⏸ Pause';
    var box = document.getElementById('guide-rec');
    if (box) box.classList.toggle('guide-rec-paused', !!_rec.paused);
  }
  function recAddPage() {
    recResync();
    _rec.triggers.push({ kind: 'etat', condition: '__page', page: currentPagePath(), required: true });
    recSave(); recRenderList();
  }
  function recUndo() {
    recResync();
    if (!_rec.triggers.length) return;
    _rec.triggers.pop();
    recSave(); recRenderList();
  }
  function recDone() {
    localStorage.setItem('guide_rec_result', JSON.stringify({
      formation_id: _rec.formation_id, step_id: _rec.step_id, triggers: _rec.triggers
    }));
    var url = _rec.return_url;
    recClearStorage();
    window.location.href = url || (apiRoot() + 'Modules/Custom/GUIDE/admin/');
  }
  function recAbort() {
    if (!confirm('Abandonner l\'enregistrement ?\nLes triggers enregistrés seront perdus.')) return;
    var url = _rec.return_url;
    recClearStorage();
    window.location.href = url || (apiRoot() + 'Modules/Custom/GUIDE/admin/');
  }

  function recRenderList() {
    var n = _rec.triggers.length;
    var title = document.getElementById('guide-rec-title');
    if (title) title.textContent = 'Enregistrement (' + n + ')';
    var list = document.getElementById('guide-rec-list');
    if (!list) return;
    list.innerHTML = '';
    _rec.triggers.forEach(function (t, i) {
      var row = document.createElement('div');
      row.className = 'guide-rec-item';
      row.textContent = (i + 1) + '. ' + ((t.kind === 'etat')
        ? '📍 page : ' + (t.page || '')
        : '🖱 ' + (t.selector || ''));
      list.appendChild(row);
    });
    list.scrollTop = list.scrollHeight;
  }

  function currentPagePath() {
    var base = apiRoot().replace(/\/$/, '');
    var path = window.location.pathname;
    if (base && path.indexOf(base) === 0) path = path.slice(base.length);
    return path || '/';
  }

  /* Génère un sélecteur CSS raisonnablement robuste pour un élément. */
  function cssEsc(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/([^a-zA-Z0-9_-])/g, '\\$1');
  }
  // Id dynamique type "d_q_QuSession_25360" (suffixe numérique = id d'enregistrement DB) :
  // le #id exact ne survivrait pas au changement de participant/compétition → sélecteur
  // d'attribut par préfixe [id^="d_q_QuSession_"], stable et qui matche tous les équivalents.
  function dynamicIdSelector(id) {
    var m = id.match(/^(.+?[_-])\d+$/);
    if (m && m[1].length >= 3) return '[id^="' + m[1].replace(/"/g, '\\"') + '"]';
    return null;
  }

  function buildSelector(el) {
    if (!el || el.nodeType !== 1) return null;
    if (el.id) {
      var dyn = dynamicIdSelector(el.id);
      if (dyn) return dyn;
      var idSel = '#' + cssEsc(el.id);
      try { if (document.querySelectorAll(idSel).length === 1) return idSel; } catch (e) {}
    }
    var parts = [];
    var node = el, depth = 0;
    while (node && node.nodeType === 1 && node !== document.body && depth < 6) {
      if (node.id) {
        var s = dynamicIdSelector(node.id) || ('#' + cssEsc(node.id));
        try { if (document.querySelectorAll(s).length === 1) { parts.unshift(s); return parts.join(' > '); } } catch (e) {}
      }
      var part = node.tagName.toLowerCase();
      var cls = recUniqueClass(node);
      if (cls) {
        part += '.' + cls;
      } else {
        var nth = recNthOfType(node);
        if (nth > 1) part += ':nth-of-type(' + nth + ')';
      }
      parts.unshift(part);
      var cand = parts.join(' > ');
      try { if (document.querySelectorAll(cand).length === 1) return cand; } catch (e) {}
      node = node.parentElement;
      depth++;
    }
    return parts.join(' > ');
  }
  function recUniqueClass(node) {
    if (!node.classList || !node.classList.length || !node.parentElement) return null;
    for (var i = 0; i < node.classList.length; i++) {
      var c = node.classList[i];
      if (/^guide-/.test(c)) continue;
      var esc2 = cssEsc(c);
      try {
        if (node.parentElement.querySelectorAll(':scope > .' + esc2).length === 1) return esc2;
      } catch (e) {}
    }
    return null;
  }
  function recNthOfType(node) {
    var i = 1, sib = node;
    while (sib.previousElementSibling) {
      sib = sib.previousElementSibling;
      if (sib.tagName === node.tagName) i++;
    }
    return i;
  }

})();
