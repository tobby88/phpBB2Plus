<?php
// Actual name parsers and publication controllers on canonical owned tables.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_USERNAME_CREATE_NATIVE') !== '1') {
    echo "Account creation identity checks require an explicitly enabled disposable database.\n"; return;
}
$creation_mode = isset($argv[1]) ? $argv[1] : 'public';
if (!in_array($creation_mode, array('public','quick-add'), true)) { throw new RuntimeException('Invalid creation fixture mode'); }
putenv('PHPBB_REGISTRATION_NATIVE=1');
putenv('PHPBB_REGISTRATION_PORT='.(getenv('PHPBB_USERNAME_CREATE_PORT') ?: '3306'));
putenv('PHPBB_REGISTRATION_PASSWORD='.(getenv('PHPBB_USERNAME_CREATE_PASSWORD') ?: ''));
$creation_file=__DIR__.'/check-registration-native.php'; $creation_source=file_get_contents($creation_file);
$creation_cut=strpos($creation_source," foreach(array(0,1,2,3) as \$mode)");
if ($creation_cut===false) { throw new RuntimeException('Canonical creation fixture setup'); }
$creation_head=str_replace('__DIR__',var_export(__DIR__,true),substr($creation_source,5,$creation_cut-5));
$creation_head=str_replace('codex_registration_','codex_creation_identity_',$creation_head);
function creation_identity_suite()
{
    global $creation_mode,$ats_source,$rgn_tables,$schema,$db,$peer,$userdata,$rgn_mails,$rgn_open,$phpEx,$lang;
    require_once __DIR__.'/profile-request-fixture.php';
    ats_load_function($ats_source.'includes/usercp_register.php','usercp_post_scalar');
    if ($creation_mode==='quick-add') {
        require $ats_source.'includes/functions_admin_registration_storage.php';
        require $ats_source.'language/lang_english/lang_admin.php';
        define('THEMES_TABLE','fixture_themes');
        foreach(array('jr_admin_users'=>'jr','themes'=>'themes') as $original=>$suffix) {
            ats_check(preg_match('/CREATE TABLE `?phpbb_'.$original.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical quick-add participant');
            rgn_sql(str_replace('phpbb_'.$original,'fixture_'.$suffix,$m[0])); $rgn_tables[]=$suffix;
        }
        ats_load_function(__DIR__.'/check-admin-registration-native.php','qad_reset');
        ats_load_function(__DIR__.'/check-admin-registration-native.php','qad_run');
        $source=str_replace("\r\n","\n",file_get_contents($ats_source.'admin/admin_user_register.php'));
        $a=strpos($source,'$account_created_at = time();'); $b=strpos($source,"\n\t}\n} // End of submit",$a);
        ats_check($a!==false&&$b>$a,'Actual quick-add publication'); $GLOBALS['qad_body']=substr($source,$a,$b-$a);
        ats_check(preg_match('/^\t\$username = phpbb_username_key\([^\r\n]+;/m',$source,$m)===1,'Actual quick-add name input'); $parser=$m[0];
    } else {
        $source=file_get_contents($ats_source.'includes/usercp_register.php');
        ats_check(preg_match('/^\t\$username = phpbb_username_form\([^\r\n]+;/m',$source,$m)===1,'Actual public name input'); $parser=$m[0];
    }
    $cases=0; $mode='register';
    foreach(array('', 'NO_BACKSLASH_ESCAPES') as $sql_mode) {
        rgn_sql("SET SESSION sql_mode='$sql_mode'"); $db->sql_query("SET SESSION sql_mode='$sql_mode'");
        foreach(array('Müller','í',str_repeat('ä',25),str_repeat('😀',25),'A&B',"O'Reilly",'C:\\notes',"A\\'B") as $raw) {
            if ($creation_mode==='quick-add') { qad_reset(); } else { rgn_reset(0,'none'); }
            profile_fixture_request($_POST+array('username'=>$raw)); eval($parser);
            $expected=phpbb_username_key($raw); ats_check($username===$expected&&$username!=='','Actual request parser preserves full canonical identity');
            $out=$creation_mode==='quick-add'?qad_run($username):rgn_run($username);
            ats_check(rgn_success($out),'Actual account controller reports committed creation');
            $rows=rgn_rows('SELECT username FROM fixture_users WHERE user_id=8');
            ats_check(count($rows)===1&&$rows[0]['username']===$expected&&$rgn_open===0,'Canonical identity persisted without clipping or slash aliases');
            if ($creation_mode==='public') { ats_check(count($rgn_mails)===1&&$rgn_mails[0]['USERNAME']===$raw,'Full decoded Unicode name reaches notification'); }
            $cases++;
        }
        foreach(array(str_repeat('ä',26),str_repeat('😀',26),str_repeat('a',26),"bad\xc3","bad\0name",'') as $raw) {
            if ($creation_mode==='quick-add') { qad_reset(); } else { rgn_reset(0,'none'); }
            profile_fixture_request($_POST+array('username'=>$raw)); eval($parser); $before=rgn_snap();
            $out=$creation_mode==='quick-add'?qad_run($username):rgn_run($username);
            ats_check($username===''&&!rgn_success($out)&&rgn_snap()===$before&&!$rgn_mails&&$rgn_open===0,'Invalid identity cannot create a prefix or empty-name account'); $cases++;
        }
    }
    echo 'Native '.$creation_mode.' account identities: '.$cases." actual parser/publication cases passed.\n";
}
$creation_tail= <<<'PHP'
 creation_identity_suite();
} finally { $rgn_hook=$rgn_after=null; $rgn_fail=0; $rgn_commit=''; $rgn_main->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE '.$fixture); $control->sql_close(); restore_error_handler(); }
PHP;
eval($creation_head.$creation_tail);
