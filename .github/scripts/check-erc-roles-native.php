<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_ROLES_NATIVE') !== '1') { return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('MOD', 2); define('USER', 0);
foreach (array('USERS'=>'users','USER_GROUP'=>'members','AUTH_ACCESS'=>'auth','GROUPS'=>'groups','FORUMS'=>'forums') as $key=>$table) { define($key.'_TABLE','fixture_'.$table); }
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require $root.'includes/php_compat.php';
$source=file_get_contents($root.'includes/functions_dbmtnc.php');
$a=strpos($source,'function check_authorisation(');$b=strpos($source,'function get_config_data(',$a);
if($a===false||$b<=$a||eval(str_replace('__FILE__',var_export($root.'includes/functions_dbmtnc.php',true),substr($source,$a,$b-$a)))===false){throw new RuntimeException('Actual ERC authorization unavailable');}
function ercr_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class ErcRoleFailure extends RuntimeException {}
function erc_throw_error($message){throw new ErcRoleFailure($message);}
function success_message($message){$GLOBALS['ercr_success']=true;}
function check_mysql_version(){return true;}
class ErcRoleDatabase {
    public $native,$peer,$hook=null,$failure='',$lostAck=false,$queries=array();
    function __construct($native,$peer){$this->native=$native;$this->peer=$peer;}
    function sql_query($sql){
        $this->queries[]=$sql;if($this->hook){$hook=$this->hook;$hook($sql,$this);}
        $fail=$this->failure!==''&&strpos($sql,$this->failure)===0;
        if($fail&&!$this->lostAck){return false;}
        $result=mysqli_query($this->native,$sql);
        if(!$result){throw new RuntimeException('Native fixture query failed: '.mysqli_error($this->native));}
        return $fail?false:$result;
    }
    function sql_fetchrow($r){return mysqli_fetch_assoc($r);} function sql_freeresult($r){mysqli_free_result($r);}
    function sql_escape($value){return mysqli_real_escape_string($this->native,$value);}
    function sql_affectedrows(){return mysqli_affected_rows($this->native);}
}
mysqli_report(MYSQLI_REPORT_OFF);
$port=getenv('PHPBB_ERC_ROLES_PORT')?:'3306';$password=getenv('PHPBB_ERC_ROLES_PASSWORD')?:'';
ercr_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<65536,'Invalid fixture port');
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);ercr_check($control!==false,'Loopback fixture unavailable');
$schema='codex_erc_roles_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
ercr_check(mysqli_query($control,'CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Create owned fixture');
$native=$peer=null;
try {
    $native=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);
    ercr_check($native&&$peer,'Independent fixture connections');mysqli_set_charset($native,'utf8mb4');mysqli_set_charset($peer,'utf8mb4');
    $db=new ErcRoleDatabase($native,$peer);$option='raa';$dbuser='fixture-owner';$dbpasswd='fixture-owner-password';
    $lang=array('Removing_admins'=>'Removing','raa_success'=>'Completed');
    $source=file_get_contents($root.'admin/erc.php');$execute=strpos($source,"case 'execute':");
    $a=strpos($source,"case 'raa':",$execute);$b=strpos($source,"case 'mua':",$a);
    ercr_check($a!==false&&$b>$a,'Actual role controller');$code="switch('raa'){".substr($source,$a,$b-$a).'}';
    function ercr_sql($sql){ercr_check(mysqli_query($GLOBALS['peer'],$sql)!==false,'Fixture SQL failed');}
    function ercr_rows(){
        $r=mysqli_query($GLOBALS['peer'],'SELECT user_id,username,user_level,user_active,user_password FROM fixture_users ORDER BY user_id');
        $rows=array();while($row=mysqli_fetch_assoc($r)){$rows[(int)$row['user_id']]=$row;}mysqli_free_result($r);return $rows;
    }
    function ercr_reset($alias='Admin',$method='board'){
        global $db,$HTTP_POST_VARS,$dbuser,$dbpasswd;
        $db->hook=null;$db->failure='';$db->lostAck=false;$db->queries=array();
        foreach(array('users','members','auth','groups','forums') as $table){ercr_sql('DELETE FROM fixture_'.$table);}
        ercr_sql("INSERT INTO fixture_users VALUES (-1,'Anonymous','',0,0),(2,'Admin','".md5('fixture-password')."',1,1),(3,'Other admin','',1,1),(4,'Moderator admin','',1,1),(5,'Pending admin','',1,1),(6,'Member','',1,0),(7,'Special','',1,3)");
        ercr_sql('INSERT INTO fixture_groups VALUES (10)');ercr_sql('INSERT INTO fixture_forums VALUES (20)');
        ercr_sql('INSERT INTO fixture_auth VALUES (10,20,1)');ercr_sql('INSERT INTO fixture_members VALUES (4,10,0),(5,10,1)');
        $HTTP_POST_VARS=array('auth_method'=>$method,'board_user'=>$alias,'board_password'=>'fixture-password','db_user'=>$dbuser,'db_password'=>$dbpasswd);
    }
    function ercr_run(){
        global $db,$HTTP_POST_VARS,$lang,$code;$GLOBALS['ercr_success']=false;$error='';ob_start();
        try{eval($code);}catch(ErcRoleFailure $e){$error=$e->getMessage();}finally{$html=ob_get_clean();}
        return array($GLOBALS['ercr_success'],$error,$html);
    }
    $cases=0;
    foreach(array('InnoDB','MyISAM') as $engine){
        foreach(array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INTEGER,user_level INTEGER',
            'members'=>'user_id INTEGER,group_id INTEGER,user_pending INTEGER','auth'=>'group_id INTEGER,forum_id INTEGER,auth_mod INTEGER',
            'groups'=>'group_id INTEGER PRIMARY KEY','forums'=>'forum_id INTEGER PRIMARY KEY') as $table=>$columns){
            ercr_sql('DROP TABLE IF EXISTS fixture_'.$table);ercr_sql('CREATE TABLE fixture_'.$table.' ('.$columns.') ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        foreach(array('Admin','ADMIN','admin') as $alias){
            ercr_reset($alias);$before=ercr_rows();$out=ercr_run();$after=ercr_rows();
            ercr_check($out[0]&&$out[1]==='','Authorized controller success');
            ercr_check($after[2]===$before[2],'Authenticated actor survives database-resolved alias '.$alias);
            ercr_check((int)$after[3]['user_level']===0&&(int)$after[4]['user_level']===2&&(int)$after[5]['user_level']===0,'Only other administrators demoted according to current moderator rights');
            foreach(array(-1,6,7) as $id){ercr_check($after[$id]===$before[$id],'Non-admin accounts unchanged');}$cases++;
        }
        ercr_reset('Admin','db');$out=ercr_run();$after=ercr_rows();
        ercr_check($out[0]&&(int)$after[2]['user_level']===0,'Explicit database owner may also remove the board administrator');$cases++;
        ercr_reset('ADMIN');ercr_sql("UPDATE fixture_users SET username='Admín' WHERE user_id=2");$before=ercr_rows();$out=ercr_run();
        ercr_check($out[0]&&ercr_rows()[2]===$before[2],'Accent-insensitive database identity preserves the actual actor');$cases++;
        ercr_reset();$db->hook=function($sql,$connection){
            if(strpos($sql,'UPDATE fixture_users SET user_level')!==0){return;}$connection->hook=null;
            ercr_sql("UPDATE fixture_users SET username='ADMIN' WHERE user_id=2");
        };$out=ercr_run();ercr_check($out[0]&&(int)ercr_rows()[2]['user_level']===1,'Case-only rename remains a valid database-resolved credential');$cases++;
        // Read/write failures and a lost acknowledgement must never continue
        // into another account or claim that all administrators were processed.
        foreach(array('read','write','ack') as $failure){
            ercr_reset();$before=ercr_rows();$db->failure=$failure==='read'?"SELECT user_id, username\n":'UPDATE fixture_users SET user_level';
            $db->lostAck=$failure==='ack';$out=ercr_run();$after=ercr_rows();
            ercr_check(!$out[0]&&$out[1]!=='','Failed role operation is not reported successful');
            if($failure==='ack'){
                ercr_check((int)$after[3]['user_level']===0,'Lost ACK can leave only the completed first target');
                $after[3]['user_level']=$before[3]['user_level'];
            }
            ercr_check($after===$before,'No following or speculative role writes on failure');$cases++;
        }
        foreach(array('demoted','inactive','password','actor-renamed','actor-replaced') as $race){
            ercr_reset();$injected=null;
            $db->hook=function($sql,$connection)use($race,&$injected){
                if(strpos($sql,'UPDATE fixture_users SET user_level')!==0){return;}$connection->hook=null;
                if($race==='demoted'){ercr_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');}
                elseif($race==='inactive'){ercr_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
                elseif($race==='password'){ercr_sql("UPDATE fixture_users SET user_password='independent-password' WHERE user_id=2");}
                elseif($race==='actor-renamed'){ercr_sql("UPDATE fixture_users SET username='Independent rename' WHERE user_id=2");}
                else{ercr_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=2');}
                $injected=ercr_rows();
            };
            $out=ercr_run();
            ercr_check($injected!==null&&!$out[0]&&$out[1]!=='','Changed actor interrupts remaining work: '.$race);
            ercr_check(ercr_rows()===$injected,'No role write after actor revocation: '.$race);$cases++;
        }
        foreach(array('promoted-special','already-demoted','deleted','grant-added','grant-removed','pending','invalid-pending','orphan-group','orphan-forum') as $race){
            ercr_reset();$target=$race==='grant-added'?3:4;$injected=null;
            $db->hook=function($sql,$connection)use($race,$target,&$injected){
                if(strpos($sql,'UPDATE fixture_users SET user_level')!==0||strpos($sql,' WHERE user_id = '.$target.' ')===false){return;}$connection->hook=null;
                if($race==='promoted-special'){ercr_sql('UPDATE fixture_users SET user_level=3 WHERE user_id='.$target);}
                elseif($race==='already-demoted'){ercr_sql('UPDATE fixture_users SET user_level=0 WHERE user_id='.$target);}
                elseif($race==='deleted'){ercr_sql('DELETE FROM fixture_users WHERE user_id='.$target);}
                elseif($race==='grant-added'){ercr_sql('INSERT INTO fixture_members VALUES (3,10,0)');}
                elseif($race==='grant-removed'){ercr_sql('DELETE FROM fixture_auth');}
                elseif($race==='orphan-group'){ercr_sql('DELETE FROM fixture_groups');}
                elseif($race==='orphan-forum'){ercr_sql('DELETE FROM fixture_forums');}
                else{ercr_sql('UPDATE fixture_members SET user_pending='.($race==='pending'?1:2).' WHERE user_id='.$target);}
                $injected=ercr_rows();
            };
            $out=ercr_run();$after=ercr_rows();ercr_check($injected!==null&&$out[0]&&$out[1]==='','Current target policy completes: '.$race);
            ercr_check((int)$after[2]['user_level']===1,'Actor stays administrator during target changes');
            if($race==='deleted'){ercr_check(!isset($after[$target]),'Deleted target is not recreated');}
            else{ercr_check((int)$after[$target]['user_level']===($race==='promoted-special'?3:($race==='grant-added'?2:0)),'Dispatch uses current target and valid ACLs: '.$race);}
            $cases++;
        }
        ercr_reset();$injected=null;$db->hook=function($sql,$connection)use(&$injected){
            if(strpos($sql,'UPDATE fixture_users SET user_level')!==0||strpos($sql,' WHERE user_id = 4 ')===false){return;}$connection->hook=null;
            ercr_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');$injected=ercr_rows();
        };$out=ercr_run();$after=ercr_rows();
        ercr_check($injected!==null&&!$out[0]&&$out[1]!==''&&$after===$injected&&(int)$after[3]['user_level']===0&&(int)$after[4]['user_level']===1,'Revocation stops later targets without undoing independently completed changes');$cases++;
        ercr_reset();$before=ercr_rows();$out=dbmtnc_erc_remove_administrator(2,2);
        ercr_check($out===0&&ercr_rows()===$before,'Helper itself cannot demote its actor');$cases++;
        foreach(array(array(-1,2),array(0,2),array('3',2),array(3,null),array(3,-1),array(3,8)) as $invalid){
            ercr_reset();$before=ercr_rows();ercr_check(dbmtnc_erc_remove_administrator($invalid[0],$invalid[1])===false&&ercr_rows()===$before,'Invalid target/changed actor binding refuses writes');$cases++;
        }
        ercr_reset('Admin','db');$HTTP_POST_VARS['db_password']='wrong';$before=ercr_rows();
        ercr_check(dbmtnc_erc_remove_administrator(3,0)===false&&ercr_rows()===$before,'Database-owner path still requires valid credentials');$cases++;
    }
    echo "ERC roles: $cases native actor-identity, current-policy, failure and dispatch-race cases passed.\n";
} finally {
    if($native){mysqli_close($native);}if($peer){mysqli_close($peer);}
    ercr_check(mysqli_query($control,'DROP DATABASE '.$schema),'Owned fixture cleanup failed');mysqli_close($control);
}
