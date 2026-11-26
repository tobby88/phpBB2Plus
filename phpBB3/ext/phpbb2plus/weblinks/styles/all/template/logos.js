(function () {
	'use strict';
	document.querySelectorAll('.wl-rotation').forEach(function (widget) {
		var template = widget.querySelector('template');
		if (!template || !template.content) { return; }
		var items = widget.querySelector('.wl-items');
		var button = widget.querySelector('.wl-pause');
		var waiting = Array.from(template.content.children);
		var next = 0, timer, away = false, hovered = false;
		var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
		var paused = motion.matches;
		var pauseLabel = button.title;

		function schedule() {
			window.clearTimeout(timer);
			if (paused || hovered || away || document.hidden || widget.contains(document.activeElement)) { return; }
			timer = window.setTimeout(function () {
				var appearing = waiting[next];
				waiting[next] = items.removeChild(items.firstElementChild);
				items.appendChild(appearing);
				next = (next + 1) % waiting.length;
				schedule();
			}, Number(widget.dataset.wlInterval));
		}
		function state() {
			var label = paused ? button.dataset.run : pauseLabel;
			button.title = label;
			button.setAttribute('aria-label', label);
			button.firstElementChild.textContent = paused ? '▶' : 'Ⅱ';
			schedule();
		}
		button.hidden = false;
		button.addEventListener('click', function () { paused = !paused; state(); });
		widget.addEventListener('mouseenter', function () { hovered = true; schedule(); });
		widget.addEventListener('mouseleave', function () { hovered = false; schedule(); });
		widget.addEventListener('focusin', schedule);
		widget.addEventListener('focusout', function () { window.setTimeout(schedule, 0); });
		document.addEventListener('visibilitychange', schedule);
		window.addEventListener('pagehide', function () { away = true; schedule(); });
		window.addEventListener('pageshow', function () { away = false; schedule(); });
		if (motion.addEventListener) {
			motion.addEventListener('change', function () { paused = motion.matches; state(); });
		}
		state();
	});
}());
