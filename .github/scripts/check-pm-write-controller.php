<?php
// Execute the actual form identity, SEND/EDIT and response branches. SQL uses
// isolated tables; only the SMTP transport and attachment export are doubles.
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-notifications.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Notification fixture boundary missing'); }
eval(substr($fixture,5,$end-5));
define('GENERAL_MESSAGE',200);
function append_sid($url,$unused=false) { return $url; }
$functions = file_get_contents($root.'includes/functions.php');
$a=strpos($functions,'function phpbb_profile_text('); $b=strpos($functions,'function phpbb_stored_text(', $a);
pm_repair_check($a!==false && $b>$a,'Actual HTML escaping helper');
eval(substr($functions,$a,$b-$a));
$helper = file_get_contents($root.'includes/functions_pm_controller.php');
$a=strpos($helper,'function phpbb_pm_revision('); $b=strpos($helper,'function phpbb_pm_complete_response(', $a);
pm_repair_check($a!==false && $b>$a,'Actual controller helper boundaries');
eval(substr($helper,$a,$b-$a));
// Namespace only the response's transport. The real mailer file may be loaded,
// but cannot be instantiated or send mail by this isolated response fixture.
eval('namespace PmWriteResponseFixture;'.substr($helper,$b));
eval('namespace PmWriteResponseFixture; class emailer {
 function __construct($smtp) { $GLOBALS["pm_mail_vars"]=array(); }
 function __call($name,$arguments) { $GLOBALS["pm_mail_calls"][$name]=$arguments; }
 function assign_vars($vars) { $GLOBALS["pm_mail_vars"]=$vars; }
 function send() { \pm_repair_check($GLOBALS["pm_repair_server"]->owner===null,"No SMTP inside database lock"); $GLOBALS["pm_mail_count"]++; }
}');
function phpbb_pm_complete_response($nonce) { \PmWriteResponseFixture\phpbb_pm_complete_response($nonce); }
class PmWriteDisplayForum extends PmRepairForum
{
	function sql_query($sql)
	{
		pm_repair_check(strpos($sql,'SELECT ')===0,'Unprotected controller connection is read-only');
		return new PmRepairRows($GLOBALS['pm_repair_server']->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
	}
	function sql_fetchrow($r) { return array_shift($r->rows); }
	function sql_fetchrowset($r) { return $r->rows; }
	function sql_affectedrows() { return 0; }
	function sql_freeresult($r) {}
}
class PmWriteTemplate
{
	public $vars=array();
	function assign_vars($vars) { $this->vars=array_merge($this->vars,$vars); }
}
class PmWriteAttachmentExport
{
	public $calls=0;
	function prepared_write_attachments() { $this->calls++; return array(); }
}
$controller = str_replace("\r\n","\n",file_get_contents($root.'privmsg.php'));
$a=strpos($controller,'// Durable write form identity'); $b=strpos($controller,'try { execute_privmsgs_attachment_handling($mode); }',$a);
pm_repair_check($a!==false && $b>$a,'Actual pre-parser form identity branch');
$pm_form_branch=substr($controller,$a,$b-$a);
$a=strpos($controller,"\t\ttry\n\t\t{\n\t\t\t\$privmsg_sent_id = phpbb_pm_write_message(");
$b=strpos($controller,"\n\t}\n\telse if ( \$preview",$a);
pm_repair_check($a!==false && $b>$a,'Actual successful validation SEND/EDIT branch');
$pm_send_branch=substr($controller,$a,$b-$a);
pm_repair_check(strpos($controller,'name="pm_write_nonce"')!==false && strpos($controller,'name="pm_write_revision"')!==false,'Actual form retains nonce and edit revision');

function pm_controller_fixture($engine)
{
	global $db,$board_config,$template,$attachment_mod,$userdata,$pm_mail_count,$pm_mail_calls;
	pm_notification_fixture($engine); $db=new PmWriteDisplayForum(); $template=new PmWriteTemplate();
	$userdata['user_allow_pm']=1;
	$board_config=array('max_inbox_privmsgs'=>0,'script_path'=>'/forum/','cookie_secure'=>1,'server_port'=>443,
		'server_name'=>'forum.example.invalid','smtp_delivery'=>0,'board_email'=>'board@example.invalid',
		'sitename'=>'Testforum','board_email_sig'=>'Grüße<br />Forum');
	$attachment_mod=array('pm'=>new PmWriteAttachmentExport()); $pm_mail_count=0; $pm_mail_calls=array();
}
function pm_controller_form($mode,$id=0,$submit=false)
{
	global $pm_form_branch,$board_config;
	$privmsg_id=$id;
	eval($pm_form_branch);
	return array($pm_write_nonce,$pm_write_revision);
}
function pm_controller_send($mode,$nonce,$id=0,$revision='')
{
	global $pm_form_branch,$pm_send_branch,$board_config,$attachment_mod;
	$privmsg_id=$id; $submit=true;
	$_POST=array('sid'=>'fixture-sid','pm_write_nonce'=>$nonce,'pm_write_revision'=>$revision);
	eval($pm_form_branch);
	$data=pm_write_data("Grüße 'Zitat' \\ Pfad");
	$privmsg_subject=addslashes($data['subject']); $privmsg_message=addslashes($data['text']);
	$bbcode_uid=$data['bbcode_uid']; $bbcode_on=1; $html_on=0; $smilies_on=1; $attach_sig=0;
	$user_ip=$data['ip']; $to_userdata=array('user_id'=>8);
	eval($pm_send_branch);
}
function pm_controller_result($callback,$success)
{
	global $lang;
	$message='';
	try { call_user_func($callback); }
	catch (RuntimeException $error) { $message=$error->getMessage(); }
	pm_repair_check($message!=='' && (strpos($message,$lang['Message_sent'])===0)===$success,'Actual controller response: '.$message);
	pm_repair_check($GLOBALS['pm_repair_server']->owner===null,'Controller always releases writer');
	return $message;
}
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
	{
		foreach (array('english','german') as $locale)
		{
			include $root.'language/lang_'.$locale.'/lang_main.php';
			$nonce=str_repeat('a',32);
			pm_controller_fixture($engine);
			$_SERVER['REQUEST_METHOD']='GET'; $_POST=array();
			$form=pm_controller_form('post'); $other=pm_controller_form('post');
			pm_repair_check(preg_match('/^[a-f0-9]{32}$/D',$form[0])===1 && $form[0]!==$other[0],'Fresh forms have distinct random identities');
			$form=pm_controller_form('edit',12);
			pm_repair_check($form[1]==='','Legacy edit revision is empty');
			$_SERVER['REQUEST_METHOD']='POST';
			pm_controller_result(function() use($nonce) { pm_controller_send('post',$nonce); },true);
			$id=pm_repair_value("SELECT privmsgs_id FROM fixture_pm WHERE privmsgs_write_token='".$nonce."'");
			$row=$pm_repair_server->pdo->query('SELECT p.privmsgs_subject,t.privmsgs_text FROM fixture_pm p,fixture_text t WHERE p.privmsgs_id='.$id.' AND t.privmsgs_text_id=p.privmsgs_id')->fetch(PDO::FETCH_ASSOC);
			$data=pm_write_data("Grüße 'Zitat' \\ Pfad");
			pm_repair_check($row['privmsgs_subject']===$data['subject'] && $row['privmsgs_text']===$data['text'],'Controller passes prepared content without double SQL escaping');
			pm_repair_check($pm_mail_count===1 && $pm_mail_vars['U_INBOX']==='https://forum.example.invalid/forum/privmsg.php?folder=inbox','One notification with correct HTTPS inbox URL');
			pm_controller_result(function() use($nonce) { pm_controller_send('post',$nonce); },true);
			pm_repair_check($attachment_mod['pm']->calls===1 && $pm_mail_count===1,'Repeated accepted form skips attachment export and mail');
			$edit=str_repeat('b',32);
			pm_controller_result(function() use($edit,$id,$nonce) { pm_controller_send('edit',$edit,$id,$nonce); },true);
			pm_repair_check($pm_mail_count===1,'Editing never resends creation mail');
			$_SERVER['REQUEST_METHOD']='GET'; $_POST=array(); $form=pm_controller_form('edit',$id);
			pm_repair_check($form[1]===$edit,'Edit form observes current revision');
			foreach (array('INSERT INTO fixture_pm ','INSERT INTO fixture_text ','UPDATE fixture_pm SET privmsgs_to_userid') as $boundary)
			{
				pm_controller_fixture($engine); $board_config['max_inbox_privmsgs']=4;
				$pm_repair_server->failure=$boundary;
				pm_controller_result(function() use($nonce) { pm_controller_send('post',$nonce); },false);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=10')===1,'Failure before publication cannot evict existing inbox message');
				pm_repair_check($pm_mail_count===0,'Failed publication sends no mail');
				$pm_repair_server->failure='';
				$html=phpbb_pm_pending_writes_html();
				pm_repair_check(($html!=='')===($boundary!=='INSERT INTO fixture_pm '),'Recovery UI lists only accepted pending intent');
				if ($html!=='') { pm_repair_check(strpos($html,'name="sid" value="fixture-sid"')!==false && strpos($html,$nonce)!==false,'Resume form includes session and accepted identity'); }
				$calls=$attachment_mod['pm']->calls;
				pm_controller_result(function() use($nonce) { pm_controller_send('post',$nonce); },true);
				pm_repair_check($attachment_mod['pm']->calls===$calls+($boundary==='INSERT INTO fixture_pm '?1:0),'Accepted retry bypasses re-export, unaccepted retry exports normally');
				pm_repair_check(phpbb_pm_pending_writes_html()==='' && $pm_mail_count===1,'Completed recovery disappears and notifies once');
				pm_repair_check(pm_repair_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===4,'Actual controller quota recount exact');
			}
			pm_controller_fixture($engine); $pm_repair_server->failure='INSERT INTO fixture_text ';
			pm_controller_result(function() use($nonce) { pm_controller_send('post',$nonce); },false);
			$pm_repair_server->failure=''; $userdata['user_id']=8;
			pm_repair_check(phpbb_pm_pending_writes_html()==='','Recipient cannot see sender recovery actions');
			$userdata['user_id']=1; $_POST=array('sid'=>'fixture-sid','pm_write_nonce'=>$nonce,'pm_retry'=>1);
			$_SERVER['REQUEST_METHOD']='GET';
			$message=pm_controller_result(function() { pm_controller_form(''); },false);
			pm_repair_check(strpos($message,phpbb_profile_text($lang['Session_invalid']))===0,'GET cannot resume accepted write');
			$_SERVER['REQUEST_METHOD']='POST';
			pm_controller_result(function() { pm_controller_form(''); },true);
			pm_repair_check($pm_mail_count===1,'Explicit POST recovery completes and notifies');
			echo $engine.' '.$locale." actual PM compose/send/edit/retry controller checks passed.\n";
		}
	}
}
finally { if (isset($pm_repair_server) && $pm_repair_server->owner!==null) { $pm_repair_server->owner->sql_close(); } restore_error_handler(); }
