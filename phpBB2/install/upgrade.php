<?php
// Historical phpBB 1 conversion is not a supported entrypoint in this package.
if (PHP_SAPI !== 'cli') { http_response_code(410); header('Content-Type: text/plain; charset=UTF-8'); }
echo "This historical updater is disabled. Use update/update_from_153a.php for Plus 1.53a or later.\n";
echo "For older versions, first prepare an isolated 1.53a copy using the matching historical release. See update/README.md.\n";
exit(1);

// phpBB1 database upgrades are outside this preserved phpBB2 Plus build.
http_response_code(410);
die('The historical phpBB1-to-phpBB2 upgrader is not included. Use a dedicated archival migration environment.');
