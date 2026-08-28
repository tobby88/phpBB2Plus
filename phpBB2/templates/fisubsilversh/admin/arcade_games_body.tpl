<!-- phpBB Arcade Admin Games Template v2.1.7 -->

<form action="{S_CONFIG_ACTION}" method="post">

 <table width="99%" cellpadding="4" cellspacing="0" border="0" align="center" class="bodyline">
  <tr> 
   <th class="thHead" colspan="2">{L_GAME_MENU} {VERSION}</th>
  </tr>

  <tr>
   <td class="catTop" colspan="2">
     <span class="gensmall">
		{L_HEADER}
     </span>
   </td>
  </tr>

  <tr>
   <td class="catBottom" colspan="2"></td>
  </tr>
</table>

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr> 
		<td align="right" nowrap="nowrap"><span class="gensmall">{MOVE_TO}&nbsp;{MOVE_ITEM}&nbsp;&nbsp;-:-&nbsp;&nbsp;{S_MODE_SELECT}&nbsp;&nbsp;{S_ORDER_SELECT}&nbsp;&nbsp;<input type="submit" name="sort_submit" value="{L_SORT}" class="mainoption" /></span></td>
	</tr>
</table>

<table width="99%" cellspacing="0" cellpadding="4" border="0" align="center" class="bodyline">
  <tr>
    <th class="thCornerL" width="5%">{L_MONEY}</th>
    <th class="thTop" width="15%">{L_BUTTON}</th>
    <th class="thTop" width="30%">{L_DESC}</th>
    <th class="thTop" width="35%">{L_STATS}</th>
    <th class="thTop" width="5%">{L_GUEST}</th>
    <th class="thTop" width="5%">{L_AVAIL}</th>
    <th class="thTop" width="2%">{L_REWARD}</th>
    <th class="thTop" width="2%">{L_BONUS}</th>
    <th class="thTop" width="2%">{L_PLAYED}</th>
    <th class="thCornerR" width="4%" colspan="4">{L_ACTION}</th>
  </tr>

  <!-- BEGIN game -->
  <tr>
    <td class="{game.ROW_CLASS}" align="center">{game.CHARGE}</td>
    <td class="{game.ROW_CLASS}"><img src ="{game.IMAGE}" alt="{game.ID} - {game.NAME}" border="0" align="middle" width="{game.IMAGE_WIDTH}" height="{game.IMAGE_HEIGHT}"></td>
    <td class="{game.ROW_CLASS}"><b>{game.DESC}</b><br /><span class="gensmall">[{game.CAT_ID}]<br />({game.WIDTH}x{game.HEIGHT})<br />{game.CONTROL}</span></td>
    <td class="{game.ROW_CLASS}" align="center"><span class="gensmall">{game.STATS}</span></td>
    <td class="{game.ROW_CLASS}" align="center">{game.GUEST}</td>
    <td class="{game.ROW_CLASS}" align="center">{game.AVAIL}</td>
    <td class="{game.ROW_CLASS}" align="center">{game.REWARD}</td>
    <td class="{game.ROW_CLASS}" align="center">{game.BONUS}</td>
    <td class="{game.ROW_CLASS}" align="center">{game.PLAYED}</td>
    <td class="{game.ROW_CLASS}"><a href="{game.U_GAME_EDIT}">{IMAGE_EDIT}</a></td>
    <td class="{game.ROW_CLASS}" align="center">{game.IMAGE_UP}{game.IMAGE_DOWN}</td>
    <td class="{game.ROW_CLASS}"><a href="{game.U_GAME_DELETE}">{IMAGE_DEL}</a></td>
    <td class="{game.ROW_CLASS}">{MOVE}<br><input type="submit" name="id" value="{game.ID}"></td>
  </tr>
  <!-- END game -->

  <tr>
    <td colspan="16" class="cat" align="center">
      <input type="submit" name="add_game" value="{L_ADD}" class="mainoption" />
      <input type="submit" name="repair_game" value="{L_REPAIR}" class="mainoption" />
      <input type="submit" name="clear_scores" value="{L_RESET_SCORE}" class="mainoption" />
      <input type="submit" name="clear_at_scores" value="{L_RESET_AT_SCORE}" class="mainoption" />
	{S_HIDDEN_FIELDS}
    </td>
  </tr>
</table>
<table width="100%" cellspacing="2" cellpadding="2" border="0">
	<tr> 
		<td><span class="nav">{PAGE_NUMBER}</span></td>
		<td align="right"><span class="nav">{PAGINATION}</span></td>
	</tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
