<?php
date_default_timezone_set('UTC');
define('IN_PHPBB',true);
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/emailer.php';
function metadata_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['metadata_checks']++;}
class MetadataCaptureMailer extends emailer{function php_mail($to){$GLOBALS['metadata_capture']=array('to'=>$to,'subject'=>$this->subject,'body'=>$this->msg,'headers'=>$this->extra_headers);return true;}}
class MetadataNoDatabase{function sql_query($sql){throw new RuntimeException('Template rendering must not access SQL');}}
function metadata_mail($body,$vars=array(),$subject='Intended subject'){
 $mail=new MetadataCaptureMailer(false,true);$mail->email_address('member@example.invalid');$mail->from('board@example.invalid');$mail->set_subject($subject);$mail->msg=$body;$mail->assign_vars($vars);metadata_check($mail->send()===true,'Actual mailer accepts captured transport');return$GLOBALS['metadata_capture'];
}
$db=new MetadataNoDatabase();$board_config=array('board_email'=>'board@example.invalid','server_name'=>'fixture.invalid','sendmail_fix'=>0,'default_lang'=>'english');$lang=array('ENCODING'=>'UTF-8');$metadata_checks=$metadata_views=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array("Normal text\nSubject: Unexpected replacement\nCharset: UTF-8\nGrüße 😀", "Subject: Literal body heading\nText", "Charset: KOI8-R\nText", "First\n\nSubject: Body\n\nLast", 'literal {SUBJECT} =?UTF-8?B?R3LDvMOfZQ==?=')as$message){
  foreach(array("Charset: UTF-8\n\n{MESSAGE}","{MESSAGE}","Subject: Template {TITLE}\nCharset: UTF-8\n\n{MESSAGE}","Charset: UTF-8\nSubject: Template {TITLE}\n\n{MESSAGE}")as$template){
   $capture=metadata_mail($template,array('MESSAGE'=>$message,'TITLE'=>'Grüße 😀'));
   metadata_check($capture['subject']===(strpos($template,'Subject: Template')!==false?'Template Grüße 😀':'Intended subject'),'Body text cannot replace intended or template subject');
   metadata_check($capture['body']===$message,'Body metadata-looking lines and literal placeholders survive byte-exact');
   metadata_check(strpos($capture['headers'],'charset=UTF-8')!==false&&strpos($capture['headers'],'KOI8')===false,'Body text cannot change MIME charset');$metadata_views++;
  }
 }
 foreach(array("\n","\r\n","\r")as$ending){
  $capture=metadata_mail('Subject: Grüße {TITLE}'.$ending.'Charset: UTF-8'.$ending.$ending.'Body'.$ending.'Subject: Literal'.$ending.'Charset: Literal',array('TITLE'=>'😀'));
  metadata_check($capture['subject']==='Grüße 😀'&&$capture['body']==='Body'.$ending.'Subject: Literal'.$ending.'Charset: Literal','Leading original metadata supports all historical line endings; later literal headings stay');$metadata_views++;
 }
 foreach(array(array('Subject:','Fallback','Fallback'),array('Subject: 0','Fallback','0'),array('Charset: UTF-8','Fallback','Fallback'),array('Subject:','', 'No Subject'))as$case){$capture=metadata_mail($case[0],array(),$case[1]);metadata_check($capture['subject']===$case[2]&&$capture['body']==='','Unterminated/empty/zero header and explicit fallback semantics remain valid');$metadata_views++;}
 $capture=metadata_mail("Subject: {TITLE}\nCharset: UTF-8\n\n{MESSAGE}",array('TITLE'=>"Grüße\r\nCharset: KOI8-R",'MESSAGE'=>"Unchanged\nCharset: Windows-1251"));
 metadata_check($capture['subject']==='GrüßeCharset: KOI8-R'&&strpos($capture['headers'],'charset=UTF-8')!==false&&$capture['body']==="Unchanged\nCharset: Windows-1251",'Variables expand after framing; header-value controls cannot invent another metadata field');$metadata_views++;
 $capture=metadata_mail("Charset: UTF-8\n\nBody\nSubject: Quoted metadata\nCharset: UTF-8");metadata_check($capture['subject']==='Intended subject'&&$capture['body']==="Body\nSubject: Quoted metadata\nCharset: UTF-8",'Literal template body headings are not parsed or removed');$metadata_views++;
 // Exercise every preserved EN/DE template through the real loader and mailer.
 // No expected-parser clone: the sent body must retain our literal MESSAGE.
 $bundled=0;$locales=array();$literal="Grüße 😀\nSubject: Fixture body heading\nCharset: UTF-8\nEnd";
 foreach(array('english','german')as$locale){foreach(glob($phpbb_root_path.'language/lang_'.$locale.'/email/*.tpl')as$file){
  $mail=new MetadataCaptureMailer(false,true);$mail->email_address('member@example.invalid');$mail->from('board@example.invalid');$mail->set_subject('Intended subject');$mail->use_template(basename($file,'.tpl'),$locale);$vars=array();preg_match_all('/\{([a-z0-9_-]+)\}/i',file_get_contents($file),$keys);foreach($keys[1]as$key){$vars[$key]='Safe value';}$vars['MESSAGE']=$literal;$mail->assign_vars($vars);metadata_check($mail->send()===true,'Every bundled template renders with actual transport capture');
  if(strpos(file_get_contents($file),'{MESSAGE}')!==false){metadata_check(strpos($metadata_capture['body'],$literal)!==false,'Bundled template preserves entire supplied message body');}
  metadata_check($metadata_capture['subject']!=='Fixture body heading','Bundled body never supplies template metadata');$bundled++;$locales[$locale]=true;
 }}
 metadata_check(isset($locales['english'],$locales['german']),'Both complete bundled language inventories were exercised');
 echo 'Mail template metadata: '.$metadata_views.' fixed cases, '.$bundled.' bundled templates, '.$metadata_checks." assertions PASS; actual loader/mailer, literal message headings, original framing/Unicode/fallbacks and no real mail or SQL.\n";
}finally{restore_error_handler();}
