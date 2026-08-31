<?php
/***************************************************************************
 *                              admin_banner.php
 *                            -------------------
 *		ver 1.2.3
 *          Author: Niels Chr. Rød, Denmark
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }

if( !empty($setmodules) )
{
	$file = basename(__FILE__);
	$module['Styles']['Banner'] = $file;
	return;
}

function selection($default='0', $select_name = 'banner_type')
{
	global $lang;
	$options = isset($lang[$select_name]) && is_array($lang[$select_name]) ? $lang[$select_name] : array();
	$type_select = '<select name="' . htmlspecialchars(strtolower($select_name), ENT_QUOTES, 'UTF-8') . '">';
	foreach ($options as $offset => $type)
	{
		$selected = ( $offset == $default ) ? ' selected="selected"' : '';
		$type_select .= '<option value="' . (int) $offset . '"' . $selected . '>' . htmlspecialchars((string) $type, ENT_QUOTES, 'UTF-8') . '</option>';
	}
	$type_select .= '</select>';
	return $type_select;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_banner.' . $phpEx);

function admin_banner_post_scalar($name, $default)
{
	return isset($_POST[$name]) && is_scalar($_POST[$name]) ? (string) $_POST[$name] : $default;
}

if (isset($_POST['mode']))
{
	$mode = is_scalar($_POST['mode']) ? (string) $_POST['mode'] : '';
}
else if (isset($_GET['mode']))
{
	$mode = is_scalar($_GET['mode']) ? (string) $_GET['mode'] : '';
}
else
{
	//
	// These could be entered via a form button
	//
	if( isset($_POST['add']) )
	{
		$mode = "add";
	}
	else if( isset($_POST['save']) )
	{
		$mode = "save";
	}
	else
	{
		$mode = "";
	}
}
if (!in_array($mode, array('', 'edit', 'add', 'save', 'delete'), true))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}


if( $mode!= "")
{
	if( $mode == "edit" || $mode == "add" )
	{
		//
		// They want to add a new banner, show the form.
		//
		if( isset($_POST['id']) || isset($_GET['id']) )
		{
			$banner_id_value = isset($_POST['id']) ? $_POST['id'] : $_GET['id'];
			$banner_id = is_scalar($banner_id_value) ? max(0, (int) $banner_id_value) : 0;
		}
		else
		{
			$banner_id = 0;
		}

		$s_hidden_fields = phpbb_admin_session_field();
		$banner_info = array();

		if( $mode == "edit" )
		{
			if( empty($banner_id) )
			{
				message_die(GENERAL_MESSAGE, $lang['Missing_banner_id']);
			}

			$sql = "SELECT * FROM " . BANNERS_TABLE . "
				WHERE banner_id = $banner_id";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, "Couldn't obtain banner data", "", __LINE__, __FILE__, $sql);
			}

			$banner_info = $db->sql_fetchrow($result);
			if (!$banner_info)
			{
				message_die(GENERAL_MESSAGE, $lang['Missing_banner_id']);
			}
			$s_hidden_fields .= '<input type="hidden" name="id" value="' . $banner_id . '" />';
		}
		else
		{
			// Default settings for new banners
			$banner_info['banner_active'] = 1;
			$banner_info['banner_weigth'] = 50;
			$banner_info['banner_level'] = -1;
			$banner_info['banner_level_type'] = 2;
			$banner_info['banner_type'] = 0;
			$banner_info['banner_width'] = 122;
			$banner_info['banner_height'] = 55;
			$banner_info['banner_filter_time'] = 600;

		}

		$banner_info = array_merge(array(
			'banner_active' => 1, 'banner_weigth' => 50, 'banner_level' => -1,
			'banner_level_type' => 2, 'banner_type' => 0, 'banner_width' => 122,
			'banner_height' => 55, 'banner_filter_time' => 600, 'banner_filter' => 0,
			'banner_owner' => 0, 'banner_timetype' => 0, 'time_begin' => 0,
			'time_end' => 0, 'date_begin' => 0, 'date_end' => 0, 'banner_forum' => 0,
			'banner_spot' => 0, 'banner_name' => '', 'banner_description' => '',
			'banner_click' => 0, 'banner_view' => 0, 'banner_comment' => '',
			'banner_url' => '', 'banner_id' => 0
		), $banner_info);
		$s_hidden_fields .= '<input type="hidden" name="mode" value="save" />';
		$banner_is_active = ( $banner_info['banner_active'] ) ? "checked=\"checked\"" : "";
		$banner_is_not_active = ( !$banner_info['banner_active'] ) ? "checked=\"checked\"" : "";

		$template->set_filenames(array(
			'body' => 'admin/banner_edit_body.tpl')
		);

		$owner = get_userdata(isset($banner_info['banner_owner']) ? (int) $banner_info['banner_owner'] : 0);
		if (!is_array($owner))
		{
			$owner = array('username' => '');
		}
		$s_time_week_begin='<option value="0">-
			<option value="1">'.$lang['datetime']['Mon'].'
			<option value="2">'.$lang['datetime']['Tue'].'
			<option value="3">'.$lang['datetime']['Wed'].'
			<option value="4">'.$lang['datetime']['Thu'].'
			<option value="5">'.$lang['datetime']['Fri'].'
			<option value="6">'.$lang['datetime']['Sat'].'
			<option value="0">'.$lang['datetime']['Sun'];
		$s_time_week_end=$s_time_week_begin;
		$s_time_min_begin ='<option value="00">00
			<option value="10">10
			<option value="15">15
			<option value="20">20
			<option value="30">30
			<option value="40">40
			<option value="45">45
			<option value="50">50
			<option value="59">59';
		$s_time_min_end ='<option value="00">00
			<option value="09">09
			<option value="14">14
			<option value="19">19
			<option value="29">29
			<option value="39">39
			<option value="44">44
			<option value="49">49
			<option value="59">59';
		$s_time_hours_begin ='<option value="00">00
			<option value="01">01
			<option value="02">02
			<option value="03">03
			<option value="04">04
			<option value="05">05
			<option value="06">06
			<option value="07">07
			<option value="08">08
			<option value="09">09
			<option value="10">10
			<option value="11">11
			<option value="12">12
			<option value="13">13
			<option value="14">14
			<option value="15">15
			<option value="16">16
			<option value="17">17
			<option value="18">18
			<option value="19">19
			<option value="20">20
			<option value="21">21
			<option value="22">22
			<option value="23">23';
		$s_time_hours_end=$s_time_hours_begin;
		$s_time_date_begin='<option value="0">-
			<option value="01">01
			<option value="02">02
			<option value="03">03
			<option value="04">04
			<option value="05">05
			<option value="06">06
			<option value="07">07
			<option value="08">08
			<option value="09">09
			<option value="10">10
			<option value="11">11
			<option value="12">12
			<option value="13">13
			<option value="14">14
			<option value="15">15
			<option value="16">16
			<option value="17">17
			<option value="18">18
			<option value="19">19
			<option value="20">20
			<option value="21">21
			<option value="22">22
			<option value="23">23
			<option value="24">24
			<option value="25">25
			<option value="26">26
			<option value="27">27
			<option value="28">28
			<option value="29">29
			<option value="30">30
			<option value="31">31';
		$s_time_date_end=$s_time_date_begin;
		$s_time_months_begin='<option value="0">-
			<option value="01">'.$lang['datetime']['Jan'].'
			<option value="02">'.$lang['datetime']['Feb'].'
			<option value="03">'.$lang['datetime']['Mar'].'
			<option value="04">'.$lang['datetime']['Apr'].'
			<option value="05">'.$lang['datetime']['May'].'
			<option value="06">'.$lang['datetime']['Jun'].'
			<option value="07">'.$lang['datetime']['Jul'].'
			<option value="08">'.$lang['datetime']['Aug'].'
			<option value="09">'.$lang['datetime']['Sep'].'
			<option value="10">'.$lang['datetime']['Oct'].'
			<option value="11">'.$lang['datetime']['Nov'].'
			<option value="12">'.$lang['datetime']['Dec'];
		$s_time_months_end=$s_time_months_begin;
		$s_time_year_begin = '<option value="0">-</option>';
		$current_year = (int) date('Y');
		for ($year = 2000; $year <= $current_year + 10; $year++)
		{
			$s_time_year_begin .= '<option value="' . $year . '">' . $year . '</option>';
		}
		$s_time_year_end =$s_time_year_begin;
		$c_no_time = $c_by_time = $c_by_week = $c_by_date = '';
		$s_banner_spot = '';
		switch ($banner_info['banner_timetype'])
		{
			case 0: $rule_type=$lang['No_time'];
				$rule_begin = $lang['None'];
				$rule_end = $lang['None'];
				$c_no_time = 'CHECKED';break;
			case 2:
				$time_begin = sprintf('%04d', (int) $banner_info['time_begin']);
				$hour_begin=$time_begin['0'].$time_begin['1'];
				$min_begin=$time_begin['2'].$time_begin['3'];
				$time_end = sprintf('%04d', (int) $banner_info['time_end']);
				$hour_end=$time_end['0'].$time_end['1'];
				$min_end=$time_end['2'].$time_end['3'];
				$s_time_hours_begin = str_replace("value=\"$hour_begin\">", "value=\"".$hour_begin."\" SELECTED>" ,$s_time_hours_begin);
				$s_time_hours_end = str_replace("value=\"$hour_end\">", "value=\"".$hour_end."\" SELECTED>" ,$s_time_hours_end);
				$s_time_min_begin = str_replace("value=\"$min_begin\">", "value=\"".$min_begin."\" SELECTED>" ,$s_time_min_begin);
				$s_time_min_end = str_replace("value=\"$min_end\">", "value=\"".$min_end."\" SELECTED>" ,$s_time_min_end);
				$rule_type=$lang['By_time'];
				$rule_begin = sprintf("%04d",$banner_info['time_begin']);
				$rule_end = sprintf("%04d",$banner_info['time_end']);
				$c_by_time = 'CHECKED';break;
			case 4 :
				$time_begin = sprintf('%04d', (int) $banner_info['time_begin']);
				$hour_begin=$time_begin['0'].$time_begin['1'];
				$min_begin=$time_begin['2'].$time_begin['3'];
				$week_begin=$banner_info['date_begin'];
				$time_end = sprintf('%04d', (int) $banner_info['time_end']);
				$hour_end=$time_end['0'].$time_end['1'];
				$min_end=$time_end['2'].$time_end['3'];
				$week_end=$banner_info['date_end'];
				$s_time_hours_begin = str_replace("value=\"$hour_begin\">", "value=\"".$hour_begin."\" SELECTED>" ,$s_time_hours_begin);
				$s_time_hours_end = str_replace("value=\"$hour_end\">", "value=\"".$hour_end."\" SELECTED>" ,$s_time_hours_end);
				$s_time_min_begin = str_replace("value=\"$min_begin\">", "value=\"".$min_begin."\" SELECTED>" ,$s_time_min_begin);
				$s_time_min_end = str_replace("value=\"$min_end\">", "value=\"".$min_end."\" SELECTED>" ,$s_time_min_end);
				$s_time_week_begin=str_replace("value=\"$week_begin\">", "value=\"".$week_begin."\" SELECTED>" ,$s_time_week_begin);
				$s_time_week_end=str_replace("value=\"$week_end\">", "value=\"".$week_end."\" SELECTED>" ,$s_time_week_end);
				$rule_type=$lang['By_week'];
				$day_array = array('Sun','Mon','Tue','Wed','Thu','Fri','Sat');
				$begin_day = max(0, min(6, (int) $banner_info['date_begin']));
				$end_day = max(0, min(6, (int) $banner_info['date_end']));
				$rule_begin = $lang['datetime'][$day_array[$begin_day]].', '.sprintf("%04d",$banner_info['time_begin']);
				$rule_end = $lang['datetime'][$day_array[$end_day]].', '.sprintf("%04d",$banner_info['time_end']);
				$c_by_week = 'CHECKED';break;
			case 6:
				$time_begin = sprintf('%04d', (int) $banner_info['time_begin']);
				$hour_begin=$time_begin['0'].$time_begin['1'];
				$min_begin=$time_begin['2'].$time_begin['3'];
				$time_end = sprintf('%04d', (int) $banner_info['time_end']);
				$hour_end=$time_end['0'].$time_end['1'];
				$min_end=$time_end['2'].$time_end['3'];
				$s_time_hours_begin = str_replace("value=\"$hour_begin\">", "value=\"".$hour_begin."\" SELECTED>" ,$s_time_hours_begin);
				$s_time_hours_end = str_replace("value=\"$hour_end\">", "value=\"".$hour_end."\" SELECTED>" ,$s_time_hours_end);
				$s_time_min_begin = str_replace("value=\"$min_begin\">", "value=\"".$min_begin."\" SELECTED>" ,$s_time_min_begin);
				$s_time_min_end = str_replace("value=\"$min_end\">", "value=\"".$min_end."\" SELECTED>" ,$s_time_min_end);
				$date_begin = sprintf('%08d', (int) $banner_info['date_begin']);
				$year_begin=$date_begin['0'].$date_begin['1'].$date_begin['2'].$date_begin['3'];
				$month_begin=$date_begin['4'].$date_begin['5'];
				$day_begin=$date_begin['6'].$date_begin['7'];
				$date_end = sprintf('%08d', (int) $banner_info['date_end']);
				$year_end=$date_end['0'].$date_end['1'].$date_end['2'].$date_end['3'];
				$month_end=$date_end['4'].$date_end['5'];
				$day_end=$date_end['6'].$date_end['7'];
				$s_time_year_begin = str_replace("value=\"$year_begin\">", "value=\"$year_begin\" SELECTED>" ,$s_time_year_begin);
				$s_time_year_end = str_replace("value=\"$year_end\">", "value=\"$year_end\" SELECTED>" ,$s_time_year_end);
				$s_time_months_begin = str_replace("value=\"$month_begin\">", "value=\"$month_begin\" SELECTED>" ,$s_time_months_begin);
				$s_time_months_end = str_replace("value=\"$month_end\">", "value=\"$month_end\" SELECTED>" ,$s_time_months_end);
				$s_time_date_begin = str_replace("value=\"$day_begin\">", "value=\"$day_begin\" SELECTED>" ,$s_time_date_begin);
				$s_time_date_end = str_replace("value=\"$day_end\">", "value=\"$day_end\" SELECTED>" ,$s_time_date_end);
				$rule_type=$lang['By_date'];
				$rule_begin = $banner_info['date_begin'].', '.sprintf("%04d",$banner_info['time_begin']);
				$rule_end = $banner_info['date_end'].', '.sprintf("%04d",$banner_info['time_end']);
				$c_by_date = 'CHECKED';break;
		default:	$rule_type=$lang['Not_specify'];
		}
		foreach ((array) $lang['Banner_spot'] as $n => $label)
		{
			$s_banner_spot .= '<option value="' . (int) $n . '"' . ($banner_info['banner_spot'] == $n ? ' selected="selected"' : '') . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
		}
		$s_level='<select name="banner_level">';
		foreach ((array) $lang['Banner_level'] as $n => $label)
		{
			$s_level .= '<option value="' . (int) $n . '"' . ($banner_info['banner_level'] == $n ? ' selected="selected"' : '') . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
		}
		$s_level .='</select>';
		$s_level_type = '<select name="banner_level_type">';
		foreach ((array) $lang['Banner_level_type'] as $n => $label)
		{
			$s_level_type .= '<option value="' . (int) $n . '"' . ($banner_info['banner_level_type'] == $n ? ' selected="selected"' : '') . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
		}
		$s_level_type .='</select>';


		//forum selection
		$sql = "SELECT f.forum_name, f.forum_id
			FROM " . FORUMS_TABLE . " f
			WHERE f.cat_id=0 ORDER BY f.forum_name ASC";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, "Couldn't obtain special pages list", "", __LINE__, __FILE__, $sql);
		}
		$forum_rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);

		$sql = "SELECT f.forum_name, f.forum_id
			FROM " . FORUMS_TABLE . " f, " . CATEGORIES_TABLE . " c
			WHERE c.cat_id = f.cat_id ORDER BY c.cat_order ASC, f.forum_order ASC";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, "Couldn't obtain forum list", "", __LINE__, __FILE__, $sql);
		}
		$forum_rows = array_merge($forum_rows,$db->sql_fetchrowset($result));
		$db->sql_freeresult($result);

		$forum_select_list = '<select name="' . POST_FORUM_URL . '">';
		$forum_select_list .= '<option value="0">' . $lang['All_available'] . '</option>';
		for($i = 0; $i < count($forum_rows); $i++)
		{
			$forum_select_list .= '<option value="' . (int) $forum_rows[$i]['forum_id'] . '">' . htmlspecialchars((string) $forum_rows[$i]['forum_name'], ENT_QUOTES, 'UTF-8') . '</option>';
		}
		$forum_select_list .= '</select>';
		$forum_select_list = str_replace("value=\"".$banner_info['banner_forum']."\">", "value=\"".$banner_info['banner_forum']."\" SELECTED>*" ,$forum_select_list);
		$banner_size = ($banner_info['banner_width'] && $banner_info['banner_height']) ? 'width="' . (int) $banner_info['banner_width'] . '" height="' . (int) $banner_info['banner_height'] . '"' : '';
		$safe_banner_name = htmlspecialchars((string) $banner_info['banner_name'], ENT_QUOTES, 'UTF-8');
		$safe_banner_description = htmlspecialchars((string) $banner_info['banner_description'], ENT_QUOTES, 'UTF-8');
		switch ($banner_info['banner_type'])
		{
			case 6 :
				// swf
				$banner_example = '<script type="text/javascript" src="../assets/ruffle/phpbb-config.js"></script><script type="text/javascript" src="../assets/ruffle/ruffle.js"></script><object type="application/x-shockwave-flash" data="'.$safe_banner_name.'" '.$banner_size.'><param name="movie" value="'.$safe_banner_name.'" /><param name="allowScriptAccess" value="never" /><a href="'.append_sid('redirect.'.$phpEx.'?banner_id='.(int) $banner_info['banner_id']).'" target="_blank" rel="noopener noreferrer">'.$safe_banner_description.'</a></object>';
				break;
			case 4 :
				// custom
				$banner_example = $banner_info['banner_name'];
				break;
			case 2 :
				$banner_example = '<a href="'.append_sid('redirect.'.$phpEx.'?banner_id='.(int) $banner_info['banner_id']).'" target="_blank" rel="noopener noreferrer">'.$safe_banner_name.'</a>';
				break;
			case 0 :
			default:
				$banner_example = '<a href="'.append_sid('redirect.'.$phpEx.'?banner_id='.(int) $banner_info['banner_id']).'" target="_blank" rel="noopener noreferrer"><img src="'.$safe_banner_name.'" '.$banner_size.' border="0" alt="'.$safe_banner_description.'" title="'.$safe_banner_description.'" /></a>';
		}


		$template->assign_vars(array(
			'L_BANNER_TITLE' => $lang['Banner_title'],
			'L_BANNER_TEXT' => $lang['Banner_add_text'],
			'L_BANNER_ACTIVATE' => $lang['Banner_activate'],
			'BANNER_NOT_ACTIVE' => $banner_is_not_active,
			'BANNER_ACTIVE' => $banner_is_active,
'L_BANNER_TYPE' => $lang['Banner_type_text'],
'BANNER_TYPE' => selection($banner_info['banner_type'], 'Banner_type'),

			'L_BANNER_NAME' => $lang['Banner_name'],
			'L_BANNER_NAME_EXPLAIN' =>$lang['Banner_name_explain'],
			'BANNER_NAME' => $safe_banner_name,
'L_BANNER_EXAMPLE' => $lang['Banner_example'],
'L_BANNER_EXAMPLE_EXPLAIN' => $lang['Banner_example_explain'],

'BANNER_EXAMPLE' => $banner_example,
'U_BANNER' => preg_match('#^https?://#i', (string) $banner_info['banner_name']) ? $safe_banner_name : '../' . $safe_banner_name,
			'L_BANNER_DESCRIPTION' => $lang['Banner_description'],
			'L_BANNER_DESCRIPTION_EXPLAIN' => $lang['Banner_description_explain'],
			'BANNER_DESCRIPTION' => $safe_banner_description,
	'L_BANNER_SIZE' => $lang['Banner_size'],
'L_BANNER_SIZE_EXPLAIN' => $lang['Banner_size_explain'],
	'L_BANNER_HEIGHT' => $lang['Banner_height'],
	'L_BANNER_WIDTH' => $lang['Banner_width'],
	'BANNER_WIDTH' => $banner_info['banner_width'],
	'BANNER_HEIGHT' => $banner_info['banner_height'],


	'L_BANNER_FILTER' => $lang['Banner_filter'],
	'L_BANNER_FILTER_EXPLAIN' => $lang['Banner_filter_explain'],
	'BANNER_FILTER_YES' => ($banner_info['banner_filter']) ? 'checked="checked"' : '',
	'BANNER_FILTER_NO' => ($banner_info['banner_filter']) ? '' : 'checked="checked"',
	'L_BANNER_FILTER_TIME' => $lang['Banner_filter_time'],
	'L_BANNER_FILTER_TIME_EXPLAIN' => $lang['Banner_filter_time_explain'],
	'BANNER_FILTER_TIME' => $banner_info['banner_filter_time'],

			'L_BANNER_CLICK' => $lang['Banner_clicks'],
'L_BANNER_CLICK_EXPLAIN' => $lang['Banner_clicks_explain'],
			'BANNER_CLICK' => $banner_info['banner_click'],
			'L_BANNER_VIEW' => $lang['Banner_view'],
			'BANNER_VIEW' => $banner_info['banner_view'],
			'L_BANNER_COMMENT' => $lang['Banner_comment'],
'L_BANNER_COMMENT_EXPLAIN' => $lang['Banner_comment_explain'],
			'BANNER_COMMENT' => htmlspecialchars((string) $banner_info['banner_comment'], ENT_QUOTES, 'UTF-8'),
			'L_BANNER_URL' => $lang['Banner_url'],
			'L_BANNER_URL_EXPLAIN' => $lang['Banner_url_explain'],
			'BANNER_URL' => htmlspecialchars((string) $banner_info['banner_url'], ENT_QUOTES, 'UTF-8'),
			'L_BANNER_OWNER' => $lang['Banner_owner'],
			'L_BANNER_OWNER_EXPLAIN' => $lang['Banner_owner_explain'],
			'BANNER_OWNER' => htmlspecialchars((string) $owner['username'], ENT_QUOTES, 'UTF-8'),
			'U_SEARCH_USER' => append_sid("./../search.$phpEx?mode=searchuser"),
			'L_FIND_USERNAME' => $lang['Find_username'],

			'L_BANNER_WEIGTH' => $lang['Banner_weigth'],
			'L_BANNER_WEIGTH_EXPLAIN' => $lang['Banner_weigth_explain'],
			'BANNER_WEIGTH' => $banner_info['banner_weigth'],
			'L_BANNER_SPOT' => $lang['Banner_placement'],
			'S_BANNER_SPOT' => $s_banner_spot,
'S_BANNER_FORUM' => $forum_select_list,
			'L_BANNER_SHOW_TO' => $lang['Show_to_users'],
			'L_BANNER_SHOW_TO_EXPLAIN' => $lang['Show_to_users_explain'],
			'S_BANNER_SHOW_TO' => sprintf($lang['Show_to_users_select'],$s_level_type,$s_level),
			'C_NO_TIME' => $c_no_time,
			'C_BY_TIME' => $c_by_time,
			'C_BY_WEEK' => $c_by_week,
			'C_BY_DATE' => $c_by_date,
			'L_RULE_TYPE' => $rule_type,
			'RULE_BEGIN' => $rule_begin,
			'RULE_END' => $rule_end,
			'L_START' => $lang['Start'],
			'L_END' => $lang['End'],
			'L_YEAR' => $lang['Year'],
			'L_MONTH' => $lang['Month'],
			'L_DATE' => $lang['Date'],
			'L_WEEKDAY' => $lang['Weekday'],
			'L_HOURS' => $lang['Hours'],
			'L_MIN' => $lang['Min'],
			'S_WEEK_BEGIN' => $s_time_week_begin,
			'S_WEEK_END' => $s_time_week_end,
			'S_MIN_BEGIN' => $s_time_min_begin,
			'S_MIN_END' => $s_time_min_end,
			'S_HOURS_BEGIN' => $s_time_hours_begin,
			'S_HOURS_END' => $s_time_hours_end,
			'S_DATE_BEGIN' => $s_time_date_begin,
			'S_DATE_END' => $s_time_date_end,
			'S_MONTHS_BEGIN' => $s_time_months_begin,
			'S_MONTHS_END' => $s_time_months_end,
			'S_YEAR_BEGIN' => $s_time_year_begin,
			'S_YEAR_END' => $s_time_year_end,

			'L_TIME_INTERVAL' => $lang['Time_interval'] ,
			'L_TIME_INTERVAL_EXPLAIN' => $lang['Time_interval_explain'],
			'L_TIME_SELECT' => $lang['Time_select'],
			'L_TIME_TYPE' => $lang['Time_type'],
			'L_TIME_TYPE_EXPLAIN' => $lang['Time_type_explain'],
			'L_TIME_NO' => $lang['No_time'],
			'L_TIME_TIME' => $lang['By_time'],
			'L_TIME_WEEK' => $lang['By_week'],
			'L_TIME_DATE' => $lang['By_date'],
			'L_SUBMIT' => $lang['Submit'],
			'L_RESET' => $lang['Reset'],
			'L_YES' => $lang['Yes'],
			'L_NO' => $lang['No'],

			'S_BANNER_ACTION' => append_sid("admin_banner.$phpEx"),
			'S_HIDDEN_FIELDS' => $s_hidden_fields)
		);
	}
	else if( $mode == "save" )
	{
		phpbb_admin_require_post_session();
		//
		// Ok, they sent us our info, let's update it.
		//
		$banner_id = max(0, (int) admin_banner_post_scalar('id', '0'));
		$banner_active = admin_banner_post_scalar('banner_active', '0') === '1' ? 1 : 0;
		$banner_filter = admin_banner_post_scalar('banner_filter', '0') === '1' ? 1 : 0;
		$banner_filter_time = max(0, (int) admin_banner_post_scalar('banner_filter_time', '0'));

		$banner_type = (int) admin_banner_post_scalar('banner_type', '0');
		$banner_name = substr(trim(admin_banner_post_scalar('banner_name', '')), 0, 255);
		$banner_description = substr(trim(admin_banner_post_scalar('banner_description', '')), 0, 255);
		$banner_width = max(0, (int) admin_banner_post_scalar('banner_width', '0'));
		$banner_height = max(0, (int) admin_banner_post_scalar('banner_height', '0'));
		$banner_click = max(0, (int) admin_banner_post_scalar('banner_click', '0'));
		$banner_view = max(0, (int) admin_banner_post_scalar('banner_view', '0'));
		$banner_url = substr(trim(admin_banner_post_scalar('banner_url', '')), 0, 255);
		$banner_owner = substr(trim(admin_banner_post_scalar('username', '')), 0, 255);
		$banner_spot = (int) admin_banner_post_scalar('banner_spot', '0');
		$banner_forum = max(0, (int) admin_banner_post_scalar(POST_FORUM_URL, '0'));

		$banner_weigth = max(0, (int) admin_banner_post_scalar('banner_weigth', '0'));
		$banner_level = (int) admin_banner_post_scalar('banner_level', '-1');
		$banner_level_type = (int) admin_banner_post_scalar('banner_level_type', '0');

		$time_type = (int) admin_banner_post_scalar('time_type', '0');
		if (!in_array($time_type, array(0, 2, 4, 6), true))
		{
			$time_type = 0;
		}
		$date_begin_week = max(0, min(6, (int) admin_banner_post_scalar('date_begin_week', '0')));
		$date_end_week = max(0, min(6, (int) admin_banner_post_scalar('date_end_week', '0')));
		$date_begin_day = max(0, min(31, (int) admin_banner_post_scalar('date_begin_day', '0')));
		$date_end_day = max(0, min(31, (int) admin_banner_post_scalar('date_end_day', '0')));
		$date_begin_year = max(0, min(9999, (int) admin_banner_post_scalar('date_begin_year', '0')));
		$date_begin_month = max(0, min(12, (int) admin_banner_post_scalar('date_begin_month', '0')));
		$date_end_year = max(0, min(9999, (int) admin_banner_post_scalar('date_end_year', '0')));
		$date_end_month = max(0, min(12, (int) admin_banner_post_scalar('date_end_month', '0')));
		$time_begin_hour = max(0, min(23, (int) admin_banner_post_scalar('time_begin_hour', '0')));
		$time_begin_min = max(0, min(59, (int) admin_banner_post_scalar('time_begin_min', '0')));
		$time_end_hour = max(0, min(23, (int) admin_banner_post_scalar('time_end_hour', '0')));
		$time_end_min = max(0, min(59, (int) admin_banner_post_scalar('time_end_min', '0')));

		switch ($time_type)
		{
		case 0: 	$time_begin=0;$time_end=0;
			$date_begin=0;$date_end=0;break;
		case 2:	$date_begin = 0;$date_end = 0;
			$time_begin = $time_begin_hour.$time_begin_min;
			$time_end = $time_end_hour.$time_end_min;
			break;
		case 4 :	$time_begin = $time_begin_hour.$time_begin_min;
			$time_end = $time_end_hour.$time_end_min;
			$date_begin = $date_begin_week;
			$date_end = $date_end_week;
			break;
		case 6 :	$time_begin = $time_begin_hour.$time_begin_min;
			$time_end = $time_end_hour.$time_end_min;
			$date_begin = $date_begin_year.$date_begin_month.$date_begin_day;
			$date_end = $date_end_year.$date_end_month.$date_end_day;
			if (!$date_begin_year || !$date_begin_month || !$date_begin_day || !$date_end_year || !$date_end_month || !$date_end_day)
			{
				message_die(GENERAL_MESSAGE, $lang['Missing_date']);
			}break;
		}
		$banner_comment = substr(trim(admin_banner_post_scalar('banner_comment', '')), 0, 255);
		// verify the inputs
		if( $banner_name == "" )
		{
			message_die(GENERAL_MESSAGE, $lang['Missing_banner_name']);
		}
		$owner = get_userdata($banner_owner);
		if (!is_array($owner) || empty($owner['user_id']))
		{
			message_die(GENERAL_MESSAGE, $lang['Missing_banner_owner']);
		}
		$banner_name_sql = $db->sql_escape($banner_name);
		$banner_description_sql = $db->sql_escape($banner_description);
		$banner_url_sql = $db->sql_escape($banner_url);
		$banner_comment_sql = $db->sql_escape($banner_comment);
		$banner_owner_id = (int) $owner['user_id'];
		if( !empty($banner_id) )
		{
			$sql = "UPDATE " . BANNERS_TABLE . "
				SET 	banner_active = $banner_active, banner_name = '$banner_name_sql',
					banner_description = '$banner_description_sql',	banner_click = $banner_click,	banner_view = $banner_view,
					banner_url = '$banner_url_sql', banner_owner = $banner_owner_id,
					banner_type = '$banner_type', banner_width = '$banner_width', banner_height = '$banner_height',
					banner_filter = '$banner_filter',banner_filter_time='$banner_filter_time',
					banner_spot = $banner_spot, banner_forum= $banner_forum, banner_weigth = $banner_weigth,
					banner_level = '$banner_level', banner_level_type = '$banner_level_type', banner_timetype = $time_type,
					date_begin=$date_begin, date_end=$date_end, time_begin=$time_begin, time_end=$time_end,
					banner_comment='$banner_comment_sql'	WHERE banner_id = $banner_id";
			$message = $lang['Banner_updated'];
		}
		else
		{
			$sql = "SELECT MAX(banner_id) as banner_id FROM " . BANNERS_TABLE;
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, "Couldn't obtain banner id data", "", __LINE__, __FILE__, $sql);
			}
			$banner_nr = $db->sql_fetchrow($result);
			$banner_id = (int) $banner_nr['banner_id'] + 1;
			$sql = "INSERT INTO " . BANNERS_TABLE . " (banner_id, banner_name, banner_active, banner_spot, banner_description, banner_url, banner_click, banner_view, banner_owner, banner_level, banner_level_type, banner_timetype, time_begin, time_end, date_begin, date_end, banner_comment, banner_type, banner_width, banner_height, banner_filter, banner_filter_time, banner_weigth)
				VALUES ($banner_id, '$banner_name_sql', $banner_active, $banner_spot, '$banner_description_sql', '$banner_url_sql', $banner_click, $banner_view, $banner_owner_id, $banner_level, $banner_level_type, $time_type, $time_begin, $time_end, $date_begin, $date_end, '$banner_comment_sql', $banner_type, $banner_width, $banner_height, $banner_filter, $banner_filter_time, $banner_weigth)";
			$message = $lang['Banner_added'];
		}
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, "Couldn't update/insert into banners table", "", __LINE__, __FILE__, $sql);
		}
		$message .= "<br /><br />" . sprintf($lang['Click_return_banneradmin'], "<a href=\"" . append_sid("admin_banner.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message);
	}
	else if( $mode == "delete" )
	{
		//
		// Ok, they lets delete the selected banner
		//

		if( isset($_POST['id']) || isset($_GET['id']) )
		{
			$banner_id_value = isset($_POST['id']) ? $_POST['id'] : $_GET['id'];
			$banner_id = is_scalar($banner_id_value) ? max(0, (int) $banner_id_value) : 0;
		}
		else
		{
			$banner_id = '';
		}

		$confirmed = isset($_POST['confirm']);
		if (isset($_POST['cancel']))
		{
			redirect(append_sid("admin_banner.$phpEx"));
		}

		if( !empty($banner_id) && $confirmed )
		{
			if (!isset($_POST['id']) || !is_scalar($_POST['id']) || (int) $_POST['id'] <= 0)
			{
				message_die(GENERAL_MESSAGE, $lang['Missing_banner_id']);
			}
			$banner_id = (int) $_POST['id'];
			phpbb_admin_require_post_session();
			$sql = "DELETE FROM " . BANNERS_TABLE . "
				WHERE banner_id = $banner_id";

			if( !$result = $db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, "Couldn't delete banner data", "", __LINE__, __FILE__, $sql);
			}
			$message = $lang['Banner_removed'] . "<br /><br />" . sprintf($lang['Click_return_banneradmin'], "<a href=\"" . append_sid("admin_banner.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
			message_die(GENERAL_MESSAGE, $message);
		}
		else if( !empty($banner_id) )
		{
			$template->set_filenames(array(
				'body' => 'admin/confirm_body.tpl')
			);

			$hidden_fields = '<input type="hidden" name="mode" value="delete" />' .
				'<input type="hidden" name="id" value="' . $banner_id . '" />' . phpbb_admin_session_field();

			$template->assign_vars(array(
				'MESSAGE_TITLE' => $lang['Confirm'],
				'MESSAGE_TEXT' => $lang['Confirm_delete_banner'],
				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],
				'S_CONFIRM_ACTION' => append_sid("admin_banner.$phpEx"),
				'S_HIDDEN_FIELDS' => $hidden_fields)
			);
		}
		else
		{
			message_die(GENERAL_MESSAGE, $lang['Missing_banner_id']);
		}
	} else
	{
		message_die(GENERAL_ERROR, 'Error illigal mode specifyed');
	}
}
else
{
//
// Show the default page
//
$template->set_filenames(array(
	"body" => "admin/banner_list_body.tpl"));
	$sql = "SELECT * FROM " . BANNERS_TABLE ." order by banner_spot";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, "Couldn't obtain ranks data", "", __LINE__, __FILE__, $sql);
	}
	$banners_count = $db->sql_numrows($result);
	$banners_rows = $db->sql_fetchrowset($result);
	$template->assign_vars(array(
		"L_BANNER_TITLE" => $lang['Banner_title'],
		"L_BANNER_TEXT" => $lang['Banner_text'],
		"L_BANNER_DESCRIPTION" => $lang['Banner_description'],
		"L_BANNER_ACTIVATED" => $lang['Banner_activated'],
		"L_TIME_TYPE" => $lang['Time_type'],
		"L_BANNER_NAME" => $lang['Banner_name'],
		"L_BANNER_COMMENT" => $lang['Banner_comment'],
		"L_BANNER_CLICKS" => $lang['Banner_clicks'],
		"L_BANNER_VIEW" => $lang['Banner_view'],
		"L_BANNER_SPOT" => $lang['Banner_placement'],
		"L_EDIT" => $lang['Edit'],
		"L_DELETE" => $lang['Delete'],
		"L_ADD_BANNER" => $lang['Add_new_banner'],
		"L_ACTION" => $lang['Action'],

		"S_BANNER_ACTION" => append_sid("admin_banner.$phpEx"))
	);

	for($i = 0; $i < $banners_count; $i++)
	{
		$banner_name = htmlspecialchars((string) $banners_rows[$i]['banner_name'], ENT_QUOTES, 'UTF-8');
		$banner_id = (int) $banners_rows[$i]['banner_id'];
		$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
		$banner_is_active = ( $banners_rows[$i]['banner_active'] ) ? $lang['Yes'] : $lang['No'];
		switch ($banners_rows[$i]['banner_timetype'])
		{
			case 0: $rule_type=$lang['No_time'];
					$rule_begin = '';
					$rule_end = '';break;
			case 2:	$rule_type=$lang['By_time'].'</br>';
					$rule_begin = sprintf("%04d",$banners_rows[$i]['time_begin']).'</br>';
					$rule_end = sprintf("%04d",$banners_rows[$i]['time_end']);break;
			case 4 :	$rule_type=$lang['By_week'].'</br>';
					$day_array = array('Sun','Mon','Tue','Wed','Thu','Fri','Sat');
					$begin_day = max(0, min(6, (int) $banners_rows[$i]['date_begin']));
					$end_day = max(0, min(6, (int) $banners_rows[$i]['date_end']));
					$rule_begin = $lang['datetime'][$day_array[$begin_day]].', '.sprintf("%04d",$banners_rows[$i]['time_begin']).'</br>';
					$rule_end = $lang['datetime'][$day_array[$end_day]].', '.sprintf("%04d",$banners_rows[$i]['time_end']);break;
			case 6:	$rule_type=$lang['By_date'].'</br>';
					$rule_begin = $banners_rows[$i]['date_begin'].', '.sprintf("%04d",$banners_rows[$i]['time_begin']).'</br>';
					$rule_end = $banners_rows[$i]['date_end'].', '.sprintf("%04d",$banners_rows[$i]['time_end']);break;
		default:		$rule_type=$lang['Not_specify'];
		}
		$template->assign_block_vars("banners", array(
			'ROW_COLOR' => "#" . $row_color,
			'ROW_CLASS' => $row_class,
			'BANNER_DESCRIPTION' => htmlspecialchars((string) $banners_rows[$i]['banner_description'], ENT_QUOTES, 'UTF-8'),
			'BANNER_IS_ACTIVE' => $banner_is_active,
			'BANNER_NAME' => $banner_name,
			'BANNER_CLICKS' => $banners_rows[$i]['banner_click'],
			'BANNER_VIEW' => $banners_rows[$i]['banner_view'],
			'BANNER_COMMENT' => htmlspecialchars((string) $banners_rows[$i]['banner_comment'], ENT_QUOTES, 'UTF-8'),
			'BANNER_SPOT' => isset($lang['Banner_spot'][$banners_rows[$i]['banner_spot']]) ? htmlspecialchars((string) $lang['Banner_spot'][$banners_rows[$i]['banner_spot']], ENT_QUOTES, 'UTF-8') : '',
			'BANNER_ID' => $banner_id,
			'L_RULE_TYPE' => $rule_type,
			'RULE_BEGIN' => $rule_begin,
			'RULE_END' => $rule_end,
			'U_BANNER_EDIT' => append_sid("admin_banner.$phpEx?mode=edit&amp;id=$banner_id"),
			'U_BANNER_DELETE' => append_sid("admin_banner.$phpEx?mode=delete&amp;id=$banner_id"))
		);
	}
}

$template->pparse("body");

include('./page_footer_admin.'.$phpEx);

?>
