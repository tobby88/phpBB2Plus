<?php

/**
 * Compatibility helpers for APIs removed after PHP 5.
 *
 * Every helper is conditional so PHP 5.6 continues to use its native API.
 */

/**
 * Decode legacy serialized arrays without allowing object instantiation.
 *
 * Several phpBB2 cookies and caches predate JSON. Passing attacker-controlled
 * serialized data to unserialize() can invoke magic methods on loaded classes.
 * PHP 7+ can reject classes natively; the PHP 5.6 fallback rejects serialized
 * object/custom-object tokens before decoding.
 */
if (!function_exists('phpbb_safe_unserialize'))
{
	function phpbb_safe_unserialize($serialized)
	{
		if (!is_string($serialized) || $serialized === '')
		{
			return false;
		}

		if (version_compare(PHP_VERSION, '7.0.0', '>='))
		{
			$value = @unserialize($serialized, array('allowed_classes' => false));
			return is_object($value) ? false : $value;
		}

		if (preg_match('/(^|[;{}])(O|C):[0-9]+:/i', $serialized))
		{
			return false;
		}

		return @unserialize($serialized);
	}
}

/**
 * Decode the array-shaped cookies and caches used throughout phpBB2.
 *
 * Keeping the type guarantee here prevents every caller from having to defend
 * count(), sorting, foreach and array-offset operations against false/scalars.
 */
if (!function_exists('phpbb_safe_unserialize_array'))
{
	function phpbb_safe_unserialize_array($serialized)
	{
		$value = phpbb_safe_unserialize($serialized);
		return is_array($value) ? $value : array();
	}
}

if (!function_exists('phpbb_safe_unserialize_scalar_array'))
{
	function phpbb_safe_unserialize_scalar_array($serialized, $limit = 1000)
	{
		$values = phpbb_safe_unserialize_array($serialized);
		$clean = array();
		foreach ($values as $key => $value)
		{
			if (count($clean) >= $limit)
			{
				break;
			}
			if ((is_int($key) || is_string($key)) && is_scalar($value))
			{
				$clean[$key] = $value;
			}
		}
		return $clean;
	}
}

if (!function_exists('phpbb_tracking_cookie_array'))
{
	function phpbb_tracking_cookie_array($serialized)
	{
		$values = phpbb_safe_unserialize_array($serialized);
		$clean = array();
		$now = time();
		foreach ($values as $id => $timestamp)
		{
			if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D', (string) $id) || !is_scalar($timestamp))
			{
				continue;
			}
			$timestamp = intval($timestamp);
			if ($timestamp > 0)
			{
				$clean[intval($id)] = min($timestamp, $now);
			}
		}
		if (count($clean) > 150)
		{
			arsort($clean, SORT_NUMERIC);
			$clean = array_slice($clean, 0, 150, true);
		}
		return $clean;
	}
}

/**
 * Set application cookies with modern browser protections on every PHP level.
 */
if (!function_exists('phpbb_request_is_https'))
{
	function phpbb_request_is_https()
	{
		$https = isset($_SERVER['HTTPS']) && is_scalar($_SERVER['HTTPS']) ? strtolower(trim((string) $_SERVER['HTTPS'])) : '';
		if ($https !== '' && $https !== 'off' && $https !== '0')
		{
			return true;
		}

		return isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443;
	}
}

if (!function_exists('phpbb_setcookie'))
{
	function phpbb_setcookie($name, $value, $expires, $path, $domain, $secure)
	{
		$path = ($path === '') ? '/' : $path;
		$secure = (bool) $secure || phpbb_request_is_https();

		if (version_compare(PHP_VERSION, '7.3.0', '>='))
		{
			return setcookie($name, $value, array(
				'expires' => (int) $expires,
				'path' => $path,
				'domain' => $domain,
				'secure' => $secure,
				'httponly' => true,
				'samesite' => 'Lax'
			));
		}

		return setcookie($name, $value, (int) $expires, rtrim($path, '; ') . '; SameSite=Lax', $domain, $secure, true);
	}
}

/**
 * Apply phpBB2's historical SQL quoting consistently to nested request data.
 *
 * The application predates prepared statements and expects magic-quotes-style
 * input. Modern PHP keeps the legacy $HTTP_* aliases as copies, so changing
 * only $_GET/$_POST leaves the aliases unquoted and reopens SQL injection.
 */
