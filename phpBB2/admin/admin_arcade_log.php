<?php
/*************************************************************************** 
 *                          admin_arcade_log.php 
 *                          --------------------- 
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (c) 2007 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: admin_arcade_log.php,v 1.0.0 2007/01/02 12:45:00 dEfEndEr Exp $
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
	$module['Arcade']['Error_Log'] = "admin_arcade_log.".$phpEx;
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
// Lets start work :)
//
$start        = max(0, (int) $arcade->pass_var('start', 0, true));
$sort         = (int) $arcade->pass_var('sort', 0, true);
$sort         = ($sort >= 0 && $sort <= 5) ? $sort : 0;
$write_requested = isset($HTTP_POST_VARS['purge']) || isset($HTTP_POST_VARS['delete']) || isset($HTTP_POST_VARS['x']) || isset($HTTP_POST_VARS['y']);
$pagination   = '';
$page_number  = '';
//
//  make sort into a WHERE statement for SQL
//
switch($sort)
{
  case 1:
    $where = "WHERE name = 'BAD_GAME'";
    break;
    
  case 2:
    $where = "WHERE name = 'ERROR'";
    break;
    
  case 3:
    $where = "WHERE name = 'GAME'";
    break;
    
  case 4:
    $where = "WHERE name = 'GAME_ERROR'";
    break;
    
  case 5:
    $where = "WHERE name = 'POST'";
    break;
    
  default:
    $where = '';
}
//
//  Purge the records
//
if ($write_requested)
{
    phpbb_admin_require_post_session();
    require_once dirname(__DIR__).'/includes/functions_arcade_log_storage.php';
    try { phpbb_arcade_log_remove($db,$HTTP_POST_VARS); }
    catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE,$error->getMessage()); }
    catch (PhpbbOwnedStorageFailure $error)
    {
        $key=$error->getMessage();message_die(GENERAL_MESSAGE,isset($lang[$key])?$lang[$key]:$lang['no_config_update']);
    }
}
//
$template->set_filenames(array('body' => 'admin/arcade_log_body.tpl'));

//
//  Get Record Count
//
$sql = "SELECT COUNT(*) AS total FROM " . iNA_LOG . "
  $where";
if(!($result = $db->sql_query($sql)))
{
  message_die(GENERAL_ERROR, '', '', __LINE__, __FILE__, $sql);
}
$log = $db->sql_fetchrow($result);
$record_count = $log['total'];
//
//  Get the Info from the table
//
$sql = "SELECT u.username, l.* FROM " . iNA_LOG . " l
  LEFT JOIN " . USERS_TABLE . " u ON l.user_id = u.user_id
  $where
  ORDER BY record_no DESC";
//
//  Use the games per page to display the results
//
$games_per_admin_page = max(1, intval(isset($arcade->arcade_config['games_per_admin_page']) ? $arcade->arcade_config['games_per_admin_page'] : 20));
// A deletion can empty the last page; keep remaining records reachable.
$start = $record_count ? min($start, (int) (floor(($record_count - 1) / $games_per_admin_page) * $games_per_admin_page)) : 0;
if($games_per_admin_page > 0)
{
  $sql .= " LIMIT ".$start.",".$games_per_admin_page;
}
if(!($result = $db->sql_query($sql)))
{
  message_die(GENERAL_ERROR, '', '', __LINE__, __FILE__, $sql);
}
$error_log = $db->sql_fetchrowset($result);
if(count($error_log) == 0)
{
  $template->assign_block_vars('log_record_none', array(
   )); 
}
else
{
//
//  Loop through the Log
//
  for($i = 0; $i < count($error_log); $i++)
  {
    $date = create_date($board_config['default_dateformat'], $error_log[$i]['date'], $board_config['board_timezone']);

    $template->assign_block_vars('log_record', array(
      'RECORD_NO' => $error_log[$i]['record_no'],
      'RECORD_DATE' => $date,
      'RECORD_NAME' => phpbb_admin_html($error_log[$i]['name']),
      'RECORD_USERNAME' => phpbb_admin_html(isset($error_log[$i]['username']) ? $error_log[$i]['username'] : ''),
      'RECORD_DATA' => phpbb_admin_html($error_log[$i]['value'])
        ));
  }
//
//  Pagination
//
  if ( $record_count >= $games_per_admin_page )
  {
	  	$pagination = generate_pagination("$file?sort=$sort", $record_count, $games_per_admin_page, $start) . '&nbsp;';
	  	$page_number = sprintf($lang['Page_of'], ( floor( $start / $games_per_admin_page ) + 1 ), ceil( $record_count / $games_per_admin_page ));
  }
}
$template->assign_vars(array(
  'NONE' => $lang['None'],
  
  'LOG_TXT' => $lang['admin_arcade_log'].' v'.$version,
  'LOG_TXT_INFO' => $lang['admin_arcade_log_info'],
  'RECORD_TXT' => $lang['record_number'],
  
  
  'RECORDS' => sprintf($lang['arcade_log_records'], $record_count),
  'VERSION' => sprintf($lang['activitiy_mod_info'], $arcade->version),
  
  'PURGE' => $lang['arcade_log_purge'],
  'L_DELETE' => $lang['Delete'],
  'SORT' => $sort,
  'PAGE_NO' => $page_number,
  'PAGINATION' => $pagination,
  
  'S_ACTION' => append_sid("$file"),
  'S_FORM_TOKEN' => phpbb_admin_session_field()
));
//
// Generate the page
//
$template->pparse('body');
include('page_footer_admin.' . $phpEx);
//
//  End of Program..!
//
?>
