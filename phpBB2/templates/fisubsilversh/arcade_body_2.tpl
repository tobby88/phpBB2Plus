<!-- phpBB Arcade Activities Template #2 v2.2.0 -->

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
											<td align="middle">{stats_menu.U_PLAY_LAST}</td>
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
<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
  <tr> 
    <td colspan="2" align="left" valign="middle" nowrap="nowrap">{MODERATE}<span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
    <td align="right" nowrap="nowrap">{CAT_JUMP}</td>
  </tr>
</table>

<!-- BEGIN tournament_menu -->
<div class="forumlinemain">
	<table width='100%' border="0" cellspacing="1" cellpadding="4" class="forumline">
		<tr>
 			<td align="center" width="100%" nowrap="nowrap" class="catHead">{L_TOURNAMENT_INFO}</td>
		</tr>
   	<tr>
   		<td class="alt1" colspan="2">
  			<table width="100%" border="1" cellspacing="2" cellpadding="2">
    			<tr>
          	<td width='33%' align='center' nowrap='nowrap' class='gensmall' valign='middle'>{TOUR_STATS}</td>
        		<td width='33%' align='center' nowrap='nowrap' class='gensmall' valign='middle'>{USER_TOUR_STATS}</td>
        		<td width='33%' align='center' nowrap='nowrap' class='gensmall' valign='middle'>{START_NEW_TOUR}</td>
				  </tr>
				</table>
      </td>
   	</tr>
  </table>
</div>
<br />
<!-- END tournament_menu -->

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

<table width='100%' border="0" cellspacing="1" cellpadding="2">
	<tr>
  	<td align="left" valign="bottom" width="35%" nowrap="nowrap">
      <form method="post" action="{S_MODE_ACTION}">
         <span class="gensmall">{L_SELECT_SORT_METHOD}:&nbsp;{S_MODE_SELECT}&nbsp;&nbsp;{L_ORDER}&nbsp;{S_ORDER_SELECT}&nbsp;&nbsp; 
        <input type="submit" name="submit" value="{L_SUBMIT}" class="liteoption" />
        </span>
      </form>
  	</td>

  	<td align="right" width="65%">
    	<table class="tborder" border="0" cellspacing="1" cellpadding="1" align="right">
      	<tr>
        	<td class="nav" width="26" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=cat&amp;cat_id="><b>All</b></a></div></td><td class="alt1" width="24" height="20">
          <div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=0-9&amp;search_type=3">0-9</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=A&amp;search_type=1">A</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=B&amp;search_type=1">B</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=C&amp;search_type=1">C</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=D&amp;search_type=1">D</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=E&amp;search_type=1">E</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=F&amp;search_type=1">F</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=G&amp;search_type=1">G</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=H&amp;search_type=1">H</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=I&amp;search_type=1">I</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=J&amp;search_type=1">J</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=K&amp;search_type=1">K</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=L&amp;search_type=1">L</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=M&amp;search_type=1">M</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=N&amp;search_type=1">N</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=O&amp;search_type=1">O</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=P&amp;search_type=1">P</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=Q&amp;search_type=1">Q</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=R&amp;search_type=1">R</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=S&amp;search_type=1">S</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=T&amp;search_type=1">T</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=U&amp;search_type=1">U</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=V&amp;search_type=1">V</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=W&amp;search_type=1">W</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=X&amp;search_type=1">X</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=Y&amp;search_type=1">Y</a></div></td><td class="alt1" width="18" height="20"><div align="center"><a class="gensmall" href="activity.php?mode=search&amp;search_word=Z&amp;search_type=1">Z</a></div></td>
      	</tr>
    	</table>
  	</td>
	</tr>
</table>

<div class="forumlinemain">
	<table width="100%" border="1" cellspacing="1" cellpadding="4" class="forumline">
		<tr>
			<td width="100%" align="center" colspan="4" class="catHead">{L_GAMES}</td>
   	</tr>
 		<tr>
    	<td colspan="4" class="pformstrip">
       	<div class="forumlinemain">
           <table width="100%" border="1" cellspacing="1" cellpadding="4">
             	<tr class="row1">
             		<td width="15%" class="row1" align="center" style="font-weight: normal;">
                {L_LAUNCH_INFO}
               </tr>
            </table>
          </div>
        </td>
    </tr>
	<tr>

<!-- BEGIN game -->

<td width="25%" valign="top">
  <table cellpadding="0" cellspacing="0" border="1" class="row1" width="100%" align="center">
    <tr><a name="{game.ID}" id="{game.ID}"></a>
      <td>
        <table cellpadding="6" cellspacing="0" border="0" width="100%">
          <tr>
            <td class="{game.ROW_CLASS}">
            <fieldset>
              <legend><b>{game.DESC}</b></legend>
              <table cellpadding="6" cellspacing="0" border="0" class="row1" width="100%" align="left">
                <tr>
                  <td rowspan="2" width="5%">{game.IMAGE}</td>
                  <td>{game.CONTROL} {game.NEW} {game.INFO}</td>
                </tr>
                <tr>
                  <td>{game.PLAY_SAME}</td>
                </tr>
                <tr>
                  <td colspan="2">{game.ADD_FAV}{game.COMMENT}</td>
                </tr>
              </table>
            </fieldset>

            <fieldset>
              <legend>{game.L_AT_SCORE}</legend>
              <center><span class="gensmall">
              <img src="./images/crown.gif" alt="" /> <b>{game.BEST_AT_PLAYER}</b> <img src="./images/crown.gif" alt="" />
              <br />
              {L_WITH_SCORE}<b>{game.BEST_AT_SCORE}</b></span></center>
            </fieldset>

            <fieldset>
              <legend>{game.L_CURRENT}</legend>
              <center>
              <span class="gensmall">
              <img src="./images/trophy.gif" alt="" /> <b>{game.BEST_PLAYER}</b> <img src="./images/trophy.gif" alt="" />
              <br />
              {L_WITH_SCORE}<b>{game.BEST_SCORE}</b></span>
              </center>
            </fieldset>

            <fieldset>
              <legend>{L_INFO}</legend>
              <center><span class="gensmall">
              {L_TIME_PLAYED} <b>{game.TIME_PLAYED}</b>
              {game.PLAYED}{game.SIZE}<br />{game.CATEGORY}<br />{game.LIST} {game.AT_LIST}
            </span></center></fieldset>

            <fieldset>
              <legend>{L_RATING}</legend>
              <center><span class="gensmall">
              {game.RATE}
            </span></center></fieldset>

            </td>
         </tr>
        </table>
      </td>
    </tr>
  </table>
</td>
{game.ROW_BREAK}

<!-- END game -->
</tr>
</table>
</div>

<!-- BEGIN switch_user_logged_out -->
	<br />
		<a href="{U_REGISTER}" class="mainmenu"><img src="{TEMPLATE_NAME}/images/icon_mini_register.gif" width="12" height="13" border="0" alt="{L_REGISTER}" hspace="3" />{L_REGISTER} - {L_GUEST_TXT}</a><br />
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
  <table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
	<tr> 
	  <td class="catHead" height="28"><a name="login"></a><span class="cattitle">{L_LOGIN_LOGOUT}</span></td>
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
{GAMELIB_LINK}<br />

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
