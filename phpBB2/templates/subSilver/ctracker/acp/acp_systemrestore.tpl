<h1>{L_HEADLINE}</h1>
<p>{L_SUBHEADLINE}</p>

<br /><br />

<!-- BEGIN infobox -->
<div align="center">
<table width="80%" cellspacing="1" cellpadding="3" border="0" class="forumline">
	<tr>
		<td align="center" style="background-color:#{infobox.COLOR};"><b>{infobox.L_MESSAGE_TEXT}</b></td>
	</tr>
</table>
</div>

<br /><br />
<!-- END infobox -->

<table width="100%" cellspacing="1" cellpadding="3" border="0" class="forumline">
	<tr> 
		<th colspan="2">{L_HEADLINE}</th>
	</tr>
	<tr> 
		<td class="row2" width="20%" align="center" rowspan="2"><img src="{IMG_RECOVERY}" alt="{L_HEADLINE}" title="{L_HEADLINE}" border="0"></td>
		<td class="row3" align="center"><b>{L_SAVE_STATUS}</b></td>
	</tr>
	<tr>
		<td class="row1" width="80%">
			<form action="{S_FORM_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="backup" /><input type="submit" value="{L_BACKUP}" class="mainoption" /></form>
			<!-- BEGIN restore_available --><form action="{S_FORM_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="restore" /><input type="submit" value="{L_RESTORE}" class="mainoption" /></form><!-- END restore_available -->
			<!-- BEGIN restore_unavailable --><strong>{L_RESTORE}</strong><!-- END restore_unavailable -->
		</td>
	</tr>
	<tr>
		<td class="catBottom" colspan="2">&nbsp;</td>
	</tr>
</table>
