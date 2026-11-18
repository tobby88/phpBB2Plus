<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_DB_BACKUP_NATIVE')!=='1'){echo "Native backup column checks require an explicitly enabled disposable database.\n";return;}
$case=isset($argv[1])?$argv[1]:'full';
if(!in_array($case,array('full','gzip','data','structure','empty','read-failure','metadata-failure','ddl'),true)){throw new RuntimeException('Unknown backup column case');}
$argv[1]='columns-'.$case;require __DIR__.'/check-db-backup-native.php';
