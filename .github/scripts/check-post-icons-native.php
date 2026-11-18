<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ICONS_NATIVE')!=='1'){echo "Native post icons require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_ICONS_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_ICONS_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");if($cut===false){throw new RuntimeException('Shared own-schema fixture required');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_post_icons_',$head);
$body= <<<'PHP'
 if(getenv('PHPBB_ICONS_MYSQL')==='8.4'){$version=phpbb_acl_rows($peer,'SELECT VERSION() v');sd_check(count($version)===1&&strpos($version[0]['v'],'8.4.')===0&&stripos($version[0]['v'],'MariaDB')===false,'Actual requested MySQL8.4 service');}
 define('POSTS_TABLE','fixture_posts');define('TOPICS_TABLE','fixture_topics');define('POST_ICON_CONFIG_TABLE','fixture_post_icon_config');define('PHPBB_LEGACY_REQUEST_ESCAPED',getenv('PHPBB_ICONS_RAW')!=='1');
 $constants=file_get_contents($sd_source.'includes/constants.php');preg_match_all('/define\(\x27((?:AUTH|POST)_[A-Z_]+)\x27, (-?[0-9]+)\);/m',$constants,$defs,PREG_SET_ORDER);foreach($defs as$def){if(!defined($def[1])){define($def[1],(int)$def[2]);}}
 $lang_extend_admin=true;require $sd_source.'language/lang_english/lang_main.php';require $sd_source.'language/lang_english/lang_admin.php';require $sd_source.'language/lang_english/lang_extend_post_icons.php';
 require_once $sd_source.'includes/functions_icon_storage.php';require_once dirname(rtrim($sd_source,'/')).'/update/post_icon_storage_migration.php';
 $core=file_get_contents($sd_source.'includes/functions.php');sd_check(preg_match('/^function get_icon_title\(.*?^\}/ms',$core,$helper)===1,'Actual icon renderer');eval($helper[0]);
 foreach(array('posts'=>'post','topics'=>'topic')as$table=>$stem){sd_sql('CREATE TABLE fixture_'.$table.' ('.$stem.'_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'.$stem.'_icon INT NULL DEFAULT NULL,body VARCHAR(100)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 list($icon_table,$icon_ddl)=plus_icon_storage_definition('fixture_');sd_sql($icon_ddl);
 $dir=$sd_root.'/data';sd_check(mkdir($dir,0700),'Owned legacy data');$sd_dirs[]=$dir;$sd_files[]=$dir.'/icons.dat';file_put_contents($dir.'/icons.dat',serialize(phpbb_icon_defaults($sd_source)));$legacy_hash=hash_file('sha256',$dir.'/icons.dat');
 $theme=array('template_name'=>'fisubsilversh');$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'xs_add_comments'=>0,'default_lang'=>'english','icon_per_row'=>10);$images=array('spacer'=>'images/spacer.gif');
 require_once $sd_source.'includes/template.php';$footer=$sd_root.'/admin/page_footer_admin.php';file_put_contents($footer,'<?php throw new StyleDataExit("view");');$sd_files[]=$footer;
 function pi_seed_config(){
  $config=phpbb_icon_defaults($GLOBALS['sd_source']);$config['icons']=array(array('ind'=>0,'img'=>'','alt'=>'icon_none','auth'=>0),array('ind'=>1,'img'=>'images/one.gif','alt'=>'icon_note','auth'=>0),array('ind'=>2,'img'=>'images/two.gif','alt'=>'icon_idea','auth'=>1));
  $config['special']['POST_NORMAL']['icon']=1;return$config;
 }
 function pi_reset($actor='root'){
  sd_reset($actor==='root'?'root':'none');foreach(array('fixture_posts','fixture_topics','fixture_post_icon_config')as$table){sd_sql('DELETE FROM '.$table);}
  sd_sql("INSERT INTO fixture_posts VALUES(1,1,'One'),(2,1,'Two'),(3,2,'Other'),(4,-1,'Unknown'),(5,NULL,'Empty')");
  sd_sql("INSERT INTO fixture_topics VALUES(1,1,'One'),(2,2,'Other'),(3,-1,'Unknown'),(4,NULL,'Empty')");
  sd_sql("INSERT INTO fixture_post_icon_config VALUES(1,1,'".$GLOBALS['peer']->sql_escape(serialize(pi_seed_config()))."')");
  if($actor!=='root'){$route=$actor==='wrong'?'admin_words.php':'admin_icons.php';$hash=array_search($route,jr_admin_authorization_routes(),true);sd_check($hash!==false,'Actual registered icon permission');sd_sql("INSERT INTO fixture_jr VALUES(1,'$hash')");}
 }
 function pi_snapshot(){return array(phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_posts ORDER BY post_id'),phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_topics ORDER BY topic_id'),phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_post_icon_config ORDER BY config_id'));}
 function pi_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Icon fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error()).' SQL='.$sql);}return$r;}
 function pi_request($mode){$r=array('sid'=>'fixture-admin','icon'=>'1');
  if($mode==='delete'){$r+=array('mode'=>'del','confirm'=>'1','replace_icon'=>'2');}
  elseif($mode==='up'||$mode==='down'){$r+=array('mode'=>$mode==='up'?'up':'dw');}
  else{$r+=array('mode'=>'edit','submit'=>'1','icon_title'=>'icon_note','icon_url'=>'images/changed.gif','icon_auth'=>'3','ids'=>array('POST_NORMAL'));if($mode==='add'){unset($r['icon']);}}
  return$r;
 }
 function pi_run($post,$get=array()){
  global$db,$userdata,$phpEx,$lang,$template,$phpbb_root_path,$theme,$board_config,$images,$icones,$icon_defined_special,$map_icon,$pi_html;
  $_POST=$post;$_GET=$get;$template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($GLOBALS['sd_source'].'templates/fisubsilversh',false);ob_start();
  try{include $GLOBALS['sd_source'].'admin/admin_icons.php';throw new RuntimeException('Actual icon controller must terminate');}catch(StyleDataExit$e){return$e->getMessage();}finally{$pi_html=ob_get_clean();}
 }
 $cases=$faults=$serialized=$views=0;
 foreach(array('root','delegated')as$actor){foreach(array('add','edit','delete','up','down')as$mode){
  pi_reset($actor);$before=pi_snapshot();$r=pi_request($mode);sd_check(pi_run($r)==='view','Actual authorized icon controller: '.$actor.'/'.$mode);$expected=pi_snapshot();$queries=$sd_queries;$cfg=phpbb_icon_config_decode($expected[2][0]['config_value']);sd_check($cfg!==false,'Complete persisted icon/default state');
  if($mode==='delete'){sd_check($expected[0][0]['post_icon']==='2'&&$expected[0][1]['post_icon']==='2'&&$expected[1][0]['topic_icon']==='2'&&$cfg['special']['POST_NORMAL']['icon']===2,'All post/topic and special-default references replaced atomically');sd_check(array_slice($expected[0],2)===array_slice($before[0],2)&&array_slice($expected[1],1)===array_slice($before[1],1),'Other, negative and NULL references preserved');}
  else{sd_check($expected[0]===$before[0]&&$expected[1]===$before[1],'Non-deletion leaves post and topic data untouched');}
  sd_check(hash_file('sha256',$GLOBALS['sd_root'].'/data/icons.dat')===$GLOBALS['legacy_hash'],'Runtime never changes the historical file');sd_unlocked();$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant')as$change){pi_reset($actor);sd_sql(sd_revoke($change));$before=pi_snapshot();sd_check(pi_run($r)!=='view'&&pi_snapshot()===$before,'Current authority rejects stale entry');sd_unlocked();$cases++;}
  foreach(array('SELECT * FROM '.POST_ICON_CONFIG_TABLE.' LIMIT 0','UPDATE '.POST_ICON_CONFIG_TABLE.' SET config_value=','COMMIT')as$boundary){
   pi_reset($actor);$before=pi_snapshot();$seen=$blocked=false;$change=$actor==='root'?'role':'grant';$sd_hook=function($sql)use($boundary,$change,&$seen,&$blocked){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($change))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only real authority row lock serializes');$blocked=true;}};
   $out=pi_run($r);sd_check($seen,'Every chosen operation boundary reached');if($blocked){sd_check($out==='view'&&pi_snapshot()===$expected,'Commit precedes serialized authority change');sd_sql(sd_revoke($change));$serialized++;}else{sd_check($out!=='view'&&pi_snapshot()===$before,'Earlier revocation rolls back complete operation');}sd_check(pi_run($r)!=='view','Following revoked request denied');sd_unlocked();$cases++;
  }
  if($actor==='root'&&in_array($mode,array('edit','delete'),true)){
   foreach(array_unique($queries)as$sql){
    // These last two queries render acknowledged committed state, so a read
    // failure there cannot undo a completed operation. Native writer checks
    // cover every owned SQL operation through COMMIT.
    if(stripos($sql,'SELECT post_icon, count(')===0){pi_reset();$sd_failure=$sql;sd_check(pi_run($r)==='view'&&pi_snapshot()===$expected&&strpos($pi_html,$lang['Icons_usage_unavailable'])!==false,'An optional usage read cannot turn an acknowledged save into a failed operation');sd_unlocked();$cases++;continue;}
    pi_reset();$before=pi_snapshot();$sd_failure=$sql;$out=pi_run($r);sd_check($out!=='view'&&pi_snapshot()===$before,'Every owned SQL failure rolls back post/topic/config: '.$sql);sd_unlocked();$faults++;
   }
  }
  pi_reset($actor);$sd_failure='lost-ack';sd_check(pi_run($r)!=='view'&&pi_snapshot()===$expected,'Lost COMMIT acknowledgement reports uncertain completion');sd_unlocked();$cases++;
 }}
 foreach(array('0','1junk','-1','65536',array('1'),null,true)as$bad){pi_reset();$r=pi_request('delete');$r['icon']=$bad;$before=pi_snapshot();sd_check(pi_run($r)!=='view'&&pi_snapshot()===$before,'Invalid or protected target cannot change references');sd_unlocked();$cases++;}
 foreach(array('1','999','-1','65536',array('2'),null,true)as$bad){pi_reset();$r=pi_request('delete');$r['replace_icon']=$bad;$before=pi_snapshot();sd_check(pi_run($r)!=='view'&&pi_snapshot()===$before,'Invalid replacement preserves full prior state');sd_unlocked();$cases++;}
 foreach(array('wrong',array('bad'))as$sid){pi_reset();$r=pi_request('edit');$r['sid']=$sid;$before=pi_snapshot();sd_check(pi_run($r)!=='view'&&pi_snapshot()===$before,'Exact POST session required');sd_unlocked();$cases++;}
 foreach(array('add','edit','delete','up','down')as$mode){pi_reset('wrong');$before=pi_snapshot();sd_check(pi_run(pi_request($mode))!=='view'&&pi_snapshot()===$before,'Unrelated delegated module refused');sd_unlocked();$cases++;}
 foreach(array('icon_title'=>array('',"bad\0key",'key-name',array('bad'),null),'icon_url'=>array('../outside.gif','https://example.invalid/a.png','bad".gif',str_repeat('a',256),array('bad'),null),'icon_auth'=>array('2','1junk','3.5',array('3'),null,true),'ids'=>array(array('UNKNOWN'),array('POST_NORMAL','POST_NORMAL'),array(array('POST_NORMAL')),true))as$field=>$values){foreach($values as$value){pi_reset();$r=pi_request('edit');$r[$field]=PHPBB_LEGACY_REQUEST_ESCAPED&&is_string($value)?addslashes($value):$value;$before=pi_snapshot();sd_check(pi_run($r)!=='view'&&pi_snapshot()===$before,'Malformed complete request is refused');sd_unlocked();$cases++;}}
 require dirname($sd_source).'/.github/scripts/post-icons-native-fixture.php';
 echo 'Post icons native: '.$cases.' cases, '.$faults.' SQL faults, '.$serialized.' serialized revocations, '.$views." actual EN/DE XS views PASS.\n";
PHP;
$tail= <<<'PHP'
}finally{
 $sd_hook=null;$sd_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
