<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// A username is an identity, not a byte-sized display excerpt. The schema
// counts Unicode codepoints; never shorten input to another member's prefix.
function phpbb_username_input($value)
{
    if (!is_string($value) || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)
        || preg_match('//u', $value) !== 1) { return null; }
    $value = trim($value);
    if (preg_match_all('/./us', $value, $characters) === false || count($characters[0]) > 25) { return null; }
    return $value === '' ? null : $value;
}

function phpbb_username_key($value, $flags = ENT_QUOTES)
{
    $value = phpbb_username_input($value);
    if ($value === null || !in_array($flags, array(ENT_QUOTES, ENT_COMPAT), true)) { return ''; }
    $key = htmlspecialchars($value, $flags, 'UTF-8');
    if (preg_match_all('/./us', $key, $characters) === false || count($characters[0]) > 25) { return ''; }
    return $key;
}

// Historical PHP default quote flags differed. Both actual HTML identity
// representations can be looked up, but a collision must never pick a row.
// Do not invent raw-HTML, SQL-slash, truncated or double-backslash aliases.
function phpbb_username_keys($value)
{
    $keys = array();
    foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags) {
        $key = phpbb_username_key($value, $flags);
        if ($key !== '' && !in_array($key, $keys, true)) { $keys[] = $key; }
    }
    return $keys;
}

function phpbb_username_form($value, $existing = null)
{
    $value = phpbb_username_input($value);
    if ($value === null) { return ''; }
    // Preserve an unchanged trusted database identity on unrelated profile
    // saves instead of silently renaming old ENT_COMPAT accounts.
    if (is_string($existing) && $existing !== '' && preg_match('//u', $existing) === 1
        && html_entity_decode($existing, ENT_QUOTES, 'UTF-8') === $value) { return $existing; }
    return phpbb_username_key($value);
}

// A prepared guest-name value may be empty, but must never be silently cast,
// clipped or de-slashed again by a content writer.
function phpbb_username_stored($value)
{
    if (!is_string($value) || strlen($value) > 100) { return null; }
    if ($value === '') { return ''; }
    if (preg_match('//u', $value) !== 1 || preg_match_all('/./us', $value, $characters) === false
        || count($characters[0]) > 25) { return null; }
    return in_array($value, phpbb_username_keys(html_entity_decode($value, ENT_QUOTES, 'UTF-8')), true) ? $value : null;
}

function phpbb_username_rows($database, $value, $fields = null, $lock = '', $exclude = 0)
{
    $keys = phpbb_username_keys($value);
    if (!$keys) { return array(); }
    if (!in_array($lock, array('', 'FOR UPDATE', 'LOCK IN SHARE MODE'), true)
        || !is_int($exclude) || $exclude < 0 || $exclude > 8388607) { throw new InvalidArgumentException('Invalid identity reader scope'); }
    $select = '*';
    if ($fields !== null) {
        if (!is_array($fields) || !$fields || count($fields) > 120) { throw new InvalidArgumentException('Invalid identity fields'); }
        foreach ($fields as $field) { if (!is_string($field) || preg_match('/^[a-z_][a-z0-9_]*$/D', $field) !== 1) { throw new InvalidArgumentException('Invalid identity field'); } }
        $select = implode(',', array_unique(array_merge(array('user_id','username'), $fields)));
    }
    $literals = array(); foreach ($keys as $key) { $literals[] = "'" . $database->sql_escape($key) . "'"; }
    $sql = 'SELECT ' . $select . ' FROM ' . USERS_TABLE . ' WHERE username IN (' . implode(',', $literals) . ') AND user_id>0'
        . ($exclude ? ' AND user_id<>' . $exclude : '') . ($lock !== '' ? ' ' . $lock : '');
    $result = $database->sql_query($sql);
    if (!$result) { return false; }
    try { return $database->sql_fetchrowset($result); }
    finally { $database->sql_freeresult($result); }
}

// null = missing/invalid/ambiguous identity; false = database read failure.
function phpbb_username_lookup($database, $value, $fields = null, $lock = '')
{
    $rows = phpbb_username_rows($database, $value, $fields, $lock);
    if ($rows === false) { return false; }
    return count($rows) === 1 ? $rows[0] : null;
}

// Search forms document only '*' as a wildcard. SQL '%'/'_' and the explicit
// escape character are literal name characters, regardless of SQL mode.
// Search both historical HTML representations without decoding user input.
function phpbb_username_search_sql($database, $column, $value, $prefix = false, $wildcards = true)
{
    if (!is_string($column) || preg_match('/^(?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*$/D', $column) !== 1) {
        throw new InvalidArgumentException('Invalid username search column');
    }
    $value = phpbb_username_input($value);
    if ($value === null) { return '(1=0)'; }
    $patterns = array();
    foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags) {
        $pattern = htmlspecialchars($value, $flags, 'UTF-8');
        $pattern = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $pattern);
        if ($wildcards) { $pattern = str_replace('*', '%', $pattern); }
        if ($prefix && (!$wildcards || substr($value, -1) !== '*')) { $pattern .= '%'; }
        $patterns[$pattern] = $column . " LIKE '" . $database->sql_escape($pattern) . "' ESCAPE '!'";
    }
    return '(' . implode(' OR ', array_values($patterns)) . ')';
}

function phpbb_username_search_allowed($value, $minimum)
{
    $value = phpbb_username_input($value);
    if ($value === null) { return false; }
    if (strpos($value, '*') === false) { return true; }
    preg_match_all('/./us', str_replace('*', '', $value), $characters);
    return count($characters[0]) >= max(0, (int) $minimum);
}
