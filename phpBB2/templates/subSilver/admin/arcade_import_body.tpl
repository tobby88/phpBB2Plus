<!-- phpBB Arcade Admin Import Template v2.1.7 -->

<form method="post" action="{S_ACTION}">
<table width="100%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
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
   <th class="thHead" colspan="2" align="center">{L_AUTO_IMPORT}</th>
  </tr>

 <tr>
   <td class="row1"><span class="gensmall">{L_IN_PATH_INFO}</span></td>
   <td class="row1" width="20%"><input class="post" type="text" size="40" name="import_path" value="{DIR_NAME}" />
   </td>
 </tr>

 <tr>
   <td class="row1"><span class="gensmall">{L_AMOD_INFO}</span></td>
	<td class="row1" width="20%"><select name="file_type"><option value="default">{L_FILE_PACKAGE}</option><option value="flash">{L_FILE_FLASH}</option><option value="jpg">{L_FILE_JPG}</option><option value="gif">{L_FILE_GIF}</option></select>
   </td>
 </tr>
 
 <tr>
   <td class="row1"><span class="gensmall">{L_SIZE_INFO}</span></td>
	<td class="row1" width="20%"><select name="autosize"><option value="0">{L_SIZE_DETECT}</option><option value="1">{L_SIZE_DEFAULT}</option></select>
   </td>
 </tr>

 <tr>
   <td class="row1"><span class="gensmall">{L_ONLINE_INFO}</span></td>
	<td class="row1" width="20%"> <select name="online"><option value="0">{L_OFFLINE}</option><option value="1">{L_ONLINE}</option></select>
   </td>
 </tr>

 <tr>
   <td class="row1"><span class="gensmall">{L_CAT_INFO}</span></td>
	<td class="row1" width="20%">{CATS}
   </td>
 </tr>

  <tr>
   <td class="cat" colspan="2" align="center">{S_HIDDEN_FIELDS} 
    <input type="submit" name="import_submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;
   </td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
