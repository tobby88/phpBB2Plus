<?php
/***************************************************************************
 *                         lang_arcade.php [english]
 *                         -------------------------
 *   begin                : Monday 1st Jan, 2007
 *   copyright            : (c) 2003-2007 dEfEndEr - www.phpbb-arcade.com       
 *   email                : support@phpbb-arcade.com
 *
 *   $Id: lang_arcade.php, v2.1.8 2007/01/01 21:37:59 dEfEndEr Exp $
 *
 *	Based on the Original Activity Mod by Napoleon.
 *
 ***************************************************************************
 *
 *   This language file is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************
 * 	CREDITS: 
 *  Napoleon - Original Activity Mod v2.0.0
 *  Painkiller
 *  Minesh - Add-On's
 *  Mark
 *	Others - Everybody else that helps with the project.
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}
//
// General
//
$lang['Edit_Games'] = 'Edit Games';
$lang['None'] = 'None';
$lang['All'] = 'All';
$lang['all_games'] = 'All Activities';
$lang['arcade_ruffle_loading'] = 'Loading Flash game with Ruffle&hellip;';
$lang['arcade_ruffle_error'] = 'The Flash game could not be started with Ruffle.';
//
// 2.0.6
//
$lang['date_added'] = 'Date Added';
$lang['alphabetically'] = 'Alphabetically';
$lang['games_not_enough_posts'] = 'Sorry but your access level will only allow guest access<br /><br />Please post in the forums for better access<br /><br />[%s]';
$lang['Played_Times'] = 'Played: %d times.';
//
// 2.0.7 
//
$lang['cat_info_stats'] = 'Info / Stats';
$lang['cat_total_games'] = 'Total';
$lang['cat_total_played'] = 'Played';
$lang['cat_last_played'] = 'Last Played';
$lang['games_test_noscore'] = 'This user can not submit scores to the database<br />';
$lang['games_highscore'] = '<br /><b>Highscore ';
$lang['games_athighscore'] = '<br /><b>All Time ';
$lang['highscore_you_have'] = ' you have:</b><br /><br />';
$lang['place'] = ' place';
$lang['places'] = ' places.';
$games_position_text = array('> 20th place','st place','nd place','rd place','th place');
//
// 2.1.2
//
$lang['Arcade'] = 'Arcade';
//
// Arcade 
//
$lang['not_enough_points'] = 'You do NOT have enough %s to play this game. <br /><br /><a href="javascript:parent.window.close();" alt="[Close Window]"><img src="images/close.gif"></a>';
$lang['not_enough_reward'] = 'You do NOT have enough to play this game. <br /><br /><a href="javascript:parent.window.close();">[Close Window]</a>';
$lang['game_instructions'] = 'Instructions';
$lang['game_no_instructions'] = 'No Instructions.';
$lang['game_free'] = 'Free';
$lang['game_cost'] = 'Cost';
$lang['game_dash'] = ':';
$lang['game_number'] = '#';
$lang['game_points'] = 'Points';
$lang['game_list'] = $board_config['sitename'] . ' Activities';
$lang['game_score'] = 'Score';
$lang['game_info'] = 'Info';
$lang['game_bonuses'] = 'Bonuses';
$lang['game_best_player'] = 'Best Player';
$lang['game_highscores'] = 'Highscores';
$lang['game_highscore'] = 'Highscore';
$lang['game_at_highscores'] = 'All Time';
$lang['game_new_high_score'] = '<b>*** Congratulations - New High Score ***</b><br /><br />';
$lang['game_new_at_high_score'] = '<b>* Congratulations - New All Time High Score *</b><br /><br />';
$lang['game_score_saved'] = 'High Score was Saved';
$lang['game_score_updated'] = 'Score was Updated';
$lang['game_score_text'] = '<b>%s</b> your %s<br /><br />';
$lang['game_highscore_off'] = '<br><br>Highscores are OFF';
$lang['game_no_score_saved'] = 'You don\'t have a score so your score was not saved<br />';
$lang['game_no_high_score'] = 'score did not beat your current best';
$lang['game_score_close'] = 'Close';
$lang['game_cheater'] = 'Busted!';
$lang['game_statistics'] = 'Game Statistics';
$lang['game_played'] = 'Played';
$lang['game_stat_price'] = 'Price to play';
$lang['game_stat_highscore'] = 'Highscore Bonus';
$lang['game_stat_at_highscore'] = 'AT Highscore Bonus';
$lang['game_score_reward'] = 'Score Reward';
$lang['game_all_time_score'] = 'All Time Score';
$lang['game_current_best'] = 'Current Best Player';
$lang['game_highest_score'] = 'Highest Score';
//
// 2.0.4
//
$lang['game_welcome'] = 'Welcome to %s ';
$lang['game_guest_welcome'] = 'Welcome to %s, Please ';
$lang['game_stats'] = 'Game Stats and Information';
$lang['game_tournament'] = 'Tournament';
$lang['Game_Select'] = 'Select Game';
$lang['Active_Tournaments'] = 'There are <b>%s</b> Active Tournaments:';
$lang['at_score_no_guest'] = 'Sorry, Guests can not post to the all time high score<br /><br />Registration is free, and you could hold the game trophy..<br />';
$lang['total_games'] = 'We have <b>%s</b> activities to do in this section.';
$lang['total_games_played'] = 'In total there has been <b>%s</b> games played in this section.';
$lang['games_are_offline'] = 'Sorry, but the Activities are currently unavailable. Please try again later.';
$lang['games_register'] = ' - for access to even more games - ITS FREE.<br />';
$lang['games_top_header'] = 'Top %d Activities';
$lang['games_bum_header'] = 'Special Play';
$lang['games_catagories'] = 'Games Categories';
$lang['games_section'] = 'Section';
$lang['games_total_points'] = 'You have <b>%s</b> %s.<br />';
$lang['games_top_players'] = 'The best players are:<br />';
$lang['game_your_score'] = 'Your <i><b>%s</b></i> Score of \'<b>%s</b>\' has been submitted.<br /><br />';
$lang['game_hidden'] = 'Hidden';
//
// 2.0.6
//
$lang['remove_fav_data'] = 'Could not remove data from favorites table';
$lang['insert_fav_data'] = 'Could not insert data into favorites table';
$lang['no_fav_topic'] = 'No topic to set as favorite was set';
$lang['play_favorites'] = 'Play your Favorites List';
$lang['add_fav'] = 'Add To Favorites';
$lang['del_fav'] = 'Deleted from Favorites List<br /><br />';
$lang['already_fav'] = 'Already in Favorites List<br /><br />';
//
// 2.0.7
//
$lang['games_important_info'] = "Important Information from the Arcade";
$lang['games_pm_info'] = "Your Highscore list is being viewed by %s but he/she does not know this message was sent. \n\nYou could be about to loose some highscore trophies to him/her..!\n\n\nYours {The phpBB Arcade Mod}\n\n(You can disable messages from the arcade in your user profile)";
$lang['games_new_header'] = '%d New Activities';
$lang['games_play_again'] = '<img src="images/play_again.gif" alt="[Play Again]" border="0" />';
$lang['games_add_fav'] = 'Add to Favourites';
$lang['games_last_viewed'] = 'The last item viewed was <b>%s</b>';
$lang['games_last_u_viewed'] = 'The last item you viewed was <b>%s</b>';
$lang['games_time_taken'] = 'Time';
$lang['games_unrecorded'] = 'Un-Recorded';
$lang['games_seconds'] = '%d Seconds';
$lang['Game'] = 'Game';
//
//  Updated for 2.0.8.2
//
$lang['games_pm_info_lost'] = "Your Highscore for '<b>[url=http://%sactivity.".$phpEx."?mode=game&amp;id=%d&win=self]%s[/url]</b>' has been lost to the player that this message is from.\n\n\nYours {The phpBB Arcade Mod}\n\n(You can disable messages from the arcade in your user profile)";
$lang['games_pm_info_lost_at'] = "Your All Time Highscore for '<b>[url=http://%sactivity.".$phpEx."?mode=game&amp;id=%d&win=self]%s[/url]</b>' has been lost to the player that this message is from.\n\n\nYours {The phpBB Arcade Mod}\n\n(You can disable messages from the arcade in your user profile)";
//
// 2.0.8
//
$lang['games_minutes'] = '%d min %d sec';
$lang['games_hours'] = '%d hr %d min %d sec';
$lang['games_days'] = '<b>%d days</b> %dh %dm %ds';
$lang['games_reward_givem'] = 'Reward given for every %s points scored';
$lang['newscore_return'] = '<br />[<a href="activity.'.$phpEx.'">Activities</a>]';
$lang['return_to_arcade'] = 'Click %sHere%s to return to the Arcade';
$lang['game_size'] = '<br /><i>Filesize: %ld kb.</i>';
$lang['ON'] = 'ON';
$lang['OFF'] = 'OFF';
$lang['No_Instructions'] = 'No Instructions';
$lang['games_image_default'] = '<span class="gensmall"><i>{default}</i></span>';
$lang['games_not_enough_posts'] = 'You need to post more in the forums for access';
//
// 2.1.2
//
$lang['games_last_score_gained'] = '<br /><b>%s</b> has just scored <b>%s</b> playing <b>%s</b>';
$lang['games_new_game_added_info'] = 'New Activity Added to the Arcade';
$lang['games_new_game_added'] = "A NEW Activity has been added called '%s' be the first to get a Highscore for it.\n\n\nYours {The phpBB Arcade Mod}\n\n(You can disable messages from the arcade in your user profile)";
//
//  2.1.3
//
$lang['allow_guests'] = 'Guest';
$lang['arcade_searched'] = 'Searched %s for %s';
$lang['Comments'] = 'Comments';
$lang['incorrect_category'] = 'Incorrect Category Selected. Please use the links provided.';
$lang['register_to_play'] = '***** <a href="profile.php?mode=register">Register</a> to Play *****';
$lang['games_updated_points'] = 'You got %s %s for that score<br />';
$lang['category'] = 'Category';
$lang['control'] = 'Control';
$lang['times'] = 'times.';
$lang['arcade_mouse'] = 'Mouse';
$lang['arcade_keyboard'] = 'Keyboard';
$lang['games_time_held'] = 'Time Held';
$lang['games_day'] = '<b>%d day</b> %dh %dm %ds';
$lang['games_weeks'] = '<i>%d weeks</i> <b>%d days</b>';
$lang['games_weeks_only'] = '<i>%d weeks</i>';
$lang['Block_Arcade_pm'] = 'Block PM\'s from the Arcade'; 
$lang['Information'] = 'Information';
$lang['Action'] = 'Action';
//
//  v2.1.8 - First 2 lines Updated from v2.0.7 area
//
$lang['games_best_player'] = '<b><u>Best Player</b></u><br /><img src="images/crown.gif" alt="[Best]" border="0" /> <b><i><a href="%s">%s</a></b></i> with <a href="%s"><b>%d</b> Wins.</a><br />';
$lang['games_best_at_player'] = '<b><u>Best All Time Player</b></u><br /><img src="images/crown.gif" alt="[Best]" border="0" /> <b><i><a href="%s">%s</a></b></i> with <a href="%s"><b>%d</b> Wins.</a><br />';

$lang['cat_jump_to'] = 'Jump to Category';

//
//  Comment System (2.0.8)
//
$lang['arcade_comment_edit'] = 'Arcade Comment Editor';
$lang['arcade_comment_delete'] = 'Arcade Comment Deletion';
$lang['arcade_comment_sure'] = 'Are you sure you want to delete the comment?';
$lang['no_comment_text'] = 'Sorry, you did not enter enough information';
$lang['to_much_comment_text'] = 'Sorry, This site only excepts comments up to %d charactures';
$lang['comment_poster'] = 'Poster:';
$lang['games_comment_added_info'] = "A new comment has been added to your comment left for %s..!\n\n\nYours {The phpBB Arcade Mod}\n\n(You can disable messages from the arcade in your user profile)";
$lang['games_comment_info'] = "Re: Comment from the Arcade";
//
//  2.1.2
//
$lang['arcade_added'] = 'Added';
$lang['arcade_comments'] = 'Comments';

/******************************************************************************
//  Rating System
******************************************************************************/
$lang['rating'] = 'Rating';
$lang['times'] = ' times.';
$lang['already_rated'] = 'Already Rated';
//
//  2.1.2
//
$lang['arcade_not_rated'] = 'Not Rated';
$lang['arcade_rated'] = 'Rated';
$lang['arcade_title'] = 'Title';
$lang['arcade_played'] = 'Played';
$lang['arcade_current_rating'] = 'Current Rating';
$lang['arcade_rate'] = 'Rate';
$lang['arcade_rate_return_cat'] = 'Click to return to the %sCategory%s';
$lang['arcade_rate_return_forum'] = 'Click to return to the %sForum%s';


