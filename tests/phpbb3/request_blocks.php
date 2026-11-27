<?php
// Ordinary rule, retention and migration operations on a disposable local board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1],
		(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->set_charset('utf8mb4');
	$old->query("UPDATE phpbb_ctracker_config SET ct_config_value='0' WHERE ct_config_name='ipblock_enabled'");
	$old->query("UPDATE phpbb_ctracker_config SET ct_config_value='2' WHERE ct_config_name='ipblock_logsize'");
	$item = $old->prepare('INSERT INTO phpbb_ctracker_ipblocker (id,ct_blocker_value) VALUES (?,?)');
	$item->bind_param('is', $id, $text);
	foreach (array(61 => 'Archive Reader/*', 62 => '192.0.2.128/25', 63 => '2001:db8:abcd:0100::/57',
		64 => '*.fixture.invalid', 65 => 'Bücher & Ablage [1]?', 66 => str_repeat('x', 201), 67 => null) as $id => $text) { $item->execute(); }
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function ct_expect($test, $why) { if (!$test) { throw new RuntimeException($why); } }
$blocks = $phpbb_container->get('phpbb2plus.community.request_blocks');
$tools = (new \phpbb\db\tools\factory())->get($db);
$rules = $table_prefix . 'plus_ct_blocks';
$events = $table_prefix . 'plus_ct_block_events';
if ($argv[2] === 'older') { ct_expect(!$tools->sql_table_exists($rules) && !isset($config['plus_ct_block_enabled']), 'The former extension schema was not reached'); return; }
$phase = $argv[2];
$state = $blocks->state();
$history = $blocks->history();
if ($phase === 'upgraded')
{
	ct_expect(!$blocks->rules(0, 1) && !$history && !(int) $state['archived'] && !$state['reset_time'] && !$state['rules_imported'] && !$state['settings_imported'] && !$state['log_imported'], 'A source-free upgrade inferred block records');
	ct_expect((int) $config['plus_ct_block_enabled'] === 1 && (int) $config['plus_ct_block_logging'] === 1 && (int) $config['plus_ct_block_limit'] === 100, 'Wrong fresh defaults');
	require_once __DIR__ . '/../../phpBB3/install/convertors/plus_request_blocks.php';
	require_once __DIR__ . '/../../phpBB3/install/convertors/plus_media.php';
	$folder = './store/ct_fixture_' . bin2hex(random_bytes(8));
	$sub = $folder . '/ctracker/logfiles';
	$file = $sub . '/logfile_blocklist.txt';
	if (!mkdir($sub, 0700, true)) { throw new RuntimeException('Could not create the private log fixture'); }
	try
	{
		ct_expect(plus_block_log_snapshot($folder, 2) === null, 'A missing source log was replaced with history');
		foreach (array('' => 'legacy', '|||created' => 'created', '|||cleared' => 'cleared') as $kind => $label)
		{
			file_put_contents($file, '1|||1180000000|||null|||null|||null|||null|||null' . $kind . "\n");
			$sample = plus_block_log_snapshot($folder, 2);
			ct_expect($sample['reset_kind'] === $label && !$sample['archived'] && !$sample['events'], 'The original marker was mistaken for a different operation');
		}
		file_put_contents($file, "1|||0|||null|||null|||null|||null|||null\n");
		$sample = plus_block_log_snapshot($folder, 2);
		ct_expect(!$sample['reset_time'] && $sample['reset_kind'] === '', 'A zero-dated package seed became a real reset');
	}
	finally { if (is_file($file)) { unlink($file); } rmdir($sub); rmdir($folder . '/ctracker'); rmdir($folder); }
}
elseif ($phase === 'converted')
{
	$rows = array_column($blocks->rules(0, 200), null, 'rule_id');
	ct_expect(count($rows) === 7 && (int) $rows[61]['rule_active'] === 1 && $rows[65]['rule_value'] === 'Bücher & Ablage [1]?' &&
		!(int) $rows[66]['rule_active'] && !(int) $rows[67]['rule_active'], 'Imported entries were lost, activated or rewritten');
	ct_expect($state['rules_imported'] && $state['settings_imported'] && $state['log_imported'] && $state['reset_kind'] === 'rotated' && (int) $state['reset_time'] === 1700000000 &&
		(int) $state['archived'] === 3501 && count($history) === 2 && $history[1]['address'] === '192.0.2.2' &&
		$history[0]['agent'] === 'Archiv <Leser> 3' && $history[0]['script'] === '/viewtopic.php' && $history[0]['referrer_origin'] === 'https://example.invalid', 'Offline events, rotation counts or URL minimization failed');
	ct_expect((int) $config['plus_ct_block_enabled'] === 0 && (int) $config['plus_ct_block_limit'] === 2 &&
		!$blocks->matches('192.0.2.140', '', ''), 'A disabled original policy was not retained');
	$language = $phpbb_container->get('language');
	foreach (array('de' => 'CrackerTracker-Sperrregeln', 'en' => 'CrackerTracker blocking rules') as $iso => $label)
	{
		$language->set_user_language($iso, true);
		$language->add_lang('info_acp_request_blocks', 'phpbb2plus/community');
		ct_expect($language->lang('ACP_PLUS_CT_BLOCKS') === $label && $language->lang('PLUS_CT_CREATED', '2006') !== 'PLUS_CT_CREATED', 'A shipped translation was not loaded');
	}
}
elseif ($phase === 'matching')
{
	ct_expect($blocks->matches('192.0.2.128', '', '') && $blocks->matches('192.0.2.255', '', '') &&
		!$blocks->matches('192.0.2.127', '', '') && $blocks->matches('2001:0db8:abcd:017f::ff', '', '') &&
		!$blocks->matches('2001:db8:abcd:180::', '', '') && $blocks->matches('127.0.0.1', '', 'User.Fixture.Invalid') &&
		!$blocks->matches('127.0.0.1', '', 'fixture.invalid') && $blocks->matches('127.0.0.1', 'Bücher & Ablage [1]?', ''),
		'Native policy changed family, partial-prefix, hostname or literal matching');
	$pattern = '\phpbb2plus\community\service\request_pattern';
	ct_expect($pattern::matches('2001:db8::7', '2001:0db8:0:0:0:0:0:7', '', '') &&
		!$pattern::matches('0.0.0.0/0', '2001:db8::7', '', '') && !$pattern::matches('ab*bc', '127.0.0.1', 'abc', '') &&
		$pattern::matches('Rea*d*?er', '127.0.0.1', 'READ FOLDER?ER', '') && !$pattern::matches('Rea*d*?er', '127.0.0.1', 'READ FOLDER', ''), 'Literal glob or exact packed address failed');
}
elseif ($phase === 'replay')
{
	require_once __DIR__ . '/../../phpBB3/install/convertors/plus_request_blocks.php';
	$src_db = $db;
	$convert = (object) array('src_table_prefix' => $table_prefix . 'ct_retry_', 'options' => array('forum_path' => __DIR__));
	$retry = $convert->src_table_prefix . 'ctracker_ipblocker';
	$options = $convert->src_table_prefix . 'ctracker_config';
	// Some DBAL drivers roll back an open transaction when probing a missing table.
	// Both source tables are normally on their own connection in the converter.
	$db->sql_query("CREATE TABLE $retry (id INT PRIMARY KEY,ct_blocker_value VARCHAR(250))");
	$db->sql_query("CREATE TABLE $options (ct_config_name VARCHAR(64) PRIMARY KEY,ct_config_value VARCHAR(64))");
	try
	{
		$db->sql_query("INSERT INTO $retry VALUES (9001,'Already converted source')");
		plus_import_request_blocks();
		$current = array_column($blocks->rules(0, 200), 'rule_value');
		ct_expect(!in_array('Already converted source', $current, true) && !in_array('Archive Reader/*', $current, true) &&
			count($current) === 6 && $blocks->state() === $state && $blocks->history() === $history, 'A resumed converter undid a later deletion or recounted the log');
		// A restored source can arrive after a native administrator has added a rule or cleared the log.
		// Exercise that path without changing the regular converted board used by later suites.
		$db->sql_transaction('begin');
		try
		{
			$s = $table_prefix . 'plus_ct_block_state';
			$db->sql_query("UPDATE $retry SET id=62");
			$db->sql_query("UPDATE $s SET rules_imported=0,log_imported=0,archived=5,reset_time=1700000100 WHERE state_id=1");
			plus_import_request_blocks();
			$merged = array_column($blocks->rules(0, 200), 'rule_value', 'rule_id');
			ct_expect(count($merged) === 7 && $merged[62] === '192.0.2.128/25' && in_array('Already converted source', $merged, true), 'A local rule was overwritten by an equal source identifier');
			$event = \phpbb2plus\community\service\request_blocks::event_fields(1700000090, '192.0.2.1', 'Archived', '', '/index.php', '');
			$later = array('event_time' => 1700000200) + $event;
			plus_import_block_log($blocks, array('archived' => 3, 'reset_kind' => 'legacy', 'reset_time' => 1700000000, 'events' => array($event, $later)), 2);
			$kept = $blocks->history();
			ct_expect(count($kept) === 1 && (int) $kept[0]['event_time'] === 1700000200 && (int) $blocks->state()['archived'] === 9 &&
				$blocks->state()['reset_kind'] === 'cleared', 'Restoring a previous log reversed a later clear or lost its historical count');
		}
		finally { $db->sql_transaction('rollback'); }
	}
	finally { $db->sql_query("DROP TABLE $retry, $options"); }
}
elseif ($phase === 'rotated')
{
	ct_expect(count($history) === 1 && $history[0]['address'] === '127.0.0.1' && $history[0]['script'] === '/index.php' &&
		$history[0]['agent'] === 'Archive Reader/1.0' && (int) $state['archived'] === 3503 && $state['reset_kind'] === 'rotated', 'The first current request did not rotate exactly the active window');
}
elseif ($phase === 'paused') { ct_expect(count($history) === 1 && (int) $state['archived'] === 3503, 'Logging continued while paused'); }
elseif ($phase === 'cleared') { ct_expect(!$history && (int) $state['archived'] === 3505 && $state['reset_kind'] === 'cleared', 'Manual clearing lost the historical count'); }
elseif (in_array($phase, array('log-reader', 'log-clearer', 'log-done'), true))
{
	require_once './includes/functions_user.php';
	require_once './includes/acp/auth.php';
	$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='ctlogreviewer'");
	$id = (int) $db->sql_fetchfield('user_id');
	$db->sql_freeresult($result);
	if ($phase === 'log-done')
	{
		if ($id) { user_delete('retain', array($id), false); }
		return;
	}
	if (!$id)
	{
		$result = $db->sql_query('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL);
		$group = (int) $db->sql_fetchfield('group_id');
		$db->sql_freeresult($result);
		$id = user_add(array('username' => 'CTLogReviewer', 'group_id' => $group, 'user_type' => USER_NORMAL, 'user_lang' => 'en',
			'user_email' => 'ct-log-reviewer@example.invalid', 'user_password' => $phpbb_container->get('passwords.manager')->hash(getenv('PLUS_TEST_MODERN_PASSWORD'))));
		ct_expect($id, 'The synthetic log reader was not created');
	}
	(new auth_admin())->acl_set('user', 0, $id, array('a_' => ACL_YES, 'a_viewlogs' => ACL_YES,
		'a_plus_ct_blocks' => ACL_NEVER, 'a_clearlogs' => $phase === 'log-clearer' ? ACL_YES : ACL_NEVER));
}
else { throw new RuntimeException('Unknown CrackerTracker fixture phase'); }
