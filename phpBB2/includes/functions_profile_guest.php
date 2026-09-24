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
    return phpbb_profile_owned_columns($fields, $actions, $physical);
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
