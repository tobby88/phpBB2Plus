<?php
function ad_seed_batch_cases()
{
 global $control,$schema,$db;
 $checks=0;$check=function($ok,$message)use(&$checks){ad_check($ok,$message);$checks++;};
 $names=array('fixture_seed_probe_a','fixture_seed_probe_b');
 $snapshot=function($connection)use($names){$out=array();foreach($names as$name){$r=$connection->sql_query('SELECT id,body FROM '.$name.' ORDER BY id');ad_check($r!==false,'Actual seed probe read');try{$out[$name]=$connection->sql_fetchrowset($r);}finally{$connection->sql_freeresult($r);}}return$out;};
 ad_check($control->sql_query('SET TRANSACTION READ WRITE')!==false,'Seed tests start with their own idle control');
 try{
  foreach($names as$name){ad_sql('CREATE TABLE '.$name.' (id INT PRIMARY KEY,body TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');ad_sql('INSERT INTO '.$name." VALUES(1,'original'),(2,'preserve')");}
  $before=$snapshot($control);$text="Grüße ' \\ 😀";
  $batched=album_native_seed_batch($control,$schema,$names,function()use($snapshot,$before,$check,$text){ad_sql('DELETE FROM fixture_seed_probe_a');ad_sql('INSERT INTO fixture_seed_probe_a VALUES(3,'.ad_literal($text).')');$check($snapshot($GLOBALS['db'])===$before,'Independent application connection sees original rows throughout fixture preparation');});
  $check($batched===true,'Canonical fixture setup commits as one batch');
  $after=$snapshot($control);$check($after===$snapshot($db)&&count($after['fixture_seed_probe_a'])===1&&$after['fixture_seed_probe_a'][0]['body']===$text&&$after['fixture_seed_probe_b']===$before['fixture_seed_probe_b'],'Complete original values, Unicode and unrelated rows survive actual commit');
  $caught=false;try{album_native_seed_batch($control,$schema,$names,function(){ad_sql('DELETE FROM fixture_seed_probe_a');throw new RuntimeException('own seed callback fault');});}catch(RuntimeException$error){if($error->getMessage()!=='own seed callback fault'){throw$error;}$caught=true;}
  $check($caught&&$snapshot($control)===$after&&$snapshot($db)===$after,'Callback failure rolls back all preparation');
  $caught=false;try{album_native_seed_batch($control,$schema,$names,function(){ad_sql('DELETE FROM fixture_seed_probe_a');ad_sql('INSERT INTO fixture_seed_probe_b(no_such_column) VALUES(9)');});}catch(RuntimeException$error){$caught=true;}
  $check($caught&&$snapshot($control)===$after&&$snapshot($db)===$after,'Late actual SQL failure cannot publish a partial fixture');
  $check($control->sql_query('SET TRANSACTION READ WRITE')!==false,'Failure leaves the control idle');
  ad_sql('START TRANSACTION');ad_sql("INSERT INTO fixture_seed_probe_a VALUES(7,'outer pending')");
  $caught=false;try{album_native_seed_batch($control,$schema,$names,function(){throw new RuntimeException('must not run');});}catch(RuntimeException$error){$caught=true;}
  $pending=$snapshot($control);$check($caught&&count($pending['fixture_seed_probe_a'])===2&&$snapshot($db)===$after,'An existing caller transaction remains private and is never borrowed');
  ad_sql('ROLLBACK');$check($snapshot($control)===$after,'Original caller still owns its rollback');
  ad_sql('SET SESSION autocommit=0');ad_sql("INSERT INTO fixture_seed_probe_a VALUES(8,'manual pending')");
  $caught=false;try{album_native_seed_batch($control,$schema,$names,function(){throw new RuntimeException('must not run');});}catch(RuntimeException$error){$caught=true;}
  $check($caught&&count($snapshot($control)['fixture_seed_probe_a'])===2&&$snapshot($db)===$after,'Manual commit mode is refused without ending pending work');ad_sql('ROLLBACK');ad_sql('SET SESSION autocommit=1');
  $caught=false;try{album_native_seed_batch($control,'codex_album_other_'.str_repeat('a',16),$names,function(){throw new RuntimeException('must not run');});}catch(RuntimeException$error){$caught=true;}
  $check($caught&&$snapshot($control)===$after,'A different fixture namespace cannot be prepared');
  $refused=0;foreach(array(array(array('fixture_seed_probe_a')),array('fixture_seed_probe_a; DROP TABLE fixture_seed_probe_b'))as$bad){try{album_native_seed_batch($control,$schema,$bad,function(){throw new RuntimeException('must not run');});}catch(RuntimeException$error){$refused++;}}
  $check($refused===2&&$snapshot($control)===$after,'Invalid identifiers are refused warning-free before writes');
  ad_sql('CREATE TABLE fixture_seed_probe_legacy(id INT PRIMARY KEY,body TEXT NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $batched=album_native_seed_batch($control,$schema,array('fixture_seed_probe_legacy'),function(){ad_sql("INSERT INTO fixture_seed_probe_legacy VALUES(1,'legacy refusal setup')");});
  $legacy=ad_rows('SELECT body FROM fixture_seed_probe_legacy');$check($batched===false&&count($legacy)===1&&$control->sql_query('SET TRANSACTION READ WRITE')!==false,'Legacy engine refusal cases retain explicit unbatched setup without a false atomicity claim');
 }finally{$control->sql_query('ROLLBACK');$control->sql_query('SET SESSION autocommit=1');foreach(array_merge($names,array('fixture_seed_probe_legacy'))as$name){ad_sql('DROP TABLE IF EXISTS '.$name);}}
 echo 'Native Album fixture seed batches: '.$checks." checks PASS; exact rows, independent visibility, callback/SQL rollback, pending caller/manual mode, namespace/identifier refusal and legacy setup.\n";
}
