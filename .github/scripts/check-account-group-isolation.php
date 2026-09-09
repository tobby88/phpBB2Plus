<?php
// Execute the real ACP creation SQL against an isolated database. Only durable
// ID allocation is stubbed; its own runtime/native regression suite covers it.
namespace AccountGroupFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
define('USERS_TABLE','fixture_users'); define('GROUPS_TABLE','fixture_groups');
define('USER_GROUP_TABLE','fixture_user_group'); define('POST_USERS_URL','u');
define('BEGIN_TRANSACTION',1); define('END_TRANSACTION',2); define('DEFAULT_USER_ID',2);
function check($ok,$message) { if(!$ok) { throw new \RuntimeException($message); } }
function message_die($type,$message) { throw new \RuntimeException($message); }
function phpbb_allocate_user_id($db,$prefix) { check($prefix==='fixture_','Expected allocator prefix'); return 42; }
function phpbb_user_write_begin(&$db) { return $db; }
function phpbb_user_write_end(&$db,$scope) { check($db===$scope,'Creation releases its writer scope'); }
class CreationDb
{
    public $pdo; public $queries=array();
    function __construct() { $this->pdo=new \PDO('sqlite::memory:'); $this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION); }
    function sql_query($sql,$transaction=false) { $this->queries[]=$sql; return $this->pdo->query($sql); }
    function sql_nextid() { return (int)$this->pdo->lastInsertId(); }
    function sql_fetchrow($result) { return $result->fetch(\PDO::FETCH_ASSOC); }
}
$source=file_get_contents($root.'admin/admin_users.php');
$begin=strpos($source,'//we need to create the user');
$end=$begin===false?false:strpos($source,'$_POST[POST_USERS_URL] = $user_id;',$begin);
check($begin!==false && $end>$begin,'Actual ACP creation body found');
$body=substr($source,$begin,$end-$begin+strlen('$_POST[POST_USERS_URL] = $user_id;'));
$body=str_replace("require_once(\$phpbb_root_path . 'includes/functions_user_ids.' . \$phpEx);",'',$body);
$body='namespace AccountGroupFixture;'.$body;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try
{
    foreach(array(0,1) as $reference_level)
    {
        foreach(array(0,1) as $pending)
        {
            $db=new CreationDb(); $pdo=$db->pdo; $table_prefix='fixture_'; $_POST=array();
            $pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY,username TEXT,user_regdate INTEGER,user_active INTEGER,user_level INTEGER DEFAULT 0)');
            $pdo->exec('CREATE TABLE fixture_groups (group_id INTEGER PRIMARY KEY AUTOINCREMENT,group_name TEXT,group_description TEXT,group_single_user INTEGER,group_moderator INTEGER)');
            $pdo->exec('CREATE TABLE fixture_user_group (user_id INTEGER,group_id INTEGER,user_pending INTEGER)');
            $pdo->exec('CREATE TABLE fixture_auth_access (group_id INTEGER,auth_view INTEGER,auth_read INTEGER,auth_mod INTEGER)');
            $pdo->exec("INSERT INTO fixture_users VALUES (2,'Reference',100,1,".$reference_level."),(8,'Other member',200,1,0)");
            $pdo->exec("INSERT INTO fixture_groups VALUES (10,'Private','Private access',0,2),(20,'Pending','Requested membership',0,8),(30,'Moderators','Moderator access',0,2),(100,'','Reference personal group',1,0)");
            $pdo->exec('INSERT INTO fixture_user_group VALUES (2,10,0),(2,20,'.$pending.'),(2,30,0),(2,100,0),(8,10,0)');
            $pdo->exec('INSERT INTO fixture_auth_access VALUES (10,1,1,0),(20,1,1,0),(30,1,1,1)');
            $before=$pdo->query('SELECT * FROM fixture_user_group ORDER BY user_id,group_id')->fetchAll(\PDO::FETCH_ASSOC);
            eval($body);
            $memberships=$pdo->query('SELECT ug.*,g.group_single_user,g.group_moderator FROM fixture_user_group ug JOIN fixture_groups g ON g.group_id=ug.group_id WHERE ug.user_id=42')->fetchAll(\PDO::FETCH_ASSOC);
            check(count($memberships)===1 && (int)$memberships[0]['group_single_user']===1 && (int)$memberships[0]['user_pending']===0 && (int)$memberships[0]['group_moderator']===0,'New account receives only its personal group, never reference privileges');
            check((int)$pdo->query('SELECT COUNT(*) FROM fixture_user_group ug JOIN fixture_auth_access a ON a.group_id=ug.group_id WHERE ug.user_id=42 AND ug.user_pending=0 AND (a.auth_view=1 OR a.auth_read=1 OR a.auth_mod=1)')->fetchColumn()===0,'Private and moderator forum permissions are not inherited');
            check($before===$pdo->query('SELECT * FROM fixture_user_group WHERE user_id<>42 ORDER BY user_id,group_id')->fetchAll(\PDO::FETCH_ASSOC),'Existing and pending memberships remain byte-for-byte unchanged');
            $user=$pdo->query('SELECT * FROM fixture_users WHERE user_id=42')->fetch(\PDO::FETCH_ASSOC);
            check((int)$user['user_level']===0 && (int)$user['user_active']===0 && $_POST['u']===42,'Existing disabled placeholder and subsequent profile dispatch are preserved');
            check(count($db->queries)===3,'Creation issues only account, personal-group and personal-membership SQL');
        }
    }
    foreach(array('english','german') as $language)
    {
        $lang=array(); $text=file_get_contents($root.'language/lang_'.$language.'/lang_admin.php');
        check(preg_match('/^\$lang\[\x27Create_user_explain\x27\] = .*;$/m',$text,$match)===1,'Localized creation explanation found');
        eval($match[0]);
        check(substr_count($lang['Create_user_explain'],'%s')===1,'Profile reference remains a single explain placeholder');
        check(strpos($lang['Create_user_explain'],$language==='english'?'permissions are not copied':'Rechte werden nicht übernommen')!==false,'Permission-isolation behavior is explicit in both ACP languages');
    }
    echo "ACP personal-group isolation, no inherited ACLs and existing-membership preservation checks passed.\n";
}
finally { restore_error_handler(); }
