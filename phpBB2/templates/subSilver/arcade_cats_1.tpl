<!-- phpBB Arcade Categories Template #1 v2.2.0 -->

<div class="forumlinemain">
	<table width='100%' border="0" cellspacing="0" cellpadding="4" class="forumline">
  		<tr>
  			<td colspan="3" align="center" width="60%" nowrap="nowrap" class="cat">
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
	<!-- END switch_user_logged_out --></span>
        </td>
      </tr>
    	<tr>
     		<td class="row1" align="left" valign="top" style="width: 20%;padding-left:7px;padding-top:5px;padding-bottom:5px;">
<!-- BEGIN stats_menu -->					
          <div class="forumlinemain">
          	<table width='100%' border='1' cellspacing='1' cellpadding='4'>
        		<tr>
        			<td align="center" width="20%" nowrap="nowrap" class="catHead">{stats_menu.BOTTOM_HEADER}</td>
        		</tr>
        		<tr>
        			<td align="left" valign="top" class="row1">{stats_menu.BOTTOM_TEN_LIST}</td>
						</tr>
						<tr>
  						<td align="center" width="20%" nowrap="nowrap" class="cat">{L_SEARCH}</td>
						</tr>
						<tr>
          		<td width='20%' align='center' nowrap='nowrap' class='row2' valign='middle'>
          		<br /><form action="" method="GET">
          		<input type="text" size="15" name="search_word" class="post" value="" />&nbsp;&nbsp; <br /><br />
              <input type="hidden" name="mode" value="search" />
              <input type="hidden" name="search_type" value="0" />
          		<input type="submit" value="search" class="forminput" />
          		</form></td>
						</tr>
					</table>
				</div>
	  	</td>

   		<td class="row2" align="center" valign="top" style="width: 60%;padding-left:7px;padding-top:5px;padding-bottom:5px;">
				<div class='forumlinemain'>
					<table width='100%' cellspacing='0' cellpadding='4' border="1">
						<tr>
							<th width='100%' align='center' nowrap='nowrap' class='cat' colspan='3'><img src="./images/trophy.gif" border="0" alt="" />{stats_menu.L_HIGHSCORE_CHAMP}<img src="./images/trophy.gif" border="0" alt="" /></th>
						</tr>
						<tr>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'><b><img src="./images/1st.gif" border="0" alt="Player with most highscores" /></b></td>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'><b><img src="./images/2nd.gif" border="0" alt="Player on 2nd place" /></b></td>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'><b><img src="./images/3rd.gif" border="0" alt="Player on 3rd place" /></b></td>
						</tr>
						<tr>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'>{stats_menu.BEST_AVATAR_1}</td>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'>{stats_menu.BEST_AVATAR_2}</td>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'>{stats_menu.BEST_AVATAR_3}</td>
						</tr>
						<tr>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'><span style="font-weight: bold;">{stats_menu.BEST_PLAYER_1}</span><br />{stats_menu.BEST_SCORE_1}</td>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'><span style="font-weight: bold;">{stats_menu.BEST_PLAYER_2}</span><br />{stats_menu.BEST_SCORE_2}</td>
							<td width='33%' align='center' nowrap='nowrap' class='alt2' valign='middle'><span style="font-weight: bold;">{stats_menu.BEST_PLAYER_3}</span><br />{stats_menu.BEST_SCORE_3}</td>
						</tr>
						</table>
					</div>

					<br />

					<div class='forumlinemain'>
						<table width='100%' height="100" border='1' cellspacing='0' cellpadding='4'>
						<tr>
							<th width='50%' align='center' nowrap='nowrap' colspan='1'><img src="./images/crown.gif" border="0" alt="" />{stats_menu.L_HIGHSCORE_KING}<img src="./images/crown.gif" border="0" alt="" /></th>
							<th width='50%' align='center' nowrap='nowrap' colspan='1'>{stats_menu.L_HIGHSCORE_LEADER}</th>
						</tr>

						<tr>
							<td width='50%' align='center' nowrap='nowrap' valign='middle'>
							{stats_menu.KING_AVATAR}</td>

							<td width='50%' align='center' nowrap='nowrap' valign='middle'>
							{stats_menu.LEADER_AVATAR}</td>
						</tr>

						<tr>
							<td width='50%' align='center' nowrap='nowrap' valign='middle'>
							<span style="font-weight: bold;">{stats_menu.KING_NAME}</span><br />{stats_menu.KING_SCORES}
							</td>

							<td width='50%' align='center' nowrap='nowrap' valign='middle'>
							<b><span style="font-weight: bold;">{stats_menu.LEADER_NAME}</span></b><br />{stats_menu.LEADER_SCORES}
							</td>
						</tr>

						</table>
					</div>

				<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
					<legend>Newest Champions&nbsp;</legend>
					<div style="padding: 0px;">
						<table cellpadding="0" cellspacing="0" border="0" align="center" width="100%">
							<tr>
								<td width="100%">
									<table cellpadding="2" cellspacing="1" border="0" width="100%">
