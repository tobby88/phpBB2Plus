<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db_test = new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],
	(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
$stage = $argv[2];
if ($stage === 'source')
{
	$db_test->query("UPDATE phpbb_plus SET config_value='1' WHERE config_name='show_links'");
	$db_test->query("UPDATE phpbb_link_config SET config_value=CASE config_name WHEN 'display_logo_num' THEN '2'
		WHEN 'display_interval' THEN '400' WHEN 'width' THEN '86' WHEN 'height' THEN '28' END
		WHERE config_name IN ('display_logo_num','display_interval','width','height')");
	return;
}
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$table = $table_prefix . 'plus_wl_entries';
$state = $phpbb_container->get('config_text');
$key = 'plus_wl_test_rotation';
if ($stage === 'prepare')
{
	foreach (array('index_logos'=>1,'display_logo_num'=>2,'display_interval'=>400,'width'=>86,'height'=>28) as $name=>$value)
	{
		if ((int) $config['plus_wl_' . $name] !== $value) { throw new RuntimeException('Unconverted logo setting: ' . $name); }
	}
	$saved = array('display'=>$config['plus_wl_display_links_logo'],'rows'=>$db_test->query(
		"SELECT link_id,local_logo,destination,status,hits FROM $table WHERE link_id IN (2,3,4,4111,4112)")->fetch_all(MYSQLI_ASSOC));
	$state->set($key,json_encode($saved));
	// The directory list and the forum index had independent display switches.
	$config->set('plus_wl_display_links_logo',0);
	$db_test->query("UPDATE $table SET status=2 WHERE link_id=2");
	$db_test->query("UPDATE $table SET destination='' WHERE link_id=3");
	$db_test->query("UPDATE $table SET local_logo='unavailable' WHERE link_id=4");
}
elseif ($stage === 'local-images')
{
	// The real Chromium fixture gets an offline image for every visible website.
	$db_test->query("UPDATE $table dest JOIN $table src ON src.link_id=1 SET dest.local_logo=src.local_logo WHERE dest.link_id=4112");
}
elseif ($stage === 'withdraw') { $db_test->query("UPDATE $table SET status=0 WHERE link_id=4111"); }
elseif ($stage === 'cleanup')
{
	$saved = json_decode($state->get($key),true);
	$config->set('plus_wl_display_links_logo',$saved['display']);
	$config->set('plus_wl_index_logos',1);
	$counted = false;
	foreach ($saved['rows'] as $row)
	{
		$id = (int) $row['link_id'];
		$actual = $db_test->query("SELECT hits FROM $table WHERE link_id=$id")->fetch_row();
		$counted = $counted || (int) $actual[0] !== (int) $row['hits'];
		unset($row['link_id'],$row['hits']);
		$db->sql_query("UPDATE $table SET " . $db->sql_build_array('UPDATE',$row) . " WHERE link_id=$id");
	}
	$state->delete($key);
	if ($counted) { throw new RuntimeException('Displaying a logo counted a link visit'); }
}
else { throw new RuntimeException('Unexpected logo fixture phase'); }
