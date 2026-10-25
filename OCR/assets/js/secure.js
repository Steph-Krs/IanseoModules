/**
 * The live camera needs a secure context: https, or the computer itself. A local
 * ianseo is served over plain http, and the firewall rule its installer makes opens
 * the http ports only, so the other computers and phones cannot reach it over https.
 *
 * Without changing the server, Chrome (and the browsers built on it) can be told, on
 * each device, once, to treat this server's address as secure: this explains how,
 * with the exact address to paste. The page says what works without it.
 */
(function (root) {
    'use strict';

    var FLAG = 'chrome://flags/#unsafely-treat-insecure-origin-as-secure';

    /** Copy a text from a read-only field: the clipboard API itself needs https. */
    function copyButton(field, t) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ocr-btn';
        b.textContent = t('InsecureCopy');
        b.addEventListener('click', function () {
            field.focus();
            field.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            b.textContent = ok ? t('InsecureCopied') : t('InsecureCopy');
        });
        return b;
    }

    function field(value) {
        var f = document.createElement('input');
        f.type = 'text';
        f.readOnly = true;
        f.value = value;
        f.className = 'ocr-copy';
        f.addEventListener('focus', function () { f.select(); });
        return f;
    }

    /**
     * Fill a message element with the explanation, and show it.
     *
     * @param {HTMLElement} box
     * @param {Function} t Translation function of the page.
     * @param {string} otherKey Key of the text saying what works without the setting.
     * @return {boolean} True when the page is not secure (and the box was filled).
     */
    function explain(box, t, otherKey) {
        if (window.isSecureContext || !box) return false;
        box.innerHTML = '';
        var p = document.createElement('p');
        p.textContent = t('InsecureLead');
        box.appendChild(p);
        var h = document.createElement('p');
        h.className = 'ocr-strong';
        h.textContent = t('InsecureChrome');
        box.appendChild(h);
        var ol = document.createElement('ol');
        [['InsecureStep1', FLAG], ['InsecureStep2', location.origin], ['InsecureStep3', null]].forEach(function (s) {
            var li = document.createElement('li');
            var span = document.createElement('span');
            span.textContent = t(s[0]);
            li.appendChild(span);
            if (s[1]) {
                var row = document.createElement('div');
                row.className = 'ocr-copy-row';
                var f = field(s[1]);
                row.appendChild(f);
                row.appendChild(copyButton(f, t));
                li.appendChild(row);
            }
            ol.appendChild(li);
        });
        box.appendChild(ol);
        if (otherKey) {
            var q = document.createElement('p');
            q.textContent = t(otherKey);
            box.appendChild(q);
        }
        box.hidden = false;
        return true;
    }

    root.OcrSecure = {explain: explain};
})(typeof self !== 'undefined' ? self : this);
