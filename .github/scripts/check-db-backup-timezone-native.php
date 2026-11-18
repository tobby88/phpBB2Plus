<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_DB_BACKUP_NATIVE')!=='1'){echo "Native backup timezone checks require an explicitly enabled disposable database.\n";return;}
$case=isset($argv[1])?$argv[1]:'full';
if(!in_array($case,array('full','gzip','data','structure','east-west','west-east','utc-failure','read-failure'),true)){throw new RuntimeException('Unknown backup timezone case');}
$argv[1]='timezone-'.$case;require __DIR__.'/check-db-backup-native.php';
