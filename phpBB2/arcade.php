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
//
//  Process the VBulletin Arcade v3 Command Set
//
if ($sessdo != '')
{		
  $session_info = $arcade->get_session();

	switch($sessdo)
	{
		case 'sessionstart' :		
			echo '&connStatus=1&initbar='. $arcade->pass_var('gamename', '').'&val=x';
			exit;			
		break;
		
		case 'permrequest' :		
			echo '&validate=1&microone='. $arcade->pass_var('score', 0) .'|'. $arcade->pass_var('fakekey', '') .'&val=x';
			exit;			
	 	break;
		
		case 'burn' :		
    	$microone 	         = $arcade->pass_var('microone', '');
			$game_data           = explode('|', $microone);
			$arcade->score 	     = $game_data[0];
			$arcade->game_name   = trim(addslashes(stripslashes($game_data[1])));
      $arcade->arcade_hash = $arcade->pass_var('arcade_hash', '');

      if($arcade->score > 0)
      {
    		echo ('<form method="post" name="vbv3" action="newscore.php"><input type="hidden" name="score" value="'. $arcade->score .'"><input type="hidden" name="game_name" value="'. $arcade->game_name .'"><input type="hidden" name="arcade_hash" value="'. $arcade->arcade_hash .'"></form>
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
