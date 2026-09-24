<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STYLE_LOAD_NATIVE')!=='1'){return;}
define('IN_PHPBB',true);define('THEMES_TABLE','fixture_themes');define('USERS_TABLE','fixture_users');define('CRITICAL_ERROR',1);define('GENERAL_ERROR',2);
$root=dirname(dirname(__DIR__)).'/phpBB2/';require $root.'includes/php_compat.php';
// Keep the actual loader/cache/config validators. Replace only the terminal
// full-page error renderer with the throwing boundary below for assertions.
$function_source=file_get_contents($root.'includes/functions.php');
$function_source=str_replace('function message_die(', 'function unused_style_fixture_message_die(', $function_source, $renderer_count);
if($renderer_count!==1){throw new RuntimeException('Actual error-rendering boundary missing');}eval(substr($function_source,5));
$category_source=file_get_contents($root.'includes/functions_categories_hierarchy.php');
if(preg_match('/function cache_themes\(\)\s*\{.*?^\}/ms',$category_source,$cache_match)!==1){throw new RuntimeException('Actual theme cache loader missing');}eval($cache_match[0]);
function style_load_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class StyleLoadFailure extends RuntimeException {}
function message_die($code,$message){throw new StyleLoadFailure($message);}
class Template {public $root;function __construct($root){$this->root=$root;}}
class StyleLoadDatabase
{
    public $native,$active=array(),$queries=array(),$fail='';
    function __construct($native){$this->native=$native;}
    function sql_query($sql)
    {
        $this->queries[]=$sql;
        style_load_check(preg_match('/^\s*SELECT\b/i',$sql)===1,'Display-only style loading must not mutate database rows');
        if($this->fail!==''&&strpos($sql,$this->fail)!==false){return false;}
        $r=mysqli_query($this->native,$sql);style_load_check($r!==false,'Native read failed');$this->active[spl_object_hash($r)]=true;return $r;
    }
    function sql_fetchrow($r){$row=mysqli_fetch_array($r);return is_array($row)?$row:false;}
    function sql_freeresult($r){unset($this->active[spl_object_hash($r)]);mysqli_free_result($r);}
}
mysqli_report(MYSQLI_REPORT_OFF);$port=getenv('PHPBB_STYLE_LOAD_PORT')?:'3306';$password=getenv('PHPBB_STYLE_LOAD_PASSWORD')?:'';
style_load_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<65536,'Invalid fixture port');
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);style_load_check($control!==false,'Loopback fixture unavailable');
$schema='codex_style_load_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));style_load_check(mysqli_query($control,'CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema creation');
$native=$peer=null;$phpbb_root_path=sys_get_temp_dir().'/'.$schema.'/';
try
{
    style_load_check(mkdir($phpbb_root_path)&&mkdir($phpbb_root_path.'cache')&&mkdir($phpbb_root_path.'templates')&&mkdir($phpbb_root_path.'templates/fisubsilversh')&&mkdir($phpbb_root_path.'templates/fisubsilversh/images'),'Owned template fixture');
    foreach(array('english','german') as $locale){mkdir($phpbb_root_path.'templates/fisubsilversh/images/lang_'.$locale);}
    copy($root.'templates/fisubsilversh/fisubsilversh.cfg',$phpbb_root_path.'templates/fisubsilversh/fisubsilversh.cfg');
    set_error_handler(function($severity,$message){
        // Each case loads the unmodified real template config; only its known
        // repeated constant declaration is ignored within this multi-call test.
        if(strpos($message,'Constant TEMPLATE_CONFIG already defined')!==false){return true;}
        if(error_reporting()&$severity){throw new RuntimeException($message);}
    });
    $native=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);style_load_check($native&&$peer,'Independent fixture connections');mysqli_set_charset($native,'utf8mb4');mysqli_set_charset($peer,'utf8mb4');$db=new StyleLoadDatabase($native);
    function style_load_sql($sql){style_load_check(mysqli_query($GLOBALS['peer'],$sql)!==false,'Fixture mutation failed');}
    function style_load_users(){return mysqli_fetch_all(mysqli_query($GLOBALS['peer'],'SELECT user_id,user_style FROM fixture_users ORDER BY user_id'),MYSQLI_ASSOC);}
    function style_load_reset()
    {
        global $db,$board_config,$themes_style,$images,$phpbb_root_path;
        style_load_check(!$db->active,'Every prior result is released');$db->queries=array();$db->fail='';$board_config=array('default_style'=>'1','default_lang'=>'german');$themes_style=array();$images=array();
        style_load_sql('DELETE FROM fixture_themes');style_load_sql('DELETE FROM fixture_users');
        style_load_sql("INSERT INTO fixture_themes VALUES(1,'fisubsilversh','FI Subsilver Shadow','fisubsilversh.css','123456',1)");style_load_sql('INSERT INTO fixture_users VALUES(2,9),(3,9),(4,1)');
        if(is_file($phpbb_root_path.'cache/themes.cache')){unlink($phpbb_root_path.'cache/themes.cache');}
    }
    function style_load_run($id,$failure=false)
    {
        global $db,$native,$template,$images,$phpbb_root_path;$before=style_load_users();$caught=false;$row=null;
        style_load_check(mysqli_query($native,'START TRANSACTION READ ONLY'),'Read-only display transaction');
        try{$row=setup_style($id);}catch(StyleLoadFailure $error){$caught=true;}finally{mysqli_query($native,'ROLLBACK');}
        style_load_check($caught===$failure,'Expected style lookup outcome');style_load_check(style_load_users()===$before,'Page rendering never rewrites other users preferences');style_load_check(!$db->active,'All database results are released');
        if(!$failure){style_load_check($row['template_name']==='fisubsilversh'&&$row['head_stylesheet']==='fisubsilversh.css'&&$template->root===$phpbb_root_path.'templates/fisubsilversh','Only preserved template loads');}
        return $row;
    }
    $cases=0;
    foreach(array(false,true) as $cached)
    {
        if($cached){define('CACHE_THEMES',true);}
        foreach(array('InnoDB','MyISAM') as $engine)
        {
            style_load_sql('DROP TABLE IF EXISTS fixture_themes');style_load_sql('DROP TABLE IF EXISTS fixture_users');
            style_load_sql('CREATE TABLE fixture_themes(themes_id INT PRIMARY KEY,template_name VARCHAR(30),style_name VARCHAR(30),head_stylesheet VARCHAR(100),body_bgcolor VARCHAR(6),theme_public INT) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4');style_load_sql('CREATE TABLE fixture_users(user_id INT PRIMARY KEY,user_style INT) ENGINE='.$engine);
            style_load_reset();$row=style_load_run(9);style_load_check($row['themes_id']===1&&$row['body_bgcolor']==='123456','Missing former style falls back without account writes');$cases++;
            style_load_reset();$row=style_load_run(1);style_load_check($row['themes_id']===1&&strpos($images['root_lang'],'lang_german')!==false,'Standard style and German image map load');$cases++;
            style_load_reset();$board_config['default_lang']='unavailable';style_load_run(1);style_load_check(strpos($images['root_lang'],'lang_english')!==false,'Missing image locale falls back to English');$cases++;
            foreach(array(null,array(1),'1 OR 1=1','-1','0',0,'99999999999999999999999') as $invalid){style_load_reset();$row=style_load_run($invalid);style_load_check($row['themes_id']===1,'Malformed/obsolete preference uses board default safely');$cases++;}
            style_load_reset();style_load_sql('DELETE FROM fixture_themes');style_load_run(9,true);$cases++;
            style_load_reset();$db->fail='FROM fixture_themes';style_load_run(9,true);$cases++;
            style_load_reset();$board_config['default_style']=array('1');style_load_run(9,true);$cases++;
            style_load_reset();$board_config['default_style']='invalid';$row=style_load_run(1);style_load_check($row['themes_id']===1,'Valid selected style remains usable with a damaged default');$cases++;
            style_load_reset();$row=style_load_run('1');$lookups=array_filter($db->queries,function($sql){return strpos($sql,'WHERE themes_id = 1')!==false;});style_load_check(count($lookups)<=1,'Identical selected/default IDs do not cause duplicate reads');$cases++;
            if($cached)
            {
                foreach(array(array(1=>'bad'),array(1=>array('themes_id'=>2)),array()) as $cache){style_load_reset();phpbb_data_cache_write($phpbb_root_path.'cache/themes.cache',$cache);$row=style_load_run(9);style_load_check($row['themes_id']===1,'Invalid/empty cache cannot block valid database fallback');$cases++;}
                style_load_reset();phpbb_data_cache_write($phpbb_root_path.'cache/themes.cache',array(1=>array('themes_id'=>1,'body_bgcolor'=>'abcdef')));$row=style_load_run(9);style_load_check($row['themes_id']===1,'Cached default supports obsolete user style');$cases++;
            }
        }
    }
    echo "Style loading: $cases native read-only fallback/cache cases passed.\n";
}
finally
{
    restore_error_handler();if($native){mysqli_close($native);}if($peer){mysqli_close($peer);}style_load_check(mysqli_query($control,'DROP DATABASE '.$schema),'Owned schema cleanup');mysqli_close($control);
    foreach(array('cache/themes.cache','templates/fisubsilversh/fisubsilversh.cfg') as $file){if(is_file($phpbb_root_path.$file)){unlink($phpbb_root_path.$file);}}
    foreach(array('templates/fisubsilversh/images/lang_english','templates/fisubsilversh/images/lang_german','templates/fisubsilversh/images','templates/fisubsilversh','templates','cache','') as $dir){if(is_dir($phpbb_root_path.$dir)){rmdir($phpbb_root_path.$dir);}}
}
