<?php
namespace phpbb2plus\arcade\cron;

class tournament_notices extends \phpbb\cron\task\base
{
	private $db;
	private $config;
	private $auth;
	private $catalog;
	private $messages;
	private $prefix;

	public function __construct($db, $config, $auth, $catalog, $messages, $prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->catalog = $catalog;
		$this->messages = $messages;
		$this->prefix = $prefix;
	}

	private function enabled() { return $this->config['allow_privmsg'] && (int) $this->catalog->setting('games_use_pms',0); }

	public function queue($event, $sender)
	{
		if (!$this->enabled() || !$this->auth->acl_get('u_sendpm')) { return; }
		$p = $this->prefix;
		$event = (int) $event;
		$sender = (int) $sender;
		$this->db->sql_query("INSERT INTO {$p}tour_notices (tour_id,recipient_id,sender_id)
			SELECT DISTINCT $event,u.user_id,$sender FROM {$p}tour_plays p JOIN " . USERS_TABLE . " u ON u.user_id=p.owner_id
			JOIN {$p}members m ON m.user_id=u.user_id AND m.block_pm=0 WHERE p.tour_id=$event AND u.user_id<>$sender
			AND u.user_id>" . ANONYMOUS . ' AND u.user_allow_pm=1 AND ' . $this->db->sql_in_set('u.user_type',array(USER_NORMAL,USER_FOUNDER)));
		if ($this->db->sql_affectedrows()) { $this->config->set('plus_arc_notice_time', 0, true); }
	}

	public function should_run() { return (int) $this->config['plus_arc_notice_time'] + 300 <= time(); }

	private function deliver($job)
	{
		$p = $this->prefix;
		$tour = (int) $job['tour_id'];
		$recipient = (int) $job['recipient_id'];
		$where = "tour_id=$tour AND recipient_id=$recipient";
		$this->db->sql_transaction('begin');
		try
		{
			$result = $this->db->sql_query("SELECT sender_id FROM {$p}tour_notices WHERE $where FOR UPDATE");
			$sender = (int) $this->db->sql_fetchfield('sender_id');
			$this->db->sql_freeresult($result);
			if ($sender && $this->enabled())
			{
				$result = $this->db->sql_query('SELECT s.user_id,s.user_type,s.user_permissions,s.user_perm_from,s.username,u.user_lang,t.tour_name
					FROM ' . USERS_TABLE . ' s JOIN ' . USERS_TABLE . " u ON u.user_id=$recipient JOIN {$p}members m ON m.user_id=u.user_id
					JOIN {$p}tours t ON t.tour_id=$tour AND t.tour_active=3 WHERE s.user_id=$sender AND s.user_allow_pm=1 AND u.user_allow_pm=1
					AND m.block_pm=0 AND " . $this->db->sql_in_set('s.user_type',array(USER_NORMAL,USER_FOUNDER)) . ' AND ' .
					$this->db->sql_in_set('u.user_type',array(USER_NORMAL,USER_FOUNDER)));
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);
				if ($row)
				{
					$authority = clone $this->auth;
					$authority->acl($row);
					if ($authority->acl_get('a_plus_arcade') && $authority->acl_get('u_sendpm'))
					{
						$this->messages->send($sender,$row['username'],'',$recipient,$row['user_lang'],'tournament/' . $tour,
							'PLUS_ARC_RESULT_PM','PLUS_ARC_RESULT_PM_TEXT',array($row['tour_name']));
					}
				}
			}
			$this->db->sql_query("DELETE FROM {$p}tour_notices WHERE $where");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e)
		{
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			throw $e;
		}
	}

	public function run()
	{
		$result = $this->db->sql_query_limit('SELECT tour_id,recipient_id FROM ' . $this->prefix . 'tour_notices ORDER BY tour_id,recipient_id', 25);
		$jobs = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($jobs as $job) { $this->deliver($job); }
		$this->config->set('plus_arc_notice_time', count($jobs) === 25 ? time() - 240 : time(), true);
	}
}
