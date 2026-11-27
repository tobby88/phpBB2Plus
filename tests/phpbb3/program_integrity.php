<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true); $phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }

function expect_programs($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function program_row($sql)
{
	global $db;
	$result = $db->sql_query($sql); $row = $db->sql_fetchrow($result); $db->sql_freeresult($result); return $row;
}
$table = $table_prefix . 'plus_ct_programs';
$state = $phpbb_container->get('phpbb2plus.community.program_integrity');
$phase = $argv[2];
if ($phase === 'older' || $phase === 'upgraded')
{
	$tools = (new \phpbb\db\tools\factory())->get($db);
	$installed = $phase === 'upgraded';
	expect_programs($tools->sql_table_exists($table) === $installed, 'Incorrect upgrade schema');
	expect_programs((bool) program_row("SELECT auth_option_id FROM " . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_ct_files'") === $installed, 'Incorrect upgrade permission');
	$module = $db->sql_escape('\\phpbb2plus\\community\\acp\\file_integrity_module');
	expect_programs((bool) program_row("SELECT module_id FROM " . MODULES_TABLE . " WHERE module_basename='$module' AND module_mode='main' AND module_auth LIKE '%acl_a_plus_ct_files%'") === $installed, 'Incorrect upgrade module');
	if ($installed)
	{
		$row = $state->state();
		expect_programs(!(int) $row['reference_time'] && !$row['reference_data'] && !(int) $row['scan_start'], 'Upgrade approved a reference without an administrator');
	}
	return;
}
$storage = $phpbb_container->get('config_text');
$key = 'plus_test_ct_program_state';
if ($phase === 'setup')
{
	$original = program_row("SELECT * FROM $table WHERE state_id=1");
	expect_programs($original && !$storage->get($key) && !(int) $original['reference_time'] && !(int) $original['scan_start'], 'A new target must await its first reference');
	$files = $phpbb_container->get('phpbb2plus.community.program_files');
	$packaged = $files->catalog()['paths'];
	expect_programs(count($packaged) > 1000 && in_array('index.php', $packaged, true) && in_array('includes/functions.php', $packaged, true) &&
		in_array('ext/phpbb2plus/community/service/program_files.php', $packaged, true), 'Incomplete native or Plus catalog');
	foreach (array('config.php', 'cache/production.php', 'store/notes.php', 'files/document.php', 'images/avatars/member.php', 'ext/example/config/local.php') as $private)
	{
		expect_programs(!in_array($private, $packaged, true) && !$files::program_name($private), 'Non-program data included in the check');
	}
	$first = $files->sample(array_search('index.php', $packaged, true), 1);
	expect_programs($first === array('index.php' => hash_file('sha256', './index.php')), 'The packaged entrypoint could not be read reliably');
	$storage->set($key, json_encode($original));
}
elseif ($phase === 'status')
{
	$row = $state->state();
	echo json_encode(array('revision' => (int) $row['revision'], 'cursor' => (int) $row['file_cursor'], 'finished' => (bool) $row['scan_end'],
		'approved' => (bool) $row['reference_time'], 'entries' => count($row['reference_data']), 'working' => count($row['scan_data']),
		'reference' => hash('sha256', json_encode($row['reference_data']))));
}
elseif ($phase === 'labels')
{
	foreach (array('de', 'en') as $lang_dir)
	{
		$lang = array(); require './ext/phpbb2plus/community/language/' . $lang_dir . '/info_acp_program_files.php';
		$labels[$lang_dir] = array_keys($lang);
		expect_programs(!empty($lang['ACL_A_PLUS_CT_FILES']) && !empty($lang['LOG_PLUS_CT_FILES_REFERENCE']), 'Missing ACL or audit label');
	}
	sort($labels['de']); sort($labels['en']);
	expect_programs($labels['de'] === $labels['en'], 'Program check translations differ in coverage');
}
elseif (in_array($phase, array('reviewer', 'revoke', 'remove-reviewer'), true))
{
	require_once './includes/functions_user.php'; require_once './includes/acp/auth.php';
	$account = program_row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='programfilereviewer'");
	$id = $account ? (int) $account['user_id'] : 0;
	if ($phase === 'remove-reviewer')
	{
		if ($id) { user_delete('retain', array($id), false); }
		return;
	}
	if ($phase === 'reviewer')
	{
		expect_programs(!$id, 'The synthetic program reviewer already exists');
		$group = program_row('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL);
		$id = user_add(array('username' => 'ProgramFileReviewer', 'group_id' => (int) $group['group_id'], 'user_type' => USER_NORMAL, 'user_lang' => 'en',
			'user_email' => 'program-reviewer@example.invalid', 'user_password' => $phpbb_container->get('passwords.manager')->hash(getenv('PLUS_TEST_MODERN_PASSWORD'))));
	}
	expect_programs((bool) $id, 'Missing synthetic ACP reviewer');
	(new auth_admin())->acl_set('user', 0, $id, array('a_' => ACL_YES, 'a_viewlogs' => ACL_YES, 'a_plus_ct_files' => $phase === 'reviewer' ? ACL_YES : ACL_NEVER));
}
elseif ($phase === 'restore')
{
	$original = json_decode($storage->get($key), true);
	if ($original)
	{
		unset($original['state_id']);
		$db->sql_query("UPDATE $table SET " . $db->sql_build_array('UPDATE', $original) . ' WHERE state_id=1');
		$storage->delete($key);
	}
}
else { throw new InvalidArgumentException('Unknown program file fixture'); }
