/* Points of sale — waiting screen of a volunteer's join request (staff/join.php).
   Asks the server every 3 seconds whether the organiser approved; opens the till by itself
   once approved, says so when refused or expired. Texts come from the page (#shp-texts). */
(function () {
    'use strict';
    var Shp = window.Shp;
    var status = document.getElementById('sj-status');
    if (!Shp || !status || !Shp.cfg.status) return;

    function show(text, cls) {
        status.textContent = text;
        status.className = cls || 'shp-muted';
    }

    function startAgain() {
        var p = document.createElement('p');
        var a = document.createElement('a');
        a.className = 'shp-btn shp-btn-block';
        a.href = window.location.pathname;
        a.textContent = Shp.t('ShStfStartAgain');
        p.appendChild(a);
        status.parentNode.appendChild(p);
    }

    var poller = Shp.poll(function () {
        return Shp.api(Shp.cfg.status, null).then(function (res) {
            var st = res && res.state;
            if (st === 'ok') {
                poller.stop();
                show(Shp.t('ShStfApproved'), 'shp-msg shp-msg-ok');
                Shp.vibrate([150]);
                window.location.replace(res.next || Shp.cfg.till);
            } else if (st === 'pending') {
                show(Shp.t('ShStfWaitHint'));
            } else if (st === 'refused' || st === 'revoked') {
                poller.stop();
                show(Shp.t(st === 'refused' ? 'ShStfErrRefused' : 'ShStfErrRevoked'), 'shp-msg shp-msg-err');
            } else if (st) {
                poller.stop();
                show(res.msg || Shp.t('ShStfErrExpired'), 'shp-msg shp-msg-warn');
                startAgain();
            }
        });
    }, 3000, 10000);
})();
