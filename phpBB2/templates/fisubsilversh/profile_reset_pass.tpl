<form action="{S_RESET_ACTION}" method="post">
<table width="100%" cellspacing="2" cellpadding="2" border="0">
<tr>
	<td class="maintitle">{L_RESET_TITLE}</td>
</tr>
<tr>
	<td class="nav"><a href="{U_INDEX}">{L_INDEX}</a> &raquo; {L_RESET_TITLE}</td>
</tr>
</table>
<table border="0" cellpadding="3" cellspacing="1" width="100%" class="forumline">
<tr>
	<th colspan="2">{L_RESET_TITLE}</th>
</tr>
<!-- BEGIN switch_error -->
<tr>
	<td class="row1" colspan="2"><span class="gen" style="color: #CC0000">{ERROR_MESSAGE}</span></td>
</tr>
<!-- END switch_error -->
<tr>
	<td class="row2" colspan="2"><span class="gensmall">{L_RESET_EXPLAIN}</span></td>
</tr>
<tr>
	<td nowrap="nowrap" class="row1"><span class="explaintitle">{L_NEW_PASSWORD}:</span></td>
	<td class="row2" width="100%"><input type="password" class="post" style="width: 240px" name="new_password" size="30" maxlength="128" autocomplete="new-password" required="required" /></td>
</tr>
<tr>
	<td nowrap="nowrap" class="row1"><span class="explaintitle">{L_CONFIRM_PASSWORD}:</span></td>
	<td class="row2"><input type="password" class="post" style="width: 240px" name="password_confirm" size="30" maxlength="128" autocomplete="new-password" required="required" /></td>
</tr>
<tr>
	<td class="cat" colspan="2" align="center">{S_HIDDEN_FIELDS}<input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" /></td>
</tr>
</table>
</form>
