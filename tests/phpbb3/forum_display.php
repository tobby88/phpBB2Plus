<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	require_once __DIR__ . '/fixture_database.php';
	$source = plus_fixture_database($argv[1]);
	$source->query("UPDATE phpbb_users SET user_last_topic_title=0,user_sub_level_links=1,user_split_sticky=0 WHERE username='HistoricMember'");
	$source->query("UPDATE phpbb_config SET config_value='13' WHERE config_name='last_topic_title_length'");
	$source->query("UPDATE phpbb_config SET config_value='1' WHERE config_name='split_sticky_over'");
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$one = static function ($sql) use ($db) { $rs=$db->sql_query_limit($sql,1); $r=$db->sql_fetchrow($rs); $db->sql_freeresult($rs); return $r; };
if ($phase === 'older' || $phase === 'upgraded')
{
	$columns = $phpbb_container->get('dbal.tools')->sql_column_exists(USERS_TABLE, 'plus_last_topic_title');
	if ($phase === 'older')
	{
		if ($columns) { throw new RuntimeException('Expected the previous layout schema'); }
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_legacy_id=29900 WHERE user_id=2');
	}
	else
	{
		$row = $one('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=2');
		if (!$columns || (int) $row['plus_last_topic_title'] !== -1 || (int) $config['plus_last_topic_title_length'] !== 30 ||
			(int) $config['plus_split_sticky'] !== 0 || (int) $config['plus_split_topic_split'] !== 1)
		{ throw new RuntimeException('An earlier conversion acquired an invented personal choice or lost its native layout'); }
	}
	return;
}
$db->sql_transaction('begin');
if ($phase === 'setup')
{
	$member = $one('SELECT * FROM ' . USERS_TABLE . " WHERE username='HistoricMember'");
	if ((int) $config['plus_last_topic_title_length'] !== 13 || (int) $config['plus_split_sticky_over'] !== 1 ||
		(int) $member['plus_split_sticky'] !== 0 || (int) $member['plus_sub_level_links'] !== 1 || (int) $member['plus_last_topic_title'] !== 0)
	{ throw new RuntimeException('Source presentation choices or board overrides were not converted'); }
	require_once './includes/functions_admin.php';
	$forum = $one('SELECT * FROM ' . FORUMS_TABLE . ' WHERE forum_id=1');
	$right = (int) $one('SELECT MAX(right_id) AS r FROM ' . FORUMS_TABLE)['r'];
	// Isolated permissions and nested sets; no existing category is renumbered.
	foreach (array(29850=>array(0,1,12,FORUM_CAT,'Layout index'),29851=>array(29850,2,7,FORUM_POST,'Layout room'),
		29852=>array(29851,3,6,FORUM_POST,'Layout subforum'),29853=>array(29852,4,5,FORUM_POST,'Layout third level'),
		29854=>array(29850,8,11,FORUM_CAT,'Layout empty category'),29855=>array(29854,9,10,FORUM_POST,'Layout private leaf')) as $id=>$details)
	{
		list($parent,$left,$end,$type,$name) = $details;
		$data=array_replace($forum,array('forum_id'=>$id,'parent_id'=>$parent,'left_id'=>$right+$left,'right_id'=>$right+$end,
			'forum_name'=>$name,'forum_desc'=>'','forum_parents'=>'','forum_type'=>$type,'display_on_index'=>1,
			'display_subforum_list'=>1,'forum_password'=>'','forum_last_post_id'=>0,'forum_last_post_time'=>0,
			'forum_posts_approved'=>0,'forum_topics_approved'=>0,'forum_last_post_subject'=>''));
		$db->sql_query('INSERT INTO '.FORUMS_TABLE.' '.$db->sql_build_array('INSERT',$data));
	}
	copy_forum_permissions(1,array(29850,29851,29852,29853,29854),true,false);
	$topic=$one('SELECT * FROM '.TOPICS_TABLE.' WHERE topic_id=1');
	$post=$one('SELECT * FROM '.POSTS_TABLE.' WHERE post_id='.$topic['topic_first_post_id']);
	$time=1667770000;
	$title='Käse &amp; Grüße im Forum';
	$id=985720;
	$t=array_replace($topic,array('forum_id'=>29852,'topic_id'=>$id,'topic_type'=>POST_NORMAL,'topic_first_post_id'=>$id,
		'topic_last_post_id'=>$id,'topic_last_post_time'=>$time,'topic_time'=>$time,'topic_title'=>$title,'topic_visibility'=>ITEM_APPROVED,
		'plus_news_cat'=>0,'topic_moved_id'=>0,'topic_status'=>ITEM_UNLOCKED,'topic_posts_approved'=>1,'topic_attachment'=>0));
	$p=array_replace($post,array('forum_id'=>29852,'topic_id'=>$id,'post_id'=>$id,'post_subject'=>'Een andere antwoordtitel','post_time'=>$time,
		'post_text'=>'Display source board topic','post_visibility'=>ITEM_APPROVED,'post_attachment'=>0));
	$db->sql_query('INSERT INTO '.TOPICS_TABLE.' '.$db->sql_build_array('INSERT',$t));
	$db->sql_query('INSERT INTO '.POSTS_TABLE.' '.$db->sql_build_array('INSERT',$p));
	$db->sql_query('UPDATE '.FORUMS_TABLE." SET forum_last_post_subject='Een andere antwoordtitel',forum_last_post_id=$id,
		forum_last_post_time=$time,forum_topics_approved=1,forum_posts_approved=1 WHERE forum_id=29852");
}
elseif ($phase === 'locked' || $phase === 'unlocked')
{
	$db->sql_query('UPDATE '.FORUMS_TABLE.' SET forum_password=' . ($phase === 'locked' ? "'layout-fixture-private'" : "''") . ' WHERE forum_id=29852');
}
elseif ($phase === 'clean')
{
	$db->sql_query('DELETE FROM '.POSTS_TABLE.' WHERE forum_id=29852 AND post_id=985720');
	$db->sql_query('DELETE FROM '.TOPICS_TABLE.' WHERE forum_id=29852 AND topic_id=985720');
	foreach (array(ACL_USERS_TABLE,ACL_GROUPS_TABLE,FORUMS_TABLE,FORUMS_TRACK_TABLE) as $table)
	{ $db->sql_query('DELETE FROM '.$table.' WHERE forum_id BETWEEN 29850 AND 29855'); }
	$db->sql_query('UPDATE '.USERS_TABLE." SET plus_last_topic_title=0,plus_sub_level_links=1,plus_sub_forum=1,plus_split_cat=1 WHERE username='HistoricMember'");
	$config->set('plus_last_topic_title',1); $config->set('plus_last_topic_title_over',0);
	$config->set('plus_sub_level_links',2); $config->set('plus_sub_level_links_over',0);
	$auth->acl_clear_prefetch();
}
else { throw new RuntimeException('Unknown fixture operation'); }
$db->sql_transaction('commit');
