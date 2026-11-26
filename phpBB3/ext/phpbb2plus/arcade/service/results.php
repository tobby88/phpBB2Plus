<?php
namespace phpbb2plus\arcade\service;

class results
{
	private $db;
	private $catalog;
	private $user;
	private $config;
	private $prefix;
	private $wallet;
	private $tournaments;

	public function __construct($db, $catalog, $user, $config, $prefix, $wallet, $tournaments)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->user = $user;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->wallet = $wallet;
		$this->tournaments = $tournaments;
	}

	private function first($select)
	{
		$result = $this->db->sql_query_limit($select, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	private function better($candidate, $row, $reverse)
	{
		return !$row || $row['score_units'] === null || ($reverse ? $candidate < $row['score_units'] : $candidate > $row['score_units']);
	}

	private function board($game, $launch, $units, $now, $all)
	{
		$table = $this->prefix . ($all ? 'all_scores' : 'scores');
		$id = (int) $launch['user_id'];
		$guest = $id === ANONYMOUS;
		$game_where = "game_name='" . $this->db->sql_escape($game['game_name']) . "'";
		$actor = $guest ? "owner_id=0 AND guest_key='" . $this->db->sql_escape($launch['session_key']) . "'" : 'owner_id=' . $id;
		$reverse = (bool) $game['reverse_list'];
		$order = ' ORDER BY score_units ' . ($reverse ? 'ASC' : 'DESC') . ',recorded_at,player_id,row_id';
		$old_top = $this->first("SELECT score_units,owner_id FROM $table WHERE $game_where$order");
		$old = $this->first("SELECT row_id,score_units FROM $table WHERE $game_where AND $actor$order");
		$changed = $this->better($units, $old, $reverse);
		if ($changed)
		{
			$name = $guest ? '' : $this->user->data['username'];
			$values = array('score_units' => $units, 'recorded_at' => $now, 'time_taken' => max(0, $now - (int) $launch['started_at']),
				'player_ip' => $this->user->ip, 'player' => $name, 'author_removed' => 0);
			if ($all) { $values['player_name'] = $name; }
			if ($old)
			{
				// Keep a migrated player's source ID and any saved game attached to that historic row.
				$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $values) . ' WHERE row_id=' . (int) $old['row_id']);
			}
			else
			{
				$values += array('game_name' => $game['game_name'], 'player_id' => $guest ? -1 : $id, 'owner_id' => $guest ? 0 : $id);
				if ($guest) { $values['guest_key'] = $launch['session_key']; }
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $values));
			}
			$limit = (int) $game[$all ? 'at_highscore_limit' : 'highscore_limit'];
			if ($limit > 0)
			{
				// Work in small sets. Converted boards occasionally exceed their historical limit.
				do
				{
					$result = $this->db->sql_query_limit("SELECT row_id FROM $table WHERE $game_where$order", 150, $limit);
					$remove = array();
					while ($row = $this->db->sql_fetchrow($result)) { $remove[] = (int) $row['row_id']; }
					$this->db->sql_freeresult($result);
					if ($remove) { $this->db->sql_query("DELETE FROM $table WHERE " . $this->db->sql_in_set('row_id', $remove)); }
				} while (count($remove) === 150);
			}
			score::refresh_winner($this->db, $this->prefix, $game, $all);
		}
		return array('record' => $changed && $this->better($units, $old_top, $reverse), 'previous' => (int) ($old_top['owner_id'] ?? 0));
	}

	private function monthly($game, $units, $id, $now)
	{
		if (!(int) $this->catalog->setting('games_show_mhm', 1) || $units < 0 || $units > 999999999999) { return; }
		$date = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($this->config['board_timezone'] ?: 'UTC'));
		$year = (int) $date->format('Y');
		$month = (int) $date->format('n');
		$table = $this->prefix . 'monthly';
		$where = "highscore_year=$year AND highscore_mon=$month AND highscore_game='" . $this->db->sql_escape($game['game_name']) . "'";
		$reverse = (bool) $game['reverse_list'];
		$row = $this->first("SELECT highscore_id,score_units FROM $table WHERE $where ORDER BY score_units " .
			($reverse ? 'ASC' : 'DESC') . ',highscore_date,highscore_user_id,highscore_id');
		if (!$this->better($units, $row, $reverse)) { return; }
		$values = array('owner_id' => $id, 'highscore_user_id' => $id, 'highscore_player' => $this->user->data['username'],
			'score_units' => $units, 'highscore_date' => $now, 'author_removed' => 0);
		if ($row) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $values) . ' WHERE highscore_id=' . (int) $row['highscore_id']); }
		else
		{
			$values += array('highscore_year' => $year, 'highscore_mon' => $month, 'highscore_game' => $game['game_name']);
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $values));
		}
	}

	// Called inside sessions::locked after the current board permissions and launch have been checked.
	public function submit($game, $launch, $units, $protocol)
	{
		$id = (int) $launch['user_id'];
		if ($units === '0' || !$this->catalog->scores_available($game) ||
			($id === ANONYMOUS && !(int) $this->catalog->setting('games_guest_highscore', 0))) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		if ($launch['finished_at'])
		{
			if ((string) $launch['score_units'] !== $units) { throw new \DomainException('PLUS_ARC_FINISHED'); }
			return;
		}
		$now = time();
		if ((int) $launch['tour_id']) { $this->tournaments->finish_turn($game, $launch, $units, $now); }
		$current = $this->board($game, $launch, $units, $now, false);
		if ($id !== ANONYMOUS)
		{
			$all = (int) $this->catalog->setting('games_at_highscore', 1) ? $this->board($game, $launch, $units, $now, true) : null;
			$this->monthly($game, $units, $id, $now);
			if ($protocol === 'standard') { $this->wallet->reward($game, $id, $units, $current['record'], !empty($all['record'])); }
			$duration = max(0, $now - (int) $launch['started_at']);
			$won = $current['record'] || !empty($all['record']) ? ",last_won_date=$now" : '';
			$this->db->sql_query('UPDATE ' . $this->prefix . "players SET user_game_time=CASE WHEN COALESCE(user_game_time,0)>=" .
				(2147483647 - $duration) . " THEN 2147483647 ELSE COALESCE(user_game_time,0)+$duration END$won WHERE owner_id=$id");
		}
		if ((int) $this->catalog->setting('games_use_log', 0))
		{
			$row = array('user_id' => $id === ANONYMOUS ? -1 : $id, 'owner_id' => $id === ANONYMOUS ? 0 : $id, 'name' => 'GAME',
				'log_value' => 'game=' . $game['game_name'] . ' score=' . score::display($units) . ' protocol=' . $protocol, 'recorded_at' => $now);
			$this->db->sql_query('INSERT INTO ' . $this->prefix . 'log ' . $this->db->sql_build_array('INSERT', $row));
		}
		$this->db->sql_query('UPDATE ' . $this->prefix . 'launches SET ' . $this->db->sql_build_array('UPDATE', array('finished_at' => $now, 'score_units' => $units)) .
			" WHERE token_key='" . $this->db->sql_escape($launch['token_key']) . "'");
	}

	public function progress($game, $launch, $data = null)
	{
		$id = (int) $launch['user_id'];
		if ($id === ANONYMOUS || ($data !== null && ($launch['finished_at'] || strlen($data) > 65535 || preg_match('//u', $data) !== 1)))
		{ throw new \DomainException('PLUS_ARC_PROGRESS'); }
		$gid = (int) $game['game_id'];
		$table = $this->prefix . 'saves';
		$row = $this->first("SELECT row_id,game_data,save_encoded FROM $table WHERE owner_id=$id AND game_id=$gid ORDER BY saved_at DESC,row_id DESC");
		if ($data === null)
		{
			if ($row) { return saved_game::unpack($row); }
			if (!$this->catalog->scores_available($game)) { return ''; }
			$legacy = saved_game::from_score($this->db, $this->prefix, $game, $id);
			return $legacy ? saved_game::unpack($legacy) : '';
		}
		$values = saved_game::pack($data) + array('saved_at' => (string) time());
		if ($row) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $values) . ' WHERE row_id=' . (int) $row['row_id']); }
		else { $this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $values + array('user_id' => $id, 'owner_id' => $id, 'game_id' => $gid))); }
		return '';
	}

	public function challenge($launch)
	{
		if ($launch['finished_at']) { throw new \DomainException('PLUS_ARC_FINISHED'); }
		$a = (int) $launch['rand_one'];
		$b = (int) $launch['rand_two'];
		if (!$a || !$b)
		{
			$a = random_int(1, 200);
			$b = random_int(1, 200);
			$this->db->sql_query('UPDATE ' . $this->prefix . "launches SET rand_one=$a,rand_two=$b WHERE token_key='" . $this->db->sql_escape($launch['token_key']) . "'");
		}
		return "&randchar=$a&randchar2=$b&savescore=1&blah=OK";
	}
}
