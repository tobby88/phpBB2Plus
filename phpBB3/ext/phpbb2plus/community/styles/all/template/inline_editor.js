(function () {
	'use strict';
	var template = document.getElementById('plus-inline-editor');
	if (!template || !template.content || !window.fetch || !window.FormData || !window.URL || !window.DOMParser) { return; }

	function show(slot, link, original, documentText, address, focusSubject) {
		var page = new DOMParser().parseFromString(documentText, 'text/html');
		var source = page.getElementById('postform');
		if (!source) { throw new Error('No editing form'); }
		var action = new URL(source.getAttribute('action'), address);
		var sourceSubject = source.elements.namedItem('subject');
		var sourceMessage = source.elements.namedItem('message');
		if (action.origin !== location.origin || action.pathname !== link.pathname || action.searchParams.get('mode') !== 'edit' ||
			action.searchParams.get('p') !== slot.dataset.editPost || !sourceSubject || !sourceMessage || !source.elements.namedItem('form_token')) {
			throw new Error('The native editing form changed');
		}

		var form = template.content.querySelector('form').cloneNode(true);
		form.action = action.href;
		form.enctype = source.enctype;
		var preview = new URL(action.href);
		preview.hash = 'preview';
		form.querySelector('[name="preview"]').formAction = preview.href;
		// Keep the successful controls of the issued native form, including attachments, poll choices,
		// edit checksums and extension fields. Only the subject and message get editable copies.
		new FormData(source).forEach(function (value, name) {
			if (name === 'message' || name === 'subject' || typeof value !== 'string') { return; }
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = name;
			hidden.value = value;
			form.appendChild(hidden);
		});
		form.elements.subject.value = sourceSubject.value;
		if (sourceSubject.maxLength >= 0) { form.elements.subject.maxLength = sourceSubject.maxLength; }
		form.elements.message.value = sourceMessage.value;
		if (slot.hasAttribute('data-title-only')) {
			form.elements.message.closest('label').hidden = true;
			form.querySelector('legend').textContent = link.textContent.trim();
		}
		slot.appendChild(form);
		slot.hidden = false;
		if (original) { original.classList.add('plus-inline-original-hidden'); }
		form.elements[focusSubject ? 'subject' : 'message'].focus();
		form.querySelector('[data-inline-cancel]').addEventListener('click', function () {
			slot.hidden = true;
			form.remove();
			if (original) { original.classList.remove('plus-inline-original-hidden'); }
			link.focus();
		});

		// phpBB rejects a form key from the same clock second in which it was issued.
		var readyAt = performance.now() + 1050;
		var waiting = false;
		form.addEventListener('submit', function (event) {
			if (waiting) { event.preventDefault(); return; }
			var delay = readyAt - performance.now();
			if (delay > 0) {
				event.preventDefault();
				waiting = true;
				var button = event.submitter || form.querySelector('[name="post"]');
				setTimeout(function () {
					waiting = false;
					if (form.isConnected) { button.click(); }
				}, delay + 5);
			}
		});
	}

	function openEditor(event, link, focusSubject) {
		var listing = link.closest('.plus-list-edit');
		var row = listing || link.closest('.post[id]');
		var slot = row && row.querySelector('.plus-inline-slot');
		if (!slot || link.origin !== location.origin || new URL(slot.dataset.editUrl, document.baseURI).href !== link.href) { return; }
		var original = (listing || slot).parentElement.querySelector(':scope > .content');
		focusSubject = focusSubject || slot.hasAttribute('data-title-only');
		var current = slot.querySelector('form');
		if (current) { event.preventDefault(); current.elements[focusSubject ? 'subject' : 'message'].focus(); return; }
		// A second click while loading always opens the ordinary native page.
		if (link.getAttribute('aria-busy') === 'true') { return; }
		event.preventDefault();
		link.setAttribute('aria-busy', 'true');
		fetch(link.href, {credentials: 'same-origin', mode: 'same-origin', cache: 'no-store'})
			.then(function (reply) {
				if (!reply.ok) { throw new Error('The edit page was unavailable'); }
				return reply.text().then(function (text) { show(slot, link, original, text, reply.url, focusSubject); });
			})
			.catch(function () { location.assign(link.href); })
			.then(function () { link.removeAttribute('aria-busy'); });
	}

	function ordinary(event) {
		return !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && event.target.closest;
	}

	document.addEventListener('click', function (event) {
		if (!ordinary(event)) { return; }
		var link = event.target.closest('.post-buttons a[href], .plus-list-edit-link');
		if (link) { openEditor(event, link, false); }
	});

	document.addEventListener('dblclick', function (event) {
		if (!ordinary(event) || event.target.closest('a, button, input, textarea, select, form')) { return; }
		// The old gesture was on the title cell. Actual navigation links keep their single-click behavior.
		var container = event.target.closest('.list-inner, .search .postbody h3');
		if (!container) { return; }
		var row = container.closest('.list-inner, .postbody');
		var link = row.querySelector('.plus-list-edit-link');
		if (link) { openEditor(event, link, true); }
	});
}());
