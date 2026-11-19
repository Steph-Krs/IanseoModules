/**
 * Browser-side test runner for a custom ianseo module.
 *
 * WHY THIS EXISTS. A page can be perfectly correct when the server sends it and
 * wrong a moment later, once its own JavaScript has built the widgets. That is
 * how a multilingual field ended up showing "[object Object]" in the Interactive
 * Guide's editor: nothing was wrong in the HTML, so fetching the page proved
 * nothing. The only way to catch that class of bug is to execute the script.
 *
 * The page under test is the REAL one, fetched from the local server, not a
 * fixture: a fixture drifts away from the code it is meant to protect. Only the
 * module's own markup and script are lifted out of it — the rest of the ianseo
 * template (jQuery, the menus) is left behind, because it is not what is being
 * tested and it drags a great deal of unrelated machinery into the sandbox.
 *
 * A module's own checks live in dom/<MODULE>.js and export a list of scenarios;
 * this file only provides the harness. Run by tests/bin/pages.php while its web
 * server is up, or on its own against any ianseo:
 *
 *     node tests/dom/run.js GUIDE [http://localhost]
 */

const path = require('path');
const { JSDOM } = require('jsdom');

const moduleName = process.argv[2];
const baseUrl    = (process.argv[3] || 'http://localhost').replace(/\/$/, '');

if (!moduleName) {
    console.error('usage: node run.js <MODULE> [base-url]');
    process.exit(2);
}

let scenarios;
try {
    scenarios = require(path.join(__dirname, moduleName + '.js'));
} catch (e) {
    console.error(`No browser-side checks for ${moduleName} (expected tests/dom/${moduleName}.js).`);
    process.exit(2);
}

let failed = 0;

/**
 * Record one assertion.
 *
 * @param {string} label What is being asserted, in the reader's terms.
 * @param {boolean} ok
 * @param {string} [detail] Shown only on failure — the value that was wrong.
 */
function check(label, ok, detail) {
    if (!ok) failed++;
    console.log(`  [${ok ? ' OK ' : 'FAIL'}] ${label}` + (ok || !detail ? '' : `  (${detail})`));
}

/**
 * Load one page of the module and run its script.
 *
 * @param {string} url Page to fetch, relative to the module.
 * @param {object} opts
 * @param {string} opts.from Marker where the module's own markup begins.
 * @returns {Promise<{dom: object, doc: object, win: object, html: string}>}
 */
async function loadPage(url, opts) {
    const html = await (await fetch(url)).text();

    const start = html.indexOf(opts.from);
    if (start < 0) throw new Error(`marker "${opts.from}" not found in ${url}`);

    // Everything from the marker to the end of the last <script> of the page:
    // the module's markup and the script that drives it, and nothing before.
    const lastScript = html.lastIndexOf('</script>');
    if (lastScript < start) throw new Error(`no script after the marker in ${url}`);
    const fragment = html.slice(start, lastScript + '</script>'.length);

    const dom = new JSDOM(
        '<!doctype html><html><body>' + fragment + '</body></html>',
        { runScripts: 'dangerously', url }
    );
    return { dom, doc: dom.window.document, win: dom.window, html };
}

/**
 * Every piece of text the page shows: field values, option labels, the visible
 * text, and the attributes that carry wording.
 *
 * @param {object} doc
 * @returns {string[]}
 */
function visibleText(doc) {
    const out = [];
    doc.querySelectorAll('input, textarea, select').forEach(el => out.push(el.value || ''));
    doc.querySelectorAll('option').forEach(el => out.push(el.textContent || ''));
    doc.querySelectorAll('[title], [placeholder]').forEach(el => {
        out.push(el.getAttribute('title') || '', el.getAttribute('placeholder') || '');
    });
    out.push(doc.body.textContent || '');
    return out;
}

(async () => {
    for (const scenario of scenarios) {
        console.log('\n' + scenario.name);
        try {
            await scenario.run({
                baseUrl,
                loadPage: (url, opts) => loadPage(baseUrl + url, opts),
                check,
                visibleText,
            });
        } catch (e) {
            check(scenario.name + ' — did not run to the end', false, e.message);
        }
    }

    console.log('\n' + (failed ? `${failed} CHECK(S) FAILED` : 'All checks passed.'));
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
