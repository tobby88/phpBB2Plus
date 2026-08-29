<h1>{L_HEADLINE}</h1>
<p>{L_SUBHEADLINE}</p>

<br />

<!-- BEGIN infobox -->
<div align="center">
<table width="80%" cellspacing="1" cellpadding="3" border="0" class="forumline">
	<tr>
		<td align="center" style="background-color:#DBFFCF;"><b>{infobox.L_MESSAGE_TEXT}</b></td>
	</tr>
</table>
</div>

<br /><br />
<!-- END infobox -->

<!-- BEGIN overview -->
<table width="100%" cellspacing="1" cellpadding="3" border="0" class="forumline">
	<tr>
		<th colspan="2">{overview.L_OVERVIEW}</th>
	</tr>
	<tr>
		<td class="row2" width="20%" align="center" style="vertical-align:top;"><img src="{IMG_ICON}" title="{overview.L_OVERVIEW}" alt="{overview.L_OVERVIEW}" border="0"></td>
		<td class="row1" width="80%" align="center">
			<table border="0" class="forumline" cellspacing="1" cellpadding="3" width="60%">
				<tr>
					<td class="row3" align="center">{overview.L_COUNTER_VALUE}</td>
				</tr>
			</table>

			<br /><br />

			<table border="0" class="forumline" cellspacing="1" cellpadding="3" width="100%">
				<tr>
					<th colspan="3">{overview.L_LOG_OVERVIEW}</th>
				<tr>
					<th>{overview.L_LOGHEAD_1}</th>
					<th>{overview.L_LOGHEAD_2}</th>
					<th>{overview.L_LOGHEAD_3}</th>
				</tr>
				<tr>
					<td class="row2"><b>{overview.L_LOGNAME_2}</b></td>
					<td class="row2">{overview.S_LOGVALUE_2}</td>
					<td class="row2" align="center"><a href="{overview.S_VIEW_2}">{overview.L_VIEW}</a><form action="{S_DELETE_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete" /><input type="hidden" name="logid" value="{overview.LOG_ID_2}" /><input type="submit" value="{overview.L_DELETE}" class="liteoption" /></form></td>
				</tr>
				<tr>
					<td class="row1"><b>{overview.L_LOGNAME_3}</b></td>
					<td class="row1">{overview.S_LOGVALUE_3}</td>
					<td class="row1" align="center"><a href="{overview.S_VIEW_3}">{overview.L_VIEW}</a><form action="{S_DELETE_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete" /><input type="hidden" name="logid" value="{overview.LOG_ID_3}" /><input type="submit" value="{overview.L_DELETE}" class="liteoption" /></form></td>
				</tr>
				<tr>
					<td class="row2"><b>{overview.L_LOGNAME_4}</b></td>
					<td class="row2">{overview.S_LOGVALUE_4}</td>
					<td class="row2" align="center"><a href="{overview.S_VIEW_4}">{overview.L_VIEW}</a><form action="{S_DELETE_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete" /><input type="hidden" name="logid" value="{overview.LOG_ID_4}" /><input type="submit" value="{overview.L_DELETE}" class="liteoption" /></form></td>
				</tr>
				<tr>
					<td class="row1"><b>{overview.L_LOGNAME_5}</b></td>
					<td class="row1">{overview.S_LOGVALUE_5}</td>
					<td class="row1" align="center"><a href="{overview.S_VIEW_5}">{overview.L_VIEW}</a><form action="{S_DELETE_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete" /><input type="hidden" name="logid" value="{overview.LOG_ID_5}" /><input type="submit" value="{overview.L_DELETE}" class="liteoption" /></form></td>
				</tr>
				<tr>
					<td class="row2"><b>{overview.L_LOGNAME_6}</b></td>
					<td class="row2">{overview.S_LOGVALUE_6}</td>
					<td class="row2" align="center"><a href="{overview.S_VIEW_6}">{overview.L_VIEW}</a><form action="{S_DELETE_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete" /><input type="hidden" name="logid" value="{overview.LOG_ID_6}" /><input type="submit" value="{overview.L_DELETE}" class="liteoption" /></form></td>
				</tr>
				<tr>
					<td class="catBottom" colspan="3" align="center"><form action="{overview.S_DELETE_FORM}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete_all" /><input type="submit" value="{overview.L_DELETE_ALL}" class="mainoption" /></form></td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<!-- END overview -->

<!-- BEGIN show_log_header -->
<div align="center">
<table width="80%" cellspacing="1" cellpadding="3" border="0" class="forumline">
	<tr>
		<td align="center" style="background-color:#FFE2BF;">{show_log_header.L_MESSAGE_TEXT}</td>
	</tr>
</table>
</div>

<br /><br />

<table width="100%" cellspacing="1" cellpadding="3" border="0" class="forumline">
	<tr>
		<th colspan="7">{show_log_header.L_LOG_SHOW}</th>
	</tr>
	<tr>
		<th><b>#</b></th>
		<th>{show_log_header.L_LOGCELL1}</th>
		<th>{show_log_header.L_LOGCELL2}</th>
		<th>{show_log_header.L_LOGCELL3}</th>
		<th>{show_log_header.L_LOGCELL4}</th>
		<th>{show_log_header.L_LOGCELL5}</th>
		<th>{show_log_header.L_LOGCELL6}</th>
	</tr>
<!-- END show_log_header -->

<!-- BEGIN show_system_message -->
	<tr>
		<td class="row3" colspan="7" align="center"><b>{show_system_message.L_SYS_MSG}</b><form action="{S_DELETE_ACTION}" method="post">{S_FORM_TOKEN}<input type="hidden" name="mode" value="delete" /><input type="hidden" name="logid" value="{show_system_message.LOG_ID}" /><input type="submit" value="{show_system_message.L_DELETE}" class="liteoption" /></form></td>
	</tr>
<!-- END show_system_message -->

<!-- BEGIN show_log -->
	<tr>
		<td class="{show_log.TABLE_CLASS}"><b>{show_log.L_NUMBER}</b></td>
		<td class="{show_log.TABLE_CLASS}">{show_log.L_OUTPUT_1}</td>
		<td class="{show_log.TABLE_CLASS}">{show_log.L_OUTPUT_2}</td>
		<td class="{show_log.TABLE_CLASS}">{show_log.L_OUTPUT_3}</td>
		<td class="{show_log.TABLE_CLASS}">{show_log.L_OUTPUT_4}</td>
		<td class="{show_log.TABLE_CLASS}">{show_log.L_OUTPUT_5}</td>
		<td class="{show_log.TABLE_CLASS}">{show_log.L_OUTPUT_6}</td>
	</tr>
<!-- END show_log -->

<!-- BEGIN show_log_footer -->
	<tr>
		<td class="catBottom" colspan="7">&nbsp;</td>
	</tr>
</table>
<!-- END show_log_footer -->

<br />
