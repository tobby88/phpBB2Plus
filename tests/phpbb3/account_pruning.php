<?php
// Create only named test accounts on the ephemeral loopback board supplied by the runner.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_user.php';
require_once './includes/acp/auth.php';

$phase = $argv[2];
$text = $phpbb_container->get('config_text');
$key = 'plus_test_account_pruning';
$state = json_decode((string) $text->get($key), true) ?: array();
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql); $rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
};
$acl = new auth_admin();
$day = 86400;
$registered = (int) ($state['registered'] ?? (time() - 500 * $day));
$group = (int) $rows("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL)[0]['group_id'];
$create = static function ($key, $options = array()) use ($phpbb_container, $group, $registered) {
	static $hash;
	if (!$hash) { $hash = $phpbb_container->get('passwords.manager')->hash(getenv('PLUS_TEST_MODERN_PASSWORD')); }
	$name = 'PruneFixture' . $key;
	$uid = user_add($options + array('username' => $name, 'user_email' => strtolower($name) . '@example.invalid',
		'group_id' => $group, 'user_password' => $hash, 'user_type' => USER_NORMAL, 'user_lang' => 'en', 'user_regdate' => $registered));
	if (!$uid) { throw new RuntimeException('Unable to create a synthetic account'); }
	return (int) $uid;
};

if ($phase === 'prepare')
{
	if ($state || $rows("SELECT user_id FROM " . USERS_TABLE . " WHERE username_clean LIKE 'prunefixture%'")) { throw new RuntimeException('Fixture already in use'); }
	$state = array('registered' => $registered, 'users' => array());
	if (isset(\phpbb2plus\community\auth\junior_admin::MODULES['admin_prune_users.php'][2]['a_userdel']))
	{ throw new RuntimeException('The old menu entry must not grant native user deletion'); }
	require_once './includes/functions_messenger.php';
	foreach (array('de' => 'Dein Benutzerkonto', 'en' => 'Your user account') as $locale => $subject)
	{
		$mail = new messenger(false);
		$mail->template('@phpbb2plus_community/plus_pruned_account', $locale);
		$mail->assign_vars(array('USERNAME' => 'PruneFixtureMail'));
		$mail->send(NOTIFY_EMAIL, true); // Render only, without any recipient or transport.
		if (strpos($mail->subject, $subject) === false || strpos($mail->msg, 'PruneFixtureMail') === false)
		{ throw new RuntimeException('Missing localized account notification'); }
	}
	foreach (array('newest_user_id', 'newest_username', 'newest_user_colour') as $configName)
	{ $state['newest'][$configName] = $config[$configName]; }
	$recent = time() - 59 * $day;
	$pending = array('user_type' => USER_INACTIVE, 'user_inactive_reason' => INACTIVE_REGISTER, 'user_actkey' => 'localfixture');
	$users = array('Operator' => array(),
		'Never' => array(), 'Pending' => $pending, 'PendingWithPosts' => $pending,
		'Manual' => array('user_inactive_reason' => INACTIVE_MANUAL) + $pending,
		'NoKey' => array('user_actkey' => '') + $pending, 'Young' => array('user_regdate' => time() - 3 * $day),
		'RateEqual' => array('user_posts' => 2, 'user_lastvisit' => $registered + 20 * $day),
		'RateLow' => array('user_posts' => 2, 'user_lastvisit' => $registered + 21 * $day),
		'Recent' => array('user_last_active' => $recent), 'Dormant' => array('user_lastvisit' => time() - 61 * $day),
		'Author' => array('user_posts' => 1, 'user_lastvisit' => $registered + 100 * $day),
		'SignsIn' => array(), 'Remembered' => array(), 'Grace' => array(), 'Expired' => array(),
		'Promoted' => array(), 'PostsAgain' => array(), 'Untouched' => array(),
		'Technical' => array('user_type' => USER_IGNORE), 'Founder' => array('user_type' => USER_FOUNDER),
		'Junior' => array('plus_jr_delegate' => 1), 'WithAdminOption' => array(), 'AdminGroup' => array());
	for ($i = 1; $i <= 16; $i++) { $users['Page' . $i] = array(); }
	foreach ($users as $label => $options) { $state['users'][$label] = $create($label, $options); }
	$ids = $state['users'];
	$acl->acl_set('user', 0, $ids['Operator'], array('a_userdel' => ACL_YES, 'a_board' => ACL_YES));
	$acl->acl_set('user', 0, $ids['WithAdminOption'], array('a_board' => ACL_YES));
	$admins = (int) $rows("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name='ADMINISTRATORS' AND group_type=" . GROUP_SPECIAL)[0]['group_id'];
	$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' ' . $db->sql_build_array('INSERT', array(
		'user_id' => $ids['AdminGroup'], 'group_id' => $admins, 'user_pending' => 0)));

	// Reuse the schema of an installed synthetic post without touching its owner, counters or thread.
	$topic = $rows('SELECT * FROM ' . TOPICS_TABLE . ' ORDER BY topic_id LIMIT 1')[0];
	unset($topic['topic_id']);
	$topic['topic_title'] = 'Prune fixture topic';
	$topic['topic_poster'] = $topic['topic_last_poster_id'] = $ids['Author'];
	$topic['topic_first_poster_name'] = $topic['topic_last_poster_name'] = 'PruneFixtureAuthor';
	$topic['topic_posts_approved'] = 1;
	$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $topic));
	$tid = (int) $db->sql_nextid();
	$post = $rows('SELECT * FROM ' . POSTS_TABLE . ' ORDER BY post_id LIMIT 1')[0];
	unset($post['post_id']);
	$post['topic_id'] = $tid; $post['forum_id'] = $topic['forum_id'];
	$post['poster_id'] = $ids['Author']; $post['post_username'] = ''; $post['post_text'] = 'Prune fixture retained text';
	$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $post));
	$pid = (int) $db->sql_nextid();
	$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_first_post_id=$pid,topic_last_post_id=$pid WHERE topic_id=$tid");
	$post['poster_id'] = $ids['PendingWithPosts']; $post['post_postcount'] = 0;
	$post['post_text'] = 'Prune fixture uncounted text';
	$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $post));
	$state['uncounted'] = (int) $db->sql_nextid();
	$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_posts_approved=2,topic_last_post_id=' . $state['uncounted'] . " WHERE topic_id=$tid");
	$state['post'] = $pid; $state['topic'] = $tid;
	$text->set($key, json_encode($state, JSON_THROW_ON_ERROR));
	echo json_encode($ids, JSON_THROW_ON_ERROR);
}
elseif (!$state) { throw new RuntimeException('No fixture state'); }
elseif ($phase === 'session_window')
{
	// Represent native sessions on both sides of the normal inactivity timeout.
	$session = $rows('SELECT * FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $state['users']['Operator'] . ' LIMIT 1')[0];
	$age = (int) $config['session_length'];
	foreach (array('Grace' => $age + 5, 'Remembered' => $age + 180, 'Expired' => $age + 180) as $label => $seconds)
	{
		$session['session_id'] = bin2hex(random_bytes(16)); $session['session_user_id'] = $state['users'][$label];
		$session['session_admin'] = 0; $session['session_autologin'] = (int) ($label === 'Remembered');
		$session['session_time'] = $session['session_start'] = time() - $seconds;
		$db->sql_query('INSERT INTO ' . SESSIONS_TABLE . ' ' . $db->sql_build_array('INSERT', $session));
	}
	echo json_encode((bool) $config['allow_autologin'] && (!$config['max_autologin_time'] || 86400 * (int) $config['max_autologin_time'] - 60 >= $age + 180));
}
elseif ($phase === 'revoke' || $phase === 'allow')
{
	$acl->acl_set('user', 0, $state['users']['Operator'], array('a_userdel' => $phase === 'allow' ? ACL_YES : ACL_NEVER));
}
elseif ($phase === 'german')
{
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='de' WHERE user_id=" . $state['users']['Operator']);
}
elseif ($phase === 'change')
{
	$acl->acl_set('user', 0, $state['users']['Promoted'], array('a_board' => ACL_YES));
	$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts=1 WHERE user_id=' . $state['users']['PostsAgain']);
	$state['users']['AddedLater'] = $create('AddedLater');
	$text->set($key, json_encode($state, JSON_THROW_ON_ERROR));
}
elseif ($phase === 'intact' || $phase === 'complete')
{
	$ids = $state['users'];
	$actual = array_map('intval', array_column($rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $db->sql_in_set('user_id', $ids)), 'user_id'));
	$expected = $ids;
	if ($phase === 'complete')
	{
		unset($expected['Never'], $expected['Author']);
		$post = $rows('SELECT post_text,poster_id FROM ' . POSTS_TABLE . ' WHERE post_id=' . $state['post'])[0];
		$byline = $rows('SELECT author_name FROM ' . $table_prefix . 'plus_bylines WHERE post_id=' . $state['post']);
		if ($post['post_text'] !== 'Prune fixture retained text' || (int) $post['poster_id'] !== ANONYMOUS || ($byline[0]['author_name'] ?? '') !== 'PruneFixtureAuthor')
		{ throw new RuntimeException('The native retained post or former-member byline was lost'); }
	}
	sort($expected); sort($actual);
	if ($actual !== $expected) { throw new RuntimeException('Unexpected account removed or retained'); }
	$logs = $rows('SELECT log_id FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_ACCOUNT_PRUNED' AND user_id=" . $ids['Operator']);
	if (count($logs) !== ($phase === 'complete' ? 2 : 0)) { throw new RuntimeException('Incorrect account-removal audit count'); }
}
elseif ($phase === 'clean')
{
	$ids = $state['users'];
	$authorWasPruned = !$rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $ids['Author']);
	$db->sql_query('DELETE FROM ' . $table_prefix . 'plus_bylines WHERE post_id=' . $state['post']);
	$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE post_id=' . $state['post'] . " AND post_text='Prune fixture retained text'");
	$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE post_id=' . $state['uncounted'] . " AND post_text='Prune fixture uncounted text'");
	$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $state['topic'] . " AND topic_title='Prune fixture topic'");
	$active = array_column($rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean LIKE 'prunefixture%' AND " . $db->sql_in_set('user_id', $ids)), 'user_id');
	if ($active)
	{
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts=0 WHERE ' . $db->sql_in_set('user_id', array_map('intval', $active)));
		user_delete('retain', $active, true);
	}
	if ($authorWasPruned) { $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts=user_posts-1 WHERE user_posts>0 AND user_id=' . ANONYMOUS); }
	foreach ($state['newest'] as $configName => $value) { $config->set($configName, $value, false); }
	$db->sql_query('DELETE FROM ' . $table_prefix . 'plus_former_names WHERE ' . $db->sql_in_set('native_id', $ids));
	$db->sql_query('DELETE FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_ACCOUNT_PRUNED' AND user_id=" . $ids['Operator']);
	$text->delete($key);
}
else { throw new RuntimeException('Unknown fixture action'); }
