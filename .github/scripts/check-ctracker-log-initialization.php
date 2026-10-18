<?php
// Real file writer on own disposable logs; no forum, database or network access.
require dirname(dirname(__DIR__)).'/phpBB2/ctracker/classes/class_log_manager.php';
function cli_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$init_root=sys_get_temp_dir().'/phpbb-ct-initial-'.getmypid().'-'.mt_rand(100000,999999).'/';
$init_files=array(1=>'logfile_attempt_counter.txt',2=>'logfile_worms.txt',3=>'logfile_blocklist.txt',
    4=>'logfile_malformed_logins.txt',5=>'logfile_spammer.txt',6=>'logfile_debug_mode.txt');
cli_check(mkdir($init_root,0700)&&mkdir($init_root.'ctracker',0700)&&mkdir($init_root.'ctracker/logfiles',0700),'Own fixture directories');
$phpbb_root_path=$init_root;$HTTP_SERVER_VARS=array();$HTTP_ENV_VARS=array();$cases=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array(2,3,4,5,6)as$id){foreach(array('missing','empty')as$kind){
  foreach($init_files as$number=>$name){file_put_contents($init_root.'ctracker/logfiles/'.$name,$number===1?'7':($number===6?'':"1|||0|||null|||null|||null|||null|||null\n"));}
  $path=$init_root.'ctracker/logfiles/'.$init_files[$id];if($kind==='missing'){unlink($path);}else{file_put_contents($path,'');}
  $manager=new log_manager();$entry='0|||1|||synthetic-first|||referrer|||agent|||ip|||host';
  cli_check($manager->write_to_log($id,$entry),'Initial event write succeeds');
  $lines=$manager->read_log_lines($id,10);
  cli_check($manager->check_log_size($id)===1,'First event must be counted: '.$id.'/'.$kind);
  cli_check($manager->get_counter_value()===7+(in_array($id,array(2,3,5),true)?1:0),'Historical aggregate and non-attack policy preserved');
  if($id===6){cli_check($lines===array($entry."\n"),'Debug log remains header-free');}
  else{
   cli_check(count($lines)===2&&strpos($lines[0],'1|||')===0&&$lines[1]===$entry."\n",'First event follows exactly one real header');
   $second='0|||2|||synthetic-second|||referrer|||agent|||ip|||host';
   cli_check($manager->write_to_log($id,$second),'Second event write succeeds');
   $next=$manager->read_log_lines($id,10);
   cli_check(count($next)===3&&$next[0]===$lines[0]&&$next[1]===$lines[1]&&$next[2]===$second."\n"&&$manager->check_log_size($id)===2,'Existing nonempty log is never reinitialized');
  }
  $cases++;
 }}
 echo 'CrackerTracker first-entry initialization: ',$cases," real filesystem cases passed\n";
}finally{
 restore_error_handler();foreach($init_files as$name){$path=$init_root.'ctracker/logfiles/'.$name;if(is_file($path)){unlink($path);}}
 rmdir($init_root.'ctracker/logfiles');rmdir($init_root.'ctracker');rmdir($init_root);
}
