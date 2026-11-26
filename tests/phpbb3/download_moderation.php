<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$done=false;
$db->sql_return_on_error(true);
register_shutdown_function(static function() use (&$done,$db) {
	if (!$done || $db->get_sql_error_triggered()) { fwrite(STDERR, "Download moderation fixture failed: " .
		($db->get_sql_error_returned()['message'] ?? 'incomplete') . "\n"); exit(1); }
});
$p=$table_prefix . 'plus_dl_';
$state=$phpbb_container->get('config_text');
$rows=static function($sql) use ($db) {
	$r=$db->sql_query($sql);
	if ($r===false) { throw new RuntimeException($db->get_sql_error_returned()['message']); }
	$data=$db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $data;
};
$insert=static function($table,$data) use ($db) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$data)); };
$pref=static function($key,$value) use ($db,$p) {
	$db->sql_query("UPDATE {$p}settings SET " . $db->sql_build_array('UPDATE',array('config_value'=>$value)) . " WHERE config_name='$key'");
};
$actor=(int) $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0]['user_id'];
$group=11119;
$selection='file_id BETWEEN 11130 AND 11137';
switch ($argv[2])
{
	case 'seed':
		if ($rows("SELECT file_id FROM {$p}files WHERE $selection") || $rows("SELECT cat_id FROM {$p}categories WHERE cat_id BETWEEN 11110 AND 11114") ||
			$rows('SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $group)) { throw new RuntimeException('Synthetic directory IDs are occupied'); }
		$settings=array_column($rows("SELECT config_name,config_value FROM {$p}settings WHERE config_name IN ('validator','settings_file_page')"),'config_value','config_name');
		$state->set('plus_test_dl_moderation',json_encode($settings));
		$pref('validator','validator_admin'); $pref('settings_file_page','2');
		$g=$rows('SELECT * FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED'")[0];
		$insert(GROUPS_TABLE,array('group_id'=>$group,'group_name'=>'Download moderation fixture','group_type'=>GROUP_CLOSED)+$g);
		$insert(USER_GROUP_TABLE,array('group_id'=>$group,'user_id'=>$actor,'group_leader'=>0,'user_pending'=>0));
		$base=$rows("SELECT * FROM {$p}categories WHERE cat_id=21")[0];
		foreach (array(11110=>'Moderation North',11111=>'Moderation South',11112=>'Unlisted parent',11113=>'Unlisted child',11114=>'Ordinary category') as $id=>$title)
		{
			$levels=json_decode($base['access_levels'],true);
			$levels['view']=$levels['read']=$levels['view_file']=$id===11112?5:0;
			$insert($p.'categories',array('cat_id'=>$id,'parent_id'=>$id===11113?11112:0,'title'=>$title,
				'allow_files'=>(int)($id!==11112),'access_levels'=>json_encode($levels))+$base);
		}
		foreach (array(11110=>'u',11111=>'g',11113=>'u') as $cat=>$kind)
		{
			$insert($p.'grants',array('cat_id'=>$cat,'legacy_group'=>0,'subject_type'=>$kind,'subject_id'=>$kind==='g'?$group:$actor,'allowed'=>'["mod"]','source_json'=>'{}'));
		}
		$f=$rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$files=array(11130=>array(11110,'A north pending',0,0),11131=>array(11111,'B south pending',0,1),
			11132=>array(11110,'C north broken',1,1),11133=>array(11111,'D south broken',0,1),
			11134=>array(11113,'Hidden moderator draft',0,1),11135=>array(11114,'Unmoderated draft',0,1),
			11136=>array(11110,'E north published',1,0),11137=>array(11110,'F north published',1,0));
		foreach ($files as $id=>$item)
		{
			$insert($p.'files',array('file_id'=>$id,'cat_id'=>$item[0],'title'=>$item[1],'approved'=>$item[2],'broken'=>$item[3],
				'added_at'=>time()+$id,'downloads'=>11,'review_revision'=>0)+$f);
		}
		break;
	case 'allow': $pref('validator','validator_mod'); break;
	case 'suspend':
	case 'resume':
		$db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending=' . (int) ($argv[2]==='suspend') . " WHERE group_id=$group AND user_id=$actor");
		break;
	case 'change':
		$db->sql_query("UPDATE {$p}files SET review_revision=review_revision+1 WHERE file_id=11131");
		break;
	case 'untouched':
	case 'approved':
	case 'withdrawn':
		$flags=array_column($rows("SELECT file_id,approved,broken,downloads,review_revision,storage_name FROM {$p}files WHERE $selection ORDER BY file_id"),null,'file_id');
		$phase=$argv[2];
		$expected=array(11130=>array($phase!=='untouched',0,$phase==='untouched'?0:1),
			11131=>array($phase==='approved',1,$phase==='untouched'?0:($phase==='approved'?2:3)),
			11132=>array(1,1,0),11133=>array(0,1,0),11134=>array(0,1,0),11135=>array(0,1,0),11136=>array(1,0,0),11137=>array(1,0,0));
		$original=$rows("SELECT storage_name FROM {$p}files WHERE file_id=50")[0]['storage_name'];
		foreach ($expected as $id=>$want)
		{
			$f=$flags[$id];
			if ((int) $f['approved']!==(int) $want[0] || (int) $f['broken']!==$want[1] || (int) $f['review_revision']!==$want[2] ||
				(int) $f['downloads']!==11 || $f['storage_name']!==$original) { throw new RuntimeException("Unexpected moderated download state for $id after $phase"); }
		}
		break;
	case 'cleanup':
		foreach (json_decode($state->get('plus_test_dl_moderation'),true) as $key=>$value) { $pref($key,$value); }
		$db->sql_query("DELETE FROM {$p}files WHERE $selection"); $db->sql_query("DELETE FROM {$p}grants WHERE cat_id BETWEEN 11110 AND 11114");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id BETWEEN 11110 AND 11114");
		$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id=' . $group); $db->sql_query('DELETE FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $group);
		$state->delete('plus_test_dl_moderation');
		break;
	default: throw new RuntimeException('Unknown moderated downloads fixture stage');
}
$done=true;
