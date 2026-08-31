<?php
/**
 * Central request-shape and exploit-signature protection.
 *
 * CrackerTracker 5.0.6 used hundreds of unscoped word fragments (including
 * quotes, "and", "or", ".js" and ordinary shell words). That both blocked
 * legitimate forum use and encouraged a false sense that substring matching
 * could replace validation at an SQL, HTML or command boundary. This engine
 * keeps the central early-request barrier, but limits it to structural abuse
 * and high-confidence exploit syntax. Free-text fields are shape-checked and
 * remain the responsibility of their proper output/database boundary.
 */

if (!defined('IN_PHPBB'))
{
	die('Hacking attempt!');
}

if (!defined('CT_DEBUG_MODE'))
{
	define('CT_DEBUG_MODE', false);
}

function ct_request_shape_is_safe($value, $depth, &$nodes)
{
	$nodes++;
	if ($nodes > 4000 || $depth > 8)
	{
		return false;
	}

	if (is_array($value))
	{
		foreach ($value as $key => $item)
		{
			if (strlen((string) $key) > 256 || !ct_request_shape_is_safe($item, $depth + 1, $nodes))
			{
				return false;
			}
		}
		return true;
	}

	return is_scalar($value) && strlen((string) $value) <= 4194304;
}

function ct_security_normalize($value)
{
	$value = is_scalar($value) ? (string) $value : '';
	for ($i = 0; $i < 3; $i++)
	{
		$decoded = rawurldecode($value);
		if ($decoded === $value)
		{
			break;
		}
		$value = $decoded;
	}
	return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
}

function ct_security_url_host($value, $is_authority)
{
	$value = is_scalar($value) ? trim((string) $value) : '';
	if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value))
	{
		return false;
	}

	$parts = @parse_url($is_authority ? 'http://' . $value : $value);
	if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))
	{
		return false;
	}
	if (!$is_authority && (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)))
	{
		return false;
	}

	return strtolower(rtrim($parts['host'], '.'));
}

function ct_security_hosts_match($first, $second)
{
	$first = strtolower(rtrim((string) $first, '.'));
	$second = strtolower(rtrim((string) $second, '.'));
	if ($first === $second)
	{
		return true;
	}
	$first = strpos($first, 'www.') === 0 ? substr($first, 4) : $first;
	$second = strpos($second, 'www.') === 0 ? substr($second, 4) : $second;
	return $first !== '' && $first === $second;
}

/**
 * Reject browser-confirmed cross-site writes without breaking old clients
 * which legitimately omit Origin, Referer and Fetch Metadata headers.
 */
function ct_security_cross_site_write($server)
{
	$server = is_array($server) ? $server : array();
	$method = isset($server['REQUEST_METHOD']) && is_scalar($server['REQUEST_METHOD']) ? strtoupper((string) $server['REQUEST_METHOD']) : '';
	if ($method !== 'POST')
	{
		return false;
	}

	$fetch_site = isset($server['HTTP_SEC_FETCH_SITE']) && is_scalar($server['HTTP_SEC_FETCH_SITE']) ? strtolower(trim((string) $server['HTTP_SEC_FETCH_SITE'])) : '';
	if ($fetch_site === 'cross-site')
	{
		return true;
	}

	$request_host = isset($server['HTTP_HOST']) ? ct_security_url_host($server['HTTP_HOST'], true) : false;
	if ($request_host === false)
	{
		return false;
	}

	$source = '';
	if (isset($server['HTTP_ORIGIN']) && is_scalar($server['HTTP_ORIGIN']))
	{
		$source = trim((string) $server['HTTP_ORIGIN']);
		if (strtolower($source) === 'null')
		{
			return true;
		}
	}
	elseif (isset($server['HTTP_REFERER']) && is_scalar($server['HTTP_REFERER']))
	{
		$source = trim((string) $server['HTTP_REFERER']);
	}

	if ($source === '')
	{
		return false;
	}
	$source_host = ct_security_url_host($source, false);
	return $source_host === false || !ct_security_hosts_match($request_host, $source_host);
}

function ct_security_disallowed_method($server)
{
	$method = isset($server['REQUEST_METHOD']) && is_scalar($server['REQUEST_METHOD']) ? strtoupper(trim((string) $server['REQUEST_METHOD'])) : '';
	if ($method === '')
	{
		return false;
	}
	return !in_array($method, array('GET', 'POST', 'HEAD', 'OPTIONS'), true);
}

