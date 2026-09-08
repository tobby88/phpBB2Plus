<?php
require __DIR__ . '/check-attachment-mutation.php';
foreach (array('ADMIN'=>1,'MOD'=>2,'USER'=>0,'ANONYMOUS'=>-1,'GENERAL_MESSAGE'=>200,'POST_USERS_URL'=>'u','USERS_TABLE'=>'fixture_users','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_memberships','AUTH_ACCESS_TABLE'=>'fixture_auth','FORUMS_TABLE'=>'fixture_forums','JR_ADMIN_TABLE'=>'fixture_junior','BANLIST_TABLE'=>'fixture_bans','SESSIONS_TABLE'=>'fixture_sessions') as $key=>$value) { define($key,$value); }
require $forum_root . 'includes/functions_userlist_storage.php';
$phpEx = 'php';
function userlist_fixture()
{
	global $mutation_server, $userdata;
	$mutation_server = new MutationServer(); $p = $mutation_server->pdo;
	$p->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_level INTEGER, user_active INTEGER)');
	$p->exec('INSERT INTO fixture_users VALUES (-1,0,1),(1,1,1),(8,1,1),(9,0,1),(10,0,0),(11,2,1)');
	$p->exec('CREATE TABLE fixture_groups (group_id INTEGER PRIMARY KEY, group_single_user INTEGER)');
	$p->exec('INSERT INTO fixture_groups VALUES (3,0),(4,1),(5,0),(6,0)');
	$p->exec('CREATE TABLE fixture_memberships (group_id INTEGER, user_id INTEGER, user_pending INTEGER)');
	$p->exec('INSERT INTO fixture_memberships VALUES (3,10,1),(4,9,0)');
	$p->exec('CREATE TABLE fixture_auth (group_id INTEGER, forum_id INTEGER, auth_mod INTEGER)');
	$p->exec('INSERT INTO fixture_auth VALUES (3,2,1),(5,99,1)');
	$p->exec('CREATE TABLE fixture_forums (forum_id INTEGER PRIMARY KEY)');
	$p->exec('INSERT INTO fixture_forums VALUES (2)');
	$p->exec('CREATE TABLE fixture_junior (user_id INTEGER, user_jr_admin VARCHAR(255))');
	$p->exec('CREATE TABLE fixture_bans (ban_userid INTEGER NOT NULL, ban_ip VARCHAR(8) NOT NULL, ban_email VARCHAR(255))');
	$p->exec("INSERT INTO fixture_bans VALUES (0,'7f000001',''),(0,'','fixture@example.invalid'),(1,'',''),(9,'','')");
	$p->exec('CREATE TABLE fixture_sessions (session_user_id INTEGER)');
	$p->exec('INSERT INTO fixture_sessions VALUES (-1),(1),(8),(9),(9),(10),(11)');
	$userdata = array('user_id'=>8,'user_level'=>1,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-session');
	$_POST = array('sid'=>'fixture-session'); $_SERVER['REQUEST_METHOD'] = 'POST';
}
function userlist_value($sql) { return (int) $GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
function userlist_failure($callback, $message)
{
	$caught = false;
	try { call_user_func($callback); } catch (PhpbbUserlistException $e) { $caught = $e->getMessage() === $message; }
	mutation_check($caught, 'Expected controlled userlist failure: ' . $message);
	mutation_check($GLOBALS['mutation_server']->owner === null, 'Failed action releases owner');
}
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach (array('activate','deactivate','ban','unban','group') as $action)
	{
		userlist_fixture();
		$result = phpbb_userlist_apply($db, $action, array(1,8,9,10,11,999,9), 3);
		mutation_check($result['changed'] + $result['unchanged'] === 6, 'Deduplicated result counts');
		mutation_check(userlist_value('SELECT SUM(user_active) FROM fixture_users WHERE user_id IN (1,8)') === 2, 'Current admin and actor remain active');
		mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id IN (-1,1,8)') === 3, 'Guest, actor and founder sessions preserved');
		mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id IN (9,10,11)') === 0, 'Eligible sessions invalidated');
		mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_bans WHERE ban_userid IN (0,1)') === 3, 'IP/email and protected user bans preserved');
		if ($action === 'activate' || $action === 'deactivate') { mutation_check(userlist_value('SELECT SUM(user_active) FROM fixture_users WHERE user_id IN (9,10,11)') === ($action === 'activate' ? 3 : 0), 'Requested status applied'); }
		if ($action === 'ban') { mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_bans WHERE ban_userid IN (9,10,11)') === 3, 'One user ban per target'); }
		if ($action === 'unban') { mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_bans WHERE ban_userid IN (9,10,11)') === 0, 'Only eligible user bans removed'); }
		if ($action === 'group')
		{
			mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=3 AND user_pending=0') === 3, 'Add and approve requested memberships');
			mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_users WHERE user_id IN (9,10,11) AND user_level=2') === 3, 'Real moderator group synchronizes moderator role');
			mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=4') === 1, 'Personal membership preserved');
		}
		mutation_check(phpbb_userlist_apply($db, $action, array(1,8,9,10,11,999), 3)['changed'] === 0, 'Repeated action is idempotent');
		mutation_check($mutation_server->owner === null, 'Successful action releases owner');
	}
	foreach (array(array(array(9)), array('9oops'), array(true), array(0), array(-1), array('16777216'), array('1e1'), '9', array(), array_fill(0,1001,9)) as $invalid)
	{
		userlist_fixture(); userlist_failure(function() use($db,$invalid) { phpbb_userlist_apply($db,'ban',$invalid); }, 'Admin_userlist_invalid_selection');
		mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions') === 7, 'Malformed original selection has no writes');
	}
	foreach (array(array(3), '3oops', true, 0) as $invalid)
	{
		userlist_fixture(); userlist_failure(function() use($db,$invalid) { phpbb_userlist_apply($db,'group',array(9),$invalid); }, 'Admin_userlist_invalid_selection');
	}
	foreach (array(4,999) as $invalid)
	{
		userlist_fixture(); userlist_failure(function() use($db,$invalid) { phpbb_userlist_apply($db,'group',array(9),$invalid); }, 'Admin_userlist_invalid_group');
		mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions') === 7, 'No sessions lost on invalid/personal group');
	}
	foreach (array(5,6) as $group)
	{
		userlist_fixture(); phpbb_userlist_apply($db,'group',array(9),$group);
		mutation_check(userlist_value('SELECT user_level FROM fixture_users WHERE user_id=9') === 0, 'Orphan ACL or ordinary group never grants moderator role');
	}
	foreach (array('inactive','deleted','demoted','no-admin-session','no-login','get','nested-sid','wrong-sid') as $case)
	{
		userlist_fixture(); $expected = 'Not_Authorised';
		if ($case === 'inactive') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8'); }
		if ($case === 'deleted') { $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=8'); }
		if ($case === 'demoted') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8'); }
		if ($case === 'no-admin-session') { $userdata['session_admin'] = false; }
		if ($case === 'no-login') { $userdata['session_logged_in'] = false; }
		if ($case === 'get') { $_SERVER['REQUEST_METHOD'] = 'GET'; $expected = 'Session_invalid'; }
		if ($case === 'nested-sid') { $_POST['sid'] = array('fixture-session'); $expected = 'Session_invalid'; }
		if ($case === 'wrong-sid') { $_POST['sid'] = 'wrong'; $expected = 'Session_invalid'; }
		userlist_failure(function() use($db) { phpbb_userlist_apply($db,'deactivate',array(9)); }, $expected);
		mutation_check(userlist_value('SELECT user_active FROM fixture_users WHERE user_id=9') === 1 && userlist_value('SELECT COUNT(*) FROM fixture_sessions') === 7, 'Denied actor/form has no writes');
	}
	userlist_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	$grant = md5('UsersUsers Listadmin_users_list.php');
	$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'" . $grant . "')");
	mutation_check(phpbb_userlist_apply($db,'deactivate',array(9))['changed'] === 1, 'Actual registered junior-admin module remains usable');
	$mutation_server->pdo->exec('DELETE FROM fixture_junior');
	userlist_failure(function() use($db) { phpbb_userlist_apply($db,'activate',array(9)); }, 'Not_Authorised');
	// Promote the target after the early reads, immediately before its write.
	foreach (array('activate'=>'UPDATE fixture_users','deactivate'=>'UPDATE fixture_users','ban'=>'INSERT INTO fixture_bans','unban'=>'DELETE FROM fixture_bans','group'=>'INSERT INTO fixture_memberships') as $action=>$prefix)
	{
		userlist_fixture();
		$mutation_server->hook = function($sql) use($prefix) {
			if (strpos($sql,$prefix) === 0) { $GLOBALS['mutation_server']->hook = null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=9'); }
		};
		mutation_check(phpbb_userlist_apply($db,$action,array(9),3)['changed'] === 0, 'Late promoted target protected at actual ' . $action . ' statement');
		mutation_check(userlist_value('SELECT user_active FROM fixture_users WHERE user_id=9') === 1 && userlist_value('SELECT COUNT(*) FROM fixture_bans WHERE ban_userid=9') === 1 && userlist_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=3 AND user_id=9') === 0, 'Late admin data preserved');
	}
	// No stale actor role/grant can authorize a later individual SQL write.
	foreach (array('deactivate'=>'UPDATE fixture_users','ban'=>'INSERT INTO fixture_bans','unban'=>'DELETE FROM fixture_bans','group'=>'INSERT INTO fixture_memberships') as $action=>$prefix)
	{
		foreach (array('root','junior') as $actor)
		{
			userlist_fixture();
			if ($actor === 'junior')
			{
				$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
				$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'" . $grant . "')");
			}
			$mutation_server->hook = function($sql) use($prefix,$actor) {
				if (strpos($sql,$prefix) === 0)
				{
					$GLOBALS['mutation_server']->hook = null;
					$GLOBALS['mutation_server']->pdo->exec($actor === 'root' ? 'UPDATE fixture_users SET user_level=0 WHERE user_id=8' : 'DELETE FROM fixture_junior');
				}
			};
			mutation_check(phpbb_userlist_apply($db,$action,array(9),3)['changed'] === 0, 'Late actor revocation guards actual ' . $action . ' write');
		}
	}
	userlist_fixture(); $mutation_server->hook = function($sql) {
		if (strpos($sql,'DELETE FROM fixture_sessions') === 0) { $GLOBALS['mutation_server']->hook = null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=9'); }
	};
	phpbb_userlist_apply($db,'ban',array(9));
	mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=9') === 2, 'Late promoted administrator also keeps sessions');
	userlist_fixture(); $mutation_server->failure = 'DELETE FROM fixture_sessions';
	userlist_failure(function() use($db) { phpbb_userlist_apply($db,'deactivate',array(9)); }, 'Admin_userlist_storage_failed');
	mutation_check(userlist_value('SELECT user_active FROM fixture_users WHERE user_id=9') === 1, 'Failed session invalidation prevents status write');
	userlist_fixture(); $mutation_server->failure = 'INSERT INTO fixture_memberships';
	userlist_failure(function() use($db) { phpbb_userlist_apply($db,'group',array(9),3); }, 'Admin_userlist_storage_failed');
	mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=9') === 0 && userlist_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=3 AND user_id=9') === 0, 'Partial failure is explicit, not a fictional rollback');
	userlist_fixture(); $mutation_server->hook = function($sql) {
		if (strpos($sql,'INSERT INTO fixture_memberships') === 0) { $GLOBALS['mutation_server']->hook = null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_groups SET group_single_user=1 WHERE group_id=3'); }
	};
	userlist_failure(function() use($db) { phpbb_userlist_apply($db,'group',array(9),3); }, 'Admin_userlist_storage_failed');
	mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=3 AND user_id=9') === 0, 'Late personal group not modified');
	userlist_fixture(); $interleaved = false;
	$mutation_server->hook = function($sql) use($db,&$interleaved) {
		if ($interleaved || strpos($sql,'DELETE FROM fixture_sessions') !== 0) { return; }
		$interleaved = true; $caught = false;
		try { phpbb_userlist_apply($db,'ban',array(9)); } catch (PhpbbUserlistException $e) { $caught = $e->getMessage() === 'busy'; }
		mutation_check($caught, 'Competing bulk writer cannot interleave');
	};
	phpbb_userlist_apply($db,'deactivate',array(9));
	mutation_check($interleaved && $mutation_server->owner === null, 'Competing call does not release original owner');
	// Execute the real controller branch, including its unmodified POST input.
	$controller = file_get_contents($forum_root . 'admin/admin_users_list.php');
	$start = strpos($controller, '$selected_users = isset('); $end = strpos($controller, '$template->set_filenames', $start);
	mutation_check($start !== false && $end !== false, 'Actual controller branch located');
	$branch = substr($controller,$start,$end-$start); $phpbb_root_path = $forum_root;
	$lang['Admin_userlist_result'] = '%d changed; %d unchanged'; $lang['Click_return_userlist'] = '%sReturn%s';
	userlist_fixture(); $_POST['u'] = array(9); $_POST['bulk_action'] = 'deactivate';
	mutation_expect_failure(function() use($branch,$db,$phpbb_root_path,$phpEx,$lang) { eval($branch); }, '1 changed; 0 unchanged<br /><br /><a href="admin_users_list.php">Return</a>');
	mutation_check(userlist_value('SELECT user_active FROM fixture_users WHERE user_id=9') === 0, 'Controller delegates real mutation');
	userlist_fixture(); $_POST['u'] = array(array(9)); $_POST['bulk_action'] = 'group'; $_POST['group_id'] = array(3);
	mutation_expect_failure(function() use($branch,$db,$phpbb_root_path,$phpEx,$lang) { eval($branch); }, 'Admin_userlist_invalid_selection');
	mutation_check(userlist_value('SELECT COUNT(*) FROM fixture_sessions') === 7, 'Controller rejects nested original values without coercion');
	echo "Coordinated userlist bulk actions, protected targets, group roles and sessions passed.\n";
}
finally { if (isset($mutation_server) && $mutation_server->owner !== null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
function phpbb_admin_require_post_session() { mutation_check($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['sid'] === $GLOBALS['userdata']['session_id'], 'Actual controller calls ACP POST boundary'); }
function append_sid($value) { return $value; }
