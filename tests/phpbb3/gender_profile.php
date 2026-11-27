<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source' || $argv[2] === 'source-off')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$s = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	if ($argv[2] === 'source-off') { $s->query('ALTER TABLE phpbb_users DROP COLUMN user_gender'); return; }
	$s->query('UPDATE phpbb_users SET user_gender=CASE user_id WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 87 THEN 7 ELSE 0 END');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
use phpbb2plus\community\profile\gender;
$rows = static function ($sql) use ($db) { $r=$db->sql_query($sql); $rows=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows; };
$expect = static function ($actual, $message) { if (!$actual) { throw new RuntimeException($message); } };
$field=gender::catalogue($db, $config);
$expect($field && ($argv[2]==='restore' || $field['values']==array(1=>2,2=>3)) && $field['none'] === 1, 'Missing native gender options');
list($id, $ident)=gender::owner($config);
$col='pf_' . $ident;
$key='test_plus_gender_profile';
$text=$phpbb_container->get('config_text');
$settings=array('enable_confirm'=>0,'require_activation'=>0,'email_enable'=>0,'coppa_enable'=>0,'check_dnsbl'=>0,'email_check_mx'=>0);
$accounts=static function () use ($rows,$col) {
	return $rows("SELECT u.user_id,u.username,d.$col AS choice FROM " . USERS_TABLE . ' u JOIN ' . PROFILE_FIELDS_DATA_TABLE .
		" d ON u.user_id=d.user_id WHERE u.username IN ('ModernMember','HistoricMember','DepartingFixture','GenderSignupFixture')");
};
switch ($argv[2])
{
	case 'fresh':
	case 'missing':
		$expect(!$rows('SELECT user_id FROM ' . PROFILE_FIELDS_DATA_TABLE . " WHERE $col<>1"), 'An existing board acquired an inferred profile value');
		if ($argv[2] === 'missing') { break; }
		// Simulate an upgrade with an independently owned field and another orphan data column.
		$tools=$phpbb_container->get('dbal.tools');
		$stored=$config[gender::SETTING];
		$before=$rows('SELECT * FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_id=$id");
		$expect((int)$before[0]['field_show_on_reg'] && (int)$before[0]['field_show_profile'] && (int)$before[0]['field_show_on_vt'] &&
			!(int)$before[0]['field_required'] && !(int)$before[0]['field_show_on_ml'] && !(int)$before[0]['field_show_on_pm'] &&
			!(int)$before[0]['field_is_contact'], 'The field visibility or its optional registration changed');
		$labels=$rows('SELECT * FROM ' . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$id ORDER BY lang_id,option_id");
		$tools->sql_column_add(PROFILE_FIELDS_DATA_TABLE, 'pf_plus_gender_2', array('VCHAR:20','preserved'));
		$config->set(gender::SETTING, '');
		$migration=new \phpbb2plus\community\migrations\v176_gender_profile($config,$db,$tools,'./','php',$table_prefix);
		$migration->install_field(); $migration->install_field();
		list($other, $other_ident)=gender::owner($config);
		$expect($other !== $id && $other_ident === 'plus_gender_3' && $tools->sql_column_exists(PROFILE_FIELDS_DATA_TABLE, 'pf_plus_gender_3') &&
			$rows('SELECT * FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_id=$id") === $before &&
			$rows('SELECT * FROM ' . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$id ORDER BY lang_id,option_id") === $labels, 'A collision replaced existing profile data or a field definition');
		foreach (array(PROFILE_FIELDS_LANG_TABLE,PROFILE_LANG_TABLE,PROFILE_FIELDS_TABLE) as $table) { $db->sql_query("DELETE FROM $table WHERE field_id=$other"); }
		$tools->sql_column_remove(PROFILE_FIELDS_DATA_TABLE,'pf_plus_gender_3'); $tools->sql_column_remove(PROFILE_FIELDS_DATA_TABLE,'pf_plus_gender_2');
		$config->set(gender::SETTING,$stored);
		break;
	case 'prepare':
		$people=array_column($accounts(),null,'username');
		$expect(count($people)===3 && (int)$people['ModernMember']['choice']===2 && (int)$people['HistoricMember']['choice']===3 &&
			(int)$people['DepartingFixture']['choice']===1 && $text->get($key)===null, 'Source identity, invalid gender or converted no-value changed');
		$state=array();
		foreach (array_merge(array_keys($settings), array('num_users','newest_user_id','newest_username','newest_user_colour')) as $name) { $state[$name]=$config[$name]; }
		foreach ($settings as $name=>$value) { $config->set($name,$value); }
		$snapshot=$rows('SELECT * FROM ' . PROFILE_FIELDS_DATA_TABLE . ' WHERE user_id=2')[0];
		unset($snapshot['user_id']);
		$flags=$rows('SELECT field_show_on_vt FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_id=$id")[0];
		$text->set($key,json_encode(array('settings'=>$state,'profile'=>$snapshot,'flags'=>$flags)));
		echo json_encode(array('ident'=>$ident,'historical'=>(int)$people['HistoricMember']['user_id']));
		break;
	case 'hide-author':
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_show_on_vt=0 WHERE field_id=$id");
		break;
	case 'show-author':
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_show_on_vt=1 WHERE field_id=$id");
		break;
	case 'relabel':
		$r=$rows('SELECT lang_id FROM ' . LANG_TABLE . " WHERE lang_iso='en'");
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_LANG_TABLE . " SET lang_value='Custom choice' WHERE field_id=$id AND option_id=1 AND lang_id=" . (int)$r[0]['lang_id']);
		$catalogue=gender::catalogue($db,$config);
		$expect(empty($catalogue['values'][1]) && $catalogue['values'][2]===3, 'A repurposed option retained its original icon');
		try { gender::imported_value('1',$catalogue); }
		catch (RuntimeException $e) { break; }
		throw new RuntimeException('A historical choice could be written to a repurposed native option');
	case 'registered':
		$people=array_column($accounts(),null,'username');
		$expect((int)($people['GenderSignupFixture']['choice']??0)===3, 'Native registration did not store the voluntary choice');
		break;
	case 'restore':
		// Restore the shared synthetic member and registration environment for later suites.
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_LANG_TABLE . " SET lang_value='Male' WHERE field_id=$id AND option_id=1 AND lang_value='Custom choice'");
		$state=json_decode($text->get($key),true);
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',$state['flags']) . " WHERE field_id=$id");
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_DATA_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $state['profile']) . ' WHERE user_id=2');
		$people=array_column($accounts(),null,'username');
		if (isset($people['GenderSignupFixture'])) { require_once './includes/functions_user.php'; user_delete('retain',(int)$people['GenderSignupFixture']['user_id']); }
		foreach ($state['settings'] as $name=>$value) { $config->set($name,$value); }
		$text->delete($key);
		break;
	default: exit(2);
}
