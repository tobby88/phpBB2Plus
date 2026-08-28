<?php
/*************************************************************************** 
 *                          admin_arcade_cache.php 
 *                          ---------------------- 
 *   begin                : Wednesday, November 29th, 2006
 *   copyright            : (c) 2003-2006 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: admin_arcade_cache.php,v 2.0.0 2006/11/29 12:59:59 dEfEndEr Exp $
 ***************************************************************************
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
//
//  Make this file apart of the phpBB system files.
//
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
//
//  Make sure the ACP doesn't go and run something.
//
if( !empty($setmodules) )
{
	$module['Arcade']['Cache'] = "admin_arcade_cache.".$phpEx;
	return;
}
//
//  Set the system ROOT directory
//
$phpbb_root_path = './../';
//
//  Load phpBB System required files
//
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
//
//  Load the Arcade required files
//
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
//  Check the phpBB Arcade Mod version
//
$version = $arcade->version('./../');
//
//  Set filename
//
$file = basename(__FILE__);
//
//  Check to see if the SUBMIT button has been activated / Process Changes
//
if(!empty($HTTP_POST_VARS['submit']))
{
  $use_cache = intval($HTTP_POST_VARS['use_cache']);
//
//  First Set the Main Cache Option
//
	$sql = "UPDATE " . iNA . "
 			SET config_value = '".$use_cache."'
		WHERE config_name = 'use_cache'";
 	if( !$db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_config_update'] . $use_cache, '', __LINE__, __FILE__, $sql);
 	}
//
//  First Load the Main Config Option's
//
	$sql = "SELECT * FROM " . iNA . "
		WHERE config_name IN('config_cache','categories_cache','games_cache','highscore_cache', 'at_highscore_cache')";

	if( !$result = $db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
	}
	while( $row = $db->sql_fetchrow($result) )
	{
//
//  Build Array for database updates
//
		$config_name = $row['config_name'];
		$config_value = $row['config_value'];
		$default_config[$config_name] = $config_value;
//
//  next update the different Cache settings
//
		$new[$config_name] = ( isset($HTTP_POST_VARS[$config_name]) ) ? $HTTP_POST_VARS[$config_name] : $default_config[$config_name];
    $newvalue = (intval($new[$config_name]) < 10080) ? (intval($new[$config_name])*60) : 10080*60;

		$sql = "UPDATE " . iNA . "
  			SET config_value = '" . $newvalue . "'
			WHERE config_name = '$config_name'";
		
  	if(!$db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_config_update'] . $config_name, '', __LINE__, __FILE__, $sql);
  	}
  }
//
//  Clear the cached copy of the config
//
  $arcade->clear_cache('config');
}
//
//  Load the config back into memory
//
$config_version = $arcade->arcade_config('version','./../');
//
//  Check to see if we have the cache system set to ON
//
if($arcade->arcade_config['use_cache'])
{
		$template->assign_block_vars('cache_menu_on', array( ));

}
//
//  Set the main template name
//
$template->set_filenames(array('body' => 'admin/arcade_cache.tpl'));
//
//  Build Template
//
$template->assign_vars(array(
	'S_GAME_ACTION' => append_sid("$file"),
	'VERSION' => $version,

	'L_CACHE_MENU' => $lang['admin_cache_menu'],
	'L_INA_HEADER' => $lang['admin_cache_header'],
	'L_TOGGLES' => $lang['admin_toggles'],
  'L_USE_CACHE_INFO' => $lang['admin_use_cache_info'],
  'L_USE_CACHE' => $lang['admin_use_cache'],
  'L_CONFIG' => $lang['admin_config_cache'],
  'L_CONFIG_INFO' => $lang['admin_config_cache_info'],
  'L_CAT' => $lang['admin_cat_cache'],
  'L_CAT_INFO' => $lang['admin_cat_cache_info'],
  'L_GAMES' => $lang['admin_games_cache'],
  'L_GAMES_INFO' => $lang['admin_games_cache_info'],
  'L_HIGHSCORE' => $lang['admin_highscore_cache'],
  'L_HIGHSCORE_INFO' => $lang['admin_highscore_cache_info'],
  'L_AT_HIGHSCORE' => $lang['admin_at_highscore_cache'],
  'L_AT_HIGHSCORE_INFO' => $lang['admin_at_highscore_cache_info'],
    
  'L_YES' => $lang['Yes'],
	'L_NO' => $lang['No'],
	'DASH' => $lang['game_dash'],
	'L_MINS' => $lang['admin_mins'],

	'L_SUBMIT' => $lang['Submit'],
	'L_RESET' => $lang['Reset'],

  'L_ARCADE_CACHE' => $lang['admin_arcade_cache'],

  'S_USE_CACHE_YES' => ( $arcade->arcade_config['use_cache'] ) ? 'checked="checked"' : '',
  'S_USE_CACHE_NO' => ( !$arcade->arcade_config['use_cache'] ) ? 'checked="checked"' : '',

  'S_CONFIG_CACHE' => ($arcade->arcade_config['config_cache'] / 60),
  'S_CAT_CACHE' => ($arcade->arcade_config['categories_cache'] / 60),
  'S_GAMES_CACHE' => ($arcade->arcade_config['games_cache'] / 60),
  'S_HIGHSCORE_CACHE' => ($arcade->arcade_config['highscore_cache'] / 60),
  'S_AT_HIGHSCORE_CACHE' => ($arcade->arcade_config['at_highscore_cache'] / 60),
		
	'S_HIDDEN_FIELDS' => '' ));
//
//  Generate the page
//
$template->pparse('body');
//
//  Generate footer
//
include('page_footer_admin.' . $phpEx);

?>
