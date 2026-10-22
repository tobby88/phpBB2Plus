<?php
// Actual public helper/header branch and canonical small disposable schema.
// No production contents, external requests, load tests or data repair.
$kp_quick_stats_mode=true;
class KqTemplate {
 var $blocks=array();
 function assign_block_vars($name,$values){$this->blocks[$name][]=$values;}
}
class KqReads {
 var $connection;var $queries=array();var $freed=0;var $fail=false;
 function __construct($connection){$this->connection=$connection;}
 function __call($name,$args){return call_user_func_array(array($this->connection,$name),$args);}
 function sql_query($sql,$transaction=false){kp_check(strpos($sql,'SELECT ')===0,'Quick summary performs SELECT only');$this->queries[]=$sql;return$this->fail?false:$this->connection->sql_query($sql,$transaction);}
 function sql_freeresult($result){$this->freed++;return$this->connection->sql_freeresult($result);}
 function sql_numrows($result){throw new RuntimeException('Summary must aggregate, not materialize article-ID results');}
 function reset(){$this->queries=array();$this->freed=0;$this->fail=false;}
}
function kq_seed(){
 global$db,$kq_records;
 kp_reset('root');$caller=$GLOBALS['kp_caller'];
 kp_check($caller->sql_query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'ONLY_FULL_GROUP_BY')"),'Actual strict full-group SQL mode');
 kp_sql("INSERT INTO fixture_kb_types(id,type) VALUES(2,'A empty'),(3,'Meta <b>Grüße</b> & 😀'),(4,'0'),(5,''),(1,'Z duplicate'),(1,'Synthetic')");
 $kq_records=array(array('type'=>1,'approved'=>0));
 foreach(array(array(2,1,1,4),array(3,1,2,4),array(4,3,1,4),array(5,3,0,4),array(6,0,1,4),array(7,99,1,4),array(8,1,1,99),array(9,4,1,0),array(10,1,3,4))as$r){
  kp_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_description,article_date,article_author_id,article_body,article_type,approved) VALUES(".$r[0].",2,'Synthetic','Kept','123',".$r[3].",'Small owned body',".$r[1].','.$r[2].')');$kq_records[]=array('type'=>$r[1],'approved'=>$r[2]);
 }
 $db=new KqReads($caller);
}
function kq_assert($label,$enabled=true,$header=false){
 global$db,$template,$lang,$kq_records,$kp_source,$kb_config;
 $types=kp_rows('SELECT id,type FROM fixture_kb_types ORDER BY type,id');$counts=array();
 foreach($kq_records as$r){if($r['approved']===1){$counts[$r['type']]=isset($counts[$r['type']])?$counts[$r['type']]+1:1;}}
 $expected=array();foreach($types as$i=>$type){$id=(int)$type['id'];$expected[]=array('Q_TYPE_NAME'=>($i===0?':: ':'').phpbb_stored_text($type['type']),'Q_TYPE_AMOUNT'=>'('.(isset($counts[$id])?$counts[$id]:0).') ::');}
 $before=kp_snapshot();$db->reset();$template=new KqTemplate();$same_db=$db;
 if($header){
  $source=file_get_contents($kp_source.'includes/kb_header.php');$a=strpos($source,"if ( \$kb_config['stats_list'] == 1 )");$b=strpos($source,"\$template->pparse('kb_header');",$a);
  kp_check($a!==false&&$b>$a,'Actual public enabled/disabled header branch');$kb_config['stats_list']=$enabled?'1':'0';eval(substr($source,$a,$b-$a));
 }else{kp_check(get_quick_stats()===$template,'Existing template return contract retained');}
 if(!$enabled){kp_check(!$template->blocks&&!$db->queries&&$db->freed===0,'Disabled public summary does not query/render '.$label);}
 else{
  $actual=isset($template->blocks['switch_quick_stats.quick_stats'])?$template->blocks['switch_quick_stats.quick_stats']:array();
  kp_check($actual===$expected,'Exact published counts/escaped labels/empty and duplicate metadata rows '.$label);
  kp_check($template->blocks['switch_quick_stats'][0]['L_QUICK_STATS']===$lang['Quick_stats'],'Existing localized heading '.$label);
  kp_check(count($db->queries)===1&&$db->freed===1,'Exactly one query and one freed result regardless of type/article count '.$label);
  kp_check(strpos($db->queries[0],'WHERE approved=1 GROUP BY article_type')!==false&&strpos($db->queries[0],'COALESCE(c.article_count,0)')!==false&&strpos($db->queries[0],'article_body')===false&&strpos($db->queries[0],'SELECT article_id')===false,'Only aggregate counts/metadata cross the driver '.$label);
  foreach($actual as$row){kp_check(strpos($row['Q_TYPE_NAME'],'<b>')===false&&strpos($row['Q_TYPE_NAME'],'<script')===false,'Metadata is literal escaped text');}
 }
 kp_check($db===$same_db&&kp_snapshot()===$before,'Summary preserves all rows, duplicate identities and caller connection '.$label);
}
function kp_quick_stats_suite(){
 global$db,$template,$userdata,$lang,$kp_source,$kq_records;
 $cases=0;
 foreach(array('english','german')as$locale){require$kp_source.'language/lang_'.$locale.'/lang_main.php';require$kp_source.'language/lang_'.$locale.'/lang_kb.php';
  kq_seed();
  foreach(array('guest','ordinary','admin')as$actor){$userdata=array('user_id'=>$actor==='guest'?ANONYMOUS:($actor==='admin'?1:4),'session_logged_in'=>$actor!=='guest','user_level'=>$actor==='admin'?ADMIN:USER);kq_assert($locale.'/'.$actor);$cases++;}
  foreach(array(false,true)as$enabled){kq_assert($locale.'/actual-header',$enabled,true);$cases++;}
  foreach(array(1,2,0,1)as$state){kp_sql('UPDATE fixture_kb_articles SET approved='.$state.' WHERE article_id=1');$kq_records[0]['approved']=$state;kq_assert($locale.'/publication-transition-'.$state);$cases++;}
  // Canonical article/type ID indexes are intentionally nonunique. Read-only
  // counts must not silently deduplicate rows or multiply them via type joins.
  kp_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_description,article_date,article_author_id,article_body,article_type,approved) VALUES(2,2,'Retained duplicate','Kept','123',4,'Kept',1,1)");$kq_records[]=array('type'=>1,'approved'=>1);kq_assert($locale.'/retained-duplicate-article');$cases++;
  kp_sql('DELETE FROM fixture_kb_articles');$kq_records=array();kq_assert($locale.'/all-empty-types');$cases++;
  kq_seed();kp_sql('DELETE FROM fixture_kb_types');kq_assert($locale.'/no-types-keeps-articles');$cases++;
  kq_seed();$before=kp_snapshot();$db->reset();$db->fail=true;$template=new KqTemplate();$failed=false;
  try{get_quick_stats();}catch(KpMessageExit$exit){$failed=true;}
  kp_check($failed&&!$template->blocks&&count($db->queries)===1&&$db->freed===0&&kp_snapshot()===$before,'Actual query failure produces no misleading partial summary or writes');$cases++;
 }
 echo 'KB quick statistics native: '.$cases." actual public/header/role/publication/duplicate/empty/locale/full-group/query/resource/fault/read-only cases passed.\n";
}
require __DIR__.'/check-kb-publication-native.php';
