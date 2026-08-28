<center><b><span class="genmed"><h3>{TITLE}</h3></span></b></center>
<script type="text/javascript">
<!--
function checkRateForm() {
	if (document.rateform.rate.value == -1)
	{
		return false;
	}
	else
	{
		return true;
	}
}
// -->
</script>

<form name="rateform" action="{S_ARCADE_ACTION}" method="post" onsubmit="return checkRateForm();">
<table width="100%" cellspacing="2" cellpadding="2" border="0">
  <tr>
	<td class="nav"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a> &raquo; <a class="nav" href="{U_ARCADE_CAT}">{L_ARCADE_CAT}</a> &raquo; <a class="nav" href="{U_ARCADE}">{L_ARCADE}</a> &raquo; <a href="{L_RATE_TITLE}" class="nav">{RATE_TITLE}</a></span></td>
  </tr>
</table>

<table width="100%" cellpadding="2" cellspacing="1" border="0" class="forumline">
  <tr>
	<th class="thTop" height="25" colspan="2">{L_RATING}</th>
  </tr>
  <tr>
	<td class="row1" align="center" width="50%"><table width="100%" cellspacing="2" cellpadding="2" border="0">
		<tr>
		  <td align="right" nowrap="nowrap" class="genmed">{L_POSTED}:</td>
		  <td class="genmed"><strong>{GAME_TIME}</strong></td>
		</tr>
		<tr>
		  <td align="right" nowrap="nowrap" class="genmed">{L_PLAYED}:</td>
		  <td class="genmed"><strong>{GAME_PLAYED}</strong></td>
		</tr>
		<tr>
		  <td align="right" nowrap="nowrap" class="genmed">{L_RATED}:</td>
		  <td class="genmed"><strong>{GAME_RATED}</strong></td>
		</tr>
	</table></td>
	<td class="row1" valign="top">
	<span class="gen"><br />{L_CURRENT_RATING}:&nbsp;<strong>{ACTIVITY_RATING}</strong><br />
	<br />{L_PLEASE_RATE_IT}:&nbsp;<select name="rate">
	<option value="-1">{S_RATE_MSG}</option>
	<!-- BEGIN rate_row -->
	<option value="{rate_row.POINT}">{rate_row.POINT}</option>
	<!-- END rate_row -->
	</select><br />&nbsp;</span>
	</td>
  </tr>
  <tr>
	<td class="cat" align="center" height="28" colspan="2"><input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" /></td>
  </tr>
</table>
</form>

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
