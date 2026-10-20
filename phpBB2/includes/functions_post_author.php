<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// A guest byline is not an account identity. Keep the account helpers at 25;
// guest text supports 255 raw UTF-8 characters, up to 1530 escaped characters.
function phpbb_post_author_input($value)
{
	if (!is_string($value) || strlen($value) > 1020 || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1) { return null; }
	$value = trim($value);
	if (preg_match_all('/./us', $value, $characters) === false || count($characters[0]) > 255) { return null; }
	return $value;
}
function phpbb_post_author_key($value, $flags = ENT_QUOTES)
{
	$value = phpbb_post_author_input($value);
	return $value === null || !in_array($flags, array(ENT_QUOTES, ENT_COMPAT), true) ? null : htmlspecialchars($value, $flags, 'UTF-8');
}
function phpbb_post_author_keys($value)
{
	$keys = array();
	foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags) {
		$key = phpbb_post_author_key($value, $flags);
		if ($key !== null && $key !== '' && !in_array($key, $keys, true)) { $keys[] = $key; }
	}
	return $keys;
}
function phpbb_post_author_stored($value)
{
	if (!is_string($value) || strlen($value) > 1530 || preg_match('//u', $value) !== 1) { return null; }
	$raw = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
	return phpbb_post_author_key($raw) === $value ? $value : null;
}
function phpbb_post_author_storage_ready($database, $value)
{
	if (phpbb_post_author_stored($value) === null) { return false; }
	preg_match_all('/./us', $value, $characters); $required = count($characters[0]);
	if ($required <= 25) { return true; }
	$table = $database->sql_escape(POSTS_TABLE);
	$result = $database->sql_query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . $table . "') AND COLUMN_NAME='post_username' AND DATA_TYPE='varchar'");
	if (!$result) { return false; }
	try { $rows = $database->sql_fetchrowset($result); }
	finally { $database->sql_freeresult($result); }
	return count($rows) === 1 && (int)$rows[0]['CHARACTER_MAXIMUM_LENGTH'] >= $required;
}
function phpbb_post_author_search_sql($database, $column, $value, $prefix = false, $wildcards = true)
{
	if (!is_string($column) || preg_match('/^(?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*$/D', $column) !== 1) { throw new InvalidArgumentException('Invalid post author search column'); }
	$value = phpbb_post_author_input($value);
	if ($value === null || $value === '') { return '(1=0)'; }
	$patterns = array();
	foreach (phpbb_post_author_keys($value) as $pattern) {
		$pattern = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $pattern);
		if ($wildcards) { $pattern = str_replace('*', '%', $pattern); }
		if ($prefix && (!$wildcards || substr($value, -1) !== '*')) { $pattern .= '%'; }
		$patterns[$pattern] = $column . " LIKE '" . $database->sql_escape($pattern) . "' ESCAPE '!'";
	}
	return '(' . implode(' OR ', array_values($patterns)) . ')';
}
function phpbb_post_author_search_allowed($value, $minimum)
{
	$value = phpbb_post_author_input($value);
	if ($value === null || $value === '') { return false; }
	if (strpos($value, '*') === false) { return true; }
	preg_match_all('/./us', str_replace('*', '', $value), $characters);
	return count($characters[0]) >= max(0, (int)$minimum);
}
