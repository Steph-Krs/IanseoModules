/**
 * Interactive Guide — course player.
 *
 * Runs on every ianseo page, injected by the module's menu.php. It owns the side
 * panel, walks the user through the steps of a course, highlights the element
 * each step points at, and plays the quiz, challenge, checklist and
 * troubleshooting readers.
 *
 * THE ONE RULE THAT SHAPES MOST OF THIS FILE: never hold on to a DOM node.
 * ianseo re-renders whole sub-trees after a save, so a node kept in a variable
 * becomes detached and would be read as "the element disappeared". The current
 * CSS SELECTOR is kept instead and re-queried; event listeners are delegated on
 * document so they survive the node being replaced.
 *
 * Progress lives in two places on purpose: localStorage for instant reads and
 * for installations without accounts, and the server (through guide-api.php) so
 * a user finds their place again on another machine.
 */
(function () {
  'use strict';

  /* ===== Translations =====
     menu.php publishes the strings as window.GUIDE_T; see guide_js_strings().
     T() falls back to the key itself, so a missing translation shows something
     recognisable instead of "undefined". */
  function T(key) {
    var t = window.GUIDE_T;
    return (t && typeof t[key] === 'string') ? t[key] : key;
  }

  // Per-account separation (the account module is optional): menu.php sets
  // window.GUIDE_USER, '' when there is none. The functional state is suffixed
  // with the account so two users of the same browser share neither the course
  // in progress nor its progress. Cosmetic preferences (side, width) stay common.
  var GUSER     = (typeof window.GUIDE_USER === 'string') ? window.GUIDE_USER : '';
  var LS_SUFFIX = GUSER ? '::' + GUSER : '';

  var LS_STATE = 'guide_state' + LS_SUFFIX;
  var LS_DONE  = 'guide_completed' + LS_SUFFIX;
  var LS_CTX   = 'guide_ctx_help' + LS_SUFFIX;
  var LS_SIDE  = 'guide_panel_side';
  var LS_WIDE  = 'guide_panel_wide';

  var state       = null;
  var formation   = null;
  var panel, fab;
  var _triggerOff      = null;  // cleanup of the active listener
  var _syncTimer       = null;  // debounce for the server sync
  var _triggerIdx      = 0;     // current trigger within the step
  var _doneTriggerMask = {};    // { index: true } for triggers already fired
  var _navigating      = false; // page unloading (an ianseo submit or navigation)

  /* ===== Diagnostic tracer, surviving page reloads =====
     Kept in localStorage rather than the console because the bugs worth tracing
     here happen ACROSS a page load — an ianseo form submit wipes the console.
     Console: GuideDebug(true) to arm and reset, reproduce, then GuideDebug(). */
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
    if (on === true)  { localStorage.setItem('guide_debug_on', '1'); localStorage.removeItem(LS_DBG); console.log('[Guide] debug ON — reproduce the bug, then type GuideDebug()'); return; }
    if (on === false) { localStorage.setItem('guide_debug_on', '0'); console.log('[Guide] debug OFF'); return; }
    var arr = [];
    try { arr = JSON.parse(localStorage.getItem(LS_DBG)) || []; } catch (e) {}
    console.log('%c[Guide] trace (' + arr.length + ' events):', 'font-weight:bold');
    arr.forEach(function (e) { console.log(e.t + '  ' + e.p + '  ' + e.m); });
    return arr;
  };

  /* ===== Init ===== */

  function guideInit() {
    panel = document.getElementById('guide-panel');
    fab   = document.getElementById('guide-fab');
    if (!panel) return;

    // ianseo popups (PopEdit.php and friends) use head-popup.php, which does NOT
    // call get_which_menu() — so the server never injects the panel into them.
    // window.open is wrapped in the parent window instead.
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

    // Once the page is unloading, stop moving backwards: an ianseo form submit
    // tears elements out of the DOM, and treating that as "the target vanished"
    // would save a position earlier than the one the user actually reached.
    window.addEventListener('beforeunload', function () { _navigating = true; });
    window.addEventListener('pagehide',     function () { _navigating = true; });

    // Trigger recording takes priority over playing a course.
    if (recActive()) { recInit(); return; }

    applyPanelSide(loadSide());
    applyWide(loadWide());

    state = loadState();
    _dbg('INIT trigger_index=' + (state && state.trigger_index) + ' step=' + (state && state.step_index) +
         ' active=' + (state && state.active) + ' validated=' + (state && state.validated ? Object.keys(state.validated).join(',') : '-'));

    // Push the local position to the server on load, which catches up the
    // navigations that were interrupted before the debounced sync could fire.
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

  // Runs even when the DOM is already parsed. Essential in a popup, where this
  // script is injected after the page has finished loading and DOMContentLoaded
  // will never fire again.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', guideInit);
  else guideInit();

  /* ===== Public API (window.Guide*) ===== */

  window.GuideStart = function (formationId) {
    clearHighlight(); clearTrigger(); clearTourWarning();
    fetchFormation(formationId, function (f) {
      formation = f;
      if (!formation) { alert(T('JsCourseNotFound')); return; }
      state = { active: true, formation_id: formationId, step_index: 0, validated: {}, gp_id: null };
      saveState();
      serverPost('start', {
        formation_id:      formationId,
        formation_version: formation.version || '1.0'
      }, function (data) {
        if (data && data.gp_id) {
          state.gp_id = data.gp_id;
          saveState();
          // The user may already have moved on while the request was in flight,
          // so send the position again now that there is a row to attach it to.
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
      if (!formation) { alert(T('JsCourseNotFound')); return; }
      if (state.step_index >= formation.steps.length) state.step_index = 0;

      // Always ask the server as well: gp_id is missing whenever localStorage
      // was cleared or the user is resuming from another machine.
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
      if (toggle) { toggle.textContent = '→'; toggle.title = T('JsMoveRight'); }
    } else {
      panel.classList.remove('guide-panel-left');
      if (fab) fab.classList.remove('guide-fab-left');
      if (toggle) { toggle.textContent = '←'; toggle.title = T('JsMoveLeft'); }
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
      btn.title       = wide ? T('JsPanelNormalSize') : T('PanelMaximise');
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
    _dbg('nextStep -> step ' + (state.step_index + 1) + ', reset trigger_index=0');
    clearHighlight(); clearTrigger();
    state.step_index++;
    state.trigger_index = 0;
    saveState(); scheduleSync(); renderStep();
  }

  // Index of the previous ACTIONABLE trigger, skipping the sub-steps that only
  // display something and cannot be acted on — going back to one of those would
  // look like nothing happened.
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

  // 🔄 Start the hints of this step again. This is a DELIBERATE reset by the
  // user, which is why it is allowed to move trigger_index backwards — the
  // automatic paths never are (see persistTriggerIdx).
  function restartTriggers() {
    if (!formation || !state) return;
    var step = formation.steps[state.step_index];
    if (!(step.triggers || []).length) return;
    _dbg('restartTriggers (manual) -> trigger_index=0');
    clearHighlight(); clearTrigger();
    _triggerIdx = 0;
    _doneTriggerMask = {};
    if (state.validated) delete state.validated[step.id];
    state.trigger_index = 0;
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
    startTriggerSequence(step);
  }

  // ↶ Back one hint. Steps back exactly one actionable trigger and stops there:
  // no cascade, so the user stays in control of where they land.
  function backOneTrigger() {
    if (!formation || !state) return;
    var step = formation.steps[state.step_index];
    var i = prevActionableIdx(step, _triggerIdx);
    if (i < 0) return;
    _dbg('backOneTrigger (manual) ' + _triggerIdx + ' -> ' + i);
    clearHighlight(); clearTrigger();
    for (var k = i; k < (step.triggers || []).length; k++) delete _doneTriggerMask[k];
    _triggerIdx = i;
    if (state.validated) delete state.validated[step.id];
    state.trigger_index = i;
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
    startTriggerSequence(step);
  }

  // Enable, disable, or hide entirely the "start over" and "back one hint"
  // buttons: a step with no trigger has nothing for them to act on.
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
    // The panel deliberately stays open: it now offers the quiz, the challenge
    // and the next course, which is where most users go from here.
    renderCompletionView();
  }

  function stopFormation() {
    if (!state || !state.active) { formation = null; hidePanel(); return; }
    var isTool = (state.mode === 'checklist' || state.mode === 'faq' || state.mode === 'quiz' || state.mode === 'defi');
    // A tool (checklist, troubleshooting, quiz, challenge) has nothing stored on
    // the server, so the wording promises only what is actually kept.
    var msg = isTool ? T('JsCloseKeepLocal') : T('JsLeaveCourse');
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

  /* ===== Is a step done? =====
     A step with no REQUIRED trigger is done as soon as it is shown: it only had
     something to read. A step that does have one waits for it to fire. */

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
    btn.textContent = isLast ? T('JsFinish') + ' ✓' : T('CmdNextStep') + ' ▶';
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
    // optional === false means the step insists on the real action: the manual
    // "mark as done" escape hatch is then hidden rather than merely discouraged.
    var isStrict        = step.optional === false;
    var done            = !!(state.validated && state.validated[step.id]);

    if (wrap) wrap.style.display = (needsValidation && !isStrict) ? '' : 'none';
    btn.textContent = done ? '✓ ' + T('JsDone') : '☐ ' + T('CmdMarkDone');
    if (done) btn.classList.add('guide-validated');
    else      btn.classList.remove('guide-validated');
  }

  /* ===== Sequential triggers =====
     A step holds an ordered list of triggers and the player attaches them one at
     a time, so the user is only ever shown one thing to do. */

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

    // A sub-step with neither an event to wait for nor a requirement is purely
    // decorative: skip it rather than stall the sequence on it.
    var isAction  = !t.kind || t.kind === 'action';
    var actionable = isAction ? (t.trigger || t.required) : t.required;
    if (!actionable) { skipCurrentTrigger(step); return; }

    // Conditional branch. Evaluating the gate may need the server, so everything
    // after it has to cope with the sequence having moved on meanwhile.
    if (t.when || t.when_not) {
      var idxAtEval = _triggerIdx;
      clearHighlight();
      evaluateGate(t, function (active) {
        // Race guard: did the step or the trigger change while we were asking?
        if (!state || !formation || formation.steps[state.step_index] !== step || _triggerIdx !== idxAtEval) return;
        if (active) proceedWithTrigger(step, t);
        else        skipCurrentTrigger(step);
      });
      return;
    }

    proceedWithTrigger(step, t);
  }

  // Mark the current trigger as done and move on. Used both for a decorative
  // sub-step and for a branch that was not taken — a required trigger disabled
  // by its own condition must NOT block the step.
  function skipCurrentTrigger(step) {
    _dbg('SKIP idx=' + _triggerIdx);
    _doneTriggerMask[_triggerIdx] = true;
    _triggerIdx++;
    persistTriggerIdx();
    attachCurrentTrigger(step);
  }

  // Evaluate a trigger's activation condition. 'when' makes it active when the
  // condition is met, 'when_not' when it is not; both together are an AND. This
  // is what gives a course an if/else without nesting its steps.
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
    // ---- State trigger: a server condition, or "the right page is open" ----
    if (t.kind === 'etat') {
      clearHighlight();
      evaluateEtat(step, t, function (met, label) {
        _dbg('etat idx=' + _triggerIdx + ' cond=' + (t.condition || '') + ' page=' + (t.page || '') + ' met=' + met);
        if (met) {
          onTriggerFired(step);
        } else if (t.condition === '__page') {
          // updatePageInfo() already shows a "go to the page" link, so a second
          // message saying the same thing would only add noise.
          clearConditionWait();
        } else {
          showConditionWait(label);
          // A __css element can appear or disappear without a page load, so it
          // has to be polled rather than checked once.
          if (t.condition === '__css') startEtatPoll(step, t);
        }
      });
      return;
    }

    // ---- Action trigger: a DOM event ----
    var tPage = triggerPage(step, t);
    clearHighlight();

    if (!isOnRightPage(tPage)) return;   // wrong page: updatePageInfo shows the link

    if (t.selector) {
      // Never step backwards automatically. The highlight settles on the target
      // if it is there (or on its nearest visible ancestor when it is inside a
      // closed submenu), and otherwise simply waits for it to appear — the
      // visibility watch calls applyHighlight again. The event listener is
      // delegated on document, so it works even before the target exists.
      _curSelector = t.selector;
      _hint = t.hint || '';
      applyHighlight();         // the element itself, or its nearest visible ancestor
      startHighlightTracking(); // scroll/resize listeners plus the re-query watch
      if (step.strict_click) enableStrictClick(t.selector);
    }

    // A manual trigger (trigger: null) or one with no target has no event to
    // wait for: the user confirms it with "mark as done".
    if (!t.trigger || !t.selector) return;

    bindTriggerEvent(step, t);
  }

  // Delegated on document rather than bound to the node, so it survives ianseo
  // re-rendering the part of the page the target lives in. No direct reference
  // to the element is kept anywhere.
  function bindTriggerEvent(step, t) {
    // A 'change' trigger on a field that is ALREADY filled in would never fire,
    // leaving the user stuck on a step they have effectively done: check first.
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
    _dbg('FIRED -> idx=' + _triggerIdx);
    persistTriggerIdx();
    attachCurrentTrigger(step);
    updatePageInfo(step);
    if (allRequiredDone(step)) autoValidateStep(step);
  }

  // Saving the position within a step is MONOTONIC: it never goes down.
  //
  // Only real progress (onTriggerFired, skipCurrentTrigger) is saved through
  // here. That is a defence against a whole class of bug: after an ianseo page
  // reload the earlier targets are often gone, and anything that inferred a
  // position from what is currently on screen would walk the user backwards.
  // Deliberate rewinds — prev/nextStep, revalidateEtat, the 🔄 and ↶ buttons —
  // set state.trigger_index directly and bypass this.
  function persistTriggerIdx() {
    if (!state) return;
    if (typeof state.trigger_index !== 'number' || _triggerIdx > state.trigger_index) {
      _dbg('PERSIST trigger_index ' + state.trigger_index + ' -> ' + _triggerIdx);
      state.trigger_index = _triggerIdx;
      saveState();
    } else {
      _dbg('persist skipped (monotonic) _triggerIdx=' + _triggerIdx + ' saved=' + state.trigger_index);
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
    // Hidden by being positioned off-screen: ianseo's Suckerfish menus park
    // closed submenus at left/top -9999px, which passes every display and size
    // test above. Without this the arrow was placed at top: -99736px.
    // Simply scrolling out of view keeps a document coordinate >= 0, so a
    // scrolled element is not mistaken for a hidden one.
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

  /* ===== Rendering a step ===== */

  function renderStep() {
    if (!formation || !state) return;
    showNav(true);
    var step  = formation.steps[state.step_index];
    var total = formation.steps.length;
    var idx   = state.step_index;
    var pct   = total > 1 ? Math.round(idx / (total - 1) * 100) : 100;

    document.getElementById('guide-panel-formation-name').textContent = formation.title;
    document.getElementById('guide-panel-progress-fill').style.width  = pct + '%';
    // Steps are stored zero-based and shown one-based.
    document.getElementById('guide-panel-progress-text').textContent  = T('JsStep') + ' ' + (idx + 1) + ' / ' + total;
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
      cb(isOnRightPage(page), T('JsMustBeOnPage') + ' ' + (page || T('JsPageUndefined')));
    } else if (t.condition === '__css') {
      var present = cssElementVisible(t.selector);
      var met = t.absent ? !present : present;
      var label = t.hint || (t.absent ? T('JsWaitElementGone') : T('JsWaitElementShown'));
      cb(met, label);
    } else {
      checkCondition(t.condition || '', cb);
    }
  }

  // Is a selector matched by something actually visible on screen? Handles the
  // attribute-prefix selectors courses use for ianseo's numbered ids, such as
  // [id^="d_q_QuSession_"].
  function cssElementVisible(selector) {
    if (!selector) return false;
    try {
      var el = document.querySelector(selector);
      return !!(el && isElementVisible(el));
    } catch (e) { return false; }
  }

  // Polling for a client-side state (__css). Needed because the element can
  // appear or disappear without any page load — ianseo builds several tables in
  // JavaScript after the page is served.
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

  // Re-check a state, taking its activation condition into account first: a
  // branch that was never taken must not be able to un-validate the step.
  function gateThenEtat(step, t, cb) {
    evaluateGate(t, function (active) {
      if (!active) { cb(true); return; }
      evaluateEtat(step, t, function (met) { cb(met); });
    });
  }

  function revalidateEtat(step, etatTriggers) {
    _dbg('revalidateEtat: ' + etatTriggers.length + ' required state(s) on a validated step');
    var remaining = etatTriggers.length;
    var allMet    = true;
    etatTriggers.forEach(function (t) {
      gateThenEtat(step, t, function (met) {
        _dbg('  revalidate cond=' + (t.condition || '') + ' page=' + (t.page || '') + ' met=' + met);
        if (!met) allMet = false;
        if (--remaining === 0) {
          if (allMet) {
            _dbg('revalidateEtat -> all still true, step stays validated');
            clearHighlight(); clearTrigger();
          } else {
            _dbg('revalidateEtat -> FAILED: un-validate + RESET trigger_index=0, restart sequence');
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

  /* ===== Highlighting =====
     The part of the file that has to survive ianseo re-rendering the page under
     it, hence the selector-not-node rule stated at the top. */

  var _highlighted     = null;
  var _arrow           = null;
  var _hint            = '';
  var _strictClickOff  = null;
  var _visWatch        = null;
  var _curSelector     = null;  // selector of the current trigger, re-queried, never a stale node

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

  // Place or re-place the highlight by re-querying the current selector.
  //   target visible          -> highlight it
  //   target present, hidden  -> anchor on the nearest visible ancestor, which
  //                              is the menu entry the user has to open first
  //   nothing visible         -> remove the arrow rather than leave a ghost
  function applyHighlight() {
    if (!_curSelector) return;
    var target = document.querySelector(_curSelector);
    var anchor = target ? (isElementVisible(target) ? target : nearestVisibleAncestor(target)) : null;
    if (anchor === _highlighted) return;   // already on the right element
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

  // A watch that re-queries rather than trusting a remembered node, so it copes
  // with ianseo replacing the element: applyHighlight() re-anchors on the target
  // (or its visible parent) and removes the arrow when nothing is there.
  //
  // It NEVER steps back. An earlier version did, and after a reload that changed
  // the page — importing a lookup table, for instance — the previous triggers'
  // elements were gone, so it walked back up to the navigation menu, which is
  // present on every page, and the course appeared to restart itself. Staying on
  // the current trigger costs nothing: the highlight returns as soon as the
  // target does.
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
      // Never block the guide's own controls. Without this, minimising the panel
      // while strict mode is on would leave the user unable to reopen it.
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

  // Keep the panel from covering the ELEMENT being pointed at. The arrow itself
  // is ignored: it is small, and moving the panel for it would be constant.
  //
  // Escalation: element behind the panel -> move the panel to the other side;
  // still behind it -> halve the panel's width for the duration of the trigger.
  //
  // The panel's FINAL position is computed rather than measured, because during
  // the side-switch animation a measurement returns an intermediate rectangle
  // and produces a false positive.
  var _panelDodged = false;
  var _panelShrunk = false;

  function rectsOverlap(a, b) {
    return !(a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom);
  }

  // The rectangle the panel would occupy on a given side, anchored to the bottom
  // with a 20px margin — matching the CSS, without depending on a measurement.
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

    // 1) Is the element behind the panel, at the panel's final position?
    if (!rectsOverlap(panelSideRect(side, w, h), elRect)) return;

    // 2) Move to the other side, once. clearHighlight() puts the user's own
    //    choice of side back afterwards.
    if (!_panelDodged) {
      _panelDodged = true;
      side = (side === 'right') ? 'left' : 'right';
      applyPanelSide(side);   // visual only, deliberately not saved
    }

    // 3) Still behind it after moving: halve the width, once.
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

  /* ===== Panel visibility, and the "different competition" warning =====
     A course is followed against one competition. Resuming it while another one
     is open is allowed — the steps still make sense — but the elements a step
     points at may not exist there, so the user is told rather than left
     wondering why nothing is highlighted. */

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
    warn.title = T('JsClickToHide');
    warn.innerHTML = T('JsOtherCompetition');
    warn.addEventListener('click', clearTourWarning);
    var prog = document.getElementById('guide-panel-progress');
    if (prog) prog.insertAdjacentElement('afterend', warn);
  }

  function clearTourWarning() {
    var w = document.getElementById(WARN_ID);
    if (w) w.parentNode.removeChild(w);
  }

  function showPanel() { panel.style.display = 'flex'; if (fab) fab.style.display = 'none'; }

  // Minimising means PAUSE. The active trigger AND strict-click mode are both
  // detached — leaving strict mode on with the panel hidden would block the very
  // clicks needed to bring it back. Reopening from the floating button
  // re-attaches everything.
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
    // /foo/index.php and /foo/ are the same page to the web server, so they must
    // compare equal here too. Mirrors guide_norm_path() on the PHP side.
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

  /* Ask the server about SEVERAL conditions at once.
     One request whatever the number of conditions: a challenge polls its own
     every ten seconds, and one request per condition per poll is the kind of
     traffic that is invisible on a laptop and painful on a venue's wifi.
     cb receives an object keyed by condition id: { met, label }. */
  function checkConditions(cids, cb) {
    var list = (cids || []).filter(function (c) { return !!c; });
    if (!list.length) { cb({}); return; }

    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=check-condition&cid='
            + encodeURIComponent(list.join(','));
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var out = {};
      try {
        var data = JSON.parse(xhr.responseText);
        out = data.conditions || {};
      } catch (e) { /* leave out empty: every condition then reads as not met */ }
      list.forEach(function (c) { if (!out[c]) out[c] = { met: false, label: c }; });
      cb(out);
    };
    xhr.send();
  }

  /* One condition, on top of the batch call — the shape most callers want. */
  function checkCondition(cid, cb) {
    checkConditions([cid], function (res) {
      var r = res[cid] || { met: false, label: cid };
      cb(r.met === true, r.label || cid);
    });
  }

  function showConditionWait(label) {
    var el = document.getElementById('guide-panel-condition-wait');
    if (!el) return;
    el.innerHTML = '<p class="guide-condition-wait">🔍 ' + esc(T('JsConditionNotMet')) +
                   '<br><b>' + esc(label) + '</b></p>';
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
        '<p class="guide-page-warning">📍 ' + esc(T('JsStepOnOtherPage')) + '<br>' +
        '<a href="' + esc(buildUrl(page)) + '" class="guide-page-link">' + esc(T('JsGoToPage')) + ' →</a></p>';
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

  /* ===== The other panel views =====
     The same panel serves the quiz, the challenge, the checklist, the
     troubleshooting tree and the end-of-course screen. They share its chrome and
     its persistence; only the body changes. */

  function showNav(show) {
    var n = document.getElementById('guide-panel-nav');
    if (n) n.style.display = show ? 'flex' : 'none';
  }

  // Fill the panel with a view that is not the step-by-step guide, hiding the
  // parts that only make sense there.
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

  // Achievement level: measured against what this course OFFERS, so a course
  // without a quiz can still reach gold.
  function badgeLevel(f, srv) {
    var avail = 1 + (hasQuiz(f) ? 1 : 0) + (hasChallenge(f) ? 1 : 0);
    var done  = 1 + ((srv && srv.quiz) ? 1 : 0) + ((srv && srv.challenge) ? 1 : 0);
    if (done > avail) done = avail;
    if (done >= avail) return 'or';
    return done >= 2 ? 'argent' : 'bronze';
  }

  function badgeHtml(lvl) {
    var label = lvl === 'or' ? T('TargetGold') : (lvl === 'argent' ? T('TargetSilver') : T('TargetBronze'));
    return '<b class="guide-badge-' + lvl + '">🎯 ' + label + '</b>';
  }

  function updateDoneBadge(fid) {
    fetchServerProgress(fid, function (s) {
      var el = document.getElementById('guide-done-badge');
      if (!el || !formation) return;
      var lvl = badgeLevel(formation, s);
      el.innerHTML = esc(T('JsAchievement')) + ' ' + badgeHtml(lvl) +
        (lvl !== 'or' ? '<br><span style="font-size:11px;color:#888">' + esc(T('JsGoldHint')) + '</span>' : '');
    });
  }

  /* ---- End-of-guide screen ---- */

  function renderCompletionView() {
    var f = formation;
    if (!f) return;
    var wrap = document.createElement('div');
    var p = document.createElement('p');
    p.innerHTML = T('JsGuideFinished');
    wrap.appendChild(p);
    var badge = document.createElement('p');
    badge.id = 'guide-done-badge';
    wrap.appendChild(badge);
    // The remaining activities are offered in order, and whichever comes first
    // gets the primary styling, so a course without a quiz still leads somewhere.
    if (hasQuiz(f))      wrap.appendChild(ctaBtn('📝 ' + T('Quiz'), 'guide-cta-main', function () { GuideStartQuiz(f.id); }));
    if (hasChallenge(f)) wrap.appendChild(ctaBtn('🎯 ' + T('JsTakeChallenge'), hasQuiz(f) ? '' : 'guide-cta-main', function () { GuideStartChallenge(f.id); }));
    var nextHolder = document.createElement('div');
    wrap.appendChild(nextHolder);
    wrap.appendChild(ctaBtn('🏠 ' + T('JsBackToCatalogue'), 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    setPanelView('🎉 ' + T('JsCourseComplete'), wrap, f.title);
    document.getElementById('guide-panel-progress-fill').style.width = '100%';
    updateDoneBadge(f.id);
    fetchNextFormation(f.id, function (n) {
      if (n) nextHolder.appendChild(ctaBtn('▶ ' + T('JsNextCourse') + ' : ' + esc(n.title), (hasQuiz(f) || hasChallenge(f)) ? '' : 'guide-cta-main', function () { GuideStart(n.id); }));
    });
  }

  /* ---- QCM ---- */

  window.GuideStartQuiz = function (fid) {
    clearHighlight(); clearTrigger();
    fetchFormation(fid, function (f) {
      if (!f || !hasQuiz(f)) { alert(T('JsNoQuiz')); return; }
      formation = f;
      state = { active: true, formation_id: fid, mode: 'quiz', qi: 0, qok: 0 };
      saveState();
      renderQuiz(); showPanel();
    });
  };

  // 'correct' is either one index (the original format) or an array of them
    // (several right answers). Both are still accepted.
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
      note.textContent = '☑ ' + T('JsMultipleAnswers');
      wrap.appendChild(note);
    }

    // Display order, shuffled when the course asks for it. Each button keeps its
    // original index, so the answer is checked against the right choice.
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
      wrap.appendChild(ctaBtn(T('JsConfirmAnswer') + ' ✓', 'guide-cta-main', function () {
        if (wrap._answered) return;
        var sel = [];
        var btns = wrap.querySelectorAll('.guide-quiz-choice');
        for (var k = 0; k < btns.length; k++) if (btns[k]._sel) sel.push(btns[k]._orig);
        if (!sel.length) return;   // never accept an empty answer
        answerQuiz(sel, wrap, q, correct);
      }));
    }

    setPanelView(q.q || '', wrap,
      T('Quiz') + ' — ' + T('JsQuestion') + ' ' + (i + 1) + ' / ' + qs.length);
    document.getElementById('guide-panel-progress-fill').style.width = Math.round(i / qs.length * 100) + '%';
  }

  function answerQuiz(selected, wrap, q, correct) {
    if (wrap._answered) return;
    wrap._answered = true;
    // Right answer means EXACTLY the set of correct choices: on a
    // multiple-answer question, picking one of two correct choices is wrong.
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
    fb.innerHTML = (ok ? '✅ ' + esc(T('JsRightAnswer')) : '❌ ' + esc(T('JsWrongAnswer')))
                 + (q.explain ? '<br>' + esc(q.explain) : '');
    wrap.appendChild(fb);
    var isLast = (state.qi || 0) >= formation.quiz.questions.length - 1;
    wrap.appendChild(ctaBtn((isLast ? T('JsSeeResult') : T('JsNextQuestion')) + ' ▶', 'guide-cta-main', function () {
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
      p.innerHTML = '✅ ' + T('JsQuizPassedScore') + score + '% (' + ok + '/' + total + ').';
      wrap.appendChild(p);
      var badge = document.createElement('p');
      badge.id = 'guide-done-badge';
      wrap.appendChild(badge);
      serverPost('activity', { formation_id: f.id, activity: 'quiz' }, function () { updateDoneBadge(f.id); });
      if (hasChallenge(f)) wrap.appendChild(ctaBtn('🎯 ' + T('JsTakeChallenge'), 'guide-cta-main', function () { GuideStartChallenge(f.id); }));
      var nh = document.createElement('div');
      wrap.appendChild(nh);
      fetchNextFormation(f.id, function (n) {
        if (n) nh.appendChild(ctaBtn('▶ ' + T('JsNextCourse') + ' : ' + esc(n.title), hasChallenge(f) ? '' : 'guide-cta-main', function () { GuideStart(n.id); }));
      });
      state.active = false; state.mode = null; saveState();
    } else {
      p.innerHTML = '❌ ' + esc(T('JsScoreFailed')
        .replace('{score}', score).replace('{ok}', ok).replace('{total}', total).replace('{pass}', pass));
      wrap.appendChild(p);
      wrap.appendChild(ctaBtn('↺ ' + T('JsRetry'), 'guide-cta-main', function () {
        state.qi = 0; state.qok = 0; saveState();
        renderQuiz();
      }));
    }
    wrap.appendChild(ctaBtn('🏠 ' + T('JsBackToCatalogue'), 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    setPanelView(passed ? '🎉 ' + T('JsQuizPassed') : T('JsQuizResult'), wrap, f.title + ' — ' + T('Quiz'));
    document.getElementById('guide-panel-progress-fill').style.width = '100%';
  }

  /* ---- Challenge ---- */

  var _defiTimer = null;

  window.GuideStartChallenge = function (fid) {
    clearHighlight(); clearTrigger();
    fetchFormation(fid, function (f) {
      if (!f || !hasChallenge(f)) { alert(T('JsNoChallenge')); return; }
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
    wrap.appendChild(ctaBtn('🔍 ' + T('JsCheckNow'), 'guide-cta-main', runDefiCheck));
    var hint = document.createElement('p');
    hint.className = 'guide-defi-hint';
    // Polled as well as offered as a button: the conditions become true through
    // work done in ianseo, not through anything happening on this panel.
    hint.textContent = T('JsAutoCheck');
    wrap.appendChild(hint);
    setPanelView('🎯 ' + T('Challenge'), wrap, formation.title + T('JsChallengeSuffix'));
    runDefiCheck();
    if (_defiTimer) clearInterval(_defiTimer);
    _defiTimer = setInterval(function () {
      if (!state || state.mode !== 'defi') { clearInterval(_defiTimer); _defiTimer = null; return; }
      runDefiCheck();
    }, 10000);
  }

  function runDefiCheck() {
    var rows = Array.prototype.slice.call(
      document.querySelectorAll('#guide-defi-conds .guide-defi-cond'));
    if (!rows.length) return;

    // One request for the whole challenge, not one per condition.
    checkConditions(rows.map(function (r) { return r.dataset.cid; }), function (res) {
      var allMet = true;
      rows.forEach(function (row) {
        var r   = res[row.dataset.cid] || { met: false, label: row.dataset.cid };
        var met = r.met === true;
        row.querySelector('.guide-defi-status').textContent = met ? '✅' : '❌';
        row.querySelector('.guide-defi-label').textContent  = r.label || row.dataset.cid;
        row.classList.toggle('met', met);
        if (!met) allMet = false;
      });
      if (allMet) defiSuccess();
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
    p.innerHTML = T('JsChallengeDone');
    wrap.appendChild(p);
    var badge = document.createElement('p');
    badge.id = 'guide-done-badge';
    wrap.appendChild(badge);
    var nh = document.createElement('div');
    wrap.appendChild(nh);
    wrap.appendChild(ctaBtn('🏠 ' + T('JsBackToCatalogue'), 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    setPanelView('🏆 ' + T('JsChallengePassed'), wrap, f.title + T('JsChallengeSuffix'));
    document.getElementById('guide-panel-progress-fill').style.width = '100%';
    fetchNextFormation(f.id, function (n) {
      if (n) nh.appendChild(ctaBtn('▶ ' + T('JsNextCourse') + ' : ' + esc(n.title), 'guide-cta-main', function () { GuideStart(n.id); }));
    });
  }

  /* ---- Outils : checklist & FAQ ---- */

  window.GuideStartTool = function (id) {
    clearHighlight(); clearTrigger();
    fetchFormation(id, function (f) {
      if (!f) { alert(T('JsContentNotFound')); return; }
      formation = f;
      if (f.type === 'checklist') {
        // Keep what was already ticked when the same checklist is reopened
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
    // Every item driven by a condition, gathered before the rows are built, so
    // the whole checklist costs one request instead of one per item.
    var pending = {};
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
      span.innerHTML = esc(it.label) + (it.page ? ' <a href="' + esc(buildUrl(it.page)) +
                     '" class="guide-ck-link" title="' + esc(T('JsGoToPage')) + '">↗</a>' : '');
      row.appendChild(span);
      if (cb.checked) row.classList.add('done');
      wrap.appendChild(row);
      // Items driven by a condition tick themselves. Updated in place rather than
      // re-rendered, so the boxes the user just ticked are not thrown away.
      if (it.condition && !cb.checked) {
        (pending[it.condition] = pending[it.condition] || []).push({ cb: cb, row: row, key: key });
      }
    });

    var conds = Object.keys(pending);
    if (conds.length) {
      checkConditions(conds, function (res) {
        var changed = false;
        conds.forEach(function (cid) {
          if (!res[cid] || res[cid].met !== true) return;
          pending[cid].forEach(function (p) {
            if (p.cb.checked) return;
            p.cb.checked = true;
            p.row.classList.add('done');
            if (!state.checked) state.checked = {};
            state.checked[p.key] = true;
            changed = true;
          });
        });
        if (changed) { saveState(); updateCkProgress(); }
      });
    }
    if (qs.length) {
      wrap.appendChild(ctaBtn('↺ ' + T('JsRedoQuestions'), 'guide-cta-ghost', function () {
        state.answers = null; state.tags = []; state.qidx = 0;
        saveState();
        renderChecklist();
      }));
    }
    setPanelView(t.title || T('JsChecklist'), wrap, '');
    updateCkProgress();
  }

  function updateCkProgress() {
    var rows  = document.querySelectorAll('#guide-panel-step-content .guide-ck-item');
    var done  = document.querySelectorAll('#guide-panel-step-content .guide-ck-item.done');
    var total = rows.length;
    document.getElementById('guide-panel-progress-text').textContent =
      T('JsChecklist') + ' — ' + done.length + ' / ' + total;
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
    setPanelView(q.q || '', wrap, T('JsQuestion') + ' ' + (i + 1) + ' / ' + qs.length);
  }

  function renderFaq() {
    var nodes = formation.nodes || {};
    var nid = state.node || 'start';
    var n = nodes[nid] || nodes.start;
    var wrap = document.createElement('div');
    if (!n) {
      wrap.textContent = T('JsFaqEmpty');
      setPanelView(formation.title || T('Troubleshooting'), wrap, '');
      return;
    }
    if (n.solution) {
      var d = document.createElement('div');
      d.className = 'guide-faq-sol';
      d.innerHTML = sanitizeContent(n.solution);
      wrap.appendChild(d);
      if (n.page)      wrap.appendChild(ctaBtn('📍 ' + T('JsGoToPage'), 'guide-cta-main', function () { window.location.href = buildUrl(n.page); }));
      if (n.formation) wrap.appendChild(ctaBtn('🎓 ' + T('JsStartLinkedCourse'), '', function () { GuideStart(n.formation); }));
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
      wrap.appendChild(ctaBtn('↶ ' + T('JsBack'), 'guide-cta-ghost', function () {
        var h = state.hist || [];
        state.node = h.pop() || 'start';
        state.hist = h;
        saveState();
        renderFaq();
      }));
    }
    if (nid !== 'start') {
      wrap.appendChild(ctaBtn('⟲ ' + T('JsRestart'), 'guide-cta-ghost', function () {
        state.node = 'start'; state.hist = [];
        saveState();
        renderFaq();
      }));
    }
    setPanelView(formation.title || T('Troubleshooting'), wrap, T('Troubleshooting'));
  }

  /* ---- Contextual help ---- */

  var _ctxItems = [];

  // With an account the preference comes from the server (menu.php publishes it
  // as window.GUIDE_CTX on every page), so it follows the user from one machine
  // to another. Without one, localStorage is all there is.
  function ctxEnabled() {
    if (GUSER && typeof window.GUIDE_CTX !== 'undefined' && window.GUIDE_CTX !== null) return window.GUIDE_CTX != 0;
    return localStorage.getItem(LS_CTX) !== '0';
  }

  function setCtxPref(on) {
    if (GUSER) {
      window.GUIDE_CTX = on ? 1 : 0;
      serverPost('pref', { ctx_help: on ? 1 : 0 });
    } else {
      localStorage.setItem(LS_CTX, on ? '1' : '0');
    }
  }

  function maybeContextHelp() {
    if (state && state.active) return;
    if (!ctxEnabled()) { showFabIfNeeded(); return; }
    var url = apiRoot() + 'Modules/Custom/GUIDE/guide-api.php?action=context&path=' + encodeURIComponent(window.location.pathname);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      // The API answers in the ianseo envelope: error / msg, then the payload.
      try { _ctxItems = (JSON.parse(xhr.responseText) || {}).items || []; } catch (e) { _ctxItems = []; }
      showFabIfNeeded();
    };
    xhr.send();
  }

  function renderContextPanel() {
    formation = null;
    var wrap = document.createElement('div');
    var p = document.createElement('p');
    p.textContent = T('JsCtxIntro');
    wrap.appendChild(p);
    var icons = { formation: '🎓', checklist: '🧰', faq: '🛟' };
    _ctxItems.forEach(function (it) {
      wrap.appendChild(ctaBtn((icons[it.type] || '📄') + ' ' + esc(it.title), 'guide-cta-main', function () {
        if (it.type === 'formation') GuideStart(it.id);
        else GuideStartTool(it.id);
      }));
    });
    wrap.appendChild(ctaBtn('🏠 ' + T('JsWholeCatalogue'), 'guide-cta-ghost', function () { window.location.href = buildUrl('/Modules/Custom/GUIDE/'); }));
    var off = document.createElement('p');
    off.innerHTML = '<a href="#" style="font-size:11px;color:#999">' + esc(T('JsDisableCtx')) + '</a>';
    off.querySelector('a').addEventListener('click', function (e) {
      e.preventDefault();
      setCtxPref(false);
      _ctxItems = [];
      hidePanel();
    });
    wrap.appendChild(off);
    setPanelView('💡 ' + T('JsContextHelp'), wrap, '');
  }

  /* ===== Getting the guide into ianseo popups (PopEdit.php and friends) =====
     Popups use head-popup.php, which does not call get_which_menu(), so the
     server never injects the panel into them. window.open is wrapped in the
     parent instead, and once the popup has loaded — same origin — the CSS, a
     copy of the panel and recorder markup, and an instance of this script are
     inserted into it. That instance initialises itself, because the popup's DOM
     is already parsed by the time it arrives. */

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
        doc = win.document;                                   // throws if cross-origin
        if (!doc || doc.readyState !== 'complete' || !doc.body) return;
      } catch (e) { clearInterval(iv); return; }              // autre origine → on abandonne
      if (!shouldGuidePopup()) return;                         // nothing to show
      if (!doc.getElementById('guide-panel')) injectGuide(win);// (re)inject after a load or reload
    }, 750);
  }

  // Reuse the URL, cache-busting suffix and all, of an asset the parent window
  // has already loaded.
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
      sVar.textContent = 'var WebDir=' + JSON.stringify(root) + ';'
        + 'window.GUIDE_USER=' + JSON.stringify(GUSER) + ';'
        + 'window.GUIDE_CTX=' + JSON.stringify(typeof window.GUIDE_CTX === 'undefined' ? null : window.GUIDE_CTX) + ';';
      head.appendChild(sVar);

      var link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = guideAssetUrl(/Modules\/Custom\/GUIDE\/assets\/guide\.css/, root + 'Modules/Custom/GUIDE/assets/guide.css');
      head.appendChild(link);

      // Copy the panel, the floating button and the recorder from the parent, with
    // none of its display state carried over.
      ['guide-panel', 'guide-fab', 'guide-rec'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        var imported = doc.importNode(el, true);
        imported.style.display = 'none';
        if (imported.classList) imported.classList.remove('guide-panel-wide', 'guide-panel-left', 'guide-fab-left');
        doc.body.appendChild(imported);
      });

      // An instance of this script inside the popup. It initialises itself, since
    // the DOM is already parsed by the time the script is inserted.
      var sc = doc.createElement('script');
      sc.src = guideAssetUrl(/Modules\/Custom\/GUIDE\/assets\/guide\.js/, root + 'Modules/Custom/GUIDE/assets/guide.js');
      doc.body.appendChild(sc);
    } catch (e) { /* popup closed, or cross-origin */ }
  }

  /* ===== Enregistreur de triggers ===== */

  var LS_REC = 'guide_rec';
  var _rec = null;
  var _recClickOff = null;

  function recLoad() { try { return JSON.parse(localStorage.getItem(LS_REC)); } catch (e) { return null; } }
  function recSave() { localStorage.setItem(LS_REC, JSON.stringify(_rec)); }
  function recClearStorage() { localStorage.removeItem(LS_REC); }
  function recActive() { var r = recLoad(); return !!(r && r.active); }
  // Re-read _rec from localStorage before touching it: the parent window and the
  // popup share one recording, and without this each would overwrite the
  // triggers the other has just added.
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
      // Do not record on the module's own pages: the author clicking through the
      // editor is not describing a course.
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
      // The click is NOT prevented: the author has to be able to navigate and
      // interact normally while recording.
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
    if (!confirm(T('JsAbortRecording'))) return;
    var url = _rec.return_url;
    recClearStorage();
    window.location.href = url || (apiRoot() + 'Modules/Custom/GUIDE/admin/');
  }

  function recRenderList() {
    var n = _rec.triggers.length;
    var title = document.getElementById('guide-rec-title');
    if (title) title.textContent = T('RecTitle') + ' (' + n + ')';
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

  /* Build a reasonably robust CSS selector for an element. */
  function cssEsc(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/([^a-zA-Z0-9_-])/g, '\\$1');
  }
  // A numbered id such as "d_q_QuSession_25360" carries a database record
  // number, so the exact #id would stop matching the moment the participant or
  // the competition changes. A prefix selector, [id^="d_q_QuSession_"], is
  // stable and matches every equivalent element.
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
