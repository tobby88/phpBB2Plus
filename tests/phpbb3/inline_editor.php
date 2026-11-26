<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->query("UPDATE phpbb_config SET config_value=IF(config_name='use_ajax_edit_over','1','0') WHERE config_name IN ('use_ajax_edit','use_ajax_edit_over')");
	$old->query("UPDATE phpbb_config SET config_value=IF(config_name='use_ajax_preview','1','0') WHERE config_name IN ('use_ajax_preview','use_ajax_preview_over')");
	$old->query('UPDATE phpbb_users SET user_use_ajax_edit=IF(user_id=1,1,0),user_use_ajax_preview=IF(user_id=1,0,1) WHERE user_id IN (1,2)');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function inline_rows($sql)
{
	global $db;
	$r = $db->sql_query($sql); $rows = $db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
}
function inline_check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
function inline_personal_grant($member, $forum, $option, $rows)
{
	global $db, $auth;
	$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE forum_id=$forum AND user_id=$member AND auth_role_id=0 AND auth_option_id=$option");
	foreach ($rows as $row) { $db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', $row)); }
	$auth->acl_clear_prefetch($member);
}
$member = (int) inline_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0]['user_id'];
$state = $phpbb_container->get('config_text');
$key = 'plus_test_inline_editor';
$saved = json_decode((string) $state->get($key), true);
$type = $argv[2];
if ($type === 'prepare')
{
	inline_check(!$saved && (int) $config['plus_inline_edit_over'] === 1 && !(int) $config['plus_inline_edit'], 'Source editor policy was lost');
	inline_check(!inline_rows('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE forum_id=1 AND topic_poster=$member AND topic_title='Inline editor fixture'") &&
		!inline_rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . " WHERE poster_id=$member AND real_filename='inline-edit-fixture.gif'"),
		'The synthetic editor topic or its attachment already exists');
	$members = inline_rows('SELECT user_id,plus_inline_edit,plus_full_preview FROM ' . USERS_TABLE . " WHERE user_id IN (2,$member)");
	$prefs = array_column($members, 'plus_inline_edit', 'user_id');
	$previews = array_column($members, 'plus_full_preview', 'user_id');
	inline_check((int) $prefs[$member] === 1 && (int) $prefs[2] === 0, 'Converted members lost distinct editing choices');
	inline_check((int) $config['plus_full_preview'] === 1 && !(int) $config['plus_full_preview_over'] && !(int) $previews[$member] && (int) $previews[2] === 1,
		'Full editor preview source choices did not convert independently');
	$state->set($key, json_encode(array('settings' => array_intersect_key(iterator_to_array($config),
		array_flip(array('plus_inline_edit', 'plus_inline_edit_over', 'plus_full_preview', 'plus_full_preview_over', 'flood_interval', 'edit_time', 'search_interval', 'search_anonymous_interval', 'display_unapproved_posts'))),
		'prefs' => $prefs, 'previews' => $previews, 'topic' => 0, 'listing' => array(),
		'grants' => array_column(inline_rows('SELECT session_id FROM ' . FORUMS_ACCESS_TABLE . " WHERE forum_id=1 AND user_id=$member"), 'session_id'),
		'forum' => inline_rows('SELECT forum_flags,forum_status,forum_password FROM ' . FORUMS_TABLE . ' WHERE forum_id=1')[0])));
	$config->set('flood_interval', 0);
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_flags=forum_flags | ' . FORUM_FLAG_POST_REVIEW . ' WHERE forum_id=1');
}
elseif ($type === 'upgrade' || $type === 'preview-upgrade')
{
	$field = $type === 'upgrade' ? 'plus_inline_edit' : 'plus_full_preview';
	$name = $type === 'upgrade' ? '\phpbb2plus\community\migrations\v159_inline_editor' : '\phpbb2plus\community\migrations\v165_full_preview';
	$before = inline_rows("SELECT user_id,$field FROM " . USERS_TABLE . " WHERE $field<>-1");
	$m = $phpbb_container->get('migrator');
	for ($n = 0; $n < 12 && inline_rows('SELECT migration_name FROM ' . $table_prefix . "migrations WHERE migration_name='" .
		$db->sql_escape($name) . "'"); ++$n) { $m->revert($name); }
	$tools = $phpbb_container->get('dbal.tools');
	inline_check(!$tools->sql_column_exists(USERS_TABLE, $field), 'Previous editor schema was not restored');
	$m->load_migration_state(); $m->set_migrations(array($name));
	for ($n = 0; $n < 16 && !$m->finished(); ++$n) { $m->update(); }
	inline_check($m->finished() && (int) $config[$field] === 1 && !(int) $config[$field . '_over'] &&
		!inline_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE $field<>-1"), 'Upgraded members did not inherit the board default');
	foreach ($before as $row) { $db->sql_query('UPDATE ' . USERS_TABLE . " SET $field=" . (int) $row[$field] . ' WHERE user_id=' . (int) $row['user_id']); }
	foreach (array($field, $field . '_over') as $k) { $config->set($k, $saved['settings'][$k]); }
}
elseif ($type === 'preview-force') { $config->set('plus_full_preview_over', 1); $config->set('plus_full_preview', 0); }
elseif ($type === 'preview-open') { $config->set('plus_full_preview', 1); $config->set('plus_full_preview_over', 0); }
elseif ($type === 'preview-draft')
{
	$db->sql_query('INSERT INTO ' . DRAFTS_TABLE . ' ' . $db->sql_build_array('INSERT', array('user_id' => $member, 'forum_id' => 1, 'topic_id' => 0,
		'save_time' => time(), 'draft_subject' => 'Stored editor preview', 'draft_message' => '[b]Earlier draft[/b]')));
	$saved['draft'] = (int) $db->sql_nextid();
	$state->set($key, json_encode($saved)); echo $saved['draft'];
}
elseif ($type === 'preview-counts')
{
	$counts = array();
	foreach (array(DRAFTS_TABLE, POSTS_TABLE, TOPICS_TABLE, POLL_OPTIONS_TABLE, PRIVMSGS_TABLE, PRIVMSGS_TO_TABLE, ATTACHMENTS_TABLE) as $table)
	{
		$counts[] = (int) inline_rows("SELECT COUNT(*) AS n FROM $table")[0]['n'];
	}
	$counts[] = inline_rows('SELECT draft_subject,draft_message FROM ' . DRAFTS_TABLE . ' WHERE draft_id=' . (int) $saved['draft'] . " AND user_id=$member");
	echo json_encode($counts);
}
elseif ($type === 'open') { $config->set('plus_inline_edit_over', 0); }
elseif ($type === 'locate')
{
	$rows = inline_rows('SELECT topic_id,topic_first_post_id FROM ' . TOPICS_TABLE . " WHERE forum_id=1 AND topic_poster=$member AND topic_title='Inline editor fixture'");
	inline_check(count($rows) === 1, 'The native member did not create the synthetic topic');
	$saved['topic'] = (int) $rows[0]['topic_id']; $saved['post'] = (int) $rows[0]['topic_first_post_id'];
	$state->set($key, json_encode($saved));
	$files = inline_rows('SELECT attach_id,attach_comment,real_filename FROM ' . ATTACHMENTS_TABLE . ' WHERE in_message=0 AND is_orphan=0 AND post_msg_id=' . $saved['post']);
	inline_check(count($files) === 1 && $files[0]['real_filename'] === 'inline-edit-fixture.gif', 'The native posting form did not attach the synthetic file');
	$saved['file'] = $files[0]; $state->set($key, json_encode($saved));
	echo $saved['topic'] . ',' . $saved['post'];
}
elseif ($type === 'intact' || $type === 'changed')
{
	$rows = inline_rows('SELECT p.post_subject,p.post_text,p.post_attachment,t.topic_title,t.plus_description FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE .
		' t ON t.topic_id=p.topic_id WHERE t.topic_id=' . (int) $saved['topic'] . ' AND p.post_id=' . (int) $saved['post']);
	$files = inline_rows('SELECT attach_id,attach_comment,real_filename FROM ' . ATTACHMENTS_TABLE . ' WHERE in_message=0 AND is_orphan=0 AND post_msg_id=' . (int) $saved['post']);
	inline_check(count($rows) === 1 && (int) $rows[0]['post_attachment'] === 1 && $rows[0]['plus_description'] === 'Inline description' && $files === array($saved['file']), 'The edit changed the attachment or topic metadata');
	$title = utf8_encode_ucr($type === 'changed' ? 'Änderung – 📘' : 'Inline editor fixture');
	inline_check($rows[0]['topic_title'] === $title && $rows[0]['post_subject'] === $title, 'The native post and topic titles diverged');
	inline_check(strpos(html_entity_decode($rows[0]['post_text'], ENT_QUOTES | ENT_XML1, 'UTF-8'), $type === 'changed' ? 'Grüße' : 'Original') !== false,
		'The native stored message lost its original characters');
}
elseif ($type === 'lock' || $type === 'unlock')
{
	$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_edit_locked=' . (int) ($type === 'lock') . ' WHERE post_id=' . (int) $saved['post']);
}
elseif ($type === 'listing')
{
	$topic = inline_rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (int) $saved['topic'])[0];
	$post = inline_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=' . (int) $saved['post'])[0];
	$reply = inline_rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE topic_id=' . (int) $saved['topic'] . " AND poster_id=2 AND post_subject='Listed moderator reply'");
	inline_check(count($reply) === 1, 'Missing separately authored reply');
	$config->set('edit_time', 60); $config->set('display_unapproved_posts', 0);
	$config->set('search_interval', 0); $config->set('search_anonymous_interval', 0);
	unset($topic['topic_id'], $post['post_id']);
	$topic['topic_posts_approved'] = 1; $topic['topic_attachment'] = $post['post_attachment'] = 0;
	$items = array('own' => array(), 'locked_post' => array('post_edit_locked' => 1),
		'old' => array('post_time' => time() - 7200), 'locked_topic' => array('topic_status' => ITEM_LOCKED),
		'pending_first' => array('post_visibility' => ITEM_UNAPPROVED),
		'erased' => array('post_visibility' => ITEM_DELETED, 'plus_legacy_erased' => 1, 'plus_public_post' => 1));
	$db->sql_transaction('begin');
	foreach ($items as $name => $overrides)
	{
		$title = 'Listed edit ' . $name;
		$t = array_replace($topic, array_intersect_key($overrides, $topic), array('topic_title' => $title));
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
		$tid = (int) $db->sql_nextid();
		$p = array_replace($post, array_intersect_key($overrides, $post), array('topic_id' => $tid, 'post_subject' => $title));
		$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $p));
		$pid = (int) $db->sql_nextid();
		$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_first_post_id=$pid,topic_last_post_id=$pid WHERE topic_id=$tid");
		$saved['listing'][$name] = array($tid, $pid);
	}
	$state->set($key, json_encode($saved));
	$db->sql_transaction('commit');
	echo json_encode(array('variants' => $saved['listing'], 'reply' => (int) $reply[0]['post_id']));
}
elseif ($type === 'forum-lock' || $type === 'forum-open' || $type === 'forum-password')
{
	$password = $type === 'forum-password' ? $phpbb_container->get('passwords.manager')->hash('synthetic-inline-forum') : $saved['forum']['forum_password'];
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password' => $password,
		'forum_status' => $type === 'forum-lock' ? ITEM_LOCKED : $saved['forum']['forum_status'])) . ' WHERE forum_id=1');
}
elseif ($type === 'deny-edit' || $type === 'preview-pm-off')
{
	$permission = $type === 'deny-edit' ? 'f_edit' : 'u_sendpm';
	$forum = $type === 'deny-edit' ? 1 : 0;
	$option = (int) inline_rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='$permission'")[0]['auth_option_id'];
	$rows = inline_rows('SELECT * FROM ' . ACL_USERS_TABLE . " WHERE forum_id=$forum AND user_id=$member AND auth_role_id=0 AND auth_option_id=$option");
	$saved['grants_before_denial'][$permission] = array($forum, $option, $rows);
	$state->set($key, json_encode($saved));
	inline_personal_grant($member, $forum, $option, array(array('forum_id' => $forum, 'user_id' => $member, 'auth_role_id' => 0, 'auth_option_id' => $option, 'auth_setting' => ACL_NEVER)));
}
elseif ($type === 'restore-edit' || $type === 'preview-pm-on')
{
	$permission = $type === 'restore-edit' ? 'f_edit' : 'u_sendpm';
	inline_personal_grant($member, ...$saved['grants_before_denial'][$permission]);
	unset($saved['grants_before_denial'][$permission]);
	$state->set($key, json_encode($saved));
}
elseif ($type === 'cleanup' && $saved)
{
	foreach ($saved['grants_before_denial'] ?? array() as $grant) { inline_personal_grant($member, ...$grant); }
	if (!empty($saved['listing']))
	{
		// Only these lightweight, non-counting rows were inserted directly.
		$ids = array_column($saved['listing'], 0);
		foreach (array(TOPICS_TRACK_TABLE, TOPICS_POSTED_TABLE, POSTS_TABLE, TOPICS_TABLE) as $table)
		{
			$db->sql_query("DELETE FROM $table WHERE " . $db->sql_in_set('topic_id', $ids));
		}
	}
	$topics = $saved['topic'] ? array((int) $saved['topic']) : array_column(inline_rows('SELECT topic_id FROM ' . TOPICS_TABLE .
		" WHERE forum_id=1 AND topic_poster=$member AND topic_title='Inline editor fixture'"), 'topic_id');
	if ($topics)
	{
		require_once './includes/functions_admin.php';
		$user->session_begin(); $auth->acl($user->data); $user->setup();
		delete_topics('topic_id', $topics);
	}
	$orphans = array_column(inline_rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE .
		" WHERE is_orphan=1 AND poster_id=$member AND real_filename='inline-edit-fixture.gif'"), 'attach_id');
	if ($orphans) { $phpbb_container->get('attachment.manager')->delete('attach', $orphans); }
	foreach ($saved['settings'] as $k => $v) { $config->set($k, $v); }
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $saved['forum']) . ' WHERE forum_id=1');
	$db->sql_query('DELETE FROM ' . FORUMS_ACCESS_TABLE . " WHERE forum_id=1 AND user_id=$member" .
		($saved['grants'] ? ' AND ' . $db->sql_in_set('session_id', $saved['grants'], true) : ''));
	foreach ($saved['prefs'] as $id => $val) { $db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_inline_edit=' . (int) $val . ' WHERE user_id=' . (int) $id); }
	foreach ($saved['previews'] as $id => $val) { $db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_full_preview=' . (int) $val . ' WHERE user_id=' . (int) $id); }
	if (!empty($saved['draft'])) { $db->sql_query('DELETE FROM ' . DRAFTS_TABLE . ' WHERE draft_id=' . (int) $saved['draft'] . " AND user_id=$member"); }
	$state->delete($key);
}
else { exit(2); }
