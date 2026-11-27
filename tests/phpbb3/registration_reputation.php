<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
if ($argv[2] === 'source') {
	$conn = new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],(int)getenv('PLUS_TEST_DB_PORT'));
	$conn->query("UPDATE phpbb_config SET config_value='1' WHERE config_name='sfs_fail_closed'");
	return;
}
define('IN_PHPBB',true);
$phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$storage = $phpbb_container->get('config_text'); $key = 'test_plus_sfs_saved';
if ($argv[2] === 'setup') {
	if (get_class($phpbb_container->get('phpbb2plus.community.registration_reputation_http')) !== 'phpbb2plus\community\service\fixture_reputation_http' ||
		(string)$config['plus_sfs_enable'] !== '0' || (string)$config['plus_sfs_fail_closed'] !== '1' || $storage->get($key) !== null) {
		throw new RuntimeException('Use the converted synthetic settings and the offline transport'); }
	$policy=array('require_activation'=>USER_ACTIVATION_NONE,'email_enable'=>0,'enable_confirm'=>0,'coppa_enable'=>0,'check_dnsbl'=>0,'email_check_mx'=>0);
	$saved=array(); foreach (array_merge(array_keys($policy),array('plus_sfs_enable','plus_sfs_fail_closed','num_users','newest_user_id','newest_username','newest_user_colour')) as $name) { $saved[$name]=$config[$name]; }
	$storage->set($key,json_encode($saved));
	foreach ($policy as $name=>$value) { $config->set($name,$value); }
} elseif ($argv[2] === 'restore') {
	$saved=json_decode((string)$storage->get($key),true);
	if (!$saved) { throw new RuntimeException('Missing disposable registration settings'); }
	$ids=array();
	$r=$db->sql_query('SELECT user_id FROM '.USERS_TABLE.' WHERE '.$db->sql_in_set('username',array('SfsTestClean','SfsTestDown','SfsTestListed','SfsTestLocal','SfsTestOff')));
	while ($row=$db->sql_fetchrow($r)) { $ids[]=(int)$row['user_id']; } $db->sql_freeresult($r);
	if ($ids) { require_once './includes/functions_user.php'; user_delete('retain',$ids); }
	foreach ($saved as $name=>$value) { $config->set($name,$value); }
	$storage->delete($key);
} else { exit(2); }
