/* Archer statistics: tooltips of the score chart (lib/stats.php, bk_stats_chart).
   The pointer snaps to the nearest round by date; touch and keyboard focus show the same.
   Texts come from the page (competition names): written with textContent only. */
(function () {
    'use strict';
    [].forEach.call(document.querySelectorAll('.bk-chart'), function (fig) {
        var pts;
        try { pts = JSON.parse(fig.getAttribute('data-pts') || '[]'); } catch (e) { pts = []; }
        var svg = fig.querySelector('svg'), tip = fig.querySelector('.bk-chart-tip');
        if (!svg || !tip || !pts.length) return;
        var cross = svg.querySelector('.cross'), dots = svg.querySelectorAll('.pt');
        var vb = svg.viewBox.baseVal, cur = -1;

        function line(cls, text) {
            if (!text) return;
            var d = document.createElement('div');
            d.className = cls;
            d.textContent = text;
            tip.appendChild(d);
        }

        function show(i) {
            if (i === cur) return;
            cur = i;
            var p = pts[i];
            while (tip.firstChild) tip.removeChild(tip.firstChild);
            line('v', String(p.v));
            line('n', p.n);
            line('m', p.w);
            line('m', p.d);
            line('m', p.k);
            [].forEach.call(dots, function (d) { d.classList.toggle('on', +d.getAttribute('data-i') === i); });
            cross.setAttribute('x1', p.x);
            cross.setAttribute('x2', p.x);
            cross.classList.add('on');
            tip.hidden = false;
            var box = svg.getBoundingClientRect(), fb = fig.getBoundingClientRect();
            var px = box.left - fb.left + p.x / vb.width * box.width;
            var py = box.top - fb.top + p.y / vb.height * box.height;
            var w = tip.offsetWidth, h = tip.offsetHeight;
            var left = Math.max(0, Math.min(fb.width - w, px - w / 2));
            var top = py - h - 14;
            if (top < 0) top = py + 16;
            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
        }

        function hide() {
            cur = -1;
            tip.hidden = true;
            cross.classList.remove('on');
            [].forEach.call(dots, function (d) { d.classList.remove('on'); });
        }

        function nearest(ev) {
            var box = svg.getBoundingClientRect();
            var vx = (ev.clientX - box.left) / box.width * vb.width, best = 0;
            for (var i = 1; i < pts.length; i++) if (Math.abs(pts[i].x - vx) < Math.abs(pts[best].x - vx)) best = i;
            show(best);
        }

        svg.addEventListener('pointermove', nearest);
        svg.addEventListener('pointerdown', nearest);
        svg.addEventListener('pointerleave', function (ev) { if (ev.pointerType === 'mouse') hide(); });
        [].forEach.call(svg.querySelectorAll('.hit'), function (h) {
            h.addEventListener('focus', function () { show(+h.getAttribute('data-i')); });
            h.addEventListener('blur', hide);
        });
        document.addEventListener('pointerdown', function (ev) { if (!fig.contains(ev.target)) hide(); });
    });
})();
