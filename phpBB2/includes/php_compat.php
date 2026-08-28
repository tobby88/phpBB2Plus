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
