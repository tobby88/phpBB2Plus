<?php
function ala_admin_reset($root=false)
{
    ala_reset();ala_sql('UPDATE fixture_sessions SET session_admin=1');
    ala_sql('UPDATE fixture_album_cat SET cat_view_level=1,cat_upload_level=1,cat_delete_level=1 WHERE cat_id=1');
    if($root){ala_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');}
    else{ala_sql('INSERT INTO fixture_jr_admin_users(user_id,user_jr_admin,admin_notes) VALUES(7,'.ala_literal(md5('Photo_AlbumCategoriesadmin_album_cat.php')).",'')");}
}
function ala_admin_authorize($category=1)
{
    $GLOBALS['storage']=new AlbumFileCoordinator($GLOBALS['db'],'fixture_',0);$GLOBALS['storage']->begin();
    $authority=new AlbumLifecycleAuthority($GLOBALS['storage'],'fixture_');$before=ala_globals();
    try{$context=$authority->lock_admin($GLOBALS['userdata'],$category);}
    finally{ala_check(ala_globals()===$before,'ACP authority restores exact request globals');}
    return array($authority,$context);
}
function ala_admin_denied($category=1)
{
    $denied=false;try{$result=ala_admin_authorize($category);$denied=$result[1]===false;}catch(AlbumFileCoordinatorFailure$error){$denied=true;}finally{ala_release();}
    ala_check($denied,'Current exact ACP module/session authority must deny stale or unrelated grants');$GLOBALS['cases']++;
}
function ala_admin_cases()
{
    global $db,$storage,$control,$cases,$userdata;$before_cases=$cases;$baselines=array();
    foreach(array(false,true)as$root)
    {
        ala_admin_reset($root);$result=ala_admin_authorize();ala_check($result[1]!==false&&$result[1]['userdata']['user_level']===($root?ADMIN:0),'Actual root/delegated ACP category access is independent of public category permissions');
        $storage->commit();ala_release();$baselines[]=$db->queries;$cases++;
        ala_admin_reset($root);ala_sql('UPDATE fixture_sessions SET session_admin=0');ala_admin_denied();
        ala_admin_reset($root);ala_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=7');ala_admin_denied();
        ala_admin_reset($root);ala_sql('UPDATE fixture_sessions SET session_time=1');ala_admin_denied();
    }
    ala_admin_reset();ala_sql('DELETE FROM fixture_jr_admin_users');$userdata['user_jr_admin']=md5('Photo_AlbumCategoriesadmin_album_cat.php');ala_admin_denied();
    ala_admin_reset();ala_sql('UPDATE fixture_jr_admin_users SET user_jr_admin='.ala_literal(md5('GeneralConfigurationadmin_board.php')));ala_admin_denied();
    ala_admin_reset();ala_sql('UPDATE fixture_jr_admin_users SET user_jr_admin='.ala_literal(md5('Photo_AlbumCategoriesadmin_album_cat.php').'not-a-route'));ala_admin_denied();
    ala_admin_reset(true);ala_sql('DELETE FROM fixture_jr_admin_users');ala_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');ala_admin_denied();
    ala_admin_reset();ala_admin_denied(2);
    foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci')as$shape)
    {
        ala_admin_reset();ala_sql('ALTER TABLE fixture_jr_admin_users '.$shape);ala_admin_denied();ala_sql('ALTER TABLE fixture_jr_admin_users ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    ala_admin_reset();$result=ala_admin_authorize();ala_check($result[1]!==false,'Delegated module grant before peer revocation');ala_sql('SET SESSION innodb_lock_wait_timeout=1');
    ala_check($control->sql_query("UPDATE fixture_jr_admin_users SET user_jr_admin='' WHERE user_id=7")===false,'Real delegated grant stays share-locked through owned commit');
    $storage->commit();ala_release();ala_sql('SET SESSION innodb_lock_wait_timeout=5');$cases++;
    foreach($baselines as$root=>$queries){foreach($queries as$index=>$sql){
        ala_admin_reset((bool)$root);$db->reset_case($index+1);$failed=false;
        try{$result=ala_admin_authorize();if($result[1]===false){$failed=true;}else{$storage->commit();}}catch(AlbumFileCoordinatorFailure$error){$failed=true;}finally{ala_release();}
        ala_check($failed&&!ala_rows('SELECT pic_id FROM fixture_album'),'Every native root/delegated authority SQL failure refuses commit: '.($index+1));$cases++;
    }}
    echo 'Native Album ACP authority: '.($cases-$before_cases).' cases PASS; actual registered module hashes, delegated/root rights, current admin session, grant locking and every native SQL failure; no live data.',"\n";
}
