(() => {
	'use strict';
	let popup;
	let target;
	for (const link of document.querySelectorAll('[data-sign-target]')) {
		link.addEventListener('click', (event) => {
			if (event.ctrlKey || event.shiftKey || event.altKey || event.metaKey) { return; }
			const field = document.getElementById(link.dataset.signTarget) || link.closest('form')?.elements.signature;
			if (!(field instanceof HTMLTextAreaElement)) { return; }
			const opened = window.open(link.href, 'plusSmilieCreator', 'popup,width=640,height=680,resizable=yes,scrollbars=yes');
			if (opened) { event.preventDefault(); target = field; popup = opened; popup.focus(); }
		});
	}
	window.addEventListener('message', (event) => {
		if (!popup || !target || !target.isConnected || event.source !== popup || event.origin !== location.origin) { return; }
		const data = event.data;
		if (!data || data.type !== 'plus-smilie-sign' || typeof data.markup !== 'string' || data.markup.length > 2048) { return; }
		target.setRangeText(data.markup, target.selectionStart, target.selectionEnd, 'end');
		target.dispatchEvent(new Event('input', {bubbles: true}));
		target.focus();
	});
	const insert = document.getElementById('plus-sign-insert');
	if (insert && window.opener) {
		insert.hidden = false;
		insert.addEventListener('click', () => {
			window.opener.postMessage({type: 'plus-smilie-sign', markup: document.getElementById('plus-sign-code').value}, location.origin);
			window.close();
		});
	}
})();