if (!function_exists('phpbb_addslashes_recursive'))
{
	function phpbb_addslashes_recursive($value)
	{
		if (is_array($value))
		{
			foreach ($value as $key => $item)
			{
				$value[$key] = phpbb_addslashes_recursive($item);
			}
			return $value;
		}

		return is_string($value) ? addslashes($value) : $value;
	}
}

/**
 * Read one scalar request value without allowing PHP 8 string TypeErrors.
 */
if (!function_exists('phpbb_request_scalar'))
{
	function phpbb_request_scalar($source, $key, $default = '')
	{
		return (is_array($source) && isset($source[$key]) && is_scalar($source[$key]))
			? (string) $source[$key]
			: $default;
	}
}

/**
 * Read a request array as unique positive integer IDs.
 */
if (!function_exists('phpbb_request_id_array'))
{
	function phpbb_request_id_array($source, $key)
	{
		if (!is_array($source) || !isset($source[$key]) || !is_array($source[$key]))
		{
			return array();
		}

		$ids = array();
		foreach ($source[$key] as $value)
		{
			if (!is_scalar($value))
			{
				continue;
			}

			$value = (string) $value;
			if (!preg_match('/^[1-9][0-9]*$/D', $value))
			{
				continue;
			}

			$id = intval($value);
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}
}

/**
 * Return random bytes on PHP 5.6 through current PHP versions.
 */
if (!function_exists('phpbb_random_bytes'))
{
	function phpbb_random_bytes($length)
	{
		$length = max(1, (int) $length);
		if (function_exists('random_bytes'))
		{
			try
			{
				return random_bytes($length);
			}
			catch (Exception $e)
			{
				// Fall through to the PHP 5.6-compatible provider.
			}
		}

		if (function_exists('openssl_random_pseudo_bytes'))
		{
			$strong = false;
			$bytes = openssl_random_pseudo_bytes($length, $strong);
			if ($bytes !== false && $strong && strlen($bytes) === $length)
			{
				return $bytes;
			}
		}

		// Last-resort compatibility fallback for unusually limited PHP 5.6
		// builds. Modern supported installations use random_bytes().
		$bytes = '';
		while (strlen($bytes) < $length)
		{
			$bytes .= hash('sha256', uniqid((string) mt_rand(), true) . microtime(true), true);
		}
		return substr($bytes, 0, $length);
	}
}

/**
 * Return a cryptographically random string without modulo bias.
 */
if (!function_exists('phpbb_random_string'))
{
	function phpbb_random_string($length, $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz')
	{
		$length = max(1, (int) $length);
		$alphabet = (string) $alphabet;
		$alphabet_length = strlen($alphabet);
		if ($alphabet_length < 2 || $alphabet_length > 256)
		{
			throw new InvalidArgumentException('Random-string alphabet must contain 2 to 256 characters.');
		}

		$limit = 256 - (256 % $alphabet_length);
		$result = '';
		while (strlen($result) < $length)
		{
			$bytes = phpbb_random_bytes(max(16, $length - strlen($result)));
			for ($i = 0, $count = strlen($bytes); $i < $count && strlen($result) < $length; $i++)
			{
				$value = ord($bytes[$i]);
				if ($value < $limit)
				{
					$result .= $alphabet[$value % $alphabet_length];
				}
			}
		}
		return $result;
	}
}

/**
 * Build an absolute URL from the configured board origin, never HTTP_HOST.
 */
if (!function_exists('phpbb_board_url'))
{
	function phpbb_board_url($relative_path = '')
	{
		global $board_config;

		$secure_request = phpbb_request_is_https();
		$scheme = ($secure_request || !empty($board_config['cookie_secure'])) ? 'https' : 'http';
		$host = isset($board_config['server_name']) ? trim($board_config['server_name']) : '';
		if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])$/i', $host))
		{
			$host = 'localhost';
		}

		$port = isset($board_config['server_port']) ? (int) $board_config['server_port'] : 0;
		// Old boards commonly retain port 80 after being moved behind HTTPS.
		// Treat both standard web ports as implicit; preserve only custom ports.
		$port_part = ($port > 0 && !in_array($port, array(80, 443), true))
			? ':' . $port
			: '';
		$script_path = isset($board_config['script_path']) ? $board_config['script_path'] : '';
		$script_path = preg_replace('/[^a-z0-9._~!$&()*+,;=:@\/%-]/i', '', str_replace('\\', '/', $script_path));
		$script_path = ($script_path === '' || $script_path === '/') ? '/' : '/' . trim($script_path, '/') . '/';

		return $scheme . '://' . $host . $port_part . $script_path . ltrim($relative_path, '/');
	}
}

