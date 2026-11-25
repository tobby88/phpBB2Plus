<?php
namespace phpbb2plus\banners\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class catalog
{
	private $db;
	private $config;
	private $auth;
	private $user;
	private $request;
	private $language;
	private $prefix;
	private $root;
	private $forum = 0;
	private $pool;
	private $skip = false;
	private $counted = array();
	private $open_forums = array();
	private $now;

	public function __construct($db, $config, $auth, $user, $request, $language, $prefix, $root)
	{
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->request = $request;
		$this->language = $language;
		$this->prefix = $prefix;
		$this->root = $root;
	}

	public function context($forum)
	{
		if ($this->forum !== (int) $forum) { $this->forum = (int) $forum; $this->pool = null; }
	}

	public function skip_display() { $this->skip = true; }

	public static function external($value)
	{
		$url = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
		if ($url === '' || strlen($url) > 2048 || preg_match('~[\\\\\x00-\x20\x7f<>"\x27\x60]~', $url)) { return ''; }
		$parts = parse_url($url);
		if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
			!in_array(strtolower($parts['scheme'] ?? ''), array('http','https'), true) || !filter_var($url, FILTER_VALIDATE_URL)) { return ''; }
		return $url;
	}

	public static function scheduled($row, \DateTimeImmutable $clock)
	{
		$mode = (int) $row['time_mode'];
		if ($mode === 0) { return true; }
		$begin = (int) $row['time_begin']; $end = (int) $row['time_end'];
		if (min($begin,$end) < 0 || max($begin,$end) > 2359 || $begin % 100 > 59 || $end % 100 > 59) { return false; }
		$hour = (int) $clock->format('Hi');
		$inside = $begin <= $end ? $hour >= $begin && $hour <= $end : $hour >= $begin || $hour <= $end;
		if (!$inside || $mode === 2) { return $inside; }
		$first = (int) $row['date_begin']; $last = (int) $row['date_end'];
		if ($mode === 6) { $date = (int) $clock->format('Ymd'); return $date >= $first && $date <= $last; }
		if ($mode !== 4 || min($first,$last) < 0 || max($first,$last) > 6) { return false; }
		$weekday = (int) $clock->format('w');
		return $first <= $last ? $weekday >= $first && $weekday <= $last : $weekday >= $first || $weekday <= $last;
	}

	private function forum_open($row)
	{
		$id = (int) $row['forum_id'];
		if (!$id) { return true; }
		if (!$row['existing_forum'] || !$this->auth->acl_get('f_list', $id) || !$this->auth->acl_get('f_read', $id)) { return false; }
		if ($row['forum_password'] === '') { return true; }
		if (!isset($this->open_forums[$id]))
		{
			$r = $this->db->sql_query_limit('SELECT forum_id FROM ' . FORUMS_ACCESS_TABLE . " WHERE forum_id=$id AND user_id=" .
				(int) $this->user->data['user_id'] . " AND session_id='" . $this->db->sql_escape($this->user->session_id) . "'", 1);
			$this->open_forums[$id] = $this->db->sql_fetchfield('forum_id') !== false;
			$this->db->sql_freeresult($r);
		}
		return $this->open_forums[$id];
	}

	private function eligible($row)
	{
		if (!$row || !$row['active'] || !self::external($row['target_url']) || !$this->forum_open($row)) { return false; }
		if (!$this->now) { $this->now = new \DateTimeImmutable('now', new \DateTimeZone($this->config['board_timezone'])); }
		if (!self::scheduled($row, $this->now)) { return false; }
		$level = -1;
		if (!empty($this->user->data['is_registered']))
		{
			$level = 0;
			if ((int) $this->user->data['user_type'] === USER_FOUNDER || $this->auth->acl_get('a_board')) { $level = 2; }
			elseif ($this->auth->acl_getf_global('m_edit') || $this->auth->acl_getf_global('m_delete') || $this->auth->acl_getf_global('m_approve')) { $level = 1; }
		}
		$threshold = (int) $row['audience'];
		switch ((int) $row['audience_mode'])
		{
			case 0: return $level === $threshold;
			case 1: return $level <= $threshold;
			case 2: return $level >= $threshold;
			case 3: return $level !== $threshold;
			default: return false;
		}
	}

	private function select()
	{
		return 'SELECT b.*,f.forum_id AS existing_forum,f.forum_password FROM ' . $this->prefix . 's b LEFT JOIN ' .
			FORUMS_TABLE . ' f ON f.forum_id=b.forum_id';
	}

	public function item($id, $lock = false)
	{
		if (!$this->config['plus_banners_enabled']) { throw new NotFoundHttpException(); }
		if ($lock)
		{
			// Different banners in the same forum can be clicked independently.
			$r = $this->db->sql_query('SELECT banner_id FROM ' . $this->prefix . 's WHERE banner_id=' . (int) $id . ' FOR UPDATE');
			$this->db->sql_freeresult($r);
		}
		$r = $this->db->sql_query($this->select() . ' WHERE b.banner_id=' . (int) $id);
		$row = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		if (!$this->eligible($row)) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function filtered($id)
	{
		return $this->request->variable($this->config['cookie_name'] . '_plus_b_' . (int) $id, '', false, \phpbb\request\request_interface::COOKIE) === '1';
	}

	private function choose()
	{
		$this->pool = array();
		$r = $this->db->sql_query($this->select() . ' WHERE b.active=1 AND ' .
			$this->db->sql_in_set('b.forum_id', array_unique(array(0, $this->forum))) . ' ORDER BY b.banner_id');
		$weights = array();
		while ($b = $this->db->sql_fetchrow($r))
		{
			if (!$this->eligible($b) || ($b['filter_clicks'] && $this->filtered($b['banner_id']))) { continue; }
			$spot = (int) $b['spot'];
			$score = (int) $b['weight'] * random_int(0,99);
			if (isset($weights[$spot]) && $weights[$spot] >= $score) { continue; }
			$weights[$spot] = $score;
			$this->pool[$spot] = $b;
		}
		$this->db->sql_freeresult($r);
	}

	public function slots($first, $last)
	{
		if ($this->skip || !$this->config['plus_banners_enabled']) { return array(); }
		if ($this->pool === null) { $this->choose(); }
		$rows = $views = array();
		for ($spot = (int) $first; $spot <= (int) $last; $spot++)
		{
			if (!isset($this->pool[$spot])) { continue; }
			$b = $this->pool[$spot];
			$id = (int) $b['banner_id'];
			$kind = (int) $b['media_type'];
			$remote = self::external($b['remote_asset']);
			$media = $b['asset_name'] !== '' || $remote !== '';
			$description = html_entity_decode($b['description'], ENT_QUOTES, 'UTF-8');
			$rows[] = array('id'=>$id, 'spot'=>$spot, 'text'=>$kind === 2 ? html_entity_decode($b['display_name'], ENT_QUOTES, 'UTF-8') : $description,
				'description'=>$description, 'remote'=>$remote, 'image'=>$kind !== 2 && $kind !== 6 && $media, 'movie'=>$kind === 6 && $media,
				'width'=>max(0,min(4096,(int) $b['width'])), 'height'=>max(0,min(4096,(int) $b['height'])));
			if (!isset($this->counted[$id])) { $views[] = $id; $this->counted[$id] = true; }
		}
		if ($views && $this->request->server('REQUEST_METHOD') === 'GET')
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 's SET views=LEAST(18446744073709551615,views+1) WHERE ' . $this->db->sql_in_set('banner_id', $views));
		}
		if ($rows) { $this->language->add_lang('banners', 'phpbb2plus/banners'); }
		return $rows;
	}

	public function media($id)
	{
		$b = $this->item($id);
		$uploads = $this->root . trim($this->config['upload_path'], '/');
		if (strpos($b['asset_name'],'library/') === 0)
		{
			$name = substr($b['asset_name'],8);
			$art = artwork::inspect($uploads,$name);
			if (!$art || $art['asset_mime'] !== $b['asset_mime']) { throw new NotFoundHttpException(); }
			return array($uploads . '/plus_banner_artwork/' . $name, $art['asset_mime']);
		}
		if (!preg_match('/^banner_[a-f0-9]{64}$/D', $b['asset_name'])) { throw new NotFoundHttpException(); }
		$path = $uploads . '/' . $b['asset_name'];
		if (is_link($path) || !is_file($path) || (string) filesize($path) !== (string) $b['asset_bytes']) { throw new NotFoundHttpException(); }
		return array($path, $b['asset_mime']);
	}
}
