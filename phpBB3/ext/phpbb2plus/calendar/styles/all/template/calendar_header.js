(function () {
	'use strict';
	var header = document.getElementById('plus-calendar-header');
	if (!header || !window.fetch) { return; }
	var loaded = false;
	function loadDays() {
		if (loaded || !header.open) { return; }
		loaded = true;
		window.fetch(header.dataset.src, {credentials: 'same-origin', cache: 'no-store'})
			.then(function (response) {
				if (!response.ok || response.redirected) { throw new Error(); }
				return response.text();
			})
			.then(function (html) { header.querySelector('.plus-cal-days').innerHTML = html; })
			.catch(function () { loaded = false; });
	}
	header.addEventListener('toggle', loadDays);
	loadDays();
}());
