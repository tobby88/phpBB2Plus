<?php
// Native ACP ranks audit; only explicitly enabled disposable schemas are used.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_RANKS_NATIVE') !== '1') { echo "Rank checks require an explicitly enabled disposable database.\n"; return; }
$scripts=__DIR__; putenv('PHPBB_STYLE_DATA_NATIVE=1'); putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_RANKS_PORT')?:'3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_RANKS_PASSWORD')?:''));
$fixture_source=file_get_contents($scripts.'/check-style-data-native.php'); $cut=strpos($fixture_source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Fixture boundary missing');}
$head=str_replace('__DIR__',var_export($scripts,true),substr($fixture_source,5,$cut-5)); $head=str_replace('codex_style_data_','codex_ranks_',$head);
$head=str_replace('INSERT INTO fixture_users VALUES','INSERT INTO fixture_users (user_id,user_level,user_active) VALUES',$head);
$body= <<<'PHP'
 define('RANKS_TABLE','fixture_ranks'); require $sd_source.'language/lang_english/lang_admin.php';
 $functions=file_get_contents($sd_source.'includes/functions.php');$a=strpos($functions,'function phpbb_profile_image_name(');$b=strpos($functions,'function phpbb_profile_asset_path(',$a);sd_check($a!==false&&$b!==false,'Actual rank image validator');eval(substr($functions,$a,$b-$a));
 $rank_controller=file_get_contents($sd_source.'admin/admin_ranks.php');$a=strpos($rank_controller,'function admin_ranks_apply(');$b=strpos($rank_controller,'if ($cancel)',$a);
 sd_check($a!==false&&$b!==false,'Actual controller boundary');$declaration=substr($rank_controller,$a,$b-$a);eval($declaration);
 // Load the actual function once, then execute its unchanged call sites for
 // each request. Rendering bootstrap comes from the shared native fixture.
 $rank_controller=str_replace($declaration,'',$rank_controller);$rank_controller=str_replace('__DIR__',var_export($sd_source.'admin',true),$rank_controller);
 sd_sql('ALTER TABLE fixture_users ADD COLUMN user_rank SMALLINT UNSIGNED NOT NULL DEFAULT 0');
 sd_check(preg_match('/CREATE TABLE phpbb_ranks\s*\([\s\S]*?;/', $schema, $definition)===1,'Canonical rank definition');
 sd_sql(str_replace('phpbb_ranks',RANKS_TABLE,$definition[0]));
 function rank_audit_snapshot(){return array(phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_ranks ORDER BY rank_id'),phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_users ORDER BY user_id'));}
 function rank_audit_run($request){global $db,$userdata,$phpEx,$lang,$phpbb_root_path,$template;$_GET=array();$_POST=array_merge(array('sid'=>'fixture-admin'),$request);try{eval(substr($GLOBALS['rank_controller'],5));throw new RuntimeException('Rank controller did not terminate');}catch(StyleDataExit $e){return strpos($e->getMessage(),$lang['Rank_added'])===0||strpos($e->getMessage(),$lang['Rank_updated'])===0||strpos($e->getMessage(),$lang['Rank_removed'])===0;}}
 function rank_audit_reset($actor='root'){sd_reset($actor);sd_sql('DELETE FROM fixture_ranks');sd_sql("INSERT INTO fixture_ranks VALUES (1,'Original',-1,1,'')");sd_sql('INSERT INTO fixture_users VALUES (2,0,1,1)');if($actor==='delegated'){$grant=array_search('admin_ranks.php',jr_admin_authorization_routes(),true);sd_check($grant!==false,'Actual ranks module');sd_sql("UPDATE fixture_jr SET user_jr_admin='".$grant."'");}}
 $requests=array('edit'=>array('mode'=>'save','id'=>'1','title'=>"Größe ' \\ 😀",'special_rank'=>'0','min_posts'=>'10'),
  'delete'=>array('mode'=>'delete','id'=>'1','confirm'=>'1'), 'add'=>array('mode'=>'save','title'=>str_repeat('Ä',50),'special_rank'=>'1'));
 $cases=0;
 foreach(array('root','delegated')as$actor){foreach($requests as$action=>$request){
  rank_audit_reset($actor);sd_check(rank_audit_run($request),'Actual rank '.$actor.'/'.$action);$expected=rank_audit_snapshot();sd_unlocked();$cases++;
  if($action==='edit'){sd_check($expected[0][0]['rank_title']===$request['title']&&(int)$expected[1][1]['user_rank']===0,'Exact Unicode/raw title and user cleanup');}
  if($action==='delete'){sd_check(!$expected[0]&&(int)$expected[1][1]['user_rank']===0,'Deletion clears dependent assignments');}
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant')as$kind){rank_audit_reset($actor);sd_sql(sd_revoke($kind));$before=rank_audit_snapshot();sd_check(!rank_audit_run($request)&&rank_audit_snapshot()===$before,'Revocation denies all rank/user writes: '.$kind);sd_unlocked();$cases++;}
  $failures=array('COMMIT','lost-ack');$failures[]=$action==='add'?'INSERT INTO fixture_ranks':($action==='delete'?'DELETE FROM fixture_ranks':'UPDATE fixture_ranks');if($action!=='add'){$failures[]='UPDATE fixture_users';}
  foreach($failures as$failure){rank_audit_reset($actor);$before=rank_audit_snapshot();$sd_failure=$failure;sd_check(!rank_audit_run($request),'Failed/uncertain rank change cannot announce success');$after=rank_audit_snapshot();if($failure==='lost-ack'&&$action==='add'){sd_check(count($after[0])===2&&$after[0][1]['rank_title']===$request['title']&&$after[1]===$before[1],'Lost insert ACK leaves one committed new rank, not a false success');}else{sd_check($after===($failure==='lost-ack'?$expected:$before),'Rank and user updates commit/rollback together: '.$failure);}sd_unlocked();$cases++;}
  rank_audit_reset($actor);$blocked=false;$sd_hook=function($sql)use(&$blocked,$actor){if($sql!=='COMMIT'){return;}$GLOBALS['sd_hook']=null;sd_check(!$GLOBALS['peer']->sql_query(sd_revoke($actor==='root'?'role':'grant')),'Revocation serialized through actual commit');$error=$GLOBALS['peer']->sql_error();sd_check((int)$error['code']===1205,'Only native row-lock timeout accepted');$blocked=true;};sd_check(rank_audit_run($request)&&$blocked,'Current authority held through commit');sd_unlocked();$cases++;
 }}
 foreach(array(array('title'=>array('bad')),array('title'=>str_repeat('Ä',51)),array('id'=>'-1'),array('id'=>'65536'),array('id'=>'999'),array('special_rank'=>'2'),array('sid'=>'wrong'),array('min_posts'=>'8388608'))as$invalid){rank_audit_reset();$before=rank_audit_snapshot();sd_check(!rank_audit_run(array_merge($requests['edit'],$invalid))&&rank_audit_snapshot()===$before,'Invalid rank request changes nothing');sd_unlocked();$cases++;}
 foreach(array('fixture_ranks','fixture_users','fixture_sessions','fixture_jr')as$table){rank_audit_reset();sd_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=rank_audit_snapshot();sd_check(!rank_audit_run($requests['edit'])&&rank_audit_snapshot()===$before,'Nontransactional table refused before writes');sd_unlocked();sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('edit','delete')as$action){rank_audit_reset();$before=rank_audit_snapshot();$reached=false;$sd_hook=function($sql,$connection)use(&$reached){if(strpos($sql,'UPDATE fixture_users SET user_rank=0 WHERE ')!==0){return;}$GLOBALS['sd_hook']=null;$reached=true;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));};sd_check(!rank_audit_run($requests[$action])&&$reached&&rank_audit_snapshot()===$before,'Lost owner cannot continue paired changes');sd_unlocked();$cases++;}
 // Dedicated capture never completes/rolls back its caller's transaction.
 rank_audit_reset();sd_check($db->sql_query('START TRANSACTION'),'Caller transaction');sd_check($db->sql_query('UPDATE fixture_users SET user_active=0 WHERE user_id=2'),'Caller uncommitted row');
 $source_before=phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx');sd_check(rank_audit_run($requests['add']),'Rank insert using a separate owner');
 sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx')===$source_before,'Original settings/transaction unchanged');
 sd_check((int)phpbb_acl_rows($db,'SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']===0&&(int)phpbb_acl_rows($peer,'SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']===1,'Original transaction still private');sd_check($db->sql_query('ROLLBACK'),'Caller rollback only');sd_unlocked();$cases++;
 rank_audit_reset();class RanksReuseDatabase extends StyleDataDatabase {function sql_dedicated_connection(){return $this;}}$broken=new RanksReuseDatabase($db->inner);$refused=false;
 try{$owner=new PhpbbRanksWriter($broken);$owner->release();}catch(PhpbbAclException $error){$refused=true;}sd_check($refused&&phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id'),'Broken factory refused without closing original');sd_unlocked();$cases++;
 // Request adapter quoting is decoded once, not stored or stripped twice.
 define('PHPBB_LEGACY_REQUEST_ESCAPED',true);rank_audit_reset();$encoded=$requests['edit'];$encoded['title']=addslashes($encoded['title']);sd_check(rank_audit_run($encoded)&&rank_audit_snapshot()[0][0]['rank_title']===$requests['edit']['title'],'Legacy adapter exact title');sd_unlocked();$cases++;
 echo 'Native ranks '.$cases." authority/atomicity cases passed\n";
PHP;
$tail= <<<'PHP'
}finally{
 $db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}
 foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
