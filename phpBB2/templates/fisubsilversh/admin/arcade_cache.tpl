<!-- phpBB Arcade Admin Cache Template v2.1.7 -->

<form action="{S_CONFIG_ACTION}" method="post">

 <table width="99%" cellpadding="4" cellspacing="0" border="0" align="center" class="bodyline">
  <tr> 
   <th class="thHead" colspan="2">{L_CACHE_MENU} {VERSION}</th>
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
   <td class="row1">{L_USE_CACHE}<span class="gensmall">{L_USE_CACHE_INFO}</span></td>
   <td class="row1" align="center">
     <input type="radio" name="use_cache" value="1" {S_USE_CACHE_YES} onClick="" /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="use_cache" value="0" {S_USE_CACHE_NO} onClick=""/> {L_NO}
   </td>
  </tr>
<!-- BEGIN cache_menu_on -->
  <tr>
   <th class="thHead" colspan="2" align="center">{L_ARCADE_CACHE}</td>
  </tr>
  <tr>
   <td class="row1">{L_CONFIG}<span class="gensmall">{L_CONFIG_INFO}</span></td>
   <td class="row1" align="right">{L_MINS} {DASH} <input class="post" type="text" size="5" name="config_cache" value="{S_CONFIG_CACHE}" /></td>
  </tr>
  <tr>
   <td class="row2">{L_CAT}<span class="gensmall">{L_CAT_INFO}</span></td>
   <td class="row2" align="right">{L_MINS} {DASH} <input class="post" type="text" size="5" name="categories_cache" value="{S_CAT_CACHE}" /></td>
  </tr>

  <tr>
   <td class="row1">{L_GAMES}<span class="gensmall">{L_GAMES_INFO}</span></td>
   <td class="row1" align="right">{L_MINS} {DASH} <input class="post" type="text" size="5" name="games_cache" value="{S_GAMES_CACHE}" /></td>
  </tr>

   <tr>
   <td class="row2">{L_HIGHSCORE}<span class="gensmall">{L_HIGHSCORE_INFO}</span></td>
   <td class="row2" align="right">{L_MINS} {DASH} <input class="post" type="text" size="5" name="highscore_cache" value="{S_HIGHSCORE_CACHE}" /></td>
  </tr>
  <tr>
   <td class="row1">{L_AT_HIGHSCORE}<span class="gensmall">{L_AT_HIGHSCORE_INFO}</span></td>
   <td class="row1" align="right">{L_MINS} {DASH} <input class="post" type="text" size="5" name="at_highscore_cache" value="{S_AT_HIGHSCORE_CACHE}" /></td>
  </tr>
  
<!-- END cache_menu_on -->
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