<!-- BEGIN champ -->
											<tr>
                      	<td class="alt1" align="left" valign="middle" width="70%">
                        	<span class="gensmall">{stats_menu.champ.NAME}</span>
                        </td>
                        <td class="alt1" align="right" valign="middle" width="30%">
                        	<font size="1"><i>{stats_menu.champ.DATE}</i></font>
                        </td>
                      </tr>
<!-- END champ -->                     
									</table>
								</td>
							</tr>
						</table>
					</div>
				</fieldset>

				<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
					<legend>Latest Arcade Score&nbsp;</legend>
					<div style="padding: 0px;">
						<table cellpadding="0" cellspacing="0" border="0" align="center" width="100%">
							<tr>
								<td width="100%">
								<table cellpadding="2" cellspacing="1" border="0" width="100%">
										<tr>
                      <td align="left">{stats_menu.LAST_PLAYED_SCORE}</td>
											<td align="right">{stats_menu.U_PLAY_LAST}</td>
										</tr>
								</table>
								</td>
							</tr>
						</table>
					</div>
				</fieldset>
  		</td>

  		<td class="row1" align="right" valign="top" style="width: 20%;padding-left:7px;padding-top:5px;padding-bottom:5px;">
        <div class="forumlinemain">
          <table width='100%' border='1' cellspacing='1' cellpadding='4'>
        		<tr>
        			<td align="center" width="20%" nowrap="nowrap" class="catHead">{stats_menu.TOP_HEADER}</td>
        		</tr>
        		<tr>
        			<td class="row1" align="right" valign="top" style="width: 20%;padding-right:7px;padding-top:5px;padding-bottom:5px;">
                {stats_menu.TOP_TEN_LIST}
         			</td>
  					</tr>
						
  					<tr>
  						<td align="center" width="20%" nowrap="nowrap" class="cat">{stats_menu.L_RANDOM}</td>
  					</tr>
   					<tr>
  						<td width='20%' align='center' nowrap='nowrap' class='row1' valign='middle'><br />{stats_menu.U_RANDOM_GAME}</td>
  					</tr>
  				</table>
  			</div>
  		</td>
  	</tr>
<!-- END stats_menu -->
  </table>
</div>
<br />

<table width="100%" cellspacing="1" cellpadding="3" border="0" align="center">
	<tr> 
		<td align="left" colspan="2"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
    <td align="right" nowrap="nowrap">{CAT_JUMP}</td>
	</tr>
</table>

<!-- BEGIN announcement -->

<br />
<!-- END announcement -->

<div class="forumlinemain">
	<table width="100%" border="1" cellspacing="1" cellpadding="4" class="forumline">
		<tr>
			<td width="100%" align="center" colspan="4" class="catHead">{L_CATS}</td>
   	</tr>
  	<tr>
