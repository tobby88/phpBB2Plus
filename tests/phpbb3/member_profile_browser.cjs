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
    const listing = new URL('memberlist.php?first_char=m', url);
    await command('Page.navigate', {url: listing.href});
    await until(() => evaluate('location.search.includes("first_char=m") && document.readyState === "complete"'), 'the member list');
    for (const width of [390, 1180]) {
        await command('Emulation.setDeviceMetricsOverride', {width, height: 760, deviceScaleFactor: 1, mobile: false});
        await until(() => evaluate('window.innerWidth === ' + width), 'the member list viewport');
        const visible = await evaluate('(' + function () {
            const link = Array.from(document.querySelectorAll('td.plus-member-album a')).find(
                a => new URL(a.href).pathname.endsWith('/album/member/2'));
            if (!link || !link.getAttribute('aria-label').includes('ModernMember')) return false;
            const box = link.getBoundingClientRect();
            return box.width > 0 && box.height > 0 && box.left >= 0 && box.right <= window.innerWidth;
        } + ')()');
        if (!visible) throw Error('The personal album link is unavailable at ' + width + 'px');
    }
    await command('Emulation.clearDeviceMetricsOverride');
};
