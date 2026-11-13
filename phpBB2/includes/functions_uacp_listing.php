<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_uacp_attachments.php';

class PhpbbUacpListReader
{
    private $connection=null;
    private $transaction=false;
    private $identity=null;

    private function fail($key='Uacp_attachment_list_failed'){throw new PhpbbOwnedStorageFailure($key);}
    function __construct($database)
    {
        $candidate=null;
        try{
            if(!is_object($database)||!method_exists($database,'sql_dedicated_connection')){$this->fail();}
            $source=$this->connection_identity($database);$candidate=$database->sql_dedicated_connection();
            if(!is_object($candidate)||$candidate===$database||empty($candidate->db_connect_id)||$candidate->db_connect_id===$database->db_connect_id){$this->fail();}
            $own=$this->connection_identity($candidate);
            if($own['schema_name']!==$source['schema_name']||$own['id']===$source['id']||$own['automatic']!=='1'){$this->fail();}
            // Refuse busy borrowed connections before taking ownership. Never
            // commit, roll back or close work which belongs to another caller.
            if(!$candidate->sql_query('SET TRANSACTION READ ONLY')){$this->fail();}
            $this->connection=$candidate;$candidate=null;$this->identity=$own['id'];
            $this->control('SET SESSION lock_wait_timeout=3');
            $this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->control('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');$this->transaction=true;
            foreach(array(CONFIG_TABLE,SESSIONS_TABLE,USERS_TABLE,FORUMS_TABLE,TOPICS_TABLE,POSTS_TABLE,AUTH_ACCESS_TABLE,USER_GROUP_TABLE,PRIVMSGS_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE)as$table){
                if(!is_string($table)||preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)!==1){$this->fail();}
                $r=$this->sql_query('SELECT * FROM '.$table.' LIMIT 0');$this->sql_freeresult($r);$name=$this->sql_escape($table);
                $meta=$this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$name') AND TABLE_TYPE='BASE TABLE'");
                $legacy=$this->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$name') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
                if(count($meta)!==1||$meta[0]['ENGINE']!=='InnoDB'||strtolower($meta[0]['ROW_FORMAT'])!=='dynamic'||$meta[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'||$legacy){$this->fail('Uacp_attachment_upgrade');}
            }
        }catch(Exception$error){$this->release();throw$error;}catch(Error$error){$this->release();$this->fail();}
    }
    private function connection_identity($database)
    {
        $r=$database->sql_query('SELECT CONNECTION_ID() id,DATABASE() schema_name,@@SESSION.autocommit automatic');if(!$r){$this->fail();}
        try{$rows=$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}
        if(!is_array($rows)||count($rows)!==1||!is_string($rows[0]['schema_name'])||$rows[0]['schema_name']===''||preg_match('/^[1-9][0-9]*$/D',(string)$rows[0]['id'])!==1){$this->fail();}
        return array('schema_name'=>$rows[0]['schema_name'],'id'=>(string)$rows[0]['id'],'automatic'=>isset($rows[0]['automatic'])?(string)$rows[0]['automatic']:'');
    }
    private function control($sql){if(!$this->connection){$this->fail();}$r=$this->connection->sql_query($sql);if(!$r){$this->fail();}return$r;}
    function sql_query($sql,$transaction=false)
    {
        if(!$this->transaction||!is_string($sql)||strpos($sql,'SELECT ')!==0||$transaction){$this->fail();}
        $r=$this->control('SELECT CONNECTION_ID() id');try{$row=$this->connection->sql_fetchrow($r);}finally{$this->connection->sql_freeresult($r);}
        if(!$row||(string)$row['id']!==$this->identity){$this->fail();}return$this->control($sql);
    }
    function rows($sql){$r=$this->sql_query($sql);try{$rows=$this->connection->sql_fetchrowset($r);}finally{$this->connection->sql_freeresult($r);}if(!is_array($rows)){$this->fail();}return$rows;}
    function sql_fetchrow($r){return$this->connection->sql_fetchrow($r);}
    function sql_fetchrowset($r){return$this->connection->sql_fetchrowset($r);}
    function sql_freeresult($r){return$this->connection->sql_freeresult($r);}
    function sql_escape($value){return$this->connection->sql_escape((string)$value);}
    function release()
    {
        $c=$this->connection;$this->connection=null;
        if($c){try{if($this->transaction){$c->sql_query('ROLLBACK');}}catch(Exception$ignored){}catch(Error$ignored){}$this->transaction=false;try{$c->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}
    }
}

function phpbb_uacp_page_size($value)
{
    return(is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]{0,3}$/D',(string)$value)===1&&(int)$value<=1000?(int)$value:25;
}
function phpbb_uacp_topic_label($stored)
{
    $plain=html_entity_decode((string)$stored,ENT_QUOTES,'UTF-8');
    if(preg_match('//u',$plain)===1&&preg_match('/^.{0,32}$/usD',$plain)!==1){$plain=phpbb_text_excerpt($plain,30);}
    return phpbb_profile_text($plain);
}
function phpbb_uacp_attachment_listing($database,$actor,$target,$mode,$order,$start,$size)
{
    $reader=null;
    try{
        if(!is_array($actor)){throw new PhpbbOwnedStorageFailure('Session_invalid');}
        foreach(array(isset($actor['user_id'])?$actor['user_id']:null,$target)as$id){if(!(is_int($id)||is_string($id))||preg_match('/^[1-9][0-9]{0,6}$/D',(string)$id)!==1||(float)$id>8388607){throw new PhpbbOwnedStorageFailure('Not_Authorised');}}
        $columns=array('real_filename'=>'real_filename','comment'=>'comment','extension'=>'extension','filesize'=>'filesize','downloads'=>'download_count','post_time'=>'filetime');
        if(!isset($actor['session_logged_in'])||!in_array($actor['session_logged_in'],array(true,1,'1'),true)||!isset($actor['session_id'])||!is_string($actor['session_id'])||$actor['session_id']===''||strlen($actor['session_id'])>32||strpos($actor['session_id'],"\0")!==false||!is_string($mode)||!isset($columns[$mode])||!in_array($order,array('ASC','DESC'),true)||!is_int($start)||$start<0||$start>1000000){throw new PhpbbOwnedStorageFailure('Session_invalid');}
        $size=phpbb_uacp_page_size($size);$target=(int)$target;$id=(int)$actor['user_id'];$reader=new PhpbbUacpListReader($database);$sid=$reader->sql_escape($actor['session_id']);
        $ttl=$reader->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length')");
        if(count($ttl)!==1||preg_match('/^[1-9][0-9]{0,9}$/D',(string)$ttl[0]['config_value'])!==1||(float)$ttl[0]['config_value']>2147483647){throw new PhpbbOwnedStorageFailure('Uacp_attachment_list_failed');}
        $session=$reader->rows('SELECT session_time FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1");
        if(count($session)!==1||preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$session[0]['session_time'])!==1||(float)$session[0]['session_time']<time()-(int)$ttl[0]['config_value']){throw new PhpbbOwnedStorageFailure('Session_invalid');}
        $users=$reader->rows('SELECT user_id,user_level,user_active,user_blocktime FROM '.USERS_TABLE.' WHERE user_id='.$id);
        if(count($users)!==1||(string)$users[0]['user_active']!=='1'||!in_array((string)$users[0]['user_level'],array((string)USER,(string)MOD,(string)ADMIN),true)||preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$users[0]['user_blocktime'])!==1||(float)$users[0]['user_blocktime']>=time()||$id!==$target&&(string)$users[0]['user_level']!==(string)ADMIN){throw new PhpbbOwnedStorageFailure('Not_Authorised');}
        $profile=$reader->rows('SELECT user_id,username FROM '.USERS_TABLE.' WHERE user_id='.$target);if(count($profile)!==1){throw new PhpbbOwnedStorageFailure('No_such_user');}
        $user=$users[0];$user['session_logged_in']=true;$user['session_id']=$actor['session_id'];$permissions=auth(AUTH_ALL,AUTH_LIST_ALL,$user,'',$reader);$forums=array();foreach($permissions as$fid=>$auth){if((int)$fid>0&&!empty($auth['auth_view'])&&!empty($auth['auth_read'])){$forums[]=(int)$fid;}}$forum_sql=$forums?'p.forum_id IN ('.implode(',',$forums).')':'1=0';
        $owned='(a.user_id_1='.$target.' OR a.user_id_2='.$target.')';
        $post_from=ATTACHMENTS_TABLE.' a JOIN '.POSTS_TABLE.' p ON p.post_id=a.post_id JOIN '.TOPICS_TABLE.' t ON t.topic_id=p.topic_id';
        $post_where=$owned.' AND a.post_id>0 AND a.privmsgs_id=0 AND p.poster_id='.$target.' AND p.post_deleted=0 AND p.forum_id=t.forum_id AND t.topic_moved_id=0 AND '.$forum_sql;
        $pm_from=ATTACHMENTS_TABLE.' a JOIN '.PRIVMSGS_TABLE.' p ON p.privmsgs_id=a.privmsgs_id';
        $pm_where=$owned.' AND a.post_id=0 AND a.privmsgs_id>0 AND p.privmsgs_write_payload IS NULL AND ((p.privmsgs_type IN ('.PRIVMSGS_READ_MAIL.','.PRIVMSGS_NEW_MAIL.','.PRIVMSGS_UNREAD_MAIL.','.PRIVMSGS_SAVED_IN_MAIL.') AND p.privmsgs_to_userid='.$target.') OR (p.privmsgs_type IN ('.PRIVMSGS_SENT_MAIL.','.PRIVMSGS_SAVED_OUT_MAIL.') AND p.privmsgs_from_userid='.$target.'))';
        $eligible='(EXISTS(SELECT 1 FROM '.$post_from.' WHERE a.attach_id=d.attach_id AND '.$post_where.') OR EXISTS(SELECT 1 FROM '.$pm_from.' WHERE a.attach_id=d.attach_id AND '.$pm_where.'))';
        $counts=$reader->rows('SELECT COUNT(*) total FROM '.ATTACHMENTS_DESC_TABLE.' d WHERE '.$eligible);
        if(count($counts)!==1||preg_match('/^(?:0|[1-9][0-9]{0,7})$/D',(string)$counts[0]['total'])!==1||(float)$counts[0]['total']>16777215){throw new PhpbbOwnedStorageFailure('Uacp_attachment_list_failed');}
        $total=(int)$counts[0]['total'];if(!$total){$start=0;}elseif($start>=$total){$start=(int)(floor(($total-1)/$size)*$size);}
        $rows=$reader->rows('SELECT d.attach_id,d.real_filename,d.comment,d.extension,d.filesize,d.download_count,d.filetime FROM '.ATTACHMENTS_DESC_TABLE.' d WHERE '.$eligible.' ORDER BY d.'.$columns[$mode].' '.$order.',d.attach_id '.$order.' LIMIT '.$start.','.$size);$by_id=array();foreach($rows as$i=>$row){$by_id[(int)$row['attach_id']]=$i;$rows[$i]['parents']=array();}
        if($by_id){
            $scope='a.attach_id IN ('.implode(',',array_keys($by_id)).')';
            foreach($reader->rows('SELECT DISTINCT a.attach_id,p.post_id,t.topic_title FROM '.$post_from.' WHERE '.$scope.' AND '.$post_where.' ORDER BY a.attach_id,p.post_id')as$parent){$rows[$by_id[(int)$parent['attach_id']]]['parents'][]=array('type'=>'post','post_id'=>(int)$parent['post_id'],'title'=>$parent['topic_title']);}
            foreach($reader->rows('SELECT DISTINCT a.attach_id,p.privmsgs_id,p.privmsgs_type,p.privmsgs_from_userid,p.privmsgs_to_userid FROM '.$pm_from.' WHERE '.$scope.' AND '.$pm_where.' ORDER BY a.attach_id,p.privmsgs_id')as$parent){$box=phpbb_uacp_pm_box($parent,$target);if($box===false){throw new PhpbbOwnedStorageFailure('Uacp_attachment_list_failed');}$rows[$by_id[(int)$parent['attach_id']]]['parents'][]=array('type'=>'pm','box'=>$box);}
        }
        foreach($rows as$row){if(!$row['parents']){throw new PhpbbOwnedStorageFailure('Uacp_attachment_list_failed');}}
        return array('user'=>$profile[0],'rows'=>$rows,'total'=>$total,'start'=>$start,'size'=>$size);
    }catch(PhpbbOwnedStorageFailure$error){throw$error;}catch(Exception$error){throw new PhpbbOwnedStorageFailure('Uacp_attachment_list_failed');}catch(Error$error){throw new PhpbbOwnedStorageFailure('Uacp_attachment_list_failed');}
    finally{if($reader){$reader->release();}}
}
