/* Shared helpers of the live draw module's pages: translation lookup, HTML
   escaping, the JSON calls to the module's endpoints, and a small toast for
   short confirmations. Loaded before the page's own script; defines globals
   prefixed TIR so nothing collides with ianseo's own scripts. */

(function () {
    'use strict';

    var strings = window.TIR_T || {};

    /* Translated text; {$a} or {$a[name]} are replaced from the second argument. */
    function T(key, a) {
        var s = Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key;
        if (a === undefined || a === null) return s;
        if (typeof a !== 'object') return s.split('{$a}').join(String(a));
        Object.keys(a).forEach(function (k) { s = s.split('{$a[' + k + ']}').join(String(a[k])); });
        return s;
    }

    function esc(s) {
        return String(s === undefined || s === null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* POST an action to a JSON endpoint. Resolves with the decoded answer when
       error is 0, rejects with the message otherwise. */
    function call(url, action, params, csrf) {
        var body = params instanceof FormData ? params : new FormData();
        if (!(params instanceof FormData)) {
            Object.keys(params || {}).forEach(function (k) {
                var v = params[k];
                if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
                else body.append(k, v === null || v === undefined ? '' : v);
            });
        }
        body.append('action', action);
        if (csrf) body.append('csrf', csrf);
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.error !== 0) throw new Error((j && j.msg) || T('ErrNetwork'));
                return j;
            });
    }

    function get(url) {
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); });
    }

    var toastTimer = null;
    function toast(message, isError) {
        var el = document.getElementById('tir-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'tir-toast';
            document.body.appendChild(el);
        }
        el.textContent = message;
        el.className = 'tir-on' + (isError ? ' tir-err' : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.className = ''; }, isError ? 5000 : 2500);
    }

    /* Locale-aware comparison, so accented names sort where a reader expects them. */
    function byName(a, b) {
        return String(a.name).localeCompare(String(b.name), document.documentElement.lang || undefined, { sensitivity: 'base' });
    }

    window.TIR = { T: T, esc: esc, call: call, get: get, toast: toast, byName: byName };
})();
