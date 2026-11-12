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
		// Match profile.php's dispatch order, not the generic POST-first rule.
		// A conflicting POST mode must not downgrade a GET registration/email.
		$get_mode = isset($get['mode']) && is_scalar($get['mode']) ? (string) $get['mode'] : '';
		$post_mode = isset($post['mode']) && is_scalar($post['mode']) ? (string) $post['mode'] : '';
		$mode = strtolower($get_mode !== '' ? $get_mode : $post_mode);
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
		// dload.php falls back on empty/non-scalar POST values and dispatches
		// the part before its optional ?module-action suffix.
		$post_action = isset($post['action']) && is_scalar($post['action']) ? (string) $post['action'] : '';
		$get_action = isset($get['action']) && is_scalar($get['action']) ? (string) $get['action'] : '';
		$action = substr($post_action !== '' ? $post_action : ($get_action !== '' ? $get_action : 'main'), 0, 120);
		$action_parts = explode('?', $action, 2);
		$action = strtolower($action_parts[0]);
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

require_once dirname(__DIR__).'/classes/class_rate_storage.php';

function ctracker_rate_limit_increment($bucket,$identity,$window_seconds,$limit)
{
    global $db;
    $hash=ctracker_rate_arguments($bucket,$identity);if($hash===false||!is_scalar($window_seconds)||!is_scalar($limit)){return false;}
    $window_seconds=max(1,min(86400,intval($window_seconds)));$limit=max(1,min(10000,intval($limit)));$now=time();$start=(int)(floor($now/$window_seconds)*$window_seconds);$scope=null;
    try{
        $scope=new CtrackerRateScope($db,true);
        $scope->query('INSERT INTO '.CTRACKER_RATE_LIMITS." (bucket_hash,window_start,request_count,updated_at) VALUES ('".$scope->escape($hash)."', $start, 1, $now) ON DUPLICATE KEY UPDATE request_count=IF(window_start>=VALUES(window_start),LEAST(request_count,4294967294)+1,1),window_start=GREATEST(window_start,VALUES(window_start)),updated_at=GREATEST(updated_at,VALUES(updated_at))");
        $rows=$scope->rows('SELECT request_count,window_start,updated_at FROM '.CTRACKER_RATE_LIMITS." WHERE bucket_hash='".$scope->escape($hash)."' FOR UPDATE");
        if(count($rows)!==1||(float)$rows[0]['request_count']<1||(float)$rows[0]['window_start']<$start||(float)$rows[0]['updated_at']<$now){ctracker_rate_storage_fail();}
        $retry=(float)$rows[0]['request_count']>$limit?(int)max(1,min($window_seconds,(float)$rows[0]['window_start']+$window_seconds-time())):0;
        $scope->commit();
        if(mt_rand(1,100)===1){try{$scope->collect($now);}catch(Exception$ignored){}catch(Error$ignored){}}
        return $retry;
    }catch(Exception$error){return false;}catch(Error$error){return false;}finally{if($scope!==null){$scope->release();}}
}

function ctracker_rate_limit_cooldown_remaining($bucket,$identity,$cooldown_seconds)
{
    global $db;
    $hash=ctracker_rate_arguments($bucket,$identity);if($hash===false||!is_scalar($cooldown_seconds)){return false;}$cooldown_seconds=max(1,min(86400,intval($cooldown_seconds)));$scope=null;
    try{
        $scope=new CtrackerRateScope($db);$rows=$scope->rows('SELECT updated_at FROM '.CTRACKER_RATE_LIMITS." WHERE bucket_hash='".$scope->escape($hash)."'");
        if(!$rows){return 0;}if(count($rows)!==1||!isset($rows[0]['updated_at'])||preg_match('/^[0-9]{1,10}$/D',(string)$rows[0]['updated_at'])!==1){ctracker_rate_storage_fail();}
        return (int)min(PHP_INT_MAX,max(0,(float)$rows[0]['updated_at']+$cooldown_seconds-time()));
    }catch(Exception$error){return false;}catch(Error$error){return false;}finally{if($scope!==null){$scope->release();}}
}

function ctracker_rate_limit_mark_success($bucket,$identity)
{
    global $db;
    $hash=ctracker_rate_arguments($bucket,$identity);if($hash===false){return false;}$now=time();$scope=null;
    try{
        $scope=new CtrackerRateScope($db,true);$scope->query(ctracker_rate_success_sql($scope->connection,$hash,$now));$rows=$scope->rows('SELECT window_start,request_count,updated_at FROM '.CTRACKER_RATE_LIMITS." WHERE bucket_hash='".$scope->escape($hash)."' FOR UPDATE");
        if(count($rows)!==1||(float)$rows[0]['window_start']<$now||(float)$rows[0]['updated_at']<$now||(float)$rows[0]['request_count']<1){ctracker_rate_storage_fail();}
        $scope->commit();return true;
    }catch(Exception$error){return false;}catch(Error$error){return false;}finally{if($scope!==null){$scope->release();}}
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