function ct_security_key_is_safe($key)
{
	$key = strtolower(ct_security_normalize($key));
	if ($key === '' || preg_match('/[\x00-\x1f\x7f]/', $key))
	{
		return false;
	}

	return !preg_match('/^(?:globals|_(?:get|post|cookie|request|server|env|files|session)|http_(?:get|post|cookie|server|env|session)_vars)$/D', $key);
}

function ct_security_value_is_attack($value, $free_text, $custom_rules)
{
	$value = ct_security_normalize($value);
	if (strpos($value, "\0") !== false)
	{
		return true;
	}

	if (!$free_text)
	{
		$patterns = array(
			'~<\?(?:php|=)?|\?>~i',
			'~\b(?:php|data|expect|phar|zip|glob)\s*:(?://|text/html)~i',
			'~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~',
			'~(?:/etc/(?:passwd|shadow)|/proc/self/environ|(?:^|[/\\\\])\.ht(?:access|passwd)(?:$|[?&#]))~i',
			'~<(?:script|iframe|object|embed|svg|math)\b~i',
			'~\b(?:java|vb)script\s*:|\bon[a-z]+\s*=~i',
			'~\bunion\s+(?:all\s+)?select\b~i',
			'~[\'\"]\s*(?:or|and)\s+(?:\d+\s*=\s*\d+|[\'\"][^\'\"]*[\'\"]\s*=\s*[\'\"])~i',
			'~\b(?:sleep|benchmark|load_file)\s*\(~i',
			'~\binto\s+(?:out|dump)file\b~i',
			'~;\s*(?:select|insert|update|delete|drop|alter|create|truncate)\b~i',
			'~[\'\"]\s*(?:--|#|/\*)~'
		);
		foreach ($patterns as $pattern)
		{
			if (preg_match($pattern, $value))
			{
				return true;
			}
		}
	}

	$value_lower = strtolower($value);
	foreach ((array) $custom_rules as $rule)
	{
		$rule = is_scalar($rule) ? strtolower((string) $rule) : '';
		if ($rule !== '' && strpos($value_lower, $rule) !== false)
		{
			return true;
		}
	}

	return false;
}

function ct_security_array_is_attack($values, $ignored_fields, $free_text_fields, $custom_rules, $scan_values)
{
	foreach ((array) $values as $field => $value)
	{
		if (!ct_security_key_is_safe($field))
		{
			return true;
		}

		$field_name = strtolower((string) $field);
		if (is_array($value))
		{
			if (ct_security_array_is_attack($value, $ignored_fields, $free_text_fields, $custom_rules, $scan_values))
			{
				return true;
			}
			continue;
		}

		if (!is_scalar($value))
		{
			return true;
		}
		$free_text = in_array($field_name, $free_text_fields, true);
		if (strpos((string) $value, "\0") !== false)
		{
			return true;
		}
		if ($scan_values && !in_array($field_name, $ignored_fields, true) &&
			ct_security_value_is_attack($value, $free_text, $custom_rules))
		{
			return true;
		}
	}

	return false;
}

function ct_security_request_is_attack($get, $post, $server, $options)
{
	$get = is_array($get) ? $get : array();
	$post = is_array($post) ? $post : array();
	$server = is_array($server) ? $server : array();
	$options = is_array($options) ? $options : array();

	$nodes = 0;
	if (!ct_request_shape_is_safe($get, 0, $nodes))
	{
		return true;
	}
	$nodes = 0;
	if (!ct_request_shape_is_safe($post, 0, $nodes))
	{
		return true;
	}

	$query_string = isset($server['QUERY_STRING']) && is_scalar($server['QUERY_STRING']) ? (string) $server['QUERY_STRING'] : '';
	if (strlen($query_string) > 32768)
	{
		return true;
	}

	$get_ignored = isset($options['get_ignored']) ? (array) $options['get_ignored'] : array();
	$post_ignored = isset($options['post_ignored']) ? (array) $options['post_ignored'] : array();
	$get_free_text = isset($options['get_free_text']) ? (array) $options['get_free_text'] : array();
	$post_free_text = isset($options['post_free_text']) ? (array) $options['post_free_text'] : array();
	$custom_rules = isset($options['custom_rules']) ? (array) $options['custom_rules'] : array();
	$scan_post = !isset($options['scan_post']) || (bool) $options['scan_post'];

	return ct_security_array_is_attack($get, $get_ignored, $get_free_text, $custom_rules, true) ||
		ct_security_array_is_attack($post, $post_ignored, $post_free_text, $custom_rules, $scan_post);
}

