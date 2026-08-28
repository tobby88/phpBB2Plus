<?php

define('IN_PHPBB', true);
$phpbb_root_path = dirname(__DIR__) . '/';
$phpEx = 'php';
$lang = array(
	'day_short' => array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'),
	'day_long' => array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
	'month_short' => array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'),
	'month_long' => array(
		'January', 'February', 'March', 'April', 'May', 'June',
		'July', 'August', 'September', 'October', 'November', 'December'
	),
);

require dirname(__DIR__) . '/includes/functions.php';

$result = realdate('Y-m-d', -1);
if ($result !== '1969-12-31')
{
	fwrite(STDERR, "Legacy negative-date conversion failed: $result\n");
	exit(1);
}

echo "Legacy negative-date conversion passed.\n";
