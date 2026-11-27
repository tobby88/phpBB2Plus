(function () {
	'use strict';
	var label = document.querySelector('meta[name="plus-scroll-label"]');
	if (!label || !window.matchMedia) { return; }
	var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
	var controls = [];
	document.querySelectorAll('.plus-scroll').forEach(function (frame) {
		var track = frame.firstElementChild;
		if (!track || !track.classList.contains('plus-scroll-track')) { return; }
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button2 plus-scroll-toggle';
		button.textContent = label.content;
		button.setAttribute('aria-label', label.content);
		function animate(enabled) {
			frame.classList.toggle('plus-scroll-moving', enabled);
			button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
		}
		button.addEventListener('click', function () { animate(button.getAttribute('aria-pressed') !== 'true'); });
		frame.insertBefore(button, track);
		animate(!motion.matches);
		controls.push(animate);
	});
	if (motion.addEventListener) {
		motion.addEventListener('change', function () {
			controls.forEach(function (animate) { animate(!motion.matches); });
		});
	}
}());
