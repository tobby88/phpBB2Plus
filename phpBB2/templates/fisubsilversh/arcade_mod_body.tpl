<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr>
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
</table>

<form method="post" action="{S_ACTION}">
	<table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
		<tr>
			<th class="thTop" colspan="6">{MOD_MENU}</th>
		</tr>
		<tr>
			<td class="row1" align="center" colspan="6"><span class="gen">{MOD_BUTTONS}&nbsp;
				<input type="submit" name="mod_submit" value="{L_SUBMIT}" class="mainoption" /></span></td>
		</tr>
		<!-- BEGIN game_menu -->
		<tr>
			<th class="thCornerL" align="center">{L_ARCADE_MOD_IMAGE}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_FILENAME}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_DESCRIPTION}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_PATH}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_STATS}</th>
			<th class="thCornerR" align="center">&nbsp;</th>
		</tr>
		<!-- END game_menu -->
		<!-- BEGIN scores_edit_menu -->
		<tr>
			<th class="thTop" align="center" colspan="6">{L_ARCADE_MOD_SCORE_EDITOR}</th>
		</tr>
		<tr>
			<th class="thCornerL" align="center">{L_ARCADE_MOD_PLAYER}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_DATE}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_TIME}</th>
			<th class="thTop" align="center">{L_ARCADE_MOD_SCORE}</th>
			<th class="thCornerR" align="center" colspan="2">{L_ARCADE_MOD_ACTIONS}</th>
		</tr>
		<!-- END scores_edit_menu -->
		<!-- BEGIN game -->
		<tr>
			<td class="row2" width="10%">{game.IMAGE}<br /><span class="gensmall">{game.IMAGE_PATH}</span></td>
			<td class="row2" width="10%">{game.NAME}<br /><br /><span class="gensmall"><i>{L_ARCADE_MOD_FLASH}: {game.FLASH}</i></span></td>
			<td class="row2" width="40%">{game.DESC}<br /><span class="gensmall"><i>{game.INST}</i></span></td>
			<td class="row2" width="20%">{game.PATH}</td>
			<td class="row2" width="18%">{game.WIDTH}x{game.HEIGHT}<br /><span class="gensmall">{L_ARCADE_MOD_AUTOSIZE}: {game.AUTO}</span></td>
			<td class="row2" width="2%"><input type="radio" name="game_id" value="{game.GAME_ID}" /></td>
		</tr>
		<!-- END game -->
		<!-- BEGIN highscores -->
		<tr>
			<td class="row2" width="15%">{highscores.PLAYER}</td>
			<td class="row2" width="20%">{highscores.DATE}</td>
			<td class="row2" width="15%">{highscores.TIME}</td>
			<td class="row2" width="15%">{highscores.SCORE}</td>
			<td class="row2" width="35%" colspan="2">{highscores.DELETE_IMG}&nbsp; {highscores.IP_IMG}</td>
		</tr>
		<!-- END highscores -->
		<tr>
			<td class="cat" colspan="6" align="center">&nbsp;</td>
		</tr>
	</table>
	{S_HIDDEN_OPTIONS}
</form>

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr>
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
</table>

<br />
<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
