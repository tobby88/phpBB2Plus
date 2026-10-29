<?php
/** Preview or resume durable Album file operations; never infer orphan ownership. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require_once __DIR__.'/album_file_recovery.php';
try{$options=plus_album_recovery_options(array_slice($argv,1));}
catch(InvalidArgumentException$error){fwrite(STDERR,$error->getMessage()."\nUse --help. No changes made.\n");exit(2);}
if($options['help']){
	echo "Album file recovery (CLI only)\n\n";
	echo "php update/recover_album_files.php                         Preview eligible journal headers only\n";
	echo "php update/recover_album_files.php --apply --backup-confirmed  Resume bounded recovery\n";
	echo "--jobs=10 (1..100) --items=100 (1..1000 total) --older-than-days=7 (1..3650)\n";
	echo "--after=32-lowercase-hex-operation-id resumes a journal page.\n\n";
	echo "Uses this project's phpBB2/config.php, or the forum root's config.php in a flat installation.\n";
	echo "The configuration and Album directories belong together; no database/root override.\n";
	echo "Back up both database and Album files before --apply. Recent/published uploads are untouched.\n";
	echo "An unfinished job retains the preceding next_after cursor; repeat it to continue pending items.\n";
	echo "Finished/skipped/shared/changed receipts remain recorded. No filenames or capabilities are printed.\n";
	echo "A failed/unconfirmed run stops; inspect the durable journal before resuming.\n";exit(0);
}
$database=null;
try{
	$root=plus_album_recovery_installation();
	if(!is_file($root.'/config.php')||is_link($root.'/config.php')){throw new RuntimeException('Run recovery from the configured installation with its own config.php');}
	// The application root and credentials come from the same installation.
	// A clone DB override must never acquire cleanup authority over live files.
	$config=function($path){require$path;return array('host'=>isset($dbhost)?$dbhost:null,'user'=>isset($dbuser)?$dbuser:null,'password'=>isset($dbpasswd)?$dbpasswd:null,'database'=>isset($dbname)?$dbname:null,'prefix'=>isset($table_prefix)?$table_prefix:null);};$config=$config($root.'/config.php');
	foreach($config as$key=>$value){if(!is_string($value)||($key!=='password'&&$value==='')){throw new RuntimeException('Incomplete local installation configuration');}}
	if(!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$config['prefix'])){throw new RuntimeException('Invalid local Album namespace');}
	require_once$root.'/includes/php_compat.php';require_once$root.'/db/mysqli.php';
	$database=new sql_db($config['host'],$config['user'],$config['password'],$config['database'],false);unset($config['password']);
	if(!$database->db_connect_id){throw new RuntimeException('Local Album database connection unavailable');}
	$recovery=new PlusAlbumFileRecovery($database,$config['prefix'],$root);$report=$recovery->run($options);
	$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($json===false){throw new RuntimeException('Recovery report encoding failed');}echo$json,"\n";
}catch(Exception$error){fwrite(STDERR,"Album recovery stopped without confirming completion. Retain backups and inspect durable journal state before resuming.\n");exit(3);}
catch(Error$error){fwrite(STDERR,"Album recovery stopped without confirming completion. Retain backups and inspect durable journal state before resuming.\n");exit(3);}
finally{if($database!==null){$database->sql_close();}}
