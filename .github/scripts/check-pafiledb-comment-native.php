<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_PA_COMMENT_NATIVE') !== '1') { echo "Native paFileDB comment fixture skipped.\n"; return; }
define('IN_PHPBB', true);
$project = dirname(dirname(__DIR__)); $phpbb_root_path = $project . '/phpBB2/'; $phpEx = 'php'; $table_prefix = 'fixture_';
require $phpbb_root_path . 'includes/php_compat.php';
require $phpbb_root_path . 'includes/constants.php';
require $phpbb_root_path . 'attach_mod/includes/constants.php';
require $phpbb_root_path . 'pafiledb/includes/pafiledb_constants.php';
require $phpbb_root_path . 'db/mysqli.php';
require $phpbb_root_path . 'pafiledb/includes/functions.php';
require $phpbb_root_path . 'pafiledb/includes/functions_comment_storage.php';
require $phpbb_root_path . 'pafiledb/includes/functions_comment.php';
require $phpbb_root_path . 'pafiledb/modules/pa_post_comment.php';
require $project . '/update/innodb_migration.php';

$pc_core_source = file_get_contents($phpbb_root_path . 'includes/functions.php');
foreach (array('phpbb_profile_text', 'phpbb_profile_details_visible') as $name) {
 if (preg_match('/^function ' . $name . '\(.*?^\}/ms', $pc_core_source, $match) !== 1) { throw new RuntimeException('Actual profile helper missing: ' . $name); }
 eval($match[0]);
}

class PaCommentMessage extends RuntimeException {
 public $level;
 function __construct($level, $text) { $this->level = $level; parent::__construct($text); }
}
function message_die($level, $text) { throw new PaCommentMessage($level, $text); }
function append_sid($url, $html = true) { return $url; }
function redirect($url) { throw new PaCommentMessage(GENERAL_MESSAGE, 'redirect:' . $url); }
function create_date($format, $time, $zone) { return 'fixture date'; }
function obtain_word_list(&$words, &$replacements) { $words = $replacements = array(); }
function pc_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['pc_cases']++; }

