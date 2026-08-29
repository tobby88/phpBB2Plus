 <table width="99%" cellpadding="4" cellspacing="0" border="0" align="center" class="bodyline">
  <tr> 
   <th class="thHead" colspan="2">{L_GAME_MENU} {VERSION}</th>
  </tr>

  <tr>
   <td class="catTop" colspan="2"><span class="gensmall">{L_SELECT_GAMES}</span></td>
  </tr>

  <tr>
   <td class="catBottom" colspan="2"></td>
  </tr>
</table>

<form action="{S_CONFIG_ACTION}" method="post">
{S_FORM_TOKEN}
<table width="100%" cellspacing="2" cellpadding="2" border="0">
	<tr> 
		<td><span class="nav">{CATAGORY}</span></td>
		<td align="right"><span class="nav">{CAT_SELECT}</span></td>
	</tr>
</table>
</form>

<form action="{S_CONFIG_ACTION}" method="post">
{S_FORM_TOKEN}
<table width="99%" cellspacing="0" cellpadding="4" border="0" align="center" class="bodyline">
  <tr>
    <th class="thTop" width="15%">{L_BUTTON}</th>
    <th class="thTop" width="30%">{L_GAME_DESC}</th>
    <th class="thTop" width="35%">{L_SELECT}</th>
  </tr>

  <!-- BEGIN game -->
  <tr>
    <td class="{game.ROW_CLASS}"><img src ="{game.IMAGE}" alt="{game.ID} - {game.NAME}" border="0" align="middle" width="{game.IMAGE_WIDTH}" height="{game.IMAGE_HEIGHT}"></td>
    <td class="{game.ROW_CLASS}"><b>{game.DESC}</b><br /><span class="gensmall">[{game.CAT_ID}]<br />{game.CONTROL}</span></td>
    <td class="{game.ROW_CLASS}" align="center"><span class="gensmall">{game.SELECT}</span></td>
   </tr>
  <!-- END game -->

  <tr>
    <td colspan="15" class="cat" align="center">
      <input type="submit" name="add_game" value="{L_ADD}" class="mainoption" />
	{S_HIDDEN_ADD}
    </td>
  </tr>
</table>
</form>

<table width="100%" cellspacing="2" cellpadding="2" border="0">
	<tr> 
		<td><span class="nav">{PAGE_NUMBER}</span></td>
		<td align="right"><span class="nav">{PAGINATION}</span></td>
	</tr>
</table>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
