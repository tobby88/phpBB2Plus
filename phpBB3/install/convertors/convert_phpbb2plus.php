<?php
if (!defined('IN_PHPBB')) { exit; }

if (!$get_info)
{
	foreach (array('community','gallery','knowledgebase','arcade','downloads','calendar','news','colours','banners','weblinks') as $module)
	{
		$extension = 'phpbb2plus/' . $module;
		$result = $db->sql_query("SELECT ext_active FROM " . EXT_TABLE . " WHERE ext_name = '" . $db->sql_escape($extension) . "'");
		$enabled = $db->sql_fetchfield('ext_active');
		$db->sql_freeresult($result);
		if (!$enabled) { throw new \RuntimeException('Enable ' . $extension . ' on the destination before starting the conversion.'); }
	}
}

// Use phpBB's native mapping for accounts, groups, ACLs, posts, polls, PMs
// and attachments. Change only the fields for which the Plus schema differs.
include __DIR__ . '/convert_phpbb20.php';

$convertor_data['forum_name'] = 'phpBB2 Plus (UTF-8)';
$convertor_data['version'] = '0.1.0';
$test_file = 'includes/functions_post_author.php';
$tables[] = 'bookmarks';
$tables[] = 'news';
$tables[] = 'portal';
$tables[] = 'plus';
$tables[] = 'color_groups';
$tables[] = 'post_icon_config';
$tables[] = 'banner';
$tables[] = 'banner_stats';
if (defined('MOD_ATTACHMENT')) { $tables[] = 'quota_limits'; $tables[] = 'attach_quota'; }

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
	$convertor['execute_first'] = str_replace(array('import_avatar_gallery();', 'phpbb_insert_forums();'),
		array('plus_import_avatar_gallery();', 'plus_insert_forums();'), $convertor['execute_first']);
	$convertor['execute_first'] .= ' plus_import_admin_contact();';
	// Schema hooks run after the wizard resets the destination media issue report.
	$convertor['schema'][0]['execute_first'] = ($convertor['schema'][0]['execute_first'] ?? '') . ' plus_import_post_icons();';
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_media_issues');
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_action_import');
	foreach (array(POLL_VOTES_TABLE, $table_prefix . 'plus_poll_review', $table_prefix . 'plus_poll_choices', $table_prefix . 'plus_poll_receipts') as $table)
	{
		$convertor['query_first'][] = array('target', $convert->truncate_statement . $table);
	}
	// A fresh conversion replaces native accounts; old locally managed IDs must not follow them.
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_jr_delegations');
	// Category listing grants depend on the final visibility of their descendant forums.
	$convertor['execute_last'][] = 'plus_forum_permissions();';
	$convertor['execute_last'][] = 'plus_restore_forum_tree();';
	$convertor['execute_last'][] = 'plus_banner_next_id();';
	$convertor['execute_last'][] = 'plus_enable_post_icons();';
	$convertor['execute_last'][] = 'plus_configure_native_contacts();';
	$convertor['execute_last'][] = 'plus_sync_new_pms();';
	$convertor['execute_last'][] = 'plus_publish_poll_receipts();';
	$convertor['execute_last'][] = 'plus_kb_moderators();';
	$convertor['execute_last'][] = 'plus_gallery_personal_permissions();';
	$convertor['execute_last'][] = 'plus_jr_finish();';
	$convertor['execute_last'][] = 'plus_finish_action_log();';
	$convertor['execute_last'][] = 'plus_login_settings();';
	$convertor['execute_last'][] = 'plus_import_member_notice();';
	$convertor['execute_last'][] = 'plus_card_finish();';
	$convertor['execute_last'][] = 'plus_arcade_grants();';
	if (defined('MOD_ATTACHMENT'))
	{
		$convertor['execute_last'][] = 'plus_quota_defaults();';
	}
	$convertor['execute_last'][] = 'plus_finish_news();';
	$convertor['execute_last'][] = 'plus_portal_settings();';
	$convertor['execute_last'][] = 'plus_statistics_settings();';
	$convertor['execute_last'][] = 'plus_finish_colours();';
	// Native dynamic totals precede its per-topic resynchronization. Recount only
	// after that pass has settled visibility and removed empty redirect records.
	if (!empty($config['plus_removed_recount_due']) && $request->variable('final_jump', 0) === 1)
	{
		\phpbb2plus\community\service\removed_topics::refresh_counts($db, $config);
		$config->delete('plus_removed_recount_due');
	}
	// The native converter resets default-group colours after all execute_last callbacks.
	// Apply the imported priorities when its next, resumable forum-sync stage opens.
	if (!empty($config['plus_convert_colours_due']) && $request->variable('jump',0) === 2)
	{
		plus_apply_converted_colours();
	}
	$config_schema['settings']['default_lang'] = 'plus_convert_locale(default_lang)';
	$config_schema['settings']['plus_hide_former_names'] = 'hide_removed_author_names';
	$config_schema['settings']['plus_card_threshold'] = 'plus_card_threshold(max_user_bancard)';
	$config_schema['settings']['plus_announce_default'] = 'announcement_duration';
	$config_schema['settings']['plus_announce_sticky'] = 'announcement_prune_strategy';
	foreach (array('publish'=>'allow_news','page_size'=>'news_item_num','trim'=>'news_item_trim','title_trim'=>'news_title_trim',
		'feed'=>'allow_rss','feed_limit'=>'news_rss_item_count','feed_abstract'=>'news_rss_show_abstract',
		'feed_language'=>'news_rss_language','feed_ttl'=>'news_rss_ttl') as $new=>$old)
	{
		$config_schema['settings']['plus_news_' . $new] = $old;
	}
	foreach (\phpbb2plus\calendar\service\settings::MEMBER + \phpbb2plus\calendar\service\settings::BOARD as $name=>$default)
	{
		$config_schema['settings']['plus_cal_' . $name] = $name === 'birthday_days' ? 'birthday_check_day' : 'calendar_' . $name;
		if (isset(\phpbb2plus\calendar\service\settings::MEMBER[$name])) { $config_schema['settings']['plus_cal_' . $name . '_over'] = 'calendar_' . $name . '_over'; }
	}
	if (defined('MOD_ATTACHMENT'))
	{
		$convertor['schema'] = array_values(array_filter($convertor['schema'], static function ($section) {
			return $section['target'] !== ATTACHMENTS_TABLE;
		}));
	}

	$schema = array();
	$published_pm = plus_published_pm_condition($src_db, $convert->src_table_prefix);
	foreach ($convertor['schema'] as $section)
	{
		if ($section['target'] === TOPICS_TABLE)
		{
			// A topic has exactly one native row even if several source polls claim it.
			$section['left_join'] = array_values(array_filter((array) $section['left_join'], static function ($join) {
				return strpos($join, 'LEFT JOIN vote_desc ') === false;
			}));
			foreach ($section as $index => $field)
			{
				if (is_int($index) && in_array($field[0], array('poll_title', 'poll_start', 'poll_length'), true))
				{
					$section[$index] = array($field[0], $field[0] === 'poll_title' ? '' : 0, '');
				}
			}
		}
		if ($section['target'] === POLL_OPTIONS_TABLE)
		{
			$schema[] = array('target' => POLL_OPTIONS_TABLE, 'primary' => 'vote_desc.vote_id', 'query_first' => $section['query_first'],
				array('', array('vote_desc.vote_id', 'vote_desc.topic_id', 'vote_desc.vote_text', 'vote_desc.vote_start', 'vote_desc.vote_length'), 'plus_import_poll'));
			continue;
		}
		if ($section['target'] === POLL_VOTES_TABLE)
		{
			$schema[] = plus_poll_receipt_schema($table_prefix);
			continue;
		}
		if (isset($section['where']) && in_array($section['target'], array(PRIVMSGS_TABLE, PRIVMSGS_TO_TABLE), true))
		{
			$section['where'] .= ' AND (' . $published_pm . ')';
		}
		if ($section['target'] === BANLIST_TABLE)
		{
			if (isset($section['query_first'])) { $schema = array_merge($schema, plus_card_ban_schema($section)); }
			continue;
		}
		if ($section['target'] === USER_GROUP_TABLE)
		{
			foreach (plus_membership_sections($section) as $members) { $schema[] = $members; }
			continue;
		}
		foreach ($section as $key => &$field)
		{
			if (!is_int($key)) { continue; }
			if ($field[2] === 'phpbb_prepare_message') { $field[2] = 'plus_convert_message'; }
			if ($field[0] === 'icon_id' && in_array($section['target'],array(TOPICS_TABLE,POSTS_TABLE),true))
			{
				$field = array('icon_id',$section['target'] === TOPICS_TABLE ? 'topics.topic_icon' : 'posts.post_icon','plus_post_icon_id');
			}
			if (in_array($field[0], array('post_attachment', 'topic_attachment', 'message_attachment'), true))
			{
				// Rebuild these markers from the attachments actually owned by each item.
				$field = array($field[0], 0, '');
			}

			if ($section['target'] === USERS_TABLE && $field[0] === 'user_password')
			{
				$field[2] = array('\phpbb2plus\community\password\legacy', 'import');
			}
			if ($section['target'] === USERS_TABLE && in_array($field[0], array('user_avatar','user_avatar_type','user_avatar_width','user_avatar_height'), true))
			{
				$field = array($field[0], array('users.user_id','users.user_allowavatar','users.user_avatar_type','users.user_avatar',$field[0]), 'plus_profile_avatar_field');
			}
			if ($section['target'] === RANKS_TABLE && $field[0] === 'rank_image')
			{
				$field = array('rank_image', array('ranks.rank_id','ranks.rank_image'), 'plus_profile_rank');
			}
			if ($section['target'] === USERS_TABLE && $field[0] === 'user_lang')
			{
				$field = array('user_lang', 'users.user_lang', 'plus_convert_locale');
			}
			if ($section['target'] === USERS_TABLE && $field[0] === 'user_birthday')
			{
				$field = array('user_birthday', 'users.user_birthday', 'plus_convert_birthday');
			}
			if ($section['target'] === USERS_TABLE && $field[0] === 'user_new_privmsg')
			{
				$field = array('user_new_privmsg', 0, '');
			}
			if ($section['target'] === PRIVMSGS_TO_TABLE && $field[0] === 'folder_id' && $field[1] === PRIVMSGS_INBOX)
			{
				$field = array('folder_id', 'privmsgs.privmsgs_type', 'plus_pm_incoming_folder');
			}
			if ($section['target'] === PRIVMSGS_TO_TABLE && $field[2] === 'phpbb_unread_pm')
			{
				$field[2] = 'plus_pm_unread';
			}
			if (in_array($section['target'], array(PRIVMSGS_TABLE, PRIVMSGS_TO_TABLE), true) && $field[0] === 'author_id')
			{
				$field[2] = 'plus_existing_author';
			}
			if ($section['target'] === PRIVMSGS_TABLE && $field[0] === 'to_address')
			{
				$field[2] = 'plus_pm_recipient';
			}
			if ($section['target'] === PRIVMSGS_TO_TABLE && $field[0] === 'user_id')
			{
				// Each native folder mapping chooses its own source owner. A
				// retired numeric ID can be reused by the converter for old ID 1.
				$peer = $field[1] === 'privmsgs.privmsgs_to_userid' ? 'privmsgs.privmsgs_from_userid' : 'privmsgs.privmsgs_to_userid';
				$section['left_join'] = array(
					'privmsgs LEFT JOIN users ON users.user_id = ' . $field[1],
					'privmsgs LEFT JOIN users AS pm_peer ON pm_peer.user_id = ' . $peer . ' AND privmsgs.privmsgs_type IN (1,5)',
				);
				// The original maintenance rules cancelled new and unread deliveries
				// if either participant had left. Completed copies need only their owner.
				$section['where'] .= ' AND users.user_id > 0 AND (privmsgs.privmsgs_type NOT IN (1,5) OR pm_peer.user_id > 0)';
			}
			if ($section['target'] === TOPICS_TABLE && $field[0] === 'topic_poster')
			{
				$joins = (array) $section['left_join'];
				$joins[] = 'topics LEFT JOIN users ON users.user_id = topics.topic_poster';
				$section['left_join'] = $joins;
				$field = array('topic_poster', array('topics.topic_id', 'topics.topic_poster AS poster_id', 'users.user_id AS plus_topic_author'), 'plus_topic_owner');
			}
			if ($section['target'] === POSTS_TABLE && $field[0] === 'poster_id')
			{
				$section['left_join'] = 'posts LEFT JOIN users ON users.user_id = posts.poster_id';
				$field = array('poster_id', array('users.user_id AS plus_post_author', 'posts.post_author_removed'), 'plus_post_owner');
			}
			if ($section['target'] === POSTS_TABLE && $field[0] === 'post_username')
			{
				$field = array('post_username', array('posts.post_username', 'posts.post_author_removed', 'posts.poster_id', 'users.user_id AS plus_post_author'), 'plus_convert_author');
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
		if ($section['target'] === SMILIES_TABLE)
		{
			$section = array('target' => SMILIES_TABLE, 'primary' => 'smilies.smilies_id', 'autoincrement' => 'smiley_id',
				'query_first' => array('target', $convert->truncate_statement . SMILIES_TABLE),
				array('', array('smilies.smilies_id', 'smilies.smile_url','smilies.code','smilies.emoticon'), 'plus_profile_smiley'));
		}
		if ($section['target'] === TOPICS_WATCH_TABLE)
		{
			$section = array(
				'target' => TOPICS_WATCH_TABLE,
				'query_first' => array('target', $convert->truncate_statement . TOPICS_WATCH_TABLE),
				'distinct' => true, 'order_by' => 'topics_watch.topic_id, topics_watch.user_id',
				array('topic_id', 'topics_watch.topic_id', 'intval'),
				array('user_id', 'topics_watch.user_id', 'phpbb_user_id'),
				array('notify_status', array('topics_watch.topic_id', 'topics_watch.user_id'), 'plus_watch_status'),
				'where' => 'topics_watch.user_id > 0 AND topics_watch.topic_id = topics.topic_id AND topics_watch.user_id = users.user_id',
			);
		}
		if ($section['target'] === USERS_TABLE)
		{
			$section[] = array('plus_colour_id','users.user_color_group','intval');
			$section[] = array('user_ip', 'users.user_reg_ip', 'plus_registration_ip');
			$section[] = array('plus_legacy_id', 'users.user_id', 'plus_legacy_account_id');
			$section[] = array('plus_last_login', 'users.user_lastlogon', 'intval');
			$source_tools = (new \phpbb\db\tools\factory())->get($src_db);
			if ($source_tools->sql_column_exists($convert->src_table_prefix . 'users', 'ct_global_msg_read'))
			{
				$section[] = array('plus_notice_pending', 'users.ct_global_msg_read', 'plus_tracker_flag');
			}
			if ($source_tools->sql_column_exists($convert->src_table_prefix . 'users', 'user_passwd_change'))
			{
				$section[] = array('user_passchg', 'users.user_passwd_change', 'plus_password_change_time');
				$section[] = array('plus_pw_native_origin', 'users.user_passwd_change', 'plus_password_change_time');
				$section[] = array('plus_pw_renewal_seen', 'users.user_passwd_change', 'plus_password_renewal_seen');
			}
			if ($source_tools->sql_column_exists($convert->src_table_prefix . 'users', 'ct_last_pw_change'))
			{
				$section[] = array('plus_pw_notice_change', 'users.ct_last_pw_change', 'plus_password_change_time');
			}
			foreach (array('ct_enable_ip_warn' => 'plus_login_warn', 'ct_last_ip' => 'plus_login_previous', 'ct_last_used_ip' => 'plus_login_current') as $old => $new)
			{
				if ($source_tools->sql_column_exists($convert->src_table_prefix . 'users', $old))
				{
					$section[] = array($new, 'users.' . $old, $old === 'ct_enable_ip_warn' ? 'plus_tracker_flag' : 'plus_login_address');
				}
			}
			$section[] = array('plus_card_count', array('users.user_id', 'users.user_warnings'), 'plus_card_count');
			foreach (\phpbb2plus\calendar\service\settings::MEMBER as $name=>$default)
			{
				$section[] = array('plus_cal_' . $name, 'users.user_calendar_' . $name, 'intval');
			}
		}
		if ($section['target'] === PRIVMSGS_TABLE) { $section[] = array('plus_legacy_pm', 1, ''); }
		if ($section['target'] === PRIVMSGS_FOLDER_TABLE) { $section[] = array('plus_legacy_savebox', 1, ''); }
		if ($section['target'] === GROUPS_TABLE)
		{
			$section[] = array('plus_legacy_group', 1, '');
			$section[] = array('plus_colour_id','groups.group_color_group','intval');
		}
		if ($section['target'] === TOPICS_TABLE)
		{
			$section[] = array('topic_time_limit',array('topics.topic_type','topics.topic_announce_duration','seconds'),'plus_announcement_limit');
			$section[] = array('plus_announce_days',array('topics.topic_type','topics.topic_announce_duration','days'),'plus_announcement_limit');
			$section[] = array('plus_description','topics.topic_desc','plus_topic_description');
			$section[] = array('plus_news_cat','topics.news_id','intval');
			$section[] = array('plus_cal_start','topics.topic_calendar_time','intval');
			$section[] = array('plus_cal_end',array('topics.topic_calendar_time','topics.topic_calendar_duration'),'plus_calendar_end');
		}
		if ($section['target'] === PROFILE_FIELDS_DATA_TABLE)
		{
			$section['execute_first'] = ($section['execute_first'] ?? '') . ' plus_import_flags(); plus_define_custom_profiles();';
			$section[] = array('pf_plus_country', 'users.user_from_flag', 'plus_country_value');
			foreach (plus_custom_profile_fields() as $id=>$custom)
			{
				$section[] = array('pf_' . $custom['ident'], array('users.' . $custom['source_column'], $id), 'plus_custom_account_value');
			}
			foreach (\phpbb2plus\community\profile\contacts::FIELDS as $ident => $definition)
			{
				$section[] = array('pf_' . $ident, 'users.' . $definition[0], 'plus_convert_byline');
			}
		}
		if ($section['target'] === POSTS_TABLE)
		{
			$section['left_join'] = (array) $section['left_join'];
			$section['left_join'][] = 'posts LEFT JOIN topics AS source_topic ON source_topic.topic_id=posts.topic_id';
			$section[] = array('plus_recovery_topic', array('posts.topic_id', 'source_topic.topic_id AS known_topic',
				'source_topic.topic_status AS old_status', 'source_topic.topic_moved_id AS old_move'), 'plus_recovery_post_topic');
			$section[] = array('plus_public_post', 1, '');
			$section[] = array('plus_legacy_erased', 'posts.post_deleted', 'plus_erased_post');
			$section[] = array('post_delete_time', 'posts.post_deleted_time', 'intval');
			$section[] = array('post_delete_user', 'posts.post_deleted_by', 'plus_convert_optional_user');
		}
		$schema[] = $section;
	}
	$convertor['schema'] = $schema;
	unset($schema);
	$convertor['schema'][] = array('target'=>$table_prefix . 'plus_colours', 'primary'=>'color_groups.group_id', 'autoincrement'=>'colour_id',
		'query_first'=>array('target',$convert->truncate_statement . $table_prefix . 'plus_colours'),
		array('colour_id','color_groups.group_id','intval'),array('name','color_groups.group_name','plus_convert_byline'),
		array('colour','color_groups.group_color','plus_colour_value'),array('in_legend','color_groups.hidden','plus_colour_legend'),
		array('position','color_groups.order_num','intval'));
	$convertor['schema'][] = array('target'=>$table_prefix . 'plus_news_cats', 'primary'=>'news.news_id', 'autoincrement'=>'category_id',
		'query_first'=>array('target',$convert->truncate_statement . $table_prefix . 'plus_news_cats'),
		array('category_id','news.news_id','intval'), array('name','news.news_category','plus_convert_byline'),
		array('image',array('news.news_id','news.news_image'),'plus_news_image'), array('active',1,''));
	if (defined('MOD_ATTACHMENT'))
	{
		$convertor['schema'][] = array(
			'target' => ATTACHMENTS_TABLE, 'primary' => 'attachments_desc.attach_id', 'autoincrement' => 'attach_id',
			'execute_first' => 'plus_check_attachment_source();',
			'query_first' => array(array('target', $convert->truncate_statement . ATTACHMENTS_TABLE),
				array('target', $convert->truncate_statement . $table_prefix . 'plus_attach_refs'),
				array('target', $convert->truncate_statement . $table_prefix . 'plus_attach_payers')),
			array('', 'attachments_desc.attach_id', 'plus_attachment_primary'),
		);
		$convertor['schema'][] = array(
			'target' => ATTACHMENTS_TABLE, 'primary' => 'attachments.attach_id',
			'group_by' => 'attachments.attach_id', 'having' => 'COUNT(*) > 1',
			array('', 'attachments.attach_id', 'plus_attachment_copies'),
		);
		$convertor['schema'] = array_merge($convertor['schema'],plus_quota_schema($table_prefix,$convert->truncate_statement));
	}
	$shouts_table = $table_prefix . 'plus_shouts';
	// Without a post header there is no trustworthy author, forum or visibility.
	$convertor['schema'][] = array(
		'target' => $table_prefix . 'plus_orphan_posts', 'primary' => 'posts_text.post_id',
		'query_first' => array('target', $convert->truncate_statement . $table_prefix . 'plus_orphan_posts'),
		array('source_id', 'posts_text.post_id', 'intval'),
		array('source_json', array('posts_text.post_subject', 'posts_text.bbcode_uid', 'posts_text.post_text'), 'plus_orphan_post_data'),
		'left_join' => 'posts_text LEFT JOIN posts ON posts.post_id = posts_text.post_id',
		'where' => 'posts.post_id IS NULL',
	);
	$convertor['schema'][] = array(
		'target' => $table_prefix . 'plus_former_names',
		'order_by' => 'removed_author_names.author_user_id, removed_author_names.author_name_hash',
		'query_first' => array('target', $convert->truncate_statement . $table_prefix . 'plus_former_names'),
		array('source_id', 'removed_author_names.author_user_id', 'intval'),
		array('native_id', 0, ''),
		array('name_hash', 'removed_author_names.author_name_hash', ''),
		array('name_key', 'removed_author_names.author_name', 'plus_name_lookup_key'),
		array('name_clean', 'removed_author_names.author_name', array('\phpbb2plus\community\service\former_members', 'stored_clean')),
		array('author_name', 'removed_author_names.author_name', 'utf8_encode_ucr'),
		array('recorded_at', 'removed_author_names.recorded_at', 'intval'),
		'left_join' => 'removed_author_names LEFT JOIN users ON users.user_id=removed_author_names.author_user_id',
		'where' => 'removed_author_names.author_user_id=0 OR users.user_id IS NULL',
	);
	$convertor['schema'][] = array(
		'target' => $shouts_table, 'primary' => 'shout.shout_id', 'autoincrement' => 'shout_id',
		'query_first' => array('target', $convert->truncate_statement . $shouts_table),
		array('shout_id', 'shout.shout_id', 'intval'),
		array('author_id', 'shout.shout_user_id', 'plus_existing_author'),
		array('author_name', 'shout.shout_username', 'plus_convert_byline'),
		array('author_removed', array('shout.shout_author_removed', 'shout.shout_user_id'), 'plus_shout_removed'),
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
		array('author_name', array('posts.post_username', 'posts.post_author_removed', 'posts.poster_id', 'users.user_id AS plus_post_author'), 'plus_retained_byline'),
		array('author_clean', array('posts.post_username', 'users.user_id AS plus_post_author', 'posts.post_author_removed'), 'plus_search_author_clean'),
		array('author_key', array('posts.post_username', 'users.user_id AS plus_post_author', 'posts.post_author_removed'), 'plus_search_author_key'),
		array('editor_name', 'posts.post_edit_username', 'plus_convert_byline'),
		'left_join' => 'posts LEFT JOIN users ON users.user_id = posts.poster_id',
		'where' => "posts.post_author_removed = 1 OR posts.post_edit_username <> '' OR ((posts.poster_id <= 0 OR users.user_id IS NULL) AND posts.post_username <> '')",
	);
	// Bylines are available and the search backend has not indexed these posts.
	$convertor['schema'][] = array(
		'target' => SEARCH_WORDMATCH_TABLE, 'primary' => 'posts.post_id', 'execute_first' => 'plus_recover_topic_routes(); plus_removed_topics();',
		array('', 'posts.post_id', 'plus_index_native_post'),
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
	if (plus_jr_source_present($src_db, $convert->src_table_prefix))
	{
		$convertor['schema'][] = plus_jr_schema($table_prefix, $convert->truncate_statement);
	}
	else
	{
		$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_jr_records');
	}
	$convertor['schema'] = array_merge($convertor['schema'], plus_gallery_schema($table_prefix, $convert->truncate_statement),
		plus_kb_schema($table_prefix, $convert->truncate_statement), plus_arcade_schema($table_prefix, $convert->truncate_statement),
		plus_dl_schema($table_prefix, $convert->truncate_statement), plus_banner_schema($table_prefix, $convert->truncate_statement));
	if (plus_weblink_source($src_db,$convert->src_table_prefix))
	{
		$convertor['schema'] = array_merge($convertor['schema'],plus_weblink_schema($table_prefix,$convert->truncate_statement));
		$convertor['execute_last'][] = 'plus_weblink_settings();';
	}
	$actions = plus_action_schema($src_db, $convert->src_table_prefix, $table_prefix);
	if ($actions) { $convertor['schema'][] = $actions; }
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_topic_readers');
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_readers_import');
	if ((new \phpbb\db\tools\factory())->get($src_db)->sql_table_exists($convert->src_table_prefix . 'topic_view'))
	{
		$convertor['schema'][] = plus_topic_readers_schema($table_prefix . 'plus_readers_import');
		$convertor['execute_last'][] = 'plus_publish_topic_readers();';
	}
	else { $convertor['execute_last'][] = '$config->set("plus_topic_readers", 0);'; }
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_account_logins');
	if ((new \phpbb\db\tools\factory())->get($src_db)->sql_table_exists($convert->src_table_prefix . 'ctracker_loginhistory'))
	{
		// Account ownership is established on the source before native ID remapping.
		$convertor['schema'][] = array('target' => $table_prefix . 'plus_account_logins', 'distinct' => true,
			'order_by' => 'users.user_id', array('', 'users.user_id', 'plus_import_account_logins'),
			array('', 'ctracker_loginhistory.ct_user_id', ''),
			'where' => 'ctracker_loginhistory.ct_user_id=users.user_id AND users.user_id>0');
	}
	$convertor['query_first'][] = array('target', $convert->truncate_statement . $table_prefix . 'plus_credits');
	if ((new \phpbb\db\tools\factory())->get($src_db)->sql_table_exists($convert->src_table_prefix . 'hacks_list'))
	{
		$convertor['schema'][] = plus_credits_schema($table_prefix);
	}
}
