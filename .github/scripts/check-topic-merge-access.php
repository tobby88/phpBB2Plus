<?php
require __DIR__ . '/check-topic-move-storage.php';
require $forum_root . 'includes/functions_topic_merge.php';
$lang['Not_Authorised']='Not_Authorised';
class MergeReadDatabase extends PollReadDatabase
{
	var $queries=array(); var $before_query=null;
	function sql_query($sql)
	{
		$this->queries[]=$sql;
		if ($this->before_query) { call_user_func($this->before_query,$sql); }
		return parent::sql_query($sql);
	}
}
function merge_access_fixture()
{
	global $mutation_server;
	topic_move_fixture(); $p=$mutation_server->pdo;
	$p->exec('ALTER TABLE fixture_users ADD user_level INTEGER DEFAULT 0');
	$p->exec('ALTER TABLE fixture_users ADD user_active INTEGER DEFAULT 1');
	return new MergeReadDatabase();
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	$read=merge_access_fixture();
	mutation_check(phpbb_merge_forum_allowed($read,3) && !phpbb_merge_forum_allowed($read,4),'Forum moderator can select only their currently moderated forum');
	mutation_check(phpbb_merge_topic_preview($read,100)['topic_title']===posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=100'),'Authorized preview returns current title');
	foreach(array('100','000100','t=100','viewtopic.php?mode=read&t=100#10','https://fixture.invalid/viewtopic.php?sid=x&t=100','p=10','viewtopic.php?t=100&p=10#10') as $value)
	{
		mutation_check(phpbb_merge_topic_id($read,$value)===100,'Plain topic/post URL resolves: '.$value);
	}
	foreach(array('',0,-1,true,array(100),'100x','16777216','999999999999999999999999999','t[]=100','p[]=10','t=100x','p=10x','t=200&p=10','p=999','t=100&p=999',"t=10\n0",'t=0',str_repeat('a',2049)) as $value)
	{
		mutation_check(phpbb_merge_topic_id($read,$value)===0,'Invalid/ambiguous identifier must not select a different topic');
	}
	foreach(array(
		'DELETE FROM fixture_groups', 'UPDATE fixture_groups SET user_pending=1',
		'UPDATE fixture_auth SET auth_mod=0', 'UPDATE fixture_forums SET auth_read=5',
		'UPDATE fixture_forums SET auth_view=5', 'UPDATE fixture_users SET user_active=0',
		'DELETE FROM fixture_users WHERE user_id=8', 'DELETE FROM fixture_forums',
		"UPDATE fixture_forums SET forum_link='https://fixture.invalid/'", 'DELETE FROM fixture_topics',
		'UPDATE fixture_topics SET forum_id=4', 'UPDATE fixture_topics SET topic_moved_id=999'
	) as $change)
	{
		$read=merge_access_fixture(); $mutation_server->pdo->exec($change);
		mutation_check(phpbb_merge_topic_preview($read,100)===false,'Denied preview returns no title');
		mutation_check(strpos(implode("\n",$read->queries),'SELECT topic_title')===false,'Denied request does not fetch title before authorization');
	}
	$read=merge_access_fixture(); $userdata['session_logged_in']=false;
	mutation_check(!phpbb_merge_forum_allowed($read,3),'Guest cannot use moderator picker');
	$read=merge_access_fixture(); $userdata['user_level']=ADMIN;
	mutation_check(!phpbb_merge_forum_allowed($read,4),'Stale session admin flag cannot override current account/group rights');
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8');
	mutation_check(phpbb_merge_forum_allowed($read,4),'Current active administrator retains access');
	$read=merge_access_fixture();
	$read->before_query=function($sql) { if(strpos($sql,'SELECT topic_title')===0) { $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_topics SET forum_id=4'); } };
	mutation_check(phpbb_merge_topic_preview($read,100)===false,'Topic moved between ACL and title lookup is not disclosed');
	mutation_check(phpbb_merge_html('" onfocus="alert(1) & x')==='&quot; onfocus=&quot;alert(1) &amp; x','Plain fields are attribute-safe');
	mutation_check(phpbb_merge_html('Grüße &amp; &lt;b&gt; "',true)==='Grüße &amp; &lt;b&gt; &quot;','Stored titles remain escaped without entity accumulation');

	// Execute actual controller guards: neither refresh nor picker needs to
	// enter the confirmed mutation branch to protect confidential topic titles.
	$source=file_get_contents($forum_root.'merge.php');
	$start=strpos($source,'// Authorize each requested topic'); $end=strpos($source,'// forum_id',$start);
	mutation_check($start!==false && $end>$start,'Locate actual preview controller'); $preview_branch=substr($source,$start,$end-$start);
	$db=merge_access_fixture(); $from_topic_id=100; $to_topic_id=0; eval($preview_branch);
	mutation_check($from_title===phpbb_merge_html(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=100'),true),'Actual refresh renders authorized escaped title');
	$mutation_server->pdo->exec('UPDATE fixture_auth SET auth_mod=0');
	mutation_expect_failure(function() use($preview_branch,$db,$from_topic_id,$to_topic_id,$lang) { eval($preview_branch); },'Not_Authorised');
	$start=strpos($source,'if (($select_from || $select_to) && (!$cancel))'); $end=strpos($source,'// get the list of forums',$start);
	mutation_check($start!==false && $end>$start,'Locate actual picker guard'); $picker=substr($source,$start,$end-$start).'}';
	$db=merge_access_fixture(); $forum_id=4; $select_from=true; $select_to=false; $cancel=false;
	mutation_expect_failure(function() use($picker,$db,$forum_id,$select_from,$select_to,$cancel,$lang) { eval($picker); },'Not_Authorised');
	$forum_id=3; eval($picker);
	mutation_check(strpos($source,'$sid = phpbb_request_scalar($_POST, \'sid\');')!==false,'Mutation SID comes from POST, not URL fallback');
	mutation_check(strpos($source,'addslashes($topic_title)')===false && strpos($source,'phpbb_merge_topics($db,')!==false,'Hidden fields avoid slash accumulation and mutation uses tested storage worker');
	echo "Merge picker/preview current ACL, strict URL identifiers and output boundaries passed.\n";
}
finally { restore_error_handler(); }
