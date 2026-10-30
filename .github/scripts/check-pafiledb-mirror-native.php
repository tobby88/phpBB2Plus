<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_MIRROR_NATIVE')!=='1'){echo "Native download mirror fixture skipped.\n";return;}
if(!isset($argv[1])||$argv[1]!=='public'){define('IN_ADMIN',true);}
putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');require __DIR__.'/check-pafiledb-file-coordinator-native.php';
