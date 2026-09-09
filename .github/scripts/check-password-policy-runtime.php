<?php
namespace PasswordPolicyFixture;
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require_once $root . 'includes/php_compat.php';
require_once $root . 'includes/functions_validate.php';
if (!defined('GENERAL_MESSAGE')) { define('GENERAL_MESSAGE', 200); }
function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
function message_die($type, $message) { throw new \RuntimeException($message); }
function phpbb_password_hash($password) { return $GLOBALS['fail_hash'] ? false : \phpbb_password_hash($password); }
function phpbb_admin_require_post_session() {}
function page_header($title) {}
function page_error($title, $message) { throw new \RuntimeException($message); }
class NoPasswordWriteDb
{
    public $queries = array();
    function sql_query($sql) { $this->queries[]=$sql; return true; }
}
function fragment($source, $start, $end)
{
    $source = str_replace("\r\n", "\n", $source);
    $from = strpos($source, $start); $to = $from === false ? false : strpos($source, $end, $from);
    check($from !== false && $to > $from, 'Actual controller fragment found');
    return 'namespace PasswordPolicyFixture;' . substr($source, $from, $to - $from);
}
function rejects($code, $expected)
{
    $caught = false;
    try { call_user_func($code); } catch (\RuntimeException $error) { $caught = $error->getMessage() === $expected; }
    check($caught, 'Expected controlled rejection without credential disclosure: ' . $expected);
}
$lang = array('Fields_empty'=>'empty','Password_invalid'=>'invalid','Password_long'=>'long','Password_hash_failed'=>'hash-failed','New_user_password_required'=>'empty','Password_mismatch'=>'mismatch','Password_not_complex'=>'policy:','Password_to_short'=>'minimum %d','Password_not_same'=>'same-name','Password_mixed'=>'mixed');
$board_config = array('password_hashing'=>1,'min_password_len'=>6,'force_complex_password'=>1,'password_not_login'=>1);
$fail_hash = false;
$admin = file_get_contents($root.'admin/admin_users.php');
$quick = file_get_contents($root.'admin/admin_user_register.php');
$installer = file_get_contents($root.'install/install.php');
foreach (array('admin_user_post_string','admin_user_require_creation_password') as $name)
{
    check(preg_match('/^function '.$name.'\(.*?^\}/ms', $admin, $match)===1, 'Actual ACP function found');
    eval('namespace PasswordPolicyFixture;'.$match[0]);
}
$quick_validate = fragment($quick, "\t\$passwd_sql = '';", "\t//\n\t// Do a ban check");
$admin_assign = fragment($admin, "\t\t\$passwd_sql = '';", '// End add - Admin add user MOD');
$installer_input = fragment($installer, '$admin_pass1 =', '$ftp_path =');
$lang['Password_minimum_invalid']='minimum-invalid'; $lang['Install']='install'; $lang['Installer_Error']='installer-error';
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new \RuntimeException($message); } });
try
{
    foreach (array(array(null,'invalid'),array(array('secret'),'invalid'),array(true,'invalid'),array('', 'empty'),array("secret\0tail",'invalid'),array(str_repeat('a',73),'long'),array(str_repeat('ä',37),'long'),array(str_repeat('😀',19),'long')) as $case)
    {
        check(\phpbb_password_input_error($case[0]) === array_search($case[1],$lang,true), 'Shared boundary classifies malformed, NUL and byte-length input');
        check(\validate_complex_password('Fixture', $case[0])['error'], 'Policy rejects unsafe input before hashing');
        foreach (array(0,1) as $hashing)
        {
            $board_config['password_hashing']=$hashing;
            check(\phpbb_password_hash($case[0]) === false, 'No malformed/truncated hash in either schema migration state');
        }
    }
    $board_config['password_hashing']=1;
    foreach (array(str_repeat('a',72),str_repeat('ä',36),str_repeat('😀',18)) as $value)
    {
        check(\phpbb_password_input_error($value)==='', 'Exact 72-byte boundary accepted without trimming');
        check(\phpbb_password_verify($value, \phpbb_password_hash($value)), 'Boundary hash verifies original UTF-8 bytes');
    }
    foreach (array(array('short','policy:minimum 6, mixed'),array('lettersOnly','policy:mixed'),array('Fixture9','policy:same-name'),array(str_repeat('a',73),'long'),array("Good9\0bad",'invalid')) as $case)
    {
        $_POST=array('username'=>'Fixture9','password'=>$case[0],'password_confirm'=>$case[0]);
        rejects(function(){admin_user_require_creation_password();}, $case[1]);
        $username='Fixture9'; $email='fixture@example.invalid'; $new_password=$case[0]; $password_confirm=$case[0]; $error=false; $error_msg='';
        eval($quick_validate); check($error, 'Quick-add enforces same configured rules');
    }
    check(!\validate_complex_password('0e12345','0e67890')['error'], 'Not-login rule uses exact strings, not numeric coercion');
    $board_config['min_password_len']=128;
    check(!\validate_complex_password('Fixture',str_repeat('a',71).'9')['error'], 'Legacy excessive minimum cannot make all new passwords impossible');
    $board_config['min_password_len']=6;

    $_POST=array('username'=>'Fixture','password'=>'Correct!99','password_confirm'=>'Correct!99');
    $fail_hash=true;
    rejects(function(){admin_user_require_creation_password();}, 'hash-failed');
    check(strpos($admin,'$new_user_password_hash = admin_user_require_creation_password();') < strpos($admin,'phpbb_allocate_user_id('), 'Early hash completes before reservation/placeholder writes');
    $username='Fixture'; $password='Correct!99'; $password_confirm=$password; $new_user=false; $force_new_passwd=false; $error=false; $error_msg='';
    $caught=false;
    try { eval($admin_assign); } catch (\RuntimeException $error) { $caught=$error->getMessage()==='hash-failed'; }
    check($caught && $passwd_sql==='', 'Failed edit hash never becomes password SQL');
    foreach (array(
        array($quick, '$new_password = phpbb_password_hash($new_password);', 'require_once('),
        array(file_get_contents($root.'change_password.php'), '$new_password_hash = phpbb_password_hash($new_password);', '$new_password_hash_sql ='),
        array(file_get_contents($root.'includes/usercp_register.php'), '$new_password = phpbb_password_hash($new_password);', '$passwd_sql ='),
        array(file_get_contents($root.'includes/usercp_activate.php'), '$new_hash = phpbb_password_hash($new_password);', '$new_hash_sql ='),
        array($installer, '$admin_password = phpbb_password_hash($admin_pass1);', '// Load in the sql parser')
    ) as $writer)
    {
        $failure_fragment=fragment($writer[0],$writer[1],$writer[2]);
        rejects(function() use ($failure_fragment) { global $lang; $new_password=$admin_pass1='Correct!99'; eval($failure_fragment); }, 'hash-failed');
    }
    $fail_hash=false;

    $long=str_repeat('x',72).'legacy-suffix';
    foreach (array(md5($long),password_hash($long,PASSWORD_BCRYPT)) as $legacy)
    {
        check(\phpbb_password_verify($long,$legacy), 'Legacy long password remains usable to authenticate and change it');
        check(\phpbb_password_hash($long)===false, 'Automatic rehash cannot silently truncate a long legacy password');
    }
    $login=file_get_contents($root.'login.php');
    $rehash=fragment($login, "\t\t\t\t\t\tif (!empty(\$board_config['password_hashing'])", "\t\t\t\t\t\t\$autologin =");
    $password=$long; $row=array('user_id'=>42,'user_password'=>md5($long)); $db=new NoPasswordWriteDb(); eval($rehash);
    check(count($db->queries)===0, 'Actual login rehash branch preserves the stored long legacy hash');
    check(!\phpbb_password_verify("Correct!99\0tail",md5('Correct!99')), 'NUL rejected consistently during verification');
    check(!\phpbb_password_verify(str_repeat('x',129),md5(str_repeat('x',129))), 'Verification matches the existing login 128-byte bound');

    foreach (array("Quote' & \\slash!9",str_repeat('ä',36),str_repeat('ä',37),"Null\0value9") as $value)
    {
        $_POST=array('admin_pass1'=>addslashes($value),'admin_pass2'=>addslashes($value)); eval($installer_input);
        check($admin_pass1===$value && $admin_pass2===$value && $install_password_error===\phpbb_password_input_error($value), 'Installer removes exactly its own adapter escaping and applies shared bounds');
    }
    check(strpos($installer,'$admin_password = phpbb_password_hash($admin_pass1);') < strpos($installer,'// Load in the sql parser'), 'Installer hashes before schema or account writes');
    check(strpos($installer,'$admin_pass1 != $admin_pass2')===false && strpos($installer,'install_html($admin_pass1)')===false, 'Installer neither loosely compares nor double-unescapes passwords');
    check(strpos($installer,"'min_password_len' => 6")!==false && strpos($installer,"'password_not_login' => 1")!==false && strpos($installer,'$install_password_policy = validate_complex_password(')!==false, 'Installer enforces its seeded policy before creation');

    $board=file_get_contents($root.'admin/admin_board.php');
    $board_gate=fragment($board,'if ($is_submit)', "//\n// Pull all config data");
    foreach (array('73','-1','1.5','6bad',array('6')) as $value)
    {
        $_POST=array('min_password_len'=>$value);
        rejects(function() use($board_gate) { global $lang; $is_submit=true; eval($board_gate); },'minimum-invalid');
    }
    $_POST=array('min_password_len'=>'72'); $is_submit=true; eval($board_gate);

    foreach (array('change_password.php','includes/usercp_register.php','includes/usercp_activate.php','admin/admin_users.php','admin/admin_user_register.php') as $path)
    {
        $source=file_get_contents($root.$path);
        check(strpos($source,'validate_complex_password(')!==false || strpos($source,'validate_complex_password (')!==false, 'Every interactive password writer applies configured policy: '.$path);
        check(strpos($source,"\$lang['Password_hash_failed']")!==false && strpos($source,"'L_PASSWORD_LIMIT' =>")!==false, 'Writer handles hash failures and displays the byte bound: '.$path);
        check(!preg_match('/\$new_password\s*!=\s*\$password_confirm/', $source), 'No loose confirmation remains: '.$path);
    }
    echo "Shared password boundaries, ACP policy, hashing failures, installer bytes and legacy authentication checks passed.\n";
}
finally { restore_error_handler(); }

// Exercise the ACTUAL helper against native hashing failures on all PHP lines.
namespace PasswordHashFaultFixture;
function password_hash($password,$algorithm)
{
    if ($GLOBALS['fault_mode']==='exception') { throw new \RuntimeException('secret must not escape'); }
    if ($GLOBALS['fault_mode']==='error') { throw new \Error('secret must not escape'); }
    return false;
}
$compat=file_get_contents($root.'includes/php_compat.php');
if (!preg_match('/^\tfunction phpbb_password_hash\(.*?^\t\}/ms',$compat,$match)) { throw new \RuntimeException('Hash helper not found'); }
eval('namespace PasswordHashFaultFixture; use \\Exception; use \\Throwable;'.$match[0]);
foreach (array('false','exception','error') as $fault_mode)
{
    if ($fault_mode==='error' && PHP_VERSION_ID<70000) { continue; }
    if (phpbb_password_hash('Correct!99')!==false) { throw new \RuntimeException('Hash failure was not contained'); }
}
echo "Native false/exception/error hashing failures return false without disclosing credentials.\n";
