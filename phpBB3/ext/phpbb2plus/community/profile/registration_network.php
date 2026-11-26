<?php
namespace phpbb2plus\community\profile;

class registration_network
{
	public const PAGE = 25;
	private $db;
	private $auth;
	private $user;

	public function __construct($db, $auth, $user)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
	}

	public function permitted()
	{
		return defined('IN_ADMIN') && IN_ADMIN && !empty($this->user->data['session_admin']) &&
			!empty($this->user->data['is_registered']) && empty($this->user->data['user_perm_from']) && $this->auth->acl_get('a_plus_reg_network');
	}

	private function accounts()
	{
		return 'user_id>' . ANONYMOUS . ' AND ' . $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_INACTIVE, USER_FOUNDER));
	}

	public function find($id, $name)
	{
		if (!$this->permitted()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$match = $name !== '' ? "username_clean='" . $this->db->sql_escape(utf8_clean_string($name)) . "'" : 'user_id=' . (int) $id;
		$result = $this->db->sql_query('SELECT user_id,username,user_ip,plus_reg_host FROM ' . USERS_TABLE . ' WHERE ' . $this->accounts() . ' AND ' . $match);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function matches($address, $after, $date)
	{
		if (!$this->permitted()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$address = trim($address);
		if (filter_var($address, FILTER_VALIDATE_IP) === false) { return array(array(), null); }
		$binary = inet_pton($address);
		// Unspecified placeholders do not establish a relationship between accounts.
		if ($binary === false || trim($binary, "\0") === '') { return array(array(), null); }
		// Existing conversions/native accounts can spell an IPv6 address in long,
		// compressed or mixed case form. Use its bytes; no hostname lookup is needed.
		$match = strlen($binary) === 4 ? "user_ip='" . $this->db->sql_escape(inet_ntop($binary)) . "'" :
			"INET6_ATON(user_ip)=UNHEX('" . bin2hex($binary) . "')";
		$after = max(0, (int) $after);
		$date = max(0, (int) $date);
		$cursor = $after ? " AND (user_regdate>$date OR (user_regdate=$date AND user_id>$after))" : '';
		$result = $this->db->sql_query_limit('SELECT user_id,username,user_email,user_posts,user_regdate FROM ' . USERS_TABLE .
			' WHERE ' . $this->accounts() . ' AND ' . $match . $cursor . ' ORDER BY user_regdate,user_id', self::PAGE + 1);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$more = count($rows) > self::PAGE;
		if ($more) { array_pop($rows); }
		return array($rows, $more ? end($rows) : null);
	}
}
