<?php
namespace phpbb2plus\community\profile;

class visits
{
	const MAX_COUNT = '9223372036854775807';

	/** Keep positive 64-bit counters exact, including during conversion on 32-bit PHP. */
	public static function count($source)
	{
		$value = ltrim((string) $source, '0');
		if ($value === '' || !ctype_digit($value)) { return '0'; }
		if (strlen($value) > strlen(self::MAX_COUNT) || (strlen($value) === strlen(self::MAX_COUNT) && strcmp($value, self::MAX_COUNT) > 0))
		{ return self::MAX_COUNT; }
		return $value;
	}

	/** Both arguments are trusted SQL expressions. Avoid evaluating an overflowing sum. */
	public static function increment($column, $increment)
	{
		return 'CASE WHEN ' . $column . ' > ' . self::MAX_COUNT . ' - (' . $increment . ') THEN ' . self::MAX_COUNT .
			' ELSE ' . $column . ' + (' . $increment . ') END';
	}

	public static function duration($seconds, $language)
	{
		$seconds = max(0, (int) $seconds);
		$pieces = array();
		foreach (array('PLUS_VISIT_YEARS'=>31536000, 'PLUS_VISIT_WEEKS'=>604800, 'PLUS_VISIT_DAYS'=>86400) as $word => $unit)
		{
			$number = intdiv($seconds, $unit);
			if ($number || $pieces) { $pieces[] = $language->lang($word, $number); }
			$seconds %= $unit;
		}
		$pieces[] = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
		return implode(', ', $pieces);
	}
}
