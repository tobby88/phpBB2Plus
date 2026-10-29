<?php
require_once __DIR__.'/native-fixture-locks.php';
function nfl_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['nfl_cases']++; }
$nfl_cases=0;
foreach (array(
 array('10.11.14-MariaDB',0),array('5.5.5-10.11.14-MariaDB-ubuntu',0),
 array('10.3.0-MariaDB',0),array('11.8.3-MariaDB-debian',0),
 array('10.2.44-MariaDB',1),array('5.5.68-MariaDB',1),
 array('8.0.44',1),array('8.4.7 MySQL Community Server',1),array('unknown',1),array(null,1)
) as $case) { nfl_check(native_fixture_conflict_wait($case[0])===$case[1],'Version-aware native conflict timeout'); }
if (PHP_SAPI!=='cli'||getenv('PHPBB_FIXTURE_LOCKS_NATIVE')!=='1') {
 echo 'Native fixture lock selection: ',$nfl_cases," version/fallback cases PASS; real contention requires explicit native fixture.\n"; return;
}
define('IN_PHPBB',true);$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';
require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
$port=getenv('PHPBB_FIXTURE_LOCKS_PORT')?:'3306';$password=getenv('PHPBB_FIXTURE_LOCKS_PASSWORD')?:'';
if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}
$host='127.0.0.1:'.$port;$fixture='codex_lock_probe_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);$owner=$peer=null;$created=false;
function nfl_rows($database,$sql) {
 $result=$database->sql_query($sql);if(!$result){throw new RuntimeException('Own fixture probe query failed');}
 try { return $database->sql_fetchrowset($result); } finally { $database->sql_freeresult($result); }
}
try {
 nfl_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable fixture schema');$created=true;
 $owner=new sql_db($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
 nfl_check($owner->sql_query('CREATE TABLE fixture_probe (id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB'),'Actual InnoDB participant');
 nfl_check($owner->sql_query('INSERT INTO fixture_probe VALUES(1,0),(2,0)'),'Own rows');
 $version=nfl_rows($peer,'SELECT VERSION() version');$expected=native_fixture_conflict_wait($version[0]['version']);
 nfl_check(native_fixture_peer_nowait($peer)===$expected,'Actual server selects supported conflict timeout');
 $before=nfl_rows($control,'SELECT @@SESSION.innodb_lock_wait_timeout wait_time');$denied=false;
 try { native_fixture_peer_nowait($control); } catch (RuntimeException $error) { $denied=true; }
 nfl_check($denied&&nfl_rows($control,'SELECT @@SESSION.innodb_lock_wait_timeout wait_time')===$before,'Connection without owned fixture refused before changing timeout');
 nfl_check($peer->sql_query('START TRANSACTION'),'Caller-owned peer transaction');
 native_fixture_peer_nowait($peer);$transaction=nfl_rows($peer,'SELECT @@in_transaction active');
 nfl_check((int)$transaction[0]['active']===1,'Timeout setup preserves caller transaction');
 nfl_check($peer->sql_query('ROLLBACK')&&$owner->sql_query('START TRANSACTION'),'Own source transaction');
 nfl_rows($owner,'SELECT * FROM fixture_probe WHERE id=1 FOR UPDATE');
 $started=microtime(true);$result=$peer->sql_query('UPDATE fixture_probe SET value=1 WHERE id=1');$elapsed=microtime(true)-$started;$error=$peer->sql_error();
 nfl_check($result===false&&(int)$error['code']===1205,'Real conflicting peer write reports native lock timeout');
 if($expected===0){nfl_check($elapsed<1,'Immediate conflict avoids a compulsory one-second wait');}
 nfl_check($peer->sql_query('UPDATE fixture_probe SET value=2 WHERE id=2'),'Unrelated unlocked row remains writable');
 $locked=nfl_rows($owner,'SELECT value FROM fixture_probe WHERE id=1');nfl_check((int)$locked[0]['value']===0,'Blocked write changed no locked row');
 nfl_check($owner->sql_query('COMMIT')&&$peer->sql_query('UPDATE fixture_probe SET value=3 WHERE id=1'),'Write succeeds after actual owner releases row');
 $released=nfl_rows($peer,'SELECT value FROM fixture_probe WHERE id=1');nfl_check((int)$released[0]['value']===3,'Actual released-row result');
 echo 'Native fixture locks: ',$nfl_cases," selection/owned-schema/transaction/real-conflict/unrelated-row/release cases PASS.\n";
} finally {
 foreach(array($peer,$owner)as$connection){if($connection){$connection->sql_query('ROLLBACK');$connection->sql_close();}}
 if(!preg_match('/^codex_lock_probe_[a-f0-9]{16}$/D',$fixture)){throw new RuntimeException('Own probe schema required');}
 if($created){$control->sql_query('DROP DATABASE '.$fixture);}$control->sql_close();
}

