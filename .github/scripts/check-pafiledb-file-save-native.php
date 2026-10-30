<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_FILE_SAVE_NATIVE')!=='1'){echo "Native download file save fixture skipped.\n";return;}
if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');
require __DIR__.'/check-pafiledb-file-coordinator-native.php';
