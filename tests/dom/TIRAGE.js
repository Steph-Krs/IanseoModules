/**
 * Browser-side checks for the live draw module (TIRAGE).
 *
 * Every screen of this module is an empty frame filled by its script from the
 * module's JSON endpoints, so the HTML the server sends proves nothing. These
 * scenarios load the real pages with their real scripts and let them talk to
 * the local server, then read what a person would see.
 *
 * WHICH DRAW. Pages of a draw need an id and screen tokens that only exist in a
 * given database. The scenarios take the most recent draw listed on the home
 * page, so they run on any installation that has at least one draw, and say so
 * plainly when there is none.
 *
 * WHAT IS GUARDED, on the preparation, control, public and commentators' pages:
 *   - the page actually builds its content (a script error leaves the frame
 *     empty, and nothing on the server side would notice);
 *   - no "[object Object]" and no raw translation key reaches the screen — T()
 *     falls back to the key's own name, which breaks nothing and is only ever
 *     seen by a reader.
 *
 * The harness of run.js lifts markup and inline scripts only; these pages load
 * their scripts by URL, so the scenarios build their own jsdom with external
 * resources enabled and the Node fetch lent to the window.
 */

const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const ROOT = '/Modules/Custom/TIRAGE/';

/** Load a page with its external scripts, and wait until `ready(doc)` holds. */
async function open(baseUrl, url, ready) {
    const full = baseUrl + url;
    const html = await (await fetch(full)).text();
    // ianseo's own stylesheets use syntax jsdom cannot parse; that is noise here.
    // So is the control page's preview frame: it runs in a window of its own that
    // is not lent fetch, and the public screen is tested on its own below.
    // Script errors of the page itself, on the other hand, are exactly what to show.
    const console_ = new VirtualConsole();
    console_.on('jsdomError', e => {
        if (/Could not parse CSS|fetch is not defined/.test(e.message)) return;
        console.log('    page error: ' + e.message);
    });
    console_.on('error', (...a) => console.log('    console.error: ' + a.join(' ')));
    const dom = new JSDOM(html, {
        virtualConsole: console_,
        url: full,
        runScripts: 'dangerously',
        resources: 'usable',
        pretendToBeVisual: true,
        beforeParse(win) {
            win.fetch = (u, o) => fetch(new URL(u, full), o);
            win.FormData = FormData;
            win.scrollTo = () => {};
            win.confirm = () => false;
        },
    });
    const doc = dom.window.document;
    const until = Date.now() + 15000;
    while (Date.now() < until) {
        if (ready(doc)) return { dom, doc, win: dom.window };
        await new Promise(r => setTimeout(r, 250));
    }
    dom.window.close();
    throw new Error('the page never built its content: ' + url);
}

/**
 * Visible strings that look like a translation key shown as its own name.
 *
 * A key missing from the table is precisely one the table cannot list, so keys
 * are recognised by their shape: CamelCase with at least two capitals
 * ("LeftToDraw"). Team names are written in capitals and never match.
 */
function rawKeys(texts) {
    return texts.map(t => t.trim()).filter(t => /^[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]*)+$/.test(t));
}

function texts(doc) {
    const out = [];
    const walker = doc.createTreeWalker(doc.body, 4 /* NodeFilter.SHOW_TEXT */);
    while (walker.nextNode()) out.push(walker.currentNode.nodeValue);
    doc.querySelectorAll('input, textarea').forEach(el => out.push(el.value || ''));
    doc.querySelectorAll('[title], [placeholder]').forEach(el => {
        out.push(el.getAttribute('title') || '', el.getAttribute('placeholder') || '');
    });
    return out;
}

async function latestDraw(baseUrl) {
    const html = await (await fetch(baseUrl + ROOT + 'index.php')).text();
    const id = (html.match(/edit\.php\?id=(\d+)/) || [])[1];
    const display = (html.match(/display\.php\?t=([a-f0-9]{32})/) || [])[1];
    const speaker = (html.match(/speaker\.php\?t=([a-f0-9]{32})/) || [])[1];
    return id ? { id, display, speaker } : null;
}

function screen(name, page, ready) {
    return {
        name,
        async run({ baseUrl, check }) {
            const draw = await latestDraw(baseUrl);
            if (!draw) { check(name + ': no draw on this installation to test with', true); return; }
            for (const lang of ['en', 'fr', 'de']) {
                const { doc, win } = await open(baseUrl, page(draw) + '&Lang=' + lang, ready);
                const all = texts(doc);
                const objs = all.filter(t => t.includes('[object'));
                check(`${lang}: no [object Object]`, objs.length === 0, objs.slice(0, 3).join(' | '));
                const keys = rawKeys(all);
                check(`${lang}: no translation key shown as text`, keys.length === 0, keys.slice(0, 5).join(', '));
                win.close();
            }
        },
    };
}

module.exports = [
    screen('Preparation page', d => ROOT + 'edit.php?id=' + d.id,
        doc => doc.querySelector('#tir-edit .tir-card')),
    screen('Control page', d => ROOT + 'control.php?id=' + d.id,
        doc => doc.querySelector('#tir-control #tir-cats') && doc.querySelector('#tir-scenes button')),
    screen('Public screen', d => ROOT + 'display.php?t=' + d.display,
        doc => doc.querySelector('#tir-stage h1, #tir-stage .tir-idle-kicker')),
    screen("Commentators' screen", d => ROOT + 'speaker.php?t=' + d.speaker,
        doc => doc.querySelector('#tir-speaker .tir-sp-head')),
];
