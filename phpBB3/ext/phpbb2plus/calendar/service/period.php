<?php
namespace phpbb2plus\calendar\service;

/** Calendar dates are civil dates in the editor's timezone. Stored ends are inclusive. */
class period
{
	public static function month_cells(\DateTimeImmutable $day, $week_start)
	{
		$first = $day->modify('first day of this month')->setTime(0, 0);
		$next = $first->modify('first day of next month')->setTime(0,0);
		$offset = ((int) $first->format('w') - $week_start + 7) % 7;
		$date = $first->modify('-' . $offset . ' days');
		for ($cell = 0; $date < $next || $cell % 7; $cell++, $date = $date->modify('+1 day')->setTime(0,0))
		{
			yield $date->format('Y-m-d') => $date;
		}
	}

	public static function values($start, $end, \DateTimeZone $zone)
	{
		$empty = array('start_date'=>'','start_time'=>'','end_date'=>'','end_time'=>'');
		if (!$start) { return $empty; }
		$a = (new \DateTimeImmutable('@' . $start))->setTimezone($zone);
		$b = (new \DateTimeImmutable('@' . $end))->setTimezone($zone);
		$all_day = $start === (int) $a->setTime(0,0)->format('U') &&
			$end === (int) $b->modify('+1 day')->setTime(0,0)->format('U') - 1;
		return array('start_date'=>$a->format('Y-m-d'), 'start_time'=>$all_day ? '' : $a->format('H:i:s'),
			'end_date'=>$all_day || $end !== $start ? $b->format('Y-m-d') : '',
			'end_time'=>$all_day || $end === $start ? '' : $b->format('H:i:s'));
	}

	private static function local($date, $clock, $stored, $zone)
	{
		if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date)) { throw new \DomainException('PLUS_CAL_INVALID'); }
		if ($clock === '')
		{
			$day = \DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
			if (!$day || $day->format('Y-m-d') !== $date) { throw new \DomainException('PLUS_CAL_INVALID'); }
			return $day;
		}
		if (preg_match('/^[0-9]{2}:[0-9]{2}$/D',$clock)) { $clock .= ':00'; }
		$value = $date . ' ' . $clock;
		if ($stored)
		{
			$existing = (new \DateTimeImmutable('@' . $stored))->setTimezone($zone);
			if ($existing->format('Y-m-d H:i:s') === $value) { return $existing; }
		}
		$day = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$zone);
		if (!$day || $day->format('Y-m-d H:i:s') !== $value) { throw new \DomainException('PLUS_CAL_INVALID'); }
		return $day;
	}

	public static function parse($fields, $remove, $stored, \DateTimeZone $zone)
	{
		if ($remove) { return array(0,0); }
		if (!array_filter($fields, static function ($v) { return $v !== ''; })) { return $stored; }
		$a = self::local($fields['start_date'],$fields['start_time'],$stored[0],$zone);
		if ($fields['end_date'] === '' && $fields['end_time'] !== '') { throw new \DomainException('PLUS_CAL_INVALID'); }
		if ($fields['end_date'] !== '')
		{
			$b = self::local($fields['end_date'],$fields['end_time'],$stored[1],$zone);
			$end = $fields['end_time'] === '' ? (int) $b->modify('+1 day')->setTime(0,0)->format('U') - 1 : (int) $b->format('U');
		}
		else
		{
			$end = $fields['start_time'] === '' ? (int) $a->modify('+1 day')->setTime(0,0)->format('U') - 1 : (int) $a->format('U');
		}
		// On PHP 7.4 getTimestamp() can reinterpret the first repeated local hour as the second.
		$start = (int) $a->format('U');
		if ($start === $stored[0] && $end === $stored[1]) { return $stored; }
		if ($end < $start || (int) $a->format('Y') < 1971 || (int) $a->format('Y') > 2069 || $end > 4294967294)
		{
			throw new \DomainException('PLUS_CAL_INVALID');
		}
		return array($start,$end);
	}
}