<!-- BEGIN tournament_menu -->	
<td width="25%" valign="top">
  <table cellpadding="0" cellspacing="0" border="1" class="row1" width="100%" align="center">
    <tr>
      <td colspan="5">
        <table cellpadding="6" cellspacing="0" border="0" width="100%">
          <tr>
            <td class="row1">
            <fieldset>
              <legend><a href="{tournament_menu.L_TOUR}" class="forumlink"> &laquo; {tournament_menu.TOUR} &raquo; </a></legend>
              <br /><a href="{cats.LINK}"><img src="images/tournaments.gif" border="0" /></a> 
            </fieldset>

            <fieldset>
              <legend>{L_INFO}</legend>
              <center><span class="gensmall">
              
              {L_TOTAL_GAMES}: {tournament_menu.TOUR_PLAYED}<br />
              {L_TOTAL_PLAYED}: {tournament_menu.TOUR_PLAYED}<br /><br />
              {tournament_menu.TOUR_LAST}
            </span></center></fieldset>

            </td>
         </tr>
        </table>
      </td>
    </tr>
  </table>
</td>
<!-- END tournament_menu -->

<!-- BEGIN all_games -->
<td width="25%" valign="top">
  <table cellpadding="0" cellspacing="0" border="1" class="row1" width="100%" align="center">
    <tr>
      <td colspan="5">
        <table cellpadding="6" cellspacing="0" border="0" width="100%">
          <tr>
            <td class="row2">
            <fieldset>
              <legend><a href="{all_games.LINK}" class="forumlink"> &laquo; {all_games.NAME} &raquo; </a></legend>
              <br /><a href="{all_games.LINK}">{all_games.ICON}</a>
            </fieldset>

            <fieldset>
              <legend>{all_games.NAME}</legend>
              <span class="gensmall">{all_games.DESC}<br />{all_games.MODERATOR}
            </fieldset>

            <fieldset>
              <legend>{L_INFO}</legend>
              <center><span class="gensmall">
              
              {L_TOTAL_GAMES}: {all_games.TOTAL_GAMES}<br />
              {L_TOTAL_PLAYED}: {all_games.TOTAL_PLAYED}<br /><br />
              {all_games.LAST_ALL}
            </span></center></fieldset>

            </td>
         </tr>
        </table>
      </td>
    </tr>
  </table>
</td>
<!-- END all_games -->

<!-- BEGIN cats -->
<td width="25%" valign="top">
  <table cellpadding="0" cellspacing="0" border="1" class="row1" width="100%" align="center">
    <tr>
      <td colspan="5">
        <table cellpadding="6" cellspacing="0" border="0" width="100%">
          <tr>
            <td class="{cats.ROW_CLASS}">
            <fieldset>
              <legend><a href="{cats.LINK}" class="forumlink"> &laquo; {cats.NAME} &raquo; </a></legend>
              <br /><a href="{cats.LINK}">{cats.ICON}</a> 
            </fieldset>

            <fieldset>
              <legend>{cats.NAME}</legend>
              <span class="gensmall">{cats.DESC}<br />{cats.MODERATOR}
            </fieldset>

            <fieldset>
              <legend>{L_INFO}</legend>
              <center><span class="gensmall">
              
              {L_TOTAL_GAMES}: {cats.TOTAL_GAMES}<br />
              {L_TOTAL_PLAYED}: {cats.TOTAL_PLAYED}<br /><br />
              {cats.LAST_PLAYED}
            </span></center></fieldset>

            </td>
         </tr>
        </table>
      </td>
    </tr>
  </table>
</td>
{cats.ROW_BREAK}

<!-- END cats -->
</tr>
</table>
</div>


<form method="post" action="{S_MODE_ACTION}">
<table width="100%" cellspacing="1" cellpadding="3" border="0" align="center">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
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

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
