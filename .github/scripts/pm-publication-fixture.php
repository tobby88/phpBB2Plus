<?php
require __DIR__.'/check-private-message-cleanup.php';
foreach(array('SQL_LAYER'=>'mysqli','BEGIN_TRANSACTION'=>1,'END_TRANSACTION'=>2) as $name=>$value){if(!defined($name)){define($name,$value);}}
class PmPublicationFixtureDb extends MutationForum
{
    public $failure='';
    function sql_query($sql,$transaction=false)
    {
        $normalized=preg_replace('/\s+/',' ',trim($sql));
        if($this->failure!=='' && strpos($normalized,$this->failure)===0){return false;}
        return $GLOBALS['mutation_server']->pdo->query($sql);
    }
    function sql_nextid(){return (int)$GLOBALS['mutation_server']->pdo->lastInsertId();}
}
function publication_fixture()
{
    pm_cleanup_fixture();$pdo=$GLOBALS['mutation_server']->pdo;
    foreach(array('privmsgs_subject TEXT DEFAULT \'\'','privmsgs_ip TEXT DEFAULT \'\'','privmsgs_enable_html INTEGER DEFAULT 0',
        'privmsgs_enable_bbcode INTEGER DEFAULT 1','privmsgs_enable_smilies INTEGER DEFAULT 1','privmsgs_attach_sig INTEGER DEFAULT 0') as $field){$pdo->exec('ALTER TABLE fixture_messages ADD COLUMN '.$field);}
    $pdo->exec("ALTER TABLE fixture_message_text ADD COLUMN privmsgs_bbcode_uid TEXT DEFAULT ''");
    $pdo->exec('ALTER TABLE fixture_users ADD COLUMN user_last_privmsg INTEGER DEFAULT 0');
    $pdo->exec('UPDATE fixture_users SET user_new_privmsg=1,user_unread_privmsg=0 WHERE user_id=7');
    $GLOBALS['db']=new PmPublicationFixtureDb();
    $GLOBALS['board_config']=array('max_inbox_privmsgs'=>1,'max_sentbox_privmsgs'=>1);
}