class PaCommentConnection {
 public $inner, $db_connect_id;
 function __construct($inner) { $this->inner = $inner; $this->db_connect_id = $inner->db_connect_id; }
 function __call($name, $args) { return call_user_func_array(array($this->inner, $name), $args); }
 function sql_query($sql, $transaction = false) {
  if (is_callable($GLOBALS['pc_hook'])) { call_user_func($GLOBALS['pc_hook'], $sql); }
  if (strpos($sql, 'INSERT INTO fixture_pa_comments') === 0 || strpos($sql, 'DELETE FROM fixture_pa_comments') === 0) { $GLOBALS['pc_mutated'] = true; }
  if ($GLOBALS['pc_failure'] === 'lost-commit-ack' && $sql === 'COMMIT' && $GLOBALS['pc_mutated']) { $this->inner->sql_query($sql, $transaction); return false; }
  if ($GLOBALS['pc_failure'] !== '' && strpos($sql, $GLOBALS['pc_failure']) === 0 && ($GLOBALS['pc_failure'] !== 'COMMIT' || $GLOBALS['pc_mutated'])) { return false; }
  return $this->inner->sql_query($sql, $transaction);
 }
}
class PaCommentSource extends sql_db {
 function sql_dedicated_connection() {
  $GLOBALS['pc_factories']++;
  if (is_callable($GLOBALS['pc_before_owner'])) { $action = $GLOBALS['pc_before_owner']; $GLOBALS['pc_before_owner'] = null; call_user_func($action); }
  if (isset($GLOBALS['pc_borrowed'])) { return $GLOBALS['pc_borrowed']; }
  $owner = new PaCommentConnection(parent::sql_dedicated_connection()); $GLOBALS['pc_last_owner'] = $owner; return $owner;
 }
}
class PaCommentTemplate {
 public $data = array(), $rows = array();
 function assign_vars($data) { $this->data = array_merge($this->data, $data); }
 function assign_block_vars($block, $data) { $this->rows[$block][] = $data; }
}
class PaCommentStyle {
 function make_filename($file) { return $GLOBALS['phpbb_root_path'] . 'templates/fisubsilversh/' . $file; }
}
class PaCommentController extends pafiledb_post_comment {
 function generate_category_nav($cat_id) {}
 function display($title, $template) {}
}
class PaCommentFunctions extends pafiledb_functions {
 function pa_generate_smilies($mode, $page_id) {}
 function obtain_ranks(&$ranks) { $ranks = array(); }
}
function pc_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own comment fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error()));}return$r;}
function pc_rows($sql){$r=pc_sql($sql);try{$rows=$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}foreach($rows as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return$rows;}
function pc_insert($table,$values){foreach(pc_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pc_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES('.implode(',',$encoded).')');}

function pc_reset() {
 global $userdata, $board_config, $pafiledb_config, $pafiledb_template, $pc_failure, $pc_hook, $pc_mutated, $pc_before_owner;
 $pc_failure = ''; $pc_hook = $pc_before_owner = null; $pc_mutated = false; unset($GLOBALS['pc_borrowed']);
 foreach (array('pa_comments','pa_files','pa_cat','pa_auth','pa_config','users','sessions','user_group','groups','config') as $suffix) { pc_sql('DELETE FROM fixture_' . $suffix); }
 pc_insert('fixture_config', array('config_name'=>'session_length','config_value'=>'3600'));
 pc_insert('fixture_config', array('config_name'=>'allow_html_tags','config_value'=>'b,i,a,img'));
 foreach (array(-1,8,9) as $id) {
  pc_insert('fixture_users', array('user_id'=>$id,'username'=>$id === -1 ? 'Anonymous' : 'Member ' . $id,'user_active'=>$id === -1 ? 0 : 1,'user_level'=>USER,'user_allowhtml'=>0,'user_allowbbcode'=>1,'user_allowsmile'=>1));
 }
 foreach (array(array('fixture-member',8,1),array('fixture-other',9,1),array('fixture-guest',-1,0)) as $item) {
  pc_insert('fixture_sessions', array('session_id'=>$item[0],'session_user_id'=>$item[1],'session_logged_in'=>$item[2],'session_time'=>time()));
 }
 pc_insert('fixture_pa_cat', array('cat_id'=>5,'cat_name'=>'Root','cat_parent'=>null,'parents_data'=>'','cat_allow_comments'=>1,'auth_view'=>AUTH_ALL,'auth_read'=>AUTH_ALL,'auth_view_file'=>AUTH_ALL,'auth_view_comment'=>AUTH_ALL,'auth_post_comment'=>AUTH_REG,'auth_delete_comment'=>AUTH_REG));
 pc_insert('fixture_pa_files', array('file_id'=>42,'user_id'=>8,'file_name'=>'<b>Größe 😀 & Titel</b>','real_name'=>'fixture.bin','file_dir'=>'','file_catid'=>5,'file_approved'=>1));
 $pafiledb_config = array('allow_html'=>0,'allow_bbcode'=>1,'allow_smilies'=>1,'max_comment_chars'=>5000,'allow_comment_images'=>1,'allow_comment_links'=>1,'no_comment_image_message'=>'[no image]','no_comment_link_message'=>'[no link]','settings_dbname'=>'Fixture');
 foreach (array('allow_html','allow_bbcode','allow_smilies','max_comment_chars') as $name) { pc_insert('fixture_pa_config', array('config_name'=>$name,'config_value'=>$pafiledb_config[$name])); }
 $userdata = array('user_id'=>8,'session_id'=>'fixture-member','session_logged_in'=>true,'user_level'=>USER,'user_allowhtml'=>0,'user_allowbbcode'=>1,'user_allowsmile'=>1);
 $board_config = array('allow_html_tags'=>'b,i,a,img','default_dateformat'=>'Y-m-d','board_timezone'=>0,'sitename'=>'Fixture','hide_removed_author_names'=>0,'smilies_path'=>'images/smiles');
 $_SERVER['REQUEST_METHOD'] = 'POST'; $_REQUEST = array('file_id'=>'42');
 $_POST = array('sid'=>'fixture-member','submit'=>'Post','subject'=>'Grüße 😀','message'=>'äöü 😀 & \\ Pfad');
 $pafiledb_template = new PaCommentTemplate();
}
function pc_post($title = 'Grüße 😀', $text = 'äöü 😀 & \\ Pfad') { return pafiledb_comment_write($GLOBALS['db'], 'post', 42, 0, $title, $text); }
function pc_delete($id) { return pafiledb_comment_write($GLOBALS['db'], 'delete', 42, $id); }
function pc_denied($action, $key = null) {
 $caught = null; try { $action(); } catch (PaFileDbCommentFailure $error) { $caught = $error->getMessage(); }
 pc_check($caught !== null && ($key === null || $caught === $key), 'Expected controlled comment failure ' . (string)$key . ', got ' . (string)$caught);
}
function pc_count() { $rows = pc_rows('SELECT COUNT(*) n FROM fixture_pa_comments'); return (int)$rows[0]['n']; }
function pc_controller($controller) {
 try { $controller->main(); } catch (PaCommentMessage $message) { return $message; }
 return null;
}
$port = getenv('PHPBB_PA_COMMENT_PORT') ?: '3306'; $password = getenv('PHPBB_PA_COMMENT_PASSWORD') ?: '';
if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port < 1 || (int)$port > 65535) { throw new RuntimeException('Own loopback fixture port required'); }
$fixture = 'codex_pa_comment_' . bin2hex(phpbb_random_bytes(8)); $host = '127.0.0.1:' . $port;
$control = new sql_db($host, 'root', $password, '', false); $db = $peer = null; $created = false; $pc_cases = $pc_factories = 0;
$pc_failure = ''; $pc_hook = $pc_before_owner = null; $pc_mutated = false;
try {
 pc_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Own disposable comment schema'); $created = true;
 $db = new PaCommentSource($host, 'root', $password, $fixture, false); $peer = new sql_db($host, 'root', $password, $fixture, false);
 $schema = file_get_contents($phpbb_root_path . 'install/schemas/mysql_schema.sql');
 foreach (array('pa_files','pa_comments','pa_cat','pa_auth','pa_config','users','sessions','user_group','groups','config','smilies') as $suffix) {
  pc_check(preg_match('/^CREATE TABLE phpbb_' . $suffix . '\\s*\\([\\s\\S]*?;/m', $schema, $match) === 1, 'Canonical comment participant');
  pc_sql(str_replace('phpbb_' . $suffix, 'fixture_' . $suffix, $match[0]));
 }
 pc_insert('fixture_smilies', array('smilies_id'=>1,'code'=>':)','smile_url'=>'fixture.gif','emoticon'=>'Smile'));
 foreach (array('english','german') as $locale) {
  $lang = array(); require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php'; require $phpbb_root_path . 'language/lang_' . $locale . '/lang_pafiledb.php';
  foreach (array('PA_comment_failed','PA_comment_upgrade','PA_comment_invalid','PA_comment_too_long','PA_comment_storage_limit') as $key) { pc_check(isset($lang[$key]) && preg_match('//u', $lang[$key]) === 1, 'Complete UTF-8 comment translations'); }
 }
 $template = new PaCommentStyle(); $pafiledb_functions = new PaCommentFunctions(); $images = array('icon_delpost'=>'delete','icon_minipost'=>'post','pa_comment_post'=>'reply');
 pc_reset(); $saved = pc_post(); $stored = pc_rows('SELECT * FROM fixture_pa_comments');
 pc_check(count($stored) === 1 && $stored[0]['comments_title'] === 'Grüße 😀' && html_entity_decode($stored[0]['comments_text'], ENT_QUOTES, 'UTF-8') === 'äöü 😀 & \\ Pfad', 'Raw Unicode, ampersand and backslash survive actual writer');
 pc_check($saved['comment_id'] === (int)$stored[0]['comments_id'], 'Read-back comment identity'); pc_delete($saved['comment_id']); pc_check(pc_count() === 0, 'Owner can delete own comment');

 foreach (array("DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_time=1","UPDATE fixture_sessions SET session_user_id=9","UPDATE fixture_users SET user_active=0 WHERE user_id=8","DELETE FROM fixture_users WHERE user_id=8","DELETE FROM fixture_pa_files","UPDATE fixture_pa_files SET file_approved=0","UPDATE fixture_pa_cat SET cat_allow_comments=0","UPDATE fixture_pa_cat SET auth_post_comment=" . AUTH_ADMIN,"UPDATE fixture_pa_cat SET auth_view_file=" . AUTH_ADMIN,"UPDATE fixture_pa_cat SET cat_parent=5") as $change) {
  pc_reset(); pc_sql($change); pc_denied(function(){pc_post();}); pc_check(pc_count() === 0, 'Revoked current context cannot retain a comment');
 }
 foreach (array('wrong','',array()) as $token) { pc_reset(); $_POST['sid'] = $token; $before = $pc_factories; pc_denied(function(){pc_post();}, 'Session_invalid'); pc_check($before === $pc_factories, 'Bad token never opens writer'); }
 pc_reset(); $_SERVER['REQUEST_METHOD'] = 'GET'; pc_denied(function(){pc_post();}, 'Session_invalid');
 foreach (array(array('post',0,0),array('post','42x',0),array('delete',42,0),array('delete',42,'2147483648'),array('edit',42,1)) as $input) {
  pc_reset(); pc_denied(function()use($input){pafiledb_comment_write($GLOBALS['db'],$input[0],$input[1],$input[2]);});
 }
 pc_reset(); pc_sql('UPDATE fixture_pa_cat SET auth_post_comment=' . AUTH_ACL); pc_insert('fixture_groups',array('group_id'=>12,'group_single_user'=>0,'group_name'=>'Fixture')); pc_insert('fixture_user_group',array('user_id'=>8,'group_id'=>12,'user_pending'=>0)); pc_insert('fixture_pa_auth',array('group_id'=>12,'cat_id'=>5,'auth_post_comment'=>1));
 pc_post(); pc_sql('UPDATE fixture_user_group SET user_pending=1'); pc_denied(function(){pc_post();},'Not_Authorised'); pc_sql('UPDATE fixture_user_group SET user_pending=0'); pc_sql('DELETE FROM fixture_groups'); pc_denied(function(){pc_post();},'Not_Authorised'); pc_check(pc_count()===1,'Only nonpending membership in existing group grants posting');
 pc_reset(); pc_sql('UPDATE fixture_pa_cat SET auth_view_comment=' . AUTH_ADMIN); pc_post(); pc_check(pc_count()===1,'Independent post permission permits blind feedback');
 pc_reset(); pc_insert('fixture_pa_cat',array('cat_id'=>6,'cat_name'=>'Parent','cat_parent'=>0,'parents_data'=>'','auth_view'=>AUTH_ADMIN,'auth_read'=>AUTH_ALL));pc_sql('UPDATE fixture_pa_cat SET cat_parent=6 WHERE cat_id=5');pc_denied(function(){pc_post();},'Not_Authorised');pc_check(pc_count()===0,'Restricted ancestor cannot be bypassed by direct comment URL');
 pc_reset(); $userdata=array('user_id'=>-1,'session_id'=>'fixture-guest','session_logged_in'=>false); $_POST['sid']='fixture-guest'; pc_denied(function(){pc_post();},'Not_Authorised'); pc_sql('UPDATE fixture_pa_cat SET auth_post_comment=' . AUTH_ALL); $guest=pc_post(); pc_denied(function()use($guest){pc_delete($guest['comment_id']);},'Not_Authorised'); pc_check(pc_count()===1,'Configured real guest may post but cannot own-delete');

 foreach (array('deactivate','revoke','target','missing','file') as $case) {
  pc_reset(); $own=pc_post(); $id=$own['comment_id'];
  if($case==='deactivate'){pc_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
  if($case==='revoke'){pc_sql('UPDATE fixture_pa_cat SET auth_delete_comment=' . AUTH_ADMIN);}
  if($case==='target'){pc_sql('UPDATE fixture_pa_comments SET poster_id=9');}
  if($case==='missing'){pc_sql('DELETE FROM fixture_pa_comments');}
  if($case==='file'){pc_sql('UPDATE fixture_pa_comments SET file_id=43');}
  pc_denied(function()use($id){pc_delete($id);});
  pc_check(pc_count()===($case==='missing'?0:1),'Delete checks exact existing target and current actor');
 }
 pc_reset(); pc_insert('fixture_pa_comments',array('comments_id'=>17,'file_id'=>42,'poster_id'=>9,'comments_title'=>'Other','comments_text'=>'Keep'));
 pc_sql('UPDATE fixture_users SET user_level=' . ADMIN . ' WHERE user_id=8'); pc_delete(17); pc_check(pc_count()===0,'Current administrator may delete another comment');
 pc_reset();pc_insert('fixture_pa_comments',array('comments_id'=>17,'file_id'=>42,'poster_id'=>9,'comments_title'=>'Other','comments_text'=>'Keep'));pc_insert('fixture_groups',array('group_id'=>12,'group_single_user'=>0,'group_name'=>'Fixture'));pc_insert('fixture_user_group',array('user_id'=>8,'group_id'=>12,'user_pending'=>0));pc_insert('fixture_pa_auth',array('group_id'=>12,'cat_id'=>5,'auth_mod'=>1));pc_delete(17);pc_check(pc_count()===0,'Existing group moderator can delete another comment');

 pc_reset(); pc_sql("UPDATE fixture_pa_config SET config_value='3' WHERE config_name='max_comment_chars'"); pc_post('Title','äöü'); pc_post('Title','😀😀😀'); pc_denied(function(){pc_post('Title','äöüß');},'PA_comment_too_long'); pc_check(pc_count()===2,'Limits count Unicode characters rather than bytes');
 foreach(array(array(), "bad\0text", "\xff", '') as $text){pc_reset();pc_denied(function()use($text){pc_post('Title',$text);});pc_check(pc_count()===0,'Invalid/empty input leaves no comment');}
 pc_reset(); pc_denied(function(){pc_post(str_repeat('a',65536),'Text');},'PA_comment_storage_limit'); pc_check(pc_count()===0,'Physical subject capacity enforced');
 pc_reset();pc_denied(function(){pc_post(array(),'Text');},'PA_comment_invalid');pc_sql('ALTER TABLE fixture_pa_comments MODIFY comments_title varchar(3) NOT NULL');pc_denied(function(){pc_post('Too long title','Text');},'PA_comment_storage_limit');pc_sql('ALTER TABLE fixture_pa_comments MODIFY comments_title text NOT NULL');pc_check(pc_count()===0,'Current physical capacities protect customized installations');
 pc_reset(); pc_sql("UPDATE fixture_pa_config SET config_value='65535' WHERE config_name='max_comment_chars'"); pc_denied(function(){pc_post('Title',str_repeat('<',20000));},'PA_comment_storage_limit'); pc_check(pc_count()===0,'Escaping expansion is checked before INSERT');

 foreach(array('INSERT INTO fixture_pa_comments','SELECT * FROM fixture_pa_comments WHERE comments_id=','COMMIT') as $fault) {
  pc_reset();$pc_failure=$fault;pc_denied(function(){pc_post();});$pc_failure='';pc_check(pc_count()===0,'Failed INSERT/read-back/COMMIT rolls back');pc_post();pc_check(pc_count()===1,'Failed owner releases lease for next request');
 }
 pc_reset();$own=pc_post();$pc_failure='DELETE FROM fixture_pa_comments';pc_denied(function()use($own){pc_delete($own['comment_id']);});$pc_failure='';pc_check(pc_count()===1,'Failed DELETE preserves target');
 pc_reset();$own=pc_post();$pc_mutated=false;$pc_failure='COMMIT';pc_denied(function()use($own){pc_delete($own['comment_id']);});$pc_failure='';pc_check(pc_count()===1,'Failed delete commit restores the exact target');
 pc_reset();$pc_failure='lost-commit-ack';pc_denied(function(){pc_post();});$pc_failure='';pc_check(pc_count()===1,'Uncertain commit never silently rolls back or replays an accepted comment');
 pc_reset();$pc_hook=function($sql){if(strpos($sql,'INSERT INTO fixture_pa_comments')===0){$GLOBALS['pc_hook']=null;$GLOBALS['pc_last_owner']->inner->sql_close();}};pc_denied(function(){pc_post();});pc_check(pc_count()===0 && $db->sql_query('SELECT 1')!==false,'Lost writer does not reconnect or damage caller');
 pc_reset();$pc_borrowed=$db;pc_denied(function(){pc_post();});unset($GLOBALS['pc_borrowed']);pc_check($db->sql_query('SELECT 1')!==false,'Source handle cannot be borrowed');
 pc_reset();$db->sql_query('START TRANSACTION');$db->sql_query("INSERT INTO fixture_config(config_name,config_value) VALUES('caller-owned','pending')");pc_post();$r=$db->sql_query('SELECT @@in_transaction active');$state=$db->sql_fetchrow($r);$db->sql_freeresult($r);pc_check((int)$state['active']===1,'Comment does not commit caller transaction');$db->sql_query('ROLLBACK');pc_check(!pc_rows("SELECT * FROM fixture_config WHERE config_name='caller-owned'")&&pc_count()===1,'Dedicated comment persists independently of caller rollback');
 pc_reset();pc_sql('ALTER TABLE fixture_pa_comments ENGINE=MyISAM');pc_denied(function(){pc_post();},'PA_comment_upgrade');foreach(plus_storage_plan($peer->db_connect_id,array('fixture_pa_comments'))as$item){plus_storage_query($peer->db_connect_id,$item);}pc_post();pc_check(pc_count()===1,'Consolidated updater restores canonical comment storage');

 // One bounded peer wait proves serialization without repeating it per case.
 pc_reset();pc_sql('SET SESSION innodb_lock_wait_timeout=1');$blocked=false;
 $pc_hook=function($sql)use(&$blocked){if(strpos($sql,'INSERT INTO fixture_pa_comments')!==0){return;}$GLOBALS['pc_hook']=null;$ok=$GLOBALS['peer']->sql_query('UPDATE fixture_pa_files SET file_approved=0 WHERE file_id=42');$err=$GLOBALS['peer']->sql_error();$blocked=$ok===false&&(int)$err['code']===1205;};
 pc_post();pc_check($blocked&&pc_count()===1,'Peer file withdrawal serializes through actual comment commit');

 $controller=new PaCommentController();$controller->auth=array(5=>array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true,'auth_mod'=>true,'auth_post_comment'=>true,'auth_delete_comment'=>true));$controller->cat_rowset=array(5=>array('cat_parent'=>0,'cat_allow_comments'=>1));
 pc_reset();$pc_before_owner=function(){pc_sql('DELETE FROM fixture_pa_files');};$result=pc_controller($controller);pc_check($result&&$result->getMessage()===$lang['File_not_exist']&&pc_count()===0,'Actual cached controller rejects file removed before writer');
 pc_reset();pc_insert('fixture_pa_comments',array('comments_id'=>17,'file_id'=>42,'poster_id'=>9,'comments_title'=>'Other','comments_text'=>'Keep'));$_REQUEST=array('file_id'=>42,'cid'=>17,'delete'=>'do');$_POST=array('sid'=>'fixture-member','confirm_delete'=>'Yes');pc_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=8');$result=pc_controller($controller);pc_check($result&&$result->getMessage()===$lang['Not_Authorised']&&pc_count()===1,'Actual stale moderator cannot delete after deactivation');
 pc_reset();$controller->auth[5]['auth_post_comment']=false;$result=pc_controller($controller);pc_check($result&&pc_count()===1,'Actual mutation uses fresh grants instead of cached denial');$controller->auth[5]['auth_post_comment']=true;
 pc_reset();$_POST=array('sid'=>'fixture-member','preview'=>'Preview','subject'=>'<b>Grüße &amp;</b>','message'=>"[b]Grüße 😀[/b] & \\ Pfad\nhttps://example.invalid/");pc_check(pc_controller($controller)===null,'Actual preview renders form');$preview=$pafiledb_template->data;
 pc_check($preview['SUBJECT']===htmlspecialchars($_POST['subject'],ENT_QUOTES,'UTF-8')&&$preview['COMMENT']===htmlspecialchars($_POST['message'],ENT_QUOTES,'UTF-8'),'Preview retains exact typed subject and body');
 unset($_POST['preview']);$_POST['submit']='Post';$result=pc_controller($controller);$row=pc_rows('SELECT * FROM fixture_pa_comments');$rendered=pafiledb_comment_render($row[0]['comments_text'],$row[0]['comment_bbcode_uid'],$pafiledb_config,true);
 pc_check($result&&$preview['PRE_COMMENT']===$rendered&&strpos($rendered,'Grüße 😀')!==false&&strpos($rendered,'[b:')===false,'Actual preview and saved BBCode output agree after a single encoding pass');
 $pafiledb_template=new PaCommentTemplate();$pafiledb=(object)array('module_name'=>'file','modules'=>array('file'=>$controller));$file_data=array('file_id'=>42,'file_catid'=>5);display_comments($file_data);$visible=$pafiledb_template->rows['text'][0];pc_check($visible['TITLE']===htmlspecialchars($_POST['subject'],ENT_QUOTES,'UTF-8')&&$visible['TEXT']===$rendered,'Actual comment display escapes literal subject and matches preview');
 pc_sql("UPDATE fixture_pa_comments SET comments_title='<b>Literal title</b>',comments_text='<b>Literal body</b>',comment_bbcode_uid=''");$pafiledb_template=new PaCommentTemplate();$userdata['user_allowhtml']=0;display_comments($file_data);$visible=$pafiledb_template->rows['text'][0];pc_check($visible['TITLE']==='&lt;b&gt;Literal title&lt;/b&gt;'&&strpos($visible['TEXT'],'<b>')===false,'Actual display escapes legacy raw HTML independently of viewer HTML preference');
 pc_sql('UPDATE fixture_pa_comments SET poster_id=999');$pafiledb_template=new PaCommentTemplate();display_comments($file_data);pc_check($pafiledb_template->rows['text'][0]['POSTER']===$lang['Guest'],'Missing joined account has safe guest display');
 $controller->cat_rowset[5]['cat_allow_comments']=0;$pafiledb_template=new PaCommentTemplate();display_comments($file_data);pc_check(!$pafiledb_template->data['AUTH_POST'],'Disabled category removes posting link');$controller->cat_rowset[5]['cat_allow_comments']=1;

 pc_reset();$settings=$pafiledb_config;$settings['allow_html']=1;$settings['_html_tags']=$board_config['allow_html_tags'];$user=$userdata;$user['user_allowhtml']=1;$caps=pafiledb_comment_capacities($db);
 $prepared=pafiledb_comment_prepare('Literal &amp;','<a href="https://example.invalid/?a=1&b=2">Grüße &</a> \\ Pfad',$settings,$user,$caps);$html=pafiledb_comment_render($prepared['text'],$prepared['uid'],$settings);
 pc_check(strpos($html,'&amp;amp;')===false&&strpos($html,'href="https://example.invalid/?a=1&amp;amp;')===false&&strpos($html,'\\ Pfad')!==false,'Stored HTML entities and backslashes are not escaped twice');
 pc_check(strpos($html,'href="https://example.invalid/?a=1&amp;b=2"')!==false,'Stored permitted HTML attribute retains exact query parameters');
 $safe=pafiledb_comment_safe_html('<b onclick="fixture()">Text</b><a href="javascript:fixture()">Link</a>',true);pc_check(strpos($safe,'onclick')===false&&strpos($safe,'javascript:')===false&&strpos($safe,'<b>Text</b>')!==false,'Stored HTML reuses current tag/attribute/scheme sanitizer');
 $board_config['allow_html_tags']='i';$safe=pafiledb_comment_safe_html('<b>Text</b>',true);pc_check(strpos($safe,'<b>')===false,'Revoked HTML tag allowance applies to existing comments');$board_config['allow_html_tags']='b,i,a,img';
 $raw="C:\\Pfad\\Datei O'Reilly &amp;";foreach(array(0,1)as$html_on){$roundtrip=$settings;$roundtrip['allow_html']=$html_on;$prepared=pafiledb_comment_prepare('Title',$raw,$roundtrip,$user,$caps);$view=pafiledb_comment_render($prepared['text'],$prepared['uid'],$roundtrip);pc_check(html_entity_decode($view,ENT_QUOTES,'UTF-8')===$raw,'Raw quotes, backslashes and literal entities survive HTML-on/off encoding');}
 $policy=$settings;$policy['allow_comment_links']=0;$policy['allow_comment_images']=0;$prepared=pafiledb_comment_prepare('Title','[url=https://example.invalid/]Link[/url] [img]https://example.invalid/a.png[/img] https://example.invalid/',$settings,$user,$caps);$limited=pafiledb_comment_render($prepared['text'],$prepared['uid'],$policy);
 pc_check(strpos($limited,'<a ')===false&&strpos($limited,'<img ')===false&&strpos($limited,'[no link]')!==false&&strpos($limited,'[no image]')!==false,'Current image/link policy applies after BBCode and before automatic links');
 $prepared=pafiledb_comment_prepare('Title',':)',$settings,$user,$caps);$smile=pafiledb_comment_render($prepared['text'],$prepared['uid'],$policy,true);pc_check(strpos($smile,'fixture.gif')!==false,'Disabled user images preserve configured smileys');
 pc_reset();pc_sql('UPDATE fixture_users SET user_allowbbcode=0 WHERE user_id=8');pc_post('Title','[b]Literal[/b]');$plain=pc_rows('SELECT * FROM fixture_pa_comments');pc_check($plain[0]['comment_bbcode_uid']===''&&$plain[0]['comments_text']==='[b]Literal[/b]','Current stored user preference controls first-pass encoding');
 $prepared=pafiledb_comment_prepare('Title','[code]https://example.invalid/ [url]literal[/url][/code]',$settings,$user,$caps);$code=pafiledb_comment_render($prepared['text'],$prepared['uid'],$policy);pc_check(strpos($code,'https://example.invalid/')!==false&&strpos($code,'<a ')===false,'Disabled links retain literal URLs in code examples');
 // The real bootstrap escapes request values for legacy controllers. Exercise
 // the explicit modern boundary once, after raw-input fixture cases above.
 define('PHPBB_LEGACY_REQUEST_ESCAPED',true);pc_reset();$raw="Grüße 😀 C:\\Pfad O'Reilly &amp;";$_POST['subject']=addslashes($raw);$_POST['message']=addslashes($raw);$result=pc_controller($controller);$row=pc_rows('SELECT * FROM fixture_pa_comments');pc_check($result&&$row[0]['comments_title']===$raw&&html_entity_decode($row[0]['comments_text'],ENT_QUOTES,'UTF-8')===$raw,'Actual controller removes bootstrap request escaping exactly once');
 echo 'Native paFileDB comments: ', $pc_cases, " actual-controller/storage/preview/display/Unicode/ACL/session/rollback/migration/peer-lock cases PASS; own disposable schema only.\n";
} finally {
 unset($GLOBALS['pc_borrowed']);
 foreach(array($peer,$db) as $connection){if($connection){$connection->sql_close();}}
 if(!preg_match('/^codex_pa_comment_[a-f0-9]{16}$/D',$fixture)){throw new RuntimeException('Owned comment schema identity required');}
 if($created){$control->sql_query('DROP DATABASE '.$fixture);} $control->sql_close();
}
