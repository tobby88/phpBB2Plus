<?php
namespace phpbb2plus\community\service;

/** Reads current forum references. The separate import report retains the historical locations. */
class post_references
{
	public const PAGE_SIZE = 200;
	private $db;

	public function __construct($db) { $this->db = $db; }

	public function page($section, $after, $through)
	{
		$posts = $section === 'posts';
		$table = $posts ? POSTS_TABLE : TOPICS_TABLE;
		$key = $posts ? 'post_id' : 'topic_id';
		if ($through === null)
		{
			$result = $this->db->sql_query("SELECT MAX($key) AS last_id FROM $table");
			$through = (int) $this->db->sql_fetchfield('last_id');
			$this->db->sql_freeresult($result);
		}
		if ($posts)
		{
			$sql = 'SELECT p.post_id AS item_id, p.forum_id, p.topic_id, p.poster_id AS author_id, u.user_id,
				p.post_visibility, p.plus_legacy_erased, f.forum_type, t.topic_id AS parent_id, t.forum_id AS parent_forum,
				t.topic_type, t.topic_status, t.topic_moved_id FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id
				LEFT JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=p.forum_id
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id';
			$column = 'p.post_id';
		}
		else
		{
			$sql = 'SELECT t.topic_id AS item_id, t.forum_id, t.topic_type, t.topic_status, t.topic_moved_id,
				t.topic_poster AS author_id, u.user_id, t.topic_visibility, t.plus_legacy_removed, f.forum_type,
				dst.topic_id AS destination_id, dst.topic_status AS destination_status, dst.topic_moved_id AS next_destination,
				EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.topic_id=t.topic_id) AS has_posts
				FROM ' . TOPICS_TABLE . ' t LEFT JOIN ' . TOPICS_TABLE . ' dst ON dst.topic_id=t.topic_moved_id
				LEFT JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=t.topic_poster';
			$column = 't.topic_id';
		}
		$result = $this->db->sql_query_limit($sql . " WHERE $column > " . (int) $after . " AND $column <= " . (int) $through .
			" ORDER BY $column", self::PAGE_SIZE + 1);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$more = count($rows) > self::PAGE_SIZE;
		if ($more) { array_pop($rows); }
		$findings = array();
		foreach ($rows as $row)
		{
			$issues = array();
			$forum = (int) $row['forum_id'];
			$global = (int) $row['topic_type'] === POST_GLOBAL;
			if (!($global && $forum === 0) && ($row['forum_type'] === null || (int) $row['forum_type'] !== FORUM_POST))
			{
				$issues['forum'] = array($forum);
			}
			// Deleted accounts with retained texts use ANONYMOUS. Erased Plus placeholders have no personal attribution.
			$erased = $posts ? ($row['plus_legacy_erased'] && (int) $row['post_visibility'] === ITEM_DELETED) : removed_topics::marker($row);
			if (!$erased && (int) $row['author_id'] !== ANONYMOUS && $row['user_id'] === null)
			{
				$issues['author'] = array((int) $row['author_id']);
			}
			$moved = (int) $row['topic_status'] === ITEM_MOVED;
			$target = (int) $row['topic_moved_id'];
			if ($posts)
			{
				if ($row['parent_id'] === null) { $issues['topic'] = array((int) $row['topic_id']); }
				elseif ($moved || $target) { $issues['post_shadow'] = array((int) $row['topic_id']); }
				elseif ($forum !== (int) $row['parent_forum'] && !($global && (!$forum || !$row['parent_forum'])))
				{
					$issues['route'] = array($forum, (int) $row['topic_id'], (int) $row['parent_forum']);
				}
			}
			elseif ($moved)
			{
				// A normal shadow has no posts of its own and its first/last post belongs to its destination.
				if (!$target || $row['destination_id'] === null) { $issues['missing_target'] = array($target); }
				elseif ($target === (int) $row['item_id']) { $issues['self_target'] = array(); }
				elseif ((int) $row['destination_status'] === ITEM_MOVED || $row['next_destination']) { $issues['chained_target'] = array($target); }
			}
			elseif ($target) { $issues['move_flag'] = array($target); }
			elseif (!$row['has_posts']) { $issues['empty_topic'] = array(); }
			if ($issues) { $findings[(int) $row['item_id']] = $issues; }
		}
		$last = $rows ? (int) end($rows)['item_id'] : 0;
		return array('checked'=>count($rows), 'first'=>$rows ? (int) $rows[0]['item_id'] : 0, 'last'=>$last,
			'through'=>$through, 'next'=>$more ? $last : 0, 'findings'=>$findings);
	}
}
