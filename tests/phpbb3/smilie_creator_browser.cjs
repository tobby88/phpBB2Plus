// Exercise the real posting editor, its popup and the return to the draft.
module.exports = async function ({command, find, evaluate, until, url}) {
    const {cookies, agent} = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
    await command('Network.enable');
    await command('Network.setUserAgentOverride', {userAgent: agent});
    for (const [name, value] of cookies) await command('Network.setCookie', {name, value, url});
    await command('Network.setCookie', {name:'cookie_consent', value:'1', url});
    await command('Page.navigate', {url});
    await until(() => find('a[data-sign-target="message"]'), 'the sign button in the posting form');
    await until(() => evaluate('document.readyState === "complete"'), 'the editor script');
    await evaluate('(' + function () {
        const open = window.open;
        window.open = function (...args) {
            window.testSignWindow = open.apply(this, args);
            return window.testSignWindow;
        };
        const editor = document.getElementById('message');
        editor.value = 'Anfang alt Ende';
        editor.setSelectionRange(7, 10);
        editor.addEventListener('input', () => { window.testSignChanged = true; });
    } + ')()');
    const point = await evaluate('(' + function () {
        const link = document.querySelector('a[data-sign-target="message"]');
        link.scrollIntoView({block:'center'});
        const box = link.getBoundingClientRect();
        return {x:box.left+box.width/2, y:box.top+box.height/2};
    } + ')()');
    const pointer = {...point, button:'left', clickCount:1};
    await command('Input.dispatchMouseEvent', {type:'mousePressed', ...pointer});
    await command('Input.dispatchMouseEvent', {type:'mouseReleased', ...pointer});
    await until(() => evaluate('!!window.testSignWindow?.document.getElementById("sign-caption")'), 'the separate sign creator');
    await evaluate('(' + function () {
        const popup = window.testSignWindow.document;
        popup.getElementById('sign-caption').value = 'Gruß aus Köln';
        popup.querySelector('input[name="face"][value="19"]').checked = true;
        popup.querySelector('button[name="make"]').click();
    } + ')()');
    await until(() => evaluate('!!window.testSignWindow?.document.querySelector("#plus-sign-insert:not([hidden])")'), 'the generated sign');
    await evaluate('window.testSignWindow.document.querySelector(".plus-sign-face").scrollIntoView()');
    await until(() => evaluate('window.testSignWindow.document.querySelector(".plus-sign-face").naturalWidth > 0'), 'the original smiley image');
    const sample = await evaluate('(' + function () {
        const popup = window.testSignWindow, doc = popup.document;
        const sign = doc.querySelector('.plus-smilie-sign'), face = sign?.querySelector('img');
        const style = sign && popup.getComputedStyle(sign);
        return {code:doc.getElementById('plus-sign-code').value, text:sign?.getAttribute('aria-label'),
                width:face?.naturalWidth, height:face?.naturalHeight, align:style?.verticalAlign,
                fit:face && popup.getComputedStyle(face).objectFit};
    } + ')()');
    if (sample.text !== 'Gruß aus Köln' || sample.width !== 19 || sample.height !== 23 || sample.fit !== 'scale-down' || sample.align !== 'middle') {
        throw Error('The sign preview lost its local image, text or alignment');
    }
    await evaluate('window.testSignWindow.document.getElementById("plus-sign-insert").click()');
    await until(() => evaluate('!!window.testSignChanged && window.testSignWindow.closed'), 'insertion into the open editor');
    const draft = await evaluate('document.getElementById("message").value');
    if (draft !== 'Anfang ' + sample.code + ' Ende') throw Error('The popup replaced the wrong part of the original draft');
};
