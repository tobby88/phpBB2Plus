<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_acl_storage.php';
require_once dirname(__FILE__).'/functions_forum_read_snapshot.php';
require_once dirname(__FILE__).'/functions_mail_delivery.php';
require_once dirname(__FILE__).'/emailer.php';

class PhpbbMassMailFailure extends RuntimeException
{
    var$recipients=0;var$batches=0;var$attempted=false;var$uncertain=false;
}
function phpbb_mass_mail_fail($key='Mass_mail_unavailable'){throw new PhpbbMassMailFailure($key);}
function phpbb_mass_mail_group($value)
{
    if((is_string($value)||is_int($value))&&(string)$value==='-1'){return -1;}
    if(!(is_string($value)||is_int($value))||preg_match('/^[1-9][0-9]{0,7}$/D',(string)$value)!==1||(float)$value>16777215){phpbb_mass_mail_fail('Mass_mail_group_invalid');}
    return(int)$value;
}
function phpbb_mass_mail_payload($request)
{
    if(!is_array($request)){phpbb_mass_mail_fail('Mail_invalid');}
    try{$subject=phpbb_mail_text(phpbb_mail_input($request,'subject'),255,true);$message=phpbb_mail_text(phpbb_mail_input($request,'message'),1048576);}
    catch(PhpbbMailFailure$error){phpbb_mass_mail_fail($error->getMessage());}
    $group=phpbb_mass_mail_group(isset($request[POST_GROUPS_URL])?$request[POST_GROUPS_URL]:null);
    return array('subject'=>$subject,'message'=>$message,'group'=>$group);
}
function phpbb_mass_mail_context($request)
{
    global$userdata;
    if(!is_array($request)||!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!PhpbbForumReadSnapshot::valid_actor($userdata)||empty($userdata['session_admin'])||!isset($request['sid'])||!is_string($request['sid'])||!hash_equals($userdata['session_id'],$request['sid'])){phpbb_mass_mail_fail('Session_invalid');}
    return array('id'=>(int)$userdata['user_id'],'sid'=>$userdata['session_id']);
}
function phpbb_mass_mail_address($value)
{
    if(!is_string($value)){return false;}$value=trim($value,' ');
    if($value===''||strlen($value)>254||preg_match('/[\x00-\x20\x7f-\xff]/',$value)||!filter_var($value,FILTER_VALIDATE_EMAIL)){return false;}
    $at=strrpos($value,'@');return array('address'=>$value,'key'=>substr($value,0,$at+1).strtolower(substr($value,$at+1)));
}

