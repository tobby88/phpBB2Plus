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
	if ($script === 'login.php')
	{
		return array('login', 600, 'request_limit_login', 30);
	}
	if ($script === 'profile.php')
	{
		$mode_value = isset($post['mode']) ? $post['mode'] : (isset($get['mode']) ? $get['mode'] : '');
		$mode = is_scalar($mode_value) ? strtolower((string) $mode_value) : '';
		if ($mode === 'register')
		{
			return array('register', 3600, 'request_limit_register', 10);
		}
		if (in_array($mode, array('sendpassword', 'email'), true))
		{
			return array('account', 3600, 'request_limit_account', 20);
		}
	}
	if (in_array($script, array('album_upload.php', 'album_nuffload.php'), true))
	{
		return array('upload', 3600, 'request_limit_upload', 30);
	}
	if (in_array($script, array('change_password.php', 'kontakt_post.php', 'tellafriend.php'), true))
	{
		return array('account', 3600, 'request_limit_account', 20);
	}
	if ($script === 'dload.php')
	{
		$action_value = isset($post['action']) ? $post['action'] : (isset($get['action']) ? $get['action'] : '');
		$action = is_scalar($action_value) ? strtolower((string) $action_value) : '';
		if ($action === 'email')
		{
			return array('account', 3600, 'request_limit_account', 20);
		}
		if ($action === 'user_upload')
		{
			return array('upload', 3600, 'request_limit_upload', 30);
		}
		if (in_array($action, array('post_comment', 'rate'), true))
		{
			return array('content', 300, 'request_limit_content', 60);
		}
	}
	if ($script === 'ajax.php')
	{
		$mode_value = isset($post['mode']) ? $post['mode'] : (isset($get['mode']) ? $get['mode'] : '');
		$mode = is_scalar($mode_value) ? strtolower((string) $mode_value) : '';
		if (in_array($mode, array('edit_post_subject', 'edit_post_text', 'vote_poll', 'watch_topic', 'lock_topic', 'mark_topic', 'mark_forum'), true))
		{
			return array('content', 300, 'request_limit_content', 60);
		}
	}
	$content_scripts = array(
		'posting.php', 'privmsg.php', 'shoutbox.php', 'shoutbox_max.php',
		'arcade.php', 'ibproarcade.php', 'pnflashgames.php', 'newscore.php', 'arcade_comment.php',
		'arcade_rate.php', 'arcade_tournament.php', 'album_showpage.php',
		'album_comment_edit.php', 'album_comment_delete.php', 'album_edit.php',
		'album_delete.php', 'album_hotornot.php', 'link_register.php'
	);
	if (in_array($script, $content_scripts, true))
	{
		return array('content', 300, 'request_limit_content', 60);
	}

	// Every remaining POST is bounded by a deliberately generous fallback.
	// This automatically covers integrated MODs and future entry points without
	// maintaining a fragile filename allowlist. Ordinary GET/HEAD page views do
	// not call this classifier and are never counted.
	return array('write', 60, 'request_limit_write', 120);
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

/**
 * Return the remaining rolling cooldown since the last successful action.
 *
 * This is intentionally separate from the fixed-window request counter: a
 * registration form with validation errors must not consume the cooldown.
 * Missing or temporarily unavailable storage fails open, like the main
 * limiter, so a database update cannot make the board unavailable.
 */
function ctracker_rate_limit_cooldown_remaining($bucket, $identity, $cooldown_seconds)
{
	global $db;

	$bucket = is_scalar($bucket) ? (string) $bucket : '';
	$identity = is_scalar($identity) ? (string) $identity : '';
	$cooldown_seconds = max(1, min(86400, intval($cooldown_seconds)));
	if ($bucket === '' || $identity === '')
	{
		return false;
	}

	$bucket_hash = hash('sha256', $bucket . "\0" . $identity);
	$sql = 'SELECT updated_at FROM ' . CTRACKER_RATE_LIMITS . "
		WHERE bucket_hash = '" . $db->sql_escape($bucket_hash) . "'";
	if (!($result = $db->sql_query($sql)))
	{
		return false;
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row || empty($row['updated_at']))
	{
		return 0;
	}

	return max(0, (intval($row['updated_at']) + $cooldown_seconds) - time());
}

