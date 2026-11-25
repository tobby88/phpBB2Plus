<?php
namespace phpbb2plus\downloads\service;

class catalogue_selection
{
	public static function ids($values, $error)
	{
		if (!is_array($values) || !$values || count($values)>500) { throw new \DomainException($error); }
		$ids = array();
		foreach ($values as $value)
		{
			if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $value) ||
				$value > 4294967295 || isset($ids[$value])) { throw new \DomainException($error); }
			$ids[(int) $value] = (int) $value;
		}
		sort($ids,SORT_NUMERIC);
		return $ids;
	}
}