/*****************************************************************************
// Moderators
*****************************************************************************/
$lang['amod_mod_config_updated'] = 'Arcade / Activites Moderators Configuration Updated<br /><br />';
$lang['moderators_options'] = 'Moderators Options';
$lang['amod_admin_offline'] = '<b>Take Mod Offline</b><br />-:- Will your moderators be able to take the mod offline.';
$lang['amod_admin_scores'] = '<b>Moderators Scores Options</b><br />-:- What control over the scores will your Moderators have?';
$lang['amod_admin_games'] = '<b>Moderators Game Options</b><br />-:- Can your Moderators take games online/offline?';
$lang['amod_admin_ban'] = '<b>Moderators Ban Users</b><br />-:- Can your moderators ban users from posting scores?';
//
// 2.0.8
//
$lang['arcade_mod_menu'] = 'phpBB Arcade Mod - Moderators Menu';
//
// 2.1.0
//
$lang['games_rate'] = "[Rate]";
$lang['games_add_comments'] = "[Comment]";
$lang['arcade_score_sure'] = "<br />Remove Score '<b>%d</b>' for user '<b><i>%s</i></b>'<br /><br />Are you Sure?";

/******************************************************************************
// Tournament
******************************************************************************/
$lang['tournaments'] = 'Tournaments';
$lang['tournament'] = 'Tournament';
$lang['Active_Tournaments_link'] = 'There are <b>%s</b> <a href="%s" class="gensmall" alt="View Tournaments">Active Tournaments</a>';
$lang['amod_tour_config_updated'] = 'Arcade / Activities Tournaments Updated<br /><br />';
$lang['Total'] = 'Total';
$lang['Join'] = 'Join';
$lang['Start'] = 'Start';
$lang['Info'] = 'Info';
$lang['Stats'] = 'Stats';
$lang['Data'] = 'Data';
$lang['tournament_games'] = 'Games: <b>%d</b>';
$lang['tournament_players'] = 'Players: <b>%d</b>';
$lang['Min'] = 'Min';
$lang['Max'] = 'Max';
$lang['Yes'] = 'Yes';
$lang['No'] = 'No';
$lang['End'] = 'End';
$lang['No-One'] = 'No-One';
$lang['has'] = 'has';
$lang['have'] = 'have';
$lang['tour_added']  = 'Tournament Added';
$lang['tour_add_players'] = '<br /><br /><a href="arcade_tournament.'.$phpEx.'?mode=add_players">Add Players</a><br /><br />';
$lang['tour_invite_players'] = '<br /><br /><a href="arcade_tournament.'.$phpEx.'?mode=invite_players">Invite Players</a><br /><br />';
$lang['tour_add_games'] = '<a href="arcade_tournament.'.$phpEx.'?mode=add_games">Add Games</a>';
$lang['tour_play_stats'] = 'You have %d turns remaining<br>%s has the best score so far<br>%s %s played';
$lang['tour_not_part'] = 'You are not apart of the Tournament<br>%s has the best score so far<br>%s has played';
$lang['tour_no_join'] = 'Opps, you didn\'t select anything to join';
$lang['tour_return'] = '<br><br>Click <a href="arcade_tournament.'.$phpEx.'">HERE</a> to return to the Tournaments';
$lang['tour_joined'] = '<br>Your have Joined the %s Tournament';
$lang['tour_full'] = '<br>The Tournament %s is full.';
$lang['tour_member'] = '<br>Already a Member of the %s Tournament';
$lang['tour_save'] = '<b>Tournament Score</b><br><br>';
$lang['waiting'] = 'Waiting';
$lang['inactive'] = 'Inactive';
$lang['active'] = 'Active';
$lang['complete'] = 'Complete';
$lang['finished'] = 'Finished';
$lang['running'] = 'Running';
$lang['champion'] = 'Champion';
$lang['champion_of'] = '&laquo; The Champion was &raquo;';
$lang['Champions'] = 'Last %d Champions';
$lang['view_champions'] = 'View the Champions List';
$lang['arcade_tournament_end_sure'] = 'Are you sure you want to End this Tournament?';
$lang['arcade_tournament_end'] = 'Tournament <b>%s</b> has now ended';
$lang['tour_msg_subject'] = 'phpBB Arcade - Tournament Information';
$lang['tour_msg_message'] = "\n\n\nTo view the Results click [url=http://%sarcade_tournament.".$phpEx."]HERE[/url]\n\n\nYours {The phpBB Arcade Mod}\n\n(You can disable messages from the arcade in your user profile)";
$lang['tour_msg_winner'] = " with <b>%s</b> being the champion.";
$lang['tour_msg_draw'] = ' has been drawn, and has been re-started ';
$lang['arcade_admin_only'] = 'Admin ONLY Feature.';
$lang['Invite_Players'] = 'Invite Players';
$lang['Invite'] = 'Invite';
$lang['Add_Games'] = 'Add Games';

