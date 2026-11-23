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
const commentClient = fs.readFileSync(path.join(base, '../../album_mod/album_comment_form.js'), 'utf8');
const detail = fs.readFileSync(path.join(base, 'album_showpage_body.tpl'), 'utf8');
const editor = fs.readFileSync(path.join(base, 'album_comment_body.tpl'), 'utf8');
const context = { window: {}, document: { forms: {} } };
let warning;
context.window.alert = value => { warning = value; };
vm.runInNewContext(commentClient, context);
for (const html of [detail, editor]) {
  check(html.includes('src="album_mod/album_comment_form.js"'), 'Both album forms load their shared validation');
  check(!html.includes('onsubmit="return checkForm();'), 'Both album forms use their own field set');
}
const commentForms = [...detail.matchAll(/<form name="commentform"[^>]+>/g), ...editor.matchAll(/<form name="commentform"[^>]+>/g)];
check(commentForms.length === 3, 'Actual rating-only, combined and editing forms');
for (const [index, match] of commentForms.entries()) {
  const attrs = Object.fromEntries(Array.from(match[0].matchAll(/(data-[\w-]+)="([^"]+)"/g), item => [item[1], item[2]]));
  check(match[0].includes('onsubmit="return checkAlbumComment(this);"'), 'Actual submit handler');
  const elements = {};
  const form = { elements, getAttribute: key => key === 'data-limit' ? '3' : attrs[key] };
  for (const [comment, rating, guest, error] of [
    [null, '-1', null, 'data-empty'], [null, '2', null, null], ['', null, null, 'data-empty'],
    ['0', null, null, null], ['😀😀😀', null, null, null], ['😀😀😀😀', null, null, 'data-long'],
    [' \t', '-1', null, 'data-empty'], ['a\nb', null, null, 'data-long'], ['', '2', null, null],
    ['Hi', '-1', '😀'.repeat(32), null], ['Hi', '1', '😀'.repeat(33), 'data-name-long']
  ]) {
    if ((index === 0 && comment !== null) || (index === 2 && (rating !== null || guest !== null)) ||
        (index === 1 && comment === null)) continue;
    for (const [name, value] of [['comment', comment], ['rate', rating], ['comment_username', guest]]) {
      if (value === null) delete elements[name]; else elements[name] = { value };
    }
    warning = null;
    check(context.checkAlbumComment(form) === !error && warning === (error ? attrs[error] : null),
      'Actual rendered form handles present controls, one-character posts, Unicode, ratings and translated validation');
    cases++;
  }
}
check(detail.includes('{switch_comment_post.rate_comment.rate_row.POINT}'), 'Combined rating options use their full template block path');
const field = { value: 'abcd', selectionStart: 1, selectionEnd: 3,
  setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }, focus() {} };
context.document.forms.commentform = { elements: { comment: field } };
context.emotions(':)');
check(field.value === 'a:)d' && field.selectionStart === 3 && field.selectionEnd === 3, 'Insert smiley at the selection');
context.window.open = () => null;
context.openAllSmiles('album_showpage.php?mode=smilies');
console.log('Album photo and comment forms: ' + cases + ' input cases PASS.');
