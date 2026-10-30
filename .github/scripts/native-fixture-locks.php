<?php
// Test fixtures only. A peer used for synchronous contention probes must report
// a real lock conflict immediately; it cannot wait for its caller to release it.
function native_fixture_conflict_wait($server_version)
{
 if (!is_string($server_version)) { return 1; }
 if (preg_match('/(?:^|-)([0-9]+\.[0-9]+\.[0-9]+)-MariaDB(?:-|$)/i', $server_version, $match) === 1 &&
     version_compare($match[1], '10.3.0', '>=')) { return 0; }
 // MySQL and older/unknown servers require a positive timeout.
 return 1;
}

function native_fixture_peer_nowait($database)
{
 if (PHP_SAPI !== 'cli' || !is_object($database)) { throw new RuntimeException('Native fixture connection required'); }
 $result = $database->sql_query('SELECT DATABASE() AS fixture_schema,VERSION() AS fixture_server');
 if (!$result) { throw new RuntimeException('Native fixture identity unavailable'); }
 try { $row = $database->sql_fetchrow($result); }
 finally { $database->sql_freeresult($result); }
 if (!is_array($row) || !isset($row['fixture_schema'], $row['fixture_server']) ||
     preg_match('/^codex_[a-z0-9_]+_[a-f0-9]{12,16}$/D', $row['fixture_schema']) !== 1)
 { throw new RuntimeException('Owned disposable fixture schema required'); }
 $wait = native_fixture_conflict_wait($row['fixture_server']);
 if (!$database->sql_query('SET SESSION innodb_lock_wait_timeout=' . $wait)) { throw new RuntimeException('Native fixture timeout unavailable'); }
 $result = $database->sql_query('SELECT @@SESSION.innodb_lock_wait_timeout AS fixture_wait');
 if (!$result) { throw new RuntimeException('Native fixture timeout readback unavailable'); }
 try { $readback = $database->sql_fetchrow($result); }
 finally { $database->sql_freeresult($result); }
 if (!is_array($readback) || !isset($readback['fixture_wait']) || (string)$readback['fixture_wait'] !== (string)$wait)
 { throw new RuntimeException('Native fixture timeout readback differs'); }
 return $wait;
}

// DDL/reset work must retain its ordinary wait. Capture the real conflict error
// before restoring the timeout, since the successful SET clears driver errno.
function native_fixture_conflict_query($database, $sql)
{
 if (PHP_SAPI !== 'cli' || !is_object($database)) { throw new RuntimeException('Native fixture connection required'); }
 $result = $database->sql_query('SELECT @@SESSION.innodb_lock_wait_timeout AS fixture_wait');
 if (!$result) { throw new RuntimeException('Native fixture previous timeout unavailable'); }
 try { $row = $database->sql_fetchrow($result); }
 finally { $database->sql_freeresult($result); }
 if (!is_array($row) || !isset($row['fixture_wait']) || !preg_match('/^[0-9]{1,10}$/D', (string)$row['fixture_wait']))
 { throw new RuntimeException('Native fixture previous timeout invalid'); }
 $previous = (int)$row['fixture_wait'];
 native_fixture_peer_nowait($database);
 try { $result = $database->sql_query($sql); return array($result, $database->sql_error()); }
 finally
 {
  if (!$database->sql_query('SET SESSION innodb_lock_wait_timeout=' . $previous))
  { throw new RuntimeException('Native fixture previous timeout could not be restored'); }
 }
}

