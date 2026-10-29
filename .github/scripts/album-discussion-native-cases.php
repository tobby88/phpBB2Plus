<?php
function ad_complete_outcome($before,$after,$method,$text,$point,$started){
 if($before===$after){return;}
 ad_check($before[0]===$after[0],'Discussion failure never alters any picture metadata');
 if($method==='delete'){$expected=$before;$expected[1]=array($before[1][1]);ad_check($after===$expected,'Committed delete removes only exact target comment and preserves all other state');return;}
 if($method==='change'){
  $expected=$before;ad_check(count($after[1])===2,'Edit preserves complete comment inventory');$row=$after[1][0];
  foreach(array('comment_text'=>$text,'comment_edit_count'=>1,'comment_edit_user_id'=>7)as$key=>$value){ad_check((string)$row[$key]===(string)$value,'Committed edit has all intended values');$expected[1][0][$key]=$row[$key];}
  ad_check((int)$row['comment_edit_time']>=$started&&(int)$row['comment_edit_time']<=time(),'Committed edit timestamp belongs to current action');$expected[1][0]['comment_edit_time']=$row['comment_edit_time'];ad_check($expected===$after,'Committed edit preserves every immutable and unrelated value');return;
 }
 ad_check(count($after[1])===3&&array_slice($after[1],0,2)===$before[1],'Committed publication preserves every old comment');$row=$after[1][2];
 foreach(array('comment_pic_id'=>1,'comment_cat_id'=>1,'comment_user_id'=>7,'comment_username'=>"Fresh's \\ Größe 😀",'comment_user_ip'=>'7f000001','comment_text'=>$text,'comment_edit_count'=>0)as$key=>$value){ad_check((string)$row[$key]===(string)$value,'Committed publication has full expected comment metadata');}
 ad_check($row['comment_edit_time']===null&&$row['comment_edit_user_id']===null&&(int)$row['comment_time']>=$started&&(int)$row['comment_time']<=time(),'New comment retains unedited state and current timestamp');
 ad_check(count($after[2])===1,'Combined publication cannot retain a comment without its vote');$vote=$after[2][0];foreach(array('rate_pic_id'=>1,'rate_user_id'=>7,'rate_user_ip'=>'7f000001','rate_point'=>$point,'rate_hon_point'=>0)as$key=>$value){ad_check((string)$vote[$key]===(string)$value,'Combined vote retains exact complete selected channel');}
}
function ad_discussion_cases(){
 global $db,$cases,$userdata;
 foreach(array('','STRICT_ALL_TABLES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','STRICT_ALL_TABLES,ANSI_QUOTES')as$mode){
  ad_reset();$db->sql_query("SET SESSION sql_mode='".$mode."'");$text=AlbumPictureDiscussion::input_text(addslashes("Grüße ' \\ 😀"),65535);$before=ad_snapshot();$db->watch=true;$db->before=$before;$result=ad_action('publish',$text,5);
  $rows=ad_rows('SELECT * FROM fixture_album_comment WHERE comment_id='.(int)$result['comment']);ad_check(count($rows)===1&&$rows[0]['comment_text']===$text&&$rows[0]['comment_username']==="Fresh's \\ Größe 😀"&&$rows[0]['comment_user_ip']==='7f000001','Fresh registered byline and complete canonical text survive SQL modes');ad_check(count(ad_rows('SELECT * FROM fixture_album_rate'))===1,'Combined comment and rating committed together');$cases++;
 }
 $db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
 foreach(array(1,2,3,4,255,65535)as$limit){foreach(array("ä","😀","&","'",'"','<','>')as$unit){
  ad_reset();ad_sql("UPDATE fixture_album_config SET config_value='".$limit."' WHERE config_name='desc_length'");$text=AlbumPictureDiscussion::input_text(addslashes(str_repeat($unit,$limit+2)),$limit);$result=ad_action('publish',$text);$saved=ad_rows('SELECT comment_text FROM fixture_album_comment WHERE comment_id='.(int)$result['comment'])[0]['comment_text'];ad_check($saved===$text&&preg_match('//u',$saved)===1&&html_entity_decode($saved,ENT_QUOTES,'UTF-8')===str_repeat($unit,$limit),'Logical character boundary fits complete escaped Unicode, including the largest configured description');$cases++;
 }}
 ad_reset();ad_actor(-1);$guest=AlbumPictureDiscussion::input_guest_name(addslashes(str_repeat('"',32).'extra'));$text=AlbumPictureDiscussion::input_text(addslashes('Guest Grüße 😀'),65535);$result=(new AlbumPictureDiscussion($db,'fixture_'))->publish($userdata,0,1,1,$text,$guest);ad_check(ad_rows('SELECT comment_username FROM fixture_album_comment WHERE comment_id='.(int)$result['comment'])[0]['comment_username']===str_repeat('&quot;',32),'Guest logical name fits escaped storage');$cases++;
 foreach(array('publish','change','delete')as$method){
  ad_reset();ad_controller($method,"Actual controller ' \\ Grüße 😀",$method==='publish'?5:null);$rows=ad_rows('SELECT * FROM fixture_album_comment WHERE comment_id=1');if($method==='delete'){ad_check(!$rows,'Actual delete controller uses owned writer');}elseif($method==='change'){ad_check($rows[0]['comment_text']===htmlspecialchars("Actual controller ' \\ Grüße 😀",ENT_QUOTES,'UTF-8'),'Actual edit controller preserves Unicode/quotes/backslashes');}else{ad_check(count(ad_rows('SELECT comment_id FROM fixture_album_comment'))===3&&count(ad_rows('SELECT * FROM fixture_album_rate'))===1,'Actual publish controller commits both inputs');}$cases++;
 }
 ad_reset();ad_sql('INSERT INTO fixture_album_rate VALUES(1,7,\'7f000001\',5,0)');ad_denied(function(){ad_action('publish','No partial comment',7);},'Duplicate vote does not leave half a combined comment');
 ad_reset();ad_sql('UPDATE fixture_album_comment SET comment_user_id=8 WHERE comment_id=1');foreach(array('change','delete')as$method){ad_denied(function()use($method){ad_action($method);},'Ordinary user cannot alter another author comment');}
 foreach(array('change','delete')as$method){foreach(array('moderator','admin')as$role){ad_reset();ad_sql('UPDATE fixture_album_comment SET comment_user_id=8 WHERE comment_id=1');ad_sql($role==='admin'?'UPDATE fixture_users SET user_level=1 WHERE user_id=7':'INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,7,0)');ad_action($method);$cases++;}}
 foreach(array('publish','change','delete')as$method){foreach(array("DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_time=1","UPDATE fixture_users SET user_active=0 WHERE user_id=7","UPDATE fixture_album_cat SET cat_view_level=1 WHERE cat_id=1","UPDATE fixture_album_config SET config_value='0' WHERE config_name='comment'","UPDATE fixture_album SET pic_cat_id=2 WHERE pic_id=1","DELETE FROM fixture_album WHERE pic_id=1")as$change){ad_reset();ad_sql($change);ad_denied(function()use($method){ad_action($method);},'Fresh authority/parent required despite stale page state');}}
 foreach(array('publish','change','delete')as$method){ad_reset();ad_actor(-1);if($method==='publish'){ad_sql("UPDATE fixture_album_cat SET cat_comment_level=1 WHERE cat_id=1");}ad_denied(function()use($method){ad_action($method);},'Guest cannot bypass comment policy or edit/delete authentication');}
 foreach(array('publish','change')as$method){foreach(array('','   ','<raw>',str_repeat('x',65536),"bad\xff")as$text){ad_reset();ad_denied(function()use($method,$text){ad_action($method,$text);},'Noncanonical/empty/oversized comment refuses without writes');}}
 foreach(array('comment_username'=>'VARCHAR(32)','comment_text'=>'TEXT')as$field=>$type){ad_reset();ad_sql('ALTER TABLE fixture_album_comment MODIFY '.$field.' '.$type.' NULL');ad_denied(function(){ad_action('publish');},'Missing text capacity migration refuses before insert');ad_sql('ALTER TABLE fixture_album_comment MODIFY comment_username VARCHAR(255) NULL, MODIFY comment_text MEDIUMTEXT NULL');}
 ad_reset();ad_sql('UPDATE fixture_album_comment SET comment_edit_count=65535 WHERE comment_id=1');ad_action('change');ad_check((int)ad_rows('SELECT comment_edit_count FROM fixture_album_comment WHERE comment_id=1')[0]['comment_edit_count']===65535,'Historical edit counter saturates without losing new text');$cases++;
 foreach(array(0,-1,256,11,'5')as$point){ad_reset();ad_denied(function()use($point){ad_action('publish','No partial comment',$point);},'Invalid typed/current-scale vote cannot leave a comment');}
 foreach(array("DELETE FROM fixture_album_config WHERE config_name='desc_length'","UPDATE fixture_album_config SET config_value='bad' WHERE config_name='desc_length'","UPDATE fixture_album_config SET config_value='65536' WHERE config_name='desc_length'","UPDATE fixture_album_config SET config_value='2' WHERE config_name='desc_length'")as$change){ad_reset();ad_sql($change);ad_denied(function(){ad_action('publish','Longer than the fresh limit');},'Current logical length configuration required before publication');}
 ad_reset();ad_actor(-1);ad_action('publish','',4);ad_denied(function(){ad_action('publish','',5);},'Same guest IP cannot rate same channel twice');ad_sql("UPDATE fixture_sessions SET session_ip='7f000002'");ad_action('publish','',6);ad_check(count(ad_rows('SELECT * FROM fixture_album_rate WHERE rate_pic_id=1'))===2,'Different current guest IP has independent vote identity');$cases++;
 ad_reset();ad_action('publish','',4);ad_sql("UPDATE fixture_sessions SET session_ip='7f000002'");ad_denied(function(){ad_action('publish','',5);},'Registered vote identity survives changed IP');
 ad_reset();ad_sql("INSERT INTO fixture_album_rate VALUES(2,8,'7f000002',3,6)");$before=ad_rows('SELECT * FROM fixture_album_rate WHERE rate_pic_id=2');ad_action('publish','Independent comment',5);ad_check(ad_rows('SELECT * FROM fixture_album_rate WHERE rate_pic_id=2')===$before,'Other picture ratings remain byte-exact');$cases++;
 ad_reset();ad_sql("CREATE TRIGGER own_comment_rewrite BEFORE INSERT ON fixture_album_comment FOR EACH ROW SET NEW.comment_text='Unexpected'");ad_denied(function(){ad_action('publish','Preserve',5);},'Readback mismatch rolls back rating and comment');ad_sql('DROP TRIGGER own_comment_rewrite');
 ad_reset();ad_sql("CREATE TRIGGER own_comment_edit BEFORE UPDATE ON fixture_album_comment FOR EACH ROW SET NEW.comment_user_id=8");ad_denied(function(){ad_action('change');},'Unexpected trigger author change rolls back edit');ad_sql('DROP TRIGGER own_comment_edit');
 ad_reset();$db->lost=true;ad_denied(function(){ad_action('publish','Actual commit',5);},'Lost commit acknowledgement is reported, never blindly retried',false);ad_check(count(ad_rows('SELECT comment_id FROM fixture_album_comment'))===3&&count(ad_rows('SELECT * FROM fixture_album_rate'))===1,'Uncertain acknowledgement preserves the actual committed comment and vote');$cases++;
 ad_reset();$db->sql_query('START TRANSACTION');$db->sql_query("INSERT INTO fixture_config VALUES('caller_unrelated','pending')");ad_action('publish');ad_check(!ad_rows("SELECT * FROM fixture_config WHERE config_name='caller_unrelated'"),'Dedicated writer never commits caller pending transaction');$db->sql_query('ROLLBACK');$cases++;
 if(getenv('PHPBB_ALBUM_DISCUSSION_QUICK')==='1'){return;}
 foreach(array('publish','change','delete')as$method){
  ad_reset();ad_action($method,'Fault boundary',5);$baseline=$db->queries;
  foreach($baseline as$index=>$sql){ad_reset();$before=ad_snapshot();$db->reset_case($index+1);$started=time();try{ad_action($method,'Fault boundary',5);}catch(Exception$error){}ad_complete_outcome($before,ad_snapshot(),$method,'Fault boundary',5,$started);$cases++;}
 }
 ad_hotornot_cases();
 ad_parallel_vote_cases();
 ad_rating_display_cases();
}
function ad_hotornot_cases(){
 global $cases,$db,$userdata;
 foreach(array(false,true)as$honfirst){ad_reset();if($honfirst){ad_action('hotornot','',8);ad_action('publish','',4);}else{ad_action('publish','',4);ad_action('hotornot','',8);}ad_check(ad_rows('SELECT rate_point,rate_hon_point FROM fixture_album_rate')===array(array('rate_point'=>4,'rate_hon_point'=>8))||ad_rows('SELECT rate_point,rate_hon_point FROM fixture_album_rate')===array(array('rate_point'=>'4','rate_hon_point'=>'8')),'Independent channels fill one existing row without blocking or zero dilution');$cases++;}
 foreach(array('publish','hotornot')as$method){ad_denied(function()use($method){ad_action($method,'',6);},'One vote per selected channel when configured');}
 ad_reset();ad_sql("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_sep'");ad_action('hotornot','',8);ad_denied(function(){ad_action('publish','',6);},'Shared channel correctly prevents another normal vote');
 ad_reset();ad_sql("UPDATE fixture_album_sp_config SET config_value='1' WHERE config_name='hon_rate_times'");ad_action('hotornot','',8);ad_action('hotornot','',6);ad_check(count(ad_rows('SELECT * FROM fixture_album_rate'))===2,'Configured repeat votes retained');$cases++;
 ad_reset();ad_actor(-1);ad_action('hotornot','',8);ad_action('hotornot','',6);ad_check(count(ad_rows('SELECT * FROM fixture_album_rate'))===2,'Enabled guests retain repeat HotOrNot votes');$cases++;
 ad_reset();ad_actor(-1);ad_sql("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_users'");ad_denied(function(){ad_action('hotornot');},'Current guest switch rechecked');
 ad_reset();ad_sql("UPDATE fixture_album_sp_config SET config_value='2,3' WHERE config_name='hon_rate_where'");ad_denied(function(){ad_action('hotornot');},'Posted photo outside current HotOrNot categories refused');
 ad_reset();ad_sql("UPDATE fixture_album_sp_config SET config_value='1,2' WHERE config_name='hon_rate_where'");ad_controller('hotornot','',7);ad_check(count(ad_rows('SELECT * FROM fixture_album_rate'))===1,'Actual HotOrNot controller writes explicit mandatory normal and separate columns');$cases++;
 foreach(array("UPDATE fixture_album SET pic_lock=1 WHERE pic_id=1","UPDATE fixture_album_config SET config_value='0' WHERE config_name='rate'","UPDATE fixture_album_config SET config_value='2' WHERE config_name='rate_scale'","DELETE FROM fixture_album_sp_config WHERE config_name='hon_rate_sep'","UPDATE fixture_album_sp_config SET config_value='bad' WHERE config_name='hon_rate_sep'")as$change){ad_reset();ad_sql($change);ad_denied(function(){ad_action('hotornot');},'Current lock/rating/settings limits fail closed');}
 foreach(array('engine','format','charset','column')as$change){ad_reset();ad_sql($change==='engine'?'ALTER TABLE fixture_album_sp_config ENGINE=MyISAM':($change==='format'?'ALTER TABLE fixture_album_sp_config ROW_FORMAT=COMPACT':($change==='charset'?'ALTER TABLE fixture_album_sp_config DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci':'ALTER TABLE fixture_album_sp_config MODIFY config_value VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT \'\'')));ad_denied(function(){ad_action('hotornot');},'Optional current policy table must have canonical transactional storage');ad_sql('ALTER TABLE fixture_album_sp_config ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
 ad_reset();ad_sql("CREATE TRIGGER own_hon_rewrite BEFORE INSERT ON fixture_album_rate FOR EACH ROW SET NEW.rate_point=2");ad_denied(function(){ad_action('hotornot');},'Unexpected other-channel rewrite rolls back rating');ad_sql('DROP TRIGGER own_hon_rewrite');
 ad_reset();ad_sql('SET SESSION innodb_lock_wait_timeout=1');$db->sp_peer=true;ad_action('hotornot');ad_check(!$db->sp_peer,'Actual policy lock attempt observed');ad_sql("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_sep'");ad_sql('SET SESSION innodb_lock_wait_timeout=50');ad_check((int)ad_rows('SELECT rate_hon_point FROM fixture_album_rate')[0]['rate_hon_point']===5,'Policy change succeeds only after previous vote committed under original channel');$cases++;
 ad_reset();$db->lost=true;ad_denied(function(){ad_action('hotornot');},'Lost rating commit acknowledgement reported without retry',false);ad_check(count(ad_rows('SELECT * FROM fixture_album_rate'))===1,'Actual committed uncertain vote retained once');$cases++;
 ad_reset();ad_action('hotornot','',5);$baseline=$db->queries;foreach($baseline as$i=>$sql){ad_reset();$db->reset_case($i+1);try{ad_action('hotornot','',5);}catch(Exception$error){}$rows=ad_rows('SELECT rate_point,rate_hon_point FROM fixture_album_rate');ad_check(!$rows||count($rows)===1&&(int)$rows[0]['rate_point']===0&&(int)$rows[0]['rate_hon_point']===5,'Every HotOrNot SQL failure leaves no partial/invalid rating');$cases++;}
}

function ad_parallel_vote_cases(){
 global $schema,$cases;
 $directory=sys_get_temp_dir().'/'.$schema;ad_check(!file_exists($directory)&&mkdir($directory,0700),'Own parallel vote directory');$nonce=bin2hex(phpbb_random_bytes(16));ad_check(file_put_contents($directory.'/owner',$nonce)===32,'Own worker identity');$workers=array();
 try{foreach(array(array('normal','normal'),array('normal','hotornot'))as$modes){
  ad_reset();$before=ad_snapshot();
  foreach($modes as$mode){$command=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';if(extension_loaded('mbstring')){$command.=' -d extension=php_mbstring.dll';}}
   $command.=' '.escapeshellarg(__DIR__.'/check-album-discussion-native.php').' --vote-worker '.escapeshellarg($schema).' '.escapeshellarg($nonce).' '.escapeshellarg($mode);
   $p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));ad_check(is_resource($p),'Owned concurrent rating child');fclose($pipes[0]);$workers[]=array('process'=>$p,'pipes'=>$pipes);
  }
  ad_check(file_put_contents($directory.'/start','start')===5,'Release both independent vote workers');$results=array();
  foreach($workers as$key=>$worker){$deadline=microtime(true)+20;do{$status=proc_get_status($worker['process']);if(!$status['running']){break;}if(microtime(true)>$deadline){throw new RuntimeException('Owned rating child deadline');}usleep(10000);}while(true);
   $out=stream_get_contents($worker['pipes'][1]);$err=stream_get_contents($worker['pipes'][2]);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);$closed=proc_close($worker['process']);unset($workers[$key]);$code=$status['exitcode']>=0?$status['exitcode']:$closed;ad_check($code===0,'Own rating child completed: '.$err.$out);$value=json_decode(trim($out),true);ad_check(is_array($value)&&isset($value['rated'])&&is_bool($value['rated']),'Typed concurrent vote result');$results[]=$value['rated'];
  }
  $after=ad_snapshot();ad_check($before[0]===$after[0]&&$before[1]===$after[1]&&count($after[2])===1,'Concurrent votes preserve all pictures/comments and one shared voter row');
  ad_check((int)$after[2][0]['rate_point']===5&&(int)$after[2][0]['rate_hon_point']===($modes[1]==='normal'?0:5),'Concurrent channel result exact');ad_check(array_sum($results)===($modes[1]==='normal'?1:2),'Same-channel duplicate refused; separate channels both succeed');$cases++;
  unlink($directory.'/start');
 }}finally{foreach($workers as$worker){proc_terminate($worker['process'],9);foreach(array(1,2)as$i){if(is_resource($worker['pipes'][$i])){fclose($worker['pipes'][$i]);}}proc_close($worker['process']);}foreach(array('start','owner')as$file){if(is_file($directory.'/'.$file)){unlink($directory.'/'.$file);}}rmdir($directory);}
}

