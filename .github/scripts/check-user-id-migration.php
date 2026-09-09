<?php
require __DIR__ . '/check-user-id-allocation.php';
$source=file_get_contents(dirname($forum_root).'/update/update_from_153a.php');
$start=strpos($source,'function update_queue_user_id_sequence('); $end=strpos($source,'function update_table_exists(',$start);
mutation_check($start!==false && $end>$start,'Actual sequence migration planner found'); eval(substr($source,$start,$end-$start));
mutation_check(strpos($source,'update_queue_user_id_sequence($operations, new UpdateUserIdDatabase($connection), $table_prefix, update_table_exists($connection, $dbname, $table_prefix . \'user_id_sequence\'));')!==false,'Updater invokes sequence planner with live existence check');
$start=strpos($source,'function update_extract_create_tables('); $end=strpos($source,'function update_extract_seed_statements(',$start);
eval(substr($source,$start,$end-$start));
$schema=file_get_contents($forum_root.'install/schemas/mysql_schema.sql'); $create=update_extract_create_tables($schema);
mutation_check(isset($create['phpbb_user_id_sequence']),'Generic fresh-schema migration includes sequence table');
set_error_handler(function($severity,$message) { if(error_reporting()&$severity) { throw new RuntimeException($message); } });
try
{
    foreach(array('fixture_','custom_') as $prefix)
    {
        user_id_fixture($prefix); $reader=new UserIdReadDatabase(); $operations=array();
        update_queue_user_id_sequence($operations,$reader,$prefix,true);
        mutation_check($operations===array('UPDATE `'.$prefix.'user_id_sequence` SET last_id = 120 WHERE singleton = 1 AND last_id < 120'),'Seed floor covers retired ownership without allocating an account');
        mutation_check(user_id_value('SELECT last_id FROM '.$prefix.'user_id_sequence')===0,'Dry run changes no sequence value');
        foreach($operations as $sql) { $mutation_server->pdo->exec($sql); }
        $operations=array(); update_queue_user_id_sequence($operations,$reader,$prefix,true); mutation_check(!$operations,'Repeated migration is empty');
        mutation_check(phpbb_allocate_user_id($db,$prefix)===121,'First registration after migration starts above retained IDs');
        $mutation_server->pdo->exec('INSERT INTO '.$prefix.'album_cat VALUES (300)'); $operations=array(); update_queue_user_id_sequence($operations,$reader,$prefix,true);
        $mutation_server->pdo->exec('UPDATE '.$prefix.'user_id_sequence SET last_id=400'); foreach($operations as $sql) { $mutation_server->pdo->exec($sql); }
        mutation_check(user_id_value('SELECT last_id FROM '.$prefix.'user_id_sequence')===400,'Applying an older migration plan cannot lower a newer reservation');
        $mutation_server->pdo->exec('DELETE FROM '.$prefix.'user_id_sequence'); $operations=array(); update_queue_user_id_sequence($operations,$reader,$prefix,true);
        mutation_check(count($operations)===2 && strpos($operations[0],'INSERT INTO `'.$prefix.'user_id_sequence`')===0,'Interrupted table creation resumes missing seed and preserves the observed floor');
        $mutation_server->pdo->exec('INSERT INTO '.$prefix.'user_id_sequence VALUES (1,0)');
        foreach($operations as $sql) { $mutation_server->pdo->exec($sql); }
        mutation_check(user_id_value('SELECT last_id FROM '.$prefix.'user_id_sequence')===300,'Missing seed restores floor from preserved ownership');
        $mutation_server->pdo->exec('DROP TABLE '.$prefix.'user_id_sequence'); $operations=array(); update_queue_user_id_sequence($operations,$reader,$prefix,false);
        mutation_check(count($operations)===2,'Absent table planned without querying missing sequence');
        $ddl=preg_replace('/\) ENGINE=InnoDB[^;]*;$/',')',str_replace('phpbb_user_id_sequence',$prefix.'user_id_sequence',$create['phpbb_user_id_sequence']));
        if($mutation_server->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') { $ddl=preg_replace('/\b(?:tinyint|int)\([0-9]+\)(?: unsigned)?/i','INTEGER',$ddl); }
        $mutation_server->pdo->exec($ddl); foreach($operations as $sql) { $mutation_server->pdo->exec($sql); }
        mutation_check(user_id_value('SELECT COUNT(*) FROM '.$prefix.'users')===3 && user_id_value('SELECT COUNT(*) FROM '.$prefix.'album_cat')===2 && phpbb_allocate_user_id($db,$prefix)===301,'Canonical create+seed leaves all account and plugin records intact');
    }
    echo "User ID sequence additive migration and monotonic planning checks passed.\n";
}
finally { restore_error_handler(); }
