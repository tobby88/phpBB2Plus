<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_POST_TOMBSTONE_NATIVE')!=='1'){echo "Native post tombstone fixture skipped.\n";return;}
putenv('PHPBB_POST_DELETE_NATIVE=1');putenv('PHPBB_POST_DELETE_TOMBSTONE_ONLY=1');require __DIR__.'/check-post-delete-native.php';
