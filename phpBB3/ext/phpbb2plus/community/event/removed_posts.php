<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class removed_posts implements EventSubscriberInterface
{
	private $db;
	private $auth;
	private $visibility;
	private $config;
	private $language;
	private $markers = array();

	public function __construct($db, $auth, $visibility, $config, $language)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->config = $config;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.submit_post_modify_sql_data'=>'posting', 'core.set_post_visibility_before_sql'=>'approval',
			'core.viewtopic_modify_post_data'=>'positions', 'core.viewtopic_modify_post_row'=>'caption',
			'phpbb2plus.knowledgebase.reply_visibility'=>'reply_markers');
	}

	public function reply_markers($event)
	{
		// A formerly published reply may retain its position in a visible topic.
		// The preview renders only a marker for every deleted row.
		$event['visible'] = '(' . $event['visible'] . ') OR (t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_DELETED . ' AND p.plus_public_post=1)';
	}

	public function posting($event)
	{
		$sql = $event['sql_data'];
		if (isset($sql[POSTS_TABLE]['sql']['post_visibility']))
		{
			$sql[POSTS_TABLE]['sql']['plus_public_post'] = (int) ((int) $sql[POSTS_TABLE]['sql']['post_visibility'] === ITEM_APPROVED);
			$event['sql_data'] = $sql;
		}
	}

	public function approval($event)
	{
		if ((int) $event['visibility'] === ITEM_DELETED) { return; }
		$data = $event['data'];
		$data['plus_public_post'] = (int) ((int) $event['visibility'] === ITEM_APPROVED);
		$event['data'] = $data;
	}

	private function boundary($row, $operator)
	{
		$time = (int) $row['post_time'];
		$id = (int) $row['post_id'];
		return "(p.post_time $operator $time OR (p.post_time=$time AND p.post_id $operator $id))";
	}

	public function positions($event)
	{
		$this->markers = array();
		$topic = $event['topic_data'];
		$forum = (int) $event['forum_id'];
		if (empty($topic['topic_posts_softdeleted']) || (int) $topic['topic_visibility'] !== ITEM_APPROVED ||
			$this->auth->acl_get('m_approve', $forum) || !$this->auth->acl_get('f_read', $forum) || !$this->auth->acl_get('f_list', $forum)) { return; }
		$chronological = $event['sort_key'] === 't';
		// Deleted bylines and subjects have no meaningful position in those sort orders.
		if (!$chronological && $event['start']) { return; }
		$rows = $event['rowset'];
		$list = $event['post_list'];
		ksort($list, SORT_NUMERIC); // phpBB can fetch the last page in reverse.
		$anchors = array();
		foreach ($list as $id)
		{
			if (isset($rows[$id])) { $anchors[] = $rows[$id]; }
		}
		if (!$anchors) { return; }
		$where = 'p.forum_id=' . $forum . ' AND p.topic_id=' . (int) $event['topic_id'];
		if ($event['sort_days']) { $where .= ' AND p.post_time>=' . (time() - 86400 * (int) $event['sort_days']); }
		$range = $where . ' AND p.post_visibility=' . ITEM_DELETED . ' AND p.plus_public_post=1';
		$slot = '0';
		if ($chronological)
		{
			$descending = $event['sort_dir'] === 'd';
			$before = $descending ? '>' : '<';
			$after = $descending ? '<' : '>';
			$last = end($anchors);
			if ($event['start']) { $range .= ' AND ' . $this->boundary($anchors[0], $after); }
			if (count($anchors) >= (int) $this->config['posts_per_page'])
			{
				$order = $descending ? 'DESC' : 'ASC';
				$result = $this->db->sql_query_limit('SELECT p.post_time,p.post_id FROM ' . POSTS_TABLE . " p WHERE $where AND " .
					$this->visibility->get_visibility_sql('post', $forum, 'p.') . ' AND ' . $this->boundary($last, $after) .
					" ORDER BY p.post_time $order,p.post_id $order", 1);
				$next = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);
				if ($next) { $range .= ' AND ' . $this->boundary($next, $before); }
			}
			$slot = 'CASE';
			foreach ($anchors as $i => $anchor) { $slot .= ' WHEN ' . $this->boundary($anchor, $before) . ' THEN ' . $i; }
			$slot .= ' ELSE ' . count($anchors) . ' END';
		}
		// Fetch one count per gap. Text, authors and removal details stay in phpBB's
		// normal moderator view, even for a long run of consecutive removals.
		$result = $this->db->sql_query("SELECT $slot AS marker_slot,COUNT(*) AS removed_count,MIN(p.post_id) AS first_id
			FROM " . POSTS_TABLE . " p WHERE $range GROUP BY marker_slot");
		$this->language->add_lang('community', 'phpbb2plus/community');
		while ($group = $this->db->sql_fetchrow($result))
		{
			$position = (int) $group['marker_slot'];
			$count = (int) $group['removed_count'];
			$anchor = (int) $anchors[$position ? $position - 1 : 0]['post_id'];
			$side = $position ? 'AFTER' : 'BEFORE';
			$this->markers[$anchor]['PLUS_REMOVED_' . $side] = $this->language->lang('PLUS_REMOVED_POSTS', $count);
			$this->markers[$anchor]['PLUS_REMOVED_' . $side . '_ID'] = $count === 1 ? (int) $group['first_id'] : 0;
		}
		$this->db->sql_freeresult($result);
	}

	public function caption($event)
	{
		$id = (int) $event['row']['post_id'];
		if (isset($this->markers[$id])) { $event['post_row'] = $event['post_row'] + $this->markers[$id]; }
	}
}
