<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
$phase=$argv[2];
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$db=new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$db->query("UPDATE phpbb_plus SET config_value='0' WHERE config_name='default_avatar'");
	return;
}
define('IN_PHPBB',true); $phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows=static function ($sql) use ($db) { $r=$db->sql_query($sql); $v=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $v; };
$expect=static function ($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$backup='plus_test_avatar_default';
$text=$phpbb_container->get('config_text');
if ($phase==='fresh')
{
	$expect((int)$config['plus_topic_default_avatar']===1 && $config['plus_topic_avatar_image']==='stock.gif',
		'A fresh Plus board did not retain the stock default-avatar choice');
}
elseif ($phase==='older' || $phase==='upgraded')
{
	$previous=28693;
	if ($phase==='older')
	{
		$expect(!$phpbb_container->get('dbal.tools')->sql_column_exists(USERS_TABLE,'plus_no_default_avatar'),
			'Upgrade fixture requires the former schema');
		$db->sql_query('UPDATE '.USERS_TABLE." SET plus_legacy_id=$previous WHERE user_id=2 AND plus_legacy_id=0 AND user_avatar=''");
		$db->sql_query('INSERT INTO '.$table_prefix.'plus_media_issues '.$db->sql_build_array('INSERT',array(
			'asset_kind'=>'avatar','source_id'=>$previous,'reference_json'=>'"old.gif"','reason'=>'unavailable')));
	}
	else
	{
		$user=$rows('SELECT user_avatar,plus_no_default_avatar FROM '.USERS_TABLE." WHERE plus_legacy_id=$previous")[0];
		$expect($user['user_avatar']==='' && (int)$user['plus_no_default_avatar']===1 && !(int)$config['plus_topic_default_avatar'] &&
			$config['plus_topic_avatar_image']==='stock.gif','Earlier conversion guessed the switch or lost evidence of an unavailable personal avatar');
	}
}
elseif ($phase === 'prepare')
{
	$expect(!$text->get($backup) && !(int) $config['plus_topic_default_avatar'] && preg_match('/^[a-f0-9]{64}\.gif$/D',$config['plus_topic_avatar_image']),
		'The legacy disabled switch or local custom image was lost');
	// Retry the real converter helper when formerly missing local artwork has been supplied.
	require_once __DIR__ . '/../../phpBB3/install/convertors/plus_profile_media.php';
	$asset=$config['plus_topic_avatar_image'];
	$source_dir=sys_get_temp_dir().'/plus3-avatar-'.bin2hex(random_bytes(8));
	if (!mkdir($source_dir) || !mkdir($source_dir.'/images')) { throw new RuntimeException('Unable to prepare local source images'); }
	$src_db=$db;
	$convert=(object)array('src_table_prefix'=>$table_prefix.'tmp_avatar_', 'options'=>array('forum_path'=>$source_dir));
	$source_table=$convert->src_table_prefix.'plus';
	$db->sql_query("CREATE TEMPORARY TABLE $source_table (config_name VARCHAR(32), config_value VARCHAR(4))");
	$db->sql_query("INSERT INTO $source_table VALUES ('default_avatar','1')");
	$issues='SELECT reason FROM '.$table_prefix."plus_media_issues WHERE asset_kind='topic-avatar' AND source_id=0";
	$expect(!$rows($issues),'A clean converted default image was reported missing');
	try
	{
		plus_import_topic_avatar();
		$expect((int)$config['plus_topic_default_avatar']===1 && $config['plus_topic_avatar_image']==='' && $rows($issues)[0]['reason']==='unavailable',
			'An enabled missing source image was silently replaced');
		if (!copy($phpbb_root_path.'images/plusavatars/'.$asset,$source_dir.'/images/default_avatar.gif')) { throw new RuntimeException('Missing imported GIF'); }
		plus_import_topic_avatar();
		plus_import_topic_avatar();
		$expect($config['plus_topic_avatar_image']===$asset && !$rows($issues),'A repeated import lost the original image or left a stale missing-file report');
	}
	finally
	{
		$config->set('plus_topic_default_avatar',0);
		$config->set('plus_topic_avatar_image',$asset);
		if (is_file($source_dir.'/images/default_avatar.gif')) { unlink($source_dir.'/images/default_avatar.gif'); }
		rmdir($source_dir.'/images'); rmdir($source_dir);
	}
	$u=array_column($rows('SELECT user_id,plus_legacy_id,plus_no_default_avatar FROM '.USERS_TABLE.' WHERE plus_legacy_id IN (1,2,85,86,87)'),'user_id','plus_legacy_id');
	$missing=$rows('SELECT plus_no_default_avatar FROM '.USERS_TABLE.' WHERE plus_legacy_id IN (85,86)');
	$normal=$rows("SELECT user_id FROM ".USERS_TABLE." WHERE user_type=".USER_NORMAL." AND plus_legacy_id>2 AND plus_no_default_avatar=0 AND user_avatar='' ORDER BY user_id LIMIT 1");
	$expect(count($missing)===2 && (int) $missing[0]['plus_no_default_avatar'] && (int) $missing[1]['plus_no_default_avatar'] && $normal,
		'The personal-file and no-personal-avatar cases were merged');
	$expect(!$rows('SELECT topic_id FROM '.TOPICS_TABLE.' WHERE topic_id=28701') &&
		!$rows('SELECT post_id FROM '.POSTS_TABLE.' WHERE post_id BETWEEN 28701 AND 28708'),'Topic avatar fixture identifiers are occupied');
	$topic=$rows('SELECT * FROM '.TOPICS_TABLE.' WHERE topic_id=1')[0];
	$post=$rows('SELECT * FROM '.POSTS_TABLE.' WHERE post_id=1')[0];
	$owners=array(ANONYMOUS,$normal[0]['user_id'],$u[2],$u[85],$u[86],$u[87],ANONYMOUS,$u[1]);
	$topic['topic_id']=28701; $topic['topic_title']='Avatar probe'; $topic['topic_first_post_id']=28701; $topic['topic_last_post_id']=28708;
	$topic['topic_posts_approved']=7; $topic['topic_posts_softdeleted']=1;
	$state=array('allow_avatar'=>$config['allow_avatar'],'allow_avatar_local'=>$config['allow_avatar_local'],
		'plus_topic_default_avatar'=>$config['plus_topic_default_avatar']);
	$bits=$rows('SELECT user_options FROM '.USERS_TABLE.' WHERE user_id='.(int)$u[2])[0]['user_options'];
	$personal=$rows('SELECT user_avatar,user_avatar_type,user_avatar_width,user_avatar_height,plus_no_default_avatar FROM '.USERS_TABLE.
		' WHERE user_id='.(int)$u[85])[0];
	$text->set($backup,json_encode(array('config'=>$state,'viewer'=>(int)$u[2],'options'=>$bits,'withdrawn'=>(int)$u[85],'personal'=>$personal)));
	$db->sql_query('INSERT INTO '.TOPICS_TABLE.' '.$db->sql_build_array('INSERT',$topic));
	foreach ($owners as $i=>$owner)
	{
		$post['post_id']=28701+$i; $post['topic_id']=28701; $post['poster_id']=$owner; $post['post_time']=1200000000+$i;
		$post['post_visibility']=$i===6 ? ITEM_DELETED : ITEM_APPROVED; $post['plus_legacy_erased']=(int)($i===6); $post['plus_public_post']=1;
		$post['post_username']=$owner===ANONYMOUS?'Besucher':''; $post['post_text']='Avatar topic'; $post['post_subject']='Avatar topic';
		$db->sql_query('INSERT INTO '.POSTS_TABLE.' '.$db->sql_build_array('INSERT',$post));
	}
	echo json_encode(array('image'=>$config['plus_topic_avatar_image'],'profile'=>(int)$normal[0]['user_id'],'withdrawn'=>(int)$u[85]));
}
elseif ($phase==='clean')
{
	$state=json_decode($text->get($backup),true);
	$db->sql_query('DELETE FROM '.POSTS_TABLE.' WHERE topic_id=28701 AND post_id BETWEEN 28701 AND 28708');
	$db->sql_query("DELETE FROM ".TOPICS_TABLE." WHERE topic_id=28701 AND topic_title='Avatar probe'");
	foreach ($state['config'] as $key=>$value) { $config->set($key,$value); }
	$db->sql_query('UPDATE '.USERS_TABLE.' SET user_options='.(int)$state['options'].' WHERE user_id='.(int)$state['viewer']);
	$db->sql_query('UPDATE '.USERS_TABLE.' SET '.$db->sql_build_array('UPDATE',$state['personal']).' WHERE user_id='.(int)$state['withdrawn']);
	$text->delete($backup);
}
else { throw new RuntimeException('Unexpected topic avatar test phase'); }