/******************************************************************************
// If anything is changed below this line, then don't be surprised if you don't
// get very good support from the MOD Author. The next few lines deal with 
// error handling & GPL licenses. 
// By changing them you could break the law as well as cause errors.
//=============================================================================
// DO NOT TAKE THIS LINK OUT! Scott Porters Gamelib requires that this link is 
// included by anyone using his library. If you don't have any games using
// gamelib, then turn it off and this link will not get displayed!
******************************************************************************/
$lang['game_lib_link'] = '<br />Some of the <b>JAVA</b> games here have been created with &copy; <A HREF="http://www.javascript-games.org/gamelib/" TARGET="New_Window">GameLib</A> v2.08<br />Check out <A HREF="http://www.javascript-games.org" TARGET="New_Window">JavaScript Games</A> for more info.';
$lang['activitiy_mod_info'] = 'phpBB Activity, Arcade Mod %s &copy 2001, 2007 - Napoleon, dEfEndEr';

/******************************************************************************
// Errors
******************************************************************************/
$lang['no_main_data'] = 'Couldn\'t obtain main data';
$lang['no_game_data'] = 'Couldn\'t obtain game data';
$lang['no_cat_update'] = 'Couldn\'t update category data';
$lang['no_cat_data'] = 'Couldn\'t obtain category data';
$lang['no_cat_data_enter'] = 'No category Name or Description - Saved Failed';
$lang['no_game_update'] = 'Couldn\'t update game data';
$lang['no_game_total'] = 'Error getting total games';
$lang['no_game_user'] = 'Error obtaining user game data';
$lang['no_game_delete'] = 'Couldn\'t delete game';
$lang['no_game_repair'] = 'Couldn\'t repair game tables';
$lang['no_game_save'] = 'Couldn\'t save game data';
$lang['no_user_data'] = 'Couldn\'t obtain user data';
$lang['no_user_update'] = 'Couldn\'t update user data';
$lang['no_score_data'] = 'Couldn\'t obtain scores data';
$lang['no_score_reset'] = 'Couldn\'t reset scores data';
$lang['no_score_insert'] = 'Couldn\'t insert score';
$lang['no_score_reset'] = 'Couldn\'t reset scores';
$lang['no_config_data'] = 'Could not access Online Activities configuration';
$lang['no_config_update'] = 'Failed to update Online Activities configuration for ';
$lang['no_game_info_data'] = 'ERROR, No Game Data Received<br />';
$lang['no_game_import'] = 'No file to Import / Export<br /><br />';
$lang['no_game_import_found'] = 'Import file not found<br /><br />';
$lang['no_read_game_data'] = 'Error reading from file';
$lang['no_write_game_data'] = 'Error writing to file';
//
// 2.0.4 - 2.0.6
//
$lang['no_game_data_inform'] = 'ERROR, Reading Game Data from System<br /><br />This Error is caused by your Proxy settings.<br /><br />Registering will allow you to save scores.';
$lang['session_error'] = 'Error creating new session';
$lang['game_invalid_game'] = 'ERROR - Invalid Game Options Received<br /><br />This game was not crated for this Arcade Mod<br />';
$lang['no_special_play_games'] = 'None Available';
$lang['games_no_guests'] = 'Sorry, You Have NO Access to that Game';
$lang['game_not_compatable'] = 'This game is NOT compatable with this version of Arcade mod<br />';
//
// 2.0.7
//
$lang['game_move_error'] = 'Hit an Error, Deleted a game from slot Zero<br /><br />Added it as a NEW game, Move not processed - Please Try Again';
$lang['game_at_bottom'] = 'You are already at the bottom';
$lang['game_at_top'] = 'You are already at the top';
$lang['newscore_close'] = '<br /><a href="javascript:self.close();"><img src="images/close.gif" alt="[Close]" border="0" /></a>';
/*****************************************************************************
						Only change the word CLOSE in the line ABOVE
*****************************************************************************/
//
// 2.0.8
//
$lang['no_arcadehash_data'] = 'Error Reading Game Data from URL';
$lang['amod_update_error'] = 'Error updating database, unable to connect';
$lang['admin_arcade_reward_error'] = 'You can ONLY set-up ONE reward system at a time<br><br>';
$land['no_rate_data'] = 'Couldn\'t obtain rate data';
$lang['no_rate_update'] = 'Couldn\'t update rate data';
$lang['no_comment_data'] = 'Couldn\'t obtain comment data';
$lang['no_comment_edit_data'] = 'Couldn\'t obtain comment editing data';
$lang['no_comment_update'] = 'Couldn\'t update comment data';
$lang['no_comment_found'] = 'This comment does not exist';
$lang['does_not_exist'] = 'Does not exist.';
$lang['no_activity'] = 'No Activity specified';
$lang['bad_submitted_value'] = 'Bad submited value';
$lang['error_game_info_data'] = '<br>Unexpected Error - Bad GAME??<br>';
$lang['games_group_rank_limit'] = 'Sorry, your Access level does not allow access to this area.';
//
//  2.1.0
//
$lang['arcade_file_not_found'] = '<font color="#FFFFFF">Unable to find the file:> %s</font>';
//
//  2.1.2
//
$lang['newscore_close_first'] = '<br /><a href="javascript:self.close();opener.location.reload(true);"><img src="images/close.gif" alt="[Close]" border="0" /></a>';
/*****************************************************************************^^^^^
						Only change the word CLOSE in the line ABOVE
*****************************************************************************/
$lang['arcade_incorrect_install'] = 'Incorrect Installation, Check the install.txt and update your includes/constants.php';
$lang['arcade_incorrect_version'] = 'Incorrect Installation, Version Missmatch %s should be %s Use the ACP DB Update routine';
$lang['arcade_user_held'] = 'Sorry, Your access to the Arcade/Activity section is ON-HOLD';
$lang['arcade_user_banned'] = 'Sorry, Your access to the Arcade/Activity section has been REMOVED';
//
//  2.1.3
//
$lang['class_invalid_data'] = 'Error -> %s <- invalid data.';
$lang['game_id_error'] = 'The entered game ID does not exist.';
$lang['no_tour_data'] = 'Couldn\'t obtain tournament data';
$lang['no_tour_update'] = 'Couldn\'t update tournament data';
$lang['no_tour_delete'] = 'Couldn\'t delete tournament data';
$lang['no_tour_player_data'] = 'Couldn\'t obtain tournament player data';
$lang['no_tour_update_data'] = 'Couldn\'t update tournament player data';
$lang['no_tour_delete_data'] = 'Couldn\'t delete tournament player data';
$lang['no_cookie_data'] = 'Sorry - Guest\'s must ALLOW cookies from this site<br />';
$lang['no_tour_play_data'] = 'Couldn\'t obtain tournament player data';
$lang['arcade_incorrect'] = 'Invalid system call<br><br>You can not access this program via the Internet';
$lang['game_not_available'] = 'Sorry, this game is not online currently.';
$lang['score_no_guest'] = 'Sorry, Guests can not post high scores<br /><br />Registration is free, and you could hold the game trophy..<br />';
//
//  2.1.4
//
$lang['session_data_error'] = 'Couldn\'t obtain session data';
$lang['error_no_session'] = 'Arcade Session Error<br/ >';
$lang['incorrect_game_info_data'] = 'Incorrect game data received<br />';
$lang['no_session_data'] = 'ERROR: No Arcade Session found<br />';
$lang['game_name_error'] = 'Incorrect Data Received';
$lang['arcade_admin_must_play'] = 'This Activity has not been played by an Admin/Mod<br><br>So I am unable to save your score yet..!';
//
//  2.1.6
//
$lang['Your'] = 'Your';
$lang['1st'] = '1st';
$lang['2nd'] = '2nd';
$lang['3rd'] = '3rd';
$lang['arcade_comments'] = 'Arcade Comments for ';
$lang['cats_no_access'] = 'Sorry, you have no access to that Category';
$lang['game_repair_critical'] = '<br><b>Your Arcade has a Serious Issue, you need to MANUALLY add back the game_id field.</b><br><br><br><i>ALTER TABLE `phpbb_ina_games` ADD `game_id` MEDIUMINT( 9 ) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;</i><br><br />';
$lang['game_link_error'] = 'Link file DATA Error - Please use the DESC as the LINK<br><br />';
//
//  2.1.8
//
$lang['arcade_link_visted'] = 'link visited %s times';
$lang['activitiy_mod__newinfo'] = 'phpBB Activity, Arcade Mod %s is available';
//
//  Changed from v2.0.8
//
$lang['arcade_install_issue'] = '<br>You have not followed the install instructions correctly.<br><br /><a href="http://www.phpbb-arcade.com/viewtopic.php?t=766"><CLICK HERE></a><br><br />';

