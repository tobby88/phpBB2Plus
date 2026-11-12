<?php
function native_identity_reset_checks(){
 $checks=0;
 foreach(array('10.11.14-MariaDB'=>true,'5.5.5-10.11.14-MariaDB-local'=>true,'10.3.0-MariaDB'=>true,'10.2.44-MariaDB'=>false,'8.0.40'=>false,'unknown'=>false)as$version=>$expected){ae_check(ae_identity_can_skip($version)===$expected,'Conservative identity metadata support');$checks++;}
 $version=ae_rows('SELECT VERSION() AS server_version');$can_skip=ae_identity_can_skip($version[0]['server_version']);
 foreach(array('InnoDB','MyISAM')as$engine){
  ae_sql('DELETE FROM fixture_logs');ae_sql('ALTER TABLE fixture_logs ENGINE='.$engine.($engine==='InnoDB'?' ROW_FORMAT=DYNAMIC':''));ae_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');
  ae_check(ae_reset_identity('logs',1)===!$can_skip,'Exact warm counter avoids only supported redundant DDL');$checks++;
  ae_insert('fixture_logs',array('id_log'=>41,'mode'=>'Identity fixture'));ae_sql('DELETE FROM fixture_logs');ae_check(ae_reset_identity('logs',1),'Allocated/deleted identities force the original reset');ae_insert('fixture_logs',array('mode'=>'Identity fixture'));$rows=ae_rows('SELECT id_log,mode FROM fixture_logs');ae_check(count($rows)===1&&(int)$rows[0]['id_log']===1&&$rows[0]['mode']==='Identity fixture','Actual next insert receives the exact original identity');$checks++;
  $before=ae_rows('SELECT * FROM fixture_logs');ae_check(ae_reset_identity('logs',900)&&ae_rows('SELECT * FROM fixture_logs')===$before,'Required higher identity changes no row bytes');ae_insert('fixture_logs',array('mode'=>'Identity fixture'));ae_check((int)ae_rows('SELECT MAX(id_log) AS id FROM fixture_logs')[0]['id']===900,'Actual higher identity allocation');$checks++;
  ae_sql('DELETE FROM fixture_logs');ae_reset_identity('logs',1);
  if($engine==='InnoDB'){
   ae_sql('START TRANSACTION');ae_insert('fixture_logs',array('mode'=>'Rolled back fixture'));ae_sql('ROLLBACK');ae_check(ae_reset_identity('logs',1)&&ae_rows('SELECT * FROM fixture_logs')===array(),'Rolled-back allocation is detected and reset');ae_insert('fixture_logs',array('mode'=>'Identity fixture'));ae_check((int)ae_rows('SELECT id_log FROM fixture_logs')[0]['id_log']===1,'Allocation after rollback starts at the original identity');$checks++;
   if($can_skip){foreach(array(3,900)as$next){ae_sql('START TRANSACTION');ae_insert('fixture_logs',array('mode'=>'Uncommitted fixture'));$denied=false;try{ae_reset_identity('logs',$next);}catch(RuntimeException$error){$denied=$error->getMessage()==='Fixture identity reset must not commit an open transaction';}$state=ae_rows('SELECT @@SESSION.in_transaction AS active_transaction');ae_check($denied&&(int)$state[0]['active_transaction']===1,'Reset cannot silently commit an existing fixture transaction');ae_sql('ROLLBACK');ae_check(count(ae_rows('SELECT * FROM fixture_logs'))===1,'Rejected reset leaves rollback effective');$checks++;}}
  }
 }
 ae_sql('DELETE FROM fixture_logs');ae_sql('ALTER TABLE fixture_logs ENGINE=InnoDB ROW_FORMAT=DYNAMIC');ae_reset_identity('logs',1);
 foreach(array(array('sessions',1),array('logs; DROP TABLE fixture_users',1),array('logs',0),array('logs',true),array('logs','1'))as$bad){$denied=false;try{ae_reset_identity($bad[0],$bad[1]);}catch(RuntimeException$error){$denied=$error->getMessage()==='Exact fixture identity reset target';}ae_check($denied,'Only explicit fixture tables and exact positive counters accepted');$checks++;}
 echo 'Native fixture identities: ',$checks," checks PASS; exact warm counters, deleted/rolled-back allocations, retained rows, both engines, transaction barriers and conservative server fallback.\n";
}
native_identity_reset_checks();
