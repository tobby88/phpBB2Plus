<?php
// Original unbatched seed from 80805bd; reference data and next-ID checks only.
function gmn_seed_reference($actor='root'){
 global $userdata,$gmn_actor,$gmn_hook,$gmn_fail,$gmn_write,$gmn_commit,$gmn_queries;
 $gmn_hook=null;$gmn_fail=$gmn_write=0;$gmn_commit='';$gmn_queries=array();
 $GLOBALS['gmn_after_commit']=null;$GLOBALS['gmn_acknowledged']=false;$GLOBALS['gmn_after_queries']=array();
 foreach($GLOBALS['gmn_tables'] as $s){gmn_sql('DELETE FROM fixture_'.$s);}
 gmn_sql('ALTER TABLE fixture_groups AUTO_INCREMENT=1');
 gmn_sql("INSERT INTO fixture_users (user_id,user_level,user_active,username) VALUES (1,1,1,'actor'),(2,2,1,'old'),(3,0,1,'new'),(4,2,1,'member'),(5,0,1,'unrelated')");
 gmn_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1),('old-session',2,1,0),('new-session',3,1,0),('member-session',4,1,0),('unrelated-session',5,1,1)");
 gmn_sql("INSERT INTO fixture_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES (10,0,'before','before',2,0),(11,0,'other','other',5,0),(12,0,'personal','personal',4,1)");
 gmn_sql('INSERT INTO fixture_user_group (group_id,user_id,user_pending) VALUES (10,2,0),(10,3,1),(10,4,0),(11,5,0),(12,4,0)');
 gmn_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_mod) VALUES (10,1,1),(11,1,1)');
 gmn_sql("INSERT INTO fixture_forums (forum_id,cat_id,forum_name) VALUES (1,1,'fixture')");
 gmn_sql('INSERT INTO fixture_pa_auth VALUES (10,1),(11,1)');
 gmn_sql("INSERT INTO fixture_quota_limits VALUES (1,'first',1024),(2,'second',2048)");
 gmn_sql('INSERT INTO fixture_attach_quota VALUES (0,10,1,2),(0,11,1,2),(4,0,2,1)');

 $map=array('root'=>1,'leader'=>2,'pending'=>3,'member'=>4,'outsider'=>5);$gmn_actor=$map[$actor];
 gmn_sql("UPDATE fixture_sessions SET session_user_id=".$gmn_actor.",session_admin=0 WHERE session_id='fixture-admin'");
 $userdata=array('user_id'=>$gmn_actor,'user_level'=>1,'session_logged_in'=>true,'session_admin'=>false,'session_id'=>'fixture-admin');$_SERVER['REQUEST_METHOD']='POST';

}
