<?php
namespace KbWriteNotificationFixture;
use RuntimeException;
define('IN_PHPBB',true);
function wn_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function phpbb_board_url($path){return 'https://forum.example.invalid/'.$path;}
// Dispatch/transport unit checks only. The native suite exercises the actual
// independent read owner, canonical tables and current notification context.
function kb_notification_ready($database,$outcome_id){
 $GLOBALS['wn_calls'][]='ready';if(!empty($GLOBALS['wn_ready_error'])){throw new RuntimeException('Synthetic readiness failure');}
 if(!empty($GLOBALS['wn_ready_skip'])){return false;}
 return array('article'=>array('article_title'=>$GLOBALS['outcome']['article_title']),'settings'=>$GLOBALS['board_config']);
}
class emailer {
 function __construct($smtp,$optional){wn_check($optional===true,'Optional postcommit notification');$GLOBALS['wn_calls'][]='new';if($GLOBALS['wn_failure']==='construct'){throw new RuntimeException('private transport details');}}
 function from($value){$GLOBALS['wn_from']=$value;}
 function replyto($value){$GLOBALS['wn_reply']=$value;}
 function email_address($value){$GLOBALS['wn_to']=$value;}
 function set_subject($value){$GLOBALS['wn_subject']=$value;}
 function use_template($value,$language){$GLOBALS['wn_template']=$value;$GLOBALS['wn_language']=$language;if($GLOBALS['wn_failure']==='template'){throw new RuntimeException('private template details');}}
 function assign_vars($value){$GLOBALS['wn_vars']=$value;}
 function send(){$GLOBALS['wn_calls'][]='send';if($GLOBALS['wn_failure']==='exception'){throw new RuntimeException('private send details');}return $GLOBALS['wn_failure']!=='refused';}
}
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
$source=file_get_contents($phpbb_root_path.'includes/functions_kb_write_notifications.php');
wn_check(preg_match('/^function kb_notify_after_write\(.*?^\}/ms',$source,$body)===1,'Unchanged actual dispatch body');
eval('namespace KbWriteNotificationFixture; use Exception; use Error; '.$body[0]);
$board_config=array('board_email'=>'board@example.invalid','smtp_delivery'=>0,'sitename'=>'Synthetic board','default_lang'=>'german','board_email_sig'=>'Signature');$db=null;
$lang=array('New_article'=>'Neuer Artikel','Email_body'=>'Neuer Artikel wartet auf Prüfung.');
$outcome=array('article_id'=>1,'notify_email'=>true,'article_title'=>"Größe 😀 O'Reilly");
foreach(array('','construct','template','exception','refused')as$failure){
 $GLOBALS['wn_failure']=$failure;$GLOBALS['wn_calls']=array();
 wn_check(kb_notify_after_write($outcome)===($failure===''),'Notification transport result cannot undo the article');
 if($failure===''){wn_check($GLOBALS['wn_to']==='board@example.invalid'&&$GLOBALS['wn_template']==='kb_pending_article'&&$GLOBALS['wn_language']==='german'&&$GLOBALS['wn_vars']['ARTICLE_TITLE']===$outcome['article_title']&&$GLOBALS['wn_vars']['U_REVIEW']==='https://forum.example.invalid/admin/admin_kb_art.php','Exact current article metadata, board language and fresh ACP entry link');}
}
$GLOBALS['wn_calls']=array();$outcome['notify_email']=false;
wn_check(kb_notify_after_write($outcome)&&!$GLOBALS['wn_calls'],'Disabled notification never constructs a transport');
$outcome['notify_email']=true;$board_config['board_email']="bad\nheader";
wn_check(!kb_notify_after_write($outcome)&&$GLOBALS['wn_calls']===array('ready'),'Invalid current board address never constructs a transport');
echo "KB optional postcommit notifications: local transport doubles only, no email/network; success/failure/metadata/isolation passed.\n";
