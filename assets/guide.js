/* Guide FFTA — moteur de tutoriel v3 */
(function () {
  'use strict';

  var LS_STATE = 'guide_state';
  var LS_DONE  = 'guide_completed';
  var LS_SIDE  = 'guide_panel_side';

  var state       = null;
  var formation   = null;
  var panel, fab;
  var _triggerOff      = null; // cleanup du listener actif
  var _syncTimer       = null; // debounce sync serveur
  var _triggerIdx      = 0;   // index du trigger courant dans l'étape
  var _doneTriggerMask = {};  // { index: true } des triggers déjà déclenchés

  /* ===== Init ===== */

  document.addEventListener('DOMContentLoaded', function () {
    panel = document.getElementById('guide-panel');
    fab   = document.getElementById('guide-fab');
    if (!panel) return;

    document.getElementById('guide-panel-close').addEventListener('click', hidePanel);
    document.getElementById('guide-panel-toggle-side').addEventListener('click', togglePanelSide);
    document.getElementById('guide-btn-prev').addEventListener('click', prevStep);
    document.getElementById('guide-btn-next').addEventListener('click', nextStep);
    document.getElementById('guide-btn-stop').addEventListener('click', stopFormation);
    document.getElementById('guide-btn-validate').addEventListener('click', toggleValidate);
    fab.addEventListener('click', onFabClick);

    applyPanelSide(loadSide());

    state = loadState();

    // Sync état local → serveur au chargement (rattrape les navigations interrompues)
    if (state && state.active && state.gp_id) {
      serverPost('update', {
        gp_id: state.gp_id, step: state.step_index || 0,
        status: 'en_cours', validated: state.validated || {}
      }, null);
    }

    if (state && state.active && state.formation_id) {
      fetchFormation(state.formation_id, function (f) {
        formation = f;
        if (!formation) { resetState(); showFabIfNeeded(); return; }
        if (state.step_index >= formation.steps.length) state.step_index = 0;
        renderStep();
        showPanel();
      });
    } else {
      showFabIfNeeded();
    }
  });

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

  /* ===== Navigation ===== */

  function prevStep() {
    if (!state || state.step_index <= 0) return;
    clearHighlight(); clearTrigger();
    state.step_index--;
    state.trigger_index = 0;
    saveState(); scheduleSync(); renderStep();
  }

  function nextStep() {
    if (!formation || !state) return;
    if (!isStepDone(formation.steps[state.step_index])) return;
    if (state.step_index >= formation.steps.length - 1) { completeFormation(); return; }
    clearHighlight(); clearTrigger();
    state.step_index++;
    state.trigger_index = 0;
    saveState(); scheduleSync(); renderStep();
  }

  function completeFormation() {
    if (!state) return;
    var done = loadCompleted();
    if (done.indexOf(state.formation_id) === -1) {
      done.push(state.formation_id);
      localStorage.setItem(LS_DONE, JSON.stringify(done));
    }
    clearHighlight(); clearTrigger();
    state.active = false;
    saveState();
    serverPost('update', {
      gp_id: state.gp_id, step: state.step_index || 0,
      status: 'termine', validated: state.validated || {}
    }, null);
    formation = null;
    hidePanel(); showFabIfNeeded();
    alert('Félicitations ! Formation terminée.\nVous pouvez en démarrer une autre depuis le catalogue.');
  }

  function stopFormation() {
    if (!confirm('Quitter la formation ?\nVotre progression est sauvegardée — vous pourrez reprendre ici.')) return;
    clearHighlight(); clearTrigger(); clearTourWarning();
    if (state && state.gp_id) {
      serverPost('update', {
        gp_id: state.gp_id, step: state.step_index || 0,
        status: 'en_cours', validated: state.validated || {}
      }, null);
    }
    resetState(); formation = null;
    hidePanel(); showFabIfNeeded();
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
    var triggers = step.triggers || [];
    // Passer les sous-étapes sans action et non obligatoires
    while (_triggerIdx < triggers.length) {
      var t = triggers[_triggerIdx];
      var isAction = !t.kind || t.kind === 'action';
      if (isAction ? (t.trigger || t.required) : t.required) break;
      _doneTriggerMask[_triggerIdx] = true;
      _triggerIdx++;
    }
    if (_triggerIdx >= triggers.length) {
      clearHighlight();
      if (allRequiredDone(step)) autoValidateStep(step);
      return;
    }

    var t = triggers[_triggerIdx];

    // ---- Trigger état (vérification serveur) ----
    if (t.kind === 'etat') {
      clearHighlight();
      checkCondition(t.condition || '', function (met, label) {
        if (met) {
          onTriggerFired(step);
        } else {
          showConditionWait(label);
        }
      });
      return;
    }

    // ---- Trigger action (événement DOM) ----
    var tPage = triggerPage(step, t);
    clearHighlight();
    if (t.selector && isOnRightPage(tPage)) highlight(t.selector, t.hint || '');
    if (step.strict_click && t.selector && isOnRightPage(tPage)) enableStrictClick(t.selector);

    // Trigger manuel (trigger: null, required: true) → l'utilisateur clique "Marquer comme fait"
    if (!t.trigger || !t.selector) return;

    if (!isOnRightPage(tPage)) return;

    var el = document.querySelector(t.selector);
    if (!el) return;

    // Pré-validation
    if (t.trigger === 'change') {
      var alreadyDone = false;
      if (el.type === 'checkbox') alreadyDone = el.checked;
      else if (el.type !== 'file') alreadyDone = !!(el.value && el.value !== '' && el.value !== '0' && el.value !== '-1');
      if (alreadyDone) { onTriggerFired(step); return; }
    }

    function onEvent() {
      if (el.type === 'checkbox' && !el.checked) return;
      el.removeEventListener(t.trigger, onEvent);
      _triggerOff = null;
      onTriggerFired(step);
    }
    el.addEventListener(t.trigger, onEvent);
    _triggerOff = function () { el.removeEventListener(t.trigger, onEvent); };
  }

  function onTriggerFired(step) {
    var triggers = step.triggers || [];
    _doneTriggerMask[_triggerIdx] = true;
    _triggerIdx++;
    while (_triggerIdx < triggers.length && _doneTriggerMask[_triggerIdx]) _triggerIdx++;
    state.trigger_index = _triggerIdx;
    saveState();
    attachCurrentTrigger(step);
    updatePageInfo(step);
    if (allRequiredDone(step)) autoValidateStep(step);
  }

  function allRequiredDone(step) {
    return (step.triggers || []).every(function (t, i) {
      return !t.required || !!_doneTriggerMask[i];
    });
  }

  function clearTrigger() {
    if (_triggerOff) { _triggerOff(); _triggerOff = null; }
    disableStrictClick();
  }

  function autoValidateStep(step) {
    if (!state) return;
    if (!state.validated) state.validated = {};
    state.validated[step.id] = true;
    saveState(); scheduleSync();
    renderValidateBtn(); updateNextBtn();
  }

  /* ===== Rendu d'une étape ===== */

  function renderStep() {
    if (!formation || !state) return;
    var step  = formation.steps[state.step_index];
    var total = formation.steps.length;
    var idx   = state.step_index;
    var pct   = total > 1 ? Math.round(idx / (total - 1) * 100) : 100;

    document.getElementById('guide-panel-formation-name').textContent = formation.title;
    document.getElementById('guide-panel-progress-fill').style.width  = pct + '%';
    document.getElementById('guide-panel-progress-text').textContent  = 'Étape ' + (idx + 1) + ' / ' + total;
    document.getElementById('guide-panel-step-title').textContent     = step.title;
    document.getElementById('guide-panel-step-content').innerHTML     = sanitizeContent(step.content);

    document.getElementById('guide-btn-prev').disabled = (idx === 0);
    _doneTriggerMask = {};
    _triggerIdx = state.trigger_index || 0;
    for (var i = 0; i < _triggerIdx; i++) _doneTriggerMask[i] = true;
    updatePageInfo(step);
    renderValidateBtn();
    updateNextBtn();
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

  function revalidateEtat(step, etatTriggers) {
    var remaining = etatTriggers.length;
    var allMet    = true;
    etatTriggers.forEach(function (t) {
      checkCondition(t.condition || '', function (met) {
        if (!met) allMet = false;
        if (--remaining === 0) {
          if (allMet) {
            clearHighlight(); clearTrigger();
          } else {
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

  function enableStrictClick(selector) {
    disableStrictClick();
    var handler = function (e) {
      var panel = document.getElementById('guide-panel');
      if (panel && panel.contains(e.target)) return;
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

  function highlight(selector, hint) {
    var el = document.querySelector(selector);
    if (!el) return;
    _highlighted = el;
    _hint = hint || '';
    el.classList.add('guide-highlight');
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    placeArrow(el);
    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onResize);
    document.addEventListener('scroll', onResize, true);
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
  }

  function removeArrow() {
    if (_arrow) { _arrow.parentNode && _arrow.parentNode.removeChild(_arrow); _arrow = null; }
  }

  function onResize() { if (_highlighted) placeArrow(_highlighted); }

  function clearHighlight() {
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
  function hidePanel()  { panel.style.display = 'none'; clearHighlight(); showFabIfNeeded(); }

  function showFabIfNeeded() {
    if (!fab) return;
    fab.style.display = (state && state.active) ? 'flex' : 'none';
  }

  function onFabClick() {
    if (state && state.active && formation) {
      renderStep(); showPanel();
    } else if (state && state.active && state.formation_id) {
      fetchFormation(state.formation_id, function (f) {
        formation = f;
        if (formation) { renderStep(); showPanel(); }
      });
    } else {
      window.location.href = buildUrl('/Modules/Custom/GUIDE/');
    }
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
    if (!page) return true;
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

})();
