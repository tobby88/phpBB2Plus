async function exerciseFullPreviews(frame, load, next, step, expect, renderedText) {
    function update(doc, form, click, test) {
        return new Promise((resolve, reject) => {
            const output = form.querySelector('.plus-full-preview-output');
            const timer = setTimeout(() => {
                observer.disconnect();
                const status = form.querySelector('[role="status"]')?.textContent || '';
                reject(Error('No native preview update: ' + status + ' ' + (output?.textContent || '').trim().slice(0, 100)));
            }, 9000);
            const observer = new MutationObserver(() => {
                if (frame.contentDocument === doc && test(output)) {
                    observer.disconnect();
                    clearTimeout(timer);
                    resolve(output);
                }
            });
            observer.observe(form, {subtree: true, childList: true, attributes: true});
            click();
        });
    }

    for (const kind of ['post', 'pm', 'draft']) {
        await step('full preview ' + kind);
        const url = kind === 'draft' ? 'posting.php?mode=post&f=1&d=' + storedDraft :
            (kind === 'pm' ? 'ucp.php?i=pm&mode=compose&action=post&u=2' : 'posting.php?mode=edit&p=' + post);
        const doc = await load(url);
        const form = doc.querySelector('#postform');
        const button = form.querySelector('[type="submit"][name="preview"]');
        const originalAction = form.getAttribute('action');
        const language = form.querySelector('#plus-full-preview');
        expect(language && language.dataset.kind === (kind === 'pm' ? 'pm' : 'post'), 'Full editor preference missing');
        if (kind === 'draft') {
            expect(form.elements.message.value === '[b]Earlier draft[/b]', 'A saved draft was not loaded');
        }
        const recipients = [...form.querySelectorAll('[name^="address_list["]')].map(n => [n.name, n.value]);
        if (kind === 'pm') { expect(recipients.some(row => row[0] === 'address_list[u][2]'), 'Missing native PM recipient'); }
        const attached = form.querySelector('input[name^="attachment_data["]');
        let file;
        if (kind === 'post') {
            expect(attached, 'The synthetic GIF did not reach the native editor');
            // plupload removes the native basic uploader. Restore the real fallback control
            // from the same page to check browsers with a selected, not-yet-added local file.
            file = form.querySelector('[name="fileupload"]');
            if (!file) {
                const source = await frame.contentWindow.fetch(doc.URL).then(r => r.text());
                const basic = new DOMParser().parseFromString(source, 'text/html').querySelector('input[name="fileupload"]');
                expect(basic, 'No native fallback file control');
                file = doc.importNode(basic, true);
                form.querySelector('#attach-panel').append(file);
            }
            const files = new frame.contentWindow.DataTransfer();
            files.items.add(new frame.contentWindow.File(['selected'], 'pending-preview.gif', {type: 'image/gif'}));
            file.files = files.files;
        }
        form.elements.subject.value = 'Vorschautitel Ä';
        form.elements.message.value = '[b]Vorschau Ö[/b] & Grüße 🌱';
        if (form.elements.disable_bbcode) { form.elements.disable_bbcode.checked = false; }
        const poll = kind === 'draft' && form.elements.poll_title && form.elements.poll_option_text;
        if (poll) {
            form.elements.poll_title.value = 'Umfrage zur Vorschau';
            poll.value = 'Erste Auswahl\nZweite Auswahl';
        }
        const rendered = await update(doc, form, () => {
            button.click();
            form.elements.message.value = 'Weitergeschrieben ' + kind + ' 🌱';
        }, output => !!output?.querySelector('#preview'));
        expect(renderedText(rendered).includes('Vorschau Ö & Grüße 🌱') && rendered.querySelector('strong,b') &&
            form.elements.message.value === 'Weitergeschrieben ' + kind + ' 🌱', 'Preview navigation lost formatting or later typing');
        expect(form.getAttribute('action') === originalAction, 'Preview changed the subsequent posting action');
        if (kind === 'post') {
            expect(file.files[0].name === 'pending-preview.gif' && attached.isConnected && rendered.querySelector('.attachbox') &&
                form.querySelector('[role="status"]').textContent === language.dataset.pending, 'Preview replaced a pending file or its saved attachment');
        }
        if (kind === 'pm') {
            const current = [...form.querySelectorAll('[name^="address_list["]')].map(n => [n.name, n.value]);
            expect(JSON.stringify(recipients) === JSON.stringify(current), 'Preview replaced the PM recipient controls');
        }
        if (poll) {
            expect(rendered.querySelector('.polls') && rendered.querySelector('.postbody') && rendered.textContent.includes('Zweite Auswahl'),
                'A poll preview omitted the poll or the message');
        }
        const token = form.elements.form_token;
        const issued = token.value;
        token.value = 'expired';
        await update(doc, form, () => button.click(), o => !!o?.querySelector('.error') && !o.querySelector('#preview'));
        const recovery = form.querySelector('.plus-full-preview-output').parentElement.querySelector('button[type="button"]');
        expect(form.elements.message.value === 'Weitergeschrieben ' + kind + ' 🌱' && recovery, 'Validation discarded the draft or its recovery action');
        if (kind === 'pm') {
            const renewed = await next(() => recovery.click());
            const fresh = renewed.querySelector('#postform');
            expect(fresh && fresh.elements.message.value === 'Weitergeschrieben pm 🌱' && fresh.elements.form_token.value !== 'expired' &&
                fresh.querySelector('[name="address_list[u][2]"]'), 'Full-page recovery lost the PM or did not renew its form key');
        } else { token.value = issued; }
    }
}
