<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/functions_phpbb20.php';
require_once __DIR__ . '/plus_forums.php';
require_once __DIR__ . '/plus_native.php';
require_once __DIR__ . '/plus_attachments.php';
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/password/legacy.php';
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/profile/contacts.php';

function plus_convert_birthday($days)
{
	if ($days === null || $days === '' || (int) $days === 999999) { return ' 0- 0-   0'; }
	// Niels' Birthday MOD stores signed days since the Unix epoch (zero is a real birthday).
	$date = (new \DateTimeImmutable('1970-01-01', new \DateTimeZone('UTC')))->modify(sprintf('%+d days', (int) $days));
	if (!$date || (int) $date->format('Y') < 1901 || (int) $date->format('Y') > 2099)
	{
		throw new \RuntimeException('A Plus birthday lies outside the source calendar range.');
	}
	return sprintf('%2d-%2d-%4d', $date->format('j'), $date->format('n'), $date->format('Y'));
}

function plus_registration_ip($ip)
{
	$ip = trim((string) $ip);
	if (filter_var($ip, FILTER_VALIDATE_IP) !== false) { return $ip; }
	return preg_match('/^[0-9a-f]{8}$/iD', $ip) ? decode_ip($ip) : '';
}

function plus_configure_native_contacts()
{
	global $db;
	// The Plus profile accepts either an account name or a pasted service URL.
	// Keep both editable in the same two native fields after conversion.
	$patterns = array('phpbb_facebook' => '(?:@?[\w.-]+|(?:https?://)?(?:[\w-]+\.)*facebook\.com/[^\s]+)',
		'phpbb_twitter' => '(?:@?[\w]+|(?:https?://)?(?:[\w-]+\.)*(?:x|twitter)\.com/[^\s]+)');
	foreach ($patterns as $ident => $pattern)
	{
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',
			array('field_minlen' => '0', 'field_maxlen' => '255', 'field_validation' => $pattern))
			. " WHERE field_ident='" . $db->sql_escape($ident) . "'");
	}
	$inactive = array('phpbb_icq', 'phpbb_yahoo', 'phpbb_skype', 'phpbb_wlm', 'phpbb_aol');
	$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET field_active=0 WHERE ' . $db->sql_in_set('field_ident', $inactive));
}

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

function plus_shout_flags($bbcode, $smilies)
{
	return OPTION_FLAG_LINKS | ($bbcode ? OPTION_FLAG_BBCODE : 0) | ($smilies ? OPTION_FLAG_SMILIES : 0);
}

function plus_shout_moderator($id)
{
	return (int) $id < 0 ? ANONYMOUS : plus_convert_optional_user($id);
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
