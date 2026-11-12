<?php
// Execute the real mailer/templates; replace only its final transport. A fresh
// name lets the production dispatcher instantiate its normal `emailer` class.
$tn_mailer_file=$GLOBALS['tn_app'].'includes/emailer.php';$tn_mailer_source=file_get_contents($tn_mailer_file);
$tn_mailer_source=preg_replace('/^class emailer\s*$/m','class TopicNativeMailerBase',$tn_mailer_source,-1,$tn_replaced);if($tn_replaced!==1){throw new RuntimeException('Exact real mailer class required');}
$tn_mailer_source=str_replace(array('__DIR__','__FILE__'),array(var_export(dirname($tn_mailer_file),true),var_export($tn_mailer_file,true)),$tn_mailer_source);eval(substr($tn_mailer_source,5));
class emailer extends TopicNativeMailerBase{
 function __construct($smtp,$optional=false){nt_check(!$smtp&&$optional===true,'Native notification fixture cannot select SMTP or mandatory mail');parent::__construct(false,true);}
 function use_template($name,$language=''){global$phpbb_root_path;$previous=$phpbb_root_path;$phpbb_root_path=$GLOBALS['tn_app'];try{return parent::use_template($name,$language);}finally{$phpbb_root_path=$previous;}}
 function php_mail($to){nt_check($GLOBALS['tn_open_owners']===0&&tn_lock_free(),'Actual transport never holds the writer lock');$GLOBALS['tn_deliveries'][]=array('to'=>$to,'subject'=>$this->subject,'body'=>$this->msg,'headers'=>$this->extra_headers,'vars'=>$this->vars);return$GLOBALS['tn_transport'];}
}
