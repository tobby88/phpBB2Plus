<?php
$file=$GLOBALS['kp_source'].'includes/emailer.php';$source=file_get_contents($file);
$source=preg_replace('/^class emailer\s*$/m','class LinkNativeMailerBase',$source,-1,$replaced);kp_check($replaced===1,'Actual mailer class');
$source=str_replace(array('__DIR__','__FILE__'),array(var_export(dirname($file),true),var_export($file,true)),$source);eval(substr($source,5));
class emailer extends LinkNativeMailerBase{
 function __construct($smtp,$optional=false){kp_check(!$smtp&&$optional===true&&in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true),'Fixture refuses SMTP/mandatory/real mail');if($GLOBALS['lr_mail_fault']==='construct'){throw new RuntimeException('Optional constructor failure');}parent::__construct(false,true);}
 function use_template($name,$language=''){global$phpbb_root_path;if($GLOBALS['lr_mail_fault']==='template'){throw new RuntimeException('Optional template failure');}$prior=$phpbb_root_path;$phpbb_root_path=$GLOBALS['kp_source'];try{return parent::use_template($name,$language);}finally{$phpbb_root_path=$prior;}}
 function php_mail($to){
  foreach($GLOBALS['kp_owner_connections']as$owner){kp_check(!$owner->connection->db_connect_id,'No SQL owner held across actual mail transport');}
  $GLOBALS['lr_mail'][]=array('to'=>$to,'subject'=>$this->subject,'body'=>$this->msg,'from'=>$this->from);
  if($GLOBALS['lr_mail_fault']==='throw'){throw new RuntimeException('Optional transport failure');}return$GLOBALS['lr_mail_fault']!=='false';
 }
}
