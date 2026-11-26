// Reuse the signed-in member's browser so the converted board's profile permissions apply.
module.exports = async function ({command, evaluate, until, url}) {
    const profile = new URL('memberlist.php?mode=viewprofile&u=2', url);
    await command('Page.navigate', {url: profile.href});
    await until(() => evaluate('location.pathname.endsWith("/memberlist.php") && document.readyState === "complete"'),
                'the signed-in profile');
    if (!await evaluate('document.querySelector("#viewprofile .signature.standalone")?.textContent.includes("Signatur aus dem Testforum.")')) {
        throw Error('The converted profile signature is missing');
    }
    for (const width of [1180, 390]) {
        await command('Emulation.setDeviceMetricsOverride', {width, height: 760, deviceScaleFactor: 1, mobile: false});
        await until(() => evaluate('window.innerWidth === ' + width), 'the member profile viewport');
        const fits = await evaluate('(' + function () {
            const signature = document.querySelector('#viewprofile .signature.standalone');
            const panel = signature.closest('.inner').getBoundingClientRect();
            const body = signature.parentElement.getBoundingClientRect();
            return Math.abs(panel.left - body.left) < 2 && Math.abs(panel.right - body.right) < 2
                && document.documentElement.scrollWidth <= window.innerWidth + 1;
        } + ')()');
        if (!fits) throw Error('The profile signature no longer fills its panel at ' + width + 'px');
    }
    await command('Emulation.clearDeviceMetricsOverride');
};
