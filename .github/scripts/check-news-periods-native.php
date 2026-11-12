<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_NEWS_READERS_NATIVE')!=='1'){echo "Native News archive checks require an explicitly enabled disposable database.\n";return;}
define('PHPBB_NEWS_PERIOD_FIXTURE',true);
require __DIR__.'/check-news-tombstones-native.php';
