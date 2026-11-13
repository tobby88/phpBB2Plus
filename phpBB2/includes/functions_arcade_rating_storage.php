<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';
require_once dirname(__FILE__) . '/functions_forum_read_snapshot.php';

class PhpbbArcadeRatingFailure extends RuntimeException {}
function phpbb_arcade_rating_fail($key) { throw new PhpbbArcadeRatingFailure($key); }
function phpbb_arcade_rating_id($value, $maximum = 8388607)
{
	return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)
		&& (float)$value <= $maximum;
}
function phpbb_arcade_rating_tables()
{
	return array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE, iNA, iNA_GAMES,
		iNA_CAT, iNA_GAMES_RATE, GROUPS_TABLE, USER_GROUP_TABLE);
}

// Read and write use the same current policy. A reader keeps one independent
// read-only snapshot; a writer locks the rows on its own mutation connection.
function phpbb_arcade_rating_context($store, $actor, $game_id, $locked)
{
	$share = $locked ? ' LOCK IN SHARE MODE' : '';
	$update = $locked ? ' FOR UPDATE' : '';
	$now = time(); $id = (int)$actor['user_id']; $sid = $store->sql_escape($actor['session_id']);
	$ttl = $store->rows("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length')" . $share);
	if (count($ttl) !== 1 || !phpbb_arcade_rating_id($ttl[0]['config_value'], 2147483647)) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	$session = $store->rows('SELECT session_time,session_ip FROM ' . SESSIONS_TABLE
		. " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1" . $update);
	if (count($session) !== 1 || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$session[0]['session_time'])
		|| (float)$session[0]['session_time'] > 2147483647 || (float)$session[0]['session_time'] > $now + 60
		|| (float)$session[0]['session_time'] < $now - (int)$ttl[0]['config_value']
		|| !preg_match('/^[a-fA-F0-9]{8}$/D', (string)$session[0]['session_ip'])) { phpbb_arcade_rating_fail('Session_invalid'); }
	$users = $store->rows('SELECT user_id,user_level,user_active,user_blocktime,user_rank,user_posts,arcade_banned FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . $update);
	if (count($users) !== 1 || (string)$users[0]['user_active'] !== '1'
		|| !in_array((string)$users[0]['user_level'], array((string)USER,(string)MOD,(string)ADMIN), true)
		|| !preg_match('/^-?[0-9]+$/D', (string)$users[0]['user_blocktime']) || (float)$users[0]['user_blocktime'] >= $now
		|| $store->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id . $share)) { phpbb_arcade_rating_fail('Not_Authorised'); }
	$user = $users[0]; $role = (int)$user['user_level']; $root = $role === ADMIN;
	$settings = array();
	foreach ($store->rows('SELECT config_name,config_value FROM ' . iNA . ' ORDER BY config_name' . $share) as $row) { $settings[$row['config_name']] = (string)$row['config_value']; }
	foreach (array('games_rate','games_offline','games_mod_ban_users') as $key)
	{
		if (!isset($settings[$key]) || !in_array((string)$settings[$key], array('0','1'), true)) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	}
	foreach (array('games_posts_required','games_rank_required','games_group_required') as $key)
	{
		if (!isset($settings[$key]) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$settings[$key])
			|| (float)$settings[$key] > 2147483647) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	}
	if (!isset($settings['games_default_rate']) || !phpbb_arcade_rating_id($settings['games_default_rate'], 255)) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	if ($settings['games_rate'] !== '1' || (!$root && $role !== MOD && $settings['games_offline'] === '1')
		|| (!$root && $settings['games_mod_ban_users'] === '1' && !empty($user['arcade_banned']))
		|| (int)$user['user_posts'] < (int)$settings['games_posts_required'] || (int)$user['user_rank'] < (int)$settings['games_rank_required']) { phpbb_arcade_rating_fail('Not_Authorised'); }
	$games = $store->rows('SELECT * FROM ' . iNA_GAMES . ' WHERE game_id=' . (int)$game_id . $update);
	if (count($games) !== 1) { phpbb_arcade_rating_fail('does_not_exist'); }
	$game = $games[0]; $name = $game['game_name'];
	if (!is_string($name) || $name === '' || strlen($name) > 200 || strpos($name, "\0") !== false || preg_match('//u', $name) !== 1) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	$escaped = $store->sql_escape($name);
	// Historical ratings use the name's collation. Preserve those rows, but
	// never mix ratings from two current games sharing that identity.
	$identity = $store->rows('SELECT game_id FROM ' . iNA_GAMES . " WHERE game_name='$escaped' ORDER BY game_id" . $update);
	if (count($identity) !== 1 || (int)$identity[0]['game_id'] !== (int)$game_id) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	$required = (int)$game['level_required'];
	if (!in_array($required, array(USER,MOD,ADMIN), true) || (!$root && (empty($game['game_avail'])
		|| (int)$user['user_rank'] < (int)$game['rank_required'] || ($required !== USER && !($role === MOD && $required === MOD))))) { phpbb_arcade_rating_fail('Not_Authorised'); }
	$groups = array(0=>true);
	foreach ($store->rows('SELECT ug.group_id FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE
		. ' g ON g.group_id=ug.group_id WHERE ug.user_id=' . $id . ' AND ug.user_pending=0 ORDER BY ug.group_id' . $share) as $row) { $groups[(int)$row['group_id']] = true; }
	if (!isset($groups[(int)$settings['games_group_required']]) || (!$root && !isset($groups[(int)$game['group_required']]))) { phpbb_arcade_rating_fail('Not_Authorised'); }
	$category = (int)$game['cat_id'] > 0 ? (int)$game['cat_id'] : -1; $visited = array(); $category_name = null;
	do
	{
		if (isset($visited[$category]) || count($visited) >= 32) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
		$visited[$category] = true;
		$categories = $store->rows('SELECT cat_id,cat_name,cat_type,cat_parent,group_required FROM ' . iNA_CAT . ' WHERE cat_id=' . $category . $update);
		if (count($categories) !== 1 || $categories[0]['cat_type'] === 'l') { phpbb_arcade_rating_fail('does_not_exist'); }
		$cat = $categories[0]; if ($category_name === null) { $category_name = $cat['cat_name']; }
		if (!$root && !isset($groups[(int)$cat['group_required']])) { phpbb_arcade_rating_fail('Not_Authorised'); }
		$category = (int)$cat['cat_parent'];
	} while ($category > 0);
	$game['cat_name'] = $category_name;
	return array('user'=>$user, 'game'=>$game, 'scale'=>(int)$settings['games_default_rate'], 'ip'=>$session[0]['session_ip']);
}

