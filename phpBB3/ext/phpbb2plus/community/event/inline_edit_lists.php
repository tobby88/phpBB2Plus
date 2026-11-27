<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\removed_topics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class inline_edit_lists implements EventSubscriberInterface
{
	private $db;
	private $auth;
	private $user;
	private $visibility;
	private $config;
	private $editor;
	private $root;
	private $ext;

	public function __construct($db, $auth, $user, $visibility, $config, $editor, $root, $ext)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->visibility = $visibility;
		$this->config = $config;
		$this->editor = $editor;
		$this->root = $root;
		$this->ext = $ext;
	}

	public static function getSubscribedEvents()
	{
		return array('core.search_modify_rowset' => 'prepare', 'core.viewforum_modify_topics_data' => 'prepare',
			'core.search_modify_tpl_ary' => 'search', 'core.viewforum_modify_topicrow' => 'forum',
			'phpbb2plus.community.announcement_rows' => 'prepare', 'phpbb2plus.community.announcement_topic_row' => 'forum');
	}

	public function prepare($event)
	{
		if (empty($this->user->data['is_registered']) || !empty($this->user->data['is_bot'])) { return; }
		$rows = $event['rowset'];
		$posts = isset($event['show_results']) && $event['show_results'] === 'posts';
		$key = $posts ? 'post_id' : 'topic_first_post_id';
		$ids = array_unique(array_filter(array_column($rows, $key)));
		if (!$ids) { return; }

		// A topic list has no post editing lock or password grant. Fetch the actual post and forum
		// once for the whole page, including global announcements displayed in another forum.
		$user_id = (int) $this->user->data['user_id'];
		$sql = 'SELECT p.post_id, p.poster_id, p.post_time, p.post_visibility, p.post_edit_locked, p.plus_legacy_erased,
			t.topic_id, t.topic_poster, t.topic_status, t.topic_visibility, t.plus_legacy_removed, f.forum_id, f.forum_status
			FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
			WHERE ' . $this->db->sql_in_set('p.post_id', $ids) . ' AND f.forum_type=' . FORUM_POST .
			' AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED .
			($posts ? '' : ' AND p.post_id=t.topic_first_post_id') .
			" AND (f.forum_password='' OR EXISTS (SELECT 1 FROM " . FORUMS_ACCESS_TABLE .
			" a WHERE a.forum_id=f.forum_id AND a.user_id=$user_id AND a.session_id='" . $this->db->sql_escape($this->user->session_id) . "'))";
		$result = $this->db->sql_query($sql);
		$allowed = array();
		$minutes = (int) $this->config['edit_time'];
		$cutoff = $minutes ? time() - 60 * $minutes : null;
		while ($post = $this->db->sql_fetchrow($result))
		{
			$forum = (int) $post['forum_id'];
			if (!$this->auth->acl_get('f_list', $forum) || !$this->auth->acl_get('f_read', $forum) ||
				removed_topics::marker($post) || ((int) $post['post_visibility'] === ITEM_DELETED && $post['plus_legacy_erased']) ||
				!$this->visibility->is_visible('topic', $forum, $post) || !$this->visibility->is_visible('post', $forum, $post)) { continue; }
			// posting.php rechecks these on every GET and POST. Editing in a locked forum or
			// topic and editing after the author's time window both require m_edit.
			if (!$this->auth->acl_get('m_edit', $forum) && (!$this->auth->acl_get('f_edit', $forum) ||
				(int) $post['poster_id'] !== $user_id || (int) $post['forum_status'] === ITEM_LOCKED ||
				(int) $post['topic_status'] === ITEM_LOCKED || $post['post_edit_locked'] ||
				($cutoff !== null && (int) $post['post_time'] <= $cutoff))) { continue; }
			$allowed[$post['post_id']] = (int) $post['topic_id'];
		}
		$this->db->sql_freeresult($result);
		foreach ($rows as &$row)
		{
			$id = (int) ($row[$key] ?? 0);
			if (isset($allowed[$id]) && $allowed[$id] === (int) $row['topic_id'] &&
				(int) $row['topic_status'] !== ITEM_MOVED && empty($row['topic_moved_id'])) { $row['plus_edit_post'] = $id; }
		}
		unset($row);
		$event['rowset'] = $rows;
	}

	private function link($event, $block)
	{
		$id = (int) ($event['row']['plus_edit_post'] ?? 0);
		$row = $event[$block];
		if (!$id || empty($row['U_VIEW_TOPIC'])) { return; }
		$row['PLUS_EDIT_POST_ID'] = $id;
		$row['U_PLUS_EDIT_POST'] = append_sid($this->root . 'posting.' . $this->ext, 'mode=edit&amp;p=' . $id);
		$row['S_PLUS_INLINE_EDIT'] = $this->editor->prepare_controls();
		$event[$block] = $row;
	}

	public function search($event) { $this->link($event, 'tpl_ary'); }
	public function forum($event) { $this->link($event, 'topic_row'); }
}
