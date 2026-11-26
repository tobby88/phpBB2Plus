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

	public function can_play($game, $in_tournament = false)
	{
		return empty($this->user->data['is_bot']) && $game['game_avail'] && $game['asset_status'] === 'ready' &&
			(!empty($this->user->data['is_registered']) || $game['allow_guest']) &&
			(int) $this->user->data['user_posts'] >= (int) $this->setting('games_posts_required', 0) &&
			($in_tournament || $this->normal_play_allowed($game));
	}

	private function normal_play_allowed($game)
	{
		if (!(int) $this->setting('games_tournament_mode', 0)) { return true; }
		$p = $this->prefix;
		$member = empty($this->user->data['is_registered']) ? '' : " AND NOT EXISTS
			(SELECT 1 FROM {$p}tour_plays p WHERE p.tour_id=t.tour_id AND p.owner_id=" . (int) $this->user->data['user_id'] . ')';
		$result = $this->db->sql_query_limit("SELECT t.tour_id FROM {$p}tour_games d JOIN {$p}tours t ON t.tour_id=d.tour_id
			WHERE t.tour_active=2 AND t.block_plays=1 AND d.game_name='" . $this->db->sql_escape($game['game_name']) . "'" . $member, 1);
		$allowed = $this->db->sql_fetchrow($result) === false;
		$this->db->sql_freeresult($result);
		return $allowed;
	}

	public function staff()
	{
		$forum = array('m_edit','m_delete','m_lock','m_approve','m_move','m_split','m_merge');
		return !empty($this->user->data['is_registered']) && ($this->admin() || $this->auth->acl_get('m_plus_arcade')
			|| $this->auth->acl_gets($forum) || $this->auth->acl_getf_global($forum));
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

	public function game_filter($category = 0, $include_offline = false)
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
		return 'g.game_id>0 AND ' . ($include_offline ? '' : 'g.game_avail=1 AND ') . $cat_sql . ' AND ' . $this->db->sql_in_set('g.level_required', $levels) .
			' AND g.rank_required<=' . (int) $this->user->data['user_rank'] . ' AND ' . $this->db->sql_in_set('g.group_required', $this->groups());
	}

	public function category_overview($parent)
	{
		$ids = array();
		if (!$parent && $this->setting('games_show_all', 1)) { $ids[] = -1; }
		foreach ($this->categories() as $id => $category)
		{
			if ((int) $category['cat_parent'] === $parent) { $ids[] = $id; }
		}
		if (!$ids) { return array(); }
		$cats = $this->prefix . 'categories';
		$games = $this->prefix . 'games';
		// A snapshot stores a name, and may outlive a move or refer to a restricted or ambiguous game.
		// Historical counts are independent of that link and of the continued existence of the player.
		$sql = 'SELECT c.*,m.username AS mod_name,m.user_colour AS mod_colour,u.username AS player_name,u.user_colour AS player_colour,
			u.user_allow_viewonline,g.game_id,g.game_desc,g.game_name,g.game_avail FROM ' . $cats . ' c
			LEFT JOIN ' . USERS_TABLE . ' m ON m.user_id=c.moderator_id AND c.moderator_id>' . ANONYMOUS . '
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=c.last_user_id AND c.last_user_id>' . ANONYMOUS . '
			LEFT JOIN ' . $games . " g ON c.cat_type<>'l' AND c.last_time>0 AND g.game_name=c.last_game
				AND (c.last_user_id>" . ANONYMOUS . " OR c.last_player=-1 OR c.last_player>0)
				AND (c.cat_id=-1 OR c.cat_id=g.cat_id OR (c.cat_type='p' AND EXISTS
					(SELECT 1 FROM $cats child WHERE child.cat_id=g.cat_id AND child.cat_type='s' AND child.cat_parent=c.cat_id)))
				AND " . $this->unique_game_condition() . ' AND ' . $this->game_filter(0, true) . '
			WHERE ' . $this->db->sql_in_set('c.cat_id', $ids) . ' ORDER BY CASE WHEN c.cat_id=-1 THEN 0 ELSE 1 END,c.cat_order,c.cat_id';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function last_player($row, $language)
	{
		if ($row['player_name'] === null)
		{
			$former = (int) $row['last_player'] > 0 || (int) $row['last_user_id'] > ANONYMOUS;
			return $language->lang($former ? 'PLUS_ARC_FORMER' : 'GUEST');
		}
		if (!$row['user_allow_viewonline'] && (int) $row['last_user_id'] !== (int) $this->user->data['user_id'] && !$this->auth->acl_get('u_viewonline'))
		{ return $language->lang('PLUS_ARC_HIDDEN_PLAYER'); }
		return get_username_string('full', $row['last_user_id'], $row['player_name'], $row['player_colour']);
	}

	public static function category_target($category)
	{
		$url = self::plain($category['cat_desc']);
		if ($category['cat_type'] !== 'l' || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\\\\\x00-\x20\x7f]/', $url) ||
			!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), array('http','https'), true) ||
			parse_url($url, PHP_URL_USER) !== null) { return ''; }
		return $url;
	}

	public function visit_link($category, $count)
	{
		$url = self::category_target($category);
		if ($url === '') { throw new NotFoundHttpException(); }
		if ($count)
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . "categories SET total_played=CAST(COALESCE(NULLIF(total_played,''),'0') AS DECIMAL(20,0))+1
				WHERE cat_type='l' AND cat_id=" . (int) $category['cat_id'] . " AND group_required=" . (int) $category['group_required'] .
				" AND cat_desc='" . $this->db->sql_escape($category['cat_desc']) . "' AND CAST(COALESCE(NULLIF(total_played,''),'0') AS DECIMAL(20,0))<18446744073709551615");
		}
		return $url;
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

	public function directory_sorts($ratings)
	{
		$sorts = array('alphabetical' => "LOWER(COALESCE(NULLIF(g.game_desc,''),g.game_name))", 'date_added' => 'g.date_added',
			'game_played' => 'g.played', 'default' => 'g.game_id', 'allow_guest' => 'g.allow_guest',
			'game_instructions' => 'LOWER(g.instructions)', 'game_charge' => 'g.game_charge', 'game_bonus' => 'g.game_bonus');
		if ($ratings) { $sorts['rating'] = '(SELECT AVG(v.rate_point) FROM ' . $this->prefix . 'ratings v WHERE v.target_game=g.game_id)'; }
		if ($this->comment_limit())
		{
			$sorts['comments'] = 'CASE WHEN ' . $this->unique_game_condition() . ' THEN (SELECT COUNT(*) FROM ' .
				$this->prefix . 'comments c WHERE c.target_game=g.game_id) ELSE 0 END';
		}
		return $sorts;
	}

	private function chart_source($category)
	{
		return ' FROM ' . $this->prefix . 'games g WHERE g.game_avail=1 AND ' . $this->game_filter() .
			($category ? ' AND g.cat_id=' . (int) $category : '');
	}

	public function chart_games($chart, $limit, $category)
	{
		$order = array('popular' => 'g.played DESC,g.game_id', 'recent' => 'g.date_added DESC,g.game_id' . ($category ? ' DESC' : ''),
			'least' => 'g.played ASC,g.game_id');
		$result = $this->db->sql_query_limit('SELECT g.game_id,g.game_name,g.game_desc,g.allow_guest' . $this->chart_source($category) .
			' ORDER BY ' . $order[$chart], $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function enough_for_least_played($category, $threshold)
	{
		if ($threshold <= 0) { return false; }
		$result = $this->db->sql_query_limit('SELECT g.game_id' . $this->chart_source($category), 1, $threshold);
		$enough = $this->db->sql_fetchrow($result) !== false;
		$this->db->sql_freeresult($result);
		return $enough;
	}

	public function award_counts($source)
	{
		$result = $this->db->sql_query("SELECT place,COUNT(*) AS n FROM $source WHERE place>0 GROUP BY place");
		$counts = array_column($this->db->sql_fetchrowset($result), 'n', 'place');
		$this->db->sql_freeresult($result);
		return $counts;
	}

	public function title_filter($term, $type)
	{
		$title = "COALESCE(NULLIF(g.game_desc,''),g.game_name)";
		if ($type === 3) { return " AND SUBSTR($title,1,1) IN ('0','1','2','3','4','5','6','7','8','9')"; }
		if ($term === '') { return ''; }
		$any = $this->db->get_any_char();
		$prefix = $type === 1 ? '' : $any;
		$matches = array();
		// Converted captions can contain literal text or the original HTML entities.
		$terms = array();
		foreach (array_unique(array($term, utf8_htmlspecialchars($term))) as $plain)
		{
			$terms[] = $plain;
			$hex = utf8_encode_ucr($plain);
			if ($hex !== $plain)
			{
				$terms[] = $hex;
				$terms[] = preg_replace_callback('/&#x([a-f0-9]{5,6});/i', static function ($match) {
					return '&#' . hexdec($match[1]) . ';';
				}, $hex);
			}
		}
		foreach (array_unique($terms) as $text)
		{
			$literal = str_replace('\\', '\\\\', utf8_strtolower($text));
			$matches[] = "LOWER($title) " . $this->db->sql_like_expression($prefix . $literal . $any);
		}
		return ' AND (' . implode(' OR ', $matches) . ')';
	}

	public function scores_available($game)
	{
		return $game['game_show_score'] && $this->unique_name($game);
	}

	public function unique_name($game)
	{
		if ($game['game_name'] === null || $game['game_name'] === '') { return false; }
		// Historic results have a name, not a game ID. An ambiguous name cannot identify a restricted game.
		$result = $this->db->sql_query('SELECT COUNT(*) AS games FROM ' . $this->prefix . "games WHERE game_name='" . $this->db->sql_escape($game['game_name']) . "'");
		$unique = (int) $this->db->sql_fetchfield('games') === 1;
		$this->db->sql_freeresult($result);
		return $unique;
	}

	public function comment_limit($game = null)
	{
		if (empty($this->user->data['is_registered']) || !empty($this->user->data['is_bot']) ||
			(int) $this->setting('games_comments',0) !== 1 ||
			(int) $this->user->data['user_posts'] < (int) $this->setting('games_posts_required',0) ||
			(int) $this->user->data['user_rank'] < (int) $this->setting('games_rank_required',0)) { return 0; }
		$size = (int) $this->setting('games_comment_size',0);
		return $size > 0 && $size <= 65535 && ($game === null || $this->unique_name($game)) ? $size : 0;
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

	public function can_view_awards($member)
	{
		return (int) $member > ANONYMOUS && (int) $this->setting('games_show_stats',1) &&
			((int) $this->user->data['user_id'] === (int) $member || $this->auth->acl_get('u_viewprofile'));
	}

	private function unique_game_condition()
	{
		$games = $this->prefix . 'games';
		return "g.game_name<>'' AND NOT EXISTS
			(SELECT 1 FROM $games other WHERE other.game_name=g.game_name AND other.game_id<>g.game_id)";
	}

	/** Scores are keyed by name. Never attribute one name shared by several games to a visible category. */
	public function ranked_game_filter()
	{
		return $this->game_filter() . ' AND g.game_avail=1 AND g.game_show_score=1 AND ' . $this->unique_game_condition();
	}

	/** Rank only eligible games. Use the same score and tie order as the game's leaderboard. */
	public function award_query($member,$all_time)
	{
		$table = $this->prefix . ($all_time ? 'all_scores' : 'scores');
		$games = $this->prefix . 'games';
		$scope = $this->ranked_game_filter();
		if ($all_time) { $scope .= ' AND g.at_highscore_limit>0'; }
		$directions = array();
		foreach (array('DESC'=>'COALESCE(g.reverse_list,0)=0','ASC'=>'g.reverse_list<>0') as $direction=>$order_filter)
		{
			// Only the top three entries need consideration, including guests and retired accounts.
			$rank = 'CASE s.row_id';
			for ($offset=0; $offset<3; $offset++)
			{
				$rank .= " WHEN (SELECT r.row_id FROM $table r WHERE r.game_name=g.game_name
					ORDER BY r.score_units $direction,r.recorded_at,r.player_id,r.row_id LIMIT 1 OFFSET $offset) THEN " . ($offset+1);
			}
			$directions[] = "SELECT g.game_id,g.game_name,g.game_desc,s.row_id,s.score_units,s.recorded_at,s.time_taken,$rank ELSE 0 END AS place
				FROM $table s JOIN $games g ON g.game_name=s.game_name WHERE s.owner_id=" . (int) $member . " AND $order_filter AND $scope";
		}
		return '(' . implode(' UNION ALL ',$directions) . ') podium';
	}

	static public function plain($value) { return html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

	public static function player_name($row,$language,$hide_former)
	{
		if ($row['owner_id'] && $row['username'] !== null) { return get_username_string('full',$row['owner_id'],$row['username'],$row['user_colour']); }
		if ($row['author_removed'] && $hide_former) { return $language->lang('PLUS_ARC_FORMER'); }
		return $row['display_name'] !== null && $row['display_name'] !== '' ? utf8_htmlspecialchars(self::plain($row['display_name'])) : $language->lang('GUEST');
	}
}
