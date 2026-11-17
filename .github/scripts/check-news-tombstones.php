<?php
require __DIR__.'/news-tombstone-fixture.php';
require __DIR__.'/topic-export-fixture.php';
try{nt_render_suite();nt_export_suite();echo 'News tombstones: ',$nt_cases,' actual article/comment/XS/export views, ',$nt_checks," assertions PASS; EN/DE, guest/member bylines, compact markers, suppressed stale data/attachments, RSS dates and retained counts/links.\n";nt_export_format_suite();}finally{restore_error_handler();}
