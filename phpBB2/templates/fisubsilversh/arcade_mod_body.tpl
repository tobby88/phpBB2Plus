<form method="post" action="{S_MODE_ACTION}">
<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT} ***** ALPHA RELEASE *****</span></td>
	</tr>
</table>
</form>

<form method="post" action="{S_ACTION}">
  <table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
    <tr> 
      <th class="thTop" colspan="6">***** ALPHA - {MOD_MENU} - ALPHA *****</th>
    </tr>
    <tr> 
      <td class="row1" align="center" colspan="6"><span class="gen">{MOD_BUTTONS}&nbsp; 
        <input type="submit" name="mod_submit" value="{L_SUBMIT}" class="mainoption" /></span></td>
    </tr>
<!-- BEGIN game_menu -->
      <th class="thCornerL" align="center">Image Info</th>
      <th class="thTop" align="center">Filename</th>
      <th class="thTop" align="center">Game Description</th>
      <th class="thTop" align="center">Path to Game</th>
      <th class="thTop" align="center">Stats</th>
      <th class="thCornerR" align="center">&nbsp;</th>
<!-- END game_menu -->
<!-- BEGIN game_edit_menu -->
	 <th class="thTop" align="center" colspan="2">{game_edit_menu.EDITING_GAME}&nbsp;{game_edit_menu.DESC}</th>
