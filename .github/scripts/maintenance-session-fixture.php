<?php
// Shared test-only state checks; all callers pass isolated fixture connections.
function maintenance_session_revoke($pdo,$kind){
 $sql=array('missing'=>'DELETE FROM fixture_sessions','foreign'=>'UPDATE fixture_sessions SET session_user_id=99',
  'logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','admin-revoked'=>'UPDATE fixture_sessions SET session_admin=0',
  'case-changed'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID'",'replacement'=>"UPDATE fixture_sessions SET session_id='different-sid'");
 if(!isset($sql[$kind])){throw new RuntimeException('Unknown fixture revocation');}
 $pdo->exec($sql[$kind]);
}
function maintenance_session_snapshot($pdo,$tables){
 $out=array();foreach($tables as $table){
  if(!preg_match('/^fixture_[a-z_]+$/D',$table)){throw new RuntimeException('Fixture table boundary');}
  $rows=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
  $rows=array_map('serialize',$rows);sort($rows,SORT_STRING);$out[$table]=$rows;
 }return $out;
}
function maintenance_session_login($pdo,$actor){
 global $userdata;
 $pdo->exec('DELETE FROM fixture_sessions');
 $pdo->exec("INSERT INTO fixture_sessions VALUES ('new-admin-sid',".(int)$actor.",1,1)");
 $userdata['session_id']='new-admin-sid';$_POST=array('sid'=>'new-admin-sid');$_SERVER['REQUEST_METHOD']='POST';
}
