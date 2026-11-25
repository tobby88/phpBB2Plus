<?php
namespace phpbb2plus\community\service;

class removed_topic_shadows
{
	/**
	 * Native forum lists retain a move link's own title. Retire the published,
	 * empty links pointing to this removed topic, or to proven imported markers.
	 * The caller owns the transaction and adjusts the board-wide topic count.
	 */
	public static function hide($db, $topic_id, $resync_forums)
	{
		$topics = TOPICS_TABLE;
		$posts = POSTS_TABLE;
		$targets = $topic_id ? 't.topic_id=' . (int) $topic_id . ' AND t.topic_visibility<>' . ITEM_APPROVED : removed_topics::condition('t.');
		$last = $changed = 0;
		$forums = array();
		do
		{
			$r = $db->sql_query("SELECT s.topic_id,s.forum_id,t.topic_title FROM $topics s JOIN $topics t ON t.topic_id=s.topic_moved_id
				WHERE s.topic_id>$last AND s.topic_id<>t.topic_id AND s.topic_status=" . ITEM_MOVED . ' AND s.topic_visibility=' . ITEM_APPROVED .
				" AND t.topic_moved_id=0 AND ($targets) AND NOT EXISTS (SELECT 1 FROM $posts p WHERE p.topic_id=s.topic_id)
				ORDER BY s.topic_id LIMIT 200 FOR UPDATE");
			$rows = $db->sql_fetchrowset($r);
			$db->sql_freeresult($r);
			if (!$rows) { break; }
			$localized = array();
			foreach ($rows as $row)
			{
				$last = (int) $row['topic_id'];
				$forums[(int) $row['forum_id']] = true;
				$label = $row['topic_title'] === removed_topics::TITLES[1] ? removed_topics::TITLES[1] : removed_topics::TITLES[0];
				$localized[$label][] = $last;
			}
			foreach ($localized as $label => $ids)
			{
				$fields = array('topic_title' => $label, 'plus_description' => '', 'topic_visibility' => ITEM_DELETED, 'topic_poster' => ANONYMOUS,
					'topic_first_poster_name' => '', 'topic_first_poster_colour' => '', 'topic_last_poster_id' => ANONYMOUS,
					'topic_last_poster_name' => '', 'topic_last_poster_colour' => '', 'topic_last_post_subject' => '', 'topic_attachment' => 0);
				$db->sql_query("UPDATE $topics SET " . $db->sql_build_array('UPDATE', $fields) . ' WHERE ' . $db->sql_in_set('topic_id', $ids));
				$changed += (int) $db->sql_affectedrows();
			}
		}
		while (count($rows) === 200);
		if ($resync_forums && $forums)
		{
			global $phpbb_root_path, $phpEx;
			require_once $phpbb_root_path . 'includes/functions_admin.' . $phpEx;
			sync('forum', 'forum_id', array_keys($forums), true);
		}
		return $changed;
	}
}
