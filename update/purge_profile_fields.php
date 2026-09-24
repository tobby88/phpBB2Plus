<?php
// Explicit, one-operation-at-a-time offline erasure; never called by install,
// the general updater or a web request. Default mode is read-only inspection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }

function phpbb_profile_cleanup_arguments($arguments)
{
    $options = array();
    foreach ($arguments as $argument)
    {
        if (!is_string($argument) || !preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $argument, $match)) { throw new RuntimeException('Invalid argument'); }
        $key = $match[1]; $flags = array('help','apply','backup-confirmed','maintenance-confirmed','erase-confirmed');
        if (array_key_exists($key, $options) || !in_array($key, array_merge($flags, array('config','kind','operation','confirm')), true))
        { throw new RuntimeException('Unknown or duplicate option'); }
        if (in_array($key, $flags, true))
        { if (isset($match[2])) { throw new RuntimeException('Flags do not take values'); } $options[$key] = true; }
        else { if (!isset($match[2]) || $match[2] === '') { throw new RuntimeException('Option needs a value'); } $options[$key] = $match[2]; }
    }
    if (isset($options['kind']) !== isset($options['operation'])) { throw new RuntimeException('Select both kind and operation'); }
    if (isset($options['kind']) && !in_array($options['kind'], array('retired','staged'), true)) { throw new RuntimeException('Invalid cleanup kind'); }
    foreach (array('operation','confirm') as $key)
    { if (isset($options[$key]) && !preg_match('/^[a-f0-9]{64}$/D', $options[$key])) { throw new RuntimeException('Invalid operation/confirmation'); } }
    if (isset($options['apply']))
    {
        foreach (array('kind','operation','confirm','backup-confirmed','maintenance-confirmed','erase-confirmed') as $key)
        { if (!isset($options[$key])) { throw new RuntimeException('Apply requires an exact preview and all three confirmations'); } }
    }
    elseif (isset($options['confirm']) || isset($options['backup-confirmed']) || isset($options['maintenance-confirmed']) || isset($options['erase-confirmed']))
    { throw new RuntimeException('Confirmation flags require --apply'); }
    return $options;
}

$worker = null; $database = null; $exit_code = 0;
try
{
    $options = phpbb_profile_cleanup_arguments(array_slice($argv, 1));
    if (isset($options['help']))
    {
        echo "Offline custom profile cleanup (database-owner CLI only)\n\n";
        echo "  php update/purge_profile_fields.php                     List receipts; no data changes\n";
        echo "  php update/purge_profile_fields.php --kind=retired --operation=<64-hex>  Preview one removal\n";
        echo "  Use --kind=staged for a never-published creation receipt.\n";
        echo "  Add --apply --confirm=<preview-token> --backup-confirmed --maintenance-confirmed --erase-confirmed\n";
        echo "  ONLY after verifying a backup and stopping/draining ALL web/cron requests (readers too).\n";
        echo "  board_disable is not enough; old readers may still reference the removed column.\n";
        echo "  --config=/absolute/path/config.php selects a trusted config outside the webroot.\n";
        echo "  Erasure permanently drops that column and its values. Retain backups according to your policy.\n";
        echo "  DDL is not rolled back. After failure, preview/retry the SAME operation; do not edit receipts.\n";
    }
    else
    {
        $phpbb_root_path = dirname(__DIR__) . '/phpBB2/'; $phpEx = 'php';
        define('IN_PHPBB', true);
        $config = isset($options['config']) ? $options['config'] : $phpbb_root_path . 'config.php';
        $config = realpath($config);
        if ($config === false || !is_file($config)) { throw new RuntimeException('Trusted config not found'); }
        require $config;
        foreach (array('dbhost','dbuser','dbpasswd','dbname','table_prefix') as $key)
        { if (!isset($$key) || !is_string($$key)) { throw new RuntimeException('Incomplete database configuration'); } }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table_prefix) || $dbname === ''
            || (isset($dbms) && !in_array($dbms, array('mysql','mysql4','mysqli'), true))) { throw new RuntimeException('Unsupported database configuration'); }
        require_once $phpbb_root_path . 'includes/php_compat.php';
        require_once $phpbb_root_path . 'includes/constants.php';
        require_once $phpbb_root_path . 'attach_mod/includes/constants.php';
        require_once $phpbb_root_path . 'db/mysqli.php';
        require_once __DIR__ . '/profile_field_cleanup.php';
        $database = new sql_db($dbhost, $dbuser, $dbpasswd, $dbname, false);
        if (!$database->db_connect_id) { throw new RuntimeException('Database unavailable'); }
        $worker = new PhpbbProfileFieldCleanup($database);
        if (!isset($options['operation'])) { $result = array('mode'=>'read-only inventory','receipts'=>$worker->inventory()); }
        elseif (!isset($options['apply'])) { $result = array('mode'=>'read-only preview','plan'=>$worker->plan($options['kind'], $options['operation'])); }
        else { $result = array('mode'=>'confirmed erasure','plan'=>$worker->apply($options['kind'], $options['operation'], $options['confirm'], true, true, true)); }
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new RuntimeException('Cannot render cleanup result'); }
        echo $json . "\n";
    }
}
catch (Exception $error)
{
    // Never echo driver SQL, configuration secrets, profile values or snapshots.
    if ($error instanceof PhpbbProfileCleanupRefusal) { fwrite(STDERR, $error->getMessage() . "\n"); }
    fwrite(STDERR, "Cleanup not confirmed. Check options (--help), current schema, receipt ownership and offline prerequisites. Preview/retry the SAME operation after an interrupted apply.\n"); $exit_code = 2;
}
catch (Throwable $error)
{ fwrite(STDERR, 'Cleanup runtime error (' . get_class($error) . ' in ' . basename($error->getFile()) . ':' . $error->getLine() . "). No result confirmed; inspect the receipt/schema before retrying.\n"); $exit_code = 2; }
finally { if ($worker) { $worker->release(); } if ($database) { $database->sql_close(); } }
exit($exit_code);
