(() => {
	'use strict';
	const tools = document.getElementById('plus-quick-tools');
	if (!tools) { return; }
	const form = tools.closest('form');
	const message = form.elements.message;
	const status = tools.querySelector('[role=status]');
	const smilies = tools.querySelector('[data-qr-smilies]');
	if (smilies) {
		smilies.addEventListener('click', event => {
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
			// The native picker uses the opener's named form and field at the current caret.
			window.form_name = form.id;
			window.text_name = message.name;
			const popup = window.open(smilies.href, 'phpbbsmilies', 'width=440,height=350,resizable=yes,scrollbars=yes');
			if (popup) { event.preventDefault(); popup.focus(); }
		});
	}
	function selectedPassage() {
		const selection = window.getSelection();
		if (!selection || selection.rangeCount !== 1 || selection.isCollapsed) { return ''; }
		const range = selection.getRangeAt(0);
		const nearest = node => (node.nodeType === 1 ? node : node.parentElement)?.closest('.postbody .content');
		const body = nearest(range.startContainer);
		if (!body || body !== nearest(range.endContainer) || !body.getClientRects().length ||
			!body.closest('.post')?.querySelector('.post-buttons a[href*="mode=quote"]')) { return ''; }
		return selection.toString().trim();
	}
	const selectionButton = tools.querySelector('[data-qr-selection]');
	if (selectionButton) {
		selectionButton.hidden = false;
		let pressed = '';
		selectionButton.addEventListener('pointerdown', event => {
			pressed = selectedPassage();
			event.preventDefault();
		});
		selectionButton.addEventListener('click', () => {
			const text = selectedPassage() || pressed;
			pressed = '';
			status.textContent = text ? '' : tools.dataset.empty;
			if (!text) { return; }
			message.setRangeText('[quote]' + text + '[/quote]\n', message.selectionStart, message.selectionEnd, 'end');
			message.dispatchEvent(new Event('input', {bubbles:true}));
			message.focus();
		});
	}
	const last = tools.querySelector('[data-qr-last]');
	const include = tools.querySelector('[data-qr-include]');
	if (!last || !include || !window.fetch || !form.requestSubmit) { return; }
	tools.querySelector('[data-qr-option]').hidden = false;
	last.hidden = true;
	let busy = false;
	form.addEventListener('submit', async event => {
		if (busy) { event.preventDefault(); return; }
		if (!include.checked) { return; }
		event.preventDefault();
		busy = true;
		const submitter = event.submitter || form.querySelector('[name=post]');
		try {
			// The native quote composer rechecks access and supplies editable BBCode
			// with its own depth, attachment and displayed-author rules.
			const reply = await fetch(last.href, {mode:'same-origin', credentials:'same-origin', cache:'no-store'});
			if (!reply.ok) { throw Error(); }
			const page = new DOMParser().parseFromString(await reply.text(), 'text/html');
			const quote = page.querySelector('#postform textarea[name=message]')?.value.trimEnd();
			if (!quote || !quote.startsWith('[quote')) { throw Error(); }
			if (!include.checked) { return; }
			message.value = quote + '\n' + message.value;
			message.dispatchEvent(new Event('input', {bubbles:true}));
			include.checked = false;
			status.textContent = '';
		} catch {
			status.textContent = tools.dataset.failed;
			last.hidden = false;
			return;
		} finally {
			busy = false;
		}
		form.requestSubmit(submitter);
	});
})();
