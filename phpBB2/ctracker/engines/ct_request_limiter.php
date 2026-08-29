<?php
/**
 * Central, low-false-positive request throttling for expensive write paths.
 *
 * This deliberately runs after the database-backed CrackerTracker settings
 * are loaded but before a page executes plugin-specific code. Limits are per
 * verified REMOTE_ADDR and action class, never derived from spoofable proxy
 * headers. Missing tables fail open so an interrupted update cannot take the
 * forum offline.
 */

if (!defined('IN_PHPBB'))
{
	die('Hacking attempt!');
}

function ctracker_request_limit_profile($script, $post, $get)
{
	$write_scripts = array(
		'posting.php', 'privmsg.php', 'shoutbox.php', 'shoutbox_max.php',
		'album_comment_delete.php', 'album_comment_edit.php', 'album_edit.php',
		'album_hotornot.php', 'arcade_comment.php', 'arcade_rate.php',
		'newscore.php', 'ibproarcade.php', 'pnflashgames.php', 'dload.php',
		'kontakt_post.php', 'tellafriend.php', 'link_register.php', 'ajax.php'
	);

	if ($script === 'login.php' && isset($post['login']))
	{
		return array('login', 600, 'request_limit_login', 30);
	}
	if ($script === 'profile.php' && isset($post['submit']))
	{
		$mode_value = isset($post['mode']) ? $post['mode'] : (isset($get['mode']) ? $get['mode'] : '');
		$mode = is_scalar($mode_value) ? strtolower((string) $mode_value) : '';
		if ($mode === 'register')
		{
			return array('register', 3600, 'request_limit_register', 10);
		}
	}
	if ($script === 'album_upload.php')
	{
		return array('upload', 3600, 'request_limit_upload', 30);
	}
	if (in_array($script, $write_scripts, true))
	{
		return array('write', 60, 'request_limit_write', 120);
	}

	return false;
}

function ctracker_rate_limit_increment($bucket, $identity, $window_seconds, $limit)
{
	global $db;

	$bucket = is_scalar($bucket) ? (string) $bucket : '';
	$identity = is_scalar($identity) ? (string) $identity : '';
	$window_seconds = max(1, min(86400, intval($window_seconds)));
	$limit = max(1, min(10000, intval($limit)));
	if ($bucket === '' || $identity === '')
	{
		return false;
	}

	$now = time();
	$window_start = intval(floor($now / $window_seconds) * $window_seconds);
	$bucket_hash = hash('sha256', $bucket . "\0" . $identity);
	$sql = 'INSERT INTO ' . CTRACKER_RATE_LIMITS . "
		(bucket_hash, window_start, request_count, updated_at)
		VALUES ('" . $db->sql_escape($bucket_hash) . "', $window_start, 1, $now)
		ON DUPLICATE KEY UPDATE
			request_count = IF(window_start = VALUES(window_start), request_count + 1, 1),
			window_start = VALUES(window_start), updated_at = VALUES(updated_at)";
	if (!$db->sql_query($sql))
	{
		return false;
	}

	$sql = 'SELECT request_count FROM ' . CTRACKER_RATE_LIMITS . "
		WHERE bucket_hash = '" . $db->sql_escape($bucket_hash) . "'
			AND window_start = $window_start";
	if (!($result = $db->sql_query($sql)))
	{
		return false;
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if (mt_rand(1, 100) === 1)
	{
		$db->sql_query('DELETE FROM ' . CTRACKER_RATE_LIMITS . ' WHERE updated_at < ' . ($now - 172800));
	}

	return ($row && intval($row['request_count']) > $limit)
		? max(1, ($window_start + $window_seconds) - $now)
		: 0;
}

function ctracker_enforce_request_limit()
{
	global $db, $ctracker_config, $HTTP_SERVER_VARS, $HTTP_POST_VARS, $HTTP_GET_VARS;

	if (empty($ctracker_config->settings['request_limit_enabled']))
	{
		return;
	}

	$method = isset($HTTP_SERVER_VARS['REQUEST_METHOD']) && is_scalar($HTTP_SERVER_VARS['REQUEST_METHOD']) ? strtoupper((string) $HTTP_SERVER_VARS['REQUEST_METHOD']) : '';
	if ($method !== 'POST')
	{
		return;
	}

	$script_value = isset($HTTP_SERVER_VARS['SCRIPT_NAME']) && is_scalar($HTTP_SERVER_VARS['SCRIPT_NAME']) ? (string) $HTTP_SERVER_VARS['SCRIPT_NAME'] : '';
	$script = strtolower(basename(str_replace('\\', '/', $script_value)));
	$profile = ctracker_request_limit_profile(
		$script,
		is_array($HTTP_POST_VARS) ? $HTTP_POST_VARS : array(),
		is_array($HTTP_GET_VARS) ? $HTTP_GET_VARS : array()
	);
	if ($profile === false)
	{
		return;
	}

	list($bucket, $window_seconds, $setting_name, $default_limit) = $profile;
	$configured_limit = isset($ctracker_config->settings[$setting_name]) ? intval($ctracker_config->settings[$setting_name]) : $default_limit;
	$limit = max(1, min(10000, $configured_limit));
	$remote_ip = isset($ctracker_config->user_ip_value) ? (string) $ctracker_config->user_ip_value : '0.0.0.0';
	$retry_after = ctracker_rate_limit_increment($bucket, $remote_ip, $window_seconds, $limit);

	if ($retry_after !== false && $retry_after > 0)
	{
		if (!headers_sent())
		{
			http_response_code(429);
			header('Retry-After: ' . $retry_after);
			header('Cache-Control: no-store');
			header('Content-Type: text/html; charset=UTF-8');
		}
		echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Too many requests</title></head><body>' .
			'<h1>Zu viele Anfragen / Too many requests</h1>' .
			'<p>Bitte warte kurz und versuche es danach erneut. Please wait briefly and try again.</p>' .
			'</body></html>';
		exit;
	}
}

if (!defined('CTRACKER_REQUEST_LIMITER_NO_AUTO_RUN'))
{
	ctracker_enforce_request_limit();
}

?>
