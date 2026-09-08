<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// The title column holds 60 Unicode characters including escaped HTML entities.
// Input is plain UTF-8, without legacy request slashes; false means invalid text.
function phpbb_storage_subject($value)
{
	if (!is_string($value) || preg_match_all('/./us', $value, $characters) === false) { return false; }
	$result = ''; $length = 0;
	foreach ($characters[0] as $character)
	{
		$escaped = htmlspecialchars($character, ENT_COMPAT, 'UTF-8');
		$size = $escaped === $character ? 1 : strlen($escaped);
		if ($length + $size > 60) { break; }
		$result .= $escaped; $length += $size;
	}
	return $result;
}
