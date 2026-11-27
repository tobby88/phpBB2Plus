(function () {
	'use strict';
	var field = document.getElementById('album-description');
	if (!field) { return; }
	var limit = Number(field.dataset.limit);
	function validate() {
		// Native maxlength counts UTF-16 units. The source forum and the server count Unicode code points.
		var tooLong = field.value.length > limit && Array.from(field.value).length > limit;
		field.setCustomValidity(tooLong ? field.dataset.error : '');
	}
	field.addEventListener('input', validate);
	field.form.addEventListener('reset', function () { setTimeout(validate, 0); });
	validate();
})();
