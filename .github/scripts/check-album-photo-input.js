const fs = require('fs');
const path = require('path');
const vm = require('vm');
const base = path.join(__dirname, '../../phpBB2/templates/fisubsilversh');
let cases = 0;
function check(ok, message) { if (!ok) throw new Error(message); }
function functionSource(body, name) {
  const start = body.indexOf('function ' + name + '(');
  check(start >= 0, 'Actual ' + name + ' function');
  let depth = 0;
  for (let i = body.indexOf('{', start); i < body.length; i++) {
    if (body[i] === '{') depth++;
    if (body[i] === '}' && --depth === 0) return body.slice(start, i + 1);
  }
  throw new Error('Unbalanced actual client function');
}
for (const kind of ['upload', 'edit']) {
  const html = fs.readFileSync(path.join(base, `album_${kind}_body.tpl`), 'utf8');
  const body = html.replace(/\{S_PIC_DESC_MAX_LENGTH\}/g, '32');
  const form = { pic_title: { value: '0' }, pic_desc: { value: '' }, pic_file: { value: 'image.jpg' }, cat_id: { value: '10' } };
  const context = { document: { upload: form, editform: form }, alerts: [], alert(value) { context.alerts.push(value); } };
  vm.createContext(context);
  // Execute both real validation functions; no transport, DOM or HTTP stubs run.
  vm.runInContext(functionSource(body, 'albumPhotoLength') + '\n' + functionSource(body, 'checkAlbumForm'), context);
  for (const [title, description, expected] of [
    ['0', '', true], ['A', '', true], ['', '', false], ['😀'.repeat(255), '', true],
    ['😀'.repeat(256), '', false], ["'".repeat(255), '', true], ['Title', '😀'.repeat(32), true],
    ['Title', '😀'.repeat(33), false], ['Title', 'a' + '😀'.repeat(31), true],
    ['Title', 'a' + '😀'.repeat(32), false], ['Title', '&amp; <tag>', true]
  ]) {
    form.pic_title.value = title; form.pic_desc.value = description;
    context.alerts = [];
    check(vm.runInContext('checkAlbumForm()', context) === expected, `${kind} actual title/Unicode description boundary`);
    check(context.alerts.length === (expected ? 0 : 1), `${kind} actual validation message count`);
    cases++;
  }
  for (const text of ['', '0', 'Grüße 😀', '\ud800', '\udc00', '😀'.repeat(32)]) {
    context.input = text;
    check(vm.runInContext('albumPhotoLength(input)', context) === Array.from(text).length, 'Actual surrogate-pair length'); cases++;
  }
  if (kind === 'upload') {
    check(/name="pic_username"[^>]*maxlength="64"/.test(html), 'Actual guest field permits 32 supplementary Unicode characters'); cases++;
    form.pic_title.value = '0'; form.pic_desc.value = '';
    for (const [name, expected] of [['😀'.repeat(32), true], ['😀'.repeat(33), false], ['a'.repeat(32), true], ['a'.repeat(33), false], ["'".repeat(32), true], ["'".repeat(33), false], ['0', true], ['', true]]) {
      form.pic_username = { value: name }; context.alerts = [];
      check(vm.runInContext('checkAlbumForm()', context) === expected, 'Actual guest name uses the server logical character limit');
      check(context.alerts.length === (expected ? 0 : 1), 'Actual guest validation message count');
      if (!expected) check(context.alerts[0] === '{L_PIC_USERNAME_TOO_LONG}', 'Specific translated guest length message');
      cases++;
    }
    delete form.pic_username;
    check(vm.runInContext('checkAlbumForm()', context) === true, 'Registered upload has no guest input'); cases++;
  }
}
console.log(`Album picture client input: ${cases} actual upload/edit Unicode/title-zero/boundary cases PASS; no network.`);
