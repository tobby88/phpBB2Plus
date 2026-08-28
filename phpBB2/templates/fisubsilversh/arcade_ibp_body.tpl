<table width="75%" border="0" align="center">
	<tr>
		<td align="center" nowrap="nowrap" width="100%">
			<form method="post" action="{S_MODE_ACTION}">
				<span class="genmed">
					<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
						<legend align="center"> {ORDER_SELECT_TITLE} </legend>
							<img src="{HEADER_LOGO}" border="0" align="left">
							<br />
						{L_SELECT_SORT_METHOD}:  {S_MODE_SELECT}      {L_ORDER}: {S_ORDER_SELECT}      <input type="submit" name="submit" value="{L_SUBMIT}" class="liteoption">
							<br />
					</fieldset>
				</span>
			</form>
		</td>
	</tr>
</table>
<table width="100%" align="center" valign="top">
	<tr>
		<form>
			<td align="center" nowrap="nowrap" width="33%" valign="top">
				<span class="genmed">
					<select onchange="if(options[selectedIndex].value)window.location.href=(options[selectedIndex].value)">
						<option selected value="">{D_DEFAULT}</option>
					<!-- BEGIN drop -->
						<option value="{drop.D_SELECT_1}">{drop.D_SELECT_2}</option>
					<!-- END drop -->
					</select>
					<noscript><input type="submit" value="Go"></noscript>
				</span>
			</td>
		</form>
		<td align="center" nowrap="nowrap" width="33%" valign="top">
			<span class="genmed">
				<img src="{RANDOM_IMAGE}" border="0"> <a href="{RANDOM_GAME}" class="nav">{RANDOM_LINK}</a> <img src="{RANDOM_IMAGE}" border="0">
			</span>
		</td>
		<form>
			<td align="center" nowrap="nowrap" width="33%" valign="top">
				<span class="genmed">
					<select onchange="if(options[selectedIndex].value)window.location.href=(options[selectedIndex].value)">
						<option selected value="">{C_DEFAULT}</option>
						<option value="{C_DEFAULT_ALL}">{C_DEFAULT_ALL_L}</option>
						<option value="{C_CAT_PAGE}">{L_CAT_PAGE}</option>
					<!-- BEGIN cat -->
						<option value="{cat.C_SELECT_2}">{cat.C_SELECT_1}</option>
					<!-- END cat -->
					</select>
					<noscript><input type="submit" value="Go"></noscript>
				</span>
			</td>
		</form>
	</tr>
</table>
<br />
	<!-- BEGIN links_check -->
<table width="100%" border="0" align="center">
	<tr>
		<td align="center">
			<span class="nav">
				{links_check.LINKS}
			</span>
		</td>
	</tr>
</table>
	<!-- END links_check -->

{ACTIVITY_INFO_SECTION}

{ACTIVITY_DAILY_SECTION}

{ACTIVITY_NEWEST_SECTION}

<!-- BEGIN games_on -->
<table class="forumline" width="100%" cellspacing="0" cellpadding="5" border="0" align="center">
	<tr>
		<th class="thTop" width="20%">{L_GAMES}</th>
		<th class="thTop" width="15%">{L_T_HOLDER}</th>
		<th class="thTop" width="20%">{L_STATS}</th>
		<th class="thTop" width="45%">{L_INFO}</th>
	</tr>
<!-- BEGIN game -->
	<tr>
		<td class="{games_on.game.ROW_CLASS}" width="20%">
			<span class="genmed">
			<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
			<legend> {games_on.game.PROPER_NAME} </legend>
				<table align="center" width="100%" valign="top">
					<tr>
						<td align="left" width="50%">
							{games_on.game.NEW_I_LINK}{games_on.game.IMAGE_LINK}</a>
						</td>
						<td align="center" width="50%">
							{games_on.game.KEYBOARD}{games_on.game.MOUSE}
						</td>
					</tr>
					<tr>
						<td align="left" width="100%" colspan="2">
							<span class="genmed">
								{games_on.game.LINKS}{games_on.game.DOWNLOAD_LINK}<br>
							</span>
						</td>
					</tr>
				</table>
			</fieldset>
		</span>
		</td>
		<td class="{games_on.game.ROW_CLASS}" width="15%">
			<span class="genmed">
				{games_on.game.TROPHY_IMG}  {games_on.game.TOP_PLAYER}<br />{games_on.game.TOP_SCORE}<br /><br />
				{games_on.game.RUNNER_IMG}  {games_on.game.BEST_PLAYER}<br />{games_on.game.BEST_SCORE}
				<br><br>{games_on.game.FAVORITE_GAME}
			</span>
		</td>
		<td class="{games_on.game.ROW_CLASS}" width="20%">
			<span class="genmed">
				{games_on.game.SEPERATOR}<a href="{games_on.game.COMMENTS}" class="nav">{games_on.game.L_COMMENTS}</a>
				{games_on.game.CHALLENGE}
				{games_on.game.LIST}
				<br>{games_on.game.SEPERATOR}<a href="{games_on.game.STATS}" class="nav">{games_on.game.INFO}</a><br>
				{games_on.game.GAMES_PLAYED} {games_on.game.I_PLAYED}
				<br><center>{games_on.game.POP_PIC}</center>
			</span>
		</td>
		<td class="{games_on.game.ROW_CLASS}" width="45%">
			<span class="genmed">
				<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
					<legend align="center"> {games_on.game.DESC2} </legend>
					{games_on.game.DESC}
				</fieldset>
				<br />
				<fieldset class="fieldset" style="margin: 0px 0px 0px 0px;">
					<legend> {games_on.game.RATING_TITLE} </legend>
						<br />
						{games_on.game.SEPERATOR}{games_on.game.RATING_SENT}  {games_on.game.RATING_SUBMIT}  {games_on.game.RATING_IMAGE}<br />
				</fieldset>
			</span>
		</td>
	</tr>
<!-- END game -->
	<tr>
		<th class="thTop" width="100%" align="center" colspan="4">&nbsp;</th>
	</tr>
</table>
<!-- END games_on -->

{ACTIVITY_ONLINE_SECTION}

<!-- BEGIN games_on -->
<table width="100%" cellspacing="0" cellpadding="0" border="0">
	<tr>
		<td><span class="nav">{PAGE_NUMBER}</span></td>
		<td align="right"><span class="nav">{PAGINATION}</span></td>
	</tr>
</table>
<!-- END games_on -->

{GAMELIB_LINK}