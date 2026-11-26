<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$rows=static function($sql) use ($db) { $r=$db->sql_query($sql); $result=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $result; };
$check=static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$text=$phpbb_container->get('config_text'); $key='plus_away_signup_fixture'; $notes=$table_prefix . 'plus_away_notes';
$people=$rows('SELECT u.user_id,u.username,u.user_type,u.plus_away,u.plus_away_mode,u.plus_away_revision,n.note_text FROM ' . USERS_TABLE .
	" u LEFT JOIN $notes n ON n.user_id=u.user_id WHERE u.username IN ('AwaySignupTest','AwaySignupClosed') ORDER BY u.username");
$saved=json_decode((string)$text->get($key),true);
switch ($argv[2])
{
	case 'seed':
		$check(!$saved && !$people,'Disposable registration names already exist');
		$policy=array('plus_away_members'=>0,'require_activation'=>USER_ACTIVATION_NONE,'email_enable'=>0,'enable_confirm'=>0,
			'coppa_enable'=>0,'check_dnsbl'=>0,'email_check_mx'=>0);
		$saved=array();
		foreach (array_merge(array_keys($policy),array('num_users','newest_user_id','newest_username','newest_user_colour')) as $k) { $saved[$k]=$config[$k]; }
		$text->set($key,json_encode($saved)); foreach ($policy as $k=>$v) { $config->set($k,$v); }
		break;
	case 'on': $config->set('plus_away_members',1); break;
	case 'off': $config->set('plus_away_members',0); break;
	case 'invalid': $check(!$people,'Invalid absence data created a partial registration'); break;
	case 'outcome':
		$check(count($people)===2 && $people[0]['username']==='AwaySignupClosed' && $people[1]['username']==='AwaySignupTest','Missing completed native registration');
		list($closed,$away)=$people;
		$check(!(int)$closed['plus_away'] && !(int)$closed['plus_away_mode'] && $closed['plus_away_revision']==='' && $closed['note_text']===null,
			'An older registration form set an absence after the board stopped accepting it');
		$check((int)$away['user_type']===USER_NORMAL && (int)$away['plus_away']===1 && (int)$away['plus_away_mode']===3 &&
			strlen($away['plus_away_revision'])===32 && json_decode($away['note_text'],true)==="Natur & <Berge> 🎨\n0",'Registration lost the native account, status or exact personal note');
		break;
	case 'restore':
		$check($saved,'Missing registration fixture');
		if ($people)
		{
			require_once './includes/functions_user.php';
			$ids=array_map('intval',array_column($people,'user_id'));
			user_delete('retain',$ids);
			$check(!$rows("SELECT user_id FROM $notes WHERE " . $db->sql_in_set('user_id',$ids)),'Removing a registered account left its personal note behind');
		}
		foreach ($saved as $k=>$v) { $config->set($k,$v); }
		$text->delete($key); break;
	default: exit(2);
}
