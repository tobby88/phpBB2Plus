<?php
if(PHP_SAPI!=='cli'||!isset($db,$folder,$custom,$cases)||!function_exists('im_apply')){throw new RuntimeException('Owned icon migration fixture required');}
// Refusals must precede all schema/data changes and restore only owned state.
foreach(array(
 'config-column'=>'ALTER TABLE fixture_post_icon_config ADD unexpected INT NULL',
 'config-index'=>'CREATE INDEX unexpected ON fixture_post_icon_config(schema_version)',
 'config-version'=>'UPDATE fixture_post_icon_config SET schema_version=2',
 'config-bytes'=>"UPDATE fixture_post_icon_config SET config_value='corrupt'",
 'config-row'=>"INSERT INTO fixture_post_icon_config VALUES(2,1,'corrupt')",
 'post-float'=>'ALTER TABLE fixture_posts MODIFY post_icon DOUBLE NULL DEFAULT NULL',
 'topic-text'=>'ALTER TABLE fixture_topics MODIFY topic_icon VARCHAR(20) NULL DEFAULT NULL',
 'post-primary'=>'ALTER TABLE fixture_posts MODIFY body VARCHAR(100) NOT NULL,DROP PRIMARY KEY,ADD PRIMARY KEY(post_id,body)',
 'topic-engine'=>'ALTER TABLE fixture_topics ENGINE=MyISAM',
 'post-row-format'=>'ALTER TABLE fixture_posts ROW_FORMAT=COMPACT',
 'topic-charset'=>'ALTER TABLE fixture_topics CONVERT TO CHARACTER SET latin1'
)as$kind=>$ddl){
 im_reset();if(strpos($kind,'config-')===0){im_apply();}if($kind==='topic-charset'){im_query("UPDATE fixture_topics SET body='ASCII'");}im_query($ddl);$before=im_snapshot();$state=im_state();
 im_check(im_denied(function(){im_apply();})&&im_snapshot()===$before&&im_state()===$state,'Unsupported current storage refuses without changing rows/schema/session: '.$kind);$cases++;
}
// A different live owner keeps its own lease. Failure cannot release it or
// accidentally retain the first migration lease acquired before the conflict.
$other=mysqli_connect('127.0.0.1',$account,$secret,$schema,(int)$port);im_check($other&&mysqli_set_charset($other,'utf8mb4'),'Independent database-local peer');
try{
 foreach(array('plus_innodb_'.sha1($schema),'attachment:'.md5($schema."\0fixture_attachments"))as$lease){
  im_reset();$before=im_snapshot();$state=im_state();$held=plus_storage_rows($other,"SELECT GET_LOCK('$lease',0) n");im_check($held[0]['n']==='1','Peer owns expected lease');
  im_check(im_denied(function(){im_apply();})&&im_snapshot()===$before&&im_state()===$state,'Conflicting external lease refuses unchanged');
  im_check(im_rows("SELECT IS_USED_LOCK('$lease') n")[0]['n']===(string)mysqli_thread_id($other),'Refusal retains the other owner');
  plus_storage_rows($other,"SELECT RELEASE_LOCK('$lease')");foreach(array('plus_innodb_'.sha1($schema),'attachment:'.md5($schema."\0fixture_attachments"))as$released){im_check(im_rows("SELECT IS_FREE_LOCK('$released') n")[0]['n']==='1','No partial migration lease remains');}$cases++;
 }
}finally{mysqli_close($other);}
foreach(array(false,true)as$backup){foreach(array(false,true)as$maintenance){if($backup&&$maintenance){continue;}im_reset();$before=im_snapshot();im_check(im_denied(function()use($backup,$maintenance){plus_icon_storage_apply($GLOBALS['db'],'fixture_',$GLOBALS['folder'],$backup,$maintenance,1);})&&im_snapshot()===$before,'Unconfirmed backup/maintenance never starts DDL');$cases++;}}
foreach(array(0,31,'1',null)as$wait){im_reset();$before=im_snapshot();im_check(im_denied(function()use($wait){plus_icon_storage_apply($GLOBALS['db'],'fixture_',$GLOBALS['folder'],true,true,$wait);})&&im_snapshot()===$before,'Invalid migration wait refuses before mutation');$cases++;}
// Supported wider custom definitions and negative/NULL history are preserved.
foreach(array('SMALLINT UNSIGNED','MEDIUMINT','INT','BIGINT')as$type){
 im_reset();im_query('UPDATE fixture_posts SET post_icon=0 WHERE post_icon<0');im_query('ALTER TABLE fixture_posts MODIFY post_icon '.$type.' NULL DEFAULT NULL COMMENT \'Custom width\'');
 $signature=plus_storage_signature($db,'fixture_posts');$before=im_refs();$result=im_apply();im_check($result['references']===1&&plus_storage_signature($db,'fixture_posts')===$signature&&im_refs()===$before,'Already capable custom width survives exactly: '.$type);$cases++;
}
// The historical PHP definition is imported only when the serialized file is
// absent, without rewriting either representation. Empty old defaults normalize.
im_reset();unlink($folder.'/data/icons.dat');$legacy=$custom;$legacy['special']['POST_NORMAL']['icon']='';$expected=$legacy;$expected['special']['POST_NORMAL']['icon']=0;
$legacy_file=$folder.'/includes/def_icons.php';$legacy_bytes='<?php $icones='.var_export($legacy['icons'],true).';$icon_defined_special='.var_export($legacy['special'],true).';';im_check(file_put_contents($legacy_file,$legacy_bytes)===strlen($legacy_bytes),'Own legacy PHP source');
try{
 $hash=hash_file('sha256',$legacy_file);$result=im_apply();im_check($result['source']==='legacy-php'&&im_rows('SELECT config_value FROM fixture_post_icon_config')[0]['config_value']===serialize($expected)&&hash_file('sha256',$legacy_file)===$hash&&!file_exists($folder.'/data/icons.dat'),'Actual legacy PHP import preserves source and custom values');$cases++;
 im_reset();file_put_contents($folder.'/data/icons.dat','invalid-preferred-file');$before=im_snapshot();im_check(im_denied(function(){im_apply();})&&im_snapshot()===$before&&hash_file('sha256',$legacy_file)===$hash,'Invalid preferred file never falls back to an older valid definition');$cases++;
}finally{unlink($legacy_file);}
im_reset();$legacy=$custom;$legacy['special']['POST_NORMAL']['icon']='';file_put_contents($folder.'/data/icons.dat',serialize($legacy));$hash=hash_file('sha256',$folder.'/data/icons.dat');$result=im_apply();$legacy['special']['POST_NORMAL']['icon']=0;im_check($result['source']==='serialized'&&im_rows('SELECT config_value FROM fixture_post_icon_config')[0]['config_value']===serialize($legacy)&&hash_file('sha256',$folder.'/data/icons.dat')===$hash,'Serialized historical empty default also normalizes without rewriting the file');$cases++;
// A known partial DDL state is resumable: complete only the missing table/width
// and import once, preserving already widened references and all record bytes.
im_reset();list($table,$ddl)=plus_icon_storage_definition('fixture_');im_query($ddl);im_query('ALTER TABLE fixture_posts MODIFY post_icon INT NULL DEFAULT NULL COMMENT \'Größe ä\'');$before=im_refs();$signature=plus_storage_signature($db,'fixture_posts');$result=im_apply();im_check($result===array('created'=>0,'references'=>1,'imported'=>1,'source'=>'serialized')&&im_refs()===$before&&plus_storage_signature($db,'fixture_posts')===$signature,'Resume after a partial schema step without duplicate import or data changes');$cases++;
// Observe the actual CLI waiting for an owned metadata lock, then change the
// owned source file. No production callback or simulated DDL boundary is used.
im_reset();im_config('fixture_');$before=im_refs();$peer=mysqli_connect('127.0.0.1',$account,$secret,$schema,(int)$port);im_check($peer&&mysqli_set_charset($peer,'utf8mb4'),'Metadata-lock peer');$child=null;$observed=false;$result=null;
try{
 plus_storage_query($peer,'START TRANSACTION');$pin=plus_storage_query($peer,'SELECT * FROM fixture_posts LIMIT 0');mysqli_free_result($pin);
 $child=im_cli_start(array('--post-icons-only','--apply','--backup-confirmed','--maintenance-confirmed'));
 $deadline=microtime(true)+2.5;
 do{
  foreach(im_rows("SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE USER='".mysqli_real_escape_string($db,$account)."' AND DB='".mysqli_real_escape_string($db,$schema)."'")as$process){
   if(strpos((string)$process['INFO'],'ALTER TABLE `fixture_posts` MODIFY `post_icon` ')===0&&stripos((string)$process['STATE'],'metadata lock')!==false){$observed=true;break;}
  }
  if($observed){break;}usleep(20000);
 }while(microtime(true)<$deadline);
 if($observed){$changed=$custom;$changed['icons'][0]['img']='images/changed-during-migration.gif';file_put_contents($folder.'/data/icons.dat',serialize($changed));}
 plus_storage_query($peer,'ROLLBACK');$result=im_cli_finish($child);$child=null;
}finally{if($peer){mysqli_close($peer);}if($child!==null){im_cli_finish($child);}}
im_check($observed&&$result[0]!==0&&strpos($result[2],'Legacy icon source changed during import')!==false,'Actual waited CLI refuses a changed source before importing: '.$result[2]);
im_check(im_refs()===$before&&!im_rows('SELECT * FROM fixture_post_icon_config')&&!plus_icon_reference_plan($db,'fixture_',true),'Interrupted import retains exact post/topic bytes and resumable completed widths');
$hash=hash_file('sha256',$folder.'/data/icons.dat');$result=im_apply();im_check($result===array('created'=>0,'references'=>0,'imported'=>1,'source'=>'serialized')&&im_rows('SELECT config_value FROM fixture_post_icon_config')[0]['config_value']===serialize($changed)&&hash_file('sha256',$folder.'/data/icons.dat')===$hash&&im_refs()===$before,'Retry imports only the newly reviewed source and never repeats DDL or rewrites original files');$cases++;
