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
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
//
// Start session management 
//
$userdata			= session_pagestart($user_ip, PAGE_ACTIVITY); 
init_userprefs($userdata); 
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
if (!phpbb_request_source_is_same_origin())
{
	message_die(GENERAL_ERROR, 'Cross-site score submissions are not accepted.');
}
phpbb_arcade_enforce_protocol_limit();
$arcade_version = $arcade->arcade_config('version');
//
// End session management 
//
$enscore      = 0;
$decodescore  = 0;
$do           = $arcade->pass_var('do', '');
if (!in_array($do, array('verifyscore', 'savescore', 'newscore'), true))
{
	$gen_simple_header = TRUE;
	message_die(GENERAL_MESSAGE, $lang['newscore_close']);
}
$session_info = $arcade->get_session();
//
//  Arcade LOG
//
if ($arcade->arcade_config['games_use_log'] == 1)
{
	$log = $db->sql_escape('IBProArcade action=' . substr((string) $do, 0, 32));
	$sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date)
		VALUES (".(int) $userdata['user_id'].", 'GAME', '$log', ".time().")";
	$db->sql_query($sql);
}
//
//  Now Process the IBProArcade v3.x Commands
//
if($do == 'verifyscore')
{
	$randchar1 = (hexdec(bin2hex(phpbb_random_bytes(2))) % 200) + 1;
	$randchar2 = (hexdec(bin2hex(phpbb_random_bytes(2))) % 200) + 1;
	$session_hash_sql = $db->sql_escape($session_info['arcade_hash']);

  $sql = "UPDATE " . iNA_SESSIONS . "
    SET randchar1 = $randchar1, randchar2 = $randchar2
    WHERE arcade_hash = '". $session_hash_sql . "'";
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
  
  if(is_finite((float) $arcade->score) && abs((float) $arcade->score) <= 9999999999.9999 &&
	(int) $session_info['randchar1'] >= 1 && (int) $session_info['randchar1'] <= 200 &&
	(int) $session_info['randchar2'] >= 1 && (int) $session_info['randchar2'] <= 200 && $enscore == $decodescore)
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
