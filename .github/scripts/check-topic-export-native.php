<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_TOPIC_EXPORT_NATIVE')!=='1'){echo "Native topic export requires an explicitly enabled disposable database.\n";return;}
require __DIR__.'/news-tombstone-fixture.php';
require $nt_app.'db/mysqli.php';require $nt_app.'includes/auth.php';require $nt_app.'includes/functions_topic_export.php';
// Extra attachment ACL columns cannot change this view/read decision.
function attach_setup_basic_auth($type,&$fields,&$sql){}
class ExportOwner{
    var $inner,$db_connect_id;
    function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
    function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
    function sql_query($sql,$transaction=false){
        $GLOBALS['ex_trace'][]=$sql;
        if(is_callable($GLOBALS['ex_hook'])&&call_user_func($GLOBALS['ex_hook'],'before',$sql)===false){return false;}
        $r=$this->inner->sql_query($sql,$transaction);
        if(is_callable($GLOBALS['ex_hook'])&&call_user_func($GLOBALS['ex_hook'],'after',$sql)===false){return false;}
        return$r;
    }
}
class ExportSource extends sql_db{
    function sql_dedicated_connection(){
        if($GLOBALS['ex_factory']==='missing'){return false;}
        if($GLOBALS['ex_factory']==='borrow'){return$this;}
        if($GLOBALS['ex_factory']==='busy'){return new ExportOwner($GLOBALS['peer']);}
        $owner=new ExportOwner(parent::sql_dedicated_connection());$GLOBALS['ex_owners'][]=$owner;return$owner;
    }
}
function ex_sql($query){$r=$GLOBALS['peer']->sql_query($query);if(!$r){$error=$GLOBALS['peer']->sql_error();throw new RuntimeException('Own export SQL: '.$error['message']);}return$r;}
function ex_rows($query){$r=ex_sql($query);try{return$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}}
function ex_seed($table,$values){
    foreach($GLOBALS['ex_columns'][$table]as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
    $literals=array();foreach($values as$v){$literals[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}ex_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$literals).')');
}
function ex_actor($id=8,$level=USER){return array('user_id'=>$id,'user_level'=>$level,'session_logged_in'=>true,'session_id'=>'export-session-'.$id);}
function ex_reset($locale='english'){
    global $ex_hook,$ex_factory,$ex_trace,$userdata,$template,$board_config;
    $ex_hook=null;$ex_factory='normal';$ex_trace=array();nt_context($locale,true);$template=nt_template('news.tpl');$userdata=ex_actor();
    ex_sql('START TRANSACTION');foreach(array_reverse($GLOBALS['ex_tables'])as$s){ex_sql('DELETE FROM fixture_'.$s);}
    ex_seed('fixture_config',array('config_name'=>'session_length','config_value'=>'3600'));
    foreach(array(-1,7,8,9)as$id){ex_seed('fixture_users',array('user_id'=>$id,'username'=>$id===7?'Current &amp; member':($id===-1?'Anonymous':'Viewer '.$id),'user_active'=>1,'user_level'=>$id===9?ADMIN:USER,'user_blocktime'=>0));if($id>0){ex_seed('fixture_sessions',array('session_id'=>'export-session-'.$id,'session_user_id'=>$id,'session_logged_in'=>1,'session_time'=>time()));}}
    foreach(array(3=>AUTH_ALL,4=>AUTH_ADMIN,5=>AUTH_ACL)as$id=>$access){ex_seed('fixture_forums',array('forum_id'=>$id,'forum_name'=>'Forum &amp; '.$id,'auth_view'=>$access,'auth_read'=>$access));}
    ex_seed('fixture_auth_access',array('forum_id'=>5,'group_id'=>20,'auth_view'=>1,'auth_read'=>1));ex_seed('fixture_user_group',array('group_id'=>20,'user_id'=>8,'user_pending'=>0));
    foreach(array(10=>3,20=>4,30=>5)as$id=>$forum){ex_seed('fixture_topics',array('topic_id'=>$id,'forum_id'=>$forum,'topic_title'=>'Größe &amp; 😀 '.$id));}
    for($i=1;$i<=70;$i++){ex_seed('fixture_posts',array('post_id'=>$i,'topic_id'=>10,'forum_id'=>3,'poster_id'=>7,'post_time'=>1700000000+(int)floor($i/3),'post_deleted'=>$i===2?1:0));ex_seed('fixture_posts_text',array('post_id'=>$i,'post_text'=>$i===2?'DELETED_BODY_MUST_NOT_LEAK':'Body-'.$i.'-END  A' . "\t" . 'B &amp;','bbcode_uid'=>''));}
    foreach(array(101=>array(20,4),102=>array(30,5),103=>array(10,4),104=>array(999,3))as$id=>$v){ex_seed('fixture_posts',array('post_id'=>$id,'topic_id'=>$v[0],'forum_id'=>$v[1],'poster_id'=>7,'post_time'=>1700000100));ex_seed('fixture_posts_text',array('post_id'=>$id,'post_text'=>'PRIVATE_OR_ORPHAN_MUST_NOT_LEAK','bbcode_uid'=>''));}
    ex_sql('COMMIT');
}
function ex_export($actor=null,$topic=10,$post=0,$direction='ASC',$maximum=67108864){
    global $db,$userdata;$saved_db=$db;$saved_user=$userdata;$before=count($GLOBALS['ex_owners']);$out=phpbb_topic_export($db,$actor===null?$userdata:$actor,$topic,$post,$direction,$maximum);
    nt_check($db===$saved_db&&$userdata===$saved_user,'Exporter restores caller globals');for($i=$before;$i<count($GLOBALS['ex_owners']);$i++){nt_check(!$GLOBALS['ex_owners'][$i]->inner->db_connect_id,'Owned reader closes before delivery');}
    if($out['status']==='ok'){try{$text=stream_get_contents($out['stream']);nt_check(strlen($text)===$out['bytes'],'Exact acknowledged download byte length');$out['text']=$text;}finally{fclose($out['stream']);}}
    return$out;
}
function ex_order($text){preg_match_all('/Body-([0-9]+)-END/',$text,$m);return array_map('intval',$m[1]);}
// Header and final response sinks only; the actual exporter and native SQL run.
eval('namespace TopicExportController; class Response extends \\RuntimeException{} function header($value){$GLOBALS["ex_headers"][]=$value;} function message_die($level,$text){throw new Response($text);} function redirect($url){throw new Response("LOGIN:".$url);}');
function ex_controller($topic=10,$post=0){
    global$db,$userdata,$lang,$phpbb_root_path,$phpEx,$topic_id,$post_id;
    $topic_id=$topic;$post_id=$post;$GLOBALS['ex_headers']=array();$code=$GLOBALS['ex_controller'];ob_start();try{eval('namespace TopicExportController; use \\Exception; use \\Error; use \\PhpbbTopicExportFailure;'.$code);return array('body'=>ob_get_contents(),'headers'=>$GLOBALS['ex_headers'],'error'=>'');}catch(TopicExportController\Response$e){return array('body'=>ob_get_contents(),'headers'=>$GLOBALS['ex_headers'],'error'=>$e->getMessage());}finally{ob_end_clean();}
}
$port=getenv('PHPBB_TOPIC_EXPORT_PORT')?:'3306';$password=getenv('PHPBB_TOPIC_EXPORT_PASSWORD')?:'';$cases=$faults=$views=0;$ex_tables=array('config','sessions','users','banlist','forums','topics','posts','posts_text','user_group','auth_access','removed_author_names');$ex_columns=$ex_owners=$ex_trace=array();$ex_hook=null;$ex_factory='normal';$control=$db=$peer=null;$made=false;
nt_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Explicit own loopback port');$host='127.0.0.1:'.$port;$schema='codex_export_'.bin2hex(phpbb_random_bytes(8));
try{
    $control=new sql_db($host,'root',$password,'',false);nt_check($control->db_connect_id&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive native fixture schema');$made=true;$peer=new sql_db($host,'root',$password,$schema,false);$db=new ExportSource($host,'root',$password,$schema,false);nt_check($peer->db_connect_id&&$db->db_connect_id,'Independent native fixture connections');
    $canonical=file_get_contents($nt_app.'install/schemas/mysql_schema.sql');foreach($ex_tables as$s){nt_check(preg_match('/^CREATE TABLE phpbb_'.$s.' \(\r?\n.*?^\) ENGINE=.*?;/ms',$canonical,$m)===1,'Actual canonical export definition');ex_sql(str_replace('CREATE TABLE phpbb_'.$s.' ','CREATE TABLE fixture_'.$s.' ',$m[0]));$ex_columns['fixture_'.$s]=ex_rows('SHOW COLUMNS FROM fixture_'.$s);}
    if(getenv('PHPBB_TOPIC_EXPORT_MYSQL')==='8.4'){$version=ex_rows('SELECT VERSION() version')[0]['version'];nt_check(strpos($version,'MariaDB')===false&&preg_match('/^8\.4\./',$version),'Actual MySQL8.4 required');}
    $source=file_get_contents($nt_app.'export.php');$offset=strpos($source,'$direction=');nt_check($offset!==false,'Actual post-bootstrap export controller');$ex_controller=preg_replace('/\?>\s*$/','',substr($source,$offset));
    foreach(array('english','german')as$locale){foreach(array(false,true)as$logged){ex_reset($locale);$actor=$logged?ex_actor():array('user_id'=>-1,'user_level'=>USER,'session_logged_in'=>false);$r=ex_export($actor);$expected=range(1,70);unset($expected[1]);nt_check($r['status']==='ok'&&ex_order($r['text'])===array_values($expected),'Complete tied-time keyset pages without duplicates');nt_check(strpos($r['text'],'MUST_NOT_LEAK')===false&&substr_count($r['text'],$lang['Post_deleted_placeholder'])===1,'Deleted/mismatched/orphan bodies never enter export');nt_check(strpos($r['text'],"Body-1-END  A\tB &")!==false&&strpos($r['text'],$lang['Topic'].":\tGröße & 😀 10")!==false,'Real localized body/header preserves text and Unicode');$cases++;
        $r=ex_export($actor,0,35,'DESC');nt_check(ex_order($r['text'])===array_reverse(array_values($expected)),'Post target resolves whole topic and reverse ties');$cases++;
        ex_sql("UPDATE fixture_posts SET poster_id=-1,post_username='Former alias',post_author_removed=1 WHERE post_id=1");$r=ex_export($actor);nt_check(strpos($r['text'],($logged?'Former alias':$lang['Former_member']).':')!==false,'Actual native byline policy');$cases++;
        ex_seed('fixture_removed_author_names',array('author_user_id'=>0,'author_name'=>'Retired quote alias','author_name_hash'=>hash('sha256','Retired quote alias'),'recorded_at'=>1));$quote=stripslashes(bbencode_first_pass('[quote=\\&quot;Retired quote alias\\&quot;]Visible quote[/quote]','abc1234567'));nt_check(strpos($quote,'[quote:abc1234567="')===0,'Actual first-pass quote after storage unescaping');ex_sql("UPDATE fixture_posts_text SET bbcode_uid='abc1234567',post_text='".$peer->sql_escape($quote)."' WHERE post_id=3");$r=ex_export($actor);nt_check((strpos($r['text'],'Retired quote alias')!==false)===$logged&&strpos($r['text'],'Visible quote')!==false,'Actual quote parser uses the same former-author reader/privacy policy');$cases++;
        $userdata=$actor;$_GET=array();$_POST=array();$r=ex_controller();nt_check($r['error']===''&&count($r['headers'])===5&&in_array('Content-Length: '.strlen($r['body']),$r['headers'],true)&&in_array('Content-Type: text/plain; charset=UTF-8',$r['headers'],true)&&in_array('Cache-Control: private, no-store',$r['headers'],true)&&in_array('X-Content-Type-Options: nosniff',$r['headers'],true),'Actual controller delivers complete UTF-8 download and exact headers');$views++;
        $ordered=$expected;unset($ordered[2]);$_POST=array('postorder'=>'desc');$r=ex_controller();nt_check(ex_order($r['body'])===array_reverse(array_values($ordered)),'Actual controller accepts descending order');$views++;
        $_POST=array('postorder'=>array('desc'));$r=ex_controller();$ordered=$expected;unset($ordered[2]);nt_check(ex_order($r['body'])===array_values($ordered),'Array request falls back to ascending scalar order without warning');$views++;
        $ex_hook=function($phase,$query){if($phase==='before'&&strpos($query,'SELECT t.topic_id,t.topic_title,f.*')===0){return false;}};$r=ex_controller();$ex_hook=null;nt_check($r['body']===''&&$r['headers']===array()&&$r['error']===$lang['Topic_export_unavailable'],'Actual SQL failure emits only controlled localized error before download headers');$views++;
        $_POST=array();$r=ex_controller(20);nt_check($r['body']===''&&$r['headers']===array()&&$r['error']!=='','Private denial emits no partial download');nt_check($logged||strpos($r['error'],'LOGIN:login.php?redirect=viewtopic.php&t=20')===0,'Guest private export uses login route');$views++;
    }}
    foreach(array(array(20,ex_actor(),false),array(20,ex_actor(9,USER),true),array(30,ex_actor(),true),array(30,ex_actor(7,ADMIN),false))as$v){ex_reset();$r=ex_export($v[1],$v[0]);nt_check(($r['status']==='ok')===$v[2],'Actual current administrator/group ACLs override stale supplied role');$cases++;}
    foreach(array("UPDATE fixture_users SET user_active=0 WHERE user_id=8","UPDATE fixture_users SET user_blocktime=".(time()+3600).' WHERE user_id=8',"DELETE FROM fixture_sessions WHERE session_user_id=8","UPDATE fixture_sessions SET session_time=1 WHERE session_user_id=8","UPDATE fixture_sessions SET session_id='EXPORT-SESSION-8' WHERE session_user_id=8","INSERT INTO fixture_banlist(ban_userid,ban_ip) VALUES(8,'')")as$q){ex_reset();ex_sql($q);nt_check(ex_export()['status']==='denied','Current account, exact session, expiry and ban gate');$cases++;}
    foreach(array('DELETE FROM fixture_user_group','UPDATE fixture_user_group SET user_pending=1','UPDATE fixture_auth_access SET auth_view=0','UPDATE fixture_auth_access SET auth_read=0')as$q){ex_reset();ex_sql($q);nt_check(ex_export(null,30)['status']==='denied','Current group membership and both permissions required');$cases++;}
    ex_reset();ex_sql('UPDATE fixture_topics SET forum_id=4 WHERE topic_id=10');nt_check(ex_export()['status']==='denied'&&ex_export(null,0,1)['status']==='missing','Move since initial lookup cannot reuse old public forum authorization');$cases++;
    ex_reset();ex_sql('DELETE FROM fixture_users WHERE user_id=7');ex_sql('DELETE FROM fixture_posts_text WHERE post_id=3');$r=ex_export();nt_check($r['status']==='ok'&&substr_count($r['text'],$lang['Former_member'].':')===69&&strpos($r['text'],'Body-4-END')!==false,'Missing accounts/text retain neighboring ordered positions');$cases++;
    foreach(array(array(999,0),array(0,103),array(0,104))as$v){ex_reset();nt_check(ex_export(null,$v[0],$v[1])['status']==='missing','Missing/cross-forum/orphan target refused');$cases++;}
    ex_reset();ex_sql('DELETE FROM fixture_posts WHERE topic_id=10 AND forum_id=3');nt_check(ex_export()['status']==='missing','Empty topic yields no header-only success');$cases++;
    ex_reset();$fired=false;$ex_hook=function($phase,$q)use(&$fired){if(!$fired&&$phase==='after'&&strpos($q,'SELECT t.topic_id,t.topic_title,f.*')===0){$fired=true;ex_sql('UPDATE fixture_forums SET auth_read=5,auth_view=5 WHERE forum_id=3');ex_sql("UPDATE fixture_posts_text SET post_text='Changed after snapshot' WHERE post_id=1");}};$r=ex_export();$ex_hook=null;nt_check($fired&&strpos($r['text'],'Body-1-END')!==false&&strpos($r['text'],'Changed after snapshot')===false&&ex_export()['status']==='denied','ACL, context and text share one native read snapshot; next request refreshes');$cases++;
    ex_reset();$db->sql_query('START TRANSACTION');$db->sql_query("UPDATE fixture_posts_text SET post_text='Pending caller' WHERE post_id=1");try{nt_check(strpos(ex_export()['text'],'Pending caller')===false,'Independent reader cannot expose caller pending work');}finally{$db->sql_query('ROLLBACK');}nt_check(ex_rows('SELECT post_text FROM fixture_posts_text WHERE post_id=1')[0]['post_text']!=='Pending caller','Caller rollback remains effective');$cases++;
    foreach(array('STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES','ANSI_QUOTES','TRADITIONAL')as$mode){ex_reset();$fired=false;$ex_hook=function($phase,$q)use($mode,&$fired){if(!$fired&&$phase==='before'&&$q==='SET SESSION lock_wait_timeout=3'){$fired=true;$owner=end($GLOBALS['ex_owners']);$owner->inner->sql_query("SET SESSION sql_mode='$mode'");}};nt_check(ex_export()['status']==='ok'&&$fired,'Actual alternate SQL mode');$cases++;}
    foreach(array('missing','borrow','busy')as$factory){ex_reset();$ex_factory=$factory;if($factory==='busy'){ex_sql('START TRANSACTION');ex_sql("UPDATE fixture_forums SET forum_name='Pending peer' WHERE forum_id=3");}$failed=false;try{ex_export();}catch(Exception$e){$failed=true;}finally{if($factory==='busy'){ex_sql('ROLLBACK');}$ex_factory='normal';}nt_check($failed&&$db->db_connect_id&&$peer->db_connect_id,'Refused factories preserve caller and borrowed transaction');$cases++;}
    ex_reset();ex_export();$trace=$ex_trace;foreach(array('before','after')as$phase){foreach($trace as$index=>$query){if($index<array_search('SET SESSION lock_wait_timeout=3',$trace,true)||$query==='ROLLBACK'){continue;}ex_reset();$seen=0;$ex_hook=function($at,$q)use($phase,$index,&$seen){if($at!==$phase){return;}return$seen++===$index?false:null;};$failed=false;try{ex_export();}catch(Exception$e){$failed=true;}$ex_hook=null;nt_check($failed&&ex_export()['status']==='ok','Every native reader SQL failure is honest and recoverable');$faults++;}}
    ex_reset();$failed=false;try{ex_export(null,10,0,'ASC',100);}catch(PhpbbTopicExportFailure$e){$failed=$e->getMessage()==='Topic_export_too_large';}nt_check($failed&&ex_export()['status']==='ok','Output cap aborts before response without poisoning next request');$cases++;
    foreach(array(array(0,0,'ASC',100),array('10',0,'ASC',100),array(-1,0,'ASC',100),array(16777216,0,'ASC',100),array(10,array(),'ASC',100),array(10,0,'asc',100),array(10,0,'ASC',0),array(10,0,'ASC',67108865))as$args){$before=count($ex_trace);$failed=false;try{phpbb_topic_export($db,ex_actor(),$args[0],$args[1],$args[2],$args[3]);}catch(PhpbbTopicExportFailure$e){$failed=true;}nt_check($failed&&count($ex_trace)===$before,'Invalid typed export contract fails before native SQL');$cases++;}
    ex_reset();ex_sql('ALTER TABLE fixture_posts ENGINE=MyISAM');try{$failed=false;try{ex_export();}catch(Exception$e){$failed=true;}nt_check($failed,'Noncanonical storage refused');$cases++;}finally{ex_sql('ALTER TABLE fixture_posts ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
    // >2 MiB forces php://temp onto disk. Never allocate the full result here.
    ex_reset();ex_sql('START TRANSACTION');ex_sql('DELETE FROM fixture_posts');ex_sql('DELETE FROM fixture_posts_text');for($i=1;$i<=640;$i++){ex_seed('fixture_posts',array('post_id'=>$i,'topic_id'=>10,'forum_id'=>3,'poster_id'=>7,'post_time'=>1700000000+$i));ex_seed('fixture_posts_text',array('post_id'=>$i,'post_text'=>str_repeat('X',8192),'bbcode_uid'=>''));}ex_sql('COMMIT');$memory=memory_get_usage(true);$r=phpbb_topic_export($db,ex_actor(),10,0);try{
        $title='Größe & 😀 10';$characters=preg_match_all('/./us',$title,$unused);$header="Forum:\tForum & 3\r\n".$lang['Topic'].":\t".$title."\r\n".str_repeat('-',$characters+8)."\r\n";$expected=hash_init('sha256');hash_update($expected,$header);$piece="\r\nCurrent & member:\r\n".str_repeat('X',8192)."\r\n";for($i=0;$i<640;$i++){hash_update($expected,$piece);}$expected=hash_final($expected);
        $actual=hash_init('sha256');$bytes=hash_update_stream($actual,$r['stream']);nt_check($r['status']==='ok'&&$bytes===$r['bytes']&&$bytes>5242880&&hash_final($actual)===$expected,'Actual multi-megabyte stream has exact complete bytes/hash');nt_check(memory_get_usage(true)-$memory<4194304,'Large export retains bounded memory rather than whole topic');$cases++;
    }finally{fclose($r['stream']);}
    echo 'Topic export native: ',$cases,' cases, ',$faults,' SQL faults, ',$views," actual-controller/header views PASS; canonical SQL/current ACLs and sessions, coherent keyset pages, tombstones, caller isolation, bounded temp stream and exact download headers.\n";
}finally{$ex_hook=null;if($db){$db->sql_close();}if($peer){$peer->sql_close();}if($made&&preg_match('/^codex_export_[a-f0-9]{16}$/D',$schema)){$control->sql_query('DROP DATABASE '.$schema);}if($control){$control->sql_close();}restore_error_handler();}
