<?php
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('PROFILE_FIELDS_TABLE'=>'fixture_profile_fields','BANLIST_TABLE'=>'fixture_banlist','TEXT_FIELD'=>0,'TEXTAREA'=>1,'RADIO'=>2,'CHECKBOX'=>3,'TEXT_FIELD_MAXLENGTH'=>255,'TEXTAREA_MINLENGTH'=>0,'TEXTAREA_MAXLENGTH'=>1024) as $key=>$value){if(!defined($key)){define($key,$value);}}
require $ats_source.'includes/functions_profile_definition_storage.php';
define('PROFILE_FIELD_JOBS_TABLE','fixture_profile_field_jobs');
function pds_values(){return array('field_name'=>'Renamed notes 😀','field_description'=>'Preserve &amp; text','field_type'=>'0','text_field_default'=>'','text_field_maxlen'=>'255','text_area_default'=>'','text_area_maxlen'=>'1024','radio_button_default'=>'','radio_button_values'=>'','checkbox_default'=>'','checkbox_values'=>'','is_required'=>'0','users_can_view'=>'1','view_in_profile'=>'1','profile_location'=>'2','view_in_memberlist'=>'0','view_in_topic'=>'0','topic_location'=>'1');}
$values=pds_values();ats_check(phpbb_profile_definition_values($values)===$values,'Exact internal definition inventory');
foreach(array('unknown','mapping','bad-type','bad-flag','oversize','bad-utf8','bad-option','array','zero-default') as $case){
 $v=$values;if($case==='unknown'){$v['sql']='injected';}elseif($case==='mapping'){$v['field_column']='user_password';}elseif($case==='bad-type'){$v['field_type']='4';}elseif($case==='bad-flag'){$v['is_required']='2';}elseif($case==='oversize'){$v['field_name']=str_repeat('x',256);}elseif($case==='bad-utf8'){$v['field_name']="\xc3";}elseif($case==='bad-option'){$v['field_type']='2';$v['radio_button_values']='first';$v['radio_button_default']='absent';}elseif($case==='array'){$v['field_name']=array('bad');}else{$v['field_type']='2';$v['radio_button_values']='first,0';$v['radio_button_default']='0';}
 $denied=false;try{phpbb_profile_definition_values($v);}catch(PhpbbAclException $e){$denied=true;}ats_check($denied===($case!=='zero-default'),'Definition validation '.$case);
}
$schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');preg_match('/CREATE TABLE phpbb_users\s*\(([\s\S]*?)\)\s*ENGINE=/',$schema,$m);preg_match_all('/^\s+([a-z_][a-z0-9_]*)\s+(?:mediumint|tinyint|smallint|int|bigint|varchar|char|text|decimal|float|double)\b/mi',$m[1],$columns);$core=$columns[1];preg_match_all('/ALTER TABLE phpbb_users\s+([\s\S]*?);/',$schema,$alters);foreach($alters[1] as $alter){preg_match_all('/\bADD\s+([a-z_][a-z0-9_]*)\s+/i',$alter,$added);$core=array_merge($core,$added[1]);}
ats_check($core===phpbb_profile_definition_core_columns(),'Complete canonical core-column protection');
$capacity=array('DATA_TYPE'=>'varchar','COLUMN_KEY'=>'','EXTRA'=>'');
foreach(array('literal','null','escaped','comment','mysql-literal','expression','indexed','core','shrink') as $case){
 $tail=" NOT NULL DEFAULT 'keep'";$cap=$capacity;$column='user_notes';$maximum=60000;
 if($case==='null'){$tail=' DEFAULT NULL';}elseif($case==='escaped'){$tail=" DEFAULT 'quote\\' and \\\\ slash'";}elseif($case==='comment'){$tail=" DEFAULT ('keep') COMMENT 'note'";}elseif($case==='expression'){$tail=' DEFAULT (uuid())';}elseif($case==='indexed'){$cap['COLUMN_KEY']='UNI';}elseif($case==='core'){$column='user_password';}elseif($case==='shrink'){$maximum=1;}
 if($case==='mysql-literal'){$cap['EXTRA']='DEFAULT_GENERATED';$tail=" DEFAULT ('keep')";}
 $denied=false;try{$sql=phpbb_profile_definition_widen_sql('fixture_users',$column,"CREATE TABLE `fixture_users` (\n  `$column` varchar(255)$tail,\n)",$cap,$maximum);}catch(PhpbbAclException $e){$denied=true;}
 ats_check($denied===in_array($case,array('expression','indexed','core','shrink'),true),'Widening planner '.$case);
 if(!$denied){ats_check(strpos($sql,'MODIFY COLUMN `user_notes` mediumtext')!==false,'Only wider type generated');if($case==='literal'){ats_check(strpos($sql,"NOT NULL DEFAULT ('keep')")!==false,'Literal default/nullability retained');}if($case==='comment'){ats_check(strpos($sql,$tail)!==false,'Existing literal expression/comment retained');}}
}
echo "Definition publication: input inventory and ".count($core)." protected core columns passed.\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_PROFILE_DEFINITION_NATIVE')!=='1'){return;}
require $ats_source.'db/mysqli.php';$port=getenv('PHPBB_PROFILE_DEFINITION_PORT')?:'3306';$password=getenv('PHPBB_PROFILE_DEFINITION_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Local port');$host='127.0.0.1:'.$port;
$fixture='codex_profile_definition_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class DefinitionConnectionFixture {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$tx=false){
  $GLOBALS['pds_queries'][]=$sql;if(is_callable($GLOBALS['pds_hook'])){call_user_func($GLOBALS['pds_hook'],$sql,$this);}
  if($sql==='COMMIT'){$GLOBALS['pds_commit_count']++;}
  $failure=$GLOBALS['pds_failure'];
  foreach(array('stage-insert'=>'INSERT INTO fixture_profile_field_jobs','initialize'=>'UPDATE fixture_users SET `cpf_','publish'=>'INSERT INTO fixture_profile_fields','receipt'=>'UPDATE fixture_profile_field_jobs') as $kind=>$prefix){if($failure===$kind&&strpos($sql,$prefix)===0){return false;}}
  if($sql==='COMMIT'&&(($failure==='stage-commit'&&$GLOBALS['pds_commit_count']===1)||($failure==='publish-commit'&&$GLOBALS['pds_commit_count']===2))){return false;}
  if(strpos($sql,'UPDATE fixture_profile_fields')===0&&$GLOBALS['pds_failure']==='update'){return false;}
  if(strpos($sql,'ALTER TABLE `fixture_users`')===0&&$GLOBALS['pds_failure']==='ddl'){return false;}
  if($sql==='COMMIT'&&$GLOBALS['pds_failure']==='commit'){return false;}
  $r=$this->inner->sql_query($sql,$tx);if($sql==='COMMIT'&&(($failure==='stage-ack'&&$GLOBALS['pds_commit_count']===1)||($failure==='publish-ack'&&$GLOBALS['pds_commit_count']===2))){return false;}if($sql==='COMMIT'&&$GLOBALS['pds_failure']==='ack'){return false;}if(strpos($sql,'ALTER TABLE `fixture_users`')===0&&$GLOBALS['pds_failure']==='ddl-ack'){return false;}return $r;
 }
}
class DefinitionDatabaseFixture extends sql_db {function sql_dedicated_connection(){return new DefinitionConnectionFixture(parent::sql_dedicated_connection());}}
$main=new DefinitionDatabaseFixture($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
$tables=array('users'=>'fixture_users','sessions'=>'fixture_sessions','jr_admin_users'=>'fixture_jr','banlist'=>'fixture_banlist','profile_fields'=>'fixture_profile_fields','profile_field_jobs'=>'fixture_profile_field_jobs');
$pds_hook=null;$pds_failure='';$pds_queries=array();
function pds_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Fixture SQL '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function pds_rows($sql){$r=pds_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function pds_insert($table,$values){foreach(pds_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$out=array();foreach($values as $v){$out[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pds_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$out).')');}
function pds_reset($actor='root'){
 global $userdata,$pds_failure,$pds_hook,$pds_queries;
 $pds_failure='';$pds_hook=null;$pds_queries=array();$GLOBALS['pds_commit_count']=0;pds_sql('START TRANSACTION');foreach($GLOBALS['tables'] as $t){pds_sql('DELETE FROM '.$t);}
 pds_insert('fixture_users',array('user_id'=>2,'username'=>'Admin','user_password'=>'unchanged','user_active'=>1,'user_level'=>$actor==='root'?1:0,'user_notes'=>"Stored \\ &amp; 😀"));
 pds_insert('fixture_users',array('user_id'=>7,'username'=>'Other','user_password'=>'other','user_active'=>1,'user_notes'=>'Other saved text'));
 pds_insert('fixture_sessions',array('session_id'=>'exact-session','session_user_id'=>2,'session_logged_in'=>1,'session_admin'=>1));
 $routes=jr_admin_authorization_routes();ats_check(is_array($routes),'Actual JR module inventory');$route='admin_profile_fields.php?mode='.($actor==='add'?'add':'edit').'&pfid=x';$hash=array_search($route,$routes,true);ats_check($hash!==false,'Exact delegated route');
 pds_insert('fixture_jr',array('user_id'=>2,'user_jr_admin'=>$hash));
 $row=pds_values();$row['field_id']=1;$row['field_name']='user_notes';pds_insert('fixture_profile_fields',$row);pds_sql('COMMIT');
 $userdata=pds_rows('SELECT * FROM fixture_users WHERE user_id=2')[0];$userdata['session_id']='exact-session';$userdata['session_logged_in']=true;$userdata['session_admin']=true;$_SERVER['REQUEST_METHOD']='POST';
 return phpbb_profile_definition_revision(pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id=1')[0]);
}
function pds_run($revision,$values,$action='edit'){
 $writer=null;try{$writer=new PhpbbProfileDefinitionWriter($GLOBALS['main'],$action,array('sid'=>'exact-session'));$writer->edit(1,$revision,$values);return $writer->confirmed;}catch(PhpbbAclException $e){return false;}finally{if($writer){$writer->release();}}
}
function pds_create($operation,$values,$action='add'){
 $writer=null;try{$writer=new PhpbbProfileDefinitionWriter($GLOBALS['main'],$action,array('sid'=>$GLOBALS['userdata']['session_id']));$id=$writer->create($operation,$values);return $writer->confirmed?$id:false;}catch(PhpbbAclException $e){return false;}finally{if($writer){$writer->release();}}
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 foreach($tables as $suffix=>$table){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$suffix.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical '.$suffix);pds_sql(str_replace('phpbb_'.$suffix,$table,$m[0]));}
 pds_sql("ALTER TABLE fixture_users ADD user_notes VARCHAR(255) DEFAULT 'old default'");pds_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=0;
 foreach(array('root','edit','add') as $actor){$revision=pds_reset($actor);$before=pds_rows('SELECT * FROM fixture_users ORDER BY user_id');ats_check(pds_run($revision,pds_values())===($actor!=='add'),'Exact ACP grant '.$actor);ats_check($before===pds_rows('SELECT * FROM fixture_users ORDER BY user_id'),'No user bytes touched');if($actor!=='add'){$row=pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id=1')[0];ats_check($row['field_column']==='user_notes'&&$row['field_name']==='Renamed notes 😀','Stable physical mapping');}foreach($pds_queries as $sql){ats_check(!preg_match('/^(ALTER|CREATE|DROP|TRUNCATE)\b/',$sql),'No DDL in metadata publication');}$cases++;}
 foreach(array('update','commit','ack') as $failure){$revision=pds_reset();$pds_failure=$failure;ats_check(pds_run($revision,pds_values())===false,'Unconfirmed save not success');$row=pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id=1')[0];ats_check($row['field_name']===($failure==='ack'?'Renamed notes 😀':'user_notes'),'Atomic metadata despite failure '.$failure);$cases++;}
 foreach(array('role','inactive','session','session-admin','session-owner','session-case','grant','ban','stale') as $change){$revision=pds_reset($change==='grant'?'edit':'root');
  $changes=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=2','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','session'=>'DELETE FROM fixture_sessions','session-admin'=>'UPDATE fixture_sessions SET session_admin=0','session-owner'=>'UPDATE fixture_sessions SET session_user_id=7','session-case'=>"UPDATE fixture_sessions SET session_id='EXACT-SESSION'",'grant'=>"UPDATE fixture_jr SET user_jr_admin=''",'stale'=>"UPDATE fixture_profile_fields SET field_description='changed' WHERE field_id=1");
  if($change==='role'){pds_sql("UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2");}if($change==='ban'){pds_insert('fixture_banlist',array('ban_userid'=>2));}else{pds_sql($changes[$change]);}
  $before=pds_rows('SELECT * FROM fixture_profile_fields');ats_check(!pds_run($revision,pds_values())&&$before===pds_rows('SELECT * FROM fixture_profile_fields'),'Reject stale authority/definition '.$change);$cases++;
 }
 foreach(array('role','session','grant','definition','ban') as $change){$revision=pds_reset($change==='grant'?'edit':'root');$blocked=false;$pds_hook=function($sql)use($change,&$blocked){if(strpos($sql,'UPDATE fixture_profile_fields')!==0){return;}$GLOBALS['pds_hook']=null;$queries=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=2','session'=>'DELETE FROM fixture_sessions','grant'=>"UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2",'definition'=>"UPDATE fixture_profile_fields SET field_description='peer' WHERE field_id=1",'ban'=>"INSERT INTO fixture_banlist (ban_userid,ban_ip) VALUES (2,'')");$r=$GLOBALS['peer']->sql_query($queries[$change]);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};ats_check(pds_run($revision,pds_values())&&$blocked,'Concurrent change serialized '.$change);$cases++;}
 foreach(array('role','session','grant') as $change){
  $revision=pds_reset($change==='grant'?'edit':'root');$seen=false;
  $pds_hook=function($sql)use($change,&$seen){if($sql!=='START TRANSACTION'){return;}$GLOBALS['pds_hook']=null;$seen=true;if($change==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2");}elseif($change==='session'){pds_sql('DELETE FROM fixture_sessions');}else{pds_sql("UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2");}};
  ats_check(!pds_run($revision,pds_values())&&$seen,'Reject revocation after preliminary authorization '.$change);$cases++;
 }
 $revision=pds_reset();$pds_hook=function($sql,$writer){if(strpos($sql,'UPDATE fixture_profile_fields')!==0){return;}$GLOBALS['pds_hook']=null;pds_sql('KILL CONNECTION '.(int)$writer->db_connect_id->thread_id);};ats_check(!pds_run($revision,pds_values()),'Disconnected writer cannot succeed');ats_check(pds_rows('SELECT field_name FROM fixture_profile_fields WHERE field_id=1')[0]['field_name']==='user_notes','Killed transaction keeps old definition');$cases++;
 $revision=pds_reset();ats_check(!pds_run($revision,pds_values(),'add'),'Add-scoped owner cannot edit');$cases++;
 $revision=pds_reset();$v=pds_values();$v['field_id']=2;ats_check(!pds_run($revision,$v),'Cannot inject row ID assignment');$cases++;
 $revision=pds_reset();pds_insert('fixture_profile_fields',array_merge(pds_values(),array('field_id'=>3)));ats_check(!pds_run($revision,pds_values()),'Duplicate label rollback');$cases++;
 $revision=pds_reset();pds_insert('fixture_profile_fields',array_merge(pds_values(),array('field_id'=>3,'field_name'=>'Other alias','field_column'=>'user_notes')));ats_check(!pds_run($revision,pds_values()),'Legacy and explicit aliases cannot share ownership');$cases++;
 $revision=pds_reset();$v=pds_values();$v['text_field_maxlen']='1';ats_check(pds_run($revision,$v),'Smaller logical limit allowed');ats_check(strlen(pds_rows('SELECT user_notes FROM fixture_users WHERE user_id=7')[0]['user_notes'])>1,'Existing long values preserved');$cases++;
 foreach(array('success','ddl','ddl-ack','update','commit','ack','role','session','stale','kill','checkbox') as $failure){
  $revision=pds_reset();$v=pds_values();$v['field_type']=$failure==='checkbox'?'3':'1';if($failure==='checkbox'){$v['checkbox_values']='a,0';$v['checkbox_default']='0';}
  $before=pds_rows('SELECT * FROM fixture_users ORDER BY user_id');$pds_failure=$failure;
  if(in_array($failure,array('role','session','stale','kill'),true)){$pds_hook=function($sql,$connection)use($failure){if(strpos($sql,'ALTER TABLE `fixture_users`')!==0){return;}$GLOBALS['pds_hook']=null;if($failure==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2");}elseif($failure==='session'){pds_sql('DELETE FROM fixture_sessions');}elseif($failure==='stale'){pds_sql("UPDATE fixture_profile_fields SET field_description='peer edit' WHERE field_id=1");}else{pds_sql('KILL CONNECTION '.(int)$connection->db_connect_id->thread_id);}};}
  ats_check(pds_run($revision,$v)===in_array($failure,array('success','checkbox'),true),'Widening result '.$failure);
  $row=pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id=1')[0];ats_check($row['field_name']===(in_array($failure,array('success','checkbox','ack'),true)?'Renamed notes 😀':'user_notes'),'No premature metadata publication '.$failure);
  if($failure==='role'){$before[0]['user_level']='0';}ats_check($before===pds_rows('SELECT * FROM fixture_users ORDER BY user_id'),'Widening preserves user bytes '.$failure);
  $c=pds_rows("SHOW COLUMNS FROM fixture_users WHERE Field='user_notes'")[0];ats_check($c['Type']===(in_array($failure,array('ddl','kill'),true)?'varchar(255)':($failure==='checkbox'?'mediumtext':'varchar(1024)')),'Only capacity changed '.$failure);ats_check($c['Default']==="'old default'"||$c['Default']==='old default','Preserve physical default '.$failure);
  $active=false;foreach($pds_queries as $sql){if($sql==='START TRANSACTION'){$active=true;}elseif($sql==='ROLLBACK'||$sql==='COMMIT'){$active=false;}elseif(strpos($sql,'ALTER TABLE `fixture_users`')===0){ats_check(!$active,'Never DDL within publication transaction');}}
  if($failure==='ddl-ack'){$pds_failure='';ats_check(pds_run($revision,$v),'Lost DDL reply can safely retry');}
  pds_sql("ALTER TABLE fixture_users MODIFY user_notes VARCHAR(255) DEFAULT 'old default'");$cases++;
 }
 foreach(array('null','empty','utf8','quoted','comment') as $variant){
  $revision=pds_reset();$default=$variant==='null'?null:($variant==='empty'?'':($variant==='quoted'?"quote' \\ text":'Grüße 😀'));
  $definition=$variant==='null'?'NULL DEFAULT NULL':"NOT NULL DEFAULT '".$peer->sql_escape($default)."'";if($variant==='comment'){$definition.=" COMMENT 'keep comment'";}
  pds_sql('ALTER TABLE fixture_users MODIFY user_notes VARCHAR(255) '.$definition);pds_sql("UPDATE fixture_users SET user_notes='".$peer->sql_escape(str_repeat('😀',200))."' WHERE user_id=7");
  pds_insert('fixture_users',array('user_id'=>98,'username'=>'Before default probe'));$old_default=pds_rows('SELECT user_notes FROM fixture_users WHERE user_id=98')[0]['user_notes'];pds_sql('DELETE FROM fixture_users WHERE user_id=98');
  $before=pds_rows('SELECT * FROM fixture_users ORDER BY user_id');$attributes=pds_rows("SHOW FULL COLUMNS FROM fixture_users WHERE Field='user_notes'")[0];
  $v=pds_values();$v['field_type']='3';$v['checkbox_values']='a,0';ats_check(pds_run($revision,$v),'Widen with existing attributes '.$variant);
  ats_check($before===pds_rows('SELECT * FROM fixture_users ORDER BY user_id'),'UTF8 values unchanged '.$variant);$after=pds_rows("SHOW FULL COLUMNS FROM fixture_users WHERE Field='user_notes'")[0];foreach(array('Null','Collation','Comment') as $key){ats_check($attributes[$key]===$after[$key],'Preserve '.$key.' '.$variant);}
  // SHOW COLUMNS can quote a TEXT literal differently from VARCHAR. Verify
  // actual INSERT behavior, not equality of two metadata representations.
  pds_insert('fixture_users',array('user_id'=>99,'username'=>'Default probe'));$actual=pds_rows('SELECT user_notes FROM fixture_users WHERE user_id=99')[0]['user_notes'];ats_check($actual===$old_default,'New row receives byte-exact default '.$variant.' '.json_encode(array('requested'=>$default,'old_default'=>$old_default,'actual'=>$actual,'before'=>$attributes['Default'],'after'=>$after['Default'])));
  pds_reset();pds_sql("ALTER TABLE fixture_users MODIFY user_notes VARCHAR(255) NULL DEFAULT 'old default' COMMENT ''");$cases++;
 }
 foreach(array('users','sessions','jr_admin_users','banlist','profile_fields') as $suffix){$revision=pds_reset();$table=$tables[$suffix];pds_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');ats_check(!pds_run($revision,pds_values()),'Require transactional '.$suffix);pds_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 $revision=pds_reset();pds_sql("UPDATE fixture_profile_fields SET field_column='user_password' WHERE field_id=1");$revision=phpbb_profile_definition_revision(pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id=1')[0]);ats_check(!pds_run($revision,pds_values()),'Core column cannot be owned by custom definition');$cases++;
 foreach(array('root','add','edit') as $actor){pds_reset($actor);$op=bin2hex(phpbb_random_bytes(32));$v=pds_values();$id=pds_create($op,$v);ats_check(($id!==false)===($actor!=='edit'),'Creation exact delegated module '.$actor);$cases++;}
 foreach(array(0,1,2,3) as $type){
  pds_reset();$op=bin2hex(phpbb_random_bytes(32));$column='cpf_'.substr($op,0,32);$v=pds_values();$v['field_type']=(string)$type;$key=array('text_field_default','text_area_default','radio_button_default','checkbox_default')[$type];$value=$type<2?"Grüße &amp; 😀 \\ 0":($type===2?'0':'0,one');$v[$key]=$value;if($type===2){$v['radio_button_values']='one,0';}if($type===3){$v['checkbox_values']='one,0';}
  $id=pds_create($op,$v);ats_check(is_int($id),'Create type '.$type);$rows=pds_rows('SELECT '.$column.' FROM fixture_users');ats_check(count($rows)===2&&$rows[0][$column]===$value&&$rows[1][$column]===$value,'Atomic defaults for every user '.$type);
  pds_sql('UPDATE fixture_users SET '.$column."='changed by user' WHERE user_id=7");ats_check(pds_create($op,$v)===$id,'Replay returns same published ID');ats_check(pds_rows('SELECT '.$column.' FROM fixture_users WHERE user_id=7')[0][$column]==='changed by user','Replay never resets user values');ats_check(count(pds_rows('SELECT * FROM fixture_profile_fields'))===2,'No duplicate metadata');$cases++;
 }
 foreach(array('stage-insert','stage-commit','stage-ack','ddl','ddl-ack','initialize','publish','receipt','publish-commit','publish-ack') as $failure){
  pds_reset();$op=bin2hex(phpbb_random_bytes(32));$column='cpf_'.substr($op,0,32);$v=pds_values();$v['text_field_default']='Initial 😀';$pds_failure=$failure;
  ats_check(pds_create($op,$v)===false,'Unconfirmed create fails '.$failure);$rows=pds_rows("SELECT * FROM fixture_profile_fields WHERE field_column='$column'");ats_check(count($rows)===($failure==='publish-ack'?1:0),'Definition publication atomic '.$failure);
  $columns=pds_rows("SHOW COLUMNS FROM fixture_users WHERE Field='$column'");if($columns){foreach(pds_rows('SELECT '.$column.' FROM fixture_users') as $row){ats_check($row[$column]===($failure==='publish-ack'?'Initial 😀':null),'No partial defaults '.$failure);}}
  $pds_failure='';$pds_commit_count=0;$id=pds_create($op,$v);ats_check(is_int($id),'Retry completes same operation '.$failure);ats_check(count(pds_rows("SELECT * FROM fixture_profile_fields WHERE field_column='$column'"))===1,'Exactly one field after retry '.$failure);$cases++;
 }
 foreach(array('role','session','grant','definition','kill') as $change){
  pds_reset($change==='grant'?'add':'root');$op=bin2hex(phpbb_random_bytes(32));$column='cpf_'.substr($op,0,32);$v=pds_values();
  $pds_hook=function($sql,$connection)use($change,$v){if(strpos($sql,'ALTER TABLE `fixture_users` ADD COLUMN')!==0){return;}$GLOBALS['pds_hook']=null;if($change==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2");}elseif($change==='session'){pds_sql('DELETE FROM fixture_sessions');}elseif($change==='grant'){pds_sql("UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2");}elseif($change==='definition'){pds_insert('fixture_profile_fields',$v);}else{pds_sql('KILL CONNECTION '.(int)$connection->db_connect_id->thread_id);}};
  ats_check(pds_create($op,$v)===false,'Revocation/change during preparation '.$change);ats_check(!pds_rows("SELECT * FROM fixture_profile_fields WHERE field_column='$column'"),'Not published after preparation change '.$change);$cases++;
 }
 foreach(array('payload','session','foreign-values','marker') as $change){
  pds_reset();$op=bin2hex(phpbb_random_bytes(32));$column='cpf_'.substr($op,0,32);$v=pds_values();$pds_failure='ddl-ack';ats_check(pds_create($op,$v)===false,'Prepared unacknowledged column');$pds_failure='';
  if($change==='payload'){$v['field_name']='Different form';}elseif($change==='session'){pds_sql("UPDATE fixture_sessions SET session_id='other-session'");$userdata['session_id']='other-session';}elseif($change==='foreign-values'){pds_sql('UPDATE fixture_users SET '.$column."='independent value' WHERE user_id=7");}else{pds_sql('ALTER TABLE fixture_users MODIFY '.$column." MEDIUMTEXT NULL COMMENT 'not owned'");}
  ats_check(pds_create($op,$v)===false,'Reject unsafe staged reuse '.$change);ats_check(!pds_rows("SELECT * FROM fixture_profile_fields WHERE field_column='$column'"),'No staged corruption published '.$change);if($change==='foreign-values'){ats_check(pds_rows('SELECT '.$column.' FROM fixture_users WHERE user_id=7')[0][$column]==='independent value','Independent values never overwritten');}$cases++;
 }
 pds_reset();$op=bin2hex(phpbb_random_bytes(32));$column='cpf_'.substr($op,0,32);pds_sql('ALTER TABLE fixture_users ADD '.$column." MEDIUMTEXT NULL COMMENT 'phpbb-profile-job:$op'");ats_check(pds_create($op,pds_values())===false,'Matching marker without journal is not ownership');$cases++;
 pds_reset();pds_sql('ALTER TABLE fixture_profile_field_jobs ENGINE=MyISAM');ats_check(pds_create(bin2hex(phpbb_random_bytes(32)),pds_values())===false,'Creation journal must be transactional');pds_sql('ALTER TABLE fixture_profile_field_jobs ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;
 foreach(array('role','session','grant','journal','definition') as $change){
  pds_reset($change==='grant'?'add':'root');$op=bin2hex(phpbb_random_bytes(32));$blocked=false;
  $pds_hook=function($sql)use($change,$op,&$blocked){if(strpos($sql,'UPDATE fixture_users SET `cpf_')!==0){return;}$GLOBALS['pds_hook']=null;$queries=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=2','session'=>'DELETE FROM fixture_sessions','grant'=>"UPDATE fixture_jr SET user_jr_admin='' WHERE user_id=2",'journal'=>"UPDATE fixture_profile_field_jobs SET job_state='retired' WHERE operation_key='$op'",'definition'=>"UPDATE fixture_profile_fields SET field_name='peer label' WHERE field_id=1");$r=$GLOBALS['peer']->sql_query($queries[$change]);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};
  ats_check(is_int(pds_create($op,pds_values()))&&$blocked,'Publication pins competing '.$change);$cases++;
 }
 pds_reset();$op=bin2hex(phpbb_random_bytes(32));ats_check(pds_create($op,pds_values(),'edit')===false,'Edit-scoped owner cannot create');$cases++;
 pds_reset();$v=pds_values();$v['field_name']='USER_NOTES';ats_check(pds_create(bin2hex(phpbb_random_bytes(32)),$v)===false&&!pds_rows('SELECT * FROM fixture_profile_field_jobs'),'Duplicate label uses database collation before staging');$cases++;
 pds_reset();$op=bin2hex(phpbb_random_bytes(32));$pds_failure='ddl';ats_check(pds_create($op,pds_values())===false,'Durable preparation before failed DDL');$pds_failure='';$collision=substr($op,0,32).str_repeat(substr($op,32,1)==='a'?'b':'a',32);ats_check(pds_create($collision,pds_values())===false&&count(pds_rows('SELECT * FROM fixture_profile_field_jobs'))===1,'Different operation cannot reuse staged physical name');$cases++;
 echo 'Native definition lifecycle: '.$cases." authorization, failure, concurrency, capacity, creation replay and byte-preservation cases passed.\n";
}finally{$pds_hook=null;$main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned schema');$control->sql_close();restore_error_handler();}
