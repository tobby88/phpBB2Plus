<style type="text/css">
.uploadProgressBox { margin: 8px auto; width: 96%; }
.uploadProgressBody { padding: 14px; text-align: center; }
.uploadProgressTrack { background: #d7e2ec; border: 1px solid #7d9db7; height: 22px; overflow: hidden; padding: 2px; position: relative; text-align: left; }
.uploadProgressFill { background: #006699; height: 22px; min-width: 0; transition: width .25s ease; width: 0; }
.uploadProgressPercent { color: #ffffff; font-weight: bold; left: 0; line-height: 22px; position: absolute; right: 0; text-align: center; top: 2px; }
.uploadProgressStatus { margin-top: 10px; min-height: 18px; }
.uploadProgressDetails { line-height: 1.5; margin-top: 8px; }
</style>

<table class="forumline uploadProgressBox" border="0" cellspacing="1" cellpadding="3">
	<tr>
		<th class="thTop">{L_UPLOAD_IN_PROGRESS}</th>
	</tr>
	<tr>
		<td class="row1 uploadProgressBody">
			<div class="uploadProgressTrack">
				<div id="progress1" class="uploadProgressFill"></div>
				<div id="progressPercent" class="uploadProgressPercent">0%</div>
			</div>
			<div id="progressStatus" class="gen uploadProgressStatus">{L_UPLOAD_WAITING}</div>
			<div id="progress2" class="gensmall uploadProgressDetails">
				0 / 0 KB<br />
				{L_TIME_ELAPSED}: 00:00:00 &nbsp;·&nbsp; {L_TIME_REMAINING}: 00:00:00
			</div>
		</td>
	</tr>
</table>
<div class="gensmall" style="text-align:center">Upload Powered by Nuffload {L_NUFFLOAD_VERSION}</div>

<script type="text/javascript">
// <![CDATA[
(function () {
	var statusUrl = '{STATUS_URL}'.replace(/&amp;/g, '&');
	var uploadSession = {UPLOAD_SESSION_JSON};
	var storageKey = 'albumUploadProgress:' + uploadSession;
	var closeOnFinish = {CLOSE_ON_FINISH};
	var maxIdlePolls = {MAX_IDLE_POLLS};
	var lastCurrent = -1;
	var lastState = '';
	var idlePolls = 0;
	var finished = false;
	var clientProgress = null;
	var labels = {
		waiting: {L_UPLOAD_WAITING_JSON},
		processing: {L_UPLOAD_PROCESSING_JSON},
		complete: {L_UPLOAD_COMPLETE_JSON},
		stalled: {L_UPLOAD_STALLED_JSON}
	};

	function formatKb(bytes) {
		return (Number(bytes || 0) / 1024).toFixed(1);
	}

	function formatSeconds(seconds) {
		seconds = Math.max(0, parseInt(seconds, 10) || 0);
		var hours = Math.floor(seconds / 3600);
		var minutes = Math.floor((seconds % 3600) / 60);
		var remainder = seconds % 60;
		return (hours < 10 ? '0' : '') + hours + ':' +
			(minutes < 10 ? '0' : '') + minutes + ':' +
			(remainder < 10 ? '0' : '') + remainder;
	}

	function acceptClientProgress(data) {
		if (!data || data.type !== 'album-upload-progress' || data.sessionid !== uploadSession) {
			return;
		}
		clientProgress = data;
	}

	function readStoredProgress() {
		try {
			var stored = window.localStorage.getItem(storageKey);
			if (stored) {
				acceptClientProgress(JSON.parse(stored));
			}
		} catch (ignore) { ignore = null; }
	}

	function clientProgressData() {
		if (!clientProgress || (new Date().getTime() - Number(clientProgress.timestamp || 0)) > 7200000) {
			return null;
		}
		var total = Number(clientProgress.total || 0);
		var current = Number(clientProgress.loaded || 0);
		var percent = total > 0 ? Math.floor((current / total) * 100) : 0;
		return {
			state: clientProgress.state === 'processing' ? 'processing' : clientProgress.state,
			percent: Math.max(0, Math.min(clientProgress.state === 'processing' ? 100 : 99, percent)),
			current: current,
			total: total,
			speed_kb: Number(clientProgress.speed_kb || 0),
			elapsed: formatSeconds(clientProgress.elapsed_seconds),
			remaining: formatSeconds(clientProgress.remaining_seconds),
			done: false
		};
	}

	function render(data) {
		var percent = Math.max(0, Math.min(100, parseInt(data.percent, 10) || 0));
		document.getElementById('progress1').style.width = percent + '%';
		document.getElementById('progressPercent').innerHTML = percent + '%';
		var status = labels.waiting;
		if (data.state === 'uploading') {
			status = Number(data.speed_kb || 0).toFixed(2) + ' KB/s';
		} else if (data.state === 'processing') {
			status = labels.processing;
		} else if (data.state === 'complete') {
			status = labels.complete;
		} else if (data.state === 'error') {
			status = labels.stalled;
		}
		document.getElementById('progressStatus').innerHTML = status;
		document.getElementById('progress2').innerHTML =
			formatKb(data.current) + ' / ' + formatKb(data.total) + ' KB<br />' +
			'{L_TIME_ELAPSED}: ' + data.elapsed + ' &nbsp;·&nbsp; {L_TIME_REMAINING}: ' + data.remaining;
	}

	function cleanupAndClose() {
		try { window.localStorage.removeItem(storageKey); } catch (ignore) { ignore = null; }
		fetch(statusUrl + '&cleanup=1', {cache: 'no-store', credentials: 'same-origin', keepalive: true});
		if (closeOnFinish) {
			window.setTimeout(function () { window.close(); }, 700);
		}
	}

	function poll() {
		if (finished) { return; }
		fetch(statusUrl + '&_=' + new Date().getTime(), {cache: 'no-store', credentials: 'same-origin'})
			.then(function (response) {
				if (!response.ok) { throw new Error('HTTP ' + response.status); }
				return response.json();
			})
			.then(function (data) {
				readStoredProgress();
				var browserData = clientProgressData();
				// The web server can hand the already transferred request body to
				// the CGI in a second, very fast pass. Keep browser progress
				// authoritative until the server reports processing or completion,
				// otherwise the bar visibly jumps from 100 back to 0.
				if (browserData && (data.state === 'waiting' || data.state === 'uploading')) {
					data = browserData;
				}
				render(data);
				if (data.current !== lastCurrent || data.state !== lastState) {
					idlePolls = 0;
					lastCurrent = data.current;
					lastState = data.state;
				} else {
					idlePolls++;
				}
				if (data.done) {
					finished = true;
					cleanupAndClose();
					return;
				}
				if (idlePolls >= maxIdlePolls) {
					document.getElementById('progressStatus').innerHTML = labels.stalled;
				}
				window.setTimeout(poll, 500);
			})
			.catch(function () { window.setTimeout(poll, 1000); });
	}

	window.addEventListener('message', function (event) {
		if (event.origin === window.location.protocol + '//' + window.location.host) {
			acceptClientProgress(event.data);
		}
	});
	window.addEventListener('storage', function (event) {
		if (event.key === storageKey && event.newValue) {
			try { acceptClientProgress(JSON.parse(event.newValue)); } catch (ignore) { ignore = null; }
		}
	});
	readStoredProgress();
	poll();
}());
// ]]>
</script>
