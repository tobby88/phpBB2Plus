<!-- phpBB Arcade Activities Template #1 v2.2.0 -->

<table width="100%" cellpadding="3" cellspacing="2" border="0" class="forumline">
  <tr>
	<td class="cat" colspan="3" height="20"><span class="cattitle">
<!-- BEGIN switch_user_logged_in -->
	{L_WELCOME}<a href="{U_PROFILE}"><script type="text/javascript">
<!--
      inoutstr = "{L_LOGIN_LOGOUT}";
      endOfUsername = inoutstr.lastIndexOf("]");
      startOfUsername = inoutstr.indexOf("[") +1 ;
      document.write(inoutstr.substring(startOfUsername,endOfUsername));
//-->
    </script></a>
	<!-- END switch_user_logged_in -->
	<!-- BEGIN switch_user_logged_out -->
	{L_WELCOME_GUEST}<a href="{U_REGISTER}">{L_REGISTER}</a> or <a href="{U_LOGIN_LOGOUT}">Login</a></span>
	<!-- END switch_user_logged_out --></span></td>
  </tr>
  <tr>
	<td width="50%" class="row1" align="left" valign="top" rowspan="2"><span class="gensmall">
  {CURRENT_TIME}<br />{TOTAL_GAMES}<br />{TOTAL_GAMES_PLAYED}<br />{stats_menu.LAST_PLAYED_SCORE}
<!-- BEGIN tournament_menu -->
	<br />{L_ACTIVE_TOURNAMENTS}
<!-- END tournament_menu -->
	<br />{PLAYER_POINTS}<br /><br />{HIGHSCORE_INPUT}</span></td>
<!-- BEGIN stats_menu -->
	<th class="thCornerL" align="center" valign="middle">{stats_menu.TOP_HEADER}</th>
	<th class="thCornerR" align="center" valign="middle">{stats_menu.BOTTOM_HEADER}</th>
  </tr>
  <tr>
	<td width="25%" class="row1" align="right" valign="top"><span class="gensmall">{stats_menu.TOP_TEN_LIST}</span></td>
	<td width="25%" class="row1" align="right" valign="top"><span class="gensmall">{stats_menu.BOTTOM_TEN_LIST}
	</span></td>
<!-- END stats_menu -->
  </tr>
</table>

<!-- BEGIN cat_head -->
<br />
<table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
	<tr>
		<th class="thCornerL" colspan="2" width="60%">{cat_head.L_CATS}</th>
		<th class="thTop" width="10%">{cat_head.L_TOTAL_GAMES}</th>
		<th class="thTop" width="10%">{cat_head.L_TOTAL_PLAYED}</th>
		<th class="thCornerR" width="20%" nowrap="nowrap">{cat_head.L_LAST_PLAYED}</th>
	</tr>
<!-- END cat_head -->
<!-- BEGIN cats -->
	<tr>
		<td class="{cats.ROW_CLASS}" width="5%" align="center"><span class="gen"><a href="{cats.LINK}">{cats.ICON}</a></span></td>
		<td class="{cats.ROW_CLASS}" width="55%" align="left"><span class="forumlink"><a href="{cats.LINK}" class="forumlink"> &laquo; {cats.NAME} &raquo; </a></span>
			<br /><span class="gensmall">{cats.DESC}</span>
			<span class="gensmall">{cats.MODERATOR}</span></td>
		<td class="{cats.ROW_CLASS}" width="10%" align="center"><span class="gensmall">{cats.TOTAL_GAMES}</span></td>
		<td class="{cats.ROW_CLASS}" width="10%" align="center"><span class="gensmall">{cats.TOTAL_PLAYED}</span></td>
		<td class="{cats.ROW_CLASS}" width="30%" align="center" nowrap="nowrap"><span class="gensmall">{cats.LAST_PLAYED}</span></td>
	</tr>
<!-- END cats -->
<!-- BEGIN cat_head -->
	<tr>
		<td class="cat" colspan="5">&nbsp;</td>
	</tr>
</table><br />
<!-- END cat_head -->

<table width="75%" border="0" align="center">
	<tr>
		<td align="center" nowrap="nowrap" width="100%">
			<form method="post" action="{S_MODE_ACTION}">
				<span class="genmed">
					<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
						<legend align="center"> {L_SELECT_SORT_METHOD}/{L_ORDER} </legend>
							<br />{S_MODE_SELECT} &nbsp; {S_ORDER_SELECT}      <input type="submit" name="submit" value="{L_SUBMIT}" class="liteoption"><br />
					</fieldset>
				</span>
			</form>
		</td>
	</tr>
</table>
<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
  <tr>
    <td colspan="2" align="left" valign="middle" nowrap="nowrap">{MODERATE}<span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
    <td align="right" nowrap="nowrap">{CAT_JUMP}</td>
  </tr>
</table>
<br />

<table class="forumline" width="100%" cellspacing="0" cellpadding="5" border="0" align="center">
	<tr>
		<th class="thTop" width="20%">{L_GAMES}</th>
		<th class="thTop" width="20%" colspan="2">{L_SCORES}</th>
		<th class="thTop" width="45%">{L_INFO}</th>
	</tr>
<!-- BEGIN game -->
	<tr>
		<td class="{game.ROW_CLASS}" width="50%">
			<span class="genmed">
			<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
			<legend> {game.DESC} </legend>
				<table align="center" width="100%" valign="top">
					<tr>
						<td align="left" width="20%">{game.IMAGE}</td>
						<td align="left" width="80%">{game.CONTROL}{game.NEW}{game.INFO}{game.PLAYED}{game.SIZE}<br />{game.CATEGORY}{game.ADD_FAV}{game.COMMENT}{game.RATE}</td>
					</tr>
				</table>
			</fieldset>
		</span>
		</td>
		<td class="{game.ROW_CLASS}" width="15%" valign="bottom"><span class="genmed"><center>{game.BEST_PLAYER}<br /><br />{game.BEST_AT_PLAYER}</center></span><br /></td>
		<td class="{game.ROW_CLASS}" width="8%" valign="bottom"><span class="genmed"><center>{game.BEST_SCORE}<br /><br />{game.BEST_AT_SCORE}</center></span><br /></td>
		<td class="{game.ROW_CLASS}" width="20%">
			<span class="genmed">
				<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
					<legend align="center"> {game.INSRUCTIONS} </legend>
					{game.INST}
				</fieldset>
				<br />
				<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
					<legend> {game.ALT_STATS} </legend>
						<center>{game.LIST}<br /> <br />{game.AT_LIST}</center>
				</fieldset>
			</span>
		</td>
	</tr>
<!-- END game -->
	<tr>
		<th class="thTop" width="100%" align="center" colspan="4">&nbsp;</th>
	</tr>
</table>

<!-- BEGIN switch_user_logged_out -->
	<br />
		<a href="{U_REGISTER}" class="mainmenu">{L_REGISTER} - {L_GUEST_TXT}</a><br />
<!-- END switch_user_logged_out -->

<table width="100%" cellspacing="2" cellpadding="2" border="0">
	<tr>
		<td><span class="nav" nowrap="nowrap"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
</table>

<table width="100%" cellspacing="0" cellpadding="0" border="0">
	<tr>
		<td><span class="nav">{PAGE_NUMBER}</span></td>
		<td align="right"><span class="nav">{PAGINATION}</span></td>
	</tr>
</table>
{GAMELIB_LINK}<br />

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
