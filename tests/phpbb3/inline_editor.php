<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->query("UPDATE phpbb_config SET config_value=IF(config_name='use_ajax_edit_over','1','0') WHERE config_name IN ('use_ajax_edit','use_ajax_edit_over')");
	$old->query('UPDATE phpbb_users SET user_use_ajax_edit=IF(user_id=1,1,0) WHERE user_id IN (1,2)');
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
	$prefs = array_column(inline_rows('SELECT user_id,plus_inline_edit FROM ' . USERS_TABLE . " WHERE user_id IN (2,$member)"), 'plus_inline_edit', 'user_id');
	inline_check((int) $prefs[$member] === 1 && (int) $prefs[2] === 0, 'Converted members lost distinct editing choices');
	$state->set($key, json_encode(array('settings' => array_intersect_key(iterator_to_array($config),
		array_flip(array('plus_inline_edit', 'plus_inline_edit_over', 'flood_interval'))), 'prefs' => $prefs, 'topic' => 0,
		'forum_flags' => (int) inline_rows('SELECT forum_flags FROM ' . FORUMS_TABLE . ' WHERE forum_id=1')[0]['forum_flags'])));
	$config->set('flood_interval', 0);
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_flags=forum_flags | ' . FORUM_FLAG_POST_REVIEW . ' WHERE forum_id=1');
}
elseif ($type === 'upgrade')
{
	$name = '\phpbb2plus\community\migrations\v159_inline_editor';
	$before = inline_rows('SELECT user_id,plus_inline_edit FROM ' . USERS_TABLE . ' WHERE plus_inline_edit<>-1');
	$m = $phpbb_container->get('migrator');
	for ($n = 0; $n < 12 && inline_rows('SELECT migration_name FROM ' . $table_prefix . "migrations WHERE migration_name='" .
		$db->sql_escape($name) . "'"); ++$n) { $m->revert($name); }
	$tools = $phpbb_container->get('dbal.tools');
	inline_check(!$tools->sql_column_exists(USERS_TABLE, 'plus_inline_edit'), 'Previous editor schema was not restored');
	$m->load_migration_state(); $m->set_migrations(array($name));
	for ($n = 0; $n < 16 && !$m->finished(); ++$n) { $m->update(); }
	inline_check($m->finished() && (int) $config['plus_inline_edit'] === 1 && !(int) $config['plus_inline_edit_over'] &&
		!inline_rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE plus_inline_edit<>-1'), 'Upgraded members did not inherit the board default');
	foreach ($before as $row) { $db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_inline_edit=' . (int) $row['plus_inline_edit'] . ' WHERE user_id=' . (int) $row['user_id']); }
	foreach ($saved['settings'] as $k => $v) { if ($k !== 'flood_interval') { $config->set($k, $v); } }
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
elseif ($type === 'cleanup' && $saved)
{
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
	if (isset($saved['forum_flags'])) { $db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_flags=' . (int) $saved['forum_flags'] . ' WHERE forum_id=1'); }
	foreach ($saved['prefs'] as $id => $val) { $db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_inline_edit=' . (int) $val . ' WHERE user_id=' . (int) $id); }
	$state->delete($key);
}
else { exit(2); }