class PhpbbArcadeRatingReader extends PhpbbForumReadSnapshot
{
	function __construct($database) { parent::__construct($database, phpbb_arcade_rating_tables()); }
}
function phpbb_arcade_rating_view($database, $actor, $game_id)
{
	if (!PhpbbForumReadSnapshot::valid_actor($actor)) { phpbb_arcade_rating_fail('Session_invalid'); }
	if (!phpbb_arcade_rating_id($game_id)) { phpbb_arcade_rating_fail('no_activity'); }
	$reader = null;
	try
	{
		$reader = new PhpbbArcadeRatingReader($database);
		$context = phpbb_arcade_rating_context($reader, $actor, (int)$game_id, false);
		$name = $reader->sql_escape($context['game']['game_name']);
		$totals = $reader->rows('SELECT COUNT(rate_user_id) total,AVG(rate_point) rating,COALESCE(MAX(CASE WHEN rate_user_id=' . (int)$actor['user_id']
			. ' THEN 1 ELSE 0 END),0) already_rated FROM ' . iNA_GAMES_RATE . " WHERE rate_game_name='$name'");
		if (count($totals) !== 1) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
		$context['game']['total'] = $totals[0]['total']; $context['game']['rating'] = $totals[0]['rating'];
		$context['already_rated'] = (string)$totals[0]['already_rated'] === '1';
		return $context;
	}
	catch (PhpbbArcadeRatingFailure $error) { throw $error; }
	catch (Exception $error) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	catch (Error $error) { phpbb_arcade_rating_fail('arcade_rating_unavailable'); }
	finally { if ($reader) { $reader->release(); } }
}

