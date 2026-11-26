(function () {
	'use strict';
	var form = document.getElementById('album-upload');
	var status = document.getElementById('album-upload-status');
	if (!form || !status || !window.FormData || !window.XMLHttpRequest || !('upload' in new XMLHttpRequest())) { return; }
	var details = document.getElementById('album-upload-details');
	var bar = document.getElementById('album-transfer-bar');
	var percent = document.getElementById('album-percent');
	var bytes = document.getElementById('album-bytes');
	var speed = document.getElementById('album-speed');
	var elapsed = document.getElementById('album-elapsed');
	var remaining = document.getElementById('album-remaining');
	var message = status.querySelector('[role="status"]');
	var back = status.querySelector('a');
	var fields = form.querySelectorAll('fieldset');
	var request;

	function say(text) {
		if (message.textContent !== text) { message.textContent = text; }
	}
	function clock(seconds) {
		seconds = Math.max(0, Math.floor(seconds));
		function digits(n) { return n < 10 ? '0' + n : String(n); }
		return digits(Math.floor(seconds / 3600)) + ':' + digits(Math.floor(seconds / 60) % 60) + ':' + digits(seconds % 60);
	}
	function number(value, fraction) {
		return value.toLocaleString(document.documentElement.lang || undefined, {maximumFractionDigits: fraction});
	}
	function disable(value) {
		for (var i = 0; i < fields.length; i++) { fields[i].disabled = value; }
	}
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		if (request || form.hidden) { return; }
		var body = new FormData(form);
		body.append('file_count', form.querySelector('[name="photos[]"]').files.length);
		var xhr = new XMLHttpRequest();
		request = xhr;
		var started = Date.now(), lastChange = started, transferredAt = 0, loaded = 0, total = 0;
		var idleMs = Math.max(30, Number(status.dataset.idle) || 30) * 1000;
		disable(true);
		status.hidden = details.hidden = false;
		back.hidden = true;
		status.dataset.state = 'sending';
		bar.removeAttribute('value');
		percent.textContent = '';
		say(form.dataset.sending);

		function render() {
			var now = Date.now(), transferTime = Math.max(0, (transferredAt || now) - started);
			var rate = transferTime > 0 ? loaded * 1000 / transferTime : 0;
			elapsed.textContent = clock((now - started) / 1000);
			bytes.textContent = number(loaded, 0) + (total ? ' / ' + number(total, 0) : '') + ' B';
			speed.textContent = rate > 0 ? number(rate / 1024, 1) + ' KiB/s' : '—';
			remaining.textContent = transferredAt ? clock(0) : (rate && total ? clock(Math.ceil((total - loaded) / rate)) : '—');
			if (now - lastChange >= idleMs) { say(transferredAt ? status.dataset.processWait : status.dataset.transferWait); }
		}
		function record(e) {
			var next = Math.max(loaded, e.loaded || 0);
			if (e.lengthComputable && e.total > 0) { total = e.total; }
			if (next > loaded) { loaded = next; lastChange = Date.now(); say(form.dataset.sending); }
		}
		function processing(e) {
			if (request !== xhr || transferredAt) { return; }
			if (e) { record(e); }
			transferredAt = lastChange = Date.now();
			status.dataset.state = 'processing';
			bar.value = 100;
			percent.textContent = '100 %';
			say(form.dataset.processing);
			render();
		}
		function fail(text) {
			if (request !== xhr) { return; }
			clearInterval(timer);
			request = null;
			status.dataset.state = 'error';
			details.hidden = true;
			disable(false);
			say(text || form.dataset.error);
		}
		render();
		var timer = setInterval(render, 1000);
		xhr.upload.onprogress = function (e) {
			if (request !== xhr || transferredAt) { return; }
			record(e);
			if (total && loaded >= total) { processing(); return; }
			if (total) {
				bar.value = Math.max(bar.value, Math.min(99, Math.floor(100 * loaded / total)));
				percent.textContent = bar.value + ' %';
			}
			render();
		};
		xhr.upload.onload = processing;
		xhr.onerror = xhr.onabort = xhr.ontimeout = function () { fail(); };
		xhr.onload = function () {
			if (request !== xhr) { return; }
			var data;
			try { data = JSON.parse(xhr.responseText); } catch (ignore) { fail(); return; }
			if (xhr.status !== 200 || !data || !data.location || typeof data.message !== 'string') { fail(data && data.message); return; }
			processing();
			clearInterval(timer);
			render();
			request = null;
			status.dataset.state = 'complete';
			form.hidden = true;
			details.hidden = status.dataset.close === '1';
			back.hidden = false;
			say(data.message);
		};
		try {
			xhr.open('POST', form.action);
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			xhr.send(body);
		} catch (error) { fail(); }
	});
}());
