<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/functions_phpbb20.php';
require_once __DIR__ . '/plus_absence.php';
require_once __DIR__ . '/plus_inline_editor.php';
require_once __DIR__ . '/plus_forums.php';
require_once __DIR__ . '/plus_topology.php';
require_once __DIR__ . '/plus_topic_readers.php';
require_once __DIR__ . '/plus_native.php';
require_once __DIR__ . '/plus_polls.php';
require_once __DIR__ . '/plus_removed_topics.php';
require_once __DIR__ . '/plus_profile_media.php';
require_once __DIR__ . '/plus_flags.php';
require_once __DIR__ . '/plus_custom_profiles.php';
require_once __DIR__ . '/plus_permissions.php';
require_once __DIR__ . '/plus_junior_admin.php';
require_once __DIR__ . '/plus_actions.php';
require_once __DIR__ . '/plus_account_logins.php';
require_once __DIR__ . '/plus_member_notice.php';
require_once __DIR__ . '/plus_credits.php';

function plus_acronym_text($value)
{
	return \phpbb2plus\community\service\acronym_dictionary::encode($value);
}
require_once __DIR__ . '/plus_cards.php';
require_once __DIR__ . '/plus_memberships.php';
require_once __DIR__ . '/plus_news.php';
require_once __DIR__ . '/plus_portal.php';
require_once __DIR__ . '/plus_statistics.php';
require_once __DIR__ . '/plus_banners.php';
require_once __DIR__ . '/plus_weblinks.php';
require_once __DIR__ . '/plus_colours.php';
require_once __DIR__ . '/plus_post_icons.php';
require_once __DIR__ . '/plus_attachments.php';
require_once __DIR__ . '/plus_attachment_quotas.php';
require_once __DIR__ . '/plus_gallery.php';
require_once __DIR__ . '/plus_knowledgebase.php';
require_once __DIR__ . '/plus_arcade.php';
require_once __DIR__ . '/plus_arcade_media.php';
require_once __DIR__ . '/plus_arcade_wallets.php';
require_once __DIR__ . '/plus_downloads.php';
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/password/legacy.php';
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/profile/contacts.php';
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/service/former_members.php';

/** Built-in account columns can never be a custom profile field or Arcade wallet. */
function plus_core_account_columns()
{
	return explode(' ',
		'user_id user_active username user_password user_session_time user_session_page user_session_topic user_lastvisit' . ' ' .
		'user_regdate user_reg_ip user_reg_host user_level user_posts user_timezone user_style user_lang user_dateformat' . ' ' .
		'user_new_privmsg user_unread_privmsg user_last_privmsg user_login_tries user_last_login_try user_emailtime' . ' ' .
		'user_viewemail user_attachsig user_setbm user_allowhtml user_allowbbcode user_allowsmile user_allowavatar' . ' ' .
		'user_allow_pm user_allow_viewonline user_notify user_notify_pm user_popup_pm user_rank user_avatar' . ' ' .
		'user_avatar_type user_email user_icq user_website user_from user_from_flag user_sig user_sig_bbcode_uid user_aim' . ' ' .
		'user_yim user_msnm user_fb user_ig user_pt user_twr user_skp user_tg user_li user_tt user_dc user_signal' . ' ' .
		'user_threema user_occ user_interests user_actkey user_newpasswd ct_last_pw_reset ct_enable_ip_warn' . ' ' .
		'ct_last_used_ip ct_last_ip ct_last_pw_change ct_global_msg_read ct_miserable_user user_sub_forum user_split_cat' . ' ' .
		'user_last_topic_title user_sub_level_links user_display_viewonline user_birthday user_next_birthday_greeting' . ' ' .
		'user_gender user_color_group user_lastlogon user_totaltime user_totallogon user_totalpages' . ' ' .
		'user_calendar_display_open user_calendar_header_cells user_calendar_week_start user_calendar_nb_row' . ' ' .
		'user_calendar_birthday user_calendar_forum user_warnings user_passwd_change user_badlogin user_blocktime' . ' ' .
		'user_block_by user_split_global_announce user_split_announce user_split_sticky user_split_news' . ' ' .
		'user_split_topic_split user_absence user_absence_mode user_absence_text user_announcement_date_display' . ' ' .
		'user_announcement_display user_announcement_display_forum user_announcement_split user_announcement_forum' . ' ' .
		'user_use_ajax_preview user_use_ajax_edit games_block_pm arcade_banned');
}

