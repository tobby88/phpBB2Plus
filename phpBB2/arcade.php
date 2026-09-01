<?php
/***************************************************************************
 * 
 *                              arcade.php
 *                             ------------
 *   begin                : Thursday, Jan 4th, 2007
 *   copyright            : (c) 2005 - 2007 phpbb-arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: arcade.php, v1.1.1 2007/01/04 13:49:39 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 *  All Information Contained Within is Copyright www.phpbb-arcade.com
 *
 **************************************************************************/
//
//  Set phpBB Information
//
define('IN_PHPBB', true); 
$phpbb_root_path = './'; 
$phpEx = substr(strrchr(__FILE__, '.'), 1);
//
//  Load required files
//
include_once($phpbb_root_path . 'common.'.$phpEx); 
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ACTIVITY);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
$arcade_version = $arcade->arcade_config('version');
//
// End session management
//
$sessdo       = $arcade->pass_var('sessdo', '');
$sessdo = in_array($sessdo, array('sessionstart', 'permrequest', 'burn'), true) ? $sessdo : '';
//
//  Process the VBulletin Arcade v3 Command Set
//
if ($sessdo != '')
{
	if (!phpbb_request_source_is_same_origin())
	{
		message_die(GENERAL_ERROR, 'Cross-site Arcade protocol requests are not accepted.');
	}
	  $session_info = $arcade->get_session();
	if (empty($arcade->arcade_hash) || empty($session_info['arcade_hash']) ||
		!hash_equals((string) $session_info['arcade_hash'], (string) $arcade->arcade_hash))
	{
		$arcade->message_die(GENERAL_ERROR, $lang['no_session_data']);
	}

	switch($sessdo)
	{
		case 'sessionstart' :		
			header('Content-Type: text/plain; charset=UTF-8');
			echo '&connStatus=1&initbar='. rawurlencode((string) $session_info['game_name']) .'&val=x';
			exit;			
		
		case 'permrequest' :		
			$permission_score = (float) $arcade->pass_var('score', 0);
			$permission_score = (is_finite($permission_score) && abs($permission_score) <= 9999999999.9999) ? round($permission_score, 4) : 0;
			header('Content-Type: text/plain; charset=UTF-8');
			echo '&validate=1&microone='. rawurlencode((string) $permission_score) .'|'. rawurlencode($arcade->pass_var('fakekey', '')) .'&val=x';
			exit;			
		
		case 'burn' :		
    	$microone 	         = $arcade->pass_var('microone', '');
			$game_data           = explode('|', $microone);
			$arcade->score 	     = isset($game_data[0]) ? (float) $game_data[0] : 0;
			$arcade->game_name   = (string) $session_info['game_name'];
			$arcade->arcade_hash = (string) $session_info['arcade_hash'];

			if (is_finite($arcade->score) && $arcade->score > 0 && $arcade->score <= 9999999999.9999)
      {
			$score_html = htmlspecialchars((string) round($arcade->score, 4), ENT_QUOTES, 'UTF-8');
			$game_name_html = htmlspecialchars($arcade->game_name, ENT_QUOTES, 'UTF-8');
			$arcade_hash_html = htmlspecialchars($arcade->arcade_hash, ENT_QUOTES, 'UTF-8');
			header('Content-Type: text/html; charset=UTF-8');
			echo ('<form method="post" name="vbv3" action="newscore.php"><input type="hidden" name="score" value="'. $score_html .'" /><input type="hidden" name="game_name" value="'. $game_name_html .'" /><input type="hidden" name="arcade_hash" value="'. $arcade_hash_html .'" /></form>
    		     <script type="text/javascript">
    		     window.onload = function(){document.vbv3.submit()}
    		     </script>');
      }
		  exit;		
	}
}
else
{
 	$header_location = ( @preg_match("/Microsoft|WebSTAR|Xitami/", getenv("SERVER_SOFTWARE")) ) ? "Refresh: 0; URL=" : "Location: ";
	header($header_location . 'activity.'. $phpEx .'?sid='. $userdata['session_id'], true);
}

?>
