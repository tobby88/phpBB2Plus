<?php
if (PHP_SAPI !== 'cli') { exit(2); }

require dirname(__DIR__,2) . '/phpBB3/ext/phpbb2plus/calendar/service/period.php';
use phpbb2plus\calendar\service\period;

$zone = new DateTimeZone('Europe/Berlin');
foreach (array('2021-03-28'=>23,'2021-10-31'=>25) as $day=>$hours)
{
	$fields = array('start_date'=>$day,'end_date'=>$day,'start_time'=>'','end_time'=>'');
	$times = period::parse($fields,false,array(0,0),$zone);
	if ($times[1]-$times[0]+1 !== $hours * 3600 || period::values($times[0],$times[1],$zone) != $fields)
	{
		throw new RuntimeException('A civil all-day event did not round-trip through its clock change.');
	}
}
foreach (array('2021-10-31T02:15:37+02:00','2021-10-31T02:15:37+01:00') as $occurrence)
{
	$time = (new DateTimeImmutable($occurrence))->getTimestamp();
	$old = array($time,$time+11);
	if ($old !== period::parse(period::values($old[0],$old[1],$zone),false,$old,$zone))
	{
		throw new RuntimeException('A repeated local time or sub-minute duration changed.');
	}
}
try
{
	period::parse(array('start_date'=>'2021-03-28','start_time'=>'02:30','end_date'=>'','end_time'=>''),false,array(0,0),$zone);
	throw new RuntimeException('The editor accepted a nonexistent civil time.');
}
catch (DomainException $expected) {}
