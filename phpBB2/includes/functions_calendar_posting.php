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

function calendar_post_day_boundary($time, $days)
{
	$time = calendar_post_number($time,2147483647); $days = calendar_post_number($days,24855);
	if ($time === null || $days === null) { return null; }
	try
	{
		$zone = new DateTimeZone(date_default_timezone_get()); $utc = new DateTimeZone('UTC');
		$first = (new DateTimeImmutable('@' . $time))->setTimezone($zone);
		// Choose the nominal civil date in UTC, then its first local instant.
		// This also handles a missing midnight or a skipped calendar date.
		$date = (new DateTimeImmutable($first->format('Y-m-d') . ' 00:00:00',$utc))->modify('+' . $days . ' days')->format('Y-m-d');
		return new DateTimeImmutable($date . ' 00:00:00',$zone);
	}
	catch (Exception $error) { return null; }
	catch (Error $error) { return null; }
}

function calendar_post_day_duration($time, $days)
{
	$first = calendar_post_day_boundary($time,0); $last = calendar_post_day_boundary($time,$days);
	if ($first === null || $last === null) { return null; }
	try
	{
		$utc = new DateTimeZone('UTC');
		// Subtract in UTC: DateTime's local diff describes calendar components,
		// while storage needs actual elapsed seconds, even on 32-bit PHP.
		$span = $first->setTimezone($utc)->diff($last->setTimezone($utc));
		if ($span->invert) { return null; }
		$duration = 0;
		foreach (array(array((int)$span->format('%a'),86400),array($span->h,3600),array($span->i,60),array($span->s,1)) as $part)
		{
			if ($part[0] > (int)floor((2147483647-$duration)/$part[1])) { return null; }
			$duration += $part[0]*$part[1];
		}
		return $duration;
	}
	catch (Exception $error) { return null; }
	catch (Error $error) { return null; }
}

// Saved duration+1 can be exactly 2^31 on a 32-bit host. Keep that single
// representable float for display; the final stored integer is still <= INT_MAX.
function calendar_post_form_duration($duration)
{
	$value = calendar_post_number($duration,2147483647);
	if ($value !== null) { return $value; }
	return $duration === '2147483648' || $duration === 2147483648 || $duration === 2147483648.0 ? 2147483648.0 : null;
}

function calendar_post_civil_day_count($time, $duration)
{
	$time = calendar_post_number($time,2147483647); $duration = calendar_post_form_duration($duration);
	if (!$time || !$duration) { return null; }
	try
	{
		$zone = new DateTimeZone(date_default_timezone_get()); $utc = new DateTimeZone('UTC');
		$first = (new DateTimeImmutable('@' . $time))->setTimezone($zone);
		$boundary = calendar_post_day_boundary($time,0);
		if ($boundary === null || $boundary->format('U') !== (string)$time) { return null; }
		$last = $first->setTimezone($utc)->modify('+' . sprintf('%.0f',$duration) . ' seconds')->setTimezone($zone);
		$a = new DateTimeImmutable($first->format('Y-m-d') . ' 00:00:00',$utc);
		$b = new DateTimeImmutable($last->format('Y-m-d') . ' 00:00:00',$utc);
		$days = (int)$a->diff($b)->format('%a');
		$boundary = calendar_post_day_boundary($time,$days);
		if ($days > 0 && $boundary !== null && $boundary->format('U') === $last->format('U'))
		{
			// A skipped date can give +1 day and +2 days the same boundary.
			// Keep the smallest equivalent span; the stored interval is exact.
			while ($days > 1)
			{
				$previous = calendar_post_day_boundary($time,$days-1);
				if ($previous === null || $previous->format('U') !== $last->format('U')) { break; }
				$days--;
			}
			return $days;
		}
	}
	catch (Exception $error) { return null; }
	catch (Error $error) { return null; }
	return null;
}

function calendar_post_is_all_day($time, $duration)
{
	$time = calendar_post_number($time,2147483647);
	return $time && (date('H:i:s',$time) === '00:00:00' || calendar_post_civil_day_count($time,$duration) !== null);
}

function calendar_post_duration_parts($time, $duration)
{
	$time = calendar_post_number($time,2147483647); $duration = calendar_post_form_duration($duration);
	if (!$time || !$duration) { return array('day'=>0,'hour'=>0,'min'=>0); }
	$civil_days = calendar_post_civil_day_count($time,$duration);
	if ($civil_days !== null) { return array('day'=>$civil_days,'hour'=>0,'min'=>0); }
	$days = (int)floor($duration/86400); $rest = $duration-$days*86400;
	return array('day'=>$days,'hour'=>(int)floor($rest/3600),'min'=>(int)floor(($rest%3600)/60));
}

// Form duration remains exclusive until the final call to submit_post().
// Keep the calendar's server-local date policy and existing clear-date action.
function calendar_post_input($source, $existing_time = 0, $existing_duration = null)
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
	$all_day = $f['hour'] === 0 && $f['min'] === 0;
	if ($all_day && date('Y-m-d',$time) === substr($tuple,0,10))
	{
		$boundary = calendar_post_day_boundary($time,0);
		$time = $boundary === null ? null : calendar_post_number($boundary->format('U'),2147483647);
	}
	elseif (date('Y-m-d H:i',$time) !== $tuple) { $result['error'] = 'Calendar_input_error'; return $result; }
	if ($time === null || ($all_day && date('Y-m-d',$time) !== substr($tuple,0,10))) { $result['error'] = 'Calendar_input_error'; return $result; }
	// A repeated autumn hour has two possible timestamps. An unchanged form
	// must retain its saved occurrence instead of choosing the other one.
	$existing_time = calendar_post_number($existing_time,2147483647);
	if ($existing_time && (date('Y-m-d H:i',$existing_time) === $tuple || ($all_day && date('Y-m-d',$existing_time) === substr($tuple,0,10) && calendar_post_is_all_day($existing_time,$existing_duration)))) { $time = $existing_time; }
	$duration = $all_day ? calendar_post_day_duration($time,$f['duration_day']) : 0;
	if ($duration === null) { $result['error'] = 'Calendar_input_error'; return $result; }
	foreach ($all_day ? array() : array('duration_day'=>86400,'duration_hour'=>3600,'duration_min'=>60) as $key=>$unit)
	{
		if ($f[$key] > (int) floor((2147483647 - $duration) / $unit)) { $result['error'] = 'Calendar_input_error'; return $result; }
		$duration += $f[$key] * $unit;
	}
	// Preserve an unchanged saved interval, including legacy sub-minute values
	// or historical fixed-day durations the minute-only form cannot encode.
	$existing_duration = calendar_post_form_duration($existing_duration);
	if ($existing_time && $time === $existing_time && $existing_duration !== null)
	{
		$parts = calendar_post_duration_parts($time,$existing_duration);
		if ($all_day) { $parts['hour'] = $parts['min'] = 0; }
		if ($parts === array('day'=>$f['duration_day'],'hour'=>$all_day?0:$f['duration_hour'],'min'=>$all_day?0:$f['duration_min'])) { $duration = $existing_duration; }
	}
	$result['time'] = $time; $result['duration'] = $duration;
	return $result;
}
