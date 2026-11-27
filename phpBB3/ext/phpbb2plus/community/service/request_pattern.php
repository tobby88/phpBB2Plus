<?php
namespace phpbb2plus\community\service;

class request_pattern
{
	public static function normalize($value)
	{
		$value = is_string($value) ? trim($value, ' ') : '';
		return $value !== '' && strlen($value) <= 200 && substr_count($value, '*') <= 8 &&
			preg_match('//u', $value) && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : '';
	}

	public static function matches($pattern, $ip, $agent, $host)
	{
		$pattern = self::normalize($pattern);
		if ($pattern === '') { return false; }
		$parts = explode('/', $pattern, 2);
		$network = @inet_pton($parts[0]);
		$address = @inet_pton($ip);
		if ($network !== false && $address !== false && strlen($network) === strlen($address))
		{
			if (count($parts) === 1 && $network === $address) { return true; }
			if (isset($parts[1]) && preg_match('/^[0-9]{1,3}$/D', $parts[1]) && (int) $parts[1] <= strlen($network) * 8)
			{
				$bytes = intdiv((int) $parts[1], 8);
				$rest = (int) $parts[1] % 8;
				if (substr($network, 0, $bytes) === substr($address, 0, $bytes) &&
					(!$rest || (ord($network[$bytes]) >> (8 - $rest)) === (ord($address[$bytes]) >> (8 - $rest)))) { return true; }
			}
		}
		// A historical rule applies to each whole field. Only '*' is special.
		$pieces = explode('*', strtolower($pattern));
		foreach (array($ip, substr($agent, 0, 2048), substr($host, 0, 255)) as $field)
		{
			if ($field !== '' && self::glob($pieces, strtolower($field))) { return true; }
		}
		return false;
	}

	private static function glob(array $pieces, $field)
	{
		$first = array_shift($pieces);
		if (!$pieces) { return $first === $field; }
		$last = array_pop($pieces);
		$position = strlen($first);
		$end = strlen($field) - strlen($last);
		if ($end < $position || strncmp($field, $first, $position) || ($last !== '' && substr($field, $end) !== $last)) { return false; }
		foreach ($pieces as $piece)
		{
			if ($piece === '') { continue; }
			$found = strpos($field, $piece, $position);
			if ($found === false || $found + strlen($piece) > $end) { return false; }
			$position = $found + strlen($piece);
		}
		return true;
	}
}
