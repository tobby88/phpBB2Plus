// Follow the original links through the native redirects in an existing local browser.
module.exports = async function ({command, evaluate, until, url}) {
    await command('Emulation.setDeviceMetricsOverride', {width: 390, height: 790, deviceScaleFactor: 1, mobile: false});
    try {
        for (const [path, number, labels] of [
            ['faq.php#20', '20', ['Umfrage', 'poll']],
            ['faq.php?mode=bbcode#9', '9', ['Bild', 'image']],
            ['faq.php#47', '47', ['herunterladen', 'download']]
        ]) {
            await command('Page.navigate', {url: new URL(path, url).href});
            await until(() => evaluate('document.readyState === "complete" && location.pathname.includes("/help/") && location.hash === '
                + JSON.stringify('#' + number)), 'the native help bookmark ' + number);
            const answer = await evaluate('(' + function (id) {
                const heading = document.getElementById(id)?.closest('dl')?.querySelector('dt[id]');
                if (!heading) return null;
                const box = heading.getBoundingClientRect();
                return {title: heading.textContent.toLowerCase(), visible: box.top >= -1 && box.bottom <= innerHeight};
            } + ')(' + JSON.stringify(number) + ')');
            if (!answer?.visible || !labels.some(label => answer.title.includes(label.toLowerCase()))) {
                throw Error('The historic help bookmark does not bring question ' + number + ' into view');
            }
        }
    } finally {
        await command('Emulation.clearDeviceMetricsOverride');
    }
};
