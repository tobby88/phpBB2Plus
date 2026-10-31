<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_READ_FORMS_NATIVE')!=='1'){echo "Native download read-form fixture skipped.\n";return;}
define('IN_PHPBB',true);$pdr_render=in_array('render',$argv,true);if(in_array('legacy',$argv,true)){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';foreach(array('pa_email','pa_license','pa_rate','pa_post_comment')as$module){require$phpbb_root_path.'pafiledb/modules/'.$module.'.php';}
class PdrReached extends RuntimeException{}
class PdrDenied extends RuntimeException{}
function message_die($level,$message){throw new PdrDenied($message);}
function redirect($url){throw new PdrDenied('login');}
function append_sid($url,$html=true){return$url;}
function pdr_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pdr_cases']++;}
class PdrSource extends sql_db{public $queries=array();function sql_query($sql='',$transaction=false){if(!preg_match('/^SELECT\b/i',$sql)){throw new RuntimeException('Read-form fixture forbids every write');}$this->queries[]=$sql;return parent::sql_query($sql,$transaction);}}
class PdrEmail extends pafiledb_email{function generate_category_nav($id){throw new PdrReached('email');}}
class PdrLicense extends pafiledb_license{function generate_category_nav($id){throw new PdrReached('license');}}
require$phpbb_root_path.'pafiledb/includes/template.php';
class PdrRenderedTemplate{public$values=array();function assign_vars($values){$this->values=$values;}}
class PdrNativePageTemplate extends pafiledb_Template{function compile_write(&$handle,$data){return false;}}
class PdrLicenseRendered extends pafiledb_license{
 function generate_category_nav($id){}
 function display($title,$template){
  global$pafiledb_template,$board_config,$pdr_board,$pdr_license_name,$pdr_title;
  $v=$pafiledb_template->values;
  pdr_check(html_entity_decode($v['LE_NAME'],ENT_QUOTES,'UTF-8')===$pdr_license_name&&strpos($v['LE_NAME'],'<b>')===false,'Actual public heading treats raw license names as text');
  pdr_check(html_entity_decode($v['DOWNLOAD'],ENT_QUOTES,'UTF-8')===$pdr_title&&strpos($v['DOWNLOAD'],'<b>')===false,'Actual breadcrumb treats stored download title as text');
  pdr_check(strpos($v['LE_TEXT'],'<p>Grüße &amp; <strong>Terms</strong></p>')!==false&&strpos($v['LE_TEXT'],'<ol><li>One</li></ol>')!==false,'Actual terms preserve paragraphs, lists, entities and Unicode');
  pdr_check(strpos($v['LE_TEXT'],'onclick=')===false&&strpos($v['LE_TEXT'],'<script')===false&&strpos($v['LE_TEXT'],'javascript:')===false&&strpos($v['LE_TEXT'],'style=')===false,'Actual permitted view removes dangerous tags, attributes and URL protocols');
  pdr_check($board_config===$pdr_board&&$v['U_DOWNLOAD']==='dload.php?action=download&file_id=42','Actual render preserves board HTML policy and original download link');
  $page=new PdrNativePageTemplate();$page->root=$GLOBALS['phpbb_root_path'].'templates/fisubsilversh';$page->cachedir=sys_get_temp_dir().'/codex_license_page_no_cache/';$page->force_recompile=true;$page->set_filenames(array('body'=>'pa_license_body.tpl'));
  $page->assign_vars(array_merge($v,array('IS_AUTH_SEARCH'=>false,'IS_AUTH_STATS'=>false,'IS_AUTH_TOPLIST'=>false,'IS_AUTH_UPLOAD'=>false,'IS_AUTH_VIEWALL'=>false,'IS_AUTH_MCP'=>false,'S_JUMPBOX_ACTION'=>'dload.php','L_JUMP'=>'Jump','JUMPMENU'=>'','S_TIMEZONE'=>'')));
  ob_start();try{$page->display('body');$html=ob_get_contents();}finally{ob_end_clean();}
  pdr_check(strpos($html,'<th class="thHead">'.$v['LE_NAME'].'</th>')!==false&&strpos($html,$v['LE_TEXT'])!==false&&strpos($html,'<b>Title</b>')===false,'Actual public PA template inserts the escaped heading and sanitized formatted terms');
  throw new PdrReached('license');
 }
}
class PdrRate extends pafiledb_rate{function generate_category_nav($id){throw new PdrReached('rating');}}
class PdrForm{function pa_generate_smilies($mode,$page){throw new PdrReached('comment');}}
function pdr_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own read-form SQL failed');}return$r;}
function pdr_rows($sql){$r=pdr_sql($sql);try{return$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}}
function pdr_insert($table,$values){foreach(pdr_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pdr_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');}
$port=getenv('PHPBB_PA_READ_FORMS_PORT')?:'3306';$password=getenv('PHPBB_PA_READ_FORMS_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Owned loopback port required');}$host='127.0.0.1:'.$port;
$name='codex_pa_read_forms_'.bin2hex(phpbb_random_bytes(6));$control=new sql_db($host,'root',$password,'',false);$db=$peer=null;$created=false;$pdr_cases=0;$handler=false;
try{
 pdr_check($control->sql_query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')!==false,'Own disposable schema');$created=true;$db=new PdrSource($host,'root',$password,$name,false);$peer=new sql_db($host,'root',$password,$name,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');foreach(array('pa_cat','pa_files','pa_votes','pa_comments','users','pa_license','pa_auth','user_group','groups')as$suffix){pdr_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$schema,$m)===1,'Canonical read participant');pdr_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 foreach(array(8,9)as$id){pdr_insert('fixture_users',array('user_id'=>$id,'username'=>'Own fixture member','user_active'=>1));}
 $pdr_license_name="License O'Reilly \\ &amp; <b>Title</b> Grüße 😀";$pdr_title='Downloads &amp; <b>Literal</b> 😀';$pdr_terms='<p>Grüße &amp; <strong>Terms</strong></p>'."\n".'<ol><li>One</li></ol><p onclick="return false" style="display:none">Visible</p><script>/* inert fixture */</script><a href="javascript:void(0)">Inert</a>';
 foreach(array(1,2)as$id){pdr_insert('fixture_pa_license',array('license_id'=>$id,'license_name'=>$pdr_license_name,'license_text'=>$pdr_terms));}
 $lang=array();require$phpbb_root_path.'language/lang_english/lang_main.php';require$phpbb_root_path.'language/lang_english/lang_pafiledb.php';$board_config=array('sitename'=>'Fixture','allow_html'=>0,'allow_html_tags'=>'b');$pdr_board=$board_config;$pafiledb_config=array('settings_dbname'=>$pdr_title,'auth_toplist'=>0,'auth_search'=>0,'auth_stats'=>0,'auth_viewall'=>0);$pafiledb_template=new PdrRenderedTemplate();$pafiledb_functions=new PdrForm();$_SERVER['REQUEST_METHOD']='GET';$_POST=array();
 $expectations=array('guest-visible'=>array(true,true,true,true),'member-visible'=>array(true,true,true,true),'hidden-leaf'=>array(false,false,false,false),'unreadable-leaf'=>array(false,false,false,false),'hidden-parent'=>array(false,false,false,false),'unreadable-parent'=>array(false,false,false,false),'missing-category'=>array(false,false,false,false),'missing-parent'=>array(false,false,false,false),'cyclic-parent'=>array(false,false,false,false),'file-view-denied'=>array(false,false,false,false),'email-denied'=>array(false,true,true,true),'download-denied'=>array(true,false,true,true),'rating-denied'=>array(true,true,false,true),'comment-denied'=>array(true,true,true,false),'ratings-disabled'=>array(true,true,false,true),'comments-disabled'=>array(true,true,true,false),'mismatched-license'=>array(true,false,true,true),'no-license'=>array(true,false,true,true),'unapproved-guest'=>array(false,false,false,false),'unapproved-member'=>array(false,false,false,false),'unapproved-owner'=>array(false,true,false,false),'unapproved-admin'=>array(false,true,false,false),'unapproved-moderator'=>array(false,true,false,false),'unapproved-stale-guest-admin'=>array(false,false,false,false),'unapproved-owner-hidden-parent'=>array(false,false,false,false));
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});$handler=true;
 foreach($expectations as$case=>$expected){
  foreach(array('pa_cat','pa_files','pa_auth','user_group','groups')as$table){pdr_sql('DELETE FROM fixture_'.$table);}
  $member=strpos($case,'member')!==false||strpos($case,'owner')!==false||in_array($case,array('unapproved-admin','unapproved-moderator'),true);$userdata=array('user_id'=>$member?8:ANONYMOUS,'user_level'=>in_array($case,array('unapproved-admin','unapproved-stale-guest-admin'),true)?ADMIN:USER,'session_logged_in'=>$member);$_REQUEST=array('file_id'=>42,'license_id'=>$case==='mismatched-license'?2:1);
  pdr_insert('fixture_pa_files',array('file_id'=>42,'file_catid'=>5,'file_name'=>'Own file Grüße 😀','user_id'=>strpos($case,'owner')!==false?8:9,'file_approved'=>strpos($case,'unapproved-')===0?0:1,'file_license'=>$case==='no-license'?0:1,'file_dls'=>7));
  if($case!=='missing-parent'){pdr_insert('fixture_pa_cat',array('cat_id'=>6,'cat_parent'=>$case==='cyclic-parent'?5:0,'cat_name'=>'Own parent','auth_view'=>$case==='unapproved-owner-hidden-parent'?AUTH_ADMIN:($case==='hidden-parent'?AUTH_REG:AUTH_ALL),'auth_read'=>$case==='unreadable-parent'?AUTH_REG:AUTH_ALL));}
  if($case!=='missing-category'){pdr_insert('fixture_pa_cat',array('cat_id'=>5,'cat_parent'=>6,'cat_name'=>'Own leaf','parents_data'=>'','cat_allow_ratings'=>$case==='ratings-disabled'?0:1,'cat_allow_comments'=>$case==='comments-disabled'?0:1,'auth_view'=>$case==='hidden-leaf'?AUTH_REG:AUTH_ALL,'auth_read'=>$case==='unreadable-leaf'?AUTH_REG:AUTH_ALL,'auth_view_file'=>$case==='file-view-denied'?AUTH_REG:AUTH_ALL,'auth_email'=>$case==='email-denied'?AUTH_REG:AUTH_ALL,'auth_download'=>$case==='download-denied'?AUTH_REG:AUTH_ALL,'auth_rate'=>$case==='rating-denied'?AUTH_REG:AUTH_ALL,'auth_post_comment'=>$case==='comment-denied'?AUTH_REG:AUTH_ALL));}
  if($case==='unapproved-moderator'){pdr_insert('fixture_groups',array('group_id'=>12,'group_single_user'=>0,'group_name'=>'Own mod group'));pdr_insert('fixture_user_group',array('user_id'=>8,'group_id'=>12,'user_pending'=>0));pdr_insert('fixture_pa_auth',array('cat_id'=>5,'group_id'=>12,'auth_mod'=>1));}
  foreach(array('email','license','rating','comment')as$i=>$route){
   $controller=$route==='email'?new PdrEmail():($route==='license'?($pdr_render?new PdrLicenseRendered():new PdrLicense()):($route==='rating'?new PdrRate():new pafiledb_post_comment()));$controller->init();$db->queries=array();$before=pdr_rows('SELECT * FROM fixture_pa_files');$allowed=false;$denied=false;
   try{$controller->main();}catch(PdrReached$e){$allowed=true;}catch(PdrDenied$e){$denied=true;}
   pdr_check($allowed===$expected[$i]&&$allowed!==$denied,'Actual '.$route.' GET behavior: '.$case);pdr_check($before===pdr_rows('SELECT * FROM fixture_pa_files'),'Actual GET preserves file data and counters');
   if($route==='license'&&!$expected[$i]){foreach($db->queries as$sql){pdr_check(strpos($sql,'FROM '.PA_LICENSE_TABLE)===false,'Denied license route cannot read unrelated terms');}}
  }
 }
 restore_error_handler();$handler=false;echo 'Native download read forms: ',$pdr_cases," actual email/license/rating/comment/approval/ancestry/role/binding/no-write cases PASS; own disposable schema only.\n";
}finally{if($handler){restore_error_handler();}foreach(array($peer,$db)as$c){if($c){$c->sql_close();}}if(!preg_match('/^codex_pa_read_forms_[a-f0-9]{12}$/D',$name)){throw new RuntimeException('Own schema identity required');}if($created){$control->sql_query('DROP DATABASE '.$name);}$control->sql_close();}