//
// Monthly Highscore Mod
//
$lang['Highscore'] = 'Highscores';
$lang['highscore_table_error'] = 'Wasn\'t able to obtain data from the Highscore Table';
$lang['highscore_jan'] = 'January';
$lang['highscore_feb'] = 'February';
$lang['highscore_mar'] = 'March';
$lang['highscore_apr'] = 'April';
$lang['highscore_may'] = 'May';
$lang['highscore_jun'] = 'June';
$lang['highscore_jul'] = 'July';
$lang['highscore_aug'] = 'August';
$lang['highscore_sep'] = 'September';
$lang['highscore_oct'] = 'October';
$lang['highscore_nov'] = 'November';
$lang['highscore_dec'] = 'December';
$lang['highscore_table_header'] = 'Highscore Ranking List For';
$lang['highscore_submit'] = 'Show';
$lang['highscore_no_score'] = 'No Score added';
$lang['highscore_for'] = 'Highscores for';
$lang['highscore_count_err'] = 'Wasn\'t able to count Highscores';
$lang['highscore_other_score'] = "Highscores for the month";
$lang['highscore_new_mon_score'] = '<b>New Highscore for the month added</b>';
$lang['highscore_no_new_mon_score'] = 'No new Highscore for the month reached';
//
// TFFT - The End...!
//

?>
