<?php
namespace phpbb2plus\community\service;

class poll_recovery
{
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
