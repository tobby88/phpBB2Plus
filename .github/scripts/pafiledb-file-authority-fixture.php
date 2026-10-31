<?php
require __DIR__.'/pafiledb-file-authority-native-support.php';
$pa_before=$pc_checks;
$matrix=array(
 array('guest-create',-1,'public','create',9,true),array('guest-reg',-1,'public','create',9,false),array('owner-edit',8,'public','edit',9,true),array('other-edit',10,'public','edit',9,false),array('owner-delete-denied',8,'public','delete',0,false),array('edit-without-upload',8,'public','edit',9,true),array('move-denied',8,'public','edit',10,false),array('owner-move',8,'public','edit',10,true),array('moderator-delete',9,'public','delete',0,true),array('moderator-approve',9,'public','approve',0,true),array('other-approve',10,'public','approve',0,false),array('removed-mod-grant',9,'public','delete',0,false),array('guest-stale-admin',-1,'public','delete',0,false),array('hidden-parent',8,'public','edit',9,false),array('missing-parent',8,'public','edit',9,false),array('cyclic-parent',8,'public','edit',9,false),array('admin-orphan-delete',7,'files','delete',0,true),array('junior-file',11,'files','delete',0,true),array('junior-wrong-route',11,'files','delete',0,false),array('junior-revoked',11,'files','delete',0,false),array('admin-public-create',7,'public','create',9,true),array('acp-session-revoked',7,'files','delete',0,false),array('root-role-revoked',7,'files','delete',0,false),array('missing-destination',7,'files','create',99,false),array('broken-destination',7,'files','create',9,false),array('folder-destination',7,'files','create',9,false),array('inactive-owner',8,'public','edit',9,false),array('expired-session',8,'public','edit',9,false),array('case-session',8,'public','edit',9,false),array('moved-away',9,'public','delete',0,false)
);
$matrix[]=array('padded-session',8,'public','edit',9,false);
foreach($matrix as$case){list($name,$id,$mode,$action,$destination,$expected)=$case;pa_reset($id,$mode);
 if($name==='guest-reg'){pc_sql('UPDATE fixture_pa_cat SET auth_upload='.AUTH_REG.' WHERE cat_id=9');}
 elseif($name==='owner-delete-denied'){pc_sql('UPDATE fixture_pa_cat SET auth_delete_file='.AUTH_ADMIN.' WHERE cat_id=9');}
 elseif($name==='edit-without-upload'){pc_sql('UPDATE fixture_pa_cat SET auth_upload='.AUTH_ADMIN.' WHERE cat_id=9');}
 elseif($name==='move-denied'){pc_sql('UPDATE fixture_pa_cat SET auth_upload='.AUTH_ADMIN.' WHERE cat_id=10');}
 elseif($name==='removed-mod-grant'){pc_sql('DELETE FROM fixture_pa_auth');}
 elseif($name==='guest-stale-admin'){$userdata['user_level']=ADMIN;pc_sql('UPDATE fixture_pa_files SET user_id=-1 WHERE file_id=42');}
 elseif($name==='hidden-parent'){pc_sql('UPDATE fixture_pa_cat SET auth_view='.AUTH_ADMIN.' WHERE cat_id=1');}
 elseif(in_array($name,array('missing-parent','broken-destination'),true)){pc_sql('UPDATE fixture_pa_cat SET cat_parent=99 WHERE cat_id=9');}
 elseif($name==='cyclic-parent'){pc_sql('UPDATE fixture_pa_cat SET cat_parent=9 WHERE cat_id=1');}
 elseif($name==='admin-orphan-delete'){pc_sql('UPDATE fixture_pa_files SET file_catid=99 WHERE file_id=42');}
 elseif($name==='junior-wrong-route'){$routes=jr_admin_authorization_routes();$hash=array_search('admin_pa_category.php',$routes,true);pc_sql("UPDATE fixture_jr_admin_users SET user_jr_admin='".$peer->sql_escape($hash)."' WHERE user_id=11");}
 elseif($name==='junior-revoked'){pc_sql('DELETE FROM fixture_jr_admin_users');}
 elseif($name==='acp-session-revoked'){pc_sql('UPDATE fixture_sessions SET session_admin=0');}
 elseif($name==='root-role-revoked'){pc_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');}
 elseif($name==='folder-destination'){pc_sql('UPDATE fixture_pa_cat SET cat_allow_file=0 WHERE cat_id=9');}
 elseif($name==='inactive-owner'){pc_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
 elseif($name==='expired-session'){pc_sql('UPDATE fixture_sessions SET session_time=1');}
 elseif($name==='case-session'){pc_sql("UPDATE fixture_sessions SET session_id='FILES-8'");}
 elseif($name==='padded-session'){$userdata['session_id'].=' ';$GLOBALS['pa_actor']['sid'].=' ';}
 elseif($name==='moved-away'){pc_sql('UPDATE fixture_pa_files SET file_catid=10 WHERE file_id=42');}
 $before=pc_rows('SELECT * FROM fixture_pa_files');$c=pa_open();try{$c->begin();$allowed=false;try{$authority=pa_authorize($c,$action,$destination);$allowed=true;}catch(PaFileDbFileActionFailure$error){}pc_check($allowed===$expected,'Actual current file authority: '.$name);pc_check(pc_rows('SELECT * FROM fixture_pa_files')===$before,'Authority selection changes no file metadata: '.$name);}finally{$c->release();}
}
pa_reset(9,'public');pc_sql("UPDATE fixture_pa_config SET config_value='validator_admin' WHERE config_name='validator'");$c=pa_open();try{$c->begin();pc_denied(function()use($c){pa_authorize($c,'approve');},'Current validator_admin setting restricts moderation approval to administrators');}finally{$c->release();}
pa_reset(8,'public');$c=pa_open();try{$c->begin();$authority=pa_authorize($c,'delete');$c->sql_query('DELETE FROM fixture_pa_files WHERE file_id=42');$c->commit();pc_check(!pc_rows('SELECT file_id FROM fixture_pa_files'),'Guard permits its own authorized logical deletion through commit');}finally{$c->release();}
pa_reset(11,'files');$c=pa_open();try{$c->begin();$authority=pa_authorize($c,'delete');$userdata['user_id']=7;$userdata['session_id']='files-7';pc_denied(function()use($authority){$authority->check();},'Global cached identity cannot replace the frozen file actor');}finally{$c->release();}
foreach(array('role','session','grant')as$kind){$id=$kind==='grant'?11:7;pa_reset($id,'files');$c=pa_open();try{$c->begin();$authority=pa_authorize($c,'delete');$sql=$kind==='role'?'UPDATE fixture_users SET user_level=0 WHERE user_id=7':($kind==='session'?'DELETE FROM fixture_sessions WHERE session_user_id='.$id:'DELETE FROM fixture_jr_admin_users WHERE user_id=11');$probe=native_fixture_conflict_query($peer,$sql);pc_check($probe[0]===false&&(int)$probe[1]['code']===1205,'Current actor/session/delegation stays locked until commit: '.$kind);$c->commit();}finally{$c->release();}pc_sql($sql);$c=pa_open();try{$c->begin();pc_denied(function()use($c){pa_authorize($c,'delete');},'Next operation observes committed authority revocation: '.$kind);}finally{$c->release();}}
// REPEATABLE READ must retain the actor lock without locking other sessions.
foreach(array(array(8,'public'),array(7,'files'),array(11,'files'))as$actor){
 pa_reset($actor[0],$actor[1]);pa_insert('fixture_sessions',array('session_id'=>'unrelated-session','session_user_id'=>10,'session_time'=>time(),'session_logged_in'=>1,'session_admin'=>0));$c=pa_open();
 try{$c->begin(true);$authority=pa_authorize($c,'delete');$probe=native_fixture_conflict_query($peer,"UPDATE fixture_sessions SET session_time=session_time+1 WHERE session_id='unrelated-session'");pc_check($probe[0]!==false,'Structural authority never blocks unrelated session updates: '.$actor[1].'/'.$actor[0]);$probe=native_fixture_conflict_query($peer,"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='files-".$actor[0]."'");pc_check($probe[0]===false&&(int)$probe[1]['code']===1205,'The exact structural actor session stays locked through commit');$c->commit();}finally{$c->release();}
}
echo 'Native download file authority: ',$pc_checks-$pa_before," current owner/moderator/guest/ACP/session/ancestry/selection/revocation checks PASS.\n";
