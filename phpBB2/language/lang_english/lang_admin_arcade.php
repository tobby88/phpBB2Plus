<?php
/***************************************************************************
 *                         lang_admin_arcade.php [english]
 *                         ------------------------------
 *   begin                : Monday 27th Jan, 2007
 *   copyright            : (c) 2003-2007 dEfEndEr - www.phpbb-arcade.com       
 *   email                : support@phpbb-arcade.com
 *
 *   $Id: lang_arcade.php, v2.1.7 2007/01/01 23:59:59 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 *   This language file is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}

// Admin
$lang['admin_main_header'] = 'This control panel will help you maintain and control the online activities.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com"  target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.';
$lang['admin_config_menu'] = 'Online Arcade Configuration Menu';
$lang['admin_game_menu'] = 'Online Arcade/Activity Menu';
$lang['admin_game_editor'] = 'Online Arcade/Activity Editor Menu';
$lang['admin_game_import'] = 'Online Arcade/Activity Import Menu';
$lang['admin_editor_info'] = '-:- This control panel allows you to Add/Edit your Arcade/Activity data. Any game that has been released by <a href="http://www.phpbb-arcade.com" target="new_window">dEfEndEr</a>, Buddystuart, Whoo, Mullac or Alegis can easily be plugged into your forums and Highscores will be saved. Also you can install any of the 1000\'s of free to download games, music, movies and images to add some great content to your site. If you would like to convert a game to use this control panel, then ask for help at our <a href="http://www.phpbb-arcade.com/" target="new_window">forums</a>. If you are having trouble with this menu or the Arcade MOD then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank>www.phpbb-arcade.com</a>.';
$lang['admin_import_info'] = '-:- This control panel allows you to import data directly in to your database (to save you time). If you are having trouble with this menu or the Arcade MOD then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank >www.phpbb-arcade.com</a>.';
$lang['admin_game_deleted'] = 'Game Deleted<br /><br />';
$lang['admin_game_not_deleted'] = 'Item NOT Deleted<br /><br />';
$lang['admin_game_repaired'] = 'Database Repaired<br /><br />';
$lang['admin_game_saved'] = 'Item Saved Successfully<br /><br />';
$lang['admin_score_reset'] = 'All scores have been reset<br /><br />';
$lang['admin_return_arcade'] = 'Click %sHere%s to return to the Arcade Menu';
$lang['admin_return_import'] = 'Click %sHere%s to return to the Import Menu';
$lang['admin_config_updated'] = 'Online Activities Configuration Updated<br /><br />';
$lang['admin_toggles'] = 'Support Toggles';
$lang['admin_rewards'] = 'Reward system Toggles';
$lang['admin_arcade_config'] = 'Arcade Configuration';
$lang['admin_use_adar_shop'] = '<b>Use the <a href="http://www.phpbb.com" target="newindow">Adar Item Shop</a></b><br />';
$lang['admin_use_adar_info'] = '-:- If you want to reward Items for Highscore bonuses and you have the<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Adar ItemShop, then toggle this to <b>Yes</b>.<br /><br />-:- <b>NOTE : </b>This will not work with other shops!';
$lang['admin_use_gamelib'] = '<b>Use the Gamelib Javascript Library</a></b><br />';
$lang['admin_use_gl_info'] = '-:- If you are using a <i>JAVA</i> game/application that uses Scott Porter\'s gamelib,<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;then toggle this on.';
$lang['admin_use_points'] = '<b>Use the <a href="http://www.phpbb-arcade.com" target="newindow">Points System</a></b><br />';
$lang['admin_use_pts_info'] = '-:- If you are using the Points System then toggle this on so that these MODs<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;can use this reward system for your forums.';
$lang['admin_use_cash'] = '<b>Use the <a href="http://www.phpbb.com/community/" target="newindow">Cash System</a></b><br />';
$lang['admin_use_cash_info'] = '-:- If you are using the Cash System then toggle this on so that these MODs<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;can use this reward system for your forums.';
$lang['admin_use_allowance'] = '<b>Use the <a href="http://www.phpbb-arcade.com" target="newindow">Allowance System</a></b><br />';
$lang['admin_use_allowance_info'] = '-:- If you are using the Allowance System then toggle this on so that these MODs<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;can use this reward system for your forums.';
$lang['admin_games_path'] = '<b>Default Games Path</b><br />';
$lang['admin_games_path_info'] = '-:- A directory located in your forums root directory where you wish to hold all of your games.';
$lang['admin_gl_game_path'] = '<b>Gamelib Games Path</b><br />';
$lang['admin_gl_path_info'] = '-:- This is the directory located in your forums root directory where you wish<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;to hold all of your games that use the gamelib.';
$lang['admin_gl_lib_path'] = '<b>Gamelib Javascript Library Path</b><br />';
$lang['admin_gl_lib_info'] = '-:- This Directory is located in your <b>Gamelib Games Path</b> and holds all<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;of the gamelib*.js files.<br /><br />-:- <b>NOTE</b> : If your games are locking up or you don\'t hear any<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;sound then check this directory to make sure there are gamelib files<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;in here.';
$lang['admin_games_per_page'] = '<b>Games Per Page</b><br />';
$lang['admin_games_per_info'] = '-:- This is how many games you want listed before a new page is needed. (0 = all)';
$lang['admin_page'] = 'Pages';
$lang['admin_game_id'] = 'Game ID#';
$lang['admin_path'] = 'Path';
$lang['admin_adar_config'] = 'Adarian Shop Options';
$lang['admin_adar_shop'] = '<b>Adar Shop</b><br />';
$lang['admin_no_adar_info'] = '-:- The Adarian Shop options are not installed. Please install Adar before<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;setting these options.';
$lang['admin_games'] = 'Games';
$lang['admin_charge'] = 'Charge';
$lang['admin_button'] = 'Button';
$lang['admin_description'] = 'Description';
$lang['admin_reward'] = 'Reward';
$lang['admin_bonus'] = 'Bonus';
$lang['admin_at_bonus'] = 'AT Bonus';
$lang['admin_flash'] = 'Flash';
$lang['admin_score'] = 'Score';
$lang['admin_gamelib'] = 'Gamelib';
$lang['admin_action'] = 'Action';
$lang['admin_move'] = 'Move';
$lang['admin_repair'] = 'Repair Game Index';
$lang['admin_reset'] = 'Reset High Scores';
$lang['admin_at_reset'] = 'Reset ALL TIME Scores';
$lang['admin_up'] = 'Up';
$lang['admin_down'] = 'Dn';
$lang['admin_down_full'] = 'Down';
$lang['admin_delete'] = 'X';
$lang['admin_delete_full'] = 'Delete';
$lang['admin_limit'] = 'Limit';
$lang['admin_at_limit'] = 'AT Limit';
$lang['admin_width'] = 'Width';
$lang['admin_height'] = 'Height';
$lang['admin_cash'] = 'Cash';
$lang['admin_name'] = '<b>Filename / phpBB Amod Game Name</b><br />';
$lang['admin_name_info'] = '-:- This is the FILENAME of the game you are installing (case sensitive). <br /><br />-:- <b>NOTE</b> : Keep the name of this game exactly as you find it...!<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;phpBB Amod/Activity Mod/Arcade Mod games have the name HARD CODED in to them<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;so do not use the extension .swf (turn ON FLASH support below. Anything else will cause errors.';
$lang['admin_game_path'] = '<b>Path to the file above</b><br />';
$lang['admin_game_path_info'] = '-:- This is the PATH to the location of the file within your phpBB root directory.';
$lang['admin_game_desc'] = '<b>Description</b><br />';
$lang['admin_game_desc_info'] = '-:- This is the description of the activity that is displayed to your users.';
$lang['admin_game_charge'] = '<b>Game Charges</b><br />';
$lang['admin_game_charge_info'] = '-:- This is how much to charge users to play this game.';
$lang['admin_game_per'] = '<b>Score per point reward</b><br />';
$lang['admin_game_per_info'] = '-:- This is the score the user has to get to receive 1 Reward (point etc)  <br /><br />-:- <b>Example</b> : If you put 100 as the value for this option,<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;and a player scores 100 in this game. then the player will get 1 Reward/Point.';
$lang['admin_game_bonus'] = '<b>Highscore Bonus</b><br />';
$lang['admin_game_bonus_info'] = '-:- This is the amount of reward points to give a user if they obtain the highscore.';
$lang['admin_game_gamelib'] = '<b>Use the Gamelib</b><br />';
//
//  Words changed in 2.0.8
//
$lang['admin_game_gamelib_info'] = '-:- Set this to yes if this JAVA game uses the <b>GameLib</b>.';
$lang['admin_game_flash'] = '<b>Macromedia Flash Game Type</b><br />';
$lang['admin_game_flash_info'] = '-:- for Activity Mod/pnFlashGames/Arcade Mod & IBPro the filename (top) does not require the .swf ext and this should be set to YES.';
//
$lang['admin_game_show_score'] = '<b>Show the scores</b><br />';
$lang['admin_game_show_info'] = '-:- Set this to yes if you wish to use scores for this activity.';
$lang['admin_game_reverse'] = '<b>Reverse Highscore Listing</b><br />';
$lang['admin_game_reverse_info'] = '-:- Set this to yes if you wish to list the lowest scores first.';
$lang['admin_game_highscore'] = '<b>Highscore Limit</b><br />';
$lang['admin_game_highscore_info'] = '-:- This is how many scores you want listed for this game. Leave blank for all.';
$lang['admin_game_size'] = '<b>Window Size</b><br />';
$lang['admin_game_size_info'] = '-:- This is how big in pixels, the window will be when a game is started.';
$lang['instructions_info'] = 'Enter this games "How to play" instructions below. HTML Tags will also work in here.<br />';
$lang['admin_game_reset_hs'] = '<b>Reset Highscores</b><br />';
$lang['admin_game_reset_hs_info'] = '-:- By setting this to <b>"Yes"</b> the Highscores will reset.';
$lang['admin_game_reset_at_hs'] = '<b>Reset ALL-TIME Scores</b><br />';
$lang['admin_game_reset_at_hs_info'] = '-:- By setting this to <b>"Yes"</b> the ALL TIME Highscores will reset.';
$lang['admin_use_rewards'] = '<b>Use Rewards MOD</b><br />';
$lang['admin_use_rewards_info'] = '-:- If you have a rewards MOD installed [Points/Cash/Allowance]<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;then turn this on to configure your rewards MOD.';
$lang['admin_cheat'] = '<b>Use Cheat Mode</b><br />';
$lang['admin_cheat_info'] = '-:- If turned on, Proxy <b>guests</b> & users logged out during a session, will NOT be able to save high scores.';
$lang['admin_warn_cheater'] = '<b>Display warning to possible cheater</b><br />';
$lang['admin_warn_cheater_info'] = '-:- If turned on, then this will display a message to anyone who might get caught cheating.';
$lang['admin_cheater_warning'] = '<br />You have been reported to the site <b>Admin</b> as a possible cheater.<br /><br /> If you feel that you have not cheated in an online game, then please contact the site Admin.<br />';
$lang['admin_warn_admin'] = '<b>Report cheaters</b><br />';
$lang['admin_warn_admin_info'] = '-:- If turned on the site Admin will get an E-mail notification if someone is caught cheating.';
$lang['admin_cash_default_info'] = '-:- The Arcade MOD uses only 1 reward field. Please enter in a default user<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;reward field for your users. [<b>Cash MOD Only</b>]';
$lang['admin_games_offline'] = '<b>Take Mod Offline</b><br />';
$lang['admin_games_offline_info'] = '-:- This will show any uses who try and access the games an offline message';
$lang['admin_default_game_id'] = '<b>Default Game ID Number</b><br />';
$lang['admin_default_game_id_info'] = '-:- When Adding a game, use this game as a default. "0" = All fields empty.';
$lang['admin_cat'] = '<b>Game Category</b><br />';
$lang['admin_cat_info'] = '-:- The category that the game will be viewed under.';
$lang['admin_default_img'] = '<b>Default Image</b><br />';
$lang['admin_default_img_info'] = '-:- Default games image, this image will be displayed if you do not have a image available.';
//
// 2.0.4
//
$lang['admin_moderators_header'] = 'Online Arcade Moderators Options Menu';
$lang['admin_moderators_info'] = 'This control panel will allow you to set-up the moderators options for the categories sections.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.';
$lang['admin_default_txt'] = '<b>Default Guest Text</b><br />';
$lang['admin_default_txt_info'] = '-:- Default text that is displayed to Guests to try to get them to register.';
$lang['admin_tournament_txt'] = '<b>Use Tournament Mode</b><br />';
$lang['admin_tournament_txt_info'] = '-:- Turn on Tournament Option.';
$lang['admin_played'] = 'Hits';
$lang['admin_available'] = 'Offline';
$lang['admin_guest'] = 'Guests';
$lang['admin_image_path'] = '<b>Path to Game Image</b><br />';
$lang['admin_image_path_info'] = '-:- This path can be anywhere.<br />     (if left blank default will be be used {filename.gif}, or enter just the ext {.gif})';
//
// Changed in 2.0.8 from admin_game_level to admin_game_guest and Updated
//
$lang['admin_game_guest'] = '<b>Guest Access</b><br />';
$lang['admin_game_guest_info'] = '-:- Allow Guests to play (overrides the next 3 options)';
$lang['admin_game_offline'] = '<b>Game is Available</b><br />';
$lang['admin_game_offline_info'] = '-:- Is the game available to the users? - allows you to fix the game';
$lang['admin_game_import_ok'] = 'Imported "%s" Games Successfully<br /><br />Skipped "%s" Game Records.<br /><br />';
$lang['admin_game_moderator_info'] = 'The name of the user that is to moderate this area.';
//
// 2.0.6
//
$lang['admin_game_exists'] = 'Name Already Exists - Not Saved<br /><br />';
$lang['admin_cat_menu'] = 'Online Arcade Categories Menu';
$lang['admin_cat_header'] = 'This control panel will help you maintain the categories for your online activities.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.';
$lang['admin_cat_saved'] = 'Category Saved Successfully<br /><br />';
$lang['admin_cat_deleted'] = 'Category Deleted<br /><br />';
$lang['admin_cat_not_deleted'] = 'Category NOT Deleted<br /><br />';
$lang['admin_cat_icon'] = '<b>ICON</b><br />';
$lang['admin_cat_icon_info'] = 'Path to the image file for this category. If blank No image will be displayed.';
$lang['admin_cat_name'] = '<b>Category Name</b><br />';
$lang['admin_cat_name_info'] = 'The Name of the Category. This is displayed after the image as a category header.';
$lang['admin_game_special'] = '<b>Special Play</b><br />';
$lang['admin_game_special_info'] = 'The Number of games required before special play is enabled. 0=OFF.';
$lang['admin_games_per_admin_info'] = '-:- This is how many games you want listed before a new page is needed in the ACP. (0 = all)';
$lang['admin_games_image_txt'] = '<b>Games Icon Image Size</b><br />';
$lang['admin_games_image_txt_info'] = '-:- Set this to the size you want your games images to be.';
$lang['admin_auto_size_txt'] = '<b>Use Auto Game Size</b><br />';
$lang['admin_auto_size_txt_info'] = '-:- This will overide the config for Flash and Image files, so they load to the correct size.<br />-:- This does NOT effect the AUTO configuration of the FLASH and IMAGE files when adding. ';
$lang['admin_guest_high_txt'] = '<b>Allow Guests to Post a High Score</b><br />';
$lang['admin_guest_high_txt_info'] = '-:- Guests can NEVER post to the AT highscore. This will turn off normal High Scores too.';
$lang['admin_at_highscore_txt'] = '<b>Use the AT HighScore System</b><br />';
$lang['admin_at_highscore_txt_info'] = '-:- Use the All Time HighScore system to hold scores.';
$lang['admin_show_stats_txt'] = '<b>Show the Stats</b><br />';
$lang['admin_show_stats_txt_info'] = '-:- Turn this off to remove the Stats from the pages';
$lang['admin_return_games'] = 'Click %sHere%s to return to the Games Menu';
//
// 2.0.7
//
$lang['admin_messages_header'] = 'Online Arcade Private Message Options Menu';
$lang['admin_messages_info'] = 'This control panel will help you maintain what private messages are sent via the Arcade mod.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.';
$lang['admin_moderators_txt'] = '<b>Use Moderators Menu</b><br />';
$lang['admin_moderators_txt_info'] = '-:- Turn on the MCP (Moderators Control Panel).';
$lang['admin_min_posts_txt'] = '<b>Minimum Number of Posts Required for Access to the Mod</b><br />';
$lang['admin_min_posts_txt_info'] = '-:- Users below this number of posts will only be shown the guests games available,<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;and will be told to post for better access.';
$lang['admin_use_pms_txt'] = '<b>Use the Private Message System</b>';
$lang['admin_use_pms_txt_info'] = '<br />-:- If this is set the MOD will send PM\'s to the members when required.';
$lang['admin_min_rank_txt'] = '<b>Minimum Rank for Access</b>';
$lang['admin_min_rank_txt_info'] = '<br />-:- If this is set the MOD will only allow guest access to those below this rank.';
$lang['admin_game_wrong_name'] = 'You can not have spaces in game names or path names<br /><br />';
$lang['admin_game_score_type'] = '<b>Score Save Type</b><br />';
$lang['admin_game_score_type_info'] = '-:- What method of saving does this game have? (autoset by admin/mod playing)';
$lang['Auto'] = 'Automatic';
$lang['Extras'] = 'Extras';
$lang['admin_get_method'] = 'Napoleon\'s Method';
$lang['admin_post_method'] = 'Whoo\'s Method';
$lang['admin_new_method'] = 'dEfEndEr\'s Method';
$lang['admin_game_autosize'] = '<b>Turn OFF the AutoSize Setting</b><br />';
$lang['admin_game_autosize_info'] = '-:- This option will override the ACP setting if the Autosize option is ON.';
$lang['admin_cat_image_txt'] = '<b>Categories Icon Image Size</b><br />';
$lang['admin_cat_image_txt_info'] = '-:- Set this to the size you want your Categories images to be.';
$lang['admin_rank_required'] = '<b>Rank Required</b><br />-:- Set this if you want to limit this game to one rank of users.';
$lang['admin_level_required'] = '<b>Access Level Required</b><br />-:- Set this if you want to limit this game to one level of users.';
$lang['admin_import_path'] = '<br />Enter the <b>path and name</b> of the file that holds the game data:<br /><br />';
$lang['admin_import_dir'] = '<br />Enter the <b>directory</b> that holds the games.<br /><br />';
$lang['admin_import_amod'] = 'Import which type of file ?';
$lang['admin_import_online'] = 'Import All the Games as:';
$lang['admin_auto_size'] = 'Auto Size will only work on FLASH files and IMAGES';
$lang['admin_show_played_txt'] = '<b>Show the Games Played</b><br />';
$lang['admin_show_played_txt_info'] = '-:- Turn this off to remove the \'Games Played\' from the Games Menu';
$lang['admin_show_all_txt'] = '<b>Show All The Games</b><br />';
$lang['admin_show_all_txt_info'] = '-:- Turn this off to remove the \'Show All The Games\' option on the Categories Menu';
$lang['admin_show_new_txt'] = '<b>Show New Games</b><br />';
$lang['admin_show_new_txt_info'] = '-:- Turn this off to remove the \'New Games\' list and replace it with \'Special Play\' (Least Played Games)';
$lang['admin_games_zero_txt'] = '<b>Category Zero Text</b><br />';
$lang['admin_games_zero_txt_info'] = '-:- The test that is shown in the categories options for \'All Games\' (if used)';
$lang['admin_num_top_games_txt'] = '<b>Number of Top Games</b><br />';
$lang['admin_num_top_games_txt_info'] = '-:- How many games will the Top X Games list\'s show?';
//
// 2.0.8
//
$lang['admin_return_cats'] = 'Click %sHere%s to return to the Categories Menu';
$lang['admin_no_guests'] = '<b>Block All Guest Access</b><br />';
$lang['admin_no_guests_info'] = '-:- All players must me logged in members for access';
$lang['admin_ibPro_method'] = 'ibPro Method';
$lang['admin_pnflashgames_method'] = 'pnFlashGames Method';
$lang['admin_mixed_method'] = 'Mixed Method';
$lang['admin_return_games_new'] = 'Click %sHere%s to Edit Games in the Category';
$lang['admin_game_level'] = '<b>Level Required</b><br />';
$lang['admin_game_level_info'] = '-:- Only allow Admin / Mods to play';
$lang['admin_game_rank'] = '<b>Rank Required</b><br />';
$lang['admin_game_rank_info'] = '-:- Limit the game to user higher than rank.';
$lang['admin_game_group'] = '<b>Group Required</b><br />';
$lang['admin_game_group_info'] = '-:- Limit the game to this usergroup.';
$lang['admin_ban_users_txt'] = '<b>Use the Auto BAN System</b><br />';
$lang['admin_ban_users_txt_info'] = '-:- Turn this on to allow mods/admin to BAN users from the Arcade.';
$lang['admin_use_rate_txt'] = '<b>Rating System</b><br />';
$lang['admin_use_rate_txt_info'] = '-:- Turn this off to remove the option to RATE Games.';
$lang['admin_use_comment_txt'] = '<b>Comment System</b><br />';
$lang['admin_use_comment_txt_info'] = '-:- Turn this off to remove the option to Post/view Comments.';
$lang['admin_category'] = 'Category';
$lang['admin_file_not_found'] = '<br />file: <b><i>%s</b></i> NOT FOUND<br />';
$lang['admin_min_group_txt'] = '<b>Required Group for Access</b>';
$lang['admin_min_group_txt_info'] = '<br />-:- If this is set the MOD will only allow access to those in this group.';
//
//  2.1.2
//
$lang['admin_show_fav_txt'] = '<b>Show the Favourites</b><br />';
$lang['admin_show_fav_txt_info'] = '-:- Turn this off to remove the \'Favourites\' from the Games Menu';
$lang['admin_vbulletin_method'] = 'vBulletin Method';
//
//  2.1.3
//
$lang['admin_default_sort'] = '<b>Default Sort</b><br />';
$lang['admin_default_sort_info'] = '-:- The default sort order when a user first enters the listings.';
$lang['admin_default_sort_type'] = '<b>Default Sort Type</b><br />';
$lang['admin_default_sort_type_info'] = '-:- The default sort order (top to bottom / bottom to top).';
$lang['admin_new_for_txt'] = '<b>Show Flashing NEW</b><br />';
$lang['admin_new_for_txt_info'] = '-:- Show the flashing *NEW* (0=OFF) IN DAYS.';
$lang['admin_rate_txt'] = '<b>Range for Rating System</b>';
$lang['admin_rate_txt_info'] = '<br />-:- Min = 2, Max = 20';
$lang['admin_cache_menu'] = 'phpBB Arcade - Cache Settings -';
$lang['admin_cache_header'] = 'This control panel will help you maintain the cache system used by the Arcade Mod for your online activities.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.';
$lang['admin_use_cache'] = '<b>Use the phpBB Arcade CACHE system</b><br>';
$lang['admin_use_cache_info'] = '-:- This will turn ON the cache system, make sure your /cache/ directory is set 0666 (rw-rw-rw)';
$lang['admin_arcade_cache'] = 'Cache Duration (1 day = 1440 Minutes - USE 0 for OFF)';
$lang['admin_mins'] = 'Minutes';
$lang['admin_config_cache'] = '<b>Time between update\'s of</b> <i>/cache/arcade_config.php</i><br>';
$lang['admin_config_cache_info'] = '-:- This is updated automatically when updating the Arcade config in the ACP.';
$lang['admin_cat_cache'] = '<b>Time between update\'s of</b> <i>/cache/arcade_categories.php</i><br>';
$lang['admin_cat_cache_info'] = '-:- This is updated automatically when updating the Arcade categories in the ACP.';
$lang['admin_games_cache'] = '<b>Time between update\'s of</b> <i>/cache/arcade_games_x.php</i><br>';
$lang['admin_games_cache_info'] = '-:- This is updated automatically when updating the Arcade games in the ACP.';
$lang['admin_highscore_cache'] = '<b>Time between update\'s of</b> <i>/cache/arcade_best_player.php</i><br>';
$lang['admin_highscore_cache_info'] = '-:- Keep this low, else users may complain.';
$lang['admin_at_highscore_cache'] = '<b>Time between update\'s of</b> <i>/cache/arcade_best_at_player.php</i><br>';
$lang['admin_at_highscore_cache_info'] = '-:- This may be anything upto 10080, depending on your All Time Champion.';
$lang['admin_show_mhm'] = '<b>Use Monthly Highscore Mod</b><br>';
$lang['admin_show_mhm_info'] = '-:- Turn this off if you do not want to use this feature.';
$lang['admin_control'] = '<b>Game Control</b><br />';
$lang['admin_control_info'] = '-:- The controls that the game use\'s.';
$lang['admin_mouse'] = 'Mouse';
$lang['admin_keyboard'] = 'Keyboard';
$lang['admin_mouse_keyboard'] = 'Mouse & Keyboard';
$lang['admin_both'] = 'Both';
$lang['admin_edit_games'] = 'Edit Games';
$lang['Submit'] = 'Submit';
$lang['Add'] = 'Add';
$lang['admin_stats'] = 'Stats';
$lang['admin_score_top_score'] = ' score(s) - Top Score : ';
$lang['admin_at_score_top_score'] = ' AT score(s) - Top Score : ';
$lang['admin_comment'] = ' comment';
$lang['admin_comments'] = ' comments';
$lang['admin_rated'] = '<br>Rated : ';
$lang['admin_date_added'] = 'Date Added';
$lang['admin_alphabetically'] = 'Alphabetically';
$lang['admin_game_played'] = 'Played';
$lang['admin_allow_guests'] = 'Guest';
$lang['admin_score_header'] = 'Arcade Scores Editor';
$lang['admin_score_info'] = 'This control panel will help you maintain the scores set by your users for your online activities.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.<br />';
$lang['admin_score_editor'] = 'Score Editor';
$lang['admin_score_options'] = '<br><br /><form method="POST" action="%s"><input type="Submit" name="confirm" value="Yes">&nbsp;&nbsp;&nbsp;<input type="Submit" name="cancel" value="No"></form>';
$lang['admin_points'] = 'Points';
$lang['arcade_delete_at_sure'] = 'Are you sure you want to delete the Historical Scores Data';
$lang['arcade_delete_scores_sure'] = 'Are you sure you want to delete the current score data?';
//
//  v2.1.4
//
$lang['admin_ibprov3_method'] = 'IBPro v3 Method';
$lang['game_skipped'] = 'I\'ve Skipped %s as I found game #%d with the same name.<br />';
//
//  v2.1.6
//
$lang['admin_cat_type'] = '<b>Category Type</b><br />';
$lang['admin_cat_type_info'] = '-:- Main (shown on Cat page), Sub (shown on Games page under Parent) or Link (use desc as link too)';
$lang['admin_cat_parent'] = '<b>Parent Category</b><br />';
$lang['admin_cat_parent_info'] = '-:- If SUB CATEGORY, then use this as the Parent';
$lang['admin_cat_group'] = '<b>Category Group</b><br />';
$lang['admin_cat_group_info'] = '-:- Restrict Category to this Group';
$lang['admin_cat_main'] = 'Main';
$lang['admin_cat_sub'] = 'Sub';
$lang['admin_cat_link'] = 'Link';
$lang['admin_cat_no_parent'] = '<br>You MUST set the Parent when setting up a Sub-Category<br><br />';
$lang['admin_cat_wrong_parent'] = '<br>Your Parent can not be a Sub-Category<br><br />';
$lang['admin_import_cat'] = 'Category to Import to.';
$lang['admin_move_to'] = 'Move to:';
$lang['admin_move'] = 'Move';
$lang['admin_move_failed'] = 'You MUST select a valid MOVE TO Position';
//
//  v2.1.7
//
$lang['admin_cats_resynced'] = 'Category Database Re-Synced<br /><br />';
$lang['admin_resync'] = 'Re-Sync Categories';
//
//  v2.1.8
//
$lang['admin_use_log'] = '<b>Use Arcade Log System</b><br />';
$lang['admin_use_log_info'] = '-:- Will LOG all errors. newscore.php messages are ALWAYS logged.';
$lang['no_arcade_log'] = 'Unable to open Arcade Error Log';
$lang['admin_arcade_log'] = 'Arcade Error Log Viewer/Editor';
$lang['admin_arcade_log_info'] = 'This control panel will allow you to view and delete entries from the Arcade Error Log.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.';
$lang['arcade_log_purge'] = 'Purge Log';
$lang['record_number'] = "Record No'";
$lang['arcade_log_records'] = 'There are %s records in the log.';

/******************************************************************************
// Admin SET Feature
******************************************************************************/
$lang['admin_set'] = 'This will set the Arcade Charge, Highscore Bonus and All Time Highscore Bonus, etc For ALL Activity\'s<br>Before Seting the arcade Charge always backup your database first.';
$lang['admin_set_header'] = 'Arcade Mod BULK Setting Creator';
$lang['admin_set_info'] = 'This control panel will help you set ALL the GAMES values to that entered below.<br><br><center><b>WARNING: Once this feature is used, theres NO GOING BACK..!</b></center><br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.<br />';
$lang['admin_set_warning'] = '<b>WARNING: Once this feature is used, theres NO GOING BACK..!</b>';
$lang['admin_set_arcade'] = 'Set Arcade Defaults';
$lang['admin_set_charge'] = 'Set Game Charge';
$lang['admin_set_highscore'] = 'Set Highscore Bonus';
$lang['admin_set_at_highscore'] = 'Set All Time Highscore Bonus';