// Serialize only this administrator's campaigns. No database transaction or
// forum-wide mutation lock is held while the external transport runs.
class PhpbbMassMailLease
{
    private$connection=null;private$identity;private$schema;private$lock;
    function __construct($database,$actor)
    {
        $owned=false;$candidate=null;
        try{
            if(!is_object($database)||!method_exists($database,'sql_dedicated_connection')){phpbb_mass_mail_fail();}
            $source=$this->probe($database);$this->schema=$source['schema_name'];$candidate=$database->sql_dedicated_connection();
            if(!is_object($candidate)||$candidate===$database||empty($candidate->db_connect_id)||$candidate->db_connect_id===$database->db_connect_id){phpbb_mass_mail_fail();}
            $target=$this->probe($candidate);
            if($target['schema_name']!==$this->schema||(string)$target['id']===(string)$source['id']||(string)$target['automatic']!=='1'||$target['charset']!=='utf8mb4'){phpbb_mass_mail_fail();}
            $this->lock='massmail:'.md5($this->schema."\0".USERS_TABLE."\0".$actor['id']);
            foreach(array($this->lock,'attachment:'.md5($this->schema."\0".ATTACHMENTS_TABLE))as$name){$rows=$this->read($candidate,"SELECT IS_USED_LOCK('$name') owner");if(count($rows)!==1||(string)$rows[0]['owner']===(string)$target['id']){phpbb_mass_mail_fail();}}
            if(!$candidate->sql_query('SET TRANSACTION READ ONLY')){phpbb_mass_mail_fail();}$owned=true;
            $this->connection=$candidate;$this->identity=(string)$target['id'];register_shutdown_function(array($this,'release'));
            $rows=$this->read($candidate,"SELECT GET_LOCK('".$this->lock."',0) acquired");if(count($rows)!==1||(string)$rows[0]['acquired']!=='1'){phpbb_mass_mail_fail('Mass_mail_busy');}
        }catch(Exception$error){if($owned){$this->release();}throw$error;}catch(Error$error){if($owned){$this->release();}throw$error;}
    }
    private function read($database,$sql){$r=$database->sql_query($sql);if(!$r){phpbb_mass_mail_fail();}try{$rows=$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}if(!is_array($rows)){phpbb_mass_mail_fail();}return$rows;}
    private function probe($database){$rows=$this->read($database,'SELECT CONNECTION_ID() id,DATABASE() schema_name,@@SESSION.autocommit automatic,@@SESSION.character_set_connection charset');if(count($rows)!==1||!is_string($rows[0]['schema_name'])||$rows[0]['schema_name']===''||!preg_match('/^[1-9][0-9]*$/D',(string)$rows[0]['id'])){phpbb_mass_mail_fail();}return$rows[0];}
    function verify(){if(!$this->connection){phpbb_mass_mail_fail();}$state=$this->probe($this->connection);$held=$this->read($this->connection,"SELECT IS_USED_LOCK('".$this->lock."') owner");if($state['schema_name']!==$this->schema||(string)$state['id']!==$this->identity||count($held)!==1||(string)$held[0]['owner']!==$this->identity){phpbb_mass_mail_fail();}}
    function release(){$connection=$this->connection;$this->connection=null;if($connection){try{$connection->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}}
}

function phpbb_mass_mail_snapshot($database)
{
    return new PhpbbForumReadSnapshot($database,array(CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,USER_GROUP_TABLE));
}
function phpbb_mass_mail_authority($reader,$context)
{
    global$userdata,$phpEx;
    if(!PhpbbForumReadSnapshot::valid_actor($userdata)||(int)$userdata['user_id']!==$context['id']||$userdata['session_id']!==$context['sid']){phpbb_mass_mail_fail('Not_Authorised');}
    try{$actor=phpbb_acp_actor($reader,'admin_mass_email.'.$phpEx);}catch(PhpbbAclException$error){phpbb_mass_mail_fail('Not_Authorised');}
    if((string)$actor['user_active']!=='1'){phpbb_mass_mail_fail('Not_Authorised');}
    $sid=$reader->sql_escape($context['sid']);$sessions=$reader->rows('SELECT s.session_time,c.config_value session_length FROM '.SESSIONS_TABLE.' s JOIN '.CONFIG_TABLE." c ON c.config_name='session_length' AND HEX(c.config_name)=HEX('session_length') WHERE s.session_id='$sid' AND HEX(s.session_id)=HEX('$sid') AND s.session_user_id=".$context['id'].' AND s.session_logged_in=1 AND s.session_admin=1');
    if(count($sessions)!==1||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$sessions[0]['session_length'])||(float)$sessions[0]['session_length']>2147483647||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$sessions[0]['session_time'])||(float)$sessions[0]['session_time']>2147483647||(float)$sessions[0]['session_time']<time()-(int)$sessions[0]['session_length']){phpbb_mass_mail_fail('Session_invalid');}
    $users=$reader->rows('SELECT username FROM '.USERS_TABLE.' WHERE user_id='.$context['id']);if(count($users)!==1||!is_string($users[0]['username'])||strpos($users[0]['username'],"\0")!==false||preg_match('//u',$users[0]['username'])!==1){phpbb_mass_mail_fail();}
    return preg_replace('/[\x01-\x1f\x7f]+/',' ',html_entity_decode($users[0]['username'],ENT_QUOTES,'UTF-8'));
}
function phpbb_mass_mail_scope($reader,$group)
{
    if($group!==-1){$rows=$reader->rows('SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id='.$group.' AND group_single_user=0');if(count($rows)!==1){phpbb_mass_mail_fail('Mass_mail_group_invalid');}}
    return $group===-1?' FROM '.USERS_TABLE.' u WHERE u.user_id>0':' FROM '.USERS_TABLE.' u JOIN '.USER_GROUP_TABLE.' m ON m.user_id=u.user_id AND m.group_id='.$group.' AND m.user_pending=0 WHERE u.user_id>0';
}
function phpbb_mass_mail_settings($reader)
{
    $keys=array('smtp_delivery','smtp_host','smtp_username','smtp_password','board_email','server_name','sitename','default_lang','sendmail_fix');$values=array();
    foreach($reader->rows('SELECT config_name,config_value FROM '.CONFIG_TABLE." WHERE config_name IN ('".implode("','",$keys)."')")as$row){if(!in_array($row['config_name'],$keys,true)||isset($values[$row['config_name']])||!is_string($row['config_value'])||strpos($row['config_value'],"\0")!==false||preg_match('//u',$row['config_value'])!==1){phpbb_mass_mail_fail();}$values[$row['config_name']]=$row['config_value'];}
    if(count($values)!==count($keys)||!in_array($values['smtp_delivery'],array('0','1'),true)||!in_array($values['sendmail_fix'],array('0','1'),true)||!phpbb_mass_mail_address($values['board_email'])||!preg_match('/^[a-z_]{1,30}$/D',$values['default_lang'])){phpbb_mass_mail_fail();}
    ksort($values,SORT_STRING);return$values;
}
function phpbb_mass_mail_frame($database,$context,$group,$ids=null)
{
    $reader=null;
    try{
        $reader=phpbb_mass_mail_snapshot($database);$name=phpbb_mass_mail_authority($reader,$context);$scope=phpbb_mass_mail_scope($reader,$group);
        if($ids===null){$rows=$reader->rows('SELECT DISTINCT u.user_id,u.user_email'.$scope.' ORDER BY u.user_id');$result=array();foreach($rows as$row){$id=$row['user_id'];if(!(is_int($id)||is_string($id))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$id)||(int)$id>8388607){phpbb_mass_mail_fail();}if(phpbb_mass_mail_address($row['user_email'])){$result[]=(int)$id;}}return$result;}
        if(!is_array($ids)||!$ids||count($ids)>500){phpbb_mass_mail_fail();}foreach($ids as$id){if(!is_int($id)||$id<1||$id>8388607){phpbb_mass_mail_fail();}}
        $rows=$reader->rows('SELECT DISTINCT u.user_id,u.user_email'.$scope.' AND u.user_id IN ('.implode(',',$ids).') ORDER BY u.user_id');$targets=array();foreach($rows as$row){$targets[]=array('id'=>(string)$row['user_id'],'email'=>$row['user_email']);}
        return array('name'=>$name,'settings'=>phpbb_mass_mail_settings($reader),'targets'=>$targets);
    }finally{if($reader){$reader->release();}}
}

