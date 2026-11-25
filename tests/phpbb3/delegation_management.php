<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$test_id = 91;
$result = $db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=' . $test_id);
if ($db->sql_fetchfield('username') !== 'JuniorFixture') { throw new RuntimeException('Missing delegation management fixture'); }
$db->sql_freeresult($result);
$work = $table_prefix . 'plus_jr_delegations';
$source = $table_prefix . 'plus_jr_records';
switch ($argv[2])
{
	case 'operators':
		require_once './includes/acp/auth.php';
		require_once './includes/functions_module.php';
		$writer = new auth_admin();
		$manager = $phpbb_container->get('phpbb2plus.community.delegations');
		$load = function () use ($db, $test_id, $user, $auth)
		{
			$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $test_id);
			$user->data = $db->sql_fetchrow($result) + array('is_registered' => true, 'session_admin' => 1);
			$db->sql_freeresult($result);
			$auth->acl($user->data);
		};
		try
		{
			foreach (array(array(ACL_YES, ACL_NO), array(ACL_NO, ACL_YES), array(ACL_YES, ACL_YES)) as $pair)
			{
				$writer->acl_set('user', 0, $test_id, array('a_authusers' => $pair[0], 'a_aauth' => $pair[1]));
				$load();
				if (\phpbb2plus\community\auth\delegations::permitted($auth, $user->data) !== ($pair === array(ACL_YES, ACL_YES)))
				{ throw new RuntimeException('Changing delegations requires the two independent native administration permissions'); }
			}
			foreach (array(array($test_id, array(), 'PLUS_JR_NO_CHANGE'), array(87, array('a_aauth'), 'PLUS_JR_INVALID_GRANT')) as $invalid)
			{
				try { $manager->save($auth, $user->data, '127.0.0.1', $invalid[0], 0, $invalid[1], '', false); }
				catch (DomainException $e) { if ($e->getMessage() === $invalid[2]) { continue; } throw $e; }
				throw new RuntimeException('The delegated manager could expand its own access or grant an unreviewed module');
			}
			$user->data['user_perm_from'] = 2;
			if (\phpbb2plus\community\auth\delegations::permitted($auth, $user->data)) { throw new RuntimeException('A permission trace could change a delegation'); }
		}
		finally { $writer->acl_set('user', 0, $test_id, array('a_authusers' => ACL_NO, 'a_aauth' => ACL_NO)); }
		break;
	case 'audit':
		$result = $db->sql_query("SELECT log_data FROM " . LOG_TABLE . " WHERE log_operation='LOG_PLUS_JR_UPDATED'");
		$logs = array_column($db->sql_fetchrowset($result), 'log_data');
		$db->sql_freeresult($result);
		if (count($logs) < 4 || strpos(implode('', $logs), 'JuniorFixture') === false || strpos(implode('', $logs), 'Vermerk') !== false)
		{ throw new RuntimeException('Delegation changes were not logged privately for the correct member'); }
		break;
	case 'never':
	case 'reset':
		require_once './includes/acp/auth.php';
		(new auth_admin())->acl_set('user', 0, $test_id, array('a_words' => $argv[2] === 'never' ? ACL_NEVER : ACL_NO));
		break;
	case 'backfill':
		// Exercise the upgrade step on a prior conversion with notes, a remapped
		// member and an orphan. Only the new live records are reconstructed.
		$db->sql_query("DELETE FROM $work WHERE user_id=$test_id");
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_jr_delegate=0 WHERE user_id=$test_id");
		\phpbb2plus\community\auth\delegations::import($db, $table_prefix);
		$result = $db->sql_query("SELECT COUNT(*) AS matching FROM $work d JOIN $source s ON s.user_id=d.user_id JOIN " . USERS_TABLE . " u ON u.user_id=d.user_id
			WHERE d.user_id=$test_id AND d.rights_json=s.rights_json AND d.notes_b64=s.notes_b64 AND d.notes_view=s.notes_view
			AND d.start_date=s.start_date AND d.update_date=s.update_date AND d.revision=1 AND u.plus_jr_delegate=1");
		if ((int) $db->sql_fetchfield('matching') !== 1) { throw new RuntimeException('Previous Junior Admin conversion could not be upgraded'); }
		$db->sql_freeresult($result);
		break;
	case 'recheck':
		\phpbb2plus\community\auth\delegations::import($db, $table_prefix);
		$result = $db->sql_query("SELECT s.notes_b64 AS original,d.rights_json AS current_rights,u.plus_jr_delegate FROM $work d JOIN $source s ON s.user_id=d.user_id
			JOIN " . USERS_TABLE . " u ON u.user_id=d.user_id WHERE d.user_id=$test_id");
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if ($row['current_rights'] !== '[]' || $row['plus_jr_delegate'] || base64_decode($row['original'], true) !== 'Fixture <only> & 😀')
		{ throw new RuntimeException('Reapplying import resurrected a revocation or changed the original private record'); }
		break;
	default: exit(2);
}
