<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$fallback = PHP_VERSION_ID >= 80000 ? 1 : 0;
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->query("UPDATE phpbb_ctracker_config SET ct_config_value='0' WHERE ct_config_name='vconfirm_guest'");
	$source->query("UPDATE phpbb_plus SET config_value='$fallback' WHERE config_name='enable_confirm_post'");
	return;
}
if ($argv[2] !== 'converted') { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require dirname(__DIR__, 2) . '/phpBB3/install/convertors/plus_native.php';
foreach (array(array(true,'1','0',1), array(true,'1','1',1), array(true,'0','1',1), array(true,'0','0',0),
	array(true,false,false,1), array(true,'00',false,1), array(false,false,'1',1), array(false,false,'0',0),
	array(false,false,false,null)) as list($installed,$tracker,$plus,$expected))
{
	if (plus_guest_post_confirmation($installed, $tracker, $plus) !== $expected)
	{ throw new RuntimeException('The effective guest challenge changed for a source configuration'); }
}
if (empty($config['plus_guest_confirm_imported']) || (int) $config['enable_post_confirm'] !== $fallback)
{ throw new RuntimeException('The native guest-post setting did not match the old fallback'); }
try
{
	$config->set('enable_post_confirm', 1 - $fallback);
	plus_import_guest_confirmation();
	if ((int) $config['enable_post_confirm'] !== 1 - $fallback) { throw new RuntimeException('A resumed conversion replaced a later native choice'); }
}
finally { $config->set('enable_post_confirm', $fallback); }
echo $fallback;
