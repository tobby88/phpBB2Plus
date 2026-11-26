<?php
namespace phpbb2plus\weblinks\service;

class directory
{
	public const DEFAULTS = array('linkspp'=>10, 'width'=>88, 'height'=>31, 'display_links_logo'=>1, 'display_interval'=>6000,
		'display_logo_num'=>10, 'lock_submit_site'=>0, 'allow_no_logo'=>1, 'email_notify'=>0, 'pm_notify'=>0,
		'site_logo'=>'', 'site_url'=>'');
	public const SORTS = array('link_hits'=>'hits','link_joined'=>'joined','link_title'=>'title','link_desc'=>'description');
	private $db;
	private $config;
	private $auth;
	private $user;
	private $prefix;

	public function __construct($db, $config, $auth, $user, $prefix)
	{
		$this->db = $db; $this->config = $config; $this->auth = $auth; $this->user = $user; $this->prefix = $prefix;
	}

	public static function encode($plain)
	{
		return utf8_encode_ucr(str_replace('&','&amp;', (string) $plain));
	}
	public static function plain($stored) { return html_entity_decode((string) $stored, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

	public static function external($value)
	{
		if ($value === '' || strlen($value) > 2048 || preg_match('~[\\\\\x00-\x20\x7f<>"\x27\x60]~',$value)) { return ''; }
		$parts = parse_url($value);
		return $parts && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) &&
			in_array(strtolower($parts['scheme'] ?? ''),array('http','https'),true) && filter_var($value,FILTER_VALIDATE_URL) ? $value : '';
	}

	private function rows($sql, $limit = 0, $start = 0)
	{
		$result = $limit ? $this->db->sql_query_limit($sql,$limit,$start) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}
	private function one($sql) { return $this->rows($sql)[0] ?? false; }

	public function categories()
	{
		return $this->rows('SELECT c.cat_id,c.title,c.position,COUNT(l.link_id) AS amount FROM ' . $this->prefix .
			'categories c LEFT JOIN ' . $this->prefix . 'entries l ON l.cat_id=c.cat_id AND l.status=1
			GROUP BY c.cat_id,c.title,c.position ORDER BY c.position,c.cat_id');
	}

	public function listing($view, $category, $sort, $order, $query, $offset)
	{
		$p = $this->prefix;
		$where = 'l.status=1';
		$ordering = 'l.joined DESC,l.link_id DESC';
		if ($view === 'sub_pages')
		{
			$where .= ' AND l.cat_id=' . (int) $category;
			$ordering = 'l.' . (self::SORTS[$sort] ?? 'joined') . ($order === 'ASC' ? ' ASC' : ' DESC') . ',l.link_id DESC';
		}
		elseif ($view === 'pop') { $ordering = 'l.hits DESC,l.link_id DESC'; }
		elseif ($view === 'search')
		{
			if ($query === '') { return array(0,0,array()); }
			$term = strtr(self::encode($query),array('!'=>'!!','%'=>'!%','_'=>'!_'));
			$like = "LIKE '" . $this->db->sql_escape('%' . $term . '%') . "' ESCAPE '!'";
			$where .= ' AND (l.title ' . $like . ' OR l.description ' . $like . ')';
			$ordering = 'l.link_id DESC';
		}
		$total = (int) $this->one("SELECT COUNT(*) AS n FROM {$p}entries l WHERE $where")['n'];
		$limit = max(1,min(100,(int) $this->config['plus_wl_linkspp']));
		$start = intdiv(min(max(0,$offset),max(0,$total-1)),$limit)*$limit;
		$rows = $total ? $this->rows("SELECT l.link_id,l.cat_id,l.title,l.description,l.joined,l.hits,l.user_id,l.source_user,
			l.logo,l.local_logo,u.username,u.user_colour FROM {$p}entries l LEFT JOIN " . USERS_TABLE . " u ON u.user_id=l.user_id
			WHERE $where ORDER BY $ordering",$limit,$start) : array();
		return array($total,$start,$rows);
	}

	public function member()
	{
		return !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) && empty($this->user->data['user_perm_from']);
	}
	public function may_submit() { return $this->member() && $this->config['plus_wl_inputs_valid'] &&
		(!$this->config['plus_wl_lock_submit_site'] || $this->auth->acl_get('a_plus_wl_publish')); }

	private function actor($in_acp)
	{
		if (!$this->member() || ($in_acp && (!defined('IN_ADMIN') || !IN_ADMIN))) { throw new \DomainException('NOT_AUTHORISED'); }
		$actor = $this->one('SELECT u.*,s.session_admin FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
			" s ON s.session_user_id=u.user_id WHERE s.session_id='" . $this->db->sql_escape($this->user->session_id) .
			"' AND u.user_id=" . (int) $this->user->data['user_id'] . ' FOR UPDATE');
		if (!$actor || $actor['user_perm_from'] || !in_array((int) $actor['user_type'],array(USER_NORMAL,USER_FOUNDER),true) ||
			($in_acp && !$actor['session_admin'])) { throw new \DomainException('NOT_AUTHORISED'); }
		$actor['is_registered'] = true; $actor['is_bot'] = false;
		$this->auth->acl($actor);
		return $actor;
	}

	public function acp_actor($right)
	{
		$actor = $this->actor(true);
		if (!$this->auth->acl_get($right)) { throw new \DomainException('NOT_AUTHORISED'); }
		return $actor;
	}

	public static function entry_fields($data, $previous = array())
	{
		$fields = array();
		foreach (array('title'=>100,'description'=>255,'destination'=>100,'logo'=>120) as $key=>$limit)
		{
			$value = trim($data[$key]);
			// Imported local logos and longer historical labels survive unrelated edits.
			if (isset($previous[$key]) && self::plain($previous[$key]) === $value)
			{
				$fields[$key] = $previous[$key];
				continue;
			}
			if (($key !== 'logo' && $value === '') || utf8_strlen($value)>$limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value) ||
				(in_array($key,array('destination','logo'),true) && $value !== '' && (strlen($value)>$limit || self::external($value)==='')))
			{ throw new \DomainException('WL_INVALID'); }
			$fields[$key] = self::encode($value);
		}
		return $fields;
	}

	public function submit($data)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$actor = $this->actor(false);
			$publish = $this->auth->acl_get('a_plus_wl_publish');
			$options = array('plus_wl_lock_submit_site'=>1, 'plus_wl_allow_no_logo'=>0, 'plus_wl_inputs_valid'=>0);
			foreach ($this->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
				$this->db->sql_in_set('config_name',array_keys($options)) . ' LOCK IN SHARE MODE') as $r)
			{ $options[$r['config_name']] = (int) $r['config_value']; }
			if (!$options['plus_wl_inputs_valid'] || ($options['plus_wl_lock_submit_site'] && !$publish)) { throw new \DomainException('WL_LOCKED'); }
			$data = self::entry_fields($data) + array('cat_id'=>(int) $data['cat_id']);
			if (!$options['plus_wl_allow_no_logo'] && $data['logo'] === '') { throw new \DomainException('WL_LOGO_REQUIRED'); }
			$p = $this->prefix;
			if (!$this->one("SELECT cat_id FROM {$p}categories WHERE cat_id=" . (int) $data['cat_id'] . ' LOCK IN SHARE MODE'))
			{ throw new \DomainException('WL_BAD_CATEGORY'); }
			$id = (int) $actor['user_id'];
			$now = time();
			$last = $this->one("SELECT MAX(joined) AS newest FROM {$p}entries WHERE user_id=$id");
			if ($last['newest'] !== null && $now-(int) $last['newest']<=60) { throw new \DomainException('WL_WAIT'); }
			$data += array('user_id'=>$id,'source_user'=>0,'local_logo'=>'','status'=>(int) $publish,'joined'=>$now,
				'submitter_ip'=>filter_var($this->user->ip,FILTER_VALIDATE_IP) ? $this->user->ip : '');
			$this->db->sql_query("INSERT INTO {$p}entries " . $this->db->sql_build_array('INSERT',$data));
			$link = (int) $this->db->sql_nextid();
			$this->db->sql_transaction('commit');
			return array($link,(bool) $publish);
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function visit($id, $count)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$id = (int) $id;
			$table = $this->prefix . 'entries';
			$row = $this->one("SELECT destination,last_ip FROM $table WHERE link_id=$id AND status=1" . ($count ? ' FOR UPDATE' : ''));
			$url = $row ? self::external(self::plain($row['destination'])) : '';
			$ip = $this->user->ip;
			if ($url !== '' && $count && filter_var($ip,FILTER_VALIDATE_IP) && $ip !== $row['last_ip'])
			{
				$this->db->sql_query("UPDATE $table SET hits=CASE WHEN hits<4294967295 THEN hits+1 ELSE hits END,last_ip='" .
					$this->db->sql_escape($ip) . "' WHERE link_id=$id AND status=1");
			}
			$this->db->sql_transaction('commit');
			return $url;
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public static function image_url($row, $helper, $index = false)
	{
		if ($row['local_logo'] === 'unavailable') { return ''; }
		if ($row['local_logo'] !== '')
		{
			$params = array('id'=>(int) $row['link_id']);
			if ($index && $params['id']) { $params['index'] = 1; }
			return $helper->route('phpbb2plus_weblinks_logo',$params,false);
		}
		return self::external(self::plain($row['logo']));
	}

