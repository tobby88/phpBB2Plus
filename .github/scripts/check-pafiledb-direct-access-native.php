<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_DIRECT_NATIVE')!=='1'){echo "Native direct-download access fixture skipped.\n";return;}
define('IN_PHPBB',true);$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';require$phpbb_root_path.'pafiledb/modules/pa_file.php';require$phpbb_root_path.'pafiledb/modules/pa_download.php';
class PdReached extends RuntimeException{}
class PdDenied extends RuntimeException{}
function message_die($level,$message){throw new PdDenied($message);}
function redirect($url){throw new PdDenied('login');}
function append_sid($url,$html=true){return$url;}
function pd_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pd_cases']++;}
class PdSource extends sql_db{
 public $queries=array();
 function sql_query($sql='',$transaction=false){if(!preg_match('/^SELECT\b/i',$sql)){throw new RuntimeException('Actual direct controllers must not write during access tests');}$this->queries[]=$sql;if(strpos($sql,'FROM '.PA_MIRRORS_TABLE)!==false){throw new PdReached('download');}return parent::sql_query($sql,$transaction);}
}
class PdFile extends pafiledb_file{function generate_category_nav($id){throw new PdReached('file');}}
function pd_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own direct-route fixture SQL failed');}return$r;}
function pd_rows($sql){$r=pd_sql($sql);try{return$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}}
function pd_insert($table,$values){foreach(pd_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pd_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');}
$port=getenv('PHPBB_PA_DIRECT_PORT')?:'3306';$password=getenv('PHPBB_PA_DIRECT_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Owned loopback fixture port required');}$host='127.0.0.1:'.$port;
$name='codex_pa_direct_'.bin2hex(phpbb_random_bytes(6));$control=new sql_db($host,'root',$password,'',false);$db=$peer=null;$created=false;$pd_cases=0;$handler=false;
try{
 pd_check($control->sql_query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')!==false,'Own disposable schema');$created=true;$db=new PdSource($host,'root',$password,$name,false);$peer=new sql_db($host,'root',$password,$name,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');foreach(array('pa_cat','pa_files','pa_votes','pa_comments','users','pa_auth','user_group','groups')as$suffix){pd_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$schema,$m)===1,'Canonical direct-route table');pd_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 foreach(array(8,9)as$id){pd_insert('fixture_users',array('user_id'=>$id,'username'=>'Own fixture member','user_active'=>1));}
 $lang=array();require$phpbb_root_path.'language/lang_english/lang_main.php';require$phpbb_root_path.'language/lang_english/lang_pafiledb.php';$board_config=array('sitename'=>'Own fixture');$pafiledb_config=array('auth_toplist'=>0,'auth_search'=>0,'auth_stats'=>0,'auth_viewall'=>0,'hotlink_prevent'=>0);$_REQUEST=array('file_id'=>42);
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});$handler=true;
 $expectations=array(
  'guest-visible'=>array(true,true,true),'member-visible'=>array(true,true,true),
  'hidden-leaf'=>array(false,false,false),'unreadable-leaf'=>array(false,false,false),
  'hidden-parent'=>array(false,false,false),'unreadable-parent'=>array(false,false,false),
  'file-view-denied'=>array(false,false,false),'download-denied'=>array(true,false,true),
  'missing-category'=>array(false,false,false),'missing-parent'=>array(false,false,false),
  'cyclic-parent'=>array(false,false,false),'negative-parent'=>array(false,false,false),
  'unapproved-guest'=>array(false,false,true),'unapproved-stale-guest-admin'=>array(false,false,true),
  'unapproved-member'=>array(false,false,true),'unapproved-owner'=>array(false,true,true),
  'unapproved-admin'=>array(false,true,true),'unapproved-moderator'=>array(false,true,true),
  'unapproved-owner-hidden-parent'=>array(false,false,false));
 foreach($expectations as$case=>$expected){
  foreach(array('pa_cat','pa_files','pa_auth','user_group','groups')as$table){pd_sql('DELETE FROM fixture_'.$table);}
  $member=strpos($case,'member')!==false||strpos($case,'owner')!==false||$case==='unapproved-admin'||$case==='unapproved-moderator';$level=$case==='unapproved-admin'||$case==='unapproved-stale-guest-admin'?ADMIN:USER;$userdata=array('user_id'=>$member?8:ANONYMOUS,'user_level'=>$level,'session_logged_in'=>$member);
  $unapproved=strpos($case,'unapproved-')===0;$owner=strpos($case,'owner')!==false?8:9;
  pd_insert('fixture_pa_files',array('file_id'=>42,'file_catid'=>5,'user_id'=>$owner,'file_approved'=>$unapproved?0:1,'file_name'=>'Own fixture file','file_dls'=>3));
  if($case!=='missing-parent'){pd_insert('fixture_pa_cat',array('cat_id'=>6,'cat_parent'=>$case==='cyclic-parent'?5:0,'cat_name'=>'Own parent','auth_view'=>$case==='unapproved-owner-hidden-parent'?AUTH_ADMIN:($case==='hidden-parent'?AUTH_REG:AUTH_ALL),'auth_read'=>$case==='unreadable-parent'?AUTH_REG:AUTH_ALL));}
  if($case!=='missing-category'){pd_insert('fixture_pa_cat',array('cat_id'=>5,'cat_parent'=>$case==='negative-parent'?-1:6,'cat_name'=>'Own leaf','parents_data'=>'','auth_view'=>$case==='hidden-leaf'?AUTH_REG:AUTH_ALL,'auth_read'=>$case==='unreadable-leaf'?AUTH_REG:AUTH_ALL,'auth_view_file'=>$case==='file-view-denied'?AUTH_REG:AUTH_ALL,'auth_download'=>$case==='download-denied'?AUTH_REG:AUTH_ALL));}
  if($case==='unapproved-moderator'){pd_insert('fixture_groups',array('group_id'=>12,'group_single_user'=>0,'group_name'=>'Own fixture moderators'));pd_insert('fixture_user_group',array('user_id'=>8,'group_id'=>12,'user_pending'=>0));pd_insert('fixture_pa_auth',array('cat_id'=>5,'group_id'=>12,'auth_mod'=>1));}
  foreach(array('file','download')as$index=>$route){
   $controller=$route==='file'?new PdFile():new pafiledb_download();$controller->init();$scoped=in_array(5,pafiledb_toplist_categories($controller),true);pd_check($scoped===$expected[2],'Shared list scope for '.$case);
   pd_check(pafiledb_file_category_accessible($controller,'5')===$scoped,'Direct helper matches actual current initialized scope');
   $before=pd_rows('SELECT * FROM fixture_pa_files');$db->queries=array();$reached=false;$denied=false;try{$controller->main();}catch(PdReached$error){$reached=true;}catch(PdDenied$error){$denied=true;}
   pd_check($reached===$expected[$index]&&$denied!==$reached,'Actual '.$route.' access for '.$case);
   pd_check($before===pd_rows('SELECT * FROM fixture_pa_files'),'File counters and metadata preserved at access boundary');
   if(!$expected[$index]){foreach($db->queries as$sql){pd_check(strpos($sql,'FROM '.PA_MIRRORS_TABLE)===false,'Denied route does not read mirrors or begin download delivery');}}
  }
 }
 foreach(array(0,-1,2147483648,'05','5x',array(),true,null)as$id){pd_check(!pafiledb_file_category_accessible($controller,$id),'Malformed direct category identity is denied');}
 $approval_module=(object)array('auth'=>array());$approval_file=array('file_approved'=>0,'file_catid'=>5,'user_id'=>9);
 foreach(array(true,1,'1',false,0,'0','false',2,null,array())as$flag){$viewer=array('session_logged_in'=>$flag,'user_id'=>8,'user_level'=>ADMIN);pd_check(pafiledb_download_approval_allowed($approval_module,$approval_file,$viewer)===in_array($flag,array(true,1,'1'),true),'Only strict authenticated flags allow administrator approval exception');}
 foreach(array(0,-1,2147483648,'08','8x',array(),true,null)as$id){pd_check(!pafiledb_download_approval_allowed($approval_module,$approval_file,array('session_logged_in'=>true,'user_id'=>$id,'user_level'=>ADMIN)),'Malformed account identity cannot grant an approval exception');}
 restore_error_handler();$handler=false;echo 'Native direct-download access: ',$pd_cases," actual-init/file/download/ancestry/approval/role/no-write cases PASS; own disposable schema only.\n";
}finally{if($handler){restore_error_handler();}foreach(array($peer,$db)as$c){if($c){$c->sql_close();}}if(!preg_match('/^codex_pa_direct_[a-f0-9]{12}$/D',$name)){throw new RuntimeException('Own schema identity required');}if($created){$control->sql_query('DROP DATABASE '.$name);}$control->sql_close();}