/******************************************************************************
// PM Message System
******************************************************************************/
$lang['amod_mess_new'] = 'Send PM to all users when a new game is added.';
$lang['amod_mess_highscore'] = 'Send PM when Highscore is lost.';
$lang['amod_mess_at_highscore'] = 'Send PM when All Time Highscore is lost.';
$lang['amod_mess_comment'] = 'Send PM when new comment is added to comment left.';

/******************************************************************************
// Tournament
******************************************************************************/
$lang['admin_tournament_header'] = 'Online Arcade Tournaments Menu';
$lang['admin_tournament_info'] = 'This control panel will help you maintain the tournamants for your online activities.<br />If you are currently having a problem with our MOD, then please contact us at <a href="http://www.phpbb-arcade.com" target=_blank class="copyright">www.phpbb-arcade.com</a> so we may help fix the problem.<br />';
$lang['tournaments'] = 'Tournaments';
$lang['add_tournament'] = 'Add/Edit Tournament';
$lang['tournament_settings'] = 'Tournament Settings';
$lang['tournament_options'] = 'Tournament Options';
$lang['tournament_max_number'] = '<b>Maximum number of Active Tournaments.</b>';
$lang['tournament_max_games'] = '<b>Maximum number of Games per Tournament.</b>';
$lang['tournament_max_players'] = '<b>Maximum number of Players per Tournament.</b>';
$lang['tournament_user_start'] = '<b>Allow users to Start Tournaments.</b>';
$lang['tournament_name'] = '<b>Tournament Name.</b>';
$lang['tournament_name_info'] = '<br />-:- The name that is displayed to all the users on the Categories menu';
$lang['tournament_desc'] = '<b>Tournament Description.</b>';
$lang['tournament_desc_info'] = '<br />-:- A Quick Discription of the Tournament - be as helpful as you can.';
$lang['tournament_max_player'] = '<b>Maxinum Players.</b>';
$lang['tournament_max_player_info'] = '<br />-:- Max Number of players for <b>this</b> Tournament.';
$lang['tournament_turns'] = '<b>Maxinum number of turns.</b>';
$lang['tournament_turns_info'] = '<br />-:- The number of times each game can be played.';
$lang['tournament_block'] = '<b>Block NORMAL players ?</b>';
$lang['tournament_block_info'] = '<br />-:- Stops NONE Tournament players from using the games in THIS Tournament.';
$lang['tournament_active'] = '<b>Tournament Active?</b>';
$lang['tournament_active_info'] = '<br />-:- Allows you to re-activate completed Tournaments.';
$lang['tournament_start'] = '<b>Tournament Start</b>';
$lang['tournament_start_info'] = '<br />-:- How is the Tournament to Start?';
$lang['tournament_end'] = '<b>Tournament End</b>';
$lang['tournament_end_info'] = '<br />-:- How is the Tournament to End?';
$lang['admin_tournament_updated']  = 'Tournament Updated<br /><br />';
$lang['admin_tournament_added']  = 'Tournament Added<br /><br />';
$lang['admin_tournament_deleted']  = 'Tournament Deleted<br /><br />';
$lang['admin_add_games'] = 'Add Games';
$lang['admin_add_players'] = 'Add Players';
$lang['admin_tournament_add_games'] = 'Arcade Tournament - Add Games Menu';
$lang['admin_tournament_select_games'] = 'Select which games you would like to add to the %s Tournament';
$lang['error_no_tour_info'] = 'No Name/Desc Entered';

$lang['tour_options'] = array('Waiting', 'Inactive', 'Active', 'Finished');
$lang['tour_start_options'] = array('Manually', 'Min Players Reached', 'Max Players Reached', 'Automatically');
$lang['tour_end_options'] = array('Manually', 'All Games Played', 'Automatically');

?>
