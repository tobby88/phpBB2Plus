<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_profile_definition_storage.php';

// Removing a definition and remembering how to recover it is one transaction.
// Physical cleanup is a separate, explicitly confirmed operation; retirement
// and restoration never DROP/MODIFY columns or touch stored user values.
class PhpbbProfileDefinitionRetirement extends PhpbbProfileDefinitionWriter
{
    function __construct($database, $request)
    {
        parent::__construct($database, 'edit', $request);
    }
    private function operation($operation)
    {
        if (!is_string($operation) || !preg_match('/^[a-f0-9]{64}$/D', $operation)) { phpbb_acl_error('Profile_definition_invalid'); }
        return $operation;
    }
    private function column_signature($column)
    {
        if (!is_string($column) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $column)
            || in_array($column, phpbb_profile_definition_core_columns(), true)) { phpbb_acl_error('Profile_definition_invalid'); }
        $capacity = $this->column_capacity($column);
        if ($capacity['COLUMN_KEY'] !== '') { phpbb_acl_error('Profile_definition_capacity'); }
        $rows = phpbb_acl_rows($this, "SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape(USERS_TABLE) . "' AND COLUMN_NAME='" . $column . "'");
        if (count($rows) !== 1 || $rows[0]['CHARACTER_SET_NAME'] !== 'utf8mb4' || $rows[0]['COLLATION_NAME'] !== 'utf8mb4_unicode_ci') { phpbb_acl_error('Profile_definition_changed'); }
        $snapshot = array();
        foreach (array('COLUMN_TYPE','IS_NULLABLE','COLUMN_DEFAULT','CHARACTER_SET_NAME','COLLATION_NAME','COLUMN_COMMENT','EXTRA') as $key) { $snapshot[$key] = $rows[0][$key]; }
        $json = json_encode($snapshot);
        if ($json === false) { phpbb_acl_error('Profile_definition_changed'); }
        return hash('sha256', $json);
    }
    private function action($operation)
    {
        $rows = phpbb_acl_rows($this, 'SELECT * FROM ' . PROFILE_FIELD_ACTIONS_TABLE . " WHERE operation_key='" . $this->operation($operation) . "' FOR UPDATE");
        if (!$rows) { return null; }
        if (count($rows) !== 1) { phpbb_acl_error('Profile_definition_changed'); }
        return $rows[0];
    }
    private function definitions()
    {
        $r = $this->sql_query('SELECT field_column FROM ' . PROFILE_FIELDS_TABLE . ' LIMIT 0'); $this->sql_freeresult($r);
        return phpbb_acl_rows($this, 'SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id FOR UPDATE');
    }
    private function snapshot($action)
    {
        $row = json_decode($action['definition_snapshot'], true);
        $keys = array_merge(array('field_id','field_column'), phpbb_profile_definition_fields());
        if (!is_array($row) || array_keys($row) !== $keys || (string)$row['field_id'] !== (string)$action['field_id']
            || !hash_equals(phpbb_profile_definition_revision($row), $action['definition_revision'])
            || phpbb_profile_field_column($row) !== $action['field_column']) { phpbb_acl_error('Profile_definition_changed'); }
        return $row;
    }
    function retire($id, $revision, $operation)
    {
        global $userdata;
        try
        {
            $id = phpbb_acl_id($id); $operation = $this->operation($operation);
            if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { phpbb_acl_error('Profile_definition_changed'); }
            $this->begin(array(PROFILE_FIELDS_TABLE, PROFILE_FIELD_ACTIONS_TABLE, BANLIST_TABLE), true); $actor = $this->pin_actor();
            $action = $this->action($operation);
            if ($action)
            {
                if ((int)$action['field_id'] !== $id || !hash_equals($action['definition_revision'], $revision)
                    || (int)$action['actor_id'] !== (int)$actor['user_id'] || !hash_equals($action['session_hash'], hash('sha256', $userdata['session_id']))
                    || !in_array($action['action_state'], array('retired','purging','purged'), true)) { phpbb_acl_error('Profile_definition_changed'); }
                foreach ($this->definitions() as $row)
                { if ((int)$row['field_id'] === $id || phpbb_profile_field_column($row) === $action['field_column']) { phpbb_acl_error('Profile_definition_changed'); } }
                $this->sql_query('ROLLBACK'); $this->confirmed = true; return $operation;
            }
            $current = $this->current_definition($id, $revision); $column = phpbb_profile_field_column($current);
            $signature = $this->column_signature($column);
            // Lock this column's action range, including the empty range. Old
            // restored receipts remain as tombstones against stale replays.
            $actions = phpbb_acl_rows($this, 'SELECT action_state FROM ' . PROFILE_FIELD_ACTIONS_TABLE . " WHERE field_column='" . $column . "' FOR UPDATE");
            foreach ($actions as $other) { if ($other['action_state'] !== 'restored') { phpbb_acl_error('Profile_definition_changed'); } }
            $snapshot = array();
            foreach (array_merge(array('field_id','field_column'), phpbb_profile_definition_fields()) as $key)
            { $snapshot[$key] = isset($current[$key]) ? (string)$current[$key] : null; }
            $json = json_encode($snapshot); if ($json === false) { phpbb_acl_error('Profile_definition_changed'); }
            $now = time();
            $this->sql_query('INSERT INTO ' . PROFILE_FIELD_ACTIONS_TABLE . " (operation_key,field_id,field_column,definition_revision,definition_snapshot,column_signature,actor_id,session_hash,action_state,created_at,updated_at) VALUES ('" . $operation . "'," . $id . ",'" . $column . "','" . $revision . "','" . $this->sql_escape($json) . "','" . $signature . "'," . (int)$actor['user_id'] . ",'" . hash('sha256', $userdata['session_id']) . "','retired'," . $now . ',' . $now . ')');
            $this->sql_query('DELETE FROM ' . PROFILE_FIELDS_TABLE . ' WHERE field_id=' . $id);
            $this->pin_actor(); $this->commit_attempted = true; $this->commit(); $this->confirmed = true;
            return $operation;
        }
        finally { $this->release(); }
    }
    function restore($operation)
    {
        try
        {
            $operation = $this->operation($operation);
            $this->begin(array(PROFILE_FIELDS_TABLE, PROFILE_FIELD_ACTIONS_TABLE, BANLIST_TABLE), true); $this->pin_actor();
            $action = $this->action($operation);
            if (!$action || !in_array($action['action_state'], array('retired','restored'), true)) { phpbb_acl_error('Profile_definition_changed'); }
            $snapshot = $this->snapshot($action); $id = phpbb_acl_id($action['field_id']); $column = $action['field_column'];
            $definitions = $this->definitions();
            if ($action['action_state'] === 'restored')
            {
                $found = false;
                foreach ($definitions as $row) { if ((int)$row['field_id'] === $id && phpbb_profile_field_column($row) === $column) { $found = true; } }
                if (!$found) { phpbb_acl_error('Profile_definition_changed'); }
                $this->sql_query('ROLLBACK'); $this->confirmed = true; return $id;
            }
            foreach ($definitions as $row)
            { if ((int)$row['field_id'] === $id || phpbb_profile_field_column($row) === $column) { phpbb_acl_error('Profile_definition_changed'); } }
            if (!hash_equals($action['column_signature'], $this->column_signature($column))) { phpbb_acl_error('Profile_definition_changed'); }
            if (phpbb_acl_rows($this, 'SELECT field_id FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_name='" . $this->sql_escape($snapshot['field_name']) . "' FOR UPDATE")) { phpbb_acl_error('Profile_definition_exists'); }
            // Restoration pins even a legacy mapping. A label is not a storage
            // identifier and must not be used to rename/recreate the column.
            $snapshot['field_column'] = $column; $encoded = array();
            foreach ($snapshot as $value) { $encoded[] = $value === null ? 'NULL' : "'" . $this->sql_escape($value) . "'"; }
            $this->sql_query('INSERT INTO ' . PROFILE_FIELDS_TABLE . ' (' . implode(',', array_keys($snapshot)) . ') VALUES (' . implode(',', $encoded) . ')');
            $this->sql_query('UPDATE ' . PROFILE_FIELD_ACTIONS_TABLE . " SET action_state='restored',updated_at=" . time() . " WHERE operation_key='" . $operation . "'");
            $this->pin_actor(); $this->commit_attempted = true; $this->commit(); $this->confirmed = true;
            return $id;
        }
        finally { $this->release(); }
    }
}
