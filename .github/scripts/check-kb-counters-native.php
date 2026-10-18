<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_KB_COUNTER_NATIVE')!=='1'){echo "KB counter checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);define('GENERAL_ERROR',2);define('KB_CATEGORIES_TABLE','fixture_kb_categories');
$kbc_source=dirname(dirname(__DIR__)).'/phpBB2/';
require $kbc_source.'includes/php_compat.php';require $kbc_source.'db/mysqli.php';require $kbc_source.'includes/functions_kb.php';
class KbCounterNativeExit extends RuntimeException {}
function message_die($level,$message){throw new KbCounterNativeExit($message);}
function kbc_native_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$port=getenv('PHPBB_KB_COUNTER_PORT')?:'3306';$password=getenv('PHPBB_KB_COUNTER_PASSWORD')?:'';
kbc_native_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);
kbc_native_check($control->db_connect_id,'Native fixture connection');
$fixture='codex_kb_counter_'.bin2hex(phpbb_random_bytes(8));
kbc_native_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own schema');
class KbCounterNativeDatabase extends sql_db {
 var $hook=null;
 function sql_query($sql='',$transaction=false){if(is_callable($this->hook)){call_user_func($this->hook,$sql);}return parent::sql_query($sql,$transaction);}
}
$db=null;$peer=null;
function kbc_native_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);kbc_native_check($r,'Native fixture statement');return $r;}
function kbc_native_reset(){
 $GLOBALS['db']->hook=null;kbc_native_sql('DELETE FROM '.KB_CATEGORIES_TABLE);
 kbc_native_sql("INSERT INTO ".KB_CATEGORIES_TABLE."(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(1,'Root','Synthetic',10,0,0),(2,'Größe 😀','Synthetic',5,1,1),(3,'Leaf','Synthetic',1,2,2)");
}
function kbc_native_counts(){
 $r=kbc_native_sql('SELECT category_id,number_articles FROM '.KB_CATEGORIES_TABLE.' ORDER BY category_id');$out=array();
 while($row=$GLOBALS['peer']->sql_fetchrow($r)){$out[(int)$row['category_id']]=(int)$row['number_articles'];}$GLOBALS['peer']->sql_freeresult($r);return $out;
}
try{
 $db=new KbCounterNativeDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
 $schema=file_get_contents($kbc_source.'install/schemas/mysql_schema.sql');
 kbc_native_check(preg_match('/CREATE TABLE phpbb_kb_categories\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical category schema');
 kbc_native_sql(str_replace('phpbb_kb_categories',KB_CATEGORIES_TABLE,$m[0]));$cases=0;
 foreach(array('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ANSI_QUOTES,NO_BACKSLASH_ESCAPES')as$mode){
  kbc_native_check($db->sql_query("SET SESSION sql_mode='".$mode."'"),'Production connection SQL mode');kbc_native_sql("SET SESSION sql_mode='".$mode."'");
  foreach(array(array('+ 1',11,6),array('- 1',9,4),array(1,11,6),array(-1,9,4),array('0',10,5),array('- 0',10,5))as$case){
   kbc_native_reset();update_kb_number(2,$case[0]);kbc_native_check(kbc_native_counts()===array(1=>$case[1],2=>$case[2],3=>1),'Actual signed delta updates both category and ancestor');$cases++;
  }
  foreach(array('1 OR 1=1','1.0',array('1'),null,true,"1\0")as$bad){
   kbc_native_reset();$before=kbc_native_counts();$calls=0;$db->hook=function($sql)use(&$calls){$calls++;};update_kb_number(2,$bad);$db->hook=null;
   kbc_native_check($calls===0&&kbc_native_counts()===$before,'Invalid complete delta rejected before any SQL');$cases++;
  }
  kbc_native_reset();update_kb_number(3,1);kbc_native_check(kbc_native_counts()===array(1=>11,2=>6,3=>2),'Complete ancestor path updated once');$cases++;
  kbc_native_reset();kbc_native_sql('UPDATE '.KB_CATEGORIES_TABLE.' SET number_articles=0 WHERE category_id=3');update_kb_number(3,-1);
  kbc_native_check(kbc_native_counts()===array(1=>9,2=>4,3=>0),'Unsigned zero subtraction cannot overflow before clamping');$cases++;
  kbc_native_reset();kbc_native_sql('UPDATE '.KB_CATEGORIES_TABLE.' SET parent=3 WHERE category_id=2');update_kb_number(2,1);
  kbc_native_check(kbc_native_counts()===array(1=>10,2=>6,3=>2),'Cyclic path bounded and each category visited once');$cases++;
  kbc_native_reset();$before=kbc_native_counts();update_kb_number(999,1);kbc_native_check(kbc_native_counts()===$before,'Missing category never changes unrelated counters');$cases++;
  kbc_native_reset();$reached=false;
  $db->hook=function($sql)use(&$reached){
   if(strpos($sql,'UPDATE '.KB_CATEGORIES_TABLE.' SET number_articles ')!==0||substr($sql,-22)!==' WHERE category_id = 2'){return;}
   $GLOBALS['db']->hook=null;$reached=true;$outer=$GLOBALS['db'];$GLOBALS['db']=$GLOBALS['peer'];
   try{update_kb_number(2,1);}finally{$GLOBALS['db']=$outer;}
  };
  update_kb_number(2,1);$db->hook=null;
  kbc_native_check($reached&&kbc_native_counts()===array(1=>12,2=>7,3=>1),'Independent interleaved increment is not lost to a stale SELECT snapshot');$cases++;
 }
 echo 'Knowledge Base native category counters: ',$cases," cases passed\n";
}finally{
 if($db){$db->hook=null;$db->sql_close();}if($peer){$peer->sql_close();}
 kbc_native_check($control->sql_query('DROP DATABASE '.$fixture),'Remove only own fixture schema');$control->sql_close();
}
