<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/

class pafiledb_stats extends pafiledb_public
{
	function main($action = false)
	{
		global $pafiledb_template, $lang, $board_config, $phpEx, $pafiledb_config, $db, $images;
		global $_REQUEST, $phpbb_root_path, $userdata;


		if(!$this->auth_global['auth_stats'])
		{
			if ( !$userdata['session_logged_in'] )
			{
				redirect(append_sid("login.$phpEx?redirect=dload.$phpEx?action=stats", true));
			}

			$message = sprintf($lang['Sorry_auth_stats'], $this->auth_global['auth_stats_type']);
			message_die(GENERAL_MESSAGE, $message);
		}

		require_once dirname(__DIR__) . '/includes/functions_stats.php';
		try
		{
			$stats = pafiledb_stats_read($db, pafiledb_toplist_categories($this));
		}
		catch (Exception $error)
		{
			message_die(GENERAL_ERROR, $lang['PA_stats_unavailable']);
			return;
		}
		$stats_text = pafiledb_stats_message($lang['Stats_text'], $stats);

		$agent_lang = array('OPERA' => 'Opera', 'IE' => 'Internet Explorer', 'MOZILLA' => 'Mozilla', 'NETSCAPE' => 'NetScape', 'OTHER' => $lang['PA_stats_other']);
		$agent_image = array('OPERA' => 'opera.gif', 'IE' => 'explorer.gif', 'MOZILLA' => 'mozilla.gif', 'NETSCAPE' => 'netscape.gif', 'OTHER' => 'irix.gif');

		$os_lang = array('Win' => 'Windows', 'Mac' => 'Macintosh', 'Linux' => 'Linux', 'Unix' => 'Unix', 'Other' => $lang['PA_stats_other']);
		$os_image = array('Win' => 'windows.gif', 'Mac' => 'mac.gif', 'Linux' => 'linux.gif', 'Unix' => 'linux.gif', 'Other' => 'os2.gif');

		$os_point = $stats['downloads']['os'];
		$agent_point = $stats['downloads']['browser'];

		$os_graphic = 0;
		$os_graphic_max = count($images['voting_graphic']);

		foreach($os_point as $index => $point)
		{
			$temp_point = ($point > 100) ? 100 : $point;
			$os_graphic_img = $images['voting_graphic'][$os_graphic];
			$os_graphic = ($os_graphic < $os_graphic_max - 1) ? $os_graphic + 1 : 0;
// MX
			$pafiledb_template->assign_block_vars("downloads_os", array(
				'OS_IMG' => "pafiledb/images/stats/" . $os_image[$index],
				'OS_NAME' => $os_lang[$index],
				'OS_OPTION_RESULT' => $point,
				'OS_OPTION_IMG' => $os_graphic_img,
				'OS_OPTION_IMG_WIDTH' => $temp_point * 2)
			);
		}

		$b_graphic = 0;
		$b_graphic_max = count($images['voting_graphic']);

		foreach($agent_point as $index => $point)
		{
			$temp_point = ($point > 100) ? 100 : $point;
			$b_graphic_img = $images['voting_graphic'][$b_graphic];
			$b_graphic = ($b_graphic < $b_graphic_max - 1) ? $b_graphic + 1 : 0;
// MX
			$pafiledb_template->assign_block_vars("downloads_b", array(
				'B_IMG' => "pafiledb/images/stats/" . $agent_image[$index],
				'B_NAME' => $agent_lang[$index],
				'B_OPTION_RESULT' => $point,
				'B_OPTION_IMG' => $b_graphic_img,
				'B_OPTION_IMG_WIDTH' => $temp_point * 2)
			);
		}


		$os_point = $stats['ratings']['os'];
		$agent_point = $stats['ratings']['browser'];

		$os_graphic = 0;
		$os_graphic_max = count($images['voting_graphic']);

		foreach($os_point as $index => $point)
		{
			$temp_point = ($point > 100) ? 100 : $point;
			$os_graphic_img = $images['voting_graphic'][$os_graphic];
			$os_graphic = ($os_graphic < $os_graphic_max - 1) ? $os_graphic + 1 : 0;
// MX
			$pafiledb_template->assign_block_vars("rating_os", array(
				'OS_IMG' => "pafiledb/images/stats/" . $os_image[$index],
				'OS_NAME' => $os_lang[$index],
				'OS_OPTION_RESULT' => $point,
				'OS_OPTION_IMG' => $os_graphic_img,
				'OS_OPTION_IMG_WIDTH' => $temp_point)
			);
		}


		$b_graphic = 0;
		$b_graphic_max = count($images['voting_graphic']);

		foreach($agent_point as $index => $point)
		{
			$temp_point = ($point > 100) ? 100 : $point;
			$b_graphic_img = $images['voting_graphic'][$b_graphic];
			$b_graphic = ($b_graphic < $b_graphic_max - 1) ? $b_graphic + 1 : 0;
// MX
			$pafiledb_template->assign_block_vars("rating_b", array(
				'B_IMG' => "pafiledb/images/stats/" . $agent_image[$index],
				'B_NAME' => $agent_lang[$index],
				'B_OPTION_RESULT' => $point,
				'B_OPTION_IMG' => $b_graphic_img,
				'B_OPTION_IMG_WIDTH' => $temp_point)
			);
		}

		$pafiledb_template->assign_vars(array(
			'S_ACTION_CHART' => append_sid('dload.'.$phpEx.'?action=stats'),

		 	'L_STATISTICS' => $lang['Statistics'],
			'L_INDEX' => sprintf($lang['Forum_Index'], $board_config['sitename']),
			'L_GENERAL_INFO' => $lang['General_Info'],
			'L_DOWNLOADS_STATS' => $lang['Downloads_stats'],
			'L_RATING_STATS' => $lang['Rating_stats'],
			'L_OS' => $lang['Os'],
			'L_BROWSERS' => $lang['Browsers'],

			'U_INDEX' => append_sid('index.'.$phpEx),
			'U_DOWNLOAD' => append_sid('dload.'.$phpEx),

// Mx Addon
			'U_VOTE_LCAP' => "templates/fisubsilversh/images/vote_lcap.gif",
			'U_VOTE_RCAP' => "templates/fisubsilversh/images/vote_rcap.gif",

			'DOWNLOAD' => pafiledb_plain_text($pafiledb_config['settings_dbname']),
			'STATS_TEXT' => $stats_text)
		);
		$this->display($lang['Download'], 'pa_stats_body.tpl');
	}
}
