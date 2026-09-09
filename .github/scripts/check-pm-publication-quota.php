<?php
require __DIR__.'/pm-publication-fixture.php';
class PublicationAttachments
{
    public $fail=false;
    function insert_attachment_pm($id){if($this->fail){message_die(GENERAL_ERROR,'fixture attachment failure');}}
    function duplicate_attachment_pm($flag,$source,$target){if($this->fail){message_die(GENERAL_ERROR,'fixture attachment failure');}mutation_pm()->duplicate_attachment_pm($flag,$source,$target);}
}
$source=str_replace("\r\n","\n",file_get_contents($forum_root.'privmsg.php'));
$start=strpos($source,"\t\t\$msg_time = time();");$end=strpos($source,"\t\t\tif ( \$to_userdata['user_notify_pm']",$start);
mutation_check($start!==false && $end>$start,'Actual send-through-quota branch found');$send=substr($source,$start,$end-$start)."\n}";
$start=strpos($source,"\t\t// Update appropriate counter");$end=strpos($source,"\n\t}\n\t//\n\t// Pick a folder",$start);
mutation_check($start!==false && $end>$start,'Actual read-through-quota branch found');$read=substr($source,$start,$end-$start);
mutation_check(!is_dir($upload_dir) && mkdir($upload_dir,0700),'Owned publication quota directory');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
    foreach(array('english','german') as $locale)
    {
        include $forum_root.'language/lang_'.$locale.'/lang_main.php';
        foreach(array('INSERT INTO fixture_messages','INSERT INTO fixture_message_text','attachment','success','unlimited') as $failure)
        {
            publication_fixture();$db->failure=strpos($failure,'INSERT')===0?$failure:'';
            $mode='post';$privmsg_id=0;$privmsg_subject='new subject';$privmsg_message='new Grüße';$bbcode_uid='';$user_ip='7f000001';
            $html_on=0;$bbcode_on=1;$smilies_on=1;$attach_sig=0;$to_userdata=array('user_id'=>7);
            $attachment_mod=array('pm'=>new PublicationAttachments());$attachment_mod['pm']->fail=$failure==='attachment';
            if($failure==='unlimited'){$board_config['max_inbox_privmsgs']=0;}
            $caught=false;try{eval($send);}catch(MutationFailure $error){$caught=true;}
            if($failure==='success'||$failure==='unlimited')
            {
                mutation_check(!$caught,'Complete delivery finalizes');
                mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id='.$privmsg_sent_id)===1 && pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id='.$privmsg_sent_id)===1,'Published new header/text retained');
                mutation_check(pm_scalar('SELECT user_new_privmsg FROM fixture_users WHERE user_id=7')===($failure==='unlimited'?2:1),'Final delivery recount is exact, including quota deletion/unlimited inbox');
                mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===($failure==='unlimited'?1:0),'Old inbox eviction follows completed publication only');
            }
            else
            {
                mutation_check($caught,'Publication boundary failed');
                mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===1 && pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=20')===1,'Failed new delivery preserves old inbox header and text');
                mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Failed new delivery does not evict old attachment references');
                mutation_check($mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===0,'Failed publication creates no quota deletion intent');
            }
        }
        foreach(array('INSERT INTO fixture_messages','INSERT INTO fixture_message_text','attachment','success','older-copy') as $failure)
        {
            publication_fixture();$db->failure=strpos($failure,'INSERT')===0?$failure:'';$userdata['user_id']=7;$folder='inbox';
            if($failure==='older-copy'){$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_date=1 WHERE privmsgs_id=20');}
            $privmsg=$mutation_server->pdo->query('SELECT p.*,t.* FROM fixture_messages p,fixture_message_text t WHERE p.privmsgs_id=20 AND t.privmsgs_text_id=p.privmsgs_id')->fetch(PDO::FETCH_ASSOC);
            $attachment_mod=array('pm'=>new PublicationAttachments());$attachment_mod['pm']->fail=$failure==='attachment';
            $caught=false;try{eval($read);}catch(MutationFailure $error){$caught=true;}
            $old=pm_scalar("SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text='sent copy'");
            if($failure==='success'||$failure==='older-copy')
            {
                mutation_check(!$caught && $old===0,'Complete sent-copy publication may evict the old sent copy');
                mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id='.$privmsg_sent_id.' AND privmsgs_type=2')===1 && pm_scalar('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id='.$privmsg_sent_id)===1,'New sent copy and its shared attachment survive even with the oldest original date');
                mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Original inbox plus complete sent copy retained');
            }
            else
            {
                mutation_check($caught && $old===1 && pm_scalar('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=21')===1,'Failed sent-copy publication preserves old sender mailbox data');
                mutation_check($mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===0,'No quota deletion intent before complete sent copy');
            }
        }
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
