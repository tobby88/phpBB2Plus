<?php
namespace PasswordRehashFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $root.'includes/php_compat.php';
define('USERS_TABLE','fixture_users');
function check($ok,$message) { if(!$ok) { throw new \RuntimeException($message); } }
class AccountDb
{
    public $hash; public $active; public $id; public $attempts=0; public $fail=false; public $pdo;
    function __construct($hash,$active=1,$id=42,$pdo=null)
    {
        $this->hash=$hash; $this->active=$active; $this->id=$id; $this->pdo=$pdo;
        if($pdo)
        {
            $pdo->exec('DELETE FROM fixture_users');
            $statement=$pdo->prepare('INSERT INTO fixture_users (user_id,user_active,user_password) VALUES (?,?,?)');
            $statement->execute(array($id,$active,$hash));
        }
    }
    function sql_escape($value) { return $this->pdo ? substr($this->pdo->quote($value),1,-1) : str_replace("'","''",$value); }
    function sql_query($sql)
    {
        $this->attempts++;
        check(preg_match('/^UPDATE fixture_users SET user_password = \x27([a-zA-Z0-9$.\/]+)\x27 WHERE user_id = 42 AND user_active = 1 AND CAST\(user_password AS BINARY\) = CAST\(\x27([a-zA-Z0-9$.\/]+)\x27 AS BINARY\)$/D',$sql,$match)===1,'Actual upgrade SQL is scoped to active identity and exact observed credential');
        if($this->fail) { return false; }
        if($this->pdo)
        {
            $this->pdo->exec($sql);
            $this->hash=$this->pdo->query('SELECT user_password FROM fixture_users')->fetchColumn();
        }
        elseif($this->id===42 && $this->active===1 && $this->hash===$match[2]) { $this->hash=$match[1]; }
        return true;
    }
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

    // Native mode is opt-in and confined to an explicitly owned local fixture DB.
    $dsn=getenv('PHPBB_REHASH_TEST_DSN'); $pdo=null; $engines=array('Memory');
    if($dsn!==false && $dsn!=='')
    {
        check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_rehash_[a-f0-9]+;charset=utf8mb4$/D',$dsn)===1,'Native DSN restricted to local owned fixture');
        $pdo=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
        $engines=array('MyISAM','InnoDB');
    }
    foreach($engines as $engine)
    {
        if($pdo) { $pdo->exec('DROP TABLE IF EXISTS fixture_users'); $pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY,user_active INTEGER,user_password VARCHAR(255) COLLATE utf8mb4_unicode_ci) ENGINE='.$engine); }
        foreach($hashes as $name=>$hash)
        {
            foreach(array(0,1) as $enabled)
            {
                $board_config['password_hashing']=$enabled;
                $row=array('user_id'=>42,'user_password'=>$hash); $db=new AccountDb($hash,1,42,$pdo); eval($dispatch);
                $expected=$enabled && \phpbb_password_needs_rehash($hash);
                check($db->attempts===($expected?1:0),'Actual login observes schema opt-in and monotonic cost');
                if($expected)
                {
                    $info=password_get_info($db->hash);
                    check($info['algoName']==='bcrypt' && $info['options']['cost']===$target && \phpbb_password_verify($password,$db->hash),'Actual replacement is valid native-cost bcrypt');
                }
                else { check($db->hash===$hash,'Stronger or disabled migration leaves exact stored bytes unchanged'); }
            }
        }
        $board_config['password_hashing']=1; $original=$hashes['md5']; $row=array('user_id'=>42,'user_password'=>$original);
        foreach(array(array(md5('concurrent new password'),1,42),array(strtoupper($original),1,42),array($hashes['cost13'],1,42),array($original,0,42),array($original,1,43)) as $current)
        {
            $db=new AccountDb($current[0],$current[1],$current[2],$pdo); eval($dispatch);
            check($db->attempts===1 && $db->hash===$current[0],'Concurrent reset/rehash, case-only change, deactivation or other identity cannot be overwritten');
        }
        $db=new AccountDb($original,1,42,$pdo); $db->fail=true; eval($dispatch);
        check($db->hash===$original,'Optional rehash query failure does not change the stored credential');
        $password=str_repeat('x',73); $row['user_password']=md5($password); $db=new AccountDb($row['user_password'],1,42,$pdo); eval($dispatch);
        check($db->attempts===0,'Long legacy password is never truncation-rehashed');
        $password='Fixture-password!9';
        echo 'Actual password rehash dispatch and exact compare-and-swap passed: '.PHP_VERSION.' '.$engine."\n";
    }
}
finally { restore_error_handler(); }
