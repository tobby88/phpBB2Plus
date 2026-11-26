<?php
namespace phpbb2plus\arcade\service;

class diagnostics
{
	public const TYPES = array('','BAD_GAME','ERROR','GAME','GAME_ERROR','POST');
	private $db;
	private $access;
	private $catalog;
	private $sessions;
	private $log;
	private $user;
	private $table;

	public function __construct($db, $access, $catalog, $sessions, $log, $user, $table)
	{
		$this->db = $db;
		$this->access = $access;
		$this->catalog = $catalog;
		$this->sessions = $sessions;
		$this->log = $log;
		$this->user = $user;
		$this->table = $table;
	}

	private function condition($filter, $ids)
	{
		if (!array_key_exists($filter,self::TYPES)) { throw new \DomainException('PLUS_ARC_LOG_CHANGED'); }
		$where = $filter ? "l.name='" . self::TYPES[$filter] . "'" : '1=1';
		if ($ids !== null)
		{
			if (!$ids || count($ids)>1000 || min($ids)<1) { throw new \DomainException('PLUS_ARC_LOG_SELECT'); }
			$where .= ' AND ' . $this->db->sql_in_set('l.record_no',$ids);
		}
		return $where;
	}

	public function preview($filter, $ids)
	{
		$this->access->authorize('diagnostics');
		$where = $this->condition($filter,$ids);
		$result = $this->db->sql_query("SELECT COUNT(*) AS total,MAX(l.record_no) AS last_id FROM $this->table l WHERE $where");
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		$count = (int) $row['total'];
		if ($ids !== null && $count !== count($ids)) { throw new \DomainException('PLUS_ARC_LOG_CHANGED'); }
		return array($count,(int) $row['last_id']);
	}

	public function listing($filter, $offset)
	{
		list($total) = $this->preview($filter,null);
		$limit = max(1,min(1000,(int) $this->catalog->setting('games_per_admin_page',20)));
		$start = intdiv(min(max(0,$offset),max(0,$total-1)),$limit)*$limit;
		$rows = array();
		if ($total)
		{
			$where = $this->condition($filter,null);
			$result = $this->db->sql_query_limit("SELECT l.*,u.username FROM $this->table l LEFT JOIN " . USERS_TABLE .
				" u ON u.user_id=l.owner_id WHERE $where ORDER BY l.record_no DESC",$limit,$start);
			$rows = $this->db->sql_fetchrowset($result);
			$this->db->sql_freeresult($result);
		}
		return array($rows,$total,$limit,$start);
	}

	public function clear($filter, $ids, $count, $last)
	{
		if ($count < 1 || $last < 1 || ($ids !== null && $count !== count($ids))) { throw new \DomainException('PLUS_ARC_LOG_CHANGED'); }
		$this->sessions->locked(function () use ($filter,$ids,$count,$last) {
			$this->access->authorize('diagnostics',true);
			$where = $this->condition($filter,$ids);
			$this->db->sql_query("DELETE l FROM $this->table l WHERE $where AND l.record_no<=" . (int) $last);
			if ((int) $this->db->sql_affectedrows() !== $count) { throw new \DomainException('PLUS_ARC_LOG_CHANGED'); }
			$this->log->add('admin',(int) $this->user->data['user_id'],$this->user->ip,'LOG_PLUS_ARC_DIAGNOSTICS',false,
				array($count,self::TYPES[$filter] ?: '*'));
		});
	}
}
