<?php
namespace phpbb2plus\knowledgebase\service;

class settings
{
	const SECTIONS = array(
		'submission' => array('allow_new' => array('bool','1'), 'allow_anon' => array('bool','0'), 'approve_new' => array('bool','1'),
			'allow_edit' => array('bool','1'), 'approve_edit' => array('bool','1'), 'show_pretext' => array('bool','0'),
			'pt_header' => array('text','',100), 'pt_body' => array('memo','',255)),
		'discussion' => array('comments' => array('bool','1'), 'forum_id' => array('forum','0'),
			'bump_post' => array('bool','1'), 'del_topic' => array('bool','1')),
		'notices' => array('notify' => array('notify','0'), 'admin_id' => array('user','0')),
		'display' => array('stats_list' => array('bool','1'), 'header_banner' => array('bool','1'), 'art_pagination' => array('page_size','5'),
			'news_sort' => array('choice','Alphabetic',array('Alphabetic','Creation','Id','Latest','Userrank')),
			'news_sort_par' => array('choice','ASC',array('ASC','DESC'))),
		'ratings' => array('allow_rating' => array('bool','0'), 'allow_anonymos_rating' => array('bool','0'),
			'votes_check_userid' => array('bool','1'), 'votes_check_ip' => array('bool','1')),
	);
	private $articles;
	private $editor;
	private $db;
	private $auth;
	private $user;
	private $table;

	public function __construct($articles, $editor, $db, $auth, $user, $table)
	{
		$this->articles = $articles;
		$this->editor = $editor;
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->table = $table;
	}

	public function values($section)
	{
		$values = array();
		foreach (self::SECTIONS[$section] as $name => $field) { $values[$name] = $this->articles->setting($name, $field[1]); }
		return $values;
	}

	public static function revision($values) { return hash('sha256', serialize($values)); }

	public function forums()
	{
		$visible = array_diff(array_intersect(array_keys($this->auth->acl_getf('f_list', true)), array_keys($this->auth->acl_getf('f_read', true))),
			$this->user->get_passworded_forums());
		if (!$visible) { return array(); }
		$result = $this->db->sql_query('SELECT forum_id,forum_name FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_POST
			. ' AND ' . $this->db->sql_in_set('forum_id', array_map('intval', $visible)) . ' ORDER BY left_id');
		$forums = array();
		while ($row = $this->db->sql_fetchrow($result)) { $forums[(int) $row['forum_id']] = html_entity_decode($row['forum_name'], ENT_QUOTES, 'UTF-8'); }
		$this->db->sql_freeresult($result);
		return $forums;
	}

	private function valid($field, $value)
	{
		switch ($field[0])
		{
			case 'bool': return in_array($value, array('0','1'), true);
			case 'notify': return in_array($value, array('0','1','2'), true);
			case 'page_size': return preg_match('/^[1-9][0-9]{0,3}$/D', $value) && (int) $value <= 1000;
			case 'choice': return in_array($value, $field[2], true);
			case 'text':
			case 'memo': return utf8_strlen($value) <= $field[2];
			case 'user':
			case 'forum':
				if ($value === '0') { return true; }
				if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (string) (int) $value !== $value) { return false; }
				if ($field[0] === 'forum') { return isset($this->forums()[(int) $value]); }
				$result = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $value . ' AND user_id<>' . ANONYMOUS
					. ' AND ' . $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_FOUNDER)));
				$found = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);
				return (bool) $found;
		}
		return false;
	}

	public function save($section, $values, $revision)
	{
		if (!isset(self::SECTIONS[$section]) || !$this->auth->acl_gets('a_plus_kb','a_plus_kb_settings')) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->editor->locked(function () use ($section, $values, $revision)
		{
			$current = $this->values($section);
			if (!hash_equals(self::revision($current), $revision)) { throw new \DomainException('ACP_PLUS_KB_SETTINGS_CONFLICT'); }
			foreach (self::SECTIONS[$section] as $name => $field)
			{
				if (!isset($values[$name])) { throw new \DomainException('ACP_PLUS_KB_SETTINGS_INVALID'); }
				$value = $values[$name];
				if ($value === $current[$name]) { continue; }
				if (!$this->valid($field, $value)) { throw new \DomainException('ACP_PLUS_KB_SETTINGS_INVALID'); }
				$encoded = in_array($field[0], array('text','memo'), true);
				$row = array('setting_value' => $encoded ? articles::encode_label($value) : $value, 'value_encoded' => (int) $encoded);
				if ($this->articles->setting($name, null) === null)
				{
					$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row + array('setting_name' => $name)));
				}
				else
				{
					$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $row)
						. " WHERE setting_name='" . $this->db->sql_escape($name) . "'");
				}
			}
		});
		$this->articles->refresh();
	}
}
