<?php
// Inspect only a disposable native board created by the integration runner.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function verify_duration($id,$expected)
{
	global $db;
	$r = $db->sql_query('SELECT topic_type,topic_time_limit,plus_announce_days FROM ' . TOPICS_TABLE . " WHERE topic_id=$id AND forum_id=7");
	$row = $db->sql_fetchrow($r); $db->sql_freeresult($r);
	if (!$row || array_map('intval',array_values($row)) !== $expected) { throw new RuntimeException("Incorrect duration state for topic $id"); }
}
$cron = $phpbb_container->get('phpbb2plus.community.announcement_cron');
switch ($argv[2])
{
	case 'paused':
		if ((int) $config['plus_announce_default'] !== 0 || (int) $config['plus_announce_sticky'] !== 1 || $cron->should_run()) { throw new RuntimeException('Source expiry settings lost'); }
		$cron->run();
		verify_duration(701,array(POST_ANNOUNCE,259200,0));
		verify_duration(702,array(POST_GLOBAL,0,-1));
		verify_duration(703,array(POST_ANNOUNCE,0,0));
		verify_duration(704,array(POST_ANNOUNCE,0,80000));
		verify_duration(706,array(POST_STICKY,0,0));
		break;
	case 'resume':
		verify_duration(701,array(POST_ANNOUNCE,259200,0));
		$config->set('plus_announce_default',7);
		if (!$cron->should_run()) { throw new RuntimeException('Resuming expiry did not schedule a sweep'); }
		break;
	case 'sweep':
		verify_duration(701,array(POST_STICKY,0,0));
		$config->set('plus_announce_sticky',0);
		$cron->run();
		if ($cron->should_run()) { throw new RuntimeException('The daily sweep was scheduled again'); }
		verify_duration(702,array(POST_GLOBAL,604800,0));
		verify_duration(703,array(POST_ANNOUNCE,0,0));
		verify_duration(704,array(POST_ANNOUNCE,0,80000));
		verify_duration(705,array(POST_NORMAL,0,0));
		break;
	case 'posted':
		$r = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE forum_id=7 AND topic_title='Ankündigung mit Laufzeit'");
		$id=(int) $db->sql_fetchfield('topic_id'); $db->sql_freeresult($r);
		if (!$id) { throw new RuntimeException('New scheduled announcement missing'); }
		verify_duration($id,array(POST_ANNOUNCE,604800,0));
		break;
	case 'edited':
		verify_duration(704,array(POST_ANNOUNCE,0,0));
		break;
	default: exit(2);
}
