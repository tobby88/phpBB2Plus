// Exercise both refresh paths in the existing member browser. Only the 120-second clock is advanced.
module.exports = async function ({command, evaluate, until, url}) {
    const target = new URL('app.php/portal', url);
    await command('Page.enable');
    const script = await command('Page.addScriptToEvaluateOnNewDocument', {source: '(' + function () {
        const interval = window.setInterval;
        window.setInterval = function (fn, delay, ...rest) {
            if (delay === 120000) window.testPlusShoutTick = fn;
            return interval.call(this, fn, delay, ...rest);
        };
    } + ')()'});
    try {
        await command('Page.navigate', {url: target.href});
        await until(() => evaluate('document.readyState === "complete" && !!document.getElementById("plus-quick-message")'), 'the member shout panel');
        if (!await evaluate('typeof window.testPlusShoutTick === "function"')) {
            const present = await evaluate('Array.from(document.scripts).some(s => s.src.includes("/shout_panel.js"))');
            throw Error('The portal did not schedule the two-minute refresh (script present: ' + present + ')');
        }
        const draft = 'Entwurf ä 😀';
        for (const manual of [true, false]) {
            const started = await evaluate('(' + function (manual, draft) {
                const link = document.querySelector('.plus-shout-refresh');
                if (new URL(link.href).pathname !== location.pathname || link.hash !== '#plus-shout-panel') return false;
                const messages = document.getElementById('plus-shout-messages');
                messages.dataset.beforeRefresh = '1';
                const form = document.getElementById('plus-shout-quick');
                form.elements.message.value = draft;
                form.elements.message.focus();
                window.testPlusShoutForm = form;
                if (manual) link.click();
                else window.testPlusShoutTick();
                return true;
            } + ')(' + manual + ',' + JSON.stringify(draft) + ')');
            if (!started) throw Error('The manual shoutbox fallback left the portal');
            await until(() => evaluate('!document.getElementById("plus-shout-messages").dataset.beforeRefresh'), 'refreshed native shout messages');
            const preserved = await evaluate('(' + function (draft) {
                const form = document.getElementById('plus-shout-quick');
                const link = document.querySelector('#plus-shout-messages a[href*="memberlist.php"]');
                return form === window.testPlusShoutForm && form.elements.message.value === draft
                    && document.activeElement === form.elements.message
                    && (!link || new URL(link.href).pathname === new URL('../memberlist.php', location.href).pathname);
            } + ')(' + JSON.stringify(draft) + ')');
            if (!preserved) throw Error('Refreshing the shoutbox lost the draft, focus or native author link');
        }
    } finally {
        await command('Page.removeScriptToEvaluateOnNewDocument', {identifier: script.identifier});
    }
};
