(function () {
	'use strict';
	var form = document.getElementById('album-upload');
	if (!form || !window.FormData || !window.XMLHttpRequest || !('upload' in new XMLHttpRequest())) { return; }
	var status = document.getElementById('album-upload-status');
	var bar = status.querySelector('progress');
	var message = status.querySelector('[role="status"]');
	var back = status.querySelector('a');
	var button = form.querySelector('[type="submit"]');
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		if (button.disabled) { return; }
		var body = new FormData(form);
		body.append('file_count', form.querySelector('[type="file"]').files.length);
		var xhr = new XMLHttpRequest();
		button.disabled = true;
		back.hidden = true;
		status.hidden = false;
		bar.hidden = false;
		bar.value = 0;
		message.textContent = form.dataset.sending;
		function processing() {
			bar.value = 100;
			message.textContent = form.dataset.processing;
		}
		function failed(text) {
			button.disabled = false;
			bar.hidden = true;
			message.textContent = text || form.dataset.error;
		}
		xhr.upload.onprogress = function (e) {
			if (!e.lengthComputable || !e.total) { return; }
			bar.value = Math.max(bar.value, Math.floor(100 * e.loaded / e.total));
			if (e.loaded === e.total) { processing(); }
		};
		xhr.upload.onload = processing;
		xhr.onerror = xhr.onabort = function () { failed(); };
		xhr.onload = function () {
			var data;
			try { data = JSON.parse(xhr.responseText); } catch (ignore) { failed(); return; }
			if (xhr.status !== 200 || !data || !data.location) { failed(data && data.message); return; }
			bar.value = 100;
			message.textContent = data.message;
			back.hidden = false;
		};
		xhr.open('POST', form.action);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.send(body);
	});
}());
