<?php

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);

$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);

$request_method = isset($_SERVER['REQUEST_METHOD']) && is_scalar($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
if ($request_method !== 'POST')
{
	header('Allow: POST');
	http_response_code(405);
	exit;
}

$mode = isset($_POST['mode']) && is_scalar($_POST['mode']) ? (string) $_POST['mode'] : '';
$sid = isset($_POST['sid']) && is_scalar($_POST['sid']) ? (string) $_POST['sid'] : '';
if (!in_array($mode, array('auto', 'mobile', 'desktop'), true) || !hash_equals((string) $userdata['session_id'], $sid))
{
	message_die(GENERAL_ERROR, $lang['Session_invalid']);
}

$cookie_path = isset($board_config['cookie_path']) ? $board_config['cookie_path'] : '/';
$cookie_domain = isset($board_config['cookie_domain']) ? $board_config['cookie_domain'] : '';
$cookie_secure = !empty($board_config['cookie_secure']);
phpbb_setcookie($board_config['cookie_name'] . '_style_mode', $mode, time() + 31536000, $cookie_path, $cookie_domain, $cookie_secure);

$return_url = isset($_POST['return']) && is_scalar($_POST['return']) ? substr((string) $_POST['return'], 0, 2048) : '';
if (!preg_match('/^[A-Za-z0-9_.-]+\.php(?:\?[^\x00-\x20<>]*)?$/D', $return_url))
{
	$return_url = 'portal.' . $phpEx;
}

redirect($return_url);
