<?php
/***************************************************************************
 * Moderation action log (Log Actions MOD 1.1.6 + Enhanced Log Actions)
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
	die('Hacking attempt');
}

function log_action($action, $topic_ids, $user_id = 0, $username = '', $database = null)
{
	global $userdata, $user_ip, $client_ip;
	$db = $database !== null ? $database : $GLOBALS['db'];

	$allowed = array('delete', 'move', 'lock', 'unlock', 'split', 'edit', 'announce', 'sticky', 'normal');
	if (!in_array($action, $allowed, true))
	{
		return false;
	}

	if (!is_array($topic_ids))
	{
		$topic_ids = preg_split('/\s*,\s*/', (string) $topic_ids, -1, PREG_SPLIT_NO_EMPTY);
	}
	$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
	if (empty($topic_ids))
	{
		return false;
	}

	$user_id = $user_id ? intval($user_id) : intval($userdata['user_id']);
	$username = ($username !== '') ? $username : $userdata['username'];
	$username = $db->sql_escape((string) $username);
	$has_client_ip = isset($client_ip) && is_string($client_ip) && filter_var($client_ip, FILTER_VALIDATE_IP) !== false;
	$ip = $has_client_ip ? $client_ip : (isset($user_ip) ? (string) $user_ip : '');
	if (!$has_client_ip && preg_match('/^[0-9a-f]{8}$/i', $ip))
	{
		$ip = decode_ip($ip);
	}
	$ip = $db->sql_escape(substr($ip, 0, 45));
	$now = time();

	foreach ($topic_ids as $topic_id)
	{
		$sql = "INSERT INTO " . LOGS_TABLE . " (mode, topic_id, user_id, username, user_ip, log_time)
			VALUES ('" . $action . "', " . intval($topic_id) . ", $user_id, '$username', '$ip', $now)";
		if (!$db->sql_query($sql))
		{
			return false;
		}
	}
	return true;
}
?>