function phpbb_send_mass_mail($database,$request,$factory)
{
    global$board_config;
    $lease=null;$sent=$batches=0;$attempted=$inflight=false;$previous=$board_config;
    try{
        $context=phpbb_mass_mail_context($request);$data=phpbb_mass_mail_payload($request);
        $packed=isset($_SERVER['REMOTE_ADDR'])&&is_string($_SERVER['REMOTE_ADDR'])?@inet_pton($_SERVER['REMOTE_ADDR']):false;if($packed===false){phpbb_mass_mail_fail();}$ip=inet_ntop($packed);
        $lease=new PhpbbMassMailLease($database,$context);$ids=phpbb_mass_mail_frame($database,$context,$data['group']);$seen=array();
        for($start=0;$start<count($ids);$start+=500){
            $lease->verify();$chunk=array_slice($ids,$start,500);$frame=phpbb_mass_mail_frame($database,$context,$data['group'],$chunk);$targets=array();
            foreach($frame['targets']as$row){$address=phpbb_mass_mail_address($row['email']);if($address&&!isset($seen[$address['key']])){$targets[$address['key']]=$address['address'];}}
            if(!$targets){continue;}
            $board_config=array_merge($previous,$frame['settings']);$mailer=call_user_func($factory,$frame['settings']['smtp_delivery']);if(!($mailer instanceof emailer)){phpbb_mass_mail_fail();}
            $sender=phpbb_mass_mail_address($frame['settings']['board_email']);$mailer->from($sender['address']);$mailer->replyto($sender['address']);$mailer->email_address($sender['address']);
            foreach($targets as$key=>$address){if($key!==$sender['key']){$mailer->bcc($address);}}
            $mailer->use_template('admin_send_email',$frame['settings']['default_lang']);$mailer->set_subject($data['subject']);$mailer->assign_vars(array('SITENAME'=>$frame['settings']['sitename'],'BOARD_EMAIL'=>$sender['address'],'MESSAGE'=>$data['message']));
            $server=preg_replace('/[\x00-\x1f\x7f]+/',' ',$frame['settings']['server_name']);$mailer->extra_headers('X-AntiAbuse: Board servername - '.$server."\nX-AntiAbuse: User_id - ".$context['id']."\nX-AntiAbuse: Username - ".$frame['name']."\nX-AntiAbuse: User IP - ".$ip."\n");
            // Preparation can read templates and invoke a factory. Use a new
            // snapshot afterwards, then release all SQL locks before transport.
            $lease->verify();if(phpbb_mass_mail_context($request)!==$context||phpbb_mass_mail_frame($database,$context,$data['group'],$chunk)!==$frame){phpbb_mass_mail_fail('Mass_mail_source_changed');}
            $attempted=$inflight=true;if($mailer->send()!==true){phpbb_mass_mail_fail('Mass_mail_delivery_failed');}$inflight=false;$sent+=count($targets);$batches++;foreach($targets as$key=>$address){$seen[$key]=true;}$mailer->reset();
        }
        if(!$sent){phpbb_mass_mail_fail('Mass_mail_no_recipients');}
        return array('recipients'=>$sent,'batches'=>$batches);
    }catch(Exception$error){$failure=$error instanceof PhpbbMassMailFailure?$error:new PhpbbMassMailFailure('Mass_mail_unavailable',0,$error);$failure->recipients=$sent;$failure->batches=$batches;$failure->attempted=$attempted;$failure->uncertain=$inflight;throw$failure;}
    catch(Error$error){$failure=new PhpbbMassMailFailure('Mass_mail_unavailable',0,$error);$failure->recipients=$sent;$failure->batches=$batches;$failure->attempted=$attempted;$failure->uncertain=$inflight;throw$failure;}
    finally{$board_config=$previous;if($lease){$lease->release();}}
}
class PhpbbMassMailController
{
    function create_mailer($smtp){return new emailer($smtp,true);}
    function send($database,$request){return phpbb_send_mass_mail($database,$request,array($this,'create_mailer'));}
}
