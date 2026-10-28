<?php
// Disposable loopback schema only. No forum config or installed index is read.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_REBUILD_NATIVE') !== '1') { exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$port = getenv('PHPBB_REBUILD_TEST_PORT') ?: '3306';
if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port < 1 || (int)$port > 65535) { throw new RuntimeException('Invalid rebuild fixture port'); }
$control = mysqli_connect('127.0.0.1', 'root', getenv('PHPBB_REBUILD_TEST_PASSWORD') ?: '', '', (int)$port);
if (!$control) { throw new RuntimeException('Own loopback server unavailable'); }
$name = 'codex_rebuild_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
if (!mysqli_query($control, 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) { throw new RuntimeException('Own rebuild fixture creation failed'); }
putenv('PHPBB_REBUILD_TEST_DSN=mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $name . ';charset=utf8mb4');
try { require __DIR__ . '/check-maintenance-rebuild.php'; }
finally { mysqli_query($control, 'DROP DATABASE `' . $name . '`'); mysqli_close($control); putenv('PHPBB_REBUILD_TEST_DSN'); }
