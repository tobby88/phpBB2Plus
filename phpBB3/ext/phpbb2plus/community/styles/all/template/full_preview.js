(function () {
	'use strict';
	var settings = document.getElementById('plus-full-preview');
	var form = settings && settings.closest('#postform');
	var button = form && form.querySelector('[type="submit"][name="preview"]');
	var position = form && (form.querySelector('#pmheader-postingbox') || form.querySelector('#postingbox'));
	if (!button || !position || !window.fetch || !window.FormData || !window.URL || !window.URLSearchParams ||
		!window.DOMParser || !Element.prototype.replaceChildren || !FormData.prototype.forEach) { return; }

	var panel = document.createElement('section');
	panel.id = 'plus-full-preview-panel';
	button.setAttribute('aria-controls', panel.id);
	panel.setAttribute('aria-label', button.value);
	panel.hidden = true;
	var status = document.createElement('p');
	status.setAttribute('role', 'status');
	var fallback = document.createElement('button');
	fallback.type = 'button';
	fallback.className = 'button2';
	fallback.textContent = settings.dataset.open;
	var content = document.createElement('div');
	content.className = 'plus-full-preview-output';
	panel.append(status, content);
	position.parentElement.insertBefore(panel, position);

	var nativePreview = false;
	var sequence = 0;
	var abort;
	var beforeClick = form.getAttribute('action');
	var ready = performance.now() + 1050; // phpBB requires the form key to be at least one clock second old.
	button.addEventListener('click', function () { beforeClick = form.getAttribute('action'); }, true);
	fallback.addEventListener('click', function () {
		nativePreview = true;
		try { button.click(); } finally { nativePreview = false; }
	});

	function removePreviousPreview() {
		var old = form.querySelector('#preview');
		if (!old || content.contains(old)) { return; }
		// The native preview renders a separate post after the poll when a poll is present.
		var message = old.querySelector('.polls') && old.nextElementSibling;
		if (message && !message.matches('.post')) { message = null; }
		var rule = (message || old).nextElementSibling;
		if (rule && rule.tagName === 'HR') { rule.remove(); }
		if (message) { message.remove(); }
		old.remove();
	}

	form.addEventListener('submit', function (event) {
		if (event.defaultPrevented) { return; }
		var current = ++sequence;
		if (abort) { abort.abort(); }
		if (event.submitter !== button || nativePreview) { return; }
		var address = new URL(form.action, document.baseURI);
		if (address.origin !== location.origin) { return; }
		event.preventDefault();
		// prosilver adds #preview to the form action on each preview click.
		if (address.href === new URL(beforeClick, document.baseURI).href + '#preview') { form.setAttribute('action', beforeClick); }
		address.hash = '';
		var data = new URLSearchParams();
		var selectedFile = false;
		var commands = ['post', 'preview', 'cancel', 'save', 'load', 'confirm', 'delete', 'add_file', 'delete_file',
			'add_to', 'add_bcc', 'remove_u', 'remove_g', 'plus_inline_expand', 'plus_full_preview_request'];
		new FormData(form).forEach(function (value, key) {
			if (typeof value !== 'string') { if (value.name) { selectedFile = true; } return; }
			if (commands.indexOf(key.split('[')[0]) === -1) { data.append(key, value); }
		});
		data.set('preview', '1');
		data.set('plus_full_preview_request', '1');
		panel.hidden = false;
		panel.setAttribute('aria-busy', 'true');
		status.className = '';
		status.textContent = settings.dataset.working;
		fallback.remove();
		var controller = window.AbortController ? new AbortController() : null;
		abort = controller;
		setTimeout(function () {
			if (current !== sequence) { return; }
			fetch(address.href, {method: 'POST', mode: 'same-origin', credentials: 'same-origin', cache: 'no-store',
				redirect: 'error', body: data, signal: controller ? controller.signal : undefined})
				.then(function (reply) {
					if (!reply.ok || reply.headers.get('X-phpBB-Plus-Preview') !== settings.dataset.kind) { throw new Error('No preview'); }
					return reply.text();
				})
				.then(function (html) {
					if (current !== sequence) { return; }
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var result = doc.querySelector('[data-plus-preview-result]');
					if (!result || result.dataset.plusPreviewResult !== settings.dataset.kind || !result.firstElementChild) {
						throw new Error('No preview content');
					}
					// Only the native rendered result enters the page. Keep the author's form,
					// recipients, file controls, edit checksums and current typing in place.
					result.querySelectorAll('script, base, link, meta').forEach(function (node) { node.remove(); });
					result.querySelectorAll('input, select, textarea, button').forEach(function (node) { node.disabled = true; });
					var error = result.querySelector('.error');
					if (error) { error.setAttribute('role', 'alert'); }
					removePreviousPreview();
					content.replaceChildren(document.importNode(result, true));
					status.textContent = selectedFile ? settings.dataset.pending : (error ? '' : settings.dataset.ready);
					if (!selectedFile) { status.className = 'sr-only'; }
					// A full native preview also renews an expired form while retaining the draft.
					if (error && !result.querySelector('#preview')) { status.after(fallback); }
					panel.removeAttribute('aria-busy');
					if (document.activeElement === button) { panel.scrollIntoView({block: 'start'}); }
				})
				.catch(function () {
					if (current !== sequence) { return; }
					removePreviousPreview();
					content.replaceChildren();
					status.textContent = settings.dataset.failed;
					status.after(fallback);
					panel.removeAttribute('aria-busy');
				});
		}, Math.max(0, ready - performance.now()));
	});
}());
