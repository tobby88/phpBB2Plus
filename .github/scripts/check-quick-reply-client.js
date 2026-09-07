const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const template = fs.readFileSync(path.join(__dirname, '../../phpBB2/templates/fisubsilversh/quick_reply.tpl'), 'utf8');
const script = template.match(/<script\b[^>]*>([\s\S]*?)<\/script>/i)[1]
  .replace(/\{U_MORE_SMILIES\}/g, 'fixture-smilies')
  .replace(/\{L_NO_TEXT_SELECTED\}/g, 'empty-selection')
  .replace(/\{L_EMPTY_MESSAGE\}/g, 'empty-message');
let selection = { toString: () => '' };
let focused = 0;
const alerts = [];
const message = { value: 'before after', selectionStart: 7, selectionEnd: 7,
  focus() { focused++; }, setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; } };
const form = { elements: { message, quick_quote: { checked: false }, last_msg: { value: '[quote]Previous[/quote]' } }, message };
const context = vm.createContext({ document: { forms: { post: form }, post: form },
  window: { getSelection: () => selection, open: () => null }, alert: value => alerts.push(value) });
vm.runInContext(script, context, { timeout: 1000 });
context.quoteSelection();
assert.equal(message.value, 'before after');
assert.deepEqual(alerts, ['empty-selection']);
selection = null;
context.quoteSelection();
assert.equal(message.value, 'before after');
assert.equal(alerts.pop(), 'empty-selection');
selection = { toString: () => 'Grüße 😀' };
context.quoteSelection();
assert.equal(message.value, 'before [quote]Grüße 😀[/quote]\nafter');
assert.equal(message.selectionStart, 'before [quote]Grüße 😀[/quote]\n'.length);
assert.equal(message.selectionEnd, message.selectionStart);
message.value = 'left SELECT right'; message.selectionStart = 5; message.selectionEnd = 11;
context.emoticon(':)');
assert.equal(message.value, 'left :) right');
assert.equal(message.selectionStart, 7);
assert.ok(focused > 0);
context.openAllSmiles(); // Popup blockers must not cause null.focus() errors.
message.value = '';
form.elements.quick_quote.checked = true;
assert.equal(context.checkForm(form), false);
assert.equal(alerts.pop(), 'empty-message');
assert.equal(form.elements.quick_quote.checked, true);
message.value = 'Antwort äöü ß 😀';
assert.equal(context.checkForm(form), true);
assert.equal(message.value, '[quote]Previous[/quote]Antwort äöü ß 😀');
assert.equal(form.elements.quick_quote.checked, false);
assert.equal(context.checkForm(form), true);
assert.equal(message.value, '[quote]Previous[/quote]Antwort äöü ß 😀');
assert.equal(Object.hasOwn(context, 'formErrors'), false);
assert.equal(Object.hasOwn(context, 'theSelection'), false);
assert.equal(Object.hasOwn(context, 'smiles'), false);
console.log('Quick-reply client behavior checks passed.');
