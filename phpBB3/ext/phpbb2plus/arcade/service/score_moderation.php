<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class score_moderation
{
	private $db;
	private $auth;
	private $user;
	private $prefix;
	private $sessions;
	private $log;

	public function __construct($db, $auth, $user, $prefix, $sessions, $log)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->prefix = $prefix;
		$this->sessions = $sessions;
		$this->log = $log;
	}

	private function rows($sql, $limit = 0, $start = 0)
	{
		$r = $limit ? $this->db->sql_query_limit($sql, $limit, $start) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		return $rows;
	}

	private function actor($panel, $lock = false)
	{
		$data = $this->user->data;
		if (empty($data['is_registered']) || !empty($data['is_bot']) || ($panel && (!defined('IN_ADMIN') || !IN_ADMIN))) { return false; }
		$auth = $this->auth;
		if ($lock)
		{
			$sid = $this->db->sql_escape($this->user->session_id);
			$rows = $this->rows('SELECT u.*,s.session_admin FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
				" s ON s.session_user_id=u.user_id WHERE s.session_id='$sid' AND u.user_id=" . (int) $data['user_id'] . ' FOR UPDATE');
			if (!$rows) { return false; }
			$data = $rows[0];
		}
		if (!empty($data['user_perm_from']) || !in_array((int) $data['user_type'], array(USER_NORMAL, USER_FOUNDER), true) ||
			($panel && empty($data['session_admin']))) { return false; }
		if ($lock)
		{
			$data['is_registered'] = true;
			$data['is_bot'] = false;
			$auth = clone $auth;
			$auth->acl($data);
		}
		$admin = $auth->acl_get('a_plus_arc_scores') && ($panel || $auth->acl_get('a_plus_arcade'));
		if ($panel && !$admin) { return false; }
		return array('id' => (int) $data['user_id'], 'admin' => $admin, 'all_categories' => $admin || $auth->acl_get('m_plus_arcade'));
	}

	public function categories($panel = false, $require_access = false)
	{
		$actor = $this->actor($panel);
		if (!$actor)
		{
			if ($require_access) { throw new NotFoundHttpException(); }
			return array();
		}
		$where = $actor['all_categories'] ? '' : ' AND moderator_id=' . $actor['id'];
		$list = $this->rows('SELECT cat_id,cat_name FROM ' . $this->prefix . "categories WHERE cat_id>0 AND cat_type<>'l'$where ORDER BY cat_order,cat_name,cat_id");
		$categories = array_column($list, null, 'cat_id');
		if ($require_access && !$categories && !$actor['all_categories']) { throw new NotFoundHttpException(); }
		if ($actor['admin'])
		{
			foreach ($this->rows('SELECT DISTINCT COALESCE(cat_id,0) AS cat_id FROM ' . $this->prefix . 'games WHERE cat_id IS NULL OR cat_id IN (0,-1) ORDER BY cat_id') as $row)
			{
				$categories[(int) $row['cat_id']] = $row + array('cat_name' => '');
			}
		}
		return $categories;
	}

	public function category($id, $panel, $lock = false)
	{
		$actor = $this->actor($panel, $lock);
		if (!$actor || $id < -1) { throw new NotFoundHttpException(); }
		if ($id === 0 || $id === -1)
		{
			if (!$actor['admin']) { throw new NotFoundHttpException(); }
			return array('cat_id' => $id, 'cat_name' => '');
		}
		$rows = $this->rows('SELECT cat_id,cat_name,moderator_id FROM ' . $this->prefix . "categories WHERE cat_type<>'l' AND cat_id=" . (int) $id .
			($lock ? ' FOR UPDATE' : ''));
		if (!$rows || (!$actor['all_categories'] && (int) $rows[0]['moderator_id'] !== $actor['id'])) { throw new NotFoundHttpException(); }
		return $rows[0];
	}

	private function category_filter($category)
	{
		return $category === 0 ? '(g.cat_id=0 OR g.cat_id IS NULL)' : 'g.cat_id=' . (int) $category;
	}

	public function games($category, $limit, $start)
	{
		$from = ' FROM ' . $this->prefix . 'games g WHERE ' . $this->category_filter($category);
		$total = (int) $this->rows('SELECT COUNT(*) AS n' . $from)[0]['n'];
		$start = self::offset($start, $limit, $total);
		$rows = $this->rows('SELECT g.game_id,g.game_name,g.game_desc,g.game_path,g.image_path,g.instructions,g.win_width,g.win_height,g.game_autosize,g.game_avail,g.icon_storage' .
			$from . ' ORDER BY g.game_name,g.game_id', $limit, $start);
		return array($rows, $total, $start);
	}

	public function game($category, $id, $lock = false)
	{
		$games = $this->prefix . 'games';
		$rows = $this->rows("SELECT g.* FROM $games g WHERE g.game_id=" . (int) $id . ' AND ' . $this->category_filter($category) . ($lock ? ' FOR UPDATE' : ''));
		if (!$rows) { throw new NotFoundHttpException(); }
		return $rows[0];
	}

	private function name_filter($game, $lock = false)
	{
		$name = $this->db->sql_escape((string) $game['game_name']);
		// Imported scores have only a game name. Its identity must still be unique when a change commits.
		$sql = 'SELECT game_id FROM ' . $this->prefix . "games WHERE game_name='$name'" . ($lock ? ' FOR UPDATE' : '');
		if ($name === '' || count($this->rows($sql)) !== 1) { throw new \DomainException('PLUS_ARC_MOD_AMBIGUOUS'); }
		return "s.game_name='$name'";
	}

	private function table($board)
	{
		if (!in_array($board, array('current','all'), true)) { throw new NotFoundHttpException(); }
		return $this->prefix . ($board === 'all' ? 'all_scores' : 'scores');
	}

	private static function offset($start, $size, $total)
	{
		return min(intdiv(max(0, (int) $start), $size), intdiv(max(0, $total - 1), $size)) * $size;
	}

	public function entries($game, $board, $limit, $start)
	{
		$from = ' FROM ' . $this->table($board) . ' s';
		$where = ' WHERE ' . $this->name_filter($game);
		$total = (int) $this->rows('SELECT COUNT(*) AS n' . $from . $where)[0]['n'];
		$start = self::offset($start, $limit, $total);
		$name = $board === 'all' ? "COALESCE(NULLIF(s.player_name,''),s.player)" : 's.player';
		$sql = "SELECT s.row_id,s.owner_id,s.author_removed,$name AS display_name,u.username,u.user_colour,s.score_units,s.recorded_at,s.time_taken,s.player_ip" .
			$from . ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=s.owner_id AND s.owner_id>0' . $where .
			' ORDER BY s.score_units ' . ($game['reverse_list'] ? 'ASC' : 'DESC') . ',s.recorded_at,s.player_id,s.row_id';
		return array($this->rows($sql, $limit, $start), $total, $start);
	}

	public function entry($game, $board, $id, $lock = false)
	{
		$where = $this->name_filter($game, $lock) . ' AND s.row_id=' . (int) $id;
		$rows = $this->rows('SELECT s.* FROM ' . $this->table($board) . ' s WHERE ' . $where . ($lock ? ' FOR UPDATE' : ''));
		if (!$rows) { throw new \DomainException('PLUS_ARC_SCORE_CHANGED'); }
		return $rows[0];
	}

	public static function revision($game, $board, $row)
	{
		$identity = array((string) $game['game_id'], (string) $game['cat_id'], $game['game_name'], $game['game_desc'], (string) $game['reverse_list'], $board);
		ksort($row);
		foreach ($row as $value) { $identity[] = $value === null ? null : (string) $value; }
		return hash('sha256', serialize($identity));
	}

	public function entry_player($row, $board)
	{
		$users = $row['owner_id'] ? $this->rows('SELECT username,user_colour FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $row['owner_id']) : array();
		$row['display_name'] = $board === 'all' && isset($row['player_name']) && $row['player_name'] !== '' ? $row['player_name'] : $row['player'];
		return $row + ($users[0] ?? array('username' => null, 'user_colour' => ''));
	}

	// An older board can keep a player's only save inside a score. Preserve the effective save before changing its ranking.
	private function keep_progress($game, $owner)
	{
		if ($owner <= ANONYMOUS) { return; }
		$game_id = (int) $game['game_id'];
		$table = $this->prefix . 'saves';
		if ($this->rows("SELECT row_id FROM $table WHERE game_id=$game_id AND owner_id=$owner", 1)) { return; }
		$saved = saved_game::from_score($this->db, $this->prefix, $game, $owner);
		if (!$saved) { return; }
		$values = array('game_id' => $game_id, 'user_id' => (int) $saved['player_id'], 'owner_id' => $owner,
			'game_data' => $saved['game_data'], 'save_encoded' => $saved['save_encoded'], 'saved_at' => (string) (int) $saved['recorded_at']);
		$this->db->sql_query('INSERT INTO ' . $table . ' ' . $this->db->sql_build_array('INSERT', $values));
	}

	public function change($category, $id, $board, $row_id, $revision, $action, $value, $panel)
	{
		if ($action !== 'delete' && !($panel && $action === 'edit')) { throw new NotFoundHttpException(); }
		$this->sessions->locked(function () use ($category, $id, $board, $row_id, $revision, $action, $value, $panel) {
			$this->category($category, $panel, true);
			$game = $this->game($category, $id, true);
			$row = $this->entry($game, $board, $row_id, true);
			if (!is_string($revision) || !hash_equals(self::revision($game, $board, $row), $revision)) { throw new \DomainException('PLUS_ARC_SCORE_CHANGED'); }
			$table = $this->table($board);
			$where = ' WHERE row_id=' . (int) $row['row_id'];
			if ($action === 'edit')
			{
				$units = score::from_wire(str_replace(',', '.', $value));
				if ($row['score_units'] !== null && (string) $row['score_units'] === $units) { return; }
				$sql = 'UPDATE ' . $table . ' SET ' . $this->db->sql_build_array('UPDATE', array('score_units' => $units)) . $where;
			}
			else { $sql = 'DELETE FROM ' . $table . $where; }
			if ($board === 'current') { $this->keep_progress($game, (int) $row['owner_id']); }
			$this->db->sql_query($sql);
			if ((int) $this->db->sql_affectedrows() !== 1) { throw new \DomainException('PLUS_ARC_SCORE_CHANGED'); }
			score::refresh_winner($this->db, $this->prefix, $game, $board === 'all');
			$this->log->add($panel ? 'admin' : 'mod', (int) $this->user->data['user_id'], $this->user->ip,
				'LOG_PLUS_ARC_SCORE_' . strtoupper($action) . '_' . strtoupper($board), false, array((int) $row_id, (int) $id));
		});
	}
}
