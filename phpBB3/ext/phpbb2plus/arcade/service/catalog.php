<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class catalog
{
	private $db;
	private $auth;
	private $user;
	private $prefix;
	private $settings;
	private $groups;
	private $categories;

	public function __construct($db, $auth, $user, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public function setting($name, $default)
	{
		if ($this->settings === null)
		{
			$this->settings = array();
			$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . $this->prefix . 'settings');
			while ($row = $this->db->sql_fetchrow($result)) { $this->settings[$row['config_name']] = $row['config_value']; }
			$this->db->sql_freeresult($result);
		}
		return $this->settings[$name] ?? $default;
	}

	public function admin() { return !empty($this->user->data['is_registered']) && $this->auth->acl_get('a_plus_arcade'); }

	public function refresh() { $this->settings = $this->groups = $this->categories = null; }

	public function can_play($game)
	{
		return empty($this->user->data['is_bot']) && $game['game_avail'] && $game['asset_status'] === 'ready' &&
			(!empty($this->user->data['is_registered']) || $game['allow_guest']) &&
			(int) $this->user->data['user_posts'] >= (int) $this->setting('games_posts_required', 0);
	}

	public function staff()
	{
		return !empty($this->user->data['is_registered']) && ($this->admin() || $this->auth->acl_get('m_plus_arcade') || $this->auth->acl_get('m_') || $this->auth->acl_getf_global('m_'));
	}

	private function groups()
	{
		if ($this->groups !== null) { return $this->groups; }
		$this->groups = array(0);
		if (empty($this->user->data['is_registered'])) { return $this->groups; }
		$user = (int) $this->user->data['user_id'];
		$result = $this->db->sql_query('SELECT DISTINCT g.legacy_group FROM ' . $this->prefix . 'grants g
			LEFT JOIN ' . USER_GROUP_TABLE . " ug ON g.subject_type='g' AND ug.group_id=g.subject_id AND ug.user_id=$user AND ug.user_pending=0
			WHERE (g.subject_type='u' AND g.subject_id=$user) OR ug.user_id IS NOT NULL");
		while ($row = $this->db->sql_fetchrow($result)) { $this->groups[] = (int) $row['legacy_group']; }
		$this->db->sql_freeresult($result);
		return $this->groups;
	}

	public function board_access()
	{
		if ((int) $this->setting('games_offline', 1) && !$this->staff()) { return 'PLUS_ARC_CLOSED'; }
		if ((int) $this->setting('games_no_guests', 1) && empty($this->user->data['is_registered'])) { return 'LOGIN'; }
		if ($this->admin()) { return ''; }
		if ((int) $this->setting('games_mod_ban_users', 1) && !empty($this->user->data['is_registered']))
		{
			$result = $this->db->sql_query('SELECT blocked FROM ' . $this->prefix . 'members WHERE user_id=' . (int) $this->user->data['user_id']);
			$blocked = (int) $this->db->sql_fetchfield('blocked');
			$this->db->sql_freeresult($result);
			if ($blocked) { return 'NOT_AUTHORISED'; }
		}
		$level = (int) $this->setting('games_level_required', 0);
		if (($level !== 0 && ($level !== 2 || !$this->staff())) || (int) $this->user->data['user_rank'] < (int) $this->setting('games_rank_required', 0) ||
			!in_array((int) $this->setting('games_group_required', 0), $this->groups(), true)) { return 'NOT_AUTHORISED'; }
		return '';
	}

	public function categories()
	{
		if ($this->categories !== null) { return $this->categories; }
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'categories WHERE cat_id>0 ORDER BY cat_order,cat_id');
		$rows = array();
		while ($cat = $this->db->sql_fetchrow($result))
		{
			if ($this->admin() || in_array((int) $cat['group_required'], $this->groups(), true)) { $rows[(int) $cat['cat_id']] = $cat; }
		}
		$this->db->sql_freeresult($result);
		$this->categories = array();
		foreach ($rows as $id => $row)
		{
			$parent = (int) $row['cat_parent'];
			if ((in_array($row['cat_type'], array('p','l'), true) && !$parent) ||
				($row['cat_type'] === 's' && isset($rows[$parent]) && $rows[$parent]['cat_type'] === 'p' && !(int) $rows[$parent]['cat_parent']))
			{ $this->categories[$id] = $row; }
		}
		return $this->categories;
	}

	public function game_filter($category = 0)
	{
		$cats = $this->categories();
		if ($category && (!isset($cats[$category]) || $cats[$category]['cat_type'] === 'l')) { throw new NotFoundHttpException(); }
		$ids = array();
		foreach ($cats as $id => $cat)
		{
			if ($cat['cat_type'] !== 'l' && (!$category || $id === $category || (int) $cat['cat_parent'] === $category)) { $ids[] = $id; }
		}
		$cat_sql = $ids ? $this->db->sql_in_set('g.cat_id', $ids) : '1=0';
		if (!$category) { $cat_sql = "(g.cat_id IS NULL OR g.cat_id<=0 OR $cat_sql)"; }
		if ($this->admin()) { return 'g.game_id>0 AND ' . $cat_sql; }
		$levels = $this->staff() ? array(0, 2) : array(0);
		return 'g.game_id>0 AND g.game_avail=1 AND ' . $cat_sql . ' AND ' . $this->db->sql_in_set('g.level_required', $levels) .
			' AND g.rank_required<=' . (int) $this->user->data['user_rank'] . ' AND ' . $this->db->sql_in_set('g.group_required', $this->groups());
	}

	public function game($id)
	{
		if ($this->board_access() !== '') { throw new NotFoundHttpException(); }
		$result = $this->db->sql_query('SELECT g.* FROM ' . $this->prefix . 'games g WHERE g.game_id=' . (int) $id . ' AND ' . $this->game_filter());
		$game = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$game) { throw new NotFoundHttpException(); }
		return $game;
	}

	public function scores_available($game)
	{
		if (!$game['game_show_score'] || $game['game_name'] === null || $game['game_name'] === '') { return false; }
		// Historic results have a name, not a game ID. An ambiguous name cannot identify a restricted game.
		$result = $this->db->sql_query('SELECT COUNT(*) AS games FROM ' . $this->prefix . "games WHERE game_name='" . $this->db->sql_escape($game['game_name']) . "'");
		$unique = (int) $this->db->sql_fetchfield('games') === 1;
		$this->db->sql_freeresult($result);
		return $unique;
	}

	public function same_boards($game)
	{
		$where = " WHERE game_name='" . $this->db->sql_escape($game['game_name']) . "'";
		$key = 'owner_id, CASE WHEN owner_id>0 THEN 0 ELSE COALESCE(player_id,0) END AS legacy_player,score_units';
		$result = $this->db->sql_query_limit("SELECT 1 AS difference FROM (SELECT $key,1 AS delta FROM " . $this->prefix . 'scores' . $where .
			" UNION ALL SELECT $key,-1 AS delta FROM " . $this->prefix . 'all_scores' . $where .
			') s GROUP BY owner_id,legacy_player,score_units HAVING SUM(delta)<>0', 1);
		$same = $this->db->sql_fetchrow($result) === false;
		$this->db->sql_freeresult($result);
		return $same;
	}

	static public function plain($value) { return html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
