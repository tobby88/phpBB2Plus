<?php
// Exercise only a named synthetic account on a disposable local phpBB installation.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
require_once './includes/functions_module.php';
require_once '../finished-installer/convertors/plus_junior_admin.php';
require_once '../finished-installer/convertors/plus_media.php';
require_once __DIR__ . '/delegation.php';
if (plus_jr_source_present($db, 'missing_fixture_')) { throw new RuntimeException('An absent Junior Admin source was treated as installed'); }
$root = realpath('../legacy');
$directory = $root . '/staff';
$sibling = 'admin_pa_ug_auth.php';
$digest = 'fa7397cc5100fd2dbeb871477fc5bc166d63a5d3ff789df671ce1a5e09edcd01';
if (!plus_jr_reviewed_dependencies($root, $directory, array($sibling => $digest))
	|| plus_jr_reviewed_dependencies($root, $directory, array($sibling => str_repeat('0', 64)))
	|| plus_jr_reviewed_dependencies($root, $directory, array('missing_fixture_module.php' => $digest)))
{ throw new RuntimeException('Changed or missing dependency was accepted for automatic delegation'); }
$convert = (object) array('options' => array('forum_path' => $root));
$source_catalog = plus_jr_registry();
$add = $source_catalog[md5('ArcadeAddadmin_arcade_games.php?mode=add_game')] ?? array();
$import = $source_catalog[md5('ArcadeImportadmin_arcade_games.php?mode=import_game')] ?? array();
if (($add['option'] ?? '')!=='a_plus_arc_add' || isset($add['options']) || ($import['option'] ?? '')!=='a_plus_arc_import' || isset($import['options']))
{ throw new RuntimeException('The distinct Arcade Add, Edit and Import registrations overlapped'); }
$quota = $root . '/attach_mod/includes/functions_quota_storage.php';
$original = file_get_contents($quota);
try
{
	file_put_contents($quota,$original . "\n/* Custom local quota writer */\n");
	$registry = plus_jr_registry();
	// The shared game writer inherits the import writer, which loads this helper.
	foreach (array('ArcadeImportadmin_arcade_games.php?mode=import_game','ArcadeAddadmin_arcade_games.php?mode=add_game',
		'ArcadeEditadmin_arcade_games.php?mode=edit_games') as $key)
	{
		$entry = $registry[md5($key)] ?? array();
		if (empty($entry['customised']) || isset($entry['option']) || isset($entry['options']))
		{ throw new RuntimeException('A changed common import dependency retained a reviewed game registration'); }
	}
}
finally { file_put_contents($quota,$original); }
$file_grant = md5('DownloadFile_manage_titleadmin_pa_file.php');
$helper = $root . '/pafiledb/includes/functions_mirror_storage.php';
$original = file_get_contents($helper);
try
{
	file_put_contents($helper, $original . "\n/* Locally customised mirror handler */\n");
	$registry = plus_jr_registry();
	$entry = $registry[$file_grant] ?? array();
	if (empty($entry['customised']) || isset($entry['options']) || isset($entry['option']))
	{ throw new RuntimeException('A customised file-management dependency retained its automatic assignment'); }
	if (($registry[md5('DownloadSettingsadmin_pa_settings.php')]['option'] ?? '') !== 'a_plus_dl_settings')
	{ throw new RuntimeException('Changing a mirror handler also suspended the separate settings assignment'); }
}
finally { file_put_contents($helper, $original); }
$log_writer = $root . '/includes/functions_arcade_log_storage.php';
$original = file_get_contents($log_writer);
try
{
	file_put_contents($log_writer,$original . "\n/* Custom local retention policy */\n");
	$registry = plus_jr_registry();
	$log_entry = $registry[md5('ArcadeError_Logadmin_arcade_log.php')];
	if (empty($log_entry['customised']) || isset($log_entry['option']) ||
		($registry[md5('ArcadeCategoriesadmin_arcade_cats.php')]['option'] ?? '') !== 'a_plus_arc_categories')
	{ throw new RuntimeException('A customised diagnostic writer was delegated or affected an independent Arcade registration'); }
}
finally { file_put_contents($log_writer,$original); }
$arcade = $directory . '/admin_arcade_scores.php';
$original = file_get_contents($arcade);
try
{
	file_put_contents($arcade, $original . "\n/* Locally modified score handler */\n");
	$registry = plus_jr_registry();
	$entry = $registry[md5('ArcadeEditadmin_arcade_games.php?mode=edit_games')] ?? array();
	if (empty($entry['customised']) || isset($entry['options']) || isset($entry['option']))
	{ throw new RuntimeException('Customised Arcade score authority was not held for review'); }
}
finally { file_put_contents($arcade, $original); }
$flag_writer = $root . '/includes/functions_flags_storage.php';
$category_history = $root . '/includes/functions_arcade_last_play.php';
$original = file_get_contents($category_history);
try
{
	file_put_contents($category_history, $original . "\n/* Locally modified category sync */\n");
	$registry = plus_jr_registry();
	$entry = $registry[md5('ArcadeCategoriesadmin_arcade_cats.php')] ?? array();
	if (empty($entry['customised']) || isset($entry['option']) || isset($entry['options'])) { throw new RuntimeException('Unreviewed Arcade category scope was activated'); }
	$create = $registry[md5('ArcadeAddadmin_arcade_games.php?mode=add_game')] ?? array();
	if (empty($create['customised']) || isset($create['option']) || isset($create['options'])) { throw new RuntimeException('An unreviewed game creation dependency received the Add grant'); }
}
finally { file_put_contents($category_history, $original); }
$original = file_get_contents($flag_writer);
try
{
	file_put_contents($flag_writer, $original . "\n/* Locally modified flag writer */\n");
	$registry = plus_jr_registry();
	$entry = $registry[md5('UsersFlagsadmin_flags.php')] ?? array();
	if (empty($entry['customised']) || isset($entry['option']) || isset($entry['options']))
	{ throw new RuntimeException('A customised country flag writer received an automatic administrative grant'); }
}
finally { file_put_contents($flag_writer, $original); }
$db->sql_transaction('begin');
try
{
	$db->sql_query('UPDATE ' . ACL_OPTIONS_TABLE . " SET is_global=0 WHERE auth_option='a_plus_dl_mirrors'");
	list($report, $rights) = plus_jr_plan(56, 0, 1, $file_grant);
	if ((json_decode($report, true)[0]['result'] ?? '') !== 'target-option-unavailable' || $rights !== '[]')
	{ throw new RuntimeException('An incomplete target extension received part of a bundled source grant'); }
}
finally { $db->sql_transaction('rollback'); }
plus_jr_finish();
plus_jr_finish();
$writer = new auth_admin();
$subject = function () use ($db)
{
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=91 AND username='JuniorFixture'");
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row) { throw new RuntimeException('Missing Junior Admin fixture'); }
	return $row + array('is_registered' => true, 'is_bot' => false);
};
$account = $subject();
$auth->acl($account);
$stock = new \phpbb\auth\auth();
$stock->acl($account);
$review = method_exists(\phpbb2plus\knowledgebase\service\articles::class, 'reviewer');
if (!$auth->has_delegated_acp() || $auth->acl_get('a_') || $stock->acl_get('a_') ||
	$auth->acl_get('a_words') || $stock->acl_get('a_words') || $auth->acl_get('a_user') || (bool) $auth->acl_get('a_plus_kb_articles') !== $review)
{ throw new RuntimeException('An archived Junior Admin grant affected native public staff permissions'); }
if ($review)
{
	$user->data = $account;
	$kb = $phpbb_container->get('phpbb2plus.knowledgebase.articles');
	if (!$kb->reviewer() || $kb->moderator()) { throw new RuntimeException('Delegated article review must not imply editorial rights'); }
}
define('ADMIN_START', true);
if (!$auth->acl_get('a_') || !$auth->acl_get('a_words') || !$auth->acl_get('a_plus_kb_types') ||
	!$auth->acl_get('a_plus_dl_categories') || $auth->acl_gets('a_plus_downloads', 'a_icons'))
{ throw new RuntimeException('Junior Admin entrance or the scoped module grant changed'); }
define('IN_ADMIN', true);
if ($auth->acl_get('a_')) { throw new RuntimeException('The ACP entrance changed native administrator status inside the panel'); }
foreach (delegation_fixture_roles() as $id => $role)
{
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $id);
	$single = $db->sql_fetchrow($result) + array('is_registered' => true, 'is_bot' => false);
	$db->sql_freeresult($result);
	$auth->acl($single);
	$stock->acl($single);
	if ($single['username'] !== $role[0] || !$auth->has_delegated_acp() || $stock->acl_get('a_')) { throw new RuntimeException('Invalid isolated delegate'); }
	$options = (array) $role[2];
	foreach (array_keys($auth->acl_options['global']) as $option)
	{
		if (strpos($option, 'a_') === 0 && (bool) $auth->acl_get($option) !== in_array($option, $options, true))
		{ throw new RuntimeException('Unexpected native ACP option ' . $option . ' for ' . $role[0]); }
	}
	$targets = \phpbb2plus\community\auth\junior_admin::targets();
	$panels = array_intersect_key($targets, array_flip($options));
	foreach ($targets as $target)
	{
		if ($auth->delegated_module($target[0], $target[1]) !== in_array($target, $panels, true))
		{ throw new RuntimeException('Unexpected delegated ACP module for ' . $role[0]); }
	}
	try
	{
		$writer->acl_set('user', 0, $id, array_fill_keys($options, ACL_NEVER));
		$auth->acl($single);
		if ($auth->has_delegated_acp()) { throw new RuntimeException('Native Never did not close all delegated modules'); }
	}
	finally { $writer->acl_set('user', 0, $id, array_fill_keys($options, ACL_NO)); }
}
$auth->acl($account);
$db->sql_transaction('begin');
try
{
	$db->sql_query('UPDATE ' . EXT_TABLE . " SET ext_active=0 WHERE ext_name='phpbb2plus/knowledgebase'");
	$account = $subject();
	$auth->acl($account);
	if (!$auth->acl_get('a_words') || $auth->acl_get('a_plus_kb_types') || $auth->acl_get('a_plus_kb_articles'))
	{ throw new RuntimeException('The loaded container retained rights for a disabled Knowledge Base'); }
	$db->sql_query('UPDATE ' . EXT_TABLE . " SET ext_active=0 WHERE ext_name='phpbb2plus/downloads'");
	$auth->acl($account);
	if ($auth->acl_get('a_plus_dl_categories') || !$auth->acl_get('a_words'))
	{ throw new RuntimeException('Disabling Downloads did not suspend exactly its own category grant'); }
	$db->sql_query('UPDATE ' . EXT_TABLE . " SET ext_active=0 WHERE ext_name='phpbb2plus/community'");
	$auth->acl($account);
	if ($auth->has_delegated_acp()) { throw new RuntimeException('The loaded container retained a disabled Community delegation'); }
}
finally { $db->sql_transaction('rollback'); }
try
{
	$writer->acl_set('user', 0, 91, array('a_words' => ACL_NEVER));
	$account = $subject();
	$auth->acl($account);
	if ($auth->acl_get('a_words') || !$auth->acl_get('a_plus_kb_types')) { throw new RuntimeException('An individual native Never did not revoke just its matching delegation'); }
	$writer->acl_set('user', 0, 91, array('a_plus_kb_types' => ACL_NEVER, 'a_plus_kb_articles' => ACL_NEVER, 'a_plus_dl_categories' => ACL_NEVER));
	plus_jr_finish();
	$account = $subject();
	$auth->acl($account);
	if ($auth->has_delegated_acp()) { throw new RuntimeException('An account with no effective delegation could enter the ACP'); }
	$writer->acl_set('user', 0, 91, array('a_ranks' => ACL_YES, 'a_words' => ACL_NO));
	$account = $subject();
	$auth->acl($account);
	if (!$auth->native_module('acl_a_ranks') || !$auth->native_module('') || $auth->native_module('acl_a_words') || !$auth->acl_get('a_words'))
	{ throw new RuntimeException('Scoped assignments changed independently held native ACP rights'); }
	$account['user_perm_from'] = 2;
	$auth->acl($account);
	if ($auth->has_delegated_acp()) { throw new RuntimeException('A permission trace inherited the real account’s Junior Admin assignments'); }
}
finally
{
	$writer->acl_set('user', 0, 91, array('a_words' => ACL_NO, 'a_plus_kb_types' => ACL_NO, 'a_plus_kb_articles' => ACL_NO,
		'a_plus_dl_categories' => ACL_NO, 'a_ranks' => ACL_NO));
}

// The source fixture's ordinary Archive Club has no administrative assignments.
$membership = array('group_id' => 73, 'user_id' => 91, 'group_leader' => 0, 'user_pending' => 1);
$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' ' . $db->sql_build_array('INSERT', $membership));
try
{
	$writer->acl_set('group', 0, 73, array('a_words' => ACL_NEVER));
	$account = $subject();
	$auth->acl($account);
	if (!$auth->acl_get('a_words')) { throw new RuntimeException('A pending group membership changed Junior Admin access'); }
	$db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending=0 WHERE group_id=73 AND user_id=91');
	$account = $subject();
	$auth->acl($account);
	if ($auth->acl_get('a_words') || !$auth->acl_get('a_plus_kb_types'))
	{ throw new RuntimeException('An active group denial did not revoke the corresponding delegation'); }
}
finally
{
	$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id=73 AND user_id=91');
	$writer->acl_set('group', 0, 73, array('a_words' => ACL_NO));
}
