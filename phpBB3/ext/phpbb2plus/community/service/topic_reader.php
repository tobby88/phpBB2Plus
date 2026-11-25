<?php
namespace phpbb2plus\community\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class topic_reader
{
	private const BATCH = 64;
	private const BATCH_BYTES = 2097152;
	private $db;
	private $auth;
	private $visibility;
	private $user;
	private $language;
	private $helper;
	private $dispatcher;
	private $config;
	private $root;
	private $ext;

	public function __construct($db, $auth, $visibility, $user, $language, $helper, $dispatcher, $config, $root, $ext)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->user = $user;
		$this->language = $language;
		$this->helper = $helper;
		$this->dispatcher = $dispatcher;
		$this->config = $config;
		$this->root = $root;
		$this->ext = $ext;
	}

	public function open($topic_id, $post_id, $route, $params, $interactive = true, $require_print = true)
	{
		$this->language->add_lang('viewtopic');
		$this->language->add_lang('community', 'phpbb2plus/community');
		$join = $post_id ? ' JOIN ' . POSTS_TABLE . ' p ON p.topic_id=t.topic_id AND p.forum_id=t.forum_id' : '';
		$columns = $post_id ? ',p.post_id,p.post_time,p.post_visibility,p.poster_id,p.plus_public_post' : '';
		$target = $post_id ? 'p.post_id=' . (int) $post_id : 't.topic_id=' . (int) $topic_id;
		$result = $this->db->sql_query('SELECT t.topic_id,t.topic_title,t.topic_poster,t.topic_visibility,t.topic_status,t.plus_legacy_removed,f.forum_id,f.forum_name,f.forum_password' . $columns .
			' FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . " f ON f.forum_id=t.forum_id $join
			WHERE $target AND t.topic_moved_id=0 AND t.topic_status<>" . ITEM_MOVED);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$topic) { throw new NotFoundHttpException(); }
		$forum = (int) $topic['forum_id'];
		if (!$this->auth->acl_get('f_list', $forum) || !$this->auth->acl_get('f_read', $forum))
		{
			if ($interactive && empty($this->user->data['is_registered']))
			{
				$url = $this->helper->route($route, $params, false);
				login_box($url, $this->language->lang('LOGIN_VIEWFORUM'));
			}
			throw new NotFoundHttpException();
		}
		$marker = removed_topics::marker($topic);
		if (!$marker && ((int) $topic['topic_visibility'] === ITEM_DELETED || !$this->visibility->is_visible('topic', $forum, $topic))) { throw new NotFoundHttpException(); }
		if ($marker) { $topic['topic_title'] = $this->language->lang('PLUS_REMOVED_TOPIC'); }
		if ($post_id)
		{
			if ($marker) { $visible = (int) $topic['post_visibility'] === ITEM_DELETED && $topic['plus_public_post']; }
			else
			{
				$visible = (int) $topic['post_visibility'] === ITEM_DELETED
					? (int) $topic['topic_visibility'] === ITEM_APPROVED && $topic['plus_public_post']
					: $this->visibility->is_visible('post', $forum, $topic);
			}
			if (!$visible) { throw new NotFoundHttpException(); }
		}
		if ($require_print && !$this->auth->acl_get('f_print', $forum)) { throw new AccessDeniedHttpException($this->language->lang('NO_AUTH_PRINT_TOPIC')); }
		if ($topic['forum_password'] !== '' && $interactive)
		{
			require_once $this->root . 'includes/functions_display.' . $this->ext;
			login_forum_box($topic);
		}
		elseif ($topic['forum_password'] !== '')
		{
			$result = $this->db->sql_query('SELECT forum_id FROM ' . FORUMS_ACCESS_TABLE . " WHERE forum_id=$forum AND session_id='" .
				$this->db->sql_escape($this->user->session_id) . "' AND user_id=" . (int) $this->user->data['user_id']);
			$unlocked = $this->db->sql_fetchfield('forum_id');
			$this->db->sql_freeresult($result);
			if (!$unlocked) { throw new AccessDeniedHttpException(); }
		}
		return $topic;
	}

	private function attachments($rows, $topic)
	{
		$files = array();
		$ids = array_keys(array_filter($rows, static function ($post) { return $post['post_attachment']; }));
		if (!$ids || !$this->config['allow_attachments'] || !$this->auth->acl_get('u_download') || !$this->auth->acl_get('f_download', $topic['forum_id'])) { return $files; }
		$result = $this->db->sql_query('SELECT * FROM ' . ATTACHMENTS_TABLE . ' WHERE in_message=0 AND is_orphan=0 AND topic_id=' . (int) $topic['topic_id'] .
			' AND ' . $this->db->sql_in_set('post_msg_id', $ids) . ' ORDER BY attach_id DESC');
		while ($file = $this->db->sql_fetchrow($result)) { $files[$file['post_msg_id']][] = $file; }
		$this->db->sql_freeresult($result);
		return $files;
	}

	private function export_batch_size($from)
	{
		// phpBB3 post_text is MEDIUMTEXT. A fixed number of rows alone could load
		// much more than the entire allowed attachment before it reaches the spool.
		$result = $this->db->sql_query_limit('SELECT OCTET_LENGTH(b.post_text) AS bytes' . $from, self::BATCH);
		$count = $bytes = 0;
		while ($post = $this->db->sql_fetchrow($result))
		{
			$size = (int) $post['bytes'];
			if ($count && $size > self::BATCH_BYTES - $bytes) { break; }
			$bytes += $size;
			++$count;
		}
		$this->db->sql_freeresult($result);
		return $count;
	}

	public function posts($topic, $filters, $include_attachments = true)
	{
		$forum = (int) $topic['forum_id'];
		$id = (int) $topic['topic_id'];
		$order = isset($filters['postorder']) ? 'DESC' : 'ASC';
		$next = $order === 'DESC' ? '<' : '>';
		$result = $this->db->sql_query('SELECT MAX(post_id) AS last_id FROM ' . POSTS_TABLE . " WHERE topic_id=$id AND forum_id=$forum");
		$last = (int) $this->db->sql_fetchfield('last_id');
		$this->db->sql_freeresult($result);
		// Each batch rechecks that the topic, its forum and the accepted password are still the same.
		$where = "p.topic_id=$id AND p.forum_id=$forum AND p.post_id<=$last AND t.topic_moved_id=0 AND t.topic_status<>" . ITEM_MOVED .
			" AND f.forum_password='" . $this->db->sql_escape($topic['forum_password']) . "'";
		if (removed_topics::marker($topic))
		{
			$where .= ' AND ' . removed_topics::condition('t.') . ' AND p.post_visibility=' . ITEM_DELETED . ' AND p.plus_public_post=1';
		}
		else
		{
			$where .= ' AND t.topic_visibility<>' . ITEM_DELETED . ' AND ' . $this->visibility->get_visibility_sql('topic', $forum, 't.') .
				' AND ((' . $this->visibility->get_visibility_sql('post', $forum, 'p.') . ' AND p.post_visibility<>' . ITEM_DELETED .
				') OR (t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_DELETED . ' AND p.plus_public_post=1))';
		}
		if (isset($filters['postdays'])) { $where .= ' AND p.post_time>=' . (time() - 86400 * $filters['postdays']); }
		// Deleted posts and ignored writers supply a position only. Their text and bylines
		// never join the records passed to formatters, template events or attachments.
		$select = 'SELECT p.post_id,p.post_time,p.post_visibility,z.foe,b.post_subject,b.post_text,b.bbcode_uid,b.bbcode_bitfield,
			b.enable_bbcode,b.enable_smilies,b.enable_magic_url,b.post_attachment,b.poster_id AS user_id,b.post_username,u.username';
		$from = ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
			LEFT JOIN ' . ZEBRA_TABLE . ' z ON z.zebra_id=p.poster_id AND z.user_id=' . (int) $this->user->data['user_id'] .
			' LEFT JOIN ' . POSTS_TABLE . ' b ON b.post_id=p.post_id AND p.post_visibility<>' . ITEM_DELETED . ' AND COALESCE(z.foe,0)=0
			LEFT JOIN ' . USERS_TABLE . " u ON u.user_id=b.poster_id WHERE $where";
		$cursor = '';
		do
		{
			$ordered = $from . $cursor . " ORDER BY p.post_time $order,p.post_id $order";
			$limit = $include_attachments ? self::BATCH : $this->export_batch_size($ordered);
			if (!$limit) { break; }
			$result = $this->db->sql_query_limit($select . $ordered, $limit);
			$rows = array_column($this->db->sql_fetchrowset($result), null, 'post_id');
			$this->db->sql_freeresult($result);
			if (!$rows) { break; }
			$readable = array_filter($rows, static function ($post) { return $post['user_id'] !== null; });
			$authors = $this->dispatcher->trigger_event('phpbb2plus.community.preview_bylines', array('rowset' => $readable, 'post_list' => array_keys($readable)));
			$files = $include_attachments ? $this->attachments($readable, $topic) : array();
			foreach ($rows as $post_id => $post)
			{
				if ((int) $post['post_visibility'] === ITEM_DELETED) { yield array('REMOVED' => true, 'ID' => (int) $post_id); }
				elseif ($post['foe']) { yield array('IGNORED' => true); }
				else
				{
					$post = $authors['rowset'][$post_id];
					$options = ($post['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) | ($post['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) |
						($post['enable_magic_url'] ? OPTION_FLAG_LINKS : 0);
					$body = generate_text_for_display($post['post_text'], $post['bbcode_uid'], $post['bbcode_bitfield'], $options);
					$attached = $files[$post_id] ?? array();
					if ($attached) { $counts = array(); parse_attachments($forum, $body, $attached, $counts); }
					$orphan = (int) $post['user_id'] > ANONYMOUS && $post['username'] === null;
					$author = $orphan ? $this->language->lang('PLUS_FORMER_MEMBER')
						: get_username_string('username', (int) $post['user_id'], $post['username'], false, $post['post_username']);
					yield array('ID' => (int) $post_id, 'SUBJECT' => censor_text($post['post_subject']), 'BODY' => $body,
						'AUTHOR' => $author,
						'DATE' => $this->user->format_date($post['post_time'], false, true), 'ATTACHMENTS' => $attached,
						'PENDING' => (int) $post['post_visibility'] !== ITEM_APPROVED);
				}
			}
			$boundary = end($rows);
			$time = (int) $boundary['post_time'];
			$cursor = " AND (p.post_time $next $time OR (p.post_time=$time AND p.post_id $next " . (int) $boundary['post_id'] . '))';
		}
		while (count($rows) === $limit);
	}
}
