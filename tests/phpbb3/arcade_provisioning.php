<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./';
$phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$p=$table_prefix.'plus_arc_';
$rows=static function($sql)use($db){
	$r=$db->sql_query($sql); $rows=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$text=$phpbb_container->get('config_text');
$state='plus_test_game_stages';
$add=(int)$rows('SELECT user_id FROM '.USERS_TABLE." WHERE username_clean='arcadeaddjunior'")[0]['user_id'];
$edit=(int)$rows('SELECT user_id FROM '.USERS_TABLE." WHERE username_clean='arcadescoresjunior'")[0]['user_id'];
$history=static function() use ($rows,$p) {
	$data=array();
	foreach (array('scores'=>'row_id','all_scores'=>'row_id','monthly'=>'highscore_id','favorites'=>'row_id') as $table=>$id)
	{ $data[]=$rows("SELECT * FROM $p$table ORDER BY $id"); }
	$data[]=$rows("SELECT * FROM {$p}assets WHERE game_id=61 ORDER BY path_key");
	return hash('sha256',serialize($data));
};
$phase=$argv[2];
if ($phase==='prepare')
{
	$game=$rows("SELECT * FROM {$p}games WHERE game_id=63")[0];
	$rights=$rows("SELECT rights_json FROM {$table_prefix}plus_jr_delegations WHERE user_id=$add");
	if ($game['asset_status']==='ready' || $rows("SELECT game_id FROM {$p}assets WHERE game_id=63") ||
		!$rights || json_decode($rights[0]['rights_json'],true)!==array('a_plus_arc_add'))
	{ throw new RuntimeException('Expected a converted game with missing media and the original standalone Add grant'); }
	$categories=$rows("SELECT * FROM {$p}categories WHERE cat_id IN(-1,10)");
	$default=$rows("SELECT config_value FROM {$p}settings WHERE config_name='games_default_id'")[0]['config_value'];
	$text->set($state,json_encode(array('closed'=>$game,'cats'=>$categories,'history'=>$history(),'default'=>$default),JSON_THROW_ON_ERROR));
	$db->sql_query("UPDATE {$p}settings SET config_value='61' WHERE config_name='games_default_id'");
}
elseif ($phase==='never' || $phase==='allow')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$add,array('a_plus_arc_add'=>$phase==='never' ? ACL_NEVER : ACL_NO));
}
elseif ($phase==='hide-file' || $phase==='restore-file')
{
	$stage=$rows("SELECT stage_key FROM {$p}staged WHERE game_name='fixture-stage-play' AND game_id=0 AND ready=1");
	if (count($stage)!==1 || $rows("SELECT game_id FROM {$p}games WHERE game_name='fixture-stage-play'"))
	{ throw new RuntimeException('An incomplete media assignment committed a game'); }
	$key=$stage[0]['stage_key'];
	$file='./'.trim($config['upload_path'],'/').'/arcade_'.hash_hmac('sha256',"staged\0".$key."\0levels/two.txt",$config['plus_arc_media_key']);
	$held=$file.'.fixture-held';
	$from=$phase==='hide-file'?$file:$held;
	$to=$phase==='hide-file'?$held:$file;
	if (!is_file($from) || is_link($from) || file_exists($to) || is_link($to) || !rename($from,$to))
	{ throw new RuntimeException('Cannot move the identified synthetic level file'); }
}
elseif ($phase==='verify')
{
	$stored=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	$new=$rows("SELECT * FROM {$p}games WHERE game_name='fixture-stage-play'");
	if (count($new)!==1 || $new[0]['asset_status']!=='ready' || $new[0]['main_asset']!=='fixture-stage-play.swf' ||
		(int)$new[0]['cat_id']!==10 || (int)$new[0]['date_added']<=0 || $history()!==$stored['history'])
	{ throw new RuntimeException('Creation duplicated or attached unrelated historical Arcade results'); }
	$id=(int)$new[0]['game_id'];
	$assets=array_column($rows("SELECT * FROM {$p}assets WHERE game_id=$id ORDER BY relative_name"),null,'relative_name');
	if (array_keys($assets)!==array('fixture-stage-play.swf','icon.png','levels/two.txt') || $assets['icon.png']['storage_name']!==$new[0]['icon_storage'] ||
		!$rows("SELECT game_id FROM {$p}staged WHERE game_id=$id AND ready=1") ||
		!$rows("SELECT game_id FROM {$p}staged WHERE game_id=63 AND ready=1"))
	{ throw new RuntimeException('The game did not receive precisely the prepared files and icon'); }
	$recovered=$rows("SELECT * FROM {$p}games WHERE game_id=63")[0];
	if ($recovered['asset_status']!=='ready' || $recovered['main_asset']!=='closed.swf' || $recovered['game_desc']!==$stored['closed']['game_desc'] ||
		(int)$rows('SELECT COUNT(*) AS n FROM '.LOG_TABLE." WHERE log_operation='LOG_PLUS_ARC_ADD_GAME' AND user_id=$add")[0]['n']!==1 ||
		(int)$rows('SELECT COUNT(*) AS n FROM '.LOG_TABLE." WHERE log_operation='LOG_PLUS_ARC_ATTACH_GAME' AND user_id=$edit")[0]['n']!==1)
	{ throw new RuntimeException('Media repair changed old metadata or produced an inconsistent audit log'); }
}
elseif ($phase==='cleanup')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$add,array('a_plus_arc_add'=>ACL_NO));
	$stored=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	$new=$rows("SELECT game_id FROM {$p}games WHERE game_name='fixture-stage-play'");
	$ids=array_merge(array(63),array_map('intval',array_column($new,'game_id')));
	$where=$db->sql_in_set('game_id',$ids);
	$files=$rows("SELECT storage_name FROM {$p}assets WHERE $where");
	if ($new) { $id=(int)$new[0]['game_id']; $db->sql_query("DELETE FROM {$p}launches WHERE game_id=$id"); $db->sql_query("DELETE FROM {$p}games WHERE game_id=$id"); }
	$db->sql_query("DELETE FROM {$p}assets WHERE $where");
	$db->sql_query("DELETE FROM {$p}staged WHERE $where");
	$db->sql_query("UPDATE {$p}games SET ".$db->sql_build_array('UPDATE',$stored['closed']).' WHERE game_id=63');
	$db->sql_query("UPDATE {$p}settings SET config_value='".$db->sql_escape($stored['default'])."' WHERE config_name='games_default_id'");
	foreach ($stored['cats'] as $row) { $db->sql_query("UPDATE {$p}categories SET ".$db->sql_build_array('UPDATE',$row).' WHERE cat_id='.(int)$row['cat_id']); }
	foreach ($files as $row)
	{
		$name=$row['storage_name'];
		if (!preg_match('/^arcade_[a-f0-9]{64}$/D',$name) || $rows("SELECT game_id FROM {$p}assets WHERE storage_name='$name'")) { continue; }
		$path='./'.trim($config['upload_path'],'/').'/'.$name;
		if (!is_link($path) && is_file($path)) { unlink($path); }
	}
	$db->sql_query('DELETE FROM '.LOG_TABLE." WHERE (log_operation='LOG_PLUS_ARC_ADD_GAME' AND user_id=$add) OR (log_operation='LOG_PLUS_ARC_ATTACH_GAME' AND user_id=$edit)");
	$text->delete($state);
}
else { throw new RuntimeException('Unexpected local Arcade provisioning phase'); }