function ct_security_auxiliary_input_is_attack($cookies, $files, $server)
{
	$cookies = is_array($cookies) ? $cookies : array();
	$files = is_array($files) ? $files : array();
	$server = is_array($server) ? $server : array();
	$cookie_header = isset($server['HTTP_COOKIE']) && is_scalar($server['HTTP_COOKIE']) ? (string) $server['HTTP_COOKIE'] : '';
	if (strlen($cookie_header) > 32768)
	{
		return true;
	}

	$nodes = 0;
	if (!ct_request_shape_is_safe($cookies, 0, $nodes))
	{
		return true;
	}
	$nodes = 0;
	if (!ct_request_shape_is_safe($files, 0, $nodes))
	{
		return true;
	}

	return ct_security_array_is_attack($cookies, array(), array(), array(), false) ||
		ct_security_array_is_attack($files, array(), array(), array(), false);
}

function ct_security_block_request($phpbb_root_path, $phpEx)
{
	if (CT_DEBUG_MODE !== true)
	{
		include_once($phpbb_root_path . 'ctracker/classes/class_log_manager.' . $phpEx);
		$logfile = new log_manager();
		$logfile->write_worm();
		unset($logfile);
	}

	if (!headers_sent())
	{
		http_response_code(403);
		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: no-store');
	}

	echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>CrackerTracker security alert</title></head>' .
		'<body><h1>Sicherheitsprüfung / Security check</h1>' .
		'<p>Die Anfrage enthielt eine nicht zulässige technische Struktur und wurde abgewiesen. ' .
		'The request contained a disallowed technical structure and was rejected.</p></body></html>';
	exit;
}

if (!defined('CTRACKER_SECURITY_NO_AUTO_RUN'))
{
	if (!isset($phpbb_root_path) || !is_string($phpbb_root_path) || $phpbb_root_path === '')
	{
		die('CrackerTracker: invalid phpBB root path.');
	}

	$free_post_fields = array(
		'username', 'password', 'subject', 'message', 'poll_title', 'poll_option',
		'email', 'aim', 'msn', 'yim', 'interests', 'occupation', 'signature',
		'website', 'location', 'search', 'sitename', 'word', 'replacement', 'help',
		'last_msg', 'quote', 'content', 'site_desc', 'disable_reg_msg', 'disable_msg',
		'pic_desc', 'pic_title', 'filecomment', 'comment', 'search_author',
		'add_poll_option_text', 'global_message'
	);
	$free_get_fields = array('search_author', 'search_keywords', 'highlight', 'topic', 'q');
	$ignored_get = array('submit');
	$ignored_post = array();
	$custom_rules = array();
	$scan_post = !defined('CT_SECLEVEL') || CT_SECLEVEL !== 'LOW';

	if (defined('CT_SECLEVEL') && (CT_SECLEVEL === 'MEDIUM' || CT_SECLEVEL === 'LOW'))
	{
		$ignored_get = array_merge($ignored_get, isset($ct_ignoregvar) ? (array) $ct_ignoregvar : array());
		$ignored_post = isset($ct_ignorepvar) ? (array) $ct_ignorepvar : array();
		$custom_rules = isset($ct_addheuristic) ? (array) $ct_addheuristic : array();
		if (isset($ct_delheuristic))
		{
			$custom_rules = array_diff($custom_rules, (array) $ct_delheuristic);
		}
	}

	$options = array(
		'get_ignored' => array_map('strtolower', $ignored_get),
		'post_ignored' => array_map('strtolower', $ignored_post),
		'get_free_text' => $free_get_fields,
		'post_free_text' => $free_post_fields,
		'custom_rules' => $custom_rules,
		'scan_post' => $scan_post
	);

	$security_cookies = isset($HTTP_COOKIE_VARS) && is_array($HTTP_COOKIE_VARS) ? $HTTP_COOKIE_VARS : (isset($_COOKIE) ? $_COOKIE : array());
	$security_files = isset($HTTP_POST_FILES) && is_array($HTTP_POST_FILES) ? $HTTP_POST_FILES : (isset($_FILES) ? $_FILES : array());
	if (ct_security_disallowed_method($HTTP_SERVER_VARS) ||
		ct_security_cross_site_write($HTTP_SERVER_VARS) ||
		ct_security_auxiliary_input_is_attack($security_cookies, $security_files, $HTTP_SERVER_VARS) ||
		ct_security_request_is_attack($HTTP_GET_VARS, $HTTP_POST_VARS, $HTTP_SERVER_VARS, $options))
	{
		ct_security_block_request($phpbb_root_path, $phpEx);
	}
}

define('protection_unit_one', true);

?>
