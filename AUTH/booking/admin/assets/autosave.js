/* Autosave — the "Save" button disappears when scripts run, and each change is written as it
   goes. Choice: the WHOLE form goes back to the same entry point (same validation, same
   server-side normalisation) rather than one API per field; only the answer changes (JSON
   instead of the page). Without scripts, the button stays. */
(function () {
  var forms = [].slice.call(document.querySelectorAll('form[data-autosave]'));
  if (!forms.length || !window.fetch || !window.FormData) return;

  var pill = document.getElementById('bk-pill');
  var timer = null, busy = false, again = null, dirty = false;

  function state(cls, txt) { if (!pill) return; pill.className = cls; pill.textContent = txt; pill.hidden = false; }
  function clock() { return new Date().toLocaleTimeString(BK_T.lang, { hour: '2-digit', minute: '2-digit' }); }

  function send(form) {
    if (busy) { again = form; return; }      // one write at a time, the last one replayed
    busy = true;
    state('wait', BK_T.saving);
    var fd = new FormData(form);
    fd.append('ajax', '1');
    fetch(window.location.href, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (j && j.error === 0) { dirty = false; state('ok', BK_T.savedAt.split('{$a}').join(clock())); }
        else state('err', '⚠ ' + ((j && j.msg) || BK_T.refused));
      })
      .catch(function () { state('err', '⚠ ' + BK_T.offline); })
      .then(function () {
        busy = false;
        if (again) { var f = again; again = null; send(f); }
      });
  }

  function schedule(form, delay) {
    dirty = true;
    clearTimeout(timer);
    timer = setTimeout(function () { send(form); }, delay);
  }

  forms.forEach(function (form) {
    // The tariff preview is built on fields WITHOUT a name (sim-*): touching them changes
    // nothing to save, no write needed.
    function relevant(e) { return e.target && e.target.name && !e.target.disabled; }
    // Typing: let the typing end. Box/list/date: at once.
    form.addEventListener('input',  function (e) { if (relevant(e)) schedule(form, 900); });
    form.addEventListener('change', function (e) { if (relevant(e)) schedule(form, 150); });
    form.addEventListener('submit', function (e) { e.preventDefault(); schedule(form, 0); });
    form.querySelectorAll('[data-manual-save]').forEach(function (b) { b.hidden = true; });
    // After the form, not inside: the fee one is a flex row.
    var note = document.createElement('p');
    note.className = 'bk-auto-note';
    note.textContent = BK_T.auto;
    if (form.parentNode) form.parentNode.insertBefore(note, form.nextSibling);
  });

  // Deleting a tariff rule removes fields without firing an event. (The deletion itself
  // happens in the other listener, synchronously: the delay below makes the send go AFTER.)
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.bk-cat-del')) {
      var f = document.getElementById('bk-cfg');
      if (f) schedule(f, 200);
    }
  });

  // Leaving the page with a change not written yet: warn.
  window.addEventListener('beforeunload', function (e) {
    if (dirty || busy) { e.preventDefault(); e.returnValue = ''; }
  });
})();
