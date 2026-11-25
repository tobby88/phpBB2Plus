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
	if (!$done || $db->get_sql_error_triggered()) { fwrite(STDERR,"Incomplete download report fixture: " .
		($db->get_sql_error_returned()['message'] ?? 'interrupted') . "\n"); exit(1); }
});
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql);
	if ($r===false) { throw new RuntimeException('Download report lookup failed'); }
	$rows = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $rows;
};
$put = static function ($table,$row) use ($db) {
	$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$row));
};
$set = static function ($key,$value) use ($db,$p,$put) {
	$db->sql_query("DELETE FROM {$p}settings WHERE config_name='" . $db->sql_escape($key) . "'");
	$put($p.'settings',array('config_name'=>$key,'config_value'=>$value));
};
$keys = array('auth_stats','auth_toplist','settings_topnumber','settings_file_page','settings_newdays');
switch ($argv[2])
{
	case 'converted':
		$converted = $rows("SELECT client_os,client_browser FROM {$p}visitors WHERE file_id=50");
		if (!in_array(array('client_os'=>'linux','client_browser'=>'firefox'),$converted,true)) { throw new RuntimeException('Historical client families missing after conversion'); }
		break;
	case 'older':
		if ($phpbb_container->get('dbal.tools')->sql_column_exists($p.'votes','client_os')) { throw new RuntimeException('Older schema not restored'); }
		$batch = array_fill(0,500,array('file_id'=>10788,'ip_address'=>'','owner_id'=>0,'points'=>7,'source_json'=>'{"voter_os":"Mac","voter_browser":"NETSCAPE"}'));
		$batch[] = array('file_id'=>10788,'ip_address'=>'','owner_id'=>0,'points'=>9,'source_json'=>'{"voter_os":"unknown","voter_browser":"IE"}');
		$db->sql_multi_insert($p.'votes',$batch);
		break;
	case 'upgraded':
		$count = $rows("SELECT client_os,client_browser,COUNT(*) AS n FROM {$p}votes WHERE file_id=10788 GROUP BY client_os,client_browser ORDER BY client_os");
		if (count($count)!==2 || $count[0]['client_os']!=='mac' || $count[0]['client_browser']!=='netscape' || (int) $count[0]['n']!==500 ||
			$count[1]['client_os']!=='other' || $count[1]['client_browser']!=='ie' || (int) $count[1]['n']!==1) { throw new RuntimeException('Report backfill lost a client group across batches'); }
		$db->sql_query("DELETE FROM {$p}votes WHERE file_id=10788");
		break;
	case 'open':
		$old = $rows("SELECT config_name,config_value FROM {$p}settings WHERE " . $db->sql_in_set('config_name',$keys));
		$put($p.'settings',array('config_name'=>'test_report_backup','config_value'=>json_encode($old)));
		foreach (array('auth_stats'=>'0','auth_toplist'=>'0','settings_topnumber'=>'1','settings_file_page'=>'2','settings_newdays'=>'7') as $key=>$value) { $set($key,$value); }
		break;
	case 'seed':
		$cat = $rows("SELECT * FROM {$p}categories WHERE cat_id=21")[0];
		$levels = json_decode($cat['access_levels'],true);
		$public = array_fill_keys(array('view','read','view_file','rate','download'),0);
		$put($p.'categories',array('cat_id'=>10730,'title'=>'Report fixture','allow_ratings'=>1,'access_levels'=>json_encode($public+$levels))+$cat);
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$mark = 1136034000;
		$files = array(10741=>array('Archiv &amp; Übersicht',10730,$mark,60000,1),10742=>array('Recent sample',10730,time(),59000,1),
			10743=>array('Old sample',10730,time()-10*86400,58000,1),10744=>array('Club record',23,$mark,70000,1),
			10745=>array('Waiting record',10730,$mark,80000,0),10746=>array('Personal record',24,$mark,72000,1));
		foreach ($files as $id=>$info)
		{
			list($title,$cat,$added,$downloads,$approved)=$info;
			$put($p.'files',array('file_id'=>$id,'cat_id'=>$cat,'title'=>$title,'added_at'=>$added,'downloads'=>$downloads,'approved'=>$approved,
				'owner_id'=>$id===10741 ? 0 : $file['owner_id'],'license_id'=>0)+$file);
		}
		foreach (array(10741=>array(9,10),10742=>array(2),10744=>array(10),10745=>array(10),10746=>array(10)) as $id=>$points)
		{
			foreach ($points as $point) { $put($p.'votes',array('file_id'=>$id,'points'=>$point,'client_os'=>'unix','client_browser'=>'netscape','source_json'=>'{}')); }
			$put($p.'visitors',array('file_id'=>$id,'client_os'=>'ios','client_browser'=>'safari','source_json'=>'{}'));
		}
		break;
	case 'restricted':
		$set('auth_stats','1');
		$set('auth_toplist','3');
		break;
	case 'calendar':
		$reports = $phpbb_container->get('phpbb2plus.downloads.report_data');
		list($summary,$records) = $reports->statistics(array('where'=>'f.file_id IN (10741,10742,10743)'));
		if ((int) $summary['files']!==3 || (int) $summary['downloads']!==177000 || (int) $summary['mean_downloads']!==59000 ||
			abs((float) $summary['mean_rating']-5.75)>0.00001 || (int) $records['best']['file_id']!==10741 || (int) $records['lowest']['file_id']!==10742)
		{ throw new RuntimeException('File averages changed with the number of votes per file'); }
		list($empty,$records) = $reports->statistics(array('where'=>'1=0'));
		if ((int) $empty['files'] || array_filter($records)) { throw new RuntimeException('An empty catalogue acquired a record'); }
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=10741")[0];
		$zone = new DateTimeZone('Europe/Berlin');
		$db->sql_transaction('begin');
		foreach (array('2021-10-30 23:59:59','2021-10-31T02:30:00+02:00','2021-10-31T02:30:00+01:00','2021-10-31 23:59:59','2021-11-01 00:00:00') as $i=>$date)
		{ $put($p.'files',array('file_id'=>10770+$i,'added_at'=>(new DateTimeImmutable($date,$zone))->getTimestamp())+$file); }
		$counts = $reports->day_counts('f.file_id BETWEEN 10770 AND 10774',new DateTimeImmutable('2021-11-01',$zone),3);
		$db->sql_transaction('rollback');
		ksort($counts);
		if ($counts!==array('2021-10-30'=>1,'2021-10-31'=>3,'2021-11-01'=>1)) { throw new RuntimeException('The clock change moved a file to a neighbouring day'); }
		break;
	case 'native':
		if (!$rows("SELECT row_id FROM {$p}votes WHERE file_id=10743 AND client_os='android' AND client_browser='edge'") ||
			!$rows("SELECT row_id FROM {$p}visitors WHERE file_id=10743 AND client_os='android' AND client_browser='edge'"))
		{ throw new RuntimeException('A new vote or download was not included in the same family reports as converted records'); }
		break;
	case 'restore':
		$old = json_decode($rows("SELECT config_value FROM {$p}settings WHERE config_name='test_report_backup'")[0]['config_value'],true);
		$db->sql_query("DELETE FROM {$p}settings WHERE " . $db->sql_in_set('config_name',array_merge($keys,array('test_report_backup'))));
		foreach ($old as $row) { $put($p.'settings',$row); }
		foreach (array('votes','visitors','receipts','files') as $table) { $db->sql_query("DELETE FROM $p$table WHERE file_id BETWEEN 10741 AND 10746"); }
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id=10730");
		break;
	default: exit(2);
}
$done = true;
