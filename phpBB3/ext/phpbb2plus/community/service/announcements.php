<?php
namespace phpbb2plus\community\service;

class announcements
{
	// Preserve the signed MEDIUMINT source range even when seconds exceed the native timestamp column.
	const MAX_DAYS = 8388607;
	const NATIVE_DAYS = 24855;
	private $db;
	private $config;

	public function __construct($db,$config) { $this->db=$db; $this->config=$config; }

	public static function is_announcement($type)
	{
		return (int) $type === POST_ANNOUNCE || (int) $type === POST_GLOBAL;
	}

	public static function storage($days,$default)
	{
		$days = (int) $days;
		if ($days < 0) { return array(0,0); }
		if (!$days)
		{
			if ($default <= 0) { return array(0,-1); }
			$days = (int) $default;
		}
		return $days > self::NATIVE_DAYS ? array(0,$days) : array($days * 86400,0);
	}

	public static function editor_days($type,$native_days,$extra)
	{
		if (!self::is_announcement($type)) { return (int) $native_days; }
		if ((int) $extra === -1) { return 0; }
		return (int) $extra ?: ((int) $native_days ?: -1);
	}

	public function still_current($scope,$expected)
	{
		list($forum,$topic,$post) = $scope;
		$r = $this->db->sql_query('SELECT topic_type,topic_time_limit,plus_announce_days FROM ' . TOPICS_TABLE .
			" WHERE forum_id=$forum AND topic_id=$topic AND topic_first_post_id=$post FOR UPDATE");
		$row = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		if ($row && $expected === array((int) $row['topic_type'],self::editor_days($row['topic_type'],$row['topic_time_limit']/86400,$row['plus_announce_days']))) { return true; }
		$this->db->sql_transaction('rollback');
		return false;
	}

	public function day_end()
	{
		$zone = new \DateTimeZone($this->config['board_timezone'] ?: 'UTC');
		return (new \DateTimeImmutable('now',$zone))->setTime(23,59,59)->getTimestamp();
	}

	public function should_sweep()
	{
		return (int) $this->config['plus_announce_default'] > 0 && (int) $this->config['plus_announce_sweep'] !== $this->day_end();
	}

	public function sweep()
	{
		$end = $this->day_end();
		if ((int) $this->config['plus_announce_default'] <= 0) { return; }
		if ($this->apply($end)) { $this->config->set('plus_announce_sweep',$end,true); }
	}

	private function apply($end,$topic = 0)
	{
		$scope = 'topic_type IN (' . POST_ANNOUNCE . ',' . POST_GLOBAL . ')';
		if ($topic) { $scope .= ' AND topic_id=' . (int) $topic; }
		$this->db->sql_transaction('begin');
		try
		{
			// The ACP may have paused expiry since the cron task or topic request started.
			$r = $this->db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE .
				" WHERE config_name IN ('plus_announce_default','plus_announce_sticky') ORDER BY config_name FOR UPDATE");
			$policy = array_column($this->db->sql_fetchrowset($r),'config_value','config_name');
			$this->db->sql_freeresult($r);
			$days = (int) ($policy['plus_announce_default'] ?? 0);
			if ($days > 0)
			{
				list($seconds,$extra) = self::storage(0,$days);
				$this->db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_time_limit=$seconds,plus_announce_days=$extra WHERE $scope AND plus_announce_days=-1");
				$next = (int) ($policy['plus_announce_sticky'] ?? 0) === 1 ? POST_STICKY : POST_NORMAL;
				// Use wide arithmetic for long durations and test the current row in the update itself.
				$this->db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_type=$next,topic_time_limit=0,plus_announce_days=0 WHERE $scope AND
					((topic_time_limit>0 AND topic_time+0.0+topic_time_limit<=$end) OR (plus_announce_days>0 AND topic_time+86400.0*plus_announce_days<=$end))");
			}
			$this->db->sql_transaction('commit');
			return $days > 0;
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function on_view($row)
	{
		if (!self::is_announcement($row['topic_type'])) { return $row; }
		$extra = (int) $row['plus_announce_days'];
		$seconds = (int) $row['topic_time_limit'];
		if (!$extra && !$seconds) { return $row; }
		if ((int) $this->config['plus_announce_default'] > 0)
		{
			$end = $this->day_end();
			if ($extra === -1 || (int) $row['topic_time'] + ($extra > 0 ? 86400.0*$extra : $seconds) <= $end)
			{
				$id = (int) $row['topic_id'];
				$this->apply($end,$id);
				$r = $this->db->sql_query('SELECT topic_type,topic_time_limit,plus_announce_days FROM ' . TOPICS_TABLE . " WHERE topic_id=$id");
				$current = $this->db->sql_fetchrow($r);
				$this->db->sql_freeresult($r);
				if ($current) { $row = array_replace($row,$current); }
			}
		}
		// The native view would otherwise demote a paused announcement, or ignore the board's sticky fallback.
		if (self::is_announcement($row['topic_type'])) { $row['topic_time_limit'] = 0; }
		return $row;
	}
}
