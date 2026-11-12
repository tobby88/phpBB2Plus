<?php
require __DIR__.'/news-tombstone-fixture.php';require __DIR__.'/news-pagination-fixture.php';$ng_cases=$ng_views=0;
try{ng_pure_suite();echo 'News pagination: ',$ng_cases,' boundary cases, ',$ng_views,' actual dispatcher/XS views, ',$nt_checks," assertions PASS; effective size/start, aliases, validated index/category links, fallback limits and no orphan comments.\n";}finally{restore_error_handler();}
