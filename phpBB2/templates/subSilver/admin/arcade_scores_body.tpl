<!-- phpBB Arcade Admin Scores Template v2.1.7 -->

<form method="post" action="{S_ACTION}">
<table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
 <tr> 
  <th class="thHead" colspan="6">{L_SCORE_HEADER}&nbsp;{VERSION}</th>
 </tr>
 <tr>
  <td class="catTop" colspan="6"><span class="gensmall">{L_SCORE_INFO}</span></td>
 </tr>
  <tr>
  <th class="thHead" align="center" colspan="6">{L_SCORE_EDITOR}&nbsp;-:-&nbsp;{GAME_DESC}</th>
</tr>

    <tr> 
      <td class="row1" width="10%">{PLAYER}&nbsp;</td>
      <td class="row1" width="15%">{DATE}</td>
      <td class="row1" width="10%">{TIME}</td>
      <td class="row1" width="10%">{SCORE}</td>
      <td class="row1" width="10%">{FUNCTION}</td>
    </tr>

 <!-- BEGIN highscores --> 
    <tr><a name="{highscores.PLAYER}" id="{highscores.PLAYER}"></a>
      <td class="row2" width="10%">{highscores.PLAYER}</td>
      <td class="row2" width="15%">{highscores.DATE}</td>
      <td class="row2" width="10%">{highscores.TIME}</td>
      <td class="row2" width="10%">{highscores.SCORE}</td>
      <td class="row2" width="10%">{highscores.EDIT_IMG}&nbsp;{highscores.DELETE_IMG}&nbsp;{highscores.IP_IMG}</td>
    </tr>
<!-- END highscores --> 


 </tr>
  <tr>
   <td class="cat" colspan="6" align="center">{S_HIDDEN_FIELDS}{S_MODE}</td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
