<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_CHECKER_NATIVE')!=='1'){echo "Native download file checker fixture skipped.\n";return;}
define('IN_ADMIN',true);putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');require __DIR__.'/check-pafiledb-file-coordinator-native.php';