/**
 * Compare the configured board host with its conventional www/apex alias.
 * No other sibling or child subdomain is trusted by this helper.
 */
if (!function_exists('phpbb_board_hosts_match'))
{
	function phpbb_board_hosts_match($first, $second)
	{
		$first = strtolower(rtrim(trim((string) $first), '.'));
		$second = strtolower(rtrim(trim((string) $second), '.'));
		if ($first === '' || $second === '' ||
			!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])$/i', $first) ||
			!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])$/i', $second))
		{
			return false;
		}

		if ($first === $second)
		{
			return true;
		}

		$first_without_www = (strpos($first, 'www.') === 0) ? substr($first, 4) : $first;
		$second_without_www = (strpos($second, 'www.') === 0) ? substr($second, 4) : $second;
		return $first_without_www !== '' && $first_without_www === $second_without_www;
	}
}

/**
 * Validate a Referer against the board host and an optional administrator
 * allowlist. This replaces the legacy substring check, which accepted hosts
 * such as "trusted.example.attacker.test".
 */
if (!function_exists('phpbb_referer_is_allowed'))
{
	function phpbb_referer_is_allowed($referer, $board_host, $additional_hosts = '')
	{
		$referer = is_scalar($referer) ? trim((string) $referer) : '';
		$referer_host = $referer === '' ? '' : @parse_url($referer, PHP_URL_HOST);
		if (!is_string($referer_host) || $referer_host === '')
		{
			return false;
		}

		if (phpbb_board_hosts_match($referer_host, $board_host))
		{
			return true;
		}

		foreach (explode(',', (string) $additional_hosts) as $allowed)
		{
			$allowed = trim($allowed);
			if ($allowed === '')
			{
				continue;
			}
			$allowed_host = strpos($allowed, '://') !== false
				? @parse_url($allowed, PHP_URL_HOST)
				: @parse_url('http://' . ltrim($allowed, '.'), PHP_URL_HOST);
			$allowed_host = is_string($allowed_host) ? strtolower(rtrim($allowed_host, '.')) : '';
			$referer_host_normalized = strtolower(rtrim($referer_host, '.'));
			if ($allowed_host !== '' && ($referer_host_normalized === $allowed_host ||
				substr($referer_host_normalized, -strlen('.' . $allowed_host)) === '.' . $allowed_host))
			{
				return true;
			}
		}

		return false;
	}
}

/**
 * Reject browser-declared cross-site state-changing requests. SameSite cookies
 * remain the primary compatibility-safe CSRF defence; this covers clients
 * which also provide Origin or Fetch Metadata without breaking older agents.
 */
if (!function_exists('phpbb_request_origin_is_valid'))
{
	function phpbb_request_origin_is_valid()
	{
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
		if (!in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true))
		{
			return true;
		}

		$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
		if ($origin === '')
		{
			$fetch_site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower((string) $_SERVER['HTTP_SEC_FETCH_SITE']) : '';
			return $fetch_site !== 'cross-site';
		}
		if (strtolower($origin) === 'null')
		{
			return false;
		}

		$actual = @parse_url($origin);
		$expected = @parse_url(phpbb_board_url());
		if (!$actual || !$expected || empty($actual['scheme']) || empty($actual['host']))
		{
			return false;
		}
		$actual_port = isset($actual['port']) ? (int) $actual['port'] : (strtolower($actual['scheme']) === 'https' ? 443 : 80);
		$expected_port = isset($expected['port']) ? (int) $expected['port'] : (strtolower($expected['scheme']) === 'https' ? 443 : 80);

		return strtolower($actual['scheme']) === strtolower($expected['scheme'])
			&& phpbb_board_hosts_match($actual['host'], $expected['host'])
			&& $actual_port === $expected_port;
	}
}

/**
 * Origin check for legacy endpoints which may change state through GET because
 * the embedded game protocol predates normal HTML form conventions.
 */
