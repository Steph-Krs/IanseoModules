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

  document.addEventListener('DOMContentLoaded', function () {
    panel = document.getElementById('guide-panel');
    fab   = document.getElementById('guide-fab');
    if (!panel) return;

    document.getElementById('guide-panel-min').addEventListener('click', hidePanel);
    document.getElementById('guide-panel-max').addEventListener('click', togglePanelWide);
    document.getElementById('guide-panel-close').addEventListener('click', stopFormation);
    document.getElementById('guide-panel-toggle-side').addEventListener('click', togglePanelSide);
    document.getElementById('guide-btn-prev').addEventListener('click', prevStep);
    document.getElementById('guide-btn-next').addEventListener('click', nextStep);
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
    } else {
      checkCondition(t.condition || '', cb);
    }
  }

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
  }

  function removeArrow() {
    if (_arrow) { _arrow.parentNode && _arrow.parentNode.removeChild(_arrow); _arrow = null; }
  }

  function onResize() { if (_highlighted) placeArrow(_highlighted); }

  function clearHighlight() {
    stopVisibilityWatch();
    _curSelector = null;
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

  /* ===== Enregistreur de triggers ===== */

  var LS_REC = 'guide_rec';
  var _rec = null;
  var _recClickOff = null;

  function recLoad() { try { return JSON.parse(localStorage.getItem(LS_REC)); } catch (e) { return null; } }
  function recSave() { localStorage.setItem(LS_REC, JSON.stringify(_rec)); }
  function recClearStorage() { localStorage.removeItem(LS_REC); }
  function recActive() { var r = recLoad(); return !!(r && r.active); }

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
    _rec.triggers.push({ kind: 'etat', condition: '__page', page: currentPagePath(), required: true });
    recSave(); recRenderList();
  }
  function recUndo() {
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
  function buildSelector(el) {
    if (!el || el.nodeType !== 1) return null;
    if (el.id) {
      var idSel = '#' + cssEsc(el.id);
      try { if (document.querySelectorAll(idSel).length === 1) return idSel; } catch (e) {}
    }
    var parts = [];
    var node = el, depth = 0;
    while (node && node.nodeType === 1 && node !== document.body && depth < 6) {
      if (node.id) {
        var s = '#' + cssEsc(node.id);
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
