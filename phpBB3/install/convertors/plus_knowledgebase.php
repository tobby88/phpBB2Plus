<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_kb_plain($text)
{
	return html_entity_decode((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function plus_kb_setting($name, $value)
{
	if ($name !== 'admin_id') { return $value; }
	$id = plus_existing_author($value);
	return $id > ANONYMOUS ? (string) $id : '0';
}

function plus_kb_editor($text, $uid)
{
	if ((string) $uid !== '')
	{
		if (!preg_match('/^[a-zA-Z0-9]{1,10}$/D', $uid)) { throw new \RuntimeException('Invalid legacy Knowledge Base BBCode identifier.'); }
		$text = preg_replace('/(:[a-z0-9])?:' . preg_quote($uid, '/') . '(?=\])/i', '', $text);
	}
	return plus_kb_plain(str_replace('<br />', "\n", $text));
}

function plus_kb_search($text, $uid)
{
	$text = plus_kb_editor($text, $uid);
	return trim(preg_replace('/\s+/u', ' ', preg_replace('/\[\/?[a-z][a-z0-9]*(?:[=:\s][^\]\r\n]*)?\]/i', ' ', $text)));
}

function plus_kb_pages($id, $text, $uid, $time)
{
	global $convert, $db, $table_prefix;
	$table = $table_prefix . 'plus_kb_pages';
	$id = (int) $id;
	if ($uid !== '' && !preg_match('/^[a-zA-Z0-9]{1,10}$/D', $uid)) { throw new \RuntimeException('Invalid legacy Knowledge Base BBCode identifier.'); }
	$convert->row['post_id'] = $id;
	$convert->row['post_time'] = (int) $time;
	$convert->row['old_bbcode_uid'] = $uid;
	$db->sql_transaction('begin');
	$db->sql_query("DELETE FROM $table WHERE article_id=$id");
	foreach (explode('[page]', $text) as $index => $page)
	{
		$parts = explode('[toc]', $page, 2);
		$heading = count($parts) === 2 ? trim(plus_kb_search(strip_tags($parts[0]), $uid)) : '';
		$content = plus_convert_message(str_replace('[toc]', '', $page));
		$data = array('article_id' => $id, 'page_number' => $index + 1, 'heading' => utf8_substr($heading, 0, 255),
			'content' => $content, 'bbcode_uid' => make_uid((int) $time), 'bbcode_bitfield' => (string) get_bbcode_bitfield(), 'bbcode_options' => 7);
		$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $data));
	}
	$db->sql_transaction('commit');
}

function plus_kb_moderators()
{
	global $src_db, $convert, $db, $phpbb_container;
	$p = $convert->src_table_prefix;
	$result = $src_db->sql_query("SELECT config_value FROM {$p}kb_config WHERE config_name='mod_group'");
	$group = (int) $src_db->sql_fetchfield('config_value');
	$src_db->sql_freeresult($result);
	if ($group < 1) { return; }
	$result = $src_db->sql_query("SELECT group_id,group_single_user FROM {$p}groups WHERE group_id=$group");
	$row = $src_db->sql_fetchrow($result);
	$src_db->sql_freeresult($result);
	if (!$row) { return; }
	$ids = array($group);
	$table = ACL_GROUPS_TABLE;
	$field = 'group_id';
	if ((int) $row['group_single_user'])
	{
		$table = ACL_USERS_TABLE;
		$field = 'user_id';
		$ids = array();
		$result = $src_db->sql_query("SELECT user_id FROM {$p}user_group WHERE group_id=$group AND user_pending=0");
		while ($member = $src_db->sql_fetchrow($result)) { $ids[] = $member['user_id']; }
		$src_db->sql_freeresult($result);
		$ids = array_unique(array_map('plus_existing_author', $ids));
		$ids = array_diff($ids, array(ANONYMOUS));
	}
	$result = $db->sql_query("SELECT auth_option_id FROM " . ACL_OPTIONS_TABLE . " WHERE auth_option='m_plus_kb'");
	$option = (int) $db->sql_fetchfield('auth_option_id');
	$db->sql_freeresult($result);
	if (!$option) { throw new \RuntimeException('Missing destination Knowledge Base moderator permission.'); }
	foreach ($ids as $id)
	{
		// Grant just this option. Other native roles assigned to the subject remain intact.
		$data = array($field => (int) $id, 'forum_id' => 0, 'auth_option_id' => $option, 'auth_role_id' => 0, 'auth_setting' => ACL_YES);
		$db->sql_query("DELETE FROM $table WHERE $field=" . (int) $id . " AND forum_id=0 AND auth_option_id=$option AND auth_role_id=0");
		$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $data));
	}
	if ($ids) { $phpbb_container->get('auth')->acl_clear_prefetch(); }
}

