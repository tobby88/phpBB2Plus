<?php
namespace phpbb2plus\community\service;

class member_notice
{
	public const KEY = 'plus_member_notice';
	public const EMPTY_STATE = '{"revision":"0","format":"text","message":""}';
	private $db;
	private $table;

	public function __construct($db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	public static function link($message)
	{
		$url = html_entity_decode(trim($message), ENT_QUOTES, 'UTF-8');
		if ($url === '' || preg_match('~[\\\\\x00-\x20\x7f]~', $url)) { return ''; }
		$parts = parse_url($url);
		if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
			!in_array(strtolower($parts['scheme'] ?? ''), array('https', 'http'), true)) { return ''; }
		return $url;
	}

	public static function valid($format, $message)
	{
		return in_array($format, array('text', 'link'), true) && is_string($message) && strlen($message) <= 1020 &&
			preg_match('/\A.{0,255}\z/us', $message) === 1 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message) &&
			($format === 'text' || self::link($message) !== '');
	}

	private static function decode($value)
	{
		$state = is_string($value) ? json_decode($value, true) : null;
		if (!isset($state['message'], $state['format'], $state['revision']) || !is_string($state['message']) ||
			!in_array($state['format'], array('text', 'link'), true) || !is_string($state['revision']))
		{
			throw new \UnexpectedValueException('The stored member notice could not be read.');
		}
		return $state;
	}

	public function current($lock = 'read')
	{
		$suffix = array('read' => '', 'shared' => ' LOCK IN SHARE MODE', 'write' => ' FOR UPDATE');
		$result = $this->db->sql_query("SELECT config_value FROM {$this->table} WHERE config_name='" . self::KEY . "'" . $suffix[$lock]);
		$value = $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);
		return self::decode($value);
	}

	public function pending($id)
	{
		$result = $this->db->sql_query("SELECT c.config_value FROM {$this->table} c, " . USERS_TABLE . ' u WHERE u.user_id=' . (int) $id .
			' AND u.plus_notice_pending=1 AND u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ") AND c.config_name='" . self::KEY . "'");
		$value = $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);
		return $value === false ? null : self::decode($value);
	}

	/** The ACP already holds the notice row and the operator's current module assignment. */
	public function distribute($format, $message, $publish)
	{
		// A repeated publication of the same text is a new notice. Old open tabs still refer to their original revision.
		$state = array('revision' => bin2hex(random_bytes(16)), 'format' => $format, 'message' => $message);
		$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE',
			array('config_value' => json_encode($state, JSON_THROW_ON_ERROR))) . " WHERE config_name='" . self::KEY . "'");
		// Accounts created afterwards start with zero. Existing inactive members receive their copy on activation.
		$flag = (int) $publish;
		$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_notice_pending=$flag WHERE plus_notice_pending<>$flag" .
			' AND user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ',' . USER_INACTIVE . ')');
	}

	public function acknowledge($id, $sid, $revision)
	{
		$id = (int) $id;
		$db = $this->db;
		$db->sql_transaction('begin');
		try
		{
			$state = $this->current('shared');
			$result = $db->sql_query('SELECT u.user_id FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id
				WHERE u.user_id=$id AND s.session_id='" . $db->sql_escape($sid) . "' AND u.user_type IN (" . USER_NORMAL . ',' . USER_FOUNDER . ') FOR UPDATE');
			$found = $db->sql_fetchfield('user_id');
			$db->sql_freeresult($result);
			if ($found === false || $id <= ANONYMOUS) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if (!hash_equals($state['revision'], $revision)) { throw new \DomainException('PLUS_MEMBER_NOTICE_CHANGED'); }
			$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_notice_pending=0 WHERE user_id=$id AND plus_notice_pending=1");
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}
}
