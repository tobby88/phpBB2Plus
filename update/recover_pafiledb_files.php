<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit(2); }
require_once __DIR__.'/pafiledb_file_recovery.php';
try { $options=plus_pa_recovery_options(array_slice($argv,1)); }
catch(InvalidArgumentException $error){fwrite(STDERR,$error->getMessage()."\nUse --help. No changes made.\n");exit(2);}
if($options['help']){
    echo "Download file recovery (CLI only)\n\nphp update/recover_pafiledb_files.php  Preview pending receipts without file operations\n";
    echo "php update/recover_pafiledb_files.php --apply --backup-confirmed --items=50\n";
    echo "--items=1..500; --after=operation-id:item-index continues after attempted receipts.\n";
    echo "Use the returned next_after for subsequent pages, including pages with pending changed files.\n";
    echo "Start a new scan without --after to retry earlier pending receipts. A short page ends this scan.\n";
    echo "Uses only this installation's config.php, database, board origin and download directories.\n";
    echo "Back up the database and download files before --apply. No orphan-directory scan or filenames are printed.\n";
    echo "Changed/shared assets are protected. An uncertain run must be retried from the previous cursor.\n";exit(0);
}
$database=null;
try{
    $root=plus_pa_recovery_installation();
    if(!is_file($root.'/config.php')||is_link($root.'/config.php')){throw new RuntimeException('Configured local installation required');}
    $load=function($path){require $path;return array('host'=>isset($dbhost)?$dbhost:null,'user'=>isset($dbuser)?$dbuser:null,'password'=>isset($dbpasswd)?$dbpasswd:null,'database'=>isset($dbname)?$dbname:null,'prefix'=>isset($table_prefix)?$table_prefix:null);};$config=$load($root.'/config.php');
    foreach($config as$key=>$value){if(!is_string($value)||($key!=='password'&&$value==='')){throw new RuntimeException('Incomplete local installation configuration');}}
    require_once $root.'/includes/php_compat.php';require_once $root.'/db/mysqli.php';
    $database=new sql_db($config['host'],$config['user'],$config['password'],$config['database'],false);unset($config['password']);
    if(!$database->db_connect_id){throw new RuntimeException('Local download database connection unavailable');}
    $recovery=new PlusPaFileRecovery($database,$config['prefix'],$root);$report=$recovery->run($options);
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($json===false){throw new RuntimeException('Recovery report encoding failed');}echo $json,"\n";
}catch(Exception$error){fwrite(STDERR,"Download recovery stopped without confirming completion. Retain backups, inspect the journal and retry from the preceding cursor.\n");exit(3);}
catch(Error$error){fwrite(STDERR,"Download recovery stopped without confirming completion. Retain backups, inspect the journal and retry from the preceding cursor.\n");exit(3);}
finally{if($database){$database->sql_close();}}
