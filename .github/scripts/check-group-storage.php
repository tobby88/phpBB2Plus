<?php
require __DIR__ . '/check-attachment-mutation.php';
foreach (array('ADMIN'=>1,'MOD'=>2,'USER'=>0,'GROUP_OPEN'=>0,'GROUP_CLOSED'=>1,'GROUP_HIDDEN'=>2,'GENERAL_MESSAGE'=>200,'POST_GROUPS_URL'=>'g','USERS_TABLE'=>'fixture_users','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_memberships','AUTH_ACCESS_TABLE'=>'fixture_auth','FORUMS_TABLE'=>'fixture_forums','SESSIONS_TABLE'=>'fixture_sessions') as $key=>$value) { define($key,$value); }
require $forum_root . 'includes/functions_group_storage.php';
// Extract unchanged function bodies; do not load common.php or a real forum DB.
function group_test_function($source,$name)
{
	$tokens = token_get_all($source); $capture = false; $seen = false; $depth = 0; $result = '';
	foreach ($tokens as $i=>$token)
	{
		if (!$capture && is_array($token) && $token[0] === T_FUNCTION)
		{
			$j = $i + 1; while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
			$capture = is_array($tokens[$j]) && $tokens[$j][1] === $name;
		}
		if (!$capture) { continue; }
		$result .= is_array($token) ? $token[1] : $token;
		if ($token === '{') { $depth++; $seen = true; }
		if ($token === '}' && --$depth === 0 && $seen) { return $result; }
	}
	throw new RuntimeException('Fixture function not found: ' . $name);
}
function group_fixture($actor = 8)
{
	global $mutation_server,$userdata,$phpEx,$phpbb_root_path;
	$mutation_server = new MutationServer(); $p = $mutation_server->pdo;
	$p->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY,user_level INTEGER,user_active INTEGER,username VARCHAR(255),user_email VARCHAR(255),user_lang VARCHAR(20))');
	$p->exec("INSERT INTO fixture_users VALUES (1,1,1,'Root','root@example.invalid','english'),(8,2,1,'Leader','leader@example.invalid','german'),(9,0,1,'Member','member@example.invalid','english'),(10,0,1,'Pending','pending@example.invalid','german'),(11,0,1,'Stranger','stranger@example.invalid','english'),(12,0,1,'O''Brien','obrien@example.invalid','english')");
	$p->exec('CREATE TABLE fixture_groups (group_id INTEGER PRIMARY KEY,group_type INTEGER,group_single_user INTEGER,group_moderator INTEGER,group_name VARCHAR(255))');
	$p->exec("INSERT INTO fixture_groups VALUES (3,0,0,8,'Grüße &amp; Test'),(4,0,1,9,'Personal'),(5,1,0,1,'Closed'),(6,2,0,1,'Hidden'),(7,0,0,1,'Other')");
	$p->exec('CREATE TABLE fixture_memberships (user_id INTEGER,group_id INTEGER,user_pending INTEGER)');
	$p->exec('INSERT INTO fixture_memberships VALUES (8,3,0),(9,3,0),(10,3,1),(11,7,1),(9,4,0)');
	$p->exec('CREATE TABLE fixture_auth (group_id INTEGER,forum_id INTEGER,auth_mod INTEGER)');
	$p->exec('INSERT INTO fixture_auth VALUES (3,2,1),(7,2,1)');
	$p->exec('CREATE TABLE fixture_forums (forum_id INTEGER PRIMARY KEY)'); $p->exec('INSERT INTO fixture_forums VALUES (2)');
	$p->exec('CREATE TABLE fixture_sessions (session_user_id INTEGER)'); $p->exec('INSERT INTO fixture_sessions VALUES (1),(8),(9),(10),(11),(12)');
	$userdata = array('user_id'=>$actor,'user_level'=>$actor === 1 ? 1 : ($actor === 8 ? 2 : 0),'session_logged_in'=>true,'session_id'=>'fixture-session');
	$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('sid'=>'fixture-session'); $_GET = array();
	$phpEx = 'php'; $phpbb_root_path = $GLOBALS['forum_root'];
}
function group_value($sql) { return (int) $GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
function group_failure($callback,$expected)
{
	$caught = false; try { call_user_func($callback); } catch (PhpbbGroupException $e) { $caught = $e->getMessage() === $expected; }
	mutation_check($caught,'Expected group failure: ' . $expected);
	mutation_check($GLOBALS['mutation_server']->owner === null,'Failure releases group owner');
}
class PhpbbMailException extends RuntimeException {}
class GroupMailerDouble
{
	var $address; var $template; var $language; var $vars;
	function __construct($smtp,$optional) { mutation_check($optional && $GLOBALS['mutation_server']->owner === null,'Optional mail transport runs only after writer release'); }
	function from($value) {}
	function replyto($value) {}
	function email_address($value) { $this->address = $value; }
	function use_template($value,$language) { $this->template = $value; $this->language = $language; }
	function set_subject($value) {}
	function assign_vars($value) { $this->vars = $value; }
	function send()
	{
		$GLOBALS['group_test_deliveries'][] = array($this->address,$this->template,$this->language,$this->vars);
		if (!empty($GLOBALS['group_test_mail_failure'])) { throw new PhpbbMailException('Fixture transport failure'); }
		return true;
	}
}
class GroupControllerReadDatabase
{
	function sql_query($sql) { mutation_check(strpos($sql,'SELECT ') === 0,'Controller must not retain unlocked mutations'); return $GLOBALS['mutation_server']->pdo->query($sql); }
	function sql_fetchrow($r) { return $r->fetch(PDO::FETCH_ASSOC); }
	function sql_freeresult($r) { $r->closeCursor(); }
}
class GroupControllerTemplate { function assign_vars($value) {} }
function phpbb_board_url($path) { return 'https://fixture.invalid/' . $path; }
function append_sid($value) { return $value; }
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	group_fixture(11); $out = phpbb_group_change($db,'join',3);
	mutation_check($out['changed'] === array(11) && $out['recipients'][0]['user_id'] == 8,'Only new join notifies actual leader');
	mutation_check(group_value('SELECT user_pending FROM fixture_memberships WHERE user_id=11 AND group_id=3') === 1 && group_value('SELECT user_level FROM fixture_users WHERE user_id=11') === 0,'Join is pending, not moderator');
	mutation_check(phpbb_group_change($db,'join',3)['recipients'] === array(),'Repeated join has no second mail');
	group_fixture(); $out = phpbb_group_change($db,'approve',3,array(10,11,9,10,999));
	mutation_check($out['changed'] === array(10) && count($out['recipients']) === 1,'Approval only changes actual pending members of this group');
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=10') === 2 && group_value('SELECT user_level FROM fixture_users WHERE user_id=11') === 0,'Unrelated selected account does not gain moderator status');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id IN (1,8,9,11,12)') === 5,'Only changed other member loses cached sessions');
	mutation_check(phpbb_group_change($db,'approve',3,array(10))['changed'] === array(),'Repeated approval is unchanged');
	group_fixture(); $out = phpbb_group_change($db,'add',3,'O\'Brien');
	mutation_check($out['changed'] === array(12) && group_value('SELECT user_level FROM fixture_users WHERE user_id=12') === 2,'Exact apostrophe username is escaped once and added');
	mutation_check(phpbb_group_change($db,'add',3,'O\'Brien')['recipients'] === array(),'Repeated add has no mail');
	group_fixture(); mutation_check(phpbb_group_change($db,'add',3,'Pending')['changed'] === array(10),'Explicit add approves pending member');
	group_fixture(); $out = phpbb_group_change($db,'deny',3,array(9,10,11,8));
	mutation_check($out['changed'] === array(10) && $out['recipients'] === array(),'Denial only removes this group pending membership');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE user_id=9 AND group_id=3') === 1,'Denial cannot remove already approved membership');
	group_fixture(); $out = phpbb_group_change($db,'remove',3,array(8,9,10,11));
	mutation_check($out['changed'] === array(9),'Removal preserves group leader, pending and unrelated users');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE user_id=9 AND group_id=4') === 1,'Personal group membership preserved');
	foreach (array('unsubscribe'=>9,'unsubscribe_pending'=>10) as $action=>$actor)
	{
		group_fixture($actor); $out = phpbb_group_change($db,$action,3);
		mutation_check($out['changed'] === array($actor),'Self unsubscribe matches membership state');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions') === 6,'Self unsubscribe does not log out actor');
	}
	group_fixture(9); mutation_check(phpbb_group_change($db,'unsubscribe_pending',3)['changed'] === array(),'Pending withdrawal cannot remove approved membership');
	group_fixture(); mutation_check(phpbb_group_change($db,'unsubscribe',3)['changed'] === array(),'Leader cannot remove own mandatory membership');
	foreach (array('no-other','pending-other','approved-other','orphan-forum','promoted-admin') as $case)
	{
		group_fixture(9); $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=2 WHERE user_id=9');
		if ($case === 'pending-other' || $case === 'approved-other') { $mutation_server->pdo->exec('INSERT INTO fixture_memberships VALUES (9,7,' . ($case === 'pending-other' ? 1 : 0) . ')'); }
		if ($case === 'orphan-forum') { $mutation_server->pdo->exec('INSERT INTO fixture_memberships VALUES (9,7,0)'); $mutation_server->pdo->exec('DELETE FROM fixture_forums'); }
		if ($case === 'promoted-admin')
		{
			$mutation_server->hook = function($sql) { if (strpos($sql,'UPDATE fixture_users') === 0) { $GLOBALS['mutation_server']->hook = null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=9'); } };
		}
		phpbb_group_change($db,'unsubscribe',3);
		mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9') === ($case === 'promoted-admin' ? 1 : ($case === 'approved-other' ? 2 : 0)),'Derived role ignores pending/orphan ACL and preserves late admin: ' . $case);
	}
	foreach (array(8,1) as $actor)
	{
		group_fixture($actor); mutation_check(phpbb_group_change($db,'status',3,GROUP_HIDDEN)['changed'] === array(3),'Current leader/root can change status');
	}
	foreach (array('status','add','approve','deny','remove') as $action)
	{
		group_fixture(11); $value = $action === 'status' ? GROUP_CLOSED : ($action === 'add' ? 'Pending' : array(10));
		group_failure(function() use($db,$action,$value) { phpbb_group_change($db,$action,3,$value); },'Not_group_moderator');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions') === 6,'Unprivileged group management has no writes');
	}
	foreach (array('inactive','deleted','get','nested-sid','wrong-sid') as $case)
	{
		group_fixture(); $error = 'Not_Authorised';
		if ($case === 'inactive') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8'); }
		if ($case === 'deleted') { $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=8'); }
		if ($case === 'get') { $_SERVER['REQUEST_METHOD'] = 'GET'; $error = 'Session_invalid'; }
		if ($case === 'nested-sid') { $_POST['sid'] = array('fixture-session'); $error = 'Session_invalid'; }
		if ($case === 'wrong-sid') { $_POST['sid'] = 'wrong'; $error = 'Session_invalid'; }
		group_failure(function() use($db) { phpbb_group_change($db,'approve',3,array(10)); },$error);
	}
	foreach (array(array(3),'3oops',true,0,-1,'16777216') as $invalid)
	{
		group_fixture(); group_failure(function() use($db,$invalid) { phpbb_group_change($db,'join',$invalid); },'Group_invalid_selection');
	}
	foreach (array(array(array(10)),array('10oops'),array(true),array(0),'10',array_fill(0,1001,10)) as $invalid)
	{
		group_fixture(); group_failure(function() use($db,$invalid) { phpbb_group_change($db,'approve',3,$invalid); },'Group_invalid_selection');
	}
	foreach (array(4,999) as $invalid)
	{
		group_fixture(); group_failure(function() use($db,$invalid) { phpbb_group_change($db,'approve',$invalid,array(10)); },'Group_not_exist');
	}
	foreach (array(5,6) as $closed)
	{
		group_fixture(11); group_failure(function() use($db,$closed) { phpbb_group_change($db,'join',$closed); },'This_closed_group');
	}
	foreach (array('closed','personal','deleted') as $case)
	{
		group_fixture(11); $mutation_server->hook = function($sql) use($case) {
			if (strpos($sql,'INSERT INTO fixture_memberships') === 0)
			{
				$GLOBALS['mutation_server']->hook = null;
				$GLOBALS['mutation_server']->pdo->exec($case === 'deleted' ? 'DELETE FROM fixture_groups WHERE group_id=3' : 'UPDATE fixture_groups SET ' . ($case === 'closed' ? 'group_type=1' : 'group_single_user=1') . ' WHERE group_id=3');
			}
		};
		group_failure(function() use($db) { phpbb_group_change($db,'join',3); },$case === 'closed' ? 'This_closed_group' : 'Group_not_exist');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE user_id=11 AND group_id=3') === 0,'Changed group does not accept stale join');
	}
	foreach (array('leader','root') as $actor)
	{
		group_fixture($actor === 'root' ? 1 : 8);
		$mutation_server->hook = function($sql) use($actor) {
			if (strpos($sql,'UPDATE fixture_memberships') === 0)
			{
				$GLOBALS['mutation_server']->hook = null;
				$GLOBALS['mutation_server']->pdo->exec($actor === 'root' ? 'UPDATE fixture_users SET user_level=0 WHERE user_id=1' : 'UPDATE fixture_groups SET group_moderator=1 WHERE group_id=3');
			}
		};
		group_failure(function() use($db) { phpbb_group_change($db,'approve',3,array(10)); },'Not_group_moderator');
		mutation_check(group_value('SELECT user_pending FROM fixture_memberships WHERE user_id=10 AND group_id=3') === 1,'Late actor revocation prevents approval');
	}
	foreach (array('DELETE FROM fixture_sessions','UPDATE fixture_memberships','UPDATE fixture_users','SELECT user_id, username') as $failure)
	{
		group_fixture(); $mutation_server->failure = $failure;
		group_failure(function() use($db) { phpbb_group_change($db,'approve',3,array(10)); },'Group_storage_failed');
		mutation_check(group_value('SELECT user_pending FROM fixture_memberships WHERE user_id=10 AND group_id=3') === (in_array($failure,array('DELETE FROM fixture_sessions','UPDATE fixture_memberships'),true) ? 1 : 0),'Storage failure does not pretend MyISAM rollback');
	}
	group_fixture(); $interleaved = false;
	$mutation_server->hook = function($sql) use($db,&$interleaved) {
		if ($interleaved || strpos($sql,'UPDATE fixture_memberships') !== 0) { return; }
		$interleaved = true; $caught = false;
		try { phpbb_group_change($db,'deny',3,array(10)); } catch (PhpbbGroupException $e) { $caught = $e->getMessage() === 'busy'; }
		mutation_check($caught,'Concurrent membership writer cannot interleave');
	};
	phpbb_group_change($db,'approve',3,array(10)); mutation_check($interleaved && $mutation_server->owner === null,'Owner released after coordinated approval');
	// Execute real controller wrappers, with nested original POST data intact.
	$controller = file_get_contents($forum_root . 'groupcp.php');
	eval(group_test_function($controller,'groupcp_change'));
	group_fixture(); $_POST['g'] = array(3); $_GET['g'] = 3;
	mutation_expect_failure(function() { groupcp_change('approve',array(10)); },'Group_invalid_selection');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions') === 6,'Malformed POST group must not fall back to a valid GET group');
	group_fixture(); $_POST['g'] = 3; mutation_check(groupcp_change('approve',array(10))['changed'] === array(10),'Actual controller wrapper applies approved membership');
	$notification_source = file_get_contents($forum_root . 'includes/functions_group_notifications.php');
	$notification = group_test_function($notification_source,'phpbb_group_notify');
	// Only the transport constructor/include is substituted. Recipient routing,
	// template/language selection, optional failure handling and text are real.
	$notification = str_replace("require_once \$phpbb_root_path . 'includes/emailer.' . \$phpEx;", '', $notification);
	$notification = str_replace('new emailer(', 'new GroupMailerDouble(', $notification);
	eval($notification);
	$board_config = array('smtp_delivery'=>false,'board_email'=>'board@example.invalid','sitename'=>'Fixture','board_email_sig'=>'Signature');
	$lang = array_merge($lang,array('Group_request'=>'Request','Group_added'=>'Added','Group_approved'=>'Approved','Group_mail_failed'=>'Mail failed','Group_members_changed'=>'%d memberships changed','Click_return_group'=>'%sReturn%s','Click_return_index'=>'%sIndex%s','Group_type_updated'=>'Type updated','Group_joined'=>'Joined','Already_member_group'=>'Already member','Unsub_success'=>'Unsubscribed'));
	foreach (array('join','add','approve') as $action)
	{
		group_fixture($action === 'join' ? 11 : 8); $group_test_deliveries = array(); $group_test_mail_failure = false;
		$out = phpbb_group_change($db,$action,3,$action === 'add' ? 'Stranger' : array(10));
		mutation_check(phpbb_group_notify($out) && count($group_test_deliveries) === 1,'Actual notification routing sends once after ' . $action);
		mutation_check($group_test_deliveries[0][2] === ($action === 'add' ? 'english' : 'german'),'Recipient language preserved');
		mutation_check($group_test_deliveries[0][3]['GROUP_NAME'] === 'Grüße & Test','Mail uses decoded UTF-8 group name');
		$group_test_mail_failure = true;
		mutation_check(!phpbb_group_notify($out),'Optional delivery failure reported without rolling back membership');
		mutation_check($mutation_server->owner === null,'Mail failure cannot retain lock');
	}
	$group_test_mail_failure = false;
	eval(group_test_function($controller,'groupcp_require_post_session'));
	$notify_wrapper = group_test_function($controller,'groupcp_notify');
	$notify_wrapper = str_replace("require_once \$phpbb_root_path . 'includes/functions_group_notifications.' . \$phpEx;", '', $notify_wrapper);
	eval($notify_wrapper);
	$branch_start = strpos($controller,"if ( isset(\$_POST['groupstatus']) && \$group_id )");
	$branch_end = strpos($controller,"\t//\n\t// Get group details",$branch_start);
	mutation_check($branch_start !== false && $branch_end !== false,'Actual group mutation branches located');
	$branch = substr($controller,$branch_start,$branch_end-$branch_start) . "\n}";
	foreach (array('status','join','unsubscribe','unsubscribe_pending','add','approve','deny','remove') as $action)
	{
		group_fixture($action === 'join' ? 11 : ($action === 'unsubscribe' ? 9 : ($action === 'unsubscribe_pending' ? 10 : 8)));
		$keys = array('status'=>'groupstatus','join'=>'joingroup','unsubscribe'=>'unsub','unsubscribe_pending'=>'unsubpending','add'=>'add','approve'=>'approve','deny'=>'deny','remove'=>'remove');
		$_POST[$keys[$action]] = '1'; $_POST['g'] = 3; $_POST['group_type'] = '1'; $_POST['username'] = 'Stranger'; $_POST['members'] = array(9); $_POST['pending_members'] = array(10);
		$group_test_deliveries = array(); $caught = false;
		try
		{
			call_user_func(function() use($branch,$userdata,$lang,$phpEx) {
				$db = new GroupControllerReadDatabase(); $template = new GroupControllerTemplate();
				$group_id = 3; $sid = 'fixture-session'; $confirm = true; $cancel = false;
				eval($branch);
			});
		}
		catch (MutationFailure $e) { $caught = strpos($e->getMessage(),'Return') !== false; }
		mutation_check($caught && $mutation_server->owner === null,'Actual controller finishes ' . $action . ' outside lock');
		mutation_check(count($group_test_deliveries) === (in_array($action,array('join','add','approve'),true) ? 1 : 0),'Actual controller sends only transition-specific notifications');
	}
	group_fixture(); $mutation_server->pdo->exec("UPDATE fixture_users SET username='O&#039;Brien' WHERE user_id=12");
	mutation_check(phpbb_group_change($db,'add',3,'O\'Brien')['changed'] === array(12),'Legacy entity-stored apostrophe name remains usable');
	group_fixture(); $mutation_server->pdo->exec("UPDATE fixture_users SET username='O&#039;Brien' WHERE user_id=11");
	group_failure(function() use($db) { phpbb_group_change($db,'add',3,'O\'Brien'); },'Could_not_add_user');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions') === 6,'Ambiguous raw/entity name does not select another account');
	echo "Coordinated group lifecycle, current owner/roles and pending-state checks passed.\n";
}
finally { if (isset($mutation_server) && $mutation_server->owner !== null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
