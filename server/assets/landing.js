/* Landing page: the hero stage (6 steps on the made-up Fernleaf site) and "Try it on a site". */
'use strict';
(function () {
const $ = (id) => document.getElementById(id);
const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
const nf1 = new Intl.NumberFormat('en', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
// Captions and labels come translated from the page (palette-data); the made-up sites and the exported code stay in English.
const T = JSON.parse($('palette-data').textContent).t || {};
const tr = (k, v = {}) => (T[k] ?? k).replace(/\{(\w+)\}/g, (m, n) => (n in v ? v[n] : m));
const role = (k) => tr('lp_st_' + k);

/* ---------- colour maths: every ratio shown is computed, not typed ---------- */
const lum = (hex) => {
    const c = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
// Every pair here is normal-size text, so under 4.5:1 fails.
const grade = (r) => (r >= 7 ? 'AAA' : r >= 4.5 ? 'AA' : tr('lp_st_grade_fail'));
const hex = (rgb) => '#' + rgb.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('').toUpperCase();
/* Darken the background in small steps, keeping its hue, until the text passes AA. */
function fixFor(fg, bg) {
    let rgb = [1, 3, 5].map((i) => parseInt(bg.slice(i, i + 2), 16));
    for (let k = 0; k < 60 && ratio(fg, hex(rgb)) < 4.5; k++) rgb = rgb.map((v) => v * 0.97);
    return hex(rgb);
}

/* ---------- the made-up sites ---------- */
const SITES = {
    fernleaf: {
        name: 'Fernleaf', host: 'fernleaf.example', nav: ['Features', 'Pricing', 'Guides', 'About'],
        badge: 'New: frost alerts for your region', h1: 'Plan a garden that grows all year round',
        p: 'Fernleaf maps your beds, tracks what you planted and when, and tells you what to sow next.',
        cta1: 'Start free', cta2: 'Book a demo',
        cards: [['Bed planner', 'Draw your beds to scale and drag crops into place.'], ['Sowing calendar', 'A week-by-week list, tuned to your last frost date.'], ['Harvest log', 'Record every basket and see which varieties earned their space.']],
        c: { primary: '#1F5E4B', accent: '#E0763A', surface: '#FFFFFF', surface2: '#F4F1EA', text: '#1A1A1A', text2: '#5B6470', border: '#E5E7EB', onPrimary: '#FFFFFF', onAccent: '#FFFFFF' },
        head: 'Georgia', body: 'Inter', headStack: "Georgia, 'Times New Roman', serif", bodyStack: 'Inter, system-ui, sans-serif', rBtn: 8, rCard: 12,
    },
    copperkettle: {
        name: 'Copperkettle', host: 'copperkettle.example', nav: ['Roasts', 'Subscriptions', 'Brewing', 'Visit'],
        badge: 'This week: a washed Ethiopian', h1: 'Fresh roasts, posted on Mondays',
        p: 'Small batches roasted on Saturday, in your letterbox by Tuesday, with a brew card for each bag.',
        cta1: 'Choose a roast', cta2: 'Gift a bag',
        cards: [['Single origins', 'One farm per bag, with the altitude and process on the label.'], ['House blend', 'The one we drink in the shop, roasted a little darker.'], ['Brew guides', 'Filter, espresso and stovetop, with the grind for each.']],
        c: { primary: '#6B3A2A', accent: '#C8892B', surface: '#FFFDF8', surface2: '#F3EBDD', text: '#2B211C', text2: '#6E5F55', border: '#E6DCCB', onPrimary: '#FFFFFF', onAccent: '#2B211C' },
        head: 'Palatino', body: 'System UI', headStack: "'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif", bodyStack: 'system-ui, sans-serif', rBtn: 4, rCard: 6,
    },
    nightjar: {
        name: 'Nightjar', host: 'nightjar.example', nav: ['Map', 'Species', 'Recorders', 'Help'],
        badge: '312 recordings last night', h1: 'Hear who sings after dark',
        p: 'Leave your phone on the windowsill. Nightjar records the night, names the birds and puts them on a map.',
        cta1: 'Start listening', cta2: 'See the map',
        cards: [['Night log', 'Each call with its time, length and a short clip.'], ['Species list', 'Every bird heard near you, with the first and last date.'], ['Sound map', 'Recordings from your street and the fields around it.']],
        c: { primary: '#8AB4FF', accent: '#F2C14E', surface: '#12151C', surface2: '#1B2030', text: '#E9ECF2', text2: '#9AA3B5', border: '#2A3142', onPrimary: '#0B0E14', onAccent: '#0B0E14' },
        head: 'System UI', body: 'System UI', headStack: 'system-ui, sans-serif', bodyStack: 'system-ui, sans-serif', rBtn: 999, rCard: 16,
    },
};
const ROLES = [['primary', 'Primary'], ['accent', 'Accent'], ['surface', 'Surface'], ['text', 'Text'], ['border', 'Border']];
const leaf = (c) => `<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path fill="${c}" d="M12 2C7 7 5 11 5 15a7 7 0 0 0 14 0c0-4-2-8-7-13z"/></svg>`;

function siteVars(s) {
    const c = s.c;
    return `--c-primary:${c.primary};--c-accent:${c.accent};--c-surface:${c.surface};--c-surface2:${c.surface2};--c-text:${c.text};--c-text2:${c.text2};--c-border:${c.border};--c-on-primary:${c.onPrimary};--c-on-accent:${c.onAccent};--f-head:${s.headStack};--f-body:${s.bodyStack};--r-btn:${s.rBtn}px;--r-card:${s.rCard}px`;
}
function siteHTML(s) {
    return `<div class="fl" style="${siteVars(s)}">
<div class="fl-top" data-src="surface"><span class="fl-logo">${leaf('currentColor')}${s.name}</span><span class="fl-nav">${s.nav.map((n) => `<span>${n}</span>`).join('')}</span><span class="fl-btn fl-pri" data-src="primary">${s.cta1}</span></div>
<div class="fl-hero"><span class="fl-badge"><i></i>${s.badge}</span><div class="fl-h1" data-src="text">${s.h1}</div><p class="fl-p">${s.p}</p>
<div class="fl-ctas"><span class="fl-btn fl-pri">${s.cta1}</span><span class="fl-btn fl-acc" data-src="accent">${s.cta2}</span></div></div>
<div class="fl-cards">${s.cards.map(([h, p], i) => `<div class="fl-card"${i === 0 ? ' data-src="border"' : ''}><div class="fl-h3">${h}</div><p>${p}</p></div>`).join('')}</div></div>`;
}

/* ---------- exports, built from the current tokens ---------- */
function exportText(fmt, s, host) {
    const c = s.c;
    if (fmt === 'md') return `# Design system — ${host}\nUse only these tokens for UI.\nDo not invent new colours, fonts or spacing.\n\n| Role    | Token           | Hex     |\n| ------- | --------------- | ------- |\n${ROLES.map(([k, n]) => `| ${n.padEnd(7)} | --color-${k.padEnd(7)} | ${c[k]} |`).join('\n')}\n\nType: ${s.head} for headings, ${s.body} for text.\nRadius: buttons ${s.rBtn}px, cards ${s.rCard}px.\nSpacing: 8 px base (8, 16, 24, 32).`;
    if (fmt === 'css') return `:root {\n${ROLES.map(([k]) => `  --color-${k}: ${c[k]};`).join('\n')}\n  --font-heading: ${s.headStack};\n  --font-body: ${s.bodyStack};\n  --radius-button: ${s.rBtn}px;\n  --radius-card: ${s.rCard}px;\n  --space-1: 8px;\n  --space-2: 16px;\n  --space-3: 24px;\n  --space-4: 32px;\n}`;
    if (fmt === 'tw') return `export default {\n  theme: {\n    extend: {\n      colors: {\n${ROLES.map(([k]) => `        ${k}: '${c[k]}',`).join('\n')}\n      },\n      borderRadius: { button: '${s.rBtn}px', card: '${s.rCard}px' },\n      spacing: { 1: '8px', 2: '16px', 3: '24px', 4: '32px' },\n    },\n  },\n};`;
    return `{\n  "collection": "${host}",\n  "variables": [\n${ROLES.map(([k]) => `    { "name": "color/${k}", "type": "COLOR", "value": "${c[k]}" }`).join(',\n')},\n    { "name": "radius/button", "type": "FLOAT", "value": ${s.rBtn} }\n  ]\n}`;
}
const FORMATS = [['md', 'DESIGN.md'], ['css', 'CSS'], ['tw', 'Tailwind'], ['figma', 'Figma']];
const esc = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;');

/* ---------- icons, same line set as the live page ---------- */
const ICON = {
    pointer: '<path d="M4 4l6.5 16 2.3-6.7L19.5 11z"/>',
    rescan: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
    pause: '<path d="M8 5v14M16 5v14"/>',
    play: '<path d="M7 4l12 8-12 8z"/>',
};
const icon = (n, s = 16) => `<svg class="icon" width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON[n]}</svg>`;

/* ---------- the stage ---------- */
const stage = $('stage'), vp = $('vp'), page = $('page'), panel = $('panel'), fx = $('fx'), cursor = $('cursor');
const base = SITES.fernleaf;
const FAIL = { fg: base.c.onAccent, bg: base.c.accent };
FAIL.r = ratio(FAIL.fg, FAIL.bg);
FAIL.fix = fixFor(FAIL.fg, FAIL.bg);
FAIL.fr = ratio(FAIL.fg, FAIL.fix);
page.insertAdjacentHTML('beforeend', siteHTML(base));
const fl = page.querySelector('.fl');

/* Tokens as they stand at the start of a step: the edit in step 5 carries into the export. */
function tokensAt(i) {
    const s = structuredClone(base);
    if (i >= 5) s.c.accent = FAIL.fix;
    return s;
}
function applyTokens(s) { fl.setAttribute('style', siteVars(s)); }

let timers = [];
const later = (ms, fn) => { if (RM) fn(); else timers.push(setTimeout(fn, ms)); };
const clearTimers = () => { timers.forEach(clearTimeout); timers = []; };
const vpRect = () => vp.getBoundingClientRect();
function at(el, dx = 0.5, dy = 0.5) {
    const r = el.getBoundingClientRect(), v = vpRect();
    return [r.left - v.left + r.width * dx, r.top - v.top + r.height * dy];
}
function box(el) { const r = el.getBoundingClientRect(), v = vpRect(); return { x: r.left - v.left, y: r.top - v.top, w: r.width, h: r.height }; }
const phone = matchMedia('(max-width: 639px)');
const pageBottom = () => (panel.classList.contains('closed') || !phone.matches ? vp.clientHeight : panel.offsetTop);
const visible = (b) => b.y >= 0 && b.y + b.h <= pageBottom();
/* On phones the sheet covers the lower page, so the site scrolls up to show what the step points at. */
function showOnPage(el) {
    const b = box(el), d = Math.ceil(b.y + b.h + 16 - pageBottom());
    if (d > 0) page.style.setProperty('--shift', `${-d}px`);
}

function moveCursor(x, y) { cursor.classList.add('show'); cursor.style.transform = `translate(${x}px, ${y}px)`; }
function click(x, y) {
    const c = document.createElement('span');
    c.className = 'click go';
    // left and top, because the ring's own animation owns transform
    c.style.left = `${x - 12}px`;
    c.style.top = `${y - 12}px`;
    fx.append(c);
    later(500, () => c.remove());
}
function overlay(cls, b, label) {
    const el = document.createElement('div');
    el.className = cls;
    el.style.transform = `translate(${b.x}px, ${b.y}px)`;
    el.style.width = `${b.w}px`;
    el.style.height = `${b.h}px`;
    if (label) el.innerHTML = `<span>${label}</span>`;
    fx.append(el);
    requestAnimationFrame(() => el.classList.add('in'));
    if (RM) el.classList.add('in');
    return el;
}
function fly(color, from, to, done) {
    if (RM) return done();
    const d = document.createElement('span');
    d.className = 'fly';
    d.style.background = color;
    d.style.transform = `translate(${from[0]}px, ${from[1]}px)`;
    fx.append(d);
    d.getBoundingClientRect();
    requestAnimationFrame(() => { d.style.transform = `translate(${to[0]}px, ${to[1]}px) scale(.6)`; });
    setTimeout(() => { done(); d.style.opacity = '0'; setTimeout(() => d.remove(), 300); }, 780);
}

/* Panel parts, in the extension's own words. */
const header = (sub, editOn = false) => `<div class="sp-header"><span class="mark" aria-hidden="true"></span><div class="sp-ht"><span class="sp-name">${tr('lp_st_ds')}</span><span class="sp-sub">${sub}</span></div><span class="icon-btn${editOn ? ' on' : ''}" id="p-edit">${icon('pointer', 15)}</span><span class="icon-btn">${icon('rescan', 15)}</span></div>`;
const foot = `<div class="sp-foot"><div class="sp-export">${icon('download', 15)}${tr('lp_st_export_btn')}</div><p class="sp-cap">${tr('lp_st_export_cap')}</p></div>`;
const scanned = tr('lp_st_scanned', { host: base.host });
function colours(s, shown) {
    const w = [30, 7, 24, 22, 17];
    return `<div class="sp-sec"><p class="sp-label">${tr('lp_st_colours')}</p><div class="st-strip">${ROLES.map(([k], i) => `<span data-k="${k}" class="${shown ? 'in' : ''}" style="flex:${w[i]};background:${s.c[k]}"></span>`).join('')}</div>
<div class="legend">${ROLES.map(([k, n]) => `<span class="leg${shown ? ' in' : ''}" data-k="${k}"><i style="background:${s.c[k]}"></i>${role(k)} <code>${s.c[k]}</code></span>`).join('')}</div></div>`;
}
function typeRows(s) {
    const h1 = getComputedStyle(fl.querySelector('.fl-h1')), p = getComputedStyle(fl.querySelector('.fl-p')), h3 = getComputedStyle(fl.querySelector('.fl-h3'));
    const px = (v) => Math.round(parseFloat(v));
    const rows = [[tr('lp_st_display'), s.head, h1], [tr('lp_st_h3'), s.head, h3], [tr('lp_st_body'), s.body, p]];
    return `<div class="sp-sec"><p class="sp-label">${tr('lp_st_type')}</p><div class="rows">${rows.map(([n, f, cs]) => `<div class="row"><span class="aa" style="font-family:${cs.fontFamily};font-weight:${cs.fontWeight}">Aa ${n}</span><span class="mono">${f} ${cs.fontWeight} · ${px(cs.fontSize)}/${px(cs.lineHeight)}</span></div>`).join('')}</div></div>
<div class="sp-sec"><p class="sp-label">${tr('lp_st_spacing')}</p><div class="space">${[8, 16, 24, 32].map((v) => `<div><b style="width:${v}px"></b><span class="mono">${v}</span></div>`).join('')}</div></div>`;
}
function setPanel(html, open = true) {
    panel.innerHTML = html;
    vp.classList.toggle('has-panel', open);
    panel.classList.toggle('closed', !open);
    $('ext').classList.toggle('st-on', open);
}
const reveal = (sel, step, start = 0) => panel.querySelectorAll(sel).forEach((el, i) => later(start + i * step, () => el.classList.add('in')));

const STEPS = [
    {
        label: tr('lp_st_s1'), dur: 5200,
        cap: () => tr('lp_st_c1', { host: base.host }),
        run() {
            setPanel('', false);
            moveCursor(vp.clientWidth * 0.55, vp.clientHeight * 0.55);
            later(500, () => { const [x, y] = at($('ext')); moveCursor(x - 4, y - 46); });
            later(1300, () => { const [x, y] = at($('ext')); click(x, y - 42); $('ext').classList.add('st-on'); });
            later(1500, () => {
                setPanel(header(tr('lp_st_reading')) + `<div class="sp-body"><p class="loading"><span class="st-spin"></span>${tr('lp_st_scanning')}</p></div>` + foot);
                const s = $('scanline');
                s.className = 'scanline';
                s.getBoundingClientRect();
                s.classList.add('go');
            });
            later(3300, () => {
                $('scanline').className = 'scanline st-done';
                setPanel(header(`${scanned}`) + `<div class="sp-body"><p class="sp-label">${tr('lp_st_found')}</p></div>` + foot);
                cursor.classList.remove('show');
            });
        },
    },
    {
        label: tr('lp_st_s2'), dur: 5600,
        cap: () => tr('lp_st_c2'),
        run() {
            setPanel(header(scanned) + `<div class="sp-body">${colours(base, false)}</div>` + foot);
            ROLES.forEach(([k], i) => later(300 + i * 420, () => {
                const src = fl.querySelector(`[data-src="${k}"]`), dot = panel.querySelector(`.leg[data-k="${k}"]`);
                const sb = box(src);
                const from = visible(sb) ? at(src) : [sb.x + 20, Math.min(sb.y + 20, vp.clientHeight * 0.4)];
                const target = dot.querySelector('i');
                fly(base.c[k], from, at(target), () => { dot.classList.add('in'); panel.querySelector(`.st-strip [data-k="${k}"]`).classList.add('in'); });
            }));
        },
    },
    {
        label: tr('lp_st_s3'), dur: 5400,
        cap: () => tr('lp_st_c3'),
        run() {
            setPanel(header(scanned) + `<div class="sp-body">${colours(base, true)}${typeRows(base)}</div>` + foot);
            const h1 = fl.querySelector('.fl-h1'), p = fl.querySelector('.fl-p'), cs = getComputedStyle(h1), ps = getComputedStyle(p);
            const px = (v) => Math.round(parseFloat(v));
            later(200, () => overlay('measure', box(h1), `${base.head} ${px(cs.fontSize)}/${px(cs.lineHeight)}`));
            later(700, () => overlay('measure', box(p), `${base.body} ${px(ps.fontSize)}/${px(ps.lineHeight)}`));
            later(1200, () => {
                const [a, b] = fl.querySelectorAll('.fl-ctas .fl-btn'), ra = box(a), rb = box(b);
                if (rb.x > ra.x + ra.w) overlay('measure gap', { x: ra.x + ra.w, y: ra.y, w: rb.x - ra.x - ra.w, h: ra.h }, `${Math.round(rb.x - ra.x - ra.w)}`);
            });
            later(1700, () => {
                const card = fl.querySelector('.fl-card'), b = box(card), pad = px(getComputedStyle(card).paddingTop);
                if (visible({ ...b, h: pad })) overlay('measure gap', { x: b.x + 1, y: b.y + 1, w: b.w - 2, h: pad }, `${pad}`);
            });
            reveal('.row', 350, 300);
            reveal('.space div', 200, 1500);
        },
    },
    {
        label: tr('lp_st_s4'), dur: 6200,
        cap: () => tr('lp_st_c4', { ratio: nf1.format(FAIL.r), fix: FAIL.fix, fixed: nf1.format(FAIL.fr) }),
        run() {
            const body = tr('lp_st_body'), btns = tr('lp_st_buttons');
            const pairs = [[base.c.text, base.c.surface, body], [base.c.onPrimary, base.c.primary, btns], [base.c.text2, base.c.surface2, body], [FAIL.fg, FAIL.bg, btns]];
            const fails = pairs.filter(([f, b]) => ratio(f, b) < 4.5).length;
            const row = ([f, b, where]) => { const r = ratio(f, b), g = grade(r); return `<div class="row pair"><span class="st-chip" style="color:${f};background:${b}">Aa</span><span>${tr('lp_st_on', { fg: f, bg: b })}<small>${where}</small></span><span class="grade ${r >= 4.5 ? 'g-pass' : 'g-fail'}">${nf1.format(r)}:1 ${g}</span></div>`; };
            setPanel(header(scanned) + `<div class="sp-body"><p class="sp-label">${tr('lp_st_contrast')}</p><div class="sum"><div class="sum-pass"><b>${pairs.length - fails}</b>${tr('lp_st_pass')}</div><div class="sum-fail"><b>${fails}</b>${tr('lp_st_fail')}</div></div><div class="rows">${pairs.map(row).join('')}</div>
<div class="fix"><b>${tr('lp_st_fixfor')}</b><div class="fix-row"><em>${tr('lp_st_before')}</em><span class="s" style="color:${FAIL.fg};background:${FAIL.bg}">${tr('lp_st_sample')}</span><em>${tr('lp_st_after')}</em><span class="s" style="color:${FAIL.fg};background:${FAIL.fix}">${tr('lp_st_sample')}</span></div><div class="fix-row"><span class="grade g-pass">${tr('lp_st_fixed', { ratio: nf1.format(FAIL.fr) })}</span><code class="mono">${FAIL.fix}</code></div></div></div>` + foot);
            reveal('.row', 250, 200);
            const btn = fl.querySelector('.fl-acc');
            showOnPage(btn);
            later(1400, () => { const b = box(btn); overlay('ring fail', { x: b.x - 4, y: b.y - 4, w: b.w + 8, h: b.h + 8 }, tr('lp_st_fails', { ratio: nf1.format(FAIL.r) })); });
            later(2600, () => {
                panel.querySelector('.fix').classList.add('in');
                const body = panel.querySelector('.sp-body');
                body.scrollTo({ top: body.scrollHeight, behavior: RM ? 'auto' : 'smooth' });
            });
        },
    },
    {
        label: tr('lp_st_s5'), dur: 6800,
        cap: () => tr('lp_st_c5'),
        run() {
            setPanel(header(scanned) + `<div class="sp-body"><div class="st-hint" id="hint">${tr('lp_st_hint')}</div><div id="tokslot"></div><p class="edits" id="edits">${tr('lp_st_edits', { n: 0 })}</p></div>` + foot);
            moveCursor(vp.clientWidth * 0.5, vp.clientHeight * 0.4);
            later(400, () => { const [x, y] = at($('p-edit')); moveCursor(x - 4, y - 4); });
            later(1200, () => {
                const [x, y] = at($('p-edit')); click(x, y);
                $('p-edit').classList.add('on');
                $('hint').textContent = tr('lp_st_hint2');
            });
            const btn = fl.querySelector('.fl-acc');
            showOnPage(btn);
            later(1700, () => { const [x, y] = at(btn, 0.5, 0.6); moveCursor(x - 4, y - 4); });
            later(2500, () => {
                const [x, y] = at(btn, 0.5, 0.6); click(x, y);
                const b = box(btn);
                overlay('ring', { x: b.x - 4, y: b.y - 4, w: b.w + 8, h: b.h + 8 }, `${role('accent')} · ${tr('lp_st_uses', { n: 2 })}`);
                $('tokslot').innerHTML = `<div class="tok"><div class="tok-head"><span>${role('accent')}</span><span class="mono">${tr('lp_st_uses', { n: 2 })}</span></div><div class="st-field" id="field"><i id="sw" style="background:${base.c.accent}"></i><code id="hexv">${base.c.accent}</code></div><p class="edits">${tr('lp_st_repaints')}</p></div>`;
                later(50, () => panel.querySelector('.tok').classList.add('in'));
            });
            later(3600, () => { const [x, y] = at($('field'), 0.7); moveCursor(x - 4, y - 4); });
            later(4300, () => {
                $('field').classList.add('typing');
                $('hexv').textContent = FAIL.fix;
                $('sw').style.background = FAIL.fix;
                applyTokens(tokensAt(5));
                $('edits').textContent = tr('lp_st_edits', { n: 1 });
            });
            later(5200, () => cursor.classList.remove('show'));
        },
    },
    {
        label: tr('lp_st_s6'), dur: 6400,
        cap: () => tr('lp_st_c6'),
        run() {
            const s = tokensAt(5);
            setPanel(header(scanned) + `<div class="sp-body"><p class="sp-label">${tr('lp_st_export')}</p><div class="fmt">${FORMATS.map(([k, n], i) => `<span data-f="${k}"${i === 0 ? ' class="on"' : ''}>${n}</span>`).join('')}</div><pre class="code" id="code"></pre></div>` + foot);
            const show = (k) => {
                panel.querySelectorAll('.fmt span').forEach((el) => el.classList.toggle('on', el.dataset.f === k));
                const code = $('code');
                code.textContent = exportText(k, s, base.host);
                code.classList.remove('swap'); code.getBoundingClientRect(); code.classList.add('swap');
            };
            show('md');
            FORMATS.slice(1).forEach(([k], i) => later(1500 + i * 1500, () => {
                const tab = panel.querySelector(`[data-f="${k}"]`), [x, y] = at(tab);
                moveCursor(x - 4, y - 4);
                later(450, () => { click(x, y); show(k); });
            }));
            later(1500 + 3 * 1500 + 600, () => cursor.classList.remove('show'));
        },
    },
];

/* ---------- step control and autoplay ---------- */
let cur = -1, autoTimer = 0, left = 0, startedAt = 0;
const paused = new Set(RM ? ['motion'] : []);
const steps = $('steps');
STEPS.forEach((s, i) => steps.insertAdjacentHTML('beforeend', `<button type="button" data-i="${i}"><span class="n">${i + 1}</span>${s.label}<span class="bar"></span></button>`));
const btns = [...steps.children];

function go(i) {
    clearTimers();
    clearTimeout(autoTimer);
    cur = (i + STEPS.length) % STEPS.length;
    fx.querySelectorAll(':scope > :not(#cursor)').forEach((el) => el.remove());
    cursor.classList.remove('show');
    page.style.removeProperty('--shift');
    applyTokens(tokensAt(cur));
    btns.forEach((b, k) => {
        b.toggleAttribute('aria-current', false);
        if (k === cur) b.setAttribute('aria-current', 'step');
        const bar = b.querySelector('.bar');
        bar.classList.remove('run');
        if (k === cur) { bar.style.setProperty('--dur', `${STEPS[cur].dur}ms`); bar.getBoundingClientRect(); bar.classList.add('run'); }
    });
    $('cap-n').textContent = tr('lp_st_of', { n: cur + 1, total: STEPS.length });
    const t = $('cap-t');
    t.textContent = STEPS[cur].cap();
    t.classList.remove('swap'); t.getBoundingClientRect(); t.classList.add('swap');
    STEPS[cur].run();
    left = STEPS[cur].dur;
    schedule();
}
function schedule() {
    clearTimeout(autoTimer);
    if (paused.size) return;
    startedAt = performance.now();
    autoTimer = setTimeout(() => go(cur + 1), left);
}
function setPaused(reason, on) {
    const was = paused.size > 0;
    on ? paused.add(reason) : paused.delete(reason);
    const now = paused.size > 0;
    stage.classList.toggle('is-paused', now);
    if (now && !was) { clearTimeout(autoTimer); left = Math.max(0, left - (performance.now() - startedAt)); }
    if (!now && was) schedule();
    const user = paused.has('user') || paused.has('motion');
    $('play').innerHTML = user ? icon('play', 14) + tr('lp_st_play') : icon('pause', 14) + tr('lp_st_pause');
    $('play').setAttribute('aria-label', tr(user ? 'lp_st_play_a' : 'lp_st_pause_a'));
}

steps.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    setPaused('user', true);
    go(+b.dataset.i);
});
steps.addEventListener('keydown', (e) => {
    const d = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
    if (!d) return;
    e.preventDefault();
    const n = (btns.indexOf(document.activeElement) + d + btns.length) % btns.length;
    btns[n].focus();
});
$('play').addEventListener('click', () => {
    const on = paused.has('user') || paused.has('motion');
    setPaused('motion', false);
    setPaused('user', !on);
    if (on) go(cur + 1);
});
stage.addEventListener('pointerenter', () => setPaused('hover', true));
stage.addEventListener('pointerleave', () => setPaused('hover', false));
stage.addEventListener('focusin', () => setPaused('focus', true));
stage.addEventListener('focusout', (e) => { if (!stage.contains(e.relatedTarget)) setPaused('focus', false); });
new IntersectionObserver(([e]) => setPaused('offscreen', !e.isIntersecting), { threshold: 0.2 }).observe(stage);
let resizeT = 0;
addEventListener('resize', () => { clearTimeout(resizeT); resizeT = setTimeout(() => go(cur), 250); });

setPaused('init', false);
go(0);
/* ---------- try it on a site ---------- */
(function () {
    const pick = $('try-pick'), siteEl = $('try-site'), side = $('try-side');
    let fmt = 'css';
    Object.entries(SITES).forEach(([k, s], i) => pick.insertAdjacentHTML('beforeend', `<button type="button" data-k="${k}" aria-pressed="${i === 0}">${s.name}</button>`));
    function show(k) {
        const s = SITES[k];
        pick.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.k === k)));
        siteEl.innerHTML = siteHTML(s);
        const pairs = [[s.c.text, s.c.surface], [s.c.onPrimary, s.c.primary], [s.c.onAccent, s.c.accent], [s.c.text2, s.c.surface2]];
        const fails = pairs.filter(([f, b]) => ratio(f, b) < 4.5).length;
        side.innerHTML = `<div><p class="sp-label">${s.host}</p><div class="rows">${ROLES.map(([r, n]) => `<div class="row in"><span class="leg in"><i style="background:${s.c[r]}"></i>${role(r)}</span><code class="mono">${s.c[r]}</code></div>`).join('')}</div></div>
<p class="mono" style="margin:0">${s.head} / ${s.body} · ${tr('lp_try_radius', { btn: s.rBtn, card: s.rCard })} · ${tr('lp_try_pairs', { pass: pairs.length - fails, total: pairs.length })}</p>
<div class="fmt" role="group" aria-label="${tr('lp_try_fmt')}">${FORMATS.map(([f, n]) => `<button type="button" data-f="${f}" class="${f === fmt ? 'on' : ''}" aria-pressed="${f === fmt}">${n}</button>`).join('')}</div>
<pre class="code swap">${esc(exportText(fmt, s, s.host))}</pre>`;
    }
    pick.addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) show(b.dataset.k); });
    side.addEventListener('click', (e) => {
        const f = e.target.closest('[data-f]');
        if (!f) return;
        fmt = f.dataset.f;
        show(pick.querySelector('[aria-pressed="true"]').dataset.k);
    });
    show('fernleaf');
})();
})();