<tr>
   <td class="row1">{L_NAME}
    <span class="gensmall">
	{L_NAME_INFO}
    </span>
   </td>
   <td class="row2" width="20%">
     <input class="post" type="text" size="40" name="game_name" value="{game_edit_menu.NAME}" />
   </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_PATH}
    <span class="gensmall">
	{L_GAME_PATH_INFO}
   </td>
   <td class="row2" width="20%">
     <input class="post" type="text" size="40" name="game_path" value="{game_edit_menu.PATH}" />
   </td>
 </tr>

 <tr>
   <td class="row1">{L_IMAGE_PATH}
    <span class="gensmall">
	{L_IMAGE_PATH_INFO}
   </td>
   <td class="row2" width="20%">
     <input class="post" type="text" size="40" name="image_path" value="{game_edit_menu.IMAGE}" />
   </td>
 </tr>


 <tr>
   <td class="row1">{L_GAME_DESC}
    <span class="gensmall">
	{L_GAME_DESC_INFO}
    </span>
   </td>
   <td class="row2" width="20%">
     <input class="post" type="text" size="40" name="game_desc" value="{game_edit_menu.DESC}" />
   </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_CHARGE}
    <span class="gensmall">
	{L_GAME_CHARGE_INFO}
    </span>
   </td>
   <td class="row2" width="20%" align="right">
     {L_MONEY} {L_CHARGE} {DASH} <input class="post" type="text" size="20" name="game_charge" value="{game_edit_menu.CHARGE}" />
   </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_PER}
    <span class="gensmall">
     {L_GAME_PER_INFO}
    </span>
   </td>
   <td class="row2" width="20%" align="right">
     {L_MONEY} {L_REWARD} {DASH} <input class="post" type="text" size="20" name="game_reward" value="{game_edit_menu.REWARD}" />
   </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_BONUS}
    <span class="gensmall">
	{L_GAME_BONUS_INFO}
    </span>
   </td>
   <td class="row2" width="20%" align="right">
     {L_BONUS} {DASH} <input class="post" type="text" size="6" name="game_bonus" value="{BONUS}" /> &nbsp;&nbsp;
     {L_AT_BONUS} {DASH} <input class="post" type="text" size="6" name="game_at_bonus" value="{AT_BONUS}" />
   </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_CAT}<span class="gensmall">{L_GAME_CAT_INFO}</span></td>
   <td class="row2" width="20%" align="center">{CATAGORY}</td>
 </tr>
 <tr>
   <td class="row1">{L_GAME_CONTROL}<span class="gensmall">{L_GAME_CONTROL_INFO}</span></td>
   <td class="row2" width="20%" align="center">{CONTROL}</td>
 </tr>

 <tr>
  <td class="row1">{L_GAME_GAMELIB}
    <span class="gensmall">
	{L_GAME_GAMELIB_INFO}
    </span>
  </td>
  <td class="row2" align="center">
    <input type="radio" name="game_use_gl" value="1" {S_USE_GL_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="game_use_gl" value="0" {S_USE_GL_NO} /> {L_NO}
  </td>
 </tr>

 <tr>
  <td class="row1">{L_GAME_FLASH}
    <span class="gensmall">
	{L_GAME_FLASH_INFO}
    </span>
  </td>
  <td class="row2" align="center">
    <input type="radio" name="game_flash" value="1" {S_USE_FLASH_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="game_flash" value="0" {S_USE_FLASH_NO} /> {L_NO}
  </td>
 </tr>

 <tr>
  <td class="row1">{L_GAME_OFFLINE}
    <span class="gensmall">
	{L_GAME_OFFLINE_INFO}
    </span>
  </td>
  <td class="row2" align="center">
    <input type="radio" name="game_avail" value="1" {S_OFFLINE_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="game_avail" value="0" {S_OFFLINE_NO} /> {L_NO}
  </td>
 </tr>
 <tr>
  <td class="row3">{L_GAME_GUEST}
    <span class="gensmall">
	{L_GAME_GUEST_INFO}
    </span>
  </td>
  <td class="row1" align="center">
    <input type="radio" name="allow_guest" value="1" {S_ALLOW_GUEST_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="allow_guest" value="0" {S_ALLOW_GUEST_NO} /> {L_NO}
  </td>
 </tr>
 <tr>
  <td class="row3">{L_GAME_GROUP}
    <span class="gensmall">
	{L_GAME_GROUP_INFO}
    </span>
  </td>
   <td class="row1" width="20%" align="center">{GROUP_TYPE}</td>
 </tr>
 <tr>
  <td class="row3">{L_GAME_RANK}
    <span class="gensmall">
	{L_GAME_RANK_INFO}
    </span>
  </td>
   <td class="row1" width="20%" align="center">{RANK_TYPE}</td>
 </tr>
 <tr>
  <td class="row3">{L_GAME_LEVEL}
    <span class="gensmall">
	{L_GAME_LEVEL_INFO}
    </span>
  </td>
   <td class="row1" width="20%" align="center">{LEVEL_TYPE}</td>
  </tr>

 <tr>
  <td class="row1">{L_GAME_SHOW_SCORE}
    <span class="gensmall">
	{L_GAME_SHOW_INFO}
    </span>
  </td>
  <td class="row2" align="center">
    <input type="radio" name="game_show_score" value="1" {S_SHOW_SCORE_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="game_show_score" value="0" {S_SHOW_SCORE_NO} /> {L_NO}
  </td>
 </tr>

 <tr>
   <td class="row1">{L_HIGHSCORE_LIMIT}
    <span class="gensmall">
	{L_HIGHSCORE_INFO}
    </span>
   </td>
   <td class="row2" width="20%" align="center">
     {L_LIMIT} {DASH} <input class="post" type="text" size="5" name="highscore_limit" value="{HIGHSCORE_LIMIT}" /> &nbsp;&nbsp;
     {L_AT_LIMIT} {DASH} <input class="post" type="text" size="5" name="at_highscore_limit" value="{AT_HIGHSCORE_LIMIT}" />
   </td>
 </tr>

 <tr>
  <td class="row1">{L_GAME_REVERSE}
    <span class="gensmall">
	{L_GAME_REVERSE_INFO}
    </span>
  </td>
  <td class="row2" align="center">
    <input type="radio" name="game_reverse_list" value="1" {S_REVERSE_LIST_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="game_reverse_list" value="0" {S_REVERSE_LIST_NO} /> {L_NO}
  </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_SIZE}
    <span class="gensmall">
	{L_GAME_SIZE_INFO}
    </span>
   </td>
   <td class="row2" width="20%" align="center">
     {L_WIDTH} {DASH} <input class="post" type="text" size="5" name="win_width" value="{WIDTH}" /> &nbsp;&nbsp;
     {L_HEIGHT} {DASH} <input class="post" type="text" size="5" name="win_height" value="{HEIGHT}" />
   </td>
 </tr>

 <tr>
  <td class="row1">{L_GAME_AUTO_SIZE}
    <span class="gensmall">
	{L_GAME_AUTO_SIZE_INFO}
    </span>
  </td>
  <td class="row2" align="center">
    <input type="radio" name="game_autosize" value="1" {S_AUTO_SIZE_YES} /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="game_autosize" value="0" {S_AUTO_SIZE_NO} /> {L_NO}
  </td>
 </tr>

 <tr>
   <td class="row1">{L_GAME_SCORE_TYPE}<span class="gensmall">{L_GAME_SCORE_TYPE_INFO}</span></td>
   <td class="row2" width="20%" align="center">{SCORE_TYPE}</td>
 </tr>

 <tr>
  <td class="row3">{L_GAME_RESET_SCORE}
    <span class="gensmall">
	{L_GAME_RESET_SCORE_INFO}
    </span>
  </td>
  <td class="row3" align="center">
    <input type="radio" name="reset_scores" value="1" /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="reset_scores" value="0" checked/> {L_NO}
  </td>
 </tr>

 <tr>
  <td class="row3">{L_GAME_RESET_AT_SCORE}
    <span class="gensmall">
	{L_GAME_RESET_AT_SCORE_INFO}
    </span>
  </td>
  <td class="row3" align="center">
    <input type="radio" name="reset_at_scores" value="1" /> {L_YES}&nbsp;&nbsp;
    <input type="radio" name="reset_at_scores" value="0" checked/> {L_NO}
  </td>
 </tr>

 <tr> 
  <th class="thHead" colspan="2">{L_INSTRUCTIONS}</th>
 </tr>

 <tr>
   <td class="row2" colspan="2" align="center">
     <span class="gentblsmall">
	{L_INSTRUCTIONS_INFO}
     </span>
     <textarea rows="10" cols="100%" wrap="virtual" name="game_instructions" class="post">{GAME_INSTRUCTIONS}</textarea></td>
   </td>
 </tr>

 <tr>
   <td class="cat" colspan="2" align="center">
     <input class="mainoption" type="submit" value="{L_SUBMIT}" />
     <input class="liteoption" type="reset" value="{L_RESET}" />
   </td>
 </tr>

<!-- END game_edit_menu -->
<!-- BEGIN scores_edit_menu -->
	 <th class="thTop" align="center" colspan="6">Score Editor</th>
<!-- END scores_edit_menu -->
<!-- BEGIN comments_edit_menu -->
	 <th class="thTop" align="center">&nbsp;</th>
<!-- END comments_edit_menu -->
<!-- BEGIN game --> 
    <tr> 
      <td class="row2" width="10%">{game.IMAGE}&nbsp; {game.IMAGE_PATH}</td>
      <td class="row2" width="10%">{game.NAME}<br /><br /><span class="gensmall"><i>Flash: {game.FLASH}</i></span></td>
      <td class="row2" width="40%">{game.DESC}<br /><span class="gensmall"><i>{game.INST}</i></td>
      <td class="row2" width="20%">{game.PATH}</td>
      <td class="row2" width="18%">{game.WIDTH}x{game.HEIGHT}&nbsp; Autosize: {game.AUTO}<br /></td>
      <td class="row2" width="2%"><input type="radio" name="game_id" value="{game.GAME_ID}"></td>
    </tr>
<!-- END game --> 
<!-- BEGIN highscores --> 
    <tr> 
      <td class="row2" width="10%">{highscores.PLAYER}</td>
      <td class="row2" width="10%">{highscores.DATE}</td>
      <td class="row2" width="10%">{highscores.TIME}</td>
      <td class="row2" width="10%">{highscores.SCORE}</td>
      <td class="row2" width="10%">{highscores.EDIT_IMG}&nbsp;{highscores.DELETE_IMG}&nbsp;{highscores.IP_IMG}</td>
    </tr>
<!-- END highscores --> 
    <tr> 
      <td class="cat" colspan="6" align="center"></td>
    </tr>
  </table>
{S_HIDDEN_OPTIONS}
</form>

<form method="post" action="{S_MODE_ACTION}">
<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT} ***** ALPHA RELEASE *****</span></td>
	</tr>
</table>
</form>

<br />
<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>