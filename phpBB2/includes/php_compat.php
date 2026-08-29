<?php

/**
 * Compatibility helpers for APIs removed after PHP 5.
 *
 * Every helper is conditional so PHP 5.6 continues to use its native API.
 */

if (!function_exists('each'))
{
	function each(&$array)
	{
		$key = key($array);
		if ($key === null)
		{
			return false;
		}

		$value = current($array);
		next($array);

		return array(1 => $value, 'value' => $value, 0 => $key, 'key' => $key);
	}
}

if (!function_exists('get_magic_quotes_gpc'))
{
	function get_magic_quotes_gpc()
	{
		return false;
	}
}

if (!function_exists('get_magic_quotes_runtime'))
{
	function get_magic_quotes_runtime()
	{
		return false;
	}
}

if (!function_exists('set_magic_quotes_runtime'))
{
	function set_magic_quotes_runtime($enabled)
	{
		return false;
	}
}

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
 * Set application cookies with modern browser protections on every PHP level.
 */
if (!function_exists('phpbb_setcookie'))
{
	function phpbb_setcookie($name, $value, $expires, $path, $domain, $secure)
	{
		$path = ($path === '') ? '/' : $path;
		$request_secure = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
		$secure = (bool) $secure || $request_secure;

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
 * Build an absolute URL from the configured board origin, never HTTP_HOST.
 */
if (!function_exists('phpbb_board_url'))
{
	function phpbb_board_url($relative_path = '')
	{
		global $board_config;

		$secure_request = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
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
			&& strtolower($actual['host']) === strtolower($expected['host'])
			&& $actual_port === $expected_port;
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
		if (!is_string($stored_hash) || $stored_hash === '')
		{
			return false;
		}

		if (preg_match('/^[a-f0-9]{32}$/i', $stored_hash))
		{
			$legacy_hash = md5($password);
			return function_exists('hash_equals')
				? hash_equals(strtolower($stored_hash), strtolower($legacy_hash))
				: strtolower($stored_hash) === strtolower($legacy_hash);
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

if (!function_exists('phpbb_compat_posix_pattern'))
{
	function phpbb_compat_posix_pattern($pattern, $case_insensitive)
	{
		return '~' . str_replace('~', '\\~', $pattern) . '~' . ($case_insensitive ? 'i' : '');
	}
}

if (!function_exists('ereg'))
{
	function ereg($pattern, $string, &$matches = null)
	{
		$result = preg_match(phpbb_compat_posix_pattern($pattern, false), $string, $matches);
		return $result === 1 ? max(1, strlen($matches[0])) : false;
	}
}

if (!function_exists('eregi'))
{
	function eregi($pattern, $string, &$matches = null)
	{
		$result = preg_match(phpbb_compat_posix_pattern($pattern, true), $string, $matches);
		return $result === 1 ? max(1, strlen($matches[0])) : false;
	}
}

if (!function_exists('ereg_replace'))
{
	function ereg_replace($pattern, $replacement, $string)
	{
		return preg_replace(phpbb_compat_posix_pattern($pattern, false), $replacement, $string);
	}
}

if (!function_exists('eregi_replace'))
{
	function eregi_replace($pattern, $replacement, $string)
	{
		return preg_replace(phpbb_compat_posix_pattern($pattern, true), $replacement, $string);
	}
}

if (!function_exists('split'))
{
	function split($pattern, $string, $limit = -1)
	{
		return preg_split(phpbb_compat_posix_pattern($pattern, false), $string, $limit);
	}
}

if (!function_exists('spliti'))
{
	function spliti($pattern, $string, $limit = -1)
	{
		return preg_split(phpbb_compat_posix_pattern($pattern, true), $string, $limit);
	}
}
