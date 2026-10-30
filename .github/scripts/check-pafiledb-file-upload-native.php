<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_FILE_UPLOAD_NATIVE')!=='1'){echo "Native download HTTP upload fixture skipped.\n";return;}
putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');
require __DIR__.'/check-pafiledb-file-coordinator-native.php';
