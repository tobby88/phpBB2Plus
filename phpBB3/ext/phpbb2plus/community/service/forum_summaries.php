<?php
namespace phpbb2plus\community\service;

class forum_summaries
{
	public const CURSOR = 'plus_convert_forums_after';

	/** The forum changes and their checkpoint commit together, including on a retry. */
	public static function advance($db, $config, $limit = 16)
	{
		$db->sql_transaction('begin');
		try
		{
			$result = $db->sql_query('SELECT config_value FROM ' . CONFIG_TABLE .
				" WHERE config_name='" . self::CURSOR . "' FOR UPDATE");
			$checkpoint = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			$next = null;
			if ($checkpoint)
			{
				$next = self::refresh_batch($db, (int) $checkpoint['config_value'], $limit);
				if ($next === null) { $config->delete(self::CURSOR, false); }
				else { $config->set(self::CURSOR, $next, false); }
			}
			$db->sql_transaction('commit');
			return $next;
		}
		catch (\Throwable $error)
		{
			$db->sql_transaction('rollback');
			throw $error;
		}
	}

	/** Refresh one page of posting forums. A null result marks the end. */
	private static function refresh_batch($db, $after, $limit)
	{
		global $phpbb_root_path, $phpEx;
		$limit = max(1, min(100, (int) $limit));
		// Existing boards may be accepting posts while their extension is updated.
		$result = $db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE .
			' WHERE forum_type=' . FORUM_POST . ' AND forum_id>' . (int) $after .
			' ORDER BY forum_id LIMIT ' . ($limit + 1) . ' FOR UPDATE');
		$forums = array_map('intval', array_column($db->sql_fetchrowset($result), 'forum_id'));
		$db->sql_freeresult($result);
		if (!$forums) { return null; }
		$more = count($forums) > $limit;
		if ($more) { array_pop($forums); }

		require_once $phpbb_root_path . 'includes/functions_admin.' . $phpEx;
		sync('forum', 'forum_id', $forums, false, true);

		// The maintenance sync permits a move link to supply the destination's
		// last post. The native posting routine uses approved posts in this forum.
		$result = $db->sql_query('SELECT f.forum_id FROM ' . FORUMS_TABLE . ' f LEFT JOIN ' . POSTS_TABLE .
			' p ON p.post_id=f.forum_last_post_id AND p.forum_id=f.forum_id AND p.post_visibility=' . ITEM_APPROVED .
			' LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=f.forum_id AND t.topic_visibility=' . ITEM_APPROVED .
			' WHERE ' . $db->sql_in_set('f.forum_id', $forums) . ' AND (f.forum_last_post_id<>0 OR f.forum_posts_approved>0) AND t.topic_id IS NULL');
		$missing = array_map('intval', array_column($db->sql_fetchrowset($result), 'forum_id'));
		$db->sql_freeresult($result);
		if ($missing)
		{
			require_once $phpbb_root_path . 'includes/functions_posting.' . $phpEx;
			update_post_information('forum', $missing);
		}
		return $more ? end($forums) : null;
	}
}
