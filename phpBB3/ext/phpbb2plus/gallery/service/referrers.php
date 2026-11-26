<?php
namespace phpbb2plus\gallery\service;

class referrers
{
	private static function host($value, $entry, $validate = false)
	{
		$value = trim((string) $value);
		if ($value === '') { return ''; }
		if ($entry && strpos($value, '://') === false) { $value = 'http://' . ltrim($value, '.'); }
		$parts = parse_url($value);
		if (empty($parts['host'])) { return ''; }
		// Incoming referrers and old settings were compared by host. New ACP entries are domains or web URLs.
		if ($validate && (!in_array(strtolower($parts['scheme'] ?? ''), array('http','https'), true) ||
			isset($parts['user']) || isset($parts['pass']))) { return ''; }
		$host = strtolower(rtrim($parts['host'], '.'));
		if (substr($host, 0, 1) === '[')
		{
			return substr($host, -1) === ']' && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $host : '';
		}
		if (preg_match('/[^\x00-\x7f]/', $host) && function_exists('idn_to_ascii'))
		{
			$host = idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46);
		}
		return is_string($host) && $host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ? strtolower($host) : '';
	}

	public static function valid_list($list)
	{
		if (strlen($list) > 4096) { return false; }
		foreach (explode(',', $list) as $entry)
		{
			$entry = trim($entry);
			if ($entry !== '' && (preg_match('/\s/u', $entry) || self::host($entry, true, true) === '')) { return false; }
		}
		return true;
	}

	public static function allows($referrer, $board_hosts, $list)
	{
		$from = self::host($referrer, false);
		if ($from === '') { return false; }
		$without_www = preg_replace('/^www\./', '', $from);
		foreach ($board_hosts as $entry)
		{
			$board = self::host($entry, true);
			if ($board !== '' && preg_replace('/^www\./', '', $board) === $without_www) { return true; }
		}
		foreach (explode(',', $list) as $entry)
		{
			$allowed = self::host($entry, true);
			if ($allowed !== '' && ($from === $allowed || substr($from, -strlen($allowed) - 1) === '.' . $allowed)) { return true; }
		}
		return false;
	}
}
