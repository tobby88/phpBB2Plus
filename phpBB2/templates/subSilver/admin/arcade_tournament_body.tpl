<form method="post" action="{S_ACTION}">
<table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
 <tr> 
  <th class="thHead" colspan="4">{L_TOUR_HEADER} {VERSION}</th>
 </tr>
 <tr>
  <td class="catTop" colspan="4"><span class="gensmall">{L_TOUR_INFO}</span></td>
 </tr>
  <tr>
   <th class="thHead" colspan="4" align="center">{TOURNAMENT_SETTINGS}</td>
  </tr>


   <tr>
   <td class="row1" colspan="3"><span class="gensmall">{L_MAX_TOUR}</span></td>
   <td class="row1" align="center"><input class="post" type="text" size="5" name="games_tournament_max" value="{S_MAX_TOUR}" /></td>
  </tr>
  <tr>
   <td class="row2" colspan="3"><span class="gensmall">{L_MAX_GAMES}</span></td>
   <td class="row2" align="center"><input class="post" type="text" size="5" name="games_tournament_games" value="{S_MAX_GAMES}" /></td>
  </tr>
  <tr>
   <td class="row1" colspan="3"><span class="gensmall">{L_MAX_PLAYERS}</span></td>
   <td class="row1" align="center"><input class="post" type="text" size="5" name="games_tournament_players" value="{S_MAX_PLAYERS}" /></td>
  </tr>
  <tr>
   <td class="row2" colspan="3"><span class="gensmall">{L_USER_START}</span></td>
   <td class="row2" align="center">
     <input type="radio" name="games_tournament_user" value="1" {S_USER_START_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_tournament_user" value="0" {S_USER_START_NO} /> {L_NO}</td>
  </tr>

 <tr>
   <th class="thHead" colspan="4" align="center">{TOURNAMENTS}</td>
  </tr>

	<!-- BEGIN tournament_none -->
	<tr>
		<td class="row1" colspan="4" align="center"><span class="gen">{L_NONE}</span></td>
	</tr>
	<!-- END tournament_none -->
	<!-- BEGIN tour -->
	<tr>
		<td class="{tour.ROW_CLASS}" width="50%" align="left"><span class="gen"><b>{tour.NAME}</b>&nbsp;-&nbsp;<i>{tour.DESC}</i></span></td>
    <td class="{tour.ROW_CLASS}" align="center" colspan="2"><span class="gensmall">{tour.TOTAL_GAMES}{tour.TOTAL_PLAYERS}</span></td>

    <td class="{tour.ROW_CLASS}" width="20%" align="center"><span class="gen"><a href="{tour.EDIT}">{IMAGE_EDIT}</a>&nbsp;&nbsp;<a href="{tour.DELETE}">{IMAGE_DEL}</a></span></td>
	</tr>
	<!-- END tour -->

 </tr>
  <tr>
   <td class="cat" colspan="4" align="center">{S_HIDDEN_FIELDS} 
    <input type="submit" name="tour_submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;
    <input type="submit" name="add_tour" value="{L_ADD}" class="mainoption" />&nbsp;
   </td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
