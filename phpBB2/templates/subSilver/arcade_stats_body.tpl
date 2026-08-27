<table width="100%" cellspacing="1" cellpadding="3" border="0" align="center">
<form method="post" action="{S_MODE_ACTION}">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
</form>
</table>

<table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
	<tr>
		<th class="thCornerL" width="5%">#</th>
		<th class="thTopL" width="40%">{L_GAME}</th>
		<th class="thTop" width="15%">{L_HIGHSCORE}</th>
		<th class="thTop" nowrap="nowrap" width="25%">{L_PLAYED}</th>
		<th class="thCornerR" nowrap="nowrap" width="15%">{L_TIME_TAKEN}</th>
	</tr>

	<!-- BEGIN stats -->
	<tr>
		<td class="{stats.ROW_CLASS}" width="5%" align="center"><span class="gen">{stats.COUNT}</span></td>
		<td class="{stats.ROW_CLASS}" width="40%" align="center"><span class="gen">{stats.GAME}</span></td>
		<td class="{stats.ROW_CLASS}" width="15%" align="center"><span class="gen">{stats.SCORE}</span></td>
		<td class="{stats.ROW_CLASS}"  nowrap="nowrap" width="25%" align="center"><span class="gen">{stats.LAST_PLAYED}</span></td>
		<td class="{stats.ROW_CLASS}"  nowrap="nowrap" width="15%" align="center"><span class="gen">{stats.TIME_TAKEN}</span></td>
	</tr>
	<!-- END stats -->

	<tr>
		<td class="cat" colspan="5">&nbsp;</td>
	</tr>
</table>
<br />
<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>