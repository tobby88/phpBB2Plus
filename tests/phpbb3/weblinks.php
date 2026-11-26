<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
$stage = $argv[2];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],
	(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
$database->set_charset('utf8mb4');
$verify = static function($truth,$message) { if (!$truth) { throw new RuntimeException($message); } };
if ($stage === 'source')
{
	$database->query("UPDATE phpbb_links SET link_joined=1000 WHERE link_id BETWEEN 1 AND 5");
	$database->query("INSERT INTO phpbb_link_categories VALUES (711,'Verzeichnis &amp; Café',11),(712,'Leer',10)");
	$database->query("INSERT INTO phpbb_links VALUES
		(4111,'Zeitschrift &amp; Café','Früher einmal',711,'https://directory.example.invalid/old','images/links/phpBB_88a.gif',10010,1,2,1,'c0000201','c000020a'),
		(4112,'Stern ⭐','Exakt 100%_Beispiel',711,'https://directory.example.invalid/star','https://logo.example.invalid/source.png',10020,1,7,962341,'',''),
		(4113,'A-Katalog','100 weiterer Text',711,'https://directory.example.invalid/catalog','',10030,1,4,-1,'',''),
		(4114,'Alter Entwurf','Nicht öffentlich',711,'https://directory.example.invalid/pending','images/links/phpBB_88a.gif',10040,0,0,1,'','')");
	$database->query("UPDATE phpbb_link_config SET config_value='2' WHERE config_name='linkspp'");
	$database->query("UPDATE phpbb_link_config SET config_name='pm_notify ',config_value='1' WHERE config_name='pm_notify'");
	$database->query("INSERT INTO phpbb_link_config VALUES ('Altoption','Zeichen 🚀'),('site_logo','images/links/web_logo88a.gif'),('site_url','https://directory.example.invalid/')");
	$database->query('ALTER TABLE phpbb_links AUTO_INCREMENT=947750');
	return;
}
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$one = static function($sql) use($database) { return $database->query($sql)->fetch_assoc(); };
$wl = $table_prefix . 'plus_wl_entries';
$state = $phpbb_container->get('config_text');
$phase = explode(':',$stage,2);
if ($phase[0] === 'import')
{
	$r = $one("SELECT * FROM $wl WHERE link_id=4111");
	$verify($r['title'] === 'Zeitschrift &amp; Café' && $r['submitter_ip'] === '192.0.2.1' && $r['last_ip'] === '192.0.2.10'
		&& $r['local_logo'] && is_file($config['upload_path'] . '/' . $r['local_logo']),'Imported text, IP or offline logo did not survive');
	$verify((int) $config['plus_wl_linkspp']===2 && !$config['plus_wl_allow_no_logo'] && $config['plus_wl_inputs_valid'] &&
		strpos($state->get('plus_wl_source_settings'),'Altoption')!==false && $config['plus_wl_local_logo'],'Historical options did not survive');
	$verify((int) $one("SELECT user_id FROM $wl WHERE link_id=4112")['user_id']===ANONYMOUS,'Former link owner was assigned to a new account');
	$port = ctype_digit($phase[1] ?? '') ? (int) $phase[1] : 0;
	if ($port < 1024 || $port > 65535) { exit(2); }
	$settings = array('smtp_delivery'=>1,'smtp_host'=>'127.0.0.1','smtp_port'=>$port,'smtp_username'=>'','smtp_password'=>'',
		'email_package_size'=>0,'board_email'=>'weblinks@example.invalid','board_contact'=>'weblinks@example.invalid',
		'email_enable'=>1,'jab_enable'=>0,'allow_privmsg'=>1);
	$old = array();
	foreach ($settings as $key=>$value) { $old[$key]=$config[$key]; }
	$state->set('plus_wl_test_mail',json_encode($old));
	foreach ($settings as $key=>$value) { $config->set($key,$value); }
}
elseif ($stage === 'count') { $verify((int) $one("SELECT hits FROM $wl WHERE link_id=4111")['hits'] === 3,'Two visits from one browser were counted twice or HEAD counted'); }
elseif ($stage === 'submitted')
{
	$r = $one("SELECT * FROM $wl WHERE title='Local suggested site'");
	$verify($r && (int) $r['link_id']===947750 && (int) $r['status']===0 &&
		$r['destination']==='https://directory.example.invalid/submitted','Suggestion reused an old ID or became public before review');
	$verify((int) $one("SELECT COUNT(*) AS n FROM $wl WHERE title='Local suggested site'")['n']===1,'The submission interval allowed a duplicate');
	$pm = $one('SELECT COUNT(*) AS n FROM ' . PRIVMSGS_TABLE . ' m JOIN ' . PRIVMSGS_TO_TABLE .
		" t ON t.msg_id=m.msg_id AND t.user_id=2 WHERE m.message_subject='Website wartet auf Freigabe' AND m.message_text LIKE '%Local suggested site%'");
	$verify((int) $pm['n'] === 1,'The reviewer did not receive a single native PM');
}
elseif ($stage === 'lock') { $config->set('plus_wl_lock_submit_site',1); }
elseif ($stage === 'cleanup')
{
	$database->query("DELETE FROM $wl WHERE title IN ('Local suggested site','Local published site') AND link_id>=947750");
	$database->query("UPDATE $wl SET hits=2,last_ip='192.0.2.10' WHERE link_id=4111");
	$config->set('plus_wl_lock_submit_site',0);
	include_once './includes/functions_privmsgs.php';
	$messages = $database->query('SELECT t.user_id,t.msg_id,t.folder_id FROM ' . PRIVMSGS_TO_TABLE . ' t JOIN ' .
		PRIVMSGS_TABLE . " m ON m.msg_id=t.msg_id WHERE m.message_subject IN ('Website wartet auf Freigabe','Website awaiting review')
		AND m.message_text LIKE '%Local suggested site%'")->fetch_all(MYSQLI_ASSOC);
	$user->data += array('user_new_privmsg'=>0,'user_unread_privmsg'=>0);
	foreach ($messages as $message) { delete_pm((int) $message['user_id'],(int) $message['msg_id'],(int) $message['folder_id']); }
	foreach (json_decode($state->get('plus_wl_test_mail'),true) as $key=>$value) { $config->set($key,$value); }
	$state->delete('plus_wl_test_mail');
	// A failed browser assertion may be retried against this disposable converted board.
	$database->query("ALTER TABLE $wl AUTO_INCREMENT=947750");
}
else { throw new RuntimeException('Unexpected link directory fixture phase'); }
