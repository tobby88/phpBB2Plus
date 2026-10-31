<?php
require$app.'ctracker/classes/class_ct_database.php';require$app.'ctracker/classes/class_ct_adminfunctions.php';require$app.'ctracker/classes/class_settings_storage.php';
class CtSettingsNativeTemplate extends CtLogNativeTemplate {
 public$vars=array();function assign_vars($values){$this->vars=array_merge($this->vars,$values);}
}
function cs_rows(){return cl_rows('SELECT ct_config_name,ct_config_value FROM '.CTRACKER_CONFIG.' ORDER BY HEX(ct_config_name)');}
function cs_reset($id=7){
 global$ctracker_config,$template,$db;
 cl_reset($id);cl_sql('DELETE FROM '.CTRACKER_CONFIG);$defaults=(new ct_database())->default_settings();$tuples=array();
 $defaults['unrelated_setting']='Preserved Grüße 😀';foreach($defaults as$key=>$value){$tuples[]="('".$db->sql_escape($key)."','".$db->sql_escape($value)."')";}
 cl_sql('INSERT INTO '.CTRACKER_CONFIG.'(ct_config_name,ct_config_value)VALUES'.implode(',',$tuples));
 $ctracker_config=new ct_database();$template=new CtSettingsNativeTemplate();
 $routes=jr_admin_authorization_routes();$hash=array_search('admin_cracker_tracker.php?modu=9',$routes,true);cl_check($hash!==false,'Actual exact settings module registered');
 cl_sql("UPDATE fixture_jr_admin_users SET user_jr_admin='".$hash."' WHERE user_id=11");
}
function cs_controller($values,$token='log-own-session'){
 global$ctracker_config,$template,$lang,$images,$phpEx,$phpbb_root_path,$HTTP_POST_VARS;
 $_POST=$HTTP_POST_VARS=array_merge(array('submit'=>'1','sid'=>$token),$values);
 include$GLOBALS['app'].'ctracker/admin/acp_module_settings.php';
}
function cs_failure($callback,$message){
 $before=cs_rows();$memory=$GLOBALS['ctracker_config']->settings;$invalid=$GLOBALS['ctracker_config']->invalid_settings;$files=cl_fingerprint();$denied=false;
 try{$callback();}catch(CtLogControllerExit$error){$denied=true;}catch(CtrackerSettingsFailure$error){$denied=true;}catch(CtrackerAcpAuthorityFailure$error){$denied=true;}
 cl_check($denied&&cs_rows()===$before&&$GLOBALS['ctracker_config']->settings===$memory&&$GLOBALS['ctracker_config']->invalid_settings===$invalid&&cl_fingerprint()===$files&&cl_closed(),$message.' rolls back whole database batch; memory/files unchanged; owner released');
}
function cs_recreate(){global$canonical;cl_sql('DROP TABLE '.CTRACKER_CONFIG);preg_match('/^CREATE TABLE `phpbb_ctracker_config`\s*\([\s\S]*?;/m',$canonical,$match);cl_sql(str_replace('phpbb_ctracker_config',CTRACKER_CONFIG,$match[0]));}
