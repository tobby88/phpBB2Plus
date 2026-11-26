<?php
namespace phpbb2plus\community\profile;

class member_directory
{
	public const COMMON_SORTS = array('username' => 'USERNAME', 'user_email' => 'EMAIL_ADDRESS');
	public const MEMBER_SORTS = array('user_id' => 'PLUS_DIRECTORY_ID', 'user_posts' => 'POSTS', 'user_regdate' => 'JOINED',
		'user_lastvisit' => 'LAST_VISIT', 'user_active' => 'PLUS_DIRECTORY_ACTIVE');

	private $db;
	private $members;
	private $where;

	public function __construct($db, $members, $query, $letter)
	{
		$this->db = $db;
		$this->members = $members;
		$where = array('u.user_id > ' . ANONYMOUS, $db->sql_in_set('u.user_type', array(USER_NORMAL, USER_INACTIVE, USER_FOUNDER)));
		$any = $db->get_any_char();
		if ($query !== '')
		{
			// phpBB stores the display username and the underlying email address differently.
			$name = $db->sql_like_expression($any . htmlspecialchars($query, ENT_COMPAT, 'UTF-8') . $any);
			$email = $db->sql_like_expression($any . $query . $any);
			$where[] = "(u.username $name OR u.user_email $email)";
		}
		if ($letter === '0') { $where[] = "u.username NOT REGEXP '^[A-Za-z]'"; }
		elseif (preg_match('/^[A-Z]$/D', $letter)) { $where[] = 'u.username ' . $db->sql_like_expression($letter . $any); }
		$this->where = implode(' AND ', $where);
	}

	public function total()
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . USERS_TABLE . ' u WHERE ' . $this->where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $total;
	}

	public function page($sort, $ascending, $limit, $start)
	{
		$available = self::COMMON_SORTS + ($this->members ? self::MEMBER_SORTS : array());
		if (!isset($available[$sort])) { throw new \InvalidArgumentException('Invalid directory sort'); }
		$dir = $ascending ? ' ASC' : ' DESC';
		$select = 'u.user_id, u.username, u.user_email, u.user_type';
		if ($this->members) { $select .= ', u.user_posts, u.user_colour, u.user_regdate, u.user_lastvisit'; }
		$order = $sort === 'user_active' ? 'CASE WHEN u.user_type = ' . USER_INACTIVE . ' THEN 0 ELSE 1 END' : 'u.' . $sort;
		$result = $this->db->sql_query_limit("SELECT $select FROM " . USERS_TABLE . ' u WHERE ' . $this->where .
			' ORDER BY ' . $order . $dir . ($sort !== 'user_id' ? ', u.user_id' . $dir : ''), $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function account_bans(array $ids)
	{
		if (!$this->members || !$ids) { return array(); }
		// An IP/email rule is not an account ban. An active account exclusion wins.
		$result = $this->db->sql_query('SELECT ban_userid, MAX(ban_exclude) AS excluded FROM ' . BANLIST_TABLE .
			' WHERE ' . $this->db->sql_in_set('ban_userid', array_map('intval', $ids)) .
			' AND (ban_end = 0 OR ban_end >= ' . time() . ') GROUP BY ban_userid');
		$bans = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			if (!(int) $row['excluded']) { $bans[(int) $row['ban_userid']] = true; }
		}
		$this->db->sql_freeresult($result);
		return $bans;
	}
}
