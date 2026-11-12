<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_NEWS_READERS_NATIVE')!=='1'){echo "Native News pagination checks require an explicitly enabled disposable database.\n";return;}
define('PHPBB_NEWS_PAGINATION_FIXTURE',true);
require __DIR__.'/check-news-tombstones-native.php';
