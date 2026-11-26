<?php
namespace phpbb2plus\arcade\service;

class standings
{
	private $db;
	private $catalog;
	private $config;
	private $prefix;

	public function __construct($db, $catalog, $config, $prefix)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->config = $config;
		$this->prefix = $prefix;
	}

	public function month($requested, $months_ago = 0)
	{
		$zone = new \DateTimeZone($this->config['board_timezone'] ?: 'UTC');
		if (preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $requested))
		{
			return new \DateTimeImmutable($requested . '-01 12:00:00', $zone);
		}
		$first = new \DateTimeImmutable('first day of this month 12:00:00', $zone);
		return $first->modify('-' . min(120, max(0, (int) $months_ago)) . ' months');
	}

	private function monthly_from($guest)
	{
		return ' FROM ' . $this->prefix . 'monthly s JOIN ' . $this->prefix . 'games g ON g.game_name=s.highscore_game
			WHERE ' . $this->catalog->ranked_game_filter() . ($guest ? ' AND g.allow_guest<>0' : '');
	}

	public function available_months($guest)
	{
		$result = $this->db->sql_query_limit('SELECT DISTINCT s.highscore_year,s.highscore_mon' . $this->monthly_from($guest) .
			' AND s.highscore_mon BETWEEN 1 AND 12 AND s.highscore_year BETWEEN 1000 AND 9999
			ORDER BY s.highscore_year DESC,s.highscore_mon DESC', 12);
		$months = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$months[] = sprintf('%04d-%02d', $row['highscore_year'], $row['highscore_mon']);
		}
		$this->db->sql_freeresult($result);
		return $months;
	}

	// Two sort keys avoid negating a signed, fixed-point score at the lower end of its range.
	private function score_order()
	{
		return 'CASE WHEN g.reverse_list<>0 THEN r.score_units END ASC,
			CASE WHEN COALESCE(g.reverse_list,0)=0 THEN r.score_units END DESC';
	}

	private function monthly_query(array $months, $guest)
	{
		$periods = array();
		foreach ($months as $month)
		{
			$periods[] = '(s.highscore_year=' . (int) $month->format('Y') . ' AND s.highscore_mon=' . (int) $month->format('n') . ')';
		}
		return 'SELECT g.game_id,g.game_name,g.game_desc,g.icon_storage,s.highscore_id,s.score_units,s.owner_id,s.author_removed,
			s.highscore_user_id AS legacy_id,s.highscore_player AS display_name' . $this->monthly_from($guest) .
			' AND (' . implode(' OR ', $periods) . ') AND s.highscore_id=(SELECT r.highscore_id FROM ' . $this->prefix . 'monthly r
				WHERE r.highscore_game=s.highscore_game AND r.highscore_year=s.highscore_year AND r.highscore_mon=s.highscore_mon
				ORDER BY ' . $this->score_order() . ',r.highscore_date,r.highscore_user_id,r.highscore_id LIMIT 1)';
	}

	public function leading_player($all_time)
	{
		$table = $this->prefix . ($all_time ? 'all_scores' : 'scores');
		// Cached per-player totals include private categories. Rank the visible scores, then resolve their current owners.
		$result = $this->db->sql_query_limit('SELECT u.user_id,u.username,u.user_colour,COUNT(*) AS wins
			FROM ' . $this->prefix . "games g JOIN $table s ON s.row_id=(SELECT r.row_id FROM $table r
				WHERE r.game_name=g.game_name ORDER BY " . $this->score_order() . ',r.recorded_at,r.player_id,r.row_id LIMIT 1)
			JOIN ' . USERS_TABLE . ' u ON u.user_id=s.owner_id WHERE u.user_id>' . ANONYMOUS . ' AND ' . $this->catalog->ranked_game_filter() .
			($all_time ? ' AND g.at_highscore_limit>0' : '') . ' GROUP BY u.user_id,u.username,u.user_colour ORDER BY wins DESC,
			(SELECT MIN(p.last_won_date) FROM ' . $this->prefix . 'players p WHERE p.owner_id=u.user_id),u.user_id', 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	private function with_users($query)
	{
		return "SELECT w.*,u.username,u.user_colour FROM ($query) w LEFT JOIN " . USERS_TABLE . ' u ON u.user_id=w.owner_id AND w.owner_id>0';
	}

	public function monthly($month, $guest)
	{
		$query = $this->with_users($this->monthly_query(array($month), $guest)) . ' ORDER BY w.score_units DESC,w.highscore_id';
		$result = $this->db->sql_query_limit($query, 60);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function all_time_query()
	{
		$table = $this->prefix . 'all_scores';
		// The original points table chooses the best all-time result belonging to an existing account
		// (including the anonymous account). A deleted account's monthly trophies still count.
		return "SELECT s.owner_id,s.author_removed,s.player_id AS legacy_id,COALESCE(NULLIF(s.player_name,''),s.player) AS display_name
			FROM " . $this->prefix . "games g JOIN $table s ON s.game_name=g.game_name WHERE " . $this->catalog->ranked_game_filter() . "
			AND g.at_highscore_limit>0 AND s.row_id=(SELECT r.row_id FROM $table r WHERE r.game_name=g.game_name
				AND ((r.owner_id=0 AND r.player_id=-1 AND r.author_removed=0) OR EXISTS
					(SELECT 1 FROM " . USERS_TABLE . " winner_user WHERE winner_user.user_id=r.owner_id AND r.owner_id>0))
				ORDER BY " . $this->score_order() . ',r.recorded_at,r.player_id,r.row_id LIMIT 1)';
	}

	public function points($current)
	{
		$queries = $players = array();
		if ((int) $this->catalog->setting('games_show_mhm', 1))
		{
			$queries['monthly'] = $this->monthly_query(array($current, $current->modify('-1 month')), false);
		}
		if ((int) $this->catalog->setting('games_at_highscore', 1)) { $queries['all_time'] = $this->all_time_query(); }
		foreach ($queries as $kind => $query)
		{
			$result = $this->db->sql_query($this->with_users($query));
			while ($row = $this->db->sql_fetchrow($result))
			{
				if ($row['username'] !== null) { $key = 'u:' . $row['owner_id']; }
				elseif ((int) $row['legacy_id'] > 0) { $key = 'r:' . $row['legacy_id']; }
				else { $key = 'n:' . $row['display_name']; }
				if (!isset($players[$key]))
				{
					$players[$key] = $row + array('monthly' => 0, 'all_time' => 0, 'points' => 0, 'key' => $key);
				}
				$players[$key][$kind]++;
				$players[$key]['points'] += $kind === 'monthly' ? 1 : 4;
			}
			$this->db->sql_freeresult($result);
		}
		usort($players, static function ($left, $right) {
			return ($right['points'] <=> $left['points']) ?: ($right['all_time'] <=> $left['all_time'])
				?: strcasecmp(catalog::plain($left['username'] ?? $left['display_name']), catalog::plain($right['username'] ?? $right['display_name']))
				?: strcmp($left['key'], $right['key']);
		});
		return $players;
	}
}
