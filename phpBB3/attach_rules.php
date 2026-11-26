<?php
require __DIR__ . '/ext/phpbb2plus/community/legacy_links.php';
phpbb2plus_redirect_legacy('attach_rules', array('f' => $_GET['f'] ?? '0'));
