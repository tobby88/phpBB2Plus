<!-- phpBB Arcade Admin Config Template v2.1.7 -->

<form action="{S_CONFIG_ACTION}" method="post">

 <table width="99%" cellpadding="4" cellspacing="0" border="0" align="center" class="bodyline">
  <tr> 
   <th class="thHead" colspan="2">{L_CONFIG_MENU} {VERSION}</th>
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
   <td class="row1">{L_USE_ADAR_SHOP}<span class="gensmall">{L_USE_ADAR_INFO}</span></td>
   <td class="row1" align="center">
     <input type="radio" name="use_gk_shop" value="1" {S_USE_GKS_YES} /> {L_YES}&nbsp;&nbsp;
     <input type="radio" name="use_gk_shop" value="0" {S_USE_GKS_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_USE_GAMELIB}<span class="gensmall">{L_USE_GL_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="use_gamelib" value="1" {S_USE_GL_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="use_gamelib" value="0" {S_USE_GL_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_USE_REWARDS}<span class="gensmall">{L_USE_REWARDS_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="use_rewards_mod" value="1" {S_USE_REWARDS_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="use_rewards_mod" value="0" {S_USE_REWARDS_NO} /> {L_NO}
   </td>
  </tr>
<!-- BEGIN rewards_menu_on -->
  <tr>
   <th class="thHead" colspan="2" align="center">{L_REWARDS}</td>
  </tr>
  <tr>
   <td class="row1">{L_USE_POINTS}<span class="gensmall">{L_USE_POINTS_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="use_point_system" value="1" {S_USE_PSM_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="use_point_system" value="0" {S_USE_PSM_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row2">{L_USE_ALLOWANCE}<span class="gensmall">{L_USE_ALLOWANCE_INFO}</span></td>
   <td class="row2" align="center"> 
     <input type="radio" name="use_allowance_system" value="1" {S_USE_ASM_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="use_allowance_system" value="0" {S_USE_ASM_NO} /> {L_NO}
   </td>
  </tr>
  <tr>
   <td class="row1">{L_USE_CASH}<span class="gensmall">{L_USE_CASH_INFO}</span></td>
   <td class="row1" align="center"> 
     <input type="radio" name="use_cash_system" value="1" {S_USE_CASH_YES} /> {L_YES}&nbsp;&nbsp; 
     <input type="radio" name="use_cash_system" value="0" {S_USE_CASH_NO} /> {L_NO}
   </td>
  </tr>
<!-- END rewards_menu_on -->
<!-- BEGIN cash_default_menu -->
  <tr>
   <td class="row1"><span class="gensmall">{L_CASH_DEFAULT_INFO}</span></td>
   <td class="row1" align="right">{L_CASH} {DASH} <input class="post" type="text" size="15" name="default_cash" value="{DEFAULT_CASH}" /></td>
  </tr>
<!-- END cash_default_menu -->
  <tr>
   <th class="thHead" colspan="2" align="center">{L_ARCADE_CONFIG}</td>
  </tr>
<!-- BEGIN display_gamelib_menu -->
  <tr>
   <td class="row1">{L_GL_GAME_PATH}<span class="gensmall">{L_GL_PATH_INFO}</span></td>
   <td class="row1" align="right">{L_PATH} {DASH} <input class="post" type="text" size="15" name="games_path" value="{S_GL_GAMES_PATH}" /></td>
  </tr>
  <tr>
   <td class="row2">{L_GL_LIB_PATH}<span class="gensmall">{L_GL_LIB_INFO}</span></td>
   <td class="row2" align="right">{L_PATH} {DASH} <input class="post" type="text" size="15" name="gamelib_path" value="{S_GAMELIB_PATH}" /></td>
  </tr>
<!-- END display_gamelib_menu -->
  <tr>
   <td class="row1">{L_GAMES_PER_PAGE}<span class="gensmall">{L_GAMES_PER_INFO}</span></td>
   <td class="row1" align="right">{L_PAGE} {DASH} <input class="post" type="text" size="5" name="games_per_page" value="{S_GAMES_PER_PAGE}" /></td>
  </tr>
  <tr>
   <td class="row2">{L_GAMES_PER_PAGE}<span class="gensmall">{L_GAMES_PER_ADMIN_INFO}</span></td>
   <td class="row2" align="right">{L_PAGE} {DASH} <input class="post" type="text" size="5" name="games_per_admin_page" value="{S_GAMES_PER_ADMIN_PAGE}" /></td>
  </tr>

  <tr>
   <td class="row1">{L_GAMES_PATH}<span class="gensmall">{L_GAMES_PATH_INFO}</span></td>
   <td class="row1" align="right" nowrap>{L_PATH} {DASH} <input class="post" type="text" size="25" name="games_path" value="{S_GAMES_PATH}" /></td>
  </tr>

   <tr>
   <td class="row2">{L_DEFAULT_IMG}<span class="gensmall">{L_DEFAULT_IMG_INFO}</span></td>
   <td class="row2" align="right"><input class="post" type="text" size="30" name="games_default_img" value="{S_DEFAULT_IMG}" /></td>
  </tr>
  <tr>
   <td class="row1">{L_DEFAULT_TXT}<span class="gensmall">{L_DEFAULT_TXT_INFO}</span></td>
   <td class="row1" align="right"><input class="post" type="text" size="30" name="games_default_txt" value="{S_GUEST_TXT}" /></td>
  </tr>
  <tr>
   <td class="row2">{L_GAMES_ZERO_TXT}<span class="gensmall">{L_GAMES_ZERO_INFO}</span></td>
   <td class="row2" align="right"><input class="post" type="text" size="30" name="games_cat_zero" value="{S_GAMES_ZERO_TXT}" /></td>
  </tr>
 <tr>
   <td class="row1">{L_RANK_TXT}<span class="gensmall">{L_RANK_TXT_INFO}</span></td>
   <td class="row1" width="20%" align="right">{S_RANK}</td>
  </tr>
 <tr>
   <td class="row2">{L_GROUP_TXT}<span class="gensmall">{L_GROUP_TXT_INFO}</span></td>
   <td class="row2" width="20%" align="right">{S_GROUP}</td>
  </tr>
 <tr>
   <td class="row1">{L_MIN_POSTS}<span class="gensmall">{L_MIN_POSTS_INFO}</span></td>
   <td class="row1" align="right">{L_POSTS} {DASH} <input class="post" type="text" size="5" name="games_posts_required" value="{S_POSTS_REQUIRED}" /></td>
  </tr>
 <tr>
   <td class="row2">{L_DEFAULT_GAME_ID}<span class="gensmall">{L_DEFAULT_GAME_ID_INFO}</span></td>
   <td class="row2" align="right">{L_GAME_ID} {DASH} <input class="post" type="text" size="5" name="games_default_id" value="{S_DEFAULT_GAME_ID}" /></td>
  </tr>
 <tr>
   <td class="row1">{L_DEFAULT_SORT}<span class="gensmall">{L_DEFAULT_SORT_INFO}</span></td>
   <td class="row1" align="right">{S_DEFAULT_SORT}</td>
  </tr>
 <tr>
   <td class="row2">{L_DEFAULT_SORT_TYPE}<span class="gensmall">{L_DEFAULT_SORT_TYPE_INFO}</span></td>
   <td class="row2" align="right">{S_DEFAULT_SORT_TYPE}</td>
  </tr>
 <tr>
   <td class="row1">{L_TOP_X_TXT}<span class="gensmall">{L_TOP_X_INFO}</span></td>
   <td class="row1" align="right">{L_TOP_X}   <input class="post" type="text" size="5" name="games_total_top" value="{S_TOP_X}" /></td>
  </tr>
 <tr>
   <td class="row2">{L_NEW_FOR}<span class="gensmall">{L_NEW_FOR_INFO}</span></td>
   <td class="row2" align="right">{S_NEW_FOR}</td>
  </tr>
 <tr>
   <td class="row1">{L_RATE}<span class="gensmall">{L_RATE_INFO}</span></td>
   <td class="row1" align="right"><input class="post" type="text" size="2" name="games_default_rate" value="{S_RATE}" /></td>
  </tr>
 <tr>
   <td class="row2">{L_IMAGE_SIZE}<span class="gensmall">{L_IMAGE_SIZE_INFO}</span></td>
   <td class="row2" align="right" nowrap> 
      {L_WIDTH} {DASH} <input class="post" type="text" size="5" name="games_image_width" value="{S_WIDTH}" /> &nbsp;&nbsp;
     {L_HEIGHT} {DASH} <input class="post" type="text" size="5" name="games_image_height" value="{S_HEIGHT}" /></td>
  </tr>
 <tr>
   <td class="row1">{L_CAT_IMAGE_SIZE}<span class="gensmall">{L_CAT_IMAGE_SIZE_INFO}</span></td>
   <td class="row1" align="right" nowrap> 
      {L_WIDTH} {DASH} <input class="post" type="text" size="5" name="games_cat_image_width" value="{S_CAT_WIDTH}" /> &nbsp;&nbsp;
     {L_HEIGHT} {DASH} <input class="post" type="text" size="5" name="games_cat_image_height" value="{S_CAT_HEIGHT}" /></td>
  </tr>
<!-- BEGIN display_shop_menu -->
  <tr>
   <th class="thHead" colspan="2" align="center">{L_ADAR_SHOP_CONFIG}</td>
  </tr>
  <tr>
   <td class="row1" colspan="2">{L_ADAR_SHOP}<span class="gensmall">{L_ADAR_INFO}</span></td>
  </tr>
<!-- END display_shop_menu -->
  <tr>
   <td class="cat" colspan="2" align="center">{S_HIDDEN_postS} 
    <input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;
    <input type="reset" value="{L_RESET}" class="mainoption" />
   </td>
  </tr>
</table>
</form>
<br />
<div align="center"><span class="gensmall">Arcade Mod {VERSION} - by <a href="http://www.phpbb-arcade.com/">dEfEndEr</a></span></div>
