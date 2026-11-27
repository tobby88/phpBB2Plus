<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if (in_array($argv[2], array('source', 'source-missing'), true))
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->query($argv[2] === 'source-missing' ? 'ALTER TABLE phpbb_users DROP COLUMN user_setbm' : 'UPDATE phpbb_users SET user_setbm=1 WHERE user_id=1');
	return;
}
define('IN_PHPBB', true); $phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function bookmark_expect($test, $why) { if (!$test) { throw new RuntimeException($why); } }
function bookmark_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql); $values = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	return $values;
}
$tools = (new \phpbb\db\tools\factory())->get($db);
$phase = $argv[2];
if ($phase === 'older' || $phase === 'upgraded')
{
	$has = $tools->sql_column_exists(USERS_TABLE, 'plus_post_bookmark');
	bookmark_expect($has === ($phase === 'upgraded'), 'Unexpected posting preference schema');
	if ($has) { bookmark_expect(!bookmark_rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE plus_post_bookmark<>0'), 'A source-free upgrade inferred a member preference'); }
	return;
}
$member = bookmark_rows('SELECT user_id,plus_post_bookmark FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'")[0];
if ($phase === 'converted-missing')
{
	bookmark_expect((int) $member['plus_post_bookmark'] === 0, 'A source without the optional column enabled personal post bookmarks');
	return;
}
$owner = bookmark_rows('SELECT user_id,plus_post_bookmark FROM ' . USERS_TABLE . " WHERE username_clean='modernmember'")[0];
$text = $phpbb_container->get('config_text');
$key = 'plus_test_post_bookmarks';
$prior = 'SELECT topic_id,user_id FROM ' . BOOKMARKS_TABLE . ' WHERE user_id IN (' . (int) $owner['user_id'] . ',' . (int) $member['user_id'] . ') ORDER BY topic_id,user_id';
$topic = bookmark_rows('SELECT topic_id,topic_first_post_id FROM ' . TOPICS_TABLE . " WHERE topic_title='Posting bookmark fixture' AND forum_id=1");
if ($phase === 'setup' || $phase === 'setup-missing')
{
	bookmark_expect(!$topic && !$text->get($key) && (int) $member['plus_post_bookmark'] === (int) ($phase === 'setup') && !$owner['plus_post_bookmark'], 'The converted per-member preference or fixture isolation failed');
	$policy = array('allow_bookmarks' => 1, 'flood_interval' => 0, 'require_activation' => USER_ACTIVATION_NONE, 'email_enable' => 0,
		'enable_confirm' => 0, 'coppa_enable' => 0, 'check_dnsbl' => 0, 'email_check_mx' => 0);
	$saved = array();
	foreach (array_merge(array_keys($policy), array('num_users','newest_user_id','newest_username','newest_user_colour')) as $name) { $saved[$name] = $config[$name]; }
	$text->set($key, json_encode(array($saved, $member['plus_post_bookmark'], bookmark_rows($prior))));
	foreach ($policy as $name => $value) { $config->set($name, $value); }
}
elseif ($phase === 'unpublished') { bookmark_expect(!$topic, 'A failed or previewed topic was created'); }
elseif ($phase === 'created')
{
	bookmark_expect(count($topic) === 1, 'The native composer did not create the topic');
	echo json_encode(array_map('intval', array_values($topic[0])) + array(2 => (int) $member['user_id']));
}
elseif ($phase === 'member' || $phase === 'both')
{
	bookmark_expect(count($topic) === 1, 'The published topic disappeared');
	$ids = array_map('intval', array_column(bookmark_rows('SELECT user_id FROM ' . BOOKMARKS_TABLE . ' WHERE topic_id=' . (int) $topic[0]['topic_id']), 'user_id'));
	$want = array((int) $member['user_id']);
	if ($phase === 'both') { $want[] = (int) $owner['user_id']; }
	sort($want); sort($ids);
	bookmark_expect($ids === $want, 'The choice changed another member’s bookmark or created a duplicate');
}
elseif ($phase === 'off') { $config->set('allow_bookmarks', 0); }
elseif ($phase === 'on') { $config->set('allow_bookmarks', 1); }
elseif ($phase === 'signup' || $phase === 'hide-forum')
{
	$rows = bookmark_rows('SELECT user_id,plus_post_bookmark FROM ' . USERS_TABLE . " WHERE username_clean='bookmarksigntest'");
	bookmark_expect(count($rows) === 1 && (int) $rows[0]['plus_post_bookmark'] === 1, 'The personal registration preference was not saved');
	if ($phase === 'hide-forum')
	{
		require_once './includes/acp/auth.php';
		(new auth_admin())->acl_set('user', 1, (int) $rows[0]['user_id'], array('f_list' => ACL_NEVER));
	}
}
elseif ($phase === 'restore')
{
	require_once './includes/functions_admin.php'; require_once './includes/functions_user.php';
	if ($topic) { delete_topics('topic_id', array_column($topic, 'topic_id')); }
	$signups = bookmark_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='bookmarksigntest'");
	if ($signups) { user_delete('retain', array_column($signups, 'user_id'), false); }
	list($saved, $default, $bookmarks) = json_decode($text->get($key), true);
	$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_post_bookmark=' . (int) $default . ' WHERE user_id=' . (int) $member['user_id']);
	foreach ($saved as $name => $value) { $config->set($name, $value); }
	$text->delete($key);
	bookmark_expect(bookmark_rows($prior) === $bookmarks, 'A post form changed bookmarks belonging to unrelated topics');
}
else { throw new RuntimeException('Unknown post bookmark fixture phase'); }
