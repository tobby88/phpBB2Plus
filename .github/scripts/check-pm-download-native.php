<?php
// Actual download controller, installer tables and mysqli connections. Only the
// final header/file transport terminates in a sink; no file bytes or mail leave.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PM_NOTIFY_NATIVE')!=='1'){return;}
$pd_root_queries=array();$pd_root_fault='';$pd_after_extensions=null;$pd_factory='normal';
class PdDispatch extends RuntimeException {
    var $kind;var $value;
    function __construct($kind,$value){parent::__construct('Synthetic download sink');$this->kind=$kind;$this->value=$value;}
}
eval('namespace PdDownloadTransport; function send_file_to_browser($attachment,$dir){throw new \\PdDispatch("stream",$attachment);} function header($value){throw new \\PdDispatch("redirect",$value);}');
function pd_classes(){
    class PdDatabase extends PnDatabase {
        function sql_query($sql='',$transaction=false){
            $GLOBALS['pd_root_queries'][]=$sql;
            if($GLOBALS['pd_root_fault']!==''&&strpos($sql,$GLOBALS['pd_root_fault'])===0){return false;}
            $result=parent::sql_query($sql,$transaction);
            if($result&&strpos($sql,'SELECT e.extension, g.download_mode')===0&&is_callable($GLOBALS['pd_after_extensions'])){
                $callback=$GLOBALS['pd_after_extensions'];$GLOBALS['pd_after_extensions']=null;call_user_func($callback);
            }
            return$result;
        }
        function sql_dedicated_connection(){
            if($GLOBALS['pd_factory']==='alias'){return new KpOwnerConnection($this);}
            if($GLOBALS['pd_factory']==='busy'){return$GLOBALS['pd_busy'];}
            if($GLOBALS['pd_factory']==='throw'){throw new RuntimeException('Synthetic private connection details');}
            return parent::sql_dedicated_connection();
        }
    }
}
function pd_login($id,$cached_level=0){
    $GLOBALS['userdata']['user_id']=$id;$GLOBALS['userdata']['user_level']=$cached_level;
    kp_sql('UPDATE fixture_sessions SET session_user_id='.(int)$id);
}
function pd_prepare($locale){
    global$pd_after_extensions,$pd_root_queries,$pd_root_fault,$pd_factory,$kp_hook,$lang;
    $pd_after_extensions=null;$pd_root_queries=array();$pd_root_fault='';$pd_factory='normal';$kp_hook=null;
    pn_prepare($locale);require$GLOBALS['kp_source'].'language/lang_'.$locale.'/lang_main_attach.php';pd_login(4);
    kp_sql("INSERT INTO fixture_attachments_config VALUES('allow_pm_attach','1')");
    kp_sql("INSERT INTO fixture_extension_groups(group_id,group_name,cat_id,allow_group,download_mode) VALUES(1,'Synthetic',0,1,".INLINE_LINK.')');
    kp_sql("INSERT INTO fixture_extensions(ext_id,group_id,extension,comment) VALUES(1,1,'bin','Synthetic')");
    kp_sql("INSERT INTO fixture_attachments_desc(attach_id,physical_filename,real_filename,extension,mimetype,filesize) VALUES(1,'synthetic.bin','Grüße 😀.bin','bin','application/octet-stream',1)");
    kp_sql('INSERT INTO fixture_attachments(attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES(1,0,'.(int)$GLOBALS['pn_id'].',4,3)');
    kp_sql('UPDATE fixture_privmsgs SET privmsgs_attachment=1 WHERE privmsgs_id='.(int)$GLOBALS['pn_id']);
}
function pd_route($allow=true,$thumb=false){
    global$db,$userdata,$lang,$pd_body;
    $download_id=1;$thumbnail=$thumb;$upload_dir='synthetic-no-files';
    $attach_config=array('allow_pm_attach'=>$allow,'allow_ftp_upload'=>0,'download_path'=>'');
    try{eval('namespace PdDownloadTransport;'.$pd_body);throw new RuntimeException('Actual download must reach a controlled sink or denial');}
    catch(PdDispatch$result){return$result;}
    catch(KpMessageExit$result){return$result;}
}
function pd_check($result,$expected,$label){
    kp_check(($result instanceof PdDispatch)===$expected&&$GLOBALS['pn_open']===0,'Actual download authorization/release: '.$label);
    if(!$expected){kp_check($result instanceof KpMessageExit,'Denied download never reaches transport');}
}
function pd_revoke($kind){
    global$pn_id;
    if($kind==='missing'){kp_sql('DELETE FROM fixture_sessions');}
    elseif($kind==='foreign'){kp_sql('UPDATE fixture_sessions SET session_user_id=2');}
    elseif($kind==='logout'){kp_sql('UPDATE fixture_sessions SET session_logged_in=0');}
    elseif($kind==='case'){kp_sql('UPDATE fixture_sessions SET session_id=UPPER(session_id)');}
    elseif($kind==='replacement'){kp_sql("UPDATE fixture_sessions SET session_id='".str_repeat('d',32)."'");}
    elseif($kind==='inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=4');}
    elseif($kind==='deleted-user'){kp_sql('DELETE FROM fixture_users WHERE user_id=4');}
    elseif($kind==='disabled'){kp_sql("UPDATE fixture_attachments_config SET config_value='0'");}
    elseif($kind==='missing-setting'){kp_sql('DELETE FROM fixture_attachments_config');}
    elseif($kind==='parent'){kp_sql('DELETE FROM fixture_privmsgs WHERE privmsgs_id='.(int)$pn_id);}
    elseif($kind==='pending'){kp_sql("UPDATE fixture_privmsgs SET privmsgs_write_payload='pending' WHERE privmsgs_id=".(int)$pn_id);}
    elseif($kind==='staging'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_type=6 WHERE privmsgs_id='.(int)$pn_id);}
    elseif($kind==='owner'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_from_userid=2 WHERE privmsgs_id='.(int)$pn_id);}
    elseif($kind==='link'){kp_sql('DELETE FROM fixture_attachments');}
    elseif($kind==='link-target'){kp_sql('UPDATE fixture_attachments SET privmsgs_id=999');}
    elseif($kind==='public-link'){kp_sql('UPDATE fixture_attachments SET post_id=999');}
    elseif($kind==='descriptor'){kp_sql('DELETE FROM fixture_attachments_desc');}
    elseif($kind==='demoted'){kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
    elseif($kind==='filename'){kp_sql("UPDATE fixture_attachments_desc SET physical_filename='fresh.bin',real_filename='Fresh Grüße.bin'");}
    elseif($kind==='extension'){kp_sql("UPDATE fixture_attachments_desc SET extension='blocked'");}
}
function pd_suite(){
    global$kp_source,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$pd_body,$pd_after_extensions,$pd_factory,$pd_busy,$kp_hook,$kp_factory,$db,$userdata,$peer,$pd_root_fault;
    require_once$kp_source.'attach_mod/includes/functions_delete.php';require$kp_source.'includes/functions_pm_controller.php';
    $pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();$pd_factory='normal';$pd_root_fault='';$pd_root_queries=array();$pd_after_extensions=null;
    $source=str_replace("\r\n","\n",file_get_contents($kp_source.'download.php'));
    $start=strpos($source,"\$sql = 'SELECT *\n\tFROM ' . ATTACHMENTS_DESC_TABLE");kp_check($start!==false,'Actual complete download branch');$pd_body=substr($source,$start);
    putenv('SERVER_SOFTWARE=fixture-only');$cases=$failures=0;
    foreach(array('english','german')as$locale){
        foreach(array('ordinary','missing','foreign','logout','case','replacement','inactive','deleted-user','disabled','missing-setting','parent','pending','staging','owner','link','link-target','public-link','descriptor','stale-admin','current-admin','inactive-admin','demoted','front-admin','admin-disabled','cached-admin-extension')as$kind){
            pd_prepare($locale);$allow=true;$expected=in_array($kind,array('ordinary','current-admin','front-admin','admin-disabled'),true);
            if(in_array($kind,array('stale-admin','current-admin','inactive-admin','demoted'),true)){$userdata['user_level']=ADMIN;$allow=false;}
            if($kind==='current-admin'||$kind==='demoted'){pd_login(1,ADMIN);}
            elseif($kind==='inactive-admin'){kp_sql('UPDATE fixture_users SET user_level=1,user_active=0 WHERE user_id=4');}
            elseif(!in_array($kind,array('ordinary','stale-admin'),true)){pd_revoke($kind);}
            if($kind==='demoted'){pd_revoke($kind);}
            if($kind==='front-admin'){pd_login(1);kp_sql('UPDATE fixture_sessions SET session_admin=0');$allow=false;}
            if($kind==='admin-disabled'){pd_login(1);pd_revoke('disabled');$allow=false;}
            if($kind==='cached-admin-extension'){$userdata['user_level']=ADMIN;pd_revoke('extension');}
            $before=kp_snapshot();$result=pd_route($allow);pd_check($result,$expected,$locale.'/'.$kind);
            if(!$expected){kp_check(kp_snapshot()===$before,'Denied route changes no counters or private data: '.$kind);}$cases++;
        }
        pd_prepare($locale);kp_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=4');$userdata['user_level']=ADMIN;pd_revoke('extension');$pd_after_extensions=function(){kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=4');};pd_check(pd_route(),false,'Owner demotion removes disabled-extension bypass');$cases++;
        pd_prepare($locale);$before=kp_snapshot();
        foreach(array(null,0,-1,false,'1 OR 1=1',array(1),'999999999999999999999')as$bad){
            kp_check(!phpbb_pm_download_attachment($db,$bad,$userdata,true,1)&&!phpbb_pm_download_attachment($db,$GLOBALS['pn_id'],$userdata,true,$bad)&&!phpbb_pm_attachment_access($db,$bad,$userdata,true),'Malformed complete target rejected');$cases++;
        }
        foreach(array(null,false,array('a'),'',str_repeat('a',33),str_repeat('e',32))as$bad){$viewer=$userdata;$viewer['session_id']=$bad;kp_check(!phpbb_pm_download_attachment($db,$GLOBALS['pn_id'],$viewer,true,1),'Malformed or absent session identity rejected');$cases++;}
        kp_check($before===kp_snapshot()&&$pn_open===0,'Invalid input produces no data changes or retained readers');
        foreach(array(4,3,2,1)as$actor){foreach(range(0,6)as$type){
            pd_prepare($locale);pd_login($actor);kp_sql('UPDATE fixture_privmsgs SET privmsgs_type='.$type.' WHERE privmsgs_id='.(int)$GLOBALS['pn_id']);
            $expected=$actor===1?$type!==6:($actor===4?in_array($type,array(1,2,4,5),true):($actor===3&&in_array($type,array(0,1,3,5),true)));
            pd_check(pd_route(),$expected,'Current mailbox type/actor '.$actor.'/'.$type);$cases++;
        }}
        foreach(array('missing','foreign','logout','case','inactive','disabled','parent','pending','owner','link','link-target','descriptor','filename','extension','demoted')as$kind){
            pd_prepare($locale);if($kind==='demoted'){pd_login(1,ADMIN);}
            $reached=false;$pd_after_extensions=function()use($kind,&$reached){$reached=true;pd_revoke($kind);};
            $result=pd_route();$expected=$kind==='filename';pd_check($result,$expected,'Late controller transition '.$kind);kp_check($reached,'Actual extension-query checkpoint reached');
            if($expected){kp_check($result->value['physical_filename']==='fresh.bin'&&$result->value['real_filename']==='Fresh Grüße.bin','Actual stream uses current descriptor');}
            else{$rows=kp_rows('SELECT download_count FROM fixture_attachments_desc');kp_check(!$rows||(int)$rows[0]['download_count']===0,'Late denial precedes download counter/transport');}$cases++;
        }
        pd_prepare($locale);$pd_after_extensions=function(){kp_sql("INSERT INTO fixture_extensions(ext_id,group_id,extension,comment) VALUES(2,1,'png','Synthetic')");kp_sql('UPDATE fixture_extension_groups SET download_mode='.PHYSICAL_LINK);kp_sql("UPDATE fixture_attachments_desc SET physical_filename='fresh.png',extension='png',mimetype='image/png'");};
        // The preflight extension list is deliberately still BIN; the changed
        // extension must be refused rather than use the old file/mode bypass.
        pd_check(pd_route(),false,'Late extension cannot reuse a stale allow list');$cases++;
        pd_prepare($locale);kp_sql('UPDATE fixture_extension_groups SET download_mode='.PHYSICAL_LINK);kp_sql("UPDATE fixture_attachments_desc SET extension='bin',mimetype='image/png'");$result=pd_route();pd_check($result,true,'Actual physical redirect');kp_check($result->kind==='redirect'&&strpos($result->value,'synthetic.bin')!==false,'Controlled redirect uses bound descriptor');$cases++;
        foreach(array(false,true)as$thumb){pd_prepare($locale);$result=pd_route(true,$thumb);pd_check($result,true,'Actual thumbnail/stream');kp_check($result->kind==='stream'&&$result->value['physical_filename']===($thumb?THUMB_DIR.'/t_synthetic.bin':'synthetic.bin'),'Thumbnail path derives from current descriptor');$cases++;}
        foreach(array('missing','borrow','alias','throw','busy')as$factory){
            pd_prepare($locale);$before=kp_snapshot();$pd_factory=$factory;$kp_factory=in_array($factory,array('missing','borrow'),true)?$factory:'normal';
            if($factory==='busy'){$pd_busy=new sql_db($db->server,$db->user,getenv('PHPBB_PM_NOTIFY_PASSWORD')?:'',$db->dbname,false);kp_check($pd_busy->sql_query('START TRANSACTION'),'Owned busy fixture');kp_check($pd_busy->sql_query("UPDATE fixture_users SET username='Pending fixture' WHERE user_id=2"),'Owned pending work');}
            try{$result=pd_route();pd_check($result,false,'Invalid dedicated factory '.$factory);kp_check($result->level===GENERAL_ERROR&&$result->getMessage()===$GLOBALS['lang']['PM_cleanup_failed'],'Factory errors reveal no connection details');kp_check($db->db_connect_id&&kp_snapshot()===$before,'Invalid factory does not mutate/close caller');if($factory==='busy'){$r=$pd_busy->sql_query('SELECT @@in_transaction active');$row=$pd_busy->sql_fetchrow($r);$pd_busy->sql_freeresult($r);kp_check($pd_busy->db_connect_id&&(int)$row['active']===1,'Busy borrowed transaction remains open');}}
            finally{$pd_factory='normal';$kp_factory='normal';if($factory==='busy'){$pd_busy->sql_query('ROLLBACK');$pd_busy->sql_close();}}$cases++;
        }
        foreach(array('SELECT @@SESSION.autocommit','SELECT d.*,u.user_level')as$needle){foreach(array('before','after')as$phase){
            pd_prepare($locale);$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($needle,$phase,&$reached){if($event===$phase&&strpos($sql,$needle)===0){$reached=true;return false;}};
            try{pd_check(pd_route(),false,'Native reader SQL failure');kp_check($reached&&kp_snapshot()===$before,'Read failure leaves all data unchanged');}finally{$kp_hook=null;}$failures++;
        }}
        foreach(array('SELECT *','SELECT e.extension, g.download_mode','UPDATE '.ATTACHMENTS_DESC_TABLE)as$needle){pd_prepare($locale);$pd_root_fault=$needle;$before=kp_snapshot();pd_check(pd_route(),false,'Actual controller SQL failure');kp_check(kp_snapshot()===$before,'Controller failure does not emit bytes or change counters');$pd_root_fault='';$failures++;}
        foreach(array('missing','inactive','demoted','link')as$kind){
            pd_prepare($locale);if($kind==='demoted'){pd_login(1,ADMIN);}$db->sql_query('START TRANSACTION');$r=$db->sql_query('SELECT * FROM fixture_users');$db->sql_freeresult($r);$r=$db->sql_query('SELECT * FROM fixture_sessions');$db->sql_freeresult($r);$r=$db->sql_query('SELECT * FROM fixture_attachments');$db->sql_freeresult($r);
            try{pd_revoke($kind);$before=kp_snapshot();pd_check(pd_route(),false,'Caller RR snapshot '.$kind);$r=$db->sql_query('SELECT @@in_transaction active');$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_check((int)$row['active']===1&&$db->db_connect_id&&kp_snapshot()===$before,'Caller transaction remains open and unchanged');}
            finally{$db->sql_query('ROLLBACK');}$cases++;
        }
    }
    echo 'PM download native: '.$cases.' controller/session/copy/metadata/factory cases, '.$failures." SQL failures PASS; fresh independent authorization, current admin/settings, late revocations, caller snapshots and controlled stream/redirect sinks.\n";
}
$pd_file=__DIR__.'/check-pm-notifications-native.php';$pd_source=file_get_contents($pd_file);
if(substr_count($pd_source,'pn_suite();return;')!==1||substr_count($pd_source,'pn_classes();$db = new PnDatabase(')!==1){throw new RuntimeException('Actual canonical PM fixture boundaries');}
$pd_source=str_replace('pn_suite();return;','pd_suite();return;',$pd_source);$pd_source=str_replace('pn_classes();$db = new PnDatabase(','pn_classes();pd_classes();$db = new PdDatabase(',$pd_source);
$pd_source=str_replace('ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE','ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE,ATTACH_CONFIG_TABLE,EXTENSIONS_TABLE,EXTENSION_GROUPS_TABLE',$pd_source);
$pd_eval='';foreach(token_get_all($pd_source)as$t){$pd_eval.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($pd_eval,5));
