<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_CATEGORY_NATIVE')!=='1'){echo "Native download category checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);define('GENERAL_ERROR',1);define('PA_CATEGORY_TABLE','fixture_pa_cat');
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require$root.'includes/php_compat.php';require$root.'db/mysqli.php';require$root.'pafiledb/includes/functions.php';require$root.'pafiledb/includes/functions_pafiledb.php';
class PaCategoryResponse extends RuntimeException{}
function message_die($level,$text){throw new PaCategoryResponse($text);}
class PaCategoryDatabase extends sql_db{public$queries=array();function sql_query($sql='',$transaction=false){$this->queries[]=$sql;return parent::sql_query($sql,$transaction);}}
class PaCategoryPolicy extends pafiledb{
 public$refreshes=0;
 function init(){$this->refreshes++;parent::init();}
 function auth($rows){$this->auth=array();foreach($rows as$row){$this->auth[$row['cat_id']]=array('auth_view'=>(int)$row['cat_id']!==5);}}
}
$pcs_cases=0;
function pcs_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pcs_cases']++;}
function pcs_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if(!$r){throw new RuntimeException('Own category fixture SQL failed');}return$r;}
function pcs_rows($sql){$r=pcs_sql($sql);try{return$GLOBALS['db']->sql_fetchrowset($r);}finally{$GLOBALS['db']->sql_freeresult($r);}}
function pcs_snapshot(){$rows=pcs_rows('SELECT * FROM fixture_pa_cat ORDER BY cat_id');$out=array();foreach($rows as$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}$out[(int)$row['cat_id']]=$row;}return$out;}
$port=getenv('PHPBB_PA_CATEGORY_PORT')?:'3306';$password=getenv('PHPBB_PA_CATEGORY_PASSWORD')?:'';
if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}
$name='codex_pa_category_'.bin2hex(phpbb_random_bytes(8));$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);$db=null;$created=false;
try{
 pcs_check($control->sql_query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable category schema');$created=true;$db=new PaCategoryDatabase($host,'root',$password,$name,false);
 $schema=file_get_contents($root.'install/schemas/mysql_schema.sql');pcs_check(preg_match('/^CREATE TABLE phpbb_pa_cat\s*\([\s\S]*?;/m',$schema,$m)===1,'Canonical category schema');pcs_sql(str_replace('phpbb_pa_cat','fixture_pa_cat',$m[0]));
 foreach(array(1=>array(null,-9),2=>array(0,23),3=>array(1,30),4=>array(1,10),5=>array(null,23),6=>array(2,47))as$id=>$data){
  pcs_sql("INSERT INTO fixture_pa_cat(cat_id,cat_name,cat_parent,parents_data,cat_order,cat_files,cat_last_file_id,cat_last_file_name,cat_last_file_time) VALUES(".$id.",'Category ".$id."',".($data[0]===null?'NULL':$data[0]).",'legacy cache ".$id."',".$data[1].",8,17,'Old file',123)");
 }
 $before=pcs_snapshot();$db->queries=array();$policy=new PaCategoryPolicy();$policy->init();
 pcs_check($policy->cat_rowset[1]['cat_parent']===0&&array_keys($policy->subcat_rowset[0])===array(1,2),'NULL and zero roots share the visible root group');
 pcs_check(!isset($policy->cat_rowset[5])&&!isset($policy->subcat_rowset[0][5]),'Root normalization preserves actual view filtering');
 pcs_check(pcs_snapshot()===$before,'Initialization normalizes memory without rewriting stored parents/cache');
 $refresh=$policy->refreshes;$queries=count($db->queries);$policy->sync(1,false);
 pcs_check(pcs_snapshot()===$before&&$policy->refreshes===$refresh&&count($db->queries)===$queries+1,'NULL-root sync only clears request summaries and respects false refresh without cache writes');
 $policy->sync(2,true);pcs_check(pcs_snapshot()===$before&&$policy->refreshes===$refresh+1,'Zero-root sync refreshes once without rewriting persistent category data');
 foreach(array(0,-1,99,'invalid')as$id){$queries=count($db->queries);$refresh=$policy->refreshes;$policy->sync($id);pcs_check(count($db->queries)===$queries&&$policy->refreshes===$refresh,'Missing identity performs no SQL or refresh');}
 $refresh=$policy->refreshes;$policy->sync_all();
 pcs_check(pcs_snapshot()===$before&&$policy->refreshes===$refresh+1,'Full sync reloads once while preserving every stored field, excluded category, parent and historical cache');
 foreach($db->queries as$sql){pcs_check(!preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql),'Category grouping and refresh never issue deferred or write-on-read DML');}
 echo 'Native download categories: ',$pcs_cases," actual NULL/zero grouping, visibility, read-only sync, data-preservation and refresh checks PASS; owning ordering covered by category action fixture.\n";
}finally{
 if($db){$db->sql_close();}if(!preg_match('/^codex_pa_category_[a-f0-9]{16}$/D',$name)){throw new RuntimeException('Own category schema required');}if($created){$control->sql_query('DROP DATABASE '.$name);}$control->sql_close();
}
