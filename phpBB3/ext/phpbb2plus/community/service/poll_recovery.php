<?php
namespace phpbb2plus\community\service;

class poll_recovery
{
	private $db, $prefix;

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->prefix = $prefix;
	}

	public function count_held()
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS n FROM ' . $this->prefix . 'plus_poll_review');
		$count = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		return $count;
	}

	private function rows($sql, $limit, $start)
	{
		$result = $limit ? $this->db->sql_query_limit($sql, $limit, $start) : $this->db->sql_query($sql);
		try { while ($row = $this->db->sql_fetchrow($result)) { yield $row; } }
		finally { $this->db->sql_freeresult($result); }
	}

	public function listing($limit = 0, $start = 0)
	{
		$sql = 'SELECT source_id,topic_id,reason FROM ' . $this->prefix . 'plus_poll_review ORDER BY source_id';
		foreach ($this->rows($sql, $limit, $start) as $row)
		{
			yield array('source_id'=>(int) $row['source_id'], 'topic_id'=>(int) $row['topic_id'], 'reason'=>$row['reason']);
		}
	}

	public function header($id)
	{
		$p = $this->prefix;
		$result = $this->db->sql_query("SELECT h.source_id,h.topic_id,h.reason,h.header_json,
			(SELECT COUNT(*) FROM {$p}plus_poll_choices c WHERE c.source_id=h.source_id) AS choices,
			(SELECT COUNT(*) FROM {$p}plus_poll_receipts r WHERE r.source_id=h.source_id) AS receipts
			FROM {$p}plus_poll_review h WHERE h.source_id=" . (int) $id);
		$header = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$header) { return null; }
		return array('source_id'=>(int) $header['source_id'], 'topic_id'=>(int) $header['topic_id'], 'reason'=>$header['reason'],
			'choices'=>(int) $header['choices'], 'receipts'=>(int) $header['receipts']) + json_decode($header['header_json'], true, 512, JSON_THROW_ON_ERROR);
	}

	public function choices($id, $limit = 0, $start = 0)
	{
		$sql = 'SELECT choice_json FROM ' . $this->prefix . 'plus_poll_choices WHERE source_id=' . (int) $id . ' ORDER BY position';
		foreach ($this->rows($sql, $limit, $start) as $row) { yield json_decode($row['choice_json'], true, 512, JSON_THROW_ON_ERROR); }
	}

	/** Remove only held ballots belonging to the selected original topics, inside the caller's transaction. */
	public static function discard($db, $prefix, $topic_condition)
	{
		$review = $prefix . 'plus_poll_review';
		// A topic created later can receive the number of a missing source topic.
		$scope = "h.reason <> 'missing-topic' AND ($topic_condition)";
		foreach (array('plus_poll_choices', 'plus_poll_receipts') as $child)
		{
			$db->sql_query("DELETE c FROM $prefix$child c JOIN $review h ON h.source_id=c.source_id WHERE $scope");
		}
		$db->sql_query("DELETE h FROM $review h WHERE $scope");
	}
}