class PhpbbArcadeRatingWriter extends PhpbbOwnedStore
{
	public $commit_attempted = false;
	protected function storage_tables() { return phpbb_arcade_rating_tables(); }
	protected function pin_tables()
	{
		// Validate the complete participant set together, retaining the same
		// transactional metadata pins without a separate metadata round trip
		// per table on every rating or injected failure boundary.
		$tables = $this->storage_tables(); $pins = $names = array();
		foreach ($tables as $i=>$table)
		{
			if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)) { $this->storage_fail(); }
			$pins[] = $table . ' rating_pin_' . $i; $names[] = "'" . $table . "'";
		}
		$this->rows('SELECT 1 FROM ' . implode(',',$pins) . ' LIMIT 0');
		$scope = 'TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',',$names) . ')';
		$metadata = $this->rows('SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE ' . $scope . " AND TABLE_TYPE='BASE TABLE'");
		if (count($metadata) !== count($tables)) { $this->storage_fail('Owned_storage_upgrade'); }
		foreach ($metadata as $row)
		{
			if (!in_array($row['TABLE_NAME'],$tables,true) || $row['ENGINE'] !== 'InnoDB' || strtolower($row['ROW_FORMAT']) !== 'dynamic'
				|| $row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { $this->storage_fail('Owned_storage_upgrade'); }
		}
		if ($this->rows('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE ' . $scope
			. " AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci') LIMIT 1")) { $this->storage_fail('Owned_storage_upgrade'); }
	}
	protected function storage_fail($key = 'arcade_rating_failed')
	{
		phpbb_arcade_rating_fail($key === 'Owned_storage_upgrade' ? 'arcade_rating_unavailable' : 'arcade_rating_failed');
	}
	function sql_escape($value) { return $this->escape($value); }
	function apply($game_id, $point)
	{
		$context = phpbb_arcade_rating_context($this, $this->actor, $game_id, true);
		if ($point > $context['scale']) { phpbb_arcade_rating_fail('bad_submitted_value'); }
		$name = $this->escape($context['game']['game_name']); $id = (int)$this->actor['user_id'];
		$where = "rate_game_name='$name' AND rate_user_id=$id";
		if ($this->rows('SELECT rate_game_name FROM ' . iNA_GAMES_RATE . ' WHERE ' . $where . ' FOR UPDATE')) { phpbb_arcade_rating_fail('already_rated'); }
		$ip = $this->escape($context['ip']);
		$this->query('INSERT INTO ' . iNA_GAMES_RATE . " (rate_game_name,rate_user_id,rate_user_ip,rate_point) VALUES('$name',$id,'$ip'," . (int)$point . ')');
		$saved = $this->rows('SELECT rate_game_name,rate_user_ip,rate_point FROM ' . iNA_GAMES_RATE . ' WHERE ' . $where);
		if (count($saved) !== 1 || $saved[0]['rate_game_name'] !== $context['game']['game_name'] || $saved[0]['rate_user_ip'] !== $context['ip'] || (int)$saved[0]['rate_point'] !== $point) { $this->storage_fail(); }
		$current = phpbb_arcade_rating_context($this, $this->actor, $game_id, true);
		if ($current['game']['game_name'] !== $context['game']['game_name'] || $point > $current['scale']) { $this->storage_fail(); }
		$this->commit_attempted = true; $this->commit();
		return array('game_id'=>$game_id, 'cat_id'=>(int)$current['game']['cat_id']);
	}
}
function phpbb_arcade_rate_game($database, $actor, $game_id, $point)
{
	if (!PhpbbForumReadSnapshot::valid_actor($actor)) { phpbb_arcade_rating_fail('Session_invalid'); }
	if (!phpbb_arcade_rating_id($game_id)) { phpbb_arcade_rating_fail('no_activity'); }
	if (!phpbb_arcade_rating_id($point, 255)) { phpbb_arcade_rating_fail('bad_submitted_value'); }
	$writer = null;
	try
	{
		$writer = new PhpbbArcadeRatingWriter($database, $actor);
		return $writer->apply((int)$game_id, (int)$point);
	}
	catch (Exception $error)
	{
		if ($writer && $writer->commit_attempted) { phpbb_arcade_rating_fail('arcade_rating_unconfirmed'); }
		if ($error instanceof PhpbbArcadeRatingFailure) { throw $error; }
		phpbb_arcade_rating_fail('arcade_rating_failed');
	}
	catch (Error $error) { phpbb_arcade_rating_fail($writer && $writer->commit_attempted ? 'arcade_rating_unconfirmed' : 'arcade_rating_failed'); }
	finally
	{
		if ($writer)
		{
			$attempted = $writer->commit_attempted; $writer->release();
			if ($attempted && isset($GLOBALS['phpbb_root_path']) && is_string($GLOBALS['phpbb_root_path'])
				&& isset($GLOBALS['arcade']) && is_object($GLOBALS['arcade']) && method_exists($GLOBALS['arcade'],'clear_cache'))
			{
				try { $GLOBALS['arcade']->clear_cache('games', $GLOBALS['phpbb_root_path']); } catch (Exception $ignored) {} catch (Error $ignored) {}
			}
		}
	}
}

function phpbb_arcade_rating_message($error)
{
	global $lang;
	$key = $error instanceof PhpbbArcadeRatingFailure ? $error->getMessage() : 'arcade_rating_failed';
	$allowed = array('Session_invalid','Not_Authorised','no_activity','does_not_exist','bad_submitted_value','already_rated',
		'arcade_rating_unavailable','arcade_rating_failed','arcade_rating_unconfirmed');
	if (in_array($key,$allowed,true) && isset($lang[$key])) { return $lang[$key]; }
	return isset($lang['arcade_rating_failed']) ? $lang['arcade_rating_failed'] : 'Game ratings are currently unavailable.';
}
