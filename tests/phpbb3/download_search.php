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
	if (!$done || $db->get_sql_error_triggered()) { fwrite(STDERR,"Incomplete advanced search fixture: " . ($db->get_sql_error_returned()['message'] ?? 'interrupted') . "\n"); exit(1); }
});
$rows = static function ($sql) use ($db) {
	$r=$db->sql_query($sql);
	if ($r===false) { throw new RuntimeException('Download search lookup failed'); }
	$rows=$db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $rows;
};
$put = static function ($table,$row) use ($db) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$row)); };
$set = static function ($key,$value) use ($db,$p,$put) {
	$db->sql_query("DELETE FROM {$p}settings WHERE config_name='" . $db->sql_escape($key) . "'");
	$put($p.'settings',array('config_name'=>$key,'config_value'=>$value));
};
$keys=array('auth_search','settings_file_page','allow_comment_links','need_validation');
switch ($argv[2])
{
	case 'converted':
		$file=$rows("SELECT search_creator,search_detail FROM {$p}files WHERE file_id=50")[0];
		$comment=$rows("SELECT search_body FROM {$p}comments WHERE comment_id=91")[0];
		if ($file['search_creator']!=='fiktive autorin' || $file['search_detail']!=='mehrzeilige beschreibung' ||
			strpos($comment['search_body'],'beispiel')===false || strpos($comment['search_body'],'forbidden-preview')!==false)
		{ throw new RuntimeException('Converted search data mixed public text with hidden markup'); }
		break;
	case 'older':
		if ($phpbb_container->get('dbal.tools')->sql_column_exists($p.'files','search_creator')) { throw new RuntimeException('Older file schema not restored'); }
		$file=$rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$put($p.'files',array('file_id'=>10880,'source_json'=>'{"file_creator":"Altbestand","unchanged":11}')+$file);
		$row=$rows("SELECT * FROM {$p}comments WHERE comment_id=91")[0];
		$put($p.'comments',array('comment_id'=>10880,'file_id'=>10880,'body'=>'<r><B>Öffentlich</B> <URL url="https://example.invalid/hidden">Linkname</URL></r>')+$row);
		break;
	case 'upgraded':
		$file=$rows("SELECT search_creator,source_json FROM {$p}files WHERE file_id=10880")[0];
		$comment=$rows("SELECT search_body,search_links FROM {$p}comments WHERE comment_id=10880")[0];
		if ($file['search_creator']!=='altbestand' || json_decode($file['source_json'],true)['unchanged']!==11 ||
			trim($comment['search_body'])!=='öffentlich' || trim($comment['search_links'])!=='linkname') { throw new RuntimeException('Old native board lost searchable text'); }
		$db->sql_query("DELETE FROM {$p}comments WHERE comment_id=10880");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=10880");
		break;
	case 'seed':
		$old=$rows("SELECT config_name,config_value FROM {$p}settings WHERE " . $db->sql_in_set('config_name',$keys));
		$set('test_search_backup',json_encode($old));
		foreach (array('auth_search'=>'0','settings_file_page'=>'1','allow_comment_links'=>'1','need_validation'=>'0') as $key=>$value) { $set($key,$value); }
		$cat=$rows("SELECT * FROM {$p}categories WHERE cat_id=21")[0];
		$levels=array('view'=>0,'read'=>0,'view_file'=>0,'view_comment'=>0,'post_comment'=>1,'edit_file'=>1,'upload'=>1)+json_decode($cat['access_levels'],true);
		$put($p.'categories',array('cat_id'=>10830,'parent_id'=>21,'title'=>'Search fixture','allow_comments'=>1,'access_levels'=>json_encode($levels))+$cat);
		$source=$rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$modern=(int)$rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='ModernMember'")[0]['user_id'];
		$files=array(10841=>array('Atlas A','Farbplan 100% Ecke_1','Werkstatt 🎨','Polarstern &amp; Archiv Fantasie',10830,$source['owner_id'],1),
			10842=>array('Atlas B','Musik 100x Eckex1','Team','Wolke',10830,$modern,1),10843=>array('Atlas C','Farbplan','Team','Wolke',10830,0,1),
			10844=>array('Clubatlas','SucheLabor','Team','Clubbrief',23,$modern,1),10845=>array('Atlas Entwurf','SucheLabor','Team','Polarstern',10830,$modern,0),
			10846=>array('Privatatlas','SucheLabor','Team','Einzelbrief',24,$modern,1));
		foreach ($files as $id=>$f)
		{
			list($title,$summary,$creator,$detail,$category,$owner,$approved)=$f;
			$meta=array('file_creator'=>$creator,'file_longdesc'=>$detail,'private_storage'=>'InternalPathFixture');
			$row=array('file_id'=>$id,'cat_id'=>$category,'title'=>$title,'summary'=>$summary,'downloads'=>10847-$id,'updated_at'=>$id,
				'owner_id'=>$owner,'approved'=>$approved,'source_json'=>json_encode($meta));
			$put($p.'files',$row+\phpbb2plus\downloads\service\search_text::file($row,$meta)+$source);
		}
		$row=array('comment_id'=>10850,'file_id'=>10842,'title'=>'Winterbericht','body'=>'<r><B>Himbeerfarbe</B> <URL url="https://example.invalid/SecretURL"><s>[url]</s>Wegweiser<e>[/url]</e></URL><IMG src="https://example.invalid/HiddenImage">HiddenImage</IMG></r>','source_json'=>'{}');
		$put($p.'comments',$row+\phpbb2plus\downloads\service\search_text::comment($row));
		$put($p.'votes',array('file_id'=>10842,'points'=>10,'source_json'=>'{}'));
		break;
	case 'terms':
		$search=$phpbb_container->get('phpbb2plus.downloads.file_search');
		$criteria=array('search_author'=>'','search_terms'=>'any','comments_search'=>'YES');
		foreach (array('100%'=>10841,'Ecke_1'=>10841,'Winterbericht'=>10842,'Wegweiser'=>10842,'&'=>10841,'amp'=>0,
			'SecretURL'=>0,'HiddenImage'=>0,'Atlas +Phantasie'=>10841,'Farbplan -Polarstern'=>10843) as $text=>$expected)
		{
			$where=$search->filter(array('search_keywords'=>$text)+$criteria,array(10830),array(10830));
			if ($search->ids($where)!==($expected ? array($expected) : array())) { throw new RuntimeException('Unexpected scoped search match for ' . $text); }
		}
		$where=$search->filter(array('search_keywords'=>'Atlas')+$criteria,array(10830),array(10830));
		$listing=$phpbb_container->get('phpbb2plus.downloads.report_data');
		foreach (array('file_name'=>10843,'file_time'=>10843,'file_rating'=>10842,'file_update_time'=>10843,'file_dls'=>10841) as $sort=>$id)
		{
			$row=$listing->files($where,$sort,1,0,'DESC');
			if ((int) $row[0]['file_id']!==$id) { throw new RuntimeException('Wrong search ranking with ' . $sort); }
		}
		break;
	case 'narrow':
		$levels=json_decode($rows("SELECT access_levels FROM {$p}categories WHERE cat_id=10830")[0]['access_levels'],true);
		$levels['view_comment']=1;
		$db->sql_query("UPDATE {$p}categories SET " . $db->sql_build_array('UPDATE',array('access_levels'=>json_encode($levels))) . ' WHERE cat_id=10830');
		$db->sql_query("UPDATE {$p}files SET approved=0 WHERE file_id=10841");
		$row=$rows("SELECT * FROM {$p}files WHERE file_id=10843")[0];
		$put($p.'files',array('file_id'=>10847,'title'=>'Atlas D','search_title'=>'atlas d')+$row);
		$set('allow_comment_links','0');
		break;
	case 'restricted':
		$set('auth_search','3');
		break;
	case 'expire':
		$db->sql_query("UPDATE {$p}searches SET created_at=0 WHERE payload LIKE '%1084%'");
		break;
	case 'restore':
		$old=json_decode($rows("SELECT config_value FROM {$p}settings WHERE config_name='test_search_backup'")[0]['config_value'],true);
		$db->sql_query("DELETE FROM {$p}settings WHERE " . $db->sql_in_set('config_name',array_merge($keys,array('test_search_backup'))));
		foreach ($old as $row) { $put($p.'settings',$row); }
		$db->sql_query("DELETE FROM {$p}comments WHERE file_id BETWEEN 10841 AND 10847");
		$db->sql_query("DELETE FROM {$p}receipts WHERE file_id BETWEEN 10841 AND 10847");
		$db->sql_query("DELETE FROM {$p}votes WHERE file_id=10842");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id BETWEEN 10841 AND 10847");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id=10830");
		$phpbb_container->get('phpbb2plus.downloads.search_results')->expire();
		if ($rows("SELECT search_id FROM {$p}searches WHERE created_at=0")) { throw new RuntimeException('Expired download searches were not removed'); }
		break;
	default: exit(2);
}
$done=true;
