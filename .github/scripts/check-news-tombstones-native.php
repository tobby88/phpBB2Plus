<?php
// Real NewsDataAccess, forum ACLs, driver and canonical schema, never live data.
if(PHP_SAPI!=='cli'||getenv('PHPBB_NEWS_READERS_NATIVE')!=='1'){echo "Native News readers require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/news-tombstone-fixture.php';require $nt_app.'db/mysqli.php';require $nt_app.'includes/auth.php';
// Attachment-specific ACL fields do not affect this reader's view/read gate.
function attach_setup_basic_auth($type,&$fields,&$sql){}
class NewsNativeDatabase extends sql_db{
 var $reading=false;var $failure='';
 function sql_query($sql='',$transaction=false){
  if($this->reading&&!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('News readers must remain read-only');}
  if($this->failure!==''&&strpos(preg_replace('/\s+/',' ',$sql),$this->failure)!==false){return false;}
  return parent::sql_query($sql,$transaction);
 }
}
function nn_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if($r===false){$e=$GLOBALS['db']->sql_error();throw new RuntimeException('Owned News SQL: '.$e['message']);}return$r;}
function nn_snapshot(){ $out=array();foreach($GLOBALS['nn_tables']as$table){$r=nn_sql('SELECT * FROM fixture_'.$table);$out[$table]=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);}return serialize($out);}
function nn_ids($rows,$key){$out=array();foreach($rows as$row){$out[]=(int)$row[$key];}sort($out);return$out;}
$nn_tables=array('users','forums','topics','posts','posts_text','news','auth_access','user_group','color_groups','acronyms');$db=$control=null;$created=false;$cases=0;$schema='codex_news_'.bin2hex(phpbb_random_bytes(8));
try{
 $port=getenv('PHPBB_NEWS_READERS_PORT')?:'3306';$password=getenv('PHPBB_NEWS_READERS_PASSWORD')?:'';nt_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
 $control=new sql_db($host,'root',$password,'',false);nt_check((bool)$control->db_connect_id,'Own server connection');nt_check(preg_match('/^codex_news_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive disposable schema');$created=true;$db=new NewsNativeDatabase($host,'root',$password,$schema,false);nt_check((bool)$db->db_connect_id,'Own schema connection');
 $canonical=file_get_contents($nt_app.'install/schemas/mysql_schema.sql');foreach($nn_tables as$table){nt_check(preg_match('/CREATE TABLE phpbb_'.$table.'\s*\(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical table '.$table);nn_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 nn_sql("INSERT INTO fixture_users(user_id,username,user_password,user_regdate) VALUES(-1,'Anonymous','',1),(7,'Current &amp; member','',1),(8,'Viewer','',1),(9,'Administrator','',1)");
 nn_sql('INSERT INTO fixture_forums(forum_id,cat_id,auth_view,auth_read) VALUES(3,1,0,0),(9,1,5,5)');nn_sql("INSERT INTO fixture_news(news_id,news_category,news_image) VALUES(1,'News &amp; category','general.gif')");
 if(defined('PHPBB_NEWS_PERIOD_FIXTURE')){require __DIR__.'/news-period-native-fixture.php';nn_period_suite();}elseif(defined('PHPBB_NEWS_PAGINATION_FIXTURE')){require __DIR__.'/news-pagination-fixture.php';require __DIR__.'/news-pagination-native-fixture.php';nn_pagination_suite();}else{
 foreach(array(100=>array(3,7,999,0,''),200=>array(3,-1,-1,0,'Real guest'),300=>array(3,-1,777,0,'Former alias'),400=>array(3,-1,-1,1,'OLD_AUTHOR_MUST_NOT_LEAK'),900=>array(9,7,7,0,''))as$topic=>$seed){
  $id=(int)($topic/10);$time=1700000000+$id;$removed=$topic===300?1:0;
  nn_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_first_post_id,topic_last_post_id,topic_poster,topic_title,topic_time,news_id,topic_replies) VALUES($topic,".$seed[0].",$id,$id,".$seed[2].",'Context &amp; $id',$time,1,".($topic===100?5:0).')');
  nn_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username,post_author_removed,post_deleted) VALUES($id,$topic,".$seed[0].','.$seed[1].",$time,'7f000001','".$seed[4]."',$removed,".$seed[3].')');
  nn_sql("INSERT INTO fixture_posts_text(post_id,bbcode_uid,post_subject,post_text) VALUES($id,'','Subject $id','".($seed[3]?'OLD_BODY_MUST_NOT_LEAK':"Visible body $id")."')");
 }
 foreach(array(11,12,13,14,15)as$id){$poster=in_array($id,array(11,12,13),true)?-1:7;$removed=$id===12?1:0;$deleted=$id===13?1:0;$name=$id===11?'Real guest':($id===12?'Former alias':($deleted?'OLD_AUTHOR_MUST_NOT_LEAK':''));nn_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username,post_author_removed,post_deleted) VALUES($id,100,3,$poster,".(1700000000+$id).",'7f000001','$name',$removed,$deleted)");nn_sql("INSERT INTO fixture_posts_text(post_id,bbcode_uid,post_subject,post_text) VALUES($id,'','".($deleted?'OLD_SUBJECT_MUST_NOT_LEAK':"Subject $id")."','".($deleted?'OLD_BODY_MUST_NOT_LEAK':($id===14?'':"Visible body $id"))."')");}
 $db->reading=true;$before=nn_snapshot();
 foreach(array('english','german')as$locale){foreach(array('guest','member','admin')as$role){nt_context($locale,$role!=='guest');$userdata['user_id']=$role==='guest'?-1:($role==='admin'?9:8);$userdata['user_level']=$role==='admin'?ADMIN:USER;$tree=array();$data=new NewsDataAccess($nt_app);$data->setItemCount(100);$expected=$role==='admin'?array(100,200,300,400,900):array(100,200,300,400);
  $rows=$data->fetchArticles();nt_check(nn_ids($rows,'topic_id')===$expected&&(int)$data->fetchArticlesCount()===count($expected),'Actual article list/count retain stale-topic authors and current forum ACLs');$cases++;
  nt_check(nn_ids($data->fetchDay(14,11,2023),'topic_id')===$expected,'Actual archive reader retains current ACLs and deleted positions');$cases++;
  foreach($expected as$topic){$one=$data->fetchArticle($topic);nt_check(count($one)===1&&isset($one[0]['post_deleted'],$one[0]['poster_id'],$one[0]['post_username'],$one[0]['post_author_removed']),'Actual first-post reader supplies deletion/identity flags');nt_check((int)$one[0]['poster_id']===($topic===100||$topic===900?7:-1),'First-post identity is authoritative rather than stale topic_poster');$cases++;}
  if($role!=='admin'){nt_check($data->fetchArticle(900)===array()&&$data->fetchPosts(900)===array()&&(int)$data->fetchPostsCount(900)===0,'Direct private article/comments/count denied');$cases++;}
  $data->setItemCount(2);$comments=array_merge($data->fetchPosts(100,0),$data->fetchPosts(100,2),$data->fetchPosts(100,4));nt_check(nn_ids($comments,'post_id')===array(11,12,13,14,15)&&(int)$data->fetchPostsCount(100)===5,'Actual pagination/count include marker without losing or duplicating neighbors');nt_check((int)$comments[2]['post_id']===13&&(int)$comments[2]['post_deleted']===1,'Deleted row stays at its original ordered position');$cases++;
  foreach(array('news.tpl','portal_body.tpl','news_200_rss_body.tpl')as$file){$t=nt_template($file);$n=nt_module($t);$n->syndication=$file==='news_200_rss_body.tpl';$n->prepareArticles($rows);$html=nt_html($t);nt_check(strpos($html,'MUST_NOT_LEAK')===false&&substr_count($html,$lang['Post_deleted_placeholder'])===1,'Canonical article JOIN renders only marker, including stale deleted body');$cases++;}
  $data->setItemCount(100);foreach(array('news.tpl','portal_body.tpl')as$file){$t=nt_template($file);$n=nt_module($t);$n->data=$data;$n->renderComments(100);$html=nt_html($t);nt_check(strpos($html,'MUST_NOT_LEAK')===false&&substr_count($html,$lang['Post_deleted_placeholder'])===1&&$n->item_count==5,'Actual SQL comment reader and XS hide stale fields and preserve count');$cases++;}
  $db->failure='COUNT( t.topic_id ) AS news_count';$failed=false;try{$data->fetchPostsCount(100);}catch(NewsReaderFailure$e){$failed=true;}finally{$db->failure='';}nt_check($failed,'Comment count SQL failure is controlled rather than an invalid array count');$cases++;
  nt_check(nn_snapshot()===$before,'Every reader leaves canonical data unchanged');
 }}
 echo 'News readers native: ',$cases,' cases, ',$nt_checks," assertions PASS; canonical InnoDB joins, actual ACLs, stale/orphan topic authors, pagination/counts, deleted articles/comments/RSS, controlled failures and unchanged data.\n";
 }
}finally{if($db){$db->sql_close();}if($created){nt_check(preg_match('/^codex_news_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exclusive owned schema');}if($control){$control->sql_close();}restore_error_handler();}
