const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const { spawnSync } = require('child_process');
const template = fs.readFileSync('phpBB2/templates/fisubsilversh/links_js_body.tpl', 'utf8');
const script = template.match(/<script[^>]*>([\s\S]*?)<\/script>/i)[1];
let cases = 0;
class Node {
    constructor(tag) { this.tag = tag; this.attrs = {}; this.children = []; this.style = {}; this.listeners = {}; this.parent = null; }
    get firstChild() { return this.children[0] || null; }
    appendChild(child) { child.parent = this; this.children.push(child); }
    removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parent = null; }
    setAttribute(key, value) { this.attrs[key] = String(value); }
    addEventListener(key, handler) { this.listeners[key] = handler; }
    contains(child) { for (; child; child = child.parent) { if (child === this) return true; } return false; }
    event(key, event = {}) { if (this.listeners[key]) this.listeners[key](event); }
}
function run(code, missing = false) {
    const target = new Node('div'), document = new Node('document'), window = new Node('window'), timers = new Map(); let next = 0;
    document.visibilityState = 'visible'; document.getElementById = () => missing ? null : target; document.createElement = tag => new Node(tag);
    window.setTimeout = (fn, ms) => { assert.strictEqual(typeof fn, 'function'); assert(ms >= 250 && ms <= 2147483647); timers.set(++next, fn); return next; };
    window.clearTimeout = id => timers.delete(id);
    vm.runInNewContext(code, { window, document });
    return { target, document, window, timers, tick() { assert.strictEqual(timers.size, 1); const [id, fn] = timers.entries().next().value; timers.delete(id); fn(); assert(timers.size <= 1); }, images() { return target.children.map(a => a.children[0].attrs.src); } };
}
function code(items, visible = 1) { return script.replace('{LINKS_LOGO}', JSON.stringify(items)).replace('{DISPLAY_INTERVAL}', '1000').replace('{DISPLAY_LOGO_NUM}', String(visible)).replace('{SITE_LOGO_WIDTH}', '88').replace('{SITE_LOGO_HEIGHT}', '31'); }
const items = Array.from({ length: 5 }, (_, i) => ({ url: `links.php?action=go&link_id=${i + 1}`, image: `https://example.invalid/${i + 1}.png`, title: `Grüße ${i + 1}` }));
for (const count of [0, 1, 2, 5]) { for (const visible of [1, 2, 10]) {
    const list = items.slice(0, count), r = run(code(list, visible)); assert.deepStrictEqual(r.images(), list.slice(0, visible).map(x => x.image));
    assert.strictEqual(r.timers.size, count > visible ? 1 : 0); cases++;
    if (count > visible) { for (let step = 1; step <= count + 2; step++) { r.tick(); assert.deepStrictEqual(r.images(), Array.from({ length: visible }, (_, i) => list[(step + i) % count].image)); cases++; } }
    for (const anchor of r.target.children) { assert.strictEqual(anchor.attrs.rel, 'noopener noreferrer'); assert.strictEqual(anchor.attrs.target, '_blank'); assert.strictEqual(anchor.children[0].attrs.referrerpolicy, 'no-referrer'); assert.strictEqual(anchor.children[0].attrs.width, '88'); }
} }
const r = run(code(items));
r.target.event('mouseenter'); assert.strictEqual(r.timers.size, 0); r.target.event('focusin'); r.target.event('mouseleave'); assert.strictEqual(r.timers.size, 0); cases++;
r.target.event('focusout', { relatedTarget: r.target.firstChild }); assert.strictEqual(r.timers.size, 0); r.target.event('focusout', { relatedTarget: null }); assert.strictEqual(r.timers.size, 1); cases++;
r.document.visibilityState = 'hidden'; r.document.event('visibilitychange'); assert.strictEqual(r.timers.size, 0); r.document.visibilityState = 'visible'; r.document.event('visibilitychange'); assert.strictEqual(r.timers.size, 1); cases++;
r.window.event('pagehide'); assert.strictEqual(r.timers.size, 0); r.window.event('pageshow'); r.window.event('pageshow'); assert.strictEqual(r.timers.size, 1); r.tick(); cases++;
assert.strictEqual(run(code(items), true).timers.size, 0); assert.strictEqual(run(code(null)).timers.size, 0); cases += 2;
const php = process.env.PHP_BINARY || 'php';
const rendered = spawnSync(php, ['-d', 'disable_functions=mail', '.github/scripts/check-link-rotation-view.php', '--sample'], { encoding: 'utf8' });
assert.strictEqual(rendered.status, 0, rendered.stderr); assert.strictEqual((rendered.stdout.match(/<\/script>/g) || []).length, 1);
const actual = rendered.stdout.match(/<script[^>]*>([\s\S]*?)<\/script>/i)[1], integrated = run(actual);
assert.deepStrictEqual(integrated.images(), ['https://example.invalid/image.php?a=1&b=2']); assert.strictEqual(integrated.target.firstChild.children[0].attrs.alt, 'One & "Grüße 😀" </script>'); integrated.tick(); assert.strictEqual(integrated.target.firstChild.attrs.href, 'links.php?action=go&link_id=29'); cases++;
assert(!script.includes('innerHTML') && !script.includes('document.all') && !script.includes('document.write'));
console.log(`Link rotation client: ${cases} cases PASS; actual flat DOM windows/wrap/timers, hover/focus/visibility/BFCache pauses, isolated images and real PHP/XS/JSON integration; no network.`);
