<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_PA_LISTING_NATIVE') !== '1') {
 echo "Native paFileDB listing fixture skipped.\n"; return;
}
define('IN_PHPBB', true);
$project=dirname(dirname(__DIR__)); $phpbb_root_path=$project.'/phpBB2/'; $phpEx='php'; $table_prefix='fixture_';
require $phpbb_root_path.'includes/php_compat.php';
require $phpbb_root_path.'includes/constants.php';
require $phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';
require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'pafiledb/includes/functions.php';
require $phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';
require $phpbb_root_path.'pafiledb/includes/functions_listing.php';
foreach (array('viewall','category','toplist','search') as $action) { require $phpbb_root_path.'pafiledb/modules/pa_'.$action.'.php'; }
class PaListMessage extends RuntimeException {
 public $level;
 function __construct($level,$text) { $this->level=$level; parent::__construct($text); }
}
function message_die($level,$text) { throw new PaListMessage($level,$text); }
function redirect($url) { throw new PaListMessage(GENERAL_MESSAGE,'redirect:'.$url); }
function append_sid($url,$html=true) { return $url.'&sid=fixture-token'; }
function create_date($format,$time,$zone) { return 'fixture date'; }
// Decoration only: exercise the actual controller, scope, SQL and URL normalizer.
function generate_pagination($url,$total,$page,$start) {
 $GLOBALS['pl_pagination']=array('url'=>$url,'total'=>$total,'page'=>$page,'start'=>$start); return 'fixture pagination';
}
function pl_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['pl_cases']++; }
class PaListTemplate {
 public $data=array(),$rows=array();
 function assign_vars($data) { $this->data=array_merge($this->data,$data); }
 function assign_block_vars($block,$data) { $this->rows[$block][]=$data; }
}
class PaListViewall extends pafiledb_viewall { function display($title,$template) {} }
class PaListCategory extends pafiledb_category {
 function display($title,$template) {}
 function generate_category_nav($category) {}
 function category_display($category=0) {}
}
class PaListSource extends sql_db {
 public $queries=0,$fail_at=0,$open=array();
 function sql_query($sql='',$transaction=false) {
  if (preg_match('/^(?:SELECT|SET|START TRANSACTION READ ONLY|ROLLBACK)\b/i',$sql)!==1) { throw new RuntimeException('Listing attempted a write'); }
  $this->queries++;
  if ($this->fail_at && $this->queries===$this->fail_at) { return false; }
  $result=parent::sql_query($sql,$transaction);
  if ($result instanceof mysqli_result) { $this->open[spl_object_hash($result)]=true; }
  return $result;
 }
 function sql_freeresult($result=0) {
  if ($result instanceof mysqli_result) { unset($this->open[spl_object_hash($result)]); }
  return parent::sql_freeresult($result);
 }
}
function pl_sql($sql) {
 $result=$GLOBALS['peer']->sql_query($sql);
 if (!$result) { throw new RuntimeException('Own listing fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error())); }
 return $result;
}
function pl_rows($sql) {
 $result=pl_sql($sql);
 try { $rows=$GLOBALS['peer']->sql_fetchrowset($result); } finally { $GLOBALS['peer']->sql_freeresult($result); }
 foreach ($rows as &$row) { foreach (array_keys($row) as $key) { if (is_int($key)) { unset($row[$key]); } } } unset($row);
 return $rows;
}
function pl_insert($table,$values) {
 foreach (pl_rows('SHOW COLUMNS FROM '.$table) as $column) {
  if (!array_key_exists($column['Field'],$values) && $column['Null']==='NO' && $column['Default']===null && strpos($column['Extra'],'auto_increment')===false) {
   $values[$column['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$column['Type']) ? 0 : '';
  }
 }
 $encoded=array();
 foreach ($values as $value) { $encoded[]=$value===null ? 'NULL' : "'".$GLOBALS['peer']->sql_escape((string)$value)."'"; }
 pl_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');
}
function pl_language($locale) {
 global $lang; $lang=array();
 require $GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_main.php';
 require $GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_pafiledb.php';
}
function pl_seed() {
 foreach (array('pa_files','pa_votes','pa_comments','pa_cat') as $suffix) { pl_sql('DELETE FROM fixture_'.$suffix); }
 foreach (array(5,6) as $id) { pl_insert('fixture_pa_cat',array('cat_id'=>$id,'cat_parent'=>0,'parents_data'=>'','cat_name'=>'Fixture')); }
 foreach (array(
  array(42,5,'<b>Grüße 😀</b>',0,100,1,1),
  array(43,6,'Private fixture',0,200,1,999),
  array(44,5,'Pinned fixture',FILE_PINNED,150,1,4),
  array(48,5,'Unapproved fixture',0,300,0,999),
  array(49,99,'Orphan fixture',0,400,1,999)
 ) as $file) {
  pl_insert('fixture_pa_files',array('file_id'=>$file[0],'file_catid'=>$file[1],'file_name'=>$file[2],'file_pin'=>$file[3],'file_time'=>$file[4],
   'file_approved'=>$file[5],'file_dls'=>$file[6],'file_update_time'=>$file[4],'file_desc'=>'Literal <i>description</i>',
   'file_posticon'=>'none','file_ssurl'=>'','file_sshot_link'=>0,'real_name'=>'fixture.bin','file_dir'=>''));
 }
 foreach (array(9,7) as $vote) { pl_insert('fixture_pa_votes',array('votes_file'=>44,'rate_point'=>$vote)); }
 foreach (array(1,2,3) as $id) { pl_insert('fixture_pa_comments',array('comments_id'=>$id,'file_id'=>44,'comments_title'=>'Fixture','comments_text'=>'Fixture')); }
}
function pl_controller($class='PaListViewall') {
 $module=new $class();
 $module->auth_global=array('auth_viewall'=>true,'auth_viewall_type'=>'fixture');
 $module->auth=array(5=>array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true,'auth_read_type'=>'fixture'),
  6=>array('auth_view'=>false,'auth_read'=>false,'auth_view_file'=>false));
 $module->cat_rowset=array(5=>array('cat_name'=>'<b>Visible category</b>','cat_parent'=>0),6=>array('cat_name'=>'Private category','cat_parent'=>0));
 return $module;
}
function pl_render($module,$request=array()) {
 $_REQUEST=$request; $GLOBALS['_REQUEST']=$_REQUEST;
 $GLOBALS['pafiledb_template']=new PaListTemplate(); $GLOBALS['pl_pagination']=null;
 $module->main(); return $GLOBALS['pafiledb_template'];
}
function pl_ids($template) {
 $ids=array();
 foreach (isset($template->rows['file_rows']) ? $template->rows['file_rows'] : array() as $row) {
  if (preg_match('/file_id=([0-9]+)/',$row['U_FILE'],$match)!==1) { throw new RuntimeException('Actual file URL absent'); }
  $ids[]=(int)$match[1];
 }
 return $ids;
}
$port=getenv('PHPBB_PA_LISTING_PORT') ?: '3306'; $password=getenv('PHPBB_PA_LISTING_PASSWORD') ?: '';
if (!preg_match('/^[0-9]{1,5}$/D',$port) || (int)$port<1 || (int)$port>65535) { throw new RuntimeException('Own loopback fixture port required'); }
$host='127.0.0.1:'.$port; $fixture='codex_pa_list_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false); $db=$peer=null; $created=false; $handler=false; $pl_cases=0;
try {
 pl_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable listing schema'); $created=true;
 $db=new PaListSource($host,'root',$password,$fixture,false); $peer=new sql_db($host,'root',$password,$fixture,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');
 foreach (array('pa_files','pa_votes','pa_comments','pa_cat') as $suffix) {
  pl_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$schema,$match)===1,'Canonical listing participant');
  pl_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$match[0]));
 }
 $db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
 set_error_handler(function($severity,$message) { throw new RuntimeException('Unexpected listing warning: '.$message); }); $handler=true;
 pl_language('english'); pl_seed();
 $board_config=array('default_dateformat'=>'Y-m-d','board_timezone'=>0,'sitename'=>'Fixture');
 $userdata=array('session_logged_in'=>false); $images=array('pa_file_new'=>'fixture-new','folder_sticky'=>'fixture-sticky');
 $pafiledb_config=array('settings_newdays'=>7,'settings_file_page'=>1,'settings_viewall'=>1,'settings_dbname'=>'Fixture','sort_method'=>'file_time','sort_order'=>'DESC');
 $controller=pl_controller(); $db->queries=0;
 $data=pafiledb_listing_read($db,pafiledb_toplist_categories($controller),false,'file_time','DESC',0,1);
 pl_check((int)$data['pinned'][0]['total_votes']===2 && (int)$data['pinned'][0]['total_comments']===3 && (float)$data['pinned'][0]['rating']===8.0,'Votes and comments remain independent under strict grouping');
 pl_check($data['total']===1 && (int)$data['files'][0]['file_id']===42,'Scope applies before count and LIMIT, pinned excluded from ordinary total');
 pl_check($db->queries===3 && !$db->open,'Three native queries release every result');
 $template=pl_render($controller);
 pl_check(pl_ids($template)===array(44,42),'Actual viewall retains pinned and accessible first-page normal file');
 pl_check($pl_pagination['total']===1 && $pl_pagination['page']===1 && $pl_pagination['start']===0,'Actual pagination excludes private/unapproved/orphan/pinned files');
 pl_check($template->rows['file_rows'][1]['FILE_NAME']==='&lt;b&gt;Grüße 😀&lt;/b&gt;' && $template->rows['file_rows'][1]['CAT_NAME']==='&lt;b&gt;Visible category&lt;/b&gt;','Unicode titles and category names remain literal escaped text');
 pl_check($template->rows['file_rows'][1]['FILE_DESC']==='Literal &lt;i&gt;description&lt;/i&gt;','Descriptions retain literal escaping');
 $category=pl_controller('PaListCategory');
 $template=pl_render($category,array('cat_id'=>5));
 pl_check(pl_ids($template)===array(44,42) && strpos($pl_pagination['url'],'action=category&amp;cat_id=5')!==false,'Actual category controller preserves restricted list and category paging URL');
 foreach (array('auth_view','auth_read','auth_view_file') as $field) {
  $controller->auth[5][$field]=false;
  pl_check(pl_ids(pl_render($controller))===array(),'Each denied leaf permission excludes pinned and ordinary rows before count');
  $controller->auth[5][$field]=true;
 }
 foreach (array(6,99,5) as $parent) {
  $controller->cat_rowset[5]['cat_parent']=$parent;
  pl_check(pl_ids(pl_render($controller))===array(),'Denied/missing/cyclic ancestor cannot leak list rows');
 }
 $controller->cat_rowset[5]['cat_parent']=0;
 $empty=pafiledb_listing_read($db,array(5),6,'file_time','DESC',0,1);
 pl_check(!$empty['pinned'] && !$empty['files'] && $empty['total']===0,'Explicit inaccessible category never falls back to all categories');
 $empty=pafiledb_listing_read($db,array(),false,'file_time','DESC',0,1);
 pl_check(!$empty['pinned'] && !$empty['files'] && $empty['total']===0,'Empty allowed scope remains empty');
 pl_sql('UPDATE fixture_pa_files SET file_pin=NULL,file_dls=NULL WHERE file_id=42');
 $template=pl_render($controller);
 pl_check(pl_ids($template)===array(44,42) && (int)$template->rows['file_rows'][1]['FILE_DLS']===0,'Legal NULL pin remains ordinary and NULL downloads display zero');
 foreach (array('https://example.invalid/picture.JPG?a=1&b=2'=>true,'javascript:fixture'=>false,'https://example.invalid/picture.php'=>false,'https://example.invalid/picture.jpg"'=>false) as $url=>$allowed) {
  pl_sql("UPDATE fixture_pa_files SET file_ssurl='".$peer->sql_escape($url)."' WHERE file_id=42");
  $row=pl_render($controller)->rows['file_rows'][1];
  pl_check($row['HAS_SCREENSHOTS']===$allowed && (!$allowed || $row['FILE_SCREENSHOT']==='https://example.invalid/picture.JPG?a=1&amp;b=2'),'Actual shared screenshot URL normalizer and attribute escaping remain active');
 }
 pl_sql("UPDATE fixture_pa_files SET file_posticon='fixture&quot; onerror=&quot;bad',file_ssurl='' WHERE file_id=42");
 $row=pl_render($controller)->rows['file_rows'][1];
 pl_check(strpos($row['PIN_IMAGE'],'"')===false && strpos($row['PIN_IMAGE'],'&amp;quot;')!==false,'Icon path cannot introduce a quoted HTML attribute');
 // Also cover a literal stored quote, independent of entity-shaped filenames.
 pl_sql("UPDATE fixture_pa_files SET file_posticon='fixture\" onerror=\"bad' WHERE file_id=42");
 $row=pl_render($controller)->rows['file_rows'][1];
 pl_check(strpos($row['PIN_IMAGE'],'"')===false && strpos($row['PIN_IMAGE'],'&quot;')!==false,'Literal stored icon quotes are escaped');
 pl_sql('UPDATE fixture_pa_files SET file_pin='.FILE_PINNED.' WHERE file_id=42');
 $template=pl_render($controller,array('start'=>999));
 pl_check(count(pl_ids($template))===2 && $pl_pagination['total']===0 && $template->data['PAGE_NUMBER']===sprintf($lang['Page_of'],1,1),'Pinned-only list stays on page one of one');
 pl_seed();
 pl_insert('fixture_pa_files',array('file_id'=>45,'file_catid'=>5,'file_name'=>'Z fixture','file_pin'=>0,'file_time'=>100,'file_update_time'=>400,'file_dls'=>10,'file_approved'=>1,'file_posticon'=>'none','file_ssurl'=>'','file_desc'=>'','real_name'=>'fixture.bin','file_dir'=>''));
 pl_insert('fixture_pa_votes',array('votes_file'=>42,'rate_point'=>9)); pl_insert('fixture_pa_votes',array('votes_file'=>45,'rate_point'=>2));
 foreach (array('file_name'=>array(42,45),'file_time'=>array(42,45),'file_dls'=>array(42,45),'rating'=>array(45,42),'file_update_time'=>array(42,45)) as $sort=>$ascending) {
  foreach (array('ASC','DESC') as $direction) {
   $data=pafiledb_listing_read($db,array(5),false,$sort,$direction,0,100);
   $actual=array_map(function($row) { return (int)$row['file_id']; },$data['files']);
   pl_check($actual===($direction==='ASC' ? $ascending : array_reverse($ascending)),'Whitelisted list sorts and stable file-id tie order');
  }
 }
 foreach (array('PaListViewall','PaListCategory') as $class) {
  $module=pl_controller($class); $request=array('sort_method'=>'file_rating','sort_order'=>'DESC','cat_id'=>5);
  $first=pl_render($module,$request);
  pl_check(pl_ids($first)===array(44,42) && strpos($pl_pagination['url'],'sort_method=rating')!==false,'Rating selection generates canonical paging sort');
  $request['sort_method']='rating'; $request['start']=1;
  pl_check(pl_ids(pl_render($module,$request))===array(44,45),'Following actual rating pagination preserves sort and pinned row');
 }
 $template=pl_render($controller,array('start'=>999));
 pl_check($pl_pagination['start']===1 && pl_ids($template)===array(44,42),'Stale out-of-range page clamps to last ordinary page');
 $pafiledb_config['sort_method']='file_rating';
 pl_check(pl_ids(pl_render($controller))===array(44,42),'Legacy configured rating alias is normalized');
 $pafiledb_config['sort_method']='invalid'; $pafiledb_config['sort_order']='invalid'; $pafiledb_config['settings_file_page']=0;
 $template=pl_render($controller);
 pl_check(pl_ids($template)===array(44,45) && $pl_pagination['page']===1,'Invalid config falls back to safe sort and positive page size');
 foreach (array(-1,'bad',array(),null) as $size) {
  $pafiledb_config['settings_file_page']=$size;
  pl_check(pl_ids(pl_render($controller))===array(44,45) && $pl_pagination['page']===1,'Malformed configured page size cannot divide by zero or enter SQL');
 }
 $pafiledb_config['settings_file_page']=1000000; $template=pl_render($controller);
 pl_check(pl_ids($template)===array(44,45,42) && $pl_pagination['page']===1000000,'Existing valid administrator page-size range remains supported');
 $pafiledb_config['settings_file_page']=1; $pafiledb_config['sort_method']='file_time'; $pafiledb_config['sort_order']='DESC';
 for ($failure=1;$failure<=3;$failure++) {
  $db->queries=0; $db->fail_at=$failure; $caught=null;
  try { pl_render($controller); } catch (PaListMessage $error) { $caught=$error; } $db->fail_at=0;
  pl_check($caught && $caught->level===GENERAL_ERROR && $caught->getMessage()===$lang['PA_list_unavailable'] && !$db->open,'Each failed read gives a translated error and frees previous results');
 }
 $db->sql_query('START TRANSACTION READ ONLY'); pl_render($controller);
 $result=$db->sql_query('SELECT @@in_transaction active'); $row=$db->sql_fetchrow($result); $db->sql_freeresult($result);
 pl_check((int)$row['active']===1,'Actual list preserves caller-owned read-only transaction'); $db->sql_query('ROLLBACK');
 pl_check(!$db->open,'Caller/source remains open without leaked results');
 foreach (array('english','german') as $locale) {
  pl_language($locale);
  foreach (array('viewall','toplist','search') as $action) {
   $class='pafiledb_'.$action; $module=new $class(); $module->auth_global=array('auth_'.$action=>false,'auth_'.$action.'_type'=>'fixture');
   $userdata['session_logged_in']=true; $caught=null;
   try { pl_render($module); } catch (PaListMessage $error) { $caught=$error; }
   pl_check($caught && $caught->level===GENERAL_MESSAGE && $caught->getMessage()===sprintf($lang['Sorry_auth_'.$action],'fixture'),'Actual member denial has a complete current-language message');
   $userdata['session_logged_in']=false; $caught=null;
   try { pl_render($module); } catch (PaListMessage $error) { $caught=$error; }
   pl_check($caught && strpos($caught->getMessage(),'redirect:login.php?redirect=dload.php?action='.$action)!==false,'Guest denial retains correct actual controller login destination');
  }
 }
 $pafiledb_config['settings_viewall']=0; $caught=null;
 try { pl_render($controller); } catch (PaListMessage $error) { $caught=$error; }
 pl_check($caught && $caught->getMessage()===$lang['viewall_disabled'],'Globally disabled viewall remains disabled'); $pafiledb_config['settings_viewall']=1;
 $category=pl_controller('PaListCategory'); $caught=null;
 try { pl_render($category,array('cat_id'=>999)); } catch (PaListMessage $error) { $caught=$error; }
 pl_check($caught && $caught->getMessage()===$lang['Cat_not_exist'],'Unknown category remains rejected');
 $category->auth[5]['auth_read']=false; $userdata['session_logged_in']=true; $caught=null;
 try { pl_render($category,array('cat_id'=>5)); } catch (PaListMessage $error) { $caught=$error; }
 pl_check($caught && $caught->getMessage()===sprintf($lang['Sorry_auth_view'],'fixture'),'Actual unreadable leaf category remains denied');
 $category->subcat_rowset[5]=array(6=>array()); $category->auth[6]['auth_view']=true;
 $template=pl_render($category,array('cat_id'=>5));
 pl_check(pl_ids($template)===array(),'Folder can show child navigation without exposing unreadable local files');
 pl_sql('DELETE FROM fixture_pa_files'); $template=pl_render($controller);
 pl_check(pl_ids($template)===array() && !empty($template->data['NO_FILE']),'Empty actual list renders without warnings');
 restore_error_handler(); $handler=false;
 echo 'Native paFileDB listings: ',$pl_cases," actual-controller/scope/pagination/strict-grouping/counts/sorts/Unicode/URL/denial/query-cleanup cases PASS; own disposable schema only.\n";
} finally {
 if ($handler) { restore_error_handler(); }
 foreach (array($peer,$db) as $connection) { if ($connection) { $connection->sql_close(); } }
 if (!preg_match('/^codex_pa_list_[a-f0-9]{16}$/D',$fixture)) { throw new RuntimeException('Own listing schema identity required'); }
 if ($created) { $control->sql_query('DROP DATABASE '.$fixture); } $control->sql_close();
}

