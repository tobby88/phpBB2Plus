function checkAlbumComment(form) {
	var field = form.elements.comment;
	var text = field ? field.value.replace(/^[\x00\x09-\x0d\x20]+|[\x00\x09-\x0d\x20]+$/g, '') : '';
	var rating = form.elements.rate;
	var name = form.elements.comment_username;
	var error = '';
	function length(value) {
		return value.replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_').length;
	}
	if (!text && (!rating || rating.value === '-1')) {
		error = form.getAttribute('data-empty');
	} else if (length(text.replace(/\r?\n/g, '\r\n')) > Number(form.getAttribute('data-limit'))) {
		error = form.getAttribute('data-long');
	} else if (name && length(name.value) > 32) {
		error = form.getAttribute('data-name-long');
	}
	if (error) {
		window.alert(error);
		return false;
	}
	return true;
}

function emotions(text) {
	var form = document.forms['commentform'];
	var field = form && form.elements.comment;
	if (!field) { return; }
	if (typeof field.selectionStart === 'number') {
		var start = field.selectionStart;
		field.value = field.value.slice(0, start) + text + field.value.slice(field.selectionEnd);
		field.setSelectionRange(start + text.length, start + text.length);
	} else {
		field.value += text;
	}
	field.focus();
}

function openAllSmiles(url) {
	var popup = window.open(url, '_phpbbsmilies', 'height=600,resizable=yes,scrollbars=yes,width=470');
	if (popup) { popup.focus(); }
}
