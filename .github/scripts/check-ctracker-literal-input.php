<?php
// Execute the real early engine and actual common/login/password helpers.
// All inputs are disposable literals; no database, HTTP, logs or mail are used.
$root=dirname(dirname(__DIR__)).'/phpBB2/';
function ctl_cases(){return array(
 array('HIGH',array(),array('password'=>'Fixture%00Phrase!9'),200,false),
 array('HIGH',array(),array('message'=>'Erklärung zu %00 und %2500 😀'),200,false),
 array('MEDIUM',array(),array('poll_option_text'=>array('Wörtlich %00')),200,false),
 array('HIGH',array(),array('password'=>"Fixture\0Phrase!9"),403,false),
 array('LOW',array('id'=>'%00'),array(),403,false),
 array('MEDIUM',array(),array('message'=>'Wörtlich %00 encoded%2Dsentinel'),403,false),
 array('HIGH',array(),array('message'=>'Wörtlich %00'),403,true),
 array('HIGH',array('GLOBALS'=>'plain'),array(),403,false),
 array('LOW',array(),array('password'=>'Fixture%00Phrase!9'),200,false),
 array('LOW',array(),array('message'=>"literal\0content"),403,false),
 array('MEDIUM',array(),array('message'=>'Wörtlich %00'),403,false,array('%00')));}
