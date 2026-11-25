<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class tournaments
{
	private $db;
	private $catalog;
	private $user;
	private $prefix;

	public function __construct($db, $catalog, $user, $prefix)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public function available()
	{
		return !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot'])
			&& (int) $this->catalog->setting('games_tournament_mode', 0) === 1 && $this->catalog->board_access() === '';
	}

	private function rows($sql, $limit = 0, $start = 0)
	{
		$result = $limit ? $this->db->sql_query_limit($sql, $limit, $start) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function entries()
	{
		$p = $this->prefix;
		$user = (int) $this->user->data['user_id'];
		return "SELECT t.*,
			(SELECT COUNT(*) FROM {$p}tour_plays p WHERE p.tour_id=t.tour_id) AS players,
			EXISTS (SELECT 1 FROM {$p}tour_plays p WHERE p.tour_id=t.tour_id AND p.owner_id=$user) AS is_member,
			EXISTS (SELECT 1 FROM {$p}invites i WHERE i.tour_id=t.tour_id AND i.owner_id=$user) AS invited
			FROM {$p}tours t";
	}

	public function entry($id)
	{
		$rows = $this->rows($this->entries() . ' WHERE t.tour_id=' . (int) $id);
		if (!$rows) { throw new NotFoundHttpException(); }
		return $rows[0];
	}

	public function total($champions)
	{
		$where = $champions ? 'tour_active=3 AND champion>0' : 'tour_active<>3';
		return (int) $this->rows('SELECT COUNT(*) AS n FROM ' . $this->prefix . "tours WHERE $where")[0]['n'];
	}

	public function listing($start, $limit)
	{
		return $this->rows($this->entries() . ' WHERE t.tour_active<>3 ORDER BY t.tour_active DESC,t.tour_id', $limit, $start);
	}

	public function champions($start, $limit)
	{
		return $this->rows('SELECT t.tour_id,t.tour_name,t.winner_id AS owner_id,t.champion AS source_player,u.username,u.user_colour
			FROM ' . $this->prefix . 'tours t LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=t.winner_id AND t.winner_id>0
			WHERE t.tour_active=3 AND t.champion>0 ORDER BY t.tour_id DESC', $limit, $start);
	}

	public function participants($id, $start, $limit)
	{
		return $this->rows('SELECT p.row_id,p.owner_id,p.user_id AS source_player,u.username,u.user_colour
			FROM ' . $this->prefix . 'tour_plays p LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.owner_id AND p.owner_id>0
			WHERE p.tour_id=' . (int) $id . ' ORDER BY p.row_id', $limit, $start);
	}

	private function visible_games()
	{
		return $this->catalog->game_filter() . " AND g.game_name<>'' AND CAST(g.game_name AS BINARY)=CAST(d.game_name AS BINARY)
			AND NOT EXISTS (SELECT 1 FROM " . $this->prefix . 'games other WHERE other.game_name=g.game_name AND other.game_id<>g.game_id)';
	}

	public function participant_games($participants)
	{
		if (!$participants) { return array(); }
		$p = $this->prefix;
		$own = (int) $this->user->data['user_id'];
		$admin = $this->catalog->admin();
		$rows = $this->rows("SELECT p.row_id,p.owner_id,s.played,s.score_units,g.game_name,g.game_desc
			FROM {$p}tour_plays p JOIN {$p}turns s ON s.participant_id=p.row_id
			JOIN {$p}tour_games d ON d.tour_id=p.tour_id AND d.game_name=s.game_name AND CAST(d.game_name AS BINARY)=CAST(s.game_name AS BINARY)
			JOIN {$p}games g ON g.game_name=d.game_name
			WHERE (s.played>0 OR s.score_units IS NOT NULL) AND " . $this->db->sql_in_set('p.row_id', array_column($participants, 'row_id')) .
			' AND ' . $this->visible_games() . ' ORDER BY p.row_id,g.game_name');
		$games = array();
		foreach ($rows as $row)
		{
			$details = $admin || (int) $row['owner_id'] === $own;
			$games[$row['row_id']][] = array('name'=>catalog::plain($row['game_desc'] ?: $row['game_name']),
				'played'=>$details ? (int) $row['played'] : null, 'score'=>$details ? $row['score_units'] : null);
		}
		return $games;
	}

	public function games($id)
	{
		$p = $this->prefix;
		$owner = (int) $this->user->data['user_id'];
		return $this->rows("SELECT g.game_id,g.game_name,g.game_desc,g.game_avail,g.allow_guest,g.asset_status,g.game_show_score,
			d.score_units,d.winner_id AS owner_id,d.top_player AS source_player,u.username,u.user_colour,p.turns_state,
			s.played,s.score_units AS own_units
			FROM {$p}tour_games d JOIN {$p}games g ON g.game_name=d.game_name
			LEFT JOIN {$p}tour_plays p ON p.tour_id=d.tour_id AND p.owner_id=$owner
			LEFT JOIN {$p}turns s ON s.participant_id=p.row_id AND CAST(s.game_name AS BINARY)=CAST(d.game_name AS BINARY)
			LEFT JOIN " . USERS_TABLE . ' u ON u.user_id=d.winner_id AND d.winner_id>0
			WHERE d.tour_id=' . (int) $id . ' AND ' . $this->visible_games() . ' ORDER BY d.game_name,d.row_id');
	}

	public function can_start($entry, $game)
	{
		return (int) $entry['tour_active'] === 2 && (int) $game['turns_state'] === 1
			&& (int) $game['played'] < (int) $entry['tour_player_turns'] && $game['game_show_score']
			&& $this->eligible($this->user->data) && $this->catalog->can_play($game, true);
	}

	/** Bind a launch to the same event entry and participant throughout its lifetime. */
	public function play_state($id, $game, $lock)
	{
		if (!$this->available() || !$this->eligible($this->user->data) || !$this->catalog->scores_available($game)) { throw new NotFoundHttpException(); }
		$p = $this->prefix;
		$owner = (int) $this->user->data['user_id'];
		$name = $this->db->sql_escape($game['game_name']);
		$key = hash('sha256', $game['game_name']);
		$rows = $this->rows("SELECT p.row_id AS participant_id,p.user_id AS source_player,d.row_id AS entry_id,t.tour_player_turns,
			s.played,s.score_units AS own_units,d.score_units AS top_units,d.top_player
			FROM {$p}tours t JOIN {$p}tour_plays p ON p.tour_id=t.tour_id AND p.owner_id=$owner AND p.turns_state=1
			JOIN {$p}tour_games d ON d.tour_id=t.tour_id AND d.game_name='$name' AND CAST(d.game_name AS BINARY)=CAST('$name' AS BINARY)
			LEFT JOIN {$p}turns s ON s.participant_id=p.row_id AND s.name_key='$key'
			WHERE t.tour_id=" . (int) $id . ' AND t.tour_active=2' . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { throw new NotFoundHttpException(); }
		return $rows[0];
	}

	private function last_play($participant, $game, $now)
	{
		$set = array('last_played_game'=>$game['game_name'], 'last_played_time'=>$now);
		$this->db->sql_query('UPDATE ' . $this->prefix . 'tour_plays SET ' . $this->db->sql_build_array('UPDATE', $set) . ' WHERE row_id=' . (int) $participant);
	}

	public function begin_turn($id, $game, $now)
	{
		$turn = $this->play_state($id, $game, true);
		$played = (int) $turn['played'];
		if ($played >= (int) $turn['tour_player_turns']) { throw new \DomainException('PLUS_ARC_TOUR_EXHAUSTED'); }
		$table = $this->prefix . 'turns';
		$key = hash('sha256', $game['game_name']);
		$player = (int) $turn['participant_id'];
		if ($turn['played'] === null)
		{
			$row = array('participant_id'=>$player, 'name_key'=>$key, 'game_name'=>$game['game_name'], 'played'=>1);
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $row));
		}
		else { $this->db->sql_query("UPDATE $table SET played=played+1 WHERE participant_id=$player AND name_key='$key'"); }
		$this->last_play($player, $game, $now);
		return array('tour_id'=>(int) $id, 'tour_participant'=>$player, 'tour_entry'=>(int) $turn['entry_id']);
	}

	public function finish_turn($game, $launch, $units, $now)
	{
		if (abs((float) $units) > 999999999999) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		$turn = $launch['turn'];
		$better = static function ($old) use ($game, $units) { return $old === null || ($game['reverse_list'] ? $units < $old : $units > $old); };
		$player = (int) $turn['participant_id'];
		if ($better($turn['own_units']))
		{
			$key = hash('sha256', $game['game_name']);
			$set = $this->db->sql_build_array('UPDATE', array('score_units'=>$units));
			$this->db->sql_query('UPDATE ' . $this->prefix . "turns SET $set WHERE participant_id=$player AND name_key='$key'");
		}
		if ($better((int) $turn['top_player'] > 0 ? $turn['top_units'] : null))
		{
			$values = array('score_units'=>$units, 'top_player'=>(int) $turn['source_player'], 'winner_id'=>(int) $launch['user_id']);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'tour_games SET ' . $this->db->sql_build_array('UPDATE', $values) . ' WHERE row_id=' . (int) $turn['entry_id']);
		}
		$this->last_play($player, $game, $now);
	}

	private function eligible($actor)
	{
		return in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true)
			&& (int) $actor['user_posts'] >= (int) $this->catalog->setting('games_posts_required', 0)
			&& (int) $actor['user_rank'] >= (int) $this->catalog->setting('games_rank_required', 0);
	}

	public function join_status($entry)
	{
		if ($entry['is_member']) { return 'PLUS_ARC_TOUR_MEMBER'; }
		if ((int) $entry['tour_active'] !== 2) { return 'PLUS_ARC_TOUR_CLOSED'; }
		if ((int) $entry['players'] >= (int) $entry['tour_max_players']) { return 'PLUS_ARC_TOUR_FULL'; }
		if (!$this->eligible($this->user->data)) { return 'NOT_AUTHORISED'; }
		return '';
	}

	public function may_participate()
	{
		return $this->available() && $this->eligible($this->user->data);
	}

	/** Read the current participant while holding the Arcade transaction. */
	public function current_participant()
	{
		if (!$this->available()) { throw new NotFoundHttpException(); }
		$owner = (int) $this->user->data['user_id'];
		$actors = $this->rows('SELECT user_type,user_rank,user_posts FROM ' . USERS_TABLE . " WHERE user_id=$owner LOCK IN SHARE MODE");
		if (!$actors || !$this->eligible($actors[0])) { throw new AccessDeniedHttpException(); }
		return array('owner_id'=>$owner, 'user_id'=>(int) ($this->user->data['plus_legacy_id'] ?? 0) ?: $owner);
	}

	public function join(array $ids)
	{
		$actor = $this->current_participant();
		$owner = $actor['owner_id'];
		$source = $actor['user_id'];
		$p = $this->prefix;
		$entries = $this->rows($this->entries() . ' WHERE ' . $this->db->sql_in_set('t.tour_id', $ids) . ' ORDER BY t.tour_id FOR UPDATE');
		$result = array();
		foreach ($entries as $entry)
		{
			$id = (int) $entry['tour_id'];
			$status = $this->join_status($entry);
			if ($status === '')
			{
				$values = array('tour_id'=>$id, 'owner_id'=>$owner, 'user_id'=>$source, 'turns_state'=>1);
				$this->db->sql_query("INSERT INTO {$p}tour_plays " . $this->db->sql_build_array('INSERT', $values));
				if ((int) $this->catalog->setting('games_use_log', 0))
				{
					$log = array('user_id'=>$source, 'owner_id'=>$owner, 'name'=>'GAME', 'log_value'=>'Tournament join id=' . $id, 'recorded_at'=>time());
					$this->db->sql_query("INSERT INTO {$p}log " . $this->db->sql_build_array('INSERT', $log));
				}
				$status = 'PLUS_ARC_TOUR_JOINED';
			}
			$result[$id] = array('name'=>$entry['tour_name'], 'status'=>$status);
		}
		return $result;
	}
}
