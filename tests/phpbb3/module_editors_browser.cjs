// Reuse the signed-in Chromium from the member tools. Each document is loaded once for both widths.
module.exports = async function ({command, evaluate, until, url}) {
    const pages = [
        ['album/picture/100/manage/edit', '#album-manage'],
        ['album/category/1/upload', '#album-upload'],
        ['knowledge/article/201?page=2', '#kb-article'],
        ['knowledge/article/201/edit', '#kb-editor'],
        ['downloads/category/21/submit', '#dl-submit'],
    ];
    for (const [route, selector] of pages) {
        const destination = new URL('app.php/' + route, url);
        await command('Page.navigate', {url: destination.href});
        await until(() => evaluate('location.href === ' + JSON.stringify(destination.href) +
            ' && document.readyState === "complete"'), route);
        for (const width of [1180, 390]) {
            await command('Emulation.setDeviceMetricsOverride', {width, height: 780, deviceScaleFactor: 1, mobile: false});
            await until(() => evaluate('window.innerWidth === ' + width), 'the module viewport');
            const problem = await evaluate('(' + function (selector, width) {
                const section = document.querySelector(selector);
                if (!section || document.documentElement.scrollWidth > width + 1) return 'missing or overflowing page';
                if (selector === '#kb-article') {
                    const details = section.querySelector('.kb-article-details');
                    const category = details?.querySelector("a[href*='/knowledge/category/8']");
                    const contents = section.querySelector('nav ol');
                    const pages = section.querySelector('.kb-page-links');
                    if (!category || category.textContent !== 'Wissensarchiv' || !details.textContent.includes('Beschreibung') ||
                        !contents || parseFloat(getComputedStyle(contents).paddingInlineStart) < 16 ||
                        pages?.querySelector('[aria-current=page]')?.textContent !== '2' || !pages.querySelector("a[href*='page=1']")) {
                        return 'missing article details, list indentation or page navigation';
                    }
                    return '';
                }
                const fields = section.querySelectorAll('.inputbox:not([type=hidden])');
                for (const field of fields) {
                    if (!field.labels.length || !field.offsetWidth) return 'missing form label or field';
                    if (width === 390 && field.offsetWidth < .90 * field.closest('dd').clientWidth) return 'unused mobile field width';
                }
                const actions = Array.from(section.querySelectorAll('.submit-buttons button, .submit-buttons a, .submit-buttons input:not([type=hidden])'));
                if (!actions.length || actions.some(a => !a.offsetHeight || getComputedStyle(a).borderTopStyle === 'none')) return 'unmarked form actions';
                if (selector === '#kb-editor') {
                    function luminance(rgb) {
                        const levels = rgb.match(/\d+/g).slice(0, 3).map(n => {
                            const value = Number(n) / 255;
                            return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4;
                        });
                        return levels[0] * .2126 + levels[1] * .7152 + levels[2] * .0722;
                    }
                    if (actions.some(a => {
                        const paint = getComputedStyle(a);
                        return (luminance(paint.backgroundColor) + .05) / (luminance(paint.color) + .05) < 4.5;
                    })) return 'illegible editor action';
                    return section.querySelector('[for=kb-description]').textContent === 'Beschreibung' &&
                        actions[0].getBoundingClientRect().right + 3 < actions[1].getBoundingClientRect().left ? '' : 'editor language or button spacing';
                }
                if (selector === '#dl-submit') return section.querySelector('#dl-file') ? '' : 'missing local file input';
                const crumbs = Array.from(document.querySelectorAll('#nav-breadcrumbs a[href]')).map(a => a.pathname);
                const desc = section.querySelector('textarea[name=description]');
                const hint = document.getElementById(desc.getAttribute('aria-describedby'));
                if (!crumbs.some(p => p.endsWith('/album')) || !crumbs.some(p => p.endsWith('/album/category/1')) ||
                    !hint?.textContent.includes(desc.dataset.limit) || !section.querySelector('[type=reset]')) return 'missing album path, text limit or reset';
                return '';
            } + ')(' + JSON.stringify(selector) + ',' + width + ')');
            if (problem) throw Error(route + ' at ' + width + 'px: ' + problem);
        }
        if (selector === '#album-manage' || selector === '#album-upload') {
            await evaluate('const field = document.querySelector("input[name=title]"); field.focus(); field.select()');
            await command('Input.insertText', {text: '😀'.repeat(255)});
            const fits = await evaluate('(' + function () {
                const title = document.querySelector('input[name=title]');
                const desc = document.getElementById('album-description');
                const limit = Number(desc.dataset.limit);
                if (!limit || title.value !== '😀'.repeat(255) || !title.validity.valid) return false;
                function accepts(text) {
                    desc.value = text;
                    desc.dispatchEvent(new Event('input', {bubbles:true}));
                    return desc.validity.valid;
                }
                const complete = '😀'.repeat(limit);
                const ok = accepts(complete) && !accepts(complete + 'x');
                desc.form.querySelector('[type=reset]').click();
                return ok && title.value === title.defaultValue && desc.value === desc.defaultValue;
            } + ')()');
            if (!fits) throw Error(route + ': original Unicode limits or form reset are unavailable');
            await until(() => evaluate('document.getElementById("album-description").validity.valid'), 'resetting album text validation');
        }
    }
    await command('Emulation.clearDeviceMetricsOverride');
};
