<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$notice = str_repeat('Führung & Bau <2027> ', 9) . '🎓';
if ($argv[2] === 'source-closed')
{
	require __DIR__ . '/fixture_database.php';
	$source = plus_fixture_database($argv[1]);
	$source->query("UPDATE phpbb_config SET config_value='1' WHERE config_name='registration_status'");
	$source->query("UPDATE phpbb_config SET config_value='2' WHERE config_name='require_activation'");
	$source->query("UPDATE phpbb_config SET config_value=UNHEX('" . bin2hex($notice) . "') WHERE config_name='registration_closed'");
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$state = $phpbb_container->get('config_text');
$key = 'plus_test_registration_policy';
$phase = $argv[2];
if ($phase === 'converted-closed')
{
	if ((int) $config['require_activation'] !== USER_ACTIVATION_DISABLE || !(int) $config['enable_confirm'])
	{ throw new RuntimeException('The complete converter did not preserve the source registration policy'); }
	if (json_decode((string) $state->get('plus_registration_notice'), true) !== $notice)
	{ throw new RuntimeException('The complete converter did not preserve the original UTF-8 reason for closing registration'); }
	return;
}
if ($phase === 'setup')
{
	if ($state->get($key) !== null) { throw new RuntimeException('Registration policy fixture already active'); }
	$old = array();
	foreach (array('enable_confirm','require_activation','email_enable','coppa_enable','captcha_plugin') as $name) { $old[$name] = $config[$name]; }
	$state->set($key, json_encode(array('config'=>$old, 'notice'=>$state->get('plus_registration_notice'))));
	$config->set('captcha_plugin','core.captcha.plugins.nogd');
	$config->set('email_enable',1);
	$config->set('coppa_enable',0);
	return;
}
if ($phase === 'restore')
{
	$old = json_decode($state->get($key), true);
	foreach ($old['config'] as $name => $value) { $config->set($name, $value); }
	$state->set('plus_registration_notice', $old['notice']);
	$state->delete($key);
	return;
}
if ($state->get($key) === null) { throw new RuntimeException('Expected a saved registration policy'); }
if ($phase === 'notice-source' || $phase === 'notice-resume')
{
	require dirname(__DIR__, 2) . '/phpBB3/install/convertors/plus_native.php';
	$src_db = $db;
	$convert = (object) array('src_table_prefix'=>'test_plus_reg_policy_');
	$db->sql_query('CREATE TEMPORARY TABLE test_plus_reg_policy_config (config_name VARCHAR(80) PRIMARY KEY, config_value VARCHAR(255)) CHARACTER SET utf8mb4');
	if ($phase === 'notice-source') { $state->set('plus_registration_notice', ''); }
	$db->sql_query("INSERT INTO test_plus_reg_policy_config VALUES ('registration_closed',UNHEX('" . bin2hex($notice) . "'))");
	plus_import_registration_notice();
	return;
}
if (!preg_match('/^policy-([01x])([01x])-([01x])([0123x])$/D', $phase, $parts)) { exit(2); }
list(, $native, $anti, $closed, $activation) = $parts;

// Exercise phpBB's actual config mapper on a disposable source within this CLI connection.
require './includes/functions_convert.php';
require dirname(__DIR__, 2) . '/phpBB3/install/convertors/plus_native.php';
$prefix = 'test_plus_reg_policy_';
$src_db = $db;
$same_db = true;
$schema = array('table_name'=>'config', 'table_format'=>array('config_name'=>'config_value'), 'settings'=>array(
	'enable_confirm'=>'plus_registration_confirmation(enable_confirm)',
	'require_activation'=>'plus_registration_activation(require_activation)'));
$convert = (object) array('src_table_prefix'=>$prefix, 'mysql_convert'=>false, 'config_schema'=>$schema);
foreach (array('config','plus') as $table)
{
	$db->sql_query('CREATE TEMPORARY TABLE ' . $prefix . $table . ' (config_name VARCHAR(80) PRIMARY KEY, config_value VARCHAR(30))');
}
foreach (array('fixture'=>'1','enable_confirm'=>$native,'registration_status'=>$closed,'require_activation'=>$activation,
	'enable_antirobot'=>$anti) as $name => $value)
{
	if ($value === 'x') { continue; }
	$table = $prefix . ($name === 'enable_antirobot' ? 'plus' : 'config');
	$db->sql_query('INSERT INTO ' . $table . ' ' . $db->sql_build_array('INSERT', array('config_name'=>$name, 'config_value'=>$value)));
}
restore_config($schema);
