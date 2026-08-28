<!-- phpBB Arcade Admin Categories Edit Template v2.1.7 -->

<form method="post" action="{S_GAME_ACTION}">
<table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
 <tr> 
  <th class="thHead" colspan="2">{L_MENU_HEADER} {VERSION}</th>
 </tr>

 <tr>
  <td class="catTop" colspan="2"><span class="gensmall">{L_MENU_INFO}</span></td>
 </tr>
 <tr>
   <td class="catBottom" colspan="2" align="center">&nbsp;</td>
 </tr>

 <tr>
   <td class="row1" colspan="2" align="left">{DISPLAY_ICON}</td>
 </tr>

 <tr>
   <td class="row1">{L_ICON}<span class="gensmall">{L_ICON_INFO}</td>
   <td class="row2" width="20%"><input class="post" type="text" size="40" name="icon" value="{ICON}" /></td>
 </tr>

 <tr>
   <td class="row1">{L_NAME}<span class="gensmall">{L_NAME_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="40" name="name" value="{NAME}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_TYPE}<span class="gensmall">{L_TYPE_INFO}</span></td>
   <td class="row2" width="20%">{TYPE}</td>
 </tr>
 <tr>
   <td class="row1">{L_PARENT}<span class="gensmall">{L_PARENT_INFO}</span></td>
   <td class="row2" width="20%">{PARENT}</td>
 </tr>

 <tr>
   <td class="row1">{L_GROUP}<span class="gensmall">{L_GROUP_INFO}</span></td>
   <td class="row2" width="20%">{GROUP}</td>
 </tr>
 <tr>
   <td class="row1"><b>{L_MODERATOR}</b><br /><span class="gensmall">{L_MODERATOR_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="40" name="moderator" value="{MODERATOR}" /></td>
 </tr>

 <tr>
   <td class="row1">{L_SPECIAL}<span class="gensmall">{L_SPECIAL_INFO}</span></td>
   <td class="row2" width="20%"><input class="post" type="text" size="10" name="special" value="{SPECIAL}" /></td>
 </tr>

 <tr> 
  <th class="thHead" colspan="2">{L_DESC}</th>
 </tr>

 <tr>
   <td class="row2" colspan="2" align="center"><span class="gentblsmall">{L_DESC_INFO}</span>
     <textarea rows="10" cols="100%" wrap="virtual" name="desc" class="post">{DESC}</textarea></td>
   </td>
 </tr>

 <tr>
   <td class="cat" colspan="2" align="center">
     <input class="mainoption" type="submit" value="{L_SUBMIT}" />
     <input class="liteoption" type="reset" value="{L_RESET}" />
   </td>
 </tr>
</table>
{S_HIDDEN_FIELDS}
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
