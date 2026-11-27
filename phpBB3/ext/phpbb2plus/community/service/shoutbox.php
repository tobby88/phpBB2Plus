<?php
namespace phpbb2plus\community\service;

class shoutbox
{
	private const PORTAL_PUBLIC = 1, PORTAL_MEMBERS = 2;
	private const BOTH_PUBLIC = 3, BOTH_MEMBERS = 4;
	private const INDEX_PUBLIC = 5, INDEX_MEMBERS = 6;

	private $db;
	private $config;
	private $user;
	private $auth;
	private $language;
	private $table;

	public function __construct($db, $config, $user, $auth, $language, $table)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->language = $language;
		$this->table = $table;
	}

	public function may_post()
	{
		return !empty($this->user->data['is_registered']) && $this->auth->acl_get('u_plus_shout');
	}

	/** Keep the portal-only choices used by earlier phpBB3 installations at 1 and 2. */
	public static function converted_placement($legacy)
	{
		$placements = array(1 => self::BOTH_PUBLIC, 2 => self::BOTH_MEMBERS, 3 => self::PORTAL_PUBLIC,
			4 => self::PORTAL_MEMBERS, 5 => self::INDEX_PUBLIC, 6 => self::INDEX_MEMBERS);
		return $placements[trim((string) $legacy)] ?? 0;
	}

	public function panel_visible($on_index)
	{
		$mode = (int) $this->config['plus_portal_shouts'];
		$public = $on_index ? self::INDEX_PUBLIC : self::PORTAL_PUBLIC;
		$members = $on_index ? self::INDEX_MEMBERS : self::PORTAL_MEMBERS;
		return $mode === self::BOTH_PUBLIC || $mode === $public
			|| (($mode === self::BOTH_MEMBERS || $mode === $members) && $this->user->data['is_registered']);
	}

	public function may_moderate()
	{
		return !empty($this->user->data['is_registered']) && $this->auth->acl_get('m_plus_shout');
	}

	public function may_change($row)
	{
		return $row && ($this->may_moderate() || ($this->may_post() && !(int) $row['hidden_by']
			&& (int) $row['author_id'] === (int) $this->user->data['user_id']));
	}

	public function find($id)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table . ' WHERE shout_id=' . (int) $id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function count()
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $this->table);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $count;
	}

	public function listing($limit, $offset = 0)
	{
		$result = $this->db->sql_query_limit('SELECT s.*, u.username, u.user_colour
			FROM ' . $this->table . ' s LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=s.author_id
			ORDER BY s.shout_time DESC, s.shout_id DESC', $limit, $offset);
		$items = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id = (int) $row['author_id'];
			if ($id <= ANONYMOUS || $row['username'] === null)
			{
				$masked = $this->config['plus_hide_former_names'] && empty($this->user->data['is_registered'])
					&& ($row['author_removed'] || $id > ANONYMOUS);
				$author = $masked ? $this->language->lang('PLUS_FORMER_MEMBER') : ($row['author_name'] ?: $this->language->lang('GUEST'));
			}
			else
			{
				$author = get_username_string('full', $id, $row['username'], $row['user_colour']);
			}
			$items[] = array(
				'ID' => (int) $row['shout_id'], 'AUTHOR' => $author, 'TIME' => $this->user->format_date($row['shout_time']),
				'MESSAGE' => $row['hidden_by'] ? $this->language->lang('PLUS_SHOUT_HIDDEN') :
					generate_text_for_display($row['message'], $row['bbcode_uid'], $row['bbcode_bitfield'], $row['bbcode_options']),
				'S_HIDDEN' => (bool) $row['hidden_by'], 'S_CHANGE' => $this->may_change($row),
			);
		}
		$this->db->sql_freeresult($result);
		return $items;
	}

	/** Guard ownership and moderation state again on the write for concurrent requests. */
	private function change_where($id)
	{
		$where = 'shout_id=' . (int) $id;
		if (!$this->may_moderate())
		{
			$where .= ' AND author_id=' . (int) $this->user->data['user_id'] . ' AND hidden_by=0';
		}
		return $where;
	}

	public function bbcode_enabled()
	{
		return (bool) $this->config['allow_bbcode'];
	}

	public function store($text, $id = 0)
	{
		if ($id ? !$this->may_change($this->find($id)) : !$this->may_post()) { return 'NOT_AUTHORISED'; }
		if ($text === '' || strlen($text) > 16000) { return 'PLUS_SHOUT_LENGTH'; }
		$uid = $bitfield = '';
		$flags = 0;
		generate_text_for_storage($text, $uid, $bitfield, $flags, $this->bbcode_enabled(),
			(bool) $this->config['allow_post_links'], (bool) $this->config['allow_smilies'], false, false);
		$changes = array('message' => $text, 'bbcode_uid' => $uid, 'bbcode_bitfield' => $bitfield, 'bbcode_options' => $flags);
		if ($id)
		{
			$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $changes)
				. ' WHERE ' . $this->change_where($id));
			return '';
		}
		$now = time();
		$user_id = (int) $this->user->data['user_id'];
		$interval = $this->auth->acl_get('u_ignoreflood') ? 1 : max(1, (int) $this->config['flood_interval']);
		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_last_shout=' . $now
				. ' WHERE user_id=' . $user_id . ' AND plus_last_shout <= ' . ($now - $interval));
			if (!$this->db->sql_affectedrows())
			{
				$this->db->sql_transaction('rollback');
				return 'FLOOD_ERROR';
			}
			$changes += array('author_id' => $user_id, 'author_name' => $this->user->data['username'],
				'shout_time' => $now, 'author_ip' => $this->user->ip);
			$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $changes));
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			throw $error;
		}
		return '';
	}

	public function moderate($id, $action)
	{
		$row = $this->find($id);
		if (!$this->may_change($row)) { return false; }
		if ($action === 'delete')
		{
			$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE ' . $this->change_where($id));
		}
		else if ($this->may_moderate() && in_array($action, array('hide', 'restore'), true))
		{
			$hidden_by = $action === 'hide' ? (int) $this->user->data['user_id'] : 0;
			$this->db->sql_query('UPDATE ' . $this->table . ' SET hidden_by=' . $hidden_by . ' WHERE shout_id=' . (int) $id);
		}
		else { return false; }
		return true;
	}
}
