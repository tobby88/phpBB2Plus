<?php
/*************************************************************************** 
 * 
 *                              IBProArcade.php 
 *                            ------------------ 
 *   begin                : Monday, November 27th, 2006 
 *   copyright            : (c) 2006 www.phpbb-arcade.com 
 *   email                : defenders_realm@yahoo.com 
 * 
 *   $Id: IBProArcade.php, v1.1.0 2006/11/29 12:59:59 dEfEndEr Exp $ 
 * 
 ***************************************************************************
 *
 *  All Information Contained Within is Copyright www.phpbb-arcade.com
 *
 **************************************************************************/

define('IN_PHPBB', true);
if(isset($_GET['phpbb_root_path']))
{
	die("Hacking attempt");
}
$phpbb_root_path = './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include_once($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
// Start session management 
//
$userdata			= session_pagestart($user_ip, PAGE_ACTIVITY); 
init_userprefs($userdata); 
$arcade_version = $arcade->arcade_config('version');
//
// End session management 
//
$enscore      = 0;
$decodescore  = 0;
$do           = $arcade->pass_var('do', '');
$session_info = $arcade->get_session();
//
//  Arcade LOG
//
$log = 'IBProArcade ';
foreach($HTTP_POST_VARS as $key => $value)
{
  if(!is_array($value))
  {
    $log .= $key . '=>' . $value . ' ';
  }
}
$log = mysqli_real_escape_string($db->db_connect_id, substr($log, 0, 4000));
$sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
  VALUES (".(int) $userdata['user_id'].", 'GAME', '$log', ".time().")";
$db->sql_query($sql);
//
//  Now Process the IBProArcade v3.x Commands
//
if($do == 'verifyscore')
{
  $randchar1 = rand(1, 200);
	$randchar2 = rand(1, 200);

  $sql = "UPDATE " . iNA_SESSIONS . "
    SET randchar1 = $randchar1, randchar2 = $randchar2
    WHERE arcade_hash = '". $session_info['arcade_hash'] . "'";
  if(!$result = $db->sql_query($sql)) 
  {
  	message_die(GENERAL_ERROR, $lang['session_data_error'] . $lang['newscore_close'], "", __LINE__, __FILE__, $sql); 
  }

  print("&randchar=$randchar1&randchar2=$randchar2&savescore=1&blah=OK");
  exit;
}
else if ($do == 'savescore' || $do == 'newscore')
{
  $arcade->score = $arcade->pass_var('gscore', 0);
  $enscore = $arcade->pass_var('enscore', 0);

  $decodescore = $arcade->score * $session_info['randchar1'] ^ $session_info['randchar2'];
  
  if(is_finite((float) $arcade->score) && $enscore == $decodescore)
  {  
    $arcade->game_name = $session_info['game_name'];

    require 'newscore.' . $phpEx;
    exit;
  }
  else
  {
    message_die(CRITICAL_ERROR, $lang['newscore_close']);
  }
}

$gen_simple_header = TRUE;
message_die(GENERAL_MESSAGE, $lang['newscore_close']);

?>
