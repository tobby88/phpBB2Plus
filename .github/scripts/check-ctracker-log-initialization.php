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
   cli_check($manager->metadata_kind(explode('|||',$lines[0]))==='created','Actual initialized header retains its meaning through the real line reader');
   $second='0|||2|||synthetic-second|||referrer|||agent|||ip|||host';
   cli_check($manager->write_to_log($id,$second),'Second event write succeeds');
   $next=$manager->read_log_lines($id,10);
   cli_check(count($next)===3&&$next[0]===$lines[0]&&$next[1]===$lines[1]&&$next[2]===$second."\n"&&$manager->check_log_size($id)===2,'Existing nonempty log is never reinitialized');
   cli_check($manager->delete_logfile($id),'Clear own initialized log');$cleared=$manager->read_log_lines($id,10);cli_check(count($cleared)===1&&$manager->metadata_kind(explode('|||',$cleared[0]))==='cleared','Actual clear marker survives the same reader framing');
  }
  $cases++;
 }}
 echo 'CrackerTracker first-entry initialization: ',$cases," real filesystem cases passed\n";
 foreach (array(2, 3, 4, 5) as $id) {
  $path = $init_root.'ctracker/logfiles/'.$init_files[$id];
  file_put_contents($path, '');
  file_put_contents($init_root.'ctracker/logfiles/'.$init_files[1], '7');
  $manager = new log_manager();
  $manager->prepare_log('Synthetic first event');
  $manager->write_general_logfile(1, $id);
  $manager->prepare_log('Synthetic next event');
  $manager->write_general_logfile(1, $id);
  $lines = $manager->read_log_lines($id, 10);
  cli_check(count($lines) === 2 && $manager->metadata_kind(explode('|||', $lines[0])) === 'rotated' &&
   strpos($lines[1], 'Synthetic next event') !== false, 'Automatic size-limit reset has its own marker: '.$id);
  $counted = in_array($id, array(2, 3, 5), true);
  cli_check($manager->check_log_size($id, true) === ($counted ? 2 : 1) &&
   file_get_contents($init_root.'ctracker/logfiles/'.$init_files[1]) === '7',
   'Only counted log types retain the previous event in their own marker');
  cli_check($manager->delete_logfile($id) &&
   $manager->metadata_kind(explode('|||', $manager->read_log_lines($id, 10)[0])) === 'cleared',
   'The explicit clear retains its existing marker');
 }
 $path = $init_root.'ctracker/logfiles/'.$init_files[2];
 $header = "1|||0|||null|||null|||null|||null|||null\n";
 file_put_contents($path, $header.str_repeat("0|||1|||synthetic|||referrer|||agent|||ip|||host\n", 100));
 file_put_contents($init_root.'ctracker/logfiles/'.$init_files[1], '7');
 $manager = new log_manager();
 $manager->prepare_log('Synthetic next event');
 $manager->write_worm();
 $lines = $manager->read_log_lines(2, 10);
 cli_check(count($lines) === 2 && $manager->metadata_kind(explode('|||', $lines[0])) === 'rotated' &&
  strpos($lines[1], 'Synthetic next event') !== false &&
  $manager->check_log_size(2, true) === 101 &&
  file_get_contents($init_root.'ctracker/logfiles/'.$init_files[1]) === '7',
  'The fixed-size log uses the automatic marker and keeps the previous event count in place');
 echo "CrackerTracker automatic rotation metadata: five real filesystem cases passed\n";
}finally{
 restore_error_handler();foreach($init_files as$name){$path=$init_root.'ctracker/logfiles/'.$name;if(is_file($path)){unlink($path);}}
 rmdir($init_root.'ctracker/logfiles');rmdir($init_root.'ctracker');rmdir($init_root);
}