if(isset($argv[1])&&$argv[1]==='worker'){
 $cases=ctl_cases();$index=isset($argv[2])?$argv[2]:'';if(!preg_match('/^[0-9]+$/D',$index)||!isset($cases[(int)$index])){exit(2);}$case=$cases[(int)$index];
 define('IN_PHPBB',true);define('CT_DEBUG_MODE',true);define('CT_SECLEVEL',$case[0]);$phpbb_root_path=$root;$phpEx='php';
 parse_str(http_build_query($case[1],'','&'),$HTTP_GET_VARS);parse_str(http_build_query($case[2],'','&'),$HTTP_POST_VARS);$HTTP_COOKIE_VARS=$HTTP_POST_FILES=array();
 $HTTP_SERVER_VARS=array('REQUEST_METHOD'=>'POST','HTTP_HOST'=>'forum.example.invalid','HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_ORIGIN'=>$case[4]?'https://other.example.invalid':'https://forum.example.invalid');$ct_addheuristic=isset($case[5])?$case[5]:array('encoded-sentinel');$before=serialize(array($HTTP_GET_VARS,$HTTP_POST_VARS));
 ob_start();register_shutdown_function(function()use($before){$body=ob_get_clean();echo json_encode(array('status'=>http_response_code()?:200,'pass'=>$body==='engine-passed','unchanged'=>$before===serialize(array($GLOBALS['HTTP_GET_VARS'],$GLOBALS['HTTP_POST_VARS'])),'logs_loaded'=>class_exists('log_manager',false),'body'=>$body));});
 require$root.'ctracker/engines/ct_security.php';echo 'engine-passed';return;
}
define('IN_PHPBB',true);define('CTRACKER_SECURITY_NO_AUTO_RUN',true);require$root.'ctracker/engines/ct_security.php';require$root.'includes/php_compat.php';require __DIR__.'/profile-request-fixture.php';
$checks=0;function ctl_check($ok,$message){$GLOBALS['checks']++;if(!$ok){throw new RuntimeException($message);}}
$options=array('post_free_text'=>ct_security_free_post_fields(),'get_free_text'=>array('search_keywords'),'scan_post'=>true);
foreach(ct_security_free_post_fields()as$field){foreach(array('literal%00tail','literal%2500tail','literal%252500tail','Grüße 😀 %00')as$value){parse_str(http_build_query(array($field=>$value),'','&'),$post);$before=serialize($post);ctl_check(!ct_security_request_is_attack(array(),$post,array(),$options),'Literal form content allowed: '.$field);ctl_check(serialize($post)===$before,'Original form bytes preserved');ctl_check(ct_security_request_is_attack(array(),array($field=>"actual\0byte"),array(),$options),'Actual NUL stays blocked: '.$field);}}
foreach(array('%00','%2500','%252500',"actual\0byte")as$value){ctl_check(ct_security_request_is_attack(array('id'=>$value),array(),array(),$options),'Technical encoded/actual NUL still blocked');}
ctl_check(!ct_security_request_is_attack(array(),array('poll_option_text'=>array('Grüße %00',array('Wörtlich %2500'))),array(),$options),'Nested free-text arrays preserve policy');
ctl_check(!ct_security_request_is_attack(array('search_keywords'=>'Guide %00'),array(),array(),$options),'Free-text search preserves literal spelling');
$custom=$options;$custom['custom_rules']=array('encoded-sentinel');ctl_check(ct_security_request_is_attack(array(),array('message'=>'%00 encoded%2Dsentinel'),array(),$custom),'Normalized custom rules still apply to free text');
$custom['custom_rules']=array('%00');ctl_check(ct_security_request_is_attack(array(),array('message'=>'Literal %00'),array(),$custom),'Explicit literal percent rule still applies');
$custom['custom_rules']=array('&amp;');ctl_check(ct_security_request_is_attack(array(),array('message'=>'Literal &amp;'),array(),$custom),'Literal entity rules are not lost during normalization');
ctl_check(ct_security_request_is_attack(array(),array('poll_option_text'=>array('GLOBALS'=>'%00')),array(),$options),'Nested reserved keys still blocked');
$login=file_get_contents($root.'login.php');$a=strpos($login,'$password_value =');$b=strpos($login,'$row = phpbb_username_lookup(',$a);ctl_check($a!==false&&$b>$a,'Actual credential input located');$input=substr($login,$a,$b-$a);
foreach(array(0,1)as$hashing){$board_config=array('password_hashing'=>$hashing);foreach(array('Fixture%00Phrase!9','Grüße%2500Phrase!9')as$original){parse_str(http_build_query(array('password'=>$original),'','&'),$post);ctl_check(!ct_security_request_is_attack(array(),$post,array(),$options),'WAF admits exact credential');profile_fixture_request($post);eval($input);$expected=addslashes($original);$stored=phpbb_password_hash($expected);ctl_check(is_string($stored)&&$password===$expected&&phpbb_password_verify($password,$stored),'Actual form/bootstrap/login/hash round trip');ctl_check(!phpbb_password_verify(str_replace(array('%00','%2500'),"\0",$password),$stored),'Actual NUL is never a credential alias');}}
foreach(ctl_cases()as$index=>$case){
 $command=escapeshellarg(PHP_BINARY).' -d disable_functions=mail -d date.timezone=UTC '.escapeshellarg(__FILE__).' worker '.(int)$index;$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));ctl_check(is_resource($process),'Own engine worker');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out=$err='';$deadline=microtime(true)+10;$exit=null;
 try{do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$state=proc_get_status($process);if(!$state['running']){$exit=$state['exitcode'];break;}ctl_check(microtime(true)<$deadline&&strlen($out)+strlen($err)<65536,'Bounded actual engine worker');usleep(10000);}while(true);$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);$process=null;$value=json_decode($out,true);ctl_check(($exit>=0?$exit:$closed)===0&&$err===''&&is_array($value),'Actual engine completed cleanly (case '.$index.', exit '.(int)$exit.', stderr '.substr($err,0,500).')');ctl_check($value['status']===$case[3]&&$value['pass']===($case[3]===200)&&$value['unchanged']&&!$value['logs_loaded'],'Actual HIGH/MEDIUM/LOW engine response and untouched inputs');}finally{if(is_resource($process)){proc_terminate($process);foreach($pipes as$pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($process);}}
}
echo "CrackerTracker literal input: all free-text fields, exact form/common/login MD5+bcrypt round trips and 11 actual engine responses PASS; raw NUL, technical signatures, custom rules, reserved keys and cross-site checks retained; no database/log/mail writes.\n";
