<?php
namespace PasswordRehashFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $root.'includes/php_compat.php';
define('USERS_TABLE','fixture_users');
function check($ok,$message) { if(!$ok) { throw new \RuntimeException($message); } }
class NoPrematureCredentialWrite
{
    function sql_query($sql) { throw new \RuntimeException('Hash generation must not publish credentials before the owned login'); }
}
$login=str_replace("\r\n","\n",file_get_contents($root.'login.php'));
$start=strpos($login,"\t\t\t\t\t\tif (!empty(\$board_config['password_hashing'])");
$end=$start===false?false:strpos($login,"\t\t\t\t\t\t\$autologin =",$start);
check($start!==false && $end>$start,'Actual opportunistic login rehash block located');
$dispatch='namespace PasswordRehashFixture;'.substr($login,$start,$end-$start);
$password='Fixture-password!9';
$board_config=array('password_hashing'=>1);
$hashes=array('md5'=>md5($password));
foreach(array(4,10,12,13) as $cost) { $hashes['cost'.$cost]=password_hash($password,PASSWORD_BCRYPT,array('cost'=>$cost)); }
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try
{
    foreach(array(null,array('invalid'),true,'','invalid',str_repeat('a',32)."\n",'$argon2id$v=19$m=65536,t=4,p=1$unavailable$format') as $value)
    {
        check(!\phpbb_password_needs_rehash($value),'Malformed or other algorithms are not implicitly replaced by bcrypt');
    }
    if(defined('PASSWORD_ARGON2ID'))
    {
        $argon=password_hash($password,PASSWORD_ARGON2ID,array('memory_cost'=>8192,'time_cost'=>1,'threads'=>1));
        check(!\phpbb_password_needs_rehash($argon),'Recognized Argon2id is not downgraded to bcrypt');
    }
    $target=PASSWORD_BCRYPT_DEFAULT_COST;
    foreach($hashes as $name=>$hash)
    {
        $expected=$name==='md5' || (int)substr($name,4)<$target;
        check((bool)\phpbb_password_needs_rehash($hash)===$expected,'Only MD5 or a lower bcrypt cost needs migration');
        check(\phpbb_password_verify($password,$hash),'All original bcrypt work factors and MD5 remain verifiable');
    }

    // Actual database/race/rollback coverage now lives in the canonical native
    // login-publication suite. Generation itself must never write a credential.
    $db=new NoPrematureCredentialWrite();
        foreach($hashes as $name=>$hash)
        {
            foreach(array(0,1) as $enabled)
            {
                $board_config['password_hashing']=$enabled;
                $row=array('user_id'=>42,'user_password'=>$hash); $upgraded_password=null; eval($dispatch);
                $expected=$enabled && \phpbb_password_needs_rehash($hash);
                check(($upgraded_password!==null)===(bool)$expected,'Actual login observes schema opt-in and monotonic cost');
                check($row['user_password']===$hash,'Observed credential snapshot is not overwritten before publication');
                if($expected)
                {
                    $info=password_get_info($upgraded_password);
                    check($info['algoName']==='bcrypt' && $info['options']['cost']===$target && \phpbb_password_verify($password,$upgraded_password),'Actual replacement is valid native-cost bcrypt');
                }
                else { check($upgraded_password===null,'Stronger or disabled migration does not generate a replacement'); }
            }
        }
        $board_config['password_hashing']=1;$password=str_repeat('x',73);$row['user_password']=md5($password);$upgraded_password=null;eval($dispatch);
        check($upgraded_password===null,'Long legacy password is never truncation-rehashed');
        $owner=file_get_contents($root.'includes/functions_login_storage.php');
        check(strpos($login,'phpbb_login_session($db, $row,')!==false&&strpos($login,'$admin, $upgraded_password, $password, $ctracker_config)')!==false,'Actual controller hands observed snapshot, upgrade, verified policy bytes and tracker to owned publication');
        check(strpos($owner,"array('user_id','username','user_password','user_active','user_level','user_blocktime')")!==false&&strpos($owner,'hash_equals((string)$expected[$key], (string)$current[$key])')!==false,'Owner binds upgrades to exact credential and current account context');
        echo 'Actual monotonic password rehash generation and owned publication wiring passed: '.PHP_VERSION."\n";
}
finally { restore_error_handler(); }
