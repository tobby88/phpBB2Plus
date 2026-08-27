<!-- phpBB Arcade Admin Categories Template v2.1.7 -->

<form action="{S_CONFIG_ACTION}" method="post">
  <table width="99%" cellpadding="2" cellspacing="0" border="0" align="center" class="bodyline">
    <tr> 
      <th class="thHead" colspan="6">{L_CONFIG_MENU} {VERSION}</th>
    </tr>
    <tr> 
      <td class="catTop" colspan="6"><span class="gensmall">{L_INA_HEADER}</span></td>
    </tr>
    <tr> 
      <td class="catBottom" colspan="6"></td>
    </tr>
    <tr> 
      <th class="thCornerL" width="5%">{L_CAT_ID}</th>
      <th class="thTop" colspan="2" width="60%">{L_CATS}</th>
    <th class="thCornerR" colspan=3" width="5%">{L_ACTION}</th>
    </tr>
    <!-- BEGIN cats --> 
    <tr> 
      <td class="{cats.ROW_CLASS}" rowspan="2" width="5%" align="center"><span class="gen">{cats.CAT_ID}</span></td>
      <td class="{cats.ROW_CLASS}" rowspan="2" width="10%" align="center"><span class="gen">{cats.ICON}</span></td>
      <td class="{cats.ROW_CLASS}" rowspan="2" align="left"><span class="gen">{cats.NAME}</span><br />
        <span class="gensmall">{cats.DESC}{cats.SUBS}</span></td>
      <td class="{cats.ROW_CLASS}" colspan="3" align="center" nowrap>{cats.EDIT_GAMES}</td>
    </tr>
    <tr> 
      <td class="{cats.ROW_CLASS}" align="right"><a href="{cats.U_CAT_EDIT}">{IMAGE_EDIT}</a></td>
      <td class="{cats.ROW_CLASS}" align="center"><a href="{cats.U_CAT_UP}">{cats.IMAGE_UP}</a><a href="{cats.U_CAT_DOWN}">{cats.IMAGE_DOWN}</a></td>
      <td class="{cats.ROW_CLASS}"><a href="{cats.U_CAT_DELETE}">{IMAGE_DEL}</a></td>
    </tr>
    <!-- END cats --> 
    <tr> 
      <td colspan="14" class="cat" align="center"> 
        <input type="submit" name="add_cat" value="{L_ADD}" class="mainoption" />
        <input type="submit" name="resync_cats" value="{L_RESYNC}" class="mainoption" />
      </td>
    </tr>
  </table>
<br />
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>

<!-- phpBB Arcade Admin Categories Template -->
