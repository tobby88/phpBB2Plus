<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_FILE_AUTHORITY_NATIVE')!=='1'){echo "Native download file authority fixture skipped.\n";return;}
putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');require __DIR__.'/check-pafiledb-file-coordinator-native.php';
