<?php
// Actual forum validator + KB services, canonical native disposable schemas.
// No HTTP, real accounts, mail or external spam-provider requests.
$kp_guest_policy_mode=true;
function kgp_seed($comments=true,$pending=false,$name='Allowed Guest')
{
 kw_reset('root',$comments);$GLOBALS['userdata']['user_id']=ANONYMOUS;$GLOBALS['userdata']['session_logged_in']=false;$GLOBALS['userdata']['session_admin']=0;
 kp_sql('UPDATE fixture_sessions SET session_user_id=-1,session_logged_in=0,session_admin=0');
 kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='allow_anon'");kp_sql("UPDATE fixture_kb_config SET config_value='".($pending?'1':'0')."' WHERE config_name='approve_new'");
 $input=kw_input();$input['username']=$name;return$input;
}
function kgp_rules()
{
 kp_sql("INSERT INTO fixture_disallow(disallow_username) VALUES('Blocked Guest')");kp_sql("INSERT INTO fixture_words(word,replacement) VALUES('Forbidden','Replacement')");
}
function kgp_policy_insertions($name)
{
 $db=$GLOBALS['peer'];$name=$db->sql_escape($name);
 return array(USERS_TABLE=>"INSERT INTO fixture_users(user_id,username,user_password,user_email,user_active,user_level) VALUES(55,'".$name."','synthetic','guest-reservation@example.invalid',1,0)",
  GROUPS_TABLE=>"INSERT INTO fixture_groups(group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES(55,0,'".$name."','Synthetic',1,0)",
  DISALLOW_TABLE=>"INSERT INTO fixture_disallow(disallow_username) VALUES('".$name."')",WORDS_TABLE=>"INSERT INTO fixture_words(word,replacement) VALUES('".$name."','Replacement')");
}
function kp_guest_policy_suite()
{
 global$kp_source,$kp_hook,$userdata,$lang,$board_config;
 require$kp_source.'includes/functions_kb_write_storage.php';$cases=0;
 foreach(array(false,true)as$comments){foreach(array(false,true)as$pending){foreach(array('root','KB moderation','Blocked Guest','Forbidden',"Invisible\xc2\xa0Guest","Invisible\xc2\xadGuest")as$name){
  $input=kgp_seed($comments,$pending,$name);kgp_rules();$before=kp_snapshot();$out=kw_run('add',$input);
  kp_check($out instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Protected/invisible guest name cannot create article/index/PM/counters/discussion');$cases++;
 }}}
 foreach(array('',"Allowed Grüße 😀 O'Reilly \\ exact",str_repeat("'",255),str_repeat('😀',255))as$name){foreach(array(false,true)as$pending){
  $input=kgp_seed(true,$pending,$name);$out=kw_ok(kw_run('add',$input),'Valid or unnamed guest survives ordinary policy');
  $rows=kp_rows('SELECT article_author_id,username FROM fixture_kb_articles WHERE article_id='.(int)$out['article_id']);kp_check($rows===array(array('article_author_id'=>'0','username'=>$name)),'Valid raw guest name remains byte-exact in article');
  if($pending){$userdata['user_id']=1;$userdata['session_logged_in']=true;$userdata['session_admin']=1;kp_sql('UPDATE fixture_sessions SET session_user_id=1,session_logged_in=1,session_admin=1');kp_check(is_array(kp_run(1,'acp',$out['article_id'])),'Pending valid wide/empty guest approval still works');}
  $posts=kp_rows('SELECT post_username FROM fixture_posts');kp_check(count($posts)===1&&$posts[0]['post_username']===($pending?'':phpbb_post_author_key($name)),'Immediate guest byline exact; approval uses registered configured poster');$cases++;
 }}
 foreach(array(false,true)as$comments){$input=kgp_seed($comments,true);kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='notify'");kw_ok(kw_run('add',$input),'Guest policy locking coexists with pending admin PM on same transaction');kp_check(count(kp_rows('SELECT * FROM fixture_privmsgs'))===1,'Guest pending PM publishes');$cases++;}
 foreach(array('acp','edit')as$operation){kw_reset('root',true);kgp_rules();kp_sql("UPDATE fixture_kb_articles SET article_author_id=0,username='Blocked Guest' WHERE article_id=1");$out=$operation==='acp'?kp_run(1,'acp'):kw_run('edit');kw_ok($out,'Historical saved guest not invalidated by current local rules');kp_check(kp_rows('SELECT username FROM fixture_kb_articles WHERE article_id=1')===array(array('username'=>'Blocked Guest')),'Historical author preserved');$cases++;}
 foreach(array_keys(kgp_policy_insertions('Allowed Guest'))as$table){$input=kgp_seed(true,false);$before=kp_snapshot();$reached=false;$sqls=kgp_policy_insertions($input['username']);$kp_hook=function($event,$sql)use(&$reached,$sqls,$table){if($event==='before'&&strpos($sql,'SELECT username FROM fixture_users WHERE username IN (')===0){$GLOBALS['kp_hook']=null;$reached=true;kp_sql($sqls[$table]);}};$out=kw_run('add',$input);$kp_hook=null;$after=kp_snapshot();unset($before[$table],$after[$table]);kp_check($reached&&$out instanceof PhpbbKbPublicationException&&$after===$before,'Independently committed current name/rule is seen despite earlier transaction reads');$cases++;}
 foreach(array('before-first-write','before-commit')as$boundary){$input=kgp_seed(true,false);$reached=false;$kp_hook=function($event,$sql)use(&$reached,$boundary,$input){$match=$boundary==='before-first-write'?strpos($sql,'INSERT INTO fixture_kb_articles ')===0:$sql==='COMMIT';if($event!=='before'||!$match){return;}$GLOBALS['kp_hook']=null;$reached=true;kp_sql('SET SESSION innodb_lock_wait_timeout=0');foreach(kgp_policy_insertions($input['username'])as$attempt){$r=$GLOBALS['peer']->sql_query($attempt);$e=$GLOBALS['peer']->sql_error();kp_check(!$r&&(int)$e['code']===1205,'Actual empty account/group/disallow/word ranges cannot acquire new conflicting rows through COMMIT');}$r=$GLOBALS['peer']->sql_query('DELETE FROM fixture_sessions');$e=$GLOBALS['peer']->sql_error();kp_check(!$r&&(int)$e['code']===1205,'Guest session current read remains locked through commit');kp_sql('SET SESSION innodb_lock_wait_timeout=1');};kw_ok(kw_run('add',$input),'Pinned current guest policy survives foreign attempts');$kp_hook=null;kp_check($reached,'Actual guest policy locking boundary reached');$cases++;}
 $input=kgp_seed(true,false);$before=kp_snapshot();kp_sql("CREATE TRIGGER kgp_restrict BEFORE INSERT ON fixture_kb_articles FOR EACH ROW INSERT INTO fixture_disallow(disallow_username) VALUES('Allowed Guest')");$out=kw_run('add',$input);kp_check($out instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'New in-transaction restriction detected by later writes/COMMIT and all effects roll back');kp_sql('DROP TRIGGER kgp_restrict');$cases++;
 foreach(array(false,true)as$pending){$input=kgp_seed(true,$pending);$mutations=0;$kp_hook=function($event,$sql)use(&$mutations){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){$mutations++;}};kw_ok(kw_run('add',$input),'Inventory actual guest write mutations');$kp_hook=null;kp_check($mutations>0,'Actual guest effects observed');foreach(array('before','after')as$boundary){for($fail=1;$fail<=$mutations;$fail++){$input=kgp_seed(true,$pending);$before=kp_snapshot();$seen=0;$kp_hook=function($event,$sql)use($boundary,$fail,&$seen){if($event===$boundary&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){return++$seen===$fail?false:null;}};$out=kw_run('add',$input);$kp_hook=null;kp_check($out instanceof PhpbbKbPublicationException&&$seen===$fail&&kp_snapshot()===$before,'Every guest mutation fault restores all article/policy/index/counter/discussion data');$cases++;}}}
 foreach(array('before','after')as$boundary){$input=kgp_seed();$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($boundary,&$reached){if($event===$boundary&&$sql==='COMMIT'){$reached=true;return false;}};$out=kw_run('add',$input);$kp_hook=null;kp_check($reached&&$out instanceof PhpbbKbPublicationException,'Guest COMMIT acknowledgement failure not reported as success');kp_check($boundary==='before'?kp_snapshot()===$before:count(kp_rows('SELECT * FROM fixture_posts'))===1,'Durability distinct from acknowledgement');$cases++;}
 foreach(array(DISALLOW_TABLE,WORDS_TABLE)as$table){$input=kgp_seed();kp_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=kp_snapshot();kp_check(kw_run('add',$input) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Legacy nontransactional guest-policy table refuses publication');kp_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES,NO_BACKSLASH_ESCAPES')as$mode){$input=kgp_seed(true,false,"Allowed O'Reilly \\ Guest");$reached=false;$kp_hook=function($event,$sql,$proxy)use($mode,&$reached){if($event==='before'&&strpos($sql,'SET SESSION sql_mode')===0){$GLOBALS['kp_hook']=null;$reached=true;kp_check($proxy->connection->sql_query("SET SESSION sql_mode='".$mode."'"),'Only own dedicated session SQL mode');}};kw_ok(kw_run('add',$input),'Literal names and rules independent of SQL mode');$kp_hook=null;kp_check($reached,'Own session mode boundary reached');$cases++;}
 $original=$board_config;$board_config['sfs_enable']='1';$input=kgp_seed();kw_ok(kw_run('add',$input),'Guest local policy works with external spam option enabled but unused');$board_config=$original;$cases++;
 $saved=$lang;foreach(array('english','german')as$locale){require$kp_source.'language/lang_'.$locale.'/lang_kb.php';require$kp_source.'language/lang_'.$locale.'/lang_main.php';$input=kgp_seed(true,false,'root');$out=kw_run('add',$input);kp_check($out instanceof PhpbbKbPublicationException&&$out->getMessage()===$lang['Username_taken']&&preg_match('//u',$out->getMessage())===1,'Existing localized real forum policy message retained');$cases++;}$lang=$saved;
 echo 'KB guest-name policy native: '.$cases." actual-validator/reservation/word/UTF8/empty/historical/PM/current-rule/range/session/fault/SQL-mode/locale cases passed; no external requests.\n";
}
require __DIR__.'/check-kb-writing-native.php';
