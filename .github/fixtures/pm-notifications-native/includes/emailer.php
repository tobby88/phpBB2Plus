<?php
$pn_mailer_file=$GLOBALS['pn_app'].'includes/emailer.php';$pn_mailer_source=file_get_contents($pn_mailer_file);$pn_mailer_source=preg_replace('/^class emailer\s*$/m','class PmNativeMailerBase',$pn_mailer_source,-1,$pn_replaced);kp_check($pn_replaced===1,'Actual PM mailer class');$pn_mailer_source=str_replace(array('__DIR__','__FILE__'),array(var_export(dirname($pn_mailer_file),true),var_export($pn_mailer_file,true)),$pn_mailer_source);eval(substr($pn_mailer_source,5));
class emailer extends PmNativeMailerBase {
 function __construct($smtp,$optional=false){kp_check(!$smtp&&$optional===true&&in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true),'Native PM sink refuses mandatory/SMTP/real mail');if($GLOBALS['pn_fault']==='construct'){throw new RuntimeException('Synthetic constructor failure');}parent::__construct(false,true);}
 function use_template($name,$language=''){global$phpbb_root_path;if($GLOBALS['pn_fault']==='template'){throw new RuntimeException('Synthetic template failure');}$prior=$phpbb_root_path;$phpbb_root_path=$GLOBALS['pn_app'];try{return parent::use_template($name,$language);}finally{$phpbb_root_path=$prior;}}
 function php_mail($to){
  kp_check($GLOBALS['pn_open']===0,'PM transport retains no claim/read owner');
  $old=kp_rows('SELECT @@SESSION.innodb_lock_wait_timeout seconds')[0]['seconds'];kp_sql('SET SESSION innodb_lock_wait_timeout=1');try{kp_sql('UPDATE fixture_users SET username=username WHERE user_id=3');kp_sql('UPDATE fixture_privmsgs SET privmsgs_subject=privmsgs_subject');kp_sql('UPDATE fixture_pm_write_receipts SET created_at=created_at');}finally{kp_sql('SET SESSION innodb_lock_wait_timeout='.(int)$old);}
  $GLOBALS['pn_mail'][]=array('to'=>$to,'subject'=>$this->subject,'body'=>$this->msg,'vars'=>$this->vars);if($GLOBALS['pn_fault']==='transport-exception'){throw new RuntimeException('Synthetic transport exception');}return$GLOBALS['pn_fault']!=='transport-false';
 }
}
