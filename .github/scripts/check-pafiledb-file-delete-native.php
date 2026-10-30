<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_FILE_DELETE_NATIVE')!=='1'){echo "Native download file deletion fixture skipped.\n";return;}
if(isset($argv[1])&&$argv[1]==='acp'){define('IN_ADMIN',true);}
putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');require __DIR__.'/check-pafiledb-file-coordinator-native.php';
