<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_CT_SETTINGS_NATIVE')!=='1'){echo "Native CrackerTracker settings fixture skipped.\n";return;}
putenv('PHPBB_CT_LOG_AUTHORITY_NATIVE=1');
putenv('PHPBB_CT_LOG_AUTHORITY_PORT='.(getenv('PHPBB_CT_SETTINGS_PORT')?:'3306'));
putenv('PHPBB_CT_LOG_AUTHORITY_PASSWORD='.(getenv('PHPBB_CT_SETTINGS_PASSWORD')?:''));
// Reuse only the canonical disposable DB/actor/file harness, not its log tests.
require __DIR__.'/check-ctracker-log-authority-native.php';
