<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\print_options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class topic_print
{
	private const BATCH = 64;
	private $db;
	private $auth;
	private $visibility;
	private $user;
	private $language;
	private $request;
	private $template;
	private $helper;
	private $dispatcher;
	private $config;
	private $root;
	private $ext;

	public function __construct($db, $auth, $visibility, $user, $language, $request, $template, $helper, $dispatcher, $config, $root, $ext)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->user = $user;
		$this->language = $language;
		$this->request = $request;
		$this->template = $template;
		$this->helper = $helper;
		$this->dispatcher = $dispatcher;
		$this->config = $config;
		$this->root = $root;
		$this->ext = $ext;
	}

	public function show($topic_id)
	{
		$this->language->add_lang('viewtopic');
		$this->language->add_lang('community', 'phpbb2plus/community');
		$filters = print_options::filters($this->request->variable('postdays', 0), $this->request->variable('postorder', ''), true);
		$result = $this->db->sql_query('SELECT t.topic_id,t.topic_title,t.topic_poster,t.topic_visibility,f.forum_id,f.forum_name,f.forum_password
			FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
			WHERE t.topic_id=' . (int) $topic_id . ' AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$topic) { throw new NotFoundHttpException(); }
		$forum = (int) $topic['forum_id'];
		if (!$this->auth->acl_get('f_list', $forum) || !$this->auth->acl_get('f_read', $forum))
		{
			if (empty($this->user->data['is_registered']))
			{
				$url = $this->helper->route('phpbb2plus_community_topic_print', array('topic_id' => (int) $topic_id) + $filters, false);
				login_box($url, $this->language->lang('LOGIN_VIEWFORUM'));
			}
			throw new NotFoundHttpException();
		}
		if ((int) $topic['topic_visibility'] === ITEM_DELETED || !$this->visibility->is_visible('topic', $forum, $topic)) { throw new NotFoundHttpException(); }
		if (!$this->auth->acl_get('f_print', $forum)) { throw new AccessDeniedHttpException($this->language->lang('NO_AUTH_PRINT_TOPIC')); }
		if ($topic['forum_password'] !== '')
		{
			require_once $this->root . 'includes/functions_display.' . $this->ext;
			login_forum_box($topic);
		}
		$headers = array('Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'Content-Type' => 'text/html; charset=UTF-8');
		if ($this->request->server('REQUEST_METHOD') === 'HEAD') { return new Response('', 200, $headers); }
		$title = censor_text($topic['topic_title']);
		page_header($title, false, $forum, 'forum', false);
		$this->helper->display_footer(false);
		$this->template->assign_vars(array('TOPIC_TITLE' => $title, 'FORUM_NAME' => $topic['forum_name'],
			'U_FORUM' => generate_board_url() . '/viewforum.' . $this->ext . '?f=' . $forum,
			'U_TOPIC' => generate_board_url() . '/viewtopic.' . $this->ext . '?t=' . (int) $topic_id,
			'PRINT_POSTS' => $this->posts($topic, $filters), 'PRINT_FILTERED' => isset($filters['postdays']),
			'PRINT_REMOVED' => $this->language->lang('PLUS_REMOVED_POSTS', 1)));
		$this->template->set_filenames(array('plus_print' => '@phpbb2plus_community/topic_print.html'));
		return new StreamedResponse(function () { $this->template->display('plus_print'); }, 200, $headers);
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

	private function posts($topic, $filters)
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
			' AND t.topic_visibility<>' . ITEM_DELETED . ' AND ' . $this->visibility->get_visibility_sql('topic', $forum, 't.') .
			" AND f.forum_password='" . $this->db->sql_escape($topic['forum_password']) . "' AND ((" .
			$this->visibility->get_visibility_sql('post', $forum, 'p.') . ' AND p.post_visibility<>' . ITEM_DELETED .
			') OR (t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_DELETED . ' AND p.plus_public_post=1))';
		if (isset($filters['postdays'])) { $where .= ' AND p.post_time>=' . (time() - 86400 * $filters['postdays']); }
		// Deleted posts and ignored writers supply a position only. Their text and bylines
		// never join the records passed to formatters, template events or attachments.
		$sql = 'SELECT p.post_id,p.post_time,p.post_visibility,z.foe,b.post_subject,b.post_text,b.bbcode_uid,b.bbcode_bitfield,
			b.enable_bbcode,b.enable_smilies,b.enable_magic_url,b.post_attachment,b.poster_id AS user_id,b.post_username,u.username
			FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
			LEFT JOIN ' . ZEBRA_TABLE . ' z ON z.zebra_id=p.poster_id AND z.user_id=' . (int) $this->user->data['user_id'] .
			' LEFT JOIN ' . POSTS_TABLE . ' b ON b.post_id=p.post_id AND p.post_visibility<>' . ITEM_DELETED . ' AND COALESCE(z.foe,0)=0
			LEFT JOIN ' . USERS_TABLE . " u ON u.user_id=b.poster_id WHERE $where";
		$cursor = '';
		do
		{
			$result = $this->db->sql_query_limit($sql . $cursor . " ORDER BY p.post_time $order,p.post_id $order", self::BATCH);
			$rows = array_column($this->db->sql_fetchrowset($result), null, 'post_id');
			$this->db->sql_freeresult($result);
			if (!$rows) { break; }
			$readable = array_filter($rows, static function ($post) { return $post['user_id'] !== null; });
			$authors = $this->dispatcher->trigger_event('phpbb2plus.community.preview_bylines', array('rowset' => $readable, 'post_list' => array_keys($readable)));
			$files = $this->attachments($readable, $topic);
			foreach ($rows as $post_id => $post)
			{
				if ((int) $post['post_visibility'] === ITEM_DELETED) { yield array('REMOVED' => true); }
				elseif ($post['foe']) { yield array('IGNORED' => true); }
				else
				{
					$post = $authors['rowset'][$post_id];
					$options = ($post['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) | ($post['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) |
						($post['enable_magic_url'] ? OPTION_FLAG_LINKS : 0);
					$body = generate_text_for_display($post['post_text'], $post['bbcode_uid'], $post['bbcode_bitfield'], $options);
					$attached = $files[$post_id] ?? array();
					if ($attached) { $counts = array(); parse_attachments($forum, $body, $attached, $counts); }
					yield array('ID' => (int) $post_id, 'SUBJECT' => censor_text($post['post_subject']), 'BODY' => $body,
						'AUTHOR' => get_username_string('username', $post['username'] === null ? ANONYMOUS : (int) $post['user_id'], $post['username'], false, $post['post_username']),
						'DATE' => $this->user->format_date($post['post_time'], false, true), 'ATTACHMENTS' => $attached,
						'PENDING' => (int) $post['post_visibility'] !== ITEM_APPROVED);
				}
			}
			$boundary = end($rows);
			$time = (int) $boundary['post_time'];
			$cursor = " AND (p.post_time $next $time OR (p.post_time=$time AND p.post_id $next " . (int) $boundary['post_id'] . '))';
		}
		while (count($rows) === self::BATCH);
	}
}
