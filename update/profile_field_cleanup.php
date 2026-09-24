<?php
// No web entry point and no automatic writes. Database-owner authority comes
// from the trusted CLI config, not from a cached ACP identity. All web/cron
// traffic must be stopped and in-flight requests drained before apply: DDL
// commits independently of receipts and old readers can still name a column.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
require_once dirname(__DIR__) . '/phpBB2/includes/functions_profile_definition_storage.php';

// Only fixed, content-free refusal reasons are safe to print at the CLI.
class PhpbbProfileCleanupRefusal extends RuntimeException {}

class PhpbbProfileFieldCleanup extends PhpbbAclDatabase
{
    private $lock;
    private $lock_name;
    private $target;
    private $in_transaction = false;
    function __construct($database)
    {
        foreach (array(USERS_TABLE, PROFILE_FIELDS_TABLE, PROFILE_FIELD_ACTIONS_TABLE, PROFILE_FIELD_JOBS_TABLE) as $table)
        { if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) { throw new PhpbbProfileCleanupRefusal('Invalid cleanup table'); } }
        if (!isset($database->server) || !is_string($database->server) || $database->server === '') { throw new PhpbbProfileCleanupRefusal('Database endpoint identity unavailable'); }
        $this->target = $database->server;
        $this->lock_name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
        // This is a writer, not optional statistics. COM_QUIT does not wait for
        // server-side disconnect/lock cleanup; an immediate GET_LOCK(..., 0)
        // can still see our own preceding, already closed connection. Use the
        // normal bounded acquisition wait, never retry a DDL/write operation.
        $this->lock = new attach_mutation_lock($database);
        if (!$this->lock->acquired) { throw new PhpbbProfileCleanupRefusal('Profile writers are busy'); }
        parent::__construct($this->lock->connection, 'Profile_definition_failed');
        register_shutdown_function(array($this, 'release'));
    }
    function release()
    {
        if ($this->connection && $this->in_transaction)
        { try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $ignored) {} catch (Throwable $ignored) {} }
        $this->in_transaction = false; $this->connection = null;
        if ($this->lock) { $this->lock->release(); }
    }
    private function token($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new PhpbbProfileCleanupRefusal('Invalid operation/confirmation token'); }
        return $token;
    }
    private function rows($sql) { return phpbb_acl_rows($this, $sql); }
    private function held()
    {
        $rows = $this->rows("SELECT IS_USED_LOCK('" . $this->lock_name . "')=CONNECTION_ID() AS held");
        if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new PhpbbProfileCleanupRefusal('Profile writer ownership lost'); }
    }
    private function storage()
    {
        $this->held();
        foreach (array(USERS_TABLE=>'user_id', PROFILE_FIELDS_TABLE=>'field_id', PROFILE_FIELD_ACTIONS_TABLE=>'operation_key', PROFILE_FIELD_JOBS_TABLE=>'operation_key') as $table=>$key)
        {
            $name = $this->sql_escape($table);
            $rows = $this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
                . " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
            if (count($rows) !== 1 || strtoupper((string)$rows[0]['ENGINE']) !== 'INNODB' || strtoupper((string)$rows[0]['ROW_FORMAT']) !== 'DYNAMIC'
                || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE')
            { throw new PhpbbProfileCleanupRefusal('Run the consolidated storage/schema updater first'); }
            $keys = $this->rows("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
            if (count($keys) !== 1 || $keys[0]['COLUMN_NAME'] !== $key) { throw new PhpbbProfileCleanupRefusal('Unexpected cleanup primary key'); }
        }
    }
    function inventory()
    {
        $this->storage(); $rows = array();
        foreach (array('retired'=>array(PROFILE_FIELD_ACTIONS_TABLE,'action_state'), 'staged'=>array(PROFILE_FIELD_JOBS_TABLE,'job_state')) as $kind=>$source)
        {
            foreach ($this->rows('SELECT operation_key,field_id,field_column,' . $source[1] . ' AS state FROM ' . $source[0] . " WHERE " . $source[1] . " IN ('" . $kind . "','purging','purged')" . ($kind === 'staged' ? ' AND field_id IS NULL' : '') . ' ORDER BY operation_key') as $row)
            { $rows[] = array('kind'=>$kind, 'operation'=>$row['operation_key'], 'field_id'=>$row['field_id'] === null ? null : (int)$row['field_id'], 'column'=>$row['field_column'], 'state'=>$row['state']); }
        }
        return $rows;
    }
    private function inspect($kind, $operation, $locking = false)
    {
        if (!in_array($kind, array('retired','staged'), true)) { throw new PhpbbProfileCleanupRefusal('Select retired or staged cleanup'); }
        $operation = $this->token($operation); $suffix = $locking ? ' FOR UPDATE' : '';
        $table = $kind === 'retired' ? PROFILE_FIELD_ACTIONS_TABLE : PROFILE_FIELD_JOBS_TABLE;
        $state_key = $kind === 'retired' ? 'action_state' : 'job_state';
        $rows = $this->rows("SELECT * FROM $table WHERE operation_key='$operation'" . $suffix);
        if (count($rows) !== 1) { throw new PhpbbProfileCleanupRefusal('Cleanup receipt not found'); }
        $receipt = $rows[0]; $column = $receipt['field_column']; $state = $receipt[$state_key];
        if ($receipt['operation_key'] !== $operation || !is_string($column) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $column) || in_array($column, phpbb_profile_definition_core_columns(), true)
            || !in_array($state, array($kind,'purging','purged'), true)) { throw new PhpbbProfileCleanupRefusal('Receipt cannot authorize cleanup'); }
        foreach ($this->rows('SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id' . $suffix) as $field)
        {
            $mapped = phpbb_profile_field_column($field);
            if ($mapped === '' || $mapped === $column || ($kind === 'retired' && (string)$field['field_id'] === (string)$receipt['field_id']))
            { throw new PhpbbProfileCleanupRefusal('An active or invalid definition prevents cleanup'); }
        }
        $actions = $this->rows('SELECT * FROM ' . PROFILE_FIELD_ACTIONS_TABLE . " WHERE field_column='$column' ORDER BY operation_key" . $suffix);
        $jobs = $this->rows('SELECT * FROM ' . PROFILE_FIELD_JOBS_TABLE . " WHERE field_column='$column' ORDER BY operation_key" . $suffix);
        if ($kind === 'retired')
        {
            if ((string)phpbb_acl_id($receipt['field_id']) !== (string)$receipt['field_id']) { throw new PhpbbProfileCleanupRefusal('Invalid retired ID'); }
            $this->token($receipt['definition_revision']); $this->token($receipt['column_signature']);
            if ($state !== 'purged')
            {
                $definition = json_decode($receipt['definition_snapshot'], true);
                if (!is_array($definition) || array_keys($definition) !== array_merge(array('field_id','field_column'), phpbb_profile_definition_fields())
                    || (string)$definition['field_id'] !== (string)$receipt['field_id']
                    || phpbb_profile_field_column($definition) !== $column
                    || !hash_equals($receipt['definition_revision'], phpbb_profile_definition_revision($definition)))
                { throw new PhpbbProfileCleanupRefusal('Retired snapshot changed'); }
            }
            elseif ($receipt['definition_snapshot'] !== '') { throw new PhpbbProfileCleanupRefusal('Incomplete purged receipt'); }
            foreach ($actions as $other)
            { if ($other['operation_key'] !== $operation && $other['action_state'] !== 'restored') { throw new PhpbbProfileCleanupRefusal('Conflicting retirement receipt'); } }
            if (count($jobs) > 1) { throw new PhpbbProfileCleanupRefusal('Conflicting creation receipts'); }
            foreach ($jobs as $job)
            {
                if ((string)$job['field_id'] !== (string)$receipt['field_id'] || !in_array($job['job_state'], $state === 'purged' ? array('purged') : array('published'), true)
                    || $column !== 'cpf_' . substr($this->token($job['operation_key']), 0, 32)) { throw new PhpbbProfileCleanupRefusal('Conflicting creation receipt'); }
            }
            $identity = array($kind,$operation,$column,$receipt['field_id'],$receipt['definition_revision'],$receipt['column_signature'],$receipt['created_at']);
        }
        else
        {
            if ($receipt['field_id'] !== null || $column !== 'cpf_' . substr($operation, 0, 32) || count($jobs) !== 1 || $actions)
            { throw new PhpbbProfileCleanupRefusal('Staged column was published or reassigned'); }
            $this->token($receipt['payload_hash']);
            $identity = array($kind,$operation,$column,$receipt['payload_hash'],$receipt['created_at']);
        }
        $physical = $this->rows("SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape(USERS_TABLE) . "' AND COLUMN_NAME='$column'");
        if (count($physical) > 1 || ($state === 'purged' && $physical)) { throw new PhpbbProfileCleanupRefusal('Never delete a column recreated after completed cleanup'); }
        if (!$physical && $kind === 'retired' && $state === 'retired') { throw new PhpbbProfileCleanupRefusal('Missing column without a prior cleanup intent'); }
        if ($physical)
        {
            $meta = $physical[0];
            if (!in_array($meta['DATA_TYPE'], array('char','varchar','tinytext','text','mediumtext','longtext'), true)
                || !in_array($meta['EXTRA'], array('','DEFAULT_GENERATED'), true) || $meta['CHARACTER_SET_NAME'] !== 'utf8mb4' || $meta['COLLATION_NAME'] !== 'utf8mb4_unicode_ci')
            { throw new PhpbbProfileCleanupRefusal('Unsupported physical profile column'); }
            $name = $this->sql_escape(USERS_TABLE);
            if ($this->rows("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND COLUMN_NAME='$column'")
                || $this->rows("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND COLUMN_NAME='$column'")
                || $this->rows("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='$name'"))
            { throw new PhpbbProfileCleanupRefusal('An index, constraint or trigger prevents automatic cleanup'); }
            if ($kind === 'retired')
            {
                if (!hash_equals($receipt['column_signature'], phpbb_profile_definition_column_signature($meta))) { throw new PhpbbProfileCleanupRefusal('Retired physical storage changed'); }
            }
            else
            {
                $defaults = $this->rows('SHOW COLUMNS FROM `' . USERS_TABLE . "` WHERE Field='$column'");
                if ($meta['DATA_TYPE'] !== 'mediumtext' || $meta['IS_NULLABLE'] !== 'YES' || $meta['EXTRA'] !== ''
                    || $meta['COLUMN_COMMENT'] !== 'phpbb-profile-job:' . $operation || count($defaults) !== 1 || $defaults[0]['Default'] !== null
                    || $this->rows('SELECT user_id FROM `' . USERS_TABLE . '` WHERE `' . $column . '` IS NOT NULL LIMIT 1' . $suffix))
                { throw new PhpbbProfileCleanupRefusal('Staged column has changed or contains independent data'); }
            }
        }
        return array('kind'=>$kind,'operation'=>$operation,'column'=>$column,'state'=>$state,'present'=>(bool)$physical,
            'confirmation'=>hash('sha256', json_encode(array_merge(array($this->target, $this->lock_name, USERS_TABLE), array_map('strval', $identity)))));
    }
    function plan($kind, $operation) { $this->storage(); return $this->inspect($kind, $operation); }
    private function begin()
    {
        $this->held(); $this->sql_query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->sql_query('START TRANSACTION'); $this->in_transaction = true;
    }
    private function commit()
    {
        $this->sql_query('COMMIT'); $this->in_transaction = false;
    }
    private function confirmed($plan, $confirmation)
    {
        if (!hash_equals($plan['confirmation'], $this->token($confirmation))) { throw new PhpbbProfileCleanupRefusal('Preview confirmation no longer matches'); }
    }
    function apply($kind, $operation, $confirmation, $backup_confirmed, $maintenance_confirmed, $erase_confirmed)
    {
        try
        {
            if ($backup_confirmed !== true || $maintenance_confirmed !== true || $erase_confirmed !== true)
            { throw new PhpbbProfileCleanupRefusal('Confirm backup, stopped/drained ALL web/cron requests and permanent erasure'); }
            $plan = $this->plan($kind, $operation); $this->confirmed($plan, $confirmation);
            if ($plan['state'] === 'purged') { return $plan; }
            $table = $kind === 'retired' ? PROFILE_FIELD_ACTIONS_TABLE : PROFILE_FIELD_JOBS_TABLE;
            $key = $kind === 'retired' ? 'action_state' : 'job_state';
            $this->begin(); $plan = $this->inspect($kind, $operation, true); $this->confirmed($plan, $confirmation);
            $this->sql_query("UPDATE $table SET $key='purging',updated_at=" . time() . " WHERE operation_key='$operation'");
            $this->commit(); // Durable intent before any DDL; never guess a lost reply.
            $plan = $this->plan($kind, $operation); $this->confirmed($plan, $confirmation);
            if ($plan['state'] !== 'purging') { throw new PhpbbProfileCleanupRefusal('Cleanup intent changed'); }
            if ($plan['present'])
            {
                $this->held(); $this->sql_query('SET SESSION lock_wait_timeout=10');
                $this->sql_query('ALTER TABLE `' . USERS_TABLE . '` DROP COLUMN `' . $plan['column'] . '`');
                if ($this->rows('SHOW WARNINGS')) { throw new PhpbbProfileCleanupRefusal('Review cleanup DDL warnings before retry'); }
            }
            $this->begin(); $plan = $this->inspect($kind, $operation, true); $this->confirmed($plan, $confirmation);
            if ($plan['present'] || $plan['state'] !== 'purging') { throw new PhpbbProfileCleanupRefusal('Physical cleanup not confirmed'); }
            if ($kind === 'retired')
            {
                // Erase saved definition defaults too, but retain content-free
                // receipt identities to reject stale restoration/creation forms.
                $this->sql_query('UPDATE ' . PROFILE_FIELD_ACTIONS_TABLE . " SET definition_snapshot='' WHERE field_column='" . $plan['column'] . "' AND action_state='restored'");
                $this->sql_query('UPDATE ' . PROFILE_FIELD_JOBS_TABLE . " SET job_state='purged',updated_at=" . time() . " WHERE field_column='" . $plan['column'] . "' AND job_state='published'");
            }
            $this->sql_query("UPDATE $table SET $key='purged',updated_at=" . time() . ($kind === 'retired' ? ",definition_snapshot=''" : '') . " WHERE operation_key='$operation'");
            $this->commit(); return $this->plan($kind, $operation);
        }
        finally { $this->release(); }
    }
}
