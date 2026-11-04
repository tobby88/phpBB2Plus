<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_quota_storage.php';

// Filesystem/protocol identities must be exact. Captions use raw UTF-8;
// shortening a caption must never rename the asset or an existing game.
function phpbb_arcade_import_values($name, $path, $reverse, $description, $flash, $available, $width, $height, $category)
{
	if (!is_string($name) || $name === '' || trim($name) !== $name || in_array($name, array('.', '..'), true)
		|| phpbb_arcade_text_limit($name, 50, 50) !== $name || preg_match('#[\s/\\\\\x00-\x1f\x7f]#', $name)
		|| !is_string($path) || !is_string($description)) { return false; }
	$path = phpbb_arcade_local_asset($path);
	if ($path !== '' && substr($path, -1) !== '/') { $path .= '/'; }
	if ($path === '' || phpbb_arcade_text_limit($path, 255) !== $path || preg_match('/[\x00-\x1f\x7f]/', $path)) { return false; }
	foreach (array($reverse, $flash, $available, $width, $height, $category) as $number)
	{ if (!(is_int($number) || is_string($number) || is_bool($number)) || !preg_match('/^-?[0-9]{1,10}$/D', (string)(int)$number) || (is_string($number) && !preg_match('/^-?[0-9]{1,10}$/D', $number))) { return false; } }
	$category = (int)$category; if ($category < -1 || $category > 8388607) { return false; }
	if ($description === '') { $description = ucfirst(trim(str_replace('_', ' ', $name))); }
	$description = phpbb_arcade_text_limit($description, 255);
	if ($description === null) { return false; }
	return array('game_name'=>$name, 'game_path'=>$path, 'game_desc'=>$description,
		'reverse_list'=>empty($reverse)?0:1, 'game_flash'=>empty($flash)?0:1, 'game_avail'=>empty($available)?0:1,
		'win_width'=>(int)$width<=0?550:min(4096,(int)$width), 'win_height'=>(int)$height<=0?450:min(4096,(int)$height),
		'cat_id'=>$category===0?-1:$category);
}

class PhpbbArcadeImportWriter extends PhpbbOwnedStore
{
	public $commit_attempted = false;
	function __construct($database)
	{
		phpbb_attach_quota_post($_POST);
		parent::__construct($database, null);
		try { $this->actor(); }
		catch (Exception $e) { $this->release(); throw $e; }
		catch (Error $e) { $this->release(); throw $e; }
	}
	protected function storage_tables() { return array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, iNA_GAMES, iNA_CAT); }
	function sql_query($sql, $transaction = false) { return $this->query($sql); }
	function sql_escape($value) { return $this->escape($value); }
	function sql_fetchrow($result) { return $this->connection->sql_fetchrow($result); }
	function sql_fetchrowset($result) { return $this->connection->sql_fetchrowset($result); }
	function sql_freeresult($result) { return $this->connection->sql_freeresult($result); }
	protected function actor()
	{
		global $phpEx;
		return phpbb_acp_actor($this, 'admin_arcade_games.' . $phpEx . '?mode=import_game');
	}
	protected function query($sql)
	{
		if ($this->transaction && preg_match('/^\s*(?:INSERT|UPDATE|DELETE|COMMIT)\b/i', $sql)) { $this->actor(); }
		return parent::query($sql);
	}
	function add($values)
	{
		global $userdata;
		$keys = array('game_name','game_path','game_desc','reverse_list','game_flash','game_avail','win_width','win_height','cat_id');
		if (!is_array($values) || array_keys($values) !== $keys
			|| phpbb_arcade_import_values($values['game_name'], $values['game_path'], $values['reverse_list'], $values['game_desc'],
				$values['game_flash'], $values['game_avail'], $values['win_width'], $values['win_height'], $values['cat_id']) !== $values) { $this->storage_fail(); }
		$actor = $this->actor(); $sid = $this->escape($userdata['session_id']);
		// Keep a current ACP identity through the complete publication. Revocation
		// commits before these locks, or waits until this game's commit completes.
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
		{ $this->rows($sql . ' LOCK IN SHARE MODE'); }
		$this->actor();
		$category = $values['cat_id']; $wanted = $category > 0 ? array(-1, $category) : array(-1);
		$rows = $this->rows('SELECT cat_id,cat_type FROM ' . iNA_CAT . ' WHERE cat_id IN (' . implode(',', $wanted) . ') ORDER BY cat_id FOR UPDATE');
		if (count($rows) !== count($wanted)) { return false; }
		foreach ($rows as $row) { if ($row['cat_type'] === 'l') { return false; } }
		$name = $this->escape($values['game_name']);
		if ($this->rows('SELECT game_id FROM ' . iNA_GAMES . " WHERE game_name='$name' FOR UPDATE")) { return false; }
		$columns = array('date_added'); $literals = array((string)time());
		foreach ($values as $key=>$value) { $columns[] = $key; $literals[] = "'" . $this->escape((string)$value) . "'"; }
		$this->query('INSERT INTO ' . iNA_GAMES . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $literals) . ')');
		// No intervening SELECT/UPDATE: mysqli_insert_id belongs to this INSERT.
		$id = $this->connection->sql_nextid();
		if (!preg_match('/^[1-9][0-9]{0,7}$/D', (string)$id) || (float)$id > 8388607) { $this->storage_fail(); }
		if ($values['game_avail'])
		{
			foreach ($wanted as $cat)
			{
				$this->query('UPDATE ' . iNA_CAT . ' SET total_games=total_games+1 WHERE cat_id=' . $cat);
				if ((int)$this->connection->sql_affectedrows() !== 1) { $this->storage_fail(); }
			}
		}
		$this->commit_attempted = true; $this->commit();
		return (int)$id;
	}
}

function phpbb_arcade_import_game($database, $values)
{
	global $arcade, $phpbb_root_path;
	if ($values === false) { return false; }
	$writer = new PhpbbArcadeImportWriter($database);
	try { return $writer->add($values); }
	finally
	{
		try
		{
			// Also evict after an uncertain commit acknowledgement. Never replay
			// database writes to repair a cache, or retain stale earlier batch data.
			if ($writer->commit_attempted)
			{
				foreach (array('games','categories') as $key)
				{
					try { $arcade->clear_cache($key, $phpbb_root_path); }
					catch (Exception $ignored) {} catch (Error $ignored) {}
				}
			}
		}
		finally { $writer->release(); }
	}
}
