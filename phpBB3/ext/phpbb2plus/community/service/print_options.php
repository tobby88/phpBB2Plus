<?php
namespace phpbb2plus\community\service;

class print_options
{
	public static function filters($days, $order, $native = false)
	{
		$days = is_scalar($days) ? (int) $days : 0;
		$result = array();
		// phpBB2 used 52 weeks for a year. The native topic selector uses 365 days.
		if (in_array($days, array(1, 7, 14, 30, 90, 180, 364), true) || ($native && $days === 365))
		{
			$result['postdays'] = $days;
		}
		if ($order === 'desc') { $result['postorder'] = 'desc'; }
		return $result;
	}
}
