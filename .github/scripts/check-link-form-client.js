const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('phpBB2/includes/javascript/links_form.js', 'utf8');
const alerts = [];
const window = { URL, alert: text => alerts.push(text) };
vm.runInNewContext(source, { window, encodeURIComponent });
let cases = 0;
function field(value, max, required = false) {
    const attrs = { 'data-link-maxchars': String(max) };
    return { value, required, focus() { this.focused = true; }, getAttribute: key => attrs[key] ?? null,
        setAttribute: (key, value) => { attrs[key] = value; }, removeAttribute: key => { delete attrs[key]; } };
}
function form(required = false) {
    const image = field('', 0), panel = { style: {}, querySelector: () => image };
    return { elements: { link_title: field('Grüße 😀', 100), link_desc: field('Description', 255), link_url: field('https://example.invalid/', 100), link_logo_src: field('', 120, required), link_category: field('1', 0) },
        getAttribute: key => key === 'data-preview-error' ? 'preview' : 'form', querySelector: () => panel, panel, image };
}
for (const required of [false, true]) {
    const f = form(required);
    assert.strictEqual(window.phpbbLinkForm.validate(f), !required); cases++;
}
for (const [key, values] of Object.entries({
    link_title: ['', ' ', 'a'.repeat(101), '😀'.repeat(101), '\ud800', 'bad\u0000'],
    link_desc: ['', '\n\t', 'ä'.repeat(256), '\ud800'],
    link_url: ['', 'http://', 'javascript:invalid', 'data:text/plain,invalid', '//example.invalid/', 'https:example.invalid', 'https://name:pass@example.invalid/', 'https://example.invalid:0/', 'https://example.invalid/\nnext', 'https://example.invalid/\\path', 'https://example.invalid/' + 'ä'.repeat(50)],
    link_logo_src: ['http://', 'javascript:invalid', 'https://example.invalid/' + 'a'.repeat(121)],
    link_category: ['', '01', '1e0', '1junk', '16777216']
})) {
    for (const value of values) {
        const f = form(); f.elements[key].value = value; alerts.length = 0;
        assert.strictEqual(window.phpbbLinkForm.validate(f), false, key + ' rejects invalid input');
        assert.strictEqual(f.elements[key].getAttribute('aria-invalid'), 'true'); assert.strictEqual(alerts.length, 1); cases++;
    }
}
for (const text of ['a'.repeat(100), 'ä'.repeat(100), '😀'.repeat(100), '  Grüße  ']) {
    const f = form(); f.elements.link_title.value = text;
    assert.strictEqual(window.phpbbLinkForm.validate(f), true); cases++;
}
for (const [text, expected] of [['a'.repeat(101), 'a'.repeat(100)], ['😀'.repeat(101), '😀'.repeat(100)], ['ä😀'.repeat(51), 'ä😀'.repeat(50)]]) {
    const input = field(text, 100); window.phpbbLinkForm.limit(input); assert.strictEqual(input.value, expected); cases++;
}
for (const logo of ['', 'https://example.invalid/logo.png', 'http://example.invalid/logo.png']) {
    const f = form(); f.elements.link_logo_src.value = logo;
    assert.strictEqual(window.phpbbLinkForm.preview(f), false); assert.strictEqual(f.panel.style.display, '');
    assert.strictEqual(f.image.src, logo || 'images/links/no_logo88a.gif');
    assert.strictEqual(f.image.getAttribute('referrerpolicy'), 'no-referrer'); cases++;
}
for (const logo of ['javascript:invalid', 'data:text/html,invalid', '//example.invalid/', 'https://name:pass@example.invalid/', 'https://example.invalid/\r\nnext']) {
    const f = form(); f.elements.link_logo_src.value = logo; f.image.setAttribute('src', 'old'); alerts.length = 0;
    assert.strictEqual(window.phpbbLinkForm.preview(f), false); assert.strictEqual(f.panel.style.display, 'none');
    assert.strictEqual(f.image.getAttribute('src'), null); assert.deepStrictEqual(alerts, ['preview']); cases++;
}
assert(!source.includes('window.open') && !source.includes('innerHTML') && !source.includes('eval('));
console.log(`Link form client: ${cases} cases PASS; actual form handler, Unicode character limits, optional logo, URL byte limits, inline image preview and no navigation/opener.`);
