<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ADMIN_HISTORY_NATIVE') !== '1') {
    echo "Admin history checks require an explicitly enabled disposable database.\n"; return;
}
require __DIR__ . '/admin-history-label-fixture.php';
require $al_root . 'db/mysqli.php'; require $al_root . 'includes/functions_log.php';
class AdminHistoryNativeDatabase extends sql_db {
    var $setup = true; var $fail = false;
    function sql_query($sql = '', $transaction = false) {
        if (!$this->setup && !preg_match('/^SELECT\b/i',ltrim($sql))) { throw new RuntimeException('Report display must be read-only'); }
        if (!$this->setup && $this->fail) { return false; }
        return parent::sql_query($sql,$transaction);
    }
}
function ahn_sql($sql) { $r=$GLOBALS['db']->sql_query($sql); al_check($r!==false,'Owned history SQL'); return $r; }
function ahn_change($sql) { $GLOBALS['db']->setup=true; try { return ahn_sql($sql); } finally { $GLOBALS['db']->setup=false; } }
function ahn_snapshot() {
    $out=array(); foreach(array('users','logs','ranks')as$table){$r=ahn_sql('SELECT * FROM fixture_'.$table.' ORDER BY 1');$out[$table]=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);} return serialize($out);
}
function ahn_log_page() {
    global $db,$template,$lang,$phpEx,$phpbb_root_path,$board_config;
    $template=al_template('logs_body.tpl');$phpbb_root_path='../';$sort_map=array('time'=>'l.log_time');$sort='time';$order='ASC';$start=0;$per_page=20;
    eval(al_fragment('admin/admin_logs.php','$sql = "SELECT l.* FROM "','$template->pparse(\'body\');'));
    $phpbb_root_path=$GLOBALS['al_root'];return al_html($template);
}
function xs_error($message) { throw new AdminLabelDenied($message); }
function ahn_style_page() {
    global $db,$template,$HTTP_GET_VARS;
    $HTTP_GET_VARS=array('list'=>'3');$template=ah_style_template();
    eval(al_fragment('admin/xs_styles.php','if(isset($HTTP_GET_VARS[\'list\'])','$template->set_filenames(array(\'body\''));
    return al_html($template);
}
$db=$control=null;$created=false;$schema='codex_admin_history_'.bin2hex(phpbb_random_bytes(8));
try {
    $port=getenv('PHPBB_ADMIN_HISTORY_PORT')?:'3306';$password=getenv('PHPBB_ADMIN_HISTORY_PASSWORD')?:'';
    al_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
    $control=new sql_db($host,'root',$password,'',false);al_check((bool)$control->db_connect_id,'Owned server connection');
    al_check(preg_match('/^codex_admin_history_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive owned schema');$created=true;
    $db=new AdminHistoryNativeDatabase($host,'root',$password,$schema,false);al_check((bool)$db->db_connect_id,'Owned schema connection');
    $canonical=file_get_contents($al_root.'install/schemas/mysql_schema.sql');
    foreach(array('users','logs','ranks')as$table){al_check(preg_match('/CREATE TABLE phpbb_'.$table.' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical history schema');ahn_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
    ahn_sql("INSERT INTO fixture_users(user_id,username,user_password,user_style,user_posts,user_rank) VALUES(7,'Member','',3,12,999),(8,'OtherStyleCanary','',4,2,0)");$db->setup=false;
    $client_ip='192.0.2.7';$user_ip='c0000207';
    foreach(array('english','german')as$locale){ah_locale($locale);
        foreach(al_names()as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){
            $key=phpbb_username_key($raw,$flags);al_check($key!=='','Canonical native identity');
            ahn_change("UPDATE fixture_users SET username='".$db->sql_escape($key)."' WHERE user_id=7");
            $userdata=array('user_id'=>7,'username'=>$key);$before=ahn_snapshot();
            $db->setup=true;try{al_check(log_action('edit',array(11,12)),'Actual audit writer persists canonical snapshot');}finally{$db->setup=false;}
            $r=ahn_sql('SELECT username,user_id,topic_id FROM fixture_logs ORDER BY id_log DESC LIMIT 2');$rows=$db->sql_fetchrowset($r);$db->sql_freeresult($r);
            al_check(count($rows)===2&&$rows[0]['username']===$key&&$rows[1]['username']===$key&&(int)$rows[0]['user_id']===7,'Actual writer stores one identity representation and original account ID');
            ahn_change("UPDATE fixture_users SET username='RenamedCanary' WHERE user_id=7");$before=ahn_snapshot();$html=ahn_log_page();
            al_check(substr_count($html,'>'.htmlspecialchars($raw,ENT_QUOTES,'UTF-8').'</a>')===2&&strpos($html,'RenamedCanary')===false,'Actual log read/template preserves both historical names after account rename');$ah_cases++;
            al_check(ahn_snapshot()===$before,'Historical display leaves snapshots and accounts unchanged');
            ahn_change("UPDATE fixture_users SET username='".$db->sql_escape($key)."' WHERE user_id=7");$before=ahn_snapshot();$html=ahn_style_page();
            al_check(strpos($html,'<td>[7]</td>')!==false&&strpos($html,'<td>'.htmlspecialchars($raw,ENT_QUOTES,'UTF-8').'</td>')!==false&&strpos($html,'OtherStyleCanary')===false,'Actual style query displays current name and retains numeric style filter');$ah_cases++;
            $r=ahn_sql('SELECT user_id,username,user_posts FROM fixture_users WHERE user_id=7');$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);ah_reports($row,$raw);
            al_check(ahn_snapshot()===$before,'All actual report statements and style list remain read-only');
            ahn_change('DELETE FROM fixture_logs');
        }}
    }
    // Historical log remains readable after deletion; a new account cannot take its ID.
    $userdata=array('user_id'=>7,'username'=>phpbb_username_key('Old&Name'));$db->setup=true;
    try{al_check(log_action('edit',11),'Owned historical deletion fixture');}finally{$db->setup=false;}
    ahn_change('DELETE FROM fixture_users WHERE user_id=7');ahn_change("INSERT INTO fixture_users(user_id,username,user_password,user_style) VALUES(11,'Old&amp;Name','',4)");
    $before=ahn_snapshot();$html=ahn_log_page();al_check(strpos($html,'u=7')!==false&&strpos($html,'>Old&amp;Name</a>')!==false&&strpos($html,'u=11')===false,'Deleted account audit is not remapped to a reused name');$ah_cases++;
    foreach(array('ahn_log_page','ahn_style_page')as$reader){$db->fail=true;$denied=false;try{$reader();}catch(AdminLabelDenied$e){$denied=true;}finally{$db->fail=false;}al_check($denied,'Database read failure stops report rendering');$ah_cases++;}
    al_check(ahn_snapshot()===$before,'Missing accounts and failed reads cannot change data');
    echo 'Admin history labels native: ',$ah_cases,' cases, ',$al_checks," assertions PASS; actual log writer/reader and XS query, canonical InnoDB records, rename/deletion snapshots and unchanged report data.\n";
} finally {
    if($db){$db->sql_close();}if($created){al_check(preg_match('/^codex_admin_history_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exact owned schema');}if($control){$control->sql_close();}restore_error_handler();
}
