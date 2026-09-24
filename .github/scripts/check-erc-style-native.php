<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_STYLE_NATIVE') !== '1') { return; }
set_time_limit(120); // Bound regressions such as the PHP 5.6 cleanup-loop case.
define('IN_PHPBB',true);define('ADMIN',1);define('USERS_TABLE','fixture_users');define('CONFIG_TABLE','fixture_config');define('THEMES_TABLE','fixture_themes');define('ATTACHMENTS_TABLE','fixture_attachments');
$root=dirname(dirname(__DIR__)).'/phpBB2/';require $root.'includes/php_compat.php';
$source=file_get_contents($root.'includes/functions_dbmtnc.php');$a=strpos($source,'function check_authorisation(');$b=strpos($source,'function success_message(',$a);
if($a===false||$b<=$a||eval(substr($source,$a,$b-$a))===false){throw new RuntimeException('Actual ERC functions unavailable');}
if(is_file($root.'includes/functions_maintenance_style.php')){require $root.'includes/functions_maintenance_style.php';}
function ercs_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class ErcStyleFailure extends RuntimeException {}
function erc_throw_error($message){throw new ErcStyleFailure($message);}
function success_message($message){$GLOBALS['ercs_message']=$message;}
class ErcStyleDatabase
{
    public $native,$dbname,$db_connect_id=true,$closed=false,$dedicated=false;
    function __construct($native,$dedicated=false){$this->native=$native;$this->dbname=$GLOBALS['schema'];$this->dedicated=$dedicated;}
    function sql_dedicated_connection()
    {
        $connection=mysqli_connect('127.0.0.1','root',$GLOBALS['password'],$this->dbname,(int)$GLOBALS['port']);ercs_check($connection!==false,'Owned connection opened');mysqli_set_charset($connection,'utf8mb4');
        $owned=new self($connection,true);$GLOBALS['style_state']->connections[]=$owned;return $owned;
    }
    function sql_query($sql)
    {
        $state=$GLOBALS['style_state'];$state->queries[]=$sql;if($this->closed){return false;}
        if($state->hook){$hook=$state->hook;$hook($sql,$this);}
        if($this->closed){return false;}
        $failed=$state->fail!==''&&strpos($sql,$state->fail)===0;if($failed&&!$state->lostAck){return false;}
        if(function_exists('dbmtnc_erc_reset_style')&&preg_match('/^(INSERT|UPDATE) /',$sql)){ercs_check($this->dedicated&&$state->owner===$this,'Every style write uses the owning connection');}
        $result=mysqli_query($this->native,$sql);ercs_check($result!==false,'Native query failed: '.mysqli_error($this->native));
        if(strpos($sql,'SELECT GET_LOCK(')===0){$row=mysqli_fetch_assoc($result);mysqli_data_seek($result,0);if((int)$row['acquired']===1){$state->owner=$this;}}
        return $failed?false:$result;
    }
    function sql_fetchrow($r){return mysqli_fetch_assoc($r);}
    function sql_freeresult($r){mysqli_free_result($r);}
    function sql_escape($value){return mysqli_real_escape_string($this->native,$value);}
    function sql_affectedrows(){return mysqli_affected_rows($this->native);}
    function sql_nextid(){return mysqli_insert_id($this->native);}
    function sql_close(){if(!$this->closed){mysqli_close($this->native);$this->closed=true;$this->db_connect_id=false;if($GLOBALS['style_state']->owner===$this){$GLOBALS['style_state']->owner=null;}}}
}
mysqli_report(MYSQLI_REPORT_OFF);$port=getenv('PHPBB_ERC_STYLE_PORT')?:'3306';$password=getenv('PHPBB_ERC_STYLE_PASSWORD')?:'';
ercs_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<65536,'Invalid fixture port');
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);ercs_check($control!==false,'Loopback fixture unavailable');
$schema='codex_erc_style_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));ercs_check(mysqli_query($control,'CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema creation');
$native=$peer=null;$phpbb_root_path=sys_get_temp_dir().'/'.$schema.'/';$style_state=(object)array('connections'=>array(),'owner'=>null,'queries'=>array(),'hook'=>null,'fail'=>'','lostAck'=>false);
try
{
    ercs_check(mkdir($phpbb_root_path)&&mkdir($phpbb_root_path.'cache')&&mkdir($phpbb_root_path.'templates')&&mkdir($phpbb_root_path.'templates/fisubsilversh'),'Owned file fixture');
    foreach(array('fisubsilversh.cfg','fisubsilversh.css','overall_header.tpl','overall_footer.tpl') as $file){file_put_contents($phpbb_root_path.'templates/fisubsilversh/'.$file,'fixture; never executed');}
    set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
    $native=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);ercs_check($native&&$peer,'Independent connections');mysqli_set_charset($native,'utf8mb4');mysqli_set_charset($peer,'utf8mb4');
    $db=new ErcStyleDatabase($native);$dbuser='fixture-owner';$dbpasswd='fixture-owner-password';$option='rtd';$phpEx='php';$lang=array('rtd_success'=>'saved','rtd_restore_success'=>'created','ERC_style_failed'=>'failed');
    $source=file_get_contents($root.'admin/erc.php');$e=strpos($source,"case 'execute':");$a=strpos($source,"case 'rtd':",$e);$b=strpos($source,"case 'dgc':",$a);ercs_check($a!==false&&$b>$a,'Actual style controller');$code="switch('rtd'){".substr($source,$a,$b-$a).'}';
    // Keep the production include but point its source at the checkout; all
    // writable runtime/cache/template files belong exclusively to this fixture.
    $code=str_replace("require_once(\$phpbb_root_path . 'includes/functions_maintenance_style.' . \$phpEx);",'require_once('.var_export($root.'includes/functions_maintenance_style.php',true).');',$code);
    $schema_source=file_get_contents($root.'install/schemas/mysql_schema.sql');ercs_check(preg_match('/CREATE TABLE phpbb_themes \(.*?;/s',$schema_source,$schema_match)===1,'Actual theme schema');
    $basic=file_get_contents($root.'install/schemas/mysql_basic.sql');ercs_check(preg_match('/INSERT INTO phpbb_themes \(.*?;/s',$basic,$basic_match)===1,'Actual standard theme defaults');$style_insert=str_replace('phpbb_themes','fixture_themes',$basic_match[0]);
    function ercs_sql($sql){ercs_check(mysqli_query($GLOBALS['peer'],$sql)!==false,'Fixture mutation failed: '.mysqli_error($GLOBALS['peer']));}
    function ercs_rows()
    {
        $out=array();foreach(array('users'=>'user_id','config'=>'config_name,config_value','themes'=>'themes_id') as $table=>$order){$r=mysqli_query($GLOBALS['peer'],'SELECT * FROM fixture_'.$table.' ORDER BY '.$order);$out[$table]=array();while($row=mysqli_fetch_assoc($r)){$out[$table][]=$row;}mysqli_free_result($r);}return $out;
    }
    function ercs_reset($method='select_theme')
    {
        global $style_state,$HTTP_POST_VARS,$phpbb_root_path,$board_config,$style_insert;
        ercs_check($style_state->owner===null,'Previous owner released');$style_state->hook=null;$style_state->fail='';$style_state->lostAck=false;$style_state->queries=array();
        ercs_sql('DELETE FROM fixture_users');ercs_sql('DELETE FROM fixture_config');ercs_sql('DELETE FROM fixture_themes');
        ercs_sql("INSERT INTO fixture_users VALUES(2,'Admin','".md5('Fixture!9')."',1,1,9),(3,'Other','',1,0,9)");ercs_sql("INSERT INTO fixture_config VALUES('default_style','9'),('unrelated','keep')");if($method==='select_theme'){ercs_sql($style_insert);}
        $HTTP_POST_VARS=array('auth_method'=>'board','board_user'=>'Admin','board_password'=>'Fixture!9','method'=>$method,'new_style'=>'1');$board_config=array('default_style'=>'9');
        foreach(array('config_data.cache','themes.cache','unrelated.cache') as $file){file_put_contents($phpbb_root_path.'cache/'.$file,'stale');}
    }
    function ercs_run()
    {
        global $db,$HTTP_POST_VARS,$lang,$code,$phpbb_root_path,$phpEx;$original=$db;$GLOBALS['ercs_message']='';$error='';ob_start();
        try{eval($code);}catch(ErcStyleFailure $e){$error=$e->getMessage();}finally{$html=ob_get_clean();}
        ercs_check($db===$original&&$GLOBALS['style_state']->owner===null,'Controller restores original connection and releases writer');return array($GLOBALS['ercs_message'],$error,$html);
    }
    $cases=0;
    foreach(array('InnoDB','MyISAM') as $engine)
    {
        foreach(array('users','config','themes') as $table){ercs_sql('DROP TABLE IF EXISTS fixture_'.$table);}
        ercs_sql('CREATE TABLE fixture_users(user_id INT PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INT,user_level INT,user_style INT) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ercs_sql('CREATE TABLE fixture_config(config_name VARCHAR(191),config_value TEXT) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');ercs_sql(str_replace(array('phpbb_themes','ENGINE=InnoDB'),array('fixture_themes','ENGINE='.$engine),$schema_match[0]));
        ercs_reset();$out=ercs_run();$saved=ercs_rows();ercs_check($out[0]==='saved'&&$saved['users'][0]['user_style']==='1'&&$saved['users'][1]['user_style']==='9'&&$saved['config'][0]['config_value']==='1','Select updates only resolved actor and board default');$cases++;
        foreach(array('select_theme','recreate_theme') as $method)
        {
            foreach(array('demoted','inactive','password','renamed','rebound') as $race)
            {
                ercs_reset($method);$injected=null;$prefix=$method==='select_theme'?'UPDATE fixture_users':'INSERT INTO fixture_themes';
                $style_state->hook=function($sql,$connection)use($prefix,$race,&$injected){
                    if(strpos($sql,$prefix)!==0){return;}$GLOBALS['style_state']->hook=null;
                    if($race==='demoted'){ercs_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');}elseif($race==='inactive'){ercs_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
                    elseif($race==='password'){ercs_sql("UPDATE fixture_users SET user_password='changed' WHERE user_id=2");}elseif($race==='renamed'){ercs_sql("UPDATE fixture_users SET username='Changed' WHERE user_id=2");}else{ercs_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=2');}$injected=ercs_rows();
                };
                $out=ercs_run();ercs_check($injected!==null&&$out[0]===''&&$out[1]!==''&&ercs_rows()===$injected,'Revoked actor cannot repair styles: '.$method.'/'.$race);$cases++;
            }
        }
        ercs_reset();$expected=ercs_rows()['themes'][0];unset($expected['themes_id']);
        ercs_reset('recreate_theme');$out=ercs_run();$saved=ercs_rows();$theme=$saved['themes'][0];$id=$theme['themes_id'];unset($theme['themes_id']);
        ercs_check($out[0]==='saved'&&strpos($out[2],'created')!==false&&count($saved['themes'])===1&&$theme===$expected&&$saved['users'][0]['user_style']===$id&&$saved['config'][0]['config_value']===$id,'Recreation exactly matches the actual installer defaults');
        ercs_check(!file_exists($phpbb_root_path.'cache/config_data.cache')&&!file_exists($phpbb_root_path.'cache/themes.cache')&&file_get_contents($phpbb_root_path.'cache/unrelated.cache')==='stale','Only both affected caches expire');$cases+=2;
        foreach(array('config_data.cache','themes.cache') as $file){file_put_contents($phpbb_root_path.'cache/'.$file,'stale');}
        $out=ercs_run();ercs_check($out[0]==='saved'&&strpos($out[2],'created')===false&&ercs_rows()===$saved&&!file_exists($phpbb_root_path.'cache/themes.cache'),'Repeated recreation reuses its row and refreshes caches');$cases++;
        ercs_reset();ercs_sql("UPDATE fixture_themes SET body_bgcolor='123456',style_name='Custom standard' WHERE themes_id=1");$before=ercs_rows();$HTTP_POST_VARS['method']='recreate_theme';$out=ercs_run();
        ercs_check($out[0]==='saved'&&ercs_rows()['themes']===$before['themes'],'Existing standard properties survive recovery');$cases++;
        foreach(array('select_theme','recreate_theme') as $method)
        {
            foreach(array('config-missing','config-duplicate','config-alias','theme-missing','theme-replaced','theme-private') as $race)
            {
                ercs_reset($method);$injected=null;
                $style_state->hook=function($sql,$connection)use($race,&$injected){
                    if(strpos($sql,'UPDATE fixture_users')!==0){return;}$GLOBALS['style_state']->hook=null;
                    if($race==='config-missing'){ercs_sql("DELETE FROM fixture_config WHERE config_name='default_style'");}elseif($race==='config-duplicate'){ercs_sql("INSERT INTO fixture_config VALUES('default_style','independent')");}elseif($race==='config-alias'){ercs_sql("UPDATE fixture_config SET config_name='DEFAULT_STYLE' WHERE config_name='default_style'");}
                    elseif($race==='theme-missing'){ercs_sql('DELETE FROM fixture_themes');}elseif($race==='theme-replaced'){ercs_sql("UPDATE fixture_themes SET template_name='other'");}else{ercs_sql('UPDATE fixture_themes SET theme_public=0');}$injected=ercs_rows();
                };
                $out=ercs_run();ercs_check($injected!==null&&$out[0]===''&&$out[1]!==''&&ercs_rows()===$injected,'Selected theme and exact config stay current at update dispatch: '.$race);$cases++;
            }
            foreach(array('read','write','ack','confirm','lock','disconnect') as $fault)
            {
                ercs_reset($method);$before=ercs_rows();$write=$method==='select_theme'?'UPDATE fixture_users':'INSERT INTO fixture_themes';
                if($fault==='read'){$style_state->fail='SELECT config_name';}elseif($fault==='lock'){$style_state->fail='SELECT GET_LOCK';}
                elseif($fault==='disconnect'){$style_state->hook=function($sql,$connection)use($write){if(strpos($sql,$write)===0){$GLOBALS['style_state']->hook=null;$connection->sql_close();}};}
                elseif($fault==='confirm'){$style_state->hook=function($sql,$connection)use($write){if(strpos($sql,$write)===0){$GLOBALS['style_state']->hook=null;$GLOBALS['style_state']->fail='SELECT user_id, username';}};}
                else{$style_state->fail=$write;$style_state->lostAck=$fault==='ack';}
                $out=ercs_run();$after=ercs_rows();ercs_check($out[0]===''&&$out[1]!=='','Unconfirmed repair is not successful: '.$method.'/'.$fault);
                if(!in_array($fault,array('ack','confirm'),true)){ercs_check($after===$before,'Failed dispatch cannot publish partial preference or theme');}
                $writes=array_filter($style_state->queries,function($sql){return preg_match('/^(INSERT|UPDATE) /',$sql);});ercs_check(count($writes)<=1,'No automatic retry after failed/unknown write');
                $style_state->fail='';$style_state->lostAck=false;$style_state->hook=null;$out=ercs_run();ercs_check($out[0]==='saved'&&count(ercs_rows()['themes'])===1,'Explicit retry does not duplicate recreated style');$cases++;
            }
        }
        ercs_reset('recreate_theme');$style_state->hook=function($sql,$connection){if(strpos($sql,'INSERT INTO fixture_themes')===0){$GLOBALS['style_state']->hook=null;ercs_sql($GLOBALS['style_insert']);ercs_sql("UPDATE fixture_themes SET body_bgcolor='abcdef'");}};
        $out=ercs_run();ercs_check($out[0]==='saved'&&strpos($out[2],'created')===false&&count(ercs_rows()['themes'])===1&&ercs_rows()['themes'][0]['body_bgcolor']==='abcdef','Independently restored row is reused, not overwritten or duplicated');$cases++;
        foreach(array('config_data.cache','themes.cache') as $file)
        {
            ercs_reset();unlink($phpbb_root_path.'cache/'.$file);mkdir($phpbb_root_path.'cache/'.$file);$out=ercs_run();$other=$file==='themes.cache'?'config_data.cache':'themes.cache';
            ercs_check($out[0]===''&&$out[1]!==''&&!file_exists($phpbb_root_path.'cache/'.$other)&&ercs_rows()['users'][0]['user_style']==='1','One cache failure still expires the other and releases writer');rmdir($phpbb_root_path.'cache/'.$file);$cases++;
        }
        foreach(array(null,array('1'),0,'0','01','-1','1e0','1.0','16777216',"1\0",'missing') as $id)
        {
            ercs_reset();$before=ercs_rows();$HTTP_POST_VARS['new_style']=$id;$out=ercs_run();ercs_check($out[0]===''&&$out[1]!==''&&ercs_rows()===$before,'Malformed style IDs cannot mutate storage');$cases++;
        }
        foreach(array('fisubsilversh.cfg','fisubsilversh.css','overall_header.tpl','overall_footer.tpl') as $file)
        {
            ercs_reset();$before=ercs_rows();unlink($phpbb_root_path.'templates/fisubsilversh/'.$file);$out=ercs_run();ercs_check($out[0]===''&&$out[1]!==''&&ercs_rows()===$before,'Incomplete preserved template cannot be selected');file_put_contents($phpbb_root_path.'templates/fisubsilversh/'.$file,'fixture');$cases++;
        }
        ercs_reset();ercs_sql("INSERT INTO fixture_themes(template_name,style_name,theme_public) VALUES('fisubsilversh','Duplicate',1)");$HTTP_POST_VARS['method']='recreate_theme';$before=ercs_rows();$out=ercs_run();
        ercs_check($out[0]===''&&$out[1]!==''&&ercs_rows()===$before,'Ambiguous recreation never guesses or deletes duplicates');$HTTP_POST_VARS['method']='select_theme';ercs_check(ercs_run()[0]==='saved','Explicit valid selection still works with duplicate standard rows');$cases+=2;
        ercs_reset();$locked=false;$style_state->hook=function($sql,$connection)use(&$locked){if(strpos($sql,'UPDATE fixture_users')!==0){return;}$GLOBALS['style_state']->hook=null;$name='attachment:'.md5($GLOBALS['schema']."\0".ATTACHMENTS_TABLE);$r=mysqli_query($GLOBALS['peer'],"SELECT GET_LOCK('".$name."',0) AS acquired");$row=mysqli_fetch_assoc($r);mysqli_free_result($r);$locked=(int)$row['acquired']===0;};
        ercs_check(ercs_run()[0]==='saved'&&$locked,'Independent connection cannot acquire the writer lock during publication');$cases++;
        ercs_reset();ercs_sql('UPDATE fixture_themes SET themes_id=0');$HTTP_POST_VARS['method']='recreate_theme';$before=ercs_rows();$out=ercs_run();ercs_check($out[0]===''&&$out[1]!==''&&ercs_rows()===$before,'Damaged zero theme ID is never installed as the default');$cases++;
        foreach(array('md5','bcrypt') as $scheme)
        {
            ercs_reset();$candidate=addslashes("Grüße'\\!9");$hash=$scheme==='md5'?md5($candidate):password_hash($candidate,PASSWORD_BCRYPT);ercs_sql("UPDATE fixture_users SET user_password='".mysqli_real_escape_string($peer,$hash)."' WHERE user_id=2");$HTTP_POST_VARS['board_user']='ADMIN';$HTTP_POST_VARS['board_password']=$candidate;
            ercs_check(ercs_run()[0]==='saved','Exact escaped board credential and database alias work on dedicated owner');$cases++;
        }
        foreach(array(null,'unknown',array('select_theme')) as $method)
        {
            ercs_reset();$before=ercs_rows();$HTTP_POST_VARS['method']=$method;$out=ercs_run();ercs_check($out[0]===''&&$out[1]!==''&&ercs_rows()===$before,'Invalid recovery mode does not write');$cases++;
        }
        foreach(array(null,-1,0,'2',8) as $actor)
        {
            ercs_reset();$before=ercs_rows();ercs_check(dbmtnc_erc_reset_style('select_theme','1',$actor)===false&&ercs_rows()===$before&&$style_state->owner===null,'Invalid/rebound/non-board actor does not write or retain lock');$cases++;
        }
    }
    echo "ERC style: $cases native actual-controller cases passed.\n";
}
finally
{
    restore_error_handler();foreach($style_state->connections as $connection){$connection->sql_close();}if($native){mysqli_close($native);}if($peer){mysqli_close($peer);}ercs_check(mysqli_query($control,'DROP DATABASE '.$schema),'Owned schema cleanup');mysqli_close($control);
    foreach(array('config_data.cache','themes.cache','unrelated.cache') as $file){$path=$phpbb_root_path.'cache/'.$file;if(is_file($path)){unlink($path);}elseif(is_dir($path)){rmdir($path);}}
    foreach(array('fisubsilversh.cfg','fisubsilversh.css','overall_header.tpl','overall_footer.tpl') as $file){$path=$phpbb_root_path.'templates/fisubsilversh/'.$file;if(is_file($path)){unlink($path);}}
    foreach(array('templates/fisubsilversh','templates','cache','') as $dir){if(is_dir($phpbb_root_path.$dir)){rmdir($phpbb_root_path.$dir);}}
}
