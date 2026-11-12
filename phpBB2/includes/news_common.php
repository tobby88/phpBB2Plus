<?php
// ********************************************************************************
// news_common.php
// Author : Nicholas Young-Soares
//
// Copyright (c) 2002-2003 Nicholas Young-Soares
// --------------------------------------------------------------------------------
// Version: 0.1
// Date Started: 08/10/2003
// Description:
//   Sets up all requirements for the news classes.
//
// Requirements:
//   Requires $phpbb_root_path to be set to the root dir of phpBB before this file
//   is included.
//
// --------------------------------------------------------------------------------
// Changelog:
// Version 0.1 - 08/10/2003
//   - Initial version.
//
// ********************************************************************************

// Make phpBB files think that they are in the phpBB enviroment.
//define( 'IN_PHPBB', 1 );

// Set up some constants.
define( 'DEFAULT_NUM_ITEMS', 10 );

define( 'SORT_DATE_DEC', 0 );
define( 'SORT_DATE_ASC', 1 );
define( 'SORT_ALPH_ASC', 2 );
define( 'SORT_ALPH_DEC', 3 );

// Archive links use UTC date components. Keep their bounds and grouping on
// that same clock, without native 32-bit mktime()/timestamp coercion.
function phpbb_news_archive_bounds($year, $month = 0, $day = 0)
{
  foreach (array($year, $month, $day) as $value)
  {
    if (!is_int($value) && !(is_string($value) && preg_match('/^[0-9]{1,4}$/D', $value) === 1)) { return null; }
  }
  $year = (int)$year; $month = (int)$month; $day = (int)$day;
  if ($year < 1 || $year > 2069 || $month < 0 || $month > 12 || $day < 0 || $day > 31 || ($day > 0 && ($month === 0 || !checkdate($month, $day, $year)))) { return null; }
  $start = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month ?: 1, $day ?: 1), new DateTimeZone('UTC'));
  if (!$start) { return null; }
  return array($start, $start->modify($day > 0 ? '+1 day' : ($month > 0 ? '+1 month' : '+1 year')));
}

function phpbb_news_utc_clock($epoch)
{
  if (!(is_int($epoch) || is_string($epoch)) || preg_match('/^-?[0-9]{1,12}$/D', (string)$epoch) !== 1) { return null; }
  $clock = DateTimeImmutable::createFromFormat('U', (string)$epoch);
  return $clock ? $clock->setTimezone(new DateTimeZone('UTC')) : null;
}

function phpbb_news_utc_text($format, $epoch)
{
  global $lang;
  $clock = phpbb_news_utc_clock($epoch);
  if (!$clock || !is_string($format)) { return ''; }
  $text = $clock->format($format);
  if (isset($lang['datetime']) && is_array($lang['datetime']))
  {
    $words = array();
    foreach ($lang['datetime'] as $from => $to)
    {
      if (is_string($from) && $from !== '' && is_string($to)) { $words[$from] = $to; }
    }
    if ($words) { $text = strtr($text, $words); }
  }
  return $text;
}
