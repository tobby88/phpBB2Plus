<?php
// Test-only peer: keep the real statement and InnoDB conflict, skip its idle wait.
// MySQL's minimum row timeout is one second; MariaDB also accepts zero (NOWAIT).
function poll_fixture_peer_timeout($pdo)
{
    if (PHP_SAPI !== 'cli' || empty($GLOBALS['native']) || !($pdo instanceof PDO) ||
        !isset($GLOBALS['dsn']) || !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=codex_poll_mtnc_[a-f0-9]{16};charset=utf8mb4$/D', $GLOBALS['dsn'])) {
        throw new RuntimeException('Only the owned loopback poll peer may change its wait');
    }
    $seconds = stripos($pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') !== false ? 0 : 1;
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=' . $seconds);
    if ((int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn() !== $seconds) {
        throw new RuntimeException('Native poll peer timeout was not applied');
    }
    return $seconds;
}

function poll_fixture_schema_checks()
{
    $checks = 0; $names = array('users', 'topics', 'polls', 'options', 'voters', 'junior', 'sessions');
    $check = function($ok, $message) use (&$checks) { poll_mtnc_check($ok, $message); $checks++; };
    $snapshot = function($server) use ($names) {
        $out = array();
        foreach ($names as $name) {
            $rows = array_map('serialize', $server->pdo->query('SELECT * FROM fixture_' . $name)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows, SORT_STRING); $out[$name] = $rows;
        }
        return $out;
    };
    $GLOBALS['poll_mtnc_server']->pdo->exec('DROP TABLE fixture_junior');
    $cold = new PollMaintenanceServer('InnoDB'); $expected = $cold->schema_signature($names); $rows = $snapshot($cold);
    $check(!$cold->native_schema_reused && $expected['__triggers'] === false, 'Missing owned schema is constructed without triggers');
    $cold->pdo->exec("INSERT INTO fixture_users VALUES(999,'stale',1,0,0)");
    $cold->pdo->exec('INSERT INTO fixture_topics VALUES(999,1)');
    $cold->pdo->exec("INSERT INTO fixture_polls VALUES(999,999,'stale',9,9)");
    $cold->pdo->exec("INSERT INTO fixture_options VALUES(999,9,'stale',99)");
    $cold->pdo->exec("INSERT INTO fixture_voters VALUES(999,999,'stale')");
    $cold->pdo->exec("INSERT INTO fixture_junior VALUES(999,'stale')");
    $cold->pdo->exec("INSERT INTO fixture_sessions VALUES('stale',999,1,1)");
    $cold->pdo->exec("UPDATE fixture_users SET username='changed',user_active=0 WHERE user_id=1");
    $warm = new PollMaintenanceServer('InnoDB');
    $check($warm->native_schema_reused && $warm->schema_signature($names) === $expected, 'Exactly matching native table definitions are reused');
    $check($snapshot($warm) === $rows && !$warm->pdo->inTransaction(), 'All seven complete original data states restored and seed transaction closed');
    $warm->pdo->exec('ALTER TABLE fixture_options ADD COLUMN fixture_drift INTEGER NULL');
    $drift = new PollMaintenanceServer('InnoDB');
    $check(!$drift->native_schema_reused && $drift->schema_signature($names) === $expected && $snapshot($drift) === $rows, 'Column drift forces exact schema and data reconstruction');
    $drift->pdo->exec('DROP TABLE fixture_sessions'); $missing = new PollMaintenanceServer('InnoDB');
    $check(!$missing->native_schema_reused && $missing->schema_signature($names) === $expected && $snapshot($missing) === $rows, 'Missing session table cannot contaminate subsequent authority cases');
    $missing->pdo->exec('CREATE TRIGGER fixture_poll_probe BEFORE INSERT ON fixture_users FOR EACH ROW SET NEW.user_active=0');
    $trigger = new PollMaintenanceServer('InnoDB');
    $check(!$trigger->native_schema_reused && $trigger->schema_signature($names) === $expected && $snapshot($trigger) === $rows, 'Unexpected real trigger is removed before original fixture seeding');
    $legacy = new PollMaintenanceServer('MyISAM'); $legacy_schema = $legacy->schema_signature($names);
    $check(!$legacy->native_schema_reused && $legacy_schema !== $expected && $snapshot($legacy) === $rows, 'Real legacy engine remains distinct with all original rows');
    $legacy_warm = new PollMaintenanceServer('MyISAM');
    $check($legacy_warm->native_schema_reused && $legacy_warm->schema_signature($names) === $legacy_schema && $snapshot($legacy_warm) === $rows, 'Legacy refusal cases also start with fresh matching data');
    $switched = new PollMaintenanceServer('InnoDB');
    $check(!$switched->native_schema_reused && $switched->schema_signature($names) === $expected && $snapshot($switched) === $rows && !$switched->pdo->inTransaction(), 'Engine switch restores exact InnoDB schema, original rows and idle seed state');
    $forum = new PollMaintenanceForum();
    $check($forum->dbname === $switched->pdo->query('SELECT DATABASE()')->fetchColumn() && preg_match('/^codex_poll_mtnc_[a-f0-9]{16}$/D', $forum->dbname) === 1, 'Actual application advisory namespace follows this owned database');
    unset($cold, $warm, $drift, $missing, $trigger, $legacy, $legacy_warm, $switched, $forum);
    echo 'Native poll fixture schema: ', $checks, " checks PASS; exact warm schema, seven original data states, missing/column/trigger drift, legacy refusal, engine switch and closed seed transactions.\n";
}

function poll_fixture_peer_wait_checks()
{
    global $poll_mtnc_server, $dsn;
    $checks = 0;
    $check = function($ok, $message) use (&$checks) { poll_mtnc_check($ok, $message); $checks++; };
    poll_mtnc_fixture('InnoDB');
    $peer = $poll_mtnc_server->pdo;
    $worker = new PollMaintenanceConnection($poll_mtnc_server);
    $password = getenv('PHPBB_POLL_MAINTENANCE_TEST_PASSWORD') ?: '';
    $blocker = new PDO($dsn, 'root', $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $state_sql = 'SELECT CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode sql_mode,@@SESSION.innodb_lock_wait_timeout row_wait,@@GLOBAL.innodb_lock_wait_timeout global_wait';
    $peer_state = $peer->query($state_sql)->fetch(PDO::FETCH_ASSOC);
    $worker_state = $worker->pdo->query($state_sql)->fetch(PDO::FETCH_ASSOC);
    $blocker_state = $blocker->query($state_sql)->fetch(PDO::FETCH_ASSOC);
    $expected_wait = stripos($peer->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') !== false ? 0 : 1;
    $snapshot = poll_session_snapshot();
    $sessions = $peer->query('SELECT * FROM fixture_sessions ORDER BY session_id')->fetchAll(PDO::FETCH_ASSOC);
    $elapsed = null; $lease = null;
    try {
        $check((int) $peer_state['row_wait'] === $expected_wait, 'Only the explicit poll fixture peer has its engine-specific minimum wait');
        $check($peer_state['id'] !== $worker_state['id'] && $peer_state['id'] !== $blocker_state['id'] && $worker_state['id'] !== $blocker_state['id'], 'Three genuinely independent native connections');
        $check($worker_state['row_wait'] === $worker_state['global_wait'] && $blocker_state['row_wait'] === $blocker_state['global_wait'], 'Application worker and blocker retain server defaults');
        $check($blocker->beginTransaction(), 'Own real blocking transaction');
        $check(count($blocker->query("SELECT * FROM fixture_sessions WHERE session_id='fixture-sid' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC)) === 1, 'Actual owned session row is locked');
        $denied = false; $start = microtime(true);
        try { $peer->exec("UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-sid'"); }
        catch (PDOException $error) {
            if (!isset($error->errorInfo[1]) || (int) $error->errorInfo[1] !== 1205) { throw $error; }
            $denied = true;
        }
        $elapsed = microtime(true) - $start;
        $check($denied && $elapsed < 5.0, 'Actual UPDATE still fails with native row-lock conflict 1205');
        $check($blocker->inTransaction() && !$peer->inTransaction(), 'Failed peer does not end the independent owner transaction');
        $check($peer->query('SELECT * FROM fixture_sessions ORDER BY session_id')->fetchAll(PDO::FETCH_ASSOC) === $sessions, 'Failed revocation leaves the current session unchanged');
        $check($blocker->rollBack(), 'Release only our own blocking transaction');
        $check($peer->beginTransaction(), 'Own post-release retry transaction');
        $check($peer->exec("UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-sid'") === 1, 'Identical real UPDATE succeeds after the owner releases its row');
        $check((int) $peer->query("SELECT session_logged_in FROM fixture_sessions WHERE session_id='fixture-sid'")->fetchColumn() === 0, 'Actual retry write is visible to its owner');
        $check((int) $worker->pdo->query("SELECT session_logged_in FROM fixture_sessions WHERE session_id='fixture-sid'")->fetchColumn() === 1, 'Uncommitted retry remains private from the real worker');
        $check($peer->rollBack(), 'Undo only the owned probe write');
        $check($peer->query($state_sql)->fetch(PDO::FETCH_ASSOC) === $peer_state && $worker->pdo->query($state_sql)->fetch(PDO::FETCH_ASSOC) === $worker_state && $blocker->query($state_sql)->fetch(PDO::FETCH_ASSOC) === $blocker_state, 'Caller, independent worker and global settings are unchanged');
        $check(poll_session_snapshot() === $snapshot && $peer->query('SELECT * FROM fixture_sessions ORDER BY session_id')->fetchAll(PDO::FETCH_ASSOC) === $sessions, 'Every original fixture row survives the real conflict and retry');
        $fresh = new PDO($dsn, 'root', $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $fresh_state = $fresh->query($state_sql)->fetch(PDO::FETCH_ASSOC);
        $check($fresh_state['row_wait'] === $worker_state['global_wait'] && $fresh_state['global_wait'] === $worker_state['global_wait'], 'Future connections still inherit the original server default');
        $fresh = null;
        $forum = new PollMaintenanceForum(); $lease = new attach_mutation_lock($forum, false);
        $name = 'attachment:' . md5($forum->dbname . "\0" . ATTACHMENTS_TABLE);
        $check($lease->acquired, 'Actual application lease uses this independent fixture database');
        $lease_id = $lease->connection->pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $check((string) $peer->query('SELECT IS_USED_LOCK(' . $peer->quote($name) . ')')->fetchColumn() === (string) $lease_id, 'Real advisory lock belongs to the exact application connection and owned schema');
        $competitor = new attach_mutation_lock(new PollMaintenanceForum(), false);
        try { $check(!$competitor->acquired, 'Real competing application lease conflicts in the same schema'); }
        finally { $competitor->release(); }
        $lease->release();
        $check($peer->query('SELECT IS_USED_LOCK(' . $peer->quote($name) . ')')->fetchColumn() === null, 'Only the owned application lease is released');
    } finally {
        if ($lease !== null) { $lease->release(); }
        if ($peer->inTransaction()) { $peer->rollBack(); }
        if ($blocker->inTransaction()) { $blocker->rollBack(); }
        $worker->sql_close();
        $blocker = null;
    }
    $check(!$peer->inTransaction() && $worker->closed && $poll_mtnc_server->owner === null, 'Owned transactions, connection and application lease are released');
    echo 'Native poll fixture peer waits: 5 cases, ', $checks, ' assertions PASS; actual locked UPDATE1205 and post-release retry, unchanged application/default waits and data; ', json_encode(array('peer_wait_seconds' => $expected_wait, 'conflict_seconds' => round($elapsed, 3), 'production_access' => false)), "\n";
}
