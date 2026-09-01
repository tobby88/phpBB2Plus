<!-- phpBB Arcade Activities Template v2.1.8 -->

<table width="100%" cellpadding="3" cellspacing="2" border="0" class="forumline">
  <tr> 
	<td class="cat" colspan="3" height="20"><span class="cattitle">
<!-- BEGIN switch_user_logged_in -->
	{L_WELCOME}<a href="{U_PROFILE}">{ARCADE_USERNAME}</a>
	<!-- END switch_user_logged_in -->
	<!-- BEGIN switch_user_logged_out -->
	{L_WELCOME_GUEST}<a href="{U_REGISTER}">{L_REGISTER}</a> or <a href="{U_LOGIN_LOGOUT}">Login</a></span>
	<!-- END switch_user_logged_out --></span></td>
  </tr>
  <tr>
	<td width="50%" class="row1" align="left" valign="top" rowspan="2"><span class="gensmall">{CURRENT_TIME}<br />{TOTAL_GAMES}<br />{TOTAL_GAMES_PLAYED}{LAST_PLAYED_SCORE}
<!-- BEGIN tournament_menu -->	
	<br />{L_ACTIVE_TOURNAMENTS}
<!-- END tournament_menu -->
	<br />{PLAYER_POINTS}<br /><br />{HIGHSCORE_INPUT}</span></td>
<!-- BEGIN stats_menu -->		
	<th class="thCornerL" align="center" valign="middle">{TOP_HEADER}</th>
	<th class="thCornerR" align="center" valign="middle">{BOTTOM_HEADER}</th>
  </tr>
  <tr>
	<td width="25%" class="row1" align="right" valign="top"><span class="gensmall">{TOP_TEN_LIST}</span></td>
	<td width="25%" class="row1" align="right" valign="top"><span class="gensmall">{BOTTOM_TEN_LIST}
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

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
  <tr> 
    <td colspan="2" align="left" valign="middle" nowrap="nowrap">{MODERATE}<span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
  </tr>
  <tr> 
    <td align="left" nowrap="nowrap">{CAT_JUMP}</td>
    <form method="post" action="{S_MODE_ACTION}">
      <td align="right" valign="middle" nowrap="nowrap">
        <span class="gensmall">{L_SELECT_SORT_METHOD}:&nbsp;{S_MODE_SELECT}&nbsp;&nbsp;{L_ORDER}&nbsp;{S_ORDER_SELECT}&nbsp;&nbsp; 
        <input type="submit" name="submit" value="{L_SUBMIT}" class="liteoption" />
        </span>
      </td>
    </form>
  </tr>
</table>

<!-- BEGIN announcement -->

<!-- END announcement -->

<table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
	<tr>
		<th class="thCornerL" width="5%">{L_MONEY}</th>
		<th class="thTop" colspan="2" width="60%">{L_GAMES}</th>
		<th class="thTop" width="10%">{L_SCORES}</th>
		<th class="thTop" width="5%">{L_SCORE_BONUS}</th>
		<th class="thTop" width="5%">{L_INFO}</th>
		<th class="thCornerR" colspan="2" width="15%" nowrap="nowrap">{L_PLAYER}</th>
	</tr>

	<!-- BEGIN game -->
	<tr><a name="{game.ID}" id="{game.ID}"></a>
		<td class="{game.ROW_CLASS}" align="center"><span class="gensmall">{game.CHARGE}</span></td>
		<td class="{game.ROW_CLASS}" width="5%">{game.IMAGE}</td>
		<td class="{game.ROW_CLASS}"><span class="forumlink">{game.CONTROL}{game.NEW}{game.DESC}</span><span class="gensmall">{game.INFO}{game.PLAYED}{game.SIZE}{game.ADD_FAV}{game.COMMENT}{game.RATE}</span></td>
		<td class="{game.ROW_CLASS}" align="center"><span class="gensmall">{game.LIST}{game.AT_LIST}</span></td>
		<td class="{game.ROW_CLASS}" align="center"><span class="gensmall">{game.BONUS}</span></td>
		<td class="{game.ROW_CLASS}" align="center"><a href="{game.STATS}"><img src="templates/fisubsilversh/images/game_info.gif" alt="{game.ALT_STATS}" border="0" width="20" height="20" /></a></td>
		<td class="{game.ROW_CLASS}" align="right"><span class="gensmall">{game.BEST_PLAYER}{game.BEST_AT_PLAYER}</span></td>
		<td class="{game.ROW_CLASS}" align="right"><span class="gensmall">{game.BEST_SCORE}{game.BEST_AT_SCORE}</span></td>
	</tr>
	<!-- END game -->
	<tr>
		<td class="cat" colspan="8">&nbsp;</td>
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

<table width="100%" cellspacing="2" cellpadding="2" border="0">
	<tr> 
		<td width="15%" nowrap="nowrap"><span class="nav">&nbsp;{PAGE_NUMBER}&nbsp;</span></td>
		<td align="right" nowrap="nowrap"><span class="nav">&nbsp;{PAGINATION}&nbsp;</span></td>
	</tr>
</table>

<!-- BEGIN switch_user_logged_out -->
<form method="post" action="{S_LOGIN_ACTION}">
{S_LOGIN_FIELDS}
  <table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
	<tr> 
	  <td class="catHead" height="28"><a name="login"></a><span class="cattitle">{L_LOGIN_LOGOUT}</span></td>
	</tr>
	<tr> 
	  <td class="row1" align="center" valign="middle" height="28"><span class="gensmall">{L_USERNAME}: 
		<input class="post" type="text" name="username" size="10" />
		&nbsp;&nbsp;&nbsp;{L_PASSWORD}: 
		<input class="post" type="password" name="password" size="10" maxlength="128" autocomplete="current-password" />
		&nbsp;&nbsp; &nbsp;&nbsp;{L_AUTO_LOGIN} 
		<input class="text" type="checkbox" name="autologin" checked="checked" />
		&nbsp;&nbsp;&nbsp; 
		<input type="submit" class="mainoption" name="login" value="{L_LOGIN}" />
		</span> </td>
	</tr>
  </table>
{S_HIDDEN_OPTIONS}
</form>
<!-- END switch_user_logged_out -->
{GAMELIB_LINK}<br />

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
