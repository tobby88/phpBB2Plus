<?php
/***************************************************************************
 *                            calendar.php
 *                            ------------
 *	begin				: 03/08/2003
 *	copyright			: Ptirhiik
 *	email				: admin@rpgnet-fr.com
 *
 *	version				: 1.0.5 - 14/09/2003
 *
 ***************************************************************************/
/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *
 ***************************************************************************/

define('IN_PHPBB', true);
define('IN_CALENDAR', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);
@include($phpbb_root_path . 'profilcp/functions_profile.' . $phpEx);
include($phpbb_root_path . 'includes/functions_calendar.' . $phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
//
// End session management
//

//
//  get parameters
//

//
// set the page title and include the page header
//
$page_title = $lang['Calendar'];
include ($phpbb_root_path . 'includes/page_header.' . $phpEx);
//
// get paramters
//
$start_date = 0;
if (isset($_GET['start']) && is_scalar($_GET['start']) && preg_match('/^(19[7-9][1-9]|20[0-6][0-9])(0[1-9]|1[0-2])([0-2][0-9]|3[01])$/D', (string) $_GET['start'], $date_match))
{
	$year = (int) $date_match[1];
	$month = (int) $date_match[2];
	$day = (int) $date_match[3];
	if (checkdate($month, $day, $year))
	{
		$start_date = mktime(0, 0, 0, $month, $day, $year);
	}
}

if (isset($_POST['start_month'], $_POST['start_year']) && is_scalar($_POST['start_month']) && is_scalar($_POST['start_year']))
{
	$month	= intval($_POST['start_month']);
	$year	= intval($_POST['start_year']);
	if (($month >= 1) && ($month <= 12) && ($year >= 1971) && ($year <= 2069))
	{
		$start_date = mktime( 0,0,0, $month, 01, $year);
	}
}

if (empty($start_date) || ($start_date <= 0))
{
	$start_date = mktime( 0,0,0, intval(date('m', time())), intval(date('d', time())), intval(date('Y', time())) );
}

// get the forum id selected
$fid = '';
if ( isset($_POST['selected_id']) || isset($_GET['fid']) )
{
	$fid = calendar_normalize_forum_filter(isset($_POST['selected_id']) ? $_POST['selected_id'] : $_GET['fid']);
}

//
// template name
//
$template->set_filenames(array(
	'body' => 'calendar_body.tpl')
);

// Header
$template->assign_vars(array(
	'L_CALENDAR'	=> $lang['Calendar'],
	'U_CALENDAR'	=> append_sid("./calendar.$phpEx"),
	)
);

display_calendar('CALENDAR_MONTH', 0, $start_date, $fid);

// system
$s_hidden_fields = '';
if (!isset($nav_separator))
{
	$nav_separator = '&nbsp;->&nbsp;';
}
$template->assign_vars(array(
	'NAV_SEPARATOR'		=> $nav_separator,
	'S_ACTION'			=> append_sid("./calendar.$phpEx"),
	'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
	)
);

// send to browser
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
