<?php
// Reuse the installer's literal value tuples, including their SQL quoting.
function album_native_config_seed_sql($table = 'album_config')
{
    static $statements = array();
    $minimum = array('album_config' => 41, 'album_sp_config' => 11);
    if (!isset($minimum[$table])) {
        throw new RuntimeException('Unknown Album settings fixture');
    }
    if (isset($statements[$table])) {
        return $statements[$table];
    }

    $source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_basic.sql');
    preg_match_all('/^INSERT INTO phpbb_' . $table . '\b[^\r\n]*/m', $source, $lines);
    if (count($lines[0]) < $minimum[$table]) {
        throw new RuntimeException('Incomplete Album defaults in installer');
    }
    $tuples = array();
    foreach ($lines[0] as $line) {
        if (preg_match('/^INSERT INTO phpbb_' . $table . '(?:\s+\(config_name,\s*config_value\))?\s+VALUES\s+(\(.*\));\s*$/D', $line, $match) !== 1) {
            throw new RuntimeException('Unrecognized Album default in installer');
        }
        $tuples[] = $match[1];
    }
    $statements[$table] = 'INSERT INTO fixture_' . $table . ' (config_name, config_value) VALUES ' . implode(',', $tuples);
    return $statements[$table];
}

// Only prepare owned loopback fixtures. Application connections and their
// transaction, failure and concurrency boundaries remain unchanged.
function album_native_seed_batch($database, $schema, $tables, $callback)
{
 if (PHP_SAPI !== 'cli' || !is_object($database) || !isset($database->db_connect_id)
  || !($database->db_connect_id instanceof mysqli) || strpos($database->db_connect_id->host_info,'127.0.0.1')!==0
  || !is_string($schema) || preg_match('/^codex_album_[a-z_]+_[a-f0-9]{16}$/D',$schema)!==1
  || !is_array($tables) || !$tables || !is_callable($callback)) { throw new RuntimeException('Owned native Album seed required'); }
 foreach($tables as$table){if(!is_string($table)||preg_match('/^fixture_[a-z0-9_]+$/D',$table)!==1){throw new RuntimeException('Only fixture seed participants allowed');}}
 $tables=array_values(array_unique($tables));
 $rows=function($sql)use($database){$result=$database->sql_query($sql);if(!$result){throw new RuntimeException('Native Album seed read failed');}try{return$database->sql_fetchrowset($result);}finally{$database->sql_freeresult($result);}};
 $state=$rows('SELECT DATABASE() fixture_schema,@@SESSION.autocommit fixture_autocommit');
 if(count($state)!==1||$state[0]['fixture_schema']!==$schema||(string)$state[0]['fixture_autocommit']!=='1'
  ||!$database->sql_query('SET TRANSACTION READ WRITE')){throw new RuntimeException('Native Album seed cannot borrow pending work');}
 $active=false;
 try{
  if(!$database->sql_query('START TRANSACTION')){throw new RuntimeException('Native Album seed begin failed');}$active=true;
  foreach(array_chunk($tables,50)as$chunk){$rows('SELECT 1 FROM '.implode(',',$chunk).' LIMIT 0');}
  $metadata=$rows("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".implode("','",$tables)."')");
  if(count($metadata)!==count($tables)){throw new RuntimeException('Native Album seed participant missing');}
  foreach($metadata as$row){if($row['ENGINE']!=='InnoDB'){
   if(!$database->sql_query('ROLLBACK')){throw new RuntimeException('Native Album seed release failed');}$active=false;
   // Legacy-storage refusal cases retain their original autocommit setup.
   call_user_func($callback);return false;
  }}
  call_user_func($callback);
  if(!$database->sql_query('COMMIT')){throw new RuntimeException('Native Album seed commit failed');}$active=false;return true;
 }finally{if($active){$database->sql_query('ROLLBACK');}}
}
