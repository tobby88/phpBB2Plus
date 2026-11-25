<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./';
$phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$p=$table_prefix . 'plus_dl_';
$done=false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$done,$db) {
	if (!$done || $db->get_sql_error_triggered()) { fwrite(STDERR,"Incomplete download mail fixture: " . ($db->get_sql_error_returned()['message'] ?? 'interrupted') . "\n"); exit(1); }
});
$rows=static function ($sql) use ($db) {
	$r=$db->sql_query($sql);
	$data=$db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $data;
};
$insert=static function ($table,$row) use ($db) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$row)); };
$set_level=static function ($id,$level) use ($rows,$db,$p) {
	$row=$rows("SELECT access_levels FROM {$p}categories WHERE cat_id=$id")[0];
	$levels=json_decode($row['access_levels'],true);
	$levels['email']=$level;
	$db->sql_query("UPDATE {$p}categories SET " . $db->sql_build_array('UPDATE',array('access_levels'=>json_encode($levels))) . " WHERE cat_id=$id");
};
$phase=explode(':',$argv[2],2);
switch ($phase[0])
{
	case 'converted':
	case 'older':
	case 'upgraded':
		$present=in_array($p.'mail_receipts',$phpbb_container->get('dbal.tools')->sql_list_tables(),true);
		$policy=array_column($rows("SELECT config_name,config_value FROM {$p}settings WHERE config_name IN ('massmail_protection','massmail_time')"),'config_value','config_name');
		if ($present!==($phase[0]!=='older') || ($policy['massmail_time'] ?? '')!=='37' || ($policy['massmail_protection'] ?? '')!=='0' ||
			!$rows("SELECT file_id FROM {$p}files WHERE file_id=50")) { throw new RuntimeException('Mail schema or converted CrackerTracker policy missing'); }
		if ($phase[0]==='converted')
		{
			require_once __DIR__ . '/../../phpBB3/install/convertors/plus_downloads.php';
			$src_db=$db;
			$convert=(object) array('src_table_prefix'=>'mailfixture_');
			$original='mailfixture_ctracker_config';
			$tools=$phpbb_container->get('dbal.tools');
			if (in_array($original,$tools->sql_list_tables(),true)) { throw new RuntimeException('Mail conversion fixture name in use'); }
			$read_policy=static function () use ($rows,$p) {
				return array_column($rows("SELECT config_name,config_value FROM {$p}settings WHERE config_name IN ('massmail_protection','massmail_time') ORDER BY config_name"),'config_value','config_name');
			};
			plus_dl_mail_policy();
			if ($read_policy()!==array('massmail_protection'=>'1','massmail_time'=>'20')) { throw new RuntimeException('Absent CrackerTracker lost its historical safe defaults'); }
			$db->sql_query("CREATE TABLE $original (ct_config_name VARCHAR(50) PRIMARY KEY,ct_config_value VARCHAR(50) NOT NULL) ENGINE=InnoDB");
			try
			{
				$insert($original,array('ct_config_name'=>'massmail_protection','ct_config_value'=>'unexpected'));
				plus_dl_mail_policy();
				if ($read_policy()!==array('massmail_protection'=>'unexpected','massmail_time'=>'')) { throw new RuntimeException('Incomplete policy was silently enabled'); }
			}
			finally
			{
				$db->sql_query("DROP TABLE $original");
				foreach ($policy as $key=>$value)
				{ $db->sql_query("UPDATE {$p}settings SET " . $db->sql_build_array('UPDATE',array('config_value'=>$value)) . " WHERE config_name='" . $db->sql_escape($key) . "'"); }
			}
		}
		break;
	case 'seed':
		$port=isset($phase[1]) && ctype_digit($phase[1]) ? (int) $phase[1] : 0;
		if ($port<1024 || $port>65535) { exit(2); }
		// Synthetic transport accepts mail on the same machine only. No external host or address comes from the caller.
		$values=array('smtp_delivery'=>1,'smtp_host'=>'127.0.0.1','smtp_port'=>$port,'smtp_username'=>'','smtp_password'=>'','email_package_size'=>0,
			'board_email'=>'download-board@example.invalid','board_contact'=>'download-board@example.invalid','email_enable'=>1,'flood_interval'=>2);
		$old=array();
		foreach ($values as $key=>$value) { $old[$key]=$config[$key]; }
		$old['default_lang']=$config['default_lang'];
		$subjects=$rows('SELECT user_id,user_emailtime FROM ' . USERS_TABLE . " WHERE username IN ('HistoricMember','DepartingFixture')");
		$cats=$rows("SELECT cat_id,access_levels FROM {$p}categories WHERE cat_id IN (21,23,25)");
		$insert($p.'settings',array('config_name'=>'test_download_mail','config_value'=>json_encode(array($old,$subjects,$cats))));
		$db->sql_query("UPDATE {$p}settings SET config_value='1' WHERE config_name='massmail_protection'");
		foreach ($values as $key=>$value) { $config->set($key,$value); }
		foreach ($subjects as $person) { $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=0 WHERE user_id=' . (int) $person['user_id']); }
		$set_level(21,1); $set_level(23,0); $set_level(25,0);
		$file=$rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$insert($p.'files',array('file_id'=>11050,'cat_id'=>25,'title'=>'Guest recommendation')+$file);
		break;
	case 'english': $config->set('default_lang','en'); break;
	case 'board_interval':
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name='massmail_protection'");
		$config->set('flood_interval',600);
		break;
	case 'invalid':
		$db->sql_query("UPDATE {$p}settings SET config_value='' WHERE config_name='massmail_time'");
		break;
	case 'minimum':
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name='massmail_time'");
		$db->sql_query("UPDATE {$p}settings SET config_value='1' WHERE config_name='massmail_protection'");
		$now=time()-10;
		$db->sql_query("UPDATE {$p}mail_receipts SET attempted_at=$now,finished_at=$now WHERE status=1");
		break;
	case 'elapsed':
		$config->set('flood_interval',2);
		$db->sql_query("UPDATE {$p}mail_receipts SET attempted_at=attempted_at-5,finished_at=finished_at-5 WHERE status=1");
		break;
	case 'revoked': $set_level(21,5); break;
	case 'disabled': $config->set('email_enable',0); break;
	case 'restore':
		$settings=$rows("SELECT config_value FROM {$p}settings WHERE config_name='test_download_mail'")[0];
		list($old,$subjects,$cats)=json_decode($settings['config_value'],true);
		foreach ($old as $key=>$value) { $config->set($key,$value); }
		foreach ($subjects as $person)
		{ $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . (int) $person['user_emailtime'] . ' WHERE user_id=' . (int) $person['user_id']); }
		foreach ($cats as $cat)
		{ $db->sql_query("UPDATE {$p}categories SET " . $db->sql_build_array('UPDATE',array('access_levels'=>$cat['access_levels'])) . ' WHERE cat_id=' . (int) $cat['cat_id']); }
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=11050");
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name='massmail_protection'");
		$db->sql_query("UPDATE {$p}settings SET config_value='37' WHERE config_name='massmail_time'");
		$db->sql_query("DELETE FROM {$p}settings WHERE config_name='test_download_mail'");
		break;
	case 'outcome':
		$statuses=array_column($rows("SELECT status,COUNT(*) AS n FROM {$p}mail_receipts GROUP BY status"),'n','status');
		if ((int) ($statuses[1] ?? 0)!==3 || (int) ($statuses[2] ?? 0)!==1 || isset($statuses[0])) { throw new RuntimeException('Unexpected mail reservation outcomes'); }
		$db->sql_query("UPDATE {$p}mail_receipts SET attempted_at=1");
		$phpbb_container->get('phpbb2plus.downloads.recommendations')->expire();
		if ($rows("SELECT receipt FROM {$p}mail_receipts")) { throw new RuntimeException('Expired mail reservations survived session garbage collection'); }
		break;
	default: exit(2);
}
$done=true;