function plus_birthday_year($year)
{
	return max(0, min(9999, (int) $year));
}

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

function plus_legacy_account_id($source_id)
{
	// The upstream converter maps a real source user 1 away from native Anonymous.
	return max(0, (int) $source_id);
}

function plus_registration_ip($ip)
{
	$ip = trim((string) $ip);
	if (filter_var($ip, FILTER_VALIDATE_IP) !== false) { return $ip; }
	return preg_match('/^[0-9a-f]{8}$/iD', $ip) ? decode_ip($ip) : '';
}

function plus_calendar_end($start, $duration)
{
	$start = (int) $start; $duration = (int) $duration;
	if ($start < 0 || $duration < 0 || (!$start && $duration))
	{
		throw new \RuntimeException('A source topic has an invalid calendar interval. Review its date and duration.');
	}
	return $start + $duration;
}

function plus_removed_author($id, $name)
{
	global $src_db, $convert;
	if ((int) $id > 0) { return plus_existing_author($id) === ANONYMOUS ? 1 : 0; }
	$raw = html_entity_decode((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	if ($raw === '') { return 0; }
	static $found = array();
	if (!isset($found[$raw]))
	{
		$p = $convert->src_table_prefix;
		$result = $src_db->sql_query_limit("SELECT n.author_name_hash FROM {$p}removed_author_names n LEFT JOIN {$p}users u
			ON u.user_id=n.author_user_id WHERE (n.author_user_id=0 OR u.user_id IS NULL) AND n.author_name='" . $src_db->sql_escape($raw) . "'", 1);
		$found[$raw] = $src_db->sql_fetchfield('author_name_hash') !== false ? 1 : 0;
		$src_db->sql_freeresult($result);
	}
	return $found[$raw];
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

function plus_post_owner($verified, $removed)
{
	return (int) $removed || (int) $verified <= 0 ? ANONYMOUS : phpbb_user_id($verified);
}

function plus_topic_owner($topic, $original, $verified)
{
	global $convert, $src_db;
	if ((int) $verified > 0 || (int) $original === -1) { return plus_post_owner($verified, 0); }
	// The Plus author repair used the first post only when the topic's own
	// account no longer existed. The stored first-post pointer can also be stale.
	$p = $convert->src_table_prefix;
	$result = $src_db->sql_query_limit("SELECT u.user_id, p.post_author_removed FROM {$p}posts p
		LEFT JOIN {$p}users u ON u.user_id=p.poster_id WHERE p.topic_id=" . (int) $topic . ' ORDER BY p.post_id', 1);
	$row = $src_db->sql_fetchrow($result);
	$src_db->sql_freeresult($result);
	return $row ? plus_post_owner($row['user_id'], $row['post_author_removed']) : ANONYMOUS;
}

function plus_post_removed($removed, $original, $verified)
{
	return (int) $removed || ((int) $original > 0 && (int) $verified <= 0);
}

function plus_convert_author($name, $removed, $original, $verified)
{
	global $config;
	if (plus_post_removed($removed, $original, $verified))
	{
		return $config['default_lang'] === 'de' ? 'Ehemaliger Nutzer' : 'Former member';
	}
	return plus_convert_byline($name);
}

function plus_convert_byline($name)
{
	return utf8_encode_ucr(utf8_htmlspecialchars(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
}

function plus_retained_byline($name, $removed, $original, $verified)
{
	return plus_post_removed($removed, $original, $verified) ? plus_convert_byline($name) : '';
}

function plus_topic_description($source)
{
	// The Plus editor stored entity-escaped plain text. Decode exactly one layer.
	return htmlspecialchars_decode(plus_convert_byline($source),ENT_QUOTES);
}

function plus_search_author_clean($name,$poster,$removed)
{
	return (int) $removed || (int) $poster <= 0 ? \phpbb2plus\community\service\former_members::stored_clean(plus_convert_byline($name)) : '';
}

function plus_search_author_key($name,$poster,$removed)
{
	$clean = (int) $removed || (int) $poster <= 0 ? \phpbb2plus\community\service\former_members::clean(plus_convert_byline($name)) : '';
	return $clean === '' ? '' : hash('sha256',$clean);
}

function plus_convert_editor($id, $count, $poster)
{
	if (!(int) $count) { return 0; }
	return plus_existing_author($id === null ? $poster : $id);
}

function plus_existing_author($id)
{
	global $src_db, $convert;
	static $users = array();
	$id = (int) $id;
	if ($id <= 0) { return ANONYMOUS; }
	if (!isset($users[$id]))
	{
		$result = $src_db->sql_query('SELECT user_id FROM ' . $convert->src_table_prefix . 'users WHERE user_id=' . $id);
		$exists = $src_db->sql_fetchfield('user_id') !== false;
		$src_db->sql_freeresult($result);
		$users[$id] = $exists ? phpbb_user_id($id) : ANONYMOUS;
	}
	return $users[$id];
}

function plus_name_lookup_key($name)
{
	return hash('sha256', \phpbb2plus\community\service\former_members::clean($name));
}

function plus_convert_visibility($deleted)
{
	return (int) $deleted ? ITEM_DELETED : ITEM_APPROVED;
}

function plus_announcement_limit($type,$days,$field)
{
	if (!\phpbb2plus\community\service\announcements::is_announcement($type)) { return 0; }
	$default = get_config_value('announcement_duration');
	$values = \phpbb2plus\community\service\announcements::storage($days,$default === false ? 7 : (int) $default);
	return $values[$field === 'seconds' ? 0 : 1];
}

function plus_shout_flags($bbcode, $smilies)
{
	return OPTION_FLAG_LINKS | ($bbcode ? OPTION_FLAG_BBCODE : 0) | ($smilies ? OPTION_FLAG_SMILIES : 0);
}

function plus_shout_removed($marked, $id)
{
	return (int) $marked || ((int) $id > 0 && plus_existing_author($id) === ANONYMOUS) ? 1 : 0;
}

function plus_shout_moderator($id)
{
	return (int) $id < 0 ? ANONYMOUS : plus_convert_optional_user($id);
}

function plus_convert_optional_user($id)
{
	return (int) $id > 0 ? phpbb_user_id($id) : 0;
}

function plus_reset_converted_formatter()
{
	global $phpbb_container;
	// Rebuild in the running board with all of its native extension listeners.
	require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/service/smilie_sign.php';
	\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
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
	$xml = phpbb_prepare_message((string) $message);
	// The standalone installer may build its formatter before extensions are loaded.
	if ((!isset($convert->row['enable_bbcode']) || $convert->row['enable_bbcode']) && stripos($xml, '[schild=') !== false &&
		strpos($xml, '<PLUSSMILIESIGN ') === false && strpos($xml, '<SCHILD') === false)
	{
		require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/service/smilie_sign.php';
		require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/service/legacy_smilie_signs.php';
		$xml = \phpbb2plus\community\service\legacy_smilie_signs::upgrade($xml);
	}
	return $xml;
}

function plus_orphan_post_data($subject, $uid, $text)
{
	// JSON preserves NULL and original markup. ASCII escapes also carry four-byte
	// characters through the native mysqli destination's three-byte connection.
	return json_encode(array('post_subject' => $subject, 'bbcode_uid' => $uid, 'post_text' => $text),
		JSON_THROW_ON_ERROR);
}
