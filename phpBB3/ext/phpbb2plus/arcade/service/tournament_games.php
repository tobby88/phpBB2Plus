<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class tournament_games
{
	private $db;
	private $catalog;
	private $tours;
	private $management;
	private $prefix;

	public function __construct($db, $catalog, $tours, $management, $prefix)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->tours = $tours;
		$this->management = $management;
		$this->prefix = $prefix;
	}

	private function catalog_query($tour = null)
	{
		$games = $this->prefix . 'games';
		$assigned = $tour === null ? '' : ', EXISTS (SELECT 1 FROM ' . $this->prefix .
			'tour_games d WHERE d.tour_id=' . (int) $tour . ' AND d.game_name=g.game_name) AS assigned';
		return "SELECT g.game_id,g.game_name,g.game_desc $assigned FROM $games g WHERE g.game_id>0 AND g.game_avail=1 AND g.game_show_score=1
			AND g.game_name<>'' AND NOT EXISTS (SELECT 1 FROM $games other WHERE other.game_name=g.game_name AND other.game_id<>g.game_id)";
	}

	private function page($sql, $limit, $start)
	{
		$result = $this->db->sql_query_limit($sql, $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function choices($id, $search, $limit, $start)
	{
		$sql = $this->catalog_query() . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->prefix .
			'tour_games d WHERE d.tour_id=' . (int) $id . ' AND d.game_name=g.game_name)';
		if ($search !== '')
		{
			$like = $this->db->sql_like_expression($this->db->get_any_char() . $search . $this->db->get_any_char());
			$sql .= " AND (g.game_name $like OR g.game_desc $like)";
		}
		return $this->page($sql . ' ORDER BY g.game_desc,g.game_name,g.game_id', $limit, $start);
	}

	public function assigned($id, $limit, $start)
	{
		return $this->page('SELECT game_name FROM ' . $this->prefix . 'tour_games WHERE tour_id=' . (int) $id .
			' ORDER BY game_name,row_id', $limit, $start);
	}

	public static function token($game)
	{
		return (int) $game['game_id'] . ':' . hash('sha256', serialize(array($game['game_name'], $game['game_desc'])));
	}

	/** Called under the same lock as tournament edits and score submission. */
	public function add($id, array $tokens, $revision)
	{
		if (!$this->catalog->admin()) { throw new AccessDeniedHttpException(); }
		$event = $this->tours->entry($id);
		if ((int) $event['tour_active'] === 3 || !hash_equals(tournament_management::revision($event), $revision))
		{ throw new \DomainException('PLUS_ARC_EVENT_CHANGED'); }
		if (!$tokens || count($tokens) > 25) { throw new \DomainException('PLUS_ARC_EVENT_SELECT'); }
		$selected = array();
		foreach ($tokens as $token)
		{
			if (!preg_match('/^([1-9][0-9]{0,9}):[a-f0-9]{64}$/D', $token, $parts) || isset($selected[(int) $parts[1]]))
			{ throw new \DomainException('PLUS_ARC_EVENT_SELECT'); }
			$selected[(int) $parts[1]] = $token;
		}
		$result = $this->db->sql_query($this->catalog_query($id) . ' AND ' . $this->db->sql_in_set('g.game_id', array_keys($selected)) . ' ORDER BY g.game_id');
		$games = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (count($games) !== count($selected)) { throw new \DomainException('PLUS_ARC_EVENT_CHANGED'); }
		$table = $this->prefix . 'tour_games';
		$result = $this->db->sql_query("SELECT COUNT(*) AS n FROM $table WHERE tour_id=" . (int) $id);
		$count = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		$adding = array();
		foreach ($games as $game)
		{
			if (!hash_equals(self::token($game), $selected[(int) $game['game_id']])) { throw new \DomainException('PLUS_ARC_EVENT_CHANGED'); }
			if (!(int) $game['assigned']) { $adding[] = array('tour_id'=>(int) $id, 'game_name'=>$game['game_name']); }
		}
		if (!$adding) { return; }
		if ($count + count($adding) > $this->management->limit('games_tournament_games'))
		{ throw new \DomainException('PLUS_ARC_EVENT_GAME_LIMIT'); }
		$this->db->sql_multi_insert($table, $adding);
		$this->management->activity($this->tours->identity(), 'add-games', $id);
	}
}
