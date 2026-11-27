(function () {
	'use strict';
	var form = document.getElementById('plus-ct-files-advance');
	if (!form) { return; }
	// phpBB accepts its native form keys from the next clock second onwards.
	var timer = window.setTimeout(function () { form.submit(); }, 1200);
	document.addEventListener('submit', function () { window.clearTimeout(timer); });
}());
