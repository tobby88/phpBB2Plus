<?php
namespace phpbb2plus\community\service;

class former_members
{
	private $db;
	private $config;
	private $user;
	private $language;
	private $table;
	private $found = array();
	private $searches = array();

	public function __construct($db, $config, $user, $language, $table)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->language = $language;
		$this->table = $table;
	}

	public function hidden()
	{
		return $this->config['plus_hide_former_names'] && empty($this->user->data['is_registered']);
	}

	public function label()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		return $this->language->lang('PLUS_FORMER_MEMBER');
	}

	public static function clean($name)
	{
		return utf8_clean_string(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'));
	}

	private function exists($conditions)
	{
		$result = $this->db->sql_query_limit('SELECT n.name_hash FROM ' . $this->table . ' n WHERE (' . $conditions . ')
			AND (n.native_id=0 OR NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id=n.native_id))', 1);
		$exists = $this->db->sql_fetchfield('name_hash') !== false;
		$this->db->sql_freeresult($result);
		return $exists;
	}

	public function known($name, $user_id = 0)
	{
		$key = hash('sha256', self::clean($name));
		$user_id = (int) $user_id;
		$index = $user_id . ':' . $key;
		if (!isset($this->found[$index]))
		{
			$where = "n.name_key='$key'";
			if ($user_id > ANONYMOUS)
			{
				// An active account can legitimately reuse a former display name.
				$where = "n.native_id=$user_id OR ($where AND NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " current_user_row WHERE current_user_row.user_id=$user_id))";
			}
			$this->found[$index] = $this->exists($where);
		}
		return $this->found[$index];
	}

	public function matches_search($keywords)
	{
		$keywords = trim(html_entity_decode((string) $keywords, ENT_QUOTES, 'UTF-8'));
		if ($keywords === '') { return false; }
		if (isset($this->searches[$keywords])) { return $this->searches[$keywords]; }
		if (strlen($keywords) > 2048) { return true; }
		$terms = preg_split('/[\s|(),"]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY);
		if ($terms === false || count($terms) > 64) { return true; }
		$conditions = array();
		foreach (array_unique(array_merge(array(trim($keywords, '"')), $terms)) as $term)
		{
			$term = ltrim($term, '+-');
			if ($term === '' || in_array(strtoupper($term), array('AND', 'OR', 'NOT'), true)) { continue; }
			if (strpos($term, '*') === false)
			{
				$conditions[] = "n.name_key='" . hash('sha256', self::clean($term)) . "'";
			}
			else
			{
				$parts = array_map(array(__CLASS__, 'clean'), explode('*', $term));
				$conditions[] = 'n.name_clean ' . $this->db->sql_like_expression(implode($this->db->get_any_char(), $parts));
			}
		}
		return $this->searches[$keywords] = $conditions && $this->exists(implode(' OR ', $conditions));
	}
}
