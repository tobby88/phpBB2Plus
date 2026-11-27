<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
if ($argv[2] === 'source-classic')
{
	$s=new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],(int)(getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$s->query("UPDATE phpbb_plus SET config_value='index_body.tpl' WHERE config_name='index_layout'");
	return;
}
define('IN_PHPBB',true);
$phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$visitors=$phpbb_container->get('phpbb2plus.community.daily_visitors');
$table=USERS_TABLE; $key='test_plus_daily_registration_counts';
$store=$phpbb_container->get('config_text');
$rows=static function($sql) use($db) { $r=$db->sql_query($sql); $out=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $out; };
$check=static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
// Independent calendar-date oracle on the small synthetic membership table.
$expected=static function($now,$zone) use($rows,$table) {
	$day=(new DateTimeImmutable('@'.$now))->setTimezone($zone); $today=$day->format('Y-m-d'); $yesterday=$day->modify('-1 day')->format('Y-m-d');
	$counts=array('today'=>0,'yesterday'=>0);
	foreach ($rows("SELECT user_id,user_type,user_regdate FROM $table") as $r) {
		if ((int)$r['user_id']===ANONYMOUS || (int)$r['user_type']===USER_IGNORE || $r['user_regdate']>$now) { continue; }
		$local=(new DateTimeImmutable('@'.$r['user_regdate']))->setTimezone($zone)->format('Y-m-d');
		if ($local===$today) { ++$counts['today']; } elseif ($local===$yesterday) { ++$counts['yesterday']; }
	}
	return $counts;
};
$phase=$argv[2];
if ($phase==='older')
{
	$check(isset($config['plus_registration_counts']) && !(int)$config['plus_registration_counts'],'Registration display unexpectedly enabled');
	return;
}
if ($phase==='boundaries')
{
	$index=$rows("SHOW INDEX FROM $table WHERE Key_name='plus_regdate'");
	$check(array_column($index,'Column_name')===array('user_regdate','user_type'),'Missing native registration range index');
	$people=array_column($rows("SELECT user_id FROM $table WHERE user_id<>".ANONYMOUS.' ORDER BY user_id LIMIT 8'),'user_id');
	$check(count($people)===8,'Not enough synthetic accounts');
	$db->sql_transaction('begin');
	try {
		$zone=new DateTimeZone('Europe/Berlin');
		foreach (array('2024-04-01 12:00','2024-10-28 12:00') as $date) {
			$day=new DateTimeImmutable($date,$zone); $now=$day->getTimestamp(); $start=$day->setTime(0,0); $y=$start->modify('-1 day')->getTimestamp(); $t=$start->getTimestamp();
			foreach (array($y-1,$y,$t-1,$t,$now,$now+1,$t,$y) as $i=>$stamp) {
				$type=$i===6 ? USER_IGNORE : ($i===7 ? USER_INACTIVE : USER_NORMAL);
				$db->sql_query("UPDATE $table SET user_regdate=$stamp,user_type=$type WHERE user_id=".(int)$people[$i]);
			}
			$db->sql_query("UPDATE $table SET user_regdate=$t,user_type=".USER_NORMAL.' WHERE user_id='.ANONYMOUS);
			foreach (array($zone,new DateTimeZone('Pacific/Honolulu'),new DateTimeZone('Asia/Kathmandu')) as $local) {
				$check($visitors->registrations($now,$local)===$expected($now,$local),'Visitor-zone/DST registration count changed');
			}
		}
	} finally { $db->sql_transaction('rollback'); }
	return;
}
if ($phase==='setup' || $phase==='setup-classic')
{
	$check((int)$config['plus_registration_counts']===($phase==='setup'?1:0),'Source index layout was not converted');
	$settings=array('plus_registration_counts','plus_daily_scope','plus_daily_force','plus_daily_mode','board_timezone');
	$old=array(); foreach ($settings as $item) { $old[$item]=$config[$item]; }
	$members=$rows("SELECT user_id,user_timezone,user_regdate FROM $table WHERE username IN ('HistoricMember','ModernMember')");
	$check(count($members)===2 && $store->get($key)===null,'Unexpected registration fixture');
	$store->set($key,json_encode(array($old,$members)));
	$config->set('plus_daily_scope',2); $config->set('plus_daily_force',1); $config->set('plus_daily_mode',0); $config->set('board_timezone','UTC');
	$db->sql_query("UPDATE $table SET user_timezone='Asia/Kathmandu' WHERE username='HistoricMember'");
	$now=time(); $utc=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'))->setTime(0,0)->getTimestamp();
	$other=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Kathmandu'))->setTime(0,0)->getTimestamp();
	$between=intdiv($utc+$other,2);
	$db->sql_query("UPDATE $table SET user_regdate=$between WHERE username IN ('HistoricMember','ModernMember')");
	$category=$rows('SELECT forum_id FROM '.FORUMS_TABLE.' WHERE forum_type='.FORUM_CAT.' AND plus_legacy_category>0 ORDER BY left_id LIMIT 1');
	echo json_encode(array('category'=>(int)$category[0]['forum_id']));
}
elseif ($phase==='expected')
{
	$now=time(); echo json_encode(array('guest'=>$expected($now,new DateTimeZone('UTC')),'member'=>$expected($now,new DateTimeZone('Asia/Kathmandu'))));
}
elseif (in_array($phase,array('no-statistics','index-only','both-places'),true))
{
	$config->set('plus_daily_scope',array_search($phase,array('no-statistics','index-only','both-places'),true));
}
elseif ($phase==='restore')
{
	list($old,$members)=json_decode($store->get($key),true);
	foreach ($members as $row) {
		$id=(int)$row['user_id']; unset($row['user_id']);
		$db->sql_query("UPDATE $table SET ".$db->sql_build_array('UPDATE',$row)." WHERE user_id=$id");
	}
	foreach ($old as $item=>$value) { $config->set($item,$value); }
	$store->delete($key);
}
else { exit(2); }
