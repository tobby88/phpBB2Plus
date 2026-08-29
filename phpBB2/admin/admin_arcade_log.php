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
$mode         = $arcade->pass_var('mode', '', true);
$start        = $arcade->pass_var('start', 0, true);
$sort         = $arcade->pass_var('sort', 0, true);
$purge        = isset($HTTP_POST_VARS['purge']) ? (string) $HTTP_POST_VARS['purge'] : '';
$x            = isset($HTTP_POST_VARS['x']) ? intval($HTTP_POST_VARS['x']) : 0;
$y            = isset($HTTP_POST_VARS['y']) ? intval($HTTP_POST_VARS['y']) : 0;
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
if($purge != '')
{
  if (!isset($HTTP_POST_VARS['sid']) || !hash_equals((string) $userdata['session_id'], (string) $HTTP_POST_VARS['sid']))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  if($sort < 1)
  {
    $sql = "TRUNCATE " . iNA_LOG;
  }
  else
  {
    $sql = "DELETE FROM " . iNA_LOG . " " . $where;
  }
  if(!($result = $db->sql_query($sql)))
  {
    message_die(GENERAL_ERROR, '', '', __LINE__, __FILE__, $sql);
  }  
}
//
//  Delete the selected records.
//
if($x > 0 && $y > 0)
{
  if (!isset($HTTP_POST_VARS['sid']) || !hash_equals((string) $userdata['session_id'], (string) $HTTP_POST_VARS['sid']))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  $record_no = isset($HTTP_POST_VARS['record_no']) ? $HTTP_POST_VARS['record_no'] : 0;
  
  if(is_array($record_no))
  {
    for($i = 0; $i < count($record_no); $i++)
    {
      $record_id = intval($record_no[$i]);
      if ($record_id <= 0) { continue; }
      $sql = "DELETE FROM " . iNA_LOG . "
        WHERE record_no = " . $record_id;
      if(!($result = $db->sql_query($sql)))
      {
        message_die(GENERAL_ERROR, '', '', __LINE__, __FILE__, $sql);
      }
    }
  }
}
//
//
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
$sql = "SELECT u.username, l.*, COUNT(l.record_no) AS count FROM " . iNA_LOG . " l
  LEFT JOIN " . USERS_TABLE . " u ON l.user_id = u.user_id
  $where
  GROUP BY record_no
  ORDER BY record_no DESC";
//
//  Use the games per page to display the results
//
if($arcade->arcade_config['games_per_admin_page'] > 0)
{
  $sql .= " LIMIT ".$start.",".$arcade->arcade_config['games_per_admin_page'];
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
      'RECORD_NAME' => htmlspecialchars($error_log[$i]['name'], ENT_QUOTES, 'UTF-8'),
      'RECORD_USERNAME' => htmlspecialchars($error_log[$i]['username'], ENT_QUOTES, 'UTF-8'),
      'RECORD_DATA' => htmlspecialchars($error_log[$i]['value'], ENT_QUOTES, 'UTF-8')
        ));
  }
//
//  Pagination
//
	$games_per_admin_page = max(1, intval(isset($arcade->arcade_config['games_per_admin_page']) ? $arcade->arcade_config['games_per_admin_page'] : 20));
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
  
  'DELETE_IMG' => './../' . $images['icon_delpost'] . '" alt="' . $lang['Delete'] . '" title="' . $lang['Delete'],
  
  'RECORDS' => sprintf($lang['arcade_log_records'], $record_count),
  'VERSION' => sprintf($lang['activitiy_mod_info'], $arcade->version),
  
  'PURGE' => $lang['arcade_log_purge'],
  'SORT' => $sort,
  'PAGE_NO' => $page_number,
  'PAGINATION' => $pagination,
  
  'S_ACTION' => append_sid("$file"),
  'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />'
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
