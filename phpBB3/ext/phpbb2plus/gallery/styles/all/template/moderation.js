(function () {
	'use strict';
	var form = document.getElementById('album-mod-select');
	if (!form) { return; }
	var toggles = document.getElementById('album-mod-toggles');
	toggles.hidden = false;
	toggles.addEventListener('click', function (event) {
		if (event.target.tagName !== 'BUTTON') { return; }
		var mode = event.target.value;
		form.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
			box.checked = mode === 'invert' ? !box.checked : mode === 'all';
		});
	});
	var destination = document.getElementById('album-mod-destination');
	if (destination) {
		var action = document.getElementById('album-mod-operation');
		function update() { destination.closest('dl').hidden = action.value !== 'move'; }
		action.addEventListener('change', update);
		update();
	}
}());
