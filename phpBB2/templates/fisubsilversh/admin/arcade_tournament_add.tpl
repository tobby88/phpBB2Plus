<form method="post" action="{S_ACTION}">
<table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
 <tr> 
  <th class="thHead" colspan="2">{L_TOUR_HEADER} {VERSION}</th>
 </tr>
 <tr>
  <td class="catTop" colspan="2"><span class="gensmall">{L_TOUR_INFO}</span></td>
 </tr>
  <tr>
   <th class="thHead" colspan="2" align="center">{ADD_TOURNAMENT}</td>
  </tr>


  <tr>
   <td class="row1">{L_NAME}<span class="gensmall">{L_NAME_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="25" maxlength="25" name="tour_name" value="{NAME}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_DESC}<span class="gensmall">{L_DESC_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="40" name="tour_desc" value="{DESC}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_PLAYERS}<span class="gensmall">{L_PLAYERS_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="2" name="tour_max_players" value="{PLAYERS}" />&nbsp;&nbsp;{TOTAL_PLAYERS}</td>
 </tr>
 <tr>
   <td class="row1">{L_TURNS}<span class="gensmall">{L_TURNS_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="2" name="tour_player_turns" value="{TURNS}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_BLOCK}<span class="gensmall">{L_BLOCK_INFO}</span></td>
   <td class="row2" width="20%">
     <input type="radio" name="block_plays" value="1" {S_BLOCK_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="block_plays" value="0" {S_BLOCK_NO} /> {L_NO}</td>
 </tr>
 <tr>
   <td class="row1">{L_ACTIVE}<span class="gensmall">{L_ACTIVE_INFO}</span></td>
   <td class="row2" width="20%">{TOUR_ACTIVE} {S_SELECT_ACTIVE}</td>
 </tr>

 </tr>
  <tr>
   <td class="cat" colspan="2" align="center">{S_HIDDEN_ADD} 
    <input type="Submit" name="add_tour_submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;
<!-- BEGIN add -->
{add.ADD_GAMES}{add_ADD_PLAYERS}
<!-- END add -->
   </td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
