/* Organiser pages of AUTH — shared behaviour of the frame (adm-lib.php): #shpadm, or #autadm for
 * the accounts of the server, which keep the core's look.
 *
 * Navigation .sa-fit (accounts of the server): kept on one line, see below.
 *
 * Save button ([data-sa-save], fixed at the bottom right): marked while its form differs from
 * what the page showed — compared, not just touched: a choice made and undone (a level picked,
 * then the first one again) is no change —, and the browser asks before leaving the page with
 * changes not saved. When the GUIDE module's
 * panel (or its reduced button, or its recorder) sits in the same corner, the save button moves
 * to its left — checked every second, the guide opens and closes by itself.
 *
 * Messages [data-sa-toast]: shown floating at the top right, then hidden after 6 seconds.
 *
 * Explanations (.sa-q, adm-lib.php aut_adm_help): a click unfolds the text, a second folds it.
 *
 * Tabs (.sa-tabset, aut_adm_tabs): display only; the tab shown is kept for the visit.
 */
(function () {
    'use strict';
    var frame = document.getElementById('shpadm') || document.getElementById('autadm');
    if (!frame) return;

    /* ---- Navigation on one line (.sa-fit): the font of the ianseo menus, smaller as long as the
       tabs do not fit; below 9 px they wrap rather than become unreadable. ---- */
    var fit = frame.querySelector('.sa-nav.sa-fit');
    if (fit) {
        var fitNav = function () {
            fit.style.fontSize = '';
            fit.classList.remove('sa-wrap');
            var size = parseFloat(window.getComputedStyle(fit).fontSize);
            while (fit.scrollWidth > fit.clientWidth + 1 && size > 9) {
                size -= 0.5;
                fit.style.fontSize = size + 'px';
            }
            if (fit.scrollWidth > fit.clientWidth + 1) fit.classList.add('sa-wrap');
        };
        fitNav();
        var fitTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(fitTimer);
            fitTimer = setTimeout(fitNav, 100);
        });
    }

    /* ---- Save button ---- */
    var save = frame.querySelector('[data-sa-save]');
    if (save) {
        frame.classList.add('sa-has-save');
        var form = save.form || document.getElementById(save.getAttribute('form'));
        var dirty = false;
        if (form) {
            // What the form would send (fields marked data-sa-nodirty left out).
            var state = function () {
                var out = [];
                Array.prototype.forEach.call(form.elements, function (el) {
                    if (!el.name || el.disabled || el.hasAttribute('data-sa-nodirty')) return;
                    var t = (el.type || '').toLowerCase();
                    if (t === 'submit' || t === 'button' || t === 'file') return;
                    if ((t === 'checkbox' || t === 'radio') && !el.checked) return;
                    if (t === 'select-multiple') {
                        Array.prototype.forEach.call(el.options, function (o) { if (o.selected) out.push(el.name + '=' + o.value); });
                        return;
                    }
                    out.push(el.name + '=' + el.value);
                });
                return out.join('\n');
            };
            var initial = null;
            // Taken once every script of the page has set its fields up (deferred scripts run
            // before DOMContentLoaded).
            var start = function () { initial = state(); };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
            else start();
            var mark = function () {
                if (initial === null) return;
                dirty = state() !== initial;
                save.classList.toggle('sa-dirty', dirty);
            };
            form.addEventListener('input', mark);
            form.addEventListener('change', mark);
            // Lines added or removed by a page script (catalogue, points of sale).
            if (window.MutationObserver) new MutationObserver(mark).observe(form, { childList: true, subtree: true });
            form.addEventListener('submit', function () { dirty = false; });
        }
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
        var GUIDE = ['guide-panel', 'guide-fab', 'guide-rec'];
        var place = function () {
            var right = 20;
            GUIDE.forEach(function (id) {
                var g = document.getElementById(id);
                if (!g) return;
                var st = window.getComputedStyle(g);
                if (st.display === 'none' || st.visibility === 'hidden') return;
                var r = g.getBoundingClientRect();
                if (!r.width || !r.height) return;
                // In the bottom right corner: the button goes to its left.
                if (r.bottom > window.innerHeight - 90 && r.right > window.innerWidth - 260) {
                    right = Math.max(right, window.innerWidth - r.left + 12);
                }
            });
            save.style.right = right + 'px';
        };
        place();
        window.addEventListener('resize', place);
        setInterval(place, 1000);
    }

    /* ---- Tabs (aut_adm_tabs): the one shown is kept for the visit ---- */
    var tabKey = function (set) { return 'sa-tab-' + location.pathname + '-' + set.getAttribute('data-tabset'); };
    var showTab = function (set, key) {
        var found = false;
        Array.prototype.forEach.call(set.querySelectorAll(':scope > .sa-tabbar > .sa-tab'), function (t) {
            var on = t.getAttribute('data-tab') === key;
            found = found || on;
            t.classList.toggle('on', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (!found) return;
        Array.prototype.forEach.call(set.querySelectorAll(':scope > .sa-tabpanel'), function (p) {
            p.hidden = p.getAttribute('data-tabpanel') !== key;
        });
    };
    Array.prototype.forEach.call(frame.querySelectorAll('.sa-tabset'), function (set) {
        var kept = null;
        try { kept = sessionStorage.getItem(tabKey(set)); } catch (e) { /* private window */ }
        if (kept) showTab(set, kept);
        set.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('.sa-tab') : null;
            if (!t || t.parentNode.parentNode !== set) return;
            showTab(set, t.getAttribute('data-tab'));
            try { sessionStorage.setItem(tabKey(set), t.getAttribute('data-tab')); } catch (err) { /* private window */ }
        });
    });

    /* ---- Explanations on demand ---- */
    frame.addEventListener('click', function (e) {
        var q = e.target.closest ? e.target.closest('.sa-q') : null;
        if (!q) return;
        e.preventDefault();   // inside a label, the click must not tick or choose anything
        var h = document.getElementById(q.getAttribute('aria-controls'));
        if (!h) return;
        h.hidden = !h.hidden;
        q.setAttribute('aria-expanded', h.hidden ? 'false' : 'true');
    });

    /* ---- Floating messages ---- */
    Array.prototype.forEach.call(frame.querySelectorAll('[data-sa-toast]'), function (m) {
        m.classList.add('sa-toast');
        setTimeout(function () { m.remove(); }, 6000);
    });
})();
