<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./';
$phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$p = $table_prefix . 'plus_dl_';
$done = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$done,$db) {
	if (!$done || $db->get_sql_error_triggered()) { fwrite(STDERR,"Incomplete download directory fixture: " .
		($db->get_sql_error_returned()['message'] ?? 'interrupted') . "\n"); exit(1); }
});
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $rows;
};
$insert = static function ($table,$data) use ($db) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$data)); };
$set = static function ($key,$value) use ($db,$p,$insert) {
	$db->sql_query("DELETE FROM {$p}settings WHERE config_name='" . $db->sql_escape($key) . "'");
	$insert($p.'settings',array('config_name'=>$key,'config_value'=>(string) $value));
};
$keys = array('settings_viewall','settings_file_page','settings_newdays','sort_method','sort_order','auth_viewall','auth_search');
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		$before = $rows("SELECT config_name,config_value FROM {$p}settings WHERE " . $db->sql_in_set('config_name',$keys));
		$set('test_download_listing',json_encode($before));
		foreach (array('settings_viewall'=>1,'settings_file_page'=>2,'settings_newdays'=>2,'sort_method'=>'file_dls','sort_order'=>'ASC',
			'auth_viewall'=>0,'auth_search'=>0) as $name=>$value) { $set($name,$value); }
		$cat = $rows("SELECT * FROM {$p}categories WHERE cat_id=21")[0];
		$hidden = $rows("SELECT * FROM {$p}categories WHERE cat_id=22")[0];
		foreach (array(
			array(10930,0,1,'Listing directory',0,$cat),array(10931,10930,5,'Public listing shelf',1,$cat),
			array(10932,10930,2,'Shared listing shelf',0,$hidden),array(10933,10932,1,'Inherited listing shelf',1,$cat),
		) as list($id,$parent,$position,$title,$fileable,$template))
		{ $insert($p.'categories',array('cat_id'=>$id,'parent_id'=>$parent,'position'=>$position,'title'=>$title,'allow_files'=>$fileable)+$template); }
		foreach ($rows("SELECT * FROM {$p}grants WHERE cat_id=22 AND subject_type='g'") as $grant)
		{ unset($grant['row_id']); $insert($p.'grants',array('cat_id'=>10932)+$grant); }
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$now = time();
		foreach (array(
			// ID, title, added, updated and download ordering deliberately differ.
			array(10941,'Pin West',2,2,80),array(10942,'Pin East',3,3,60),
			array(10943,'Alpha',1,4,40),array(10944,'Bravo',4,3,10),array(10945,'Bravo',3,5,10),array(10946,'Zulu',2,2,30),
			array(10947,'Waiting listing file',8,8,100),array(10948,'Inherited listing file',6,6,90),array(10949,'Folder-only file',9,9,99),
		) as list($id,$title,$added,$updated,$count))
		{
			$insert($p.'files',array('file_id'=>$id,'cat_id'=>$id===10948 ? 10933 : ($id===10949 ? 10930 : 10931),
				'owner_id'=>$id===10941 ? 0 : ($id===10942 ? 2147483000 : $file['owner_id']),
				'file_bytes'=>$id===10941 ? 0 : $file['file_bytes'],
				'title'=>$title,'summary'=>'Listing sample','pinned'=>(int) ($id<10943),'approved'=>(int) ($id!==10947),
				'added_at'=>$now-900+$added,'updated_at'=>$now-900+$updated,'downloads'=>$count)+$file);
		}
		$vote = $rows("SELECT * FROM {$p}votes WHERE file_id=50 ORDER BY row_id")[0];
		unset($vote['row_id']);
		foreach (array(10943=>4,10944=>9,10946=>6) as $id=>$value) { $insert($p.'votes',array('file_id'=>$id,'points'=>$value)+$vote); }
		break;
	case 'private':
		$set('auth_viewall',2);
		$set('settings_file_page',100);
		break;
	case 'personal':
		$person = $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'")[0];
		$insert($p.'grants',array('cat_id'=>0,'legacy_group'=>0,'subject_type'=>'u','subject_id'=>(int) $person['user_id'],
			'allowed'=>'["viewall"]','source_json'=>'{"listing_fixture":1}'));
		break;
	case 'disabled':
		$set('settings_viewall',0);
		break;
	case 'restore':
		$previous = $rows("SELECT config_value FROM {$p}settings WHERE config_name='test_download_listing'")[0];
		$keys[]='test_download_listing';
		$db->sql_query("DELETE FROM {$p}settings WHERE " . $db->sql_in_set('config_name',$keys));
		foreach (json_decode($previous['config_value'],true) as $record) { $insert($p.'settings',$record); }
		$db->sql_query("DELETE FROM {$p}votes WHERE file_id BETWEEN 10941 AND 10949");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id BETWEEN 10941 AND 10949");
		$db->sql_query("DELETE FROM {$p}grants WHERE cat_id=10932");
		$db->sql_query("DELETE FROM {$p}grants WHERE cat_id=0 AND source_json='{\"listing_fixture\":1}'");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id BETWEEN 10930 AND 10933");
		break;
	default: exit(2);
}
$db->sql_transaction('commit');
$done=true;
