<?php
// Real log manager and ACP module after authenticated bootstrap; only own files.
define('IN_PHPBB', true); define('CTRACKER_ACP', true); define('GENERAL_ERROR', 2);
$counter_source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $counter_source . 'includes/php_compat.php';
require $counter_source . 'ctracker/classes/class_log_manager.php';
$cache_source=file_get_contents($counter_source.'includes/functions.php');
$cache_start=strpos($cache_source,'function phpbb_serialized_data_read(');
$cache_end=strpos($cache_source,'function phpbb_data_store_read(',$cache_start);
if($cache_start===false||$cache_end<=$cache_start){throw new RuntimeException('Actual cache helper boundary unavailable');}
eval(substr($cache_source,$cache_start,$cache_end-$cache_start));
$bootstrap = file_get_contents($counter_source . 'admin/pagestart.php');
$start = strpos($bootstrap, "if (!function_exists('phpbb_admin_post_session_valid'))");
$end = strpos($bootstrap, 'if (empty($no_page_header))', $start);
if ($start === false || $end <= $start) { throw new RuntimeException('ACP request helpers unavailable'); }
eval(substr($bootstrap, $start, $end - $start));
class CounterPolicyTemplate {
    function set_filenames($values) {}
    function assign_block_vars($name, $values) {}
    function assign_vars($values) {}
    function pparse($name) {}
}
function append_sid($url) { return $url; }
function message_die($level, $message) { throw new RuntimeException($message); }
function cp_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$counter_root = sys_get_temp_dir() . '/phpbb-ct-counter-' . getmypid() . '-' . bin2hex(phpbb_random_bytes(6)) . '/';
$counter_files = array(1=>'logfile_attempt_counter.txt', 2=>'logfile_worms.txt', 3=>'logfile_blocklist.txt',
    4=>'logfile_malformed_logins.txt', 5=>'logfile_spammer.txt', 6=>'logfile_debug_mode.txt');
cp_check(mkdir($counter_root,0700) && mkdir($counter_root.'ctracker',0700) && mkdir($counter_root.'ctracker/logfiles',0700) && mkdir($counter_root.'cache',0700), 'Own fixture directories');
$phpbb_root_path = $counter_root; $HTTP_SERVER_VARS = array('REMOTE_ADDR'=>'192.0.2.1'); $HTTP_ENV_VARS = array();
$userdata = array('session_id'=>'counter-fixture'); $template = new CounterPolicyTemplate();
$phpEx = 'php'; $lang = array(); require $counter_source . 'language/lang_english/lang_cback_ctracker.php';
$images = array('ctracker_log_manager'=>'fixture.gif');
function cp_reset() {
    $cache=$GLOBALS['counter_root'].'cache/ctracker_counter.cache'; if(is_file($cache)){unlink($cache);}
    foreach ($GLOBALS['counter_files'] as $id=>$name) {
        $body = $id===1 ? '7' : ($id===6 ? '' : "1|||0|||null|||null|||null|||null|||null\n");
        cp_check(file_put_contents($GLOBALS['counter_root'].'ctracker/logfiles/'.$name, $body)!==false, 'Initialize fixture');
    }
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'counter-fixture'); $_GET=array();
}
function cp_admin($mode, $id=0) {
    global $template, $lang, $images, $phpEx, $phpbb_root_path;
    $_POST=array('mode'=>$mode,'logid'=>(string)$id,'sid'=>'counter-fixture');
    include $GLOBALS['counter_source'].'ctracker/admin/acp_module_logmanager.php';
}
$cases=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try {
    foreach (array(2,3,4,5,6) as $id) {
        cp_reset(); $manager=new log_manager();
        $manager->write_to_log($id,'0|||1|||synthetic|||referrer|||agent|||ip|||host');
        $manager->write_to_log($id,'0|||2|||synthetic|||referrer|||agent|||ip|||host');
        $before=$manager->get_counter_value(); cp_check(is_file($counter_root.'cache/ctracker_counter.cache'),'Actual counter cache created'); cp_admin('delete',$id);
        cp_check($manager->check_log_size($id)===0, 'Actual ACP clears selected log');
        cp_check($manager->get_counter_value()===$before, 'Actual ACP deletion does not change attack total: '.$id); $cases++;
        cp_reset(); $manager=new log_manager(); $counted=in_array($id,array(2,3,5),true);
        $manager->write_general_logfile(1,$id);
        cp_check($manager->get_counter_value()===7+($counted?1:0), 'Initial counter includes only attack logs');
        $manager->write_general_logfile(1,$id);
        $manager->write_general_logfile(1,$id);
        cp_check($manager->get_counter_value()===7+($counted?3:0), 'Rotation retains the same attack-log policy: '.$id);
        cp_check($manager->check_log_size($id)===1, 'Automatic rotation retains the latest entry'); $cases++;
    }
    cp_reset(); $manager=new log_manager();
    foreach (array(2,3,4,5,6) as $id) { $manager->write_to_log($id,'0|||1|||synthetic|||referrer|||agent|||ip|||host'); }
    $before=$manager->get_counter_value(); cp_admin('delete_all');
    foreach (array(2,3,4,5,6) as $id) { cp_check($manager->check_log_size($id)===0, 'Actual ACP clears every log'); }
    cp_check($before===10 && $manager->get_counter_value()===$before, 'Actual ACP clear-all preserves existing historical aggregate and current attack total'); $cases++;
    cp_admin('delete_all'); cp_check($manager->get_counter_value()===$before, 'Repeated clear-all is counter-idempotent'); $cases++;
    echo 'CrackerTracker counter policy: ', $cases, " actual rotation/ACP cases passed\n";
} finally {
    restore_error_handler();
    foreach ($counter_files as $name) { $path=$counter_root.'ctracker/logfiles/'.$name; if(is_file($path)){unlink($path);} }
    $cache=$counter_root.'cache/ctracker_counter.cache'; if(is_file($cache)){unlink($cache);}
    rmdir($counter_root.'cache'); rmdir($counter_root.'ctracker/logfiles'); rmdir($counter_root.'ctracker'); rmdir($counter_root);
}
