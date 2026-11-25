<?php
namespace phpbb2plus\community\service;

class statistics_installation
{
	private $db;
	private $cache;
	private $config;
	private $language;
	private $user;
	private $root;
	private $ext;
	private $prefix;

	public function __construct($db, $cache, $config, $language, $user, $root, $ext, $prefix)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->config = $config;
		$this->language = $language;
		$this->user = $user;
		$this->root = $root;
		$this->ext = $ext;
		$this->prefix = $prefix;
	}

	private function avatar_bytes()
	{
		$base = realpath($this->root);
		$path = realpath($this->root . $this->config['avatar_path']);
		if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_dir($path)) { return null; }
		$bytes = 0;
		try
		{
			foreach (new \DirectoryIterator($path) as $file)
			{
				if (!$file->isLink() && $file->isFile()) { $bytes += $file->getSize(); }
			}
		}
		catch (\RuntimeException $exception) { return null; }
		return $bytes;
	}

	private function size_key() { return '_plus_stats_sizes_' . md5($this->prefix . '/' . $this->config['avatar_path']); }

	public function clear() { $this->cache->destroy($this->size_key()); }

	private function sizes()
	{
		// Only cache board-wide numbers. No profile data or viewer-specific values belong here.
		$ttl = (int) ($this->config['plus_statistics_storage_ttl'] ?? 600);
		$key = $this->size_key();
		$sizes = $ttl ? $this->cache->get($key) : false;
		if ($sizes !== false) { return $sizes; }
		$sizes = array('database' => null, 'avatars' => $this->avatar_bytes());
		if ($this->db->get_sql_layer() === 'mysqli')
		{
			// Several boards can share one database; keep the original board-prefix limit.
			$result = $this->db->sql_query("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS amount FROM information_schema.TABLES
				WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND LEFT(TABLE_NAME," . strlen($this->prefix) . ")='" .
				$this->db->sql_escape($this->prefix) . "'");
			$value = $this->db->sql_fetchfield('amount');
			$this->db->sql_freeresult($result);
			if (is_numeric($value)) { $sizes['database'] = (int) $value; }
		}
		if ($ttl) { $this->cache->put($key, $sizes, $ttl); }
		return $sizes;
	}

	public function rows($profiles)
	{
		$this->language->add_lang('acp/common');
		$na = $this->language->lang('NOT_AVAILABLE');
		$config = $this->config;
		$started = (int) $config['board_startdate'];
		$days = $started > 0 ? max(1, (int) round((time() - $started) / 86400)) : null;
		$sizes = $this->sizes();
		$values = array();
		foreach (array('POSTS' => 'num_posts', 'TOPICS' => 'num_topics', 'USERS' => 'num_users') as $label => $key)
		{
			$total = (int) $config[$key];
			$values['NUMBER_' . $label] = $total;
			$values[$label . '_PER_DAY'] = $days === null ? $na : number_format($total / $days, 2, $this->language->lang('PLUS_STATS_DECIMAL'), '');
		}
		$values['BOARD_STARTED'] = $started > 0 ? $this->user->format_date($started) : $na;
		$values['PLUS_STATS_DAYS'] = $days ?? $na;
		$values['DATABASE_SIZE'] = $sizes['database'] === null ? $na : get_formatted_filesize($sizes['database']);
		$values['AVATAR_DIR_SIZE'] = $sizes['avatars'] === null ? $na : get_formatted_filesize($sizes['avatars']);
		$profile_link = '';
		if ($profiles)
		{
			$result = $this->db->sql_query('SELECT user_id,username,user_regdate FROM ' . USERS_TABLE . ' WHERE user_id=' .
				(int) $config['newest_user_id'] . ' AND user_id<>' . ANONYMOUS . ' AND user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ')');
			$member = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($member)
			{
				$values['PLUS_STATS_LATEST_DATE'] = $member['user_regdate'] ? $this->user->format_date($member['user_regdate']) : $na;
				$values['PLUS_STATS_LATEST_MEMBER'] = htmlspecialchars_decode($member['username'], ENT_QUOTES);
				$profile_link = append_sid($this->root . 'memberlist.' . $this->ext, 'mode=viewprofile&u=' . (int) $member['user_id'], false);
			}
		}
		$values['PLUS_STATS_ONLINE_DATE'] = $config['record_online_date'] ? $this->user->format_date($config['record_online_date']) : $na;
		$values['PLUS_STATS_ONLINE_RECORD'] = $config['record_online_users'] ? (int) $config['record_online_users'] : $na;
		$values['GZIP_COMPRESSION'] = $this->language->lang($config['gzip_compress'] && extension_loaded('zlib') ? 'ENABLED' : 'DISABLED');
		// The native converter resynchronises this same aggregate used by phpBB's ACP.
		$values['UPLOAD_DIR_SIZE'] = get_formatted_filesize($config['upload_dir_size']);
		$rows = array();
		foreach ($values as $key => $value)
		{
			$rows[] = array('LABEL' => $this->language->lang($key), 'VALUE' => $value, 'URL' => $key === 'PLUS_STATS_LATEST_MEMBER' ? $profile_link : '');
		}
		return $rows;
	}
}
