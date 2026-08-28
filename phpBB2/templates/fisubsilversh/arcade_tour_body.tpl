<!-- phpBB Arcade Tournament Template v2.1.8 -->

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
<form method="post" action="{S_MODE_ACTION}">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
</form>
</table>

<table class="forumline" width="100%" cellspacing="1" cellpadding="5" border="0" align="center">
<form method="post" action="{S_ACTION}">

	<!-- BEGIN champions -->
	<tr>
		<th class="thTop" width="100%" colspan="4">{CHAMPIONS}</th>
	</tr>
	<tr>
	 <td><span class="forumlink"><center>{champions.LIST}</center></span></td>
	 <td><span class="forumlink"><center>{champions.OF}</center></span></td>
	 <td><span class="gensmall"><center>{champions.NAME}</center></span></td>
	 <td colspan="4"><span class="forumlink"><center>{champions.LINK}</center></span></td>
	</tr>
	<!-- END champions -->
	<!-- BEGIN tour_head -->
	<tr>
		<th class="thTop" width="50%">{tour_head.TOURNAMENT}</th>
		<th class="thTop" width="25%">{tour_head.INFORMATION}</th>
		<th colspan="2" class="thTop" width="25%">{tour_head.ACTION}</th>
	</tr>
	<!-- END tour_head -->
	<!-- BEGIN game_head -->
	<tr>
		<th class="thTop" width="5%">Icon</th>
		<th class="thTop" width="60%">Game</th>
		<th colspan="2" class="thTop" width="35%">Info</th>
	</tr>
	<!-- END game_head -->
  <!-- BEGIN tournament_add -->
	<tr>
		<th class="thCornerL" width="60%">{NAME}</th>
		<th class="thCornerR" colspan="3" nowrap="nowrap" width="20%">{DATA}</th>
	</tr>
  <tr>
   <td class="row1">{L_NAME}<span class="gensmall">{L_NAME_INFO}</span></td>
   <td colspan="3" class="row2" width="20%"><input class="post" type="text" size="25" maxlength="25" name="tour_name" value="{tournament_add.NAME}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_DESC}<span class="gensmall">{L_DESC_INFO}</span></td>
   <td colspan="3" class="row2" width="20%"><input class="post" type="text" size="40" name="tour_desc" value="{tournament_add.DESC}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_PLAYERS}<span class="gensmall">{L_PLAYERS_INFO}</span></td>
   <td colspan="3" class="row2" width="20%"><input class="post" type="text" size="2" name="tour_max_players" value="{tournament_add.PLAYERS}" />&nbsp;&nbsp;{TOTAL_PLAYERS}</td>
 </tr>
 <tr>
   <td class="row1">{L_TURNS}<span class="gensmall">{L_TURNS_INFO}</span></td>
   <td colspan="3" class="row2" width="20%"><input class="post" type="text" size="2" name="tour_player_turns" value="{tournament_add.TURNS}" /></td>
 </tr>
 <tr>
   <td class="row1">{L_BLOCK}<span class="gensmall">{L_BLOCK_INFO}</span></td>
   <td colspan="3" class="row2" width="20%">
     <input type="radio" name="block_plays" value="1" {tournament_add.S_BLOCK_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="block_plays" value="0" {tournament_add.S_BLOCK_NO} /> {L_NO}</td>
 </tr>
 <tr>
   <td class="row1">{L_START}<span class="gensmall">{L_START_INFO}</span></td>
   <td colspan="3" class="row2" width="20%">{tournament_add.S_SELECT_START}</td>
 </tr>
 <tr>
   <td class="row1">{L_END}<span class="gensmall">{L_END_INFO}</span></td>
   <td colspan="3" class="row2" width="20%">{tournament_add.S_SELECT_END}</td>
 </tr>
  <!-- END tournament_add -->
	<!-- BEGIN tournament_none -->
	<tr>
		<td class="row1" colspan="4" align="center"><span class="gen"> {tournament_none.NONE} </span></td>
	</tr>
	<!-- END tournament_none -->
	<!-- BEGIN tour -->
	<tr>
		<td class="{tour.ROW_CLASS}" width="50%" align="left"><span class="gen"><b>{tour.NAME}</b>&nbsp;-&nbsp;<i>{tour.DESC}</i></span></td>
    <td class="{tour.ROW_CLASS}" align="center"><span class="gensmall">{tour.TOTAL_GAMES}&nbsp;-&nbsp;{tour.TOTAL_PLAYERS}</span></td>

    <td colspan="2" class="{tour.ROW_CLASS}" width="20%" align="center"><span class="gen">{tour.JOIN}&nbsp;&nbsp;<a href="{tour.EDIT}">{tour.IMAGE_EDIT}</a>&nbsp;&nbsp;<a href="{tour.DELETE}">{tour.IMAGE_DEL}</a></span></td>
	</tr>
	<!-- END tour -->
	<!-- BEGIN game -->
	<tr><a name="{game.ID}" id="{game.ID}"></a>
		<td class="{game.ROW_CLASS}" width="5%">{game.IMAGE}</td>
		<td class="{game.ROW_CLASS}"><span class="forumlink">{game.CONTROL}{game.DESC}</span></td>
		<td colspan="2" class="{game.ROW_CLASS}" align="center" wrap="nowrap"><span class="gensmall">{game.INFO}</span></td>
	</tr>
	<!-- END game -->
	<!-- BEGIN player_head -->
	<tr>
		<th class="thCornerL" width="100%" colspan="4">Players</th>
	</tr>
	<!-- END player_head -->
	<!-- BEGIN player -->
	<tr><a name="{player.ID}" id="{game.ID}"></a>
		<td class="{player.ROW_CLASS}" colspan="4" align="center"><span class="forumlink">{player.NAME}{player.PLAYED_GAMES}</span></td>
	</tr>
	<!-- END player -->
	<tr>
		<td class="cat" colspan="4" align="center">{S_OPTIONS}</td>
	</tr>
</form>
</table>

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
<form method="post" action="{S_MODE_ACTION}">
	<tr> 
		<td align="left"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a>{U_CAT}</span></td>
	</tr>
</form>
</table>

<br />
<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
