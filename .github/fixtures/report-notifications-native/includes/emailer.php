<?php
$rn_mailer_file=$GLOBALS['rn_app'].'includes/emailer.php';$rn_mailer_source=file_get_contents($rn_mailer_file);
$rn_mailer_source=preg_replace('/^class emailer\s*$/m','class ReportNativeMailerBase',$rn_mailer_source,-1,$rn_replaced);if($rn_replaced!==1){throw new RuntimeException('Exact real report mailer class');}
$rn_mailer_source=str_replace(array('__DIR__','__FILE__'),array(var_export(dirname($rn_mailer_file),true),var_export($rn_mailer_file,true)),$rn_mailer_source);eval(substr($rn_mailer_source,5));
class emailer extends ReportNativeMailerBase{
 function __construct($smtp,$optional=false){ats_check(!$smtp&&$optional===true,'Native report sink cannot select SMTP or mandatory mail');parent::__construct(false,true);}
 function use_template($name,$language=''){global$phpbb_root_path;$prior=$phpbb_root_path;$phpbb_root_path=$GLOBALS['rn_app'];try{return parent::use_template($name,$language);}finally{$phpbb_root_path=$prior;}}
 function php_mail($to){
  ats_check($GLOBALS['lp_open']===0,'Report mail transport holds no mutation owner');
  // The real server must also permit a peer to lock the rows read by readiness.
  // A one-second bound accommodates server processing of a just-closed client.
  $old=lp_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds'];lp_query('SET SESSION innodb_lock_wait_timeout=1');
  try{lp_query('UPDATE fixture_users SET username=username WHERE user_id IN(3,4)');lp_query('UPDATE fixture_posts SET post_bluecard=post_bluecard WHERE post_id=30');}finally{lp_query('SET SESSION innodb_lock_wait_timeout='.(int)$old);}
  $GLOBALS['rn_deliveries'][]=array('to'=>$to,'subject'=>$this->subject,'body'=>$this->msg,'vars'=>$this->vars);
  if(is_callable($GLOBALS['rn_after_transport'])){call_user_func($GLOBALS['rn_after_transport']);}
  return $GLOBALS['rn_transport'];
 }
}
