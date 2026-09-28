/**
 * Scorecard splitter page.
 *
 * Two jobs. The PDF link of each club is built at the moment it is clicked, from
 * the options as they are then, so the list never has to be redrawn. And the
 * archive button drives api/build.php: one request to start, one per batch of
 * files while the progress bar moves, one to pack the ZIP, then the download.
 *
 * The option boxes behave as on the core's printout: "no distance" and the
 * numbered distances exclude each other, and the full-page header excludes the
 * competition header and images, which it replaces. Hiding the text of the
 * header only means something with the full-page header, so it follows it.
 */
(function () {
    'use strict';

    /**
     * Translated text, from the table the page publishes as window.SCS_T.
     *
     * @param {string} key
     * @param {object} [a] Values for the {$a[name]} placeholders.
     * @returns {string}
     */
    function T(key, a) {
        var s = (window.SCS_T && window.SCS_T[key]) || key;
        if (a) {
            Object.keys(a).forEach(function (k) {
                s = s.split('{$a[' + k + ']}').join(String(a[k]));
            });
        }
        return s;
    }

    /**
     * The form's options as address parameters, without the anti-CSRF token.
     *
     * @param {HTMLFormElement} form
     * @returns {URLSearchParams}
     */
    function options(form) {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (value, name) {
            if (name !== 'csrf') params.append(name, value);
        });
        return params;
    }

    /**
     * POST to the archive endpoint and read its JSON answer.
     *
     * @param {string} url
     * @param {URLSearchParams} body
     * @returns {Promise<object>} Resolves with the answer, rejects with a message.
     */
    function call(url, body) {
        return fetch(url, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (res) {
                return res.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error(T('ErrServer', {status: res.status}));
                    }
                    if (data.error) throw new Error(data.msg || T('ErrServer', {status: res.status}));
                    return data;
                });
            });
    }

    function init() {
        var root = document.getElementById('scs');
        var form = document.getElementById('scs-form');
        if (!root || !form) return;

        // The text of the full-page header can only be hidden when there is one.
        function syncHeaderText() {
            form.elements.HideHeaderText.disabled = !form.elements.ScorePageHeaderFooter.checked;
        }
        syncHeaderText();

        // Distances: "no distance" (value 0) and the numbered ones exclude each other.
        form.addEventListener('change', function (ev) {
            var box = ev.target;
            if (box.name === 'ScoreDist[]' && box.checked) {
                form.querySelectorAll('input[name="ScoreDist[]"]').forEach(function (other) {
                    if (other !== box && ((box.value === '0') !== (other.value === '0'))) other.checked = false;
                });
            }
            // The full-page header replaces the competition header and images.
            if (box.name === 'ScorePageHeaderFooter' && box.checked) {
                ['ScoreHeader', 'ScoreLogos'].forEach(function (n) {
                    if (form.elements[n]) form.elements[n].checked = false;
                });
            }
            if ((box.name === 'ScoreHeader' || box.name === 'ScoreLogos') && box.checked) {
                form.elements.ScorePageHeaderFooter.checked = false;
            }
            syncHeaderText();
        });

        // A club's PDF, with the options as they are when the link is clicked.
        root.addEventListener('click', function (ev) {
            var link = ev.target.closest('a.scs-pdf');
            if (!link) return;
            var params = options(form);
            params.set('Mode', 'club');
            params.set('Key', link.getAttribute('data-key'));
            link.href = root.getAttribute('data-pdf') + '?' + params.toString();
        });

        var button   = document.getElementById('scs-zip');
        var progress = document.getElementById('scs-progress');
        var status   = document.getElementById('scs-status');
        if (!button) return;

        function say(text, isError) {
            status.textContent = text;
            status.className = isError ? 'scs-error' : '';
        }

        button.addEventListener('click', function () {
            var api = root.getAttribute('data-api');
            var csrf = form.elements.csrf.value;

            if (!form.querySelector('input[name="Sessions[]"]:checked')) {
                say(T('ErrNoSession'), true);
                return;
            }

            function step(job, done, total) {
                progress.max = total;
                progress.value = done;
                say(T('Progress', {done: done, total: total}));
                if (done < total) {
                    return call(api, new URLSearchParams({do: 'step', job: job, csrf: csrf}))
                        .then(function (r) { return step(job, r.done, r.total); });
                }
                say(T('Packing'));
                return call(api, new URLSearchParams({do: 'finish', job: job, csrf: csrf}))
                    .then(function (r) {
                        say(T('Ready'));
                        window.location.href = r.url;
                    });
            }

            var body = options(form);
            body.set('do', 'start');
            body.set('csrf', csrf);

            button.disabled = true;
            progress.hidden = false;
            progress.removeAttribute('value');
            say(T('Starting'));

            call(api, body)
                .then(function (r) { return step(r.job, r.done, r.total); })
                .catch(function (err) { say(err.message, true); })
                .then(function () {
                    button.disabled = false;
                    progress.hidden = true;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
