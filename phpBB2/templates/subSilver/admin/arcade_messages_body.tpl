<!-- phpBB Arcade Admin Messages Template v2.1.7 -->

<form method="post" action="{S_ACTION}">
<table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
 <tr> 
  <th class="thHead" colspan="2">{L_MESS_HEADER} {VERSION}</th>
 </tr>

 <tr>
  <td class="catTop" colspan="2"><span class="gensmall">{L_MESS_INFO}</span></td>
 </tr>
 <tr>
   <td class="catBottom" colspan="2" align="center">&nbsp;</td>
 </tr>
  <tr>
   <th class="thHead" colspan="2" align="center">Private Message System</td>
  </tr>

 <tr>
   <td class="row1"><span class="gensmall">{L_MESS_NEW}</span></td>
   <td class="row1" align="center" width="20%">
     <input type="radio" name="games_pm_new" value="1" {S_PM_NEW_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_pm_new" value="0" {S_PM_NEW_NO} /> {L_NO}</td>
  </tr>
 </tr>
 <tr>
   <td class="row2"><span class="gensmall">{L_MESS_HIGHSCORE}</span></td>
   <td class="row2" align="center" width="20%">
     <input type="radio" name="games_pm_highscore" value="1" {S_PM_HIGH_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_pm_highscore" value="0" {S_PM_HIGH_NO} /> {L_NO}</td>
 </tr>
 <tr>
   <td class="row1"><span class="gensmall">{L_MESS_AT_HIGHSCORE}</span></td>
   <td class="row1" align="center" width="20%">
     <input type="radio" name="games_pm_at_highscore" value="1" {S_PM_AHIGH_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_pm_at_highscore" value="0" {S_PM_AHIGH_NO} /> {L_NO}</td>
 </tr>
 <tr>
   <td class="row2"><span class="gensmall">{L_MESS_COMMENT}</span></td>
   <td class="row2" align="center" width="20%">
     <input type="radio" name="games_pm_comment" value="1" {S_PM_COMMENT_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="games_pm_comment" value="0" {S_PM_COMMENT_NO} /> {L_NO}</td>
 </tr>

  </tr>
  <tr>
   <td class="cat" colspan="2" align="center">{S_HIDDEN_FIELDS} 
    <input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;
   </td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="copyright">Activity Mod {VERSION} - by <a href="http://www.phpbb-amod.co.uk/">dEfEndEr</a></span></div>
