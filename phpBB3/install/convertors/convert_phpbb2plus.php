<?php
if (!defined('IN_PHPBB')) { exit; }

if (!$get_info)
{
	$result = $db->sql_query("SELECT ext_active FROM " . EXT_TABLE . " WHERE ext_name = 'phpbb2plus/community'");
	$enabled = $db->sql_fetchfield('ext_active');
	$db->sql_freeresult($result);
	if (!$enabled)
	{
		throw new \RuntimeException('Enable phpBB2 Plus Community on the destination before starting the conversion.');
	}
}

// Use phpBB's native mapping for accounts, groups, ACLs, posts, polls, PMs
// and attachments. Change only the fields for which the Plus schema differs.
include __DIR__ . '/convert_phpbb20.php';

$convertor_data['forum_name'] = 'phpBB2 Plus (UTF-8)';
$convertor_data['version'] = '0.1.0';
$test_file = 'includes/functions_post_author.php';
$tables[] = 'bookmarks';

if (!$get_info)
{
	$lang_enc_array = array('english' => 'UTF-8', 'german' => 'UTF-8', 'en' => 'UTF-8', 'de' => 'UTF-8');
	$GLOBALS['lang_enc_array'] = $lang_enc_array;
	// All source data in this converter is UTF-8, irrespective of an old
	// account's language preference. Avoid loading PHP from legacy locales.
	$GLOBALS['lang_enc_array'][trim((string) get_config_value('default_lang'))] = 'UTF-8';
	$result = $src_db->sql_query('SELECT DISTINCT user_lang FROM ' . $convert->src_table_prefix . 'users');
	while ($source_language = $src_db->sql_fetchrow($result))
	{
		$GLOBALS['lang_enc_array'][trim((string) $source_language['user_lang'])] = 'UTF-8';
	}
	$src_db->sql_freeresult($result);
	$convertor['test_file'] = $test_file;
	$convertor['execute_last'][] = 'plus_restore_forum_tree();';
	$convertor['execute_last'][] = 'plus_configure_native_contacts();';
	$config_schema['settings']['default_lang'] = 'plus_convert_locale(default_lang)';
	$config_schema['settings']['plus_hide_former_names'] = 'hide_removed_author_names';

	foreach ($convertor['schema'] as &$section)
	{
		foreach ($section as $key => &$field)
		{
			if (!is_int($key)) { continue; }
			if ($field[2] === 'phpbb_prepare_message') { $field[2] = 'plus_convert_message'; }

			if ($section['target'] === USERS_TABLE && $field[0] === 'user_password')
			{
				$field[2] = array('\phpbb2plus\community\password\legacy', 'import');
			}
			if ($section['target'] === USERS_TABLE && $field[0] === 'user_lang')
			{
				$field = array('user_lang', 'users.user_lang', 'plus_convert_locale');
			}
			if ($section['target'] === USERS_TABLE && $field[0] === 'user_birthday')
			{
				$field = array('user_birthday', 'users.user_birthday', 'plus_convert_birthday');
			}
			if ($section['target'] === POSTS_TABLE && $field[0] === 'post_username')
			{
				$field = array('post_username', array('posts.post_username', 'posts.post_author_removed'), 'plus_convert_author');
			}
			if ($section['target'] === POSTS_TABLE && $field[0] === 'post_visibility')
			{
				$field = array('post_visibility', 'posts.post_deleted', 'plus_convert_visibility');
			}
			if ($section['target'] === POSTS_TABLE && $field[0] === 'post_edit_user')
			{
				$field = array('post_edit_user', array('posts.post_edit_user_id', 'posts.post_edit_count', 'posts.poster_id'), 'plus_convert_editor');
			}
		}
		unset($field);
		if ($section['target'] === USERS_TABLE)
		{
			$section[] = array('user_ip', 'users.user_reg_ip', 'plus_registration_ip');
		}
		if ($section['target'] === PROFILE_FIELDS_DATA_TABLE)
		{
			foreach (\phpbb2plus\community\profile\contacts::FIELDS as $ident => $definition)
			{
				$section[] = array('pf_' . $ident, 'users.' . $definition[0], 'plus_convert_byline');
			}
		}
		if ($section['target'] === POSTS_TABLE)
		{
			$section[] = array('post_delete_time', 'posts.post_deleted_time', 'intval');
			$section[] = array('post_delete_user', 'posts.post_deleted_by', 'plus_convert_optional_user');
		}
	}
	unset($section);
	$shouts_table = $table_prefix . 'plus_shouts';
	$convertor['schema'][] = array(
		'target' => $shouts_table, 'primary' => 'shout.shout_id', 'autoincrement' => 'shout_id',
		'query_first' => array('target', $convert->truncate_statement . $shouts_table),
		array('shout_id', 'shout.shout_id', 'intval'),
		array('author_id', 'shout.shout_user_id', 'phpbb_user_id'),
		array('author_name', 'shout.shout_username', 'plus_convert_byline'),
		array('author_removed', 'shout.shout_author_removed', 'intval'),
		array('shout_time', 'shout.shout_session_time', 'intval'),
		array('hidden_by', 'shout.shout_active', 'plus_shout_moderator'),
		array('author_ip', 'shout.shout_ip', 'decode_ip'),
		array('bbcode_uid', 'shout.shout_session_time', 'make_uid'),
		array('message', 'shout.shout_text', 'plus_convert_message'),
		array('bbcode_bitfield', '', 'get_bbcode_bitfield'),
		array('bbcode_options', array('shout.enable_bbcode', 'shout.enable_smilies'), 'plus_shout_flags'),
		array('', 'shout.shout_session_time AS post_time', ''),
		array('', 'shout.shout_id AS post_id', ''),
		array('', 'shout.shout_bbcode_uid AS old_bbcode_uid', ''),
	);
	$convertor['schema'][] = array(
		'target' => $table_prefix . 'plus_bylines',
		'primary' => 'posts.post_id',
		'query_first' => array('target', $convert->truncate_statement . $table_prefix . 'plus_bylines'),
		array('post_id', 'posts.post_id', 'intval'),
		array('author_name', array('posts.post_username', 'posts.post_author_removed'), 'plus_retained_byline'),
		array('editor_name', 'posts.post_edit_username', 'plus_convert_byline'),
		'where' => "posts.post_author_removed = 1 OR posts.post_edit_username <> ''",
	);
	$convertor['schema'][] = array(
		'target' => BOOKMARKS_TABLE,
		'query_first' => array('target', $convert->truncate_statement . BOOKMARKS_TABLE),
		'distinct' => true,
		'order_by' => 'bookmarks.topic_id, bookmarks.user_id',
		array('topic_id', 'bookmarks.topic_id', 'intval'),
		array('user_id', 'bookmarks.user_id', 'phpbb_user_id'),
		array('', 'topics.topic_id AS existing_topic', ''),
		array('', 'users.user_id AS existing_user', ''),
		'where' => 'bookmarks.user_id > 0 AND bookmarks.topic_id = topics.topic_id AND bookmarks.user_id = users.user_id',
	);
}
