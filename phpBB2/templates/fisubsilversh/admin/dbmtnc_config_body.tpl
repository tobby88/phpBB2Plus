
<h1>{L_DBMTNC_TITLE} - {L_DBMTNC_SUB_TITLE}</h1>

<p>{L_CONFIG_INFO}</p>

<form action="{S_CONFIG_ACTION}" method="post"><table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="forumline">
	<tr>
	  <th class="thHead" colspan="2">{L_GENERAL_CONFIG}</th>
	</tr>
	<tr>
		<td class="row1">{L_DISALLOW_POSTCOUNTER}<br /><span class="gensmall">{L_DISALLOW_POSTCOUNTER_EXPLAIN}</span></td>
		<td class="row2" nowrap="nowrap"><input type="radio" name="disallow_postcounter" value="1" {DISALLOW_POSTCOUNTER_YES} /> {L_YES}&nbsp;&nbsp;<input type="radio" name="disallow_postcounter" value="0" {DISALLOW_POSTCOUNTER_NO} /> {L_NO}</td>
	</tr>
	<tr>
		<td class="row1">{L_DISALLOW_REBUILD}<br /><span class="gensmall">{L_DISALLOW_REBUILD_EXPLAIN}</span></td>
		<td class="row2"><input type="radio" name="disallow_rebuild" value="1" {DISALLOW_REBUILD_YES} /> {L_YES}&nbsp;&nbsp;<input type="radio" name="disallow_rebuild" value="0" {DISALLOW_REBUILD_NO} /> {L_NO}</td>
	</tr>
	<tr>
		<td class="catBottom" colspan="2" align="center">{S_HIDDEN_FIELDS}<input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;&nbsp;<input type="reset" value="{L_RESET}" class="liteoption" />
		</td>
	</tr>
</table></form>
