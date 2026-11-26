<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./';
$phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$p=$table_prefix.'plus_arc_';
$rows=static function($sql) use ($db) {
	$r=$db->sql_query($sql); $rows=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$text=$phpbb_container->get('config_text');
$actor=(int)$rows('SELECT user_id FROM '.USERS_TABLE." WHERE username_clean='arcadeimportjunior'")[0]['user_id'];
$state='plus_test_import_batch';
$history=static function() use ($rows,$p) {
	$data=array();
	foreach (array('scores'=>'row_id','all_scores'=>'row_id','monthly'=>'highscore_id','favorites'=>'row_id') as $table=>$id)
	{ $data[]=$rows("SELECT * FROM $p$table ORDER BY $id"); }
	$data[]=$rows("SELECT * FROM {$p}games WHERE game_name='timer'");
	return hash('sha256',serialize($data));
};
$path=static function($key,$name) use ($config) {
	return './'.trim($config['upload_path'],'/').'/arcade_'.hash_hmac('sha256',"staged\0".$key."\0".$name,$config['plus_arc_media_key']);
};
$phase=$argv[2];
if ($phase==='prepare')
{
	$rights=$rows("SELECT rights_json FROM {$table_prefix}plus_jr_delegations WHERE user_id=$actor");
	if (!$rights || json_decode($rights[0]['rights_json'],true)!==array('a_plus_arc_import')) { throw new RuntimeException('Missing source Import-only grant'); }
	$stored=array('history'=>$history(),'cats'=>$rows("SELECT cat_id,total_games,cat_type FROM {$p}categories WHERE cat_id IN(-1,10)"),
		'stages'=>array_column($rows("SELECT stage_key FROM {$p}staged"),'stage_key'),
		'example'=>$rows("SELECT win_width,win_height FROM {$p}games WHERE game_id=63 AND game_name='closed'")[0],
		'default'=>$rows("SELECT config_value FROM {$p}settings WHERE config_name='games_default_id'")[0]['config_value']);
	$text->set($state,json_encode($stored,JSON_THROW_ON_ERROR));
	$db->sql_query("UPDATE {$p}games SET win_width=317,win_height=193 WHERE game_id=63 AND game_name='closed'");
	$db->sql_query("UPDATE {$p}settings SET config_value='63' WHERE config_name='games_default_id'");
}
elseif ($phase==='never' || $phase==='allow')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$actor,array('a_plus_arc_import'=>$phase==='never' ? ACL_NEVER : ACL_NO));
}
elseif ($phase==='hold' || $phase==='restore')
{
	$stage=$rows("SELECT stage_key FROM {$p}staged WHERE game_name='batch_broken' AND game_id=0 AND ready=1");
	if (count($stage)!==1) { throw new RuntimeException('Unexpected prepared fixture identity'); }
	$file=$path($stage[0]['stage_key'],'batch_broken.swf'); $held=$file.'.fixture-held';
	$from=$phase==='hold' ? $file : $held; $to=$phase==='hold' ? $held : $file;
	if (!is_file($from) || is_link($from) || file_exists($to) || !rename($from,$to)) { throw new RuntimeException('Cannot hold the specified synthetic movie'); }
}
elseif ($phase==='category-away' || $phase==='category-back')
{
	$stored=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	$type=$phase==='category-away' ? 'l' : array_column($stored['cats'],'cat_type','cat_id')[10];
	$db->sql_query("UPDATE {$p}categories SET cat_type='".$db->sql_escape($type)."' WHERE cat_id=10");
}
elseif ($phase==='verify')
{
	$stored=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	$games=$rows("SELECT * FROM {$p}games WHERE game_name LIKE 'batch\\_%' ORDER BY game_name");
	if (count($games)!==15 || $history()!==$stored['history']) { throw new RuntimeException('Unexpected new or updated games and historical scores'); }
	$result=0;
	foreach ($games as $g)
	{
		$name=$g['game_name']; $id=(int)$g['game_id']; $ext=pathinfo($name,PATHINFO_EXTENSION);
		$package=$ext==='';
		$w=$ext==='jpg' ? 317 : ($ext==='gif' ? 1 : 80);
		$h=$ext==='jpg' ? 193 : ($ext==='gif' ? 1 : 45);
		if ($name==='batch_lzma.swf') { $w=550; $h=450; }
		if (($package && !preg_match('/^batch_(?:0[0-9]|nested)$/D',$name)) || (!$package && !in_array($name,array('batch_plain.swf','batch_lzma.swf','batch_photo.jpg','batch_image.gif'),true)) ||
			(int)$g['game_avail']!==($package ? 1 : 0) || (int)$g['cat_id']!==10 || (int)$g['game_flash']!==($package ? 1 : 0) ||
			$g['game_desc']!==ucfirst(str_replace('_',' ',$name)) || (int)$g['game_show_score']!==1 || (int)$g['reverse_list']!==0 ||
			(int)$g['game_charge']!==0 || (int)$g['win_width']!==$w || (int)$g['win_height']!==$h || $g['asset_status']!=='ready' ||
			$g['main_asset']!==($package ? $name.'.swf' : $name) || (bool)$g['icon_storage']!==$package)
		{ throw new RuntimeException('Unexpected imported metadata or dimensions for '.$name); }
		$assets=$rows("SELECT relative_name FROM {$p}assets WHERE game_id=$id");
		if (count($assets)!==($package ? 2 : 1)) { throw new RuntimeException('Imported unrelated sidecar files'); }
		if ($name==='batch_00') { $result=$id; }
	}
	foreach ($stored['cats'] as $c)
	{
		$id=(int)$c['cat_id'];
		if ((int)$rows("SELECT total_games FROM {$p}categories WHERE cat_id=$id")[0]['total_games']!==(int)$c['total_games']+11)
		{ throw new RuntimeException('Incorrect online category total after bulk import'); }
	}
	if ($rows("SELECT game_id FROM {$p}assets WHERE relative_name='batch_broken.gif'") ||
		(int)$rows('SELECT COUNT(*) AS n FROM '.LOG_TABLE." WHERE log_operation='LOG_PLUS_ARC_IMPORT' AND user_id=$actor")[0]['n']!==5)
	{ throw new RuntimeException('Partial private files or duplicate import audit records remained'); }
	echo $result;
}
elseif ($phase==='cleanup')
{
	$stored=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$actor,array('a_plus_arc_import'=>ACL_NO));
	$batches=$rows("SELECT batch_key FROM {$p}imports WHERE label LIKE 'Fixture Batch %'");
	$keys=array();
	foreach ($batches as $batch)
	{
		$key=$db->sql_escape($batch['batch_key']);
		foreach ($rows("SELECT stage_key FROM {$p}imports_items WHERE batch_key='$key'") as $item) { $keys[$item['stage_key']]=true; }
		$db->sql_query("DELETE FROM {$p}imports_items WHERE batch_key='$key'");
		$db->sql_query("DELETE FROM {$p}imports WHERE batch_key='$key'");
	}
	foreach (array_diff(array_keys($keys),$stored['stages']) as $key)
	{
		if (!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new RuntimeException('Unexpected local fixture key'); }
		$stage=$rows("SELECT * FROM {$p}staged WHERE stage_key='$key'")[0] ?? null;
		if (!$stage) { continue; }
		if ($id=(int)$stage['game_id'])
		{
			$game=$rows("SELECT game_name FROM {$p}games WHERE game_id=$id")[0]['game_name'];
			if (strpos($game,'batch_')!==0) { throw new RuntimeException('Unexpected synthetic game ownership'); }
			$db->sql_query("DELETE FROM {$p}assets WHERE game_id=$id");
			$db->sql_query("DELETE FROM {$p}games WHERE game_id=$id");
		}
		foreach (array_keys(json_decode($stage['manifest_json'],true)) as $name)
		{
			$file=$path($key,$name); $storage=basename($file);
			if (!is_link($file) && is_file($file) && !$rows("SELECT game_id FROM {$p}assets WHERE storage_name='$storage'")) { unlink($file); }
		}
		$db->sql_query("DELETE FROM {$p}staged WHERE stage_key='$key'");
	}
	foreach ($stored['cats'] as $c) { $db->sql_query("UPDATE {$p}categories SET ".$db->sql_build_array('UPDATE',$c).' WHERE cat_id='.(int)$c['cat_id']); }
	$db->sql_query("UPDATE {$p}games SET ".$db->sql_build_array('UPDATE',$stored['example'])." WHERE game_id=63 AND game_name='closed'");
	$db->sql_query("UPDATE {$p}settings SET config_value='".$db->sql_escape($stored['default'])."' WHERE config_name='games_default_id'");
	$db->sql_query('DELETE FROM '.LOG_TABLE." WHERE user_id=$actor AND log_operation='LOG_PLUS_ARC_IMPORT'");
	$text->delete($state);
}
else { throw new RuntimeException('Unexpected Arcade import fixture phase'); }
