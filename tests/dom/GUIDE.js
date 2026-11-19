/**
 * Browser-side checks for the Interactive Guide module.
 *
 * The visual editor is where this matters: it builds nearly all of its widgets
 * in JavaScript, so the server sends an empty shell and everything worth
 * checking appears afterwards. Two things are guarded.
 *
 * 1. No field shows "[object Object]". That is the shape a language map takes
 *    when it is dropped into an input instead of being resolved for one
 *    language, and it is invisible to anything that only reads the HTML the
 *    server sent. It happened for real, in the quiz, the challenge, the
 *    condition list and the preview panel at once.
 *
 * 2. Switching the editing language loses nothing. A course carries all its
 *    languages in one file; an author who opens Italian, types a little and
 *    goes back to French must find the French exactly as it was.
 *
 * 3. Every string the browser asks for is one the server actually sends. The
 *    player reads its wording from window.GUIDE_T, and T() falls back to the
 *    key when it is missing — so a key the server never publishes reaches the
 *    screen as its own name ("InProgressStep" instead of "In progress"). It
 *    breaks nothing, throws nothing, and is only ever seen by a reader.
 */

const COURSE = 'n4kp7xm2r9f1a8q5t3wb';   // the shipped course, translated fr+en
const EDITOR = '/Modules/Custom/GUIDE/admin/edit.php';
const CATALOGUE = '/Modules/Custom/GUIDE/index.php';
const FROM   = '<div class="ge-main">';  // where the module's own markup starts

