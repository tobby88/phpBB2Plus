<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_profile_fields.php';

// Used only when recreating the missing shared anonymous account. Callers own
// the mutation lock and retain their own current-authority checks. No existing
// user's values are read/copied and member defaults are deliberately ignored.
function phpbb_profile_guest_rows($db, $sql)
{
    $result = $db->sql_query($sql);
    if (!$result) { throw new RuntimeException('Unable to inspect guest profile storage'); }
    try { $rows = $db->sql_fetchrowset($result); }
    finally { $db->sql_freeresult($result); }
    if (!is_array($rows)) { throw new RuntimeException('Invalid guest profile storage'); }
    return $rows;
}

function phpbb_profile_guest_columns($fields, $actions, $physical)
{
    $columns = array(); $active = array(); $core = phpbb_profile_definition_core_columns();
    foreach ($fields as $field)
    {
        $column = phpbb_profile_field_column($field);
        if ($column === '' || in_array($column, $core, true) || isset($active[$column]))
        { throw new UnexpectedValueException('Invalid active guest profile mapping'); }
        $columns[$column] = true; $active[$column] = true;
    }
    foreach ($actions as $action)
    {
        $column = isset($action['field_column']) ? $action['field_column'] : null;
        $state = isset($action['action_state']) ? $action['action_state'] : null;
        if (!is_string($column) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $column)
            || in_array($column, $core, true) || !in_array($state, array('retired','restored','purging','purged'), true))
        { throw new UnexpectedValueException('Invalid archived guest profile mapping'); }
        // Old restored receipts are tombstones, not claims on current storage.
        if ($state === 'restored') { continue; }
        if (isset($active[$column])) { throw new UnexpectedValueException('Conflicting guest profile mapping'); }
        $columns[$column] = !empty($columns[$column]) || $state !== 'purged';
    }
    $storage = array();
    foreach ($physical as $row) { $storage[$row['COLUMN_NAME']] = $row; }
    $result = array();
    foreach ($columns as $column => $required)
    {
        if (!isset($storage[$column]))
        {
            if (!$required) { continue; } // Permanently cleaned-up receipt.
            throw new UnexpectedValueException('Missing guest profile column');
        }
        $row = $storage[$column];
        if (!in_array(strtolower($row['DATA_TYPE']), array('char','varchar','tinytext','text','mediumtext','longtext'), true)
            || !in_array($row['EXTRA'], array('', 'DEFAULT_GENERATED'), true))
        { throw new UnexpectedValueException('Unsupported guest profile column'); }
        $result[] = $column;
    }
    return $result;
}

function phpbb_profile_guest_insert_parts($db)
{
    $fields = phpbb_profile_guest_rows($db, 'SELECT field_name,field_column FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id');
    $actions = phpbb_profile_guest_rows($db, 'SELECT field_column,action_state FROM ' . PROFILE_FIELD_ACTIONS_TABLE . ' ORDER BY operation_key');
    $physical = phpbb_profile_guest_rows($db, "SELECT COLUMN_NAME,DATA_TYPE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->sql_escape(USERS_TABLE) . "'");
    $columns = $values = '';
    foreach (phpbb_profile_guest_columns($fields, $actions, $physical) as $column)
    { $columns .= ', `' . $column . '`'; $values .= ", ''"; }
    return array($columns, $values);
}
