<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Accept integral field/storage values without platform-dependent overflow,
// float coercion or turning malformed input into a request to remove an event.
function calendar_post_number($value, $maximum)
{
	if (!is_int($value) && !is_string($value)) { return null; }
	$value = trim((string) $value);
	if ($value === '') { return 0; }
	if (!preg_match('/^[0-9]{1,12}$/D', $value)) { return null; }
	$value = ltrim($value, '0');
	if ($value === '') { return 0; }
	$bound = (string) $maximum;
	if (strlen($value) > strlen($bound) || (strlen($value) === strlen($bound) && strcmp($value,$bound) > 0)) { return null; }
	return (int) $value;
}

// The current schema stores both columns as signed INT. Keep that contract
// explicit at every writer rather than relying on SQL modes to clip values.
// Duration is inclusive in storage; zero also represents a point event.
function calendar_post_stored_values($time, $duration)
{
	$time = calendar_post_number($time,2147483647);
	$duration = calendar_post_number($duration,2147483647);
	if ($time === null || $duration === null || ($time === 0 && $duration !== 0)) { return null; }
	return array('time'=>$time,'duration'=>$duration);
}

// Form duration remains exclusive until the final call to submit_post().
// Keep the calendar's server-local date policy and existing clear-date action.
function calendar_post_input($source, $existing_time = 0)
{
	$keys = array('year','month','day','hour','min','duration_day','duration_hour','duration_min');
	$result = array('time'=>0,'duration'=>0,'error'=>'','present'=>false,'fields'=>array());
	foreach ($keys as $key)
	{
		$name = 'topic_calendar_' . $key;
		if (is_array($source) && array_key_exists($name,$source)) { $result['present'] = true; }
		$value = is_array($source) && array_key_exists($name,$source) ? calendar_post_number($source[$name],2147483647) : 0;
		$result['fields'][$key] = $value === null ? '' : $value;
		if ($value === null) { $result['error'] = 'Calendar_input_error'; }
	}
	if ($result['error'] !== '') { return $result; }
	$f = $result['fields'];
	if ($f['year'] === 0 || $f['month'] === 0 || $f['day'] === 0) { return $result; }
	if (!checkdate($f['month'],$f['day'],$f['year']) || $f['hour'] > 23 || $f['min'] > 59)
	{
		$result['error'] = 'Calendar_input_error'; return $result;
	}
	if ($f['year'] < 1971 || $f['year'] > 2069) { $result['error'] = 'Calendar_date_range'; return $result; }
	$time = mktime($f['hour'],$f['min'],0,$f['month'],$f['day'],$f['year']);
	if ($time === false || $time < 0 || $time > 2147483647) { $result['error'] = 'Calendar_date_range'; return $result; }
	$tuple = sprintf('%04d-%02d-%02d %02d:%02d',$f['year'],$f['month'],$f['day'],$f['hour'],$f['min']);
	if (date('Y-m-d H:i',$time) !== $tuple) { $result['error'] = 'Calendar_input_error'; return $result; }
	// A repeated autumn hour has two possible timestamps. An unchanged form
	// must retain its saved occurrence instead of choosing the other one.
	$existing_time = calendar_post_number($existing_time,2147483647);
	if ($existing_time && date('Y-m-d H:i',$existing_time) === $tuple) { $time = $existing_time; }
	$duration = 0;
	foreach (array('duration_day'=>86400,'duration_hour'=>3600,'duration_min'=>60) as $key=>$unit)
	{
		if ($f[$key] > (int) floor((2147483647 - $duration) / $unit)) { $result['error'] = 'Calendar_input_error'; return $result; }
		$duration += $f[$key] * $unit;
	}
	// As before, all-day dates ignore sub-day duration controls.
	if ($f['hour'] === 0 && $f['min'] === 0) { $duration = $f['duration_day'] * 86400; }
	$result['time'] = $time; $result['duration'] = $duration;
	return $result;
}
