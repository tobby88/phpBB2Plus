<?php
if(PHP_SAPI!=='cli'||!isset($cases,$peer,$db,$sd_source)||!function_exists('pi_reset')){throw new RuntimeException('Owned post icon fixture required');}
// The preceding revocation probes use a NOWAIT peer. Schema setup here is
// ordinary fixture work; give its DDL bounded normal waits. The one intentional
// metadata conflict below still installs its own zero wait explicitly.
pi_sql('SET SESSION lock_wait_timeout=5,innodb_lock_wait_timeout=5');
// Every storage participant is pinned and must stay modern through the write.
foreach(array(POST_ICON_CONFIG_TABLE,POSTS_TABLE,TOPICS_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE)as$table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1')as$ddl){
 pi_reset();pi_sql('ALTER TABLE '.$table.' '.$ddl);$before=pi_snapshot();sd_check(pi_run(pi_request('delete'))!=='view'&&pi_snapshot()===$before,'Noncanonical participant refuses whole operation');sd_unlocked();pi_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC,CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 // CONVERT can promote MEDIUMTEXT to LONGTEXT when widening latin1. Restore
 // the fixture's exact original column, rather than weakening the real gate.
 if($table===POST_ICON_CONFIG_TABLE){pi_sql('ALTER TABLE '.$table.' MODIFY config_value MEDIUMTEXT NOT NULL');}
 $cases++;
}}
pi_reset();pi_sql('ALTER TABLE fixture_post_icon_config ADD foreign_column INT NULL');$before=pi_snapshot();sd_check(pi_run(pi_request('delete'))!=='view'&&pi_snapshot()===$before,'Extra config columns cannot be silently adopted');sd_unlocked();pi_sql('ALTER TABLE fixture_post_icon_config DROP foreign_column');$cases++;
pi_reset();pi_sql("INSERT INTO fixture_post_icon_config VALUES(2,1,'".$peer->sql_escape(serialize(pi_seed_config()))."')");$before=pi_snapshot();sd_check(pi_run(pi_request('delete'))!=='view'&&pi_snapshot()===$before,'Extra authoritative rows are not discarded');sd_unlocked();$cases++;
foreach(array('', 'ANSI_QUOTES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','TRADITIONAL')as$mode){
 pi_reset();$seen=false;$sd_hook=function($sql,$connection)use($mode,&$seen){if(strpos($sql,'SET SESSION sql_mode ')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_check($connection->inner->sql_query("SET SESSION sql_mode='$mode'"),'Owned SQL mode');};$out=pi_run(pi_request('delete'));sd_check($out==='view'&&$seen,'Actual icon/reference save under four SQL modes: '.$mode.' result='.$out);sd_unlocked();$cases++;
}
pi_reset();$original_wait=phpbb_acl_rows($peer,'SELECT @@SESSION.lock_wait_timeout n')[0]['n'];pi_sql('SET SESSION lock_wait_timeout=0');$seen=$blocked=false;
$sd_hook=function($sql)use(&$seen,&$blocked){if(strpos($sql,'UPDATE fixture_post_icon_config ')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;if(!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_post_icon_config ENGINE=MyISAM')){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only actual metadata conflict');$blocked=true;}};
try{sd_check(pi_run(pi_request('delete'))==='view'&&$seen&&$blocked,'Transaction pins prevent an engine swap before publication');}finally{pi_sql('SET SESSION lock_wait_timeout='.(int)$original_wait);}sd_unlocked();$cases++;
// Hidden third-party defaults survive ordinary edits and follow deletion.
pi_reset();$config=pi_seed_config();$config['special']['EXTRA_DEFAULT']=array('lang_key'=>'Untranslated_fixture','icon'=>1);pi_sql("UPDATE fixture_post_icon_config SET config_value='".$peer->sql_escape(serialize($config))."' WHERE config_id=1");$r=pi_request('edit');$r['ids']=array();sd_check(pi_run($r)==='view','Edit with no selected displayed default');$stored=phpbb_icon_config_decode(pi_snapshot()[2][0]['config_value']);sd_check($stored['special']['POST_NORMAL']['icon']===0&&$stored['special']['EXTRA_DEFAULT']['icon']===1,'Unchecked displayed defaults reset; hidden ones survive');sd_check(pi_run(pi_request('delete'))==='view','Delete with hidden default');$stored=phpbb_icon_config_decode(pi_snapshot()[2][0]['config_value']);sd_check($stored['special']['EXTRA_DEFAULT']['icon']===2,'Hidden assignments follow replacement');sd_unlocked();$cases++;
// Maximum supported reference IDs are real SQL values, not merely PHP casts.
pi_reset();$config=pi_seed_config();$config['icons'][]=array('ind'=>65535,'img'=>'images/max.gif','alt'=>'icon_note','auth'=>3);$config['special']['POST_NORMAL']['icon']=65535;pi_sql("UPDATE fixture_post_icon_config SET config_value='".$peer->sql_escape(serialize($config))."' WHERE config_id=1");pi_sql('UPDATE fixture_posts SET post_icon=65535 WHERE post_id=1');pi_sql('UPDATE fixture_topics SET topic_icon=65535 WHERE topic_id=1');$r=pi_request('delete');$r['icon']='65535';sd_check(pi_run($r)==='view'&&pi_snapshot()[0][0]['post_icon']==='2'&&pi_snapshot()[1][0]['topic_icon']==='2','Full ID65535 replaced across all actual storage');sd_unlocked();$cases++;
pi_reset();$config=pi_seed_config();$config['icons']=array();for($id=0;$id<256;$id++){$config['icons'][]=array('ind'=>$id,'img'=>'images/icon.gif','alt'=>'icon_note','auth'=>0);}pi_sql("UPDATE fixture_post_icon_config SET config_value='".$peer->sql_escape(serialize($config))."' WHERE config_id=1");$before=pi_snapshot();sd_check(pi_run(pi_request('add'))!=='view'&&pi_snapshot()===$before,'Count exhaustion is reported before any write');sd_unlocked();$cases++;
// Separate owners preserve unrelated pending work, and refuse conflicting work
// without committing or rolling back the caller's transaction.
pi_reset();sd_check($db->sql_query('START TRANSACTION'),'Caller starts');sd_check($db->sql_query("UPDATE fixture_themes SET tr_color1='202020' WHERE themes_id=1"),'Unrelated pending caller');$state=phpbb_acl_rows($db,'SELECT CONNECTION_ID() id,@@SESSION.sql_mode m,@@SESSION.autocommit a');sd_check(pi_run(pi_request('delete'))==='view','Independent icon operation');sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() id,@@SESSION.sql_mode m,@@SESSION.autocommit a')===$state&&phpbb_acl_rows($db,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='202020'&&phpbb_acl_rows($peer,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='aaaaaa','Caller state and private row survive');sd_check($db->sql_query('ROLLBACK'),'Caller still owns rollback');sd_unlocked();$cases++;
pi_reset();sd_check($db->sql_query('START TRANSACTION'),'Conflicting caller starts');sd_check($db->sql_query("UPDATE fixture_posts SET body='pending' WHERE post_id=1"),'Conflicting pending row');$before=pi_snapshot();$sd_hook=function($sql,$connection){if(strpos($sql,'SET SESSION sql_mode ')!==0){return;}$GLOBALS['sd_hook']=null;sd_check($connection->inner->sql_query('SET SESSION innodb_lock_wait_timeout=0'),'Bounded own conflict');};sd_check(pi_run(pi_request('delete'))!=='view'&&pi_snapshot()===$before,'Conflicting pending caller refuses complete mutation');sd_check(phpbb_acl_rows($db,'SELECT body FROM fixture_posts WHERE post_id=1')[0]['body']==='pending'&&$db->sql_query('ROLLBACK'),'Refusal retains caller pending row and rollback');sd_unlocked();$cases++;
class PostIconBorrowedFactory extends StyleDataDatabase{function sql_dedicated_connection(){return$this;}}
pi_reset();$borrowed=new PostIconBorrowedFactory($db->inner);$before=pi_snapshot();$caught=false;try{phpbb_icon_admin_change($borrowed,'delete',pi_request('delete'));}catch(PhpbbAclException$e){$caught=true;}sd_check($caught&&pi_snapshot()===$before&&$db->sql_query('SELECT 1'),'Borrowed factory rejected without closing caller');sd_unlocked();$cases++;
class PostIconFixedFactory extends StyleDataDatabase{var$participant;function sql_dedicated_connection(){return new StyleDataConnection($this->participant);}}
foreach(array('active','implicit','inherited-lease')as$kind){
 pi_reset();$participant=new sql_db($host,'root',$password,$fixture,false);$factory=new PostIconFixedFactory($db->inner);$factory->participant=$participant;$before=pi_snapshot();$lease='attachment:'.md5($fixture."\0".ATTACHMENTS_TABLE);
 try{
  if($kind==='inherited-lease'){$held=phpbb_acl_rows($participant,"SELECT GET_LOCK('$lease',0) n");sd_check($held[0]['n']==='1','Factory peer owns inherited lease');}
  else{sd_check($participant->sql_query($kind==='active'?'START TRANSACTION':'SET autocommit=0'),'Factory participant starts its own transaction');sd_check($participant->sql_query("UPDATE fixture_themes SET tr_color1='303030' WHERE themes_id=1"),'Factory participant pending work');}
  $state=phpbb_acl_rows($participant,'SELECT CONNECTION_ID() id,@@SESSION.autocommit a,@@SESSION.sql_mode m');$caught=false;try{phpbb_icon_admin_change($factory,'delete',pi_request('delete'));}catch(PhpbbAclException$e){$caught=true;}
  sd_check($caught&&pi_snapshot()===$before&&phpbb_acl_rows($participant,'SELECT CONNECTION_ID() id,@@SESSION.autocommit a,@@SESSION.sql_mode m')===$state,'Rejected factory participant remains open and unchanged');
  if($kind==='inherited-lease'){sd_check(phpbb_acl_rows($peer,"SELECT IS_USED_LOCK('$lease') n")[0]['n']===$state[0]['id'],'Rejected participant retains its own lease');phpbb_acl_rows($participant,"SELECT RELEASE_LOCK('$lease')");}
  else{sd_check(phpbb_acl_rows($participant,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='303030'&&phpbb_acl_rows($peer,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='aaaaaa'&&$participant->sql_query('ROLLBACK'),'Rejected participant retains uncommitted data and rollback');}
 }finally{$participant->sql_close();}sd_unlocked();$cases++;
}
foreach(array('autocommit','foreign-table','foreign-insert','ddl','double-begin','after-commit','after-release')as$kind){pi_reset();$before=pi_snapshot();$owner=new PhpbbIconConfigWriter($db);$caught=false;try{
 if($kind!=='autocommit'&&$kind!=='after-release'){$owner->sql_query('START TRANSACTION');}
 if($kind==='ddl'){$owner->sql_query('ALTER TABLE fixture_posts ENGINE=MyISAM');}elseif($kind==='double-begin'){$owner->sql_query('START TRANSACTION');}elseif($kind==='foreign-table'){$owner->sql_query('UPDATE fixture_users SET user_level=1');}elseif($kind==='foreign-insert'){$owner->sql_query('INSERT INTO fixture_posts(post_id) VALUES(99)');}else{if($kind==='after-commit'){$owner->sql_query('COMMIT');}if($kind==='after-release'){$owner->release();}$owner->sql_query("UPDATE fixture_post_icon_config SET config_value='bad'");}
}catch(PhpbbAclException$e){$caught=true;}finally{$owner->release();}sd_check($caught&&pi_snapshot()===$before,'Owner rejects arbitrary writes and completed lifecycle');sd_unlocked();$cases++;}
foreach(array('english','german')as$locale){
 $lang=array();$lang_extend_admin=true;require $sd_source.'language/lang_'.$locale.'/lang_main.php';require $sd_source.'language/lang_'.$locale.'/lang_admin.php';require $sd_source.'language/lang_'.$locale.'/lang_extend_post_icons.php';
 foreach(array('list','add','edit','delete','delete-zero','missing','invalid','refresh','cancel-save','cancel-delete','unconfirmed','get-up','unused','bad-label','quoted-label','quoted-default','theme-image','corrupt-config')as$view){
  pi_reset();$post=$get=array();$_SERVER['REQUEST_METHOD']='GET';$images=array('spacer'=>'images/spacer.gif');
  if($view==='unused'){pi_sql('UPDATE fixture_posts SET post_icon=0');pi_sql('UPDATE fixture_topics SET topic_icon=0');}
  if($view==='corrupt-config'){pi_sql("UPDATE fixture_post_icon_config SET config_value='not-serialized' WHERE config_id=1");}
  if($view==='bad-label'){$config=pi_seed_config();$config['icons'][1]['alt']='datetime';$lang['datetime']=array('bad');pi_sql("UPDATE fixture_post_icon_config SET config_value='".$peer->sql_escape(serialize($config))."' WHERE config_id=1");}
  if($view==='quoted-label'){$lang['icon_note']='Größe "<X>"';}
  if($view==='quoted-default'){$lang['Post_Normal']='Größe "<Default>"';}
  if($view==='theme-image'){$images['images/one.gif']=array('bad');}
  if($view==='add'){$post=array('create'=>'1');$_SERVER['REQUEST_METHOD']='POST';}
  elseif($view==='refresh'){$post=pi_request('edit');unset($post['submit']);$post['refresh']='1';$_SERVER['REQUEST_METHOD']='POST';}
  elseif($view==='cancel-save'||$view==='cancel-delete'){$post=pi_request($view==='cancel-save'?'edit':'delete');$post['cancel']='1';$_SERVER['REQUEST_METHOD']='POST';}
  elseif($view==='unconfirmed'){$post=pi_request('delete');unset($post['confirm']);$_SERVER['REQUEST_METHOD']='POST';}
  elseif(in_array($view,array('edit','delete','delete-zero','missing','invalid','get-up','quoted-default'),true)){$get=array('mode'=>$view==='get-up'?'up':(strpos($view,'delete')===0?'del':'edit'),'icon'=>$view==='delete-zero'?'0':($view==='missing'?'999':($view==='invalid'?array('1'):'1')));}
  $before=pi_snapshot();$out=pi_run($post,$get);sd_check(pi_snapshot()===$before,'Every preview/cancel/GET leaves all actual data intact');
  $error=in_array($view,array('delete-zero','missing','invalid','get-up','corrupt-config'),true);
  if($error){sd_check($out!=='view','Invalid preview has localized response');}else{sd_check($out==='view'&&strpos($pi_html,'<form')!==false,'Actual standard XS form renders');}
  if(in_array($view,array('add','edit','delete','refresh'),true)){sd_check(strpos($pi_html,'name="sid" value="fixture-admin"')!==false,'Every actual control carries exact session token');}
  if($view==='quoted-label'){sd_check(strpos($pi_html,'&lt;X&gt;')!==false&&strpos($pi_html,'<X>')===false,'Translated labels are literal text in attributes and visible cells');unset($lang['icon_note']);require $sd_source.'language/lang_'.$locale.'/lang_extend_post_icons.php';}
  if($view==='quoted-default'){sd_check(strpos($pi_html,'&lt;Default&gt;')!==false&&strpos($pi_html,'<Default>')===false,'Default assignment labels are escaped in the actual edit form');require $sd_source.'language/lang_'.$locale.'/lang_main.php';}
  if($view==='bad-label'){sd_check(strpos($pi_html,'datetime')!==false,'Array-valued language entries have literal key fallback');}
  $views++;
 }
}
// Missing storage can display existing legacy icons, but cannot accept writes.
pi_reset();pi_sql('DROP TABLE fixture_post_icon_config');// The snapshots below intentionally exclude the now absent table.
$posts=phpbb_acl_rows($peer,'SELECT * FROM fixture_posts ORDER BY post_id');$topics=phpbb_acl_rows($peer,'SELECT * FROM fixture_topics ORDER BY topic_id');$_SERVER['REQUEST_METHOD']='GET';sd_check(pi_run(array())==='view','Pre-migration read uses existing file without writes');$_SERVER['REQUEST_METHOD']='POST';sd_check(pi_run(pi_request('delete'))!=='view'&&phpbb_acl_rows($peer,'SELECT * FROM fixture_posts ORDER BY post_id')===$posts&&phpbb_acl_rows($peer,'SELECT * FROM fixture_topics ORDER BY topic_id')===$topics,'Pre-migration writes refuse without touching references');sd_unlocked();pi_sql($icon_ddl);$cases+=2;
sd_check(hash_file('sha256',$sd_root.'/data/icons.dat')===$legacy_hash,'All runtime tests preserve the original legacy file');
