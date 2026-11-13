<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_MEMBER_LABELS_NATIVE') !== '1') {
    echo "Member label checks require an explicitly enabled disposable database.\n"; return;
}
define('CCache',true);
require __DIR__ . '/member-label-fixture.php'; require $al_root . 'db/mysqli.php';
class MemberLabelNativeDatabase extends sql_db {
    var $setup=true; var $queries=0; var $fail_name=false;
    function sql_query($sql='', $transaction=false) {
        if (!$this->setup && !preg_match('/^SELECT\b/i',ltrim($sql))) { throw new RuntimeException('Member display must remain read-only'); }
        $this->queries++;
        if ($this->fail_name && strpos($sql,'SELECT username FROM ')===0) { return false; }
        return parent::sql_query($sql,$transaction);
    }
}
function mln_sql($sql) { $r=$GLOBALS['db']->sql_query($sql); al_check($r!==false,'Owned member-label SQL'); return $r; }
function mln_snapshot() {
    $out=array(); foreach(array('users','groups','user_group','color_groups','removed_author_names')as$table) {
        $r=mln_sql('SELECT * FROM fixture_'.$table.' ORDER BY 1'); $out[$table]=$GLOBALS['db']->sql_fetchrowset($r); $GLOBALS['db']->sql_freeresult($r);
    } return serialize($out);
}
function mln_prepare($id,$key) {
    global $db;
    if(isset($GLOBALS['ml_previous_snapshot'])) { al_check(mln_snapshot()===$GLOBALS['ml_previous_snapshot'],'Previous template rendering leaves all stored account data unchanged'); }
    $db->setup=true;
    try { mln_sql("INSERT INTO fixture_users(user_id,username,user_password,user_color_group) VALUES(".$id.",'".$db->sql_escape($key)."','',5)"); }
    finally { $db->setup=false; }
    $record=get_userdata($id); al_check(is_array($record)&&$record['username']===$key,'Actual account lookup returns byte-exact canonical stored name');
    $GLOBALS['ml_previous_snapshot']=mln_snapshot(); return $record;
}
function mln_after($record) {
    global $db;
    $before=$db->queries; $cached=cg_get_data((int)$record['user_id'],true);
    al_check($db->queries===$before,'Actual file-cache hit avoids repeating name/color SQL for ' . $record['username'] . ' (extra queries: ' . ($db->queries-$before) . ')');
    al_check(isset($cached[$record['user_id']])&&$cached[$record['user_id']]['name']===$record['username'],'Real cache round-trip preserves name entities, Unicode and backslashes');
    al_check(mln_snapshot()===$GLOBALS['ml_previous_snapshot'],'Actual display and cache path cannot change stored account data');
}
$db=$control=null; $created=$made_root=false;
$schema='codex_member_labels_'.bin2hex(phpbb_random_bytes(8)); $ml_cache_root=sys_get_temp_dir().'/'.$schema;
try {
    al_check(!file_exists($ml_cache_root)&&!is_link($ml_cache_root)&&mkdir($ml_cache_root,0700),'Exclusive owned cache directory'); $made_root=true;
    al_check(mkdir($ml_cache_root.'/cache',0700),'Owned cache subdirectory');
    $port=getenv('PHPBB_MEMBER_LABELS_PORT')?:'3306'; $password=getenv('PHPBB_MEMBER_LABELS_PASSWORD')?:'';
    al_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback database port'); $host='127.0.0.1:'.$port;
    $control=new sql_db($host,'root',$password,'',false); al_check((bool)$control->db_connect_id,'Owned server connection');
    al_check(preg_match('/^codex_member_labels_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive owned schema'); $created=true;
    $db=new MemberLabelNativeDatabase($host,'root',$password,$schema,false); al_check((bool)$db->db_connect_id,'Owned schema connection');
    $canonical=file_get_contents($al_root.'install/schemas/mysql_schema.sql');
    foreach(array('users','groups','user_group','color_groups','removed_author_names')as$table) {
        al_check(preg_match('/CREATE TABLE phpbb_'.$table.' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical InnoDB member schema');
        mln_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));
    }
    mln_sql("INSERT INTO fixture_users(user_id,username,user_password,user_color_group) VALUES(8,'Canary','',5),(-1,'Anonymous','',0)");
    mln_sql("INSERT INTO fixture_color_groups(group_id,group_name,group_color,order_num) VALUES(5,'Raw&amp;Group','#123456',1)"); $db->setup=false;
    ml_suite('mln_prepare','mln_after'); al_check(mln_snapshot()===$GLOBALS['ml_previous_snapshot'],'Privacy and final templates are read-only');
    ml_context('german');
    $last=get_userdata(140);
    al_check(phpbb_data_cache_write($ml_cache_root.'/cache/cg_users.cache',array('updated'=>time(),'users'=>array(140=>array('name'=>array('invalid'),'color'=>'#123456')))),'Owned malformed cache fixture');
    $before_queries=$db->queries; $reloaded=cg_get_data(140,true);
    al_check($db->queries>$before_queries&&$reloaded[140]['name']===$last['username'],'Invalid cached name type reloads canonical bytes instead of rendering an array');
    $lang['Guest']='Guest&amp;literal'; $before=mln_snapshot();
    ml_expect(color_group_colorize_name(999998),'Guest&amp;amp;literal','Missing account uses raw translated guest label without decoding it as a name key');
    $db->fail_name=true; try { ml_expect(color_group_colorize_name(999999),'John Doe_999999','Failed fallback read cannot display another account name'); } finally { $db->fail_name=false; }
    $before_queries=$db->queries;
    al_check(color_group_colorize_name(ANONYMOUS)===$lang['Guest']&&$db->queries===$before_queries,'Anonymous label retains its existing translation and performs no SQL');
    al_check(mln_snapshot()===$before,'Missing accounts and failed reads leave all tables unchanged');
    $known_names=array('Current &amp; Grüße 😀','&lt;img src=x onerror=alert(1)&gt;','',"Back\\slash &amp; literal");
    foreach($known_names as$known){$shown=color_group_colorize_name(140,true,$known);$expected='<span style="font-weight:bold;color:#123456">'.phpbb_stored_text($known).'</span>';al_check($shown===$expected,'Current authorized name bypasses cached identity while retaining safe cached color');}
    al_check(mln_snapshot()===$before,'Known-name rendering leaves canonical account data unchanged');
    echo "Native current ColorGroups names: 4 cases PASS; actual formatter, trusted current rows, cached colors, empty names and safe Unicode/entities.\n";
    echo 'Member labels native: ',$ml_cases,' cases, ',$al_checks,' assertions, ',$ml_templates," actual templates PASS; canonical InnoDB names, actual account/Arcade/ColorGroups queries and file cache, read-only display and privacy.\n";
} finally {
    if($db){$db->sql_close();} if($created){al_check(preg_match('/^codex_member_labels_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exact owned schema');} if($control){$control->sql_close();}
    if($made_root){
        al_check(!is_link($ml_cache_root)&&realpath(dirname($ml_cache_root))===realpath(sys_get_temp_dir())&&preg_match('/^codex_member_labels_[a-f0-9]{16}$/D',basename($ml_cache_root)),'Exact owned cache root before cleanup');
        $cache=$ml_cache_root.'/cache'; $file=$cache.'/cg_users.cache';
        if(file_exists($file)){al_check(!is_link($file)&&unlink($file),'Remove owned generated cache');}
        if(is_dir($cache)){al_check(!is_link($cache)&&rmdir($cache),'Remove empty owned cache directory');} al_check(rmdir($ml_cache_root),'Remove empty owned cache root');
    }
    restore_error_handler();
}
