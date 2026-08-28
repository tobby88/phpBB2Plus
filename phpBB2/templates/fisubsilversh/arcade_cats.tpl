<!-- phpBB Arcade Categories Template v2.1.8 -->

<table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
  <tr> 
	<td class="cat" colspan="4" height="28"><span class="cattitle">
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
	{L_WELCOME_GUEST}<a href="{U_REGISTER}">{L_REGISTER}</a> or <a href="{U_LOGIN_LOGOUT}">Login</a>
	<!-- END switch_user_logged_out -->
	</span></td>
  </tr>
<!-- BEGIN stats_menu -->
  <tr>
	<th width="50%" class="thCornerL" valign="middle" colspan="2">{stats_menu.L_INFO_STATS}</th>
	<th class="thTop" valign="middle">{stats_menu.TOP_HEADER}</th>
	<th class="thCornerR" valign="middle">{stats_menu.BOTTOM_HEADER}</th>
  </tr>
<!-- END stats_menu -->
  <tr>
	<td class="row1" align="left" valign="top" colspan="2"><p><span class="gensmall">{CURRENT_TIME}<br />
<!-- BEGIN tournament_menu -->	
    {ACTIVE_TOURNAMENTS}{L_ACTIVE_TOURNAMENTS}
<!-- END tournament_menu -->
<!-- BEGIN switch_user_logged_in -->
	<span class="gensmall"><a href="{U_LIST_FAV}" class="gensmall">{L_LIST_FAV}</a><br />{LAST_PLAYED}{LAST_PLAYED_SCORE}</span>
<!-- END switch_user_logged_in -->
    </span></td>
<!-- BEGIN stats_menu -->
	<td width="25%" class="row1" align="right" valign="top" rowspan="2"><span class="gensmall">{stats_menu.TOP_TEN_LIST}</span></td>
	<td width="25%" class="row1" align="right" valign="top" rowspan="2"><span class="gensmall">{stats_menu.BOTTOM_TEN_LIST}</span></td>
  </tr>  
  <tr>
	<td width="25%" class="row1" align="left" valign="top">
    <p align="center"><span class="gensmall">
    {stats_menu.BEST_PLAYER}{stats_menu.LEADER}</span>
    </td>
	<td width="25%" class="row1" align="left" valign="top">
    <p align="center"><span class="gensmall">
    {stats_menu.BEST_AT_PLAYER}{stats_menu.AT_LEADER}</span>
    </td>
<!-- END stats_menu -->
  </tr>  
</table>

<table width="100%" cellspacing="1" cellpadding="3" border="0" align="center">
	<tr> 
		<td align="left" colspan="2"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
  <tr>
    <td align="left" nowrap="nowrap">{CAT_JUMP}</td>
    <form method="post" action="{S_MODE_ACTION}">
		  <td align="right" nowrap="nowrap"><span class="gen">{L_SEARCH}:&nbsp;<input class="post" type="text" size="40" name="search_word" value="" />&nbsp;&nbsp;<input type="submit" name="search" value="{L_SUBMIT}" class="liteoption" /></span></td>
    </form>
  </tr>
</table>
<!-- BEGIN announcement -->

<!-- END announcement -->

<table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
	<tr>
		<th class="thCornerL" colspan="2" width="60%">{L_CATS}</th>
		<th class="thTop" width="10%">{L_TOTAL_GAMES}</th>
		<th class="thTop" width="10%">{L_TOTAL_PLAYED}</th>
		<th class="thCornerR" width="20%" nowrap="nowrap">{L_LAST_PLAYED}</th>
	</tr>
<!-- BEGIN tournament_menu -->	
	<tr>
		<td class="row2" width="5%" align="center"><span class="gen"><img src="images/tournaments.gif" /></span></td>
		<td class="row2" width="55%" align="left"><span class="forumlink"><a href="{tournament_menu.L_TOUR}" class="forumlink"> &laquo; {tournament_menu.TOUR} &raquo; </a></span></td>
		<td class="row2" width="10%" align="center" nowrap="nowrap"><span class="gen">{tournament_menu.TOUR_TOTAL}</span></td>
		<td class="row2" width="10%" align="center" nowrap="nowrap"><span class="gen">{tournament_menu.TOUR_PLAYED}</span></td>
		<td class="row2" width="30%" align="center" nowrap="nowrap"><span class="gensmall">{tournament_menu.TOUR_LAST}</span></td>
	</tr>
<!-- END tournament_menu -->
	<!-- BEGIN all_games -->
	<tr>
		<td class="row1" width="5%" align="center"><span class="gen"><a href="{all_games.LINK}">{all_games.ICON}</a></span></td>
		<td class="row1" width="55%" align="left"><span class="forumlink"><a href="{all_games.LINK}" class="forumlink"> &laquo; {all_games.DESC} &raquo; </a></span></td>
		<td class="row1" width="10%" align="center" nowrap="nowrap"><span class="gen">{all_games.TOTAL_GAMES}</span></td>
		<td class="row1" width="10%" align="center" nowrap="nowrap"><span class="gen">{all_games.TOTAL_PLAYED}</span></td>
		<td class="row1" width="30%" align="center" nowrap="nowrap"><span class="gensmall">{all_games.LAST_ALL}</span></td>
	</tr>
	<!-- END all_games -->
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

	<tr>
		<td class="cat" colspan="5">&nbsp;</td>
	</tr>
</table>
<form method="post" action="{S_MODE_ACTION}">
<table width="100%" cellspacing="1" cellpadding="3" border="0" align="center">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
    <td align="right">{CAT_JUMP}</td>
	</tr>
</table>
</form>

<br />
<!-- BEGIN switch_user_logged_out -->
<form method="post" action="{S_LOGIN_ACTION}">
  <table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
	<tr> 
	  <th height="28"><a name="login"></a>{L_LOGIN_LOGOUT}</th>
	</tr>
	<tr> 
	  <td class="row1" align="center" valign="middle" height="28"><span class="gensmall">{L_USERNAME}: 
		<input class="post" type="text" name="username" size="10" />
		&nbsp;&nbsp;&nbsp;{L_PASSWORD}: 
		<input class="post" type="password" name="password" size="10" maxlength="32" />
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

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
