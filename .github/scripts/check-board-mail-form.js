const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync('phpBB2/templates/fisubsilversh/profile_send_email.tpl', 'utf8');
const match = source.match(/function checkForm\(formObj\)[\s\S]*?\n}/);
assert(match, 'Actual profile email validation function must exist');
const alerts = [];
const context = { alert: message => alerts.push(message) };
vm.createContext(context);
vm.runInContext(match[0], context);
for (const [subject, message, valid] of [['Ä', 'Ö', true], ['😀', 'Grüße', true], ['', 'Text', false], ['Betreff', '', false], ['  ', 'Text', false], ['Betreff', '\n\t ', false]]) {
  alerts.length = 0;
  const result = context.checkForm({ email_subject: { value: subject }, email_message: { value: message } });
  assert.strictEqual(result !== false, valid, 'Actual form field names must work without a TypeError');
  assert.strictEqual(alerts.length, valid ? 0 : 1, 'Only empty fields prompt an alert');
}
console.log('Actual profile mail form: 6 Unicode/nonempty/field-binding cases PASS.');
