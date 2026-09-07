<?php
require __DIR__ . '/check-ajax-edit-storage.php';
require $forum_root . 'includes/functions_poll_storage.php';
$ip_source=file_get_contents($forum_root.'includes/functions.php');
$ip_start=strpos($ip_source,'function encode_ip('); $ip_end=strpos($ip_source,'function decode_ip(',$ip_start);
mutation_check($ip_start!==false && $ip_end>$ip_start,'Locate the actual legacy IP encoder');
eval(substr($ip_source,$ip_start,$ip_end-$ip_start));
foreach (array('Poll_storage_failed','Poll_vote_denied','Poll_expired','No_vote_option','Vote_cast','Already_voted') as $key) { $lang[$key]=$key; }
function poll_fixture()
{
	global $mutation_server, $user_ip;
	ajax_storage_fixture(); $p=$mutation_server->pdo;
	$user_ip=encode_ip('127.0.0.1');
	$p->exec('ALTER TABLE fixture_voters ADD vote_user_ip CHAR(8)');
	$p->exec("INSERT INTO fixture_votes VALUES (1,100,'Fixture poll',1,0)");
	$p->exec("INSERT INTO fixture_vote_results VALUES (1,1,'First',0),(1,2,'Second',0)");
}
function poll_failure($callback,$expected)
{
	$caught=false;
	try { call_user_func($callback); } catch (PhpbbPollStorageException $error) { $caught=$error->getMessage()===$expected; }
	mutation_check($caught,'Expected poll error: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Poll failure releases owning connection');
}
// Only the read-only ballot/response lookup may use the unlocked connection.
class PollReadDatabase extends MutationForum
{
	function sql_escape($value) { return substr($GLOBALS['mutation_server']->pdo->quote($value),1,-1); }
	function sql_query($sql)
	{
		mutation_check(preg_match('/^SELECT\s/',$sql)===1,'Endpoint must not write outside the poll worker');
		$result=new stdClass(); $result->rows=$GLOBALS['mutation_server']->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); $result->position=0; return $result;
	}
	function sql_fetchrow($result) { return isset($result->rows[$result->position]) ? $result->rows[$result->position++] : false; }
	function sql_freeresult($result) {}
	function sql_numrows($result) { return count($result->rows); }
	function sql_fetchrowset($result) { return $result->rows; }
}
class PollTemplateFixture
{
	var $files=array(); var $vars=array(); var $blocks=array();
	function set_filenames($files) { $this->files=array_merge($this->files,$files); }
	function assign_vars($vars) { $this->vars=array_merge($this->vars,$vars); }
	function assign_block_vars($name,$values) { $this->blocks[$name]=true; }
	function assign_var_from_handle($name,$handle) {}
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	poll_fixture();
	mutation_check(phpbb_cast_poll_vote($db,100,1)==='Vote_cast','First registered vote succeeds');
	$user_ip=encode_ip('2001:db8::8');
	mutation_check(phpbb_cast_poll_vote($db,100,2)==='Already_voted','Registered identity is independent of IP and chosen option');
	mutation_check((int)posting_value('SELECT SUM(vote_result) FROM fixture_vote_results')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_voters')===1,'Duplicate vote changes neither count nor voter rows');
	$userdata['user_id']=9;
	mutation_check(phpbb_cast_poll_vote($db,100,2)==='Vote_cast','Another member may vote from the same IP');
	poll_fixture(); $userdata['session_logged_in']=false; $userdata['user_id']=ANONYMOUS;
	$mutation_server->pdo->exec('UPDATE fixture_forums SET auth_view=0,auth_read=0,auth_vote=0'); $user_ip=encode_ip('2001:db8::1');
	mutation_check(phpbb_cast_poll_vote($db,100,1)==='Vote_cast','Guest can vote when explicitly permitted');
	mutation_check(phpbb_cast_poll_vote($db,100,2)==='Already_voted','Guest IP identifies a repeated vote');
	$user_ip=encode_ip('2001:db8::2');
	mutation_check(phpbb_cast_poll_vote($db,100,2)==='Vote_cast','Separate guest IP is not conflated with all anonymous users');
	foreach(array(
		array('UPDATE fixture_forums SET auth_vote=5','Poll_vote_denied'),
		array('UPDATE fixture_forums SET auth_read=5','Topic_post_not_exist'),
		array('UPDATE fixture_forums SET auth_view=5','Topic_post_not_exist'),
		array('UPDATE fixture_forums SET forum_status=1','Forum_locked'),
		array('UPDATE fixture_topics SET topic_status=1','Topic_locked'),
		array('UPDATE fixture_topics SET topic_moved_id=99','Topic_post_not_exist'),
		array('DELETE FROM fixture_topics','Topic_post_not_exist'),
		array('DELETE FROM fixture_forums','Topic_post_not_exist'),
		array('DELETE FROM fixture_votes','Topic_post_not_exist'),
		array('DELETE FROM fixture_vote_results WHERE vote_option_id=1','No_vote_option'),
		array('UPDATE fixture_votes SET vote_start=1,vote_length=1','Poll_expired'),
		array("INSERT INTO fixture_votes VALUES (2,100,'Duplicate',1,0)",'Topic_post_not_exist')
	) as $case)
	{
		poll_fixture(); $mutation_server->pdo->exec($case[0]);
		poll_failure(function() use($db) { phpbb_cast_poll_vote($db,100,1); },$case[1]);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_voters')===0,'Rejected vote creates no voter marker');
	}
	foreach(array(0,-1,'1x','1,2',array(1),'9223372036854775808') as $id)
	{
		poll_fixture(); poll_failure(function() use($db,$id) { phpbb_cast_poll_vote($db,100,$id); },'No_vote_option');
	}
	poll_fixture(); $mutation_server->pdo->exec("UPDATE fixture_votes SET vote_start=".(time()+3600).",vote_length=100");
	mutation_check(phpbb_cast_poll_vote($db,100,1)==='Vote_cast','A poll with a future expiry is open');
	poll_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (3,7,1)');
	$mutation_server->pdo->exec('INSERT INTO fixture_groups VALUES (8,7,0)');
	$mutation_server->pdo->exec('UPDATE fixture_topics SET topic_status=1'); $mutation_server->pdo->exec('UPDATE fixture_forums SET forum_status=1');
	mutation_check(phpbb_cast_poll_vote($db,100,1)==='Vote_cast','Current moderator may vote in a locked topic/forum');
	poll_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if($interleaved || strpos($sql,'INSERT INTO fixture_voters')!==0) { return; }
		$interleaved=true; $caught=false;
		try { phpbb_cast_poll_vote($GLOBALS['db'],100,2); } catch(PhpbbPollStorageException $error) { $caught=$error->getMessage()==='busy'; }
		mutation_check($caught,'Overlapping endpoint cannot record a second vote');
		$caught=false; try { posting_delete(); } catch(MutationFailure $error) { $caught=$error->getMessage()==='busy'; }
		mutation_check($caught,'Topic deletion cannot run while vote storage owns the lock');
	};
	mutation_check(phpbb_cast_poll_vote($db,100,1)==='Vote_cast' && $interleaved,'Interleaved vote still records exactly once'); $mutation_server->hook=null;
	foreach(array('SELECT vd.','SELECT a.forum_id','SELECT vote_id FROM fixture_voters','INSERT INTO fixture_voters','UPDATE fixture_vote_results') as $failure)
	{
		poll_fixture(); $mutation_server->failure=$failure;
		poll_failure(function() use($db) { phpbb_cast_poll_vote($db,100,1); },'Poll_storage_failed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_voters')===0 && (int)posting_value('SELECT SUM(vote_result) FROM fixture_vote_results')===0,'Failed SQL does not consume an uncounted vote');
	}
	foreach(array('DELETE FROM fixture_vote_results WHERE vote_option_id=1','DELETE FROM fixture_votes','DELETE FROM fixture_topics','UPDATE fixture_topics SET forum_id=4','UPDATE fixture_forums SET forum_status=1','UPDATE fixture_topics SET topic_status=1','UPDATE fixture_votes SET vote_length=1','UPDATE fixture_vote_results SET vote_result=2147483647 WHERE vote_option_id=1') as $change)
	{
		poll_fixture(); $mutation_server->hook=function($sql) use($change)
		{
			if(strpos($sql,'UPDATE fixture_vote_results')===0) { $GLOBALS['mutation_server']->pdo->exec($change); }
		};
		poll_failure(function() use($db) { phpbb_cast_poll_vote($db,100,1); },'Poll_storage_failed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_voters')===0,'Zero affected count update compensates this voter');
	}
	// Execute the actual controller handoffs, including their transport catches.
	poll_fixture();
	$mutation_server->hook=function($sql)
	{
		if(strpos($sql,'UPDATE fixture_vote_results')===0) { $GLOBALS['mutation_server']->pdo->exec('DELETE FROM fixture_vote_results WHERE vote_option_id=1'); $GLOBALS['mutation_server']->failure='DELETE FROM fixture_voters'; }
	};
	poll_failure(function() use($db) { phpbb_cast_poll_vote($db,100,1); },'Poll_storage_failed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_voters')===1,'Failed compensation reports an error rather than a false successful vote');
	$ajax=file_get_contents($forum_root.'ajax.php'); $posting=file_get_contents($forum_root.'posting.php');
	$start=strpos($ajax,"\t// Get topic_id",strpos($ajax,'// Voting/Viewing of polls'));
	$end=strpos($ajax,"\t// Display vote information",$start); mutation_check($start!==false && $end>$start,'Locate actual AJAX poll branch');
	$ajax_branch=substr($ajax,$start,$end-$start);
	$start=strpos($posting,"\t\trequire_once(",strpos($posting,"else if ( \$mode == 'vote' )"));
	$end=strpos($posting,"\t\t\$template->assign_vars",$start); mutation_check($start!==false && $end>$start,'Locate actual standard vote handoff');
	$post_branch=substr($posting,$start,$end-$start);
	poll_fixture(); $db=new PollReadDatabase(); $mode='vote_poll'; $HTTP_POST_VARS=array('t'=>100,'vote_option_id'=>1); $HTTP_GET_VARS=array();
	eval($ajax_branch); mutation_check(!$can_vote,'AJAX switches from ballot to results after vote');
	$_POST=array('vote_id'=>2); eval($post_branch); mutation_check($message==='Already_voted','Standard endpoint sees the AJAX vote');
	poll_fixture(); $db=new PollReadDatabase(); $topic_id=100; $_POST=array('vote_id'=>2); eval($post_branch); mutation_check($message==='Vote_cast','Standard endpoint stores its vote');
	$mode='vote_poll'; eval($ajax_branch); mutation_check((int)posting_value('SELECT SUM(vote_result) FROM fixture_vote_results')===1,'AJAX cannot repeat the standard vote');
	$mutation_server->pdo->exec('UPDATE fixture_forums SET forum_status=1');
	$response=null; try { eval($ajax_branch); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
	mutation_check($response['result']===AJAX_ERROR && $response['error_msg']==='Forum_locked','AJAX lock denial retains XML error transport');
	$mode='view_ballot'; eval($ajax_branch); mutation_check($mode==='view_poll' && !$can_vote,'Locked forum may show results but not a ballot');
	$viewtopic_source=file_get_contents($forum_root.'viewtopic.php');
	$start=strpos($viewtopic_source,"if ( !empty(\$forum_topic_data['topic_vote']) )"); $end=strpos($viewtopic_source,'init_display_post_attachments(',$start);
	mutation_check($start!==false && $end>$start,'Locate actual topic poll renderer');
	$topic_renderer=substr($viewtopic_source,$start,$end-$start);
	$portal_source=file_get_contents($forum_root.'portal.php');
	$start=strpos($portal_source,'if (count($readable_poll_forums))'); $end=strpos($portal_source,'End - vgan',$start);
	mutation_check($start!==false && $end>$start,'Locate actual portal poll renderer');
	$portal_renderer=substr($portal_source,$start,$end-$start);
	foreach(array('Total_votes','View_ballot','View_results','Submit_vote','No_poll') as $key) { $lang[$key]=$key; }
	foreach(array('topic','portal') as $surface)
	{
		foreach(array('open','same_guest','other_guest','locked_forum','denied_vote') as $case)
		{
			poll_fixture(); $db=new PollReadDatabase(); $template=new PollTemplateFixture();
			$userdata['session_logged_in']=false; $userdata['user_id']=ANONYMOUS; $userdata['session_id']='fixture'; $user_ip=encode_ip('2001:db8::1');
			$mutation_server->pdo->exec('UPDATE fixture_topics SET topic_vote=1');
			$mutation_server->pdo->exec('UPDATE fixture_forums SET auth_view=0,auth_read=0,auth_vote=0');
			if($case==='same_guest') { $mutation_server->pdo->exec("INSERT INTO fixture_voters VALUES (1,-1,'".encode_ip('2001:db8::1')."')"); }
			if($case==='other_guest') { $mutation_server->pdo->exec("INSERT INTO fixture_voters VALUES (1,-1,'".encode_ip('2001:db8::2')."')"); }
			if($case==='locked_forum') { $mutation_server->pdo->exec('UPDATE fixture_forums SET forum_status=1'); }
			if($case==='denied_vote') { $mutation_server->pdo->exec('UPDATE fixture_forums SET auth_vote=1'); }
			$topic_id=100; $forum_topic_data=array('topic_vote'=>1); $readable_poll_forums=array(3);
			$orig_word=array(); $replacement_word=array(); $images=array('voting_graphic'=>array('fixture.gif'));
			$board_config['vote_graphic_length']=100; $length=65; $post_days=0; $post_order='asc'; $_GET=array(); $_POST=array();
			eval($surface==='topic'?$topic_renderer:$portal_renderer);
			$ballot=in_array($case,array('open','other_guest'),true);
			mutation_check($template->files['pollbox']===($surface==='topic'?'viewtopic':'portal').'_poll_'.($ballot?'ballot':'result').'.tpl','Actual '.$surface.' renderer follows current permission and guest identity: '.$case);
			if($ballot) { mutation_check(strpos($template->vars['S_HIDDEN_FIELDS'],'name="sid"')!==false,'Ballot keeps the session token'); }
		}
	}
	$portal_template=file_get_contents($forum_root.'templates/fisubsilversh/portal_poll_ballot.tpl');
	mutation_check(strpos($portal_template,'switch_user_logged_in')===false && strpos($portal_template,'{L_SUBMIT_VOTE}')!==false,'An explicitly permitted guest receives the portal submit button');
	echo "Poll voting identity, current permissions, shared lock, compensation and ballot rendering checks passed.\n";
}
finally
{
	if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	restore_error_handler();
}