function plus_kb_schema($prefix, $truncate)
{
	$p = $prefix . 'plus_kb_';
	return array(
		array('target' => $p . 'settings', 'primary' => 'kb_config.config_name', 'query_first' => array('target', $truncate . $p . 'settings'),
			array('setting_name', 'kb_config.config_name', ''), array('setting_value', array('kb_config.config_name','kb_config.config_value'), 'plus_kb_setting')),
		array('target' => $p . 'cats', 'primary' => 'kb_categories.category_id', 'autoincrement' => 'cat_id',
			'query_first' => array('target', $truncate . $p . 'cats'), array('cat_id','kb_categories.category_id','intval'),
			array('parent_id','kb_categories.parent','intval'), array('sort_order','kb_categories.cat_order','intval'),
			array('title','kb_categories.category_name','plus_kb_plain'), array('description','kb_categories.category_details','plus_kb_plain')),
		array('target' => $p . 'types', 'primary' => 'kb_types.id', 'autoincrement' => 'type_id',
			'query_first' => array('target', $truncate . $p . 'types'), array('type_id','kb_types.id','intval'), array('title','kb_types.type','plus_kb_plain')),
		array('target' => $p . 'articles', 'primary' => 'kb_articles.article_id', 'autoincrement' => 'article_id',
			'query_first' => array(array('target', $truncate . $p . 'articles'), array('target', $truncate . $p . 'pages')),
			array('article_id','kb_articles.article_id','intval'), array('cat_id','kb_articles.article_category_id','intval'),
			array('type_id','kb_articles.article_type','intval'), array('title','kb_articles.article_title','plus_kb_plain'),
			array('description','kb_articles.article_description','plus_kb_plain'), array('author_id','kb_articles.article_author_id','plus_existing_author'),
			array('author_name','kb_articles.username','plus_kb_plain'), array('author_removed',array('kb_articles.article_author_id','kb_articles.username'),'plus_removed_author'),
			array('created_at','kb_articles.article_date','intval'), array('source_date','kb_articles.article_date',''),
			array('approval','kb_articles.approved','intval'), array('topic_id','kb_articles.topic_id','intval'), array('views','kb_articles.views',''),
			array('historical_points','kb_articles.article_rating',''), array('historical_votes','kb_articles.article_totalvotes',''),
			array('editor_text',array('kb_articles.article_body','kb_articles.bbcode_uid'),'plus_kb_editor'),
			array('search_text',array('kb_articles.article_body','kb_articles.bbcode_uid'),'plus_kb_search'),
			array('',array('kb_articles.article_id','kb_articles.article_body','kb_articles.bbcode_uid','kb_articles.article_date'),'plus_kb_pages')),
		array('target' => $p . 'votes','order_by' => 'kb_votes.votes_file,kb_votes.votes_userid,kb_votes.votes_ip',
			'query_first' => array('target', $truncate . $p . 'votes'), array('article_id','kb_votes.votes_file','intval'),
			array('user_id','kb_votes.votes_userid','plus_existing_author'), array('user_ip','kb_votes.votes_ip','plus_registration_ip')),
	);
}