if (!function_exists('phpbb_request_source_is_same_origin'))
{
	function phpbb_request_source_is_same_origin()
	{
		$fetch_site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_scalar($_SERVER['HTTP_SEC_FETCH_SITE'])
			? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_SITE']))
			: '';
		if ($fetch_site === 'cross-site')
		{
			return false;
		}

		$origin = isset($_SERVER['HTTP_ORIGIN']) && is_scalar($_SERVER['HTTP_ORIGIN'])
			? trim((string) $_SERVER['HTTP_ORIGIN'])
			: '';
		if ($origin === '')
		{
			// Older Flash clients provide neither Origin nor Fetch Metadata.
			return true;
		}
		if (strtolower($origin) === 'null')
		{
			return false;
		}

		$actual = @parse_url($origin);
		$expected = @parse_url(phpbb_board_url());
		if (!$actual || !$expected || empty($actual['scheme']) || empty($actual['host']) ||
			!in_array(strtolower($actual['scheme']), array('http', 'https'), true))
		{
			return false;
		}
		$actual_port = isset($actual['port']) ? (int) $actual['port'] : (strtolower($actual['scheme']) === 'https' ? 443 : 80);
		$expected_port = isset($expected['port']) ? (int) $expected['port'] : (strtolower($expected['scheme']) === 'https' ? 443 : 80);

		return strtolower($actual['scheme']) === strtolower($expected['scheme'])
			&& phpbb_board_hosts_match($actual['host'], $expected['host'])
			&& $actual_port === $expected_port;
	}
}

/**
 * Bind a compact action capability to the current session without storing
 * additional server-side state. This is intended for legacy GET controls
 * which cannot be converted to forms without breaking their UI contract.
 */
if (!function_exists('phpbb_session_action_token'))
{
	function phpbb_session_action_token($scope, $action, $target_id, $session_id)
	{
		$scope = preg_replace('/[^a-z0-9_-]/i', '', (string) $scope);
		$action = preg_replace('/[^a-z0-9_-]/i', '', (string) $action);
		return hash_hmac('sha256', $scope . ':' . $action . ':' . (int) $target_id, (string) $session_id);
	}
}

/**
 * Password helpers accept historical unsalted MD5 hashes and create only
 * adaptive hashes. Successful legacy logins can therefore migrate in place.
 */
if (!function_exists('phpbb_password_hash'))
{
	function phpbb_password_hash($password)
	{
		global $board_config;

		// Existing databases opt in only after their password columns have
		// been widened by update_from_153a.php.
		if (isset($board_config) && is_array($board_config) && empty($board_config['password_hashing']))
		{
			return md5($password);
		}

		return password_hash($password, PASSWORD_DEFAULT);
	}
}

if (!function_exists('phpbb_password_verify'))
{
	function phpbb_password_verify($password, $stored_hash)
	{
		// A fixed, valid bcrypt hash gives unknown or malformed accounts the
		// same deliberately expensive verification path as modern accounts.
		$dummy_hash = '$2y$10$a69Y35T0bxEO.FwchNKEX.BmLguLKRHzzCbtMMUgMgrTcATJka/sm';
		$password_is_scalar = is_scalar($password);
		$password = $password_is_scalar ? (string) $password : '';
		if (!$password_is_scalar)
		{
			password_verify($password, $dummy_hash);
			return false;
		}
		if (!is_string($stored_hash) || $stored_hash === '')
		{
			password_verify((string) $password, $dummy_hash);
			return false;
		}

		if (preg_match('/^[a-f0-9]{32}$/i', $stored_hash))
		{
			$legacy_hash = md5($password);
			$valid = function_exists('hash_equals')
				? hash_equals(strtolower($stored_hash), strtolower($legacy_hash))
				: strtolower($stored_hash) === strtolower($legacy_hash);
			// Legacy MD5 comparison is otherwise observably faster than adaptive
			// hashes, which would reveal which accounts still need migration.
			password_verify((string) $password, $dummy_hash);
			return $valid;
		}

		return password_verify($password, $stored_hash);
	}
}

if (!function_exists('phpbb_password_needs_rehash'))
{
	function phpbb_password_needs_rehash($stored_hash)
	{
		return preg_match('/^[a-f0-9]{32}$/i', (string) $stored_hash)
			|| password_needs_rehash($stored_hash, PASSWORD_DEFAULT);
	}
}
