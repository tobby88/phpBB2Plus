<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_STATS_NATIVE')!=='1'){echo "Native paFileDB statistics fixture skipped.\n";return;}
define('IN_PHPBB',true);$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';require$phpbb_root_path.'pafiledb/includes/functions_stats.php';require$phpbb_root_path.'pafiledb/modules/pa_stats.php';
class PaStatsMessage extends RuntimeException{public $level;function __construct($level,$text){$this->level=$level;parent::__construct($text);}}
function message_die($level,$text){throw new PaStatsMessage($level,$text);}
function append_sid($url,$html=true){return$url.'&sid=fixture-token';}
function redirect($url){throw new PaStatsMessage(GENERAL_MESSAGE,'redirect:'.$url);}
function ps_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['ps_cases']++;}
class PaStatsController extends pafiledb_stats{function display($title,$template){}}
class PaStatsTemplate{public $data=array(),$rows=array();function assign_vars($data){$this->data=array_merge($this->data,$data);}function assign_block_vars($block,$data){$this->rows[$block][]=$data;}}
class PaStatsSource extends sql_db{
 public $queries=0,$fail_at=0,$open=array();
 function sql_query($sql='',$transaction=false){$this->queries++;if($this->fail_at&&$this->queries===$this->fail_at){return false;}$result=parent::sql_query($sql,$transaction);if($result instanceof mysqli_result){$this->open[spl_object_hash($result)]=true;}return$result;}
 function sql_freeresult($query_id=0){if($query_id instanceof mysqli_result){unset($this->open[spl_object_hash($query_id)]);}return parent::sql_freeresult($query_id);}
}
function ps_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own comment fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error()));}return$r;}
function ps_rows($sql){$r=ps_sql($sql);try{$rows=$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}foreach($rows as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return$rows;}
function ps_insert($table,$values){foreach(ps_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}ps_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');}


function ps_seed(){
 foreach(array('pa_files','pa_votes','pa_download_info','pa_cat')as$suffix){ps_sql('DELETE FROM fixture_'.$suffix);}
 foreach(array(5,6)as$id){ps_insert('fixture_pa_cat',array('cat_id'=>$id,'cat_parent'=>0,'parents_data'=>'','cat_name'=>'Fixture'));}
 foreach(array(
  array(42,5,'<b>Grüße 😀 {average}</b>',null,100,1),
  array(44,5,'Second fixture',5,101,1),
  array(45,5,'Unrated fixture',10,102,1),
  array(43,6,'Private fixture',1000,999,1),
  array(48,5,'Unapproved fixture',90000,1000,0),
  array(49,99,'Missing category fixture',90000,1001,1)
 )as$item){ps_insert('fixture_pa_files',array('file_id'=>$item[0],'file_catid'=>$item[1],'file_name'=>$item[2],'file_dls'=>$item[3],'file_time'=>$item[4],'file_approved'=>$item[5],'real_name'=>'fixture.bin','file_dir'=>''));}
 foreach(array(array(42,9,'Win','OPERA'),array(42,7,'Win','OPERA'),array(43,10,'Win','IE'),array(48,10,'Win','IE'))as$v){ps_insert('fixture_pa_votes',array('votes_file'=>$v[0],'rate_point'=>$v[1],'voter_os'=>$v[2],'voter_browser'=>$v[3]));}
 for($i=0;$i<10;$i++){ps_insert('fixture_pa_votes',array('votes_file'=>44,'rate_point'=>2,'voter_os'=>'Unknown fixture OS','voter_browser'=>'Unknown fixture browser'));}
 foreach(array(array(42,'Win','OPERA'),array(42,'Win','OPERA'),array(44,'Unknown fixture OS','Unknown fixture browser'),array(45,'',''),array(43,'Win','IE'),array(48,'Win','IE'),array(99,'Win','IE'))as$d){ps_insert('fixture_pa_download_info',array('file_id'=>$d[0],'downloader_os'=>$d[1],'downloader_browser'=>$d[2]));}
}
function ps_render($controller){$GLOBALS['pafiledb_template']=new PaStatsTemplate();$controller->main();return$GLOBALS['pafiledb_template'];}
function ps_load_language($locale){global$lang;$lang=array();require$GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_main.php';require$GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_pafiledb.php';}
$port=getenv('PHPBB_PA_STATS_PORT')?:'3306';$password=getenv('PHPBB_PA_STATS_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}
$host='127.0.0.1:'.$port;$fixture='codex_pa_stats_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);$db=$peer=null;$created=false;$handler=false;$ps_cases=0;
try{
 ps_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable statistics schema');$created=true;$db=new PaStatsSource($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');foreach(array('pa_files','pa_votes','pa_download_info','pa_cat')as$suffix){ps_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$schema,$m)===1,'Canonical statistics participant');ps_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 $db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");$db->queries=0;
 set_error_handler(function($severity,$message){throw new RuntimeException('Unexpected statistics warning: '.$message);});$handler=true;
 ps_seed();ps_load_language('english');$board_config=array('default_lang'=>'german','sitename'=>'Fixture');$userdata=array('session_logged_in'=>false);$pafiledb_config=array('settings_dbname'=>'Fixture');$images=array('voting_graphic'=>array('fixture-bar'));
 $controller=new PaStatsController();$controller->auth_global=array('auth_stats'=>true,'auth_stats_type'=>'fixture');$controller->auth=array(5=>array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true),6=>array('auth_view'=>false,'auth_read'=>false,'auth_view_file'=>false));$controller->cat_rowset=array(5=>array('cat_parent'=>0),6=>array('cat_parent'=>0));
 $data=pafiledb_stats_read($db,pafiledb_toplist_categories($controller));
 ps_check((int)$data['summary']['total_files']===3&&(int)$data['summary']['total_downloads']===15&&(int)$data['summary']['average_downloads']===5,'Summary excludes private/unapproved/orphan files and counts NULL downloads as zero');
 ps_check((float)$data['summary']['average_rating']===5.0,'Average includes every rated file and weights files, not number of votes');
 ps_check((int)$data['popular']['file_id']===42&&(int)$data['least_popular']['file_id']===44,'Highest/lowest rating selected from accessible files');
 ps_check((int)$data['newest']['file_id']===45&&(int)$data['oldest']['file_id']===42,'Newest/oldest exclude private/unapproved/orphan metadata');
 ps_check((int)$data['most_downloaded']['file_id']===45&&(int)$data['least_downloaded']['file_id']===42,'Download extremes coalesce legal NULL counters');
 ps_check($db->queries===9&&!$db->open,'Nine bounded queries free every native result');
 ps_check($data['downloads']['os']['Win']===2&&$data['downloads']['os']['Other']===2&&array_sum($data['downloads']['os'])===4,'Download chart scope and unknown buckets');
 ps_check($data['ratings']['os']['Win']===2&&$data['ratings']['os']['Other']===10&&array_sum($data['ratings']['browser'])===12,'Rating chart excludes private/unapproved votes and retains unknown metadata');
 foreach(array('english','german')as$locale){
  ps_load_language($locale);$format=$lang['Stats_text'];$rendered=ps_render($controller);$text=$rendered->data['STATS_TEXT'];
  ps_check(strpos($text,'Private fixture')===false&&strpos($text,'Unapproved fixture')===false&&strpos($text,'Missing category fixture')===false,'Actual controller hides every inaccessible filename');
  ps_check(strpos($text,'<b>Grüße')===false&&strpos($text,'&lt;b&gt;Grüße 😀 {average}&lt;/b&gt;')!==false,'Actual controller escapes literal Unicode title and preserves placeholder text');
  ps_check($lang['Stats_text']===$format,'Rendering does not mutate/reload current user-language format');
  ps_check(strpos($text,'href="dload.php?action=file&amp;file_id=')!==false&&strpos($text,'&amp;sid=fixture-token"')!==false,'Localized URL attributes are quoted and escaped');
  ps_check(count($rendered->rows['downloads_os'])===5&&count($rendered->rows['rating_b'])===5,'Unknown client data never creates spurious chart categories');
  ps_check($rendered->rows['downloads_os'][4]['OS_NAME']===$lang['PA_stats_other'],'Other chart bucket follows current language');
 }
 ps_load_language('english');ps_sql('DELETE FROM fixture_pa_files WHERE file_id<>42');$single=pafiledb_stats_read($db,array(5));ps_check((float)$single['summary']['average_rating']===8.0,'Single rated file keeps its nonzero average');$rendered=ps_render($controller);ps_check(strpos($rendered->data['STATS_TEXT'],'The average file rating is 8/10')!==false,'Actual single-file controller displays eight, not zero');ps_check(strpos($rendered->data['STATS_TEXT'],'with 0 downloads')!==false,'Actual controller handles legal NULL download counters without PHP 8.5 deprecations');
 ps_sql('DELETE FROM fixture_pa_votes WHERE votes_file=42');$unrated=pafiledb_stats_read($db,array(5));ps_check((float)$unrated['summary']['average_rating']===0.0&&(int)$unrated['popular']['file_id']===0,'Unrated-only files have zero average and no fake popular file');
 foreach(array('auth_view','auth_read','auth_view_file')as$field){$controller->auth[5][$field]=false;$zero=pafiledb_stats_read($db,pafiledb_toplist_categories($controller));ps_check((int)$zero['summary']['total_files']===0&&array_sum($zero['ratings']['os'])===0,'Each denied leaf permission excludes summary and charts');$controller->auth[5][$field]=true;}
 $controller->cat_rowset[5]['cat_parent']=6;$zero=pafiledb_stats_read($db,pafiledb_toplist_categories($controller));ps_check((int)$zero['summary']['total_files']===0,'Denied ancestor cannot leak statistics');$controller->cat_rowset[5]['cat_parent']=0;
 ps_sql('DELETE FROM fixture_pa_files');$empty=ps_render($controller);ps_check(strpos($empty->data['STATS_TEXT'],'There are 0 files')!==false,'Empty database renders without warnings');$zero=pafiledb_stats_read($db,array());ps_check((int)$zero['summary']['total_files']===0&&$zero['categories']===0,'Explicit empty scope remains empty');
 ps_seed();for($failure=1;$failure<=9;$failure++){$db->queries=0;$db->fail_at=$failure;$caught=false;try{pafiledb_stats_read($db,array(5));}catch(RuntimeException$error){$caught=true;}$db->fail_at=0;ps_check($caught&&!$db->open,'Each SQL failure frees previous results and fails closed');}
 $db->queries=0;$db->fail_at=1;$caught=null;try{ps_render($controller);}catch(PaStatsMessage$error){$caught=$error;}$db->fail_at=0;ps_check($caught&&$caught->level===GENERAL_ERROR&&$caught->getMessage()===$lang['PA_stats_unavailable'],'Actual controller translates a failed read without exposing SQL');
 foreach(array('english','german')as$locale){ps_load_language($locale);ps_check(isset($lang['PA_stats_unavailable'],$lang['PA_stats_other'])&&preg_match('//u',$lang['PA_stats_unavailable'])===1,'Complete statistics translations');}
 $db->sql_query('START TRANSACTION READ ONLY');pafiledb_stats_read($db,array(5));$r=$db->sql_query('SELECT @@in_transaction active');$active=$db->sql_fetchrow($r);$db->sql_freeresult($r);ps_check((int)$active['active']===1,'Statistics preserve caller-owned read-only transaction');$db->sql_query('ROLLBACK');ps_check(!$db->open,'Caller/source remains open with no leaked result');
 foreach(array(array('bad','ASC'),array('file_time','bad'))as$order){$denied=false;try{pafiledb_stats_extreme_sql(array(5),$order[0],$order[1]);}catch(InvalidArgumentException$error){$denied=true;}ps_check($denied,'Unsupported sort/direction cannot enter SQL');}
 $denied=false;try{pafiledb_stats_client_sql(array(5),'bad');}catch(InvalidArgumentException$error){$denied=true;}ps_check($denied,'Unsupported chart kind cannot enter SQL');
 $controller->auth_global['auth_stats']=false;$caught=null;try{ps_render($controller);}catch(PaStatsMessage$error){$caught=$error;}ps_check($caught&&strpos($caught->getMessage(),'action=stats')!==false,'Guest global denial keeps statistics login target');$userdata['session_logged_in']=true;$caught=null;try{ps_render($controller);}catch(PaStatsMessage$error){$caught=$error;}ps_check($caught&&$caught->level===GENERAL_MESSAGE,'Member global denial remains enforced');
 restore_error_handler();$handler=false;
 echo 'Native paFileDB statistics: ',$ps_cases," actual-controller/scope/ancestry/strict-grouping/Unicode/averages/NULL/client-bucket/query-cleanup cases PASS; own disposable schema only.\n";
}finally{if($handler){restore_error_handler();}foreach(array($peer,$db)as$connection){if($connection){$connection->sql_close();}}if(!preg_match('/^codex_pa_stats_[a-f0-9]{16}$/D',$fixture)){throw new RuntimeException('Own statistics schema identity required');}if($created){$control->sql_query('DROP DATABASE '.$fixture);}$control->sql_close();}
