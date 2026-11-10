<?php
require __DIR__.'/arcade-game-fixture.php';require $as_app.'db/mysqli.php';
$ag_trace=array();$ag_peer=null;$ag_async=false;
class ArcadeGameNativeDatabase extends sql_db
{
 var $owned=false;var $fault='';var $fired=false;var $writes=0;var $rename=0;var $factory_mode='';var $fixture_factory;
 function __construct($host,$user,$password,$schema,$persistent=false,$owned=false){parent::__construct($host,$user,$password,$schema,$persistent);$this->owned=$owned;$this->fixture_factory=function()use($host,$user,$password,$schema){return new ArcadeGameNativeDatabase($host,$user,$password,$schema,false,true);};}
 function sql_dedicated_connection(){if($this->factory_mode==='same'){return $this;}if($this->factory_mode==='missing'){return false;}$c=call_user_func($this->fixture_factory);$c->fault=$this->fault;if($this->factory_mode==='no-backslash'){as_check($c->sql_query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'NO_BACKSLASH_ESCAPES')")!==false,'Alternate native SQL mode');}return $c;}
 function sql_query($sql='',$transaction=false){
  global $ag_peer,$ag_trace,$ag_async;if(!$this->owned){return parent::sql_query($sql,$transaction);}$ag_trace[]=$sql;$command=strtoupper(trim($sql));
  if(!$this->fired&&$command==='START TRANSACTION'&&strpos($this->fault,'revoke-')===0){$this->fired=true;$changes=array('revoke-role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=2','revoke-active'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','revoke-grant'=>'DELETE FROM fixture_jr_admin_users WHERE user_id=2','revoke-session'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-import'",'revoke-admin'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-import'");as_check(isset($changes[$this->fault])&&$ag_peer->sql_query($changes[$this->fault]),'Competing own authority change');}
  $write=preg_match('/^(INSERT|UPDATE|DELETE) /',$command)===1;$renaming=preg_match('/^UPDATE fixture_\w+ SET \w+=\x27new_game\x27 WHERE \w+=\x27old_game\x27$/D',$sql)===1;
  if($renaming){$this->rename++;}
  $hit=($write&&$this->fault==='write-'.($this->writes+1))||($renaming&&$this->fault==='rename-'.$this->rename)||($command==='COMMIT'&&$this->writes&&in_array($this->fault,array('commit','commit-after'),true))||($this->fault==='summary-read'&&strpos($sql,'SELECT g.game_id,g.game_name,s.player_id FROM ')===0)||($this->fault==='winner-read'&&strpos($sql,'SELECT s.player_id FROM fixture_ina_scores s INNER JOIN ')===0);
  if(!$this->fired&&$hit){$this->fired=true;if($this->fault==='commit-after'){as_check(parent::sql_query($sql,$transaction)!==false,'Synthetic lost commit acknowledgement');}return false;}
  if(!$this->fired&&$write&&in_array($this->fault,array('serialize-role','serialize-grant'),true)){$this->fired=true;$change=$this->fault==='serialize-role'?'UPDATE fixture_users SET user_level=0 WHERE user_id=2':'DELETE FROM fixture_jr_admin_users WHERE user_id=2';as_check(mysqli_query($ag_peer->db_connect_id,$change,MYSQLI_ASYNC),'Queue own revocation');$read=array($ag_peer->db_connect_id);$error=$reject=array();as_check(mysqli_poll($read,$error,$reject,0,0)===0,'Revocation waits for share locks');$ag_async=true;}
  $r=parent::sql_query($sql,$transaction);if($write&&$r!==false){$this->writes++;}return $r;
 }
}
function ag_sql($sql){$r=$GLOBALS['ag_peer']->sql_query($sql);as_check($r!==false,'Own game fixture SQL: '.$sql);return $r;}
function ag_rows($sql){$r=ag_sql($sql);try{return $GLOBALS['ag_peer']->sql_fetchrowset($r);}finally{$GLOBALS['ag_peer']->sql_freeresult($r);}}
function ag_snapshot(){global $ag_tables;$out=array();foreach($ag_tables as$table){if(in_array($table,array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE),true)){continue;}$rows=array_map('json_encode',ag_rows('SELECT * FROM '.$table));sort($rows);$out[$table]=$rows;}return $out;}
function ag_reset($role='root'){
 global $db,$ag_tables,$ag_routes,$ag_trace,$ag_notifications,$arcade,$phpbb_root_path,$ag_async;
 as_check(!$ag_async,'No outstanding test revocation');$db->fault=$db->factory_mode='';aif_request();$ag_notifications=array();ag_language();
 // Seed a case in one peer transaction. Autocommitting every fixture row
 // spent most of the suite waiting for fsync, before the code under test ran.
 if(!$GLOBALS['ag_peer']->sql_query('START TRANSACTION')){throw new RuntimeException('Own fixture seed transaction unavailable');}
 foreach($ag_tables as$table){ag_sql('DELETE FROM '.$table);}
 ag_sql("INSERT INTO fixture_users(user_id,username,user_password,user_level,user_active) VALUES(2,'Actor','',".($role==='root'?1:0).",1),(8,'Player','',0,1)");ag_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_admin) VALUES('fixture-import',2,1,1)");
 if($role!=='root'){ag_sql("INSERT INTO fixture_jr_admin_users(user_id,user_jr_admin,admin_notes) VALUES(2,'".$ag_routes[$role]."','')");}
 ag_sql("INSERT INTO fixture_groups(group_id,group_name,group_description,group_single_user) VALUES(99,'Sentinel','',0)");
 ag_sql("INSERT INTO fixture_ina_cat(cat_id,cat_name,cat_type,total_games,total_played,last_game) VALUES(-1,'All','p',1,12,'old_game'),(7,'Old','p',1,12,'old_game'),(9,'New','p',0,0,NULL),(8,'Link','l',0,0,NULL)");
 $v=phpbb_arcade_game_values(ag_values());$assign=array();foreach($v as$key=>$value){$assign[]=$key."='".$GLOBALS['ag_peer']->sql_escape($value)."'";}
 ag_sql('INSERT INTO fixture_ina_games SET game_id=41,played=12,highscore_id=8,at_highscore_id=8,'.implode(',',$assign));
 foreach(array(iNA_SCORES,iNA_AT_SCORES)as$table){ag_sql("INSERT INTO $table(game_name,player_id,score,date) VALUES('old_game',8,100,1),('old_game',2,50,2)");}
 ag_sql("INSERT INTO fixture_ina_user_data(user_id,last_played,first_places,at_first_places) VALUES(8,'old_game',10,12),(2,'old_game',5,7)");
 ag_sql("INSERT INTO fixture_ina_comment(comment_game_name,game_name) VALUES('old_game','old_game')");
 foreach(array(iNA_FAV=>'fav_game_name',iNA_GAMES_RATE=>'rate_game_name',iNA_SESSIONS=>'game_name',iNA_BANNED=>'game',iNA_TOUR_DATA=>'game_name',iNA_TOUR_PLAY=>'last_played_game')as$table=>$field){ag_sql("INSERT INTO $table($field) VALUES('old_game')");}
 ag_sql("INSERT INTO fixture_ina_highscore(highscore_game,highscore_year) VALUES('old_game',2020)");
 if(!$GLOBALS['ag_peer']->sql_query('COMMIT')){throw new RuntimeException('Own fixture seed commit failed');}
 $arcade->arcade_config['games_pm_new']=0;foreach(array('games','categories','best_player','best_at_player')as$key){as_check($arcade->write_cache($key,array('old'=>'cache'),$phpbb_root_path),'Own affected cache canary');}$ag_trace=array();
}
function ag_revision(){return phpbb_arcade_game_revision(ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0]);}
function ag_call($values=null,$id=41,$revision=null,$reset=null){if($values===null){$values=ag_values('new_game');}if($revision===null){$revision=$id?ag_revision():'';}if($reset===null){$reset=array('current'=>false,'all_time'=>false);}try{return array('result'=>phpbb_arcade_game_save($GLOBALS['db'],$id,$revision,$values,$reset),'error'=>false);}catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}}
function ag_evicted(){global $phpbb_root_path;foreach(array('games','categories','best_player','best_at_player')as$key){as_check(!file_exists($phpbb_root_path.'cache/arcade_'.$key.'.cache'),'Evict affected cache '.$key);}}
function ag_reap(){global $ag_async,$ag_peer;if(!$ag_async){return;}$read=array($ag_peer->db_connect_id);$error=$reject=array();as_check(mysqli_poll($read,$error,$reject,3,0)===1&&mysqli_reap_async_query($ag_peer->db_connect_id)!==false,'Serialized own revocation completes');$ag_async=false;}