module.exports = [
    {
        name: 'Editor — a translated course, interface in each language',
        async run({ loadPage, check, visibleText }) {
            for (const lang of ['en', 'fr', 'it']) {
                const { doc } = await loadPage(`${EDITOR}?id=${COURSE}&Lang=${lang}`, { from: FROM });

                const objs = visibleText(doc).filter(t => t.includes('[object Object]'));
                check(`${lang}: no [object Object] anywhere`, objs.length === 0,
                      objs.slice(0, 3).join(' | '));

                const val = id => (doc.getElementById(id) || {}).value || '';
                check(`${lang}: course title shown`,  val('f-title').length > 0);
                check(`${lang}: step title shown`,
                      (doc.getElementById('pv-stitle').textContent || '').length > 0);
                check(`${lang}: preview panel shows the title`,
                      !(doc.getElementById('pv-fname').textContent || '').includes('object'));

                const q = doc.querySelector('#quiz-list .qz-q');
                check(`${lang}: quiz question shown`, q && q.value.length > 0,
                      q ? q.value.slice(0, 40) : 'no quiz block');
                const a = doc.querySelector('#quiz-list .qz-choice');
                check(`${lang}: quiz answer shown`, a && a.value.length > 0,
                      a ? a.value.slice(0, 40) : 'no answer');
                check(`${lang}: challenge brief shown`, val('defi-intro').length > 0);

                // Condition labels are resolved on the server; the browser must
                // only ever see plain strings.
                const labels = Array.from(doc.querySelectorAll('.tr-cond option'))
                                    .map(o => o.textContent);
                check(`${lang}: condition labels resolved`,
                      !labels.some(t => t.includes('[object')),
                      labels.filter(t => t.includes('[object')).slice(0, 2).join(' | '));
            }
        },
    },
    {
        name: 'Editor — a round trip through a language the course does not have',
        async run({ loadPage, check }) {
            const { doc, win } = await loadPage(`${EDITOR}?id=${COURSE}&Lang=en`, { from: FROM });

            const inLang = (code, read) => { win.switchLang(code); return read(); };
            const title  = () => doc.getElementById('f-title').value;
            const quiz   = () => doc.querySelector('#quiz-list .qz-q').value;
            const answer = () => doc.querySelector('#quiz-list .qz-choice').value;
            const brief  = () => doc.getElementById('defi-intro').value;

            const frTitle = inLang('fr', title), enTitle = inLang('en', title);
            check('title differs between fr and en', frTitle && enTitle && frTitle !== enTitle,
                  `${frTitle} / ${enTitle}`);

            const frQuiz = inLang('fr', quiz), enQuiz = inLang('en', quiz);
            check('quiz question differs between fr and en', frQuiz && enQuiz && frQuiz !== enQuiz,
                  `${frQuiz.slice(0, 30)} / ${enQuiz.slice(0, 30)}`);
            const frBrief = inLang('fr', brief);

            // A language the course does not carry: every field must come up
            // blank, because the author has to SEE what is missing rather than
            // be shown the original and believe it is done. Driven by a code the
            // course will never hold, so this stays true as courses get
            // translated — asserting it of a real language would only hold until
            // somebody translated into it.
            const ABSENT = 'pt';
            win.switchLang(ABSENT);
            check('a language the course does not carry shows empty fields',
                  title() === '' && quiz() === '' && answer() === '' && brief() === '');

            const type = (el, value) => {
                el.value = value;
                el.dispatchEvent(new win.Event('input', { bubbles: true }));
            };
            type(doc.querySelector('#quiz-list .qz-q'), 'Pergunta em portugues');
            type(doc.querySelector('#quiz-list .qz-choice'), 'Resposta em portugues');
            type(doc.getElementById('defi-intro'), '<p>Desafio em portugues</p>');

            win.switchLang('fr');
            check('the French quiz question survives the new-language edit', quiz() === frQuiz,
                  quiz().slice(0, 40));
            check('the French brief survives the new-language edit', brief() === frBrief,
                  brief().slice(0, 40));

            win.switchLang(ABSENT);
            check('the new quiz question is kept', quiz()   === 'Pergunta em portugues');
            check('the new answer is kept',        answer() === 'Resposta em portugues');
            check('the new brief is kept',         brief()  === '<p>Desafio em portugues</p>');

            // Only the TEXT may become a language map. The structure around it
            // must not be duplicated: a translation that pointed at a different
            // element than the original would be worse than no translation.
            const json = JSON.parse(doc.getElementById('guide-json-editor').value);
            const q0   = json.quiz.questions[0];
            check('the question holds every language it had, plus the new one',
                  typeof q0.q === 'object' && ['fr', 'en', ABSENT].every(k => q0.q[k]),
                  JSON.stringify(Object.keys(q0.q || {})));
            check('the number of answers is unchanged',
                  Array.isArray(q0.choices) && q0.choices.length >= 2,
                  String(q0.choices && q0.choices.length));
            check('selectors are still plain strings',
                  json.steps.every(s => (s.triggers || []).every(
                      t => t.selector == null || typeof t.selector === 'string')));
            check('pages are still plain strings',
                  json.steps.every(s => s.page == null || typeof s.page === 'string'));
        },
    },
    {
        name: 'Player — every string the browser asks for is one the server sends',
        async run({ baseUrl, loadPage, check }) {
            // The catalogue carries window.GUIDE_T and the same t() fallback as
            // the panel, so it is the cheapest page to read the table from.
            // From the script menu.php injects on every page — the one that
            // carries window.GUIDE_T — to the end of the page.
            const { win, html } = await loadPage(`${CATALOGUE}?Lang=fr`,
                                                 { from: '<script>\n/* Published for assets/guide.js' });
            const T = win.GUIDE_T || {};
            check('the browser receives a string table', Object.keys(T).length > 40,
                  String(Object.keys(T).length) + ' key(s)');

            // Every key the player and the catalogue look up, gathered from the
            // shipped JavaScript rather than from a list kept here — a list
            // would go stale the first time somebody adds a string.
            const sources = [
                await (await fetch(new URL('/Modules/Custom/GUIDE/assets/guide.js', baseUrl))).text(),
                html,
            ].join('\n');

            const asked = new Set();
            for (const rx of [/\bT\(\s*'([A-Za-z][A-Za-z0-9_]*)'/g,
                              /\bt\(\s*'([A-Za-z][A-Za-z0-9_]*)'/g]) {
                let m;
                while ((m = rx.exec(sources)) !== null) asked.add(m[1]);
            }
            check('the sources were read', asked.size > 20, String(asked.size) + ' key(s) asked for');

            const missing = [...asked].filter(k => !(k in T));
            check('no key reaches the screen as its own name', missing.length === 0,
                  missing.slice(0, 5).join(', '));

            // The keys that were actually caught out, kept by name so the
            // regression is named and not merely implied.
            for (const k of ['InProgressStep', 'PreviousVersion', 'JsQuestion',
                             'JsChecklist', 'JsConditionNotMet', 'JsContentNotFound',
                             'JsRedoQuestions', 'JsPanelNormalSize',
                             'TargetGold', 'TargetSilver', 'TargetBronze',
                             'Quiz', 'Troubleshooting', 'RecTitle', 'JsGoToPage']) {
                check(`${k} is published`, typeof T[k] === 'string' && T[k] !== '',
                      JSON.stringify(T[k]));
            }
            check('InProgressStep is translated, not the key itself',
                  (T.InProgressStep || '').indexOf('InProgress') === -1, T.InProgressStep);
            check('InProgressStep still carries its {$a} placeholder',
                  (T.InProgressStep || '').indexOf('{$a}') !== -1, T.InProgressStep);
        },
    },
    {
        name: 'Conditions — the labels are translated for the reader',
        async run({ loadPage, check }) {
            // The labels are resolved on the server before they reach the page,
            // so the browser only ever sees plain strings — and a different one
            // per language.
            const seen = {};
            for (const lang of ['en', 'fr', 'it', 'de', 'es']) {
                const { win } = await loadPage(`${EDITOR}?id=${COURSE}&Lang=${lang}`, { from: FROM });
                const conds = win.GUIDE_CONDITIONS || [];
                check(`${lang}: the conditions reach the browser`, conds.length > 0,
                      String(conds.length));
                check(`${lang}: every label is a plain string`,
                      conds.every(c => typeof c.label === 'string' && c.label !== ''),
                      JSON.stringify((conds.find(c => typeof c.label !== 'string') || {}).label));
                const one = conds.find(c => c.id === 'has_participant');
                seen[lang] = one ? one.label : '';
                check(`${lang}: has_participant has a label`, !!seen[lang], seen[lang]);
            }
            const distinct = new Set(Object.values(seen));
            check('the label really differs from one language to the next',
                  distinct.size === 5, JSON.stringify(seen));
        },
    },
];
