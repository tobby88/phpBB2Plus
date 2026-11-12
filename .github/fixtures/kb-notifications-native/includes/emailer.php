<?php
$kn_mailer_file=$GLOBALS['kn_app'].'includes/emailer.php';$kn_mailer_source=file_get_contents($kn_mailer_file);$kn_mailer_source=preg_replace('/^class emailer\s*$/m','class KbNativeMailerBase',$kn_mailer_source,-1,$kn_mailer_replaced);kp_check($kn_mailer_replaced===1,'Actual KB mailer class');$kn_mailer_source=str_replace(array('__DIR__','__FILE__'),array(var_export(dirname($kn_mailer_file),true),var_export($kn_mailer_file,true)),$kn_mailer_source);eval(substr($kn_mailer_source,5));
class emailer extends KbNativeMailerBase {
 function __construct($smtp,$optional=false){kp_check(!$smtp&&$optional===true&&in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true),'Native KB sink refuses SMTP/mandatory/real mail');if($GLOBALS['kn_fault']==='construct'){throw new RuntimeException('Synthetic optional constructor failure');}parent::__construct(false,true);}
 function use_template($name,$language=''){global$phpbb_root_path;if($GLOBALS['kn_fault']==='template'){throw new RuntimeException('Synthetic optional template failure');}$prior=$phpbb_root_path;$phpbb_root_path=$GLOBALS['kn_app'];try{return parent::use_template($name,$language);}finally{$phpbb_root_path=$prior;}}
 function php_mail($to){
  foreach($GLOBALS['kp_owner_connections']as$owner){kp_check(!$owner->connection->db_connect_id,'Real KB write/read owner released before mail');}
  $old=kp_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds'];kp_sql('SET SESSION innodb_lock_wait_timeout=1');
  try{kp_sql('UPDATE fixture_kb_articles SET article_title=article_title');kp_sql('UPDATE fixture_kb_config SET config_value=config_value');kp_sql('UPDATE fixture_config SET config_value=config_value');}finally{kp_sql('SET SESSION innodb_lock_wait_timeout='.(int)$old);}
  $GLOBALS['kn_mail'][]=array('to'=>$to,'subject'=>$this->subject,'body'=>$this->msg,'vars'=>$this->vars);if($GLOBALS['kn_fault']==='transport-exception'){throw new RuntimeException('Synthetic optional transport exception');}return$GLOBALS['kn_fault']!=='transport-false';
 }
}
