<?php
// Actual approval service, canonical native schema and dedicated driver.
// No real config, browser session, mail, uploads or third-party targets.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_KB_COUNTER_NATIVE') !== '1') { echo "KB publication requires an explicitly enabled disposable database.\n"; return; }
define('IN_PHPBB', true); $kp_source = dirname(dirname(__DIR__)) . '/phpBB2/';
$phpbb_root_path = $kp_source; $phpEx = 'php'; $table_prefix = 'fixture_';
require $kp_source . 'includes/php_compat.php'; require $kp_source . 'includes/constants.php'; require $kp_source . 'attach_mod/includes/constants.php';
foreach (array('ARTICLES'=>'articles','CATEGORIES'=>'categories','CONFIG'=>'config','TYPES'=>'types','WORD'=>'wordlist','MATCH'=>'wordmatch','SEARCH'=>'results','VOTES'=>'votes') as $constant=>$suffix) { define('KB_' . $constant . '_TABLE', 'fixture_kb_' . $suffix); }
require $kp_source . 'db/mysqli.php'; require $kp_source . 'includes/functions_kb.php'; require $kp_source . 'includes/functions_search.php';
require $kp_source . 'includes/functions_post.php'; require $kp_source . 'includes/bbcode.php'; require $kp_source . 'includes/functions_kb_publication.php';
if(!empty($kp_controller_mode)){require $kp_source.'includes/functions_kb_delete_storage.php';}
function kp_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class KpMessageExit extends RuntimeException { public $level; function __construct($level,$message){parent::__construct($message);$this->level=$level;} }
function message_die($level, $message) { throw new KpMessageExit($level,$message); }
function append_sid($url) { return $url; }
function phpbb_board_url($path) { return 'https://forum.example.invalid/' . $path; }
$stored_source = file_get_contents($kp_source . 'includes/functions.php');
kp_check(preg_match('/^function phpbb_stored_text\(.*?^\}/ms', $stored_source, $stored) === 1, 'Actual stored-text helper'); eval($stored[0]);
$lang = array(); require $kp_source . 'language/lang_english/lang_main.php'; require $kp_source . 'language/lang_english/lang_admin.php'; require $kp_source . 'language/lang_english/lang_kb.php';
$lang['Not_Authorised'] = 'denied'; $lang['Session_invalid'] = 'invalid-session';
$board_config = array('default_lang'=>'english','version'=>'.0.23','allow_html_tags'=>'b,i,u'); $kb_config = array('comments'=>0,'mod_group'=>'999'); $user_ip = '7f000001'; $client_ip='127.0.0.1';
$port = getenv('PHPBB_KB_COUNTER_PORT') ?: '3306'; $password = getenv('PHPBB_KB_COUNTER_PASSWORD') ?: '';
kp_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback port');
$host = '127.0.0.1:' . $port; $control = new sql_db($host, 'root', $password, '', false);
kp_check($control->db_connect_id, 'Own database server'); $fixture = 'codex_kb_publish_' . bin2hex(phpbb_random_bytes(8));
kp_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Own disposable schema');
$kp_hook = null; $kp_factory = 'normal'; $db = null; $peer = null; $kp_owner_connections = array();
$kp_original_reporting=error_reporting(E_ALL);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
class KpOwnerConnection {
 public $connection; public $db_connect_id;
 function __construct($connection) { $this->connection = $connection; $this->db_connect_id = $connection->db_connect_id; }
 function __call($name, $args) { return call_user_func_array(array($this->connection, $name), $args); }
 function sql_query($sql, $transaction = false) {
  if (is_callable($GLOBALS['kp_hook']) && call_user_func($GLOBALS['kp_hook'], 'before', $sql, $this) === false) { return false; }
  $result = $this->connection->sql_query($sql, $transaction);
  if (is_callable($GLOBALS['kp_hook']) && call_user_func($GLOBALS['kp_hook'], 'after', $sql, $this) === false) { return false; }
  return $result;
 }
}
class KpDatabase extends sql_db {
 function sql_dedicated_connection() {
  if ($GLOBALS['kp_factory'] === 'borrow') { return $this; }
  if ($GLOBALS['kp_factory'] === 'missing') { return false; }
  $connection = parent::sql_dedicated_connection(); if (!$connection) { return false; }
  $proxy = new KpOwnerConnection($connection); $GLOBALS['kp_owner_connections'][] = $proxy; return $proxy;
 }
}
function kp_sql($sql) { $r = $GLOBALS['peer']->sql_query($sql); kp_check($r, 'Fixture SQL'); return $r; }
function kp_rows($sql) { $r = kp_sql($sql); $out = array(); while ($row = $GLOBALS['peer']->sql_fetchrow($r)) { $named = array(); foreach ($row as $key=>$value) { if (is_string($key)) { $named[$key] = $value; } } $out[] = $named; } $GLOBALS['peer']->sql_freeresult($r); return $out; }
function kp_snapshot() { $out = array(); foreach ($GLOBALS['kp_tables'] as $table) { $out[$table] = kp_rows('SELECT * FROM ' . $table); } return $out; }
function kp_controller_run($context,$mode,$sid=true,$method='POST',$article_id='1') {
 global $db,$userdata,$lang,$phpbb_root_path,$phpEx,$kb_config,$is_admin,$kp_controller_bodies;
 $is_admin=true;$_GET=array();$_POST=array('mode'=>$mode,'action'=>$mode,'a'=>$article_id,'c'=>'yes','confirm_action'=>'yes');$_SERVER['REQUEST_METHOD']=$method;
 if($sid){$_POST['sid']=$userdata['session_id'];}$_REQUEST=$_POST;
 try{eval($kp_controller_bodies[$context]);}catch(KpMessageExit $exit){return $exit;}
 throw new RuntimeException('Actual controller did not report completion/refusal');
}
function kp_load_controllers() {
 global $kp_source,$kp_controller_bodies;
 if(isset($kp_controller_bodies)){return;}
 $bootstrap=file_get_contents($kp_source.'admin/pagestart.php');$a=strpos($bootstrap,"if (!function_exists('phpbb_admin_post_session_valid'))");$b=strpos($bootstrap,'if (empty($no_page_header))',$a);
 kp_check($a!==false&&$b>$a,'Actual ACP token helpers');eval(substr($bootstrap,$a,$b-$a));$kp_controller_bodies=array();
 foreach(array('acp'=>'admin/admin_kb_art.php','moderator'=>'includes/kb_moderator.php')as$context=>$file){
  $source=file_get_contents($kp_source.$file);$a=strpos($source,$context==='acp'?'$approve =':'$category_id =');$b=strpos($source,"$"."template->pparse('body');",$a);
  kp_check($a!==false&&$b>$a,'Complete actual controller action body');$kp_controller_bodies[$context]=substr($source,$a,$b-$a);
 }
}
function kp_controller_suite() {
 kp_load_controllers();
 $cases=0;
 foreach(array(array('root','acp'),array('junior','acp'),array('root','moderator'),array('moderator','moderator'))as$actor){
  foreach(array(false,true)as$comments){
  kp_reset($actor[0],$comments);
  foreach(array('approve','unapprove','delete')as$mode){foreach(array(array(false,'POST'),array(true,'GET'))as$request){$before=kp_snapshot();kp_controller_run($actor[1],$mode,$request[0],$request[1]);kp_check(kp_snapshot()===$before,'Invalid method/token cannot mutate actual controller');$cases++;}}
  foreach(array(array('1'),'1e0','1foo',true,1.5)as$bad){$before=kp_snapshot();kp_controller_run($actor[1],'approve',true,'POST',$bad);kp_check(kp_snapshot()===$before,'Malformed controller ID cannot partially select another article');$cases++;}
  foreach(array('approve','approve','unapprove','unapprove','approve')as$mode){
   $result=kp_controller_run($actor[1],$mode);kp_check($result->level===GENERAL_MESSAGE,'Actual controller succeeds: '.$result->getMessage());kp_check(kp_counts($mode==='approve'?1:0),'Actual controller counts state transitions once');$cases++;
  }
  kp_sql("INSERT INTO fixture_kb_votes VALUES('127.0.0.1','1',1),('127.0.0.1','1',2)");
  kp_check(kp_controller_run($actor[1],'delete')->level===GENERAL_MESSAGE&&kp_counts(0),'Legacy approved deletion still decrements once');
  kp_check(!kp_rows('SELECT * FROM fixture_kb_articles')&&!kp_rows('SELECT * FROM fixture_kb_wordmatch')&&count(kp_rows('SELECT * FROM fixture_kb_votes'))===1,'Delete preserves unrelated vote');$cases++;
  $before=kp_snapshot();kp_controller_run($actor[1],'delete');kp_check(kp_snapshot()===$before,'Missing/repeated deletion cannot decrement again');$cases++;
  }
 }
 echo 'Knowledge Base actual ACP/frontend action bodies: '.$cases." transition/token/input/search/deletion cases passed (controlled bootstrap; comments on/off, no attachment deletion).\n";
}
function kp_reset($actor = 'root', $comments = false) {
 global $kp_hook, $kp_factory, $userdata;
 $kp_hook = null; $kp_factory = 'normal'; foreach ($GLOBALS['kp_tables'] as $table) { kp_sql('DELETE FROM ' . $table); }
 kp_sql("INSERT INTO fixture_users(user_id,username,user_password,user_email,user_active,user_level,user_attachsig) VALUES(1,'Root','synthetic','root@example.invalid',1,1,0),(2,'Junior','synthetic','junior@example.invalid',1,0,0),(3,'KB moderator','synthetic','moderator@example.invalid',1,0,0),(4,'Ordinary','synthetic','ordinary@example.invalid',1,0,0)");
 $ids = array('root'=>1,'junior'=>2,'moderator'=>3,'ordinary'=>4); $id = $ids[$actor];
 $userdata = array('user_id'=>$id,'session_id'=>str_repeat('a',32),'session_logged_in'=>true,'session_admin'=>1,'user_level'=>ADMIN);
 kp_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_admin) VALUES('" . $userdata['session_id'] . "'," . $id . ',1,1)');
 kp_sql("INSERT INTO fixture_jr_admin_users(user_id,user_jr_admin,admin_notes) VALUES(2,'" . md5('KB_titleArt_manadmin_kb_art.php') . "','')");
 kp_sql("INSERT INTO fixture_groups(group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES(10,0,'KB moderation','Synthetic',1,0)");
 kp_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,3,0)');
 kp_sql("INSERT INTO fixture_kb_config(config_name,config_value) VALUES('comments','" . ($comments ? '1' : '0') . "'),('mod_group','10'),('forum_id','1'),('admin_id','1'),('del_topic','0')");
 kp_sql("INSERT INTO fixture_kb_categories(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(1,'Root','Keep',0,0,0),(2,'Größe 😀','Keep',0,1,1)");
 kp_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_description,article_date,article_author_id,article_body,article_type,approved) VALUES(1,2,'Synthetic title','Keep description','123',4,'Synthetic searchable article',1,0)");
 kp_sql("INSERT INTO fixture_kb_types(id,type) VALUES(1,'Synthetic')");
 kp_sql("INSERT INTO fixture_forums(forum_id,cat_id,forum_name,forum_desc,forum_order) VALUES(1,1,'Discussion','Synthetic',0)");
 $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('sid'=>$userdata['session_id']);
}
function kp_run($approved, $context = 'acp', $id = 1) {
 try { return kb_publish_approval($GLOBALS['db'], $id, $approved, $context); }
 catch (PhpbbKbPublicationException $error) { return $error; }
}
function kp_counts($value) { return kp_rows('SELECT category_id,number_articles FROM fixture_kb_categories ORDER BY category_id') === array(array('category_id'=>'1','number_articles'=>(string)$value),array('category_id'=>'2','number_articles'=>(string)$value)); }
try {
 $db = new KpDatabase($host,'root',$password,$fixture,false); $kp_caller=$db; $peer = new sql_db($host,'root',$password,$fixture,false); kp_check($db->db_connect_id && $peer->db_connect_id, 'Independent native connections');
 $kp_tables = array(KB_ARTICLES_TABLE,KB_CATEGORIES_TABLE,KB_CONFIG_TABLE,KB_TYPES_TABLE,KB_WORD_TABLE,KB_MATCH_TABLE,KB_SEARCH_TABLE,KB_VOTES_TABLE,
  TOPICS_TABLE,POSTS_TABLE,POSTS_TEXT_TABLE,FORUMS_TABLE,USERS_TABLE,SEARCH_WORD_TABLE,SEARCH_MATCH_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,USER_GROUP_TABLE,DISALLOW_TABLE,WORDS_TABLE);
 if(!empty($kp_delete_mode)||!empty($kp_controller_mode)){$kp_tables=array_unique(array_merge($kp_tables,array(CONFIG_TABLE,AUTH_ACCESS_TABLE,VOTE_DESC_TABLE,VOTE_RESULTS_TABLE,VOTE_USERS_TABLE,LOGS_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE,TOPICS_WATCH_TABLE,BOOKMARK_TABLE,TOPIC_VIEW_TABLE,PRIVMSGS_TABLE)));}
 if(!empty($kp_write_mode)){$kp_tables=array_merge($kp_tables,array(PRIVMSGS_TABLE,PRIVMSGS_TEXT_TABLE));}
 $schema = file_get_contents($kp_source . 'install/schemas/mysql_schema.sql');
 foreach ($kp_tables as $table) { $canonical = 'phpbb_' . substr($table,strlen('fixture_')); kp_check(preg_match('/CREATE TABLE `?' . preg_quote($canonical,'/') . '`?\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical table ' . $canonical); kp_sql(str_replace($canonical,$table,$m[0])); }
 if(!empty($kp_stats_mode)){kp_stats_suite();return;}
 if(!empty($kp_author_mode)){kp_author_suite();return;}
 if(!empty($kp_guest_policy_mode)){kp_guest_policy_suite();return;}
 if(!empty($kp_config_mode)){kp_config_suite();return;}
 if(!empty($kp_text_mode)){kp_text_suite();return;}
 if(!empty($kp_mirror_mode)){kp_mirror_suite();return;}
 if(!empty($kp_type_mode)){kp_type_suite();return;}
 if(!empty($kp_category_mode)){kp_category_suite();return;}
 if(!empty($kp_rating_mode)){kp_rating_suite();return;}
 if(!empty($kp_write_mode)){kp_write_suite();return;}
 if(!empty($kp_delete_mode)){kp_delete_suite();return;}
 if(!empty($kp_controller_mode)){kp_controller_suite();return;}
 $cases = 0;
 foreach (array(array('root','acp'),array('junior','acp'),array('root','moderator'),array('moderator','moderator')) as $actor) {
  foreach (array(false,true) as $comments) {
   kp_reset($actor[0],$comments); $original_db=$db; $original_config=$kb_config; $original_board=$board_config;
   foreach (array(1,1,0,0,1) as $approved) {
    $result=kp_run($approved,$actor[1]);kp_check(is_array($result),'Authorized approval transition: '.($result instanceof Exception?$result->getMessage():'unexpected'));
    kp_check(kp_counts($approved),'Complete category path reflects exactly one transition');
    kp_check($db===$original_db&&$kb_config===$original_config&&$board_config===$original_board,'Caller connection/preferences/configuration restored');
    $matches=kp_rows('SELECT * FROM fixture_kb_wordmatch WHERE article_id=1');kp_check(($approved===1)===(count($matches)>0),'Search index reflects approval');$cases++;
   }
   kp_check(count(kp_rows('SELECT * FROM fixture_topics'))===($comments?1:0)&&count(kp_rows('SELECT * FROM fixture_posts'))===($comments?1:0),'Repeated approval never duplicates optional discussion');
  }
 }
 foreach(array('ordinary','junior') as $actor) { kp_reset($actor);$before=kp_snapshot();kp_check(kp_run(1,'moderator') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'ACP grant alone is not a frontend KB moderation grant');$cases++; }
 kp_reset('moderator');$before=kp_snapshot();kp_check(kp_run(1,'acp') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'KB group alone is not a delegated ACP grant');$cases++;
 foreach(array('root','moderator')as$actor){kp_reset($actor);$userdata['session_admin']=0;kp_sql('UPDATE fixture_sessions SET session_admin=0');kp_check(is_array(kp_run(1,'moderator'))&&kp_counts(1),'Legitimate frontend moderation does not require an ACP elevation');$cases++;}
 foreach(array('cached','stored','both')as$flag){kp_reset();if($flag!=='stored'){$userdata['session_admin']=0;}if($flag!=='cached'){kp_sql('UPDATE fixture_sessions SET session_admin=0');}$before=kp_snapshot();kp_check(kp_run(1,'acp') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'ACP publication requires current and request-entry elevation');$cases++;}
 foreach(array('UPDATE fixture_users SET user_level=2 WHERE user_id=4','UPDATE fixture_user_group SET user_pending=1 WHERE user_id=3','UPDATE fixture_users SET user_active=0 WHERE user_id=3')as$change){$actor=strpos($change,'user_id=4')!==false?'ordinary':'moderator';kp_reset($actor);kp_sql($change);$before=kp_snapshot();kp_check(kp_run(1,'moderator') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Forum moderator flag, pending membership or inactive account is not current KB authority');$cases++;}
 foreach(array('missing','borrow') as $factory) { kp_reset();$kp_factory=$factory;$before=kp_snapshot();kp_check(kp_run(1) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Unsafe factory cannot write or close caller');$r=$db->sql_query('SELECT CONNECTION_ID()');kp_check($r,'Caller still usable');$db->sql_freeresult($r);$cases++; }
 kp_reset();$before=kp_snapshot();kp_sql("CREATE TRIGGER kp_failure BEFORE UPDATE ON fixture_kb_categories FOR EACH ROW BEGIN IF NEW.category_id=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SyntheticRawSecret'; END IF; END");
 $result=kp_run(1);kp_check($result instanceof PhpbbKbPublicationException&&strpos($result->getMessage(),'SyntheticRawSecret')===false&&kp_snapshot()===$before,'Ancestor server failure rolls back article/leaf/index');kp_sql('DROP TRIGGER kp_failure');$cases++;
 kp_check(is_array(kp_run(1))&&kp_counts(1),'Retry after complete rollback succeeds');$cases++;
 kp_reset('moderator',true);kp_check(is_array(kp_run(1,'moderator')),'Seed existing discussion for reviewed edit');
 kp_sql('UPDATE fixture_kb_articles SET approved=2');kp_sql('UPDATE fixture_kb_categories SET number_articles=0');kp_sql('DELETE FROM fixture_kb_wordmatch');$discussion_before=kp_rows('SELECT * FROM fixture_topics');
 kp_check(is_array(kp_run(1,'moderator'))&&kp_counts(1)&&kp_rows('SELECT * FROM fixture_topics')===$discussion_before&&count(kp_rows('SELECT * FROM fixture_kb_wordmatch'))>0,'Pending edit becomes approved without duplicating its existing discussion');$cases++;
 kp_reset('root',true);$before=kp_snapshot();$reached=false;
 $kp_hook=function($event,$sql,$proxy)use(&$reached){
  if($event!=='after'||strpos($sql,'UPDATE fixture_kb_categories SET number_articles ')!==0){return;}$GLOBALS['kp_hook']=null;$reached=true;
  $r=$proxy->connection->sql_query('SELECT CONNECTION_ID() AS id');$row=$proxy->connection->sql_fetchrow($r);$proxy->connection->sql_freeresult($r);$own=(int)$row['id'];
  $r=$GLOBALS['kp_caller']->sql_query('SELECT CONNECTION_ID() AS id');$caller=$GLOBALS['kp_caller']->sql_fetchrow($r);$GLOBALS['kp_caller']->sql_freeresult($r);$peer=kp_rows('SELECT CONNECTION_ID() AS id');
  kp_check($own>0&&$own!==(int)$caller['id']&&$own!==(int)$peer[0]['id'],'Kill only the owned writer, never source/control/peer connection');kp_sql('KILL CONNECTION '.$own);
 };
 $result=kp_run(1);$kp_hook=null;kp_check($reached&&$result instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Actual server-killed owned connection rolls back all SQL effects and never closes caller');
 kp_check(is_array(kp_run(1))&&kp_counts(1)&&count(kp_rows('SELECT * FROM fixture_topics'))===1,'Healthy caller can retry after owned connection loss');$cases++;
 kp_reset();kp_sql('UPDATE fixture_kb_categories SET number_articles=16777215 WHERE category_id=1');$before=kp_snapshot();kp_check(kp_run(1) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Unsigned counter capacity failure rolls back article and preceding leaf increment');$cases++;
 foreach(array(null,array('1'),true,1.0,'1 OR 1=1','1e0','+1',' 1','0','16777216',"1\0")as$bad){
  kp_reset();$before=kp_snapshot();$connections=count($kp_owner_connections);kp_check(kp_run(1,'acp',$bad) instanceof PhpbbKbPublicationException&&count($kp_owner_connections)===$connections&&kp_snapshot()===$before,'Invalid complete article ID rejected before connection/writes');$cases++;
 }
 foreach(array(array('request'=>'GET'),array('sid'=>null),array('sid'=>array('a')),array('sid'=>str_repeat('b',32)))as$bad){
  kp_reset();$before=kp_snapshot();if(isset($bad['request'])){$_SERVER['REQUEST_METHOD']=$bad['request'];}else{$_POST['sid']=$bad['sid'];}
  $connections=count($kp_owner_connections);kp_check(kp_run(1) instanceof PhpbbKbPublicationException&&count($kp_owner_connections)===$connections&&kp_snapshot()===$before,'Invalid method/session rejected before connection/writes');$cases++;
 }
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARACTER SET latin1')as$legacy){
  kp_reset();kp_sql('ALTER TABLE fixture_kb_categories '.$legacy);$before=kp_snapshot();kp_check(kp_run(1) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Unsafe participant storage refuses all approval effects');kp_sql('ALTER TABLE fixture_kb_categories ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci');$cases++;
 }
 foreach(array("DELETE FROM fixture_forums WHERE forum_id=1","UPDATE fixture_kb_config SET config_value='4' WHERE config_name='admin_id'","UPDATE fixture_kb_config SET config_value='invalid' WHERE config_name='comments'")as$invalid){
  kp_reset('root',true);kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=4');kp_sql($invalid);$before=kp_snapshot();kp_check(kp_run(1) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Invalid current discussion/configuration target refuses all effects');$cases++;
 }
 foreach(array(false,true)as$comments){
  kp_reset('root',$comments);$mutations=0;$kp_hook=function($event,$sql)use(&$mutations){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){$mutations++;}};
  kp_check(is_array(kp_run(1))&&$mutations>0,'Collect exact fresh approval mutation inventory');$kp_hook=null;$total=$mutations;
  for($fail=1;$fail<=$total;$fail++){
   kp_reset('root',$comments);$before=kp_snapshot();$seen=0;
   $kp_hook=function($event,$sql)use(&$seen,$fail){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){return ++$seen===$fail?false:null;}};
   $result=kp_run(1);$kp_hook=null;kp_check($result instanceof PhpbbKbPublicationException&&$seen===$fail&&kp_snapshot()===$before,'Every mutation failure rolls back article/counters/index/discussion/statistics');$cases++;
  }
  foreach(array('before','after')as$boundary){
   kp_reset('root',$comments);$before=kp_snapshot();$reached=false;
   $kp_hook=function($event,$sql)use($boundary,&$reached){if($sql==='COMMIT'&&$event===$boundary){$reached=true;return false;}};
   $result=kp_run(1);$kp_hook=null;kp_check($reached&&$result instanceof PhpbbKbPublicationException,'Lost/refused commit is never reported successful');
   if($boundary==='before'){kp_check(kp_snapshot()===$before,'Refused pre-COMMIT keeps complete old state');}
   else{kp_check(kp_counts(1)&&count(kp_rows('SELECT * FROM fixture_kb_wordmatch'))>0&&count(kp_rows('SELECT * FROM fixture_topics'))===($comments?1:0),'Lost acknowledgement may leave complete committed publication, never a claimed rollback');kp_check(is_array(kp_run(1))&&kp_counts(1)&&count(kp_rows('SELECT * FROM fixture_topics'))===($comments?1:0),'Retry after committed-but-unacknowledged approval cannot duplicate counters or discussion');}
   $cases++;
  }
  kp_reset('root',$comments);kp_check(is_array(kp_run(1)),'Seed approved article for unapproval faults');$mutations=0;
  $kp_hook=function($event,$sql)use(&$mutations){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){$mutations++;}};
  kp_check(is_array(kp_run(0))&&$mutations>0,'Collect exact unapproval mutation inventory');$kp_hook=null;$total=$mutations;
  for($fail=1;$fail<=$total;$fail++){
   kp_reset('root',$comments);kp_check(is_array(kp_run(1)),'Seed approved article');$before=kp_snapshot();$seen=0;
   $kp_hook=function($event,$sql)use(&$seen,$fail){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){return ++$seen===$fail?false:null;}};
   $result=kp_run(0);$kp_hook=null;kp_check($result instanceof PhpbbKbPublicationException&&$seen===$fail&&kp_snapshot()===$before,'Every unapproval mutation failure preserves complete approved publication');$cases++;
  }
 }
 kp_load_controllers();
 foreach(array(array('root','acp'),array('junior','acp'),array('root','moderator'),array('moderator','moderator'))as$actor){
  foreach(array(false,true)as$comments){
   kp_reset($actor[0],$comments);$reached=false;
   $kp_hook=function($event,$sql)use(&$reached,$actor){
    if($event!=='before'||strpos($sql,'SELECT GET_LOCK(')!==0){return;}$GLOBALS['kp_hook']=null;$reached=true;
    kp_check(kp_controller_run($actor[1],'approve')->level===GENERAL_MESSAGE,'Independent complete controller publishes after outer cached lookup but before outer owner');
   };
   kp_check(kp_controller_run($actor[1],'approve')->level===GENERAL_MESSAGE&&$reached&&kp_counts(1),'Actual stale outer controller re-reads locked article and cannot double-count');$kp_hook=null;
   kp_check(count(kp_rows('SELECT * FROM fixture_topics'))===($comments?1:0),'Interleaved approvals create at most one discussion');$cases++;
  }
 }
 foreach(array(array('root','acp','UPDATE fixture_users SET user_level=0 WHERE user_id=1','fixture_users'),
  array('junior','acp',"UPDATE fixture_jr_admin_users SET user_jr_admin='' WHERE user_id=2",'fixture_jr_admin_users'),
  array('moderator','moderator','DELETE FROM fixture_user_group WHERE user_id=3','fixture_user_group'),
  array('root','moderator',"DELETE FROM fixture_sessions",'fixture_sessions'))as$revocation){
  foreach(array('entry','before-authority-lock','before-commit')as$boundary){
   kp_reset($revocation[0]);$before=kp_snapshot();$reached=false;$blocked=false;
   $change=function()use($revocation,&$reached,&$blocked){$reached=true;kp_sql('SET SESSION innodb_lock_wait_timeout=0');$r=$GLOBALS['peer']->sql_query($revocation[2]);if(!$r){$error=$GLOBALS['peer']->sql_error();kp_check((int)$error['code']===1205,'Only native authority-row lock timeout establishes serialization');$blocked=true;}kp_sql('SET SESSION innodb_lock_wait_timeout=1');};
   if($boundary==='entry'){$change();}
   else{$kp_hook=function($event,$sql)use($boundary,$change){$match=$boundary==='before-commit'?$sql==='COMMIT':strpos($sql,'SELECT session_id FROM fixture_sessions ')===0&&substr($sql,-18)==='LOCK IN SHARE MODE';if($event==='before'&&$match){$GLOBALS['kp_hook']=null;$change();}};}
   $result=kp_run(1,$revocation[1]);$kp_hook=null;kp_check($reached,'Authority race boundary reached');
   if($blocked){kp_check(is_array($result)&&kp_counts(1),'Pinned current authority permits complete publication before waiting revocation');}
   else{$after=kp_snapshot();unset($before[$revocation[3]],$after[$revocation[3]]);kp_check($result instanceof PhpbbKbPublicationException&&$after===$before,'Earlier revocation refuses publication, preserving only independent authority change');}$cases++;
  }
  kp_reset($revocation[0]);kp_sql($revocation[2]);$before=kp_snapshot();$result=kp_controller_run($revocation[1],'approve');kp_check($result->level===GENERAL_ERROR&&kp_snapshot()===$before,'Actual controller refuses revoked current rights despite cached admin snapshot');$cases++;
 }
 kp_reset();$reached=false;
 $kp_hook=function($event,$sql)use(&$reached){
  if($event!=='after'||strpos($sql,'SELECT * FROM fixture_kb_articles WHERE article_id=1 FOR UPDATE')!==0){return;}$GLOBALS['kp_hook']=null;$reached=true;
  $name='attachment:'.md5($GLOBALS['fixture']."\0".ATTACHMENTS_TABLE);kp_check(kp_rows("SELECT GET_LOCK('".$name."',0) AS held")===array(array('held'=>'0')),'Real independent writer cannot acquire held publication owner');
  kp_sql('SET SESSION innodb_lock_wait_timeout=0');kp_sql('SET SESSION lock_wait_timeout=0');
  foreach(array('UPDATE fixture_kb_articles SET approved=1 WHERE article_id=1','UPDATE fixture_kb_categories SET parent=0 WHERE category_id=2','ALTER TABLE fixture_kb_wordlist ENGINE=MyISAM')as$blocked_sql){$r=$GLOBALS['peer']->sql_query($blocked_sql);$error=$GLOBALS['peer']->sql_error();kp_check(!$r&&(int)$error['code']===1205,'Article/hierarchy/metadata pinned by actual transaction');}
  kp_sql('SET SESSION innodb_lock_wait_timeout=1');kp_sql('SET SESSION lock_wait_timeout=1');
 };
 kp_check(is_array(kp_run(1))&&$reached&&kp_counts(1),'Pinned native publication completes');$kp_hook=null;$cases++;
 kp_reset();kp_sql('CREATE TABLE fixture_caller (marker INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 kp_check($db->sql_query('START TRANSACTION')&&$db->sql_query('INSERT INTO fixture_caller VALUES(7)'),'Caller pending transaction');
 kp_check(is_array(kp_run(1))&&kp_counts(1)&&kp_rows('SELECT * FROM fixture_caller')===array(),'Owned commit cannot commit unrelated caller work');
 $r=$db->sql_query('SELECT marker FROM fixture_caller');$marker=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_check((int)$marker['marker']===7&&$db->sql_query('ROLLBACK')&&kp_counts(1),'Caller rollback remains independent of confirmed publication');kp_sql('DROP TABLE fixture_caller');$cases++;
 $original_lang=$lang;
 foreach(array('english','german')as$locale){require $kp_source.'language/lang_'.$locale.'/lang_kb.php';kp_reset('moderator');$before=kp_snapshot();$result=kp_controller_run('moderator','unapprove',false);kp_check($result->level===GENERAL_MESSAGE&&strpos($result->getMessage(),$lang['Un_approve'])!==false&&kp_snapshot()===$before,'Real confirmation form uses available localized unapproval label');$cases++;}
 $lang=$original_lang;
 echo 'KB publication native service: '.$cases." authority/transition/factory/fault cases passed.\n";
} finally {
 $kp_hook=null;
 foreach($kp_owner_connections as $connection){try{$connection->sql_close();}catch(Exception $ignored){}catch(Error $ignored){}}
 if($db){$db->sql_close();}if($peer){$peer->sql_close();}kp_check($control->sql_query('DROP DATABASE '.$fixture),'Remove only own fixture schema');$control->sql_close();
 restore_error_handler();
 error_reporting($kp_original_reporting);
}
