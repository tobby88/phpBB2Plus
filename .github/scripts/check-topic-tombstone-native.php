<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_TOPIC_TOMBSTONE_NATIVE')!=='1'){echo "Native topic tombstone fixture skipped.\n";return;}
putenv('PHPBB_TOPIC_REMOVAL_NATIVE=1');putenv('PHPBB_POST_DELETE_TOMBSTONE_ONLY=1');require __DIR__.'/check-topic-removal-native.php';
