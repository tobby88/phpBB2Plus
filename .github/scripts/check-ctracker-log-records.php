<?php
$root=dirname(dirname(__DIR__));require$root.'/phpBB2/includes/php_compat.php';require$root.'/phpBB2/ctracker/classes/class_log_manager.php';date_default_timezone_set('UTC');$HTTP_SERVER_VARS=$HTTP_ENV_VARS=array();
function lr_owned($base){$real=realpath($base);if($real===false||is_link($base)||dirname($real)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_ct_records_[a-f0-9]{16}$/D',basename($real))){throw new RuntimeException('Own log record fixture required');}return$real;}
if(isset($argv[1])&&$argv[1]==='--memory-child'){
 $phpbb_root_path=lr_owned($argv[2]).DIRECTORY_SEPARATOR;$manager=new log_manager();$event=$manager->check_log_size(5);$debug=$manager->check_log_size(6);$tail=$manager->read_log_lines(5,10);
 if($event!==1||$debug!==2||$tail!==array()){throw new RuntimeException('Bounded-memory count/group/tail result differs');}echo "Bounded CT record count and oversized tail: PASS at 16 MiB memory limit.\n";exit(0);
}
function lr_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['lr_checks']++;}
function lr_lines($bytes){return preg_split('/(?<=\n)/',$bytes,-1,PREG_SPLIT_NO_EMPTY);}
function lr_expected($bytes,$limit){$valid=array();foreach(lr_lines($bytes)as$line){if(strlen($line)<=16384){$valid[]=$line;}}return array_slice($valid,-max(1,min(5000,(int)$limit)));}
function append_sid($url){return$url;}function phpbb_admin_post_string($key,$default=''){return isset($_POST[$key])?(string)$_POST[$key]:$default;}function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="record-fixture" />';}function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function message_die($level,$text){throw new RuntimeException('Unexpected ACP record message: '.$text);}
class LogRecordsTemplate{public$blocks=array();function set_filenames($files){}function assign_vars($vars){}function assign_block_vars($name,$row){$this->blocks[$name][]=$row;}function pparse($name){}}
function lr_test($bytes,$limit=10){
 global$manager,$log_root;
 file_put_contents($log_root.'logfile_spammer.txt',$bytes);$before=hash_file('sha256',$log_root.'logfile_spammer.txt');
 lr_check($manager->read_log_lines(5,$limit)===lr_expected($bytes,$limit),'Actual newest tail retains complete logical records, line endings and Unicode');
 $expected=max(0,count(lr_lines($bytes))-1);lr_check($manager->check_log_size(5)===$expected&&hash_file('sha256',$log_root.'logfile_spammer.txt')===$before,'Counting is read-only and excludes exactly one reserved header');
 lr_check($manager->delete_logfile(5)&&$manager->last_deleted_entries===$expected&&$manager->check_log_size(5)===0,'Reset transfers each logical event exactly once under its existing lock');
}
$base=sys_get_temp_dir().'/codex_ct_records_'.bin2hex(phpbb_random_bytes(8));$created=false;$lr_checks=0;$manager=null;$header="1|||0|||null|||null|||null|||null|||null\n";
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 lr_check(mkdir($base,0700)&&mkdir($base.'/ctracker',0700)&&mkdir($base.'/ctracker/logfiles',0700)&&mkdir($base.'/cache',0700),'Own log record directories');$created=true;$phpbb_root_path=$base.'/';$log_root=$phpbb_root_path.'ctracker/logfiles/';$manager=new log_manager();
 foreach(array('',"\n",'unterminated',$header,$header."\n",$header."first\r\nsecond\r\nthird",$header."Grüße 😀\nnext\n",$header.str_repeat('x',16384)."0|||123|||synthetic|||referrer|||agent|||address|||host\n".$header)as$bytes){foreach(array(1,2,10,0,5001)as$limit){lr_test($bytes,$limit);}}
 foreach(array(8190,8191,8192,8193,16382,16383,16384,16385,32768)as$length){lr_test($header.str_repeat('x',$length)."\nactual\n");lr_test($header.str_repeat('x',$length)."\nactual");}
 define('IN_PHPBB',true);define('CTRACKER_ACP',true);require __DIR__.'/ctracker-log-controller-fixture.php';$phpEx='php';$images=array('ctracker_log_manager'=>'fixture.gif');$board_config=array('default_dateformat'=>'Y-m-d H:i:s');
 foreach(array('english','german')as$locale){$lang=array();require$root.'/phpBB2/language/lang_'.$locale.'/lang_cback_ctracker.php';$data=$header.str_repeat('x',16384)."0|||123|||invented-middle|||referrer|||agent|||address|||host\n0|||456|||Actual & <literal>|||referrer|||agent|||address|||host\n";file_put_contents($log_root.'logfile_spammer.txt',$data);$_GET=array('mode'=>'view','logid'=>'5');$_POST=array();$template=new LogRecordsTemplate();eval(ctracker_log_fixture_source($root.'/phpBB2/'));
  lr_check(count($template->blocks['show_log'])===1&&$template->blocks['show_log'][0]['L_OUTPUT_2']==='Actual &amp; &lt;literal&gt;','Actual ACP does not manufacture an event from a middle fragment and escapes a real event');lr_check(strpos($template->blocks['show_log_header'][0]['L_MESSAGE_TEXT'],$lang['ctracker_log_manager_tail_info'])!==false,'Actual ACP explains the bounded display in both languages');lr_check(file_get_contents($log_root.'logfile_spammer.txt')===$data,'Actual ACP view leaves every original record byte unchanged');
 }
 mt_srand(73);for($case=0;$case<60;$case++){$bytes=$header;for($line=0,$n=mt_rand(1,7);$line<$n;$line++){$count=mt_rand(0,19000);$bytes.=str_repeat($line%2?'u':'x',$count).($line%2?'Grüße 😀':'').(mt_rand(0,2)==0?"\r\n":"\n");}if($case%3===0){$bytes=rtrim($bytes,"\n");}lr_test($bytes,mt_rand(1,5));}
 // Debug groups compare the complete first logical line, including its ending.
 foreach(array(array('',0),array('header',1),array("head\nother\nhead\n",2),array("head\r\nhead\nhead\r\n",2),array(str_repeat('h',17000)."\nother\n".str_repeat('h',17000)."\n",2))as$case){file_put_contents($log_root.'logfile_debug_mode.txt',$case[0]);lr_check($manager->check_log_size(6)===$case[1],'Debug group counting preserves exact complete-header identity');}
 // Exercise a byte-budget cut within a logical record. No partial oldest
 // record or middle fragment may be returned as an independently valid event.
 $path=$log_root.'logfile_spammer.txt';$h=fopen($path,'wb');fwrite($h,$header);$chunk=str_repeat('b',8192);for($i=0;$i<1100;$i++){fwrite($h,$chunk);}fwrite($h,"\nnewest\n");fclose($h);lr_check($manager->read_log_lines(5,1000)===array("newest\n"),'Hard byte budget discards a cut/oversized record while retaining the newest complete entry');
 $h=fopen($path,'wb');fwrite($h,$header);for($i=0;$i<1100;$i++){$prefix='record-'.$i.'-';fwrite($h,$prefix.str_repeat('v',8192-strlen($prefix))."\n");}fclose($h);$tail=$manager->read_log_lines(5,1000);lr_check(count($tail)===1000&&strpos($tail[0],'record-100-')===0&&strpos($tail[999],'record-1099-')===0&&strlen($tail[0])===8193,'Tail byte budget retains the full newest thousand records at the current writer maximum');unset($tail);
 file_put_contents($path,str_repeat("x\n",6000));lr_check(count($manager->read_log_lines(5,999999))===5000,'Public reader retains its existing maximum-line ceiling');
 // Count giant records in a fresh constrained child. The fixture writes in
 // small chunks so allocating the test data cannot mask reader memory growth.
 $h=fopen($path,'wb');fwrite($h,$header);for($i=0;$i<2560;$i++){fwrite($h,$chunk);}fwrite($h,"\n");fclose($h);
 $h=fopen($log_root.'logfile_debug_mode.txt','wb');for($group=0;$group<2;$group++){for($i=0;$i<2560;$i++){fwrite($h,$chunk);}fwrite($h,"\n");}fclose($h);
 $command=escapeshellarg(PHP_BINARY).' -n -d memory_limit=16M -d display_errors=stderr -d log_errors=0 '.escapeshellarg(__FILE__).' --memory-child '.escapeshellarg($base);$child=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));lr_check(is_resource($child),'Constrained reader child starts');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($child);lr_check($status===0&&$err===''&&strpos($out,'PASS at 16 MiB')!==false,'Giant event/header counts and bounded tail complete within 16 MiB: '.substr($err,0,200));
 echo 'CrackerTracker logical records: ',$lr_checks," actual tail/framing/LF/CRLF/Unicode/oversize/debug/count/reset/read-only/16MiB-memory checks PASS.\n";
}finally{restore_error_handler();if($created){lr_owned($base);foreach(array('logfile_spammer.txt','logfile_debug_mode.txt')as$name){if(is_file($log_root.$name)){unlink($log_root.$name);}}rmdir($base.'/ctracker/logfiles');rmdir($base.'/ctracker');rmdir($base.'/cache');rmdir($base);}}