	public function rotation_entries()
	{
		$result = $this->db->sql_query('SELECT link_id,title,destination,logo,local_logo FROM ' . $this->prefix .
			"entries WHERE status=1 AND destination<>'' AND (logo<>'' OR local_logo<>'') ORDER BY hits DESC,link_id DESC");
		try
		{
			while ($row = $this->db->sql_fetchrow($result))
			{
				if (self::external(self::plain($row['destination'])) !== '') { yield $row; }
			}
		}
		finally { $this->db->sql_freeresult($result); }
	}

	public function local_logo($id, $index = false)
	{
		if (!$id) { return (string) $this->config['plus_wl_local_logo']; }
		if (!$this->config[$index ? 'plus_wl_index_logos' : 'plus_wl_display_links_logo']) { return ''; }
		$row = $this->one('SELECT local_logo,destination FROM ' . $this->prefix . 'entries WHERE link_id=' . (int) $id . ' AND status=1');
		if ($index && $row && self::external(self::plain($row['destination'])) === '') { return ''; }
		return $row ? $row['local_logo'] : '';
	}

	public function review_page($status, $start)
	{
		$p = $this->prefix;
		$status = (int) $status;
		$total = (int) $this->one("SELECT COUNT(*) AS n FROM {$p}entries WHERE status=$status")['n'];
		$start = intdiv(min(max(0,$start),max(0,$total-1)),25)*25;
		$rows = $this->rows("SELECT l.*,c.title AS category FROM {$p}entries l LEFT JOIN {$p}categories c ON c.cat_id=l.cat_id
			WHERE l.status=$status ORDER BY l.joined DESC,l.link_id DESC",25,$start);
		return array($rows,$total,$start);
	}

	public function decide($id, $revision, $status)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->acp_actor('a_plus_wl_review');
			$p = $this->prefix;
			$id = (int) $id;
			$r = $this->one("SELECT l.revision,l.status,l.destination FROM {$p}entries l WHERE l.link_id=$id FOR UPDATE");
			if (!$r || (int) $r['revision'] !== $revision || (int) $r['status'] === $status)
			{ throw new \DomainException('WL_CHANGED'); }
			if ($status === 1 && !self::external(self::plain($r['destination']))) { throw new \DomainException('WL_INVALID'); }
			if (!in_array($status,array(0,1,2),true)) { throw new \DomainException('FORM_INVALID'); }
			$this->db->sql_query("UPDATE {$p}entries SET status=$status,revision=revision+1 WHERE link_id=$id");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
