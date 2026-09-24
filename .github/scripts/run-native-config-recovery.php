<?php
// Own only a random loopback schema; never load a forum configuration.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_CONFIG_RECOVERY_NATIVE') !== '1') { return; }
$recovery_port = getenv('PHPBB_CONFIG_RECOVERY_PORT') ?: '3306';
if (!preg_match('/^[0-9]{1,5}$/D', $recovery_port) || (int)$recovery_port < 1 || (int)$recovery_port > 65535) { throw new RuntimeException('Invalid fixture port'); }
$recovery_password = getenv('PHPBB_CONFIG_RECOVERY_PASSWORD') ?: '';
$recovery_schema = 'codex_config_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
$recovery_control = new PDO('mysql:host=127.0.0.1;port=' . $recovery_port . ';charset=utf8mb4', 'root', $recovery_password, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$recovery_created = false;
$recovery_old_dsn = getenv('PHPBB_CONFIG_TEST_DSN'); $recovery_old_password = getenv('PHPBB_CONFIG_TEST_PASSWORD');
try
{
    $recovery_control->exec('CREATE DATABASE ' . $recovery_schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $recovery_created = true;
    putenv('PHPBB_CONFIG_TEST_DSN=mysql:host=127.0.0.1;port=' . $recovery_port . ';dbname=' . $recovery_schema . ';charset=utf8mb4');
    putenv('PHPBB_CONFIG_TEST_PASSWORD=' . $recovery_password);
    require __DIR__ . '/check-maintenance-config.php';
}
finally
{
    if ($recovery_created) { $recovery_control->exec('DROP DATABASE ' . $recovery_schema); }
    putenv($recovery_old_dsn === false ? 'PHPBB_CONFIG_TEST_DSN' : 'PHPBB_CONFIG_TEST_DSN=' . $recovery_old_dsn);
    putenv($recovery_old_password === false ? 'PHPBB_CONFIG_TEST_PASSWORD' : 'PHPBB_CONFIG_TEST_PASSWORD=' . $recovery_old_password);
}
