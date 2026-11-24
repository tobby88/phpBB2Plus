<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/functions_phpbb20.php';
require_once __DIR__ . '/plus_forums.php';
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/password/legacy.php';

function plus_convert_locale($name)
{
	global $config;
	$locales = array('english' => 'en', 'german' => 'de', 'en' => 'en', 'de' => 'de');
	return isset($locales[$name]) ? $locales[$name] : $config['default_lang'];
}

function plus_convert_author($name, $removed)
{
	global $config;
	if ((int) $removed)
	{
		return $config['default_lang'] === 'de' ? 'Ehemaliger Nutzer' : 'Former member';
	}
	return plus_convert_byline($name);
}

function plus_convert_byline($name)
{
	return utf8_encode_ucr(utf8_htmlspecialchars(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
}

function plus_retained_byline($name, $removed)
{
	return (int) $removed ? plus_convert_byline($name) : '';
}

function plus_convert_editor($id, $count, $poster)
{
	if (!(int) $count) { return 0; }
	if ((int) $id > 0) { return phpbb_user_id($id); }
	return $id === null && (int) $poster > 0 ? phpbb_user_id($poster) : ANONYMOUS;
}

function plus_convert_visibility($deleted)
{
	return (int) $deleted ? ITEM_DELETED : ITEM_APPROVED;
}

function plus_convert_optional_user($id)
{
	return (int) $id > 0 ? phpbb_user_id($id) : 0;
}

function plus_convert_message($message)
{
	global $convert, $convert_row;
	// The upstream converter treats the one-character post "0" as empty.
	if ($message === '0')
	{
		$convert->row['mp_bbcode_bitfield'] = $convert_row['mp_bbcode_bitfield'] = '';
		return '<t>0</t>';
	}
	return phpbb_prepare_message((string) $message);
}
