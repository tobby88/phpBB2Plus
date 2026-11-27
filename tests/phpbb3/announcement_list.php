<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	$db = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$db->query("UPDATE phpbb_config SET config_value='1' WHERE config_name IN ('announcement_display','announcement_display_forum',
		'announcement_date_display','announcement_date_display_over','announcement_split','announcement_forum')");
	$db->query("UPDATE phpbb_users SET user_announcement_display=0,user_announcement_display_forum=1,user_announcement_split=0,
		user_announcement_forum=0,user_announcement_date_display=0 WHERE username='HistoricMember'");
	return;
}
define('IN_PHPBB',true); $phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows=static function($sql) use ($db) { $r=$db->sql_query($sql); $rows=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows; };
$store=$phpbb_container->get('config_text'); $key='test_plus_announcement_list';
$bylines=$table_prefix . 'plus_bylines';
$names=array('announce_index','announce_forum','announce_dates','announce_groups','announce_path');
$member="username_clean='historicmember'";
if ($phase==='older' || $phase==='upgraded')
{
	$tools=$phpbb_container->get('dbal.tools');
	$columns=$tools->sql_column_exists(USERS_TABLE,'plus_announce_index');
	if ($phase==='older' ? $columns : !$columns) { throw new RuntimeException('Incorrect announcement preference table version'); }
	if ($phase==='upgraded' && ((int) $config['plus_announce_index']!==0 || (int) $config['plus_announce_forum']!==0 ||
		(int) $rows('SELECT plus_announce_path FROM '.USERS_TABLE." WHERE $member")[0]['plus_announce_path']!==-1))
	{ throw new RuntimeException('The upgrade guessed personal or board announcement settings'); }
	return;
}
$db->sql_transaction('begin');
if ($phase==='seed')
{
	require_once './includes/functions_admin.php'; require_once './includes/acp/auth.php';
	if ($store->get($key)!==null || $rows('SELECT forum_id FROM '.FORUMS_TABLE.' WHERE forum_id BETWEEN 39020 AND 39026') ||
		$rows('SELECT topic_id FROM '.TOPICS_TABLE.' WHERE topic_id BETWEEN 926110 AND 926122') ||
		$rows('SELECT post_id FROM '.POSTS_TABLE.' WHERE post_id BETWEEN 926110 AND 926133') ||
		$rows('SELECT attach_id FROM '.ATTACHMENTS_TABLE.' WHERE attach_id BETWEEN 926140 AND 926143'))
	{ throw new RuntimeException('Announcement fixture identities are already occupied'); }
	$settings=array('board_timezone','topics_per_page','posts_per_page','plus_announce_default','plus_announce_sweep','edit_time',
		'plus_inline_edit','plus_inline_edit_over','plus_icon_global_announce','allow_attachments');
	$columns=array(); foreach ($names as $n) { $columns[]=$settings[]='plus_'.$n; $settings[]='plus_'.$n.'_over'; }
	$previous=array(); foreach ($settings as $n) { $previous[$n]=$config[$n]; }
	$personal=$rows('SELECT '.implode(',',$columns).' FROM '.USERS_TABLE." WHERE $member")[0];
	if (array_map('intval',array_values($personal))!==array(0,1,0,0,0) || (int) $config['plus_announce_dates_over']!==1)
	{ throw new RuntimeException('Converted announcement settings or their personal override were lost'); }
	$group_choices=$rows('SELECT plus_split_global_announce,plus_split_announce,user_timezone FROM '.USERS_TABLE." WHERE $member")[0];
	$store->set($key,json_encode(array($previous,$personal+$group_choices)));
	$config->set('board_timezone','Europe/Berlin'); $config->set('topics_per_page',50); $config->set('plus_announce_default',0); $config->set('edit_time',0);
	$config->set('plus_inline_edit',1); $config->set('plus_inline_edit_over',1);
	$icons=array_column($rows('SELECT plus_icon_id,icons_id,icons_url FROM '.ICONS_TABLE.' WHERE plus_icon_id IN (3,22)'),null,'plus_icon_id');
	$config->set('plus_icon_global_announce',(int) $icons[22]['icons_id']); $config->set('allow_attachments',1); $config->set('posts_per_page',2);
	$db->sql_query('UPDATE '.USERS_TABLE." SET user_timezone='America/New_York' WHERE $member");
	$base=$rows('SELECT * FROM '.FORUMS_TABLE.' WHERE forum_id=1')[0];
	$right=(int) $rows('SELECT MAX(right_id) AS r FROM '.FORUMS_TABLE)[0]['r'];
	$rooms=array(39020=>array(0,1,12,'Notice category'),39021=>array(39020,2,5,'Notice parent'),
		39022=>array(39021,3,4,'Notice child'),39023=>array(39020,6,7,'Notice sibling'),
		39024=>array(39020,8,9,'Locked notice room'),39025=>array(39020,10,11,'Unlisted notice room'),
		39026=>array(0,13,14,'Remote notice room'));
	foreach ($rooms as $id=>$room)
	{
		list($parent,$lo,$hi,$name)=$room;
		$f=array_replace($base,array('forum_id'=>$id,'parent_id'=>$parent,'left_id'=>$right+$lo,'right_id'=>$right+$hi,
			'forum_type'=>$id===39020 ? FORUM_CAT : FORUM_POST,'forum_name'=>$name,'forum_parents'=>'','forum_desc'=>'','enable_icons'=>(int) ($id!==39023),
			'forum_password'=>$id===39024 ? $phpbb_container->get('passwords.manager')->hash('notice-fixture-local') : '',
			'forum_last_post_id'=>0,'forum_posts_approved'=>0,'forum_topics_approved'=>0,'forum_last_post_subject'=>''));
		$db->sql_query('INSERT INTO '.FORUMS_TABLE.' '.$db->sql_build_array('INSERT',$f));
	}
	copy_forum_permissions(1,array(39020,39021,39022,39023,39024,39026),true,false);
	(new auth_admin())->acl_set('user',39022,2,array('m_'=>ACL_YES,'m_approve'=>ACL_YES,'m_edit'=>ACL_YES));
	$auth->acl_clear_prefetch();
	$t0=$rows('SELECT * FROM '.TOPICS_TABLE.' WHERE topic_id=1')[0];
	$p0=$rows('SELECT * FROM '.POSTS_TABLE.' WHERE post_id=32')[0];
	$actor=(int) $rows('SELECT user_id FROM '.USERS_TABLE." WHERE $member")[0]['user_id'];
	$time=strtotime('2024-03-30T22:30:00Z');
	$types=array(110=>array(39021,POST_ANNOUNCE),111=>array(39022,POST_ANNOUNCE),112=>array(39023,POST_ANNOUNCE),
		113=>array(39026,POST_ANNOUNCE),114=>array(39026,POST_GLOBAL),115=>array(39024,POST_GLOBAL),116=>array(39025,POST_GLOBAL),
		117=>array(39022,POST_ANNOUNCE,ITEM_UNAPPROVED),118=>array(39026,POST_GLOBAL,ITEM_DELETED),119=>array(39022,POST_NORMAL),
		122=>array(39022,POST_ANNOUNCE));
	foreach ($types as $n=>$type)
	{
		$id=926000+$n; $status=$type[2] ?? ITEM_APPROVED; $title='Notice fixture '.$n;
		$t=array_replace($t0,array('topic_id'=>$id,'forum_id'=>$type[0],'topic_type'=>$type[1],'topic_visibility'=>$status,
			'topic_title'=>$title,'topic_first_post_id'=>$id,'topic_last_post_id'=>$id,'topic_time'=>$time,'topic_last_post_time'=>$time,
			'topic_time_limit'=>in_array($n,array(111,122),true) ? 86400 : 0,'plus_announce_days'=>0,'plus_list_news'=>0,
			'plus_legacy_removed'=>0,'topic_status'=>ITEM_UNLOCKED,'topic_moved_id'=>0,'topic_poster'=>$n===112 ? $actor : ANONYMOUS,'poll_start'=>0,
			'plus_description'=>$n===111 ? 'Hinweis & <April>' : '', 'icon_id'=>in_array($n,array(111,112),true) ? $icons[3]['icons_id'] : 0,
			'plus_cal_start'=>$n===111 ? strtotime('2024-03-30T23:30:00Z') : ($n===113 ? strtotime('2024-04-09T22:00:00Z') : 0),
			'plus_cal_end'=>$n===111 ? strtotime('2024-03-31T10:45:00Z') : ($n===113 ? strtotime('2024-04-10T21:59:59Z') : 0),
			'topic_attachment'=>(int) in_array($n,array(110,112,114,122),true),
			'topic_posts_approved'=>(int) ($status===ITEM_APPROVED),'topic_posts_unapproved'=>(int) ($status===ITEM_UNAPPROVED),
			'topic_posts_softdeleted'=>(int) ($status===ITEM_DELETED),'topic_views'=>17));
		$p=array_replace($p0,array('post_id'=>$id,'forum_id'=>$type[0],'topic_id'=>$id,'post_visibility'=>$status,'poster_id'=>$t['topic_poster'],
			'post_username'=>$n===112 ? '' : 'Notice guest','post_subject'=>$title,'post_time'=>$time,'post_edit_locked'=>0,
			'post_text'=>'<t>Notice fixture</t>','plus_legacy_erased'=>0));
		$db->sql_query('INSERT INTO '.TOPICS_TABLE.' '.$db->sql_build_array('INSERT',$t));
		$db->sql_query('INSERT INTO '.POSTS_TABLE.' '.$db->sql_build_array('INSERT',$p));
	}
	for ($i=0;$i<4;++$i)
	{
		$p=array_replace($p0,array('post_id'=>926130+$i,'topic_id'=>926122,'forum_id'=>39022,'poster_id'=>ANONYMOUS,'post_username'=>'Notice guest',
			'post_subject'=>'Later notice reply','post_text'=>'<t>Notice reply '.($i+1).'</t>','post_time'=>$time+$i+1,
			'post_visibility'=>$i<2 ? ITEM_APPROVED : ITEM_UNAPPROVED,'plus_legacy_erased'=>0,'post_attachment'=>(int) ($i===2)));
		$db->sql_query('INSERT INTO '.POSTS_TABLE.' '.$db->sql_build_array('INSERT',$p));
	}
	$db->sql_query('UPDATE '.TOPICS_TABLE.' SET topic_posts_approved=3,topic_posts_unapproved=2,topic_last_post_id=926131,topic_last_post_time='.($time+2).' WHERE topic_id=926122');
	$gift=$rows('SELECT * FROM '.ATTACHMENTS_TABLE.' WHERE attach_id=50')[0];
	foreach (array(array(926114,926114,'gif',0),array(926122,926132,'gif',0),array(926112,926112,'gif',1),array(926110,926110,'noticeinvalid',0)) as $i=>$record)
	{
		list($tid,$pid,$extension,$orphan)=$record;
		$f=array_replace($gift,array('attach_id'=>926140+$i,'topic_id'=>$tid,'post_msg_id'=>$pid,'in_message'=>0,'is_orphan'=>$orphan,
			'extension'=>$extension,'real_filename'=>'Notice attachment.'.$extension));
		$db->sql_query('INSERT INTO '.ATTACHMENTS_TABLE.' '.$db->sql_build_array('INSERT',$f));
	}
	$former=$rows("SELECT * FROM $bylines WHERE post_id=32")[0]; $former['post_id']=926111;
	$db->sql_query("INSERT INTO $bylines ".$db->sql_build_array('INSERT',$former));
	$db->sql_query('INSERT INTO '.TOPICS_TRACK_TABLE." (user_id,topic_id,forum_id,mark_time) VALUES ($actor,926114,39026,".($time-60).')');
	sync('forum','forum_id',array_keys($rooms),false,true);
	echo json_encode(array('former'=>$former['author_name'],'explicit'=>$icons[3]['icons_url'],'default'=>$icons[22]['icons_url']));
}
elseif ($phase==='short') { $config->set('topics_per_page',1); }
elseif ($phase==='deny-download')
{
	require_once './includes/acp/auth.php';
	$actor=(int) $rows('SELECT user_id FROM '.USERS_TABLE." WHERE $member")[0]['user_id'];
	(new auth_admin())->acl_set('user',39026,$actor,array('f_download'=>ACL_NEVER));
	$auth->acl_clear_prefetch();
}
elseif ($phase==='edited')
{
	$topic=$rows('SELECT topic_title,topic_type,plus_description FROM '.TOPICS_TABLE.' WHERE topic_id=926111')[0];
	$post=$rows('SELECT post_text FROM '.POSTS_TABLE.' WHERE post_id=926111')[0];
	if ($topic['topic_title']!=='Notice fixture 111 – geändert' || (int)$topic['topic_type']!==POST_ANNOUNCE ||
		$topic['plus_description']!=='Hinweis & <April>' || html_entity_decode(strip_tags($post['post_text']), ENT_QUOTES, 'UTF-8')!=='Notice fixture')
	{ throw new RuntimeException('The authorized announcement title edit changed the message, description or topic type'); }
}
elseif ($phase==='expire')
{
	$config->set('topics_per_page',50);
	// Exercise the selected topic's normal expiry without sweeping other tests' schedules.
	$config->set('plus_announce_sweep',$phpbb_container->get('phpbb2plus.community.announcements')->day_end());
	$config->set('plus_announce_default',7);
}
elseif ($phase==='clean')
{
	list($settings,$personal)=json_decode($store->get($key),true);
	foreach ($settings as $k=>$v) { $config->set($k,$v); }
	$db->sql_query('UPDATE '.USERS_TABLE.' SET '.$db->sql_build_array('UPDATE',$personal)." WHERE $member");
	$db->sql_query("DELETE FROM $bylines WHERE post_id=926111");
	if ($rows('SELECT post_id FROM '.SEARCH_WORDMATCH_TABLE.' WHERE post_id=926111 LIMIT 1'))
	{
		$error=false;
		$index=new \phpbb\search\fulltext_native($error,$phpbb_root_path,$phpEx,$auth,$config,$db,$user,$phpbb_dispatcher);
		$index->index_remove(array(926111),array(ANONYMOUS),array(39022));
	}
	$db->sql_query('DELETE FROM '.LOG_TABLE.' WHERE forum_id=39022 AND topic_id=926111');
	$db->sql_query('DELETE FROM '.ATTACHMENTS_TABLE.' WHERE attach_id BETWEEN 926140 AND 926143 AND topic_id BETWEEN 926110 AND 926122');
	$db->sql_query('DELETE FROM '.POSTS_TABLE.' WHERE post_id BETWEEN 926130 AND 926133 AND topic_id=926122');
	$db->sql_query('DELETE FROM '.POSTS_TABLE.' WHERE topic_id BETWEEN 926110 AND 926122 AND post_id=topic_id');
	$db->sql_query('DELETE FROM '.TOPICS_TABLE." WHERE topic_id BETWEEN 926110 AND 926122 AND topic_title LIKE 'Notice fixture %'");
	foreach (array(ACL_USERS_TABLE,ACL_GROUPS_TABLE,FORUMS_TRACK_TABLE,FORUMS_ACCESS_TABLE,FORUMS_TABLE) as $table)
	{ $db->sql_query('DELETE FROM '.$table.' WHERE forum_id BETWEEN 39020 AND 39026'); }
	foreach (array(TOPICS_TRACK_TABLE,TOPICS_POSTED_TABLE,TOPICS_WATCH_TABLE) as $table)
	{ $db->sql_query('DELETE FROM '.$table.' WHERE topic_id BETWEEN 926110 AND 926122'); }
	$auth->acl_clear_prefetch(); $store->delete($key);
}
else { throw new RuntimeException('Unexpected announcement fixture action'); }
$db->sql_transaction('commit');
