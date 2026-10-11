/* Organiser pages of AUTH — shared behaviour of the frame (adm-lib.php): #shpadm, or #autadm for
 * the accounts of the server, which keep the core's look.
 *
 * Navigation .sa-fit (accounts of the server): kept on one line, see below.
 *
 * Save button ([data-sa-save], fixed at the bottom right): marked as soon as its form changes,
 * and the browser asks before leaving the page with changes not saved. When the GUIDE module's
 * panel (or its reduced button, or its recorder) sits in the same corner, the save button moves
 * to its left — checked every second, the guide opens and closes by itself.
 *
 * Messages [data-sa-toast]: shown floating at the top right, then hidden after 6 seconds.
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
            var mark = function (e) {
                if (e && e.target && e.target.hasAttribute && e.target.hasAttribute('data-sa-nodirty')) return;
                dirty = true;
                save.classList.add('sa-dirty');
            };
            form.addEventListener('input', mark);
            form.addEventListener('change', mark);
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

    /* ---- Floating messages ---- */
    Array.prototype.forEach.call(frame.querySelectorAll('[data-sa-toast]'), function (m) {
        m.classList.add('sa-toast');
        setTimeout(function () { m.remove(); }, 6000);
    });
})();
