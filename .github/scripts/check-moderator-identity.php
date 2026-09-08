<?php
require __DIR__ . '/check-topic-split-storage.php';
$lang['Session_invalid']='Session_invalid';

function moderator_identity_fixture()
{
	global $userdata;
	topic_split_fixture();
	$userdata['session_logged_in']=true; $userdata['session_id']='identity-fixture';
	$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'identity-fixture','confirm'=>'yes');
}
function moderator_identity_action($action)
{
	global $db;
	if ($action==='delete') { return phpbb_delete_moderated_topics($db,3,array(100)); }
	if ($action==='move') { return phpbb_move_topics($db,3,4,array(100)); }
	if ($action==='split') { return topic_split_run(); }
	return phpbb_moderate_topic_state($db,3,array(100),$action);
}
function moderator_identity_snapshot()
{
	$rows=array();
	foreach(array('fixture_topics','fixture_posts','fixture_post_text','fixture_matches','fixture_links','fixture_votes','fixture_vote_results','fixture_voters','fixture_bookmarks','fixture_views','fixture_watches','fixture_action_log') as $table)
	{ $rows[$table]=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC); }
	return $rows;
}
function moderator_identity_denied($action,$expected='Not_Moderator')
{
	$before=moderator_identity_snapshot(); $caught=false;
	try { moderator_identity_action($action); }
	catch (Exception $error) { $caught=$error->getMessage()===$expected && ($error instanceof MutationFailure || $error instanceof PhpbbTopicStateException || $error instanceof PhpbbTopicMoveException || $error instanceof PhpbbTopicSplitException); }
	mutation_check($caught,'Controlled identity rejection: '.$action.' / '.$expected);
	mutation_check(moderator_identity_snapshot()===$before && $GLOBALS['mutation_server']->owner===null,'Rejected identity cannot change topic/content/preferences/audit and releases owner: '.$action);
}
set_error_handler(function($severity,$message){ if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach(array('delete','lock','sticky','announce','move','split') as $action)
	{
		foreach(array('UPDATE fixture_users SET user_active=0 WHERE user_id=8','DELETE FROM fixture_users WHERE user_id=8','DELETE FROM fixture_groups','UPDATE fixture_groups SET user_pending=1','UPDATE fixture_auth SET auth_mod=0','UPDATE fixture_forums SET auth_view=5 WHERE forum_id=3','UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3') as $change)
		{
			moderator_identity_fixture(); $userdata['user_level']=ADMIN;
			$mutation_server->pdo->exec($change);
			moderator_identity_denied($action);
		}
		foreach(array(0,-1,null,true,'8junk','16777216',array(8)) as $bad)
		{
			moderator_identity_fixture(); $userdata['user_id']=$bad;
			moderator_identity_denied($action);
		}
		moderator_identity_fixture(); $userdata['session_logged_in']=false;
		moderator_identity_denied($action);
		// A real current administrator stays authorized even if the old session
		// role says ordinary user; group-less ordinary users do not gain access.
		moderator_identity_fixture(); $userdata['user_level']=0;
		$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8');
		$mutation_server->pdo->exec('DELETE FROM fixture_groups');
		moderator_identity_action($action);
		mutation_check($mutation_server->owner===null,'Current administrator keeps legitimate access: '.$action);
		moderator_identity_fixture(); $userdata['user_level']=0;
		$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=2 WHERE user_id=8');
		moderator_identity_action($action);
		mutation_check($mutation_server->owner===null,'Normal current forum moderator needs no ACP privilege: '.$action);
		moderator_identity_fixture(); $changed=false;
		$mutation_server->hook=function($sql) use(&$changed){ if(!$changed && strpos($sql,'SELECT user_id, user_level, user_active')===0) { $changed=true; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8'); } };
		moderator_identity_denied($action); mutation_check($changed,'Account recheck occurs on the owning connection: '.$action);
		moderator_identity_fixture(); $mutation_server->failure='SELECT user_id, user_level, user_active';
		$errors=array('delete'=>'Could not obtain current moderator account','lock'=>'Moderation_state_failed','sticky'=>'Moderation_state_failed','announce'=>'Moderation_state_failed','move'=>'Moderation_move_failed','split'=>'Moderation_split_failed');
		moderator_identity_denied($action,$errors[$action]);
	}
	moderator_identity_fixture(); $mutation_server->pdo->exec('UPDATE fixture_forums SET auth_delete=5 WHERE forum_id=3');
	moderator_identity_denied('delete');
	foreach(array('GET','missing','wrong','array') as $kind)
	{
		moderator_identity_fixture();
		if($kind==='GET') { $_SERVER['REQUEST_METHOD']='GET'; }
		elseif($kind==='missing') { unset($_POST['sid']); }
		else { $_POST['sid']=$kind==='array'?array('identity-fixture'):'wrong'; }
		moderator_identity_denied('delete','Session_invalid');
	}
	echo "Fresh moderator identity, inactive/demoted accounts, actual grants and mutation failure boundaries passed.\n";
}
finally { restore_error_handler(); }