function ad_rating_display_cases(){
 global $root,$cases,$db,$userdata,$album_sp_config;
 ad_reset();ad_sql("INSERT INTO fixture_album_rate VALUES(1,7,'7f000001',4,0),(1,8,'7f000002',0,8)");
 foreach(array('album.php','album_cat.php','album_modcp.php','album_personal.php','album_showpage.php','album_hotornot.php','portal.php','album_mod/album_hierarchy_sql.php','album_mod/album_memberlist.php')as$file){
  $source=file_get_contents($root.$file);ad_check(!preg_match('/AVG\(r\.rate_(?:hon_)?point\)/',$source),'Every Album/portal reader excludes unused zero channel placeholders');
  ad_check(preg_match_all('/AVG\(NULLIF\(r\.(rate_(?:hon_)?point), 0\)\)/',$source,$matches,PREG_SET_ORDER)>0,'Actual rating expression extracted');
  foreach($matches as$match){$row=ad_rows('SELECT '.$match[0].' AS rating FROM fixture_album_rate r WHERE rate_pic_id=1')[0];ad_check((float)$row['rating']===($match[1]==='rate_point'?4.0:8.0),'Actual display SQL excludes the other channel instead of diluting its average');$cases++;}
 }
 $phpbb_root_path=$root;$phpEx='php';require_once $root.'album_mod/clown_album_functions.php';$album_sp_config=array('hon_rate_users'=>1,'hon_rate_times'=>0,'hon_rate_sep'=>1);
 ad_check(CanRated(1,7)===true,'Actual HotOrNot UI allows a separate vote after normal rating');$cases++;
 $album_sp_config['hon_rate_sep']=0;ad_check(CanRated(1,7)===false,'Actual shared-channel UI recognizes normal rating');$cases++;
 $album_sp_config['hon_rate_sep']=1;ad_check(CanRated(1,8)===false,'Actual separate-channel UI recognizes existing HotOrNot vote');$cases++;
 $album_sp_config['hon_rate_times']=1;ad_check(CanRated(1,8)===true,'Actual repeat-vote UI preserves configured repeated ratings');$cases++;
 ad_actor(-1);$album_sp_config['hon_rate_users']=0;ad_check(CanRated(1,-1)===false,'Actual guest UI respects disabled guest ratings');$cases++;
 $album_sp_config['hon_rate_users']=1;ad_check(CanRated(1,-1)===true,'Actual guest UI offers configured repeat votes');$cases++;
}
