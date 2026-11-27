(function () {
	'use strict';
	var panel = document.getElementById('plus-shout-panel');
	if (!panel || !window.fetch || !window.DOMParser || !window.URL) { return; }
	var refreshLink = panel.querySelector('.plus-shout-refresh');
	var error = document.getElementById('plus-shout-refresh-error');
	var lastAttempt = Date.now();
	var loading = false;

	function refresh(manual) {
		var messages = document.getElementById('plus-shout-messages');
		if (loading || !messages || panel.hidden || (!manual && (document.hidden || messages.contains(document.activeElement)))) { return; }
		loading = true;
		lastAttempt = Date.now();
		window.fetch(refreshLink.dataset.url, {credentials: 'same-origin', cache: 'no-store', redirect: 'error'})
			.then(function (response) {
				if (response.status === 403) {
					panel.hidden = true;
					window.clearInterval(timer);
					return;
				}
				if (!response.ok || new URL(response.url).origin !== window.location.origin) { throw new Error(); }
				return response.text().then(function (html) {
					var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('plus-shout-messages');
					if (!fresh) { throw new Error(); }
					// Native author, smiley and BBCode links were rendered relative to the fragment URL.
					fresh.querySelectorAll('[href], [src]').forEach(function (node) {
						['href', 'src'].forEach(function (attr) {
							var value = node.getAttribute(attr);
							if (value && !/^(?:[a-z][a-z\d+.-]*:|\/|#)/i.test(value)) {
								node.setAttribute(attr, new URL(value, response.url).href);
							}
						});
					});
					messages.parentNode.replaceChild(document.importNode(fresh, true), messages);
					error.hidden = true;
				});
			}).catch(function () {
				if (manual) { error.hidden = false; }
			}).then(function () { loading = false; });
	}
	refreshLink.addEventListener('click', function (event) {
		if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
		event.preventDefault();
		refresh(true);
	});
	var timer = window.setInterval(function () { refresh(false); }, 120000);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && Date.now() - lastAttempt >= 120000) { refresh(false); }
	});
}());
