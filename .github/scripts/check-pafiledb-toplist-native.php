<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_TOPLIST_NATIVE')!=='1'){echo "Native paFileDB toplist fixture skipped.\n";return;}
define('IN_PHPBB',true);$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';require$phpbb_root_path.'pafiledb/modules/pa_toplist.php';
class PaTopFixtureMessage extends RuntimeException{public $level;function __construct($level,$text){$this->level=$level;parent::__construct($text);}}
function message_die($level,$text){throw new PaTopFixtureMessage($level,$text);}
function append_sid($url,$html=true){return$url;}
function redirect($url){throw new PaTopFixtureMessage(GENERAL_MESSAGE,'redirect:'.$url);}
function pt_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pt_cases']++;}
function create_date($format,$time,$zone){return'fixture date';}
class PaTopFixtureController extends pafiledb_toplist{function display($title,$template){}}
class PaTopFixtureTemplate{public $data=array(),$rows=array();function assign_vars($data){$this->data=array_merge($this->data,$data);}function assign_block_vars($block,$data){$this->rows[$block][]=$data;}}
class PaTopFixtureSource extends sql_db{public $aggregate=null,$last_failure=null;function sql_query($query='',$transaction=false){$r=parent::sql_query($query,$transaction);if(!$r){$this->last_failure=$this->sql_error();}if($r instanceof mysqli_result&&strpos($query,'AS total_comments')!==false){$this->aggregate=mysqli_fetch_assoc($r);mysqli_data_seek($r,0);}return$r;}}
function pt_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own comment fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error()));}return$r;}
function pt_rows($sql){$r=pt_sql($sql);try{$rows=$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}foreach($rows as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return$rows;}
function pt_insert($table,$values){foreach(pt_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pt_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');}

$port=getenv('PHPBB_PA_TOPLIST_PORT')?:'3306';$password=getenv('PHPBB_PA_TOPLIST_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}$host='127.0.0.1:'.$port;$pt_cases=0;
$fixture='codex_pa_top_before_'.bin2hex(phpbb_random_bytes(6));$control=new sql_db($host,'root',$password,'',false);$db=$peer=null;$created=false;
try{
 if(!$control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')){throw new RuntimeException('Own toplist fixture unavailable');}$created=true;$db=new PaTopFixtureSource($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');foreach(array('pa_files','pa_votes','pa_comments','pa_cat','users')as$suffix){if(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\\s*\\([\\s\\S]*?;/m',$schema,$m)!==1){throw new RuntimeException('Canonical toplist table missing');}pt_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 $lang=array();require$phpbb_root_path.'language/lang_english/lang_main.php';require$phpbb_root_path.'language/lang_english/lang_pafiledb.php';$board_config=array('sitename'=>'Fixture','default_dateformat'=>'Y-m-d','board_timezone'=>0);$userdata=array('session_logged_in'=>true);
 $pafiledb_config=array('settings_dbname'=>'Fixture','settings_newdays'=>7);$images=array('pa_file_new'=>'new','folder_sticky'=>'sticky');
 pt_insert('fixture_users',array('user_id'=>8,'username'=>'Fixture submitter'));foreach(array(5,6)as$id){pt_insert('fixture_pa_cat',array('cat_id'=>$id,'cat_name'=>$id===5?'Visible':'Private','cat_parent'=>0,'parents_data'=>''));pt_insert('fixture_pa_files',array('file_id'=>$id===5?42:43,'file_name'=>$id===5?'Visible fixture':'Private fixture','user_id'=>8,'file_catid'=>$id,'file_approved'=>1,'file_dls'=>100,'file_posticon'=>'none','real_name'=>'fixture.bin','file_dir'=>'','file_time'=>time()));foreach(array(9,7)as$vote){pt_insert('fixture_pa_votes',array('votes_file'=>$id===5?42:43,'user_id'=>8,'rate_point'=>$vote));}}
 foreach(array(1,2,3)as$id){pt_insert('fixture_pa_comments',array('comments_id'=>$id,'file_id'=>42,'comments_title'=>'Fixture','comments_text'=>'Fixture'));}
 $controller=new PaTopFixtureController();$controller->auth_global=array('auth_toplist'=>true);$controller->auth=array(5=>array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true),6=>array('auth_view'=>false,'auth_read'=>false,'auth_view_file'=>false));
 $controller->cat_rowset=array(5=>array('cat_id'=>5,'cat_parent'=>0),6=>array('cat_id'=>6,'cat_parent'=>0));
 $db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
 foreach(array('downloads','rating','newest')as$mode){
  $pafiledb_template=new PaTopFixtureTemplate();$_REQUEST=array('mode'=>$mode,'most_num'=>1,'selected_date'=>time());$controller->main();
  $list=isset($pafiledb_template->rows['files_row'])?$pafiledb_template->rows['files_row']:array();
  pt_check(count($list)===1&&$list[0]['FILE_NAME']==='Visible fixture','Actual strict-mode controller exposes only accessible file in '.$mode);
  if($mode==='newest'){pt_check((int)$db->aggregate['total_votes']===2&&(int)$db->aggregate['total_comments']===3,'Actual newest aggregates count two votes and three comments independently');}
 }
 $selected=pt_rows(pafiledb_toplist_files_sql(array(5),'file_time',array(42)));
 pt_check(count($selected)===1&&(float)$selected[0]['rating']===8.0&&(int)$selected[0]['total_votes']===2&&(int)$selected[0]['total_comments']===3,'Actual strict-mode list SQL preserves independent average and counts');
 foreach(array('auth_view','auth_read','auth_view_file')as$permission){
  $controller->auth[5][$permission]=false;
  foreach(array('downloads','rating','newest')as$mode){$pafiledb_template=new PaTopFixtureTemplate();$_REQUEST=array('mode'=>$mode,'selected_date'=>time());$controller->main();pt_check(empty($pafiledb_template->rows['files_row']),'Every actual list honors '.$permission);}
  $controller->auth[5][$permission]=true;
 }
 $controller->cat_rowset[5]['cat_parent']=6;
 foreach(array('downloads','rating','newest')as$mode){$pafiledb_template=new PaTopFixtureTemplate();$_REQUEST=array('mode'=>$mode,'selected_date'=>time());$controller->main();pt_check(empty($pafiledb_template->rows['files_row']),'Denied parent blocks every direct list');}
 $controller->auth[6]=array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true);
 pt_check(pafiledb_toplist_categories($controller)===array(5,6),'Readable parent permits child');
 unset($controller->cat_rowset[6]);pt_check(pafiledb_toplist_categories($controller)===array(),'Missing parent fails closed');
 $controller->cat_rowset[5]['cat_parent']=5;pt_check(pafiledb_toplist_categories($controller)===array(),'Category cycle fails closed');
 $controller->cat_rowset[5]['cat_parent']='bad';pt_check(pafiledb_toplist_categories($controller)===array(),'Invalid parent fails closed');
 $controller->cat_rowset[5]['cat_parent']=0;$controller->auth[6]=array('auth_view'=>false,'auth_read'=>false,'auth_view_file'=>false);
 pt_check(!pt_rows(pafiledb_toplist_files_sql(array(),'file_time')),'Empty category scope returns no rows');
 pt_sql('UPDATE fixture_pa_files SET file_approved=0 WHERE file_id=42');pt_check(!pt_rows(pafiledb_toplist_files_sql(array(5),'file_time')),'Unapproved file excluded');pt_sql('UPDATE fixture_pa_files SET file_approved=1 WHERE file_id=42');
 pt_sql('DELETE FROM fixture_pa_votes WHERE votes_file=42');$unrated=pt_rows(pafiledb_toplist_files_sql(array(5),'file_time'));pt_check(count($unrated)===1&&$unrated[0]['rating']===null&&(int)$unrated[0]['total_votes']===0&&(int)$unrated[0]['total_comments']===3,'Unrated file retains exact comment count');pt_check(!pt_rows(pafiledb_toplist_files_sql(array(5),'rating',array(),1,true)),'Rating-only filter excludes unrated before LIMIT');
 pt_sql('DELETE FROM fixture_users');$missing=pt_rows(pafiledb_toplist_files_sql(array(5),'file_time'));pt_check(count($missing)===1&&pafiledb_toplist_submitter($missing[0])===$lang['Guest'],'Deleted submitter does not remove file or create empty profile link');
 pt_insert('fixture_users',array('user_id'=>8,'username'=>'<b>Literal name</b>'));$named=pt_rows(pafiledb_toplist_files_sql(array(5),'file_time'));$name=pafiledb_toplist_submitter($named[0]);pt_check(strpos($name,'&lt;b&gt;Literal name&lt;/b&gt;')!==false&&strpos($name,'<b>')===false,'Literal submitter name escaped');
 foreach(array(array(array('5'),'file_time',array(),1),array(array(5),'file_time;bad',array(),1),array(array(5),'file_time',array('42'),1),array(array(5),'file_time',array(),-1))as$input){$failed=false;try{pafiledb_toplist_files_sql($input[0],$input[1],$input[2],$input[3]);}catch(InvalidArgumentException$error){$failed=true;}pt_check($failed,'Malformed internal SQL scope/options rejected');}
 $pafiledb_template=new PaTopFixtureTemplate();$_REQUEST=array('mode'=>'newest','days'=>7);$controller->main();pt_check($pafiledb_template->data['TOTAL_FILE_WEEK']===1&&$pafiledb_template->data['TOTAL_FILE_MONTH']===1&&count($pafiledb_template->rows['files_date'])===7,'Calendar summary and days count only accessible files');
 $pafiledb_template=new PaTopFixtureTemplate();$_REQUEST=array('mode'=>'downloads','most_type'=>'per','most_num'=>100);$controller->main();pt_check(count($pafiledb_template->rows['files_row'])===1&&strpos($pafiledb_template->data['L_NEW_FILES'],'1')!==false,'Percentage ranking limits and totals use accessible files only');
 $pafiledb_template=new PaTopFixtureTemplate();$_REQUEST=array('mode'=>'newest','selected_date'=>1);$controller->main();pt_check(empty($pafiledb_template->rows['files_row']),'Empty selected day has no inherited query results');
 $controller->auth_global['auth_toplist']=false;$userdata['session_logged_in']=false;$denied=null;try{$controller->main();}catch(PaTopFixtureMessage$error){$denied=$error;}pt_check($denied&&strpos($denied->getMessage(),'action=toplist')!==false,'Global permission denial returns to correct module after login');
 echo 'Native paFileDB toplists: ',$pt_cases," actual-controller/visibility/ancestors/strict-grouping/count/limit/submitter cases PASS; own disposable schema only.\n";
}finally{foreach(array($peer,$db)as$connection){if($connection){$connection->sql_close();}}if(!preg_match('/^codex_pa_top_before_[a-f0-9]{12}$/D',$fixture)){throw new RuntimeException('Own toplist schema identity required');}if($created){$control->sql_query('DROP DATABASE '.$fixture);}$control->sql_close();}
