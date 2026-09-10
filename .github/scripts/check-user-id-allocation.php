<?php
require __DIR__ . '/check-attachment-mutation.php';
if (!defined('GENERAL_MESSAGE')) { define('GENERAL_MESSAGE',200); }
require $forum_root.'includes/functions_user_ids.php';
// SQLite supplies real fixture metadata for the MySQL information_schema query.
// Native runs use unmodified information_schema, SET and runtime SQL instead.
class UserIdFixturePDO
{
    var $inner;
    function __construct($inner) { $this->inner=$inner; }
    function __call($method,$args) { return call_user_func_array(array($this->inner,$method),$args); }
    function query($sql)
    {
        if($sql==='SET autocommit = 1') { return $this->inner->query('SELECT 1 WHERE 0'); }
        if(strpos($sql,'SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name,DATA_TYPE AS data_type FROM information_schema.COLUMNS')===0)
        {
            $parts=array(); $tables=$this->inner->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            foreach($tables as $table)
            {
                foreach($this->inner->query('PRAGMA table_info(`'.$table.'`)')->fetchAll(PDO::FETCH_ASSOC) as $column)
                {
                    $type=strtolower(preg_replace('/[^a-z].*/i','',$column['type'])); if($type==='integer') { $type='int'; }
                    $parts[]='SELECT '.$this->inner->quote($table).' AS table_name,'.$this->inner->quote($column['name']).' AS column_name,'.$this->inner->quote($type).' AS data_type';
                }
            }
            return $this->inner->query(implode(' UNION ALL ',$parts));
        }
        return $this->inner->query($sql);
    }
}
class UserIdReadDatabase
{
    function sql_query($sql) { mutation_check(strpos($sql,'SELECT ')===0,'Migration planning is read-only'); return $GLOBALS['mutation_server']->pdo->query($sql); }
    function sql_fetchrowset($r) { return $r->fetchAll(PDO::FETCH_ASSOC); }
    function sql_freeresult($r) { $r->closeCursor(); }
}
function user_id_value($sql) { return (int)$GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
function user_id_fixture($prefix='fixture_')
{
    global $mutation_server,$forum_root;
    $mutation_server=new MutationServer(); $p=$mutation_server->pdo;
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') { $p=new UserIdFixturePDO($p); $mutation_server->pdo=$p; }
    $p->exec('CREATE TABLE '.$prefix.'users (user_id MEDIUMINT PRIMARY KEY,user_posts INTEGER)'); $p->exec('INSERT INTO '.$prefix.'users VALUES (-1,0),(2,0),(20,999999)');
    $p->exec('CREATE TABLE '.$prefix.'album_cat (cat_user_id MEDIUMINT)'); $p->exec('INSERT INTO '.$prefix.'album_cat VALUES (70)');
    $p->exec('CREATE TABLE '.$prefix.'ina_scores (player_id MEDIUMINT)'); $p->exec('INSERT INTO '.$prefix.'ina_scores VALUES (80)');
    $p->exec('CREATE TABLE '.$prefix.'privmsgs (privmsgs_from_userid MEDIUMINT,privmsgs_to_userid MEDIUMINT)'); $p->exec('INSERT INTO '.$prefix.'privmsgs VALUES (90,120)');
    $p->exec('CREATE TABLE '.$prefix.'unrelated (user_id INTEGER)'); $p->exec('INSERT INTO '.$prefix.'unrelated VALUES (8000000)');
    $schema=file_get_contents($forum_root.'install/schemas/mysql_schema.sql');
    mutation_check(preg_match('/CREATE TABLE phpbb_user_id_sequence \(.*?\) ENGINE=InnoDB[^;]*;/s',$schema,$match)===1,'Canonical durable InnoDB sequence table found');
    $sql=preg_replace('/\) ENGINE=InnoDB[^;]*;$/',')',str_replace('phpbb_user_id_sequence',$prefix.'user_id_sequence',$match[0]));
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') { $sql=preg_replace('/\b(?:tinyint|int)\([0-9]+\)(?: unsigned)?/i','INTEGER',$sql); }
    $p->exec($sql); $p->exec('INSERT INTO '.$prefix.'user_id_sequence VALUES (1,0)');
}
function user_id_failure($callback,$message='User_id_allocation_failed')
{
    $caught=false; try { $callback(); } catch(PhpbbUserIdException $error) { $caught=$error->getMessage()===$message; }
    mutation_check($caught,'Expected controlled allocation failure: '.$message);
    mutation_check($GLOBALS['mutation_server']->owner===null,'Failure releases sequence owner');
}
set_error_handler(function($severity,$message) { if(error_reporting()&$severity) { throw new RuntimeException($message); } });
try
{
    $schema=file_get_contents($forum_root.'install/schemas/mysql_schema.sql');
    foreach(phpbb_user_id_references() as $table=>$fields)
    {
        mutation_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`? \((.*?)\) ENGINE=/s',$schema,$definition)===1,'Registered ownership table exists in fresh schema: '.$table);
        foreach($fields as $field) { mutation_check(preg_match('/\b`?'.$field.'`?\s+(?:tinyint|smallint|mediumint|int|bigint)\b/',$definition[1])===1,'Only verified numeric user references are registered: '.$table.'.'.$field); }
    }
    user_id_fixture(); $id=phpbb_allocate_user_id($db,'fixture_');
    mutation_check($id===121 && user_id_value('SELECT last_id FROM fixture_user_id_sequence')===121,'Retained PM/album/Arcade ownership reserves old IDs; unrelated fields/tables do not');
    $mutation_server->pdo->exec('INSERT INTO fixture_users VALUES (121,0)'); $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=121');
    foreach(array('album_cat','ina_scores','privmsgs') as $table) { $mutation_server->pdo->exec('DELETE FROM fixture_'.$table); }
    mutation_check(phpbb_allocate_user_id($db,'fixture_')===122 && phpbb_allocate_user_id($db,'fixture_')===123,'Deleted highest account and vanished references cannot lower the durable mark');
    mutation_check(user_id_value('SELECT COUNT(*) FROM fixture_users')===3 && user_id_value('SELECT COUNT(*) FROM fixture_unrelated')===1,'Allocator never creates accounts or changes content');
    $mutation_server->pdo->exec('INSERT INTO fixture_album_cat VALUES (500)');
    mutation_check(phpbb_allocate_user_id($db,'fixture_')===501,'Later imported retained ownership is detected on each allocation');
    $mutation_server->pdo->exec('INSERT INTO fixture_users VALUES (800,0)');
    mutation_check(phpbb_allocate_user_id($db,'fixture_')===801,'Imported current accounts also raise the floor');
    user_id_fixture(); $mutation_server->pdo->exec('CREATE TABLE fixture_user_removal_items (item_type VARCHAR(16),related_id INTEGER)');
    $mutation_server->pdo->exec("INSERT INTO fixture_user_removal_items VALUES ('pm',600),('group',7000000),('pm',-1)");
    mutation_check(phpbb_allocate_user_id($db,'fixture_')===601 && user_id_value('SELECT COUNT(*) FROM fixture_user_removal_items')===3,'Pending PM recipient identities are reserved without interpreting group IDs as users');
    user_id_fixture('custom_'); mutation_check(phpbb_allocate_user_id($db,'custom_')===121,'Configured custom prefix honored');
    foreach(array('',null,array('fixture_'),'fixture_;DROP TABLE users') as $prefix) { user_id_failure(function() use($db,$prefix) { phpbb_allocate_user_id($db,$prefix); }); }
    foreach(array('SET autocommit','SELECT last_id FROM','SELECT TABLE_NAME AS','SELECT MAX(value)','UPDATE `fixture_user_id_sequence`') as $failure)
    {
        user_id_fixture(); $mutation_server->failure=$failure; user_id_failure(function() use($db) { phpbb_allocate_user_id($db,'fixture_'); });
        mutation_check(user_id_value('SELECT last_id FROM fixture_user_id_sequence')===0,'Failed prerequisite must not reserve an unverified ID');
    }
    user_id_fixture(); $mutation_server->hook=function($sql) { if(strpos($sql,'UPDATE `fixture_user_id_sequence`')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->failure='SELECT last_id FROM'; } };
    user_id_failure(function() use($db) { phpbb_allocate_user_id($db,'fixture_'); });
    mutation_check(user_id_value('SELECT last_id FROM fixture_user_id_sequence')===121,'Lost readback retains consumed reservation');
    $mutation_server->failure=''; mutation_check(phpbb_allocate_user_id($db,'fixture_')===122,'Ambiguous reservation is skipped, never reused');
    user_id_fixture(); $mutation_server->hook=function($sql) { if(strpos($sql,'UPDATE `fixture_user_id_sequence`')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_user_id_sequence SET last_id=600 WHERE singleton=1'); } };
    user_id_failure(function() use($db) { phpbb_allocate_user_id($db,'fixture_'); });
    mutation_check(user_id_value('SELECT last_id FROM fixture_user_id_sequence')===600 && phpbb_allocate_user_id($db,'fixture_')===601,'Compare-and-set cannot overwrite an independently advanced mark');
    foreach(array('missing-row','missing-table','malformed-reference','missing-users','exhausted','too-large') as $case)
    {
        user_id_fixture(); $p=$mutation_server->pdo;
        if($case==='missing-row') { $p->exec('DELETE FROM fixture_user_id_sequence'); }
        if($case==='missing-table') { $p->exec('DROP TABLE fixture_user_id_sequence'); }
        if($case==='missing-users') { $p->exec('DROP TABLE fixture_users'); }
        if($case==='malformed-reference') { $p->exec('CREATE TABLE fixture_kb_articles (article_author_id VARCHAR(20))'); $p->exec("INSERT INTO fixture_kb_articles VALUES ('123')"); }
        if($case==='exhausted' || $case==='too-large') { $p->exec('UPDATE fixture_user_id_sequence SET last_id='.($case==='exhausted'?8388607:8388608)); }
        user_id_failure(function() use($db) { phpbb_allocate_user_id($db,'fixture_'); },in_array($case,array('exhausted','too-large'),true)?'User_id_capacity_exhausted':'User_id_allocation_failed');
    }
    user_id_fixture(); $mutation_server->pdo->exec('UPDATE fixture_user_id_sequence SET last_id=8388606');
    mutation_check(phpbb_allocate_user_id($db,'fixture_')===8388607,'Last signed MEDIUMINT identity is usable exactly once');
    user_id_failure(function() use($db) { phpbb_allocate_user_id($db,'fixture_'); },'User_id_capacity_exhausted');
    user_id_fixture(); $owner=new attach_mutation_lock($db); $caught=false;
    try { phpbb_allocate_user_id($db,'fixture_'); } catch(PhpbbUserIdException $error) { $caught=$error->getMessage()==='busy'; }
    mutation_check($caught && $mutation_server->owner===$owner->connection && user_id_value('SELECT last_id FROM fixture_user_id_sequence')===0,'Existing removal/content owner prevents interleaved allocation'); $owner->release();
    // Execute each actual dispatch block, not a copy of controller ID arithmetic.
    foreach(array('admin/admin_users.php','admin/admin_user_register.php','includes/usercp_register.php') as $file)
    {
        $source=file_get_contents($forum_root.$file);
        mutation_check(strpos($source,'MAX(user_id)')===false,'No legacy allocator remains in '.$file);
        $begin=strpos($source,"require_once(\$phpbb_root_path . 'includes/functions_user_ids.'"); $end=strpos($source,'catch (PhpbbUserIdException $error)',$begin); $end=strpos($source,'}',$end);
        mutation_check($begin!==false && $end>$begin,'Actual allocation dispatch found'); $body=substr($source,$begin,$end-$begin+1);
        user_id_fixture(); $allocated=call_user_func(function() use($body,$db,$forum_root) { $phpbb_root_path=$forum_root; $phpEx='php'; $table_prefix='fixture_'; eval($body); return $user_id; });
        mutation_check($allocated===121,'Actual controller uses durable allocation: '.$file);
        user_id_fixture(); $mutation_server->failure='UPDATE `fixture_user_id_sequence`'; $caught=false;
        try { call_user_func(function() use($body,$db,$forum_root) { $phpbb_root_path=$forum_root; $phpEx='php'; $table_prefix='fixture_'; eval($body); throw new RuntimeException('Failed allocation fell through to account INSERT'); }); } catch(MutationFailure $error) { $caught=$error->getMessage()==='User_id_allocation_failed'; }
        mutation_check($caught && user_id_value('SELECT COUNT(*) FROM fixture_users')===3,'Controller stops before account creation on allocation failure: '.$file);
    }
    user_id_fixture(); $original_db=$db;
    $scope=phpbb_user_write_begin($db);
    mutation_check($db!==$original_db && $db===$mutation_server->owner,'Creation switches to the owning writer connection');
    $contender=$original_db; $caught=false;
    try { phpbb_user_write_begin($contender); } catch(MutationFailure $error) { $caught=$error->getMessage()==='busy'; }
    mutation_check($caught && $contender===$original_db && $mutation_server->owner===$db,'Concurrent repair/creation cannot enter or replace the owner');
    mutation_check($db->sql_query('SELECT user_id FROM fixture_users')!==false,'Scoped reads use the live owner');
    phpbb_user_write_end($db,$scope);
    mutation_check($db===$original_db && $mutation_server->owner===null,'Publication restores original connection and releases writer lock');
    $scope=phpbb_user_write_begin($db); $scope[1]->release();
    mutation_check($db->sql_query('SELECT user_id FROM fixture_users')===false,'Lost ownership cannot silently continue on the unlocked connection');
    phpbb_user_write_end($db,$scope);
    mutation_check($db===$original_db,'Failure cleanup restores original connection');
    foreach(array('admin/admin_users.php','admin/admin_user_register.php','includes/usercp_register.php') as $file)
    {
        $source=file_get_contents($forum_root.$file);
        mutation_check(strpos($source,'phpbb_user_write_begin($db)')!==false && strpos($source,'phpbb_user_write_end($db,')!==false,'Account/group repair scopes are connected: '.$file);
    }
    // Maintenance now adds current ACP authorization to the same dedicated
    // writer. Its complete lifecycle and mutation guards run in the separate
    // check-maintenance-users.php controller suite, not this allocator fixture.
    $source=file_get_contents($forum_root.'admin/admin_db_maintenance.php');
    $maintenance=file_get_contents($forum_root.'includes/functions_maintenance_users.php');
    mutation_check(strpos($source,'dbmtnc_user_begin($db, $_POST)')!==false && strpos($source,'dbmtnc_user_end($db, $user_repair_scope)')!==false,'Authorized maintenance scope is connected');
    mutation_check(strpos($maintenance,'new attach_mutation_lock($database)')!==false && strpos($maintenance,'$lock->connection')!==false && strpos($maintenance,'$scope[1]->release()')!==false,'Maintenance still shares the dedicated account-publication writer');
    echo "Durable user ID allocation, shared publication lock and controller checks passed.\n";
}
finally { restore_error_handler(); }
