<?php
namespace ContactWorkflow;
// Actual controller and real mailer/XS; final rate decision/transport sinks.
// Canonical native rate persistence is covered by contact-rate-native-fixture.
if (!in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true)) { throw new \RuntimeException('Real mail must be disabled'); }
if (!defined('IN_PHPBB')) { define('IN_PHPBB',true); }
$cp_source=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $cp_source.'includes/php_compat.php';require_once $cp_source.'includes/functions.php';require_once $cp_source.'includes/sessions.php';require_once $cp_source.'includes/template.php';
function cp_check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
class ContactMailExit extends \RuntimeException {}
class emailer extends \emailer {
	function php_mail($to){$GLOBALS['cp_sent'][]=array('to'=>$to,'reply'=>$this->reply_to,'subject'=>$this->subject,'body'=>$this->msg,'headers'=>$this->extra_headers);return$GLOBALS['cp_transport'];}
	function fail($message){throw new ContactMailExit($message);}
}
class ContactTemplate {
	var $view;var $data=array();
	function __construct(){global$cp_source;$this->view=(new \ReflectionClass('Template'))->newInstanceWithoutConstructor();$this->view->vars=&$this->view->_tpldata['.'][0];$this->view->load_config($cp_source.'templates/fisubsilversh',false);}
	function assign_vars($values){$this->data=array_merge($this->data,$values);$this->view->assign_vars($values);}
	function set_filenames($values){$this->view->set_filenames($values);}
	function pparse($handle){ob_start();try{$this->view->pparse($handle);$GLOBALS['cp_html']=ob_get_contents();}finally{ob_end_clean();}}
}
function ctracker_rate_limit_contact($directory,$identity,$interval){
	cp_check($directory===$GLOBALS['cp_dir'].'/cache'&&$identity==='7f000001'&&$interval===60,'Actual controller passes scoped service arguments');
	$GLOBALS['cp_reads']++;if($GLOBALS['cp_fault']!==''||$GLOBALS['cp_claimed']){return false;}$GLOBALS['cp_claimed']=true;return true;
}
$source=file_get_contents($cp_source.'kontakt_post.php');$start=strpos($source,'function kontakt_claim_rate_limit(');$split=strpos($source,"\$page_title = \$lang['Kontakt'];",$start);
cp_check($start!==false&&$split!==false,'Actual contact helper/controller boundaries');eval('namespace ContactWorkflow;'.substr($source,$start,$split-$start));$cp_code=substr($source,$split);$cp_code=preg_replace('/\?>\s*$/','',$cp_code);
foreach(array('page_header','page_tail','emailer')as$file){$include="include(\$phpbb_root_path . 'includes/$file.' . \$phpEx);";cp_check(substr_count($cp_code,$include)===1,'Actual include boundary: '.$file);$cp_code=str_replace($include,'',$cp_code);}
$cp_form=file_get_contents($cp_source.'kontakt.php');$start=strpos($cp_form,"\$page_title = \$lang['Kontakt'];");cp_check($start!==false,'Actual form view boundary');$cp_form=substr($cp_form,$start);$cp_form=preg_replace('/\?>\s*$/','',$cp_form);foreach(array('page_header','page_tail')as$file){$cp_form=str_replace("include(\$phpbb_root_path . 'includes/$file.'.\$phpEx);",'',$cp_form);}
$cp_dir=false;for($attempt=0;$attempt<100;$attempt++){$candidate=sys_get_temp_dir().'/phpbb-contact-workflow-'.getmypid().'-'.$attempt;if(@mkdir($candidate,0700)){$cp_dir=$candidate;break;}}cp_check($cp_dir!==false&&mkdir($cp_dir.'/cache',0700),'Fresh owned contact environment');
function cp_reset_cache(){$GLOBALS['cp_claimed']=false;}
function cp_run($locale,$changes=array(),$keep=false,$fault='',$transport=true,$settings=array()){
	global$cp_dir,$cp_code,$cp_fault,$cp_reads,$cp_sent,$cp_transport,$cp_html,$phpbb_root_path,$phpEx,$board_config,$plus_config,$userdata,$user_ip,$lang,$template,$theme,$SID;
	if(!$keep){cp_reset_cache();}$cp_fault=$fault;$cp_reads=0;$cp_sent=array();$cp_transport=$transport;$cp_html='';
	$phpbb_root_path=$cp_dir.'/';$phpEx='php';$SID='sid='.str_repeat('a',32);$board_config=array_merge(array('board_email'=>'board@example.invalid','server_name'=>'forum.example.invalid','default_lang'=>$locale,'smtp_delivery'=>0,'sendmail_fix'=>0,'flood_interval'=>0,'xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0),$settings);
	$plus_config=array('contact_email'=>isset($settings['contact_email'])?$settings['contact_email']:'contact@example.invalid');$user_ip='7f000001';$userdata=array('session_id'=>str_repeat('a',32),'session_logged_in'=>false,'user_id'=>-1);
	$lang=array();require$GLOBALS['cp_source'].'language/lang_'.$locale.'/lang_main.php';$theme=array('template_name'=>'fisubsilversh');$template=new ContactTemplate();
	$raw=array_merge(array('sid'=>str_repeat('a',32),'website'=>'','name'=>'Synthetic Name','mail'=>'reply@example.invalid','betreff'=>'Original submitted subject','textfeld'=>'Synthetic body'),$changes);$method=isset($raw['_method'])?$raw['_method']:'POST';unset($raw['_method']);$_SERVER['REQUEST_METHOD']=$method;$_POST=\phpbb_addslashes_recursive($raw);
	$exit='';try{eval('namespace ContactWorkflow;'.$cp_code);}catch(ContactMailExit$error){$exit=$error->getMessage();}
	return array('mail'=>$cp_sent,'html'=>$cp_html,'values'=>$template->data,'exit'=>$exit,'raw'=>$raw,'rate'=>$cp_reads);
}
$cp_cases=0;$cp_forms=0;
try{
	foreach(array('english','german')as$locale){
		foreach(array('Grüße 😀 C:\\Archiv\\Datei {literal}',"Before\nSubject: archived message\nAfter","Charset: windows-1251\nKeep this line",'{CONTACT_BODY} {CONTACT_SUBJECT} {unknown} {}',"Subject: x\r\nCharset: UTF-16\r\n\r\nQuoted","Subject: Grüße {CONTACT_BODY} \\ Subject: literal\nCharset: UTF-8\n\nKeep the exact repeated metadata",str_repeat('a',10000))as$body){
			$r=cp_run($locale,array('textfeld'=>$body,'betreff'=>'Grüße {CONTACT_BODY} \\ Subject: literal','name'=>'Name {unknown}'));
			cp_check(count($r['mail'])===1&&$r['mail'][0]['subject']===$r['raw']['betreff'],'Preserve submitted subject and literal braces');
			$expected="Name: ".$r['raw']['name']."\nEmail: reply@example.invalid\nIP: 127.0.0.1\n\n".trim($body);
			cp_check($r['mail'][0]['body']===$expected,'Preserve actual complete body bytes and header-looking lines');cp_check($r['values']['true']!==''&&strpos($r['html'],$r['values']['true'])!==false,'Actual XS success message');$cp_cases++;
		}
		$r=cp_run($locale,array('name'=>str_repeat('n',100),'betreff'=>str_repeat('s',150)));cp_check(count($r['mail'])===1&&strlen($r['mail'][0]['subject'])===150,'Accepted exact name/subject bounds');$cp_cases++;
		$r=cp_run($locale,array(),false,'',true,array('contact_email'=>'invalid'));cp_check($r['mail'][0]['to']==='board@example.invalid','Configured destination fallback');$cp_cases++;
		$r=cp_run($locale,array('name'=>"A\r\nB",'betreff'=>"A\r\nB"));cp_check($r['mail'][0]['subject']==='A B'&&strpos($r['mail'][0]['body'],"Name: A B\n")===0&&$r['mail'][0]['reply']==='reply@example.invalid','Control sanitization and reply address');$cp_cases++;
		$bad=array(array('_method'=>'GET'),array('sid'=>'wrong'),array('sid'=>''),array('sid'=>array('nested')),array('website'=>'bot'),array('website'=>array('nested')),array('name'=>''),array('name'=>array()),array('name'=>str_repeat('n',101)),array('mail'=>'invalid'),array('mail'=>array()),array('betreff'=>''),array('betreff'=>array()),array('betreff'=>str_repeat('s',151)),array('textfeld'=>''),array('textfeld'=>array()),array('textfeld'=>str_repeat('a',10001)));
		foreach($bad as$input){$r=cp_run($locale,$input);cp_check(!$r['mail']&&!$r['rate']&&$r['values']['false']!==''&&$r['values']['true']==='','Invalid form does not claim quota or send');$cp_cases++;}
		$r=cp_run($locale,array(),false,'',true,array('contact_email'=>'invalid','board_email'=>'invalid'));cp_check(!$r['mail']&&!$r['rate']&&$r['values']['false']!=='','Invalid configured recipients');$cp_cases++;
		foreach(array('unavailable','busy','legacy-storage','unconfirmed','readback','legacy-read','legacy-lock','legacy-overflow')as$fault){$r=cp_run($locale,array(),false,$fault);cp_check(!$r['mail']&&$r['values']['false']===htmlspecialchars($lang['Flood_email_limit'],ENT_QUOTES,'UTF-8'),'No mail without acknowledged service decision: '.$fault);$cp_cases++;}
		$r=cp_run($locale);cp_check(count($r['mail'])===1,'First acknowledged service claim');$cp_cases++;$r=cp_run($locale,array(),true);cp_check(!$r['mail']&&$r['values']['false']!==''&&$r['values']['true']==='','Denied repeat has no delivery');$cp_cases++;
		$r=cp_run($locale,array(),false,'',false);cp_check(count($r['mail'])===1&&$r['exit']!==''&&(!isset($r['values']['true'])||$r['values']['true']===''),'Actual mailer failure never reports success');$cp_cases++;
		// Render the real form with its real SID/honeypot fields; bootstrap is
		// already represented by the synthetic current guest above.
		$template=new ContactTemplate();ob_start();try{eval('namespace ContactWorkflow;'.$cp_form);}finally{ob_end_clean();}cp_check(strpos($cp_html,'name="sid" value="'.str_repeat('a',32).'"')!==false&&strpos($cp_html,'name="website"')!==false,'Actual XS form token/honeypot');$cp_forms++;
	}
}finally{cp_reset_cache();rmdir($cp_dir.'/cache');rmdir($cp_dir);}
echo 'Contact workflow: '.$cp_cases.' actual controller/mailer/decision cases, '.$cp_forms." EN/DE XS forms PASS; literal content, headers, bounds, SID/honeypot, acknowledged decision and transport failure; real mails0.\n";
