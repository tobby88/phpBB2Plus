<?php
namespace phpbb2plus\community\mail;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class recommendations implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $auth;
	private $user;
	private $table;

	public function __construct($db,$config,$auth,$user,$table)
	{
		$this->db=$db; $this->config=$config; $this->auth=$auth; $this->user=$user; $this->table=$table;
	}

	public static function getSubscribedEvents() { return array('core.session_gc_after'=>'expire','core.viewtopic_modify_page_title'=>'topic_link'); }
	public function expire()
	{
		// Even an unlimited native form token may not be used for this delivery more than 24 hours later.
		$this->db->sql_query("DELETE FROM $this->table WHERE attempted<" . (time()-172800));
	}
	public function topic_link($event)
	{
		global $template;
		$topic=$event['topic_data'];
		if (!$this->member() || !$this->auth->acl_get('u_sendemail') || (int)$this->config['plus_friend_protection']===2 ||
			(int)$topic['topic_visibility']!==ITEM_APPROVED || !empty($topic['plus_legacy_removed']))
		{ $template->assign_var('U_EMAIL_TOPIC',''); }
	}
	public function member()
	{
		return !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) && empty($this->user->data['user_perm_from'])
			&& in_array((int) $this->user->data['user_type'],array(USER_NORMAL,USER_FOUNDER),true);
	}

	public function board()
	{
		$scheme = $this->config['server_protocol'];
		if (!in_array($scheme,array('http://','https://'),true)) { $scheme = $this->config['cookie_secure'] ? 'https://' : 'http://'; }
		$port = (int) $this->config['server_port'];
		$host = $this->config['server_name'];
		if ($host === '' || strpbrk($host,"/\\@?# \t\r\n")!==false) { return ''; }
		$url = $scheme . $host . ($port && $port !== ($scheme === 'https://' ? 443 : 80) ? ':' . $port : '') .
			'/' . trim($this->config['script_path'],'/');
		return filter_var($url,FILTER_VALIDATE_URL) ? rtrim($url,'/') : '';
	}

	public function legacy_topic($value)
	{
		// Extract only the local topic number. Never copy a URL's query string, fragment, or a display title into mail.
		$value = html_entity_decode($value,ENT_QUOTES,'UTF-8');
		if ($value==='' || strlen($value)>2048 || preg_match('~[\\\\\x00-\x20\x7f]~',$value) || strpos($value,'//')===0) { return 0; }
		$parts = parse_url($value);
		if (!$parts || isset($parts['user']) || isset($parts['pass'])) { return 0; }
		$path = $parts['path'] ?? '';
		if (isset($parts['scheme']) || isset($parts['host']) || strpos($path,'/')===0)
		{
			$local = false;
			foreach (array($this->board(),$this->config['plus_friend_source_board']) as $url)
			{
				$origin = parse_url($url);
				if (!$origin || empty($origin['host'])) { continue; }
				if (isset($parts['scheme']) || isset($parts['host']))
				{
					if (!isset($parts['scheme'],$parts['host']) || !in_array(strtolower($parts['scheme']),array('http','https'),true) ||
						strcasecmp($parts['host'],$origin['host'])) { continue; }
					// The old board built links using either HTTP or HTTPS on their respective standard ports.
					$port=$parts['port'] ?? null;
					if ($port === (strtolower($parts['scheme'])==='https' ? 443 : 80)) { $port=null; }
					if ($port !== ($origin['port'] ?? null)) { continue; }
				}
				$prefix = rtrim($origin['path'] ?? '','/') . '/';
				if (strpos($path,$prefix)===0) { $path=substr($path,strlen($prefix)); $local=true; break; }
			}
			if (!$local) { return 0; }
		}
		if (strpos($path,'./')===0) { $path=substr($path,2); }
		if (preg_match('~^ftopic([1-9][0-9]{0,8})(?:-[0-9]+)?\.html$~D',$path,$matched)) { return (int) $matched[1]; }
		if ($path!=='viewtopic.php') { return 0; }
		parse_str($parts['query'] ?? '',$values);
		$topic=$values['t'] ?? '';
		return is_string($topic) && preg_match('/^[1-9][0-9]{0,8}$/D',$topic) ? (int) $topic : 0;
	}

	private function row($sql)
	{
		$result=$this->db->sql_query($sql);
		$row=$this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function reserve($receipt,$check)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$id=(int) $this->user->data['user_id'];
			$now=time();
			$actor=$this->row('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
				" s ON s.session_user_id=u.user_id WHERE u.user_id=$id AND s.session_id='" . $this->db->sql_escape($this->user->session_id) .
				"' AND s.session_time>=" . ($now-(int)$this->config['session_length']) . ' FOR UPDATE');
			if (!$actor || !$this->member() || !in_array((int) $actor['user_type'],array(USER_NORMAL,USER_FOUNDER),true) || $actor['user_perm_from'] ||
				!filter_var($actor['user_email'],FILTER_VALIDATE_EMAIL) || $this->user->check_ban($id,$this->user->ip,$actor['user_email'],true))
			{ throw new \DomainException('NO_AUTH_OPERATION'); }
			$actor['is_registered']=true; $actor['is_bot']=false;
			$this->auth->acl($actor);
			$error=$check();
			if ($error) { throw new \DomainException($error); }
			$settings=array();
			$result=$this->db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
				$this->db->sql_in_set('config_name',array('email_enable','board_email','flood_interval','plus_friend_protection','plus_friend_minutes')) . ' ORDER BY config_name LOCK IN SHARE MODE');
			while ($r=$this->db->sql_fetchrow($result)) { $settings[$r['config_name']]=$r['config_value']; }
			$this->db->sql_freeresult($result);
			$mode=$settings['plus_friend_protection'] ?? '';
			$mins=$settings['plus_friend_minutes'] ?? '';
			if (empty($settings['email_enable']) || !filter_var($settings['board_email'] ?? '',FILTER_VALIDATE_EMAIL) ||
				!in_array($mode,array('0','1'),true) || !ctype_digit($mins) || (int)$mins<1 || (int)$mins>180)
			{ throw new \DomainException('EMAIL_DISABLED'); }
			$prior=$this->row("SELECT status FROM $this->table WHERE receipt='$receipt' FOR UPDATE");
			if ($prior) { throw new \DomainException('PLUS_FRIEND_USED'); }
			if ($now-(int)$actor['user_emailtime']<max(1,(int)$settings['flood_interval']) ||
				($mode==='1' && $now-(int)$actor['plus_mail_success']<(int)$mins*60)) { throw new \DomainException('FLOOD_EMAIL_LIMIT'); }
			$this->db->sql_query("INSERT INTO $this->table " . $this->db->sql_build_array('INSERT',array('receipt'=>$receipt,'attempted'=>$now,'status'=>0)));
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . ($now+600) . " WHERE user_id=$id");
			$this->db->sql_transaction('commit');
			return array($actor,$now,$settings['board_email']);
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function finish($receipt,$claim,$success)
	{
		list($actor,$at)=$claim;
		$now=time();
		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=CASE WHEN user_emailtime=' . ($at+600) .
				" THEN $now ELSE user_emailtime END" . ($success ? ",plus_mail_success=GREATEST(plus_mail_success,$now)" : '') .
				' WHERE user_id=' . (int)$actor['user_id']);
			$this->db->sql_query("UPDATE $this->table SET status=" . ($success?1:2) . " WHERE receipt='$receipt' AND attempted=$at AND status=0");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
