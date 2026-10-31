<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_CT_ACTIONS_NATIVE')!=='1'){echo "Native CrackerTracker actions fixture skipped.\n";return;}
putenv('PHPBB_CT_LOG_AUTHORITY_NATIVE=1');
putenv('PHPBB_CT_LOG_AUTHORITY_PORT='.(getenv('PHPBB_CT_ACTIONS_PORT')?:'3306'));
putenv('PHPBB_CT_LOG_AUTHORITY_PASSWORD='.(getenv('PHPBB_CT_ACTIONS_PASSWORD')?:''));
require __DIR__.'/check-ctracker-log-authority-native.php';
