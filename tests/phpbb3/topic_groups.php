<?php
// Use the temporary public forum created by the forum_display fixture.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true); $phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$phase=$argv[2];
$one=static function ($sql) use ($db) { $result=$db->sql_query_limit($sql,1); $row=$db->sql_fetchrow($result); $db->sql_freeresult($result); return $row; };
if ($phase==='older' || $phase==='upgraded')
{
	$state=$phpbb_container->get('config_text');
	$key='plus_test_upgraded_topic_group';
	if ($phase==='older')
	{
		$row=$one('SELECT topic_id FROM '.TOPICS_TABLE.' WHERE topic_type='.POST_NORMAL.' AND topic_visibility='.ITEM_APPROVED.' AND topic_status<>'.ITEM_MOVED.' ORDER BY topic_id');
		if (!$row) { throw new RuntimeException('Missing native topic before upgrading news roles'); }
		$state->set($key, (string) $row['topic_id']);
		return;
	}
	$row=$one('SELECT topic_type,plus_list_news FROM '.TOPICS_TABLE.' WHERE topic_id='.(int) $state->get($key));
	if (!$row || (int) $row['plus_list_news'] !== -1 || (int) $row['topic_type'] !== POST_NORMAL)
	{ throw new RuntimeException('Upgrading guessed that an earlier native topic had or lacked a historical news role'); }
	$state->delete($key);
	return;
}
$db->sql_transaction('begin');
if ($phase==='setup')
{
	$topic=$one('SELECT * FROM '.TOPICS_TABLE.' WHERE topic_id=985720'); $post=$one('SELECT * FROM '.POSTS_TABLE.' WHERE post_id=985720');
	$size=(int) $config['topics_per_page'];
	if (!$topic || $size < 1 || $size > 100) { throw new RuntimeException('Unexpected fixture or forum pagination'); }
	$formats=array('Global'=>array(POST_GLOBAL,0,ITEM_APPROVED),'Announcement'=>array(POST_ANNOUNCE,0,ITEM_APPROVED),
		'News'=>array(POST_NORMAL,1,ITEM_APPROVED),'Sticky'=>array(POST_STICKY,0,ITEM_APPROVED),
		'Pending'=>array(POST_NORMAL,1,ITEM_UNAPPROVED),'Withdrawn'=>array(POST_NORMAL,1,ITEM_DELETED));
	foreach (range(0,$size+1) as $n) { $formats['Normal '.$n]=array(POST_NORMAL,0,ITEM_APPROVED); }
	$id=985730;
	foreach ($formats as $name=>$data)
	{
		list($type,$news,$status)=$data;
		$title='List fixture '.$name;
		$time=$news ? 1550000000 : time()-60;
		$t=array_replace($topic,array('topic_id'=>$id,'topic_time'=>$time,'topic_title'=>$title,'topic_type'=>$type,
			'topic_first_post_id'=>$id,'topic_last_post_id'=>$id,'topic_last_post_time'=>$time,'topic_visibility'=>$status,
			'plus_news_cat'=>0,'plus_list_news'=>$news,'topic_posts_approved'=>(int) ($status===ITEM_APPROVED),
			'topic_posts_unapproved'=>(int) ($status===ITEM_UNAPPROVED),'topic_posts_softdeleted'=>(int) ($status===ITEM_DELETED)));
		$p=array_replace($post,array('post_id'=>$id,'topic_id'=>$id,'post_time'=>$time,'post_visibility'=>$status,'post_edit_time'=>0,
			'post_subject'=>$title,'post_text'=>$title));
		$db->sql_query('INSERT INTO '.TOPICS_TABLE.' '.$db->sql_build_array('INSERT',$t));
		$db->sql_query('INSERT INTO '.POSTS_TABLE.' '.$db->sql_build_array('INSERT',$p));
		++$id;
	}
	$db->sql_query('UPDATE '.FORUMS_TABLE.' SET forum_topics_approved='.($size+7).',forum_topics_unapproved=1,forum_topics_softdeleted=1 WHERE forum_id=29852');
	echo (string) $size;
}
elseif ($phase==='snapshot')
{
	// A reachable same-server phpBB2 snapshot. The three conflicts are a native sticky, a changed title and a later moderator decision.
	$db->sql_query('CREATE TABLE layout_src_topics (topic_id INT PRIMARY KEY,topic_type INT,topic_first_post_id INT,forum_id INT,
		topic_status INT,topic_time INT,topic_title VARCHAR(255)) ENGINE=InnoDB');
	$db->sql_query('CREATE TABLE layout_src_posts (post_id INT PRIMARY KEY,topic_id INT,post_edit_time INT) ENGINE=InnoDB');
	$db->sql_query('INSERT INTO layout_src_topics SELECT topic_id,4,topic_first_post_id,forum_id,topic_status,topic_time,topic_title
		FROM '.TOPICS_TABLE.' WHERE topic_id IN (985732,985733,985736,985737)');
	$db->sql_query('INSERT INTO layout_src_posts SELECT post_id,topic_id,post_edit_time FROM '.POSTS_TABLE.' WHERE topic_id IN (985732,985733,985736,985737)');
	$db->sql_query("UPDATE layout_src_topics SET topic_title='An older source title' WHERE topic_id=985736");
	$db->sql_query('UPDATE '.TOPICS_TABLE.' SET plus_list_news=-1 WHERE topic_id IN (985732,985736,985737)');
	$db->sql_query('INSERT INTO '.LOG_TABLE.' '.$db->sql_build_array('INSERT',array('log_type'=>LOG_MOD,'user_id'=>2,'forum_id'=>29852,
		'topic_id'=>985737,'log_operation'=>'LOG_TOPIC_TYPE_CHANGED','log_time'=>time(),'log_data'=>'')));
}
elseif ($phase==='published' || $phase==='unpublished' || $phase==='unmarked')
{
	$id=$phase==='unmarked' ? 985732 : 985738;
	$row=$one('SELECT plus_list_news,plus_news_cat,topic_type FROM '.TOPICS_TABLE.' WHERE topic_id='.$id);
	if (!$row || (int) $row['topic_type']!==POST_NORMAL || (int) $row['plus_list_news']!==(int) ($phase==='published') ||
		(int) $row['plus_news_cat']!==($phase==='published' ? 2 : 0))
	{ throw new RuntimeException('A native editor or moderation form left stale news presentation: '.$phase.' '.json_encode($row)); }
}
elseif ($phase==='clean')
{
	$db->sql_query('DROP TABLE IF EXISTS layout_src_topics, layout_src_posts');
	$db->sql_query('DELETE FROM '.LOG_TABLE.' WHERE forum_id=29852 AND topic_id BETWEEN 985730 AND 985840');
	foreach (array(TOPICS_TRACK_TABLE, TOPICS_POSTED_TABLE) as $table)
	{ $db->sql_query('DELETE FROM '.$table.' WHERE topic_id BETWEEN 985730 AND 985840'); }
	$db->sql_query('DELETE FROM '.SEARCH_WORDMATCH_TABLE.' WHERE post_id BETWEEN 985730 AND 985840');
	$db->sql_query('DELETE FROM '.POSTS_TABLE.' WHERE forum_id=29852 AND post_id BETWEEN 985730 AND 985840 AND topic_id=post_id');
	$db->sql_query('DELETE FROM '.TOPICS_TABLE." WHERE forum_id=29852 AND topic_id BETWEEN 985730 AND 985840 AND topic_title LIKE 'List fixture %'");
	$config->set('plus_split_sticky_over',1);
	$db->sql_query('UPDATE '.USERS_TABLE." SET plus_split_global_announce=1,plus_split_announce=1,plus_split_news=1,plus_split_sticky=0,plus_split_topic_split=0 WHERE username='HistoricMember'");
}
else { throw new RuntimeException('Unknown topic group fixture phase'); }
$db->sql_transaction('commit');