/** Record a successful action for a later rolling-cooldown check. */
function ctracker_rate_limit_mark_success($bucket, $identity)
{
	global $db;

	$bucket = is_scalar($bucket) ? (string) $bucket : '';
	$identity = is_scalar($identity) ? (string) $identity : '';
	if ($bucket === '' || $identity === '')
	{
		return false;
	}

	$now = time();
	$bucket_hash = hash('sha256', $bucket . "\0" . $identity);
	$sql = 'INSERT INTO ' . CTRACKER_RATE_LIMITS . "
		(bucket_hash, window_start, request_count, updated_at)
		VALUES ('" . $db->sql_escape($bucket_hash) . "', $now, 1, $now)
		ON DUPLICATE KEY UPDATE window_start = VALUES(window_start),
			request_count = request_count + 1, updated_at = VALUES(updated_at)";
	return (bool) $db->sql_query($sql);
}

function ctracker_enforce_request_limit_profile($profile)
{
	global $ctracker_config;

	if (empty($ctracker_config->settings['request_limit_enabled']) || !is_array($profile) || count($profile) !== 4)
	{
		return;
	}

	list($bucket, $window_seconds, $setting_name, $default_limit) = $profile;
	if (!is_scalar($bucket) || !is_scalar($setting_name))
	{
		return;
	}
	$bucket = (string) $bucket;
	$setting_name = (string) $setting_name;
	$window_seconds = max(1, min(86400, intval($window_seconds)));
	$default_limit = max(1, min(10000, intval($default_limit)));
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

function ctracker_enforce_request_limit()
{
	global $HTTP_SERVER_VARS, $HTTP_POST_VARS, $HTTP_GET_VARS;

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
	ctracker_enforce_request_limit_profile($profile);
}

/**
 * Add a tighter failed-login limit for one verified IP and one submitted
 * account name. Unlike the legacy user-table flag, another visitor can never
 * force a CAPTCHA or lock state onto the account for its legitimate owner.
 */
function ctracker_enforce_login_identity_limit($username)
{
	global $ctracker_config;

	if (empty($ctracker_config->settings['loginfeature']))
	{
		return;
	}
	$username = is_scalar($username) ? strtolower(trim((string) $username)) : '';
	if ($username === '')
	{
		return;
	}

	$configured_limit = isset($ctracker_config->settings['logincount']) ? intval($ctracker_config->settings['logincount']) : 5;
	$limit = max(5, min(20, $configured_limit));
	$remote_ip = isset($ctracker_config->user_ip_value) ? (string) $ctracker_config->user_ip_value : '0.0.0.0';
	$identity = $remote_ip . "\0" . hash('sha256', $username);
	$retry_after = ctracker_rate_limit_increment('login-identity', $identity, 900, $limit);

	if ($retry_after !== false && $retry_after > 0)
	{
		if (!headers_sent())
		{
			http_response_code(429);
			header('Retry-After: ' . $retry_after);
			header('Cache-Control: no-store');
			header('Content-Type: text/html; charset=UTF-8');
		}
		echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Login temporarily limited</title></head><body>' .
			'<h1>Login vorübergehend begrenzt / Login temporarily limited</h1>' .
			'<p>Für diese Verbindung gab es mehrere fehlgeschlagene Versuche. Bitte warte kurz. ' .
			'This connection made several unsuccessful attempts. Please wait briefly.</p></body></html>';
		exit;
	}
}

if (!defined('CTRACKER_REQUEST_LIMITER_NO_AUTO_RUN'))
{
	ctracker_enforce_request_limit();
}

?>
