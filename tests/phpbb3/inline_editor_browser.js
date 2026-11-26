(async function () {
    const frame = document.getElementById('board');
    const output = document.querySelector('output');
    let stage = 'login';
    const next = (act) => new Promise(resolve => {
        frame.addEventListener('load', () => resolve(frame.contentDocument), {once: true});
        act();
    });
    const load = path => next(() => { frame.src = path; });
    const waitKey = () => new Promise(resolve => setTimeout(resolve, 1100));
    const report = result => fetch(location.pathname, {method: 'POST', body: new URLSearchParams({result}), cache: 'no-store'});
    async function step(name) { stage = name; await report('stage ' + name); }
    function expect(ok, why) { if (!ok) throw Error(why); }
    function renderedText(element) {
        const copy = element.cloneNode(true);
        for (const image of copy.querySelectorAll('img[alt]')) { image.replaceWith(image.alt); }
        return copy.textContent;
    }
    function attachedForm(slot, action) {
        return new Promise(resolve => {
            const observer = new MutationObserver(() => {
                const form = slot.querySelector('form');
                if (form) { observer.disconnect(); resolve(form); }
            });
            observer.observe(slot, {childList: true});
            action();
        });
    }
    async function openEditor() {
        const doc = frame.contentDocument;
        const row = doc.getElementById('p' + post);
        const slot = row && row.querySelector('.plus-inline-slot');
        expect(slot, 'Missing authorized inline target (' + doc.location.pathname + ', row ' + !!row + ', signed in ' + !!doc.querySelector('[href*="mode=logout"]') + ')');
        const link = [...row.querySelectorAll('.post-buttons a')].find(a => a.href === new URL(slot.dataset.editUrl, doc.baseURI).href);
        expect(link && new URL(link.href).searchParams.get('mode') === 'edit', 'Missing ordinary edit link');
        const form = await attachedForm(slot, () => link.click());
        expect(!slot.hidden && new URL(doc.URL).pathname.endsWith('/viewtopic.php'), 'Editor left the topic');
        return form;
    }
    async function listedEditor(path, gesture) {
        const doc = await load(path);
        const link = doc.querySelector('a.plus-list-edit-link[data-edit-post="' + post + '"]');
        expect(link && link.tabIndex >= 0 && new URL(link.href).searchParams.get('p') === String(post), 'Missing keyboard-accessible listed edit link: ' + path);
        link.focus();
        expect(doc.activeElement === link, 'Listed link is not focusable');
        const slot = link.parentElement.querySelector('.plus-inline-slot');
        const titleOnly = slot.hasAttribute('data-title-only');
        const titleCell = link.closest('.list-inner') || link.closest('.postbody').querySelector('h3');
        const form = await attachedForm(slot, () => gesture ? titleCell.dispatchEvent(new MouseEvent('dblclick', {bubbles: true, cancelable: true})) : link.click());
        expect(doc.activeElement === form.elements[gesture || titleOnly ? 'subject' : 'message'], 'Incorrect field focused from ' + path);
        expect(!!form.elements.message.getClientRects().length !== titleOnly, 'Wrong editing controls for ' + path);
        return form;
    }
    const thread = 'viewtopic.php?t=' + topic;
    try {
        await step('login');
        let doc = await load('index.php');
        for (let attempt = 0; attempt < 2; attempt++) {
            doc = await load('ucp.php?mode=login');
            const signin = doc.querySelector('#login');
            signin.elements.username.value = 'HistoricMember';
            signin.elements.password.value = password;
            await waitKey();
            doc = await next(() => signin.querySelector('[name="login"]').click());
            if (doc.querySelector('[href*="mode=logout"]')) break;
        }
        expect(doc.querySelector('[href*="mode=logout"]'), 'Native login did not establish the synthetic session (' +
            (doc.querySelector('.error,#message p')?.textContent || doc.title).trim().slice(0,140) + ')');
        await exerciseFullPreviews(frame, load, next, step, expect, renderedText);
        await step('cancel');
        doc = await load(thread);
        frame.style.width = '390px';
        let form = await openEditor();
        expect(form.elements.message.value.includes('[b]Ärger[/b]') && form.elements.subject.value === 'Inline editor fixture', 'Raw BBCode or initial subject changed');
        expect(form.getBoundingClientRect().width <= frame.clientWidth && form.scrollWidth <= form.clientWidth + 1, 'Mobile editor overflowed');
        form.elements.message.value = 'Discarded local change';
        form.querySelector('[data-inline-cancel]').click();
        expect(!doc.querySelector('.plus-inline-form') && doc.querySelector('#p' + post + ' .content').textContent.includes('Original'), 'Cancel hid or changed the post');
        await step('listed post');
        form = await listedEditor('search.php?author=HistoricMember&sr=posts&ch=5', false);
        expect(form.elements.message.value === 'Original [b]Ärger[/b] & Grüße.', 'The search excerpt replaced the full BBCode source');
        form.elements.message.value = 'Entwurf aus der Suche [i]Ä & Ö[/i]';
        doc = await next(() => form.querySelector('[name="plus_inline_expand"]').click());
        expect(doc.querySelector('#postform [name="message"]').value === 'Entwurf aus der Suche [i]Ä & Ö[/i]' &&
            doc.body.textContent.includes('inline-edit-fixture.gif'), 'Full editor lost the search draft or its attachment');
        await step('listed topic');
        form = await listedEditor('search.php?author=HistoricMember&sr=topics', true);
        expect(form.elements.subject.value === 'Inline editor fixture' && form.elements.message.value.includes('[b]Ärger[/b]'), 'Topic results did not edit the first post');
        form.elements.subject.value = 'Titelentwurf – Öl';
        doc = await next(() => form.querySelector('[name="plus_inline_expand"]').click());
        expect(doc.querySelector('#postform [name="subject"]').value === 'Titelentwurf – Öl' &&
            doc.querySelector('#postform [name="message"]').value === 'Original [b]Ärger[/b] & Grüße.', 'A title handoff replaced the message');
        await step('expand');
        doc = await load('viewforum.php?f=1');
        const cell = doc.querySelector('a.plus-list-edit-link[data-edit-post="' + post + '"]').closest('.list-inner');
        doc = await next(() => cell.querySelector('a.topictitle').click());
        expect(doc.getElementById('p' + post), 'An ordinary click on a topic title stopped opening the topic');
        form = await openEditor();
        const handoff = 'Ungespeichert [i]Öl & Grüße[/i]\nZweite Zeile 📎 &amp; <3';
        form.elements.subject.value = 'Übergabe & Entwurf';
        form.elements.message.value = handoff;
        await waitKey();
        doc = await next(() => form.querySelector('[name="plus_inline_expand"]').click());
        form = doc.querySelector('#postform');
        expect(form && form.elements.message.value === handoff && form.elements.subject.value === 'Übergabe & Entwurf', 'Full editor lost current typing');
        expect(form.querySelector('[name^="attachment_data["]') && doc.body.textContent.includes('inline-edit-fixture.gif') &&
            form.querySelector('[name="plus_description"]').value === 'Inline description', 'Full editor lost attachment or topic metadata');
        await step('preview');
        await load(thread);
        form = await openEditor();
        form.elements.message.value = '[b]Vorschau Ä🌱[/b]';
        await waitKey();
        doc = await next(() => form.querySelector('[name="preview"]').click());
        expect([...doc.querySelectorAll('#preview strong, #preview b')].some(n => renderedText(n).includes('Vorschau Ä🌱')),
            'Native preview did not format the draft (' + (doc.querySelector('#preview .content, .error, #message p')?.textContent || doc.title).trim().slice(0,140) + ')');
        await step('validation');
        await load(thread);
        form = await openEditor();
        form.elements.message.value = '';
        await waitKey();
        doc = await next(() => form.querySelector('[name="post"]').click());
        expect(doc.querySelector('#postform .error') && doc.querySelector('#postform [name="message"]').value === '', 'Native validation lost the failing draft');
        await step('listed forum');
        form = await listedEditor('viewforum.php?f=1', true);
        expect(form.elements.subject.value === 'Inline editor fixture', 'A prior preview or handoff saved a title');
        form.elements.subject.value = 'Listentitel Ä🌱';
        doc = await next(() => form.querySelector('[name="post"]').click());
        expect(renderedText(doc.getElementById('p' + post)).includes('Listentitel Ä🌱') &&
            doc.getElementById('p' + post).querySelector('.content').textContent.includes('Original Ärger & Grüße.'),
            'Saving only the title changed the message or did not reach its native topic');
        await step('save');
        await load(thread);
        form = await openEditor();
        expect(form.elements.message.value.includes('Original') && form.elements.subject.value === 'Listentitel Ä🌱', 'The list edit did not retain the complete post');
        form.elements.subject.value = 'Änderung – 📘';
        form.elements.message.value = '[b]Grüße[/b] & Ω\nZweite Zeile 🌱';
        await waitKey();
        doc = await next(() => form.querySelector('[name="post"]').click());
        const row = doc.getElementById('p' + post);
        expect(row && [...row.querySelectorAll('strong,b')].some(n => n.textContent === 'Grüße') &&
            renderedText(row).includes('Zweite Zeile 🌱') && renderedText(row).includes('Änderung – 📘'), 'Saved post or first-post title did not render correctly');
        output.textContent = 'OK';
    } catch (error) { output.textContent = 'FAIL ' + stage + ' / ' + error.message; }
    await report(output.textContent);
    window.close();
}());
