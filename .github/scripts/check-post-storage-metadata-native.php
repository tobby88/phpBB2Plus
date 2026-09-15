<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_POST_METADATA_NATIVE') !== '1') { echo "Native metadata checks require an explicitly enabled disposable database.\n"; return; }
$fixture=file_get_contents(__DIR__.'/check-topic-removal-native.php');
$cut=strpos($fixture,"\n\$body= <<<'PHP'");
if ($cut===false) { throw new RuntimeException('Canonical fixture boundary missing'); }
putenv('PHPBB_TOPIC_REMOVAL_NATIVE=1');
putenv('PHPBB_TOPIC_REMOVAL_PORT='.(getenv('PHPBB_POST_METADATA_PORT')?:'3306'));
putenv('PHPBB_TOPIC_REMOVAL_PASSWORD='.(getenv('PHPBB_POST_METADATA_PASSWORD')?:''));
eval(substr($fixture,5,$cut-5));
$editor=file_get_contents(__DIR__.'/check-full-editor-native.php');
$functions_start=strpos($editor,'function fe_fixture(');$functions_end=strpos($editor,"\n\$body= <<<'PHP'");
if($functions_start===false||$functions_end<=$functions_start){throw new RuntimeException('Full editor fixture functions missing');}
eval(substr($editor,$functions_start,$functions_end-$functions_start));
$head=str_replace('codex_topic_removal_','codex_post_metadata_',$head);
$body= <<<'PHP'
 require $phpbb_root_path.'includes/functions_forum_maintenance.php';
 require $phpbb_root_path.'attach_mod/includes/functions_attach.php';
 $upload_dir=sys_get_temp_dir().'/phpbb-metadata-owned-'.bin2hex(phpbb_random_bytes(8));
 ae_check(mkdir($upload_dir,0700)&&mkdir($upload_dir.'/'.THUMB_DIR,0700),'Owned files');
 $other=$schema.'_other';
 ae_check($control->sql_query('CREATE DATABASE '.$other),'Owned comparison schema');
 try{
  ae_check($control->sql_query('CREATE TABLE '.$other.'.fixture_users (name VARCHAR(10)) ENGINE=MyISAM DEFAULT CHARSET=latin1'),'Unrelated legacy table');
  foreach(array('guest','member','moderator','root') as $actor){foreach(array('newtopic','reply') as $mode){ps_fixture($actor);ae_check(is_array(ps_submit($mode)),'Actual publication with literal metadata scope: '.$actor.'/'.$mode);$cases++;}}
  foreach(array('member','moderator','root') as $actor){fe_fixture($actor);ae_check(is_array(fe_submit()),'Actual editor with literal metadata scope: '.$actor);$cases++;}
  foreach(array('reply','single','poll') as $kind){pd_fixture('root',$kind);ae_check(is_array(pd_submit($kind)),'Actual deletion with literal metadata scope: '.$kind);$cases++;}
  $variants=array(
   array('fixture_users','',true),
   array('fixture_users','ENGINE=MyISAM',false),
   array('fixture_search_wordlist','ROW_FORMAT=COMPACT',false),
   array('fixture_users','DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci',false),
   array('fixture_users','MODIFY username VARCHAR(255) CHARACTER SET latin1',false),
   array('fixture_users','MODIFY username VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',false),
   array('fixture_search_wordlist','MODIFY word_text VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',true),
   array('fixture_search_wordlist','MODIFY word_text VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',true),
   array('fixture_search_wordlist','MODIFY word_text VARCHAR(50) CHARACTER SET latin1 NOT NULL',false)
  );
  foreach($variants as $variant){
   list($table,$alter,$allowed)=$variant;
   // Restore only the previous test's metadata, then its owned fixture rows.
   foreach(array('fixture_users','fixture_search_wordlist') as $reset){ae_sql('ALTER TABLE '.$reset.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
   tr_fixture('moderator');ae_sql("UPDATE fixture_users SET username='Fixture user'");
   if($alter!==''){ae_sql('ALTER TABLE '.$table.' '.$alter);}
   $before=tr_snapshot();$lock=new attach_mutation_lock($db);$owner=null;$outcome='';
   try{$owner=new PhpbbTopicRemovalDatabase($lock->connection,3,'moderator');$owner->begin();$outcome='allowed';}
   catch(PhpbbPostSubmitException $e){$outcome=$e->getMessage();}
   finally{if($owner!==null){$owner->rollback();}$lock->release();}
   ae_check($outcome===($allowed?'allowed':'Moderation_storage_upgrade'),'Actual storage policy: '.$table.'/'.$alter);
   ae_check(tr_snapshot()===$before,'Metadata validation must not mutate contents');
   $query='';foreach($ae_queries as $sql){if(strpos($sql,'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.')===0&&strpos($sql,"AND TABLE_NAME='".$table."'")!==false){$query=$sql;}}
   $scope="c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='".$table."'";
   ae_check($query!==''&&strpos($query,$scope)!==false,'Optimizer receives literal metadata scope');
   $legacy=str_replace($scope,'c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME',$query);
   ae_check(ae_rows($query)===ae_rows($legacy),'Scoped and correlated queries enforce identical column policy');
   $cases++;
  }
  echo 'Native posting metadata: '.$cases." policy/equivalence cases passed; unrelated legacy schema ignored.\n";
 }finally{
  $control->sql_query('DROP DATABASE '.$other);
  foreach(array($upload_dir.'/owned.txt',$upload_dir.'/'.THUMB_DIR.'/t_owned.txt') as $path){if(is_file($path)){unlink($path);}}
  rmdir($upload_dir.'/'.THUMB_DIR);rmdir($upload_dir);
 }
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head.$body);
