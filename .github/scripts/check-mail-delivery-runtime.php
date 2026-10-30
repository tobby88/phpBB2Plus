<?php
namespace MailDeliveryFixture;
date_default_timezone_set('UTC');
// Execute the real mailer and SMTP protocol; substitute only native transport,
// timing and cache/log side effects. No sockets, mail or production config.
class LegacyMailFailure extends \RuntimeException {}
function message_die($type,$message) { throw new LegacyMailFailure($message); }
function mail_check($ok,$message) { if(!$ok) { throw new \RuntimeException($message); } }
function phpbb_random_bytes($length) { return str_repeat('x',$length); }
function microtime($as_float=false) { $GLOBALS['clock_tick']+=$GLOBALS['clock_step']; return $GLOBALS['clock_tick']; }
function fsockopen($host,$port,&$errno,&$errstr,$timeout)
{
	mail_check($host==='fixture.invalid' && $port===25 && $timeout===20,'Bounded expected fake connection');
	if($GLOBALS['connect_fail']) { $errno=111; $errstr='FIXTURE_SECRET_CONNECT'; return false; }
	$socket=\fopen('php://temp','w+'); \fwrite($socket,$GLOBALS['smtp_replies']); \rewind($socket);
	$GLOBALS['smtp_socket']=$socket; return $socket;
}
function stream_set_timeout($socket,$seconds) { mail_check($seconds>0 && $seconds<=20,'Per-command timeout bounded'); return true; }
function stream_get_meta_data($socket) { return array('timed_out'=>$GLOBALS['read_timeout']); }
function fwrite($socket,$data)
{
	if($data==="QUIT\r\n" && $GLOBALS['quit_fail']) { return false; }
	if($GLOBALS['write_fail']) { return $GLOBALS['write_fail']==='zero'?0:false; }
	$length=min(strlen($data),$GLOBALS['write_chunk']); $GLOBALS['smtp_written'].=substr($data,0,$length); return $length;
}
function fclose($socket) { $GLOBALS['closed_sockets']++; return \fclose($socket); }
function mail($to,$subject,$message,$headers)
{
	$GLOBALS['delivery_events'][]='mail'; $GLOBALS['mail_calls'][]=array($to,$subject,$message,$headers);
	$result=array_shift($GLOBALS['mail_results']);
	if($result==='throw') { throw new \RuntimeException('FIXTURE_SECRET_NATIVE_MAIL'); }
	return $result;
}
function unlink($path) { $GLOBALS['cache_unlinks'][]=$path; return true; }
function error_log($message) { $GLOBALS['mail_logs'][]=$message; return true; }
class MailDb
{
	function sql_query($sql) { $GLOBALS['delivery_events'][]='sql'; return $GLOBALS['save_config']; }
}
$root=dirname(dirname(__DIR__)).'/phpBB2/';
define('GENERAL_ERROR',1); define('CONFIG_TABLE','fixture_config');
foreach(array('smtp.php','emailer.php') as $file)
{
	$source=file_get_contents($root.'includes/'.$file);
	$source=str_replace('__DIR__',var_export($root.'includes',true),$source);
	eval('namespace MailDeliveryFixture; use \\RuntimeException;'.substr($source,5));
}
function mail_fixture($replies="220 Ready\r\n250 Hello\r\n250 Sender\r\n250 Recipient\r\n354 Data\r\n250 Accepted\r\n")
{
	global $board_config,$lang,$phpbb_root_path,$phpEx,$db;
	$board_config=array('smtp_host'=>'fixture.invalid','smtp_username'=>'','smtp_password'=>'','board_email'=>'forum@example.invalid','server_name'=>'fixture.invalid','sendmail_fix'=>0,'default_lang'=>'english');
	$lang=array('ENCODING'=>'UTF-8'); $phpbb_root_path=$GLOBALS['root']; $phpEx='php'; $db=new MailDb();
	foreach(array('clock_tick'=>0,'clock_step'=>0,'connect_fail'=>false,'smtp_replies'=>$replies,'smtp_socket'=>null,'read_timeout'=>false,'write_chunk'=>3,'write_fail'=>false,'quit_fail'=>false,'smtp_written'=>'','closed_sockets'=>0,'delivery_events'=>array(),'mail_calls'=>array(),'mail_results'=>array(false),'cache_unlinks'=>array(),'mail_logs'=>array(),'save_config'=>true) as $name=>$value) { $GLOBALS[$name]=$value; }
}
function expected_failure($callback,$class,$contains)
{
	$caught=false;
	try { call_user_func($callback); } catch(\Exception $error)
	{
		$caught=$error instanceof $class && ($contains === '' || strpos($error->getMessage(),$contains)!==false);
		mail_check(strpos($error->getMessage(),'FIXTURE_SECRET')===false && strpos($error->getMessage(),'<script>')===false,'Errors never echo remote text or secrets');
	}
	mail_check($caught,'Expected controlled failure: '.$contains);
}
function decoded_header($headers,$field)
{
 $headers=preg_replace('/\r\n[ \t]+/',' ',$headers);$value=null;
 foreach(preg_split('/\r\n|\r|\n/',$headers)as$line){if(stripos($line,$field.': ')===0){$value=substr($line,strlen($field)+2);break;}}
 mail_check($value!==null,'Actual wire header present');
 if(strpos($value,'=?UTF-8?B?')!==0){return$value;}
 preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/',$value,$words);$decoded='';
 foreach($words[1]as$word){$bytes=base64_decode($word,true);mail_check($bytes!==false&&preg_match('//u',$bytes)===1,'Actual wire words independently decode as UTF-8');$decoded.=$bytes;}
 return$decoded;
}
function fixture_mailer($smtp=false,$optional=false)
{
	$mailer=new emailer($smtp,$optional);
	$mailer->email_address('reader@example.invalid'); $mailer->from('forum@example.invalid');
	$mailer->msg="Subject: Fixture {TITLE}\nCharset: UTF-8\n\nGrüße 😀\n.\nEnd";
	$mailer->assign_vars(array('TITLE'=>'title')); return $mailer;
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new \RuntimeException($message); } });
try
{
	mail_fixture();
	mail_check(smtpmail('reader@example.invalid','Subject',"Grüße\n.\nEnd",'',true)===true,'Complete successful SMTP exchange');
	mail_check(strpos($smtp_written,"MAIL FROM: <forum@example.invalid>\r\nRCPT TO: <reader@example.invalid>\r\nDATA\r\n")!==false,'Short writes assemble complete ordered commands');
	mail_check(strpos($smtp_written,"Grüße\r\n..\r\nEnd\r\n.\r\n")!==false,'Complete DATA payload is dot-stuffed');
	mail_check(!is_resource($smtp_socket) && $closed_sockets===1,'Successful delivery closes socket');
	mail_fixture("220\r\n250-first\r\n250\r\n250 OK\r\n251 Forwarded\r\n354\r\n250\r\n");
	$quit_fail=true; mail_check(smtpmail('reader@example.invalid','Subject','Body','',true)===true,'Bare/multiline replies and RCPT251 accepted; failed QUIT does not undo acceptance');
	mail_fixture("220 Ready\r\n250 Hello\r\n250 Sender\r\n250 Recipient\r\n250 CC\r\n250 BCC\r\n354 Data\r\n250 Accepted\r\n");
	mail_check(smtpmail('reader@example.invalid','Subject','Body',"Cc: copy@example.invalid\r\nBcc: hidden@example.invalid,\r\n hidden@example.invalid",true)===true,'Folded BCC and duplicate recipient accepted once');
	$data=substr($smtp_written,strpos($smtp_written,"DATA\r\n")+6);
	mail_check(strpos($data,'hidden@example.invalid')===false && substr_count($smtp_written,'RCPT TO: <hidden@example.invalid>')===1,'BCC stays out of transmitted headers/body and envelope deduplicates');
	foreach(array("550 FIXTURE_SECRET <script>\r\n", "220-first\r\n250 Last\r\n", "220 ".str_repeat('x',510)."\r\n", "220 incomplete", str_repeat("220-more\r\n",100)) as $reply)
	{
		mail_fixture($reply); expected_failure(function() { smtpmail('reader@example.invalid','Subject','Body','',true); },__NAMESPACE__.'\\PhpbbSmtpException','');
		mail_check(!is_resource($smtp_socket) && $closed_sockets===1,'Failure always closes acquired socket');
	}
	mail_fixture(''); $read_timeout=true;
	expected_failure(function() { smtpmail('reader@example.invalid','Subject','Body','',true); },__NAMESPACE__.'\\PhpbbSmtpException','timed out');
	mail_fixture("220-more\r\n220 Done\r\n"); $clock_step=11;
	expected_failure(function() { smtpmail('reader@example.invalid','Subject','Body','',true); },__NAMESPACE__.'\\PhpbbSmtpException','timed out');
	foreach(array('zero','false') as $failure)
	{
		mail_fixture(); $write_fail=$failure;
		expected_failure(function() { smtpmail('reader@example.invalid','Subject','Body','',true); },__NAMESPACE__.'\\PhpbbSmtpException','write complete');
		mail_check($closed_sockets===1,'Failed write closes socket');
	}
	mail_fixture(); $connect_fail=true;
	expected_failure(function() { smtpmail('reader@example.invalid','Subject','Body'); },__NAMESPACE__.'\\LegacyMailFailure','Could not connect');
	mail_fixture("220 Ready\r\n250-capability\r\n250 OK\r\n334 User\r\n334 Password\r\n535 FIXTURE_SECRET_AUTH\r\n");
	$board_config['smtp_username']='fixture-login'; $board_config['smtp_password']='fixture-password';
	expected_failure(function() { smtpmail('reader@example.invalid','Subject','Body','',true); },__NAMESPACE__.'\\PhpbbSmtpException','SMTP 535');
	mail_check(strpos($smtp_written,"AUTH LOGIN\r\n")!==false && !is_resource($smtp_socket),'AUTH failure releases socket without leaking challenge/credentials');

	foreach(array(false,true) as $optional)
	{
		$class=__NAMESPACE__.($optional?'\\PhpbbMailException':'\\LegacyMailFailure');
		mail_fixture(); $mailer=fixture_mailer(false,$optional);
		expected_failure(function() use($mailer) { $mailer->send(); },$class,'Failed sending email :: PHP');
		mail_fixture(); $mail_results=array('throw'); $mailer=fixture_mailer(false,$optional);
		expected_failure(function() use($mailer) { $mailer->send(); },$class,'Failed sending email :: PHP');
		mail_fixture("550 FIXTURE_SECRET_SERVER\r\n"); $mailer=fixture_mailer(true,$optional);
		expected_failure(function() use($mailer) { $mailer->send(); },$class,'SMTP 550');
		mail_check(!is_resource($smtp_socket),'Mailer error boundary does not retain SMTP socket');
		mail_fixture(); $mailer=fixture_mailer(false,$optional);
		expected_failure(function() use($mailer) { $mailer->use_template('../invalid'); },$class,'No template file');
		expected_failure(function() use($mailer) { $mailer->use_template('fixture_absent_template','english'); },$class,'Could not find email template');
		mail_check($mailer->msg==='','Failed template load cannot reuse prior message');
		$mailer->email_address('invalid');
		expected_failure(function() use($mailer) { $mailer->send(); },$class,'Invalid email sender or recipient');
	}
	mail_fixture(); $mail_results=array(true); $mailer=fixture_mailer(false,true);
	mail_check($mailer->send()===true && strpos(quoted_printable_decode($mail_calls[0][2]),'Grüße 😀')!==false,'Optional successful PHP mail still transmits Unicode');
	mail_fixture(); $mailer=fixture_mailer(true,true); mail_check($mailer->send()===true,'Actual mailer to actual SMTP protocol success');
	foreach(array(str_repeat('Änne 😀 ',30),'=?UTF-8?B?R3LDvMOfZQ==?=',str_repeat('long ASCII ',30))as$title){foreach(array(false,true)as$smtp){
	 mail_fixture();$mail_results=array(true);$mailer=fixture_mailer($smtp,true);$mailer->assign_vars(array('TITLE'=>$title));$mailer->extra_headers('X-AntiAbuse: Username - '.$title);
	 mail_check($mailer->send()===true,'Actual Unicode MIME delivery accepted');
	 if($smtp){$start=strpos($smtp_written,"DATA\r\n")+6;$data=substr($smtp_written,$start,strpos($smtp_written,"\r\n.\r\n",$start)-$start);$parts=explode("\r\n\r\n",$data,2);$headers=$parts[0];$body=preg_replace('/^\.\./m','.',$parts[1]);}
	 else{$headers='Subject: '.$mail_calls[0][1]."\r\n".$mail_calls[0][3];$body=$mail_calls[0][2];}
	 mail_check(decoded_header($headers,'Subject')==='Fixture '.rtrim($title),'Actual PHP/SMTP subject remains exact Unicode/literal encoded-word text after existing outer trim');
	 mail_check(decoded_header($headers,'X-AntiAbuse')==='Username - '.rtrim($title),'Actual PHP/SMTP diagnostic value remains exact after existing outer trim');
	 mail_check(stripos($headers,'Content-transfer-encoding: quoted-printable')!==false&&quoted_printable_decode($body)==="Grüße 😀\r\n.\r\nEnd",'Actual MIME body and transfer declaration agree, including SMTP dot stuffing');
	 mail_check(!preg_match('/[^\x09\x0a\x0d\x20-\x7e]/',$headers.$body),'Actual generated wire message is seven-bit safe');
	 foreach(explode("\r\n",$headers)as$line){if(strpos($line,'=?UTF-8?B?')!==false){mail_check(strlen($line)<=76,'Actual encoded header lines satisfy MIME bound');}}
	}}
	mail_fixture(); $mail_results=array(false,true); $mailer=fixture_mailer(); $mailer->email_address(''); $mailer->bcc('hidden@example.invalid');
	mail_check($mailer->send()===true && $delivery_events===array('mail','mail','sql'),'Persist sendmail fallback only after accepted retry');
	mail_fixture(); $mail_results=array(false,false); $mailer=fixture_mailer(false,true); $mailer->email_address(''); $mailer->bcc('hidden@example.invalid');
	expected_failure(function() use($mailer) { $mailer->send(); },__NAMESPACE__.'\\PhpbbMailException','Failed sending email');
	mail_check($delivery_events===array('mail','mail') && !$cache_unlinks,'Failed fallback changes no configuration/cache');
	mail_fixture(); $save_config=false; $mail_results=array(false,true); $mailer=fixture_mailer(); $mailer->email_address(''); $mailer->bcc('hidden@example.invalid');
	mail_check($mailer->send()===true && count($mail_logs)===1,'Config persistence failure does not negate already accepted delivery');
	echo "Mail optional/mandatory failure boundaries, SMTP protocol, short writes and cleanup checks passed.\n";
}
finally { if(is_resource($smtp_socket)) { \fclose($smtp_socket); } restore_error_handler(); }
