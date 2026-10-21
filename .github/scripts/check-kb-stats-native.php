<?php
// Actual bounded native queries/renderers/controller tail and existing real
// pagination generator. Small disposable canonical fixtures only; no load test.
$kp_stats_mode=true;
class KsTemplate {
 var $blocks=array();var $vars=array();
 function assign_block_vars($name,$values){$this->blocks[$name][]=$values;}
 function assign_vars($values){$this->vars=array_merge($this->vars,$values);}
}
class KsReads {
 var $connection;var $queries=array();var $fetched=array();var $data_result=null;var $fail_at=0;
 function __construct($connection){$this->connection=$connection;}
 function __call($name,$args){return call_user_func_array(array($this->connection,$name),$args);}
 function reset($fail_at=0){$this->queries=$this->fetched=array();$this->data_result=null;$this->fail_at=$fail_at;}
 function sql_query($sql,$transaction=false){
  if(preg_match('/^SELECT\b/i',$sql)!==1){throw new RuntimeException('Statistics display attempted a non-read query');}
  $this->queries[]=$sql;if(count($this->queries)===$this->fail_at){return false;}
  $result=$this->connection->sql_query($sql,$transaction);if(strpos($sql,'SELECT a.article_id,')===0){$this->data_result=$result;}return$result;
 }
 function sql_fetchrow($result){$row=$this->connection->sql_fetchrow($result);if($row&&$result===$this->data_result){$this->fetched[]=$row;}return$row;}
}
function create_date($format,$time,$zone){return(string)$time;}
function ks_ids($template){$ids=array();foreach(isset($template->blocks['rows'])?$template->blocks['rows']:array()as$row){kp_check(preg_match('/k=([0-9]+)/',$row['ARTICLE'],$match)===1,'Actual article URL');$ids[]=(int)$match[1];}return$ids;}
function ks_render($mode,$start,$size,$fail=0){
 global$db,$template;$db->reset($fail);$template=new KsTemplate();$total=null;$actual=null;
 $out=get_kb_stats($mode,1,'rows',$start,$size,$total,$actual);
 kp_check($out===$template,'Existing helper template return contract retained');return array($total,$actual,ks_ids($template));
}
function ks_controller($mode,$start,$size){
 global$db,$template,$kp_source,$kb_config,$lang,$stats,$phpEx;
 $stats=$mode;$_GET=array('start'=>$start);$kb_config['art_pagination']=$size;$template=new KsTemplate();$db->reset();
 $source=file_get_contents($kp_source.'includes/kb_stats.php');$a=strpos($source,'$stats_per_page=');$b=strpos($source,'//',$a);kp_check($a!==false&&$b>$a,'Complete actual pagination controller tail');eval(substr($source,$a,$b-$a));return$template;
}
function ks_category_controller($requested,$admin,$fail=0){
 global$db,$template,$kp_source,$kb_config,$lang,$is_admin,$phpEx,$kb_news_sort_method,$kb_news_sort_par;
 $is_admin=$admin;$kb_config['art_pagination']='7';$_GET=array('start'=>$requested);$template=new KsTemplate();$db->reset($fail);$category_id=2;
 $source=file_get_contents($kp_source.'includes/kb_cat.php');$a=strpos($source,'$start =');$b=strpos($source,'$category_id =',$a);kp_check($a!==false&&$b>$a,'Actual category request/page-size initialization');eval(substr($source,$a,$b-$a));
 $a=strpos($source,'$sql_pag =');$b=strpos($source,'$template->assign_vars(',$a);kp_check($a!==false&&$b>$a,'Complete actual category count/window/pagination block');eval(substr($source,$a,$b-$a));
 $kb_news_sort_method='t.article_date';$kb_news_sort_par='DESC';get_kb_articles(2,1,'rows',$start,$articles_per_page);
 return array($total_articles,$start,ks_ids($template),$pagination);
}
function kp_stats_suite(){
 global$db,$template,$kp_source,$board_config,$kb_config,$userdata,$is_admin,$is_block,$page_id,$lang;
 $functions=file_get_contents($kp_source.'includes/functions.php');foreach(array('phpbb_profile_text','generate_pagination')as$name){kp_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$functions,$match)===1,'Actual core helper '.$name);eval($match[0]);}
 kp_reset('root');$userdata['user_level']=USER;$is_admin=$is_block=false;$page_id=0;$board_config['default_dateformat']='Y';$board_config['board_timezone']=0;$kb_config['allow_edit']='1';kp_sql('DELETE FROM fixture_kb_articles');
 $records=array();$body=str_repeat('Unused plain article body. ',80);
 for($i=1;$i<=43;$i++){
  $date=$i===1?999999999:($i===2?1000000000:1700000000+(int)floor($i/3));$approved=$i%7===0?($i%14===0?2:0):1;$votes=$i%4===0?0:2;$rating=($i%4)*4;$views=$i%6;
  $records[]=array('id'=>$i,'date'=>$date,'approved'=>$approved,'votes'=>$votes,'rating'=>$rating,'views'=>$views);
  $author=$i%11===0?404:($i%3===0?0:4);
  kp_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_description,article_date,article_author_id,username,article_body,article_type,approved,views,article_rating,article_totalvotes) VALUES(".$i.",2,'Record ".$i."','Kept description','".$date."',".$author.",'Saved guest', '".$GLOBALS['peer']->sql_escape($body)."',1,".$approved.','.$views.','.$rating.','.$votes.')');
 }
 $db=new KsReads($db);$before=kp_snapshot();$cases=0;
 foreach(array('latest','toprated','mostpopular')as$mode){
  $expected=array_values(array_filter($records,function($record)use($mode){return$record['approved']===1&&($mode!=='toprated'||$record['votes']>0)&&($mode!=='mostpopular'||$record['views']>0);}));
  usort($expected,function($left,$right)use($mode){$a=$mode==='latest'?$left['date']:($mode==='mostpopular'?$left['views']:$left['rating']/$left['votes']);$b=$mode==='latest'?$right['date']:($mode==='mostpopular'?$right['views']:$right['rating']/$right['votes']);return$a==$b?($right['id']-$left['id']):($a>$b?-1:1);});$ids=array_column($expected,'id');$all=array();
  for($offset=0;$offset<count($ids);$offset+=7){
   $out=ks_render($mode,(string)$offset,'7');kp_check($out===array(count($ids),$offset,array_slice($ids,$offset,7)),'Actual '.$mode.' page matches visible count, numeric ordering and stable ID tie-break');$all=array_merge($all,$out[2]);
   kp_check(count($db->queries)===2&&count($db->fetched)<=7,'Exactly count plus bounded page query, never per-row identity/type/category lookups');
   kp_check(strpos($db->queries[1],'article_body')===false&&strpos($db->queries[1],'SELECT *')===false&&strpos($db->queries[1],' LIMIT '.$offset.',7')!==false,'Only needed columns and numeric bounded LIMIT cross the driver');
   foreach($db->fetched as$row){kp_check(!array_key_exists('article_body',$row),'Real native rows contain no unused full article body');}$cases++;
  }
  kp_check($all===$ids&&count(array_unique($all))===count($ids),'Every qualifying article remains reachable exactly once across all pages');$cases++;
  foreach(array('99999999999999999999','00000000000000000000','-1','1e2','7tail',array('7'),true,null)as$start){$out=ks_render($mode,$start,7);$last=count($ids)-1;$last-=$last%7;$wanted=$start==='99999999999999999999'?$last:0;kp_check($out===array(count($ids),$wanted,array_slice($ids,$wanted,7)),'Malformed/oversized offsets cannot escape numeric page bounds or produce a partial numeric ID');$cases++;}
  $out=ks_render($mode,'8',7);kp_check($out[1]===7&&$out[2]===array_slice($ids,7,7),'Nonaligned offset normalizes to a complete page');$cases++;
  foreach(array('english','german')as$locale){require$kp_source.'language/lang_'.$locale.'/lang_main.php';$page=ks_controller($mode,'99999999999999999999','7');$last=count($ids)-1;$last-=$last%7;kp_check(strpos($page->vars['PAGINATION'],'mode=stats&amp;stats='.$mode)!==false&&strpos($page->vars['PAGINATION'],'&amp;start=')!==false,'Actual existing pagination links retain stats mode, sort, escaped separators and page offsets');kp_check($page->vars['PAGE_NUMBER']===sprintf($lang['Page_of'],(int)floor($last/7)+1,(int)ceil(count($ids)/7)),'Actual localized controller uses clamped current page, not raw requested start');$cases++;}
 }
 foreach(array(array('0',1),array('1001',1000),array('99999999',1000),array('bad',20),array(array(),20),array(true,20))as$size){$out=ks_render('latest',0,$size[0]);kp_check(count($out[2])<=min($size[1],37)&&strpos($db->queries[1],','.$size[1])!==false,'Invalid/stale per-page settings use bounded safe defaults');$cases++;}
 foreach(array(1,2)as$failure){$denied=false;try{ks_render('latest',0,7,$failure);}catch(KpMessageExit$error){$denied=$error->level===GENERAL_ERROR;}kp_check($denied,'Actual count/page SQL failures report cleanly without writes');$cases++;}
 $source=file_get_contents($kp_source.'includes/kb_cat.php');$a=strpos($source,'$sql_pag =');$b=strpos($source,'if ( !($result =',$a);kp_check($a!==false&&$b>$a,'Actual category pagination count');$category_id=2;
 foreach(array(false,true)as$admin){$is_admin=$admin;eval(substr($source,$a,$b-$a));$count=kp_rows($sql_pag);kp_check((int)$count[0]['total']===($admin?43:37),'Actual public category count matches approved visibility; existing admin full listing remains complete');$cases++;}$is_admin=false;
 $GLOBALS['images']=array('icon_approve'=>'synthetic.png','icon_unapprove'=>'synthetic.png','icon_delpost'=>'synthetic.png');
 foreach(array(false,true)as$admin){
  $expected=array_values(array_filter($records,function($record)use($admin){return$admin||$record['approved']===1;}));usort($expected,function($a,$b){return$a['date']===$b['date']?$b['id']-$a['id']:($a['date']>$b['date']?-1:1);});$ids=array_column($expected,'id');
  foreach(array('0','7','8','99999999999999999999','7tail',array('7'))as$requested){$out=ks_category_controller($requested,$admin);$last=count($ids)-1;$last-=$last%7;$wanted=$requested==='99999999999999999999'?$last:(in_array($requested,array('7','8'),true)?7:0);kp_check(array_slice($out,0,3)===array(count($ids),$wanted,array_slice($ids,$wanted,7)),'Actual complete public/moderator category count/window/list uses visible total, numeric creation order and stable ties');kp_check(strpos($out[3],'mode=cat&amp;cat=2')!==false&&strpos($out[3],'&amp;start=')!==false,'Actual category links retain normalized offsets and escaped route parameters');$cases++;}
 }
 $is_admin=false;$db->reset();$template=new KsTemplate();get_kb_articles(2,1,'rows',1000007,7);kp_check(preg_match('/LIMIT\s+1000007,\s*7/',$db->queries[0])===1,'Actual bounded list SQL does not silently clamp legitimate later pages to the old arbitrary million offset');$cases++;
 $failed=false;try{ks_category_controller('0',false,1);}catch(KpMessageExit$error){$failed=$error->level===GENERAL_ERROR;}kp_check($failed,'Actual category count SQL failure reports the real query without undefined-variable warnings');$cases++;
 $is_admin=true;$userdata['user_level']=ADMIN;$out=ks_render('latest',0,7);kp_check($out[0]===37&&count($db->queries)===2&&!empty($template->blocks['rows'][0]['U_APPROVE']),'Admin action rendering retains approved-only statistics and the same two-query bound');$cases++;
 $saved_user=$userdata;$userdata['user_id']=ANONYMOUS;$userdata['user_level']=USER;$userdata['session_logged_in']=false;$is_admin=false;$out=ks_render('latest',0,7);kp_check($out[0]===37&&count($db->queries)===2&&empty($template->blocks['rows'][0]['U_APPROVE']),'Anonymous statistics expose only approved rows without administrative actions or extra per-row lookups');$userdata=$saved_user;$userdata['user_level']=USER;$cases++;
 kp_check(kp_snapshot()===$before,'Every statistics/count/controller read preserves all tables, bodies, authors and approval states');
 kp_sql('UPDATE fixture_kb_articles SET approved=0');$empty=kp_snapshot();$out=ks_render('latest','9999999999',7);kp_check($out===array(0,0,array())&&isset($template->blocks['no_articles'])&&count($db->queries)===2,'Empty visible list uses zero page without leaking pending counts');$page=ks_controller('latest',0,'7');kp_check($page->vars['PAGINATION']===''&&$page->vars['PAGE_NUMBER']===''&&kp_snapshot()===$empty,'Empty actual controller has no fake pages and does not mutate hidden records');$cases++;
 $markup=file_get_contents($kp_source.'templates/fisubsilversh/kb_stats_body.tpl');kp_check(strpos($markup,'{PAGINATION}')!==false&&strpos($markup,'{PAGE_NUMBER}')!==false&&strpos($markup,'class="thTop nowrap=')===false,'Standard template exposes the actual navigation with valid heading attributes');$cases++;
 echo 'KB statistics native: '.$cases." actual bounded/narrow/two-query/full-navigation/numeric-date/tie/visibility/controller/locale/failure/read-only cases passed. Small own fixture, no stress/network.\n";
}
require __DIR__.'/check-kb-publication-native.php';
