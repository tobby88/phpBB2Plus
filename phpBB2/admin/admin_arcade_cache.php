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
$submit = isset($_POST['submit']);
if ($submit)
{
    phpbb_admin_require_post_session();
    require_once dirname(__DIR__).'/includes/functions_arcade_config_storage.php';
    try { phpbb_arcade_cache_save($db,$_POST); }
    catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE,$error->getMessage()); }
    catch (PhpbbOwnedStorageFailure $error)
    {
        $key=$error->getMessage();message_die(GENERAL_MESSAGE,isset($lang[$key])?$lang[$key]:$lang['no_config_update']);
    }
}
// Read current values directly; the ACP must not display another request's
// cached settings or create/normalize configuration rows while rendering.
$result=$db->sql_query('SELECT config_name,config_value FROM '.iNA);
if (!$result) { message_die(GENERAL_ERROR,$lang['no_config_data']); }
while ($row=$db->sql_fetchrow($result)) { $arcade->arcade_config[$row['config_name']]=$row['config_value']; }
$db->sql_freeresult($result);
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
	'S_CONFIG_ACTION' => append_sid("$file"),
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

  'S_CONFIG_CACHE' => max(0, (int) $arcade->arcade_config['config_cache']) / 60,
  'S_CAT_CACHE' => max(0, (int) $arcade->arcade_config['categories_cache']) / 60,
  'S_GAMES_CACHE' => max(0, (int) $arcade->arcade_config['games_cache']) / 60,
  'S_HIGHSCORE_CACHE' => max(0, (int) $arcade->arcade_config['highscore_cache']) / 60,
  'S_AT_HIGHSCORE_CACHE' => max(0, (int) $arcade->arcade_config['at_highscore_cache']) / 60,
		
	'S_HIDDEN_FIELDS' => phpbb_admin_session_field() ));
//
//  Generate the page
//
$template->pparse('body');
//
//  Generate footer
//
include('page_footer_admin.' . $phpEx);

?>
