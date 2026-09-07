<!-- BEGIN quick_reply -->
<script language='JavaScript'>
	function openAllSmiles(){
		var smiles = window.open('{U_MORE_SMILIES}', '_phpbbsmilies', 'HEIGHT=250,resizable=yes,scrollbars=yes,WIDTH=300');
		if (smiles) smiles.focus();
		return false;
	}
	
	function quoteSelection() {

		var theSelection = '';
			if (window.getSelection)
			{
				theSelection = window.getSelection();
			}
			else if (document.getSelection)
			{
				theSelection = document.getSelection();
			}
			else if (document.selection)
			{
				theSelection = document.selection.createRange().text;
			}

		theSelection = theSelection ? String(theSelection) : '';
		if (theSelection) {
			// Add tags around selection
					emoticon( '[quote]' + theSelection + '[/quote]\n');
			document.post.message.focus();
			theSelection = '';
			return;
			}
			else
			{
			alert('{L_NO_TEXT_SELECTED}');
		}
	}

	function storeCaret(textEl) {
		if (textEl.createTextRange) textEl.caretPos = document.selection.createRange().duplicate();
	}

	function emoticon(text) {
		var message = document.forms['post'].elements['message'];
		if (typeof message.selectionStart === 'number') {
			var start = message.selectionStart;
			var end = message.selectionEnd;
			message.value = message.value.substring(0, start) + text + message.value.substring(end);
			message.focus();
			message.setSelectionRange(start + text.length, start + text.length);
		} else if (message.createTextRange && message.caretPos) {
			var caretPos = message.caretPos;
			caretPos.text = caretPos.text.charAt(caretPos.text.length - 1) == ' ' ? text + ' ' : text;
			document.post.message.focus();
		} else {
			document.post.message.value  += text;
			document.post.message.focus();
		}
	}

	function checkForm(form) {
		var formErrors = '';
		if (form.elements['message'].value.length < 2) {
			formErrors = '{L_EMPTY_MESSAGE}';
		}
		if (formErrors) {
			alert(formErrors);
			return false;
		} else {
			if (form.elements['quick_quote'].checked) {
				form.elements['message'].value = form.elements['last_msg'].value + form.elements['message'].value;
			} 
			form.elements['quick_quote'].checked = false;
			return true;
		}
	}
</script>
<form action="{quick_reply.POST_ACTION}" method="post" name="post" accept-charset="UTF-8" onsubmit="return checkForm(this)">
<input type="hidden" name="sid" value="{quick_reply.SID}" />
<input type="hidden" name="mode" value="reply" />
<input type="hidden" name="t" value="{quick_reply.TOPIC_ID}" />
<input type="hidden" name="last_msg" value="{quick_reply.LAST_MESSAGE}" />
<table width="100%" border="0" cellspacing="0" cellpadding="3">
<tr>
<th>{L_OPTIONS}</th>
<th><b>{L_QUICK_REPLY}</b></th>
</tr>
<tr>
<td class="row1" align="left" valign="top"><label class="gensmall"><input type="checkbox" name="quick_quote" /> {L_QUOTE_LAST_MESSAGE}</label><br />
<!-- BEGIN user_logged_in -->
<label class="gensmall"><input type="checkbox" name="attach_sig" {quick_reply.user_logged_in.ATTACH_SIGNATURE} /> {L_ATTACH_SIGNATURE}</label><br />
<label class="gensmall"><input type="checkbox" name="notify" {quick_reply.user_logged_in.NOTIFY_ON_REPLY} /> {L_NOTIFY_ON_REPLY}</label>
<!-- END user_logged_in -->
</td>
<td class="row1" valign="top">
<textarea name="message" aria-label="{L_QUICK_REPLY}" rows="6" cols="35" style="box-sizing:border-box;width:100%;max-width:700px" wrap="virtual" tabindex="1" class="post" onselect="storeCaret(this);" onclick="storeCaret(this);" onkeyup="storeCaret(this);"></textarea><br />
<INPUT TYPE='button' name='smiles_all' class='liteoption' VALUE='{L_ADD_SMILIES}' ONCLICK="openAllSmiles();">&nbsp;
<input type='button' name='quoteselected' class='liteoption' value='{L_QUOTE_SELECTED}' onclick='javascript:quoteSelection()'>&nbsp;
<input type='submit' name='preview' class='liteoption' value='{L_PREVIEW}'>&nbsp;
<input type='submit' accesskey='s' name='post' class='mainoption' value='{L_SUBMIT}'>

</td>
</tr>
</table>
</form>
<!-- END quick_reply -->
