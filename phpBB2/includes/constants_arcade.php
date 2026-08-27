<?php
/***************************************************************************
 *                           constants_arcade.php
 *                           --------------------
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (C) 2004 - 2007 phpbb-arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: functions_arcade.php, v2.1.8 2007/01/02 14:06:00 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 *  All Information Contained Within is Copyright www.phpbb-arcade.com
 *
 **************************************************************************/

if ( !defined('IN_PHPBB') || $HTTP_GET_VARS['phpbb_root_path'])
{
	die("Hacking attempt");
}

if ( defined('ARCADE_CONSTANTS') )
{
	return;
}

define('ARCADE_CONSTANTS', TRUE);
//
//  Page Definitions
//
define('PAGE_PLAYING_GAMES', -216);
define('PAGE_ARCADE_MOD', -217);
define('PAGE_HIGHSCORE', -218);
// Official phpBB Page Numbers
define('PAGE_ACTIVITY', -1355);
define('PAGE_ARCADE_RATE', -1356);
define('PAGE_ARCADE_COMMENTS', -1357);
define('PAGE_ARCADE_SCORE', -1358);
define('PAGE_ARCADE_TOUR', -1359);
//
//  Start of Activity Table Data
//
define('iNA', $table_prefix.'ina_data');
define('iNA_GAMES', $table_prefix.'ina_games');
define('iNA_SCORES', $table_prefix.'ina_scores');
//
//  dEfEndEr's Additional Info for Arcade v2.0.1 Onwards
//
define('iNA_AT_SCORES', $table_prefix.'ina_at_scores');
define('iNA_SESSIONS', $table_prefix.'ina_sessions');
define('iNA_CAT', $table_prefix.'ina_cat');
define('iNA_FAV',  $table_prefix.'ina_fav');
define('iNA_PMs_TABLE',  $table_prefix.'ina_pms');
define('iNA_USER_DATA',  $table_prefix.'ina_user_data');
define('iNA_GAMES_RATE',  $table_prefix.'ina_rate');
define('iNA_GAMES_COMMENT', $table_prefix.'ina_comment');
define('iNA_HIGHSCORE', $table_prefix.'ina_highscore ');
define('iNA_TOUR', $table_prefix.'ina_tour');
define('iNA_TOUR_DATA', $table_prefix.'ina_tour_data');
define('iNA_TOUR_PLAY', $table_prefix.'ina_tour_play');
define('iNA_TOUR_INVITE', $table_prefix.'ina_tour_invite');
define('iNA_BANNED', $table_prefix.'ina_banned');
define('iNA_LOG', $table_prefix.'ina_log');
//
//  Monthly Highscore Mod by Painkiller
//
define('iNA_HIGHSCORES', $table_prefix.'ina_highscore');
//
//  Arcade Save Score Definitions.
//
define('ARCADE_AUTO', '0');
define('ARCADE_GET', '1');
define('ARCADE_POST', '2');
define('ARCADE_NEW', '3');
define('ARCADE_IBPRO', '4');
define('ARCADE_MIXED', '5');
define('ARCADE_pnFlashGames', '6');
define('ARCADE_vBULLETIN', '7');
define('ARCADE_IBPROv3', '8');
//
?>