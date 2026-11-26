<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class sessions
{
	private $catalog;
	private $db;
	private $user;
	private $config;
	private $prefix;
	private $root;
	private $wallet;
	private $tournaments;

	public function __construct($catalog, $db, $user, $config, $prefix, $root, $wallet, $tournaments)
	{
		$this->catalog = $catalog;
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->wallet = $wallet;
		$this->tournaments = $tournaments;
	}

	private function session_key()
	{
		return hash_hmac('sha256', $this->user->session_id, $this->config['plus_arc_media_key']);
	}

	public function locked(callable $operation)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$table = $this->prefix . 'state';
			// Take the coordinator lock without opening a read snapshot before the operation locks its own rows.
			$result = $this->db->sql_query("SELECT state_id FROM $table WHERE state_id=1 FOR UPDATE");
			$exists = $this->db->sql_fetchfield('state_id') !== false;
			$this->db->sql_freeresult($result);
			if (!$exists) { throw new \RuntimeException('Arcade coordinator missing.'); }
			$this->catalog->refresh();
			$value = $operation();
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Arcade transaction did not commit.'); }
			return $value;
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	public function asset($id, $name)
	{
		$key = hash('sha256', (string) $name);
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . "assets WHERE game_id=" . (int) $id . " AND path_key='$key'");
		$asset = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$asset || $asset['relative_name'] !== $name || !preg_match('/^arcade_[a-f0-9]{64}$/D', $asset['storage_name'])) { throw new NotFoundHttpException(); }
		$asset['path'] = $this->root . trim($this->config['upload_path'], '/') . '/' . $asset['storage_name'];
		if (!is_file($asset['path']) || is_link($asset['path']) || (string) filesize($asset['path']) !== (string) $asset['bytes']) { throw new NotFoundHttpException(); }
		return $asset;
	}

	public function find($token, $id = 0, $lock = false)
	{
		if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) { throw new NotFoundHttpException(); }
		$key = hash('sha256', $token);
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . "launches WHERE token_key='$key' AND session_key='" . $this->session_key() .
			"' AND user_id=" . (int) $this->user->data['user_id'] . ' AND expires_at>' . time());
		$launch = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$launch || ($id && (int) $id !== (int) $launch['game_id'])) { throw new NotFoundHttpException(); }
		$game = $this->catalog->game($launch['game_id']);
		if (!$this->catalog->can_play($game, (int) $launch['tour_id'] > 0)) { throw new NotFoundHttpException(); }
		if ((int) $launch['tour_id'])
		{
			$turn = $this->tournaments->play_state($launch['tour_id'], $game, $lock);
			if ((int) $turn['participant_id'] !== (int) $launch['tour_participant'] || (int) $turn['entry_id'] !== (int) $launch['tour_entry']
				|| (int) $turn['played'] < 1) { throw new NotFoundHttpException(); }
			$launch['turn'] = $turn;
		}
		return array($game, $launch);
	}

	private function record_play($game, $now)
	{
		$id = (int) $game['game_id'];
		$user = (int) $this->user->data['user_id'];
		$this->db->sql_query('UPDATE ' . $this->prefix . "games SET played=COALESCE(played,0)+1 WHERE game_id=$id AND (played IS NULL OR played<4294967295)");
		$cats = array(-1);
		$category = (int) $game['cat_id'];
		if ($category > 0)
		{
			$rows = $this->catalog->categories();
			$cats[] = $category;
			if (!empty($rows[$category]['cat_parent'])) { $cats[] = (int) $rows[$category]['cat_parent']; }
		}
		$source_user = (int) ($this->user->data['plus_legacy_id'] ?? 0) ?: $user;
		$change = array('last_game' => $game['game_name'], 'last_player' => $user === ANONYMOUS ? -1 : $source_user,
			'last_user_id' => $user === ANONYMOUS ? 0 : $user, 'last_time' => $now);
		$this->db->sql_query('UPDATE ' . $this->prefix . 'categories SET ' . $this->db->sql_build_array('UPDATE', $change) .
			",total_played=CASE WHEN total_played='18446744073709551615' THEN total_played ELSE CAST(COALESCE(total_played,'0') AS DECIMAL(20,0))+1 END WHERE " . $this->db->sql_in_set('cat_id', $cats));
		if ($user === ANONYMOUS) { return; }
		$table = $this->prefix . 'players';
		$result = $this->db->sql_query_limit("SELECT row_id FROM $table WHERE owner_id=$user ORDER BY row_id", 1);
		$row = (int) $this->db->sql_fetchfield('row_id');
		$this->db->sql_freeresult($result);
		$activity = array('last_played' => $game['game_name'], 'last_played_date' => $now);
		if ($row) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $activity) . " WHERE row_id=$row"); }
		else { $this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $activity + array('user_id' => $user, 'owner_id' => $user))); }
	}

	public function start($id, $nonce, $tour = 0)
	{
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		$id = (int) $id;
		$user = (int) $this->user->data['user_id'];
		$session = $this->session_key();
		$tour = (int) $tour;
		$identity = implode(':', array($session,$user,$id,$nonce)) . ($tour ? ':tour:' . $tour : '');
		$token = substr(hash_hmac('sha256', $identity, $this->config['plus_arc_media_key']), 0, 32);
		$this->locked(function () use ($id, $token, $user, $session, $tour)
		{
			$game = $this->catalog->game($id);
			if (!$this->catalog->can_play($game, $tour > 0)) { throw new NotFoundHttpException(); }
			$this->asset($id, $game['main_asset']);
			$table = $this->prefix . 'launches';
			$key = hash('sha256', $token);
			$result = $this->db->sql_query("SELECT expires_at FROM $table WHERE token_key='$key'");
			$existing = $this->db->sql_fetchfield('expires_at');
			$this->db->sql_freeresult($result);
			if ($existing !== false) { if ((int) $existing <= time()) { throw new \DomainException('FORM_INVALID'); } return; }
			$now = time();
			$row = array('token_key' => $key, 'session_key' => $session, 'user_id' => $user, 'game_id' => $id, 'started_at' => $now, 'expires_at' => $now + 86400);
			if ($tour) { $row += $this->tournaments->begin_turn($tour, $game, $now); }
			else { $this->wallet->charge($game, $user); }
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $row));
			$this->record_play($game, $now);
			// Keep receipts longer than an accepted form token, including unlimited form lifetimes.
			$lifetime = (int) $this->config['form_token_lifetime'];
			if ($lifetime > 0) { $this->db->sql_query("DELETE FROM $table WHERE expires_at<" . ($now - $lifetime - 86400)); }
		});
		$this->user->set_cookie('plus_arcade', $token, time() + 86400);
		return $token;
	}
}
