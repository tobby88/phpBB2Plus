<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbUserIdException extends RuntimeException {}

// Share the same writer connection with account cleanup and group maintenance.
// Reserving an ID is a separate durable operation; acquire this scope AFTER
// allocation and release it before notifications or other lock-taking helpers.
function phpbb_user_write_begin(&$database)
{
	$original = $database;
	$lock = attach_require_mutation_lock($database);
	$database = $lock->connection;
	return array($original, $lock);
}
function phpbb_user_write_end(&$database, $scope)
{
	$database = $scope[0];
	$scope[1]->release();
}

function phpbb_user_id_error($key = 'User_id_allocation_failed')
{
	global $lang;
	throw new PhpbbUserIdException(isset($lang[$key]) ? $lang[$key] : $key);
}
function phpbb_user_id_references()
{
	// Explicit numeric user references in the preserved core and bundled MODs.
	// Never infer identities from names, IP addresses, arbitrary *_id fields or
	// user-controlled table names. Optional historical modules may be absent.
	return array(
		'user_group' => array('user_id'),
		'user_removals' => array('user_id','created_by'),
		'user_removal_items' => array('related_id'),
		'groups' => array('group_moderator'),
		'banlist' => array('ban_userid'),
		'posts' => array('poster_id'),
		'privmsgs' => array('privmsgs_from_userid','privmsgs_to_userid'),
		'sessions' => array('session_user_id'),
		'sessions_keys' => array('user_id'),
		'topics' => array('topic_poster'),
		'topics_watch' => array('user_id'),
		'users' => array('user_id'),
		'logs' => array('user_id'),
		'vote_voters' => array('vote_user_id'),
		'attachments' => array('user_id_1','user_id_2'),
		'attach_quota' => array('user_id'),
		'jr_admin_users' => array('user_id'),
		'pa_comments' => array('poster_id'),
		'pa_download_info' => array('user_id'),
		'pa_files' => array('user_id'),
		'pa_votes' => array('user_id'),
		'album' => array('pic_user_id'),
		'album_rate' => array('rate_user_id'),
		'album_comment' => array('comment_user_id','comment_edit_user_id'),
		'album_cat' => array('cat_user_id'),
		'bookmarks' => array('user_id'),
		'shout' => array('shout_user_id'),
		'topic_view' => array('user_id'),
		'links' => array('user_id'),
		'kb_articles' => array('article_author_id'),
		'kb_votes' => array('votes_userid'),
		'ctracker_loginhistory' => array('ct_user_id'),
		'ina_at_scores' => array('player_id'),
		'ina_banned' => array('user_id'),
		'ina_cat' => array('mod_id','last_player'),
		'ina_comment' => array('comment_user_id','comment_edit_user_id'),
		'ina_fav' => array('user_id'),
		'ina_highscore' => array('highscore_user_id'),
		'ina_log' => array('user_id'),
		'ina_rate' => array('rate_user_id'),
		'ina_scores' => array('player_id'),
		'ina_sessions' => array('user_id'),
		'ina_tour' => array('start_id','champion'),
		'ina_tour_data' => array('top_player'),
		'ina_tour_invite' => array('user_id'),
		'ina_tour_play' => array('user_id'),
		'ina_user_data' => array('user_id'),
	);
}
function phpbb_user_id_prefix($prefix)
{
	if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) { phpbb_user_id_error(); }
	return $prefix;
}
function phpbb_user_id_rows($database, $sql)
{
	$result = $database->sql_query($sql);
	if (!$result) { phpbb_user_id_error(); }
	$rows = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
	return $rows;
}
function phpbb_user_id_number($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { phpbb_user_id_error(); }
	$digits = ltrim((string) $value, '0');
	// users.user_id is signed MEDIUMINT; retain that existing ID range.
	if (strlen($digits) > 7 || (int) $digits > 8388607) { phpbb_user_id_error('User_id_capacity_exhausted'); }
	return (int) $digits;
}
function phpbb_user_id_reference_floor($database, $prefix)
{
	$prefix = phpbb_user_id_prefix($prefix); $registry = phpbb_user_id_references(); $tables = array(); $expected = array();
	foreach ($registry as $suffix => $fields)
	{
		$tables[] = "'" . $prefix . $suffix . "'"; $expected[$prefix . $suffix] = $fields;
	}
	$rows = phpbb_user_id_rows($database, 'SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name,DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', $tables) . ')');
	$selects = array(); $seen = array(); $inventory = array();
	foreach ($rows as $row) { $inventory[$row['table_name']][$row['column_name']] = strtolower($row['data_type']); }
	foreach ($rows as $row)
	{
		$table = $row['table_name']; $column = $row['column_name'];
		if (!isset($expected[$table]) || !in_array($column, $expected[$table], true)) { continue; }
		if (!in_array(strtolower($row['data_type']), array('tinyint','smallint','mediumint','int','bigint'), true)) { phpbb_user_id_error(); }
		$seen[$table . '.' . $column] = true;
		$where = '';
		if ($table === $prefix . 'user_removal_items')
		{
			// Only the PM item's related ID is a user. Other journal item IDs
			// describe groups, messages or attachment records, not identities.
			if (!isset($inventory[$table]['item_type']) || !in_array($inventory[$table]['item_type'], array('char','varchar'), true)) { phpbb_user_id_error(); }
			$where = " WHERE item_type = 'pm'";
		}
		$selects[] = 'SELECT COALESCE(MAX(`' . $column . '`),0) AS value FROM `' . $table . '`' . $where;
	}
	if (!isset($seen[$prefix . 'users.user_id'])) { phpbb_user_id_error(); }
	$rows = phpbb_user_id_rows($database, 'SELECT MAX(value) AS maximum FROM (' . implode(' UNION ALL ', $selects) . ') user_id_reference_floor');
	if (count($rows) !== 1 || !isset($rows[0]['maximum'])) { phpbb_user_id_error(); }
	// Negative guest/sentinel IDs never reserve a positive user identity.
	if (is_numeric($rows[0]['maximum']) && $rows[0]['maximum'] < 0) { return 0; }
	return phpbb_user_id_number($rows[0]['maximum']);
}
function phpbb_allocate_user_id($database, $prefix)
{
	$prefix = phpbb_user_id_prefix($prefix);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_user_id_error('Attachment_storage_busy'); }
	try
	{
		$db = $lock->connection; $table = '`' . $prefix . 'user_id_sequence`';
		// Dedicated connection only: never commit the caller's transaction.
		// A reserved ID must survive a later failed/rolled-back registration,
		// with the canonical InnoDB sequence as well as legacy MyISAM tables.
		if (!$db->sql_query('SET autocommit = 1')) { phpbb_user_id_error(); }
		$rows = phpbb_user_id_rows($db, 'SELECT last_id FROM ' . $table . ' WHERE singleton = 1');
		if (count($rows) !== 1) { phpbb_user_id_error(); }
		$previous = phpbb_user_id_number($rows[0]['last_id']);
		$floor = max($previous, phpbb_user_id_reference_floor($db, $prefix));
		if ($floor >= 8388607) { phpbb_user_id_error('User_id_capacity_exhausted'); }
		$next = $floor + 1;
		if (!$db->sql_query('UPDATE ' . $table . ' SET last_id = ' . $next . ' WHERE singleton = 1 AND last_id = ' . $previous) || (int) $db->sql_affectedrows() !== 1) { phpbb_user_id_error(); }
		$rows = phpbb_user_id_rows($db, 'SELECT last_id FROM ' . $table . ' WHERE singleton = 1');
		if (count($rows) !== 1 || phpbb_user_id_number($rows[0]['last_id']) !== $next) { phpbb_user_id_error(); }
		return $next;
	}
	catch (PhpbbUserIdException $error) { throw $error; }
	catch (Exception $error) { phpbb_user_id_error(); }
	catch (Error $error) { phpbb_user_id_error(); }
	finally { $lock->release(); }
}
