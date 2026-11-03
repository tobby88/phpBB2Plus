<?php
require __DIR__ . '/admin-history-label-fixture.php';
ah_suite();
echo 'Admin history labels: ', $ah_cases, ' cases, ', $al_checks, " assertions PASS; actual EN/DE maintenance/recovery statements and real log/XS templates, stored identities and numeric account details.\n";
restore_error_handler();
