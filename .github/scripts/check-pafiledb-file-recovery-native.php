<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_FILE_RECOVERY_NATIVE')!=='1'){echo "Native download file recovery fixture skipped.\n";return;}
putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');
require __DIR__.'/check-pafiledb-file-coordinator-native.php';
