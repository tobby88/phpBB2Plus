<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_SEARCH_NATIVE')!=='1'){echo "Native paFileDB search fixture skipped.\n";return;}
define('IN_PHPBB',true);$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';require$phpbb_root_path.'pafiledb/includes/functions_search.php';require$phpbb_root_path.'pafiledb/modules/pa_search.php';
class PaSearchMessage extends RuntimeException{public$level;function __construct($level,$text){$this->level=$level;parent::__construct($text);}}
function message_die($level,$text){throw new PaSearchMessage($level,$text);}
function redirect($url){throw new PaSearchMessage(GENERAL_MESSAGE,'redirect:'.$url);}
function append_sid($url,$html=true){return$url;}
function create_date($format,$time,$zone){return'fixture date';}
function generate_pagination($url,$total,$page,$start){$GLOBALS['se_pagination']=array('url'=>$url,'total'=>$total,'page'=>$page,'start'=>$start);return'fixture pagination';}
function se_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['se_cases']++;}
class PaSearchTemplate{public$data=array(),$rows=array();function assign_vars($data){$this->data=array_merge($this->data,$data);}function assign_block_vars($block,$data){$this->rows[$block][]=$data;}}
class PaSearchController extends pafiledb_search{function display($title,$template){throw new PaSearchMessage(GENERAL_MESSAGE,'display:'.$template);}function jumpmenu_option($cat_id=0,$depth=0,$default='',$for_file=false,$check_upload=false){return'';}}
class PaSearchSource extends sql_db{
 public$queries=0,$fail_at=0,$open=array(),$writes=array(),$sqls=array();
 function sql_query($sql='',$transaction=false){$this->queries++;$this->sqls[]=$sql;if(preg_match('/^(INSERT INTO|UPDATE|DELETE FROM)\s+/i',$sql)){if(!preg_match('/^(INSERT INTO|UPDATE|DELETE FROM)\s+fixture_search_results\b/i',$sql)){throw new RuntimeException('Search mutated non-cache data');}$this->writes[]=$sql;}if($this->fail_at&&$this->queries===$this->fail_at){return false;}$result=parent::sql_query($sql,$transaction);if($result instanceof mysqli_result){$this->open[spl_object_hash($result)]=true;}return$result;}
 function sql_freeresult($result=0){if($result instanceof mysqli_result){unset($this->open[spl_object_hash($result)]);}return parent::sql_freeresult($result);}
}
function se_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own search fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error()));}return$r;}
function se_rows($sql){$r=se_sql($sql);try{$rows=$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}foreach($rows as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return$rows;}
function se_insert($table,$values){foreach(se_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}se_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');}
function se_language($locale){global$lang;$lang=array();require$GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_main.php';require$GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_pafiledb.php';}
function se_seed(){
 foreach(array('pa_files','pa_votes','pa_comments','pa_cat','users','search_results','removed_author_names')as$table){se_sql('DELETE FROM fixture_'.$table);}
 se_insert('fixture_users',array('user_id'=>8,'username'=>'<b>Ü member 😀</b>','user_active'=>1,'user_level'=>USER));
 foreach(array(5,6,7,8,9,10)as$id){
  se_insert('fixture_pa_cat',array('cat_id'=>$id,'cat_parent'=>$id===9?7:0,'cat_name'=>$id===5?'<b>Fixture category</b>':'Fixture','parents_data'=>serialize(array()),
   'auth_view'=>$id===7?AUTH_ADMIN:AUTH_ALL,'auth_read'=>$id===6?AUTH_REG:AUTH_ALL,'auth_view_file'=>$id===8?AUTH_ADMIN:AUTH_ALL,'auth_view_comment'=>$id===10?AUTH_ADMIN:AUTH_ALL));
 }
 foreach(array(
  array(42,5,'<b>Grüße 😀 Alpha</b>',1,8,100),array(43,6,'Read denied Beta',1,8,200),array(44,7,'View denied Beta',1,8,300),
  array(45,8,'File denied Beta',1,8,400),array(46,9,'Hidden ancestor Beta',1,8,500),array(47,5,'Unapproved Beta',0,8,600),
  array(48,99,'Orphan Beta',1,8,700),array(49,5,'Former submitter Alpha',1,9,110),array(50,10,'Comment limited Gamma',1,8,120)
 )as$f){se_insert('fixture_pa_files',array('file_id'=>$f[0],'file_catid'=>$f[1],'file_name'=>$f[2],'file_approved'=>$f[3],'user_id'=>$f[4],'file_time'=>$f[5],'file_update_time'=>$f[5],'file_dls'=>null,'file_pin'=>0,'file_posticon'=>'fixture" marker="literal','file_desc'=>'Literal <i>text</i>','real_name'=>'fixture.bin','file_dir'=>''));}
 foreach(array(9,7)as$point){se_insert('fixture_pa_votes',array('votes_file'=>42,'rate_point'=>$point));}
 foreach(array(array(1,42,'visiblecommentneedle'),array(2,42,'visiblecommentneedle'),array(3,42,'visiblecommentneedle'),array(4,50,'privatecommentneedle'))as$c){se_insert('fixture_pa_comments',array('comments_id'=>$c[0],'file_id'=>$c[1],'comments_title'=>'Fixture','comments_text'=>$c[2]));}
 se_insert('fixture_removed_author_names',array('author_user_id'=>9,'author_name_hash'=>hash('sha256','Former fixture'),'author_name'=>'Former fixture','recorded_at'=>time()));
}
function se_controller(){ $m=new PaSearchController();$m->init();return$m; }
function se_render($get,$post=array(),$module=null){
 $GLOBALS['_GET']=$get;$GLOBALS['_POST']=$post;$GLOBALS['pafiledb_template']=new PaSearchTemplate();$GLOBALS['se_pagination']=null;
 if($module===null){$module=se_controller();}$GLOBALS['db']->queries=0;$GLOBALS['db']->writes=array();$GLOBALS['db']->sqls=array();$caught=null;
 try{$module->main();}catch(PaSearchMessage$error){$caught=$error;}
 return array('template'=>$GLOBALS['pafiledb_template'],'message'=>$caught,'pagination'=>$GLOBALS['se_pagination']);
}
function se_ids($rendered){
 $ids=array();foreach(isset($rendered['template']->rows['searchresults'])?$rendered['template']->rows['searchresults']:array()as$row){if(!preg_match('/file_id=([0-9]+)/',$row['U_FILE'],$m)){throw new RuntimeException('Actual search result link absent');}$ids[]=(int)$m[1];}return$ids;
}
function se_cache($ids,$extra=array(),$sid=null){
 $data=array_merge(array('pafiledb'=>1,'search_results'=>$ids,'split_search'=>array(),'sort_method'=>'file_time','sort_order'=>'DESC'),$extra);$sid=$sid===null?$GLOBALS['userdata']['session_id']:$sid;
 se_sql('DELETE FROM fixture_search_results WHERE search_id=123');se_insert('fixture_search_results',array('search_id'=>123,'session_id'=>$sid,'search_time'=>time(),'search_array'=>serialize($data)));
 return array('search_id'=>'123');
}
function se_no_match($rendered){return$rendered['message']&&$rendered['message']->level===GENERAL_MESSAGE&&$rendered['message']->getMessage()===$GLOBALS['lang']['No_search_match']&&!se_ids($rendered);}
$port=getenv('PHPBB_PA_SEARCH_PORT')?:'3306';$password=getenv('PHPBB_PA_SEARCH_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}
$host='127.0.0.1:'.$port;$fixture='codex_pa_search_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);$db=$peer=null;$created=false;$handler=false;$se_cases=0;
try{
 se_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable search schema');$created=true;$db=new PaSearchSource($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');
 foreach(array('pa_files','pa_votes','pa_comments','pa_cat','pa_auth','users','user_group','groups','search_results','removed_author_names')as$suffix){se_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$schema,$match)===1,'Canonical search participant');se_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$match[0]));}
 $db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException('Unexpected search warning: '.$message);}});$handler=true;
 $board_config=array('default_lang'=>'english','default_dateformat'=>'Y-m-d','board_timezone'=>0,'sitename'=>'Fixture','session_length'=>3600,'hide_removed_author_names'=>1);
 $pafiledb_config=array('sort_method'=>'file_time','sort_order'=>'DESC','settings_file_page'=>10,'settings_newdays'=>7,'settings_dbname'=>'Fixture','auth_search'=>AUTH_ALL,'auth_stats'=>AUTH_ALL,'auth_toplist'=>AUTH_ALL,'auth_viewall'=>AUTH_ALL);
 $userdata=array('user_id'=>ANONYMOUS,'user_level'=>USER,'session_logged_in'=>false,'session_id'=>md5('own fixture session'));
 $images=array('folder_sticky'=>'fixture-sticky','pa_file_new'=>'fixture-new');se_language('english');se_seed();
 $all='42,43,44,45,46,47,48,49,50';$result=se_render(se_cache($all));
 se_check(se_ids($result)===array(50,49,42),'Actual cached search rechecks current view/read/file/ancestor/approval/category before sort');
 se_check($result['pagination']['total']===3,'Actual cached total omits inaccessible/unapproved/orphan identities');
 se_check(!$db->writes&&!$db->open,'Cached path performs no data writes and frees every native result');
 $rows=$result['template']->rows['searchresults'];
 se_check($rows[2]['FILE_NAME']==='&lt;b&gt;Grüße 😀 Alpha&lt;/b&gt;'&&$rows[2]['CAT_NAME']==='&lt;b&gt;Fixture category&lt;/b&gt;','Actual search preserves escaped UTF8 filenames/categories');
 se_check(strpos($rows[2]['FILE_SUBMITER'],'&lt;b&gt;Ü member 😀&lt;/b&gt;')!==false&&strpos($rows[2]['FILE_SUBMITER'],'<b>Ü')===false,'Current submitter attribution escapes literal markup');
 se_check($rows[1]['FILE_SUBMITER']===$lang['Former_member']&&strpos($rows[1]['FILE_SUBMITER'],'href=')===false,'Removed submitter uses common former-member label without phantom profile');
 se_check(strpos($rows[2]['PIN_IMAGE'],'&quot; marker=&quot;literal')!==false&&strpos($rows[2]['PIN_IMAGE'],'" marker="literal')===false,'Stored icon cannot introduce HTML attributes');
 se_check((int)$rows[2]['DOWNLOADS']===0&&$rows[2]['RATING']==='8 / 10','Legal NULL downloads and independent rating mean render');
 $page=pafiledb_listing_rows($db,pafiledb_search_page_sql(array(5),array(42),'file_time','DESC',0,10));
 se_check((int)$page[0]['total_votes']===2&&(int)$page[0]['total_comments']===3,'Search counts two votes and three comments independently');
 se_check(pafiledb_search_input('',500)==='','Empty input remains a string on PHP 5.6 where substr can return false');
 foreach(array('english','german')as$locale){se_language($locale);$result=se_render(array('search_keywords'=>'Alpha'));
  se_check(se_ids($result)===array(49,42),'Actual fresh keyword search works with ONLY_FULL_GROUP_BY ('.$locale.'): '.json_encode(array('ids'=>se_ids($result),'message'=>$result['message']?$result['message']->getMessage():null,'queries'=>$db->sqls)));
  se_check(count($db->writes)===2,'Fresh results write only expiring/updating their own fixture cache');
  $cached=se_rows("SELECT search_array FROM fixture_search_results WHERE session_id='".$peer->sql_escape($userdata['session_id'])."' ORDER BY search_time DESC LIMIT 1");
  $stored=phpbb_safe_unserialize_array($cached[0]['search_array']);se_check(pafiledb_search_ids($stored['search_results'])===array(42,49),'Stored fresh candidates already exclude inaccessible records');
 }
 se_language('english');$result=se_render(array('search_author'=>'*Ü member*'));
 se_check(se_ids($result)===array(50,42),'Actual fresh author search applies readable scope before match limit');
 $result=se_render(array('search_keywords'=>'Alpha','search_author'=>'*Ü member*'));
 se_check(se_ids($result)===array(42),'Combined author/keyword search preserves intersection');
 $result=se_render(array('search_keywords'=>'visiblecommentneedle','comments_search'=>'YES'));se_check(se_ids($result)===array(42),'Allowed comment matches find the file once');
 se_check(se_no_match(se_render(array('search_keywords'=>'privatecommentneedle','comments_search'=>'YES'))),'Comment searches cannot disclose matches through unreadable comments');
 se_check(se_no_match(se_render(array('search_keywords'=>'visiblecommentneedle'))),'Comment text is opt-in');
 foreach(array(array('Alpha Gamma',array(50,49,42)),array('Alpha Gamma','all',array()),array('Alpha OR Gamma','all',array(50,49,42)),array('Alpha NOT Former',array(42)),array('NOT Alpha',array(50)),array('NOT Alpha AND Gamma',array(50)))as$case){
  $get=array('search_keywords'=>$case[0]);$expected=$case[count($case)-1];if(count($case)===3){$get['search_terms']=$case[1];}
  $result=se_render($get);se_check($expected?se_ids($result)===$expected:se_no_match($result),'Actual Boolean search retains OR/AND and honors exclusions');
 }
 se_check(se_no_match(se_render(array('search_keywords'=>'the'))),'Stopword-only input does not become a match-all query');
 se_check(se_no_match(se_render(array('search_keywords'=>"bad\xff"))),'Malformed UTF8 is rejected without SQL errors');
 se_check(pafiledb_search_input(str_repeat('a',99).'😀',100)===str_repeat('a',99),'Text limits preserve complete UTF8 character boundaries');
 $result=se_render(array('search_keywords'=>'Grüße 😀'));se_check(se_ids($result)===array(42),'Unicode query matches literal UTF8 filename');
 foreach(array(6,7,8,9,999)as$category){se_check(se_no_match(se_render(array('search_keywords'=>'Beta','cat_id'=>$category))),'Explicit denied/missing category never expands to all categories');}
 $result=se_render(array('search_keywords'=>'Alpha','cat_id'=>5));se_check(se_ids($result)===array(49,42),'Explicit readable category remains scoped');
 $pafiledb_config['settings_file_page']=1;$get=se_cache($all);$result=se_render($get);
 se_check(se_ids($result)===array(50)&&$result['pagination']['total']===3,'Cached private identities cannot consume a visible first-page slot');
 $get['start']=1;$result=se_render($get);se_check(se_ids($result)===array(49),'Actual second page uses refreshed candidates');
 $get['start']=999;$result=se_render($get);se_check(se_ids($result)===array(42)&&$result['pagination']['start']===2,'Stale page clamps to last accessible page');
 $pafiledb_config['settings_file_page']=0;$result=se_render(se_cache($all));se_check(se_ids($result)===array(50)&&$result['pagination']['page']===1,'Zero page size cannot divide by zero');
 $pafiledb_config['settings_file_page']=10;
 $result=se_render(se_cache('0,42,42,2147483648,1 OR 1=1,-1,999999'));se_check(se_ids($result)===array(42),'Malformed/duplicate/overflow/deleted cached IDs cannot enter result SQL or totals');
 se_check(se_no_match(se_render(se_cache($all,array(),md5('other own fixture session')))),'Search cache belongs to the exact current session');
 $get=se_cache($all);se_sql('UPDATE fixture_pa_cat SET auth_read='.AUTH_ADMIN.' WHERE cat_id=5');$result=se_render($get);
 se_check(se_ids($result)===array(50)&&$result['pagination']['total']===1,'Cached results lose access immediately after category read grant changes');
 se_sql('UPDATE fixture_pa_cat SET auth_read='.AUTH_ALL.' WHERE cat_id=5');se_sql('UPDATE fixture_pa_files SET file_approved=0 WHERE file_id=42');$result=se_render(se_cache('42'));se_check(se_no_match($result),'Cached approval withdrawal is enforced');
 se_sql('UPDATE fixture_pa_files SET file_approved=1 WHERE file_id=42');se_sql('UPDATE fixture_pa_cat SET cat_parent=5 WHERE cat_id=5');se_check(se_no_match(se_render(se_cache('42,49'))),'Cyclic category cannot expose cached results');se_sql('UPDATE fixture_pa_cat SET cat_parent=0 WHERE cat_id=5');
 $userdata['session_logged_in']=true;$userdata['user_id']=8;$result=se_render(se_cache($all));se_check(se_ids($result)===array(43,50,49,42),'Actual member initialization retains current registered-only read grant');
 se_check($result['template']->rows['searchresults'][2]['FILE_SUBMITER']===$lang['Guest'],'Members retain prior missing-account guest attribution');
 $userdata['session_logged_in']=false;$userdata['user_id']=ANONYMOUS;
 for($failure=1;$failure<=3;$failure++){
  $get=se_cache('42');$module=se_controller();$db->fail_at=$failure;$result=se_render($get,array(),$module);$db->fail_at=0;
  se_check($result['message']&&$result['message']->level===GENERAL_ERROR&&$result['message']->getMessage()===$lang['PA_search_unavailable']&&!$db->open,'Each cached read failure closes prior resources and returns translated failure');
 }
 // A cache read participates in, but never commits, the caller's transaction.
 $get=se_cache('42');$module=se_controller();$db->sql_query('START TRANSACTION READ ONLY');$result=se_render($get,array(),$module);$r=$db->sql_query('SELECT @@in_transaction active');$active=$db->sql_fetchrow($r);$db->sql_freeresult($r);
 se_check(se_ids($result)===array(42)&&(int)$active['active']===1&&!$db->writes,'Actual cached controller preserves caller read-only transaction');$db->sql_query('ROLLBACK');se_check(!$db->open,'No leaked native query resources');
 // Literal SQL/wildcard characters in names retain their exact meaning.
 foreach(array(10=>"O'Reilly%_Tester",11=>"O'ReillyABTester")as$id=>$name){se_insert('fixture_users',array('user_id'=>$id,'username'=>$name,'user_active'=>1,'user_level'=>USER));se_insert('fixture_pa_files',array('file_id'=>$id+41,'file_catid'=>5,'file_name'=>$id===10?'exactauthorneedle':'otherauthorneedle','file_approved'=>1,'user_id'=>$id,'file_time'=>0,'file_pin'=>0,'file_posticon'=>'none','file_desc'=>'','real_name'=>'fixture.bin','file_dir'=>''));}
 se_check(se_ids(se_render(array('search_author'=>"O'Reilly%_Tester")))===array(51),'Author quote is SQL-escaped and literal percent/underscore are not wildcards');
 se_check(se_ids(se_render(array('search_author'=>"O'Reilly*")))===array(52,51),'Explicit author star wildcard remains supported');
 se_check(se_ids(se_render(array('search_author'=>"O'Reilly*",'search_keywords'=>'exactauthorneedle')))===array(51),'Combined author pattern and keyword filter remain scoped before matching');
 se_check(se_no_match(se_render(se_cache($all,array('pafiledb'=>0)))),'Generic forum caches cannot be read as download caches');
 se_sql('UPDATE fixture_search_results SET search_array=\'invalid serialization\' WHERE search_id=123');se_check(se_no_match(se_render(array('search_id'=>123))),'Malformed serialized cache returns no search match');
 se_insert('fixture_pa_files',array('file_id'=>53,'file_catid'=>5,'file_name'=>null,'file_desc'=>null,'file_approved'=>1,'file_time'=>0,'file_pin'=>0,'file_posticon'=>'none','real_name'=>'fixture.bin','file_dir'=>''));
 se_check(se_ids(se_render(array('search_keywords'=>'NOT Alpha')))===array(50,53,52,51),'NOT includes readable files whose text fields are NULL');
 se_check(se_ids(se_render(array('search_keywords'=>'exact*needle')))===array(51),'Inner keyword wildcard retains its matching meaning');
 $result=se_render(se_cache($all,array('sort_method'=>'rating','sort_order'=>'DESC')));se_check(se_ids($result)===array(42,50,49),'Cached rating order uses its validated aggregate alias and stable ties');
 // Private matches must not fill the old 12,000-row candidate cap.
 se_sql('START TRANSACTION');$values=array();for($i=1000;$i<=13000;$i++){$values[]='('.$i.",7,'capacityneedle','fixture.bin','',1,0)";if(count($values)===500){se_sql('INSERT INTO fixture_pa_files (file_id,file_catid,file_name,real_name,file_dir,file_approved,file_time) VALUES'.implode(',',$values));$values=array();}}
 if($values){se_sql('INSERT INTO fixture_pa_files (file_id,file_catid,file_name,real_name,file_dir,file_approved,file_time) VALUES'.implode(',',$values));}
 se_insert('fixture_pa_files',array('file_id'=>99999,'file_catid'=>5,'file_name'=>'capacityneedle','file_approved'=>1,'user_id'=>8,'file_time'=>0,'file_pin'=>0,'file_posticon'=>'none','file_desc'=>'','real_name'=>'fixture.bin','file_dir'=>''));
 se_sql('COMMIT');$result=se_render(array('search_keywords'=>'capacityneedle'));se_check(se_ids($result)===array(99999)&&$result['pagination']['total']===1,'12,001 inaccessible matches cannot hide the later accessible result');
 se_sql('START TRANSACTION');$values=array();for($i=13001;$i<=25001;$i++){$values[]='('.$i.",5,'commonneedle','fixture.bin','',1,0)";if(count($values)===500){se_sql('INSERT INTO fixture_pa_files (file_id,file_catid,file_name,real_name,file_dir,file_approved,file_time) VALUES'.implode(',',$values));$values=array();}}
 if($values){se_sql('INSERT INTO fixture_pa_files (file_id,file_catid,file_name,real_name,file_dir,file_approved,file_time) VALUES'.implode(',',$values));}se_sql("UPDATE fixture_pa_files SET file_name='capacityneedle commonneedle uniqueneedle' WHERE file_id=99999");se_sql('COMMIT');
 $result=se_render(array('search_keywords'=>'commonneedle uniqueneedle','search_terms'=>'all'));se_check(se_ids($result)===array(99999),'Apply the full Boolean condition before candidate limit, not a separate cap per word');
 restore_error_handler();$handler=false;echo 'Native paFileDB search: ',$se_cases," actual-init/controller/scope/cache/Boolean/strict-SQL/Unicode/approval/paging/session/candidate-cap cases PASS; own disposable schema only.\n";
}finally{
 if($handler){restore_error_handler();}foreach(array($peer,$db)as$connection){if($connection){$connection->sql_close();}}
 if(!preg_match('/^codex_pa_search_[a-f0-9]{16}$/D',$fixture)){throw new RuntimeException('Own search schema identity required');}if($created){$control->sql_query('DROP DATABASE '.$fixture);}$control->sql_close();
}

