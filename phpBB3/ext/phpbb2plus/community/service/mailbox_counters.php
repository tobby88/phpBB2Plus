<?php
namespace phpbb2plus\community\service;

class mailbox_counters
{
	const PAGE_SIZE = 100;
	private $db;

	public function __construct($db) { $this->db = $db; }

	private function accounts($where, $limit)
	{
		$sql = 'SELECT user_id, user_new_privmsg, user_unread_privmsg FROM ' . USERS_TABLE .
			' WHERE user_id <> ' . ANONYMOUS . ' AND user_type <> ' . USER_IGNORE . ' AND ' . $where . ' ORDER BY user_id';
		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function counts(array $accounts)
	{
		if (!$accounts) { return array(); }
		// Use the same mailbox flags and folders as core update_pm_counts().
		$sql = 'SELECT user_id, SUM(CASE WHEN ' . $this->pending() . ' THEN 1 ELSE 0 END) AS pending,
			SUM(CASE WHEN ' . $this->unread() . ' THEN 1 ELSE 0 END) AS unread FROM ' . PRIVMSGS_TO_TABLE .
			' WHERE ' . $this->db->sql_in_set('user_id', array_map('intval', array_column($accounts, 'user_id'))) . ' GROUP BY user_id';
		$result = $this->db->sql_query($sql);
		$totals = array_column($this->db->sql_fetchrowset($result), null, 'user_id');
		$this->db->sql_freeresult($result);
		$rows = array();
		foreach ($accounts as $account)
		{
			$id = (int) $account['user_id'];
			$rows[] = array('id' => $id, 'stored_new' => (int) $account['user_new_privmsg'], 'stored_unread' => (int) $account['user_unread_privmsg'],
				'pending' => (int) ($totals[$id]['pending'] ?? 0), 'unread' => (int) ($totals[$id]['unread'] ?? 0));
		}
		return $rows;
	}

	private function pending() { return 'pm_new = 1 AND folder_id IN (' . PRIVMSGS_NO_BOX . ', ' . PRIVMSGS_HOLD_BOX . ')'; }
	private function unread() { return 'pm_unread = 1 AND folder_id <> ' . PRIVMSGS_OUTBOX; }

	public static function revision(array $row) { return hash('sha256', implode(':', $row)); }

	public function page($after)
	{
		$users = $this->accounts('user_id > ' . max(0, (int) $after), self::PAGE_SIZE + 1);
		$more = count($users) > self::PAGE_SIZE;
		if ($more) { array_pop($users); }
		$last = $users ? (int) end($users)['user_id'] : 0;
		$changes = array();
		foreach ($this->counts($users) as $row)
		{
			if ($row['stored_new'] !== $row['pending'] || $row['stored_unread'] !== $row['unread']) { $changes[] = $row; }
		}
		return array('checked' => count($users), 'changes' => $changes, 'next' => $more ? $last : 0);
	}

	public function repair($id, $revision)
	{
		$id = (int) $id;
		$rows = $this->counts($this->accounts('user_id = ' . $id, 1));
		if (!$rows || !hash_equals(self::revision($rows[0]), $revision)) { return false; }
		$row = $rows[0];
		// Guard the update with the current counters and mailbox rows. A delivery
		// or read between the report and its confirmation invalidates that report.
		$count = '(SELECT COUNT(msg_id) FROM ' . PRIVMSGS_TO_TABLE . ' WHERE user_id = ' . $id . ' AND ';
		$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_new_privmsg = ' . $row['pending'] .
			', user_unread_privmsg = ' . $row['unread'] . ' WHERE user_id = ' . $id .
			' AND user_new_privmsg = ' . $row['stored_new'] . ' AND user_unread_privmsg = ' . $row['stored_unread'] .
			' AND ' . $count . $this->pending() . ') = ' . $row['pending'] .
			' AND ' . $count . $this->unread() . ') = ' . $row['unread']);
		return (bool) $this->db->sql_affectedrows();
	}
}
