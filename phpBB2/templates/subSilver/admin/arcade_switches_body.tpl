<!-- phpBB Arcade Admin Switches Template v2.1.8 -->

<form action="{S_CONFIG_ACTION}" method="post">

 <table width="99%" cellpadding="4" cellspacing="0" border="0" align="center" class="bodyline">
  <tr> 
   <th class="thHead" colspan="2">{L_CONFIG_MENU} {VERSION}</th>
  </tr>
  <tr>
   <td class="catTop" colspan="2"><span class="gensmall">{L_INA_HEADER}</span></td>
  </tr>
  <tr>
   <td class="catBottom" colspan="2"></td>
  </tr>
  <tr>
   <th class="thHead" colspan="2" align="center">{L_TOGGLES}</td>
  </tr>
  <tr>
   <td class="row1">{L_MOD_OFFLINE}<span class="gensmall">{L_MOD_OFFLINE_INFO}</span></td>
   <td class="row1" align="center" width="20%">
     <input type="radio" name="games_offline" value="1" {S_MOD_OFFLINE_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_offline" value="0" {S_MOD_OFFLINE_NO} /> {L_NO}</td>
  </tr>
  <tr>
   <td class="row2">{L_BLOCK_GUEST}<span class="gensmall">{L_BLOCK_GUEST_INFO}</span></td>
   <td class="row2" align="center">
     <input type="radio" name="games_no_guests" value="1" {S_NO_GUESTS_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_no_guests" value="0" {S_NO_GUESTS_NO} /> {L_NO}</td>
  </tr>
  <tr>
   <th class="thHead" colspan="2" align="center">{L_ARCADE_CONFIG}</td>
  </tr>

  <tr>
   <td class="row2">{L_AUTO_SIZE}<span class="gensmall">{L_AUTO_SIZE_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_auto_size" value="1" {S_AUTO_SIZE_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_auto_size" value="0" {S_AUTO_SIZE_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_GUEST_SCORE}<span class="gensmall">{L_GUEST_SCORE_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="games_guest_highscore" value="1" {S_GUEST_SCORE_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_guest_highscore" value="0" {S_GUEST_SCORE_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_AT_HIGHSCORE}<span class="gensmall">{L_AT_HIGHSCORE_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_at_highscore" value="1" {S_AT_HIGHSCORE_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_at_highscore" value="0" {S_AT_HIGHSCORE_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_SHOW_STATS}<span class="gensmall">{L_SHOW_STATS_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="games_show_stats" value="1" {S_SHOW_STATS_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_show_stats" value="0" {S_SHOW_STATS_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_BAN_USERS}<span class="gensmall">{L_BAN_USERS_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_mod_ban_users" value="1" {S_MOD_BAN_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_mod_ban_users" value="0" {S_MOD_BAN_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_MOD_RATE}<span class="gensmall">{L_MOD_RATE_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="games_rate" value="1" {S_MOD_RATE_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_rate" value="0" {S_MOD_RATE_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_MOD_COMMENTS}<span class="gensmall">{L_MOD_COMMENTS_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_comments" value="1" {S_MOD_COMMENT_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_comments" value="0" {S_MOD_COMMENT_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_NEW_GAMES}<span class="gensmall">{L_NEW_GAMES_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="games_new_games" value="1" {S_NEW_GAMES_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_new_games" value="0" {S_NEW_GAMES_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_SHOW_PLAYED}<span class="gensmall">{L_SHOW_PLAYED_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_show_played" value="1" {S_SHOW_PLAYED_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_show_played" value="0" {S_SHOW_PLAYED_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_SHOW_ALL}<span class="gensmall">{L_SHOW_ALL_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="games_show_all" value="1" {S_SHOW_ALL_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_show_all" value="0" {S_SHOW_ALL_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_SHOW_FAV}<span class="gensmall">{L_SHOW_FAV_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_show_fav" value="1" {S_SHOW_FAV_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_show_fav" value="0" {S_SHOW_FAV_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_SHOW_MHM}<span class="gensmall">{L_SHOW_MHM_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="games_show_mhm" value="1" {S_SHOW_MHM_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_show_mhm" value="0" {S_SHOW_MHM_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_USE_LOG}<span class="gensmall">{L_USE_LOG_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="games_use_log" value="1" {S_USE_LOG_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_use_log" value="0" {S_USE_LOG_NO} /> {L_NO}
   </td>
  </tr>
  
  <th class="thHead" colspan="2">{L_EXTRAS}</th>
  
  <tr>
   <td class="row2">{L_CHEAT_TXT}<span class="gensmall">{L_CHEAT_TXT_INFO}</span></td>
   <td class="row2" align="center">
     <input type="radio" name="games_cheat_mode" value="1" {S_USE_CHEAT_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_cheat_mode" value="0" {S_USE_CHEAT_NO} /> {L_NO}
   </td>
  </tr>
<!-- BEGIN cheat_menu -->
  <tr>
   <td class="row2">{L_WARN_CHEATER}<span class="gensmall">{L_WARN_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="warn_cheater" value="1" {S_WARN_CHEATER_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="warn_cheater" value="0" {S_WARN_CHEATER_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_REPORT_CHEATER}<span class="gensmall">{L_REPORT_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="report_cheater" value="1" {S_REPORT_CHEATER_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="report_cheater" value="0" {S_REPORT_CHEATER_NO} /> {L_NO}
   </td>
  </tr>
<!-- END cheat_menu -->
  <tr>
   <td class="row1">{L_PMS_TXT}<span class="gensmall">{L_PMS_TXT_INFO}</span></td>
   <td class="row1" align="center">

     <input type="radio" name="games_use_pms" value="1" {S_USE_PMS_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_use_pms" value="0" {S_USE_PMS_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_TOURNAMENT_TXT}<span class="gensmall">{L_TOURNAMENT_TXT_INFO}</span></td>
   <td class="row2" align="center">
     <input type="radio" name="games_tournament_mode" value="1" {S_USE_TOURNAMENT_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_tournament_mode" value="0" {S_USE_TOURNAMENT_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_MODERATORS_TXT}<span class="gensmall">{L_MODERATORS_TXT_INFO}</span></td>
   <td class="row1" align="center">
     <input type="radio" name="games_moderators_mode" value="1" {S_USE_MODERATORS_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_moderators_mode" value="0" {S_USE_MODERATORS_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="cat" colspan="2" align="center">{S_HIDDEN_FIELDS} 
    <input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;
    <input type="reset" value="{L_RESET}" class="mainoption" />
   </td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
