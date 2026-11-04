<?php
require_once dirname(__FILE__) . '/functions_profile_columns.php';
// Profile values have one HTML-encoding pass in storage. Limits describe the
// original Unicode text, while physical columns must also hold that encoding.
function phpbb_profile_encoded_value_valid($value, $maximum)
{
    if (!is_string($value) || strpos($value, "\0") !== false || strlen($value) > 360000 || preg_match('//u', $value) !== 1) { return false; }
    $maximum = max(0, min(60000, (int)$maximum));
    $raw = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $characters = preg_split('//u', $raw, $maximum + 2, PREG_SPLIT_NO_EMPTY);
    return $characters !== false && count($characters) <= $maximum;
}

function phpbb_profile_field_value_limit($field)
{
    if (!is_array($field) || !isset($field['field_type']) || !is_scalar($field['field_type'])) { return false; }
    $type = (string)$field['field_type'];
    if (!in_array($type, array('0','1','2','3'), true)) { return false; }
    if ($type === '2') { return 255; }
    if ($type === '3') { return 60000; }
    $key = $type === '0' ? 'text_field_maxlen' : 'text_area_maxlen';
    $ceiling = $type === '0' ? 255 : 60000;
    if (!isset($field[$key])) { return $ceiling; }
    if (!is_scalar($field[$key]) || !preg_match('/^[0-9]{1,5}$/D', (string)$field[$key])) { return false; }
    return min($ceiling, (int)$field[$key]);
}

function phpbb_profile_text_metadata_columns()
{
    return array('text_field_default','text_area_default','radio_button_default','radio_button_values','checkbox_default','checkbox_values');
}


function phpbb_profile_text_widen_sql($table, $column, $create, $capacity, $maximum, $literal_override = null, &$has_literal_default = false)
{
    if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)
        || !is_string($column) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $column)) { throw new UnexpectedValueException('Profile_definition_capacity'); }
    if (!is_int($maximum) || $maximum < 1 || $maximum > 360000 || in_array($column, phpbb_profile_definition_core_columns(), true)
        || !is_array($capacity) || !isset($capacity['DATA_TYPE'], $capacity['COLUMN_KEY'], $capacity['EXTRA'])
        || $capacity['COLUMN_KEY'] !== '' || !in_array($capacity['EXTRA'], array('', 'DEFAULT_GENERATED'), true) || !is_string($create)) { throw new UnexpectedValueException('Profile_definition_capacity'); }
    if (!preg_match('/^\s*`' . preg_quote($column, '/') . '` (varchar\([0-9]+\)|tinytext|text|mediumtext|longtext)([^\r\n]*)$/mi', $create, $match))
    { throw new UnexpectedValueException('Profile_definition_capacity'); }
    $old_type = strtolower($match[1]); $tail = preg_replace('/,$/', '', $match[2]);
    $type = preg_replace('/\([0-9]+\)$/', '', $old_type);
    if ($type !== $capacity['DATA_TYPE']) { throw new UnexpectedValueException('Profile_definition_capacity'); }
    $literal = <<<'SQL_LITERAL'
'(?:[^'\\]|\\[\s\S]|'')*'
SQL_LITERAL;
    $pattern = '/^(?: CHARACTER SET utf8mb4)?(?: COLLATE utf8mb4_unicode_ci)?(?: NOT NULL| NULL)?(?: DEFAULT (NULL|' . $literal . '|\(' . $literal . '\)))?(?: COMMENT ' . $literal . ')?$/D';
    if (!preg_match($pattern, $tail, $attributes)) { throw new UnexpectedValueException('Profile_definition_capacity'); }
    $has_literal_default = isset($attributes[1]) && $attributes[1] !== '' && $attributes[1] !== 'NULL';
    if ($literal_override !== null)
    {
        if (!$has_literal_default || !is_string($literal_override) || !preg_match('/^' . $literal . '$/D', $literal_override)) { throw new UnexpectedValueException('Profile_definition_capacity'); }
        $needle = ' DEFAULT ' . $attributes[1]; $position = strpos($tail, $needle);
        $tail = substr_replace($tail, ' DEFAULT ' . $literal_override, $position, strlen($needle));
        $attributes[1] = $literal_override;
    }
    if ($type === 'varchar' && $maximum <= 1024)
    {
        if (!preg_match('/^varchar\(([0-9]+)\)$/D', $old_type, $length) || (int)$length[1] >= $maximum) { throw new UnexpectedValueException('Profile_definition_capacity'); }
        $new_type = 'varchar(' . $maximum . ')';
    }
    else
    {
        if (!in_array($type, array('varchar','tinytext','text'), true)) { throw new UnexpectedValueException('Profile_definition_capacity'); }
        $new_type = $type === 'tinytext' && $maximum <= 1024 ? 'text' : 'mediumtext';
        // MySQL requires a parenthesized expression for a non-NULL TEXT default;
        // MariaDB accepts the same literal expression. Never evaluate expressions.
        if (isset($attributes[1]) && isset($attributes[1][0]) && $attributes[1][0] === "'")
        {
            $needle = ' DEFAULT ' . $attributes[1]; $position = strpos($tail, $needle);
            $tail = substr_replace($tail, ' DEFAULT (' . $attributes[1] . ')', $position, strlen($needle));
        }
    }
    return 'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` ' . $new_type . $tail;
}
