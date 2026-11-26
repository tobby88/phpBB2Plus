<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source=new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],(int)(getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$at=time()-900;
	$success=$at-90;
	$hash=hash('sha256',"mail-user\0user:1");
	$source->query("UPDATE phpbb_users SET user_emailtime=$at WHERE user_id=1 AND username='HistoricMember'");
	if ($source->affected_rows!==1) { throw new RuntimeException('Missing original registered mail sender'); }
	$source->query("INSERT INTO phpbb_ctracker_rate_limits (bucket_hash,window_start,request_count,updated_at) VALUES ('$hash',$at,1,$success)");
	$source->query('UPDATE phpbb_forums SET auth_post=5 WHERE forum_id IN (9,10)');
	return;
}
define('IN_PHPBB',true);
$phpbb_root_path='./';
$phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
$rows=static function ($sql) use ($db) {
	$r=$db->sql_query($sql);
	$data=$db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $data;
};
$check=static function ($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$text=$phpbb_container->get('config_text');
$key='plus_friend_test_state';
$state=json_decode((string)$text->get($key),true);
$table=$table_prefix . 'plus_friend_mail';
$downloads=$table_prefix . 'plus_dl_';
$person=$rows('SELECT user_id,user_emailtime,plus_mail_success FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0];
$id=(int)$person['user_id'];
$writer=new auth_admin();
$args=explode(':',$argv[2]);
switch ($args[0])
{
	case 'converted':
		$check((int)$person['user_emailtime']>0 && (int)$person['user_emailtime']-(int)$person['plus_mail_success']===90,
			'The original account attempt and its separate successful-send timestamp did not migrate to the same member');
		$check(rtrim($config['plus_friend_source_board'],'/')==='http://www.myserver.tld/phpBB2','The original board origin for saved topic recommendations changed');
		$check((int)$config['plus_friend_read_acl']===1,'The source conversion did not enable combined reader rights for recommendations');
		break;
	case 'seed':
		$check(!$state && count($args)===3 && ctype_digit($args[1]) && ctype_digit($args[2]),'Invalid disposable mail setup');
		foreach (array($args[1],$args[2]) as $port) { $check((int)$port>=1024 && (int)$port<=65535,'Invalid loopback port'); }
		$check((string)$config['plus_friend_protection']==='0' && (string)$config['plus_friend_minutes']==='37','Original CrackerTracker mail policy was not converted');
		require_once __DIR__ . '/../../phpBB3/install/convertors/plus_account_logins.php';
		foreach (array(array('1','20',1,20),array('0','0',0,1),array('1','999',1,180),array('1','',2,20),array('X','5',2,20)) as $case)
		{
			$check(plus_friend_policy(array('massmail_protection'=>$case[0],'massmail_time'=>$case[1]))===array($case[2],$case[3]),
				'Source mail limits or incomplete settings were silently relaxed');
		}
		$current=array('smtp_delivery'=>1,'smtp_host'=>'127.0.0.1','smtp_port'=>$args[1],'smtp_username'=>'','smtp_password'=>'',
			'email_package_size'=>0,'board_contact'=>'recommend-team@example.invalid','board_email'=>'recommend-board@example.invalid',
			'server_protocol'=>'http://','server_name'=>'127.0.0.1','server_port'=>$args[2],'script_path'=>'/','email_enable'=>1,
			'board_email_form'=>0,'flood_interval'=>1,'plus_friend_protection'=>1,'plus_friend_minutes'=>3,'plus_friend_source_board'=>'https://old.example.invalid/archive',
			'plus_friend_read_acl'=>(int)$config['plus_friend_read_acl']);
		$original=array();
		foreach ($current as $k=>$v) { $original[$k]=$config[$k]; }
		$direct=array();
		foreach (array('u_sendemail'=>0,'f_email'=>1) as $name=>$forum)
		{
			$r=$rows('SELECT a.auth_setting FROM ' . ACL_USERS_TABLE . ' a JOIN ' . ACL_OPTIONS_TABLE . " o ON o.auth_option_id=a.auth_option_id
				WHERE a.user_id=$id AND a.forum_id=$forum AND o.auth_option='$name'");
			$direct[$name]=(int)($r[0]['auth_setting'] ?? ACL_NO);
		}
		$cat=$rows("SELECT access_levels FROM {$downloads}categories WHERE cat_id=21")[0]['access_levels'];
		$combined=$rows('SELECT a.auth_setting FROM ' . ACL_USERS_TABLE . ' a JOIN ' . ACL_OPTIONS_TABLE . " o ON o.auth_option_id=a.auth_option_id
			WHERE a.user_id=$id AND a.forum_id=10 AND o.auth_option='f_email'");
		$dl_policy=$rows("SELECT config_value FROM {$downloads}settings WHERE config_name='massmail_protection'")[0]['config_value'];
		$password=$rows('SELECT forum_password FROM ' . FORUMS_TABLE . ' WHERE forum_id=1')[0]['forum_password'];
		$state=array('config'=>$original,'user'=>$person,'acl'=>$direct,'password'=>$password,'receipts'=>$rows("SELECT receipt FROM $table ORDER BY receipt"),
			'combined'=>(int)($combined[0]['auth_setting'] ?? ACL_NO),'dl_cat'=>$cat,'dl_policy'=>$dl_policy,'dl_receipts'=>$rows("SELECT receipt FROM {$downloads}mail_receipts"));
		$text->set($key,json_encode($state));
		foreach ($current as $k=>$v) { $config->set($k,$v); }
		$levels=json_decode($cat,true);
		$levels['email']=1;
		$db->sql_query("UPDATE {$downloads}categories SET " . $db->sql_build_array('UPDATE',array('access_levels'=>json_encode($levels))) . ' WHERE cat_id=21');
		$db->sql_query("UPDATE {$downloads}settings SET config_value='1' WHERE config_name='massmail_protection'");
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=0,plus_mail_success=0 WHERE user_id=$id");
		$writer->acl_set('user',0,$id,array('u_sendemail'=>ACL_YES));
		$writer->acl_set('user',1,$id,array('f_email'=>ACL_YES));
		break;
	case 'upgrade':
		// Exercise the native migrator on the prior board schema, then restore the source-specific policy.
		$mt=$table_prefix . 'migrations';
		$matches=$rows("SELECT migration_name FROM $mt WHERE migration_name LIKE '%community%v153_topic_recommendations'");
		$check(count($matches)===1 && !$state['receipts'],'Unexpected disposable migration state');
		$name=$matches[0]['migration_name'];
		$installed="SELECT migration_name FROM $mt WHERE migration_name='" . $db->sql_escape($name) . "'";
		$m=$phpbb_container->get('migrator');
		$writer->acl_set('user',10,$id,array('f_email'=>ACL_NEVER));
		$m->load_migration_state();
		for ($i=0;$i<40 && $rows($installed);$i++) { $m->revert($name); }
		$tools=$phpbb_container->get('dbal.tools');
		$check(!$tools->sql_table_exists($table) && !$tools->sql_column_exists(USERS_TABLE,'plus_mail_success'),'Incomplete historical schema fixture');
		$recent=time()-30;
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=$recent WHERE user_id=$id");
		$m->load_migration_state();
		$m->set_migrations(array('\phpbb2plus\community\migrations\v153_topic_recommendations'));
		for ($i=0;$i<40 && !$m->finished();$i++) { $m->update(); }
		$after=$rows('SELECT user_emailtime,plus_mail_success FROM ' . USERS_TABLE . " WHERE user_id=$id")[0];
		$explicit=$rows('SELECT a.auth_setting FROM ' . ACL_USERS_TABLE . ' a JOIN ' . ACL_OPTIONS_TABLE . " o ON o.auth_option_id=a.auth_option_id
			WHERE a.user_id=$id AND a.forum_id=10 AND o.auth_option='f_email'");
		$check((int)$config['plus_friend_read_acl']===0 && (int)$explicit[0]['auth_setting']===ACL_NEVER,'The native upgrade overrode an existing forum email restriction');
		$check($m->finished() && $tools->sql_table_exists($table) && (int)$after['plus_mail_success']===$recent &&
			(int)$after['user_emailtime']===$recent && (int)$config['plus_friend_minutes']===20,'A previous native board lost its currently active sending interval');
		$config->set('plus_friend_protection',1);
		$config->set('plus_friend_minutes',3);
		$config->set('plus_friend_source_board','https://old.example.invalid/archive');
		$config->set('plus_friend_read_acl',$state['config']['plus_friend_read_acl']);
		$writer->acl_set('user',10,$id,array('f_email'=>$state['combined']));
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=0,plus_mail_success=0 WHERE user_id=$id");
		break;
	case 'after_attempt':
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=0 WHERE user_id=$id");
		break;
	case 'another':
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=0,plus_mail_success=0 WHERE user_id=$id");
		break;
	case 'deny_forum': $writer->acl_set('user',1,$id,array('f_email'=>ACL_NEVER)); break;
	case 'allow_forum': $writer->acl_set('user',1,$id,array('f_email'=>ACL_YES)); break;
	case 'deny_combined': $writer->acl_set('user',10,$id,array('f_email'=>ACL_NEVER)); break;
	case 'allow_combined': $writer->acl_set('user',10,$id,array('f_email'=>$state['combined'])); break;
	case 'lock_forum':
	case 'unlock_forum':
		$password=$args[0]==='lock_forum' ? $phpbb_container->get('passwords.manager')->hash('Disposable test forum') : $state['password'];
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',array('forum_password'=>$password)) . ' WHERE forum_id=1');
		break;
	case 'deny_user': $writer->acl_set('user',0,$id,array('u_sendemail'=>ACL_NEVER)); break;
	case 'allow_user': $writer->acl_set('user',0,$id,array('u_sendemail'=>ACL_YES)); break;
	case 'disable': $config->set('plus_friend_protection',0); $config->set('email_enable',0); break;
	case 'outcome':
		$total=$rows("SELECT status,COUNT(*) AS n FROM $table GROUP BY status ORDER BY status");
		$check(array_map('intval',array_column($total,'status'))===array(1,2) && array_map('intval',array_column($total,'n'))===array(2,1),'Unexpected recommendation receipt outcomes');
		break;
	case 'restore':
		$check($state,'Missing recommendation fixture state');
		foreach ($state['config'] as $k=>$v) { $config->set($k,$v); }
		$writer->acl_set('user',0,$id,array('u_sendemail'=>$state['acl']['u_sendemail']));
		$writer->acl_set('user',1,$id,array('f_email'=>$state['acl']['f_email']));
		$writer->acl_set('user',10,$id,array('f_email'=>$state['combined']));
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',array('forum_password'=>$state['password'])) . ' WHERE forum_id=1');
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . (int)$state['user']['user_emailtime'] .
			',plus_mail_success=' . (int)$state['user']['plus_mail_success'] . " WHERE user_id=$id");
		$db->sql_query("DELETE FROM $table" . ($state['receipts'] ? ' WHERE ' . $db->sql_in_set('receipt',array_column($state['receipts'],'receipt'),true) : ''));
		$db->sql_query("UPDATE {$downloads}categories SET " . $db->sql_build_array('UPDATE',array('access_levels'=>$state['dl_cat'])) . ' WHERE cat_id=21');
		$db->sql_query("UPDATE {$downloads}settings SET " . $db->sql_build_array('UPDATE',array('config_value'=>$state['dl_policy'])) . " WHERE config_name='massmail_protection'");
		$db->sql_query("DELETE FROM {$downloads}mail_receipts" . ($state['dl_receipts'] ? ' WHERE ' . $db->sql_in_set('receipt',array_column($state['dl_receipts'],'receipt'),true) : ''));
		$text->delete($key);
		break;
	default: exit(2);
}
