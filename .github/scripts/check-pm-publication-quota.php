<?php
require __DIR__.'/pm-publication-fixture.php';
// Actual SEND/EDIT publication, failure preservation and quota recounts run in
// check-pm-write-controller.php with the durable writer and owning connection.
mutation_check(!is_dir($upload_dir) && mkdir($upload_dir,0700),'Owned publication quota directory');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
    foreach(array('english','german') as $locale)
    {
        include $forum_root.'language/lang_'.$locale.'/lang_main.php';
        // Read publication now runs through the durable worker; its actual
        // controller branch and quota failures are covered in check-pm-read-controller.php.
        foreach(array('absent','text-missing','moved','foreign','revoked','moved-before-delete','removed-before-delete') as $case)
        {
            publication_fixture();
            $mutation_server->pdo->exec("INSERT INTO fixture_messages (privmsgs_id,privmsgs_type,privmsgs_to_userid,privmsgs_from_userid,privmsgs_attachment,privmsgs_date) VALUES(22,1,7,8,0,500)");
            $mutation_server->pdo->exec("INSERT INTO fixture_message_text (privmsgs_text_id,privmsgs_text) VALUES(22,'new payload')");
            if($case==='absent'){$mutation_server->pdo->exec('DELETE FROM fixture_messages WHERE privmsgs_id=22');}
            elseif($case==='text-missing'){$mutation_server->pdo->exec('DELETE FROM fixture_message_text WHERE privmsgs_text_id=22');}
            elseif($case==='moved'){$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=22');}
            elseif($case==='foreign'){$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=99 WHERE privmsgs_id=22');}
            else
            {
                $mutation_server->hook=function($sql) use($case)
                {
                    if(strpos($sql,'DELETE FROM fixture_messages WHERE')!==0){return;}
                    $s=$GLOBALS['mutation_server'];$s->hook=null;
                    if($case==='revoked'){$s->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=8');}
                    elseif($case==='moved-before-delete'){$s->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=22');}
                    else {$s->pdo->exec('DELETE FROM fixture_messages WHERE privmsgs_id=22');}
                };
            }
            $caught=false;try{phpbb_pm_finalize_delivery(7,22,1);}catch(MutationFailure $error){$caught=true;}
            mutation_check($caught===($case==='revoked'),'Finalization reports current send capability changes');
            mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===1 && is_file($upload_dir.'/fixture.txt'),'Current publication/capacity guard preserves old inbox: '.$case);
        }
        echo $locale." actual PM publication/quota failure and completion checks passed.\n";
    }
}
finally
{
    if($mutation_server->owner!==null){$mutation_server->owner->sql_close();}
    if(is_file($upload_dir.'/fixture.txt')){unlink($upload_dir.'/fixture.txt');}rmdir($upload_dir);restore_error_handler();
}
