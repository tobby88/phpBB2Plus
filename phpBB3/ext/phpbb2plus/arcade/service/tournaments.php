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
		return $this->rows('SELECT p.owner_id,p.user_id AS source_player,u.username,u.user_colour
			FROM ' . $this->prefix . 'tour_plays p LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.owner_id AND p.owner_id>0
			WHERE p.tour_id=' . (int) $id . ' ORDER BY p.row_id', $limit, $start);
	}

	public function games($id)
	{
		$p = $this->prefix;
		return $this->rows("SELECT g.game_id,g.game_name,g.game_desc,d.score_units,d.winner_id AS owner_id,d.top_player AS source_player,u.username,u.user_colour
			FROM {$p}tour_games d JOIN {$p}games g ON g.game_name=d.game_name
			LEFT JOIN " . USERS_TABLE . ' u ON u.user_id=d.winner_id AND d.winner_id>0
			WHERE d.tour_id=' . (int) $id . ' AND ' . $this->catalog->game_filter() . "
			AND g.game_name<>'' AND NOT EXISTS (SELECT 1 FROM {$p}games other WHERE other.game_name=g.game_name AND other.game_id<>g.game_id)
			ORDER BY d.game_name,d.row_id");
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

	/** Called inside the same Arcade transaction coordinator as game starts. */
	public function join(array $ids)
	{
		if (!$this->available()) { throw new NotFoundHttpException(); }
		$owner = (int) $this->user->data['user_id'];
		$actors = $this->rows('SELECT user_type,user_rank,user_posts FROM ' . USERS_TABLE . " WHERE user_id=$owner LOCK IN SHARE MODE");
		if (!$actors || !$this->eligible($actors[0])) { throw new AccessDeniedHttpException(); }
		$source = (int) ($this->user->data['plus_legacy_id'] ?? 0) ?: $owner;
		$p = $this->prefix;
		$entries = $this->rows($this->entries() . ' WHERE ' . $this->db->sql_in_set('t.tour_id', $ids) . ' ORDER BY t.tour_id FOR UPDATE');
		$result = array();
		foreach ($entries as $entry)
		{
			$id = (int) $entry['tour_id'];
			$status = $this->join_status($entry);
			if ($status === '')
			{
				$values = array('tour_id'=>$id, 'owner_id'=>$owner, 'user_id'=>$source);
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
