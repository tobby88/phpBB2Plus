<?php
/***************************************************************************
 *                                common.php
 *                            -------------------
 *   begin                : Saturday, Feb 23, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: common.php,v 1.74.2.10 2003/06/04 17:41:39 acydburn Exp $
 *
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

// Keep runtime diagnostics out of HTML responses while retaining a local log.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', dirname(__FILE__) . '/logs/php_errors.log');

include_once($phpbb_root_path . 'includes/php_compat.' . $phpEx);

if (!headers_sent())
{
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: SAMEORIGIN');
	header('Referrer-Policy: strict-origin-when-cross-origin');
	header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
	header("Content-Security-Policy: base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'self' blob: data:");
}

//-- mod : run stats -----------------------------------------------------------
//-- add
$starttime = microtime();
$trc_loc_start = $trc_loc_end = 0;
//-- fin mod : run stats -------------------------------------------------------

//
error_reporting  (E_ERROR | E_WARNING | E_PARSE); // This will NOT report uninitialized variables

// The following code (unsetting globals)
// Thanks to Matt Kavanagh and Stefan Esser for providing feedback as well as patch files

// PHP5+ with register_long_arrays off?
if (version_compare(PHP_VERSION, '5.0.0', '>=') && (!@ini_get('register_long_arrays') || @ini_get('register_long_arrays') == '0' || strtolower(@ini_get('register_long_arrays')) == 'off'))
{
	$HTTP_POST_VARS = $_POST;
	$HTTP_GET_VARS = $_GET;
	$HTTP_SERVER_VARS = $_SERVER;
	$HTTP_COOKIE_VARS = $_COOKIE;
	$HTTP_ENV_VARS = $_ENV;
	$HTTP_POST_FILES = $_FILES;

	// _SESSION is the only superglobal which is conditionally set
	if (isset($_SESSION))
	{
		$HTTP_SESSION_VARS = $_SESSION;
	}
}

// CrackerTracker v5.x
include($phpbb_root_path . 'ctracker/engines/ct_security.' . $phpEx);

// Protect against GLOBALS tricks
if (isset($HTTP_POST_VARS['GLOBALS']) || isset($HTTP_POST_FILES['GLOBALS']) || isset($HTTP_GET_VARS['GLOBALS']) || isset($HTTP_COOKIE_VARS['GLOBALS']))
{
	die("Hacking attempt");
}

// Protect against HTTP_SESSION_VARS tricks
if (isset($HTTP_SESSION_VARS) && !is_array($HTTP_SESSION_VARS))
{
	die("Hacking attempt");
}

if (@ini_get('register_globals') == '1' || strtolower(@ini_get('register_globals')) == 'on')
{
	// PHP4+ path
	$not_unset = array('HTTP_GET_VARS', 'HTTP_POST_VARS', 'HTTP_COOKIE_VARS', 'HTTP_SERVER_VARS', 'HTTP_SESSION_VARS', 'HTTP_ENV_VARS', 'HTTP_POST_FILES', 'phpEx', 'phpbb_root_path');
	// Not only will array_merge give a warning if a parameter
	// is not an array, it will actually fail. So we check if
	// HTTP_SESSION_VARS has been initialised.
	if (!isset($HTTP_SESSION_VARS) || !is_array($HTTP_SESSION_VARS))
	{
		$HTTP_SESSION_VARS = array();
	}

	// Merge all into one extremely huge array; unset
	// this later
	$input = array_merge($HTTP_GET_VARS, $HTTP_POST_VARS, $HTTP_COOKIE_VARS, $HTTP_SERVER_VARS, $HTTP_SESSION_VARS, $HTTP_ENV_VARS, $HTTP_POST_FILES);

	unset($input['input']);
	unset($input['not_unset']);

	foreach ($input as $var => $value)
	{
		if (in_array($var, $not_unset))
		{
			die('Hacking attempt!');
		}
		unset($$var);
	} 
   
	unset($input);
}

// phpBB2's SQL layer predates prepared statements and expects request values
// to have magic-quotes-style escaping. Apply it recursively, then synchronize
// both the modern superglobals and the legacy aliases actually used by most
// modules. $_REQUEST is a separate startup-time copy and must be covered too.
$_GET = phpbb_addslashes_recursive($_GET);
$_POST = phpbb_addslashes_recursive($_POST);
$_COOKIE = phpbb_addslashes_recursive($_COOKIE);
$_REQUEST = phpbb_addslashes_recursive($_REQUEST);
$HTTP_GET_VARS = $_GET;
$HTTP_POST_VARS = $_POST;
$HTTP_COOKIE_VARS = $_COOKIE;

//
// Define some basic configuration arrays this also prevents
// malicious rewriting of language and otherarray values via
// URI params
//
$board_config = array();
$plus_config = array();
$userdata = array();
$theme = array();
$images = array();
$lang = array();
$nav_links = array();
$dss_seeded = false;
$gen_simple_header = FALSE;

include($phpbb_root_path . 'config.'.$phpEx);

if( !defined("PHPBB_INSTALLED") )
{
	header('Location: ' . $phpbb_root_path . 'install/install.' . $phpEx);
	exit;
}

include($phpbb_root_path . 'includes/constants.'.$phpEx);
include_once($phpbb_root_path . 'includes/template.'.$phpEx);
include($phpbb_root_path . 'includes/sessions.'.$phpEx);
include($phpbb_root_path . 'includes/auth.'.$phpEx);
include_once( $phpbb_root_path . './includes/functions_categories_hierarchy.' . $phpEx );
include($phpbb_root_path . 'includes/functions.'.$phpEx);
include($phpbb_root_path . 'includes/db.'.$phpEx);

// We do not need this any longer, unset for safety purposes
unset($dbpasswd);

//
// Obtain and encode users IP
//
// I'm removing HTTP_X_FORWARDED_FOR ... this may well cause other problems such as
// private range IP's appearing instead of the guilty routable IP, tough, don't
// even bother complaining ... go scream and shout at the idiots out there who feel
// "clever" is doing harm rather than good ... karma is a great thing ... :)
//
$client_ip = ( !empty($HTTP_SERVER_VARS['REMOTE_ADDR']) ) ? $HTTP_SERVER_VARS['REMOTE_ADDR'] : ( ( !empty($HTTP_ENV_VARS['REMOTE_ADDR']) ) ? $HTTP_ENV_VARS['REMOTE_ADDR'] : getenv('REMOTE_ADDR') );
$user_ip = encode_ip($client_ip);

// CrackerTracker v5.x
include($phpbb_root_path . 'ctracker/engines/ct_varsetter.' . $phpEx);
include($phpbb_root_path . 'ctracker/engines/ct_request_limiter.' . $phpEx);
include($phpbb_root_path . 'ctracker/engines/ct_ipblocker.' . $phpEx);

// cache configs -----------------
$cache_dir = $phpbb_root_path . 'cache';
$cache_config = $cache_dir . '/config_data.cache';
define('CCache', true);

if (@file_exists($cache_config) && defined('CCache'))
{
	$config_cache = phpbb_data_cache_read($cache_config);
	if (is_array($config_cache) && isset($config_cache['board'], $config_cache['plus']) &&
		is_array($config_cache['board']) && is_array($config_cache['plus']))
	{
		$board_config = $config_cache['board'];
		$plus_config = $config_cache['plus'];
	}
}
// cache configs -----------------

//
// Setup forum wide options, if this fails
// then we output a CRITICAL_ERROR since
// basic forum information is not available
//
// cache configs -----------------
if (empty($board_config['config_id']))
{
	// is /cache/ useable 
	$use_cache = (is_writable($cache_dir) && defined('CCache') && !defined('IN_ADMIN') ) ? true : false;

	// Boardconfig -----------------
	$sql = "SELECT *
		FROM " . CONFIG_TABLE;
	if( !($result = $db->sql_query($sql)) )
	{
		message_die(CRITICAL_ERROR, "Could not query config information", "", __LINE__, __FILE__, $sql);
	}

	while ( $row = $db->sql_fetchrow($result) )
	{
		$board_config[$row['config_name']] = $row['config_value'];
	}
	// Boardconfig -----------------
	
	// PLUSconfig -----------------
	$sql = "SELECT *
		FROM " . PLUS_TABLE;
	if( !($result = $db->sql_query($sql)) )
	{
		message_die(CRITICAL_ERROR, "Could not query Plus-Config information", "", __LINE__, __FILE__, $sql);
	}
	
	while ( $row = $db->sql_fetchrow($result) )
	{
		$plus_config[$row['config_name']] = $row['config_value'];
	}
	// PLUSconfig -----------------
	
	$db->sql_freeresult($result);

	if ($use_cache)
	{
		phpbb_data_cache_write($cache_config, array(
			'board' => $board_config,
			'plus' => $plus_config,
		));
	}

	// \:cls 
	unset($config_cache, $cache_config, $use_cache);
}
/*
else {
	$sql = "SELECT * FROM " . CONFIG_TABLE . " WHERE config_name 
			IN (xs_template_time, )";
	if( !($result = $db->sql_query($sql)) ) {
		message_die(CRITICAL_ERROR, "Could not query config information", "", __LINE__, __FILE__, $sql);
	}
	while ( $row = $db->sql_fetchrow($result) ) {
		$board_config[$row['config_name']] = $row['config_value'];
	}
	$db->sql_freeresult($result);
}
*/
// cache configs -----------------

if (!phpbb_request_origin_is_valid())
{
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	exit('Cross-site request rejected.');
}

include($phpbb_root_path . 'attach_mod/attachment_mod.'.$phpEx);

if (file_exists('install') || file_exists('contrib'))
{
	@unlink($phpbb_root_path . 'cache/config_data.cache');
	message_die(GENERAL_MESSAGE, 'Please_remove_install_contrib');
}

//
// Show 'Board is disabled' message if needed.
//
if( $board_config['board_disable'] && !defined("IN_ADMIN") && !defined("IN_LOGIN") )
{
	if ( $board_config['board_disable_msg'] != "" )
	{
		message_die(GENERAL_MESSAGE, $board_config['board_disable_msg'], 'Information');
	}
	else
	{
		message_die(GENERAL_MESSAGE, 'Board_disable', 'Information');
	}
}

?>
