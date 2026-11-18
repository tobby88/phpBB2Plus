<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_SMILIES_NATIVE')!=='1'){echo "Native smiley checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_SMILIES_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_SMILIES_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");if($cut===false){throw new RuntimeException('Shared own-schema fixture required');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_smilies_',$head);
$body= <<<'PHP'
 if(getenv('PHPBB_SMILIES_MYSQL')==='8.4'){$version=phpbb_acl_rows($peer,'SELECT VERSION() v');sd_check(count($version)===1&&strpos($version[0]['v'],'8.4.')===0&&stripos($version[0]['v'],'MariaDB')===false,'Actual requested MySQL8.4 service');}
 define('SMILIES_TABLE','fixture_smilies');define('PHPBB_LEGACY_REQUEST_ESCAPED',getenv('PHPBB_SMILIES_RAW')!=='1');
 require_once$sd_source.'includes/functions_smilies_storage.php';require_once$sd_source.'includes/template.php';
 $core=file_get_contents($sd_source.'includes/functions.php');foreach(array('phpbb_profile_image_name','phpbb_realpath')as$name){sd_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$core,$m)===1,'Actual controller dependency');eval($m[0]);}
 function redirect($url){throw new StyleDataExit('cancel');}
 foreach(array('phpbb_smilies'=>SMILIES_TABLE,'phpbb_config'=>CONFIG_TABLE)as$original=>$target){sd_check(preg_match('/^CREATE TABLE '.$original.' \(.*?;/ms',$schema,$m)===1,'Canonical smiley/config schema');sd_sql(str_replace($original,$target,$m[0]));}
 foreach(array('/images','/images/smiles')as$suffix){sd_check(mkdir($sd_root.$suffix,0700),'Own image directory');$sd_dirs[]=$sd_root.$suffix;}
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII=');
 foreach(array('smile.png'=>$png,'other.png'=>$png,'fixture.pak'=>"other.png=+:Changed=+::same:\nsmile.png=+:New=+::new:\n",'broken.pak'=>"smile.png=+:Valid=+::valid:\nmissing.png=+:Invalid=+::bad:\n")as$name=>$bytes){$path=$sd_root.'/images/smiles/'.$name;file_put_contents($path,$bytes);$sd_files[]=$path;}
 $footer=$sd_root.'/admin/page_footer_admin.php';file_put_contents($footer,'<?php throw new StyleDataExit("view");');$sd_files[]=$footer;
 $theme=array('template_name'=>'fisubsilversh','td_color1'=>'FFFFFF','td_color2'=>'EEEEEE','td_class1'=>'row1','td_class2'=>'row2');$board_config=array('smilies_path'=>'images/smiles','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'xs_add_comments'=>0,'default_lang'=>'english');
 function sm_language($locale){global$lang,$phpEx;$lang=array();require$GLOBALS['sd_source'].'language/lang_'.$locale.'/lang_main.php';require$GLOBALS['sd_source'].'language/lang_'.$locale.'/lang_admin.php';}
 function sm_reset($actor='root'){
  sd_reset($actor==='root'?'root':'none');sd_sql('DELETE FROM '.SMILIES_TABLE);sd_sql('ALTER TABLE '.SMILIES_TABLE.' AUTO_INCREMENT=4');sd_sql('DELETE FROM '.CONFIG_TABLE);
  sd_sql("INSERT INTO fixture_config VALUES('smilies_path','images/smiles')");sd_sql("INSERT INTO fixture_smilies VALUES(1,':old:','smile.png','Old'),(2,':same:','smile.png','Before'),(3,':Case:','smile.png','Case')");
  if($actor!=='root'){$route=$actor==='wrong'?'admin_words.php':'admin_smilies.php';$hash=array_search($route,jr_admin_authorization_routes(),true);sd_check($hash!==false,'Actual registered smiley permission');sd_sql("INSERT INTO fixture_jr VALUES(1,'$hash')");}
 }
 function sm_snapshot(){return phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_smilies ORDER BY smilies_id');}
 function sm_encoded($value){return PHPBB_LEGACY_REQUEST_ESCAPED?addslashes($value):$value;}
 function sm_request($mode){$r=array('sid'=>'fixture-admin');
  if($mode==='import'){$r+=array('import_pack'=>'1','smile_pak'=>'fixture.pak','clear_current'=>'1','replace'=>'1');}
  elseif($mode==='delete'){$r+=array('mode'=>'delete','id'=>'2','confirm'=>'Yes');}
  else{$r+=array('mode'=>$mode==='add'?'savenew':'save','smile_id'=>'2','smile_code'=>sm_encoded("<Grüße'\\😀>"),'smile_emotion'=>sm_encoded("Fröhlich ' \\ 😀"),'smile_url'=>'other.png');}
  return$r;
 }
 function sm_run($post,$get=array()){
  global$db,$userdata,$phpEx,$lang,$template,$phpbb_root_path,$theme,$board_config,$sm_html;
  $_POST=$post;$_GET=$get;$template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($GLOBALS['sd_source'].'templates/fisubsilversh',false);ob_start();
  try{include$GLOBALS['sd_source'].'admin/admin_smilies.php';throw new RuntimeException('Actual smiley controller must terminate');}catch(StyleDataExit$e){return$e->getMessage();}finally{$sm_html=ob_get_clean();}
 }
 function sm_success($message,$mode){$keys=array('add'=>'smiley_add_success','save'=>'smiley_edit_success','delete'=>'smiley_del_success','import'=>'smiley_import_success');return strpos($message,$GLOBALS['lang'][$keys[$mode]])===0;}
 sm_language('english');$cases=$faults=$serialized=$views=0;
 foreach(array('root','delegated')as$actor){foreach(array('add','save','delete','import')as$mode){
  sm_reset($actor);$r=sm_request($mode);$before=sm_snapshot();$result=sm_run($r);sd_check(sm_success($result,$mode),'Actual controller accepts authorized '.$actor.'/'.$mode.': '.$result.' Last SQL: '.json_encode(array_slice($sd_queries,-5)));$expected=sm_snapshot();$queries=$sd_queries;
  if($mode==='import'){sd_check(count($expected)===2&&$expected[0]['code']===':same:'&&$expected[0]['emoticon']==='Changed'&&$expected[1]['code']===':new:','Clear/import accepts complete package only');}
  elseif($mode==='delete'){sd_check($expected===array($before[0],$before[2]),'Delete removes only selected row');}
  else{$row=$expected[$mode==='add'?3:1];sd_check($row['code']==="&lt;Grüße'\\😀&gt;"&&$row['emoticon']==="Fröhlich ' \\ 😀"&&$row['smile_url']==='other.png','Exact UTF-8, angle encoding and one request decoding');sd_check($mode==='add'?array_slice($expected,0,3)===$before:array($expected[0],$expected[2])===array($before[0],$before[2]),'Other smileys retained');}
  sd_unlocked();$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant')as$change){sm_reset($actor);sd_sql(sd_revoke($change));$before=sm_snapshot();sd_check(!sm_success(sm_run($r),$mode)&&sm_snapshot()===$before,'Current authority refuses stale form');sd_unlocked();$cases++;}
  foreach(array('SELECT * FROM '.SMILIES_TABLE.' LIMIT 0', $mode==='delete'?'DELETE FROM '.SMILIES_TABLE.' WHERE smilies_id=':($mode==='save'?'UPDATE '.SMILIES_TABLE.' SET code=':'INSERT INTO '.SMILIES_TABLE.' (code,smile_url,emoticon) SELECT '),'COMMIT')as$boundary){
   sm_reset($actor);$before=sm_snapshot();$seen=$blocked=false;$change=$actor==='root'?'role':'grant';$sd_hook=function($sql)use($boundary,$change,&$seen,&$blocked){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($change))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only real authority lock serializes revocation');$blocked=true;}};
   $out=sm_run($r);sd_check($seen,'Chosen boundary reached');if($blocked){sd_check(sm_success($out,$mode)&&sm_snapshot()===$expected,'Commit precedes serialized authority change');sd_sql(sd_revoke($change));$serialized++;}else{sd_check(!sm_success($out,$mode)&&sm_snapshot()===$before,'Earlier revocation preserves all smileys');}sd_check(!sm_success(sm_run($r),$mode),'Following revoked request denied');sd_unlocked();$cases++;
  }
  if($actor==='root'&&in_array($mode,array('import','save','delete'),true)){foreach(array_unique($queries)as$sql){sm_reset();$before=sm_snapshot();$sd_failure=$sql;$out=sm_run($r);sd_check(!sm_success($out,$mode)&&sm_snapshot()===$before,'Owned SQL failure retains complete original data: '.$sql);sd_unlocked();$faults++;}}
  sm_reset($actor);$sd_failure='lost-ack';sd_check(!sm_success(sm_run($r),$mode)&&sm_snapshot()===$expected,'Lost commit acknowledgement reports uncertainty without a retry');sd_unlocked();$cases++;
 }}
 // This was the destructive original failure: old list cleared, first row saved,
 // second insert fails. Execute the actual controller and preserve exact old rows.
 sm_reset();$before=sm_snapshot();$inserts=0;$sd_hook=function($sql)use(&$inserts){if(strpos($sql,'INSERT INTO fixture_smilies ')===0&&++$inserts===2){$GLOBALS['sd_failure']='INSERT INTO fixture_smilies ';}};sd_check(!sm_success(sm_run(sm_request('import')),'import')&&$inserts===2&&sm_snapshot()===$before,'Second insert rolls back clear plus first insert');sd_unlocked();$cases++;
 foreach(array('0','1')as$replace){sm_reset();$r=sm_request('import');$r['clear_current']='0';$r['replace']=$replace;sd_check(sm_success(sm_run($r),'import'),'Merge mode accepted');$rows=sm_snapshot();sd_check(count($rows)===4&&$rows[1]['emoticon']===($replace==='1'?'Changed':'Before')&&$rows[3]['code']===':new:','Keep/replace existing rows and append new code');$cases++;}
 foreach(array('0','1')as$replace){sm_reset();$pak=$sd_root.'/images/smiles/fixture.pak';$old=file_get_contents($pak);file_put_contents($pak,"other.png=+:First=+::same:=+::case:\nsmile.png=+:Last=+::same:=+::CASE:\n");try{$r=sm_request('import');$r['clear_current']='0';$r['replace']=$replace;sd_check(sm_success(sm_run($r),'import'),'Repeated codes accepted');$rows=sm_snapshot();sd_check(count($rows)===5&&$rows[1]['emoticon']===($replace==='1'?'Last':'Before')&&$rows[2]['code']===':Case:'&&$rows[2]['emoticon']==='Case'&&$rows[3]['code']===':case:'&&$rows[4]['code']===':CASE:','Exact codes remain separate under case-insensitive SQL collation');}finally{file_put_contents($pak,$old);}$cases++;}
 foreach(array('clear_current','replace')as$key){foreach(array(array('1'),true,null,'2','01','yes')as$bad){sm_reset();$r=sm_request('import');$r[$key]=$bad;$before=sm_snapshot();sd_check(!sm_success(sm_run($r),'import')&&sm_snapshot()===$before,'Malformed import flags never clear data');sd_unlocked();$cases++;}}
 foreach(array('smile_code'=>array('',str_repeat('x',51),str_repeat('<',13),"bad\0code",array('x'),null),'smile_emotion'=>array(str_repeat('ü',76),"bad\ntext",array('x'),null),'smile_url'=>array('../smile.png','missing.png','fixture.pak',array('x'),null),'smile_id'=>array('0','2junk','999',array('2'),null))as$key=>$values){foreach($values as$value){sm_reset();$r=sm_request('save');$r[$key]=is_string($value)?sm_encoded($value):$value;$before=sm_snapshot();sd_check(!sm_success(sm_run($r),'save')&&sm_snapshot()===$before,'Malformed/stale smiley input preserves data');sd_unlocked();$cases++;}}
 foreach(array('wrong',array('fixture-admin'))as$bad){sm_reset();$r=sm_request('import');$r['sid']=$bad;$before=sm_snapshot();sd_check(!sm_success(sm_run($r),'import')&&sm_snapshot()===$before,'Exact POST session required');$cases++;}
 sm_reset('wrong');$before=sm_snapshot();sd_check(!sm_success(sm_run(sm_request('import')),'import')&&sm_snapshot()===$before,'Unrelated delegated module cannot import');$cases++;
 foreach(array('broken.pak','../fixture.pak','missing.pak','smile.png')as$name){sm_reset();$r=sm_request('import');$r['smile_pak']=$name;$before=sm_snapshot();sd_check(!sm_success(sm_run($r),'import')&&sm_snapshot()===$before,'Whole package validated before clearing');sd_unlocked();$cases++;}
 sm_reset();$r=sm_request('save');$r['smile_code']=sm_encoded(str_repeat('😀',50));$r['smile_emotion']=sm_encoded(str_repeat('ü',75));sd_check(sm_success(sm_run($r),'save')&&sm_snapshot()[1]['code']===str_repeat('😀',50)&&sm_snapshot()[1]['emoticon']===str_repeat('ü',75),'Limits count UTF-8 characters rather than bytes');$cases++;
 sm_reset();sd_sql('ALTER TABLE fixture_smilies AUTO_INCREMENT=65535');$before=sm_snapshot();sd_check(!sm_success(sm_run(sm_request('import')),'import')&&sm_snapshot()===$before,'Insufficient auto-increment capacity detected before clearing');sd_unlocked();$cases++;
 sm_reset();$before=sm_snapshot();$pak=$sd_root.'/images/smiles/fixture.pak';$old=file_get_contents($pak);$seen=false;$sd_hook=function($sql)use($pak,&$seen){if(strpos($sql,'INSERT INTO fixture_smilies ')===0){$GLOBALS['sd_hook']=null;$seen=true;file_put_contents($pak,"smile.png=+:Changed=+::changed:\n");}};try{sd_check(!sm_success(sm_run(sm_request('import')),'import')&&$seen&&sm_snapshot()===$before,'Changed package rolls back complete import');sd_unlocked();}finally{file_put_contents($pak,$old);}$cases++;
 require dirname($sd_source).'/.github/scripts/smilies-native-fixture.php';
 foreach(array('english','german')as$locale){sm_language($locale);foreach(array('list','add','edit','import','delete','missing','cancel-save','cancel-delete')as$view){sm_reset();$get=$post=array();$_SERVER['REQUEST_METHOD']='GET';if($view==='add'){$get=array('add'=>'1');}elseif($view==='import'){$get=array('import_pack'=>'1');}elseif($view==='edit'||$view==='delete'||$view==='missing'){$get=array('mode'=>$view==='delete'?'delete':'edit','id'=>$view==='missing'?'999':'2');}elseif(strpos($view,'cancel-')===0){$post=sm_request($view==='cancel-save'?'save':'delete');$post['cancel']='1';$_SERVER['REQUEST_METHOD']='POST';}
   if($view==='edit'){sd_sql("UPDATE fixture_smilies SET code='&lt;3',emoticon='Grüße &lt;X&gt;' WHERE smilies_id=2");}
   $before=sm_snapshot();$out=sm_run($post,$get);sd_check(sm_snapshot()===$before,'Actual preview/cancel does not mutate data');if(strpos($view,'cancel-')===0){sd_check($out==='cancel','Cancel bypasses all mutations');}elseif($view==='missing'){sd_check($out!=='view','Missing preview reports a controlled message');}else{sd_check($out==='view'&&strpos($sm_html,'<form')!==false,'Actual standard XS form renders');if($view!=='list'){sd_check(strpos($sm_html,'name="sid" value="fixture-admin"')!==false,'Actual form carries exact session');}}if($view==='edit'){sd_check(strpos($sm_html,'value="&lt;3"')!==false&&strpos($sm_html,'&amp;lt;3')===false,'Encoded angle code edits without accumulating entity encoding');}$views++;
 }}
 echo 'Smilies native: '.$cases.' cases, '.$faults.' SQL faults, '.$serialized.' serialized revocations, '.$views." actual EN/DE XS views PASS.\n";
PHP;
$tail= <<<'PHP'
}finally{$sd_hook=null;$sd_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();}
PHP;
eval($head.$body.$tail);
