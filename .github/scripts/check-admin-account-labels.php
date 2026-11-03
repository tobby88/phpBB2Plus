<?php
require __DIR__ . '/admin-account-label-fixture.php';
al_label_suite();
echo 'Admin account labels: ', $al_cases, ' cases, ', $al_checks, " assertions PASS; five actual bindings, real EN/DE templates, attribute round-trips and raw metadata.\n";
restore_error_handler();
