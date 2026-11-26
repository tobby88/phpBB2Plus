// Select rendered post text, choose an actual popup smiley and continue in the native editor.
module.exports = async function ({command, find, evaluate, until, url}) {
    const {cookies, agent} = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
    await command('Network.enable');
    await command('Network.setUserAgentOverride', {userAgent:agent});
    for (const [name,value] of cookies) await command('Network.setCookie', {name, value, url});
    await command('Network.setCookie', {name:'cookie_consent', value:'1', url});
    await command('Page.navigate', {url});
    await until(() => find('#qr_postform [data-qr-selection]:not([hidden])'), 'the quick reply tools');
    async function click(selector) {
        const nodeId = await find(selector);
        await command('DOM.scrollIntoViewIfNeeded', {nodeId});
        const rect = (await command('DOM.getBoxModel', {nodeId})).model.content;
        const point = {x:(rect[0]+rect[4])/2, y:(rect[1]+rect[5])/2, button:'left', clickCount:1};
        await command('Input.dispatchMouseEvent', {type:'mousePressed', ...point});
        await command('Input.dispatchMouseEvent', {type:'mouseReleased', ...point});
    }
    await evaluate('(' + function () {
        const form = document.getElementById('qr_postform');
        const edit = form.elements.message;
        edit.value = 'Anfang alt Ende';
        edit.setSelectionRange(7, 10);
        const body = document.querySelector('#p1 .postbody .content');
        const range = document.createRange();
        range.selectNodeContents(body.querySelector('strong,b'));
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        const open = window.open;
        window.open = function (...args) { window.testQuickPicker = open.apply(this, args); return window.testQuickPicker; };
    } + ')()');
    await click('[data-qr-selection]');
    const message = 'document.getElementById("qr_postform").elements.message';
    if (await evaluate(message+'.value') !== 'Anfang [quote]Forum[/quote]\n Ende') throw Error('Quoting a selected passage replaced the wrong part of the draft');
    await evaluate('window.getSelection().removeAllRanges()');
    await click('[data-qr-selection]');
    if (!await evaluate('!!document.querySelector("#plus-quick-tools [role=status]").textContent')) throw Error('A missing selection was left unexplained');
    await evaluate(message+'.value = "Grüße Ende"; '+message+'.setSelectionRange(5,5)');
    await click('[data-qr-smilies]');
    await until(() => evaluate('!!window.testQuickPicker?.document.querySelector("a[onclick] img[alt]") && typeof window.testQuickPicker.insert_text === "function"'), 'the native smiley popup');
    const code = await evaluate('(' + function () {
        const image = window.testQuickPicker.document.querySelector('a[onclick] img[alt]');
        image.closest('a').click(); return image.alt;
    } + ')()');
    if (await evaluate(message+'.value') !== 'Grüße '+code+'  Ende') throw Error('The native popup did not insert its smiley at the caret');
    await evaluate('window.testQuickPicker.close(); '+message+'.value="Mein Entwurf Grüße"; document.querySelector("[data-qr-include]").checked=true');
    await until(() => evaluate('Date.now()/1000 > Number(document.querySelector("#qr_postform [name=creation_time]").value)+1.05'), 'the native posting token');
    await command('Network.emulateNetworkConditions', {offline:true, latency:0, downloadThroughput:-1, uploadThroughput:-1});
    await click('#qr_full_editor');
    await until(() => find('[data-qr-last]:not([hidden])'), 'the native fallback after an interrupted quote request');
    if (await evaluate(message+'.value') !== 'Mein Entwurf Grüße') throw Error('A failed quote request lost the original draft');
    await command('Network.emulateNetworkConditions', {offline:false, latency:0, downloadThroughput:-1, uploadThroughput:-1});
    await click('#qr_full_editor');
    await until(() => find('#postform textarea[name=message]'), 'the full native editor after quoting the visible post');
    const draft = await evaluate('document.querySelector("#postform textarea[name=message]").value');
    if (!draft.startsWith('[quote') || !draft.includes('[b]Forum[/b]') || !draft.endsWith('Mein Entwurf Grüße') ||
        draft.split('Mein Entwurf Grüße').length !== 2) throw Error('The last-post quote or the original draft was lost in the native preview');
};
