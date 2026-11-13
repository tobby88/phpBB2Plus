<?php
// Actual entry controller/providers and canonical InnoDB tables. Only final
// mail transport is replaced; no real accounts, config, mail or remote URLs.
if(PHP_SAPI!=='cli'||getenv('PHPBB_LINK_NATIVE')!=='1'){echo "Link registration requires explicitly enabled disposable storage.\n";return;}
if(!in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true)){throw new RuntimeException('Real mail must be disabled');}
function lr_classes(){
 class LinkNativeDatabase extends KpDatabase{
  function sql_dedicated_connection(){
   if($GLOBALS['kp_factory']==='alias'){return new KpOwnerConnection($this);}
   if($GLOBALS['kp_factory']==='busy'){return new KpOwnerConnection($GLOBALS['peer']);}
   return parent::sql_dedicated_connection();
  }
 }
}
class LinkNativeTemplate{var$vars=array();function assign_vars($values){$this->vars=array_merge($this->vars,$values);}}
function lr_reset($email=false,$pm=false,$locale='english'){
 global$kp_hook,$kp_factory,$userdata,$lang,$board_config,$phpbb_root_path,$kp_source,$lr_mail,$lr_mail_fault;
 $kp_hook=null;$kp_factory='normal';$phpbb_root_path=$kp_source;$lr_mail=array();$lr_mail_fault='';
 kp_sql('START TRANSACTION');
 try{
  foreach($GLOBALS['kp_tables']as$table){kp_sql('DELETE FROM '.$table);}
  kp_sql("INSERT INTO fixture_users(user_id,username,user_password,user_email,user_lang,user_active,user_level,user_allow_pm) VALUES(1,'Admin EN','synthetic','en@example.invalid','english',1,1,1),(2,'Admin DE','synthetic','de@example.invalid','german',1,1,1),(4,'Author','synthetic','author@example.invalid','english',1,0,1)");
  kp_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_admin) VALUES('".str_repeat('a',32)."',4,1,0)");
  kp_sql("INSERT INTO fixture_link_categories(cat_id,cat_title,cat_order) VALUES(1,'Synthetic',1)");
  kp_sql("INSERT INTO fixture_link_config(config_name,config_value) VALUES('lock_submit_site','0'),('allow_no_logo','1'),('email_notify','".($email?'1':'0')."'),('pm_notify','".($pm?'1':'0')."')");
  kp_sql("INSERT INTO fixture_config(config_name,config_value) VALUES('privmsg_disable','0'),('max_inbox_privmsgs','50'),('default_lang','english'),('sitename','Current Grüße 😀 board'),('board_email','current@example.invalid')");
  kp_sql('COMMIT');
 }catch(Exception$error){kp_sql('ROLLBACK');throw$error;}catch(Error$error){kp_sql('ROLLBACK');throw$error;}
 $userdata=array('user_id'=>4,'session_id'=>str_repeat('a',32),'session_logged_in'=>true,'user_level'=>0);
 $board_config=array('smtp_delivery'=>0,'board_email'=>'cached@example.invalid','sitename'=>'Cached board','default_lang'=>$locale,'sendmail_fix'=>0,'server_name'=>'forum.example.invalid');
 $lang=array();require$kp_source.'language/lang_'.$locale.'/lang_main.php';require$kp_source.'language/lang_'.$locale.'/lang_main_link.php';
 $_SERVER['REQUEST_METHOD']='POST';$_GET=array();$_POST=array('sid'=>$userdata['session_id'],'link_title'=>'Synthetic Grüße 😀 link','link_desc'=>'Synthetic description','link_category'=>'1','link_url'=>'https://example.invalid/?a=1&b=2','link_logo_src'=>'');
}
function lr_controller(){
 global$db,$userdata,$lang,$phpbb_root_path,$phpEx,$template,$lr_body;
 $template=new LinkNativeTemplate();$prior_db=$db;$prior_lang=$lang;$prior_config=$GLOBALS['board_config'];
 try{eval($lr_body);throw new RuntimeException('Actual link controller must terminate');}
 catch(KpMessageExit$error){$out=$error;}
 kp_check($db===$prior_db&&$lang===$prior_lang&&$GLOBALS['board_config']===$prior_config,'Controller preserves caller database/language/settings');
 foreach($GLOBALS['kp_owner_connections']as$owner){kp_check(!$owner->connection->db_connect_id,'Every link owner released before response');}
 return$out;
}
function lr_success($response){return strpos($response->getMessage(),$GLOBALS['lang']['Link_update_success'])!==false;}
function lr_suite(){
 global$kp_source,$db,$peer,$userdata,$kp_hook,$kp_factory,$phpbb_root_path,$lr_body,$lr_mail,$lr_mail_fault;
 require$kp_source.'includes/functions_link_submission.php';require$kp_source.'includes/functions_link_notifications.php';
 $source=file_get_contents($kp_source.'link_register.php');$start=strpos($source,"require_once \$phpbb_root_path . 'includes/functions_link_submission.'");kp_check($start!==false,'Actual controller provider boundary');$lr_body=substr($source,$start);$cases=$failures=0;
 foreach(array('english','german')as$locale){foreach(array('ordinary','utf8-title','utf8-description','truncate-title','truncate-description','literal-backslash','current-admin','stale-admin','current-moderator')as$kind){
  lr_reset(false,false,$locale);$title=$_POST['link_title'];$description=$_POST['link_desc'];$active=0;
  if($kind==='utf8-title'){$title=$_POST['link_title']=str_repeat('ä',49).'€😀';}
  if($kind==='utf8-description'){$description=$_POST['link_desc']=str_repeat('ä',127).'€😀';}
  if($kind==='truncate-title'){$_POST['link_title']=str_repeat('😀',101);$title=str_repeat('😀',100);}
  if($kind==='truncate-description'){$_POST['link_desc']=str_repeat('€',256);$description=str_repeat('€',255);}
  if($kind==='literal-backslash'){$title=$_POST['link_title']="Path \\literal 'quote'";}
  if($kind==='current-admin'){kp_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=4');$active=1;}
  if($kind==='stale-admin'){$userdata['user_level']=ADMIN;}
  if($kind==='current-moderator'){kp_sql('UPDATE fixture_users SET user_level=2 WHERE user_id=4');}
  $response=lr_controller();$rows=kp_rows('SELECT link_title,link_desc,link_active FROM fixture_links');kp_check(lr_success($response)&&count($rows)===1&&$rows[0]['link_title']===$title&&$rows[0]['link_desc']===$description&&(int)$rows[0]['link_active']===$active,'Actual controller stores whole Unicode characters/current role: '.$kind);$cases++;
 }}
 foreach(array('missing-session','foreign-session','guest-session','sid-case','inactive','missing-user','post-token','get','missing-category','locked','logo-required','url-long','url-script','logo-long','invalid-utf8','title-array','description-array','url-array','category-array','category-exponent','category-float','empty-title','empty-description')as$kind){
  lr_reset();
  if($kind==='missing-session'){kp_sql('DELETE FROM fixture_sessions');}elseif($kind==='foreign-session'){kp_sql('UPDATE fixture_sessions SET session_user_id=1');}elseif($kind==='guest-session'){kp_sql('UPDATE fixture_sessions SET session_logged_in=0');}elseif($kind==='sid-case'){$userdata['session_id']=$_POST['sid']=str_repeat('A',32);}
  elseif($kind==='inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=4');}elseif($kind==='missing-user'){kp_sql('DELETE FROM fixture_users WHERE user_id=4');}elseif($kind==='post-token'){$_POST['sid']='wrong';}elseif($kind==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($kind==='missing-category'){kp_sql('DELETE FROM fixture_link_categories');}
  elseif($kind==='locked'){kp_sql("UPDATE fixture_link_config SET config_value='1' WHERE config_name='lock_submit_site'");$userdata['user_level']=ADMIN;}elseif($kind==='logo-required'){kp_sql("UPDATE fixture_link_config SET config_value='0' WHERE config_name='allow_no_logo'");}
  elseif($kind==='url-long'){$_POST['link_url']='https://example.invalid/'.str_repeat('x',101);}elseif($kind==='url-script'){$_POST['link_url']='javascript:alert(1)';}elseif($kind==='logo-long'){$_POST['link_logo_src']='https://example.invalid/'.str_repeat('x',121);}elseif($kind==='invalid-utf8'){$_POST['link_title']="broken\xc3";}
  elseif($kind==='title-array'){$_POST['link_title']=array('x');}elseif($kind==='description-array'){$_POST['link_desc']=array('x');}elseif($kind==='url-array'){$_POST['link_url']=array('https://example.invalid/');}elseif($kind==='category-array'){$_POST['link_category']=array(1);}elseif($kind==='category-exponent'){$_POST['link_category']='1e0';}elseif($kind==='category-float'){$_POST['link_category']=1.5;}elseif($kind==='empty-title'){$_POST['link_title']=' ';}elseif($kind==='empty-description'){$_POST['link_desc']='';}
  $before=kp_snapshot();kp_check(!lr_success(lr_controller())&&kp_snapshot()===$before,'Rejected controller input/authority has no writes: '.$kind);$cases++;
 }
 lr_reset();kp_check(lr_success(lr_controller()),'First submission');$before=kp_snapshot();kp_check(!lr_success(lr_controller())&&kp_snapshot()===$before,'Second immediate request cannot bypass cooldown');$cases++;
 kp_sql('CREATE TABLE fixture_lr_caller(id INT PRIMARY KEY,marker INT NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');kp_sql('INSERT INTO fixture_lr_caller VALUES(1,7)');
 foreach(array('normal','missing','borrow','alias','busy')as$factory){
  lr_reset();kp_check($db->sql_query('START TRANSACTION')!==false&&$db->sql_query('UPDATE fixture_lr_caller SET marker=8 WHERE id=1')!==false,'Outer caller transaction with pending work');$r=$db->sql_query('SELECT username FROM fixture_users WHERE user_id=4');$old=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_sql("UPDATE fixture_users SET username='Current author' WHERE user_id=4");
  if($factory==='busy'){kp_sql('START TRANSACTION');kp_sql("UPDATE fixture_users SET username='Uncommitted peer' WHERE user_id=4");}
  $kp_factory=$factory;
  try{$response=lr_controller();kp_check(lr_success($response)===($factory==='normal'),'Factory ownership '.$factory);$r=$db->sql_query('SELECT username FROM fixture_users WHERE user_id=4');$still=$db->sql_fetchrow($r);$db->sql_freeresult($r);$r=$db->sql_query('SELECT marker FROM fixture_lr_caller WHERE id=1');$pending=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_check($still['username']===$old['username']&&$pending['marker']==='8'&&kp_rows('SELECT marker FROM fixture_lr_caller WHERE id=1')[0]['marker']==='7','Caller snapshot and uncommitted work survive '.$factory);if($factory==='busy'){kp_check(kp_rows('SELECT username FROM fixture_users WHERE user_id=4')[0]['username']==='Uncommitted peer','Borrowed pending peer work remains uncommitted');}$cases++;}
  finally{$kp_factory='normal';if($factory==='busy'){kp_sql('ROLLBACK');}$db->sql_query('ROLLBACK');kp_check(kp_rows('SELECT marker FROM fixture_lr_caller WHERE id=1')[0]['marker']==='7','Caller still owns rollback');}
 }
 // Every SQL boundary of the real store is failed once; after-COMMIT loss
 // is distinguished from rollback, and optional work never starts on it.
 lr_reset();$queries=array();$kp_hook=function($phase,$sql)use(&$queries){if($phase==='before'){$queries[]=$sql;}};kp_check(lr_success(lr_controller()),'Record full real store');$kp_hook=null;
 foreach($queries as$position=>$sql){lr_reset();$at=0;$kp_hook=function($phase,$statement)use(&$at,$position){if($phase==='before'){return++$at===$position+1?false:null;}};$response=lr_controller();$kp_hook=null;kp_check(!lr_success($response)&&count(kp_rows('SELECT * FROM fixture_links'))===0,'Each failed store query rolls back before success: '.$position);$failures++;}
 lr_reset();$kp_hook=function($phase,$sql){return$phase==='after'&&$sql==='COMMIT'?false:null;};$response=lr_controller();$kp_hook=null;kp_check(!lr_success($response)&&count(kp_rows('SELECT * FROM fixture_links'))===1&&strpos($response->getMessage(),$GLOBALS['lang']['Link_save_unconfirmed'])!==false,'Lost acknowledgement reports uncertainty, not retry or false rollback');$cases++;
 foreach(array(LINKS_TABLE,LINK_CONFIG_TABLE,LINK_CATEGORIES_TABLE,USERS_TABLE,SESSIONS_TABLE)as$table){lr_reset();kp_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');try{$before=kp_snapshot();kp_check(!lr_success(lr_controller())&&kp_snapshot()===$before,'Unmigrated participant refuses all writes');$cases++;}finally{kp_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}}
 foreach(array('off','pm','mail','both','mail-false','mail-throw','mail-construct','mail-template','full-inbox','pm-disabled','pm-disallowed','recipient-demoted','recipient-inactive','notify-off','source-approved','source-deleted','current-language')as$kind){
  lr_reset($kind!=='off'&&$kind!=='pm',$kind!=='off'&&$kind!=='mail');$outcome=phpbb_submit_link($db,$_POST,$userdata);
  if($kind==='full-inbox'){kp_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='max_inbox_privmsgs'");kp_sql("INSERT INTO fixture_privmsgs(privmsgs_id,privmsgs_type,privmsgs_subject,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_ip) VALUES(700,0,'Keep EN',4,1,1,'7f000001'),(701,0,'Keep DE',4,2,1,'7f000001')");kp_sql("INSERT INTO fixture_privmsgs_text(privmsgs_text_id,privmsgs_bbcode_uid,privmsgs_text) VALUES(700,'','Keep EN body'),(701,'','Keep DE body')");}
  elseif($kind==='pm-disabled'){kp_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='privmsg_disable'");}elseif($kind==='pm-disallowed'){kp_sql('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id IN (1,2)');}elseif($kind==='recipient-demoted'){kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id IN (1,2)');}elseif($kind==='recipient-inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id IN (1,2)');}elseif($kind==='notify-off'){kp_sql("UPDATE fixture_link_config SET config_value='0' WHERE config_name IN ('email_notify','pm_notify')");}elseif($kind==='source-approved'){kp_sql('UPDATE fixture_links SET link_active=1');}elseif($kind==='source-deleted'){kp_sql('DELETE FROM fixture_links');}elseif($kind==='current-language'){kp_sql("UPDATE fixture_users SET user_lang='../invalid' WHERE user_id=1");kp_sql("UPDATE fixture_config SET config_value='german' WHERE config_name='default_lang'");}
  $lr_mail_fault=strpos($kind,'mail-')===0?substr($kind,5):'';$phpbb_root_path=__DIR__.'/../fixtures/link-registration-native/';
  $before_links=kp_rows('SELECT * FROM fixture_links');$ok=phpbb_link_notify($db,$outcome);$phpbb_root_path=$kp_source;
  kp_check($ok===!in_array($kind,array('mail-false','mail-throw','mail-construct','mail-template'),true)&&kp_rows('SELECT * FROM fixture_links')===$before_links,'Optional notification result never changes saved link: '.$kind);
  $none=in_array($kind,array('off','recipient-demoted','recipient-inactive','notify-off','source-approved','source-deleted'),true);
  $expect_pm=!$none&&!in_array($kind,array('mail','pm-disabled','pm-disallowed'),true);$messages=kp_rows('SELECT p.privmsgs_from_userid,p.privmsgs_to_userid,p.privmsgs_subject,t.privmsgs_text FROM fixture_privmsgs p JOIN fixture_privmsgs_text t ON t.privmsgs_text_id=p.privmsgs_id ORDER BY p.privmsgs_to_userid');
  kp_check(count($messages)===($kind==='full-inbox'?2:($expect_pm?2:0)),'Atomic optional PM count '.$kind);
  if($kind==='full-inbox'){kp_check($messages[0]['privmsgs_subject']==='Keep EN'&&$messages[1]['privmsgs_text']==='Keep DE body','Full inbox never evicts or rewrites existing PM');}
  elseif($expect_pm){foreach($messages as$message){kp_check($message['privmsgs_from_userid']==='4'&&strpos($message['privmsgs_text'],'https://example.invalid/?a=1&amp;b=2')!==false,'PM sender and escaped URL preserved');}$counts=kp_rows('SELECT user_new_privmsg,user_unread_privmsg FROM fixture_users WHERE user_id IN (1,2)');foreach($counts as$count){kp_check($count===array('user_new_privmsg'=>'1','user_unread_privmsg'=>'0'),'PM counters commit with complete text');}}
  $expect_mail=!$none&&!in_array($kind,array('pm','mail-construct','mail-template'),true);kp_check(count($lr_mail)===($expect_mail?2:0),'Actual mail transport count '.$kind);
  if($expect_mail){foreach($lr_mail as$mail){kp_check($mail['from']==='current@example.invalid'&&strpos($mail['body'],'Current Grüße 😀 board')!==false&&strpos($mail['body'],'https://example.invalid/?a=1&b=2')!==false,'Actual template uses current site/from and exact URL');}}
  $cases++;
 }
 // A real controller still acknowledges the saved link when notice SQL fails.
 foreach(array('english','german')as$locale){lr_reset(true,true,$locale);$committed=false;$kp_hook=function($phase,$sql)use(&$committed){if($phase==='after'&&$sql==='COMMIT'){$committed=true;}return$phase==='before'&&$committed?false:null;};$response=lr_controller();$kp_hook=null;kp_check(lr_success($response)&&strpos($response->getMessage(),$GLOBALS['lang']['Link_notification_failed'])!==false&&count(kp_rows('SELECT * FROM fixture_links'))===1,'Optional SQL failure keeps actual localized success response');$cases++;}
 // Fail each PM write/read-back/COMMIT boundary: saved link remains, PM pair
 // and counters roll back together. A lost commit ack retains the whole pair.
 lr_reset(false,true);$outcome=phpbb_submit_link($db,$_POST,$userdata);$queries=array();$kp_hook=function($phase,$sql)use(&$queries){if($phase==='before'){$queries[]=$sql;}};phpbb_link_notice_pm($db,$outcome,1);$kp_hook=null;
 foreach($queries as$position=>$sql){if(!preg_match('/^(INSERT INTO|UPDATE|COMMIT|SELECT p\.privmsgs_|SELECT u\.user_new_privmsg)/',$sql)){continue;}lr_reset(false,true);$outcome=phpbb_submit_link($db,$_POST,$userdata);$before=kp_snapshot();$at=0;$kp_hook=function($phase,$statement)use(&$at,$position){return$phase==='before'&&++$at===$position+1?false:null;};try{phpbb_link_notice_pm($db,$outcome,1);throw new RuntimeException('Failed PM must refuse commit');}catch(PhpbbLinkException$error){}finally{$kp_hook=null;}kp_check(kp_snapshot()===$before,'Optional PM failure cannot orphan text or alter counters');$failures++;}
 lr_reset(false,true);$outcome=phpbb_submit_link($db,$_POST,$userdata);$kp_hook=function($phase,$sql){return$phase==='after'&&$sql==='COMMIT'?false:null;};try{phpbb_link_notice_pm($db,$outcome,1);throw new RuntimeException('Lost PM acknowledgement cannot be confirmed');}catch(PhpbbLinkException$error){kp_check($error->getMessage()==='Link_save_unconfirmed','Distinct optional PM uncertainty');}finally{$kp_hook=null;}kp_check(count(kp_rows('SELECT * FROM fixture_privmsgs'))===1&&count(kp_rows('SELECT * FROM fixture_privmsgs_text'))===1&&kp_rows('SELECT user_new_privmsg FROM fixture_users WHERE user_id=1')[0]['user_new_privmsg']==='1','Lost PM acknowledgement retains complete parent/body/counters');$cases++;
 foreach(array('link-title','pm-body','pm-counter')as$drift){
  lr_reset(false,true);$outcome=$drift==='link-title'?null:phpbb_submit_link($db,$_POST,$userdata);
  $trigger=$drift==='link-title'?"CREATE TRIGGER fixture_drift BEFORE INSERT ON fixture_links FOR EACH ROW SET NEW.link_title='Wrong'":($drift==='pm-body'?"CREATE TRIGGER fixture_drift BEFORE INSERT ON fixture_privmsgs_text FOR EACH ROW SET NEW.privmsgs_text='Wrong'":"CREATE TRIGGER fixture_drift BEFORE UPDATE ON fixture_users FOR EACH ROW SET NEW.user_new_privmsg=0");kp_sql($trigger);
  try{$before=kp_snapshot();if($drift==='link-title'){kp_check(!lr_success(lr_controller()),'Native title drift refuses success');}else{try{phpbb_link_notice_pm($db,$outcome,1);throw new RuntimeException('Native drift must refuse PM');}catch(PhpbbLinkException$error){}}kp_check(kp_snapshot()===$before,'Read-back rejects actual trigger drift and rolls back all data: '.$drift);$cases++;}finally{kp_sql('DROP TRIGGER fixture_drift');}
 }
 foreach(array('missing','bad','duplicate')as$kind){lr_reset();if($kind==='missing'){kp_sql('DELETE FROM fixture_link_config');$expected=true;}elseif($kind==='bad'){kp_sql("UPDATE fixture_link_config SET config_value='yes' WHERE config_name='email_notify'");$expected=false;}else{kp_sql("INSERT INTO fixture_link_config(config_name,config_value) VALUES('allow_no_logo','0')");$expected=false;}$before=kp_snapshot();kp_check(lr_success(lr_controller())===$expected&&($expected||kp_snapshot()===$before),'Current link flags have deterministic defaults/refuse ambiguity: '.$kind);$cases++;}
 foreach(array('demoted','inactive','session-removed','category-removed','locked')as$change){
  lr_reset();$userdata['user_level']=ADMIN;kp_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=4');$fired=false;
  $kp_hook=function($phase,$sql)use(&$fired,$change){if($phase!=='before'||$fired||strpos($sql,'SELECT u.user_id,u.user_level')!==0){return;}$fired=true;if($change==='demoted'){kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=4');}elseif($change==='inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=4');}elseif($change==='session-removed'){kp_sql('DELETE FROM fixture_sessions');}elseif($change==='category-removed'){kp_sql('DELETE FROM fixture_link_categories');}else{kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=4');kp_sql("UPDATE fixture_link_config SET config_value='1' WHERE config_name='lock_submit_site'");}};
  $response=lr_controller();$kp_hook=null;$rows=kp_rows('SELECT link_active FROM fixture_links');kp_check($fired&&($change==='demoted'?lr_success($response)&&count($rows)===1&&$rows[0]['link_active']==='0':!lr_success($response)&&!$rows),'Actual peer change before store authority read: '.$change);$cases++;
 }
 lr_reset();$locked=false;$timeout=kp_rows('SELECT @@SESSION.innodb_lock_wait_timeout seconds')[0]['seconds'];kp_sql('SET SESSION innodb_lock_wait_timeout=1');
 $kp_hook=function($phase,$sql)use(&$locked){if($phase==='after'&&strpos($sql,'SELECT u.user_id,u.user_level')===0){$result=$GLOBALS['peer']->sql_query('UPDATE fixture_users SET user_level=1 WHERE user_id=4');$error=$GLOBALS['peer']->sql_error();kp_check($result===false&&(int)$error['code']===1205,'Current user is locked until the write decision completes');$locked=true;}};
 try{kp_check(lr_success(lr_controller())&&$locked,'Actual current-user lock verified through independent peer');$cases++;}finally{$kp_hook=null;kp_sql('SET SESSION innodb_lock_wait_timeout='.(int)$timeout);}
 lr_reset();$mutex='attachment:'.md5($db->dbname."\0".ATTACHMENTS_TABLE);$serialized=false;
 $kp_hook=function($phase,$sql)use($mutex,&$serialized){if($phase==='before'&&strpos($sql,'SELECT MAX(link_joined)')===0){kp_check(kp_rows("SELECT GET_LOCK('".$mutex."',0) acquired")[0]['acquired']==='0','Another writer cannot enter the check/insert window');$serialized=true;}};
 try{kp_check(lr_success(lr_controller())&&$serialized,'Actual cooldown write is serialized');$cases++;}finally{$kp_hook=null;}
 // mysqli_close sends COM_QUIT without waiting for the server to finish
 // disconnect cleanup. Require a real acknowledged handoff, bounded below
 // the production writer's three-second acquisition limit; no blind sleep.
 $handoff=kp_rows("SELECT GET_LOCK('".$mutex."',1) acquired")[0]['acquired'];
 kp_check($handoff==='1','Confirmed store releases the real shared writer mutex; handoff='.var_export($handoff,true));
 kp_check(kp_rows("SELECT RELEASE_LOCK('".$mutex."') released")[0]['released']==='1','Peer releases the acknowledged shared mutex');
 foreach(array('installer','legacy-space','legacy-case','duplicate-alias')as$kind){
  lr_reset();if($kind==='installer'){$basic=file_get_contents($kp_source.'install/schemas/mysql_basic.sql');kp_check(preg_match_all('/INSERT INTO phpbb_link_config \([^;]+;/',$basic,$m)>0,'Actual installer Link settings');kp_sql('DELETE FROM fixture_link_config');kp_sql('START TRANSACTION');try{foreach($m[0]as$sql){kp_sql(str_replace('phpbb_link_config','fixture_link_config',$sql));}kp_sql('COMMIT');}catch(Exception$error){kp_sql('ROLLBACK');throw$error;}$_POST['link_logo_src']='https://example.invalid/logo.gif';}
  elseif($kind==='duplicate-alias'){kp_sql("INSERT INTO fixture_link_config(config_name,config_value) VALUES('pm_notify ','1')");}
  else{kp_sql("UPDATE fixture_link_config SET config_name='".($kind==='legacy-space'?'pm_notify ':'PM_NOTIFY')."' WHERE config_name='pm_notify'");}
  // Keep optional mail/PM out of this store-only installer compatibility case.
  $before=kp_snapshot();try{$out=phpbb_submit_link($db,$_POST,$GLOBALS['userdata']);$saved=true;}catch(PhpbbLinkException$error){$saved=false;}
  kp_check($saved===($kind!=='duplicate-alias')&&($saved||kp_snapshot()===$before),'Actual seeded/legacy key normalization without ambiguous values: '.$kind);$cases++;
 }
 define('PHPBB_LEGACY_REQUEST_ESCAPED',true);lr_reset();$_POST['link_title']="Path \\literal 'Grüße 😀'";foreach($_POST as$key=>$value){$_POST[$key]=addslashes($value);}kp_check(lr_success(lr_controller())&&kp_rows('SELECT link_title FROM fixture_links')[0]['link_title']==="Path \\literal 'Grüße 😀'",'Actual controller removes common.php escaping exactly once');$cases++;
 echo 'Link registration native: '.$cases.' cases, '.$failures." SQL failures PASS; actual controller, current session/role, Unicode, owned transactions, canonical schema, optional EN/DE mail and atomic quota-preserving PM.\n";
}
// Reuse canonical schema setup/cleanup, never a separate hand-written model.
$lr_file=__DIR__.'/check-kb-publication-native.php';$lr_source=file_get_contents($lr_file);
$lr_replacements=array("getenv('PHPBB_KB_COUNTER_NATIVE') !== '1'"=>"getenv('PHPBB_LINK_NATIVE') !== '1'",'PHPBB_KB_COUNTER_PORT'=>'PHPBB_LINK_PORT','PHPBB_KB_COUNTER_PASSWORD'=>'PHPBB_LINK_PASSWORD','codex_kb_publish_'=>'codex_link_register_',
 '$db = new KpDatabase('=>'lr_classes();$db = new LinkNativeDatabase(',
 '$schema = file_get_contents($kp_source'=>'$kp_tables=array_unique(array_merge($kp_tables,array(LINKS_TABLE,LINK_CATEGORIES_TABLE,LINK_CONFIG_TABLE,PRIVMSGS_TABLE,PRIVMSGS_TEXT_TABLE,CONFIG_TABLE)));$schema = file_get_contents($kp_source',
 ' $cases = 0;'=>' lr_suite();return; $cases = 0;');
foreach($lr_replacements as$before=>$after){if(substr_count($lr_source,$before)!==1){throw new RuntimeException('Canonical fixture boundary missing: '.$before);}$lr_source=str_replace($before,$after,$lr_source);}
$lr_code='';foreach(token_get_all($lr_source)as$t){$lr_code.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($lr_code,5));
