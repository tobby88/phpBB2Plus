<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function album_photo_description_limit($value)
{
	return is_scalar($value) ? max(0, min(65535, (int) $value)) : 0;
}

// Logical Unicode limits precede HTML escaping; work identically without mbstring.
function album_photo_plain_text($value, $limit)
{
	if ((!is_scalar($value) && $value !== null) || !is_int($limit) || $limit < 1 || $limit > 65535) { return ''; }
	$value = substr(trim(stripslashes((string) $value)), 0, $limit * 4 + 4);
	$value = html_entity_decode(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), ENT_QUOTES, 'UTF-8');
	$characters = preg_split('//u', $value, $limit + 2, PREG_SPLIT_NO_EMPTY);
	if ($characters === false) { return ''; }
	return implode('', array_slice($characters, 0, $limit));
}

function album_photo_text($value, $limit)
{
	return htmlspecialchars(album_photo_plain_text($value, $limit), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
}
