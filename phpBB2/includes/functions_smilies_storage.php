<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_board_config.php';

function admin_smiley_request_string($source,$key)
{
    return isset($source[$key])&&is_scalar($source[$key])?trim((string)$source[$key]):'';
}
function admin_smiley_request_int($source,$key)
{
    return isset($source[$key])&&is_scalar($source[$key])?max(0,intval($source[$key])):0;
}
function admin_smiley_html($value)
{
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function phpbb_smiley_id($value)
{
    if(!(is_string($value)||is_int($value))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)||(float)$value>2147483647){phpbb_acl_error('Smilies_selection_changed');}
    return(int)$value;
}
function phpbb_smiley_text($value,$limit,$code=false)
{
    if(!is_string($value)||strlen($value)>4*$limit||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)){phpbb_acl_error('Smilies_invalid');}
    $value=trim($value);if($code){$value=str_replace(array('<','>'),array('&lt;','&gt;'),$value);}
    if(($code&&$value==='')||preg_match_all('/./us',$value,$matches)>$limit){phpbb_acl_error('Smilies_invalid');}
    return$value;
}
function phpbb_smiley_boolean($request,$key)
{
    if(!array_key_exists($key,$request)){return false;}$value=$request[$key];if(!(is_string($value)||is_int($value))||!in_array((string)$value,array('0','1'),true)){phpbb_acl_error('Smilies_invalid');}return(string)$value==='1';
}
function phpbb_smiley_folder($db,$root)
{
    $rows=phpbb_acl_rows($db,'SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='smilies_path' AND HEX(config_name)=HEX('smilies_path') LOCK IN SHARE MODE");
    if(count($rows)!==1||!is_string($rows[0]['config_value'])||$rows[0]['config_value']===''||strpos($rows[0]['config_value'],"\0")!==false){phpbb_acl_error('Smilies_storage_failed');}
    $root=realpath($root);$relative=str_replace('\\','/',$rows[0]['config_value']);
    if($root===false||preg_match('#^(?:/|[A-Za-z]:)|(?:^|/)\.\.(?:/|$)#',$relative)){phpbb_acl_error('Smilies_storage_failed');}
    $folder=realpath($root.'/'.$relative);$prefix=str_replace('\\','/',$root).'/';$candidate=$folder===false?'':str_replace('\\','/',$folder).'/';
    if(DIRECTORY_SEPARATOR==='\\'){$prefix=strtolower($prefix);$candidate=strtolower($candidate);}
    if($folder===false||!is_dir($folder)||strpos($candidate,$prefix)!==0){phpbb_acl_error('Smilies_storage_failed');}return$folder;
}
function phpbb_smiley_asset($folder,$name,$pak=false)
{
    $limit=$pak?128:100;
    if(!is_string($name)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,'.($limit-1).'}$/D',$name)||($pak&&!preg_match('/\.pak$/iD',$name))){phpbb_acl_error('Smilies_invalid');}
    $path=$folder.'/'.$name;clearstatcache(true,$path);
    if(is_link($path)||!is_file($path)||!is_readable($path)){phpbb_acl_error('Smilies_invalid');}
    if($pak){if(filesize($path)>262144){phpbb_acl_error('Smilies_invalid');}}
    else{$size=@getimagesize($path);if(!is_array($size)||empty($size[0])||empty($size[1])){phpbb_acl_error('Smilies_invalid');}}
    return$path;
}
function phpbb_smiley_pack($folder,$name)
{
    $path=phpbb_smiley_asset($folder,$name,true);$bytes=file_get_contents($path);
    if(!is_string($bytes)||strlen($bytes)>262144||strpos($bytes,"\0")!==false||preg_match('//u',$bytes)!==1){phpbb_acl_error('Smilies_invalid');}
    $text=substr($bytes,0,3)==="\xef\xbb\xbf"?substr($bytes,3):$bytes;$lines=preg_split('/\r\n|\r|\n/',$text);$entries=array();$line_count=0;$assets=array();
    foreach($lines as$line){$line=trim($line);if($line===''){continue;}if(++$line_count>5000){phpbb_acl_error('Smilies_invalid');}$parts=explode('=+:',$line);if(count($parts)<3){phpbb_acl_error('Smilies_invalid');}
        $image=trim($parts[0]);if(!isset($assets[$image])){phpbb_smiley_asset($folder,$image);$assets[$image]=true;}$emotion=phpbb_smiley_text($parts[1],75);
        for($i=2;$i<count($parts);$i++){$entries[]=array('code'=>phpbb_smiley_text($parts[$i],50,true),'smile_url'=>$image,'emoticon'=>$emotion);if(count($entries)>10000){phpbb_acl_error('Smilies_invalid');}}
    }
    if(!$entries){phpbb_acl_error('Smilies_invalid');}return array('entries'=>$entries,'path'=>$path,'sha256'=>hash('sha256',$bytes),'assets'=>array_keys($assets));
}
class PhpbbSmiliesWriter extends PhpbbBoardConfigWriter
{
    var$identity='';var$schema='';var$finished=false;
    function __construct($database)
    {
        $source=phpbb_acl_rows(new PhpbbAclDatabase($database,'Smilies_storage_failed'),'SELECT CONNECTION_ID() smile_connection,DATABASE() smile_schema');
        if(count($source)!==1||!is_string($source[0]['smile_schema'])||$source[0]['smile_schema']===''||!isset($database->dbname)||$database->dbname!==$source[0]['smile_schema']||!method_exists($database,'sql_dedicated_connection')){phpbb_acl_error('Smilies_storage_failed');}
        $this->schema=$source[0]['smile_schema'];$this->failure_key='Smilies_storage_failed';$owned=false;$candidate=null;
        try{
            $candidate=$database->sql_dedicated_connection();if(!$candidate||!$candidate->db_connect_id){phpbb_acl_error('Smilies_storage_failed');}$probe=new PhpbbAclDatabase($candidate,'Smilies_storage_failed');
            $target=phpbb_acl_rows($probe,'SELECT CONNECTION_ID() smile_connection,DATABASE() smile_schema,@@SESSION.autocommit automatic,@@SESSION.character_set_connection charset');
            if(count($target)!==1||(string)$target[0]['smile_connection']===(string)$source[0]['smile_connection']||(string)$target[0]['automatic']!=='1'){phpbb_acl_error('Smilies_storage_failed');}
            $lock='attachment:'.md5($database->dbname."\0".ATTACHMENTS_TABLE);$lease=phpbb_acl_rows($probe,"SELECT IS_USED_LOCK('$lock') owner");if(count($lease)!==1||(string)$lease[0]['owner']===(string)$target[0]['smile_connection']){phpbb_acl_error('Smilies_storage_failed');}
            $probe->sql_query('SET TRANSACTION READ WRITE');$owned=true;
            if($target[0]['smile_schema']!==$this->schema||$target[0]['charset']!=='utf8mb4'){phpbb_acl_error('Smilies_storage_failed');}
            $this->connection=$candidate;$this->identity=(string)$target[0]['smile_connection'];register_shutdown_function(array($this,'release'));
            $rows=phpbb_acl_rows($this,"SELECT GET_LOCK('$lock',10) acquired");if(count($rows)!==1||(string)$rows[0]['acquired']!=='1'){phpbb_acl_error('Smilies_storage_failed');}
            (new PhpbbAclDatabase($this->connection,'Smilies_storage_failed'))->sql_query('SET SESSION lock_wait_timeout=5,innodb_lock_wait_timeout=5');
        }catch(Exception$e){if($owned){if($this->connection){$this->release();}else{$candidate->sql_close();}}throw$e;}catch(Error$e){if($owned){if($this->connection){$this->release();}else{$candidate->sql_close();}}throw$e;}
    }
    function current_identity()
    {
        if(!$this->connection){phpbb_acl_error('Smilies_storage_failed');}$rows=phpbb_acl_rows(new PhpbbAclDatabase($this->connection,'Smilies_storage_failed'),'SELECT CONNECTION_ID() smile_connection,DATABASE() smile_schema');
        if(count($rows)!==1||$rows[0]['smile_schema']!==$this->schema){phpbb_acl_error('Smilies_storage_failed');}return(string)$rows[0]['smile_connection'];
    }
    function actor(){global$phpEx;return phpbb_acp_actor($this,'admin_smilies.'.$phpEx);}
    function sql_query($sql,$transaction=false)
    {
        if($this->finished||!is_string($sql)||$this->current_identity()!==$this->identity){phpbb_acl_error('Smilies_storage_failed');}
        if(in_array($sql,array('SHOW FULL COLUMNS FROM '.SMILIES_TABLE,'SHOW INDEX FROM '.SMILIES_TABLE),true)){
            if(!$this->transactional){phpbb_acl_error('Smilies_storage_failed');}return(new PhpbbAclDatabase($this->connection,'Smilies_storage_failed'))->sql_query($sql,$transaction);
        }
        if(preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql)){
            if(!$this->transactional||!(strpos($sql,'INSERT INTO '.SMILIES_TABLE.' (code,smile_url,emoticon) SELECT ')===0||strpos($sql,'UPDATE '.SMILIES_TABLE.' SET code=')===0||strpos($sql,'DELETE FROM '.SMILIES_TABLE.' WHERE ')===0)){phpbb_acl_error('Smilies_storage_failed');}
            $this->actor();return(new PhpbbAclDatabase($this->connection,'Smilies_storage_failed'))->sql_query($sql,$transaction);
        }
        $result=parent::sql_query($sql,$transaction);if($sql==='COMMIT'||$sql==='ROLLBACK'){$this->finished=true;}return$result;
    }
}
function phpbb_smiley_storage_capacity($db)
{
    $capacity=null;$fields=array();foreach(phpbb_acl_rows($db,'SHOW FULL COLUMNS FROM '.SMILIES_TABLE)as$c){$fields[$c['Field']]=$c;}
    foreach(array('code'=>50,'smile_url'=>100,'emoticon'=>75)as$key=>$length){if(!isset($fields[$key])||!preg_match('/^varchar\(([0-9]+)\)$/iD',$fields[$key]['Type'],$size)||(int)$size[1]<$length||$fields[$key]['Collation']!=='utf8mb4_unicode_ci'||$fields[$key]['Extra']!==''){phpbb_acl_error('Smilies_storage_failed');}}
    if(!isset($fields['smilies_id'])||stripos($fields['smilies_id']['Extra'],'auto_increment')===false){phpbb_acl_error('Smilies_storage_failed');}
    $type=preg_replace('/\([0-9]+\)/','',strtolower($fields['smilies_id']['Type']));$limits=array('smallint unsigned'=>65535,'smallint'=>32767,'mediumint unsigned'=>16777215,'mediumint'=>8388607,'int'=>2147483647,'int unsigned'=>2147483647,'bigint'=>2147483647,'bigint unsigned'=>2147483647);
    if(!isset($limits[$type])){phpbb_acl_error('Smilies_storage_failed');}$capacity=$limits[$type];$primary=array();foreach(phpbb_acl_rows($db,'SHOW INDEX FROM '.SMILIES_TABLE)as$index){if($index['Key_name']==='PRIMARY'){$primary[]=$index;}}
    if(count($primary)!==1||$primary[0]['Column_name']!=='smilies_id'||$primary[0]['Sub_part']!==null){phpbb_acl_error('Smilies_storage_failed');}return$capacity;
}
function phpbb_smiley_rows($db,$sql)
{
    // The legacy driver returns both numeric and named copies of every field.
    // Compare the complete named state without retaining stale numeric copies.
    $rows=phpbb_acl_rows($db,$sql);
    foreach($rows as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);
    return$rows;
}
function phpbb_smilies_admin_change($database,$operation,$request,$root)
{
    global$userdata;
    if(!is_array($request)||!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||empty($userdata['session_id'])||!is_string($userdata['session_id'])||!isset($request['sid'])||!is_string($request['sid'])||!hash_equals($userdata['session_id'],$request['sid'])){phpbb_acl_error('Session_invalid');}
    if(!in_array($operation,array('add','save','delete','import'),true)){phpbb_acl_error('Smilies_invalid');}
    $db=new PhpbbSmiliesWriter($database);
    try{
        $db->actor();$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");$db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->sql_query('START TRANSACTION');
        foreach(array(SMILIES_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE)as$table){$r=$db->sql_query('SELECT * FROM '.$table.' LIMIT 0');$db->sql_freeresult($r);$literal="'".$db->sql_escape($table)."'";
            $meta=phpbb_acl_rows($db,'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('.$literal.')');
            $legacy=phpbb_acl_rows($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX(".$literal.") AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if(count($meta)!==1||$meta[0]['ENGINE']!=='InnoDB'||strtolower($meta[0]['ROW_FORMAT'])!=='dynamic'||$meta[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'||$legacy){phpbb_acl_error('Smilies_storage_failed');}
        }
        $capacity=phpbb_smiley_storage_capacity($db);$rows=phpbb_smiley_rows($db,'SELECT * FROM '.SMILIES_TABLE.' ORDER BY smilies_id FOR UPDATE');$by_id=$by_code=array();
        foreach($rows as$row){$by_id[(int)$row['smilies_id']]=$row;if($row['code']!==null){$by_code['code:'.$row['code']][]=(int)$row['smilies_id'];}}
        $folder=null;$pack=null;$entries=array();$id=null;$clear=false;$replace=false;
        if($operation==='delete'){if(empty($request['confirm'])||!is_string($request['confirm'])){phpbb_acl_error('Smilies_invalid');}$id=phpbb_smiley_id(isset($request['id'])?$request['id']:null);}
        elseif($operation==='import'){$folder=phpbb_smiley_folder($db,$root);$name=isset($request['smile_pak'])?phpbb_request_raw_value($request['smile_pak']):null;$pack=phpbb_smiley_pack($folder,$name);$entries=$pack['entries'];$clear=phpbb_smiley_boolean($request,'clear_current');$replace=phpbb_smiley_boolean($request,'replace');}
        else{
            $folder=phpbb_smiley_folder($db,$root);if($operation==='save'){$id=phpbb_smiley_id(isset($request['smile_id'])?$request['smile_id']:null);}
            $entry=array('code'=>phpbb_smiley_text(isset($request['smile_code'])?phpbb_request_raw_value($request['smile_code']):null,50,true),'smile_url'=>isset($request['smile_url'])?phpbb_request_raw_value($request['smile_url']):null,'emoticon'=>phpbb_smiley_text(isset($request['smile_emotion'])?phpbb_request_raw_value($request['smile_emotion']):null,75));phpbb_smiley_asset($folder,$entry['smile_url']);$entries[]=$entry;
        }
        if($id!==null&&!isset($by_id[$id])){phpbb_acl_error('Smilies_selection_changed');}
        $targets=array();$known=$clear?array():$by_code;$needed=0;
        foreach($entries as$entry){$key='code:'.$entry['code'];if($operation==='save'){$targets[]=array('ids'=>array($id),'entry'=>$entry);}
            elseif($operation==='add'||!isset($known[$key])){$targets[]=array('ids'=>null,'entry'=>$entry);$known[$key]=array();$needed++;}
            elseif($replace){$targets[]=array('ids'=>$known[$key],'entry'=>$entry);}
        }
        if($needed){$meta=phpbb_acl_rows($db,'SELECT AUTO_INCREMENT next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX(\''.$db->sql_escape(SMILIES_TABLE).'\')');
            if(count($meta)!==1||!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$meta[0]['next_id'])||(float)$meta[0]['next_id']>$capacity-$needed+1){phpbb_acl_error('Smilies_capacity');}}
        $actor=$db->actor();$sid=$db->sql_escape($userdata['session_id']);foreach(array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id'])as$sql){$r=$db->sql_query($sql.' LOCK IN SHARE MODE');$db->sql_freeresult($r);}$actor=$db->actor();
        if($clear){$db->sql_query('DELETE FROM '.SMILIES_TABLE.' WHERE '.$actor['guard']);$by_id=array();$by_code=array();}
        if($operation==='delete'){$db->sql_query('DELETE FROM '.SMILIES_TABLE.' WHERE smilies_id='.$id.' AND '.$actor['guard']);unset($by_id[$id]);}
        foreach($targets as$target){$entry=$target['entry'];$key='code:'.$entry['code'];$ids=$target['ids'];if($ids!==null&&!$ids){$ids=isset($by_code[$key])?$by_code[$key]:array();}
            $code=$db->sql_escape($entry['code']);$image=$db->sql_escape($entry['smile_url']);$emotion=$db->sql_escape($entry['emoticon']);
            if($ids===null){$db->sql_query('INSERT INTO '.SMILIES_TABLE." (code,smile_url,emoticon) SELECT '$code','$image','$emotion' WHERE ".$actor['guard']);$new=$db->sql_nextid();if(!(is_int($new)||is_string($new))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$new)||(float)$new>$capacity||isset($by_id[(int)$new])){phpbb_acl_error('Smilies_storage_failed');}$new=(int)$new;$actual=phpbb_smiley_rows($db,'SELECT * FROM '.SMILIES_TABLE.' WHERE smilies_id='.$new);if(count($actual)!==1){phpbb_acl_error('Smilies_storage_failed');}$by_id[$new]=$actual[0];foreach($entry as$field=>$value){if($by_id[$new][$field]!==$value){phpbb_acl_error('Smilies_storage_failed');}}$by_code[$key][]=$new;}
            else{if(!$ids){phpbb_acl_error('Smilies_storage_failed');}foreach($ids as$target_id){$db->sql_query('UPDATE '.SMILIES_TABLE." SET code='$code',smile_url='$image',emoticon='$emotion' WHERE smilies_id=".$target_id.' AND '.$actor['guard']);foreach($entry as$field=>$value){$by_id[$target_id][$field]=$value;}}}
        }
        $after=phpbb_smiley_rows($db,'SELECT * FROM '.SMILIES_TABLE.' ORDER BY smilies_id');$expected=array_values($by_id);usort($expected,function($a,$b){return(int)$a['smilies_id']-(int)$b['smilies_id'];});if($after!==$expected){phpbb_acl_error('Smilies_storage_failed');}
        if($pack!==null){$path=phpbb_smiley_asset($folder,$name,true);if(hash_file('sha256',$path)!==$pack['sha256']){phpbb_acl_error('Smilies_source_changed');}foreach($pack['assets']as$image){phpbb_smiley_asset($folder,$image);}}
        elseif($folder!==null){phpbb_smiley_asset($folder,$entries[0]['smile_url']);}
        $db->sql_query('COMMIT');
    }finally{$db->release();}
    return true;
}
