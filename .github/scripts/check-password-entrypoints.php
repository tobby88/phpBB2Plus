<?php
// Actual request adapters, input assignments and credential verifier. No real
// forum configuration/database/mail and no access to existing user credentials.
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $root.'includes/php_compat.php';
define('ADMIN',1);define('USERS_TABLE','fixture_users');
function boundary_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function boundary_fragment($source,$a,$b){$start=strpos($source,$a);$end=strpos($source,$b,$start);boundary_check($start!==false&&$end>$start,'Actual credential source boundary');return substr($source,$start,$end-$start);}
$installer=file_get_contents($root.'install/install.php');
boundary_check(preg_match('/^function install_slash_request_value\(.*?^}/ms',$installer,$match)===1,'Actual installer request adapter');eval($match[0]);
$install_input=boundary_fragment($installer,'$admin_pass1 =','$ftp_path =');
boundary_check(preg_match('/\$admin_password = phpbb_password_hash\([^;]+;/', $installer,$match)===1,'Actual installer hash');$install_hash=$match[0];
$source=file_get_contents($root.'login.php');$login_input=boundary_fragment($source,'$password_value =','$sql =');
$source=file_get_contents($root.'includes/usercp_register.php');$register_input=boundary_fragment($source,"foreach (array('cur_password', 'new_password', 'password_confirm')",'$signature =');
boundary_check(preg_match('/\$new_password = phpbb_password_hash\([^;]+;/', $source,$match)===1,'Actual public registration hash');$register_hash=$match[0];
$source=file_get_contents($root.'admin/erc.php');
boundary_check(preg_match('/^function dbmtnc_escape_request_data\(.*?^}/ms',$source,$match)===1,'Actual ERC request adapter');eval($match[0]);
$source=file_get_contents($root.'includes/functions_dbmtnc.php');eval(boundary_fragment($source,'function check_authorisation(','/**'));
// The credential SELECT returns a synthetic row; hashing and verification are
// production code. Native ERC tests separately exercise real SQL and races.
class PasswordBoundaryDatabase {
    public $hash='';
    function sql_query($sql){return true;}
    function sql_fetchrow($r){return array('user_id'=>2,'username'=>'Admin','user_password'=>$this->hash,'user_level'=>ADMIN,'user_active'=>1);}
    function sql_freeresult($r){} function sql_escape($value){return addslashes($value);}
}
$db=new PasswordBoundaryDatabase();$option='cbl';$dbuser='fixture-owner';$dbpasswd="owner'\\password";
$cases=0;
foreach(array(0,1) as $hashing){
    $board_config=array('password_hashing'=>$hashing);
    foreach(array('Synthetic-only!93',"Quote'Only!93",'Quote"Only!93','Slash\\Only!93','Grüße-only!93',' space only!93 ','&amp; literal !93',str_repeat("'",36),str_repeat('ä',36)) as $raw){
        $_POST=install_slash_request_value(array('admin_pass1'=>$raw,'admin_pass2'=>$raw));eval($install_input);
        boundary_check($install_password_error===''&&$admin_pass1===$raw&&$admin_pass2===$raw,'Installer accepts valid input and retains exact confirmation');
        eval($install_hash);$installed=$admin_password;
        $_POST=phpbb_addslashes_recursive(array('new_password'=>$raw,'password_confirm'=>$raw));eval($register_input);eval($register_hash);$registered=$new_password;
        $_POST=phpbb_addslashes_recursive(array('password'=>$raw));eval($login_input);
        boundary_check($password===addslashes($raw)&&phpbb_password_verify($password,$installed)&&phpbb_password_verify($password,$registered),'Both account creators match the unchanged actual login input');
        foreach(array($installed,$registered) as $hash){
            $db->hash=$hash;$HTTP_POST_VARS=dbmtnc_escape_request_data(array('auth_method'=>'board','board_user'=>'Admin','board_password'=>$raw));
            boundary_check(check_authorisation(false),'Actual ERC verifier accepts exactly the normal login credential');
            foreach(array(trim($raw),htmlspecialchars($raw,ENT_QUOTES,'UTF-8'),addslashes($raw),stripslashes($raw)) as $changed){
                if($changed===$raw){continue;}
                $HTTP_POST_VARS=dbmtnc_escape_request_data(array('auth_method'=>'board','board_user'=>'Admin','board_password'=>$changed));
                boundary_check(!check_authorisation(false),'Transformed or trimmed variants are not credential aliases');
            }
        }
        $cases++;
    }
}
foreach(array(str_repeat("'",37),'prefix'."\0".'suffix',array('secret')) as $invalid){
    $_POST=install_slash_request_value(array('admin_pass1'=>$invalid,'admin_pass2'=>$invalid));eval($install_input);
    boundary_check($install_password_error!=='','Invalid/oversized canonical input is rejected before installer schema writes');
}
$HTTP_POST_VARS=dbmtnc_escape_request_data(array('auth_method'=>'db','db_user'=>$dbuser,'db_password'=>$dbpasswd));
boundary_check(check_authorisation(false),'Literal database-owner password is still decoded exactly once, not treated as a board hash input');
$HTTP_POST_VARS=dbmtnc_escape_request_data(array('auth_method'=>'board','board_user'=>'Admin','board_password'=>array('secret')));
boundary_check(!check_authorisation(false),'Malformed board password fails without a PHP string TypeError');
$db->hash=md5('');
boundary_check(!check_authorisation(false),'A malformed password must not become the empty-string credential of an old account');
foreach(array('auth_method','board_user','board_password','db_user','db_password') as $field){
    $HTTP_POST_VARS=dbmtnc_escape_request_data(array('auth_method'=>strpos($field,'db_')===0?'db':'board','board_user'=>'Admin','board_password'=>'Synthetic-only!93','db_user'=>$dbuser,'db_password'=>$dbpasswd));
    $HTTP_POST_VARS[$field]=array('invalid');boundary_check(!check_authorisation(false),'Malformed credential component is rejected: '.$field);
}
$dbpasswd='0e123';$HTTP_POST_VARS=dbmtnc_escape_request_data(array('auth_method'=>'db','db_user'=>$dbuser,'db_password'=>'0e456'));
boundary_check(!check_authorisation(false),'Numeric-looking database passwords are not loose-comparison aliases');
foreach(array('english','german') as $language){
    $pack=file_get_contents($root.'language/lang_'.$language.'/lang_main.php');
    boundary_check(preg_match('/^\$lang\[\'Password_long\'\] = .*;$/m',$pack,$match)===1,'Actual password-limit explanation');
    eval($match[0]);boundary_check(preg_match('//u',$lang['Password_long'])===1&&strpos($lang['Password_long'],'72')!==false&&strpos($lang['Password_long'],$language==='english'?'two bytes':'jeweils doppelt')!==false,'Both localized limits explain internal quote/backslash counting');
}
echo "Password entrypoints: $cases exact installer/registration/login/ERC round-trips, variant rejection and input-policy cases passed.\n";
