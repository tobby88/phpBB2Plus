<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_board_config.php';
require_once dirname(__FILE__).'/functions_icon_config.php';

class PhpbbIconConfigWriter extends PhpbbBoardConfigWriter
{
    var $identity='';var $schema='';var $finished=false;
    function __construct($database)
    {
        $source=phpbb_acl_rows(new PhpbbAclDatabase($database,'Icons_storage_failed'),'SELECT CONNECTION_ID() icon_connection,DATABASE() icon_schema');
        if(count($source)!==1||!is_string($source[0]['icon_schema'])||$source[0]['icon_schema']===''||!isset($database->dbname)||$source[0]['icon_schema']!==$database->dbname){phpbb_acl_error('Icons_storage_failed');}
        $this->schema=$source[0]['icon_schema'];$this->failure_key='Icons_storage_failed';$owned=false;$candidate=null;
        if(!method_exists($database,'sql_dedicated_connection')){phpbb_acl_error('Icons_storage_failed');}
        try{
            $candidate=$database->sql_dedicated_connection();if(!$candidate||!$candidate->db_connect_id){phpbb_acl_error('Icons_storage_failed');}
            $probe=new PhpbbAclDatabase($candidate,'Icons_storage_failed');
            $target=phpbb_acl_rows($probe,'SELECT CONNECTION_ID() icon_connection,DATABASE() icon_schema,@@SESSION.autocommit automatic,@@SESSION.character_set_connection charset');
            if(count($target)!==1||(string)$target[0]['icon_connection']===(string)$source[0]['icon_connection']||(string)$target[0]['automatic']!=='1'){phpbb_acl_error('Icons_storage_failed');}
            $lock='attachment:'.md5($database->dbname."\0".ATTACHMENTS_TABLE);$lease=phpbb_acl_rows($probe,"SELECT IS_USED_LOCK('$lock') owner");
            if(count($lease)!==1||(string)$lease[0]['owner']===(string)$target[0]['icon_connection']){phpbb_acl_error('Icons_storage_failed');}
            // This portable guard refuses an active factory participant without
            // committing, rolling back or closing somebody else's work.
            $probe->sql_query('SET TRANSACTION READ WRITE');$owned=true;
            if($target[0]['icon_schema']!==$this->schema||$target[0]['charset']!=='utf8mb4'){phpbb_acl_error('Icons_storage_failed');}
            $this->connection=$candidate;$this->identity=(string)$target[0]['icon_connection'];register_shutdown_function(array($this,'release'));
            $rows=phpbb_acl_rows($this,"SELECT GET_LOCK('$lock',10) acquired");
            if(count($rows)!==1||(string)$rows[0]['acquired']!=='1'){phpbb_acl_error('Icons_storage_failed');}
            // Bound waits only on this independent owner. A locked external
            // writer or DDL must not hold the shared forum lease indefinitely.
            (new PhpbbAclDatabase($this->connection,'Icons_storage_failed'))->sql_query('SET SESSION lock_wait_timeout=5,innodb_lock_wait_timeout=5');
        }catch(Exception$error){if($owned){if($this->connection){$this->release();}else{$candidate->sql_close();}}throw$error;}
        catch(Error$error){if($owned){if($this->connection){$this->release();}else{$candidate->sql_close();}}throw$error;}
    }
    function current_identity()
    {
        if(!$this->connection){phpbb_acl_error('Icons_storage_failed');}$rows=phpbb_acl_rows(new PhpbbAclDatabase($this->connection,'Icons_storage_failed'),'SELECT CONNECTION_ID() icon_connection,DATABASE() icon_schema');
        if(count($rows)!==1||$rows[0]['icon_schema']!==$this->schema){phpbb_acl_error('Icons_storage_failed');}return(string)$rows[0]['icon_connection'];
    }
    function actor(){global$phpEx;return phpbb_acp_actor($this,'admin_icons.'.$phpEx);}
    function sql_query($sql,$transaction=false)
    {
        if($this->finished||!is_string($sql)||$this->current_identity()!==$this->identity){phpbb_acl_error('Icons_storage_failed');}
        if(in_array($sql,array('SHOW FULL COLUMNS FROM '.POST_ICON_CONFIG_TABLE,'SHOW INDEX FROM '.POST_ICON_CONFIG_TABLE,'SHOW FULL COLUMNS FROM '.POSTS_TABLE,'SHOW FULL COLUMNS FROM '.TOPICS_TABLE),true)){
            if(!$this->transactional){phpbb_acl_error('Icons_storage_failed');}
            return (new PhpbbAclDatabase($this->connection,'Icons_storage_failed'))->sql_query($sql,$transaction);
        }
        if(preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql)){
            if(!$this->transactional||!(strpos($sql,'UPDATE '.POST_ICON_CONFIG_TABLE.' SET config_value=')===0||strpos($sql,'UPDATE '.POSTS_TABLE.' SET post_icon=')===0||strpos($sql,'UPDATE '.TOPICS_TABLE.' SET topic_icon=')===0)){phpbb_acl_error('Icons_storage_failed');}
        }
        $result=parent::sql_query($sql,$transaction);if($sql==='COMMIT'||$sql==='ROLLBACK'){$this->finished=true;}return$result;
    }
}
function phpbb_icon_admin_change($database,$operation,$request)
{
    global$userdata,$lang;
    if(!is_array($request)||!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||empty($userdata['session_id'])||!is_string($userdata['session_id'])||!isset($request['sid'])||!is_string($request['sid'])||!hash_equals($userdata['session_id'],$request['sid'])){phpbb_acl_error('Session_invalid');}
    if(!in_array($operation,array('save','delete','up','down'),true)){phpbb_acl_error('Icons_invalid');}
    $db=new PhpbbIconConfigWriter($database);
    try{
        $db->actor();$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");$db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->sql_query('START TRANSACTION');
        foreach(array(POST_ICON_CONFIG_TABLE,POSTS_TABLE,TOPICS_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE)as$table){
            $r=$db->sql_query('SELECT * FROM '.$table.' LIMIT 0');$db->sql_freeresult($r);$literal="'".$db->sql_escape($table)."'";
            $meta=phpbb_acl_rows($db,'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('.$literal.')');
            $columns=phpbb_acl_rows($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX(".$literal.") AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if(count($meta)!==1||$meta[0]['ENGINE']!=='InnoDB'||strtolower($meta[0]['ROW_FORMAT'])!=='dynamic'||$meta[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'||$columns){phpbb_acl_error('Icons_storage_failed');}
        }
        if(!phpbb_icon_storage_shape(phpbb_acl_rows($db,'SHOW FULL COLUMNS FROM '.POST_ICON_CONFIG_TABLE),phpbb_acl_rows($db,'SHOW INDEX FROM '.POST_ICON_CONFIG_TABLE))){phpbb_acl_error('Icons_storage_failed');}
        foreach(array(POSTS_TABLE=>'post_icon',TOPICS_TABLE=>'topic_icon')as$table=>$name){$found=false;foreach(phpbb_acl_rows($db,'SHOW FULL COLUMNS FROM '.$table)as$column){if($column['Field']===$name&&$column['Extra']===''&&phpbb_icon_reference_type($column['Type'])){$found=true;}}if(!$found){phpbb_acl_error('Icons_storage_failed');}}
        $rows=phpbb_acl_rows($db,'SELECT config_id,schema_version,config_value FROM '.POST_ICON_CONFIG_TABLE.' ORDER BY config_id LIMIT 2 FOR UPDATE');
        if(count($rows)!==1||(string)$rows[0]['config_id']!=='1'||(string)$rows[0]['schema_version']!=='1'||($config=phpbb_icon_config_decode($rows[0]['config_value']))===false){phpbb_acl_error('Icons_storage_failed');}
        try{$transition=phpbb_icon_transition($config,$operation,$request,$lang);}catch(InvalidArgumentException$error){phpbb_acl_error($error->getMessage());}
        $actor=$db->actor();$sid=$db->sql_escape($userdata['session_id']);
        foreach(array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id'])as$sql){$r=$db->sql_query($sql.' LOCK IN SHARE MODE');$db->sql_freeresult($r);}
        $actor=$db->actor();
        if($transition['removed']!==null){
            foreach(array(POSTS_TABLE=>'post_icon',TOPICS_TABLE=>'topic_icon')as$table=>$field){
                $db->sql_query('UPDATE '.$table.' SET '.$field.'='.$transition['replacement'].' WHERE '.$field.'='.$transition['removed'].' AND '.$actor['guard']);
                if(phpbb_acl_rows($db,'SELECT '.$field.' FROM '.$table.' WHERE '.$field.'='.$transition['removed'].' LIMIT 1')){phpbb_acl_error('Icons_storage_failed');}
            }
        }
        $bytes=serialize($transition['config']);
        if($transition['config']!==$config){$db->sql_query('UPDATE '.POST_ICON_CONFIG_TABLE." SET config_value='".$db->sql_escape($bytes)."' WHERE config_id=1 AND schema_version=1 AND ".$actor['guard']);
            $stored=phpbb_acl_rows($db,'SELECT config_value FROM '.POST_ICON_CONFIG_TABLE.' WHERE config_id=1');if(count($stored)!==1||$stored[0]['config_value']!==$bytes){phpbb_acl_error('Icons_storage_failed');}}
        $db->sql_query('COMMIT');
    }finally{$db->release();}
    return$transition['config'];
}
function phpbb_admin_icons_read($current=null)
{
    global$icones,$icon_defined_special,$map_icon;
    if($current!==null){if(!phpbb_icon_config_valid($current)){phpbb_acl_error('Icons_storage_failed');}$icones=$current['icons'];$icon_defined_special=$current['special'];}
    elseif(!phpbb_load_icon_config(true)){phpbb_acl_error('Icons_storage_failed');}
    $map_icon=array();foreach($icones as$i=>$icon){$map_icon[$icon['ind']]=$i;}
}
