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

