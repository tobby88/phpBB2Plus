<?php
/***************************************************************************
 *                              rewards_api.php
 *                            -------------------
 *   begin                : Friday, Dec 8th, 2006
 *   copyright            : (C) 2003 Xore, Napoleon, dEfEndEr
 *   email                : mods@xore.ca, admin@phpbb-arcade.com
 *
 *   $Id: rewards_api.php,v 2.1.6 2006/12/08 23:59:59 dEfEndEr $
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

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}
//
// give rewards to the user
//
function add_reward($user_id,$amount)
{
	global $userdata, $db;
	$user_id = (int) $user_id;
	$amount = phpbb_reward_number($amount);
	$dbfield = get_db_reward();
	if ( $userdata['user_id'] == $user_id )
	{
		$userdata[$dbfield] += $amount;
	}
	$sql = "UPDATE " . USERS_TABLE . "
		SET `$dbfield` = `$dbfield` + $amount
		WHERE user_id = $user_id";
	if ( !$db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, "Failed to add rewards", "", __LINE__, __FILE__, $sql);
	}
}
//
// subdtract rewards from the user
//
function subtract_reward($user_id,$amount)
{
	global $userdata, $db;
	$user_id = (int) $user_id;
	$amount = phpbb_reward_number($amount);
	$dbfield = get_db_reward();
	if ( $userdata['user_id'] == $user_id )
	{
		$userdata[$dbfield] -= $amount;
	}
	$sql = "UPDATE " . USERS_TABLE . "
		SET `$dbfield` = `$dbfield` - $amount
		WHERE user_id = $user_id";
	if ( !$db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, "Failed to subtract rewards", "", __LINE__, __FILE__, $sql);
	}
}
//
// set the user's rewards
//
function set_reward($user_id,$amount)
{
	global $userdata, $db;
	$user_id = (int) $user_id;
	$amount = phpbb_reward_number($amount);
	$dbfield = get_db_reward();
	if ( $userdata['user_id'] == $user_id )
	{
		$userdata[$dbfield] = $amount;
	}
	$sql = "UPDATE " . USERS_TABLE . "
		SET `$dbfield` = $amount
		WHERE user_id = $user_id";
	if ( !$db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, "Failed to set rewards", "", __LINE__, __FILE__, $sql);
	}
}
//
// get the user's reward amounts
//
function get_reward($user_id)
{
	global $userdata, $db;
	$user_id = (int) $user_id;
	$dbfield = get_db_reward();
	if ( $userdata['user_id'] == $user_id )
	{
		return $userdata[$dbfield];
	}
	else
	{
		$sql = "SELECT `$dbfield`
			FROM " . USERS_TABLE . "
			WHERE user_id = $user_id";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, "Failed to get rewards", "", __LINE__, __FILE__, $sql);
		}
		if ( !($row = $db->sql_fetchrow($result)) )
		{
			message_die(GENERAL_ERROR, "Bad user_id or default reward column", "", __LINE__, __FILE__);
		}
		return $row[$dbfield];
	}
}
//
// check if user has $amount minimum rewards
//
function has_reward($user_id, $amount)
{
	$users_amount = get_reward($user_id);
	return ($users_amount >= $amount);
}
//
// Get the rewards dbfield (API-internal function)
//
function get_db_reward()
{
	// All rewards mods must store their default database field in the config table ...
	// 'default_reward_dbfield'
	global $board_config, $arcade;
	
	if(!empty($board_config['default_reward_dbfield']))
	{
		return phpbb_reward_field_name($board_config['default_reward_dbfield']);
  }
  else
  {
		return phpbb_reward_field_name($arcade->arcade_config['default_cash']);
  }
}

function phpbb_reward_field_name($field)
{
	$field = (string) $field;
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $field))
	{
		message_die(GENERAL_ERROR, 'Invalid reward database field configuration');
	}
	return $field;
}

function phpbb_reward_number($amount)
{
	$amount = (float) $amount;
	if (!is_finite($amount))
	{
		return '0';
	}
	return rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
}
//
//  New function to get the cash system name from the db filked entered in the Arcade config
//
function get_cash_name($field = null)
{
  global $table_prefix, $db, $arcade, $lang;
  $fallback=isset($lang['game_points'])?$lang['game_points']:'Points';
  if($field===null){$field=isset($arcade->arcade_config['default_cash'])?$arcade->arcade_config['default_cash']:'';}
  if(!is_string($field)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',$field)||!is_string($table_prefix)||!preg_match('/^[A-Za-z0-9_]{1,42}$/D',$table_prefix)){return $fallback;}
  // Currency modules are optional. A missing module/table/name must not make
  // the Arcade list or an already committed score fail during rendering.
  $result=$db->sql_query('SELECT cash_name FROM '.$table_prefix."cash WHERE cash_dbfield='".$db->sql_escape($field)."' LIMIT 1");
  if(!$result){return $fallback;}
  try{$row=$db->sql_fetchrow($result);}finally{$db->sql_freeresult($result);}
  return $row&&isset($row['cash_name'])&&is_string($row['cash_name'])&&$row['cash_name']!==''?$row['cash_name']:$fallback;
}
